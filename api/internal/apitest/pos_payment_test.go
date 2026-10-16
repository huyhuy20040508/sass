package apitest

import (
	"encoding/json"
	"fmt"
	"math"
	"net/http"
	"strings"
	"testing"
)

// QUẦY THU ĐỦ 4 PHƯƠNG THỨC — tiền mặt, chuyển khoản, quẹt thẻ, QR tự động —
// theo cấu hình của chính chi nhánh (Cài đặt → Phương thức thanh toán).

type donQuayTT struct {
	OrderID       uint    `json:"order_id"`
	OrderCode     string  `json:"order_code"`
	Total         float64 `json:"total_amount"`
	Status        string  `json:"status"`
	PaymentStatus string  `json:"payment_status"`
	CardFee       float64 `json:"card_fee"`
	Payment       *struct {
		QRImage     string `json:"qr_image"`
		Transaction string `json:"transaction_code"`
	} `json:"payment"`
}

func banQuayTT(t *testing.T, h *heThong, tok string, c *cuaHang, them map[string]any) (traLoi, donQuayTT) {
	t.Helper()
	body := map[string]any{"customer_name": "Khach " + c.vet, "items": []map[string]any{{"product_variant_id": c.bienThe, "quantity": 1}}}
	for k, v := range them {
		body[k] = v
	}
	r := h.goi(t, tok, http.MethodPost, "/api/v1/admin/orders/pos", body)
	var d struct {
		Data donQuayTT `json:"data"`
	}
	_ = json.Unmarshal([]byte(r.than), &d)

	return r, d.Data
}

func cotDon(t *testing.T, h *heThong, id uint) (status, pay string, detail *uint, phi float64) {
	t.Helper()
	row := h.db.WithContext(ctxThoat()).Raw("SELECT status, payment_status, payment_method_detail_id, card_fee FROM orders WHERE id = ?", id).Row()
	if err := row.Scan(&status, &pay, &detail, &phi); err != nil {
		t.Fatalf("không đọc được đơn %d: %v", id, err)
	}

	return
}

func tonBienThe(t *testing.T, h *heThong, id uint) int {
	t.Helper()
	var n int
	h.db.WithContext(ctxThoat()).Raw("SELECT COALESCE(SUM(quantity), 0) FROM variant_stocks WHERE product_variant_id = ?", id).Scan(&n)

	return n
}

