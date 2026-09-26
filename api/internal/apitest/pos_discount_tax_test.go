package apitest

import (
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"strings"
	"testing"

	"sass-api/internal/tenant"
)

// Giảm cả đơn, phụ thu, thuế sản phẩm, khách mới và hoá đơn điện tử ở QUẦY.
//
// Chạy trên API thật + MySQL thật vì con số phải khớp qua ba tầng: service tính,
// repository ghi đủ cột mới (migration 0067), và đường đọc đơn trả lại đúng như
// đã ghi. Kiểm riêng từng tầng thì một cột quên khai trong migration vẫn xanh.

// TestBanTaiQuay_GiamDonPhuThuThue — tổng tiền = tiền hàng − giảm + phụ thu + thuế,
// với thuế tính trên phần tiền hàng SAU khi trừ giảm cả đơn.
func TestBanTaiQuay_GiamDonPhuThuThue(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	ctx := tenant.WithoutScope(context.Background(), "test: đặt thuế suất cho mặt hàng gieo sẵn")
	if err := h.db.WithContext(ctx).Exec("UPDATE products SET vat = 10 WHERE id = ? AND tenant_id = ?", a.sanPham, a.id).Error; err != nil {
		t.Fatalf("không đặt được thuế suất: %v", err)
	}

	res := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/orders/pos", map[string]any{
		"payment_method":        "cash",
		"amount_tendered":       200000,
		"order_discount_amount": 18000,
		"surcharge_amount":      20000,
		"surcharge_note":        " Gói quà ",
		"items":                 []map[string]any{{"product_variant_id": a.bienThe, "quantity": 2}},
	})
	if res.ma != http.StatusCreated {
		t.Fatalf("bán tại quầy trả %d\n%s", res.ma, catBot(res.than))
	}

	var ban struct {
		Data struct {
			OrderID       uint     `json:"order_id"`
			Subtotal      float64  `json:"subtotal_amount"`
			Discount      float64  `json:"discount_amount"`
			OrderDiscount float64  `json:"order_discount_amount"`
			Surcharge     float64  `json:"surcharge_amount"`
			VatAmount     float64  `json:"vat_amount"`
			Total         float64  `json:"total_amount"`
			ChangeAmount  *float64 `json:"change_amount"`
			EInvoice      any      `json:"einvoice"`
		} `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &ban); err != nil {
		t.Fatalf("không đọc được kết quả bán: %v\n%s", err, catBot(res.than))
	}
	d := ban.Data

	// 2 × 90.000 (đã trừ khuyến mãi 10%) = 180.000. Giảm cả đơn 18.000 → phần chịu
	// thuế 162.000 → thuế 10% = 16.200. Cộng phụ thu 20.000 (không chịu thuế).
	if d.Subtotal != 180000 || d.Discount != 18000 || d.OrderDiscount != 18000 {
		t.Fatalf("tiền hàng/giảm phải là 180000/18000/18000, đang là %v/%v/%v", d.Subtotal, d.Discount, d.OrderDiscount)
	}
	if d.Surcharge != 20000 || d.VatAmount != 16200 {
		t.Fatalf("phụ thu/thuế phải là 20000/16200, đang là %v/%v", d.Surcharge, d.VatAmount)
	}
	if d.Total != 198200 {
		t.Fatalf("tổng phải là 180000 − 18000 + 20000 + 16200 = 198200, đang là %v", d.Total)
	}
	if d.ChangeAmount == nil || *d.ChangeAmount != 1800 {
		t.Fatalf("tiền thối phải tính trên tổng ĐÃ cộng thuế và phụ thu (1800), đang là %v", d.ChangeAmount)
	}
	// Không bật hoá đơn điện tử thì không có khối kết quả hoá đơn.
	if d.EInvoice != nil {
		t.Fatalf("không bật xuất hoá đơn thì không được có einvoice, đang là %v", d.EInvoice)
	}

	// Đọc lại qua đường chi tiết đơn: mọi cột mới phải thật sự nằm trong sổ.
	don := donQuay(t, h, a, d.OrderID)
	for cot, mong := range map[string]float64{
		"vat_amount": 16200, "surcharge_amount": 20000, "order_discount_amount": 18000,
		"discount_amount": 18000, "total_amount": 198200,
	} {
		if don[cot] != mong {
			t.Fatalf("đơn đọc lại: %s phải là %v, đang là %v", cot, mong, don[cot])
		}
	}
	if don["surcharge_note"] != "Gói quà" {
		t.Fatalf("lý do phụ thu phải được cắt khoảng trắng và ghi lại, đang là %q", don["surcharge_note"])
	}
	items, _ := don["items"].([]any)
	if len(items) != 1 {
		t.Fatalf("đơn phải có 1 dòng, đang có %d", len(items))
	}
	dong, _ := items[0].(map[string]any)
	// Thuế suất CHỤP vào dòng: chủ tiệm đổi thuế mặt hàng sau này thì hoá đơn phát
	// hành bù cho đơn này vẫn mang 10%.
	if dong["vat"] != float64(10) || dong["vat_amount"] != float64(16200) {
		t.Fatalf("dòng đơn phải chụp vat=10, vat_amount=16200, đang là %v/%v", dong["vat"], dong["vat_amount"])
	}
}

// TestBanTaiQuay_GiamDonVuotQuyenNhanVien — giảm cả đơn chịu CÙNG hạn quyền với
// giảm từng dòng, kể cả khi nhân viên gõ số tiền thay vì phần trăm.
func TestBanTaiQuay_GiamDonVuotQuyenNhanVien(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	thuNgan := h.dangNhapVoi(t, a.ma, "nhanvien")

	truoc := tonCua(t, h, a, a.chiNhanh, a.bienThe)

	for ten, giam := range map[string]map[string]any{
		"phần trăm": {"order_discount_percent": 90},
		// 170.000 trên tiền hàng 180.000 là ~94% — gõ số tiền không lách được hạn.
		"số tiền": {"order_discount_amount": 170000},
	} {
		than := map[string]any{
			"payment_method": "bank_transfer",
			"items":          []map[string]any{{"product_variant_id": a.bienThe, "quantity": 2}},
		}
		for k, v := range giam {
			than[k] = v
		}
		res := h.goi(t, thuNgan, http.MethodPost, "/api/v1/admin/orders/pos", than)
		if res.ma != http.StatusForbidden {
			t.Fatalf("giảm cả đơn theo %s vượt quyền phải bị chặn 403, đang là %d\n%s", ten, res.ma, catBot(res.than))
		}
		if !strings.Contains(res.than, "giảm cả đơn tối đa") {
			t.Fatalf("câu báo phải nói mức tối đa của giảm cả đơn\n%s", catBot(res.than))
		}
	}

	// Bị chặn là KHÔNG bán: kho còn nguyên.
	if sau := tonCua(t, h, a, a.chiNhanh, a.bienThe); sau != truoc {
		t.Fatalf("lượt bán bị chặn không được trừ kho: %d → %d", truoc, sau)
	}
}

// TestBanTaiQuay_ThemKhachTaiQuay — thu ngân (không có quyền khu Khách hàng) thêm
// và tra được khách ngay ở quầy; trùng số điện thoại thì dùng lại hồ sơ cũ; cửa
// hàng khác không nhìn thấy khách này.
func TestBanTaiQuay_ThemKhachTaiQuay(t *testing.T) {
	h := dungHeThong(t)
	a, b := haiCuaHang(t, h)
	thuNgan := h.dangNhapVoi(t, a.ma, "nhanvien")

	ten := "Khách quầy " + a.vet
	res := h.goi(t, thuNgan, http.MethodPost, "/api/v1/admin/orders/pos/khach-hang", map[string]any{
		"full_name": ten, "phone": "0909 555 123", "address": "12 Lê Lợi",
	})
	if res.ma != http.StatusCreated {
		t.Fatalf("thêm khách tại quầy trả %d\n%s", res.ma, catBot(res.than))
	}
	var tao struct {
		Data struct {
			Customer struct {
				ID       uint   `json:"id"`
				FullName string `json:"full_name"`
			} `json:"customer"`
			Existed bool `json:"existed"`
		} `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &tao); err != nil || tao.Data.Customer.ID == 0 || tao.Data.Existed {
		t.Fatalf("phải trả hồ sơ mới (existed=false): %v\n%s", err, catBot(res.than))
	}

	// Cùng số, khác cách gõ: KHÔNG tạo bản thứ hai.
	lai := h.goi(t, thuNgan, http.MethodPost, "/api/v1/admin/orders/pos/khach-hang", map[string]any{
		"full_name": "Tên gõ khác", "phone": "0909555123",
	})
	if lai.ma != http.StatusOK {
		t.Fatalf("trùng số điện thoại phải trả 200 kèm hồ sơ cũ, đang là %d\n%s", lai.ma, catBot(lai.than))
	}
	var trung struct {
		Data struct {
			Customer struct {
				ID uint `json:"id"`
			} `json:"customer"`
			Existed bool `json:"existed"`
		} `json:"data"`
	}
	_ = json.Unmarshal([]byte(lai.than), &trung)
	if !trung.Data.Existed || trung.Data.Customer.ID != tao.Data.Customer.ID {
		t.Fatalf("phải trả đúng hồ sơ %d với existed=true\n%s", tao.Data.Customer.ID, catBot(lai.than))
	}

	// Tra lại bằng đường của quầy.
	tim := h.goi(t, thuNgan, http.MethodGet, "/api/v1/admin/orders/pos/khach-hang?keyword="+a.vet, nil)
	if tim.ma != http.StatusOK || !strings.Contains(tim.than, fmt.Sprintf(`"id":%d`, tao.Data.Customer.ID)) {
		t.Fatalf("thu ngân phải tra được khách vừa thêm, trả %d\n%s", tim.ma, catBot(tim.than))
	}
	// Khu Khách hàng của chủ tiệm vẫn đóng với thu ngân — đường quầy không mở nó ra.
	if cam := h.goi(t, thuNgan, http.MethodGet, "/api/v1/admin/customers", nil); cam.ma != http.StatusForbidden {
		t.Fatalf("/admin/customers phải vẫn đóng với thu ngân, đang là %d", cam.ma)
	}
	// Cửa hàng khác không thấy khách này.
	if khac := h.goi(t, b.token, http.MethodGet, "/api/v1/admin/orders/pos/khach-hang?keyword="+a.vet, nil); strings.Contains(khac.than, ten) {
		t.Fatalf("cửa hàng khác tra ra khách của cửa hàng này\n%s", catBot(khac.than))
	}
}

