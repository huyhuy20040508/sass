package apitest

import (
	"encoding/json"
	"fmt"
	"net/http"
	"testing"
)

// LUỒNG TRỌN VẸN CỦA MỘT CỬA HÀNG HAI CHI NHÁNH.
//
// Mọi bài kiểm khác chỉ soi một mảnh: bài này đi hết một vòng đời hàng hoá —
// nhập, bán, điều chuyển, bán tiếp — rồi ĐỐI CHIẾU SỐ Ở CẢ HAI BÊN sau từng
// bước.
//
// Vì sao cần một bài như vậy dù từng mảnh đã có bài riêng: các chốt chi nhánh
// nằm rải ở năm chỗ khác nhau (header, đường ghi kho, bộ lọc danh sách, sổ kho,
// bảng giá). Mỗi chỗ đúng riêng lẻ vẫn có thể ghép lại thành sai — kiểu sai chỉ
// lộ ra khi đi liền một mạch, và ngoài đời nó lộ ra lúc có người đếm hàng thật.

// khoCua đọc tồn của một biến thể tại một chi nhánh — tên ngắn để phần khẳng
// định bên dưới đọc được thành câu.
func khoCua(t *testing.T, h *heThong, c *cuaHang, shopID uint) int {
	t.Helper()

	return tonCua(t, h, c, shopID, c.bienThe)
}

// demChungTu đếm số dòng của một danh sách chứng từ khi đứng ở một chi nhánh.
//
// Không gửi `shop_id`: đó chính là thứ cần kiểm — API phải TỰ cắt theo chi nhánh
// đang làm việc, chứ không chờ giao diện truyền lên.
func demChungTu(t *testing.T, h *heThong, c *cuaHang, shopID uint, duong string) int {
	t.Helper()

	res := h.goiChiNhanh(t, c.token, shopID, http.MethodGet, duong+"?page_size=100", nil)
	if res.ma != http.StatusOK {
		t.Fatalf("đọc %s ở chi nhánh %d trả %d\n%s", duong, shopID, res.ma, catBot(res.than))
	}

	var body struct {
		Data []struct {
			ID uint `json:"id"`
		} `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &body); err != nil {
		t.Fatalf("không đọc được %s: %v", duong, err)
	}

	return len(body.Data)
}

// soBuToanKho đếm bút toán trong SỔ KHO của một biến thể tại một chi nhánh.
//
// Sổ kho là chỗ đối chiếu cuối cùng: con số tồn có thể đúng do may mắn (hai lỗi
// bù nhau), còn sổ thì phải kể đúng từng lượt hàng vào ra.
func soBuToanKho(t *testing.T, h *heThong, c *cuaHang, shopID uint) int {
	t.Helper()

	res := h.goiChiNhanh(t, c.token, shopID, http.MethodGet,
		fmt.Sprintf("/api/v1/admin/inventory/%d/history?shop_id=%d&page_size=100", c.bienThe, shopID), nil)
	if res.ma != http.StatusOK {
		t.Fatalf("đọc sổ kho chi nhánh %d trả %d\n%s", shopID, res.ma, catBot(res.than))
	}

	var body struct {
		Data []struct {
			ShopID uint `json:"shop_id"`
		} `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &body); err != nil {
		t.Fatalf("không đọc được sổ kho: %v", err)
	}

	// Mọi dòng phải thuộc ĐÚNG kho đang xem. Sổ lẫn dòng của kho khác thì cặp số
	// "trước → sau" trên màn hình mâu thuẫn với chính con số tồn ngay cạnh nó.
	for _, d := range body.Data {
		if d.ShopID != shopID {
			t.Fatalf("sổ kho của chi nhánh %d lẫn dòng của chi nhánh %d", shopID, d.ShopID)
		}
	}

	return len(body.Data)
}

