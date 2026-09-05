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

type categoryRepository struct{ db *gorm.DB }

func NewCategoryRepository(db *gorm.DB) domain.CategoryRepository {
	return &categoryRepository{db: db}
}

func (r *categoryRepository) List(ctx context.Context, onlyActive bool) ([]domain.Category, error) {
	var cats []domain.Category
	q := r.db.WithContext(ctx).Order("sort_order ASC, id ASC")
	if onlyActive {
		q = q.Where("is_active = ?", true)
	}
	err := q.Find(&cats).Error
	return cats, err
}

// ListCoHang — xem chú thích ở domain.CategoryRepository.
//
// Đếm bằng EXISTS chứ không JOIN + DISTINCT: chỉ cần biết nhóm có hay không có
// mặt hàng, mà EXISTS thì database dừng ngay ở dòng đầu tiên tìm được.
//
// Bộ điều kiện lấy đúng của bảng tồn kho (xem TonTheoChiNhanh): chỉ loại dòng
// đã xoá mềm, KHÔNG loại hàng ngừng bán — bảng tồn kho vẫn liệt kê hàng ngừng
// bán, nên nhóm chứa toàn hàng ngừng bán mà biến mất khỏi ô lọc thì ô lọc và
// bảng nói hai chuyện khác nhau.
func (r *categoryRepository) ListCoHang(ctx context.Context, onlyActive bool) ([]domain.Category, error) {
	var cats []domain.Category
	q := r.db.WithContext(ctx).Model(&domain.Category{}).
		Order("sort_order ASC, id ASC").
		Where(`EXISTS (
			SELECT 1 FROM products p
			JOIN product_variants v ON v.product_id = p.id AND v.deleted_at IS NULL
			WHERE p.category_id = categories.id AND p.deleted_at IS NULL
		)`)
	if onlyActive {
		q = q.Where("is_active = ?", true)
	}
	err := q.Find(&cats).Error

	return cats, err
}

func (r *categoryRepository) FindByID(ctx context.Context, id uint) (*domain.Category, error) {
	var c domain.Category
	err := r.db.WithContext(ctx).First(&c, id).Error
	if errors.Is(err, gorm.ErrRecordNotFound) {
		return nil, domain.ErrNotFound
	}
	return &c, err
}

func (r *categoryRepository) FindBySlug(ctx context.Context, slug string) (*domain.Category, error) {
	var c domain.Category
	err := r.db.WithContext(ctx).Where("slug = ?", slug).First(&c).Error
	if errors.Is(err, gorm.ErrRecordNotFound) {
		return nil, domain.ErrNotFound
	}
	return &c, err
}

func (r *categoryRepository) ExistsBySlug(ctx context.Context, slug string, excludeID uint) (bool, error) {
	var count int64
	// Unscoped: danh mục xoá mềm vẫn chiếm slug trong UNIQUE index (uq_categories_slug
	// chỉ trên cột slug) nên phải tính vào — nhất quán với kiểm tra email ở user repo.
	q := r.db.WithContext(ctx).Unscoped().Model(&domain.Category{}).Where("slug = ?", slug)
	if excludeID > 0 {
		q = q.Where("id <> ?", excludeID)
	}
	err := q.Count(&count).Error
	return count > 0, err
}

// ExistsByName — trùng TÊN trong cùng cửa hàng, không phân biệt hoa thường.
//
// KHÔNG Unscoped, khác hẳn ExistsBySlug ở trên: mã bị khoá duy nhất ở tầng DB
// nên nhóm đã xoá vẫn giữ chỗ, còn tên thì không — xoá một nhóm rồi mà tên nó
// vẫn cấm dùng lại thì người dùng không có đường nào gỡ.
func (r *categoryRepository) ExistsByName(ctx context.Context, name string, excludeID uint) (bool, error) {
	var count int64
	q := r.db.WithContext(ctx).Model(&domain.Category{}).
		Where("LOWER(name) COLLATE utf8mb4_bin = LOWER(?)", name)
	if excludeID > 0 {
		q = q.Where("id <> ?", excludeID)
	}
	err := q.Count(&count).Error

	return count > 0, err
}

// NextCode dò mã lớn nhất trong dải NH rồi cộng một.
//
// Unscoped: mã của nhóm đã xoá mềm vẫn giữ chỗ trong khoá duy nhất.
func (r *categoryRepository) NextCode(ctx context.Context) (string, error) {
	var codes []string
	if err := r.db.WithContext(ctx).Unscoped().
		Model(&domain.Category{}).
		Where("slug REGEXP ?", "^NH[0-9]+$").
		Pluck("slug", &codes).Error; err != nil {
		return "", err
	}

	max := 0
	for _, c := range codes {
		if n, err := strconv.Atoi(strings.TrimPrefix(c, "NH")); err == nil && n > max {
			max = n
		}
	}

	return fmt.Sprintf("NH%04d", max+1), nil
}

func (r *categoryRepository) Create(ctx context.Context, c *domain.Category) error {
	return translateCategoryErr(r.db.WithContext(ctx).Create(c).Error)
}

func (r *categoryRepository) Update(ctx context.Context, c *domain.Category) error {
	return translateCategoryErr(r.db.WithContext(ctx).Save(c).Error)
}

// Delete xóa mềm (soft delete) để nhất quán với product.
// Dữ liệu danh mục vẫn được giữ lại trong DB phục vụ truy vấn lịch sử.
func (r *categoryRepository) Delete(ctx context.Context, id uint) error {
	return translateCategoryErr(r.db.WithContext(ctx).Delete(&domain.Category{}, id).Error)
}

// translateCategoryErr chuyển lỗi DB thô sang lỗi nghiệp vụ để handler trả mã HTTP thân thiện.
func translateCategoryErr(err error) error {
	switch {
	case err == nil:
		return nil
	case errors.Is(err, gorm.ErrDuplicatedKey):
		return domain.ErrSlugExists
	case errors.Is(err, gorm.ErrForeignKeyViolated):
		return domain.ErrConflict
	default:
		return err
	}
}
