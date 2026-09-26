package domain

import (
	"context"
	"errors"
	"time"

	"gorm.io/gorm"
)

// PHIẾU ĐIỀU CHỈNH TỒN KHO — Quản lý kho → Điều chỉnh tồn kho.
//
// Nắn lại số tồn của MỘT kho theo chứng từ, đúng vòng đời của
// war_warehouse_adjusts bên v2:
//
//	lưu tạm ──gửi duyệt──> chờ duyệt ──duyệt──> đã duyệt (kho đổi số)
//	                                  └──từ chối──> từ chối
//
// Duyệt là lúc DUY NHẤT tồn kho đổi vì phiếu này, và là MỘT bước — xem
// migration 0054 vì sao không port công tắc "2 bước" của v2.
type PhieuDieuChinh struct {
	ID uint `json:"id" gorm:"primaryKey"`
	TenantOwned
	AdjustmentCode string `json:"code" gorm:"column:adjustment_code"`

	// ShopID là kho bị nắn số, chốt lúc lập phiếu. Lượt duyệt ghi kho theo con số
	// này, KHÔNG theo chi nhánh của người bấm duyệt.
	ShopID uint `json:"shop_id"`

	Type   string `json:"type"`   // adjust | balance
	Status string `json:"status"` // draft | pending | approved | rejected

	Note         string `json:"note"`
	RejectReason string `json:"reject_reason"`

	CreatedBy *uint `json:"created_by"`
	HandledBy *uint `json:"handled_by"`

	ApprovedAt *time.Time `json:"approval_date"`

	// Ba cột dưới đây tra kèm khi đọc lên, bảng không có chúng.
	ShopName      string `json:"shop_name" gorm:"-"`
	CreatedByName string `json:"created_by_name" gorm:"-"`
	// ApproverName chỉ có khi phiếu ĐÃ DUYỆT — người từ chối không phải người duyệt.
	ApproverName string `json:"approver_name" gorm:"-"`
	// WarehouseStatus là cột "Trạng thái kho" của bảng danh sách v2: rỗng khi kho
	// chưa đổi, `done` khi đã duyệt, `rejected` khi từ chối. Suy từ Status.
	WarehouseStatus string `json:"warehouse_status" gorm:"-"`

	Items []PhieuDieuChinhItem `json:"items,omitempty" gorm:"foreignKey:StockAdjustmentID"`

	CreatedAt time.Time      `json:"created_at"`
	UpdatedAt time.Time      `json:"updated_at"`
	DeletedAt gorm.DeletedAt `json:"-" gorm:"index"`
}

func (PhieuDieuChinh) TableName() string { return "stock_adjustments" }

// Trạng thái phiếu điều chỉnh — bốn nước như v2 (0 lưu tạm · 1 chờ duyệt ·
// 2 đã duyệt · 3 từ chối).
const (
	DieuChinhDraft    = "draft"
	DieuChinhPending  = "pending"
	DieuChinhApproved = "approved"
	DieuChinhRejected = "rejected"
)

// Loại phiếu điều chỉnh.
const (
	// DieuChinhLoaiThuong — người dùng gõ số lệch từng dòng.
	DieuChinhLoaiThuong = "adjust"
	// DieuChinhLoaiCanDoi — cân đối hàng âm: đưa lô đang âm về 0.
	DieuChinhLoaiCanDoi = "balance"
)

// Trạng thái kho suy ra cho cột "Trạng thái kho" — chữ do giao diện đặt.
const (
	DieuChinhKhoXong    = "done"
	DieuChinhKhoTuChoi  = "rejected"
	DieuChinhKhoChoXuLy = "pending"
)

// TrangThaiKho suy cột "Trạng thái kho" từ trạng thái phiếu: kho chỉ đổi lúc
// duyệt nên phiếu lưu tạm / chờ duyệt để trống, đúng như v2 chưa sinh thẻ kho.
func (p PhieuDieuChinh) TrangThaiKho() string {
	switch p.Status {
	case DieuChinhApproved:
		return DieuChinhKhoXong
	case DieuChinhRejected:
		return DieuChinhKhoTuChoi
	}

	return ""
}

