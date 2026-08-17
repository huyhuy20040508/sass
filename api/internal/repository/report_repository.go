package repository

import (
	"context"
	"fmt"
	"slices"
	"time"

	"gorm.io/gorm"

	"sass-api/internal/domain"
)

// reportRepository — mọi phép gộp của nhóm trang Báo cáo.
//
// Tất cả đều CHỈ ĐỌC và chạy thẳng bằng SQL gộp, không nạp đơn ra bộ nhớ rồi
// cộng bằng Go: một kỳ 90 ngày của cửa hàng đang chạy là hàng nghìn đơn và hàng
// chục nghìn dòng hàng, kéo hết về ứng dụng thì trang báo cáo sẽ là trang chậm
// nhất hệ thống.
//
// Ở đây dùng Table(...) chứ không dùng Model(...), nên GORM KHÔNG tự thêm điều
// kiện xoá mềm — mọi câu truy vấn phải tự khai `deleted_at IS NULL`. Quên một
// chỗ là báo cáo cộng cả đơn đã xoá.
type reportRepository struct {
	db *gorm.DB
}

func NewReportRepository(db *gorm.DB) domain.ReportRepository {
	return &reportRepository{db: db}
}

// deadStatuses — trạng thái KHÔNG tính vào doanh thu. Giữ đúng danh sách mà
// OrderRepository.Stats đang dùng; lệch nhau là trang Tổng quan và trang Báo cáo
// nói hai con số khác nhau về cùng một kỳ.
var deadStatuses = []string{domain.OrderStatusCancelled, domain.OrderStatusReturned}

// costExpr — giá vốn MỘT đơn vị của dòng hàng.
//
// Thứ tự ưu tiên, và lý do của bậc đầu tiên:
//
//	oi.cost_price  giá vốn CHỤP LÚC BÁN. Phải đứng trước, nếu không thì nhập lô
//	               mới đắt hơn 20% là lãi gộp của mọi tháng trước tự co lại trên
//	               báo cáo dù không đơn nào thay đổi — sổ sách tự sửa số liệu quá
//	               khứ thì không dùng để ra quyết định được.
//	pv.cost_price  giá vốn hiện tại của biến thể, cho dòng bán trước khi có cột
//	pv.cost_price  chụp, hoặc đơn tạo thủ công (người nhập gõ giá bán, không tra
//	p.cost_price   giá vốn). Đây đúng bằng cách báo cáo vẫn tính từ trước tới nay.
//	0              chưa khai giá vốn = chưa tính được lãi. Giao diện phải nói rõ
//	               chỗ đó thay vì bịa ra một con số.
const costExpr = "COALESCE(oi.cost_price, pv.cost_price, p.cost_price, 0)"

// orders lọc đơn CÒN HIỆU LỰC trong kỳ. Bí danh bảng luôn là `o` để các câu
// truy vấn bên dưới ghép join vào mà không phải đoán tên.
func (r *reportRepository) orders(ctx context.Context, p domain.ReportPeriod) *gorm.DB {
	return r.allOrders(ctx, p).Where("o.status NOT IN ?", deadStatuses)
}

// allOrders lọc MỌI đơn trong kỳ, kể cả huỷ và hoàn — chỉ báo cáo đơn hàng dùng
// tới, để tính được tỷ lệ huỷ trên tổng đơn đã đặt.
func (r *reportRepository) allOrders(ctx context.Context, p domain.ReportPeriod) *gorm.DB {
	return r.db.WithContext(ctx).Table("orders o").
		Where("o.deleted_at IS NULL").
		Where("o.created_at >= ? AND o.created_at < ?", p.From, p.To)
}

// items nối đơn còn hiệu lực với từng dòng hàng + biến thể + sản phẩm.
//
// LEFT JOIN chứ không INNER: biến thể hoặc sản phẩm bị xoá sau khi bán thì dòng
// hàng vẫn còn (order_items giữ snapshot tên/giá), mất nó là doanh thu của kỳ cũ
// tự nhiên hụt đi mỗi lần dọn danh mục.
func (r *reportRepository) items(ctx context.Context, p domain.ReportPeriod) *gorm.DB {
	return r.orders(ctx, p).
		Joins("JOIN order_items oi ON oi.order_id = o.id").
		Joins("LEFT JOIN product_variants pv ON pv.id = oi.product_variant_id").
		Joins("LEFT JOIN products p ON p.id = oi.product_id")
}

// groupExpr dịch cách chia trục thời gian sang biểu thức MySQL.
//
// Tuần dùng %x-%v (chuẩn ISO: tuần bắt đầu Thứ Hai, năm tính theo tuần) để khớp
// với time.Time.ISOWeek() bên Go — tầng service bù mốc trống bằng hàm đó, hai
// bên lệch chuẩn tuần là mốc có dữ liệu không khớp mốc nào cả.
func groupExpr(groupBy string) string {
	switch groupBy {
	case domain.ReportGroupWeek:
		return "DATE_FORMAT(o.created_at, '%x-W%v')"
	case domain.ReportGroupMonth:
		return "DATE_FORMAT(o.created_at, '%Y-%m')"
	default:
		return "DATE_FORMAT(o.created_at, '%Y-%m-%d')"
	}
}

