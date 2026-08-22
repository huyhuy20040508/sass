package repository

import (
	"testing"

	"gorm.io/gorm"

	"sass-api/internal/domain"
)

// Trả hàng nhập là nghiệp vụ TRỪ tồn kho, nên test này giữ đúng những chỗ mà một
// lỗi sẽ làm sổ kho nói sai:
//
//  1. Phiếu nháp KHÔNG được đụng tới tồn kho.
//  2. Chốt "đã trả NCC" trừ kho đúng số, ghi bút toán export với số lượng ÂM và
//     tồn trước/sau khớp thực tế.
//  3. Chốt hai lần không được trừ kho hai lần.
//  4. Số còn trả được = đã nhận − phần đã nằm trong phiếu trả khác (kể cả nháp).
//  5. Kho không đủ hàng thì KHÔNG trừ dòng nào (tất cả hoặc không).
//
// Cần MySQL thật vì thứ đang kiểm là SQL do GORM sinh ra. Cách dựng DB test xem
// ghi chú ở product_repository_test.go.
func TestPurchaseReturnTruKhoDungMotLan(t *testing.T) {
	db := testDB(t)
	ctx := ctxTest()
	productID := seedProduct(t, db)

	v := domain.ProductVariant{ProductID: productID, SKU: "TEST-PRET-M", Name: "M", IsActive: true}
	if err := db.WithContext(ctxTest()).Create(&v).Error; err != nil {
		t.Fatalf("không tạo được biến thể: %v", err)
	}

	poRepo := NewPurchaseOrderRepository(db)
	po := &domain.PurchaseOrder{
		SupplierName: "NCC kiểm thử trả hàng",
		Status:       domain.PurchaseStatusOrdered,
		// Bắt buộc dưới MySQL strict — xem chú thích ở seedProduct
		// (product_repository_test.go).
		PaymentStatus: domain.PurchasePaymentUnpaid,
		Items: []domain.PurchaseOrderItem{
			{ProductID: &productID, ProductVariantID: &v.ID, ProductName: "Áo test", VariantSKU: "TEST-PRET-M", Quantity: 10, UnitCost: 100_000},
		},
	}
	if err := poRepo.Create(ctx, po); err != nil {
		t.Fatalf("không tạo được phiếu đặt: %v", err)
	}
	t.Cleanup(func() {
		db.WithContext(ctxRaw()).Exec(`DELETE FROM inventory_transactions
			WHERE (reference_type = 'purchase_order' AND reference_id = ?)
			   OR (reference_type = 'purchase_return' AND reference_id IN
			       (SELECT id FROM purchase_returns WHERE purchase_order_id = ?))`, po.ID, po.ID)
		db.WithContext(ctxRaw()).Exec("DELETE FROM purchase_returns WHERE purchase_order_id = ?", po.ID)
		db.WithContext(ctxTest()).Unscoped().Where("purchase_order_id = ?", po.ID).Delete(&domain.PurchaseOrderItem{})
		db.WithContext(ctxTest()).Unscoped().Delete(&domain.PurchaseOrder{}, po.ID)
	})

	saved, err := poRepo.FindByID(ctx, po.ID)
	if err != nil {
		t.Fatalf("không đọc lại được phiếu đặt: %v", err)
	}
	poItemID := saved.Items[0].ID

	// Nhận 8/10 về kho để có hàng mà trả.
	if _, err := poRepo.Receive(ctx, po.ID, domain.PurchaseReceipt{
		Lines:      []domain.PurchaseReceiptLine{{ItemID: poItemID, Quantity: 8}},
		UpdateCost: true,
	}); err != nil {
		t.Fatalf("nhận hàng lỗi: %v", err)
	}
	if got := stockOf(t, db, v.ID); got != 8 {
		t.Fatalf("sau khi nhận, tồn phải là 8, nhận %d", got)
	}

	repo := NewPurchaseReturnRepository(db)

	// Chỉ dòng đã nhận mới trả được, và tối đa bằng số đã nhận.
	returnable, err := repo.Returnable(ctx, po.ID)
	if err != nil {
		t.Fatalf("đọc hàng còn trả được lỗi: %v", err)
	}
	if len(returnable) != 1 || returnable[0].Received != 8 || returnable[0].Remain != 8 || returnable[0].Stock != 8 {
		t.Fatalf("hàng còn trả được sai: %+v", returnable)
	}

	// --- (1) Phiếu NHÁP không được đụng tới tồn kho ---
	rt := &domain.PurchaseReturn{
		PurchaseOrderID: &po.ID,
		POCode:          po.POCode,
		SupplierName:    po.SupplierName,
		Status:          domain.PurchaseReturnStatusDraft,
		Reason:          domain.PurchaseReturnReasonDefect,
		ItemsAmount:     300_000,
		RefundStatus:    domain.PurchaseRefundUnpaid,
		Items: []domain.PurchaseReturnItem{
			{PurchaseOrderItemID: &poItemID, ProductID: &productID, ProductVariantID: &v.ID,
				ProductName: "Áo test", VariantSKU: "TEST-PRET-M", Quantity: 3, UnitCost: 100_000, TotalCost: 300_000},
		},
	}
	if err := repo.Create(ctx, rt); err != nil {
		t.Fatalf("lập phiếu trả nháp lỗi: %v", err)
	}
	if got := stockOf(t, db, v.ID); got != 8 {
		t.Fatalf("phiếu NHÁP không được trừ kho: tồn phải còn 8, nhận %d", got)
	}
	if rt.ReturnCode == "" || rt.ReturnCode[:2] != "PR" {
		t.Fatalf("mã phiếu trả sai khuôn: %q", rt.ReturnCode)
	}

	// --- (4) Phiếu nháp đang giữ chỗ 3 cái -> còn trả được 5 ---
	returnable, err = repo.Returnable(ctx, po.ID)
	if err != nil {
		t.Fatalf("đọc lại hàng còn trả được lỗi: %v", err)
	}
	if returnable[0].Returned != 3 || returnable[0].Remain != 5 {
		t.Fatalf("phiếu nháp phải giữ chỗ 3 cái: %+v", returnable[0])
	}

	// --- (2) Chốt phiếu: trừ kho + ghi bút toán ---
	if _, err := repo.MarkReturned(ctx, rt.ID, 0); err != nil {
		t.Fatalf("chốt phiếu trả lỗi: %v", err)
	}
	if got := stockOf(t, db, v.ID); got != 5 {
		t.Fatalf("sau khi trả 3, tồn phải là 5, nhận %d", got)
	}

	var tx domain.InventoryTransaction
	if err := db.WithContext(ctxTest()).Where("reference_type = 'purchase_return' AND reference_id = ?", rt.ID).
		First(&tx).Error; err != nil {
		t.Fatalf("không tìm thấy bút toán trả hàng: %v", err)
	}
	if tx.Type != "export" || tx.Quantity != -3 || tx.QuantityBefore != 8 || tx.QuantityAfter != 5 {
		t.Fatalf("bút toán trả hàng sai: type=%s qty=%d tồn %d->%d",
			tx.Type, tx.Quantity, tx.QuantityBefore, tx.QuantityAfter)
	}

	// --- (3) Chốt lần hai phải bị chặn và không trừ thêm ---
	if _, err := repo.MarkReturned(ctx, rt.ID, 0); err == nil {
		t.Fatal("chốt phiếu hai lần phải trả lỗi")
	}
	if got := stockOf(t, db, v.ID); got != 5 {
		t.Fatalf("chốt hai lần đã trừ kho thêm: tồn %d", got)
	}

	// --- (5) Kho không đủ: phiếu trả 6 cái trong khi kho chỉ còn 5 ---
	over := &domain.PurchaseReturn{
		PurchaseOrderID: &po.ID,
		POCode:          po.POCode,
		SupplierName:    po.SupplierName,
		Status:          domain.PurchaseReturnStatusDraft,
		Reason:          domain.PurchaseReturnReasonOther,
		ItemsAmount:     500_000,
		RefundStatus:    domain.PurchaseRefundUnpaid,
		Items: []domain.PurchaseReturnItem{
			{PurchaseOrderItemID: &poItemID, ProductID: &productID, ProductVariantID: &v.ID,
				ProductName: "Áo test", VariantSKU: "TEST-PRET-M", Quantity: 5, UnitCost: 100_000, TotalCost: 500_000},
		},
	}
	if err := repo.Create(ctx, over); err != nil {
		t.Fatalf("lập phiếu trả thứ hai lỗi: %v", err)
	}
	// Bán bớt 1 cái để kho chỉ còn 4 < 5 của phiếu.
	//
	// variant_stocks là nơi DUY NHẤT giữ số hàng (từ 0036 không còn cột cache
	// nào ở product_variants nữa), và phép kiểm "đủ hàng để trả không" đọc tồn
	// của chính chi nhánh giữ hàng.
	if err := db.WithContext(ctxTest()).Model(&domain.TonKhoChiNhanh{}).
		Where("product_variant_id = ?", v.ID).Update("quantity", 4).Error; err != nil {
		t.Fatalf("không đặt được tồn kho chi nhánh: %v", err)
	}
	if _, err := repo.MarkReturned(ctx, over.ID, 0); err != domain.ErrOutOfStock {
		t.Fatalf("kho không đủ phải trả ErrOutOfStock, nhận %v", err)
	}
	if got := stockOf(t, db, v.ID); got != 4 {
		t.Fatalf("chốt thất bại không được đụng tới kho: tồn %d", got)
	}
	var count int64
	db.WithContext(ctxTest()).Model(&domain.InventoryTransaction{}).
		Where("reference_type = 'purchase_return' AND reference_id = ?", over.ID).Count(&count)
	if count != 0 {
		t.Fatalf("chốt thất bại không được ghi bút toán, có %d dòng", count)
	}

	// Lịch sử phiếu: tạo + chốt = 2 mốc.
	his, err := repo.Histories(ctx, rt.ID)
	if err != nil {
		t.Fatalf("đọc lịch sử lỗi: %v", err)
	}
	if len(his) != 2 || his[1].ToStatus != domain.PurchaseReturnStatusReturned {
		t.Fatalf("lịch sử phiếu sai: %d mốc, cuối = %+v", len(his), his[len(his)-1])
	}

	// Thống kê: chỉ tính phiếu đã trả thật (phiếu nháp thứ hai không được tính).
	stats, err := repo.Stats(ctx)
	if err != nil {
		t.Fatalf("đọc thống kê lỗi: %v", err)
	}
	if stats.Returned < 1 || stats.ReturnedQuantity < 3 {
		t.Fatalf("thống kê phải tính phiếu đã trả: %+v", stats)
	}
}

// stockOf đọc tồn kho hiện tại của biến thể, đọc thẳng DB để không phụ thuộc
// bất kỳ cache nào của repository.
// stockOf đọc tồn của MỘT biến thể trên toàn cửa hàng.
//
// Cộng từ variant_stocks chứ không đọc một cột cache: từ migration 0036 không
// còn cột nào giữ sẵn con số này, và đó là chủ ý — một bản cộng nằm sẵn trong
// bảng là chỗ để hai nguồn sự thật lệch nhau.
func stockOf(t *testing.T, db *gorm.DB, variantID uint) int {
	t.Helper()
	var stock int
	if err := db.WithContext(ctxRaw()).
		Raw("SELECT COALESCE(SUM(quantity), 0) FROM variant_stocks WHERE product_variant_id = ?", variantID).
		Scan(&stock).Error; err != nil {
		t.Fatalf("không đọc được tồn kho: %v", err)
	}
	return stock
}
