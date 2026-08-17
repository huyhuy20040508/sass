package domain

import (
	"context"
	"time"
)

// ProductFilter là tham số lọc/phân trang khi liệt kê sản phẩm.
type ProductFilter struct {
	Keyword    string
	CategoryID *uint
	// CategoryIDs do service tự nở từ CategoryID: gồm chính nó + mọi danh mục
	// con/cháu (cây danh mục tối đa 3 cấp: Câu lạc bộ → giải đấu → CLB).
	// Có phần tử thì repo lọc IN theo danh sách này thay cho CategoryID.
	CategoryIDs []uint
	BrandID     *uint
	KitType     string
	IsFeatured  *bool
	IsActive    *bool // lọc chính xác theo trạng thái (nil = không lọc)
	OnSale      *bool // true = chỉ sản phẩm đang giảm giá (sale_price hợp lệ < base_price)
	MinPrice    *float64
	MaxPrice    *float64
	Sort        string // newest | price_asc | price_desc | best_selling
	Page        int
	PageSize    int

	// IncludeInactive = true: bỏ ràng buộc is_active mặc định (admin xem cả sản phẩm ẩn).
	// Bị bỏ qua khi IsActive != nil (lúc đó lọc theo IsActive).
	IncludeInactive bool

	// Status lọc chính xác theo trạng thái kinh doanh (rỗng = không lọc). Chi tiết
	// hơn IsActive: tạm ẩn và ngừng kinh doanh đều có is_active = 0.
	Status string

	// Slim = true: bỏ nạp kèm biến thể và thư viện ảnh. Trang danh sách ngoài cửa
	// hàng chỉ cần ảnh đại diện + giá, nạp cả hai thứ kia cho 12 sản phẩm là kéo
	// theo hàng trăm dòng rồi vứt đi.
	Slim bool
}

// CustomerFilter là tham số lọc/sắp xếp/phân trang khi liệt kê khách hàng.
type CustomerFilter struct {
	Keyword  string
	Status   string // all | active | inactive
	Gender   string // all | male | female | other
	Sort     string // newest | oldest | name_asc | name_desc | spent_desc
	Page     int
	PageSize int
}

// InternalUserFilter là tham số lọc/sắp xếp/phân trang khi liệt kê tài khoản NỘI BỘ
// (mọi vai trò trừ customer). Khách hàng có luồng riêng — xem CustomerFilter.
type InternalUserFilter struct {
	Keyword  string
	RoleID   uint   // 0 = mọi vai trò nội bộ
	Status   string // all | active | inactive
	FromDate *time.Time
	ToDate   *time.Time
	Sort     string // newest | oldest | name_asc | name_desc
	Page     int
	PageSize int
}

// InternalUserStats — đếm tài khoản nội bộ theo trạng thái (không phụ thuộc bộ lọc).
type InternalUserStats struct {
	Total    int64 `json:"total"`
	Active   int64 `json:"active"`
	Inactive int64 `json:"inactive"`
}

// CustomerAggregate — số liệu mua hàng tổng hợp của một khách hàng.
type CustomerAggregate struct {
	UserID      uint
	TotalOrders int64
	TotalSpent  float64
	LastOrderAt *time.Time
}

// CustomerStats — đếm khách hàng theo trạng thái tài khoản (active | inactive).
type CustomerStats struct {
	Total    int64 `json:"total"`
	Active   int64 `json:"active"`
	Inactive int64 `json:"inactive"`
}

// CheckoutLine — một dòng hàng khách muốn mua, ở dạng "định danh" chứ chưa có giá.
// Biến thể được xác định bằng ID nếu có, không thì tra theo slug sản phẩm + size + màu.
type CheckoutLine struct {
	VariantID uint
	Slug      string
	Size      string
	Color     string
	Quantity  int
	// DiscountPercent là mức bớt giá của RIÊNG dòng này, do người bán ở quầy bấm.
	// Luôn 0 với đơn khách đặt trên web: ở đó không có ai để bấm, và một trường
	// giảm giá mà client tự gửi lên được là một cách tự phát mã giảm giá.
	DiscountPercent    float64
	CustomPlayerName   string
	CustomPlayerNumber string
}

// CheckoutVariant — thông tin biến thể đã tra từ DB (giá, tồn kho, snapshot sản phẩm).
// Đây mới là nguồn sự thật về giá; giá client gửi lên bị bỏ qua hoàn toàn.
type CheckoutVariant struct {
	VariantID   uint
	ProductID   uint
	ProductName string
	Slug        string
	SKU         string
	// Barcode chỉ được điền ở đường QUÉT MÃ (ScanVariant) — luồng đặt hàng không
	// cần tới nó, và không đọc thừa một cột cho mọi dòng giỏ hàng chỉ vì một màn
	// hình dùng tới.
	Barcode   string
	Size      string
	Color     string
	Thumbnail string
	// CategoryID / BrandID lấy sẵn để đối chiếu phạm vi chương trình khuyến mãi mà
	// không phải hỏi thêm bảng products lần nữa ngay giữa giao dịch đặt hàng.
	CategoryID uint
	BrandID    *uint
	// Price là giá hiệu lực TRƯỚC khuyến mãi (giá riêng biến thể > sale_price > giá
	// gốc). Chương trình khuyến mãi được trừ thêm ở tầng service — xem
	// promotionService.ApplyToCheckout.
	Price float64
	// CostPrice là giá vốn một đơn vị tại thời điểm tra, để CHỤP vào dòng hàng của
	// đơn. nil = cửa hàng chưa khai giá vốn cho món này — giữ nil chứ đừng quy về
	// 0, vì 0 sẽ hiện lên báo cáo thành "lãi 100%".
	CostPrice *float64
	Stock     int
	// PromotionName là tên chương trình đã áp cho dòng này (rỗng = không có), chỉ
	// để hiển thị lại cho khách xem giỏ hàng.
	PromotionName string
}

// OrderFilter là tham số lọc/sắp xếp/phân trang khi liệt kê đơn hàng.
type OrderFilter struct {
	Keyword       string // mã đơn / tên / SĐT / email người nhận
	Status        string // all | pending | confirmed | ... | returned
	PaymentStatus string // all | pending | paid | failed | refunded
	PaymentMethod string // all | cod | vnpay | momo | bank_transfer | cash
	// Channel: all | web | pos — nơi đơn phát sinh. Đây là bộ lọc tách được doanh
	// thu quầy khỏi doanh thu giao hàng, hai thứ có cách vận hành khác hẳn nhau.
	Channel  string
	UserID   *uint  // lọc đơn của một khách hàng
	FromDate string // YYYY-MM-DD (theo created_at)
	ToDate   string // YYYY-MM-DD
	Sort     string // newest | oldest | total_desc | total_asc
	Page     int
	PageSize int
}

// StockRelease yêu cầu trả TOÀN BỘ hàng của đơn về kho — dùng khi đơn khép lại
// (huỷ / hoàn hàng). Số trả về đúng bằng số đơn đã lấy khỏi kho theo sổ kho, nên
// đơn chưa từng trừ kho thì không cộng khống.
type StockRelease struct {
	Type string // loại bút toán ghi sổ kho: return | adjustment
	Note string // ghi chú kèm mã đơn, VD "Huỷ đơn"
}

// OrderStats — số đơn theo trạng thái + doanh thu (không tính đơn huỷ/hoàn).
// Các trường là số ĐÃ GỘP cho bảng tổng quan (vd Processing = đã xác nhận +
// đang chuẩn bị).
type OrderStats struct {
	Total      int64   `json:"total"`
	Pending    int64   `json:"pending"`
	Processing int64   `json:"processing"`
	Shipping   int64   `json:"shipping"`
	Completed  int64   `json:"completed"`
	Cancelled  int64   `json:"cancelled"`
	Revenue    float64 `json:"revenue"`
}

// RevenuePoint — doanh thu và số đơn của MỘT ngày.
type RevenuePoint struct {
	Date    string  `json:"date" example:"2026-07-28"`
	Orders  int64   `json:"orders"`
	Revenue float64 `json:"revenue"`
}

// RevenueSummary — chuỗi doanh thu theo ngày kèm số liệu của KỲ TRƯỚC cùng độ dài,
// để giao diện tính được mức tăng/giảm mà không phải gọi thêm lần nữa.
//
// Points luôn đủ số ngày yêu cầu, ngày không có đơn nào vẫn có mặt với giá trị 0 —
// nếu để trống, biểu đồ sẽ nối thẳng qua ngày đó và vẽ sai đường.
type RevenueSummary struct {
	Days         int            `json:"days"`
	Points       []RevenuePoint `json:"points"`
	TotalRevenue float64        `json:"total_revenue"`
	TotalOrders  int64          `json:"total_orders"`
	PrevRevenue  float64        `json:"prev_revenue"`
	PrevOrders   int64          `json:"prev_orders"`
}

