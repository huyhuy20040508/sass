package apitest

import (
	"context"
	"testing"
	"time"

	"gorm.io/gorm"

	"sass-api/internal/domain"
	"sass-api/internal/tenant"
	"sass-api/pkg/hash"
)

// cuaHang là MỘT khách hàng giả cùng toàn bộ dữ liệu của nó.
//
// Mỗi trường là một id sẽ được đem đi thử trên URL của cửa hàng KIA. Gieo đủ mọi
// loại chứ không chỉ vài loại tiêu biểu: chỗ rò rỉ không nằm ở nhóm route mà
// người viết nhớ ra, nó nằm ở nhóm mà người viết quên.
type cuaHang struct {
	id    uint
	ma    string // tenants.code — ô thứ nhất của màn hình đăng nhập
	vet   string // dấu vết nhét vào mọi tên/tiêu đề, để dò rò rỉ ở các đường danh sách
	token string

	quanTri  uint // tài khoản đăng nhập
	nhanVien uint

	// Hai nhóm quyền mặc định của cửa hàng này — bài kiểm phân quyền cần id để
	// tick thêm hoặc bỏ bớt quyền rồi xem chốt phản ứng ra sao.
	nhomQuanLy  uint
	nhomThuNgan uint
	khach       uint // khách hàng (vai trò customer)

	chiNhanh uint // điểm bán (bảng shops), KHÔNG phải cửa hàng
	danhMuc  uint
	sanPham  uint
	slug     string
	bienThe  uint

	donHang    uint   // trạng thái confirmed — dùng cho các đường sửa đơn
	maDonHang  string // orders.order_code — khoá của đường tra cứu công khai
	donGiao    uint   // trạng thái delivered — dùng cho trả hàng
	traHang    uint
	maGiaoDich string // payments.transaction_code — khoá của /payments/{code}

	nhaCungCap uint
	phieuDat   uint // ordered, chưa nhận đợt nào
	dongDat    uint // purchase_order_items.id của phieuDat
	phieuNhan  uint // received, đã có đợt nhập
	dongNhan   uint // purchase_order_items.id của phieuNhan
	maDotNhap  string
	traNhap    uint

	banner    uint
	khuyenMai uint
	voucher   uint
	yeuCau    uint
	nhanTin   uint
	thongBao  uint
}

// gieo dựng một cửa hàng đầy đủ dữ liệu.
//
// Ghi thẳng qua GORM với ctx của cửa hàng chứ không gọi API: mục đích của bài
// kiểm là kiểm ĐƯỜNG ĐỌC, nên đường ghi phải là con đường ngắn nhất và chắc
// chắn nhất. Bộ lọc tenant tự điền tenant_id cho mọi dòng ở đây — đó cũng là
// điều tenant_scope_test đã kiểm riêng.
// maChiNhanhGoc là mã chi nhánh đầu tiên của mọi cửa hàng — khớp hằng số cùng
// nghĩa bên repository. Chỉ duy nhất TRONG một tenant, nên hai cửa hàng giả cùng
// mang mã này là chuyện đúng, và cũng chính là thứ đáng kiểm.
const maChiNhanhGoc = "mac-dinh"

