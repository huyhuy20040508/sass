package domain

import (
	"context"
	"time"
)

// Loại phương thức thanh toán — giữ đúng mã số của ordertable v2 (sys_payment_methods.type)
// để đối chiếu dữ liệu hai bên không phải dịch.
const (
	PTTienMat     uint8 = 1
	PTChuyenKhoan uint8 = 4
	PTQuetThe     uint8 = 5
	PTQRTuDong    uint8 = 6
)

// PaymentMethodCard — đơn quầy thu bằng quẹt thẻ (migration 0081).
const PaymentMethodCard = "card"

// PaymentMethodMposQR — đơn quầy thu qua mã QR của NextPay mPOS (migration 0083).
const PaymentMethodMposQR = "mpos_qr"

// CacLoaiPT là 4 phương thức mỗi chi nhánh luôn có, theo thứ tự hiển thị.
var CacLoaiPT = []uint8{PTTienMat, PTChuyenKhoan, PTQuetThe, PTQRTuDong}

// Loại chi tiết hợp lệ theo từng phương thức.
var LoaiChiTietPT = map[uint8][]string{
	PTChuyenKhoan: {"bank_transfer"},
	PTQuetThe:     {"mpos_device", "physical_device"},
	PTQRTuDong:    {"sepay", "payos", "mpos_qr", "momo"},
}

// PaymentMethod — một phương thức của một chi nhánh.
type PaymentMethod struct {
	ID uint `json:"id" gorm:"primaryKey"`
	TenantOwned
	ShopID    uint      `json:"shop_id"`
	Type      uint8     `json:"type"`
	Status    bool      `json:"status"`
	IsDefault bool      `json:"is_default"`
	CreatedAt time.Time `json:"created_at"`
	UpdatedAt time.Time `json:"updated_at"`

	// Details KHÔNG phải cột: repository nạp kèm cho màn cấu hình.
	Details []PaymentMethodDetail `json:"details" gorm:"-"`
}

func (PaymentMethod) TableName() string { return "payment_methods" }

// PaymentMethodDetail — tài khoản ngân hàng, cấu hình máy quẹt thẻ hoặc cổng QR.
//
// Data giữ các ô riêng của từng loại dưới dạng chuỗi JSON; service giải ra map.
// Khoá bí mật trong đó đã mã hoá — xem PaymentMethodService.
type PaymentMethodDetail struct {
	ID uint `json:"id" gorm:"primaryKey"`
	TenantOwned
	ShopID          uint   `json:"shop_id"`
	PaymentMethodID uint   `json:"payment_method_id"`
	DetailType      string `json:"detail_type"`
	Status          bool   `json:"status"`
	IsDefault       bool   `json:"is_default"`
	DeviceID        *uint  `json:"device_id"`
	// WebhookCode: mã ngẫu nhiên trong URL webhook riêng của cổng QR (migration 0078).
	WebhookCode *string   `json:"-"`
	Data        *string   `json:"-" gorm:"column:data"`
	CreatedAt   time.Time `json:"created_at"`
	UpdatedAt   time.Time `json:"updated_at"`
}

func (PaymentMethodDetail) TableName() string { return "payment_method_details" }

// PaymentDevice — máy quẹt thẻ của chi nhánh (máy mPOS hoặc máy POS ngân hàng).
type PaymentDevice struct {
	ID uint `json:"id" gorm:"primaryKey"`
	TenantOwned
	ShopID      uint         `json:"shop_id"`
	Code        string       `json:"code"`
	Name        string       `json:"name"`
	Description StringOrNull `json:"description"`
	IsPhysical  bool         `json:"is_physical"`
	CreatedAt   time.Time    `json:"created_at"`
	UpdatedAt   time.Time    `json:"updated_at"`
}

func (PaymentDevice) TableName() string { return "payment_devices" }

// PaymentMethodRepository — sổ phương thức thanh toán của từng chi nhánh.
type PaymentMethodRepository interface {
	// ChiNhanhGhi / ChiNhanhTrungTam trả chi nhánh cho lượt ghi của quản trị và
	// cho website — xem chú thích bên repository.
	ChiNhanhGhi(ctx context.Context) (uint, error)
	ChiNhanhTrungTam(ctx context.Context) (uint, error)

	PhuongThuc(ctx context.Context, shopID uint) ([]PaymentMethod, error)
	ChiTiet(ctx context.Context, shopID uint) ([]PaymentMethodDetail, error)
	MayQuetThe(ctx context.Context, shopID uint) ([]PaymentDevice, error)

	// TaoSan dựng 4 phương thức cho chi nhánh chưa có; tao=true nghĩa là chính
	// lượt này vừa dựng (lượt chạy song song thì nhận false).
	TaoSan(ctx context.Context, shopID uint, ds []PaymentMethod, chiTiet func(map[uint8]uint) []PaymentMethodDetail) (tao bool, err error)

	// Ghi chạy fn trong một giao dịch có khoá các dòng phương thức của chi nhánh,
	// để hai lượt bấm cùng lúc không phá luật "đúng một mặc định".
	Ghi(ctx context.Context, shopID uint, fn func(tx PaymentMethodTx) error) error

	TaoMay(ctx context.Context, d *PaymentDevice) error

	// TheoMaWebhook tìm cổng theo mã trong URL webhook — chạy KHÔNG lọc tenant vì
	// chính dòng này cho biết tiền thuộc cửa hàng nào. Không thấy: ErrNotFound.
	TheoMaWebhook(ctx context.Context, ma string) (*PaymentMethodDetail, error)
	// GhiLanBaoTien ghi lúc cổng gọi webhook gần nhất, để màn cấu hình nói được
	// "đã kết nối" hay chưa.
	GhiLanBaoTien(ctx context.Context, id uint, luc time.Time) error
	// GanMaWebhook gán mã cho cổng chưa có (dòng tạo trước migration 0078).
	GanMaWebhook(ctx context.Context, id uint, ma string) error
	// GhiDonDung ghi đơn quầy đã thu bằng chi tiết nào + phí thẻ (migration 0080).
	GhiDonDung(ctx context.Context, orderID uint, detailID *uint, phi float64) error
	// SoDonDung đếm đơn đã thu bằng một chi tiết — có đơn thì không cho xoá.
	SoDonDung(ctx context.Context, detailID uint) (int64, error)
}

