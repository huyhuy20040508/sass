package domain

import "time"

// ---------- Báo cáo doanh thu ----------
//
// Tab "Báo cáo doanh thu" của báo cáo cuối ngày bản v2: mỗi NGÀY một dòng tiền,
// bấm vào ngày thì ra từng hoá đơn của ngày đó, kèm bốn biểu đồ (theo ngày /
// giờ / thứ / tháng).
//
// Cùng quy ước với các báo cáo khác: đơn còn hiệu lực, mốc là created_at.
//   - Doanh thu (VAT) = total_amount; Doanh thu = total_amount − vat_amount.
//   - Đã thanh toán = tổng tiền các đơn payment_status = paid; Công nợ = phần
//     còn lại — cùng quy ước với cột "Còn nợ" của CRM.
//   - Tiền theo hình thức chỉ cộng đơn ĐÃ THU, nên các cột hình thức cộng lại
//     đúng bằng Đã thanh toán.

// SalesDay — tiền của một ngày (hoặc dòng tổng, khi Date rỗng).
type SalesDay struct {
	Date       string  `json:"date"` // YYYY-MM-DD
	Orders     int64   `json:"orders"`
	Revenue    float64 `json:"revenue"`     // chưa VAT
	RevenueVAT float64 `json:"revenue_vat"` // gồm VAT = total_amount
	// Returns — tiền hoàn cho phiếu trả hàng lập trong ngày (đã nhận hàng / đã
	// hoàn tiền). Để riêng một cột, KHÔNG trừ vào doanh thu: các báo cáo khác
	// cũng không trừ, trừ ở đây là hai trang nói hai số cho cùng một ngày.
	Returns float64 `json:"returns"`
	Paid    float64 `json:"paid"`
	// Tiền đã thu theo hình thức. QR tự động = payos + sepay. Quẹt thẻ: quầy
	// chưa có hình thức này nên luôn 0. Other = cod / vnpay / momo của đơn web.
	Cash     float64 `json:"cash"`
	Transfer float64 `json:"transfer"`
	Card     float64 `json:"card"`
	AutoQR   float64 `json:"auto_qr"`
	Other    float64 `json:"other"`
	Debt     float64 `json:"debt"`
}

// Cộng dồn một ngày vào dòng tổng.
func (d *SalesDay) Cong(x SalesDay) {
	d.Orders += x.Orders
	d.Revenue += x.Revenue
	d.RevenueVAT += x.RevenueVAT
	d.Returns += x.Returns
	d.Paid += x.Paid
	d.Cash += x.Cash
	d.Transfer += x.Transfer
	d.Card += x.Card
	d.AutoQR += x.AutoQR
	d.Other += x.Other
	d.Debt += x.Debt
}

// SalesReport — báo cáo doanh thu của một kỳ.
type SalesReport struct {
	From string `json:"from"`
	To   string `json:"to"`
	// Days — CHỈ ngày có đơn hoặc có trả hàng, mới trước (như bảng v2).
	Days   []SalesDay `json:"days"`
	Totals SalesDay   `json:"totals"`
	// Bốn biểu đồ, doanh thu gồm VAT. ByDay đủ mọi ngày của kỳ (key YYYY-MM-DD),
	// ByHour đủ 24 mốc ("0".."23"), ByWeekday đủ 7 ("1".."7", 1 = Thứ Hai),
	// ByMonth đủ 12 ("1".."12").
	ByDay     []ReportSlice `json:"by_day"`
	ByHour    []ReportSlice `json:"by_hour"`
	ByWeekday []ReportSlice `json:"by_weekday"`
	ByMonth   []ReportSlice `json:"by_month"`
}

// SalesOrderRow — một hoá đơn trong hộp "Chi tiết báo cáo bán hàng" của một ngày.
type SalesOrderRow struct {
	ID            uint    `json:"id"`
	Code          string  `json:"code"`
	Channel       string  `json:"channel"`
	PaymentMethod string  `json:"payment_method"`
	Units         int64   `json:"units"`
	Subtotal      float64 `json:"subtotal"`  // tạm tính (tiền hàng)
	Surcharge     float64 `json:"surcharge"` // phụ thu
	Discount      float64 `json:"discount"`
	Revenue       float64 `json:"revenue"` // chưa VAT
	VAT           float64 `json:"vat"`
	Total         float64 `json:"total"` // doanh thu (VAT)
	Paid          float64 `json:"paid"`
	Debt          float64 `json:"debt"`
	// Returns — tiền hoàn của các phiếu trả (đã nhận hàng / đã hoàn tiền) của đơn này.
	Returns float64 `json:"returns"`
	// Khách mua. Khách lẻ không có tài khoản thì lấy tên / số người nhận trên đơn.
	CustomerCode string    `json:"customer_code"`
	CustomerName string    `json:"customer_name"`
	Phone        string    `json:"phone"`
	CreatedAt    time.Time `json:"created_at"`
}
