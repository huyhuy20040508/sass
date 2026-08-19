// Package config nạp cấu hình ứng dụng từ biến môi trường / file .env (Viper).
package config

import (
	"fmt"
	"net/url"
	"strings"
	"time"

	"github.com/spf13/viper"
)

// Config gom toàn bộ cấu hình ứng dụng.
type Config struct {
	App      AppConfig
	Database DatabaseConfig
	// Platform là lược đồ THỨ HAI — control plane (selliotech_platform): sổ đăng
	// ký khách hàng, thuê bao, tài khoản khu điều hành, tên miền. Nằm ở database
	// riêng chứ không phải vài bảng trong Database, xem
	// database/platform/0001_control-plane-2026-08-12.sql.
	Platform  DatabaseConfig
	JWT       JWTConfig
	CORS      CORSConfig
	RateLimit RateLimitConfig
	Mail      MailConfig
	PayOS     PayOSConfig
	SePay     SePayConfig
	Facebook  FacebookConfig
	Google    GoogleConfig
}

type AppConfig struct {
	Name string
	// ShopAdminURL là gốc địa chỉ khu quản trị cửa hàng. Dùng để dựng đường quay
	// về sau khi khách trả tiền gia hạn ở cổng thanh toán.
	ShopAdminURL string
	// Code là MÃ PHẦN MỀM mà tiến trình này phục vụ — khớp `apps.code` của
	// control plane ("order", "bida"...).
	//
	// Từ khi một khách mua được nhiều phần mềm (migration 0008/0009), mỗi tiến
	// trình API phải biết mình là phần mềm nào: sổ tên miền có cả tên miền của
	// các phần mềm khác, và phân giải một địa chỉ KHÔNG PHẢI của mình rồi phục
	// vụ bằng dữ liệu của mình là cho khách xem nhầm sản phẩm.
	//
	// Nướng vào .env chứ không đoán theo tên miền hay theo database: một tiến
	// trình chỉ phục vụ đúng một phần mềm, và đó là quyết định lúc triển khai.
	Code    string
	Env     string
	Port    string
	BaseURL string
	// TrustedProxies là danh sách máy chủ đứng trước API được phép khai hộ địa
	// chỉ khách qua header X-Forwarded-For / X-Real-IP.
	//
	// KHÔNG ĐƯỢC để rỗng: gin mặc định tin MỌI proxy, nghĩa là nó lấy nguyên giá
	// trị X-Forwarded-For do khách tự gửi lên. Khi đó nhật ký ghi địa chỉ bịa, và
	// mọi hạn mức tính theo IP (xem middleware.RateLimit) bị vượt qua chỉ bằng
	// cách đổi header mỗi lượt gọi. Ở máy thật chỉ có nginx cùng máy gọi tới nên
	// mặc định 127.0.0.1 + ::1 là đủ.
	TrustedProxies []string
	// EnableStorefront bật/tắt CẢ CỤM route công khai phục vụ trang bán hàng cho
	// khách (đăng ký tài khoản, đặt hàng, giỏ hàng, tra cứu đơn, webhook thanh
	// toán, form liên hệ...). Dự án này chỉ dùng khu quản trị nên mặc định TẮT.
	//
	// Tắt bằng cờ chứ không xoá code: mã xử lý vẫn còn nguyên trong handler/
	// service, cần bán hàng cho khách lúc nào thì đặt STOREFRONT_API_ENABLED=true
	// là có lại, không phải viết lại từ đầu.
	//
	// CHÚ Ý: một số route trông có vẻ "công khai" nhưng khu quản trị VẪN GỌI nên
	// KHÔNG nằm trong cụm này — /health, /auth/login, /auth/me, /auth/refresh,
	// /categories, /products, /notifications, /events. Xem router.go.
	EnableStorefront bool
}

func (a AppConfig) IsProduction() bool { return a.Env == "production" }

