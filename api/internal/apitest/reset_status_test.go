package apitest

import (
	"fmt"
	"net/http"
	"testing"
)

// TestDatLaiTrangThaiDangCo — đặt một bản ghi về ĐÚNG trạng thái nó đang có phải
// thành công, không được trả "không tìm thấy dữ liệu".
//
// Vì sao đây là chuyện thường ngày chứ không phải trường hợp hiếm: mọi nút
// bật/tắt trong trang quản trị gửi trạng thái ĐÍCH ("đặt về active"), không gửi
// "đảo trạng thái". Nên bấm hai lần, hai nhân viên cùng bấm, hay tải lại trang
// rồi bấm đều rơi vào đúng tình huống này.
//
// Lỗi gốc: MySQL đếm số dòng ĐỔI chứ không phải số dòng KHỚP, mà repository lại
// đọc `RowsAffected == 0` thành ErrNotFound. Người bán bấm "Đang bán" cho sản
// phẩm vốn đang bán và nhận về "Không tìm thấy dữ liệu" — một lỗi bịa, mà tin
// theo thì tưởng sản phẩm vừa biến mất.
//
// Bài kiểm gọi HAI LẦN: lượt đầu có thể đổi thật, lượt sau chắc chắn không đổi
// gì. Kèm một id không tồn tại để chắc rằng bản vá không biến "không có" thành
// "thành công" — đó mới là hỏng nặng.
func TestDatLaiTrangThaiDangCo(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	// idLa là id chắc chắn không thuộc về ai: cao hơn mọi id đang có trong bảng.
	const idLa = 999_999_999

	cases := []struct {
		ten   string
		duong string
		la    string
		than  map[string]any
	}{
		{
			"sản phẩm đang bán, đặt lại 'đang bán'",
			fmt.Sprintf("/api/v1/admin/products/%d/status", a.sanPham),
			fmt.Sprintf("/api/v1/admin/products/%d/status", idLa),
			map[string]any{"status": "active"},
		},
		{
			"khuyến mãi đang bật, bật lại",
			fmt.Sprintf("/api/v1/admin/promotions/%d/status", a.khuyenMai),
			fmt.Sprintf("/api/v1/admin/promotions/%d/status", idLa),
			map[string]any{"is_active": true},
		},
		{
			"voucher đang bật, bật lại",
			fmt.Sprintf("/api/v1/admin/vouchers/%d/status", a.voucher),
			fmt.Sprintf("/api/v1/admin/vouchers/%d/status", idLa),
			map[string]any{"is_active": true},
		},
		{
			"banner đang bật, bật lại",
			fmt.Sprintf("/api/v1/admin/banners/%d/status", a.banner),
			fmt.Sprintf("/api/v1/admin/banners/%d/status", idLa),
			map[string]any{"is_active": true},
		},
	}

	for _, x := range cases {
		t.Run(x.ten, func(t *testing.T) {
			for lan := 1; lan <= 2; lan++ {
				res := h.goi(t, a.token, http.MethodPut, x.duong, x.than)
				if res.ma != http.StatusOK {
					t.Fatalf("lần %d phải trả 200, nhận %d\n%s", lan, res.ma, catBot(res.than))
				}
			}

			if res := h.goi(t, a.token, http.MethodPut, x.la, x.than); res.ma != http.StatusNotFound {
				t.Fatalf("id không tồn tại vẫn phải là 404, nhận %d\n%s", res.ma, catBot(res.than))
			}
		})
	}

	// Sửa mà không đổi gì cũng vậy: bấm Lưu trên biểu mẫu chưa gõ thêm chữ nào là
	// thao tác người dùng làm suốt.
	t.Run("lưu voucher mà không sửa gì", func(t *testing.T) {
		than := map[string]any{
			"code": "voucher-" + a.vet, "discount_type": "fixed", "discount_value": 10000,
		}
		duong := fmt.Sprintf("/api/v1/admin/vouchers/%d", a.voucher)

		for lan := 1; lan <= 2; lan++ {
			if res := h.goi(t, a.token, http.MethodPut, duong, than); res.ma != http.StatusOK {
				t.Fatalf("lần %d phải trả 200, nhận %d\n%s", lan, res.ma, catBot(res.than))
			}
		}
		if res := h.goi(t, a.token, http.MethodPut,
			fmt.Sprintf("/api/v1/admin/vouchers/%d", idLa), than); res.ma != http.StatusNotFound {
			t.Fatalf("id không tồn tại vẫn phải là 404, nhận %d\n%s", res.ma, catBot(res.than))
		}
	})
}
