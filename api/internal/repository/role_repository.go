package repository

import (
	"context"
	"errors"

	"sass-api/internal/domain"

	"gorm.io/gorm"
	"gorm.io/gorm/clause"
)

type roleRepository struct{ db *gorm.DB }

func NewRoleRepository(db *gorm.DB) domain.RoleRepository {
	return &roleRepository{db: db}
}

// FindByName tra vai trò theo MÃ (`name`).
//
// KHÔNG đè nhãn của cửa hàng: đường này chỉ dùng để đổi một mã vai trò lấy id
// (luồng đăng ký tìm vai trò customer), không chỗ nào in tên ra màn hình. Đè
// nhãn ở đây là thêm một câu truy vấn vào mọi lượt đăng ký để không ai đọc.
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
	if err != nil {
		return nil, err
	}

	var label domain.RoleLabel
	err = r.db.WithContext(ctx).Where("role_id = ?", id).First(&label).Error
	switch {
	case errors.Is(err, gorm.ErrRecordNotFound):
		// Cửa hàng chưa đặt tên riêng — giữ tên mặc định của roles.
	case err != nil:
		return nil, err
	default:
		role.ApplyLabel(&label)
	}

	return &role, nil
}

// List trả về mọi vai trò theo id tăng dần — trùng thứ tự quyền từ cao xuống thấp
// trong seed (super_admin → admin → staff → customer) — đã đè nhãn của cửa hàng.
func (r *roleRepository) List(ctx context.Context) ([]domain.Role, error) {
	var roles []domain.Role
	if err := r.db.WithContext(ctx).Order("id ASC").Find(&roles).Error; err != nil {
		return nil, err
	}

	nhan, err := r.Labels(ctx)
	if err != nil {
		return nil, err
	}
	for i := range roles {
		if n, ok := nhan[roles[i].ID]; ok {
			roles[i].ApplyLabel(&n)
		}
	}

	return roles, nil
}

// Labels đọc nhãn của cửa hàng trong ctx. Bảng role_labels có cột tenant_id nên
// bộ lọc tự chèn điều kiện — không có cách nào đọc nhầm nhãn của cửa hàng khác.
func (r *roleRepository) Labels(ctx context.Context) (map[uint]domain.RoleLabel, error) {
	var rows []domain.RoleLabel
	if err := r.db.WithContext(ctx).Find(&rows).Error; err != nil {
		return nil, err
	}

	out := make(map[uint]domain.RoleLabel, len(rows))
	for _, row := range rows {
		out[row.RoleID] = row
	}

	return out, nil
}

// SetLabel ghi nhãn của cửa hàng, thêm mới nếu chưa có.
//
// Upsert chứ không "đọc rồi quyết định thêm hay sửa": hai người cùng bấm Lưu thì
// đường đọc-rồi-ghi sẽ có một người thua ở khoá unique và nhận về lỗi 500 chẳng
// nói lên điều gì. tenant_id do bộ lọc tự điền lúc tạo dòng.
func (r *roleRepository) SetLabel(ctx context.Context, roleID uint, displayName, description string) error {
	row := &domain.RoleLabel{RoleID: roleID, DisplayName: displayName, Description: description}

	return r.db.WithContext(ctx).Clauses(clause.OnConflict{
		Columns:   []clause.Column{{Name: "tenant_id"}, {Name: "role_id"}},
		DoUpdates: clause.AssignmentColumns([]string{"display_name", "description", "updated_at"}),
	}).Create(row).Error
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
