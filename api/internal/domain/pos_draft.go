package domain

import (
	"errors"
	"time"
)

// ĐƠN NHÁP Ở QUẦY — hoá đơn đang mở, chưa thanh toán (bảng pos_drafts, 0084).
//
// Nằm riêng khỏi bảng orders có chủ ý: mọi báo cáo, công nợ và tồn kho đọc
// orders, nên đơn nháp ở đó là phải dạy từng câu truy vấn bỏ qua nó. Lúc thanh
// toán, đơn thật được lập qua POSCheckout và mang đúng mã của nháp.
const (
	NhapQuayDangMo   = "open"
	NhapQuayDangChot = "dang_chot"
	NhapQuayDaChot   = "converted"
	NhapQuayDaHuy    = "cancelled"
)

// HanGianhChot: một máy bấm thanh toán thì giữ hoá đơn trong khoảng này. Máy đó
// tắt ngang giữa chừng thì hết hạn là máy khác chốt tiếp được, không kẹt mãi.
const HanGianhChot = 2 * time.Minute

var (
	// ErrNhapKhongMo — hoá đơn đã thanh toán hoặc đã huỷ (thường là ở máy khác).
	ErrNhapKhongMo = errors.New("hoá đơn này đã thanh toán hoặc đã huỷ ở máy khác — tải lại danh sách hoá đơn")
	// ErrNhapDangChot — một máy khác đang thanh toán đúng hoá đơn này.
	ErrNhapDangChot = errors.New("hoá đơn đang được thanh toán ở máy khác")
)

type DonNhapQuay struct {
	ID uint `json:"id" gorm:"primaryKey"`
	TenantOwned
	ShopID    uint   `json:"shop_id"`
	OrderCode string `json:"order_code"`
	Status    string `json:"status"`
	CreatedBy uint   `json:"created_by"`
	// CreatedByName: tên người mở hoá đơn — đọc kèm từ users, không phải cột.
	CreatedByName string     `json:"created_by_name" gorm:"-"`
	UpdatedBy     *uint      `json:"updated_by"`
	UserID        *uint      `json:"user_id"`
	CustomerName  string     `json:"customer_name"`
	CustomerPhone string     `json:"customer_phone"`
	Note          string     `json:"note"`
	ItemCount     int        `json:"item_count"`
	TamTinh       float64    `json:"tam_tinh"`
	Items         string     `json:"-"`
	DuLieu        string     `json:"-"`
	OrderID       *uint      `json:"order_id"`
	CancelReason  string     `json:"cancel_reason"`
	CancelledBy   *uint      `json:"cancelled_by"`
	CancelledAt   *time.Time `json:"cancelled_at"`
	ClaimedAt     *time.Time `json:"-"`
	ConvertedAt   *time.Time `json:"converted_at"`
	CreatedAt     time.Time  `json:"created_at"`
	UpdatedAt     time.Time  `json:"updated_at"`
}

func (DonNhapQuay) TableName() string { return "pos_drafts" }

// LocNhapQuay — bộ lọc danh sách đơn nháp của CHI NHÁNH đang làm.
type LocNhapQuay struct {
	// Status rỗng = mọi trạng thái; nhiều giá trị ngăn bởi dấu phẩy.
	Status    string
	CreatedBy uint
	FromDate  string
	ToDate    string
	Limit     int
}
