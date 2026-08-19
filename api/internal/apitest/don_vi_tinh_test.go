package apitest

import (
	"encoding/json"
	"fmt"
	"net/http"
	"testing"
)

// Bài kiểm ĐƠN VỊ TÍNH qua API thật và MySQL thật.
//
// Bốn chỗ bản cũ v2 làm hỏng, và không chỗ nào lộ ra ở tầng service với sổ giả:
// mã có duy nhất theo TỪNG cửa hàng không (bản cũ ràng buộc trên cả bảng dùng
// chung), tên trùng có bị chặn không, công tắc trạng thái có ghi lẫn sang tên
// không (bản cũ `fill($request->all())` nên có), và hai cửa hàng có nhìn thấy
// hay sửa được đơn vị của nhau không (bản cũ tắt hẳn bộ lọc chi nhánh).

// donVi là một dòng trên bảng Đơn vị tính.
type donVi struct {
	ID       uint   `json:"id"`
	Code     string `json:"code"`
	Name     string `json:"name"`
	IsActive bool   `json:"is_active"`
}

// themDonVi gọi đường tạo và trả về mã HTTP kèm dòng vừa tạo.
func themDonVi(t *testing.T, h *heThong, token, code, name string) (int, donVi) {
	t.Helper()

	res := h.goi(t, token, http.MethodPost, "/api/v1/admin/don-vi-tinh", map[string]any{
		"code": code,
		"name": name,
	})

	var body struct {
		Data donVi `json:"data"`
	}
	_ = json.Unmarshal([]byte(res.than), &body)

	return res.ma, body.Data
}

