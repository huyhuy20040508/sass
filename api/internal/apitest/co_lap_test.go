package apitest

import (
	"fmt"
	"net/http"
	"sort"
	"testing"
)

// Hai cửa hàng giả. Mã ngắn, không dấu — đúng khuôn tenants.code thật.
const (
	maA = "isoa"
	maB = "isob"
)

// buoc là MỘT đường có nhận id trên URL.
//
// duong dựng đường dẫn từ dữ liệu của một cửa hàng, nên cùng một dòng bảng chạy
// được cả hai chiều: lắp id của cửa hàng KIA (phải 404) và lắp id của CHÍNH MÌNH
// (không được 404).
type buoc struct {
	nhom   string
	mau    string // để đọc trong báo lỗi
	method string
	duong  func(c *cuaHang) string
	body   func(c *cuaHang) any
	// pha quyết định thứ tự chạy ở lượt đối chứng: đọc trước, sửa sau, xoá cuối.
	// Không có nó thì DELETE chạy trước GET và bài kiểm tự tay tạo ra 404 rồi báo
	// lỗi cho chính mình.
	pha int
}

const (
	phaDoc = iota
	phaSua
	phaXoa
)

// bangTuyen là toàn bộ đường nhận id trên URL của khu quản trị.
//
// Danh sách này phải bám sát router.go. Thêm route mới có `:id` mà không thêm
// vào đây thì nhóm đó không được kiểm — và cách duy nhất nhìn ra là đọc chéo hai
// tệp, nên hãy thêm cùng lúc.
var bangTuyen = []buoc{
	// --- Catalog công khai (đọc token nếu có, nên vẫn phải lọc theo cửa hàng) ---
	{"catalog", "GET /categories/{id}", http.MethodGet,
		func(c *cuaHang) string { return fmt.Sprintf("/api/v1/categories/%d", c.danhMuc) }, nil, phaDoc},
	{"catalog", "GET /brands/{id}", http.MethodGet,
		func(c *cuaHang) string { return fmt.Sprintf("/api/v1/brands/%d", c.thuongHieu) }, nil, phaDoc},
	{"catalog", "GET /products/{slug}", http.MethodGet,
		func(c *cuaHang) string { return "/api/v1/products/" + c.slug }, nil, phaDoc},

	// --- Thông báo ---
	{"thong-bao", "PUT /notifications/{id}/read", http.MethodPut,
		func(c *cuaHang) string { return fmt.Sprintf("/api/v1/notifications/%d/read", c.thongBao) }, nil, phaSua},

	// --- Danh mục ---
	{"danh-muc", "PUT /admin/categories/{id}", http.MethodPut,
		func(c *cuaHang) string { return fmt.Sprintf("/api/v1/admin/categories/%d", c.danhMuc) },
		func(c *cuaHang) any {
			return map[string]any{"name": "Danh mục " + c.vet, "slug": "dm-" + c.vet}
		}, phaSua},
	{"danh-muc", "DELETE /admin/categories/{id}", http.MethodDelete,
		func(c *cuaHang) string { return fmt.Sprintf("/api/v1/admin/categories/%d", c.danhMuc) }, nil, phaXoa},

	// --- Thương hiệu ---
	{"thuong-hieu", "PUT /admin/brands/{id}", http.MethodPut,
		func(c *cuaHang) string { return fmt.Sprintf("/api/v1/admin/brands/%d", c.thuongHieu) },
		func(c *cuaHang) any {
			return map[string]any{"name": "Thương hiệu " + c.vet, "slug": "th-" + c.vet}
		}, phaSua},
	{"thuong-hieu", "DELETE /admin/brands/{id}", http.MethodDelete,
		func(c *cuaHang) string { return fmt.Sprintf("/api/v1/admin/brands/%d", c.thuongHieu) }, nil, phaXoa},

	// --- Banner ---
	{"banner", "GET /admin/banners/{id}", http.MethodGet,
		func(c *cuaHang) string { return fmt.Sprintf("/api/v1/admin/banners/%d", c.banner) }, nil, phaDoc},
	{"banner", "PUT /admin/banners/{id}", http.MethodPut,
		func(c *cuaHang) string { return fmt.Sprintf("/api/v1/admin/banners/%d", c.banner) },
		func(c *cuaHang) any {
			return map[string]any{"image": "/anh/" + c.vet + ".jpg", "position": "home_slider"}
		}, phaSua},
	{"banner", "PUT /admin/banners/{id}/status", http.MethodPut,
		func(c *cuaHang) string { return fmt.Sprintf("/api/v1/admin/banners/%d/status", c.banner) },
		func(*cuaHang) any { return map[string]any{"is_active": true} }, phaSua},
	{"banner", "DELETE /admin/banners/{id}", http.MethodDelete,
		func(c *cuaHang) string { return fmt.Sprintf("/api/v1/admin/banners/%d", c.banner) }, nil, phaXoa},

	// --- Khuyến mãi ---
	{"khuyen-mai", "GET /admin/promotions/{id}", http.MethodGet,
		func(c *cuaHang) string { return fmt.Sprintf("/api/v1/admin/promotions/%d", c.khuyenMai) }, nil, phaDoc},
	{"khuyen-mai", "PUT /admin/promotions/{id}", http.MethodPut,
		func(c *cuaHang) string { return fmt.Sprintf("/api/v1/admin/promotions/%d", c.khuyenMai) },
		func(c *cuaHang) any {
			return map[string]any{
				"name": "Khuyến mãi " + c.vet, "discount_type": "percentage", "discount_value": 10,
				"start_at": "2026-01-01T00:00", "end_at": "2030-01-01T00:00",
				"product_ids": []uint{c.sanPham},
			}
		}, phaSua},
	{"khuyen-mai", "PUT /admin/promotions/{id}/status", http.MethodPut,
		func(c *cuaHang) string { return fmt.Sprintf("/api/v1/admin/promotions/%d/status", c.khuyenMai) },
		func(*cuaHang) any { return map[string]any{"is_active": true} }, phaSua},
	{"khuyen-mai", "DELETE /admin/promotions/{id}", http.MethodDelete,
		func(c *cuaHang) string { return fmt.Sprintf("/api/v1/admin/promotions/%d", c.khuyenMai) }, nil, phaXoa},

	// --- Voucher ---
	{"voucher", "GET /admin/vouchers/{id}", http.MethodGet,
		func(c *cuaHang) string { return fmt.Sprintf("/api/v1/admin/vouchers/%d", c.voucher) }, nil, phaDoc},
	{"voucher", "PUT /admin/vouchers/{id}", http.MethodPut,
		func(c *cuaHang) string { return fmt.Sprintf("/api/v1/admin/vouchers/%d", c.voucher) },
		func(c *cuaHang) any {
			return map[string]any{"code": "voucher-" + c.vet, "discount_type": "fixed", "discount_value": 10000}
		}, phaSua},
	{"voucher", "PUT /admin/vouchers/{id}/status", http.MethodPut,
		func(c *cuaHang) string { return fmt.Sprintf("/api/v1/admin/vouchers/%d/status", c.voucher) },
		func(*cuaHang) any { return map[string]any{"is_active": true} }, phaSua},
	{"voucher", "DELETE /admin/vouchers/{id}", http.MethodDelete,
		func(c *cuaHang) string { return fmt.Sprintf("/api/v1/admin/vouchers/%d", c.voucher) }, nil, phaXoa},

	// --- Sản phẩm ---
	{"san-pham", "GET /admin/products/{id}", http.MethodGet,
		func(c *cuaHang) string { return fmt.Sprintf("/api/v1/admin/products/%d", c.sanPham) }, nil, phaDoc},
	{"san-pham", "PUT /admin/products/{id}", http.MethodPut,
		func(c *cuaHang) string { return fmt.Sprintf("/api/v1/admin/products/%d", c.sanPham) },
		func(c *cuaHang) any {
			return map[string]any{
				"category_id": c.danhMuc, "name": "Sản phẩm " + c.vet,
				"slug": c.slug, "sku": "sku-" + c.vet, "base_price": 100000,
			}
		}, phaSua},
	// "active" — đúng trạng thái sản phẩm đang có. Đặt lại giá trị đang có phải
	// thành công; xem conDong trong internal/repository/cap_nhat.go và bài kiểm
	// riêng ở dat_lai_trang_thai_test.go.
	{"san-pham", "PUT /admin/products/{id}/status", http.MethodPut,
		func(c *cuaHang) string { return fmt.Sprintf("/api/v1/admin/products/%d/status", c.sanPham) },
		func(*cuaHang) any { return map[string]any{"status": "active"} }, phaSua},
	{"san-pham", "POST /admin/products/{id}/duplicate", http.MethodPost,
		func(c *cuaHang) string { return fmt.Sprintf("/api/v1/admin/products/%d/duplicate", c.sanPham) }, nil, phaSua},
	{"san-pham", "DELETE /admin/products/{id}", http.MethodDelete,
		func(c *cuaHang) string { return fmt.Sprintf("/api/v1/admin/products/%d", c.sanPham) }, nil, phaXoa},

	// --- Khách hàng ---
	{"khach-hang", "GET /admin/customers/{id}", http.MethodGet,
		func(c *cuaHang) string { return fmt.Sprintf("/api/v1/admin/customers/%d", c.khach) }, nil, phaDoc},
	{"khach-hang", "PUT /admin/customers/{id}", http.MethodPut,
		func(c *cuaHang) string { return fmt.Sprintf("/api/v1/admin/customers/%d", c.khach) },
		func(c *cuaHang) any {
			return map[string]any{
				"full_name": "Khách " + c.vet, "email": "khach@" + c.vet + ".test", "status": "active",
			}
		}, phaSua},
	{"khach-hang", "PUT /admin/customers/{id}/status", http.MethodPut,
		func(c *cuaHang) string { return fmt.Sprintf("/api/v1/admin/customers/%d/status", c.khach) },
		func(*cuaHang) any { return map[string]any{"status": "active"} }, phaSua},
	{"khach-hang", "PUT /admin/customers/{id}/password", http.MethodPut,
		func(c *cuaHang) string { return fmt.Sprintf("/api/v1/admin/customers/%d/password", c.khach) },
		func(*cuaHang) any { return map[string]any{"password": matKhauTest} }, phaSua},
	{"khach-hang", "DELETE /admin/customers/{id}", http.MethodDelete,
		func(c *cuaHang) string { return fmt.Sprintf("/api/v1/admin/customers/%d", c.khach) }, nil, phaXoa},

	// --- Đơn hàng ---
	{"don-hang", "GET /admin/orders/{id}", http.MethodGet,
		func(c *cuaHang) string { return fmt.Sprintf("/api/v1/admin/orders/%d", c.donHang) }, nil, phaDoc},
	{"don-hang", "GET /admin/orders/{id}/returnable", http.MethodGet,
		func(c *cuaHang) string { return fmt.Sprintf("/api/v1/admin/orders/%d/returnable", c.donGiao) }, nil, phaDoc},
	{"don-hang", "PUT /admin/orders/{id}/note", http.MethodPut,
		func(c *cuaHang) string { return fmt.Sprintf("/api/v1/admin/orders/%d/note", c.donHang) },
		func(c *cuaHang) any { return map[string]any{"admin_note": "ghi chú " + c.vet} }, phaSua},
	{"don-hang", "PUT /admin/orders/{id}/shipping", http.MethodPut,
		func(c *cuaHang) string { return fmt.Sprintf("/api/v1/admin/orders/%d/shipping", c.donHang) },
		func(*cuaHang) any { return map[string]any{"shipping_method": "ghn", "tracking_number": "x1"} }, phaSua},
	{"don-hang", "PUT /admin/orders/{id}/payment", http.MethodPut,
		func(c *cuaHang) string { return fmt.Sprintf("/api/v1/admin/orders/%d/payment", c.donHang) },
		func(*cuaHang) any { return map[string]any{"payment_status": "pending"} }, phaSua},
	{"don-hang", "PUT /admin/orders/{id}", http.MethodPut,
		func(c *cuaHang) string { return fmt.Sprintf("/api/v1/admin/orders/%d", c.donHang) },
		func(c *cuaHang) any {
			return map[string]any{
				"recipient_name": "Người nhận " + c.vet, "recipient_phone": "0900000004",
				"shipping_address": "Địa chỉ của " + c.vet, "payment_method": "cod",
				"items": []map[string]any{{
					"product_variant_id": c.bienThe, "product_name": "Sản phẩm " + c.vet,
					"unit_price": 100000, "quantity": 1,
				}},
			}
		}, phaSua},
	{"don-hang", "PUT /admin/orders/{id}/status", http.MethodPut,
		func(c *cuaHang) string { return fmt.Sprintf("/api/v1/admin/orders/%d/status", c.donHang) },
		func(*cuaHang) any { return map[string]any{"status": "processing"} }, phaSua},

	// --- Trả hàng ---
	{"tra-hang", "GET /admin/returns/{id}", http.MethodGet,
		func(c *cuaHang) string { return fmt.Sprintf("/api/v1/admin/returns/%d", c.traHang) }, nil, phaDoc},
	{"tra-hang", "PUT /admin/returns/{id}/note", http.MethodPut,
		func(c *cuaHang) string { return fmt.Sprintf("/api/v1/admin/returns/%d/note", c.traHang) },
		func(c *cuaHang) any { return map[string]any{"admin_note": "ghi chú " + c.vet} }, phaSua},
	{"tra-hang", "PUT /admin/returns/{id}/settle", http.MethodPut,
		func(c *cuaHang) string { return fmt.Sprintf("/api/v1/admin/returns/%d/settle", c.traHang) },
		func(*cuaHang) any { return map[string]any{"shipping_fee": 0, "deduction": 0} }, phaSua},
	{"tra-hang", "PUT /admin/returns/{id}/status", http.MethodPut,
		func(c *cuaHang) string { return fmt.Sprintf("/api/v1/admin/returns/%d/status", c.traHang) },
		func(*cuaHang) any { return map[string]any{"status": "approved"} }, phaSua},

	// --- Tồn kho (đơn vị là BIẾN THỂ, không phải sản phẩm) ---
	{"ton-kho", "GET /admin/inventory/{id}", http.MethodGet,
		func(c *cuaHang) string { return fmt.Sprintf("/api/v1/admin/inventory/%d", c.bienThe) }, nil, phaDoc},
	{"ton-kho", "GET /admin/inventory/{id}/history", http.MethodGet,
		func(c *cuaHang) string { return fmt.Sprintf("/api/v1/admin/inventory/%d/history", c.bienThe) }, nil, phaDoc},
	{"ton-kho", "PUT /admin/inventory/{id}", http.MethodPut,
		func(c *cuaHang) string { return fmt.Sprintf("/api/v1/admin/inventory/%d", c.bienThe) },
		func(*cuaHang) any { return map[string]any{"mode": "delta", "quantity": 1} }, phaSua},

	// --- Nhà cung cấp ---
	{"nha-cung-cap", "GET /admin/suppliers/{id}", http.MethodGet,
		func(c *cuaHang) string { return fmt.Sprintf("/api/v1/admin/suppliers/%d", c.nhaCungCap) }, nil, phaDoc},
	{"nha-cung-cap", "PUT /admin/suppliers/{id}", http.MethodPut,
		func(c *cuaHang) string { return fmt.Sprintf("/api/v1/admin/suppliers/%d", c.nhaCungCap) },
		func(c *cuaHang) any { return map[string]any{"name": "Nhà cung cấp " + c.vet} }, phaSua},
	{"nha-cung-cap", "DELETE /admin/suppliers/{id}", http.MethodDelete,
		func(c *cuaHang) string { return fmt.Sprintf("/api/v1/admin/suppliers/%d", c.nhaCungCap) }, nil, phaXoa},

	// --- Đặt hàng nhập ---
	{"dat-hang-nhap", "GET /admin/purchases/{id}", http.MethodGet,
		func(c *cuaHang) string { return fmt.Sprintf("/api/v1/admin/purchases/%d", c.phieuDat) }, nil, phaDoc},
	// Số lượng phải > 0: gửi 0 thì service chặn ngay ở bước kiểm dữ liệu ("chưa
	// chọn sản phẩm nào để nhận") và không bao giờ tra tới phiếu — lượt kiểm chèn
	// id sẽ nhận 422 và không chứng minh được điều gì.
	//
	// Đứng TRƯỚC lệnh sửa phiếu: sửa phiếu thay mới toàn bộ dòng hàng, nên chạy
	// sau thì dongDat trỏ vào một dòng đã bị xoá và lượt đối chứng nhận 404 vì lý
	// do chẳng liên quan gì tới cô lập tenant.
	{"dat-hang-nhap", "POST /admin/purchases/{id}/receive", http.MethodPost,
		func(c *cuaHang) string { return fmt.Sprintf("/api/v1/admin/purchases/%d/receive", c.phieuDat) },
		func(c *cuaHang) any {
			return map[string]any{"items": []map[string]any{{"item_id": c.dongDat, "quantity": 1}}}
		}, phaSua},
	{"dat-hang-nhap", "PUT /admin/purchases/{id}", http.MethodPut,
		func(c *cuaHang) string { return fmt.Sprintf("/api/v1/admin/purchases/%d", c.phieuDat) },
		func(c *cuaHang) any {
			return map[string]any{
				"supplier_id": c.nhaCungCap,
				"items":       []map[string]any{{"variant_id": c.bienThe, "quantity": 5, "unit_cost": 60000}},
			}
		}, phaSua},
	{"dat-hang-nhap", "PUT /admin/purchases/{id}/payment", http.MethodPut,
		func(c *cuaHang) string { return fmt.Sprintf("/api/v1/admin/purchases/%d/payment", c.phieuDat) },
		func(*cuaHang) any { return map[string]any{"paid_amount": 0} }, phaSua},
	{"dat-hang-nhap", "PUT /admin/purchases/{id}/status", http.MethodPut,
		func(c *cuaHang) string { return fmt.Sprintf("/api/v1/admin/purchases/%d/status", c.phieuDat) },
		func(c *cuaHang) any { return map[string]any{"status": "cancelled", "note": "huỷ " + c.vet} }, phaSua},
	{"dat-hang-nhap", "DELETE /admin/purchases/{id}", http.MethodDelete,
		func(c *cuaHang) string { return fmt.Sprintf("/api/v1/admin/purchases/%d", c.phieuDat) }, nil, phaXoa},

	// --- Nhập hàng (đợt hàng đã về, tra theo MÃ chứ không phải id) ---
	{"nhap-hang", "GET /admin/receipts/{code}", http.MethodGet,
		func(c *cuaHang) string { return "/api/v1/admin/receipts/" + c.maDotNhap }, nil, phaDoc},

	// --- Trả hàng nhập ---
	{"tra-hang-nhap", "GET /admin/purchase-returns/returnable/{id}", http.MethodGet,
		func(c *cuaHang) string {
			return fmt.Sprintf("/api/v1/admin/purchase-returns/returnable/%d", c.phieuNhan)
		}, nil, phaDoc},
	{"tra-hang-nhap", "GET /admin/purchase-returns/{id}", http.MethodGet,
		func(c *cuaHang) string { return fmt.Sprintf("/api/v1/admin/purchase-returns/%d", c.traNhap) }, nil, phaDoc},
	{"tra-hang-nhap", "PUT /admin/purchase-returns/{id}", http.MethodPut,
		func(c *cuaHang) string { return fmt.Sprintf("/api/v1/admin/purchase-returns/%d", c.traNhap) },
		func(c *cuaHang) any {
			return map[string]any{
				"purchase_order_id": c.phieuNhan,
				"items":             []map[string]any{{"purchase_order_item_id": c.dongNhan, "quantity": 1}},
			}
		}, phaSua},
	{"tra-hang-nhap", "PUT /admin/purchase-returns/{id}/refund", http.MethodPut,
		func(c *cuaHang) string { return fmt.Sprintf("/api/v1/admin/purchase-returns/%d/refund", c.traNhap) },
		func(*cuaHang) any { return map[string]any{"refund_amount": 0} }, phaSua},
	{"tra-hang-nhap", "PUT /admin/purchase-returns/{id}/status", http.MethodPut,
		func(c *cuaHang) string { return fmt.Sprintf("/api/v1/admin/purchase-returns/%d/status", c.traNhap) },
		func(c *cuaHang) any { return map[string]any{"status": "cancelled", "note": "huỷ " + c.vet} }, phaSua},
	{"tra-hang-nhap", "DELETE /admin/purchase-returns/{id}", http.MethodDelete,
		func(c *cuaHang) string { return fmt.Sprintf("/api/v1/admin/purchase-returns/%d", c.traNhap) }, nil, phaXoa},

	// --- Yêu cầu của khách ---
	{"lien-he", "GET /admin/contact-requests/{id}", http.MethodGet,
		func(c *cuaHang) string { return fmt.Sprintf("/api/v1/admin/contact-requests/%d", c.yeuCau) }, nil, phaDoc},
	{"lien-he", "PUT /admin/contact-requests/{id}/status", http.MethodPut,
		func(c *cuaHang) string { return fmt.Sprintf("/api/v1/admin/contact-requests/%d/status", c.yeuCau) },
		func(*cuaHang) any { return map[string]any{"status": "dang-xu-ly"} }, phaSua},
	{"lien-he", "DELETE /admin/contact-requests/{id}", http.MethodDelete,
		func(c *cuaHang) string { return fmt.Sprintf("/api/v1/admin/contact-requests/%d", c.yeuCau) }, nil, phaXoa},

	// --- Nhận tin ---
	{"nhan-tin", "PUT /admin/newsletter/{id}/unsubscribe", http.MethodPut,
		func(c *cuaHang) string { return fmt.Sprintf("/api/v1/admin/newsletter/%d/unsubscribe", c.nhanTin) }, nil, phaSua},

	// --- Chi nhánh (điểm bán trong một cửa hàng) ---
	//
	// Nhóm này nguy hiểm theo kiểu riêng: hai cửa hàng khác nhau được phép có chi
	// nhánh TRÙNG MÃ ('mac-dinh'), nên một câu truy vấn quên lọc tenant vẫn tra ra
	// đúng một dòng và trả 200 như thường.
	//
	// Lượt xoá của CHÍNH CHỦ trả 409 (chi nhánh hoạt động cuối cùng) chứ không
	// phải 2xx — lượt đối chứng chỉ đòi "không 404", nên đó là câu trả lời hợp lệ,
	// và tiện thể nó kiểm luôn cái chốt chặn ấy.
	{"chi-nhanh", "GET /admin/chi-nhanh/{id}", http.MethodGet,
		func(c *cuaHang) string { return fmt.Sprintf("/api/v1/admin/chi-nhanh/%d", c.chiNhanh) }, nil, phaDoc},
	{"chi-nhanh", "PUT /admin/chi-nhanh/{id}", http.MethodPut,
		func(c *cuaHang) string { return fmt.Sprintf("/api/v1/admin/chi-nhanh/%d", c.chiNhanh) },
		func(c *cuaHang) any { return map[string]any{"name": "Chi nhánh " + c.vet} }, phaSua},
	{"chi-nhanh", "DELETE /admin/chi-nhanh/{id}", http.MethodDelete,
		func(c *cuaHang) string { return fmt.Sprintf("/api/v1/admin/chi-nhanh/%d", c.chiNhanh) }, nil, phaXoa},

	// --- Tài khoản nội bộ ---
	{"tai-khoan", "GET /admin/users/{id}", http.MethodGet,
		func(c *cuaHang) string { return fmt.Sprintf("/api/v1/admin/users/%d", c.nhanVien) }, nil, phaDoc},
	{"tai-khoan", "PUT /admin/users/{id}", http.MethodPut,
		func(c *cuaHang) string { return fmt.Sprintf("/api/v1/admin/users/%d", c.nhanVien) },
		func(c *cuaHang) any {
			return map[string]any{
				"full_name": "Nhân viên " + c.vet, "username": "nhanvien",
				"email": "nhanvien@" + c.vet + ".test", "role_id": 3, "status": "active",
			}
		}, phaSua},
	{"tai-khoan", "PUT /admin/users/{id}/status", http.MethodPut,
		func(c *cuaHang) string { return fmt.Sprintf("/api/v1/admin/users/%d/status", c.nhanVien) },
		func(*cuaHang) any { return map[string]any{"status": "active"} }, phaSua},
	{"tai-khoan", "PUT /admin/users/{id}/password", http.MethodPut,
		func(c *cuaHang) string { return fmt.Sprintf("/api/v1/admin/users/%d/password", c.nhanVien) },
		func(*cuaHang) any { return map[string]any{"password": matKhauTest} }, phaSua},
	{"tai-khoan", "DELETE /admin/users/{id}", http.MethodDelete,
		func(c *cuaHang) string { return fmt.Sprintf("/api/v1/admin/users/%d", c.nhanVien) }, nil, phaXoa},
}

