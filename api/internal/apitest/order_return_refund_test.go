package apitest

import (
	"fmt"
	"net/http"
	"testing"
)

// TestTraHang_HoanTienDuMoiCach — KB #74: phiếu trả của đơn quầy thu TIỀN MẶT
// chuyển sang "đã hoàn tiền" từng lỗi 500 vì payments.provider không nhận 'cash'.
// Mỗi cách hoàn phải ghi được, và lượt hoàn + phiếu chi ghi đúng cách tiền ra.
// KB #75: hoàn tiền mặt là tiền ra khỏi két — vào sổ quỹ của ca đang mở; cách
// khác thì két không đổi.
func TestTraHang_HoanTienDuMoiCach(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	ca := uint(moCa(t, h, a, 0)["id"].(float64))
	chiCuaCa := func() float64 {
		t.Helper()
		return doc(t, h.goi(t, a.token, http.MethodGet, "/api/v1/admin/ca-lam-viec/hien-tai", nil))["tong_chi"].(float64)
	}

	for _, c := range []struct {
		hoan, thuChi string
		raKet        float64
	}{
		{"cash", "cash", 90000},
		{"bank_transfer", "transfer", 0},
		{"ewallet", "transfer", 0},
	} {
		t.Run(c.hoan, func(t *testing.T) {
			res := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/orders/pos", map[string]any{
				"payment_method": "cash",
				"items":          []map[string]any{{"product_variant_id": a.bienThe, "quantity": 1}},
			})
			if res.ma != http.StatusCreated {
				t.Fatalf("bán tại quầy trả %d\n%s", res.ma, catBot(res.than))
			}
			donID := uint(doc(t, res)["order_id"].(float64))
			tra := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/returns", map[string]any{
				"order_id": donID, "reason": "other", "refund_method": c.hoan,
				"items": []map[string]any{{"order_item_id": dongHangDauTien(t, h, a, donID), "quantity": 1}},
			})
			if tra.ma != http.StatusCreated {
				t.Fatalf("lập phiếu trả trả %d\n%s", tra.ma, catBot(tra.than))
			}
			phieu := uint(doc(t, tra)["id"].(float64))
			chiTruoc := chiCuaCa()
			for _, st := range []string{"received", "refunded"} {
				if r := h.goi(t, a.token, http.MethodPut, fmt.Sprintf("/api/v1/admin/returns/%d/status", phieu),
					map[string]any{"status": st}); r.ma != http.StatusOK {
					t.Fatalf("chuyển phiếu trả sang %s trả %d\n%s", st, r.ma, catBot(r.than))
				}
			}

			var hoan struct {
				Provider string
				Amount   float64
			}
			h.db.WithContext(ctxThoat()).Raw(
				"SELECT provider, amount FROM payments WHERE order_id = ? AND status = 'refunded'", donID,
			).Scan(&hoan)
			if hoan.Provider != c.hoan || hoan.Amount != 90000 {
				t.Errorf("lượt hoàn phải ghi %s / 90000, đang là %+v", c.hoan, hoan)
			}
			var pt string
			h.db.WithContext(ctxThoat()).Raw(
				"SELECT payment_method FROM income_expenses WHERE source = 'order_return' AND source_id = ?", phieu,
			).Scan(&pt)
			if pt != c.thuChi {
				t.Errorf("phiếu chi hoàn tiền phải là %s, đang là %q", c.thuChi, pt)
			}

			var quy []struct {
				Direction string
				Amount    float64
				ShiftID   *uint
			}
			h.db.WithContext(ctxThoat()).Raw(
				"SELECT direction, amount, shift_id FROM cash_entries WHERE reference_type = 'order_return' AND reference_id = ?", phieu,
			).Scan(&quy)
			switch {
			case c.raKet == 0 && len(quy) != 0:
				t.Errorf("hoàn %s không qua két, sổ quỹ không được có dòng nào, đang có %+v", c.hoan, quy)
			case c.raKet > 0 && (len(quy) != 1 || quy[0].Direction != "out" || quy[0].Amount != c.raKet || quy[0].ShiftID == nil || *quy[0].ShiftID != ca):
				t.Errorf("hoàn tiền mặt phải ghi đúng 1 dòng chi %v vào ca %d, đang là %+v", c.raKet, ca, quy)
			}
			if sau := chiCuaCa(); sau-chiTruoc != c.raKet {
				t.Errorf("\"Chi tiền mặt\" của ca phải tăng %v, đang tăng %v", c.raKet, sau-chiTruoc)
			}
		})
	}
}