// Đi hết một vòng và soi số sau từng bước.
func TestLuongHaiChiNhanh_SoLieuDungOCaHaiBen(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	kho1 := a.chiNhanh
	kho2 := moChiNhanhThuHai(t, h, a, "Kho hai")

	donKho(t, h, a)

	// ---------- Bước 1: NHẬP 20 vào kho 1 ----------
	nhapLo(t, h, a, "LO-LUONG", 20, "2031-01-31")

	if got := khoCua(t, h, a, kho1); got != 20 {
		t.Fatalf("B1 kho gửi phải có 20, nhận %d", got)
	}
	if got := khoCua(t, h, a, kho2); got != 0 {
		t.Fatalf("B1 kho hai CHƯA nhận gì mà đã có %d — hàng nhập vào kho một đang chảy sang kho khác", got)
	}

	// ---------- Bước 2: BÁN 5 ở kho 1 ----------
	if res := h.goiChiNhanh(t, a.token, kho1, http.MethodPost, "/api/v1/admin/orders/pos", map[string]any{
		"payment_method": "cash", "amount_tendered": 10_000_000, "customer_name": "Khách lẻ",
		"items": []map[string]any{{"product_variant_id": a.bienThe, "quantity": 5}},
	}); res.ma != http.StatusCreated {
		t.Fatalf("B2 bán ở kho một trả %d\n%s", res.ma, catBot(res.than))
	}

	if got := khoCua(t, h, a, kho1); got != 15 {
		t.Fatalf("B2 kho một phải còn 15, nhận %d", got)
	}
	if got := khoCua(t, h, a, kho2); got != 0 {
		t.Fatalf("B2 bán ở kho một mà kho hai đổi số: %d", got)
	}

	// ---------- Bước 3: ĐIỀU CHUYỂN 6 sang kho 2 ----------
	_, idPhieu := lapDieuChuyen(t, h, a, map[string]any{
		"from_shop_id": kho1, "to_shop_id": kho2,
		"items": []any{map[string]any{"variant_id": a.bienThe, "quantity": 6, "lot_number": "LO-LUONG"}},
	})
	if res := h.goiChiNhanh(t, a.token, kho1, http.MethodPost,
		fmt.Sprintf("%s/%d/duyet", duongDieuChuyen, idPhieu), nil); res.ma != http.StatusOK {
		t.Fatalf("B3 duyệt phiếu điều chuyển trả %d\n%s", res.ma, catBot(res.than))
	}

	if got := khoCua(t, h, a, kho1); got != 9 {
		t.Fatalf("B3 kho một phải còn 9 (15 - 6), nhận %d", got)
	}
	if got := khoCua(t, h, a, kho2); got != 6 {
		t.Fatalf("B3 kho hai phải có 6, nhận %d", got)
	}

	// ---------- Bước 4: BÁN 2 ở kho 2 ----------
	if res := h.goiChiNhanh(t, a.token, kho2, http.MethodPost, "/api/v1/admin/orders/pos", map[string]any{
		"payment_method": "cash", "amount_tendered": 10_000_000, "customer_name": "Khách lẻ",
		"items": []map[string]any{{"product_variant_id": a.bienThe, "quantity": 2}},
	}); res.ma != http.StatusCreated {
		t.Fatalf("B4 bán ở kho hai trả %d\n%s", res.ma, catBot(res.than))
	}

	if got := khoCua(t, h, a, kho1); got != 9 {
		t.Fatalf("B4 bán ở kho hai mà kho một đổi số: %d", got)
	}
	if got := khoCua(t, h, a, kho2); got != 4 {
		t.Fatalf("B4 kho hai phải còn 4 (6 - 2), nhận %d", got)
	}

	// ---------- Đối chiếu tổng ----------
	//
	// Nhập 20, bán 7, điều chuyển không làm mất hay sinh ra món nào → còn 13.
	// Con số này phải khớp dù chia thế nào giữa hai kho.
	if tong := khoCua(t, h, a, kho1) + khoCua(t, h, a, kho2); tong != 13 {
		t.Fatalf("tổng hai kho phải là 13 (nhập 20 − bán 7), nhận %d", tong)
	}
}

