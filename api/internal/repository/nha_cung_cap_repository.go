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

type nhaCungCapRepository struct{ db *gorm.DB }

func NewNhaCungCapRepository(db *gorm.DB) domain.NhaCungCapRepository {
	return &nhaCungCapRepository{db: db}
}

func (r *nhaCungCapRepository) List(ctx context.Context, f domain.NhaCungCapFilter) ([]domain.NhaCungCap, error) {
	// Mới thêm lên đầu, cùng thứ tự với các danh mục khác; Shop Admin tự sắp lại
	// theo lựa chọn của người xem.
	q := r.db.WithContext(ctx).Order("id DESC")
	if f.OnlyActive {
		q = q.Where("is_active = ?", true)
	}
	if kw := strings.TrimSpace(f.Keyword); kw != "" {
		like := "%" + kw + "%"
		q = q.Where("(name LIKE ? OR code LIKE ? OR short_name LIKE ? OR phone LIKE ? OR tax_code LIKE ?)",
			like, like, like, like, like)
	}

	var list []domain.NhaCungCap
	if err := q.Find(&list).Error; err != nil {
		return nil, err
	}

	return list, nil
}

// TienTheoNCC — một câu gộp cho cả danh mục, khoá là supplier_id.
//
// GREATEST(...,0) chứ không phải hiệu của hai tổng: một phiếu trả dư không được
// bù cho phiếu còn thiếu, hai chuyện đó là hai khoản riêng với bên bán.
//
// Phiếu vãng lai (supplier_id NULL) không thuộc bên nào nên loại ra ngay ở đây.
func (r *nhaCungCapRepository) TienTheoNCC(ctx context.Context) (map[uint]domain.TienNCC, error) {
	type dong struct {
		SupplierID uint
		domain.TienNCC
	}

	var rows []dong
	err := r.db.WithContext(ctx).Model(&domain.PurchaseOrder{}).
		Select(`supplier_id,
		        SUM(total_amount) AS total_purchases,
		        SUM(paid_amount) AS total_payment,
		        SUM(GREATEST(total_amount - paid_amount, 0)) AS still_in_debt`).
		Where("status = ?", domain.PurchaseStatusApproved).
		Where("supplier_id IS NOT NULL").
		Group("supplier_id").
		Scan(&rows).Error
	if err != nil {
		return nil, err
	}

	tien := make(map[uint]domain.TienNCC, len(rows))
	for _, d := range rows {
		tien[d.SupplierID] = d.TienNCC
	}

	return tien, nil
}

func (r *nhaCungCapRepository) FindByID(ctx context.Context, id uint) (*domain.NhaCungCap, error) {
	var ncc domain.NhaCungCap
	err := r.db.WithContext(ctx).First(&ncc, id).Error
	if errors.Is(err, gorm.ErrRecordNotFound) {
		return nil, domain.ErrNotFound
	}
	if err != nil {
		return nil, err
	}

	return &ncc, nil
}

// ExistsByCode dùng Unscoped: mã của bên đã xoá mềm vẫn giữ chỗ trong UNIQUE
// index, báo trùng ở đây thân thiện hơn là để MySQL ném lỗi lúc ghi.
func (r *nhaCungCapRepository) ExistsByCode(ctx context.Context, code string, excludeID uint) (bool, error) {
	var count int64
	q := r.db.WithContext(ctx).Unscoped().Model(&domain.NhaCungCap{}).Where("code = ?", code)
	if excludeID > 0 {
		q = q.Where("id <> ?", excludeID)
	}
	err := q.Count(&count).Error

	return count > 0, err
}

// NextCode sinh mã kế tiếp dạng NCC001.
//
// Chỉ đọc các mã ĐÚNG KHUÔN NCC + chữ số, tính cả dòng đã xoá mềm; mã người dùng
// tự gõ (ANBINH, VIETTIEN…) không tham gia nên khai tay xen kẽ không làm hỏng dãy.
func (r *nhaCungCapRepository) NextCode(ctx context.Context) (string, error) {
	var codes []string
	if err := r.db.WithContext(ctx).Unscoped().
		Model(&domain.NhaCungCap{}).
		Where("code REGEXP ?", "^NCC[0-9]+$").
		Pluck("code", &codes).Error; err != nil {
		return "", err
	}

	max := 0
	for _, c := range codes {
		if n, err := strconv.Atoi(strings.TrimPrefix(c, "NCC")); err == nil && n > max {
			max = n
		}
	}

	return fmt.Sprintf("NCC%03d", max+1), nil
}

func (r *nhaCungCapRepository) Create(ctx context.Context, ncc *domain.NhaCungCap) error {
	return translateNhaCungCapErr(r.db.WithContext(ctx).Create(ncc).Error)
}

func (r *nhaCungCapRepository) Update(ctx context.Context, ncc *domain.NhaCungCap) error {
	return translateNhaCungCapErr(r.db.WithContext(ctx).Save(ncc).Error)
}

// Delete xoá mềm.
func (r *nhaCungCapRepository) Delete(ctx context.Context, id uint) error {
	return translateNhaCungCapErr(r.db.WithContext(ctx).Delete(&domain.NhaCungCap{}, id).Error)
}

func translateNhaCungCapErr(err error) error {
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

// ExistsByName — trùng TÊN trong cùng cửa hàng, không phân biệt hoa thường.
// Hai bên cùng tên thì lúc lập phiếu mua nhìn ô chọn ra hai dòng y hệt nhau,
// chọn nhầm bên nào cũng không biết, mà công nợ thì tách đôi.
//
// KHÔNG Unscoped: tên không bị khoá duy nhất ở tầng DB, nên xoá xong phải dùng
// lại tên được.
func (r *nhaCungCapRepository) ExistsByName(ctx context.Context, name string, excludeID uint) (bool, error) {
	var count int64
	q := r.db.WithContext(ctx).Model(&domain.NhaCungCap{}).
		Where("LOWER(name) COLLATE utf8mb4_bin = LOWER(?)", name)
	if excludeID > 0 {
		q = q.Where("id <> ?", excludeID)
	}
	err := q.Count(&count).Error

	return count > 0, err
}
