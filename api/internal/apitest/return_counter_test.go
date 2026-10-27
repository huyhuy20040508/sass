package apitest

import (
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"strings"
	"testing"
	"time"

	"sass-api/internal/domain"
	"sass-api/internal/tenant"
)

// Bài kiểm TRẢ HÀNG Ở QUẦY (/admin/quay/tra-hang) — phiếu một bước: nhận hàng,
// nhập kho, hoàn tiền trong một giao dịch. Bốn thứ bản cũ / màn quản trị làm sai:
//
//   - tiền hoàn tính theo giá TRƯỚC giảm giá (đơn giảm 10% thì hoàn dư 10%);
//   - đơn có HĐĐT / còn nợ / quá hạn chỉ bị chặn ở giao diện;
//   - hoàn tiền mặt không cần ca mở;
//   - đổi hàng không kiểm điều kiện đơn.

const duongTraQuay = "/api/v1/admin/quay/tra-hang"

// banQuayGiam bán một đơn tiền mặt soLuong × giá biến thể, giảm tay cả đơn giam đồng.
func banQuayGiam(t *testing.T, h *heThong, c *cuaHang, soLuong int, giam float64) (uint, uint) {
	t.Helper()

	res := h.goi(t, c.token, http.MethodPost, "/api/v1/admin/orders/pos", map[string]any{
		"payment_method":        "cash",
		"order_discount_amount": giam,
		"items":                 []map[string]any{{"product_variant_id": c.bienThe, "quantity": soLuong}},
	})
	if res.ma != http.StatusCreated {
		t.Fatalf("bán tại quầy trả %d\n%s", res.ma, catBot(res.than))
	}
	donID := uint(doc(t, res)["order_id"].(float64))
	var dong domain.OrderItem
	if err := h.db.WithContext(ctxThoat()).Where("order_id = ? AND is_gift = 0", donID).First(&dong).Error; err != nil {
		t.Fatalf("không tra được dòng hàng của đơn %d: %v", donID, err)
	}

	return donID, dong.ID
}

type tongTraQuay struct {
	SoPhieu     int64   `json:"count"`
	SoMon       int64   `json:"item_quantity"`
	SoMonNhap   int64   `json:"restock_quantity"`
	TienHoan    float64 `json:"refund_amount"`
	TienMatHoan float64 `json:"cash_refund_amount"`
	CoTheTra    bool    `json:"co_the_tra"`
}

func traQuay(t *testing.T, h *heThong, token string, don, dong uint, sl int, extra map[string]any) traLoi {
	t.Helper()

	than := map[string]any{
		"order_id": don, "reason": "changed_mind", "refund_method": "cash",
		"items": []map[string]any{{"order_item_id": dong, "quantity": sl}},
	}
	for k, v := range extra {
		than[k] = v
	}

	return h.goi(t, token, http.MethodPost, duongTraQuay, than)
}

// TestTraQuay_HoanDungSoKhachDaTra — 2 × 90.000, giảm tay cả đơn 18.000: khách trả
// 162.000, trả 1 món phải được 81.000 (không phải 90.000). Tiền mặt ra khỏi két ca,
// có phiếu chi; trả nốt thì đơn khép lại và không trả thêm được.
func TestTraQuay_HoanDungSoKhachDaTra(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	moCa(t, h, a, 500000)
	thuNgan := h.dangNhapVoi(t, a.ma, "nhanvien")
	don, dong := banQuayGiam(t, h, a, 2, 18000)

	ra := doc(t, h.goi(t, thuNgan, http.MethodGet, fmt.Sprintf("%s/don/%d", duongTraQuay, don), nil))
	if ra["returnable"] != true {
		t.Fatalf("đơn vừa bán, đã thu đủ phải trả được — lý do đang trả: %v", ra["reason"])
	}
	dongs := ra["items"].([]any)
	if paid := dongs[0].(map[string]any)["paid_total"].(float64); paid != 162000 {
		t.Fatalf("paid_total của dòng phải là 162.000 (đã trừ giảm cả đơn), nhận %v", paid)
	}

	res := traQuay(t, h, thuNgan, don, dong, 1, nil)
	if res.ma != http.StatusCreated {
		t.Fatalf("trả tại quầy phải 201, nhận %d\n%s", res.ma, catBot(res.than))
	}
	p := doc(t, res)
	if p["status"] != "refunded" || p["refund_amount"].(float64) != 81000 {
		t.Fatalf("phiếu quầy vào thẳng refunded với 81.000, nhận %v / %v", p["status"], p["refund_amount"])
	}

	ca := doc(t, h.goi(t, a.token, http.MethodGet, "/api/v1/admin/ca-lam-viec/hien-tai", nil))
	if ca["tong_chi"].(float64) != 81000 {
		t.Fatalf("hoàn tiền mặt phải ra khỏi két ca: tổng chi 81.000, nhận %v", ca["tong_chi"])
	}
	ds, _ := docThuChi(t, h, a.token, "source=return")
	if len(ds) != 1 || ds[0].Amount != 81000 || ds[0].Type != 1 {
		t.Fatalf("phải có đúng 1 phiếu chi tự sinh 81.000, nhận %+v", ds)
	}

	// Trả nốt món còn lại, khấu trừ 1.000 → 80.000; đơn khép lại.
	if res := traQuay(t, h, thuNgan, don, dong, 1, map[string]any{"deduction": 1000, "restock": false, "refund_method": "bank_transfer"}); res.ma != http.StatusCreated {
		t.Fatalf("trả nốt phải 201, nhận %d\n%s", res.ma, catBot(res.than))
	}
	res = traQuay(t, h, thuNgan, don, dong, 1, nil)
	if res.ma != http.StatusConflict {
		t.Fatalf("đơn đã trả hết thì 409, nhận %d\n%s", res.ma, catBot(res.than))
	}

	resDs := h.goi(t, thuNgan, http.MethodGet, duongTraQuay, nil)
	var body struct {
		Data []map[string]any `json:"data"`
		Meta tongTraQuay      `json:"meta"`
	}
	_ = json.Unmarshal([]byte(resDs.than), &body)
	m := body.Meta
	if !m.CoTheTra {
		t.Fatal("thu ngân có don-hang.doi-hang thì co_the_tra phải true")
	}
	if len(body.Data) != 2 || m.SoPhieu != 2 || m.TienHoan != 161000 || m.TienMatHoan != 81000 || m.SoMon != 2 || m.SoMonNhap != 1 {
		t.Fatalf("sổ quầy: 2 phiếu, hoàn 161.000 (tiền mặt 81.000), 2 món, 1 món nhập kho — nhận %d dòng, %+v", len(body.Data), m)
	}
}