type DatabaseConfig struct {
	Host            string
	Port            string
	User            string
	Password        string
	Name            string
	MaxOpenConns    int
	MaxIdleConns    int
	ConnMaxLifetime time.Duration
	// ConnMaxIdleTime phải NHỎ HƠN wait_timeout của MySQL, nếu không kết nối
	// nhàn rỗi bị server đóng sẽ được tái sử dụng và gây lỗi "invalid connection".
	ConnMaxIdleTime time.Duration
	// SQLModeThem là những luật sql_mode CỘNG THÊM vào phiên kết nối. Rỗng =
	// không đụng, để server quyết — và đó là giá trị của app chạy thật.
	//
	// CỘNG THÊM chứ không phải đặt đè, và khác biệt này quan trọng: `SET sql_mode`
	// THAY THẾ toàn bộ danh sách. Đặt đè bằng một danh sách thiếu mất một luật là
	// âm thầm NỚI LỎNG chính chỗ mình đang muốn siết — trên CI (MySQL 8) nó sẽ gỡ
	// luôn ONLY_FULL_GROUP_BY khỏi phiên test, tức là tắt đúng cái chốt đã tìm ra
	// lỗi trang Báo cáo → theo size.
	//
	// CỐ Ý để rỗng ở production: máy chủ đã đặt sql_mode ở tầng global, thêm một
	// chỗ khai nữa trong mã nguồn chỉ tạo thêm một chỗ có thể lệch với nó.
	//
	// Chỉ bộ test đặt trường này (xem SQLModeKiemThu).
	SQLModeThem string
	// SecretKey CHỈ có nghĩa với cfg.Platform: khoá mã hoá các giá trị bí mật cất
	// trong `platform_settings` (khoá cổng thanh toán của nền tảng — xem
	// pkg/bimat). Nằm trong struct này vì nó đi cùng đúng một kết nối, và tách ra
	// một struct riêng cho một chuỗi thì chỗ khai báo xa chỗ dùng.
	//
	// Rỗng = chưa khai. API vẫn chạy; màn hình cài đặt từ chối lưu ô bí mật và
	// nói rõ lý do, thay vì lặng lẽ ghi plaintext.
	SecretKey string
}

// DSN trả về chuỗi kết nối MySQL cho GORM.
// SQLModeKiemThu là những luật sql_mode mà BỘ TEST CỘNG THÊM vào phiên kết nối
// của nó — cộng thêm, không đặt đè, xem DatabaseConfig.SQLModeThem.
//
// Mục đích: máy phát triển và máy chủ phải cho cùng một câu trả lời. MySQL đi
// kèm XAMPP chạy sql_mode rỗng hoặc gần rỗng, nên ghi chuỗi rỗng vào một cột
// ENUM ở đó chỉ là cảnh báo rồi ghi bừa, còn trên máy chủ là lỗi thẳng. Năm chỗ
// trong bộ test từng sống nhờ đúng khe hở đó.
//
// THIẾU ONLY_FULL_GROUP_BY LÀ CỐ Ý, dù máy chủ có bật.
//
// Luật đó hai hệ hiểu KHÁC NHAU, không phải bật/tắt. MySQL 8 suy được phụ thuộc
// hàm: `SELECT t.* ... GROUP BY t.id` là hợp lệ vì mọi cột của t phụ thuộc vào
// khoá chính. MariaDB 10.4 không cài phần suy luận đó nên từ chối cùng câu lệnh:
//
//	Error 1055: 'nen_tang.t.code' isn't in GROUP BY
//
// Bật nó lên cho máy chạy MariaDB là tạo ra ĐỎ GIẢ trên những truy vấn hoàn toàn
// đúng ở production — và không gì phá bộ test nhanh bằng việc nó đỏ vì lý do
// không có thật. Luật này để CI gác: ở đó là MySQL 8 thật, đúng thứ máy chủ chạy,
// nên nó vừa bắt được vi phạm vừa không báo oan. Trang Báo cáo → theo size chính
// là do CI tìm ra theo đường đó.
const SQLModeKiemThu = "STRICT_TRANS_TABLES,NO_ZERO_IN_DATE," +
	"NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION"

func (d DatabaseConfig) DSN() string {
	return fmt.Sprintf(
		"%s:%s@tcp(%s:%s)/%s?charset=utf8mb4&parseTime=True&loc=Local%s",
		d.User, d.Password, d.Host, d.Port, d.Name, d.thamSoSQLMode(),
	)
}

// thamSoSQLMode trả về phần "&sql_mode=..." của DSN, hoặc chuỗi rỗng khi không
// ai khai — để nguyên cấu hình của máy chủ.
//
// Giá trị là một BIỂU THỨC SQL chứ không phải chuỗi hằng, và go-sql-driver đưa
// nguyên nó vào câu `SET sql_mode=...` lúc bắt tay, nên MySQL tự tính:
//
//	CONCAT(@@sql_mode, IF(@@sql_mode='','',','), 'STRICT_TRANS_TABLES,...')
//
// Nhánh IF là để lo trường hợp máy chủ đang để sql_mode RỖNG: nối thẳng sẽ ra
// chuỗi mở đầu bằng dấu phẩy, và MySQL từ chối cả câu. Luật trùng thì không sao,
// MySQL tự gộp.
func (d DatabaseConfig) thamSoSQLMode() string {
	if d.SQLModeThem == "" {
		return ""
	}
	return "&" + ThamSoSQLModeThem(d.SQLModeThem)
}

