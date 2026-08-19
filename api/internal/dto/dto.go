// Package dto định nghĩa cấu trúc request/response của tầng HTTP.
package dto

import (
	"time"

	"sass-api/internal/domain"
)

// ---------- Auth ----------

type RegisterRequest struct {
	FullName string `json:"full_name" binding:"required,min=2,max=150"`
	Email    string `json:"email" binding:"required,email"`
	Phone    string `json:"phone" binding:"omitempty,max=20"`
	Password string `json:"password" binding:"required,min=6,max=72"`
}

type LoginRequest struct {
	Email    string `json:"email" binding:"required,email"`
	Password string `json:"password" binding:"required"`
}

// ShopLoginRequest — đăng nhập 3 ô của Shop Admin.
//
// Tách hẳn khỏi LoginRequest (đăng nhập bằng email) vì hai đường phục vụ hai
// nhóm người khác nhau: đường này cho NHÂN VIÊN của một cửa hàng, còn email là
// đường của khách mua sắm và của khu điều hành nền tảng. Gộp chung một endpoint
// thì hai nhóm dùng chung hạn mức chống dò mật khẩu, và ô nào cũng thành tuỳ chọn
// nên không còn kiểm tra được gì.
type ShopLoginRequest struct {
	// ShopCode là tenants.code — MÃ KHÁCH HÀNG, không phải mã chi nhánh
	// (shops.code). Gọi là "mã cửa hàng" vì đó là chữ người dùng nhìn thấy: chuỗi
	// mình cấp lúc bàn giao phần mềm.
	ShopCode string `json:"shop_code" binding:"required,max=30"`
	Username string `json:"username" binding:"required,max=50"`
	Password string `json:"password" binding:"required"`
}

// VerifyEmailRequest — xác thực email bằng mã 6 số gửi qua thư.
type VerifyEmailRequest struct {
	Email string `json:"email" binding:"required,email"`
	Code  string `json:"code" binding:"required,len=6,numeric"`
}

// ResendCodeRequest — xin gửi lại mã xác thực.
type ResendCodeRequest struct {
	Email string `json:"email" binding:"required,email"`
}

// ---------- Yêu cầu khách gửi từ storefront ----------

// CreateContactRequest là dữ liệu form Liên hệ / form Thu mua gửi lên.
//
// Ảnh KHÔNG tải lên qua đường này: storefront (Laravel) nhận tệp, kiểm tra và cất
// vào ổ đĩa của nó rồi chỉ gửi sang đây danh sách URL. Nhận tệp ở cả hai tầng là
// nhân đôi chỗ phải canh dung lượng, kiểu tệp và chỗ trống trên đĩa.
type CreateContactRequest struct {
	// Type để trống thì hiểu là 'lien-he'.
	Type     string   `json:"type" binding:"omitempty,oneof=lien-he thu-mua"`
	FullName string   `json:"full_name" binding:"required,min=2,max=150"`
	Phone    string   `json:"phone" binding:"omitempty,max=20"`
	Email    string   `json:"email" binding:"omitempty,email,max=191"`
	Address  string   `json:"address" binding:"omitempty,max=255"`
	Subject  string   `json:"subject" binding:"omitempty,max=191"`
	Content  string   `json:"content" binding:"required,min=5,max=5000"`
	Images   []string `json:"images" binding:"omitempty,max=5,dive,url,max=500"`
}

// UpdateContactStatusRequest — trang quản trị đổi trạng thái xử lý.
type UpdateContactStatusRequest struct {
	Status    string `json:"status" binding:"required,oneof=moi dang-xu-ly da-xong"`
	AdminNote string `json:"admin_note" binding:"omitempty,max=500"`
}

// ContactRequestResponse là bản trả về của một yêu cầu.
//
// Có kiểu riêng thay vì trả thẳng entity vì hai chỗ lệch nhau: Images trong CSDL
// là chuỗi JSON còn ở đây phải là mảng, và tên nhân viên xử lý cần phẳng ra để
// giao diện khỏi đào vào object user lồng bên trong.
type ContactRequestResponse struct {
	ID          uint     `json:"id"`
	Type        string   `json:"type"`
	FullName    string   `json:"full_name"`
	Phone       string   `json:"phone"`
	Email       string   `json:"email"`
	Address     string   `json:"address"`
	Subject     string   `json:"subject"`
	Content     string   `json:"content"`
	Images      []string `json:"images"`
	Status      string   `json:"status"`
	AdminNote   string   `json:"admin_note"`
	HandlerName string   `json:"handler_name"`
	HandledAt   string   `json:"handled_at"`
	CreatedAt   string   `json:"created_at"`
}

// SubscribeNewsletterRequest — ô đăng ký nhận tin ở chân trang.
type SubscribeNewsletterRequest struct {
	Email  string `json:"email" binding:"required,email,max=191"`
	Source string `json:"source" binding:"omitempty,max=30"`
}

// ForgotPasswordRequest — bước 1 của quên mật khẩu: xin mã gửi về email.
type ForgotPasswordRequest struct {
	Email string `json:"email" binding:"required,email"`
}

// ResetPasswordRequest — bước 2: nhập mã vừa nhận kèm mật khẩu mới.
//
// Mật khẩu tối đa 72 ký tự giống lúc đăng ký: bcrypt cắt cụt từ byte thứ 73 trở
// đi, nên cho nhập dài hơn là hứa một đằng lưu một nẻo.
type ResetPasswordRequest struct {
	Email       string `json:"email" binding:"required,email"`
	Code        string `json:"code" binding:"required,len=6,numeric"`
	NewPassword string `json:"new_password" binding:"required,min=6,max=72"`
}

// RegisterResponse — kết quả đăng ký: chưa có token, cần nhập mã xác thực.
type RegisterResponse struct {
	Email              string `json:"email"`
	RequiresValidation bool   `json:"requires_verification"`
	ExpiresInSeconds   int64  `json:"expires_in"`
	ResendAfterSeconds int64  `json:"resend_after"`
	Message            string `json:"message"`
}

// FacebookLoginRequest — đăng nhập bằng Facebook.
//
// Chỉ nhận `code` (mã một lần Facebook trả về sau khi khách bấm đồng ý), KHÔNG nhận
// email/id do phía gọi tự khai: chỉ backend giữ app secret mới đổi được code, nên
// hồ sơ lấy ra chắc chắn là của khách chứ không phải do ai đó bịa.
type FacebookLoginRequest struct {
	Code string `json:"code" binding:"required"`
	// RedirectURI phải TRÙNG TỪNG KÝ TỰ với cái đã dùng lúc mở hộp thoại đăng nhập,
	// nếu không Facebook từ chối đổi code.
	RedirectURI string `json:"redirect_uri" binding:"required,url"`
}

// GoogleLoginRequest — đăng nhập bằng Google. Cùng lý lẽ với FacebookLoginRequest:
// chỉ nhận `code`, không nhận email/id do phía gọi tự khai.
type GoogleLoginRequest struct {
	Code string `json:"code" binding:"required"`
	// RedirectURI phải TRÙNG TỪNG KÝ TỰ với cái đã dùng lúc mở màn hình chọn tài
	// khoản, nếu không Google từ chối đổi code (redirect_uri_mismatch).
	RedirectURI string `json:"redirect_uri" binding:"required,url"`
}

type RefreshRequest struct {
	RefreshToken string `json:"refresh_token" binding:"required"`
}

type AuthResponse struct {
	AccessToken  string       `json:"access_token"`
	RefreshToken string       `json:"refresh_token"`
	TokenType    string       `json:"token_type"`
	ExpiresIn    int64        `json:"expires_in"` // giây
	User         *domain.User `json:"user"`
	// Tenant CHỈ có ở đăng nhập 3 ô: Shop Admin cần tên cửa hàng để hiện lên
	// thanh tiêu đề, mà token thì chưa mang tenant_id nên nó không tự tra được.
	// Các đường đăng nhập khác để trống (omitempty giấu hẳn khỏi JSON).
	//
	// Làm mới token KHÔNG trả lại trường này — Shop Admin cất vào session lúc
	// đăng nhập và giữ nguyên tới lúc đăng xuất.
	Tenant *domain.Tenant `json:"tenant,omitempty"`
	// CuaHangKhoa = true: cửa hàng đã HẾT HẠN HỢP ĐỒNG. Phiên vừa cấp vẫn dùng
	// được, nhưng chỉ cho ĐÚNG một đường đọc (gói dịch vụ) — mọi đường khác trả
	// 403 kèm mã CUA_HANG_KHOA.
	//
	// Chỉ vai trò quản lý mới nhận được phiên như vậy; nhân viên bị từ chối ngay
	// từ lượt đăng nhập. Shop Admin đọc cờ này để đưa người dùng thẳng tới trang
	// gói dịch vụ thay vì để họ bấm quanh và nhận lỗi ở từng trang.
	CuaHangKhoa bool `json:"cua_hang_khoa,omitempty"`
}

// PlatformAuthResponse — kết quả đăng nhập / làm mới token của KHU ĐIỀU HÀNH.
//
// Kiểu riêng chứ không dùng chung AuthResponse, và khác biệt nằm ở đúng một
// trường: `user` ở đây là một dòng `platform_users` (người của nền tảng), không
// phải một dòng `users` của cửa hàng nào. Dùng chung kiểu thì phải bịa ra một
// tài khoản cửa hàng cho người điều hành — chính là cách làm cũ, và là lỗ hổng
// mà migration 0007 đóng lại.
//
// Cũng KHÔNG có trường `tenant`: người điều hành không đứng ở cửa hàng nào, và
// token phát ra mang tenant_id = 0.
type PlatformAuthResponse struct {
	AccessToken  string `json:"access_token"`
	RefreshToken string `json:"refresh_token"`
	TokenType    string `json:"token_type"`
	ExpiresIn    int64  `json:"expires_in"` // giây
	// User.Role là CHUỖI (owner | operator | support), không phải một đối tượng
	// vai trò như bên cửa hàng — khu điều hành không có bảng RBAC riêng.
	User *domain.PlatformUser `json:"user"`
}

// ---------- Banner ----------

// BannerRequest là dữ liệu tạo/sửa một banner.
//
// StartAt/EndAt nhận chuỗi "2006-01-02T15:04" (đúng thứ mà ô <input
// type="datetime-local"> của trang quản trị gửi lên) hoặc để trống = không giới
// hạn. Cố ý KHÔNG dùng time.Time: ô trống sẽ thành "0001-01-01" thay vì nil, và
// banner nào cũng bị coi là đã hết hạn từ hai nghìn năm trước.
type BannerRequest struct {
	Title     string `json:"title" binding:"omitempty,max=200"`
	Image     string `json:"image" binding:"required,max=255"`
	Link      string `json:"link" binding:"omitempty,max=255"`
	Position  string `json:"position" binding:"required,max=50" example:"home_slider"`
	SortOrder *int   `json:"sort_order"`
	IsActive  *bool  `json:"is_active"`
	StartAt   string `json:"start_at" binding:"omitempty,datetime=2006-01-02T15:04" example:"2026-08-10T08:00"`
	EndAt     string `json:"end_at" binding:"omitempty,datetime=2006-01-02T15:04" example:"2026-09-01T23:59"`
}

// BannerStatusRequest bật/tắt hiển thị một banner.
type BannerStatusRequest struct {
	IsActive *bool `json:"is_active" binding:"required"`
}

// BannerSortRequest sắp xếp lại banner trong CÙNG một vị trí: thứ tự phần tử
// trong mảng chính là thứ tự hiển thị.
type BannerSortRequest struct {
	IDs []uint `json:"ids" binding:"required,min=1"`
}

// ---------- Chương trình khuyến mãi ----------

// PromotionRequest — tạo/sửa một đợt giảm giá.
//
// Ngày giờ đi theo đúng định dạng của ô <input type="datetime-local"> mà trang
// quản trị đang dùng, để hai bên không phải đổi qua lại.
type PromotionRequest struct {
	Name         string `json:"name" binding:"required,max=150"`
	Description  string `json:"description" binding:"omitempty,max=255"`
	DiscountType string `json:"discount_type" binding:"required,oneof=percentage fixed"`
	// DiscountValue là % (1–100) khi type=percentage, hoặc số tiền giảm mỗi sản
	// phẩm khi type=fixed. Trần trên của % được kiểm ở tầng service vì nó phụ
	// thuộc discount_type.
	DiscountValue float64 `json:"discount_value" binding:"required,gt=0"`
	// MaxDiscountAmount chỉ dùng khi giảm theo %: "giảm 30% nhưng tối đa 200.000đ".
	MaxDiscountAmount *float64 `json:"max_discount_amount" binding:"omitempty,gt=0"`
	StartAt           string   `json:"start_at" binding:"required,datetime=2006-01-02T15:04" example:"2026-08-10T08:00"`
	EndAt             string   `json:"end_at" binding:"required,datetime=2006-01-02T15:04" example:"2026-09-01T23:59"`
	IsActive          *bool    `json:"is_active"`
	// Hai danh sách phạm vi. Phải có ít nhất một id ở một trong hai — chương trình
	// không phạm vi thì không giảm cho ai, tạo ra chỉ để nằm đó gây hiểu nhầm.
	ProductIDs  []uint `json:"product_ids"`
	CategoryIDs []uint `json:"category_ids"`
}

// PromotionStatusRequest bật/tắt một chương trình mà không đụng tới ngày chạy.
type PromotionStatusRequest struct {
	IsActive *bool `json:"is_active" binding:"required"`
}

// PromotionResponse là chương trình kèm phạm vi đã tách sẵn thành hai danh sách id
// và mấy thông tin suy ra sẵn cho giao diện.
type PromotionResponse struct {
	ID                uint     `json:"id"`
	Name              string   `json:"name"`
	Description       string   `json:"description"`
	DiscountType      string   `json:"discount_type"`
	DiscountValue     float64  `json:"discount_value"`
	MaxDiscountAmount *float64 `json:"max_discount_amount"`
	StartAt           string   `json:"start_at" example:"2026-08-10T08:00"`
	EndAt             string   `json:"end_at" example:"2026-09-01T23:59"`
	IsActive          bool     `json:"is_active"`
	// Status: running | scheduled | ended | paused — tính từ ngày giờ + is_active
	// ở một chỗ duy nhất, để trang quản trị và storefront không tự suy mỗi nơi một kiểu.
	Status      string `json:"status"`
	ProductIDs  []uint `json:"product_ids"`
	CategoryIDs []uint `json:"category_ids"`
	// ProductCount là số sản phẩm đang bán mà chương trình phủ tới (đã tính cả
	// danh mục con).
	ProductCount int64  `json:"product_count"`
	CreatedAt    string `json:"created_at"`
}

// ---------- Voucher ----------

// VoucherRequest — tạo/sửa một mã giảm giá.
//
// Ngày giờ đi theo đúng định dạng của ô <input type="datetime-local"> mà trang
// quản trị đang dùng. Để TRỐNG là không giới hạn phía đó (dùng được ngay / dùng
// mãi tới khi tắt), khác hẳn với chương trình khuyến mãi vốn bắt buộc hai mốc.
type VoucherRequest struct {
	// Code được chuẩn hoá về CHỮ HOA ở tầng service — khách gõ "sale10" vẫn trúng
	// mã "SALE10". Chỉ nhận chữ không dấu, số, gạch ngang và gạch dưới.
	Code         string `json:"code" binding:"required,max=50" example:"SALE10"`
	Description  string `json:"description" binding:"omitempty,max=255"`
	DiscountType string `json:"discount_type" binding:"required,oneof=percentage fixed"`
	// DiscountValue là % (1–100) khi type=percentage, hoặc số tiền giảm trên TỔNG
	// ĐƠN khi type=fixed. Trần trên của % được kiểm ở tầng service vì nó phụ thuộc
	// discount_type.
	DiscountValue float64 `json:"discount_value" binding:"required,gt=0"`
	// MaxDiscountAmount chỉ dùng khi giảm theo %: "giảm 20% nhưng tối đa 100.000₫".
	MaxDiscountAmount *float64 `json:"max_discount_amount" binding:"omitempty,gt=0"`
	// MinOrderAmount là giá trị đơn tối thiểu để mã có hiệu lực (0 = không yêu cầu).
	MinOrderAmount float64 `json:"min_order_amount" binding:"omitempty,gte=0"`
	// UsageLimit là tổng số lượt dùng của mã, UsageLimitPerUser là số lượt mỗi
	// khách. Để null = không giới hạn.
	UsageLimit        *uint  `json:"usage_limit" binding:"omitempty,gt=0"`
	UsageLimitPerUser *uint  `json:"usage_limit_per_user" binding:"omitempty,gt=0"`
	StartAt           string `json:"start_at" binding:"omitempty,datetime=2006-01-02T15:04" example:"2026-08-10T08:00"`
	EndAt             string `json:"end_at" binding:"omitempty,datetime=2006-01-02T15:04" example:"2026-09-01T23:59"`
	IsActive          *bool  `json:"is_active"`
	// IsPublic = true thì mã hiện thẳng ở ô nhập mã lúc thanh toán cho MỌI khách.
	// Mặc định false — mã gửi tay cho một người mà khoe ra là ai cũng dùng được.
	IsPublic *bool `json:"is_public"`
}

// VoucherCheckRequest — khách gõ mã ở giỏ hàng để xem trước số tiền được giảm.
type VoucherCheckRequest struct {
	Code string `json:"code" binding:"required,max=50" example:"SALE10"`
	// Subtotal là tiền hàng của giỏ hiện tại. Con số này chỉ dùng để XEM TRƯỚC —
	// lúc đặt thật, API tính lại trên giá đã khoá chứ không tin số client gửi.
	Subtotal float64 `json:"subtotal" binding:"required,gt=0"`
	// Phone là số điện thoại người nhận nếu khách đã điền. Có nó thì hạn mức "mỗi
	// khách N lượt" kiểm được ngay tại đây với cả khách chưa đăng nhập, thay vì để
	// khách áp mã xong mới bị chặn lúc bấm đặt.
	Phone string `json:"phone" binding:"omitempty,max=20"`
}

// VoucherCheckResponse — kết quả xem trước một mã.
type VoucherCheckResponse struct {
	Code        string  `json:"code"`
	Description string  `json:"description"`
	Discount    float64 `json:"discount_amount"`
	// Subtotal / Total là tiền hàng trước và sau giảm. CHƯA gồm phí vận chuyển —
	// phí ship tính trên tiền hàng trước giảm và client đã tự biết.
	Subtotal float64 `json:"subtotal"`
	Total    float64 `json:"total"`
}

// VoucherAvailableRequest — lấy danh sách mã đại trà để gợi ý ngay tại ô nhập mã.
type VoucherAvailableRequest struct {
	// Subtotal là tiền hàng của giỏ hiện tại, dùng để tính sẵn mỗi mã giảm được bao
	// nhiêu và mã nào còn thiếu bao nhiêu mới dùng được.
	Subtotal float64 `json:"subtotal" binding:"required,gt=0"`
	// Phone là số điện thoại người nhận nếu khách đã điền — dùng để loại mã họ đã
	// dùng hết lượt riêng, kể cả khi chưa đăng nhập.
	Phone string `json:"phone" binding:"omitempty,max=20"`
}

