package repository

import (
	"context"
	"errors"

	"sass-api/internal/domain"

	"gorm.io/gorm"
)

type roleRepository struct{ db *gorm.DB }

func NewRoleRepository(db *gorm.DB) domain.RoleRepository {
	return &roleRepository{db: db}
}

func (r *roleRepository) FindByName(ctx context.Context, name string) (*domain.Role, error) {
	var role domain.Role
	err := r.db.WithContext(ctx).Where("name = ?", name).First(&role).Error
	if errors.Is(err, gorm.ErrRecordNotFound) {
		return nil, domain.ErrNotFound
	}
	return &role, err
}

func (r *roleRepository) FindByID(ctx context.Context, id uint) (*domain.Role, error) {
	var role domain.Role
	err := r.db.WithContext(ctx).First(&role, id).Error
	if errors.Is(err, gorm.ErrRecordNotFound) {
		return nil, domain.ErrNotFound
	}
	return &role, err
}

// List trả về mọi vai trò theo id tăng dần — trùng thứ tự quyền từ cao xuống thấp
// trong seed (super_admin → admin → staff → customer).
func (r *roleRepository) List(ctx context.Context) ([]domain.Role, error) {
	var roles []domain.Role
	err := r.db.WithContext(ctx).Order("id ASC").Find(&roles).Error
	return roles, err
}

// Update chỉ ghi tên hiển thị và mô tả. Cột `name` cố tình không nằm trong danh
// sách cập nhật: đó là mã vai trò được so khớp ở middleware và ở gate của trang
// quản trị, đổi nó là khoá mọi người ra khỏi hệ thống.
func (r *roleRepository) Update(ctx context.Context, role *domain.Role) error {
	return r.db.WithContext(ctx).Model(&domain.Role{ID: role.ID}).
		Select("display_name", "description", "updated_at").
		Updates(role).Error
}

// CountUsers đếm tài khoản còn sống của từng vai trò (đã xoá mềm không tính).
func (r *roleRepository) CountUsers(ctx context.Context) (map[uint]int64, error) {
	var rows []struct {
		RoleID uint
		Total  int64
	}
	err := r.db.WithContext(ctx).Model(&domain.User{}).
		Select("role_id, COUNT(*) AS total").
		Group("role_id").
		Scan(&rows).Error
	if err != nil {
		return nil, err
	}

	out := make(map[uint]int64, len(rows))
	for _, row := range rows {
		out[row.RoleID] = row.Total
	}
	return out, nil
}
