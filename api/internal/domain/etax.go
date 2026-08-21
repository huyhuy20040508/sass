package domain

import (
	"context"
	"errors"
	"time"
)

// HOÁ ĐƠN ĐIỆN TỬ — kết nối của từng chi nhánh tới cổng của nhà cung cấp.
//
// Hôm nay mới làm M-Invoice. `Provider` để chuỗi chứ không phải cờ vì thêm nhà
// cung cấp thứ hai là thêm một client, không phải sửa lược đồ.

// NhaCungCapMInvoice — mã nhà cung cấp duy nhất đang chạy.
const NhaCungCapMInvoice = "minvoice"

// NhaCungCapETax là danh mục để màn hình vẽ ô chọn và API kiểm giá trị gửi lên.
var NhaCungCapETax = map[string]string{
	NhaCungCapMInvoice: "M-Invoice",
}

// EtaxConnection — tài khoản cổng HĐĐT của MỘT chi nhánh.
//
// Password luôn là chuỗi ĐÃ mã hoá (pkg/bimat) và không bao giờ trả ra JSON:
// màn hình chỉ cần biết đã nối tới tài khoản nào, không cần đọc lại mật khẩu.
// Token cũng ẩn — nó là vé ra vào, lộ ra là phát hành được hoá đơn.
type EtaxConnection struct {
	ID uint `json:"id" gorm:"primaryKey"`
	TenantOwned
	ShopID   uint   `json:"shop_id"`
	Provider string `json:"provider"`
	TaxCode  string `json:"tax_code"`
	Username string `json:"username"`
	Password string `json:"-" gorm:"column:password_enc"`
	MaDVCS   string `json:"ma_dvcs"`

	Token         string     `json:"-"`
	TokenSyncedAt *time.Time `json:"token_synced_at"`

	TemplateSymbol string `json:"template_symbol"`
	AutoRelease    bool   `json:"auto_release"`
	AutoPrint      bool   `json:"auto_print"`
	IsActive       bool   `json:"is_active"`

	CreatedAt time.Time `json:"created_at"`
	UpdatedAt time.Time `json:"updated_at"`

	// Templates KHÔNG phải cột: repository nạp thêm cho màn hình chi tiết.
	Templates []EtaxTemplate `json:"templates" gorm:"-"`
}

func (EtaxConnection) TableName() string { return "etax_connections" }

// EtaxTemplate — một ký hiệu hoá đơn đã đăng ký, chép về từ nhà cung cấp.
type EtaxTemplate struct {
	ID uint `json:"id" gorm:"primaryKey"`
	TenantOwned
	ConnectionID uint   `json:"connection_id"`
	Symbol       string `json:"symbol"`
	FormNo       string `json:"form_no"`
	TypeName     string `json:"type_name"`

	CreatedAt time.Time `json:"created_at"`
	UpdatedAt time.Time `json:"updated_at"`
}

func (EtaxTemplate) TableName() string { return "etax_templates" }

// Trạng thái một hoá đơn đã gửi lên cổng. Ba giá trị, ba hậu quả khác nhau —
// xem migration 0035.
const (
	// HoaDonNhap — đã lưu bên cổng nhưng CHƯA ký: chưa có giá trị pháp lý.
	HoaDonNhap = "draft"
	// HoaDonDaPhatHanh — đã ký, có số hoá đơn thật.
	HoaDonDaPhatHanh = "issued"
	// HoaDonHong — cổng từ chối. Giữ lại để tra `error`.
	HoaDonHong = "failed"
)

// EtaxInvoice — một lượt phát hành hoá đơn cho MỘT đơn hàng.
type EtaxInvoice struct {
	ID uint `json:"id" gorm:"primaryKey"`
	TenantOwned
	ShopID       uint  `json:"shop_id"`
	OrderID      uint  `json:"order_id"`
	ConnectionID *uint `json:"connection_id"`

	Provider string `json:"provider"`
	Symbol   string `json:"symbol"`
	Status   string `json:"status"`

	InvoiceNo string `json:"invoice_no"`
	InvoiceID string `json:"invoice_id"`

	TotalAmount float64 `json:"total_amount"`
	VatAmount   float64 `json:"vat_amount"`

	// Payload/Response giữ NGUYÊN VĂN thứ đã gửi và đã nhận. Hoá đơn là chứng từ
	// pháp lý: dựng lại từ đơn hàng hôm nay không trả lời được câu "lúc ấy đã
	// gửi cái gì".
	Payload  string `json:"-"`
	Response string `json:"-"`
	Error    string `json:"error"`

	IssuedAt  *time.Time `json:"issued_at"`
	CreatedAt time.Time  `json:"created_at"`
	UpdatedAt time.Time  `json:"updated_at"`
}

