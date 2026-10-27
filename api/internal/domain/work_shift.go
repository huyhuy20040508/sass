package domain

import (
	"context"
	"database/sql/driver"
	"encoding/json"
	"errors"
	"fmt"
	"time"
)

// ---------- Ca làm việc & sổ quỹ ----------
//
// Hai thứ này chỉ có nghĩa khi đi cùng nhau. Sổ quỹ ghi từng lần tiền mặt vào
// hoặc ra khỏi két; ca làm việc là khoảng thời gian có MỘT người chịu trách
// nhiệm về cái két đó. Thiếu ca thì "két thiếu 200 nghìn" là câu không ai trả
// lời được — không biết thiếu từ lúc nào, trong lượt trực của ai.
//
// CHỈ TIỀN MẶT. Chuyển khoản không đi qua két nên không vào sổ này; trộn vào là
// con số đối chiếu cuối ca không còn khớp với tiền đếm được, tức là làm hỏng
// đúng thứ cả cụm này sinh ra để phục vụ.

// Trạng thái của một ca.
const (
	CaDangMo = "dang_mo"
	CaDaDong = "da_dong"
)

// Chiều của một dòng sổ quỹ.
const (
	// SoQuyThu — tiền VÀO két (bán hàng thu tiền mặt, khách trả nợ).
	SoQuyThu = "in"
	// SoQuyChi — tiền RA khỏi két (hoàn tiền trả hàng, mua vặt, chủ rút tiền).
	SoQuyChi = "out"
)

// Nguồn gốc một dòng sổ quỹ (cột reference_type).
const (
	SoQuyTuDonHang = "order"
	SoQuyTuTraHang = "order_return"
	SoQuyGhiTay    = "manual"
	// SoQuyTuThuChi — dòng DẪN XUẤT từ một phiếu thu/chi tiền mặt. Két của ca
	// cộng từ bảng này (xem tongKetCa), nên phiếu chi tiền mặt mà không có dòng
	// ở đây là lúc đóng ca đếm thiếu đúng bằng số ấy mà không ai biết vì sao.
	SoQuyTuThuChi = "income_expense"
)

// CaLamViec — một lượt trực két tại một chi nhánh.
type CaLamViec struct {
	ID uint `json:"id" gorm:"primaryKey"`
	TenantOwned
	// ShopID là chi nhánh của ca. Mỗi chi nhánh chỉ được có MỘT ca đang mở —
	// ràng buộc nằm ở database (uq_work_shifts_open), không phải ở tầng code:
	// hai người bấm "mở ca" cùng lúc thì chỉ khoá của database mới chặn được.
	ShopID uint `json:"shop_id"`

	OpenedBy uint `json:"opened_by"`
	// EmployeeID — nhân sự trực két (employees.id), có thể khác người bấm Mở ca.
	// nil = ca cũ hoặc người mở chưa có hồ sơ nhân sự.
	EmployeeID  *uint     `json:"employee_id"`
	OpenedAt    time.Time `json:"opened_at"`
	OpeningCash float64   `json:"opening_cash"`
	// OpeningCount — bảng đếm theo mệnh giá lúc mở (migration 0092); rỗng = gõ thẳng tổng.
	OpeningCount BangDem `json:"opening_count" gorm:"type:json"`

	ClosedBy *uint      `json:"closed_by"`
	ClosedAt *time.Time `json:"closed_at"`
	// CountedCash là tiền ĐẾM ĐƯỢC lúc đóng ca, ExpectedCash là tiền SỔ nói lẽ ra
	// phải có, Difference là chênh lệch (âm = thiếu két).
	//
	// Lưu cả ba chứ không tính lại khi đọc: ExpectedCash chốt theo sổ tại đúng
	// thời điểm đóng ca, mà sổ có thể được ghi thêm sau đó (một khoản chi quên
	// ghi). Con số hai bên đã ký nhận hôm ấy phải giữ nguyên.
	CountedCash *float64 `json:"counted_cash"`
	// CountedCount — bảng đếm theo mệnh giá lúc đóng; tổng luôn bằng CountedCash.
	CountedCount BangDem `json:"counted_count" gorm:"type:json"`
	// DeliveredCash + KeptCash = CountedCash (migration 0090); nil = ca đóng trước đó.
	DeliveredCash *float64 `json:"delivered_cash"`
	KeptCash      *float64 `json:"kept_cash"`
	ExpectedCash  *float64 `json:"expected_cash"`
	Difference    *float64 `json:"difference"`

	// Note là ghi chú lúc MỞ ca, CloseNote là ghi chú lúc ĐÓNG ca — hai cột
	// riêng từ migration 0073, trước đó đóng ca ghi đè lên Note.
	Note      string    `json:"note"`
	CloseNote string    `json:"close_note"`
	CreatedAt time.Time `json:"created_at"`
	UpdatedAt time.Time `json:"updated_at"`

	// Các trường dưới đây KHÔNG phải cột — tầng service điền thêm khi trả về màn
	// hình, để một lượt gọi là đủ dựng cả trang.
	ShopName     string  `json:"shop_name,omitempty" gorm:"-"`
	OpenedName   string  `json:"opened_by_name,omitempty" gorm:"-"`
	EmployeeName string  `json:"employee_name,omitempty" gorm:"-"`
	ClosedName   string  `json:"closed_by_name,omitempty" gorm:"-"`
	TongThu      float64 `json:"tong_thu" gorm:"-"`
	TongChi      float64 `json:"tong_chi" gorm:"-"`
	// DaNopVe — tiền đã nộp về công ty trong ca (đã nằm trong TongChi).
	DaNopVe      float64 `json:"da_nop_ve" gorm:"-"`
	SoDonTienMat int64   `json:"so_don_tien_mat" gorm:"-"`
}

