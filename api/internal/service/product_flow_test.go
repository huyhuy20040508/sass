package service

import (
	"testing"

	"sass-api/internal/domain"
	"sass-api/internal/dto"
)

func boolPtr(b bool) *bool      { return &b }
func f64Ptr(v float64) *float64 { return &v }

// Trạng thái kinh doanh phải suy ra đúng một lần ở một chỗ.
//
// `status` là nguồn sự thật, `is_active` chỉ là bản rút gọn để truy vấn bán hàng
// lọc cho nhanh. Nhận cả hai từ request rồi ghi thẳng là có ngày chúng lệch nhau:
// sản phẩm ngừng kinh doanh mà vẫn bày ngoài cửa hàng.
func TestResolveProductStatus(t *testing.T) {
	cases := []struct {
		ten      string
		req      dto.ProductRequest
		hienTai  *domain.Product
		isCreate bool
		want     string
	}{
		{
			ten:      "tạo mới, không khai gì -> đang bán",
			req:      dto.ProductRequest{},
			hienTai:  &domain.Product{},
			isCreate: true,
			want:     domain.ProductStatusActive,
		},
		{
			ten:      "status khai rõ thì dùng luôn",
			req:      dto.ProductRequest{Status: domain.ProductStatusDiscontinued},
			hienTai:  &domain.Product{Status: domain.ProductStatusActive},
			isCreate: false,
			want:     domain.ProductStatusDiscontinued,
		},
		{
			// Bản quản trị cũ và các script gọi API sẵn có chỉ biết gửi cờ bật/tắt.
			ten:      "chỉ gửi cờ cũ is_active=false -> tạm ẩn",
			req:      dto.ProductRequest{IsActive: boolPtr(false)},
			hienTai:  &domain.Product{Status: domain.ProductStatusActive},
			isCreate: false,
			want:     domain.ProductStatusHidden,
		},
		{
			ten:      "chỉ gửi cờ cũ is_active=true -> đang bán",
			req:      dto.ProductRequest{IsActive: boolPtr(true)},
			hienTai:  &domain.Product{Status: domain.ProductStatusHidden},
			isCreate: false,
			want:     domain.ProductStatusActive,
		},
		{
			// Người gửi chỉ có cờ bật/tắt, không có ý hạ "ngừng kinh doanh" xuống
			// thành "tạm ẩn" — giữ nguyên thay vì tự ý đổi nghĩa.
			ten:      "cờ cũ tắt, đang ngừng kinh doanh -> giữ nguyên",
			req:      dto.ProductRequest{IsActive: boolPtr(false)},
			hienTai:  &domain.Product{Status: domain.ProductStatusDiscontinued},
			isCreate: false,
			want:     domain.ProductStatusDiscontinued,
		},
		{
			ten:      "sửa mà không gửi gì -> giữ nguyên trạng thái đang có",
			req:      dto.ProductRequest{},
			hienTai:  &domain.Product{Status: domain.ProductStatusHidden},
			isCreate: false,
			want:     domain.ProductStatusHidden,
		},
		{
			ten:      "status rác thì bỏ qua, rơi về cờ cũ",
			req:      dto.ProductRequest{Status: "linh tinh", IsActive: boolPtr(true)},
			hienTai:  &domain.Product{Status: domain.ProductStatusHidden},
			isCreate: false,
			want:     domain.ProductStatusActive,
		},
	}

	for _, c := range cases {
		t.Run(c.ten, func(t *testing.T) {
			if got := resolveProductStatus(c.req, c.hienTai, c.isCreate); got != c.want {
				t.Fatalf("được %q, mong đợi %q", got, c.want)
			}
		})
	}
}

// applyProductRequest phải luôn suy is_active ra từ status, không nhận riêng.
func TestApplyProductRequestSuyRaCoHienThi(t *testing.T) {
	for _, c := range []struct {
		status string
		want   bool
	}{
		{domain.ProductStatusActive, true},
		{domain.ProductStatusHidden, false},
		{domain.ProductStatusDiscontinued, false},
	} {
		p := &domain.Product{}
		// Cố tình gửi kèm is_active=true ngược với status: status phải thắng.
		applyProductRequest(p, dto.ProductRequest{Status: c.status, IsActive: boolPtr(true)}, true)
		if p.Status != c.status {
			t.Fatalf("status = %q, mong đợi %q", p.Status, c.status)
		}
		if p.IsActive != c.want {
			t.Fatalf("status %q -> is_active = %v, mong đợi %v", c.status, p.IsActive, c.want)
		}
	}
}

