package apitest

import (
	"encoding/json"
	"fmt"
	"net/http"
	"testing"
)

// Bài kiểm CỦA CÂU HỎI CHÍNH: quầy bán được một lượt hàng thật chưa.
//
// Chạy qua API thật + MySQL thật vì thứ cần chứng minh nằm rải khắp các tầng và
// không tầng nào một mình nói được: đơn phải VÀO SỔ đúng kênh, kho phải TỤT
// đúng số, tiền phải ghi "đã thu", và tiền thối phải do server tính. Kiểm ở tầng
// service thì kho là bản giả — mà kho giả thì luôn trừ đúng, kể cả khi câu lệnh
// thật sai.

// donQuay đọc lại một đơn qua đúng đường màn hình chi tiết đơn đang dùng.
func donQuay(t *testing.T, h *heThong, c *cuaHang, id uint) map[string]any {
	t.Helper()

	res := h.goi(t, c.token, http.MethodGet, fmt.Sprintf("/api/v1/admin/orders/%d", id), nil)
	if res.ma != http.StatusOK {
		t.Fatalf("đọc lại đơn %d trả %d\n%s", id, res.ma, catBot(res.than))
	}
	var out struct {
		Data map[string]any `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &out); err != nil {
		t.Fatalf("không đọc được đơn: %v\n%s", err, catBot(res.than))
	}
	return out.Data
}

// TestBanTaiQuay_MotLuotBanTronVen — một lượt bán tại quầy đi trọn từ giỏ hàng
// tới đơn đã thu tiền, kho đã trừ và tiền thối đã tính.
func TestBanTaiQuay_MotLuotBanTronVen(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	truoc := tonCua(t, h, a, a.chiNhanh, a.bienThe)

	// Khách lẻ: KHÔNG gửi user_id. Đây là ca thường gặp nhất ở quầy và cũng là ca
	// mà luồng đặt đơn thủ công không làm được (nó bắt buộc user_id có thật).
	res := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/orders/pos", map[string]any{
		"payment_method":  "cash",
		"amount_tendered": 500000,
		"customer_name":   "Khách lẻ",
		"items": []map[string]any{
			{"product_variant_id": a.bienThe, "quantity": 2},
		},
	})
	if res.ma != http.StatusCreated {
		t.Fatalf("bán tại quầy trả %d\n%s", res.ma, catBot(res.than))
	}

	var ban struct {
		Data struct {
			OrderID        uint     `json:"order_id"`
			OrderCode      string   `json:"order_code"`
			Subtotal       float64  `json:"subtotal_amount"`
			Total          float64  `json:"total_amount"`
			AmountTendered *float64 `json:"amount_tendered"`
			ChangeAmount   *float64 `json:"change_amount"`
			Status         string   `json:"status"`
			PaymentStatus  string   `json:"payment_status"`
		} `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &ban); err != nil {
		t.Fatalf("không đọc được kết quả bán: %v\n%s", err, catBot(res.than))
	}
	d := ban.Data

	// Giá do server tra từ database VÀ đã trừ khuyến mãi đang chạy: hàng gieo giá
	// 100.000, đợt khuyến mãi của sản phẩm giảm 10%, nên 2 × 90.000. Payload trên
	// không hề có trường giá nào — người bán không gõ giá thì cũng không bán sai
	// giá được, và quầy không bao giờ nói một con số khác với web.
	if d.Subtotal != 180000 {
		t.Fatalf("tiền hàng phải là 180000 (đã trừ khuyến mãi 10%%), đang là %v", d.Subtotal)
	}
	// Không phí ship: hàng trao tay tại quầy.
	if d.Total != d.Subtotal {
		t.Fatalf("đơn quầy không được có phí ship: tiền hàng %v mà tổng %v", d.Subtotal, d.Total)
	}
	if d.ChangeAmount == nil || *d.ChangeAmount != 320000 {
		t.Fatalf("tiền thối phải là 320000, đang là %v", d.ChangeAmount)
	}
	if d.AmountTendered == nil || *d.AmountTendered != 500000 {
		t.Fatalf("tiền khách đưa phải ghi lại 500000, đang là %v", d.AmountTendered)
	}
	// Đây là điểm khác biệt của cả tính năng: xong trong MỘT bước, không còn gì
	// để xác nhận hay để giao.
	if d.Status != "completed" || d.PaymentStatus != "paid" {
		t.Fatalf("đơn quầy phải sinh ra completed/paid, đang là %s/%s", d.Status, d.PaymentStatus)
	}

	// Kho phải tụt đúng 2 — trừ thật, không phải chỉ ghi vào đơn.
	if sau := tonCua(t, h, a, a.chiNhanh, a.bienThe); sau != truoc-2 {
		t.Fatalf("kho phải tụt từ %d xuống %d, đang là %d", truoc, truoc-2, sau)
	}

	// Đơn vào sổ đúng kênh và KHÔNG mang địa chỉ giao — hai thứ khiến báo cáo về
	// sau tách được doanh thu quầy khỏi doanh thu web.
	don := donQuay(t, h, a, d.OrderID)
	if don["channel"] != "pos" {
		t.Fatalf("đơn phải mang channel=pos, đang là %v", don["channel"])
	}
	if don["shipping_address"] != "" {
		t.Fatalf("đơn quầy không được có địa chỉ giao, đang là %q", don["shipping_address"])
	}
	if don["user_id"] != nil {
		t.Fatalf("đơn khách lẻ không được gắn tài khoản nào, đang là %v", don["user_id"])
	}

	// Chi nhánh bán đơn phải được ghi lại. Bằng 0 nghĩa là đơn treo ở một chi
	// nhánh không tồn tại, và mọi con số chia theo chi nhánh sẽ bỏ sót nó.
	if fmt.Sprintf("%v", don["shop_id"]) != fmt.Sprintf("%v", float64(a.chiNhanh)) {
		t.Fatalf("đơn phải thuộc chi nhánh %d, đang là %v", a.chiNhanh, don["shop_id"])
	}

	// Lượt bán của sản phẩm. Đơn quầy sinh ra đã hoàn tất nên KHÔNG còn lượt
	// chuyển trạng thái nào về sau để cộng hộ — không cộng ngay lúc tạo thì mọi
	// thứ bán tại quầy vĩnh viễn vắng mặt trong "bán chạy nhất".
	if got := luotBan(t, h, a, a.sanPham); got != 2 {
		t.Fatalf("lượt bán của sản phẩm phải là 2, đang là %d", got)
	}
}

