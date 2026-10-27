package apitest

import (
	"encoding/json"
	"fmt"
	"net/http"
	"strings"
	"testing"
	"time"
)

// TestSoDon_TongTheoNgay — KB #85: meta.tong.theo_ngay cộng ĐƠN BÁN theo ngày
// tạo trên cả kỳ (không gồm phiếu trả), một lượt; không xin thì không tính.
func TestSoDon_TongTheoNgay(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	moCa(t, h, a, 0)

	ban := func(sl int) uint {
		r := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/orders/pos", map[string]any{
			"payment_method": "cash", "amount_tendered": 10_000_000,
			"items": []map[string]any{{"product_variant_id": a.bienThe, "quantity": sl}},
		})
		if r.ma != http.StatusCreated {
			t.Fatalf("bán tại quầy trả %d\n%s", r.ma, catBot(r.than))
		}
		return uint(doc(t, r)["order_id"].(float64))
	}
	homTruoc := ban(1)
	ban(2)
	traLai := ban(1)
	if err := h.db.WithContext(ctxThoat()).Exec(
		"UPDATE orders SET created_at = DATE_SUB(created_at, INTERVAL 2 DAY) WHERE id = ?", homTruoc,
	).Error; err != nil {
		t.Fatalf("không lùi được ngày đơn: %v", err)
	}
	tra := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/returns", map[string]any{
		"order_id": traLai, "reason": "other", "refund_method": "cash",
		"items": []map[string]any{{"order_item_id": dongHangDauTien(t, h, a, traLai), "quantity": 1}},
	})
	if tra.ma != http.StatusCreated {
		t.Fatalf("lập phiếu trả trả %d\n%s", tra.ma, catBot(tra.than))
	}
	if r := h.goi(t, a.token, http.MethodPut, fmt.Sprintf("/api/v1/admin/returns/%d/status", uint(doc(t, tra)["id"].(float64))),
		map[string]any{"status": "received"}); r.ma != http.StatusOK {
		t.Fatalf("nhận hàng trả trả %d\n%s", r.ma, catBot(r.than))
	}

	ky := fmt.Sprintf("from_date=%s&to_date=%s", time.Now().AddDate(0, 0, -3).Format("2006-01-02"), time.Now().Format("2006-01-02"))
	var so struct {
		Data []struct {
			Loai      string  `json:"loai"`
			TongTien  float64 `json:"tong_tien"`
			CreatedAt string  `json:"created_at"`
		}
		Meta struct {
			Tong struct {
				TheoNgay []struct {
					Ngay     string  `json:"ngay"`
					SoDong   int64   `json:"so_dong"`
					TongTien float64 `json:"tong_tien"`
				} `json:"theo_ngay"`
			} `json:"tong"`
		} `json:"meta"`
	}
	r := h.goi(t, a.token, http.MethodGet, "/api/v1/admin/orders/so-don?page_size=100&theo_ngay=1&"+ky, nil)
	if r.ma != http.StatusOK {
		t.Fatalf("sổ đơn trả %d\n%s", r.ma, catBot(r.than))
	}
	if err := json.Unmarshal([]byte(r.than), &so); err != nil {
		t.Fatalf("không đọc được sổ đơn: %v", err)
	}

	type ngay struct {
		n    int64
		tien float64
	}
	muon, coPhieuTra := map[string]ngay{}, false
	for _, d := range so.Data {
		if d.Loai != "don" {
			coPhieuTra = true
			continue
		}
		k := d.CreatedAt[:10]
		muon[k] = ngay{muon[k].n + 1, muon[k].tien + d.TongTien}
	}
	if !coPhieuTra || len(muon) != 2 {
		t.Fatalf("dữ liệu thử phải có phiếu trả và đơn ở 2 ngày, có %d ngày (phiếu trả: %v)", len(muon), coPhieuTra)
	}
	co := map[string]ngay{}
	for _, d := range so.Meta.Tong.TheoNgay {
		co[d.Ngay] = ngay{d.SoDong, d.TongTien}
	}
	if fmt.Sprint(co) != fmt.Sprint(muon) {
		t.Fatalf("theo_ngay phải cộng đơn bán theo ngày %v, đang là %v", muon, co)
	}

	if r := h.goi(t, a.token, http.MethodGet, "/api/v1/admin/orders/so-don?page_size=1&"+ky, nil); strings.Contains(r.than, "theo_ngay") {
		t.Fatalf("không xin theo_ngay thì không được tính: %s", catBot(r.than))
	}
}
