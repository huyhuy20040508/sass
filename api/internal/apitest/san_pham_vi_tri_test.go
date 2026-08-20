package apitest

import (
	"encoding/json"
	"fmt"
	"net/http"
	"testing"
)

// Bài kiểm GẮN VỊ TRÍ VÀO SẢN PHẨM.
//
// Vị trí là bảng tra đầu tiên của khu Hàng hóa thật sự móc vào mặt hàng, nên
// những chỗ dễ hỏng ở đây đều là chỗ chỉ lộ ra khi chạy thật:
//
//   - `location_id` theo quy ước CON TRỎ: vắng mặt = giữ nguyên, 0 = gỡ ra. Gộp
//     hai nghĩa ấy lại là mọi lượt Lưu từ một màn hình không dựng được ô Vị trí
//     sẽ gỡ vị trí trong im lặng.
//   - Vị trí của cửa hàng KHÁC không gán trộm được.
//   - Vị trí còn hàng trỏ tới thì không xoá được — nếu không, mặt hàng mất chỗ
//     mà không ai biết, và khoá ngoại gãy.
//   - Cờ `in_use` phải đúng cả hai chiều, vì màn quản trị xám nút xoá theo nó.

// sanPhamCoViTri là phần thân sản phẩm mà bài kiểm này quan tâm.
type sanPhamCoViTri struct {
	ID         uint  `json:"id"`
	LocationID *uint `json:"location_id"`
	Location   *struct {
		ID   uint   `json:"id"`
		Code string `json:"code"`
		Name string `json:"name"`
	} `json:"location"`
}

// themSanPhamViTri tạo một sản phẩm, kèm hoặc không kèm vị trí.
//
// locID < 0 nghĩa là KHÔNG gửi trường location_id (kiểm nhánh "vắng mặt").
func themSanPhamViTri(t *testing.T, h *heThong, c *cuaHang, ten string, locID int) (int, sanPhamCoViTri) {
	t.Helper()

	body := map[string]any{
		"category_id": c.danhMuc,
		"name":        ten + " " + c.vet,
		"slug":        slugTest(ten) + "-" + c.vet,
		"sku":         "SP-" + slugTest(ten) + "-" + c.vet,
		"base_price":  100000,
		"status":      "active",
		"variants": []map[string]any{
			{"size": "M", "sku": "SP-" + slugTest(ten) + "-" + c.vet + "-M"},
		},
	}
	if locID >= 0 {
		body["location_id"] = locID
	}

	res := h.goi(t, c.token, http.MethodPost, "/api/v1/admin/products", body)

	var out struct {
		Data sanPhamCoViTri `json:"data"`
	}
	_ = json.Unmarshal([]byte(res.than), &out)

	return res.ma, out.Data
}

// slugTest rút gọn tên thành chuỗi dùng được cho slug/SKU trong bài kiểm.
func slugTest(s string) string {
	out := make([]rune, 0, len(s))
	for _, r := range s {
		switch {
		case r >= 'a' && r <= 'z', r >= '0' && r <= '9':
			out = append(out, r)
		case r >= 'A' && r <= 'Z':
			out = append(out, r+('a'-'A'))
		}
	}

	return string(out)
}

