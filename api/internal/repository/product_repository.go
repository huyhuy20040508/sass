package repository

import (
	"context"
	"errors"
	"fmt"
	"slices"
	"sort"
	"strings"

	"sass-api/internal/chinhanh"
	"sass-api/internal/domain"

	"gorm.io/gorm"
	"gorm.io/gorm/clause"
)

// groupNameExpr lấy TÊN nhóm hàng của một mặt hàng để sắp xếp.
//
// Truy vấn con thay vì JOIN: câu List đã đếm tổng bằng chính query này, thêm JOIN
// vào là mặt hàng thuộc nhóm đã xoá mềm biến mất khỏi cả danh sách lẫn con số tổng.
const groupNameExpr = "(SELECT c.name FROM categories c WHERE c.id = products.category_id)"

type productRepository struct{ db *gorm.DB }

// tongTonExpr cộng tồn của MỌI chi nhánh cho một biến thể.
//
// Thay cho cột `product_variants.stock_quantity` đã bỏ: giữ một bản cộng trong
// bảng nghĩa là hai chỗ cùng giữ một sự thật, và cái lệch giữa chúng chỉ lộ ra
// lúc có người đếm hàng thật. Cộng ngay trong câu truy vấn thì không có gì để
// lệch — đổi lại là một truy vấn con, và nó đi qua chỉ mục
// uq_variant_stocks_shop_variant nên rẻ.
//
// Dùng cho những màn hình chỉ hỏi "cả cửa hàng còn bao nhiêu". Màn kho hỏi
// "nằm ở đâu" thì đọc thẳng variant_stocks theo từng chi nhánh.
const tongTonExpr = `COALESCE((SELECT SUM(vs.quantity) FROM variant_stocks vs
	WHERE vs.product_variant_id = product_variants.id), 0) AS stock_quantity`

// tonExprSanPham chọn biểu thức tồn theo ctx: ĐANG ĐỨNG Ở MỘT CHI NHÁNH thì trả
// tồn của riêng kho đó, không thì trả bản cộng cả cửa hàng.
//
// Vì sao phải theo chi nhánh: cột "Tồn kho" của màn Hàng hoá trước đây luôn là
// tổng mọi chi nhánh, nên người đứng ở quầy Quận 7 (kho còn 3) vẫn đọc ra 40 —
// con số của cả chuỗi. Họ nhận đơn 10 cái, rồi lượt trừ kho từ chối vì kho ấy
// chỉ có 3, sau khi khách đã trả tiền. Cùng một lỗi mà tonCuaChiNhanh đã chữa
// bên đường bán hàng; đây là chỗ còn sót.
//
// Gian hàng công khai không mang chi nhánh nào trong ctx nên vẫn đọc bản cộng —
// đúng ý "cả cửa hàng còn bao nhiêu".
//
// %d an toàn: shopID là uint đã qua middleware.ChiNhanhDangLam (đã tra sổ, đã
// đối chiếu với cửa hàng), không sinh ra được ký tự nào ngoài chữ số.
func tonExprSanPham(ctx context.Context) string {
	if ctx == nil {
		return tongTonExpr
	}
	shopID, ok := chinhanh.ID(ctx)
	if !ok {
		return tongTonExpr
	}

	return fmt.Sprintf(`COALESCE((SELECT SUM(vs.quantity) FROM variant_stocks vs
		WHERE vs.product_variant_id = product_variants.id AND vs.shop_id = %d), 0) AS stock_quantity`, shopID)
}

// giaExprSanPham trả GIÁ RIÊNG của chi nhánh đang làm việc, hoặc chuỗi rỗng khi
// không đứng ở chi nhánh nào.
//
// Truy vấn con thay vì LEFT JOIN: đây là một Preload, và thêm JOIN vào đó thì
// biến thể chưa khai giá riêng sẽ biến mất khỏi danh sách. NULL ở đây mang đúng
// nghĩa "chưa khai" — nơi đọc rơi về giá gốc.
func giaExprSanPham(ctx context.Context) string {
	if ctx == nil {
		return ""
	}
	shopID, ok := chinhanh.ID(ctx)
	if !ok {
		return ""
	}

	return fmt.Sprintf(`, (SELECT vsp.price FROM variant_shop_prices vsp
		WHERE vsp.product_variant_id = product_variants.id AND vsp.shop_id = %d) AS shop_price`, shopID)
}

// bienTheKemTon là bộ Preload biến thể có kèm tồn VÀ giá của chi nhánh đang làm
// việc — thiếu Select này thì stock_quantity im lặng bằng 0 và shop_price im
// lặng bằng nil, tức là màn hình hiện giá gốc cho một quầy có giá riêng.
func bienTheKemTon(db *gorm.DB) *gorm.DB {
	ctx := db.Statement.Context

	return db.Select("product_variants.*, " + tonExprSanPham(ctx) + giaExprSanPham(ctx)).
		Order("pos ASC, id ASC")
}

