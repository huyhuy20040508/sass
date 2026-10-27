package apitest

import (
	"fmt"
	"net/http"
	"strings"
	"testing"
)

// Lỗi 67 — cổng báo LỆCH số tiền không được chốt lượt thu thành failed: gói báo
// ĐÚNG tới sau phải hoàn tất đơn. Ở đây là SePay; NextPay nằm trong TestMpos_QRTaiQuay.
func TestQuay_SePayLechTienRoiDung(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	moCa(t, h, a, 0)
	ma := ketNoiSePay(t, h, a)
	thuNgan := h.dangNhapVoi(t, a.ma, "nhanvien")

	_, d := banQuayTT(t, h, thuNgan, a, map[string]any{"payment_method": "sepay"})
	hoi := func() traLoi {
		return h.goi(t, thuNgan, http.MethodGet, "/api/v1/admin/orders/pos/payments/"+d.Payment.Transaction, nil)
	}

	if r := baoTienSePay(t, h, ma, khoaSePayTest, "CK "+d.OrderCode, int(d.Total)-5000); r.ma != http.StatusOK {
		t.Fatalf("gói lệch vẫn phải trả 200 cho SePay (không bắt gửi lại), nhận %d", r.ma)
	}
	if st, pay, _, _ := cotDon(t, h, d.OrderID); st != "pending" || pay == "paid" {
		t.Fatalf("lệch số tiền không được hoàn tất đơn, nhận %s %s", st, pay)
	}
	var tt string
	h.db.WithContext(ctxThoat()).Raw("SELECT status FROM payments WHERE order_id = ? ORDER BY id DESC LIMIT 1", d.OrderID).Scan(&tt)
	if tt != "pending" {
		t.Fatalf("lượt thu phải giữ pending sau gói lệch, nhận %s", tt)
	}
	if r := hoi(); !strings.Contains(r.than, `"status":"pending"`) || !strings.Contains(r.than, fmt.Sprintf(`"lech_tien":%v`, d.Total-5000)) ||
		!strings.Contains(r.than, "kiểm lại với khách") {
		t.Fatalf("quầy phải thấy mã còn chờ + số lệch + câu nhắc, nhận %s", catBot(r.than))
	}

	baoTienSePay(t, h, ma, khoaSePayTest, "CK "+d.OrderCode, int(d.Total))
	if st, pay, _, _ := cotDon(t, h, d.OrderID); st != "completed" || pay != "paid" {
		t.Fatalf("gói đúng tới sau gói lệch phải hoàn tất đơn, nhận %s %s", st, pay)
	}
	if r := hoi(); !strings.Contains(r.than, `"paid":true`) || strings.Contains(r.than, "lech_tien") {
		t.Fatalf("đã trả đủ thì hết câu nhắc lệch, nhận %s", catBot(r.than))
	}
}

// KB #83 — đơn quầy QR bị bỏ dở huỷ được ở màn quản trị (next_statuses có
// "cancelled"), và huỷ ở đó cũng chốt mã QR như nút ở quầy: khách quét mã cũ
// sau đó thì tiền không ghi vào đơn đã huỷ.
func TestQuay_QuanTriHuyDonQRDangCho(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	moCa(t, h, a, 0)
	ma := ketNoiSePay(t, h, a)
	thuNgan := h.dangNhapVoi(t, a.ma, "nhanvien")
	ton := tonBienThe(t, h, a.bienThe)

	_, d := banQuayTT(t, h, thuNgan, a, map[string]any{"payment_method": "sepay"})
	ct := h.goi(t, a.token, http.MethodGet, fmt.Sprintf("/api/v1/admin/orders/%d", d.OrderID), nil)
	if !strings.Contains(ct.than, `"next_statuses":["cancelled"]`) {
		t.Fatalf("đơn QR đang chờ phải huỷ được ở quản trị, nhận %s", catBot(ct.than))
	}
	if r := h.goi(t, a.token, http.MethodPut, fmt.Sprintf("/api/v1/admin/orders/%d/status", d.OrderID),
		map[string]any{"status": "cancelled", "note": "Khách bỏ không quét"}); r.ma != http.StatusOK {
		t.Fatalf("huỷ đơn QR đang chờ phải 200, nhận %d\n%s", r.ma, catBot(r.than))
	}
	var tt string
	h.db.WithContext(ctxThoat()).Raw("SELECT status FROM payments WHERE order_id = ? ORDER BY id DESC LIMIT 1", d.OrderID).Scan(&tt)
	if tt != "cancelled" {
		t.Fatalf("huỷ đơn phải chốt lượt chờ QR thành cancelled, nhận %s", tt)
	}

	baoTienSePay(t, h, ma, khoaSePayTest, "CK "+d.OrderCode, int(d.Total))
	if st, pay, _, _ := cotDon(t, h, d.OrderID); st != "cancelled" || pay == "paid" {
		t.Fatalf("tiền quét muộn không được ghi vào đơn đã huỷ, nhận %s %s", st, pay)
	}
	if got := tonBienThe(t, h, a.bienThe); got != ton {
		t.Fatalf("huỷ đơn phải trả hàng về kho (%d), đang %d", ton, got)
	}
}
