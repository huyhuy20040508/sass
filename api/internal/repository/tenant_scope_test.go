package repository

import (
	"context"
	"errors"
	"strings"
	"testing"

	"gorm.io/gorm"

	"sass-api/internal/domain"
	"sass-api/internal/tenant"
)

// Bài kiểm tra của RANH GIỚI GIỮA HAI KHÁCH HÀNG.
//
// Phần lớn chạy trên MySQL THẬT chứ không mock, vì thứ đang kiểm chính là SQL mà
// GORM sinh ra. Mock lại thì test xanh kể cả khi câu lệnh thiếu mất `WHERE
// tenant_id` — đúng cái lỗi mà cả tệp tenant_scope.go sinh ra để chặn.
//
// Cách chạy: xem chú thích ở product_repository_test.go (TEST_DB_DSN).

// ---------- phần không cần database ----------

func TestCleanTableName(t *testing.T) {
	cases := map[string]string{
		"`orders`":                 "orders",
		"orders o":                 "orders",
		"order_return_items AS ri": "order_return_items",
		"`sell`.`orders`":          "orders",
		"products":                 "products",
	}
	for input, want := range cases {
		if got := cleanTableName(input); got != want {
			t.Errorf("cleanTableName(%q) = %q, mong đợi %q", input, got, want)
		}
	}
}

// ---------- phần chạy trên MySQL thật ----------

// hangHoa dựng một danh mục để làm dữ liệu thử. Chọn categories vì nó là bảng
// đơn giản nhất có tenant_id mà không dây dưa sang bảng khác.
func hangHoa(slug string) *domain.Category {
	return &domain.Category{Name: "Thử " + slug, Slug: slug, IsActive: true}
}

// haiCuaHang dựng hai cửa hàng và trả về ctx của từng bên, kèm dọn dẹp.
//
// Mọi dòng dữ liệu test đều mang slug bắt đầu bằng tienTo nên dọn được sạch mà
// không đụng tới dữ liệu sẵn có trong database test.
func haiCuaHang(t *testing.T, db *gorm.DB, tienTo string) (motCtx, haiCtx context.Context) {
	t.Helper()

	// tenants là bảng toàn cục (không có cột tenant_id) nên ghi được mà không cần
	// ctx nào — chính là điều test này cũng đang kiểm.
	nen := context.Background()
	for _, id := range []uint{1, 2} {
		var co int64
		if err := db.WithContext(nen).Model(&domain.Tenant{}).Where("id = ?", id).Count(&co).Error; err != nil {
			t.Fatalf("không đếm được cửa hàng: %v", err)
		}
		if co > 0 {
			continue
		}
		row := &domain.Tenant{ID: id, Code: "test-shop-" + string(rune('0'+id)), Name: "Cửa hàng thử", Status: domain.TenantActive}
		if err := db.WithContext(nen).Create(row).Error; err != nil {
			t.Fatalf("không tạo được cửa hàng %d: %v", id, err)
		}
	}

	motCtx = tenant.WithID(context.Background(), 1)
	haiCtx = tenant.WithID(context.Background(), 2)

	don := func() {
		for _, ctx := range []context.Context{motCtx, haiCtx} {
			db.WithContext(ctx).Unscoped().Where("slug LIKE ?", tienTo+"%").Delete(&domain.Category{})
		}
	}
	don()
	t.Cleanup(don)

	return motCtx, haiCtx
}

