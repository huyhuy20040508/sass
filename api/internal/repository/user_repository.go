package repository

import (
	"context"
	"errors"
	"strings"
	"time"

	"sass-api/internal/domain"

	"gorm.io/gorm"
)

type userRepository struct{ db *gorm.DB }

func NewUserRepository(db *gorm.DB) domain.UserRepository {
	return &userRepository{db: db}
}

func (r *userRepository) Create(ctx context.Context, u *domain.User) error {
	return translateUserErr(r.db.WithContext(ctx).Create(u).Error)
}

func (r *userRepository) Update(ctx context.Context, u *domain.User) error {
	return translateUserErr(r.db.WithContext(ctx).Save(u).Error)
}

// translateUserErr đổi lỗi DB thô sang lỗi nghiệp vụ để handler trả mã HTTP thân thiện
// (email đã có UNIQUE index uq_users_email, kể cả với tài khoản đã xoá mềm).
func translateUserErr(err error) error {
	switch {
	case err == nil:
		return nil
	case errors.Is(err, gorm.ErrDuplicatedKey):
		return domain.ErrEmailExists
	case errors.Is(err, gorm.ErrForeignKeyViolated):
		return domain.ErrConflict
	default:
		return err
	}
}

func (r *userRepository) Delete(ctx context.Context, id uint) error {
	return r.db.WithContext(ctx).Delete(&domain.User{}, id).Error
}

func (r *userRepository) FindByID(ctx context.Context, id uint) (*domain.User, error) {
	var u domain.User
	err := r.db.WithContext(ctx).Preload("Role").First(&u, id).Error
	if errors.Is(err, gorm.ErrRecordNotFound) {
		return nil, domain.ErrNotFound
	}
	return &u, err
}

func (r *userRepository) FindByEmail(ctx context.Context, email string) (*domain.User, error) {
	var u domain.User
	err := r.db.WithContext(ctx).Preload("Role").Where("email = ?", email).First(&u).Error
	if errors.Is(err, gorm.ErrRecordNotFound) {
		return nil, domain.ErrNotFound
	}
	return &u, err
}

func (r *userRepository) FindByTenantUsername(ctx context.Context, tenantID uint, username string) (*domain.User, error) {
	var u domain.User
	// Chuỗi rỗng khớp với... không gì cả (tài khoản khách hàng để NULL), nhưng chặn
	// từ đây cho khỏi bắn một câu truy vấn vô nghĩa xuống CSDL mỗi lần ai đó gửi
	// form rỗng. tenantID = 0 cũng vậy: không có cửa hàng nào mang id đó.
	if tenantID == 0 || username == "" {
		return nil, domain.ErrNotFound
	}
	err := r.db.WithContext(ctx).Preload("Role").
		Where("tenant_id = ? AND username = ?", tenantID, username).
		First(&u).Error
	if errors.Is(err, gorm.ErrRecordNotFound) {
		return nil, domain.ErrNotFound
	}
	return &u, err
}

func (r *userRepository) FindByFacebookID(ctx context.Context, fbID string) (*domain.User, error) {
	var u domain.User
	// Chuỗi rỗng khớp với mọi tài khoản chưa liên kết — chặn từ đây cho chắc, kể cả
	// khi tầng trên đã kiểm tra.
	if fbID == "" {
		return nil, domain.ErrNotFound
	}
	err := r.db.WithContext(ctx).Preload("Role").Where("facebook_id = ?", fbID).First(&u).Error
	if errors.Is(err, gorm.ErrRecordNotFound) {
		return nil, domain.ErrNotFound
	}
	return &u, err
}

func (r *userRepository) FindByGoogleID(ctx context.Context, ggID string) (*domain.User, error) {
	var u domain.User
	// Chuỗi rỗng khớp với mọi tài khoản chưa liên kết — chặn từ đây cho chắc, kể cả
	// khi tầng trên đã kiểm tra.
	if ggID == "" {
		return nil, domain.ErrNotFound
	}
	err := r.db.WithContext(ctx).Preload("Role").Where("google_id = ?", ggID).First(&u).Error
	if errors.Is(err, gorm.ErrRecordNotFound) {
		return nil, domain.ErrNotFound
	}
	return &u, err
}

