package apitest

import (
	"encoding/json"
	"fmt"
	"net/http"
	"testing"
)

// Bài kiểm VỊ TRÍ qua API thật và MySQL thật.
//
// Vị trí là bảng tra mới hoàn toàn (bản cũ v2 không có màn này), nhưng nó dùng
// chung khuôn với Đơn vị tính nên phải chịu đúng những ràng buộc đã đặt ở đó —
// và đó đều là thứ chỉ lộ ra khi chạy trên MySQL thật: mã duy nhất theo TỪNG
// cửa hàng, tên trùng bị chặn kể cả khác hoa thường (nhưng khác DẤU thì không),
// công tắc trạng thái không ghi lẫn sang tên, và hai cửa hàng không nhìn thấy
// vị trí của nhau.

// viTri là một dòng trên bảng Vị trí.
type viTri struct {
	ID       uint   `json:"id"`
	Code     string `json:"code"`
	Name     string `json:"name"`
	IsActive bool   `json:"is_active"`
}

// themViTri gọi đường tạo và trả về mã HTTP kèm dòng vừa tạo.
func themViTri(t *testing.T, h *heThong, token, code, name string) (int, viTri) {
	t.Helper()

	res := h.goi(t, token, http.MethodPost, "/api/v1/admin/vi-tri", map[string]any{
		"code": code,
		"name": name,
	})

	var body struct {
		Data viTri `json:"data"`
	}
	_ = json.Unmarshal([]byte(res.than), &body)

	return res.ma, body.Data
}

