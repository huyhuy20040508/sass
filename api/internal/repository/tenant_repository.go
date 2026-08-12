package repository

import (
	"context"
	"errors"

	"sass-api/internal/domain"

	"gorm.io/gorm"
)

type tenantRepository struct{ db *gorm.DB }

func NewTenantRepository(db *gorm.DB) domain.TenantRepository {
	return &tenantRepository{db: db}
}

func (r *tenantRepository) FindByCode(ctx context.Context, code string) (*domain.Tenant, error) {
	var t domain.Tenant
	// Mã rỗng: chặn tại đây thay vì bắn truy vấn xuống CSDL mỗi lần có người gửi
	// form đăng nhập trống.
	if code == "" {
		return nil, domain.ErrNotFound
	}
	err := r.db.WithContext(ctx).Where("code = ?", code).First(&t).Error
	if errors.Is(err, gorm.ErrRecordNotFound) {
		return nil, domain.ErrNotFound
	}
	return &t, err
}