func (EtaxInvoice) TableName() string { return "etax_invoices" }

var (
	// ErrETaxChuaNoi — chi nhánh của đơn chưa nối cổng HĐĐT.
	ErrETaxChuaNoi = errors.New("chi nhánh của đơn này chưa kết nối hoá đơn điện tử")

	// ErrETaxChuaChonKyHieu — đã nối nhưng chưa chọn ký hiệu phát hành. Không
	// đoán bừa một ký hiệu: sai ký hiệu là hoá đơn sai loại, và huỷ nó phải làm
	// biên bản với khách.
	ErrETaxChuaChonKyHieu = errors.New("chưa chọn ký hiệu phát hành cho chi nhánh này")

	// ErrHoaDonDaPhatHanh — đơn này đã có hoá đơn. Một lần bán một hoá đơn.
	ErrHoaDonDaPhatHanh = errors.New("đơn này đã phát hành hoá đơn rồi")

	// ErrDonChuaThuTien — phát hành cho một đơn chưa thu tiền là ghi doanh thu
	// cho một lượt bán chưa xảy ra.
	ErrDonChuaThuTien = errors.New("đơn chưa thanh toán nên chưa phát hành hoá đơn được")

	// ErrETaxChuaCoKhoa — chưa khai ETAX_SECRET_KEY nên không mã hoá được mật
	// khẩu. Từ chối lưu chứ không ghi plaintext.
	ErrETaxChuaCoKhoa = errors.New("máy chủ chưa khai khoá mã hoá (ETAX_SECRET_KEY) nên chưa lưu được mật khẩu cổng hoá đơn điện tử")

	// ErrETaxNhaCungCapLa — mã nhà cung cấp không có trong danh mục.
	ErrETaxNhaCungCapLa = errors.New("nhà cung cấp hoá đơn điện tử này chưa được hỗ trợ")

	// ErrETaxMSTDaDung — mã số thuế này đã nối ở một chi nhánh khác.
	//
	// Chặn vì hai chi nhánh cùng phát hành trên một tài khoản thì số hoá đơn của
	// chúng đan vào nhau, và lúc đối chiếu với cơ quan thuế không tách ra được
	// phiếu nào của nơi nào.
	ErrETaxMSTDaDung = errors.New("mã số thuế này đã kết nối ở một chi nhánh khác")

	// ErrETaxKyHieuLa — ký hiệu chọn để phát hành không nằm trong danh sách mẫu
	// đã kéo về. Nhận bừa thì tới lúc phát hành mới hỏng, và lúc đó khách đang
	// đứng ở quầy chờ hoá đơn.
	ErrETaxKyHieuLa = errors.New("ký hiệu hoá đơn này không có trong danh sách đã đăng ký")
)

// EtaxRepository — sổ kết nối HĐĐT của một cửa hàng.
type EtaxRepository interface {
	// TheoChiNhanh trả kết nối của một chi nhánh, kèm danh sách mẫu. ErrNotFound
	// = chi nhánh đó chưa nối.
	TheoChiNhanh(ctx context.Context, shopID uint) (*EtaxConnection, error)
	// MaSoThueDaDung cho biết mã số thuế đã có chi nhánh KHÁC dùng chưa.
	MaSoThueDaDung(ctx context.Context, taxCode string, trChiNhanh uint) (bool, error)
	Luu(ctx context.Context, cn *EtaxConnection) error
	Xoa(ctx context.Context, id uint) error
	// LuuMau ghi đè danh sách mẫu của một kết nối bằng đúng thứ vừa kéo về.
	LuuMau(ctx context.Context, connectionID uint, ds []EtaxTemplate) error

	// HoaDonTheoDon trả hoá đơn đã phát hành của một đơn. ErrNotFound = chưa có.
	HoaDonTheoDon(ctx context.Context, orderID uint) (*EtaxInvoice, error)
	LuuHoaDon(ctx context.Context, hd *EtaxInvoice) error
	// ThueSuatTheoMatHang tra % thuế của một loạt mặt hàng: khoá là product id,
	// giá trị theo quy ước Product.VAT (số dương = %, -1 KCT, -2 KKKNT).
	ThueSuatTheoMatHang(ctx context.Context, ids []uint) (map[uint]int, error)
}
