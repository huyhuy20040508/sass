package domain

import "strings"

// Sinh tên miền cho cửa hàng mới — QUY TẮC BÁN HÀNG, không phải mẹo xử lý chuỗi.
//
// Khách mua gói Chuỗi thì được cấp một địa chỉ riêng, và địa chỉ đó dựng từ TÊN
// CỬA HÀNG: "Quốc Huy" → quochuy.selliotech.store. Đặt ở gói domain vì cả công
// cụ dòng lệnh hôm nay lẫn luồng đăng ký sau này đều phải sinh ra ĐÚNG MỘT kết
// quả cho cùng một cái tên — hai chỗ tự cắt dấu theo cách riêng thì cùng một
// khách sẽ nhận hai địa chỉ khác nhau tuỳ đường nào tạo ra nó.
//
// KHÔNG dùng thư viện chuẩn hoá Unicode: bảng dưới đây phủ đúng chữ tiếng Việt,
// đọc được bằng mắt, và không kéo thêm một phụ thuộc chỉ để bỏ dấu.

// boDau — mỗi dòng là "chữ không dấu" và toàn bộ biến thể CÓ DẤU của nó.
//
// Chỉ liệt kê chữ thường: hàm bên dưới hạ chữ trước khi tra, nên viết hoa hay
// viết thường đều ra một kết quả.
var boDau = []struct {
	thay rune
	dau  string
}{
	{'a', "àáạảãâầấậẩẫăằắặẳẵ"},
	{'e', "èéẹẻẽêềếệểễ"},
	{'i', "ìíịỉĩ"},
	{'o', "òóọỏõôồốộổỗơờớợởỡ"},
	{'u', "ùúụủũưừứựửữ"},
	{'y', "ỳýỵỷỹ"},
	{'d', "đ"},
}

var bangBoDau = func() map[rune]rune {
	m := make(map[rune]rune, 128)
	for _, d := range boDau {
		for _, r := range d.dau {
			m[r] = d.thay
		}
	}

	return m
}()

// DoDaiNhanToiDa — cắt nhãn ở 30 ký tự.
//
// Nhãn DNS cho phép tới 63, nhưng 30 là độ dài của `tenants.code` và là thứ
// người ta còn đọc được qua điện thoại. Tên cửa hàng dài thì cắt cụt vẫn hơn là
// một địa chỉ không ai gõ nổi.
const DoDaiNhanToiDa = 30

// nhanDatTruoc — những nhãn KHÔNG được cấp cho khách.
//
// Hai nhóm: nhãn nền tảng đang dùng thật (order, admin, api, app, www) và nhãn
// hạ tầng hay phải dựng thêm sau này (mail, cdn, static, dev...). Cấp mất một
// trong số đó cho khách rồi thì đòi lại nghĩa là đổi địa chỉ của một cửa hàng
// đang chạy — việc mà khách đã in lên card, lên bao bì, gửi cho người mua.
//
// Thà chặn thừa vài nhãn còn hơn phải đi xin lại một nhãn đã cấp.
var nhanDatTruoc = map[string]bool{
	"order": true, "admin": true, "api": true, "app": true, "www": true,
	"mail": true, "smtp": true, "imap": true, "webmail": true,
	"ns1": true, "ns2": true, "dns": true, "mx": true,
	"cdn": true, "static": true, "assets": true, "img": true, "media": true,
	"dev": true, "test": true, "staging": true, "demo": true, "beta": true,
	"blog": true, "docs": true, "help": true, "support": true, "status": true,
	"saas": true, "store": true, "shop": true, "pay": true, "billing": true,
}

// NhanTenMienTuTen dựng NHÃN tên miền từ tên cửa hàng — phần đứng trước
// ".selliotech.store", không phải cả tên miền.
//
// Bốn bước, theo đúng thứ tự:
//
//  1. hạ chữ thường và bỏ dấu tiếng Việt;
//  2. BỎ HẲN khoảng trắng và mọi ký tự không phải a-z0-9 — "Quốc Huy" ra
//     "quochuy" chứ không phải "quoc-huy". Đây là cách mã cửa hàng đang được
//     đặt sẵn trong hệ thống, và một địa chỉ liền mạch thì đọc qua điện thoại
//     đỡ phải giải thích "có dấu gạch ngang";
//  3. cắt còn DoDaiNhanToiDa ký tự;
//  4. trả "" khi không rút được chữ cái nào (tên toàn ký hiệu, hoặc rỗng).
//
// Trả "" là kết quả HỢP LỆ và nơi gọi phải xử: lấy mã cửa hàng làm nhãn thay
// thế. Tự bịa ra một nhãn ngẫu nhiên ở đây thì khách nhận một địa chỉ không
// liên quan gì tới tên mình mà không ai biết vì sao.
func NhanTenMienTuTen(ten string) string {
	var b strings.Builder
	b.Grow(len(ten))

	for _, r := range strings.ToLower(strings.TrimSpace(ten)) {
		if thay, co := bangBoDau[r]; co {
			r = thay
		}
		if (r >= 'a' && r <= 'z') || (r >= '0' && r <= '9') {
			b.WriteRune(r)
		}
	}

	nhan := b.String()
	if len(nhan) > DoDaiNhanToiDa {
		nhan = nhan[:DoDaiNhanToiDa]
	}

	return nhan
}

// LaNhanDatTruoc cho biết nhãn có thuộc nhóm giữ lại cho nền tảng không.
//
// Nơi gọi xử nó GIỐNG HỆT trường hợp nhãn đã có chủ: đổi sang nhãn khác. Không
// báo lỗi bắt người ta đặt lại tên, vì khách tên "Order" không làm gì sai cả.
func LaNhanDatTruoc(nhan string) bool { return nhanDatTruoc[strings.ToLower(nhan)] }
