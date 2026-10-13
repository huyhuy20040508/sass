// Package nextpay gọi cổng NextPay mPOS (pushpayment.nextpay.vn) — QR mPOS và
// đẩy số tiền xuống máy quẹt thẻ — theo đúng giao thức ordertable v2 đang chạy
// (OrderMposController::sendToMpos): gói JSON mã hoá AES-128-ECB bằng Secret Key
// của merchant, gửi dạng {"merchantId": …, "reqData": base64}.
package nextpay

import (
	"bytes"
	"context"
	"crypto/aes"
	"encoding/base64"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"os"
	"strconv"
	"strings"
	"time"
)

// URLMacDinh — máy chủ thật của NextPay (v2 gọi thẳng, không có sandbox).
// NEXTPAY_BASE_URL đè lên được, để test chạy với máy giả lập.
const URLMacDinh = "https://pushpayment.nextpay.vn"

var (
	ErrNotConfigured = errors.New("nextpay: chưa khai Merchant ID / Secret Key")
	// ErrGiaiMa — gói báo tiền không giải được bằng khoá của cổng: sai khoá, hoặc
	// gói giả. Không ghi nhận tiền.
	ErrGiaiMa = errors.New("nextpay: không giải mã được gói dữ liệu")
)

// APIError — NextPay trả resCode khác 200.
type APIError struct {
	Code    int
	Message string
}

func (e *APIError) Error() string { return fmt.Sprintf("nextpay: %d %s", e.Code, e.Message) }

type Config struct {
	BaseURL    string
	MerchantID string
	SecretKey  string
}

type Client struct {
	cfg  Config
	http *http.Client
}

func New(cfg Config) *Client {
	if cfg.BaseURL == "" {
		cfg.BaseURL = strings.TrimRight(os.Getenv("NEXTPAY_BASE_URL"), "/")
	}
	if cfg.BaseURL == "" {
		cfg.BaseURL = URLMacDinh
	}

	return &Client{cfg: cfg, http: &http.Client{Timeout: 20 * time.Second}}
}

func (c *Client) Enabled() bool { return c.cfg.MerchantID != "" && c.cfg.SecretKey != "" }

// khoa16 dựng khoá AES-128 y như openssl_encrypt của PHP: khoá ngắn hơn 16 byte
// được đệm \0, dài hơn thì cắt — khác cách này là gói của mình NextPay không đọc được.
func (c *Client) khoa16() []byte {
	k := make([]byte, 16)
	copy(k, c.cfg.SecretKey)

	return k
}

// Ma mã hoá AES-128-ECB, đệm PKCS#7 (mặc định của openssl), ra base64.
func (c *Client) Ma(thuong []byte) (string, error) {
	khoi, err := aes.NewCipher(c.khoa16())
	if err != nil {
		return "", err
	}
	dem := aes.BlockSize - len(thuong)%aes.BlockSize
	buf := append(append([]byte{}, thuong...), bytes.Repeat([]byte{byte(dem)}, dem)...)
	ra := make([]byte, len(buf))
	for i := 0; i < len(buf); i += aes.BlockSize {
		khoi.Encrypt(ra[i:i+aes.BlockSize], buf[i:i+aes.BlockSize])
	}

	return base64.StdEncoding.EncodeToString(ra), nil
}

// Giai là chiều ngược của Ma. Khoá sai thường lộ ra ở bước gỡ đệm.
func (c *Client) Giai(b64 string) ([]byte, error) {
	kin, err := base64.StdEncoding.DecodeString(strings.TrimSpace(b64))
	if err != nil || len(kin) == 0 || len(kin)%aes.BlockSize != 0 {
		return nil, ErrGiaiMa
	}
	khoi, err := aes.NewCipher(c.khoa16())
	if err != nil {
		return nil, err
	}
	ra := make([]byte, len(kin))
	for i := 0; i < len(kin); i += aes.BlockSize {
		khoi.Decrypt(ra[i:i+aes.BlockSize], kin[i:i+aes.BlockSize])
	}
	dem := int(ra[len(ra)-1])
	if dem < 1 || dem > aes.BlockSize || dem > len(ra) || !bytes.Equal(ra[len(ra)-dem:], bytes.Repeat([]byte{byte(dem)}, dem)) {
		return nil, ErrGiaiMa
	}

	return ra[:len(ra)-dem], nil
}

