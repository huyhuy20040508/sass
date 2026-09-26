package apitest

import (
	"encoding/json"
	"fmt"
	"net/http"
	"testing"
)

// GIÁ BÁN THEO CHI NHÁNH — thiếu dòng thì dùng giá gốc.
//
// Bài kiểm ở đây canh đúng một câu: CON SỐ THU TIỀN phải là giá của cái quầy
// đang bán. Bày đúng giá lên màn hình mà thu tiền theo giá khác là kiểu sai tệ
// nhất — khách nhìn một đằng, hoá đơn in một nẻo.

// giaCuaDon bán MỘT món ở chi nhánh `shopID` rồi trả về số tiền hàng đã thu.
//
// Đi qua ĐÚNG đường bán hàng thật thay vì đọc bảng giá: chỗ duy nhất đáng tin để
// biết cửa hàng sẽ thu bao nhiêu là chỗ nó thu.
//
// Đọc `subtotal_amount` (tiền hàng trước giảm giá) với số lượng 1 — đó chính là
// đơn giá đã áp dụng. Phản hồi của quầy không trả về từng dòng hàng.
func giaCuaDon(t *testing.T, h *heThong, c *cuaHang, shopID uint) float64 {
	t.Helper()

	res := h.goiChiNhanh(t, c.token, shopID, http.MethodPost, "/api/v1/admin/orders/pos", map[string]any{
		"payment_method":  "cash",
		"amount_tendered": 100_000_000,
		"customer_name":   "Khách lẻ",
		"items":           []map[string]any{{"product_variant_id": c.bienThe, "quantity": 1}},
	})
	if res.ma != http.StatusCreated {
		t.Fatalf("bán tại quầy ở chi nhánh %d trả %d\n%s", shopID, res.ma, catBot(res.than))
	}

	var body struct {
		Data struct {
			Subtotal float64 `json:"subtotal_amount"`
		} `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &body); err != nil {
		t.Fatalf("không đọc được đơn: %v\n%s", err, catBot(res.than))
	}

	return body.Data.Subtotal
}

// Khai giá riêng cho kho hai: quầy đó thu theo giá của nó, quầy gốc vẫn giá cũ.
func TestGiaChiNhanh_MoiQuayThuTheoGiaCuaMinh(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	kho2 := moChiNhanhThuHai(t, h, a, "Kho hai")

	// Đủ hàng ở cả hai kho để bán được.
	donKho(t, h, a)
	nhapLo(t, h, a, "LO-GIA", 20, "")
	_, id := lapDieuChuyen(t, h, a, map[string]any{
		"from_shop_id": a.chiNhanh, "to_shop_id": kho2,
		"items": []any{map[string]any{"variant_id": a.bienThe, "quantity": 10}},
	})
	if res := h.goiChiNhanh(t, a.token, a.chiNhanh, http.MethodPost,
		fmt.Sprintf("%s/%d/duyet", duongDieuChuyen, id), nil); res.ma != http.StatusOK {
		t.Fatalf("duyệt phiếu chuẩn bị hàng trả %d\n%s", res.ma, catBot(res.than))
	}

	giaGoc := giaCuaDon(t, h, a, a.chiNhanh)
	if giaGoc <= 0 {
		t.Fatalf("giá gốc phải lớn hơn 0, nhận %v", giaGoc)
	}

	// CHƯA khai giá riêng: kho hai thu đúng giá gốc.
	if g := giaCuaDon(t, h, a, kho2); g != giaGoc {
		t.Fatalf("chưa khai giá riêng thì kho hai phải thu giá gốc %v, nhận %v", giaGoc, g)
	}

	// Khai một giá riêng rồi khai gấp đôi: số tiền thu được phải gấp đôi theo.
	//
	// So SÁNH TỈ LỆ chứ không so con số tuyệt đối, vì cửa hàng gieo trong bài kiểm
	// có sẵn một chương trình khuyến mãi phần trăm — đóng đinh một con số ở đây là
	// bài kiểm vỡ vào ngày ai đó sửa dữ liệu gieo, mà cái vỡ ấy không nói lên điều
	// gì về giá theo chi nhánh.
	duong := fmt.Sprintf("/api/v1/admin/bien-the/%d/gia-chi-nhanh", a.bienThe)
	khai := func(gia float64) {
		t.Helper()
		res := h.goiChiNhanh(t, a.token, a.chiNhanh, http.MethodPost, duong,
			map[string]any{"shop_id": kho2, "price": gia})
		if res.ma != http.StatusOK {
			t.Fatalf("khai giá riêng %v trả %d\n%s", gia, res.ma, catBot(res.than))
		}
	}

	khai(50000)
	thuMot := giaCuaDon(t, h, a, kho2)
	if thuMot == giaGoc {
		t.Fatalf("khai giá riêng rồi mà quầy vẫn thu như giá gốc (%v)", giaGoc)
	}

	khai(100000)
	thuHai := giaCuaDon(t, h, a, kho2)
	if thuHai != thuMot*2 {
		t.Fatalf("giá riêng gấp đôi thì tiền thu phải gấp đôi: %v -> %v", thuMot, thuHai)
	}

	// Quầy gốc KHÔNG bị ảnh hưởng: khai giá cho một chi nhánh không đụng chi nhánh khác.
	if g := giaCuaDon(t, h, a, a.chiNhanh); g != giaGoc {
		t.Fatalf("quầy gốc phải giữ giá cũ %v, nhận %v", giaGoc, g)
	}

	// Gỡ đi thì kho hai trở lại giá gốc — đó là cách "trả về mặc định", không
	// phải khai lại bằng đúng giá gốc.
	if res := h.goiChiNhanh(t, a.token, a.chiNhanh, http.MethodDelete,
		fmt.Sprintf("%s/%d", duong, kho2), nil); res.ma != http.StatusOK {
		t.Fatalf("gỡ giá riêng trả %d\n%s", res.ma, catBot(res.than))
	}
	if g := giaCuaDon(t, h, a, kho2); g != giaGoc {
		t.Fatalf("gỡ giá riêng xong kho hai phải về giá gốc %v, nhận %v", giaGoc, g)
	}
}

// Danh sách chỉ liệt kê chi nhánh ĐÃ khai giá riêng — chi nhánh vắng mặt là chi
// nhánh đang dùng giá gốc, không phải chi nhánh "giá 0".
func TestGiaChiNhanh_ChiLietKeChiNhanhDaKhai(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	kho2 := moChiNhanhThuHai(t, h, a, "Kho hai")

	duong := fmt.Sprintf("/api/v1/admin/bien-the/%d/gia-chi-nhanh", a.bienThe)

	doc := func() []struct {
		ShopID   uint    `json:"shop_id"`
		Price    float64 `json:"price"`
		ShopName string  `json:"shop_name"`
	} {
		res := h.goiChiNhanh(t, a.token, a.chiNhanh, http.MethodGet, duong, nil)
		if res.ma != http.StatusOK {
			t.Fatalf("đọc giá theo chi nhánh trả %d\n%s", res.ma, catBot(res.than))
		}
		var body struct {
			Data []struct {
				ShopID   uint    `json:"shop_id"`
				Price    float64 `json:"price"`
				ShopName string  `json:"shop_name"`
			} `json:"data"`
		}
		if err := json.Unmarshal([]byte(res.than), &body); err != nil {
			t.Fatalf("không đọc được phản hồi: %v", err)
		}

		return body.Data
	}

	if ds := doc(); len(ds) != 0 {
		t.Fatalf("chưa khai gì thì danh sách phải rỗng, nhận %d dòng", len(ds))
	}

	if res := h.goiChiNhanh(t, a.token, a.chiNhanh, http.MethodPost, duong,
		map[string]any{"shop_id": kho2, "price": 12345}); res.ma != http.StatusOK {
		t.Fatalf("khai giá riêng trả %d", res.ma)
	}

	ds := doc()
	if len(ds) != 1 {
		t.Fatalf("phải có đúng một chi nhánh đã khai, nhận %d", len(ds))
	}
	if ds[0].ShopID != kho2 || ds[0].Price != 12345 {
		t.Fatalf("dòng khai sai: %+v", ds[0])
	}
	// Tên chi nhánh phải có: màn khai giá bày tên chứ không bày id.
	if ds[0].ShopName == "" {
		t.Fatal("phải trả kèm tên chi nhánh")
	}

	// Khai lại chính chi nhánh ấy thì GHI ĐÈ, không đẻ ra dòng thứ hai.
	if res := h.goiChiNhanh(t, a.token, a.chiNhanh, http.MethodPost, duong,
		map[string]any{"shop_id": kho2, "price": 99000}); res.ma != http.StatusOK {
		t.Fatalf("khai lại trả %d", res.ma)
	}
	ds = doc()
	if len(ds) != 1 || ds[0].Price != 99000 {
		t.Fatalf("khai lại phải ghi đè, nhận %+v", ds)
	}
}

// Chi nhánh của cửa hàng KHÁC thì không khai giá vào được.
func TestGiaChiNhanh_KhongKhaiSangCuaHangKhac(t *testing.T) {
	h := dungHeThong(t)
	a, b := haiCuaHang(t, h)

	res := h.goiChiNhanh(t, a.token, a.chiNhanh, http.MethodPost,
		fmt.Sprintf("/api/v1/admin/bien-the/%d/gia-chi-nhanh", a.bienThe),
		map[string]any{"shop_id": b.chiNhanh, "price": 1000})
	if res.ma == http.StatusOK {
		t.Fatal("khai giá cho chi nhánh của cửa hàng khác phải bị từ chối")
	}
}