func NewProductRepository(db *gorm.DB) domain.ProductRepository {
	return &productRepository{db: db}
}

func (r *productRepository) List(ctx context.Context, f domain.ProductFilter) ([]domain.Product, int64, error) {
	q := r.db.WithContext(ctx).Model(&domain.Product{})

	// Mặc định chỉ lấy sản phẩm đang bán; admin có thể lọc chính xác (IsActive)
	// hoặc lấy tất cả (IncludeInactive).
	if f.IsActive != nil {
		q = q.Where("is_active = ?", *f.IsActive)
	} else if !f.IncludeInactive {
		q = q.Where("is_active = ?", true)
	}

	// Tìm bằng tên, mã hàng, HOẶC mã/mã vạch của một biến thể bất kỳ. Cái cuối
	// mới là đường dùng nhiều nhất ngoài quầy: người bán quét mã vạch dán trên
	// món hàng, mà mã ấy nằm ở biến thể chứ không nằm ở mặt hàng.
	if f.Keyword != "" {
		like := "%" + f.Keyword + "%"
		q = q.Where(`name LIKE ? OR sku LIKE ? OR EXISTS (
			SELECT 1 FROM product_variants v
			WHERE v.product_id = products.id AND v.deleted_at IS NULL
			  AND (v.sku LIKE ? OR v.barcode LIKE ? OR v.name LIKE ?)
		)`, like, like, like, like, like)
	}
	if len(f.CategoryIDs) > 0 {
		q = q.Where("category_id IN ?", f.CategoryIDs)
	} else if f.CategoryID != nil {
		q = q.Where("category_id = ?", *f.CategoryID)
	}
	// Vị trí: lọc theo một chỗ cụ thể, hoặc lấy riêng phần CHƯA gán chỗ nào.
	// Hai bộ lọc rời nhau chứ không phải hai giá trị của một trường — xem
	// ProductFilter.NoLocation.
	//
	// Đọc BẢNG NỐI, và "chưa gán" tính THEO CHI NHÁNH ĐANG XEM: mặt hàng đã xếp
	// kệ ở Quận 1 nhưng chưa xếp ở Quận 7 thì với người đứng ở Quận 7 nó đúng là
	// "chưa biết để đâu" — đó mới là câu hỏi họ đang hỏi.
	if f.NoLocation {
		if shopID := chiNhanhDoc(ctx, r.db); shopID > 0 {
			q = q.Where(`NOT EXISTS (SELECT 1 FROM product_shop_locations psl
				WHERE psl.product_id = products.id AND psl.shop_id = ?)`, shopID)
		} else {
			// Không đứng ở chi nhánh nào: "chưa gán" nghĩa là chưa xếp kệ ở BẤT KỲ
			// kho nào — câu duy nhất còn nghĩa khi không có kho nào để hỏi.
			q = q.Where(`NOT EXISTS (SELECT 1 FROM product_shop_locations psl
				WHERE psl.product_id = products.id)`)
		}
	} else if f.LocationID != nil {
		q = q.Where(`EXISTS (SELECT 1 FROM product_shop_locations psl
			WHERE psl.product_id = products.id AND psl.location_id = ?)`, *f.LocationID)
	}
	if f.UnitID != nil {
		q = q.Where("unit_id = ?", *f.UnitID)
	}
	// Chi nhánh: hàng gán đích danh chi nhánh này, HOẶC hàng chưa gán chi nhánh
	// nào (= dùng chung mọi chi nhánh). Xem ProductFilter.ShopID.
	if f.ShopID != nil {
		q = q.Where(`EXISTS (
			SELECT 1 FROM product_shops ps
			WHERE ps.product_id = products.id AND ps.shop_id = ?
		) OR NOT EXISTS (
			SELECT 1 FROM product_shops ps2 WHERE ps2.product_id = products.id
		)`, *f.ShopID)
	}
	if f.IsMultiVariant != nil {
		q = q.Where("is_multi_variant = ?", *f.IsMultiVariant)
	}
	if len(f.Statuses) > 0 {
		q = q.Where("status IN ?", f.Statuses)
	} else if f.Status != "" {
		q = q.Where("status = ?", f.Status)
	}
	if f.IsFeatured != nil {
		q = q.Where("is_featured = ?", *f.IsFeatured)
	}
	if f.OnSale != nil {
		if *f.OnSale {
			q = q.Where("sale_price IS NOT NULL AND sale_price > 0 AND sale_price < base_price")
		} else {
			q = q.Where("sale_price IS NULL OR sale_price <= 0 OR sale_price >= base_price")
		}
	}
	if f.MinPrice != nil {
		q = q.Where("base_price >= ?", *f.MinPrice)
	}
	if f.MaxPrice != nil {
		q = q.Where("base_price <= ?", *f.MaxPrice)
	}

	var total int64
	if err := q.Count(&total).Error; err != nil {
		return nil, 0, err
	}

	// Bản cũ v2 sắp xếp bằng cách BẤM TIÊU ĐỀ CỘT (tên, nhóm, giá bán) chứ không
	// có ô chọn "sắp xếp theo". Mỗi nhánh dưới đây là một tiêu đề bấm được.
	switch f.Sort {
	case "name_asc":
		q = q.Order("name ASC")
	case "name_desc":
		q = q.Order("name DESC")
	case "group_asc":
		// Sắp theo TÊN nhóm chứ không theo category_id: id là số nội bộ, người
		// dùng bấm tiêu đề "Nhóm hàng" là muốn thấy nhóm xếp theo vần.
		q = q.Order(groupNameExpr + " ASC")
	case "group_desc":
		q = q.Order(groupNameExpr + " DESC")
	case "price_asc":
		q = q.Order("base_price ASC")
	case "price_desc":
		q = q.Order("base_price DESC")
	case "best_selling":
		// Thêm id để thứ tự ổn định khi nhiều sản phẩm cùng lượt bán (VD đều bằng 0).
		q = q.Order("sold_count DESC, id DESC")
	default:
		// Mặc định là THỨ TỰ NGƯỜI BÁN TỰ XẾP (bản cũ v2 cũng vậy): số lớn nằm
		// trên. Thêm id để hai mặt hàng cùng sort không nhảy chỗ giữa hai lượt tải.
		q = q.Order("sort DESC, id DESC")
	}

	offset := (f.Page - 1) * f.PageSize

	// Danh mục & thương hiệu luôn nạp: thẻ sản phẩm hiển thị chúng, và tầng
	// khuyến mãi cần để biết chương trình có áp cho sản phẩm này không.
	q = q.Preload("Category").Preload("Unit").
		Preload("Shops").Preload("Tags")
	if !f.Slim {
		q = q.Preload("Variants", bienTheKemTon).
			Preload("Variants.Attributes").
			Preload("Variants.Attributes.Attribute").
			Preload("Variants.Attributes.Value").
			Preload("Images", func(db *gorm.DB) *gorm.DB { return db.Order("sort_order ASC, id ASC") })
	}

	var products []domain.Product
	if err := q.Offset(offset).Limit(f.PageSize).Find(&products).Error; err != nil {
		return nil, 0, err
	}

	return products, total, r.napViTri(ctx, products)
}

