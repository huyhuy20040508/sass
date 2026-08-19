package domain

import "sort"

// DANH MỤC QUYỀN — nguồn sự thật DUY NHẤT về "trong phần mềm này có những quyền gì".
//
// Trước tệp này, quyền của một người là MỘT CỘT: `users.role_id` trỏ vào bảng
// `roles` với bốn vai trò ghi cứng trong code. Toàn bộ việc chặn nằm ở hai dòng
// trong router — nhóm `admin` và nhóm `manage`. Nghĩa là cửa hàng chỉ có hai
// mức: hoặc thấy tất cả, hoặc chỉ thấy quầy bán. Không giao được việc coi kho
// cho một thu ngân lâu năm mà không mở luôn cho họ lương của đồng nghiệp và giá
// vốn từng mặt hàng.
//
// Từ đây, quyền là một TẬP CHUỖI. Mỗi chuỗi là một việc cụ thể trên một màn
// hình cụ thể: `san-pham.xem`, `san-pham.xoa`, `ton-kho.gia-von`. Cửa hàng gom
// chúng thành NHÓM QUYỀN do chính họ đặt tên, và gán nhóm cho từng người.
//
// Vì sao danh mục nằm trong Go chứ không phải một bảng dưới database: danh sách
// này phải khớp từng chữ với những gì router thật sự chặn. Để nó dưới database
// là mở đường cho một hàng quyền không ứng với đường nào (không ai dùng được) —
// hoặc tệ hơn, một đường không ứng với hàng quyền nào (không ai chặn được).
// Ở đây, bài kiểm đối chiếu được cả hai chiều lúc build.

// Bốn việc chuẩn của một màn hình danh sách. Tách `xem` khỏi ba việc ghi là ranh
// giới đáng giá nhất: phần lớn người cần đọc hơn là cần sửa.
const (
	QuyenXem  = "xem"
	QuyenThem = "them"
	QuyenSua  = "sua"
	QuyenXoa  = "xoa"
)

// QuyenLe — một việc RIÊNG nằm ngoài bốn việc chuẩn, vì nó nguy hiểm hơn hẳn
// phần còn lại của cùng màn hình. Ví dụ: ai cũng có thể xem tồn kho, nhưng nhìn
// thấy giá vốn là biết cửa hàng lãi bao nhiêu trên mỗi món.
type QuyenLe struct {
	Ma  string `json:"ma"`
	Ten string `json:"ten"`
}

// MucQuyen — một màn hình / một đối tượng nghiệp vụ.
type MucQuyen struct {
	// Prefix đi vào chuỗi quyền: "san-pham" -> "san-pham.xem". Tiếng Việt không
	// dấu, gạch ngang — cùng lối đặt tên với đường dẫn của dự án.
	Prefix string `json:"prefix"`
	Ten    string `json:"ten"`
	// Viec là những việc chuẩn màn hình này có. KHÔNG phải màn hình nào cũng đủ
	// bốn: danh mục sản phẩm không có "xem" riêng vì ai đọc được sản phẩm thì
	// cũng đọc được danh mục của nó.
	Viec []string  `json:"viec"`
	Le   []QuyenLe `json:"le,omitempty"`
}

// NhomMucQuyen — một khối bên trong một khu, xếp theo đúng thứ tự của thanh điều
// hướng để người tick không phải đi tìm.
type NhomMucQuyen struct {
	Ten  string     `json:"ten"`
	Mucs []MucQuyen `json:"mucs"`
}

// KhuQuyen — MỘT KHU LÀM VIỆC của phần mềm, và là tầng trên cùng của cây quyền.
//
// Hai khu này chính là hai module người dùng đứng vào (xem ModuleLamViec bên
// trang quản trị): Quản trị và Thu ngân. Chia ra chứ không gom một mớ, vì cửa
// vào đứng TRÊN quyền — nhóm route `manage` đòi cửa `quan_ly` trước khi hỏi tới
// quyền, nên với người chỉ đứng quầy thì mọi ô ngoài khu Thu ngân là chữ chết.
// Gom chung thì màn Phân quyền không có cách nào nói ra điều đó, và chủ tiệm
// tick xong vẫn không hiểu vì sao người kia không mở được trang.
//
// Ma khớp `users.access_areas` (CuaQuanLy / CuaThuNgan): đó là thứ màn hình dựa
// vào để biết khu nào khoá cho ai.
type KhuQuyen struct {
	Ma   string         `json:"ma"`
	Ten  string         `json:"ten"`
	MoTa string         `json:"mo_ta"`
	Nhom []NhomMucQuyen `json:"nhom"`
}