// PaymentMethodTx — các lượt ghi bên trong PaymentMethodRepository.Ghi.
type PaymentMethodTx interface {
	PhuongThuc() ([]PaymentMethod, error)
	ChiTiet() ([]PaymentMethodDetail, error)
	May(id uint) (*PaymentDevice, error)
	LuuPhuongThuc(m *PaymentMethod) error
	LuuChiTiet(d *PaymentMethodDetail) error
	XoaChiTiet(id uint) error
	DanhSachMay() ([]PaymentDevice, error)
	LuuMay(d *PaymentDevice) error
	XoaMay(id uint) error
}

// NganHangVN — ngân hàng nhận được VietQR, theo mã BIN (napas).
//
// Chép từ getBankBinMap() của ordertable v2, xếp ngân hàng phổ biến lên đầu cho
// ô chọn. Tên ngắn là thứ in ra cho khách và đưa vào dịch vụ dựng ảnh QR.
var NganHangVN = []NganHang{
	{"970436", "Vietcombank"}, {"970415", "VietinBank"}, {"970418", "BIDV"}, {"970405", "Agribank"},
	{"970407", "Techcombank"}, {"970422", "MBBank"}, {"970416", "ACB"}, {"970432", "VPBank"},
	{"970423", "TPBank"}, {"970403", "Sacombank"}, {"970437", "HDBank"}, {"970441", "VIB"},
	{"970443", "SHB"}, {"970431", "Eximbank"}, {"970426", "MSB"}, {"970448", "OCB"},
	{"970440", "SeABank"}, {"970449", "LPBank"}, {"970428", "NamABank"}, {"970425", "ABBANK"},
	{"970409", "BacABank"}, {"970412", "PVcomBank"}, {"970427", "VietABank"}, {"970400", "SaigonBank"},
	{"970438", "BaoVietBank"}, {"970454", "VietCapitalBank"}, {"970452", "KienLongBank"}, {"970419", "NCB"},
	{"970433", "VietBank"}, {"970430", "PGBank"}, {"970429", "SCB"}, {"970408", "GPBank"},
	{"970444", "CBBank"}, {"970446", "COOPBANK"}, {"970414", "MBV"}, {"970406", "Vikki"},
	{"970421", "VRB"}, {"970434", "IndovinaBank"}, {"970457", "Woori"}, {"970424", "ShinhanBank"},
	{"458761", "HSBC"}, {"970410", "StandardChartered"}, {"970439", "PublicBank"}, {"970442", "HongLeong"},
	{"970458", "UnitedOverseas"}, {"422589", "CIMB"}, {"533948", "Citibank"}, {"796500", "DBSBank"},
	{"668888", "KBank"}, {"970462", "KookminHN"}, {"970463", "KookminHCM"}, {"970466", "KEBHanaHCM"},
	{"970467", "KEBHANAHN"}, {"970455", "IBKHN"}, {"970456", "IBKHCM"}, {"801011", "Nonghyup"},
	{"546034", "CAKE"}, {"546035", "Ubank"}, {"963388", "Timo"}, {"971005", "ViettelMoney"},
	{"971011", "VNPTMoney"}, {"977777", "MAFC"}, {"999888", "VBSP"},
}

type NganHang struct {
	BIN string `json:"bin"`
	Ten string `json:"name"`
}

// TenNganHang trả tên ngắn theo mã BIN; mã lạ trả "".
func TenNganHang(bin string) string {
	for _, n := range NganHangVN {
		if n.BIN == bin {
			return n.Ten
		}
	}

	return ""
}

// PhiTheDon — phí quẹt thẻ của một đơn cho hộp chi tiết đơn: phí, máy đã quẹt và
// mã phiếu chi tự sinh (rỗng khi phiếu chưa ghi được).
type PhiTheDon struct {
	CardFee    float64 `json:"card_fee"`
	TenMay     string  `json:"device_name"`
	MaPhieuChi string  `json:"expense_code"`
}