// napViTri điền chỗ để hàng TẠI CHI NHÁNH ĐANG LÀM VIỆC cho cả trang — hai câu
// truy vấn, không phải mỗi mặt hàng một câu.
//
// Không đứng ở chi nhánh nào (gian hàng công khai, báo cáo toàn cửa hàng) thì
// để trống: "kệ nào" không có câu trả lời khi chưa biết đang hỏi kho nào, và
// bịa ra kệ của một chi nhánh bất kỳ là dắt người soạn hàng đi nhầm chỗ.
func (r *productRepository) napViTri(ctx context.Context, list []domain.Product) error {
	if len(list) == 0 {
		return nil
	}

	shopID := chiNhanhDoc(ctx, r.db)
	if shopID == 0 {
		return nil
	}

	ids := make([]uint, 0, len(list))
	for _, p := range list {
		ids = append(ids, p.ID)
	}

	var gan []struct {
		ProductID  uint
		LocationID uint
	}
	if err := r.db.WithContext(ctx).Table("product_shop_locations").
		Select("product_id, location_id").
		Where("shop_id = ? AND product_id IN ?", shopID, ids).
		Scan(&gan).Error; err != nil {
		return err
	}
	if len(gan) == 0 {
		return nil
	}

	viTriIDs := make([]uint, 0, len(gan))
	theoHang := make(map[uint]uint, len(gan))
	for _, g := range gan {
		theoHang[g.ProductID] = g.LocationID
		if !slices.Contains(viTriIDs, g.LocationID) {
			viTriIDs = append(viTriIDs, g.LocationID)
		}
	}

	// Unscoped: kệ có thể đã xoá mềm mà mặt hàng vẫn còn trỏ tới — màn hình phải
	// in ra được tên cũ chứ không phải một ô trống không giải thích gì.
	var kes []domain.ViTri
	if err := r.db.WithContext(ctx).Unscoped().
		Where("id IN ?", viTriIDs).Find(&kes).Error; err != nil {
		return err
	}
	theoKe := make(map[uint]*domain.ViTri, len(kes))
	for i := range kes {
		theoKe[kes[i].ID] = &kes[i]
	}

	for i := range list {
		id, co := theoHang[list[i].ID]
		if !co {
			continue
		}
		vt := id
		list[i].LocationID = &vt
		list[i].Location = theoKe[id]
	}

	return nil
}

