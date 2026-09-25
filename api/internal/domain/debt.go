package domain

import (
	"context"
	"time"
)

// CÔNG NỢ — Thu chi → Công nợ.
//
// Dựng theo màn `cashbook/debt` của bản cũ v2, nhưng KHÔNG có bảng riêng.
//
// VÌ SAO KHÔNG DỰNG BẢNG `cab_debts` NHƯ V2 — đọc kỹ, đây là quyết định gốc:
//
// v2 để mỗi khoản nợ thành một dòng `cab_debts` trỏ ngược về chứng từ gốc bằng
// `order_id`, phân biệt nợ khách với nợ nhà cung cấp bằng cột `type`. Quan hệ ấy
// là MỘT–MỘT: mỗi phiếu tối đa một dòng nợ, đẻ ra ngay trong lượt trả tiền của
// chính phiếu đó và không ai dùng lại. Chép sang đây thì bảng mới chỉ là bản sao
// của `purchase_orders` với thêm một khoá ngoại, và phải giữ cho hai chỗ khớp
// nhau mãi mãi — mà v2 đã KHÔNG giữ được: `cab_debts.paid` cộng dồn trong
// DebtController còn `pch_orders.payment_total_price` cộng ở chỗ khác, hai con
// số lệch nhau là chuyện thường ngày bên đó.
//
// Migration 0048 đã chốt hướng ngược lại: thoả thuận nợ nằm THẲNG trên phiếu
// mua (`is_debt`, `debt_due_date`, người liên hệ), còn từng lượt trả nằm ở
// `purchase_payments` (migration 0049 — đúng vai `cab_debt_detail` của v2). Cụm
// này chỉ ĐỌC hai bảng ấy dưới một hình dạng khác, nên không có gì để lệch.
//
// VÌ SAO CHƯA CÓ NỢ KHÁCH HÀNG:
//
// v2 gộp hai chiều nợ vào một màn và cho lọc bằng ô "Đối tượng". Bên này chưa có
// bảng đơn bán nào cả — không có nguồn nào đẻ ra nợ khách. Bày sẵn một ô lọc
// không bao giờ trả về dòng nào là dựng cái bẫy cho người dùng, nên màn này hiện
// chỉ có nợ NHÀ CUNG CẤP. Làm bán hàng rồi thì `CongNo` mọc thêm một nguồn nữa
// và ô lọc ấy mới có nghĩa để bày ra.
type CongNo struct {
	// ID là id phiếu mua hàng — công nợ KHÔNG có id của riêng nó.
	ID uint `json:"id"`
	// Code là mã phiếu mua. v2 in mã `cab_debts.code` sinh theo rule 'debt'
	// riêng, tức mỗi khoản nợ mang hai mã cho cùng một chứng từ và người dùng
	// phải nhớ cả hai. Ở đây một chứng từ một mã.
	Code string `json:"code"`

	ShopID     uint   `json:"shop_id"`
	BranchName string `json:"branch_name"`

	SupplierID   *uint  `json:"supplier_id"`
	SupplierName string `json:"supplier_name"`

	TotalAmount float64 `json:"total_amount"`
	PaidAmount  float64 `json:"paid_amount"`
	// Remaining = TotalAmount − PaidAmount. Tính khi đọc chứ không cất thành
	// cột: một con số cất riêng là một con số phải giữ cho khớp.
	Remaining float64 `json:"remaining"`

	DueDate      *time.Time `json:"due_date"`
	ContactName  string     `json:"contact_name"`
	ContactPhone string     `json:"contact_phone"`

	// Status: paid | partial | unpaid — suy từ hai con số tiền, xem TinhTrangNo.
	Status string `json:"status"`
	// DaysLeft là số ngày còn tới hạn; ÂM = đã quá hạn ngần ấy ngày. Nil khi
	// phiếu không có hạn nợ.
	//
	// Tính ở máy chủ chứ không ở trình duyệt: v2 để blade tự `diffInDays` theo
	// giờ MÁY NGƯỜI DÙNG, nên hai người ngồi hai múi giờ đọc ra hai con số khác
	// nhau cho cùng một phiếu.
	DaysLeft *int `json:"days_left"`

	CreatedBy     *uint     `json:"created_by"`
	CreatedByName string    `json:"created_by_name"`
	CreatedAt     time.Time `json:"created_at"`
}

// Trạng thái trả tiền của một khoản nợ.
const (
	CongNoChuaTra = "unpaid"
	CongNoMotPhan = "partial"
	CongNoDaTra   = "paid"
)

// Mốc lọc theo hạn — bốn nút đếm số trên đầu bảng, đúng bốn nút của v2.
const (
	CongNoHanTatCa  = "all"
	CongNoHanGan    = "near"
	CongNoHanQua    = "over"
	CongNoHanHomNay = "today"
)