// KB Q01–Q03 — quẹt thẻ: chặn khi tắt, ghi phí + máy vào đơn, dòng đã dùng không xoá được.
func TestQuay_QuetThe(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	moCa(t, h, a, 0)

	if r, _ := banQuayTT(t, h, a.token, a, map[string]any{"payment_method": "card"}); r.ma != http.StatusUnprocessableEntity || !strings.Contains(r.than, "Quẹt thẻ đang tắt") {
		t.Fatalf("quẹt thẻ đang tắt phải 422 kèm lý do, nhận %d %s", r.ma, catBot(r.than))
	}

	may := idTra(t, h.goi(t, a.token, http.MethodPost, "/api/v1/admin/payment-devices", map[string]any{"code": "VCB-" + a.vet, "name": "May POS", "is_physical": true}))
	qt, _, _ := docPM(t, h, a.token).pt(5)
	r := h.goi(t, a.token, http.MethodPost, fmt.Sprintf("/api/v1/admin/payment-methods/%d/details", qt), map[string]any{
		"detail_type": "physical_device", "device_id": may,
		"data": map[string]string{"card_fee_physical": "1.5", "bank_id": "970436", "bank_account": "0071", "bank_account_name": "A"},
	})
	ct := idTra(t, r)
	if r := h.goi(t, a.token, http.MethodPut, fmt.Sprintf("/api/v1/admin/payment-methods/%d/status", qt), map[string]any{"status": true}); r.ma != http.StatusOK {
		t.Fatalf("bật quẹt thẻ trả %d", r.ma)
	}

	r, d := banQuayTT(t, h, a.token, a, map[string]any{"payment_method": "card"})
	if r.ma != http.StatusCreated || d.PaymentStatus != "paid" || d.Status != "completed" {
		t.Fatalf("quẹt thẻ phải bán xong ngay, nhận %d %s", r.ma, catBot(r.than))
	}
	if want := math.Round(d.Total * 1.5 / 100); d.CardFee != want || want == 0 {
		t.Fatalf("phí thẻ phải là %.0f, nhận %.0f", want, d.CardFee)
	}
	_, _, detail, phi := cotDon(t, h, d.OrderID)
	if detail == nil || *detail != ct || phi != d.CardFee {
		t.Fatalf("đơn phải ghi máy %d và phí %.0f, nhận %v %.0f", ct, d.CardFee, detail, phi)
	}

	// Dòng đã có đơn thu: không xoá được (v2: cannot_delete_payment_method_detail_used).
	// Bật thêm một máy để luật "dòng bật cuối" không chen vào trước.
	may2 := idTra(t, h.goi(t, a.token, http.MethodPost, "/api/v1/admin/payment-devices", map[string]any{"code": "VCB2-" + a.vet, "name": "May 2", "is_physical": true}))
	h.goi(t, a.token, http.MethodPost, fmt.Sprintf("/api/v1/admin/payment-methods/%d/details", qt), map[string]any{
		"detail_type": "physical_device", "device_id": may2, "data": map[string]string{"bank_id": "970436", "bank_account": "0072", "bank_account_name": "A"},
	})
	if r := h.goi(t, a.token, http.MethodDelete, fmt.Sprintf("/api/v1/admin/payment-methods/%d/details/%d", qt, ct), nil); r.ma != http.StatusConflict || !strings.Contains(r.than, "1 đơn") {
		t.Fatalf("xoá máy đã có đơn phải 409, nhận %d %s", r.ma, catBot(r.than))
	}
	// Máy id lạ: 422.
	if r, _ := banQuayTT(t, h, a.token, a, map[string]any{"payment_method": "card", "payment_detail_id": 999999}); r.ma != http.StatusUnprocessableEntity {
		t.Fatalf("máy lạ phải 422, nhận %d", r.ma)
	}
}

// KB Q04 — chuyển khoản ghi đúng tài khoản đã chọn; vẫn nhận chuyển khoản tay khi chưa cấu hình.
func TestQuay_ChuyenKhoanChonTaiKhoan(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	moCa(t, h, a, 0)
	if r, d := banQuayTT(t, h, a.token, a, map[string]any{"payment_method": "bank_transfer"}); r.ma != http.StatusCreated || d.PaymentStatus != "paid" {
		t.Fatalf("chuyển khoản tay khi chưa cấu hình vẫn phải bán được, nhận %d %s", r.ma, catBot(r.than))
	}
	ck, _, _ := docPM(t, h, a.token).pt(4)
	tk1 := idTra(t, h.goi(t, a.token, http.MethodPost, fmt.Sprintf("/api/v1/admin/payment-methods/%d/details", ck), taiKhoan("970436", "111", "A", nil)))
	tk2 := idTra(t, h.goi(t, a.token, http.MethodPost, fmt.Sprintf("/api/v1/admin/payment-methods/%d/details", ck), taiKhoan("970407", "222", "B", nil)))
	h.goi(t, a.token, http.MethodPut, fmt.Sprintf("/api/v1/admin/payment-methods/%d/status", ck), map[string]any{"status": true})

	_, d := banQuayTT(t, h, a.token, a, map[string]any{"payment_method": "bank_transfer"})
	if _, _, detail, _ := cotDon(t, h, d.OrderID); detail == nil || *detail != tk1 {
		t.Fatalf("không chọn thì ghi tài khoản mặc định %d, nhận %v", tk1, detail)
	}
	_, d = banQuayTT(t, h, a.token, a, map[string]any{"payment_method": "bank_transfer", "payment_detail_id": tk2})
	if _, _, detail, _ := cotDon(t, h, d.OrderID); detail == nil || *detail != tk2 {
		t.Fatalf("chọn tài khoản 2 thì đơn ghi %d, nhận %v", tk2, detail)
	}
}