// OrderRepository — truy cập bảng orders.
type OrderRepository interface {
	List(ctx context.Context, f OrderFilter) ([]Order, int64, error)
	FindByID(ctx context.Context, id uint) (*Order, error)
	// Create tạo đơn mới (kèm sản phẩm) trong một transaction, tự sinh mã đơn theo
	// ID, TRỪ TỒN KHO (khoá biến thể, ghi sổ kho) và ghi mốc lịch sử khởi tạo.
	// Không đủ hàng thì trả ErrOutOfStock và cả đơn bị rollback.
	Create(ctx context.Context, o *Order) error
	// UserExists kiểm tra khách hàng (user) có tồn tại và chưa bị xoá — dùng để
	// đảm bảo đơn thủ công chỉ gắn với khách có sẵn.
	UserExists(ctx context.Context, id uint) (bool, error)
	// Checkout tạo đơn từ storefront trong MỘT transaction: khoá các biến thể được
	// mua (SELECT ... FOR UPDATE), kiểm tra tồn kho, trừ kho, ghi sổ kho rồi mới
	// tạo đơn. Khoá trước khi kiểm tra là để hai khách mua cùng lúc không bán vượt
	// số hàng đang có. resolve nhận danh sách biến thể đã khoá và trả về đơn hoàn
	// chỉnh cùng số lượng cần trừ theo từng biến thể.
	//
	// build cũng trả về yêu cầu tiêu voucher (nil nếu khách không nhập mã). Lượt
	// voucher được chốt trong CÙNG transaction này: hết lượt ngay lúc đó thì cả đơn
	// bị rollback, thay vì tạo đơn xong mới phát hiện mã không dùng được nữa.
	Checkout(ctx context.Context, lines []CheckoutLine, build func(map[uint]CheckoutVariant) (*Order, *VoucherClaim, error)) (*Order, error)
	// DoiHang thực hiện MỘT lượt đổi hàng tại quầy trong một transaction: khoá đơn
	// cũ, nhận hàng cũ về kho, tạo đơn mới đã trừ kho, nối hai vế lại và ghi chênh
	// lệch tiền mặt vào sổ quỹ. Rời nhau ra thì một lần sập giữa chừng để lại lệch
	// kho câm — không lỗi nào nổi lên, chỉ có con số sai từ hôm đó.
	//
	// build nhận đơn cũ ĐÃ KHOÁ, số còn trả được của từng dòng, và bảng giá hàng
	// mới đã khoá biến thể; trả về phiếu trả, đơn mới, và dòng sổ quỹ (nil = lượt
	// đổi không đụng tới tiền mặt).
	DoiHang(ctx context.Context, orderCu uint, lines []CheckoutLine,
		build func(cu *Order, conTraDuoc map[uint]ReturnableItem, giaMoi map[uint]CheckoutVariant) (*OrderReturn, *Order, *SoQuy, error),
	) (*OrderReturn, *Order, error)
	// ScanVariant tra MỘT biến thể theo mã vạch hoặc SKU (mã người bán vừa quét),
	// kèm giá và tồn của chi nhánh đang bán — cùng đường tra giá với lúc đặt hàng.
	// Không tìm thấy, hoặc sản phẩm cha đã ẩn/xoá, đều trả ErrVariantNotFound.
	ScanVariant(ctx context.Context, code string) (*CheckoutVariant, error)
	// QuoteVariants tra GIÁ và TỒN KHO hiện tại của các dòng giỏ hàng. Chỉ đọc:
	// không khoá dòng, không đụng tới kho. Dòng nào không tra được (sản phẩm đã ẩn
	// hoặc biến thể không còn) thì vắng mặt trong kết quả thay vì báo lỗi cả giỏ.
	QuoteVariants(ctx context.Context, lines []CheckoutLine) (map[uint]CheckoutVariant, error)
	// FindByCode tra cứu đơn theo mã đơn (order_code) — dùng cho tra cứu công khai.
	FindByCode(ctx context.Context, code string) (*Order, error)
	Stats(ctx context.Context) (OrderStats, error)
	// RevenueSeries trả doanh thu + số đơn theo TỪNG NGÀY trong `days` ngày gần
	// nhất (tính cả hôm nay), kèm tổng của kỳ trước cùng độ dài để so sánh.
	// Cùng quy ước với Stats: không tính đơn huỷ / hoàn hàng vào doanh thu.
	RevenueSeries(ctx context.Context, days int) (RevenueSummary, error)
	// LockAndUpdate thực hiện đọc-sửa-ghi an toàn dưới khoá dòng (SELECT ... FOR
	// UPDATE) trong một transaction, tránh ghi đè mất dữ liệu khi nhiều admin thao
	// tác đồng thời. apply nhận bản đơn mới nhất (đã khoá), mutate nó, rồi trả về:
	// mốc lịch sử cần ghi (nil nếu không có), danh sách CỘT thay đổi cần lưu, và
	// yêu cầu trả hàng về kho (nil nếu đơn không khép lại). Chỉ các cột đó được ghi
	// (Select), nên thao tác này không đụng tới cột khác.
	LockAndUpdate(ctx context.Context, id uint, apply func(o *Order) (*OrderStatusHistory, []string, *StockRelease, error)) (*Order, error)
	// UpdateDetail sửa thông tin đơn + THAY TOÀN BỘ danh sách sản phẩm trong một
	// transaction dưới khoá dòng. mutate nhận bản đơn mới nhất (đã khoá, kèm items
	// cũ), kiểm tra điều kiện sửa rồi mutate các cột vô hướng của đơn; nó trả về:
	// danh sách CỘT cần lưu, danh sách sản phẩm MỚI (thay hết dòng cũ) và mốc lịch
	// sử cần ghi (nil nếu không). Chỉ các cột được chọn mới bị ghi. Tồn kho được
	// chỉnh theo đúng phần CHÊNH giữa danh sách mới và số đơn đang giữ của kho.
	UpdateDetail(ctx context.Context, id uint, mutate func(o *Order) ([]string, []OrderItem, *OrderStatusHistory, error)) (*Order, error)
	// Histories trả lịch sử chuyển trạng thái của đơn, cũ -> mới.
	Histories(ctx context.Context, orderID uint) ([]OrderStatusHistory, error)
}

// PaymentRepository lưu các lần thử thanh toán qua cổng (bảng payments).
type PaymentRepository interface {
	Create(ctx context.Context, p *Payment) error
	// FindByTransactionCode tra một lần thử theo mã giao dịch phía cổng. Đây là
	// đường webhook đi từ "PayOS báo đã thu tiền" về tới đơn hàng của mình.
	FindByTransactionCode(ctx context.Context, code string) (*Payment, error)
	// FindOpenByOrder trả lần thử CÒN HIỆU LỰC gần nhất của một đơn: còn chờ trả
	// tiền và chưa quá hạn. Có nó thì khách bấm "thanh toán lại" được mở đúng link
	// cũ thay vì sinh thêm một link mới cho cùng một khoản tiền.
	FindOpenByOrder(ctx context.Context, orderID uint, now time.Time) (*Payment, error)
	// FindPendingByContent tìm lần thử đang chờ mà MÃ GIAO DỊCH của nó nằm trong
	// `content` — nội dung chuyển khoản đã được chuẩn hoá (bỏ ký tự lạ, viết hoa).
	//
	// Đây là cách duy nhất để đối soát với SePay: cổng đó chỉ báo "tài khoản vừa
	// nhận X đồng, nội dung là Y", không hề biết đơn hàng nào. Mã đơn nằm trong nội
	// dung chính là sợi dây nối hai bên.
	FindPendingByContent(ctx context.Context, provider, content string) (*Payment, error)
	// MarkSettled chốt kết quả một lần thử: đổi trạng thái, lưu nguyên văn phản hồi
	// của cổng và mốc thu tiền. Chỉ ghi khi lần thử ĐANG ở trạng thái chờ, và trả
	// về false nếu không có dòng nào bị đổi — đó là dấu hiệu webhook gửi lặp, phần
	// việc phía sau (ghi nhận đơn đã trả tiền, báo cho nhân viên) phải bỏ qua.
	MarkSettled(ctx context.Context, id uint, status, gatewayResponse string, paidAt *time.Time) (bool, error)
}

// ReturnFilter là tham số lọc/sắp xếp/phân trang khi liệt kê phiếu trả hàng.
type ReturnFilter struct {
	Keyword  string // mã phiếu / mã đơn / tên / SĐT người nhận
	Status   string // all | pending | approved | received | refunded | rejected | cancelled
	Reason   string // all | defective | wrong_item | ...
	OrderID  *uint
	UserID   *uint  // lọc phiếu của một khách hàng
	FromDate string // YYYY-MM-DD (theo created_at)
	ToDate   string // YYYY-MM-DD
	Sort     string // newest | oldest | amount_desc | amount_asc
	Page     int
	PageSize int
}

// ReturnStats — số phiếu theo trạng thái + tổng tiền đã hoàn thực tế.
type ReturnStats struct {
	Total     int64 `json:"total"`
	Pending   int64 `json:"pending"`
	Approved  int64 `json:"approved"`
	Received  int64 `json:"received"`
	Refunded  int64 `json:"refunded"`
	Rejected  int64 `json:"rejected"`
	Cancelled int64 `json:"cancelled"`
	// Refunded amount chỉ cộng phiếu đã hoàn tiền xong, không cộng phiếu đang chờ.
	RefundedAmount float64 `json:"refunded_amount"`
}

// ReturnableItem — một dòng hàng của đơn kèm số lượng CÒN trả được.
// Số còn lại = số đã mua trừ số đang nằm trong các phiếu trả chưa bị từ chối/huỷ,
// nên khách không thể trả cùng một món hai lần qua hai phiếu.
type ReturnableItem struct {
	OrderItemID      uint    `json:"order_item_id"`
	ProductID        *uint   `json:"product_id"`
	ProductVariantID *uint   `json:"product_variant_id"`
	ProductName      string  `json:"product_name"`
	VariantSKU       string  `json:"variant_sku"`
	Size             string  `json:"size"`
	Color            string  `json:"color"`
	Thumbnail        string  `json:"thumbnail"`
	UnitPrice        float64 `json:"unit_price"`
	Quantity         int     `json:"quantity"`          // số đã mua
	ReturnedQuantity int     `json:"returned_quantity"` // số đang/đã trả
	RemainQuantity   int     `json:"remain_quantity"`   // số còn trả được
}

// ReturnLine — một dòng khách/admin chọn trả: dòng hàng nào, mấy cái.
type ReturnLine struct {
	OrderItemID uint
	Quantity    int
}

