package sepay

import (
	"context"
	"net/http"
	"net/http/httptest"
	"net/url"
	"strings"
	"testing"
	"time"

	"sass-api/config"
)

const testKey = "khoa-webhook-de-test"

func testClient(apiBase string, token string) *Client {
	return New(config.SePayConfig{
		AccountNumber: "0123456789",
		Bank:          "MBBank",
		AccountName:   "NGUYEN VAN A",
		WebhookAPIKey: testKey,
		APIToken:      token,
		APIBaseURL:    apiBase,
		QRBaseURL:     "https://qr.sepay.vn",
	})
}

const webhookBody = `{
  "id": 92704,
  "gateway": "Vietcombank",
  "transactionDate": "2026-08-01 11:08:33",
  "accountNumber": "0123456789",
  "code": null,
  "content": "FB202608010053 chuyen tien",
  "transferType": "in",
  "description": "NGUYEN VAN B chuyen tien",
  "transferAmount": 32000,
  "accumulated": 105000000,
  "referenceCode": "FT24012345678"
}`

// Bài kiểm tra quan trọng nhất của gói: khoá webhook là thứ DUY NHẤT chứng minh
// gói dữ liệu đến từ SePay. Lọt khoá sai nghĩa là ai cũng tự báo "đã thanh toán".
func TestParseWebhookTuChoiKhoaSai(t *testing.T) {
	c := testClient("http://khong-goi-toi", "")

	for _, h := range []string{
		"",
		"Apikey khoa-sai",
		"Apikey " + testKey + "x",
		testKey[:len(testKey)-1],
		"Bearer ",
	} {
		if _, err := c.ParseWebhook(h, []byte(webhookBody)); err != ErrUnauthorized {
			t.Fatalf("header %q phải bị từ chối, nhận: %v", h, err)
		}
	}
}

// SePay cho chọn tiền tố khi cấu hình, đổi kiểu xác thực bên trang quản lý không
// được làm sập webhook đang chạy.
func TestParseWebhookChapNhanCacTienTo(t *testing.T) {
	c := testClient("http://khong-goi-toi", "")

	for _, h := range []string{"Apikey " + testKey, "ApiKey " + testKey, "Bearer " + testKey, testKey} {
		got, err := c.ParseWebhook(h, []byte(webhookBody))
		if err != nil {
			t.Fatalf("header %q phải được chấp nhận, nhận: %v", h, err)
		}
		if got.TransferAmount != 32000 || got.ID != 92704 {
			t.Fatalf("đọc sai dữ liệu: %+v", got)
		}
		if !got.IsIncoming() {
			t.Fatalf("transferType=in phải là tiền vào")
		}
	}
}

// Tiền RA cũng bắn về cùng địa chỉ webhook. Nhận nhầm nó là "khách đã trả tiền"
// thì mỗi lần cửa hàng chi tiền lại có một đơn được đánh dấu đã thanh toán.
func TestWebhookPhanBietTienRa(t *testing.T) {
	c := testClient("http://khong-goi-toi", "")
	body := strings.Replace(webhookBody, `"transferType": "in"`, `"transferType": "out"`, 1)

	got, err := c.ParseWebhook("Apikey "+testKey, []byte(body))
	if err != nil {
		t.Fatalf("ParseWebhook lỗi: %v", err)
	}
	if got.IsIncoming() {
		t.Fatal("transferType=out không được coi là tiền vào")
	}
}

// Mỗi ngân hàng nhào nặn nội dung một kiểu. Chuẩn hoá xong mã đơn vẫn phải nằm
// nguyên trong chuỗi, nếu không thì tiền về mà không đơn nào nhận.
func TestNormalizeContentGiuNguyenMaDon(t *testing.T) {
	const code = "FB202608010053"

	for _, raw := range []string{
		"FB202608010053 chuyen tien",
		"fb202608010053",
		"CT DEN:395xxxx FB 202608010053 CHUYEN TIEN",
		"MBVCB.123456.FB202608010053.CT tu 0123",
		"  fb-202608010053  ",
	} {
		if !strings.Contains(NormalizeContent(raw), code) {
			t.Fatalf("nội dung %q chuẩn hoá thành %q, mất mã đơn", raw, NormalizeContent(raw))
		}
	}

	// Nội dung của đơn khác thì không được khớp nhầm.
	if strings.Contains(NormalizeContent("FB202608010054 chuyen tien"), code) {
		t.Fatal("khớp nhầm sang mã đơn khác")
	}
}

func TestQRImageURLDungThamSo(t *testing.T) {
	c := testClient("http://khong-goi-toi", "")

	raw := c.QRImageURL(32000, "FB202608010053")
	u, err := url.Parse(raw)
	if err != nil {
		t.Fatalf("URL hỏng: %v", err)
	}
	q := u.Query()
	for key, want := range map[string]string{
		"acc":      "0123456789",
		"bank":     "MBBank",
		"amount":   "32000",
		"des":      "FB202608010053",
		"template": "compact",
	} {
		if got := q.Get(key); got != want {
			t.Fatalf("tham số %s = %q, muốn %q", key, got, want)
		}
	}
}