// TestTraQuay_ChuaMoCaThiChan — hoàn tiền là tiền ra khỏi két.
func TestTraQuay_ChuaMoCaThiChan(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	ca := uint(moCa(t, h, a, 0)["id"].(float64))
	don, dong := banQuayGiam(t, h, a, 1, 0)
	if err := h.db.WithContext(ctxThoat()).Exec("UPDATE work_shifts SET closed_at = NOW(3), counted_cash = 0 WHERE id = ?", ca).Error; err != nil {
		t.Fatalf("không đóng được ca: %v", err)
	}
	res := traQuay(t, h, a.token, don, dong, 1, nil)
	if res.ma != http.StatusConflict || maLoi(t, res) != "CHUA_MO_CA" {
		t.Fatalf("chưa mở ca phải 409 CHUA_MO_CA, nhận %d\n%s", res.ma, catBot(res.than))
	}
}

// TestTraQuay_ChanOMayChu — HĐĐT, còn nợ, quá hạn: chặn ở MÁY CHỦ, và câu lý do
// đi ra cả ở bước chọn đơn lẫn lượt ghi. Đổi hàng cùng luật.
func TestTraQuay_ChanOMayChu(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	moCa(t, h, a, 500000)
	db := h.db.WithContext(ctxThoat())

	cases := []struct {
		ten  string
		lam  func(don uint)
		chua string
	}{
		{"có HĐĐT", func(don uint) {
			if err := db.Exec("INSERT INTO etax_invoices (tenant_id, shop_id, order_id, symbol, status, created_at, updated_at) VALUES (?, ?, ?, 'C26TAA', 'issued', NOW(3), NOW(3))", a.id, a.chiNhanh, don).Error; err != nil {
				t.Fatalf("không gieo được HĐĐT: %v", err)
			}
		}, "HĐĐT"},
		{"còn nợ", func(don uint) { db.Exec("UPDATE orders SET payment_status = 'pending' WHERE id = ?", don) }, "còn nợ"},
		{"quá hạn", func(don uint) {
			db.Exec("UPDATE orders SET created_at = ?, delivered_at = ? WHERE id = ?", time.Now().AddDate(0, 0, -10), time.Now().AddDate(0, 0, -10), don)
		}, "Quá hạn"},
	}
	for _, c := range cases {
		don, dong := banQuayGiam(t, h, a, 1, 0)
		c.lam(don)

		ra := doc(t, h.goi(t, a.token, http.MethodGet, fmt.Sprintf("%s/don/%d", duongTraQuay, don), nil))
		if ra["returnable"] != false || !chua(ra["reason"], c.chua) {
			t.Fatalf("%s: bước chọn món phải báo không trả được (%q), nhận %v / %v", c.ten, c.chua, ra["returnable"], ra["reason"])
		}
		res := traQuay(t, h, a.token, don, dong, 1, nil)
		if res.ma != http.StatusConflict || !chua(doc2(t, res)["message"], c.chua) {
			t.Fatalf("%s: lượt trả phải 409 kèm lý do, nhận %d\n%s", c.ten, res.ma, catBot(res.than))
		}
		res = h.goi(t, a.token, http.MethodPost, "/api/v1/admin/orders/pos/doi-hang", map[string]any{
			"order_id": don, "tra": []map[string]any{{"order_item_id": dong, "quantity": 1}},
			"moi": []map[string]any{{"product_variant_id": a.bienThe, "quantity": 1}}, "payment_method": "cash",
		})
		if res.ma != http.StatusConflict {
			t.Fatalf("%s: đổi hàng cùng luật phải 409, nhận %d\n%s", c.ten, res.ma, catBot(res.than))
		}
	}

	resDon := h.goi(t, a.token, http.MethodGet, duongTraQuay+"/don?days=30", nil)
	var dsDon struct {
		Data []struct {
			ID       uint   `json:"id"`
			LyDoChan string `json:"blocked_reason"`
		} `json:"data"`
	}
	_ = json.Unmarshal([]byte(resDon.than), &dsDon)
	chan3 := 0
	for _, d := range dsDon.Data {
		if d.LyDoChan != "" {
			chan3++
		}
	}
	if chan3 < 3 {
		t.Fatalf("bước chọn đơn phải có ít nhất 3 đơn bị chặn kèm lý do, nhận %d / %d đơn", chan3, len(dsDon.Data))
	}
}

