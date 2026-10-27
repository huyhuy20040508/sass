package apitest

import (
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"testing"

	"sass-api/internal/tenant"
)

// Bài kiểm "CÒN NỢ" CỦA KHÁCH — phải cộng đúng như sổ đơn: đơn chưa thu đủ thì
// nợ = tổng đơn − các lượt đã thu (order_payments). Trước đây cả tiền đơn bị tính
// là nợ dù khách đã trả một phần, nên hồ sơ khách, cột xếp theo nợ và báo cáo CRM
// đều lệch với cột "Còn nợ" của sổ đơn.

func TestKhachHang_ConNoTruLuotThuMotPhan(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	// Khách gieo sẵn có vài đơn chưa thu; lấy mốc trước khi thêm lượt thu.
	truoc := doc(t, h.goi(t, a.token, http.MethodGet, fmt.Sprintf("/api/v1/admin/customers/%d", a.khach), nil))
	noTruoc := truoc["still_in_debt"].(float64)
	daTraTruoc := truoc["total_paid"].(float64)

	ctx := tenant.WithID(context.Background(), a.id)
	don := gieoDon(t, h.db, ctx, a, "NO-"+a.vet, "delivered")
	res := h.goi(t, a.token, http.MethodPost, fmt.Sprintf("/api/v1/admin/orders/%d/payments", don),
		map[string]any{"amount": 30000, "payment_method": "cash"})
	if res.ma != http.StatusOK && res.ma != http.StatusCreated {
		t.Fatalf("thu một phần đơn %d trả %d\n%s", don, res.ma, catBot(res.than))
	}

	sau := doc(t, h.goi(t, a.token, http.MethodGet, fmt.Sprintf("/api/v1/admin/customers/%d", a.khach), nil))
	if got := sau["still_in_debt"].(float64) - noTruoc; got != 70000 {
		t.Fatalf("đơn 100.000 đã thu 30.000 thì nợ của khách tăng 70.000, nhận %v", got)
	}
	if got := sau["total_paid"].(float64) - daTraTruoc; got != 30000 {
		t.Fatalf("lượt thu một phần 30.000 phải vào 'Tổng đã thanh toán', nhận %v", got)
	}

	// Ở quầy (cùng handler) con số phải y hệt.
	thuNgan := h.dangNhapVoi(t, a.ma, "nhanvien")
	quay := doc(t, h.goi(t, thuNgan, http.MethodGet, fmt.Sprintf("/api/v1/admin/orders/pos/khach-hang/%d", a.khach), nil))
	if quay["still_in_debt"] != sau["still_in_debt"] {
		t.Fatalf("quầy và Quản trị phải cùng một số nợ: %v ≠ %v", quay["still_in_debt"], sau["still_in_debt"])
	}

	// Báo cáo CRM: "Cần chăm sóc · còn nợ" cộng nợ của mọi khách — phải giảm đúng
	// 30.000 so với cách cũ (cả đơn là nợ), tức khớp tổng nợ trên hồ sơ các khách.
	rp := doc(t, h.goi(t, a.token, http.MethodGet, "/api/v1/admin/reports/crm?range=30d", nil))
	care, _ := rp["care"].(map[string]any)
	v, ok := care["debt_amount"].(float64)
	if !ok {
		t.Fatalf("báo cáo CRM thiếu care.debt_amount: %v", rp["care"])
	}
	if v != sau["still_in_debt"].(float64) {
		t.Fatalf("báo cáo CRM 'còn nợ' (%v) phải khớp nợ trên hồ sơ khách duy nhất có nợ (%v)", v, sau["still_in_debt"])
	}
}

func TestKhachHang_TimTheoMaKhach(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	res := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/customers", map[string]any{
		"full_name": "Tìm theo mã " + a.vet, "status": "active",
	})
	if res.ma != http.StatusCreated {
		t.Fatalf("thêm khách trả %d\n%s", res.ma, catBot(res.than))
	}
	ma, _ := doc(t, res)["customer_code"].(string)
	if ma == "" {
		t.Fatal("khách mới phải có mã tự sinh")
	}

	for _, duong := range []string{"/api/v1/admin/customers?keyword=", "/api/v1/admin/orders/pos/khach-hang?keyword="} {
		r := h.goi(t, a.token, http.MethodGet, duong+ma, nil)
		var body struct {
			Data []struct {
				CustomerCode string `json:"customer_code"`
			} `json:"data"`
		}
		_ = json.Unmarshal([]byte(r.than), &body)
		if len(body.Data) != 1 || body.Data[0].CustomerCode != ma {
			t.Fatalf("%s%s phải ra đúng khách mang mã đó, nhận %+v", duong, ma, body.Data)
		}
	}
}
