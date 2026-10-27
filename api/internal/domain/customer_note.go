package domain

import (
	"context"
	"errors"
	"time"

	"gorm.io/gorm"
)

// GhiChuKhach — một dòng sổ ghi chú của khách (migration 0097). Khác ô
// users.customer_note (ghi chú cố định, bị ghi đè khi sửa hồ sơ): dòng ở đây chỉ
// thêm, biết ai ghi và lúc nào.
type GhiChuKhach struct {
	ID uint `json:"id" gorm:"primaryKey"`
	TenantOwned
	CustomerID uint   `json:"customer_id"`
	ShopID     *uint  `json:"shop_id"`
	Loai       string `json:"loai" gorm:"column:type"`
	NoiDung    string `json:"noi_dung" gorm:"column:content"`
	// HenNgay chỉ có ở loại "hen"; ra JSON dưới dạng NgayHen (YYYY-MM-DD).
	HenNgay   *time.Time     `json:"-" gorm:"column:due_date;type:date"`
	CreatedBy *uint          `json:"created_by"`
	CreatedAt time.Time      `json:"created_at"`
	UpdatedAt time.Time      `json:"-"`
	DeletedAt gorm.DeletedAt `json:"-" gorm:"index"`

	NgayHen     string `json:"ngay_hen,omitempty" gorm:"-"`
	NguoiGhi    string `json:"nguoi_ghi" gorm:"-"`
	TenChiNhanh string `json:"ten_chi_nhanh" gorm:"-"`
}

func (GhiChuKhach) TableName() string { return "customer_notes" }

const (
	GhiChuChamSoc = "cham-soc"
	GhiChuHen     = "hen"
	GhiChuCanhBao = "canh-bao"
)

// LoaiGhiChuHopLe — ba loại ghi chú màn quầy cho chọn.
func LoaiGhiChuHopLe(s string) bool {
	return s == GhiChuChamSoc || s == GhiChuHen || s == GhiChuCanhBao
}

// LoiGhiChuKhach — ô của hộp thêm ghi chú không hợp lệ, theo từng ô.
type LoiGhiChuKhach struct{ O map[string]string }

func (e *LoiGhiChuKhach) Error() string { return "ghi chú chưa hợp lệ" }

type GhiChuKhachRepository interface {
	// CuaKhach — sổ ghi chú của một khách, mới trước.
	CuaKhach(ctx context.Context, customerID uint) ([]GhiChuKhach, error)
	Them(ctx context.Context, g *GhiChuKhach) error
	Tim(ctx context.Context, id uint) (*GhiChuKhach, error)
	Xoa(ctx context.Context, id uint) error
	// LaKhach — id có phải một khách của cửa hàng đang làm không.
	LaKhach(ctx context.Context, id uint) (bool, error)
}

// ErrChuaCoSoGhiChu — DB chưa chạy migration 0097.
var ErrChuaCoSoGhiChu = errors.New("cơ sở dữ liệu chưa có sổ ghi chú khách (migration 0097)")
