package repository

import (
	"context"
	"errors"
	"slices"
	"strings"

	"gorm.io/gorm"
	"gorm.io/gorm/clause"

	"sass-api/internal/domain"
)

type inventoryRepository struct{ db *gorm.DB }

func NewInventoryRepository(db *gorm.DB) domain.InventoryRepository {
	return &inventoryRepository{db: db}
}

// effectivePriceExpr là giá HIỆU LỰC của một biến thể, tính ngay trong SQL để
// còn sắp xếp/cộng dồn được: giá riêng của biến thể nếu có, không thì giá khuyến
// mãi (chỉ khi nó thực sự thấp hơn giá gốc), không nữa thì giá gốc.
const effectivePriceExpr = `COALESCE(v.price,
	CASE WHEN p.sale_price IS NOT NULL AND p.sale_price > 0 AND p.sale_price < p.base_price
	    THEN p.sale_price ELSE p.base_price END)`

// effectiveCostExpr là giá VỐN hiệu lực: giá vốn riêng của biến thể nếu có, không
// thì giá vốn của sản phẩm cha. Cùng quy ước ghi đè với giá bán ở trên.
//
// KHÔNG bọc COALESCE(..., 0): NULL ở đây nghĩa là CHƯA KHAI giá vốn, khác hẳn giá
// vốn bằng 0. Đổi NULL thành 0 ngay trong SQL là mất luôn khả năng phân biệt, và
// tổng giá trị kho sẽ im lặng thiếu đi phần hàng chưa khai giá.
const effectiveCostExpr = `COALESCE(v.cost_price, p.cost_price)`

// stockValueExpr là giá trị hàng đang nằm trong kho, tính theo GIÁ VỐN.
//
// Giá trị tồn kho về kế toán phải theo giá vốn, không phải giá bán — tính theo giá
// bán là đã ghi nhận trước phần lãi chưa bán được. Biến thể chưa khai giá vốn đóng
// góp 0₫ và được đếm riêng qua missing_cost để giao diện nói rõ tổng đang thiếu.
//
// GREATEST(stock, 0): tồn âm (dữ liệu lệch) không được kéo tổng xuống thành số âm.
const stockValueExpr = `COALESCE(` + effectiveCostExpr + `, 0) * GREATEST(v.stock_quantity, 0)`

// baseQuery dựng phần FROM/JOIN dùng chung cho cả danh sách lẫn đếm.
//
// Sản phẩm đã xoá mềm bị loại hẳn: hàng của chúng không còn bán được nên đưa vào
// bảng tồn kho chỉ làm nhiễu con số.
func (r *inventoryRepository) baseQuery(ctx context.Context) *gorm.DB {
	return r.db.WithContext(ctx).
		Table("product_variants AS v").
		Joins("JOIN products p ON p.id = v.product_id AND p.deleted_at IS NULL").
		Where("v.deleted_at IS NULL")
}

// applyFilter gắn các điều kiện lọc lên một truy vấn đã có sẵn FROM/JOIN.
func applyInventoryFilter(q *gorm.DB, f domain.InventoryFilter) *gorm.DB {
	if kw := strings.TrimSpace(f.Keyword); kw != "" {
		like := "%" + kw + "%"
		q = q.Where("(p.name LIKE ? OR v.sku LIKE ? OR p.sku LIKE ?)", like, like, like)
	}
	if f.CategoryID != nil {
		q = q.Where("p.category_id = ?", *f.CategoryID)
	}
	if f.BrandID != nil {
		q = q.Where("p.brand_id = ?", *f.BrandID)
	}
	if f.IsActive != nil {
		q = q.Where("v.is_active = ?", *f.IsActive)
	}

	switch f.Stock {
	case "out":
		q = q.Where("v.stock_quantity <= 0")
	case "low":
		q = q.Where("v.stock_quantity > 0 AND v.stock_quantity <= ?", f.LowStock)
	case "in":
		q = q.Where("v.stock_quantity > ?", f.LowStock)
	}

	switch f.Cost {
	case "missing":
		q = q.Where(effectiveCostExpr + " IS NULL")
	case "set":
		q = q.Where(effectiveCostExpr + " IS NOT NULL")
	}
	return q
}

