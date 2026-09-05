package apitest

import (
	"encoding/json"
	"fmt"
	"net/http"
	"strings"
	"testing"
)

// TRÙNG TÊN — luật chung của cả hệ thống.
//
// Khai mới mà trùng tên với thứ đã có thì bị chặn, DÙ MÃ KHÁC. Mã còn phân biệt
// được bằng mắt; hai dòng cùng tên thì trong mọi ô chọn nhìn y hệt nhau, chọn
// nhầm dòng nào cũng không ai biết, mà số liệu thì tách đôi.
//
// Bài này gom mọi danh mục vào một chỗ: mỗi danh mục thêm sau này phải có một
// nhánh ở đây, không thì luật chung lại hở đúng chỗ vừa thêm.

// TestTrungTen_NhomHangHoa — nhóm hàng hoá.
func TestTrungTen_NhomHangHoa(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	ten := "Do uong " + a.vet
	tao := func(name string) int {
		return h.goi(t, a.token, http.MethodPost, "/api/v1/admin/categories",
			map[string]any{"name": name}).ma
	}

	if ma := tao(ten); ma != http.StatusCreated {
		t.Fatalf("lượt tạo đầu phải trả 201, nhận %d", ma)
	}
	// Mã do máy chủ tự đặt nên hai lượt này chắc chắn khác mã nhau.
	if ma := tao(ten); ma != http.StatusUnprocessableEntity {
		t.Fatalf("trùng tên phải bị chặn bằng 422 dù mã khác, nhận %d", ma)
	}
	// Khác hoa thường vẫn là trùng.
	if ma := tao("DO UONG " + a.vet); ma != http.StatusUnprocessableEntity {
		t.Fatalf("trùng tên khác hoa thường phải bị chặn, nhận %d", ma)
	}
}

// TestTrungTen_NhaCungCap — bên bán.
func TestTrungTen_NhaCungCap(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	ten := "Ben ban " + a.vet

	if ma, _ := themNCC(t, h, a.token, map[string]any{"name": ten, "address": "Ha Noi"}); ma != http.StatusCreated {
		t.Fatalf("lượt tạo đầu phải trả 201, nhận %d", ma)
	}
	// Mã khác hẳn, chỉ trùng tên.
	if ma, _ := themNCC(t, h, a.token, map[string]any{
		"code": "NCCKHAC" + a.vet, "name": ten, "address": "Hai Phong",
	}); ma != http.StatusUnprocessableEntity {
		t.Fatalf("trùng tên phải bị chặn bằng 422 dù mã khác, nhận %d", ma)
	}
}

// TestTrungTen_ChiNhanh — chi nhánh.
func TestTrungTen_ChiNhanh(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	ten := "Chi nhanh " + a.vet
	tao := func(code, name string) int {
		return h.goi(t, a.token, http.MethodPost, "/api/v1/admin/chi-nhanh",
			map[string]any{"code": code, "name": name}).ma
	}

	if ma := tao("cn-mot-"+a.vet, ten); ma != http.StatusCreated {
		t.Fatalf("lượt tạo đầu phải trả 201, nhận %d", ma)
	}
	if ma := tao("cn-hai-"+a.vet, ten); ma != http.StatusUnprocessableEntity {
		t.Fatalf("trùng tên phải bị chặn bằng 422 dù mã khác, nhận %d", ma)
	}
}

// TestTrungTen_KhongVuotSangCuaHangKhac — luật trùng tên nằm TRONG một cửa hàng.
//
// Chặn theo tên mà quên bộ lọc cửa hàng thì tiệm B không đặt được tên mà tiệm A
// đã dùng — hai tiệm chẳng liên quan gì nhau lại giẫm chân nhau.
func TestTrungTen_KhongVuotSangCuaHangKhac(t *testing.T) {
	h := dungHeThong(t)
	a, b := haiCuaHang(t, h)

	ten := "Nhom chung " + a.vet
	tao := func(token, name string) int {
		return h.goi(t, token, http.MethodPost, "/api/v1/admin/categories",
			map[string]any{"name": name}).ma
	}

	if ma := tao(a.token, ten); ma != http.StatusCreated {
		t.Fatalf("tiệm A tạo phải trả 201, nhận %d", ma)
	}
	if ma := tao(b.token, ten); ma != http.StatusCreated {
		t.Fatalf("tiệm B dùng cùng tên phải được, nhận %d", ma)
	}
}

// TestTrungTen_XoaRoiThiDungLaiTenDuoc — xoá mềm không được giữ chỗ cái TÊN.
//
// Mã thì có (UNIQUE index ở tầng DB giữ chỗ), còn tên thì không: xoá một nhóm
// rồi mà tên nó vẫn cấm dùng lại thì người dùng không có đường nào gỡ.
func TestTrungTen_XoaRoiThiDungLaiTenDuoc(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	ten := "Nhom tam " + a.vet

	res := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/categories", map[string]any{"name": ten})
	if res.ma != http.StatusCreated {
		t.Fatalf("lượt tạo đầu phải trả 201, nhận %d\n%s", res.ma, catBot(res.than))
	}

	var body struct {
		Data struct {
			ID uint `json:"id"`
		} `json:"data"`
	}
	_ = json.Unmarshal([]byte(res.than), &body)

	if ma := h.goi(t, a.token, http.MethodDelete,
		fmt.Sprintf("/api/v1/admin/categories/%d", body.Data.ID), nil).ma; ma != http.StatusOK {
		t.Fatalf("xoá phải trả 200, nhận %d", ma)
	}

	if ma := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/categories",
		map[string]any{"name": ten}).ma; ma != http.StatusCreated {
		t.Fatalf("xoá xong phải dùng lại tên ấy được, nhận %d", ma)
	}
}

// TestTrungTen_MatHang — mặt hàng, và câu lỗi phải nói về TÊN chứ không phải slug.
//
// slug sinh ra TỪ tên nên đặt trùng tên là vấp cả hai lượt kiểm cùng lúc. Báo
// "trùng slug" trước là nói về một thứ người dùng không nhìn thấy và không gõ;
// thứ tự hai lượt kiểm vì thế là một phần của hành vi, không phải chi tiết vặt.
func TestTrungTen_MatHang(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	ten := "May tinh " + a.vet
	tao := func(slug, sku string) (int, string) {
		res := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/products", map[string]any{
			"category_id": a.danhMuc,
			"name":        ten,
			"slug":        slug,
			"sku":         sku,
			"base_price":  100000,
			"status":      "active",
			"variants":    []map[string]any{{"name": "", "sku": sku + "-1"}},
		})

		return res.ma, res.than
	}

	if ma, than := tao("may-tinh-mot-"+a.vet, "SP-MT1-"+a.vet); ma != http.StatusCreated {
		t.Fatalf("lượt tạo đầu phải trả 201, nhận %d\n%s", ma, catBot(than))
	}

	// slug và mã đều KHÁC, chỉ trùng mỗi tên.
	ma, than := tao("may-tinh-hai-"+a.vet, "SP-MT2-"+a.vet)
	if ma != http.StatusUnprocessableEntity {
		t.Fatalf("trùng tên phải bị chặn bằng 422 dù slug và mã đều khác, nhận %d\n%s", ma, catBot(than))
	}
	if !strings.Contains(than, "Tên mặt hàng") {
		t.Fatalf("câu lỗi phải nói về TÊN chứ không phải slug, nhận:\n%s", catBot(than))
	}
}
