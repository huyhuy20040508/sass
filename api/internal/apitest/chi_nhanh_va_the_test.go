package apitest

import (
	"encoding/json"
	"fmt"
	"net/http"
	"testing"
)

// Bài kiểm CHI NHÁNH QUẢN LÝ + THẺ HÀNG HÓA.
//
// Chạy qua API thật + MySQL thật vì cả hai cụm đều nằm ở BẢNG NỐI, và bảng nối
// là chỗ chỉ hỏng khi chạy thật:
//
//   - hai bảng ấy có tenant_id NOT NULL, mà GORM tự lưu quan hệ many2many thì
//     chèn thẳng vào bảng nối, không đi qua plugin đóng dấu cửa hàng. Kiểm ở
//     tầng service thì kho là bản giả, và bản giả thì luôn ghi được;
//   - thẻ gửi lên bằng TÊN chứ không phải id, nên chỗ "tên đã có thì dùng lại
//     dòng cũ" phải đụng đúng khoá UNIQUE của database mới nói lên điều gì.

type sanPhamCoChiNhanh struct {
	ID    uint `json:"id"`
	Shops []struct {
		ID   uint   `json:"id"`
		Name string `json:"name"`
	} `json:"shops"`
	Tags []struct {
		ID   uint   `json:"id"`
		Name string `json:"name"`
	} `json:"tags"`
}

// themSanPhamGan tạo một mặt hàng kèm chi nhánh + thẻ. shopIDs/tags = nil thì
// KHÔNG gửi khoá ấy (kiểm nhánh "màn hình không nắm được cụm này").
func themSanPhamGan(t *testing.T, h *heThong, c *cuaHang, ten string, shopIDs []uint, tags []string) (int, sanPhamCoChiNhanh) {
	t.Helper()

	body := map[string]any{
		"category_id": c.danhMuc,
		"name":        ten + " " + c.vet,
		"slug":        slugTest(ten) + "-" + c.vet,
		"sku":         "SP-" + slugTest(ten) + "-" + c.vet,
		"base_price":  100000,
		"status":      "active",
		"variants": []map[string]any{
			{"name": "", "sku": "SP-" + slugTest(ten) + "-" + c.vet + "-1"},
		},
	}
	if shopIDs != nil {
		body["shop_ids"] = shopIDs
	}
	if tags != nil {
		body["tags"] = tags
	}

	res := h.goi(t, c.token, http.MethodPost, "/api/v1/admin/products", body)

	var out struct {
		Data sanPhamCoChiNhanh `json:"data"`
	}
	_ = json.Unmarshal([]byte(res.than), &out)

	return res.ma, out.Data
}

// TestChiNhanhQuanLy_GanVaDocLai — tick chi nhánh lúc khai thì đọc lại phải còn.
func TestChiNhanhQuanLy_GanVaDocLai(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	ma, sp := themSanPhamGan(t, h, a, "noi lau", []uint{a.chiNhanh}, nil)
	if ma != http.StatusCreated && ma != http.StatusOK {
		t.Fatalf("tạo mặt hàng trả %d", ma)
	}
	if len(sp.Shops) != 1 || sp.Shops[0].ID != a.chiNhanh {
		t.Fatalf("phải gán đúng một chi nhánh %d, đang là %+v", a.chiNhanh, sp.Shops)
	}

	// Không tick chi nhánh nào = MỌI chi nhánh, tức không dòng nào ở bảng nối.
	// Đây là quy ước cố ý, không phải dữ liệu thiếu.
	_, moi := themSanPhamGan(t, h, a, "chao chong dinh", []uint{}, nil)
	if len(moi.Shops) != 0 {
		t.Fatalf("không tick gì thì không được gán chi nhánh nào, đang là %+v", moi.Shops)
	}
}

// TestChiNhanhQuanLy_KhongGanTromCuaHangKhac — id chi nhánh của cửa hàng khác
// phải bị từ chối, không phải lặng lẽ gán vào.
func TestChiNhanhQuanLy_KhongGanTromCuaHangKhac(t *testing.T) {
	h := dungHeThong(t)
	a, b := haiCuaHang(t, h)

	ma, _ := themSanPhamGan(t, h, a, "hang tron", []uint{b.chiNhanh}, nil)
	if ma == http.StatusCreated || ma == http.StatusOK {
		t.Fatalf("gán được chi nhánh của cửa hàng khác — phải bị từ chối, đang trả %d", ma)
	}
}

