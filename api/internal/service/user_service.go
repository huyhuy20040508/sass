package service

import (
	"context"
	"regexp"
	"strings"
	"time"

	"sass-api/internal/domain"
	"sass-api/internal/dto"
	"sass-api/pkg/hash"
)

// defaultStaffPassword là mật khẩu cấp cho tài khoản nội bộ tạo mà không nhập mật
// khẩu. Người quản lý đưa lại cho nhân viên rồi đặt lại sau.
const defaultStaffPassword = "Nhanvien@123"

// Actor là người đang thực hiện thao tác, đọc từ access token.
//
// Mọi chốt chặn của module này đều cần biết "ai đang bấm": tự khoá mình, tự hạ
// vai trò mình, hay quản trị viên thường đụng vào tài khoản super admin đều là
// những thứ chỉ nhìn payload thì không phát hiện được.
type Actor struct {
	ID   uint
	Role string
}

// UserService quản lý tài khoản NỘI BỘ (super_admin, admin, staff) và vai trò.
//
// Khách hàng KHÔNG thuộc phạm vi ở đây — xem CustomerService.
type UserService interface {
	List(ctx context.Context, filter domain.InternalUserFilter) ([]dto.UserResponse, int64, error)
	Stats(ctx context.Context) (domain.InternalUserStats, error)
	GetByID(ctx context.Context, id uint) (*dto.UserResponse, error)
	Create(ctx context.Context, req *dto.UserRequest, actor Actor) (*dto.UserResponse, error)
	Update(ctx context.Context, id uint, req *dto.UserRequest, actor Actor) (*dto.UserResponse, error)
	UpdateStatus(ctx context.Context, id uint, status string, actor Actor) (*dto.UserResponse, error)
	SetPassword(ctx context.Context, id uint, password string, actor Actor) (*dto.UserResponse, error)
	Delete(ctx context.Context, id uint, actor Actor) error

	// Hồ sơ của CHÍNH người đang đăng nhập — mọi vai trò nội bộ đều gọi được, kể
	// cả nhân viên (không vào được /admin/users). Không nhận actor vì đối tượng
	// thao tác luôn là chính người gọi, id lấy từ token.
	Profile(ctx context.Context, id uint) (*dto.UserResponse, error)
	UpdateProfile(ctx context.Context, id uint, req *dto.ProfileRequest) (*dto.UserResponse, error)
	ChangePassword(ctx context.Context, id uint, req *dto.ChangePasswordRequest) error

	Roles(ctx context.Context) ([]dto.RoleResponse, error)
	UpdateRole(ctx context.Context, id uint, req *dto.RoleUpdateRequest) (*dto.RoleResponse, error)
}

type userService struct {
	users domain.UserRepository
	roles domain.RoleRepository
}

func NewUserService(users domain.UserRepository, roles domain.RoleRepository) UserService {
	return &userService{users: users, roles: roles}
}

func (s *userService) List(ctx context.Context, filter domain.InternalUserFilter) ([]dto.UserResponse, int64, error) {
	users, total, err := s.users.ListInternal(ctx, filter)
	if err != nil {
		return nil, 0, err
	}

	// Một câu truy vấn nhãn cho cả trang, không phải mỗi dòng một câu.
	nhan, err := s.roles.Labels(ctx)
	if err != nil {
		return nil, 0, err
	}

	items := make([]dto.UserResponse, 0, len(users))
	for i := range users {
		items = append(items, buildUser(&users[i], nhan))
	}
	return items, total, nil
}

func (s *userService) Stats(ctx context.Context) (domain.InternalUserStats, error) {
	return s.users.InternalStats(ctx)
}

func (s *userService) GetByID(ctx context.Context, id uint) (*dto.UserResponse, error) {
	u, err := s.internalUser(ctx, id)
	if err != nil {
		return nil, err
	}
	nhan, err := s.roles.Labels(ctx)
	if err != nil {
		return nil, err
	}
	res := buildUser(u, nhan)
	return &res, nil
}

func (s *userService) Create(ctx context.Context, req *dto.UserRequest, actor Actor) (*dto.UserResponse, error) {
	if err := s.canAssignRole(req.RoleID, actor); err != nil {
		return nil, err
	}

	email := strings.TrimSpace(req.Email)
	exists, err := s.users.ExistsByEmail(ctx, email)
	if err != nil {
		return nil, err
	}
	if exists {
		return nil, domain.ErrEmailExists
	}

	username, err := s.resolveUsername(ctx, req.Username, 0)
	if err != nil {
		return nil, err
	}

	password := req.Password
	if password == "" {
		password = defaultStaffPassword
	}
	hashed, err := hash.Hash(password)
	if err != nil {
		return nil, err
	}

	u := &domain.User{
		RoleID:       req.RoleID,
		FullName:     strings.TrimSpace(req.FullName),
		Username:     domain.StringOrNull(username),
		Email:        email,
		Phone:        strings.TrimSpace(req.Phone),
		Avatar:       strings.TrimSpace(req.Avatar),
		Status:       req.Status,
		PasswordHash: hashed,
	}
	if u.Status == "" {
		u.Status = "active"
	}

	if err := s.users.Create(ctx, u); err != nil {
		return nil, err
	}

	// Đọc lại để lấy kèm vai trò (Create không preload quan hệ).
	return s.GetByID(ctx, u.ID)
}

