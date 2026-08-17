package repository

import (
	"context"
	"errors"
	"fmt"
	"slices"
	"strings"
	"time"

	"sass-api/internal/domain"

	"gorm.io/gorm"
	"gorm.io/gorm/clause"
)

type orderRepository struct{ db *gorm.DB }

func NewOrderRepository(db *gorm.DB) domain.OrderRepository {
	return &orderRepository{db: db}
}

// splitCSV tách chuỗi "a,b,c" thành danh sách đã bỏ khoảng trắng và phần tử rỗng.
func splitCSV(s string) []string {
	parts := strings.Split(s, ",")
	out := make([]string, 0, len(parts))
	for _, p := range parts {
		if v := strings.TrimSpace(p); v != "" {
			out = append(out, v)
		}
	}
	return out
}

// orderStockLedger tổng hợp số lượng đã xuất/nhập kho cho một đơn, đọc từ sổ kho
// (nguồn sự thật). Giá trị âm = đang giữ hàng của kho, 0 = đơn chưa từng trừ kho.
func orderStockLedger(tx *gorm.DB, orderID uint) (map[uint]int, error) {
	var rows []struct {
		ProductVariantID uint
		Total            int
	}
	err := tx.Model(&domain.InventoryTransaction{}).
		Select("product_variant_id, COALESCE(SUM(quantity), 0) AS total").
		Where("reference_type = ? AND reference_id = ?", "order", orderID).
		Group("product_variant_id").
		Scan(&rows).Error
	if err != nil {
		return nil, err
	}
	ledger := make(map[uint]int, len(rows))
	for _, row := range rows {
		ledger[row.ProductVariantID] = row.Total
	}
	return ledger, nil
}

// orderDesiredStock là số lượng từng biến thể mà đơn ĐANG giữ của kho.
func orderDesiredStock(items []domain.OrderItem) map[uint]int {
	desired := make(map[uint]int, len(items))
	for _, it := range items {
		if it.ProductVariantID == nil || it.Quantity == 0 {
			continue
		}
		desired[*it.ProductVariantID] += it.Quantity
	}
	return desired
}

// syncOrderStock kéo tồn kho về đúng với số lượng đơn đang giữ (desired), dựa trên
// chênh lệch so với những gì đơn ĐÃ ghi trong sổ kho.
//
// Cách này tự đúng cho mọi luồng: tạo đơn thủ công (sổ trống → trừ đủ), sửa đơn
// (chỉ trừ/hoàn phần chênh), huỷ hoặc hoàn hàng (desired rỗng → trả lại đúng số đã
// lấy). Đơn cũ chưa từng trừ kho thì khi huỷ cũng không cộng khống vào kho.
//
// Biến thể được khoá theo thứ tự ID tăng dần (SELECT ... FOR UPDATE) để hai thao
// tác chạm cùng tập biến thể không khoá chéo nhau.
//
// Mọi thay đổi tồn kho được ghi lại vào o.StockMoves để service bắn cảnh báo sắp
// hết hàng sau khi commit. Hàm trả lỗi thì transaction rollback, o.StockMoves lúc
// đó là số chưa từng có thật — chỉ được đọc khi thao tác thành công.
func syncOrderStock(tx *gorm.DB, o *domain.Order, desired map[uint]int, txType, note string) error {
	o.StockMoves = nil

	ledger, err := orderStockLedger(tx, o.ID)
	if err != nil {
		return err
	}

	// Sổ ghi số âm khi xuất, nên số cần có sau đồng bộ là -desired.
	deltas := make(map[uint]int, len(desired)+len(ledger))
	for vid, qty := range desired {
		deltas[vid] = -qty - ledger[vid]
	}
	for vid, moved := range ledger {
		if _, ok := desired[vid]; !ok {
			deltas[vid] = -moved
		}
	}

	ids := make([]uint, 0, len(deltas))
	for vid, d := range deltas {
		if d != 0 {
			ids = append(ids, vid)
		}
	}
	if len(ids) == 0 {
		return nil
	}
	slices.Sort(ids)

	// Unscoped: biến thể có thể đã bị xoá mềm sau khi đơn được đặt. Vẫn phải trả
	// hàng của nó về kho, nếu không admin sẽ không huỷ được đơn.
	var variants []domain.ProductVariant
	if err := tx.Unscoped().Clauses(clause.Locking{Strength: "UPDATE"}).
		Where("id IN ?", ids).Order("id ASC").Find(&variants).Error; err != nil {
		return err
	}
	if len(variants) != len(ids) {
		return domain.ErrVariantNotFound
	}

	// Kho bị trừ là kho của CHI NHÁNH BÁN ĐƠN NÀY, chốt lúc đặt (xem
	// domain.Order.ShopID). Không lấy theo chi nhánh người đang thao tác: một
	// quản trị viên đứng ở kho Quận 7 mở lại đơn của Quận 1 để sửa thì hàng phải
	// đi ra khỏi Quận 1, nếu không hai kho cùng lệch.
	//
	// Đơn cũ (trước 0005) có shop_id do migration dồn về chi nhánh gốc, nên nhánh
	// 0 dưới đây chỉ xảy ra với dòng dựng bằng tay — rơi về chi nhánh của request
	// vẫn đúng cửa hàng, và tổng kho không sai.
	shopID := o.ShopID
	if shopID == 0 {
		var err error
		if shopID, err = chiNhanhCuaRequest(tx.Statement.Context, tx); err != nil {
			return err
		}
	}

	for _, v := range variants {
		delta := deltas[v.ID]
		// Nhưng không cho LẤY THÊM hàng từ biến thể đã ngừng bán.
		if delta < 0 && v.DeletedAt.Valid {
			return domain.ErrVariantNotFound
		}

		before, after, err := ghiTonChiNhanh(tx, shopID, v.ID, delta, false)
		if err != nil {
			if errors.Is(err, domain.ErrOutOfStock) {
				name, lerr := variantLabel(tx, v)
				if lerr != nil {
					return lerr
				}

				return fmt.Errorf("%w: %s (còn %d, cần thêm %d)", domain.ErrOutOfStock, name, before, -delta-before)
			}

			return err
		}

		orderID := o.ID
		if err := tx.Create(&domain.InventoryTransaction{
			ShopID:           shopID,
			ProductVariantID: v.ID,
			Type:             txType,
			Quantity:         delta,
			QuantityBefore:   before,
			QuantityAfter:    after,
			ReferenceType:    "order",
			ReferenceID:      &orderID,
			Note:             strings.TrimSpace(note + " " + o.OrderCode),
		}).Error; err != nil {
			return err
		}

		o.StockMoves = append(o.StockMoves, domain.StockMove{
			VariantID: v.ID,
			SKU:       v.SKU,
			Before:    before,
			After:     after,
		})
	}
	return nil
}

