package domain

import (
	"context"
	"errors"
	"time"

	"gorm.io/gorm"
)

// THU CHI — Thu chi → Quản lý thu chi.
//
// Sổ phiếu thu và phiếu chi của cửa hàng. Port từ `cab_income_expenses` của bản
// cũ v2; những chỗ làm khác và vì sao đã ghi ở migration 0058.
//
// Hai thứ đáng nhớ khi đọc struct này:
//
//   - KHÔNG có bảng quỹ. Quỹ đầu kỳ / cuối kỳ cộng khi đọc từ chính bảng này
//     (xem ThuChiTongKet). v2 giữ một bảng quỹ và cộng trừ nó trong hook của
//     model — số dư một khi lệch thì lệch vĩnh viễn.
//
//   - `ShiftID` ghi thẳng lúc lập phiếu, không dò lại theo giờ. Đó là thứ khoá
//     sửa/xoá của phiếu thuộc ca đã đóng dựa vào.
type ThuChi struct {
	ID uint `json:"id" gorm:"primaryKey"`
	TenantOwned

	// ShopID là chi nhánh phát sinh phiếu, chốt lúc lập.
	ShopID uint   `json:"shop_id"`
	Code   string `json:"code"`

	// Type: ThuChiPhieuThu (0) hoặc ThuChiPhieuChi (1). Trùng mã số của
	// LoaiThuChi.Type để hai bảng không phải dịch qua lại.
	Type       uint8   `json:"type"`
	CategoryID *uint   `json:"category_id"`
	Amount     float64 `json:"amount" gorm:"type:decimal(15,2)"`

	// PayerType quyết định cột nào trong ba cột dưới có giá trị. Ba cột tách
	// riêng chứ không dùng chung một `employee_id` như v2 — mỗi cột có khoá
	// ngoại của nó, tra nhầm bảng là không thể.
	//
	// Ba cột ấy KHÔNG ra JSON: nơi đọc chỉ cần biết "người này là ai". Bày cả ba
	// ra thì đầu bên kia phải nhớ payer_type nào ứng với cột nào — một luật thừa,
	// và là chỗ để quên. Repository gộp lại thành `PayerRefID` bên dưới.
	PayerType  string `json:"payer_type"`
	EmployeeID *uint  `json:"-"`
	SupplierID *uint  `json:"-"`
	PayerID    *uint  `json:"-"`

	PaymentMethod string `json:"payment_method"`
	// BankAccountID — tài khoản của phiếu CHUYỂN KHOẢN (payment_method_details loại
	// bank_transfer, migration 0095). Ghi sau lượt chèn như AdjustsID.
	BankAccountID *uint `json:"bank_account_id" gorm:"<-:update"`
	// Attachment là ĐƯỜNG DẪN đầy đủ tới tệp (Shop Admin đẩy tệp lên rồi gửi
	// địa chỉ), nên tên trong JSON nói đúng thứ nó chứa.
	Attachment string `json:"attachment_url"`
	Note       string `json:"note"`

	Source   string `json:"source"`
	SourceID *uint  `json:"source_id"`
	// Kind — "" = thu / chi thường; ThuChiNopVe = nộp tiền về công ty (migration
	// 0096): ghi như phiếu chi tiền mặt nhưng KHÔNG phải chi phí.
	Kind string `json:"kind" gorm:"<-:update"`
	// AdjustsID — phiếu ĐIỀU CHỈNH cho một phiếu của ca đã đóng (migration 0094).
	// Ghi sau lượt chèn (<-:update) như ApprovedBy: phiếu tự sinh không đụng tới.
	AdjustsID *uint `json:"adjusts_id" gorm:"<-:update"`

	// ShiftID nil = lúc lập không ca nào mở. Vẫn ghi phiếu bình thường, giống
	// cash_entries: chặn lại thì một buổi quên mở ca là không ai ghi được khoản
	// chi nào, mà cái giá đó lớn hơn hẳn lợi ích của việc ép đúng quy trình.
	ShiftID   *uint `json:"shift_id"`
	CreatedBy *uint `json:"created_by"`
	// ApprovedBy / ApprovedAt — chủ / quản trị duyệt phiếu chi vượt hạn mức ở quầy
	// (migration 0093). Ghi sau lượt chèn (<-:update): phiếu tự sinh không đụng tới.
	ApprovedBy *uint      `json:"approved_by" gorm:"<-:update"`
	ApprovedAt *time.Time `json:"approved_at" gorm:"<-:update"`

	CreatedAt time.Time      `json:"created_at"`
	UpdatedAt time.Time      `json:"updated_at"`
	DeletedAt gorm.DeletedAt `json:"-" gorm:"index"`

	// Các trường dưới đây KHÔNG phải cột — repository tra kèm khi đọc lên, để
	// một lượt gọi là đủ dựng cả bảng.
	BranchName   string `json:"branch_name" gorm:"-"`
	CategoryName string `json:"category_name" gorm:"-"`
	// PayerName là tên của đúng một trong ba cột đối tượng, tuỳ PayerType.
	PayerName string `json:"payer_name" gorm:"-"`
	// PayerRefID là id của ĐÚNG cột ứng với PayerType — thứ hộp Sửa cần để chọn
	// lại đúng người trong ô "Người nộp". Không có nó thì phiếu ghi cho một nhân
	// viên mở ra là ô ấy trống, lưu lại một cái là mất người nộp.
	PayerRefID    *uint  `json:"payer_id" gorm:"-"`
	CreatedByName string `json:"created_by_name" gorm:"-"`
	// ApprovedByName — tên người duyệt phiếu chi vượt hạn mức ở quầy (khi có).
	ApprovedByName string `json:"approved_by_name,omitempty" gorm:"-"`
	// SourceCode là mã chứng từ gốc của phiếu tự sinh (mã đơn, mã phiếu mua…).
	SourceCode string `json:"source_code" gorm:"-"`

	// BankAccountLabel — "VCB · 0123456789 · NGUYEN VAN A" để hiện và xuất Excel.
	BankAccountLabel string `json:"bank_account_label,omitempty" gorm:"-"`
	// AdjustsCode — mã phiếu gốc; DieuChinhBoi — các phiếu điều chỉnh trỏ về phiếu
	// này. Hai chiều để khung chi tiết nối được cả hai phía.
	AdjustsCode  string          `json:"adjusts_code,omitempty" gorm:"-"`
	DieuChinhBoi []ThuChiLienKet `json:"adjusted_by,omitempty" gorm:"-"`
	// CoTheDieuChinh — phiếu tay khoá vì ca đã đóng: chỉ còn đường lập phiếu điều chỉnh.
	CoTheDieuChinh bool `json:"can_adjust" gorm:"-"`

	// SuaLanCuoi — lượt sửa gần nhất (activity_logs), cho dòng "Sửa lần cuối …".
	SuaLanCuoi *ThuChiSuaCuoi `json:"last_edit,omitempty" gorm:"-"`
	// NhatKy — service dựng, repository ghi CÙNG giao dịch với lượt sửa / xoá:
	// sửa xong mà mất dòng nhật ký là đúng chỗ hở người ta cần bịt.
	NhatKy *ActivityLog `json:"-" gorm:"-"`

	// Locked = phiếu này không ai sửa hay xoá được nữa, LockedReason nói vì sao.
	// Tính ở tầng service theo ba lớp khoá — xem ThuChiKhoa.
	Locked       bool   `json:"locked" gorm:"-"`
	LockedReason string `json:"locked_reason" gorm:"-"`
}

