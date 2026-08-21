// Package minvoice là client cổng hoá đơn điện tử M-Invoice (minvoice.com.vn).
//
// Hai việc gói này làm, và chỉ hai: ĐĂNG NHẬP lấy token, và KÉO VỀ danh sách ký
// hiệu hoá đơn đã đăng ký với cơ quan thuế. Phần phát hành hoá đơn từ đơn hàng
// chưa làm — nó cần bản đồ trường của cả đơn hàng, thuế suất và người mua, tức
// là một lượt riêng.
//
// ĐỊA CHỈ MÁY CHỦ THEO MÃ SỐ THUẾ: mỗi khách của M-Invoice có một tên miền con
// dựng từ chính mã số thuế — `https://<mst>.minvoice.app`. Hai mã số thuế
// 0106026495-998 và -999 là tài khoản DÙNG THỬ và nằm ở `.minvoice.site`; đây
// là luật của họ, không phải quy ước của mình.
//
// Mã trả về nằm ở trường `code` trong thân JSON chứ không phải ở HTTP status:
// "00" là thành công, còn lại là hỏng kèm `message`. Nên đừng chỉ nhìn 200.
package minvoice

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"strings"
	"time"
)

var (
	// ErrDangNhap — sai mã số thuế, tên đăng nhập hoặc mật khẩu.
	ErrDangNhap = errors.New("minvoice: đăng nhập không thành công")
	// ErrThieuMST — gọi mà không có mã số thuế thì không dựng nổi địa chỉ.
	ErrThieuMST = errors.New("minvoice: thiếu mã số thuế")
)

// maThanhCong là giá trị `code` của một lượt gọi trót lọt.
const maThanhCong = "00"

// mstDungThu là hai tài khoản dùng thử nằm ở tên miền .minvoice.site.
var mstDungThu = map[string]bool{
	"0106026495-998": true,
	"0106026495-999": true,
}

// Client gọi M-Invoice. Dùng lại một instance cho cả vòng đời ứng dụng.
type Client struct {
	http *http.Client
	// goc khác rỗng thì mọi lượt gọi đi tới đó thay vì tên miền dựng từ mã số
	// thuế — xem NewVoiGoc.
	goc string
}

func New() *Client {
	// 20 giây: cổng HĐĐT chậm hơn hẳn API thường, nhưng để treo lâu hơn thế thì
	// người bấm "Kết nối" ngồi nhìn màn hình đứng im mà không biết vì sao.
	return &Client{http: &http.Client{Timeout: 20 * time.Second}}
}

// NewVoiGoc trỏ mọi lượt gọi tới một địa chỉ cố định.
//
// Dành cho BỘ TEST (httptest) và cho ngày M-Invoice đổi cách đặt tên miền. Đừng
// dùng nó để nối một cửa hàng thật vào máy chủ khác: địa chỉ là của cả tiến
// trình, đặt sai là MỌI cửa hàng gửi mật khẩu tới đó.
func NewVoiGoc(goc string) *Client {
	c := New()
	c.goc = strings.TrimRight(goc, "/")

	return c
}

// MauHoaDon là một ký hiệu hoá đơn đã đăng ký của tài khoản.
type MauHoaDon struct {
	// KyHieu (khhdon) là thứ đi lên hoá đơn phát hành, vd "C25TAA".
	KyHieu string
	// MauSo (invoiceForm) — mẫu số hoá đơn, vd "1".
	MauSo string
	// TenLoai (invoiceTypeName) — "Hóa đơn giá trị gia tăng"…
	TenLoai string
}

