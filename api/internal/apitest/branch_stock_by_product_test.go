package apitest

import (
	"encoding/json"
	"fmt"
	"net/http"
	"testing"
)

// MÀN TỒN KHO CHỈ BÀY HÀNG CỦA CHI NHÁNH ĐANG ĐỨNG.
//
// Trước bài này màn ấy liệt kê TOÀN BỘ danh mục của cửa hàng với số 0 ở mọi
// kho: chi nhánh mới mở bán ba món phải cuộn qua cả trăm dòng không dính gì tới
// mình, và bảng thôi dùng để đếm hàng được.
//
// Nhưng có một ngoại lệ KHÔNG ĐƯỢC quên, và nó quan trọng hơn cả luật chính:
// mặt hàng vừa bị gán đi chi nhánh khác mà kho này còn ôm hàng thật thì VẪN
// phải hiện ra. Giấu nó đi là giấu số hàng đang nằm trong kho.

// danhSachTonKho trả về map[variant_id]tồn mà một chi nhánh nhìn thấy.
func danhSachTonKho(t *testing.T, h *heThong, c *cuaHang, shopID uint) map[uint]int {
	t.Helper()

	res := h.goiChiNhanh(t, c.token, shopID, http.MethodGet,
		fmt.Sprintf("/api/v1/admin/inventory/chi-nhanh?shops=%d&page_size=200", shopID), nil)
	if res.ma != http.StatusOK {
		t.Fatalf("đọc tồn kho chi nhánh %d trả %d\n%s", shopID, res.ma, catBot(res.than))
	}

	var body struct {
		Data struct {
			Dong []struct {
				VariantID uint `json:"variant_id"`
				ShopID    uint `json:"shop_id"`
				Ton       int  `json:"quantity"`
			} `json:"dong"`
		} `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &body); err != nil {
		t.Fatalf("không đọc được tồn kho: %v\n%s", err, catBot(res.than))
	}

	ra := make(map[uint]int, len(body.Data.Dong))
	for _, d := range body.Data.Dong {
		ra[d.VariantID] = d.Ton
	}

	return ra
}

// doiChiNhanhCuaHang gán mặt hàng cho đúng những chi nhánh khai ở đây.
// Danh sách rỗng = trả về "mọi chi nhánh".
func doiChiNhanhCuaHang(t *testing.T, h *heThong, a *cuaHang, dungTai uint, shopIDs []uint) {
	t.Helper()

	cu := h.goiChiNhanh(t, a.token, dungTai, http.MethodGet,
		fmt.Sprintf("/api/v1/admin/products/%d", a.sanPham), nil)
	if cu.ma != http.StatusOK {
		t.Fatalf("đọc mặt hàng trả %d", cu.ma)
	}
	var hh struct {
		Data struct {
			Name       string `json:"name"`
			Slug       string `json:"slug"`
			CategoryID uint   `json:"category_id"`
		} `json:"data"`
	}
	if err := json.Unmarshal([]byte(cu.than), &hh); err != nil {
		t.Fatalf("không đọc được mặt hàng: %v", err)
	}

	res := h.goiChiNhanh(t, a.token, dungTai, http.MethodPut,
		fmt.Sprintf("/api/v1/admin/products/%d", a.sanPham), map[string]any{
			"name": hh.Data.Name, "slug": hh.Data.Slug,
			"category_id": hh.Data.CategoryID, "shop_ids": shopIDs,
		})
	if res.ma != http.StatusOK {
		t.Fatalf("gán chi nhánh cho mặt hàng trả %d\n%s", res.ma, catBot(res.than))
	}
}

// Chưa gán chi nhánh nào = dùng chung: cả hai kho đều thấy.
// Gán riêng kho một: kho hai thôi bày mặt hàng ấy ra.
func TestTonKhoTheoHang_ChiBayHangCuaChiNhanhDangDung(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	kho1 := a.chiNhanh
	kho2 := moChiNhanhThuHai(t, h, a, "Kho hai")

	if _, co := danhSachTonKho(t, h, a, kho2)[a.bienThe]; !co {
		t.Fatal("mặt hàng chưa gán chi nhánh nào phải hiện ở MỌI kho")
	}

	doiChiNhanhCuaHang(t, h, a, kho1, []uint{kho1})

	if _, co := danhSachTonKho(t, h, a, kho1)[a.bienThe]; !co {
		t.Fatal("kho được gán phải vẫn thấy mặt hàng")
	}
	if _, co := danhSachTonKho(t, h, a, kho2)[a.bienThe]; co {
		t.Fatal("kho KHÔNG được gán và KHÔNG có hàng thì không được bày mặt hàng ra")
	}
}

// NGOẠI LỆ: kho còn ôm hàng thật thì vẫn phải thấy, dù mặt hàng đã bị gán đi.
//
// Đây là chỗ dễ làm hỏng nhất khi siết bộ lọc: giấu dòng đi thì thủ kho đếm tay
// ra 2 cái mà phần mềm không có dòng nào để đối chiếu, và cũng không còn đường
// nào chỉnh kho hay lập phiếu chuyển trả số hàng ấy.
func TestTonKhoTheoHang_VanBayHangDangKetOKhoSai(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	kho1 := a.chiNhanh
	kho2 := moChiNhanhThuHai(t, h, a, "Kho hai")

	// Đưa hàng sang kho hai TRƯỚC khi gán riêng — lúc này còn hợp lệ.
	donKho(t, h, a)
	nhapLo(t, h, a, "LO-KET2", 5, "")
	_, id := lapDieuChuyen(t, h, a, map[string]any{
		"from_shop_id": kho1, "to_shop_id": kho2,
		"items": []any{map[string]any{"variant_id": a.bienThe, "quantity": 2}},
	})
	if res := h.goiChiNhanh(t, a.token, kho1, http.MethodPost,
		fmt.Sprintf("%s/%d/duyet", duongDieuChuyen, id), nil); res.ma != http.StatusOK {
		t.Fatalf("duyệt phiếu chuẩn bị trả %d\n%s", res.ma, catBot(res.than))
	}

	// Giờ mới gán riêng cho kho một.
	doiChiNhanhCuaHang(t, h, a, kho1, []uint{kho1})

	ton, co := danhSachTonKho(t, h, a, kho2)[a.bienThe]
	if !co {
		t.Fatal("kho đang ôm hàng thật phải VẪN thấy mặt hàng, không thì số hàng ấy tàng hình")
	}
	if ton != 2 {
		t.Fatalf("phải bày đúng số đang có là 2, nhận %d", ton)
	}
}