func (s *userService) Update(ctx context.Context, id uint, req *dto.UserRequest, actor Actor) (*dto.UserResponse, error) {
	u, err := s.internalUser(ctx, id)
	if err != nil {
		return nil, err
	}
	if err := s.canManage(u, actor); err != nil {
		return nil, err
	}
	if err := s.canAssignRole(req.RoleID, actor); err != nil {
		return nil, err
	}

	// Tự hạ vai trò của chính mình là cách mất quyền nhanh nhất mà không có đường
	// quay lại: phiên hiện tại vẫn chạy bằng token cũ nên người dùng còn tưởng
	// mọi thứ bình thường, tới lần đăng nhập sau mới phát hiện.
	if actor.ID == id && req.RoleID != u.RoleID {
		return nil, domain.ErrForbidden
	}
	if actor.ID == id && req.Status != u.Status {
		return nil, domain.ErrForbidden
	}

	// Hạ vai trò người super admin cuối cùng cũng là khoá cả hệ thống.
	if u.RoleID == domain.SuperAdminRoleID && req.RoleID != domain.SuperAdminRoleID {
		if err := s.ensureAnotherSuperAdmin(ctx, u.ID); err != nil {
			return nil, err
		}
	}
	if u.RoleID == domain.SuperAdminRoleID && req.Status != "active" {
		if err := s.ensureAnotherSuperAdmin(ctx, u.ID); err != nil {
			return nil, err
		}
	}

	email := strings.TrimSpace(req.Email)
	if email != "" && !strings.EqualFold(email, u.Email) {
		exists, err := s.users.ExistsByEmailExcept(ctx, email, id)
		if err != nil {
			return nil, err
		}
		if exists {
			return nil, domain.ErrEmailExists
		}
	}

	username, err := s.resolveUsername(ctx, req.Username, id)
	if err != nil {
		return nil, err
	}

	u.FullName = strings.TrimSpace(req.FullName)
	u.Username = domain.StringOrNull(username)
	u.Email = email
	u.Phone = strings.TrimSpace(req.Phone)
	u.Avatar = strings.TrimSpace(req.Avatar)
	u.RoleID = req.RoleID
	if req.Status != "" {
		u.Status = req.Status
	}
	// Vai trò đã đổi -> quan hệ nạp sẵn không còn đúng. Bỏ đi để GetByID đọc lại.
	u.Role = nil

	if err := s.users.Update(ctx, u); err != nil {
		return nil, err
	}
	return s.GetByID(ctx, u.ID)
}

func (s *userService) UpdateStatus(ctx context.Context, id uint, status string, actor Actor) (*dto.UserResponse, error) {
	u, err := s.internalUser(ctx, id)
	if err != nil {
		return nil, err
	}
	if err := s.canManage(u, actor); err != nil {
		return nil, err
	}
	// Tự khoá mình: thao tác duy nhất mà người bấm không thể tự sửa lại sau đó.
	if actor.ID == id {
		return nil, domain.ErrForbidden
	}
	if u.RoleID == domain.SuperAdminRoleID && status != "active" {
		if err := s.ensureAnotherSuperAdmin(ctx, u.ID); err != nil {
			return nil, err
		}
	}

	u.Status = status
	if err := s.users.Update(ctx, u); err != nil {
		return nil, err
	}
	return s.GetByID(ctx, u.ID)
}

func (s *userService) SetPassword(ctx context.Context, id uint, password string, actor Actor) (*dto.UserResponse, error) {
	u, err := s.internalUser(ctx, id)
	if err != nil {
		return nil, err
	}
	// Đặt lại mật khẩu CHO CHÍNH MÌNH thì được — không phải thao tác một chiều.
	if err := s.canManage(u, actor); err != nil {
		return nil, err
	}

	hashed, err := hash.Hash(password)
	if err != nil {
		return nil, err
	}

	u.PasswordHash = hashed
	if err := s.users.Update(ctx, u); err != nil {
		return nil, err
	}
	return s.GetByID(ctx, u.ID)
}

func (s *userService) Delete(ctx context.Context, id uint, actor Actor) error {
	u, err := s.internalUser(ctx, id)
	if err != nil {
		return err
	}
	if err := s.canManage(u, actor); err != nil {
		return err
	}
	if actor.ID == id {
		return domain.ErrForbidden
	}
	if u.RoleID == domain.SuperAdminRoleID {
		if err := s.ensureAnotherSuperAdmin(ctx, u.ID); err != nil {
			return err
		}
	}

	return s.users.Delete(ctx, id)
}