// ---------- Dùng chung ----------

func (r *reportRepository) Totals(ctx context.Context, p domain.ReportPeriod) (domain.ReportTotals, error) {
	var out domain.ReportTotals

	var money struct {
		Orders   int64
		Revenue  float64
		Subtotal float64
		Discount float64
		Shipping float64
	}
	err := r.orders(ctx, p).
		Select(`COUNT(*) AS orders,
			COALESCE(SUM(o.total_amount), 0) AS revenue,
			COALESCE(SUM(o.subtotal_amount), 0) AS subtotal,
			COALESCE(SUM(o.discount_amount), 0) AS discount,
			COALESCE(SUM(o.shipping_fee), 0) AS shipping`).
		Scan(&money).Error
	if err != nil {
		return out, err
	}

	var goods struct {
		Units int64
		Cost  float64
	}
	err = r.items(ctx, p).
		Select("COALESCE(SUM(oi.quantity), 0) AS units, COALESCE(SUM(" + costExpr + " * oi.quantity), 0) AS cost").
		Scan(&goods).Error
	if err != nil {
		return out, err
	}

	out = domain.ReportTotals{
		Orders:   money.Orders,
		Revenue:  money.Revenue,
		Subtotal: money.Subtotal,
		Discount: money.Discount,
		Shipping: money.Shipping,
		Units:    goods.Units,
		Cost:     goods.Cost,
	}
	out.Profit = out.Subtotal - out.Discount - out.Cost
	if out.Orders > 0 {
		out.AOV = out.Revenue / float64(out.Orders)
	}
	return out, nil
}

func (r *reportRepository) Buckets(ctx context.Context, p domain.ReportPeriod, groupBy string) (map[string]domain.ReportBucket, error) {
	expr := groupExpr(groupBy)

	var moneyRows []struct {
		Label    string
		Orders   int64
		Revenue  float64
		Subtotal float64
		Discount float64
		Shipping float64
	}
	err := r.orders(ctx, p).
		Select(expr + ` AS label,
			COUNT(*) AS orders,
			COALESCE(SUM(o.total_amount), 0) AS revenue,
			COALESCE(SUM(o.subtotal_amount), 0) AS subtotal,
			COALESCE(SUM(o.discount_amount), 0) AS discount,
			COALESCE(SUM(o.shipping_fee), 0) AS shipping`).
		Group("label").
		Scan(&moneyRows).Error
	if err != nil {
		return nil, err
	}

	out := make(map[string]domain.ReportBucket, len(moneyRows))
	for _, row := range moneyRows {
		out[row.Label] = domain.ReportBucket{
			Label:    row.Label,
			Orders:   row.Orders,
			Revenue:  row.Revenue,
			Subtotal: row.Subtotal,
			Discount: row.Discount,
			Shipping: row.Shipping,
		}
	}

	// Số món + giá vốn phải đếm trên dòng hàng, gộp chung câu trên thì mỗi đơn bị
	// nhân lên theo số dòng và cột tiền đội lên nhiều lần.
	var goodsRows []struct {
		Label string
		Units int64
		Cost  float64
	}
	err = r.items(ctx, p).
		Select(expr + " AS label, COALESCE(SUM(oi.quantity), 0) AS units, COALESCE(SUM(" + costExpr + " * oi.quantity), 0) AS cost").
		Group("label").
		Scan(&goodsRows).Error
	if err != nil {
		return nil, err
	}
	for _, row := range goodsRows {
		b := out[row.Label]
		b.Label = row.Label
		b.Units = row.Units
		b.Cost = row.Cost
		out[row.Label] = b
	}

	for label, b := range out {
		b.Profit = b.Subtotal - b.Discount - b.Cost
		out[label] = b
	}
	return out, nil
}

// ---------- Báo cáo doanh thu ----------

func (r *reportRepository) ByPaymentMethod(ctx context.Context, p domain.ReportPeriod) ([]domain.ReportSlice, error) {
	return r.sliceByOrderColumn(ctx, p, "o.payment_method", 0)
}

func (r *reportRepository) ByPaymentStatus(ctx context.Context, p domain.ReportPeriod) ([]domain.ReportSlice, error) {
	return r.sliceByOrderColumn(ctx, p, "o.payment_status", 0)
}

