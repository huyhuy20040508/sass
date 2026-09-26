package apitest

import (
	"encoding/json"
	"fmt"
	"net/http"
	"testing"
)

// Bài kiểm LOẠI THU CHI qua API thật và MySQL thật.
//
// Bốn chỗ bản cũ v2 làm hỏng, không chỗ nào lộ ra ở tầng service với sổ giả:
// hai cửa hàng có nhìn thấy danh sách của nhau không (bản cũ tắt hẳn bộ lọc chi
// nhánh), đổi tên trùng qua đường SỬA có lọt không (bản cũ chỉ kiểm lúc thêm),
// loại hệ thống có sửa/xoá được không, và có ai chuyển được một loại từ vế thu
// sang vế chi không.

// loaiTC là một dòng trên bảng Loại thu chi.
type loaiTC struct {
	ID        uint   `json:"id"`
	Type      uint8  `json:"type"`
	Name      string `json:"name"`
	IsDefault bool   `json:"is_default"`
}

// themLoai gọi đường tạo và trả về mã HTTP kèm dòng vừa tạo.
func themLoai(t *testing.T, h *heThong, token string, loai uint8, ten string) (int, loaiTC) {
	t.Helper()

	res := h.goi(t, token, http.MethodPost, "/api/v1/admin/loai-thu-chi", map[string]any{
		"type": loai,
		"name": ten,
	})

	var body struct {
		Data loaiTC `json:"data"`
	}
	_ = json.Unmarshal([]byte(res.than), &body)

	return res.ma, body.Data
}

