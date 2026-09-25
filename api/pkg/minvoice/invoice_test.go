package minvoice

import (
	"context"
	"encoding/base64"
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

// Bài kiểm của phần đời sau một tờ hoá đơn.
//
// Thứ đáng kiểm nhất ở đây là HÌNH DẠNG PAYLOAD: mỗi hàm của M-Invoice bọc dữ
// liệu một kiểu — Save có `editmode` + `data`, ThayThe chỉ có `data`, DieuChinh
// thì phẳng. Gửi sai lớp bọc thì cổng trả 200 kèm code lỗi, và code chỉ nhìn
// HTTP status sẽ tưởng là xong.

func TestTraHoaDonDocMaCoQuanThue(t *testing.T) {
	var duong string
	sv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		duong = r.URL.Path + "?" + r.URL.RawQuery
		_, _ = w.Write([]byte(`{"code":"00","data":{
			"shdon":47,"macqt":"M1-26-MBUT7-00000000153","sobaomat":"56CC49A",
			"trang_thai":4,"trang_thai_hd":0,"khieu":"1C26MYY","hoadon68_id":"abc-123"}}`))
	}))
	defer sv.Close()

	tt, err := NewVoiGoc(sv.URL).TraHoaDon(context.Background(), "0106026495-999", "tok", "abc-123")
	if err != nil {
		t.Fatalf("tra hoá đơn: %v", err)
	}

	if !strings.Contains(duong, "/api/InvoiceApi78/GetInfoInvoice?id=abc-123") {
		t.Fatalf("gọi nhầm đường: %s", duong)
	}
	// shdon là SỐ trong JSON nhưng phải đọc ra được thành chuỗi.
	if tt.SoHoaDon != "47" {
		t.Fatalf("số hoá đơn phải là 47, nhận %q", tt.SoHoaDon)
	}
	if tt.MaCQT != "M1-26-MBUT7-00000000153" {
		t.Fatalf("mã cơ quan thuế đọc sai: %q", tt.MaCQT)
	}
	if tt.MaTraCuu != "56CC49A" {
		t.Fatalf("mã tra cứu đọc sai: %q", tt.MaTraCuu)
	}
	if tt.TrangThai != TrangThaiThanhCong || tt.TrangThaiHD != 0 {
		t.Fatalf("trạng thái đọc sai: %d / %d", tt.TrangThai, tt.TrangThaiHD)
	}
}

// Cổng không nói gì về trạng thái thì phải để -1, KHÔNG phải 0: 0 là "chờ
// duyệt", một trạng thái có thật, và nhầm hai thứ đó là ghi bừa vào sổ.
func TestTraHoaDonThieuTrangThaiThiDeAm(t *testing.T) {
	sv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		_, _ = w.Write([]byte(`{"code":"00","data":{"shdon":"12"}}`))
	}))
	defer sv.Close()

	tt, err := NewVoiGoc(sv.URL).TraHoaDon(context.Background(), "0106026495-999", "tok", "id")
	if err != nil {
		t.Fatalf("tra hoá đơn: %v", err)
	}
	if tt.TrangThai != -1 || tt.TrangThaiHD != -1 {
		t.Fatalf("thiếu trạng thái phải để -1, nhận %d / %d", tt.TrangThai, tt.TrangThaiHD)
	}
}

func TestKyGuiDungThanVaBatLoiCong(t *testing.T) {
	var than map[string]string
	var duong string
	sv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		duong = r.URL.Path
		du, _ := io.ReadAll(r.Body)
		_ = json.Unmarshal(du, &than)
		_, _ = w.Write([]byte(`{"code":"00","message":"Thành công"}`))
	}))
	defer sv.Close()

	if err := NewVoiGoc(sv.URL).Ky(context.Background(), "0106026495-999", "tok", "hd-1"); err != nil {
		t.Fatalf("ký: %v", err)
	}
	if duong != "/api/InvoiceApi78/Sign" {
		t.Fatalf("gọi nhầm đường: %s", duong)
	}
	if than["hoadon68_id"] != "hd-1" {
		t.Fatalf("thân phải là {hoadon68_id}, nhận %v", than)
	}
}

