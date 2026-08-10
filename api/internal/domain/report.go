package domain

import (
	"context"
	"time"
)

// Nhóm trang Báo cáo — các phép GỘP chỉ đọc trên dữ liệu đã có.
//
// Khác với /admin/*/stats (đếm trạng thái ở thời điểm hiện tại), báo cáo luôn
// gắn với MỘT KHOẢNG THỜI GIAN và luôn trả kèm số của KỲ TRƯỚC cùng độ dài để
// giao diện tính được mức tăng/giảm mà không phải gọi thêm lần nữa.
//
// Quy ước dùng chung cho cả bốn báo cáo — lệch quy ước là hai trang nói hai số
// khác nhau về cùng một thứ:
//   - "Đơn còn hiệu lực" = đơn KHÔNG ở trạng thái cancelled/returned. Mọi con số
//     tiền (doanh thu, giá vốn, lợi nhuận) chỉ tính trên nhóm này, giống hệt cách
//     OrderRepository.Stats và trang Tổng quan đang tính.
//   - Mốc thời gian của đơn là `created_at` (lúc khách đặt), không phải lúc giao.
//   - Giá vốn lấy theo biến thể (product_variants.cost_price), thiếu thì lùi về
//     giá vốn sản phẩm (products.cost_price), thiếu nốt thì coi như 0 — chưa khai
//     giá vốn thì lợi nhuận bằng đúng doanh thu, giao diện phải nói rõ chỗ đó.

// Cách chia trục thời gian của báo cáo.
const (
	ReportGroupDay   = "day"
	ReportGroupWeek  = "week"
	ReportGroupMonth = "month"
)

// ReportPeriod — một kỳ báo cáo đã quy về mốc tuyệt đối.
//
// Nửa khoảng [From, To): To là 00:00 của ngày SAU ngày cuối kỳ. Dùng nửa khoảng
// thay vì BETWEEN để không phải xử lý phần giờ-phút-giây của ngày cuối — đơn đặt
// lúc 23:59:59.999 vẫn được tính.
type ReportPeriod struct {
	From time.Time
	To   time.Time
}

// Days trả số ngày của kỳ (tối thiểu 1).
func (p ReportPeriod) Days() int {
	d := int(p.To.Sub(p.From).Hours() / 24)
	if d < 1 {
		return 1
	}
	return d
}

// Prev trả kỳ liền trước, CÙNG ĐỘ DÀI, kết thúc ngay trước kỳ này.
func (p ReportPeriod) Prev() ReportPeriod {
	n := p.Days()
	return ReportPeriod{From: p.From.AddDate(0, 0, -n), To: p.From}
}

// FromDate / ToDate trả hai đầu kỳ ở dạng YYYY-MM-DD để in ra API.
// ToDate lùi lại một ngày vì To là mốc mở.
func (p ReportPeriod) FromDate() string { return p.From.Format("2006-01-02") }
func (p ReportPeriod) ToDate() string   { return p.To.AddDate(0, 0, -1).Format("2006-01-02") }

// ReportSlice — một lát cắt đã gộp theo MỘT chiều (phương thức thanh toán, tỉnh
// thành, danh mục, khung giờ…).
//
// Cùng một kiểu cho mọi chiều để giao diện chỉ phải viết một khối vẽ cột: `key`
// là mã máy đọc (giao diện tự dịch ra nhãn tiếng Việt bằng bảng nó đang có),
// `label` chỉ có mặt khi mã không tự nói lên điều gì (id danh mục, id thương hiệu).
type ReportSlice struct {
	Key     string  `json:"key"`
	Label   string  `json:"label,omitempty"`
	Orders  int64   `json:"orders"`
	Units   int64   `json:"units"`
	Revenue float64 `json:"revenue"`
}

// ReportTotals — số tổng của MỘT kỳ, tính trên đơn CÒN HIỆU LỰC.
//
// Quan hệ giữa các cột tiền: Revenue = Subtotal - Discount + Shipping.
// Profit là LỢI NHUẬN GỘP = Subtotal - Discount - Cost: phí vận chuyển thu hộ
// nhà xe nên không được coi là lãi, còn Cost là giá vốn của đúng số món đã bán.
type ReportTotals struct {
	Orders   int64   `json:"orders"`
	Revenue  float64 `json:"revenue"`
	Subtotal float64 `json:"subtotal"`
	Discount float64 `json:"discount"`
	Shipping float64 `json:"shipping"`
	Units    int64   `json:"units"`
	Cost     float64 `json:"cost"`
	Profit   float64 `json:"profit"`
	AOV      float64 `json:"aov"`
}

