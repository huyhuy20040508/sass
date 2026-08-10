// Package sepay là client cổng đối soát chuyển khoản SePay (sepay.vn) — kiểm
// webhook báo có, dựng ảnh QR chuyển tiền và tra cứu giao dịch của tài khoản.
//
// SePay làm việc khác hẳn một cổng thanh toán thông thường: nó KHÔNG giữ tiền và
// không cấp link thanh toán. Nó đọc biến động số dư của chính tài khoản ngân hàng
// cửa hàng rồi báo về. Nghĩa là:
//
//   - Không có bước "tạo giao dịch" — mã QR chỉ là một lệnh chuyển tiền dựng sẵn.
//   - Việc gắn tiền vào đơn hoàn toàn dựa vào NỘI DUNG chuyển khoản.
//   - Thứ chứng minh webhook đến từ SePay là khoá API trong header, không phải
//     chữ ký trên dữ liệu.
package sepay

import (
	"context"
	"crypto/subtle"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"strconv"
	"strings"
	"time"

	"sass-api/config"
)

var (
	// ErrUnauthorized — webhook không mang đúng khoá API. Coi như dữ liệu giả.
	ErrUnauthorized = errors.New("sepay: khoá webhook không hợp lệ")
	// ErrNotConfigured — chưa khai đủ tài khoản nhận tiền / khoá webhook.
	ErrNotConfigured = errors.New("sepay: chưa cấu hình tài khoản nhận tiền hoặc khoá webhook")
	// ErrNoAPIToken — chưa khai token nên không tra cứu chủ động được.
	ErrNoAPIToken = errors.New("sepay: chưa khai SEPAY_API_TOKEN nên không tra cứu được giao dịch")
)

// Client gọi SePay. Dùng lại một instance cho cả vòng đời ứng dụng.
type Client struct {
	cfg  config.SePayConfig
	http *http.Client
}

func New(cfg config.SePayConfig) *Client {
	return &Client{
		cfg:  cfg,
		http: &http.Client{Timeout: 15 * time.Second},
	}
}

// Enabled cho biết đã đủ cấu hình để nhận tiền qua SePay chưa.
func (c *Client) Enabled() bool { return c.cfg.Enabled() }

// WebhookEnabled cho biết có nhận webhook không (đã khai khoá hay chưa).
func (c *Client) WebhookEnabled() bool { return c.cfg.WebhookEnabled() }

// CanQuery cho biết có tra cứu chủ động sang SePay được không.
func (c *Client) CanQuery() bool { return c.cfg.CanQuery() }

// Account trả về tài khoản nhận tiền để hiển thị cho khách chuyển tay.
func (c *Client) Account() (number, bank, name string) {
	return c.cfg.AccountNumber, c.cfg.Bank, c.cfg.AccountName
}

// ---------- Mã QR ----------

// QRImageURL dựng địa chỉ ảnh QR chuyển tiền cho một đơn.
//
// Ảnh do SePay vẽ (qr.sepay.vn) chứ không vẽ tại chỗ như bên PayOS: chuỗi VietQR
// phải mã hoá đúng mã ngân hàng (BIN) của từng nhà băng, tự dựng lấy nghĩa là ôm
// thêm một bảng tra BIN phải bảo trì mãi, chỉ để ra đúng cái ảnh SePay đã dựng sẵn.
//
// content chính là nội dung chuyển khoản — cũng là thứ duy nhất để đối soát, nên
// nó phải là mã đơn.
func (c *Client) QRImageURL(amount int64, content string) string {
	if !c.Enabled() {
		return ""
	}
	q := url.Values{}
	q.Set("acc", c.cfg.AccountNumber)
	q.Set("bank", c.cfg.Bank)
	if amount > 0 {
		q.Set("amount", strconv.FormatInt(amount, 10))
	}
	q.Set("des", content)
	// template=compact: chỉ mã QR + logo, không kèm khung quảng cáo — hợp với việc
	// nhúng vào modal của mình.
	q.Set("template", "compact")
	return c.cfg.QRBaseURL + "/img?" + q.Encode()
}

