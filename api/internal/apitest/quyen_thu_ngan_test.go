package apitest

import (
	"fmt"
	"net/http"
	"testing"
)

// Bài kiểm RANH GIỚI QUYỀN CỦA THU NGÂN.
//
// Vì sao phải có: hai nhóm route của khu quản trị (`admin` cho thu ngân, `manage`
// cho chủ tiệm) được phân biệt bằng ĐÚNG MỘT TỪ ở đầu mỗi dòng đăng ký route, và
// cả trăm dòng ấy nằm chung trong một cặp ngoặc { } chẳng tạo ra phạm vi quyền
// nào. Gõ `admin.` thay vì `manage.` cho một đường mới là mở nó cho toàn bộ nhân
// viên — không lỗi biên dịch, không cảnh báo, và không bài kiểm nào khác đỏ lên
// vì mọi bài còn lại đều gọi bằng token quản trị.
//
// Nên bài này gọi bằng token NHÂN VIÊN và soi cả hai chiều: đường phải đóng thì
// đóng, và đường của quầy thì phải còn mở. Chỉ kiểm một chiều là nửa kia âm thầm
// hỏng — siết nhầm cả màn hình bán hàng cũng là một cách làm cửa hàng ngừng bán.

// duong là một lượt gọi thử kèm điều mong đợi.
type duong struct {
	ten    string
	method string
	duong  string
	than   map[string]any
}