// OrderReturnRepository — truy cập bảng order_returns và các bảng đi kèm.
type OrderReturnRepository interface {
	List(ctx context.Context, f ReturnFilter) ([]OrderReturn, int64, error)
	// FindByID trả phiếu kèm danh sách món và đơn gốc.
	FindByID(ctx context.Context, id uint) (*OrderReturn, error)
	Stats(ctx context.Context) (ReturnStats, error)
	// Histories trả lịch sử chuyển trạng thái của phiếu, cũ -> mới.
	Histories(ctx context.Context, returnID uint) ([]OrderReturnHistory, error)

	// Returnable liệt kê các dòng hàng của đơn kèm số lượng còn trả được.
	Returnable(ctx context.Context, orderID uint) (*Order, []ReturnableItem, error)
	// CountActive đếm số phiếu trả còn hiệu lực (chưa bị từ chối/huỷ) của một đơn.
	// Luồng đơn hàng dùng nó để chặn việc chuyển cả đơn sang "hoàn hàng" khi đã có
	// phiếu trả riêng — hai đường đều trả hàng về kho, đi cả hai là cộng khống.
	CountActive(ctx context.Context, orderID uint) (int64, error)

	// Create tạo phiếu trả trong MỘT transaction: khoá đơn, tính lại số còn trả
	// được ngay dưới khoá (hai yêu cầu gửi cùng lúc không thể cùng trả nốt phần
	// còn lại), chụp thông tin sản phẩm rồi sinh mã phiếu theo ID.
	// build nhận đơn đã khoá + số còn trả được của từng dòng, trả phiếu hoàn chỉnh.
	Create(ctx context.Context, orderID uint, build func(o *Order, remain map[uint]ReturnableItem) (*OrderReturn, error)) (*OrderReturn, error)

	// LockAndUpdate đọc-sửa-ghi phiếu dưới khoá dòng. apply nhận bản mới nhất (đã
	// khoá, kèm món), mutate rồi trả về: mốc lịch sử cần ghi, danh sách CỘT cần
	// lưu, và cờ tác dụng phụ cần chạy trong cùng transaction.
	LockAndUpdate(ctx context.Context, id uint, apply func(r *OrderReturn) (*OrderReturnHistory, []string, *ReturnEffects, error)) (*OrderReturn, error)
}

// ReturnEffects mô tả các tác dụng phụ phải chạy CÙNG transaction với việc đổi
// trạng thái phiếu, để không có trạng thái nửa vời (đã ghi "đã nhận hàng" mà kho
// chưa nhập lại).
type ReturnEffects struct {
	// Restock = true: nhập lại kho đúng số lượng từng món của phiếu, ghi bút toán
	// inventory_transactions với reference_type='order_return'. Đồng thời trừ
	// products.sold_count vì phần hàng này không còn được tính là đã bán.
	Restock bool
	// SettleOrder = true: kiểm tra xem đơn đã được trả HẾT chưa, nếu rồi thì
	// chuyển đơn sang trạng thái "returned" và ghi mốc vào lịch sử đơn.
	SettleOrder bool
	// RecordRefund = true: ghi một dòng vào bảng payments (status='refunded') và
	// đánh dấu đơn đã hoàn tiền nếu toàn bộ đơn đã được hoàn.
	RecordRefund bool
	// ActorID là nhân viên/khách thực hiện, dùng cho các bản ghi phụ.
	ActorID uint
}

// ---------- Đặt hàng nhập ----------

// SupplierFilter là tham số lọc khi liệt kê nhà cung cấp.
type SupplierFilter struct {
	Keyword string // tên / mã / SĐT / người liên hệ
	// OnlyActive = true: chỉ nhà cung cấp đang hợp tác (dropdown lập phiếu dùng cái này).
	OnlyActive bool
}

// SupplierRepository — truy cập bảng suppliers.
type SupplierRepository interface {
	List(ctx context.Context, f SupplierFilter) ([]Supplier, error)
	FindByID(ctx context.Context, id uint) (*Supplier, error)
	// ExistsByCode tính cả nhà cung cấp đã xoá mềm — mã vẫn bị UNIQUE index giữ chỗ.
	ExistsByCode(ctx context.Context, code string, excludeID uint) (bool, error)
	// NextCode sinh mã kế tiếp theo dạng NCC001 khi người dùng không tự khai.
	NextCode(ctx context.Context) (string, error)
	Create(ctx context.Context, s *Supplier) error
	Update(ctx context.Context, s *Supplier) error
	// Delete xoá mềm; nhà cung cấp còn phiếu đặt hàng thì trả ErrConflict — xoá đi
	// là các phiếu cũ mất luôn đầu mối liên hệ.
	Delete(ctx context.Context, id uint) error
}

// PurchaseFilter là tham số lọc/sắp xếp/phân trang khi liệt kê phiếu đặt hàng nhập.
type PurchaseFilter struct {
	Keyword       string // mã phiếu / tên nhà cung cấp / ghi chú
	Status        string // all | draft | ordered | partial | received | cancelled (cho phép nhiều giá trị, ngăn bởi dấu phẩy)
	PaymentStatus string // all | unpaid | partial | paid
	SupplierID    *uint
	FromDate      string // YYYY-MM-DD (theo created_at)
	ToDate        string // YYYY-MM-DD
	Sort          string // newest | oldest | total_desc | total_asc | expected_asc
	Page          int
	PageSize      int
}

// PurchaseStats — số phiếu theo trạng thái + tiền hàng đang chờ về và công nợ.
type PurchaseStats struct {
	Total     int64 `json:"total"`
	Draft     int64 `json:"draft"`
	Ordered   int64 `json:"ordered"`
	Partial   int64 `json:"partial"`
	Received  int64 `json:"received"`
	Cancelled int64 `json:"cancelled"`
	// IncomingQuantity là số hàng ĐÃ ĐẶT MÀ CHƯA VỀ (chỉ tính phiếu đang chờ) —
	// con số này mới nói được "sắp hết hàng nhưng đã có hàng trên đường".
	IncomingQuantity int64 `json:"incoming_quantity"`
	// IncomingValue là giá trị số hàng đang chờ về, theo giá nhập.
	IncomingValue float64 `json:"incoming_value"`
	// DebtAmount là tiền còn nợ nhà cung cấp của các phiếu chưa huỷ.
	DebtAmount float64 `json:"debt_amount"`
}

// PurchaseLine — một dòng hàng người dùng đưa vào phiếu: biến thể nào, mấy cái,
// giá nhập bao nhiêu. Tên/SKU/ảnh do server chụp lại từ biến thể, không nhận từ
// client — nếu không thì phiếu ghi một đằng, kho cộng một nẻo.
type PurchaseLine struct {
	VariantID uint
	Quantity  int
	UnitCost  float64
}

// PurchaseReceiptLine — một dòng trong đợt nhận hàng: dòng phiếu nào, nhận mấy cái.
type PurchaseReceiptLine struct {
	ItemID   uint
	Quantity int
}

// PurchaseReceipt là một ĐỢT nhận hàng của phiếu đặt.
type PurchaseReceipt struct {
	Lines []PurchaseReceiptLine
	// UpdateCost = true: ghi luôn giá nhập của đợt này vào giá vốn của biến thể.
	// Mặc định bật — giá vốn mới nhất là giá vừa mua, và trang tồn kho đang chờ
	// đúng con số đó để tính giá trị kho.
	UpdateCost bool
	Note       string
	ActorID    uint
}

// PurchaseVariant — thông tin biến thể server tra được khi dựng dòng phiếu.
// Cũng là dữ liệu trả thẳng ra API cho dropdown chọn hàng, nên có json tag.
type PurchaseVariant struct {
	VariantID   uint   `json:"variant_id"`
	ProductID   uint   `json:"product_id"`
	ProductName string `json:"product_name"`
	SKU         string `json:"sku"`
	Size        string `json:"size"`
	Color       string `json:"color"`
	Thumbnail   string `json:"thumbnail"`
	// CostPrice là giá vốn hiệu lực đang khai (nil = chưa khai) — dùng để gợi ý
	// giá nhập cho người lập phiếu.
	CostPrice *float64 `json:"cost_price"`
	Stock     int      `json:"stock"`
}