// TestCoLapTenant_SuaIDTrenURL là bài kiểm chính: đăng nhập bằng cửa hàng A, gọi
// từng đường bằng id của cửa hàng B.
//
// Đúng bằng thao tác mà một người dùng thật làm được: mở trang quản trị của mình
// rồi sửa con số trên thanh địa chỉ. Không cần công cụ gì.
//
// Câu trả lời phải là 404 chứ không phải 403: nói "không có quyền" là đã xác
// nhận id đó có thật, tức là vẫn đếm được cửa hàng bên kia có bao nhiêu đơn.
func TestCoLapTenant_SuaIDTrenURL(t *testing.T) {
	h := dungHeThong(t)
	a, b := haiCuaHang(t, h)

	t.Run("chen-id-cua-cua-hang-khac", func(t *testing.T) {
		for _, x := range bangTuyen {
			t.Run(x.nhom+" "+x.mau, func(t *testing.T) {
				var than any
				if x.body != nil {
					than = x.body(b)
				}
				res := h.goi(t, a.token, x.method, x.duong(b), than)

				if res.ma == http.StatusNotFound {
					return
				}
				if res.ma >= 200 && res.ma < 300 {
					t.Fatalf("RÒ RỈ: cửa hàng %s chạm được vào dữ liệu của %s — %s trả %d\n%s",
						a.ma, b.ma, x.mau, res.ma, catBot(res.than))
				}
				t.Fatalf("phải là 404 nhưng nhận %d — %s\n%s", res.ma, x.mau, catBot(res.than))
			})
		}
	})

	// Đối chứng. Không có lượt này thì một đường LUÔN trả 404 (route gõ sai, handler
	// hỏng) vẫn qua được lượt trên — bài kiểm xanh mà chẳng kiểm gì.
	t.Run("doi-chung-id-cua-chinh-minh", func(t *testing.T) {
		thuTu := make([]buoc, len(bangTuyen))
		copy(thuTu, bangTuyen)
		sort.SliceStable(thuTu, func(i, j int) bool { return thuTu[i].pha < thuTu[j].pha })

		for _, x := range thuTu {
			// Chạy tuần tự và KHÔNG dừng ở lỗi đầu tiên: mỗi đường sửa dữ liệu của
			// chính nó nên bỏ dở giữa chừng sẽ làm lệch các đường sau.
			t.Run(x.nhom+" "+x.mau, func(t *testing.T) {
				var than any
				if x.body != nil {
					than = x.body(a)
				}
				res := h.goi(t, a.token, x.method, x.duong(a), than)

				switch {
				case res.ma == http.StatusNotFound:
					t.Errorf("đối chứng hỏng: chính chủ gọi %s cũng nhận 404 — lượt kiểm chèn id ở trên vì thế không chứng minh được gì\n%s",
						x.mau, catBot(res.than))
				case res.ma == http.StatusUnauthorized || res.ma == http.StatusForbidden:
					t.Errorf("đối chứng hỏng: %s trả %d cho chính chủ (token/vai trò của bài kiểm sai)\n%s",
						x.mau, res.ma, catBot(res.than))
				case res.ma >= 500:
					t.Errorf("đối chứng hỏng: %s trả %d cho chính chủ\n%s", x.mau, res.ma, catBot(res.than))
				}
			})
		}
	})
}