// ---------- Hồ sơ của chính mình ----------

func (s *userService) Profile(ctx context.Context, id uint) (*dto.UserResponse, error) {
	return s.GetByID(ctx, id)
}

// UpdateProfile sửa những trường người dùng tự chịu trách nhiệm: họ tên, số điện
// thoại, ảnh đại diện.
//
// Vai trò, trạng thái và email giữ nguyên — chúng quyết định quyền hạn và lối
// đăng nhập, không phải thứ chủ tài khoản tự đổi được. Nhờ vậy đường này không
// cần Actor: dù ai gọi thì cũng chỉ sửa được đúng phần vô hại của chính mình.
func (s *userService) UpdateProfile(ctx context.Context, id uint, req *dto.ProfileRequest) (*dto.UserResponse, error) {
	u, err := s.internalUser(ctx, id)
	if err != nil {
		return nil, err
	}

	u.FullName = strings.TrimSpace(req.FullName)
	u.Phone = strings.TrimSpace(req.Phone)
	u.Avatar = strings.TrimSpace(req.Avatar)

	if err := s.users.Update(ctx, u); err != nil {
		return nil, err
	}
	return s.GetByID(ctx, u.ID)
}

// ChangePassword đổi mật khẩu của chính người đang đăng nhập.
func (s *userService) ChangePassword(ctx context.Context, id uint, req *dto.ChangePasswordRequest) error {
	u, err := s.internalUser(ctx, id)
	if err != nil {
		return err
	}
	if !hash.Check(req.CurrentPassword, u.PasswordHash) {
		return domain.ErrPasswordIncorrect
	}
	// Gõ lại đúng mật khẩu cũ thường là nhầm ô, không phải ý định. Báo ra còn hơn
	// trả "đã đổi mật khẩu" cho một lần lưu chẳng thay đổi gì.
	if req.NewPassword == req.CurrentPassword {
		return domain.ErrPasswordSame
	}

	hashed, err := hash.Hash(req.NewPassword)
	if err != nil {
		return err
	}

	u.PasswordHash = hashed
	return s.users.Update(ctx, u)
}

// ---------- Vai trò ----------

func (s *userService) Roles(ctx context.Context) ([]dto.RoleResponse, error) {
	roles, err := s.roles.List(ctx)
	if err != nil {
		return nil, err
	}
	counts, err := s.roles.CountUsers(ctx)
	if err != nil {
		return nil, err
	}

	items := make([]dto.RoleResponse, 0, len(roles))
	for i := range roles {
		items = append(items, buildRole(&roles[i], counts[roles[i].ID]))
	}
	return items, nil
}

func (s *userService) UpdateRole(ctx context.Context, id uint, req *dto.RoleUpdateRequest) (*dto.RoleResponse, error) {
	role, err := s.roles.FindByID(ctx, id)
	if err != nil {
		return nil, err
	}

	// Ghi NHÃN của cửa hàng, không ghi bảng roles: bảng đó dùng chung cho mọi
	// khách hàng, sửa vào đó là đổi tên vai trò trên màn hình của người khác.
	ten := strings.TrimSpace(req.DisplayName)
	moTa := strings.TrimSpace(req.Description)
	if err := s.roles.SetLabel(ctx, id, ten, moTa); err != nil {
		return nil, err
	}
	role.DisplayName = ten
	role.Description = moTa

	counts, err := s.roles.CountUsers(ctx)
	if err != nil {
		return nil, err
	}
	res := buildRole(role, counts[role.ID])
	return &res, nil
}

// ---------- Chốt chặn ----------

// internalUser đọc một tài khoản và chặn nếu đó là khách hàng.
//
// Không có bước này thì mọi endpoint ở đây thành cửa sau để sửa/xoá khách hàng
// mà không đi qua luồng khách hàng.
func (s *userService) internalUser(ctx context.Context, id uint) (*domain.User, error) {
	u, err := s.users.FindByID(ctx, id)
	if err != nil {
		return nil, err
	}
	if !isInternalRole(u.RoleID) {
		return nil, domain.ErrNotFound
	}
	return u, nil
}

// usernameRe là bộ ký tự cho phép trong tên đăng nhập.
//
// Chỉ chữ THƯỜNG không dấu, số, dấu chấm, gạch ngang, gạch dưới. Hẹp có chủ ý:
// tên này được gõ tay mỗi ca làm, phần lớn trên bàn phím điện thoại. Cho phép
// chữ hoa thì 'Admin' và 'admin' thành hai tài khoản khác nhau dưới CSDL nhưng
// người dùng đọc thấy như một; cho phép khoảng trắng hay dấu tiếng Việt thì có
// người gõ mãi không vào được mà không hiểu vì sao.
var usernameRe = regexp.MustCompile(`^[a-z0-9._-]{3,50}$`)