// soldCounted cho biết đơn ở trạng thái này có được tính vào lượt bán của sản
// phẩm hay không. Chỉ tính từ lúc hàng đã tới tay khách; đơn còn đang xử lý hoặc
// đang giao chưa chắc thành công nên không tính.
func soldCounted(status string) bool {
	switch status {
	case domain.OrderStatusDelivered, domain.OrderStatusCompleted:
		return true
	default:
		return false
	}
}

// syncSoldCount cộng/trừ products.sold_count khi đơn bước vào hoặc rời khỏi nhóm
// trạng thái được tính là đã bán.
//
// Luồng trạng thái chỉ đi một chiều và mỗi lần chuyển đều chạy dưới khoá dòng
// đơn, nên mỗi đơn cộng nhiều nhất một lần (shipping -> delivered) và trừ lại
// đúng một lần nếu khách trả hàng (delivered -> returned). Đơn bị hoàn từ lúc
// đang giao thì chưa từng được cộng nên cũng không bị trừ.
func syncSoldCount(tx *gorm.DB, o *domain.Order, from, to string) error {
	was, now := soldCounted(from), soldCounted(to)
	if was == now {
		return nil
	}

	// Gom theo sản phẩm: một đơn có thể mua nhiều biến thể của cùng một sản phẩm.
	qty := make(map[uint]int, len(o.Items))
	for _, it := range o.Items {
		if it.ProductID == nil || it.Quantity <= 0 {
			continue
		}
		qty[*it.ProductID] += it.Quantity
	}

	ids := make([]uint, 0, len(qty))
	for id := range qty {
		ids = append(ids, id)
	}
	slices.Sort(ids)

	for _, id := range ids {
		var expr clause.Expr
		if now {
			expr = gorm.Expr("sold_count + ?", qty[id])
		} else {
			// sold_count là UNSIGNED: chặn ở 0 để không bị tràn âm khi dữ liệu cũ
			// (đơn giao trước khi có tính năng này) chưa từng được cộng.
			expr = gorm.Expr("GREATEST(CAST(sold_count AS SIGNED) - ?, 0)", qty[id])
		}
		// Unscoped: sản phẩm có thể đã bị xoá mềm sau khi khách đặt, vẫn phải chỉnh
		// đúng số liệu của nó.
		if err := tx.Unscoped().Model(&domain.Product{}).
			Where("id = ?", id).
			Update("sold_count", expr).Error; err != nil {
			return err
		}
	}
	return nil
}

// variantLabel dựng tên hiển thị "Sản phẩm (size / màu)" cho thông báo thiếu hàng.
func variantLabel(tx *gorm.DB, v domain.ProductVariant) (string, error) {
	var name string
	err := tx.Table("products").Select("name").Where("id = ?", v.ProductID).Scan(&name).Error
	if err != nil {
		return "", err
	}
	if strings.TrimSpace(name) == "" {
		name = v.SKU
	}
	opts := make([]string, 0, 2)
	if v.Size != "" {
		opts = append(opts, v.Size)
	}
	if v.Color != "" {
		opts = append(opts, v.Color)
	}
	if len(opts) > 0 {
		name += " (" + strings.Join(opts, " / ") + ")"
	}
	return name, nil
}

