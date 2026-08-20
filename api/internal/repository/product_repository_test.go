package repository

import (
	"context"
	"os"
	"strings"
	"testing"

	"gorm.io/driver/mysql"
	"gorm.io/gorm"
	"gorm.io/gorm/logger"

	"sass-api/config"
	"sass-api/internal/domain"
	"sass-api/internal/tenant"
)

// Test cần MySQL thật vì thứ đang kiểm là hành vi sinh SQL của GORM
// (Omit + Save), không phải logic Go — mock lại thì test luôn xanh kể cả khi
// câu UPDATE sai. Bỏ qua khi máy không khai DSN.
//
// Dựng DB test (một lần, dùng chính lược đồ thật qua công cụ migration):
//
//	mysql -u root -e "CREATE DATABASE IF NOT EXISTS selliotech_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
//	cd ../.. && DB_NAME=selliotech_test go run ./cmd/migrate chay -y
//
// Rồi chạy:
//
//	TEST_DB_DSN="root:@tcp(127.0.0.1:3306)/selliotech_test?parseTime=true" go test ./internal/repository/
//
// Kết nối này GẮN BỘ LỌC TENANT y như bản chạy thật (xem tenant_scope.go). Bỏ
// plugin ra cho test "dễ chạy" thì test không còn kiểm cái đang chạy nữa: mọi
// câu truy vấn ở đây sẽ thiếu đúng mệnh đề mà production có.
//
// Hệ quả cho người viết test: mọi lượt gọi phải mang ctx có tenant (dùng
// ctxTest) và SQL viết tay phải bọc ctxRaw.
func testDB(t *testing.T) *gorm.DB {
	t.Helper()
	dsn := os.Getenv("TEST_DB_DSN")
	if dsn == "" {
		t.Skip("bỏ qua: chưa đặt TEST_DB_DSN")
	}
	db, err := gorm.Open(mysql.Open(ghimSQLMode(dsn)), &gorm.Config{
		Logger: logger.Default.LogMode(logger.Silent),
	})
	if err != nil {
		t.Fatalf("không kết nối được DB test: %v", err)
	}
	if err := db.Use(tenantScope{}); err != nil {
		t.Fatalf("không gắn được bộ lọc tenant: %v", err)
	}
	return db
}

// ghimSQLMode cộng config.SQLModeKiemThu vào TEST_DB_DSN nếu người chạy chưa khai.
//
// Gói này mở kết nối THẲNG bằng chuỗi DSN lấy từ biến môi trường, không đi qua
// config.DatabaseConfig.DSN() như internal/apitest — nên nó phải tự làm lấy.
// Thiếu dòng này thì bộ test ở đây chạy dưới sql_mode của máy ai nấy dùng: XAMPP
// để lỏng thì ghi chuỗi rỗng vào cột ENUM vẫn lọt, còn máy chủ và CI báo lỗi.
//
// Vẫn tôn trọng lựa chọn của người chạy: ai cố tình khai sql_mode riêng (để thử
// một máy chủ cấu hình khác) thì giữ nguyên chuỗi của họ.
func ghimSQLMode(dsn string) string {
	if strings.Contains(dsn, "sql_mode=") {
		return dsn
	}
	noi := "?"
	if strings.Contains(dsn, "?") {
		noi = "&"
	}
	return dsn + noi + config.ThamSoSQLModeThem(config.SQLModeKiemThu)
}

// ctxTest là ctx của cửa hàng số 1 — cửa hàng mà migration 0002 tạo sẵn và mọi
// dữ liệu có từ trước đều thuộc về.
func ctxTest() context.Context { return tenant.WithID(context.Background(), 1) }

// ctxRaw dùng cho SQL viết tay để dựng/dọn dữ liệu test.
//
// Bộ lọc chặn SQL viết tay vì nó không chèn điều kiện vào chuỗi người viết được;
// ở đây thì đó đúng là ý định — mấy câu lệnh dưới tự khai tenant_id hoặc chỉ
// đụng vào dữ liệu do chính test tạo ra.
func ctxRaw() context.Context {
	return tenant.WithoutScope(context.Background(), "test: SQL viết tay dựng/dọn dữ liệu")
}

