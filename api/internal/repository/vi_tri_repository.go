package repository

import (
	"context"
	"errors"
	"fmt"
	"strconv"
	"strings"

	"gorm.io/gorm"

	"sass-api/internal/domain"
)

type viTriRepository struct{ db *gorm.DB }

func NewViTriRepository(db *gorm.DB) domain.ViTriRepository {
	return &viTriRepository{db: db}
}

func (r *viTriRepository) List(ctx context.Context, f domain.ViTriFilter) ([]domain.ViTri, error) {
	// Mới nhất lên đầu, cùng thứ tự với đơn vị tính: người ta vừa thêm một vị trí
	// thì muốn thấy nó ngay, không phải dò xuống cuối bảng.
	q := r.db.WithContext(ctx).Order("id DESC")
	// Kệ là chỗ vật lý của MỘT mặt bằng: không cắt theo chi nhánh thì ô chọn vị
	// trí trộn kệ của mọi quận vào một danh sách (migration 0052).
	if f.ShopID > 0 {
		q = q.Where("shop_id = ?", f.ShopID)
	}
	if f.OnlyActive {
		q = q.Where("is_active = ?", true)
	}
	if kw := strings.TrimSpace(f.Keyword); kw != "" {
		like := "%" + kw + "%"
		q = q.Where("(name LIKE ? OR code LIKE ?)", like, like)
	}

	var list []domain.ViTri
	if err := q.Find(&list).Error; err != nil {
		return nil, err
	}

	return list, nil
}

func (r *viTriRepository) FindByID(ctx context.Context, id uint) (*domain.ViTri, error) {
	var vt domain.ViTri
	err := r.db.WithContext(ctx).First(&vt, id).Error
	if errors.Is(err, gorm.ErrRecordNotFound) {
		return nil, domain.ErrNotFound
	}
	if err != nil {
		return nil, err
	}

	return &vt, nil
}

// ExistsByCode dùng Unscoped: mã của vị trí đã xoá mềm vẫn giữ chỗ trong UNIQUE
// index, báo trùng ở đây thân thiện hơn là để MySQL ném lỗi khi ghi.
func (r *viTriRepository) ExistsByCode(ctx context.Context, code string, excludeID, shopID uint) (bool, error) {
	var count int64
	q := r.db.WithContext(ctx).Unscoped().Model(&domain.ViTri{}).Where("code = ?", code)
	// Trùng mã chỉ tính TRONG CÙNG chi nhánh — khoá duy nhất dưới database cũng
	// vậy (uq_product_locations_code). Kiểm rộng hơn là bắt chi nhánh thứ hai
	// nghĩ ra một mã khác cho chính cái kệ A của họ.
	if shopID > 0 {
		q = q.Where("shop_id = ?", shopID)
	}
	if excludeID > 0 {
		q = q.Where("id <> ?", excludeID)
	}
	err := q.Count(&count).Error

	return count > 0, err
}

// ExistsByName so bằng LOWER(...) COLLATE utf8mb4_bin — cùng lý do với đơn vị
// tính: đối chiếu mặc định của cột bỏ qua cả hoa thường lẫn dấu, nên "Kệ Đá" và
// "Ke Da" bị coi là một. Ép utf8mb4_bin để phân biệt dấu, LOWER() ở hai vế trả
// lại phần bỏ qua hoa thường.
func (r *viTriRepository) ExistsByName(ctx context.Context, name string, excludeID, shopID uint) (bool, error) {
	var count int64
	q := r.db.WithContext(ctx).Model(&domain.ViTri{}).
		Where("LOWER(name) COLLATE utf8mb4_bin = LOWER(?)", name)
	if shopID > 0 {
		q = q.Where("shop_id = ?", shopID)
	}
	if excludeID > 0 {
		q = q.Where("id <> ?", excludeID)
	}
	err := q.Count(&count).Error

	return count > 0, err
}

