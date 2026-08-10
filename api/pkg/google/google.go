// Package google là client cho Google Sign-In (OAuth 2.0 / OpenID Connect): đổi
// `code` (khách vừa bấm đồng ý ở màn hình chọn tài khoản Google) lấy token, rồi
// đọc hồ sơ.
//
// VÌ SAO PHẢI TỰ ĐỔI CODE Ở ĐÂY thay vì nhận sẵn hồ sơ từ storefront: chỉ bên giữ
// client secret mới đổi được code, nên hồ sơ lấy về chắc chắn đến từ Google chứ
// không phải do người gọi bịa ra. Nhận "email + google_id" từ trình duyệt rồi tin
// luôn thì bất kỳ ai cũng đăng nhập được vào tài khoản của người khác.
//
// Hồ sơ lấy từ `id_token` trả kèm trong chính lời đáp của token endpoint. Token đó
// đi thẳng từ máy chủ Google về đây qua TLS, đổi bằng client secret, nên KHÔNG cần
// kiểm chữ ký (đúng theo tài liệu Google: chỉ token nhận gián tiếp qua trình duyệt
// mới phải xác minh chữ ký). Vẫn đối chiếu `aud` để token do MỘT APP KHÁC cấp
// không lọt vào được — lỗi "confused deputy".
package google

import (
	"context"
	"encoding/base64"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"strings"
	"time"

	"sass-api/config"
)

var (
	// ErrNotConfigured — chưa khai GOOGLE_CLIENT_ID / GOOGLE_CLIENT_SECRET.
	ErrNotConfigured = errors.New("google: chưa cấu hình client id / client secret")
	// ErrInvalidToken — Google từ chối code, token hết hạn, hoặc token do app khác cấp.
	ErrInvalidToken = errors.New("google: mã đăng nhập không hợp lệ hoặc đã hết hạn")
)

// Profile là phần hồ sơ cần cho việc tạo tài khoản.
//
// EmailVerified rất quan trọng: tài khoản Google Workspace tự quản lý tên miền có
// thể khai email chưa được Google xác minh. Ghép tài khoản cửa hàng theo email đó
// là mở đường cho người khác chiếm tài khoản của khách, nên tầng trên bắt buộc
// phải kiểm cờ này.
type Profile struct {
	ID            string
	Name          string
	Email         string
	EmailVerified bool
	Picture       string
}

// Client gọi các endpoint OAuth của Google. Dùng lại một instance cho cả vòng đời.
type Client struct {
	cfg  config.GoogleConfig
	http *http.Client
}

// New dựng client từ cấu hình. Trả về client kể cả khi chưa khai khoá — mọi lời
// gọi sẽ trả ErrNotConfigured, để phần còn lại của ứng dụng không phải kiểm tra nil.
func New(cfg config.GoogleConfig) *Client {
	return &Client{
		cfg: cfg,
		// Lời gọi này nằm ngay trên đường đăng nhập của khách: Google chậm thì khách
		// phải nhận câu trả lời chứ không ngồi đợi vô hạn.
		http: &http.Client{Timeout: 15 * time.Second},
	}
}

// Enabled cho biết đã đủ khoá để gọi thật hay chưa.
func (c *Client) Enabled() bool { return c.cfg.Enabled() }

// LoginWithCode chạy trọn vòng: code → token → hồ sơ.
// redirectURI phải TRÙNG TỪNG KÝ TỰ với cái đã dùng lúc mở màn hình chọn tài khoản,
// nếu không Google trả lỗi redirect_uri_mismatch.
func (c *Client) LoginWithCode(ctx context.Context, code, redirectURI string) (*Profile, error) {
	if !c.Enabled() {
		return nil, ErrNotConfigured
	}

	tok, err := c.exchangeCode(ctx, code, redirectURI)
	if err != nil {
		return nil, err
	}

	prof, err := c.profileFromIDToken(tok.IDToken)
	if err != nil {
		return nil, err
	}

	// id_token thiếu email (hiếm: xin thiếu scope `email`) thì hỏi thêm userinfo
	// bằng chính access token của khách.
	if prof.Email == "" && tok.AccessToken != "" {
		if p2, err2 := c.userInfo(ctx, tok.AccessToken); err2 == nil && p2.ID == prof.ID {
			prof.Email = p2.Email
			prof.EmailVerified = p2.EmailVerified
			if prof.Name == "" {
				prof.Name = p2.Name
			}
			if prof.Picture == "" {
				prof.Picture = p2.Picture
			}
		}
	}
	return prof, nil
}

type tokenResponse struct {
	AccessToken string `json:"access_token"`
	IDToken     string `json:"id_token"`
}

// exchangeCode đổi authorization code lấy access token + id token.
func (c *Client) exchangeCode(ctx context.Context, code, redirectURI string) (*tokenResponse, error) {
	form := url.Values{
		"client_id":     {c.cfg.ClientID},
		"client_secret": {c.cfg.ClientSecret},
		"redirect_uri":  {redirectURI},
		"code":          {code},
		"grant_type":    {"authorization_code"},
	}

	req, err := http.NewRequestWithContext(ctx, http.MethodPost, c.cfg.TokenURL, strings.NewReader(form.Encode()))
	if err != nil {
		return nil, err
	}
	req.Header.Set("Content-Type", "application/x-www-form-urlencoded")

	var out tokenResponse
	if err := c.do(req, "token", &out); err != nil {
		return nil, err
	}
	if out.IDToken == "" {
		return nil, ErrInvalidToken
	}
	return &out, nil
}