// ReportBucket — một mốc trên trục thời gian.
//
// Label là khoá tự mô tả theo cách chia: "2026-07-28" (ngày), "2026-W31" (tuần
// ISO), "2026-07" (tháng). Giao diện in ra thì dịch lại theo cách chia đang xem.
type ReportBucket struct {
	Label    string  `json:"label"`
	Orders   int64   `json:"orders"`
	Revenue  float64 `json:"revenue"`
	Subtotal float64 `json:"subtotal"`
	Discount float64 `json:"discount"`
	Shipping float64 `json:"shipping"`
	Units    int64   `json:"units"`
	Cost     float64 `json:"cost"`
	Profit   float64 `json:"profit"`
}

// ---------- 1. Báo cáo doanh thu ----------

// RevenueReport — tiền vào theo thời gian: bao nhiêu, từ đâu, lãi gộp bao nhiêu.
type RevenueReport struct {
	From     string `json:"from" example:"2026-07-01"`
	To       string `json:"to" example:"2026-07-31"`
	PrevFrom string `json:"prev_from" example:"2026-06-01"`
	PrevTo   string `json:"prev_to" example:"2026-06-30"`
	GroupBy  string `json:"group_by" example:"day"`
	// Buckets luôn ĐỦ mốc của cả kỳ: mốc không phát sinh đơn vẫn có mặt với giá
	// trị 0, thiếu mốc thì biểu đồ nối thẳng qua và vẽ sai đường xu hướng.
	Buckets []ReportBucket `json:"buckets"`
	Totals  ReportTotals   `json:"totals"`
	Prev    ReportTotals   `json:"prev"`
	// Cơ cấu tiền theo hình thức thanh toán (`key` = cod|vnpay|momo|…) và theo
	// tình trạng thu tiền (`key` = pending|paid|failed|refunded).
	ByPaymentMethod []ReportSlice `json:"by_payment_method"`
	ByPaymentStatus []ReportSlice `json:"by_payment_status"`
}

// ---------- 2. Báo cáo đơn hàng ----------

// OrderReportTotals — số đơn của kỳ, tính trên TOÀN BỘ đơn (kể cả huỷ/hoàn).
//
// Total là mọi đơn phát sinh trong kỳ; Net là phần còn hiệu lực. Hai số này cố
// tình tách nhau: tỷ lệ huỷ chỉ có nghĩa khi mẫu số là tổng đơn đã đặt.
type OrderReportTotals struct {
	Total         int64   `json:"total"`
	Net           int64   `json:"net"`
	Cancelled     int64   `json:"cancelled"`
	Returned      int64   `json:"returned"`
	DeadRate      float64 `json:"dead_rate"` // % đơn huỷ + hoàn trên tổng đơn
	Revenue       float64 `json:"revenue"`
	Units         int64   `json:"units"`
	AOV           float64 `json:"aov"`
	UnitsPerOrder float64 `json:"units_per_order"`
	// Đơn còn hiệu lực nhưng chưa thu được tiền — phần doanh thu mới chỉ nằm trên giấy.
	UnpaidOrders int64   `json:"unpaid_orders"`
	UnpaidAmount float64 `json:"unpaid_amount"`
}

// OrderReport — đơn hàng ra vào thế nào: theo trạng thái, theo giờ, theo vùng.
type OrderReport struct {
	From     string            `json:"from"`
	To       string            `json:"to"`
	PrevFrom string            `json:"prev_from"`
	PrevTo   string            `json:"prev_to"`
	GroupBy  string            `json:"group_by"`
	Buckets  []ReportBucket    `json:"buckets"`
	Totals   OrderReportTotals `json:"totals"`
	Prev     OrderReportTotals `json:"prev"`
	// ByStatus đếm trên TOÀN BỘ đơn trong kỳ (có cả cancelled/returned), 8 trạng
	// thái luôn đủ mặt kể cả khi bằng 0 — bảng trạng thái mà nhảy dòng theo kỳ thì
	// không so sánh được giữa hai lần xem.
	ByStatus   []ReportSlice `json:"by_status"`
	ByHour     []ReportSlice `json:"by_hour"`     // luôn đủ 24 mốc, key = "0".."23"
	ByWeekday  []ReportSlice `json:"by_weekday"`  // luôn đủ 7 mốc, key = "1".."7" (1 = Thứ Hai)
	ByProvince []ReportSlice `json:"by_province"` // key = tên tỉnh/thành, đơn nhiều nhất lên trước
	ByChannel  []ReportSlice `json:"by_channel"`  // key = member | guest
	ByShipping []ReportSlice `json:"by_shipping"` // key = mã hình thức vận chuyển ("" = chưa khai)
}