// ByShop tách lãi gộp theo chi nhánh bán đơn.
//
// Chạy trên bảng DÒNG HÀNG chứ không trên bảng đơn: giá vốn nằm ở từng món, và
// gộp theo đơn thì mỗi đơn nhiều dòng sẽ bị nhân lên. Số ĐƠN vì thế phải đếm
// COUNT(DISTINCT o.id) — đếm thường ra số dòng hàng.
//
// LEFT JOIN sang shops: chi nhánh bị đóng rồi thì đơn cũ của nó vẫn phải có mặt
// trong báo cáo kỳ trước, chỉ là không còn tên để hiện.
func (r *reportRepository) ByShop(ctx context.Context, p domain.ReportPeriod) ([]domain.ShopProfitSlice, error) {
	var rows []struct {
		ShopID  uint
		Label   string
		Orders  int64
		Units   int64
		Revenue float64
		Cost    float64
	}

	err := r.items(ctx, p).
		Joins("LEFT JOIN shops s ON s.id = o.shop_id").
		Select(`o.shop_id AS shop_id,
			COALESCE(s.name, '') AS label,
			COUNT(DISTINCT o.id) AS orders,
			COALESCE(SUM(oi.quantity), 0) AS units,
			COALESCE(SUM(oi.total_price), 0) AS revenue,
			COALESCE(SUM(` + costExpr + ` * oi.quantity), 0) AS cost`).
		Group("o.shop_id, s.name").
		Scan(&rows).Error
	if err != nil {
		return nil, err
	}

	out := make([]domain.ShopProfitSlice, 0, len(rows))
	for _, row := range rows {
		s := domain.ShopProfitSlice{
			ShopID: row.ShopID, Label: row.Label, Orders: row.Orders,
			Units: row.Units, Revenue: row.Revenue, Cost: row.Cost,
			Profit: row.Revenue - row.Cost,
		}
		if s.Label == "" {
			// Chi nhánh đã đóng (hoặc dòng dữ liệu cũ chưa gán chi nhánh): vẫn phải
			// hiện ra, vì tiền của nó có thật trong tổng.
			s.Label = "Chi nhánh đã đóng"
		}
		if s.Revenue > 0 {
			s.Margin = s.Profit / s.Revenue * 100
		}
		out = append(out, s)
	}

	// Sắp ở Go chứ không ORDER BY: lãi là cột tính ra, và sắp theo biểu thức lặp
	// lại cả cụm COALESCE dài trong ORDER BY chỉ để được đúng thứ tự này.
	slices.SortFunc(out, func(a, b domain.ShopProfitSlice) int {
		switch {
		case a.Profit > b.Profit:
			return -1
		case a.Profit < b.Profit:
			return 1
		default:
			return 0
		}
	})
	return out, nil
}

func (r *reportRepository) ByProvince(ctx context.Context, p domain.ReportPeriod, limit int) ([]domain.ReportSlice, error) {
	return r.sliceByOrderColumn(ctx, p, "o.shipping_province", limit)
}

func (r *reportRepository) ByShipping(ctx context.Context, p domain.ReportPeriod) ([]domain.ReportSlice, error) {
	return r.sliceByOrderColumn(ctx, p, "COALESCE(o.shipping_method, '')", 0)
}

// sliceByOrderColumn gộp đơn còn hiệu lực theo MỘT cột của bảng orders.
//
// `column` là biểu thức SQL do CHÍNH tệp này dựng (không nhận từ request), nên
// không có đường cho dữ liệu người dùng lọt vào câu truy vấn. limit <= 0 = lấy hết.
func (r *reportRepository) sliceByOrderColumn(ctx context.Context, p domain.ReportPeriod, column string, limit int) ([]domain.ReportSlice, error) {
	var rows []struct {
		Key     string
		Orders  int64
		Revenue float64
	}
	q := r.orders(ctx, p).
		Select(column + " AS `key`, COUNT(*) AS orders, COALESCE(SUM(o.total_amount), 0) AS revenue").
		Group(column).
		Order("orders DESC")
	if limit > 0 {
		q = q.Limit(limit)
	}
	if err := q.Scan(&rows).Error; err != nil {
		return nil, err
	}

	out := make([]domain.ReportSlice, 0, len(rows))
	for _, row := range rows {
		out = append(out, domain.ReportSlice{Key: row.Key, Orders: row.Orders, Revenue: row.Revenue})
	}
	return out, nil
}

// ---------- Báo cáo đơn hàng ----------

