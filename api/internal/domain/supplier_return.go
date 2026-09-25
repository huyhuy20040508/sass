package domain

import (
	"context"
	"errors"
	"time"

	"gorm.io/gorm"
)

// TRẢ HÀNG NHÀ CUNG CẤP — Quản lý kho → Trả hàng nhà cung cấp.
//
// Chiều ngược của phiếu mua, dựng theo màn cùng tên của bản order v2:
//
//	lưu tạm ──duyệt──> đã duyệt (hàng RỜI kho đúng lúc này)
//
// Phiếu LUÔN gắn với một phiếu mua, từng dòng gắn với một dòng của phiếu mua ấy.
// Trả được bao nhiêu tính theo dòng đó, trừ đi phần đã trả ở các phiếu ĐÃ DUYỆT
// trước — xem migration 0043.
type SupplierReturn struct {
	ID uint `json:"id" gorm:"primaryKey"`
	TenantOwned
	// ShopID là chi nhánh lập phiếu, cũng là kho hàng sẽ rời đi khi duyệt.
	ShopID     uint   `json:"shop_id"`
	ReturnCode string `json:"return_code"`

	PurchaseOrderID   *uint  `json:"purchase_order_id"`
	PurchaseOrderCode string `json:"purchase_order_code"`

	SupplierID *uint `json:"supplier_id"`
	// SupplierCode/Name/địa chỉ/số máy là bản CHỤP hồ sơ bên bán lúc lập phiếu.
	SupplierCode  string `json:"supplier_code"`
	SupplierName  string `json:"supplier_name"`
	Address       string `json:"address"`
	Address2      string `json:"address_2" gorm:"column:address_2"`
	SupplierPhone string `json:"supplier_phone"`
	ContactPhone  string `json:"contact_phone"`

	Status string `json:"status"`

	DocumentDate *time.Time `json:"document_date" gorm:"type:date"`
	ExpiredDate  *time.Time `json:"expired_date" gorm:"type:date"`

	PurchaserID          *uint  `json:"purchaser_id"`
	ReceiverDeliveryNote string `json:"receiver_delivery_note"`

	VATPercent int `json:"vat_percent" gorm:"column:vat_percent"`

	ItemsAmount float64 `json:"items_amount"`
	VATAmount   float64 `json:"vat_amount" gorm:"column:vat_amount"`
	TotalAmount float64 `json:"total_amount"`

	Note string `json:"note"`

	CreatedBy *uint `json:"created_by"`
	HandledBy *uint `json:"handled_by"`
	// Ba cột tên dưới đây tra kèm khi đọc lên, bảng không có chúng. Chỉ có id thì
	// mọi màn hình muốn in tên đều phải tự đi tra một lượt nữa.
	CreatedByName string `json:"creator_name" gorm:"-"`
	PurchaserName string `json:"purchaser_name" gorm:"-"`
	PurchaserCode string `json:"purchaser_code" gorm:"-"`
	BranchName    string `json:"branch_name" gorm:"-"`

	ApprovedAt *time.Time `json:"approved_at"`

	Items []SupplierReturnItem `json:"items,omitempty" gorm:"foreignKey:SupplierReturnID"`

	CreatedAt time.Time      `json:"created_at"`
	UpdatedAt time.Time      `json:"updated_at"`
	DeletedAt gorm.DeletedAt `json:"-" gorm:"index"`
}

func (SupplierReturn) TableName() string { return "supplier_returns" }

// Trạng thái phiếu trả — chỉ hai nước, đúng như v2.
const (
	// SupplierReturnDraft — lưu tạm: sửa/xoá thoải mái, CHƯA đụng tới kho.
	SupplierReturnDraft = "draft"
	// SupplierReturnApproved — đã duyệt: hàng đã rời kho, phiếu khoá lại.
	SupplierReturnApproved = "approved"
)

// SupplierReturnItem — một dòng hàng trả.
type SupplierReturnItem struct {
	ID uint `json:"id" gorm:"primaryKey"`
	TenantOwned
	SupplierReturnID uint `json:"supplier_return_id"`
	// PurchaseOrderItemID trỏ dòng phiếu mua gốc — trần số lượng trả tính theo nó.
	PurchaseOrderItemID *uint `json:"purchase_order_item_id"`

	ProductID        *uint `json:"product_id"`
	ProductVariantID *uint `json:"product_variant_id"`

	ProductName string `json:"product_name"`
	VariantSKU  string `json:"variant_sku" gorm:"column:variant_sku"`
	VariantName string `json:"variant_name"`
	Thumbnail   string `json:"thumbnail"`

	UnitID    *uint   `json:"unit_id"`
	UnitName  string  `json:"unit_name"`
	UnitRatio float64 `json:"unit_ratio"`

	LotNumber  string     `json:"lot_number"`
	ExpireDate *time.Time `json:"expire_date" gorm:"type:date"`

	// Quantity là số đơn vị TRẢ; BaseQuantity = Quantity × UnitRatio và ĐÂY là số
	// trừ khỏi kho.
	Quantity     int `json:"quantity"`
	BaseQuantity int `json:"base_quantity"`

	UnitCost   float64 `json:"unit_cost"`
	VATPercent int     `json:"vat_percent" gorm:"column:vat_percent"`
	LineAmount float64 `json:"line_amount"`
	VATAmount  float64 `json:"vat_amount" gorm:"column:vat_amount"`
	TotalCost  float64 `json:"total_cost"`

	// PurchaseQuantity là số đã MUA của dòng gốc — cột "Số lượng nhập" trên màn.
	// Tra kèm khi đọc phiếu, bảng không có cột này.
	PurchaseQuantity int `json:"purchase_quantity" gorm:"-"`
	// RemainingStock là tồn còn lại của mặt hàng tại kho của phiếu, quy về đơn vị
	// trả — màn SỬA phiếu kẹp ô nhập theo tình hình HÔM NAY, không phải lúc lập.
	RemainingStock int `json:"remaining_stock" gorm:"-"`

	CreatedAt time.Time `json:"created_at"`
	UpdatedAt time.Time `json:"updated_at"`
}