// TestThuNgan_ChiVaoDuocQuayBan — nhân viên gọi được cụm quầy và KHÔNG gọi được
// gì ngoài nó.
func TestThuNgan_ChiVaoDuocQuayBan(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	// Token của tài khoản `nhanvien` — vai trò staff, do gieo() dựng sẵn trong
	// chính cửa hàng này. Cùng cửa hàng với token quản trị a.token, nên mọi khác
	// biệt bên dưới chỉ có thể đến từ vai trò.
	thuNgan := h.dangNhapVoi(t, a.ma, "nhanvien")

	// --- Chiều CẤM ---------------------------------------------------------
	//
	// Mỗi dòng là một nhóm màn hình của khu quản trị. Lấy một đường đại diện cho
	// mỗi nhóm chứ không liệt kê đủ 115 đường: thứ hỏng trong thực tế là cả một
	// nhóm bị đặt nhầm tầng, không phải một động từ lẻ trong nhóm đã đúng.
	cam := []duong{
		// Hai đường của câu hỏi gốc: tạo sản phẩm là việc của chủ tiệm.
		{"tạo sản phẩm", http.MethodPost, "/api/v1/admin/products", map[string]any{
			"name": "Hàng do thu ngân tạo", "category_id": a.danhMuc, "base_price": 1000,
		}},
		// Đường ĐỌC của sản phẩm cũng đóng, và đó là chủ ý: nó trả kèm giá vốn.
		{"chi tiết sản phẩm", http.MethodGet, fmt.Sprintf("/api/v1/admin/products/%d", a.sanPham), nil},
		{"tạo danh mục", http.MethodPost, "/api/v1/admin/categories", map[string]any{"name": "Danh mục lạ"}},
		{"danh sách banner", http.MethodGet, "/api/v1/admin/banners", nil},
		{"danh sách khuyến mãi", http.MethodGet, "/api/v1/admin/promotions", nil},
		{"danh sách voucher", http.MethodGet, "/api/v1/admin/vouchers", nil},

		// Kho: xem tồn và nhất là SỬA tồn / khai giá vốn.
		{"danh sách tồn kho", http.MethodGet, "/api/v1/admin/inventory", nil},
		{"khai giá vốn", http.MethodPut, "/api/v1/admin/inventory/cost", map[string]any{
			"product_variant_id": a.bienThe, "cost_price": 1,
		}},

		// Trả hàng — tiền ra khỏi két cho một đơn đã thu.
		{"danh sách trả hàng", http.MethodGet, "/api/v1/admin/returns", nil},
		{"món còn trả được của đơn", http.MethodGet,
			fmt.Sprintf("/api/v1/admin/orders/%d/returnable", a.donGiao), nil},

		// Chiều mua vào.
		{"đặt hàng nhập", http.MethodGet, "/api/v1/admin/purchases", nil},
		{"trả hàng nhập", http.MethodGet, "/api/v1/admin/purchase-returns", nil},
		{"nhập hàng", http.MethodGet, "/api/v1/admin/receipts", nil},

		// Hộp thư khách + danh sách nhận tin: thông tin cá nhân của người lạ.
		{"yêu cầu của khách", http.MethodGet, "/api/v1/admin/contact-requests", nil},
		{"danh sách nhận tin", http.MethodGet, "/api/v1/admin/newsletter", nil},

		// Bốn nhóm dưới đây vốn đã đóng từ trước. Giữ lại trong bảng để một lượt
		// nới quyền sau này không lặng lẽ mở lại chúng.
		{"hồ sơ khách hàng", http.MethodGet, "/api/v1/admin/customers", nil},
		{"tài khoản nội bộ", http.MethodGet, "/api/v1/admin/users", nil},
		{"chi nhánh", http.MethodGet, "/api/v1/admin/chi-nhanh", nil},
		{"báo cáo doanh thu", http.MethodGet, "/api/v1/admin/reports/revenue", nil},
		{"ghi cấu hình", http.MethodPut, "/api/v1/admin/settings", map[string]any{
			"settings": map[string]any{"site_name": "Tên do thu ngân đặt"},
		}},
	}

	for _, d := range cam {
		res := h.goi(t, thuNgan, d.method, d.duong, d.than)
		if res.ma != http.StatusForbidden {
			t.Errorf("thu ngân gọi %s (%s %s) phải nhận 403, nhận %d\n%s",
				d.ten, d.method, d.duong, res.ma, catBot(res.than))
		}
	}

	// --- Chiều MỞ ----------------------------------------------------------
	//
	// So với 403 chứ không so với 200: mấy đường này có thể trả 400/404 vì lý do
	// nghiệp vụ (chưa mở ca, chưa quét mã nào) mà vẫn là "vào được". Thứ đang
	// kiểm là cánh cửa, không phải nội dung sau cánh cửa. Riêng lượt bán tại quầy
	// thì kiểm hẳn kết quả — xem ngay dưới.
	mo := []duong{
		{"hồ sơ của chính mình", http.MethodGet, "/api/v1/admin/me", nil},
		{"đọc cấu hình", http.MethodGet, "/api/v1/admin/settings", nil},
		{"danh sách đơn", http.MethodGet, "/api/v1/admin/orders", nil},
		{"chi tiết đơn", http.MethodGet, fmt.Sprintf("/api/v1/admin/orders/%d", a.donHang), nil},
		{"quét mã ở quầy", http.MethodGet, "/api/v1/admin/orders/pos/scan?code=sku-" + a.vet + "-m", nil},
		{"hạn mức bớt giá ở quầy", http.MethodGet, "/api/v1/admin/orders/pos/discount-limit", nil},
		{"ca đang trực", http.MethodGet, "/api/v1/admin/ca-lam-viec/hien-tai", nil},
		{"danh sách ca", http.MethodGet, "/api/v1/admin/ca-lam-viec", nil},
	}

	for _, d := range mo {
		res := h.goi(t, thuNgan, d.method, d.duong, d.than)
		if res.ma == http.StatusForbidden {
			t.Errorf("thu ngân phải gọi được %s (%s %s), đang nhận 403\n%s",
				d.ten, d.method, d.duong, catBot(res.than))
		}
	}

	// --- Việc chính của quầy: bán được một lượt hàng thật -------------------
	//
	// Kiểm 201 chứ không chỉ "khác 403": cả cụm quyền này tồn tại để người đứng
	// quầy thu được tiền, nên chốt cuối phải là một lượt bán đi trọn, do CHÍNH
	// token nhân viên gọi.
	res := h.goi(t, thuNgan, http.MethodPost, "/api/v1/admin/orders/pos", map[string]any{
		"payment_method": "cash",
		"customer_name":  "Khách lẻ",
		"items": []map[string]any{
			{"product_variant_id": a.bienThe, "quantity": 1},
		},
	})
	if res.ma != http.StatusCreated {
		t.Fatalf("thu ngân bán tại quầy phải trả 201, nhận %d\n%s", res.ma, catBot(res.than))
	}
}
