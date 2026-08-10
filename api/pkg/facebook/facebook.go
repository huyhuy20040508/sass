// Package facebook là client cho Facebook Login: đổi `code` (khách vừa bấm đồng ý
// trên hộp thoại của Facebook) lấy access token, rồi đọc hồ sơ công khai.
//
// VÌ SAO PHẢI TỰ ĐỔI CODE Ở ĐÂY thay vì nhận sẵn hồ sơ từ storefront: chỉ có phía
// giữ app secret mới đổi được code, nên hồ sơ lấy về chắc chắn đến từ Facebook chứ
// không phải do người gọi bịa ra. Nhận "email + facebook_id" từ trình duyệt rồi tin
// luôn thì bất kỳ ai cũng đăng nhập được vào tài khoản của người khác.
//
// Kể cả khi đã có token, verify vẫn đối chiếu app_id qua debug_token: token hợp lệ
// nhưng do MỘT APP KHÁC cấp cũng phải bị từ chối (lỗi "confused deputy").
package facebook

import (
	"context"
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
	// ErrNotConfigured — chưa khai FACEBOOK_APP_ID / FACEBOOK_APP_SECRET.
	ErrNotConfigured = errors.New("facebook: chưa cấu hình app id / app secret")
	// ErrInvalidToken — Facebook từ chối code/token, hoặc token do app khác cấp.
	ErrInvalidToken = errors.New("facebook: mã đăng nhập không hợp lệ hoặc đã hết hạn")
)

// Profile là phần hồ sơ công khai cần cho việc tạo tài khoản.
// Email CÓ THỂ RỖNG: khách đăng ký Facebook bằng số điện thoại, hoặc bỏ tick quyền
// email ở hộp thoại đăng nhập.
type Profile struct {
	ID      string
	Name    string
	Email   string
	Picture string
}

// Client gọi Graph API. Dùng lại một instance cho cả vòng đời ứng dụng.
type Client struct {
	cfg  config.FacebookConfig
	http *http.Client
}

// New dựng client từ cấu hình. Trả về client kể cả khi chưa khai khoá — mọi lời
// gọi sẽ trả ErrNotConfigured, để phần còn lại của ứng dụng không phải kiểm tra nil.
func New(cfg config.FacebookConfig) *Client {
	return &Client{
		cfg: cfg,
		// Lời gọi này nằm ngay trên đường đăng nhập của khách: Facebook chậm thì
		// khách phải nhận câu trả lời chứ không ngồi đợi vô hạn.
		http: &http.Client{Timeout: 15 * time.Second},
	}
}

// Enabled cho biết đã đủ khoá để gọi thật hay chưa.
func (c *Client) Enabled() bool { return c.cfg.Enabled() }

// LoginWithCode chạy trọn vòng: code → access token → kiểm token → hồ sơ.
// redirectURI phải TRÙNG TỪNG KÝ TỰ với cái đã dùng lúc mở hộp thoại đăng nhập,
// nếu không Facebook trả lỗi redirect_uri mismatch.
func (c *Client) LoginWithCode(ctx context.Context, code, redirectURI string) (*Profile, error) {
	if !c.Enabled() {
		return nil, ErrNotConfigured
	}

	token, err := c.exchangeCode(ctx, code, redirectURI)
	if err != nil {
		return nil, err
	}
	if err := c.verifyToken(ctx, token); err != nil {
		return nil, err
	}
	return c.profile(ctx, token)
}

// exchangeCode đổi authorization code lấy access token của người dùng.
func (c *Client) exchangeCode(ctx context.Context, code, redirectURI string) (string, error) {
	q := url.Values{
		"client_id":     {c.cfg.AppID},
		"client_secret": {c.cfg.AppSecret},
		"redirect_uri":  {redirectURI},
		"code":          {code},
	}

	var out struct {
		AccessToken string `json:"access_token"`
	}
	if err := c.get(ctx, "/oauth/access_token", q, &out); err != nil {
		return "", err
	}
	if out.AccessToken == "" {
		return "", ErrInvalidToken
	}
	return out.AccessToken, nil
}

