package apitest

import (
	"encoding/json"
	"fmt"
	"net/http"
	"testing"
)

// HAI NGƯỜI CÙNG SỬA MỘT PHIẾU THÌ NGƯỜI SAU KHÔNG ĐƯỢC XOÁ SẠCH VIỆC NGƯỜI TRƯỚC.
//
// Khoá dòng trong giao dịch giữ cho dữ liệu không rách, nhưng nó không thấy được
// chuyện này: A và B cùng mở phiếu lúc 09:00, A lưu lúc 09:05, B lưu lúc 09:07 —
// và lượt lưu của B mang theo TOÀN BỘ danh sách hàng nó đọc được lúc 09:00, nên
// nó ghi đè chứ không phải sửa thêm. Phần A vừa nhập biến mất, không lỗi nào nổi
// lên, và cả hai đều tin phiếu đang đúng.
//
// Xem thêm docblock ở internal/service/chong_ghi_de.go.

func TestChongGhiDe_LuuBangMocCuThiBiChan(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	ma, p := lapPhieu(t, h, a.token, map[string]any{
		"supplier_name": "Cong ty " + a.vet,
		"items": []any{
			map[string]any{"variant_id": a.bienThe, "quantity": 1, "unit_cost": 10000},
		},
	})
	if ma != http.StatusCreated {
		t.Fatalf("lập phiếu trả %d", ma)
	}
	duong := fmt.Sprintf("%s/%d", duongPhieuMua, p.ID)

	// Cả hai người cùng mở phiếu và đọc được CÙNG một mốc.
	docMoc := func() string {
		res := h.goi(t, a.token, http.MethodGet, duong, nil)
		if res.ma != http.StatusOK {
			t.Fatalf("đọc phiếu trả %d\n%s", res.ma, catBot(res.than))
		}
		var body struct {
			Data struct {
				UpdatedAt string `json:"updated_at"`
			} `json:"data"`
		}
		if err := json.Unmarshal([]byte(res.than), &body); err != nil {
			t.Fatalf("không đọc được mốc: %v", err)
		}
		if body.Data.UpdatedAt == "" {
			t.Fatal("phản hồi chi tiết phải mang updated_at — thiếu nó thì chốt chặn này tắt trong im lặng")
		}

		return body.Data.UpdatedAt
	}

	mocChung := docMoc()

	suaVoi := func(moc string, sl int) traLoi {
		return h.goi(t, a.token, http.MethodPut, duong, map[string]any{
			"supplier_name": "Cong ty " + a.vet,
			"updated_at":    moc,
			"items": []any{
				map[string]any{"variant_id": a.bienThe, "quantity": sl, "unit_cost": 10000},
			},
		})
	}

	// A lưu trước bằng mốc chung: được.
	if res := suaVoi(mocChung, 5); res.ma != http.StatusOK {
		t.Fatalf("người lưu trước phải thành công, nhận %d\n%s", res.ma, catBot(res.than))
	}

	// B lưu sau, vẫn cầm mốc CŨ: phải bị chặn 409, không phải lặng lẽ ghi đè.
	res := suaVoi(mocChung, 99)
	if res.ma != http.StatusConflict {
		t.Fatalf("người lưu sau cầm mốc cũ phải bị chặn 409, nhận %d\n%s", res.ma, catBot(res.than))
	}

	// Và phần A vừa lưu còn nguyên — đây mới là điều bài kiểm này bảo vệ.
	sau := docPhieu(t, h, a.token, p.ID)
	if len(sau.Items) != 1 || sau.Items[0].Quantity != 5 {
		t.Fatalf("phiếu phải giữ nguyên số của người lưu trước (5), nhận: %+v", sau.Items)
	}

	// Mở lại phiếu lấy mốc mới thì lưu được — đúng lối thoát mà thông báo lỗi chỉ ra.
	if res := suaVoi(docMoc(), 99); res.ma != http.StatusOK {
		t.Fatalf("mở lại lấy mốc mới rồi lưu phải được, nhận %d\n%s", res.ma, catBot(res.than))
	}
}

// KHÔNG khai mốc thì KHÔNG kiểm — giao diện bản cũ chưa gửi trường này, và chặn
// chúng lại là khoá luôn đường sửa phiếu của những màn chưa kịp cập nhật.
func TestChongGhiDe_KhongKhaiMocThiVanLuuDuoc(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	ma, p := lapPhieu(t, h, a.token, map[string]any{
		"supplier_name": "Cong ty " + a.vet,
		"items": []any{
			map[string]any{"variant_id": a.bienThe, "quantity": 1, "unit_cost": 10000},
		},
	})
	if ma != http.StatusCreated {
		t.Fatalf("lập phiếu trả %d", ma)
	}

	res := h.goi(t, a.token, http.MethodPut, fmt.Sprintf("%s/%d", duongPhieuMua, p.ID),
		map[string]any{
			"supplier_name": "Cong ty " + a.vet,
			"items": []any{
				map[string]any{"variant_id": a.bienThe, "quantity": 7, "unit_cost": 10000},
			},
		})
	if res.ma != http.StatusOK {
		t.Fatalf("không khai mốc thì vẫn phải lưu được, nhận %d\n%s", res.ma, catBot(res.than))
	}
}
