// Package payos là client gọi cổng thanh toán PayOS (payos.vn) — tạo link thanh
// toán, tra trạng thái, huỷ link và kiểm chữ ký webhook.
//
// Toàn bộ giao tiếp với PayOS đều được ký bằng HMAC-SHA256 với `checksum key` của
// kênh thanh toán. Chữ ký là thứ DUY NHẤT phân biệt "PayOS báo đã thu tiền" với
// "ai đó gửi một request nói rằng đã thu tiền", nên mọi dữ liệu nhận về đều phải
// đi qua verifyData trước khi được tin.
package payos

import (
	"bytes"
	"context"
	"crypto/hmac"
	"crypto/sha256"
	"crypto/subtle"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"sort"
	"strconv"
	"strings"
	"time"

	"sass-api/config"
)

// Mã lỗi PayOS trả về khi mọi thứ suôn sẻ.
const codeSuccess = "00"

// DescriptionMax là giới hạn độ dài trường `description` của PayOS. Chuỗi này
// chính là nội dung chuyển khoản khách nhìn thấy trong app ngân hàng.
const DescriptionMax = 25

var (
	// ErrSignature — dữ liệu nhận về không khớp chữ ký. Coi như dữ liệu giả.
	ErrSignature = errors.New("payos: chữ ký không hợp lệ")
	// ErrNotConfigured — chưa khai đủ khoá trong .env.
	ErrNotConfigured = errors.New("payos: chưa cấu hình client id / api key / checksum key")
)

// APIError là lỗi nghiệp vụ do chính PayOS trả về (code khác "00").
type APIError struct {
	Code string
	Desc string
}

func (e *APIError) Error() string { return "payos: " + e.Code + " " + e.Desc }

// Client gọi API PayOS. Dùng lại một instance cho cả vòng đời ứng dụng.
type Client struct {
	cfg  config.PayOSConfig
	http *http.Client
}

// New dựng client từ cấu hình. Trả về client kể cả khi chưa khai khoá — mọi lời
// gọi sẽ trả ErrNotConfigured, để phần còn lại của ứng dụng không phải kiểm tra
// nil ở từng chỗ dùng.
func New(cfg config.PayOSConfig) *Client {
	return &Client{
		cfg: cfg,
		// Có hạn thời gian vì lời gọi này nằm ngay trên đường đặt hàng của khách:
		// PayOS treo thì khách phải nhận câu trả lời chứ không ngồi đợi vô hạn.
		http: &http.Client{Timeout: 15 * time.Second},
	}
}

// Enabled cho biết client đã đủ khoá để gọi thật hay chưa.
func (c *Client) Enabled() bool { return c.cfg.Enabled() }

// ---------- Tạo link thanh toán ----------

// Item là một dòng hàng hiển thị trên trang thanh toán của PayOS.
type Item struct {
	Name     string `json:"name"`
	Quantity int    `json:"quantity"`
	Price    int64  `json:"price"`
}

// CreateRequest là yêu cầu tạo link thanh toán.
//
// OrderCode là số nguyên do PHÍA MÌNH sinh ra và phải chưa từng dùng trên kênh
// thanh toán này — PayOS từ chối orderCode trùng, kể cả khi link cũ đã huỷ.
// Description tối đa 25 ký tự (xem DescriptionMax).
type CreateRequest struct {
	OrderCode    int64  `json:"orderCode"`
	Amount       int64  `json:"amount"`
	Description  string `json:"description"`
	BuyerName    string `json:"buyerName,omitempty"`
	BuyerEmail   string `json:"buyerEmail,omitempty"`
	BuyerPhone   string `json:"buyerPhone,omitempty"`
	BuyerAddress string `json:"buyerAddress,omitempty"`
	Items        []Item `json:"items,omitempty"`
	CancelURL    string `json:"cancelUrl"`
	ReturnURL    string `json:"returnUrl"`
	// ExpiredAt là mốc hết hạn dạng Unix giây; 0 = không đặt hạn.
	ExpiredAt int64  `json:"expiredAt,omitempty"`
	Signature string `json:"signature"`
}