func gieo(t *testing.T, db *gorm.DB, ma string) *cuaHang {
	t.Helper()

	c := &cuaHang{ma: ma, vet: "vet" + ma}
	nen := context.Background()

	// tenants là bảng toàn cục — ghi được mà không cần ctx nào.
	//
	// Đếm trước rồi mới đọc, thay vì First() rồi bắt ErrRecordNotFound: GORM ghi
	// "record not found" ra nhật ký ở mức LỖI, và mỗi lần chạy lại đẻ ra hai dòng
	// đỏ chẳng liên quan gì ngay trước phần kết quả thật.
	var co int64
	if err := db.WithContext(nen).Model(&domain.Tenant{}).Where("code = ?", ma).Count(&co).Error; err != nil {
		t.Fatalf("không tra được cửa hàng %s: %v", ma, err)
	}
	tn := domain.Tenant{Code: ma, Name: "Cửa hàng " + c.vet, Status: domain.TenantActive}
	if co > 0 {
		if err := db.WithContext(nen).Where("code = ?", ma).First(&tn).Error; err != nil {
			t.Fatalf("không đọc được cửa hàng %s: %v", ma, err)
		}
	} else if err := db.WithContext(nen).Create(&tn).Error; err != nil {
		t.Fatalf("không tạo được cửa hàng %s: %v", ma, err)
	}
	c.id = tn.ID

	ctx := tenant.WithID(nen, c.id)
	now := time.Now()

	bam, err := hash.Hash(matKhauTest)
	if err != nil {
		t.Fatalf("không băm được mật khẩu: %v", err)
	}

	// --- người ---
	quanTri := &domain.User{
		Username: domain.StringOrNull("quantri"), RoleID: domain.AdminRoleID,
		FullName: "Quản trị " + c.vet, Email: "quantri@" + c.vet + ".test",
		PasswordHash: bam, Status: "active", EmailVerifiedAt: &now,
	}
	tao(t, db, ctx, quanTri)
	c.quanTri = quanTri.ID

	nhanVien := &domain.User{
		Username: domain.StringOrNull("nhanvien"), RoleID: domain.StaffRoleID,
		FullName: "Nhân viên " + c.vet, Email: "nhanvien@" + c.vet + ".test",
		PasswordHash: bam, Status: "active", EmailVerifiedAt: &now,
	}
	tao(t, db, ctx, nhanVien)
	c.nhanVien = nhanVien.ID

	khach := &domain.User{
		RoleID: domain.CustomerRoleID, FullName: "Khách " + c.vet,
		Email: "khach@" + c.vet + ".test", PasswordHash: bam,
		Status: "active", EmailVerifiedAt: &now,
	}
	tao(t, db, ctx, khach)
	c.khach = khach.ID

	// --- nhóm quyền ---
	//
	// Gieo Y HỆT cmd/quyen làm với cửa hàng thật: hai nhóm mặc định, rồi xếp
	// người vào theo vai trò. Thiếu bước này thì mọi tài khoản trong bộ kiểm
	// không có quyền nào và cả gói đỏ ở lượt 403 đầu tiên — mà đỏ vì bối cảnh
	// gieo thiếu, không phải vì chốt sai.
	gieoNhomQuyen(t, db, ctx, c, quanTri.ID, nhanVien.ID)

	// --- điểm bán ---
	//
	// Cửa hàng thật luôn được dựng kèm đúng một chi nhánh 'mac-dinh' (xem
	// CuaHangMoiRepository.Tao). Gieo lại đúng như vậy để bài kiểm danh sách so
	// được một con số có nghĩa, thay vì so hai danh sách cùng rỗng.
	chiNhanh := &domain.ChiNhanh{
		Code: maChiNhanhGoc, Name: "Chi nhánh " + c.vet, IsActive: true,
	}
	tao(t, db, ctx, chiNhanh)
	c.chiNhanh = chiNhanh.ID

	// --- hàng hoá ---
	danhMuc := &domain.Category{Name: "Danh mục " + c.vet, Slug: "dm-" + c.vet, IsActive: true}
	tao(t, db, ctx, danhMuc)
	c.danhMuc = danhMuc.ID

	c.slug = "sp-" + c.vet
	sanPham := &domain.Product{
		CategoryID: danhMuc.ID,
		Name:       "Sản phẩm " + c.vet, Slug: c.slug, SKU: "sku-" + c.vet,
		BasePrice: 100000, Status: domain.ProductStatusActive, IsActive: true,
	}
	tao(t, db, ctx, sanPham)
	c.sanPham = sanPham.ID

	bienThe := &domain.ProductVariant{
		ProductID: sanPham.ID, SKU: "sku-" + c.vet + "-m", Size: "M",
		StockQuantity: 20, IsActive: true,
	}
	tao(t, db, ctx, bienThe)
	c.bienThe = bienThe.ID

	// Tồn kho phải gieo Ở CHI NHÁNH, không chỉ ở cột stock_quantity: từ migration
	// 0005 cột kia là bản cộng sẵn, còn nguồn sự thật là variant_stocks. Gieo
	// thiếu dòng này thì mọi bài kiểm nghiệp vụ kho chạy trên một cửa hàng "có
	// hàng trên màn hình mà kho trống rỗng" — và chúng sẽ hỏng vì lý do chẳng
	// liên quan gì tới điều đang kiểm.
	tao(t, db, ctx, &domain.TonKhoChiNhanh{
		ShopID: c.chiNhanh, ProductVariantID: bienThe.ID, Quantity: bienThe.StockQuantity,
	})

	// --- đơn hàng ---
	c.maDonHang = "dh-" + c.vet
	c.donHang = gieoDon(t, db, ctx, c, c.maDonHang, domain.OrderStatusConfirmed)
	c.donGiao = gieoDon(t, db, ctx, c, "dg-"+c.vet, domain.OrderStatusDelivered)

	var dongGiao domain.OrderItem
	if err := db.WithContext(ctx).Where("order_id = ?", c.donGiao).First(&dongGiao).Error; err != nil {
		t.Fatalf("không đọc lại được dòng hàng của đơn: %v", err)
	}

	traHang := &domain.OrderReturn{
		ShopID:     c.chiNhanh,
		ReturnCode: "th-" + c.vet, OrderID: c.donGiao, UserID: &khach.ID,
		Status: domain.ReturnStatusPending, Reason: domain.ReturnReasonDefective,
		ReasonNote: "Lý do " + c.vet, RequestedBy: "customer",
		// PHẢI khai, dù cột có DEFAULT 'bank_transfer': GORM ghi mọi trường vào
		// câu INSERT kể cả trường bỏ trống, nên database nhận chuỗi rỗng chứ
		// không bao giờ chạm tới giá trị mặc định. Mà '' không nằm trong ENUM.
		//
		// MySQL 8 (máy chủ, và CI) chạy STRICT_TRANS_TABLES nên nó BÁO LỖI:
		// "Data truncated for column 'refund_method'". MySQL/MariaDB cài kèm
		// XAMPP thường tắt strict, ở đó cùng câu lệnh chỉ là một cảnh báo và
		// hàng vẫn vào — nên lỗi này xanh suốt trên máy phát triển.
		//
		// bank_transfer là đúng thứ luồng thật sinh ra: dịch vụ tự điền giá trị
		// này khi người trả hàng không chọn (order_return_service.go).
		RefundMethod: "bank_transfer",
		ItemsAmount:  100000, RefundAmount: 100000, Restock: true,
	}
	tao(t, db, ctx, traHang)
	c.traHang = traHang.ID
	tao(t, db, ctx, &domain.OrderReturnItem{
		ReturnID: traHang.ID, OrderItemID: dongGiao.ID,
		ProductID: &sanPham.ID, ProductVariantID: &bienThe.ID,
		ProductName: "Sản phẩm " + c.vet, VariantSKU: bienThe.SKU,
		UnitPrice: 100000, Quantity: 1, TotalPrice: 100000,
	})

	// Một lần thử thanh toán của đơn — đường /payments/{code} công khai tra theo
	// mã giao dịch, nên nó là một cách nữa để với sang đơn của cửa hàng khác.
	thanhToan := &domain.Payment{
		OrderID: c.donHang, TransactionCode: "tt-" + c.vet,
		Provider: domain.PaymentMethodSePay, Amount: 100000, Currency: "VND",
		Status: domain.PaymentStatusPending,
	}
	tao(t, db, ctx, thanhToan)
	c.maGiaoDich = thanhToan.TransactionCode

	// --- mua vào ---
	nhaCungCap := &domain.Supplier{
		Code: "ncc-" + c.vet, Name: "Nhà cung cấp " + c.vet,
		Phone: "0900000002", IsActive: true,
	}
	tao(t, db, ctx, nhaCungCap)
	c.nhaCungCap = nhaCungCap.ID

	c.phieuDat, c.dongDat = gieoPhieuDat(t, db, ctx, c, "pd-"+c.vet, domain.PurchaseStatusOrdered, 0)
	c.phieuNhan, c.dongNhan = gieoPhieuDat(t, db, ctx, c, "pn-"+c.vet, domain.PurchaseStatusReceived, 5)

	// Một đợt nhập = các bút toán kho + MỘT mốc lịch sử ghi SAU chúng. Thứ tự
	// quan trọng: goods_receipt_repository gom bút toán theo mốc đứng sau chúng,
	// nên ghi mốc trước thì đợt nhập hiện ra với 0 dòng hàng.
	tao(t, db, ctx, &domain.InventoryTransaction{
		ShopID:           c.chiNhanh,
		ProductVariantID: c.bienThe, Type: "import", Quantity: 5,
		QuantityBefore: 15, QuantityAfter: 20,
		ReferenceType: "purchase_order", ReferenceID: &c.phieuNhan,
		UnitCost: conTro(60000.0), Note: "Nhập hàng theo phiếu pn-" + c.vet,
		CreatedBy: &quanTri.ID,
	})
	tao(t, db, ctx, &domain.PurchaseOrderHistory{
		PurchaseOrderID: c.phieuNhan, FromStatus: domain.PurchaseStatusOrdered,
		ToStatus: domain.PurchaseStatusReceived, Note: "Nhận 5 sản phẩm", ChangedBy: &quanTri.ID,
	})
	c.maDotNhap = "pn-" + c.vet + "-N1"

	traNhap := &domain.PurchaseReturn{
		ShopID:     c.chiNhanh,
		ReturnCode: "tn-" + c.vet, PurchaseOrderID: &c.phieuNhan, POCode: "pn-" + c.vet,
		SupplierID: &nhaCungCap.ID, SupplierName: "Nhà cung cấp " + c.vet,
		Status: domain.PurchaseReturnStatusDraft, Reason: domain.PurchaseReturnReasonDefect,
		ItemsAmount: 60000, RefundStatus: domain.PurchaseRefundUnpaid, Note: "Ghi chú " + c.vet,
	}
	tao(t, db, ctx, traNhap)
	c.traNhap = traNhap.ID
	tao(t, db, ctx, &domain.PurchaseReturnItem{
		PurchaseReturnID: traNhap.ID, PurchaseOrderItemID: &c.dongNhan,
		ProductID: &sanPham.ID, ProductVariantID: &bienThe.ID,
		ProductName: "Sản phẩm " + c.vet, VariantSKU: bienThe.SKU,
		Quantity: 1, UnitCost: 60000, TotalCost: 60000,
	})

	// --- tiếp thị & tương tác ---
	banner := &domain.Banner{
		Title: "Banner " + c.vet, Image: "/anh/" + c.vet + ".jpg",
		Position: domain.BannerPositionHomeSlider, IsActive: true,
	}
	tao(t, db, ctx, banner)
	c.banner = banner.ID

	khuyenMai := &domain.Promotion{
		Name: "Khuyến mãi " + c.vet, DiscountType: domain.DiscountPercentage, DiscountValue: 10,
		StartAt: now.Add(-time.Hour), EndAt: now.Add(24 * time.Hour), IsActive: true,
	}
	tao(t, db, ctx, khuyenMai)
	c.khuyenMai = khuyenMai.ID
	tao(t, db, ctx, &domain.PromotionTarget{
		PromotionID: khuyenMai.ID, TargetType: domain.PromotionTargetProduct, TargetID: sanPham.ID,
	})

	voucher := &domain.Voucher{
		Code: "voucher-" + c.vet, Description: "Mã của " + c.vet,
		DiscountType: domain.DiscountFixed, DiscountValue: 10000, IsActive: true,
	}
	tao(t, db, ctx, voucher)
	c.voucher = voucher.ID

	yeuCau := &domain.ContactRequest{
		Type: domain.ContactTypeContact, FullName: "Người gửi " + c.vet,
		Phone: "0900000003", Email: "lienhe@" + c.vet + ".test",
		Content: "Nội dung liên hệ của " + c.vet, Status: domain.ContactStatusNew,
	}
	tao(t, db, ctx, yeuCau)
	c.yeuCau = yeuCau.ID

	nhanTin := &domain.NewsletterSubscriber{
		Email: "nhantin@" + c.vet + ".test", IsActive: true, Source: "footer",
	}
	tao(t, db, ctx, nhanTin)
	c.nhanTin = nhanTin.ID

	// Cấu hình RIÊNG của cửa hàng. Bảng này chứa số tài khoản ngân hàng nhận
	// tiền, nên nó là chỗ mà một snapshot dùng chung sẽ gây thiệt hại thật.
	tao(t, db, ctx, &domain.Setting{Key: "site_name", Value: "Cửa hàng " + c.vet, Group: "general"})

	// user_id = nil là KÊNH QUẢN TRỊ — đúng kênh mà token quản trị đọc được.
	thongBao := &domain.Notification{
		Type: "system", Title: "Thông báo " + c.vet,
		Content: "Nội dung thông báo của " + c.vet, Data: "{}",
	}
	tao(t, db, ctx, thongBao)
	c.thongBao = thongBao.ID

	return c
}

