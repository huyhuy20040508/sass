package domain

import (
	"context"
	"errors"
	"time"
)

// CHƯƠNG TRÌNH VOUCHER / COUPON — khuôn pmt_voucher_coupon của bản v2. Xem
// migration 0071.
//
// Chương trình phát ra Quantity mã, mỗi mã là một Voucher (ProgramID trỏ về
// đây). Lưu (chưa phát hành) thì chưa có mã; Phát hành thì sinh đủ mã một lần
// và từ đó chương trình chỉ còn xem — sửa điều kiện sau khi mã đã tới tay khách
// là đổi luật của thứ khách đang cầm.

const (
	VoucherProgramChuaPhatHanh uint8 = 1
	VoucherProgramPhatHanh     uint8 = 2

	// VoucherProgramToiDa: số mã tối đa một chương trình (v2 chặn 200).
	VoucherProgramToiDa = 200
)

var (
	// ErrVCDaPhatHanh: chương trình đã phát hành thì không sửa, không xoá.
	ErrVCDaPhatHanh = errors.New("Chương trình đã phát hành, không sửa hay xoá được nữa.")
	// ErrVCHetMa: tiền tố + hậu tố này không còn đủ chỗ sinh mã không trùng.
	ErrVCHetMa = errors.New("Không sinh đủ mã không trùng với tiền tố / hậu tố này, vui lòng đổi tiền tố hoặc hậu tố.")
)

type VoucherProgram struct {
	ID uint `json:"id" gorm:"primaryKey"`
	TenantOwned
	Code              string   `json:"code"`
	Name              string   `json:"name"`
	Description       string   `json:"description"`
	DiscountType      string   `json:"discount_type"`
	DiscountValue     float64  `json:"discount_value"`
	MaxDiscountAmount *float64 `json:"max_discount_amount"`
	MinOrderAmount    float64  `json:"min_order_amount"`
	AllShops          bool     `json:"all_shops"`
	AllCategories     bool     `json:"all_categories"`
	NoTimeLimit       bool     `json:"no_time_limit"`
	// Ngày áp dụng, tính TRỌN ngày. Nil khi NoTimeLimit.
	StartDate  *time.Time `json:"start_date" gorm:"type:date"`
	EndDate    *time.Time `json:"end_date" gorm:"type:date"`
	Prefix     string     `json:"prefix"`
	Suffix     string     `json:"suffix"`
	Quantity   int        `json:"quantity"`
	UsageLimit int        `json:"usage_limit"`
	Status     uint8      `json:"status"`
	CreatedBy  *uint      `json:"created_by"`
	ReleasedAt *time.Time `json:"released_at"`

	ShopIDs     []uint `json:"shop_ids" gorm:"-"`
	CategoryIDs []uint `json:"category_ids" gorm:"-"`
	// CreatedByName KHÔNG phải cột: repository tra bảng users điền vào.
	CreatedByName string `json:"created_by_name" gorm:"-"`

	CreatedAt time.Time `json:"created_at"`
	UpdatedAt time.Time `json:"updated_at"`
}

func (VoucherProgram) TableName() string { return "voucher_programs" }

type VoucherProgramShop struct {
	ID uint `gorm:"primaryKey"`
	TenantOwned
	ProgramID uint
	ShopID    uint
}

func (VoucherProgramShop) TableName() string { return "voucher_program_shops" }

type VoucherProgramCategory struct {
	ID uint `gorm:"primaryKey"`
	TenantOwned
	ProgramID  uint
	CategoryID uint
}

func (VoucherProgramCategory) TableName() string { return "voucher_program_categories" }

// VoucherProgramFilter — lọc màn CRM → Voucher/Coupon.
type VoucherProgramFilter struct {
	ShopIDs  []uint
	Statuses []uint8 // rỗng = mọi trạng thái
	Page     int
	PageSize int
}

// VoucherCodeFilter — danh sách mã của một chương trình ("Danh sách mã chi tiết").
type VoucherCodeFilter struct {
	ProgramID uint
	Keyword   string
	Page      int
	PageSize  int
}

// VoucherCodeUse — một lượt dùng mã (hộp "Chi tiết" của từng mã).
type VoucherCodeUse struct {
	OrderID   uint      `json:"order_id"`
	OrderCode string    `json:"order_code"`
	Staff     string    `json:"staff"`
	Shop      string    `json:"shop"`
	UsedAt    time.Time `json:"used_at"`
	Discount  float64   `json:"discount"`
}

type VoucherProgramRepository interface {
	List(ctx context.Context, f VoucherProgramFilter) ([]VoucherProgram, int64, error)
	FindByID(ctx context.Context, id uint) (*VoucherProgram, error)
	// Save tạo mới (ID = 0) hoặc thay trọn chương trình cùng chi nhánh, danh mục.
	Save(ctx context.Context, p *VoucherProgram) error
	Delete(ctx context.Context, id uint) error
	// MaKeTiep: mã chương trình tiếp theo của cửa hàng (VC00001, VC00002…).
	MaKeTiep(ctx context.Context) (string, error)
	// MaDaCo: trong các mã ứng viên, mã nào đã có trong bảng vouchers.
	MaDaCo(ctx context.Context, codes []string) (map[string]bool, error)
	// PhatHanh chốt chương trình sang Phát hành và ghi các mã, trong MỘT giao dịch.
	PhatHanh(ctx context.Context, p *VoucherProgram, vouchers []Voucher) error
	Codes(ctx context.Context, f VoucherCodeFilter) ([]Voucher, int64, error)
	// SetCodeActive bật / tắt MỘT mã của chương trình (công tắc ở danh sách mã).
	SetCodeActive(ctx context.Context, voucherID uint, on bool) error
	CodeUses(ctx context.Context, voucherID uint) ([]VoucherCodeUse, error)
}
