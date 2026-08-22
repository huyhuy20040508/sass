package domain

import (
	"context"
	"errors"
	"strconv"
	"strings"
	"time"
)

// QUY TẮC ĐÁNH SỐ CHỨNG TỪ — cửa hàng tự đặt mã cho từng loại chứng từ/danh mục.
//
// Có quy tắc đang bật = phần mềm tự sinh mã, ô mã ở màn nhập khoá lại. Không có
// = người dùng tự gõ, đúng như hiện nay.

// Phần giá trị đứng giữa tiền tố và hậu tố.
const (
	// PhanSoThuTu — số đếm tăng dần, đệm 0 cho đủ độ dài.
	PhanSoThuTu = "so-thu-tu"
	// PhanNgayThangNam — ddmmyyyy rồi tới số đếm.
	PhanNgayThangNam = "ngay-thang-nam"
	// PhanThangNam — mmyy rồi tới số đếm.
	PhanThangNam = "thang-nam"
)

// Giới hạn độ dài phần giữa. Tối thiểu 3 để còn chỗ cho số đếm sau chuỗi ngày.
const (
	DoDaiMaToiThieu = 3
	DoDaiMaToiDa    = 20
)

// Mã loại — hằng số để nơi sinh mã không phải gõ chuỗi bằng tay.
const (
	LoaiHangHoa      = "hang-hoa"
	LoaiNhomHangHoa  = "nhom-hang-hoa"
	LoaiDonViTinh    = "don-vi-tinh"
	LoaiThuocTinh    = "thuoc-tinh"
	LoaiViTri        = "vi-tri"
	LoaiChiNhanh     = "chi-nhanh"
	LoaiNhanVien     = "nhan-vien"
	LoaiDonHang      = "don-hang"
	LoaiPhieuDatMua  = "phieu-dat-mua"
	LoaiTraHangNCC   = "tra-hang-ncc"
	LoaiTraHangKhach = "tra-hang-khach"
)

// LoaiMa — một loại chứng từ / danh mục đánh số được.
type LoaiMa struct {
	Ma  string `json:"ma"`
	Ten string `json:"ten"`
	// DungChung = true: một quy tắc cho cả cửa hàng (shop_id = 0). Hàng hoá hay
	// nhân viên là dữ liệu mọi chi nhánh cùng tra, hai chi nhánh đặt hai tiền tố
	// khác nhau thì mã của cùng một bảng nhảy loạn.
	DungChung bool `json:"dung_chung"`
	// BatTatDuoc = màn cấu hình có bày ô tick bật/tắt cho loại này không.
	//
	// Chỉ ba loại có: hàng hoá, nhà cung cấp, nhân viên. Đó là những chỗ mã do
	// người dùng GÕ TAY khi không bật quy tắc — tắt quy tắc là trả ô mã lại cho
	// họ (hàng hoá tắt rồi mà bỏ trống SKU thì API báo ErrSKUBatBuoc).
	//
	// Những loại còn lại KHÔNG có ô tick và luôn nằm sẵn trong bảng quy tắc:
	// nhóm hàng hoá, thuộc tính, đơn vị tính, vị trí bỏ trống mã là phần mềm đặt
	// hộ theo dải sẵn có (NH001, TT001, DV001, VT001…), còn chứng từ thì vốn tự
	// sinh mã.
	// Ở những chỗ ấy quy tắc chỉ đổi HÌNH DẠNG mã, không bật/tắt việc sinh mã —
	// bày một ô tick không tắt được cái gì chỉ khiến người dùng tưởng tắt xong
	// là được gõ tay.
	BatTatDuoc bool `json:"bat_tat_duoc"`
	// TienToGoiY điền sẵn vào ô lúc người dùng vừa bật quy tắc.
	TienToGoiY string `json:"tien_to_goi_y"`
}