func (r *reportRepository) OrderCounts(ctx context.Context, p domain.ReportPeriod) (domain.OrderReportTotals, error) {
	var out domain.OrderReportTotals

	// Một lượt quét cho cả bốn con số đếm: tổng, huỷ, hoàn và doanh thu phần còn
	// hiệu lực. CASE WHEN rẻ hơn nhiều so với bốn lần đi lại database.
	var row struct {
		Total     int64
		Cancelled int64
		Returned  int64
		Revenue   float64
	}
	err := r.allOrders(ctx, p).
		Select(fmt.Sprintf(`COUNT(*) AS total,
			SUM(CASE WHEN o.status = '%s' THEN 1 ELSE 0 END) AS cancelled,
			SUM(CASE WHEN o.status = '%s' THEN 1 ELSE 0 END) AS returned,
			COALESCE(SUM(CASE WHEN o.status NOT IN ('%s', '%s') THEN o.total_amount ELSE 0 END), 0) AS revenue`,
			domain.OrderStatusCancelled, domain.OrderStatusReturned,
			domain.OrderStatusCancelled, domain.OrderStatusReturned)).
		Scan(&row).Error
	if err != nil {
		return out, err
	}

	var unpaid struct {
		Orders int64
		Amount float64
	}
	err = r.orders(ctx, p).
		Where("o.payment_status <> ?", domain.OrderPaymentPaid).
		Select("COUNT(*) AS orders, COALESCE(SUM(o.total_amount), 0) AS amount").
		Scan(&unpaid).Error
	if err != nil {
		return out, err
	}

	var units struct{ Units int64 }
	err = r.items(ctx, p).Select("COALESCE(SUM(oi.quantity), 0) AS units").Scan(&units).Error
	if err != nil {
		return out, err
	}

	out = domain.OrderReportTotals{
		Total:        row.Total,
		Cancelled:    row.Cancelled,
		Returned:     row.Returned,
		Net:          row.Total - row.Cancelled - row.Returned,
		Revenue:      row.Revenue,
		Units:        units.Units,
		UnpaidOrders: unpaid.Orders,
		UnpaidAmount: unpaid.Amount,
	}
	if out.Total > 0 {
		out.DeadRate = float64(out.Cancelled+out.Returned) / float64(out.Total) * 100
	}
	if out.Net > 0 {
		out.AOV = out.Revenue / float64(out.Net)
		out.UnitsPerOrder = float64(out.Units) / float64(out.Net)
	}
	return out, nil
}

// ByStatus đếm trên TOÀN BỘ đơn của kỳ — huỷ và hoàn là số cần nhìn nhất ở bảng
// này, lọc chúng đi thì bảng trạng thái không còn nói được gì.
func (r *reportRepository) ByStatus(ctx context.Context, p domain.ReportPeriod) ([]domain.ReportSlice, error) {
	var rows []struct {
		Key     string
		Orders  int64
		Revenue float64
	}
	err := r.allOrders(ctx, p).
		Select("o.status AS `key`, COUNT(*) AS orders, COALESCE(SUM(o.total_amount), 0) AS revenue").
		Group("o.status").
		Scan(&rows).Error
	if err != nil {
		return nil, err
	}

	out := make([]domain.ReportSlice, 0, len(rows))
	for _, row := range rows {
		out = append(out, domain.ReportSlice{Key: row.Key, Orders: row.Orders, Revenue: row.Revenue})
	}
	return out, nil
}

func (r *reportRepository) ByHour(ctx context.Context, p domain.ReportPeriod) ([]domain.ReportSlice, error) {
	return r.sliceByOrderColumn(ctx, p, "HOUR(o.created_at)", 0)
}

// ByWeekday dùng WEEKDAY() (0 = Thứ Hai) rồi cộng 1, để khoá là 1..7 đọc theo
// lịch Việt Nam — DAYOFWEEK() của MySQL bắt đầu từ Chủ Nhật, dùng thẳng thì cột
// đầu bảng là Chủ Nhật.
func (r *reportRepository) ByWeekday(ctx context.Context, p domain.ReportPeriod) ([]domain.ReportSlice, error) {
	return r.sliceByOrderColumn(ctx, p, "WEEKDAY(o.created_at) + 1", 0)
}

// ByChannel tách đơn hội viên (có tài khoản) và đơn khách vãng lai.
func (r *reportRepository) ByChannel(ctx context.Context, p domain.ReportPeriod) ([]domain.ReportSlice, error) {
	return r.sliceByOrderColumn(ctx, p, "CASE WHEN o.user_id IS NULL THEN 'guest' ELSE 'member' END", 0)
}

// ---------- Báo cáo sản phẩm ----------

func (r *reportRepository) ProductTotals(ctx context.Context, p domain.ReportPeriod) (domain.ProductReportTotals, error) {
	var out domain.ProductReportTotals

	var row struct {
		Units    int64
		Revenue  float64
		Cost     float64
		Products int64
		Orders   int64
	}
	err := r.items(ctx, p).
		Select(`COALESCE(SUM(oi.quantity), 0) AS units,
			COALESCE(SUM(oi.total_price), 0) AS revenue,
			COALESCE(SUM(` + costExpr + ` * oi.quantity), 0) AS cost,
			COUNT(DISTINCT oi.product_id) AS products,
			COUNT(DISTINCT o.id) AS orders`).
		Scan(&row).Error
	if err != nil {
		return out, err
	}

	out = domain.ProductReportTotals{
		Units:        row.Units,
		Revenue:      row.Revenue,
		Cost:         row.Cost,
		Profit:       row.Revenue - row.Cost,
		ProductsSold: row.Products,
		Orders:       row.Orders,
	}
	if out.Revenue > 0 {
		out.Margin = out.Profit / out.Revenue * 100
	}
	return out, nil
}

