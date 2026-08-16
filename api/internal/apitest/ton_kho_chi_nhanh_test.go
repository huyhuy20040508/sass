package apitest

import (
	"encoding/json"
	"fmt"
	"net/http"
	"testing"

	"sass-api/internal/middleware"
)

// Bài kiểm CỦA CÂU HỎI CHÍNH: hai chi nhánh có kho riêng thật không.
//
// Trước migration 0005, `shops` chỉ là cái tên: cả cửa hàng dùng chung một con số
// tồn ở `product_variants.stock_quantity`, nên bán ở Quận 1 thì kho Quận 7 cũng
// tụt theo. Bài kiểm này chạy qua API thật và MySQL thật vì đó là chỗ duy nhất
// chứng minh được điều ngược lại — sổ giả thì luôn tách rời, kể cả khi code
// không tách.
//
// Cách kiểm: nhập hàng vào chi nhánh A, rồi hỏi kho của từng chi nhánh bằng
// chính header mà Shop Admin gửi lên.

// goiChiNhanh gọi API kèm header chi nhánh đang làm việc.
func (h *heThong) goiChiNhanh(t *testing.T, token string, shopID uint, method, duong string, body any) traLoi {
	t.Helper()

	return h.goiVoiHeader(t, token, method, duong, body, map[string]string{
		middleware.HeaderChiNhanh: fmt.Sprintf("%d", shopID),
	})
}

