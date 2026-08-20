package repository

import (
	"testing"

	"gorm.io/gorm"

	"sass-api/internal/domain"
)

// Cần MySQL thật: thứ đang kiểm là ràng buộc UNIQUE và cột sinh deleted_mark của
// bảng products, không phải mã Go. Cách dựng DB test xem product_repository_test.go.

// newProduct tạo một sản phẩm với slug/SKU cho trước, kèm dọn dẹp cứng.
func newProduct(t *testing.T, db *gorm.DB, slug, sku string) *domain.Product {
	t.Helper()
	// Phải là danh mục CỦA CỬA HÀNG SỐ 1 — xem seedCategory. Trước đây chỗ này
	// lấy "danh mục đầu tiên trong bảng" bất kể của ai, nên có lúc gắn sản phẩm
	// vào danh mục của cửa hàng khác và Preload("Category") trả về nil.
	//
	// Cũng bỏ luôn nhánh t.Skip cũ: bảng trống thì bài kiểm lặng lẽ không chạy,
	// bảng kết quả vẫn xanh, và không ai biết nó đã ngừng kiểm từ bao giờ.
	categoryID := seedCategory(t, db)

	db.WithContext(ctxTest()).Unscoped().Where("slug = ? OR sku = ?", slug, sku).Delete(&domain.Product{})
	p := &domain.Product{
		CategoryID: categoryID,
		Name:       "SP kiểm thử " + sku,
		Slug:       slug,
		SKU:        sku,
		BasePrice:  100000,
		Status:     domain.ProductStatusActive,
		IsActive:   true,
		// Mặt hàng bình thường: bán ra CÓ trừ kho. Bool zero-value là false
		// nên dựng tay mà quên là bài kiểm tồn kho im lặng không trừ gì.
		IsStockDeducted: true,
	}
	if err := db.WithContext(ctxTest()).Create(p).Error; err != nil {
		t.Fatalf("không tạo được sản phẩm %s: %v", sku, err)
	}
	t.Cleanup(func() {
		db.WithContext(ctxTest()).Unscoped().Where("product_id = ?", p.ID).Delete(&domain.ProductVariant{})
		db.WithContext(ctxTest()).Unscoped().Delete(&domain.Product{}, p.ID)
	})
	return p
}

// SKU trùng phải bị bắt TRƯỚC khi chạm DB.
//
// Bảng products có UNIQUE trên sku nhưng tầng service trước đây chỉ kiểm tra
// slug, nên lỗi rơi xuống MySQL rồi nhảy vào nhánh mặc định của handler: người
// dùng nhận đúng một câu "Đã có lỗi xảy ra" và không biết phải sửa gì. Mà SKU
// lại tự sinh từ đội bóng · loại áo · mùa giải nên đụng nhau là chuyện thường.
func TestExistsBySKUBatTrungTruocKhiGhi(t *testing.T) {
	db := testDB(t)
	repo := NewProductRepository(db)
	ctx := ctxTest()

	p := newProduct(t, db, "sp-test-sku-goc", "TEST-SKU-GOC")

	exists, err := repo.ExistsBySKU(ctx, "TEST-SKU-GOC", 0)
	if err != nil {
		t.Fatalf("ExistsBySKU lỗi: %v", err)
	}
	if !exists {
		t.Fatal("SKU đang có sản phẩm dùng mà báo là chưa ai dùng")
	}

	// Sửa chính nó thì SKU của nó không được coi là trùng, nếu không sản phẩm
	// nào cũng tự chặn mình mỗi lần bấm Cập nhật.
	exists, err = repo.ExistsBySKU(ctx, "TEST-SKU-GOC", p.ID)
	if err != nil {
		t.Fatalf("ExistsBySKU (loại trừ chính nó) lỗi: %v", err)
	}
	if exists {
		t.Fatal("sản phẩm tự coi SKU của chính mình là trùng")
	}

	exists, err = repo.ExistsBySKU(ctx, "TEST-SKU-CHUA-AI-DUNG", 0)
	if err != nil {
		t.Fatalf("ExistsBySKU (SKU mới) lỗi: %v", err)
	}
	if exists {
		t.Fatal("SKU chưa ai dùng mà báo là đã trùng")
	}
}