// TestTheHangHoa_TenTrungThiDungLaiTheCu — gõ lại đúng tên thẻ đã có thì phải
// TRÚNG dòng cũ, không đẻ thêm thẻ.
//
// Đây là toàn bộ lý do thẻ là bảng tra chứ không phải chuỗi tự do: để tự do thì
// "Món mới" và "món mới" thành hai thẻ, và dãy phím lọc ngoài quầy đầy thẻ rác.
func TestTheHangHoa_TenTrungThiDungLaiTheCu(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	_, mot := themSanPhamGan(t, h, a, "ca phe sua", nil, []string{"Bán chạy nhất", "Món mới"})
	if len(mot.Tags) != 2 {
		t.Fatalf("phải dán được hai thẻ, đang là %+v", mot.Tags)
	}

	// Mặt hàng thứ hai gõ lại một tên cũ (khác hoa thường, thừa khoảng trắng) và
	// một tên mới.
	_, hai := themSanPhamGan(t, h, a, "tra sua", nil, []string{"  món   mới ", "Hàng order"})
	if len(hai.Tags) != 2 {
		t.Fatalf("phải dán được hai thẻ, đang là %+v", hai.Tags)
	}

	idMonMoi := uint(0)
	for _, tg := range mot.Tags {
		if tg.Name == "Món mới" {
			idMonMoi = tg.ID
		}
	}
	dungLai := false
	for _, tg := range hai.Tags {
		if tg.ID == idMonMoi {
			dungLai = true
		}
	}
	if !dungLai {
		t.Fatalf("gõ lại tên thẻ cũ phải trúng thẻ %d, đang là %+v", idMonMoi, hai.Tags)
	}

	// Sổ thẻ của cửa hàng có đúng BA thẻ: hai thẻ đầu + "Hàng order".
	res := h.goi(t, a.token, http.MethodGet, "/api/v1/admin/the-hang-hoa", nil)
	if res.ma != http.StatusOK {
		t.Fatalf("đọc sổ thẻ trả %d\n%s", res.ma, catBot(res.than))
	}
	var so struct {
		Data []struct {
			Name string `json:"name"`
		} `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &so); err != nil {
		t.Fatalf("không đọc được sổ thẻ: %v\n%s", err, catBot(res.than))
	}
	if len(so.Data) != 3 {
		t.Fatalf("sổ thẻ phải có 3 thẻ, đang có %d: %+v", len(so.Data), so.Data)
	}
}

// TestChiNhanhVaThe_VangKhoaThiKhongDungToi — lượt sửa không gửi hai khoá ấy
// phải GIỮ NGUYÊN phần đã gán.
//
// Cùng quy ước với `variants` và `images`: mảng rỗng là "gỡ hết", còn vắng khoá
// là "màn hình không nắm được cụm này". Gộp hai nghĩa lại thì mỗi lượt bật/tắt
// trạng thái từ một màn hình không dựng ô ấy sẽ xoá sạch phần đã khai.
func TestChiNhanhVaThe_VangKhoaThiKhongDungToi(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	_, sp := themSanPhamGan(t, h, a, "banh mi", []uint{a.chiNhanh}, []string{"Món mới"})

	// Sửa giá, KHÔNG gửi shop_ids lẫn tags.
	res := h.goi(t, a.token, http.MethodPut, fmt.Sprintf("/api/v1/admin/products/%d", sp.ID), map[string]any{
		"category_id": a.danhMuc,
		"name":        "banh mi " + a.vet,
		"slug":        "banhmi-" + a.vet,
		"base_price":  120000,
	})
	if res.ma != http.StatusOK {
		t.Fatalf("sửa mặt hàng trả %d\n%s", res.ma, catBot(res.than))
	}

	var out struct {
		Data sanPhamCoChiNhanh `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &out); err != nil {
		t.Fatalf("không đọc được mặt hàng: %v\n%s", err, catBot(res.than))
	}
	if len(out.Data.Shops) != 1 || len(out.Data.Tags) != 1 {
		t.Fatalf("vắng khoá thì phải giữ nguyên chi nhánh và thẻ, đang là %+v / %+v",
			out.Data.Shops, out.Data.Tags)
	}
}
