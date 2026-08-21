package minvoice

import (
	"context"
	"encoding/json"
	"errors"
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

// Bài kiểm của client M-Invoice.
//
// Điều đáng kiểm nhất KHÔNG phải "gọi được": nó là mã trả về nằm ở TRƯỜNG `code`
// trong thân JSON chứ không phải ở HTTP status. Một cổng trả 200 kèm code "99"
// là một lượt HỎNG, và code chỉ nhìn status sẽ coi đó là thành công rồi lưu một
// tài khoản không đăng nhập được.

func TestDangNhapLayDuocToken(t *testing.T) {
	var than map[string]string
	sv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/api/Account/Login" {
			t.Errorf("gọi nhầm đường: %s", r.URL.Path)
		}
		du, _ := io.ReadAll(r.Body)
		_ = json.Unmarshal(du, &than)
		_, _ = w.Write([]byte(`{"code":"00","token":"abc123"}`))
	}))
	defer sv.Close()

	token, err := NewVoiGoc(sv.URL).DangNhap(context.Background(), "0106026495-998", "user", "pass", "")
	if err != nil {
		t.Fatalf("đăng nhập: %v", err)
	}
	if token != "abc123" {
		t.Fatalf("token phải là abc123, nhận %q", token)
	}
	// ma_dvcs bỏ trống phải thành "VP" — tài khoản một chi nhánh nào cũng dùng nó.
	if than["ma_dvcs"] != "VP" {
		t.Fatalf("ma_dvcs bỏ trống phải mặc định VP, gửi lên %q", than["ma_dvcs"])
	}
}

// HTTP 200 mà code khác "00" là HỎNG, và câu của nhà cung cấp phải đi tới người
// dùng: "sai mật khẩu" và "tài khoản hết hạn" là hai việc phải làm khác nhau.
func TestDangNhapSaiThiBaoLoiKemCauCuaCong(t *testing.T) {
	sv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		_, _ = w.Write([]byte(`{"code":"01","message":"Sai tên đăng nhập hoặc mật khẩu"}`))
	}))
	defer sv.Close()

	_, err := NewVoiGoc(sv.URL).DangNhap(context.Background(), "0106026495-998", "user", "sai", "VP")
	if !errors.Is(err, ErrDangNhap) {
		t.Fatalf("phải là ErrDangNhap, nhận %v", err)
	}
	if !strings.Contains(err.Error(), "Sai tên đăng nhập") {
		t.Fatalf("phải giữ nguyên câu của cổng, nhận %q", err.Error())
	}
}

func TestMauHoaDonDocDuocDanhSach(t *testing.T) {
	var token string
	sv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		token = r.Header.Get("Authorization")
		_, _ = w.Write([]byte(`{"code":"00","data":[
			{"khhdon":"C25TAA","invoiceForm":"1","invoiceTypeName":"Hóa đơn GTGT"},
			{"khhdon":"","invoiceForm":"1","invoiceTypeName":"Dòng rác"}
		]}`))
	}))
	defer sv.Close()

	ds, err := NewVoiGoc(sv.URL).MauHoaDonDaDangKy(context.Background(), "0106026495-998", "abc123")
	if err != nil {
		t.Fatalf("kéo mẫu: %v", err)
	}
	// Dòng thiếu ký hiệu bị bỏ: nó không phát hành được hoá đơn nào, mà bày ra ô
	// chọn thì người dùng chọn phải một dòng trống.
	if len(ds) != 1 || ds[0].KyHieu != "C25TAA" {
		t.Fatalf("phải còn đúng một mẫu C25TAA, nhận %+v", ds)
	}
	if token != "Bearer abc123" {
		t.Fatalf("phải gắn Bearer token, nhận %q", token)
	}
}

// Gõ nhầm mã số thuế thì tên miền con không tồn tại và cổng trả về HTML. Câu lỗi
// phải chỉ thẳng vào mã số thuế, không phải một lỗi phân tích JSON.
func TestTraLoiKhongPhaiJsonThiBaoKiemMaSoThue(t *testing.T) {
	sv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.WriteHeader(http.StatusNotFound)
		_, _ = w.Write([]byte("<html>404</html>"))
	}))
	defer sv.Close()

	_, err := NewVoiGoc(sv.URL).DangNhap(context.Background(), "0101010101", "user", "pass", "VP")
	if err == nil || !strings.Contains(err.Error(), "mã số thuế") {
		t.Fatalf("phải nhắc kiểm mã số thuế, nhận %v", err)
	}
}

func TestThieuMaSoThueThiKhongGoiDi(t *testing.T) {
	_, err := New().DangNhap(context.Background(), "  ", "user", "pass", "VP")
	if !errors.Is(err, ErrThieuMST) {
		t.Fatalf("phải là ErrThieuMST, nhận %v", err)
	}
}