// docDonVi đọc danh sách đơn vị của một cửa hàng. query là phần sau dấu ? (có
// thể rỗng).
func docDonVi(t *testing.T, h *heThong, token, query string) []donVi {
	t.Helper()

	duong := "/api/v1/admin/don-vi-tinh"
	if query != "" {
		duong += "?" + query
	}

	res := h.goi(t, token, http.MethodGet, duong, nil)
	if res.ma != http.StatusOK {
		t.Fatalf("đọc danh sách đơn vị phải trả 200, nhận %d\n%s", res.ma, catBot(res.than))
	}

	var body struct {
		Data []donVi `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &body); err != nil {
		t.Fatalf("không đọc được phản hồi: %v\n%s", err, catBot(res.than))
	}

	return body.Data
}

// TestDonViTinh_TaoRoiDocLai — mã tự viết hoa và đơn vị mới mặc định đang bật.
func TestDonViTinh_TaoRoiDocLai(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	ma, dv := themDonVi(t, h, a.token, "kg", "Kilogam")
	if ma != http.StatusCreated {
		t.Fatalf("tạo đơn vị phải trả 201, nhận %d", ma)
	}
	if dv.Code != "KG" {
		t.Fatalf("mã phải được viết hoa thành KG, nhận %q", dv.Code)
	}
	if !dv.IsActive {
		t.Fatal("đơn vị mới phải đang bật — không thì vừa khai xong đã không chọn được")
	}

	ds := docDonVi(t, h, a.token, "")
	if len(ds) != 1 || ds[0].Name != "Kilogam" {
		t.Fatalf("danh sách phải có đúng đơn vị vừa tạo, nhận %+v", ds)
	}
}

// TestDonViTinh_ChanTrungMaVaTrungTen — hai lỗi tách nhau vì người đọc phải sửa
// hai ô khác nhau.
func TestDonViTinh_ChanTrungMaVaTrungTen(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	if ma, _ := themDonVi(t, h, a.token, "THUNG", "Thùng"); ma != http.StatusCreated {
		t.Fatalf("lượt tạo đầu phải trả 201, nhận %d", ma)
	}

	if ma, _ := themDonVi(t, h, a.token, "thung", "Thùng carton"); ma != http.StatusUnprocessableEntity {
		t.Fatalf("mã trùng (khác hoa thường) phải bị chặn bằng 422, nhận %d", ma)
	}

	if ma, _ := themDonVi(t, h, a.token, "THUNG2", "thùng"); ma != http.StatusUnprocessableEntity {
		t.Fatalf("tên trùng (khác hoa thường) phải bị chặn bằng 422, nhận %d", ma)
	}

	// Có dấu khác không dấu là HAI đơn vị: "Thùng" và "Thung" đọc ra hai thứ
	// khác nhau, mà đối chiếu mặc định của MySQL lại coi chúng là một.
	if ma, _ := themDonVi(t, h, a.token, "THUNG3", "Thung"); ma != http.StatusCreated {
		t.Fatalf("tên khác dấu phải là đơn vị khác, nhận %d", ma)
	}
}

// TestDonViTinh_MaVanBiGiuChoSauKhiXoa — xoá mềm nên mã cũ còn nằm trong UNIQUE
// index; báo trùng ở tầng Go vẫn hơn để MySQL ném lỗi thô lên màn hình.
func TestDonViTinh_MaVanBiGiuChoSauKhiXoa(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	_, dv := themDonVi(t, h, a.token, "GOI", "Gói")

	res := h.goi(t, a.token, http.MethodDelete, fmt.Sprintf("/api/v1/admin/don-vi-tinh/%d", dv.ID), nil)
	if res.ma != http.StatusOK {
		t.Fatalf("xoá phải trả 200, nhận %d\n%s", res.ma, catBot(res.than))
	}

	if ds := docDonVi(t, h, a.token, ""); len(ds) != 0 {
		t.Fatalf("đơn vị đã xoá không được hiện lại trong danh sách, nhận %+v", ds)
	}

	if ma, _ := themDonVi(t, h, a.token, "GOI", "Gói nhỏ"); ma != http.StatusUnprocessableEntity {
		t.Fatalf("mã của đơn vị đã xoá vẫn giữ chỗ, phải trả 422, nhận %d", ma)
	}
}

// TestDonViTinh_MaChiDuyNhatTrongMotCuaHang — chỗ bản cũ hỏng nặng nhất: nó
// ràng buộc duy nhất trên cả bảng dùng chung, nên tiệm này đặt "KG" rồi là mọi
// tiệm khác hết đặt.
func TestDonViTinh_MaChiDuyNhatTrongMotCuaHang(t *testing.T) {
	h := dungHeThong(t)
	a, b := haiCuaHang(t, h)

	if ma, _ := themDonVi(t, h, a.token, "KG", "Kilogam"); ma != http.StatusCreated {
		t.Fatalf("cửa hàng A tạo KG phải trả 201, nhận %d", ma)
	}
	if ma, _ := themDonVi(t, h, b.token, "KG", "Kilogam"); ma != http.StatusCreated {
		t.Fatalf("cửa hàng B cũng phải đặt được mã KG, nhận %d", ma)
	}
}

// TestDonViTinh_CongTacKhongGhiLanSangTen — công tắc gửi ĐÚNG một trường.
//
// Bản cũ gọi `fill($request->all())` ở đường trạng thái, nên gửi kèm `name` là
// đổi luôn tên qua chính lượt gạt công tắc ấy.
func TestDonViTinh_CongTacKhongGhiLanSangTen(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	_, dv := themDonVi(t, h, a.token, "CAI", "Cái")

	res := h.goi(t, a.token, http.MethodPut,
		fmt.Sprintf("/api/v1/admin/don-vi-tinh/%d/trang-thai", dv.ID),
		map[string]any{"is_active": false, "name": "Tên gửi lén", "code": "LEN"})
	if res.ma != http.StatusOK {
		t.Fatalf("đổi trạng thái phải trả 200, nhận %d\n%s", res.ma, catBot(res.than))
	}

	ds := docDonVi(t, h, a.token, "")
	if len(ds) != 1 {
		t.Fatalf("phải còn đúng một đơn vị, nhận %+v", ds)
	}
	if ds[0].IsActive {
		t.Fatal("công tắc tắt rồi mà đơn vị vẫn đang bật")
	}
	if ds[0].Name != "Cái" || ds[0].Code != "CAI" {
		t.Fatalf("lượt gạt công tắc đã ghi lẫn sang tên/mã: %+v", ds[0])
	}
}

// TestDonViTinh_ChiLayDangBatChoODangChon — `active=true` là tham số của ô chọn
// đơn vị lúc khai mặt hàng: tắt một đơn vị thì nó phải biến khỏi ô đó, nhưng
// vẫn còn trong bảng quản lý.
func TestDonViTinh_ChiLayDangBatChoODangChon(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	themDonVi(t, h, a.token, "CAI", "Cái")
	_, tat := themDonVi(t, h, a.token, "HOP", "Hộp")

	res := h.goi(t, a.token, http.MethodPut,
		fmt.Sprintf("/api/v1/admin/don-vi-tinh/%d/trang-thai", tat.ID),
		map[string]any{"is_active": false})
	if res.ma != http.StatusOK {
		t.Fatalf("đổi trạng thái phải trả 200, nhận %d", res.ma)
	}

	if ds := docDonVi(t, h, a.token, ""); len(ds) != 2 {
		t.Fatalf("bảng quản lý phải thấy cả đơn vị đã tắt, nhận %d dòng", len(ds))
	}

	ds := docDonVi(t, h, a.token, "active=true")
	if len(ds) != 1 || ds[0].Code != "CAI" {
		t.Fatalf("ô chọn chỉ được thấy đơn vị đang bật, nhận %+v", ds)
	}
}

// TestDonViTinh_KhongNhinThayVaKhongSuaDuocCuaCuaHangKhac — bản cũ để
// `scopeBranch` trả thẳng query ra (dòng lọc bị comment), nên ghi thì đóng dấu
// chi nhánh mà đọc thì thấy của tất cả.
func TestDonViTinh_KhongNhinThayVaKhongSuaDuocCuaCuaHangKhac(t *testing.T) {
	h := dungHeThong(t)
	a, b := haiCuaHang(t, h)

	_, cuaB := themDonVi(t, h, b.token, "CHAI", "Chai")

	if ds := docDonVi(t, h, a.token, ""); len(ds) != 0 {
		t.Fatalf("cửa hàng A không được thấy đơn vị của B, nhận %+v", ds)
	}

	duong := fmt.Sprintf("/api/v1/admin/don-vi-tinh/%d", cuaB.ID)

	if res := h.goi(t, a.token, http.MethodGet, duong, nil); res.ma != http.StatusNotFound {
		t.Fatalf("đọc đơn vị của cửa hàng khác phải trả 404, nhận %d", res.ma)
	}

	res := h.goi(t, a.token, http.MethodPut, duong, map[string]any{"code": "CHAI", "name": "Bị sửa trộm"})
	if res.ma != http.StatusNotFound {
		t.Fatalf("sửa đơn vị của cửa hàng khác phải trả 404, nhận %d\n%s", res.ma, catBot(res.than))
	}

	if res := h.goi(t, a.token, http.MethodDelete, duong, nil); res.ma != http.StatusNotFound {
		t.Fatalf("xoá đơn vị của cửa hàng khác phải trả 404, nhận %d", res.ma)
	}

	// Và dòng của B vẫn nguyên vẹn sau ba lượt thọc trên.
	ds := docDonVi(t, h, b.token, "")
	if len(ds) != 1 || ds[0].Name != "Chai" {
		t.Fatalf("đơn vị của cửa hàng B phải còn nguyên, nhận %+v", ds)
	}
}

// TestDonViTinh_ChanMaCoKhoangTrangVaKyTuLa — GÕ TAY thì mã in lên tem và đọc
// lẫn với số lượng, nên chỉ nhận chữ không dấu và số. (Bỏ trống là chuyện khác:
// đó là "để phần mềm đặt hộ", xem TestDonViTinh_BoTrongMaThiTuDat.)
func TestDonViTinh_ChanMaCoKhoangTrangVaKyTuLa(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	for _, ma := range []string{"KG 2", "KG-2", "Kí"} {
		if code, _ := themDonVi(t, h, a.token, ma, "Thử "+ma); code != http.StatusUnprocessableEntity {
			t.Fatalf("mã %q phải bị chặn bằng 422, nhận %d", ma, code)
		}
	}
}

// TestDonViTinh_BoTrongMaThiTuDat — cửa hàng chưa bật quy tắc đánh số riêng thì
// mã rơi về dải DV001, và dãy đếm tiếp chứ không đụng mã người dùng tự gõ.
func TestDonViTinh_BoTrongMaThiTuDat(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	ma, dau := themDonVi(t, h, a.token, "", "Cái")
	if ma != http.StatusCreated {
		t.Fatalf("bỏ trống mã phải tạo được, nhận %d", ma)
	}
	if dau.Code != "DV001" {
		t.Fatalf("mã đầu tiên phải là DV001, nhận %q", dau.Code)
	}

	// Mã gõ tay xen vào giữa KHÔNG được làm hỏng dãy: nó không đúng khuôn DV+số
	// nên không tham gia lượt đếm.
	if ma, _ := themDonVi(t, h, a.token, "THUNG", "Thùng"); ma != http.StatusCreated {
		t.Fatalf("mã gõ tay phải tạo được, nhận %d", ma)
	}

	_, sau := themDonVi(t, h, a.token, "", "Hộp")
	if sau.Code != "DV002" {
		t.Fatalf("mã kế tiếp phải là DV002, nhận %q", sau.Code)
	}
}

// TestDonViTinh_MaTuDatTheoQuyTacCuaCuaHang — bật quy tắc ở Cài đặt → Thông số
// chung thì mã sinh ra theo đúng hình dạng cửa hàng khai, không phải DV001.
func TestDonViTinh_MaTuDatTheoQuyTacCuaCuaHang(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	res := h.goi(t, a.token, http.MethodPut, "/api/v1/admin/quy-tac-ma", map[string]any{
		"shop_id": a.chiNhanh,
		"quy_tac": []map[string]any{
			{"doc_type": "don-vi-tinh", "prefix": "DVT", "value_part": "so-thu-tu", "length": 4, "suffix": ""},
		},
	})
	if res.ma != http.StatusOK {
		t.Fatalf("lưu quy tắc phải trả 200, nhận %d\n%s", res.ma, catBot(res.than))
	}

	_, dv := themDonVi(t, h, a.token, "", "Cái")
	if dv.Code != "DVT0001" {
		t.Fatalf("mã phải theo quy tắc của cửa hàng (DVT0001), nhận %q", dv.Code)
	}
}

// TestDonViTinh_SuaBoTrongMaThiGiuNguyen — đơn vị đã gắn vào mặt hàng và in lên
// tem; lượt sửa tên mà tự đổi luôn mã là hai bên lệch nhau.
func TestDonViTinh_SuaBoTrongMaThiGiuNguyen(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	_, dv := themDonVi(t, h, a.token, "KG", "Kilogam")

	res := h.goi(t, a.token, http.MethodPut,
		fmt.Sprintf("/api/v1/admin/don-vi-tinh/%d", dv.ID),
		map[string]any{"name": "Ki lô gam"})
	if res.ma != http.StatusOK {
		t.Fatalf("sửa phải trả 200, nhận %d\n%s", res.ma, catBot(res.than))
	}

	ds := docDonVi(t, h, a.token, "")
	if len(ds) != 1 || ds[0].Code != "KG" || ds[0].Name != "Ki lô gam" {
		t.Fatalf("sửa tên mà bỏ trống mã phải giữ nguyên mã cũ, nhận %+v", ds)
	}
}
