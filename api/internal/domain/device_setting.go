package domain

import (
	"context"
	"time"
)

// CÀI ĐẶT THIẾT BỊ (Cài đặt → Thông số chung → Cài đặt thiết bị), theo
// system/parameter/setup-store của ordertable v2: máy in, phiếu nào in ra máy
// nào, bấm nút nào thì in phiếu gì, và mẫu in. Mọi thứ theo TỪNG chi nhánh —
// mỗi quầy một bộ máy, một khổ giấy.
//
// Máy quẹt thẻ cũng nằm ở màn này nhưng dữ liệu là payment_devices của
// Phương thức thanh toán — một bảng, hai lối vào.

// Kiểu kết nối máy in.
const (
	KetNoiLAN       = "lan"
	KetNoiUSB       = "usb"
	KetNoiBluetooth = "bt"
	// KetNoiUrovo — máy POS Urovo in liền thân, không cần địa chỉ.
	KetNoiUrovo = "urovo"
)

// KhoGiayMayIn — khổ giấy khai được cho một máy in.
var KhoGiayMayIn = []string{"58", "80", "a5", "a4"}

// DPIMayIn — hai độ phân giải bản cũ cho chọn.
var DPIMayIn = []int{203, 180}

// Loại phiếu in ra được. Bản cũ còn In Order và Phiếu chuyển bàn — của quán
// ăn; huyshop bán lẻ, không có bếp hay bàn.
const (
	PhieuHoaDon  = "hoa-don"
	PhieuTem     = "tem"
	PhieuKetCa   = "ket-ca"
	PhieuTraHang = "tra-hang"
	PhieuHDDT    = "hddt"
)

// LoaiPhieuIn — một loại phiếu ở bảng "phiếu nào in ra máy nào".
type LoaiPhieuIn struct {
	Ma  string `json:"ma"`
	Ten string `json:"ten"`
	// TheoNhom: in theo nhóm hàng — món thuộc nhóm nào ra máy đó. Chỉ tem có:
	// tem dán từng món, mỗi khu hàng một máy tem.
	TheoNhom bool `json:"theo_nhom"`
}

var DanhMucPhieuIn = []LoaiPhieuIn{
	{Ma: PhieuHoaDon, Ten: "Hoá đơn"},
	{Ma: PhieuTem, Ten: "Tem", TheoNhom: true},
	{Ma: PhieuKetCa, Ten: "Phiếu kết ca"},
	{Ma: PhieuTraHang, Ten: "Phiếu trả hàng"},
	{Ma: PhieuHDDT, Ten: "Hoá đơn điện tử"},
}

func TimPhieuIn(ma string) (LoaiPhieuIn, bool) {
	for _, p := range DanhMucPhieuIn {
		if p.Ma == ma {
			return p, true
		}
	}

	return LoaiPhieuIn{}, false
}

// NutBamIn — một nút ở màn Thu ngân bấm vào thì in.
type NutBamIn struct {
	Ma   string `json:"ma"`
	Ten  string `json:"ten"`
	Phim string `json:"phim"`
	// ChoPhep — phiếu nút này in được. Hoá đơn điện tử chỉ có ở Thanh toán:
	// lúc tạm tính chưa có hoá đơn nào để in.
	ChoPhep []string `json:"cho_phep"`
	// MacDinh — phiếu in khi chi nhánh chưa cấu hình gì.
	MacDinh []string `json:"mac_dinh"`
}

var DanhMucNutIn = []NutBamIn{
	{Ma: "thanh-toan", Ten: "Thanh toán", Phim: "F9", ChoPhep: []string{PhieuHoaDon, PhieuTem, PhieuHDDT}, MacDinh: []string{PhieuHoaDon}},
	{Ma: "tam-tinh", Ten: "In tạm tính", ChoPhep: []string{PhieuHoaDon}, MacDinh: []string{PhieuHoaDon}},
}

func TimNutIn(ma string) (NutBamIn, bool) {
	for _, n := range DanhMucNutIn {
		if n.Ma == ma {
			return n, true
		}
	}

	return NutBamIn{}, false
}

