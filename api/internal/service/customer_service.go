package service

import (
	"context"
	"fmt"
	"strings"
	"time"

	"sass-api/internal/domain"
	"sass-api/internal/dto"
	"sass-api/pkg/hash"
)

// defaultCustomerPassword là mật khẩu cấp cho khách hàng do admin tạo tay
// (khách đổi lại qua chức năng quên mật khẩu ở storefront).
const defaultCustomerPassword = "Khachhang@123"

// dateLayout là định dạng ngày trao đổi với admin (input type="date").
const dateLayout = "2006-01-02"

type CustomerService interface {
	List(ctx context.Context, filter domain.CustomerFilter) ([]dto.CustomerResponse, int64, error)
	GetByID(ctx context.Context, id uint) (*dto.CustomerResponse, error)
	Create(ctx context.Context, req *dto.CustomerRequest) (*dto.CustomerResponse, error)
	Update(ctx context.Context, id uint, req *dto.CustomerRequest) (*dto.CustomerResponse, error)
	UpdateStatus(ctx context.Context, id uint, status string) (*dto.CustomerResponse, error)
	SetPassword(ctx context.Context, id uint, password string) (*dto.CustomerResponse, error)
	Delete(ctx context.Context, id uint) error
	Stats(ctx context.Context) (domain.CustomerStats, error)
}

type customerService struct {
	userRepo domain.UserRepository
}

func NewCustomerService(userRepo domain.UserRepository) CustomerService {
	return &customerService{userRepo: userRepo}
}

func (s *customerService) List(ctx context.Context, filter domain.CustomerFilter) ([]dto.CustomerResponse, int64, error) {
	users, total, err := s.userRepo.ListCustomers(ctx, filter)
	if err != nil {
		return nil, 0, err
	}

	ids := make([]uint, 0, len(users))
	for _, u := range users {
		ids = append(ids, u.ID)
	}

	aggregates, err := s.userRepo.AggregateCustomerOrders(ctx, ids)
	if err != nil {
		return nil, 0, err
	}
	addresses, err := s.userRepo.DefaultAddresses(ctx, ids)
	if err != nil {
		return nil, 0, err
	}

	items := make([]dto.CustomerResponse, 0, len(users))
	for i := range users {
		items = append(items, buildCustomer(&users[i], aggregates[users[i].ID], addresses[users[i].ID]))
	}
	return items, total, nil
}

func (s *customerService) GetByID(ctx context.Context, id uint) (*dto.CustomerResponse, error) {
	u, err := s.userRepo.FindByID(ctx, id)
	if err != nil {
		return nil, err
	}
	return s.detail(ctx, u)
}

func (s *customerService) Create(ctx context.Context, req *dto.CustomerRequest) (*dto.CustomerResponse, error) {
	// Bỏ trống email thì KHÔNG kiểm trùng: `email = ''` khớp với mọi khách khác
	// cũng để trống, và lượt khai thứ hai sẽ bị báo "email đã được sử dụng" trong
	// khi người dùng có nhập email nào đâu. Khoá duy nhất dưới database cũng bỏ
	// qua chúng — xem migration 0062.
	if strings.TrimSpace(req.Email) != "" {
		exists, err := s.userRepo.ExistsByEmail(ctx, req.Email)
		if err != nil {
			return nil, err
		}
		if exists {
			return nil, domain.ErrEmailExists
		}
	}

	password := req.Password
	if password == "" {
		password = defaultCustomerPassword
	}
	hashed, err := hash.Hash(password)
	if err != nil {
		return nil, err
	}

	u := &domain.User{
		RoleID:       domain.CustomerRoleID,
		FullName:     strings.TrimSpace(req.FullName),
		Email:        strings.TrimSpace(req.Email),
		Phone:        strings.TrimSpace(req.Phone),
		Avatar:       strings.TrimSpace(req.Avatar),
		Gender:       domain.EnumOrNull(req.Gender),
		DateOfBirth:  parseDate(req.DateOfBirth),
		Status:       req.Status,
		PasswordHash: hashed,
	}
	if u.Status == "" {
		u.Status = "active"
	}
	hoSoKhach(u, req)

	if err := s.userRepo.Create(ctx, u); err != nil {
		return nil, err
	}

	// Mã sinh SAU khi ghi vì nó lấy từ id. Khai mã tay thì giữ nguyên mã đó.
	if u.CustomerCode == "" {
		u.CustomerCode = maKhachTheoID(u.ID)
		if err := s.userRepo.Update(ctx, u); err != nil {
			return nil, err
		}
	}
	if err := s.userRepo.SaveDefaultAddress(ctx, u, req.Address); err != nil {
		return nil, err
	}

	return s.detail(ctx, u)
}