func (ThuChi) TableName() string { return "income_expenses" }

// Hai vế của cột type. Trùng mã số của LoaiThu / LoaiChi.
const (
	ThuChiPhieuThu uint8 = 0
	ThuChiPhieuChi uint8 = 1
)

// Phương thức thanh toán. v2 khai sáu mã rồi lọc bỏ bốn ngay trên trình duyệt,
// chỉ còn hai cái này thật sự dùng tới.
const (
	ThuChiTienMat     = "cash"
	ThuChiChuyenKhoan = "transfer"
)

// Loại đối tượng nộp / nhận tiền.
//
// Hai vai nhân viên đặt trùng mã CỬA VÀO (`users.access_areas`) để lọc thẳng
// theo trường ấy, không phải bắc thêm một bảng tra. v2 có sáu vai vì nó là phần
// mềm nhà hàng (quầy bếp, nhân viên order bàn); bên này nhân sự chỉ có hai.
const (
	ThuChiDoiTuongQuanLy  = "quan_ly"
	ThuChiDoiTuongThuNgan = "thu_ngan"
	ThuChiDoiTuongNCC     = "supplier"
	ThuChiDoiTuongKhac    = "other"
)

// Nguồn phát sinh phiếu.
//
// `ThuChiTuTay` là phiếu người dùng tự lập — cũng là phiếu DUY NHẤT sửa/xoá
// được. Bốn nguồn còn lại do chứng từ khác đẻ ra.
//
// v2 dùng số 1..4 với nhãn lệch nghĩa: "Bán hàng" gộp cả trả hàng NCC, còn "Tự
// động tạo" thật ra là phiếu nhập tay. Ở đây mỗi giá trị nói đúng một nguồn.
const (
	ThuChiTuTay      = "manual"
	ThuChiTuDonHang  = "order"
	ThuChiTuPhieuMua = "purchase"
	ThuChiTuTraNCC   = "supplier_return"
	ThuChiTuTraHang  = "order_return"
	// ThuChiTuPhiThe — phí quẹt thẻ cửa hàng chịu của một đơn quầy (source_id = id đơn).
	ThuChiTuPhiThe = "card_fee"
)

