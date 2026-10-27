package apitest

import (
	"context"
	"encoding/json"
	"net/http"
	"testing"
	"time"

	"sass-api/internal/tenant"
)

// TestBaoCaoDon_TheoGioCoTienThuan — KB #81: by_hour / by_weekday của
// /reports/orders mang đủ thành phần tiền (tiền hàng, giảm, phí giao) để màn
// "Doanh thu thuần" kỳ một ngày không phải vẽ số GỒM VAT; cộng các mốc phải
// khớp tổng của /reports/revenue cùng kỳ.
func TestBaoCaoDon_TheoGioCoTienThuan(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	moCa(t, h, a, 0)

	ctx := tenant.WithoutScope(context.Background(), "test: đặt thuế suất cho mặt hàng gieo sẵn")
	if err := h.db.WithContext(ctx).Exec("UPDATE products SET vat = 10 WHERE id = ? AND tenant_id = ?", a.sanPham, a.id).Error; err != nil {
		t.Fatalf("không đặt được thuế suất: %v", err)
	}
	if r := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/orders/pos", map[string]any{
		"payment_method": "cash", "amount_tendered": 10_000_000,
		"items": []map[string]any{{"product_variant_id": a.bienThe, "quantity": 2}},
	}); r.ma != http.StatusCreated {
		t.Fatalf("bán tại quầy trả %d\n%s", r.ma, catBot(r.than))
	}

	homNay := time.Now().Format("2006-01-02")
	ky := "?from=" + homNay + "&to=" + homNay
	type moc struct {
		Revenue, Subtotal, Discount, Shipping float64
	}
	var don struct {
		Data struct {
			ByHour    []moc `json:"by_hour"`
			ByWeekday []moc `json:"by_weekday"`
		}
	}
	var dt struct {
		Data struct {
			Totals moc `json:"totals"`
		}
	}
	for duong, dich := range map[string]any{"/api/v1/admin/reports/orders": &don, "/api/v1/admin/reports/revenue": &dt} {
		r := h.goi(t, a.token, http.MethodGet, duong+ky, nil)
		if r.ma != http.StatusOK {
			t.Fatalf("%s trả %d\n%s", duong, r.ma, catBot(r.than))
		}
		_ = json.Unmarshal([]byte(r.than), dich)
	}

	tong := dt.Data.Totals
	if tong.Revenue <= tong.Subtotal-tong.Discount+tong.Shipping {
		t.Fatalf("đơn thử phải có VAT (doanh thu > tiền hàng − giảm + phí giao), đang là %+v", tong)
	}
	for ten, ds := range map[string][]moc{"by_hour": don.Data.ByHour, "by_weekday": don.Data.ByWeekday} {
		var cong moc
		for _, m := range ds {
			cong.Revenue += m.Revenue
			cong.Subtotal += m.Subtotal
			cong.Discount += m.Discount
			cong.Shipping += m.Shipping
		}
		if cong != tong {
			t.Errorf("%s cộng lại phải khớp tổng /reports/revenue %+v, đang là %+v", ten, tong, cong)
		}
	}
}
