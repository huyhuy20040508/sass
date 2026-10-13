// Package sepayinvoice là client cổng HOÁ ĐƠN ĐIỆN TỬ của SePay
// (developer.sepay.vn/vi/einvoice-api) — khác hẳn gói pkg/sepay, vốn chỉ đọc
// biến động số dư để đối soát chuyển khoản.
//
// BA ĐIỀU KHÁC M-INVOICE mà nơi gọi phải biết:
//
//   - Đăng nhập bằng cặp client_id / client_secret (Basic auth), không có mã số
//     thuế hay mã đơn vị. Token sống 24 giờ; hết hạn thì cổng trả HTTP 401.
//   - Một tài khoản SePay có thể nối nhiều "tài khoản nhà cung cấp" (Matbao…);
//     hoá đơn phải nói rõ phát hành trên tài khoản nào (`provider_account_id`).
//   - Tạo và phát hành đều BẤT ĐỒNG BỘ: cổng nhận yêu cầu rồi trả `tracking_code`,
//     kết quả phải hỏi lại ở đường /check. Lỗi đồng bộ nằm ở HTTP status kèm
//     `error.code`; lỗi bất đồng bộ nằm ở `status: Failed` kèm `message`.
package sepayinvoice

import (
	"bytes"
	"context"
	"encoding/base64"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"strings"
	"time"
)

// Hai máy chủ của SePay. Token cấp ở máy nào chỉ dùng được ở máy đó.
const (
	GocThat = "https://einvoice-api.sepay.vn"
	GocThu  = "https://einvoice-api-sandbox.sepay.vn"
)

// Trạng thái xử lý của một yêu cầu tạo / phát hành.
const (
	DangXuLy  = "Pending"
	ThanhCong = "Success"
	ThatBai   = "Failed"
)

// maDaCo — cổng đã ghi nhận hoá đơn cho reference_code này.
const maDaCo = "EINVOICE_DOCUMENT_EXISTED"

// ErrDangNhap — sai tên đăng nhập / mật khẩu (client_id / client_secret).
var ErrDangNhap = errors.New("sepay: tên đăng nhập hoặc mật khẩu không đúng")

// Loi là một lượt gọi bị cổng từ chối ngay (lỗi đồng bộ).
type Loi struct {
	HTTP      int
	Ma        string
	ThongDiep string
}

func (e *Loi) Error() string {
	if e.ThongDiep != "" {
		return "sepay: " + e.ThongDiep
	}
	if e.Ma != "" {
		return fmt.Sprintf("sepay: cổng từ chối (%s)", e.Ma)
	}

	return fmt.Sprintf("sepay: cổng từ chối (HTTP %d)", e.HTTP)
}

// LaHetToken: token sai hoặc quá 24 giờ — đăng nhập lại là chữa được.
func LaHetToken(err error) bool { return coMaHTTP(err, http.StatusUnauthorized) }

// LaKhongThay: cổng không có đối tượng được hỏi (hoá đơn, tracking…).
func LaKhongThay(err error) bool { return coMaHTTP(err, http.StatusNotFound) }

// LaDaCo: reference_code đã có hoá đơn bên cổng — gửi lại là bị chặn trùng.
func LaDaCo(err error) bool {
	var l *Loi

	return errors.As(err, &l) && (l.Ma == maDaCo || l.HTTP == http.StatusConflict)
}

func coMaHTTP(err error, ma int) bool {
	var l *Loi

	return errors.As(err, &l) && l.HTTP == ma
}

// tienToThu — SePay cấp tên đăng nhập sandbox dạng "EINV-TEST-…".
const tienToThu = "EINV-TEST-"

// Client gọi SePay eInvoice. Dùng lại một instance cho cả vòng đời ứng dụng.
type Client struct {
	http *http.Client
	goc  string
	// coDinh = máy chủ đã khai cứng (env, bộ test): Cho() không đổi máy nữa.
	coDinh bool
}

