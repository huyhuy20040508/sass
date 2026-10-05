package domain

import "time"

// ---------- Báo cáo hàng hoá ----------
//
// Tab "Báo cáo hàng hóa" của báo cáo cuối ngày bản v2: mỗi MẶT HÀNG một dòng
// (số lượng, tiền trước thuế, tiền gồm thuế), bấm vào mã thì ra từng hoá đơn có
// mặt hàng đó, kèm bốn biểu đồ (top bán chạy / số lượng theo giờ / theo thứ /
// theo tháng).
//
// Cùng quy ước với các báo cáo khác: đơn còn hiệu lực, mốc là created_at.
//   - Tổng giá bán = SUM(order_items.total_price): tiền dòng đã trừ phần bớt
//     của chính dòng, CHƯA thuế.
//   - Thành tiền (VAT) = Tổng giá bán + SUM(order_items.vat_amount).
//   - Giảm giá cả đơn và phí ship không chia về mặt hàng, nên cộng cột này
//     không bằng doanh thu của tab Doanh thu — v2 cũng vậy.

// GoodsFilter — ba ô lọc riêng của tab hàng hoá.
type GoodsFilter struct {
	// CategoryIDs đã được service nở gồm nhóm được chọn + mọi nhóm con/cháu.
	CategoryIDs []uint
	ProductID   uint
	// Keyword tìm theo tên / mã hàng — chỉ lọc bảng, biểu đồ không dùng (v2
	// cũng không gửi ô tìm sang biểu đồ).
	Keyword string
}

// GoodsRow — một mặt hàng trong kỳ (hoặc dòng tổng, khi ProductID = 0 và Name rỗng).
//
// ProductID = 0 với Name có chữ: dòng của mặt hàng đã bị xoá hẳn khỏi danh mục
// (order_items.product_id về NULL) — gộp theo tên lúc bán, không mở chi tiết được.
type GoodsRow struct {
	ProductID uint    `json:"product_id"`
	Code      string  `json:"code"`
	Name      string  `json:"name"`
	Quantity  int64   `json:"quantity"`
	Amount    float64 `json:"amount"` // Tổng giá bán, chưa VAT
	Total     float64 `json:"total"`  // Thành tiền gồm VAT
}

// Cong cộng một dòng vào dòng tổng.
func (t *GoodsRow) Cong(r GoodsRow) {
	t.Quantity += r.Quantity
	t.Amount += r.Amount
	t.Total += r.Total
}

// GoodsSeries — số lượng bán theo thứ (T2…CN) của một mặt hàng.
type GoodsSeries struct {
	ProductID uint     `json:"product_id"`
	Name      string   `json:"name"`
	Data      [7]int64 `json:"data"`
}

type GoodsReport struct {
	From   string     `json:"from"`
	To     string     `json:"to"`
	Rows   []GoodsRow `json:"rows"`
	Totals GoodsRow   `json:"totals"`
	// Top — N mặt hàng doanh thu (gồm VAT) cao nhất, hoặc thấp nhất khi sort=asc.
	Top []GoodsRow `json:"top"`
	// ByHour 0..23, ByMonth 1..12: số lượng bán nằm ở Units.
	ByHour  []ReportSlice `json:"by_hour"`
	ByMonth []ReportSlice `json:"by_month"`
	// ByWeekday — N mặt hàng bán NHIỀU nhất (theo số lượng), mỗi món một chuỗi.
	ByWeekday []GoodsSeries `json:"by_weekday"`
}

// GoodsOrderRow — một hoá đơn có mặt hàng đang xem (hộp chi tiết).
type GoodsOrderRow struct {
	ID        uint      `json:"id"`
	Code      string    `json:"code"`
	Channel   string    `json:"channel"`
	ItemCode  string    `json:"item_code"`
	ItemName  string    `json:"item_name"`
	Quantity  int64     `json:"quantity"`
	Total     float64   `json:"total"` // gồm VAT, cùng nghĩa cột Thành tiền (VAT)
	CreatedAt time.Time `json:"created_at"`
}