// Địa chỉ máy chủ dựng từ chính mã số thuế; hai tài khoản dùng thử nằm ở
// .minvoice.site. Sai luật này là gửi mật khẩu của khách tới một tên miền lạ.
func TestDiaChiTheoMaSoThue(t *testing.T) {
	c := New()
	if got := c.diaChi("0312345678", "/api/Account/Login"); got != "https://0312345678.minvoice.app/api/Account/Login" {
		t.Fatalf("mã số thuế thường phải đi .minvoice.app, nhận %q", got)
	}
	if got := c.diaChi("0106026495-999", "/x"); got != "https://0106026495-999.minvoice.site/x" {
		t.Fatalf("tài khoản dùng thử phải đi .minvoice.site, nhận %q", got)
	}
}

// Cổng nhận payload BỌC HAI LỚP `{editmode, data:[…]}`, và trả số hoá đơn ở
// TRONG `data` — `shdon` lại là số chứ không phải chuỗi. Gửi object phẳng hoặc
// đọc số ở gốc đều hỏng lặng: cổng trả 200 mà mình không thấy hoá đơn nào.
func TestPhatHanhBocPayloadVaDocSoTrongData(t *testing.T) {
	var nhan map[string]any
	var taxCode string
	sv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/api/InvoiceApi78/SaveSign" {
			t.Errorf("ký thì phải gọi SaveSign, nhận %s", r.URL.Path)
		}
		taxCode = r.Header.Get("TaxCode")
		du, _ := io.ReadAll(r.Body)
		_ = json.Unmarshal(du, &nhan)
		_, _ = w.Write([]byte(`{"code":"00","data":{"shdon":8072,"inv_invoiceAuth_id":"3a1a564b-60ae"}}`))
	}))
	defer sv.Close()

	kq, err := NewVoiGoc(sv.URL).PhatHanh(context.Background(), "0106026495-999", "abc123",
		map[string]any{"inv_invoiceSeries": "1C26TYY"}, true)
	if err != nil {
		t.Fatalf("phát hành: %v", err)
	}

	if nhan["editmode"] != float64(1) {
		t.Fatalf("phải gửi editmode=1, nhận %v", nhan["editmode"])
	}
	ds, ok := nhan["data"].([]any)
	if !ok || len(ds) != 1 {
		t.Fatalf("hoá đơn phải nằm trong mảng data, nhận %v", nhan["data"])
	}
	if ds[0].(map[string]any)["inv_invoiceSeries"] != "1C26TYY" {
		t.Fatalf("ký hiệu phải đi nguyên vào data[0], nhận %v", ds[0])
	}
	if taxCode != "0106026495-999" {
		t.Fatalf("SaveSign đòi header TaxCode, nhận %q", taxCode)
	}
	if kq.SoHoaDon != "8072" {
		t.Fatalf("số hoá đơn phải đọc được từ data, nhận %q", kq.SoHoaDon)
	}
	if kq.MaHoaDon != "3a1a564b-60ae" {
		t.Fatalf("mã hoá đơn phải đọc được từ data, nhận %q", kq.MaHoaDon)
	}
}

// Lưu nháp đi đường Save, và có cấu hình cổng trả `data` là MẢNG.
func TestPhatHanhNhapDocDuocDataDangMang(t *testing.T) {
	sv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/api/InvoiceApi78/Save" {
			t.Errorf("lưu nháp phải gọi Save, nhận %s", r.URL.Path)
		}
		_, _ = w.Write([]byte(`{"code":"00","data":[{"inv_invoiceNumber":"12","hoadon68_id":"xyz"}]}`))
	}))
	defer sv.Close()

	kq, err := NewVoiGoc(sv.URL).PhatHanh(context.Background(), "0106026495-999", "abc123", map[string]any{}, false)
	if err != nil {
		t.Fatalf("lưu nháp: %v", err)
	}
	if kq.SoHoaDon != "12" || kq.MaHoaDon != "xyz" {
		t.Fatalf("phải đọc được phần tử đầu của mảng data, nhận %+v", kq)
	}
}

// Cổng từ chối thì code khác "00" — và câu của họ phải đi tới người bấm nút.
func TestPhatHanhBiTuChoiThiBaoCauCuaCong(t *testing.T) {
	sv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		_, _ = w.Write([]byte(`{"code":"88","message":"Trùng key tích hợp"}`))
	}))
	defer sv.Close()

	_, err := NewVoiGoc(sv.URL).PhatHanh(context.Background(), "0106026495-999", "abc123", map[string]any{}, true)
	if err == nil || !strings.Contains(err.Error(), "Trùng key tích hợp") {
		t.Fatalf("phải giữ nguyên câu của cổng, nhận %v", err)
	}
}
