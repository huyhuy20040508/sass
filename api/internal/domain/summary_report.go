package domain

// Báo cáo tổng hợp — bức tranh MỘT NGÀY, khuôn tab "Báo cáo tổng hợp" của v2.
// Phần đơn hàng đi CÙNG QUY ƯỚC với bốn báo cáo kia (đơn còn hiệu lực, mốc là
// created_at) và dùng lại chính các câu gộp của chúng.

// SummaryReport — báo cáo tổng hợp của một ngày.
type SummaryReport struct {
	Date string `json:"date" example:"2026-09-29"`

	Cashbook SummaryCashbook `json:"cashbook"`
	// Totals của riêng ngày này: số đơn, doanh thu, số món (units)…
	Totals ReportTotals `json:"totals"`
	// ItemKinds — số MẶT HÀNG khác nhau đã bán (một biến thể là một mặt hàng).
	ItemKinds int64 `json:"item_kinds"`
	// Cơ cấu tiền theo hình thức thanh toán, `key` = cash|bank_transfer|payos|…
	ByPaymentMethod []ReportSlice `json:"by_payment_method"`
	// ByHour luôn ĐỦ 24 mốc, key = "0".."23".
	ByHour  []ReportSlice  `json:"by_hour"`
	Returns SummaryReturns `json:"returns"`
}

// SummaryCashbook — phiếu thu / phiếu chi lập trong ngày (sổ Thu chi).
type SummaryCashbook struct {
	IncomeCount  int64   `json:"income_count"`
	ExpenseCount int64   `json:"expense_count"`
	Income       float64 `json:"income"`
	Expense      float64 `json:"expense"`
	// Phần TIỀN MẶT của Income / Expense — thẻ "Quỹ tiền mặt" đọc hai số này:
	// phiếu chuyển khoản không đi qua két.
	CashIncome  float64 `json:"cash_income"`
	CashExpense float64 `json:"cash_expense"`
}

// SummaryReturns — hàng khách trả lại trong ngày. Chỉ tính phiếu đã NHẬN HÀNG
// hoặc đã HOÀN TIỀN: phiếu chờ duyệt / bị từ chối / khách huỷ thì chưa có món
// nào thật sự quay về.
type SummaryReturns struct {
	Returns int64   `json:"returns"` // số phiếu trả
	Lines   int64   `json:"lines"`   // số dòng hàng bị trả
	Units   int64   `json:"units"`   // tổng số lượng bị trả
	Refund  float64 `json:"refund"`  // tổng tiền hoàn cho khách
}
