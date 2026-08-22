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