// VoucherAvailableItem — một mã đại trà kèm mọi thứ giao diện cần để hiện thẻ mã.
type VoucherAvailableItem struct {
	Code              string   `json:"code"`
	Description       string   `json:"description"`
	DiscountType      string   `json:"discount_type"`
	DiscountValue     float64  `json:"discount_value"`
	MaxDiscountAmount *float64 `json:"max_discount_amount"`
	MinOrderAmount    float64  `json:"min_order_amount"`
	EndAt             string   `json:"end_at" example:"2026-09-01T23:59"`
	// Discount là số tiền mã này giảm cho giỏ hiện tại, 0 khi chưa đủ điều kiện.
	Discount float64 `json:"discount_amount"`
	// Usable = bấm phát là áp được ngay.
	Usable bool `json:"usable"`
	// MissingAmount là số tiền còn thiếu để đạt đơn tối thiểu (0 khi đã đủ). Có nó
	// thì giao diện nói được "mua thêm 200.000₫ để dùng mã này" thay vì chỉ làm mờ
	// mã đi rồi để khách tự đoán vì sao.
	MissingAmount float64 `json:"missing_amount"`
}

// VoucherStatusRequest bật/tắt một mã mà không đụng tới ngày hiệu lực.
type VoucherStatusRequest struct {
	IsActive *bool `json:"is_active" binding:"required"`
}

// VoucherResponse là voucher kèm mấy thông tin suy ra sẵn cho giao diện.
type VoucherResponse struct {
	ID                uint     `json:"id"`
	Code              string   `json:"code"`
	Description       string   `json:"description"`
	DiscountType      string   `json:"discount_type"`
	DiscountValue     float64  `json:"discount_value"`
	MaxDiscountAmount *float64 `json:"max_discount_amount"`
	MinOrderAmount    float64  `json:"min_order_amount"`
	UsageLimit        *uint    `json:"usage_limit"`
	UsageLimitPerUser *uint    `json:"usage_limit_per_user"`
	UsedCount         uint     `json:"used_count"`
	// Remaining là số lượt còn lại, null khi không giới hạn tổng lượt.
	Remaining *uint  `json:"remaining"`
	StartAt   string `json:"start_at" example:"2026-08-10T08:00"`
	EndAt     string `json:"end_at" example:"2026-09-01T23:59"`
	IsActive  bool   `json:"is_active"`
	IsPublic  bool   `json:"is_public"`
	// Status: running | scheduled | ended | used_up | paused — tính từ ngày giờ +
	// lượt đã dùng + is_active ở MỘT chỗ duy nhất, để trang quản trị và storefront
	// không tự suy mỗi nơi một kiểu.
	Status    string `json:"status"`
	CreatedAt string `json:"created_at"`
}

// ---------- Product ----------

type ProductRequest struct {
	CategoryID uint   `json:"category_id" binding:"required"`
	Name       string `json:"name" binding:"required,max=200"`
	Slug       string `json:"slug" binding:"required,max=191"`
	// SKU bỏ trống = sinh theo quy tắc mã hàng hoá; chưa bật quy tắc thì API trả
	// 422 đòi nhập tay. Lúc SỬA, bỏ trống là giữ mã cũ.
	SKU              string   `json:"sku" binding:"omitempty,max=64"`
	ShortDescription string   `json:"short_description" binding:"omitempty,max=500"`
	Description      string   `json:"description"`
	Team             string   `json:"team" binding:"omitempty,max=150"`
	Season           string   `json:"season" binding:"omitempty,max=20"`
	KitType          string   `json:"kit_type" binding:"omitempty,oneof=fan player"`
	BasePrice        float64  `json:"base_price" binding:"gte=0"`
	SalePrice        *float64 `json:"sale_price" binding:"omitempty,gte=0"`
	// CostPrice là giá vốn dùng tính giá trị tồn kho. Bỏ trống = chưa khai giá vốn
	// (khác với 0), lúc đó biến thể của sản phẩm không được cộng vào giá trị kho.
	CostPrice *float64 `json:"cost_price" binding:"omitempty,gte=0"`
	Thumbnail string   `json:"thumbnail" binding:"omitempty,max=255"`
	// Status là trạng thái kinh doanh: active (đang bán) | hidden (tạm ẩn) |
	// discontinued (ngừng kinh doanh). Bỏ trống thì lấy theo IsActive.
	Status string `json:"status" binding:"omitempty,oneof=active hidden discontinued"`
	// IsActive giữ lại cho tương thích ngược (bản quản trị cũ chỉ biết cờ bật/tắt).
	// Khi cả hai cùng gửi, Status thắng.
	IsActive        *bool  `json:"is_active"`
	IsFeatured      *bool  `json:"is_featured"`
	MetaTitle       string `json:"meta_title" binding:"omitempty,max=255"`
	MetaDescription string `json:"meta_description" binding:"omitempty,max=320"`

	// Biến thể (size/màu). Con trỏ để phân biệt:
	//   nil     -> không đụng tới biến thể (vd: chỉ bật/tắt trạng thái).
	//   [] hoặc [...] -> đồng bộ đúng theo danh sách (thêm/sửa/xoá).
	Variants *[]VariantRequest `json:"variants"`

	// Thư viện ảnh sản phẩm — cùng quy ước con trỏ như Variants.
	Images *[]ImageRequest `json:"images"`
}

// ImageRequest là một ảnh trong thư viện ảnh sản phẩm.
type ImageRequest struct {
	ID        uint   `json:"id"` // >0 = ảnh cũ; 0 = ảnh mới
	URL       string `json:"url" binding:"required,max=255"`
	Alt       string `json:"alt" binding:"omitempty,max=200"`
	SortOrder int    `json:"sort_order"`
	IsPrimary bool   `json:"is_primary"`
}

// VariantRequest là một biến thể của sản phẩm (mỗi size/màu một dòng).
//
// Cố ý KHÔNG có stock_quantity: tồn kho chỉ đi qua nghiệp vụ kho (nhập hàng,
// điều chỉnh/kiểm kho, đơn hàng, trả hàng) để mọi biến động đều có vết trong
// inventory_transactions. Biến thể mới luôn bắt đầu ở tồn 0.
//
// Cũng không còn `version`: fan/player đã chuyển lên cấp sản phẩm (kit_type).
type VariantRequest struct {
	ID  uint   `json:"id"` // >0 = cập nhật dòng cũ; 0 = thêm mới
	SKU string `json:"sku" binding:"omitempty,max=64"`
	// Barcode là mã vạch in trên hàng — cái máy quét ở quầy đọc được. Để trống
	// nghĩa là chưa dán mã, và hai biến thể cùng để trống là chuyện bình thường;
	// nhưng hai biến thể đang bán không được mang cùng một mã (DB chặn).
	Barcode string   `json:"barcode" binding:"omitempty,max=64"`
	Size    string   `json:"size" binding:"required,max=20"`
	Color   string   `json:"color" binding:"omitempty,max=50"`
	Price   *float64 `json:"price" binding:"omitempty,gte=0"`
	// CostPrice bỏ trống = lấy giá vốn của sản phẩm cha.
	CostPrice  *float64 `json:"cost_price" binding:"omitempty,gte=0"`
	WeightGram int      `json:"weight_gram" binding:"gte=0"`
	Image      string   `json:"image" binding:"omitempty,max=255"`
	IsActive   *bool    `json:"is_active"`
}

// ProductStatusRequest chỉ dùng để đổi trạng thái kinh doanh của sản phẩm —
// tránh gửi lại toàn bộ read-model khi chỉ cần bật/tắt hoặc ngừng kinh doanh.
//
// Nhận một trong hai: `status` (đủ 3 mức) hoặc `is_active` (cờ cũ, chỉ bật/tắt).
// Gửi cả hai thì `status` thắng.
type ProductStatusRequest struct {
	Status   string `json:"status" binding:"omitempty,oneof=active hidden discontinued"`
	IsActive *bool  `json:"is_active"`
}

// Resolve trả về trạng thái cuối cùng và cho biết yêu cầu có hợp lệ không.
func (r ProductStatusRequest) Resolve() (string, bool) {
	if r.Status != "" {
		return r.Status, true
	}
	if r.IsActive == nil {
		return "", false
	}
	if *r.IsActive {
		return "active", true
	}
	return "hidden", true
}

// ProductBulkDeleteRequest — danh sách id sản phẩm cần xoá trong một lượt.
type ProductBulkDeleteRequest struct {
	IDs []uint `json:"ids" binding:"required,min=1,max=200,dive,gt=0"`
}

// ---------- Category ----------

type CategoryRequest struct {
	Name string `json:"name" binding:"required,max=150"`
	// Slug là MÃ NHÓM. Bỏ trống = hệ thống đặt (theo quy tắc đánh số của cửa
	// hàng, hoặc dải NH0001 nếu chưa bật quy tắc).
	Slug        string `json:"slug" binding:"omitempty,max=191"`
	ParentID    *uint  `json:"parent_id"`
	Description string `json:"description" binding:"omitempty,max=500"`
	Image       string `json:"image" binding:"omitempty,max=255"`
	SortOrder   int    `json:"sort_order"`
	IsActive    *bool  `json:"is_active"`
}

// ---------- Customer ----------

// CustomerRequest — payload tạo/cập nhật khách hàng từ trang quản trị.
//
// Tài khoản khách hàng chỉ có 2 trạng thái: active (hoạt động) | inactive (không hoạt động).
type CustomerRequest struct {
	FullName string `json:"full_name" binding:"required,max=150"`
	// Email bắt buộc & duy nhất: vừa là tên đăng nhập storefront, vừa là UNIQUE key ở bảng users.
	Email       string `json:"email" binding:"required,email,max=191"`
	Phone       string `json:"phone" binding:"omitempty,max=20"`
	Avatar      string `json:"avatar" binding:"omitempty,max=255"`
	Gender      string `json:"gender" binding:"omitempty,oneof=male female other"`
	DateOfBirth string `json:"date_of_birth" binding:"omitempty" example:"1998-08-23"`
	Address     string `json:"address" binding:"omitempty,max=255"`
	Status      string `json:"status" binding:"required,oneof=active inactive"`
	// Password chỉ dùng khi tạo mới (tài khoản đăng nhập storefront);
	// bỏ trống thì hệ thống cấp mật khẩu mặc định.
	Password string `json:"password" binding:"omitempty,min=6,max=72"`
}

// ---------- Chi nhánh ----------

// ChiNhanhRequest — payload tạo/sửa MỘT CHI NHÁNH (điểm bán) của cửa hàng.
//
// "Chi nhánh" ở đây là bảng `shops`, KHÔNG phải cửa hàng (tenant) — xem
// domain.ChiNhanh về cái bẫy tên gọi này.
type ChiNhanhRequest struct {
	// Code bỏ trống khi TẠO = hệ thống tự sinh (chi-nhanh-2, chi-nhanh-3…). Bỏ
	// trống khi SỬA = giữ nguyên mã cũ: mã đã đi vào chứng từ, tự đổi là hồ sơ
	// hai bên lệch nhau.
	Code string `json:"code" binding:"omitempty,max=30" example:"kho-mien-bac"`
	Name string `json:"name" binding:"required,max=150" example:"Kho miền Bắc"`
	// Phone/Address bỏ trống ghi xuống NULL, không phải chuỗi rỗng.
	Phone   string `json:"phone" binding:"omitempty,max=20" example:"0912345678"`
	Address string `json:"address" binding:"omitempty,max=255"`
	// IsActive bỏ trống = true (chi nhánh mới mặc định đang hoạt động).
	IsActive *bool `json:"is_active"`
}

// ---------- Nhân sự (hồ sơ người đi làm) ----------

// NhanSuRequest — payload tạo/sửa MỘT HỒ SƠ NHÂN VIÊN.
//
// Đây là CON NGƯỜI, không phải tài khoản đăng nhập (xem domain.NhanVien). Việc
// cấp tài khoản là khối `tai_khoan` lồng bên trong — tuỳ chọn, vì phần đông nhân
// viên của một tiệm nhỏ không đụng vào phần mềm.
type NhanSuRequest struct {
	// Code bỏ trống khi TẠO = hệ thống tự đặt (NV0001, NV0002…). Bỏ trống khi SỬA
	// = giữ nguyên mã cũ: mã đã đi vào bảng lương và bảng chấm công.
	Code     string `json:"code" binding:"omitempty,max=30" example:"NV0007"`
	FullName string `json:"full_name" binding:"required,max=150" example:"Nguyễn Văn An"`
	// Avatar — đường dẫn ảnh do Shop Admin tải lên và lưu hộ; API chỉ cất chuỗi.
	Avatar string `json:"avatar" binding:"omitempty,max=255"`
	Gender string `json:"gender" binding:"omitempty,oneof=nam nu khac"`
	// BirthDate/HiredOn theo dạng YYYY-MM-DD (đúng thứ ô <input type="date"> gửi lên).
	BirthDate string `json:"birth_date" binding:"omitempty" example:"1998-08-23"`
	Phone     string `json:"phone" binding:"omitempty,max=20"`
	Email     string `json:"email" binding:"omitempty,email,max=191"`
	IDNumber  string `json:"id_number" binding:"omitempty,max=20"`
	Address   string `json:"address" binding:"omitempty,max=255"`

	// Position là chức danh CÔNG VIỆC, không phải quyền trên phần mềm.
	//
	// KHÔNG còn bắt buộc: trang nhân sự đã thay ô này bằng ca làm việc (với một
	// cửa hàng bán lẻ thì chức danh không nói thêm gì so với ô Phân quyền). Bỏ
	// trống thì lượt TẠO rơi về mặc định 'ban_hang' của cột, còn lượt SỬA giữ
	// nguyên giá trị cũ — hồ sơ khai từ trước không bị một lượt sửa tên làm mất
	// chức danh.
	Position string `json:"position" binding:"omitempty" example:"thu_ngan"`
	// WorkShift — các ca người này trực. Mảng vì một người trực được nhiều ca;
	// service ghép thành chuỗi cho cột SET `employees.work_shift`.
	WorkShift []string `json:"work_shift" example:"sang,chieu"`
	// ShopID BẮT BUỘC: mỗi nhân viên phải đứng ở một chi nhánh cụ thể. Cửa hàng
	// một điểm bán thì màn hình tự chọn sẵn chi nhánh duy nhất đó, người dùng
	// không phải làm gì thêm — nhưng dữ liệu vẫn ghi rõ nơi làm việc, để bảng
	// chấm công và báo cáo theo chi nhánh sau này không phải đoán.
	// KHÔNG dùng binding:"required" ở đây: bộ validate của gin đặt tên ô theo tên
	// TRƯỜNG GO ("ShopID") nên trang quản trị nhận về một khoá không khớp ô nào
	// trên form. Lượt kiểm nằm ở service và trả đúng khoá `shop_id`.
	ShopID       uint   `json:"shop_id"`
	HiredOn      string `json:"hired_on" binding:"omitempty" example:"2026-08-17"`
	ContractType string `json:"contract_type" binding:"omitempty"`
	Status       string `json:"status" binding:"required" example:"dang_lam"`

	SalaryType string  `json:"salary_type" binding:"omitempty"`
	Salary     float64 `json:"salary" binding:"omitempty,gte=0"`
	Allowance  float64 `json:"allowance" binding:"omitempty,gte=0"`
	// CommissionRate tính theo % doanh số — quá 100 thì bán càng nhiều càng lỗ,
	// gần như chắc chắn là gõ nhầm.
	CommissionRate float64 `json:"commission_rate" binding:"omitempty,gte=0,lte=100"`

	Note string `json:"note" binding:"omitempty,max=500"`

	// TaiKhoan có mặt = cấp tài khoản đăng nhập cho người này. Bỏ hẳn khoá này
	// (null) = không cấp, KHÁC với gửi khối rỗng.
	TaiKhoan *NhanSuTaiKhoanRequest `json:"tai_khoan"`

	// RoleID — QUYỀN của người này trên phần mềm (2 = quản lý, 3 = thu ngân).
	//
	// Một trường cho cả hai việc, có chủ ý: lúc cấp tài khoản mới thì đây là
	// quyền đặt cho nó, lúc sửa hồ sơ đã có tài khoản thì đây là quyền ĐỔI SANG.
	// Hai trường riêng cho cùng một khái niệm là chờ ngày màn hình gửi cái này
	// còn API đọc cái kia.
	//
	// KHÁC hẳn Position: Position là VIỆC người đó làm, còn đây là những gì họ
	// mở được trên phần mềm. Mặc định hai thứ đi đôi, nhưng cửa hàng có quyền
	// tách chúng ra — quản lý ca tối chỉ đứng quầy, hay thu ngân được giao coi kho.
	//
	// 0 = không nói gì về quyền (hồ sơ không có tài khoản).
	RoleID uint `json:"role_id" example:"3"`

	// Quyen — CỬA VÀO đã tích cho người này: "quan_ly", "thu_ngan", hoặc cả hai.
	//
	// Mảng chứ không phải một con số, vì một người giữ được cả hai cửa và màn
	// hình phải hiện lại đúng thứ đã tích. RoleID ở trên vẫn suy ra từ đây (có
	// "quan_ly" -> 2, còn lại -> 3) và vẫn được gửi kèm: nó là khoá ngoại tới
	// `roles`, nằm trong token, và là thứ phân biệt người của tiệm với khách.
	//
	// Rỗng = lượt gọi không nói gì về cửa; service giữ nguyên cột đang có.
	Quyen []string `json:"quyen" example:"quan_ly,thu_ngan"`

	// MoTaiKhoan = "nhận người này làm lại thì mở luôn tài khoản cũ".
	//
	// Chỉ có nghĩa khi Status quay về `dang_lam`. Đặt trạng thái `da_nghi` luôn
	// KHOÁ tài khoản (không hỏi), còn mở lại thì phải nói rõ ở đây — xem
	// service.dongBoTaiKhoan.
	MoTaiKhoan bool `json:"mo_tai_khoan"`
}

// NhanSuTaiKhoanRequest — khối "cấp tài khoản đăng nhập" nằm trong hồ sơ nhân sự.
//
// Không có ô email riêng: email của tài khoản lấy từ chính hồ sơ (NhanSuRequest.Email),
// nên một người chỉ khai một địa chỉ. Bảng `users` bắt buộc email và đặt UNIQUE
// lên nó, nên hồ sơ nào xin cấp tài khoản thì phải có email — service từ chối
// kèm câu nói rõ điều đó thay vì bịa ra một địa chỉ nội bộ.
type NhanSuTaiKhoanRequest struct {
	Username string `json:"username" binding:"required,min=3,max=50" example:"an.nv"`
	// Password bỏ trống thì hệ thống cấp mật khẩu mặc định, y như trang tài khoản.
	Password string `json:"password" binding:"omitempty,min=6,max=72"`
}