// ThamSoSQLModeThem dựng tham số DSN "sql_mode=..." cộng thêm các luật cho trước.
//
// Xuất ra ngoài vì internal/repository mở kết nối test THẲNG từ chuỗi DSN trong
// biến môi trường, không đi qua DatabaseConfig — nó vẫn cần đúng biểu thức này
// chứ không nên chép lại một bản gần giống.
func ThamSoSQLModeThem(modes string) string {
	bieuThuc := "CONCAT(@@sql_mode, IF(@@sql_mode='','',','), '" + modes + "')"
	return "sql_mode=" + url.QueryEscape(bieuThuc)
}

// DSNMayChu là chuỗi kết nối tới MÁY CHỦ MySQL mà không chọn database nào.
//
// Cần đúng một chỗ: lúc tạo database control plane. DSN() ở trên nhét sẵn tên
// database vào, mà database chưa tồn tại thì ngay bước kết nối đã hỏng — không
// tới được lệnh CREATE DATABASE. Xem cmd/migrate.
func (d DatabaseConfig) DSNMayChu() string {
	return fmt.Sprintf(
		"%s:%s@tcp(%s:%s)/?charset=utf8mb4&parseTime=True&loc=Local%s",
		d.User, d.Password, d.Host, d.Port, d.thamSoSQLMode(),
	)
}

type JWTConfig struct {
	Secret     string
	AccessTTL  time.Duration
	RefreshTTL time.Duration
}

type CORSConfig struct {
	AllowOrigins []string
}

// RateLimitConfig bật/tắt hạn mức gọi API theo IP.
//
// Chỉ có một công tắc chung, còn hạn mức của từng nhóm endpoint đặt thẳng trong
// router (xem router.New): mỗi nhóm chịu đựng khác nhau nên nhét ra .env thành
// một rừng biến mà chẳng ai chỉnh. Công tắc này để tắt lúc chạy máy cục bộ —
// mọi lượt gọi ở đó đều mang IP 127.0.0.1 nên dùng chung một hạn mức, ngồi thử
// đi thử lại một lúc là tự chặn chính mình.
type RateLimitConfig struct {
	Enabled bool
}

// MailConfig cấu hình gửi email (SMTP). Với Gmail dùng mật khẩu ứng dụng 16 ký tự.
type MailConfig struct {
	Host        string
	Port        string
	Username    string
	Password    string
	FromName    string
	FromAddress string
	StoreURL    string        // link về cửa hàng, chèn trong email
	CodeTTL     time.Duration // hạn dùng mã xác thực
	ResendAfter time.Duration // phải chờ bao lâu mới được gửi lại mã
}

// Enabled cho biết đã cấu hình đủ để gửi thư thật hay chưa.
func (m MailConfig) Enabled() bool {
	return m.Host != "" && m.Username != "" && m.Password != ""
}

// Addr trả về host:port cho net/smtp.
func (m MailConfig) Addr() string { return m.Host + ":" + m.Port }

// PayOSConfig là bộ khoá kênh thanh toán PayOS (payos.vn) và các đường dẫn cổng
// dùng để đưa khách quay lại website sau khi trả tiền.
//
// Ba khoá đầu là BÍ MẬT nên nằm ở .env chứ không phải bảng settings: bảng đó bị
// đọc ra qua API cấu hình, mà lộ checksum key thì bất kỳ ai cũng ký được webhook
// giả báo "đã thu tiền".
type PayOSConfig struct {
	ClientID    string
	APIKey      string
	ChecksumKey string
	// BaseURL để trỏ sang máy chủ khác khi PayOS đổi tên miền; bình thường giữ
	// nguyên mặc định.
	BaseURL string
	// ReturnURL / CancelURL là trang của STOREFRONT (không phải của API): PayOS
	// đưa khách về đó kèm query orderCode, status, cancel.
	ReturnURL string
	CancelURL string
	// Expire là hạn của một link thanh toán. Có hạn thì hàng không bị treo vô thời
	// hạn trong đơn chưa trả tiền; hết hạn khách vẫn tạo lại link mới được.
	Expire time.Duration
}

// Enabled cho biết đã khai đủ khoá để gọi PayOS thật hay chưa. Thiếu bất kỳ khoá
// nào thì coi như chưa bật — thà ẩn hình thức thanh toán đi còn hơn để khách chọn
// rồi nhận lỗi ở bước cuối.
func (p PayOSConfig) Enabled() bool {
	return p.ClientID != "" && p.APIKey != "" && p.ChecksumKey != ""
}

