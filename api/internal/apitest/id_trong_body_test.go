package apitest

import (
	"context"
	"net/http"
	"testing"

	"gorm.io/gorm"

	"sass-api/internal/domain"
	"sass-api/internal/tenant"
)

// TestCoLapTenant_IDTrongBody kiểm nhóm route KHÔNG nhận id trên URL mà nhận
// trong thân yêu cầu.
//
// Sửa id trên thanh địa chỉ chỉ là cách dễ nhất. Mấy đường dưới đây nhận cả một
// DANH SÁCH id (xoá hàng loạt, sắp xếp, kiểm kho) hoặc nhận id của bản ghi liên
// quan (đặt đơn cho khách nào, nhập hàng của nhà cung cấp nào) — cũng là con số
// người gọi tự khai, và ở đây 404 không phải câu trả lời bắt buộc: điều bắt buộc
// là DỮ LIỆU CỦA CỬA HÀNG KIA KHÔNG ĐỔI.
func TestCoLapTenant_IDTrongBody(t *testing.T) {
	h := dungHeThong(t)
	a, b := haiCuaHang(t, h)
	ctxB := tenant.WithID(context.Background(), b.id)

	t.Run("POST /admin/products/bulk-delete", func(t *testing.T) {
		h.goi(t, a.token, http.MethodPost, "/api/v1/admin/products/bulk-delete",
			map[string]any{"ids": []uint{b.sanPham}})

		var con int64
		if err := h.db.WithContext(ctxB).Model(&domain.Product{}).
			Where("id = ?", b.sanPham).Count(&con).Error; err != nil {
			t.Fatalf("không đếm lại được sản phẩm: %v", err)
		}
		if con != 1 {
			t.Fatalf("RÒ RỈ: cửa hàng %s xoá được sản phẩm của %s", a.ma, b.ma)
		}
	})

	t.Run("PUT /admin/banners/sort", func(t *testing.T) {
		truoc := soThuTuBanner(t, h.db, ctxB, b.banner)

		h.goi(t, a.token, http.MethodPut, "/api/v1/admin/banners/sort",
			map[string]any{"ids": []uint{b.banner}})

		if sau := soThuTuBanner(t, h.db, ctxB, b.banner); sau != truoc {
			t.Fatalf("RÒ RỈ: cửa hàng %s sắp xếp lại được banner của %s (%d -> %d)",
				a.ma, b.ma, truoc, sau)
		}
	})

	t.Run("POST /admin/inventory/adjust", func(t *testing.T) {
		truoc := tonKho(t, h.db, ctxB, b.bienThe)

		h.goi(t, a.token, http.MethodPost, "/api/v1/admin/inventory/adjust", map[string]any{
			"items": []map[string]any{{"variant_id": b.bienThe, "mode": "set", "quantity": 999}},
		})

		if sau := tonKho(t, h.db, ctxB, b.bienThe); sau != truoc {
			t.Fatalf("RÒ RỈ: cửa hàng %s chỉnh được tồn kho của %s (%d -> %d)", a.ma, b.ma, truoc, sau)
		}
	})

	t.Run("PUT /admin/inventory/cost", func(t *testing.T) {
		res := h.goi(t, a.token, http.MethodPut, "/api/v1/admin/inventory/cost", map[string]any{
			"items": []map[string]any{{"variant_id": b.bienThe, "cost_price": 999999}},
		})

		var v domain.ProductVariant
		if err := h.db.WithContext(ctxB).First(&v, b.bienThe).Error; err != nil {
			t.Fatalf("không đọc lại được biến thể: %v", err)
		}
		if v.CostPrice != nil {
			t.Fatalf("RÒ RỈ: cửa hàng %s khai được giá vốn cho biến thể của %s (%v, phản hồi %d)",
				a.ma, b.ma, *v.CostPrice, res.ma)
		}
	})

	// Bốn đường TẠO MỚI dưới đây tham chiếu tới bản ghi của cửa hàng kia. Ở đây
	// yêu cầu chặt hơn: không được thành công. Tạo được nghĩa là cửa hàng A vừa
	// dựng một chứng từ móc vào dữ liệu của B — đơn hàng gắn khách của B, phiếu
	// nhập gắn nhà cung cấp của B.
	taoHong := []struct {
		ten   string
		duong string
		than  map[string]any
	}{
		{"POST /admin/orders (khách của cửa hàng khác)", "/api/v1/admin/orders", map[string]any{
			"user_id": b.khach, "recipient_name": "x", "recipient_phone": "0900000009",
			"shipping_address": "x", "payment_method": "cod",
			"items": []map[string]any{{
				"product_variant_id": b.bienThe, "product_name": "x", "unit_price": 1000, "quantity": 1,
			}},
		}},
		{"POST /admin/purchases (nhà cung cấp của cửa hàng khác)", "/api/v1/admin/purchases", map[string]any{
			"supplier_id": b.nhaCungCap,
			"items":       []map[string]any{{"variant_id": b.bienThe, "quantity": 1, "unit_cost": 1000}},
		}},
		{"POST /admin/purchase-returns (phiếu đặt của cửa hàng khác)", "/api/v1/admin/purchase-returns", map[string]any{
			"purchase_order_id": b.phieuNhan,
			"items":             []map[string]any{{"purchase_order_item_id": b.dongNhan, "quantity": 1}},
		}},
		{"POST /admin/returns (đơn của cửa hàng khác)", "/api/v1/admin/returns", map[string]any{
			"order_id": b.donGiao, "reason": "defective",
			"items": []map[string]any{{"order_item_id": dongDauCuaDon(t, h.db, ctxB, b.donGiao), "quantity": 1}},
		}},
	}

	for _, x := range taoHong {
		t.Run(x.ten, func(t *testing.T) {
			res := h.goi(t, a.token, http.MethodPost, x.duong, x.than)
			if res.ma >= 200 && res.ma < 300 {
				t.Fatalf("RÒ RỈ: cửa hàng %s tạo được chứng từ trỏ vào dữ liệu của %s — trả %d\n%s",
					a.ma, b.ma, res.ma, catBot(res.than))
			}
		})
	}
}

func soThuTuBanner(t *testing.T, db *gorm.DB, ctx context.Context, id uint) int {
	t.Helper()

	var b domain.Banner
	if err := db.WithContext(ctx).First(&b, id).Error; err != nil {
		t.Fatalf("không đọc được banner %d: %v", id, err)
	}
	return b.SortOrder
}

func tonKho(t *testing.T, db *gorm.DB, ctx context.Context, id uint) int {
	t.Helper()

	var v domain.ProductVariant
	if err := db.WithContext(ctx).First(&v, id).Error; err != nil {
		t.Fatalf("không đọc được biến thể %d: %v", id, err)
	}
	return v.StockQuantity
}

func dongDauCuaDon(t *testing.T, db *gorm.DB, ctx context.Context, donID uint) uint {
	t.Helper()

	var d domain.OrderItem
	if err := db.WithContext(ctx).Where("order_id = ?", donID).First(&d).Error; err != nil {
		t.Fatalf("không đọc được dòng hàng của đơn %d: %v", donID, err)
	}
	return d.ID
}
