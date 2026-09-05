package domain

import "time"

// LoKhongXacDinh là số lô của hàng không rõ thuộc lô nào.
//
// Bản v2 để nguyên chuỗi tiếng Việt 'Không xác định' trong dữ liệu; ở đây là
// CHUỖI RỖNG — xem migration 0047 để biết vì sao. Nhãn hiển thị do tầng giao
// diện đặt, không nằm dưới database.
const LoKhongXacDinh = ""

// TonKhoLo là số hàng của MỘT LÔ của một biến thể ĐANG NẰM TẠI một chi nhánh.
//
// QUAN HỆ VỚI TonKhoChiNhanh — bất biến của cả module:
//
//	variant_stocks.quantity = SUM(stock_lots.quantity) cùng (shop, variant)
//
// `variant_stocks` vẫn là nguồn sự thật của TỔNG (hơn chục đường đọc cộng thẳng
// từ nó), còn bảng này là bản chia nhỏ theo lô. Hai bảng chỉ được ghi cùng nhau,
// trong cùng một giao dịch, qua đúng một cửa `ghiTonChiNhanh`.
type TonKhoLo struct {
	ID uint `json:"id" gorm:"primaryKey"`
	TenantOwned
	ShopID           uint `json:"shop_id"`
	ProductVariantID uint `json:"product_variant_id"`

	// LotNumber rỗng = LoKhongXacDinh.
	LotNumber string `json:"lot_number"`
	// ExpireDate là hạn dùng của LÔ. NULL = hàng không có hạn, và FEFO xếp những
	// lô ấy xuống cuối chứ không lên đầu như MySQL vẫn xếp NULL.
	ExpireDate *time.Time `json:"expire_date" gorm:"type:date"`

	Quantity int `json:"quantity"`
	// UnitCost là giá nhập một đơn vị chính của lô — để TRA CỨU và gợi ý giá cho
	// lượt nhập sau. Giá vốn dùng cho báo cáo lãi gộp vẫn là bình quân gia quyền
	// trên ProductVariant.CostPrice; bảng này không đổi cách tính đó.
	UnitCost float64 `json:"unit_cost"`

	CreatedAt time.Time `json:"created_at"`
	UpdatedAt time.Time `json:"updated_at"`
}

func (TonKhoLo) TableName() string { return "stock_lots" }

// Loại chứng từ ghi trong sổ tiêu thụ theo lô.
const (
	KhoRefDonHang   = "order"
	KhoRefTraHang   = "order_return"
	KhoRefPhieuMua  = "purchase"
	KhoRefTraNCC    = "supplier_return"
	KhoRefKiemKe    = "stocktake"
	KhoRefDieuChinh = "adjustment"
	// KhoRefDieuChuyen — phiếu điều chuyển. MỘT phiếu sinh ra HAI bút toán mang
	// cùng ref: một lượt xuất ở kho gửi, một lượt nhập ở kho nhận. Tra ngược
	// bằng ref phải ra đủ cặp, nếu không thì có một đầu đã hỏng.
	KhoRefDieuChuyen = "stock_transfer"
)

// ChuyenKhoLo là MỘT dòng sổ tiêu thụ: lô nào đi ra (hoặc vào) bao nhiêu, vì
// chứng từ nào.
//
// Có mặt vì bán hàng KHÔNG chọn lô — hệ thống tự rút theo FIFO/FEFO — nên tới
// lúc huỷ đơn hay khách trả hàng, chỉ sổ này mới biết phải hoàn về lô nào.
type ChuyenKhoLo struct {
	ID uint `json:"id" gorm:"primaryKey"`
	TenantOwned
	ShopID           uint   `json:"shop_id"`
	ProductVariantID uint   `json:"product_variant_id"`
	LotNumber        string `json:"lot_number"`

	// Quantity theo chiều hàng đi: âm = ra khỏi kho, dương = vào kho.
	Quantity int `json:"quantity"`

	ReferenceType string `json:"reference_type"`
	ReferenceID   *uint  `json:"reference_id"`

	CreatedAt time.Time `json:"created_at"`
}

func (ChuyenKhoLo) TableName() string { return "stock_lot_moves" }

// LoNhap mô tả lô của một lượt hàng VÀO kho — nhập mua, trả lại từ khách theo
// đúng lô, hay kiểm kê khai rõ lô.
type LoNhap struct {
	LotNumber  string
	ExpireDate *time.Time
	UnitCost   float64
}

// LoNhapSo là một lô kèm số lượng. Một phiếu mua có thể nhập cùng một mặt hàng
// làm nhiều lô, nên lượt ghi kho của biến thể đó mang theo cả danh sách.
type LoNhapSo struct {
	LoNhap
	Quantity int
}

// CachXuatKho — thứ tự rút lô, đọc từ cấu hình cửa hàng.
type CachXuatKho string

const (
	XuatFIFO CachXuatKho = "fifo"
	XuatFEFO CachXuatKho = "fefo"
)

// LuatXuatKho gói hai thông số điều khiển lượt rút lô. Đọc một lần ở tầng
// service rồi truyền xuống, thay vì mỗi lượt rút lại hỏi lại cấu hình.
type LuatXuatKho struct {
	Cach CachXuatKho
	// ChanHetHan = true: lô đã quá hạn dùng KHÔNG được rút để bán.
	ChanHetHan bool
}

// LuatXuatKhoMacDinh là hành vi trước khi có tính năng lô: rút theo thứ tự vào
// kho, không ai soi hạn dùng. Dùng cho những đường ghi kho không đọc cấu hình.
func LuatXuatKhoMacDinh() LuatXuatKho {
	return LuatXuatKho{Cach: XuatFIFO, ChanHetHan: false}
}
