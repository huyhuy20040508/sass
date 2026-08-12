package apitest

import (
	"encoding/json"
	"net/http"
	"testing"
	"time"
)

// khoDoc là một đường TRẢ VỀ DANH SÁCH.
//
// Chèn id trên URL không bắt được loại rò rỉ ở đây: một câu truy vấn danh sách
// quên mệnh đề tenant vẫn trả 200 đúng như bình thường, chỉ là kèm thêm hàng của
// cửa hàng khác — không có mã lỗi nào để mà kiểm.
type khoDoc struct {
	nhom  string
	duong string
	// so là số dòng cửa hàng này PHẢI thấy. -1 = đường không trả meta phân trang.
	//
	// Kiểm cả con số chứ không chỉ kiểm dấu vết: một trang tổng quan đếm nhầm
	// sang đơn của khách khác thì thân phản hồi chẳng có chữ nào để mà dò.
	so int64
}

// ky là khoảng thời gian của các đường báo cáo: hôm qua → ngày mai.
//
// Không khai một khoảng thật rộng (2000 → 2099) được: service tự kẹp khoảng lại
// và giữ phần ĐUÔI, nên "cả thế kỷ" biến thành hai năm cuối cùng của nó và báo
// cáo trả về toàn số 0 — trông hệt như đã lọc đúng, mà thực ra chưa chạm dữ liệu.
var ky = "from=" + time.Now().AddDate(0, 0, -1).Format("2006-01-02") +
	"&to=" + time.Now().AddDate(0, 0, 1).Format("2006-01-02")

// Danh sách sản phẩm của khu quản trị KHÔNG có đường riêng: trang quản trị gọi
// chính /products (xem router.go) và nhận thêm giá vốn nhờ token.
var bangDanhSach = []khoDoc{
	// Ba đường này trả mảng thẳng, không có meta phân trang.
	{"catalog", "/api/v1/categories?page_size=100", -1},
	{"catalog", "/api/v1/brands?page_size=100", -1},
	{"catalog", "/api/v1/products?page_size=100", 1},

	{"thong-bao", "/api/v1/notifications?page_size=100", -1},

	{"banner", "/api/v1/admin/banners?page_size=100", -1},
	{"khuyen-mai", "/api/v1/admin/promotions?page_size=100", 1},
	{"voucher", "/api/v1/admin/vouchers?page_size=100", 1},
	{"khach-hang", "/api/v1/admin/customers?page_size=100", 1},
	{"don-hang", "/api/v1/admin/orders?page_size=100", 2},
	{"tra-hang", "/api/v1/admin/returns?page_size=100", 1},
	{"ton-kho", "/api/v1/admin/inventory?page_size=100", 1},
	{"nha-cung-cap", "/api/v1/admin/suppliers?page_size=100", -1},
	{"dat-hang-nhap", "/api/v1/admin/purchases?page_size=100", 2},
	{"dat-hang-nhap", "/api/v1/admin/purchases/variants", -1},
	{"nhap-hang", "/api/v1/admin/receipts?page_size=100", 1},
	{"tra-hang-nhap", "/api/v1/admin/purchase-returns?page_size=100", 1},
	{"lien-he", "/api/v1/admin/contact-requests?page_size=100", 1},
	{"nhan-tin", "/api/v1/admin/newsletter?page_size=100", 1},
	{"tai-khoan", "/api/v1/admin/users?page_size=100", 2},

	// Cấu hình: mỗi cửa hàng một bộ. Đây là bảng chứa SỐ TÀI KHOẢN NGÂN HÀNG, nên
	// lẫn ở đây nghĩa là email xác nhận đơn của cửa hàng này in số tài khoản của
	// cửa hàng kia.
	{"cau-hinh", "/api/v1/admin/settings", -1},

	{"ho-so", "/api/v1/admin/me", -1},

	// Báo cáo phơi giá vốn, lợi nhuận và chi tiêu của từng khách — nhóm dữ liệu
	// nhạy cảm nhất trong cả hệ thống.
	{"bao-cao", "/api/v1/admin/reports/products?" + ky, -1},
	{"bao-cao", "/api/v1/admin/reports/customers?" + ky, -1},
}

// bangSoLieu là các đường chỉ trả về CON SỐ (không có tên, không có dấu vết).
//
// Kiểm khác hẳn: so hai cửa hàng có dữ liệu gieo giống hệt nhau, nên mỗi bên
// phải thấy đúng phần của mình. Rò rỉ ở đây làm cả hai cùng thấy số gấp đôi —
// mà dò theo chuỗi thì không thể thấy được.
var bangSoLieu = []string{
	"/api/v1/admin/orders/stats",
	"/api/v1/admin/orders/revenue",
	"/api/v1/admin/returns/stats",
	"/api/v1/admin/customers/stats",
	"/api/v1/admin/inventory/stats",
	"/api/v1/admin/purchases/stats",
	"/api/v1/admin/purchase-returns/stats",
	"/api/v1/admin/receipts/stats",
	"/api/v1/admin/contact-requests/stats",
	"/api/v1/admin/promotions/stats",
	"/api/v1/admin/vouchers/stats",
	"/api/v1/admin/users/stats",
	"/api/v1/admin/reports/revenue?" + ky,
	"/api/v1/admin/reports/orders?" + ky,
}

