package apitest

import (
	"encoding/json"
	"fmt"
	"net/http"
	"strings"
	"testing"
)

// KHUYẾN MÃI VÀ VOUCHER THEO CHI NHÁNH.
//
// Quy ước — và nó là thứ dễ hiểu ngược nhất trong cả cụm này:
//
//	KHÔNG GÁN CHI NHÁNH NÀO = ÁP DỤNG CHO MỌI CHI NHÁNH
//
// Giống product_shops, variant_shop_prices, product_shop_locations. Nhờ vậy mọi
// chương trình lập từ trước migration 0053 giữ nguyên hành vi cũ mà không cần
// một câu UPDATE nào — nếu "rỗng" nghĩa là "không nơi nào" thì đúng ngày chạy
// migration là khuyến mãi của mọi cửa hàng tắt sạch.
//
// PHẠM VI CỦA BỘ BÀI NÀY: đường QUẢN TRỊ và bán tại quầy, nơi request mang theo
// chi nhánh đang làm việc. Đường storefront (/vouchers/check của khách mua trên
// web) phân giải cửa hàng theo TÊN MIỀN và không mang chi nhánh nào, nên với
// cửa hàng nhiều chi nhánh nó không cắt — xem ghi chú ở locGanChiNhanh.

func taoVoucher(t *testing.T, h *heThong, c *cuaHang, ma string, shopIDs []uint) uint {
	t.Helper()

	than := map[string]any{
		"code": ma, "description": "Ma thu " + c.vet,
		"discount_type": "fixed", "discount_value": 5000,
		"is_active": true,
	}
	if shopIDs != nil {
		than["shop_ids"] = shopIDs
	}

	res := h.goi(t, c.token, http.MethodPost, "/api/v1/admin/vouchers", than)
	if res.ma != http.StatusCreated {
		t.Fatalf("tạo voucher trả %d\n%s", res.ma, catBot(res.than))
	}

	var body struct {
		Data struct {
			ID uint `json:"id"`
		} `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &body); err != nil {
		t.Fatalf("không đọc được phản hồi: %v\n%s", err, catBot(res.than))
	}

	return body.Data.ID
}

// coTrongDanhSach cho biết mã có nằm trong danh sách voucher mà kho này thấy hay không.
func coTrongDanhSach(t *testing.T, h *heThong, c *cuaHang, shopID uint, ma string) bool {
	t.Helper()

	res := h.goiChiNhanh(t, c.token, shopID, http.MethodGet,
		"/api/v1/admin/vouchers?page_size=200", nil)
	if res.ma != http.StatusOK {
		t.Fatalf("đọc danh sách voucher trả %d\n%s", res.ma, catBot(res.than))
	}

	// API chuẩn hoá mã về CHỮ HOA (khách gõ "sale10" vẫn trúng "SALE10"), nên so
	// theo đúng dạng nó lưu chứ không theo dạng vừa gửi lên.
	return contains(res.than, `"code":"`+strings.ToUpper(ma)+`"`)
}

// PHẢN HỒI của lượt tạo/sửa phải NÓI ĐÚNG những chi nhánh vừa lưu.
//
// Bản ghi vừa qua Create thì quan hệ Shops còn rỗng, nên nếu dựng phản hồi thẳng
// từ nó thì nó trả `shop_ids: []` — mà theo quy ước ở đây, rỗng nghĩa là "dùng
// được mọi nơi", tức là NGƯỢC HẲN thứ vừa lưu. Hộp sửa đọc đúng trường này để
// tick lại ô, nên nó sẽ mở ra với các ô trống trơn và người dùng lưu lần nữa là
// gỡ sạch chi nhánh mà không hề biết. Ghi xuống đúng vẫn chưa đủ.
func TestVoucherChiNhanh_PhanHoiNoiDungChiNhanhVuaLuu(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	kho1 := a.chiNhanh
	kho2 := moChiNhanhThuHai(t, h, a, "Kho hai")

	doc := func(res traLoi) []uint {
		var body struct {
			Data struct {
				ID      uint   `json:"id"`
				ShopIDs []uint `json:"shop_ids"`
			} `json:"data"`
		}
		if err := json.Unmarshal([]byte(res.than), &body); err != nil {
			t.Fatalf("không đọc được phản hồi: %v\n%s", err, catBot(res.than))
		}

		return body.Data.ShopIDs
	}

	// Lượt TẠO.
	res := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/vouchers", map[string]any{
		"code": "PHANHOI" + a.vet, "description": "x",
		"discount_type": "fixed", "discount_value": 5000, "is_active": true,
		"shop_ids": []uint{kho1},
	})
	if res.ma != http.StatusCreated {
		t.Fatalf("tạo voucher trả %d\n%s", res.ma, catBot(res.than))
	}
	if ds := doc(res); len(ds) != 1 || ds[0] != kho1 {
		t.Fatalf("phản hồi lượt TẠO phải nói đúng [%d], nhận %v", kho1, ds)
	}

	var tao struct {
		Data struct {
			ID uint `json:"id"`
		} `json:"data"`
	}
	_ = json.Unmarshal([]byte(res.than), &tao)

	// Lượt SỬA — đổi sang kho khác.
	res = h.goi(t, a.token, http.MethodPut,
		fmt.Sprintf("/api/v1/admin/vouchers/%d", tao.Data.ID), map[string]any{
			"code": "PHANHOI" + a.vet, "description": "x",
			"discount_type": "fixed", "discount_value": 5000, "is_active": true,
			"shop_ids": []uint{kho2},
		})
	if res.ma != http.StatusOK {
		t.Fatalf("sửa voucher trả %d\n%s", res.ma, catBot(res.than))
	}
	if ds := doc(res); len(ds) != 1 || ds[0] != kho2 {
		t.Fatalf("phản hồi lượt SỬA phải nói đúng [%d], nhận %v", kho2, ds)
	}
}

// DANH SÁCH cũng phải mang shop_ids, không chỉ lượt đọc chi tiết.
//
// Màn quản trị nhúng nguyên danh sách này vào JS rồi mở hộp sửa TỪ ĐÓ, không gọi
// lại đường chi tiết. Thiếu nó thì hộp sửa mở ra với ô chi nhánh trống trơn, và
// lượt lưu kế tiếp gỡ sạch phần gán mà người dùng không bấm gì cả — mất dữ liệu
// trong im lặng, đúng kiểu khó lần ra nhất.
func TestVoucherChiNhanh_DanhSachCungMangShopIDs(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	kho1 := a.chiNhanh
	ma := "TRONGDS" + a.vet
	taoVoucher(t, h, a, ma, []uint{kho1})

	res := h.goiChiNhanh(t, a.token, kho1, http.MethodGet,
		"/api/v1/admin/vouchers?page_size=200", nil)
	if res.ma != http.StatusOK {
		t.Fatalf("đọc danh sách trả %d\n%s", res.ma, catBot(res.than))
	}

	var body struct {
		Data []struct {
			Code    string `json:"code"`
			ShopIDs []uint `json:"shop_ids"`
		} `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &body); err != nil {
		t.Fatalf("không đọc được danh sách: %v", err)
	}

	for _, v := range body.Data {
		if v.Code != strings.ToUpper(ma) {
			continue
		}
		if len(v.ShopIDs) != 1 || v.ShopIDs[0] != kho1 {
			t.Fatalf("dòng trong danh sách phải mang shop_ids [%d], nhận %v", kho1, v.ShopIDs)
		}

		return
	}

	t.Fatal("không thấy mã vừa tạo trong danh sách")
}

