// Vòng đời một tờ hoá đơn ở cổng M-Invoice: ký, tra lại, thay thế, điều chỉnh,
// lấy bản in và bản XML. Phần nối dây (đăng nhập, ký hiệu) nằm ở minvoice.go.
package minvoice

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
)

// Các giá trị của `trang_thai` — đường đi của một tờ hoá đơn ở cổng.
//
// Chỉ TrangThaiThanhCong mới là cơ quan thuế ĐÃ CẤP MÃ. "Đã gửi" mới là gửi
// đi, và một tờ đã gửi vẫn có thể quay ra lỗi.
const (
	TrangThaiChoDuyet  = 0
	TrangThaiChoKy     = 1
	TrangThaiDaKy      = 2
	TrangThaiDaGui     = 3
	TrangThaiThanhCong = 4
	TrangThaiCoLoi     = 5
	TrangThaiDangKy    = 6
)

// Ky ký một hoá đơn ĐÃ LƯU NHÁP rồi gửi cơ quan thuế.
//
// Chỉ chạy với chữ ký số MỀM (file p12, hoặc dịch vụ EASY/ICA/INTRUST). Khách
// ký bằng USB token phải ký ở trang của nhà cung cấp — cổng không ký hộ được.
func (c *Client) Ky(ctx context.Context, mst, token, maHoaDon string) error {
	if strings.TrimSpace(maHoaDon) == "" {
		return errors.New("minvoice: thiếu mã hoá đơn cần ký")
	}

	var than struct {
		Code    string `json:"code"`
		Message string `json:"message"`
	}
	err := c.goi(ctx, http.MethodPost, mst, "/api/InvoiceApi78/Sign", token,
		map[string]any{"hoadon68_id": maHoaDon}, &than)
	if err != nil {
		return err
	}
	if than.Code != maThanhCong {
		if than.Message != "" {
			return fmt.Errorf("minvoice: ký hoá đơn không thành công: %s", than.Message)
		}

		return errors.New("minvoice: ký hoá đơn không thành công")
	}

	return nil
}

// ThongTinHoaDon là những gì cần biết khi tra lại một tờ đã lập.
type ThongTinHoaDon struct {
	MaHoaDon string
	SoHoaDon string
	KyHieu   string
	// MaCQT (macqt) là mã cơ quan thuế cấp. Rỗng = CHƯA được cấp mã, dù cổng đã
	// nói "đã gửi".
	MaCQT string
	// MaTraCuu (sobaomat) in lên bill để khách tự tra trên trang của cổng.
	MaTraCuu string
	// TrangThai là đường gửi CQT, TrangThaiHD là loại tờ (gốc / thay thế / …).
	// Cả hai để -1 khi cổng không nói gì, để phân biệt với 0 (chờ duyệt / gốc).
	TrangThai   int
	TrangThaiHD int
	Tho         []byte
}