// docLoai đọc danh sách của một cửa hàng. query là phần sau dấu ? (có thể rỗng).
func docLoai(t *testing.T, h *heThong, token, query string) []loaiTC {
	t.Helper()

	duong := "/api/v1/admin/loai-thu-chi"
	if query != "" {
		duong += "?" + query
	}

	res := h.goi(t, token, http.MethodGet, duong, nil)
	if res.ma != http.StatusOK {
		t.Fatalf("đọc danh sách loại thu chi phải trả 200, nhận %d\n%s", res.ma, catBot(res.than))
	}

	var body struct {
		Data []loaiTC `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &body); err != nil {
		t.Fatalf("không đọc được phản hồi: %v\n%s", err, catBot(res.than))
	}

	return body.Data
}

// TestLoaiThuChi_TaoRoiDocLai — dòng mới không mang cờ hệ thống, và một lượt đọc
// trả về CẢ hai vế vì màn quản trị dựng hai bảng cạnh nhau.
func TestLoaiThuChi_TaoRoiDocLai(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	ma, l := themLoai(t, h, a.token, 0, "Thu tiền bán hàng")
	if ma != http.StatusCreated {
		t.Fatalf("tạo loại thu phải trả 201, nhận %d", ma)
	}
	if l.IsDefault {
		t.Fatal("loại người dùng tự khai không được mang cờ hệ thống — mang thì chính họ hết sửa được")
	}

	if ma, _ := themLoai(t, h, a.token, 1, "Tiền điện nước"); ma != http.StatusCreated {
		t.Fatalf("tạo loại chi phải trả 201, nhận %d", ma)
	}

	if ds := docLoai(t, h, a.token, ""); len(ds) != 2 {
		t.Fatalf("một lượt đọc phải trả về cả hai vế, nhận %+v", ds)
	}

	ds := docLoai(t, h, a.token, "type=1")
	if len(ds) != 1 || ds[0].Name != "Tiền điện nước" {
		t.Fatalf("lọc type=1 chỉ được trả về vế chi, nhận %+v", ds)
	}
}

// TestLoaiThuChi_TrungTenXetTheoTungVe — "Tiền điện" bên thu và bên chi là hai
// loại khác nhau, nhưng trùng trong CÙNG một vế thì chặn.
func TestLoaiThuChi_TrungTenXetTheoTungVe(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	if ma, _ := themLoai(t, h, a.token, 1, "Tiền điện"); ma != http.StatusCreated {
		t.Fatalf("lượt tạo đầu phải trả 201, nhận %d", ma)
	}

	if ma, _ := themLoai(t, h, a.token, 0, "Tiền điện"); ma != http.StatusCreated {
		t.Fatalf("cùng tên nhưng KHÁC vế thu/chi phải tạo được, nhận %d", ma)
	}

	if ma, _ := themLoai(t, h, a.token, 1, "tiền điện"); ma != http.StatusUnprocessableEntity {
		t.Fatalf("trùng tên trong cùng vế (khác hoa thường) phải bị chặn bằng 422, nhận %d", ma)
	}

	// Có dấu khác không dấu là hai loại: "Thuê" và "Thue" đọc ra hai thứ khác
	// nhau, mà đối chiếu mặc định của MySQL lại coi chúng là một.
	if ma, _ := themLoai(t, h, a.token, 1, "Tien dien"); ma != http.StatusCreated {
		t.Fatalf("tên khác dấu phải là loại khác, nhận %d", ma)
	}
}

// TestLoaiThuChi_SuaCungChanTrungTen — chỗ bản cũ để lọt: nó chỉ validate lúc
// store, nên đổi tên một loại thành tên của loại khác thì bảng có hai dòng y hệt.
func TestLoaiThuChi_SuaCungChanTrungTen(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	themLoai(t, h, a.token, 1, "Tiền điện")
	_, hai := themLoai(t, h, a.token, 1, "Tiền nước")

	duong := fmt.Sprintf("/api/v1/admin/loai-thu-chi/%d", hai.ID)

	res := h.goi(t, a.token, http.MethodPut, duong, map[string]any{"name": "Tiền điện"})
	if res.ma != http.StatusUnprocessableEntity {
		t.Fatalf("đổi tên thành tên đã có phải bị chặn bằng 422, nhận %d\n%s", res.ma, catBot(res.than))
	}

	// Đổi tên thành CHÍNH tên đang mang thì không phải lỗi: người dùng sửa hoa
	// thường hay chỉ mở hộp ra rồi bấm Lưu.
	if res := h.goi(t, a.token, http.MethodPut, duong, map[string]any{"name": "Tiền nước"}); res.ma != http.StatusOK {
		t.Fatalf("giữ nguyên tên cũ phải lưu được, nhận %d\n%s", res.ma, catBot(res.than))
	}
}

// TestLoaiThuChi_KhongDoiDuocVeThuChi — đường sửa cố ý không nhận `type`.
//
// Đổi vế của một loại đang có phiếu trỏ vào là mọi phiếu thu cũ nhảy sang cột
// chi trong các bảng cộng dồn — một lượt sửa tên vô hại kéo theo số liệu sai.
func TestLoaiThuChi_KhongDoiDuocVeThuChi(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	_, l := themLoai(t, h, a.token, 0, "Thu tiền bán hàng")

	res := h.goi(t, a.token, http.MethodPut,
		fmt.Sprintf("/api/v1/admin/loai-thu-chi/%d", l.ID),
		map[string]any{"name": "Thu tiền bán hàng", "type": 1})
	if res.ma != http.StatusOK {
		t.Fatalf("lượt sửa phải trả 200, nhận %d\n%s", res.ma, catBot(res.than))
	}

	ds := docLoai(t, h, a.token, "")
	if len(ds) != 1 {
		t.Fatalf("phải còn đúng một loại, nhận %+v", ds)
	}
	if ds[0].Type != 0 {
		t.Fatalf("gửi kèm type qua đường sửa đã đổi được vế thu/chi: %+v", ds[0])
	}
}

// TestLoaiThuChi_ChanTypeLa — `type` lạ bị TỪ CHỐI chứ không lặng lẽ thành 0.
func TestLoaiThuChi_ChanTypeLa(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	if ma, _ := themLoai(t, h, a.token, 9, "Loại lạ"); ma != http.StatusUnprocessableEntity {
		t.Fatalf("type=9 phải bị chặn bằng 422, nhận %d", ma)
	}

	// Thiếu hẳn `type` cũng bị chặn, không được lặng lẽ hiểu thành loại thu.
	res := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/loai-thu-chi",
		map[string]any{"name": "Thiếu vế"})
	if res.ma != http.StatusUnprocessableEntity {
		t.Fatalf("thiếu type phải bị chặn bằng 422, nhận %d\n%s", res.ma, catBot(res.than))
	}

	// Còn lọc bằng type lạ thì không được trả về CẢ HAI vế: người gọi xin riêng
	// vế chi mà nhận cả thu là cộng nhầm.
	if res := h.goi(t, a.token, http.MethodGet, "/api/v1/admin/loai-thu-chi?type=9", nil); res.ma != http.StatusUnprocessableEntity {
		t.Fatalf("lọc type=9 phải trả 422, nhận %d", res.ma)
	}
}

// TestLoaiThuChi_XoaRoiKhaiLaiDungTenCu — bảng cố ý không có khoá duy nhất dưới
// database vì lý do này: xoá "Tiền điện" rồi khai lại đúng tên ấy là việc bình
// thường nhất, mà khoá trần thì tên của dòng đã xoá vẫn giữ chỗ.
func TestLoaiThuChi_XoaRoiKhaiLaiDungTenCu(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	_, l := themLoai(t, h, a.token, 1, "Tiền điện")

	res := h.goi(t, a.token, http.MethodDelete, fmt.Sprintf("/api/v1/admin/loai-thu-chi/%d", l.ID), nil)
	if res.ma != http.StatusOK {
		t.Fatalf("xoá phải trả 200, nhận %d\n%s", res.ma, catBot(res.than))
	}

	if ds := docLoai(t, h, a.token, ""); len(ds) != 0 {
		t.Fatalf("loại đã xoá không được hiện lại trong danh sách, nhận %+v", ds)
	}

	if ma, _ := themLoai(t, h, a.token, 1, "Tiền điện"); ma != http.StatusCreated {
		t.Fatalf("khai lại đúng tên vừa xoá phải được, nhận %d", ma)
	}
}

// TestLoaiThuChi_LoaiHeThongKhongSuaKhongXoa — phiếu tự sinh (bán hàng, trả
// hàng…) trỏ vào loại mang cờ này và đọc tên từ đây.
func TestLoaiThuChi_LoaiHeThongKhongSuaKhongXoa(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	_, l := themLoai(t, h, a.token, 0, "Thu tiền bán hàng")

	// Cờ hệ thống không có đường API nào bật — cố ý, người dùng không tự phong
	// cho loại của mình được. Đặt thẳng dưới database, đúng như phiếu tự sinh làm.
	if err := h.db.WithContext(ctxThoat()).
		Exec("UPDATE income_expense_types SET is_default = 1 WHERE id = ?", l.ID).Error; err != nil {
		t.Fatalf("không đặt được cờ hệ thống: %v", err)
	}

	duong := fmt.Sprintf("/api/v1/admin/loai-thu-chi/%d", l.ID)

	if res := h.goi(t, a.token, http.MethodPut, duong, map[string]any{"name": "Tên mới"}); res.ma != http.StatusConflict {
		t.Fatalf("sửa loại hệ thống phải trả 409, nhận %d\n%s", res.ma, catBot(res.than))
	}
	if res := h.goi(t, a.token, http.MethodDelete, duong, nil); res.ma != http.StatusConflict {
		t.Fatalf("xoá loại hệ thống phải trả 409, nhận %d", res.ma)
	}

	ds := docLoai(t, h, a.token, "")
	if len(ds) != 1 || ds[0].Name != "Thu tiền bán hàng" || !ds[0].IsDefault {
		t.Fatalf("loại hệ thống phải còn nguyên: %+v", ds)
	}
}

// TestLoaiThuChi_KhongNhinThayVaKhongSuaDuocCuaCuaHangKhac — chỗ bản cũ hỏng
// nặng nhất: `scopeBranch` của nó trả thẳng query ra (dòng lọc bị comment), nên
// ghi thì đóng dấu chi nhánh mà đọc thì thấy của tất cả.
func TestLoaiThuChi_KhongNhinThayVaKhongSuaDuocCuaCuaHangKhac(t *testing.T) {
	h := dungHeThong(t)
	a, b := haiCuaHang(t, h)

	_, cuaB := themLoai(t, h, b.token, 1, "Tiền thuê mặt bằng")

	if ds := docLoai(t, h, a.token, ""); len(ds) != 0 {
		t.Fatalf("cửa hàng A không được thấy loại của B, nhận %+v", ds)
	}

	// Và A vẫn khai được đúng cái tên B đang dùng: trùng tên chỉ xét trong một
	// cửa hàng, khác bản cũ ràng buộc trên cả bảng dùng chung.
	if ma, _ := themLoai(t, h, a.token, 1, "Tiền thuê mặt bằng"); ma != http.StatusCreated {
		t.Fatalf("cửa hàng A phải khai được tên mà B đang dùng, nhận %d", ma)
	}

	duong := fmt.Sprintf("/api/v1/admin/loai-thu-chi/%d", cuaB.ID)

	if res := h.goi(t, a.token, http.MethodGet, duong, nil); res.ma != http.StatusNotFound {
		t.Fatalf("đọc loại của cửa hàng khác phải trả 404, nhận %d", res.ma)
	}
	if res := h.goi(t, a.token, http.MethodPut, duong, map[string]any{"name": "Bị sửa trộm"}); res.ma != http.StatusNotFound {
		t.Fatalf("sửa loại của cửa hàng khác phải trả 404, nhận %d", res.ma)
	}
	if res := h.goi(t, a.token, http.MethodDelete, duong, nil); res.ma != http.StatusNotFound {
		t.Fatalf("xoá loại của cửa hàng khác phải trả 404, nhận %d", res.ma)
	}

	ds := docLoai(t, h, b.token, "")
	if len(ds) != 1 || ds[0].Name != "Tiền thuê mặt bằng" {
		t.Fatalf("loại của cửa hàng B phải còn nguyên, nhận %+v", ds)
	}
}