// NhanSuTrangThaiRequest — bật/tắt nhanh trạng thái làm việc từ công tắc trên
// bảng danh sách.
//
// Đường riêng chứ không dùng lại PUT hồ sơ: bấm một công tắc mà phải gửi lên cả
// hai chục trường của hồ sơ thì chỉ cần màn hình đang giữ một bản cũ là lượt bấm
// đó ghi đè ngược mọi thứ người khác vừa sửa.
type NhanSuTrangThaiRequest struct {
	Status string `json:"status" binding:"required" example:"dang_lam"`
	// MoTaiKhoan: xem NhanSuRequest.MoTaiKhoan — công tắc gạt về "đang làm" gửi
	// kèm khoá này sau khi màn hình hỏi lại người bấm.
	MoTaiKhoan bool `json:"mo_tai_khoan"`
}

// NhanSuResponse — hồ sơ nhân viên kèm hai thứ mà màn hình danh sách cần mà bảng
// `employees` không giữ: tên chi nhánh và tên đăng nhập của tài khoản.
//
// Ghép sẵn ở API thay vì để trang quản trị gọi thêm hai lượt nữa rồi tự nối —
// nối ở phía ngoài thì mỗi màn hình nối một kiểu và sẽ có màn hình nối sai.
type NhanSuResponse struct {
	domain.NhanVien
	ShopName string `json:"shop_name"`
	Username string `json:"username"`
	RoleID   uint   `json:"role_id"`
	// UserStatus (active | inactive) là trạng thái của TÀI KHOẢN, khác với Status
	// của hồ sơ. Hai cột tách nhau nên màn hình phải nói được "đang làm nhưng tài
	// khoản đang khoá" — trường hợp có thật sau khi nhận lại người cũ mà chưa mở
	// tài khoản cho họ.
	UserStatus string `json:"user_status"`
	// Quyen — cửa vào của tài khoản gắn kèm. Bảng hiện ĐÚNG danh sách này, một
	// huy hiệu mỗi cửa: tích hai ô thì ra hai huy hiệu, tích một thì ra một.
	Quyen           []string `json:"quyen"`
	RoleDisplayName string   `json:"role_display_name"`
}

// ---------- Nhóm quyền (phân quyền theo chức năng) ----------

// NhomQuyenRequest — payload tạo/sửa một NHÓM QUYỀN.
type NhomQuyenRequest struct {
	// Code bỏ trống khi TẠO = hệ thống tự đặt (nhom-1, nhom-2…). Khi SỬA thì
	// khoá này bị bỏ qua: mã là thứ mã nguồn gọi tên hai nhóm hệ thống.
	Code        string `json:"code" binding:"omitempty,max=50"`
	Name        string `json:"name" binding:"required,max=100" example:"Thủ kho"`
	Description string `json:"description" binding:"omitempty,max=255"`
	// Quyen là TOÀN BỘ danh sách quyền của nhóm sau lượt này.
	//
	// nil (bỏ hẳn khoá) = giữ nguyên danh sách đang có. Mảng RỖNG = bỏ hết tick.
	// Hai thứ đó khác nhau, và gộp lại thì không có cách nào thu hết quyền của
	// một nhóm.
	Quyen []string `json:"quyen"`
}

// NhomQuyenQuyenRequest — chỉ thay danh sách quyền. Dùng cho cả bảng tick của
// một NHÓM lẫn bảng tick của một NGƯỜI.
type NhomQuyenQuyenRequest struct {
	Quyen []string `json:"quyen"`
}

// NhomQuyenResponse — một nhóm kèm hai thứ màn hình cần mà bảng không giữ.
type NhomQuyenResponse struct {
	domain.NhomQuyen
	// Quyen: nhóm mang cờ toàn quyền trả về CẢ danh mục, vì đó đúng là những gì
	// nó có — màn hình tick hiện đủ dấu tick thay vì một danh sách trống.
	Quyen []string `json:"quyen"`
}

// QuyenCuaToiResponse — quyền của CHÍNH người đang đăng nhập.
//
// Trang quản trị đọc nó một lần lúc đăng nhập để lọc menu. Nó không thay cho
// chốt ở API: ẩn một mục menu chỉ là phép lịch sự, còn chặn thật vẫn nằm ở
// middleware của từng đường.
type QuyenCuaToiResponse struct {
	ToanQuyen bool     `json:"toan_quyen"`
	Quyen     []string `json:"quyen"`
}

// ---------- Tài khoản nội bộ & vai trò ----------

// UserRequest — payload tạo/sửa tài khoản NỘI BỘ (quản trị & nhân viên).
//
// Vai trò truyền bằng role_id; chỉ nhận vai trò nội bộ (super_admin, admin,
// staff). Muốn tạo khách hàng thì dùng /admin/customers, không phải đường này.
type UserRequest struct {
	FullName string `json:"full_name" binding:"required,max=150"`
	// Username là thứ người này gõ vào ô THỨ HAI của màn hình đăng nhập Shop Admin.
	// Bắt buộc: tài khoản nội bộ không có tên đăng nhập thì tạo ra xong không vào
	// được trang quản trị bằng đường nào cả.
	Username string `json:"username" binding:"required,min=3,max=50"`
	// Email KHÔNG còn là tên đăng nhập (xem Username) nhưng vẫn bắt buộc và vẫn là
	// UNIQUE key ở bảng users: nó là đường liên lạc và là chỗ bám của các chức năng
	// gửi thư. Nhân viên không có email thật thì chủ shop đặt một địa chỉ nội bộ.
	Email  string `json:"email" binding:"required,email,max=191"`
	Phone  string `json:"phone" binding:"omitempty,max=20"`
	Avatar string `json:"avatar" binding:"omitempty,max=255"`
	RoleID uint   `json:"role_id" binding:"required" example:"2"`
	Status string `json:"status" binding:"required,oneof=active inactive"`
	// Password chỉ dùng khi tạo mới; bỏ trống thì hệ thống cấp mật khẩu mặc định.
	// Sửa tài khoản thì bỏ qua trường này — đổi mật khẩu có endpoint riêng.
	Password string `json:"password" binding:"omitempty,min=6,max=72"`
}

// UserStatusRequest — payload bật/tắt nhanh một tài khoản nội bộ.
type UserStatusRequest struct {
	Status string `json:"status" binding:"required,oneof=active inactive"`
}

// UserPasswordRequest — payload đặt lại mật khẩu đăng nhập trang quản trị.
type UserPasswordRequest struct {
	Password string `json:"password" binding:"required,min=6,max=72"`
}

// ProfileRequest — payload người đang đăng nhập tự sửa hồ sơ của mình.
//
// KHÔNG có role_id và status: tự đổi vai trò hay tự khoá mình là thao tác một
// chiều, đã chặn ở /admin/users thì đường tự phục vụ này càng không được mở.
// Email cũng không nằm ở đây — đó là tên đăng nhập, đổi phải qua quản trị viên.
type ProfileRequest struct {
	FullName string `json:"full_name" binding:"required,max=150"`
	Phone    string `json:"phone" binding:"omitempty,max=20"`
	Avatar   string `json:"avatar" binding:"omitempty,max=255"`
}

// ChangePasswordRequest — payload người đang đăng nhập tự đổi mật khẩu.
//
// Bắt nhập mật khẩu hiện tại: khác với /admin/users/{id}/password (quản trị viên
// đặt lại hộ), đường này chạy bằng chính phiên đang mở, nên phải chứng minh người
// ngồi trước máy đúng là chủ tài khoản chứ không phải ai đó mượn màn hình.
type ChangePasswordRequest struct {
	CurrentPassword string `json:"current_password" binding:"required"`
	NewPassword     string `json:"new_password" binding:"required,min=6,max=72"`
}

// UserResponse — một tài khoản nội bộ kèm vai trò.
type UserResponse struct {
	ID       uint   `json:"id"`
	FullName string `json:"full_name"`
	Username string `json:"username"`
	Email    string `json:"email"`
	Phone    string `json:"phone"`
	Avatar   string `json:"avatar"`
	Status   string `json:"status" example:"active"`

	RoleID          uint   `json:"role_id" example:"2"`
	RoleName        string `json:"role_name" example:"admin"`
	RoleDisplayName string `json:"role_display_name" example:"Quản trị viên"`
	// Quyen — CỬA VÀO đã giao (users.access_areas). Cột rỗng thì suy từ role_id,
	// nên danh sách này LUÔN nói đúng những khu người đó mở được, kể cả với tài
	// khoản có trước migration 0015.
	Quyen []string `json:"quyen" example:"quan_ly,thu_ngan"`

	EmailVerified bool   `json:"email_verified"`
	LastLoginAt   string `json:"last_login_at"`
	CreatedAt     string `json:"created_at"`
}

// RoleResponse — một vai trò kèm số tài khoản đang mang vai trò đó.
type RoleResponse struct {
	ID          uint   `json:"id"`
	Name        string `json:"name" example:"admin"`
	DisplayName string `json:"display_name" example:"Quản trị viên"`
	Description string `json:"description"`
	// UserCount đếm cả khách hàng với vai trò customer — con số này là "bao nhiêu
	// tài khoản đang mang vai trò", không phải "bao nhiêu nhân viên".
	UserCount int64 `json:"user_count"`
	// Internal = true: vai trò dùng cho nhân viên/quản trị, gán được ở trang
	// "Người dùng & vai trò". Vai trò customer thì không.
	Internal bool `json:"internal"`
}

// RoleUpdateRequest — payload sửa vai trò.
//
// Chỉ sửa được tên hiển thị và mô tả. Mã vai trò (`name`) là thứ code so khớp để
// phân quyền nên không mở cho sửa.
type RoleUpdateRequest struct {
	DisplayName string `json:"display_name" binding:"required,max=100"`
	Description string `json:"description" binding:"omitempty,max=255"`
}

// CustomerStatusRequest — payload bật/tắt nhanh tài khoản khách hàng.
type CustomerStatusRequest struct {
	Status string `json:"status" binding:"required,oneof=active inactive"`
}

// CustomerPasswordRequest — payload cấp/đặt lại mật khẩu đăng nhập storefront.
type CustomerPasswordRequest struct {
	Password string `json:"password" binding:"required,min=6,max=72"`
}

// ---------- Order ----------

// OrderStatusRequest — payload chuyển trạng thái đơn hàng.
// Khi huỷ đơn, `note` được dùng làm lý do huỷ.
type OrderStatusRequest struct {
	Status string `json:"status" binding:"required,oneof=pending confirmed processing shipping delivered completed cancelled returned"`
	Note   string `json:"note" binding:"omitempty,max=255"`
}

// OrderPaymentRequest — payload cập nhật tình trạng thanh toán.
type OrderPaymentRequest struct {
	PaymentStatus string `json:"payment_status" binding:"required,oneof=pending paid failed refunded"`
}

// OrderNoteRequest — payload ghi chú nội bộ của admin trên đơn hàng.
type OrderNoteRequest struct {
	AdminNote string `json:"admin_note" binding:"omitempty,max=500"`
}

// OrderShippingRequest — payload cập nhật thông tin vận chuyển của đơn
// (đơn vị vận chuyển + mã vận đơn để admin theo dõi/giao hàng).
type OrderShippingRequest struct {
	ShippingMethod string `json:"shipping_method" binding:"omitempty,max=100"`
	TrackingNumber string `json:"tracking_number" binding:"omitempty,max=100"`
}

// OrderItemRequest — một dòng sản phẩm khi admin tạo đơn thủ công.
// Thông tin sản phẩm (tên/giá/sku...) do admin chọn từ danh mục gửi lên; server
// tính lại thành tiền từ đơn giá × số lượng (không tin tổng do client gửi).
type OrderItemRequest struct {
	ProductID          *uint   `json:"product_id"`
	ProductVariantID   uint    `json:"product_variant_id" binding:"required"`
	ProductName        string  `json:"product_name" binding:"required,max=255"`
	VariantSKU         string  `json:"variant_sku" binding:"omitempty,max=100"`
	Size               string  `json:"size" binding:"omitempty,max=50"`
	Color              string  `json:"color" binding:"omitempty,max=50"`
	Thumbnail          string  `json:"thumbnail" binding:"omitempty,max=500"`
	UnitPrice          float64 `json:"unit_price" binding:"gte=0"`
	Quantity           int     `json:"quantity" binding:"required,min=1"`
	CustomPlayerName   string  `json:"custom_player_name" binding:"omitempty,max=50"`
	CustomPlayerNumber string  `json:"custom_player_number" binding:"omitempty,max=10"`
}

// CheckoutItemRequest — một dòng hàng khi khách đặt từ storefront.
// Chỉ nhận định danh sản phẩm và số lượng; TÊN, GIÁ, SKU đều do server tra lại
// từ database. Không bao giờ tin giá client gửi lên.
type CheckoutItemRequest struct {
	// Ưu tiên product_variant_id nếu client biết; không thì tra theo slug + size + color.
	ProductVariantID   uint   `json:"product_variant_id"`
	Slug               string `json:"slug" binding:"omitempty,max=191"`
	Size               string `json:"size" binding:"omitempty,max=50"`
	Color              string `json:"color" binding:"omitempty,max=50"`
	Quantity           int    `json:"quantity" binding:"required,min=1,max=99"`
	CustomPlayerName   string `json:"custom_player_name" binding:"omitempty,max=50"`
	CustomPlayerNumber string `json:"custom_player_number" binding:"omitempty,max=10"`
}

// CheckoutRequest — payload khách đặt hàng từ storefront (không cần đăng nhập).
// Có gửi kèm access token thì đơn được gắn vào tài khoản, không thì là khách vãng lai.
type CheckoutRequest struct {
	RecipientName    string                `json:"recipient_name" binding:"required,max=100"`
	RecipientPhone   string                `json:"recipient_phone" binding:"required,max=20"`
	RecipientEmail   string                `json:"recipient_email" binding:"omitempty,email,max=100"`
	ShippingProvince string                `json:"shipping_province" binding:"omitempty,max=100"`
	ShippingDistrict string                `json:"shipping_district" binding:"omitempty,max=100"`
	ShippingWard     string                `json:"shipping_ward" binding:"omitempty,max=100"`
	ShippingAddress  string                `json:"shipping_address" binding:"required,max=255"`
	PaymentMethod    string                `json:"payment_method" binding:"required,oneof=cod bank_transfer payos sepay"`
	Note             string                `json:"note" binding:"omitempty,max=500"`
	Items            []CheckoutItemRequest `json:"items" binding:"required,min=1,max=50,dive"`
	// VoucherCode là mã giảm giá khách gõ tay, để trống là không dùng mã. Mức giảm
	// được API TỰ TÍNH LẠI trên giá tại thời điểm đặt — client không gửi số tiền
	// giảm lên, và có gửi cũng không được dùng.
	VoucherCode string `json:"voucher_code" binding:"omitempty,max=50" example:"SALE10"`
}

// CheckoutResponse — kết quả đặt hàng trả về cho storefront.
type CheckoutResponse struct {
	OrderID     uint    `json:"order_id"`
	OrderCode   string  `json:"order_code"`
	Subtotal    float64 `json:"subtotal_amount"`
	ShippingFee float64 `json:"shipping_fee"`
	// Discount / VoucherCode là 0 và rỗng khi khách không dùng mã.
	Discount      float64 `json:"discount_amount"`
	VoucherCode   string  `json:"voucher_code,omitempty"`
	Total         float64 `json:"total_amount"`
	PaymentMethod string  `json:"payment_method"`
	Status        string  `json:"status"`
	Message       string  `json:"message"`
	// BankTransfer chỉ có ở đơn chuyển khoản: mọi thứ khách cần để mở app ngân
	// hàng lên chuyển tiền. Tách thành trường riêng thay vì nhồi hết vào `message`
	// để client dựng được khối có nút sao chép, và `message` không thành một đoạn
	// dài nói lại đúng những gì bên dưới đã hiện.
	BankTransfer *CheckoutBankTransfer `json:"bank_transfer,omitempty"`
	// Payment chỉ có ở đơn thanh toán online (payos / sepay): mọi thứ khách cần để
	// trả tiền ngay. Vắng mặt nghĩa là không mở được cổng — đơn vẫn hợp lệ, chỉ là
	// chưa trả tiền được và `message` nói rõ phải làm gì tiếp.
	Payment *CheckoutPayment `json:"payment,omitempty"`
}

// CheckoutPayment — thông tin thanh toán online kèm theo một đơn vừa đặt.
//
// Dùng chung cho mọi cổng để client chỉ phải viết MỘT màn hình quét mã: chỗ nào
// cổng này có mà cổng kia không thì để trống, không đẻ thêm kiểu dữ liệu riêng.
type CheckoutPayment struct {
	// Provider: payos | sepay.
	Provider string `json:"provider"`
	// CheckoutURL là trang thanh toán của cổng — chỉ PayOS có. Dành cho khách dùng
	// điện thoại, không tự quét màn hình của chính mình được.
	CheckoutURL string `json:"checkout_url,omitempty"`
	// QRCode là chuỗi VietQR thô — dành cho client muốn tự vẽ mã theo kiểu của mình.
	QRCode string `json:"qr_code,omitempty"`
	// QRImage là ảnh mã QR để trang web gán thẳng vào thẻ <img>. Với PayOS đây là
	// ảnh PNG dạng data URI do API tự vẽ; với SePay là địa chỉ ảnh trên qr.sepay.vn.
	QRImage string `json:"qr_image,omitempty"`
	// TransactionCode là mã giao dịch phía cổng — cũng là mã dùng để hỏi
	// `GET /payments/{code}`. Với SePay nó chính là mã đơn (và là nội dung khách
	// phải ghi khi chuyển khoản); với PayOS nó là một con số riêng, khác mã đơn.
	TransactionCode string `json:"transaction_code"`
	// Content là nội dung chuyển khoản khách phải ghi. Luôn là mã đơn.
	Content string  `json:"content"`
	Amount  float64 `json:"amount"`
	// Ba trường tài khoản dưới đây chỉ có ở SePay — khách quét QR thì không cần,
	// nhưng ai muốn chuyển tay vẫn phải có chỗ đọc số tài khoản.
	BankName      string `json:"bank_name,omitempty"`
	AccountNumber string `json:"account_number,omitempty"`
	AccountName   string `json:"account_name,omitempty"`
	// ExpiresAt là mốc mã hết hiệu lực (RFC3339), rỗng nếu không đặt hạn — mã của
	// SePay không hết hạn vì nó chỉ là một lệnh chuyển tiền dựng sẵn.
	ExpiresAt string `json:"expires_at,omitempty"`
}

