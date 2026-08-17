package apitest

import (
	"encoding/json"
	"fmt"
	"net/http"
	"testing"

	"sass-api/internal/domain"
)

// Giai đoạn 3 — chủ shop tin được con số.
//
// Ba cụm, và cả ba đều chỉ có giá trị nếu CON SỐ đúng, nên bài kiểm ở đây bám
// vào con số chứ không bám vào mã HTTP: một endpoint trả 200 với số liệu sai còn
// tệ hơn một endpoint trả lỗi.

// doc đọc phần data của một lượt gọi thành map.
func doc(t *testing.T, res traLoi) map[string]any {
	t.Helper()

	var out struct {
		Data map[string]any `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &out); err != nil {
		t.Fatalf("không đọc được phản hồi: %v\n%s", err, catBot(res.than))
	}
	return out.Data
}

// ---------------------------------------------------------------------
//  A. Ca làm việc & sổ quỹ
// ---------------------------------------------------------------------

// moCa mở ca với số tiền đầu ca cho trước.
func moCa(t *testing.T, h *heThong, c *cuaHang, dauCa float64) map[string]any {
	t.Helper()

	res := h.goi(t, c.token, http.MethodPost, "/api/v1/admin/ca-lam-viec/mo",
		map[string]any{"opening_cash": dauCa})
	if res.ma != http.StatusCreated {
		t.Fatalf("mở ca trả %d\n%s", res.ma, catBot(res.than))
	}
	return doc(t, res)
}

// TestCaLamViec_TienBanHangTuVaoSoQuy — bán tiền mặt là tiền tự vào sổ, gắn đúng ca.
//
// Đây là mắt xích quyết định của cả cụm: nếu người bán phải tự ghi từng lượt bán
// vào sổ quỹ thì không ai ghi, và cuối ngày sổ trống trong khi két đầy tiền.
func TestCaLamViec_TienBanHangTuVaoSoQuy(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	moCa(t, h, a, 500000)

	// Bán một lượt tiền mặt: 2 × 90.000 (đã trừ khuyến mãi 10%).
	res := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/orders/pos", map[string]any{
		"payment_method": "cash",
		"items":          []map[string]any{{"product_variant_id": a.bienThe, "quantity": 2}},
	})
	if res.ma != http.StatusCreated {
		t.Fatalf("bán tại quầy trả %d\n%s", res.ma, catBot(res.than))
	}

	ca := doc(t, h.goi(t, a.token, http.MethodGet, "/api/v1/admin/ca-lam-viec/hien-tai", nil))
	if ca["tong_thu"].(float64) != 180000 {
		t.Fatalf("tổng thu của ca phải là 180000, đang là %v", ca["tong_thu"])
	}
	if int(ca["so_don_tien_mat"].(float64)) != 1 {
		t.Fatalf("số đơn tiền mặt phải là 1, đang là %v", ca["so_don_tien_mat"])
	}
}

// TestCaLamViec_ChuyenKhoanKhongVaoSoQuy — đơn chuyển khoản không đi qua két.
//
// Ghi nhầm vào đây là con số đối chiếu cuối ca không còn khớp tiền đếm được, tức
// là làm hỏng đúng thứ cả sổ quỹ sinh ra để phục vụ.
func TestCaLamViec_ChuyenKhoanKhongVaoSoQuy(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	moCa(t, h, a, 0)

	res := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/orders/pos", map[string]any{
		"payment_method": "bank_transfer",
		"items":          []map[string]any{{"product_variant_id": a.bienThe, "quantity": 1}},
	})
	if res.ma != http.StatusCreated {
		t.Fatalf("bán chuyển khoản trả %d\n%s", res.ma, catBot(res.than))
	}

	ca := doc(t, h.goi(t, a.token, http.MethodGet, "/api/v1/admin/ca-lam-viec/hien-tai", nil))
	if ca["tong_thu"].(float64) != 0 {
		t.Fatalf("chuyển khoản KHÔNG được vào sổ quỹ, mà tổng thu đang là %v", ca["tong_thu"])
	}
}

// TestCaLamViec_DongCaDoiChieuDungConSo — tiền theo sổ và chênh lệch tính đúng.
func TestCaLamViec_DongCaDoiChieuDungConSo(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	moCa(t, h, a, 500000)

	// Thu 180.000 từ một lượt bán…
	h.goi(t, a.token, http.MethodPost, "/api/v1/admin/orders/pos", map[string]any{
		"payment_method": "cash",
		"items":          []map[string]any{{"product_variant_id": a.bienThe, "quantity": 2}},
	})
	// …và chi 50.000 mua vặt.
	res := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/so-quy", map[string]any{
		"direction": "out", "amount": 50000, "reason": "Mua nước cho quầy",
	})
	if res.ma != http.StatusCreated {
		t.Fatalf("ghi sổ quỹ trả %d\n%s", res.ma, catBot(res.than))
	}

	// Theo sổ: 500.000 + 180.000 − 50.000 = 630.000. Đếm được 625.000 → thiếu 5.000.
	res = h.goi(t, a.token, http.MethodPost, "/api/v1/admin/ca-lam-viec/dong",
		map[string]any{"counted_cash": 625000})
	if res.ma != http.StatusOK {
		t.Fatalf("đóng ca trả %d\n%s", res.ma, catBot(res.than))
	}

	var out struct {
		Data struct {
			Ca struct {
				ExpectedCash float64 `json:"expected_cash"`
				CountedCash  float64 `json:"counted_cash"`
				Difference   float64 `json:"difference"`
				TongThu      float64 `json:"tong_thu"`
				TongChi      float64 `json:"tong_chi"`
			} `json:"ca"`
		} `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &out); err != nil {
		t.Fatalf("không đọc được kết quả đóng ca: %v", err)
	}
	d := out.Data.Ca

	if d.TongThu != 180000 || d.TongChi != 50000 {
		t.Fatalf("thu/chi phải là 180000/50000, đang là %v/%v", d.TongThu, d.TongChi)
	}
	if d.ExpectedCash != 630000 {
		t.Fatalf("tiền theo sổ phải là 630000 (500000 + 180000 − 50000), đang là %v", d.ExpectedCash)
	}
	if d.Difference != -5000 {
		t.Fatalf("chênh lệch phải là -5000 (thiếu két), đang là %v", d.Difference)
	}
}

