package domain

import (
	"context"
	"errors"
	"time"
)

// QuaSinhNhat — mốc "đã chúc / đã tặng mã" sinh nhật của khách (migration 0098).
// VoucherID nil = chỉ đánh dấu đã chúc.
type QuaSinhNhat struct {
	ID uint `json:"id" gorm:"primaryKey"`
	TenantOwned
	CustomerID uint      `json:"customer_id"`
	VoucherID  *uint     `json:"voucher_id"`
	ShopID     *uint     `json:"shop_id"`
	CreatedBy  *uint     `json:"created_by"`
	CreatedAt  time.Time `json:"created_at"`

	// Mã vừa tặng, để màn quầy đọc / in ngay — không phải cột.
	Voucher *Voucher `json:"voucher,omitempty" gorm:"-"`
}

func (QuaSinhNhat) TableName() string { return "customer_birthday_gifts" }

// MuaSinhNhatNgay: đã chúc trong chừng này ngày thì coi như đã chúc mùa sinh nhật
// này. Không lấy tròn 365: chúc sớm 7 ngày năm nay thì cửa sổ 7 ngày năm sau mở ra
// sau 358 ngày, ngưỡng 365 sẽ giấu khách khỏi danh sách năm sau.
const MuaSinhNhatNgay = 300

// CuaSoSinhNhatNgay: chỉ tặng được khi sinh nhật cách hôm nay không quá chừng này
// ngày (trước hoặc sau) — nút ở danh sách "Sinh nhật 7 ngày".
const CuaSoSinhNhatNgay = 7

var (
	ErrChuaCoSoSinhNhat = errors.New("cơ sở dữ liệu chưa có sổ quà sinh nhật (migration 0098)")
	ErrChuaKhaiMauSN    = errors.New("chưa khai mẫu mã sinh nhật ở Cài đặt → Quầy bán hàng")
	ErrMauSNSai         = errors.New("mẫu mã sinh nhật không hợp lệ: giảm theo phần trăm phải từ 1 đến 100")
	ErrKhongTrongMuaSN  = errors.New("khách không có sinh nhật trong 7 ngày quanh hôm nay")
	ErrDaChucMuaNay     = errors.New("khách đã được chúc mùa sinh nhật này")
)

type QuaSinhNhatRepository interface {
	// GanNhat — mốc gần nhất của khách từ `tu` trở đi; nil = chưa có.
	GanNhat(ctx context.Context, customerID uint, tu time.Time) (*QuaSinhNhat, error)
	// Tang ghi mốc, kèm sinh mã khi v != nil — một giao dịch. Mã trùng thì tự sinh lại.
	Tang(ctx context.Context, q *QuaSinhNhat, v *Voucher, sinhMa func() string) error
	// NgaySinh — ngày sinh (nil = chưa khai) và tên khách; ErrNotFound khi không phải khách.
	NgaySinh(ctx context.Context, customerID uint) (*time.Time, string, error)
}
