package repository

import (
	"context"
	"errors"
	"fmt"
	"slices"
	"strings"

	"gorm.io/gorm"
	"gorm.io/gorm/clause"

	"sass-api/internal/chinhanh"
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
func stockValueExpr(ton string) string {
	return "COALESCE(" + effectiveCostExpr + ", 0) * GREATEST(" + ton + ", 0)"
}

// tonChiNhanhExpr là TỒN ĐANG XÉT của trang tồn kho.
//
// Có chi nhánh đang làm việc thì đó là tồn CỦA CHI NHÁNH ĐÓ (bảng variant_stocks,
// nguồn sự thật từ migration 0005). Không có thì rơi về bản cộng của cả cửa hàng
// — đúng thứ cửa hàng một chi nhánh cần, và cũng đúng nghĩa "xem gộp mọi chi
// nhánh" của khách chuỗi khi họ chưa chọn kho nào.
//
// Cả trang tồn kho phải dùng CHUNG một biểu thức này: bộ lọc "sắp hết hàng", cột
// số lượng, phép sắp xếp và ô tổng giá trị kho mà đọc hai nguồn khác nhau thì
// người dùng lọc ra một danh sách rồi thấy số trong danh sách không khớp với
// điều kiện vừa lọc.
const (
	tonTheoChiNhanh = "COALESCE(vs.quantity, 0)"
	tonCaCuaHang    = "v.stock_quantity"
)

// tonExpr chọn biểu thức tồn theo ctx, và baseQuery gắn JOIN tương ứng.
func tonExpr(ctx context.Context) string {
	if _, ok := chinhanh.ID(ctx); ok {
		return tonTheoChiNhanh
	}

	return tonCaCuaHang
}

// baseQuery dựng phần FROM/JOIN dùng chung cho cả danh sách lẫn đếm.
//
// Sản phẩm đã xoá mềm bị loại hẳn: hàng của chúng không còn bán được nên đưa vào
// bảng tồn kho chỉ làm nhiễu con số.
func (r *inventoryRepository) baseQuery(ctx context.Context) *gorm.DB {
	q := r.db.WithContext(ctx).
		Table("product_variants AS v").
		Joins("JOIN products p ON p.id = v.product_id AND p.deleted_at IS NULL").
		Where("v.deleted_at IS NULL")

	// LEFT JOIN chứ không JOIN: biến thể chưa từng có hàng ở chi nhánh này thì
	// KHÔNG có dòng trong variant_stocks, và nó vẫn phải hiện ra với số 0 — đó
	// đúng là thứ người quản kho cần thấy để biết mà nhập về.
	if shopID, ok := chinhanh.ID(ctx); ok {
		// Ghép thẳng con số vào chuỗi thay vì dùng `?`: các truy vấn của trang này
		// gắn tham số ở CẢ Select (ngưỡng sắp hết hàng) lẫn Where, và GORM xếp
		// tham số theo thứ tự mệnh đề được THÊM chứ không theo thứ tự chúng xuất
		// hiện trong SQL — trộn vào là một tham số trôi sang chỗ khác, câu lệnh vẫn
		// chạy và trả về số 0 thay vì báo lỗi.
		//
		// An toàn vì shopID là uint đã qua middleware.ChiNhanhDangLam (đã tra sổ,
		// đã đối chiếu với cửa hàng): %d của một uint không sinh ra được ký tự nào
		// ngoài chữ số.
		q = q.Joins(fmt.Sprintf(
			"LEFT JOIN variant_stocks vs ON vs.product_variant_id = v.id AND vs.shop_id = %d", shopID))
	}

	return q
}

// applyFilter gắn các điều kiện lọc lên một truy vấn đã có sẵn FROM/JOIN.
func applyInventoryFilter(q *gorm.DB, f domain.InventoryFilter, ton string) *gorm.DB {
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
		q = q.Where(ton + " <= 0")
	case "low":
		q = q.Where(ton+" > 0 AND "+ton+" <= ?", f.LowStock)
	case "in":
		q = q.Where(ton+" > ?", f.LowStock)
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
func inventoryOrder(sort, ton string) string {
	switch sort {
	case "stock_desc":
		return ton + " DESC, v.id DESC"
	case "name_asc":
		return "p.name ASC, v.id ASC"
	case "name_desc":
		return "p.name DESC, v.id DESC"
	case "value_desc":
		return "stock_value DESC, v.id DESC"
	case "newest":
		return "v.id DESC"
	default: // stock_asc — mặc định đẩy hàng sắp hết lên đầu, đó mới là việc cần làm
		return ton + " ASC, v.id ASC"
	}
}

func (r *inventoryRepository) List(ctx context.Context, f domain.InventoryFilter) ([]domain.InventoryItem, int64, error) {
	var total int64
	ton := tonExpr(ctx)
	countQ := applyInventoryFilter(r.baseQuery(ctx), f, ton)
	if err := countQ.Count(&total).Error; err != nil {
		return nil, 0, err
	}
	if total == 0 {
		return []domain.InventoryItem{}, 0, nil
	}

	q := applyInventoryFilter(r.baseQuery(ctx), f, ton).
		Joins("LEFT JOIN categories c ON c.id = p.category_id").
		Joins("LEFT JOIN brands b ON b.id = p.brand_id").
		Select(`v.id AS variant_id, v.product_id, p.name AS product_name, p.slug,
			COALESCE(NULLIF(v.image, ''), NULLIF(p.thumbnail, ''), '') AS thumbnail,
			v.sku, v.size, v.color, COALESCE(p.kit_type, '') AS kit_type,
			COALESCE(c.name, '') AS category_name, COALESCE(b.name, '') AS brand_name,
			` + ton + ` AS stock_quantity, v.is_active, p.is_active AS product_active,
			` + effectivePriceExpr + ` AS price,
			` + effectiveCostExpr + ` AS cost_price,
			` + stockValueExpr(ton) + ` AS stock_value,
			(SELECT MAX(t.created_at) FROM inventory_transactions t
			  WHERE t.product_variant_id = v.id) AS last_moved_at`).
		Order(inventoryOrder(f.Sort, ton)).
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
	ton := tonExpr(ctx)
	err := r.baseQuery(ctx).
		Select(`COUNT(*) AS total_variants,
			COALESCE(SUM(GREATEST(`+ton+`, 0)), 0) AS total_quantity,
			COALESCE(SUM(CASE WHEN `+ton+` > ? THEN 1 ELSE 0 END), 0) AS in_stock,
			COALESCE(SUM(CASE WHEN `+ton+` > 0 AND `+ton+` <= ? THEN 1 ELSE 0 END), 0) AS low_stock,
			COALESCE(SUM(CASE WHEN `+ton+` <= 0 THEN 1 ELSE 0 END), 0) AS out_of_stock,
			COALESCE(SUM(`+stockValueExpr(ton)+`), 0) AS stock_value,
			COALESCE(SUM(CASE WHEN `+effectiveCostExpr+` IS NULL THEN 1 ELSE 0 END), 0) AS missing_cost,
			COALESCE(SUM(CASE WHEN `+effectiveCostExpr+` IS NULL AND `+ton+` > 0
			    THEN 1 ELSE 0 END), 0) AS missing_cost_in_stock`,
			lowStock, lowStock).
		Scan(&s).Error
	return s, err
}

func (r *inventoryRepository) FindItem(ctx context.Context, variantID uint) (*domain.InventoryItem, error) {
	ton := tonExpr(ctx)
	var items []domain.InventoryItem
	err := r.baseQuery(ctx).
		Joins("LEFT JOIN categories c ON c.id = p.category_id").
		Joins("LEFT JOIN brands b ON b.id = p.brand_id").
		Where("v.id = ?", variantID).
		Select(`v.id AS variant_id, v.product_id, p.name AS product_name, p.slug,
			COALESCE(NULLIF(v.image, ''), NULLIF(p.thumbnail, ''), '') AS thumbnail,
			v.sku, v.size, v.color, COALESCE(p.kit_type, '') AS kit_type,
			COALESCE(c.name, '') AS category_name, COALESCE(b.name, '') AS brand_name,
			` + ton + ` AS stock_quantity, v.is_active, p.is_active AS product_active,
			` + effectivePriceExpr + ` AS price,
			` + effectiveCostExpr + ` AS cost_price,
			` + stockValueExpr(ton) + ` AS stock_value,
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

	// Chỉnh kho luôn là việc của MỘT chi nhánh cụ thể: người bấm nút đang đứng ở
	// một kho và đếm hàng trong kho đó. Không xác định được chi nhánh thì dừng —
	// cộng số hàng vừa đếm vào một kho do máy đoán là cách nhanh nhất để sổ và
	// hàng thật lệch nhau.
	shopID, err := chiNhanhCuaRequest(ctx, r.db)
	if err != nil {
		return nil, err
	}

	err = r.db.WithContext(ctx).Transaction(func(tx *gorm.DB) error {
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

			// before/after ĐỌC THEO CHI NHÁNH, không theo bản cộng của cả cửa hàng:
			// người dùng vừa đếm kho của mình, nên con số họ thấy phải là con số kho
			// đó — xem ghiTonChiNhanh.
			before, _, err := ghiTonChiNhanh(tx, shopID, vid, 0, true)
			if err != nil {
				return err
			}

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
				if _, _, err := ghiTonChiNhanh(tx, shopID, vid, change, false); err != nil {
					return err
				}
				if err := tx.Create(&domain.InventoryTransaction{
					ShopID:           shopID,
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