// resolveUsername chuẩn hoá và kiểm tra tên đăng nhập trước khi ghi.
//
// excludeID = 0 khi tạo mới, = id tài khoản đang sửa khi cập nhật (không thì tự
// tài khoản đó bị tính là trùng với chính nó).
//
// Chặn ở đây thay vì để UNIQUE dưới CSDL đỡ, vì lỗi trùng khoá thô không nói được
// trùng cột nào — translateUserErr đang quy MỌI lỗi 1062 của bảng users về "email
// đã được sử dụng", nên người dùng sẽ nhận một câu sai hẳn chỗ cần sửa.
func (s *userService) resolveUsername(ctx context.Context, raw string, excludeID uint) (string, error) {
	username := NormalizeUsername(raw)
	if !usernameRe.MatchString(username) {
		return "", domain.ErrUsernameInvalid
	}

	exists, err := s.users.ExistsByUsernameExcept(ctx, username, excludeID)
	if err != nil {
		return "", err
	}
	if exists {
		return "", domain.ErrUsernameExists
	}
	return username, nil
}

// canAssignRole kiểm tra vai trò định gán có hợp lệ với quyền của người thao tác.
func (s *userService) canAssignRole(roleID uint, actor Actor) error {
	if !isInternalRole(roleID) {
		return domain.ErrForbidden
	}
	// Chỉ super admin mới tạo ra được super admin khác — nếu không, một quản trị
	// viên thường có thể tự nâng cấp bằng cách tạo tài khoản super admin mới.
	if roleID == domain.SuperAdminRoleID && actor.Role != domain.RoleSuperAdmin {
		return domain.ErrForbidden
	}
	return nil
}

// canManage kiểm tra người thao tác có được đụng vào tài khoản đích không.
func (s *userService) canManage(target *domain.User, actor Actor) error {
	if target.RoleID == domain.SuperAdminRoleID && actor.Role != domain.RoleSuperAdmin {
		return domain.ErrForbidden
	}
	return nil
}

// ensureAnotherSuperAdmin bảo đảm vẫn còn ít nhất một super admin ĐANG HOẠT ĐỘNG
// sau khi tài khoản excludeID bị khoá, xoá hoặc hạ vai trò.
func (s *userService) ensureAnotherSuperAdmin(ctx context.Context, excludeID uint) error {
	remaining, err := s.users.CountActiveByRole(ctx, domain.SuperAdminRoleID, excludeID)
	if err != nil {
		return err
	}
	if remaining == 0 {
		return domain.ErrLastSuperAdmin
	}
	return nil
}

func isInternalRole(roleID uint) bool {
	for _, id := range domain.InternalRoleIDs {
		if id == roleID {
			return true
		}
	}
	return false
}

// ---------- Dựng response ----------

// buildUser dựng response của một tài khoản.
//
// nhan là nhãn vai trò của cửa hàng (roleRepository.Labels). Nó là THAM SỐ BẮT
// BUỘC chứ không phải tuỳ chọn: quan hệ Role nạp kèm user luôn là dòng mặc định
// dùng chung, nên quên nhãn ở đây là trang Người dùng in một tên còn trang Vai
// trò in tên khác — cùng một vai trò, cùng một cửa hàng. Bắt truyền vào thì
// trình biên dịch liệt kê hộ mọi chỗ phải nhớ.
//
// nil = cửa hàng chưa đặt tên riêng cho vai trò nào.
func buildUser(u *domain.User, nhan map[uint]domain.RoleLabel) dto.UserResponse {
	res := dto.UserResponse{
		ID:            u.ID,
		FullName:      u.FullName,
		Username:      string(u.Username),
		Email:         u.Email,
		Phone:         u.Phone,
		Avatar:        u.Avatar,
		Status:        u.Status,
		RoleID:        u.RoleID,
		EmailVerified: u.EmailVerifiedAt != nil,
		LastLoginAt:   formatDateTime(u.LastLoginAt),
		CreatedAt:     u.CreatedAt.Format(time.RFC3339),
	}
	if u.Role != nil {
		res.RoleName = u.Role.Name
		res.RoleDisplayName = u.Role.DisplayName
		if n, ok := nhan[u.RoleID]; ok && n.DisplayName != "" {
			res.RoleDisplayName = n.DisplayName
		}
	}
	return res
}

func buildRole(r *domain.Role, userCount int64) dto.RoleResponse {
	return dto.RoleResponse{
		ID:          r.ID,
		Name:        r.Name,
		DisplayName: r.DisplayName,
		Description: r.Description,
		UserCount:   userCount,
		Internal:    isInternalRole(r.ID),
	}
}