// LoaiChiPhiThanhToan là loại chi gieo sẵn cho mọi cửa hàng (migration 0057) mà
// phiếu phí thẻ tự sinh trỏ vào. Tra theo tên: cửa hàng đổi tên hay xoá loại này
// thì phiếu vẫn ghi, chỉ là không có phân loại.
const LoaiChiPhiThanhToan = "Phí thanh toán, phí ngân hàng, phí cổng thanh toán"

// TuSinh cho biết phiếu do chứng từ khác đẻ ra, không phải người dùng tự lập.
func (t ThuChi) TuSinh() bool { return t.Source != "" && t.Source != ThuChiTuTay }

// NguoiNopThuChi — người trả / nhận tiền vãng lai, không phải nhân viên cũng
// không phải nhà cung cấp.
type NguoiNopThuChi struct {
	ID uint `json:"id" gorm:"primaryKey"`
	TenantOwned
	Name    string `json:"name"`
	Phone   string `json:"phone"`
	Address string `json:"address"`

	CreatedAt time.Time      `json:"created_at"`
	UpdatedAt time.Time      `json:"updated_at"`
	DeletedAt gorm.DeletedAt `json:"-" gorm:"index"`
}

func (NguoiNopThuChi) TableName() string { return "income_expense_payers" }

// ThuChiTongKet — bốn ô thống kê trên đầu bảng danh sách.
//
// CỘNG KHI ĐỌC, không đọc từ một bảng quỹ. `DauKy` là số dư tích luỹ của mọi
// phiếu TRƯỚC khoảng đang xem (cùng bộ lọc còn lại), `CuoiKy` = DauKy + Thu −
// Chi trong khoảng.
//
// Vì sao không giữ bảng quỹ như v2: bảng ấy chỉ đúng chừng nào MỌI đường ghi
// đều nhớ cập nhật nó, và v2 đã sai ở cả ba chỗ (dòng quỹ không khoá theo chi
// nhánh, đọc-cộng-ghi không lock, xoá phiếu không hoàn lại đúng). Cộng lại từ
// nguồn thì không có gì để quên.
type ThuChiTongKet struct {
	DauKy   float64 `json:"begin_balance"`
	TongThu float64 `json:"total_income"`
	TongChi float64 `json:"total_expense"`
	CuoiKy  float64 `json:"end_balance"`
	// Phần TIỀN MẶT của tổng thu / tổng chi — thứ thật sự ra vào két. Màn quầy
	// đối chiếu với két theo sổ của ca; chuyển khoản không qua két nên gộp vào
	// là số không khớp tiền đếm được.
	TienMatThu float64 `json:"cash_income"`
	TienMatChi float64 `json:"cash_expense"`
	// Số phiếu mỗi vế — số trên tab Thu / Chi của màn quầy.
	SoPhieuThu int64 `json:"income_count"`
	SoPhieuChi int64 `json:"expense_count"`
}