// TraHoaDon tra lại một hoá đơn theo id bên cổng.
//
// Đây là lượt PHẢI gọi sau khi ký: SaveSign trả về "đã gửi" chứ chưa phải "đã
// cấp mã", và mã cơ quan thuế chỉ xuất hiện ở lượt tra này.
func (c *Client) TraHoaDon(ctx context.Context, mst, token, maHoaDon string) (*ThongTinHoaDon, error) {
	if strings.TrimSpace(maHoaDon) == "" {
		return nil, errors.New("minvoice: thiếu mã hoá đơn cần tra")
	}

	var tho json.RawMessage
	duong := "/api/InvoiceApi78/GetInfoInvoice?id=" + url.QueryEscape(maHoaDon)
	if err := c.goi(ctx, http.MethodGet, mst, duong, token, nil, &tho); err != nil {
		return nil, err
	}

	var vo struct {
		Code    string          `json:"code"`
		Message string          `json:"message"`
		Data    json.RawMessage `json:"data"`
	}
	if err := json.Unmarshal(tho, &vo); err != nil {
		return nil, errors.New("minvoice: không đọc được thông tin hoá đơn")
	}
	if vo.Code != "" && vo.Code != maThanhCong {
		if vo.Message != "" {
			return nil, fmt.Errorf("minvoice: không tra được hoá đơn: %s", vo.Message)
		}

		return nil, errors.New("minvoice: không tra được hoá đơn")
	}

	// Có bản trả trong `data`, có bản trả thẳng ở gốc — thử `data` trước.
	du := bytes.TrimSpace(vo.Data)
	if len(du) == 0 {
		du = bytes.TrimSpace(tho)
	}
	if du[0] == '[' {
		var ds []json.RawMessage
		if err := json.Unmarshal(du, &ds); err != nil || len(ds) == 0 {
			return nil, errors.New("minvoice: cổng không trả về hoá đơn nào")
		}
		du = ds[0]
	}

	var than struct {
		MaHoaDon    soChuoi `json:"hoadon68_id"`
		MaHoaDon2   soChuoi `json:"inv_invoiceAuth_id"`
		MaHoaDon3   soChuoi `json:"id"`
		SoHoaDon    soChuoi `json:"shdon"`
		SoHoaDon2   soChuoi `json:"inv_invoiceNumber"`
		KyHieu      soChuoi `json:"khieu"`
		KyHieu2     soChuoi `json:"inv_invoiceSeries"`
		MaCQT       soChuoi `json:"macqt"`
		MaTraCuu    soChuoi `json:"sobaomat"`
		TrangThai   *int    `json:"trang_thai"`
		TrangThaiHD *int    `json:"trang_thai_hd"`
	}
	if err := json.Unmarshal(du, &than); err != nil {
		return nil, errors.New("minvoice: không đọc được thông tin hoá đơn")
	}

	tt := &ThongTinHoaDon{
		MaHoaDon:    dauTienKhacRong(string(than.MaHoaDon), string(than.MaHoaDon2), string(than.MaHoaDon3)),
		SoHoaDon:    dauTienKhacRong(string(than.SoHoaDon), string(than.SoHoaDon2)),
		KyHieu:      dauTienKhacRong(string(than.KyHieu), string(than.KyHieu2)),
		MaCQT:       strings.TrimSpace(string(than.MaCQT)),
		MaTraCuu:    strings.TrimSpace(string(than.MaTraCuu)),
		TrangThai:   -1,
		TrangThaiHD: -1,
		Tho:         tho,
	}
	if than.TrangThai != nil {
		tt.TrangThai = *than.TrangThai
	}
	if than.TrangThaiHD != nil {
		tt.TrangThaiHD = *than.TrangThaiHD
	}

	return tt, nil
}

// ThayThe phát hành một hoá đơn THAY CHO một tờ đã gửi cơ quan thuế.
//
// Tờ cũ phải đang ở trạng thái Gốc hoặc Thay thế; thay cho một tờ đã bị điều
// chỉnh là cổng từ chối. Payload bọc trong `data` NHƯNG không có `editmode` —
// khác Save, và đây là luật của họ chứ không phải nhầm lẫn.
func (c *Client) ThayThe(ctx context.Context, mst, token string, hoaDon map[string]any) (*KetQuaPhatHanh, error) {
	return c.goiPhatHanh(ctx, mst, token, "/api/InvoiceApi78/ThayThe",
		map[string]any{"data": []any{hoaDon}})
}

// DieuChinh phát hành một hoá đơn ĐIỀU CHỈNH cho một tờ đã gửi cơ quan thuế.
//
// than là payload phẳng `{inv_InvoiceAuth_id, inv_invoiceIssuedDate, data:[…]}`
// — điều chỉnh không bọc thêm lớp nào.
func (c *Client) DieuChinh(ctx context.Context, mst, token string, than map[string]any) (*KetQuaPhatHanh, error) {
	return c.goiPhatHanh(ctx, mst, token, "/api/InvoiceApi78/DieuChinh", than)
}

