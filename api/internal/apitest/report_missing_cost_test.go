package apitest

import (
	"encoding/json"
	"net/http"
	"testing"
	"time"
)

// TestBaoCao_ThieuGiaVon — KB #98: dòng bán chưa có giá vốn được đếm riêng ở
// totals.thieu_gia_von để màn lãi gộp không đọc nó thành lãi 100%; dòng đã có
// giá vốn thì không vào đó.
func TestBaoCao_ThieuGiaVon(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	moCa(t, h, a, 0)

	ban := func() {
		t.Helper()
		if r := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/orders/pos", map[string]any{
			"payment_method": "cash",
			"items":          []map[string]any{{"product_variant_id": a.bienThe, "quantity": 1}},
		}); r.ma != http.StatusCreated {
			t.Fatalf("bán tại quầy trả %d\n%s", r.ma, catBot(r.than))
		}
	}
	doc := func() (thieu struct {
		SoDong   int64   `json:"so_dong"`
		SoLuong  int64   `json:"so_luong"`
		TienHang float64 `json:"tien_hang"`
	}, profit, subtotal float64) {
		t.Helper()
		ngay := time.Now().Format("2006-01-02")
		r := h.goi(t, a.token, http.MethodGet, "/api/v1/admin/reports/revenue?from="+ngay+"&to="+ngay, nil)
		var b struct {
			Data struct {
				Totals struct {
					Profit      float64 `json:"profit"`
					Subtotal    float64 `json:"subtotal"`
					ThieuGiaVon struct {
						SoDong   int64   `json:"so_dong"`
						SoLuong  int64   `json:"so_luong"`
						TienHang float64 `json:"tien_hang"`
					} `json:"thieu_gia_von"`
				} `json:"totals"`
			} `json:"data"`
		}
		if err := json.Unmarshal([]byte(r.than), &b); err != nil || r.ma != http.StatusOK {
			t.Fatalf("báo cáo doanh thu trả %d\n%s", r.ma, catBot(r.than))
		}
		tt := b.Data.Totals
		thieu.SoDong, thieu.SoLuong, thieu.TienHang = tt.ThieuGiaVon.SoDong, tt.ThieuGiaVon.SoLuong, tt.ThieuGiaVon.TienHang
		return thieu, tt.Profit, tt.Subtotal
	}

	h.db.WithContext(ctxThoat()).Exec("UPDATE product_variants SET cost_price = NULL WHERE id = ?", a.bienThe)
	h.db.WithContext(ctxThoat()).Exec("UPDATE products SET cost_price = NULL WHERE id = ?", a.sanPham)
	// Cửa hàng mẫu có sẵn đơn hôm nay — so theo phần tăng thêm.
	goc, laiGoc, tienGoc := doc()
	ban()
	thieu, profit, subtotal := doc()
	them := subtotal - tienGoc
	if thieu.SoDong-goc.SoDong != 1 || thieu.SoLuong-goc.SoLuong != 1 || thieu.TienHang-goc.TienHang != them || profit-laiGoc != them {
		t.Fatalf("1 đơn chưa có giá vốn %v: thieu_gia_von phải tăng 1 dòng / 1 sp / %v (lãi tăng cả tiền hàng %v), trước %+v sau %+v",
			them, them, profit-laiGoc, goc, thieu)
	}

	// Khai giá vốn cho mặt hàng: dòng chưa chụp giá vốn lấy giá vốn hiện tại (cùng
	// luật costExpr của cột Cost), nên không còn dòng nào thiếu; bán thêm cũng vậy.
	datGiaVon(t, h, a.bienThe, 50000)
	ban()
	if sau, _, _ := doc(); sau.SoDong != 0 || sau.TienHang != 0 {
		t.Fatalf("đã khai giá vốn cho mặt hàng thì không còn dòng nào thiếu, đang %+v", sau)
	}
}
