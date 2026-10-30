package apitest

import (
	"fmt"
	"net/http"
	"strings"
	"testing"
)

// Khách đăng ký trên WEB BÁN HÀNG thì POS phải có ngay hồ sơ khách ấy — web giữ
// mật khẩu và phiên, POS giữ hồ sơ. Xem handler.WebKhachHandler.

const duongWebKhach = "/api/v1/web-ban/khach"

// sdtTest dựng số điện thoại riêng cho mỗi cửa hàng test: vết của tiệm chỉ có
// chữ và số, nên lấy phần số rồi đệm cho đủ — số trùng nhau giữa hai tiệm thì
// bài kiểm "mỗi tiệm một sổ khách" không còn nói lên điều gì.
func sdtTest(dau, vet string) string {
	so := make([]rune, 0, 8)
	for _, r := range vet {
		if r >= '0' && r <= '9' {
			so = append(so, r)
		}
	}
	for _, r := range fmt.Sprintf("%08d", len(vet)*7919) {
		if len(so) >= 8 {
			break
		}
		so = append(so, r)
	}

	return dau + string(so[:8])
}

// capKhoaWeb cấp một khoá tích hợp cho cửa hàng, như lúc chủ tiệm bấm "Kết nối"
// ở trang quản trị web.
func capKhoaWeb(t *testing.T, h *heThong, c *cuaHang) string {
	t.Helper()
	r := h.goi(t, c.token, http.MethodPost, "/api/v1/admin/tich-hop/web-ban-hang", nil)
	if r.ma != http.StatusCreated {
		t.Fatalf("cấp khoá trả %d\n%s", r.ma, catBot(r.than))
	}
	khoa, _ := doc(t, r)["khoa"].(string)
	if khoa == "" {
		t.Fatal("không nhận được khoá")
	}

	return khoa
}

func TestWebKhach_DangKyTaoKhachTrongPOS(t *testing.T) {
	h := dungHeThong(t)
	a, b := haiCuaHang(t, h)
	khoaA := capKhoaWeb(t, h, a)
	sdt := sdtTest("09", a.vet)

	than := map[string]any{"full_name": "Khach Web " + a.vet, "phone": sdt, "email": "web" + a.vet + "@test.local"}
	r := h.goiVoiHeader(t, "", http.MethodPost, duongWebKhach, than, map[string]string{"X-Integration-Key": khoaA})
	if r.ma != http.StatusCreated {
		t.Fatalf("đăng ký trả %d\n%s", r.ma, catBot(r.than))
	}
	kq := doc(t, r)
	if kq["moi"] != true {
		t.Fatalf("khách mới phải có moi=true: %+v", kq)
	}
	id, _ := kq["id"].(float64)
	if id == 0 {
		t.Fatalf("không trả id khách: %+v", kq)
	}

	// Hồ sơ phải thấy được ở màn Khách hàng của chính tiệm đó.
	ds := h.goi(t, a.token, http.MethodGet, "/api/v1/admin/customers?keyword="+sdt, nil)
	if ds.ma != http.StatusOK || !strings.Contains(ds.than, "Khach Web "+a.vet) {
		t.Fatalf("POS không thấy khách vừa đăng ký: %d\n%s", ds.ma, catBot(ds.than))
	}

	// Tiệm B không được thấy khách của tiệm A.
	dsB := h.goi(t, b.token, http.MethodGet, "/api/v1/admin/customers?keyword="+sdt, nil)
	if strings.Contains(dsB.than, "Khach Web "+a.vet) {
		t.Fatalf("khách của tiệm A lọt sang tiệm B:\n%s", catBot(dsB.than))
	}
}

func TestWebKhach_SoDienThoaiDaCoThiDungHoSoCu(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	khoa := capKhoaWeb(t, h, a)
	sdt := sdtTest("08", a.vet)

	// Khách quen đã mua tại quầy: quầy khai hồ sơ trước.
	tao := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/customers", map[string]any{
		"full_name": "Khach Quay " + a.vet, "phone": sdt, "status": "active",
	})
	if tao.ma != http.StatusCreated {
		t.Fatalf("tạo khách tại quầy trả %d\n%s", tao.ma, catBot(tao.than))
	}
	idQuay, _ := doc(t, tao)["id"].(float64)

	// Người ấy mở tài khoản web bằng cùng số điện thoại: KHÔNG đẻ hồ sơ thứ hai.
	r := h.goiVoiHeader(t, "", http.MethodPost, duongWebKhach,
		map[string]any{"full_name": "Khach Web " + a.vet, "phone": sdt},
		map[string]string{"X-Integration-Key": khoa})
	if r.ma != http.StatusOK {
		t.Fatalf("đăng ký trùng số trả %d\n%s", r.ma, catBot(r.than))
	}
	kq := doc(t, r)
	if kq["moi"] != false {
		t.Fatalf("số đã có phải trả moi=false: %+v", kq)
	}
	if id, _ := kq["id"].(float64); id != idQuay {
		t.Fatalf("phải trả đúng hồ sơ cũ %v, nhận %v", idQuay, kq["id"])
	}
}

func TestWebKhach_KhongCoKhoaThiDong(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	than := map[string]any{"full_name": "Khong khoa", "phone": sdtTest("07", a.vet)}

	// Không gửi khoá nào: khách vãng lai mở thẳng URL.
	if r := h.goi(t, "", http.MethodPost, duongWebKhach, than); r.ma != http.StatusUnauthorized {
		t.Fatalf("thiếu khoá phải 401, nhận %d\n%s", r.ma, catBot(r.than))
	}
	// Khoá bịa.
	r := h.goiVoiHeader(t, "", http.MethodPost, duongWebKhach, than, map[string]string{"X-Integration-Key": "ecw_" + strings.Repeat("0", 32)})
	if r.ma != http.StatusUnauthorized {
		t.Fatalf("khoá sai phải 401, nhận %d\n%s", r.ma, catBot(r.than))
	}
	// Token người dùng thay cho khoá: đây là đường của HỆ THỐNG, không của người.
	if r := h.goi(t, a.token, http.MethodPost, duongWebKhach, than); r.ma != http.StatusUnauthorized {
		t.Fatalf("token người dùng phải 401, nhận %d\n%s", r.ma, catBot(r.than))
	}
}
