package apitest

import (
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"testing"

	"sass-api/internal/domain"
	"sass-api/internal/tenant"
)

// GET /orders/pos/khach-hang/:id/con-no — các đơn khách còn nợ cho khung chi tiết
// khách ở quầy: cũ trước, tổng khớp still_in_debt, đơn chi nhánh khác không mời
// thu (KB #22).

type donConNoKB struct {
	ID        uint    `json:"id"`
	OrderCode string  `json:"order_code"`
	ShopID    uint    `json:"shop_id"`
	ConNo     float64 `json:"con_no"`
	DaThu     float64 `json:"da_thu"`
	ThuDuoc   bool    `json:"thu_duoc"`
}

func docConNo(t *testing.T, r traLoi) (float64, []donConNoKB) {
	t.Helper()
	if r.ma != http.StatusOK {
		t.Fatalf("đọc công nợ khách phải 200, nhận %d %s", r.ma, catBot(r.than))
	}
	var body struct {
		Data struct {
			Tong float64      `json:"tong_con_no"`
			Don  []donConNoKB `json:"don"`
		} `json:"data"`
	}
	if err := json.Unmarshal([]byte(r.than), &body); err != nil {
		t.Fatalf("không đọc được công nợ: %v %s", err, catBot(r.than))
	}
	return body.Data.Tong, body.Data.Don
}

