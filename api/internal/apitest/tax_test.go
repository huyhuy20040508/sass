package apitest

import (
	"encoding/json"
	"fmt"
	"net/http"
	"testing"
)

// Bài kiểm THUẾ SUẤT qua API thật và MySQL thật.
//
// Bốn chỗ dễ hỏng của bảng này, và không chỗ nào lộ ra ở tầng service với sổ
// giả: cửa hàng mới có tự có đủ bốn dòng không, mức lạ có bị chặn không, mức
// -1/-2 có sống sót qua một vòng lưu–đọc không, và hai cửa hàng có nhìn thấy
// bảng thuế của nhau không.

// dongThue là một dòng trên bảng Thuế.
type dongThue struct {
	ID       uint     `json:"id"`
	Loai     string   `json:"loai"`
	Ten      string   `json:"ten"`
	Muc      []int    `json:"muc"`
	MucNhan  []string `json:"muc_nhan"`
	ChonDuoc []struct {
		GiaTri int    `json:"gia_tri"`
		Nhan   string `json:"nhan"`
	} `json:"chon_duoc"`
	IsActive bool `json:"is_active"`
}

// docThue đọc bảng thuế của một cửa hàng.
func docThue(t *testing.T, h *heThong, token string) []dongThue {
	t.Helper()

	res := h.goi(t, token, http.MethodGet, "/api/v1/admin/thue", nil)
	if res.ma != http.StatusOK {
		t.Fatalf("đọc bảng thuế phải trả 200, nhận %d\n%s", res.ma, catBot(res.than))
	}

	var body struct {
		Data []dongThue `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &body); err != nil {
		t.Fatalf("không đọc được phản hồi: %v\n%s", err, catBot(res.than))
	}

	return body.Data
}

// timThue lấy một dòng theo mã loại.
func timThue(t *testing.T, ds []dongThue, loai string) dongThue {
	t.Helper()

	for _, d := range ds {
		if d.Loai == loai {
			return d
		}
	}
	t.Fatalf("bảng thuế thiếu loại %q", loai)

	return dongThue{}
}

// TestThue_CuaHangMoiCoDuBonLoai — mỗi cửa hàng là một tenant riêng nên không
// seed sẵn từ migration được; lượt mở màn hình đầu tiên phải tự dựng đủ.
func TestThue_CuaHangMoiCoDuBonLoai(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	ds := docThue(t, h, a.token)
	if len(ds) != 4 {
		t.Fatalf("cửa hàng mới phải có đủ 4 loại thuế, nhận %d", len(ds))
	}

	for _, loai := range []string{"mac-dinh", "tieu-thu-dac-biet", "mua-hang", "ban-hang"} {
		d := timThue(t, ds, loai)
		if len(d.Muc) == 0 {
			t.Fatalf("loại %q dựng ra với bộ mức rỗng — ô chọn thuế sẽ trống trơn", loai)
		}
		if len(d.ChonDuoc) == 0 {
			t.Fatalf("loại %q không có bộ mức cho chọn — màn hình không sửa được gì", loai)
		}
		if !d.IsActive {
			t.Fatalf("loại %q dựng ra ở trạng thái tắt", loai)
		}
	}

	// Gọi lần thứ hai không được đẻ thêm dòng.
	if lai := docThue(t, h, a.token); len(lai) != 4 {
		t.Fatalf("lượt đọc thứ hai phải vẫn là 4 dòng, nhận %d", len(lai))
	}
}

// TestThue_ChanMucNgoaiBoChon — bộ mức của từng loại là bộ đóng. Gửi 45% vào
// "thuế trên đơn mua hàng" phải bị từ chối, không phải lặng lẽ ghi vào.
func TestThue_ChanMucNgoaiBoChon(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	muaHang := timThue(t, docThue(t, h, a.token), "mua-hang")

	res := h.goi(t, a.token, http.MethodPut, fmt.Sprintf("/api/v1/admin/thue/%d", muaHang.ID), map[string]any{
		"muc": []int{0, 45},
	})
	if res.ma != http.StatusUnprocessableEntity {
		t.Fatalf("mức ngoài bộ chọn phải trả 422, nhận %d\n%s", res.ma, catBot(res.than))
	}

	// Và bảng phải còn nguyên như trước.
	sau := timThue(t, docThue(t, h, a.token), "mua-hang")
	if len(sau.Muc) != len(muaHang.Muc) {
		t.Fatalf("lượt lưu bị từ chối vẫn làm đổi dữ liệu: trước %v, sau %v", muaHang.Muc, sau.Muc)
	}
}

// TestThue_BoHetMucBiChan — ô chọn rỗng thì màn nghiệp vụ không còn gì để bày.
// Muốn thôi áp thuế thì tắt cả dòng, đó mới là câu nói rõ ý.
func TestThue_BoHetMucBiChan(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	banHang := timThue(t, docThue(t, h, a.token), "ban-hang")

	res := h.goi(t, a.token, http.MethodPut, fmt.Sprintf("/api/v1/admin/thue/%d", banHang.ID), map[string]any{
		"muc": []int{},
	})
	if res.ma != http.StatusUnprocessableEntity {
		t.Fatalf("bỏ hết mức phải trả 422, nhận %d\n%s", res.ma, catBot(res.than))
	}
}

// TestThue_GiuNguyenKCTvaKKKNT — -1 và -2 không phải phần trăm, và chúng chính
// là mã hoá đơn điện tử nhận. Một vòng lưu–đọc mà quy chúng về 0 là mất phân
// biệt giữa "thuế suất 0%" và "không chịu thuế".
func TestThue_GiuNguyenKCTvaKKKNT(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	banHang := timThue(t, docThue(t, h, a.token), "ban-hang")

	res := h.goi(t, a.token, http.MethodPut, fmt.Sprintf("/api/v1/admin/thue/%d", banHang.ID), map[string]any{
		"muc": []int{10, -1, -2},
	})
	if res.ma != http.StatusOK {
		t.Fatalf("lưu mức KCT/KKKNT phải trả 200, nhận %d\n%s", res.ma, catBot(res.than))
	}

	sau := timThue(t, docThue(t, h, a.token), "ban-hang")

	coKCT, coKKKNT := false, false
	for _, m := range sau.Muc {
		if m == -1 {
			coKCT = true
		}
		if m == -2 {
			coKKKNT = true
		}
	}
	if !coKCT || !coKKKNT {
		t.Fatalf("KCT/KKKNT không sống sót qua vòng lưu–đọc: %v", sau.Muc)
	}

	// Nhãn dựng ở API để mỗi màn hình khỏi tự dịch một kiểu.
	nhan := map[string]bool{}
	for _, n := range sau.MucNhan {
		nhan[n] = true
	}
	if !nhan["KCT"] || !nhan["KKKNT"] {
		t.Fatalf("nhãn hiển thị sai: %v", sau.MucNhan)
	}
}

// TestThue_CongTacTatKhongXoaBoMuc — tắt là bỏ tick, không phải xoá. Bật lại
// phải thấy nguyên bộ mức cũ.
func TestThue_CongTacTatKhongXoaBoMuc(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	truoc := timThue(t, docThue(t, h, a.token), "mua-hang")

	res := h.goi(t, a.token, http.MethodPut,
		fmt.Sprintf("/api/v1/admin/thue/%d/trang-thai", truoc.ID), map[string]any{"is_active": false})
	if res.ma != http.StatusOK {
		t.Fatalf("tắt loại thuế phải trả 200, nhận %d\n%s", res.ma, catBot(res.than))
	}

	giua := timThue(t, docThue(t, h, a.token), "mua-hang")
	if giua.IsActive {
		t.Fatal("bấm tắt xong vẫn đang bật")
	}
	if len(giua.Muc) != len(truoc.Muc) {
		t.Fatalf("tắt loại thuế làm mất bộ mức: trước %v, sau %v", truoc.Muc, giua.Muc)
	}
}

// TestThue_KhongNhinThayCuaCuaHangKhac — bản cũ dùng một bảng chung không có
// tenant, sửa mức ở tiệm này là đổi luôn tiệm kia.
func TestThue_KhongNhinThayCuaCuaHangKhac(t *testing.T) {
	h := dungHeThong(t)
	a, b := haiCuaHang(t, h)

	banHangA := timThue(t, docThue(t, h, a.token), "ban-hang")

	res := h.goi(t, a.token, http.MethodPut, fmt.Sprintf("/api/v1/admin/thue/%d", banHangA.ID), map[string]any{
		"muc": []int{10},
	})
	if res.ma != http.StatusOK {
		t.Fatalf("lưu mức phải trả 200, nhận %d\n%s", res.ma, catBot(res.than))
	}

	banHangB := timThue(t, docThue(t, h, b.token), "ban-hang")
	if len(banHangB.Muc) == 1 && banHangB.Muc[0] == 10 {
		t.Fatal("cửa hàng B thấy bộ mức của cửa hàng A — bảng thuế không tách theo tenant")
	}

	// Và B cũng không được sờ vào dòng của A qua id trên URL.
	res = h.goi(t, b.token, http.MethodPut, fmt.Sprintf("/api/v1/admin/thue/%d", banHangA.ID), map[string]any{
		"muc": []int{5},
	})
	if res.ma != http.StatusNotFound {
		t.Fatalf("sửa dòng thuế của cửa hàng khác phải trả 404, nhận %d\n%s", res.ma, catBot(res.than))
	}
}