// TestBanTaiQuay_HoaDonDienTuTaiQuay — bật xuất hoá đơn mà chi nhánh chưa nối cổng:
// đơn VẪN bán xong và câu báo nói rõ lý do; nút bấm lại chỉ nhận đơn quầy.
func TestBanTaiQuay_HoaDonDienTuTaiQuay(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	thuNgan := h.dangNhapVoi(t, a.ma, "nhanvien")

	res := h.goi(t, thuNgan, http.MethodPost, "/api/v1/admin/orders/pos", map[string]any{
		"payment_method": "bank_transfer",
		"issue_einvoice": true,
		"buyer_tax_code": "0101234567",
		"buyer_company":  "Công ty " + a.vet,
		"buyer_address":  "1 Tràng Tiền",
		"customer_email": "ketoan@example.com",
		"items":          []map[string]any{{"product_variant_id": a.bienThe, "quantity": 1}},
	})
	if res.ma != http.StatusCreated {
		t.Fatalf("hoá đơn hỏng không được làm hỏng lượt bán: trả %d\n%s", res.ma, catBot(res.than))
	}
	var ban struct {
		Data struct {
			OrderID  uint `json:"order_id"`
			EInvoice *struct {
				OK      bool   `json:"ok"`
				Message string `json:"message"`
			} `json:"einvoice"`
		} `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &ban); err != nil {
		t.Fatalf("không đọc được kết quả bán: %v", err)
	}
	if ban.Data.EInvoice == nil || ban.Data.EInvoice.OK || !strings.Contains(ban.Data.EInvoice.Message, "chưa kết nối") {
		t.Fatalf("phải báo chưa xuất được vì chi nhánh chưa kết nối, đang là %+v", ban.Data.EInvoice)
	}

	// Người mua lấy hoá đơn được ghi vào đơn — lượt bấm lại dùng đúng thông tin này.
	don := donQuay(t, h, a, ban.Data.OrderID)
	if don["buyer_tax_code"] != "0101234567" || don["buyer_address"] != "1 Tràng Tiền" || don["recipient_email"] != "ketoan@example.com" {
		t.Fatalf("thông tin người mua phải nằm trong đơn, đang là %v / %v / %v", don["buyer_tax_code"], don["buyer_address"], don["recipient_email"])
	}

	lai := h.goi(t, thuNgan, http.MethodPost, fmt.Sprintf("/api/v1/admin/orders/pos/%d/hoa-don-dien-tu", ban.Data.OrderID), nil)
	if lai.ma != http.StatusConflict || !strings.Contains(lai.than, "chưa kết nối") {
		t.Fatalf("bấm lại khi chưa nối cổng phải trả 409 kèm lý do, đang là %d\n%s", lai.ma, catBot(lai.than))
	}

	// Đơn giao hàng không đi đường của quầy.
	web := h.goi(t, thuNgan, http.MethodPost, fmt.Sprintf("/api/v1/admin/orders/pos/%d/hoa-don-dien-tu", a.donHang), nil)
	if web.ma != http.StatusNotFound {
		t.Fatalf("đơn kênh web phải trả 404 ở đường của quầy, đang là %d\n%s", web.ma, catBot(web.than))
	}
}