// Xoá một sản phẩm rồi tạo lại sản phẩm CÙNG slug và CÙNG SKU phải làm được.
//
// Trước khi có cột deleted_mark, hai UNIQUE key nằm trên cột trần nên dòng đã
// xoá mềm chiếm chỗ vĩnh viễn: người bán xoá nhầm một sản phẩm là không bao giờ
// tạo lại được nó nữa, và lỗi hiện ra chỉ là "Đã có lỗi xảy ra".
func TestXoaRoiTaoLaiCungSlugVaSKU(t *testing.T) {
	db := testDB(t)
	repo := NewProductRepository(db)
	ctx := ctxTest()

	const slug, sku = "sp-test-tao-lai", "TEST-TAO-LAI"
	first := newProduct(t, db, slug, sku)

	if err := repo.Delete(ctx, first.ID); err != nil {
		t.Fatalf("không xoá được sản phẩm: %v", err)
	}

	// Sản phẩm đã xoá mềm KHÔNG được tính là đang chiếm chỗ.
	for _, c := range []struct {
		ten   string
		check func() (bool, error)
	}{
		{"slug", func() (bool, error) { return repo.ExistsBySlug(ctx, slug, 0) }},
		{"SKU", func() (bool, error) { return repo.ExistsBySKU(ctx, sku, 0) }},
	} {
		exists, err := c.check()
		if err != nil {
			t.Fatalf("kiểm tra %s lỗi: %v", c.ten, err)
		}
		if exists {
			t.Fatalf("%s của sản phẩm ĐÃ XOÁ vẫn bị coi là đang chiếm chỗ", c.ten)
		}
	}

	// Và ghi thật xuống DB cũng phải trót lọt — đây mới là chỗ UNIQUE key lên tiếng.
	again := newProduct(t, db, slug, sku)
	if again.ID == first.ID {
		t.Fatal("tạo lại nhưng lại trúng đúng dòng cũ")
	}
}

// SetStatus phải ghi status và is_active cùng lúc.
//
// Hai cột luôn đi đôi: is_active là thứ mọi truy vấn bán hàng/kho/báo cáo đang
// lọc, status là thứ người bán đọc. Lệch nhau một cái là sản phẩm ngừng kinh
// doanh vẫn bày ngoài cửa hàng.
func TestSetStatusDongBoCoHienThi(t *testing.T) {
	db := testDB(t)
	repo := NewProductRepository(db)
	ctx := ctxTest()

	p := newProduct(t, db, "sp-test-trang-thai", "TEST-TRANG-THAI")

	cases := []struct {
		status     string
		wantActive bool
	}{
		{domain.ProductStatusHidden, false},
		{domain.ProductStatusDiscontinued, false},
		{domain.ProductStatusActive, true},
	}
	for _, c := range cases {
		if err := repo.SetStatus(ctx, p.ID, c.status); err != nil {
			t.Fatalf("SetStatus(%s) lỗi: %v", c.status, err)
		}
		got, err := repo.FindByID(ctx, p.ID)
		if err != nil {
			t.Fatalf("đọc lại sản phẩm lỗi: %v", err)
		}
		if got.Status != c.status {
			t.Fatalf("status = %q, mong đợi %q", got.Status, c.status)
		}
		if got.IsActive != c.wantActive {
			t.Fatalf("status %q -> is_active = %v, mong đợi %v", c.status, got.IsActive, c.wantActive)
		}
	}

	// Id không tồn tại phải báo không tìm thấy, không im lặng coi như xong.
	if err := repo.SetStatus(ctx, 0, domain.ProductStatusActive); err != domain.ErrNotFound {
		t.Fatalf("SetStatus cho id không tồn tại trả %v, mong đợi ErrNotFound", err)
	}
}

