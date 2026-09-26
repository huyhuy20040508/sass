package apitest

import (
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"testing"
)

// Tên miền của hai cửa hàng giả. Không đăng ký thật ở đâu cả — chúng chỉ nằm
// trong sổ tên miền của control plane test và trong header Host của request.
const (
	hostA = "isoa.selliotech.test"
	hostB = "isob.selliotech.test"
)

// haiCuaHangBanHang dựng bản API có CỤM BÁN HÀNG CHO KHÁCH, hai cửa hàng giả và
// tên miền riêng của từng bên.
func haiCuaHangBanHang(t *testing.T) (*heThong, *cuaHang, *cuaHang) {
	t.Helper()

	h := dungHeThongBanHang(t)
	a, b := haiCuaHang(t, h)
	gieoTenMien(t, h, a, hostA)
	gieoTenMien(t, h, b, hostB)

	return h, a, b
}

// TestTenMien_KhachVangLaiVaoDungCuaHang — người CHƯA ĐĂNG NHẬP vào tên miền
// nào thì được phục vụ đúng cửa hàng ấy.
//
// Đây là điều kiện để cụm bán hàng cho khách tồn tại: trước khi có phân giải
// theo tên miền, cửa hàng chỉ đến từ token, nên khách vãng lai — tức gần như mọi
// người mua — nhận 401 ở mọi đường.
func TestTenMien_KhachVangLaiVaoDungCuaHang(t *testing.T) {
	h, a, b := haiCuaHangBanHang(t)

	xem := func(host string) string {
		t.Helper()
		res := h.goiTuHost(t, host, "", http.MethodGet, "/api/v1/products?page_size=100", nil)
		if res.ma != http.StatusOK {
			t.Fatalf("khách vãng lai vào %s phải xem được hàng, nhận %d\n%s", host, res.ma, catBot(res.than))
		}
		return res.than
	}

	thanA := xem(hostA)
	if !chuaDauVet(thanA, a.vet) {
		t.Fatalf("vào %s mà không thấy hàng của %s\n%s", hostA, a.ma, catBot(thanA))
	}
	if chuaDauVet(thanA, b.vet) {
		t.Fatalf("RÒ RỈ: vào %s lại thấy hàng của %s\n%s", hostA, b.ma, catBot(thanA))
	}

	thanB := xem(hostB)
	if !chuaDauVet(thanB, b.vet) {
		t.Fatalf("vào %s mà không thấy hàng của %s\n%s", hostB, b.ma, catBot(thanB))
	}
	if chuaDauVet(thanB, a.vet) {
		t.Fatalf("RÒ RỈ: vào %s lại thấy hàng của %s\n%s", hostB, a.ma, catBot(thanB))
	}

	// Tên miền chưa vào sổ vẫn phải hành xử y như trước khi có middleware này:
	// không đoán một cửa hàng nào cả.
	if res := h.goiTuHost(t, hostKhongCoTrongSo, "", http.MethodGet, "/api/v1/products", nil); res.ma != http.StatusUnauthorized {
		t.Fatalf("tên miền lạ + không token phải là 401, nhận %d\n%s", res.ma, catBot(res.than))
	}
}