// KB CN01–CN06
func TestCRMQuay_DonConNoCuaKhach(t *testing.T) {
	h := dungHeThong(t)
	a, b := haiCuaHang(t, h)
	moCa(t, h, a, 0)
	thuNgan := h.dangNhapVoi(t, a.ma, "nhanvien")
	duong := fmt.Sprintf("/api/v1/admin/orders/pos/khach-hang/%d/con-no", a.khach)
	soNo := func() float64 {
		return doc(t, h.goi(t, thuNgan, http.MethodGet, fmt.Sprintf("/api/v1/admin/orders/pos/khach-hang/%d", a.khach), nil))["still_in_debt"].(float64)
	}

	ctx := tenant.WithID(context.Background(), a.id)
	cu := gieoDon(t, h.db, ctx, a, "CN-CU-"+a.vet, "delivered")
	moi := gieoDon(t, h.db, ctx, a, "CN-MOI-"+a.vet, "delivered")
	huy := gieoDon(t, h.db, ctx, a, "CN-HUY-"+a.vet, domain.OrderStatusCancelled)
	// Đơn "cũ" phải cũ thật: gieo cùng giây thì thứ tự chỉ còn dựa vào id.
	h.db.WithContext(ctx).Model(&domain.Order{}).Where("id = ?", cu).Update("created_at", "2026-01-01 08:00:00")

	// CN01 cũ trước, bỏ đơn huỷ, tổng = still_in_debt của danh sách khách.
	tong, ds := docConNo(t, h.goi(t, thuNgan, http.MethodGet, duong, nil))
	if len(ds) == 0 || ds[0].ID != cu {
		t.Fatalf("CN01 đơn cũ nhất phải đứng đầu, nhận %+v", ds)
	}
	var cong float64
	for _, d := range ds {
		cong += d.ConNo
		if d.ID == huy {
			t.Fatalf("CN01 đơn huỷ không phải nợ: %+v", d)
		}
		if !d.ThuDuoc {
			t.Fatalf("CN01 đơn cùng chi nhánh phải thu_duoc: %+v", d)
		}
	}
	if cong != tong || tong != soNo() {
		t.Fatalf("CN01 tổng %v, cộng dòng %v, still_in_debt %v phải bằng nhau", tong, cong, soNo())
	}

	// CN02 thu một phần đơn cũ: dòng giảm đúng số thu, tổng giảm theo.
	if r := h.goi(t, thuNgan, http.MethodPost, fmt.Sprintf("/api/v1/admin/orders/%d/payments", cu),
		map[string]any{"amount": 40000, "payment_method": "cash"}); r.ma != http.StatusOK {
		t.Fatalf("CN02 thu một phần trả %d %s", r.ma, catBot(r.than))
	}
	tong2, ds := docConNo(t, h.goi(t, thuNgan, http.MethodGet, duong, nil))
	if ds[0].ID != cu || ds[0].ConNo != 60000 || ds[0].DaThu != 40000 || tong2 != tong-40000 || tong2 != soNo() {
		t.Fatalf("CN02 sau thu 40.000: dòng đầu %+v, tổng %v (trước %v), still_in_debt %v", ds[0], tong2, tong, soNo())
	}

	// CN03 thu nốt: đơn rời danh sách, đơn kế tiếp lên đầu.
	if r := h.goi(t, thuNgan, http.MethodPost, fmt.Sprintf("/api/v1/admin/orders/%d/payments", cu),
		map[string]any{"amount": 60000, "payment_method": "cash"}); r.ma != http.StatusOK {
		t.Fatalf("CN03 thu nốt trả %d %s", r.ma, catBot(r.than))
	}
	_, ds = docConNo(t, h.goi(t, thuNgan, http.MethodGet, duong, nil))
	for _, d := range ds {
		if d.ID == cu {
			t.Fatalf("CN03 đơn đã thu đủ vẫn nằm trong danh sách nợ: %+v", d)
		}
	}
	timMoi := false
	for _, d := range ds {
		timMoi = timMoi || d.ID == moi
	}
	if !timMoi {
		t.Fatalf("CN03 đơn còn nợ khác phải còn: %+v", ds)
	}

	// CN04 đơn của chi nhánh khác: vẫn cộng vào tổng nhưng thu_duoc=false, và thu thật bị chặn.
	cn2 := &domain.ChiNhanh{Code: "cn2-" + a.vet, Name: "Chi nhánh 2 " + a.vet, IsActive: true}
	tao(t, h.db, ctx, cn2)
	khac := gieoDon(t, h.db, ctx, a, "CN-KHAC-"+a.vet, "delivered")
	h.db.WithContext(ctx).Model(&domain.Order{}).Where("id = ?", khac).Update("shop_id", cn2.ID)
	_, ds = docConNo(t, h.goiChiNhanh(t, a.token, a.chiNhanh, http.MethodGet, duong, nil))
	thay := false
	for _, d := range ds {
		if d.ID == khac {
			thay = true
			if d.ThuDuoc {
				t.Fatalf("CN04 đơn chi nhánh 2 xem từ chi nhánh 1 không được thu_duoc: %+v", d)
			}
		} else if !d.ThuDuoc {
			t.Fatalf("CN04 đơn chi nhánh 1 phải thu_duoc: %+v", d)
		}
	}
	if !thay {
		t.Fatalf("CN04 đơn chi nhánh khác vẫn phải bày để tổng khớp: %+v", ds)
	}
	if r := h.goiChiNhanh(t, a.token, a.chiNhanh, http.MethodPost, fmt.Sprintf("/api/v1/admin/orders/%d/payments", khac),
		map[string]any{"amount": 1000, "payment_method": "bank_transfer"}); r.ma == http.StatusOK {
		t.Fatalf("CN04 thu đơn chi nhánh khác phải bị chặn — thu_duoc=false mới có nghĩa")
	}
	_, ds = docConNo(t, h.goiChiNhanh(t, a.token, cn2.ID, http.MethodGet, duong, nil))
	for _, d := range ds {
		if d.ThuDuoc != (d.ID == khac) {
			t.Fatalf("CN04 xem từ chi nhánh 2 thì chỉ đơn chi nhánh 2 thu_duoc: %+v", d)
		}
	}

	// CN05 khách của cửa hàng khác: không lộ đơn nào.
	if _, ds := docConNo(t, h.goi(t, a.token, http.MethodGet, fmt.Sprintf("/api/v1/admin/orders/pos/khach-hang/%d/con-no", b.khach), nil)); len(ds) != 0 {
		t.Fatalf("CN05 khách cửa hàng khác phải rỗng, nhận %+v", ds)
	}

	// CN06 id hỏng → 400.
	if r := h.goi(t, thuNgan, http.MethodGet, "/api/v1/admin/orders/pos/khach-hang/abc/con-no", nil); r.ma != http.StatusBadRequest {
		t.Fatalf("CN06 id hỏng phải 400, nhận %d", r.ma)
	}
}
