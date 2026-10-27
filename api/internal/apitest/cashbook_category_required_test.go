package apitest

import (
	"fmt"
	"net/http"
	"testing"
)

// TestThuChi_PhieuTayBatBuocPhanLoai — KB #78: phiếu LẬP TAY thiếu phân loại bị
// từ chối ở cả sổ quản trị lẫn thu chi quầy, lúc lập lẫn lúc sửa; phiếu tự sinh
// (bán hàng) vẫn không mang phân loại như trước.
func TestThuChi_PhieuTayBatBuocPhanLoai(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	moCa(t, h, a, 0)
	thuNgan := h.dangNhapVoi(t, a.ma, "nhanvien")

	for _, duong := range []string{"/api/v1/admin/thu-chi", duongTCQuay} {
		tok := a.token
		if duong == duongTCQuay {
			tok = thuNgan
		}
		than := map[string]any{"type": 1, "amount": 20000, "payment_method": "cash", "note": "Mua nước"}
		if r := h.goi(t, tok, http.MethodPost, duong, than); r.ma != http.StatusUnprocessableEntity {
			t.Fatalf("%s: lập phiếu tay thiếu phân loại phải 422, nhận %d\n%s", duong, r.ma, catBot(r.than))
		}
		r := h.goi(t, tok, http.MethodPost, duong, voiLoaiTC(t, h, tok, than))
		if r.ma != http.StatusCreated {
			t.Fatalf("%s: có phân loại thì phải lập được, nhận %d\n%s", duong, r.ma, catBot(r.than))
		}
		id := uint(doc(t, r)["id"].(float64))
		if r := h.goi(t, tok, http.MethodPut, fmt.Sprintf("%s/%d", duong, id), than); r.ma != http.StatusUnprocessableEntity {
			t.Fatalf("%s: sửa bỏ phân loại phải 422, nhận %d\n%s", duong, r.ma, catBot(r.than))
		}
	}

	// Phiếu thu tự sinh từ đơn bán vẫn lập được, không phân loại.
	res := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/orders/pos", map[string]any{
		"payment_method": "cash",
		"items":          []map[string]any{{"product_variant_id": a.bienThe, "quantity": 1}},
	})
	if res.ma != http.StatusCreated {
		t.Fatalf("bán tại quầy trả %d\n%s", res.ma, catBot(res.than))
	}
	var dem int64
	h.db.WithContext(ctxThoat()).Raw(
		"SELECT COUNT(*) FROM income_expenses WHERE source = 'order' AND source_id = ? AND category_id IS NULL",
		uint(doc(t, res)["order_id"].(float64)),
	).Scan(&dem)
	if dem != 1 {
		t.Fatalf("đơn bán phải sinh đúng 1 phiếu thu không phân loại, có %d", dem)
	}
}