// TestTenMien_TokenLechTenMienBiTuChoi — cầm token của tiệm A mà mở trang tiệm B
// thì bị từ chối, không phải được phục vụ bằng dữ liệu của A.
//
// Tình huống này xảy ra bình thường: một người mua ở hai tiệm cùng nền tảng, và
// token của tiệm mua trước vẫn còn trong trình duyệt. Nếu token thắng tên miền
// thì người đó đứng trên trang B, nhìn thấy giá và hàng của A — mọi thứ trên màn
// hình nói một đằng, dữ liệu trả về một nẻo.
func TestTenMien_TokenLechTenMienBiTuChoi(t *testing.T) {
	h, a, _ := haiCuaHangBanHang(t)

	if res := h.goiTuHost(t, hostB, a.token, http.MethodGet, "/api/v1/products", nil); res.ma != http.StatusUnauthorized {
		t.Fatalf("token của %s trên tên miền của cửa hàng kia phải bị từ chối 401, nhận %d\n%s",
			a.ma, res.ma, catBot(res.than))
	}

	// Đối chứng: đúng tên miền của mình thì vẫn đi qua bình thường.
	if res := h.goiTuHost(t, hostA, a.token, http.MethodGet, "/api/v1/products", nil); res.ma != http.StatusOK {
		t.Fatalf("token của %s trên đúng tên miền của mình phải 200, nhận %d\n%s", a.ma, res.ma, catBot(res.than))
	}

	// Tên miền chưa vào sổ: token vẫn là thứ quyết định — đây chính là đường mà
	// khu quản trị đang đi hôm nay, và nó không được đổi.
	if res := h.goiTuHost(t, hostKhongCoTrongSo, a.token, http.MethodGet, "/api/v1/products", nil); res.ma != http.StatusOK {
		t.Fatalf("tên miền lạ + token hợp lệ phải 200 như trước, nhận %d\n%s", res.ma, catBot(res.than))
	}
}

// TestTenMien_CuaHangBiKhoaThiDongTrang — cửa hàng ngừng trả tiền thì trang bán
// hàng của họ đóng, chứ không phải vẫn bán rồi tính tiền sau.
func TestTenMien_CuaHangBiKhoaThiDongTrang(t *testing.T) {
	h, a, b := haiCuaHangBanHang(t)

	datTrangThaiNenTang(t, h, b, "suspended")

	if res := h.goiTuHost(t, hostB, "", http.MethodGet, "/api/v1/products", nil); res.ma != http.StatusForbidden {
		t.Fatalf("cửa hàng bị khoá phải trả 403, nhận %d\n%s", res.ma, catBot(res.than))
	}
	// Cửa hàng bên cạnh không liên quan gì.
	if res := h.goiTuHost(t, hostA, "", http.MethodGet, "/api/v1/products", nil); res.ma != http.StatusOK {
		t.Fatalf("cửa hàng %s vẫn phải bán bình thường, nhận %d\n%s", a.ma, res.ma, catBot(res.than))
	}
}

