package sepayinvoice

import (
	"context"
	"encoding/base64"
	"errors"
	"net/http"
	"net/http/httptest"
	"testing"
)

// Bài kiểm client SePay eInvoice: cách đọc phong bì trả lời. Ba chỗ dễ sai nhất
// là lỗi nằm ở HTTP status + `error.code`, lượt liệt kê tài khoản KHÔNG có
// `success`, và tệp tải về là base64.

func cong(t *testing.T, xuLy http.HandlerFunc) *Client {
	t.Helper()
	sv := httptest.NewServer(xuLy)
	t.Cleanup(sv.Close)

	return New(sv.URL)
}

func TestLayTokenGuiBasicAuth(t *testing.T) {
	c := cong(t, func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodPost || r.URL.Path != "/v1/token" {
			t.Errorf("gọi nhầm %s %s", r.Method, r.URL.Path)
		}
		id, bm, ok := r.BasicAuth()
		if !ok || id != "cid" || bm != "bi:mat" {
			t.Errorf("Basic auth phải là cid / bi:mat, nhận %q %q %v", id, bm, ok)
		}
		_, _ = w.Write([]byte(`{"success":true,"data":{"access_token":"tok","token_type":"Bearer","expires_in":86400}}`))
	})

	tok, err := c.LayToken(context.Background(), "cid", "bi:mat")
	if err != nil || tok != "tok" {
		t.Fatalf("phải lấy được token tok, nhận %q %v", tok, err)
	}
}

// Sai client secret: cổng trả 401 — phải thành ErrDangNhap chứ không phải câu
// "hết hạn token" (nơi gọi sẽ đăng nhập lại vô ích).
func TestLayTokenSaiThiErrDangNhap(t *testing.T) {
	c := cong(t, func(w http.ResponseWriter, _ *http.Request) {
		w.WriteHeader(http.StatusUnauthorized)
		_, _ = w.Write([]byte(`{"success":false,"error":{"code":"BAD_REQUEST","message":"Thiếu hoặc sai"}}`))
	})

	if _, err := c.LayToken(context.Background(), "cid", "sai"); !errors.Is(err, ErrDangNhap) {
		t.Fatalf("phải là ErrDangNhap, nhận %v", err)
	}
}

func TestLoiDongBoDocMaVaCau(t *testing.T) {
	c := cong(t, func(w http.ResponseWriter, r *http.Request) {
		switch r.URL.Path {
		case "/v1/invoices/create":
			w.WriteHeader(http.StatusConflict)
			_, _ = w.Write([]byte(`{"success":false,"error":{"code":"EINVOICE_DOCUMENT_EXISTED","message":"Hệ thống đã ghi nhận hoá đơn cho mã tham chiếu này."}}`))
		default:
			w.WriteHeader(http.StatusUnauthorized)
			_, _ = w.Write([]byte(`{"success":false,"error":{"code":"TOKEN_EXPIRED","message":"Token hết hạn"}}`))
		}
	})

	_, err := c.TaoHoaDon(context.Background(), "t", map[string]any{})
	if !LaDaCo(err) || LaHetToken(err) {
		t.Fatalf("409 phải là LaDaCo, nhận %v", err)
	}
	if err.Error() != "sepay: Hệ thống đã ghi nhận hoá đơn cho mã tham chiếu này." {
		t.Fatalf("phải giữ nguyên câu của cổng, nhận %q", err.Error())
	}

	_, err = c.ChiTiet(context.Background(), "t", "DH1")
	if !LaHetToken(err) {
		t.Fatalf("401 phải là LaHetToken, nhận %v", err)
	}
}

// HTTP 200 mà success=false vẫn là HỎNG.
func TestThanhCongGiaVanLaLoi(t *testing.T) {
	c := cong(t, func(w http.ResponseWriter, _ *http.Request) {
		_, _ = w.Write([]byte(`{"success":false,"error":{"code":"VALIDATION_ERROR","message":"invoice_series sai năm"}}`))
	})

	if _, err := c.TaoHoaDon(context.Background(), "t", map[string]any{}); err == nil {
		t.Fatal("success=false phải là lỗi")
	}
}

