package apitest

import (
	"encoding/json"
	"fmt"
	"net/http"
	"testing"
)

// ĐƯỜNG ĐỌC theo lô — GET /admin/inventory/chi-nhanh trả về từng lô của mỗi dòng.
//
// Phần ghi đã có ton_kho_lo_test.go canh. Bài này canh chỗ còn lại: bảng Tồn kho
// chi nhánh bày ba cột cuối (số lô · số lượng · hạn dùng) bằng đúng dữ liệu này,
// nên nếu nó trả thiếu lô hoặc gắn lô sang nhầm dòng thì người đọc bảng thấy một
// mặt hàng có tổng 8 mà chỉ liệt kê 5 — không có cách nào tự phát hiện.

// dongTon là một dòng của bảng tồn kho chi nhánh, kèm danh sách lô.
type dongTon struct {
	ShopID    uint   `json:"shop_id"`
	VariantID uint   `json:"variant_id"`
	Quantity  int    `json:"quantity"`
	SKU       string `json:"sku"`
	Lots      []struct {
		LotNumber  string  `json:"lot_number"`
		Quantity   int     `json:"quantity"`
		ExpireDate *string `json:"expire_date"`
	} `json:"lots"`
}

// docTonChiNhanh gọi đúng đường mà trang Tồn kho chi nhánh đang gọi.
func docTonChiNhanh(t *testing.T, h *heThong, c *cuaHang) []dongTon {
	t.Helper()

	res := h.goiChiNhanh(t, c.token, c.chiNhanh, http.MethodGet,
		fmt.Sprintf("/api/v1/admin/inventory/chi-nhanh?shops=%d&page_size=200", c.chiNhanh), nil)
	if res.ma != http.StatusOK {
		t.Fatalf("đọc tồn kho chi nhánh trả %d\n%s", res.ma, catBot(res.than))
	}

	var body struct {
		Data struct {
			Dong []dongTon `json:"dong"`
		} `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &body); err != nil {
		t.Fatalf("không đọc được phản hồi: %v\n%s", err, catBot(res.than))
	}

	return body.Data.Dong
}

// timDong lấy dòng của một biến thể tại một chi nhánh.
func timDong(t *testing.T, ds []dongTon, shopID, variantID uint) dongTon {
	t.Helper()

	for _, d := range ds {
		if d.ShopID == shopID && d.VariantID == variantID {
			return d
		}
	}
	t.Fatalf("không thấy dòng tồn của biến thể %d tại chi nhánh %d", variantID, shopID)

	return dongTon{}
}

// TestLoDoc_BangTonBayDuTungLo — mỗi lô một dòng, và cộng lại đúng bằng tổng.
//
// Cộng lại phải khớp là điều kiện sống còn của bảng: cột "Tổng số lượng" gộp dọc
// qua các dòng lô, nên tổng lệch với phần liệt kê bên cạnh là hai con số đá nhau
// ngay trên cùng một hàng.
func TestLoDoc_BangTonBayDuTungLo(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	donKho(t, h, a)
	nhapLo(t, h, a, "LO-DOC-A", 5, "2030-01-31")
	nhapLo(t, h, a, "LO-DOC-B", 3, "2029-01-31")

	d := timDong(t, docTonChiNhanh(t, h, a), a.chiNhanh, a.bienThe)

	if len(d.Lots) < 2 {
		t.Fatalf("dòng tồn phải liệt kê ít nhất hai lô, nhận %d: %+v", len(d.Lots), d.Lots)
	}

	cong := 0
	theoSo := map[string]int{}
	for _, l := range d.Lots {
		cong += l.Quantity
		theoSo[l.LotNumber] = l.Quantity
	}
	if cong != d.Quantity {
		t.Fatalf("cộng các lô phải bằng tổng tồn: tổng = %d, cộng lô = %d", d.Quantity, cong)
	}
	if theoSo["LO-DOC-A"] != 5 {
		t.Fatalf("lô LO-DOC-A phải có 5, nhận %d", theoSo["LO-DOC-A"])
	}
	if theoSo["LO-DOC-B"] != 3 {
		t.Fatalf("lô LO-DOC-B phải có 3, nhận %d", theoSo["LO-DOC-B"])
	}
}

// TestLoDoc_LoSapHetHanLenTruoc — lô hết hạn sớm đứng trên, hàng KHÔNG hạn xuống cuối.
//
// MySQL xếp NULL lên đầu, nên nếu quên nói rõ thì hàng không có hạn dùng (thứ
// không việc gì phải vội) chiếm mấy dòng đầu và đẩy lô sắp hỏng xuống dưới —
// đúng ngược với thứ người mở bảng tồn ra để tìm.
func TestLoDoc_LoSapHetHanLenTruoc(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	donKho(t, h, a)
	nhapLo(t, h, a, "LO-XA", 4, "2031-12-31")
	nhapLo(t, h, a, "LO-GAN", 4, "2028-01-31")
	nhapLo(t, h, a, "LO-KHONGHAN", 4, "")

	d := timDong(t, docTonChiNhanh(t, h, a), a.chiNhanh, a.bienThe)

	thuTu := make([]string, 0, len(d.Lots))
	for _, l := range d.Lots {
		thuTu = append(thuTu, l.LotNumber)
	}

	viTri := func(so string) int {
		for i, s := range thuTu {
			if s == so {
				return i
			}
		}
		t.Fatalf("không thấy lô %q trong %v", so, thuTu)

		return -1
	}

	if viTri("LO-GAN") > viTri("LO-XA") {
		t.Fatalf("lô hết hạn sớm phải đứng trước lô hết hạn muộn, thứ tự nhận: %v", thuTu)
	}
	// Lô "Không xác định" do donKho để lại cũng không có hạn, nên chỉ so với lô
	// có hạn: mọi lô có hạn phải đứng trên mọi lô không hạn.
	if viTri("LO-KHONGHAN") < viTri("LO-XA") {
		t.Fatalf("lô không có hạn dùng phải xuống cuối, thứ tự nhận: %v", thuTu)
	}
}

// TestLoDoc_KhongGanLoSangNhamChiNhanh — lô của kho này không lọt sang dòng kho kia.
//
// Khoá của một dòng là CẶP (chi nhánh, biến thể); lọc theo mình id biến thể là
// kho A hiện lô của kho B, và người đi đếm hàng đứng tại kho A không có cách nào
// biết con số đó không phải của mình.
func TestLoDoc_KhongGanLoSangNhamChiNhanh(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	// Mở thêm một kho để bảng có hai chi nhánh cùng lúc.
	res0 := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/chi-nhanh", map[string]any{
		"name": "Kho hai " + a.vet, "phone": "0912345678", "address": "Ha Noi",
	})
	if res0.ma != http.StatusCreated {
		t.Fatalf("mo chi nhanh thu hai tra %d\n%s", res0.ma, catBot(res0.than))
	}
	var cn struct {
		Data struct {
			ID uint `json:"id"`
		} `json:"data"`
	}
	if err := json.Unmarshal([]byte(res0.than), &cn); err != nil {
		t.Fatalf("khong doc duoc phan hoi mo chi nhanh: %v\n%s", err, catBot(res0.than))
	}
	kho2 := cn.Data.ID

	donKho(t, h, a)
	nhapLo(t, h, a, "LO-KHO1", 6, "2030-06-30")

	res := h.goiChiNhanh(t, a.token, a.chiNhanh, http.MethodGet,
		fmt.Sprintf("/api/v1/admin/inventory/chi-nhanh?shops=%d,%d&page_size=200", a.chiNhanh, kho2), nil)
	if res.ma != http.StatusOK {
		t.Fatalf("đọc tồn hai kho trả %d\n%s", res.ma, catBot(res.than))
	}

	var body struct {
		Data struct {
			Dong []dongTon `json:"dong"`
		} `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &body); err != nil {
		t.Fatalf("không đọc được phản hồi: %v\n%s", err, catBot(res.than))
	}

	for _, d := range body.Data.Dong {
		if d.VariantID != a.bienThe {
			continue
		}
		for _, l := range d.Lots {
			if l.LotNumber == "LO-KHO1" && d.ShopID != a.chiNhanh {
				t.Fatalf("lô của chi nhánh %d lọt sang dòng của chi nhánh %d", a.chiNhanh, d.ShopID)
			}
		}
		// Bất biến vẫn phải đúng ở TỪNG kho.
		cong := 0
		for _, l := range d.Lots {
			cong += l.Quantity
		}
		if cong != d.Quantity {
			t.Fatalf("chi nhánh %d: tổng = %d nhưng cộng lô = %d", d.ShopID, d.Quantity, cong)
		}
	}
}
