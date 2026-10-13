package apitest

import (
	"encoding/json"
	"fmt"
	"net/http"
	"strings"
	"testing"
)

// PHƯƠNG THỨC THANH TOÁN THEO CHI NHÁNH — luật lấy từ ordertable v2.
//
// Bài nào cũng đi qua HTTP thật trên DB test, vì phần lớn luật là "đọc rồi mới
// ghi" (đúng một mặc định, còn ít nhất một dòng bật) và chỉ lộ ra khi chạy cả
// chuỗi request.

type pmDS struct {
	ShopID    uint `json:"shop_id"`
	WebShopID uint `json:"web_shop_id"`
	CanEdit   bool `json:"can_edit"`
	Methods   []struct {
		ID        uint `json:"id"`
		Type      int  `json:"type"`
		Status    bool `json:"status"`
		IsDefault bool `json:"is_default"`
		Details   []struct {
			ID         uint              `json:"id"`
			DetailType string            `json:"detail_type"`
			Status     bool              `json:"status"`
			IsDefault  bool              `json:"is_default"`
			Data       map[string]string `json:"data"`
			Saved      map[string]bool   `json:"saved_secrets"`
		} `json:"details"`
	} `json:"methods"`
	Devices []struct {
		ID   uint   `json:"id"`
		Code string `json:"code"`
	} `json:"devices"`
	Banks []struct {
		BIN string `json:"bin"`
	} `json:"banks"`
}

func docPM(t *testing.T, h *heThong, token string) pmDS {
	t.Helper()
	res := h.goi(t, token, http.MethodGet, "/api/v1/admin/payment-methods", nil)
	if res.ma != http.StatusOK {
		t.Fatalf("GET payment-methods trả %d\n%s", res.ma, catBot(res.than))
	}
	var body struct {
		Data pmDS `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &body); err != nil {
		t.Fatalf("không đọc được danh sách: %v", err)
	}

	return body.Data
}

func (d pmDS) pt(loai int) (uint, bool, bool) {
	for _, m := range d.Methods {
		if m.Type == loai {
			return m.ID, m.Status, m.IsDefault
		}
	}

	return 0, false, false
}

// dong trả "id:trạngthái[M]" của các dòng chi tiết, để so cả danh sách một lần.
func (d pmDS) dong(loai int) string {
	var ra []string
	for _, m := range d.Methods {
		if m.Type != loai {
			continue
		}
		for _, c := range m.Details {
			s := fmt.Sprintf("%d:%v", c.ID, map[bool]int{true: 1, false: 0}[c.Status])
			if c.IsDefault {
				s += "M"
			}
			ra = append(ra, s)
		}
	}

	return strings.Join(ra, " ")
}

func idTra(t *testing.T, res traLoi) uint {
	t.Helper()
	var body struct {
		Data struct {
			ID uint `json:"id"`
		} `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &body); err != nil || body.Data.ID == 0 {
		t.Fatalf("phản hồi không có id: %s", catBot(res.than))
	}

	return body.Data.ID
}

func taiKhoan(bin, so, chu string, them map[string]any) map[string]any {
	b := map[string]any{"detail_type": "bank_transfer", "data": map[string]string{
		"bank_id": bin, "bank_account": so, "bank_account_name": chu,
	}}
	for k, v := range them {
		b[k] = v
	}

	return b
}

