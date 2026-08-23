package domain

import (
	"context"
	"errors"
	"time"

	"gorm.io/gorm"
)

// PHIẾU MUA HÀNG — Quản lý kho → Phiếu mua hàng.
//
// Một chứng từ duy nhất cho cả chiều mua vào, dựng theo màn cùng tên của bản
// order v2. Không tách phiếu đặt với phiếu nhập như ba màn cũ: cửa hàng nhỏ đặt
// hàng và nhận hàng trong cùng một buổi, hai chứng từ cho một việc chỉ khiến
// người nhập phải gõ hai lần.
//
//	lưu tạm ──duyệt──> đã duyệt (hàng vào kho đúng lúc này)
//	   └─────huỷ─────> đã huỷ
//
// Duyệt là lúc DUY NHẤT tồn kho đổi, nên phiếu đã duyệt khoá lại: không sửa,
// không huỷ, không xoá. Bản v2 cho sửa tiếp và mỗi lượt lưu lại cộng kho thêm
// một lần mà không trừ số cũ — xem chú thích ở migration 0041.
type PurchaseOrder struct {
	ID uint `json:"id" gorm:"primaryKey"`
	TenantOwned
	// ShopID là chi nhánh lập phiếu, và cũng là kho hàng sẽ về khi duyệt. Chốt
	// lúc lập chứ không lúc duyệt: người bấm duyệt có thể đang đứng ở chi nhánh
	// khác, mà hàng thì về đúng nơi đã đặt mua.
	ShopID uint   `json:"shop_id"`
	POCode string `json:"po_code" gorm:"column:po_code"`

	// SupplierID trỏ về danh mục để gom số liệu và lọc; nil = bên bán vãng lai.
	SupplierID *uint `json:"supplier_id"`
	// SupplierName là bản chụp TÊN bên bán lúc lập phiếu, và là thứ in ra phiếu.
	// Đổi tên nhà cung cấp hôm nay không được sửa lại chứng từ ký tháng trước.
	SupplierName string `json:"supplier_name"`

	Status string `json:"status"`

	DocumentDate *time.Time `json:"document_date" gorm:"type:date"`
	ExpectedDate *time.Time `json:"expected_date" gorm:"type:date"`

	PurchaserID          *uint  `json:"purchaser_id"`
	SupplierDeliveryCode string `json:"supplier_delivery_code"`

	// VATMode quyết định thuế khai ở đâu: cả phiếu một mức, hay mỗi dòng một mức.
	VATMode    string `json:"vat_mode" gorm:"column:vat_mode"`
	VATPercent int    `json:"vat_percent" gorm:"column:vat_percent"`

	ItemsAmount    float64 `json:"items_amount"`
	DiscountAmount float64 `json:"discount_amount"`
	VATAmount      float64 `json:"vat_amount" gorm:"column:vat_amount"`
	TotalAmount    float64 `json:"total_amount"`

	PaidAmount    float64 `json:"paid_amount"`
	PaymentStatus string  `json:"payment_status"`

	Note         string `json:"note"`
	Attachment   string `json:"attachment"`
	CancelReason string `json:"cancel_reason"`

	CreatedBy *uint `json:"created_by"`
	HandledBy *uint `json:"handled_by"`
	// CreatedByName là TÊN người lập, tra kèm khi đọc lên — bảng không có cột
	// này. Chỉ có id thì mọi màn hình muốn in tên đều phải tự đi tra một lượt
	// nữa, và mỗi nơi lại tra một kiểu.
	CreatedByName string `json:"created_by_name" gorm:"-"`

	ApprovedAt  *time.Time `json:"approved_at"`
	CancelledAt *time.Time `json:"cancelled_at"`

	Items []PurchaseOrderItem `json:"items,omitempty" gorm:"foreignKey:PurchaseOrderID"`

	CreatedAt time.Time      `json:"created_at"`
	UpdatedAt time.Time      `json:"updated_at"`
	DeletedAt gorm.DeletedAt `json:"-" gorm:"index"`
}

// Trạng thái phiếu mua hàng.
const (
	// PurchaseStatusDraft — lưu tạm: sửa/xoá thoải mái, CHƯA đụng tới kho.
	PurchaseStatusDraft = "draft"
	// PurchaseStatusApproved — đã duyệt: hàng đã vào kho, phiếu khoá lại.
	PurchaseStatusApproved = "approved"
	// PurchaseStatusCancelled — đã huỷ (chỉ từ lưu tạm) — điểm cuối.
	PurchaseStatusCancelled = "cancelled"
)

