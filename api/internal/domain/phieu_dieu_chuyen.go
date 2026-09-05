package domain

import (
	"context"
	"errors"
	"time"

	"gorm.io/gorm"
)

// PHIẾU ĐIỀU CHUYỂN — Quản lý kho → Phiếu điều chuyển.
//
// Chuyển hàng giữa HAI KHO của cùng một cửa hàng:
//
//	lưu tạm ──duyệt──> đã duyệt (hàng RỜI kho xuất và VÀO kho nhập)
//
// Đây là đường DUY NHẤT để hàng đi từ chi nhánh này sang chi nhánh kia. Mọi
// chứng từ khác chỉ đụng vào MỘT kho; phiếu này đụng hai, và đó là toàn bộ lý do
// nó phải tồn tại — xem migration 0050.
//
// Khác các chứng từ khác ở chỗ KHÔNG có cột `shop_id` chung: phiếu thuộc về cả
// hai chi nhánh cùng lúc. Danh sách của một chi nhánh phải gồm cả phiếu nó GỬI
// lẫn phiếu nó NHẬN.
type PhieuDieuChuyen struct {
	ID uint `json:"id" gorm:"primaryKey"`
	TenantOwned
	TransferCode string `json:"transfer_code"`

	// FromShopID / ToShopID chốt lúc lập phiếu và không đổi nữa. Lượt duyệt ghi
	// kho theo hai con số này, KHÔNG theo chi nhánh của người bấm duyệt: thủ kho
	// ở Quận 1 duyệt giúp một phiếu Quận 7 → Quận 3 là chuyện thường.
	FromShopID uint `json:"from_shop_id"`
	ToShopID   uint `json:"to_shop_id"`

	Status string `json:"status"`

	// ReceiverID là nhân viên ở kho NHẬN, người ký nhận hàng.
	ReceiverID *uint `json:"receiver_id"`

	Note string `json:"note"`

	// ItemsAmount là tổng giá trị hàng chuyển theo giá vốn — con số để đối chiếu,
	// không phải tiền phải trả: hàng vẫn nằm trong cùng một cửa hàng.
	ItemsAmount float64 `json:"items_amount"`

	CreatedBy *uint `json:"created_by"`
	HandledBy *uint `json:"handled_by"`

	// Bốn cột tên dưới đây tra kèm khi đọc lên, bảng không có chúng. Chỉ có id thì
	// mọi màn hình muốn in tên đều phải tự đi tra một lượt nữa.
	FromShopName  string `json:"from_shop_name" gorm:"-"`
	ToShopName    string `json:"to_shop_name" gorm:"-"`
	ReceiverName  string `json:"receiver_name" gorm:"-"`
	CreatedByName string `json:"creator_name" gorm:"-"`

	// ApprovedAt là mốc hàng đổi kho — cột "Ngày nhập kho" của bảng danh sách.
	ApprovedAt *time.Time `json:"approved_at"`

	Items []PhieuDieuChuyenItem `json:"items,omitempty" gorm:"foreignKey:StockTransferID"`

	CreatedAt time.Time      `json:"created_at"`
	UpdatedAt time.Time      `json:"updated_at"`
	DeletedAt gorm.DeletedAt `json:"-" gorm:"index"`
}

func (PhieuDieuChuyen) TableName() string { return "stock_transfers" }

// Trạng thái phiếu điều chuyển — chỉ hai nước, đúng như v2.
const (
	// DieuChuyenDraft — lưu tạm: sửa/xoá thoải mái, CHƯA đụng tới kho.
	DieuChuyenDraft = "draft"
	// DieuChuyenApproved — đã duyệt: hàng đã đổi kho, phiếu khoá lại.
	DieuChuyenApproved = "approved"
)

