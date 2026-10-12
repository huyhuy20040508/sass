package domain

import (
	"slices"
	"time"
)

// ---------- Báo cáo chương trình khuyến mại ----------
//
// Khuôn report/promotion-report của bản v2, năm tab (bỏ tab "Khách hàng" của
// v2 vì quầy shop không có loại chương trình theo số khách):
//   - hoa-don   : chương trình khuyến mại loại 0 (phiếu bán hàng)
//   - hang-hoa  : chương trình khuyến mại loại 3 (sản phẩm)
//   - nhom-hang : chương trình khuyến mại loại 2 (nhóm hàng)
//   - dong-gia  : khuyến mại đồng giá
//   - voucher   : chương trình Voucher/Coupon
//
// Mỗi CHƯƠNG TRÌNH một dòng. Khác v2 ở chỗ số liệu đọc theo ĐƠN TRONG KỲ (tạo
// trong kỳ, còn hiệu lực, đúng chi nhánh) chứ không phải đếm cả đời chương
// trình: v2 lấy cột `used` nên đổi kỳ xem mà số lượt không đổi.
//
// Ba tab đầu đọc order_promotions (bản chụp lúc bán, migration 0075); tab
// voucher đọc voucher_usages. Chương trình còn hiệu lực trong kỳ mà chưa đơn
// nào dùng vẫn có dòng (số 0) — chủ tiệm cần thấy cả chương trình "không ai
// dùng".

const (
	KMBaoCaoHoaDon   = "hoa-don"
	KMBaoCaoHangHoa  = "hang-hoa"
	KMBaoCaoNhomHang = "nhom-hang"
	KMBaoCaoDongGia  = "dong-gia"
	KMBaoCaoVoucher  = "voucher"

	OrderPromotionProgram    = "program"
	OrderPromotionFixedPrice = "fixed_price"
)

// OrderPromotion — một chương trình đã áp vào một đơn, chụp lúc bán. Xem
// migration 0075.
type OrderPromotion struct {
	ID uint `json:"id" gorm:"primaryKey"`
	TenantOwned
	OrderID        uint      `json:"order_id"`
	Kind           string    `json:"kind"`
	ProgramID      uint      `json:"program_id"`
	DetailID       uint      `json:"detail_id"`
	ProgramCode    string    `json:"program_code"`
	ProgramName    string    `json:"program_name"`
	ProgramType    uint8     `json:"program_type"`
	Quantity       int       `json:"quantity"`
	GiftQuantity   int       `json:"gift_quantity"`
	BaseAmount     float64   `json:"base_amount"`
	DiscountAmount float64   `json:"discount_amount"`
	CreatedAt      time.Time `json:"created_at"`
}

func (OrderPromotion) TableName() string { return "order_promotions" }

// PromotionCatalogItem — đầu chương trình (để bày cả chương trình chưa ai dùng,
// và hiện thời gian áp dụng hiện tại).
type PromotionCatalogItem struct {
	ID          uint
	Code        string
	Name        string
	Type        uint8
	Status      bool
	Approved    bool // voucher: đã phát hành
	NoTimeLimit bool
	StartDate   *time.Time
	EndDate     *time.Time
	// CoTuLuc — lúc chương trình bắt đầu tồn tại: voucher là lúc phát hành
	// (released_at), loại khác là created_at. "Không giới hạn thời gian" hay
	// ngày bắt đầu ghi lùi đều không làm nó chạy được trong kỳ trước mốc này.
	CoTuLuc   *time.Time
	AllShops  bool
	ShopIDs   []uint `gorm:"-"`
	CodeCount int64  // voucher: số mã đã phát
	CodeUsed  int64  // voucher: số mã đã dùng ít nhất một lần (mọi thời điểm)
}

// ApChiNhanh — chương trình áp được cho chi nhánh này (0 = cả cửa hàng).
func (c PromotionCatalogItem) ApChiNhanh(shopID uint) bool {
	if shopID == 0 || c.AllShops {
		return true
	}
	return slices.Contains(c.ShopIDs, shopID)
}