// boSung gieo THÊM một bộ dữ liệu nữa cho cửa hàng c.
//
// Dùng cho bài kiểm số liệu tổng hợp: các đường /stats chỉ trả về con số, không
// có tên nào để dò dấu vết. Cách duy nhất nhìn ra rò rỉ là làm dữ liệu của cửa
// hàng KIA thay đổi rồi xem con số bên này có nhúc nhích không — nên bộ này phải
// chạm tới đủ mọi loại mà các trang thống kê đếm.
func boSung(t *testing.T, db *gorm.DB, c *cuaHang) {
	t.Helper()

	ctx := tenant.WithID(context.Background(), c.id)
	now := time.Now()
	hau := c.vet + "-2"

	bam, err := hash.Hash(matKhauTest)
	if err != nil {
		t.Fatalf("không băm được mật khẩu: %v", err)
	}

	nhanVien := &domain.User{
		Username: domain.StringOrNull("nhanvien2"), RoleID: domain.StaffRoleID,
		FullName: "Nhân viên " + hau, Email: "nhanvien2@" + c.vet + ".test",
		PasswordHash: bam, Status: "active", EmailVerifiedAt: &now,
	}
	tao(t, db, ctx, nhanVien)

	khach := &domain.User{
		RoleID: domain.CustomerRoleID, FullName: "Khách " + hau,
		Email: "khach2@" + c.vet + ".test", PasswordHash: bam,
		Status: "active", EmailVerifiedAt: &now,
	}
	tao(t, db, ctx, khach)

	sanPham := &domain.Product{
		CategoryID: c.danhMuc, Name: "Sản phẩm " + hau, Slug: "sp-" + hau, SKU: "sku-" + hau,
		BasePrice: 200000, CostPrice: conTro(120000.0),
		Status: domain.ProductStatusActive, IsActive: true,
	}
	tao(t, db, ctx, sanPham)

	bienThe := &domain.ProductVariant{
		ProductID: sanPham.ID, SKU: "sku-" + hau + "-l", Size: "L",
		StockQuantity: 7, IsActive: true,
	}
	tao(t, db, ctx, bienThe)

	// Đơn ĐÃ GIAO và ĐÃ TRẢ TIỀN: doanh thu, báo cáo và thống kê khách hàng chỉ
	// đếm những đơn đi được tới đây.
	don := &domain.Order{
		ShopID:    c.chiNhanh,
		OrderCode: "dh2-" + c.vet, UserID: &khach.ID,
		RecipientName: "Người nhận " + hau, RecipientPhone: "0900000005",
		ShippingAddress: "Địa chỉ của " + hau,
		SubtotalAmount:  200000, TotalAmount: 200000,
		PaymentMethod: domain.PaymentMethodCOD, PaymentStatus: domain.OrderPaymentPaid,
		Status: domain.OrderStatusCompleted, PlacedAt: &now, DeliveredAt: &now,
	}
	tao(t, db, ctx, don)
	dong := &domain.OrderItem{
		OrderID: don.ID, ProductID: &sanPham.ID, ProductVariantID: &bienThe.ID,
		ProductName: "Sản phẩm " + hau, VariantSKU: bienThe.SKU, Size: "L",
		UnitPrice: 200000, Quantity: 2, TotalPrice: 400000,
	}
	tao(t, db, ctx, dong)

	traHang := &domain.OrderReturn{
		ShopID:     c.chiNhanh,
		ReturnCode: "th2-" + c.vet, OrderID: don.ID, UserID: &khach.ID,
		Status: domain.ReturnStatusPending, Reason: domain.ReturnReasonWrongSize,
		// Xem chú thích ở lượt gieo phiếu trả hàng phía trên.
		RefundMethod: "bank_transfer",
		RequestedBy:  "customer", ItemsAmount: 200000, RefundAmount: 200000, Restock: true,
	}
	tao(t, db, ctx, traHang)
	tao(t, db, ctx, &domain.OrderReturnItem{
		ReturnID: traHang.ID, OrderItemID: dong.ID,
		ProductID: &sanPham.ID, ProductVariantID: &bienThe.ID,
		ProductName: "Sản phẩm " + hau, VariantSKU: bienThe.SKU,
		UnitPrice: 200000, Quantity: 1, TotalPrice: 200000,
	})

	nhaCungCap := &domain.Supplier{
		Code: "ncc2-" + c.vet, Name: "Nhà cung cấp " + hau, IsActive: true,
	}
	tao(t, db, ctx, nhaCungCap)

	phieu := &domain.PurchaseOrder{
		ShopID: c.chiNhanh,
		POCode: "pn2-" + c.vet, SupplierID: &nhaCungCap.ID, SupplierName: "Nhà cung cấp " + hau,
		Status: domain.PurchaseStatusReceived, ItemsAmount: 240000, TotalAmount: 240000,
		PaymentStatus: domain.PurchasePaymentUnpaid, OrderedAt: &now, ReceivedAt: &now,
		CreatedBy: &c.quanTri,
	}
	tao(t, db, ctx, phieu)
	dongPhieu := &domain.PurchaseOrderItem{
		PurchaseOrderID: phieu.ID, ProductID: &sanPham.ID, ProductVariantID: &bienThe.ID,
		ProductName: "Sản phẩm " + hau, VariantSKU: bienThe.SKU,
		UnitCost: 120000, Quantity: 2, ReceivedQuantity: 2, TotalCost: 240000,
	}
	tao(t, db, ctx, dongPhieu)
	tao(t, db, ctx, &domain.InventoryTransaction{
		ShopID:           c.chiNhanh,
		ProductVariantID: bienThe.ID, Type: "import", Quantity: 2,
		QuantityBefore: 5, QuantityAfter: 7,
		ReferenceType: "purchase_order", ReferenceID: &phieu.ID,
		UnitCost: conTro(120000.0), CreatedBy: &c.quanTri,
	})
	tao(t, db, ctx, &domain.PurchaseOrderHistory{
		PurchaseOrderID: phieu.ID, FromStatus: domain.PurchaseStatusOrdered,
		ToStatus: domain.PurchaseStatusReceived, Note: "Nhận 2 sản phẩm", ChangedBy: &c.quanTri,
	})

	traNhap := &domain.PurchaseReturn{
		ShopID:     c.chiNhanh,
		ReturnCode: "tn2-" + c.vet, PurchaseOrderID: &phieu.ID, POCode: phieu.POCode,
		SupplierID: &nhaCungCap.ID, SupplierName: "Nhà cung cấp " + hau,
		Status: domain.PurchaseReturnStatusDraft, Reason: domain.PurchaseReturnReasonDefect,
		ItemsAmount: 120000, RefundStatus: domain.PurchaseRefundUnpaid,
	}
	tao(t, db, ctx, traNhap)
	tao(t, db, ctx, &domain.PurchaseReturnItem{
		PurchaseReturnID: traNhap.ID, PurchaseOrderItemID: &dongPhieu.ID,
		ProductID: &sanPham.ID, ProductVariantID: &bienThe.ID,
		ProductName: "Sản phẩm " + hau, VariantSKU: bienThe.SKU,
		Quantity: 1, UnitCost: 120000, TotalCost: 120000,
	})

	tao(t, db, ctx, &domain.Promotion{
		Name: "Khuyến mãi " + hau, DiscountType: domain.DiscountFixed, DiscountValue: 5000,
		StartAt: now.Add(-time.Hour), EndAt: now.Add(24 * time.Hour), IsActive: true,
	})
	tao(t, db, ctx, &domain.Voucher{
		Code: "voucher2-" + c.vet, DiscountType: domain.DiscountFixed, DiscountValue: 5000, IsActive: true,
	})
	tao(t, db, ctx, &domain.ContactRequest{
		Type: domain.ContactTypeTradeIn, FullName: "Người gửi " + hau,
		Content: "Nội dung " + hau, Status: domain.ContactStatusNew,
	})
	tao(t, db, ctx, &domain.NewsletterSubscriber{
		Email: "nhantin2@" + c.vet + ".test", IsActive: true, Source: "footer",
	})
	tao(t, db, ctx, &domain.Banner{
		Title: "Banner " + hau, Image: "/anh/" + hau + ".jpg",
		Position: domain.BannerPositionHomePoster, IsActive: true,
	})
}

