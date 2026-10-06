package domain

import "time"

// ---------- Báo cáo nhân viên (hoa hồng) ----------
//
// Tab "Báo cáo nhân viên" của báo cáo cuối ngày bản v2: mỗi NHÂN VIÊN một dòng
// — bán được bao nhiêu, khách trả lại bao nhiêu, tính hoa hồng trên bao nhiêu và
// được bao nhiêu hoa hồng; bấm vào tên thì ra từng đơn của người đó.
//
// Đơn được tính: đơn ĐÃ THU TIỀN, còn hiệu lực, tạo trong kỳ, do NHÂN VIÊN lập
// (orders.created_by là tài khoản nội bộ) — cùng tập đơn có người lập với Báo
// cáo ca. Đơn khách tự đặt trên web không thuộc nhân viên nào.
//   - Tổng doanh thu = total_amount − vat_amount; (VAT) = total_amount.
//   - Tổng tiền trả hàng = tiền hoàn của phiếu trả đã nhận hàng / đã hoàn tiền
//     (returnStatuses) của chính các đơn ấy.
//   - Doanh thu tính hoa hồng = Tổng doanh thu (VAT) − Tổng tiền trả hàng, không
//     âm: hàng khách trả lại thì nhân viên không ăn hoa hồng phần đó.
//   - Hoa hồng = doanh thu tính hoa hồng × tỉ lệ % của người lập CHỤP vào đơn
//     lúc tạo đơn (orders.staff_commission_rate, migration 0074): sửa tỉ lệ
//     hay xoá hồ sơ về sau không đổi hoa hồng đã tính. Đơn không có bản chụp
//     mới lùi về tỉ lệ hiện tại trên hồ sơ. Bên mình chưa có chương trình hoa
//     hồng theo món như v2 — một người một tỉ lệ; trong kỳ người đó đổi tỉ lệ
//     thì cột tỉ lệ của dòng hiện mức của đơn mới nhất.

// EmployeeOrder — một đơn của nhân viên (hộp chi tiết)
type EmployeeOrder struct {
	ID             uint      `json:"id"`
	Code           string    `json:"code"`
	CreatedAt      time.Time `json:"created_at"`
	CustomerName   string    `json:"customer_name"`
	Products       string    `json:"products"` // tên các món, ngăn bằng ", "
	Quantity       int64     `json:"quantity"`
	Revenue        float64   `json:"revenue"`
	RevenueVAT     float64   `json:"revenue_vat"`
	Returned       float64   `json:"returned"`
	CommissionBase float64   `json:"commission_base"`
	Commission     float64   `json:"commission"`
	// UserID / EmployeeCode / Name / Rate chỉ để service gộp theo người, không trả ra
	UserID       uint    `json:"-"`
	EmployeeCode string  `json:"-"`
	Name         string  `json:"-"`
	Rate         float64 `json:"-"`
}

// TinhHoaHong điền doanh thu tính hoa hồng và tiền hoa hồng theo tỉ lệ %
func (o *EmployeeOrder) TinhHoaHong(rate float64) {
	o.CommissionBase = max(o.RevenueVAT-o.Returned, 0)
	o.Commission = o.CommissionBase * rate / 100
}

// EmployeeRow — một nhân viên (hoặc dòng tổng, khi UserID = 0)
type EmployeeRow struct {
	UserID         uint            `json:"user_id"`
	EmployeeCode   string          `json:"employee_code"`
	Name           string          `json:"name"`
	CommissionRate float64         `json:"commission_rate"`
	OrderCount     int64           `json:"order_count"`
	Revenue        float64         `json:"revenue"`
	RevenueVAT     float64         `json:"revenue_vat"`
	Returned       float64         `json:"returned"`
	CommissionBase float64         `json:"commission_base"`
	Commission     float64         `json:"commission"`
	Orders         []EmployeeOrder `json:"orders,omitempty"` // mới trước
}

// Cong cộng một đơn vào dòng.
func (r *EmployeeRow) Cong(o EmployeeOrder) {
	r.OrderCount++
	r.Revenue += o.Revenue
	r.RevenueVAT += o.RevenueVAT
	r.Returned += o.Returned
	r.CommissionBase += o.CommissionBase
	r.Commission += o.Commission
}

type EmployeeReport struct {
	From   string        `json:"from"`
	To     string        `json:"to"`
	Rows   []EmployeeRow `json:"rows"` // nhiều hoa hồng trước, như v2
	Totals EmployeeRow   `json:"totals"`
}