// goiPhatHanh là phần chung của mọi lượt sinh ra một tờ hoá đơn mới.
func (c *Client) goiPhatHanh(ctx context.Context, mst, token, duong string, than any) (*KetQuaPhatHanh, error) {
	var tho json.RawMessage
	if err := c.goi(ctx, http.MethodPost, mst, duong, token, than, &tho); err != nil {
		return nil, err
	}

	var vo struct {
		Code    string          `json:"code"`
		Message string          `json:"message"`
		Data    json.RawMessage `json:"data"`
	}
	if err := json.Unmarshal(tho, &vo); err != nil {
		return &KetQuaPhatHanh{Tho: tho}, nil
	}
	if vo.Code != "" && vo.Code != maThanhCong {
		if vo.Message != "" {
			return nil, fmt.Errorf("minvoice: cổng từ chối hoá đơn: %s", vo.Message)
		}

		return nil, errors.New("minvoice: cổng từ chối hoá đơn")
	}

	phan := docPhanData(vo.Data)

	return &KetQuaPhatHanh{
		SoHoaDon: dauTienKhacRong(string(phan.SoHoaDon), string(phan.SoHoaDon2), string(phan.SoHoaDon3)),
		MaHoaDon: dauTienKhacRong(string(phan.MaHoaDon), string(phan.MaHoaDon2), string(phan.MaHoaDon3)),
		Tho:      tho,
	}, nil
}

// BanIn tải bản PDF của một hoá đơn.
//
// chuyenDoi = true lấy bản HOÁ ĐƠN CHUYỂN ĐỔI (bản in ra giấy có chữ ký và dấu
// bên bán, dùng khi đi đường hoặc lưu kế toán) thay vì bản thường.
//
// Lượt này đòi mã đơn vị cơ sở dính vào chính token — `Bearer <token>;VP` — chứ
// không phải một header riêng. Đây là chỗ M-Invoice làm khác mọi hàm còn lại.
func (c *Client) BanIn(ctx context.Context, mst, token, maDVCS, maHoaDon string, chuyenDoi bool) ([]byte, error) {
	if strings.TrimSpace(maHoaDon) == "" {
		return nil, errors.New("minvoice: thiếu mã hoá đơn cần in")
	}
	if maDVCS == "" {
		maDVCS = "VP"
	}

	duong := "/api/InvoiceApi78/PrintInvoice?id=" + url.QueryEscape(maHoaDon)
	if chuyenDoi {
		duong += "&inchuyendoi=true"
	}

	du, err := c.goiTho(ctx, mst, duong, token+";"+maDVCS)
	if err != nil {
		return nil, err
	}

	// Hỏng thì cổng trả JSON chứ không trả PDF — nhận ra bằng chính dấu hiệu đó.
	if loi := loiTuJSON(du); loi != nil {
		return nil, fmt.Errorf("minvoice: không in được hoá đơn: %w", loi)
	}

	return du, nil
}

// BanXML tải bản XML gốc (đã ký) của một hoá đơn.
//
// Cổng trả base64 trong JSON; giải ra ngay ở đây để nơi gọi nhận đúng tệp XML.
func (c *Client) BanXML(ctx context.Context, mst, token, maHoaDon string) ([]byte, error) {
	if strings.TrimSpace(maHoaDon) == "" {
		return nil, errors.New("minvoice: thiếu mã hoá đơn cần lấy XML")
	}

	var than struct {
		Code    string `json:"code"`
		Message string `json:"message"`
		Data    string `json:"data"`
	}
	duong := "/api/InvoiceApi78/ExportXml?id=" + url.QueryEscape(maHoaDon)
	if err := c.goi(ctx, http.MethodGet, mst, duong, token, nil, &than); err != nil {
		return nil, err
	}
	if than.Code != maThanhCong || than.Data == "" {
		if than.Message != "" {
			return nil, fmt.Errorf("minvoice: không lấy được XML: %s", than.Message)
		}

		return nil, errors.New("minvoice: không lấy được XML — hoá đơn chưa ký thì chưa có bản XML")
	}

	xml, err := base64.StdEncoding.DecodeString(strings.TrimSpace(than.Data))
	if err != nil {
		return nil, fmt.Errorf("minvoice: bản XML cổng trả về không giải mã được: %w", err)
	}

	return xml, nil
}

// ThongTinMST là thứ tra được từ một mã số thuế.
type ThongTinMST struct {
	MaSoThue     string `json:"ma_so_thue"`
	TenCongTy    string `json:"ten_cty"`
	DiaChi       string `json:"dia_chi"`
	CoQuanThue   string `json:"cqthue_ql"`
	NguoiDaiDien string `json:"nguoi_dai_dien"`
}