// TestCaLamViec_MotChiNhanhChiMotCaMo — mở ca thứ hai bị chặn.
//
// Hai ca cùng mở nghĩa là hai người cùng nhận trách nhiệm về một cái két, và khi
// lệch thì không ai nhận cả.
func TestCaLamViec_MotChiNhanhChiMotCaMo(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	moCa(t, h, a, 0)

	res := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/ca-lam-viec/mo",
		map[string]any{"opening_cash": 0})
	if res.ma != http.StatusConflict {
		t.Fatalf("mở ca thứ hai phải trả 409, đang trả %d\n%s", res.ma, catBot(res.than))
	}
}

// TestCaLamViec_ChuaMoCaVanBanDuoc — chưa mở ca thì vẫn bán được.
//
// Đây là đánh đổi đã cân nhắc: chặn bán khi chưa mở ca thì một buổi sáng quên mở
// ca là cả tiệm đứng im. Tiền vẫn vào sổ, chỉ là chưa thuộc ca nào — và màn hình
// đóng ca có trách nhiệm chỉ chúng ra.
func TestCaLamViec_ChuaMoCaVanBanDuoc(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	res := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/orders/pos", map[string]any{
		"payment_method": "cash",
		"items":          []map[string]any{{"product_variant_id": a.bienThe, "quantity": 1}},
	})
	if res.ma != http.StatusCreated {
		t.Fatalf("chưa mở ca vẫn phải bán được, đang trả %d\n%s", res.ma, catBot(res.than))
	}

	// Chưa có ca nào: đường "ca hiện tại" trả null chứ không phải lỗi.
	res = h.goi(t, a.token, http.MethodGet, "/api/v1/admin/ca-lam-viec/hien-tai", nil)
	if res.ma != http.StatusOK {
		t.Fatalf("hỏi ca hiện tại khi chưa mở ca phải trả 200, đang trả %d", res.ma)
	}

	// Mở ca rồi đóng ngay: khoản tiền bán lúc nãy nằm NGOÀI ca và phải được chỉ ra.
	moCa(t, h, a, 0)
	res = h.goi(t, a.token, http.MethodPost, "/api/v1/admin/ca-lam-viec/dong",
		map[string]any{"counted_cash": 0})
	if res.ma != http.StatusOK {
		t.Fatalf("đóng ca trả %d\n%s", res.ma, catBot(res.than))
	}
	// Khoản bán trước khi mở ca có created_at sớm hơn opened_at nên không lọt vào
	// khoảng của ca — điều cần khẳng định ở đây là đóng ca KHÔNG nhận nhầm nó vào
	// con số đối chiếu.
	var out struct {
		Data struct {
			Ca struct {
				ExpectedCash float64 `json:"expected_cash"`
			} `json:"ca"`
		} `json:"data"`
	}
	_ = json.Unmarshal([]byte(res.than), &out)
	if out.Data.Ca.ExpectedCash != 0 {
		t.Fatalf("tiền bán TRƯỚC khi mở ca không được tính vào ca, mà tiền theo sổ đang là %v",
			out.Data.Ca.ExpectedCash)
	}
}

