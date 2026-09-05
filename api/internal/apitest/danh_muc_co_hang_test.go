package apitest

import (
	"encoding/json"
	"net/http"
	"testing"
)

// GET /categories?has_products=true — ô lọc nhóm chỉ được bày nhóm CÓ hàng.
//
// Ô lọc nhóm đứng cạnh một bảng hàng hoá (tồn kho, danh sách hàng hoá). Bày ra
// một nhóm rỗng là mời người dùng bấm vào để nhận một bảng trắng — đúng thứ ô
// lọc sinh ra để tránh. Còn danh sách để CHỌN nhóm lúc khai mặt hàng thì phải
// giữ nguyên mọi nhóm, kể cả nhóm vừa lập chưa có hàng nào, nếu không không
// khai được mặt hàng đầu tiên vào đó.
func TestDanhMuc_LocChiBayNhomCoHang(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	// Nhóm rỗng: lập ra rồi không khai mặt hàng nào vào.
	res := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/categories", map[string]any{
		"name": "Nhom rong " + a.vet,
		"slug": "nhom-rong-" + a.vet,
	})
	if res.ma != http.StatusCreated {
		t.Fatalf("tạo nhóm rỗng phải trả 201, nhận %d\n%s", res.ma, catBot(res.than))
	}

	var dm struct {
		Data struct {
			ID uint `json:"id"`
		} `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &dm); err != nil {
		t.Fatalf("không đọc được phản hồi: %v\n%s", err, catBot(res.than))
	}
	nhomRong := dm.Data.ID

	// Nhóm mặc định của cửa hàng thì có hàng — themSanPhamViTri khai vào c.danhMuc.
	if ma, _ := themSanPhamViTri(t, h, a, "Ao co hang", -1); ma != http.StatusCreated {
		t.Fatalf("tạo mặt hàng phải trả 201, nhận %d", ma)
	}
	nhomCoHang := uint(a.danhMuc)

	doc := func(duong string) map[uint]bool {
		t.Helper()
		r := h.goi(t, a.token, http.MethodGet, duong, nil)
		if r.ma != http.StatusOK {
			t.Fatalf("%s phải trả 200, nhận %d\n%s", duong, r.ma, catBot(r.than))
		}

		var body struct {
			Data []struct {
				ID uint `json:"id"`
			} `json:"data"`
		}
		if err := json.Unmarshal([]byte(r.than), &body); err != nil {
			t.Fatalf("không đọc được %s: %v\n%s", duong, err, catBot(r.than))
		}

		co := make(map[uint]bool, len(body.Data))
		for _, dm := range body.Data {
			co[dm.ID] = true
		}

		return co
	}

	// Danh sách đầy đủ vẫn phải có cả hai — đây là danh sách để CHỌN nhóm.
	day := doc("/api/v1/categories?all=true")
	if !day[nhomRong] || !day[nhomCoHang] {
		t.Fatalf("danh sách đầy đủ phải có cả nhóm rỗng (%d) lẫn nhóm có hàng (%d), nhận %v",
			nhomRong, nhomCoHang, day)
	}

	loc := doc("/api/v1/categories?all=true&has_products=true")
	if loc[nhomRong] {
		t.Fatalf("nhóm rỗng %d không được lọt vào danh sách cho ô lọc", nhomRong)
	}
	if !loc[nhomCoHang] {
		t.Fatalf("nhóm có hàng %d phải nằm trong danh sách cho ô lọc, nhận %v", nhomCoHang, loc)
	}
}