// PurchaseOrderRepository — truy cập bảng purchase_orders và các bảng đi kèm.
type PurchaseOrderRepository interface {
	List(ctx context.Context, f PurchaseFilter) ([]PurchaseOrder, int64, error)
	FindByID(ctx context.Context, id uint) (*PurchaseOrder, error)
	Stats(ctx context.Context) (PurchaseStats, error)
	// Histories trả lịch sử thao tác của phiếu, cũ -> mới.
	Histories(ctx context.Context, purchaseID uint) ([]PurchaseOrderHistory, error)

	// SearchVariants tìm biến thể để đưa vào phiếu (dropdown chọn hàng).
	SearchVariants(ctx context.Context, keyword string, limit int) ([]PurchaseVariant, error)
	// LookupVariants tra thông tin các biến thể theo ID — dùng để chụp snapshot khi
	// tạo/sửa phiếu. Biến thể không tồn tại thì vắng mặt trong kết quả.
	LookupVariants(ctx context.Context, ids []uint) (map[uint]PurchaseVariant, error)

	// Create tạo phiếu trong MỘT transaction, tự sinh mã phiếu theo ID và ghi mốc
	// lịch sử khởi tạo. Chưa đụng gì tới tồn kho: hàng chưa về.
	Create(ctx context.Context, po *PurchaseOrder) error
	// Update sửa thông tin + THAY TOÀN BỘ danh sách hàng của phiếu dưới khoá dòng.
	// mutate nhận bản mới nhất (đã khoá, kèm dòng hàng cũ), kiểm tra điều kiện sửa
	// rồi mutate các cột vô hướng; trả về danh sách CỘT cần lưu và danh sách hàng
	// MỚI. Chỉ các cột được chọn mới bị ghi.
	Update(ctx context.Context, id uint, mutate func(po *PurchaseOrder) ([]string, []PurchaseOrderItem, error)) (*PurchaseOrder, error)
	// LockAndUpdate đọc-sửa-ghi phiếu dưới khoá dòng. apply nhận bản mới nhất (đã
	// khoá, kèm dòng hàng), mutate rồi trả về mốc lịch sử cần ghi và các CỘT cần lưu.
	LockAndUpdate(ctx context.Context, id uint, apply func(po *PurchaseOrder) (*PurchaseOrderHistory, []string, error)) (*PurchaseOrder, error)
	// Receive ghi nhận MỘT đợt nhận hàng trong một transaction: khoá phiếu, cộng số
	// đã nhận của từng dòng, CỘNG TỒN KHO và ghi bút toán inventory_transactions
	// (type='import', reference_type='purchase_order'), rồi tự chuyển phiếu sang
	// "nhận một phần" hoặc "đã nhận đủ".
	//
	// Nhận vượt số đặt trả ErrPurchaseQtyExceeded và KHÔNG dòng nào được ghi: một
	// đợt nhận hàng là một lần cân đối kho, ghi được nửa chừng còn tệ hơn báo lỗi.
	Receive(ctx context.Context, id uint, r PurchaseReceipt) (*PurchaseOrder, error)
	// Delete xoá mềm phiếu — chỉ dùng cho phiếu nháp (chưa đụng tới kho).
	Delete(ctx context.Context, id uint) error
}

// ---------- Trả hàng nhập (trả hàng lại nhà cung cấp) ----------

// PurchaseReturnFilter là tham số lọc/sắp xếp/phân trang khi liệt kê phiếu trả.
type PurchaseReturnFilter struct {
	Keyword      string // mã phiếu trả / mã phiếu đặt / nhà cung cấp / ghi chú
	Status       string // "" | all | draft | returned | refunded | cancelled (cho phép CSV)
	RefundStatus string // "" | all | unpaid | partial | paid
	SupplierID   *uint
	Reason       string
	FromDate     string // YYYY-MM-DD theo ngày lập phiếu
	ToDate       string
	Sort         string // newest | oldest | amount_desc | amount_asc
	Page         int
	PageSize     int
}

// PurchaseReturnStats — số liệu đầu trang Trả hàng nhập.
type PurchaseReturnStats struct {
	Total     int64 `json:"total"`
	Draft     int64 `json:"draft"`
	Returned  int64 `json:"returned"`
	Refunded  int64 `json:"refunded"`
	Cancelled int64 `json:"cancelled"`
	// ReturnedQuantity / ReturnedAmount tính trên phiếu ĐÃ TRẢ (kể cả đã hoàn tiền);
	// phiếu nháp chưa trả thật, phiếu huỷ thì không tính.
	ReturnedQuantity int64   `json:"returned_quantity"`
	ReturnedAmount   float64 `json:"returned_amount"`
	// PendingRefund là số tiền NCC còn phải hoàn cho các phiếu đã trả.
	PendingRefund float64 `json:"pending_refund"`
}

// PurchaseReturnable — một dòng của phiếu đặt còn có thể trả lại nhà cung cấp.
//
// Remain = đã nhận − đã nằm trong các phiếu trả chưa huỷ. Stock là tồn kho hiện
// tại của biến thể: trả nhiều hơn số đang có trong kho là không được, vì lúc
// chuyển phiếu sang "đã trả" kho sẽ bị trừ thật.
type PurchaseReturnable struct {
	PurchaseOrderItemID uint    `json:"purchase_order_item_id"`
	ProductID           *uint   `json:"product_id"`
	ProductVariantID    *uint   `json:"product_variant_id"`
	ProductName         string  `json:"product_name"`
	VariantSKU          string  `json:"variant_sku"`
	Size                string  `json:"size"`
	Color               string  `json:"color"`
	Thumbnail           string  `json:"thumbnail"`
	UnitCost            float64 `json:"unit_cost"`
	Received            int     `json:"received"`
	Returned            int     `json:"returned"`
	Remain              int     `json:"remain"`
	Stock               int     `json:"stock"`
}

// PurchaseReturnRepository — truy cập bảng purchase_returns và các bảng đi kèm.
type PurchaseReturnRepository interface {
	List(ctx context.Context, f PurchaseReturnFilter) ([]PurchaseReturn, int64, error)
	FindByID(ctx context.Context, id uint) (*PurchaseReturn, error)
	Stats(ctx context.Context) (PurchaseReturnStats, error)
	// Histories trả lịch sử thao tác của phiếu, cũ -> mới.
	Histories(ctx context.Context, id uint) ([]PurchaseReturnHistory, error)
	// Returnable liệt kê các dòng của MỘT phiếu đặt còn trả lại được.
	Returnable(ctx context.Context, purchaseOrderID uint) ([]PurchaseReturnable, error)
	// Create tạo phiếu trong một transaction, tự sinh mã phiếu theo ID và ghi mốc
	// lịch sử khởi tạo. Chưa đụng tới tồn kho.
	Create(ctx context.Context, rt *PurchaseReturn) error
	// Update sửa thông tin + THAY TOÀN BỘ dòng hàng dưới khoá dòng (chỉ phiếu nháp).
	Update(ctx context.Context, id uint, mutate func(rt *PurchaseReturn) ([]string, []PurchaseReturnItem, error)) (*PurchaseReturn, error)
	// MarkReturned chuyển phiếu nháp sang "đã trả NCC": TRỪ tồn kho và ghi bút toán
	// inventory_transactions (type='export', reference_type='purchase_return') trong
	// một transaction. Kho không đủ hàng thì trả ErrOutOfStock và không ghi gì.
	MarkReturned(ctx context.Context, id uint, actorID uint) (*PurchaseReturn, error)
	// LockAndUpdate đọc-sửa-ghi phiếu dưới khoá dòng (huỷ phiếu, ghi nhận hoàn tiền).
	LockAndUpdate(ctx context.Context, id uint, apply func(rt *PurchaseReturn) (*PurchaseReturnHistory, []string, error)) (*PurchaseReturn, error)
	// Delete xoá mềm phiếu — chỉ dùng cho phiếu nháp (chưa đụng tới kho).
	Delete(ctx context.Context, id uint) error
}

// ---------- Nhập hàng (đợt nhận hàng về kho) ----------

// GoodsReceipt — MỘT ĐỢT nhập hàng về kho theo phiếu đặt hàng.
//
// KHÔNG có bảng riêng cho đợt nhập: mỗi lần nhận hàng, tầng kho ghi một loạt bút
// toán inventory_transactions (type='import', reference_type='purchase_order')
// trong cùng một transaction. Đợt nhập được dựng lại bằng cách gom các bút toán
// CÙNG PHIẾU nằm sát nhau về thời gian — nhờ vậy mọi đợt đã nhận từ trước cũng
// hiện đủ, không phải thêm bảng rồi vá dữ liệu cũ.
type GoodsReceipt struct {
	// Code là mã đợt nhập: <mã phiếu đặt>-N<đợt>. Suy ra từ dữ liệu nên ổn định,
	// in ra giấy đối chiếu được, và tự nói rõ đợt này thuộc phiếu nào.
	Code            string `json:"code"`
	Batch           int    `json:"batch"`
	PurchaseOrderID uint   `json:"purchase_order_id"`
	POCode          string `json:"po_code"`
	SupplierID      *uint  `json:"supplier_id"`
	SupplierName    string `json:"supplier_name"`
	// POStatus là trạng thái HIỆN TẠI của phiếu đặt (đã nhận đủ / nhận một phần).
	POStatus string `json:"po_status"`

	// ReceivedAt là lúc đợt nhận được ghi xong (mốc lịch sử của phiếu).
	ReceivedAt time.Time `json:"received_at"`
	// FromAt là mốc đóng của đợt TRƯỚC (rỗng nếu đây là đợt đầu). Cặp
	// (FromAt, ReceivedAt] khoanh đúng các bút toán kho thuộc đợt này.
	FromAt time.Time `json:"from_at"`

	LineCount     int     `json:"line_count"`
	Quantity      int     `json:"quantity"`
	Amount        float64 `json:"amount"`
	CreatedByName string  `json:"created_by_name"`
	Note          string  `json:"note"`

	Items []GoodsReceiptItem `json:"items,omitempty"`
}

// GoodsReceiptItem — một dòng hàng trong đợt nhập, kèm tồn kho trước/sau để đối
// chiếu lại được với sổ kho.
type GoodsReceiptItem struct {
	TransactionID  uint    `json:"transaction_id"`
	VariantID      uint    `json:"variant_id"`
	ProductID      *uint   `json:"product_id"`
	ProductName    string  `json:"product_name"`
	SKU            string  `json:"sku"`
	Size           string  `json:"size"`
	Color          string  `json:"color"`
	Thumbnail      string  `json:"thumbnail"`
	Quantity       int     `json:"quantity"`
	UnitCost       float64 `json:"unit_cost"`
	Amount         float64 `json:"amount"`
	QuantityBefore int     `json:"quantity_before"`
	QuantityAfter  int     `json:"quantity_after"`
}

// GoodsReceiptFilter là tham số lọc/sắp xếp/phân trang khi liệt kê đợt nhập.
type GoodsReceiptFilter struct {
	Keyword    string // mã đợt / mã phiếu đặt / nhà cung cấp / người nhận / ghi chú
	SupplierID uint
	FromDate   string // YYYY-MM-DD — theo ngày nhận hàng
	ToDate     string
	Sort       string // newest | oldest | qty_desc | amount_desc
	Page       int
	PageSize   int
}

