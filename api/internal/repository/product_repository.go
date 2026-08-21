package repository

import (
	"context"
	"errors"
	"strings"

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

// bienTheKemTon là bộ Preload biến thể có kèm tổng tồn, dùng chung cho mọi
// đường đọc sản phẩm — thiếu Select này thì stock_quantity im lặng bằng 0.
func bienTheKemTon(db *gorm.DB) *gorm.DB {
	return db.Select("product_variants.*, " + tongTonExpr).Order("pos ASC, id ASC")
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
	if f.NoLocation {
		q = q.Where("location_id IS NULL")
	} else if f.LocationID != nil {
		q = q.Where("location_id = ?", *f.LocationID)
	}
	if f.UnitID != nil {
		q = q.Where("unit_id = ?", *f.UnitID)
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
	q = q.Preload("Category").Preload("Location").Preload("Unit").
		Preload("Shops").Preload("Tags")
	if !f.Slim {
		q = q.Preload("Variants", bienTheKemTon).
			Preload("Variants.Attributes").
			Preload("Variants.Attributes.Attribute").
			Preload("Variants.Attributes.Value").
			Preload("Images", func(db *gorm.DB) *gorm.DB { return db.Order("sort_order ASC, id ASC") })
	}

	var products []domain.Product
	err := q.Offset(offset).Limit(f.PageSize).Find(&products).Error
	return products, total, err
}

func (r *productRepository) FindByID(ctx context.Context, id uint) (*domain.Product, error) {
	var p domain.Product
	err := r.db.WithContext(ctx).
		Preload("Category").Preload("Location").Preload("Unit").
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
	return &p, err
}

func (r *productRepository) FindBySlug(ctx context.Context, slug string) (*domain.Product, error) {
	var p domain.Product
	err := r.db.WithContext(ctx).
		Preload("Category").Preload("Location").Preload("Unit").
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
// FindByID nên Category/Location đã được preload sẵn, mà GORM thì tự lưu quan
// hệ belongs-to TRƯỚC rồi lấy id của nó ghi đè lại khoá ngoại. Nghĩa là gỡ vị
// trí (location_id = nil) bị chính đối tượng Location cũ còn nằm trong struct
// gán ngược trở lại — sửa xong nhìn vẫn y nguyên. Biến thể và thư viện ảnh cũng
// có đường ghi riêng (ReplaceVariants/ReplaceImages), không để Save đụng vào.
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