// MayIn — một máy in của chi nhánh.
type MayIn struct {
	ID uint `json:"id" gorm:"primaryKey"`
	TenantOwned
	ShopID   uint   `json:"shop_id"`
	Name     string `json:"name"`
	ConnType string `json:"conn_type"`
	// Address: IP với máy LAN, MAC với máy Bluetooth, rỗng với USB / Urovo.
	Address      string    `json:"address"`
	Paper        string    `json:"paper"`
	DPI          int       `json:"dpi" gorm:"column:dpi"`
	PrintedCount int       `json:"printed_count"`
	IsActive     bool      `json:"is_active"`
	CreatedAt    time.Time `json:"created_at"`
	UpdatedAt    time.Time `json:"updated_at"`
}

func (MayIn) TableName() string { return "printers" }

// GanPhieuIn — một dòng "loại phiếu này in ra máy này".
type GanPhieuIn struct {
	ID uint `json:"id" gorm:"primaryKey"`
	TenantOwned
	ShopID    uint   `json:"shop_id"`
	PrinterID uint   `json:"printer_id"`
	SlipType  string `json:"slip_type"`
	// Chỉ dùng với phiếu theo nhóm (tem). AllCategories = mọi nhóm, kể cả nhóm
	// thêm sau này — bản cũ bung "Tất cả" ra danh sách id nên nhóm mới không có
	// máy nào in.
	AllCategories bool      `json:"all_categories"`
	CategoryIDs   []uint    `json:"category_ids" gorm:"column:category_ids;serializer:json"`
	IsActive      bool      `json:"is_active"`
	CreatedAt     time.Time `json:"created_at"`
	UpdatedAt     time.Time `json:"updated_at"`
}

func (GanPhieuIn) TableName() string { return "printer_routes" }

// NutIn — cấu hình đã lưu của một nút. Chưa có dòng = dùng NutBamIn.MacDinh.
type NutIn struct {
	ID uint `json:"id" gorm:"primaryKey"`
	TenantOwned
	ShopID    uint      `json:"shop_id"`
	Button    string    `json:"button"`
	Slips     []string  `json:"slips" gorm:"serializer:json"`
	IsActive  bool      `json:"is_active"`
	CreatedAt time.Time `json:"created_at"`
	UpdatedAt time.Time `json:"updated_at"`
}

func (NutIn) TableName() string { return "print_buttons" }

// CaiDatMau — các ô không phải bật/tắt của một mẫu in. Ô nào không áp cho
// loại mẫu đó thì để trống (xem MauIn.CaiDat).
type CaiDatMau struct {
	// Kho: "58" | "80" — mẫu khổ in nhiệt.
	Kho string `json:"kho,omitempty"`
	// Ban: số bản in mỗi lần bấm, 1–99.
	Ban int `json:"ban,omitempty"`
	// FooterTxt: lời chào / chân trang khi dòng chân trang đang bật.
	FooterTxt string `json:"footer_txt,omitempty"`
	// WifiTxt, ChinhSachTxt: chữ gõ tự do của hai dòng "Wifi" và "Chính sách
	// đổi trả" — chỉ mẫu nào có dòng mang ô đó mới nhận (xem coO).
	WifiTxt      string `json:"wifi_txt,omitempty"`
	ChinhSachTxt string `json:"chinhsach_txt,omitempty"`
	// Ba ô riêng của tem.
	KhoTem string `json:"kho_tem,omitempty"`
	Cot    int    `json:"cot,omitempty"`
	Co     int    `json:"co,omitempty"`
}

// TuyChonMau — một dòng bật/tắt trên phiếu.
type TuyChonMau struct {
	Ma      string `json:"ma"`
	Ten     string `json:"ten"`
	MacDinh bool   `json:"mac_dinh"`
	// Con: dòng chỉ có nghĩa khi dòng cha đang bật (ẩn đi khi cha tắt).
	Con string `json:"con,omitempty"`
	// O: ô nhập đi kèm (khoá trong CaiDatMau), vd chân trang.
	O string `json:"o,omitempty"`
	// Goi: một câu nhắc dưới ô nhập.
	Goi string `json:"goi,omitempty"`
}

type NhomTuyChon struct {
	Ten  string       `json:"ten"`
	Dong []TuyChonMau `json:"dong"`
}

// MauIn — một loại mẫu in và bộ dòng của nó. Là nguồn sự thật duy nhất: màn
// cấu hình vẽ theo đây, API kiểm theo đây, phiếu in đọc mặc định ở đây.
type MauIn struct {
	Ma     string        `json:"ma"`
	Ten    string        `json:"ten"`
	Ngan   string        `json:"ngan"`
	Nhom   []NhomTuyChon `json:"nhom"`
	CaiDat CaiDatMau     `json:"cai_dat"`
	// FooterToiDa — trần ký tự của chân trang (tem nhỏ chỉ lọt 50).
	FooterToiDa int `json:"footer_toi_da"`
}