// GoodsReceiptStats — số liệu đầu trang Nhập hàng.
type GoodsReceiptStats struct {
	TotalReceipts int64   `json:"total_receipts"`
	TotalQuantity int64   `json:"total_quantity"`
	TotalAmount   float64 `json:"total_amount"`
	TodayReceipts int64   `json:"today_receipts"`
	TodayQuantity int64   `json:"today_quantity"`
	// Hai số dưới đây là việc CÒN PHẢI LÀM: phiếu đã đặt mà hàng chưa về đủ.
	PendingOrders   int64 `json:"pending_orders"`
	PendingQuantity int64 `json:"pending_quantity"`
}

// GoodsReceiptRepository — dựng đợt nhập hàng từ sổ kho (không có bảng riêng).
type GoodsReceiptRepository interface {
	// List gom bút toán thành đợt nhập rồi lọc / sắp xếp / cắt trang.
	List(ctx context.Context, f GoodsReceiptFilter) ([]GoodsReceipt, int64, error)
	// Find đọc một đợt kèm dòng hàng, theo mã đợt do List sinh ra.
	Find(ctx context.Context, code string) (*GoodsReceipt, error)
	Stats(ctx context.Context) (GoodsReceiptStats, error)
}

// InventoryFilter là tham số lọc/sắp xếp/phân trang khi liệt kê tồn kho.
//
// Đơn vị của trang tồn kho là BIẾN THỂ (product_variants) chứ không phải sản
// phẩm: tồn kho được giữ ở từng biến thể, một áo size M hết hàng không có nghĩa
// là size L cũng hết.
type InventoryFilter struct {
	Keyword    string // tên sản phẩm / SKU biến thể / SKU sản phẩm
	CategoryID *uint
	BrandID    *uint
	Stock      string // all | in | low | out — nhóm theo mức tồn
	// Cost lọc theo tình trạng khai giá vốn: all | missing (chưa khai) | set (đã khai).
	// Có bộ lọc này thì cảnh báo "thiếu giá vốn N biến thể" mới bấm vào được — biết
	// thiếu mà không liệt kê ra được thì con số đó chẳng làm gì được.
	Cost     string
	IsActive *bool // lọc theo trạng thái bán của biến thể (nil = không lọc)
	// LowStock là ngưỡng "sắp hết": tồn > 0 và <= ngưỡng. Cũng dùng cho Stats để
	// hai bên luôn đếm theo cùng một mốc.
	LowStock int
	Sort     string // stock_asc | stock_desc | name_asc | name_desc | value_desc | newest
	Page     int
	PageSize int
}

// InventoryItem là MỘT dòng tồn kho: một biến thể kèm thông tin sản phẩm cha đã
// ghép sẵn, để giao diện không phải gọi thêm lần nữa cho từng dòng.
type InventoryItem struct {
	VariantID   uint   `json:"variant_id"`
	ProductID   uint   `json:"product_id"`
	ProductName string `json:"product_name"`
	Slug        string `json:"slug"`
	Thumbnail   string `json:"thumbnail"`
	SKU         string `json:"sku"`
	Size        string `json:"size"`
	Color       string `json:"color"`
	// KitType là loại áo của sản phẩm cha (fan | player), lấy sẵn để giao diện
	// kho phân biệt được FAN với PLAYER mà không phải gọi thêm.
	KitType       string `json:"kit_type"`
	CategoryName  string `json:"category_name"`
	BrandName     string `json:"brand_name"`
	StockQuantity int    `json:"stock_quantity"`
	// Price là giá HIỆU LỰC của biến thể: giá riêng của biến thể nếu có, không thì
	// giá khuyến mãi, không nữa thì giá gốc của sản phẩm.
	Price float64 `json:"price"`
	// CostPrice là giá VỐN hiệu lực: giá vốn riêng của biến thể nếu có, không thì
	// của sản phẩm cha. nil = CHƯA KHAI giá vốn (khác với giá vốn bằng 0).
	CostPrice *float64 `json:"cost_price"`
	// StockValue = CostPrice * StockQuantity — giá trị hàng đang nằm trong kho,
	// tính theo GIÁ VỐN đúng như kế toán yêu cầu. Biến thể chưa khai giá vốn cho
	// 0₫ ở đây; xem InventoryStats.MissingCost để biết tổng đang thiếu bao nhiêu dòng.
	StockValue float64 `json:"stock_value"`
	IsActive   bool    `json:"is_active"`
	// ProductActive = false: sản phẩm cha đang ẩn, hàng trong kho không bán được.
	ProductActive bool `json:"product_active"`
	// LastMovedAt là lần cuối biến thể này có bút toán kho (nil = chưa từng).
	LastMovedAt *time.Time `json:"last_moved_at"`
}

// InventoryStats — số liệu tổng quan của toàn kho (không phụ thuộc bộ lọc).
type InventoryStats struct {
	TotalVariants int64 `json:"total_variants"`
	TotalQuantity int64 `json:"total_quantity"`
	InStock       int64 `json:"in_stock"`
	LowStock      int64 `json:"low_stock"`
	OutOfStock    int64 `json:"out_of_stock"`
	// StockValue là tổng giá trị kho theo GIÁ VỐN.
	StockValue float64 `json:"stock_value"`
	// MissingCost là số biến thể chưa khai giá vốn, TÍNH CẢ hàng đang hết. Đây là
	// con số cần hành động: hàng hôm nay tồn 0 mai nhập về vẫn sẽ đóng góp 0₫ nếu
	// không ai khai giá vốn cho nó.
	MissingCost int64 `json:"missing_cost"`
	// MissingCostInStock là phần trong số trên mà ĐANG CÒN HÀNG — đúng những dòng
	// làm StockValue bị hụt ngay lúc này.
	MissingCostInStock int64 `json:"missing_cost_in_stock"`
}

// InventoryHistory — một dòng sổ kho đã ghép sẵn tên người thực hiện và mã chứng
// từ liên quan (mã đơn / mã phiếu trả), để giao diện đọc được ngay.
type InventoryHistory struct {
	ID             uint      `json:"id"`
	Type           string    `json:"type"`
	Quantity       int       `json:"quantity"`
	QuantityBefore int       `json:"quantity_before"`
	QuantityAfter  int       `json:"quantity_after"`
	ReferenceType  string    `json:"reference_type"`
	ReferenceID    *uint     `json:"reference_id"`
	ReferenceCode  string    `json:"reference_code"`
	UnitCost       *float64  `json:"unit_cost"`
	Note           string    `json:"note"`
	CreatedByName  string    `json:"created_by_name"`
	CreatedAt      time.Time `json:"created_at"`
}

// Chế độ điều chỉnh tồn kho.
const (
	// StockModeSet — ĐẶT tồn kho về đúng con số đếm được (kiểm kê).
	StockModeSet = "set"
	// StockModeDelta — CỘNG/TRỪ một lượng vào tồn hiện tại (nhập thêm / xuất bớt).
	StockModeDelta = "delta"
)

// InventoryAdjustment là yêu cầu chỉnh tồn kho của MỘT biến thể.
type InventoryAdjustment struct {
	VariantID uint
	Mode      string // set | delta
	Quantity  int    // số đặt (mode=set) hoặc lượng thay đổi, âm là xuất (mode=delta)
	// Type là loại bút toán ghi vào sổ kho: import | export | adjustment.
	// Rỗng thì service tự suy từ chế độ và dấu của lượng thay đổi.
	Type     string
	UnitCost *float64 // giá vốn khi nhập, chỉ có ý nghĩa với bút toán nhập
	Note     string
}

// InventoryAdjustResult — kết quả chỉnh kho của một biến thể, trả về cho giao
// diện cập nhật lại dòng mà không cần tải lại cả trang.
type InventoryAdjustResult struct {
	VariantID uint   `json:"variant_id"`
	SKU       string `json:"sku"`
	Before    int    `json:"before"`
	After     int    `json:"after"`
	Change    int    `json:"change"`
}

// VariantCost là yêu cầu khai giá vốn cho MỘT biến thể.
//
// CostPrice = nil nghĩa là XOÁ giá vốn riêng, để biến thể quay về lấy theo sản
// phẩm cha — khác với khai giá vốn bằng 0.
type VariantCost struct {
	VariantID uint
	CostPrice *float64
}

// InventoryRepository — truy cập tồn kho (product_variants) và sổ kho
// (inventory_transactions).
type InventoryRepository interface {
	List(ctx context.Context, f InventoryFilter) ([]InventoryItem, int64, error)
	// Stats đếm theo lowStock — cùng ngưỡng với bộ lọc để hai con số không lệch nhau.
	Stats(ctx context.Context, lowStock int) (InventoryStats, error)
	FindItem(ctx context.Context, variantID uint) (*InventoryItem, error)
	// Histories trả sổ kho của một biến thể, mới -> cũ.
	Histories(ctx context.Context, variantID uint, page, pageSize int) ([]InventoryHistory, int64, error)

	// Adjust chỉnh tồn kho trong MỘT transaction, tất-cả-hoặc-không: khoá các biến
	// thể theo ID tăng dần (cùng thứ tự với luồng đặt hàng nên không khoá chéo),
	// tính tồn mới ngay dưới khoá, ghi lại cache stock_quantity và một bút toán
	// inventory_transactions cho mỗi dòng.
	//
	// Tồn mới âm thì trả ErrOutOfStock và KHÔNG dòng nào được ghi — chỉnh kho hàng
	// loạt là một lần kiểm kê, ghi được nửa chừng còn tệ hơn là báo lỗi.
	Adjust(ctx context.Context, items []InventoryAdjustment, actorID uint) ([]InventoryAdjustResult, error)

	// SetCostPrices khai giá vốn cho nhiều biến thể trong một transaction,
	// tất-cả-hoặc-không. Ghi vào product_variants.cost_price (mức ghi đè) và KHÔNG
	// ghi sổ kho: đây là sửa thuộc tính của hàng, không phải hàng ra vào kho.
	// Trả về số dòng thực sự đổi giá trị.
	SetCostPrices(ctx context.Context, items []VariantCost) (int64, error)
}

