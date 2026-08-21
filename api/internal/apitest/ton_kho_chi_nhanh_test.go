package apitest

import (
	"encoding/json"
	"fmt"
	"net/http"
	"strings"
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

// manTonChiNhanh gọi màn "Tồn kho chi nhánh" và trả về phần thân đã đọc.
//
// Đọc đúng hình dạng mà trang Blade đang đọc (data.dong / data.chi_nhanh): đổi
// tên trường bên API mà quên trang thì bảng trống trơn chứ không lỗi, nên hình
// dạng phải nằm trong bài kiểm.
func manTonChiNhanh(t *testing.T, h *heThong, c *cuaHang, query string) struct {
	Data struct {
		Dong []struct {
			ShopID      uint   `json:"shop_id"`
			ShopName    string `json:"shop_name"`
			VariantID   uint   `json:"variant_id"`
			ProductName string `json:"product_name"`
			Quantity    int    `json:"quantity"`
		} `json:"dong"`
		ChiNhanh []struct {
			ShopID   uint   `json:"shop_id"`
			ShopName string `json:"shop_name"`
			SoDong   int64  `json:"so_dong"`
			TongTon  int64  `json:"tong_ton"`
		} `json:"chi_nhanh"`
		Total int64 `json:"total"`
	} `json:"data"`
} {
	t.Helper()

	var out struct {
		Data struct {
			Dong []struct {
				ShopID      uint   `json:"shop_id"`
				ShopName    string `json:"shop_name"`
				VariantID   uint   `json:"variant_id"`
				ProductName string `json:"product_name"`
				Quantity    int    `json:"quantity"`
			} `json:"dong"`
			ChiNhanh []struct {
				ShopID   uint   `json:"shop_id"`
				ShopName string `json:"shop_name"`
				SoDong   int64  `json:"so_dong"`
				TongTon  int64  `json:"tong_ton"`
			} `json:"chi_nhanh"`
			Total int64 `json:"total"`
		} `json:"data"`
	}

	res := h.goi(t, c.token, http.MethodGet, "/api/v1/admin/inventory/chi-nhanh"+query, nil)
	if res.ma != http.StatusOK {
		t.Fatalf("màn tồn kho chi nhánh trả %d\n%s", res.ma, catBot(res.than))
	}
	if err := json.Unmarshal([]byte(res.than), &out); err != nil {
		t.Fatalf("không đọc được phản hồi: %v\n%s", err, catBot(res.than))
	}

	return out
}

// tonTrenMan tìm số tồn của một biến thể TẠI một chi nhánh trong bảng.
//
// Trả về (số, có thấy dòng không): thiếu dòng KHÁC tồn 0, và phân biệt được hai
// thứ đó chính là điều bài kiểm dưới đây quan tâm nhất.
func tonTrenMan(dong []struct {
	ShopID      uint   `json:"shop_id"`
	ShopName    string `json:"shop_name"`
	VariantID   uint   `json:"variant_id"`
	ProductName string `json:"product_name"`
	Quantity    int    `json:"quantity"`
}, shopID, variantID uint,
) (int, bool) {
	for _, d := range dong {
		if d.ShopID == shopID && d.VariantID == variantID {
			return d.Quantity, true
		}
	}

	return 0, false
}

// TestManTonKhoChiNhanh — màn "Tồn kho chi nhánh" phải trả lời được câu hỏi của
// chính nó: SỐ HÀNG ĐANG NẰM Ở ĐÂU.
//
// Ba điều được chốt ở đây, đều là chỗ dễ hỏng mà không báo lỗi:
//
//   - Chi nhánh CHƯA TỪNG nhập món hàng vẫn phải có dòng, với số 0. Nó không có
//     dòng nào trong variant_stocks, nên chỉ cần viết JOIN thay vì LEFT JOIN là
//     kho đó biến mất khỏi bảng — và người đi nhập hàng không bao giờ nhìn thấy
//     chỗ đang thiếu.
//   - Tổng ở đầu mỗi nhóm phải là tổng của TOÀN bộ lọc, khớp với dòng đang hiện.
//   - Lưới (chi nhánh × biến thể) được dựng bằng một phép nhân KHÔNG có khoá
//     nối, nên phải chứng minh nó không kéo chi nhánh của cửa hàng khác vào.
func TestManTonKhoChiNhanh(t *testing.T) {
	h := dungHeThong(t)
	a, b := haiCuaHang(t, h)

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

	// 1. Chi nhánh vừa mở chưa có hàng: phải CÓ DÒNG, số 0.
	man := manTonChiNhanh(t, h, a, "")
	if len(man.Data.ChiNhanh) != 2 {
		t.Fatalf("phải gom thành 2 nhóm chi nhánh, đang có %d", len(man.Data.ChiNhanh))
	}
	if got, ok := tonTrenMan(man.Data.Dong, goc, a.bienThe); !ok || got != 20 {
		t.Fatalf("chi nhánh gốc phải có dòng 20, đang là %d (thấy dòng: %v)", got, ok)
	}
	if got, ok := tonTrenMan(man.Data.Dong, phu, a.bienThe); !ok {
		t.Fatalf("chi nhánh mới mở BIẾN MẤT khỏi bảng — nó phải hiện ra với số 0 để còn biết mà nhập hàng")
	} else if got != 0 {
		t.Fatalf("chi nhánh mới mở phải là 0, đang là %d", got)
	}

	// 2. Nhập 7 vào chi nhánh phụ — bảng và tổng của nhóm phải đổi theo, đúng
	//    bằng nhau.
	res = h.goiChiNhanh(t, a.token, phu, http.MethodPut,
		fmt.Sprintf("/api/v1/admin/inventory/%d", a.bienThe),
		map[string]any{"mode": "delta", "quantity": 7})
	if res.ma != http.StatusOK {
		t.Fatalf("nhập hàng vào chi nhánh phụ trả %d\n%s", res.ma, catBot(res.than))
	}

	man = manTonChiNhanh(t, h, a, "")
	if got, _ := tonTrenMan(man.Data.Dong, phu, a.bienThe); got != 7 {
		t.Fatalf("chi nhánh phụ phải là 7, đang là %d", got)
	}
	if got, _ := tonTrenMan(man.Data.Dong, goc, a.bienThe); got != 20 {
		t.Fatalf("RÒ KHO: nhập vào chi nhánh phụ mà chi nhánh gốc thành %d", got)
	}
	for _, g := range man.Data.ChiNhanh {
		if g.ShopID == goc && g.TongTon != 20 {
			t.Fatalf("tổng của chi nhánh gốc phải là 20, đang là %d", g.TongTon)
		}
		if g.ShopID == phu && g.TongTon != 7 {
			t.Fatalf("tổng của chi nhánh phụ phải là 7, đang là %d", g.TongTon)
		}
	}

	// 3. Lọc đích danh một chi nhánh: bảng chỉ còn kho đó.
	man = manTonChiNhanh(t, h, a, fmt.Sprintf("?shops=%d", phu))
	if len(man.Data.ChiNhanh) != 1 || man.Data.ChiNhanh[0].ShopID != phu {
		t.Fatalf("lọc theo một chi nhánh mà vẫn trả %d nhóm", len(man.Data.ChiNhanh))
	}
	for _, d := range man.Data.Dong {
		if d.ShopID != phu {
			t.Fatalf("lọc chi nhánh %d mà bảng vẫn có dòng của chi nhánh %d", phu, d.ShopID)
		}
	}

	// 4. Cửa hàng KHÁC không được thấy chi nhánh hay hàng của cửa hàng này.
	manB := manTonChiNhanh(t, h, b, "")
	for _, g := range manB.Data.ChiNhanh {
		if g.ShopID == goc || g.ShopID == phu {
			t.Fatalf("RÒ DỮ LIỆU: cửa hàng khác thấy chi nhánh %d của cửa hàng này", g.ShopID)
		}
	}
	for _, d := range manB.Data.Dong {
		if d.VariantID == a.bienThe || strings.Contains(d.ProductName, a.vet) {
			t.Fatalf("RÒ DỮ LIỆU: cửa hàng khác thấy hàng %q của cửa hàng này", d.ProductName)
		}
	}
}

// soKho đọc sổ kho của một biến thể qua đúng đường mà modal xem nhanh gọi.
func soKho(t *testing.T, h *heThong, c *cuaHang, shopID uint, duong string) []struct {
	ShopID   uint   `json:"shop_id"`
	ShopName string `json:"shop_name"`
	Before   int    `json:"quantity_before"`
	After    int    `json:"quantity_after"`
} {
	t.Helper()

	var out struct {
		Data []struct {
			ShopID   uint   `json:"shop_id"`
			ShopName string `json:"shop_name"`
			Before   int    `json:"quantity_before"`
			After    int    `json:"quantity_after"`
		} `json:"data"`
	}

	var res traLoi
	if shopID == 0 {
		res = h.goi(t, c.token, http.MethodGet, duong, nil)
	} else {
		res = h.goiChiNhanh(t, c.token, shopID, http.MethodGet, duong, nil)
	}
	if res.ma != http.StatusOK {
		t.Fatalf("đọc sổ kho trả %d\n%s", res.ma, catBot(res.than))
	}
	if err := json.Unmarshal([]byte(res.than), &out); err != nil {
		t.Fatalf("không đọc được sổ kho: %v\n%s", err, catBot(res.than))
	}

	return out.Data
}

// TestSoKhoCatTheoChiNhanh — SỔ KHO PHẢI KỂ CHUYỆN CỦA ĐÚNG CÁI KHO ĐANG XEM.
//
// Trước bản này, sổ kho chỉ lọc theo biến thể. Người đang xem kho Quận 7 (tồn 3)
// mở lịch sử ra thấy bút toán của Quận 1 kèm "trước 40 → sau 41" — cặp số đúng
// với kho kia và mâu thuẫn với con số ngay trên màn hình, mà không có gì nói vì
// sao. Hỏng kiểu này không báo lỗi ở đâu cả: nó chỉ làm người đếm hàng mất niềm
// tin vào cả cái sổ.
func TestSoKhoCatTheoChiNhanh(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

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
	duongSo := fmt.Sprintf("/api/v1/admin/inventory/%d/history", a.bienThe)

	// Kho phụ chưa có bút toán nào. "Phát sinh cuối" của nó phải TRỐNG, dù biến
	// thể này có lịch sử dày ở kho gốc — đó là dấu hiệu người quản kho dựa vào để
	// biết kho nào đang nằm im.
	var chiTiet struct {
		Data struct {
			Item struct {
				LastMovedAt *string `json:"last_moved_at"`
			} `json:"item"`
		} `json:"data"`
	}
	res = h.goiChiNhanh(t, a.token, phu, http.MethodGet,
		fmt.Sprintf("/api/v1/admin/inventory/%d", a.bienThe), nil)
	if err := json.Unmarshal([]byte(res.than), &chiTiet); err != nil {
		t.Fatalf("không đọc được chi tiết: %v", err)
	}
	if chiTiet.Data.Item.LastMovedAt != nil {
		t.Fatalf("kho phụ chưa có bút toán nào mà phát sinh cuối vẫn là %q — đang lấy MAX của cả cửa hàng",
			*chiTiet.Data.Item.LastMovedAt)
	}
	if dong := soKho(t, h, a, phu, duongSo); len(dong) != 0 {
		t.Fatalf("kho phụ chưa có bút toán nào mà sổ trả về %d dòng", len(dong))
	}

	// Mỗi kho một lượt nhập, để hai bên đều có bút toán riêng.
	for _, ch := range []struct {
		shop uint
		so   int
	}{{phu, 7}, {goc, 5}} {
		res = h.goiChiNhanh(t, a.token, ch.shop, http.MethodPut,
			fmt.Sprintf("/api/v1/admin/inventory/%d", a.bienThe),
			map[string]any{"mode": "delta", "quantity": ch.so})
		if res.ma != http.StatusOK {
			t.Fatalf("nhập hàng vào chi nhánh %d trả %d\n%s", ch.shop, res.ma, catBot(res.than))
		}
	}

	// Đứng ở kho phụ: sổ chỉ được có bút toán của kho phụ, và cặp trước → sau
	// phải là số của CHÍNH kho đó (0 → 7), không phải số của cả cửa hàng.
	dong := soKho(t, h, a, phu, duongSo)
	if len(dong) != 1 {
		t.Fatalf("sổ của kho phụ phải có đúng 1 dòng, đang có %d", len(dong))
	}
	if dong[0].ShopID != phu {
		t.Fatalf("sổ của kho phụ lẫn bút toán của chi nhánh %d", dong[0].ShopID)
	}
	if dong[0].Before != 0 || dong[0].After != 7 {
		t.Fatalf("cặp trước → sau của kho phụ phải là 0 → 7, đang là %d → %d", dong[0].Before, dong[0].After)
	}

	// Đứng ở kho gốc: không thấy dòng nào của kho phụ.
	for _, d := range soKho(t, h, a, goc, duongSo) {
		if d.ShopID != goc {
			t.Fatalf("sổ của kho gốc lẫn bút toán của chi nhánh %d", d.ShopID)
		}
	}

	// Không gửi header = xem gộp: thấy cả hai kho, và mỗi dòng phải nói được nó
	// thuộc kho nào — thiếu tên kho thì bảng gộp đọc lên vô nghĩa.
	gop := soKho(t, h, a, 0, duongSo)
	thay := map[uint]bool{}
	for _, d := range gop {
		thay[d.ShopID] = true
		if d.ShopName == "" {
			t.Fatalf("dòng sổ của chi nhánh %d không kèm tên kho", d.ShopID)
		}
	}
	if !thay[goc] || !thay[phu] {
		t.Fatalf("xem gộp phải thấy cả hai kho, đang thấy %v", thay)
	}

	// Hỏi đích danh một kho bằng shop_id — đường mà màn "Tồn kho chi nhánh" dùng,
	// vì nó nhìn nhiều kho cùng lúc nên không đứng ở kho nào cả.
	for _, d := range soKho(t, h, a, 0, duongSo+fmt.Sprintf("?shop_id=%d", phu)) {
		if d.ShopID != phu {
			t.Fatalf("hỏi sổ của kho %d mà trả về dòng của kho %d", phu, d.ShopID)
		}
	}
}
