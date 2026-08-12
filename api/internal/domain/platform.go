package domain

import (
	"context"
	"time"
)

// Thực thể của CONTROL PLANE — lược đồ selliotech_platform.
//
// ĐỌC KỸ TRƯỚC KHI DÙNG: mấy kiểu dưới đây chỉ đúng khi đi cùng KẾT NỐI THỨ HAI
// (cfg.Platform, xem cmd/api/main.go). Tên bảng của chúng — `tenants` — trùng
// đúng tên một bảng bên data plane, nên đưa nhầm *gorm.DB của data plane vào
// thì truy vấn vẫn CHẠY và vẫn trả về dữ liệu, chỉ là dữ liệu của bảng khác.
// Không có cách nào để trình biên dịch bắt lỗi đó, nên repository nào đọc mấy
// kiểu này phải nhận đúng kết nối control plane ngay ở hàm khởi tạo.
//
// Không nướng tên database vào TableName() ("selliotech_platform.tenants") vì
// tên đó là của môi trường — PLATFORM_DB_NAME ở máy cục bộ, máy thử và máy thật
// đều khác nhau.
//
// KHÔNG có khoá ngoại nào bắc qua hai lược đồ (xem đầu tệp migration), nên ở
// đây cũng không khai quan hệ GORM nào trỏ sang thực thể của data plane —
// Preload một quan hệ như vậy sẽ sinh JOIN sang database khác.

// PlatformTenant là một dòng trong sổ đăng ký khách hàng của nền tảng.
//
// Khác domain.Tenant (bảng `tenants` của data plane) ở mục đích: Tenant là bảng
// cha của mọi khoá ngoại tenant_id và là thứ API đọc lúc đăng nhập; còn kiểu này
// là sổ ghi khách đó là ai, đang mở hay đang khoá, liên lạc với ai.
//
// ID là SỐ CHUNG với domain.Tenant.ID và hôm nay do data plane cấp — bảng bên
// này không AUTO_INCREMENT, mỗi dòng phải ghi thẳng id đã có bên kia.
type PlatformTenant struct {
	ID   uint   `json:"id" gorm:"primaryKey"`
	Code string `json:"code"`
	Name string `json:"name"`
	// Status: active | suspended — cùng bộ giá trị với Tenant.Status. Quyết định
	// nằm ở đây, nhưng API chặn đăng nhập bằng cột bên data plane, nên đổi ở đây
	// thì PHẢI ghi sang bên kia.
	Status       string       `json:"status"`
	ContactName  StringOrNull `json:"contact_name"`
	ContactEmail StringOrNull `json:"contact_email"`
	ContactPhone StringOrNull `json:"contact_phone"`
	Note         StringOrNull `json:"note"`
	CreatedAt    time.Time    `json:"created_at"`
	UpdatedAt    time.Time    `json:"updated_at"`
}

func (PlatformTenant) TableName() string { return "tenants" }

// Subscription là gói khách đang dùng và hạn của nó.
//
// Một tenant có TỐI ĐA MỘT thuê bao còn hiệu lực — ràng buộc này do khoá
// uq_subscriptions_current dưới database giữ, không phải do tầng Go tự nhớ.
// Gia hạn = đẩy EndsAt của chính dòng đó; đổi gói = huỷ dòng cũ rồi thêm dòng
// mới.
type Subscription struct {
	ID       uint `json:"id" gorm:"primaryKey"`
	TenantID uint `json:"tenant_id"`
	// Plan: khoi_dau | cua_hang | chuoi
	Plan string `json:"plan"`
	// Status: trial | active | past_due | canceled
	Status string `json:"status"`
	// BillingCycle: thang | nam
	BillingCycle string  `json:"billing_cycle"`
	Price        float64 `json:"price"`
	// MaxShops là hạn mức ĐÃ CHỐT với khách lúc ký, không phải hạn mức của bảng
	// giá hiện hành — đọc thẳng ở dòng này chứ đừng suy ra từ Plan.
	MaxShops    uint         `json:"max_shops"`
	StartedAt   time.Time    `json:"started_at"`
	EndsAt      time.Time    `json:"ends_at"`
	TrialEndsAt *time.Time   `json:"trial_ends_at"`
	CanceledAt  *time.Time   `json:"canceled_at"`
	Note        StringOrNull `json:"note"`
	CreatedAt   time.Time    `json:"created_at"`
	UpdatedAt   time.Time    `json:"updated_at"`
}

