// Package jwt sinh và xác thực JSON Web Token (access + refresh).
package jwt

import (
	"errors"
	"time"

	"github.com/golang-jwt/jwt/v5"
)

// TokenType phân biệt access và refresh token.
type TokenType string

const (
	AccessToken  TokenType = "access"
	RefreshToken TokenType = "refresh"
)

var (
	ErrInvalidToken = errors.New("token không hợp lệ")
	ErrExpiredToken = errors.New("token đã hết hạn")
	// ErrMissingTenant — gọi Generate mà không nói token thuộc cửa hàng nào.
	// Lỗi lập trình, không phải lỗi người dùng.
	ErrMissingTenant = errors.New("token phải mang mã cửa hàng")
)

// Claims là payload của token.
type Claims struct {
	UserID uint `json:"uid"`
	// TenantID là CỬA HÀNG mà token này được cấp cho.
	//
	// Nằm trong token chứ không tra lại từ database ở mỗi lượt gọi vì nó là thứ
	// quyết định người cầm token đọc được dữ liệu của ai — mà token thì đã có chữ
	// ký, sửa một chữ số trong đó là chữ ký hỏng ngay. Đọc từ header hay tham số
	// URL thì đổi cửa hàng chỉ là sửa một con số.
	//
	// 0 = token cấp TRƯỚC khi hệ thống có nhiều cửa hàng. Nơi xác thực phải TỪ
	// CHỐI chứ đừng lùi về 1: lùi về là biến mọi token cũ thành chìa khoá vào cửa
	// hàng số 1.
	TenantID uint   `json:"tid"`
	Role     string `json:"role"`
	// Platform = true: đây là token của NGƯỜI ĐIỀU HÀNH NỀN TẢNG, và UserID trỏ
	// vào platform_users chứ không phải users. TenantID của nó LUÔN bằng 0.
	//
	// Hai loại token vì thế loại trừ nhau bằng chính cấu trúc, không phải bằng
	// một dãy điều kiện ai đó phải nhớ viết:
	//
	//   - Token nền tảng KHÔNG dùng được ở khu cửa hàng: JWTAuth từ chối mọi
	//     token có TenantID = 0, và nó từ chối như vậy từ trước khi có cờ này.
	//   - Token cửa hàng KHÔNG dùng được ở khu điều hành: XacThucNenTang đòi cờ
	//     này bằng true.
	//
	// Nhờ vậy một tài khoản của cửa hàng không có đường nào chạm vào bảng giá
	// của nền tảng, kể cả khi nó mang vai trò cao nhất trong cửa hàng của mình.
	Platform bool      `json:"pf,omitempty"`
	Type     TokenType `json:"typ"`
	jwt.RegisteredClaims
}

// Manager quản lý ký/verify token với secret + TTL cấu hình sẵn.
type Manager struct {
	secret     []byte
	accessTTL  time.Duration
	refreshTTL time.Duration
}

func NewManager(secret string, accessTTL, refreshTTL time.Duration) *Manager {
	return &Manager{secret: []byte(secret), accessTTL: accessTTL, refreshTTL: refreshTTL}
}

// Generate tạo token theo loại (access/refresh).
//
// tenantID là bắt buộc và phải khác 0 — token không nói được nó thuộc cửa hàng
// nào thì mọi câu truy vấn chạy bằng nó đều hỏng ở tầng repository, và người
// dùng nhận một lỗi 500 khó hiểu sau khi đăng nhập "thành công". Chặn ngay tại
// đây để lỗi chỉ vào đúng chỗ quên.
func (m *Manager) Generate(userID, tenantID uint, role string, t TokenType) (string, time.Time, error) {
	if tenantID == 0 {
		return "", time.Time{}, ErrMissingTenant
	}

	ttl := m.accessTTL
	if t == RefreshToken {
		ttl = m.refreshTTL
	}
	expiresAt := time.Now().Add(ttl)
	claims := Claims{
		UserID:   userID,
		TenantID: tenantID,
		Role:     role,
		Type:     t,
		RegisteredClaims: jwt.RegisteredClaims{
			ExpiresAt: jwt.NewNumericDate(expiresAt),
			IssuedAt:  jwt.NewNumericDate(time.Now()),
		},
	}
	token := jwt.NewWithClaims(jwt.SigningMethodHS256, claims)
	signed, err := token.SignedString(m.secret)
	return signed, expiresAt, err
}

// GeneratePlatform cấp token cho NGƯỜI ĐIỀU HÀNH NỀN TẢNG.
//
// Hàm riêng chứ không thêm tham số vào Generate, vì hai loại token có hai ràng
// buộc NGƯỢC NHAU: Generate từ chối tenantID = 0 (token của cửa hàng phải nói
// được nó thuộc tiệm nào), còn token ở đây BẮT BUỘC tenant = 0 (người điều hành
// không thuộc tiệm nào). Gộp làm một hàm là gộp hai luật đối nhau vào một chỗ,
// và chỗ đó sẽ nới lỏng dần cho tới lúc không còn ràng buộc nào.
//
// role là vai trò trong khu điều hành (owner | operator | support). Nó nằm
// trong token cho tiện đọc log, nhưng NƠI QUYẾT ĐỊNH QUYỀN không đọc nó: mỗi
// request tra lại vai trò trong sổ (xem middleware.XacThucNenTang), để thu
// quyền của một người là có hiệu lực ngay chứ không phải chờ token hết hạn.
func (m *Manager) GeneratePlatform(userID uint, role string, t TokenType) (string, time.Time, error) {
	ttl := m.accessTTL
	if t == RefreshToken {
		ttl = m.refreshTTL
	}
	expiresAt := time.Now().Add(ttl)
	claims := Claims{
		UserID:   userID,
		TenantID: 0,
		Role:     role,
		Platform: true,
		Type:     t,
		RegisteredClaims: jwt.RegisteredClaims{
			ExpiresAt: jwt.NewNumericDate(expiresAt),
			IssuedAt:  jwt.NewNumericDate(time.Now()),
		},
	}
	signed, err := jwt.NewWithClaims(jwt.SigningMethodHS256, claims).SignedString(m.secret)

	return signed, expiresAt, err
}

// Parse xác thực token và trả về claims.
func (m *Manager) Parse(tokenString string) (*Claims, error) {
	claims := &Claims{}
	token, err := jwt.ParseWithClaims(tokenString, claims, func(t *jwt.Token) (interface{}, error) {
		if _, ok := t.Method.(*jwt.SigningMethodHMAC); !ok {
			return nil, ErrInvalidToken
		}
		return m.secret, nil
	})
	if err != nil {
		if errors.Is(err, jwt.ErrTokenExpired) {
			return nil, ErrExpiredToken
		}
		return nil, ErrInvalidToken
	}
	if !token.Valid {
		return nil, ErrInvalidToken
	}
	return claims, nil
}