func (r *userRepository) ExistsByEmail(ctx context.Context, email string) (bool, error) {
	return r.ExistsByEmailExcept(ctx, email, 0)
}

func (r *userRepository) ExistsByEmailExcept(ctx context.Context, email string, excludeID uint) (bool, error) {
	// CHỈ tài khoản đang sống. Từ migration 0056, uq_users_email có thêm cột
	// deleted_mark nên tài khoản đã xoá không giữ email nữa — đếm cả chúng vào là
	// tự dựng lại đúng cái tường vừa dỡ: xoá hồ sơ nhân sự xong không khai lại
	// được người đó, mà màn hình chẳng cho thấy ai đang dùng email ấy.
	q := r.db.WithContext(ctx).Model(&domain.User{}).Where("email = ?", email)
	if excludeID > 0 {
		q = q.Where("id <> ?", excludeID)
	}

	var count int64
	err := q.Count(&count).Error
	return count > 0, err
}

func (r *userRepository) ExistsByUsernameExcept(ctx context.Context, username string, excludeID uint) (bool, error) {
	// Tên rỗng nghĩa là "không đặt tên đăng nhập" — ghi xuống là NULL, mà nhiều
	// dòng NULL thì UNIQUE cho phép thoải mái, nên không có gì để đụng nhau.
	if username == "" {
		return false, nil
	}

	// Không tự viết `tenant_id = ?`: bộ lọc ở tenant_scope.go chèn sẵn vào mọi câu
	// truy vấn, nên điều kiện ở đây khớp đúng khoá thật (tenant_id, username) —
	// hai cửa hàng cùng có tài khoản 'admin' là bình thường và không được báo trùng.
	//
	// Chỉ tài khoản đang sống, cùng lý do với ExistsByEmailExcept: từ migration
	// 0056 uq_users_username không giữ chỗ cho tài khoản đã xoá nữa.
	q := r.db.WithContext(ctx).Model(&domain.User{}).Where("username = ?", username)
	if excludeID > 0 {
		q = q.Where("id <> ?", excludeID)
	}

	var count int64
	err := q.Count(&count).Error
	return count > 0, err
}

func (r *userRepository) ListCustomers(ctx context.Context, f domain.CustomerFilter) ([]domain.User, int64, error) {
	q := r.db.WithContext(ctx).Model(&domain.User{}).Where("role_id = ?", domain.CustomerRoleID)

	if f.Keyword != "" {
		kw := "%" + f.Keyword + "%"
		// Bọc trong một Where duy nhất để nhóm OR không "ăn" mất điều kiện role_id.
		q = q.Where("(full_name LIKE ? OR email LIKE ? OR phone LIKE ?)", kw, kw, kw)
	}

	if f.Status != "" && f.Status != "all" {
		q = q.Where("status = ?", f.Status)
	}

	if f.Gender != "" && f.Gender != "all" {
		q = q.Where("gender = ?", f.Gender)
	}

	var total int64
	if err := q.Count(&total).Error; err != nil {
		return nil, 0, err
	}

	switch f.Sort {
	case "oldest":
		q = q.Order("id ASC")
	case "name_asc":
		q = q.Order("full_name ASC")
	case "name_desc":
		q = q.Order("full_name DESC")
	case "spent_desc":
		q = q.Order("(" + spentSubQuery + ") DESC")
	default:
		q = q.Order("id DESC")
	}

	page := f.Page
	if page < 1 {
		page = 1
	}
	pageSize := f.PageSize
	if pageSize < 1 {
		pageSize = 10
	}
	offset := (page - 1) * pageSize
	q = q.Offset(offset).Limit(pageSize)

	var users []domain.User
	err := q.Preload("Role").Find(&users).Error
	return users, total, err
}