func (CaLamViec) TableName() string { return "work_shifts" }

// DangMo cho biết ca còn đang trực hay đã chốt.
func (c CaLamViec) DangMo() bool { return c.ClosedAt == nil }

// TrangThai trả về trạng thái dạng chuỗi cho giao diện.
func (c CaLamViec) TrangThai() string {
	if c.DangMo() {
		return CaDangMo
	}
	return CaDaDong
}

// MenhGiaDem — các mệnh giá của hộp "Đếm theo mệnh giá", lớn → nhỏ.
var MenhGiaDem = []int64{500000, 200000, 100000, 50000, 20000, 10000, 5000, 2000, 1000}

// DemTo — một dòng bảng đếm: mệnh giá × số tờ.
type DemTo struct {
	MenhGia int64 `json:"menh_gia"`
	SoTo    int   `json:"so_to"`
}

// BangDem — bảng đếm két theo mệnh giá, lưu một cột JSON (mảng để giữ thứ tự).
type BangDem []DemTo

// Tong là số tiền bảng đếm cộng ra.
func (b BangDem) Tong() float64 {
	var t int64
	for _, d := range b {
		t += d.MenhGia * int64(d.SoTo)
	}
	return float64(t)
}

func (b BangDem) Value() (driver.Value, error) {
	if len(b) == 0 {
		return nil, nil
	}
	v, err := json.Marshal([]DemTo(b))
	return string(v), err
}

func (b *BangDem) Scan(v any) error {
	switch x := v.(type) {
	case nil:
		*b = nil
		return nil
	case []byte:
		return json.Unmarshal(x, (*[]DemTo)(b))
	case string:
		return json.Unmarshal([]byte(x), (*[]DemTo)(b))
	}
	return fmt.Errorf("bảng đếm: kiểu %T không đọc được", v)
}

