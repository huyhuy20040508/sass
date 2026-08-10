// Package config nạp cấu hình ứng dụng từ biến môi trường / file .env (Viper).
package config

import (
	"fmt"
	"strings"
	"time"

	"github.com/spf13/viper"
)

// Config gom toàn bộ cấu hình ứng dụng.
type Config struct {
	App       AppConfig
	Database  DatabaseConfig
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
	Name    string
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
	// /categories, /brands, /products, /notifications, /events. Xem router.go.
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
}

// DSN trả về chuỗi kết nối MySQL cho GORM.
func (d DatabaseConfig) DSN() string {
	return fmt.Sprintf(
		"%s:%s@tcp(%s:%s)/%s?charset=utf8mb4&parseTime=True&loc=Local",
		d.User, d.Password, d.Host, d.Port, d.Name,
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
	v.SetDefault("JWT_SECRET", "")
	v.SetDefault("JWT_ACCESS_TTL", "15m")
	v.SetDefault("JWT_REFRESH_TTL", "168h")
	v.SetDefault("CORS_ALLOW_ORIGINS", "http://localhost:8000,http://localhost:8001")
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