// verifyToken hỏi Facebook xem token còn sống và ĐÚNG LÀ của app này hay không.
func (c *Client) verifyToken(ctx context.Context, token string) error {
	q := url.Values{
		"input_token": {token},
		// Token cấp ứng dụng: "<app-id>|<app-secret>"
		"access_token": {c.cfg.AppID + "|" + c.cfg.AppSecret},
	}

	var out struct {
		Data struct {
			AppID   string `json:"app_id"`
			IsValid bool   `json:"is_valid"`
			UserID  string `json:"user_id"`
		} `json:"data"`
	}
	if err := c.get(ctx, "/debug_token", q, &out); err != nil {
		return err
	}
	if !out.Data.IsValid || out.Data.UserID == "" {
		return ErrInvalidToken
	}
	// Token của app khác: hợp lệ với Facebook nhưng không nói lên điều gì về khách
	// đang đứng trước cửa hàng này.
	if out.Data.AppID != c.cfg.AppID {
		return fmt.Errorf("%w (token của app %s)", ErrInvalidToken, out.Data.AppID)
	}
	return nil
}

// profile đọc hồ sơ công khai bằng token của chính người dùng.
func (c *Client) profile(ctx context.Context, token string) (*Profile, error) {
	q := url.Values{
		"fields":       {"id,name,email,picture.width(320).height(320)"},
		"access_token": {token},
	}

	var out struct {
		ID      string `json:"id"`
		Name    string `json:"name"`
		Email   string `json:"email"`
		Picture struct {
			Data struct {
				URL          string `json:"url"`
				IsSilhouette bool   `json:"is_silhouette"`
			} `json:"data"`
		} `json:"picture"`
	}
	if err := c.get(ctx, "/me", q, &out); err != nil {
		return nil, err
	}
	if out.ID == "" {
		return nil, ErrInvalidToken
	}

	p := &Profile{
		ID:    out.ID,
		Name:  strings.TrimSpace(out.Name),
		Email: strings.TrimSpace(out.Email),
	}
	// Ảnh mặc định (bóng người xám) thì thà để trống, giao diện tự dựng chữ cái đầu.
	if !out.Picture.Data.IsSilhouette {
		p.Picture = out.Picture.Data.URL
	}
	return p, nil
}

// get gọi Graph API và giải mã JSON vào out. Lỗi nghiệp vụ do Facebook trả về
// (sai code, token hết hạn) được quy về ErrInvalidToken kèm mô tả gốc để ghi log.
func (c *Client) get(ctx context.Context, path string, q url.Values, out any) error {
	endpoint := fmt.Sprintf("%s/%s%s?%s", c.cfg.BaseURL, c.cfg.Version, path, q.Encode())

	req, err := http.NewRequestWithContext(ctx, http.MethodGet, endpoint, nil)
	if err != nil {
		return err
	}
	res, err := c.http.Do(req)
	if err != nil {
		return fmt.Errorf("facebook: gọi %s thất bại: %w", path, err)
	}
	defer res.Body.Close()

	body, err := io.ReadAll(io.LimitReader(res.Body, 1<<20))
	if err != nil {
		return fmt.Errorf("facebook: đọc phản hồi %s: %w", path, err)
	}

	if res.StatusCode != http.StatusOK {
		var e struct {
			Error struct {
				Message string `json:"message"`
				Type    string `json:"type"`
				Code    int    `json:"code"`
			} `json:"error"`
		}
		_ = json.Unmarshal(body, &e)
		if e.Error.Message != "" {
			return fmt.Errorf("%w: %s", ErrInvalidToken, e.Error.Message)
		}
		return fmt.Errorf("%w: HTTP %d", ErrInvalidToken, res.StatusCode)
	}

	if err := json.Unmarshal(body, out); err != nil {
		return fmt.Errorf("facebook: phản hồi %s không đọc được: %w", path, err)
	}
	return nil
}