// ChayTrongKy — khoảng áp dụng giao với kỳ [from, to) (to là mốc mở).
func (c PromotionCatalogItem) ChayTrongKy(from, to time.Time) bool {
	if c.CoTuLuc != nil && !c.CoTuLuc.Before(to) {
		return false
	}
	if c.NoTimeLimit {
		return true
	}
	if c.StartDate != nil && !c.StartDate.Before(to) {
		return false
	}
	if c.EndDate != nil && !c.EndDate.AddDate(0, 0, 1).After(from) {
		return false
	}
	return true
}

// PromotionReportRow — một chương trình (hoặc dòng tổng khi ProgramID = 0).
type PromotionReportRow struct {
	ProgramID uint   `json:"program_id"`
	Code      string `json:"code"`
	Name      string `json:"name"`
	Type      uint8  `json:"type"`
	// Deleted: chương trình đã xoá, tên/mã lấy từ bản chụp trên đơn.
	Deleted     bool    `json:"deleted"`
	Status      bool    `json:"status"`
	Approved    bool    `json:"approved"`
	NoTimeLimit bool    `json:"no_time_limit"`
	StartDate   *string `json:"start_date"`
	EndDate     *string `json:"end_date"`

	OrderCount   int64   `json:"order_count"`
	GoodsAmount  float64 `json:"goods_amount"` // tiền hàng các đơn (subtotal)
	Revenue      float64 `json:"revenue"`      // khách trả (total_amount)
	Quantity     int64   `json:"quantity"`
	GiftQuantity int64   `json:"gift_quantity"`
	BaseAmount   float64 `json:"base_amount"`
	Discount     float64 `json:"discount"`

	CodeCount int64 `json:"code_count"`
	CodeUsed  int64 `json:"code_used"`
}

type PromotionReport struct {
	From string               `json:"from"`
	To   string               `json:"to"`
	Tab  string               `json:"tab"`
	Rows []PromotionReportRow `json:"rows"`
	// Totals: số đơn / tiền hàng / doanh thu đếm MỖI ĐƠN MỘT LẦN — đơn áp hai
	// chương trình cộng thẳng các dòng là đếm đôi. Các cột còn lại cộng thẳng.
	Totals PromotionReportRow `json:"totals"`
}

// PromotionOrderRow — một đơn dùng chương trình (hộp chi tiết).
type PromotionOrderRow struct {
	ID            uint      `json:"id"`
	Code          string    `json:"code"`
	CreatedAt     time.Time `json:"created_at"`
	CustomerName  string    `json:"customer_name"`
	Phone         string    `json:"phone"`
	GoodsAmount   float64   `json:"goods_amount"`
	Quantity      int64     `json:"quantity"`
	GiftQuantity  int64     `json:"gift_quantity"`
	BaseAmount    float64   `json:"base_amount"`
	Discount      float64   `json:"discount"`       // phần của chương trình này
	TotalDiscount float64   `json:"total_discount"` // mọi khoản giảm của đơn
	Total         float64   `json:"total"`
	VoucherCode   string    `json:"voucher_code"`
}

// PromotionReportFilter — tab + chương trình (hộp chi tiết).
type PromotionReportFilter struct {
	Tab       string
	ProgramID uint
	// ProgramIDs: chỉ các chương trình này (nil = mọi chương trình) — dòng tổng
	// khi đang tìm theo mã / tên.
	ProgramIDs []uint
}

// KindVaLoai — tab → (kind trong order_promotions, các loại chương trình).
// Loại trả []int chứ không []uint8: driver gửi []uint8 đi như một chuỗi byte.
func (f PromotionReportFilter) KindVaLoai() (string, []int) {
	switch f.Tab {
	case KMBaoCaoHangHoa:
		return OrderPromotionProgram, []int{int(KMDanhSachHang)}
	case KMBaoCaoNhomHang:
		return OrderPromotionProgram, []int{int(KMNhomHang)}
	case KMBaoCaoDongGia:
		return OrderPromotionFixedPrice, nil
	default:
		return OrderPromotionProgram, []int{int(KMPhieuBanHang)}
	}
}