// ListInternal liệt kê tài khoản nội bộ (mọi vai trò trừ customer).
//
// Cùng bảng users với ListCustomers nhưng ngược điều kiện vai trò, nên hai luồng
// không bao giờ nhìn thấy dữ liệu của nhau: trang nhân viên không lẫn khách hàng
// và ngược lại.
func (r *userRepository) ListInternal(ctx context.Context, f domain.InternalUserFilter) ([]domain.User, int64, error) {
	q := r.db.WithContext(ctx).Model(&domain.User{}).Where("role_id IN ?", domain.InternalRoleIDs)

	if f.RoleID > 0 {
		q = q.Where("role_id = ?", f.RoleID)
	}

	if f.Keyword != "" {
		kw := "%" + f.Keyword + "%"
		// Bọc trong một Where duy nhất để nhóm OR không "ăn" mất điều kiện vai trò.
		// Có cả username: từ khi đăng nhập bằng 3 ô, nhân viên báo hỏng thường chỉ
		// đọc được tên đăng nhập của mình chứ không nhớ email đã khai là gì.
		q = q.Where("(full_name LIKE ? OR username LIKE ? OR email LIKE ? OR phone LIKE ?)", kw, kw, kw, kw)
	}

	if f.Status != "" && f.Status != "all" {
		q = q.Where("status = ?", f.Status)
	}

	if f.FromDate != nil {
		q = q.Where("created_at >= ?", *f.FromDate)
	}
	if f.ToDate != nil {
		q = q.Where("created_at <= ?", *f.ToDate)
	}

	var total int64
	if err := q.Count(&total).Error; err != nil {
		return nil, 0, err
	}

	switch f.Sort {
	case "oldest":
		q = q.Order("id ASC")
	case "name_asc":
		q = q.Order("full_name ASC")
	case "name_desc":
		q = q.Order("full_name DESC")
	default:
		q = q.Order("id DESC")
	}

	page := f.Page
	if page < 1 {
		page = 1
	}
	pageSize := f.PageSize
	if pageSize < 1 {
		pageSize = 20
	}
	q = q.Offset((page - 1) * pageSize).Limit(pageSize)

	var users []domain.User
	err := q.Preload("Role").Find(&users).Error
	return users, total, err
}

func (r *userRepository) InternalStats(ctx context.Context) (domain.InternalUserStats, error) {
	var rows []struct {
		Status string
		Total  int64
	}
	err := r.db.WithContext(ctx).Model(&domain.User{}).
		Select("status, COUNT(*) AS total").
		Where("role_id IN ?", domain.InternalRoleIDs).
		Group("status").
		Scan(&rows).Error

	var stats domain.InternalUserStats
	if err != nil {
		return stats, err
	}
	for _, row := range rows {
		stats.Total += row.Total
		switch row.Status {
		case "active":
			stats.Active = row.Total
		default:
			stats.Inactive += row.Total
		}
	}
	return stats, nil
}

func (r *userRepository) CountActiveByRole(ctx context.Context, roleID, excludeID uint) (int64, error) {
	q := r.db.WithContext(ctx).Model(&domain.User{}).
		Where("role_id = ?", roleID).
		Where("status = ?", "active")
	if excludeID > 0 {
		q = q.Where("id <> ?", excludeID)
	}

	var count int64
	err := q.Count(&count).Error
	return count, err
}

// Đơn đã huỷ/hoàn không tính vào doanh số của khách hàng.
const spentSubQuery = `SELECT COALESCE(SUM(o.total_amount), 0) FROM orders o
	WHERE o.user_id = users.id AND o.deleted_at IS NULL AND o.status NOT IN ('cancelled', 'returned')`