// SoQuy — MỘT lần tiền mặt vào hoặc ra khỏi két.
type SoQuy struct {
	ID uint `json:"id" gorm:"primaryKey"`
	TenantOwned
	ShopID uint `json:"shop_id"`
	// ShiftID nil = phát sinh lúc không có ca nào mở.
	//
	// Quầy nay không thu/chi được khi chưa mở ca (ErrChuaMoCa), nhưng vẫn còn
	// đường ngoài quầy chạm két (hoàn tiền trả hàng ở màn quản trị) và dữ liệu
	// cũ. Dòng "ngoài ca" vẫn vào sổ đầy đủ và màn Điều phối ca chỉ chúng ra.
	ShiftID *uint `json:"shift_id"`
	// AttachedBy / AttachedAt — dòng "ngoài ca" được gắn vào ca SAU (migration 0091).
	// Chỉ ghi lúc gắn (<-:update), không bao giờ lúc tạo dòng.
	AttachedBy *uint      `json:"attached_by" gorm:"<-:update"`
	AttachedAt *time.Time `json:"attached_at" gorm:"<-:update"`

	// Direction: SoQuyThu | SoQuyChi. Amount LUÔN dương — chiều nằm ở cột này,
	// không phải ở dấu của số tiền. Cho phép số âm là mở đường cho hai cách biểu
	// diễn cùng một khoản, và mọi phép cộng về sau phải nhớ cả hai.
	Direction string  `json:"direction"`
	Amount    float64 `json:"amount"`
	Reason    string  `json:"reason"`

	ReferenceType string `json:"reference_type"`
	ReferenceID   *uint  `json:"reference_id"`

	CreatedBy *uint     `json:"created_by"`
	CreatedAt time.Time `json:"created_at"`
	UpdatedAt time.Time `json:"updated_at"`

	CreatedName string `json:"created_by_name,omitempty" gorm:"-"`
	// AttachedName — tên người đã gắn dòng ngoài ca vào ca (khi có).
	AttachedName string `json:"attached_by_name,omitempty" gorm:"-"`
	// ReferenceCode là mã chứng từ gốc (mã đơn, mã phiếu trả, mã phiếu thu chi)
	// để người dò sổ đối chiếu được với tờ giấy trên tay — id thì không ai đọc.
	ReferenceCode string `json:"reference_code,omitempty" gorm:"-"`
}

func (SoQuy) TableName() string { return "cash_entries" }

// TongKetSoQuy — cộng dồn sổ quỹ của một ca.
type TongKetSoQuy struct {
	TongThu float64 `json:"tong_thu"`
	TongChi float64 `json:"tong_chi"`
	// SoDonTienMat là số lượt bán thu tiền mặt trong ca — con số người đóng ca
	// hay đối chiếu với số hoá đơn trên tay.
	SoDonTienMat int64 `json:"so_don_tien_mat"`
}

// DoanhThuCa — đơn quầy thuộc ca và tiền theo hình thức, cùng quy ước với báo
// cáo kết ca (shift_report.go). Không lấy từ sổ quỹ: sổ chỉ có tiền mặt.
type DoanhThuCa struct {
	OrderCount    int64   `json:"order_count"`
	TotalRevenue  float64 `json:"total_revenue"`
	TotalCash     float64 `json:"total_cash"`
	TotalTransfer float64 `json:"total_transfer"`
	TotalCard     float64 `json:"total_card"`
	TotalAutoQR   float64 `json:"total_auto_qr"`
}

// NguoiTrucCa — một người đã từng mở ca ở chi nhánh, cho ô lọc "người trực".
type NguoiTrucCa struct {
	ID   uint   `json:"id"`
	Name string `json:"name"`
}

// CaFilter — tham số lọc danh sách ca.
type CaFilter struct {
	// ShopID = 0 nghĩa là mọi chi nhánh của cửa hàng.
	ShopID   uint
	FromDate string // YYYY-MM-DD, theo closed_at (ca đang mở: từ opened_at)
	ToDate   string
	// Status: "" | dang_mo | da_dong
	Status string
	// OpenedBy > 0 thì chỉ lấy ca do người này mở.
	OpenedBy uint
	// ChiLech = chỉ ca đã đóng mà tiền đếm được khác tiền theo sổ.
	ChiLech  bool
	Page     int
	PageSize int
}

// Lỗi của cụm ca làm việc.
var (
	// Chi nhánh đã có ca đang mở — mở thêm là hai người cùng nhận trách nhiệm về
	// một cái két, và không ai nhận cả.
	ErrCaDangMo = errors.New("chi nhánh này đang có ca mở")
	// Không có ca nào đang mở để đóng.
	ErrKhongCoCa = errors.New("chi nhánh này chưa mở ca nào")
	// Ca đã chốt rồi thì không đóng lại lần nữa: con số đối chiếu đã ký nhận.
	ErrCaDaDong = errors.New("ca này đã đóng rồi")
	// Chi nhánh chưa mở ca mà bán / thu tiền ở quầy. Như v2: chưa ai nhận két thì
	// không có ca nào đối chiếu được khoản tiền ấy — chặn, không ghi "ngoài ca".
	ErrChuaMoCa = errors.New("chi nhánh chưa mở ca")
	// KetAm — khoản chi lớn hơn tiền đang có trong két theo sổ; Con là số còn lại.
	ErrKetAm = errors.New("khoản chi lớn hơn tiền đang có trong két")
)