// Giá sau khuyến mãi phải tính RIÊNG cho từng biến thể.
//
// Đây là lỗi khách nhìn một giá, trả một giá khác: biến thể khai giá riêng thì
// tầng thanh toán thu tiền theo giá của nó (loadCheckoutVariants), nhưng trang
// chi tiết trước đây chỉ có một con số ở cấp sản phẩm. Test giữ cho hai bên tính
// bằng đúng một công thức.
func TestDecorateTinhGiaRiengChoTungBienThe(t *testing.T) {
	m := &promoMatcher{
		promos: []domain.Promotion{{
			Name:          "Giảm 10% toàn bộ",
			DiscountType:  domain.DiscountPercentage,
			DiscountValue: 10,
		}},
		byProduct:  map[uint][]int{7: {0}},
		byCategory: map[uint][]int{},
		parentOf:   map[uint]uint{},
	}

	p := &domain.Product{
		ID:         7,
		CategoryID: 1,
		BasePrice:  1000000,
		SalePrice:  f64Ptr(800000),
		Variants: []domain.ProductVariant{
			{Name: "M"},                          // theo giá sản phẩm
			{Name: "XXL", Price: f64Ptr(900000)}, // khai giá riêng, đắt hơn
		},
	}
	m.decorate(p)

	// Giá sản phẩm: nền là 800.000 (giá giảm gõ tay) chứ không phải giá niêm yết.
	if p.FinalPrice == nil || *p.FinalPrice != 720000 {
		t.Fatalf("final_price của sản phẩm = %v, mong đợi 720000", p.FinalPrice)
	}
	if p.PromotionName != "Giảm 10% toàn bộ" {
		t.Fatalf("promotion_name = %q", p.PromotionName)
	}

	// Biến thể không khai giá riêng thì đi theo sản phẩm.
	if p.Variants[0].FinalPrice == nil || *p.Variants[0].FinalPrice != 720000 {
		t.Fatalf("final_price size M = %v, mong đợi 720000", p.Variants[0].FinalPrice)
	}

	// Biến thể khai giá riêng: 900.000 ĐÈ giá giảm của sản phẩm, rồi mới trừ 10%
	// — đúng thứ tự mà loadCheckoutVariants + ApplyToCheckout đang làm.
	if p.Variants[1].FinalPrice == nil || *p.Variants[1].FinalPrice != 810000 {
		t.Fatalf("final_price size XXL = %v, mong đợi 810000", p.Variants[1].FinalPrice)
	}
}

// Không có chương trình nào áp thì giá biến thể vẫn phải là giá của chính nó.
func TestDecorateKhongCoKhuyenMaiVanGiuGiaRieng(t *testing.T) {
	m := &promoMatcher{
		promos:     []domain.Promotion{},
		byProduct:  map[uint][]int{},
		byCategory: map[uint][]int{},
		parentOf:   map[uint]uint{},
	}

	p := &domain.Product{
		ID:        9,
		BasePrice: 500000,
		Variants: []domain.ProductVariant{
			{Name: "M"},
			{Name: "XXL", Price: f64Ptr(550000)},
		},
	}
	m.decorate(p)

	if p.FinalPrice != nil {
		t.Fatalf("không có chương trình nào mà sản phẩm vẫn có final_price = %v", *p.FinalPrice)
	}
	if p.Variants[0].FinalPrice == nil || *p.Variants[0].FinalPrice != 500000 {
		t.Fatalf("size M = %v, mong đợi 500000", p.Variants[0].FinalPrice)
	}
	if p.Variants[1].FinalPrice == nil || *p.Variants[1].FinalPrice != 550000 {
		t.Fatalf("size XXL = %v, mong đợi giữ giá riêng 550000", p.Variants[1].FinalPrice)
	}
}