// ThuChiFilter — tham số lọc / phân trang khi liệt kê.
type ThuChiFilter struct {
	Keyword string // mã phiếu
	// TimRong — ô tìm của màn quầy: mã phiếu, mô tả, hoặc tên người nộp/nhận.
	// Tách khỏi Keyword vì ô "Mã phiếu" của màn quản trị chỉ tìm theo mã.
	TimRong string
	// ShiftID — chỉ phiếu của một ca (tab "Ca này" ở quầy). 0 = không lọc.
	ShiftID uint
	// BankAccountID — chỉ phiếu ghi vào tài khoản này (đối sao kê). 0 = không lọc.
	BankAccountID uint
	// Type: nhiều giá trị ngăn bởi dấu phẩy ("0", "1", "0,1"). Rỗng = cả hai vế.
	Type string
	// CategoryID — id phân loại, nhiều giá trị ngăn bởi dấu phẩy.
	CategoryID string
	// Source — nguồn phát sinh, nhiều giá trị ngăn bởi dấu phẩy. Giá trị "return"
	// gồm CẢ trả hàng bán lẫn trả hàng NCC: ô lọc của màn hình chỉ có một mục
	// "Trả hàng", mà v2 tách hai nguồn ấy ra rồi bỏ quên một nửa.
	Source string
	// CreatedBy — id người lập, nhiều giá trị ngăn bởi dấu phẩy.
	CreatedBy string
	ShopID    uint   // 0 = mọi chi nhánh
	FromDate  string // YYYY-MM-DD, theo created_at
	ToDate    string
	Page      int
	PageSize  int
}

// ThuChiNopVe — Kind của phiếu nộp tiền về công ty giữa ca.
const ThuChiNopVe = "deposit"

// Nhật ký sửa / xoá phiếu thu chi (bảng activity_logs, subject_type income_expense).
const (
	NhatKyThuChiLoai = "income_expense"
	NhatKyThuChiSua  = "thu_chi.sua"
	NhatKyThuChiXoa  = "thu_chi.xoa"
)

// ThuChiLienKet — mã + id của một phiếu liên quan (điều chỉnh / bị điều chỉnh).
type ThuChiLienKet struct {
	ID   uint   `json:"id"`
	Code string `json:"code"`
}

// ThuChiSuaCuoi — tóm tắt lượt sửa gần nhất của một phiếu.
type ThuChiSuaCuoi struct {
	Luc       time.Time `json:"at"`
	NguoiSua  string    `json:"by_name"`
	SoTienCu  float64   `json:"amount_old"`
	SoTienMoi float64   `json:"amount_new"`
	LyDo      string    `json:"reason"`
}