// ---------------------------------------------------------------------
//  B. Giá vốn chụp lúc bán & lãi gộp
// ---------------------------------------------------------------------

// TestGiaVon_ChupLucBanVaKhongDoiKhiNhapLoMoi — lãi gộp của quá khứ đứng yên.
//
// Đây là lý do cả cột cost_price tồn tại. Trước đó báo cáo đọc giá vốn HÔM NAY,
// nên nhập lô mới đắt hơn là lãi của mọi tháng trước tự co lại dù không đơn nào
// thay đổi — sổ sách tự sửa số liệu quá khứ thì không dùng để ra quyết định được.
func TestGiaVon_ChupLucBanVaKhongDoiKhiNhapLoMoi(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	// Dữ liệu gieo sẵn có vài đơn cũ cũng góp vào báo cáo, nên đóng băng giá vốn
	// của chúng trước: bài này nói về LƯỢT BÁN CỦA NÓ, không phải về tổng của kỳ.
	// Không đóng băng thì đổi giá vốn ở dưới cũng kéo theo mấy đơn nền — đúng
	// hành vi (chúng chưa có ảnh chụp nên lùi về giá hiện tại), nhưng che mất
	// đúng thứ đang kiểm.
	dongBangGiaVonNen(t, h)

	nen := laiGop(t, h, a)

	// Khai giá vốn 40.000 rồi bán một cái (giá bán 90.000 sau khuyến mãi).
	datGiaVon(t, h, a.bienThe, 40000)
	res := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/orders/pos", map[string]any{
		"payment_method": "cash",
		"items":          []map[string]any{{"product_variant_id": a.bienThe, "quantity": 1}},
	})
	if res.ma != http.StatusCreated {
		t.Fatalf("bán trả %d\n%s", res.ma, catBot(res.than))
	}

	truoc := laiGop(t, h, a)
	if truoc-nen != 50000 {
		t.Fatalf("lượt bán này phải góp 50000 lãi (90000 − 40000), đang góp %v", truoc-nen)
	}

	// Nhập lô mới đắt hơn: giá vốn HÔM NAY thành 70.000. Lãi của lượt bán đã
	// xong không được nhúc nhích.
	datGiaVon(t, h, a.bienThe, 70000)

	if sau := laiGop(t, h, a); sau != truoc {
		t.Fatalf("lãi gộp của lượt bán CŨ phải giữ nguyên %v, nhưng đổi thành %v sau khi nhập lô mới",
			truoc, sau)
	}
}

// dongBangGiaVonNen chụp giá vốn 0 cho mọi dòng hàng đã có sẵn trong database
// test, để chúng không đổi giá trị khi bài kiểm chỉnh giá vốn của biến thể.
func dongBangGiaVonNen(t *testing.T, h *heThong) {
	t.Helper()

	err := h.db.WithContext(ctxThoat()).
		Exec("UPDATE order_items SET cost_price = 0 WHERE cost_price IS NULL").Error
	if err != nil {
		t.Fatalf("không đóng băng được giá vốn nền: %v", err)
	}
}

// datGiaVon đặt giá vốn của một biến thể, đi thẳng vào database.
func datGiaVon(t *testing.T, h *heThong, variantID uint, gia float64) {
	t.Helper()

	err := h.db.WithContext(ctxThoat()).Model(&domain.ProductVariant{}).
		Where("id = ?", variantID).Update("cost_price", gia).Error
	if err != nil {
		t.Fatalf("không đặt được giá vốn: %v", err)
	}
}