// Lượt liệt kê tài khoản không có `success` — vắng mặt không phải hỏng.
func TestDanhSachTaiKhoanKhongCoSuccess(t *testing.T) {
	c := cong(t, func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("Authorization") != "Bearer t" {
			t.Errorf("thiếu Bearer token: %q", r.Header.Get("Authorization"))
		}
		_, _ = w.Write([]byte(`{"data":{"paging":{"total":2},"items":[
			{"id":"a","provider":"matbao","active":true,"tax_authority_approved_date":"2026-04-20"},
			{"id":"b","provider":"matbao","active":false,"tax_authority_approved_date":null}]}}`))
	})

	ds, err := c.DanhSachTaiKhoan(context.Background(), "t")
	if err != nil || len(ds) != 2 || !ds[0].Active || ds[1].Active {
		t.Fatalf("phải đọc được hai tài khoản, nhận %+v %v", ds, err)
	}
}

func TestKiemTraTaoDocHoaDonVaGiuNguyenVan(t *testing.T) {
	c := cong(t, func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/v1/invoices/create/check/trk-1" {
			t.Errorf("gọi nhầm %s", r.URL.Path)
		}
		_, _ = w.Write([]byte(`{"success":true,"data":{"reference_code":"DH1","status":"Success","message":"ok",
			"invoice":{"reference_code":"DH1","invoice_number":589,"status":"issued"}}}`))
	})

	kq, err := c.KiemTraTao(context.Background(), "t", "trk-1")
	if err != nil {
		t.Fatal(err)
	}
	// invoice_number lúc là số, lúc là chuỗi — đọc được cả hai.
	if kq.TrangThai != ThanhCong || kq.HoaDon == nil || string(kq.HoaDon.SoHoaDon) != "589" || kq.HoaDon.TrangThai != "issued" {
		t.Fatalf("đọc sai kết quả: %+v", kq)
	}
	if len(kq.Tho) == 0 {
		t.Fatal("phải giữ nguyên văn phần data")
	}
}

func TestTaiTepGiaiBase64(t *testing.T) {
	pdf := []byte("%PDF-1.4 hoa don")
	c := cong(t, func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/v1/invoices/DH1/download" || r.URL.Query().Get("type") != "pdf" {
			t.Errorf("gọi nhầm %s?%s", r.URL.Path, r.URL.RawQuery)
		}
		_, _ = w.Write([]byte(`{"success":true,"data":{"file_type":"pdf","file_name":"a.pdf","content":"` +
			base64.StdEncoding.EncodeToString(pdf) + `"}}`))
	})

	du, err := c.TaiTep(context.Background(), "t", "DH1", "pdf")
	if err != nil || string(du) != string(pdf) {
		t.Fatalf("phải giải base64 ra đúng tệp, nhận %q %v", du, err)
	}
}

// Trả lời không phải JSON (trang HTML lỗi của proxy) phải thành lỗi có mã HTTP.
func TestTraLoiKhongPhaiJSON(t *testing.T) {
	c := cong(t, func(w http.ResponseWriter, _ *http.Request) {
		w.WriteHeader(http.StatusBadGateway)
		_, _ = w.Write([]byte(`<html>502</html>`))
	})

	_, err := c.ChiTiet(context.Background(), "t", "DH1")
	var l *Loi
	if !errors.As(err, &l) || l.HTTP != http.StatusBadGateway {
		t.Fatalf("phải là Loi HTTP 502, nhận %v", err)
	}
}

// Khách không chọn môi trường: tên đăng nhập EINV-TEST-… tự đi sandbox. Máy đã
// khai cứng (env, bộ test) thì không đổi.
func TestChoChonMayTheoTenDangNhap(t *testing.T) {
	c := New("")
	if c.Cho("EINV-TEST-18PLZSFXG1Z9O6EQ").goc != GocThu {
		t.Fatal("tài khoản sandbox phải đi máy thử")
	}
	if c.Cho("EINV-ABC").goc != GocThat || c.goc != GocThat {
		t.Fatal("tài khoản thật phải đi máy thật, và Cho không được sửa client gốc")
	}
	if New("http://127.0.0.1:9").Cho("EINV-TEST-1").goc != "http://127.0.0.1:9" {
		t.Fatal("máy khai cứng thì không đổi")
	}
}
