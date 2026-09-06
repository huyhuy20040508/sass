package domain

import (
	"context"
	"errors"
	"strconv"
	"strings"
	"time"

	"gorm.io/gorm"
)

// NhanVien là HỒ SƠ một người đi làm tại cửa hàng — bảng `employees`.
//
// Đừng lẫn với User: `users` là TÀI KHOẢN đăng nhập (và còn chứa cả khách hàng),
// còn đây là CON NGƯỜI. Quan hệ 1–1 và không bắt buộc theo cả hai chiều — phần
// đông nhân viên tiệm nhỏ không đụng vào phần mềm nên UserID = nil.
//
// Vì sao không nhét mấy cột này vào `users`: xem đầu tệp migration 0010.
type NhanVien struct {
	ID uint `json:"id" gorm:"primaryKey"`
	TenantOwned

	// ShopID NULL được: cửa hàng một điểm bán thì "làm ở chi nhánh nào" chưa có
	// nghĩa. UserID NULL = người này không có tài khoản đăng nhập.
	ShopID *uint `json:"shop_id"`
	// ShopIDs là CSV id các chi nhánh người này được làm ("1,3"); NULL = mọi chi
	// nhánh (như 'all' của v2). ShopID ở trên là chi nhánh CHÍNH = phần tử đầu.
	ShopIDs StringOrNull `json:"shop_ids"`
	UserID  *uint        `json:"user_id"`

	Code     string `json:"code"`
	FullName string `json:"full_name"`
	// Avatar giữ ĐƯỜNG DẪN ảnh trên ổ đĩa công khai của Shop Admin, không giữ ảnh
	// — xem migration 0016. Rỗng = chưa có ảnh.
	Avatar StringOrNull `json:"avatar"`
	Gender EnumOrNull   `json:"gender"`
	// BirthDate/HiredOn là cột DATE. Dùng *time.Time để phân biệt "chưa khai" với
	// ngày 0001-01-01 — hai thứ đó hiện ra màn hình khác hẳn nhau.
	BirthDate *time.Time   `json:"birth_date"`
	Phone     StringOrNull `json:"phone"`
	Email     StringOrNull `json:"email"`
	IDNumber  StringOrNull `json:"id_number"`
	Address   StringOrNull `json:"address"`

	// Position là VIỆC người đó làm; quyền trên phần mềm nằm ở users.role_id.
	// Trang nhân sự KHÔNG còn gửi ô này (đã thay bằng ca làm) — hồ sơ mới rơi về
	// mặc định 'ban_hang' của cột; dữ liệu cũ và module chấm công sau vẫn dùng.
	Position string     `json:"position"`
	HiredOn  *time.Time `json:"hired_on"`
	// WorkShift là cột SET: nhiều ca trong một ô, đọc lên thành "sang,chieu".
	// NULL = chưa xếp ca, khác chuỗi rỗng ("đã xét, không trực ca nào").
	WorkShift    StringOrNull `json:"work_shift"`
	ContractType EnumOrNull   `json:"contract_type"`
	Status       string       `json:"status"`

	SalaryType     EnumOrNull `json:"salary_type"`
	Salary         float64    `json:"salary"`
	Allowance      float64    `json:"allowance"`
	CommissionRate float64    `json:"commission_rate"`

	Note StringOrNull `json:"note"`
	// AllowOutsideArea — cho phép dùng ứng dụng ngoài phạm vi hoạt động của chi
	// nhánh (allow_access_outside_area_scope của v2). Mặc định cho phép.
	AllowOutsideArea bool           `json:"allow_outside_area"`
	CreatedAt        time.Time      `json:"created_at"`
	UpdatedAt        time.Time      `json:"updated_at"`
	DeletedAt        gorm.DeletedAt `json:"-" gorm:"index"`
}

func (NhanVien) TableName() string { return "employees" }

// Chức danh công việc — khớp ENUM `employees.position`.
const (
	ChucDanhQuanLy   = "quan_ly"
	ChucDanhThuNgan  = "thu_ngan"
	ChucDanhBanHang  = "ban_hang"
	ChucDanhThuKho   = "thu_kho"
	ChucDanhKeToan   = "ke_toan"
	ChucDanhGiaoHang = "giao_hang"
	ChucDanhKhac     = "khac"
)

// Ca trực — khớp SET `employees.work_shift`.
const (
	CaSang  = "sang"
	CaChieu = "chieu"
	CaNgay  = "ca_ngay"
)

