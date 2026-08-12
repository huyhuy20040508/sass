package repository

import "testing"

// ChuanHoaHost là chỗ hai đầu phải gặp nhau: công cụ khu điều hành GHI tên miền
// vào sổ, middleware ĐỌC header Host rồi tra. Lệch nhau một dấu chấm hay một chữ
// hoa thì tên miền vào sổ rồi mà không phân giải được, và không có gì trên màn
// hình gợi ý vì sao — nên nó là hàm dùng chung, và đây là bài kiểm của nó.
func TestChuanHoaHost(t *testing.T) {
	cases := map[string]string{
		"CuaHang.Selliotech.Store": "cuahang.selliotech.store",
		"  cuahang.store  ":        "cuahang.store",
		"cuahang.store:8080":       "cuahang.store",
		"cuahang.store.":           "cuahang.store",
		"CuaHang.Store.:443":       "cuahang.store",
		"localhost":                "localhost",
		"localhost:3000":           "localhost",
		"":                         "",
		// IPv6: dấu hai chấm nằm TRONG địa chỉ, chỉ được cắt cái sau dấu ']'.
		"[::1]:8080": "::1",
		"[::1]":      "::1",
	}

	for vao, mong := range cases {
		if ra := ChuanHoaHost(vao); ra != mong {
			t.Errorf("ChuanHoaHost(%q) = %q, mong %q", vao, ra, mong)
		}
	}
}