// SePayConfig là cấu hình cổng SePay (sepay.vn).
//
// SePay làm việc khác PayOS về bản chất: nó KHÔNG cấp link thanh toán mà đọc biến
// động số dư của chính tài khoản ngân hàng cửa hàng rồi bắn webhook về. Vì vậy ở
// đây không có "khoá gọi API để tạo đơn", mà là SỐ TÀI KHOẢN nhận tiền + khoá để
// nhận biết webhook nào thật.
//
// Đối soát dựa vào NỘI DUNG chuyển khoản: khách chuyển kèm mã đơn, hệ thống dò mã
// đó trong nội dung giao dịch để biết tiền thuộc đơn nào.
type SePayConfig struct {
	// AccountNumber / Bank / AccountName là tài khoản nhận tiền. Bank là mã ngắn
	// theo quy ước của SePay (VD: MBBank, Vietcombank, TPBank, ACB).
	AccountNumber string
	Bank          string
	AccountName   string
	// WebhookAPIKey là khoá SePay gắn vào header `Authorization: Apikey <khoá>`
	// của mỗi webhook. Bỏ trống = KHÔNG nhận webhook (mọi gói bị từ chối), chứ
	// không phải nhận mà khỏi kiểm tra.
	WebhookAPIKey string
	// APIToken dùng cho API tra cứu giao dịch của SePay (my.sepay.vn/userapi) —
	// đường CHỦ ĐỘNG dò sao kê, chạy được cả khi máy chủ không có địa chỉ công khai.
	//
	// Phải có ít nhất một trong hai (khoá webhook hoặc token này) thì mới biết được
	// khách đã trả tiền hay chưa. Ở máy local thì token là đường duy nhất.
	APIToken string
	// APIBaseURL để trỏ sang máy chủ khác khi SePay đổi tên miền.
	APIBaseURL string
	// QRBaseURL là dịch vụ vẽ ảnh QR của SePay.
	QRBaseURL string
}

// Enabled cho biết đã khai đủ để NHẬN tiền qua SePay hay chưa.
//
// Cần tài khoản nhận tiền, và ít nhất MỘT đường biết được tiền đã về:
//   - khoá webhook: SePay chủ động báo sang (cần máy chủ có địa chỉ công khai), hoặc
//   - API token: mình chủ động dò sao kê (chạy được cả ở máy local).
//
// Thiếu cả hai thì mã QR vẫn vẽ ra được nhưng không ai biết khách đã trả tiền hay
// chưa — đúng bằng việc dán ảnh QR tĩnh lên trang, nên coi như chưa bật.
func (s SePayConfig) Enabled() bool {
	return s.AccountNumber != "" && s.Bank != "" && (s.WebhookAPIKey != "" || s.APIToken != "")
}

// WebhookEnabled cho biết có nhận webhook của SePay không.
//
// Không khai khoá = KHÔNG nhận, chứ không phải "nhận mà khỏi kiểm tra": thiếu khoá
// mà vẫn mở cửa thì bất kỳ ai biết địa chỉ webhook cũng tự báo được "đơn X đã
// thanh toán" và lấy hàng miễn phí.
func (s SePayConfig) WebhookEnabled() bool { return s.WebhookAPIKey != "" }

// CanQuery cho biết có tra cứu chủ động sang SePay được không (cần API token).
func (s SePayConfig) CanQuery() bool { return s.AccountNumber != "" && s.APIToken != "" }

// FacebookConfig là bộ khoá ứng dụng Facebook Login (developers.facebook.com).
//
// AppSecret nằm ở .env chứ không phải bảng settings, và CHỈ backend giữ: ai có nó
// thì đổi được `code` lấy token của bất kỳ khách nào vừa bấm đăng nhập. Storefront
// chỉ cần AppID (vốn công khai trong URL hộp thoại đăng nhập).
type FacebookConfig struct {
	AppID     string
	AppSecret string
	// BaseURL / Version để nâng phiên bản Graph API mà không phải sửa mã nguồn.
	BaseURL string
	Version string
}

// Enabled cho biết đã khai đủ khoá để đăng nhập Facebook thật hay chưa. Thiếu khoá
// thì storefront ẩn hẳn nút đi, thà không có còn hơn bấm vào là lỗi.
func (f FacebookConfig) Enabled() bool { return f.AppID != "" && f.AppSecret != "" }