// Link là link thanh toán vừa tạo.
type Link struct {
	Bin           string `json:"bin"`
	AccountNumber string `json:"accountNumber"`
	AccountName   string `json:"accountName"`
	Amount        int64  `json:"amount"`
	Description   string `json:"description"`
	OrderCode     int64  `json:"orderCode"`
	Currency      string `json:"currency"`
	PaymentLinkID string `json:"paymentLinkId"`
	Status        string `json:"status"`
	CheckoutURL   string `json:"checkoutUrl"`
	// QRCode là chuỗi VietQR — đủ để tự vẽ mã QR trên trang của mình, khách không
	// bắt buộc phải rời website sang trang của PayOS.
	QRCode string `json:"qrCode"`
}

// CreateLink tạo link thanh toán mới. Chữ ký được tính tại đây nên nơi gọi không
// cần (và không nên) tự điền trường Signature.
func (c *Client) CreateLink(ctx context.Context, req CreateRequest) (*Link, error) {
	if !c.Enabled() {
		return nil, ErrNotConfigured
	}

	// Chữ ký của API tạo link chỉ tính trên 5 trường, theo đúng thứ tự alphabet.
	// Không phải toàn bộ payload — thêm buyerName vào đây là mọi request đều hỏng.
	raw := "amount=" + strconv.FormatInt(req.Amount, 10) +
		"&cancelUrl=" + req.CancelURL +
		"&description=" + req.Description +
		"&orderCode=" + strconv.FormatInt(req.OrderCode, 10) +
		"&returnUrl=" + req.ReturnURL
	req.Signature = c.sign(raw)

	var link Link
	if err := c.call(ctx, http.MethodPost, "/v2/payment-requests", req, &link); err != nil {
		return nil, err
	}
	return &link, nil
}

// ---------- Tra cứu & huỷ ----------

// Transaction là một lần tiền thực sự vào tài khoản của link.
type Transaction struct {
	Reference           string `json:"reference"`
	Amount              int64  `json:"amount"`
	AccountNumber       string `json:"accountNumber"`
	Description         string `json:"description"`
	TransactionDateTime string `json:"transactionDateTime"`
	CounterAccountName  string `json:"counterAccountName"`
}

// Info là tình trạng hiện tại của một link thanh toán.
//
// Status: PENDING | PAID | PROCESSING | CANCELLED | EXPIRED.
type Info struct {
	ID              string        `json:"id"`
	OrderCode       int64         `json:"orderCode"`
	Amount          int64         `json:"amount"`
	AmountPaid      int64         `json:"amountPaid"`
	AmountRemaining int64         `json:"amountRemaining"`
	Status          string        `json:"status"`
	CreatedAt       string        `json:"createdAt"`
	Transactions    []Transaction `json:"transactions"`
	CanceledAt      string        `json:"canceledAt"`
	CancellationRsn string        `json:"cancellationReason"`
}

// Paid cho biết link đã thu đủ tiền hay chưa.
func (i *Info) Paid() bool { return strings.EqualFold(i.Status, "PAID") }

// Closed cho biết link đã khép lại (huỷ hoặc hết hạn) — không trả tiếp được nữa.
func (i *Info) Closed() bool {
	return strings.EqualFold(i.Status, "CANCELLED") || strings.EqualFold(i.Status, "EXPIRED")
}

// GetLink tra trạng thái hiện tại của link theo orderCode.
//
// Đây là đường xác nhận DỰ PHÒNG cho webhook: khi chạy ở máy local, PayOS không
// gọi vào được localhost, nên lúc khách quay về từ cổng ta hỏi thẳng PayOS xem
// tiền đã vào chưa.
func (c *Client) GetLink(ctx context.Context, orderCode int64) (*Info, error) {
	if !c.Enabled() {
		return nil, ErrNotConfigured
	}
	var info Info
	path := "/v2/payment-requests/" + strconv.FormatInt(orderCode, 10)
	if err := c.call(ctx, http.MethodGet, path, nil, &info); err != nil {
		return nil, err
	}
	return &info, nil
}

// CancelLink huỷ link thanh toán chưa trả tiền.
func (c *Client) CancelLink(ctx context.Context, orderCode int64, reason string) (*Info, error) {
	if !c.Enabled() {
		return nil, ErrNotConfigured
	}
	var info Info
	path := "/v2/payment-requests/" + strconv.FormatInt(orderCode, 10) + "/cancel"
	body := map[string]string{"cancellationReason": reason}
	if err := c.call(ctx, http.MethodPost, path, body, &info); err != nil {
		return nil, err
	}
	return &info, nil
}