func (r *reportRepository) ProductRows(ctx context.Context, p domain.ReportPeriod, sort string, limit int) ([]domain.ProductReportRow, error) {
	// Chỉ ba kiểu xếp hạng được phép, và tên cột lấy từ hằng số chứ không ghép từ
	// tham số — `sort` đi thẳng từ query string của người dùng vào ORDER BY là một
	// lỗ tiêm SQL.
	order := "revenue DESC"
	switch sort {
	case domain.ProductSortUnits:
		order = "units DESC"
	case domain.ProductSortProfit:
		order = "profit DESC"
	}
	if limit <= 0 {
		limit = 20
	}

	var rows []struct {
		ProductID    uint
		Name         string
		SKU          string
		Slug         string
		Thumbnail    string
		CategoryName string
		BrandName    string
		Orders       int64
		Units        int64
		Revenue      float64
		Cost         float64
		Profit       float64
		Stock        int64
	}
	err := r.items(ctx, p).
		Joins("LEFT JOIN categories c ON c.id = p.category_id").
		Joins("LEFT JOIN brands b ON b.id = p.brand_id").
		// Sản phẩm đã bị xoá hẳn khỏi danh mục thì order_items.product_id về NULL:
		// những dòng đó không gộp được về mặt hàng nào nên để ngoài bảng xếp hạng,
		// phần tiền của chúng vẫn nằm trong Totals.
		Where("oi.product_id IS NOT NULL").
		Select(`oi.product_id AS product_id,
			COALESCE(p.name, MAX(oi.product_name)) AS name,
			COALESCE(p.sku, '') AS sku,
			COALESCE(p.slug, '') AS slug,
			COALESCE(p.thumbnail, '') AS thumbnail,
			COALESCE(c.name, '') AS category_name,
			COALESCE(b.name, '') AS brand_name,
			COUNT(DISTINCT o.id) AS orders,
			COALESCE(SUM(oi.quantity), 0) AS units,
			COALESCE(SUM(oi.total_price), 0) AS revenue,
			COALESCE(SUM(` + costExpr + ` * oi.quantity), 0) AS cost,
			COALESCE(SUM(oi.total_price), 0) - COALESCE(SUM(` + costExpr + ` * oi.quantity), 0) AS profit,
			COALESCE((SELECT SUM(v.stock_quantity) FROM product_variants v
				WHERE v.product_id = oi.product_id AND v.deleted_at IS NULL), 0) AS stock`).
		Group("oi.product_id, p.name, p.sku, p.slug, p.thumbnail, c.name, b.name").
		Order(order).
		Limit(limit).
		Scan(&rows).Error
	if err != nil {
		return nil, err
	}

	out := make([]domain.ProductReportRow, 0, len(rows))
	for _, row := range rows {
		item := domain.ProductReportRow{
			ProductID:    row.ProductID,
			Name:         row.Name,
			SKU:          row.SKU,
			Slug:         row.Slug,
			Thumbnail:    row.Thumbnail,
			CategoryName: row.CategoryName,
			BrandName:    row.BrandName,
			Orders:       row.Orders,
			Units:        row.Units,
			Revenue:      row.Revenue,
			Cost:         row.Cost,
			Profit:       row.Profit,
			Stock:        row.Stock,
		}
		if item.Revenue > 0 {
			item.Margin = item.Profit / item.Revenue * 100
		}
		out = append(out, item)
	}
	return out, nil
}

func (r *reportRepository) ByCategory(ctx context.Context, p domain.ReportPeriod, limit int) ([]domain.ReportSlice, error) {
	return r.sliceByItem(ctx, p, "p.category_id", "c.name", "LEFT JOIN categories c ON c.id = p.category_id", limit)
}

func (r *reportRepository) ByBrand(ctx context.Context, p domain.ReportPeriod, limit int) ([]domain.ReportSlice, error) {
	return r.sliceByItem(ctx, p, "p.brand_id", "b.name", "LEFT JOIN brands b ON b.id = p.brand_id", limit)
}

// BySize gộp theo size ghi trên dòng hàng (snapshot lúc mua), không theo biến thể
// hiện tại — sửa size của biến thể về sau không được phép viết lại lịch sử bán.
func (r *reportRepository) BySize(ctx context.Context, p domain.ReportPeriod, limit int) ([]domain.ReportSlice, error) {
	return r.sliceByItem(ctx, p, "COALESCE(oi.size, '')", "COALESCE(oi.size, '')", "", limit)
}