func (r *productRepository) FindByID(ctx context.Context, id uint) (*domain.Product, error) {
	var p domain.Product
	err := r.db.WithContext(ctx).
		Preload("Category").Preload("Unit").
		Preload("Shops").Preload("Tags").
		Preload("Variants", bienTheKemTon).
		Preload("Variants.Attributes").
		Preload("Variants.Attributes.Attribute").
		Preload("Variants.Attributes.Value").
		Preload("Images").
		First(&p, id).Error
	if errors.Is(err, gorm.ErrRecordNotFound) {
		return nil, domain.ErrNotFound
	}
	if err != nil {
		return nil, err
	}

	mot := []domain.Product{p}
	if err := r.napViTri(ctx, mot); err != nil {
		return nil, err
	}

	return &mot[0], nil
}

func (r *productRepository) FindBySlug(ctx context.Context, slug string) (*domain.Product, error) {
	var p domain.Product
	err := r.db.WithContext(ctx).
		Preload("Category").Preload("Unit").
		Preload("Shops").Preload("Tags").
		Preload("Variants", bienTheKemTon).
		Preload("Variants.Attributes").
		Preload("Variants.Attributes.Attribute").
		Preload("Variants.Attributes.Value").
		Preload("Images").
		Where("slug = ?", slug).First(&p).Error
	if errors.Is(err, gorm.ErrRecordNotFound) {
		return nil, domain.ErrNotFound
	}
	return &p, err
}

func (r *productRepository) ExistsBySlug(ctx context.Context, slug string, excludeID uint) (bool, error) {
	return r.existsByColumn(ctx, "slug", slug, excludeID)
}

func (r *productRepository) ExistsBySKU(ctx context.Context, sku string, excludeID uint) (bool, error) {
	return r.existsByColumn(ctx, "sku", sku, excludeID)
}

// ExistsByName — trùng TÊN mặt hàng trong cùng cửa hàng, không phân biệt hoa
// thường. Hai mặt hàng cùng tên thì thu ngân gõ tên ra hai dòng y hệt nhau,
// bán nhầm dòng nào cũng không biết.
func (r *productRepository) ExistsByName(ctx context.Context, name string, excludeID uint) (bool, error) {
	var count int64
	q := r.db.WithContext(ctx).Model(&domain.Product{}).
		Where("LOWER(name) COLLATE utf8mb4_bin = LOWER(?)", name)
	if excludeID > 0 {
		q = q.Where("id <> ?", excludeID)
	}
	err := q.Count(&count).Error

	return count > 0, err
}

// Count đếm sản phẩm của cửa hàng cho lượt xét hạn mức hợp đồng.
//
// KHÔNG Unscoped: sản phẩm đã xoá mềm không còn hiện ở đâu và không chiếm chỗ
// của ai, nên tính chúng vào nghĩa là khách xoá bớt hàng mà hạn mức không nhả
// ra — một cái trần chỉ đi lên, không bao giờ đi xuống.
//
// Không lọc gì thêm: bộ lọc tenant tự chèn `tenant_id`, và đó là điều kiện duy
// nhất đúng ở đây.
func (r *productRepository) Count(ctx context.Context) (int64, error) {
	var count int64
	err := r.db.WithContext(ctx).Model(&domain.Product{}).Count(&count).Error

	return count, err
}

// existsByColumn dùng chung cho hai cột có UNIQUE index (slug, sku).
//
// KHÔNG Unscoped: từ khi products có cột deleted_mark, hai UNIQUE key là
// (slug, deleted_mark) và (sku, deleted_mark) — dòng đã xoá mềm không còn chiếm
// chỗ của dòng đang sống nữa, nên xoá một sản phẩm rồi tạo lại sản phẩm cùng
// slug/SKU là hợp lệ. Tính cả dòng đã xoá vào đây là chặn oan người dùng.
func (r *productRepository) existsByColumn(ctx context.Context, column, value string, excludeID uint) (bool, error) {
	var count int64
	q := r.db.WithContext(ctx).Model(&domain.Product{}).Where(column+" = ?", value)
	if excludeID > 0 {
		q = q.Where("id <> ?", excludeID)
	}
	err := q.Count(&count).Error
	return count > 0, err
}

// Create ghi ĐÚNG dòng products.
//
// Omit(clause.Associations) cùng lý do với Update: chi nhánh và thẻ là quan hệ
// many2many, để GORM tự lưu là nó chèn thẳng vào bảng nối — mà câu chèn kiểu ấy
// không đi qua plugin đóng dấu cửa hàng, nên tenant_id trống và MySQL từ chối.
// Hai cụm đó có đường ghi riêng: ReplaceShops / ReplaceTags.
func (r *productRepository) Create(ctx context.Context, p *domain.Product) error {
	return r.db.WithContext(ctx).Omit(clause.Associations).Create(p).Error
}

// Update ghi ĐÚNG dòng products, không đụng tới quan hệ.
//
// Omit(clause.Associations) là bắt buộc chứ không phải dọn dẹp: `p` vừa đi qua
// FindByID nên Category đã được preload sẵn, mà GORM thì tự lưu quan hệ
// belongs-to TRƯỚC rồi lấy id của nó ghi đè lại khoá ngoại. Biến thể, thư viện
// ảnh, chi nhánh, thẻ và VỊ TRÍ đều có đường ghi riêng
// (ReplaceVariants/ReplaceImages/ReplaceShops/ReplaceTags/DatViTri), không để
// Save đụng vào.
func (r *productRepository) Update(ctx context.Context, p *domain.Product) error {
	return r.db.WithContext(ctx).Omit(clause.Associations).Save(p).Error
}