// tonCua đọc số tồn mà trang Tồn kho hiển thị cho MỘT chi nhánh.
func tonCua(t *testing.T, h *heThong, c *cuaHang, shopID, variantID uint) int {
	t.Helper()

	res := h.goiChiNhanh(t, c.token, shopID, http.MethodGet,
		fmt.Sprintf("/api/v1/admin/inventory/%d", variantID), nil)
	if res.ma != http.StatusOK {
		t.Fatalf("đọc tồn kho chi nhánh %d trả %d\n%s", shopID, res.ma, catBot(res.than))
	}

	// Phản hồi bọc hai lớp: data.item — đúng hình dạng mà trang Tồn kho đọc.
	var out struct {
		Data struct {
			Item struct {
				StockQuantity int `json:"stock_quantity"`
			} `json:"item"`
		} `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &out); err != nil {
		t.Fatalf("không đọc được tồn kho: %v\n%s", err, catBot(res.than))
	}

	return out.Data.Item.StockQuantity
}

// TestTonKhoTachTheoChiNhanh — chỉnh kho ở chi nhánh này KHÔNG đụng tới chi
// nhánh kia, và bản cộng của cả cửa hàng bằng đúng tổng hai bên.
func TestTonKhoTachTheoChiNhanh(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	// Mở chi nhánh thứ hai qua đúng đường người dùng đi.
	res := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/chi-nhanh",
		map[string]any{"name": "Kho phu " + a.vet, "code": "kho-phu"})
	if res.ma != http.StatusCreated {
		t.Fatalf("mở chi nhánh thứ hai trả %d\n%s", res.ma, catBot(res.than))
	}
	var tao struct {
		Data struct {
			ID uint `json:"id"`
		} `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &tao); err != nil {
		t.Fatalf("không đọc được chi nhánh vừa mở: %v", err)
	}
	goc, phu := a.chiNhanh, tao.Data.ID

	// Dữ liệu gieo sẵn: 20 cái nằm ở chi nhánh gốc (migration 0005 dồn về đó).
	if got := tonCua(t, h, a, goc, a.bienThe); got != 20 {
		t.Fatalf("chi nhánh gốc phải có 20, đang có %d", got)
	}
	// Chi nhánh vừa mở chưa có hàng — KHÔNG có dòng trong variant_stocks, và phải
	// đọc ra 0 chứ không phải rỗng hay lỗi.
	if got := tonCua(t, h, a, phu, a.bienThe); got != 0 {
		t.Fatalf("chi nhánh mới mở phải có 0, đang có %d", got)
	}

	// Nhập 7 cái vào CHI NHÁNH PHỤ.
	res = h.goiChiNhanh(t, a.token, phu, http.MethodPut,
		fmt.Sprintf("/api/v1/admin/inventory/%d", a.bienThe),
		map[string]any{"mode": "delta", "quantity": 7})
	if res.ma != http.StatusOK {
		t.Fatalf("chỉnh kho chi nhánh phụ trả %d\n%s", res.ma, catBot(res.than))
	}

	// Đây là câu hỏi của cả bài: kho gốc có bị đụng không.
	if got := tonCua(t, h, a, goc, a.bienThe); got != 20 {
		t.Fatalf("RÒ KHO: nhập vào chi nhánh phụ mà chi nhánh gốc đổi thành %d (phải giữ 20)", got)
	}
	if got := tonCua(t, h, a, phu, a.bienThe); got != 7 {
		t.Fatalf("chi nhánh phụ phải có 7, đang có %d", got)
	}

	// Không gửi header = nhìn GỘP cả cửa hàng: đây là con số trang bán hàng và
	// báo cáo đang đọc, nên nó phải bằng đúng tổng hai chi nhánh.
	res = h.goi(t, a.token, http.MethodGet, fmt.Sprintf("/api/v1/admin/inventory/%d", a.bienThe), nil)
	if res.ma != http.StatusOK {
		t.Fatalf("đọc tồn gộp trả %d\n%s", res.ma, catBot(res.than))
	}
	var gop struct {
		Data struct {
			Item struct {
				StockQuantity int `json:"stock_quantity"`
			} `json:"item"`
		} `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &gop); err != nil {
		t.Fatalf("không đọc được tồn gộp: %v", err)
	}
	if gop.Data.Item.StockQuantity != 27 {
		t.Fatalf("bản cộng của cửa hàng phải là 27 (20 + 7), đang là %d", gop.Data.Item.StockQuantity)
	}
}

// TestSoConTrenManHinhLapPhieu — CỘT "CÒN" PHẢI HỎI ĐÚNG CÁI KHO SẼ BỊ TRỪ.
//
// Tách kho ra theo chi nhánh mới xong một nửa nếu đường GHI trừ kho A còn đường
// ĐỌC vẫn khoe số của cả cửa hàng. Cái lệch đó không báo lỗi lúc mở màn hình —
// nó chờ tới lúc người ta chốt phiếu, tức là sau khi đã hứa với nhà cung cấp
// hoặc đã nhận tiền của khách.
//
// Hai màn hình, hai câu trả lời KHÁC NHAU cho cùng một biến thể, và đó chính là
// điều bài này chứng minh:
//
//   - lập phiếu NHẬP: kho sẽ nhận hàng = chi nhánh đang đứng;
//   - lập phiếu TRẢ nhà cung cấp: kho đã nhận lô hàng = chi nhánh của PHIẾU ĐẶT
//     GỐC, kể cả khi người lập đang đứng ở chi nhánh khác.
func TestSoConTrenManHinhLapPhieu(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	phu := moChiNhanh(t, h, a, "kho-phu-2")

	// 20 cái ở chi nhánh gốc (dữ liệu gieo sẵn), 7 cái ở chi nhánh phụ.
	res := h.goiChiNhanh(t, a.token, phu, http.MethodPut,
		fmt.Sprintf("/api/v1/admin/inventory/%d", a.bienThe),
		map[string]any{"mode": "delta", "quantity": 7})
	if res.ma != http.StatusOK {
		t.Fatalf("nhập hàng vào chi nhánh phụ trả %d\n%s", res.ma, catBot(res.than))
	}

	// --- Màn hình lập phiếu NHẬP ---
	//
	// 27 là bản cộng của cả cửa hàng. Con số đó xuất hiện ở đây nghĩa là người
	// lập phiếu đang đặt hàng theo tồn của một kho khác.
	if got := tonKhiLapPhieuNhap(t, h, a, phu); got != 7 {
		t.Fatalf("đứng ở chi nhánh phụ mà cột 'còn' của phiếu nhập ghi %d — phải là 7 (27 = đang đọc bản cộng cả cửa hàng)", got)
	}
	if got := tonKhiLapPhieuNhap(t, h, a, a.chiNhanh); got != 20 {
		t.Fatalf("đứng ở chi nhánh gốc mà cột 'còn' của phiếu nhập ghi %d — phải là 20", got)
	}

	// --- Màn hình lập phiếu TRẢ nhà cung cấp ---
	//
	// Lô hàng của phiếu `phieuNhan` đã về kho GỐC, nên dù người lập đang đứng ở
	// chi nhánh phụ (nơi chỉ có 7 cái), số hiển thị vẫn phải là 20 — đúng cái kho
	// mà lượt chốt phiếu sẽ trừ đi.
	res = h.goiChiNhanh(t, a.token, phu, http.MethodGet,
		fmt.Sprintf("/api/v1/admin/purchase-returns/returnable/%d", a.phieuNhan), nil)
	if res.ma != http.StatusOK {
		t.Fatalf("đọc dòng trả được của phiếu nhập trả %d\n%s", res.ma, catBot(res.than))
	}
	var traDuoc struct {
		Data []struct {
			Stock int `json:"stock"`
		} `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &traDuoc); err != nil {
		t.Fatalf("không đọc được danh sách trả được: %v\n%s", err, catBot(res.than))
	}
	if len(traDuoc.Data) == 0 {
		t.Fatalf("phiếu nhập phải có dòng trả lại được:\n%s", catBot(res.than))
	}
	if got := traDuoc.Data[0].Stock; got != 20 {
		t.Fatalf("tồn trên phiếu trả ghi %d — phải là 20, tồn của KHO ĐÃ NHẬN lô hàng (7 = kho người đang đứng, 27 = bản cộng cả cửa hàng)", got)
	}
}