func TestPhuongThuc_DungSanVaLuatChuyenKhoan(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	ds := docPM(t, h, a.token)

	// KB PT01 — 4 phương thức dựng sẵn, tiền mặt bật và là mặc định.
	if len(ds.Methods) != 4 || !ds.CanEdit || len(ds.Banks) < 60 || ds.ShopID != a.chiNhanh || ds.WebShopID != a.chiNhanh {
		t.Fatalf("dựng sẵn sai: %d phương thức, can_edit=%v, %d ngân hàng, shop %d/%d (cn %d)",
			len(ds.Methods), ds.CanEdit, len(ds.Banks), ds.ShopID, ds.WebShopID, a.chiNhanh)
	}
	tm, tmBat, tmMD := ds.pt(1)
	ck, ckBat, _ := ds.pt(4)
	if !tmBat || !tmMD || ckBat {
		t.Fatalf("mặc định ban đầu phải là tiền mặt bật, chuyển khoản tắt")
	}
	// Đọc lần hai không đẻ thêm phương thức.
	if n := len(docPM(t, h, a.token).Methods); n != 4 {
		t.Fatalf("đọc lần hai ra %d phương thức", n)
	}

	goi := func(method, duong string, body any) traLoi {
		t.Helper()

		return h.goi(t, a.token, method, "/api/v1/admin/payment-methods"+duong, body)
	}
	bat := func(id uint, on bool) traLoi {
		return goi(http.MethodPut, fmt.Sprintf("/%d/status", id), map[string]any{"status": on})
	}

	// KB PT02 — bật chuyển khoản khi chưa có tài khoản: 409.
	if r := bat(ck, true); r.ma != http.StatusConflict {
		t.Fatalf("bật chuyển khoản trống phải 409, nhận %d %s", r.ma, catBot(r.than))
	}
	// Tắt tiền mặt: 409.
	if r := bat(tm, false); r.ma != http.StatusConflict {
		t.Fatalf("tắt tiền mặt phải 409, nhận %d", r.ma)
	}

	// KB PT03 — kiểm từng ô.
	r := goi(http.MethodPost, fmt.Sprintf("/%d/details", ck), taiKhoan("999999", "12 34", "A", nil))
	if r.ma != http.StatusUnprocessableEntity || !strings.Contains(r.than, "bank_id") || !strings.Contains(r.than, "bank_account") {
		t.Fatalf("BIN lạ + số TK có khoảng trắng phải 422 cho hai ô, nhận %d %s", r.ma, catBot(r.than))
	}
	r = goi(http.MethodPost, fmt.Sprintf("/%d/details", ck), taiKhoan("970436", "0071001234567", "Nguyễn Văn Đức", nil))
	if r.ma != http.StatusOK {
		t.Fatalf("thêm tài khoản hợp lệ phải 200, nhận %d %s", r.ma, catBot(r.than))
	}
	tk1 := idTra(t, r)
	ds = docPM(t, h, a.token)
	if ds.dong(4) != fmt.Sprintf("%d:1M", tk1) {
		t.Fatalf("tài khoản đầu tiên phải tự là mặc định: %s", ds.dong(4))
	}
	if chu := ds.Methods[1].Details[0].Data["bank_account_name"]; chu != "NGUYEN VAN DUC" {
		t.Fatalf("tên chủ TK phải viết hoa bỏ dấu, nhận %q", chu)
	}

	// KB PT04 — trùng tài khoản: 422; tài khoản khác: thêm được, không thành mặc định.
	if r := goi(http.MethodPost, fmt.Sprintf("/%d/details", ck), taiKhoan("970436", "0071001234567", "X", nil)); r.ma != http.StatusUnprocessableEntity {
		t.Fatalf("trùng tài khoản phải 422, nhận %d", r.ma)
	}
	tk2 := idTra(t, goi(http.MethodPost, fmt.Sprintf("/%d/details", ck), taiKhoan("970407", "190366889900", "CONG TY B", map[string]any{"data": map[string]string{
		"bank_id": "970407", "bank_account": "190366889900", "bank_account_name": "CONG TY B", "edit_amount": "1",
	}})))
	// Thêm dòng TẮT kèm tích mặc định: 422.
	tat := false
	if r := goi(http.MethodPost, fmt.Sprintf("/%d/details", ck), taiKhoan("970422", "0868", "C", map[string]any{"status": &tat, "is_default": true})); r.ma != http.StatusUnprocessableEntity {
		t.Fatalf("dòng tắt mà tích mặc định phải 422, nhận %d", r.ma)
	}
	tk3 := idTra(t, goi(http.MethodPost, fmt.Sprintf("/%d/details", ck), taiKhoan("970422", "0868", "C", map[string]any{"status": &tat})))
	ds = docPM(t, h, a.token)
	if want := fmt.Sprintf("%d:1M %d:1 %d:0", tk1, tk2, tk3); ds.dong(4) != want {
		t.Fatalf("sau khi thêm: %s, muốn %s", ds.dong(4), want)
	}

	// KB PT05 — bật chuyển khoản, đặt làm mặc định ở quầy.
	if r := bat(ck, true); r.ma != http.StatusOK {
		t.Fatalf("bật chuyển khoản phải 200, nhận %d %s", r.ma, catBot(r.than))
	}
	if r := goi(http.MethodPut, fmt.Sprintf("/%d/default", ck), nil); r.ma != http.StatusOK {
		t.Fatalf("đặt mặc định phải 200, nhận %d", r.ma)
	}
	ds = docPM(t, h, a.token)
	if _, _, md := ds.pt(1); md {
		t.Fatalf("đặt chuyển khoản mặc định thì tiền mặt phải hết mặc định")
	}
	// QR đang tắt: không đặt mặc định được.
	qr, _, _ := ds.pt(6)
	if r := goi(http.MethodPut, fmt.Sprintf("/%d/default", qr), nil); r.ma != http.StatusConflict {
		t.Fatalf("đặt mặc định QR đang tắt phải 409, nhận %d", r.ma)
	}

	// KB PT06 — đặt dòng 2 mặc định; tắt nó thì mặc định về dòng 1.
	dsPath := func(id uint, sau string) string { return fmt.Sprintf("/%d/details/%d%s", ck, id, sau) }
	if r := goi(http.MethodPut, dsPath(tk2, "/default"), nil); r.ma != http.StatusOK {
		t.Fatalf("đặt tk2 mặc định phải 200, nhận %d", r.ma)
	}
	if r := goi(http.MethodPut, dsPath(tk3, "/default"), nil); r.ma != http.StatusConflict {
		t.Fatalf("đặt dòng tắt mặc định phải 409, nhận %d", r.ma)
	}
	if r := goi(http.MethodPut, dsPath(tk2, "/status"), map[string]any{"status": false}); r.ma != http.StatusOK {
		t.Fatalf("tắt tk2 phải 200, nhận %d", r.ma)
	}
	if want, got := fmt.Sprintf("%d:1M %d:0 %d:0", tk1, tk2, tk3), docPM(t, h, a.token).dong(4); got != want {
		t.Fatalf("tắt dòng mặc định: %s, muốn %s", got, want)
	}
	// Tắt / xoá dòng bật cuối khi chuyển khoản đang bật: 409.
	if r := goi(http.MethodPut, dsPath(tk1, "/status"), map[string]any{"status": false}); r.ma != http.StatusConflict {
		t.Fatalf("tắt tài khoản bật cuối phải 409, nhận %d", r.ma)
	}
	if r := goi(http.MethodDelete, dsPath(tk1, ""), nil); r.ma != http.StatusConflict {
		t.Fatalf("xoá tài khoản bật cuối phải 409, nhận %d", r.ma)
	}
	// Sửa thành tắt cũng bị chặn y như vậy.
	if r := goi(http.MethodPut, dsPath(tk1, ""), taiKhoan("970436", "0071001234567", "A", map[string]any{"status": &tat})); r.ma != http.StatusConflict {
		t.Fatalf("sửa tài khoản bật cuối thành tắt phải 409, nhận %d", r.ma)
	}

	// KB PT07 — xoá dòng tắt được.
	if r := goi(http.MethodDelete, dsPath(tk3, ""), nil); r.ma != http.StatusOK {
		t.Fatalf("xoá dòng tắt phải 200, nhận %d", r.ma)
	}

	// KB PT08 — tắt chuyển khoản đang mặc định: mặc định về tiền mặt.
	if r := bat(ck, false); r.ma != http.StatusOK {
		t.Fatalf("tắt chuyển khoản phải 200, nhận %d", r.ma)
	}
	ds = docPM(t, h, a.token)
	if _, _, md := ds.pt(1); !md {
		t.Fatalf("tắt phương thức mặc định thì tiền mặt phải nhận lại mặc định")
	}
	// Bật lại khi không còn dòng nào bật → tự bật dòng cũ nhất (tk1 vẫn bật nên giữ nguyên).
	if r := bat(ck, true); r.ma != http.StatusOK {
		t.Fatalf("bật lại chuyển khoản phải 200, nhận %d", r.ma)
	}
}