// mayChuTraCuu là máy chủ tra cứu mã số thuế — DÙNG CHUNG cho mọi khách, không
// phải tên miền con của từng mã số thuế như các hàm còn lại.
const mayChuTraCuu = "https://mst.minvoice.com.vn"

// ErrChuaMoTraCuu — M-Invoice chỉ mở tra cứu cho các địa chỉ IP đã đăng ký
// trước, và giới hạn 20 lượt/10 giây. Máy chủ chưa đăng ký thì bị từ chối, và
// đó là việc phải đi đăng ký chứ không phải lỗi gõ sai mã số thuế.
var ErrChuaMoTraCuu = errors.New("minvoice: máy chủ chưa được M-Invoice mở quyền tra cứu mã số thuế")

// TraCuuMST tra tên và địa chỉ đăng ký của một mã số thuế.
func (c *Client) TraCuuMST(ctx context.Context, mst string) (*ThongTinMST, error) {
	mst = strings.TrimSpace(mst)
	if mst == "" {
		return nil, ErrThieuMST
	}

	goc := mayChuTraCuu
	if c.goc != "" {
		goc = c.goc
	}
	req, err := http.NewRequestWithContext(ctx, http.MethodGet,
		goc+"/api/System/SearchTaxCodeV2?tax="+url.QueryEscape(mst), nil)
	if err != nil {
		return nil, err
	}
	req.Header.Set("Accept", "application/json")

	res, err := c.http.Do(req)
	if err != nil {
		return nil, fmt.Errorf("minvoice: không gọi được máy chủ tra cứu: %w", err)
	}
	defer res.Body.Close()

	if res.StatusCode == http.StatusForbidden || res.StatusCode == http.StatusUnauthorized {
		return nil, ErrChuaMoTraCuu
	}

	du, err := io.ReadAll(io.LimitReader(res.Body, 1<<20))
	if err != nil {
		return nil, fmt.Errorf("minvoice: không đọc được trả lời: %w", err)
	}

	var tt ThongTinMST
	if err := json.Unmarshal(du, &tt); err != nil || strings.TrimSpace(tt.MaSoThue) == "" {
		return nil, fmt.Errorf("minvoice: không tìm thấy mã số thuế %s", mst)
	}

	return &tt, nil
}

// goiTho gửi một lượt GET rồi trả về NGUYÊN thân trả lời — dùng cho lượt tải
// PDF, thứ không phải JSON nên không đi đường `goi`.
func (c *Client) goiTho(ctx context.Context, mst, duong, auth string) ([]byte, error) {
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, c.diaChi(mst, duong), nil)
	if err != nil {
		return nil, err
	}
	req.Header.Set("Authorization", "Bearer "+auth)
	req.Header.Set("TaxCode", mst)

	res, err := c.http.Do(req)
	if err != nil {
		return nil, fmt.Errorf("minvoice: không gọi được cổng hoá đơn: %w", err)
	}
	defer res.Body.Close()

	// 20MB: một bản PDF hoá đơn nặng vài trăm KB, để rộng cho tờ nhiều dòng
	// nhưng vẫn chặn được một trang lỗi khổng lồ.
	du, err := io.ReadAll(io.LimitReader(res.Body, 20<<20))
	if err != nil {
		return nil, fmt.Errorf("minvoice: không đọc được trả lời: %w", err)
	}

	return du, nil
}

// loiTuJSON đọc câu lỗi từ một thân trả lời ĐÁNG LẼ là tệp nhị phân. Không phải
// JSON, hoặc JSON code "00", thì trả nil — nghĩa là lượt gọi trót lọt.
func loiTuJSON(du []byte) error {
	du = bytes.TrimSpace(du)
	if len(du) == 0 || du[0] != '{' {
		return nil
	}

	var than struct {
		Code    string `json:"code"`
		Message string `json:"message"`
	}
	if err := json.Unmarshal(du, &than); err != nil {
		return nil
	}
	if than.Code == "" || than.Code == maThanhCong {
		return nil
	}
	if than.Message != "" {
		return errors.New(than.Message)
	}

	return errors.New("mã lỗi " + than.Code)
}
