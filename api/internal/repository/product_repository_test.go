package repository

import (
	"context"
	"os"
	"testing"

	"gorm.io/driver/mysql"
	"gorm.io/gorm"
	"gorm.io/gorm/logger"

	"sass-api/internal/domain"
)

// Test cần MySQL thật vì thứ đang kiểm là hành vi sinh SQL của GORM
// (Omit + Save), không phải logic Go — mock lại thì test luôn xanh kể cả khi
// câu UPDATE sai. Bỏ qua khi máy không khai DSN.
//
// Dựng DB test (một lần, dùng chính lược đồ thật qua công cụ migration):
//
//	mysql -u root -e "CREATE DATABASE IF NOT EXISTS football_shop_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
//	cd ../.. && DB_NAME=football_shop_test go run ./cmd/migrate chay -y
//
// Rồi chạy:
//
//	TEST_DB_DSN="root:@tcp(127.0.0.1:3306)/football_shop_test?parseTime=true" go test ./internal/repository/
func testDB(t *testing.T) *gorm.DB {
	t.Helper()
	dsn := os.Getenv("TEST_DB_DSN")
	if dsn == "" {
		t.Skip("bỏ qua: chưa đặt TEST_DB_DSN")
	}
	db, err := gorm.Open(mysql.Open(dsn), &gorm.Config{
		Logger: logger.Default.LogMode(logger.Silent),
	})
	if err != nil {
		t.Fatalf("không kết nối được DB test: %v", err)
	}
	return db
}

// seedProduct tạo một sản phẩm trống để treo biến thể vào, kèm dọn dẹp.
func seedProduct(t *testing.T, db *gorm.DB) uint {
	t.Helper()
	var categoryID uint
	if err := db.Raw("SELECT id FROM categories LIMIT 1").Scan(&categoryID).Error; err != nil || categoryID == 0 {
		if err := db.Exec(
			"INSERT INTO categories (name, slug, is_active, created_at, updated_at) VALUES ('Test', 'test-cat', 1, NOW(3), NOW(3))",
		).Error; err != nil {
			t.Fatalf("không tạo được danh mục: %v", err)
		}
		db.Raw("SELECT id FROM categories WHERE slug = 'test-cat'").Scan(&categoryID)
	}

	p := &domain.Product{
		CategoryID: categoryID,
		Name:       "SP kiểm thử tồn kho",
		Slug:       "sp-kiem-thu-ton-kho",
		SKU:        "TEST-STOCK-SKU",
		KitType:    "fan",
		BasePrice:  100000,
	}
	db.Where("slug = ?", p.Slug).Unscoped().Delete(&domain.Product{})
	if err := db.Create(p).Error; err != nil {
		t.Fatalf("không tạo được sản phẩm: %v", err)
	}
	t.Cleanup(func() {
		db.Unscoped().Where("product_id = ?", p.ID).Delete(&domain.ProductVariant{})
		db.Unscoped().Delete(&domain.Product{}, p.ID)
	})
	return p.ID
}

// Sửa sản phẩm KHÔNG được đụng tới tồn kho. Đây là bảo chứng quan trọng nhất
// của luồng mới: tồn kho chỉ đổi qua nghiệp vụ kho và luôn kèm bút toán trong
// inventory_transactions. GORM Save() vốn ghi mọi cột, nên nếu ai đó gỡ
// Omit("stock_quantity") thì mỗi lần sửa sản phẩm sẽ đạp tồn về 0.
func TestReplaceVariantsKhongDungToiTonKho(t *testing.T) {
	db := testDB(t)
	repo := NewProductRepository(db)
	ctx := context.Background()
	productID := seedProduct(t, db)

	// Tạo biến thể lần đầu — tồn phải lấy DEFAULT 0 của DB.
	if err := repo.ReplaceVariants(ctx, productID, []domain.ProductVariant{
		{SKU: "TEST-STOCK-SKU-M", Size: "M", IsActive: true},
	}); err != nil {
		t.Fatalf("tạo biến thể lỗi: %v", err)
	}

	var v domain.ProductVariant
	if err := db.Where("product_id = ? AND size = ?", productID, "M").First(&v).Error; err != nil {
		t.Fatalf("không đọc được biến thể vừa tạo: %v", err)
	}
	if v.StockQuantity != 0 {
		t.Fatalf("biến thể mới phải có tồn 0, nhận %d", v.StockQuantity)
	}

	// Kho nhập 50 cái (mô phỏng nhận hàng từ phiếu nhập).
	if err := db.Model(&domain.ProductVariant{}).Where("id = ?", v.ID).
		UpdateColumn("stock_quantity", 50).Error; err != nil {
		t.Fatalf("không cập nhật được tồn kho: %v", err)
	}

	// Sửa sản phẩm: đổi giá riêng của đúng biến thể đó. Payload không mang tồn
	// kho (StockQuantity là zero-value) — đúng như buildVariants dựng ra.
	gia := 199000.0
	if err := repo.ReplaceVariants(ctx, productID, []domain.ProductVariant{
		{ID: v.ID, SKU: "TEST-STOCK-SKU-M", Size: "M", Price: &gia, IsActive: true},
	}); err != nil {
		t.Fatalf("sửa biến thể lỗi: %v", err)
	}

	var sau domain.ProductVariant
	if err := db.First(&sau, v.ID).Error; err != nil {
		t.Fatalf("không đọc lại được biến thể: %v", err)
	}
	if sau.StockQuantity != 50 {
		t.Fatalf("sửa sản phẩm đã đạp tồn kho: mong 50, nhận %d", sau.StockQuantity)
	}
	if sau.Price == nil || *sau.Price != gia {
		t.Fatalf("giá riêng không được ghi: %v", sau.Price)
	}
}

// Xoá một size rồi thêm lại đúng size/SKU đó phải chạy được. Biến thể bị gỡ chỉ
// bị xoá mềm (sổ kho và giỏ hàng còn trỏ vào), nên unique key phải tính cả
// deleted_at — nếu không thì thêm lại sẽ vướng lỗi trùng khoá.
func TestReplaceVariantsThemLaiSizeDaXoa(t *testing.T) {
	db := testDB(t)
	repo := NewProductRepository(db)
	ctx := context.Background()
	productID := seedProduct(t, db)

	if err := repo.ReplaceVariants(ctx, productID, []domain.ProductVariant{
		{SKU: "TEST-STOCK-SKU-L", Size: "L", IsActive: true},
	}); err != nil {
		t.Fatalf("tạo biến thể lỗi: %v", err)
	}

	// Gửi danh sách rỗng -> size L bị xoá mềm.
	if err := repo.ReplaceVariants(ctx, productID, []domain.ProductVariant{}); err != nil {
		t.Fatalf("xoá biến thể lỗi: %v", err)
	}

	// Thêm lại đúng size + SKU đó.
	if err := repo.ReplaceVariants(ctx, productID, []domain.ProductVariant{
		{SKU: "TEST-STOCK-SKU-L", Size: "L", IsActive: true},
	}); err != nil {
		t.Fatalf("thêm lại size đã xoá bị lỗi: %v", err)
	}

	var dem int64
	db.Model(&domain.ProductVariant{}).Where("product_id = ?", productID).Count(&dem)
	if dem != 1 {
		t.Fatalf("phải còn đúng 1 biến thể đang sống, nhận %d", dem)
	}
}