func TestPhuongThuc_QRVaQuetThe(t *testing.T) {
	h := dungHeThong(t)
	a, b := haiCuaHang(t, h)
	ds := docPM(t, h, a.token)
	qr, _, _ := ds.pt(6)
	qt, _, _ := ds.pt(5)
	goi := func(tok, method, duong string, body any) traLoi {
		t.Helper()

		return h.goi(t, tok, method, "/api/v1/admin"+duong, body)
	}

	// KB PT09 — PayOS: ba khoá đi cùng nhau; khoá không bao giờ trả ra; DB cất bản đã mã hoá.
	r := goi(a.token, http.MethodPost, fmt.Sprintf("/payment-methods/%d/details", qr), map[string]any{
		"detail_type": "payos", "data": map[string]string{"payos_client_id": "cid"},
	})
	if r.ma != http.StatusUnprocessableEntity || !strings.Contains(r.than, "payos_api_key") {
		t.Fatalf("PayOS thiếu khoá phải 422, nhận %d %s", r.ma, catBot(r.than))
	}
	r = goi(a.token, http.MethodPost, fmt.Sprintf("/payment-methods/%d/details", qr), map[string]any{
		"detail_type": "payos", "data": map[string]string{"payos_client_id": "cid", "payos_api_key": "khoa-bi-mat-1", "payos_checksum_key": "khoa-bi-mat-2"},
	})
	if r.ma != http.StatusOK {
		t.Fatalf("thêm PayOS đủ khoá phải 200, nhận %d %s", r.ma, catBot(r.than))
	}
	cong := idTra(t, r)
	res := h.goi(t, a.token, http.MethodGet, "/api/v1/admin/payment-methods", nil)
	if strings.Contains(res.than, "khoa-bi-mat") || !strings.Contains(res.than, `"payos_api_key":true`) {
		t.Fatalf("danh sách lộ khoá hoặc thiếu cờ đã lưu: %s", catBot(res.than))
	}
	var raw string
	h.db.WithContext(ctxThoat()).Raw("SELECT data FROM payment_method_details WHERE id = ?", cong).Scan(&raw)
	if strings.Contains(raw, "khoa-bi-mat") || !strings.Contains(raw, "enc:v1:") {
		t.Fatalf("khoá phải mã hoá dưới DB: %s", raw)
	}
	// Sửa mà bỏ trống khoá: giữ khoá cũ.
	if r := goi(a.token, http.MethodPut, fmt.Sprintf("/payment-methods/%d/details/%d", qr, cong), map[string]any{
		"data": map[string]string{"payos_client_id": "cid2"},
	}); r.ma != http.StatusOK {
		t.Fatalf("sửa PayOS bỏ trống khoá phải 200, nhận %d %s", r.ma, catBot(r.than))
	}
	var raw2 string
	h.db.WithContext(ctxThoat()).Raw("SELECT data FROM payment_method_details WHERE id = ?", cong).Scan(&raw2)
	if !strings.Contains(raw2, "cid2") || strings.Count(raw2, "enc:v1:") != 2 {
		t.Fatalf("sửa bỏ trống khoá phải giữ hai khoá đã mã hoá: %s", raw2)
	}
	// Cổng thứ hai cùng loại: 422.
	if r := goi(a.token, http.MethodPost, fmt.Sprintf("/payment-methods/%d/details", qr), map[string]any{"detail_type": "payos", "data": map[string]string{}}); r.ma != http.StatusUnprocessableEntity {
		t.Fatalf("PayOS thứ hai phải 422, nhận %d", r.ma)
	}
	// Loại không thuộc QR: 422.
	if r := goi(a.token, http.MethodPost, fmt.Sprintf("/payment-methods/%d/details", qr), map[string]any{"detail_type": "bank_transfer"}); r.ma != http.StatusUnprocessableEntity {
		t.Fatalf("bank_transfer dưới QR phải 422, nhận %d", r.ma)
	}

	// KB PT10 — quẹt thẻ: đúng loại máy, mỗi máy một cấu hình, phí 0–10.
	may := func(code string, vatLy bool) uint {
		t.Helper()
		r := goi(a.token, http.MethodPost, "/payment-devices", map[string]any{"code": code, "name": "May " + code, "is_physical": vatLy})
		if r.ma != http.StatusOK {
			t.Fatalf("thêm máy phải 200, nhận %d %s", r.ma, catBot(r.than))
		}

		return idTra(t, r)
	}
	mpos, doclap := may("NP-1", false), may("VCB-1", true)
	if r := goi(a.token, http.MethodPost, "/payment-devices", map[string]any{"code": "np-1", "name": "Trung"}); r.ma != http.StatusUnprocessableEntity {
		t.Fatalf("trùng POS ID phải 422, nhận %d", r.ma)
	}
	the := func(loai string, dev uint, phi map[string]string) traLoi {
		data := map[string]string{"bank_id": "970418", "bank_account": "2151", "bank_account_name": "A",
			"mpos_merchant_id": "M", "mpos_muid": "u", "mpos_secret_key": "s"}
		for k, v := range phi {
			data[k] = v
		}

		return goi(a.token, http.MethodPost, fmt.Sprintf("/payment-methods/%d/details", qt), map[string]any{"detail_type": loai, "device_id": dev, "data": data})
	}
	if r := the("mpos_device", doclap, nil); r.ma != http.StatusUnprocessableEntity || !strings.Contains(r.than, "device_id") {
		t.Fatalf("mPOS gắn máy độc lập phải 422, nhận %d %s", r.ma, catBot(r.than))
	}
	if r := the("mpos_device", mpos, map[string]string{"card_fee_local": "12"}); r.ma != http.StatusUnprocessableEntity || !strings.Contains(r.than, "card_fee_local") {
		t.Fatalf("phí 12%% phải 422, nhận %d", r.ma)
	}
	if r := the("mpos_device", mpos, map[string]string{"card_fee_local": "0,555"}); r.ma != http.StatusOK {
		t.Fatalf("mPOS hợp lệ phải 200, nhận %d %s", r.ma, catBot(r.than))
	}
	if r := the("physical_device", mpos, nil); r.ma != http.StatusUnprocessableEntity {
		t.Fatalf("máy đã gắn phải 422, nhận %d", r.ma)
	}
	ds = docPM(t, h, a.token)
	for _, m := range ds.Methods {
		if m.Type == 5 && (len(m.Details) != 1 || m.Details[0].Data["card_fee_local"] != "0.56" || m.Details[0].Data["card_fee_domestic"] != "0.00" || !m.Details[0].IsDefault) {
			t.Fatalf("phí chuẩn hoá / mặc định sai: %+v", m.Details)
		}
	}

	// KB PT11 — cửa hàng khác không thấy, không sửa được.
	if strings.Contains(h.goi(t, b.token, http.MethodGet, "/api/v1/admin/payment-methods", nil).than, `"NP-1"`) {
		t.Fatalf("cửa hàng B thấy máy của A")
	}
	if r := goi(b.token, http.MethodPut, fmt.Sprintf("/payment-methods/%d/details/%d/status", qr, cong), map[string]any{"status": false}); r.ma != http.StatusNotFound {
		t.Fatalf("B sửa chi tiết của A phải 404, nhận %d", r.ma)
	}
}