// TestCoLapTenant_DanhSachKhongLotDuLieu gọi mọi đường danh sách bằng token của
// cửa hàng A và soi thân phản hồi tìm dấu vết của cửa hàng B.
//
// Mỗi dòng dữ liệu của một cửa hàng đều mang chuỗi "vet<mã>" trong tên/tiêu đề/
// email, nên chỉ cần một dòng lọt sang là tìm thấy — không phải bóc từng trường
// JSON của hai mươi kiểu phản hồi khác nhau.
func TestCoLapTenant_DanhSachKhongLotDuLieu(t *testing.T) {
	h := dungHeThong(t)
	a, b := haiCuaHang(t, h)

	for _, x := range bangDanhSach {
		t.Run(x.nhom+" GET "+x.duong, func(t *testing.T) {
			res := h.goi(t, a.token, http.MethodGet, x.duong, nil)
			if res.ma != http.StatusOK {
				t.Fatalf("phải trả 200, nhận %d\n%s", res.ma, catBot(res.than))
			}

			if chuaDauVet(res.than, b.vet) {
				t.Fatalf("RÒ RỈ: danh sách của cửa hàng %s có dữ liệu mang dấu vết của %s\n%s",
					a.ma, b.ma, catBot(res.than))
			}
			// Đối chứng: dấu vết của CHÍNH MÌNH phải có mặt. Không có nó thì một
			// đường trả danh sách rỗng (lọc sai, phân trang sai) cũng "sạch".
			if !chuaDauVet(res.than, a.vet) {
				t.Fatalf("đối chứng hỏng: không thấy dữ liệu của chính cửa hàng %s trong phản hồi\n%s",
					a.ma, catBot(res.than))
			}

			if x.so < 0 {
				return
			}
			if got := tongPhanTrang(t, res.than); got != x.so {
				t.Fatalf("tổng số dòng phải là %d (dữ liệu gieo cho một cửa hàng), nhận %d\n%s",
					x.so, got, catBot(res.than))
			}
		})
	}
}

// TestCoLapTenant_SoLieuTongHopKhongCongDon kiểm các đường CHỈ TRẢ VỀ CON SỐ.
//
// Cách kiểm khác hẳn phần trên vì không có chuỗi nào để dò: chụp lại số liệu của
// cửa hàng A, gieo thêm một bộ dữ liệu cho cửa hàng B, rồi chụp lại lần nữa. Số
// của A mà nhúc nhích nghĩa là nó đang cộng cả phần của B vào.
//
// So sánh nguyên văn thân phản hồi nên không phải biết hình dạng JSON của mười
// mấy trang thống kê khác nhau — và tự bắt được cả trường mới thêm sau này.
func TestCoLapTenant_SoLieuTongHopKhongCongDon(t *testing.T) {
	h := dungHeThong(t)
	a, b := haiCuaHang(t, h)

	truoc := make(map[string]string, len(bangSoLieu))
	for _, duong := range bangSoLieu {
		res := h.goi(t, a.token, http.MethodGet, duong, nil)
		if res.ma != http.StatusOK {
			t.Fatalf("%s phải trả 200, nhận %d\n%s", duong, res.ma, catBot(res.than))
		}
		truoc[duong] = res.than
	}

	boSung(t, h.db, b)

	// Đối chứng: chính B phải THẤY phần vừa thêm. Không có bước này thì boSung gieo
	// hụt (hoặc gieo vào chỗ không trang nào đếm) cũng làm cả bài kiểm xanh.
	var doi int
	for _, duong := range bangSoLieu {
		res := h.goi(t, b.token, http.MethodGet, duong, nil)
		if res.ma != http.StatusOK {
			t.Fatalf("%s (cửa hàng %s) phải trả 200, nhận %d", duong, b.ma, res.ma)
		}
		if res.than != truoc[duong] {
			doi++
		}
	}
	if doi == 0 {
		t.Fatal("đối chứng hỏng: gieo thêm cả một bộ dữ liệu mà không trang thống kê nào đổi số")
	}

	for _, duong := range bangSoLieu {
		t.Run("GET "+duong, func(t *testing.T) {
			res := h.goi(t, a.token, http.MethodGet, duong, nil)
			if res.ma != http.StatusOK {
				t.Fatalf("phải trả 200, nhận %d\n%s", res.ma, catBot(res.than))
			}
			if res.than != truoc[duong] {
				t.Fatalf("RÒ RỈ: số liệu của cửa hàng %s đổi sau khi %s gieo thêm dữ liệu\ntrước: %s\nsau:   %s",
					a.ma, b.ma, catBot(truoc[duong]), catBot(res.than))
			}
		})
	}
}

// tongPhanTrang đọc meta.total của phản hồi phân trang.
func tongPhanTrang(t *testing.T, than string) int64 {
	t.Helper()

	var body struct {
		Meta struct {
			Total int64 `json:"total"`
		} `json:"meta"`
	}
	if err := json.Unmarshal([]byte(than), &body); err != nil {
		t.Fatalf("không đọc được meta phân trang: %v\n%s", err, catBot(than))
	}
	return body.Meta.Total
}
