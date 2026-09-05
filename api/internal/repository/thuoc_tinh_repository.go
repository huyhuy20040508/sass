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

type thuocTinhRepository struct{ db *gorm.DB }

func NewThuocTinhRepository(db *gorm.DB) domain.ThuocTinhRepository {
	return &thuocTinhRepository{db: db}
}

// preloadGiaTri nạp kèm giá trị con, xếp theo id tăng dần — đúng thứ tự người
// dùng đã khai. Thuộc tính thì mới nhất lên đầu (orderBy id desc như bản cũ):
// vừa thêm một thuộc tính thì muốn thấy nó ngay, không phải dò xuống cuối bảng.
func (r *thuocTinhRepository) preloadGiaTri(q *gorm.DB) *gorm.DB {
	return q.Preload("GiaTri", func(db *gorm.DB) *gorm.DB {
		return db.Order("id ASC")
	})
}

func (r *thuocTinhRepository) List(ctx context.Context, f domain.ThuocTinhFilter) ([]domain.ThuocTinh, error) {
	q := r.preloadGiaTri(r.db.WithContext(ctx).Order("id DESC"))
	if f.OnlyActive {
		q = q.Where("is_active = ?", true)
	}
	if kw := strings.TrimSpace(f.Keyword); kw != "" {
		like := "%" + kw + "%"
		q = q.Where("(name LIKE ? OR code LIKE ?)", like, like)
	}

	var list []domain.ThuocTinh
	if err := q.Find(&list).Error; err != nil {
		return nil, err
	}

	return list, nil
}

func (r *thuocTinhRepository) FindByID(ctx context.Context, id uint) (*domain.ThuocTinh, error) {
	var tt domain.ThuocTinh
	err := r.preloadGiaTri(r.db.WithContext(ctx)).First(&tt, id).Error
	if errors.Is(err, gorm.ErrRecordNotFound) {
		return nil, domain.ErrNotFound
	}
	if err != nil {
		return nil, err
	}

	return &tt, nil
}

// ExistsByCode dùng Unscoped: mã của thuộc tính đã xoá mềm vẫn giữ chỗ trong
// UNIQUE index, báo trùng ở đây thân thiện hơn là để MySQL ném lỗi khi ghi.
func (r *thuocTinhRepository) ExistsByCode(ctx context.Context, code string, excludeID uint) (bool, error) {
	var count int64
	q := r.db.WithContext(ctx).Unscoped().Model(&domain.ThuocTinh{}).Where("code = ?", code)
	if excludeID > 0 {
		q = q.Where("id <> ?", excludeID)
	}
	err := q.Count(&count).Error

	return count > 0, err
}

// ExistsByName so bằng LOWER(...) COLLATE utf8mb4_bin.
//
// Đối chiếu mặc định của cột là utf8mb4_unicode_ci — nó bỏ qua CẢ hoa thường
// LẪN dấu, nên "Đường" và "Duong" bị coi là một. Ép sang utf8mb4_bin thì so
// từng byte (phân biệt dấu), còn LOWER() ở hai vế trả lại phần bỏ qua hoa
// thường. Cùng một luật với đơn vị tính.
func (r *thuocTinhRepository) ExistsByName(ctx context.Context, name string, excludeID uint) (bool, error) {
	var count int64
	q := r.db.WithContext(ctx).Model(&domain.ThuocTinh{}).
		Where("LOWER(name) COLLATE utf8mb4_bin = LOWER(?)", name)
	if excludeID > 0 {
		q = q.Where("id <> ?", excludeID)
	}
	err := q.Count(&count).Error

	return count > 0, err
}

// NextCode sinh mã kế tiếp dạng TT001.
//
// Lấy số lớn nhất trong các mã ĐÚNG KHUÔN TT + chữ số rồi cộng một, tính cả
// dòng đã xoá mềm — mã cũ vẫn chiếm chỗ trong UNIQUE index. Mã do người dùng tự
// đặt (SIZE, TOPPING…) không tham gia, nên khai tay xen kẽ cũng không làm hỏng
// dãy.
func (r *thuocTinhRepository) NextCode(ctx context.Context) (string, error) {
	var codes []string
	if err := r.db.WithContext(ctx).Unscoped().
		Model(&domain.ThuocTinh{}).
		Where("code REGEXP ?", "^TT[0-9]+$").
		Pluck("code", &codes).Error; err != nil {
		return "", err
	}

	max := 0
	for _, c := range codes {
		if n, err := strconv.Atoi(strings.TrimPrefix(c, "TT")); err == nil && n > max {
			max = n
		}
	}

	return fmt.Sprintf("TT%03d", max+1), nil
}

func (r *thuocTinhRepository) Create(ctx context.Context, tt *domain.ThuocTinh, giaTri []domain.ThuocTinhGiaTri) error {
	err := r.db.WithContext(ctx).Transaction(func(tx *gorm.DB) error {
		// Omit("GiaTri"): GORM tự ghi association khi trường ấy có dữ liệu, mà ở
		// đây giá trị con được ghi tay ngay bên dưới — để cả hai chạy là mỗi giá
		// trị vào bảng hai lần.
		if err := tx.Omit("GiaTri").Create(tt).Error; err != nil {
			return err
		}

		for i := range giaTri {
			giaTri[i].ThuocTinhID = tt.ID
			if err := tx.Create(&giaTri[i]).Error; err != nil {
				return err
			}
		}
		tt.GiaTri = giaTri

		return nil
	})

	return translateThuocTinhErr(err)
}

