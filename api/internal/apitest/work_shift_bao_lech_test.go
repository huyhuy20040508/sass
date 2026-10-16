package apitest

import (
	"fmt"
	"net/http"
	"strings"
	"testing"

	"sass-api/internal/domain"
)

// ĐÓNG CA LỆCH KÉT quá ngưỡng ở Cài đặt → thông báo vào kênh quản trị (quản lý + chủ).

func soThongBaoLech(t *testing.T, h *heThong, c *cuaHang, caID uint) (int, string) {
	t.Helper()
	var ds []struct {
		Title   string
		Content string
		Data    string
	}
	h.db.WithContext(ctxThoat()).Raw(`SELECT title, content, data FROM notifications
		WHERE tenant_id = ? AND type = 'shift_diff' AND user_id IS NULL AND JSON_EXTRACT(data, '$.shift_id') = ?`, c.id, caID).Scan(&ds)
	if len(ds) == 0 {
		return 0, ""
	}
	return len(ds), ds[0].Title + " | " + ds[0].Content + " | " + ds[0].Data
}

func moRoiDong(t *testing.T, h *heThong, c *cuaHang, dem float64, ghiChu string) uint {
	t.Helper()
	var ca caMo
	docJSON(t, h.goi(t, c.token, http.MethodPost, "/api/v1/admin/ca-lam-viec/mo", map[string]any{"opening_cash": 0}), &ca)
	if r := h.goi(t, c.token, http.MethodPost, "/api/v1/admin/ca-lam-viec/dong", map[string]any{"counted_cash": dem, "note": ghiChu}); r.ma != http.StatusOK {
		t.Fatalf("đóng ca trả %d %s", r.ma, catBot(r.than))
	}
	return ca.Data.ID
}

// KB BL01–BL04 — vượt ngưỡng mặc định 50.000 thì báo đủ ca / người trực / thiếu-dư; trong ngưỡng
// thì im; đổi ngưỡng ở Cài đặt có hiệu lực; thu ngân không thấy thông báo này.
func TestDongCa_BaoLechKet(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	gieoNhanSu(t, h, a, "TrucKet", &a.quanTri, "", domain.NhanSuDangLam)

	ca1 := moRoiDong(t, h, a, 120000, "Khách trả thừa")
	n, chu := soThongBaoLech(t, h, a, ca1)
	if n != 1 || !strings.Contains(chu, fmt.Sprintf("Ca #%d lệch két: dư 120.000₫", ca1)) || !strings.Contains(chu, "Người trực TrucKet "+a.vet) ||
		!strings.Contains(chu, "Theo sổ 0₫, đếm được 120.000₫") || !strings.Contains(chu, "Khách trả thừa") || !strings.Contains(chu, fmt.Sprintf(`"shift_id": %d`, ca1)) {
		t.Fatalf("lệch 120.000 > 50.000 phải có đúng 1 thông báo đủ thông tin, nhận %d: %s", n, chu)
	}

	// Lệch đúng bằng ngưỡng (không VƯỢT) và lệch nhỏ: không báo.
	for _, dem := range []float64{50000, 30000} {
		if n, _ := soThongBaoLech(t, h, a, moRoiDong(t, h, a, dem, "")); n != 0 {
			t.Fatalf("lệch %.0f ≤ ngưỡng không được báo", dem)
		}
	}

	// Thiếu cũng báo (trị tuyệt đối): mở ca có tiền đầu ca rồi đếm thiếu.
	var ca caMo
	docJSON(t, h.goi(t, a.token, http.MethodPost, "/api/v1/admin/ca-lam-viec/mo", map[string]any{"opening_cash": 500000}), &ca)
	h.goi(t, a.token, http.MethodPost, "/api/v1/admin/ca-lam-viec/dong", map[string]any{"counted_cash": 300000, "note": "Thiếu"})
	if n, chu := soThongBaoLech(t, h, a, ca.Data.ID); n != 1 || !strings.Contains(chu, "thiếu 200.000₫") {
		t.Fatalf("đếm thiếu 200.000 phải báo 'thiếu', nhận %d: %s", n, chu)
	}

	// Nâng ngưỡng lên 200.000: lệch 120.000 không còn báo.
	if r := h.goi(t, a.token, http.MethodPut, "/api/v1/admin/settings", map[string]any{"items": map[string]any{"shift_diff_alert_threshold": "200000"}}); r.ma != http.StatusOK {
		t.Fatalf("lưu ngưỡng trả %d %s", r.ma, catBot(r.than))
	}
	if n, _ := soThongBaoLech(t, h, a, moRoiDong(t, h, a, 120000, "x")); n != 0 {
		t.Fatalf("ngưỡng 200.000 thì lệch 120.000 không được báo")
	}

	// Kênh quản trị: quản trị thấy, thu ngân không.
	tieuDe := fmt.Sprintf("Ca #%d lệch két", ca1)
	if r := h.goi(t, a.token, http.MethodGet, "/api/v1/notifications?page_size=100", nil); !strings.Contains(r.than, tieuDe) {
		t.Fatalf("quản trị phải thấy thông báo lệch két trong chuông: %s", catBot(r.than))
	}
	thuNgan := h.dangNhapVoi(t, a.ma, "nhanvien")
	if r := h.goi(t, thuNgan, http.MethodGet, "/api/v1/notifications?page_size=100", nil); strings.Contains(r.than, tieuDe) {
		t.Fatalf("thu ngân không được thấy thông báo lệch két của kênh quản trị")
	}
}