// PaymentStatusResponse — tình trạng thanh toán của một đơn, dùng cho trang khách
// quay về từ cổng và cho nút "kiểm tra lại" trên trang đó.
type PaymentStatusResponse struct {
	OrderCode string `json:"order_code"`
	// Status: pending | paid | failed | cancelled | expired
	Status string  `json:"status"`
	Paid   bool    `json:"paid"`
	Amount float64 `json:"amount"`
	// CheckoutURL còn hiệu lực thì trả lại để khách trả tiếp, rỗng nếu link đã khép.
	CheckoutURL string `json:"checkout_url,omitempty"`
	Message     string `json:"message"`
}

// CheckoutBankTransfer — hướng dẫn chuyển khoản kèm theo một đơn vừa đặt.
type CheckoutBankTransfer struct {
	BankName      string `json:"bank_name"`
	AccountNumber string `json:"account_number"`
	AccountName   string `json:"account_name"`
	// Content là nội dung khách phải ghi khi chuyển — luôn là mã đơn, vì đó là thứ
	// duy nhất đối soát được với sao kê ngân hàng.
	Content string `json:"content"`
	QRImage string `json:"qr_image,omitempty"`
	Note    string `json:"note,omitempty"`
}

// OrderCancelRequest — khách tự huỷ đơn của mình từ storefront.
// Lý do không bắt buộc; bỏ trống thì hệ thống ghi "Khách tự huỷ đơn".
type OrderCancelRequest struct {
	Reason string `json:"reason" binding:"omitempty,max=255"`
}

// CartQuoteRequest — giỏ hàng khách đang giữ ở máy, gửi lên để đối chiếu lại giá
// và tồn kho trước khi đặt. Giỏ nằm ở localStorage nên giá lưu ở đó là bản chụp
// lúc thêm hàng; admin đổi giá xong thì con số ấy đã cũ.
type CartQuoteRequest struct {
	Items []CheckoutItemRequest `json:"items" binding:"required,min=1,max=50,dive"`
}

// CartQuoteItem — kết quả đối chiếu MỘT dòng giỏ hàng theo dữ liệu hiện tại.
type CartQuoteItem struct {
	Slug             string `json:"slug"`
	Size             string `json:"size"`
	Color            string `json:"color"`
	ProductVariantID uint   `json:"product_variant_id"`
	ProductName      string `json:"product_name"`
	Thumbnail        string `json:"thumbnail"`
	// UnitPrice là ĐƠN GIÁ HIỆN TẠI trong database — chính là giá sẽ tính khi đặt.
	UnitPrice float64 `json:"unit_price"`
	// Quantity là số lượng còn đặt được (đã kẹp theo tồn kho), RequestedQuantity là
	// số khách đang để trong giỏ.
	Quantity          int     `json:"quantity"`
	RequestedQuantity int     `json:"requested_quantity"`
	LineTotal         float64 `json:"line_total"`
	Stock             int     `json:"stock"`
	// Available = false khi sản phẩm không còn bán hoặc đã hết sạch hàng.
	Available bool `json:"available"`
	// Issue mô tả ngắn thay đổi của dòng này: "" | unavailable | out_of_stock | limited
	Issue string `json:"issue"`
}

// CartQuoteResponse — số tiền server SẼ tính nếu khách đặt ngay bây giờ.
type CartQuoteResponse struct {
	Items       []CartQuoteItem `json:"items"`
	Subtotal    float64         `json:"subtotal_amount"`
	ShippingFee float64         `json:"shipping_fee"`
	Total       float64         `json:"total_amount"`
	// FreeShipThreshold để storefront hiển thị "mua thêm X để miễn phí ship" mà
	// không phải chép lại ngưỡng của server.
	FreeShipThreshold float64 `json:"free_ship_threshold"`
	// HasIssue = true khi có ít nhất một dòng hết hàng hoặc không còn bán.
	HasIssue bool `json:"has_issue"`
}

// OrderCreateRequest — payload admin tạo đơn thủ công cho một KHÁCH HÀNG CÓ SẴN.
// user_id bắt buộc và phải tồn tại; không cho tạo khách mới ở luồng này.
type OrderCreateRequest struct {
	UserID           uint               `json:"user_id" binding:"required"`
	RecipientName    string             `json:"recipient_name" binding:"required,max=100"`
	RecipientPhone   string             `json:"recipient_phone" binding:"required,max=20"`
	RecipientEmail   string             `json:"recipient_email" binding:"omitempty,email,max=100"`
	ShippingProvince string             `json:"shipping_province" binding:"omitempty,max=100"`
	ShippingDistrict string             `json:"shipping_district" binding:"omitempty,max=100"`
	ShippingWard     string             `json:"shipping_ward" binding:"omitempty,max=100"`
	ShippingAddress  string             `json:"shipping_address" binding:"required,max=255"`
	PaymentMethod    string             `json:"payment_method" binding:"required,oneof=cod vnpay momo bank_transfer payos sepay"`
	ShippingMethod   string             `json:"shipping_method" binding:"omitempty,max=100"`
	ShippingFee      float64            `json:"shipping_fee" binding:"gte=0"`
	DiscountAmount   float64            `json:"discount_amount" binding:"gte=0"`
	Note             string             `json:"note" binding:"omitempty,max=500"`
	Items            []OrderItemRequest `json:"items" binding:"required,min=1,dive"`
}

// POSCheckoutRequest — payload bán hàng TẠI QUẦY.
//
// Cố ý KHÔNG dùng lại OrderCreateRequest dù cùng là "nhân viên tạo đơn": đơn thủ
// công là đơn GIAO HÀNG đặt hộ qua điện thoại (bắt buộc có tài khoản khách, có
// địa chỉ, giá do người tạo gõ vào), còn đây là khách đứng trước mặt trả tiền và
// cầm hàng đi. Gộp hai thứ vào một payload thì mỗi trường phải mang chú thích
// "bắt buộc trong trường hợp này, bỏ qua trong trường hợp kia", và mọi ràng buộc
// đều phải nới ra tới mức lỏng nhất của hai bên.
//
// Giống luồng khách đặt trên web: mỗi dòng chỉ nói MUA GÌ, MẤY CÁI — tên, giá và
// SKU đều do server tra lại từ database rồi áp khuyến mãi đang chạy. Người bán
// không gõ giá, nên không có đường nào bán sai giá vì gõ nhầm.
type POSCheckoutRequest struct {
	// UserID gắn đơn vào một tài khoản khách CÓ SẴN — dùng khi khách quen muốn
	// tích luỹ lịch sử mua. Bỏ trống (0) là khách lẻ: quầy phải bán được cho người
	// không có tài khoản, ép tạo hồ sơ chỉ để tính tiền một lần là thứ không ai
	// làm giữa lúc có người đứng đợi.
	UserID uint `json:"user_id"`
	// Tên và số điện thoại khách lẻ, cả hai đều không bắt buộc — có thì ghi vào đơn
	// để còn gọi lại lúc đổi trả, không có thì đơn vẫn bán được.
	CustomerName  string `json:"customer_name" binding:"omitempty,max=100"`
	CustomerPhone string `json:"customer_phone" binding:"omitempty,max=20"`
	// PaymentMethod chỉ nhận hình thức mà tiền ĐÃ về trước khi khách rời quầy:
	// tiền mặt, hoặc khách tự chuyển khoản và người bán nhìn thấy báo có. Cổng
	// thanh toán online (payos/sepay) không nằm ở đây vì đơn quầy được ghi "đã
	// thanh toán" ngay lúc tạo, mà tiền qua cổng thì phải đợi cổng báo về.
	PaymentMethod string `json:"payment_method" binding:"required,oneof=cash bank_transfer"`
	// AmountTendered là số tiền mặt khách đưa. Bỏ trống nghĩa là khách đưa vừa đủ
	// (hoặc không trả bằng tiền mặt) — con trỏ chứ không phải float64 để phân biệt
	// "không khai" với "đưa 0đ". Đưa thiếu thì đơn bị từ chối, không tạo nợ.
	AmountTendered *float64 `json:"amount_tendered" binding:"omitempty,gte=0"`
	Note           string   `json:"note" binding:"omitempty,max=500"`
	// VoucherCode là mã giảm giá khách xuất trình. Mức giảm do server tự tính lại
	// trên giá tại thời điểm bán — cùng đường với luồng web.
	VoucherCode string           `json:"voucher_code" binding:"omitempty,max=50"`
	Items       []POSItemRequest `json:"items" binding:"required,min=1,max=50,dive"`
}

// POSItemRequest — một dòng hàng trên màn hình quầy.
//
// Không dùng lại CheckoutItemRequest vì hai chỗ khác nhau ở đúng hai điểm, và
// cả hai đều quan trọng:
//
//   - product_variant_id BẮT BUỘC. Storefront cho tra theo slug + size + màu vì
//     khách bấm từ trang sản phẩm và trình duyệt không phải lúc nào cũng biết id.
//     Ở quầy thì món hàng luôn đến từ ô tìm kiếm hoặc từ máy quét, cả hai đều trả
//     về id — nhận thêm đường slug chỉ là mở một lối vào mơ hồ hơn cho cùng việc.
//   - Có discount_percent. Trường này KHÔNG được phép tồn tại ở luồng web: client
//     tự khai được mức giảm thì mã giảm giá còn ý nghĩa gì nữa.
type POSItemRequest struct {
	ProductVariantID uint `json:"product_variant_id" binding:"required,min=1"`
	Quantity         int  `json:"quantity" binding:"required,min=1,max=99"`
	// DiscountPercent là mức bớt giá của riêng dòng này (0–100). Nhân viên bị chặn
	// ở mức cấu hình trong Cài đặt → Quầy bán hàng; chủ cửa hàng và quản trị viên
	// không bị chặn.
	DiscountPercent    float64 `json:"discount_percent" binding:"omitempty,gte=0,lte=100"`
	CustomPlayerName   string  `json:"custom_player_name" binding:"omitempty,max=50"`
	CustomPlayerNumber string  `json:"custom_player_number" binding:"omitempty,max=10"`
}

// POSScanResponse — kết quả quét một mã vạch ở quầy.
//
// Giá và tồn kho lấy qua ĐÚNG đường của lúc đặt hàng, nên con số máy quét đọc ra
// bằng đúng con số sẽ thu. Tồn là tồn của CHI NHÁNH đang bán, không phải bản
// cộng của cả cửa hàng.
type POSScanResponse struct {
	ProductVariantID uint   `json:"product_variant_id"`
	ProductID        uint   `json:"product_id"`
	ProductName      string `json:"product_name"`
	SKU              string `json:"sku"`
	Barcode          string `json:"barcode,omitempty"`
	Size             string `json:"size"`
	Color            string `json:"color"`
	Thumbnail        string `json:"thumbnail"`
	// Price là giá bán thật: giá riêng của biến thể (nếu có) đã trừ khuyến mãi
	// đang chạy.
	Price float64 `json:"price"`
	Stock int     `json:"stock"`
}

// POSCheckoutResponse — kết quả một lượt bán tại quầy, đủ để in hoá đơn ngay.
type POSCheckoutResponse struct {
	OrderID   uint   `json:"order_id"`
	OrderCode string `json:"order_code"`
	// Subtotal là tiền hàng theo giá server tra được (đã trừ khuyến mãi từng dòng),
	// Discount là phần mã giảm giá trừ thêm trên cả đơn.
	Subtotal float64 `json:"subtotal_amount"`
	// LineDiscount là TỔNG số tiền đã bớt trên từng món (khác Discount — phần mã
	// giảm giá trừ trên cả đơn). Tách riêng vì phiếu in phải nói rõ tiền đi đâu:
	// gộp chung thì khách nhìn một con số giảm mà không biết nó đến từ món nào.
	LineDiscount float64 `json:"line_discount_amount"`
	Discount     float64 `json:"discount_amount"`
	VoucherCode  string  `json:"voucher_code,omitempty"`
	Total        float64 `json:"total_amount"`
	// AmountTendered / ChangeAmount vắng mặt khi không thu tiền mặt. Số tiền thối
	// là thứ người bán cần đọc to lên ngay, nên nó do server tính chứ không để mỗi
	// màn hình tự trừ theo cách của mình.
	AmountTendered *float64 `json:"amount_tendered,omitempty"`
	ChangeAmount   *float64 `json:"change_amount,omitempty"`
	PaymentMethod  string   `json:"payment_method"`
	Status         string   `json:"status"`
	PaymentStatus  string   `json:"payment_status"`
	Message        string   `json:"message"`
}

// OrderUpdateRequest — payload admin sửa một đơn hàng CÓ SẴN. Không cho đổi khách
// hàng (user_id), mã đơn, trạng thái hay tình trạng thanh toán ở luồng này; chỉ sửa
// thông tin người nhận, giao hàng, giảm giá và danh sách sản phẩm. Server tính lại
// tiền hàng & tổng tiền từ đơn giá × số lượng. Chỉ sửa được khi đơn còn ở giai đoạn
// đầu (chờ xác nhận / đã xác nhận / đang chuẩn bị).
type OrderUpdateRequest struct {
	RecipientName    string             `json:"recipient_name" binding:"required,max=100"`
	RecipientPhone   string             `json:"recipient_phone" binding:"required,max=20"`
	RecipientEmail   string             `json:"recipient_email" binding:"omitempty,email,max=100"`
	ShippingProvince string             `json:"shipping_province" binding:"omitempty,max=100"`
	ShippingDistrict string             `json:"shipping_district" binding:"omitempty,max=100"`
	ShippingWard     string             `json:"shipping_ward" binding:"omitempty,max=100"`
	ShippingAddress  string             `json:"shipping_address" binding:"required,max=255"`
	PaymentMethod    string             `json:"payment_method" binding:"required,oneof=cod vnpay momo bank_transfer payos sepay"`
	ShippingMethod   string             `json:"shipping_method" binding:"omitempty,max=100"`
	ShippingFee      float64            `json:"shipping_fee" binding:"gte=0"`
	DiscountAmount   float64            `json:"discount_amount" binding:"gte=0"`
	Note             string             `json:"note" binding:"omitempty,max=500"`
	Items            []OrderItemRequest `json:"items" binding:"required,min=1,dive"`
}

// ---------- Order return (trả hàng) ----------

// ReturnItemRequest — một dòng khách/admin chọn trả: dòng hàng nào trong đơn,
// trả mấy cái. Giá và tên sản phẩm do server chụp lại từ đơn gốc, không nhận từ
// client — nếu không thì khách tự khai giá để được hoàn nhiều hơn đã trả.
type ReturnItemRequest struct {
	OrderItemID uint `json:"order_item_id" binding:"required"`
	Quantity    int  `json:"quantity" binding:"required,min=1"`
}

// ReturnCreateRequest — khách gửi yêu cầu trả hàng từ storefront.
type ReturnCreateRequest struct {
	OrderID uint `json:"order_id" binding:"required"`
	// Reason là mã lý do cố định để thống kê; reason_note là mô tả tự do.
	Reason       string              `json:"reason" binding:"required,oneof=defective wrong_item wrong_size not_as_described changed_mind other"`
	ReasonNote   string              `json:"reason_note" binding:"omitempty,max=500"`
	RefundMethod string              `json:"refund_method" binding:"omitempty,oneof=none cash bank_transfer ewallet"`
	BankAccount  string              `json:"bank_account" binding:"omitempty,max=50"`
	BankHolder   string              `json:"bank_holder" binding:"omitempty,max=150"`
	BankName     string              `json:"bank_name" binding:"omitempty,max=150"`
	Items        []ReturnItemRequest `json:"items" binding:"required,min=1,max=50,dive"`
}

// ReturnAdminCreateRequest — nhân viên lập phiếu trả tại quầy.
// Khác luồng khách: phiếu vào thẳng trạng thái "đã duyệt" (nhân viên đang cầm
// hàng trên tay, không có gì để duyệt nữa) và được đặt trước số tiền hoàn.
type ReturnAdminCreateRequest struct {
	OrderID      uint   `json:"order_id" binding:"required"`
	Reason       string `json:"reason" binding:"required,oneof=defective wrong_item wrong_size not_as_described changed_mind other"`
	ReasonNote   string `json:"reason_note" binding:"omitempty,max=500"`
	RefundMethod string `json:"refund_method" binding:"omitempty,oneof=none cash bank_transfer ewallet"`
	BankAccount  string `json:"bank_account" binding:"omitempty,max=50"`
	BankHolder   string `json:"bank_holder" binding:"omitempty,max=150"`
	BankName     string `json:"bank_name" binding:"omitempty,max=150"`
	// ShippingFee là phí ship hoàn lại cho khách, Deduction là phần khấu trừ
	// (hàng đã dùng, thiếu phụ kiện, phí xử lý). Tiền hàng server tự tính.
	ShippingFee float64 `json:"shipping_fee" binding:"gte=0"`
	Deduction   float64 `json:"deduction" binding:"gte=0"`
	// Restock = false khi hàng nhận về bị lỗi, không đưa lại lên kệ được.
	Restock   *bool               `json:"restock"`
	AdminNote string              `json:"admin_note" binding:"omitempty,max=500"`
	Items     []ReturnItemRequest `json:"items" binding:"required,min=1,max=50,dive"`
}

// ReturnStatusRequest — payload chuyển trạng thái phiếu trả hàng.
// Khi từ chối, `note` là lý do từ chối và bắt buộc phải có.
type ReturnStatusRequest struct {
	Status string `json:"status" binding:"required,oneof=approved received refunded rejected cancelled"`
	Note   string `json:"note" binding:"omitempty,max=255"`
}

// ReturnSettleRequest — chốt số tiền hoàn trước/khi hoàn tiền cho khách.
// Tiền hàng của các món trả là con số server tính từ đơn gốc và không sửa được;
// nhân viên chỉ điều chỉnh phí ship hoàn lại và phần khấu trừ.
type ReturnSettleRequest struct {
	ShippingFee  float64 `json:"shipping_fee" binding:"gte=0"`
	Deduction    float64 `json:"deduction" binding:"gte=0"`
	RefundMethod string  `json:"refund_method" binding:"omitempty,oneof=none cash bank_transfer ewallet"`
	BankAccount  string  `json:"bank_account" binding:"omitempty,max=50"`
	BankHolder   string  `json:"bank_holder" binding:"omitempty,max=150"`
	BankName     string  `json:"bank_name" binding:"omitempty,max=150"`
	// Restock quyết định hàng có được nhập lại kho khi nhận về hay không. Chỉ còn
	// đổi được trước khi phiếu chuyển sang "đã nhận hàng".
	Restock *bool `json:"restock"`
}

// ReturnNoteRequest — ghi chú nội bộ của admin trên phiếu trả hàng.
type ReturnNoteRequest struct {
	AdminNote string `json:"admin_note" binding:"omitempty,max=500"`
}

// ReturnCancelRequest — khách tự rút yêu cầu trả hàng.
type ReturnCancelRequest struct {
	Reason string `json:"reason" binding:"omitempty,max=255"`
}