// PhieuDieuChuyenItem — một dòng hàng chuyển.
//
// Số lượng tính theo ĐƠN VỊ TÍNH CHÍNH, không có quy đổi như phiếu mua: hàng
// không đổi hình dạng khi đi từ kho này sang kho kia, nên bày thêm ô "đơn vị
// chuyển" chỉ là một chỗ nữa để gõ nhầm.
type PhieuDieuChuyenItem struct {
	ID uint `json:"id" gorm:"primaryKey"`
	TenantOwned
	StockTransferID uint `json:"stock_transfer_id"`

	ProductID        *uint `json:"product_id"`
	ProductVariantID *uint `json:"product_variant_id"`

	ProductName string `json:"product_name"`
	VariantSKU  string `json:"variant_sku" gorm:"column:variant_sku"`
	VariantName string `json:"variant_name"`
	UnitName    string `json:"unit_name"`

	// LotNumber rỗng = không chỉ định lô: lượt xuất rút theo luật kho của cửa
	// hàng, lượt nhập vào lô không xác định. Có số lô thì HAI ĐẦU dùng chung
	// đúng số ấy — hàng qua kho khác nhưng vẫn là lô đó, hạn đó.
	LotNumber  string     `json:"lot_number"`
	ExpireDate *time.Time `json:"expire_date" gorm:"type:date"`

	Quantity   int     `json:"quantity"`
	UnitCost   float64 `json:"unit_cost"`
	LineAmount float64 `json:"line_amount"`

	// RemainingStock là tồn còn lại của mặt hàng TẠI KHO XUẤT, tra kèm khi đọc
	// phiếu — màn sửa kẹp ô nhập theo tình hình HÔM NAY, không phải lúc lập.
	RemainingStock int `json:"remaining_stock" gorm:"-"`

	CreatedAt time.Time `json:"created_at"`
	UpdatedAt time.Time `json:"updated_at"`
}

func (PhieuDieuChuyenItem) TableName() string { return "stock_transfer_items" }

// PhieuDieuChuyenHistory — mốc thao tác trên phiếu.
type PhieuDieuChuyenHistory struct {
	ID uint `json:"id" gorm:"primaryKey"`
	TenantOwned
	StockTransferID uint      `json:"stock_transfer_id"`
	FromStatus      string    `json:"from_status"`
	ToStatus        string    `json:"to_status"`
	Note            string    `json:"note"`
	ChangedBy       *uint     `json:"changed_by"`
	CreatedAt       time.Time `json:"created_at"`
}

func (PhieuDieuChuyenHistory) TableName() string { return "stock_transfer_history" }

// DieuChuyenFilter — tham số lọc/sắp xếp/phân trang khi liệt kê.
type DieuChuyenFilter struct {
	Keyword string // mã phiếu / ghi chú
	Status  string // rỗng hoặc all = mọi trạng thái; nhiều giá trị ngăn bởi dấu phẩy
	// ShopID cắt theo chi nhánh, và cắt theo CẢ HAI ĐẦU: phiếu mà chi nhánh này
	// gửi đi lẫn phiếu nó nhận về đều là việc của nó. Cắt một đầu thì kho nhận
	// không thấy chứng từ đã làm tồn của mình tăng lên.
	ShopID uint
	// VariantID lọc phiếu có chứa mặt hàng này — ô "Hàng hoá" của khối lọc.
	VariantID uint
	FromDate  string // YYYY-MM-DD (theo created_at)
	ToDate    string // YYYY-MM-DD
	Sort      string // newest | oldest
	Page      int
	PageSize  int
}

// DieuChuyenStats — con số một dòng trên đầu trang.
type DieuChuyenStats struct {
	Total    int64 `json:"total"`
	Draft    int64 `json:"draft"`
	Approved int64 `json:"approved"`
	// TransferredAmount là giá trị hàng đã chuyển thật (chỉ phiếu đã duyệt).
	TransferredAmount float64 `json:"transferred_amount"`
}

// DieuChuyenApproval — tham số của lượt duyệt.
type DieuChuyenApproval struct {
	ActorID uint
	Note    string
}