// Trạng thái làm việc — khớp ENUM `employees.status`. TamNghi khác DaNghi:
// người nghỉ dài ngày vẫn thuộc cửa hàng và sẽ quay lại.
const (
	NhanSuDangLam = "dang_lam"
	NhanSuTamNghi = "tam_nghi"
	NhanSuDaNghi  = "da_nghi"
)

// Danh sách giá trị ENUM chấp nhận được. Kiểm ở tầng Go chứ không phó mặc cho
// MySQL: giá trị lạ xuống ENUM thì lỗi 1265 với câu chữ không ai hiểu.
var (
	// Chỉ hai chức danh đặt được, như order v2. ENUM dưới database vẫn giữ đủ bảy
	// giá trị cho hồ sơ cũ và cho module HRM dựng sau.
	//
	// Trang nhân sự không còn ô này (đã thay bằng ca làm), nhưng luật vẫn giữ:
	// ô bỏ trống thì bỏ qua, còn gửi lên một giá trị thì phải là giá trị thật.
	ChucDanhHopLe = map[string]bool{
		ChucDanhQuanLy: true, ChucDanhThuNgan: true,
	}
	CaLamHopLe = map[string]bool{
		CaSang: true, CaChieu: true, CaNgay: true,
	}
	TrangThaiNhanSuHopLe = map[string]bool{
		NhanSuDangLam: true, NhanSuTamNghi: true, NhanSuDaNghi: true,
	}
	LoaiHopDongHopLe = map[string]bool{
		"thu_viec": true, "chinh_thuc": true, "thoi_vu": true, "cong_tac_vien": true,
	}
	HinhThucLuongHopLe = map[string]bool{"thang": true, "ca": true, "gio": true}
)

// NhanSuFilter — bộ lọc của trang danh sách. Không phân trang, và đó là quyết
// định: số người của một cửa hàng đếm bằng hàng chục.
type NhanSuFilter struct {
	Keyword string
	Status  string
	// Position vẫn lọc được qua API dù màn hình không còn ô đó — dữ liệu cũ và
	// module chấm công sau còn dùng tới chức danh.
	Position string
	// WorkShift lọc theo MỘT ca; cột là SET nên phải FIND_IN_SET chứ không so
	// bằng — người trực "sang,chieu" vẫn phải lọt vào lượt lọc ca sáng.
	WorkShift    string
	ContractType string
	ShopID       uint
}

// NhanVienRepository — sổ nhân sự. Mọi lượt đọc/ghi đi qua kết nối data plane
// nên đã tự kèm điều kiện tenant (xem repository/tenant_scope.go).
type NhanVienRepository interface {
	List(ctx context.Context, f NhanSuFilter) ([]NhanVien, error)
	FindByID(ctx context.Context, id uint) (*NhanVien, error)
	// ExistsByCode tính CẢ hồ sơ đã xoá mềm: mã của chúng vẫn giữ chỗ trong
	// uq_employees_tenant_code, nên báo trùng ở đây dễ hiểu hơn là để MySQL ném
	// lỗi khoá lúc ghi.
	ExistsByCode(ctx context.Context, code string, excludeID uint) (bool, error)
	// ExistsByName chặn hai hồ sơ cùng tên trong một cửa hàng.
	ExistsByName(ctx context.Context, name string, excludeID uint) (bool, error)
	// ExistsByCot kiểm trùng MỘT cột định danh (phone, email, id_number) trong
	// cửa hàng, như v2 kiểm unique ở tầng ứng dụng. Bỏ qua hồ sơ đã xoá mềm.
	ExistsByCot(ctx context.Context, cot, giaTri string, excludeID uint) (bool, error)
	// ExistsByUser cho biết tài khoản này đã có hồ sơ nhân sự khác nhận chưa —
	// uq_employees_user chỉ cho một hồ sơ giữ một tài khoản.
	ExistsByUser(ctx context.Context, userID uint, excludeID uint) (bool, error)
	Create(ctx context.Context, nv *NhanVien) error
	Update(ctx context.Context, nv *NhanVien) error
	Delete(ctx context.Context, id uint) error
	// MaLonNhat trả về mã nhân viên lớn nhất đang có (kể cả hồ sơ đã xoá mềm) để
	// sinh mã kế tiếp. Rỗng = cửa hàng chưa có ai.
	MaLonNhat(ctx context.Context) (string, error)

	// RangBuocCuaTaiKhoan cho biết tài khoản này còn dính gì tới sổ sách: một ca
	// chưa đóng, và/hoặc những dòng sổ quỹ do họ ghi.
	//
	// Hỏi MỘT lượt cho cả hai thay vì hai hàm: cả hai chỉ dùng đúng ở lượt xoá,
	// và tách ra thì chỗ gọi phải nhớ hỏi đủ — quên một câu là lỗ hổng im lặng.
	RangBuocCuaTaiKhoan(ctx context.Context, userID uint) (coCaChuaDong bool, coSoQuy bool, err error)

	// ChiNhanhCuaTaiKhoan trả về các chi nhánh mà tài khoản này được phân về
	// (phần tử đầu là chi nhánh chính).
	//
	// Rỗng = KHÔNG bị buộc vào chi nhánh nào: hoặc người này chưa có hồ sơ nhân
	// sự (chủ tiệm), hoặc hồ sơ chưa khai chi nhánh (tiệm một điểm bán). Cả hai
	// trường hợp đều được làm ở mọi chi nhánh — đây là chốt CHẶN người đã bị phân
	// công, không phải chốt cấp quyền cho người chưa.
	ChiNhanhCuaTaiKhoan(ctx context.Context, userID uint) ([]uint, error)
}