// LoiKetAm mang số tiền két còn theo sổ để màn ca hỏi lại đúng con số.
// LoiGanNgoaiCa — không gắn được khoản ngoài ca vào ca đang mở; Cau nói vì sao.
type LoiGanNgoaiCa struct{ Cau string }

func (e *LoiGanNgoaiCa) Error() string { return e.Cau }

// LoiDongCa — ô bàn giao của hộp Đóng ca không hợp lệ, theo từng ô.
type LoiDongCa struct{ O map[string]string }

func (e *LoiDongCa) Error() string { return "thông tin đóng ca chưa hợp lệ" }

// LoiMoCa — ô nhập của hộp Mở ca không hợp lệ (người trực, giờ mở), theo từng ô.
type LoiMoCa struct{ O map[string]string }

func (e *LoiMoCa) Error() string { return "thông tin mở ca chưa hợp lệ" }

// NhatKyNhanVien — một dòng nhật ký nhân viên (migration 0089). Chỉ ghi thêm.
// EmployeeID là người TRỰC KÉT của ca, UserID là tài khoản đã bấm nút.
type NhatKyNhanVien struct {
	ID uint `json:"id" gorm:"primaryKey"`
	TenantOwned
	ShopID     *uint     `json:"shop_id"`
	EmployeeID *uint     `json:"employee_id"`
	UserID     *uint     `json:"user_id"`
	Event      string    `json:"event"`
	ShiftID    *uint     `json:"shift_id"`
	Amount     *float64  `json:"amount"`
	Difference *float64  `json:"difference"`
	Note       string    `json:"note"`
	HappenedAt time.Time `json:"happened_at"`
	CreatedAt  time.Time `json:"created_at"`

	ShopName string `json:"shop_name,omitempty" gorm:"-"`
	UserName string `json:"user_name,omitempty" gorm:"-"`
}

func (NhatKyNhanVien) TableName() string { return "employee_logs" }

// Sự kiện nhật ký — cùng tên với hrm_employee_histories của ordertable v2.
const (
	NhatKyMoCa   = "shift_open"
	NhatKyDongCa = "shift_close"
)

// TongNhatKyCa — phần tổng của tab Nhật ký: trực bao nhiêu ca (đã đóng), lệch mấy lần.
type TongNhatKyCa struct {
	SoCa      int64   `json:"so_ca"`
	SoLanLech int64   `json:"so_lan_lech"`
	TongThieu float64 `json:"tong_thieu"`
	TongDu    float64 `json:"tong_du"`
}

// NhanVienTruc — một nhân sự chọn được làm người trực két của chi nhánh.
type NhanVienTruc struct {
	ID     uint   `json:"id"`
	Code   string `json:"code"`
	Name   string `json:"name"`
	UserID *uint  `json:"user_id"`
}

type LoiKetAm struct{ Con float64 }

func (e *LoiKetAm) Error() string { return ErrKetAm.Error() }
func (e *LoiKetAm) Unwrap() error { return ErrKetAm }

