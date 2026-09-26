package repository

import (
	"context"
	"errors"

	"gorm.io/gorm"

	"sass-api/internal/domain"
)

type nhomKhachHangRepository struct{ db *gorm.DB }

func NewCustomerGroupRepository(db *gorm.DB) domain.CustomerGroupRepository {
	return &nhomKhachHangRepository{db: db}
}

func (r *nhomKhachHangRepository) List(ctx context.Context, onlyActive bool, loai *uint) ([]domain.CustomerGroup, error) {
	q := r.db.WithContext(ctx).Model(&domain.CustomerGroup{})
	if onlyActive {
		q = q.Where("status = ?", 1)
	}
	if loai != nil {
		q = q.Where("type = ?", *loai)
	}

	var ds []domain.CustomerGroup
	err := q.Order("name ASC").Find(&ds).Error

	return ds, err
}

func (r *nhomKhachHangRepository) FindByID(ctx context.Context, id uint) (*domain.CustomerGroup, error) {
	var g domain.CustomerGroup
	err := r.db.WithContext(ctx).First(&g, id).Error
	if errors.Is(err, gorm.ErrRecordNotFound) {
		return nil, domain.ErrNotFound
	}

	return &g, err
}

// ExistsByName chỉ xét dòng CHƯA XOÁ và CÙNG LOẠI — bảng không có UNIQUE KEY,
// xem migration 0061. "Bán lẻ" ở nhóm cá nhân và ở nhóm doanh nghiệp là hai
// dòng khác nhau, không phải trùng.
func (r *nhomKhachHangRepository) ExistsByName(ctx context.Context, loai uint, name string, ignoreID uint) (bool, error) {
	q := r.db.WithContext(ctx).Model(&domain.CustomerGroup{}).
		Where("type = ?", loai).Where("name = ?", name)
	if ignoreID > 0 {
		q = q.Where("id <> ?", ignoreID)
	}

	var n int64
	err := q.Count(&n).Error

	return n > 0, err
}

func (r *nhomKhachHangRepository) Create(ctx context.Context, g *domain.CustomerGroup) error {
	return r.db.WithContext(ctx).Create(g).Error
}

func (r *nhomKhachHangRepository) Update(ctx context.Context, g *domain.CustomerGroup) error {
	return r.db.WithContext(ctx).Save(g).Error
}

func (r *nhomKhachHangRepository) Delete(ctx context.Context, id uint) error {
	return r.db.WithContext(ctx).Delete(&domain.CustomerGroup{}, id).Error
}

func (r *nhomKhachHangRepository) CountCustomers(ctx context.Context, id uint) (int64, error) {
	var n int64
	err := r.db.WithContext(ctx).Model(&domain.User{}).
		Where("role_id = ?", domain.CustomerRoleID).
		Where("customer_group_id = ?", id).
		Count(&n).Error

	return n, err
}