// docSanPhamViTri đọc lại một sản phẩm qua đường chi tiết.
func docSanPhamViTri(t *testing.T, h *heThong, c *cuaHang, id uint) sanPhamCoViTri {
	t.Helper()

	res := h.goi(t, c.token, http.MethodGet, fmt.Sprintf("/api/v1/admin/products/%d", id), nil)
	if res.ma != http.StatusOK {
		t.Fatalf("đọc chi tiết sản phẩm phải trả 200, nhận %d\n%s", res.ma, catBot(res.than))
	}

	var out struct {
		Data sanPhamCoViTri `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &out); err != nil {
		t.Fatalf("không đọc được phản hồi: %v\n%s", err, catBot(res.than))
	}

	return out.Data
}

// TestSanPhamViTri_GanRoiDocLaiKemTenViTri — gán xong thì đường đọc phải trả kèm
// CẢ tên vị trí, không chỉ id. Bảng danh sách hiện mã/tên vị trí ngay trên dòng;
// thiếu preload là mỗi dòng một lượt gọi thêm hoặc một ô trống.
func TestSanPhamViTri_GanRoiDocLaiKemTenViTri(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	_, vt := themViTri(t, h, a.token, "KEA1", "Kệ A - Tầng 1")

	ma, sp := themSanPhamViTri(t, h, a, "Ao co vi tri", int(vt.ID))
	if ma != http.StatusCreated {
		t.Fatalf("tạo sản phẩm kèm vị trí phải trả 201, nhận %d", ma)
	}

	doc := docSanPhamViTri(t, h, a, sp.ID)
	if doc.LocationID == nil || *doc.LocationID != vt.ID {
		t.Fatalf("sản phẩm phải giữ đúng location_id %d, nhận %+v", vt.ID, doc.LocationID)
	}
	if doc.Location == nil || doc.Location.Code != "KEA1" || doc.Location.Name != "Kệ A - Tầng 1" {
		t.Fatalf("đường đọc phải trả kèm mã và tên vị trí, nhận %+v", doc.Location)
	}
}

// TestSanPhamViTri_KhongGuiThiGiuNguyen_GuiKhongThiGoRa — hai nghĩa của con trỏ,
// và là chỗ dễ hỏng nhất: gộp chúng lại thì mọi lượt Lưu từ một màn hình cũ
// (chưa dựng ô Vị trí, không gửi trường này) sẽ gỡ vị trí trong im lặng.
func TestSanPhamViTri_KhongGuiThiGiuNguyen_GuiKhongThiGoRa(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	_, vt := themViTri(t, h, a.token, "KEA1", "Kệ A - Tầng 1")
	_, sp := themSanPhamViTri(t, h, a, "Ao thu", int(vt.ID))

	suaTen := func(body map[string]any) {
		t.Helper()
		body["category_id"] = a.danhMuc
		body["name"] = "Ao thu doi ten " + a.vet
		body["slug"] = "ao-thu-doi-ten-" + a.vet
		body["base_price"] = 100000
		res := h.goi(t, a.token, http.MethodPut, fmt.Sprintf("/api/v1/admin/products/%d", sp.ID), body)
		if res.ma != http.StatusOK {
			t.Fatalf("sửa sản phẩm phải trả 200, nhận %d\n%s", res.ma, catBot(res.than))
		}
	}

	// Lượt 1: KHÔNG gửi location_id -> vị trí phải còn nguyên.
	suaTen(map[string]any{})
	if doc := docSanPhamViTri(t, h, a, sp.ID); doc.LocationID == nil || *doc.LocationID != vt.ID {
		t.Fatalf("không gửi location_id thì phải giữ nguyên vị trí, nhận %+v", doc.LocationID)
	}

	// Lượt 2: gửi 0 -> gỡ vị trí ra.
	suaTen(map[string]any{"location_id": 0})
	if doc := docSanPhamViTri(t, h, a, sp.ID); doc.LocationID != nil {
		t.Fatalf("gửi location_id = 0 phải gỡ vị trí, nhận %+v", doc.LocationID)
	}
}

// TestSanPhamViTri_KhongGanDuocViTriCuaCuaHangKhac — bộ lọc tenant nằm dưới
// GORM, nên lượt tra vị trí của cửa hàng khác rơi vào ErrNotFound. Không có lượt
// tra ấy thì id lạ ghi thẳng vào cột và mặt hàng trỏ sang kho của tiệm khác.
func TestSanPhamViTri_KhongGanDuocViTriCuaCuaHangKhac(t *testing.T) {
	h := dungHeThong(t)
	a, b := haiCuaHang(t, h)

	_, cuaB := themViTri(t, h, b.token, "KHOLANH", "Kho lạnh")

	ma, _ := themSanPhamViTri(t, h, a, "Ao gan trom", int(cuaB.ID))
	if ma != http.StatusNotFound {
		t.Fatalf("gán vị trí của cửa hàng khác phải trả 404, nhận %d", ma)
	}
}

// TestSanPhamViTri_ViTriConHangThiKhongXoaDuoc — chặn ở tầng nghiệp vụ, trả 409.
// Gỡ hàng ra khỏi vị trí rồi thì xoá lại được.
func TestSanPhamViTri_ViTriConHangThiKhongXoaDuoc(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	_, vt := themViTri(t, h, a.token, "KEA1", "Kệ A - Tầng 1")
	_, sp := themSanPhamViTri(t, h, a, "Ao giu cho", int(vt.ID))

	duong := fmt.Sprintf("/api/v1/admin/vi-tri/%d", vt.ID)
	if res := h.goi(t, a.token, http.MethodDelete, duong, nil); res.ma != http.StatusConflict {
		t.Fatalf("xoá vị trí còn hàng phải trả 409, nhận %d\n%s", res.ma, catBot(res.than))
	}

	// Gỡ hàng khỏi vị trí rồi thì xoá được.
	res := h.goi(t, a.token, http.MethodPut, fmt.Sprintf("/api/v1/admin/products/%d", sp.ID), map[string]any{
		"category_id": a.danhMuc,
		"name":        "Ao giu cho " + a.vet,
		"slug":        "ao-giu-cho-" + a.vet,
		"base_price":  100000,
		"location_id": 0,
	})
	if res.ma != http.StatusOK {
		t.Fatalf("gỡ vị trí khỏi sản phẩm phải trả 200, nhận %d\n%s", res.ma, catBot(res.than))
	}

	if res := h.goi(t, a.token, http.MethodDelete, duong, nil); res.ma != http.StatusOK {
		t.Fatalf("vị trí đã hết hàng phải xoá được, nhận %d\n%s", res.ma, catBot(res.than))
	}
}

// TestSanPhamViTri_CoInUseChoManQuanTri — màn quản trị xám nút xoá theo cờ này,
// nên nó phải đúng ở CẢ HAI chiều: có hàng thì true, chưa có thì false.
func TestSanPhamViTri_CoInUseChoManQuanTri(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	_, coHang := themViTri(t, h, a.token, "KEA1", "Kệ A - Tầng 1")
	_, trong := themViTri(t, h, a.token, "KEB2", "Kệ B - Tầng 2")
	themSanPhamViTri(t, h, a, "Ao tren ke a", int(coHang.ID))

	res := h.goi(t, a.token, http.MethodGet, "/api/v1/admin/vi-tri", nil)
	if res.ma != http.StatusOK {
		t.Fatalf("đọc danh sách vị trí phải trả 200, nhận %d", res.ma)
	}

	var body struct {
		Data []struct {
			ID    uint `json:"id"`
			InUse bool `json:"in_use"`
		} `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &body); err != nil {
		t.Fatalf("không đọc được phản hồi: %v\n%s", err, catBot(res.than))
	}

	cf := map[uint]bool{}
	for _, d := range body.Data {
		cf[d.ID] = d.InUse
	}
	if !cf[coHang.ID] {
		t.Fatal("vị trí đang có hàng phải có in_use = true")
	}
	if cf[trong.ID] {
		t.Fatal("vị trí chưa có hàng nào không được đánh in_use")
	}
}

