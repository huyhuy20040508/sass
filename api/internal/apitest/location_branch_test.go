package apitest

import (
	"encoding/json"
	"fmt"
	"net/http"
	"testing"
)

// VỊ TRÍ ĐỂ HÀNG LÀ CHUYỆN CỦA TỪNG CHI NHÁNH (migration 0052).
//
// Hai câu phải đúng, và chúng độc lập với nhau:
//   - cái KỆ thuộc về một mặt bằng: kho lạnh của Quận 1 không được hiện trong ô
//     chọn của Quận 7;
//   - MẶT HÀNG nằm ở kệ nào là câu hỏi phải kèm "ở kho nào": cùng một món, mỗi
//     kho một chỗ.
//
// Trước 0052 cả hai đều sai, và cái sai không lộ ra vì gần như mọi khách còn
// một chi nhánh — đúng loại lỗi chỉ nổ vào ngày họ mở điểm bán thứ hai.

const duongViTri = "/api/v1/admin/vi-tri"

// taoViTri khai một cái kệ khi đang đứng ở `shopID`, trả về (mã HTTP, id).
func taoViTri(t *testing.T, h *heThong, c *cuaHang, shopID uint, code, ten string) (int, uint) {
	t.Helper()

	res := h.goiChiNhanh(t, c.token, shopID, http.MethodPost, duongViTri, map[string]any{
		"code": code, "name": ten,
	})

	var body struct {
		Data struct {
			ID uint `json:"id"`
		} `json:"data"`
	}
	_ = json.Unmarshal([]byte(res.than), &body)

	return res.ma, body.Data.ID
}

