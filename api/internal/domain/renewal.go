package domain

import (
	"context"
	"time"
)

// KHÁCH TỰ GIA HẠN — thực thể của CONTROL PLANE (lược đồ selliotech_platform).
//
// Tách khỏi platform.go vì đây là một luồng NGHIỆP VỤ riêng, không phải một
// mảnh nữa của sổ cái: khách bấm gói → cổng thanh toán → tiền vào → hợp đồng dài
// thêm. Ba bảng nó đụng tới (`renewal_orders`, `invoices`, `subscriptions`) đã
// có port riêng; kiểu ở đây chỉ mô tả cái đơn.

// DonGiaHan là MỘT LẦN KHÁCH BẤM GIA HẠN, không phải một lần trả tiền thành công.
//
// Phần lớn đơn KHÔNG bao giờ thành tiền — khách bấm rồi đóng tab, link hết hạn,
// đổi ý. Đó chính là lý do nó không nằm chung với `invoices` (sổ tiền đã vào):
// một dòng trong sổ thu nghĩa là tiền có thật, và trộn đơn chưa trả vào đó là
// làm hỏng con số duy nhất mà cả khu điều hành tin được.
type DonGiaHan struct {
	ID       uint `json:"id" gorm:"primaryKey"`
	TenantID uint `json:"tenant_id"`
	AppID    uint `json:"app_id"`
	// SubscriptionID là hợp đồng sẽ được đẩy hạn khi tiền vào.
	SubscriptionID uint `json:"subscription_id"`
	// PlanID là dòng bảng giá khách chọn — CHỈ ĐỂ TRUY VẾT. Số tiền đã chốt ở
	// SoTien ngay dưới; tra ngược sang bảng giá lúc tiền vào là để một lần sửa
	// giá đổi luôn số tiền của đơn khách đang mở dở.
	PlanID  *uint   `json:"plan_id"`
	SoThang uint    `json:"so_thang"`
	SoTien  float64 `json:"so_tien"`
	// MaDon là orderCode gửi sang cổng thanh toán. Duy nhất toàn hệ thống — đó là
	// chốt chặn chống ghi nhận trả tiền hai lần cho cùng một giao dịch.
	MaDon uint `json:"ma_don"`
	// TrangThai: cho_thanh_toan | da_thanh_toan | huy | het_han
	TrangThai   string       `json:"trang_thai"`
	Cong        string       `json:"cong"`
	LinkID      StringOrNull `json:"link_id"`
	CheckoutURL StringOrNull `json:"checkout_url"`
	// Năm ô dưới đây là THÔNG TIN TRẢ TIỀN do cổng trả về lúc tạo link, cất lại
	// để trang thanh toán tự vẽ được màn hình trả tiền thay vì đá khách sang
	// trang của cổng.
	//
	// Phải LƯU chứ không hỏi lại: đường tra trạng thái của cổng không trả lại
	// chuỗi QR — nó chỉ có đúng một lần, lúc tạo link (xem migration 0017).
	QRCode      StringOrNull `json:"qr_code"`
	NganHangBIN StringOrNull `json:"ngan_hang_bin"`
	SoTaiKhoan  StringOrNull `json:"so_tai_khoan"`
	ChuTaiKhoan StringOrNull `json:"chu_tai_khoan"`
	NoiDung     StringOrNull `json:"noi_dung"`
	InvoiceID   *uint        `json:"invoice_id"`
	CreatedAt   time.Time    `json:"created_at"`
	UpdatedAt   time.Time    `json:"updated_at"`
	PaidAt      *time.Time   `json:"paid_at"`
	HetHanLuc   *time.Time   `json:"het_han_luc"`
}

func (DonGiaHan) TableName() string { return "renewal_orders" }

// Trạng thái của một đơn gia hạn.
const (
	DonChoThanhToan = "cho_thanh_toan"
	DonDaThanhToan  = "da_thanh_toan"
	DonHuy          = "huy"
	DonHetHan       = "het_han"
)

// Cổng thanh toán đã dùng cho đơn.
const CongPayOS = "payos"