// gieoDon dựng một đơn hàng kèm đúng một dòng hàng.
func gieoDon(t *testing.T, db *gorm.DB, ctx context.Context, c *cuaHang, ma, trangThai string) uint {
	t.Helper()
	now := time.Now()

	don := &domain.Order{
		ShopID:    c.chiNhanh,
		OrderCode: ma, UserID: &c.khach,
		RecipientName: "Người nhận " + c.vet, RecipientPhone: "0900000004",
		RecipientEmail:  "nhan@" + c.vet + ".test",
		ShippingAddress: "Địa chỉ của " + c.vet,
		SubtotalAmount:  100000, TotalAmount: 100000,
		PaymentMethod: domain.PaymentMethodCOD, PaymentStatus: domain.OrderPaymentPending,
		Status: trangThai, PlacedAt: &now,
	}
	if trangThai == domain.OrderStatusDelivered {
		don.ConfirmedAt, don.ShippedAt, don.DeliveredAt = &now, &now, &now
	}
	tao(t, db, ctx, don)

	tao(t, db, ctx, &domain.OrderItem{
		OrderID: don.ID, ProductID: &c.sanPham, ProductVariantID: &c.bienThe,
		ProductName: "Sản phẩm " + c.vet, VariantSKU: "sku-" + c.vet + "-m", Size: "M",
		UnitPrice: 100000, Quantity: 1, TotalPrice: 100000,
	})

	return don.ID
}