// KB PT12 — thu ngân không vào màn cấu hình, nhưng quầy đọc được bản rút gọn không có khoá.
func TestPhuongThuc_QuyenThuNgan(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	ds := docPM(t, h, a.token)
	ck, _, _ := ds.pt(4)
	h.goi(t, a.token, http.MethodPost, fmt.Sprintf("/api/v1/admin/payment-methods/%d/details", ck), taiKhoan("970436", "0071", "A", nil))
	h.goi(t, a.token, http.MethodPut, fmt.Sprintf("/api/v1/admin/payment-methods/%d/status", ck), map[string]any{"status": true})

	thuNgan := h.dangNhapVoi(t, a.ma, "nhanvien")
	if r := h.goi(t, thuNgan, http.MethodGet, "/api/v1/admin/payment-methods", nil); r.ma != http.StatusForbidden {
		t.Fatalf("thu ngân vào màn cấu hình phải 403, nhận %d", r.ma)
	}
	r := h.goi(t, thuNgan, http.MethodGet, "/api/v1/admin/orders/pos/payment-methods", nil)
	if r.ma != http.StatusOK || !strings.Contains(r.than, `"bank_name":"Vietcombank"`) || !strings.Contains(r.than, `"type":1`) {
		t.Fatalf("quầy phải đọc được tiền mặt + tài khoản mặc định, nhận %d %s", r.ma, catBot(r.than))
	}
	if strings.Contains(r.than, `"type":5`) {
		t.Fatalf("quẹt thẻ đang tắt không được chào ra ở quầy: %s", catBot(r.than))
	}
}