// Chứng từ và sổ kho phải cắt đúng bên.
//
// Tách khỏi bài trên vì nó hỏi một câu khác: không phải "số có đúng không" mà là
// "ai NHÌN THẤY cái gì". Hai câu ấy hỏng độc lập với nhau.
func TestLuongHaiChiNhanh_ChungTuVaSoKhoCatDungBen(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	kho1 := a.chiNhanh
	kho2 := moChiNhanhThuHai(t, h, a, "Kho hai")

	donKho(t, h, a)
	nhapLo(t, h, a, "LO-CT", 10, "")

	// Phiếu mua lập ở kho 1: kho 2 KHÔNG được thấy.
	if n := demChungTu(t, h, a, kho1, duongPhieuMua); n == 0 {
		t.Fatal("kho một phải thấy phiếu mua của chính nó")
	}
	if n := demChungTu(t, h, a, kho2, duongPhieuMua); n != 0 {
		t.Fatalf("kho hai thấy %d phiếu mua của kho một — danh sách chứng từ không cắt theo chi nhánh", n)
	}

	// Đơn bán ở kho 1: cùng luật.
	if res := h.goiChiNhanh(t, a.token, kho1, http.MethodPost, "/api/v1/admin/orders/pos", map[string]any{
		"payment_method": "cash", "amount_tendered": 10_000_000, "customer_name": "Khách lẻ",
		"items": []map[string]any{{"product_variant_id": a.bienThe, "quantity": 1}},
	}); res.ma != http.StatusCreated {
		t.Fatalf("bán ở kho một trả %d\n%s", res.ma, catBot(res.than))
	}

	if n := demChungTu(t, h, a, kho1, "/api/v1/admin/orders"); n == 0 {
		t.Fatal("kho một phải thấy đơn của chính nó")
	}
	if n := demChungTu(t, h, a, kho2, "/api/v1/admin/orders"); n != 0 {
		t.Fatalf("kho hai thấy %d đơn của kho một", n)
	}

	// Sổ kho: kho 1 đã có bút toán (nhập + bán), kho 2 chưa có gì.
	soKho1 := soBuToanKho(t, h, a, kho1)
	if soKho1 == 0 {
		t.Fatal("sổ kho của kho một phải có bút toán")
	}
	if n := soBuToanKho(t, h, a, kho2); n != 0 {
		t.Fatalf("kho hai chưa từng có hàng mà sổ kho đã có %d dòng", n)
	}

	// Điều chuyển sang kho 2 → MỖI BÊN thêm ĐÚNG MỘT bút toán: một lượt xuất và
	// một lượt nhập. Thiếu một bên là hàng bốc hơi hoặc tự sinh ra.
	_, id := lapDieuChuyen(t, h, a, map[string]any{
		"from_shop_id": kho1, "to_shop_id": kho2,
		"items": []any{map[string]any{"variant_id": a.bienThe, "quantity": 3}},
	})
	if res := h.goiChiNhanh(t, a.token, kho1, http.MethodPost,
		fmt.Sprintf("%s/%d/duyet", duongDieuChuyen, id), nil); res.ma != http.StatusOK {
		t.Fatalf("duyệt phiếu điều chuyển trả %d\n%s", res.ma, catBot(res.than))
	}

	if n := soBuToanKho(t, h, a, kho1); n != soKho1+1 {
		t.Fatalf("kho gửi phải thêm đúng 1 bút toán xuất: %d -> %d", soKho1, n)
	}
	if n := soBuToanKho(t, h, a, kho2); n != 1 {
		t.Fatalf("kho nhận phải có đúng 1 bút toán nhập, nhận %d", n)
	}
}

// Danh mục hàng hoá: mặt hàng gán riêng một chi nhánh thì chi nhánh kia không
// thấy trong danh sách, và cũng không lập được chứng từ với nó.
func TestLuongHaiChiNhanh_HangGanRiengThiBenKiaKhongThay(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	kho1 := a.chiNhanh
	kho2 := moChiNhanhThuHai(t, h, a, "Kho hai")

	coTrongDanhSach := func(shopID uint) bool {
		t.Helper()
		res := h.goiChiNhanh(t, a.token, shopID, http.MethodGet,
			"/api/v1/products?page_size=200&all=true", nil)
		if res.ma != http.StatusOK {
			t.Fatalf("đọc hàng hoá ở chi nhánh %d trả %d\n%s", shopID, res.ma, catBot(res.than))
		}
		var body struct {
			Data []struct {
				ID uint `json:"id"`
			} `json:"data"`
		}
		if err := json.Unmarshal([]byte(res.than), &body); err != nil {
			t.Fatalf("không đọc được danh sách hàng hoá: %v", err)
		}
		for _, p := range body.Data {
			if p.ID == a.sanPham {
				return true
			}
		}

		return false
	}

	// Chưa gán chi nhánh nào = dùng chung: cả hai bên đều thấy.
	if !coTrongDanhSach(kho1) || !coTrongDanhSach(kho2) {
		t.Fatal("mặt hàng chưa gán chi nhánh phải hiện ở MỌI chi nhánh")
	}

	// Gán riêng cho kho 1.
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
	if res := h.goiChiNhanh(t, a.token, kho1, http.MethodPut,
		fmt.Sprintf("/api/v1/admin/products/%d", a.sanPham), map[string]any{
			"name": hh.Data.Name, "slug": hh.Data.Slug, "category_id": hh.Data.CategoryID,
			"shop_ids": []uint{kho1},
		}); res.ma != http.StatusOK {
		t.Fatalf("gán chi nhánh cho mặt hàng trả %d\n%s", res.ma, catBot(res.than))
	}

	if !coTrongDanhSach(kho1) {
		t.Fatal("kho được gán phải vẫn thấy mặt hàng")
	}
	if coTrongDanhSach(kho2) {
		t.Fatal("kho KHÔNG được gán vẫn thấy mặt hàng — ô Chi nhánh trên form hàng hoá không có tác dụng")
	}

	// Và không lập nổi phiếu ở kho hai.
	ma, _ := lapPhieuTaiChiNhanh(t, h, a.token, kho2, map[string]any{
		"supplier_name": "Cong ty " + a.vet,
		"items":         []any{map[string]any{"variant_id": a.bienThe, "quantity": 1, "unit_cost": 10000}},
	})
	if ma == http.StatusCreated {
		t.Fatal("kho hai không được lập phiếu mua cho mặt hàng của kho một")
	}
}
