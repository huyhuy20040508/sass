package apitest

import (
	"encoding/json"
	"net/http"
	"strconv"
	"testing"
)

// Thu ngân lập qua hộp Nhân sự phải BÁN ĐƯỢC ngay.
//
// Cửa vào (`users.access_areas`) và quyền theo chức năng (`user_permissions`) là
// hai sổ tách rời: tích "Thu ngân" mới chỉ mở khu quầy, còn từng đường trong đó
// lại đòi một quyền riêng. Hộp Nhân sự không tích được quyền, mà màn phân quyền
// chưa có bản v2 — nên trước lượt sửa này tài khoản mới ra đời với đúng 0 quyền:
// đăng nhập vào quầy được nhưng quét mã vạch nhận 403 (don-hang.xem) và bấm
// Thanh toán cũng 403 (don-hang.them), không có đường nào trên giao diện để chữa.
func TestThuNganMoiBanDuocNgay(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	tenDangNhap := "thungan.moi"
	res := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/nhan-su", map[string]any{
		"full_name": "Thu ngân mới " + a.vet, "status": "dang_lam", "shop_id": a.chiNhanh,
		"email": "thungan.moi." + a.vet + "@cua-hang-a.test", "role_id": 3,
		// Đúng kịch bản người dùng báo: tích Thu ngân, BỎ Quản lý.
		"quyen":     []string{"thu_ngan"},
		"tai_khoan": map[string]any{"username": tenDangNhap, "password": matKhauTest},
	})
	if res.ma != http.StatusCreated {
		t.Fatalf("thêm nhân sự kèm tài khoản phải trả 201, nhận %d\n%s", res.ma, catBot(res.than))
	}
	tao := docHoSo(t, res.than)
	if tao.Data.UserID == nil {
		t.Fatalf("hồ sơ chưa gắn tài khoản: %s", catBot(res.than))
	}

	// 1. Sổ quyền của tài khoản phải có đúng bộ bán hàng tối thiểu.
	res = h.goi(t, a.token, http.MethodGet,
		"/api/v1/admin/users/"+strconv.FormatUint(uint64(*tao.Data.UserID), 10)+"/quyen", nil)
	if res.ma != http.StatusOK {
		t.Fatalf("đọc quyền của tài khoản phải trả 200, nhận %d\n%s", res.ma, catBot(res.than))
	}
	var quyen struct {
		Data struct {
			ToanQuyen bool     `json:"toan_quyen"`
			Quyen     []string `json:"quyen"`
		} `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &quyen); err != nil {
		t.Fatalf("không đọc được danh sách quyền: %v\n%s", err, catBot(res.than))
	}
	co := map[string]bool{}
	for _, q := range quyen.Data.Quyen {
		co[q] = true
	}
	for _, can := range []string{"don-hang.xem", "don-hang.them"} {
		if !co[can] {
			t.Fatalf("thu ngân mới thiếu quyền %q — đã cấp: %v", can, quyen.Data.Quyen)
		}
	}
	if quyen.Data.ToanQuyen {
		t.Fatalf("thu ngân KHÔNG được mang cờ toàn quyền: %s", catBot(res.than))
	}

	// 2. Và thật sự đi lọt hai đường mà người dùng báo là 403.
	tokenThuNgan := h.dangNhapVoi(t, a.ma, tenDangNhap)
	for _, duong := range []string{
		"/api/v1/admin/orders/pos/scan?ma=KHONG-CO",
		"/api/v1/admin/orders/pos/discount-limit",
	} {
		res = h.goi(t, tokenThuNgan, http.MethodGet, duong, nil)
		if res.ma == http.StatusForbidden {
			t.Fatalf("%s vẫn chặn thu ngân bằng 403: %s", duong, catBot(res.than))
		}
	}

	// POST /orders/pos/ma-don — lượt giữ mã đơn trước khi thanh toán.
	res = h.goi(t, tokenThuNgan, http.MethodPost, "/api/v1/admin/orders/pos/ma-don", map[string]any{})
	if res.ma == http.StatusForbidden {
		t.Fatalf("giữ mã đơn vẫn chặn thu ngân bằng 403: %s", catBot(res.than))
	}
}

// Sửa hồ sơ KHÔNG được đặt lại quyền mà chủ tiệm đã tự chỉnh.
//
// Lượt gieo mặc định chỉ dành cho tài khoản còn trắng. Gieo đè thì mỗi lần sửa
// số điện thoại của nhân viên là quyền của họ âm thầm quay về bộ mặc định.
func TestSuaHoSoKhongDatLaiQuyenDaChinh(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	tenDangNhap := "thungan.giuquyen"
	res := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/nhan-su", map[string]any{
		"full_name": "Thu ngân giữ quyền " + a.vet, "status": "dang_lam", "shop_id": a.chiNhanh,
		"email": "thungan.giuquyen." + a.vet + "@cua-hang-a.test", "role_id": 3,
		"quyen":     []string{"thu_ngan"},
		"tai_khoan": map[string]any{"username": tenDangNhap, "password": matKhauTest},
	})
	if res.ma != http.StatusCreated {
		t.Fatalf("thêm nhân sự phải trả 201, nhận %d\n%s", res.ma, catBot(res.than))
	}
	tao := docHoSo(t, res.than)
	duongQuyen := "/api/v1/admin/users/" + strconv.FormatUint(uint64(*tao.Data.UserID), 10) + "/quyen"

	// Chủ tiệm thu hẹp lại còn đúng một quyền.
	res = h.goi(t, a.token, http.MethodPut, duongQuyen, map[string]any{"quyen": []string{"don-hang.xem"}})
	if res.ma != http.StatusOK {
		t.Fatalf("đặt quyền phải trả 200, nhận %d\n%s", res.ma, catBot(res.than))
	}

	// Rồi sửa hồ sơ, vẫn tích đúng cửa Thu ngân như cũ.
	res = h.goi(t, a.token, http.MethodPut,
		"/api/v1/admin/nhan-su/"+strconv.FormatUint(uint64(tao.Data.ID), 10), map[string]any{
			"full_name": "Thu ngân giữ quyền " + a.vet, "status": "dang_lam", "shop_id": a.chiNhanh,
			"email": "thungan.giuquyen." + a.vet + "@cua-hang-a.test", "role_id": 3,
			"quyen": []string{"thu_ngan"}, "phone": "0912345678",
		})
	if res.ma != http.StatusOK {
		t.Fatalf("sửa hồ sơ phải trả 200, nhận %d\n%s", res.ma, catBot(res.than))
	}

	res = h.goi(t, a.token, http.MethodGet, duongQuyen, nil)
	var quyen struct {
		Data struct {
			Quyen []string `json:"quyen"`
		} `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &quyen); err != nil {
		t.Fatalf("không đọc được quyền: %v\n%s", err, catBot(res.than))
	}
	if len(quyen.Data.Quyen) != 1 || quyen.Data.Quyen[0] != "don-hang.xem" {
		t.Fatalf("sửa hồ sơ đã đặt lại quyền: %v", quyen.Data.Quyen)
	}
}