// ThongTinTraTien là những gì cổng trả về sau khi tạo link — đủ để trang thanh
// toán tự dựng màn hình trả tiền.
//
// Gom thành struct chứ không phải sáu tham số chuỗi liền nhau: sáu tham số cùng
// kiểu string thì hoán vị nhầm hai cái là lỗi trình biên dịch không bắt được, và
// cái hoán vị đó ghi thẳng xuống database — số tài khoản đứng ở ô tên chủ tài
// khoản, và khách chuyển tiền theo đó.
type ThongTinTraTien struct {
	LinkID      string
	CheckoutURL string
	// QRCode là chuỗi VietQR nguyên văn. Trang thanh toán vẽ mã QR từ chính chuỗi
	// này nên khách quét bằng app ngân hàng nào cũng ra đúng số tiền, đúng nội dung.
	QRCode      string
	NganHangBIN string
	SoTaiKhoan  string
	ChuTaiKhoan string
	// NoiDung là nội dung chuyển khoản — thứ DUY NHẤT nối một lần tiền vào với một
	// đơn. Khách gõ sai nó thì tiền vào tài khoản mà không đơn nào được chốt.
	NoiDung string
	HetHan  *time.Time
}

// DonGiaHanRepository đọc/ghi sổ đơn gia hạn của control plane.
//
// CHẠY TRÊN CONTROL PLANE (repository.NewPlatformDB), cùng ràng buộc với
// PlanRepository.
//
// MỌI HÀM ĐỌC THEO CỬA HÀNG đều nhận tenantID và phải đưa nó vào điều kiện WHERE:
// đường gọi tới đây đến từ token của một cửa hàng bất kỳ, và bộ lọc tenant tự
// động của GORM không với tới control plane. Riêng TimTheoMaDon là ngoại lệ có
// chủ ý — xem chú thích của nó.
type DonGiaHanRepository interface {
	// Tao ghi một đơn mới và trả về id vừa cấp.
	Tao(ctx context.Context, don *DonGiaHan) error
	// GanLink ghi thông tin link thanh toán sau khi cổng trả về.
	//
	// Tách khỏi Tao vì đơn phải có id TRƯỚC khi gọi cổng: id chính là orderCode
	// gửi đi, mà cổng thì cần orderCode ngay trong request tạo link.
	GanLink(ctx context.Context, id uint, tt ThongTinTraTien) error
	// Tim tra một đơn CỦA MỘT CỬA HÀNG. Sai cửa hàng thì ErrNotFound, không phải
	// ErrForbidden: chủ tiệm này không cần biết mã đơn của tiệm kia có tồn tại.
	Tim(ctx context.Context, tenantID, id uint) (*DonGiaHan, error)
	// TimTheoMaDon tra đơn theo orderCode, KHÔNG kèm cửa hàng.
	//
	// Ngoại lệ có chủ ý: người gọi là WEBHOOK của cổng thanh toán, và nó chỉ cầm
	// mỗi orderCode — không có token, không có tenant. Bù lại, chữ ký của cổng đã
	// được kiểm TRƯỚC khi hàm này được gọi, và mã đơn là số do mình sinh ra.
	TimTheoMaDon(ctx context.Context, maDon uint) (*DonGiaHan, error)
	// DangCho trả về đơn CHƯA TRẢ gần nhất của một hợp đồng, nil nếu không có.
	//
	// Dùng để không sinh link mới cho khách vừa bấm gia hạn xong quay lại bấm
	// tiếp: mỗi lần bấm một link là sổ đơn đầy rác, và khách thì mở đúng cái nào
	// cũng được nên sẽ có người trả hai lần.
	DangCho(ctx context.Context, subscriptionID uint) (*DonGiaHan, error)
	// DanhDauDaTra chốt đơn: trạng thái, mốc trả tiền và dòng sổ thu tương ứng.
	//
	// Hiện thực PHẢI chỉ đổi những đơn đang `cho_thanh_toan` và trả về số dòng đã
	// đổi. Webhook của cổng có thể tới nhiều lần cho cùng một giao dịch (thiết kế
	// của họ, không phải lỗi), nên con số đó là thứ phân biệt "vừa chốt xong" với
	// "đã chốt từ lượt trước" — và nhờ nó mà một lần trả tiền không đẩy hạn hai lần.
	DanhDauDaTra(ctx context.Context, id, invoiceID uint) (int64, error)
	// GanHoaDon nối đơn với dòng sổ thu vừa ghi.
	//
	// Tách khỏi DanhDauDaTra vì hai việc xảy ra ở hai thời điểm: giành quyền chốt
	// đơn phải xong TRƯỚC khi ghi sổ thu (nếu không, hai lượt song song cùng ghi
	// hai dòng tiền cho một lần trả), mà id của dòng sổ thu thì chỉ có SAU đó.
	GanHoaDon(ctx context.Context, id, invoiceID uint) error
	// DoiTrangThai đưa đơn sang `huy` hoặc `het_han`.
	DoiTrangThai(ctx context.Context, id uint, trangThai string) error
}