// NotificationFilter là tham số lọc/phân trang khi liệt kê thông báo.
//
// UserID = nil nghĩa là KÊNH QUẢN TRỊ (dòng có user_id IS NULL). Khách hàng luôn
// được lọc theo đúng id của mình nên không bao giờ thấy dòng của kênh quản trị.
type NotificationFilter struct {
	UserID     *uint
	OnlyUnread bool
	Page       int
	PageSize   int
}

// NotificationRepository — truy cập bảng notifications.
//
// Quy ước kênh: user_id IS NULL = thông báo cho nhân viên quản trị (đơn mới,
// khách huỷ đơn…); user_id = <id> = thông báo cho đúng khách hàng đó.
type NotificationRepository interface {
	Create(ctx context.Context, n *Notification) error
	List(ctx context.Context, f NotificationFilter) ([]Notification, int64, error)
	// UnreadCount đếm thông báo chưa đọc của một kênh (nil = kênh quản trị).
	UnreadCount(ctx context.Context, userID *uint) (int64, error)
	// MarkRead đánh dấu MỘT thông báo đã đọc, chỉ khi nó thuộc đúng kênh đang gọi.
	// Không thuộc kênh thì trả ErrNotFound (không tiết lộ thông báo đó có tồn tại).
	MarkRead(ctx context.Context, id uint, userID *uint) error
	// MarkAllRead đánh dấu toàn bộ thông báo chưa đọc của một kênh, trả số dòng đã đổi.
	MarkAllRead(ctx context.Context, userID *uint) (int64, error)
}

// RoleRepository — truy cập bảng roles.
type RoleRepository interface {
	FindByName(ctx context.Context, name string) (*Role, error)
	FindByID(ctx context.Context, id uint) (*Role, error)
	// List trả về mọi vai trò, sắp theo id (super_admin → customer), ĐÃ đè nhãn
	// riêng của cửa hàng trong ctx.
	List(ctx context.Context) ([]Role, error)
	// Labels trả về nhãn vai trò của cửa hàng trong ctx, khoá là role id. Vai trò
	// chưa đặt tên riêng thì không có mặt trong map.
	//
	// Dùng khi phải gắn tên hiển thị cho NHIỀU tài khoản một lượt: đọc một lần
	// rồi tra trong bộ nhớ, thay vì mỗi dòng một câu truy vấn.
	Labels(ctx context.Context) (map[uint]RoleLabel, error)
	// SetLabel đặt tên hiển thị/mô tả của một vai trò CHO RIÊNG cửa hàng trong
	// ctx. Bảng `roles` không bị ghi — nó dùng chung cho mọi khách hàng, xem
	// RoleLabel.
	//
	// `name` (mã vai trò) không có mặt ở đây vì không sửa được: code kiểm tra nó
	// khắp nơi (RequireRoles, gate của trang quản trị), đổi là khoá mọi người ra
	// khỏi hệ thống.
	SetLabel(ctx context.Context, roleID uint, displayName, description string) error
	// CountUsers đếm số tài khoản còn sống của từng vai trò, khoá là role id.
	CountUsers(ctx context.Context) (map[uint]int64, error)
}

// ContactFilter là tham số lọc/phân trang khi liệt kê yêu cầu của khách.
type ContactFilter struct {
	Keyword  string
	Type     string // all | lien-he | thu-mua
	Status   string // all | moi | dang-xu-ly | da-xong
	From     *time.Time
	To       *time.Time
	Page     int
	PageSize int
}

// ContactRepository — truy cập bảng contact_requests.
type ContactRepository interface {
	// List trả về một trang dữ liệu kèm TỔNG số dòng khớp bộ lọc (để dựng thanh
	// phân trang) — đếm lại ở tầng trên là thêm một vòng truy vấn thừa.
	List(ctx context.Context, f ContactFilter) ([]ContactRequest, int64, error)
	FindByID(ctx context.Context, id uint) (*ContactRequest, error)
	Create(ctx context.Context, r *ContactRequest) error
	Update(ctx context.Context, r *ContactRequest) error
	Delete(ctx context.Context, id uint) error
	// CountByStatus đếm theo trạng thái để trang quản trị hiện số "chưa xử lý"
	// ngay trên sidebar mà không phải tải cả danh sách về.
	CountByStatus(ctx context.Context) (map[string]int64, error)
}

// NewsletterFilter là tham số lọc/phân trang danh sách email nhận tin.
type NewsletterFilter struct {
	Keyword  string
	Status   string // all | active | inactive
	Page     int
	PageSize int
}

// NewsletterRepository — truy cập bảng newsletter_subscribers.
type NewsletterRepository interface {
	List(ctx context.Context, f NewsletterFilter) ([]NewsletterSubscriber, int64, error)
	FindByID(ctx context.Context, id uint) (*NewsletterSubscriber, error)
	FindByEmail(ctx context.Context, email string) (*NewsletterSubscriber, error)
	Create(ctx context.Context, s *NewsletterSubscriber) error
	Update(ctx context.Context, s *NewsletterSubscriber) error
	Delete(ctx context.Context, id uint) error
}

// EmailVerificationRepository — truy cập bảng email_verifications.
type EmailVerificationRepository interface {
	Create(ctx context.Context, v *EmailVerification) error
	Update(ctx context.Context, v *EmailVerification) error
	// FindLatestActive lấy mã mới nhất chưa dùng của một email (kể cả đã hết hạn,
	// để phân biệt "sai mã" với "mã hết hạn" khi báo lỗi).
	FindLatestActive(ctx context.Context, email, purpose string) (*EmailVerification, error)
	// InvalidateByUser đánh dấu mọi mã cũ của user là đã dùng, tránh nhiều mã cùng sống.
	InvalidateByUser(ctx context.Context, userID uint, purpose string) error
}

// TenantRepository — truy cập bảng tenants.
//
// Mới có đúng một hàm tra theo mã: giai đoạn này tenant chỉ được ĐỌC lúc đăng
// nhập. Tạo/sửa/khoá cửa hàng thuộc khu điều hành nền tảng (SaaS Admin), làm khi
// dựng control plane.
type TenantRepository interface {
	// FindByCode tra cửa hàng theo mã người dùng gõ. Trả ErrNotFound nếu không có.
	// KHÔNG lọc theo status: trạng thái do tầng nghiệp vụ xét, và chỉ xét sau khi
	// đã đúng mật khẩu — xem authService.LoginShop.
	FindByCode(ctx context.Context, code string) (*Tenant, error)
	// TrangThaiTheoID đọc `status` của một cửa hàng theo id.
	//
	// Có mặt cho đường LÀM MỚI TOKEN: khoá một cửa hàng
	// (`status = 'suspended'`) chỉ chặn được lượt đăng nhập mới, còn phiên đang
	// mở thì cứ tự gia hạn — trừ khi chỗ gia hạn tự tra lại. Xem
	// authService.Refresh.
	TrangThaiTheoID(ctx context.Context, id uint) (string, error)
}

// TinhTrangPhien là câu trả lời cho "phiên này còn dùng được không".
//
// Ba trạng thái tách bạch chứ không gộp thành một bool: chúng dẫn tới ba câu
// trả lời khác nhau cho người dùng, và gộp lại thì người bị khoá cửa hàng nhận
// đúng câu của người bị xoá tài khoản.
type TinhTrangPhien struct {
	// CoNguoiDung = false: tài khoản đã bị xoá (kể cả xoá mềm).
	CoNguoiDung bool
	// NguoiDungHoatDong = false: tài khoản còn đó nhưng đã bị khoá.
	NguoiDungHoatDong bool
	// CuaHangHoatDong = false: cửa hàng bị khoá hoặc đã bị xoá khỏi sổ.
	CuaHangHoatDong bool
}

// ConDungDuoc gộp ba vế lại cho nơi gọi chỉ cần một câu trả lời.
func (t TinhTrangPhien) ConDungDuoc() bool {
	return t.CoNguoiDung && t.NguoiDungHoatDong && t.CuaHangHoatDong
}

// PhienRepository trả lời câu hỏi DUY NHẤT của middleware xác thực: chiếc token
// này còn ứng với một tài khoản sống trong một cửa hàng đang mở không.
//
// VÌ SAO CÓ PORT RIÊNG thay vì gọi UserRepository + TenantRepository: câu hỏi
// này chạy ở MỌI request đã đăng nhập, nên nó phải là ĐÚNG MỘT lượt đọc. Ghép
// hai repository lại thành hai lượt là nhân đôi chi phí của đường đi nóng nhất
// hệ thống, và không ai nhìn ra điều đó khi đọc riêng từng chỗ gọi.
//
// CHẠY TRÊN DATA PLANE. Kết nối có bộ lọc tenant, nhưng hàm dưới đây nhận
// tenantID tường minh và tự khai điều kiện — nó được gọi TRƯỚC khi ctx kịp mang
// tenant, vì chính nó là bước quyết định có gắn tenant vào ctx hay không.
type PhienRepository interface {
	KiemPhien(ctx context.Context, userID, tenantID uint) (TinhTrangPhien, error)
}