// DeleteMany xoá cả lô trong một giao dịch và đếm đúng số dòng thực sự xoá.
//
// Trang quản trị cho chọn hàng loạt rồi xoá; trước đây nó lặp gọi API từng cái —
// 50 sản phẩm là 50 lượt HTTP nối đuôi, hỏng giữa chừng thì một nửa đã mất.
func TestDeleteManyXoaCaLo(t *testing.T) {
	db := testDB(t)
	repo := NewProductRepository(db)
	ctx := ctxTest()

	a := newProduct(t, db, "sp-test-xoa-lo-1", "TEST-XOA-LO-1")
	b := newProduct(t, db, "sp-test-xoa-lo-2", "TEST-XOA-LO-2")

	// Kèm một id không tồn tại: nó không được làm hỏng cả lô, chỉ không được đếm.
	deleted, err := repo.DeleteMany(ctx, []uint{a.ID, b.ID, 99999999})
	if err != nil {
		t.Fatalf("DeleteMany lỗi: %v", err)
	}
	if deleted != 2 {
		t.Fatalf("DeleteMany trả %d dòng đã xoá, mong đợi 2", deleted)
	}

	for _, id := range []uint{a.ID, b.ID} {
		if _, err := repo.FindByID(ctx, id); err != domain.ErrNotFound {
			t.Fatalf("sản phẩm %d vẫn còn sau khi xoá lô (err = %v)", id, err)
		}
	}

	// Danh sách rỗng là chuyện bình thường (người dùng bỏ chọn hết), không phải lỗi.
	if deleted, err := repo.DeleteMany(ctx, nil); err != nil || deleted != 0 {
		t.Fatalf("DeleteMany(rỗng) trả (%d, %v), mong đợi (0, nil)", deleted, err)
	}
}

// Slim = true thì KHÔNG nạp kèm biến thể và thư viện ảnh.
//
// Trang danh sách ngoài cửa hàng chỉ cần ảnh đại diện + giá; nạp cả hai thứ kia
// cho 12 sản phẩm là chở về hàng trăm dòng rồi vứt đi.
func TestListSlimKhongNapBienTheVaAnh(t *testing.T) {
	db := testDB(t)
	repo := NewProductRepository(db)
	ctx := ctxTest()

	p := newProduct(t, db, "sp-test-slim", "TEST-SLIM")
	if err := db.WithContext(ctxTest()).Create(&domain.ProductVariant{
		ProductID: p.ID, SKU: "TEST-SLIM-M", Name: "M", IsActive: true,
	}).Error; err != nil {
		t.Fatalf("không tạo được biến thể: %v", err)
	}

	find := func(items []domain.Product) *domain.Product {
		for i := range items {
			if items[i].ID == p.ID {
				return &items[i]
			}
		}
		return nil
	}

	base := domain.ProductFilter{Page: 1, PageSize: 100, IncludeInactive: true}

	full, _, err := repo.List(ctx, base)
	if err != nil {
		t.Fatalf("List (đầy đủ) lỗi: %v", err)
	}
	got := find(full)
	if got == nil {
		t.Fatal("không thấy sản phẩm vừa tạo trong danh sách đầy đủ")
	}
	if len(got.Variants) == 0 {
		t.Fatal("List thường phải nạp kèm biến thể")
	}

	slim := base
	slim.Slim = true
	items, _, err := repo.List(ctx, slim)
	if err != nil {
		t.Fatalf("List (slim) lỗi: %v", err)
	}
	got = find(items)
	if got == nil {
		t.Fatal("không thấy sản phẩm vừa tạo trong danh sách slim")
	}
	if len(got.Variants) != 0 || len(got.Images) != 0 {
		t.Fatalf("slim vẫn nạp kèm %d biến thể / %d ảnh", len(got.Variants), len(got.Images))
	}
	// Danh mục & thương hiệu thì VẪN phải có: thẻ sản phẩm hiển thị chúng và
	// tầng khuyến mãi cần để biết chương trình có áp cho sản phẩm này không.
	if got.Category == nil {
		t.Fatal("slim cắt mất cả danh mục — thẻ sản phẩm và tầng khuyến mãi đều cần")
	}
}

