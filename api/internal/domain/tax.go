package domain

import (
	"context"
	"encoding/json"
	"errors"
	"strconv"
	"time"
)

// THUẾ SUẤT — mỗi loại giữ danh sách mức được phép chọn ở màn nghiệp vụ.
//
// Bốn loại cố định khai ngay trong mã nguồn: cửa hàng không thêm/xoá loại, chỉ
// tick trong mỗi loại những mức nào cho hiện ra.

// Hai mức KHÔNG phải phần trăm. Để số âm chứ không quy về 0 vì đây chính là mã
// hoá đơn điện tử nhận — quy về 0 là mất phân biệt "0%" với "không chịu thuế".
const (
	// MucKhongChiuThue — KCT.
	MucKhongChiuThue = -1
	// MucKhongKeKhai — KKKNT.
	MucKhongKeKhai = -2
)

// Mã loại thuế — nơi dùng tra bằng hằng số, không gõ chuỗi bằng tay.
const (
	ThueMacDinh   = "mac-dinh"
	ThueTieuThuDB = "tieu-thu-dac-biet"
	ThueMuaHang   = "mua-hang"
	ThueBanHang   = "ban-hang"
)

// LoaiThue — một loại thuế: bộ mức nó cho chọn và bộ mức bật sẵn.
type LoaiThue struct {
	Ma  string `json:"ma"`
	Ten string `json:"ten"`
	// MoTa nói loại này chi phối màn nào — người cấu hình cần biết tick vào đây
	// thì chỗ nào đổi theo.
	MoTa string `json:"mo_ta"`
	// ChonDuoc: các mức bày ra trong ô chọn.
	ChonDuoc []int `json:"chon_duoc"`
	// MacDinh: các mức bật sẵn lúc cửa hàng vừa mở.
	MacDinh []int `json:"mac_dinh"`
}

// DanhMucLoaiThue — bốn loại thuế của hệ thống, và là nguồn sự thật duy nhất.
//
// Bộ mức nằm ở đây chứ không nằm trong JavaScript của màn hình: luật thuế có
// đổi (8% là mức giảm có hạn, 3% đã bỏ), để trong giao diện thì mỗi lần đổi là
// sửa hai chỗ rồi phải phát hành lại trang quản trị.
var DanhMucLoaiThue = []LoaiThue{
	{
		Ma:       ThueMacDinh,
		Ten:      "Thuế mặc định",
		MoTa:     "Áp cho nhóm hàng hóa và từng mặt hàng khi không khai riêng.",
		ChonDuoc: []int{0, 5, 8, 10, MucKhongChiuThue, MucKhongKeKhai},
		MacDinh:  []int{0, 8, 10},
	},
	{
		Ma:       ThueTieuThuDB,
		Ten:      "Thuế tiêu thụ đặc biệt",
		MoTa:     "Gắn thêm cho mặt hàng thuộc diện chịu thuế tiêu thụ đặc biệt.",
		ChonDuoc: []int{12, 15, 20, 25, 30, 35, 40, 45},
		MacDinh:  []int{12},
	},
	{
		Ma:       ThueMuaHang,
		Ten:      "Thuế trên đơn mua hàng",
		MoTa:     "Bày ra ở phiếu đặt mua, nhập kho và trả hàng nhà cung cấp.",
		ChonDuoc: []int{0, 5, 8, 10},
		MacDinh:  []int{0, 5, 8, 10},
	},
	{
		Ma:       ThueBanHang,
		Ten:      "Thuế đơn bán hàng",
		MoTa:     "Bày ra ở màn thu ngân, cho cả đơn lẫn từng dòng hàng.",
		ChonDuoc: []int{0, 5, 8, 10, 12, 15, 20, 25, 30, 35, 40, 45, MucKhongChiuThue, MucKhongKeKhai},
		MacDinh:  []int{0, 5, 8, 10},
	},
}

// TimLoaiThue tra một loại theo mã.
func TimLoaiThue(ma string) (LoaiThue, bool) {
	for _, l := range DanhMucLoaiThue {
		if l.Ma == ma {
			return l, true
		}
	}

	return LoaiThue{}, false
}

// ChoChon cho biết một mức có nằm trong bộ chọn của loại không.
func (l LoaiThue) ChoChon(muc int) bool {
	for _, m := range l.ChonDuoc {
		if m == muc {
			return true
		}
	}

	return false
}

// TenMuc là chữ hiện lên màn hình cho một mức.
func TenMuc(muc int) string {
	switch muc {
	case MucKhongChiuThue:
		return "KCT"
	case MucKhongKeKhai:
		return "KKKNT"
	default:
		return strconv.Itoa(muc) + "%"
	}
}

// Thue — một dòng thuế của cửa hàng.
type Thue struct {
	ID uint `json:"id" gorm:"primaryKey"`
	TenantOwned
	// Loai khớp một mã trong DanhMucLoaiThue. Nơi dùng tra bằng cột này, KHÔNG
	// tra bằng id: id tự tăng nên mỗi cửa hàng một dãy khác nhau.
	Loai string `json:"loai" gorm:"column:tax_type"`
	// Muc là cột `rates` — JSON mảng số. Đọc/ghi qua DanhSachMuc/DatMuc.
	Muc       string    `json:"-" gorm:"column:rates"`
	IsActive  bool      `json:"is_active"`
	CreatedAt time.Time `json:"created_at"`
	UpdatedAt time.Time `json:"updated_at"`
}

func (Thue) TableName() string { return "taxes" }

// DanhSachMuc đọc cột rates ra mảng số; hỏng thì trả mảng rỗng chứ không nổ.
func (t Thue) DanhSachMuc() []int {
	var ds []int
	if err := json.Unmarshal([]byte(t.Muc), &ds); err != nil {
		return []int{}
	}

	return ds
}

// DatMuc ghi mảng số vào cột rates.
func (t *Thue) DatMuc(ds []int) {
	if ds == nil {
		ds = []int{}
	}
	b, _ := json.Marshal(ds)
	t.Muc = string(b)
}

// ErrLoaiThueLa — mã loại không có trong DanhMucLoaiThue.
var ErrLoaiThueLa = errors.New("loại thuế này không có trong danh mục")

// ErrMucThueLa — mức gửi lên không nằm trong bộ chọn của loại đó.
var ErrMucThueLa = errors.New("mức thuế không nằm trong bộ mức của loại này")

// ErrThueTrongRong — bỏ hết mức. Không cho, vì màn nghiệp vụ sẽ có một ô chọn
// rỗng: muốn thôi áp thuế thì tắt cả dòng, đó mới là câu nói rõ ý.
var ErrThueTrongRong = errors.New("phải giữ lại ít nhất một mức thuế")

// ThueRepository — sổ thuế suất của một cửa hàng.
type ThueRepository interface {
	// List trả về mọi dòng thuế của cửa hàng (cả bật lẫn tắt).
	List(ctx context.Context) ([]Thue, error)
	FindByID(ctx context.Context, id uint) (*Thue, error)
	// TheoLoai tra một dòng theo mã loại — đường mà màn nghiệp vụ dùng.
	TheoLoai(ctx context.Context, loai string) (*Thue, error)
	Luu(ctx context.Context, t *Thue) error
	// TaoThieu tạo những dòng cửa hàng chưa có, bỏ qua dòng đã có. Chạy khi mở
	// màn hình: mỗi cửa hàng là một tenant riêng nên không seed sẵn từ migration.
	TaoThieu(ctx context.Context, ds []Thue) error
}