// VẾ MỞ: không gán chi nhánh thì mã có mặt ở mọi kho.
//
// Đây là hành vi của TOÀN BỘ dữ liệu cũ, nên nếu vế này gãy thì mọi cửa hàng
// đang chạy đều mất khuyến mãi ngay sau lượt cập nhật.
func TestVoucherChiNhanh_KhongGanThiDungDuocMoiNoi(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	kho1 := a.chiNhanh
	kho2 := moChiNhanhThuHai(t, h, a, "Kho hai")

	ma := "MOINOI" + a.vet
	taoVoucher(t, h, a, ma, nil)

	for _, kho := range []uint{kho1, kho2} {
		if !coTrongDanhSach(t, h, a, kho, ma) {
			t.Fatalf("mã không gán chi nhánh phải thấy được ở kho %d", kho)
		}
	}
}

// VẾ ĐÓNG: gán cho kho 1 thì kho 2 không thấy, và mở thẳng bằng id cũng không được.
func TestVoucherChiNhanh_GanRoiThiKhoKhacKhongDung(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	kho1 := a.chiNhanh
	kho2 := moChiNhanhThuHai(t, h, a, "Kho hai")

	ma := "RIENGKHO1" + a.vet
	id := taoVoucher(t, h, a, ma, []uint{kho1})

	if !coTrongDanhSach(t, h, a, kho1, ma) {
		t.Fatal("kho được gán phải thấy mã của mình")
	}
	if coTrongDanhSach(t, h, a, kho2, ma) {
		t.Fatal("mã gán riêng kho 1 mà kho 2 vẫn thấy trong danh sách")
	}

	// Không có trong danh sách thì cũng đừng mở được bằng cách gõ thẳng id.
	res := h.goiChiNhanh(t, a.token, kho2, http.MethodGet,
		fmt.Sprintf("/api/v1/admin/vouchers/%d", id), nil)
	if res.ma != http.StatusForbidden {
		t.Fatalf("mở mã của kho khác bằng id phải bị chặn 403, nhận %d\n%s", res.ma, catBot(res.than))
	}
}

