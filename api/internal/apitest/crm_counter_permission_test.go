package apitest

import (
	"fmt"
	"net/http"
	"strings"
	"testing"
)

// GET /orders/pos/khach-hang trả meta.co_the_sua theo quyền khach-hang.sua của
// phiên; lớp chặn PUT ở API giữ nguyên (KB #21).

// KB CS01–CS04
func TestCRMQuay_CoTheSuaTrongMeta(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	duong := "/api/v1/admin/orders/pos/khach-hang"
	coTheSua := func(tok string) string {
		r := h.goi(t, tok, http.MethodGet, duong, nil)
		if r.ma != http.StatusOK {
			t.Fatalf("danh sách khách quầy phải 200, nhận %d %s", r.ma, catBot(r.than))
		}
		switch {
		case strings.Contains(r.than, `"co_the_sua":true`):
			return "true"
		case strings.Contains(r.than, `"co_the_sua":false`):
			return "false"
		}
		return "thiếu"
	}

	// CS01 chủ / quản trị: được sửa; meta vẫn giữ đủ phân trang.
	if v := coTheSua(a.token); v != "true" {
		t.Fatalf("CS01 quản trị phải co_the_sua=true, nhận %s", v)
	}
	if r := h.goi(t, a.token, http.MethodGet, duong, nil); !strings.Contains(r.than, `"total_pages":`) {
		t.Fatalf("CS01 meta phải giữ phân trang: %s", catBot(r.than))
	}

	// CS02 thu ngân chưa có khach-hang.sua: cờ false và PUT vẫn 403.
	thuNgan := h.dangNhapVoi(t, a.ma, "nhanvien")
	if v := coTheSua(thuNgan); v != "false" {
		t.Fatalf("CS02 thu ngân chưa có quyền phải co_the_sua=false, nhận %s", v)
	}
	tao := h.goi(t, thuNgan, http.MethodPost, duong+"/day-du", map[string]any{"full_name": "Khách cờ " + a.vet, "phone": "0909 555 777", "status": "active", "customer_type": 0})
	id := uint(doc(t, tao)["id"].(float64))
	sua := map[string]any{"full_name": "Khách cờ đã sửa " + a.vet, "phone": "0909555777", "status": "active", "customer_type": 0}
	if r := h.goi(t, thuNgan, http.MethodPut, fmt.Sprintf("%s/%d", duong, id), sua); r.ma != http.StatusForbidden {
		t.Fatalf("CS02 lớp chặn API giữ nguyên: PUT phải 403, nhận %d", r.ma)
	}

	// CS03 giao quyền → cờ true, PUT được.
	giaoQuyen(t, h, a, "nhanvien", "khach-hang.sua")
	thuNgan = h.dangNhapVoi(t, a.ma, "nhanvien")
	if v := coTheSua(thuNgan); v != "true" {
		t.Fatalf("CS03 có khach-hang.sua phải co_the_sua=true, nhận %s", v)
	}
	if r := h.goi(t, thuNgan, http.MethodPut, fmt.Sprintf("%s/%d", duong, id), sua); r.ma != http.StatusOK {
		t.Fatalf("CS03 có quyền thì PUT phải 200, nhận %d %s", r.ma, catBot(r.than))
	}

	// CS04 danh sách khách của Quản trị không đổi khuôn meta (không kèm cờ của quầy).
	if r := h.goi(t, a.token, http.MethodGet, "/api/v1/admin/customers", nil); strings.Contains(r.than, "co_the_sua") {
		t.Fatalf("CS04 /admin/customers không mang cờ của quầy: %s", catBot(r.than))
	}
}
