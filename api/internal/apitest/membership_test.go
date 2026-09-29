package apitest

import (
	"encoding/json"
	"fmt"
	"net/http"
	"strings"
	"testing"
)

type khachDiemThu struct {
	TotalPoints uint   `json:"total_points"`
	Points      uint   `json:"points"`
	RankName    string `json:"rank_name"`
}

func docKhachDiem(t *testing.T, h *heThong, a *cuaHang) khachDiemThu {
	t.Helper()
	r := h.goi(t, a.token, http.MethodGet, fmt.Sprintf("/api/v1/admin/customers/%d", a.khach), nil)
	var out struct {
		Data khachDiemThu `json:"data"`
	}
	_ = json.Unmarshal([]byte(r.than), &out)

	return out.Data
}

// TestTheThanhVien_TichDiemLenHangVaDoiDiem — như v2: khách tích điểm theo tiền
// thực trả, đủ điểm thì lên hạng; đơn sau được giảm theo hạng và đổi điểm ra
// tiền; điểm đổi trừ vào điểm còn dùng chứ không làm tụt hạng.
func TestTheThanhVien_TichDiemLenHangVaDoiDiem(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	goc := "/api/v1/admin/the-thanh-vien"

	// 10.000đ = 100 điểm; 1 điểm = 100đ.
	for _, q := range []map[string]any{
		{"kind": "earn", "money": 10000, "point": 100, "enabled": true},
		{"kind": "redeem", "money": 100, "point": 1, "enabled": true},
	} {
		if r := h.goi(t, a.token, http.MethodPut, goc+"/quy-doi", q); r.ma != http.StatusOK {
			t.Fatalf("lưu quy đổi trả %d\n%s", r.ma, catBot(r.than))
		}
	}
	dong := h.goi(t, a.token, http.MethodPost, goc, map[string]any{
		"name": "Đồng", "point": 1000, "discount_type": "money", "discount_value": 3000, "apply_all_order_values": true,
	})
	if dong.ma != http.StatusCreated {
		t.Fatalf("thêm hạng trả %d\n%s", dong.ma, catBot(dong.than))
	}
	var hang struct {
		Data struct {
			ID uint `json:"id"`
		} `json:"data"`
	}
	_ = json.Unmarshal([]byte(dong.than), &hang)
	if r := h.goi(t, a.token, http.MethodPost, goc, map[string]any{
		"name": "Trùng", "point": 1000, "discount_type": "money", "discount_value": 1, "apply_all_order_values": true,
	}); r.ma != http.StatusUnprocessableEntity {
		t.Fatalf("hai hạng cùng mức điểm phải 422, đang là %d", r.ma)
	}

	ban := func(sl int, dungDiem int) traLoi {
		return h.goi(t, a.token, http.MethodPost, "/api/v1/admin/orders/pos", map[string]any{
			"payment_method": "bank_transfer", "user_id": a.khach, "use_points": dungDiem,
			"items": []map[string]any{{"product_variant_id": a.bienThe, "quantity": sl}},
		})
	}
	type kqBan struct {
		Data struct {
			OrderID      uint    `json:"order_id"`
			Total        float64 `json:"total_amount"`
			RankDiscount float64 `json:"rank_discount"`
			PointsUsed   uint    `json:"points_used"`
			PointsAmount float64 `json:"points_amount"`
			PointsEarned uint    `json:"points_earned"`
		} `json:"data"`
	}

	// Đơn 1: 2 cái × 90.000đ = 180.000đ → 1.800 điểm, lên hạng Đồng. Chưa có hạng lúc mua nên chưa giảm.
	r := ban(2, 0)
	if r.ma != http.StatusCreated {
		t.Fatalf("bán lần 1 trả %d\n%s", r.ma, catBot(r.than))
	}
	var k1 kqBan
	_ = json.Unmarshal([]byte(r.than), &k1)
	if k1.Data.PointsEarned != 1800 || k1.Data.RankDiscount != 0 {
		t.Fatalf("đơn 180.000đ phải cộng 1.800 điểm, chưa giảm hạng — đang %+v", k1.Data)
	}
	if kd := docKhachDiem(t, h, a); kd.TotalPoints != 1800 || kd.Points != 1800 || kd.RankName != "Đồng" {
		t.Fatalf("khách phải có 1.800 điểm và hạng Đồng, đang %+v", kd)
	}

	// Đơn 2: 90.000đ − 3.000 (hạng) − 500 điểm × 100đ = 37.000đ → cộng 370 điểm.
	r = ban(1, 500)
	if r.ma != http.StatusCreated {
		t.Fatalf("bán lần 2 trả %d\n%s", r.ma, catBot(r.than))
	}
	var k2 kqBan
	_ = json.Unmarshal([]byte(r.than), &k2)
	if k2.Data.RankDiscount != 3000 || k2.Data.PointsUsed != 500 || k2.Data.PointsAmount != 50000 || k2.Data.Total != 37000 || k2.Data.PointsEarned != 370 {
		t.Fatalf("giảm hạng 3.000, đổi 500 điểm = 50.000, còn 37.000, cộng 370 — đang %+v", k2.Data)
	}
	if kd := docKhachDiem(t, h, a); kd.TotalPoints != 2170 || kd.Points != 1670 {
		t.Fatalf("điểm tích luỹ 2.170 (không trừ khi đổi), còn dùng 1.670 — đang %+v", kd)
	}

	// Trả 1 trong 2 cái của đơn 1: hoàn 90.000đ / 180.000đ → rút 900 điểm đã cộng.
	tra := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/returns", map[string]any{
		"order_id": k1.Data.OrderID, "reason": "other", "refund_method": "cash",
		"items": []map[string]any{{"order_item_id": dongHangDauTien(t, h, a, k1.Data.OrderID), "quantity": 1}},
	})
	if tra.ma != http.StatusCreated {
		t.Fatalf("lập phiếu trả trả %d\n%s", tra.ma, catBot(tra.than))
	}
	phieu := uint(doc(t, tra)["id"].(float64))
	for _, st := range []string{"received", "refunded"} {
		if r := h.goi(t, a.token, http.MethodPut, fmt.Sprintf("/api/v1/admin/returns/%d/status", phieu), map[string]any{"status": st}); r.ma != http.StatusOK {
			t.Fatalf("chuyển phiếu trả sang %s trả %d\n%s", st, r.ma, catBot(r.than))
		}
	}
	if kd := docKhachDiem(t, h, a); kd.TotalPoints != 1270 || kd.Points != 770 {
		t.Fatalf("trả nửa đơn 1 phải rút 900 điểm (còn 1.270 / 770), đang %+v", kd)
	}

	if r := ban(1, 999999); r.ma != http.StatusUnprocessableEntity || !strings.Contains(r.than, "không đủ điểm") {
		t.Fatalf("đổi quá số điểm đang có phải 422, đang là %d\n%s", r.ma, catBot(r.than))
	}

	ds := h.goi(t, a.token, http.MethodGet, goc, nil)
	if !strings.Contains(ds.than, `"member_count":1`) || !strings.Contains(ds.than, `"earn_point":100`) {
		t.Fatalf("danh sách hạng phải đếm 1 khách và kèm quy đổi\n%s", catBot(ds.than))
	}
	tv := h.goi(t, a.token, http.MethodGet, fmt.Sprintf("%s/%d/khach", goc, hang.Data.ID), nil)
	if !strings.Contains(tv.than, `"total_points":1270`) {
		t.Fatalf("trang chi tiết hạng phải có khách 1.270 điểm\n%s", catBot(tv.than))
	}
	quay := h.goi(t, a.token, http.MethodGet, "/api/v1/admin/orders/pos/thanh-vien", nil)
	if !strings.Contains(quay.than, `"money_per_point":100`) || !strings.Contains(quay.than, `"Đồng"`) {
		t.Fatalf("quầy phải nhận hạng đang bật và tỉ lệ đổi điểm\n%s", catBot(quay.than))
	}

	// Nâng ngưỡng Đồng lên 3.000 điểm → khách 1.270 điểm rớt hạng ngay.
	if r := h.goi(t, a.token, http.MethodPut, fmt.Sprintf("%s/%d", goc, hang.Data.ID), map[string]any{
		"name": "Đồng", "point": 3000, "discount_type": "money", "discount_value": 3000, "apply_all_order_values": true,
	}); r.ma != http.StatusOK {
		t.Fatalf("sửa hạng trả %d\n%s", r.ma, catBot(r.than))
	}
	if kd := docKhachDiem(t, h, a); kd.RankName != "" {
		t.Fatalf("nâng ngưỡng thì khách chưa đủ điểm phải rớt hạng, đang %q", kd.RankName)
	}
	if r := h.goi(t, a.token, http.MethodPost, goc+"/xoa", map[string]any{"ids": []uint{hang.Data.ID}}); r.ma != http.StatusOK {
		t.Fatalf("xoá hạng trả %d", r.ma)
	}
}