// Đây là bài kiểm tra quan trọng nhất của cả tệp: dữ liệu của cửa hàng này KHÔNG
// được lọt sang câu truy vấn của cửa hàng kia, kể cả khi người gọi cầm sẵn id.
func TestLocTenant_KhongDocDuocDuLieuCuaCuaHangKhac(t *testing.T) {
	db := testDB(t)
	mot, hai := haiCuaHang(t, db, "loc-doc-")

	cuaMot := hangHoa("loc-doc-mot")
	if err := db.WithContext(mot).Create(cuaMot).Error; err != nil {
		t.Fatalf("cửa hàng 1 không tạo được thương hiệu: %v", err)
	}
	cuaHai := hangHoa("loc-doc-hai")
	if err := db.WithContext(hai).Create(cuaHai).Error; err != nil {
		t.Fatalf("cửa hàng 2 không tạo được thương hiệu: %v", err)
	}

	// Danh sách: đường đi bình thường.
	var ds []domain.Category
	if err := db.WithContext(mot).Where("slug LIKE ?", "loc-doc-%").Find(&ds).Error; err != nil {
		t.Fatalf("đọc danh sách lỗi: %v", err)
	}
	if len(ds) != 1 || ds[0].ID != cuaMot.ID {
		t.Fatalf("cửa hàng 1 phải thấy đúng 1 thương hiệu của mình, nhận %d dòng", len(ds))
	}

	// Đọc thẳng bằng id của người khác: ĐÂY MỚI LÀ ĐƯỜNG ĐI LỆCH. Sửa id trên URL
	// là làm được, và không có bộ lọc thì nó trả về dữ liệu thật.
	var lom domain.Category
	err := db.WithContext(mot).First(&lom, cuaHai.ID).Error
	if !errors.Is(err, gorm.ErrRecordNotFound) {
		t.Fatalf("đọc bằng id của cửa hàng khác phải là 'không tìm thấy', nhận: %v (id %d)", err, lom.ID)
	}

	// Đếm cũng phải bị lọc — nếu không thì con số trên trang tổng quan là tổng của
	// mọi khách hàng.
	var n int64
	if err := db.WithContext(mot).Model(&domain.Category{}).Where("slug LIKE ?", "loc-doc-%").Count(&n).Error; err != nil {
		t.Fatalf("đếm lỗi: %v", err)
	}
	if n != 1 {
		t.Fatalf("đếm phải ra 1, nhận %d", n)
	}
}

// INSERT phải mang tenant của ctx, và phải GHI ĐÈ giá trị người gọi đặt sẵn trong
// struct: tenant là chuyện của danh tính request, không phải của dữ liệu gửi lên.
func TestLocTenant_GhiTenantVaoDongMoi(t *testing.T) {
	db := testDB(t)
	mot, _ := haiCuaHang(t, db, "loc-ghi-")

	b := hangHoa("loc-ghi-mot")
	b.TenantID = 2 // người gọi cố tình khai sai
	if err := db.WithContext(mot).Create(b).Error; err != nil {
		t.Fatalf("tạo lỗi: %v", err)
	}
	if b.TenantID != 1 {
		t.Fatalf("phải ghi đè tenant theo ctx, nhận %d", b.TenantID)
	}

	var doc domain.Category
	if err := db.WithContext(mot).First(&doc, b.ID).Error; err != nil {
		t.Fatalf("đọc lại lỗi: %v", err)
	}
	if doc.TenantID != 1 {
		t.Fatalf("dòng dưới database phải thuộc cửa hàng 1, nhận %d", doc.TenantID)
	}
}

// Ghi cũng phải bị chặn, không chỉ đọc: sửa và xoá bằng id của người khác đều
// không được chạm tới dòng nào.
func TestLocTenant_KhongSuaXoaDuocDuLieuCuaCuaHangKhac(t *testing.T) {
	db := testDB(t)
	mot, hai := haiCuaHang(t, db, "loc-ghi-cheo-")

	cuaHai := hangHoa("loc-ghi-cheo-hai")
	if err := db.WithContext(hai).Create(cuaHai).Error; err != nil {
		t.Fatalf("tạo lỗi: %v", err)
	}

	res := db.WithContext(mot).Model(&domain.Category{}).Where("id = ?", cuaHai.ID).
		Update("name", "bị người khác sửa")
	if res.Error != nil {
		t.Fatalf("lệnh sửa lỗi: %v", res.Error)
	}
	if res.RowsAffected != 0 {
		t.Fatalf("không được sửa dòng của cửa hàng khác, đã sửa %d dòng", res.RowsAffected)
	}

	res = db.WithContext(mot).Where("id = ?", cuaHai.ID).Delete(&domain.Category{})
	if res.Error != nil {
		t.Fatalf("lệnh xoá lỗi: %v", res.Error)
	}
	if res.RowsAffected != 0 {
		t.Fatalf("không được xoá dòng của cửa hàng khác, đã xoá %d dòng", res.RowsAffected)
	}

	// Bên chủ vẫn phải đọc được nguyên vẹn.
	var doc domain.Category
	if err := db.WithContext(hai).First(&doc, cuaHai.ID).Error; err != nil {
		t.Fatalf("chủ dòng phải đọc được: %v", err)
	}
	if doc.Name != cuaHai.Name {
		t.Fatalf("tên đã bị sửa: %q", doc.Name)
	}
}