// ThayDoiThuChi — phần `properties` của một dòng nhật ký: giá trị trước / sau.
// Lượt xoá chỉ có vế cũ.
type ThayDoiThuChi struct {
	Ma          string   `json:"ma"`
	Loai        uint8    `json:"loai"`
	Ca          *uint    `json:"ca"`
	SoTienCu    float64  `json:"so_tien_cu"`
	SoTienMoi   *float64 `json:"so_tien_moi,omitempty"`
	PhanLoaiCu  string   `json:"phan_loai_cu"`
	PhanLoaiMoi *string  `json:"phan_loai_moi,omitempty"`
	HinhThucCu  string   `json:"hinh_thuc_cu"`
	HinhThucMoi *string  `json:"hinh_thuc_moi,omitempty"`
	MoTaCu      string   `json:"mo_ta_cu"`
	MoTaMoi     *string  `json:"mo_ta_moi,omitempty"`
}

// NhatKyThuChi — một dòng nhật ký sửa / xoá phiếu, đọc ra cho màn hình.
type NhatKyThuChi struct {
	ID       uint          `json:"id"`
	PhieuID  uint          `json:"phieu_id"`
	HanhDong string        `json:"action"`
	Luc      time.Time     `json:"at"`
	NguoiSua string        `json:"by_name"`
	LyDo     string        `json:"reason"`
	ThayDoi  ThayDoiThuChi `json:"changes"`
}

// NguoiDuyetThuChi — đủ để kiểm một lượt duyệt: vai trò, trạng thái, mật khẩu băm.
type NguoiDuyetThuChi struct {
	ID           uint
	Role         string
	Status       string
	PasswordHash string
}

// LoiDuyetChi — phiếu chi ở quầy vượt hạn mức mà chưa có / sai người duyệt.
// Ma ra errors.ma để màn quầy biết mở ô nhập tài khoản quản lý.
type LoiDuyetChi struct {
	Ma, Cau string
	Khoa    bool // sai quá nhiều lần → 429
}

func (e *LoiDuyetChi) Error() string { return e.Cau }

// ThuChiNguoiXem — người đang đọc danh sách, để tính lớp khoá "phiếu của người
// khác". Tách thành struct thay vì hai tham số rời: nơi gọi hay đảo nhầm hai
// giá trị cùng kiểu.
type ThuChiNguoiXem struct {
	UserID uint
	// QuanLy = tài khoản có quyền sửa phiếu do người khác lập.
	QuanLy bool
}

// ThuChiNhanVien — một dòng của ô "Người nộp / nhận" ở quầy: tài khoản nhân viên
// cùng vai để ghi `payer_type` đúng như hộp lập phiếu của quản trị.
type ThuChiNhanVien struct {
	ID        uint   `json:"id"`
	Name      string `json:"name"`
	PayerType string `json:"payer_type"`
}

// ThuChiDanhMucQuay — đủ ba danh mục cho hộp lập phiếu ở quầy trong một lượt
// gọi. Ba đường riêng của chúng nằm sau cửa Quản lý, người chỉ có cửa Thu ngân
// không gọi được.
type ThuChiDanhMucQuay struct {
	PhanLoai []LoaiThuChi     `json:"phan_loai"`
	NhanVien []ThuChiNhanVien `json:"nhan_vien"`
	NguoiNop []NguoiNopThuChi `json:"nguoi_nop"`
	// PhanLoaiNhanh — id phân loại cho dãy viên chọn của hộp lập phiếu, đúng thứ tự
	// hiện (cả thu lẫn chi, màn lọc theo vế); PhanLoaiGhim — phần chủ ghim ở Cài đặt.
	PhanLoaiNhanh []uint `json:"phan_loai_nhanh"`
	PhanLoaiGhim  []uint `json:"phan_loai_ghim"`
	// MauPhieu — mẫu phiếu nhanh chủ khai ở Cài đặt → Quầy bán hàng.
	MauPhieu []MauPhieuThuChi `json:"mau_phieu"`
	// TaiKhoan — tài khoản ngân hàng đang bật của chi nhánh, cho phiếu chuyển khoản.
	TaiKhoan []TaiKhoanThuChi `json:"tai_khoan"`
}

