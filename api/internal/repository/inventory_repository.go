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
	// Tồn của CẢ CỬA HÀNG cộng thẳng từ variant_stocks. Trước đây đọc cột
	// `v.stock_quantity` — một bản cộng do repository ghi lại sau mỗi lần đụng
	// kho. Cột đó đã bỏ: hai chỗ cùng giữ một sự thật thì sớm muộn lệch nhau, mà
	// cái lệch ấy chỉ lộ ra lúc có người đếm hàng thật.
	tonCaCuaHang = `COALESCE((SELECT SUM(vs2.quantity) FROM variant_stocks vs2
		WHERE vs2.product_variant_id = v.id), 0)`
)

// tonExpr chọn biểu thức tồn theo ctx, và baseQuery gắn JOIN tương ứng.
func tonExpr(ctx context.Context) string {
	if _, ok := chinhanh.ID(ctx); ok {
		return tonTheoChiNhanh
	}

	return tonCaCuaHang
}

// lanCuoiExpr là "phát sinh cuối" của một biến thể — và nó phải hỏi ĐÚNG cái kho
// mà cột tồn bên cạnh đang nói tới.
//
// Lấy MAX của cả cửa hàng trong khi bảng đang hiển thị tồn của một chi nhánh thì
// cột này báo "hôm qua có phát sinh" cho một kho đã đứng im ba tháng — người
// quản kho đọc xong tưởng hàng vẫn đang luân chuyển và bỏ qua đúng chỗ cần dọn.
func lanCuoiExpr(ctx context.Context) string {
	if shopID, ok := chinhanh.ID(ctx); ok {
		return fmt.Sprintf(`(SELECT MAX(t.created_at) FROM inventory_transactions t
			  WHERE t.product_variant_id = v.id AND t.shop_id = %d) AS last_moved_at`, shopID)
	}

	return `(SELECT MAX(t.created_at) FROM inventory_transactions t
			  WHERE t.product_variant_id = v.id) AS last_moved_at`
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
		// Đơn vị tính đi kèm để màn kho ghi được "12 Hộp" chứ không phải một con
		// số trần. LEFT JOIN: mặt hàng chưa khai đơn vị vẫn phải hiện ra.
		Joins("LEFT JOIN product_units u ON u.id = p.unit_id").
		Select(`v.id AS variant_id, v.product_id, p.name AS product_name, p.slug,
			COALESCE(NULLIF(v.image, ''), NULLIF(p.thumbnail, ''), '') AS thumbnail,
			v.sku, COALESCE(v.name, '') AS variant_name,
			COALESCE(u.name, '') AS unit_name,
			COALESCE(c.name, '') AS category_name,
			` + ton + ` AS stock_quantity, v.is_active, p.is_active AS product_active,
			` + effectivePriceExpr + ` AS price,
			` + effectiveCostExpr + ` AS cost_price,
			` + stockValueExpr(ton) + ` AS stock_value,
			` + lanCuoiExpr(ctx)).
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
		Joins("LEFT JOIN product_units u ON u.id = p.unit_id").
		Where("v.id = ?", variantID).
		Select(`v.id AS variant_id, v.product_id, p.name AS product_name, p.slug,
			COALESCE(NULLIF(v.image, ''), NULLIF(p.thumbnail, ''), '') AS thumbnail,
			v.sku, COALESCE(v.name, '') AS variant_name,
			COALESCE(u.name, '') AS unit_name,
			COALESCE(c.name, '') AS category_name,
			` + ton + ` AS stock_quantity, v.is_active, p.is_active AS product_active,
			` + effectivePriceExpr + ` AS price,
			` + effectiveCostExpr + ` AS cost_price,
			` + stockValueExpr(ton) + ` AS stock_value,
			` + lanCuoiExpr(ctx)).
		Limit(1).Scan(&items).Error
	if err != nil {
		return nil, err
	}
	if len(items) == 0 {
		return nil, domain.ErrNotFound
	}
	return &items[0], nil
}

// khoDangXet trả về chi nhánh mà một lượt ĐỌC phải cắt theo.
//
// Thứ tự: chi nhánh gọi thẳng (màn "Tồn kho chi nhánh" xem sổ của đúng một kho)
// → chi nhánh đang làm việc trong ctx → không cắt (xem gộp cả cửa hàng).
//
// Khác hẳn chiToNhanhCuaRequest bên đường GHI, chỗ đó không được phép "không
// biết kho nào" nên phải rơi về chi nhánh bán online. Đọc thì "không cắt" là một
// câu trả lời hợp lệ và đúng ý người dùng khi họ chọn "Tất cả chi nhánh".
func khoDangXet(ctx context.Context, shopID uint) (uint, bool) {
	if shopID != 0 {
		return shopID, true
	}

	return chinhanh.ID(ctx)
}