// PhieuDieuChinhItem — một dòng hàng của phiếu.
//
// Quantity là tồn CỦA LÔ ĐÓ lúc lập (bản chụp để in "tồn trước / tồn sau"),
// AdjustQuantity là số lệch có dấu. Lượt duyệt chỉ dùng AdjustQuantity.
type PhieuDieuChinhItem struct {
	ID uint `json:"id" gorm:"primaryKey"`
	TenantOwned
	StockAdjustmentID uint `json:"stock_adjustment_id"`

	ProductID        *uint `json:"product_id"`
	ProductVariantID *uint `json:"variant_id" gorm:"column:product_variant_id"`

	ProductName string `json:"product_name"`
	VariantSKU  string `json:"sku" gorm:"column:variant_sku"`
	VariantName string `json:"variant_name"`
	UnitName    string `json:"unit_name"`

	// LotNumber rỗng = LoKhongXacDinh (cùng quy ước với stock_lots).
	LotNumber  string     `json:"lot_number"`
	ExpireDate *time.Time `json:"expire_date" gorm:"type:date"`

	Quantity       int     `json:"quantity"`
	AdjustQuantity int     `json:"adjust_quantity"`
	UnitCost       float64 `json:"adjust_price" gorm:"column:unit_cost"`

	Attachment string `json:"attachment"`

	// InventoryStatus là trạng thái nhập kho của dòng (cột "Trạng thái tồn" v2):
	// `pending` khi phiếu chưa duyệt, `stocked` khi kho đã đổi. Suy từ phiếu.
	InventoryStatus string `json:"inventory_status" gorm:"-"`
	// Lots là các lô đang có của mặt hàng TẠI KHO của phiếu, tra kèm khi đọc một
	// phiếu — ô chọn lô của màn sửa đổ danh sách này ra.
	Lots []TonKhoLo `json:"lots" gorm:"-"`

	CreatedAt time.Time `json:"created_at"`
	UpdatedAt time.Time `json:"updated_at"`
}

func (PhieuDieuChinhItem) TableName() string { return "stock_adjustment_items" }

// PhieuDieuChinhHistory — mốc thao tác trên phiếu.
type PhieuDieuChinhHistory struct {
	ID uint `json:"id" gorm:"primaryKey"`
	TenantOwned
	StockAdjustmentID uint      `json:"stock_adjustment_id"`
	FromStatus        string    `json:"from_status"`
	ToStatus          string    `json:"to_status"`
	Note              string    `json:"note"`
	ChangedBy         *uint     `json:"changed_by"`
	CreatedAt         time.Time `json:"created_at"`
}

func (PhieuDieuChinhHistory) TableName() string { return "stock_adjustment_history" }

// DieuChinhFilter — tham số lọc/sắp xếp/phân trang khi liệt kê.
type DieuChinhFilter struct {
	Keyword string // mã phiếu / ghi chú
	Type    string // adjust | balance | rỗng = cả hai
	Status  string // nhiều giá trị ngăn bởi dấu phẩy; rỗng hoặc all = mọi trạng thái
	// WarehouseStatus lọc theo cột "Trạng thái kho" (done | rejected | pending),
	// dịch ngược về trạng thái phiếu.
	WarehouseStatus string
	// CreatedBy — id người lập (users.id), nhiều giá trị ngăn bởi dấu phẩy.
	CreatedBy string
	ShopID    uint   // 0 = mọi chi nhánh
	FromDate  string // YYYY-MM-DD (theo created_at)
	ToDate    string // YYYY-MM-DD
	Sort      string // newest | oldest | code_asc | code_desc
	Page      int
	PageSize  int
}

// DieuChinhHang là bản chụp một mặt hàng TẠI KHO của phiếu, dùng lúc lập/sửa.
//
// Service tra lại thay vì tin dữ liệu trình duyệt: tên, mã, đơn vị, giá vốn, tồn
// từng lô là những thứ in lên chứng từ và ghi vào sổ kho.
//
// Cũng là thứ đường GET mat-hang trả cho màn lập phiếu — vai của `getMenu`
// bên v2: chọn hàng xong mới hỏi lô, vì ô tìm hàng dùng chung của phiếu mua chỉ
// bày lô dương.
type DieuChinhHang struct {
	VariantID   uint    `json:"variant_id"`
	ProductID   uint    `json:"product_id"`
	ProductName string  `json:"product_name"`
	SKU         string  `json:"sku"`
	VariantName string  `json:"variant_name"`
	UnitName    string  `json:"unit_name"`
	CostPrice   float64 `json:"cost_price"`
	// Stock là tổng tồn của mặt hàng tại kho.
	Stock int `json:"stock"`
	// Lots là MỌI lô đang có dòng tại kho, kể cả lô "" và lô đang âm — khác
	// loCuaBienThe (chỉ lô dương có số): phiếu điều chỉnh chính là chỗ chữa
	// những lô âm ấy.
	Lots []TonKhoLo `json:"lots"`
}

// HangAm là một lô đang âm tại kho, chờ cân đối.
type HangAm struct {
	VariantID   uint   `json:"variant_id"`
	ProductID   uint   `json:"product_id"`
	SKU         string `json:"sku"`
	ProductName string `json:"product_name"`
	VariantName string `json:"variant_name"`
	UnitName    string `json:"unit_name"`
	UnitID      uint   `json:"unit_id"`

	LotNumber  string     `json:"lot_number"`
	ExpireDate *time.Time `json:"expire_date"`

	// Quantity là số đang âm trong sổ.
	Quantity int `json:"quantity"`
	// PendingAdjust là tổng số lệch của các phiếu cân đối CHƯA duyệt đang nhắm
	// vào lô này; FutureQuantity = Quantity + PendingAdjust. Chỉ liệt kê lô mà
	// FutureQuantity vẫn âm — lô đã có phiếu chờ duyệt bù đủ thì không bày ra
	// để lập thêm một phiếu trùng.
	PendingAdjust  int `json:"pending_adjust_quantity"`
	FutureQuantity int `json:"future_quantity"`
}