// TaiKhoanThuChi — một tài khoản ngân hàng (payment_method_details bank_transfer).
type TaiKhoanThuChi struct {
	ID          uint   `json:"id"`
	ShopID      uint   `json:"shop_id"`
	ChiNhanh    string `json:"branch_name"`
	Nhan        string `json:"label"`
	NganHang    string `json:"bank_name"`
	SoTaiKhoan  string `json:"bank_account"`
	ChuTaiKhoan string `json:"bank_account_name"`
	DangBat     bool   `json:"status"`
	MacDinh     bool   `json:"is_default"`
}

// MauPhieuThuChi — một mẫu phiếu nhanh đã giải phân loại theo danh mục của tiệm;
// PhanLoai nil = không khớp loại nào, thu ngân tự chọn.
type MauPhieuThuChi struct {
	Ten      string  `json:"ten"`
	Loai     uint8   `json:"loai"`
	PhanLoai *uint   `json:"phan_loai"`
	MoTa     string  `json:"mo_ta"`
	SoTien   float64 `json:"so_tien"`
}

// ThuChiRepository — truy cập bảng income_expenses và income_expense_payers.
type ThuChiRepository interface {
	// PhanLoaiHayDung — id phân loại của phiếu LẬP TAY ở chi nhánh từ mốc `tu`,
	// nhiều phiếu nhất trước.
	PhanLoaiHayDung(ctx context.Context, shopID uint, tu time.Time) ([]uint, error)
	// TaiKhoanNganHang — tài khoản bank_transfer của chi nhánh (shopID 0 = mọi chi
	// nhánh); chiDangBat = bỏ dòng đã tắt.
	TaiKhoanNganHang(ctx context.Context, shopID uint, chiDangBat bool) ([]TaiKhoanThuChi, error)
	// NguoiDuyet tra tài khoản theo tên đăng nhập (trong cửa hàng của phiên) để
	// duyệt phiếu chi; nil = không có.
	NguoiDuyet(ctx context.Context, username string) (*NguoiDuyetThuChi, error)
	// List trả về danh sách đã cắt trang, tổng số dòng, và bốn ô thống kê quỹ
	// của CẢ bộ lọc (không phải của riêng trang đang xem).
	List(ctx context.Context, f ThuChiFilter) ([]ThuChi, int64, ThuChiTongKet, error)
	FindByID(ctx context.Context, id uint) (*ThuChi, error)
	Create(ctx context.Context, t *ThuChi) error
	// Update đọc-sửa-ghi dưới khoá dòng. mutate nhận phiếu đang khoá và trả về
	// danh sách cột cần ghi.
	Update(ctx context.Context, id uint, mutate func(t *ThuChi) ([]string, error)) (*ThuChi, error)
	// Delete xoá phiếu và ghi `nhatKy` (nếu có) trong cùng giao dịch.
	Delete(ctx context.Context, id uint, nhatKy *ActivityLog) error
	// NhatKy — nhật ký sửa / xoá của một phiếu, mới nhất trước.
	NhatKy(ctx context.Context, phieuID uint) ([]NhatKyThuChi, error)

	// ChiNhanhMacDinh là chi nhánh của request: con số ghim trong header, hoặc
	// chi nhánh duy nhất của cửa hàng. Không xác định được thì lỗi — phiếu thu
	// chi luôn phát sinh ở MỘT chi nhánh cụ thể.
	ChiNhanhMacDinh(ctx context.Context) (uint, error)
	// CaDangMo trả về id ca đang trực tại chi nhánh, nil khi không có ca nào mở.
	CaDangMo(ctx context.Context, shopID uint) (*uint, error)
	// CaDaDong cho biết ca này đã chốt chưa. Ca không tồn tại cũng trả false —
	// khoá một phiếu vì một ca không tra ra được là khoá nhầm.
	CaDaDong(ctx context.Context, shiftID uint) (bool, error)

	ListNguoiNop(ctx context.Context, keyword string) ([]NguoiNopThuChi, error)
	PhanLoai(ctx context.Context) ([]LoaiThuChi, error)
	// TimPhanLoai tra một phân loại TRONG cửa hàng của phiên; nil khi không có
	// (kể cả id của cửa hàng khác — khoá ngoại không chặn được chuyện đó).
	TimPhanLoai(ctx context.Context, id uint) (*LoaiThuChi, error)
	// CoDoiTuong — id người nộp / nhận có thật trong cửa hàng, đúng bảng theo loại.
	CoDoiTuong(ctx context.Context, loai string, id uint) (bool, error)
	// NhanVien — tài khoản đang hoạt động có cửa Quản lý hoặc Thu ngân.
	NhanVien(ctx context.Context) ([]ThuChiNhanVien, error)
	// TonTaiNguoiNopTen xét trên dòng chưa xoá, để khai lại tên đã xoá vẫn được.
	TonTaiNguoiNopTen(ctx context.Context, name string) (bool, error)
	CreateNguoiNop(ctx context.Context, n *NguoiNopThuChi) error
	// DeleteNguoiNop xoá MỀM. Phiếu cũ vẫn in ra đúng tên người nộp: tên đọc từ
	// bảng bằng truy vấn thô (xem tenTheoID), không qua bộ lọc xoá mềm của GORM.
	DeleteNguoiNop(ctx context.Context, id uint) error
}