// ctx không nói được đang phục vụ cửa hàng nào thì câu truy vấn phải HỎNG.
//
// Đây là chỗ khác biệt lớn nhất so với cách làm "quên thì thôi": một chỗ sót
// thành lỗi nhìn thấy ngay, thay vì thành một trang lặng lẽ trả dữ liệu của mọi
// khách hàng.
func TestLocTenant_ThieuTenantThiHong(t *testing.T) {
	db := testDB(t)

	var ds []domain.Category
	err := db.WithContext(context.Background()).Find(&ds).Error
	if !errors.Is(err, ErrNoTenant) {
		t.Fatalf("thiếu tenant phải trả ErrNoTenant, nhận: %v", err)
	}

	err = db.WithContext(context.Background()).Create(hangHoa("khong-bao-gio-ghi")).Error
	if !errors.Is(err, ErrNoTenant) {
		t.Fatalf("INSERT thiếu tenant phải trả ErrNoTenant, nhận: %v", err)
	}
}

// Truy vấn có BÍ DANH bảng vẫn phải lọc đúng. Statement.Table lúc đó là bí danh
// chứ không phải tên bảng, nên đây là chỗ dễ viết sai nhất trong bộ lọc.
func TestLocTenant_LocDungKhiBangCoBiDanh(t *testing.T) {
	db := testDB(t)
	mot, hai := haiCuaHang(t, db, "loc-bidanh-")

	if err := db.WithContext(mot).Create(hangHoa("loc-bidanh-mot")).Error; err != nil {
		t.Fatalf("tạo lỗi: %v", err)
	}
	if err := db.WithContext(hai).Create(hangHoa("loc-bidanh-hai")).Error; err != nil {
		t.Fatalf("tạo lỗi: %v", err)
	}

	var ten []string
	err := db.WithContext(mot).Table("categories b").
		Select("b.name").
		Where("b.slug LIKE ?", "loc-bidanh-%").
		Scan(&ten).Error
	if err != nil {
		t.Fatalf("truy vấn có bí danh lỗi: %v", err)
	}
	if len(ten) != 1 {
		t.Fatalf("phải ra đúng 1 dòng của cửa hàng 1, nhận %d", len(ten))
	}
}

// Bảng toàn cục (roles) đọc được mà không cần tenant — nếu không thì chính luồng
// đăng nhập cũng không chạy nổi.
func TestLocTenant_BangToanCucKhongCanTenant(t *testing.T) {
	db := testDB(t)

	var n int64
	if err := db.WithContext(context.Background()).Model(&domain.Role{}).Count(&n).Error; err != nil {
		t.Fatalf("đọc bảng roles không cần tenant, nhận lỗi: %v", err)
	}
}

// SQL viết tay không có chỗ để chèn điều kiện, nên bị chặn — trừ khi người viết
// tự khai lý do bằng tenant.WithoutScope.
func TestLocTenant_ChanSQLVietTay(t *testing.T) {
	db := testDB(t)
	mot, _ := haiCuaHang(t, db, "loc-raw-")

	var n int64
	err := db.WithContext(mot).Raw("SELECT COUNT(*) FROM categories").Scan(&n).Error
	if !errors.Is(err, ErrRawSQL) {
		t.Fatalf("SQL viết tay phải bị chặn, nhận: %v", err)
	}

	boQua := tenant.WithoutScope(context.Background(), "test: đếm toàn bảng có chủ ý")
	if err := db.WithContext(boQua).Raw("SELECT COUNT(*) FROM categories WHERE tenant_id = 1").Scan(&n).Error; err != nil {
		t.Fatalf("khai lý do rồi thì phải chạy được: %v", err)
	}
}

// Lưới an toàn của GORM phải còn nguyên: UPDATE/DELETE không có điều kiện nào
// vẫn phải hỏng, chứ không được biến thành "xoá sạch bảng của một cửa hàng" chỉ
// vì bộ lọc đã thêm một mệnh đề WHERE vào hộ.
func TestLocTenant_VanChanLenhGhiKhongDieuKien(t *testing.T) {
	db := testDB(t)
	mot, _ := haiCuaHang(t, db, "loc-luoi-")

	err := db.WithContext(mot).Model(&domain.Category{}).Update("name", "đổi hết").Error
	if !errors.Is(err, gorm.ErrMissingWhereClause) {
		t.Fatalf("UPDATE không điều kiện phải trả ErrMissingWhereClause, nhận: %v", err)
	}

	err = db.WithContext(mot).Delete(&domain.Category{}).Error
	if !errors.Is(err, gorm.ErrMissingWhereClause) {
		t.Fatalf("DELETE không điều kiện phải trả ErrMissingWhereClause, nhận: %v", err)
	}
}

