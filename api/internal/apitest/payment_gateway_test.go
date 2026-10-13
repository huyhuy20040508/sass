package apitest

import (
	"encoding/json"
	"fmt"
	"net/http"
	"strings"
	"testing"
)

// CỬA HÀNG TỰ KẾT NỐI CỔNG QR — như ordertable v2: chủ tiệm khai khoá, chép URL
// webhook RIÊNG sang trang cổng, tiền về là đơn tự chuyển "đã thanh toán".
//
// Bộ test không khai khoá SePay/PayOS chung (.env) nào, nên mọi lượt nhận tiền ở
// đây đều đi bằng khoá của chính cửa hàng — đúng thứ cần chứng minh.

const khoaSePayTest = "khoa-webhook-cua-tiem-a"

// ketNoiSePay khai cổng SePay cho chi nhánh trung tâm của c, bật QR tự động,
// trả mã webhook.
func ketNoiSePay(t *testing.T, h *heThong, c *cuaHang) string {
	t.Helper()
	ds := docPM(t, h, c.token)
	qr, _, _ := ds.pt(6)
	r := h.goi(t, c.token, http.MethodPost, fmt.Sprintf("/api/v1/admin/payment-methods/%d/details", qr), map[string]any{
		"detail_type": "sepay", "data": map[string]string{
			"bank_id": "970422", "sepay_account": "0868999" + c.vet[:3], "bank_account_name": "Cua hang " + c.vet,
			"sepay_api_key": khoaSePayTest,
		},
	})
	if r.ma != http.StatusOK {
		t.Fatalf("khai SePay trả %d %s", r.ma, catBot(r.than))
	}
	if r := h.goi(t, c.token, http.MethodPut, fmt.Sprintf("/api/v1/admin/payment-methods/%d/status", qr), map[string]any{"status": true}); r.ma != http.StatusOK {
		t.Fatalf("bật QR tự động trả %d %s", r.ma, catBot(r.than))
	}
	res := h.goi(t, c.token, http.MethodGet, "/api/v1/admin/payment-methods", nil)
	var body struct {
		Data struct {
			Methods []struct {
				Type    int `json:"type"`
				Details []struct {
					DetailType  string `json:"detail_type"`
					WebhookCode string `json:"webhook_code"`
				} `json:"details"`
			} `json:"methods"`
		} `json:"data"`
	}
	_ = json.Unmarshal([]byte(res.than), &body)
	for _, m := range body.Data.Methods {
		for _, d := range m.Details {
			if d.DetailType == "sepay" {
				return d.WebhookCode
			}
		}
	}
	t.Fatalf("không thấy mã webhook của cổng SePay: %s", catBot(res.than))

	return ""
}

func baoTienSePay(t *testing.T, h *heThong, ma, khoa, noiDung string, soTien int) traLoi {
	t.Helper()

	return h.goiVoiHeader(t, "", http.MethodPost, "/api/v1/payments/sepay/webhook/"+ma, map[string]any{
		"id": 1, "gateway": "MBBank", "transactionDate": "2026-10-12 10:00:00", "accountNumber": "0868",
		"content": noiDung, "transferType": "in", "transferAmount": soTien, "referenceCode": "FT" + ma[:6],
	}, map[string]string{"Authorization": "Apikey " + khoa})
}