func (c *Client) goi(ctx context.Context, duong string, goi map[string]any) (map[string]any, error) {
	if !c.Enabled() {
		return nil, ErrNotConfigured
	}
	raw, _ := json.Marshal(goi)
	req, err := c.Ma(raw)
	if err != nil {
		return nil, err
	}
	than, _ := json.Marshal(map[string]string{"merchantId": c.cfg.MerchantID, "reqData": req})
	r, err := http.NewRequestWithContext(ctx, http.MethodPost, c.cfg.BaseURL+duong, bytes.NewReader(than))
	if err != nil {
		return nil, err
	}
	r.Header.Set("Content-Type", "application/json")
	res, err := c.http.Do(r)
	if err != nil {
		return nil, err
	}
	defer res.Body.Close()
	body, _ := io.ReadAll(io.LimitReader(res.Body, 1<<20))

	var tl struct {
		ResCode json.Number `json:"resCode"`
		Message string      `json:"message"`
		ResDesc string      `json:"resDesc"`
		ResData string      `json:"resData"`
	}
	if err := json.Unmarshal(body, &tl); err != nil {
		return nil, fmt.Errorf("nextpay: phản hồi không phải JSON (HTTP %d)", res.StatusCode)
	}
	ma, _ := strconv.Atoi(tl.ResCode.String())
	if ma != 200 {
		cau := tl.Message
		if cau == "" {
			cau = tl.ResDesc
		}
		return nil, &APIError{Code: ma, Message: cau}
	}
	out := map[string]any{}
	if tl.ResData != "" {
		thuong, err := c.Giai(tl.ResData)
		if err != nil {
			return nil, err
		}
		_ = json.Unmarshal(thuong, &out)
	}

	return out, nil
}

// TaoQR — CREATE_QR. Trả CHUỖI QR (VietQR) để tự vẽ ảnh, như v2 (createQR).
func (c *Client) TaoQR(ctx context.Context, maDon, muid string, soTien int64, kieuQR, moTa string) (string, error) {
	if kieuQR == "" {
		kieuQR = "VAQR"
	}
	out, err := c.goi(ctx, "/orderQR", map[string]any{
		"serviceName": "CREATE_QR", "orderId": maDon, "muid": muid,
		"amount": soTien, "qrType": kieuQR, "description": moTa,
	})
	if err != nil {
		return "", err
	}
	qr, _ := out["qrCode"].(string)
	if qr == "" {
		return "", &APIError{Code: 0, Message: "NextPay không trả mã QR"}
	}

	return qr, nil
}

// HuyQR — REMOVE_QR: mã cũ không quét được nữa.
func (c *Client) HuyQR(ctx context.Context, maDon, muid string, soTien int64) error {
	_, err := c.goi(ctx, "/orderQR", map[string]any{
		"serviceName": "REMOVE_QR", "orderId": maDon, "muid": muid, "amount": soTien,
	})

	return err
}

// DayXuongMay — ADD_ORDER_INFOR: số tiền hiện sẵn trên máy mPOS, khách chỉ việc quẹt.
func (c *Client) DayXuongMay(ctx context.Context, maDon, posID string, soTien int64, moTa string) error {
	_, err := c.goi(ctx, "/order", map[string]any{
		"serviceName": "ADD_ORDER_INFOR", "orderId": maDon, "posId": posID,
		"amount": soTien, "description": moTa,
	})

	return err
}