// Save(&x) và Delete(&x) vẫn phải chạy được: điều kiện khoá chính của chúng do
// GORM thêm vào SAU bộ lọc, nên lưới an toàn ở trên rất dễ chặn nhầm.
func TestLocTenant_SaveVaDeleteTheoBanGhiVanChay(t *testing.T) {
	db := testDB(t)
	mot, _ := haiCuaHang(t, db, "loc-save-")

	b := hangHoa("loc-save-mot")
	if err := db.WithContext(mot).Create(b).Error; err != nil {
		t.Fatalf("tạo lỗi: %v", err)
	}

	b.Name = "Đã đổi tên"
	if err := db.WithContext(mot).Save(b).Error; err != nil {
		t.Fatalf("Save theo bản ghi phải chạy được: %v", err)
	}

	var doc domain.Category
	if err := db.WithContext(mot).First(&doc, b.ID).Error; err != nil {
		t.Fatalf("đọc lại lỗi: %v", err)
	}
	if doc.Name != "Đã đổi tên" {
		t.Fatalf("Save không ghi được tên mới, nhận %q", doc.Name)
	}
	// Save KHÔNG được đổi tenant của dòng đã có — cột khai `<-:create`.
	if doc.TenantID != 1 {
		t.Fatalf("tenant của dòng cũ bị đổi thành %d", doc.TenantID)
	}

	if err := db.WithContext(mot).Delete(b).Error; err != nil {
		t.Fatalf("Delete theo bản ghi phải chạy được: %v", err)
	}
}

// Save() với TenantID bị sửa trong struct KHÔNG được chuyển dòng sang cửa hàng
// khác — đó là đường rò khó thấy nhất, vì nó trông y hệt một lần lưu bình thường.
func TestLocTenant_SaveKhongChuyenDuocSangCuaHangKhac(t *testing.T) {
	db := testDB(t)
	mot, hai := haiCuaHang(t, db, "loc-chuyen-")

	b := hangHoa("loc-chuyen-mot")
	if err := db.WithContext(mot).Create(b).Error; err != nil {
		t.Fatalf("tạo lỗi: %v", err)
	}

	b.TenantID = 2
	if err := db.WithContext(mot).Save(b).Error; err != nil {
		t.Fatalf("Save lỗi: %v", err)
	}

	var beoSang domain.Category
	if err := db.WithContext(hai).First(&beoSang, b.ID).Error; !errors.Is(err, gorm.ErrRecordNotFound) {
		t.Fatalf("dòng đã chuyển sang cửa hàng 2 — đây là rò dữ liệu (err=%v)", err)
	}
}

// Xoá mềm rồi đọc lại bằng Unscoped vẫn phải bị lọc: Unscoped chỉ bỏ điều kiện
// deleted_at, không được bỏ ranh giới khách hàng.
func TestLocTenant_UnscopedVanBiLoc(t *testing.T) {
	db := testDB(t)
	mot, hai := haiCuaHang(t, db, "loc-unscoped-")

	cuaHai := hangHoa("loc-unscoped-hai")
	if err := db.WithContext(hai).Create(cuaHai).Error; err != nil {
		t.Fatalf("tạo lỗi: %v", err)
	}
	if err := db.WithContext(hai).Delete(cuaHai).Error; err != nil {
		t.Fatalf("xoá mềm lỗi: %v", err)
	}

	var ds []domain.Category
	if err := db.WithContext(mot).Unscoped().Where("slug LIKE ?", "loc-unscoped-%").Find(&ds).Error; err != nil {
		t.Fatalf("truy vấn lỗi: %v", err)
	}
	if len(ds) != 0 {
		t.Fatalf("Unscoped không được nhìn thấy dòng của cửa hàng khác, nhận %d dòng", len(ds))
	}
}