// DanhMucLoaiMa — danh sách loại đánh số được, và là nguồn sự thật duy nhất.
//
// CHỈ khai những thứ hệ thống này thật sự có màn hình và có cột mã. Bày ra một
// loại chưa tồn tại thì người dùng cấu hình xong ngồi chờ một thứ không bao giờ
// chạy.
var DanhMucLoaiMa = []LoaiMa{
	// Danh mục — dùng chung toàn cửa hàng. Chỉ ba loại có ô tick bật/tắt (xem
	// LoaiMa.BatTatDuoc); ba loại còn lại luôn nằm sẵn trong bảng quy tắc.
	{Ma: LoaiHangHoa, Ten: "Hàng hóa", DungChung: true, BatTatDuoc: true, TienToGoiY: "HH"},
	{Ma: LoaiNhomHangHoa, Ten: "Nhóm hàng hóa", DungChung: true, TienToGoiY: "NH"},
	{Ma: LoaiThuocTinh, Ten: "Thuộc tính", DungChung: true, TienToGoiY: "TT"},
	{Ma: LoaiDonViTinh, Ten: "Đơn vị tính", DungChung: true, TienToGoiY: "DV"},
	{Ma: LoaiViTri, Ten: "Vị trí", DungChung: true, TienToGoiY: "VT"},
	// Mã chi nhánh chỉ nhận chữ THƯỜNG (nó đi vào đường dẫn), nên tiền tố gợi ý
	// viết thường và mã sinh ra được hạ chữ trước khi ghi — xem chiNhanhService.
	{Ma: LoaiChiNhanh, Ten: "Chi nhánh", DungChung: true, TienToGoiY: "cn"},
	{Ma: LoaiNhanVien, Ten: "Nhân viên", DungChung: true, BatTatDuoc: true, TienToGoiY: "NV"},

	// Chứng từ — theo từng chi nhánh, vì mã nói ra phiếu phát sinh ở đâu.
	{Ma: LoaiDonHang, Ten: "Đơn hàng", TienToGoiY: "DH"},
	{Ma: LoaiPhieuDatMua, Ten: "Phiếu đặt mua hàng", TienToGoiY: "PO"},
	{Ma: LoaiTraHangNCC, Ten: "Trả hàng nhà cung cấp", TienToGoiY: "TNC"},
	{Ma: LoaiTraHangKhach, Ten: "Khách trả hàng", TienToGoiY: "TKH"},
}

// TimLoaiMa tra một loại theo mã.
func TimLoaiMa(ma string) (LoaiMa, bool) {
	for _, l := range DanhMucLoaiMa {
		if l.Ma == ma {
			return l, true
		}
	}

	return LoaiMa{}, false
}

// PhanGiaTriHopLe cho biết chuỗi có phải một phần giá trị đã khai không.
func PhanGiaTriHopLe(phan string) bool {
	switch phan {
	case PhanSoThuTu, PhanNgayThangNam, PhanThangNam:
		return true
	default:
		return false
	}
}

// QuyTacMa — quy tắc đang lưu của một loại, trong một phạm vi.
type QuyTacMa struct {
	ID uint `json:"id" gorm:"primaryKey"`
	TenantOwned
	// ShopID = 0: dùng chung toàn cửa hàng. Khác 0: chỉ chi nhánh đó.
	ShopID    uint      `json:"shop_id"`
	DocType   string    `json:"doc_type"`
	Prefix    string    `json:"prefix"`
	ValuePart string    `json:"value_part"`
	Length    int       `json:"length"`
	Suffix    string    `json:"suffix"`
	IsActive  bool      `json:"is_active"`
	CreatedAt time.Time `json:"created_at"`
	UpdatedAt time.Time `json:"updated_at"`
}

func (QuyTacMa) TableName() string { return "code_rules" }

// Moc trả về chuỗi mốc thời gian của quy tắc — cũng là khoá bộ đếm: rỗng nghĩa
// là đếm liên tục, còn kiểu ngày/tháng thì mỗi mốc đếm lại từ 1.
func (q QuyTacMa) Moc(moc time.Time) string {
	switch q.ValuePart {
	case PhanNgayThangNam:
		return moc.Format("02012006")
	case PhanThangNam:
		return moc.Format("0106")
	default:
		return ""
	}
}

