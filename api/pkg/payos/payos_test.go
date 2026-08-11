package payos

import (
	"context"
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"

	"sass-api/config"
)

const testChecksum = "checksum-key-de-test"

func testClient(baseURL string) *Client {
	return New(config.PayOSConfig{
		ClientID:    "client-id",
		APIKey:      "api-key",
		ChecksumKey: testChecksum,
		BaseURL:     baseURL,
		ReturnURL:   "http://localhost:8000/thanh-toan/ket-qua",
		CancelURL:   "http://localhost:8000/thanh-toan/ket-qua",
	})
}

// hmacHex ký giống hệt PayOS, dùng để dựng dữ liệu giả lập trong test.
func hmacHex(raw string) string {
	mac := hmac.New(sha256.New, []byte(testChecksum))
	mac.Write([]byte(raw))
	return hex.EncodeToString(mac.Sum(nil))
}

// Số tiền phải giữ nguyên văn trong chuỗi ký. Đi qua float64 thì 1750000 in ra
// thành "1.75e+06" và mọi chữ ký đều sai — lỗi này không lộ ra ở số nhỏ.
func TestCanonicalizeGiuNguyenSoLon(t *testing.T) {
	got, err := canonicalize(json.RawMessage(`{"orderCode":1750000000123,"amount":1750000}`))
	if err != nil {
		t.Fatalf("canonicalize lỗi: %v", err)
	}
	want := "amount=1750000&orderCode=1750000000123"
	if got != want {
		t.Fatalf("chuỗi ký sai\n có: %s\nmuốn: %s", got, want)
	}
}

// Khoá phải xếp theo alphabet, giá trị rỗng thành chuỗi rỗng.
func TestCanonicalizeSapKhoaVaGiaTriRong(t *testing.T) {
	got, err := canonicalize(json.RawMessage(`{"desc":"ok","code":"00","reference":null,"virtualAccountName":"undefined"}`))
	if err != nil {
		t.Fatalf("canonicalize lỗi: %v", err)
	}
	want := "code=00&desc=ok&reference=&virtualAccountName="
	if got != want {
		t.Fatalf("chuỗi ký sai\n có: %s\nmuốn: %s", got, want)
	}
}

func TestParseWebhookChapNhanChuKyDung(t *testing.T) {
	data := `{"orderCode":1750000000123,"amount":250000,"code":"00","desc":"success","reference":"FT123"}`
	canonical, err := canonicalize(json.RawMessage(data))
	if err != nil {
		t.Fatalf("canonicalize lỗi: %v", err)
	}
	body := `{"code":"00","desc":"success","success":true,"data":` + data + `,"signature":"` + hmacHex(canonical) + `"}`

	got, err := testClient("http://khong-goi-toi").ParseWebhook([]byte(body))
	if err != nil {
		t.Fatalf("ParseWebhook lỗi: %v", err)
	}
	if got.OrderCode != 1750000000123 || got.Amount != 250000 {
		t.Fatalf("đọc sai dữ liệu: %+v", got)
	}
	if !got.Succeeded() {
		t.Fatalf("webhook code=00 phải là thành công")
	}
}

// Đây là bài kiểm tra quan trọng nhất của gói: sửa số tiền trong webhook mà chữ
// ký cũ vẫn lọt thì bất kỳ ai cũng tự báo "đã thanh toán" được.
func TestParseWebhookTuChoiDuLieuBiSua(t *testing.T) {
	data := `{"orderCode":1750000000123,"amount":250000,"code":"00","desc":"success"}`
	canonical, _ := canonicalize(json.RawMessage(data))
	sig := hmacHex(canonical)

	sua := `{"orderCode":1750000000123,"amount":1,"code":"00","desc":"success"}`
	body := `{"code":"00","desc":"success","success":true,"data":` + sua + `,"signature":"` + sig + `"}`

	if _, err := testClient("http://khong-goi-toi").ParseWebhook([]byte(body)); err != ErrSignature {
		t.Fatalf("phải trả ErrSignature, nhận: %v", err)
	}
}

func TestParseWebhookTuChoiKhiThieuChuKy(t *testing.T) {
	body := `{"code":"00","desc":"success","success":true,"data":{"orderCode":1,"amount":1}}`
	if _, err := testClient("http://khong-goi-toi").ParseWebhook([]byte(body)); err != ErrSignature {
		t.Fatalf("phải trả ErrSignature, nhận: %v", err)
	}
}

