package apitest

import (
	"encoding/json"
	"fmt"
	"net/http"
	"testing"
)

// Bài kiểm THUỘC TÍNH qua API thật và MySQL thật.
//
// Màn này là master-detail nên phần dễ hỏng nằm ở tầng con: danh sách giá trị
// gửi lên có được đồng bộ đúng không (thêm/sửa/xoá trong MỘT lượt), bỏ một dòng
// rồi khai lại đúng mã ấy có bị chặn oan không, id giá trị của thuộc tính khác
// nhét vào thì sao (chính là lỗi CAT-R04 mà bản API v2 của bản cũ phải vá), và
// hai cửa hàng có nhìn thấy giá trị của nhau không.

// giaTriTT là một dòng trong bảng giá trị của thuộc tính.
type giaTriTT struct {
	ID   uint   `json:"id"`
	Code string `json:"code"`
	Name string `json:"name"`
}

// thuocTinh là một dòng trên bảng Thuộc tính, kèm giá trị con.
type thuocTinh struct {
	ID          uint       `json:"id"`
	Code        string     `json:"code"`
	Name        string     `json:"name"`
	IsActive    bool       `json:"is_active"`
	RawMaterial bool       `json:"raw_material"`
	InUse       bool       `json:"in_use"`
	Values      []giaTriTT `json:"values"`
}

// giaTriMoi dựng phần `values` cho một lượt gửi: chỉ tên, để server đặt mã hộ.
func giaTriMoi(ten ...string) []map[string]any {
	ra := make([]map[string]any, 0, len(ten))
	for _, t := range ten {
		ra = append(ra, map[string]any{"name": t})
	}

	return ra
}

// themThuocTinh gọi đường tạo và trả về mã HTTP kèm dòng vừa tạo.
func themThuocTinh(t *testing.T, h *heThong, token string, than map[string]any) (int, thuocTinh) {
	t.Helper()

	res := h.goi(t, token, http.MethodPost, "/api/v1/admin/thuoc-tinh", than)

	var body struct {
		Data thuocTinh `json:"data"`
	}
	_ = json.Unmarshal([]byte(res.than), &body)

	return res.ma, body.Data
}

// suaThuocTinh gọi đường sửa và trả về mã HTTP kèm dòng sau khi sửa.
func suaThuocTinh(t *testing.T, h *heThong, token string, id uint, than map[string]any) (int, thuocTinh) {
	t.Helper()

	res := h.goi(t, token, http.MethodPut, fmt.Sprintf("/api/v1/admin/thuoc-tinh/%d", id), than)

	var body struct {
		Data thuocTinh `json:"data"`
	}
	_ = json.Unmarshal([]byte(res.than), &body)

	return res.ma, body.Data
}