// ---------- Webhook ----------

// Webhook là gói dữ liệu SePay gửi khi tài khoản có biến động số dư.
type Webhook struct {
	ID              int64  `json:"id"`
	Gateway         string `json:"gateway"`
	TransactionDate string `json:"transactionDate"`
	AccountNumber   string `json:"accountNumber"`
	SubAccount      string `json:"subAccount"`
	// Code là mã SePay tự tách ra từ nội dung (chỉ có khi cấu hình mẫu mã ở SePay).
	Code string `json:"code"`
	// Content là nội dung chuyển khoản thô — nguồn đối soát chính của mình.
	Content string `json:"content"`
	// TransferType: "in" = tiền vào, "out" = tiền ra.
	TransferType   string  `json:"transferType"`
	Description    string  `json:"description"`
	TransferAmount float64 `json:"transferAmount"`
	Accumulated    float64 `json:"accumulated"`
	ReferenceCode  string  `json:"referenceCode"`
}

// IsIncoming cho biết đây có phải giao dịch TIỀN VÀO không. Tiền ra cũng được bắn
// về cùng một địa chỉ webhook, và ghi nhận nhầm nó là "khách đã trả tiền" thì mỗi
// lần cửa hàng chi tiền lại có một đơn được đánh dấu đã thanh toán.
func (w *Webhook) IsIncoming() bool { return strings.EqualFold(w.TransferType, "in") }

// ParseWebhook kiểm khoá API rồi đọc thân request.
//
// authHeader là giá trị nguyên văn của header Authorization, SePay gửi dạng
// "Apikey <khoá>".
func (c *Client) ParseWebhook(authHeader string, raw []byte) (*Webhook, error) {
	if !c.Enabled() {
		return nil, ErrNotConfigured
	}
	if err := c.checkAuth(authHeader); err != nil {
		return nil, err
	}

	var w Webhook
	if err := json.Unmarshal(raw, &w); err != nil {
		return nil, fmt.Errorf("sepay: webhook không phải JSON hợp lệ: %w", err)
	}
	return &w, nil
}

// checkAuth so khoá trong header với khoá đã khai.
func (c *Client) checkAuth(authHeader string) error {
	// Chưa khai khoá = không nhận webhook. Bỏ qua bước này khi khoá rỗng thì mọi
	// request không mang header cũng "khớp" và ai cũng tự báo đã thanh toán được.
	if !c.cfg.WebhookEnabled() {
		return ErrUnauthorized
	}

	// SePay cho chọn tiền tố khi cấu hình; chấp nhận cả "Apikey" lẫn "Bearer" để
	// đổi kiểu xác thực bên trang quản lý không làm sập webhook đang chạy.
	got := strings.TrimSpace(authHeader)
	for _, prefix := range []string{"Apikey ", "ApiKey ", "APIKey ", "Bearer "} {
		if len(got) >= len(prefix) && strings.EqualFold(got[:len(prefix)], prefix) {
			got = strings.TrimSpace(got[len(prefix):])
			break
		}
	}
	// So sánh theo thời gian hằng định — so bằng == thoát sớm ở ký tự lệch đầu tiên
	// và về lý thuyết cho phép dò dần từng ký tự của khoá.
	if subtle.ConstantTimeCompare([]byte(got), []byte(c.cfg.WebhookAPIKey)) != 1 {
		return ErrUnauthorized
	}
	return nil
}

// ---------- Tra cứu giao dịch ----------

// Transaction là một dòng sao kê SePay trả về.
type Transaction struct {
	ID              string `json:"id"`
	Gateway         string `json:"bank_brand_name"`
	AccountNumber   string `json:"account_number"`
	TransactionDate string `json:"transaction_date"`
	AmountIn        string `json:"amount_in"`
	AmountOut       string `json:"amount_out"`
	Content         string `json:"transaction_content"`
	ReferenceNumber string `json:"reference_number"`
	Code            string `json:"code"`
}