// DanhMucQuyen — toàn bộ cây quyền, cũng chính là thứ màn hình phân quyền vẽ ra.
var DanhMucQuyen = []KhuQuyen{
	{
		Ma: CuaQuanLy, Ten: "Quản trị",
		MoTa: "Chỉ người được giao cửa Quản lý mới dùng được.",
		Nhom: []NhomMucQuyen{
			{
				Ten: "Bán hàng",
				Mucs: []MucQuyen{
					{
						Prefix: "tra-hang", Ten: "Trả hàng",
						Viec: []string{QuyenXem, QuyenThem, QuyenSua},
					},
				},
			},
			{
				Ten: "Hàng hoá",
				Mucs: []MucQuyen{
					{
						Prefix: "san-pham", Ten: "Sản phẩm",
						Viec: []string{QuyenXem, QuyenThem, QuyenSua, QuyenXoa},
					},
					{
						// Không có "xem" riêng: ai đọc được sản phẩm thì cũng đọc được
						// khung phân loại của nó, và đường đọc ấy vốn công khai.
						Prefix: "danh-muc", Ten: "Danh mục",
						Viec: []string{QuyenThem, QuyenSua, QuyenXoa},
					},
					{
						// Đứng giữa Danh mục và Thuế suất, đúng thứ tự của thanh
						// điều hướng — người tick không phải đi tìm.
						Prefix: "don-vi-tinh", Ten: "Đơn vị tính",
						Viec: []string{QuyenXem, QuyenThem, QuyenSua, QuyenXoa},
					},
					{
						// Tách "xem" khỏi "sửa": đổi bộ mức là đổi ô chọn thuế của
						// mọi phiếu lập sau đó, cả bên bán lẫn bên mua.
						Prefix: "thue", Ten: "Thuế suất",
						Viec: []string{QuyenXem, QuyenSua},
					},
				},
			},
			{
				Ten: "Marketing",
				Mucs: []MucQuyen{
					{
						Prefix: "khuyen-mai", Ten: "Khuyến mãi",
						Viec: []string{QuyenXem, QuyenThem, QuyenSua, QuyenXoa},
					},
					{
						Prefix: "ma-giam-gia", Ten: "Mã giảm giá",
						Viec: []string{QuyenXem, QuyenThem, QuyenSua, QuyenXoa},
					},
					{
						Prefix: "banner", Ten: "Banner trang chủ",
						Viec: []string{QuyenXem, QuyenThem, QuyenSua, QuyenXoa},
					},
					{
						Prefix: "lien-he", Ten: "Liên hệ",
						Viec: []string{QuyenXem, QuyenSua, QuyenXoa},
					},
					{
						Prefix: "nhan-tin", Ten: "Đăng ký nhận tin",
						Viec: []string{QuyenXem, QuyenSua},
					},
				},
			},
			{
				Ten: "Khách hàng",
				Mucs: []MucQuyen{
					{
						Prefix: "khach-hang", Ten: "Khách hàng",
						Viec: []string{QuyenXem, QuyenThem, QuyenSua, QuyenXoa},
					},
				},
			},
			{
				Ten: "Kho",
				Mucs: []MucQuyen{
					{
						Prefix: "ton-kho", Ten: "Tồn kho",
						Viec: []string{QuyenXem, QuyenSua},
						Le: []QuyenLe{
							// Giá vốn tách riêng: nhìn thấy nó là biết cửa hàng lãi bao
							// nhiêu trên mỗi món.
							{Ma: "gia-von", Ten: "Xem và sửa giá vốn"},
						},
					},
					{
						Prefix: "nha-cung-cap", Ten: "Nhà cung cấp",
						Viec: []string{QuyenXem, QuyenThem, QuyenSua, QuyenXoa},
					},
					{
						Prefix: "dat-hang-nhap", Ten: "Đặt hàng nhập",
						Viec: []string{QuyenXem, QuyenThem, QuyenSua, QuyenXoa},
					},
					{
						Prefix: "nhap-kho", Ten: "Nhập kho",
						Viec: []string{QuyenXem, QuyenThem},
					},
					{
						Prefix: "tra-hang-nhap", Ten: "Trả hàng nhập",
						Viec: []string{QuyenXem, QuyenThem, QuyenSua, QuyenXoa},
					},
				},
			},
			{
				Ten: "Báo cáo",
				Mucs: []MucQuyen{
					{
						Prefix: "bao-cao", Ten: "Báo cáo",
						Viec: []string{QuyenXem},
					},
				},
			},
			{
				Ten: "Hệ thống",
				Mucs: []MucQuyen{
					{
						Prefix: "nhan-su", Ten: "Nhân sự",
						Viec: []string{QuyenXem, QuyenThem, QuyenSua, QuyenXoa},
					},
					{
						Prefix: "tai-khoan", Ten: "Tài khoản đăng nhập",
						Viec: []string{QuyenXem, QuyenThem, QuyenSua, QuyenXoa},
					},
					{
						Prefix: "nhom-quyen", Ten: "Nhóm quyền",
						Viec: []string{QuyenXem, QuyenThem, QuyenSua, QuyenXoa},
					},
					{
						Prefix: "chi-nhanh", Ten: "Chi nhánh",
						Viec: []string{QuyenXem, QuyenThem, QuyenSua, QuyenXoa},
					},
					{
						Prefix: "cau-hinh", Ten: "Cấu hình cửa hàng",
						Viec: []string{QuyenSua},
					},
					{
						// Tách "xem" khỏi "sửa": mã chứng từ đã đi vào giấy tờ, đổi
						// quy tắc là đổi cả dải số của những phiếu lập sau đó.
						Prefix: "quy-tac-ma", Ten: "Quy tắc đánh số chứng từ",
						Viec: []string{QuyenXem, QuyenSua},
					},
				},
			},
		},
	},
	{
		Ma: CuaThuNgan, Ten: "Thu ngân",
		MoTa: "Việc ở quầy. Quản lý dùng chung đúng mấy quyền này, không có bản thứ hai.",
		Nhom: []NhomMucQuyen{
			{
				Ten: "Bán tại quầy",
				Mucs: []MucQuyen{
					{
						Prefix: "don-hang", Ten: "Đơn hàng",
						Viec: []string{QuyenXem, QuyenThem, QuyenSua},
						Le: []QuyenLe{
							{Ma: "doanh-thu", Ten: "Xem doanh thu"},
							{Ma: "doi-hang", Ten: "Đổi hàng tại quầy"},
						},
					},
					{
						Prefix: "ca-lam-viec", Ten: "Ca làm việc",
						Viec: []string{QuyenXem, QuyenThem},
					},
					{
						// Thu/chi ngoài đơn hàng — tiền mặt ra vào két giữa ca.
						Prefix: "so-quy", Ten: "Sổ quỹ",
						Viec: []string{QuyenThem},
					},
				},
			},
		},
	},
}

