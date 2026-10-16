package apitest

import (
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"strings"
	"testing"

	"sass-api/internal/tenant"
)

// CÀI ĐẶT THIẾT BỊ — máy in, phiếu nào ra máy nào, nút in, mẫu in.
// Luật lấy từ system/parameter/setup-store của ordertable v2 (xem domain).

type tbDS struct {
	ShopID   uint `json:"shop_id"`
	CanEdit  bool `json:"can_edit"`
	Printers []struct {
		ID       uint   `json:"id"`
		Name     string `json:"name"`
		ConnType string `json:"conn_type"`
		Address  string `json:"address"`
		IsActive bool   `json:"is_active"`
	} `json:"printers"`
	Routes []struct {
		ID            uint   `json:"id"`
		SlipType      string `json:"slip_type"`
		PrinterID     uint   `json:"printer_id"`
		AllCategories bool   `json:"all_categories"`
		CategoryIDs   []uint `json:"category_ids"`
		IsActive      bool   `json:"is_active"`
	} `json:"routes"`
	Buttons []struct {
		Ma       string   `json:"ma"`
		Slips    []string `json:"slips"`
		IsActive bool     `json:"is_active"`
	} `json:"buttons"`
	Templates []tbMau `json:"templates"`
	Slips     []struct {
		Ma string `json:"ma"`
	} `json:"slips"`
	Groups []struct {
		ID   uint   `json:"id"`
		Name string `json:"name"`
	} `json:"groups"`
}

type tbMau struct {
	Ma       string          `json:"ma"`
	Options  map[string]bool `json:"options"`
	Settings struct {
		Kho       string `json:"kho"`
		Ban       int    `json:"ban"`
		FooterTxt string `json:"footer_txt"`
		KhoTem    string `json:"kho_tem"`
		Cot       int    `json:"cot"`
	} `json:"settings"`
	DaChinh bool `json:"da_chinh"`
}

