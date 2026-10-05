package repository

import (
	"context"

	"gorm.io/gorm"

	"sass-api/internal/domain"
)

// ---------- Báo cáo hàng hoá ----------

// goodsLineTotal — tiền một dòng hàng GỒM thuế: tiền dòng (chưa thuế) + thuế của dòng.
const goodsLineTotal = "oi.total_price + oi.vat_amount"

// CategoryTree đọc id / cha của mọi nhóm hàng để service nở nhóm con.
func (r *reportRepository) CategoryTree(ctx context.Context) ([]domain.Category, error) {
	var cats []domain.Category
	err := r.db.WithContext(ctx).Select("id", "parent_id").Find(&cats).Error
	return cats, err
}

// goodsItems là items() cộng ba ô lọc của tab hàng hoá.
func (r *reportRepository) goodsItems(ctx context.Context, p domain.ReportPeriod, f domain.GoodsFilter) *gorm.DB {
	q := r.items(ctx, p)
	if len(f.CategoryIDs) > 0 {
		q = q.Where("p.category_id IN ?", f.CategoryIDs)
	}
	if f.ProductID > 0 {
		q = q.Where("oi.product_id = ?", f.ProductID)
	}
	if f.Keyword != "" {
		kw := "%" + f.Keyword + "%"
		// Tìm cả tên / mã CHỤP lúc bán: hàng đã đổi tên vẫn tìm được bằng tên cũ
		// in trên hoá đơn.
		q = q.Where("(p.name LIKE ? OR p.sku LIKE ? OR oi.product_name LIKE ? OR oi.variant_sku LIKE ?)", kw, kw, kw, kw)
	}
	return q
}

// GoodsRows gộp dòng hàng theo MẶT HÀNG, bán nhiều trước.
//
// Dòng của mặt hàng đã xoá hẳn (product_id NULL) gộp theo tên lúc bán thay vì
// bỏ đi: bỏ là cột tổng của bảng hụt so với số tiền thật đã bán trong kỳ.
func (r *reportRepository) GoodsRows(ctx context.Context, p domain.ReportPeriod, f domain.GoodsFilter) ([]domain.GoodsRow, error) {
	var rows []domain.GoodsRow
	err := r.goodsItems(ctx, p, f).
		Select(`COALESCE(oi.product_id, 0) AS product_id,
			COALESCE(NULLIF(MAX(p.sku), ''), MAX(oi.variant_sku), '') AS code,
			COALESCE(MAX(p.name), MAX(oi.product_name), '') AS name,
			COALESCE(SUM(oi.quantity), 0) AS quantity,
			COALESCE(SUM(oi.total_price), 0) AS amount,
			COALESCE(SUM(` + goodsLineTotal + `), 0) AS total`).
		Group("oi.product_id, CASE WHEN oi.product_id IS NULL THEN oi.product_name END").
		Order("quantity DESC, total DESC, name ASC").
		Scan(&rows).Error
	return rows, err
}

// GoodsUnitsBy cộng SỐ LƯỢNG bán theo một biểu thức thời gian của đơn (giờ,
// tháng…) — hai biểu đồ số lượng của tab hàng hoá.
func (r *reportRepository) GoodsUnitsBy(ctx context.Context, p domain.ReportPeriod, f domain.GoodsFilter, column string) ([]domain.ReportSlice, error) {
	var rows []domain.ReportSlice
	err := r.goodsItems(ctx, p, f).
		Select(column + " AS `key`, COUNT(DISTINCT o.id) AS orders, COALESCE(SUM(oi.quantity), 0) AS units, COALESCE(SUM(" + goodsLineTotal + "), 0) AS revenue").
		Group(column).
		Scan(&rows).Error
	return rows, err
}

// GoodsWeekday — số lượng bán theo thứ (0 = Thứ Hai … 6 = Chủ Nhật) của từng
// mặt hàng trong productIDs.
func (r *reportRepository) GoodsWeekday(ctx context.Context, p domain.ReportPeriod, f domain.GoodsFilter, productIDs []uint) (map[uint][7]int64, error) {
	out := make(map[uint][7]int64, len(productIDs))
	if len(productIDs) == 0 {
		return out, nil
	}

	var rows []struct {
		ProductID uint
		Thu       int
		Units     int64
	}
	err := r.goodsItems(ctx, p, f).
		Where("oi.product_id IN ?", productIDs).
		Select("oi.product_id, WEEKDAY(o.created_at) AS thu, COALESCE(SUM(oi.quantity), 0) AS units").
		Group("oi.product_id, thu").
		Scan(&rows).Error
	if err != nil {
		return nil, err
	}

	for _, row := range rows {
		if row.Thu < 0 || row.Thu > 6 {
			continue
		}
		d := out[row.ProductID]
		d[row.Thu] = row.Units
		out[row.ProductID] = d
	}
	return out, nil
}

// GoodsOrders — mỗi hoá đơn có bán mặt hàng productID một dòng, mới trước.
// Một đơn có hai dòng cùng món (hai biến thể) thì cộng lại thành một.
func (r *reportRepository) GoodsOrders(ctx context.Context, p domain.ReportPeriod, productID uint) ([]domain.GoodsOrderRow, error) {
	var rows []domain.GoodsOrderRow
	err := r.items(ctx, p).
		Where("oi.product_id = ?", productID).
		Select(`o.id, o.order_code AS code,
			COALESCE(NULLIF(o.channel, ''), ?) AS channel,
			COALESCE(NULLIF(MAX(p.sku), ''), MAX(oi.variant_sku), '') AS item_code,
			COALESCE(MAX(p.name), MAX(oi.product_name), '') AS item_name,
			COALESCE(SUM(oi.quantity), 0) AS quantity,
			COALESCE(SUM(`+goodsLineTotal+`), 0) AS total,
			o.created_at`, domain.OrderChannelWeb).
		Group("o.id, o.order_code, o.channel, o.created_at").
		Order("o.created_at DESC, o.id DESC").
		Scan(&rows).Error
	return rows, err
}