// AmountInVND đọc số tiền VÀO. SePay trả số dưới dạng chuỗi ("50000.00") nên phải
// tự đổi; đọc không ra thì trả 0 và nơi gọi sẽ coi là không khớp số tiền.
func (t *Transaction) AmountInVND() float64 {
	v, err := strconv.ParseFloat(strings.TrimSpace(t.AmountIn), 64)
	if err != nil {
		return 0
	}
	return v
}

// FindIncoming tìm giao dịch TIỀN VÀO gần đây có nội dung chứa `content`.
//
// Đây là đường xác nhận dự phòng cho webhook: chạy ở máy local thì SePay không gọi
// vào localhost được, nên lúc khách bấm kiểm tra ta hỏi thẳng sao kê.
//
// since giới hạn khoảng thời gian phải quét — luôn truyền mốc tạo đơn, đừng quét
// cả sao kê.
func (c *Client) FindIncoming(ctx context.Context, content string, since time.Time) (*Transaction, error) {
	if !c.CanQuery() {
		return nil, ErrNoAPIToken
	}

	q := url.Values{}
	q.Set("account_number", c.cfg.AccountNumber)
	// Lùi lại một chút cho lệch giờ giữa máy chủ mình và ngân hàng.
	q.Set("transaction_date_min", since.Add(-2*time.Hour).Format("2006-01-02 15:04:05"))
	q.Set("limit", "100")

	endpoint := c.cfg.APIBaseURL + "/userapi/transactions/list?" + q.Encode()
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, endpoint, nil)
	if err != nil {
		return nil, fmt.Errorf("sepay: dựng request: %w", err)
	}
	req.Header.Set("Authorization", "Bearer "+c.cfg.APIToken)
	req.Header.Set("Content-Type", "application/json")

	res, err := c.http.Do(req)
	if err != nil {
		return nil, fmt.Errorf("sepay: tra cứu giao dịch: %w", err)
	}
	defer res.Body.Close()

	raw, err := io.ReadAll(io.LimitReader(res.Body, 4<<20))
	if err != nil {
		return nil, fmt.Errorf("sepay: đọc phản hồi: %w", err)
	}
	if res.StatusCode != http.StatusOK {
		return nil, fmt.Errorf("sepay: tra cứu trả mã %d: %s", res.StatusCode, truncate(string(raw), 200))
	}

	var body struct {
		Status       int           `json:"status"`
		Error        any           `json:"error"`
		Transactions []Transaction `json:"transactions"`
	}
	if err := json.Unmarshal(raw, &body); err != nil {
		return nil, fmt.Errorf("sepay: phản hồi không phải JSON: %s", truncate(string(raw), 200))
	}

	want := NormalizeContent(content)
	if want == "" {
		return nil, nil
	}
	for i := range body.Transactions {
		t := &body.Transactions[i]
		if t.AmountInVND() <= 0 {
			continue // dòng tiền ra
		}
		if strings.Contains(NormalizeContent(t.Content), want) {
			return t, nil
		}
	}
	return nil, nil
}

// ---------- Tiện ích ----------

// NormalizeContent đưa nội dung chuyển khoản về dạng so khớp được: bỏ mọi ký tự
// không phải chữ/số và viết hoa.
//
// Cần bước này vì mỗi ngân hàng nhào nặn nội dung một kiểu — chèn thêm khoảng
// trắng, đổi hoa thường, ghép thêm tiền tố của chính họ. Mã đơn của mình chỉ gồm
// chữ và số nên sau khi chuẩn hoá, nó vẫn nằm nguyên vẹn trong chuỗi kết quả.
func NormalizeContent(s string) string {
	var b strings.Builder
	b.Grow(len(s))
	for _, r := range s {
		switch {
		case r >= '0' && r <= '9':
			b.WriteRune(r)
		case r >= 'A' && r <= 'Z':
			b.WriteRune(r)
		case r >= 'a' && r <= 'z':
			b.WriteRune(r - 32)
		}
	}
	return b.String()
}

func truncate(s string, n int) string {
	if len(s) <= n {
		return s
	}
	return s[:n] + "..."
}
