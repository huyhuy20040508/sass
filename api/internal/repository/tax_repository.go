package repository

import (
	"context"
	"errors"

	"gorm.io/gorm"
	"gorm.io/gorm/clause"

	"sass-api/internal/domain"
)

type thueRepository struct{ db *gorm.DB }

// NewThueRepository dựng sổ thuế suất trên DATA PLANE.
func NewThueRepository(db *gorm.DB) domain.ThueRepository {
	return &thueRepository{db: db}
}

func (r *thueRepository) List(ctx context.Context) ([]domain.Thue, error) {
	var ds []domain.Thue
	if err := r.db.WithContext(ctx).Model(&domain.Thue{}).Order("id ASC").Find(&ds).Error; err != nil {
		return nil, err
	}

	return ds, nil
}

func (r *thueRepository) FindByID(ctx context.Context, id uint) (*domain.Thue, error) {
	var t domain.Thue
	err := r.db.WithContext(ctx).Where("id = ?", id).Take(&t).Error
	if errors.Is(err, gorm.ErrRecordNotFound) {
		return nil, domain.ErrNotFound
	}
	if err != nil {
		return nil, err
	}

	return &t, nil
}

func (r *thueRepository) TheoLoai(ctx context.Context, loai string) (*domain.Thue, error) {
	var t domain.Thue
	err := r.db.WithContext(ctx).Where("tax_type = ?", loai).Take(&t).Error
	if errors.Is(err, gorm.ErrRecordNotFound) {
		return nil, domain.ErrNotFound
	}
	if err != nil {
		return nil, err
	}

	return &t, nil
}

func (r *thueRepository) Luu(ctx context.Context, t *domain.Thue) error {
	return r.db.WithContext(ctx).Save(t).Error
}

// TaoThieu chèn những loại cửa hàng chưa có. DoNothing dựa vào khoá duy nhất
// (tenant_id, tax_type): hai lượt mở màn hình cùng lúc thì lượt sau không tạo
// bản thứ hai, và cũng không đè lên mức người dùng vừa sửa.
func (r *thueRepository) TaoThieu(ctx context.Context, ds []domain.Thue) error {
	if len(ds) == 0 {
		return nil
	}

	return r.db.WithContext(ctx).Clauses(clause.OnConflict{DoNothing: true}).Create(&ds).Error
}
