package domain

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"time"
)

// AppWebBanHang là hệ thống web bán hàng (ecommerce_web) — hôm nay là người
// dùng khoá tích hợp duy nhất.
const AppWebBanHang = "web_ban_hang"

// IntegrationKey là khoá cho một HỆ THỐNG gọi API thay một cửa hàng, không
// cần ai đăng nhập. Chỉ lưu băm — xem migration 0099.
type IntegrationKey struct {
	ID         uint       `json:"id" gorm:"primaryKey"`
	TenantID   uint       `json:"-"`
	App        string     `json:"app"`
	KeyHash    string     `json:"-"`
	KeyPrefix  string     `json:"key_prefix"`
	CreatedBy  *uint      `json:"created_by"`
	LastUsedAt *time.Time `json:"last_used_at"`
	RevokedAt  *time.Time `json:"revoked_at"`
	CreatedAt  time.Time  `json:"created_at"`
}

func (IntegrationKey) TableName() string { return "integration_keys" }

// BamKhoaTichHop là cách duy nhất biến khoá thành thứ được lưu trong database.
func BamKhoaTichHop(khoa string) string {
	h := sha256.Sum256([]byte(khoa))
	return hex.EncodeToString(h[:])
}

// ChuKhoaTichHop là thứ middleware cần biết sau khi tra một khoá: của cửa
// hàng nào, cửa hàng đó còn mở không.
type ChuKhoaTichHop struct {
	KeyID        uint
	TenantID     uint
	TenantStatus string
}

type IntegrationKeyRepository interface {
	// TheoBam tra khoá CÒN HIỆU LỰC theo băm. Chạy TRƯỚC khi biết cửa hàng —
	// chính nó là thứ cho biết cửa hàng — nên đi vòng bộ lọc tenant.
	TheoBam(ctx context.Context, bam string) (*ChuKhoaTichHop, error)
	// DangDung trả khoá còn hiệu lực của cửa hàng trong ctx cho `app`;
	// ErrNotFound khi chưa cấp hoặc đã thu hồi.
	DangDung(ctx context.Context, app string) (*IntegrationKey, error)
	// CapMoi thu hồi khoá cũ cùng app rồi lưu khoá mới, trong một transaction.
	CapMoi(ctx context.Context, k *IntegrationKey) error
	// ThuHoi thu hồi khoá còn hiệu lực của `app`; trả số khoá vừa thu hồi.
	ThuHoi(ctx context.Context, app string) (int64, error)
	// GhiLanDung đánh dấu khoá vừa được dùng (thưa — tối đa mỗi phút một lần).
	GhiLanDung(ctx context.Context, id uint) error
}