// KB WH01–WH03 — URL webhook riêng: mã ngẫu nhiên, kiểm bằng khoá của chính tiệm.
func TestCongRieng_WebhookTheoMa(t *testing.T) {
	h := dungHeThong(t)
	a, b := haiCuaHang(t, h)
	ma := ketNoiSePay(t, h, a)
	if len(ma) != 32 || strings.Trim(ma, "0123456789abcdef") != "" {
		t.Fatalf("mã webhook phải là 32 ký tự hex ngẫu nhiên, nhận %q", ma)
	}
	maB := ketNoiSePay(t, h, b)
	if maB == ma {
		t.Fatalf("hai cửa hàng không được trùng mã webhook")
	}

	// Mã lạ → 404; nhầm cổng (mã SePay gửi vào đường PayOS) → 404; sai khoá → 401.
	if r := baoTienSePay(t, h, "00000000000000000000000000000000", khoaSePayTest, "x", 1); r.ma != http.StatusNotFound {
		t.Fatalf("mã lạ phải 404, nhận %d", r.ma)
	}
	if r := h.goiVoiHeader(t, "", http.MethodPost, "/api/v1/payments/payos/webhook/"+ma, map[string]any{}, nil); r.ma != http.StatusNotFound {
		t.Fatalf("mã SePay vào đường PayOS phải 404, nhận %d", r.ma)
	}
	if r := baoTienSePay(t, h, ma, "khoa-sai", "x", 1); r.ma != http.StatusUnauthorized {
		t.Fatalf("sai khoá phải 401, nhận %d %s", r.ma, catBot(r.than))
	}
	if strings.Contains(h.goi(t, a.token, http.MethodGet, "/api/v1/admin/payment-methods", nil).than, "last_webhook_at") {
		t.Fatalf("gói sai khoá không được ghi là đã thông")
	}

	// Đúng khoá, nội dung không khớp đơn nào ("Gửi thử" bên SePay) → 200 + ghi lần báo.
	r := baoTienSePay(t, h, ma, khoaSePayTest, "GUI THU", 10000)
	if r.ma != http.StatusOK || !strings.Contains(r.than, `"success":true`) {
		t.Fatalf("gói thử đúng khoá phải 200 success, nhận %d %s", r.ma, catBot(r.than))
	}
	if !strings.Contains(h.goi(t, a.token, http.MethodGet, "/api/v1/admin/payment-methods", nil).than, "last_webhook_at") {
		t.Fatalf("nhận gói đúng khoá thì màn cấu hình phải thấy lần báo tiền")
	}
	// Cửa hàng B không bị ghi gì.
	if strings.Contains(h.goi(t, b.token, http.MethodGet, "/api/v1/admin/payment-methods", nil).than, "last_webhook_at") {
		t.Fatalf("gói của A ghi nhầm sang B")
	}

	// Kiểm tra kết nối: đã nhận báo tiền → đạt.
	ds := docPM(t, h, a.token)
	qr, _, _ := ds.pt(6)
	var id uint
	for _, m := range ds.Methods {
		if m.Type == 6 {
			id = m.Details[0].ID
		}
	}
	kt := h.goi(t, a.token, http.MethodPost, fmt.Sprintf("/api/v1/admin/payment-methods/%d/details/%d/test", qr, id), map[string]any{"webhook_url": "https://x.test/hook"})
	if kt.ma != http.StatusOK || !strings.Contains(kt.than, `"ok":true`) {
		t.Fatalf("kiểm tra kết nối sau khi nhận báo tiền phải đạt, nhận %d %s", kt.ma, catBot(kt.than))
	}
	// Bỏ khoá (sửa với khoá trống giữ khoá cũ) — dòng PayOS chưa đủ khoá thì báo đang dùng khoá chung.
	r = h.goi(t, a.token, http.MethodPost, fmt.Sprintf("/api/v1/admin/payment-methods/%d/details", qr), map[string]any{"detail_type": "payos", "data": map[string]string{}})
	if r.ma != http.StatusOK {
		t.Fatalf("PayOS để trống khoá (dùng khoá chung) phải thêm được, nhận %d %s", r.ma, catBot(r.than))
	}
	kt = h.goi(t, a.token, http.MethodPost, fmt.Sprintf("/api/v1/admin/payment-methods/%d/details/%d/test", qr, idTra(t, r)), map[string]any{"webhook_url": "https://x.test/hook"})
	if !strings.Contains(kt.than, `"ok":false`) || !strings.Contains(kt.than, "khoá chung") {
		t.Fatalf("PayOS chưa khai khoá phải báo đang dùng khoá chung, nhận %s", catBot(kt.than))
	}
	// SePay thiếu cả khoá webhook lẫn token: 422.
	if r := h.goi(t, b.token, http.MethodPost, fmt.Sprintf("/api/v1/admin/payment-methods/%d/details", docPMQR(t, h, b)), map[string]any{
		"detail_type": "sepay", "data": map[string]string{"bank_id": "970422", "sepay_account": "1", "bank_account_name": "A"},
	}); r.ma != http.StatusUnprocessableEntity || !strings.Contains(r.than, `"sepay_api_key"`) {
		t.Fatalf("SePay thiếu cả khoá webhook lẫn token phải 422 ở ô sepay_api_key, nhận %d %s", r.ma, catBot(r.than))
	}
}