func (SupplierReturnItem) TableName() string { return "supplier_return_items" }

// SupplierReturnHistory — mốc thao tác trên phiếu.
type SupplierReturnHistory struct {
	ID uint `json:"id" gorm:"primaryKey"`
	TenantOwned
	SupplierReturnID uint      `json:"supplier_return_id"`
	FromStatus       string    `json:"from_status"`
	ToStatus         string    `json:"to_status"`
	Note             string    `json:"note"`
	ChangedBy        *uint     `json:"changed_by"`
	CreatedAt        time.Time `json:"created_at"`
}

func (SupplierReturnHistory) TableName() string { return "supplier_return_history" }

// SupplierReturnFilter — tham số lọc/sắp xếp/phân trang khi liệt kê.
type SupplierReturnFilter struct {
	Keyword string // mã phiếu / mã bên bán / tên bên bán / ghi chú
	Status  string // rỗng hoặc all = mọi trạng thái; nhiều giá trị ngăn bởi dấu phẩy
	// SupplierID = 0 là mọi nhà cung cấp.
	SupplierID uint
	// ShopID cắt theo chi nhánh LẬP phiếu. 0 = không cắt. Xem OrderFilter.ShopID.
	ShopID   uint
	FromDate string // YYYY-MM-DD (theo created_at)
	ToDate   string // YYYY-MM-DD
	Sort     string // newest | oldest | total_desc | total_asc | document_desc
	Page     int
	PageSize int
}

// SupplierReturnStats — con số một dòng trên đầu trang.
type SupplierReturnStats struct {
	Total    int64 `json:"total"`
	Draft    int64 `json:"draft"`
	Approved int64 `json:"approved"`
	// ReturnedAmount là tiền hàng đã trả lại thật (chỉ phiếu đã duyệt).
	ReturnedAmount float64 `json:"returned_amount"`
}

// SupplierReturnPurchase — một phiếu mua trả hàng được, cho ô chọn phiếu mua.
type SupplierReturnPurchase struct {
	ID           uint       `json:"id"`
	POCode       string     `json:"po_code"`
	DocumentDate *time.Time `json:"document_date"`
	TotalAmount  float64    `json:"total_amount"`
	ApprovedAt   *time.Time `json:"approved_at"`
}

// SupplierReturnLine — một dòng của phiếu mua, kèm những gì màn lập phiếu cần để
// kẹp số lượng trả.
type SupplierReturnLine struct {
	PurchaseItemID uint    `json:"purchase_item_id"`
	VariantID      uint    `json:"variant_id"`
	ProductID      uint    `json:"product_id"`
	ProductName    string  `json:"product_name"`
	VariantSKU     string  `json:"variant_sku"`
	VariantName    string  `json:"variant_name"`
	Thumbnail      string  `json:"thumbnail"`
	UnitID         uint    `json:"unit_id"`
	UnitName       string  `json:"unit_name"`
	UnitRatio      float64 `json:"unit_ratio"`
	LotNumber      string  `json:"lot_number"`
	ExpireDate     *string `json:"expire_date"`
	Quantity       int     `json:"quantity"`
	UnitCost       float64 `json:"unit_cost"`
	VATPercent     int     `json:"vat_percent"`
	// Returned là số đã trả ở các phiếu ĐÃ DUYỆT trước đó, tính theo đơn vị của
	// dòng phiếu mua này.
	Returned int `json:"returned"`
	// Stock là tồn còn lại tại kho của phiếu mua, quy về đơn vị của dòng này.
	Stock int `json:"stock"`
	// Returnable = min(Quantity - Returned, Stock), không âm. Đây là con số màn
	// hình kẹp ô nhập, và cũng là con số API kiểm lại lúc lưu.
	Returnable int `json:"returnable"`
}