// NextCode sinh mã kế tiếp dạng VT001.
//
// Lấy số lớn nhất trong các mã ĐÚNG KHUÔN VT + chữ số rồi cộng một, tính cả
// dòng đã xoá mềm — mã cũ vẫn chiếm chỗ trong UNIQUE index. Mã do người dùng tự
// đặt (KEA1, KHOLANH…) không tham gia, nên khai tay xen kẽ cũng không làm hỏng dãy.
func (r *viTriRepository) NextCode(ctx context.Context, shopID uint) (string, error) {
	var codes []string
	q := r.db.WithContext(ctx).Unscoped().
		Model(&domain.ViTri{}).
		Where("code REGEXP ?", "^VT[0-9]+$")
	// Mỗi chi nhánh một dãy số riêng: mã chỉ duy nhất trong chi nhánh, nên nhìn
	// sang kệ của quận khác để chọn số kế tiếp là bỏ trống số một cách vô cớ.
	if shopID > 0 {
		q = q.Where("shop_id = ?", shopID)
	}
	if err := q.Pluck("code", &codes).Error; err != nil {
		return "", err
	}

	max := 0
	for _, c := range codes {
		if n, err := strconv.Atoi(strings.TrimPrefix(c, "VT")); err == nil && n > max {
			max = n
		}
	}

	return fmt.Sprintf("VT%03d", max+1), nil
}

func (r *viTriRepository) Create(ctx context.Context, vt *domain.ViTri) error {
	return translateViTriErr(r.db.WithContext(ctx).Create(vt).Error)
}

func (r *viTriRepository) Update(ctx context.Context, vt *domain.ViTri) error {
	return translateViTriErr(r.db.WithContext(ctx).Save(vt).Error)
}

// Delete xoá mềm. Service đã chặn vị trí còn hàng trỏ tới trước khi gọi vào
// đây; khoá ngoại fk_products_location là lớp chắn cuối nếu lọt.
func (r *viTriRepository) Delete(ctx context.Context, id uint) error {
	return translateViTriErr(r.db.WithContext(ctx).Delete(&domain.ViTri{}, id).Error)
}

// DangDuocDung đếm mặt hàng trỏ tới từng vị trí bằng MỘT câu GROUP BY.
//
// Đọc BẢNG NỐI `product_shop_locations` chứ không phải cột `products.location_id`
// — cột ấy đã bỏ ở migration 0052 vì một mặt hàng nằm ở kệ khác nhau tại mỗi
// chi nhánh. Không lọc chi nhánh ở đây: câu hỏi là "kệ này còn ai dùng không",
// và bảng nối đã gắn mỗi dòng vào đúng một kệ.
func (r *viTriRepository) DangDuocDung(ctx context.Context, ids []uint) (map[uint]bool, error) {
	dung := make(map[uint]bool, len(ids))
	if len(ids) == 0 {
		return dung, nil
	}

	var rows []struct {
		LocationID uint
		So         int64
	}
	err := r.db.WithContext(ctx).
		Table("product_shop_locations").
		Select("location_id, COUNT(*) AS so").
		Where("location_id IN ?", ids).
		Group("location_id").
		Scan(&rows).Error
	if err != nil {
		return nil, err
	}

	for _, r := range rows {
		dung[r.LocationID] = r.So > 0
	}

	return dung, nil
}

// translateViTriErr chuyển lỗi DB thô sang lỗi nghiệp vụ để handler trả mã HTTP
// thân thiện.
func translateViTriErr(err error) error {
	switch {
	case err == nil:
		return nil
	case errors.Is(err, gorm.ErrDuplicatedKey):
		return domain.ErrConflict
	case errors.Is(err, gorm.ErrForeignKeyViolated):
		return domain.ErrConflict
	default:
		return err
	}
}

// ChiNhanhGhi — xem docblock ở ViTriRepository.
//
// Dùng lại đúng chiNhanhCuaRequest của các đường ghi kho: một cái kệ thuộc về
// một mặt bằng, nên câu hỏi "kệ này của chi nhánh nào" phải trả lời y hệt câu
// "chứng từ này ghi vào kho nào".
func (r *viTriRepository) ChiNhanhGhi(ctx context.Context) (uint, error) {
	return chiNhanhCuaRequest(ctx, r.db)
}