// laiGop đọc lãi gộp của kỳ đang xem từ báo cáo doanh thu.
func laiGop(t *testing.T, h *heThong, c *cuaHang) float64 {
	t.Helper()

	res := h.goi(t, c.token, http.MethodGet, "/api/v1/admin/reports/revenue", nil)
	if res.ma != http.StatusOK {
		t.Fatalf("đọc báo cáo doanh thu trả %d\n%s", res.ma, catBot(res.than))
	}
	var out struct {
		Data struct {
			Totals struct {
				Profit float64 `json:"profit"`
			} `json:"totals"`
		} `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &out); err != nil {
		t.Fatalf("không đọc được báo cáo: %v", err)
	}
	return out.Data.Totals.Profit
}

// TestLaiGop_TachDuocTheoChiNhanh — báo cáo chia lãi theo từng kho.
func TestLaiGop_TachDuocTheoChiNhanh(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	dongBangGiaVonNen(t, h)
	datGiaVon(t, h, a.bienThe, 40000)

	// Đo phần TĂNG THÊM chứ không đo tổng: database test có sẵn vài đơn cũ, và
	// một bài kiểm chốt cứng tổng sẽ đỏ mỗi lần ai đó thêm một dòng vào dữ liệu
	// gieo — đỏ vì lý do chẳng liên quan gì tới thứ đang kiểm.
	nen := chiNhanhTrongBaoCao(t, h, a)

	h.goi(t, a.token, http.MethodPost, "/api/v1/admin/orders/pos", map[string]any{
		"payment_method": "cash",
		"items":          []map[string]any{{"product_variant_id": a.bienThe, "quantity": 2}},
	})

	sau := chiNhanhTrongBaoCao(t, h, a)

	// 2 × 90.000 = 180.000 doanh thu, 2 × 40.000 = 80.000 giá vốn.
	if got := sau.Revenue - nen.Revenue; got != 180000 {
		t.Fatalf("doanh thu của chi nhánh phải tăng 180000, đang tăng %v", got)
	}
	if got := sau.Cost - nen.Cost; got != 80000 {
		t.Fatalf("giá vốn của chi nhánh phải tăng 80000, đang tăng %v", got)
	}
	if got := sau.Profit - nen.Profit; got != 100000 {
		t.Fatalf("lãi gộp của chi nhánh phải tăng 100000, đang tăng %v", got)
	}
	// Biên lãi do server tính sẵn, phải khớp chính lãi/doanh thu của dòng đó.
	if mong := sau.Profit / sau.Revenue * 100; fmt.Sprintf("%.4f", sau.Margin) != fmt.Sprintf("%.4f", mong) {
		t.Fatalf("biên lãi phải là %.4f%%, đang là %.4f%%", mong, sau.Margin)
	}
}

// latChiNhanh là một dòng trong bảng lãi gộp theo chi nhánh.
type latChiNhanh struct {
	ShopID  uint    `json:"shop_id"`
	Label   string  `json:"label"`
	Revenue float64 `json:"revenue"`
	Cost    float64 `json:"cost"`
	Profit  float64 `json:"profit"`
	Margin  float64 `json:"margin"`
}

// chiNhanhTrongBaoCao đọc dòng của MỘT chi nhánh trong báo cáo lãi gộp.
func chiNhanhTrongBaoCao(t *testing.T, h *heThong, c *cuaHang) latChiNhanh {
	t.Helper()

	res := h.goi(t, c.token, http.MethodGet, "/api/v1/admin/reports/revenue", nil)
	if res.ma != http.StatusOK {
		t.Fatalf("đọc báo cáo doanh thu trả %d\n%s", res.ma, catBot(res.than))
	}
	var out struct {
		Data struct {
			ByShop []latChiNhanh `json:"by_shop"`
		} `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &out); err != nil {
		t.Fatalf("không đọc được báo cáo: %v", err)
	}

	for _, s := range out.Data.ByShop {
		if s.ShopID == c.chiNhanh {
			return s
		}
	}
	// Chưa có đơn nào của chi nhánh này trong kỳ — nền bằng 0, không phải lỗi.
	return latChiNhanh{ShopID: c.chiNhanh}
}

// ---------------------------------------------------------------------
//  C. Đổi hàng
// ---------------------------------------------------------------------