func (r *orderRepository) List(ctx context.Context, f domain.OrderFilter) ([]domain.Order, int64, error) {
	q := r.db.WithContext(ctx).Model(&domain.Order{})

	if f.Keyword != "" {
		kw := "%" + f.Keyword + "%"
		// Bọc trong một Where duy nhất để nhóm OR không nuốt mất các điều kiện khác.
		q = q.Where("(order_code LIKE ? OR recipient_name LIKE ? OR recipient_phone LIKE ? OR recipient_email LIKE ?)",
			kw, kw, kw, kw)
	}
	if f.Status != "" && f.Status != "all" {
		// Cho phép lọc nhiều trạng thái cùng lúc: "pending,confirmed,shipping".
		if list := splitCSV(f.Status); len(list) > 1 {
			q = q.Where("status IN ?", list)
		} else {
			q = q.Where("status = ?", f.Status)
		}
	}
	if f.PaymentStatus != "" && f.PaymentStatus != "all" {
		q = q.Where("payment_status = ?", f.PaymentStatus)
	}
	if f.PaymentMethod != "" && f.PaymentMethod != "all" {
		q = q.Where("payment_method = ?", f.PaymentMethod)
	}
	if f.Channel != "" && f.Channel != "all" {
		q = q.Where("channel = ?", f.Channel)
	}
	if f.UserID != nil {
		q = q.Where("user_id = ?", *f.UserID)
	}
	if f.FromDate != "" {
		q = q.Where("created_at >= ?", f.FromDate+" 00:00:00")
	}
	if f.ToDate != "" {
		q = q.Where("created_at <= ?", f.ToDate+" 23:59:59")
	}

	var total int64
	if err := q.Count(&total).Error; err != nil {
		return nil, 0, err
	}

	switch f.Sort {
	case "oldest":
		q = q.Order("id ASC")
	case "total_desc":
		q = q.Order("total_amount DESC")
	case "total_asc":
		q = q.Order("total_amount ASC")
	default:
		q = q.Order("id DESC")
	}

	page := f.Page
	if page < 1 {
		page = 1
	}
	pageSize := f.PageSize
	if pageSize < 1 {
		pageSize = 20
	}
	q = q.Offset((page - 1) * pageSize).Limit(pageSize)

	var orders []domain.Order
	// Preload Items để bảng danh sách hiển thị được số lượng sản phẩm mỗi đơn.
	err := q.Preload("Items").Find(&orders).Error
	return orders, total, err
}

func (r *orderRepository) FindByID(ctx context.Context, id uint) (*domain.Order, error) {
	var o domain.Order
	err := r.db.WithContext(ctx).Preload("Items").First(&o, id).Error
	if errors.Is(err, gorm.ErrRecordNotFound) {
		return nil, domain.ErrNotFound
	}
	return &o, err
}

func (r *orderRepository) FindByCode(ctx context.Context, code string) (*domain.Order, error) {
	var o domain.Order
	err := r.db.WithContext(ctx).Preload("Items").Where("order_code = ?", code).First(&o).Error
	if errors.Is(err, gorm.ErrRecordNotFound) {
		return nil, domain.ErrNotFound
	}
	return &o, err
}

func (r *orderRepository) UserExists(ctx context.Context, id uint) (bool, error) {
	var count int64
	err := r.db.WithContext(ctx).
		Table("users").
		Where("id = ? AND deleted_at IS NULL", id).
		Count(&count).Error
	return count > 0, err
}

// Create tạo đơn (kèm items) trong một transaction. Mã đơn tạm dùng thời gian để
// tránh đụng ràng buộc UNIQUE khi insert, sau đó đổi thành mã hiển thị theo ID.
// Kho bị trừ ngay trong transaction này — thiếu hàng thì cả đơn bị huỷ bỏ.
func (r *orderRepository) Create(ctx context.Context, o *domain.Order) error {
	// Chi nhánh bán đơn này, chốt NGAY LÚC ĐẶT và không đổi nữa: người bán đang
	// đứng ở đâu (đơn tại quầy), hoặc chi nhánh bán online (đơn của khách vãng
	// lai — ctx của họ không mang chi nhánh nào). Mọi lượt trừ/hoàn kho về sau
	// đều đọc con số này, nên nó phải đúng từ dòng đầu tiên.
	shopID, err := chiNhanhCuaRequest(ctx, r.db)
	if err != nil {
		return err
	}
	o.ShopID = shopID

	return r.db.WithContext(ctx).Transaction(func(tx *gorm.DB) error {
		o.OrderCode = fmt.Sprintf("TMP%d", time.Now().UnixNano())
		if err := tx.Create(o).Error; err != nil {
			return err
		}

		o.OrderCode = fmt.Sprintf("DH%s%04d", time.Now().Format("20060102"), o.ID)
		if err := tx.Model(o).Update("order_code", o.OrderCode).Error; err != nil {
			return err
		}

		if err := syncOrderStock(tx, o, orderDesiredStock(o.Items), "export", "Tạo đơn thủ công"); err != nil {
			return err
		}

		history := &domain.OrderStatusHistory{
			OrderID:    o.ID,
			FromStatus: "",
			ToStatus:   o.Status,
			Note:       "Tạo đơn thủ công",
		}
		return tx.Create(history).Error
	})
}

