// Package dto định nghĩa cấu trúc request/response của tầng HTTP.
package dto

import "sass-api/internal/domain"

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
}

// ---------- Brand ----------

type BrandRequest struct {
	Name        string `json:"name" binding:"required,max=150"`
	Slug        string `json:"slug" binding:"required,max=191"`
	Logo        string `json:"logo" binding:"omitempty,max=255"`
	Description string `json:"description" binding:"omitempty,max=500"`
	IsActive    *bool  `json:"is_active"`
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
	// Ba danh sách phạm vi. Phải có ít nhất một id ở một trong ba — chương trình
	// không phạm vi thì không giảm cho ai, tạo ra chỉ để nằm đó gây hiểu nhầm.
	ProductIDs  []uint `json:"product_ids"`
	CategoryIDs []uint `json:"category_ids"`
	BrandIDs    []uint `json:"brand_ids"`
}

// PromotionStatusRequest bật/tắt một chương trình mà không đụng tới ngày chạy.
type PromotionStatusRequest struct {
	IsActive *bool `json:"is_active" binding:"required"`
}

// PromotionResponse là chương trình kèm phạm vi đã tách sẵn thành ba danh sách id
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
	BrandIDs    []uint `json:"brand_ids"`
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
	CategoryID       uint     `json:"category_id" binding:"required"`
	BrandID          *uint    `json:"brand_id"`
	Name             string   `json:"name" binding:"required,max=200"`
	Slug             string   `json:"slug" binding:"required,max=191"`
	SKU              string   `json:"sku" binding:"required,max=64"`
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
	ID    uint     `json:"id"` // >0 = cập nhật dòng cũ; 0 = thêm mới
	SKU   string   `json:"sku" binding:"omitempty,max=64"`
	Size  string   `json:"size" binding:"required,max=20"`
	Color string   `json:"color" binding:"omitempty,max=50"`
	Price *float64 `json:"price" binding:"omitempty,gte=0"`
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
	Name        string `json:"name" binding:"required,max=150"`
	Slug        string `json:"slug" binding:"required,max=191"`
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

// ---------- Tài khoản nội bộ & vai trò ----------

// UserRequest — payload tạo/sửa tài khoản NỘI BỘ (quản trị & nhân viên).
//
// Vai trò truyền bằng role_id; chỉ nhận vai trò nội bộ (super_admin, admin,
// staff). Muốn tạo khách hàng thì dùng /admin/customers, không phải đường này.
type UserRequest struct {
	FullName string `json:"full_name" binding:"required,max=150"`
	// Email vừa là tên đăng nhập trang quản trị, vừa là UNIQUE key ở bảng users.
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
	Email    string `json:"email"`
	Phone    string `json:"phone"`
	Avatar   string `json:"avatar"`
	Status   string `json:"status" example:"active"`

	RoleID          uint   `json:"role_id" example:"2"`
	RoleName        string `json:"role_name" example:"admin"`
	RoleDisplayName string `json:"role_display_name" example:"Quản trị viên"`

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