// sliceByItem gộp DÒNG HÀNG theo một chiều của sản phẩm.
//
// Khác sliceByOrderColumn ở chỗ đơn vị là món hàng chứ không phải đơn: `orders`
// đếm số đơn KHÁC NHAU có chạm tới nhóm đó, `revenue` là tiền hàng của nhóm
// (tổng total_price), chưa trừ giảm giá cấp đơn và không gồm phí ship.
//
// Ba tham số SQL đều do chính tệp này dựng, không nhận từ request.
func (r *reportRepository) sliceByItem(ctx context.Context, p domain.ReportPeriod, keyExpr, labelExpr, join string, limit int) ([]domain.ReportSlice, error) {
	q := r.items(ctx, p)
	if join != "" {
		q = q.Joins(join)
	}

	var rows []struct {
		Key     string
		Label   string
		Orders  int64
		Units   int64
		Revenue float64
	}
	// GROUP BY phải gộp theo ĐÚNG hai biểu thức đứng trong SELECT, từng chữ một.
	//
	// MySQL 8 bật sẵn ONLY_FULL_GROUP_BY và nó đối chiếu theo MẶT CHỮ chứ không
	// rút gọn biểu thức. Trước đây SELECT lấy COALESCE(CAST(<key> AS CHAR), '')
	// mà GROUP BY chỉ ghi <key>: với <key> là một CỘT thì MySQL vẫn chịu (mọi
	// biểu thức trên cột đã gộp đều hợp lệ), nhưng với <key> là một BIỂU THỨC —
	// đúng trường hợp BySize truyền COALESCE(oi.size, '') — thì nó từ chối:
	//
	//	Error 1055: Expression #1 of SELECT list is not in GROUP BY clause
	//	and contains nonaggregated column 'oi.size'
	//
	// Tức là trang Báo cáo → theo size trả 500. Lỗi này sống được lâu vì MySQL
	// đi kèm XAMPP không bật ONLY_FULL_GROUP_BY, còn máy chủ thì có — nó chỉ lộ
	// ra ở lượt CI đầu tiên chạy trên MySQL 8 thật.
	//
	// Gộp theo chính biểu thức trong SELECT không đổi kết quả: id cast sang chuỗi
	// vẫn phân biệt từng id, còn NULL và '' thì cả hai vế đều đã cố ý dồn về một
	// nhóm (dòng hàng chưa ghi size, hoặc sản phẩm không thuộc danh mục nào).
	khoa := "COALESCE(CAST(" + keyExpr + " AS CHAR), '')"
	nhan := "COALESCE(" + labelExpr + ", '')"

	q = q.Select(khoa + " AS `key`, " + nhan + " AS label, " +
		"COUNT(DISTINCT o.id) AS orders, COALESCE(SUM(oi.quantity), 0) AS units, COALESCE(SUM(oi.total_price), 0) AS revenue").
		Group(khoa + ", " + nhan).
		Order("revenue DESC")
	if limit > 0 {
		q = q.Limit(limit)
	}
	if err := q.Scan(&rows).Error; err != nil {
		return nil, err
	}

	out := make([]domain.ReportSlice, 0, len(rows))
	for _, row := range rows {
		out = append(out, domain.ReportSlice{
			Key: row.Key, Label: row.Label,
			Orders: row.Orders, Units: row.Units, Revenue: row.Revenue,
		})
	}
	return out, nil
}

// UnsoldProducts đếm sản phẩm ĐANG BÁN mà cả kỳ không bán được món nào.
func (r *reportRepository) UnsoldProducts(ctx context.Context, p domain.ReportPeriod) (int64, error) {
	sold := r.db.WithContext(ctx).Table("order_items oi").
		Select("DISTINCT oi.product_id").
		Joins("JOIN orders o ON o.id = oi.order_id").
		Where("o.deleted_at IS NULL").
		Where("o.created_at >= ? AND o.created_at < ?", p.From, p.To).
		Where("o.status NOT IN ?", deadStatuses).
		Where("oi.product_id IS NOT NULL")

	var total int64
	err := r.db.WithContext(ctx).Table("products p").
		Where("p.deleted_at IS NULL AND p.is_active = 1").
		Where("p.id NOT IN (?)", sold).
		Count(&total).Error
	return total, err
}

// ---------- Báo cáo khách hàng ----------