// TestDoiHang_HangCuVeKhoHangMoiRaKhoTrongMotLuot — cả hai vế cùng xảy ra.
func TestDoiHang_HangCuVeKhoHangMoiRaKho(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	tonDau := tonCua(t, h, a, a.chiNhanh, a.bienThe)

	// Bán 2 cái trước đã.
	res := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/orders/pos", map[string]any{
		"payment_method": "cash",
		"items":          []map[string]any{{"product_variant_id": a.bienThe, "quantity": 2}},
	})
	if res.ma != http.StatusCreated {
		t.Fatalf("bán trả %d\n%s", res.ma, catBot(res.than))
	}
	donCu := uint(doc(t, res)["order_id"].(float64))
	if got := tonCua(t, h, a, a.chiNhanh, a.bienThe); got != tonDau-2 {
		t.Fatalf("sau khi bán kho phải còn %d, đang là %d", tonDau-2, got)
	}

	// Khách trả 1 cái, lấy lại 1 cái CÙNG loại (đổi size trong đời thật).
	dongCu := dongHangDauTien(t, h, a, donCu)
	res = h.goi(t, a.token, http.MethodPost, "/api/v1/admin/orders/pos/doi-hang", map[string]any{
		"order_id":       donCu,
		"tra":            []map[string]any{{"order_item_id": dongCu, "quantity": 1}},
		"moi":            []map[string]any{{"product_variant_id": a.bienThe, "quantity": 1}},
		"payment_method": "cash",
	})
	if res.ma != http.StatusCreated {
		t.Fatalf("đổi hàng trả %d\n%s", res.ma, catBot(res.than))
	}
	d := doc(t, res)

	// Một vào một ra: kho không đổi so với ngay trước lượt đổi.
	if got := tonCua(t, h, a, a.chiNhanh, a.bienThe); got != tonDau-2 {
		t.Fatalf("đổi 1 lấy 1 thì kho phải giữ nguyên %d, đang là %d", tonDau-2, got)
	}
	// Cùng món cùng giá: chênh lệch bằng 0.
	if d["chenh_lech"].(float64) != 0 {
		t.Fatalf("đổi cùng món thì chênh lệch phải là 0, đang là %v", d["chenh_lech"])
	}
	if d["return_code"] == "" || d["order_code"] == "" {
		t.Fatalf("phải trả về cả mã phiếu trả lẫn mã đơn mới: %v", d)
	}
}

// dongHangDauTien trả id dòng hàng đầu tiên của một đơn.
func dongHangDauTien(t *testing.T, h *heThong, c *cuaHang, orderID uint) uint {
	t.Helper()

	don := donQuay(t, h, c, orderID)
	items, _ := don["items"].([]any)
	if len(items) == 0 {
		t.Fatalf("đơn %d không có dòng hàng nào", orderID)
	}
	return uint(items[0].(map[string]any)["id"].(float64))
}

// TestDoiHang_KhachTraLaiNhieuHonThiCuaHangTraTien — chênh lệch âm ghi CHI vào sổ quỹ.
func TestDoiHang_TraHangKhongLayGiThiTraLaiTien(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	moCa(t, h, a, 0)

	res := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/orders/pos", map[string]any{
		"payment_method": "cash",
		"items":          []map[string]any{{"product_variant_id": a.bienThe, "quantity": 1}},
	})
	donCu := uint(doc(t, res)["order_id"].(float64))
	dongCu := dongHangDauTien(t, h, a, donCu)

	// Trả lại, không lấy gì: cửa hàng trả lại 90.000.
	res = h.goi(t, a.token, http.MethodPost, "/api/v1/admin/orders/pos/doi-hang", map[string]any{
		"order_id":       donCu,
		"tra":            []map[string]any{{"order_item_id": dongCu, "quantity": 1}},
		"moi":            []map[string]any{},
		"payment_method": "cash",
	})
	if res.ma != http.StatusCreated {
		t.Fatalf("trả hàng không lấy gì phải hợp lệ, đang trả %d\n%s", res.ma, catBot(res.than))
	}
	if got := doc(t, res)["chenh_lech"].(float64); got != -90000 {
		t.Fatalf("chênh lệch phải là -90000 (cửa hàng trả lại), đang là %v", got)
	}

	// Sổ quỹ: thu 90.000 lúc bán, chi 90.000 lúc trả → về 0.
	ca := doc(t, h.goi(t, a.token, http.MethodGet, "/api/v1/admin/ca-lam-viec/hien-tai", nil))
	if ca["tong_thu"].(float64) != 90000 || ca["tong_chi"].(float64) != 90000 {
		t.Fatalf("sổ quỹ phải là thu 90000 / chi 90000, đang là %v/%v",
			ca["tong_thu"], ca["tong_chi"])
	}
}

