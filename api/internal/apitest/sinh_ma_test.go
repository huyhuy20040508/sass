package apitest

import (
	"encoding/json"
	"fmt"
	"net/http"
	"strconv"
	"testing"
	"time"

	"sass-api/internal/domain"
	"sass-api/internal/middleware"
)

// Bài kiểm QUY TẮC ĐÁNH SỐ CÓ HIỆU LỰC THẬT.
//
// Màn cấu hình lưu được không nói lên điều gì: thứ cửa hàng mua là mã chứng từ
// và mã danh mục ra đúng hình dạng họ đặt. Mỗi bài dưới đây bật một quy tắc rồi
// tạo dữ liệu qua ĐÚNG đường mà trang quản trị đi, và đọc mã sinh ra.
//
// Bài nào cũng kiểm luôn cảnh CHƯA BẬT: cửa hàng không đụng tới màn cấu hình
// phải thấy mã y như trước, nếu không thì lượt nâng cấp này làm hỏng dữ liệu của
// mọi cửa hàng đang chạy.

// batQuyTac bật một quy tắc cho chi nhánh đang chọn.
func batQuyTac(t *testing.T, h *heThong, c *cuaHang, docType, tienTo string, dai int) {
	t.Helper()

	res := h.goi(t, c.token, http.MethodPut, "/api/v1/admin/quy-tac-ma", map[string]any{
		"shop_id": c.chiNhanh,
		"quy_tac": []map[string]any{
			{"doc_type": docType, "prefix": tienTo, "value_part": domain.PhanSoThuTu, "length": dai, "suffix": ""},
		},
	})
	if res.ma != http.StatusOK {
		t.Fatalf("bật quy tắc %s hỏng: %d\n%s", docType, res.ma, catBot(res.than))
	}
}

// maCuaPhanHoi đọc một trường chuỗi trong `data` của phản hồi.
func maCuaPhanHoi(t *testing.T, than, truong string) string {
	t.Helper()

	var body struct {
		Data map[string]any `json:"data"`
	}
	if err := json.Unmarshal([]byte(than), &body); err != nil {
		t.Fatalf("không đọc được phản hồi: %v\n%s", err, catBot(than))
	}
	ma, _ := body.Data[truong].(string)

	return ma
}

// TestSinhMa_NhanVien — mã nhân viên theo quy tắc.
func TestSinhMa_NhanVien(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	taoNV := func(ten string) string {
		res := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/nhan-su", map[string]any{
			"full_name": ten + " " + a.vet,
			"status":    "dang_lam",
			"shop_id":   a.chiNhanh,
		})
		if res.ma != http.StatusCreated {
			t.Fatalf("tạo hồ sơ nhân sự hỏng: %d\n%s", res.ma, catBot(res.than))
		}

		return maCuaPhanHoi(t, res.than, "code")
	}

	if ma := taoNV("Người thứ nhất"); ma != "NV0001" {
		t.Fatalf("chưa bật quy tắc thì mã phải là NV0001, nhận %q", ma)
	}

	batQuyTac(t, h, a, domain.LoaiNhanVien, "NS-", 3)

	if ma := taoNV("Người thứ hai"); ma != "NS-001" {
		t.Fatalf("mã theo quy tắc phải là NS-001, nhận %q", ma)
	}
}

// TestSinhMa_NhomHangHoa — mã nhóm bỏ trống thì hệ thống đặt: dải NH0001 khi
// chưa bật quy tắc, theo quy tắc khi đã bật.
func TestSinhMa_NhomHangHoa(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	taoNhom := func(ten string) string {
		res := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/categories", map[string]any{
			"name": ten + " " + a.vet,
		})
		if res.ma != http.StatusCreated {
			t.Fatalf("tạo nhóm hàng hoá hỏng: %d\n%s", res.ma, catBot(res.than))
		}

		return maCuaPhanHoi(t, res.than, "slug")
	}

	if ma := taoNhom("Nhóm một"); ma != "NH0001" {
		t.Fatalf("chưa bật quy tắc thì mã nhóm phải là NH0001, nhận %q", ma)
	}

	batQuyTac(t, h, a, domain.LoaiNhomHangHoa, "NHOM", 4)

	if ma := taoNhom("Nhóm hai"); ma != "NHOM0001" {
		t.Fatalf("mã theo quy tắc phải là NHOM0001, nhận %q", ma)
	}
}

// TestSinhMa_HangHoa — SKU bỏ trống: chưa bật quy tắc thì đòi nhập tay, bật rồi
// thì hệ thống đặt.
func TestSinhMa_HangHoa(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	taoHang := func(ten string) traLoi {
		return h.goi(t, a.token, http.MethodPost, "/api/v1/admin/products", map[string]any{
			"name": ten + " " + a.vet, "slug": ten + "-" + a.vet,
			"category_id": a.danhMuc, "base_price": 100000,
		})
	}

	res := taoHang("hang-mot")
	if res.ma != http.StatusUnprocessableEntity {
		t.Fatalf("chưa bật quy tắc mà bỏ trống SKU phải trả 422, nhận %d\n%s", res.ma, catBot(res.than))
	}

	batQuyTac(t, h, a, domain.LoaiHangHoa, "HH", 6)

	res = taoHang("hang-hai")
	if res.ma != http.StatusCreated {
		t.Fatalf("bật quy tắc rồi mà tạo hàng hoá vẫn hỏng: %d\n%s", res.ma, catBot(res.than))
	}
	if ma := maCuaPhanHoi(t, res.than, "sku"); ma != "HH000001" {
		t.Fatalf("SKU theo quy tắc phải là HH000001, nhận %q", ma)
	}
}