// Lỗi của cụm thu chi.
var (
	// ErrThuChiTuSinh — phiếu do chứng từ khác đẻ ra: sửa/xoá nó là sửa số liệu
	// của chứng từ gốc mà chứng từ ấy không biết.
	ErrThuChiTuSinh = errors.New("phiếu tự phát sinh từ chứng từ khác, không sửa hay xoá được")

	// ErrThuChiCaDaDong — ca đã chốt số, sửa vào là lệch báo cáo ca. Khoá với
	// MỌI tài khoản, kể cả chủ tiệm.
	ErrThuChiCaDaDong = errors.New("phiếu thuộc ca đã đóng, không sửa hay xoá được")

	// ErrThuChiCuaNguoiKhac — mặc định chỉ người lập mới sửa/xoá phiếu của mình.
	ErrThuChiCuaNguoiKhac = errors.New("phiếu do người khác lập, bạn không sửa hay xoá được")

	// ErrNguoiNopTrungTen — đã có người nộp cùng tên trong cửa hàng này.
	ErrNguoiNopTrungTen = errors.New("tên người nộp đã tồn tại")
)

// ThuChiKhoa tính BA LỚP KHOÁ sửa/xoá, trả về lý do đầu tiên chạm phải ("" =
// không khoá). Thứ tự xét đi từ lớp cứng nhất xuống:
//
//  1. Phiếu tự sinh từ chứng từ khác — không ai sửa được, kể cả chủ tiệm.
//  2. Phiếu thuộc ca đã đóng — cũng không ai sửa được. Ca đã chốt số và hai bên
//     đã ký nhận; sửa vào là con số trên giấy khác con số trong máy.
//  3. Phiếu của người khác — chỉ tài khoản quản lý mới qua được lớp này.
//
// Đây là nguồn sự thật DUY NHẤT của luật khoá: cả lượt đọc (dựng cờ `locked`
// cho giao diện ẩn nút) lẫn lượt ghi (chặn thật) đều gọi hàm này. Tách hai chỗ
// là một ngày nào đó nút hiện ra mà bấm vào thì bị từ chối.
func ThuChiKhoa(t *ThuChi, caDaDong bool, nguoi ThuChiNguoiXem) error {
	switch {
	case t.TuSinh():
		return ErrThuChiTuSinh
	case caDaDong:
		return ErrThuChiCaDaDong
	case !nguoi.QuanLy && (t.CreatedBy == nil || *t.CreatedBy != nguoi.UserID):
		return ErrThuChiCuaNguoiKhac
	default:
		return nil
	}
}