func docTB(t *testing.T, h *heThong, token string) tbDS {
	t.Helper()
	res := h.goi(t, token, http.MethodGet, "/api/v1/admin/thiet-bi", nil)
	if res.ma != http.StatusOK {
		t.Fatalf("GET thiet-bi trả %d\n%s", res.ma, catBot(res.than))
	}
	var body struct {
		Data tbDS `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &body); err != nil {
		t.Fatalf("không đọc được thiết bị: %v", err)
	}

	return body.Data
}

func (d tbDS) mau(ma string) tbMau {
	for _, m := range d.Templates {
		if m.Ma == ma {
			return m
		}
	}

	return tbMau{}
}

func mayIn(ten, ket, dc, kho string) map[string]any {
	return map[string]any{"name": ten, "conn_type": ket, "address": dc, "paper": kho, "dpi": 203}
}

// nhomHang gieo một nhóm hàng gốc cho cửa hàng, trả id.
func nhomHang(t *testing.T, h *heThong, c *cuaHang, ten string) uint {
	t.Helper()
	slug := strings.ToLower(strings.ReplaceAll(ten, " ", "-")) + "-" + c.vet
	db := h.db.WithContext(tenant.WithoutScope(context.Background(), "test: gieo nhóm hàng gốc cho cửa hàng giả"))
	if err := db.Exec(`INSERT INTO categories (tenant_id, name, slug, is_active, created_at, updated_at)
		VALUES (?, ?, ?, 1, NOW(3), NOW(3))`, c.id, ten+" "+c.vet, slug).Error; err != nil {
		t.Fatalf("gieo nhóm hàng: %v", err)
	}
	var id uint
	db.Raw("SELECT id FROM categories WHERE tenant_id = ? AND slug = ?", c.id, slug).Scan(&id)

	return id
}

func TestThietBi_MayInVaGanPhieu(t *testing.T) {
	h := dungHeThong(t)
	a, b := haiCuaHang(t, h)
	goi := func(tok, method, duong string, body any) traLoi {
		t.Helper()

		return h.goi(t, tok, method, "/api/v1/admin/thiet-bi"+duong, body)
	}

	// KB TB01 — chi nhánh mới: chưa máy nào, nút và mẫu lấy mặc định.
	ds := docTB(t, h, a.token)
	if len(ds.Printers) != 0 || len(ds.Routes) != 0 || !ds.CanEdit || ds.ShopID != a.chiNhanh || len(ds.Slips) != 5 || len(ds.Templates) != 6 {
		t.Fatalf("mặc định sai: %d máy, %d gán, can_edit=%v, shop %d/%d, %d phiếu, %d mẫu",
			len(ds.Printers), len(ds.Routes), ds.CanEdit, ds.ShopID, a.chiNhanh, len(ds.Slips), len(ds.Templates))
	}
	if len(ds.Buttons) != 2 || strings.Join(ds.Buttons[0].Slips, ",") != "hoa-don" {
		t.Fatalf("nút in mặc định sai: %+v", ds.Buttons)
	}

	// KB TB02 — kiểm từng ô máy in.
	r := goi(a.token, http.MethodPost, "/may-in", mayIn("", "lan", "", "80"))
	if r.ma != http.StatusUnprocessableEntity || !strings.Contains(r.than, `"name"`) || !strings.Contains(r.than, `"address"`) {
		t.Fatalf("bỏ trống tên + IP phải 422 cho hai ô, nhận %d %s", r.ma, catBot(r.than))
	}
	for _, sai := range []map[string]any{
		mayIn("Máy A", "lan", "192.168.1.300", "80"),
		mayIn("Máy A", "lan", "abc", "80"),
		mayIn("Máy A", "bt", "12:34", "80"),
		mayIn("Máy A", "fax", "", "80"),
		mayIn("Máy A", "usb", "", "a3"),
		{"name": "Máy A", "conn_type": "usb", "paper": "80", "dpi": 300},
	} {
		if r := goi(a.token, http.MethodPost, "/may-in", sai); r.ma != http.StatusUnprocessableEntity {
			t.Fatalf("máy in sai %v phải 422, nhận %d", sai, r.ma)
		}
	}
	m1 := idTra(t, goi(a.token, http.MethodPost, "/may-in", mayIn("Máy in quầy 1", "lan", "192.168.1.50", "80")))
	if r := goi(a.token, http.MethodPost, "/may-in", mayIn("Trùng IP", "lan", "192.168.1.50", "58")); r.ma != http.StatusUnprocessableEntity || !strings.Contains(r.than, "Máy in quầy 1") {
		t.Fatalf("trùng IP phải 422 kèm tên máy đã dùng, nhận %d %s", r.ma, catBot(r.than))
	}
	// USB gửi kèm địa chỉ: bỏ đi, không lưu rác.
	m2 := idTra(t, goi(a.token, http.MethodPost, "/may-in", mayIn("Máy in tem", "usb", "1.2.3.4", "58")))
	// Bluetooth: MAC viết thường được chuẩn về chữ hoa.
	m3 := idTra(t, goi(a.token, http.MethodPost, "/may-in", mayIn("Máy tem kho", "bt", "dc:0d:30:7a:11:4f", "58")))
	ds = docTB(t, h, a.token)
	if len(ds.Printers) != 3 || ds.Printers[1].Address != "" || ds.Printers[2].Address != "DC:0D:30:7A:11:4F" || !ds.Printers[0].IsActive {
		t.Fatalf("lưu máy in sai: %+v", ds.Printers)
	}
	// Sửa chính máy đó giữ IP cũ: không tính là trùng với chính nó.
	if r := goi(a.token, http.MethodPut, fmt.Sprintf("/may-in/%d", m1), mayIn("Máy in quầy 1 (mới)", "lan", "192.168.1.50", "80")); r.ma != http.StatusOK {
		t.Fatalf("sửa máy giữ IP phải 200, nhận %d %s", r.ma, catBot(r.than))
	}

	// KB TB03 — gán phiếu.
	pk := nhomHang(t, h, a, "Phu kien")
	dh := nhomHang(t, h, a, "Dong ho")
	if ds = docTB(t, h, a.token); len(ds.Groups) < 2 {
		t.Fatalf("phải đọc ra nhóm hàng gốc vừa gieo, nhận %+v", ds.Groups)
	}
	gan := func(phieu string, may uint, tatCa bool, nhom ...uint) traLoi {
		return goi(a.token, http.MethodPost, "/gan-phieu", map[string]any{"slip_type": phieu, "printer_id": may, "all_categories": tatCa, "category_ids": nhom})
	}
	if r := gan("hoa-don", 0, false); r.ma != http.StatusUnprocessableEntity {
		t.Fatalf("thiếu máy in phải 422, nhận %d", r.ma)
	}
	if r := gan("in-order", m1, false); r.ma != http.StatusUnprocessableEntity {
		t.Fatalf("loại phiếu lạ phải 422, nhận %d", r.ma)
	}
	g1 := idTra(t, gan("hoa-don", m1, false))
	if r := gan("hoa-don", m1, false); r.ma != http.StatusUnprocessableEntity || !strings.Contains(r.than, "đã được gán Hoá đơn") {
		t.Fatalf("gán trùng phải 422, nhận %d %s", r.ma, catBot(r.than))
	}
	if r := gan("tem", m2, false); r.ma != http.StatusUnprocessableEntity || !strings.Contains(r.than, "category_ids") {
		t.Fatalf("tem không chọn nhóm phải 422, nhận %d %s", r.ma, catBot(r.than))
	}
	idTra(t, gan("tem", m2, false, pk))
	if r := gan("tem", m2, false, pk, dh); r.ma != http.StatusUnprocessableEntity {
		t.Fatalf("tem trùng nhóm cùng máy phải 422, nhận %d", r.ma)
	}
	if r := gan("tem", m2, true); r.ma != http.StatusUnprocessableEntity {
		t.Fatalf("tem Tất cả chồng lên dòng đã có phải 422, nhận %d", r.ma)
	}
	idTra(t, gan("tem", m2, false, dh))
	// Máy khác thì cùng nhóm được — mỗi máy một dòng tem riêng.
	idTra(t, gan("tem", m3, true))
	// Phiếu không theo nhóm: nhóm gửi kèm bị bỏ.
	g5 := idTra(t, gan("ket-ca", m1, false, pk))
	ds = docTB(t, h, a.token)
	if len(ds.Routes) != 5 || len(ds.Routes[4].CategoryIDs) != 0 || !ds.Routes[3].AllCategories {
		t.Fatalf("lưu gán phiếu sai: %+v", ds.Routes)
	}

	// KB TB04 — xoá máy đang gán: 409 nói rõ phiếu; gỡ hết rồi mới xoá được.
	r = goi(a.token, http.MethodDelete, fmt.Sprintf("/may-in/%d", m1), nil)
	if r.ma != http.StatusConflict || !strings.Contains(r.than, "Hoá đơn") || !strings.Contains(r.than, "Phiếu kết ca") {
		t.Fatalf("xoá máy đang gán phải 409 kèm tên phiếu, nhận %d %s", r.ma, catBot(r.than))
	}
	for _, g := range []uint{g1, g5} {
		if r := goi(a.token, http.MethodDelete, fmt.Sprintf("/gan-phieu/%d", g), nil); r.ma != http.StatusOK {
			t.Fatalf("gỡ dòng gán phải 200, nhận %d", r.ma)
		}
	}
	if r := goi(a.token, http.MethodDelete, fmt.Sprintf("/may-in/%d", m1), nil); r.ma != http.StatusOK {
		t.Fatalf("xoá máy đã gỡ hết phải 200, nhận %d %s", r.ma, catBot(r.than))
	}

	// KB TB05 — bật/tắt; cửa hàng khác không thấy và không chạm được.
	if r := goi(a.token, http.MethodPut, fmt.Sprintf("/may-in/%d/status", m2), map[string]any{"is_active": false}); r.ma != http.StatusOK {
		t.Fatalf("tắt máy phải 200, nhận %d", r.ma)
	}
	if r := goi(a.token, http.MethodPut, fmt.Sprintf("/may-in/%d/status", m2), map[string]any{}); r.ma != http.StatusUnprocessableEntity && r.ma != http.StatusBadRequest {
		t.Fatalf("thiếu is_active phải bị từ chối, nhận %d", r.ma)
	}
	if ds = docTB(t, h, a.token); ds.Printers[0].IsActive {
		t.Fatalf("máy vừa tắt vẫn đang bật")
	}
	if dsB := docTB(t, h, b.token); len(dsB.Printers) != 0 || len(dsB.Routes) != 0 {
		t.Fatalf("cửa hàng B thấy máy của A: %+v", dsB.Printers)
	}
	if r := goi(b.token, http.MethodPut, fmt.Sprintf("/may-in/%d", m2), mayIn("X", "usb", "", "80")); r.ma != http.StatusNotFound {
		t.Fatalf("B sửa máy của A phải 404, nhận %d", r.ma)
	}
	if r := goi(b.token, http.MethodDelete, fmt.Sprintf("/may-in/%d", m3), nil); r.ma != http.StatusNotFound {
		t.Fatalf("B xoá máy của A phải 404, nhận %d", r.ma)
	}
	// B gán phiếu vào máy của A: máy không thuộc chi nhánh mình.
	if r := goi(b.token, http.MethodPost, "/gan-phieu", map[string]any{"slip_type": "hoa-don", "printer_id": m3}); r.ma != http.StatusUnprocessableEntity {
		t.Fatalf("B gán vào máy của A phải 422, nhận %d", r.ma)
	}
}

func TestThietBi_NutInVaMauIn(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	goi := func(tok, method, duong string, body any) traLoi {
		t.Helper()

		return h.goi(t, tok, method, "/api/v1/admin/thiet-bi"+duong, body)
	}

	// KB TB06 — nút in: chỉ phiếu nút đó cho phép.
	if r := goi(a.token, http.MethodPut, "/nut-in/tam-tinh", map[string]any{"slips": []string{"hoa-don", "hddt"}}); r.ma != http.StatusUnprocessableEntity {
		t.Fatalf("tạm tính in HĐĐT phải 422, nhận %d", r.ma)
	}
	if r := goi(a.token, http.MethodPut, "/nut-in/bao-bep", map[string]any{"slips": []string{}}); r.ma != http.StatusNotFound {
		t.Fatalf("nút lạ phải 404, nhận %d", r.ma)
	}
	tat := false
	if r := goi(a.token, http.MethodPut, "/nut-in/thanh-toan", map[string]any{"slips": []string{"hoa-don", "tem", "hoa-don"}}); r.ma != http.StatusOK {
		t.Fatalf("lưu nút Thanh toán phải 200, nhận %d %s", r.ma, catBot(r.than))
	}
	goi(a.token, http.MethodPut, "/nut-in/tam-tinh", map[string]any{"slips": []string{}, "is_active": &tat})
	ds := docTB(t, h, a.token)
	if strings.Join(ds.Buttons[0].Slips, ",") != "hoa-don,tem" || ds.Buttons[1].IsActive || len(ds.Buttons[1].Slips) != 0 {
		t.Fatalf("nút in lưu sai: %+v", ds.Buttons)
	}

	// KB TB07 — mẫu in: lưu, gộp mặc định, kiểm ô, về mặc định.
	if m := ds.mau("hd"); m.Options["qr"] || !m.Options["logo"] || m.Settings.Kho != "80" || m.Settings.Ban != 1 || m.DaChinh {
		t.Fatalf("mặc định hoá đơn sai: %+v", m)
	}
	for _, sai := range []map[string]any{
		{"options": map[string]bool{"bep": true}},
		{"settings": map[string]any{"ban": 100}},
		{"settings": map[string]any{"kho": "57"}},
		{"settings": map[string]any{"footer_txt": strings.Repeat("a", 121)}},
	} {
		if r := goi(a.token, http.MethodPut, "/mau-in/hd", sai); r.ma != http.StatusUnprocessableEntity {
			t.Fatalf("mẫu sai %v phải 422, nhận %d %s", sai, r.ma, catBot(r.than))
		}
	}
	if r := goi(a.token, http.MethodPut, "/mau-in/tem", map[string]any{"settings": map[string]any{"cot": 4}}); r.ma != http.StatusUnprocessableEntity {
		t.Fatalf("tem 4 cột phải 422, nhận %d", r.ma)
	}
	if r := goi(a.token, http.MethodPut, "/mau-in/tem", map[string]any{"settings": map[string]any{"footer_txt": strings.Repeat("b", 51)}}); r.ma != http.StatusUnprocessableEntity {
		t.Fatalf("chân tem quá 50 ký tự phải 422, nhận %d", r.ma)
	}
	if r := goi(a.token, http.MethodPut, "/mau-in/bep", map[string]any{}); r.ma != http.StatusNotFound {
		t.Fatalf("mẫu lạ phải 404, nhận %d", r.ma)
	}
	// Chỉ gửi hai dòng: dòng còn lại giữ mặc định, không bị tắt theo.
	r := goi(a.token, http.MethodPut, "/mau-in/hd", map[string]any{
		"options":  map[string]bool{"qr": true, "khach": false},
		"settings": map[string]any{"kho": "58", "ban": 2, "footer_txt": "  Hẹn gặp lại  ", "kho_tem": "50x70"},
	})
	if r.ma != http.StatusOK {
		t.Fatalf("lưu mẫu hoá đơn phải 200, nhận %d %s", r.ma, catBot(r.than))
	}
	ds = docTB(t, h, a.token)
	m := ds.mau("hd")
	if !m.Options["qr"] || m.Options["khach"] || !m.Options["logo"] || m.Settings.Kho != "58" || m.Settings.Ban != 2 ||
		m.Settings.FooterTxt != "Hẹn gặp lại" || m.Settings.KhoTem != "" || !m.DaChinh {
		t.Fatalf("mẫu hoá đơn đọc lại sai: %+v", m)
	}
	if ds.mau("a4").DaChinh || ds.mau("tem").DaChinh {
		t.Fatalf("lưu hoá đơn không được chạm mẫu khác")
	}

	// KB TB08 — quầy đọc mẫu của chi nhánh; nhân viên không vào được màn cấu hình.
	nv := dangNhapNhanVien(t, h, a.ma)
	r = h.goi(t, nv, http.MethodGet, "/api/v1/admin/orders/pos/mau-in/hd", nil)
	if r.ma != http.StatusOK || !strings.Contains(r.than, `"kho":"58"`) || !strings.Contains(r.than, `"qr":true`) {
		t.Fatalf("quầy phải đọc được mẫu đã lưu, nhận %d %s", r.ma, catBot(r.than))
	}
	if r := h.goi(t, nv, http.MethodGet, "/api/v1/admin/orders/pos/mau-in/bep", nil); r.ma != http.StatusNotFound {
		t.Fatalf("mẫu lạ ở quầy phải 404, nhận %d", r.ma)
	}
	if r := h.goi(t, nv, http.MethodGet, "/api/v1/admin/thiet-bi", nil); r.ma != http.StatusForbidden {
		t.Fatalf("nhân viên mở cấu hình thiết bị phải 403, nhận %d", r.ma)
	}
	if r := h.goi(t, nv, http.MethodPut, "/api/v1/admin/thiet-bi/mau-in/hd", map[string]any{}); r.ma != http.StatusForbidden {
		t.Fatalf("nhân viên sửa mẫu phải 403, nhận %d", r.ma)
	}

	// Về mặc định = xoá dòng đã lưu.
	if r := goi(a.token, http.MethodDelete, "/mau-in/hd", nil); r.ma != http.StatusOK || !strings.Contains(r.than, `"da_chinh":false`) {
		t.Fatalf("về mặc định phải 200 và hết đánh dấu đã chỉnh, nhận %d %s", r.ma, catBot(r.than))
	}
	if m := docTB(t, h, a.token).mau("hd"); m.Options["qr"] || m.Settings.Kho != "80" || m.DaChinh {
		t.Fatalf("sau khi về mặc định vẫn còn bản cũ: %+v", m)
	}
}

// KB TB09 — nhiều chi nhánh mà chưa chọn: nhắc chọn; chọn rồi thì mỗi chi nhánh một bộ.
func TestThietBi_TheoChiNhanh(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	cn2 := moChiNhanhThuHai(t, h, a, "Quay hai")
	r := h.goi(t, a.token, http.MethodGet, "/api/v1/admin/thiet-bi", nil)
	if r.ma != http.StatusConflict || !strings.Contains(r.than, "bộ thiết bị riêng") {
		t.Fatalf("chưa chọn chi nhánh phải 409 kèm câu nhắc, nhận %d %s", r.ma, catBot(r.than))
	}
	r = h.goiChiNhanh(t, a.token, cn2, http.MethodPost, "/api/v1/admin/thiet-bi/may-in", mayIn("Máy quầy hai", "usb", "", "80"))
	if r.ma != http.StatusCreated {
		t.Fatalf("thêm máy ở chi nhánh hai phải 201, nhận %d %s", r.ma, catBot(r.than))
	}
	r = h.goiChiNhanh(t, a.token, a.chiNhanh, http.MethodGet, "/api/v1/admin/thiet-bi", nil)
	if r.ma != http.StatusOK || strings.Contains(r.than, "Máy quầy hai") {
		t.Fatalf("chi nhánh một không được thấy máy của chi nhánh hai, nhận %d %s", r.ma, catBot(r.than))
	}
	// Quầy đang ở chi nhánh hai đọc mẫu của chi nhánh hai, không phải của chi nhánh một.
	h.goiChiNhanh(t, a.token, a.chiNhanh, http.MethodPut, "/api/v1/admin/thiet-bi/mau-in/hd", map[string]any{"settings": map[string]any{"kho": "58"}})
	r = h.goiChiNhanh(t, a.token, cn2, http.MethodGet, "/api/v1/admin/orders/pos/mau-in/hd", nil)
	if r.ma != http.StatusOK || !strings.Contains(r.than, `"kho":"80"`) {
		t.Fatalf("chi nhánh hai phải đọc mẫu mặc định của mình, nhận %d %s", r.ma, catBot(r.than))
	}
}