// moChiNhanh mở thêm một chi nhánh qua đúng đường người dùng đi và trả về id.
func moChiNhanh(t *testing.T, h *heThong, c *cuaHang, ma string) uint {
	t.Helper()

	res := h.goi(t, c.token, http.MethodPost, "/api/v1/admin/chi-nhanh",
		map[string]any{"name": "Kho " + ma + " " + c.vet, "code": ma})
	if res.ma != http.StatusCreated {
		t.Fatalf("mở chi nhánh %s trả %d\n%s", ma, res.ma, catBot(res.than))
	}

	var tao struct {
		Data struct {
			ID uint `json:"id"`
		} `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &tao); err != nil {
		t.Fatalf("không đọc được chi nhánh vừa mở: %v", err)
	}

	return tao.Data.ID
}

// tonKhiLapPhieuNhap đọc cột "còn" của ô chọn hàng trên màn hình lập phiếu nhập.
func tonKhiLapPhieuNhap(t *testing.T, h *heThong, c *cuaHang, shopID uint) int {
	t.Helper()

	res := h.goiChiNhanh(t, c.token, shopID, http.MethodGet,
		"/api/v1/admin/purchases/variants?keyword=sku-"+c.vet+"-m", nil)
	if res.ma != http.StatusOK {
		t.Fatalf("tìm hàng để nhập trả %d\n%s", res.ma, catBot(res.than))
	}

	var out struct {
		Data []struct {
			VariantID uint `json:"variant_id"`
			Stock     int  `json:"stock"`
		} `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &out); err != nil {
		t.Fatalf("không đọc được danh sách hàng: %v\n%s", err, catBot(res.than))
	}
	for _, v := range out.Data {
		if v.VariantID == c.bienThe {
			return v.Stock
		}
	}
	t.Fatalf("không thấy biến thể %d trong ô chọn hàng:\n%s", c.bienThe, catBot(res.than))

	return 0
}

// TestChiNhanhCuaTiemKhacKhongDungDuoc — header chi nhánh là con số do trình
// duyệt gửi lên, nên nó phải bị đối chiếu.
//
// Không có lượt đối chiếu đó thì chủ tiệm A gõ id chi nhánh của tiệm B rồi ghi
// hàng vào kho của họ — bộ lọc tenant KHÔNG đỡ được, vì nó canh cột `tenant_id`
// chứ không biết `shop_id` vừa nhận thuộc về ai.
func TestChiNhanhCuaTiemKhacKhongDungDuoc(t *testing.T) {
	h := dungHeThong(t)
	a, b := haiCuaHang(t, h)

	res := h.goiChiNhanh(t, a.token, b.chiNhanh, http.MethodGet, "/api/v1/admin/inventory", nil)
	if res.ma != http.StatusForbidden {
		t.Fatalf("RÒ RỈ: cửa hàng %s dùng được chi nhánh của %s — trả %d\n%s",
			a.ma, b.ma, res.ma, catBot(res.than))
	}

	// Đối chứng: chi nhánh của CHÍNH MÌNH thì phải chạy, nếu không lượt kiểm trên
	// chẳng chứng minh được gì (một middleware từ chối tất cũng "an toàn").
	if res = h.goiChiNhanh(t, a.token, a.chiNhanh, http.MethodGet, "/api/v1/admin/inventory", nil); res.ma != http.StatusOK {
		t.Fatalf("đối chứng hỏng: chi nhánh của chính mình trả %d\n%s", res.ma, catBot(res.than))
	}
}