// luotBan đọc products.sold_count qua đúng đường màn hình sản phẩm đang dùng.
func luotBan(t *testing.T, h *heThong, c *cuaHang, productID uint) int {
	t.Helper()

	res := h.goi(t, c.token, http.MethodGet, fmt.Sprintf("/api/v1/admin/products/%d", productID), nil)
	if res.ma != http.StatusOK {
		t.Fatalf("đọc sản phẩm %d trả %d\n%s", productID, res.ma, catBot(res.than))
	}
	var out struct {
		Data struct {
			SoldCount int `json:"sold_count"`
		} `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &out); err != nil {
		t.Fatalf("không đọc được sản phẩm: %v\n%s", err, catBot(res.than))
	}
	return out.Data.SoldCount
}

// TestDatHangTrenWeb_DonThuocVeChiNhanhBan — đơn khách đặt từ storefront phải
// ghi lại chi nhánh bán, y như đơn quầy.
//
// Bài này canh một chỗ đã từng hỏng thật: đường đặt hàng dùng chung
// (orderRepo.Checkout) không hề gán shop_id, nên đơn đi vào sổ với giá trị 0 —
// và khoá ngoại sang bảng chi nhánh từ chối cả lượt insert. Nghĩa là KHÔNG khách
// nào đặt được hàng trên web. Không có bài kiểm nào chạy qua đường đặt hàng của
// storefront nên lỗi ấy lọt qua; đây là bài lấp chỗ đó.
func TestDatHangTrenWeb_DonThuocVeChiNhanhBan(t *testing.T) {
	h, a, _ := haiCuaHangBanHang(t)

	res := h.goiTuHost(t, hostA, "", http.MethodPost, "/api/v1/orders", map[string]any{
		"recipient_name":   "Khách web",
		"recipient_phone":  "0900000001",
		"shipping_address": "12 Đường Thử",
		"payment_method":   "cod",
		"items": []map[string]any{
			{"product_variant_id": a.bienThe, "quantity": 1},
		},
	})
	if res.ma != http.StatusCreated {
		t.Fatalf("khách đặt hàng trên web trả %d\n%s", res.ma, catBot(res.than))
	}

	var dat struct {
		Data struct {
			OrderID uint `json:"order_id"`
		} `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &dat); err != nil {
		t.Fatalf("không đọc được kết quả đặt hàng: %v\n%s", err, catBot(res.than))
	}

	don := donQuay(t, h, a, dat.Data.OrderID)
	if fmt.Sprintf("%v", don["shop_id"]) != fmt.Sprintf("%v", float64(a.chiNhanh)) {
		t.Fatalf("đơn web phải thuộc chi nhánh %d, đang là %v", a.chiNhanh, don["shop_id"])
	}
	// Và nó vẫn là đơn giao hàng, không bị lẫn sang kênh quầy.
	if don["channel"] != "web" {
		t.Fatalf("đơn đặt trên web phải mang channel=web, đang là %v", don["channel"])
	}
}