func (r *reportRepository) CustomerTotals(ctx context.Context, p domain.ReportPeriod) (domain.CustomerReportTotals, error) {
	var out domain.CustomerReportTotals

	var row struct {
		Orders        int64
		Revenue       float64
		Buyers        int64
		MemberOrders  int64
		MemberRevenue float64
		GuestOrders   int64
		GuestRevenue  float64
	}
	err := r.orders(ctx, p).
		Select(`COUNT(*) AS orders,
			COALESCE(SUM(o.total_amount), 0) AS revenue,
			COUNT(DISTINCT o.user_id) AS buyers,
			SUM(CASE WHEN o.user_id IS NULL THEN 0 ELSE 1 END) AS member_orders,
			COALESCE(SUM(CASE WHEN o.user_id IS NULL THEN 0 ELSE o.total_amount END), 0) AS member_revenue,
			SUM(CASE WHEN o.user_id IS NULL THEN 1 ELSE 0 END) AS guest_orders,
			COALESCE(SUM(CASE WHEN o.user_id IS NULL THEN o.total_amount ELSE 0 END), 0) AS guest_revenue`).
		Scan(&row).Error
	if err != nil {
		return out, err
	}

	newBuyers, err := r.newBuyers(ctx, p)
	if err != nil {
		return out, err
	}
	registered, err := r.Registrations(ctx, p)
	if err != nil {
		return out, err
	}

	out = domain.CustomerReportTotals{
		Buyers:        row.Buyers,
		NewBuyers:     newBuyers,
		Returning:     row.Buyers - newBuyers,
		Registered:    registered,
		Orders:        row.Orders,
		Revenue:       row.Revenue,
		MemberOrders:  row.MemberOrders,
		MemberRevenue: row.MemberRevenue,
		GuestOrders:   row.GuestOrders,
		GuestRevenue:  row.GuestRevenue,
	}
	if out.Buyers > 0 {
		out.RevenuePerBuyer = out.MemberRevenue / float64(out.Buyers)
		out.OrdersPerBuyer = float64(out.MemberOrders) / float64(out.Buyers)
		out.RepeatRate = float64(out.Returning) / float64(out.Buyers) * 100
	}
	return out, nil
}

// firstOrders — đơn ĐẦU TIÊN trong đời của từng khách có tài khoản.
//
// Không giới hạn theo kỳ: phải nhìn hết lịch sử mới biết khách mua trong kỳ là
// người mới hay người đã từng mua từ trước.
func (r *reportRepository) firstOrders(ctx context.Context) *gorm.DB {
	return r.db.WithContext(ctx).Table("orders o").
		Select("o.user_id AS user_id, MIN(o.created_at) AS first_at").
		Where("o.deleted_at IS NULL").
		Where("o.status NOT IN ?", deadStatuses).
		Where("o.user_id IS NOT NULL").
		Group("o.user_id")
}

func (r *reportRepository) newBuyers(ctx context.Context, p domain.ReportPeriod) (int64, error) {
	var total int64
	err := r.db.WithContext(ctx).Table("(?) AS f", r.firstOrders(ctx)).
		Where("f.first_at >= ? AND f.first_at < ?", p.From, p.To).
		Count(&total).Error
	return total, err
}

// Registrations đếm tài khoản KHÁCH HÀNG đăng ký mới trong kỳ (kể cả người chưa
// mua gì) — tài khoản nội bộ không nằm trong báo cáo này.
func (r *reportRepository) Registrations(ctx context.Context, p domain.ReportPeriod) (int64, error) {
	var total int64
	err := r.db.WithContext(ctx).Table("users u").
		Where("u.deleted_at IS NULL").
		Where("u.role_id = ?", domain.CustomerRoleID).
		Where("u.created_at >= ? AND u.created_at < ?", p.From, p.To).
		Count(&total).Error
	return total, err
}

func (r *reportRepository) TopCustomers(ctx context.Context, p domain.ReportPeriod, limit int) ([]domain.CustomerReportRow, error) {
	if limit <= 0 {
		limit = 20
	}

	var rows []struct {
		UserID      uint
		Name        string
		Email       string
		Phone       string
		Orders      int64
		Revenue     float64
		LastOrderAt *time.Time
		IsNew       bool
	}
	err := r.orders(ctx, p).
		Joins("JOIN users u ON u.id = o.user_id").
		Where("o.user_id IS NOT NULL").
		Joins("LEFT JOIN (?) AS f ON f.user_id = o.user_id", r.firstOrders(ctx)).
		Select(`u.id AS user_id,
			u.full_name AS name,
			u.email AS email,
			COALESCE(u.phone, '') AS phone,
			COUNT(*) AS orders,
			COALESCE(SUM(o.total_amount), 0) AS revenue,
			MAX(o.created_at) AS last_order_at,
			MAX(CASE WHEN f.first_at >= ? THEN 1 ELSE 0 END) AS is_new`, p.From).
		Group("u.id, u.full_name, u.email, u.phone").
		Order("revenue DESC").
		Limit(limit).
		Scan(&rows).Error
	if err != nil {
		return nil, err
	}

	// Số món phải đếm trên dòng hàng nên tách thành một truy vấn riêng: nhét
	// order_items vào câu trên là mỗi đơn bị nhân lên và cột doanh thu đội lên.
	units, err := r.unitsByCustomer(ctx, p)
	if err != nil {
		return nil, err
	}

	out := make([]domain.CustomerReportRow, 0, len(rows))
	for _, row := range rows {
		item := domain.CustomerReportRow{
			UserID: row.UserID, Name: row.Name, Email: row.Email, Phone: row.Phone,
			Orders: row.Orders, Revenue: row.Revenue, Units: units[row.UserID], IsNew: row.IsNew,
		}
		if row.LastOrderAt != nil {
			t := *row.LastOrderAt
			item.LastOrderAt = &t
		}
		if item.Orders > 0 {
			item.AOV = item.Revenue / float64(item.Orders)
		}
		out = append(out, item)
	}
	return out, nil
}