// Lỗi nghiệp vụ của module nhân sự. Ba lỗi riêng chứ không gộp vào ErrConflict:
// mỗi cái chữa bằng một cách khác nhau.
var (
	ErrMaNhanVienDaCo = errors.New("mã nhân viên này đã có người dùng")
	// ErrTaiKhoanDaGanNhanSu — tài khoản đang được một hồ sơ khác nhận. Gắn hai
	// hồ sơ vào một tài khoản thì không trả lời được "ai đang đăng nhập".
	ErrTaiKhoanDaGanNhanSu = errors.New("tài khoản này đã gắn với một hồ sơ nhân sự khác")
	// ErrNhanSuDaCoTaiKhoan — hồ sơ đã có tài khoản mà lượt sửa lại xin cấp thêm
	// một cái nữa.
	ErrNhanSuDaCoTaiKhoan = errors.New("hồ sơ này đã có tài khoản đăng nhập")
	// ErrTuDanhDauNghiViec — hồ sơ đang bị đánh dấu nghỉ việc lại gắn với chính
	// tài khoản đang bấm. Đánh dấu nghỉ thì khoá luôn tài khoản, nên lượt bấm đó
	// tự đá người bấm ra ngoài giữa chừng.
	ErrTuDanhDauNghiViec = errors.New("không thể tự đánh dấu mình đã nghỉ việc")
	// ErrNhanSuDangMoCa — người này còn một ca chưa đóng. Xoá hồ sơ là khoá luôn
	// tài khoản, nên chính người đang giữ két lại mất đường đóng ca của mình; ca
	// treo lơ lửng và số tiền trong đó không ai đối chiếu được nữa.
	ErrNhanSuDangMoCa = errors.New("nhân viên này còn ca chưa đóng")
	// ErrNhanSuDaGhiSoQuy — người này đã ghi sổ quỹ. Giữ lại hồ sơ để mấy dòng
	// tiền ấy còn tra ra được tên người ghi. Nghỉ việc thì đặt trạng thái
	// "đã nghỉ" — hồ sơ ở lại, tài khoản vẫn bị khoá.
	ErrNhanSuDaGhiSoQuy = errors.New("nhân viên này đã ghi sổ quỹ")
	// ErrNhanSuChuaCoTaiKhoan — đặt lại mật khẩu cho hồ sơ chưa từng cấp tài khoản.
	ErrNhanSuChuaCoTaiKhoan = errors.New("hồ sơ này chưa có tài khoản đăng nhập")
)

// ChiNhanhTuCSV đọc cột shop_ids ("1,3") thành danh sách id; rỗng -> nil.
func ChiNhanhTuCSV(s string) []uint {
	var ra []uint
	for _, p := range strings.Split(s, ",") {
		if n, err := strconv.ParseUint(strings.TrimSpace(p), 10, 64); err == nil && n > 0 {
			ra = append(ra, uint(n))
		}
	}

	return ra
}

// ChiNhanhRaCSV ghi danh sách id thành chuỗi cho cột shop_ids.
func ChiNhanhRaCSV(ids []uint) string {
	parts := make([]string, 0, len(ids))
	for _, id := range ids {
		parts = append(parts, strconv.FormatUint(uint64(id), 10))
	}

	return strings.Join(parts, ",")
}