// KhoTemDuoc — khổ tem (rộng x cao, mm) bày sẵn để chọn.
var KhoTemDuoc = []string{"30x20", "30x40", "35x22", "40x25", "40x30", "50x30", "50x40", "50x70"}

func dong(ma, ten string) TuyChonMau         { return TuyChonMau{Ma: ma, Ten: ten, MacDinh: true} }
func dongTat(ma, ten string) TuyChonMau      { return TuyChonMau{Ma: ma, Ten: ten} }
func dongCon(ma, ten, cha string) TuyChonMau { return TuyChonMau{Ma: ma, Ten: ten, Con: cha} }

var nhomHoaDonA = []NhomTuyChon{
	{Ten: "Phần đầu", Dong: []TuyChonMau{dong("logo", "Logo cửa hàng"), dong("ten", "Tên cửa hàng"), dong("diachi", "Địa chỉ cửa hàng"), dong("sdt", "Số điện thoại cửa hàng"), dong("sohd", "Số hoá đơn"), dong("tg", "Thời gian thanh toán"), dong("khach", "Khách hàng (tên, hạng thẻ, SĐT)")}},
	{Ten: "Nội dung", Dong: []TuyChonMau{dong("dvt", "Đơn vị tính"), dong("bienthe", "Biến thể"), dong("chitietgia", "Chi tiết giá (bớt từng món)"), dong("qua", "Quà tặng"), dong("donvitien", "Đơn vị tiền (đ)"), dong("tienthua", "Tiền khách đưa và tiền thối"), dong("tietkiem", "“Bạn đã tiết kiệm …” (tổng mọi khoản giảm)"), dong("ghichu", "Ghi chú đơn"), {Ma: "qr", Ten: "QR chuyển khoản (chỉ đơn trả bằng chuyển khoản)"}}},
	{Ten: "Chữ ký", Dong: []TuyChonMau{dongTat("ckXuatKho", "Người xuất kho"), dongTat("ckGiaoHang", "Người giao hàng"), dong("ckCuaHang", "Cửa hàng"), dong("ckKhach", "Khách hàng")}},
	{Ten: "Chân phiếu", Dong: []TuyChonMau{
		{Ma: "chinhsach", Ten: "Chính sách đổi trả", O: "chinhsach_txt", Goi: "Vd: Đổi hàng trong 7 ngày, kèm hoá đơn."},
		{Ma: "footer", Ten: "Dòng chân trang", MacDinh: true, O: "footer_txt"},
		dong("mavach", "Mã vạch mã đơn"),
		dongTat("sao", "Dòng “**********” cuối phiếu"),
	}},
}

