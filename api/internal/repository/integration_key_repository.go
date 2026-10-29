package repository

import (
	"context"
	"errors"
	"time"

	"gorm.io/gorm"

	"sass-api/internal/domain"
	"sass-api/internal/tenant"
)

type integrationKeyRepository struct {
	db *gorm.DB
}

// NewIntegrationKeyRepository chạy trên DATA PLANE (có bộ lọc tenant), trừ
// TheoBam — xem chú thích ở đó.
func NewIntegrationKeyRepository(db *gorm.DB) domain.IntegrationKeyRepository {
	return &integrationKeyRepository{db: db}
}

func (r *integrationKeyRepository) TheoBam(ctx context.Context, bam string) (*domain.ChuKhoaTichHop, error) {
	ctx = tenant.WithoutScope(ctx, "khoá tích hợp: chính khoá là thứ cho biết cửa hàng nào")
	var kq struct {
		KeyID        uint
		TenantID     uint
		TenantStatus string
	}
	err := r.db.WithContext(ctx).
		Table("integration_keys k").
		Select("k.id AS key_id, k.tenant_id, t.status AS tenant_status").
		Joins("JOIN tenants t ON t.id = k.tenant_id").
		Where("k.key_hash = ? AND k.revoked_at IS NULL", bam).
		Take(&kq).Error
	if errors.Is(err, gorm.ErrRecordNotFound) {
		return nil, domain.ErrNotFound
	}
	if err != nil {
		return nil, err
	}

	return &domain.ChuKhoaTichHop{KeyID: kq.KeyID, TenantID: kq.TenantID, TenantStatus: kq.TenantStatus}, nil
}

func (r *integrationKeyRepository) DangDung(ctx context.Context, app string) (*domain.IntegrationKey, error) {
	var k domain.IntegrationKey
	err := r.db.WithContext(ctx).Where("app = ? AND revoked_at IS NULL", app).Order("id DESC").Take(&k).Error
	if errors.Is(err, gorm.ErrRecordNotFound) {
		return nil, domain.ErrNotFound
	}

	return &k, err
}

func (r *integrationKeyRepository) CapMoi(ctx context.Context, k *domain.IntegrationKey) error {
	return r.db.WithContext(ctx).Transaction(func(tx *gorm.DB) error {
		if err := tx.Model(&domain.IntegrationKey{}).
			Where("app = ? AND revoked_at IS NULL", k.App).
			Update("revoked_at", time.Now()).Error; err != nil {
			return err
		}

		return tx.Create(k).Error
	})
}

func (r *integrationKeyRepository) ThuHoi(ctx context.Context, app string) (int64, error) {
	res := r.db.WithContext(ctx).Model(&domain.IntegrationKey{}).
		Where("app = ? AND revoked_at IS NULL", app).
		Update("revoked_at", time.Now())

	return res.RowsAffected, res.Error
}

func (r *integrationKeyRepository) GhiLanDung(ctx context.Context, id uint) error {
	bay := time.Now()
	return r.db.WithContext(ctx).Model(&domain.IntegrationKey{}).
		Where("id = ? AND (last_used_at IS NULL OR last_used_at < ?)", id, bay.Add(-time.Minute)).
		UpdateColumn("last_used_at", bay).Error
}