// keCua liệt kê id kệ mà một chi nhánh nhìn thấy.
func keCua(t *testing.T, h *heThong, c *cuaHang, shopID uint) []uint {
	t.Helper()

	res := h.goiChiNhanh(t, c.token, shopID, http.MethodGet, duongViTri, nil)
	if res.ma != http.StatusOK {
		t.Fatalf("đọc vị trí ở chi nhánh %d trả %d\n%s", shopID, res.ma, catBot(res.than))
	}

	var body struct {
		Data []struct {
			ID     uint `json:"id"`
			ShopID uint `json:"shop_id"`
		} `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &body); err != nil {
		t.Fatalf("không đọc được danh sách vị trí: %v", err)
	}

	ids := make([]uint, 0, len(body.Data))
	for _, v := range body.Data {
		if v.ShopID != shopID {
			t.Fatalf("chi nhánh %d nhìn thấy kệ của chi nhánh %d", shopID, v.ShopID)
		}
		ids = append(ids, v.ID)
	}

	return ids
}

// Kệ của chi nhánh nào chỉ chi nhánh ấy thấy.
func TestViTriChiNhanh_KeCuaAiNguoiNayThay(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	kho1 := a.chiNhanh
	kho2 := moChiNhanhThuHai(t, h, a, "Kho hai")

	ma1, ke1 := taoViTri(t, h, a, kho1, "KEA1", "Kệ A - kho một")
	if ma1 != http.StatusCreated {
		t.Fatalf("khai kệ ở kho một trả %d", ma1)
	}
	ma2, ke2 := taoViTri(t, h, a, kho2, "KEA1", "Kệ A - kho hai")
	if ma2 != http.StatusCreated {
		t.Fatalf("hai chi nhánh cùng đặt mã KEA1 phải được, nhận %d", ma2)
	}

	co := func(ds []uint, id uint) bool {
		for _, x := range ds {
			if x == id {
				return true
			}
		}

		return false
	}

	ds1, ds2 := keCua(t, h, a, kho1), keCua(t, h, a, kho2)
	if !co(ds1, ke1) || co(ds1, ke2) {
		t.Fatalf("kho một phải thấy đúng kệ của mình: %v", ds1)
	}
	if !co(ds2, ke2) || co(ds2, ke1) {
		t.Fatalf("kho hai phải thấy đúng kệ của mình: %v", ds2)
	}
}

// Cùng một mặt hàng, mỗi kho một kệ — và đọc ở kho nào ra kệ của kho ấy.
func TestViTriChiNhanh_MoiKhoMotChoChoCungMotMatHang(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	kho1 := a.chiNhanh
	kho2 := moChiNhanhThuHai(t, h, a, "Kho hai")

	_, ke1 := taoViTri(t, h, a, kho1, "K1", "Kệ kho một")
	_, ke2 := taoViTri(t, h, a, kho2, "K2", "Kệ kho hai")

	// Đọc lại hồ sơ mặt hàng một lần để có ba ô bắt buộc của lượt PUT.
	cu := h.goiChiNhanh(t, a.token, kho1, http.MethodGet,
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

	xepKe := func(shopID, keID uint) {
		t.Helper()
		res := h.goiChiNhanh(t, a.token, shopID, http.MethodPut,
			fmt.Sprintf("/api/v1/admin/products/%d", a.sanPham), map[string]any{
				"name": hh.Data.Name, "slug": hh.Data.Slug,
				"category_id": hh.Data.CategoryID, "location_id": keID,
			})
		if res.ma != http.StatusOK {
			t.Fatalf("xếp kệ %d ở chi nhánh %d trả %d\n%s", keID, shopID, res.ma, catBot(res.than))
		}
	}

	docKe := func(shopID uint) *uint {
		t.Helper()
		res := h.goiChiNhanh(t, a.token, shopID, http.MethodGet,
			fmt.Sprintf("/api/v1/admin/products/%d", a.sanPham), nil)
		if res.ma != http.StatusOK {
			t.Fatalf("đọc mặt hàng ở chi nhánh %d trả %d", shopID, res.ma)
		}
		var b struct {
			Data struct {
				LocationID *uint `json:"location_id"`
			} `json:"data"`
		}
		if err := json.Unmarshal([]byte(res.than), &b); err != nil {
			t.Fatalf("không đọc được mặt hàng: %v", err)
		}

		return b.Data.LocationID
	}

	xepKe(kho1, ke1)
	xepKe(kho2, ke2)

	if got := docKe(kho1); got == nil || *got != ke1 {
		t.Fatalf("ở kho một phải ra kệ %d, nhận %v", ke1, got)
	}
	if got := docKe(kho2); got == nil || *got != ke2 {
		t.Fatalf("ở kho hai phải ra kệ %d, nhận %v", ke2, got)
	}

	// Gỡ kệ ở kho một KHÔNG được đụng tới kho hai: hai dòng độc lập.
	xepKe(kho1, 0)
	if got := docKe(kho1); got != nil {
		t.Fatalf("gỡ kệ ở kho một xong phải trống, nhận %v", *got)
	}
	if got := docKe(kho2); got == nil || *got != ke2 {
		t.Fatalf("gỡ kệ ở kho một không được đụng kho hai, nhận %v", got)
	}
}

// Xếp hàng vào kệ của chi nhánh KHÁC: từ chối.
//
// Kệ ấy là một chỗ vật lý người đứng đây không với tới được — cho qua thì mọi
// lượt soạn hàng sau đó dẫn tới một cái kho sai.
func TestViTriChiNhanh_KhongXepVaoKeCuaChiNhanhKhac(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	kho1 := a.chiNhanh
	kho2 := moChiNhanhThuHai(t, h, a, "Kho hai")

	_, keCuaKho2 := taoViTri(t, h, a, kho2, "K2", "Kệ kho hai")

	cu := h.goiChiNhanh(t, a.token, kho1, http.MethodGet,
		fmt.Sprintf("/api/v1/admin/products/%d", a.sanPham), nil)
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

	res := h.goiChiNhanh(t, a.token, kho1, http.MethodPut,
		fmt.Sprintf("/api/v1/admin/products/%d", a.sanPham), map[string]any{
			"name": hh.Data.Name, "slug": hh.Data.Slug,
			"category_id": hh.Data.CategoryID, "location_id": keCuaKho2,
		})
	if res.ma == http.StatusOK {
		t.Fatal("xếp hàng vào kệ của chi nhánh khác phải bị từ chối")
	}
}