// CaLamViecRepository — ca làm việc và sổ quỹ.
type CaLamViecRepository interface {
	// CaDangMo trả ca đang mở của một chi nhánh, nil nếu không có.
	CaDangMoCua(ctx context.Context, shopID uint) (*CaLamViec, error)
	// MoCa mở một ca mới. Trả ErrCaDangMo nếu chi nhánh đã có ca mở — ràng buộc
	// duy nhất của database là thứ quyết định, không phải một lượt kiểm trước đó.
	MoCa(ctx context.Context, ca *CaLamViec) error
	// DongCa chốt ca dưới khoá dòng: cộng lại sổ quỹ NGAY TẠI ĐÓ rồi ghi tiền
	// theo sổ, tiền đếm được và chênh lệch. Cộng ngoài khoá thì một lượt bán chen
	// vào giữa sẽ làm con số đối chiếu sai ngay lúc ký nhận.
	DongCa(ctx context.Context, id uint, countedCash float64, dem BangDem, giao, giu float64, note string, closedBy uint) (*CaLamViec, error)
	FindByID(ctx context.Context, id uint) (*CaLamViec, error)
	List(ctx context.Context, f CaFilter) ([]CaLamViec, int64, error)

	// TongKet cộng sổ quỹ của một ca.
	TongKet(ctx context.Context, caID uint) (TongKetSoQuy, error)
	// SoQuyCuaCa liệt kê các dòng sổ quỹ của một ca, cũ -> mới.
	SoQuyCuaCa(ctx context.Context, caID uint) ([]SoQuy, error)
	// SoQuyNgoaiCa liệt kê các dòng tiền mặt của chi nhánh KHÔNG gắn ca nào, phát
	// sinh trong khoảng thời gian của ca đang xét. Đây là thứ màn hình đóng ca
	// phải chỉ ra: tiền có thật trong két nhưng không thuộc ca nào.
	SoQuyNgoaiCa(ctx context.Context, shopID uint, tu, den time.Time) ([]SoQuy, error)
	// GhiTay ghi một khoản thu/chi do người trực nhập, tự gắn vào ca đang mở.
	// Khoản chi lớn hơn tiền két theo sổ của ca thì trả *LoiKetAm, trừ khi choAm.
	GhiTay(ctx context.Context, e *SoQuy, choAm bool) error
	// GanMaChungTu điền ReferenceCode và CreatedName cho các dòng sổ quỹ.
	GanMaChungTu(ctx context.Context, ds []SoQuy) error
	// DoanhThuCa gộp đơn quầy thuộc khoảng thời gian của một ca.
	DoanhThuCa(ctx context.Context, caID uint) (DoanhThuCa, error)
	// NopVeTrongCa — tổng phiếu "nộp về công ty" của ca.
	NopVeTrongCa(ctx context.Context, caID uint) (float64, error)
	// ThuChiSuaTrongCa — phiếu thu chi của ca đã bị sửa / xoá (nhật ký), mới nhất trước.
	ThuChiSuaTrongCa(ctx context.Context, caID uint) ([]NhatKyThuChi, error)
	// NguoiMoCa liệt kê những người đã mở ca ở chi nhánh (0 = mọi chi nhánh).
	NguoiMoCa(ctx context.Context, shopID uint) ([]NguoiTrucCa, error)
	// NhanVienTruc — nhân sự đang làm được giao chi nhánh (shop_ids NULL = mọi chi nhánh).
	NhanVienTruc(ctx context.Context, shopID uint) ([]NhanVienTruc, error)
	// TenNhanVien trả tên một hồ sơ nhân sự, "" nếu không có.
	TenNhanVien(ctx context.Context, id uint) string
	// GioDongCaTruoc — giờ đóng của ca gần nhất đã đóng ở chi nhánh; nil nếu chưa có.
	GioDongCaTruoc(ctx context.Context, shopID uint) (*time.Time, error)
	// GanNgoaiCa gắn các dòng sổ quỹ chưa thuộc ca nào vào ca đang mở của chi
	// nhánh, dưới khoá dòng ca (đóng ca chen vào giữa là con số đối chiếu sai).
	GanNgoaiCa(ctx context.Context, shopID uint, ids []uint, userID uint) (*CaLamViec, error)
	// NhatKyNhanVien — nhật ký của một nhân sự, mới trước; TongNhatKyCa cộng trên mọi ca đã đóng.
	NhatKyNhanVien(ctx context.Context, employeeID uint, page, pageSize int) ([]NhatKyNhanVien, int64, error)
	TongNhatKyCa(ctx context.Context, employeeID uint) (TongNhatKyCa, error)

	// BaoCaoKetCa trả MỘT TRANG ca khớp bộ lọc (mới trước), tổng số ca khớp và
	// số cộng của TẤT CẢ ca khớp — xem shift_report.go.
	BaoCaoKetCa(ctx context.Context, f BaoCaoCaFilter) ([]BaoCaoCaDong, int64, BaoCaoCaTong, error)
}