// Lọc theo trạng thái phải tách được "tạm ẩn" khỏi "ngừng kinh doanh" — hai thứ
// trước đây trông giống hệt nhau vì cùng là is_active = 0.
func TestListLocTheoTrangThai(t *testing.T) {
	db := testDB(t)
	repo := NewProductRepository(db)
	ctx := ctxTest()

	hidden := newProduct(t, db, "sp-test-loc-am", "TEST-LOC-AN")
	stopped := newProduct(t, db, "sp-test-loc-ngung", "TEST-LOC-NGUNG")
	if err := repo.SetStatus(ctx, hidden.ID, domain.ProductStatusHidden); err != nil {
		t.Fatalf("SetStatus lỗi: %v", err)
	}
	if err := repo.SetStatus(ctx, stopped.ID, domain.ProductStatusDiscontinued); err != nil {
		t.Fatalf("SetStatus lỗi: %v", err)
	}

	has := func(items []domain.Product, id uint) bool {
		for i := range items {
			if items[i].ID == id {
				return true
			}
		}
		return false
	}

	items, _, err := repo.List(ctx, domain.ProductFilter{
		Page: 1, PageSize: 100, IncludeInactive: true, Status: domain.ProductStatusHidden,
	})
	if err != nil {
		t.Fatalf("List lỗi: %v", err)
	}
	if !has(items, hidden.ID) {
		t.Fatal("lọc 'tạm ẩn' không ra sản phẩm đang tạm ẩn")
	}
	if has(items, stopped.ID) {
		t.Fatal("lọc 'tạm ẩn' lại lẫn cả sản phẩm ngừng kinh doanh")
	}
}

// Sửa sản phẩm KHÔNG được đụng tới created_at của biến thể và ảnh.
//
// Dữ liệu dựng từ request không mang created_at, mà GORM Save() ghi MỌI cột nên
// nó đẩy '0000-00-00' vào ngày tạo của dòng cũ. Máy chủ thật bật
// STRICT_TRANS_TABLES nên trả lỗi 1292 và TOÀN BỘ lệnh sửa sản phẩm hỏng — người
// bán chỉ thấy "Đã có lỗi xảy ra". Máy dễ tính hơn thì nuốt, và ngày tạo bị xoá
// trắng lúc nào không hay.
//
// Test tự bật chế độ nghiêm ngặt cho phiên của mình để bắt được lỗi kể cả khi
// MySQL trên máy dev đang để lỏng.
func TestSuaSanPhamKhongDapNgayTaoCuaBienTheVaAnh(t *testing.T) {
	db := testDB(t)
	if err := db.WithContext(ctxRaw()).Exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE'").Error; err != nil {
		t.Fatalf("không đặt được sql_mode nghiêm ngặt: %v", err)
	}
	repo := NewProductRepository(db)
	ctx := ctxTest()
	p := newProduct(t, db, "sp-test-ngay-tao", "TEST-NGAY-TAO")

	// Lần 1: tạo biến thể + ảnh.
	if err := repo.ReplaceVariants(ctx, p.ID, []domain.ProductVariant{
		{SKU: "TEST-NGAY-TAO-M", Name: "M", IsActive: true},
	}); err != nil {
		t.Fatalf("tạo biến thể lỗi: %v", err)
	}
	if err := repo.ReplaceImages(ctx, p.ID, []domain.ProductImage{
		{URL: "https://vi.du/anh-1.jpg", SortOrder: 0, IsPrimary: true},
	}); err != nil {
		t.Fatalf("tạo ảnh lỗi: %v", err)
	}

	got, err := repo.FindByID(ctx, p.ID)
	if err != nil || len(got.Variants) != 1 || len(got.Images) != 1 {
		t.Fatalf("đọc lại sau khi tạo lỗi: %v", err)
	}
	variantID, imageID := got.Variants[0].ID, got.Images[0].ID
	variantCreated, imageCreated := got.Variants[0].CreatedAt, got.Images[0].CreatedAt
	if variantCreated.IsZero() || imageCreated.IsZero() {
		t.Fatal("dòng mới tạo mà created_at đã rỗng")
	}

	// Lần 2: sửa như trang quản trị gửi lên — có id, không có created_at.
	if err := repo.ReplaceVariants(ctx, p.ID, []domain.ProductVariant{
		{ID: variantID, SKU: "TEST-NGAY-TAO-M", Name: "M · Trắng", IsActive: true},
	}); err != nil {
		t.Fatalf("sửa biến thể lỗi (đây chính là lỗi 500 ngoài trang thật): %v", err)
	}
	if err := repo.ReplaceImages(ctx, p.ID, []domain.ProductImage{
		{ID: imageID, URL: "https://vi.du/anh-1-sua.jpg", SortOrder: 2, IsPrimary: true},
	}); err != nil {
		t.Fatalf("sửa ảnh lỗi: %v", err)
	}

	got, err = repo.FindByID(ctx, p.ID)
	if err != nil {
		t.Fatalf("đọc lại sau khi sửa lỗi: %v", err)
	}
	if !got.Variants[0].CreatedAt.Equal(variantCreated) {
		t.Fatalf("created_at của biến thể bị đổi: %v -> %v", variantCreated, got.Variants[0].CreatedAt)
	}
	if !got.Images[0].CreatedAt.Equal(imageCreated) {
		t.Fatalf("created_at của ảnh bị đổi: %v -> %v", imageCreated, got.Images[0].CreatedAt)
	}

	// Dữ liệu sửa vẫn phải lưu được — đừng chữa lỗi bằng cách bỏ luôn phần ghi.
	if got.Variants[0].Name != "M · Trắng" {
		t.Fatalf("tên biến thể không lưu: %q", got.Variants[0].Name)
	}
	if got.Images[0].SortOrder != 2 || got.Images[0].URL != "https://vi.du/anh-1-sua.jpg" {
		t.Fatalf("ảnh không lưu: sort=%d url=%s", got.Images[0].SortOrder, got.Images[0].URL)
	}
}