// TestDoiHang_KhongTraQuaSoDaMua — trả nhiều hơn số đã mua thì bị chặn, và
// KHÔNG có vế nào của lượt đổi được ghi.
//
// Đây là bài canh tính toàn vẹn của cả giao dịch: hàng cũ về kho, hàng mới ra
// kho và dòng sổ quỹ nằm trong cùng một transaction, nên một lỗi ở giữa phải kéo
// lùi tất cả. Rời nhau ra thì lệch kho câm — không lỗi nào nổi lên.
func TestDoiHang_KhongTraQuaSoDaMua(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	res := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/orders/pos", map[string]any{
		"payment_method": "cash",
		"items":          []map[string]any{{"product_variant_id": a.bienThe, "quantity": 1}},
	})
	donCu := uint(doc(t, res)["order_id"].(float64))
	dongCu := dongHangDauTien(t, h, a, donCu)

	tonTruoc := tonCua(t, h, a, a.chiNhanh, a.bienThe)

	// Mua 1 mà đòi trả 3.
	res = h.goi(t, a.token, http.MethodPost, "/api/v1/admin/orders/pos/doi-hang", map[string]any{
		"order_id":       donCu,
		"tra":            []map[string]any{{"order_item_id": dongCu, "quantity": 3}},
		"moi":            []map[string]any{{"product_variant_id": a.bienThe, "quantity": 1}},
		"payment_method": "cash",
	})
	if res.ma != http.StatusBadRequest {
		t.Fatalf("trả quá số đã mua phải trả 400, đang trả %d\n%s", res.ma, catBot(res.than))
	}
	if sau := tonCua(t, h, a, a.chiNhanh, a.bienThe); sau != tonTruoc {
		t.Fatalf("RÒ KHO: lượt đổi bị chặn mà kho vẫn đổi từ %d thành %d", tonTruoc, sau)
	}
}

// TestDoiHang_NoiPhieuTraVoiDonMoi — tra được "khách đổi cái đó lấy cái gì".
func TestDoiHang_NoiPhieuTraVoiDonMoi(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	res := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/orders/pos", map[string]any{
		"payment_method": "cash",
		"items":          []map[string]any{{"product_variant_id": a.bienThe, "quantity": 1}},
	})
	donCu := uint(doc(t, res)["order_id"].(float64))
	dongCu := dongHangDauTien(t, h, a, donCu)

	res = h.goi(t, a.token, http.MethodPost, "/api/v1/admin/orders/pos/doi-hang", map[string]any{
		"order_id":       donCu,
		"tra":            []map[string]any{{"order_item_id": dongCu, "quantity": 1}},
		"moi":            []map[string]any{{"product_variant_id": a.bienThe, "quantity": 1}},
		"payment_method": "cash",
	})
	if res.ma != http.StatusCreated {
		t.Fatalf("đổi hàng trả %d\n%s", res.ma, catBot(res.than))
	}
	d := doc(t, res)
	phieuID := uint(d["return_id"].(float64))
	donMoi := uint(d["order_id"].(float64))

	// Phiếu trả phải trỏ tới đơn mới.
	res = h.goi(t, a.token, http.MethodGet, fmt.Sprintf("/api/v1/admin/returns/%d", phieuID), nil)
	if res.ma != http.StatusOK {
		t.Fatalf("đọc phiếu trả trả %d\n%s", res.ma, catBot(res.than))
	}
	var out struct {
		Data struct {
			ExchangeOrderID *uint  `json:"exchange_order_id"`
			Status          string `json:"status"`
		} `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &out); err != nil {
		t.Fatalf("không đọc được phiếu trả: %v", err)
	}
	if out.Data.ExchangeOrderID == nil || *out.Data.ExchangeOrderID != donMoi {
		t.Fatalf("phiếu trả phải trỏ tới đơn mới %d, đang là %v", donMoi, out.Data.ExchangeOrderID)
	}
	// Hàng đã cầm trên tay người bán nên phiếu sinh thẳng ở "đã nhận".
	if out.Data.Status != domain.ReturnStatusReceived {
		t.Fatalf("phiếu đổi hàng phải ở trạng thái %q, đang là %q",
			domain.ReturnStatusReceived, out.Data.Status)
	}
}