// ConfirmWebhook đăng ký địa chỉ webhook với PayOS. PayOS gửi thử một gói dữ liệu
// tới địa chỉ đó và chỉ chấp nhận khi nhận được phản hồi 2xx.
func (c *Client) ConfirmWebhook(ctx context.Context, webhookURL string) error {
	if !c.Enabled() {
		return ErrNotConfigured
	}
	body := map[string]string{"webhookUrl": webhookURL}
	return c.call(ctx, http.MethodPost, "/confirm-webhook", body, nil)
}

// ---------- Webhook ----------

// WebhookData là phần `data` của webhook báo tiền đã vào.
type WebhookData struct {
	OrderCode              int64  `json:"orderCode"`
	Amount                 int64  `json:"amount"`
	Description            string `json:"description"`
	AccountNumber          string `json:"accountNumber"`
	Reference              string `json:"reference"`
	TransactionDateTime    string `json:"transactionDateTime"`
	Currency               string `json:"currency"`
	PaymentLinkID          string `json:"paymentLinkId"`
	Code                   string `json:"code"`
	Desc                   string `json:"desc"`
	CounterAccountBankID   string `json:"counterAccountBankId"`
	CounterAccountBankName string `json:"counterAccountBankName"`
	CounterAccountName     string `json:"counterAccountName"`
	CounterAccountNumber   string `json:"counterAccountNumber"`
	VirtualAccountName     string `json:"virtualAccountName"`
	VirtualAccountNumber   string `json:"virtualAccountNumber"`
}

// Succeeded cho biết webhook này báo một giao dịch THÀNH CÔNG.
func (w *WebhookData) Succeeded() bool { return w.Code == codeSuccess }

// ParseWebhook đọc thân request webhook và KIỂM CHỮ KÝ trước khi trả dữ liệu.
//
// Không có bước này thì bất kỳ ai biết địa chỉ webhook cũng gửi được một gói
// "đơn X đã thanh toán" và lấy hàng miễn phí — webhook là đường công khai, không
// có token nào bảo vệ.
func (c *Client) ParseWebhook(raw []byte) (*WebhookData, error) {
	if !c.Enabled() {
		return nil, ErrNotConfigured
	}

	var env struct {
		Code      string          `json:"code"`
		Desc      string          `json:"desc"`
		Success   bool            `json:"success"`
		Data      json.RawMessage `json:"data"`
		Signature string          `json:"signature"`
	}
	if err := json.Unmarshal(raw, &env); err != nil {
		return nil, fmt.Errorf("payos: webhook không phải JSON hợp lệ: %w", err)
	}
	if len(env.Data) == 0 || string(env.Data) == "null" {
		return nil, errors.New("payos: webhook thiếu phần data")
	}
	if err := c.verifyData(env.Data, env.Signature); err != nil {
		return nil, err
	}

	var data WebhookData
	if err := json.Unmarshal(env.Data, &data); err != nil {
		return nil, fmt.Errorf("payos: webhook data sai định dạng: %w", err)
	}
	// Code nằm cả ở vỏ ngoài lẫn trong data; gói thử của PayOS chỉ điền ở vỏ ngoài.
	if data.Code == "" {
		data.Code = env.Code
		data.Desc = env.Desc
	}
	return &data, nil
}

// ---------- Nội bộ ----------

// call gửi một request tới PayOS và giải mã phần `data` của phản hồi vào out.
// Chữ ký của phản hồi được kiểm luôn tại đây — trả lời từ cổng cũng phải chứng
// minh là của cổng.
func (c *Client) call(ctx context.Context, method, path string, body any, out any) error {
	var reader io.Reader
	if body != nil {
		buf, err := json.Marshal(body)
		if err != nil {
			return fmt.Errorf("payos: đóng gói request: %w", err)
		}
		reader = bytes.NewReader(buf)
	}

	req, err := http.NewRequestWithContext(ctx, method, c.cfg.BaseURL+path, reader)
	if err != nil {
		return fmt.Errorf("payos: dựng request: %w", err)
	}
	req.Header.Set("x-client-id", c.cfg.ClientID)
	req.Header.Set("x-api-key", c.cfg.APIKey)
	if body != nil {
		req.Header.Set("Content-Type", "application/json")
	}

	res, err := c.http.Do(req)
	if err != nil {
		return fmt.Errorf("payos: gọi %s: %w", path, err)
	}
	defer res.Body.Close()

	// Giới hạn dung lượng đọc: phản hồi của cổng luôn nhỏ, nhưng một máy chủ lạ
	// (DNS bị đổi, proxy chen vào) có thể trả về luồng dữ liệu vô tận.
	raw, err := io.ReadAll(io.LimitReader(res.Body, 1<<20))
	if err != nil {
		return fmt.Errorf("payos: đọc phản hồi: %w", err)
	}

	var env struct {
		Code      string          `json:"code"`
		Desc      string          `json:"desc"`
		Data      json.RawMessage `json:"data"`
		Signature string          `json:"signature"`
	}
	if err := json.Unmarshal(raw, &env); err != nil {
		return fmt.Errorf("payos: phản hồi %d không phải JSON: %s", res.StatusCode, truncate(string(raw), 200))
	}
	if env.Code != codeSuccess {
		return &APIError{Code: env.Code, Desc: env.Desc}
	}
	if out == nil {
		return nil
	}
	if len(env.Data) == 0 || string(env.Data) == "null" {
		return errors.New("payos: phản hồi thiếu phần data")
	}
	// Chữ ký rỗng chỉ chấp nhận ở những endpoint PayOS không ký (confirm-webhook);
	// những endpoint đó gọi với out = nil nên không rơi vào đây.
	if err := c.verifyData(env.Data, env.Signature); err != nil {
		return err
	}
	if err := json.Unmarshal(env.Data, out); err != nil {
		return fmt.Errorf("payos: data sai định dạng: %w", err)
	}
	return nil
}