// DangNhap lấy token của tài khoản.
//
// maDVCS là mã đơn vị cơ sở bên M-Invoice; tài khoản một chi nhánh dùng "VP".
func (c *Client) DangNhap(ctx context.Context, mst, tenDangNhap, matKhau, maDVCS string) (string, error) {
	if strings.TrimSpace(mst) == "" {
		return "", ErrThieuMST
	}
	if maDVCS == "" {
		maDVCS = "VP"
	}

	var than struct {
		Code    string `json:"code"`
		Token   string `json:"token"`
		Message string `json:"message"`
	}
	err := c.goi(ctx, http.MethodPost, mst, "/api/Account/Login", "", map[string]string{
		"username": tenDangNhap,
		"password": matKhau,
		"ma_dvcs":  maDVCS,
	}, &than)
	if err != nil {
		return "", err
	}

	if than.Code != maThanhCong || than.Token == "" {
		// In nguyên câu của họ: "sai mật khẩu" và "tài khoản hết hạn" là hai việc
		// phải làm khác nhau, gộp lại thành "đăng nhập hỏng" là lấy đi phần có ích.
		if than.Message != "" {
			return "", fmt.Errorf("%w: %s", ErrDangNhap, than.Message)
		}

		return "", ErrDangNhap
	}

	return than.Token, nil
}

// MauHoaDonDaDangKy kéo về danh sách ký hiệu hoá đơn của tài khoản.
//
// Token hết hạn thì M-Invoice trả code khác "00"; nơi gọi bắt lỗi rồi đăng nhập
// lại — xem EtaxService.
func (c *Client) MauHoaDonDaDangKy(ctx context.Context, mst, token string) ([]MauHoaDon, error) {
	var than struct {
		Code string `json:"code"`
		Data []struct {
			KyHieu  string `json:"khhdon"`
			MauSo   string `json:"invoiceForm"`
			TenLoai string `json:"invoiceTypeName"`
		} `json:"data"`
		Message string `json:"message"`
	}
	err := c.goi(ctx, http.MethodGet, mst, "/api/Invoice68/GetTypeInvoiceSeries", token, nil, &than)
	if err != nil {
		return nil, err
	}
	if than.Code != maThanhCong {
		if than.Message != "" {
			return nil, fmt.Errorf("minvoice: không lấy được mẫu hoá đơn: %s", than.Message)
		}

		return nil, errors.New("minvoice: không lấy được mẫu hoá đơn")
	}

	ds := make([]MauHoaDon, 0, len(than.Data))
	for _, m := range than.Data {
		if strings.TrimSpace(m.KyHieu) == "" {
			continue
		}
		ds = append(ds, MauHoaDon{KyHieu: m.KyHieu, MauSo: m.MauSo, TenLoai: m.TenLoai})
	}

	return ds, nil
}

// diaChi dựng địa chỉ máy chủ của một mã số thuế — xem chú thích đầu gói.
func (c *Client) diaChi(mst, duong string) string {
	if c.goc != "" {
		return c.goc + duong
	}

	mien := "minvoice.app"
	if mstDungThu[mst] {
		mien = "minvoice.site"
	}

	return "https://" + mst + "." + mien + duong
}

// goi gửi một lượt gọi rồi đọc JSON vào `ra`.
//
// token rỗng = không gắn Authorization (lượt đăng nhập). `than` nil = không có
// thân request (lượt GET).
func (c *Client) goi(ctx context.Context, method, mst, duong, token string, than any, ra any) error {
	var body io.Reader
	if than != nil {
		du, err := json.Marshal(than)
		if err != nil {
			return err
		}
		body = bytes.NewReader(du)
	}

	req, err := http.NewRequestWithContext(ctx, method, c.diaChi(mst, duong), body)
	if err != nil {
		return err
	}
	req.Header.Set("Accept", "application/json")
	if than != nil {
		req.Header.Set("Content-Type", "application/json")
	}
	if token != "" {
		req.Header.Set("Authorization", "Bearer "+token)
	}

	res, err := c.http.Do(req)
	if err != nil {
		return fmt.Errorf("minvoice: không gọi được cổng hoá đơn: %w", err)
	}
	defer res.Body.Close()

	// Đọc có giới hạn: cổng hỏng có thể trả về cả một trang HTML lỗi, và nuốt
	// nguyên nó vào bộ nhớ là hỏng theo.
	du, err := io.ReadAll(io.LimitReader(res.Body, 4<<20))
	if err != nil {
		return fmt.Errorf("minvoice: không đọc được trả lời: %w", err)
	}

	if err := json.Unmarshal(du, ra); err != nil {
		// Trả lời không phải JSON gần như luôn là sai địa chỉ (mã số thuế gõ nhầm
		// nên tên miền con không tồn tại). Nói ra mã HTTP để còn đoán được.
		return fmt.Errorf("minvoice: cổng trả lời không đúng dạng (HTTP %d) — kiểm tra lại mã số thuế", res.StatusCode)
	}

	return nil
}