// loadCheckoutVariants tra biến thể + GIÁ HIỆN TẠI cho từng dòng giỏ hàng, theo
// product_variant_id nếu client biết, không thì theo slug + size + màu.
//
// Hàm này bỏ qua (không báo lỗi) những dòng không tra được — sản phẩm đã ẩn, đã
// xoá hoặc biến thể không còn: nơi gọi tự quyết định coi đó là lỗi (đặt hàng) hay
// chỉ là một dòng "không còn bán" cần báo cho khách (đối chiếu giỏ).
//
// lock = true thì khoá các dòng biến thể (SELECT ... FOR UPDATE) — bắt buộc khi
// sắp trừ kho, để hai khách bấm đặt cùng lúc không cùng thấy "còn 1 cái".
func loadCheckoutVariants(tx *gorm.DB, lines []domain.CheckoutLine, lock bool) (map[uint]domain.CheckoutVariant, error) {
	ids := make([]uint, 0, len(lines))
	for _, l := range lines {
		if l.VariantID > 0 {
			ids = append(ids, l.VariantID)
		}
	}
	for _, l := range lines {
		if l.VariantID > 0 || strings.TrimSpace(l.Slug) == "" {
			continue
		}
		var v domain.ProductVariant
		q := tx.Joins("JOIN products p ON p.id = product_variants.product_id").
			Where("p.slug = ? AND p.deleted_at IS NULL AND p.is_active = 1", strings.TrimSpace(l.Slug)).
			Where("product_variants.is_active = 1")
		if s := strings.TrimSpace(l.Size); s != "" {
			q = q.Where("product_variants.size = ?", s)
		}
		if c := strings.TrimSpace(l.Color); c != "" {
			q = q.Where("product_variants.color = ?", c)
		}
		if err := q.Order("product_variants.id").First(&v).Error; err != nil {
			if errors.Is(err, gorm.ErrRecordNotFound) {
				continue
			}
			return nil, err
		}
		ids = append(ids, v.ID)
	}
	if len(ids) == 0 {
		return map[uint]domain.CheckoutVariant{}, nil
	}

	// is_active bắt buộc cho CẢ đường tra theo ID: client tự gửi product_variant_id
	// nên nếu chỉ lọc ở nhánh slug thì vẫn đặt được biến thể đã ngừng bán bằng cách
	// gửi thẳng ID của nó.
	q := tx.Where("id IN ? AND is_active = 1", ids)
	if lock {
		q = q.Clauses(clause.Locking{Strength: "UPDATE"})
	}
	var variants []domain.ProductVariant
	if err := q.Find(&variants).Error; err != nil {
		return nil, err
	}

	// Tồn đọc theo CHI NHÁNH SẼ BÁN ĐƠN NÀY, không phải bản cộng của cả cửa hàng.
	//
	// Phải là ĐÚNG chi nhánh mà Create sẽ trừ kho (cùng một hàm chọn), nếu không
	// hai đầu nói hai con số: tiệm hai kho mỗi bên 5 cái thì bản cộng là 10, màn
	// hình nhận đơn 8 — rồi lượt trừ kho vỡ vì chi nhánh bán chỉ có 5. Với tiệm
	// một chi nhánh (gần như mọi khách hôm nay) hai con số bằng nhau, nên nhánh
	// này không đổi gì đối với họ.
	shopID, err := chiNhanhCuaRequest(tx.Statement.Context, tx)
	if err != nil {
		return nil, err
	}
	tonChiNhanh, err := tonCuaChiNhanh(tx, shopID, ids)
	if err != nil {
		return nil, err
	}

	// Ghép thêm thông tin sản phẩm để snapshot vào đơn / hiển thị cho khách
	type prodRow struct {
		ID         uint
		Name       string
		Slug       string
		CategoryID uint
		BrandID    *uint
		BasePrice  float64
		SalePrice  *float64
		Thumbnail  string
		IsActive   bool
	}
	pids := make([]uint, 0, len(variants))
	for _, v := range variants {
		pids = append(pids, v.ProductID)
	}
	var prods []prodRow
	if len(pids) > 0 {
		if err := tx.Table("products").
			Select("id, name, slug, category_id, brand_id, base_price, sale_price, thumbnail, is_active").
			Where("id IN ? AND deleted_at IS NULL", pids).Scan(&prods).Error; err != nil {
			return nil, err
		}
	}
	prodByID := make(map[uint]prodRow, len(prods))
	for _, p := range prods {
		prodByID[p.ID] = p
	}

	resolved := make(map[uint]domain.CheckoutVariant, len(variants))
	for _, v := range variants {
		p, ok := prodByID[v.ProductID]
		if !ok || !p.IsActive {
			continue
		}
		// Giá lấy theo thứ tự: giá riêng của biến thể > giá sale hợp lệ > giá gốc
		price := p.BasePrice
		if p.SalePrice != nil && *p.SalePrice > 0 && *p.SalePrice < p.BasePrice {
			price = *p.SalePrice
		}
		if v.Price != nil && *v.Price > 0 {
			price = *v.Price
		}
		resolved[v.ID] = domain.CheckoutVariant{
			VariantID: v.ID, ProductID: v.ProductID, ProductName: p.Name, Slug: p.Slug,
			SKU: v.SKU, Size: v.Size, Color: v.Color, Thumbnail: p.Thumbnail,
			// Danh mục + thương hiệu đi kèm để tầng service đối chiếu phạm vi
			// chương trình khuyến mãi mà không phải hỏi lại bảng products.
			CategoryID: p.CategoryID, BrandID: p.BrandID,
			Price: price, Stock: tonChiNhanh[v.ID],
		}
	}
	return resolved, nil
}