// DieuChinhApproval — tham số của lượt duyệt / từ chối.
type DieuChinhApproval struct {
	ActorID uint
	Note    string
}

// PhieuDieuChinhRepository — sổ phiếu điều chỉnh tồn kho.
type PhieuDieuChinhRepository interface {
	List(ctx context.Context, f DieuChinhFilter) ([]PhieuDieuChinh, int64, error)
	FindByID(ctx context.Context, id uint) (*PhieuDieuChinh, error)
	Create(ctx context.Context, p *PhieuDieuChinh) error
	// Update đọc-sửa-ghi dưới khoá dòng. mutate nhận phiếu đang khoá và trả về
	// (cột cần ghi, danh sách dòng hàng mới; nil = giữ dòng cũ).
	Update(
		ctx context.Context, id uint,
		mutate func(p *PhieuDieuChinh) ([]string, []PhieuDieuChinhItem, error),
	) (*PhieuDieuChinh, error)
	// Submit chuyển lưu tạm → chờ duyệt.
	Submit(ctx context.Context, id uint, a DieuChinhApproval) (*PhieuDieuChinh, error)
	// Approve chuyển phiếu sang đã duyệt VÀ ghi kho trong cùng một transaction.
	// Đây là lúc DUY NHẤT tồn kho đổi vì phiếu này.
	Approve(ctx context.Context, id uint, a DieuChinhApproval) (*PhieuDieuChinh, error)
	// Reject chuyển lưu tạm / chờ duyệt → từ chối, kèm lý do.
	Reject(ctx context.Context, id uint, a DieuChinhApproval) (*PhieuDieuChinh, error)
	Delete(ctx context.Context, id uint) error
	// ThongTinHang tra bản chụp mặt hàng + tồn + lô TẠI KHO. Một lượt gọi cho cả
	// phiếu.
	ThongTinHang(ctx context.Context, shopID uint, variantIDs []uint) (map[uint]DieuChinhHang, error)
	// HangAm liệt kê các lô đang âm tại kho, đã trừ phần các phiếu cân đối chờ duyệt.
	HangAm(ctx context.Context, shopID uint) ([]HangAm, error)
	// ChiNhanhMacDinh là kho của request: chi nhánh ghim trong header, hoặc chi
	// nhánh duy nhất của cửa hàng. Không xác định được thì lỗi — phiếu điều chỉnh
	// luôn là việc của MỘT kho cụ thể.
	ChiNhanhMacDinh(ctx context.Context) (uint, error)
}

// Lỗi nghiệp vụ của phiếu điều chỉnh. Mỗi lỗi một cách chữa khác nhau nên
// không gộp vào ErrConflict.
var (
	// ErrDieuChinhEmpty — phiếu không có dòng hàng nào có số lệch.
	ErrDieuChinhEmpty = errors.New("phiếu điều chỉnh phải có ít nhất một dòng hàng")

	// ErrDieuChinhLocked — phiếu đã duyệt hoặc đã từ chối: khoá lại.
	ErrDieuChinhLocked = errors.New("phiếu điều chỉnh đã duyệt hoặc đã từ chối nên không sửa được")

	// ErrDieuChinhChuaGuiDuyet — thao tác cần phiếu ở trạng thái khác (vd: từ chối
	// một phiếu đã duyệt).
	ErrDieuChinhSaiTrangThai = errors.New("phiếu điều chỉnh không ở trạng thái cho phép thao tác này")

	// ErrDieuChinhThieuTon — bớt kho nhiều hơn số đang có. Kiểm ở lượt duyệt chứ
	// không chỉ lúc lập: giữa hai thời điểm ấy hàng có thể đã bán đi.
	ErrDieuChinhThieuTon = errors.New("kho không đủ hàng để bớt")

	// ErrDieuChinhLoKhongCo — dòng phiếu trỏ vào một số lô mà kho chưa có cho mặt
	// hàng đó. Phiếu điều chỉnh chỉ NẮN số của lô đã có; lô mới vào kho bằng phiếu
	// nhập (chủ tiệm chốt). Trước đây API lặng lẽ nhận lô lạ với tồn 0 — trong khi
	// màn hình không có mục "lô mới", nên đường ấy chỉ đi được bằng tay, giống hệt
	// việc gửi variant lạ mà lại không bị 404. err được bọc kèm tên hàng + số lô.
	ErrDieuChinhLoKhongCo = errors.New("lô không có trong kho")

	// ErrDieuChinhTrungLo — cùng mặt hàng chọn trùng lô trong một phiếu.
	ErrDieuChinhTrungLo = errors.New("cùng mặt hàng không được chọn trùng lô trong một phiếu")

	// ErrDieuChinhKhongCoHangAm — bấm "Cân đối hàng âm" khi kho không còn lô âm nào.
	ErrDieuChinhKhongCoHangAm = errors.New("kho đang không có hàng hoá nào âm")
)