func TestKyBiTuChoiThiGiuCauCuaCong(t *testing.T) {
	sv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		_, _ = w.Write([]byte(`{"code":"29404","message":"Không tìm thấy hóa đơn cần xem"}`))
	}))
	defer sv.Close()

	err := NewVoiGoc(sv.URL).Ky(context.Background(), "0106026495-999", "tok", "hd-1")
	if err == nil || !strings.Contains(err.Error(), "Không tìm thấy hóa đơn") {
		t.Fatalf("phải giữ nguyên câu của cổng, nhận %v", err)
	}
}

// Thay thế bọc trong `data` NHƯNG không có `editmode` — khác hẳn Save. Gửi kèm
// editmode là cổng hiểu nhầm thành một lượt thêm mới.
func TestThayTheBocDataKhongCoEditmode(t *testing.T) {
	var nhan map[string]any
	var duong string
	sv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		duong = r.URL.Path
		du, _ := io.ReadAll(r.Body)
		_ = json.Unmarshal(du, &nhan)
		_, _ = w.Write([]byte(`{"code":"00","data":{"shdon":9,"hoadon68_id":"moi-1"}}`))
	}))
	defer sv.Close()

	kq, err := NewVoiGoc(sv.URL).ThayThe(context.Background(), "0106026495-999", "tok",
		map[string]any{"inv_originalId": "cu-1"})
	if err != nil {
		t.Fatalf("thay thế: %v", err)
	}

	if duong != "/api/InvoiceApi78/ThayThe" {
		t.Fatalf("gọi nhầm đường: %s", duong)
	}
	if _, co := nhan["editmode"]; co {
		t.Fatalf("thay thế KHÔNG được gửi editmode, thân là %v", nhan)
	}
	ds, ok := nhan["data"].([]any)
	if !ok || len(ds) != 1 || ds[0].(map[string]any)["inv_originalId"] != "cu-1" {
		t.Fatalf("hoá đơn phải nằm trong mảng data, nhận %v", nhan["data"])
	}
	if kq.SoHoaDon != "9" || kq.MaHoaDon != "moi-1" {
		t.Fatalf("đọc sai kết quả: %+v", kq)
	}
}

// Điều chỉnh gửi payload PHẲNG — không `editmode`, không bọc thêm `data` quanh
// cả tờ; `data` ở đây chính là mảng dòng chênh lệch.
func TestDieuChinhGuiThanPhang(t *testing.T) {
	var nhan map[string]any
	sv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/api/InvoiceApi78/DieuChinh" {
			t.Errorf("gọi nhầm đường: %s", r.URL.Path)
		}
		du, _ := io.ReadAll(r.Body)
		_ = json.Unmarshal(du, &nhan)
		_, _ = w.Write([]byte(`{"code":"00","data":{"inv_invoiceNumber":"15","id":"dc-1"}}`))
	}))
	defer sv.Close()

	_, err := NewVoiGoc(sv.URL).DieuChinh(context.Background(), "0106026495-999", "tok", map[string]any{
		"inv_InvoiceAuth_id": "cu-1",
		"data":               []any{map[string]any{"inv_itemName": "Quần bò"}},
	})
	if err != nil {
		t.Fatalf("điều chỉnh: %v", err)
	}

	if nhan["inv_InvoiceAuth_id"] != "cu-1" {
		t.Fatalf("id tờ cũ phải nằm ở gốc thân, nhận %v", nhan)
	}
	if _, co := nhan["editmode"]; co {
		t.Fatalf("điều chỉnh KHÔNG được gửi editmode, thân là %v", nhan)
	}
}

// Cổng hỏng thì trả JSON chứ không trả PDF. Nhận nhầm một trang lỗi thành tệp
// PDF nghĩa là người dùng mở tab mới và nhìn một trang trắng.
func TestBanInNhanRaTraLoiLoiThayViPDF(t *testing.T) {
	var auth string
	sv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		auth = r.Header.Get("Authorization")
		_, _ = w.Write([]byte(`{"code":"29404","message":"Không tìm thấy hóa đơn cần xem"}`))
	}))
	defer sv.Close()

	_, err := NewVoiGoc(sv.URL).BanIn(context.Background(), "0106026495-999", "tok", "", "hd-1", false)
	if err == nil || !strings.Contains(err.Error(), "Không tìm thấy hóa đơn") {
		t.Fatalf("phải bắt được lỗi trong thân JSON, nhận %v", err)
	}
	// Lượt in đòi mã đơn vị dính vào chính token, mặc định là VP.
	if auth != "Bearer tok;VP" {
		t.Fatalf("Authorization của lượt in phải kèm mã đơn vị, nhận %q", auth)
	}
}

