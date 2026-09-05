package apitest

import (
	"encoding/json"
	"fmt"
	"net/http"
	"strings"
	"testing"
)

// CHỐT CHẶN CỦA CHI NHÁNH Ở ĐƯỜNG GHI.
//
// Hai luật dưới đây từng KHÔNG tồn tại, và cái giá của việc thiếu chúng là dữ
// liệu sai mà không lỗi nào nổi lên — chỉ lộ ra khi có người đếm hàng thật:
//
//  1. Cửa hàng nhiều chi nhánh mà lượt ghi không khai mình đứng ở đâu thì API tự
//     lấy chi nhánh có id NHỎ NHẤT. Mở chi nhánh mới rồi nhập hàng cho nó, hàng
//     chui vào chi nhánh cũ.
//  2. Ô "Chi nhánh" trên form hàng hoá ghi vào `product_shops` nhưng không nơi
//     nào tra lại: gán một món riêng cho chi nhánh A xong, người đứng ở B vẫn
//     nhập và bán món đó, và tồn kho ghi vào B thật.

// moChiNhanhThuHai mở thêm một điểm bán rồi trả id — để cửa hàng có hai chi
// nhánh cùng lúc, tức là lúc câu hỏi "kho nào" mới có hai câu trả lời.
func moChiNhanhThuHai(t *testing.T, h *heThong, c *cuaHang, ten string) uint {
	t.Helper()

	res := h.goi(t, c.token, http.MethodPost, "/api/v1/admin/chi-nhanh", map[string]any{
		"name": ten + " " + c.vet, "phone": "0912345678", "address": "Ha Noi",
	})
	if res.ma != http.StatusCreated {
		t.Fatalf("mở chi nhánh thứ hai trả %d\n%s", res.ma, catBot(res.than))
	}

	var body struct {
		Data struct {
			ID uint `json:"id"`
		} `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &body); err != nil {
		t.Fatalf("không đọc được phản hồi mở chi nhánh: %v\n%s", err, catBot(res.than))
	}

	return body.Data.ID
}

// Cửa hàng MỘT chi nhánh thì không phải chọn gì: màn hình không có ô nào để
// chọn, và câu hỏi "kho nào" chỉ có một câu trả lời. Nhánh này phải êm.
func TestGhiKho_MotChiNhanhThiKhongCanKhai(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	ma, _ := lapPhieu(t, h, a.token, map[string]any{
		"supplier_name": "Cong ty " + a.vet,
		"items":         []any{map[string]any{"variant_id": a.bienThe, "quantity": 1, "unit_cost": 10000}},
	})
	if ma != http.StatusCreated {
		t.Fatalf("cửa hàng một chi nhánh lập phiếu phải được, nhận %d", ma)
	}
}

// Hai chi nhánh mà không khai mình đứng ở đâu: TỪ CHỐI, không đoán.
func TestGhiKho_HaiChiNhanhMaKhongKhaiThiTuChoi(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	moChiNhanhThuHai(t, h, a, "Kho hai")

	ma, _ := lapPhieu(t, h, a.token, map[string]any{
		"supplier_name": "Cong ty " + a.vet,
		"items":         []any{map[string]any{"variant_id": a.bienThe, "quantity": 1, "unit_cost": 10000}},
	})
	if ma != http.StatusConflict {
		t.Fatalf("hai chi nhánh mà không khai chi nhánh phải trả 409, nhận %d", ma)
	}

	// Khai rõ thì đi tiếp bình thường — chốt này chặn sự mơ hồ, không chặn việc.
	ma2, _ := lapPhieuTaiChiNhanh(t, h, a.token, a.chiNhanh, map[string]any{
		"supplier_name": "Cong ty " + a.vet,
		"items":         []any{map[string]any{"variant_id": a.bienThe, "quantity": 1, "unit_cost": 10000}},
	})
	if ma2 != http.StatusCreated {
		t.Fatalf("khai rõ chi nhánh thì phải lập được phiếu, nhận %d", ma2)
	}
}

// Mặt hàng đã gán riêng cho chi nhánh khác thì không nhập vào kho này được.
func TestGhiKho_KhongNhapHangCuaChiNhanhKhac(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	kho2 := moChiNhanhThuHai(t, h, a, "Kho hai")

	// Gán mặt hàng RIÊNG cho kho thứ hai. Từ lúc này chi nhánh gốc không còn
	// dính dáng gì tới nó.
	// PUT là ghi ĐÈ cả hồ sơ nên phải gửi kèm ba ô bắt buộc — đọc lại mặt hàng
	// rồi chép sang, thay vì bịa ra tên mới.
	cu := h.goiChiNhanh(t, a.token, a.chiNhanh, http.MethodGet,
		fmt.Sprintf("/api/v1/admin/products/%d", a.sanPham), nil)
	if cu.ma != http.StatusOK {
		t.Fatalf("đọc lại mặt hàng trả %d", cu.ma)
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

	res := h.goiChiNhanh(t, a.token, a.chiNhanh, http.MethodPut,
		fmt.Sprintf("/api/v1/admin/products/%d", a.sanPham), map[string]any{
			"name":        hh.Data.Name,
			"slug":        hh.Data.Slug,
			"category_id": hh.Data.CategoryID,
			"shop_ids":    []uint{kho2},
		})
	if res.ma != http.StatusOK {
		t.Fatalf("gán chi nhánh cho mặt hàng trả %d\n%s", res.ma, catBot(res.than))
	}

	ma, _ := lapPhieuTaiChiNhanh(t, h, a.token, a.chiNhanh, map[string]any{
		"supplier_name": "Cong ty " + a.vet,
		"items":         []any{map[string]any{"variant_id": a.bienThe, "quantity": 1, "unit_cost": 10000}},
	})
	if ma != http.StatusUnprocessableEntity {
		t.Fatalf("nhập hàng của chi nhánh khác phải trả 422, nhận %d", ma)
	}

	// Đúng kho của nó thì nhập được.
	ma2, _ := lapPhieuTaiChiNhanh(t, h, a.token, kho2, map[string]any{
		"supplier_name": "Cong ty " + a.vet,
		"items":         []any{map[string]any{"variant_id": a.bienThe, "quantity": 1, "unit_cost": 10000}},
	})
	if ma2 != http.StatusCreated {
		t.Fatalf("nhập vào đúng chi nhánh của mặt hàng phải được, nhận %d", ma2)
	}
}

// Header trỏ vào một chi nhánh ĐÃ ĐÓNG: từ chối ngay ở middleware.
//
// Ô chọn trên giao diện chỉ bày chi nhánh đang mở, nhưng đó là phép lịch sự chứ
// không phải hàng rào — gửi thẳng header là ghi hàng vào một kho đã đóng cửa.
func TestChiNhanh_HeaderTroVaoChiNhanhDaDongThiTuChoi(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	kho2 := moChiNhanhThuHai(t, h, a, "Kho dong")

	res := h.goi(t, a.token, http.MethodPut, fmt.Sprintf("/api/v1/admin/chi-nhanh/%d", kho2),
		map[string]any{"name": "Kho dong " + a.vet, "is_active": false})
	if res.ma != http.StatusOK {
		t.Fatalf("đóng chi nhánh trả %d\n%s", res.ma, catBot(res.than))
	}

	res2 := h.goiChiNhanh(t, a.token, kho2, http.MethodGet, "/api/v1/admin/chi-nhanh", nil)
	if res2.ma != http.StatusForbidden {
		t.Fatalf("header trỏ chi nhánh đã đóng phải trả 403, nhận %d\n%s", res2.ma, catBot(res2.than))
	}
	if !strings.Contains(res2.than, "ngừng hoạt động") {
		t.Fatalf("câu trả lời phải nói rõ chi nhánh đã ngừng hoạt động, nhận: %s", catBot(res2.than))
	}
}