// inventoryOrder ánh xạ tên sắp xếp sang mệnh đề ORDER BY.
//
// Mọi nhánh đều kết thúc bằng v.id để thứ tự ổn định giữa các trang — thiếu nó,
// hai biến thể cùng tồn kho có thể đổi chỗ khi lật trang và người dùng thấy dòng
// lặp lại hoặc biến mất.
func inventoryOrder(sort string) string {
	switch sort {
	case "stock_desc":
		return "v.stock_quantity DESC, v.id DESC"
	case "name_asc":
		return "p.name ASC, v.id ASC"
	case "name_desc":
		return "p.name DESC, v.id DESC"
	case "value_desc":
		return "stock_value DESC, v.id DESC"
	case "newest":
		return "v.id DESC"
	default: // stock_asc — mặc định đẩy hàng sắp hết lên đầu, đó mới là việc cần làm
		return "v.stock_quantity ASC, v.id ASC"
	}
}

func (r *inventoryRepository) List(ctx context.Context, f domain.InventoryFilter) ([]domain.InventoryItem, int64, error) {
	var total int64
	countQ := applyInventoryFilter(r.baseQuery(ctx), f)
	if err := countQ.Count(&total).Error; err != nil {
		return nil, 0, err
	}
	if total == 0 {
		return []domain.InventoryItem{}, 0, nil
	}

	q := applyInventoryFilter(r.baseQuery(ctx), f).
		Joins("LEFT JOIN categories c ON c.id = p.category_id").
		Joins("LEFT JOIN brands b ON b.id = p.brand_id").
		Select(`v.id AS variant_id, v.product_id, p.name AS product_name, p.slug,
			COALESCE(NULLIF(v.image, ''), NULLIF(p.thumbnail, ''), '') AS thumbnail,
			v.sku, v.size, v.color, COALESCE(p.kit_type, '') AS kit_type,
			COALESCE(c.name, '') AS category_name, COALESCE(b.name, '') AS brand_name,
			v.stock_quantity, v.is_active, p.is_active AS product_active,
			` + effectivePriceExpr + ` AS price,
			` + effectiveCostExpr + ` AS cost_price,
			` + stockValueExpr + ` AS stock_value,
			(SELECT MAX(t.created_at) FROM inventory_transactions t
			  WHERE t.product_variant_id = v.id) AS last_moved_at`).
		Order(inventoryOrder(f.Sort)).
		Limit(f.PageSize).
		Offset((f.Page - 1) * f.PageSize)

	items := make([]domain.InventoryItem, 0, f.PageSize)
	if err := q.Scan(&items).Error; err != nil {
		return nil, 0, err
	}
	return items, total, nil
}

func (r *inventoryRepository) Stats(ctx context.Context, lowStock int) (domain.InventoryStats, error) {
	var s domain.InventoryStats
	err := r.baseQuery(ctx).
		Select(`COUNT(*) AS total_variants,
			COALESCE(SUM(GREATEST(v.stock_quantity, 0)), 0) AS total_quantity,
			COALESCE(SUM(CASE WHEN v.stock_quantity > ? THEN 1 ELSE 0 END), 0) AS in_stock,
			COALESCE(SUM(CASE WHEN v.stock_quantity > 0 AND v.stock_quantity <= ? THEN 1 ELSE 0 END), 0) AS low_stock,
			COALESCE(SUM(CASE WHEN v.stock_quantity <= 0 THEN 1 ELSE 0 END), 0) AS out_of_stock,
			COALESCE(SUM(`+stockValueExpr+`), 0) AS stock_value,
			COALESCE(SUM(CASE WHEN `+effectiveCostExpr+` IS NULL THEN 1 ELSE 0 END), 0) AS missing_cost,
			COALESCE(SUM(CASE WHEN `+effectiveCostExpr+` IS NULL AND v.stock_quantity > 0
			    THEN 1 ELSE 0 END), 0) AS missing_cost_in_stock`,
			lowStock, lowStock).
		Scan(&s).Error
	return s, err
}