// New trỏ tới máy chủ `goc`. Rỗng = tự chọn theo tài khoản, xem Cho().
func New(goc string) *Client {
	goc = strings.TrimRight(strings.TrimSpace(goc), "/")
	c := &Client{
		// 20 giây như client M-Invoice: lượt tạo hoá đơn chỉ là nhận yêu cầu nên
		// thường về trong một hai giây, treo lâu hơn thế là cổng đang có chuyện.
		http:   &http.Client{Timeout: 20 * time.Second},
		goc:    goc,
		coDinh: goc != "",
	}
	if c.goc == "" {
		c.goc = GocThat
	}

	return c
}

// Cho trả client trỏ đúng máy cho tài khoản `tenDangNhap`: tài khoản sandbox
// (EINV-TEST-…) đi máy thử, còn lại máy thật. Khách chỉ việc dán tên đăng nhập
// và mật khẩu, không phải chọn môi trường — token máy nào chỉ dùng được ở máy đó.
func (c *Client) Cho(tenDangNhap string) *Client {
	if c.coDinh || !strings.HasPrefix(strings.ToUpper(strings.TrimSpace(tenDangNhap)), tienToThu) {
		return c
	}
	ban := *c
	ban.goc = GocThu

	return &ban
}

// LayToken đổi cặp client_id / client_secret lấy access token.
func (c *Client) LayToken(ctx context.Context, clientID, secret string) (string, error) {
	basic := base64.StdEncoding.EncodeToString([]byte(clientID + ":" + secret))

	var ra struct {
		AccessToken string `json:"access_token"`
	}
	if err := c.goi(ctx, http.MethodPost, "/v1/token", "Basic "+basic, nil, &ra); err != nil {
		if LaHetToken(err) {
			return "", ErrDangNhap
		}

		return "", err
	}
	if ra.AccessToken == "" {
		return "", ErrDangNhap
	}

	return ra.AccessToken, nil
}

// TaiKhoan là một tài khoản nhà cung cấp HĐĐT gắn với tài khoản SePay.
type TaiKhoan struct {
	ID       string `json:"id"`
	Provider string `json:"provider"`
	Active   bool   `json:"active"`
	// NgayDuyet — ngày cơ quan thuế duyệt tờ khai; null ở sandbox.
	NgayDuyet string `json:"tax_authority_approved_date"`
	Mau       []Mau  `json:"templates"`
}

// Mau là một cặp mẫu số + ký hiệu được cấp. Cùng một ký hiệu có thể đi với hai
// mẫu số (GTGT và bán hàng), nên phải giữ cả cặp.
type Mau struct {
	MaMau  string `json:"template_code"`
	KyHieu string `json:"invoice_series"`
	Nhan   string `json:"invoice_label"`
}

// DanhSachTaiKhoan trả mọi tài khoản nhà cung cấp, kể cả cái đã tắt.
func (c *Client) DanhSachTaiKhoan(ctx context.Context, token string) ([]TaiKhoan, error) {
	var ra struct {
		Items []TaiKhoan `json:"items"`
	}
	if err := c.goi(ctx, http.MethodGet, "/v1/provider-accounts?page=1&per_page=100", "Bearer "+token, nil, &ra); err != nil {
		return nil, err
	}

	return ra.Items, nil
}

// ChiTietTaiKhoan trả một tài khoản kèm danh sách mẫu / ký hiệu của nó.
func (c *Client) ChiTietTaiKhoan(ctx context.Context, token, id string) (*TaiKhoan, error) {
	var ra TaiKhoan
	if err := c.goi(ctx, http.MethodGet, "/v1/provider-accounts/"+url.PathEscape(id), "Bearer "+token, nil, &ra); err != nil {
		return nil, err
	}

	return &ra, nil
}

// YeuCau là thứ cổng trả về khi NHẬN một yêu cầu tạo / phát hành — chưa phải
// kết quả.
type YeuCau struct {
	MaTheoDoi string `json:"tracking_code"`
	ThongDiep string `json:"message"`
}