// Gỡ giá riêng / xoá tên biến thể / tắt biến thể đều phải lưu được.
//
// Updates() với struct bỏ qua mọi trường zero-value, nên nếu ai đó đổi map sang
// struct thì xoá tên hay gỡ giá riêng sẽ im lặng không có tác dụng.
func TestSuaBienTheLuuDuocGiaTriRong(t *testing.T) {
	db := testDB(t)
	repo := NewProductRepository(db)
	ctx := ctxTest()
	p := newProduct(t, db, "sp-test-gia-tri-rong", "TEST-GIATRI-RONG")

	gia := 650000.0
	if err := repo.ReplaceVariants(ctx, p.ID, []domain.ProductVariant{
		{SKU: "TEST-GIATRI-RONG-L", Name: "L · Đỏ", Price: &gia, IsActive: true},
	}); err != nil {
		t.Fatalf("tạo biến thể lỗi: %v", err)
	}
	got, _ := repo.FindByID(ctx, p.ID)
	id := got.Variants[0].ID

	// Xoá tên biến thể, gỡ giá riêng, tắt biến thể — cả ba đều là zero-value.
	if err := repo.ReplaceVariants(ctx, p.ID, []domain.ProductVariant{
		{ID: id, SKU: "TEST-GIATRI-RONG-L", Name: "", Price: nil, IsActive: false},
	}); err != nil {
		t.Fatalf("sửa biến thể lỗi: %v", err)
	}

	got, _ = repo.FindByID(ctx, p.ID)
	v := got.Variants[0]
	if v.Name != "" {
		t.Fatalf("xoá tên biến thể không ăn: %q", v.Name)
	}
	if v.Price != nil {
		t.Fatalf("gỡ giá riêng không ăn: %v", *v.Price)
	}
	if v.IsActive {
		t.Fatal("tắt biến thể không ăn")
	}
}