// TestBanTaiQuay_LocTheoKenh — trang đơn hàng tách được đơn quầy khỏi đơn giao.
//
// Không có bộ lọc này thì cột `channel` chỉ là dữ liệu nằm im: hai loại đơn vận
// hành khác hẳn nhau (một loại phải giao, một loại xong rồi) mà lại trộn chung
// một danh sách, nên người trực đơn phải tự đọc lướt để bỏ qua nửa số dòng.
func TestBanTaiQuay_LocTheoKenh(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	res := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/orders/pos", map[string]any{
		"payment_method": "cash",
		"items": []map[string]any{
			{"product_variant_id": a.bienThe, "quantity": 1},
		},
	})
	if res.ma != http.StatusCreated {
		t.Fatalf("bán tại quầy trả %d\n%s", res.ma, catBot(res.than))
	}

	// Dữ liệu gieo sẵn có hai đơn giao hàng, vừa thêm đúng một đơn quầy.
	quay := kenhCuaDanhSach(t, h, a, "pos")
	if len(quay) != 1 {
		t.Fatalf("lọc pos phải ra đúng 1 đơn, đang ra %d", len(quay))
	}

	web := kenhCuaDanhSach(t, h, a, "web")
	if len(web) == 0 {
		t.Fatalf("lọc web phải còn đơn giao hàng gieo sẵn, đang ra 0")
	}
	for _, k := range web {
		if k != "web" {
			t.Fatalf("lọc web mà lọt đơn kênh %q", k)
		}
	}

	// Không lọc = thấy cả hai, và tổng phải đúng bằng tổng hai nhóm.
	if tatCa := kenhCuaDanhSach(t, h, a, "all"); len(tatCa) != len(web)+len(quay) {
		t.Fatalf("không lọc phải ra %d đơn (%d web + %d quầy), đang ra %d",
			len(web)+len(quay), len(web), len(quay), len(tatCa))
	}
}

// kenhCuaDanhSach trả kênh của từng đơn mà trang danh sách hiện ra với bộ lọc đó.
func kenhCuaDanhSach(t *testing.T, h *heThong, c *cuaHang, kenh string) []string {
	t.Helper()

	res := h.goi(t, c.token, http.MethodGet, "/api/v1/admin/orders?channel="+kenh, nil)
	if res.ma != http.StatusOK {
		t.Fatalf("lọc kênh %q trả %d\n%s", kenh, res.ma, catBot(res.than))
	}
	var out struct {
		Data []struct {
			Channel string `json:"channel"`
		} `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &out); err != nil {
		t.Fatalf("không đọc được danh sách đơn: %v\n%s", err, catBot(res.than))
	}

	kenhs := make([]string, 0, len(out.Data))
	for _, d := range out.Data {
		kenhs = append(kenhs, d.Channel)
	}
	return kenhs
}

// TestBanTaiQuay_DuaThieuTienThiKhongBan — khách đưa thiếu thì cả lượt bán bị
// huỷ bỏ, kho không suy suyển.
//
// Điều thật sự kiểm ở đây là TÍNH TOÀN VẸN: lượt kiểm tiền nằm bên trong giao
// dịch đã trừ kho rồi, nên nếu nó chỉ trả lỗi mà không kéo giao dịch về thì cửa
// hàng mất hàng trên sổ cho một đơn chưa từng tồn tại.
func TestBanTaiQuay_DuaThieuTienThiKhongBan(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	truoc := tonCua(t, h, a, a.chiNhanh, a.bienThe)

	res := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/orders/pos", map[string]any{
		"payment_method":  "cash",
		"amount_tendered": 50000, // cần 100.000
		"items": []map[string]any{
			{"product_variant_id": a.bienThe, "quantity": 1},
		},
	})
	if res.ma != http.StatusBadRequest {
		t.Fatalf("đưa thiếu tiền phải trả 400, đang trả %d\n%s", res.ma, catBot(res.than))
	}

	if sau := tonCua(t, h, a, a.chiNhanh, a.bienThe); sau != truoc {
		t.Fatalf("RÒ KHO: lượt bán hỏng mà kho vẫn tụt từ %d xuống %d", truoc, sau)
	}
}

// TestBanTaiQuay_KhongBanVuotHang — quầy không bán được nhiều hơn số kho đang có.
//
// Đi cùng đường trừ kho của luồng web (khoá biến thể rồi mới trừ), nên bài này
// canh đúng chỗ dễ hỏng nhất khi thêm một đường bán thứ hai: đường mới quên khoá
// thì hai quầy cùng bán được món cuối cùng.
func TestBanTaiQuay_KhongBanVuotHang(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	truoc := tonCua(t, h, a, a.chiNhanh, a.bienThe)

	res := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/orders/pos", map[string]any{
		"payment_method": "cash",
		"items": []map[string]any{
			{"product_variant_id": a.bienThe, "quantity": truoc + 1},
		},
	})
	if res.ma != http.StatusBadRequest {
		t.Fatalf("bán vượt tồn phải trả 400, đang trả %d\n%s", res.ma, catBot(res.than))
	}

	if sau := tonCua(t, h, a, a.chiNhanh, a.bienThe); sau != truoc {
		t.Fatalf("kho phải giữ nguyên %d, đang là %d", truoc, sau)
	}
}
