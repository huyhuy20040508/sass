package repository

import (
	"context"
	"errors"

	"gorm.io/gorm"

	"sass-api/internal/domain"
)

type brandRepository struct{ db *gorm.DB }

func NewBrandRepository(db *gorm.DB) domain.BrandRepository {
	return &brandRepository{db: db}
}

func (r *brandRepository) List(ctx context.Context, onlyActive bool) ([]domain.Brand, error) {
	var brands []domain.Brand
	q := r.db.WithContext(ctx).Order("name ASC")
	if onlyActive {
		q = q.Where("is_active = ?", true)
	}
	if err := q.Find(&brands).Error; err != nil {
		return nil, err
	}
	if err := r.fillProductCounts(ctx, brands); err != nil {
		return nil, err
	}
	return brands, nil
}

func (r *brandRepository) FindByID(ctx context.Context, id uint) (*domain.Brand, error) {
	var b domain.Brand
	err := r.db.WithContext(ctx).First(&b, id).Error
	if errors.Is(err, gorm.ErrRecordNotFound) {
		return nil, domain.ErrNotFound
	}
	if err != nil {
		return nil, err
	}
	one := []domain.Brand{b}
	if err := r.fillProductCounts(ctx, one); err != nil {
		return nil, err
	}
	return &one[0], nil
}

// fillProductCounts đếm sản phẩm theo brand_id bằng MỘT truy vấn gộp rồi gán vào
// từng phần tử (tránh N+1 khi danh sách thương hiệu dài).
func (r *brandRepository) fillProductCounts(ctx context.Context, brands []domain.Brand) error {
	if len(brands) == 0 {
		return nil
	}

	ids := make([]uint, 0, len(brands))
	for _, b := range brands {
		ids = append(ids, b.ID)
	}

	var rows []struct {
		BrandID uint
		Total   int64
	}
	// Model(&domain.Product{}) để GORM tự thêm điều kiện deleted_at IS NULL.
	if err := r.db.WithContext(ctx).
		Model(&domain.Product{}).
		Select("brand_id, COUNT(*) AS total").
		Where("brand_id IN ?", ids).
		Group("brand_id").
		Scan(&rows).Error; err != nil {
		return err
	}

	counts := make(map[uint]int64, len(rows))
	for _, row := range rows {
		counts[row.BrandID] = row.Total
	}
	for i := range brands {
		brands[i].ProductCount = counts[brands[i].ID]
	}

	return nil
}

func (r *brandRepository) ExistsBySlug(ctx context.Context, slug string, excludeID uint) (bool, error) {
	var count int64
	q := r.db.WithContext(ctx).Model(&domain.Brand{}).Where("slug = ?", slug)
	if excludeID > 0 {
		q = q.Where("id <> ?", excludeID)
	}
	err := q.Count(&count).Error
	return count > 0, err
}

func (r *brandRepository) Create(ctx context.Context, b *domain.Brand) error {
	return translateBrandErr(r.db.WithContext(ctx).Create(b).Error)
}

func (r *brandRepository) Update(ctx context.Context, b *domain.Brand) error {
	return translateBrandErr(r.db.WithContext(ctx).Save(b).Error)
}

func (r *brandRepository) Delete(ctx context.Context, id uint) error {
	return translateBrandErr(r.db.WithContext(ctx).Delete(&domain.Brand{}, id).Error)
}

// translateBrandErr chuyển lỗi DB thô sang lỗi nghiệp vụ để handler trả mã HTTP thân thiện.
func translateBrandErr(err error) error {
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