// gieoPhieuDat dựng một phiếu đặt hàng nhập kèm một dòng, trả về id phiếu và id dòng.
func gieoPhieuDat(t *testing.T, db *gorm.DB, ctx context.Context, c *cuaHang, ma, trangThai string, daNhan int) (uint, uint) {
	t.Helper()
	now := time.Now()

	phieu := &domain.PurchaseOrder{
		ShopID: c.chiNhanh,
		POCode: ma, SupplierID: &c.nhaCungCap, SupplierName: "Nhà cung cấp " + c.vet,
		Status: trangThai, ItemsAmount: 300000, TotalAmount: 300000,
		PaymentStatus: domain.PurchasePaymentUnpaid, Note: "Phiếu của " + c.vet,
		OrderedAt: &now, CreatedBy: &c.quanTri,
	}
	if trangThai == domain.PurchaseStatusReceived {
		phieu.ReceivedAt = &now
	}
	tao(t, db, ctx, phieu)

	dong := &domain.PurchaseOrderItem{
		PurchaseOrderID: phieu.ID, ProductID: &c.sanPham, ProductVariantID: &c.bienThe,
		ProductName: "Sản phẩm " + c.vet, VariantSKU: "sku-" + c.vet + "-m", Size: "M",
		UnitCost: 60000, Quantity: 5, ReceivedQuantity: daNhan, TotalCost: 300000,
	}
	tao(t, db, ctx, dong)

	return phieu.ID, dong.ID
}