func (r *reportRepository) unitsByCustomer(ctx context.Context, p domain.ReportPeriod) (map[uint]int64, error) {
	var rows []struct {
		UserID uint
		Units  int64
	}
	err := r.orders(ctx, p).
		Joins("JOIN order_items oi ON oi.order_id = o.id").
		Where("o.user_id IS NOT NULL").
		Select("o.user_id AS user_id, COALESCE(SUM(oi.quantity), 0) AS units").
		Group("o.user_id").
		Scan(&rows).Error
	if err != nil {
		return nil, err
	}

	out := make(map[uint]int64, len(rows))
	for _, row := range rows {
		out[row.UserID] = row.Units
	}
	return out, nil
}

func (r *reportRepository) CustomerBuckets(ctx context.Context, p domain.ReportPeriod, groupBy string) (map[string]domain.CustomerBucket, error) {
	expr := groupExpr(groupBy)
	out := map[string]domain.CustomerBucket{}

	// 1. Số khách có mua trong từng mốc (chỉ khách có tài khoản).
	var buyerRows []struct {
		Label  string
		Buyers int64
	}
	err := r.orders(ctx, p).
		Where("o.user_id IS NOT NULL").
		Select(expr + " AS label, COUNT(DISTINCT o.user_id) AS buyers").
		Group("label").
		Scan(&buyerRows).Error
	if err != nil {
		return nil, err
	}
	buyers := make(map[string]int64, len(buyerRows))
	for _, row := range buyerRows {
		buyers[row.Label] = row.Buyers
		out[row.Label] = domain.CustomerBucket{Label: row.Label}
	}

	// 2. Khách MỚI rơi vào mốc nào — mốc chứa đơn đầu tiên trong đời của họ.
	//    Khách cũ = số khách mua trong mốc trừ đi số khách mới của chính mốc đó:
	//    khách mới luôn có đơn trong mốc mình xuất hiện, nên phép trừ này khớp
	//    đúng chứ không phải xấp xỉ.
	var newRows []struct {
		Label string
		Total int64
	}
	err = r.db.WithContext(ctx).Table("(?) AS f", r.firstOrders(ctx)).
		Where("f.first_at >= ? AND f.first_at < ?", p.From, p.To).
		Select(groupExprOn("f.first_at", groupBy) + " AS label, COUNT(*) AS total").
		Group("label").
		Scan(&newRows).Error
	if err != nil {
		return nil, err
	}
	for _, row := range newRows {
		b := out[row.Label]
		b.Label = row.Label
		b.NewBuyers = row.Total
		out[row.Label] = b
	}

	// 3. Tài khoản đăng ký mới theo mốc.
	var regRows []struct {
		Label string
		Total int64
	}
	err = r.db.WithContext(ctx).Table("users u").
		Where("u.deleted_at IS NULL").
		Where("u.role_id = ?", domain.CustomerRoleID).
		Where("u.created_at >= ? AND u.created_at < ?", p.From, p.To).
		Select(groupExprOn("u.created_at", groupBy) + " AS label, COUNT(*) AS total").
		Group("label").
		Scan(&regRows).Error
	if err != nil {
		return nil, err
	}
	for _, row := range regRows {
		b := out[row.Label]
		b.Label = row.Label
		b.Registered = row.Total
		out[row.Label] = b
	}

	for label, b := range out {
		b.Returning = buyers[label] - b.NewBuyers
		if b.Returning < 0 {
			b.Returning = 0
		}
		out[label] = b
	}
	return out, nil
}

// groupExprOn — như groupExpr nhưng cho một cột thời gian khác `o.created_at`
// (ngày mua đầu tiên, ngày đăng ký tài khoản).
func groupExprOn(column, groupBy string) string {
	switch groupBy {
	case domain.ReportGroupWeek:
		return "DATE_FORMAT(" + column + ", '%x-W%v')"
	case domain.ReportGroupMonth:
		return "DATE_FORMAT(" + column + ", '%Y-%m')"
	default:
		return "DATE_FORMAT(" + column + ", '%Y-%m-%d')"
	}
}
