package apitest

import (
	"encoding/json"
	"fmt"
	"net/http"
	"strings"
	"testing"
)

// KB PI01 — các dòng mới của mẫu Hoá đơn: mặc định, ô chữ wifi / chính sách
// (kiểm độ dài, chỉ mẫu có dòng mới nhận), quầy đọc lại đúng bản đã lưu.
func TestPhieuIn_DongMoiCuaMauHoaDon(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	goi := func(method, duong string, body any) traLoi {
		t.Helper()

		return h.goi(t, a.token, method, "/api/v1/admin/thiet-bi"+duong, body)
	}

	m := docTB(t, h, a.token).mau("hd")
	if !m.Options["mavach"] || !m.Options["tietkiem"] || !m.Options["diemconlai"] || m.Options["wifi"] || m.Options["chinhsach"] {
		t.Fatalf("mặc định: mã vạch, tiết kiệm, điểm còn lại bật; wifi, chính sách tắt — đang %+v", m.Options)
	}

	for _, sai := range []map[string]any{
		{"settings": map[string]any{"wifi_txt": strings.Repeat("w", 81)}},
		{"settings": map[string]any{"chinhsach_txt": strings.Repeat("c", 201)}},
	} {
		if r := goi(http.MethodPut, "/mau-in/hd", sai); r.ma != http.StatusUnprocessableEntity {
			t.Fatalf("%v phải 422, nhận %d %s", sai, r.ma, catBot(r.than))
		}
	}

	r := goi(http.MethodPut, "/mau-in/hd", map[string]any{
		"options":  map[string]bool{"wifi": true, "chinhsach": true, "mavach": false},
		"settings": map[string]any{"wifi_txt": "  Shop · MK 123  ", "chinhsach_txt": "Đổi trong 7 ngày"},
	})
	if r.ma != http.StatusOK {
		t.Fatalf("lưu mẫu phải 200, nhận %d %s", r.ma, catBot(r.than))
	}
	nv := dangNhapNhanVien(t, h, a.ma)
	q := h.goi(t, nv, http.MethodGet, "/api/v1/admin/orders/pos/mau-in/hd", nil)
	for _, can := range []string{`"wifi_txt":"Shop · MK 123"`, `"chinhsach_txt":"Đổi trong 7 ngày"`, `"mavach":false`, `"wifi":true`} {
		if !strings.Contains(q.than, can) {
			t.Fatalf("quầy đọc mẫu thiếu %s: %s", can, catBot(q.than))
		}
	}

	// Mẫu tem không có dòng wifi: gửi lên cũng không được giữ.
	if r := goi(http.MethodPut, "/mau-in/tem", map[string]any{"settings": map[string]any{"wifi_txt": "x"}}); r.ma != http.StatusOK || strings.Contains(r.than, "wifi_txt") {
		t.Fatalf("tem không được giữ wifi_txt, nhận %d %s", r.ma, catBot(r.than))
	}
}

// KB PI02 — dòng đơn chụp tên ĐVT; chi tiết đơn có hạng + điểm hiện có của
// khách; thu ngân đọc được phiếu trả hàng để in mà không vào được /returns.
func TestPhieuIn_DonViTinhThanhVienVaPhieuTra(t *testing.T) {
	h := dungHeThong(t)
	a, b := haiCuaHang(t, h)
	moCa(t, h, a, 0)

	ten := "Cai " + a.vet
	dv := taoDonVi(t, h, a, ten)
	if res := h.goi(t, a.token, http.MethodPut, fmt.Sprintf("/api/v1/admin/products/%d", a.sanPham), map[string]any{
		"category_id": a.danhMuc, "name": "Hang hoa " + a.vet, "slug": a.slug, "unit_id": dv,
	}); res.ma != http.StatusOK {
		t.Fatalf("gắn ĐVT cho mặt hàng trả %d\n%s", res.ma, catBot(res.than))
	}

	ban := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/orders/pos", map[string]any{
		"payment_method": "cash", "user_id": a.khach,
		"items": []map[string]any{{"product_variant_id": a.bienThe, "quantity": 2}},
	})
	if ban.ma != http.StatusCreated {
		t.Fatalf("bán ở quầy trả %d\n%s", ban.ma, catBot(ban.than))
	}
	donID := uint(doc(t, ban)["order_id"].(float64))

	var don struct {
		Data struct {
			Items []struct {
				UnitName string `json:"unit_name"`
			} `json:"items"`
			ThanhVien *struct {
				Hang string `json:"hang"`
				Diem uint   `json:"diem"`
			} `json:"thanh_vien"`
		} `json:"data"`
	}
	r := h.goi(t, a.token, http.MethodGet, fmt.Sprintf("/api/v1/admin/orders/%d", donID), nil)
	_ = json.Unmarshal([]byte(r.than), &don)
	if len(don.Data.Items) != 1 || don.Data.Items[0].UnitName != ten {
		t.Fatalf("dòng đơn phải chụp ĐVT %q, đang %+v", ten, don.Data.Items)
	}
	if don.Data.ThanhVien == nil || don.Data.ThanhVien.Diem != docKhachDiem(t, h, a).Points {
		t.Fatalf("chi tiết đơn phải có điểm hiện có của khách, đang %+v", don.Data.ThanhVien)
	}

	tra := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/returns", map[string]any{
		"order_id": donID, "reason": "other", "refund_method": "cash",
		"items": []map[string]any{{"order_item_id": dongHangDauTien(t, h, a, donID), "quantity": 1}},
	})
	if tra.ma != http.StatusCreated {
		t.Fatalf("lập phiếu trả trả %d\n%s", tra.ma, catBot(tra.than))
	}
	phieu := doc(t, tra)
	duong := fmt.Sprintf("/orders/pos/tra-hang/%d", uint(phieu["id"].(float64)))

	nv := dangNhapNhanVien(t, h, a.ma)
	if r := h.goi(t, nv, http.MethodGet, "/api/v1/admin"+duong, nil); r.ma != http.StatusOK || !strings.Contains(r.than, phieu["return_code"].(string)) {
		t.Fatalf("thu ngân đọc phiếu trả để in phải 200, nhận %d %s", r.ma, catBot(r.than))
	}
	if r := h.goi(t, nv, http.MethodGet, fmt.Sprintf("/api/v1/admin/returns/%d", uint(phieu["id"].(float64))), nil); r.ma != http.StatusForbidden {
		t.Fatalf("thu ngân vào /returns vẫn phải 403, nhận %d", r.ma)
	}
	if r := h.goi(t, b.token, http.MethodGet, "/api/v1/admin"+duong, nil); r.ma != http.StatusNotFound {
		t.Fatalf("cửa hàng khác đọc phiếu trả phải 404, nhận %d", r.ma)
	}
}