// KB PT13 — website lấy tài khoản của chi nhánh trung tâm, không còn đọc trang Cài đặt cũ.
func TestPhuongThuc_WebsiteDocChiNhanhTrungTam(t *testing.T) {
	// Gọi bằng token chứ không qua tên miền: bộ test thường không có control
	// plane, mà cửa hàng phân giải từ token cũng là đường storefront đang chạy.
	h := dungHeThongBanHang(t)
	a, _ := haiCuaHang(t, h)
	xem := func() map[string]string {
		t.Helper()
		res := h.goi(t, a.token, http.MethodGet, "/api/v1/settings", nil)
		if res.ma != http.StatusOK {
			t.Fatalf("GET /settings trả %d", res.ma)
		}
		var body struct {
			Data map[string]string `json:"data"`
		}
		_ = json.Unmarshal([]byte(res.than), &body)

		return body.Data
	}
	if v := xem(); v["payment_bank_enabled"] != "0" {
		t.Fatalf("chưa khai tài khoản thì website không chào chuyển khoản: %v", v["payment_bank_enabled"])
	}

	ds := docPM(t, h, a.token)
	ck, _, _ := ds.pt(4)
	h.goi(t, a.token, http.MethodPost, fmt.Sprintf("/api/v1/admin/payment-methods/%d/details", ck), taiKhoan("970422", "0868123456", "Trần Thị B", nil))
	if r := h.goi(t, a.token, http.MethodPut, fmt.Sprintf("/api/v1/admin/payment-methods/%d/status", ck), map[string]any{"status": true}); r.ma != http.StatusOK {
		t.Fatalf("bật chuyển khoản trả %d", r.ma)
	}
	v := xem()
	if v["payment_bank_enabled"] != "1" || v["bank_name"] != "MBBank" || v["bank_account_number"] != "0868123456" || v["bank_account_name"] != "TRAN THI B" {
		t.Fatalf("website phải đọc tài khoản mặc định của chi nhánh trung tâm, nhận %v", v)
	}
	// Tắt chuyển khoản ở chi nhánh trung tâm → website tắt theo.
	h.goi(t, a.token, http.MethodPut, fmt.Sprintf("/api/v1/admin/payment-methods/%d/status", ck), map[string]any{"status": false})
	if v := xem(); v["payment_bank_enabled"] != "0" {
		t.Fatalf("tắt chuyển khoản thì website phải tắt theo, nhận %v", v["payment_bank_enabled"])
	}
}

// KB PT14 — cửa hàng nhiều chi nhánh mà chưa chọn chi nhánh: nhắc chọn, đúng ngữ cảnh màn này.
func TestPhuongThuc_NhieuChiNhanhPhaiChon(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	cn2 := moChiNhanhThuHai(t, h, a, "Quay hai")
	r := h.goi(t, a.token, http.MethodGet, "/api/v1/admin/payment-methods", nil)
	if r.ma != http.StatusConflict || !strings.Contains(r.than, "bộ phương thức thanh toán riêng") {
		t.Fatalf("chưa chọn chi nhánh phải 409 kèm câu nhắc chọn, nhận %d %s", r.ma, catBot(r.than))
	}
	r = h.goiVoiHeader(t, a.token, http.MethodGet, "/api/v1/admin/payment-methods", nil, map[string]string{"X-Chi-Nhanh": fmt.Sprint(cn2)})
	if r.ma != http.StatusOK || !strings.Contains(r.than, fmt.Sprintf(`"shop_id":%d`, cn2)) {
		t.Fatalf("khai chi nhánh thì đọc đúng chi nhánh đó, nhận %d %s", r.ma, catBot(r.than))
	}
}