// ScanVariant tra một biến thể theo mã người bán vừa quét (hoặc gõ tay), kèm GIÁ
// và TỒN của chi nhánh đang bán.
//
// Đi qua ĐÚNG loadCheckoutVariants của luồng đặt hàng thay vì tự viết một câu
// truy vấn riêng: con số máy quét đọc ra phải bằng con số sẽ thu, kể cả khi biến
// thể có giá riêng hay đang nằm trong một đợt khuyến mãi. Hai đường tính giá là
// sớm muộn có ngày quầy báo một giá rồi máy tính một giá.
//
// Tra MÃ VẠCH TRƯỚC, SKU sau. Cả hai đều là mã người ta dán lên hàng: mã vạch do
// nhà sản xuất in sẵn, SKU là tem tiệm tự in cho hàng lẻ — quầy phải quét được
// cả thứ mua về lẫn thứ mình vừa in ra. Ưu tiên mã vạch vì nó là mã của chính
// món hàng đang cầm; SKU chỉ là quy ước nội bộ và có thể trùng hình dạng với mã
// vạch của một món khác.
func (r *orderRepository) ScanVariant(ctx context.Context, code string) (*domain.CheckoutVariant, error) {
	code = strings.TrimSpace(code)
	if code == "" {
		return nil, domain.ErrVariantNotFound
	}
	db := r.db.WithContext(ctx)

	var v domain.ProductVariant
	err := db.Where("barcode = ? AND is_active = 1", code).Order("id ASC").First(&v).Error
	if errors.Is(err, gorm.ErrRecordNotFound) {
		err = db.Where("sku = ? AND is_active = 1", code).Order("id ASC").First(&v).Error
	}
	if errors.Is(err, gorm.ErrRecordNotFound) {
		return nil, domain.ErrVariantNotFound
	}
	if err != nil {
		return nil, err
	}

	// lock = false: đây chỉ là lượt HỎI, chưa ai mua gì. Khoá dòng ở đây thì mỗi
	// lần quét là một lần giữ biến thể cho tới hết transaction, và hai quầy quét
	// cùng một món sẽ chờ nhau mà không có lý do nào.
	found, err := loadCheckoutVariants(db, []domain.CheckoutLine{{VariantID: v.ID, Quantity: 1}}, false)
	if err != nil {
		return nil, err
	}
	cv, ok := found[v.ID]
	if !ok {
		// Biến thể có thật nhưng sản phẩm cha đã ẩn hoặc đã xoá — với người đứng
		// quầy thì cũng là "món này không bán được", không phải hai chuyện khác nhau.
		return nil, domain.ErrVariantNotFound
	}
	if v.Barcode != nil {
		cv.Barcode = *v.Barcode
	}
	return &cv, nil
}

// QuoteVariants tra giá và tồn kho HIỆN TẠI của các dòng trong giỏ, chỉ đọc: không
// khoá dòng, không mở transaction, không đụng tới kho. Dùng để đối chiếu lại giỏ
// hàng trước khi khách bấm đặt.
func (r *orderRepository) QuoteVariants(ctx context.Context, lines []domain.CheckoutLine) (map[uint]domain.CheckoutVariant, error) {
	return loadCheckoutVariants(r.db.WithContext(ctx), lines, false)
}

// checkoutNote là câu mô tả nguồn gốc đơn, ghi vào sổ kho và mốc lịch sử đầu tiên.
//
// Lấy theo channel chứ không nhận từ tầng trên: hai chỗ ghi (bút toán kho và lịch
// sử đơn) phải nói cùng một câu, và câu đó là hệ quả của việc đơn tới từ đâu chứ
// không phải một lựa chọn riêng của mỗi lời gọi.
func checkoutNote(o *domain.Order) string {
	if o.Channel == domain.OrderChannelPOS {
		return "Bán tại quầy"
	}
	return "Khách đặt hàng từ website"
}