var DanhMucMauIn = []MauIn{
	{Ma: "hd", Ten: "Hoá đơn 58 / 80mm", Ngan: "Hoá đơn", FooterToiDa: 120, CaiDat: CaiDatMau{Kho: "80", Ban: 1, FooterTxt: "Cảm ơn quý khách!"}, Nhom: []NhomTuyChon{
		{Ten: "Phần đầu", Dong: []TuyChonMau{dong("logo", "Logo cửa hàng"), dong("ten", "Tên cửa hàng"), dong("diachi", "Địa chỉ cửa hàng"), dong("sdt", "Số điện thoại cửa hàng"), dong("sohd", "Số hoá đơn"), dong("tg", "Thời gian thanh toán"), dong("khach", "Khách hàng (tên, hạng thẻ, SĐT)"), dong("thungan", "Nhân viên thu ngân")}},
		{Ten: "Nội dung", Dong: []TuyChonMau{dong("dvt", "Đơn vị tính"), dong("bienthe", "Biến thể (màu, dung lượng…)"), dong("chitietgia", "Chi tiết giá — dòng bớt dưới từng món"), dong("qua", "Quà tặng"), dong("vat", "Thuế VAT"), dong("tienthua", "Tiền khách đưa và tiền thối"), dong("tietkiem", "“Bạn đã tiết kiệm …” (tổng mọi khoản giảm)"), dong("diem", "Điểm tích được"), dong("diemconlai", "Hạng thẻ và điểm còn lại của khách"), dong("ghichu", "Ghi chú đơn"), {Ma: "qr", Ten: "QR chuyển khoản (chỉ đơn trả bằng chuyển khoản)"}}},
		{Ten: "Chân phiếu", Dong: []TuyChonMau{
			{Ma: "wifi", Ten: "Wifi cho khách", O: "wifi_txt", Goi: "Vd: Selliotech · MK 12345678"},
			{Ma: "chinhsach", Ten: "Chính sách đổi trả", O: "chinhsach_txt", Goi: "Vd: Đổi hàng trong 7 ngày, kèm hoá đơn và tem nguyên vẹn."},
			{Ma: "footer", Ten: "Lời chào cuối phiếu", MacDinh: true, O: "footer_txt"},
			dong("mavach", "Mã vạch mã đơn — quét để tìm đơn khi đổi trả"),
			dongTat("sao", "Dòng “**********” cuối phiếu"),
		}},
	}},
	{Ma: "a5", Ten: "Hoá đơn A5", Ngan: "A5", FooterToiDa: 120, CaiDat: CaiDatMau{Ban: 1}, Nhom: nhomHoaDonA},
	{Ma: "a4", Ten: "Hoá đơn A4", Ngan: "A4", FooterToiDa: 120, CaiDat: CaiDatMau{Ban: 1}, Nhom: nhomHoaDonA},
	{Ma: "tem", Ten: "Tem", Ngan: "Tem", FooterToiDa: 50, CaiDat: CaiDatMau{KhoTem: "40x30", Cot: 1, Ban: 1, Co: 9}, Nhom: []NhomTuyChon{
		{Ten: "Trên tem", Dong: []TuyChonMau{dong("ten", "Tên cửa hàng"), dongTat("sdt", "Số điện thoại cửa hàng"), dong("tg", "Ngày giờ in"), dong("mahd", "Mã đơn"), dong("bienthe", "Biến thể"), dongTat("dvt", "Đơn vị tính"), dong("sl", "Số thứ tự tem (1/3, 2/3…)"), dong("gia", "Giá bán"), dong("donvitien", "Đơn vị tiền (đ)"), dong("ghichu", "Ghi chú món"),
			{Ma: "footer", Ten: "Chân tem", MacDinh: true, O: "footer_txt", Goi: "Tối đa 50 ký tự. Chỉ hiện trên tem từ 50×70mm."}}},
		{Ten: "Khi in", Dong: []TuyChonMau{dongTat("nguoc", "In ngược (xoay 180°) — cho máy in tem nhả giấy ngược")}},
	}},
	{Ma: "tra", Ten: "Phiếu trả hàng", Ngan: "Trả hàng", CaiDat: CaiDatMau{Kho: "80", Ban: 1}, Nhom: []NhomTuyChon{
		{Ten: "Phần đầu", Dong: []TuyChonMau{dong("ten", "Tên cửa hàng"), dong("diachi", "Địa chỉ cửa hàng")}},
		{Ten: "Đơn gốc", Dong: []TuyChonMau{dong("sohd", "Số hoá đơn gốc"), dong("tg", "Thời gian thanh toán"), dong("thungan", "Thu ngân"), dong("khach", "Khách hàng"), dong("pttt", "Hình thức thanh toán")}},
		{Ten: "Chi tiết trả", Dong: []TuyChonMau{dong("maphieu", "Mã phiếu trả"), dong("tgtra", "Thời gian trả"), dong("khachtra", "Người trả"), dongTat("sdttra", "Số điện thoại người trả"), dong("dvt", "Đơn vị tính"), dong("bienthe", "Biến thể")}},
		{Ten: "Chân phiếu", Dong: []TuyChonMau{dong("tienhoan", "Số tiền hoàn lại"), dong("pthoan", "Hình thức hoàn"), dong("lydo", "Lý do trả hàng"), dongTat("noidung", "Nội dung / ghi chú"), dong("kyCuaHang", "Cửa hàng xác nhận và ký tên"), dong("kyKhach", "Khách hàng xác nhận và ký tên")}},
	}},
	{Ma: "ca", Ten: "Phiếu kết ca", Ngan: "Kết ca", CaiDat: CaiDatMau{Kho: "80", Ban: 1}, Nhom: []NhomTuyChon{
		{Ten: "Cửa hàng", Dong: []TuyChonMau{dong("ten", "Tên cửa hàng"), dong("diachi", "Địa chỉ cửa hàng"), dong("sdt", "Số điện thoại cửa hàng")}},
		{Ten: "Ca", Dong: []TuyChonMau{dong("calv", "Ca làm việc"), dong("nvdong", "Nhân viên đóng ca"), dong("tgdong", "Thời gian đóng ca")}},
		{Ten: "Doanh thu", Dong: []TuyChonMau{dong("tongdt", "Tổng doanh thu"), dong("dtTienMat", "Doanh thu tiền mặt"), dong("dtCK", "Doanh thu chuyển khoản"), dong("dtThe", "Doanh thu quẹt thẻ"), dongTat("congno", "Bán nợ trong ca"), dong("khachTraNo", "Khách trả nợ trong ca"), dong("phieuthu", "Phiếu thu"), dong("phieuchi", "Phiếu chi")}},
		{Ten: "Hoá đơn & hàng", Dong: []TuyChonMau{dong("sohd", "Số hoá đơn"), dong("sohdhuy", "Số hoá đơn huỷ"), dong("sosp", "Số sản phẩm bán trong ca"), dongCon("tachTang", "Tách riêng hàng tặng", "sosp")}},
		{Ten: "Két tiền", Dong: []TuyChonMau{dong("dauca", "Tiền mặt đầu ca"), dong("cuoica", "Tiền mặt cuối ca"), dong("ghichu", "Ghi chú đóng ca"), dong("xacnhan", "Chữ ký xác nhận")}},
	}},
}