func (Subscription) TableName() string { return "subscriptions" }

// Trạng thái thuê bao.
const (
	SubscriptionTrial    = "trial"
	SubscriptionActive   = "active"
	SubscriptionPastDue  = "past_due"
	SubscriptionCanceled = "canceled"
)

// Ba gói bán ra. Giá trị khớp ENUM `plan` dưới database.
const (
	PlanKhoiDau = "khoi_dau"
	PlanCuaHang = "cua_hang"
	PlanChuoi   = "chuoi"
)

// PlatformUser là tài khoản của KHU ĐIỀU HÀNH nền tảng — mình và người làm cùng,
// KHÔNG phải nhân viên bán hàng của khách (những người đó ở bảng users của data
// plane và đăng nhập bằng 3 ô).
//
// Không có TenantID: người của nền tảng không thuộc cửa hàng nào. Đó chính là
// lý do bảng này tồn tại thay vì dùng vai trò super_admin trong users — xem
// migration control plane.
type PlatformUser struct {
	ID           uint   `json:"id" gorm:"primaryKey"`
	Email        string `json:"email"`
	FullName     string `json:"full_name"`
	PasswordHash string `json:"-"`
	// Role: owner | operator | support
	Role string `json:"role"`
	// Status: active | locked
	Status      string     `json:"status"`
	LastLoginAt *time.Time `json:"last_login_at"`
	CreatedAt   time.Time  `json:"created_at"`
	UpdatedAt   time.Time  `json:"updated_at"`
	DeletedAt   *time.Time `json:"-"`
}

func (PlatformUser) TableName() string { return "platform_users" }

// Vai trò trong khu điều hành.
const (
	PlatformRoleOwner    = "owner"
	PlatformRoleOperator = "operator"
	PlatformRoleSupport  = "support"
)

// TenantDomain là tên miền trỏ vào một cửa hàng.
//
// Host lưu CHỮ THƯỜNG, không scheme, không cổng — nó phải so khớp thẳng được
// với header Host của request.
type TenantDomain struct {
	ID       uint   `json:"id" gorm:"primaryKey"`
	TenantID uint   `json:"tenant_id"`
	Host     string `json:"host"`
	// Kind: subdomain | custom
	Kind      string `json:"kind"`
	IsPrimary bool   `json:"is_primary"`
	// VerifiedAt NULL = chưa xác minh DNS. Chưa xác minh mà đã xin chứng chỉ
	// HTTPS thì Let's Encrypt từ chối, hỏng nhiều lần liên tiếp là bị khoá hạn
	// mức cả tuần.
	VerifiedAt *time.Time `json:"verified_at"`
	CreatedAt  time.Time  `json:"created_at"`
	UpdatedAt  time.Time  `json:"updated_at"`
}

func (TenantDomain) TableName() string { return "tenant_domains" }

// TenantDomainRepository tra cửa hàng theo tên miền của request.
//
// CHẠY TRÊN CONTROL PLANE. Hiện thực của nó phải nhận kết nối thứ hai
// (repository.NewPlatformDB) — đưa nhầm kết nối data plane vào thì câu truy vấn
// vẫn chạy vì bên đó cũng có bảng `tenants`, chỉ là nó không có tenant_domains
// và mọi tên miền sẽ thành "không tìm thấy".
//
// Đây là port DUY NHẤT của luồng phục vụ request đọc sang control plane, nên nó
// cũng là chỗ quyết định control plane có phải thành phần sống còn hay không:
// bật cụm bán hàng cho khách mà sổ này không đọc được thì không tên miền nào
// phân giải được, tức là cả trang bán hàng đứng im.
type TenantDomainRepository interface {
	// FindTenantByHost trả về cửa hàng sở hữu tên miền, kèm trạng thái của nó để
	// nơi gọi tự quyết định có phục vụ hay không.
	//
	// host phải đã chuẩn hoá: chữ thường, không scheme, không cổng.
	// Không có tên miền nào khớp thì trả ErrNotFound.
	FindTenantByHost(ctx context.Context, host string) (*PlatformTenant, error)
}