// TestCoLapTenant_CumBanHangChoKhach — cùng phép thử của bảng chính, nhưng cho
// nhóm route mà TRƯỚC ĐỢT NÀY không kiểm được: đứng ở tên miền của cửa hàng A
// (khách vãng lai) rồi gọi bằng khoá của cửa hàng B.
//
// Nhóm này đáng ngờ hơn khu quản trị ở một điểm: khoá của nó là MÃ ĐỌC ĐƯỢC (mã
// đơn, mã giao dịch, mã giảm giá) chứ không phải id tự tăng, nên người ta không
// cần đoán — chỉ cần một tờ hoá đơn của cửa hàng khác.
func TestCoLapTenant_CumBanHangChoKhach(t *testing.T) {
	h, a, b := haiCuaHangBanHang(t)

	t.Run("GET /public/orders/lookup", func(t *testing.T) {
		duong := "/api/v1/public/orders/lookup?order_code=" + b.maDonHang
		if res := h.goiTuHost(t, hostA, "", http.MethodGet, duong, nil); res.ma != http.StatusNotFound {
			t.Fatalf("tra mã đơn của %s trên trang %s phải là 404, nhận %d\n%s",
				b.ma, a.ma, res.ma, catBot(res.than))
		}
		// Đối chứng: mã đơn của chính cửa hàng đó thì tra được.
		duong = "/api/v1/public/orders/lookup?order_code=" + a.maDonHang
		if res := h.goiTuHost(t, hostA, "", http.MethodGet, duong, nil); res.ma != http.StatusOK {
			t.Fatalf("đối chứng hỏng: tra mã đơn của chính mình nhận %d\n%s", res.ma, catBot(res.than))
		}
	})

	t.Run("GET /payments/{code}", func(t *testing.T) {
		if res := h.goiTuHost(t, hostA, "", http.MethodGet,
			"/api/v1/payments/"+b.maGiaoDich, nil); res.ma != http.StatusNotFound {
			t.Fatalf("tra mã giao dịch của %s trên trang %s phải là 404, nhận %d\n%s",
				b.ma, a.ma, res.ma, catBot(res.than))
		}
		// Đối chứng chỉ đòi KHÁC 404, không đòi 200: bộ test không khai khoá cổng
		// thanh toán nào (khai vào là mỗi lần chạy lại gọi ra máy chủ SePay thật),
		// nên mã của chính mình tìm thấy rồi vẫn dừng ở "cổng chưa cấu hình". Thế là
		// đủ để chứng minh lượt trên không phải 404 vì route hỏng.
		if res := h.goiTuHost(t, hostA, "", http.MethodGet,
			"/api/v1/payments/"+a.maGiaoDich, nil); res.ma == http.StatusNotFound {
			t.Fatalf("đối chứng hỏng: tra mã giao dịch của chính mình cũng nhận 404\n%s", catBot(res.than))
		}
	})

	t.Run("POST /vouchers/check", func(t *testing.T) {
		than := map[string]any{"code": "voucher-" + b.vet, "subtotal": 500000}
		res := h.goiTuHost(t, hostA, "", http.MethodPost, "/api/v1/vouchers/check", than)
		if res.ma >= 200 && res.ma < 300 {
			t.Fatalf("RÒ RỈ: dùng được mã giảm giá của %s trên trang %s\n%s", b.ma, a.ma, catBot(res.than))
		}

		than["code"] = "voucher-" + a.vet
		if res := h.goiTuHost(t, hostA, "", http.MethodPost, "/api/v1/vouchers/check", than); res.ma != http.StatusOK {
			t.Fatalf("đối chứng hỏng: mã của chính cửa hàng phải dùng được, nhận %d\n%s", res.ma, catBot(res.than))
		}
	})

	t.Run("GET /settings", func(t *testing.T) {
		res := h.goiTuHost(t, hostA, "", http.MethodGet, "/api/v1/settings", nil)
		if res.ma != http.StatusOK {
			t.Fatalf("cấu hình công khai phải trả 200, nhận %d\n%s", res.ma, catBot(res.than))
		}
		if chuaDauVet(res.than, b.vet) {
			t.Fatalf("RÒ RỈ: cấu hình công khai của %s có dấu vết của %s\n%s", a.ma, b.ma, catBot(res.than))
		}
		if !chuaDauVet(res.than, a.vet) {
			t.Fatalf("đối chứng hỏng: không thấy cấu hình của chính %s\n%s", a.ma, catBot(res.than))
		}
	})

	t.Run("GET /banners", func(t *testing.T) {
		res := h.goiTuHost(t, hostA, "", http.MethodGet, "/api/v1/banners", nil)
		if res.ma != http.StatusOK {
			t.Fatalf("banner công khai phải trả 200, nhận %d\n%s", res.ma, catBot(res.than))
		}
		if chuaDauVet(res.than, b.vet) {
			t.Fatalf("RÒ RỈ: banner của %s hiện trên trang %s\n%s", b.ma, a.ma, catBot(res.than))
		}
	})

	// Yêu cầu khách gửi lên phải rơi vào ĐÚNG cửa hàng của tên miền. Chiều này
	// không phải rò rỉ đọc mà là nhét dữ liệu nhầm nhà: form liên hệ của tiệm A
	// mà đơn hàng hỏi giá rơi vào hộp thư của tiệm B thì tiệm A mất khách và tiệm
	// B đọc được thông tin liên lạc của người không phải khách mình.
	t.Run("POST /contact-requests rơi đúng cửa hàng", func(t *testing.T) {
		than := map[string]any{
			"full_name": "Khách vãng lai gửi tới " + a.vet,
			"content":   "Nội dung gửi từ tên miền của " + a.vet,
		}
		if res := h.goiTuHost(t, hostA, "", http.MethodPost, "/api/v1/contact-requests", than); res.ma < 200 || res.ma >= 300 {
			t.Fatalf("gửi liên hệ từ tên miền của %s phải thành công, nhận %d\n%s", a.ma, res.ma, catBot(res.than))
		}

		// Cửa hàng B mở hộp thư của mình lên: không được thấy yêu cầu vừa gửi.
		res := h.goi(t, b.token, http.MethodGet, "/api/v1/admin/contact-requests?page_size=100", nil)
		if chuaDauVet(res.than, "gửi tới "+a.vet) {
			t.Fatalf("RÒ RỈ: yêu cầu gửi từ tên miền của %s lại nằm trong hộp thư của %s\n%s",
				a.ma, b.ma, catBot(res.than))
		}
		res = h.goi(t, a.token, http.MethodGet, "/api/v1/admin/contact-requests?page_size=100", nil)
		if !chuaDauVet(res.than, "gửi tới "+a.vet) {
			t.Fatalf("đối chứng hỏng: yêu cầu không rơi vào hộp thư của %s\n%s", a.ma, catBot(res.than))
		}
	})

	// Đơn hàng của CHÍNH KHÁCH đang đăng nhập. Nhóm này chỉ mở được từ đợt phân
	// giải tên miền: trước đó khách vãng lai không đăng nhập nổi để mà có token.
	t.Run("đơn của chính mình", func(t *testing.T) {
		token := h.dangNhapKhach(t, hostA, a)

		for _, x := range []struct {
			ten   string
			duong string
		}{
			{"GET /orders/me/{id}", fmt.Sprintf("/api/v1/orders/me/%d", b.donHang)},
			{"GET /orders/me/{id}/returnable", fmt.Sprintf("/api/v1/orders/me/%d/returnable", b.donGiao)},
			{"GET /returns/me/{id}", fmt.Sprintf("/api/v1/returns/me/%d", b.traHang)},
		} {
			if res := h.goiTuHost(t, hostA, token, http.MethodGet, x.duong, nil); res.ma != http.StatusNotFound {
				t.Errorf("%s với id của %s phải là 404, nhận %d\n%s", x.ten, b.ma, res.ma, catBot(res.than))
			}
		}

		// Đối chứng: đơn của chính khách đó thì xem được.
		if res := h.goiTuHost(t, hostA, token, http.MethodGet,
			fmt.Sprintf("/api/v1/orders/me/%d", a.donHang), nil); res.ma != http.StatusOK {
			t.Errorf("đối chứng hỏng: khách xem đơn của chính mình nhận %d\n%s", res.ma, catBot(res.than))
		}
	})
}