// Chữ ký lúc tạo link chỉ tính trên 5 trường, theo đúng thứ tự alphabet — thêm
// hay bớt trường nào là PayOS từ chối toàn bộ request.
func TestCreateLinkKyDungNamTruong(t *testing.T) {
	var got CreateRequest
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if err := json.NewDecoder(r.Body).Decode(&got); err != nil {
			t.Errorf("đọc body lỗi: %v", err)
		}
		if r.Header.Get("x-client-id") != "client-id" || r.Header.Get("x-api-key") != "api-key" {
			t.Errorf("thiếu header xác thực: %v", r.Header)
		}

		data := `{"checkoutUrl":"https://pay.payos.vn/web/abc","paymentLinkId":"abc","orderCode":1750000000123,"amount":250000,"status":"PENDING","qrCode":"00020101"}`
		canonical, _ := canonicalize(json.RawMessage(data))
		w.Header().Set("Content-Type", "application/json")
		_, _ = w.Write([]byte(`{"code":"00","desc":"success","data":` + data + `,"signature":"` + hmacHex(canonical) + `"}`))
	}))
	defer srv.Close()

	c := testClient(srv.URL)
	link, err := c.CreateLink(context.Background(), CreateRequest{
		OrderCode:   1750000000123,
		Amount:      250000,
		Description: "DH202607310001",
		BuyerName:   "Nguyễn Văn A",
		CancelURL:   c.cfg.CancelURL,
		ReturnURL:   c.cfg.ReturnURL,
	})
	if err != nil {
		t.Fatalf("CreateLink lỗi: %v", err)
	}
	if link.CheckoutURL != "https://pay.payos.vn/web/abc" {
		t.Fatalf("đọc sai checkoutUrl: %q", link.CheckoutURL)
	}

	want := hmacHex("amount=250000" +
		"&cancelUrl=" + c.cfg.CancelURL +
		"&description=DH202607310001" +
		"&orderCode=1750000000123" +
		"&returnUrl=" + c.cfg.ReturnURL)
	if got.Signature != want {
		t.Fatalf("chữ ký tạo link sai\n có: %s\nmuốn: %s", got.Signature, want)
	}
}

// Phản hồi của cổng cũng phải chứng minh là của cổng: máy chủ trả dữ liệu không
// ký đúng thì coi như không tin được.
func TestCallTuChoiPhanHoiSaiChuKy(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		_, _ = w.Write([]byte(`{"code":"00","desc":"success","data":{"status":"PAID","orderCode":1},"signature":"deadbeef"}`))
	}))
	defer srv.Close()

	if _, err := testClient(srv.URL).GetLink(context.Background(), 1); err != ErrSignature {
		t.Fatalf("phải trả ErrSignature, nhận: %v", err)
	}
}

// Lỗi nghiệp vụ của PayOS (code khác "00") phải nổi lên nguyên văn để log đọc được.
func TestCallTraLoiNghiepVuCuaPayOS(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		_, _ = w.Write([]byte(`{"code":"231","desc":"Đơn thanh toán đã tồn tại","data":null}`))
	}))
	defer srv.Close()

	_, err := testClient(srv.URL).GetLink(context.Background(), 1)
	var apiErr *APIError
	if err == nil || !strings.Contains(err.Error(), "231") {
		t.Fatalf("phải là lỗi 231, nhận: %v", err)
	}
	if !asAPIError(err, &apiErr) {
		t.Fatalf("phải là *APIError, nhận: %T", err)
	}
}

func asAPIError(err error, target **APIError) bool {
	e, ok := err.(*APIError)
	if ok {
		*target = e
	}
	return ok
}

// Chưa khai khoá thì mọi lời gọi phải từ chối ngay, không gửi request nào đi.
func TestChuaCauHinhThiKhongGoi(t *testing.T) {
	c := New(config.PayOSConfig{BaseURL: "http://khong-goi-toi"})
	if c.Enabled() {
		t.Fatalf("thiếu khoá mà vẫn báo Enabled")
	}
	if _, err := c.CreateLink(context.Background(), CreateRequest{}); err != ErrNotConfigured {
		t.Fatalf("phải trả ErrNotConfigured, nhận: %v", err)
	}
	if _, err := c.ParseWebhook([]byte(`{}`)); err != ErrNotConfigured {
		t.Fatalf("phải trả ErrNotConfigured, nhận: %v", err)
	}
}