// Chỉ có API token (chạy ở máy local, không nhận webhook): vẫn đủ để bật SePay
// vì hệ thống tự dò sao kê được — nhưng webhook thì phải TỪ CHỐI SẠCH.
//
// Đây là chỗ dễ hỏng nhất của luật này: bỏ qua bước kiểm khi khoá rỗng thì mọi
// request không mang header cũng "khớp", và ai cũng tự báo đã thanh toán được.
func TestChiCoTokenThiKhongNhanWebhook(t *testing.T) {
	c := New(config.SePayConfig{
		AccountNumber: "0123456789",
		Bank:          "TPBank",
		APIToken:      "token-test",
		QRBaseURL:     "https://qr.sepay.vn",
	})

	if !c.Enabled() {
		t.Fatal("có tài khoản + token thì phải coi là đã bật")
	}
	if !c.CanQuery() {
		t.Fatal("có token thì phải tra cứu được")
	}
	if c.WebhookEnabled() {
		t.Fatal("chưa khai khoá webhook mà lại báo là nhận webhook")
	}

	// Không header, header rỗng, hay header bất kỳ — đều phải bị từ chối.
	for _, h := range []string{"", "Apikey ", "Apikey bat-ky", "Bearer token-test"} {
		if _, err := c.ParseWebhook(h, []byte(webhookBody)); err != ErrUnauthorized {
			t.Fatalf("header %q phải bị từ chối, nhận: %v", h, err)
		}
	}
}

// Có tài khoản nhưng không có đường nào biết tiền đã về (không khoá webhook, không
// token) thì coi như chưa bật: mã QR vẽ ra được nhưng không ai xác nhận nổi.
func TestKhongCoDuongXacNhanThiChuaBat(t *testing.T) {
	c := New(config.SePayConfig{
		AccountNumber: "0123456789",
		Bank:          "TPBank",
		QRBaseURL:     "https://qr.sepay.vn",
	})
	if c.Enabled() {
		t.Fatal("không có khoá webhook lẫn token mà vẫn báo đã bật")
	}
}

// Chưa khai tài khoản thì không dựng QR, không nhận webhook.
func TestChuaCauHinhThiTuChoi(t *testing.T) {
	c := New(config.SePayConfig{QRBaseURL: "https://qr.sepay.vn"})
	if c.Enabled() {
		t.Fatal("thiếu cấu hình mà vẫn báo Enabled")
	}
	if got := c.QRImageURL(1000, "FB1"); got != "" {
		t.Fatalf("phải trả chuỗi rỗng, nhận %q", got)
	}
	if _, err := c.ParseWebhook("Apikey x", []byte(webhookBody)); err != ErrNotConfigured {
		t.Fatalf("phải trả ErrNotConfigured, nhận: %v", err)
	}
}

// Không có token thì không tra cứu chủ động được — nói rõ ra thay vì im lặng
// coi như "chưa thấy tiền".
func TestFindIncomingCanToken(t *testing.T) {
	c := testClient("http://khong-goi-toi", "")
	if _, err := c.FindIncoming(context.Background(), "FB1", time.Now()); err != ErrNoAPIToken {
		t.Fatalf("phải trả ErrNoAPIToken, nhận: %v", err)
	}
}

func TestFindIncomingTimDungGiaoDich(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if got := r.Header.Get("Authorization"); got != "Bearer token-test" {
			t.Errorf("thiếu token: %q", got)
		}
		if got := r.URL.Query().Get("account_number"); got != "0123456789" {
			t.Errorf("thiếu số tài khoản: %q", got)
		}
		_, _ = w.Write([]byte(`{"status":200,"transactions":[
			{"id":"1","amount_in":"0.00","amount_out":"50000.00","transaction_content":"FB202608010053 rut tien"},
			{"id":"2","amount_in":"32000.00","amount_out":"0.00","transaction_content":"CT DEN FB202608010053 CHUYEN TIEN"},
			{"id":"3","amount_in":"99000.00","amount_out":"0.00","transaction_content":"FB202608010099"}
		]}`))
	}))
	defer srv.Close()

	c := testClient(srv.URL, "token-test")
	got, err := c.FindIncoming(context.Background(), "FB202608010053", time.Now())
	if err != nil {
		t.Fatalf("FindIncoming lỗi: %v", err)
	}
	if got == nil {
		t.Fatal("phải tìm thấy giao dịch")
	}
	// Dòng 1 cũng chứa mã đơn nhưng là tiền RA — không được nhận nhầm.
	if got.ID != "2" {
		t.Fatalf("chọn nhầm giao dịch: %+v", got)
	}
	if got.AmountInVND() != 32000 {
		t.Fatalf("đọc sai số tiền: %v", got.AmountInVND())
	}
}

// Không có giao dịch nào khớp thì trả (nil, nil) — "chưa thấy tiền", không phải lỗi.
func TestFindIncomingKhongThayThiKhongLoi(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		_, _ = w.Write([]byte(`{"status":200,"transactions":[]}`))
	}))
	defer srv.Close()

	got, err := testClient(srv.URL, "token-test").
		FindIncoming(context.Background(), "FB202608010053", time.Now())
	if err != nil || got != nil {
		t.Fatalf("phải là (nil, nil), nhận: (%v, %v)", got, err)
	}
}