// docViTri đọc danh sách vị trí của một cửa hàng. query là phần sau dấu ? (có
// thể rỗng).
func docViTri(t *testing.T, h *heThong, token, query string) []viTri {
	t.Helper()

	duong := "/api/v1/admin/vi-tri"
	if query != "" {
		duong += "?" + query
	}

	res := h.goi(t, token, http.MethodGet, duong, nil)
	if res.ma != http.StatusOK {
		t.Fatalf("đọc danh sách vị trí phải trả 200, nhận %d\n%s", res.ma, catBot(res.than))
	}

	var body struct {
		Data []viTri `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &body); err != nil {
		t.Fatalf("không đọc được phản hồi: %v\n%s", err, catBot(res.than))
	}

	return body.Data
}

// TestViTri_TaoRoiDocLai — mã tự viết hoa và vị trí mới mặc định đang bật.
func TestViTri_TaoRoiDocLai(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	ma, vt := themViTri(t, h, a.token, "kea1", "Kệ A - Tầng 1")
	if ma != http.StatusCreated {
		t.Fatalf("tạo vị trí phải trả 201, nhận %d", ma)
	}
	if vt.Code != "KEA1" {
		t.Fatalf("mã phải được viết hoa thành KEA1, nhận %q", vt.Code)
	}
	if !vt.IsActive {
		t.Fatal("vị trí mới phải đang bật — không thì vừa khai xong đã không chọn được")
	}

	ds := docViTri(t, h, a.token, "")
	if len(ds) != 1 || ds[0].Name != "Kệ A - Tầng 1" {
		t.Fatalf("danh sách phải có đúng vị trí vừa tạo, nhận %+v", ds)
	}
}

// TestViTri_ChanTrungMaVaTrungTen — hai lỗi tách nhau vì người đọc phải sửa hai
// ô khác nhau. Khác DẤU thì là hai vị trí khác, dù MySQL mặc định coi là một.
func TestViTri_ChanTrungMaVaTrungTen(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	if ma, _ := themViTri(t, h, a.token, "KHOLANH", "Kho lạnh"); ma != http.StatusCreated {
		t.Fatalf("lượt tạo đầu phải trả 201, nhận %d", ma)
	}

	if ma, _ := themViTri(t, h, a.token, "kholanh", "Kho lạnh số 2"); ma != http.StatusUnprocessableEntity {
		t.Fatalf("mã trùng (khác hoa thường) phải bị chặn bằng 422, nhận %d", ma)
	}

	if ma, _ := themViTri(t, h, a.token, "KHOLANH2", "kho lạnh"); ma != http.StatusUnprocessableEntity {
		t.Fatalf("tên trùng (khác hoa thường) phải bị chặn bằng 422, nhận %d", ma)
	}

	if ma, _ := themViTri(t, h, a.token, "KHOLANH3", "Kho lanh"); ma != http.StatusCreated {
		t.Fatalf("tên khác dấu phải là vị trí khác, nhận %d", ma)
	}
}

// TestViTri_MaVanBiGiuChoSauKhiXoa — xoá mềm nên mã cũ còn nằm trong UNIQUE
// index; báo trùng ở tầng Go vẫn hơn để MySQL ném lỗi thô lên màn hình.
func TestViTri_MaVanBiGiuChoSauKhiXoa(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	_, vt := themViTri(t, h, a.token, "QUAY1", "Quầy trước")

	res := h.goi(t, a.token, http.MethodDelete, fmt.Sprintf("/api/v1/admin/vi-tri/%d", vt.ID), nil)
	if res.ma != http.StatusOK {
		t.Fatalf("xoá phải trả 200, nhận %d\n%s", res.ma, catBot(res.than))
	}

	if ds := docViTri(t, h, a.token, ""); len(ds) != 0 {
		t.Fatalf("vị trí đã xoá không được hiện lại trong danh sách, nhận %+v", ds)
	}

	if ma, _ := themViTri(t, h, a.token, "QUAY1", "Quầy trước cửa"); ma != http.StatusUnprocessableEntity {
		t.Fatalf("mã của vị trí đã xoá vẫn giữ chỗ, phải trả 422, nhận %d", ma)
	}
}

// TestViTri_MaChiDuyNhatTrongMotCuaHang — "KEA1" là chỗ để hàng của riêng một
// mặt bằng; tiệm này đặt rồi không được cản tiệm khác đặt cùng chuỗi ấy.
func TestViTri_MaChiDuyNhatTrongMotCuaHang(t *testing.T) {
	h := dungHeThong(t)
	a, b := haiCuaHang(t, h)

	if ma, _ := themViTri(t, h, a.token, "KEA1", "Kệ A - Tầng 1"); ma != http.StatusCreated {
		t.Fatalf("cửa hàng A tạo KEA1 phải trả 201, nhận %d", ma)
	}
	if ma, _ := themViTri(t, h, b.token, "KEA1", "Kệ A - Tầng 1"); ma != http.StatusCreated {
		t.Fatalf("cửa hàng B cũng phải đặt được mã KEA1, nhận %d", ma)
	}
}

// TestViTri_CongTacKhongGhiLanSangTen — công tắc trên bảng gửi ĐÚNG một trường;
// tên và mã gửi kèm phải bị bỏ qua.
func TestViTri_CongTacKhongGhiLanSangTen(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	_, vt := themViTri(t, h, a.token, "KEA1", "Kệ A - Tầng 1")

	res := h.goi(t, a.token, http.MethodPut,
		fmt.Sprintf("/api/v1/admin/vi-tri/%d/trang-thai", vt.ID),
		map[string]any{"is_active": false, "name": "Tên gửi lén", "code": "LEN"})
	if res.ma != http.StatusOK {
		t.Fatalf("đổi trạng thái phải trả 200, nhận %d\n%s", res.ma, catBot(res.than))
	}

	ds := docViTri(t, h, a.token, "")
	if len(ds) != 1 {
		t.Fatalf("phải còn đúng một vị trí, nhận %+v", ds)
	}
	if ds[0].IsActive {
		t.Fatal("công tắc tắt rồi mà vị trí vẫn đang bật")
	}
	if ds[0].Name != "Kệ A - Tầng 1" || ds[0].Code != "KEA1" {
		t.Fatalf("lượt gạt công tắc đã ghi lẫn sang tên/mã: %+v", ds[0])
	}
}

// TestViTri_ChiLayDangBatChoODangChon — `active=true` là tham số của ô chọn vị
// trí lúc khai mặt hàng: tắt một vị trí thì nó phải biến khỏi ô đó, nhưng vẫn
// còn trong bảng quản lý.
func TestViTri_ChiLayDangBatChoODangChon(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	themViTri(t, h, a.token, "KEA1", "Kệ A - Tầng 1")
	_, tat := themViTri(t, h, a.token, "KHOLANH", "Kho lạnh")

	res := h.goi(t, a.token, http.MethodPut,
		fmt.Sprintf("/api/v1/admin/vi-tri/%d/trang-thai", tat.ID),
		map[string]any{"is_active": false})
	if res.ma != http.StatusOK {
		t.Fatalf("đổi trạng thái phải trả 200, nhận %d", res.ma)
	}

	if ds := docViTri(t, h, a.token, ""); len(ds) != 2 {
		t.Fatalf("bảng quản lý phải thấy cả vị trí đã tắt, nhận %d dòng", len(ds))
	}

	ds := docViTri(t, h, a.token, "active=true")
	if len(ds) != 1 || ds[0].Code != "KEA1" {
		t.Fatalf("ô chọn chỉ được thấy vị trí đang bật, nhận %+v", ds)
	}
}

// TestViTri_KhongNhinThayVaKhongSuaDuocCuaCuaHangKhac — bộ lọc tenant nằm ở
// tầng dưới GORM, không phải mỗi truy vấn tự nhớ. Ba lượt thọc ở đây là cách
// duy nhất chứng minh nó thật sự chắn.
func TestViTri_KhongNhinThayVaKhongSuaDuocCuaCuaHangKhac(t *testing.T) {
	h := dungHeThong(t)
	a, b := haiCuaHang(t, h)

	_, cuaB := themViTri(t, h, b.token, "KHOLANH", "Kho lạnh")

	if ds := docViTri(t, h, a.token, ""); len(ds) != 0 {
		t.Fatalf("cửa hàng A không được thấy vị trí của B, nhận %+v", ds)
	}

	duong := fmt.Sprintf("/api/v1/admin/vi-tri/%d", cuaB.ID)

	if res := h.goi(t, a.token, http.MethodGet, duong, nil); res.ma != http.StatusNotFound {
		t.Fatalf("đọc vị trí của cửa hàng khác phải trả 404, nhận %d", res.ma)
	}

	res := h.goi(t, a.token, http.MethodPut, duong, map[string]any{"code": "KHOLANH", "name": "Bị sửa trộm"})
	if res.ma != http.StatusNotFound {
		t.Fatalf("sửa vị trí của cửa hàng khác phải trả 404, nhận %d\n%s", res.ma, catBot(res.than))
	}

	if res := h.goi(t, a.token, http.MethodDelete, duong, nil); res.ma != http.StatusNotFound {
		t.Fatalf("xoá vị trí của cửa hàng khác phải trả 404, nhận %d", res.ma)
	}

	// Và dòng của B vẫn nguyên vẹn sau ba lượt thọc trên.
	ds := docViTri(t, h, b.token, "")
	if len(ds) != 1 || ds[0].Name != "Kho lạnh" {
		t.Fatalf("vị trí của cửa hàng B phải còn nguyên, nhận %+v", ds)
	}
}

// TestViTri_ChanMaCoKhoangTrangVaKyTuLa — GÕ TAY thì chỉ nhận chữ không dấu và
// số. (Bỏ trống là chuyện khác: đó là "để phần mềm đặt hộ", xem
// TestViTri_BoTrongMaThiTuDat.)
func TestViTri_ChanMaCoKhoangTrangVaKyTuLa(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	for _, ma := range []string{"KE A1", "KE-A1", "Kệ"} {
		if code, _ := themViTri(t, h, a.token, ma, "Thử "+ma); code != http.StatusUnprocessableEntity {
			t.Fatalf("mã %q phải bị chặn bằng 422, nhận %d", ma, code)
		}
	}
}

// TestViTri_BoTrongMaThiTuDat — cửa hàng chưa bật quy tắc đánh số riêng thì mã
// rơi về dải VT001, và dãy đếm tiếp chứ không đụng mã người dùng tự gõ.
func TestViTri_BoTrongMaThiTuDat(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	ma, dau := themViTri(t, h, a.token, "", "Kệ A - Tầng 1")
	if ma != http.StatusCreated {
		t.Fatalf("bỏ trống mã phải tạo được, nhận %d", ma)
	}
	if dau.Code != "VT001" {
		t.Fatalf("mã đầu tiên phải là VT001, nhận %q", dau.Code)
	}

	// Mã gõ tay xen vào giữa KHÔNG được làm hỏng dãy: nó không đúng khuôn VT+số
	// nên không tham gia lượt đếm.
	if ma, _ := themViTri(t, h, a.token, "KHOLANH", "Kho lạnh"); ma != http.StatusCreated {
		t.Fatalf("mã gõ tay phải tạo được, nhận %d", ma)
	}

	_, sau := themViTri(t, h, a.token, "", "Quầy trước")
	if sau.Code != "VT002" {
		t.Fatalf("mã kế tiếp phải là VT002, nhận %q", sau.Code)
	}
}

// TestViTri_MaTuDatTheoQuyTacCuaCuaHang — bật quy tắc ở Cài đặt → Thông số
// chung thì mã sinh ra theo đúng hình dạng cửa hàng khai, không phải VT001.
func TestViTri_MaTuDatTheoQuyTacCuaCuaHang(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	res := h.goi(t, a.token, http.MethodPut, "/api/v1/admin/quy-tac-ma", map[string]any{
		"shop_id": a.chiNhanh,
		"quy_tac": []map[string]any{
			{"doc_type": "vi-tri", "prefix": "KE", "value_part": "so-thu-tu", "length": 4, "suffix": ""},
		},
	})
	if res.ma != http.StatusOK {
		t.Fatalf("lưu quy tắc phải trả 200, nhận %d\n%s", res.ma, catBot(res.than))
	}

	_, vt := themViTri(t, h, a.token, "", "Kệ A - Tầng 1")
	if vt.Code != "KE0001" {
		t.Fatalf("mã phải theo quy tắc của cửa hàng (KE0001), nhận %q", vt.Code)
	}
}

// TestViTri_SuaBoTrongMaThiGiuNguyen — mã vị trí có thể đã dán lên kệ; lượt sửa
// tên mà tự đổi luôn mã là hai bên lệch nhau.
func TestViTri_SuaBoTrongMaThiGiuNguyen(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	_, vt := themViTri(t, h, a.token, "KEA1", "Kệ A - Tầng 1")

	res := h.goi(t, a.token, http.MethodPut,
		fmt.Sprintf("/api/v1/admin/vi-tri/%d", vt.ID),
		map[string]any{"name": "Kệ A - Tầng trệt"})
	if res.ma != http.StatusOK {
		t.Fatalf("sửa phải trả 200, nhận %d\n%s", res.ma, catBot(res.than))
	}

	ds := docViTri(t, h, a.token, "")
	if len(ds) != 1 || ds[0].Code != "KEA1" || ds[0].Name != "Kệ A - Tầng trệt" {
		t.Fatalf("sửa tên mà bỏ trống mã phải giữ nguyên mã cũ, nhận %+v", ds)
	}
}
