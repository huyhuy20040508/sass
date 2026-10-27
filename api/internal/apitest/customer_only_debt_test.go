package apitest

import (
	"encoding/json"

	"net/http"
	"testing"
)

// TestKhachHang_LocChiConNo — KB #95: only_debt=1 chỉ trả khách còn nợ (> 0),
// cùng công thức cột still_in_debt, ở cả Quản trị lẫn quầy; meta.total đếm
// đúng tập ấy (số đếm cho nút "Nợ nhiều nhất").
func TestKhachHang_LocChiConNo(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	moCa(t, h, a, 0)

	// Khách gieo sẵn (a.khach) có đơn chưa thu. Thêm một khách mua và trả đủ.
	r := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/customers", map[string]any{
		"full_name": "Tra du " + a.vet, "status": "active",
	})
	if r.ma != http.StatusCreated {
		t.Fatalf("tạo khách trả %d\n%s", r.ma, catBot(r.than))
	}
	traDu := uint(doc(t, r)["id"].(float64))
	if r := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/orders/pos", map[string]any{
		"payment_method": "cash", "user_id": traDu,
		"items": []map[string]any{{"product_variant_id": a.bienThe, "quantity": 1}},
	}); r.ma != http.StatusCreated {
		t.Fatalf("bán cho khách trả đủ trả %d\n%s", r.ma, catBot(r.than))
	}

	thuNgan := h.dangNhapVoi(t, a.ma, "nhanvien")
	for _, c := range []struct{ tok, duong string }{
		{a.token, "/api/v1/admin/customers"},
		{thuNgan, "/api/v1/admin/orders/pos/khach-hang"},
	} {
		doc := func(q string) ([]map[string]any, int64) {
			t.Helper()
			r := h.goi(t, c.tok, http.MethodGet, c.duong+"?page_size=100"+q, nil)
			if r.ma != http.StatusOK {
				t.Fatalf("%s trả %d\n%s", c.duong, r.ma, catBot(r.than))
			}
			var b struct {
				Data []map[string]any
				Meta struct{ Total int64 }
			}
			_ = json.Unmarshal([]byte(r.than), &b)
			return b.Data, b.Meta.Total
		}
		co := func(ds []map[string]any, id uint) bool {
			for _, k := range ds {
				if uint(k["id"].(float64)) == id {
					return true
				}
			}
			return false
		}

		tatCa, _ := doc("")
		if !co(tatCa, a.khach) || !co(tatCa, traDu) {
			t.Fatalf("%s: không lọc thì phải có cả hai khách", c.duong)
		}
		no, tong := doc("&only_debt=1&sort=debt_desc")
		if !co(no, a.khach) || co(no, traDu) || tong != int64(len(no)) {
			t.Fatalf("%s: only_debt=1 chỉ ra khách còn nợ (có %v, khách trả đủ %v, total %d / %d dòng)",
				c.duong, co(no, a.khach), co(no, traDu), tong, len(no))
		}
		for _, k := range no {
			if v, _ := k["still_in_debt"].(float64); v <= 0 {
				t.Fatalf("%s: dòng %v lọt vào only_debt mà nợ %v", c.duong, k["id"], k["still_in_debt"])
			}
		}
	}
}