// TaoHoaDon gửi một hoá đơn (nháp hoặc phát hành luôn, tuỳ `is_draft`).
func (c *Client) TaoHoaDon(ctx context.Context, token string, hoaDon any) (*YeuCau, error) {
	var ra YeuCau
	if err := c.goi(ctx, http.MethodPost, "/v1/invoices/create", "Bearer "+token, hoaDon, &ra); err != nil {
		return nil, err
	}

	return &ra, nil
}

// PhatHanhNhap phát hành một hoá đơn đã tạo nháp.
func (c *Client) PhatHanhNhap(ctx context.Context, token, maThamChieu string) (*YeuCau, error) {
	var ra YeuCau
	err := c.goi(ctx, http.MethodPost, "/v1/invoices/issue", "Bearer "+token,
		map[string]string{"reference_code": maThamChieu}, &ra)
	if err != nil {
		return nil, err
	}

	return &ra, nil
}

// TrangThaiXuLy là kết quả của một yêu cầu tạo / phát hành.
type TrangThaiXuLy struct {
	MaThamChieu string  `json:"reference_code"`
	TrangThai   string  `json:"status"`
	ThongDiep   string  `json:"message"`
	HoaDon      *HoaDon `json:"invoice"`
	// Tho là nguyên văn `data` cổng trả — cất vào sổ để còn đối chiếu.
	Tho json.RawMessage `json:"-"`
}

// KiemTraTao hỏi kết quả của một yêu cầu tạo hoá đơn.
func (c *Client) KiemTraTao(ctx context.Context, token, maTheoDoi string) (*TrangThaiXuLy, error) {
	return c.kiemTra(ctx, token, "/v1/invoices/create/check/", maTheoDoi)
}

// KiemTraPhatHanh hỏi kết quả của một yêu cầu phát hành từ nháp.
func (c *Client) KiemTraPhatHanh(ctx context.Context, token, maTheoDoi string) (*TrangThaiXuLy, error) {
	return c.kiemTra(ctx, token, "/v1/invoices/issue/check/", maTheoDoi)
}

func (c *Client) kiemTra(ctx context.Context, token, duong, maTheoDoi string) (*TrangThaiXuLy, error) {
	var tho json.RawMessage
	if err := c.goi(ctx, http.MethodGet, duong+url.PathEscape(maTheoDoi), "Bearer "+token, nil, &tho); err != nil {
		return nil, err
	}

	var ra TrangThaiXuLy
	if err := json.Unmarshal(tho, &ra); err != nil {
		return nil, fmt.Errorf("sepay: trả lời trạng thái không đúng dạng: %w", err)
	}
	ra.Tho = tho

	return &ra, nil
}

// HoaDon là một tờ hoá đơn bên cổng.
type HoaDon struct {
	MaThamChieu string  `json:"reference_code"`
	SoHoaDon    soChuoi `json:"invoice_number"`
	KyHieu      string  `json:"invoice_series"`
	// TrangThai: draft | issued | cancelled — trạng thái của SePay.
	TrangThai string `json:"status"`
	// MaCQT chỉ có khi cơ quan thuế đã cấp mã.
	MaCQT string `json:"tax_authority_code"`
	// TrangThaiNCC là trạng thái của nhà cung cấp phía sau SePay (Matbao…):
	// "4" đã cấp mã, "2" lỗi ký, "6" không đủ điều kiện cấp mã… Lượt /check
	// không trả trường này, chỉ lượt xem chi tiết mới có.
	TrangThaiNCC soChuoi `json:"provider_status"`
	PdfURL       string  `json:"pdf_url"`
}

// ChiTiet trả một tờ hoá đơn theo reference_code. LaKhongThay(err) = cổng chưa
// ghi nhận tờ nào cho mã này.
func (c *Client) ChiTiet(ctx context.Context, token, maThamChieu string) (*HoaDon, error) {
	var ra HoaDon
	if err := c.goi(ctx, http.MethodGet, "/v1/invoices/"+url.PathEscape(maThamChieu), "Bearer "+token, nil, &ra); err != nil {
		return nil, err
	}

	return &ra, nil
}

