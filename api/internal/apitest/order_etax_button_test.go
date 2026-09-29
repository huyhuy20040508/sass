package apitest

import (
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"strings"
	"testing"

	"sass-api/internal/domain"
	"sass-api/internal/tenant"
)

// coNutXuatHoaDon đọc cờ `xuat_duoc_hoa_don` của đơn `id` trên sổ đơn hàng.
func coNutXuatHoaDon(t *testing.T, h *heThong, c *cuaHang, id uint) bool {
	t.Helper()

	res := h.goi(t, c.token, http.MethodGet, "/api/v1/admin/orders/so-don?page_size=100", nil)
	if res.ma != http.StatusOK {
		t.Fatalf("đọc sổ đơn trả %d\n%s", res.ma, catBot(res.than))
	}
	var out struct {
		Data []struct {
			Loai           string `json:"loai"`
			ID             uint   `json:"id"`
			XuatDuocHoaDon bool   `json:"xuat_duoc_hoa_don"`
		} `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &out); err != nil {
		t.Fatalf("không đọc được sổ đơn: %v", err)
	}
	for _, d := range out.Data {
		if d.Loai == "don" && d.ID == id {
			return d.XuatDuocHoaDon
		}
	}
	t.Fatalf("sổ đơn không có đơn %d", id)

	return false
}

// TestQuanLyDonHang_NutXuatHoaDon — nút "Xuất HĐĐT" trên sổ đơn chỉ bật khi API
// sẽ nhận lượt bấm: đơn đã thu, chi nhánh đã nối cổng và chọn ký hiệu, đơn chưa có
// tờ nào còn hiệu lực. Và thân gửi kèm (hộp nhập người mua) được kiểm trước khi
// đụng tới đơn.
func TestQuanLyDonHang_NutXuatHoaDon(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	ban := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/orders/pos", map[string]any{
		"payment_method":  "cash",
		"amount_tendered": 100000000,
		"items":           []map[string]any{{"product_variant_id": a.bienThe, "quantity": 1}},
	})
	if ban.ma != http.StatusCreated {
		t.Fatalf("bán tại quầy trả %d\n%s", ban.ma, catBot(ban.than))
	}
	var kq struct {
		Data struct {
			OrderID uint `json:"order_id"`
		} `json:"data"`
	}
	_ = json.Unmarshal([]byte(ban.than), &kq)
	id := kq.Data.OrderID
	shopID := uint(donQuay(t, h, a, id)["shop_id"].(float64))

	if coNutXuatHoaDon(t, h, a, id) {
		t.Fatal("chi nhánh chưa nối cổng HĐĐT thì không được bày nút xuất")
	}

	ctx := tenant.WithID(context.Background(), a.id)
	ketNoi := &domain.EtaxConnection{
		ShopID: shopID, Provider: "minvoice", TaxCode: "0100000000", Username: "u",
		Password: "x", MaDVCS: "VP", IsActive: true,
	}
	if err := h.db.WithContext(ctx).Create(ketNoi).Error; err != nil {
		t.Fatalf("không gieo được kết nối HĐĐT: %v", err)
	}
	if coNutXuatHoaDon(t, h, a, id) {
		t.Fatal("đã nối cổng nhưng chưa chọn ký hiệu thì chưa xuất được")
	}

	if err := h.db.WithContext(ctx).Model(ketNoi).Update("template_symbol", "2C26TAA").Error; err != nil {
		t.Fatalf("không chọn được ký hiệu: %v", err)
	}
	if !coNutXuatHoaDon(t, h, a, id) {
		t.Fatal("đơn đã thu + chi nhánh sẵn sàng thì phải bày nút xuất")
	}

	// Doanh nghiệp thiếu MST / tên / địa chỉ: 422 theo từng ô, đơn không bị ghi.
	duong := fmt.Sprintf("/api/v1/admin/orders/%d/etax", id)
	thieu := h.goi(t, a.token, http.MethodPost, duong, map[string]any{"buyer_type": "company", "buyer_company": "Công ty X"})
	if thieu.ma != http.StatusUnprocessableEntity || !strings.Contains(thieu.than, "buyer_tax_code") || !strings.Contains(thieu.than, "buyer_address") {
		t.Fatalf("thiếu thông tin doanh nghiệp phải trả 422 theo ô, đang là %d\n%s", thieu.ma, catBot(thieu.than))
	}
	if don := donQuay(t, h, a, id); don["buyer_company"] != "" {
		t.Fatalf("lượt bị từ chối không được ghi người mua vào đơn, đang là %v", don["buyer_company"])
	}

	// Khách cá nhân mà không có tên: 422 ở ô buyer_name.
	khongTen := h.goi(t, a.token, http.MethodPost, duong, map[string]any{"buyer_type": "personal"})
	if khongTen.ma != http.StatusUnprocessableEntity || !strings.Contains(khongTen.than, "buyer_name") {
		t.Fatalf("khách cá nhân thiếu tên phải trả 422 ở buyer_name, đang là %d\n%s", khongTen.ma, catBot(khongTen.than))
	}

	// Tờ HỎNG không tính là đã có — nút vẫn bày để bấm lại.
	hong := &domain.EtaxInvoice{ShopID: shopID, OrderID: id, Provider: "minvoice", Symbol: "2C26TAA", Status: domain.HoaDonHong}
	if err := h.db.WithContext(ctx).Create(hong).Error; err != nil {
		t.Fatalf("không gieo được hoá đơn: %v", err)
	}
	if !coNutXuatHoaDon(t, h, a, id) {
		t.Fatal("tờ hỏng thì phải cho xuất lại")
	}

	// Có tờ còn hiệu lực: nút tắt, và API từ chối trước khi sửa người mua trên đơn.
	if err := h.db.WithContext(ctx).Model(hong).Update("status", domain.HoaDonDaGui).Error; err != nil {
		t.Fatalf("không đổi được trạng thái hoá đơn: %v", err)
	}
	if coNutXuatHoaDon(t, h, a, id) {
		t.Fatal("đơn đã có hoá đơn thì không được bày nút xuất lần nữa")
	}
	lai := h.goi(t, a.token, http.MethodPost, duong, map[string]any{
		"buyer_type": "company", "buyer_tax_code": "0101234567", "buyer_company": "Công ty X", "buyer_address": "1 Tràng Tiền",
	})
	if lai.ma != http.StatusConflict {
		t.Fatalf("đơn đã có hoá đơn phải trả 409, đang là %d\n%s", lai.ma, catBot(lai.than))
	}
	if don := donQuay(t, h, a, id); don["buyer_tax_code"] != "" {
		t.Fatalf("đơn đã có hoá đơn thì người mua trên đơn phải giữ nguyên, đang là %v", don["buyer_tax_code"])
	}
}