// Checkout tạo đơn từ storefront hoặc từ quầy bán hàng, trong một transaction.
//
// Thứ tự quan trọng: KHOÁ biến thể trước (SELECT ... FOR UPDATE), rồi mới kiểm tra
// tồn kho và trừ. Nếu kiểm tra trước khi khoá thì hai khách bấm đặt cùng lúc đều
// thấy "còn 1 cái" và cùng đặt được — bán vượt hàng.
func (r *orderRepository) Checkout(
	ctx context.Context,
	lines []domain.CheckoutLine,
	build func(map[uint]domain.CheckoutVariant) (*domain.Order, *domain.VoucherClaim, error),
) (*domain.Order, error) {
	var created *domain.Order

	// Chi nhánh bán đơn này, chốt trước khi mở giao dịch — cùng cách và cùng lý do
	// với Create (xem chú thích ở đó). Bỏ bước này thì đơn vào sổ với shop_id = 0:
	// khoá ngoại sang `shops` từ chối cả lượt insert, nên không đơn nào đặt được.
	// Còn ở bản cài không có khoá ngoại ấy thì tệ hơn — đơn nằm ở một chi nhánh
	// không tồn tại, và mọi con số theo chi nhánh đều thiếu nó.
	shopID, err := chiNhanhCuaRequest(ctx, r.db)
	if err != nil {
		return nil, err
	}

	err = r.db.WithContext(ctx).Transaction(func(tx *gorm.DB) error {
		// 1-3. Tra biến thể + giá hiện tại, có KHOÁ vì các dòng này sắp bị trừ kho
		resolved, err := loadCheckoutVariants(tx, lines, true)
		if err != nil {
			return err
		}

		// 4. Service dựng đơn (kiểm tồn kho, tính tiền) từ dữ liệu đã tra
		o, claim, err := build(resolved)
		if err != nil {
			return err
		}
		// Gán ở đây chứ không để service tự khai: chi nhánh bán đơn phải là ĐÚNG cái
		// mà bước trừ kho bên dưới đọc, và cả hai cùng lấy từ một chỗ duy nhất.
		o.ShopID = shopID

		// 5. Tạo đơn — mã tạm trước để lấy ID, rồi đổi thành mã theo ngày + ID
		o.OrderCode = fmt.Sprintf("TMP%d", time.Now().UnixNano())
		if err := tx.Create(o).Error; err != nil {
			return err
		}
		o.OrderCode = fmt.Sprintf("DH%s%04d", time.Now().Format("20060102"), o.ID)
		if err := tx.Model(o).Update("order_code", o.OrderCode).Error; err != nil {
			return err
		}

		// 6. Trừ kho + ghi sổ kho, dùng ID đơn làm tham chiếu (biến thể đã khoá ở bước 2)
		note := checkoutNote(o)
		if err := syncOrderStock(tx, o, orderDesiredStock(o.Items), "export", note); err != nil {
			return err
		}

		// 6a. Lượt bán của sản phẩm.
		//
		// Đơn giao hàng sinh ra ở "chờ xác nhận" nên chưa tính, và được cộng về sau
		// khi LockAndUpdate chuyển nó sang "đã giao". Đơn tại quầy thì sinh ra đã
		// hoàn tất — hàng ra khỏi kho và tới tay khách trong cùng một thao tác, sẽ
		// KHÔNG có lượt chuyển trạng thái nào sau này để cộng hộ. Không cộng ở đây
		// thì mọi thứ bán tại quầy vĩnh viễn không xuất hiện trong "bán chạy nhất".
		if soldCounted(o.Status) {
			if err := syncSoldCount(tx, o, "", o.Status); err != nil {
				return err
			}
		}

		// 6b. Chốt lượt voucher. Phải nằm SAU bước tạo đơn vì voucher_usages cần
		// order_id, và vẫn trong cùng transaction: hết lượt ở đây là cả đơn rollback.
		if claim != nil {
			if err := consumeVoucher(tx, claim, o.ID); err != nil {
				return err
			}
		}

		// 7. Mốc lịch sử khởi tạo
		if err := tx.Create(&domain.OrderStatusHistory{
			OrderID:    o.ID,
			FromStatus: "",
			ToStatus:   o.Status,
			Note:       note,
		}).Error; err != nil {
			return err
		}

		created = o
		return nil
	})

	return created, err
}

