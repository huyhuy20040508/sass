package domain

import (
	"context"
	"errors"
	"time"

	"gorm.io/gorm"
)

// NHÀ CUNG CẤP — Quản lý kho → Nhà cung cấp.
//
// Danh mục đầu mối mua vào, dựng lại theo màn cùng tên của bản order v2 nên có
// đủ trường bên đó: tên viết tắt, địa chỉ 2, người đại diện kèm số máy, ảnh.
//
// Ở tầng tenant như mọi danh mục khác: cả chuỗi cửa hàng nhập chung một danh
// sách, mã duy nhất trong MỘT tenant.
type NhaCungCap struct {
	ID uint `json:"id" gorm:"primaryKey"`
	TenantOwned
	// Code luôn viết hoa: NCC001 do phần mềm đặt, hoặc do người dùng tự gõ.
	Code      string `json:"code"`
	Name      string `json:"name"`
	ShortName string `json:"short_name"`

	TaxCode string `json:"tax_code"`
	Phone   string `json:"phone"`
	Email   string `json:"email"`

	Address      string `json:"address"`
	AddressLine2 string `json:"address_line2"`

	RepresentativeName  string `json:"representative_name"`
	RepresentativePhone string `json:"representative_phone"`

	Image string `json:"image"`
	Note  string `json:"note"`

	// IsActive tắt = thôi hợp tác: bên này biến khỏi ô chọn lúc lập phiếu, nhưng
	// dòng vẫn còn để phiếu cũ tra ra được tên và số máy.
	IsActive bool `json:"is_active"`

	CreatedAt time.Time      `json:"created_at"`
	UpdatedAt time.Time      `json:"updated_at"`
	DeletedAt gorm.DeletedAt `json:"-" gorm:"index"`
}

func (NhaCungCap) TableName() string { return "suppliers" }

// NhaCungCapFilter — tham số lọc khi liệt kê.
type NhaCungCapFilter struct {
	Keyword string // tên / mã / SĐT / MST / tên viết tắt
	// OnlyActive = true: chỉ bên đang hợp tác (ô chọn lúc lập phiếu dùng cái này).
	OnlyActive bool
}

// NhaCungCapRepository — truy cập bảng suppliers.
type NhaCungCapRepository interface {
	List(ctx context.Context, f NhaCungCapFilter) ([]NhaCungCap, error)
	FindByID(ctx context.Context, id uint) (*NhaCungCap, error)
	// ExistsByCode tính cả dòng đã xoá mềm — mã vẫn bị UNIQUE index giữ chỗ.
	ExistsByCode(ctx context.Context, code string, excludeID uint) (bool, error)
	// NextCode sinh mã kế tiếp dạng NCC001 khi cửa hàng chưa bật quy tắc đánh số.
	NextCode(ctx context.Context) (string, error)
	Create(ctx context.Context, ncc *NhaCungCap) error
	Update(ctx context.Context, ncc *NhaCungCap) error
	Delete(ctx context.Context, id uint) error
}

var (
	// ErrNhaCungCapTrungMa — mã đã có trong cửa hàng, kể cả ở dòng đã xoá mềm.
	ErrNhaCungCapTrungMa = errors.New("mã nhà cung cấp đã tồn tại")
)