// Cách khai thuế của phiếu — bản v2 gọi là allow_vat_purchase.
const (
	// VATModeOrder — một mức thuế cho cả phiếu.
	VATModeOrder = "order"
	// VATModeGoods — mỗi dòng hàng một mức, lấy mặc định theo thuế của mặt hàng.
	VATModeGoods = "goods"
)

// Tình trạng thanh toán cho nhà cung cấp.
const (
	PurchasePaymentUnpaid  = "unpaid"
	PurchasePaymentPartial = "partial"
	PurchasePaymentPaid    = "paid"
)

// PurchaseOrderItem — một dòng hàng trên phiếu.
//
// ĐƠN VỊ MUA khác đơn vị tồn kho, và đó là chỗ khó thật của phiếu mua: mua một
// THÙNG nhưng kho đếm theo CÁI. Ba cột UnitRatio / Quantity / BaseQuantity nói
// trọn việc đó — xem chú thích ở migration 0041.
type PurchaseOrderItem struct {
	ID uint `json:"id" gorm:"primaryKey"`
	TenantOwned
	PurchaseOrderID  uint  `json:"purchase_order_id"`
	ProductID        *uint `json:"product_id"`
	ProductVariantID *uint `json:"product_variant_id"`

	ProductName string `json:"product_name"`
	VariantSKU  string `json:"variant_sku" gorm:"column:variant_sku"`
	// VariantName chụp lại TÊN biến thể lúc lập chứng từ ("128GB · Đen").
	// Rỗng = hàng không có biến thể.
	VariantName string `json:"variant_name"`
	Thumbnail   string `json:"thumbnail"`

	// UnitID là đơn vị MUA; nil = mua theo đúng đơn vị tính chính của mặt hàng.
	UnitID   *uint  `json:"unit_id"`
	UnitName string `json:"unit_name"`
	// UnitRatio: 1 đơn vị mua bằng bao nhiêu đơn vị tính chính.
	UnitRatio float64 `json:"unit_ratio"`

	// LotNumber / ExpireDate là bản CHỤP trên chứng từ, KHÔNG phải một chiều
	// của tồn kho: kho vẫn đếm theo (chi nhánh × biến thể), hai lô của cùng
	// một mặt hàng cộng vào một dòng tồn. Xem migration 0042.
	LotNumber  string     `json:"lot_number"`
	ExpireDate *time.Time `json:"expire_date" gorm:"type:date"`

	// Quantity là số đơn vị MUA, đúng như trên hoá đơn bên bán.
	Quantity int `json:"quantity"`
	// BaseQuantity = Quantity × UnitRatio, và ĐÂY là số cộng vào kho.
	BaseQuantity int `json:"base_quantity"`

	// UnitCost là giá một đơn vị MUA (giá một thùng, không phải giá một cái).
	UnitCost   float64 `json:"unit_cost"`
	VATPercent int     `json:"vat_percent" gorm:"column:vat_percent"`
	LineAmount float64 `json:"line_amount"`
	VATAmount  float64 `json:"vat_amount" gorm:"column:vat_amount"`
	TotalCost  float64 `json:"total_cost"`

	CreatedAt time.Time `json:"created_at"`
	UpdatedAt time.Time `json:"updated_at"`
}

// TableName: bảng trong schema là số ít (purchase_order_history), cùng quy ước
// với order_status_history — phải khai báo tường minh vì GORM mặc định số nhiều.
func (PurchaseOrderHistory) TableName() string { return "purchase_order_history" }

type PurchaseOrderHistory struct {
	ID uint `json:"id" gorm:"primaryKey"`
	TenantOwned
	PurchaseOrderID uint      `json:"purchase_order_id"`
	FromStatus      string    `json:"from_status"`
	ToStatus        string    `json:"to_status"`
	Note            string    `json:"note"`
	ChangedBy       *uint     `json:"changed_by"`
	CreatedAt       time.Time `json:"created_at"`
}

// PurchaseFilter là tham số lọc/sắp xếp/phân trang khi liệt kê phiếu mua hàng.
type PurchaseFilter struct {
	Keyword       string // mã phiếu / tên bên bán / ghi chú
	Status        string // rỗng hoặc all = mọi trạng thái; cho phép nhiều giá trị ngăn bởi dấu phẩy
	PaymentStatus string // all | unpaid | partial | paid
	SupplierID    uint   // 0 = mọi nhà cung cấp
	// VariantID lọc phiếu có chứa một mặt hàng cụ thể — cột "Hàng hoá" của bộ lọc.
	VariantID uint
	FromDate  string // YYYY-MM-DD (theo created_at)
	ToDate    string // YYYY-MM-DD
	Sort      string // newest | oldest | total_desc | total_asc | document_desc
	Page      int
	PageSize  int
}

