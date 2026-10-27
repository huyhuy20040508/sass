package repository

import (
	"context"
	"time"

	"sass-api/internal/domain"
)

// Chỉ đếm phiếu lập tay: phiếu tự sinh (phí thẻ, bán hàng, trả hàng) không phải
// việc thu ngân chọn loại, tính vào là "Phí thanh toán" luôn đứng đầu dãy.
func (r *thuChiRepository) PhanLoaiHayDung(ctx context.Context, shopID uint, tu time.Time) ([]uint, error) {
	var ids []uint
	err := r.db.WithContext(ctx).Model(&domain.ThuChi{}).
		Where("shop_id = ? AND source = ? AND category_id IS NOT NULL AND created_at >= ?", shopID, domain.ThuChiTuTay, tu).
		Group("category_id").
		Order("COUNT(*) DESC, MAX(created_at) DESC").
		Pluck("category_id", &ids).Error

	return ids, err
}

func (r *thuChiRepository) NguoiDuyet(ctx context.Context, username string) (*domain.NguoiDuyetThuChi, error) {
	var u struct {
		ID           uint
		RoleID       uint
		Status       string
		PasswordHash string
	}
	err := r.db.WithContext(ctx).Model(&domain.User{}).
		Select("id, role_id, status, password_hash").
		Where("username = ?", username).
		Limit(1).Scan(&u).Error
	if err != nil || u.ID == 0 {
		return nil, err
	}
	vai := ""
	switch u.RoleID {
	case domain.SuperAdminRoleID:
		vai = domain.RoleSuperAdmin
	case domain.AdminRoleID:
		vai = domain.RoleAdmin
	}
	return &domain.NguoiDuyetThuChi{ID: u.ID, Role: vai, Status: u.Status, PasswordHash: u.PasswordHash}, nil
}