// DieuChuyenHang là bản chụp một mặt hàng TẠI KHO XUẤT, dùng lúc lập/sửa phiếu.
//
// Vì sao service phải tra lại thay vì tin dữ liệu trình duyệt gửi lên: tên hàng,
// mã, đơn vị, giá vốn và hạn lô là những thứ in lên chứng từ và ghi vào sổ kho.
// Nhận chúng từ client là ghi vào sổ một con số không có gốc — và không có cách
// nào phát hiện khi nó sai.
type DieuChuyenHang struct {
	VariantID   uint
	ProductID   uint
	ProductName string
	SKU         string
	VariantName string
	UnitName    string
	CostPrice   float64
	// Stock là tồn tại KHO XUẤT — trần của ô số lượng lúc lập phiếu.
	Stock int
	// Lots là các lô CÒN HÀNG tại kho xuất, xếp theo hạn gần nhất trước.
	Lots []TonKhoLo
}

// PhieuDieuChuyenRepository — sổ phiếu điều chuyển.
type PhieuDieuChuyenRepository interface {
	List(ctx context.Context, f DieuChuyenFilter) ([]PhieuDieuChuyen, int64, error)
	Stats(ctx context.Context, f DieuChuyenFilter) (DieuChuyenStats, error)
	FindByID(ctx context.Context, id uint) (*PhieuDieuChuyen, error)
	Create(ctx context.Context, pdc *PhieuDieuChuyen) error
	// Update đọc-sửa-ghi dưới khoá dòng. mutate nhận phiếu đang khoá và trả về
	// (cột cần ghi, danh sách dòng hàng mới).
	Update(
		ctx context.Context, id uint,
		mutate func(pdc *PhieuDieuChuyen) ([]string, []PhieuDieuChuyenItem, error),
	) (*PhieuDieuChuyen, error)
	// Approve chuyển phiếu sang đã duyệt VÀ ghi kho hai đầu trong cùng một
	// transaction. Đây là lúc DUY NHẤT tồn kho đổi vì phiếu này.
	Approve(ctx context.Context, id uint, a DieuChuyenApproval) (*PhieuDieuChuyen, error)
	Delete(ctx context.Context, id uint) error
	// ThongTinHang tra bản chụp mặt hàng + tồn + lô TẠI KHO XUẤT. Một lượt gọi
	// cho cả phiếu; tra từng dòng là mỗi phiếu vài chục lượt đọc database.
	ThongTinHang(ctx context.Context, shopID uint, variantIDs []uint) (map[uint]DieuChuyenHang, error)
	// ChanHangKhongThuoc từ chối những mặt hàng đã gán riêng cho chi nhánh KHÁC.
	//
	// CHỈ dùng cho KHO NHẬN, không dùng cho kho xuất — xem chotDongHang bên
	// service: chặn đầu gửi là khoá chết số hàng đã lỡ nằm sai chỗ.
	ChanHangKhongThuoc(ctx context.Context, shopID uint, variantIDs []uint) error
}

// Lỗi nghiệp vụ của phiếu điều chuyển. Mỗi lỗi một cách chữa khác nhau nên
// không gộp vào ErrConflict.
var (
	// ErrDieuChuyenEmpty — phiếu không có dòng hàng nào.
	ErrDieuChuyenEmpty = errors.New("phiếu điều chuyển phải có ít nhất một dòng hàng")

	// ErrDieuChuyenLocked — phiếu đã duyệt: kho đã đổi theo nó nên khoá lại.
	ErrDieuChuyenLocked = errors.New("phiếu điều chuyển đã duyệt nên không sửa được")

	// ErrDieuChuyenCungKho — kho xuất trùng kho nhập. Một phiếu như vậy không
	// chuyển gì cả, nhưng vẫn ghi hai bút toán ngược nhau vào cùng một kho và làm
	// bẩn sổ.
	ErrDieuChuyenCungKho = errors.New("kho xuất và kho nhập không được trùng nhau")

	// ErrDieuChuyenKhoLa — một trong hai kho không thuộc cửa hàng, hoặc đã đóng.
	ErrDieuChuyenKhoLa = errors.New("chi nhánh không hợp lệ")

	// ErrDieuChuyenThieuTon — kho xuất không đủ hàng. Kiểm ở lượt duyệt chứ
	// không chỉ lúc lập: giữa hai thời điểm ấy hàng có thể đã bán đi.
	ErrDieuChuyenThieuTon = errors.New("kho xuất không đủ hàng")
)