// TestSanPhamViTri_LocTheoViTriVaLocPhanChuaGan — hai bộ lọc RỜI nhau. "Chưa gán
// vị trí" là câu hỏi có thật của người đi soạn hàng (còn món nào chưa biết để
// đâu), nên nó phải là một lựa chọn riêng chứ không phải hệ quả của id = 0.
func TestSanPhamViTri_LocTheoViTriVaLocPhanChuaGan(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	_, vt := themViTri(t, h, a.token, "KEA1", "Kệ A - Tầng 1")
	_, coCho := themSanPhamViTri(t, h, a, "Ao co cho", int(vt.ID))
	// Không gửi location_id: sản phẩm mới, chưa gán chỗ nào.
	_, chuaCho := themSanPhamViTri(t, h, a, "Ao chua co cho", -1)

	ids := func(query string) map[uint]bool {
		t.Helper()
		// Danh sách sản phẩm của khu quản trị đi qua chính đường /products công
		// khai, kèm all=true — không có /admin/products dạng GET danh sách.
		res := h.goi(t, a.token, http.MethodGet, "/api/v1/products?all=true&page_size=100&"+query, nil)
		if res.ma != http.StatusOK {
			t.Fatalf("lọc %q phải trả 200, nhận %d\n%s", query, res.ma, catBot(res.than))
		}

		var body struct {
			Data []struct {
				ID uint `json:"id"`
			} `json:"data"`
		}
		if err := json.Unmarshal([]byte(res.than), &body); err != nil {
			t.Fatalf("không đọc được phản hồi: %v\n%s", err, catBot(res.than))
		}

		ra := map[uint]bool{}
		for _, d := range body.Data {
			ra[d.ID] = true
		}

		return ra
	}

	theoViTri := ids(fmt.Sprintf("location_id=%d", vt.ID))
	if !theoViTri[coCho.ID] || theoViTri[chuaCho.ID] {
		t.Fatalf("lọc theo một vị trí phải ra đúng hàng để ở đó, nhận %v", theoViTri)
	}

	chuaGan := ids("location_id=none")
	if !chuaGan[chuaCho.ID] || chuaGan[coCho.ID] {
		t.Fatalf("lọc \"chưa gán vị trí\" phải ra đúng hàng chưa có chỗ, nhận %v", chuaGan)
	}
}