// Giao dịch (Transaction) phải giữ nguyên tenant của ctx: các repository ghi đơn
// hàng đều làm việc bên trong tx, sót chỗ này là sót gần hết phần ghi dữ liệu.
func TestLocTenant_TrongGiaoDichVanLoc(t *testing.T) {
	db := testDB(t)
	mot, hai := haiCuaHang(t, db, "loc-tx-")

	cuaHai := hangHoa("loc-tx-hai")
	if err := db.WithContext(hai).Create(cuaHai).Error; err != nil {
		t.Fatalf("tạo lỗi: %v", err)
	}

	err := db.WithContext(mot).Transaction(func(tx *gorm.DB) error {
		if err := tx.Create(hangHoa("loc-tx-mot")).Error; err != nil {
			return err
		}

		var lom domain.Category

		return tx.First(&lom, cuaHai.ID).Error
	})
	if !errors.Is(err, gorm.ErrRecordNotFound) {
		t.Fatalf("trong giao dịch vẫn phải lọc theo cửa hàng, nhận: %v", err)
	}

	// Giao dịch trên đã cuộn lại nên dòng vừa tạo không được còn.
	var n int64
	if err := db.WithContext(mot).Model(&domain.Category{}).Where("slug = ?", "loc-tx-mot").Count(&n).Error; err != nil {
		t.Fatalf("đếm lỗi: %v", err)
	}
	if n != 0 {
		t.Fatalf("giao dịch lỗi phải cuộn lại, còn %d dòng", n)
	}
}

// Ghi nhiều dòng một lần cũng phải được đóng dấu tenant từng dòng.
func TestLocTenant_GhiNhieuDongCungDuocDongDau(t *testing.T) {
	db := testDB(t)
	mot, _ := haiCuaHang(t, db, "loc-lo-")

	lo := []domain.Category{*hangHoa("loc-lo-a"), *hangHoa("loc-lo-b")}
	if err := db.WithContext(mot).Create(&lo).Error; err != nil {
		t.Fatalf("ghi lô lỗi: %v", err)
	}
	for i, b := range lo {
		if b.TenantID != 1 {
			t.Fatalf("dòng thứ %d thiếu tenant, nhận %d", i, b.TenantID)
		}
	}
}

// Preload chạy bằng MỘT CÂU TRUY VẤN RIÊNG, nên nó là chỗ rất dễ lọt: bản ghi
// cha đã lọc đúng rồi mà quan hệ con thì không.
//
// Cảnh dựng ở đây là cảnh xấu nhất có thể: một biến thể của cửa hàng 2 trỏ vào
// sản phẩm của cửa hàng 1. Khoá ngoại không cấm điều đó (product_variants chỉ
// ràng buộc product_id tồn tại), nên chỉ còn bộ lọc đứng giữa.
func TestLocTenant_PreloadCungBiLoc(t *testing.T) {
	db := testDB(t)
	mot, hai := haiCuaHang(t, db, "loc-preload-")

	danhMuc := &domain.Category{Name: "Preload", Slug: "loc-preload-cat", IsActive: true}
	if err := db.WithContext(mot).Create(danhMuc).Error; err != nil {
		t.Fatalf("tạo danh mục lỗi: %v", err)
	}
	sp := &domain.Product{
		CategoryID: danhMuc.ID,
		Name:       "SP preload", Slug: "loc-preload-sp", SKU: "LOC-PRELOAD-SP",
		KitType: "fan", BasePrice: 1000,
		// Bắt buộc dưới MySQL strict — xem chú thích ở seedProduct
		// (product_repository_test.go).
		Status: domain.ProductStatusActive,
	}
	if err := db.WithContext(mot).Create(sp).Error; err != nil {
		t.Fatalf("tạo sản phẩm lỗi: %v", err)
	}
	t.Cleanup(func() {
		for _, ctx := range []context.Context{mot, hai} {
			db.WithContext(ctx).Unscoped().Where("product_id = ?", sp.ID).Delete(&domain.ProductVariant{})
		}
		db.WithContext(mot).Unscoped().Delete(&domain.Product{}, sp.ID)
		db.WithContext(mot).Unscoped().Delete(&domain.Category{}, danhMuc.ID)
	})

	cuaMot := &domain.ProductVariant{ProductID: sp.ID, SKU: "LOC-PRELOAD-M", Size: "M", IsActive: true}
	if err := db.WithContext(mot).Create(cuaMot).Error; err != nil {
		t.Fatalf("tạo biến thể của cửa hàng 1 lỗi: %v", err)
	}
	cuaHai := &domain.ProductVariant{ProductID: sp.ID, SKU: "LOC-PRELOAD-L", Size: "L", IsActive: true}
	if err := db.WithContext(hai).Create(cuaHai).Error; err != nil {
		t.Fatalf("tạo biến thể của cửa hàng 2 lỗi: %v", err)
	}

	var doc domain.Product
	if err := db.WithContext(mot).Preload("Variants").First(&doc, sp.ID).Error; err != nil {
		t.Fatalf("đọc sản phẩm lỗi: %v", err)
	}
	if len(doc.Variants) != 1 || doc.Variants[0].ID != cuaMot.ID {
		t.Fatalf("Preload phải chỉ trả biến thể của cửa hàng 1, nhận %d dòng", len(doc.Variants))
	}
}