// ---------- 3. Báo cáo sản phẩm ----------

// ProductReportTotals — phần bán ra của kỳ, tính trên đơn còn hiệu lực.
type ProductReportTotals struct {
	Units        int64   `json:"units"`
	Revenue      float64 `json:"revenue"`
	Cost         float64 `json:"cost"`
	Profit       float64 `json:"profit"`
	Margin       float64 `json:"margin"`        // % lợi nhuận gộp trên doanh thu hàng
	ProductsSold int64   `json:"products_sold"` // số sản phẩm KHÁC NHAU bán được
	Orders       int64   `json:"orders"`
}

// ProductReportRow — một sản phẩm trong bảng xếp hạng.
//
// Doanh thu ở đây là tiền HÀNG (tổng total_price của các dòng đơn), không gồm
// phí ship và chưa trừ giảm giá cấp đơn — nên tổng cột này KHÔNG bằng doanh thu
// của báo cáo doanh thu, đó là chủ ý chứ không phải sai lệch.
type ProductReportRow struct {
	ProductID    uint    `json:"product_id"`
	Name         string  `json:"name"`
	SKU          string  `json:"sku"`
	Slug         string  `json:"slug"`
	Thumbnail    string  `json:"thumbnail"`
	CategoryName string  `json:"category_name"`
	BrandName    string  `json:"brand_name"`
	Orders       int64   `json:"orders"`
	Units        int64   `json:"units"`
	Revenue      float64 `json:"revenue"`
	Cost         float64 `json:"cost"`
	Profit       float64 `json:"profit"`
	Margin       float64 `json:"margin"`
	Stock        int64   `json:"stock"` // tồn kho HIỆN TẠI, để biết bán chạy mà còn hàng không
}

// Cách xếp hạng bảng sản phẩm.
const (
	ProductSortRevenue = "revenue"
	ProductSortUnits   = "units"
	ProductSortProfit  = "profit"
)

// ProductReport — mặt hàng nào kéo doanh thu, mặt hàng nào nằm im.
type ProductReport struct {
	From     string              `json:"from"`
	To       string              `json:"to"`
	PrevFrom string              `json:"prev_from"`
	PrevTo   string              `json:"prev_to"`
	Sort     string              `json:"sort" example:"revenue"`
	Totals   ProductReportTotals `json:"totals"`
	Prev     ProductReportTotals `json:"prev"`
	Items    []ProductReportRow  `json:"items"`
	// Ba lát cắt cùng dạng: `key` là id (danh mục/thương hiệu) hoặc chính giá trị
	// (size), `label` là tên đọc được.
	ByCategory []ReportSlice `json:"by_category"`
	ByBrand    []ReportSlice `json:"by_brand"`
	BySize     []ReportSlice `json:"by_size"`
	// Số sản phẩm ĐANG BÁN mà cả kỳ không bán được món nào — phần vốn nằm chết,
	// không nhìn bảng xếp hạng nào thấy được.
	UnsoldProducts int64 `json:"unsold_products"`
}

// ---------- 4. Báo cáo khách hàng ----------

// CustomerReportTotals — người mua của kỳ.
//
// Buyers chỉ đếm khách CÓ TÀI KHOẢN: đơn khách vãng lai không gắn user_id nên
// không có cách nào biết hai đơn có phải cùng một người hay không. Phần đơn vãng
// lai vẫn được đếm riêng ở GuestOrders để không biến mất khỏi báo cáo.
type CustomerReportTotals struct {
	Buyers    int64 `json:"buyers"`
	NewBuyers int64 `json:"new_buyers"` // lần mua ĐẦU TIÊN rơi vào kỳ này
	Returning int64 `json:"returning"`  // đã từng mua trước kỳ này
	// Registered là số tài khoản đăng ký mới trong kỳ, kể cả người chưa mua gì.
	Registered      int64   `json:"registered"`
	Orders          int64   `json:"orders"`
	Revenue         float64 `json:"revenue"`
	MemberOrders    int64   `json:"member_orders"`
	MemberRevenue   float64 `json:"member_revenue"`
	GuestOrders     int64   `json:"guest_orders"`
	GuestRevenue    float64 `json:"guest_revenue"`
	RevenuePerBuyer float64 `json:"revenue_per_buyer"`
	OrdersPerBuyer  float64 `json:"orders_per_buyer"`
	RepeatRate      float64 `json:"repeat_rate"` // % khách quay lại trên tổng khách có tài khoản đã mua
}