func docPMQR(t *testing.T, h *heThong, c *cuaHang) uint {
	t.Helper()
	id, _, _ := docPM(t, h, c.token).pt(6)

	return id
}

// KB WH04 — trọn vòng: website chào SePay nhờ khoá của tiệm (máy chủ không có khoá
// chung), QR chuyển vào tài khoản CỦA TIỆM, báo tiền qua URL riêng → đơn đã trả.
func TestCongRieng_DatDonSePayTronVong(t *testing.T) {
	h := dungHeThongBanHang(t)
	a, _ := haiCuaHang(t, h)
	ma := ketNoiSePay(t, h, a)

	cai := h.goi(t, a.token, http.MethodGet, "/api/v1/settings", nil)
	if !strings.Contains(cai.than, `"payment_sepay_enabled":"1"`) {
		t.Fatalf("tiệm đã tự kết nối SePay thì website phải chào SePay: %s", catBot(cai.than))
	}

	res := h.goi(t, a.token, http.MethodPost, "/api/v1/orders", map[string]any{
		"recipient_name": "Khach " + a.vet, "recipient_phone": "0900000001", "shipping_address": "Ha Noi",
		"payment_method": "sepay", "items": []map[string]any{{"product_variant_id": a.bienThe, "quantity": 1}},
	})
	if res.ma != http.StatusCreated && res.ma != http.StatusOK {
		t.Fatalf("đặt đơn SePay trả %d %s", res.ma, catBot(res.than))
	}
	var don struct {
		Data struct {
			OrderCode string  `json:"order_code"`
			Total     float64 `json:"total_amount"`
			Payment   struct {
				QRImage       string `json:"qr_image"`
				AccountNumber string `json:"account_number"`
				Transaction   string `json:"transaction_code"`
			} `json:"payment"`
		} `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &don); err != nil {
		t.Fatalf("không đọc được đơn: %v", err)
	}
	soTK := "0868999" + a.vet[:3]
	if !strings.Contains(don.Data.Payment.QRImage, "acc="+soTK) {
		t.Fatalf("QR phải chuyển vào tài khoản của tiệm (%s), nhận %q", soTK, don.Data.Payment.QRImage)
	}

	// Thiếu tiền: không ghi nhận đã trả.
	if r := baoTienSePay(t, h, ma, khoaSePayTest, "CK "+don.Data.OrderCode, int(don.Data.Total)-1000); r.ma != http.StatusOK {
		t.Fatalf("báo tiền thiếu phải 200 (để SePay không gửi lại), nhận %d", r.ma)
	}
	// Đúng tiền, đúng mã đơn: đơn đã thanh toán.
	res2 := h.goi(t, a.token, http.MethodPost, "/api/v1/orders", map[string]any{
		"recipient_name": "Khach " + a.vet, "recipient_phone": "0900000001", "shipping_address": "Ha Noi",
		"payment_method": "sepay", "items": []map[string]any{{"product_variant_id": a.bienThe, "quantity": 1}},
	})
	var don2 struct {
		Data struct {
			OrderCode string  `json:"order_code"`
			Total     float64 `json:"total_amount"`
		} `json:"data"`
	}
	_ = json.Unmarshal([]byte(res2.than), &don2)
	if r := baoTienSePay(t, h, ma, khoaSePayTest, "CK "+don2.Data.OrderCode+" thanh toan", int(don2.Data.Total)); r.ma != http.StatusOK {
		t.Fatalf("báo tiền đúng phải 200, nhận %d %s", r.ma, catBot(r.than))
	}
	tt := h.goi(t, a.token, http.MethodGet, "/api/v1/payments/"+don2.Data.OrderCode, nil)
	if !strings.Contains(tt.than, `"paid":true`) {
		t.Fatalf("đơn đúng tiền phải thành đã thanh toán: %s", catBot(tt.than))
	}
	tt1 := h.goi(t, a.token, http.MethodGet, "/api/v1/payments/"+don.Data.OrderCode, nil)
	if strings.Contains(tt1.than, `"paid":true`) {
		t.Fatalf("đơn báo thiếu tiền không được thành đã thanh toán: %s", catBot(tt1.than))
	}
}
