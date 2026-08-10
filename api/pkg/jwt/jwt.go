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
)

// Claims là payload của token.
type Claims struct {
	UserID uint      `json:"uid"`
	Role   string    `json:"role"`
	Type   TokenType `json:"typ"`
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
func (m *Manager) Generate(userID uint, role string, t TokenType) (string, time.Time, error) {
	ttl := m.accessTTL
	if t == RefreshToken {
		ttl = m.refreshTTL
	}
	expiresAt := time.Now().Add(ttl)
	claims := Claims{
		UserID: userID,
		Role:   role,
		Type:   t,
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