// haiCuaHang dựng hai cửa hàng giả đã đăng nhập sẵn, kèm dọn dẹp.
//
// Dọn TRƯỚC khi gieo chứ không chỉ sau: lần chạy trước bị Ctrl-C giữa chừng thì
// dữ liệu còn đó, và lần này sẽ vỡ ở khoá unique với một thông báo chẳng liên
// quan gì tới điều đang kiểm.
func haiCuaHang(t *testing.T, h *heThong) (a, b *cuaHang) {
	t.Helper()

	cuA, cuB := timCuaHang(t, h.db, maA), timCuaHang(t, h.db, maB)
	xoaNenTang(t, h.nenTang, cuA, cuB)
	xoaCuaHang(t, h.db, cuA, cuB)

	a = gieo(t, h.db, maA)
	b = gieo(t, h.db, maB)
	t.Cleanup(func() {
		// Sổ nền tảng trước: tenant_domains trỏ vào tenants bên đó, mà bên đó lại
		// mang cùng id với data plane.
		xoaNenTang(t, h.nenTang, a.id, b.id)
		xoaCuaHang(t, h.db, a.id, b.id)
	})

	a.token = h.dangNhap(t, maA)
	b.token = h.dangNhap(t, maB)

	return a, b
}

// catBot cắt bớt thân phản hồi cho báo lỗi đọc được.
func catBot(s string) string {
	const toiDa = 400
	if len(s) <= toiDa {
		return s
	}
	return s[:toiDa] + "…"
}