// CongNoSoNgayGan là bề rộng của mốc "Gần đến hạn" khi người gọi không nói rõ.
//
// v2 đọc con số này từ tham số hệ thống `debt_expired_notification`, và khi tham
// số ấy tắt thì rơi về 7. Bên này chưa có màn tham số hệ thống nên chốt luôn 7 —
// cùng con số, chỉ là chưa cho sửa.
const CongNoSoNgayGan = 7

// TinhTrangNo suy trạng thái từ hai con số tiền.
//
// So sánh có BIÊN 1 đồng chứ không so bằng: tiền là DECIMAL(18,2) và mọi lượt
// trả đều cộng dồn, nên "trả đủ" hay rơi vào 2999999.99 / 3000000.01. v2 so
// `paid == total_amount` trên cột INT nên một phiếu trả đủ mà lẻ một đồng thì
// nằm mãi ở "thanh toán một phần", không cách nào đóng lại.
func TinhTrangNo(total, paid float64) string {
	switch {
	case paid >= total-0.005:
		return CongNoDaTra
	case paid <= 0.005:
		return CongNoChuaTra
	default:
		return CongNoMotPhan
	}
}

// SoNgayConLai đếm số ngày từ hôm nay tới hạn nợ; âm = đã quá hạn.
//
// Cắt cả hai mốc về đầu ngày trước khi trừ: so thẳng hai `time.Time` thì một
// phiếu tới hạn chiều nay đọc ra "còn 0 ngày" lúc 8 giờ sáng và "quá hạn 1 ngày"
// lúc 5 giờ chiều cùng ngày.
func SoNgayConLai(due *time.Time, now time.Time) *int {
	if due == nil {
		return nil
	}

	d := time.Date(due.Year(), due.Month(), due.Day(), 0, 0, 0, 0, time.Local)
	n := time.Date(now.Year(), now.Month(), now.Day(), 0, 0, 0, 0, time.Local)
	so := int(d.Sub(n).Hours() / 24)

	return &so
}

// CongNoFilter — tham số lọc / phân trang khi liệt kê công nợ.
type CongNoFilter struct {
	// SupplierName và Code là HAI ô tìm riêng, đúng như v2 ("Tên đối tượng" và
	// "Mã công nợ"). Gộp thành một ô thì gõ tên nhà cung cấp cũng quét cả cột mã.
	// Cả hai khớp một phần.
	SupplierName string
	Code         string
	// SupplierID — id nhà cung cấp, nhiều giá trị ngăn bởi dấu phẩy.
	SupplierID string
	// CreatedBy — id người lập phiếu, nhiều giá trị ngăn bởi dấu phẩy.
	CreatedBy string
	// Status — paid | partial | unpaid, nhiều giá trị ngăn bởi dấu phẩy.
	// Rỗng = mọi trạng thái.
	Status string
	// Due — all | near | over | today. Rỗng coi như all.
	Due string
	// SoNgayGan là bề rộng của mốc "Gần đến hạn"; 0 = lấy CongNoSoNgayGan.
	SoNgayGan int
	ShopID    uint // 0 = mọi chi nhánh
	Page      int
	PageSize  int
}

// CongNoTongKet — bốn con số của bốn nút lọc nhanh, cộng thêm tiền còn nợ.
//
// Bốn con số này tính trên bộ lọc ĐANG BẬT nhưng BỎ mốc hạn: bấm sang "Quá hạn"
// mà cả bốn con số đổi theo thì không còn so được với nhau nữa. v2 clone câu
// truy vấn TRƯỚC khi áp cả mốc hạn lẫn bộ lọc trạng thái, nên đổi ô trạng thái
// xong bốn con số đứng im — bảng còn ba dòng mà nút vẫn ghi "Tất cả (57)".
type CongNoTongKet struct {
	TatCa  int64 `json:"count_all"`
	Gan    int64 `json:"count_near"`
	Qua    int64 `json:"count_over"`
	HomNay int64 `json:"count_today"`
	// ConNo là tổng tiền còn phải trả của CẢ bộ lọc, không riêng trang đang xem.
	ConNo float64 `json:"total_remaining"`
}

// CongNoRepository — đọc công nợ từ purchase_orders + purchase_payments.
//
// Chỉ có phép ĐỌC. Ghi một lượt trả nợ đi bằng đường thanh toán phiếu mua sẵn có
// (PhieuMuaHangService.Pay): đường ấy đã khoá dòng, đã cập nhật `payment_status`
// và đã ghi `purchase_payments` trong cùng một giao dịch. Mở thêm một đường ghi
// thứ hai vào cùng mấy cột ấy là dựng sẵn chỗ cho hai con số lệch nhau.
type CongNoRepository interface {
	// List trả về trang đang xem, tổng số dòng, và bốn con số của bốn nút lọc.
	List(ctx context.Context, f CongNoFilter) ([]CongNo, int64, CongNoTongKet, error)
	// LichSuTra là sổ từng lượt trả của một phiếu, cũ trước mới sau.
	LichSuTra(ctx context.Context, purchaseOrderID uint) ([]PurchasePayment, error)
}