func TestBanInTraVeDungTepPDF(t *testing.T) {
	var duong string
	sv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		duong = r.URL.RawQuery
		_, _ = w.Write([]byte("%PDF-1.4 giả lập"))
	}))
	defer sv.Close()

	du, err := NewVoiGoc(sv.URL).BanIn(context.Background(), "0106026495-999", "tok", "CN1", "hd-1", true)
	if err != nil {
		t.Fatalf("lấy bản in: %v", err)
	}
	if !strings.HasPrefix(string(du), "%PDF") {
		t.Fatalf("phải trả về nguyên tệp PDF, nhận %q", string(du))
	}
	if !strings.Contains(duong, "inchuyendoi=true") {
		t.Fatalf("bản chuyển đổi phải kèm inchuyendoi=true, nhận %q", duong)
	}
}

func TestBanXMLGiaiBase64(t *testing.T) {
	goc := `<?xml version="1.0"?><HDon/>`
	sv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		_, _ = w.Write([]byte(`{"code":"00","data":"` + base64.StdEncoding.EncodeToString([]byte(goc)) + `"}`))
	}))
	defer sv.Close()

	du, err := NewVoiGoc(sv.URL).BanXML(context.Background(), "0106026495-999", "tok", "hd-1")
	if err != nil {
		t.Fatalf("lấy XML: %v", err)
	}
	if string(du) != goc {
		t.Fatalf("XML giải ra sai: %q", string(du))
	}
}

// Tờ chưa ký thì cổng trả code "00" nhưng `data` rỗng. Đó là "chưa có", không
// phải "thành công với tệp rỗng".
func TestBanXMLRongThiBaoChuaKy(t *testing.T) {
	sv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		_, _ = w.Write([]byte(`{"code":"00","data":""}`))
	}))
	defer sv.Close()

	_, err := NewVoiGoc(sv.URL).BanXML(context.Background(), "0106026495-999", "tok", "hd-1")
	if err == nil || !strings.Contains(err.Error(), "chưa ký") {
		t.Fatalf("phải nói rõ hoá đơn chưa ký, nhận %v", err)
	}
}

func TestTraCuuMSTDocDuocTenDonVi(t *testing.T) {
	sv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Query().Get("tax") != "0106026495" {
			t.Errorf("gửi nhầm mã số thuế: %s", r.URL.RawQuery)
		}
		_, _ = w.Write([]byte(`{"ma_so_thue":"0106026495","ten_cty":"CÔNG TY M-INVOICE","dia_chi":"Hà Nội"}`))
	}))
	defer sv.Close()

	tt, err := NewVoiGoc(sv.URL).TraCuuMST(context.Background(), " 0106026495 ")
	if err != nil {
		t.Fatalf("tra cứu: %v", err)
	}
	if tt.TenCongTy != "CÔNG TY M-INVOICE" {
		t.Fatalf("đọc sai tên đơn vị: %q", tt.TenCongTy)
	}
}

// Máy chủ chưa đăng ký IP thì M-Invoice trả 403. Đó là việc đi ĐĂNG KÝ với nhà
// cung cấp, không phải gõ sai mã số thuế — hai câu dẫn tới hai việc khác nhau.
func TestTraCuuMSTChuaMoQuyenThiNoiRo(t *testing.T) {
	sv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.WriteHeader(http.StatusForbidden)
	}))
	defer sv.Close()

	_, err := NewVoiGoc(sv.URL).TraCuuMST(context.Background(), "0106026495")
	if err != ErrChuaMoTraCuu {
		t.Fatalf("phải là ErrChuaMoTraCuu, nhận %v", err)
	}
}