// SupplierReturnPurchaseDetail — phiếu mua kèm các dòng trả được.
type SupplierReturnPurchaseDetail struct {
	ID         uint   `json:"id"`
	POCode     string `json:"po_code"`
	SupplierID uint   `json:"supplier_id"`
	// PurchaserID là nhân viên mua hàng ghi trên PHIẾU MUA. Màn lập phiếu trả
	// điền sẵn ô "Nhân viên mua hàng" theo người này: trả lô hàng của phiếu nào
	// thì người mua lô ấy là người biết chuyện, bắt chọn lại từ đầu chỉ mời chọn
	// nhầm sang một cái tên không liên quan tới lô hàng.
	PurchaserID  uint                 `json:"purchaser_id"`
	VATMode      string               `json:"vat_mode"`
	VATPercent   int                  `json:"vat_percent"`
	DocumentDate *time.Time           `json:"document_date"`
	Lines        []SupplierReturnLine `json:"lines"`
}

// SupplierReturnRepository — truy cập bảng supplier_returns và các bảng đi kèm.
type SupplierReturnRepository interface {
	List(ctx context.Context, f SupplierReturnFilter) ([]SupplierReturn, int64, error)
	FindByID(ctx context.Context, id uint) (*SupplierReturn, error)
	Stats(ctx context.Context) (SupplierReturnStats, error)
	Histories(ctx context.Context, returnID uint) ([]SupplierReturnHistory, error)

	// PhieuMuaTraDuoc liệt kê phiếu mua ĐÃ DUYỆT của một nhà cung cấp — hàng chưa
	// vào kho thì chưa có gì để trả.
	PhieuMuaTraDuoc(ctx context.Context, supplierID uint, limit int) ([]SupplierReturnPurchase, error)
	// DongPhieuMua trả các dòng của một phiếu mua kèm số đã trả và tồn còn lại.
	DongPhieuMua(ctx context.Context, purchaseID uint) (*SupplierReturnPurchaseDetail, error)

	// Create tạo phiếu trong MỘT transaction, tự sinh mã và ghi mốc lịch sử.
	// KHÔNG đụng tới kho: phiếu mới lập luôn là phiếu lưu tạm.
	Create(ctx context.Context, sr *SupplierReturn) error
	// Update sửa thông tin + THAY TOÀN BỘ danh sách hàng dưới khoá dòng.
	Update(ctx context.Context, id uint, mutate func(sr *SupplierReturn) ([]string, []SupplierReturnItem, error)) (*SupplierReturn, error)
	// Approve duyệt phiếu trong một transaction: khoá phiếu, TRỪ TỒN KHO theo
	// base_quantity của từng dòng và ghi bút toán inventory_transactions
	// (type='export', reference_type='supplier_return').
	//
	// Duyệt hai lần trả ErrSupplierReturnLocked và KHÔNG dòng nào được ghi.
	Approve(ctx context.Context, id uint, a SupplierReturnApproval) (*SupplierReturn, error)
	Delete(ctx context.Context, id uint) error

	// DaTraTheoDongMua trả về số đã trả (ở phiếu ĐÃ DUYỆT) theo từng dòng phiếu
	// mua. `boQua` là id phiếu trả cần loại khỏi tổng — lượt sửa không tự chặn
	// chính nó.
	DaTraTheoDongMua(ctx context.Context, purchaseItemIDs []uint, boQua uint) (map[uint]int, error)
}

// SupplierReturnApproval là một lượt duyệt phiếu trả.
type SupplierReturnApproval struct {
	Note    string
	ActorID uint
}

var (
	// ErrSupplierReturnEmpty — phiếu không có dòng hàng nào.
	ErrSupplierReturnEmpty = errors.New("phiếu trả hàng phải có ít nhất một dòng hàng")

	// ErrSupplierReturnLocked — phiếu đã duyệt, không sửa/xoá được nữa.
	ErrSupplierReturnLocked = errors.New("phiếu trả đã duyệt nên không sửa được — kho đã trừ theo nó, muốn chữa thì cân đối ở màn Tồn kho")

	// ErrSupplierReturnNoPurchase — chưa chọn phiếu mua, hoặc phiếu mua không
	// phải của nhà cung cấp đang chọn.
	ErrSupplierReturnNoPurchase = errors.New("phiếu trả phải gắn với một phiếu mua đã duyệt của đúng nhà cung cấp đó")

	// ErrSupplierReturnLineLa — dòng gửi lên không nằm trong phiếu mua ấy.
	ErrSupplierReturnLineLa = errors.New("dòng hàng không thuộc phiếu mua đã chọn")

	// ErrSupplierReturnQuaSo — trả nhiều hơn phần còn được trả của dòng.
	ErrSupplierReturnQuaSo = errors.New("số lượng trả vượt quá phần còn được trả của dòng hàng")

	// ErrSupplierReturnUnitRatio — quy đổi ra số lẻ; sổ kho đếm nguyên.
	ErrSupplierReturnUnitRatio = errors.New("số lượng quy đổi ra đơn vị tính chính phải là số nguyên")
)
