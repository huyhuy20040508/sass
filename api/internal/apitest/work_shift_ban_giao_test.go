package apitest

import (
	"net/http"
	"strings"
	"testing"
)

// BÀN GIAO KÉT lúc đóng ca: tiền đếm = giao về chủ + giữ lại cho ca sau.

type caDong struct {
	Data struct {
		Ca struct {
			ID            uint     `json:"id"`
			CountedCash   *float64 `json:"counted_cash"`
			DeliveredCash *float64 `json:"delivered_cash"`
			KeptCash      *float64 `json:"kept_cash"`
		} `json:"ca"`
	} `json:"data"`
}

// KB BG01–BG05
func TestDongCa_BanGiaoKet(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	mo := func() {
		t.Helper()
		if r := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/ca-lam-viec/mo", map[string]any{"opening_cash": 500000}); r.ma != http.StatusCreated {
			t.Fatalf("mở ca trả %d %s", r.ma, catBot(r.than))
		}
	}
	dong := func(body map[string]any) (traLoi, caDong) {
		t.Helper()
		body["counted_cash"] = 500000
		r := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/ca-lam-viec/dong", body)
		var out caDong
		if r.ma == http.StatusOK {
			docJSON(t, r, &out)
		}
		return r, out
	}
	giaTri := func(p *float64) float64 {
		if p == nil {
			return -1
		}
		return *p
	}

	// Không khai: giao hết, giữ 0.
	mo()
	if _, d := dong(map[string]any{}); giaTri(d.Data.Ca.DeliveredCash) != 500000 || giaTri(d.Data.Ca.KeptCash) != 0 {
		t.Fatalf("không khai bàn giao thì giao hết: %+v", d.Data.Ca)
	}

	// Chỉ khai giữ lại: giao = đếm − giữ.
	mo()
	if _, d := dong(map[string]any{"kept_cash": 200000}); giaTri(d.Data.Ca.DeliveredCash) != 300000 || giaTri(d.Data.Ca.KeptCash) != 200000 {
		t.Fatalf("giữ 200.000 thì giao 300.000: %+v", d.Data.Ca)
	}

	// Khai cả hai mà tổng khác tiền đếm: 422, ca vẫn mở.
	mo()
	if r, _ := dong(map[string]any{"delivered_cash": 300000, "kept_cash": 150000}); r.ma != http.StatusUnprocessableEntity || !strings.Contains(r.than, "khác tiền đếm được 500.000₫") {
		t.Fatalf("giao + giữ ≠ tiền đếm phải 422 kèm câu rõ, nhận %d %s", r.ma, catBot(r.than))
	}
	// Giữ nhiều hơn tiền đếm: giao âm → 422.
	if r, _ := dong(map[string]any{"kept_cash": 600000}); r.ma != http.StatusUnprocessableEntity || !strings.Contains(r.than, "Giao về chủ không được âm") {
		t.Fatalf("giữ lại > tiền đếm phải 422, nhận %d %s", r.ma, catBot(r.than))
	}
	// Ca vẫn đang mở sau hai lượt bị từ chối; khai đúng thì đóng được.
	if r, d := dong(map[string]any{"delivered_cash": 350000, "kept_cash": 150000}); r.ma != http.StatusOK || giaTri(d.Data.Ca.KeptCash) != 150000 {
		t.Fatalf("khai đúng tổng phải đóng được, nhận %d %s", r.ma, catBot(r.than))
	}

	// Danh sách ca trả kept_cash để ca sau gợi ý tiền đầu ca.
	if r := h.goi(t, a.token, http.MethodGet, "/api/v1/admin/ca-lam-viec?status=da_dong&page_size=1", nil); !strings.Contains(r.than, `"kept_cash":150000`) {
		t.Fatalf("danh sách ca phải có kept_cash của ca vừa đóng: %s", catBot(r.than))
	}
}