// CustomerReportRow — một khách trong bảng xếp hạng chi tiêu.
type CustomerReportRow struct {
	UserID  uint    `json:"user_id"`
	Name    string  `json:"name"`
	Email   string  `json:"email"`
	Phone   string  `json:"phone"`
	Orders  int64   `json:"orders"`
	Units   int64   `json:"units"`
	Revenue float64 `json:"revenue"`
	AOV     float64 `json:"aov"`
	// IsNew = kỳ này là lần đầu khách mua hàng.
	IsNew       bool       `json:"is_new"`
	LastOrderAt *time.Time `json:"last_order_at"`
}

// CustomerBucket — một mốc thời gian của báo cáo khách hàng.
type CustomerBucket struct {
	Label      string `json:"label"`
	NewBuyers  int64  `json:"new_buyers"`
	Returning  int64  `json:"returning"`
	Registered int64  `json:"registered"`
}

// CustomerReport — ai đang mua: người mới hay người cũ, ở đâu, tiêu bao nhiêu.
type CustomerReport struct {
	From     string               `json:"from"`
	To       string               `json:"to"`
	PrevFrom string               `json:"prev_from"`
	PrevTo   string               `json:"prev_to"`
	GroupBy  string               `json:"group_by"`
	Totals   CustomerReportTotals `json:"totals"`
	Prev     CustomerReportTotals `json:"prev"`
	Buckets  []CustomerBucket     `json:"buckets"`
	Top      []CustomerReportRow  `json:"top"`
	// Khu vực tính theo địa chỉ nhận hàng của đơn, gồm CẢ đơn khách vãng lai —
	// muốn biết nên mở rộng ở đâu thì phải nhìn hết người mua chứ không chỉ hội viên.
	ByProvince []ReportSlice `json:"by_province"`
}

// ReportRepository — các phép gộp CHỈ ĐỌC phục vụ nhóm trang Báo cáo.
//
// Mọi phương thức đều nhận ReportPeriod và tự áp quy ước "đơn còn hiệu lực" nêu
// ở đầu tệp, TRỪ OrderCounts (cố ý đếm cả đơn huỷ/hoàn) và Registrations (đếm
// trên bảng users, không liên quan tới đơn).
type ReportRepository interface {
	// Totals gộp toàn kỳ. Chạy hai truy vấn: một trên orders (tiền), một trên
	// order_items (số món + giá vốn) — gộp chung một câu thì mỗi đơn bị nhân lên
	// theo số dòng hàng và cột tiền sẽ đội lên nhiều lần.
	Totals(ctx context.Context, p ReportPeriod) (ReportTotals, error)
	// Buckets chia kỳ theo groupBy (day|week|month). Trả về map theo nhãn mốc,
	// CHỈ có mốc thật sự phát sinh đơn — việc bù mốc trống là của tầng service,
	// vì chỉ nó biết cần bù tới đâu.
	Buckets(ctx context.Context, p ReportPeriod, groupBy string) (map[string]ReportBucket, error)

	ByPaymentMethod(ctx context.Context, p ReportPeriod) ([]ReportSlice, error)
	ByPaymentStatus(ctx context.Context, p ReportPeriod) ([]ReportSlice, error)

	OrderCounts(ctx context.Context, p ReportPeriod) (OrderReportTotals, error)
	ByStatus(ctx context.Context, p ReportPeriod) ([]ReportSlice, error)
	ByHour(ctx context.Context, p ReportPeriod) ([]ReportSlice, error)
	ByWeekday(ctx context.Context, p ReportPeriod) ([]ReportSlice, error)
	ByProvince(ctx context.Context, p ReportPeriod, limit int) ([]ReportSlice, error)
	ByChannel(ctx context.Context, p ReportPeriod) ([]ReportSlice, error)
	ByShipping(ctx context.Context, p ReportPeriod) ([]ReportSlice, error)

	ProductTotals(ctx context.Context, p ReportPeriod) (ProductReportTotals, error)
	ProductRows(ctx context.Context, p ReportPeriod, sort string, limit int) ([]ProductReportRow, error)
	ByCategory(ctx context.Context, p ReportPeriod, limit int) ([]ReportSlice, error)
	ByBrand(ctx context.Context, p ReportPeriod, limit int) ([]ReportSlice, error)
	BySize(ctx context.Context, p ReportPeriod, limit int) ([]ReportSlice, error)
	UnsoldProducts(ctx context.Context, p ReportPeriod) (int64, error)

	CustomerTotals(ctx context.Context, p ReportPeriod) (CustomerReportTotals, error)
	TopCustomers(ctx context.Context, p ReportPeriod, limit int) ([]CustomerReportRow, error)
	CustomerBuckets(ctx context.Context, p ReportPeriod, groupBy string) (map[string]CustomerBucket, error)
	Registrations(ctx context.Context, p ReportPeriod) (int64, error)
}
