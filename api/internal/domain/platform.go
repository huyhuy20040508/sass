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

// Ba gói bán ra. Giá trị khớp ENUM `plan` của subscriptions VÀ cột `code` của
// bảng giá plans dưới database — thêm mã gói mới là phải sửa cả hai bên.
const (
	PlanKhoiDau = "khoi_dau"
	PlanCuaHang = "cua_hang"
	PlanChuoi   = "chuoi"
)

// App là một phần mềm nền tảng bán ra.
//
// Hôm nay danh mục có đúng một dòng — AppOrder, phần mềm quản trị bán hàng
// đang chạy ở order.selliotech.store. Vì chỉ có một nên chưa chỗ nào phải hỏi
// "app nào": Subscription ghi gói mà không ghi gói CỦA app nào. Bảng có mặt để
// tới lúc có sản phẩm thứ hai thì không phải vừa dựng bảng vừa điền ngược cho
// các thuê bao đang chạy.
//
// Code — chứ không phải ID — là thứ đem đi dùng ở nơi khác (tiền tố tên miền,
// cấu hình, về sau là giá trị trong JWT/URL): ID là số tự sinh của một
// database, chép cấu hình sang máy khác là lệch.
type App struct {
	ID   uint   `json:"id" gorm:"primaryKey"`
	Code string `json:"code"`
	Name string `json:"name"`
	// Tagline: một dòng mô tả để hiện trong khu điều hành và bảng giá.
	Tagline StringOrNull `json:"tagline"`
	// Status: planned | active | retired
	Status    string    `json:"status"`
	CreatedAt time.Time `json:"created_at"`
	UpdatedAt time.Time `json:"updated_at"`
}

func (App) TableName() string { return "apps" }

// Mã của các app. Giá trị khớp cột `code` dưới database.
const (
	// AppOrder là phần mềm ĐANG CHẠY — dòng duy nhất của bảng apps hôm nay.
	AppOrder = "order"
)

// Trạng thái của một app trong danh mục.
//
// Mặc định dưới database là 'planned' chứ không phải 'active': dòng mới thêm mà
// tự nhiên bán được là cách nhanh nhất để ký hợp đồng cho phần mềm chưa tồn tại.
// AppRetired là NGỪNG BÁN, không phải ngừng chạy — khách cũ vẫn dùng tiếp.
const (
	AppPlanned = "planned"
	AppActive  = "active"
	AppRetired = "retired"
)

// Plan là MỘT MỨC GIÁ trong bảng giá hiện hành: gói này, app này, chu kỳ này.
//
// ĐÂY LÀ BẢNG GIÁ, KHÔNG PHẢI HỢP ĐỒNG. Subscription CHÉP Price/MaxShops ra
// lúc ký và từ đó sống độc lập — đừng bao giờ hiển thị giá của thuê bao bằng
// cách tra ngược về đây, vì bảng giá được phép đổi còn hợp đồng đã ký thì
// không. Chiều tra cứu hợp lệ duy nhất là lấy TÊN gói để hiển thị.
//
// Một gói bán theo tháng và theo năm là HAI dòng Plan, hai giá — khoá duy nhất
// là (AppID, Code, BillingCycle).
type Plan struct {
	ID    uint   `json:"id" gorm:"primaryKey"`
	AppID uint   `json:"app_id"`
	Code  string `json:"code"`
	Name  string `json:"name"`
	// Tagline: một dòng "gói này dành cho ai".
	Tagline StringOrNull `json:"tagline"`
	// BillingCycle: thang | nam
	BillingCycle string `json:"billing_cycle"`
	// Price nil = "Liên hệ" (gói Chuỗi): chưa có giá công khai, KHÁC 0 là miễn
	// phí. Subscription.Price thì không nil được — ký hợp đồng là phải có số.
	Price *float64 `json:"price"`
	// MaxShops nil = số chi nhánh thoả thuận riêng lúc ký. Đây là con số người
	// lập hợp đồng phải chép sang Subscription.MaxShops; quên chép thì mặc định
	// bên đó là 1, tức là bán gói chuỗi mà khách chỉ mở được một chi nhánh.
	MaxShops *uint `json:"max_shops"`
	// OwnDomain: gói này có được cấp tên miền riêng không (cả subdomain mình cấp
	// lẫn tên miền của khách — `TenantDomain.Kind` mới là chỗ phân biệt hai loại).
	//
	// ĐÂY LÀ ĐIỀU KHOẢN BÁN HÀNG NẰM TRONG DỮ LIỆU, cố ý không viết thành
	// `if plan == "chuoi"` trong code: đổi chính sách là UPDATE một ô trong bảng
	// giá, không phải sửa code rồi triển khai lại. Nơi ép luật là `cmd/ten-mien`,
	// đường duy nhất ghi vào sổ tên miền.
	OwnDomain bool `json:"own_domain"`
	TrialDays uint `json:"trial_days"`
	// Status: active | retired. Retired là NGỪNG BÁN MỚI — không xoá dòng, vì
	// thuê bao cũ còn tra tên gói ở đây.
	Status    string    `json:"status"`
	CreatedAt time.Time `json:"created_at"`
	UpdatedAt time.Time `json:"updated_at"`
}

func (Plan) TableName() string { return "plans" }

// Trạng thái của một mức giá.
const (
	PlanStatusActive  = "active"
	PlanStatusRetired = "retired"
)

// Chu kỳ tính tiền. Cùng bộ giá trị với Subscription.BillingCycle.
const (
	CycleThang = "thang"
	CycleNam   = "nam"
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
