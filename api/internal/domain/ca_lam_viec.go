package domain

import (
	"context"
	"errors"
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
)

// CaLamViec — một lượt trực két tại một chi nhánh.
type CaLamViec struct {
	ID uint `json:"id" gorm:"primaryKey"`
	TenantOwned
	// ShopID là chi nhánh của ca. Mỗi chi nhánh chỉ được có MỘT ca đang mở —
	// ràng buộc nằm ở database (uq_work_shifts_open), không phải ở tầng code:
	// hai người bấm "mở ca" cùng lúc thì chỉ khoá của database mới chặn được.
	ShopID uint `json:"shop_id"`

	OpenedBy    uint      `json:"opened_by"`
	OpenedAt    time.Time `json:"opened_at"`
	OpeningCash float64   `json:"opening_cash"`

	ClosedBy *uint      `json:"closed_by"`
	ClosedAt *time.Time `json:"closed_at"`
	// CountedCash là tiền ĐẾM ĐƯỢC lúc đóng ca, ExpectedCash là tiền SỔ nói lẽ ra
	// phải có, Difference là chênh lệch (âm = thiếu két).
	//
	// Lưu cả ba chứ không tính lại khi đọc: ExpectedCash chốt theo sổ tại đúng
	// thời điểm đóng ca, mà sổ có thể được ghi thêm sau đó (một khoản chi quên
	// ghi). Con số hai bên đã ký nhận hôm ấy phải giữ nguyên.
	CountedCash  *float64 `json:"counted_cash"`
	ExpectedCash *float64 `json:"expected_cash"`
	Difference   *float64 `json:"difference"`

	Note      string    `json:"note"`
	CreatedAt time.Time `json:"created_at"`
	UpdatedAt time.Time `json:"updated_at"`

	// Các trường dưới đây KHÔNG phải cột — tầng service điền thêm khi trả về màn
	// hình, để một lượt gọi là đủ dựng cả trang.
	ShopName     string  `json:"shop_name,omitempty" gorm:"-"`
	OpenedName   string  `json:"opened_by_name,omitempty" gorm:"-"`
	ClosedName   string  `json:"closed_by_name,omitempty" gorm:"-"`
	TongThu      float64 `json:"tong_thu" gorm:"-"`
	TongChi      float64 `json:"tong_chi" gorm:"-"`
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

// SoQuy — MỘT lần tiền mặt vào hoặc ra khỏi két.
type SoQuy struct {
	ID uint `json:"id" gorm:"primaryKey"`
	TenantOwned
	ShopID uint `json:"shop_id"`
	// ShiftID nil = phát sinh lúc không có ca nào mở.
	//
	// Cho phép nil là quyết định có cân nhắc: bán hàng KHÔNG BAO GIỜ bị chặn vì
	// chưa ai mở ca. Chặn lại thì một buổi sáng quên mở ca là cả tiệm đứng im —
	// cái giá đó lớn hơn hẳn lợi ích của việc ép đúng quy trình. Dòng "ngoài ca"
	// vẫn vào sổ đầy đủ và màn hình đóng ca chỉ chúng ra.
	ShiftID *uint `json:"shift_id"`

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

// CaFilter — tham số lọc danh sách ca.
type CaFilter struct {
	// ShopID = 0 nghĩa là mọi chi nhánh của cửa hàng.
	ShopID   uint
	FromDate string // YYYY-MM-DD, theo opened_at
	ToDate   string
	// Status: "" | dang_mo | da_dong
	Status   string
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
)

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
	DongCa(ctx context.Context, id uint, countedCash float64, note string, closedBy uint) (*CaLamViec, error)
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
	GhiTay(ctx context.Context, e *SoQuy) error
}
