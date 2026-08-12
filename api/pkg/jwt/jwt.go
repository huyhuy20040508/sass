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
	TenantID uint      `json:"tid"`
	Role     string    `json:"role"`
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
