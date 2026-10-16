package apitest

import (
	"fmt"
	"net/http"
	"strings"
	"testing"
)

// Bảng đếm theo mệnh giá lưu kèm ca — lúc mở (opening_count) và lúc đóng (counted_count).

// KB BD01–BD08
func TestCa_BangDemMenhGia(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	mo := func(tien float64, dem any) traLoi {
		return h.goi(t, a.token, http.MethodPost, "/api/v1/admin/ca-lam-viec/mo", map[string]any{"opening_cash": tien, "opening_count": dem})
	}
	to := func(m, n int) map[string]any { return map[string]any{"menh_gia": m, "so_to": n} }
	sach := `[{"menh_gia":500000,"so_to":1},{"menh_gia":100000,"so_to":2},{"menh_gia":1000,"so_to":5}]`

	for _, c := range []struct {
		ten  string
		tien float64
		dem  []map[string]any
		cau  string
	}{
		{"lệch tổng", 700000, []map[string]any{to(500000, 1), to(100000, 2), to(1000, 5)}, "Bảng đếm cộng ra 705.000₫, khác số tiền đã nhập 700.000₫"},
		{"mệnh giá lạ", 3000, []map[string]any{to(3000, 1)}, "Mệnh giá 3.000₫ không có trong bảng đếm."},
		{"trùng mệnh giá", 100000, []map[string]any{to(50000, 1), to(50000, 1)}, "ghi hai lần"},
		{"số tờ âm", 0, []map[string]any{to(50000, -1)}, "phải từ 0 đến 100.000"},
	} {
		if r := mo(c.tien, c.dem); r.ma != http.StatusUnprocessableEntity || !strings.Contains(r.than, c.cau) {
			t.Fatalf("%s: phải 422 %q, nhận %d %s", c.ten, c.cau, r.ma, catBot(r.than))
		}
	}

	// Gửi lộn thứ tự + có dòng 0 tờ: lưu lớn → nhỏ, bỏ dòng 0.
	r := mo(705000, []map[string]any{to(1000, 5), to(200000, 0), to(500000, 1), to(100000, 2)})
	if r.ma != http.StatusCreated || !strings.Contains(r.than, `"opening_count":`+sach) {
		t.Fatalf("mở ca có bảng đếm phải lưu %s, nhận %d %s", sach, r.ma, catBot(r.than))
	}
	id := uint(doc(t, r)["id"].(float64))
	if g := h.goi(t, a.token, http.MethodGet, fmt.Sprintf("/api/v1/admin/ca-lam-viec/%d", id), nil); !strings.Contains(g.than, `"opening_count":`+sach) {
		t.Fatalf("chi tiết ca phải trả bảng đếm lúc mở: %s", catBot(g.than))
	}

	dong := func(tien float64, dem any) traLoi {
		return h.goi(t, a.token, http.MethodPost, "/api/v1/admin/ca-lam-viec/dong", map[string]any{"counted_cash": tien, "counted_count": dem, "note": "test"})
	}
	if r := dong(750000, []map[string]any{to(500000, 1), to(50000, 2), to(1000, 5)}); r.ma != http.StatusUnprocessableEntity || !strings.Contains(r.than, "Bảng đếm cộng ra 605.000₫") {
		t.Fatalf("đóng ca bảng đếm lệch tổng phải 422, nhận %d %s", r.ma, catBot(r.than))
	}
	if r := h.goi(t, a.token, http.MethodGet, "/api/v1/admin/ca-lam-viec/hien-tai", nil); !strings.Contains(r.than, fmt.Sprintf(`"id":%d,`, id)) {
		t.Fatalf("422 thì ca vẫn phải đang mở: %s", catBot(r.than))
	}
	r = dong(705000, []map[string]any{to(100000, 2), to(1000, 5), to(500000, 1)})
	if r.ma != http.StatusOK || !strings.Contains(r.than, `"counted_count":`+sach) {
		t.Fatalf("đóng ca phải lưu bảng đếm %s, nhận %d %s", sach, r.ma, catBot(r.than))
	}
	var luu string
	h.db.WithContext(ctxThoat()).Raw("SELECT CAST(counted_count AS CHAR) FROM work_shifts WHERE id = ?", id).Scan(&luu)
	if !strings.Contains(strings.ReplaceAll(luu, " ", ""), `{"so_to":2,"menh_gia":100000}`) && !strings.Contains(strings.ReplaceAll(luu, " ", ""), `{"menh_gia":100000,"so_to":2}`) {
		t.Fatalf("cột counted_count phải có dòng 100.000 × 2, nhận %s", luu)
	}

	// Gõ thẳng tổng, không đếm theo tờ: cột để NULL.
	r = mo(0, nil)
	if r.ma != http.StatusCreated || !strings.Contains(r.than, `"opening_count":null`) {
		t.Fatalf("không đếm theo tờ thì opening_count null, nhận %d %s", r.ma, catBot(r.than))
	}
}