// PurchaseStats — con số một dòng trên đầu trang.
type PurchaseStats struct {
	Total     int64 `json:"total"`
	Draft     int64 `json:"draft"`
	Approved  int64 `json:"approved"`
	Cancelled int64 `json:"cancelled"`
	// PurchasedAmount là tiền hàng đã mua thật (chỉ phiếu đã duyệt).
	PurchasedAmount float64 `json:"purchased_amount"`
	// DebtAmount là tiền còn nợ nhà cung cấp của các phiếu đã duyệt.
	DebtAmount float64 `json:"debt_amount"`
}

// PurchaseLine — một dòng hàng người dùng đưa vào phiếu.
//
// Tên/SKU/ảnh và hệ số quy đổi do server tra lại từ mặt hàng, không nhận từ
// client: nếu không thì phiếu ghi một đằng, kho cộng một nẻo.
type PurchaseLine struct {
	VariantID uint
	// UnitID là đơn vị MUA; 0 = mua theo đơn vị tính chính.
	UnitID     uint
	Quantity   int
	UnitCost   float64
	VATPercent int
	LotNumber  string
	ExpireDate string
}

// PurchaseVariant — thông tin mặt hàng server tra được khi dựng dòng phiếu.
// Cũng là dữ liệu trả thẳng ra API cho ô chọn hàng, nên có json tag.
type PurchaseVariant struct {
	VariantID   uint   `json:"variant_id"`
	ProductID   uint   `json:"product_id"`
	ProductName string `json:"product_name"`
	SKU         string `json:"sku"`
	VariantName string `json:"variant_name"`
	Thumbnail   string `json:"thumbnail"`
	// CostPrice là giá vốn đang khai (nil = chưa khai) — gợi ý giá nhập cho
	// người lập phiếu.
	CostPrice *float64 `json:"cost_price"`
	Stock     int      `json:"stock"`
	// VATPercent là thuế suất của mặt hàng (âm = KCT/KKKNT, xem products.vat).
	VATPercent int `json:"vat_percent"`
	// BaseUnitID / BaseUnitName là đơn vị tính CHÍNH — đơn vị kho đang đếm.
	BaseUnitID   uint   `json:"base_unit_id"`
	BaseUnitName string `json:"base_unit_name"`
	// Units là các đơn vị mua được, kể cả đơn vị chính (hệ số 1). Màn lập phiếu
	// đổ thẳng danh sách này vào ô chọn đơn vị của dòng hàng.
	//
	// gorm:"-" vì đây KHÔNG phải quan hệ: danh sách dựng từ cột JSON
	// products.unit_conversions ở napDonVi. Bỏ thẻ này thì GORM coi nó là một
	// quan hệ và câu Scan chết với "define a valid foreign key for relations".
	Units []PurchaseUnit `json:"units" gorm:"-"`
}

// PurchaseNhomHang — một nhóm hàng CÓ hàng mua được, cho ô lọc nhóm đứng cạnh
// ô tìm hàng trong hộp lập phiếu.
type PurchaseNhomHang struct {
	ID   uint   `json:"id"`
	Name string `json:"name"`
	// SoMatHang là số mặt hàng đang bán trong nhóm — màn hình in kèm để người
	// lập phiếu biết chọn vào đó sẽ ra bao nhiêu dòng.
	SoMatHang int `json:"so_mat_hang"`
}

// PurchaseUnit — một đơn vị mua được của mặt hàng.
type PurchaseUnit struct {
	UnitID uint   `json:"unit_id"`
	Name   string `json:"name"`
	// Ratio: 1 đơn vị này bằng bao nhiêu đơn vị tính chính.
	Ratio float64 `json:"ratio"`
}

