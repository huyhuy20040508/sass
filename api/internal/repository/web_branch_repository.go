package repository

import (
	"context"
	"errors"

	"gorm.io/gorm"

	"sass-api/internal/domain"
	"sass-api/internal/tenant"
)

// webChiNhanhRepository: banHang là kết nối DATA PLANE (chỉ đọc chi nhánh), nenTang
// là CONTROL PLANE (NewPlatformDB) — sổ tên miền.
type webChiNhanhRepository struct{ banHang, nenTang *gorm.DB }

func NewWebChiNhanhRepository(banHang, nenTang *gorm.DB) domain.WebChiNhanhRepository {
	return &webChiNhanhRepository{banHang: banHang, nenTang: nenTang}
}

func (r *webChiNhanhRepository) ChiNhanh(ctx context.Context, tenantID uint) ([]domain.ChiNhanhCuaKhach, error) {
	var rows []domain.ChiNhanh
	// ctx mang tenant để bộ lọc tenant tự chèn tenant_id — khu điều hành không có
	// tenant trong token. Chi nhánh đã xoá mềm tự rơi ra (DeletedAt).
	err := r.banHang.WithContext(tenant.WithID(ctx, tenantID)).Order("id").Find(&rows).Error
	if err != nil {
		return nil, err
	}
	kq := make([]domain.ChiNhanhCuaKhach, 0, len(rows))
	for _, c := range rows {
		kq = append(kq, domain.ChiNhanhCuaKhach{ID: c.ID, Ma: c.Code, Ten: c.Name, DangMo: c.IsActive})
	}

	return kq, nil
}

func (r *webChiNhanhRepository) TenMien(ctx context.Context, tenantID, appID uint) ([]domain.TenMienWeb, error) {
	var rows []domain.TenMienWeb
	err := r.nenTang.WithContext(ctx).Table("tenant_domains").
		Select("tenant_id, app_id, shop_id, host").
		Where("tenant_id = ? AND app_id = ? AND shop_id IS NOT NULL", tenantID, appID).
		// Chi nhánh còn giữ tên miền phụ (đổi tên): tên miền chính đứng sau cùng để thắng.
		Order("is_primary, id").Scan(&rows).Error

	return rows, err
}

func (r *webChiNhanhRepository) ChuCuaHost(ctx context.Context, host string) (uint, error) {
	var chu uint
	err := r.nenTang.WithContext(ctx).Table("tenant_domains").Select("tenant_id").
		Where("host = ?", host).Limit(1).Scan(&chu).Error
	if errors.Is(err, gorm.ErrRecordNotFound) {
		return 0, nil
	}

	return chu, err
}

func (r *webChiNhanhRepository) Them(ctx context.Context, tm domain.TenMienWeb) error {
	// Mỗi web chi nhánh đúng một địa chỉ, và đó là địa chỉ chính (in lên QR, hoá đơn).
	return r.nenTang.WithContext(ctx).Exec(
		`INSERT INTO tenant_domains (tenant_id, app_id, shop_id, host, kind, is_primary, created_at, updated_at)
		 VALUES (?, ?, ?, ?, 'subdomain', 1, NOW(3), NOW(3))`,
		tm.TenantID, tm.AppID, tm.ShopID, tm.Host).Error
}

func (r *webChiNhanhRepository) Xoa(ctx context.Context, tenantID, appID, shopID uint) (int64, error) {
	res := r.nenTang.WithContext(ctx).Exec(
		"DELETE FROM tenant_domains WHERE tenant_id = ? AND app_id = ? AND shop_id = ?", tenantID, appID, shopID)

	return res.RowsAffected, res.Error
}