// dangNhapKhach đăng nhập bằng EMAIL — đường của người mua hàng, khác hẳn ba ô
// của Shop Admin.
//
// Chỉ gọi được từ một tên miền đã vào sổ: đường này nằm trong cụm khachLa, và
// nếu tên miền không phân giải ra cửa hàng nào thì API không biết phải tìm email
// đó trong sổ khách của tiệm nào.
func (h *heThong) dangNhapKhach(t *testing.T, host string, c *cuaHang) string {
	t.Helper()

	res := h.goiTuHost(t, host, "", http.MethodPost, "/api/v1/auth/login", map[string]any{
		"email":    "khach@" + c.vet + ".test",
		"password": matKhauTest,
	})
	if res.ma != http.StatusOK {
		t.Fatalf("khách của %s đăng nhập hỏng: %d %s", c.ma, res.ma, catBot(res.than))
	}

	var body struct {
		Data struct {
			AccessToken string `json:"access_token"`
		} `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &body); err != nil || body.Data.AccessToken == "" {
		t.Fatalf("không đọc được token của khách: %v — %s", err, catBot(res.than))
	}

	return body.Data.AccessToken
}

// TestTenMien_TenMienCuaPhanMemKhacKhongPhucVu — sổ tên miền là sổ của CẢ NỀN
// TẢNG, nên trong đó có cả địa chỉ của những phần mềm khác.
//
// Từ khi một khách mua được nhiều phần mềm (migration 0008/0009), tình huống
// này là chuyện bình thường: cùng một cửa hàng có `quochuy.selliotech.store`
// cho phần mềm bán hàng và một địa chỉ khác cho phần mềm bida. Tiến trình API
// này phục vụ ĐÚNG MỘT phần mềm (APP_CODE), và địa chỉ của phần mềm kia phải
// rơi vào nhánh "không tìm thấy" — không phải được phục vụ bằng dữ liệu bán
// hàng, vì khi đó khách gõ đúng địa chỉ của mình rồi nhìn thấy nhầm sản phẩm.
func TestTenMien_TenMienCuaPhanMemKhacKhongPhucVu(t *testing.T) {
	h, a, _ := haiCuaHangBanHang(t)

	const (
		appKhac  = "isobida"
		hostKhac = "bida.iso.test"
	)
	gieoAppKhac(t, h, appKhac)
	gieoTenMienCuaApp(t, h, a, hostKhac, appKhac)

	// Khách vãng lai vào địa chỉ của phần mềm KHÁC: không xác định được cửa hàng
	// cho API này, nên 401 y như một tên miền lạ.
	if res := h.goiTuHost(t, hostKhac, "", http.MethodGet, "/api/v1/products", nil); res.ma != http.StatusUnauthorized {
		t.Fatalf("tên miền của phần mềm khác phải KHÔNG phân giải được (401), nhận %d\n%s",
			res.ma, catBot(res.than))
	}

	// Đối chứng: CÙNG cửa hàng đó, địa chỉ của phần mềm này thì phục vụ bình
	// thường. Không có vế này thì bài trên vẫn xanh kể cả khi phân giải tên miền
	// hỏng hoàn toàn.
	res := h.goiTuHost(t, hostA, "", http.MethodGet, "/api/v1/products?page_size=100", nil)
	if res.ma != http.StatusOK {
		t.Fatalf("địa chỉ của chính phần mềm này phải phục vụ được, nhận %d\n%s", res.ma, catBot(res.than))
	}
	if !chuaDauVet(res.than, a.vet) {
		t.Fatalf("vào %s mà không thấy hàng của %s\n%s", hostA, a.ma, catBot(res.than))
	}
}

// gieoAppKhac thêm một phần mềm thứ hai vào danh mục của nền tảng.
//
// Dọn ngay khi bài kiểm xong: danh mục app là dữ liệu dùng chung của database
// test, để lại một dòng rác thì lần chạy sau đếm nhầm.
func gieoAppKhac(t *testing.T, h *heThong, ma string) {
	t.Helper()

	nen := context.Background()
	err := h.nenTang.WithContext(nen).Exec(
		`INSERT INTO apps (code, name, status, created_at, updated_at)
		 VALUES (?, ?, 'active', NOW(3), NOW(3))
		 ON DUPLICATE KEY UPDATE status = 'active'`, ma, "Phần mềm thử "+ma).Error
	if err != nil {
		t.Fatalf("không gieo được app %s: %v", ma, err)
	}

	t.Cleanup(func() {
		// tenant_domains trỏ vào apps bằng khoá ngoại, nên xoá tên miền trước.
		for _, cau := range []string{
			"DELETE d FROM tenant_domains d JOIN apps a ON a.id = d.app_id WHERE a.code = ?",
			"DELETE FROM apps WHERE code = ?",
		} {
			if err := h.nenTang.WithContext(nen).Exec(cau, ma).Error; err != nil {
				t.Fatalf("không dọn được app thử %s: %v", ma, err)
			}
		}
	})
}
