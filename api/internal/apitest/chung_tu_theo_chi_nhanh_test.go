package apitest

import (
	"encoding/json"
	"fmt"
	"net/http"
	"testing"
)

// CHỨNG TỪ CỦA CHI NHÁNH KHÁC THÌ KHÔNG ĐỌC, KHÔNG SỬA, KHÔNG XOÁ ĐƯỢC.
//
// Trước bản sửa này, mọi đường tra một chứng từ đều chỉ lọc theo `id` (cộng điều
// kiện cửa hàng do plugin chèn) — không đường nào hỏi chi nhánh. Mà id chạy tuần
// tự, nên khai thác không cần kỹ thuật gì: nhân viên ghim ở kho 2 gõ
// `/admin/phieu-mua-hang/1`, `/2`, `/3`… là đọc hết giá nhập, nhà cung cấp và
// công nợ của kho 1. PUT và DELETE cũng lọt y như vậy.
//
// Middleware chỉ xác minh cái HEADER chi nhánh có hợp lệ với người gọi hay
// không; nó không biết gì về bản ghi mà request đang với tới. Hai tầng khác
// nhau, và khoảng trống giữa chúng là chỗ lỗi này nằm.

// phieuCuaChiNhanh lập một phiếu mua tại đúng một chi nhánh rồi trả id.
func phieuCuaChiNhanh(t *testing.T, h *heThong, c *cuaHang, shopID uint) uint {
	t.Helper()

	ma, p := lapPhieuTaiChiNhanh(t, h, c.token, shopID, map[string]any{
		"supplier_name": "Cong ty " + c.vet,
		"items": []any{
			map[string]any{"variant_id": c.bienThe, "quantity": 2, "unit_cost": 10000},
		},
	})
	if ma != http.StatusCreated {
		t.Fatalf("lập phiếu tại chi nhánh %d trả %d", shopID, ma)
	}

	return p.ID
}

// Đọc phiếu của kho khác phải bị từ chối, không phải trả nội dung ra.
func TestChungTu_KhoKhacThiKhongDocDuoc(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	kho1 := a.chiNhanh
	kho2 := moChiNhanhThuHai(t, h, a, "Kho hai")

	id := phieuCuaChiNhanh(t, h, a, kho1)

	// Đứng ở kho 1: phiếu của mình, phải đọc được.
	res := h.goiChiNhanh(t, a.token, kho1, http.MethodGet,
		fmt.Sprintf("%s/%d", duongPhieuMua, id), nil)
	if res.ma != http.StatusOK {
		t.Fatalf("phiếu của chính kho mình phải đọc được, nhận %d\n%s", res.ma, catBot(res.than))
	}

	// Đứng ở kho 2: cùng cái id ấy, phải bị chặn.
	res = h.goiChiNhanh(t, a.token, kho2, http.MethodGet,
		fmt.Sprintf("%s/%d", duongPhieuMua, id), nil)
	if res.ma != http.StatusForbidden {
		t.Fatalf("đọc phiếu của kho khác phải bị chặn 403, nhận %d\n%s", res.ma, catBot(res.than))
	}
}

// Sửa và xoá là hai đường nguy hiểm hơn hẳn đường đọc — chặn cả hai.
func TestChungTu_KhoKhacThiKhongSuaXoaDuoc(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	kho1 := a.chiNhanh
	kho2 := moChiNhanhThuHai(t, h, a, "Kho hai")
	id := phieuCuaChiNhanh(t, h, a, kho1)

	than := map[string]any{
		"supplier_name": "Doi ten " + a.vet,
		"items": []any{
			map[string]any{"variant_id": a.bienThe, "quantity": 9, "unit_cost": 10000},
		},
	}
	res := h.goiChiNhanh(t, a.token, kho2, http.MethodPut,
		fmt.Sprintf("%s/%d", duongPhieuMua, id), than)
	if res.ma != http.StatusForbidden {
		t.Fatalf("sửa phiếu của kho khác phải bị chặn 403, nhận %d\n%s", res.ma, catBot(res.than))
	}

	res = h.goiChiNhanh(t, a.token, kho2, http.MethodDelete,
		fmt.Sprintf("%s/%d", duongPhieuMua, id), nil)
	if res.ma != http.StatusForbidden {
		t.Fatalf("xoá phiếu của kho khác phải bị chặn 403, nhận %d\n%s", res.ma, catBot(res.than))
	}

	// Và phiếu vẫn còn nguyên ở kho của nó.
	res = h.goiChiNhanh(t, a.token, kho1, http.MethodGet,
		fmt.Sprintf("%s/%d", duongPhieuMua, id), nil)
	if res.ma != http.StatusOK {
		t.Fatalf("phiếu phải còn nguyên sau hai lượt bị chặn, nhận %d", res.ma)
	}
}