// docThuocTinh đọc danh sách thuộc tính của một cửa hàng. query là phần sau dấu
// ? (có thể rỗng).
func docThuocTinh(t *testing.T, h *heThong, token, query string) []thuocTinh {
	t.Helper()

	duong := "/api/v1/admin/thuoc-tinh"
	if query != "" {
		duong += "?" + query
	}

	res := h.goi(t, token, http.MethodGet, duong, nil)
	if res.ma != http.StatusOK {
		t.Fatalf("đọc danh sách thuộc tính phải trả 200, nhận %d\n%s", res.ma, catBot(res.than))
	}

	var body struct {
		Data []thuocTinh `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &body); err != nil {
		t.Fatalf("không đọc được phản hồi: %v\n%s", err, catBot(res.than))
	}

	return body.Data
}

// tenGiaTri gom tên các giá trị để so sánh cho gọn.
func tenGiaTri(tt thuocTinh) []string {
	ra := make([]string, 0, len(tt.Values))
	for _, gt := range tt.Values {
		ra = append(ra, gt.Name)
	}

	return ra
}

// bang so hai danh sách chuỗi.
func bang(a, b []string) bool {
	if len(a) != len(b) {
		return false
	}
	for i := range a {
		if a[i] != b[i] {
			return false
		}
	}

	return true
}

// TestThuocTinh_TaoRoiDocLai — mã tự viết hoa, thuộc tính mới mặc định đang bật,
// và giá trị bỏ trống mã được đặt hộ theo dạng <mã thuộc tính><số>.
func TestThuocTinh_TaoRoiDocLai(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	ma, tt := themThuocTinh(t, h, a.token, map[string]any{
		"code":   "size",
		"name":   "Kích cỡ",
		"values": giaTriMoi("Nhỏ", "Vừa", "Lớn"),
	})
	if ma != http.StatusCreated {
		t.Fatalf("tạo thuộc tính phải trả 201, nhận %d", ma)
	}
	if tt.Code != "SIZE" {
		t.Fatalf("mã phải được viết hoa thành SIZE, nhận %q", tt.Code)
	}
	if !tt.IsActive {
		t.Fatal("thuộc tính mới phải đang bật — không thì vừa khai xong đã không chọn được")
	}
	if tt.RawMaterial {
		t.Fatal("cờ định lượng nguyên vật liệu phải mặc định TẮT")
	}

	ds := docThuocTinh(t, h, a.token, "")
	if len(ds) != 1 {
		t.Fatalf("danh sách phải có đúng thuộc tính vừa tạo, nhận %+v", ds)
	}
	if !bang(tenGiaTri(ds[0]), []string{"Nhỏ", "Vừa", "Lớn"}) {
		t.Fatalf("giá trị phải trả kèm và đúng thứ tự khai, nhận %+v", ds[0].Values)
	}
	for i, gt := range ds[0].Values {
		muon := fmt.Sprintf("SIZE%02d", i+1)
		if gt.Code != muon {
			t.Fatalf("mã giá trị bỏ trống phải được đặt hộ thành %s, nhận %q", muon, gt.Code)
		}
	}
}

// TestThuocTinh_ChanTrungMaVaTrungTen — hai lỗi tách nhau vì người đọc phải sửa
// hai ô khác nhau.
func TestThuocTinh_ChanTrungMaVaTrungTen(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	if ma, _ := themThuocTinh(t, h, a.token, map[string]any{"code": "DA", "name": "Mức đá"}); ma != http.StatusCreated {
		t.Fatalf("lượt tạo đầu phải trả 201, nhận %d", ma)
	}

	if ma, _ := themThuocTinh(t, h, a.token, map[string]any{"code": "da", "name": "Đá viên"}); ma != http.StatusUnprocessableEntity {
		t.Fatalf("mã trùng (khác hoa thường) phải bị chặn bằng 422, nhận %d", ma)
	}

	if ma, _ := themThuocTinh(t, h, a.token, map[string]any{"code": "DA2", "name": "mức đá"}); ma != http.StatusUnprocessableEntity {
		t.Fatalf("tên trùng (khác hoa thường) phải bị chặn bằng 422, nhận %d", ma)
	}

	// Có dấu khác không dấu là HAI thuộc tính: "Mức đá" và "Muc da" đọc ra hai
	// thứ khác nhau, mà đối chiếu mặc định của MySQL lại coi chúng là một.
	if ma, _ := themThuocTinh(t, h, a.token, map[string]any{"code": "DA3", "name": "Muc da"}); ma != http.StatusCreated {
		t.Fatalf("tên khác dấu phải là thuộc tính khác, nhận %d", ma)
	}
}

// TestThuocTinh_ChanGiaTriTrungMaVaTrungTen — hai giá trị cùng mã hoặc cùng tên
// trong CÙNG một thuộc tính. Bản cũ v2 không ràng buộc gì ở tầng này.
func TestThuocTinh_ChanGiaTriTrungMaVaTrungTen(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	ma, _ := themThuocTinh(t, h, a.token, map[string]any{
		"name": "Kích cỡ",
		"values": []map[string]any{
			{"code": "S", "name": "Nhỏ"},
			{"code": "s", "name": "Siêu nhỏ"},
		},
	})
	if ma != http.StatusUnprocessableEntity {
		t.Fatalf("hai giá trị trùng mã phải bị chặn bằng 422, nhận %d", ma)
	}

	ma, _ = themThuocTinh(t, h, a.token, map[string]any{
		"name": "Kích cỡ",
		"values": []map[string]any{
			{"code": "S", "name": "Nhỏ"},
			{"code": "N", "name": "nhỏ"},
		},
	})
	if ma != http.StatusUnprocessableEntity {
		t.Fatalf("hai giá trị trùng tên phải bị chặn bằng 422, nhận %d", ma)
	}

	// Nhưng "S" của Kích cỡ và "S" của một thuộc tính khác là hai thứ không liên
	// quan — mã giá trị chỉ cần khác nhau TRONG một thuộc tính.
	if ma, _ := themThuocTinh(t, h, a.token, map[string]any{
		"code": "SIZE", "name": "Kích cỡ",
		"values": []map[string]any{{"code": "S", "name": "Nhỏ"}},
	}); ma != http.StatusCreated {
		t.Fatalf("tạo thuộc tính thứ nhất phải trả 201, nhận %d", ma)
	}
	if ma, _ := themThuocTinh(t, h, a.token, map[string]any{
		"code": "LY", "name": "Cỡ ly",
		"values": []map[string]any{{"code": "S", "name": "Ly nhỏ"}},
	}); ma != http.StatusCreated {
		t.Fatalf("mã giá trị chỉ duy nhất trong một thuộc tính, nhận %d", ma)
	}
}

// TestThuocTinh_SuaDongBoDanhSachGiaTri — lượt sửa gửi lên NGUYÊN danh sách
// đang thấy: dòng có id thì sửa, không id thì thêm, giá trị cũ vắng mặt thì xoá.
func TestThuocTinh_SuaDongBoDanhSachGiaTri(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	_, tt := themThuocTinh(t, h, a.token, map[string]any{
		"code":   "SIZE",
		"name":   "Kích cỡ",
		"values": giaTriMoi("Nhỏ", "Vừa", "Lớn"),
	})
	if len(tt.Values) != 3 {
		t.Fatalf("phải tạo được ba giá trị, nhận %+v", tt.Values)
	}

	// Giữ "Nhỏ" (đổi tên), bỏ "Vừa", giữ "Lớn", thêm "Siêu lớn".
	ma, sau := suaThuocTinh(t, h, a.token, tt.ID, map[string]any{
		"name": "Kích cỡ",
		"values": []map[string]any{
			{"id": tt.Values[0].ID, "code": tt.Values[0].Code, "name": "Cỡ nhỏ"},
			{"id": tt.Values[2].ID, "code": tt.Values[2].Code, "name": "Lớn"},
			{"name": "Siêu lớn"},
		},
	})
	if ma != http.StatusOK {
		t.Fatalf("sửa phải trả 200, nhận %d", ma)
	}
	if !bang(tenGiaTri(sau), []string{"Cỡ nhỏ", "Lớn", "Siêu lớn"}) {
		t.Fatalf("danh sách giá trị phải đồng bộ đúng thứ gửi lên, nhận %+v", sau.Values)
	}

	ds := docThuocTinh(t, h, a.token, "")
	if len(ds) != 1 || len(ds[0].Values) != 3 {
		t.Fatalf("đọc lại phải thấy đúng ba giá trị, nhận %+v", ds)
	}
	for _, gt := range ds[0].Values {
		if gt.Name == "Vừa" {
			t.Fatal("giá trị vắng mặt trong danh sách gửi lên phải bị xoá")
		}
	}
}

// TestThuocTinh_BoRoiKhaiLaiDungMaTrongCungLuot — giá trị xoá HẲN chứ không xoá
// mềm. Xoá mềm thì dòng cũ còn nằm trong UNIQUE index và người dùng ăn lỗi "mã
// đã tồn tại" cho một bảng đang trống trơn.
func TestThuocTinh_BoRoiKhaiLaiDungMaTrongCungLuot(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	_, tt := themThuocTinh(t, h, a.token, map[string]any{
		"code":   "SIZE",
		"name":   "Kích cỡ",
		"values": []map[string]any{{"code": "S", "name": "Nhỏ"}},
	})

	// Bỏ hẳn dòng cũ, khai lại một dòng MỚI mang đúng mã "S".
	ma, sau := suaThuocTinh(t, h, a.token, tt.ID, map[string]any{
		"name":   "Kích cỡ",
		"values": []map[string]any{{"code": "S", "name": "Cỡ S"}},
	})
	if ma != http.StatusOK {
		t.Fatalf("bỏ rồi khai lại đúng mã ấy phải được, nhận %d", ma)
	}
	if len(sau.Values) != 1 || sau.Values[0].Code != "S" || sau.Values[0].Name != "Cỡ S" {
		t.Fatalf("phải còn đúng một giá trị mang mã S, nhận %+v", sau.Values)
	}
}

// TestThuocTinh_KhongGuiValuesThiGiuNguyen — bỏ HẲN trường `values` là "không
// đụng tới bảng giá trị"; gửi mảng RỖNG mới là "xoá hết". Hai câu ấy phải khác
// nhau, không thì một lượt sửa mỗi cái tên cũng quét sạch giá trị.
func TestThuocTinh_KhongGuiValuesThiGiuNguyen(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	_, tt := themThuocTinh(t, h, a.token, map[string]any{
		"code":   "DA",
		"name":   "Mức đá",
		"values": giaTriMoi("Ít đá", "Nhiều đá"),
	})

	if ma, _ := suaThuocTinh(t, h, a.token, tt.ID, map[string]any{"name": "Mức đá viên"}); ma != http.StatusOK {
		t.Fatalf("sửa mỗi tên phải trả 200, nhận %d", ma)
	}

	ds := docThuocTinh(t, h, a.token, "")
	if len(ds) != 1 || ds[0].Name != "Mức đá viên" {
		t.Fatalf("tên phải đổi, nhận %+v", ds)
	}
	if len(ds[0].Values) != 2 {
		t.Fatalf("không gửi values thì giá trị phải còn nguyên, nhận %+v", ds[0].Values)
	}

	if ma, _ := suaThuocTinh(t, h, a.token, tt.ID, map[string]any{
		"name": "Mức đá viên", "values": []map[string]any{},
	}); ma != http.StatusOK {
		t.Fatalf("gửi mảng rỗng phải trả 200, nhận %d", ma)
	}
	if ds := docThuocTinh(t, h, a.token, ""); len(ds[0].Values) != 0 {
		t.Fatalf("gửi mảng rỗng là xoá hết giá trị, nhận %+v", ds[0].Values)
	}
}

// TestThuocTinh_ChanGiaTriCuaThuocTinhKhac — id lạ nhét vào danh sách giá trị.
//
// Bản cũ v2 nhét thẳng id ấy vào `updateOrCreate` nên nó thành một lượt INSERT
// với khoá chính do client chọn; đây chính là lỗi CAT-R04 mà bản API v2 của họ
// phải vá lại sau.
func TestThuocTinh_ChanGiaTriCuaThuocTinhKhac(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	_, mot := themThuocTinh(t, h, a.token, map[string]any{
		"code": "SIZE", "name": "Kích cỡ", "values": giaTriMoi("Nhỏ"),
	})
	_, hai := themThuocTinh(t, h, a.token, map[string]any{
		"code": "DA", "name": "Mức đá", "values": giaTriMoi("Ít đá"),
	})

	ma, _ := suaThuocTinh(t, h, a.token, hai.ID, map[string]any{
		"name": "Mức đá",
		"values": []map[string]any{
			{"id": mot.Values[0].ID, "code": "IT", "name": "Bị cướp"},
		},
	})
	if ma != http.StatusUnprocessableEntity {
		t.Fatalf("id giá trị của thuộc tính khác phải bị chặn bằng 422, nhận %d", ma)
	}

	// Và giá trị của thuộc tính thứ nhất vẫn nguyên vẹn.
	ds := docThuocTinh(t, h, a.token, "keyword=SIZE")
	if len(ds) != 1 || len(ds[0].Values) != 1 || ds[0].Values[0].Name != "Nhỏ" {
		t.Fatalf("giá trị của thuộc tính kia phải còn nguyên, nhận %+v", ds)
	}
}

// TestThuocTinh_MaVanBiGiuChoSauKhiXoa — thuộc tính xoá mềm nên mã cũ còn nằm
// trong UNIQUE index; báo trùng ở tầng Go vẫn hơn để MySQL ném lỗi thô lên màn.
func TestThuocTinh_MaVanBiGiuChoSauKhiXoa(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	_, tt := themThuocTinh(t, h, a.token, map[string]any{
		"code": "TOPPING", "name": "Topping", "values": giaTriMoi("Trân châu"),
	})

	res := h.goi(t, a.token, http.MethodDelete, fmt.Sprintf("/api/v1/admin/thuoc-tinh/%d", tt.ID), nil)
	if res.ma != http.StatusOK {
		t.Fatalf("xoá phải trả 200, nhận %d\n%s", res.ma, catBot(res.than))
	}

	if ds := docThuocTinh(t, h, a.token, ""); len(ds) != 0 {
		t.Fatalf("thuộc tính đã xoá không được hiện lại trong danh sách, nhận %+v", ds)
	}

	if ma, _ := themThuocTinh(t, h, a.token, map[string]any{"code": "TOPPING", "name": "Topping mới"}); ma != http.StatusUnprocessableEntity {
		t.Fatalf("mã của thuộc tính đã xoá vẫn giữ chỗ, phải trả 422, nhận %d", ma)
	}
}

// TestThuocTinh_MaChiDuyNhatTrongMotCuaHang — chỗ bản cũ hỏng nặng nhất: nó
// ràng buộc duy nhất trên cả bảng dùng chung, nên tiệm này đặt "SIZE" rồi là mọi
// tiệm khác hết đặt.
func TestThuocTinh_MaChiDuyNhatTrongMotCuaHang(t *testing.T) {
	h := dungHeThong(t)
	a, b := haiCuaHang(t, h)

	if ma, _ := themThuocTinh(t, h, a.token, map[string]any{"code": "SIZE", "name": "Kích cỡ"}); ma != http.StatusCreated {
		t.Fatalf("cửa hàng A tạo SIZE phải trả 201, nhận %d", ma)
	}
	if ma, _ := themThuocTinh(t, h, b.token, map[string]any{"code": "SIZE", "name": "Kích cỡ"}); ma != http.StatusCreated {
		t.Fatalf("cửa hàng B cũng phải đặt được mã SIZE, nhận %d", ma)
	}
}

// TestThuocTinh_CongTacKhongGhiLanSangTen — công tắc gửi ĐÚNG một trường.
//
// Bản cũ gọi `fill($request->all())` ở đường trạng thái, nên gửi kèm `name` là
// đổi luôn tên qua chính lượt gạt công tắc ấy.
func TestThuocTinh_CongTacKhongGhiLanSangTen(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	_, tt := themThuocTinh(t, h, a.token, map[string]any{
		"code": "SIZE", "name": "Kích cỡ", "raw_material": true, "values": giaTriMoi("Nhỏ"),
	})
	if !tt.RawMaterial {
		t.Fatal("cờ định lượng gửi lên true mà không lưu")
	}

	res := h.goi(t, a.token, http.MethodPut,
		fmt.Sprintf("/api/v1/admin/thuoc-tinh/%d/trang-thai", tt.ID),
		map[string]any{"is_active": false, "name": "Tên gửi lén", "code": "LEN", "raw_material": false})
	if res.ma != http.StatusOK {
		t.Fatalf("đổi trạng thái phải trả 200, nhận %d\n%s", res.ma, catBot(res.than))
	}

	ds := docThuocTinh(t, h, a.token, "")
	if len(ds) != 1 {
		t.Fatalf("phải còn đúng một thuộc tính, nhận %+v", ds)
	}
	if ds[0].IsActive {
		t.Fatal("công tắc tắt rồi mà thuộc tính vẫn đang bật")
	}
	if ds[0].Name != "Kích cỡ" || ds[0].Code != "SIZE" || !ds[0].RawMaterial {
		t.Fatalf("lượt gạt công tắc đã ghi lẫn sang trường khác: %+v", ds[0])
	}
	if len(ds[0].Values) != 1 {
		t.Fatalf("lượt gạt công tắc không được đụng tới giá trị, nhận %+v", ds[0].Values)
	}
}

// TestThuocTinh_LocTheoTrangThaiVaDinhLuong — `active=true` là tham số của ô
// chọn thuộc tính lúc khai mặt hàng, `raw_material=true` là của màn định lượng
// nguyên liệu.
func TestThuocTinh_LocTheoTrangThaiVaDinhLuong(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	themThuocTinh(t, h, a.token, map[string]any{"code": "SIZE", "name": "Kích cỡ", "raw_material": true})
	_, tat := themThuocTinh(t, h, a.token, map[string]any{"code": "MAU", "name": "Màu sắc"})

	res := h.goi(t, a.token, http.MethodPut,
		fmt.Sprintf("/api/v1/admin/thuoc-tinh/%d/trang-thai", tat.ID),
		map[string]any{"is_active": false})
	if res.ma != http.StatusOK {
		t.Fatalf("đổi trạng thái phải trả 200, nhận %d", res.ma)
	}

	if ds := docThuocTinh(t, h, a.token, ""); len(ds) != 2 {
		t.Fatalf("bảng quản lý phải thấy cả thuộc tính đã tắt, nhận %d dòng", len(ds))
	}

	if ds := docThuocTinh(t, h, a.token, "active=true"); len(ds) != 1 || ds[0].Code != "SIZE" {
		t.Fatalf("ô chọn chỉ được thấy thuộc tính đang bật, nhận %+v", ds)
	}

	if ds := docThuocTinh(t, h, a.token, "raw_material=true"); len(ds) != 1 || ds[0].Code != "SIZE" {
		t.Fatalf("lọc định lượng chỉ được thấy thuộc tính bật cờ, nhận %+v", ds)
	}
}

// TestThuocTinh_KhongNhinThayVaKhongSuaDuocCuaCuaHangKhac — bản cũ để
// `scopeBranch` trả thẳng query ra (dòng lọc bị comment), và bảng giá trị con
// thì không có cột chi nhánh nào cả.
func TestThuocTinh_KhongNhinThayVaKhongSuaDuocCuaCuaHangKhac(t *testing.T) {
	h := dungHeThong(t)
	a, b := haiCuaHang(t, h)

	_, cuaB := themThuocTinh(t, h, b.token, map[string]any{
		"code": "SIZE", "name": "Kích cỡ", "values": giaTriMoi("Nhỏ", "Lớn"),
	})

	if ds := docThuocTinh(t, h, a.token, ""); len(ds) != 0 {
		t.Fatalf("cửa hàng A không được thấy thuộc tính của B, nhận %+v", ds)
	}

	duong := fmt.Sprintf("/api/v1/admin/thuoc-tinh/%d", cuaB.ID)

	if res := h.goi(t, a.token, http.MethodGet, duong, nil); res.ma != http.StatusNotFound {
		t.Fatalf("đọc thuộc tính của cửa hàng khác phải trả 404, nhận %d", res.ma)
	}

	res := h.goi(t, a.token, http.MethodPut, duong, map[string]any{"name": "Bị sửa trộm"})
	if res.ma != http.StatusNotFound {
		t.Fatalf("sửa thuộc tính của cửa hàng khác phải trả 404, nhận %d\n%s", res.ma, catBot(res.than))
	}

	if res := h.goi(t, a.token, http.MethodDelete, duong, nil); res.ma != http.StatusNotFound {
		t.Fatalf("xoá thuộc tính của cửa hàng khác phải trả 404, nhận %d", res.ma)
	}

	// Và thuộc tính của B vẫn nguyên vẹn — cả phần giá trị con.
	ds := docThuocTinh(t, h, b.token, "")
	if len(ds) != 1 || ds[0].Name != "Kích cỡ" || len(ds[0].Values) != 2 {
		t.Fatalf("thuộc tính của cửa hàng B phải còn nguyên, nhận %+v", ds)
	}
}

// TestThuocTinh_ChanMaCoKhoangTrangVaKyTuLa — GÕ TAY thì mã chỉ nhận chữ không
// dấu và số, cả ở thuộc tính lẫn ở từng giá trị. (Bỏ trống là chuyện khác: đó là
// "để phần mềm đặt hộ", xem TestThuocTinh_BoTrongMaThiTuDat.)
func TestThuocTinh_ChanMaCoKhoangTrangVaKyTuLa(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	for _, ma := range []string{"SIZE 2", "SIZE-2", "Cỡ"} {
		if code, _ := themThuocTinh(t, h, a.token, map[string]any{"code": ma, "name": "Thử " + ma}); code != http.StatusUnprocessableEntity {
			t.Fatalf("mã thuộc tính %q phải bị chặn bằng 422, nhận %d", ma, code)
		}
	}

	if code, _ := themThuocTinh(t, h, a.token, map[string]any{
		"code": "SIZE", "name": "Kích cỡ",
		"values": []map[string]any{{"code": "S 1", "name": "Nhỏ"}},
	}); code != http.StatusUnprocessableEntity {
		t.Fatalf("mã giá trị có khoảng trắng phải bị chặn bằng 422, nhận %d", code)
	}
}

// TestThuocTinh_BoTrongMaThiTuDat — cửa hàng chưa bật quy tắc đánh số riêng thì
// mã rơi về dải TT001, và dãy đếm tiếp chứ không đụng mã người dùng tự gõ.
func TestThuocTinh_BoTrongMaThiTuDat(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	ma, dau := themThuocTinh(t, h, a.token, map[string]any{"name": "Kích cỡ"})
	if ma != http.StatusCreated {
		t.Fatalf("bỏ trống mã phải tạo được, nhận %d", ma)
	}
	if dau.Code != "TT001" {
		t.Fatalf("mã đầu tiên phải là TT001, nhận %q", dau.Code)
	}

	// Mã gõ tay xen vào giữa KHÔNG được làm hỏng dãy: nó không đúng khuôn TT+số
	// nên không tham gia lượt đếm.
	if ma, _ := themThuocTinh(t, h, a.token, map[string]any{"code": "TOPPING", "name": "Topping"}); ma != http.StatusCreated {
		t.Fatalf("mã gõ tay phải tạo được, nhận %d", ma)
	}

	_, sau := themThuocTinh(t, h, a.token, map[string]any{"name": "Mức đá"})
	if sau.Code != "TT002" {
		t.Fatalf("mã kế tiếp phải là TT002, nhận %q", sau.Code)
	}
}

// TestThuocTinh_MaTuDatTheoQuyTacCuaCuaHang — bật quy tắc ở Cài đặt → Thông số
// chung thì mã sinh ra theo đúng hình dạng cửa hàng khai, không phải TT001.
func TestThuocTinh_MaTuDatTheoQuyTacCuaCuaHang(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	res := h.goi(t, a.token, http.MethodPut, "/api/v1/admin/quy-tac-ma", map[string]any{
		"shop_id": a.chiNhanh,
		"quy_tac": []map[string]any{
			{"doc_type": "thuoc-tinh", "prefix": "TTS", "value_part": "so-thu-tu", "length": 4, "suffix": ""},
		},
	})
	if res.ma != http.StatusOK {
		t.Fatalf("lưu quy tắc phải trả 200, nhận %d\n%s", res.ma, catBot(res.than))
	}

	_, tt := themThuocTinh(t, h, a.token, map[string]any{"name": "Kích cỡ"})
	if tt.Code != "TTS0001" {
		t.Fatalf("mã phải theo quy tắc của cửa hàng (TTS0001), nhận %q", tt.Code)
	}
}

// TestThuocTinh_SuaBoTrongMaThiGiuNguyen — thuộc tính đã gắn vào mặt hàng; lượt
// sửa tên mà tự đổi luôn mã là hai bên lệch nhau.
func TestThuocTinh_SuaBoTrongMaThiGiuNguyen(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	_, tt := themThuocTinh(t, h, a.token, map[string]any{"code": "SIZE", "name": "Kích cỡ"})

	ma, sau := suaThuocTinh(t, h, a.token, tt.ID, map[string]any{"name": "Cỡ"})
	if ma != http.StatusOK {
		t.Fatalf("sửa phải trả 200, nhận %d", ma)
	}
	if sau.Code != "SIZE" || sau.Name != "Cỡ" {
		t.Fatalf("sửa tên mà bỏ trống mã phải giữ nguyên mã cũ, nhận %+v", sau)
	}
}