// SetStatus ghi trạng thái kinh doanh và cờ hiển thị trong cùng một lệnh.
//
// Hai cột luôn đi đôi: is_active là thứ mọi truy vấn bán hàng/kho/báo cáo đang
// lọc, còn status là thứ người bán đọc. Ghi lệch nhau một cái là sản phẩm ngừng
// kinh doanh vẫn bày ngoài cửa hàng.
func (r *productRepository) SetStatus(ctx context.Context, id uint, status string) error {
	res := r.db.WithContext(ctx).Model(&domain.Product{}).
		Where("id = ?", id).
		UpdateColumns(map[string]any{
			"status":    status,
			"is_active": status == domain.ProductStatusActive,
		})
	if res.Error != nil {
		return res.Error
	}
	// 0 dòng có thể là id không tồn tại, cũng có thể là sản phẩm đã ở đúng trạng
	// thái này rồi — xem conDong.
	if res.RowsAffected == 0 {
		return conDong(ctx, r.db, &domain.Product{}, id)
	}
	return nil
}

func (r *productRepository) Delete(ctx context.Context, id uint) error {
	return r.db.WithContext(ctx).Delete(&domain.Product{}, id).Error
}

// DeleteMany xoá mềm nhiều sản phẩm trong một giao dịch: hoặc xoá được hết,
// hoặc không đụng gì cả. Trả về số dòng thực sự bị xoá để tầng trên báo lại cho
// người dùng (id không tồn tại hoặc đã xoá trước đó thì không tính).
func (r *productRepository) DeleteMany(ctx context.Context, ids []uint) (int64, error) {
	if len(ids) == 0 {
		return 0, nil
	}
	var affected int64
	err := r.db.WithContext(ctx).Transaction(func(tx *gorm.DB) error {
		res := tx.Where("id IN ?", ids).Delete(&domain.Product{})
		if res.Error != nil {
			return res.Error
		}
		affected = res.RowsAffected
		return nil
	})
	return affected, err
}

// DoiChoThuTu đổi chỗ hai giá trị sort — KHÔNG đánh số lại cả bảng.
//
// Đánh số lại thì mỗi lần bấm mũi tên là một lượt UPDATE quét toàn bộ mặt hàng
// của cửa hàng; đổi chỗ hai dòng thì luôn là hai dòng, dù có mười nghìn mặt hàng.
//
// Cả hai lượt ghi nằm trong MỘT giao dịch: hỏng giữa chừng là hai mặt hàng mang
// cùng một số thứ tự, và từ đó thứ tự danh sách nhảy loạn mỗi lần tải.
func (r *productRepository) DoiChoThuTu(ctx context.Context, id uint, huong string) error {
	return r.db.WithContext(ctx).Transaction(func(tx *gorm.DB) error {
		var hienTai domain.Product
		if err := tx.First(&hienTai, id).Error; err != nil {
			if errors.Is(err, gorm.ErrRecordNotFound) {
				return domain.ErrNotFound
			}
			return err
		}

		// Danh sách sắp GIẢM DẦN theo sort, nên "lên trên" là tìm mặt hàng có
		// sort LỚN HƠN gần nhất.
		q := tx.Model(&domain.Product{})
		var kt domain.Product
		var err error
		if huong == "up" {
			err = q.Where("(sort > ?) OR (sort = ? AND id > ?)", hienTai.Sort, hienTai.Sort, hienTai.ID).
				Order("sort ASC, id ASC").First(&kt).Error
		} else {
			err = q.Where("(sort < ?) OR (sort = ? AND id < ?)", hienTai.Sort, hienTai.Sort, hienTai.ID).
				Order("sort DESC, id DESC").First(&kt).Error
		}
		if errors.Is(err, gorm.ErrRecordNotFound) {
			if huong == "up" {
				return domain.ErrDaODau
			}
			return domain.ErrDaOCuoi
		}
		if err != nil {
			return err
		}

		// Hai mặt hàng cùng số thứ tự (dữ liệu cũ, hoặc vừa nhập hàng loạt) thì
		// đổi chỗ số không giải quyết được gì — đẩy lệch nhau một bậc.
		moiHienTai, moiKe := kt.Sort, hienTai.Sort
		if moiHienTai == moiKe {
			if huong == "up" {
				moiHienTai++
			} else {
				moiHienTai--
			}
		}

		if err := tx.Model(&domain.Product{}).Where("id = ?", hienTai.ID).
			UpdateColumn("sort", moiHienTai).Error; err != nil {
			return err
		}
		return tx.Model(&domain.Product{}).Where("id = ?", kt.ID).
			UpdateColumn("sort", moiKe).Error
	})
}