// globalTables phải khớp với LƯỢC ĐỒ THẬT, cả hai chiều.
//
// Thiếu một bảng trong danh sách mà bảng đó không có cột tenant_id thì mọi truy
// vấn vào nó hỏng — khó chịu nhưng nhìn thấy ngay. Chiều ngược lại mới nguy: một
// bảng CÓ tenant_id mà lỡ khai là toàn cục thì nó không bao giờ bị lọc, và không
// có gì báo động cả. Bài này đọc thẳng information_schema nên nó đúng theo
// database, không theo trí nhớ của ai.
func TestLocTenant_DanhSachBangToanCucKhopLuocDo(t *testing.T) {
	db := testDB(t)
	boQua := tenant.WithoutScope(context.Background(), "test: soát lược đồ qua information_schema")

	var bang []struct {
		TableName string
		CoTenant  int
	}
	err := db.WithContext(boQua).Raw(`
SELECT t.table_name AS table_name,
       (SELECT COUNT(*) FROM information_schema.columns c
         WHERE c.table_schema = t.table_schema
           AND c.table_name = t.table_name
           AND c.column_name = 'tenant_id') AS co_tenant
  FROM information_schema.tables t
 WHERE t.table_schema = DATABASE() AND t.table_type = 'BASE TABLE'`).Scan(&bang).Error
	if err != nil {
		t.Fatalf("không đọc được lược đồ: %v", err)
	}
	if len(bang) < 30 {
		t.Fatalf("chỉ thấy %d bảng — database test có vẻ chưa chạy migration", len(bang))
	}

	for _, b := range bang {
		ten := strings.ToLower(b.TableName)
		switch {
		case b.CoTenant > 0 && globalTables[ten]:
			t.Errorf("bảng %s CÓ cột tenant_id nhưng đang khai là toàn cục — dữ liệu của nó "+
				"không bao giờ bị lọc theo cửa hàng", ten)
		case b.CoTenant == 0 && !globalTables[ten]:
			t.Errorf("bảng %s KHÔNG có cột tenant_id và cũng không khai trong globalTables — "+
				"mọi truy vấn vào nó sẽ hỏng vì tham chiếu cột không tồn tại", ten)
		}
	}
}

// Bảng nào thuộc tenant mà entity quên nhúng domain.TenantOwned thì INSERT phải
// hỏng ngay, chứ không được ghi ra một dòng thiếu tenant_id.
func TestLocTenant_EntityQuenNhungThiHong(t *testing.T) {
	db := testDB(t)
	mot, _ := haiCuaHang(t, db, "loc-quen-")

	// Bản sao của Category nhưng CỐ Ý không nhúng TenantOwned — mô phỏng đúng lỗi
	// "thêm bảng mới rồi quên".
	type danhMucQuenTenant struct {
		ID       uint `gorm:"primaryKey"`
		Name     string
		Slug     string
		IsActive bool
	}

	err := db.WithContext(mot).Table("categories").
		Create(&danhMucQuenTenant{Name: "Quên", Slug: "loc-quen-a", IsActive: true}).Error
	if !errors.Is(err, ErrNoTenantColumn) {
		t.Fatalf("entity thiếu TenantOwned phải trả ErrNoTenantColumn, nhận: %v", err)
	}
	if err != nil && !strings.Contains(err.Error(), "categories") {
		t.Errorf("thông báo lỗi nên chỉ rõ bảng nào, nhận: %v", err)
	}
}