// HuyLenhMay — REMOVE_ORDER_INFOR.
func (c *Client) HuyLenhMay(ctx context.Context, maDon, posID string, soTien int64) error {
	_, err := c.goi(ctx, "/order", map[string]any{
		"serviceName": "REMOVE_ORDER_INFOR", "orderId": maDon, "posId": posID, "amount": soTien,
	})

	return err
}

// BaoTien — gói NextPay báo về webhook (đã giải mã).
type BaoTien struct {
	// TransStatus: 100 = quẹt thẻ thành công, 104 = QR thành công (v2).
	TransStatus int
	MaDon       string
	SoTien      float64
	MaGiaoDich  string
	// IssuerCode — loại thẻ / ví (VISA, DOMESTIC, MOMO…), quyết định mức phí.
	IssuerCode string
}

func (b *BaoTien) QuetTheXong() bool { return b.TransStatus == 100 }
func (b *BaoTien) QRXong() bool      { return b.TransStatus == 104 }

// DocWebhook giải gói {"reqData": …} NextPay gửi tới. Khoá sai → ErrGiaiMa.
func (c *Client) DocWebhook(raw []byte) (*BaoTien, error) {
	var vo struct {
		ReqData string `json:"reqData"`
	}
	if err := json.Unmarshal(raw, &vo); err != nil || vo.ReqData == "" {
		return nil, ErrGiaiMa
	}
	thuong, err := c.Giai(vo.ReqData)
	if err != nil {
		return nil, err
	}
	var d map[string]any
	if err := json.Unmarshal(thuong, &d); err != nil {
		// Khoá sai vẫn có thể gỡ đệm "trót lọt" ra rác — v2 cũng chỉ tin khi parse được JSON.
		return nil, ErrGiaiMa
	}
	chuoi := func(ks ...string) string {
		for _, k := range ks {
			switch v := d[k].(type) {
			case string:
				if v != "" {
					return v
				}
			case float64:
				return strconv.FormatFloat(v, 'f', -1, 64)
			}
		}
		return ""
	}
	so := func(k string) float64 {
		f, _ := strconv.ParseFloat(chuoi(k), 64)
		return f
	}

	return &BaoTien{
		TransStatus: int(so("transStatus")),
		MaDon:       chuoi("orderID", "orderId"),
		SoTien:      so("amount"),
		MaGiaoDich:  chuoi("transCode"),
		IssuerCode:  strings.ToUpper(chuoi("issuerCode")),
	}, nil
}

// PhiTheoThe — ô phí (Cài đặt → Quẹt thẻ) ứng với loại thẻ NextPay báo về. Gom
// theo đúng nhãn trên màn hình; bảng của v2 (issuer_to_fee_map) để ngược nhãn
// ATM/NAPAS với Visa/Master trong nước.
func PhiTheoThe(issuer string) string {
	switch strings.ToUpper(issuer) {
	case "DOMESTIC", "DOMESTIC_ON", "NAPASCREDIT", "NAPASCREDIT_ON":
		return "card_fee_local"
	case "VISA_LOCAL", "MASTER_LOCAL", "JCB_LOCAL", "CUP_LOCAL":
		return "card_fee_domestic"
	case "VISA", "MASTER", "JCB", "CUP", "AMEX", "DCI":
		return "card_fee_foreign"
	case "VISA_LOCAL_ON", "MASTER_LOCAL_ON", "CUP_LOCAL_ON", "JCB_LOCAL_ON", "DCI_LOCAL", "DCI_LOCAL_ON", "HOMECREDIT", "MUADAE":
		return "card_fee_remote"
	case "MVISA", "MASTERCARDPASS", "JCBQR", "UPIQR":
		return "card_fee_mvisa"
	case "MSBQR", "ZALOPAY", "VIETTELQRPAY", "SMARTPAY", "SHOPEE_PAY", "BANKQR", "VAQR":
		return "card_fee_qrpay"
	case "DXNEXTPAY", "DXNEXTPAY_ON":
		return "card_fee_vimo"
	case "MOMO":
		return "card_fee_momo"
	}

	return ""
}