// Chủ tiệm CHƯA CHỌN kho nào thì vẫn xem được mọi phiếu.
//
// Đây là quy ước của mọi lượt đọc trong hệ thống: mơ hồ thì KHÔNG cắt. Thà cho
// xem cả cửa hàng còn hơn giấu mất phiếu của chính mình — và chốt chặn ở trên
// vẫn có tác dụng vì nhân viên bị phân công thì luôn có chi nhánh trong ctx, kể
// cả khi họ gỡ header đi.
func TestChungTu_ChuaChonKhoThiVanXemDuocHet(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	kho1 := a.chiNhanh
	moChiNhanhThuHai(t, h, a, "Kho hai")
	id := phieuCuaChiNhanh(t, h, a, kho1)

	res := h.goi(t, a.token, http.MethodGet, fmt.Sprintf("%s/%d", duongPhieuMua, id), nil)
	if res.ma != http.StatusOK {
		t.Fatalf("chủ tiệm chưa chọn kho phải xem được phiếu, nhận %d\n%s", res.ma, catBot(res.than))
	}
}

// Phiếu điều chuyển có HAI đầu: cả kho gửi lẫn kho nhận đều phải mở được.
func TestChungTu_DieuChuyenMoDuocTuCaHaiDau(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	kho1 := a.chiNhanh
	kho2 := moChiNhanhThuHai(t, h, a, "Kho hai")
	kho3 := moChiNhanhThuHai(t, h, a, "Kho ba")

	res := h.goiChiNhanh(t, a.token, kho1, http.MethodPost, "/api/v1/admin/phieu-dieu-chuyen",
		map[string]any{
			"from_shop_id": kho1,
			"to_shop_id":   kho2,
			"items": []any{
				map[string]any{"variant_id": a.bienThe, "quantity": 1},
			},
		})
	if res.ma != http.StatusCreated {
		t.Fatalf("lập phiếu điều chuyển trả %d\n%s", res.ma, catBot(res.than))
	}

	var body struct {
		Data struct {
			ID uint `json:"id"`
		} `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &body); err != nil {
		t.Fatalf("không đọc được phản hồi: %v", err)
	}
	duong := fmt.Sprintf("/api/v1/admin/phieu-dieu-chuyen/%d", body.Data.ID)

	for _, kho := range []uint{kho1, kho2} {
		res = h.goiChiNhanh(t, a.token, kho, http.MethodGet, duong, nil)
		if res.ma != http.StatusOK {
			t.Fatalf("kho %d là một đầu của phiếu, phải đọc được — nhận %d\n%s",
				kho, res.ma, catBot(res.than))
		}
	}

	// Kho thứ ba không dính dáng gì tới phiếu này.
	res = h.goiChiNhanh(t, a.token, kho3, http.MethodGet, duong, nil)
	if res.ma != http.StatusForbidden {
		t.Fatalf("kho ngoài cuộc phải bị chặn 403, nhận %d\n%s", res.ma, catBot(res.than))
	}
}

// KHÔNG GỬI HEADER LÀ THOÁT — lỗ hổng đứng TRƯỚC mọi chốt chặn ở trên.
//
// Middleware cũ: header rỗng thì `c.Next()` và ctx không mang chi nhánh nào. Mà
// quy ước của các lượt đọc là "không rõ chi nhánh thì không cắt", nên người bị
// ghim vào kho 2 chỉ cần gỡ header đi là mọi chốt chặn phía dưới tự mở ra.
//
// Bài kiểm cho chỗ ấy nằm ở internal/middleware (TestChiNhanhDangLam_*), không
// phải ở đây: khu `manage` — nơi có phiếu mua, phiếu trả, điều chuyển — đòi vai
// trò admin (xem RequireRoles trong router), mà chỉ vai trò `staff` mới bị ghim
// chi nhánh. Viết e2e ở đây thì mọi lượt gọi bị tầng PHÂN QUYỀN chặn 403 trước
// khi chạm tới chốt chặn chi nhánh — bài kiểm vẫn xanh, nhưng xanh vì lý do
// khác hẳn thứ nó định gác, và tắt hẳn chốt chặn đi nó vẫn xanh.