// MaMau dựng mã thứ `stt` theo quy tắc — thứ màn hình cấu hình xem trước, và
// cũng là công thức bộ sinh mã thật phải theo.
//
// Phần giữa luôn dài đúng Length: kiểu ngày ăn trước một số ký tự, số đếm lấy
// phần còn lại. Số đếm tràn chỗ thì mã dài ra chứ không bị cắt — cắt là sinh
// trùng.
func (q QuyTacMa) MaMau(stt int, moc time.Time) string {
	if stt < 1 {
		stt = 1
	}

	ngay := q.Moc(moc)

	con := q.Length - len(ngay)
	if con < 1 {
		con = 1
	}

	so := strconv.Itoa(stt)
	if len(so) < con {
		so = strings.Repeat("0", con-len(so)) + so
	}

	return q.Prefix + ngay + so + q.Suffix
}

// ErrLoaiMaLa — mã loại không có trong DanhMucLoaiMa.
var ErrLoaiMaLa = errors.New("loại chứng từ này không có trong danh mục đánh số")

// ErrPhanGiaTriLa — phần giá trị không phải một trong ba kiểu đã khai.
var ErrPhanGiaTriLa = errors.New("phần giá trị chỉ nhận số thứ tự, ngày tháng năm hoặc tháng năm")

// ErrTienToLa — tiền tố/hậu tố có dấu, khoảng trắng hoặc ký tự lạ.
var ErrTienToLa = errors.New("tiền tố và hậu tố chỉ gồm chữ không dấu, số, gạch ngang hoặc gạch dưới")

// ErrDoDaiMaLa — độ dài phần giữa nằm ngoài khoảng cho phép.
var ErrDoDaiMaLa = errors.New("số ký tự phần giá trị phải từ " +
	strconv.Itoa(DoDaiMaToiThieu) + " đến " + strconv.Itoa(DoDaiMaToiDa))

// ErrHetSoDe — dò hết dải số mà mã nào cũng đã có người dùng.
//
// Chỉ xảy ra khi cửa hàng bật quy tắc chồng lên một dải mã cũ. Không sinh bừa
// một mã trùng: đổi tiền tố là việc của người cấu hình, không phải của máy.
var ErrHetSoDe = errors.New("mã sinh theo quy tắc đang trùng với mã đã có — đổi tiền tố hoặc hậu tố rồi thử lại")

// ErrSKUBatBuoc — thêm hàng hoá mà bỏ trống mã trong khi cửa hàng CHƯA bật quy
// tắc mã hàng hoá. Hai lối ra, và người dùng phải tự chọn: gõ mã, hoặc bật quy
// tắc ở Cài đặt → Thông số chung.
var ErrSKUBatBuoc = errors.New("chưa bật quy tắc mã hàng hoá nên phải tự nhập mã")

// QuyTacMaRepository — sổ quy tắc của một cửa hàng.
type QuyTacMaRepository interface {
	// List trả về MỌI quy tắc của cửa hàng (cả bật lẫn tắt, cả chi nhánh lẫn dùng
	// chung): màn cấu hình vẽ một lượt rồi tự đổi chi nhánh, khỏi đọc lại.
	List(ctx context.Context) ([]QuyTacMa, error)
	// DangBat trả về quy tắc đang bật của một loại: đúng chi nhánh, hoặc phạm vi
	// dùng chung (shopID = 0). Không có = loại đó chưa bật.
	DangBat(ctx context.Context, docType string, shopID uint) (*QuyTacMa, error)
	// SinhMa cấp mã KẾ TIẾP cho một loại, hoặc "" nếu loại đó chưa bật quy tắc —
	// khi đó nơi gọi giữ nguyên cách đặt mã sẵn có. daCo (có thể nil) hỏi bảng
	// đích xem mã vừa dựng đã có ai dùng chưa, để bật quy tắc giữa chừng không
	// đè lên mã cũ.
	SinhMa(ctx context.Context, docType string, shopID uint, daCo func(ma string) (bool, error)) (string, error)
	// Luu ghi đè quy tắc của các phạm vi trong `phamVi`: hàng có trong danh sách
	// thì tạo/sửa và bật, hàng thuộc phạm vi đó mà không gửi lên thì tắt.
	Luu(ctx context.Context, phamVi []uint, ds []QuyTacMa) error
}