func (r *inventoryRepository) Histories(ctx context.Context, variantID, shopID uint, page, pageSize int) ([]domain.InventoryHistory, int64, error) {
	// SỔ KHO PHẢI CẮT THEO ĐÚNG CÁI KHO ĐANG HIỂN THỊ. Không cắt thì người đang
	// xem kho Quận 7 (tồn 3) mở lịch sử ra lại thấy bút toán của Quận 1 với "trước
	// 40 → sau 41": cặp số ấy đúng với kho kia và mâu thuẫn với con số ngay trên
	// màn hình, mà không có gì trên giao diện nói vì sao.
	kho, coKho := khoDangXet(ctx, shopID)
	loc := func(q *gorm.DB) *gorm.DB {
		q = q.Where("t.product_variant_id = ?", variantID)
		if coKho {
			q = q.Where("t.shop_id = ?", kho)
		}

		return q
	}

	var total int64
	if err := loc(r.db.WithContext(ctx).Table("inventory_transactions AS t")).Count(&total).Error; err != nil {
		return nil, 0, err
	}
	if total == 0 {
		return []domain.InventoryHistory{}, 0, nil
	}

	// Mã chứng từ lấy theo đúng loại tham chiếu: cùng một reference_id có thể vừa
	// là id đơn hàng vừa là id phiếu trả, nên phải kèm điều kiện reference_type ở
	// từng JOIN thay vì COALESCE bừa hai bảng.
	rows := make([]domain.InventoryHistory, 0, pageSize)
	err := loc(r.db.WithContext(ctx).
		Table("inventory_transactions AS t").
		Joins("LEFT JOIN users u ON u.id = t.created_by").
		Joins("LEFT JOIN shops sh ON sh.id = t.shop_id").
		Joins("LEFT JOIN orders o ON o.id = t.reference_id AND t.reference_type = 'order'").
		Joins("LEFT JOIN order_returns rt ON rt.id = t.reference_id AND t.reference_type = 'order_return'").
		Joins("LEFT JOIN purchase_orders po ON po.id = t.reference_id AND t.reference_type = 'purchase_order'")).
		Select(`t.id, t.shop_id, COALESCE(sh.name, '') AS shop_name,
			t.type, t.quantity, t.quantity_before, t.quantity_after,
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

	// Chỉnh kho luôn là việc của MỘT chi nhánh cụ thể: người bấm nút vừa đếm hàng
	// trong một kho. Dòng nào không nói rõ kho thì rơi về chi nhánh đang làm việc
	// — không xác định được thì dừng, vì cộng số vừa đếm vào một kho do máy đoán
	// là cách nhanh nhất để sổ và hàng thật lệch nhau.
	macDinh, err := chiNhanhCuaRequest(ctx, r.db)
	if err != nil {
		return nil, err
	}

	// Gộp theo CẶP (chi nhánh, biến thể) trước khi khoá: gửi cùng một biến thể hai
	// lần cho CÙNG một kho thì lần sau phải tính trên kết quả của lần trước; còn
	// hai kho khác nhau là hai con số độc lập, gộp lại là trộn hàng của hai nơi.
	type khoaDong struct{ shop, variant uint }
	order := make([]khoaDong, 0, len(items))
	byKey := make(map[khoaDong][]domain.InventoryAdjustment, len(items))
	kho := make(map[uint]bool, 4)
	for _, it := range items {
		k := khoaDong{shop: it.ShopID, variant: it.VariantID}
		if k.shop == 0 {
			k.shop = macDinh
		}
		if _, seen := byKey[k]; !seen {
			order = append(order, k)
		}
		byKey[k] = append(byKey[k], it)
		kho[k.shop] = true
	}

	// Chi nhánh do client gửi lên phải THUỘC CỬA HÀNG NÀY. Không kiểm thì một id
	// gõ tay của tiệm khác vẫn ghi được một dòng variant_stocks mang tenant của ta
	// nhưng trỏ vào kho của họ — khoá ngoại sang `shops` không chặn được, vì nó chỉ
	// hỏi "chi nhánh có tồn tại không" chứ không hỏi "của ai".
	for shopID := range kho {
		if shopID == macDinh {
			continue
		}
		var co int64
		if err := r.db.WithContext(ctx).Model(&domain.ChiNhanh{}).
			Where("id = ?", shopID).Count(&co).Error; err != nil {
			return nil, err
		}
		if co == 0 {
			return nil, domain.ErrNotFound
		}
	}

	// Khoá theo ID biến thể tăng dần — cùng thứ tự với luồng đặt hàng/trả hàng nên
	// hai bên chạy song song không khoá chéo nhau.
	ids := make([]uint, 0, len(order))
	daCo := make(map[uint]bool, len(order))
	for _, k := range order {
		if !daCo[k.variant] {
			daCo[k.variant] = true
			ids = append(ids, k.variant)
		}
	}
	slices.Sort(ids)

	results := make([]domain.InventoryAdjustResult, 0, len(order))

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

		for _, k := range order {
			vid := k.variant
			v, ok := found[vid]
			if !ok {
				return domain.ErrNotFound
			}
			shopID := k.shop

			// before/after ĐỌC THEO CHI NHÁNH, không theo bản cộng của cả cửa hàng:
			// người dùng vừa đếm kho của mình, nên con số họ thấy phải là con số kho
			// đó — xem ghiTonChiNhanh.
			before, _, err := ghiTonChiNhanh(tx, shopID, vid, 0, true)
			if err != nil {
				return err
			}

			current := before
			for _, adj := range byKey[k] {
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
				ShopID:    shopID,
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

	// Không cần xếp lại: vòng ghi ở trên đi theo `order`, tức là đúng thứ tự người
	// dùng gửi lên. (Việc khoá dòng mới đi theo id tăng dần, và nó nằm ở câu SELECT
	// riêng phía trước.)
	return results, nil
}

// TonTheoChiNhanh dựng lưới (chi nhánh × biến thể) cho màn "Tồn kho chi nhánh".
//
// VÌ SAO LẤY DANH SÁCH CHI NHÁNH BẰNG MỘT CÂU RIÊNG rồi mới ghép vào câu chính:
// bảng này phải hiện cả những ô KHÔNG có dòng trong variant_stocks — chi nhánh
// chưa từng nhập món đó vẫn phải đứng đó với số 0, vì đấy đúng là dòng người đi
// nhập hàng cần nhìn thấy. Muốn vậy phải NHÂN chi nhánh với biến thể, tức là một
// phép ghép không có khoá nối. Mà bộ lọc tenant của GORM chỉ chèn điều kiện cho
// BẢNG CHÍNH của câu truy vấn (xem tenant_scope.go), nên ghép thẳng bảng `shops`
// vào đây là mở đường cho chi nhánh của khách khác lọt vào lưới.
//
// Lấy trước bằng một câu CÓ lọc tenant thì id thu được đã chắc chắn thuộc đúng
// cửa hàng, ghép vào câu chính bằng %d là an toàn: uint không sinh ra được ký tự
// nào ngoài chữ số. Tên và mã chi nhánh cũng ghép ở Go luôn, khỏi phải JOIN lại
// bảng shops lần nữa.
func (r *inventoryRepository) TonTheoChiNhanh(ctx context.Context, f domain.TonChiNhanhFilter) (domain.KetQuaTonChiNhanh, error) {
	out := domain.KetQuaTonChiNhanh{
		Dong:     []domain.DongTonChiNhanh{},
		ChiNhanh: []domain.TomTatChiNhanh{},
	}

	shopQ := r.db.WithContext(ctx).Model(&domain.ChiNhanh{}).Order("id ASC")
	if len(f.ShopIDs) > 0 {
		// Chọn đích danh thì lấy đúng những chi nhánh đó, KỂ CẢ chi nhánh đã đóng:
		// hàng còn kẹt trong một điểm bán vừa đóng là thứ người ta cần tra nhất.
		shopQ = shopQ.Where("id IN ?", f.ShopIDs)
	} else {
		shopQ = shopQ.Where("is_active = ?", true)
	}

	var shops []domain.ChiNhanh
	if err := shopQ.Find(&shops).Error; err != nil {
		return out, err
	}
	if len(shops) == 0 {
		return out, nil
	}

	nhanh := make([]string, 0, len(shops))
	for _, cn := range shops {
		nhanh = append(nhanh, fmt.Sprintf("SELECT %d AS shop_id", cn.ID))
	}

	// Tồn ở đây LUÔN là tồn của một chi nhánh cụ thể — khác trang Tồn kho, nơi
	// biểu thức đổi theo chi nhánh đang làm việc. Màn này nhìn nhiều chi nhánh
	// cùng lúc nên "bản cộng cả cửa hàng" không có chỗ đứng.
	const ton = tonTheoChiNhanh

	base := func() *gorm.DB {
		return r.db.WithContext(ctx).
			Table("product_variants AS v").
			Joins("JOIN products p ON p.id = v.product_id AND p.deleted_at IS NULL").
			Joins("JOIN (" + strings.Join(nhanh, " UNION ALL ") + ") s ON 1 = 1").
			Joins("LEFT JOIN variant_stocks vs ON vs.product_variant_id = v.id AND vs.shop_id = s.shop_id").
			Where("v.deleted_at IS NULL")
	}

	// Dùng lại đúng bộ điều kiện của trang Tồn kho: hai màn cùng nói về một thứ
	// nên "sắp hết" phải nghĩa giống nhau ở cả hai chỗ.
	loc := domain.InventoryFilter{
		Keyword:    f.Keyword,
		CategoryID: f.CategoryID,
		Stock:      f.Stock,
		LowStock:   f.LowStock,
	}

	var total int64
	if err := applyInventoryFilter(base(), loc, ton).Count(&total).Error; err != nil {
		return out, err
	}
	out.Total = total

	// Tổng của TỪNG chi nhánh tính trên toàn bộ bộ lọc chứ không trên trang đang
	// xem — đầu mỗi nhóm ghi "Chi nhánh A (137)", mà 137 tụt xuống 20 khi lật
	// trang thì người đọc hiểu là kho vừa mất hàng.
	type gop struct {
		ShopID  uint
		SoDong  int64
		TongTon int64
		GiaTri  float64
	}
	var gops []gop
	err := applyInventoryFilter(base(), loc, ton).
		Select("s.shop_id AS shop_id, COUNT(*) AS so_dong, " +
			"COALESCE(SUM(" + ton + "), 0) AS tong_ton, " +
			"COALESCE(SUM(" + stockValueExpr(ton) + "), 0) AS gia_tri").
		Group("s.shop_id").
		Scan(&gops).Error
	if err != nil {
		return out, err
	}

	theoID := make(map[uint]gop, len(gops))
	for _, g := range gops {
		theoID[g.ShopID] = g
	}
	ten := make(map[uint]domain.ChiNhanh, len(shops))
	for _, cn := range shops {
		ten[cn.ID] = cn
		g := theoID[cn.ID]
		out.ChiNhanh = append(out.ChiNhanh, domain.TomTatChiNhanh{
			ShopID:   cn.ID,
			ShopCode: cn.Code,
			ShopName: cn.Name,
			SoDong:   g.SoDong,
			TongTon:  g.TongTon,
			GiaTri:   g.GiaTri,
		})
	}

	if total == 0 {
		return out, nil
	}

	// Chi nhánh đứng trước mọi kiểu sắp xếp khác: bảng gom theo nhóm chi nhánh
	// nên hàng của một kho phải nằm liền nhau, nếu không tiêu đề nhóm sẽ lặp lại
	// giữa trang.
	q := applyInventoryFilter(base(), loc, ton).
		Joins("LEFT JOIN categories c ON c.id = p.category_id").
		Joins("LEFT JOIN product_units u ON u.id = p.unit_id").
		Select(`s.shop_id, v.id AS variant_id, v.product_id, v.sku,
			p.name AS product_name, COALESCE(v.name, '') AS variant_name,
			COALESCE(u.name, '') AS unit_name, COALESCE(c.name, '') AS category_name,
			COALESCE(NULLIF(v.image, ''), NULLIF(p.thumbnail, ''), '') AS thumbnail,
			` + ton + ` AS quantity, v.is_active,
			` + effectiveCostExpr + ` AS cost_price,
			` + stockValueExpr(ton) + ` AS stock_value`).
		Order("s.shop_id ASC, " + inventoryOrder(f.Sort, ton)).
		Limit(f.PageSize).
		Offset((f.Page - 1) * f.PageSize)

	dong := make([]domain.DongTonChiNhanh, 0, f.PageSize)
	if err := q.Scan(&dong).Error; err != nil {
		return out, err
	}
	for i := range dong {
		cn := ten[dong[i].ShopID]
		dong[i].ShopCode = cn.Code
		dong[i].ShopName = cn.Name
	}
	out.Dong = dong

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