// TestSinhMa_ChungTuTheoChiNhanh — đơn hàng lấy quy tắc của ĐÚNG chi nhánh lập
// đơn, và mỗi chi nhánh đếm riêng.
func TestSinhMa_ChungTuTheoChiNhanh(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	// Chi nhánh thứ hai để chứng minh hai nơi hai dải số.
	res := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/chi-nhanh",
		map[string]any{"name": "Kho phụ " + a.vet})
	if res.ma != http.StatusCreated {
		t.Fatalf("mở chi nhánh thứ hai hỏng: %d\n%s", res.ma, catBot(res.than))
	}
	khoPhu := uint(0)
	if v, err := strconv.Atoi(fmt.Sprint(maSoCuaPhanHoi(t, res.than, "id"))); err == nil {
		khoPhu = uint(v)
	}

	luuQuyTac := func(shopID uint, tienTo string) {
		res := h.goi(t, a.token, http.MethodPut, "/api/v1/admin/quy-tac-ma", map[string]any{
			"shop_id": shopID,
			"quy_tac": []map[string]any{
				{"doc_type": domain.LoaiDonHang, "prefix": tienTo,
					"value_part": domain.PhanSoThuTu, "length": 4, "suffix": ""},
			},
		})
		if res.ma != http.StatusOK {
			t.Fatalf("bật quy tắc đơn hàng hỏng: %d\n%s", res.ma, catBot(res.than))
		}
	}
	luuQuyTac(a.chiNhanh, "DHA")
	luuQuyTac(khoPhu, "DHB")

	// Kho phụ vừa mở thì trống, mà đường lập đơn chặn khi không đủ hàng. Nhập
	// sẵn vài cái để bài này kiểm được đúng thứ nó muốn kiểm: cái MÃ.
	res = h.goiChiNhanh(t, a.token, khoPhu, http.MethodPut,
		fmt.Sprintf("/api/v1/admin/inventory/%d", a.bienThe),
		map[string]any{"mode": "delta", "quantity": 5})
	if res.ma != http.StatusOK {
		t.Fatalf("nhập hàng vào kho phụ hỏng: %d\n%s", res.ma, catBot(res.than))
	}

	lapDon := func(shopID uint) string {
		res := h.goiVoiHeader(t, a.token, http.MethodPost, "/api/v1/admin/orders",
			donMau(a), map[string]string{middleware.HeaderChiNhanh: strconv.Itoa(int(shopID))})
		if res.ma != http.StatusCreated {
			t.Fatalf("lập đơn hàng hỏng: %d\n%s", res.ma, catBot(res.than))
		}

		return maCuaPhanHoi(t, res.than, "order_code")
	}

	if ma := lapDon(a.chiNhanh); ma != "DHA0001" {
		t.Fatalf("đơn của chi nhánh gốc phải là DHA0001, nhận %q", ma)
	}
	if ma := lapDon(a.chiNhanh); ma != "DHA0002" {
		t.Fatalf("số của một chi nhánh phải tăng dần, nhận %q", ma)
	}
	// Chi nhánh khác đếm riêng từ 1 — đó là lý do quy tắc chứng từ chia theo nơi.
	if ma := lapDon(khoPhu); ma != "DHB0001" {
		t.Fatalf("đơn của kho phụ phải là DHB0001, nhận %q", ma)
	}
}

// TestSinhMa_TheoNgay — kiểu "ngày tháng năm": mã mang mốc ngày, số đếm lấy chỗ
// còn lại của độ dài.
func TestSinhMa_TheoNgay(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	res := h.goi(t, a.token, http.MethodPut, "/api/v1/admin/quy-tac-ma", map[string]any{
		"shop_id": a.chiNhanh,
		"quy_tac": []map[string]any{
			{"doc_type": domain.LoaiDonHang, "prefix": "DHN",
				"value_part": domain.PhanNgayThangNam, "length": 11, "suffix": ""},
		},
	})
	if res.ma != http.StatusOK {
		t.Fatalf("bật quy tắc theo ngày hỏng: %d\n%s", res.ma, catBot(res.than))
	}

	res = h.goi(t, a.token, http.MethodPost, "/api/v1/admin/orders", donMau(a))
	if res.ma != http.StatusCreated {
		t.Fatalf("lập đơn hàng hỏng: %d\n%s", res.ma, catBot(res.than))
	}

	// 11 ký tự phần giữa: 8 cho ddmmyyyy, 3 còn lại cho số đếm.
	mong := "DHN" + time.Now().Format("02012006") + "001"
	if ma := maCuaPhanHoi(t, res.than, "order_code"); ma != mong {
		t.Fatalf("mã theo ngày phải là %s, nhận %q", mong, ma)
	}
}

// donMau — payload đơn hàng tối thiểu để lấy mã sinh ra; chỉ dùng cho các bài
// kiểm quy tắc đánh số, không phải bài kiểm nghiệp vụ đơn hàng.
func donMau(c *cuaHang) map[string]any {
	return map[string]any{
		"user_id": c.khach, "recipient_name": "Người nhận " + c.vet,
		"recipient_phone": "0900000001", "shipping_address": "Địa chỉ " + c.vet,
		"payment_method": "cod",
		"items": []map[string]any{{
			"product_variant_id": c.bienThe, "product_name": "Sản phẩm " + c.vet,
			"unit_price": 100000, "quantity": 1,
		}},
	}
}

// maSoCuaPhanHoi đọc một trường số trong `data`.
func maSoCuaPhanHoi(t *testing.T, than, truong string) float64 {
	t.Helper()

	var body struct {
		Data map[string]any `json:"data"`
	}
	if err := json.Unmarshal([]byte(than), &body); err != nil {
		t.Fatalf("không đọc được phản hồi: %v\n%s", err, catBot(than))
	}
	so, _ := body.Data[truong].(float64)

	return so
}
