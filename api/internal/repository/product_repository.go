package repository

import (
	"context"
	"errors"

	"sass-api/internal/domain"

	"gorm.io/gorm"
)

type productRepository struct{ db *gorm.DB }

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

	if f.Keyword != "" {
		like := "%" + f.Keyword + "%"
		q = q.Where("name LIKE ? OR team LIKE ? OR sku LIKE ?", like, like, like)
	}
	if len(f.CategoryIDs) > 0 {
		q = q.Where("category_id IN ?", f.CategoryIDs)
	} else if f.CategoryID != nil {
		q = q.Where("category_id = ?", *f.CategoryID)
	}
	if f.BrandID != nil {
		q = q.Where("brand_id = ?", *f.BrandID)
	}
	if f.KitType != "" {
		q = q.Where("kit_type = ?", f.KitType)
	}
	if f.Status != "" {
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

	switch f.Sort {
	case "price_asc":
		q = q.Order("base_price ASC")
	case "price_desc":
		q = q.Order("base_price DESC")
	case "best_selling":
		// Thêm id để thứ tự ổn định khi nhiều sản phẩm cùng lượt bán (VD đều bằng 0).
		q = q.Order("sold_count DESC, id DESC")
	default:
		q = q.Order("id DESC")
	}

	offset := (f.Page - 1) * f.PageSize

	// Danh mục & thương hiệu luôn nạp: thẻ sản phẩm hiển thị chúng, và tầng
	// khuyến mãi cần để biết chương trình có áp cho sản phẩm này không.
	q = q.Preload("Brand").Preload("Category")
	if !f.Slim {
		q = q.Preload("Variants", func(db *gorm.DB) *gorm.DB { return db.Order("id ASC") }).
			Preload("Images", func(db *gorm.DB) *gorm.DB { return db.Order("sort_order ASC, id ASC") })
	}

	var products []domain.Product
	err := q.Offset(offset).Limit(f.PageSize).Find(&products).Error
	return products, total, err
}

func (r *productRepository) FindByID(ctx context.Context, id uint) (*domain.Product, error) {
	var p domain.Product
	err := r.db.WithContext(ctx).
		Preload("Brand").Preload("Category").
		Preload("Variants").Preload("Images").
		First(&p, id).Error
	if errors.Is(err, gorm.ErrRecordNotFound) {
		return nil, domain.ErrNotFound
	}
	return &p, err
}

func (r *productRepository) FindBySlug(ctx context.Context, slug string) (*domain.Product, error) {
	var p domain.Product
	err := r.db.WithContext(ctx).
		Preload("Brand").Preload("Category").
		Preload("Variants").Preload("Images").
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

func (r *productRepository) Create(ctx context.Context, p *domain.Product) error {
	return r.db.WithContext(ctx).Create(p).Error
}

func (r *productRepository) Update(ctx context.Context, p *domain.Product) error {
	return r.db.WithContext(ctx).Save(p).Error
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
	if res.RowsAffected == 0 {
		return domain.ErrNotFound
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
		// Omit("stock_quantity") là bắt buộc: tồn kho chỉ thuộc về nghiệp vụ kho
		// (nhập hàng, điều chỉnh, đơn hàng, trả hàng) và luôn đi kèm một dòng
		// trong inventory_transactions. Save() vốn ghi mọi cột, nên nếu không
		// loại trừ thì mỗi lần sửa sản phẩm sẽ đạp tồn kho về 0.
		// Dòng thêm mới nhờ đó lấy DEFAULT 0 của DB; dòng cũ giữ nguyên tồn.
		//
		// Tách hẳn hai nhánh Create/Updates thay vì dùng chung Save(): dữ liệu
		// dựng từ request không mang created_at, mà Save() ghi MỌI cột nên nó
		// đẩy '0000-00-00' vào created_at của dòng cũ. MySQL bật STRICT_TRANS_TABLES
		// (như máy chủ thật) báo lỗi 1292 và cả lệnh sửa sản phẩm hỏng; MySQL dễ
		// tính hơn thì nuốt, và ngày tạo của biến thể bị xoá trắng lúc nào không hay.
		for i := range variants {
			variants[i].ProductID = productID

			if variants[i].ID == 0 {
				// Thêm mới: để GORM tự điền created_at/updated_at.
				if err := tx.Omit("stock_quantity").Create(&variants[i]).Error; err != nil {
					return err
				}
				continue
			}

			if err := tx.Model(&domain.ProductVariant{}).
				Where("id = ?", variants[i].ID).
				Omit("stock_quantity", "created_at").
				// Updates với struct bỏ qua trường zero-value, nên phải chỉ rõ
				// từng cột — không thì xoá màu, gỡ giá riêng hay tắt biến thể
				// đều không lưu được.
				Updates(map[string]any{
					"sku":         variants[i].SKU,
					"size":        variants[i].Size,
					"color":       variants[i].Color,
					"price":       variants[i].Price,
					"cost_price":  variants[i].CostPrice,
					"weight_gram": variants[i].WeightGram,
					"image":       variants[i].Image,
					"is_active":   variants[i].IsActive,
				}).Error; err != nil {
				return err
			}
		}
		return nil
	})
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