// TestSanPhamViTri_NhanBanGiuNguyenViTri — nhân bản là để khai nhanh một món
// tương tự, mà món tương tự thì gần như luôn nằm cùng kệ.
func TestSanPhamViTri_NhanBanGiuNguyenViTri(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	_, vt := themViTri(t, h, a.token, "KEA1", "Kệ A - Tầng 1")
	_, goc := themSanPhamViTri(t, h, a, "Ao goc", int(vt.ID))

	res := h.goi(t, a.token, http.MethodPost, fmt.Sprintf("/api/v1/admin/products/%d/duplicate", goc.ID), nil)
	if res.ma != http.StatusCreated && res.ma != http.StatusOK {
		t.Fatalf("nhân bản phải thành công, nhận %d\n%s", res.ma, catBot(res.than))
	}

	var out struct {
		Data sanPhamCoViTri `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &out); err != nil {
		t.Fatalf("không đọc được phản hồi: %v\n%s", err, catBot(res.than))
	}

	if out.Data.LocationID == nil || *out.Data.LocationID != vt.ID {
		t.Fatalf("bản sao phải để cùng chỗ với bản gốc, nhận %+v", out.Data.LocationID)
	}
}

// TestSanPham_DoiDanhMucThiPhaiAn — cùng gốc rễ với bài "gửi 0 phải gỡ vị trí",
// và là lý do nó nằm ở tệp này.
//
// Sản phẩm đi qua FindByID thì Category đã được preload sẵn; GORM lưu quan hệ
// belongs-to TRƯỚC rồi lấy id của nó ghi đè lại khoá ngoại, nên category_id vừa
// đổi bị chính đối tượng Category CŨ gán ngược trở lại. Người dùng bấm Lưu, màn
// hình báo thành công, danh mục vẫn y nguyên — không một tiếng động.
//
// Hỏng như vậy từ trước khi có vị trí; Omit(clause.Associations) ở
// productRepository.Update chữa cả hai.
func TestSanPham_DoiDanhMucThiPhaiAn(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	res := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/categories", map[string]any{
		"name": "Danh muc moi " + a.vet,
		"slug": "dm-moi-" + a.vet,
	})
	if res.ma != http.StatusCreated {
		t.Fatalf("tạo danh mục phải trả 201, nhận %d\n%s", res.ma, catBot(res.than))
	}

	var dm struct {
		Data struct {
			ID uint `json:"id"`
		} `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &dm); err != nil {
		t.Fatalf("không đọc được phản hồi: %v\n%s", err, catBot(res.than))
	}

	_, sp := themSanPhamViTri(t, h, a, "Ao doi danh muc", -1)

	res = h.goi(t, a.token, http.MethodPut, fmt.Sprintf("/api/v1/admin/products/%d", sp.ID), map[string]any{
		"category_id": dm.Data.ID,
		"name":        "Ao doi danh muc " + a.vet,
		"slug":        "ao-doi-danh-muc-" + a.vet,
		"base_price":  100000,
	})
	if res.ma != http.StatusOK {
		t.Fatalf("sửa sản phẩm phải trả 200, nhận %d\n%s", res.ma, catBot(res.than))
	}

	res = h.goi(t, a.token, http.MethodGet, fmt.Sprintf("/api/v1/admin/products/%d", sp.ID), nil)

	var out struct {
		Data struct {
			CategoryID uint `json:"category_id"`
		} `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &out); err != nil {
		t.Fatalf("không đọc được phản hồi: %v\n%s", err, catBot(res.than))
	}
	if out.Data.CategoryID != dm.Data.ID {
		t.Fatalf("đổi danh mục không ăn: muốn %d, nhận %d", dm.Data.ID, out.Data.CategoryID)
	}
}