// SapXepLai gán lại thứ tự cho ĐÚNG những mặt hàng trong danh sách truyền vào,
// theo đúng trình tự ấy (phần tử đầu nằm trên cùng).
//
// Cách làm: gom chính các giá trị `sort` mà mấy dòng này ĐANG giữ, xếp giảm dần,
// rồi phát lại theo thứ tự mới. Không đánh số 1..n, và đó là điểm mấu chốt —
// bảng đang phân trang, đánh số lại theo trang thì cả trang nhảy lên đầu (hoặc
// rơi xuống đáy) so với những trang khác. Hoán vị trong đúng tập số cũ thì mọi
// mặt hàng ngoài trang giữ nguyên chỗ đứng tương đối của nó.
func (r *productRepository) SapXepLai(ctx context.Context, ids []uint) error {
	if len(ids) == 0 {
		return nil
	}

	return r.db.WithContext(ctx).Transaction(func(tx *gorm.DB) error {
		var ds []domain.Product
		if err := tx.Select("id, sort").Where("id IN ?", ids).Find(&ds).Error; err != nil {
			return err
		}
		// Thiếu dòng nào nghĩa là danh sách gửi lên nói tới mặt hàng không còn
		// (vừa bị xoá ở tab khác). Nhận bừa thì số thứ tự phát lệch một nhịp cho
		// tất cả những dòng phía sau.
		if len(ds) != len(ids) {
			return domain.ErrNotFound
		}

		soCu := make([]int, 0, len(ds))
		for _, p := range ds {
			soCu = append(soCu, p.Sort)
		}
		sort.Sort(sort.Reverse(sort.IntSlice(soCu)))

		for i, id := range ids {
			if err := tx.Model(&domain.Product{}).Where("id = ?", id).
				UpdateColumn("sort", soCu[i]).Error; err != nil {
				return err
			}
		}
		return nil
	})
}

// ThuTuKeTiep trả số thứ tự cho mặt hàng mới: lớn hơn mọi giá trị đang có, để
// hàng vừa thêm nằm ngay đầu danh sách thay vì rơi xuống đáy.
func (r *productRepository) ThuTuKeTiep(ctx context.Context) (int, error) {
	var max *int
	err := r.db.WithContext(ctx).Model(&domain.Product{}).
		Select("MAX(sort)").Scan(&max).Error
	if err != nil {
		return 0, err
	}
	if max == nil {
		return 1, nil
	}
	return *max + 1, nil
}

func (r *productRepository) IncrementView(ctx context.Context, id uint) error {
	return r.db.WithContext(ctx).Model(&domain.Product{}).
		Where("id = ?", id).
		UpdateColumn("view_count", gorm.Expr("view_count + 1")).Error
}

func (r *productRepository) ReplaceVariants(ctx context.Context, productID uint, variants []domain.ProductVariant) error {
	return r.db.WithContext(ctx).Transaction(func(tx *gorm.DB) error {
		// Xoá (soft-delete) các biến thể cũ không còn trong danh sách gửi lên.
		keep := make([]uint, 0, len(variants))
		for _, v := range variants {
			if v.ID > 0 {
				keep = append(keep, v.ID)
			}
		}
		del := tx.Where("product_id = ?", productID)
		if len(keep) > 0 {
			del = del.Where("id NOT IN ?", keep)
		}
		if err := del.Delete(&domain.ProductVariant{}).Error; err != nil {
			return err
		}

		// Upsert từng biến thể: ID>0 -> cập nhật, ID=0 -> thêm mới.
		//
		// Tồn kho KHÔNG nằm trong bảng này nữa (nó ở variant_stocks, mỗi chi nhánh
		// một dòng), nên form sản phẩm không có cách nào đạp phải nó — trước đây
		// phải Omit("stock_quantity") ở cả hai nhánh đúng vì lý do đó.
		//
		// Tách hẳn hai nhánh Create/Updates thay vì dùng chung Save(): dữ liệu
		// dựng từ request không mang created_at, mà Save() ghi MỌI cột nên nó
		// đẩy '0000-00-00' vào created_at của dòng cũ. MySQL bật STRICT_TRANS_TABLES
		// (như máy chủ thật) báo lỗi 1292 và cả lệnh sửa sản phẩm hỏng; MySQL dễ
		// tính hơn thì nuốt, và ngày tạo của biến thể bị xoá trắng lúc nào không hay.
		for i := range variants {
			variants[i].ProductID = productID
			// Tổ hợp thuộc tính có đường ghi riêng ở dưới. Để GORM tự lưu quan
			// hệ thì dòng cũ của biến thể không bị dọn, và bảng nối phình lên
			// mỗi lượt sửa mặt hàng.
			tohop := variants[i].Attributes
			variants[i].Attributes = nil

			if variants[i].ID == 0 {
				// Thêm mới: để GORM tự điền created_at/updated_at.
				if err := tx.Omit(clause.Associations).Create(&variants[i]).Error; err != nil {
					return err
				}
			} else if err := tx.Model(&domain.ProductVariant{}).
				Where("id = ?", variants[i].ID).
				Omit("created_at").
				// Updates với struct bỏ qua trường zero-value, nên phải chỉ rõ
				// từng cột — không thì xoá tên biến thể, gỡ giá riêng hay tắt
				// biến thể đều không lưu được.
				Updates(map[string]any{
					"sku":         variants[i].SKU,
					"barcode":     variants[i].Barcode,
					"name":        variants[i].Name,
					"is_default":  variants[i].IsDefault,
					"pos":         variants[i].Pos,
					"price":       variants[i].Price,
					"cost_price":  variants[i].CostPrice,
					"weight_gram": variants[i].WeightGram,
					"image":       variants[i].Image,
					"is_active":   variants[i].IsActive,
				}).Error; err != nil {
				return err
			}

			if err := replaceVariantAttributes(tx, variants[i].ID, tohop); err != nil {
				return err
			}
			variants[i].Attributes = tohop
		}
		return nil
	})
}