// TestTraQuay_DonChiNhanhKhac — quầy chi nhánh B không trả đơn bán ở chi nhánh A.
func TestTraQuay_DonChiNhanhKhac(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	moCa(t, h, a, 0)
	don, dong := banQuayGiam(t, h, a, 1, 0)

	ctx := tenant.WithID(context.Background(), a.id)
	cn2 := &domain.ChiNhanh{Code: "cn2-" + a.vet, Name: "Chi nhánh 2 " + a.vet, IsActive: true}
	if err := h.db.WithContext(ctx).Create(cn2).Error; err != nil {
		t.Fatalf("không gieo được chi nhánh 2: %v", err)
	}
	moCaChiNhanh(t, h, a, cn2.ID)

	if res := h.goiChiNhanh(t, a.token, cn2.ID, http.MethodGet, fmt.Sprintf("%s/don/%d", duongTraQuay, don), nil); res.ma != http.StatusNotFound {
		t.Fatalf("xem đơn của chi nhánh khác phải 404, nhận %d", res.ma)
	}
	res := h.goiChiNhanh(t, a.token, cn2.ID, http.MethodPost, duongTraQuay, map[string]any{
		"order_id": don, "reason": "other", "refund_method": "cash",
		"items": []map[string]any{{"order_item_id": dong, "quantity": 1}},
	})
	if res.ma != http.StatusNotFound {
		t.Fatalf("trả đơn của chi nhánh khác phải 404, nhận %d\n%s", res.ma, catBot(res.than))
	}
}

// TestTraQuay_QuyenThuNgan — thu ngân trả được ở quầy (don-hang.doi-hang) nhưng
// sổ trả hàng của cả cửa hàng (/returns) vẫn đóng.
func TestTraQuay_QuyenThuNgan(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	moCa(t, h, a, 0)
	thuNgan := h.dangNhapVoi(t, a.ma, "nhanvien")

	if res := h.goi(t, thuNgan, http.MethodGet, duongTraQuay+"/don", nil); res.ma != http.StatusOK {
		t.Fatalf("thu ngân tìm đơn trả phải 200, nhận %d", res.ma)
	}
	if res := h.goi(t, thuNgan, http.MethodGet, "/api/v1/admin/returns", nil); res.ma != http.StatusForbidden {
		t.Fatalf("sổ trả hàng quản trị vẫn phải đóng với thu ngân, nhận %d", res.ma)
	}

	ctx := tenant.WithID(context.Background(), a.id)
	h.db.WithContext(ctx).Where("user_id = ? AND permission = ?", a.nhanVien, "don-hang.doi-hang").Delete(&domain.QuyenRieng{})
	thuNgan = h.dangNhapVoi(t, a.ma, "nhanvien")
	if res := h.goi(t, thuNgan, http.MethodPost, duongTraQuay, map[string]any{"order_id": 1, "reason": "other", "refund_method": "cash", "items": []map[string]any{{"order_item_id": 1, "quantity": 1}}}); res.ma != http.StatusForbidden {
		t.Fatalf("bỏ quyền đổi/trả thì lập phiếu phải 403, nhận %d", res.ma)
	}
}

func chua(v any, s string) bool {
	str, _ := v.(string)

	return strings.Contains(str, s)
}

func doc2(t *testing.T, res traLoi) map[string]any {
	t.Helper()

	var out map[string]any
	_ = json.Unmarshal([]byte(res.than), &out)

	return out
}