// TaiTep tải bản PDF hoặc XML (`loai` = "pdf" | "xml") của một tờ hoá đơn.
func (c *Client) TaiTep(ctx context.Context, token, ma, loai string) ([]byte, error) {
	var ra struct {
		NoiDung string `json:"content"`
	}
	duong := "/v1/invoices/" + url.PathEscape(ma) + "/download?type=" + url.QueryEscape(loai)
	if err := c.goi(ctx, http.MethodGet, duong, "Bearer "+token, nil, &ra); err != nil {
		return nil, err
	}

	du, err := base64.StdEncoding.DecodeString(ra.NoiDung)
	if err != nil || len(du) == 0 {
		return nil, errors.New("sepay: tệp hoá đơn trả về rỗng hoặc hỏng")
	}

	return du, nil
}

// goi gửi một lượt gọi rồi đọc phần `data` của thân trả lời vào `ra`.
//
// `than` nil = không có thân (GET, lượt lấy token).
func (c *Client) goi(ctx context.Context, method, duong, auth string, than any, ra any) error {
	var body io.Reader
	if than != nil {
		du, err := json.Marshal(than)
		if err != nil {
			return err
		}
		body = bytes.NewReader(du)
	}

	req, err := http.NewRequestWithContext(ctx, method, c.goc+duong, body)
	if err != nil {
		return err
	}
	req.Header.Set("Accept", "application/json")
	req.Header.Set("Authorization", auth)
	if than != nil {
		req.Header.Set("Content-Type", "application/json")
	}

	res, err := c.http.Do(req)
	if err != nil {
		return fmt.Errorf("sepay: không gọi được cổng hoá đơn: %w", err)
	}
	defer res.Body.Close()

	// Đọc có giới hạn: bản PDF base64 vài trăm KB, còn một trang HTML lỗi thì
	// không đáng nuốt hết vào bộ nhớ.
	du, err := io.ReadAll(io.LimitReader(res.Body, 16<<20))
	if err != nil {
		return fmt.Errorf("sepay: không đọc được trả lời: %w", err)
	}

	var vo struct {
		// Con trỏ: lượt liệt kê tài khoản trả về không có `success`, nên vắng
		// mặt không có nghĩa là hỏng.
		Success *bool           `json:"success"`
		Data    json.RawMessage `json:"data"`
		Error   *struct {
			Code    string `json:"code"`
			Message string `json:"message"`
		} `json:"error"`
		Message string `json:"message"`
	}
	if err := json.Unmarshal(du, &vo); err != nil {
		return &Loi{HTTP: res.StatusCode, ThongDiep: fmt.Sprintf("cổng trả lời không đúng dạng (HTTP %d)", res.StatusCode)}
	}

	if res.StatusCode >= 400 || (vo.Success != nil && !*vo.Success) {
		l := &Loi{HTTP: res.StatusCode, ThongDiep: vo.Message}
		if vo.Error != nil {
			l.Ma = vo.Error.Code
			if vo.Error.Message != "" {
				l.ThongDiep = vo.Error.Message
			}
		}

		return l
	}

	if len(vo.Data) == 0 || string(vo.Data) == "null" {
		return &Loi{HTTP: res.StatusCode, ThongDiep: "cổng trả lời thiếu phần data"}
	}

	if tho, ok := ra.(*json.RawMessage); ok {
		*tho = vo.Data

		return nil
	}

	return json.Unmarshal(vo.Data, ra)
}

// soChuoi đọc một trường lúc là số, lúc là chuỗi (`invoice_number`,
// `provider_status`).
type soChuoi string

func (s *soChuoi) UnmarshalJSON(du []byte) error {
	du = bytes.TrimSpace(du)
	if len(du) == 0 || string(du) == "null" {
		return nil
	}
	if du[0] == '"' {
		var v string
		if err := json.Unmarshal(du, &v); err != nil {
			return err
		}
		*s = soChuoi(strings.TrimSpace(v))

		return nil
	}
	*s = soChuoi(du)

	return nil
}

// Chuoi trả giá trị dạng chuỗi.
func (s soChuoi) Chuoi() string { return string(s) }