func (s *customerService) Update(ctx context.Context, id uint, req *dto.CustomerRequest) (*dto.CustomerResponse, error) {
	u, err := s.userRepo.FindByID(ctx, id)
	if err != nil {
		return nil, err
	}

	email := strings.TrimSpace(req.Email)
	if email != "" && !strings.EqualFold(email, u.Email) {
		exists, err := s.userRepo.ExistsByEmailExcept(ctx, email, id)
		if err != nil {
			return nil, err
		}
		if exists {
			return nil, domain.ErrEmailExists
		}
	}

	u.FullName = strings.TrimSpace(req.FullName)
	u.Email = email
	u.Phone = strings.TrimSpace(req.Phone)
	u.Avatar = strings.TrimSpace(req.Avatar)
	u.Gender = domain.EnumOrNull(req.Gender)
	u.DateOfBirth = parseDate(req.DateOfBirth)
	if req.Status != "" {
		u.Status = req.Status
	}
	hoSoKhach(u, req)

	if err := s.userRepo.Update(ctx, u); err != nil {
		return nil, err
	}
	if err := s.userRepo.SaveDefaultAddress(ctx, u, req.Address); err != nil {
		return nil, err
	}

	return s.detail(ctx, u)
}

func (s *customerService) UpdateStatus(ctx context.Context, id uint, status string) (*dto.CustomerResponse, error) {
	u, err := s.userRepo.FindByID(ctx, id)
	if err != nil {
		return nil, err
	}

	u.Status = status
	if err := s.userRepo.Update(ctx, u); err != nil {
		return nil, err
	}
	return s.detail(ctx, u)
}

// SetPassword cấp (hoặc đặt lại) mật khẩu đăng nhập storefront cho khách hàng.
func (s *customerService) SetPassword(ctx context.Context, id uint, password string) (*dto.CustomerResponse, error) {
	u, err := s.userRepo.FindByID(ctx, id)
	if err != nil {
		return nil, err
	}

	hashed, err := hash.Hash(password)
	if err != nil {
		return nil, err
	}

	u.PasswordHash = hashed
	if err := s.userRepo.Update(ctx, u); err != nil {
		return nil, err
	}
	return s.detail(ctx, u)
}

func (s *customerService) Delete(ctx context.Context, id uint) error {
	if _, err := s.userRepo.FindByID(ctx, id); err != nil {
		return err
	}
	return s.userRepo.Delete(ctx, id)
}

func (s *customerService) Stats(ctx context.Context) (domain.CustomerStats, error) {
	return s.userRepo.CustomerStats(ctx)
}

// detail dựng response đầy đủ (địa chỉ + số liệu đơn hàng) cho một khách hàng.
func (s *customerService) detail(ctx context.Context, u *domain.User) (*dto.CustomerResponse, error) {
	ids := []uint{u.ID}
	aggregates, err := s.userRepo.AggregateCustomerOrders(ctx, ids)
	if err != nil {
		return nil, err
	}
	addresses, err := s.userRepo.DefaultAddresses(ctx, ids)
	if err != nil {
		return nil, err
	}

	res := buildCustomer(u, aggregates[u.ID], addresses[u.ID])
	return &res, nil
}

