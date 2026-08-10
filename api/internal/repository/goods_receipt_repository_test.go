package repository

import (
	"context"
	"strings"
	"testing"

	"gorm.io/gorm"

	"sass-api/internal/domain"
)

// Trang "Nhập hàng" không có bảng riêng: nó dựng lại từng ĐỢT nhận hàng từ các
// bút toán sổ kho. Test này giữ đúng ba điều dễ vỡ nhất của cách làm đó:
//
//  1. Nhận hàng hai đợt phải ra HAI đợt nhập, đánh số N1/N2 theo thứ tự thời gian
//     (gom sai thì hai đợt dính thành một, hoặc một đợt bị xẻ làm hai).
//  2. Ghi chú người nhận phải đọc lại được, đã bỏ phần "Nhập hàng theo phiếu X"
//     mà tầng kho tự thêm.
//  3. Dòng hàng của mỗi đợt phải đúng đợt đó, kèm tồn trước/sau khớp sổ kho.
//
// Cần MySQL thật vì thứ đang kiểm là SQL gom nhóm do GORM sinh ra. Cách dựng DB
// test xem ghi chú ở product_repository_test.go.
func TestGoodsReceiptDungLaiTungDotNhan(t *testing.T) {
	db := testDB(t)
	ctx := context.Background()
	productID := seedProduct(t, db)

	// Hai biến thể để đợt nhận có nhiều dòng hàng.
	variants := []domain.ProductVariant{
		{ProductID: productID, SKU: "TEST-RCPT-M", Size: "M", IsActive: true},
		{ProductID: productID, SKU: "TEST-RCPT-L", Size: "L", IsActive: true},
	}
	for i := range variants {
		if err := db.Create(&variants[i]).Error; err != nil {
			t.Fatalf("không tạo được biến thể: %v", err)
		}
	}

	supplierID := seedSupplier(t, db)
	poRepo := NewPurchaseOrderRepository(db)
	po := &domain.PurchaseOrder{
		SupplierID:   &supplierID,
		SupplierName: "NCC kiểm thử nhập hàng",
		Status:       domain.PurchaseStatusOrdered,
		ItemsAmount:  1_000_000,
		TotalAmount:  1_000_000,
		Items: []domain.PurchaseOrderItem{
			{ProductID: &productID, ProductVariantID: &variants[0].ID, ProductName: "Áo test", VariantSKU: "TEST-RCPT-M", Quantity: 10, UnitCost: 50_000},
			{ProductID: &productID, ProductVariantID: &variants[1].ID, ProductName: "Áo test", VariantSKU: "TEST-RCPT-L", Quantity: 4, UnitCost: 125_000},
		},
	}
	if err := poRepo.Create(ctx, po); err != nil {
		t.Fatalf("không tạo được phiếu đặt: %v", err)
	}
	t.Cleanup(func() {
		db.Exec("DELETE FROM inventory_transactions WHERE reference_type = 'purchase_order' AND reference_id = ?", po.ID)
		db.Unscoped().Where("purchase_order_id = ?", po.ID).Delete(&domain.PurchaseOrderItem{})
		db.Unscoped().Delete(&domain.PurchaseOrder{}, po.ID)
		db.Unscoped().Delete(&domain.Supplier{}, supplierID)
	})

	items, err := poRepo.FindByID(ctx, po.ID)
	if err != nil {
		t.Fatalf("không đọc lại được phiếu: %v", err)
	}

	// Đợt 1: nhận thiếu (6/10 áo M) — phiếu phải chuyển sang "nhận một phần".
	if _, err := poRepo.Receive(ctx, po.ID, domain.PurchaseReceipt{
		Lines:      []domain.PurchaseReceiptLine{{ItemID: items.Items[0].ID, Quantity: 6}},
		UpdateCost: true,
		Note:       "NCC giao thiếu, hẹn tuần sau",
	}); err != nil {
		t.Fatalf("nhận đợt 1 lỗi: %v", err)
	}

	// Đợt 2: nhận nốt cả hai dòng — phiếu phải chuyển sang "đã nhận đủ".
	if _, err := poRepo.Receive(ctx, po.ID, domain.PurchaseReceipt{
		Lines: []domain.PurchaseReceiptLine{
			{ItemID: items.Items[0].ID, Quantity: 4},
			{ItemID: items.Items[1].ID, Quantity: 4},
		},
		UpdateCost: true,
	}); err != nil {
		t.Fatalf("nhận đợt 2 lỗi: %v", err)
	}

	repo := NewGoodsReceiptRepository(db)
	list, total, err := repo.List(ctx, domain.GoodsReceiptFilter{Page: 1, PageSize: 20, Sort: "oldest"})
	if err != nil {
		t.Fatalf("đọc danh sách đợt nhập lỗi: %v", err)
	}

	mine := make([]domain.GoodsReceipt, 0, 2)
	for _, r := range list {
		if r.PurchaseOrderID == po.ID {
			mine = append(mine, r)
		}
	}
	if len(mine) != 2 {
		t.Fatalf("phải ra 2 đợt nhập của phiếu này, nhận %d (tổng %d đợt)", len(mine), total)
	}

	if mine[0].Code != po.POCode+"-N1" || mine[1].Code != po.POCode+"-N2" {
		t.Fatalf("mã đợt sai: %q, %q", mine[0].Code, mine[1].Code)
	}
	if mine[0].Quantity != 6 || mine[0].LineCount != 1 {
		t.Fatalf("đợt 1 phải là 1 dòng / 6 sản phẩm, nhận %d dòng / %d sp", mine[0].LineCount, mine[0].Quantity)
	}
	if mine[1].Quantity != 8 || mine[1].LineCount != 2 {
		t.Fatalf("đợt 2 phải là 2 dòng / 8 sản phẩm, nhận %d dòng / %d sp", mine[1].LineCount, mine[1].Quantity)
	}
	// 6 × 50.000 = 300.000₫ theo giá nhập trên phiếu.
	if mine[0].Amount != 300_000 {
		t.Fatalf("giá trị đợt 1 phải là 300.000, nhận %.0f", mine[0].Amount)
	}
	if mine[0].Note != "NCC giao thiếu, hẹn tuần sau" {
		t.Fatalf("ghi chú đợt 1 sai: %q", mine[0].Note)
	}
	if strings.Contains(mine[0].Note, receiveHistoryPrefix) {
		t.Fatalf("ghi chú còn dính phần tự sinh: %q", mine[0].Note)
	}
	if mine[1].Note != "" {
		t.Fatalf("đợt 2 không nhập ghi chú thì phải rỗng, nhận %q", mine[1].Note)
	}
	if mine[1].POStatus != domain.PurchaseStatusReceived {
		t.Fatalf("nhận nốt rồi thì phiếu phải là %q, nhận %q", domain.PurchaseStatusReceived, mine[1].POStatus)
	}

	// Chi tiết đợt 1 chỉ được chứa dòng của đợt 1, kèm tồn trước/sau đúng sổ kho.
	one, err := repo.Find(ctx, mine[0].Code)
	if err != nil {
		t.Fatalf("đọc chi tiết đợt 1 lỗi: %v", err)
	}
	if len(one.Items) != 1 {
		t.Fatalf("đợt 1 phải có đúng 1 dòng hàng, nhận %d", len(one.Items))
	}
	it := one.Items[0]
	if it.Quantity != 6 || it.QuantityBefore != 0 || it.QuantityAfter != 6 {
		t.Fatalf("dòng hàng đợt 1 sai: qty=%d tồn %d->%d", it.Quantity, it.QuantityBefore, it.QuantityAfter)
	}
	if it.SKU != "TEST-RCPT-M" {
		t.Fatalf("dòng hàng đợt 1 phải là biến thể M, nhận %q", it.SKU)
	}

	// Lọc theo nhà cung cấp phải giữ cả hai đợt; nhà cung cấp khác thì rỗng.
	bySup, _, err := repo.List(ctx, domain.GoodsReceiptFilter{SupplierID: supplierID, Page: 1, PageSize: 20})
	if err != nil {
		t.Fatalf("lọc theo NCC lỗi: %v", err)
	}
	if len(bySup) != 2 {
		t.Fatalf("lọc theo NCC phải ra 2 đợt, nhận %d", len(bySup))
	}

	// Lọc theo từ khoá dùng mã đợt.
	byCode, _, err := repo.List(ctx, domain.GoodsReceiptFilter{Keyword: mine[1].Code, Page: 1, PageSize: 20})
	if err != nil {
		t.Fatalf("lọc theo mã đợt lỗi: %v", err)
	}
	if len(byCode) != 1 || byCode[0].Code != mine[1].Code {
		t.Fatalf("lọc theo mã đợt %q ra %d dòng", mine[1].Code, len(byCode))
	}

	if _, err := repo.Find(ctx, po.POCode+"-N9"); err == nil {
		t.Fatal("mã đợt không tồn tại phải trả lỗi")
	}
}

// seedSupplier tạo một nhà cung cấp tối giản để treo phiếu đặt vào.
func seedSupplier(t *testing.T, db *gorm.DB) uint {
	t.Helper()
	s := &domain.Supplier{Code: "TEST-NCC-RCPT", Name: "NCC kiểm thử nhập hàng", IsActive: true}
	db.Unscoped().Where("code = ?", s.Code).Delete(&domain.Supplier{})
	if err := db.Create(s).Error; err != nil {
		t.Fatalf("không tạo được nhà cung cấp: %v", err)
	}
	return s.ID
}