// UserRepository — truy cập bảng users.
type UserRepository interface {
	Create(ctx context.Context, u *User) error
	Update(ctx context.Context, u *User) error
	Delete(ctx context.Context, id uint) error
	FindByID(ctx context.Context, id uint) (*User, error)
	FindByEmail(ctx context.Context, email string) (*User, error)
	// FindByTenantUsername tra tài khoản theo ô 2 của màn hình đăng nhập 3 ô.
	// Cặp (tenant_id, username) chính là UNIQUE key uq_users_username, nên tra
	// thiếu tenant_id là tra nhầm sang tài khoản 'admin' của khách hàng khác.
	FindByTenantUsername(ctx context.Context, tenantID uint, username string) (*User, error)
	// FindByFacebookID tìm tài khoản đã liên kết Facebook. Trả ErrNotFound nếu chưa
	// có ai liên kết id này.
	FindByFacebookID(ctx context.Context, fbID string) (*User, error)
	// FindByGoogleID tìm tài khoản đã liên kết Google. Trả ErrNotFound nếu chưa có
	// ai liên kết id này.
	FindByGoogleID(ctx context.Context, ggID string) (*User, error)
	// ExistsByEmail tính cả tài khoản đã xoá mềm — email vẫn bị UNIQUE index giữ chỗ.
	ExistsByEmail(ctx context.Context, email string) (bool, error)
	// ExistsByEmailExcept như trên nhưng bỏ qua chính tài khoản đang sửa.
	ExistsByEmailExcept(ctx context.Context, email string, excludeID uint) (bool, error)
	// ExistsByUsernameExcept — cặp với ExistsByEmailExcept, cho ô tên đăng nhập.
	// Cũng tính cả tài khoản đã xoá mềm vì uq_users_username không loại chúng ra.
	ExistsByUsernameExcept(ctx context.Context, username string, excludeID uint) (bool, error)
	ListCustomers(ctx context.Context, filter CustomerFilter) ([]User, int64, error)

	// CustomerStats đếm khách hàng theo trạng thái (không phụ thuộc bộ lọc).
	CustomerStats(ctx context.Context) (CustomerStats, error)
	// AggregateCustomerOrders tổng hợp số đơn/chi tiêu của từng khách hàng.
	AggregateCustomerOrders(ctx context.Context, userIDs []uint) (map[uint]CustomerAggregate, error)
	// DefaultAddresses lấy địa chỉ mặc định (dạng chuỗi hiển thị) của từng khách hàng.
	DefaultAddresses(ctx context.Context, userIDs []uint) (map[uint]string, error)
	// SaveDefaultAddress ghi đè địa chỉ mặc định của khách hàng (xoá địa chỉ nếu chuỗi rỗng).
	SaveDefaultAddress(ctx context.Context, u *User, address string) error

	// ----- Tài khoản nội bộ (nhân viên & quản trị) -----

	// ListInternal liệt kê tài khoản của MỌI vai trò trừ customer.
	ListInternal(ctx context.Context, filter InternalUserFilter) ([]User, int64, error)
	// InternalStats đếm tài khoản nội bộ theo trạng thái (không phụ thuộc bộ lọc).
	InternalStats(ctx context.Context) (InternalUserStats, error)
	// CountActiveByRole đếm tài khoản đang hoạt động của một vai trò, bỏ qua
	// excludeID (0 = không bỏ ai).
	//
	// Dùng để chặn thao tác làm biến mất người super admin cuối cùng: khoá, xoá
	// hay hạ vai trò người đó là tự nhốt mình ngoài hệ thống, không ai mở lại được.
	CountActiveByRole(ctx context.Context, roleID, excludeID uint) (int64, error)
}

// ChiNhanhRepository — truy cập bảng `shops`, các ĐIỂM BÁN của một tenant.
//
// CHẠY TRÊN DATA PLANE: mọi hàm dưới đây đi qua bộ lọc tenant, nên chúng chỉ
// nhìn thấy chi nhánh của cửa hàng trong ctx. Đừng thêm hàm nào nhận tenantID
// làm tham số — đó là dấu hiệu của một đường đọc xuyên khách hàng, và đường đó
// thuộc về khu điều hành chứ không phải ở đây.
type ChiNhanhRepository interface {
	// List trả về chi nhánh của cửa hàng, sắp theo mã. onlyActive = true thì bỏ
	// chi nhánh đã ngừng hoạt động.
	List(ctx context.Context, onlyActive bool) ([]ChiNhanh, error)
	FindByID(ctx context.Context, id uint) (*ChiNhanh, error)
	// ExistsByCode tính CẢ chi nhánh đã xoá mềm: mã của chúng vẫn giữ chỗ trong
	// uq_shops_tenant_code, nên báo trùng ở đây dễ hiểu hơn là để MySQL ném lỗi
	// khoá lúc ghi.
	ExistsByCode(ctx context.Context, code string, excludeID uint) (bool, error)
	Create(ctx context.Context, cn *ChiNhanh) error
	Update(ctx context.Context, cn *ChiNhanh) error
	// Delete xoá mềm. Nơi gọi phải tự bảo đảm không xoá chi nhánh cuối cùng —
	// xem ChiNhanhService.
	Delete(ctx context.Context, id uint) error
	// Count đếm chi nhánh còn trong sổ (kể cả đang ngừng hoạt động) — con số của
	// hạn mức `max_shops`.
	Count(ctx context.Context) (int64, error)
	// BanOnline trả về chi nhánh PHỤ TRÁCH BÁN ONLINE của cửa hàng: nơi đơn từ
	// trang bán hàng trừ kho, và nơi mọi lượt ghi kho không nói rõ chi nhánh rơi
	// về.
	//
	// Hôm nay là chi nhánh CÒN SỐNG có id nhỏ nhất — dòng 'mac-dinh' dựng cùng
	// lúc mở tài khoản. Đây là một luật ngầm chứ chưa phải một ô cấu hình, và cố
	// ý dừng ở đó: cửa hàng một chi nhánh (gần như mọi khách hôm nay) không có gì
	// để chọn, còn khi có khách chuỗi thật muốn đổi nơi bán online thì đó là một
	// ô trong trang Chi nhánh, không phải một luật khác trong code.
	//
	// ErrNotFound = cửa hàng không còn chi nhánh nào đang mở. Không được rơi về
	// một id đoán bừa: ghi hàng vào một chi nhánh không tồn tại thì khoá ngoại từ
	// chối, còn ghi vào chi nhánh của khách khác thì không ai từ chối cả.
	BanOnline(ctx context.Context) (*ChiNhanh, error)
	// CountActiveExcept đếm chi nhánh ĐANG HOẠT ĐỘNG, bỏ qua excludeID (0 =
	// không bỏ ai).
	//
	// Có mặt để chặn thao tác làm biến mất chi nhánh hoạt động cuối cùng: một cửa
	// hàng không còn điểm bán nào là thứ mọi bảng giao dịch mang `shop_id` sẽ
	// không có chỗ để trỏ tới.
	CountActiveExcept(ctx context.Context, excludeID uint) (int64, error)
}

// CategoryRepository — truy cập bảng categories.
type CategoryRepository interface {
	List(ctx context.Context, onlyActive bool) ([]Category, error)
	FindByID(ctx context.Context, id uint) (*Category, error)
	FindBySlug(ctx context.Context, slug string) (*Category, error)
	ExistsBySlug(ctx context.Context, slug string, excludeID uint) (bool, error)
	Create(ctx context.Context, c *Category) error
	Update(ctx context.Context, c *Category) error
	Delete(ctx context.Context, id uint) error
}

// ProductRepository — truy cập bảng products.
type ProductRepository interface {
	List(ctx context.Context, f ProductFilter) ([]Product, int64, error)
	FindByID(ctx context.Context, id uint) (*Product, error)
	FindBySlug(ctx context.Context, slug string) (*Product, error)
	ExistsBySlug(ctx context.Context, slug string, excludeID uint) (bool, error)
	// ExistsBySKU — cặp với ExistsBySlug. Bảng products có UNIQUE trên sku, không
	// kiểm tra ở đây thì lỗi rơi xuống tận MySQL và trả về một câu vô nghĩa.
	ExistsBySKU(ctx context.Context, sku string, excludeID uint) (bool, error)
	// Count đếm MỌI sản phẩm còn trong sổ của cửa hàng — kể cả đang ẩn, ngừng
	// bán, chưa gắn danh mục. Cố ý KHÔNG nhận ProductFilter: nó phục vụ hạn mức
	// hợp đồng (xem LoaiHanMuc), mà hạn mức đếm chỗ đang chiếm chứ không đếm hàng
	// đang bày. Nhận bộ lọc ở đây là mở đường cho một nơi gọi lỡ tay đếm mỗi hàng
	// đang bán, và khi đó tắt hiển thị một sản phẩm trở thành cách lách hạn mức
	// bằng một cú bấm.
	Count(ctx context.Context) (int64, error)
	Create(ctx context.Context, p *Product) error
	Update(ctx context.Context, p *Product) error
	// SetStatus cập nhật trạng thái kinh doanh + cờ hiển thị, không đụng biến thể/ảnh.
	SetStatus(ctx context.Context, id uint, status string) error
	Delete(ctx context.Context, id uint) error
	// DeleteMany xoá nhiều sản phẩm trong MỘT giao dịch. Trang quản trị cho chọn
	// hàng loạt rồi xoá; lặp gọi từng cái là N lượt HTTP nối đuôi nhau và hỏng
	// giữa chừng thì một nửa đã xoá mất.
	DeleteMany(ctx context.Context, ids []uint) (int64, error)
	IncrementView(ctx context.Context, id uint) error
	// ReplaceVariants đồng bộ danh sách biến thể của sản phẩm: upsert dòng trong
	// danh sách, xoá (soft-delete) các dòng cũ không còn.
	ReplaceVariants(ctx context.Context, productID uint, variants []ProductVariant) error
	// ReplaceImages đồng bộ thư viện ảnh của sản phẩm: upsert ảnh trong danh sách,
	// xoá (hard-delete) các ảnh cũ không còn.
	ReplaceImages(ctx context.Context, productID uint, images []ProductImage) error
}

