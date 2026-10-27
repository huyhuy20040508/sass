package apitest

import (
	"encoding/json"
	"fmt"
	"net/http"
	"strings"
	"testing"
	"time"
)

// Sổ ghi chú khách ở quầy (KB #23): thêm / đọc / xoá của mình, loại "hẹn" có ngày
// vào nút lọc "Có hẹn hôm nay".

type ghiChuKB struct {
	ID       uint   `json:"id"`
	Loai     string `json:"loai"`
	NoiDung  string `json:"noi_dung"`
	NgayHen  string `json:"ngay_hen"`
	NguoiGhi string `json:"nguoi_ghi"`
}

func docGhiChu(t *testing.T, r traLoi) []ghiChuKB {
	t.Helper()
	if r.ma != http.StatusOK {
		t.Fatalf("đọc ghi chú phải 200, nhận %d %s", r.ma, catBot(r.than))
	}
	var body struct {
		Data []ghiChuKB `json:"data"`
	}
	if err := json.Unmarshal([]byte(r.than), &body); err != nil {
		t.Fatalf("không đọc được ghi chú: %v", err)
	}
	return body.Data
}

// KB GC01–GC06
func TestCRMQuay_SoGhiChuKhach(t *testing.T) {
	h := dungHeThong(t)
	a, b := haiCuaHang(t, h)
	thuNgan := h.dangNhapVoi(t, a.ma, "nhanvien")
	so := func(id uint) string { return fmt.Sprintf("/api/v1/admin/orders/pos/khach-hang/%d/ghi-chu", id) }
	homNay := time.Now().Format("2006-01-02")
	maiKia := time.Now().AddDate(0, 0, 1).Format("2006-01-02")
	them := func(tok string, id uint, body map[string]any) traLoi {
		return h.goi(t, tok, http.MethodPost, so(id), body)
	}

	// GC01 thêm ghi chú chăm sóc (loại mặc định): biết ai ghi; mới trước.
	r := them(thuNgan, a.khach, map[string]any{"noi_dung": "  Thích màu xanh, hay mua ốp lưng  "})
	if r.ma != http.StatusCreated {
		t.Fatalf("GC01 thêm ghi chú phải 201, nhận %d %s", r.ma, catBot(r.than))
	}
	moi := doc(t, r)
	if moi["nguoi_ghi"] == "" || moi["ten_chi_nhanh"] == "" {
		t.Fatalf("GC01 dòng vừa thêm phải mang người ghi + chi nhánh để màn chèn thẳng: %v", moi)
	}
	cuaThuNgan := uint(moi["id"].(float64))
	if r := them(thuNgan, a.khach, map[string]any{"loai": "canh-bao", "noi_dung": "Khách hay trả hàng"}); r.ma != http.StatusCreated {
		t.Fatalf("GC01 thêm cảnh báo trả %d", r.ma)
	}
	ds := docGhiChu(t, h.goi(t, thuNgan, http.MethodGet, so(a.khach), nil))
	if len(ds) != 2 || ds[0].Loai != "canh-bao" || ds[1].Loai != "cham-soc" || ds[1].NoiDung != "Thích màu xanh, hay mua ốp lưng" || ds[1].NguoiGhi == "" {
		t.Fatalf("GC01 sổ phải có 2 dòng mới trước, đã cắt khoảng trắng, có người ghi: %+v", ds)
	}

	// GC02 hẹn hôm nay vào nút lọc; hẹn mai và ghi chú thường thì không.
	khac := doc(t, h.goi(t, thuNgan, http.MethodPost, "/api/v1/admin/orders/pos/khach-hang/day-du",
		map[string]any{"full_name": "Khách hẹn mai " + a.vet, "phone": "0909 444 111", "status": "active", "customer_type": 0}))
	idKhac := uint(khac["id"].(float64))
	if r := them(thuNgan, idKhac, map[string]any{"loai": "hen", "noi_dung": "Hẹn lấy hàng", "ngay_hen": maiKia}); r.ma != http.StatusCreated {
		t.Fatalf("GC02 hẹn mai trả %d %s", r.ma, catBot(r.than))
	}
	henNay := them(thuNgan, a.khach, map[string]any{"loai": "hen", "noi_dung": "Hẹn 17h qua lấy áo đã sửa", "ngay_hen": homNay})
	if henNay.ma != http.StatusCreated || doc(t, henNay)["ngay_hen"] != homNay {
		t.Fatalf("GC02 hẹn hôm nay trả %d %s", henNay.ma, catBot(henNay.than))
	}
	idHen := uint(doc(t, henNay)["id"].(float64))
	locHen := func(duong string) []uint {
		r := h.goi(t, thuNgan, http.MethodGet, duong, nil)
		var body struct {
			Data []struct {
				ID uint `json:"id"`
			} `json:"data"`
		}
		_ = json.Unmarshal([]byte(r.than), &body)
		out := []uint{}
		for _, x := range body.Data {
			out = append(out, x.ID)
		}
		return out
	}
	if ids := locHen("/api/v1/admin/orders/pos/khach-hang?hen_hom_nay=1"); len(ids) != 1 || ids[0] != a.khach {
		t.Fatalf("GC02 lọc 'Có hẹn hôm nay' chỉ ra khách %d, nhận %v", a.khach, ids)
	}

	// GC03 kiểm ô.
	for _, ca := range []struct {
		body map[string]any
		o    string
	}{
		{map[string]any{"noi_dung": "   "}, "noi_dung"},
		{map[string]any{"noi_dung": strings.Repeat("ồ", 501)}, "noi_dung"},
		{map[string]any{"loai": "khac", "noi_dung": "x"}, "loai"},
		{map[string]any{"loai": "hen", "noi_dung": "x"}, "ngay_hen"},
		{map[string]any{"loai": "hen", "noi_dung": "x", "ngay_hen": time.Now().AddDate(0, 0, -1).Format("2006-01-02")}, "ngay_hen"},
	} {
		r := them(thuNgan, a.khach, ca.body)
		if r.ma != http.StatusUnprocessableEntity || !strings.Contains(r.than, `"`+ca.o+`"`) {
			t.Fatalf("GC03 %v phải 422 ở ô %s, nhận %d %s", ca.body, ca.o, r.ma, catBot(r.than))
		}
	}
	if r := them(thuNgan, a.khach, map[string]any{"noi_dung": strings.Repeat("ồ", 500)}); r.ma != http.StatusCreated {
		t.Fatalf("GC03 đúng 500 ký tự (có dấu) phải lưu được, nhận %d", r.ma)
	}

	// GC04 xoá: người khác không xoá được; người ghi xoá được; hẹn đã xoá rời nút lọc.
	if r := h.goi(t, a.token, http.MethodDelete, fmt.Sprintf("%s/%d", so(a.khach), cuaThuNgan), nil); r.ma != http.StatusForbidden {
		t.Fatalf("GC04 xoá ghi chú người khác phải 403, nhận %d", r.ma)
	}
	if r := h.goi(t, thuNgan, http.MethodDelete, fmt.Sprintf("%s/%d", so(idKhac), idHen), nil); r.ma != http.StatusNotFound {
		t.Fatalf("GC04 xoá ghi chú qua id khách khác phải 404, nhận %d", r.ma)
	}
	if r := h.goi(t, thuNgan, http.MethodDelete, fmt.Sprintf("%s/%d", so(a.khach), idHen), nil); r.ma != http.StatusOK {
		t.Fatalf("GC04 người ghi xoá phải 200, nhận %d %s", r.ma, catBot(r.than))
	}
	for _, g := range docGhiChu(t, h.goi(t, thuNgan, http.MethodGet, so(a.khach), nil)) {
		if g.ID == idHen {
			t.Fatalf("GC04 ghi chú đã xoá vẫn hiện")
		}
	}
	if ids := locHen("/api/v1/admin/orders/pos/khach-hang?hen_hom_nay=1"); len(ids) != 0 {
		t.Fatalf("GC04 hẹn đã xoá phải rời nút lọc, nhận %v", ids)
	}

	// GC05 cửa hàng khác: không đọc, không ghi được khách của a; id không phải khách → 404.
	if r := h.goi(t, b.token, http.MethodGet, so(a.khach), nil); r.ma != http.StatusNotFound {
		t.Fatalf("GC05 cửa hàng khác đọc phải 404, nhận %d", r.ma)
	}
	if r := them(b.token, a.khach, map[string]any{"noi_dung": "x"}); r.ma != http.StatusNotFound {
		t.Fatalf("GC05 cửa hàng khác ghi phải 404, nhận %d", r.ma)
	}
	if r := h.goi(t, thuNgan, http.MethodGet, so(999999999), nil); r.ma != http.StatusNotFound {
		t.Fatalf("GC05 khách không có phải 404, nhận %d", r.ma)
	}

	// GC06 ô ghi chú cố định của hồ sơ không bị sổ đụng tới.
	if kh := doc(t, h.goi(t, thuNgan, http.MethodGet, fmt.Sprintf("/api/v1/admin/orders/pos/khach-hang/%d", idKhac), nil)); kh["customer_note"] != "" && kh["customer_note"] != nil {
		t.Fatalf("GC06 customer_note phải giữ nguyên, nhận %v", kh["customer_note"])
	}
}