// replaceVariantAttributes ghi lại NGUYÊN cụm tổ hợp thuộc tính của một biến thể.
//
// Xoá sạch rồi chèn lại thay vì so từng dòng: cụm này chỉ vài dòng, không có
// khoá ngoại nào trỏ vào nó, và "so từng dòng" là chỗ dễ để sót một chiều đã bỏ
// — biến thể sẽ mang tên "128GB · Đen" mà tổ hợp vẫn còn cả "256GB".
func replaceVariantAttributes(tx *gorm.DB, variantID uint, tohop []domain.ProductVariantAttribute) error {
	if variantID == 0 {
		return nil
	}
	if err := tx.Where("variant_id = ?", variantID).
		Delete(&domain.ProductVariantAttribute{}).Error; err != nil {
		return err
	}
	for i := range tohop {
		if tohop[i].AttributeID == 0 || tohop[i].ValueID == 0 {
			continue
		}
		dong := domain.ProductVariantAttribute{
			VariantID:   variantID,
			AttributeID: tohop[i].AttributeID,
			ValueID:     tohop[i].ValueID,
		}
		// Omit quan hệ: Attribute/Value chỉ để ĐỌC (nạp kèm cho màn hình), để
		// GORM lưu chúng là sửa nhầm sang chính bảng danh mục thuộc tính.
		if err := tx.Omit(clause.Associations).Create(&dong).Error; err != nil {
			return err
		}
		tohop[i].ID = dong.ID
	}
	return nil
}

// ReplaceShops ghi lại nguyên cụm chi nhánh quản lý mặt hàng.
//
// Xoá sạch rồi chèn lại thay vì so từng dòng: cụm này nhiều lắm là vài chục
// dòng, không khoá ngoại nào trỏ vào nó, và "so từng dòng" là chỗ dễ sót một
// chi nhánh đã bỏ tick — mặt hàng sẽ vẫn còn bán ở nơi người ta vừa gỡ ra.
func (r *productRepository) ReplaceShops(ctx context.Context, productID uint, shopIDs []uint) error {
	return r.db.WithContext(ctx).Transaction(func(tx *gorm.DB) error {
		if err := tx.Where("product_id = ?", productID).
			Delete(&domain.ProductShop{}).Error; err != nil {
			return err
		}
		daCo := make(map[uint]bool, len(shopIDs))
		for _, id := range shopIDs {
			if id == 0 || daCo[id] {
				continue
			}
			daCo[id] = true
			dong := domain.ProductShop{ProductID: productID, ShopID: id}
			if err := tx.Omit(clause.Associations).Create(&dong).Error; err != nil {
				return err
			}
		}
		return nil
	})
}

// ReplaceTags dán lại nguyên cụm thẻ của mặt hàng, nhận TÊN thẻ.
//
// Tên gõ vào được dọn khoảng trắng rồi tra trong cửa hàng: có thì dùng lại dòng
// cũ, chưa có thì mở dòng mới. Đây là chỗ giữ cho dãy thẻ ngoài quầy không phình
// ra vì "Món mới" và "món  mới" — so tên nhờ collation utf8mb4_unicode_ci của
// cột, vốn đã không phân biệt hoa thường.
func (r *productRepository) ReplaceTags(ctx context.Context, productID uint, names []string) error {
	return r.db.WithContext(ctx).Transaction(func(tx *gorm.DB) error {
		if err := tx.Where("product_id = ?", productID).
			Delete(&domain.ProductTagLink{}).Error; err != nil {
			return err
		}
		daCo := make(map[string]bool, len(names))
		for _, raw := range names {
			ten := gonKhoangTrang(raw)
			if ten == "" || daCo[strings.ToLower(ten)] {
				continue
			}
			daCo[strings.ToLower(ten)] = true

			var the domain.ProductTag
			err := tx.Where("name = ?", ten).First(&the).Error
			if errors.Is(err, gorm.ErrRecordNotFound) {
				the = domain.ProductTag{Name: ten}
				if err := tx.Omit(clause.Associations).Create(&the).Error; err != nil {
					return err
				}
			} else if err != nil {
				return err
			}

			noi := domain.ProductTagLink{ProductID: productID, TagID: the.ID}
			if err := tx.Omit(clause.Associations).Create(&noi).Error; err != nil {
				return err
			}
		}
		return nil
	})
}