// CustomerResponse — khách hàng kèm tài khoản đăng nhập, địa chỉ mặc định & số liệu mua hàng.
type CustomerResponse struct {
	ID          uint    `json:"id"`
	FullName    string  `json:"full_name"`
	Email       string  `json:"email"`
	Phone       string  `json:"phone"`
	Avatar      string  `json:"avatar"`
	Gender      string  `json:"gender"`
	DateOfBirth string  `json:"date_of_birth" example:"1998-08-23"`
	Address     string  `json:"address"`
	Status      string  `json:"status" example:"active"`
	TotalOrders int64   `json:"total_orders"`
	TotalSpent  float64 `json:"total_spent"`
	LastOrderAt string  `json:"last_order_at"`

	// ----- Tài khoản đăng nhập storefront -----
	// LoginEmail là tên đăng nhập (chính là email); rỗng nghĩa là chưa thể đăng nhập.
	LoginEmail    string `json:"login_email"`
	EmailVerified bool   `json:"email_verified"`
	LastLoginAt   string `json:"last_login_at"`
	CreatedAt     string `json:"created_at"`
}

// ---------- Đặt hàng nhập ----------

// SupplierRequest — payload tạo/sửa nhà cung cấp.
// Code bỏ trống thì server tự sinh mã kế tiếp dạng NCC001.
type SupplierRequest struct {
	Code        string `json:"code" binding:"omitempty,max=30"`
	Name        string `json:"name" binding:"required,max=150"`
	ContactName string `json:"contact_name" binding:"omitempty,max=150"`
	Phone       string `json:"phone" binding:"omitempty,max=20"`
	Email       string `json:"email" binding:"omitempty,email,max=191"`
	Address     string `json:"address" binding:"omitempty,max=255"`
	TaxCode     string `json:"tax_code" binding:"omitempty,max=30"`
	Note        string `json:"note" binding:"omitempty,max=500"`
	// IsActive bỏ trống = true (nhà cung cấp mới mặc định đang hợp tác).
	IsActive *bool `json:"is_active"`
}

// PurchaseReturnItemRequest — một dòng hàng trả lại nhà cung cấp.
//
// Khoá theo DÒNG CỦA PHIẾU ĐẶT (không phải biến thể): số còn trả được tính trên
// từng dòng phiếu đặt, và giá nhập cũng lấy theo dòng đó nên phiếu trả ghi đúng
// số tiền đã mua món hàng ấy.
type PurchaseReturnItemRequest struct {
	PurchaseOrderItemID uint `json:"purchase_order_item_id" binding:"required,min=1"`
	Quantity            int  `json:"quantity" binding:"required,min=1"`
}

// PurchaseReturnRequest — payload lập/sửa phiếu trả hàng nhập.
//
// Tên/SKU/size/màu/giá nhập KHÔNG nhận từ client: server chụp lại từ dòng phiếu
// đặt gốc, nếu không phiếu trả và sổ kho lại nói về hai món khác nhau.
type PurchaseReturnRequest struct {
	PurchaseOrderID uint `json:"purchase_order_id" binding:"required,min=1"`
	// Status chỉ nhận "draft" (lưu nháp) hoặc "returned" (trả luôn); rỗng = nháp.
	Status string                      `json:"status" binding:"omitempty,oneof=draft returned"`
	Reason string                      `json:"reason" binding:"omitempty,oneof=defect wrong_item over_stock expired other"`
	Note   string                      `json:"note" binding:"omitempty,max=500"`
	Items  []PurchaseReturnItemRequest `json:"items" binding:"required,min=1,dive"`
}

// PurchaseReturnStatusRequest — chuyển trạng thái phiếu trả. Huỷ phiếu BẮT BUỘC
// có `note` (lý do huỷ) vì phiếu huỷ vẫn nằm trong sổ.
type PurchaseReturnStatusRequest struct {
	Status string `json:"status" binding:"required,oneof=returned refunded cancelled"`
	Note   string `json:"note" binding:"omitempty,max=255"`
}

// PurchaseReturnRefundRequest — ghi nhận tiền nhà cung cấp đã hoàn.
// RefundAmount là TỔNG đã hoàn (luỹ kế), không phải số vừa hoàn thêm.
type PurchaseReturnRefundRequest struct {
	RefundAmount float64 `json:"refund_amount" binding:"min=0"`
}

// PurchaseItemRequest — một dòng hàng của phiếu đặt: đặt biến thể nào, mấy cái,
// giá nhập bao nhiêu. Tên/SKU/ảnh do server chụp lại từ biến thể, không nhận từ
// client — nếu không thì phiếu ghi một đằng, kho cộng một nẻo.
type PurchaseItemRequest struct {
	VariantID uint    `json:"variant_id" binding:"required"`
	Quantity  int     `json:"quantity" binding:"required,min=1"`
	UnitCost  float64 `json:"unit_cost" binding:"gte=0"`
}

// PurchaseCreateRequest — lập phiếu đặt hàng nhập.
//
// Status quyết định phiếu ra đời ở đâu: "draft" để soạn tiếp, "ordered" khi đã
// chốt với nhà cung cấp. Bỏ trống = draft.
type PurchaseCreateRequest struct {
	SupplierID uint   `json:"supplier_id" binding:"required"`
	Status     string `json:"status" binding:"omitempty,oneof=draft ordered"`
	// ExpectedDate là ngày hẹn giao, dạng YYYY-MM-DD.
	ExpectedDate string `json:"expected_date" binding:"omitempty,datetime=2006-01-02" example:"2026-08-15"`
	// DiscountAmount là chiết khấu nhà cung cấp cho, ShippingFee là cước phải trả.
	// Tiền hàng server tự tính từ đơn giá × số lượng.
	DiscountAmount float64               `json:"discount_amount" binding:"gte=0"`
	ShippingFee    float64               `json:"shipping_fee" binding:"gte=0"`
	Note           string                `json:"note" binding:"omitempty,max=500"`
	Items          []PurchaseItemRequest `json:"items" binding:"required,min=1,max=200,dive"`
}

// PurchaseUpdateRequest — sửa phiếu đặt hàng (chỉ khi chưa nhận đợt hàng nào).
// Không đổi được trạng thái ở đây: dùng endpoint chuyển trạng thái riêng.
type PurchaseUpdateRequest struct {
	SupplierID     uint                  `json:"supplier_id" binding:"required"`
	ExpectedDate   string                `json:"expected_date" binding:"omitempty,datetime=2006-01-02" example:"2026-08-15"`
	DiscountAmount float64               `json:"discount_amount" binding:"gte=0"`
	ShippingFee    float64               `json:"shipping_fee" binding:"gte=0"`
	Note           string                `json:"note" binding:"omitempty,max=500"`
	Items          []PurchaseItemRequest `json:"items" binding:"required,min=1,max=200,dive"`
}

// PurchaseStatusRequest — chuyển trạng thái phiếu đặt hàng.
//
// Chỉ có hai đích: gửi phiếu cho nhà cung cấp ("ordered") và huỷ phiếu
// ("cancelled"). KHÔNG có "received" ở đây — đánh dấu đã nhận mà không đi qua
// luồng nhận hàng là ghi phiếu xong mà kho không có hàng. Muốn đóng phiếu thì
// nhận nốt số còn lại qua endpoint nhận hàng.
//
// Khi huỷ, `note` là lý do huỷ và bắt buộc phải có.
type PurchaseStatusRequest struct {
	Status string `json:"status" binding:"required,oneof=ordered cancelled"`
	Note   string `json:"note" binding:"omitempty,max=255"`
}

// PurchaseReceiveItem — một dòng trong đợt nhận hàng.
// Quantity = 0 nghĩa là đợt này chưa nhận dòng đó (form gửi lên mọi dòng).
type PurchaseReceiveItem struct {
	ItemID   uint `json:"item_id" binding:"required"`
	Quantity int  `json:"quantity" binding:"min=0"`
}

// PurchaseReceiveRequest — ghi nhận MỘT đợt hàng về kho.
//
// Nhận nhiều đợt được: mỗi đợt cộng thêm vào số đã nhận của từng dòng, và chỉ
// khi mọi dòng đủ số thì phiếu mới chuyển sang "đã nhận đủ".
type PurchaseReceiveRequest struct {
	Items []PurchaseReceiveItem `json:"items" binding:"required,min=1,max=200,dive"`
	// UpdateCost bỏ trống = true: ghi giá nhập của đợt này thành giá vốn của biến
	// thể, để trang tồn kho tính giá trị kho theo giá vừa mua.
	UpdateCost *bool  `json:"update_cost"`
	Note       string `json:"note" binding:"omitempty,max=255"`
}

// PurchasePaymentRequest — ghi nhận số tiền ĐÃ TRẢ nhà cung cấp (số luỹ kế, không
// phải số cộng thêm). Tình trạng thanh toán do server suy ra từ con số này.
type PurchasePaymentRequest struct {
	PaidAmount float64 `json:"paid_amount" binding:"gte=0"`
}

// ---------- Tồn kho ----------

// InventoryAdjustRequest — chỉnh tồn kho của MỘT biến thể.
//
// Mode quyết định cách hiểu Quantity:
//   - "set":   đặt tồn kho về đúng con số này (kiểm kê, Quantity >= 0)
//   - "delta": cộng thêm Quantity vào tồn hiện tại, số âm là xuất bớt
type InventoryAdjustRequest struct {
	Mode     string `json:"mode" binding:"required,oneof=set delta" example:"set"`
	Quantity int    `json:"quantity" example:"50"`
	// Type là loại bút toán ghi sổ kho. Bỏ trống thì server tự suy: "set" ghi
	// "adjustment", "delta" ghi "import" khi cộng và "export" khi trừ.
	Type     string   `json:"type" binding:"omitempty,oneof=import export adjustment" example:"adjustment"`
	UnitCost *float64 `json:"unit_cost" binding:"omitempty,gte=0"`
	Note     string   `json:"note" binding:"omitempty,max=255"`
}

// InventoryBulkAdjustItem — một dòng trong yêu cầu chỉnh kho hàng loạt.
type InventoryBulkAdjustItem struct {
	VariantID uint   `json:"variant_id" binding:"required"`
	Mode      string `json:"mode" binding:"required,oneof=set delta"`
	Quantity  int    `json:"quantity"`
}

// InventoryCostItem — khai giá vốn cho một biến thể.
//
// CostPrice bỏ trống (null) = XOÁ giá vốn riêng của biến thể, quay về lấy theo
// sản phẩm cha. Khác hẳn với khai giá vốn bằng 0.
type InventoryCostItem struct {
	VariantID uint     `json:"variant_id" binding:"required"`
	CostPrice *float64 `json:"cost_price" binding:"omitempty,gte=0"`
}

// InventoryCostRequest — khai giá vốn hàng loạt (nhập từ file).
// Tất-cả-hoặc-không: một biến thể không tồn tại thì không dòng nào được ghi.
type InventoryCostRequest struct {
	Items []InventoryCostItem `json:"items" binding:"required,min=1,max=200,dive"`
}

// InventoryBulkAdjustRequest — chỉnh kho nhiều biến thể trong một lần kiểm kê.
// Tất-cả-hoặc-không: một dòng sai thì không dòng nào được ghi.
type InventoryBulkAdjustRequest struct {
	Items    []InventoryBulkAdjustItem `json:"items" binding:"required,min=1,max=200,dive"`
	Type     string                    `json:"type" binding:"omitempty,oneof=import export adjustment"`
	UnitCost *float64                  `json:"unit_cost" binding:"omitempty,gte=0"`
	Note     string                    `json:"note" binding:"omitempty,max=255"`
}

// ---------- Cấu hình hệ thống ----------

// SettingField mô tả MỘT khoá cấu hình theo registry của service: nhóm, kiểu dữ
// liệu, giá trị mặc định, có công khai cho storefront hay không.
//
// Giao diện admin dựng form từ danh sách này (nhóm nào có khoá nào, ô nhập kiểu
// gì) nên không phải hard-code lại bảng khoá ở tầng Blade.
type SettingField struct {
	Key   string `json:"key" example:"default_shipping_fee"`
	Group string `json:"group" example:"shipping"`
	// Section chia một trang cấu hình thành các khối (nhận diện / liên hệ / mạng
	// xã hội). Rỗng = trang đó không chia khối.
	Section string `json:"section" example:"contact"`
	// Type: text | number | email | phone | image | url
	Type     string `json:"type" example:"number"`
	Label    string `json:"label" example:"Phí vận chuyển mặc định"`
	Default  string `json:"default" example:"30000"`
	Required bool   `json:"required" example:"true"`
	// Public = true: khoá này lộ ra ở GET /settings cho storefront đọc.
	Public bool `json:"public" example:"true"`
	// MaxLen / MaxNum là giới hạn server sẽ áp khi lưu (0 = không chặn). Trả ra đây
	// để form admin đặt maxlength/max cho ô nhập, chặn ngay trên giao diện thay vì
	// để người dùng bấm Lưu rồi mới nhận lỗi.
	MaxLen int     `json:"max_len" example:"0"`
	MaxNum float64 `json:"max_num" example:"1000000"`
}

// SettingsResponse — kết quả GET/PUT cấu hình phía admin.
//
// Values là map phẳng key -> value để form đọc trực tiếp; Fields là siêu dữ liệu
// của đúng những khoá có trong Values. Khoá chưa có dòng trong database vẫn xuất
// hiện, mang giá trị mặc định của registry.
type SettingsResponse struct {
	Values map[string]string `json:"values"`
	Fields []SettingField    `json:"fields"`
}

// SettingsUpdateRequest — ghi nhiều khoá trong một lần gọi.
//
// Chỉ những khoá gửi lên bị thay đổi, khoá không gửi giữ nguyên. Khoá lạ (không
// có trong registry) làm cả yêu cầu bị từ chối với mã 422 chứ không âm thầm ghi
// vào database.
type SettingsUpdateRequest struct {
	Items map[string]string `json:"items" binding:"required,min=1,max=50"`
}

// ---------- Khu điều hành nền tảng: bảng giá & tính năng gói ----------

// PlanFeatureField mô tả MỘT khoá tính năng theo registry của service: kiểu dữ
// liệu, nhãn, đơn vị, trần được phép.
//
// Màn hình Tính năng gói dựng ô nhập từ danh sách này, nên bảng khoá không bị
// chép lại lần thứ hai ở tầng Blade — thêm một hạn mức mới là sửa registry, màn
// hình tự có thêm ô.
type PlanFeatureField struct {
	Key string `json:"key" example:"max_users"`
	// Type: so | co_khong
	Type  string `json:"type" example:"so"`
	Label string `json:"label" example:"Số tài khoản"`
	// DonVi in sau ô nhập ("tài khoản", "sản phẩm"). Rỗng với khoá bật/tắt.
	DonVi string `json:"don_vi" example:"tài khoản"`
	// KhongCoDong là điều người đọc thấy khi gói KHÔNG có dòng cho khoá này:
	// "0" với khoá bật/tắt (mặc định là tắt), rỗng với hạn mức (bảng giá không
	// quy định, chốt lúc ký hợp đồng).
	KhongCoDong string `json:"khong_co_dong" example:""`
	// ChoVoHan = true: khoá này nhận giá trị "vo_han" ngoài các con số.
	ChoVoHan bool `json:"cho_vo_han" example:"true"`
	// MaxNum là trần server áp khi lưu (0 = không chặn). Trả ra để màn hình đặt
	// max cho ô nhập, chặn ngay trên giao diện thay vì để bấm Lưu rồi mới báo lỗi.
	MaxNum float64 `json:"max_num" example:"1000"`
}

// PlanItem là MỘT dòng bảng giá kèm điều khoản của nó.
//
// Features CHỈ chứa những khoá thật sự có dòng dưới database. Khoá vắng mặt
// mang nghĩa riêng — bảng giá không quy định — nên không được điền hộ giá trị
// mặc định vào đây; xem PlanFeatureField.KhongCoDong.
type PlanItem struct {
	ID      uint   `json:"id" example:"3"`
	AppCode string `json:"app_code" example:"order"`
	AppName string `json:"app_name" example:"Sellio Order"`
	Code    string `json:"code" example:"chuoi"`
	Name    string `json:"name" example:"Chuỗi"`
	Tagline string `json:"tagline" example:"Từ hai cửa hàng trở lên"`
	// BillingCycle: thang | nam
	BillingCycle string `json:"billing_cycle" example:"thang"`
	// Price nil = "Liên hệ" — chưa có giá công khai, KHÁC 0 (miễn phí).
	Price     *float64 `json:"price" example:"499000"`
	TrialDays uint     `json:"trial_days" example:"14"`
	// Status: active | retired
	Status   string            `json:"status" example:"active"`
	Features map[string]string `json:"features"`
}

// PlansResponse — kết quả GET bảng giá của khu điều hành.
type PlansResponse struct {
	Plans  []PlanItem         `json:"plans"`
	Fields []PlanFeatureField `json:"fields"`
}

// PlanFeaturesResponse — kết quả GET/PUT tính năng của MỘT gói.
type PlanFeaturesResponse struct {
	Plan   PlanItem           `json:"plan"`
	Fields []PlanFeatureField `json:"fields"`
}

// PlanFeaturesUpdateRequest — ghi nhiều khoá của một gói trong một lần gọi.
//
// Khoá không gửi lên giữ nguyên. Gửi GIÁ TRỊ RỖNG là XOÁ dòng đó, nghĩa là
// "bảng giá không quy định hạn mức này" — khác hẳn gửi "0". Khoá lạ (không có
// trong registry) làm cả yêu cầu bị từ chối 422 chứ không âm thầm ghi xuống.
type PlanFeaturesUpdateRequest struct {
	Items map[string]string `json:"items" binding:"required,min=1,max=30"`
}

// SuaGoiRequest — sửa phần THƯƠNG MẠI của một dòng bảng giá.
//
// KHÔNG có mã gói, app và chu kỳ: bộ ba đó là danh tính của dòng và hợp đồng đã
// ký tra tên gói về đây theo mã (xem domain.SuaPlan). Muốn một mức giá khác thì
// thêm dòng mới chứ không sửa mã dòng cũ.
type SuaGoiRequest struct {
	Name    string `json:"name" binding:"required,max=100" example:"Cửa hàng"`
	Tagline string `json:"tagline" binding:"max=255" example:"Cho một cửa hàng bán đều tay"`
	// Price NULL = "Liên hệ" (chưa công bố giá), KHÁC 0 là miễn phí — nên nó là
	// con trỏ, và màn hình phải gửi `null` chứ không phải chuỗi rỗng khi muốn
	// "Liên hệ".
	//
	// Trần 1 tỷ: cột dưới database là DECIMAL(12,2) nên còn xa mới tràn, nhưng
	// đây là GIÁ BÁN — gõ thừa một chữ số là một con số không ai định bán hiện
	// lên landing, và không có gì phía sau chặn lại.
	Price     *float64 `json:"price" binding:"omitempty,min=0,max=1000000000" example:"499000"`
	TrialDays uint     `json:"trial_days" binding:"max=365" example:"14"`
	// Status: active | retired. retired là NGỪNG BÁN MỚI, không xoá dòng — khách
	// đang dùng gói đó không bị ảnh hưởng.
	Status string `json:"status" binding:"required,oneof=active retired" example:"active"`
}

