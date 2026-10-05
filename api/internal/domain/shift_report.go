package domain

import "time"

// ---------- Báo cáo kết ca ----------
//
// Mỗi dòng là MỘT CA: ai trực, bán được bao nhiêu đơn, tiền về theo từng hình
// thức, và phần đối chiếu két đã chốt lúc đóng ca. Khuôn là trang
// report/shift của bản v2.
//
// ĐƠN THUỘC CA = đơn QUẦY (channel = pos) của cùng chi nhánh, tạo trong khoảng
// [opened_at, closed_at] của ca (ca chưa đóng thì tới lúc xem), đã thu tiền và
// còn hiệu lực (không huỷ / không hoàn). Đơn web không tính: ca là lượt trực
// QUẦY, đơn giao hàng không đi qua tay người trực.
//
// Không dựa vào cash_entries.shift_id: sổ quỹ chỉ có dòng TIỀN MẶT, còn báo cáo
// cần cả chuyển khoản và QR.

// BaoCaoCaFilter — bộ lọc báo cáo kết ca.
type BaoCaoCaFilter struct {
	// From / To là nửa khoảng [From, To) theo opened_at: To là 00:00 của ngày
	// SAU ngày cuối kỳ, cùng quy ước với ReportPeriod.
	From time.Time
	To   time.Time
	// ShopID = 0 là mọi chi nhánh.
	ShopID uint
	// UserID > 0 thì chỉ lấy ca do người này mở.
	UserID uint
	// Keyword tìm theo mã nhân sự, tên nhân sự hoặc tên tài khoản người mở ca.
	Keyword  string
	Page     int
	PageSize int
}

// BaoCaoCaDong — một ca trong báo cáo.
type BaoCaoCaDong struct {
	ID       uint   `json:"id"`
	ShopID   uint   `json:"shop_id"`
	ShopName string `json:"shop_name"`

	OpenedAt     time.Time  `json:"opened_at"`
	ClosedAt     *time.Time `json:"closed_at"`
	OpenedBy     uint       `json:"opened_by"`
	OpenedByName string     `json:"opened_by_name"`
	ClosedBy     *uint      `json:"closed_by"`
	ClosedByName string     `json:"closed_by_name"`

	// Mã và tên trên HỒ SƠ NHÂN SỰ của người mở ca. Trống khi tài khoản đó không
	// gắn hồ sơ nào (chủ tiệm thường không có) — giao diện tự lùi về OpenedByName.
	EmployeeCode string `json:"employee_code"`
	EmployeeName string `json:"employee_name"`

	OrderCount    int64   `json:"order_count"`
	TotalCash     float64 `json:"total_cash"`
	TotalTransfer float64 `json:"total_transfer"`
	// TotalCard — quẹt thẻ. Quầy chưa có hình thức này nên luôn 0; giữ trường
	// cho đủ khuôn cột v2, có hình thức rồi thì chỉ phải sửa câu gộp.
	TotalCard    float64 `json:"total_card"`
	TotalAutoQR  float64 `json:"total_auto_qr"`
	TotalRevenue float64 `json:"total_revenue"`

	OpeningCash float64 `json:"opening_cash"`
	// Ba con số đối chiếu két — nil khi ca chưa đóng. Không trả 0: "chênh 0"
	// đọc thành "két khớp", mà ca đó chưa ai đếm két.
	ExpectedCash *float64 `json:"expected_cash"`
	CountedCash  *float64 `json:"counted_cash"`
	Difference   *float64 `json:"difference"`

	OpenNote  string `json:"open_note"`
	CloseNote string `json:"close_note"`
}

// BaoCaoCaTong — số cộng trên TẤT CẢ ca khớp bộ lọc, không riêng trang đang xem.
type BaoCaoCaTong struct {
	TotalRevenue float64 `json:"total_revenue"`
	OrderCount   int64   `json:"order_count"`
}