// gonKhoangTrang bỏ khoảng trắng hai đầu và gộp khoảng trắng giữa các chữ về một.
// "  Món   mới " -> "Món mới".
func gonKhoangTrang(s string) string {
	return strings.Join(strings.Fields(s), " ")
}

func (r *productRepository) DanhSachThe(ctx context.Context) ([]domain.ProductTag, error) {
	var ds []domain.ProductTag
	err := r.db.WithContext(ctx).Order("name ASC").Find(&ds).Error
	return ds, err
}

func (r *productRepository) ReplaceImages(ctx context.Context, productID uint, images []domain.ProductImage) error {
	return r.db.WithContext(ctx).Transaction(func(tx *gorm.DB) error {
		// Xoá (hard-delete) các ảnh cũ không còn trong danh sách gửi lên.
		keep := make([]uint, 0, len(images))
		for _, img := range images {
			if img.ID > 0 {
				keep = append(keep, img.ID)
			}
		}
		del := tx.Where("product_id = ?", productID)
		if len(keep) > 0 {
			del = del.Where("id NOT IN ?", keep)
		}
		if err := del.Delete(&domain.ProductImage{}).Error; err != nil {
			return err
		}

		// Upsert từng ảnh: ID>0 -> cập nhật, ID=0 -> thêm mới.
		//
		// Cùng lý do như ReplaceVariants: Save() ghi cả created_at rỗng, dòng ảnh
		// cũ sẽ nhận '0000-00-00' và MySQL nghiêm ngặt chặn cả lệnh sửa sản phẩm.
		for i := range images {
			images[i].ProductID = productID

			if images[i].ID == 0 {
				if err := tx.Create(&images[i]).Error; err != nil {
					return err
				}
				continue
			}

			if err := tx.Model(&domain.ProductImage{}).
				Where("id = ?", images[i].ID).
				Omit("created_at").
				// Chỉ rõ từng cột: alt rỗng và sort_order = 0 là giá trị hợp lệ,
				// Updates với struct sẽ bỏ qua chúng.
				Updates(map[string]any{
					"url":        images[i].URL,
					"alt":        images[i].Alt,
					"sort_order": images[i].SortOrder,
					"is_primary": images[i].IsPrimary,
				}).Error; err != nil {
				return err
			}
		}
		return nil
	})
}

// DatViTri gán hoặc gỡ kệ cho một mặt hàng TẠI CHI NHÁNH ĐANG LÀM VIỆC.
//
// nil = gỡ ra (xoá dòng), khác hẳn với "ghi id 0": bảng nối không có dòng nghĩa
// là chi nhánh này chưa xếp kệ cho món ấy — cùng quy ước với `product_shops`
// (rỗng = mọi chi nhánh) và `variant_shop_prices` (thiếu dòng = giá gốc).
//
// Không đứng ở chi nhánh nào thì KHÔNG làm gì: "xếp vào kệ nào" chưa có nghĩa
// khi chưa biết đang nói về kho nào, và ghi bừa vào một chi nhánh là dắt người
// soạn hàng đi nhầm chỗ. Nhánh này xảy ra với lượt nhập hàng loạt và lượt gọi
// từ gian hàng — cả hai đều không khai kệ.
func (r *productRepository) DatViTri(ctx context.Context, productID uint, locationID *uint) error {
	if productID == 0 {
		return nil
	}

	// Luật của đường GHI: cửa hàng một chi nhánh thì tự suy ra, nhiều chi nhánh
	// mà không khai thì TỪ CHỐI. Trả 0 lặng lẽ như bản đầu là mất trắng phần
	// người dùng vừa gõ mà không câu nào nói ra.
	shopID, err := chiNhanhCuaRequest(ctx, r.db)
	if err != nil {
		return err
	}

	if locationID == nil || *locationID == 0 {
		return r.db.WithContext(ctx).
			Where("shop_id = ? AND product_id = ?", shopID, productID).
			Delete(&domain.ViTriHangHoa{}).Error
	}

	dong := domain.ViTriHangHoa{ShopID: shopID, ProductID: productID, LocationID: *locationID}

	return r.db.WithContext(ctx).Clauses(clause.OnConflict{
		Columns:   []clause.Column{{Name: "shop_id"}, {Name: "product_id"}},
		DoUpdates: clause.AssignmentColumns([]string{"location_id", "updated_at"}),
	}).Create(&dong).Error
}