// AppItem là một phần mềm trong danh mục của nền tảng.
//
// `so_goi_dang_ban` tách khỏi `status` vì hai thứ độc lập: app 'active' mà 0
// gói đang bán thì vẫn không ai mua được, và màn hình phải nói ra điều đó thay
// vì để người đọc suy từ trạng thái.
type AppItem struct {
	ID      uint   `json:"id" example:"1"`
	Code    string `json:"code" example:"order"`
	Name    string `json:"name" example:"Sellio Order"`
	Tagline string `json:"tagline" example:"Quản trị bán hàng cho cửa hàng"`
	// Status: planned | active | retired
	Status       string `json:"status" example:"active"`
	SoGoiDangBan int    `json:"so_goi_dang_ban" example:"3"`
}

// AppsResponse — danh mục phần mềm mà NGƯỜI GỌI được phụ trách.
//
// KHÔNG phải toàn bộ danh mục: operator/support chỉ thấy phần mềm được giao
// (xem migration 0010). Bộ chọn phần mềm ở đầu khu điều hành dựng từ danh sách
// này, nên nó cũng chính là thứ quyết định người ta thấy những màn hình nào.
type AppsResponse struct {
	Apps []AppItem `json:"apps"`
}

// ---------- Khu điều hành: khách hàng · hợp đồng · doanh thu ----------

// KhachHangItem là một khách hàng của MỘT phần mềm.
//
// `so_hop_dong` đếm hợp đồng còn hiệu lực trong phạm vi đang lọc — nó phân biệt
// khách đang dùng với khách đã dừng hẳn, thứ mà `trang_thai` (mở/khoá cửa hàng)
// không nói được.
type KhachHangItem struct {
	ID  uint   `json:"id" example:"7"`
	Ma  string `json:"ma" example:"quochuy"`
	Ten string `json:"ten" example:"Quốc Huy"`
	// TrangThai: active | suspended — trạng thái CỬA HÀNG, không phải hợp đồng.
	TrangThai   string    `json:"trang_thai" example:"active"`
	NguoiLienHe string    `json:"nguoi_lien_he"`
	DienThoai   string    `json:"dien_thoai"`
	Email       string    `json:"email"`
	SoHopDong   int       `json:"so_hop_dong" example:"1"`
	NgayVaoSo   time.Time `json:"ngay_vao_so"`
}

// KhachHangResponse — GET /platform/tenants.
type KhachHangResponse struct {
	KhachHang []KhachHangItem `json:"khach_hang"`
}

// HopDongItem là một thuê bao đã ký.
//
// Ba hạn mức và cờ tên miền lấy THẲNG từ hợp đồng, không tra sang bảng giá: bảng
// giá được phép đổi, hợp đồng thì không. Giá trị 0 ở ba hạn mức nghĩa là KHÔNG
// GIỚI HẠN — màn hình phải in ra chữ, không in số 0.
type HopDongItem struct {
	ID         uint   `json:"id" example:"12"`
	MaCuaHang  string `json:"ma_cua_hang" example:"quochuy"`
	TenCuaHang string `json:"ten_cua_hang" example:"Quốc Huy"`
	MaApp      string `json:"ma_app" example:"order"`
	TenApp     string `json:"ten_app" example:"Sellio Order"`
	// Goi là MÃ gói đã chốt trong hợp đồng — thứ không đổi.
	Goi string `json:"goi" example:"chuoi"`
	// TenGoi là tên hiển thị tra từ bảng giá ("Chuỗi cửa hàng"). Rơi về `Goi` khi
	// hợp đồng không sinh từ dòng bảng giá nào, nên nơi hiển thị KHÔNG bao giờ
	// nhận chuỗi rỗng và không phải tự nghĩ ra chỗ dự phòng.
	TenGoi string `json:"ten_goi" example:"Chuỗi cửa hàng"`
	// TrangThai: trial | active | past_due | canceled
	TrangThai string `json:"trang_thai" example:"active"`
	// ChuKy: thang | nam
	ChuKy        string    `json:"chu_ky" example:"thang"`
	Gia          float64   `json:"gia" example:"9990000"`
	ChiNhanh     uint      `json:"chi_nhanh" example:"5"`
	TaiKhoan     uint      `json:"tai_khoan" example:"0"`
	SanPham      uint      `json:"san_pham" example:"0"`
	TenMienRieng bool      `json:"ten_mien_rieng" example:"true"`
	BatDau       time.Time `json:"bat_dau"`
	HetHan       time.Time `json:"het_han"`
	// ConLaiNgay âm = ĐÃ QUÁ HẠN.
	ConLaiNgay int `json:"con_lai_ngay" example:"41"`
	// Ba ô liên lạc lấy từ SỔ KHÁCH HÀNG (tenants của control plane), không phải
	// từ hợp đồng: người gọi khách lúc sắp hết hạn cần số điện thoại ngay trên
	// dòng đó, chứ không phải mở thêm màn hình khác để tra. Rỗng = chưa ai điền.
	NguoiLienHe string `json:"nguoi_lien_he" example:"Anh Huy"`
	DienThoai   string `json:"dien_thoai" example:"0901234567"`
	Email       string `json:"email" example:"huy@quochuy.vn"`
	// DaThuDen là ngày CUỐI của kỳ đã trả tiền xa nhất trong sổ thu của hợp đồng
	// này. nil = chưa thu lần nào.
	//
	// KHÁC hẳn `lan_cuoi` của /platform/doanh-thu: bên đó là NGÀY TIỀN VÀO và
	// gộp theo cửa hàng, ở đây là KỲ ĐƯỢC TRẢ CHO của đúng hợp đồng này. Khách
	// trả trước ba tháng thì hai con số ấy cách nhau ba tháng.
	DaThuDen *time.Time `json:"da_thu_den"`
	// ConKyDeThu = hợp đồng này còn nhận thêm được một lần thu không.
	//
	// MÁY CHỦ QUYẾT, không phải giao diện tự suy — cùng lý do như SuaDuocHan:
	// luật nằm ở DungThuService.ThuTien (đã huỷ thì từ chối; đã trả tới đúng hạn
	// hiện tại thì hết kỳ để thu, ErrKhongConKyDeThu), và chép nó lên Blade là
	// để hai bên lệch nhau ngay lần sửa luật đầu tiên.
	//
	// false = phải GIẤU nút Thu tiền đi. Hiện ra rồi báo lỗi lúc bấm là bắt người
	// thu tiền tự đoán vì sao — mà câu trả lời ("khách trả đủ tới hạn rồi") thì
	// đằng nào cũng phải nói.
	ConKyDeThu bool `json:"con_ky_de_thu" example:"true"`
}

// HopDongResponse — GET /platform/subscriptions.
type HopDongResponse struct {
	HopDong []HopDongItem `json:"hop_dong"`
}

// HopDongChiTiet — GET /platform/subscriptions/{id}.
//
// Bọc HopDongItem rồi thêm những ô chỉ màn hình chi tiết cần. Nhúng thay vì khai
// lại từ đầu: danh sách và chi tiết phải nói CÙNG một con số cho cùng một cột,
// và cách chắc chắn nhất là chúng dùng chung một kiểu.
type HopDongChiTiet struct {
	HopDongItem
	// BatDauDungThu / HetDungThu là hai mốc của KỲ DÙNG THỬ. HetDungThu rỗng khi
	// hợp đồng không có giai đoạn thử, hoặc đã chuyển sang chính thức — lúc gia
	// hạn, mốc này bị xoá.
	HetDungThu *time.Time `json:"het_dung_thu"`
	// GhiChuHopDong là điều khoản riêng đã thoả thuận, cộng lịch sử huỷ nếu có.
	GhiChuHopDong string `json:"ghi_chu_hop_dong" example:"khách anh Sơn giới thiệu"`
	// GhiChuKhach là ghi chú về KHÁCH trong sổ khách hàng — khác ghi chú hợp đồng:
	// một cái đi theo khách qua nhiều hợp đồng, một cái chết cùng hợp đồng.
	GhiChuKhach string `json:"ghi_chu_khach" example:"khách quen, trả đúng hạn"`
	// NgayVaoSo là ngày khách được ghi vào sổ nền tảng, không phải ngày ký hợp
	// đồng này — khách cũ ký hợp đồng mới thì hai ngày đó cách nhau rất xa.
	NgayVaoSo time.Time `json:"ngay_vao_so"`
	// TrangThaiCuaHang: active | suspended. KHÁC trạng thái hợp đồng, và hai thứ
	// đó rời nhau — cửa hàng vẫn mở mà hợp đồng đã hết hạn là chuyện có thật.
	TrangThaiCuaHang string `json:"trang_thai_cua_hang" example:"active"`
	// SuaDuocHan cho màn hình biết có được hiện ô đổi ngày hết hạn không. Máy chủ
	// quyết, không phải giao diện tự suy: luật "chỉ hợp đồng dùng thử mới đổi hạn
	// trực tiếp" nằm ở service, và chép nó lên Blade là để hai bên lệch nhau.
	SuaDuocHan bool `json:"sua_duoc_han" example:"true"`
	// QuanTri là TÀI KHOẢN ĐĂNG NHẬP của khách — ô thứ hai của màn hình đăng nhập
	// 3 ô. Đọc từ DATA PLANE, nên nó vắng mặt (nil) khi cửa hàng không còn tài
	// khoản quản trị nào, hoặc khi lượt đọc bên đó hỏng.
	//
	// nil KHÔNG được hiểu là "cửa hàng hỏng": danh sách và điều khoản vẫn đúng,
	// chỉ là chưa biết ai đang quản trị. Màn hình phải nói ra điều đó thay vì
	// giấu đi cả khối.
	QuanTri *QuanTriItem `json:"quan_tri"`
}

// QuanTriItem là tài khoản quản trị của một cửa hàng. KHÔNG có mật khẩu, kể cả
// bản băm — đường duy nhất chạm tới mật khẩu khách là đặt lại, và nó chỉ ghi.
type QuanTriItem struct {
	TenDangNhap string `json:"ten_dang_nhap" example:"admin"`
	HoTen       string `json:"ho_ten" example:"Nguyễn Quốc Huy"`
	Email       string `json:"email" example:"admin@quochuy.local"`
	// TrangThai: active | inactive. In ra để người sắp đặt lại mật khẩu thấy tài
	// khoản đang bị khoá — đặt lại mật khẩu KHÔNG mở khoá hộ.
	TrangThai string `json:"trang_thai" example:"active"`
}

// CuaHangCoSanItem là một cửa hàng CHƯA có hợp đồng cho phần mềm đang xét —
// ứng viên để ký mới.
type CuaHangCoSanItem struct {
	Ma  string `json:"ma" example:"quochuy"`
	Ten string `json:"ten" example:"Cửa hàng Quốc Huy"`
	// TrangThai: active | suspended. Cửa hàng đang khoá vẫn ký được (ký xong mở
	// khoá), nhưng người ký phải nhìn thấy mình đang ký cho cái gì.
	TrangThai string `json:"trang_thai" example:"active"`
}

// CuaHangCoSanResponse — GET /platform/cua-hang-chua-ky.
type CuaHangCoSanResponse struct {
	CuaHang []CuaHangCoSanItem `json:"cua_hang"`
}

// KyHopDongRequest — POST /platform/hop-dong.
//
// Ký hợp đồng CHÍNH THỨC cho một cửa hàng ĐÃ TỒN TẠI. Khác TaoDungThuRequest ở
// chỗ không dựng gì bên data plane: cửa hàng và tài khoản đăng nhập đã có sẵn,
// việc duy nhất ở đây là ghi hợp đồng bên control plane.
//
// Giá và ba hạn mức KHÔNG có ô nào — chép từ bảng giá lúc ký, hệt như đường mở
// tài khoản dùng thử. Thoả thuận riêng vẫn đi qua `cmd/thue-bao ky`, nơi có
// bảng đối chiếu in ra trước mắt người ký.
type KyHopDongRequest struct {
	// PlanID là DÒNG bảng giá (gói × chu kỳ), lấy từ GET /platform/plans.
	PlanID uint `json:"plan_id" binding:"required" example:"2"`
	// MaCuaHang phải là cửa hàng đã có ở data plane và CHƯA có hợp đồng còn hiệu
	// lực cho phần mềm này — xem GET /platform/cua-hang-chua-ky.
	MaCuaHang string `json:"ma_cua_hang" binding:"required,max=30" example:"quochuy"`
	// SoThang là độ dài hợp đồng. Bỏ trống = một chu kỳ của gói (1 tháng cho gói
	// theo tháng, 12 tháng cho gói theo năm) — con số người ta muốn trong hầu hết
	// trường hợp, nên không bắt gõ lại.
	SoThang int    `json:"so_thang" binding:"omitempty,min=1,max=60" example:"12"`
	GhiChu  string `json:"ghi_chu" binding:"max=500"`
}

// ThuTienRequest — POST /platform/subscriptions/{id}/thu-tien.
//
// Ghi MỘT LẦN TIỀN VÀO, không phải "gia hạn". Hai việc đó cố ý tách rời: gia
// hạn đẩy hạn hợp đồng, còn đây ghi nhận tiền thật đã nhận. Gộp lại thì mỗi lần
// gia hạn sẽ báo một khoản doanh thu chưa ai trả — và ngược lại, khách trả
// trước mà chưa muốn đẩy hạn thì không ghi vào đâu được.
type ThuTienRequest struct {
	// SoTien bỏ trống (0) = lấy ĐÚNG GIÁ HỢP ĐỒNG. Khai số khác khi thu thiếu,
	// thu gộp, hoặc có chiết khấu một lần — số ở đây là tiền THẬT đã nhận, và
	// nó được phép khác giá đã ký.
	SoTien float64 `json:"so_tien" binding:"omitempty,min=0" example:"990000"`
	// HinhThuc: chuyen_khoan | tien_mat | khac. Bỏ trống = chuyen_khoan.
	HinhThuc string `json:"hinh_thuc" binding:"omitempty,oneof=chuyen_khoan tien_mat khac" example:"chuyen_khoan"`
	// MaGiaoDich là mã giao dịch ngân hàng hoặc số phiếu thu — thứ dùng để đối
	// chiếu với sao kê. Không bắt buộc, nhưng thiếu nó thì lần thu này không tra
	// ngược lại được từ sổ ngân hàng.
	MaGiaoDich string `json:"ma_giao_dich" binding:"max=100" example:"FT26081412345"`
	GhiChu     string `json:"ghi_chu" binding:"max=500"`
	// KyTu / KyDen dạng 2006-01-02, bỏ trống = MÁY CHỦ TỰ TÍNH: từ điểm cuối của
	// kỳ đã trả gần nhất (hoặc ngày bắt đầu hợp đồng nếu chưa trả lần nào) tới
	// hạn hiện tại. Nhờ vậy hai lần thu liên tiếp không chồng lấn và cũng không
	// để hở khoảng nào ở giữa.
	//
	// Chỉ khai tay khi thu cho một kỳ KHÁC với kỳ máy chủ tính ra.
	KyTu  string `json:"ky_tu" binding:"omitempty,datetime=2006-01-02" example:"2026-08-14"`
	KyDen string `json:"ky_den" binding:"omitempty,datetime=2006-01-02" example:"2027-08-14"`
}

// ThuTienResponse — biên nhận của lần thu vừa ghi.
type ThuTienResponse struct {
	SoTien   float64   `json:"so_tien" example:"990000"`
	HinhThuc string    `json:"hinh_thuc" example:"chuyen_khoan"`
	KyTu     time.Time `json:"ky_tu"`
	KyDen    time.Time `json:"ky_den"`
	// TongDaThu là tổng tiền đã thu của hợp đồng này SAU lần ghi vừa rồi — để
	// màn hình khỏi phải gọi thêm một lượt chỉ để cập nhật một con số.
	TongDaThu float64 `json:"tong_da_thu" example:"1980000"`
	SoLanThu  int     `json:"so_lan_thu" example:"2"`
}

// DoiMatKhauRequest — POST /platform/subscriptions/{id}/doi-mat-khau.
//
// Chỉ MỘT ô. Ô "xác nhận mật khẩu" là lưới chặn gõ nhầm ở giao diện, nên nó ở
// lại giao diện: gửi cả hai lên rồi so ở máy chủ chỉ thêm một đường để chúng
// lệch nhau, mà người gọi API thẳng thì không gõ nhầm hai lần giống nhau được.
type DoiMatKhauRequest struct {
	MatKhau string `json:"mat_khau" binding:"required,min=6,max=100" example:"MatKhauMoi@123"`
}

// HopDongChiTietResponse — GET /platform/subscriptions/{id}.
type HopDongChiTietResponse struct {
	HopDong HopDongChiTiet `json:"hop_dong"`
}

// SuaHopDongRequest — PUT /platform/subscriptions/{id}.
//
// DANH SÁCH Ô CỐ TÌNH NGẮN. Không có gói, chu kỳ, giá hay ba hạn mức: đó là điều
// khoản đã ký, và cả hệ thống dựng trên nguyên tắc chúng không đổi sau lúc ký.
// Bán thêm quyền lợi cho một khách là việc của `cmd/thue-bao`, nơi có bảng đối
// chiếu in ra trước mắt người ký.
//
// Mọi ô đều GHI ĐÈ, kể cả khi để trống — form gửi lên trọn bộ, và ô trống nghĩa
// là "xoá nội dung ô đó" chứ không phải "giữ nguyên". Trừ HetHan, xem dưới.
type SuaHopDongRequest struct {
	TenCuaHang  string `json:"ten_cua_hang" binding:"required,max=150" example:"Cửa hàng Quốc Huy"`
	NguoiLienHe string `json:"nguoi_lien_he" binding:"max=150" example:"Anh Huy"`
	DienThoai   string `json:"dien_thoai" binding:"max=20" example:"0901234567"`
	Email       string `json:"email" binding:"omitempty,email,max=150" example:"huy@quochuy.vn"`
	// GhiChuKhach đi theo KHÁCH (sổ khách hàng), GhiChuHopDong chết cùng hợp đồng.
	GhiChuKhach   string `json:"ghi_chu_khach" binding:"max=500"`
	GhiChuHopDong string `json:"ghi_chu_hop_dong" binding:"max=500"`
	// HetHan nhận HAI dạng, và cả hai đều có lý do tồn tại:
	//
	//   · `2006-01-02T15:04`  — có giờ. Đây là thứ ô <input type="datetime-local">
	//     gửi lên, và là dạng dùng khi cần chốt giờ chính xác ("hết hạn 9h sáng
	//     mai, trước cuộc hẹn lúc 10h").
	//   · `2006-01-02`        — ngày trần. Máy chủ tự lấy CUỐI ngày đó
	//     (23:59:59), vì "hết hạn ngày 30" trong đầu người nói nghĩa là hết ngày
	//     30 chứ không phải 0 giờ ngày 30 — hiểu theo cách kia là cắt của khách
	//     trọn một ngày.
	//
	// Bỏ trống = GIỮ NGUYÊN hạn hiện tại, khác mọi ô khác ở trên: "xoá ngày hết
	// hạn" không phải một trạng thái tồn tại được.
	//
	// Chỉ nhận khi hợp đồng đang DÙNG THỬ. Hợp đồng đã trả tiền muốn dài thêm thì
	// đi đường gia hạn, để đường tiền và đường hạn không tách khỏi nhau.
	HetHan string `json:"het_han" binding:"max=25" example:"2026-09-30T17:30"`
}