// consumeVoucher tiêu MỘT lượt của mã và ghi lịch sử sử dụng, bên trong giao dịch
// đặt hàng.
//
// Kiểm lại hạn mức ở đây dù tầng service đã kiểm rồi, vì giữa hai thời điểm đó một
// khách khác có thể vừa lấy mất lượt cuối. Khoá dòng voucher trước khi đọc
// used_count là thứ khiến việc kiểm này có nghĩa: không khoá thì hai giao dịch
// cùng đọc được "còn 1 lượt" rồi cùng tăng lên, và mã phát vượt hạn mức.
func consumeVoucher(tx *gorm.DB, claim *domain.VoucherClaim, orderID uint) error {
	var v domain.Voucher
	err := tx.Clauses(clause.Locking{Strength: "UPDATE"}).
		First(&v, claim.VoucherID).Error
	if errors.Is(err, gorm.ErrRecordNotFound) {
		return domain.ErrVoucherNotFound
	}
	if err != nil {
		return err
	}

	if claim.UsageLimit != nil && v.UsedCount >= *claim.UsageLimit {
		return domain.ErrVoucherOutOfUses
	}

	if claim.UsageLimitPerUser != nil {
		var uid uint
		if claim.UserID != nil {
			uid = *claim.UserID
		}
		// Nhận diện khách bằng tài khoản HOẶC số điện thoại: khách vãng lai không có
		// user_id, mà bỏ qua họ thì hạn mức "mỗi khách N lượt" chỉ là chữ trên màn
		// hình cấu hình.
		if who := sameCustomer(tx.Session(&gorm.Session{NewDB: true}), uid, claim.Phone); who != nil {
			var used int64
			if err := tx.Model(&domain.VoucherUsage{}).
				Where("voucher_id = ?", claim.VoucherID).
				Where(who).
				Count(&used).Error; err != nil {
				return err
			}
			if used >= int64(*claim.UsageLimitPerUser) {
				return domain.ErrVoucherUserLimitReached
			}
		}
	}

	// Cộng bằng biểu thức SQL chứ không phải v.UsedCount+1: giá trị vừa đọc đã cũ
	// ngay khi transaction khác commit xong.
	if err := tx.Model(&domain.Voucher{}).
		Where("id = ?", claim.VoucherID).
		UpdateColumn("used_count", gorm.Expr("used_count + 1")).Error; err != nil {
		return err
	}

	now := time.Now()
	return tx.Create(&domain.VoucherUsage{
		VoucherID: claim.VoucherID,
		UserID:    claim.UserID,
		// Ghi cả số điện thoại: đây là thứ nhận ra khách ở những lần đặt sau khi họ
		// không đăng nhập.
		RecipientPhone: digitsOnly(claim.Phone),
		OrderID:        orderID,
		DiscountAmount: claim.Discount,
		UsedAt:         &now,
	}).Error
}

func (r *orderRepository) LockAndUpdate(ctx context.Context, id uint, apply func(o *domain.Order) (*domain.OrderStatusHistory, []string, *domain.StockRelease, error)) (*domain.Order, error) {
	var result *domain.Order
	err := r.db.WithContext(ctx).Transaction(func(tx *gorm.DB) error {
		var o domain.Order
		// Khoá dòng đơn để đọc-sửa-ghi không bị chèn bởi thao tác đồng thời.
		err := tx.Clauses(clause.Locking{Strength: "UPDATE"}).
			Preload("Items").First(&o, id).Error
		if errors.Is(err, gorm.ErrRecordNotFound) {
			return domain.ErrNotFound
		}
		if err != nil {
			return err
		}

		fromStatus := o.Status
		history, cols, release, err := apply(&o)
		if err != nil {
			return err
		}

		// Đơn khép lại (huỷ / hoàn hàng): trả về kho đúng số đã lấy khỏi kho.
		if release != nil {
			if err := syncOrderStock(tx, &o, nil, release.Type, release.Note); err != nil {
				return err
			}
		}

		// Lượt bán của sản phẩm chạy theo trạng thái đơn (giao thành công thì cộng,
		// khách trả hàng thì trừ lại).
		if o.Status != fromStatus {
			if err := syncSoldCount(tx, &o, fromStatus, o.Status); err != nil {
				return err
			}
		}

		// Chỉ ghi đúng các cột đã thay đổi để không đè lên cột do người khác vừa sửa.
		if len(cols) > 0 {
			if err := tx.Model(&o).Select(cols).Updates(&o).Error; err != nil {
				return err
			}
		}
		if history != nil {
			if err := tx.Create(history).Error; err != nil {
				return err
			}
		}

		result = &o
		return nil
	})
	return result, err
}

// UpdateDetail sửa thông tin đơn và thay toàn bộ danh sách sản phẩm dưới khoá dòng.
// Order_items bị xoá hết rồi chèn lại theo danh sách mới; các cột vô hướng của đơn
// chỉ ghi đúng những cột mutate chọn, nên không đè cột do luồng khác vừa sửa.
func (r *orderRepository) UpdateDetail(ctx context.Context, id uint, mutate func(o *domain.Order) ([]string, []domain.OrderItem, *domain.OrderStatusHistory, error)) (*domain.Order, error) {
	var result *domain.Order
	err := r.db.WithContext(ctx).Transaction(func(tx *gorm.DB) error {
		var o domain.Order
		err := tx.Clauses(clause.Locking{Strength: "UPDATE"}).
			Preload("Items").First(&o, id).Error
		if errors.Is(err, gorm.ErrRecordNotFound) {
			return domain.ErrNotFound
		}
		if err != nil {
			return err
		}

		cols, newItems, history, err := mutate(&o)
		if err != nil {
			return err
		}

		if len(cols) > 0 {
			if err := tx.Model(&o).Select(cols).Updates(&o).Error; err != nil {
				return err
			}
		}

		// Thay toàn bộ sản phẩm: xoá cứng dòng cũ (order_items không xoá mềm) rồi
		// chèn lại danh sách mới đã gắn order_id.
		if err := tx.Where("order_id = ?", o.ID).Delete(&domain.OrderItem{}).Error; err != nil {
			return err
		}
		for i := range newItems {
			newItems[i].OrderID = o.ID
		}
		if len(newItems) > 0 {
			if err := tx.Create(&newItems).Error; err != nil {
				return err
			}
		}

		// Kho chạy theo danh sách mới: chỉ trừ/hoàn đúng phần chênh so với số đơn
		// đang giữ, kể cả khi admin đổi hẳn sang biến thể khác.
		if err := syncOrderStock(tx, &o, orderDesiredStock(newItems), "adjustment", "Sửa đơn hàng"); err != nil {
			return err
		}

		if history != nil {
			if err := tx.Create(history).Error; err != nil {
				return err
			}
		}

		o.Items = newItems
		result = &o
		return nil
	})
	return result, err
}