// idClaims là phần thân của id_token (JWT) mà cửa hàng dùng tới.
type idClaims struct {
	Iss           string  `json:"iss"`
	Aud           string  `json:"aud"`
	Sub           string  `json:"sub"`
	Exp           int64   `json:"exp"`
	Email         string  `json:"email"`
	EmailVerified boolish `json:"email_verified"`
	Name          string  `json:"name"`
	Picture       string  `json:"picture"`
}

// boolish nhận cả `true` lẫn `"true"`: id_token trả kiểu bool, còn vài endpoint cũ
// của Google trả cùng trường đó dưới dạng chuỗi.
type boolish bool

func (b *boolish) UnmarshalJSON(p []byte) error {
	*b = boolish(strings.Trim(string(p), `"`) == "true")
	return nil
}

// googleIssuers là hai giá trị `iss` hợp lệ mà Google phát hành.
var googleIssuers = []string{"accounts.google.com", "https://accounts.google.com"}

// profileFromIDToken đọc thân JWT và đối chiếu aud/iss/exp.
//
// Không kiểm chữ ký: token này lấy trực tiếp từ token endpoint qua TLS bằng client
// secret, không đi vòng qua trình duyệt nên không ai chen vào giữa được.
func (c *Client) profileFromIDToken(idToken string) (*Profile, error) {
	parts := strings.Split(idToken, ".")
	if len(parts) != 3 {
		return nil, ErrInvalidToken
	}
	// JWT dùng base64url và cắt bỏ dấu `=` ở cuối.
	body, err := base64.RawURLEncoding.DecodeString(parts[1])
	if err != nil {
		return nil, fmt.Errorf("%w: id_token không đọc được", ErrInvalidToken)
	}

	var cl idClaims
	if err := json.Unmarshal(body, &cl); err != nil {
		return nil, fmt.Errorf("%w: id_token không đọc được", ErrInvalidToken)
	}

	if cl.Sub == "" {
		return nil, ErrInvalidToken
	}
	// Token của app khác: hợp lệ với Google nhưng không nói lên điều gì về khách
	// đang đứng trước cửa hàng này.
	if cl.Aud != c.cfg.ClientID {
		return nil, fmt.Errorf("%w (token của app %s)", ErrInvalidToken, cl.Aud)
	}
	if !containsString(googleIssuers, cl.Iss) {
		return nil, fmt.Errorf("%w (iss lạ: %s)", ErrInvalidToken, cl.Iss)
	}
	if cl.Exp > 0 && time.Now().After(time.Unix(cl.Exp, 0)) {
		return nil, fmt.Errorf("%w (id_token đã hết hạn)", ErrInvalidToken)
	}

	return &Profile{
		ID:            cl.Sub,
		Name:          strings.TrimSpace(cl.Name),
		Email:         strings.TrimSpace(cl.Email),
		EmailVerified: bool(cl.EmailVerified),
		Picture:       strings.TrimSpace(cl.Picture),
	}, nil
}

// userInfo đọc hồ sơ bằng access token của chính người dùng. Chỉ dùng làm đường lùi
// khi id_token không có email.
func (c *Client) userInfo(ctx context.Context, accessToken string) (*Profile, error) {
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, c.cfg.UserInfoURL, nil)
	if err != nil {
		return nil, err
	}
	req.Header.Set("Authorization", "Bearer "+accessToken)

	var out struct {
		Sub           string  `json:"sub"`
		Name          string  `json:"name"`
		Email         string  `json:"email"`
		EmailVerified boolish `json:"email_verified"`
		Picture       string  `json:"picture"`
	}
	if err := c.do(req, "userinfo", &out); err != nil {
		return nil, err
	}
	if out.Sub == "" {
		return nil, ErrInvalidToken
	}
	return &Profile{
		ID:            out.Sub,
		Name:          strings.TrimSpace(out.Name),
		Email:         strings.TrimSpace(out.Email),
		EmailVerified: bool(out.EmailVerified),
		Picture:       strings.TrimSpace(out.Picture),
	}, nil
}

// do gọi Google và giải mã JSON vào out. Lỗi nghiệp vụ do Google trả về (sai code,
// token hết hạn) được quy về ErrInvalidToken kèm mô tả gốc để ghi log.
func (c *Client) do(req *http.Request, what string, out any) error {
	res, err := c.http.Do(req)
	if err != nil {
		return fmt.Errorf("google: gọi %s thất bại: %w", what, err)
	}
	defer res.Body.Close()

	body, err := io.ReadAll(io.LimitReader(res.Body, 1<<20))
	if err != nil {
		return fmt.Errorf("google: đọc phản hồi %s: %w", what, err)
	}

	if res.StatusCode != http.StatusOK {
		var e struct {
			Error            string `json:"error"`
			ErrorDescription string `json:"error_description"`
		}
		_ = json.Unmarshal(body, &e)
		if e.Error != "" {
			return fmt.Errorf("%w: %s %s", ErrInvalidToken, e.Error, e.ErrorDescription)
		}
		return fmt.Errorf("%w: HTTP %d", ErrInvalidToken, res.StatusCode)
	}

	if err := json.Unmarshal(body, out); err != nil {
		return fmt.Errorf("google: phản hồi %s không đọc được: %w", what, err)
	}
	return nil
}

func containsString(list []string, s string) bool {
	for _, it := range list {
		if it == s {
			return true
		}
	}
	return false
}