// verifyData kiểm chữ ký của một khối `data` JSON.
func (c *Client) verifyData(data json.RawMessage, signature string) error {
	if signature == "" {
		return ErrSignature
	}
	canonical, err := canonicalize(data)
	if err != nil {
		return err
	}
	want := c.sign(canonical)
	// So sánh theo thời gian hằng định: so bằng == sẽ thoát sớm ở ký tự lệch đầu
	// tiên và về lý thuyết cho phép dò dần từng ký tự chữ ký.
	if subtle.ConstantTimeCompare([]byte(want), []byte(signature)) != 1 {
		return ErrSignature
	}
	return nil
}

// sign ký một chuỗi bằng HMAC-SHA256 với checksum key, trả về dạng hex thường.
func (c *Client) sign(raw string) string {
	mac := hmac.New(sha256.New, []byte(c.cfg.ChecksumKey))
	mac.Write([]byte(raw))
	return hex.EncodeToString(mac.Sum(nil))
}

// canonicalize dựng chuỗi "key1=value1&key2=value2..." từ một object JSON, khoá
// sắp theo alphabet — đúng quy ước ký của PayOS.
//
// Số được giữ NGUYÊN VĂN như trong JSON (json.Number) chứ không qua float64:
// 1750000 đi qua float64 rồi in ra sẽ thành "1.75e+06" và mọi chữ ký đều sai.
func canonicalize(data json.RawMessage) (string, error) {
	dec := json.NewDecoder(bytes.NewReader(data))
	dec.UseNumber()

	var obj map[string]any
	if err := dec.Decode(&obj); err != nil {
		return "", fmt.Errorf("payos: data không phải object JSON: %w", err)
	}

	keys := make([]string, 0, len(obj))
	for k := range obj {
		keys = append(keys, k)
	}
	sort.Strings(keys)

	var b strings.Builder
	for i, k := range keys {
		if i > 0 {
			b.WriteByte('&')
		}
		b.WriteString(k)
		b.WriteByte('=')
		b.WriteString(stringifyValue(obj[k]))
	}
	return b.String(), nil
}

// stringifyValue đổi một giá trị JSON thành chuỗi theo quy ước của PayOS.
//
// Giá trị trống được quy về chuỗi rỗng — kể cả chuỗi "null"/"undefined" mà một số
// SDK phía PayOS sinh ra khi trường bị bỏ trống, vì bên ký cũng coi chúng là rỗng.
func stringifyValue(v any) string {
	switch t := v.(type) {
	case nil:
		return ""
	case string:
		if t == "null" || t == "undefined" {
			return ""
		}
		return t
	case json.Number:
		return t.String()
	case bool:
		return strconv.FormatBool(t)
	default:
		// Mảng / object lồng nhau: JSON hoá. encoding/json xuất khoá của map theo
		// thứ tự alphabet nên kết quả khớp quy ước "sort object bên trong mảng".
		b, err := json.Marshal(t)
		if err != nil {
			return ""
		}
		return string(b)
	}
}

func truncate(s string, n int) string {
	if len(s) <= n {
		return s
	}
	return s[:n] + "..."
}