// PurchaseOrderRepository — truy cập bảng purchase_orders và các bảng đi kèm.
type PurchaseOrderRepository interface {
	List(ctx context.Context, f PurchaseFilter) ([]PurchaseOrder, int64, error)
	FindByID(ctx context.Context, id uint) (*PurchaseOrder, error)
	Stats(ctx context.Context) (PurchaseStats, error)
	// Histories trả lịch sử thao tác của phiếu, cũ -> mới.
	Histories(ctx context.Context, purchaseID uint) ([]PurchaseOrderHistory, error)

	// SearchVariants tìm mặt hàng để đưa vào phiếu (ô chọn hàng). categoryID = 0
	// là mọi nhóm hàng — màn lập phiếu có ô lọc nhóm đứng cạnh ô tìm.
	SearchVariants(ctx context.Context, keyword string, categoryID uint, limit int) ([]PurchaseVariant, error)
	// NhomHangCoHang trả về CHỈ những nhóm đang có hàng mua được, theo đúng bộ
	// điều kiện của SearchVariants. Bày ra nhóm rỗng thì chọn vào là bảng trắng,
	// và người dùng không có cách nào biết đó là nhóm rỗng hay là lỗi.
	NhomHangCoHang(ctx context.Context) ([]PurchaseNhomHang, error)
	// LookupVariants tra thông tin các mặt hàng theo ID biến thể — dùng để chụp
	// snapshot khi tạo/sửa phiếu. Biến thể không tồn tại thì vắng mặt trong kết quả.
	LookupVariants(ctx context.Context, ids []uint) (map[uint]PurchaseVariant, error)

	// Create tạo phiếu trong MỘT transaction, tự sinh mã theo quy tắc và ghi mốc
	// lịch sử khởi tạo. KHÔNG đụng tới kho: phiếu mới lập luôn là phiếu lưu tạm,
	// hàng chỉ vào kho ở Approve.
	Create(ctx context.Context, po *PurchaseOrder) error
	// Update sửa thông tin + THAY TOÀN BỘ danh sách hàng dưới khoá dòng. mutate
	// nhận bản mới nhất (đã khoá, kèm dòng hàng cũ), kiểm tra điều kiện sửa rồi
	// đổi các cột vô hướng; trả về danh sách CỘT cần lưu và danh sách hàng MỚI.
	Update(ctx context.Context, id uint, mutate func(po *PurchaseOrder) ([]string, []PurchaseOrderItem, error)) (*PurchaseOrder, error)
	// Approve duyệt phiếu trong một transaction: khoá phiếu, CỘNG TỒN KHO theo
	// base_quantity của từng dòng và ghi bút toán inventory_transactions
	// (type='import', reference_type='purchase_order'), rồi chuyển sang "đã duyệt".
	//
	// Duyệt hai lần trả ErrPurchaseLocked và KHÔNG dòng nào được ghi.
	Approve(ctx context.Context, id uint, a PurchaseApproval) (*PurchaseOrder, error)
	// LockAndUpdate đọc-sửa-ghi phiếu dưới khoá dòng — dùng cho huỷ phiếu và ghi
	// nhận thanh toán, hai đường không đụng tới kho.
	LockAndUpdate(ctx context.Context, id uint, apply func(po *PurchaseOrder) (*PurchaseOrderHistory, []string, error)) (*PurchaseOrder, error)
	// Delete xoá mềm phiếu — tầng service chỉ cho gọi với phiếu lưu tạm.
	Delete(ctx context.Context, id uint) error
}

// PurchaseApproval là một lượt duyệt phiếu.
type PurchaseApproval struct {
	// UpdateCost = true: ghi luôn giá nhập của phiếu vào giá vốn của biến thể.
	// Mặc định bật — giá vốn mới nhất là giá vừa mua, và trang tồn kho đang chờ
	// đúng con số đó để tính giá trị kho.
	UpdateCost bool
	Note       string
	ActorID    uint
}

var (
	// ErrPurchaseEmpty — phiếu không có dòng hàng nào.
	ErrPurchaseEmpty = errors.New("phiếu mua hàng phải có ít nhất một dòng hàng")

	// ErrPurchaseLocked — phiếu đã duyệt hoặc đã huỷ, không sửa được nữa.
	ErrPurchaseLocked = errors.New("phiếu đã duyệt hoặc đã huỷ nên không sửa được — lập phiếu mới hoặc cân đối ở màn Tồn kho")

	// ErrPurchaseUnitRatio — quy đổi ra số lẻ ("1 thùng = 0.5 tạ"): sổ kho đếm
	// nguyên nên không có chỗ ghi phần lẻ, và tự làm tròn thì kho lệch dần.
	ErrPurchaseUnitRatio = errors.New("số lượng quy đổi ra đơn vị tính chính phải là số nguyên — đổi số lượng hoặc chọn đơn vị khác")

	// ErrPurchaseUnitLa — đơn vị mua không phải đơn vị tính chính, cũng không
	// nằm trong khối quy đổi đã khai của mặt hàng.
	ErrPurchaseUnitLa = errors.New("đơn vị mua này chưa khai quy đổi cho mặt hàng")

	// ErrPurchasePaidQuaTong — trả nhiều hơn tổng tiền phiếu.
	ErrPurchasePaidQuaTong = errors.New("số tiền đã trả không được lớn hơn tổng tiền phiếu")
)