// seedCategory trả về id một danh mục THUỘC CỬA HÀNG SỐ 1, tạo nếu chưa có.
//
// Mệnh đề tenant_id = 1 là bắt buộc, không phải cho gọn. Bộ test này dùng CHUNG
// database với internal/apitest, mà gói đó dựng hàng chục cửa hàng kèm danh mục
// riêng của từng cửa hàng. Lấy bừa "danh mục đầu tiên trong bảng" thì có lúc vớ
// đúng danh mục của cửa hàng khác — sản phẩm tạo ra vẫn nằm ở cửa hàng 1 nhưng
// trỏ sang danh mục của người ta, và mọi lượt Preload("Category") sau đó trả về
// nil vì bộ lọc tenant cắt đúng dòng ấy.
//
// Hỏng theo kiểu tệ nhất: phụ thuộc vào việc gói nào chạy trước và bảng đang có
// gì, nên nó xanh trên máy này, đỏ trên máy kia, và đỏ ngắt quãng trên CI.
//
// tenant_id cũng phải khai TƯỜNG MINH lúc chèn: từ migration 0003 cột này không
// còn giá trị mặc định, nên câu INSERT viết tay nào quên nó sẽ hỏng ngay tại đây
// thay vì âm thầm rơi vào cửa hàng số 1.
func seedCategory(t *testing.T, db *gorm.DB) uint {
	t.Helper()

	var categoryID uint
	db.WithContext(ctxRaw()).
		Raw("SELECT id FROM categories WHERE tenant_id = 1 AND slug = 'test-cat' LIMIT 1").Scan(&categoryID)
	if categoryID != 0 {
		return categoryID
	}

	if err := db.WithContext(ctxRaw()).Exec(
		"INSERT INTO categories (tenant_id, name, slug, is_active, created_at, updated_at) VALUES (1, 'Test', 'test-cat', 1, NOW(3), NOW(3))",
	).Error; err != nil {
		t.Fatalf("không tạo được danh mục: %v", err)
	}
	db.WithContext(ctxRaw()).
		Raw("SELECT id FROM categories WHERE tenant_id = 1 AND slug = 'test-cat'").Scan(&categoryID)
	if categoryID == 0 {
		t.Fatal("tạo danh mục xong vẫn không đọc lại được id")
	}
	return categoryID
}