// BannerFilter là điều kiện lọc danh sách banner.
type BannerFilter struct {
	// Position rỗng = mọi vị trí.
	Position string
	// OnlyLive = chỉ banner đang thực sự hiện với khách (bật + trong lịch chạy).
	// Storefront luôn bật cờ này; trang quản trị thì không, vì người bán cần thấy
	// cả banner đang tắt và banner hẹn lịch cho đợt sau.
	OnlyLive bool
}

// BannerRepository — truy cập bảng banners.
type BannerRepository interface {
	List(ctx context.Context, f BannerFilter) ([]Banner, error)
	FindByID(ctx context.Context, id uint) (*Banner, error)
	Create(ctx context.Context, b *Banner) error
	Update(ctx context.Context, b *Banner) error
	// SetActive chỉ cập nhật cột is_active — công tắc hiển thị ngoài danh sách
	// không phải gửi lại toàn bộ nội dung banner.
	SetActive(ctx context.Context, id uint, active bool) error
	// SetSortOrders ghi lại thứ tự cho nhiều banner trong MỘT giao dịch: kéo thả
	// đổi chỗ hai banner mà ghi từng dòng thì lỗi giữa chừng để lại thứ tự lẫn lộn.
	SetSortOrders(ctx context.Context, orders map[uint]int) error
	Delete(ctx context.Context, id uint) error
	// NextSortOrder trả về số thứ tự kế tiếp của một vị trí — banner mới luôn
	// xuống cuối thay vì chen ngang vào giữa dải đang chạy.
	NextSortOrder(ctx context.Context, position string) (int, error)
}

// PromotionFilter là tham số lọc/phân trang khi liệt kê chương trình khuyến mãi.
type PromotionFilter struct {
	Keyword string // tên chương trình
	// Status: all | running (đang chạy) | scheduled (chưa tới ngày) | ended (đã hết)
	// | paused (bị tắt tay). Bốn nhóm này là câu hỏi thật của người bán, không phải
	// chỉ đọc cột is_active.
	Status   string
	FromDate string // YYYY-MM-DD — lọc theo khoảng thời gian chạy
	ToDate   string
	Sort     string // newest | oldest | start_asc | start_desc | name_asc
	Page     int
	PageSize int
}

// PromotionStats — số đếm cho dải thẻ tóm tắt trên đầu trang quản trị.
type PromotionStats struct {
	Total     int64 `json:"total"`
	Running   int64 `json:"running"`
	Scheduled int64 `json:"scheduled"`
	Ended     int64 `json:"ended"`
	Paused    int64 `json:"paused"`
}

// PromotionRepository — truy cập bảng promotions + promotion_targets.
type PromotionRepository interface {
	List(ctx context.Context, f PromotionFilter) ([]Promotion, int64, error)
	Stats(ctx context.Context) (PromotionStats, error)
	FindByID(ctx context.Context, id uint) (*Promotion, error)
	// Create / Update ghi luôn cả phạm vi áp dụng trong MỘT giao dịch: chương trình
	// mà không có phạm vi thì không giảm cho ai, để lỡ nửa chừng là sai nghiệp vụ.
	Create(ctx context.Context, p *Promotion) error
	Update(ctx context.Context, p *Promotion) error
	SetActive(ctx context.Context, id uint, active bool) error
	Delete(ctx context.Context, id uint) error
	// Running trả về các chương trình đang chạy tại thời điểm at, kèm phạm vi.
	// Đây là truy vấn nằm trên đường đi của MỌI lần khách xem hàng nên phải gọn.
	Running(ctx context.Context, at time.Time) ([]Promotion, error)
	// CountProducts đếm số sản phẩm ĐANG BÁN nằm trong phạm vi đã cho — người bán
	// cần biết đợt này chạm vào bao nhiêu mặt hàng trước khi bật. Danh mục con đã
	// được tầng service khai triển sẵn vào categoryIDs.
	CountProducts(ctx context.Context, productIDs, categoryIDs, brandIDs []uint) (int64, error)
}

// VoucherFilter là tham số lọc/phân trang khi liệt kê voucher.
type VoucherFilter struct {
	Keyword string // mã hoặc mô tả
	// Status: all | running (dùng được ngay) | scheduled (chưa tới ngày) | ended
	// (hết hạn) | used_up (hết lượt) | paused (tắt tay).
	//
	// "Hết lượt" là nhóm riêng chứ không gộp vào "hết hạn": voucher còn hạn nhưng
	// đã phát hết lượt vẫn nằm đó, và cách sửa là nâng tổng lượt — khác hẳn với
	// dời ngày.
	Status   string
	Type     string // percentage | fixed | rỗng = tất cả
	FromDate string // YYYY-MM-DD — lọc theo khoảng thời gian hiệu lực
	ToDate   string
	Sort     string // newest | oldest | code_asc | used_desc | end_asc
	Page     int
	PageSize int
}

// VoucherStats — số đếm cho dải thẻ tóm tắt trên đầu trang quản trị.
type VoucherStats struct {
	Total     int64 `json:"total"`
	Running   int64 `json:"running"`
	Scheduled int64 `json:"scheduled"`
	Ended     int64 `json:"ended"`
	UsedUp    int64 `json:"used_up"`
	Paused    int64 `json:"paused"`
}

// VoucherRepository — truy cập bảng vouchers.
type VoucherRepository interface {
	List(ctx context.Context, f VoucherFilter) ([]Voucher, int64, error)
	Stats(ctx context.Context) (VoucherStats, error)
	FindByID(ctx context.Context, id uint) (*Voucher, error)
	// CodeTaken cho biết mã đã thuộc về voucher nào khác chưa. exceptID là voucher
	// đang sửa (0 khi tạo mới) — không có nó thì sửa voucher mà giữ nguyên mã sẽ
	// đụng chính nó và báo trùng.
	CodeTaken(ctx context.Context, code string, exceptID uint) (bool, error)
	Create(ctx context.Context, v *Voucher) error
	// Update KHÔNG đụng tới used_count: số lượt đã phát là dữ liệu do đơn hàng ghi,
	// không phải thứ người quản trị gõ lại trong biểu mẫu.
	Update(ctx context.Context, v *Voucher) error
	SetActive(ctx context.Context, id uint, active bool) error
	Delete(ctx context.Context, id uint) error

	// FindByCode tra mã khách vừa nhập (đã chuẩn hoá chữ hoa). Chỉ đọc, không khoá
	// — việc chốt lượt nằm trong giao dịch đặt hàng.
	FindByCode(ctx context.Context, code string) (*Voucher, error)
	// CountUsageByUser đếm số lượt MỘT KHÁCH đã tiêu của mã, để so với
	// usage_limit_per_user.
	//
	// Khách được nhận diện bằng userID (khi đã đăng nhập) HOẶC số điện thoại người
	// nhận (khi vãng lai) — chỉ dựa vào userID thì ai không đăng nhập là dùng mã
	// lại vô hạn, và ô "lượt mỗi khách" thành vô nghĩa. phone chỉ gồm chữ số.
	// Cả hai đều rỗng thì trả 0: không có gì để đếm.
	CountUsageByUser(ctx context.Context, voucherID, userID uint, phone string) (int64, error)
	// ListPublic trả các mã ĐẠI TRÀ còn dùng được tại thời điểm at: đang bật, trong
	// hạn, còn lượt. Đây là thứ hiện thẳng cho khách ở ô nhập mã nên chỉ lấy mã đã
	// bật cờ công khai — mã gửi tay tuyệt đối không lọt vào đây.
	ListPublic(ctx context.Context, at time.Time, limit int) ([]Voucher, error)
	// CountUsageByUserBulk đếm số lượt khách đã tiêu của NHIỀU mã trong một truy
	// vấn, để lọc mã họ đã dùng hết khỏi danh sách gợi ý mà không phải hỏi từng mã.
	// Nhận diện khách giống CountUsageByUser: userID hoặc số điện thoại.
	CountUsageByUserBulk(ctx context.Context, voucherIDs []uint, userID uint, phone string) (map[uint]int64, error)
}

// BrandRepository — truy cập bảng brands.
type BrandRepository interface {
	List(ctx context.Context, onlyActive bool) ([]Brand, error)
	FindByID(ctx context.Context, id uint) (*Brand, error)
	ExistsBySlug(ctx context.Context, slug string, excludeID uint) (bool, error)
	Create(ctx context.Context, b *Brand) error
	Update(ctx context.Context, b *Brand) error
	Delete(ctx context.Context, id uint) error
}

// SettingRepository — truy cập bảng settings (cấu hình hệ thống dạng key-value).
//
// Tầng này KHÔNG biết khoá nào là hợp lệ: registry khoá nằm ở SettingService.
// Nhiệm vụ ở đây chỉ là đọc/ghi thô.
type SettingRepository interface {
	// Map đọc toàn bộ cấu hình thành map key -> value.
	//
	// Cố ý KHÔNG có hàm lọc theo nhóm: nhóm do registry bên service quyết định,
	// cột `group` dưới database chỉ là bản sao cho dễ đọc.
	Map(ctx context.Context) (map[string]string, error)
	// Upsert ghi nhiều khoá một lần: khoá có rồi thì cập nhật `value`, chưa có thì
	// thêm mới. Nhận []Setting chứ không phải map[string]string vì dòng thêm mới
	// cần cả `group` — nhóm do registry của service quyết định.
	Upsert(ctx context.Context, items []Setting) error
}