func tao(t *testing.T, db *gorm.DB, ctx context.Context, v any) {
	t.Helper()
	if err := db.WithContext(ctx).Create(v).Error; err != nil {
		t.Fatalf("không gieo được %T: %v", v, err)
	}
}

func conTro[T any](v T) *T { return &v }

// gieoNhomQuyen dựng hai nhóm mặc định cho một cửa hàng và xếp người vào.
//
// Dùng chung nguồn với đời thật: domain.NhomDungSan(). Chép tay danh sách quyền
// vào đây là dựng một bản thứ hai để nó lệch với bản mà cửa hàng thật đang chạy
// — và lúc đó bộ kiểm sẽ xanh cho một hệ thống không còn tồn tại.
func gieoNhomQuyen(t *testing.T, db *gorm.DB, ctx context.Context, c *cuaHang, quanTriID, nhanVienID uint) {
	t.Helper()

	nhom := map[string]uint{}
	for _, nm := range domain.NhomDungSan() {
		g := &domain.NhomQuyen{
			Code: nm.Code, Name: nm.Name, Description: nm.MoTa,
			IsSystem: true, FullAccess: nm.FullAccess,
		}
		tao(t, db, ctx, g)
		nhom[nm.Code] = g.ID

		for _, q := range nm.Quyen {
			it := &domain.NhomQuyenItem{GroupID: g.ID, Permission: q}
			tao(t, db, ctx, it)
		}
	}

	c.nhomQuanLy = nhom[domain.NhomQuyenQuanLy]
	c.nhomThuNgan = nhom[domain.NhomQuyenThuNgan]

	// Quyền gán THẲNG cho người (migration 0017): quản trị nhận cờ toàn quyền,
	// thu ngân nhận đúng danh sách của vai `staff` cũ. Hai nhóm trên chỉ là mẫu.
	if err := db.WithContext(ctx).Model(&domain.User{}).
		Where("id = ?", quanTriID).Update("toan_quyen", true).Error; err != nil {
		t.Fatalf("bật toàn quyền cho quản trị: %v", err)
	}
	for _, q := range domain.QuyenThuNgan() {
		tao(t, db, ctx, &domain.QuyenRieng{UserID: nhanVienID, Permission: q})
	}
}