// Sửa lại danh sách chi nhánh phải THAY THẾ, không cộng dồn — và gỡ hết thì mã
// trở lại "dùng được mọi nơi".
func TestVoucherChiNhanh_GoHetThiTroLaiMoiNoi(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	kho1 := a.chiNhanh
	kho2 := moChiNhanhThuHai(t, h, a, "Kho hai")

	ma := "GOHET" + a.vet
	id := taoVoucher(t, h, a, ma, []uint{kho1})

	if coTrongDanhSach(t, h, a, kho2, ma) {
		t.Fatal("chưa gỡ mà kho 2 đã thấy — bài kiểm mất nghĩa")
	}

	res := h.goi(t, a.token, http.MethodPut, fmt.Sprintf("/api/v1/admin/vouchers/%d", id),
		map[string]any{
			"code": ma, "description": "Ma thu " + a.vet,
			"discount_type": "fixed", "discount_value": 5000,
			"is_active": true, "shop_ids": []uint{},
		})
	if res.ma != http.StatusOK {
		t.Fatalf("sửa voucher trả %d\n%s", res.ma, catBot(res.than))
	}

	if !coTrongDanhSach(t, h, a, kho2, ma) {
		t.Fatal("gỡ hết chi nhánh rồi thì mọi kho phải thấy mã")
	}
}

// Khuyến mãi đi cùng luật, và ở đây kiểm đúng đường ÁP DỤNG: giá bán mà khách
// nhìn thấy. Gán chương trình cho kho 1 thì kho 2 phải bán giá gốc.
func TestKhuyenMaiChiNhanh_GanRoiThiKhoKhacKhongGiam(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	kho1 := a.chiNhanh
	kho2 := moChiNhanhThuHai(t, h, a, "Kho hai")

	res := h.goi(t, a.token, http.MethodGet, "/api/v1/admin/promotions?page_size=200", nil)
	if res.ma != http.StatusOK {
		t.Fatalf("đọc danh sách khuyến mãi trả %d\n%s", res.ma, catBot(res.than))
	}

	var ds struct {
		Data []struct {
			ID   uint   `json:"id"`
			Name string `json:"name"`
		} `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &ds); err != nil || len(ds.Data) == 0 {
		t.Fatalf("cửa hàng mẫu phải có sẵn ít nhất một chương trình: %v\n%s", err, catBot(res.than))
	}
	id := ds.Data[0].ID

	// Chưa gán gì: cả hai kho đều thấy.
	for _, kho := range []uint{kho1, kho2} {
		res = h.goiChiNhanh(t, a.token, kho, http.MethodGet,
			fmt.Sprintf("/api/v1/admin/promotions/%d", id), nil)
		if res.ma != http.StatusOK {
			t.Fatalf("chương trình chưa gán kho nào phải mở được ở kho %d, nhận %d", kho, res.ma)
		}
	}

	// Gán riêng kho 1.
	res = h.goi(t, a.token, http.MethodGet, fmt.Sprintf("/api/v1/admin/promotions/%d", id), nil)
	var ct struct {
		Data map[string]any `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &ct); err != nil {
		t.Fatalf("không đọc được chương trình: %v", err)
	}
	ct.Data["shop_ids"] = []uint{kho1}
	// Hai mốc thời gian phải gửi đúng khuôn datetime-local mà API đòi.
	res = h.goi(t, a.token, http.MethodPut, fmt.Sprintf("/api/v1/admin/promotions/%d", id), ct.Data)
	if res.ma != http.StatusOK {
		t.Fatalf("gán chi nhánh cho chương trình trả %d\n%s", res.ma, catBot(res.than))
	}

	res = h.goiChiNhanh(t, a.token, kho2, http.MethodGet,
		fmt.Sprintf("/api/v1/admin/promotions/%d", id), nil)
	if res.ma != http.StatusForbidden {
		t.Fatalf("chương trình gán riêng kho 1 thì kho 2 phải bị chặn 403, nhận %d\n%s",
			res.ma, catBot(res.than))
	}
}