func (r *userRepository) CustomerStats(ctx context.Context) (domain.CustomerStats, error) {
	var rows []struct {
		Status string
		Total  int64
	}
	err := r.db.WithContext(ctx).Model(&domain.User{}).
		Select("status, COUNT(*) AS total").
		Where("role_id = ?", domain.CustomerRoleID).
		Group("status").
		Scan(&rows).Error

	var stats domain.CustomerStats
	if err != nil {
		return stats, err
	}
	for _, row := range rows {
		stats.Total += row.Total
		switch row.Status {
		case "active":
			stats.Active = row.Total
		default:
			stats.Inactive += row.Total
		}
	}
	return stats, nil
}

func (r *userRepository) AggregateCustomerOrders(ctx context.Context, userIDs []uint) (map[uint]domain.CustomerAggregate, error) {
	out := make(map[uint]domain.CustomerAggregate, len(userIDs))
	if len(userIDs) == 0 {
		return out, nil
	}

	var rows []struct {
		UserID      uint
		TotalOrders int64
		TotalSpent  float64
		LastOrderAt *time.Time
	}
	err := r.db.WithContext(ctx).Table("orders").
		Select("user_id, COUNT(*) AS total_orders, COALESCE(SUM(total_amount), 0) AS total_spent, MAX(created_at) AS last_order_at").
		Where("user_id IN ?", userIDs).
		Where("deleted_at IS NULL").
		Where("status NOT IN ?", []string{"cancelled", "returned"}).
		Group("user_id").
		Scan(&rows).Error
	if err != nil {
		return out, err
	}

	for _, row := range rows {
		out[row.UserID] = domain.CustomerAggregate{
			UserID:      row.UserID,
			TotalOrders: row.TotalOrders,
			TotalSpent:  row.TotalSpent,
			LastOrderAt: row.LastOrderAt,
		}
	}
	return out, nil
}

func (r *userRepository) DefaultAddresses(ctx context.Context, userIDs []uint) (map[uint]string, error) {
	out := make(map[uint]string, len(userIDs))
	if len(userIDs) == 0 {
		return out, nil
	}

	var rows []domain.UserAddress
	err := r.db.WithContext(ctx).
		Where("user_id IN ?", userIDs).
		Order("is_default DESC, id ASC").
		Find(&rows).Error
	if err != nil {
		return out, err
	}

	// Đã sắp xếp địa chỉ mặc định lên đầu -> chỉ giữ bản ghi đầu tiên của mỗi khách.
	for _, a := range rows {
		if _, seen := out[a.UserID]; seen {
			continue
		}
		out[a.UserID] = joinAddress(a)
	}
	return out, nil
}

// joinAddress ghép các thành phần địa chỉ thành một chuỗi hiển thị, bỏ phần trống.
func joinAddress(a domain.UserAddress) string {
	parts := make([]string, 0, 4)
	for _, p := range []string{a.AddressLine, a.Ward, a.District, a.Province} {
		if s := strings.TrimSpace(p); s != "" {
			parts = append(parts, s)
		}
	}
	return strings.Join(parts, ", ")
}

func (r *userRepository) SaveDefaultAddress(ctx context.Context, u *domain.User, address string) error {
	address = strings.TrimSpace(address)

	var existing domain.UserAddress
	err := r.db.WithContext(ctx).
		Where("user_id = ?", u.ID).
		Order("is_default DESC, id ASC").
		First(&existing).Error
	notFound := errors.Is(err, gorm.ErrRecordNotFound)
	if err != nil && !notFound {
		return err
	}

	// Xoá địa chỉ mặc định khi admin để trống ô địa chỉ.
	if address == "" {
		if notFound {
			return nil
		}
		return r.db.WithContext(ctx).Delete(&existing).Error
	}

	if notFound {
		return r.db.WithContext(ctx).Create(&domain.UserAddress{
			UserID:        u.ID,
			RecipientName: u.FullName,
			Phone:         u.Phone,
			AddressLine:   address,
			Type:          "home",
			IsDefault:     true,
		}).Error
	}

	existing.RecipientName = u.FullName
	existing.Phone = u.Phone
	existing.AddressLine = address
	existing.Province, existing.District, existing.Ward = "", "", ""
	existing.IsDefault = true
	return r.db.WithContext(ctx).Save(&existing).Error
}