// KhachHangMoiChung là bộ ô DÙNG CHUNG của hai đường tạo khách mới
// (/platform/dung-thu và /platform/chinh-thuc).
//
// Một lượt gọi dựng CẢ BA thứ: cửa hàng bên data plane, tài khoản quản trị đầu
// tiên của họ, và hợp đồng dùng thử bên control plane. Tách thành ba đường thì
// người bán phải bấm ba lần và lần thứ hai hỏng sẽ để lại một nửa khách hàng.
//
// PlanID là DÒNG bảng giá (gói × chu kỳ), không phải mã gói: gói "Cửa hàng" bán
// theo tháng và theo năm là hai dòng, hai giá, hai bộ hạn mức. Gửi mã gói lên
// thì máy chủ vẫn phải hỏi lại chu kỳ, và câu hỏi đó không có chỗ nào để hỏi.
type KhachHangMoiChung struct {
	// PlanID lấy từ GET /platform/plans. Phần mềm suy ra từ chính dòng này, nên
	// không có tham số `app` — gửi cả hai thì chúng mâu thuẫn được với nhau.
	PlanID uint `json:"plan_id" binding:"required" example:"2"`
	// MaCuaHang là ô ĐẦU TIÊN của màn hình đăng nhập 3 ô. Chuẩn hoá về chữ thường
	// ở máy chủ — khách gõ lại ô này trên điện thoại, nơi bàn phím tự viết hoa.
	MaCuaHang string `json:"ma_cua_hang" binding:"required" example:"quochuy"`
	// TenCuaHang hiển thị trong khu điều hành và làm tên chi nhánh mặc định.
	TenCuaHang string `json:"ten_cua_hang" binding:"required,max=150" example:"Cửa hàng Quốc Huy"`
	// TenDangNhap là ô THỨ HAI của màn hình đăng nhập.
	TenDangNhap string `json:"ten_dang_nhap" binding:"required" example:"admin"`
	// MatKhau là ô THỨ BA. Tối thiểu 6 ký tự, khớp ràng buộc của UserRequest.
	MatKhau string `json:"mat_khau" binding:"required,min=6" example:"MatKhau@123"`
	// HoTen của người quản trị. Bỏ trống thì lấy theo `nguoi_lien_he`, không có
	// nữa thì "Quản trị viên" — hai ô đó gần như luôn là một người.
	HoTen string `json:"ho_ten" binding:"max=150" example:"Nguyễn Quốc Huy"`
	// KHÔNG CÓ ô email cho tài khoản đăng nhập, và đó là chủ ý.
	//
	// Khách đăng nhập Sellio Order bằng ĐÚNG BA Ô (/auth/shop-login): mã cửa
	// hàng, tên đăng nhập, mật khẩu. Đăng nhập bằng email (/auth/login) là đường
	// của KHÁCH MUA SẮM ở storefront, và quên mật khẩu qua email cũng chỉ có
	// trong cụm đó — cụm đang tắt mặc định. Cột `users.email` vì thế không phải
	// một thông tin đăng nhập; nó chỉ là cột NOT NULL của lược đồ, và máy chủ tự
	// đặt <tên đăng nhập>@<mã cửa hàng>.local đúng như `cmd/tao-admin`.
	//
	// Hỏi người bán một địa chỉ email cho ô đó là hỏi một thứ không dùng vào
	// việc gì, rồi ghi nó xuống như thể khách đăng nhập được bằng nó.

	// Ba ô liên hệ ghi vào SỔ KHÁCH HÀNG (control plane), KHÔNG vào tài khoản
	// đăng nhập: đây là người mình gọi khi hết hạn dùng thử, và họ thường không
	// phải người ngồi gõ phần mềm.
	NguoiLienHe string `json:"nguoi_lien_he" binding:"max=150" example:"Anh Huy"`
	DienThoai   string `json:"dien_thoai" binding:"max=20" example:"0901234567"`
	// EmailLienHe là email THẬT của khách, để liên lạc. Khác hẳn `users.email`
	// nói ở trên — chép cái .local tự sinh sang đây thì sổ khách hàng đầy những
	// địa chỉ không gửi thư tới được, mà nhìn vẫn như email thật.
	EmailLienHe string `json:"email_lien_he" binding:"omitempty,email,max=150" example:"huy@quochuy.vn"`
	// GhiChu vào chính hợp đồng — thoả thuận riêng, ai giới thiệu, hẹn gọi lại.
	GhiChu string `json:"ghi_chu" binding:"max=500" example:"khách anh Sơn giới thiệu"`
}

// TaoDungThuRequest — POST /platform/dung-thu. Khách mới + hợp đồng DÙNG THỬ.
type TaoDungThuRequest struct {
	KhachHangMoiChung
	// SoNgayDungThu bỏ trống (null) = lấy `trial_days` của dòng bảng giá. Gửi số
	// là ghi đè cho riêng khách này. Gửi 0 bị từ chối: "dùng thử 0 ngày" tạo ra
	// một hợp đồng quá hạn ngay từ giây đầu.
	SoNgayDungThu *uint `json:"so_ngay_dung_thu" binding:"omitempty" example:"14"`
}

// TaoChinhThucRequest — POST /platform/chinh-thuc. Khách mới + hợp đồng CHÍNH
// THỨC (`active`), không qua giai đoạn dùng thử.
//
// Cùng bộ ô với đường dùng thử, khác đúng MỘT chỗ: thời hạn tính bằng THÁNG chứ
// không phải ngày, và hợp đồng ra `active` ngay. Dùng khi khách trả tiền từ đầu,
// không cần thử.
type TaoChinhThucRequest struct {
	KhachHangMoiChung
	// SoThang bỏ trống = một chu kỳ của gói (1 tháng, hoặc 12 nếu gói theo năm)
	// — con số người ta muốn trong hầu hết trường hợp, nên không bắt gõ lại.
	SoThang int `json:"so_thang" binding:"omitempty,min=1,max=60" example:"12"`
}

// DangKyRequest — POST /dang-ky, ĐƯỜNG CÔNG KHAI khách tự mở tài khoản từ trang
// giới thiệu.
//
// KHÁC TaoDungThuRequest ở đúng chỗ nguy hiểm nhất: KHÔNG có `plan_id`. Gói do
// MÁY CHỦ chọn (Khởi đầu, chu kỳ tháng, đang bán) chứ không nhận từ trình duyệt
// — để khách gửi lên mã gói nghĩa là ai cũng tự cấp cho mình một kỳ dùng thử
// gói Chuỗi, và không có màn hình nào của mình nói ra điều đó.
//
// Cũng không có `so_ngay_dung_thu`: số ngày lấy theo bảng giá. Cho khách tự khai
// là cho họ tự đặt hạn hợp đồng của chính mình.
type DangKyRequest struct {
	MaCuaHang   string `json:"ma_cua_hang" binding:"required" example:"quochuy"`
	TenCuaHang  string `json:"ten_cua_hang" binding:"required,max=150" example:"Cửa hàng Quốc Huy"`
	TenDangNhap string `json:"ten_dang_nhap" binding:"required" example:"admin"`
	MatKhau     string `json:"mat_khau" binding:"required,min=6" example:"MatKhau@123"`
	NguoiLienHe string `json:"nguoi_lien_he" binding:"required,max=150" example:"Nguyễn Quốc Huy"`
	DienThoai   string `json:"dien_thoai" binding:"required,max=20" example:"0901234567"`
	Email       string `json:"email" binding:"omitempty,email,max=191" example:"huy@example.com"`
	// Website là BẪY: một ô ẩn trong form, người thật không nhìn thấy nên luôn để
	// trống, còn máy tự điền form thì điền hết mọi ô. Điền vào đây là bị từ chối.
	//
	// Rẻ và không phiền ai — khác hẳn captcha, thứ bắt mọi khách thật giải câu đố
	// để chặn một nhóm nhỏ không phải khách. Nó không chặn được kẻ nhắm riêng vào
	// mình, và không cố tỏ ra như vậy: chốt chặn thứ hai là giới hạn tần suất
	// theo IP ở tầng route.
	Website string `json:"website" binding:"omitempty,max=200"`
}

// DangKyResponse — thứ trang giới thiệu in ra ngay sau khi đăng ký xong.
//
// Có đủ BA Ô ĐĂNG NHẬP (trừ mật khẩu — khách vừa tự đặt) và địa chỉ Shop Admin:
// người vừa đăng ký phải đi thẳng vào phần mềm được, không phải chờ email nào cả.
type DangKyResponse struct {
	MaCuaHang   string    `json:"ma_cua_hang" example:"quochuy"`
	TenDangNhap string    `json:"ten_dang_nhap" example:"admin"`
	Goi         string    `json:"goi" example:"Khởi đầu"`
	HetHan      time.Time `json:"het_han"`
	// DiaChiDangNhap là URL Shop Admin của chính tiến trình này (cfg.App.ShopAdminURL).
	DiaChiDangNhap string `json:"dia_chi_dang_nhap" example:"https://order.selliotech.store"`
}

// TaoDungThuResponse — biên bản của lượt ký, đủ để màn hình đọc lại cho người
// bán mà không phải tải lại danh sách.
//
// NguonDieuKhoan nói mỗi con số đến từ đâu ("bảng giá" / "bảng giá: không giới
// hạn"), giống bảng đối chiếu `cmd/thue-bao ky` in ra. Ghi một hợp đồng mà không
// nói được con số ở đâu ra là thứ không ai kiểm lại được.
type TaoDungThuResponse struct {
	TenantID    uint   `json:"tenant_id" example:"7"`
	HopDongID   uint   `json:"hop_dong_id" example:"12"`
	MaCuaHang   string `json:"ma_cua_hang" example:"quochuy"`
	TenCuaHang  string `json:"ten_cua_hang" example:"Cửa hàng Quốc Huy"`
	TenDangNhap string `json:"ten_dang_nhap" example:"admin"`
	Goi         string `json:"goi" example:"Cửa hàng"`
	// TrangThai của hợp đồng vừa ghi: `trial` hay `active`. Có mặt vì cùng một
	// kiểu này phục vụ CẢ HAI đường tạo khách — nơi hiển thị đọc nó để biết vừa
	// mở một kỳ dùng thử hay vừa bán một hợp đồng.
	TrangThai string `json:"trang_thai" example:"trial"`
	// HetHan cũng chính là ngày hết dùng thử: hạn hợp đồng thử KHÔNG đẩy ra xa
	// hơn ngày đó.
	HetHan         time.Time         `json:"het_han"`
	NguonDieuKhoan map[string]string `json:"nguon_dieu_khoan"`
}

// GiaHanRequest — POST /platform/subscriptions/{id}/gia-han.
//
// Cũng là đường CHUYỂN DÙNG THỬ SANG CHÍNH THỨC: gia hạn đưa hợp đồng về
// 'active' và xoá mốc hết dùng thử. Hai việc đó là một, nên không có endpoint
// riêng cho việc chuyển — bản thứ hai sẽ quên một trong hai vế.
type GiaHanRequest struct {
	// SoThang cộng vào GREATEST(ends_at, NOW()), không phải vào ends_at: hợp đồng
	// đã quá hạn ba tháng mà cộng dồn từ ngày cũ thì khách trả tiền xong vẫn còn
	// quá hạn.
	SoThang int `json:"so_thang" binding:"required,min=1,max=60" example:"12"`
}

// HuyRequest — POST /platform/subscriptions/{id}/huy.
type HuyRequest struct {
	// LyDo nối vào `note` của hợp đồng, không ghi đè: note đang giữ điều khoản
	// riêng đã thoả thuận với khách.
	LyDo string `json:"ly_do" binding:"max=300" example:"khách đổi sang gói Chuỗi"`
}

// HopDongMotItem bọc một dòng hợp đồng — câu trả lời của hai đường ghi ở trên,
// để màn hình cập nhật đúng dòng vừa đổi thay vì tải lại cả bảng.
type HopDongMotItem struct {
	HopDong HopDongItem `json:"hop_dong"`
}

// DoanhThuItem là tổng tiền ĐÃ THU của một cửa hàng.
type DoanhThuItem struct {
	MaCuaHang  string  `json:"ma_cua_hang" example:"quochuy"`
	TenCuaHang string  `json:"ten_cua_hang" example:"Quốc Huy"`
	SoLanThu   int     `json:"so_lan_thu" example:"3"`
	TongTien   float64 `json:"tong_tien" example:"29970000"`
	LanCuoi    string  `json:"lan_cuoi" example:"13/08/2026"`
}

// DoanhThuResponse — GET /platform/doanh-thu.
//
// Con số ở đây là TIỀN ĐÃ VÀO (bảng `invoices`), không phải tiền đáng lẽ phải
// thu theo hợp đồng. Sổ thu trống thì doanh thu là 0 kể cả khi có hợp đồng đang
// chạy — và đó là câu trả lời đúng.
type DoanhThuResponse struct {
	TheoQuan []DoanhThuItem `json:"theo_quan"`
	TongTien float64        `json:"tong_tien" example:"29970000"`
	SoLanThu int            `json:"so_lan_thu" example:"3"`
}

// ---------- Gói dịch vụ CỦA CHÍNH CỬA HÀNG ----------
//
// Khối này phục vụ màn hình "Các gói dịch vụ" trong Shop Admin — chỗ chủ tiệm tự
// tra mình đang dùng gói nào, còn bao nhiêu ngày, và muốn gia hạn thì có những
// mức giá nào. Nó ĐỌC CÙNG hai bảng với khu điều hành (`subscriptions`, `plans`)
// nhưng qua một cửa khác và hẹp hơn hẳn: đúng một khách, đúng một phần mềm.
//
// CỐ Ý KHÔNG có ghi chú hợp đồng, ghi chú khách và thông tin liên hệ trong sổ
// nền tảng. Đó là chỗ người bán ghi việc nội bộ ("khách trả chậm", "anh Sơn giới
// thiệu"), và nó không dành cho mắt khách đọc.

// HopDongCuaToi là hợp đồng ĐANG CHẠY của cửa hàng đang đăng nhập.
//
// Ba hạn mức và cờ tên miền lấy THẲNG từ hợp đồng chứ không tra sang bảng giá:
// bảng giá được phép đổi, hợp đồng đã ký thì không (xem domain.Subscription).
// Giá trị 0 ở ba hạn mức nghĩa là KHÔNG GIỚI HẠN — màn hình phải in ra chữ.
type HopDongCuaToi struct {
	TenApp string `json:"ten_app" example:"Sellio Order"`
	// Goi là MÃ gói đã chốt trong hợp đồng; TenGoi là tên hiển thị tra từ bảng
	// giá, rơi về mã khi không tra ra (hợp đồng thoả thuận riêng).
	Goi    string `json:"goi" example:"cua_hang"`
	TenGoi string `json:"ten_goi" example:"Cửa hàng"`
	// TrangThai: trial | active | past_due. `canceled` không bao giờ tới đây —
	// đường đọc chỉ trả hợp đồng còn hiệu lực.
	TrangThai string `json:"trang_thai" example:"active"`
	// ChuKy: thang | nam
	ChuKy        string    `json:"chu_ky" example:"thang"`
	Gia          float64   `json:"gia" example:"499000"`
	ChiNhanh     uint      `json:"chi_nhanh" example:"1"`
	TaiKhoan     uint      `json:"tai_khoan" example:"0"`
	SanPham      uint      `json:"san_pham" example:"0"`
	TenMienRieng bool      `json:"ten_mien_rieng" example:"false"`
	BatDau       time.Time `json:"bat_dau"`
	HetHan       time.Time `json:"het_han"`
	// DaHetHan là câu trả lời DUY NHẤT ĐÚNG cho "hết hạn chưa", so tới từng giây.
	//
	// KHÔNG suy ra từ ConLaiNgay. Hợp đồng vừa hết hạn hai phút trước có
	// ConLaiNgay = 0 chứ không âm, nên `con_lai_ngay < 0` đọc thành "chưa hết
	// hạn" đúng trong khoảng nguy hiểm nhất — ngay sau thời khắc hết hạn, lúc màn
	// hình phải báo động nhất.
	DaHetHan bool `json:"da_het_han" example:"false"`
	// ConLaiNgay là số ngày còn lại, LÀM TRÒN LÊN: còn 2 phút vẫn là "1 ngày" chứ
	// không phải 0, và quá hạn 25 giờ là -1. Đây là con số màn hình in to nhất,
	// nên nó được tính ở máy chủ — hai nơi tự tính theo giờ máy mình là hai câu
	// trả lời khác nhau cho cùng một hợp đồng.
	//
	// 0 mang HAI nghĩa, và phải đọc kèm DaHetHan mới phân biệt được: hết hạn
	// trong hôm nay (chưa hết) hoặc vừa hết hạn trong hôm nay (đã hết).
	ConLaiNgay int `json:"con_lai_ngay" example:"41"`
	// DungThu = true: hợp đồng còn ở KỲ DÙNG THỬ, khách chưa trả đồng nào. Khác
	// hẳn khách cũ sắp tới hạn, và câu mời gia hạn phải khác theo.
	DungThu bool `json:"dung_thu" example:"false"`
}

// GoiDichVuCuaToiResponse — GET /admin/goi-dich-vu.
//
// HopDong nil = cửa hàng chưa có hợp đồng nào trong sổ nền tảng. Đó là trạng
// thái HỢP LỆ chứ không phải lỗi (cửa hàng dựng tay trước khi có control plane),
// và màn hình phải nói ra điều đó thay vì hiện một khối trống.
//
// BangGia chỉ gồm gói ĐANG BÁN của phần mềm này, cộng thêm đúng dòng gói mà hợp
// đồng hiện tại đang dùng — kể cả khi nó đã ngừng bán. Thiếu vế sau thì khách
// đang dùng một gói cũ mở trang ra và không thấy gói của mình đâu cả.
// DaDungHanMuc là số thứ cửa hàng ĐANG CÓ, để màn hình đặt cạnh trần đã ký.
//
// Đây là vế còn thiếu của một hạn mức: "tối đa 20 tài khoản" không nói được còn
// mấy chỗ, và người dùng chỉ biết mình đụng trần vào đúng lúc bị từ chối. Con số
// đếm bằng CHÍNH cửa mà lượt chặn dùng, nên hai chỗ không nói hai câu khác nhau.
//
// Đủ cả ba hạn mức của hợp đồng. Chi nhánh luôn >= 1: mỗi cửa hàng được dựng
// kèm một chi nhánh 'mac-dinh' lúc mở tài khoản.
type DaDungHanMuc struct {
	ChiNhanh int64 `json:"chi_nhanh" example:"1"`
	TaiKhoan int64 `json:"tai_khoan" example:"4"`
	SanPham  int64 `json:"san_pham" example:"128"`
}