// TatCaQuyen trả về mọi chuỗi quyền, đã sắp xếp — dùng cho nhóm "Quản lý" và
// cho bài kiểm đối chiếu với router.
func TatCaQuyen() []string {
	ds := make([]string, 0, 96)
	for _, khu := range DanhMucQuyen {
		ds = append(ds, quyenTrongKhu(khu)...)
	}
	sort.Strings(ds)

	return ds
}

// quyenTrongKhu trải mọi chuỗi quyền của một khu.
func quyenTrongKhu(khu KhuQuyen) []string {
	ds := make([]string, 0, 32)
	for _, nhom := range khu.Nhom {
		for _, muc := range nhom.Mucs {
			for _, viec := range muc.Viec {
				ds = append(ds, muc.Prefix+"."+viec)
			}
			for _, le := range muc.Le {
				ds = append(ds, muc.Prefix+"."+le.Ma)
			}
		}
	}

	return ds
}

// tapQuyen dựng sẵn một lần lúc nạp gói: mọi lượt kiểm tra sau đó là tra map.
var tapQuyen = func() map[string]bool {
	m := make(map[string]bool, 96)
	for _, q := range TatCaQuyen() {
		m[q] = true
	}

	return m
}()

// QuyenHopLe cho biết chuỗi này có trong danh mục không.
//
// Đây là lượt chặn LỖI GÕ. Một chuỗi sai chính tả gắn trên route sẽ thành thứ
// không ai được cấp, tức là khoá cứng cả trang mà không báo lỗi gì — hỏng theo
// kiểu im lặng nhất.
func QuyenHopLe(quyen string) bool { return tapQuyen[quyen] }

// Mã hai nhóm quyền hệ thống tự dựng cho mỗi cửa hàng.
//
// Chúng chỉ là ĐIỂM XUẤT PHÁT, không phải giới hạn: cửa hàng sửa được nội dung
// của chúng và tạo thêm bao nhiêu nhóm tuỳ ý. Hai nhóm này có mặt để ngày
// chuyển sang phân quyền theo chức năng, không một ai mất hay được thêm quyền
// so với hôm trước.
const (
	NhomQuyenQuanLy  = "quan-ly"
	NhomQuyenThuNgan = "thu-ngan"
)

// QuyenThuNgan — đúng những gì vai trò `staff` mở được trước lượt đổi này: quầy
// bán, đơn hàng, ca làm việc và sổ quỹ. Không hàng hoá, không kho, không báo
// cáo, không hồ sơ đồng nghiệp.
//
// Danh sách này là BẢN GHI LẠI hành vi cũ. Đừng nới nó ra để tiện cho một cửa
// hàng cụ thể — cửa hàng đó tự sửa nhóm quyền của họ là được.
func QuyenThuNgan() []string {
	return []string{
		"don-hang.xem", "don-hang.them", "don-hang.sua", "don-hang.doi-hang",
		// Doanh thu CÓ trong danh sách, dù nghe như thứ chỉ chủ tiệm cần: đường
		// GET /orders/revenue hôm nay nằm ở nhóm `admin`, tức thu ngân đang gọi
		// được. Bỏ nó ra là lượt di trú lấy mất một thứ họ đang có.
		"don-hang.doanh-thu",
		"ca-lam-viec.xem", "ca-lam-viec.them",
		"so-quy.them",
	}
}