func (r *inventoryRepository) FindItem(ctx context.Context, variantID uint) (*domain.InventoryItem, error) {
	var items []domain.InventoryItem
	err := r.baseQuery(ctx).
		Joins("LEFT JOIN categories c ON c.id = p.category_id").
		Joins("LEFT JOIN brands b ON b.id = p.brand_id").
		Where("v.id = ?", variantID).
		Select(`v.id AS variant_id, v.product_id, p.name AS product_name, p.slug,
			COALESCE(NULLIF(v.image, ''), NULLIF(p.thumbnail, ''), '') AS thumbnail,
			v.sku, v.size, v.color, COALESCE(p.kit_type, '') AS kit_type,
			COALESCE(c.name, '') AS category_name, COALESCE(b.name, '') AS brand_name,
			v.stock_quantity, v.is_active, p.is_active AS product_active,
			` + effectivePriceExpr + ` AS price,
			` + effectiveCostExpr + ` AS cost_price,
			` + stockValueExpr + ` AS stock_value,
			(SELECT MAX(t.created_at) FROM inventory_transactions t
			  WHERE t.product_variant_id = v.id) AS last_moved_at`).
		Limit(1).Scan(&items).Error
	if err != nil {
		return nil, err
	}
	if len(items) == 0 {
		return nil, domain.ErrNotFound
	}
	return &items[0], nil
}

func (r *inventoryRepository) Histories(ctx context.Context, variantID uint, page, pageSize int) ([]domain.InventoryHistory, int64, error) {
	base := r.db.WithContext(ctx).
		Table("inventory_transactions AS t").
		Where("t.product_variant_id = ?", variantID)

	var total int64
	if err := base.Count(&total).Error; err != nil {
		return nil, 0, err
	}
	if total == 0 {
		return []domain.InventoryHistory{}, 0, nil
	}

	// Mã chứng từ lấy theo đúng loại tham chiếu: cùng một reference_id có thể vừa
	// là id đơn hàng vừa là id phiếu trả, nên phải kèm điều kiện reference_type ở
	// từng JOIN thay vì COALESCE bừa hai bảng.
	rows := make([]domain.InventoryHistory, 0, pageSize)
	err := r.db.WithContext(ctx).
		Table("inventory_transactions AS t").
		Joins("LEFT JOIN users u ON u.id = t.created_by").
		Joins("LEFT JOIN orders o ON o.id = t.reference_id AND t.reference_type = 'order'").
		Joins("LEFT JOIN order_returns rt ON rt.id = t.reference_id AND t.reference_type = 'order_return'").
		Joins("LEFT JOIN purchase_orders po ON po.id = t.reference_id AND t.reference_type = 'purchase_order'").
		Where("t.product_variant_id = ?", variantID).
		Select(`t.id, t.type, t.quantity, t.quantity_before, t.quantity_after,
			COALESCE(t.reference_type, '') AS reference_type, t.reference_id,
			COALESCE(o.order_code, rt.return_code, po.po_code, '') AS reference_code,
			t.unit_cost, COALESCE(t.note, '') AS note,
			COALESCE(u.full_name, '') AS created_by_name, t.created_at`).
		Order("t.id DESC").
		Limit(pageSize).
		Offset((page - 1) * pageSize).
		Scan(&rows).Error
	if err != nil {
		return nil, 0, err
	}
	return rows, total, nil
}

// SetCostPrices ghi giá vốn cho nhiều biến thể trong một transaction.
//
// Ghi vào product_variants.cost_price (mức ghi đè) chứ không vào products: file
// nhập khoá theo SKU biến thể, mà một sản phẩm có nhiều biến thể — suy ngược lên
// sản phẩm cha là đoán mò, và đoán sai thì đè lên giá vốn của cả những biến thể
// không có trong file.
//
// Không ghi sổ kho: đây là sửa thuộc tính của hàng, không phải hàng ra vào kho.
//
// Tất-cả-hoặc-không như các thao tác hàng loạt khác: một biến thể không tồn tại
// thì không dòng nào được ghi.
func (r *inventoryRepository) SetCostPrices(ctx context.Context, items []domain.VariantCost) (int64, error) {
	if len(items) == 0 {
		return 0, domain.ErrConflict
	}

	// Gộp theo biến thể, dòng sau thắng — file do người gõ, trùng SKU là chuyện thường.
	byID := make(map[uint]*float64, len(items))
	ids := make([]uint, 0, len(items))
	for _, it := range items {
		if _, seen := byID[it.VariantID]; !seen {
			ids = append(ids, it.VariantID)
		}
		byID[it.VariantID] = it.CostPrice
	}
	slices.Sort(ids)

	var changed int64
	err := r.db.WithContext(ctx).Transaction(func(tx *gorm.DB) error {
		var found []domain.ProductVariant
		if err := tx.Where("id IN ?", ids).Find(&found).Error; err != nil {
			return err
		}
		if len(found) != len(ids) {
			return domain.ErrNotFound
		}

		for _, vid := range ids {
			// Update theo cột chỉ định: dùng Save cả struct sẽ đè mọi cột khác của
			// biến thể bằng dữ liệu đã đọc từ trước, nuốt mất thay đổi song song.
			res := tx.Model(&domain.ProductVariant{}).
				Where("id = ?", vid).
				Update("cost_price", byID[vid])
			if res.Error != nil {
				return res.Error
			}
			changed += res.RowsAffected
		}
		return nil
	})
	if err != nil {
		if errors.Is(err, gorm.ErrRecordNotFound) {
			return 0, domain.ErrNotFound
		}
		return 0, err
	}
	return changed, nil
}