// Update ghi phần thân rồi ĐỒNG BỘ danh sách giá trị theo đúng thứ gửi lên:
// dòng có id thì sửa, không id thì thêm, giá trị cũ vắng mặt thì xoá.
func (r *thuocTinhRepository) Update(ctx context.Context, tt *domain.ThuocTinh, giaTri []domain.ThuocTinhGiaTri) error {
	err := r.db.WithContext(ctx).Transaction(func(tx *gorm.DB) error {
		if err := ghiThanThuocTinh(tx, tt); err != nil {
			return err
		}

		// Xoá trước rồi mới ghi: người dùng thường bỏ một dòng để khai lại đúng
		// mã ấy, xoá sau thì lượt ghi vấp UNIQUE index của chính dòng sắp mất.
		giu := make([]uint, 0, len(giaTri))
		for _, gt := range giaTri {
			if gt.ID > 0 {
				giu = append(giu, gt.ID)
			}
		}
		xoa := tx.Where("attribute_id = ?", tt.ID)
		if len(giu) > 0 {
			xoa = xoa.Where("id NOT IN ?", giu)
		}
		// Xoá HẲN (bảng giá trị không có deleted_at) — xem migration 0024.
		if err := xoa.Delete(&domain.ThuocTinhGiaTri{}).Error; err != nil {
			return err
		}

		for i := range giaTri {
			giaTri[i].ThuocTinhID = tt.ID

			if giaTri[i].ID == 0 {
				if err := tx.Create(&giaTri[i]).Error; err != nil {
					return err
				}
				continue
			}

			// Updates kèm map chứ không Save(): Save ghi MỌI cột, mà bản ghi dựng
			// từ request không mang created_at nên nó đẩy '0000-00-00' vào cột ấy —
			// MySQL bật STRICT_TRANS_TABLES thì lỗi 1292, dễ tính hơn thì nuốt và
			// ngày tạo bị xoá trắng lúc nào không hay.
			if err := tx.Model(&domain.ThuocTinhGiaTri{}).
				Where("id = ? AND attribute_id = ?", giaTri[i].ID, tt.ID).
				Updates(map[string]any{
					"code": giaTri[i].Code,
					"name": giaTri[i].Name,
				}).Error; err != nil {
				return err
			}
		}
		tt.GiaTri = giaTri

		return nil
	})

	return translateThuocTinhErr(err)
}

func (r *thuocTinhRepository) UpdateThan(ctx context.Context, tt *domain.ThuocTinh) error {
	return translateThuocTinhErr(ghiThanThuocTinh(r.db.WithContext(ctx), tt))
}

// Delete xoá mềm thuộc tính và xoá HẲN các giá trị của nó: giá trị không có
// thuộc tính cha thì chẳng còn nghĩa gì, giữ lại chỉ tổ chặn mã của lượt khai
// sau. Chưa bảng nào trỏ tới hai bảng này nên không phải chặn như bên nhà cung
// cấp; dựng biến thể mặt hàng thì thêm lượt đếm ở đây.
func (r *thuocTinhRepository) Delete(ctx context.Context, id uint) error {
	err := r.db.WithContext(ctx).Transaction(func(tx *gorm.DB) error {
		if err := tx.Where("attribute_id = ?", id).Delete(&domain.ThuocTinhGiaTri{}).Error; err != nil {
			return err
		}

		return tx.Delete(&domain.ThuocTinh{}, id).Error
	})

	return translateThuocTinhErr(err)
}

// DangDuocDung — chưa bảng nào trỏ tới thuộc tính (biến thể mặt hàng và định
// lượng nguyên liệu đều chưa dựng), nên tập rỗng. Trả về map thay vì bool để
// lượt dựng danh sách hỏi một lần cho cả trang, không phải mỗi dòng một câu.
func (r *thuocTinhRepository) DangDuocDung(_ context.Context, _ []uint) (map[uint]bool, error) {
	return map[uint]bool{}, nil
}

// ghiThanThuocTinh ghi đúng ba cột người dùng sửa được.
//
// Updates kèm map chứ không Save(): Save cũng lôi cả association GiaTri đã
// preload đi ghi lại, và Updates kèm struct thì bỏ qua trường zero-value nên
// tắt is_active sẽ không lưu được.
func ghiThanThuocTinh(db *gorm.DB, tt *domain.ThuocTinh) error {
	return db.Model(&domain.ThuocTinh{}).
		Where("id = ?", tt.ID).
		Updates(map[string]any{
			"code":      tt.Code,
			"name":      tt.Name,
			"is_active": tt.IsActive,
		}).Error
}

// translateThuocTinhErr chuyển lỗi DB thô sang lỗi nghiệp vụ để handler trả mã
// HTTP thân thiện.
func translateThuocTinhErr(err error) error {
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