type GoiDichVuCuaToiResponse struct {
	HopDong *HopDongCuaToi `json:"hop_dong"`
	// DaDung nil = chưa có hợp đồng để so, hoặc lượt đếm vừa hỏng. Màn hình rơi
	// về câu chữ của hạn mức chứ đừng in số 0 — "đang dùng 0/20" là một câu SAI,
	// và nó sai ngay trên màn hình khách mở ra để yên tâm.
	DaDung  *DaDungHanMuc `json:"da_dung"`
	BangGia []PlanItem    `json:"bang_gia"`
	// Fields là siêu dữ liệu của từng khoá hạn mức (nhãn, đơn vị, giá trị khi gói
	// không khai). Trả kèm để màn hình khỏi chép lại bảng khoá lần thứ hai —
	// thêm một hạn mức mới ở registry là trang này tự có thêm dòng.
	Fields []PlanFeatureField `json:"fields"`
}

// ---------- Khu điều hành: cấu hình của NHÀ CUNG CẤP ----------

// CauHinhNenTangField mô tả MỘT ô cấu hình theo registry của service.
//
// Màn hình dựng form từ danh sách này, nên bảng khoá không bị chép lại lần thứ
// hai ở tầng Blade — thêm một ô mới bên Go là màn hình tự có thêm ô.
type CauHinhNenTangField struct {
	Key string `json:"key" example:"ck_so_tai_khoan"`
	// Type: text | bool | image | textarea
	Type  string `json:"type" example:"text"`
	Label string `json:"label" example:"Số tài khoản"`
	// GoiY là câu in dưới ô nhập, viết cho người bán đọc.
	GoiY    string `json:"goi_y" example:"Chỉ gồm chữ số."`
	MacDinh string `json:"mac_dinh" example:""`
	// BatBuocKhiBat = true: bỏ trống ô này mà vẫn bật hình thức TƯƠNG ỨNG thì API
	// từ chối lưu. Trả ra để màn hình đánh dấu sao đỏ đúng chỗ, thay vì để người
	// dùng bấm Lưu rồi mới biết.
	BatBuocKhiBat bool `json:"bat_buoc_khi_bat" example:"true"`
	// CongTac là khoá bật/tắt chi phối ô này ("ck_bat", "payos_bat"). Rỗng với
	// chính ô công tắc. Màn hình dùng nó để gom ô vào đúng khối và để biết sao đỏ
	// đang nói về hình thức nào.
	CongTac string `json:"cong_tac" example:"ck_bat"`
	// BiMat = true: giá trị cất ở dạng MÃ HOÁ và KHÔNG bao giờ trả nguyên văn —
	// `values` chỉ mang bản che (••••1234). Gửi lên chuỗi rỗng nghĩa là GIỮ
	// NGUYÊN khoá cũ, không phải xoá.
	BiMat bool `json:"bi_mat" example:"true"`
	Max   int  `json:"max" example:"32"`
}

// CauHinhNenTangResponse — GET/PUT /platform/cau-hinh.
//
// Values là map phẳng khoá → giá trị, ĐÃ ghép với mặc định của registry: khoá
// chưa có dòng dưới database vẫn xuất hiện, mang giá trị mặc định.
type CauHinhNenTangResponse struct {
	Values map[string]string     `json:"values"`
	Fields []CauHinhNenTangField `json:"fields"`
	// KhoaMaHoa = false: máy chủ CHƯA khai PLATFORM_SECRET_KEY, nên mọi ô bí mật
	// đều không lưu được. Trả ra để màn hình nói trước điều đó, thay vì để người
	// dùng gõ xong khoá PayOS rồi bấm Lưu và nhận lỗi.
	KhoaMaHoa bool `json:"khoa_ma_hoa" example:"true"`
}

// CauHinhNenTangUpdateRequest — ghi nhiều khoá trong một lần gọi.
//
// Chỉ những khoá gửi lên bị đổi, khoá không gửi giữ nguyên. Khoá lạ làm cả yêu
// cầu bị từ chối 422 chứ không âm thầm ghi xuống.
type CauHinhNenTangUpdateRequest struct {
	Items map[string]string `json:"items" binding:"required,min=1,max=30"`
}

// ---------- Khách tự gia hạn ----------

// DatGiaHanRequest — khách chọn gói và số tháng để gia hạn.
//
// KHÔNG có số tiền: giá do máy chủ tra từ bảng giá và chốt vào đơn. Nhận số tiền
// từ client nghĩa là ai cũng gia hạn được với giá một đồng.
type DatGiaHanRequest struct {
	PlanID uint `json:"plan_id" binding:"required" example:"2"`
	// SoThang: 1–24. Dài hơn là một thoả thuận cần người nói chuyện với nhau, không
	// phải một ô chọn trên màn hình.
	SoThang int `json:"so_thang" binding:"required,min=1,max=24" example:"3"`
}

// DonGiaHanResponse là một đơn gia hạn nhìn từ phía CHỦ TIỆM.
//
// Cố ý KHÔNG mang id hợp đồng, id gói hay tên cổng thanh toán: màn hình chỉ cần
// biết trả bao nhiêu, trả ở đâu, và đã xong chưa.
type DonGiaHanResponse struct {
	ID    uint `json:"id" example:"12"`
	MaDon uint `json:"ma_don" example:"12"`
	// TenGoi là gói của HỢP ĐỒNG hiện tại, để màn hình nhắc lại khách đang gia hạn
	// cái gì.
	TenGoi string `json:"ten_goi" example:"Cửa hàng"`
	// ----- BÊN MUA: cửa hàng đang trả tiền -----
	//
	// Có mặt vì một trang thanh toán phải nói rõ ĐANG THU TIỀN CHO AI. Không có
	// nó, người bấm trả tiền trên một máy dùng chung không có cách nào chắc mình
	// đang gia hạn đúng cửa hàng của mình — và tiền đã chuyển thì không rút lại
	// được.
	//
	// Ba ô liên hệ lấy từ SỔ KHÁCH HÀNG của nền tảng, tức đúng thứ sẽ in trên hoá
	// đơn. Sai thì khách sửa được bằng cách gọi cho nhà cung cấp — nên hiện ra là
	// để họ phát hiện, không phải để trang trí.
	TenApp      string  `json:"ten_app" example:"Sellio Order"`
	MaCuaHang   string  `json:"ma_cua_hang" example:"order1"`
	TenCuaHang  string  `json:"ten_cua_hang" example:"Quốc Huy"`
	NguoiLienHe string  `json:"nguoi_lien_he" example:"Nguyễn Quốc Huy"`
	DienThoai   string  `json:"dien_thoai" example:"0901234567"`
	Email       string  `json:"email" example:"huy@quochuy.vn"`
	SoThang     uint    `json:"so_thang" example:"3"`
	SoTien      float64 `json:"so_tien" example:"1497000"`
	// TrangThai: cho_thanh_toan | da_thanh_toan | huy | het_han
	TrangThai string `json:"trang_thai" example:"cho_thanh_toan"`
	// DaTra là bản rút gọn của TrangThai cho màn hình khỏi so chuỗi — nó quyết
	// định trang thanh toán hiện "đang chờ" hay "đã gia hạn".
	DaTra bool `json:"da_tra" example:"false"`
	// CheckoutURL là trang trả tiền của cổng — đường LUI, cho khách muốn trả
	// bằng ví điện tử hoặc thẻ. Trang thanh toán của mình tự dựng được màn hình
	// chuyển khoản từ năm trường ngay dưới, nên khách không bắt buộc phải rời
	// phần mềm.
	CheckoutURL string `json:"checkout_url"`
	// QRCode là chuỗi VietQR nguyên văn — màn hình tự vẽ mã QR từ nó.
	QRCode string `json:"qr_code"`
	// Bốn ô dưới là bản CHỮ của chính mã QR, cho khách không quét được (máy bàn,
	// app ngân hàng cũ) vẫn chuyển tay đúng.
	NganHangBIN string `json:"ngan_hang_bin" example:"970422"`
	SoTaiKhoan  string `json:"so_tai_khoan"`
	ChuTaiKhoan string `json:"chu_tai_khoan"`
	// NoiDung là nội dung chuyển khoản — thứ duy nhất nối một lần tiền vào với
	// một đơn. Gõ sai thì tiền vào tài khoản mà không đơn nào được chốt.
	NoiDung   string     `json:"noi_dung"`
	HetHanLuc *time.Time `json:"het_han_luc"`
	// HanMoi là hạn hợp đồng SAU khi đã gia hạn — chỉ có nghĩa khi DaTra = true.
	// Đây là câu trả lời khách chờ nghe nhất sau khi trả tiền.
	HanMoi time.Time `json:"han_moi"`
}

// ---------- Ca làm việc & sổ quỹ ----------

// MoCaRequest — mở một ca trực két.
type MoCaRequest struct {
	// OpeningCash là tiền mặt ĐANG CÓ trong két lúc mở ca, do người trực đếm.
	// Không suy ra từ ca trước: giữa hai ca có thể có người rút tiền về, và con
	// số đối chiếu phải bắt đầu từ thứ đếm được thật.
	OpeningCash float64 `json:"opening_cash" binding:"gte=0"`
	Note        string  `json:"note" binding:"omitempty,max=500"`
}

// DongCaRequest — chốt ca đang mở của chi nhánh.
type DongCaRequest struct {
	// CountedCash là tiền ĐẾM ĐƯỢC trong két. Bắt buộc phải gửi, kể cả khi bằng 0:
	// đóng ca mà không đếm thì cả cái ca không còn tác dụng gì.
	CountedCash float64 `json:"counted_cash" binding:"gte=0"`
	Note        string  `json:"note" binding:"omitempty,max=500"`
}

// DongCaResponse — kết quả đóng ca.
type DongCaResponse struct {
	Ca *domain.CaLamViec `json:"ca"`
	// NgoaiCa là các dòng tiền mặt phát sinh trong giờ của ca nhưng KHÔNG gắn ca
	// nào (lúc đó chưa ai mở ca). Chúng đã vào/ra két thật nhưng không nằm trong
	// con số đối chiếu — im lặng bỏ qua là để lại một khoản chênh không ai giải
	// thích được.
	NgoaiCa []domain.SoQuy `json:"ngoai_ca"`
}

// CaChiTietResponse — một ca kèm toàn bộ dòng sổ quỹ của nó.
type CaChiTietResponse struct {
	Ca    *domain.CaLamViec `json:"ca"`
	SoQuy []domain.SoQuy    `json:"so_quy"`
}

// GhiSoQuyRequest — ghi tay một khoản thu/chi tiền mặt.
type GhiSoQuyRequest struct {
	// Direction: in (tiền vào két) | out (tiền ra).
	Direction string `json:"direction" binding:"required,oneof=in out"`
	// Amount LUÔN dương — chiều nằm ở direction. Cho phép số âm là mở đường cho
	// hai cách biểu diễn cùng một khoản.
	Amount float64 `json:"amount" binding:"required,gt=0"`
	// Reason bắt buộc: một khoản tiền ra khỏi két mà không có lý do thì đúng bằng
	// mất tiền, chỉ khác là có ghi lại.
	Reason string `json:"reason" binding:"required,max=255"`
}

// ---------- Đổi hàng tại quầy ----------

// DoiHangRequest — một lượt đổi hàng: khách trả lại vài món của đơn cũ và lấy
// vài món mới, chênh lệch thanh toán ngay.
//
// KHÔNG dùng lại luồng trả hàng nhiều bước (lập phiếu → duyệt → nhận hàng): ở
// quầy thì món hàng đang nằm trên tay người bán, không có gì để chờ duyệt. Cả
// hai vế đi trong MỘT giao dịch — xem OrderRepository.DoiHang.
type DoiHangRequest struct {
	// OrderID là đơn CŨ chứa những món khách mang tới trả.
	OrderID uint `json:"order_id" binding:"required,min=1"`
	// Tra là các món trả lại, theo dòng hàng của đơn cũ.
	Tra []DoiHangTraItem `json:"tra" binding:"required,min=1,max=50,dive"`
	// Moi là các món khách lấy về. Được phép RỖNG: khách trả hàng rồi không lấy
	// gì thêm cũng là một lượt đổi hợp lệ, chỉ khác là cửa hàng trả lại tiền.
	Moi []POSItemRequest `json:"moi" binding:"omitempty,max=50,dive"`

	// Reason — lý do đổi, dùng chung bộ mã với phiếu trả hàng.
	Reason     string `json:"reason" binding:"omitempty,max=50"`
	ReasonNote string `json:"reason_note" binding:"omitempty,max=500"`
	// Restock = false khi hàng khách trả bị lỗi, không đưa lại lên kệ được.
	// Mặc định (bỏ trống) là true: phần lớn lượt đổi là đổi size, hàng còn nguyên.
	Restock *bool `json:"restock"`

	// PaymentMethod áp cho phần CHÊNH LỆCH, chỉ dùng khi khách phải trả thêm.
	PaymentMethod string `json:"payment_method" binding:"omitempty,oneof=cash bank_transfer"`
	// AmountTendered là tiền mặt khách đưa cho phần chênh lệch.
	AmountTendered *float64 `json:"amount_tendered" binding:"omitempty,gte=0"`
	Note           string   `json:"note" binding:"omitempty,max=500"`
}

// DoiHangTraItem — một dòng hàng khách mang tới trả.
type DoiHangTraItem struct {
	// OrderItemID là DÒNG HÀNG của đơn cũ, không phải id biến thể: một đơn có thể
	// có hai dòng cùng biến thể (khác tên in áo), và số còn trả được tính theo
	// từng dòng.
	OrderItemID uint `json:"order_item_id" binding:"required,min=1"`
	Quantity    int  `json:"quantity" binding:"required,min=1"`
}

// DoiHangResponse — kết quả một lượt đổi, đủ để in phiếu ngay.
type DoiHangResponse struct {
	ReturnID   uint   `json:"return_id"`
	ReturnCode string `json:"return_code"`
	OrderID    uint   `json:"order_id"`
	OrderCode  string `json:"order_code"`
	// TienTra là giá trị hàng khách mang trả, TienMoi là giá trị hàng khách lấy về.
	TienTra float64 `json:"tien_tra"`
	TienMoi float64 `json:"tien_moi"`
	// ChenhLech = TienMoi − TienTra. Dương = khách trả thêm, âm = cửa hàng trả lại
	// khách. Một con số có dấu chứ không phải hai trường: người đọc chỉ cần biết
	// tiền đi về phía nào, và hai trường thì luôn có một cái bằng 0 gây phân vân.
	ChenhLech      float64  `json:"chenh_lech"`
	AmountTendered *float64 `json:"amount_tendered,omitempty"`
	ChangeAmount   *float64 `json:"change_amount,omitempty"`
	Message        string   `json:"message"`
}

// ---------- Quy tắc đánh số chứng từ ----------

// QuyTacMaResponse — dữ liệu màn cấu hình: danh mục loại + quy tắc đã lưu.
//
// Trả về TOÀN BỘ quy tắc của cửa hàng trong một lượt (mọi chi nhánh, cả bật lẫn
// tắt): màn hình đổi chi nhánh liên tục, đọc lại mỗi lần là một vòng mạng cho
// mấy chục dòng dữ liệu.
type QuyTacMaResponse struct {
	Loai   []domain.LoaiMa   `json:"loai"`
	QuyTac []domain.QuyTacMa `json:"quy_tac"`
}

// QuyTacMaItem — một dòng trong bảng quy tắc.
type QuyTacMaItem struct {
	DocType string `json:"doc_type" binding:"required,max=40" example:"don-hang"`
	Prefix  string `json:"prefix" binding:"omitempty,max=20" example:"DH"`
	// ValuePart: so-thu-tu | ngay-thang-nam | thang-nam.
	ValuePart string `json:"value_part" binding:"required,max=20" example:"so-thu-tu"`
	// Length là tổng số ký tự phần GIỮA (ngày + số đếm).
	Length int    `json:"length" binding:"required,min=3,max=20" example:"6"`
	Suffix string `json:"suffix" binding:"omitempty,max=20"`
}

// LuuQuyTacMaRequest — trạng thái CUỐI CÙNG của những phạm vi màn hình đang hiện.
//
// Loại nào có trong `quy_tac` là bật, loại nào vắng mặt là tắt. Phạm vi của từng
// dòng do danh mục quyết định (danh mục dùng chung / chứng từ theo chi nhánh),
// không nghe theo dữ liệu gửi lên.
type LuuQuyTacMaRequest struct {
	ShopID uint           `json:"shop_id" binding:"required,min=1"`
	QuyTac []QuyTacMaItem `json:"quy_tac" binding:"omitempty,dive"`
}

// ---------- Thuế suất (Hàng hóa → Thuế) ----------

// MucThueChon — một mức trong ô chọn: số để lưu, chữ để hiện.
//
// Nhãn dựng ở API chứ không để màn hình tự đoán: -1 và -2 không phải phần trăm,
// mỗi nơi tự dịch là mỗi nơi in ra một chữ khác nhau.
type MucThueChon struct {
	GiaTri int    `json:"gia_tri" example:"10"`
	Nhan   string `json:"nhan" example:"10%"`
}

// ThueItem — một dòng trên bảng Thuế, đã ghép sẵn tên loại và bộ mức cho chọn.
//
// Ghép ở đây thay vì trả hai danh sách rời cho màn hình tự nối: nối ở ngoài thì
// mỗi màn hình nối một kiểu và sẽ có màn hình nối sai.
type ThueItem struct {
	ID   uint   `json:"id"`
	Loai string `json:"loai" example:"ban-hang"`
	Ten  string `json:"ten" example:"Thuế đơn bán hàng"`
	MoTa string `json:"mo_ta"`
	// Muc là các mức ĐANG BẬT; MucNhan là chính chúng đã dịch sang chữ.
	Muc      []int         `json:"muc"`
	MucNhan  []string      `json:"muc_nhan"`
	ChonDuoc []MucThueChon `json:"chon_duoc"`
	IsActive bool          `json:"is_active"`
}

// CapNhatThueRequest — bảng mức sau khi sửa, gửi lên nguyên trạng thái cuối.
type CapNhatThueRequest struct {
	Muc []int `json:"muc" binding:"required,min=1"`
}

// TrangThaiThueRequest — công tắc bật/tắt trên bảng danh sách.
//
// Con trỏ chứ không phải bool: `false` là giá trị rỗng của bool, để kiểu thường
// thì "tắt dòng này" và "quên gửi trường" là hai câu giống hệt nhau.
type TrangThaiThueRequest struct {
	IsActive *bool `json:"is_active" binding:"required"`
}