func (r *inventoryRepository) Adjust(ctx context.Context, items []domain.InventoryAdjustment, actorID uint) ([]domain.InventoryAdjustResult, error) {
	if len(items) == 0 {
		return nil, domain.ErrConflict
	}

	// Gộp theo biến thể trước khi khoá: cùng một biến thể gửi lên hai lần thì lần
	// sau phải tính trên kết quả của lần trước, không phải ghi đè nó.
	order := make([]uint, 0, len(items))
	byID := make(map[uint][]domain.InventoryAdjustment, len(items))
	for _, it := range items {
		if _, seen := byID[it.VariantID]; !seen {
			order = append(order, it.VariantID)
		}
		byID[it.VariantID] = append(byID[it.VariantID], it)
	}
	// Khoá theo ID tăng dần — cùng thứ tự với luồng đặt hàng/trả hàng nên hai bên
	// chạy song song không khoá chéo nhau.
	ids := slices.Clone(order)
	slices.Sort(ids)

	results := make([]domain.InventoryAdjustResult, 0, len(order))

	err := r.db.WithContext(ctx).Transaction(func(tx *gorm.DB) error {
		var variants []domain.ProductVariant
		if err := tx.Clauses(clause.Locking{Strength: "UPDATE"}).
			Where("id IN ?", ids).Order("id ASC").Find(&variants).Error; err != nil {
			return err
		}
		found := make(map[uint]domain.ProductVariant, len(variants))
		for _, v := range variants {
			found[v.ID] = v
		}

		for _, vid := range ids {
			v, ok := found[vid]
			if !ok {
				return domain.ErrNotFound
			}

			before := v.StockQuantity
			current := before
			for _, adj := range byID[vid] {
				next := current + adj.Quantity
				if adj.Mode == domain.StockModeSet {
					next = adj.Quantity
				}
				if next < 0 {
					// Kho không được âm: sổ kho là nguồn sự thật cho hàng đang có thật,
					// một con số âm ở đây sẽ lan sang mọi phép kiểm tra tồn kho khác.
					return domain.ErrOutOfStock
				}

				change := next - current
				if err := tx.Create(&domain.InventoryTransaction{
					ProductVariantID: vid,
					Type:             adj.Type,
					Quantity:         change,
					QuantityBefore:   current,
					QuantityAfter:    next,
					ReferenceType:    "manual",
					UnitCost:         adj.UnitCost,
					Note:             adj.Note,
					CreatedBy:        actorRef(actorID),
				}).Error; err != nil {
					return err
				}
				current = next
			}

			if current != before {
				if err := tx.Model(&domain.ProductVariant{}).
					Where("id = ?", vid).
					Update("stock_quantity", current).Error; err != nil {
					return err
				}
			}

			results = append(results, domain.InventoryAdjustResult{
				VariantID: vid,
				SKU:       v.SKU,
				Before:    before,
				After:     current,
				Change:    current - before,
			})
		}
		return nil
	})
	if err != nil {
		if errors.Is(err, gorm.ErrRecordNotFound) {
			return nil, domain.ErrNotFound
		}
		return nil, err
	}

	// Trả kết quả theo đúng thứ tự người dùng gửi lên, không theo thứ tự khoá.
	pos := make(map[uint]domain.InventoryAdjustResult, len(results))
	for _, res := range results {
		pos[res.VariantID] = res
	}
	out := make([]domain.InventoryAdjustResult, 0, len(order))
	for _, vid := range order {
		out = append(out, pos[vid])
	}
	return out, nil
}

// actorRef đổi id người thực hiện sang con trỏ; 0 nghĩa là không xác định được
// (bút toán vẫn phải ghi, chỉ là không gắn tên ai).
func actorRef(id uint) *uint {
	if id == 0 {
		return nil
	}
	return &id
}