// seedProduct tạo một sản phẩm trống để treo biến thể vào, kèm dọn dẹp.
func seedProduct(t *testing.T, db *gorm.DB) uint {
	t.Helper()
	categoryID := seedCategory(t, db)

	p := &domain.Product{
		CategoryID: categoryID,
		Name:       "SP kiểm thử tồn kho",
		Slug:       "sp-kiem-thu-ton-kho",
		SKU:        "TEST-STOCK-SKU",
		BasePrice:  100000,
		// PHẢI khai, dù cột có DEFAULT 'active': GORM đưa mọi trường vào câu
		// INSERT kể cả trường bỏ trống, nên database nhận chuỗi rỗng và không
		// bao giờ chạm tới giá trị mặc định. Mà '' không nằm trong ENUM
		// ('active','hidden','discontinued').
		//
		// MySQL 8 — máy chủ và CI — chạy STRICT_TRANS_TABLES nên nó dừng lại với
		// "Data truncated for column 'status'". MySQL/MariaDB đi kèm XAMPP
		// thường TẮT strict; ở đó cùng câu lệnh chỉ là cảnh báo, hàng vẫn vào,
		// và bài kiểm xanh suốt trên máy phát triển.
		//
		// Muốn máy mình bắt được loại lỗi này thì ép strict ngay trong DSN:
		//   TEST_DB_DSN="...?parseTime=true&sql_mode='STRICT_TRANS_TABLES'"
		Status: domain.ProductStatusActive,
		// Mặt hàng bình thường: bán ra CÓ trừ kho. Bool zero-value là false
		// nên dựng tay mà quên là bài kiểm tồn kho im lặng không trừ gì.
		IsStockDeducted: true,
	}
	db.WithContext(ctxTest()).Where("slug = ?", p.Slug).Unscoped().Delete(&domain.Product{})
	if err := db.WithContext(ctxTest()).Create(p).Error; err != nil {
		t.Fatalf("không tạo được sản phẩm: %v", err)
	}
	t.Cleanup(func() {
		db.WithContext(ctxTest()).Unscoped().Where("product_id = ?", p.ID).Delete(&domain.ProductVariant{})
		db.WithContext(ctxTest()).Unscoped().Delete(&domain.Product{}, p.ID)
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
	ctx := ctxTest()
	productID := seedProduct(t, db)

	// Tạo biến thể lần đầu — tồn phải lấy DEFAULT 0 của DB.
	if err := repo.ReplaceVariants(ctx, productID, []domain.ProductVariant{
		{SKU: "TEST-STOCK-SKU-M", Name: "M", IsActive: true},
	}); err != nil {
		t.Fatalf("tạo biến thể lỗi: %v", err)
	}

	var v domain.ProductVariant
	if err := db.WithContext(ctxTest()).Where("product_id = ? AND name = ?", productID, "M").First(&v).Error; err != nil {
		t.Fatalf("không đọc được biến thể vừa tạo: %v", err)
	}
	if v.StockQuantity != 0 {
		t.Fatalf("biến thể mới phải có tồn 0, nhận %d", v.StockQuantity)
	}

	// Kho nhập 50 cái (mô phỏng nhận hàng từ phiếu nhập).
	if err := db.WithContext(ctxTest()).Model(&domain.ProductVariant{}).Where("id = ?", v.ID).
		UpdateColumn("stock_quantity", 50).Error; err != nil {
		t.Fatalf("không cập nhật được tồn kho: %v", err)
	}

	// Sửa sản phẩm: đổi giá riêng của đúng biến thể đó. Payload không mang tồn
	// kho (StockQuantity là zero-value) — đúng như buildVariants dựng ra.
	gia := 199000.0
	if err := repo.ReplaceVariants(ctx, productID, []domain.ProductVariant{
		{ID: v.ID, SKU: "TEST-STOCK-SKU-M", Name: "M", Price: &gia, IsActive: true},
	}); err != nil {
		t.Fatalf("sửa biến thể lỗi: %v", err)
	}

	var sau domain.ProductVariant
	if err := db.WithContext(ctxTest()).First(&sau, v.ID).Error; err != nil {
		t.Fatalf("không đọc lại được biến thể: %v", err)
	}
	if sau.StockQuantity != 50 {
		t.Fatalf("sửa sản phẩm đã đạp tồn kho: mong 50, nhận %d", sau.StockQuantity)
	}
	if sau.Price == nil || *sau.Price != gia {
		t.Fatalf("giá riêng không được ghi: %v", sau.Price)
	}
}

// Xoá một biến thể rồi thêm lại đúng tên/mã đó phải chạy được. Biến thể bị gỡ chỉ
// bị xoá mềm (sổ kho và giỏ hàng còn trỏ vào), nên unique key phải tính cả
// deleted_at — nếu không thì thêm lại sẽ vướng lỗi trùng khoá.
func TestReplaceVariantsThemLaiBienTheDaXoa(t *testing.T) {
	db := testDB(t)
	repo := NewProductRepository(db)
	ctx := ctxTest()
	productID := seedProduct(t, db)

	if err := repo.ReplaceVariants(ctx, productID, []domain.ProductVariant{
		{SKU: "TEST-STOCK-SKU-L", Name: "L", IsActive: true},
	}); err != nil {
		t.Fatalf("tạo biến thể lỗi: %v", err)
	}

	// Gửi danh sách rỗng -> biến thể L bị xoá mềm.
	if err := repo.ReplaceVariants(ctx, productID, []domain.ProductVariant{}); err != nil {
		t.Fatalf("xoá biến thể lỗi: %v", err)
	}

	// Thêm lại đúng tên + mã đó.
	if err := repo.ReplaceVariants(ctx, productID, []domain.ProductVariant{
		{SKU: "TEST-STOCK-SKU-L", Name: "L", IsActive: true},
	}); err != nil {
		t.Fatalf("thêm lại biến thể đã xoá bị lỗi: %v", err)
	}

	var dem int64
	db.WithContext(ctxTest()).Model(&domain.ProductVariant{}).Where("product_id = ?", productID).Count(&dem)
	if dem != 1 {
		t.Fatalf("phải còn đúng 1 biến thể đang sống, nhận %d", dem)
	}
}