// KB Q05–Q07 — QR tự động: đơn chờ tiền, QR vào tài khoản chi nhánh, tiền về tự xong;
// huỷ mã trả hàng về kho; tiền tới muộn không làm sống lại đơn đã huỷ.
func TestQuay_QRTuDong(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	moCa(t, h, a, 0)
	if r, _ := banQuayTT(t, h, a.token, a, map[string]any{"payment_method": "sepay"}); r.ma != http.StatusUnprocessableEntity {
		t.Fatalf("QR tự động đang tắt phải 422, nhận %d", r.ma)
	}
	ma := ketNoiSePay(t, h, a)
	thuNgan := h.dangNhapVoi(t, a.ma, "nhanvien")

	ton := tonBienThe(t, h, a.bienThe)
	r, d := banQuayTT(t, h, thuNgan, a, map[string]any{"payment_method": "sepay"})
	if r.ma != http.StatusCreated || d.Status != "pending" || d.PaymentStatus != "pending" || d.Payment == nil {
		t.Fatalf("đơn QR phải tạo ra CHỜ TIỀN kèm mã QR, nhận %d %s", r.ma, catBot(r.than))
	}
	if !strings.Contains(d.Payment.QRImage, "acc=0868999"+a.vet[:3]) {
		t.Fatalf("QR phải vào tài khoản của chi nhánh, nhận %q", d.Payment.QRImage)
	}
	if tonBienThe(t, h, a.bienThe) != ton-1 {
		t.Fatalf("đơn QR chờ tiền vẫn phải giữ hàng")
	}
	tt := h.goi(t, thuNgan, http.MethodGet, "/api/v1/admin/orders/pos/payments/"+d.Payment.Transaction, nil)
	if tt.ma != http.StatusOK || !strings.Contains(tt.than, `"paid":false`) {
		t.Fatalf("thu ngân hỏi trạng thái phải thấy chưa trả, nhận %d %s", tt.ma, catBot(tt.than))
	}
	if r := baoTienSePay(t, h, ma, khoaSePayTest, "CK "+d.OrderCode, int(d.Total)); r.ma != http.StatusOK {
		t.Fatalf("báo tiền trả %d", r.ma)
	}
	tt = h.goi(t, thuNgan, http.MethodGet, "/api/v1/admin/orders/pos/payments/"+d.Payment.Transaction, nil)
	if !strings.Contains(tt.than, `"paid":true`) {
		t.Fatalf("tiền về thì thu ngân phải thấy đã trả: %s", catBot(tt.than))
	}
	if st, pay, detail, _ := cotDon(t, h, d.OrderID); st != "completed" || pay != "paid" || detail == nil {
		t.Fatalf("đơn QR tiền về phải hoàn tất + ghi cổng, nhận %s %s %v", st, pay, detail)
	}
	// Đơn đã trả: không huỷ được.
	if r := h.goi(t, thuNgan, http.MethodPost, fmt.Sprintf("/api/v1/admin/orders/pos/%d/huy-qr", d.OrderID), nil); r.ma != http.StatusUnprocessableEntity {
		t.Fatalf("huỷ đơn đã trả phải 422, nhận %d %s", r.ma, catBot(r.than))
	}

	// Huỷ mã: đơn huỷ, hàng về kho; tiền tới muộn bị bỏ qua.
	ton = tonBienThe(t, h, a.bienThe)
	_, d2 := banQuayTT(t, h, thuNgan, a, map[string]any{"payment_method": "sepay"})
	if r := h.goi(t, thuNgan, http.MethodPost, fmt.Sprintf("/api/v1/admin/orders/pos/%d/huy-qr", d2.OrderID), nil); r.ma != http.StatusOK {
		t.Fatalf("thu ngân huỷ mã QR phải 200, nhận %d %s", r.ma, catBot(r.than))
	}
	if tonBienThe(t, h, a.bienThe) != ton {
		t.Fatalf("huỷ mã QR phải trả hàng về kho")
	}
	baoTienSePay(t, h, ma, khoaSePayTest, "CK "+d2.OrderCode, int(d2.Total))
	if st, pay, _, _ := cotDon(t, h, d2.OrderID); st != "cancelled" || pay == "paid" {
		t.Fatalf("tiền tới sau khi huỷ không được làm sống lại đơn, nhận %s %s", st, pay)
	}
	// Đơn tiền mặt không có gì để huỷ mã.
	_, d3 := banQuayTT(t, h, thuNgan, a, map[string]any{"payment_method": "cash"})
	if r := h.goi(t, thuNgan, http.MethodPost, fmt.Sprintf("/api/v1/admin/orders/pos/%d/huy-qr", d3.OrderID), nil); r.ma != http.StatusUnprocessableEntity {
		t.Fatalf("huỷ mã đơn tiền mặt phải 422, nhận %d", r.ma)
	}
}