func (r *orderRepository) Stats(ctx context.Context) (domain.OrderStats, error) {
	var stats domain.OrderStats

	var rows []struct {
		Status string
		Total  int64
	}
	err := r.db.WithContext(ctx).Model(&domain.Order{}).
		Select("status, COUNT(*) AS total").
		Group("status").
		Scan(&rows).Error
	if err != nil {
		return stats, err
	}

	for _, row := range rows {
		stats.Total += row.Total
		switch row.Status {
		case domain.OrderStatusPending:
			stats.Pending = row.Total
		case domain.OrderStatusConfirmed, domain.OrderStatusProcessing:
			stats.Processing += row.Total
		case domain.OrderStatusShipping:
			stats.Shipping = row.Total
		case domain.OrderStatusDelivered, domain.OrderStatusCompleted:
			stats.Completed += row.Total
		case domain.OrderStatusCancelled, domain.OrderStatusReturned:
			stats.Cancelled += row.Total
		}
	}

	// Doanh thu: bỏ đơn huỷ/hoàn, giống cách tính tổng chi tiêu của khách hàng.
	var revenue struct{ Total float64 }
	err = r.db.WithContext(ctx).Model(&domain.Order{}).
		Select("COALESCE(SUM(total_amount), 0) AS total").
		Where("status NOT IN ?", []string{domain.OrderStatusCancelled, domain.OrderStatusReturned}).
		Scan(&revenue).Error
	stats.Revenue = revenue.Total

	return stats, err
}

// RevenueSeries gom doanh thu theo ngày cho biểu đồ ở trang tổng quan.
//
// Chỉ chạy MỘT truy vấn cho cả kỳ đang xem lẫn kỳ trước (lấy từ mốc gấp đôi số
// ngày), rồi chia ở Go — hai truy vấn cho hai kỳ chỉ tốn thêm một vòng tới DB.
//
// Ngày không phát sinh đơn vẫn được điền 0: thiếu ngày thì biểu đồ nối thẳng qua
// và vẽ sai đường xu hướng.
func (r *orderRepository) RevenueSeries(ctx context.Context, days int) (domain.RevenueSummary, error) {
	if days < 1 {
		days = 1
	}
	if days > 365 {
		days = 365
	}

	loc := time.Now().Location()
	now := time.Now().In(loc)
	today := time.Date(now.Year(), now.Month(), now.Day(), 0, 0, 0, 0, loc)
	start := today.AddDate(0, 0, -(days - 1)) // đầu kỳ đang xem
	prevStart := start.AddDate(0, 0, -days)   // đầu kỳ trước, cùng độ dài

	var rows []struct {
		Day     string
		Orders  int64
		Revenue float64
	}
	err := r.db.WithContext(ctx).Model(&domain.Order{}).
		Select("DATE(created_at) AS day, COUNT(*) AS orders, COALESCE(SUM(total_amount), 0) AS revenue").
		Where("created_at >= ? AND created_at < ?", prevStart, today.AddDate(0, 0, 1)).
		Where("status NOT IN ?", []string{domain.OrderStatusCancelled, domain.OrderStatusReturned}).
		Group("DATE(created_at)").
		Scan(&rows).Error
	if err != nil {
		return domain.RevenueSummary{}, err
	}

	type agg struct {
		orders  int64
		revenue float64
	}
	byDay := make(map[string]agg, len(rows))
	for _, row := range rows {
		// Tuỳ driver, DATE() có thể về "2026-07-28" hoặc kèm giờ — cắt lấy 10 ký tự đầu.
		key := row.Day
		if len(key) > 10 {
			key = key[:10]
		}
		byDay[key] = agg{orders: row.Orders, revenue: row.Revenue}
	}

	out := domain.RevenueSummary{Days: days, Points: make([]domain.RevenuePoint, 0, days)}
	for i := 0; i < days; i++ {
		d := start.AddDate(0, 0, i).Format("2006-01-02")
		a := byDay[d]
		out.Points = append(out.Points, domain.RevenuePoint{Date: d, Orders: a.orders, Revenue: a.revenue})
		out.TotalOrders += a.orders
		out.TotalRevenue += a.revenue
	}
	for i := 0; i < days; i++ {
		a := byDay[prevStart.AddDate(0, 0, i).Format("2006-01-02")]
		out.PrevOrders += a.orders
		out.PrevRevenue += a.revenue
	}

	return out, nil
}

func (r *orderRepository) Histories(ctx context.Context, orderID uint) ([]domain.OrderStatusHistory, error) {
	var list []domain.OrderStatusHistory
	err := r.db.WithContext(ctx).
		Where("order_id = ?", orderID).
		Order("id ASC").
		Find(&list).Error
	return list, err
}
