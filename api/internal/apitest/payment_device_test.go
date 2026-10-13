package apitest

import (
	"fmt"
	"net/http"
	"strings"
	"testing"
)

// MÁY QUẸT THẺ: sửa POS ID / tên, xoá máy chưa gắn cấu hình.

func mayCua(d pmDS, id uint) (string, bool) {
	for _, m := range d.Devices {
		if m.ID == id {
			return m.Code, true
		}
	}

	return "", false
}

// KB M01–M03 — sửa: trùng POS ID 422, POS ID trống 422, sửa được kể cả khi máy đã gắn.
func TestMayQuetThe_Sua(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	tao := func(code string, vatLy bool) uint {
		return idTra(t, h.goi(t, a.token, http.MethodPost, "/api/v1/admin/payment-devices", map[string]any{"code": code, "name": "May " + code, "is_physical": vatLy}))
	}
	m1, m2 := tao("VCB-1"+a.vet, true), tao("VCB-2"+a.vet, true)
	sua := func(id uint, body map[string]any) traLoi {
		return h.goi(t, a.token, http.MethodPut, fmt.Sprintf("/api/v1/admin/payment-devices/%d", id), body)
	}

	if r := sua(m1, map[string]any{"code": strings.ToLower("VCB-2" + a.vet), "name": "X"}); r.ma != http.StatusUnprocessableEntity || !strings.Contains(r.than, "POS ID này") {
		t.Fatalf("POS ID trùng máy khác (không phân biệt hoa thường) phải 422, nhận %d %s", r.ma, catBot(r.than))
	}
	if r := sua(m1, map[string]any{"code": " ", "name": ""}); r.ma != http.StatusUnprocessableEntity || !strings.Contains(r.than, `"name"`) {
		t.Fatalf("POS ID / tên trống phải 422, nhận %d %s", r.ma, catBot(r.than))
	}

	// Gắn m1 vào một cấu hình rồi sửa: vẫn được, và loại máy giữ nguyên dù body gửi khác.
	qt, _, _ := docPM(t, h, a.token).pt(5)
	idTra(t, h.goi(t, a.token, http.MethodPost, fmt.Sprintf("/api/v1/admin/payment-methods/%d/details", qt), map[string]any{
		"detail_type": "physical_device", "device_id": m1,
		"data": map[string]string{"card_fee_physical": "1", "bank_id": "970436", "bank_account": "1", "bank_account_name": "A"},
	}))
	r := sua(m1, map[string]any{"code": "  VCB-SUA" + a.vet + " ", "name": " Máy quầy 1 ", "description": "đổi", "is_physical": false})
	if r.ma != http.StatusOK || !strings.Contains(r.than, `"code":"VCB-SUA`+a.vet+`"`) || !strings.Contains(r.than, `"name":"Máy quầy 1"`) || !strings.Contains(r.than, `"is_physical":true`) {
		t.Fatalf("sửa máy đã gắn phải 200, cắt khoảng trắng, giữ loại máy; nhận %d %s", r.ma, catBot(r.than))
	}
	if code, _ := mayCua(docPM(t, h, a.token), m1); code != "VCB-SUA"+a.vet {
		t.Fatalf("danh sách phải đọc POS ID mới, nhận %q", code)
	}
	// Giữ nguyên POS ID của chính nó: không bị coi là trùng.
	if r := sua(m2, map[string]any{"code": "VCB-2" + a.vet, "name": "Tên mới"}); r.ma != http.StatusOK {
		t.Fatalf("lưu lại POS ID của chính máy phải 200, nhận %d %s", r.ma, catBot(r.than))
	}
	if r := sua(999999, map[string]any{"code": "Z", "name": "Z"}); r.ma != http.StatusNotFound {
		t.Fatalf("máy không tồn tại phải 404, nhận %d", r.ma)
	}
}