// CoO — mẫu có dòng nào mang ô nhập o không. Ô chữ tự do (wifi, chính sách)
// mặc định rỗng nên không suy được từ CaiDat như kho/ban.
func (m MauIn) CoO(o string) bool {
	for _, n := range m.Nhom {
		for _, d := range n.Dong {
			if d.O == o {
				return true
			}
		}
	}

	return false
}

func TimMauIn(ma string) (MauIn, bool) {
	for _, m := range DanhMucMauIn {
		if m.Ma == ma {
			return m, true
		}
	}

	return MauIn{}, false
}

// MacDinhDong — bộ bật/tắt mặc định của một mẫu.
func (m MauIn) MacDinhDong() map[string]bool {
	out := map[string]bool{}
	for _, n := range m.Nhom {
		for _, d := range n.Dong {
			out[d.Ma] = d.MacDinh
		}
	}

	return out
}

// MauInLuu — bản đã chỉnh của một mẫu ở một chi nhánh. Không có dòng = mặc định.
type MauInLuu struct {
	ID uint `json:"id" gorm:"primaryKey"`
	TenantOwned
	ShopID    uint            `json:"shop_id"`
	Kind      string          `json:"kind"`
	Options   map[string]bool `json:"options" gorm:"serializer:json"`
	Settings  CaiDatMau       `json:"settings" gorm:"serializer:json"`
	CreatedAt time.Time       `json:"created_at"`
	UpdatedAt time.Time       `json:"updated_at"`
}

func (MauInLuu) TableName() string { return "print_templates" }

// ThietBiRepository — sổ thiết bị in của chi nhánh.
type ThietBiRepository interface {
	// ChiNhanhGhi: chi nhánh của lượt ghi (header X-Chi-Nhanh, hoặc chi nhánh
	// duy nhất). ChiNhanhDoc: bản mềm cho đường đọc của quầy — 0 nếu mơ hồ.
	ChiNhanhGhi(ctx context.Context) (uint, error)
	ChiNhanhDoc(ctx context.Context) uint

	MayIn(ctx context.Context, shopID uint) ([]MayIn, error)
	MotMayIn(ctx context.Context, shopID, id uint) (*MayIn, error)
	LuuMayIn(ctx context.Context, m *MayIn) error
	XoaMayIn(ctx context.Context, shopID, id uint) error

	GanPhieu(ctx context.Context, shopID uint) ([]GanPhieuIn, error)
	MotGanPhieu(ctx context.Context, shopID, id uint) (*GanPhieuIn, error)
	LuuGanPhieu(ctx context.Context, g *GanPhieuIn) error
	XoaGanPhieu(ctx context.Context, shopID, id uint) error

	NutIn(ctx context.Context, shopID uint) ([]NutIn, error)
	LuuNutIn(ctx context.Context, n *NutIn) error

	MauIn(ctx context.Context, shopID uint) ([]MauInLuu, error)
	LuuMauIn(ctx context.Context, m *MauInLuu) error
	XoaMauIn(ctx context.Context, shopID uint, kind string) error

	// NhomHangGoc — nhóm hàng cấp 1 đang dùng, để chọn "tem nhóm nào ra máy nào".
	NhomHangGoc(ctx context.Context) ([]Category, error)
}