func buildCustomer(u *domain.User, agg domain.CustomerAggregate, address string) dto.CustomerResponse {
	return dto.CustomerResponse{
		ID:          u.ID,
		FullName:    u.FullName,
		Email:       u.Email,
		Phone:       u.Phone,
		Avatar:      u.Avatar,
		Gender:      string(u.Gender),
		DateOfBirth: formatDate(u.DateOfBirth),
		Address:     address,
		Status:      u.Status,
		TotalOrders: agg.TotalOrders,
		TotalSpent:  agg.TotalSpent,
		TotalPaid:   agg.TotalPaid,
		StillInDebt: agg.TotalDebt,
		LastOrderAt: formatDateTime(agg.LastOrderAt),

		Code:                u.CustomerCode,
		CustomerType:        u.CustomerType,
		CustomerGroupID:     idNhomKhach(u.CustomerGroupID),
		GroupName:           tenNhomKhach(u),
		TaxCode:             string(u.TaxCode),
		CitizenID:           string(u.CitizenID),
		RepresentativeName:  string(u.RepresentativeName),
		RepresentativePhone: string(u.RepresentativePhone),
		Note:                string(u.CustomerNote),

		LoginEmail:    u.Email,
		EmailVerified: u.EmailVerifiedAt != nil,
		LastLoginAt:   formatDateTime(u.LastLoginAt),
		CreatedAt:     u.CreatedAt.Format(time.RFC3339),
	}
}

// parseDate đọc chuỗi "YYYY-MM-DD" (chấp nhận cả RFC3339); rỗng/sai định dạng -> nil.
func parseDate(s string) *time.Time {
	s = strings.TrimSpace(s)
	if s == "" {
		return nil
	}
	for _, layout := range []string{dateLayout, time.RFC3339} {
		if t, err := time.Parse(layout, s); err == nil {
			return &t
		}
	}
	return nil
}

func formatDate(t *time.Time) string {
	if t == nil {
		return ""
	}
	return t.Format(dateLayout)
}

func formatDateTime(t *time.Time) string {
	if t == nil {
		return ""
	}
	return t.Format(time.RFC3339)
}

// hoSoKhach chép phần hồ sơ riêng của khách từ payload vào bản ghi.
//
// Mã bỏ trống khi SỬA thì GIỮ NGUYÊN mã cũ: mã đã đi vào chứng từ, tự đổi là hồ
// sơ hai bên lệch nhau. Khách cá nhân thì xoá sạch mấy ô của doanh nghiệp và
// ngược lại — để sót mã số thuế trên một khách cá nhân thì hoá đơn xuất ra mang
// mã của lần khai trước.
func hoSoKhach(u *domain.User, req *dto.CustomerRequest) {
	if ma := strings.TrimSpace(req.Code); ma != "" {
		u.CustomerCode = ma
	}

	u.CustomerType = req.CustomerType
	if req.CustomerGroupID > 0 {
		id := req.CustomerGroupID
		u.CustomerGroupID = &id
	} else {
		u.CustomerGroupID = nil
	}
	u.CustomerNote = domain.StringOrNull(strings.TrimSpace(req.Note))

	if req.CustomerType == 1 {
		u.TaxCode = domain.StringOrNull(strings.TrimSpace(req.TaxCode))
		u.RepresentativeName = domain.StringOrNull(strings.TrimSpace(req.RepresentativeName))
		u.RepresentativePhone = domain.StringOrNull(strings.TrimSpace(req.RepresentativePhone))
		u.CitizenID = ""

		return
	}

	u.CitizenID = domain.StringOrNull(strings.TrimSpace(req.CitizenID))
	u.TaxCode = ""
	u.RepresentativeName = ""
	u.RepresentativePhone = ""
}

// maKhachTheoID sinh mã theo dải `cus-00001` — đúng dạng bản v2 đang chạy.
func maKhachTheoID(id uint) string {
	return fmt.Sprintf("cus-%05d", id)
}

// idNhomKhach đọc con trỏ id nhóm; chưa xếp nhóm thì trả 0.
func idNhomKhach(p *uint) uint {
	if p == nil {
		return 0
	}

	return *p
}

// tenNhomKhach lấy tên nhóm đã Preload; chưa xếp nhóm thì trả chuỗi rỗng.
func tenNhomKhach(u *domain.User) string {
	if u.CustomerGroup == nil {
		return ""
	}

	return u.CustomerGroup.Name
}