// KetQuaPhatHanh là thứ cổng trả về sau lượt lưu / ký hoá đơn.
type KetQuaPhatHanh struct {
	// SoHoaDon là số hoá đơn thật, chỉ có sau khi ĐÃ KÝ. Lưu nháp thì rỗng.
	SoHoaDon string
	// MaHoaDon là định danh bản ghi bên cổng, dùng để tra lại về sau.
	MaHoaDon string
	// Tho là nguyên văn JSON cổng trả về — cất vào sổ để còn đối chiếu khi
	// khách thắc mắc. Hoá đơn là chứng từ pháp lý, không dựng lại được.
	Tho []byte
}

// PhatHanh gửi một hoá đơn lên cổng.
//
// ky = true thì gọi SaveSign (lưu VÀ ký, ra số hoá đơn ngay); false thì Save
// (chỉ lưu nháp, người dùng vào trang nhà cung cấp ký tay). Đây là đúng hai
// đường của M-Invoice, không phải hai cách gọi cùng một thứ.
func (c *Client) PhatHanh(ctx context.Context, mst, token string, hoaDon map[string]any, ky bool) (*KetQuaPhatHanh, error) {
	duong := "/api/InvoiceApi78/Save"
	if ky {
		duong = "/api/InvoiceApi78/SaveSign"
	}

	// Đọc vào json.RawMessage TRƯỚC: hình dạng trả lời của lượt phát hành thay
	// đổi theo cấu hình từng khách (có nơi `data` là object, có nơi là mảng), mà
	// thứ phải giữ lại nguyên vẹn là toàn bộ câu trả lời chứ không phải vài
	// trường mình đoán trước.
	var tho json.RawMessage
	if err := c.goi(ctx, http.MethodPost, mst, duong, token, hoaDon, &tho); err != nil {
		return nil, err
	}

	var than struct {
		Code    string `json:"code"`
		Message string `json:"message"`
		// Ba tên trường cho cùng một thứ: M-Invoice trả `shdon`/`so_hoa_don` tuỳ
		// bản, và `inv_invoiceNumber` ở nhánh 78.
		SoHoaDon  string `json:"shdon"`
		SoHoaDon2 string `json:"so_hoa_don"`
		SoHoaDon3 string `json:"inv_invoiceNumber"`
		MaHoaDon  string `json:"inv_invoiceAuth_id"`
		MaHoaDon2 string `json:"id"`
	}
	if err := json.Unmarshal(tho, &than); err != nil {
		// Trả lời không đọc được thành object (vd một mảng) vẫn là một lượt gọi
		// tới nơi: giữ nguyên văn rồi để nơi gọi tự xử.
		return &KetQuaPhatHanh{Tho: tho}, nil
	}

	if than.Code != "" && than.Code != maThanhCong {
		if than.Message != "" {
			return nil, fmt.Errorf("minvoice: cổng từ chối hoá đơn: %s", than.Message)
		}

		return nil, errors.New("minvoice: cổng từ chối hoá đơn")
	}

	return &KetQuaPhatHanh{
		SoHoaDon: dauTienKhacRong(than.SoHoaDon, than.SoHoaDon2, than.SoHoaDon3),
		MaHoaDon: dauTienKhacRong(than.MaHoaDon, than.MaHoaDon2),
		Tho:      tho,
	}, nil
}

func dauTienKhacRong(ds ...string) string {
	for _, v := range ds {
		if strings.TrimSpace(v) != "" {
			return strings.TrimSpace(v)
		}
	}

	return ""
}