// GoogleConfig là bộ khoá OAuth của Google Sign-In (console.cloud.google.com).
//
// Cũng như Facebook: ClientSecret CHỈ backend giữ, vì nó là thứ đổi `code` lấy
// token của khách. Storefront chỉ cần ClientID (vốn công khai trên URL màn hình
// chọn tài khoản).
type GoogleConfig struct {
	ClientID     string
	ClientSecret string
	// TokenURL / UserInfoURL để đổi endpoint mà không phải sửa mã nguồn.
	TokenURL    string
	UserInfoURL string
}

// Enabled cho biết đã khai đủ khoá để đăng nhập Google thật hay chưa.
func (g GoogleConfig) Enabled() bool { return g.ClientID != "" && g.ClientSecret != "" }

// Load đọc cấu hình theo thứ tự ưu tiên: biến môi trường > file .env > mặc định.
func Load() (*Config, error) {
	v := viper.New()

	// Mặc định
	v.SetDefault("APP_NAME", "Sass API")
	// Mặc định "order" — phần mềm duy nhất đang bán. Bản cài cũ không có dòng
	// APP_CODE trong .env vẫn chạy đúng như trước.
	v.SetDefault("APP_CODE", "order")
	// Mặc định TẮT: dự án này chỉ có khu quản trị, không có trang bán cho khách.
	v.SetDefault("STOREFRONT_API_ENABLED", false)
	v.SetDefault("APP_ENV", "development")
	v.SetDefault("APP_PORT", "8080")
	v.SetDefault("APP_BASE_URL", "http://localhost:8080")
	v.SetDefault("DB_HOST", "127.0.0.1")
	v.SetDefault("DB_PORT", "3306")
	v.SetDefault("DB_USER", "root")
	v.SetDefault("DB_PASSWORD", "")
	v.SetDefault("DB_NAME", "sass_shop")
	v.SetDefault("DB_MAX_OPEN_CONNS", 50)
	v.SetDefault("DB_MAX_IDLE_CONNS", 10)
	v.SetDefault("DB_CONN_MAX_LIFETIME", "1h")
	v.SetDefault("DB_CONN_MAX_IDLE_TIME", "60s")
	// Control plane. Chỉ TÊN database là bắt buộc khác; host/cổng/tài khoản để
	// trống thì dùng lại đúng của DB_* — hai lược đồ hiện nằm cùng một máy chủ
	// MySQL, bắt khai lại 4 biến y hệt chỉ tạo thêm chỗ để gõ sai. Tách máy chủ
	// hoặc dùng tài khoản MySQL riêng (chỉ nên đọc được sổ nền tảng) thì khai
	// đè bằng PLATFORM_DB_HOST / PLATFORM_DB_USER...
	v.SetDefault("PLATFORM_DB_HOST", "")
	v.SetDefault("PLATFORM_DB_PORT", "")
	v.SetDefault("PLATFORM_DB_USER", "")
	v.SetDefault("PLATFORM_DB_PASSWORD", "")
	v.SetDefault("PLATFORM_DB_NAME", "selliotech_platform")
	// Khoá mã hoá những giá trị KHÔNG được nằm nguyên văn trong database — hôm
	// nay là khoá cổng thanh toán của nền tảng (xem pkg/bimat). Rỗng = chưa khai:
	// API vẫn chạy, chỉ là màn hình cài đặt TỪ CHỐI lưu mấy ô bí mật và nói rõ
	// lý do. Từ chối chứ không ghi plaintext.
	v.SetDefault("PLATFORM_SECRET_KEY", "")
	// Gốc địa chỉ Shop Admin — nơi cổng thanh toán đưa khách quay về sau khi trả
	// tiền gia hạn. Máy chủ tự dựng returnUrl từ đây chứ KHÔNG nhận từ client:
	// để client tự khai địa chỉ quay về là mở một đường chuyển hướng sang bất cứ
	// đâu, ký sẵn bằng tên miền của mình.
	v.SetDefault("SHOP_ADMIN_URL", "http://localhost:8001")
	// Pool nhỏ hơn hẳn DB_*: control plane phục vụ khu điều hành (vài người) và
	// vài lượt tra sổ, không phải luồng bán hàng. Cấp cho nó 50 kết nối là lấy
	// mất chỗ của chính data plane trong hạn mức max_connections của MySQL.
	v.SetDefault("PLATFORM_DB_MAX_OPEN_CONNS", 10)
	v.SetDefault("PLATFORM_DB_MAX_IDLE_CONNS", 2)
	v.SetDefault("JWT_SECRET", "")
	v.SetDefault("JWT_ACCESS_TTL", "15m")
	v.SetDefault("JWT_REFRESH_TTL", "168h")
	// localhost VÀ 127.0.0.1 là HAI ORIGIN khác nhau với trình duyệt, dù cùng trỏ
	// về một máy. Thiếu bản 127.0.0.1 thì mở Shop Admin bằng địa chỉ số sẽ hỏng
	// đúng một thứ: luồng realtime (/events) — đường DUY NHẤT trình duyệt gọi
	// thẳng sang API, mọi lượt gọi khác đi vòng qua PHP nên không dính CORS.
	// Nghĩa là chuông thông báo im lặng chết, còn các trang vẫn chạy bình thường.
	v.SetDefault("CORS_ALLOW_ORIGINS",
		"http://localhost:8000,http://localhost:8001,http://127.0.0.1:8000,http://127.0.0.1:8001")
	v.SetDefault("TRUSTED_PROXIES", "127.0.0.1,::1")
	v.SetDefault("RATE_LIMIT_ENABLED", true)
	v.SetDefault("MAIL_HOST", "smtp.gmail.com")
	v.SetDefault("MAIL_PORT", "587")
	v.SetDefault("MAIL_USERNAME", "")
	v.SetDefault("MAIL_PASSWORD", "")
	v.SetDefault("MAIL_FROM_NAME", "Jersey House")
	v.SetDefault("MAIL_FROM_ADDRESS", "")
	v.SetDefault("MAIL_STORE_URL", "http://localhost:8000")
	v.SetDefault("MAIL_CODE_TTL", "10m")
	v.SetDefault("MAIL_RESEND_AFTER", "60s")
	v.SetDefault("PAYOS_CLIENT_ID", "")
	v.SetDefault("PAYOS_API_KEY", "")
	v.SetDefault("PAYOS_CHECKSUM_KEY", "")
	v.SetDefault("PAYOS_BASE_URL", "https://api-merchant.payos.vn")
	v.SetDefault("PAYOS_RETURN_URL", "http://localhost:8000/thanh-toan/ket-qua")
	v.SetDefault("PAYOS_CANCEL_URL", "http://localhost:8000/thanh-toan/ket-qua")
	v.SetDefault("PAYOS_EXPIRE", "30m")
	v.SetDefault("SEPAY_ACCOUNT_NUMBER", "")
	v.SetDefault("SEPAY_BANK", "")
	v.SetDefault("SEPAY_ACCOUNT_NAME", "")
	v.SetDefault("SEPAY_WEBHOOK_API_KEY", "")
	v.SetDefault("SEPAY_API_TOKEN", "")
	v.SetDefault("SEPAY_API_BASE_URL", "https://my.sepay.vn")
	v.SetDefault("SEPAY_QR_BASE_URL", "https://qr.sepay.vn")
	v.SetDefault("FACEBOOK_APP_ID", "")
	v.SetDefault("FACEBOOK_APP_SECRET", "")
	v.SetDefault("FACEBOOK_GRAPH_BASE_URL", "https://graph.facebook.com")
	v.SetDefault("FACEBOOK_GRAPH_VERSION", "v21.0")
	v.SetDefault("GOOGLE_CLIENT_ID", "")
	v.SetDefault("GOOGLE_CLIENT_SECRET", "")
	v.SetDefault("GOOGLE_TOKEN_URL", "https://oauth2.googleapis.com/token")
	v.SetDefault("GOOGLE_USERINFO_URL", "https://openidconnect.googleapis.com/v1/userinfo")

	// Đọc file .env nếu có (không bắt buộc)
	v.SetConfigName(".env")
	v.SetConfigType("env")
	v.AddConfigPath(".")
	v.AddConfigPath("..")
	v.AddConfigPath("./api")
	if err := v.ReadInConfig(); err != nil {
		if _, ok := err.(viper.ConfigFileNotFoundError); !ok {
			return nil, fmt.Errorf("đọc file cấu hình: %w", err)
		}
	}
	v.AutomaticEnv()

	cfg := &Config{
		App: AppConfig{
			Name:           v.GetString("APP_NAME"),
			Code:           strings.ToLower(strings.TrimSpace(v.GetString("APP_CODE"))),
			Env:            v.GetString("APP_ENV"),
			Port:           v.GetString("APP_PORT"),
			BaseURL:        v.GetString("APP_BASE_URL"),
			TrustedProxies: splitAndTrim(v.GetString("TRUSTED_PROXIES")),

			EnableStorefront: v.GetBool("STOREFRONT_API_ENABLED"),
		},
		Database: DatabaseConfig{
			Host:            v.GetString("DB_HOST"),
			Port:            v.GetString("DB_PORT"),
			User:            v.GetString("DB_USER"),
			Password:        v.GetString("DB_PASSWORD"),
			Name:            v.GetString("DB_NAME"),
			MaxOpenConns:    v.GetInt("DB_MAX_OPEN_CONNS"),
			MaxIdleConns:    v.GetInt("DB_MAX_IDLE_CONNS"),
			ConnMaxLifetime: v.GetDuration("DB_CONN_MAX_LIFETIME"),
			ConnMaxIdleTime: v.GetDuration("DB_CONN_MAX_IDLE_TIME"),
		},
		JWT: JWTConfig{
			Secret:     v.GetString("JWT_SECRET"),
			AccessTTL:  v.GetDuration("JWT_ACCESS_TTL"),
			RefreshTTL: v.GetDuration("JWT_REFRESH_TTL"),
		},
		CORS: CORSConfig{
			AllowOrigins: splitAndTrim(v.GetString("CORS_ALLOW_ORIGINS")),
		},
		RateLimit: RateLimitConfig{
			Enabled: v.GetBool("RATE_LIMIT_ENABLED"),
		},
		Mail: MailConfig{
			Host: v.GetString("MAIL_HOST"),
			Port: v.GetString("MAIL_PORT"),
			// Mật khẩu ứng dụng Gmail hay được copy kèm khoảng trắng ("abcd efgh ...")
			Username:    strings.TrimSpace(v.GetString("MAIL_USERNAME")),
			Password:    strings.ReplaceAll(v.GetString("MAIL_PASSWORD"), " ", ""),
			FromName:    v.GetString("MAIL_FROM_NAME"),
			FromAddress: strings.TrimSpace(v.GetString("MAIL_FROM_ADDRESS")),
			StoreURL:    v.GetString("MAIL_STORE_URL"),
			CodeTTL:     v.GetDuration("MAIL_CODE_TTL"),
			ResendAfter: v.GetDuration("MAIL_RESEND_AFTER"),
		},
		PayOS: PayOSConfig{
			// Khoá dán từ trang quản lý PayOS hay dính khoảng trắng đầu/cuối, mà một
			// ký tự thừa trong checksum key thì mọi chữ ký đều sai và lỗi hiện ra là
			// "signature không hợp lệ" — rất khó lần ra nguyên nhân.
			ClientID:    strings.TrimSpace(v.GetString("PAYOS_CLIENT_ID")),
			APIKey:      strings.TrimSpace(v.GetString("PAYOS_API_KEY")),
			ChecksumKey: strings.TrimSpace(v.GetString("PAYOS_CHECKSUM_KEY")),
			BaseURL:     strings.TrimRight(strings.TrimSpace(v.GetString("PAYOS_BASE_URL")), "/"),
			ReturnURL:   strings.TrimSpace(v.GetString("PAYOS_RETURN_URL")),
			CancelURL:   strings.TrimSpace(v.GetString("PAYOS_CANCEL_URL")),
			Expire:      v.GetDuration("PAYOS_EXPIRE"),
		},
		SePay: SePayConfig{
			AccountNumber: strings.TrimSpace(v.GetString("SEPAY_ACCOUNT_NUMBER")),
			Bank:          strings.TrimSpace(v.GetString("SEPAY_BANK")),
			AccountName:   strings.TrimSpace(v.GetString("SEPAY_ACCOUNT_NAME")),
			WebhookAPIKey: strings.TrimSpace(v.GetString("SEPAY_WEBHOOK_API_KEY")),
			APIToken:      strings.TrimSpace(v.GetString("SEPAY_API_TOKEN")),
			APIBaseURL:    strings.TrimRight(strings.TrimSpace(v.GetString("SEPAY_API_BASE_URL")), "/"),
			QRBaseURL:     strings.TrimRight(strings.TrimSpace(v.GetString("SEPAY_QR_BASE_URL")), "/"),
		},
		Facebook: FacebookConfig{
			// App secret dán từ trang quản lý hay dính khoảng trắng đầu/cuối; thừa một
			// ký tự là mọi lần đổi code đều trả "Invalid OAuth access token".
			AppID:     strings.TrimSpace(v.GetString("FACEBOOK_APP_ID")),
			AppSecret: strings.TrimSpace(v.GetString("FACEBOOK_APP_SECRET")),
			BaseURL:   strings.TrimRight(strings.TrimSpace(v.GetString("FACEBOOK_GRAPH_BASE_URL")), "/"),
			Version:   strings.Trim(strings.TrimSpace(v.GetString("FACEBOOK_GRAPH_VERSION")), "/"),
		},
		Google: GoogleConfig{
			// Client secret dán từ console hay dính khoảng trắng đầu/cuối; thừa một ký
			// tự là mọi lần đổi code đều trả "invalid_client".
			ClientID:     strings.TrimSpace(v.GetString("GOOGLE_CLIENT_ID")),
			ClientSecret: strings.TrimSpace(v.GetString("GOOGLE_CLIENT_SECRET")),
			TokenURL:     strings.TrimSpace(v.GetString("GOOGLE_TOKEN_URL")),
			UserInfoURL:  strings.TrimSpace(v.GetString("GOOGLE_USERINFO_URL")),
		},
	}

	// Control plane: chép cấu hình của data plane rồi mới đè những gì được khai
	// riêng. Làm sau khi cfg.Database đã dựng xong để "để trống = giống DB_*"
	// đúng cả với giá trị lấy từ biến môi trường lẫn từ .env.
	cfg.Platform = cfg.Database
	cfg.Platform.Name = strings.TrimSpace(v.GetString("PLATFORM_DB_NAME"))
	cfg.Platform.SecretKey = strings.TrimSpace(v.GetString("PLATFORM_SECRET_KEY"))
	cfg.App.ShopAdminURL = strings.TrimRight(strings.TrimSpace(v.GetString("SHOP_ADMIN_URL")), "/")
	cfg.Platform.MaxOpenConns = v.GetInt("PLATFORM_DB_MAX_OPEN_CONNS")
	cfg.Platform.MaxIdleConns = v.GetInt("PLATFORM_DB_MAX_IDLE_CONNS")
	if s := strings.TrimSpace(v.GetString("PLATFORM_DB_HOST")); s != "" {
		cfg.Platform.Host = s
	}
	if s := strings.TrimSpace(v.GetString("PLATFORM_DB_PORT")); s != "" {
		cfg.Platform.Port = s
	}
	if s := strings.TrimSpace(v.GetString("PLATFORM_DB_USER")); s != "" {
		cfg.Platform.User = s
		// Mật khẩu đi LIỀN với tài khoản: khai user riêng mà để trống mật khẩu
		// thì lấy mật khẩu của tài khoản kia là sai chắc chắn, nên xoá đi.
		cfg.Platform.Password = v.GetString("PLATFORM_DB_PASSWORD")
	} else if s := v.GetString("PLATFORM_DB_PASSWORD"); s != "" {
		cfg.Platform.Password = s
	}

	// Hai lược đồ trỏ vào cùng một database là hỏng âm thầm: bảng `tenants` của
	// control plane sẽ đè lên bảng `tenants` của dữ liệu bán hàng, mà cả hai đều
	// tên đó. Chặn ngay lúc nạp cấu hình chứ không đợi migration chạy vào.
	if cfg.Platform.Name == "" {
		return nil, fmt.Errorf("PLATFORM_DB_NAME không được để trống — control plane phải là database riêng")
	}
	if cfg.Platform.Name == cfg.Database.Name &&
		cfg.Platform.Host == cfg.Database.Host && cfg.Platform.Port == cfg.Database.Port {
		return nil, fmt.Errorf(
			"PLATFORM_DB_NAME (%s) trùng DB_NAME — control plane phải nằm ở database RIÊNG, "+
				"không phải vài bảng thêm vào database bán hàng", cfg.Platform.Name)
	}

	// Không khai báo FROM riêng thì dùng chính tài khoản SMTP
	if cfg.Mail.FromAddress == "" {
		cfg.Mail.FromAddress = cfg.Mail.Username
	}

	// Huỷ giữa chừng cũng về đúng trang kết quả: trang đó đọc query của PayOS và tự
	// phân biệt đã trả tiền hay đã huỷ, nên không cần khai hai đường dẫn khác nhau.
	if cfg.PayOS.CancelURL == "" {
		cfg.PayOS.CancelURL = cfg.PayOS.ReturnURL
	}

	if cfg.JWT.Secret == "" {
		return nil, fmt.Errorf("JWT_SECRET không được để trống")
	}
	return cfg, nil
}

// splitAndTrim tách chuỗi theo dấu phẩy và loại bỏ khoảng trắng thừa.
func splitAndTrim(s string) []string {
	if strings.TrimSpace(s) == "" {
		return nil
	}
	parts := strings.Split(s, ",")
	out := make([]string, 0, len(parts))
	for _, p := range parts {
		if p = strings.TrimSpace(p); p != "" {
			out = append(out, p)
		}
	}
	return out
}