// KB M04–M06 — xoá: đang gắn 409, chưa gắn thì xoá được, bỏ cấu hình rồi xoá được.
func TestMayQuetThe_Xoa(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	gan := idTra(t, h.goi(t, a.token, http.MethodPost, "/api/v1/admin/payment-devices", map[string]any{"code": "G" + a.vet, "name": "Gan", "is_physical": true}))
	nham := idTra(t, h.goi(t, a.token, http.MethodPost, "/api/v1/admin/payment-devices", map[string]any{"code": "N" + a.vet, "name": "Go nham", "is_physical": true}))
	xoa := func(id uint) traLoi {
		return h.goi(t, a.token, http.MethodDelete, fmt.Sprintf("/api/v1/admin/payment-devices/%d", id), nil)
	}

	qt, _, _ := docPM(t, h, a.token).pt(5)
	ct := idTra(t, h.goi(t, a.token, http.MethodPost, fmt.Sprintf("/api/v1/admin/payment-methods/%d/details", qt), map[string]any{
		"detail_type": "physical_device", "device_id": gan,
		"data": map[string]string{"card_fee_physical": "1", "bank_id": "970436", "bank_account": "1", "bank_account_name": "A"},
	}))

	if r := xoa(gan); r.ma != http.StatusConflict || !strings.Contains(r.than, "đang gắn") {
		t.Fatalf("xoá máy đang gắn cấu hình phải 409 kèm lý do, nhận %d %s", r.ma, catBot(r.than))
	}
	if _, con := mayCua(docPM(t, h, a.token), gan); !con {
		t.Fatalf("máy bị chặn xoá phải còn nguyên")
	}

	if r := xoa(nham); r.ma != http.StatusOK {
		t.Fatalf("xoá máy chưa gắn phải 200, nhận %d %s", r.ma, catBot(r.than))
	}
	if _, con := mayCua(docPM(t, h, a.token), nham); con {
		t.Fatalf("máy đã xoá không được còn trong danh sách chọn")
	}
	if r := xoa(nham); r.ma != http.StatusNotFound {
		t.Fatalf("xoá lần hai phải 404, nhận %d", r.ma)
	}

	// Bỏ cấu hình (chưa có đơn) rồi xoá máy: được.
	if r := h.goi(t, a.token, http.MethodDelete, fmt.Sprintf("/api/v1/admin/payment-methods/%d/details/%d", qt, ct), nil); r.ma != http.StatusOK {
		t.Fatalf("xoá cấu hình trả %d %s", r.ma, catBot(r.than))
	}
	if r := xoa(gan); r.ma != http.StatusOK {
		t.Fatalf("máy đã bỏ cấu hình phải xoá được, nhận %d %s", r.ma, catBot(r.than))
	}
}

// KB M07–M08 — không đụng được máy của cửa hàng khác; thu ngân không có quyền.
func TestMayQuetThe_PhamVi(t *testing.T) {
	h := dungHeThong(t)
	a, b := haiCuaHang(t, h)
	may := idTra(t, h.goi(t, a.token, http.MethodPost, "/api/v1/admin/payment-devices", map[string]any{"code": "A" + a.vet, "name": "May A"}))
	duong := fmt.Sprintf("/api/v1/admin/payment-devices/%d", may)

	if r := h.goi(t, b.token, http.MethodPut, duong, map[string]any{"code": "B", "name": "B"}); r.ma != http.StatusNotFound {
		t.Fatalf("cửa hàng khác sửa máy phải 404, nhận %d", r.ma)
	}
	if r := h.goi(t, b.token, http.MethodDelete, duong, nil); r.ma != http.StatusNotFound {
		t.Fatalf("cửa hàng khác xoá máy phải 404, nhận %d", r.ma)
	}
	thuNgan := h.dangNhapVoi(t, a.ma, "nhanvien")
	if r := h.goi(t, thuNgan, http.MethodPut, duong, map[string]any{"code": "C", "name": "C"}); r.ma != http.StatusForbidden {
		t.Fatalf("thu ngân sửa máy phải 403, nhận %d", r.ma)
	}
	if r := h.goi(t, thuNgan, http.MethodDelete, duong, nil); r.ma != http.StatusForbidden {
		t.Fatalf("thu ngân xoá máy phải 403, nhận %d", r.ma)
	}
	if code, _ := mayCua(docPM(t, h, a.token), may); code != "A"+a.vet {
		t.Fatalf("máy phải còn nguyên sau các lượt bị chặn, nhận %q", code)
	}
}
