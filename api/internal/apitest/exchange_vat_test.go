package apitest

import (
	"context"
	"net/http"
	"testing"

	"sass-api/internal/tenant"
)

// TestDoiHang_TinhThueChoDonMoi — KB #91: tiền trả lại đã GỒM thuế, nên đơn mới
// của lượt đổi cũng phải tính thuế như bán ở quầy; đổi ngang giá thì chênh 0.
func TestDoiHang_TinhThueChoDonMoi(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	moCa(t, h, a, 0)
	ctx := tenant.WithoutScope(context.Background(), "test: đặt thuế suất cho mặt hàng gieo sẵn")
	if err := h.db.WithContext(ctx).Exec("UPDATE products SET vat = 10 WHERE id = ? AND tenant_id = ?", a.sanPham, a.id).Error; err != nil {
		t.Fatalf("không đặt được thuế suất: %v", err)
	}

	ban := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/orders/pos", map[string]any{
		"payment_method": "cash", "amount_tendered": 10_000_000,
		"items": []map[string]any{{"product_variant_id": a.bienThe, "quantity": 2}},
	})
	if ban.ma != http.StatusCreated {
		t.Fatalf("bán tại quầy trả %d\n%s", ban.ma, catBot(ban.than))
	}
	donCu := uint(doc(t, ban)["order_id"].(float64))
	var cu struct{ Total, Vat float64 }
	h.db.WithContext(ctxThoat()).Raw("SELECT total_amount AS total, vat_amount AS vat FROM orders WHERE id = ?", donCu).Scan(&cu)
	if cu.Vat <= 0 {
		t.Fatalf("đơn thử phải có thuế, đang %+v", cu)
	}
	motMon := cu.Total / 2
	dong := dongHangDauTien(t, h, a, donCu)

	doi := func(lay int) map[string]any {
		t.Helper()
		r := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/orders/pos/doi-hang", map[string]any{
			"order_id": donCu, "payment_method": "cash", "amount_tendered": 10_000_000,
			"tra": []map[string]any{{"order_item_id": dong, "quantity": 1}},
			"moi": []map[string]any{{"product_variant_id": a.bienThe, "quantity": lay}},
		})
		if r.ma != http.StatusCreated {
			t.Fatalf("đổi hàng trả %d\n%s", r.ma, catBot(r.than))
		}
		return doc(t, r)
	}
	kiemDonMoi := func(d map[string]any, muon float64) {
		t.Helper()
		var moi struct{ Total, Vat, ItemVat float64 }
		h.db.WithContext(ctxThoat()).Raw(`SELECT o.total_amount AS total, o.vat_amount AS vat,
			(SELECT COALESCE(SUM(vat_amount), 0) FROM order_items WHERE order_id = o.id) AS item_vat
			FROM orders o WHERE o.id = ?`, uint(d["order_id"].(float64))).Scan(&moi)
		if moi.Total != muon || moi.Vat <= 0 || moi.ItemVat != moi.Vat {
			t.Errorf("đơn mới phải tính thuế như bán thường (tổng %v), đang %+v", muon, moi)
		}
	}

	// Đổi 1 lấy 1 cùng món: ngang giá, không ai trả ai.
	d := doi(1)
	if d["chenh_lech"].(float64) != 0 {
		t.Fatalf("đổi ngang giá có thuế phải chênh 0, đang %v", d["chenh_lech"])
	}
	kiemDonMoi(d, motMon)

	// Đổi 1 lấy 2: khách trả thêm đúng một món GỒM thuế.
	d = doi(2)
	if d["chenh_lech"].(float64) != motMon {
		t.Fatalf("đổi 1 lấy 2 phải trả thêm %v (gồm thuế), đang %v", motMon, d["chenh_lech"])
	}
	kiemDonMoi(d, 2*motMon)
}
