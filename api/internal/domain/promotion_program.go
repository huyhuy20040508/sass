package domain

import (
	"context"
	"errors"
	"time"
)

// CHƯƠNG TRÌNH KHUYẾN MẠI — khuôn pmt_promotion_programs của bản v2. Xem
// migration 0070.
//
// Thu ngân chọn ở quầy (nút "Khuyến mãi"). Mỗi chương trình có nhiều BẬC; đơn
// đạt bậc nào CAO NHẤT thì giảm theo bậc đó và nhận hàng tặng của bậc đó. Khác
// Promotion (giảm tự áp lên giá từng sản phẩm, chạy cả website).

const (
	KMPhieuBanHang uint8 = 0 // bậc theo tổng tiền hàng của đơn
	KMNhomHang     uint8 = 2 // bậc theo số lượng một danh mục (kèm con)
	KMDanhSachHang uint8 = 3 // bậc theo số lượng một sản phẩm

	KMHinhThucPhanTram uint8 = 0
	KMHinhThucTien     uint8 = 1
)

type PromotionProgram struct {
	ID uint `json:"id" gorm:"primaryKey"`
	TenantOwned
	Code        string     `json:"code"`
	Name        string     `json:"name"`
	Description string     `json:"description"`
	Type        uint8      `json:"type"`
	Status      bool       `json:"status"`
	Approved    bool       `json:"approved"`
	NoTimeLimit bool       `json:"no_time_limit"`
	StartDate   *time.Time `json:"start_date" gorm:"type:date"`
	EndDate     *time.Time `json:"end_date" gorm:"type:date"`
	DaysOfWeek  string     `json:"days_of_week"`
	AllShops    bool       `json:"all_shops"`
	Used        int        `json:"used"`

	ShopIDs []uint                   `json:"shop_ids" gorm:"-"`
	Details []PromotionProgramDetail `json:"details" gorm:"foreignKey:ProgramID"`

	CreatedAt time.Time `json:"created_at"`
	UpdatedAt time.Time `json:"updated_at"`
}

func (PromotionProgram) TableName() string { return "promotion_programs" }

type PromotionProgramDetail struct {
	ID uint `json:"id" gorm:"primaryKey"`
	TenantOwned
	ProgramID  uint                   `json:"program_id"`
	TotalApply float64                `json:"total_apply"`
	ObjectID   uint                   `json:"object_id"`
	Quantity   int                    `json:"quantity"`
	Formality  uint8                  `json:"formality"`
	Value      float64                `json:"value"`
	MaxValue   float64                `json:"max_value"`
	Gifts      []PromotionProgramGift `json:"gifts" gorm:"foreignKey:DetailID"`
}

func (PromotionProgramDetail) TableName() string { return "promotion_program_details" }

type PromotionProgramGift struct {
	ID uint `json:"id" gorm:"primaryKey"`
	TenantOwned
	DetailID         uint `json:"detail_id"`
	ProductVariantID uint `json:"product_variant_id"`
	Quantity         int  `json:"quantity"`
}

func (PromotionProgramGift) TableName() string { return "promotion_program_gifts" }

type PromotionProgramShop struct {
	ID uint `gorm:"primaryKey"`
	TenantOwned
	ProgramID uint
	ShopID    uint
}

func (PromotionProgramShop) TableName() string { return "promotion_program_shops" }

// ChayNgay: có hiệu lực trong ngày của at không (ngày áp dụng + thứ).
func (p PromotionProgram) ChayNgay(at time.Time) bool {
	return hieuLucNgay(p.NoTimeLimit, p.StartDate, p.EndDate, p.DaysOfWeek, at)
}

// PromotionProgramFilter — lọc màn CRM → Chương trình khuyến mại (khuôn v2).
type PromotionProgramFilter struct {
	// Khoảng NGÀY TẠO, như v2 (lọc created_at chứ không lọc ngày áp dụng).
	FromDate string
	ToDate   string
	ShopIDs  []uint
	Codes    []string
	// Statuses: rỗng = không lọc; "1" hoạt động, "0" không hoạt động.
	Statuses []bool
	Page     int
	PageSize int
}

type PromotionProgramRepository interface {
	List(ctx context.Context, f PromotionProgramFilter) ([]PromotionProgram, int64, error)
	// Codes: mọi mã chương trình của cửa hàng — ô lọc "Mã khuyến mãi".
	Codes(ctx context.Context) ([]string, error)
	FindByID(ctx context.Context, id uint) (*PromotionProgram, error)
	Save(ctx context.Context, p *PromotionProgram) error
	SetStatus(ctx context.Context, id uint, on bool) error
	SetApproved(ctx context.Context, id uint, on bool) error
	Delete(ctx context.Context, id uint) error
	MaKeTiep(ctx context.Context) (string, error)
	DangBat(ctx context.Context) ([]PromotionProgram, error)
	// TangLuotDung +1 cho mỗi chương trình đã áp vào một đơn.
	TangLuotDung(ctx context.Context, ids []uint) error
	ChiNhanhQuay(ctx context.Context) uint
}

var (
	ErrKMDangDung = errors.New("Không thể xoá, chương trình khuyến mại đang được sử dụng.")
	// ErrKMKhongGopDongGia: v2 — "Không thể chọn khuyến mãi khi đã dùng đồng giá!"
	ErrKMKhongGopDongGia = errors.New("Không thể dùng chương trình khuyến mãi cùng đồng giá.")
)
