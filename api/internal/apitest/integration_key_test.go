package apitest

import (
	"net/http"
	"regexp"
	"strings"
	"testing"

	"sass-api/internal/domain"
)

// Khoá tích hợp của web bán hàng: web gọi API thay một cửa hàng, không token,
// không tên miền của tiệm (middleware.TenantFromIntegrationKey).

// KB TH01–TH09
func TestKhoaTichHop_WebBanHang(t *testing.T) {
	h := dungHeThong(t)
	a, b := haiCuaHang(t, h)
	const duong = "/api/v1/admin/tich-hop/web-ban-hang"

	cap := func(c *cuaHang) string {
		t.Helper()
		r := h.goi(t, c.token, http.MethodPost, duong, nil)
		if r.ma != http.StatusCreated {
			t.Fatalf("cấp khoá cho %s trả %d %s", c.ma, r.ma, catBot(r.than))
		}
		khoa, _ := doc(t, r)["khoa"].(string)
		if !strings.HasPrefix(khoa, "ecw_") {
			t.Fatalf("khoá cấp ra không đúng khuôn: %q", khoa)
		}
		return khoa
	}
	bangKhoa := func(khoa, token, duongGoi string) traLoi {
		return h.goiVoiHeader(t, token, http.MethodGet, duongGoi, nil, map[string]string{"X-Integration-Key": khoa})
	}
	ketNoi := func(c *cuaHang) map[string]any {
		r := h.goi(t, c.token, http.MethodGet, duong, nil)
		if r.ma != http.StatusOK {
			t.Fatalf("xem trạng thái trả %d %s", r.ma, catBot(r.than))
		}
		return doc(t, r)
	}

	// TH01 chưa cấp → chưa kết nối
	if v := ketNoi(a); v["ket_noi"] != false {
		t.Fatalf("TH01 chưa cấp mà ket_noi = %v", v["ket_noi"])
	}

	// TH02 khoá của A chỉ đọc được hàng của A
	khoaA, khoaB := cap(a), cap(b)
	r := bangKhoa(khoaA, "", "/api/v1/products?page_size=100")
	if r.ma != http.StatusOK || !strings.Contains(r.than, a.vet) || strings.Contains(r.than, b.vet) {
		t.Fatalf("TH02 khoá A đọc /products: %d, có hàng A=%v, lộ hàng B=%v", r.ma, strings.Contains(r.than, a.vet), strings.Contains(r.than, b.vet))
	}
	if r := bangKhoa(khoaB, "", "/api/v1/categories"); r.ma != http.StatusOK || strings.Contains(r.than, a.vet) {
		t.Fatalf("TH02 khoá B đọc /categories: %d, lộ của A=%v", r.ma, strings.Contains(r.than, a.vet))
	}

	// TH03 database chỉ giữ băm
	var soBam, soTho int64
	h.db.WithContext(ctxThoat()).Raw("SELECT COUNT(*) FROM integration_keys WHERE key_hash = ?", domain.BamKhoaTichHop(khoaA)).Scan(&soBam)
	h.db.WithContext(ctxThoat()).Raw("SELECT COUNT(*) FROM integration_keys WHERE key_hash = ? OR key_prefix = ?", khoaA, khoaA).Scan(&soTho)
	if soBam != 1 || soTho != 0 {
		t.Fatalf("TH03 băm khớp %d dòng (cần 1), khoá thô nằm trong DB %d dòng (cần 0)", soBam, soTho)
	}

	// TH04 khoá sai, hoặc khoá kèm token người dùng → 401
	if r := bangKhoa("ecw_khong-co-that", "", "/api/v1/products"); r.ma != http.StatusUnauthorized {
		t.Fatalf("TH04 khoá sai trả %d", r.ma)
	}
	if r := bangKhoa(khoaA, a.token, "/api/v1/products"); r.ma != http.StatusUnauthorized {
		t.Fatalf("TH04 khoá + token trả %d", r.ma)
	}

	// TH05 nhân viên không có quyền cấu hình → không cấp được
	if r := h.goi(t, h.dangNhapVoi(t, a.ma, "nhanvien"), http.MethodPost, duong, nil); r.ma != http.StatusForbidden {
		t.Fatalf("TH05 nhân viên cấp khoá trả %d %s", r.ma, catBot(r.than))
	}

	// TH06 cấp lại → khoá cũ chết, khoá mới chạy, trạng thái báo đúng khoá mới
	khoaA2 := cap(a)
	if r := bangKhoa(khoaA, "", "/api/v1/products"); r.ma != http.StatusUnauthorized {
		t.Fatalf("TH06 khoá cũ sau khi cấp lại vẫn trả %d", r.ma)
	}
	if r := bangKhoa(khoaA2, "", "/api/v1/products"); r.ma != http.StatusOK {
		t.Fatalf("TH06 khoá mới trả %d %s", r.ma, catBot(r.than))
	}
	if v := ketNoi(a); v["ket_noi"] != true || v["key_prefix"] != khoaA2[:10] || v["dung_lan_cuoi"] == nil {
		t.Fatalf("TH06 trạng thái sau cấp lại: %v", v)
	}

	// TH07 cửa hàng bị khoá (hết hạn) → khoá trả 403; mở lại → chạy lại
	datTrangThaiDataPlane := func(trangThai string) {
		if err := h.db.WithContext(ctxThoat()).Exec("UPDATE tenants SET status = ? WHERE id = ?", trangThai, a.id).Error; err != nil {
			t.Fatalf("không đổi được trạng thái cửa hàng: %v", err)
		}
	}
	datTrangThaiDataPlane(domain.TenantSuspended)
	r = bangKhoa(khoaA2, "", "/api/v1/products")
	datTrangThaiDataPlane(domain.TenantActive)
	if r.ma != http.StatusForbidden {
		t.Fatalf("TH07 cửa hàng tạm khoá mà khoá vẫn trả %d", r.ma)
	}
	if r := bangKhoa(khoaA2, "", "/api/v1/products"); r.ma != http.StatusOK {
		t.Fatalf("TH07 mở lại cửa hàng mà khoá trả %d", r.ma)
	}

	// TH08 thu hồi → khoá chết, trạng thái về chưa kết nối; khoá của B không bị đụng
	if r := h.goi(t, a.token, http.MethodDelete, duong, nil); r.ma != http.StatusOK {
		t.Fatalf("TH08 thu hồi trả %d %s", r.ma, catBot(r.than))
	}
	if r := bangKhoa(khoaA2, "", "/api/v1/products"); r.ma != http.StatusUnauthorized {
		t.Fatalf("TH08 khoá đã thu hồi vẫn trả %d", r.ma)
	}
	if v := ketNoi(a); v["ket_noi"] != false {
		t.Fatalf("TH08 thu hồi xong mà ket_noi = %v", v["ket_noi"])
	}
	if r := bangKhoa(khoaB, "", "/api/v1/products"); r.ma != http.StatusOK {
		t.Fatalf("TH08 thu hồi khoá A làm khoá B trả %d", r.ma)
	}

	// TH09 qua khoá không lộ giá vốn (chỉ nhân viên quản trị mới thấy)
	r = bangKhoa(khoaB, "", "/api/v1/products?page_size=100")
	if regexp.MustCompile(`"cost_price":\s*[1-9]`).MatchString(r.than) {
		t.Fatalf("TH09 khoá tích hợp đọc được giá vốn: %s", catBot(r.than))
	}
}
