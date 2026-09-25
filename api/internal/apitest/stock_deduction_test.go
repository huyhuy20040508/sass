package apitest

import (
	"context"
	"net/http"
	"testing"

	"sass-api/internal/domain"
	"sass-api/internal/tenant"
)

// Công tắc "Trừ kho" ở cột trái hộp thoại mặt hàng.
//
// Tắt = hàng dịch vụ, hàng đặt gia công: bán bao nhiêu cũng không đụng vào kho.
// Chạy qua API thật + MySQL thật vì thứ cần chứng minh là CÂU LỆNH GHI KHO có
// chạy hay không — kiểm ở tầng service thì kho là bản giả, mà kho giả thì luôn
// làm đúng kể cả khi câu lệnh thật sai.
func TestTruKho_TatThiBanKhongDungVaoKho(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	// Tắt "Trừ kho" cho mặt hàng của biến thể đang bán.
	if err := h.db.WithContext(tenant.WithID(context.Background(), a.id)).Model(&domain.Product{}).
		Where("id = ?", a.sanPham).
		UpdateColumn("is_stock_deducted", false).Error; err != nil {
		t.Fatalf("không tắt được trừ kho: %v", err)
	}

	truoc := tonCua(t, h, a, a.chiNhanh, a.bienThe)

	res := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/orders/pos", map[string]any{
		"items": []map[string]any{
			{"product_variant_id": a.bienThe, "quantity": 2},
		},
		"payment_method":  "cash",
		"amount_tendered": 500000,
	})
	if res.ma != http.StatusCreated && res.ma != http.StatusOK {
		t.Fatalf("bán tại quầy trả %d\n%s", res.ma, catBot(res.than))
	}

	// Đây là toàn bộ điểm của công tắc: bán xong mà kho ĐỨNG YÊN.
	if sau := tonCua(t, h, a, a.chiNhanh, a.bienThe); sau != truoc {
		t.Fatalf("tắt trừ kho mà kho vẫn đổi: trước %d, sau %d", truoc, sau)
	}
}