// KB Q08–Q11 — đơn đang chờ QR, khách đổi sang trả cách khác: thu ngay trên CHÍNH đơn
// đó (giữ mã, không đơn huỷ, không trừ kho lần hai), tiền QR tới muộn không thu hai lần.
func TestQuay_DoiQRSangCachKhac(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	moCa(t, h, a, 0)
	ma := ketNoiSePay(t, h, a)
	thuNgan := h.dangNhapVoi(t, a.ma, "nhanvien")
	doi := func(id uint, body map[string]any) traLoi {
		t.Helper()

		return h.goi(t, thuNgan, http.MethodPost, fmt.Sprintf("/api/v1/admin/orders/pos/%d/doi-hinh-thuc", id), body)
	}

	// Ca đóng khi đơn còn chờ QR: tiền QR về sau vẫn ghi nhận (webhook không qua
	// két), còn đổi hình thức ở quầy thì bị chặn tới khi mở ca lại.
	_, d0 := banQuayTT(t, h, thuNgan, a, map[string]any{"payment_method": "sepay"})
	if r := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/ca-lam-viec/dong", map[string]any{"counted_cash": 0}); r.ma != http.StatusOK {
		t.Fatalf("đóng ca trả %d %s", r.ma, catBot(r.than))
	}
	if r := doi(d0.OrderID, map[string]any{"payment_method": "cash"}); r.ma != http.StatusConflict || !strings.Contains(r.than, `"CHUA_MO_CA"`) {
		t.Fatalf("đổi hình thức khi chưa mở ca phải 409 CHUA_MO_CA, nhận %d %s", r.ma, catBot(r.than))
	}
	baoTienSePay(t, h, ma, khoaSePayTest, "CK "+d0.OrderCode, int(d0.Total))
	if _, pay, _, _ := cotDon(t, h, d0.OrderID); pay != "paid" {
		t.Fatalf("tiền QR về lúc không có ca vẫn phải ghi nhận, đơn đang %s", pay)
	}
	moCa(t, h, a, 0)

	ton := tonBienThe(t, h, a.bienThe)
	_, d := banQuayTT(t, h, thuNgan, a, map[string]any{"payment_method": "sepay"})
	// Khách đưa thiếu: từ chối, mã QR vẫn sống.
	if r := doi(d.OrderID, map[string]any{"payment_method": "cash", "amount_tendered": d.Total - 1000}); r.ma != http.StatusBadRequest {
		t.Fatalf("khách đưa thiếu phải 400, nhận %d %s", r.ma, catBot(r.than))
	}
	r := doi(d.OrderID, map[string]any{"payment_method": "cash", "amount_tendered": d.Total + 50000})
	if r.ma != http.StatusOK || !strings.Contains(r.than, `"order_code":"`+d.OrderCode+`"`) || !strings.Contains(r.than, `"change_amount":50000`) {
		t.Fatalf("đổi sang tiền mặt phải 200, giữ mã đơn, tính tiền thối; nhận %d %s", r.ma, catBot(r.than))
	}
	if st, pay, detail, _ := cotDon(t, h, d.OrderID); st != "completed" || pay != "paid" || detail != nil {
		t.Fatalf("đơn đổi sang tiền mặt phải hoàn tất, không còn gắn cổng QR; nhận %s %s %v", st, pay, detail)
	}
	var pm string
	h.db.WithContext(ctxThoat()).Raw("SELECT payment_method FROM orders WHERE id = ?", d.OrderID).Scan(&pm)
	if pm != "cash" {
		t.Fatalf("hình thức của đơn phải đổi thành cash, nhận %s", pm)
	}
	if tonBienThe(t, h, a.bienThe) != ton-1 {
		t.Fatalf("đổi hình thức không được trừ kho lần hai")
	}
	// Tiền QR tới muộn: lần thử đã chốt huỷ — không ghi nhận thêm, đơn vẫn là tiền mặt.
	baoTienSePay(t, h, ma, khoaSePayTest, "CK "+d.OrderCode, int(d.Total))
	var soLanTra int
	h.db.WithContext(ctxThoat()).Raw("SELECT COUNT(*) FROM payments WHERE order_id = ? AND status = 'success'", d.OrderID).Scan(&soLanTra)
	if soLanTra != 0 {
		t.Fatalf("tiền QR tới sau khi đã thu tiền mặt không được ghi là đã trả")
	}
	// Đơn đã xong: không đổi được nữa.
	if r := doi(d.OrderID, map[string]any{"payment_method": "cash"}); r.ma != http.StatusUnprocessableEntity {
		t.Fatalf("đổi đơn đã hoàn tất phải 422, nhận %d", r.ma)
	}

	// Đổi sang quẹt thẻ: kèm phí của máy.
	may := idTra(t, h.goi(t, a.token, http.MethodPost, "/api/v1/admin/payment-devices", map[string]any{"code": "POS-" + a.vet, "name": "May", "is_physical": true}))
	qt, _, _ := docPM(t, h, a.token).pt(5)
	ct := idTra(t, h.goi(t, a.token, http.MethodPost, fmt.Sprintf("/api/v1/admin/payment-methods/%d/details", qt), map[string]any{
		"detail_type": "physical_device", "device_id": may, "data": map[string]string{"card_fee_physical": "2", "bank_id": "970436", "bank_account": "1", "bank_account_name": "A"},
	}))
	_, d2 := banQuayTT(t, h, thuNgan, a, map[string]any{"payment_method": "sepay"})
	// Quẹt thẻ đang tắt: từ chối, mã QR vẫn sống (vẫn huỷ được).
	if r := doi(d2.OrderID, map[string]any{"payment_method": "card"}); r.ma != http.StatusUnprocessableEntity {
		t.Fatalf("đổi sang quẹt thẻ khi đang tắt phải 422, nhận %d", r.ma)
	}
	h.goi(t, a.token, http.MethodPut, fmt.Sprintf("/api/v1/admin/payment-methods/%d/status", qt), map[string]any{"status": true})
	r = doi(d2.OrderID, map[string]any{"payment_method": "card", "payment_detail_id": ct})
	if r.ma != http.StatusOK {
		t.Fatalf("đổi sang quẹt thẻ phải 200, nhận %d %s", r.ma, catBot(r.than))
	}
	if _, pay, detail, phi := cotDon(t, h, d2.OrderID); pay != "paid" || detail == nil || *detail != ct || phi != math.Round(d2.Total*2/100) {
		t.Fatalf("đơn đổi sang quẹt thẻ phải ghi máy + phí 2%%; nhận %s %v %.0f", pay, detail, phi)
	}

	// Đơn tiền mặt thường: không có gì để đổi.
	_, d3 := banQuayTT(t, h, thuNgan, a, map[string]any{"payment_method": "cash"})
	if r := doi(d3.OrderID, map[string]any{"payment_method": "card"}); r.ma != http.StatusUnprocessableEntity {
		t.Fatalf("đổi đơn tiền mặt phải 422, nhận %d", r.ma)
	}
	// Tiền QR về trước khi thu ngân bấm đổi: từ chối, không thu hai lần.
	_, d4 := banQuayTT(t, h, thuNgan, a, map[string]any{"payment_method": "sepay"})
	baoTienSePay(t, h, ma, khoaSePayTest, "CK "+d4.OrderCode, int(d4.Total))
	if r := doi(d4.OrderID, map[string]any{"payment_method": "cash"}); r.ma != http.StatusUnprocessableEntity || !strings.Contains(r.than, "đã trả qua QR") {
		t.Fatalf("tiền QR đã về thì đổi phải 422 kèm lý do, nhận %d %s", r.ma, catBot(r.than))
	}
}
