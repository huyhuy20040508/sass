package repository

import (
	"context"

	"sass-api/internal/domain"
)

// ---------- Báo cáo chi phí & lợi nhuận ----------
//
// Dùng chung goodsItems với tab Hàng hoá: cùng tập dòng hàng, cùng ba ô lọc,
// nên cột Số lượng của hai tab luôn khớp nhau. Tổng giá bán thì KHÁC: ở đây đã
// trừ giảm giá cả đơn, tab Hàng hoá thì chưa.

// netLineExpr — tiền dòng hàng SAU khi chia giảm giá cả đơn (hạng thẻ, đổi
// điểm, voucher, giảm tay — gộp sẵn ở o.discount_amount) theo tỷ lệ tiền dòng.
// o.subtotal_amount = SUM(oi.total_price) của đơn nên các dòng chia vừa đủ.
const netLineExpr = "(oi.total_price - CASE WHEN o.subtotal_amount > 0 THEN o.discount_amount * oi.total_price / o.subtotal_amount ELSE 0 END)"

// ProfitRows gộp dòng hàng theo MẶT HÀNG: bán bao nhiêu, tiền bán, tiền vốn.
// Lãi và biên lãi do service tính (ProfitRow.TinhBien).
func (r *reportRepository) ProfitRows(ctx context.Context, p domain.ReportPeriod, f domain.GoodsFilter) ([]domain.ProfitRow, error) {
	var rows []domain.ProfitRow
	err := r.goodsItems(ctx, p, f).
		Joins("LEFT JOIN categories c ON c.id = p.category_id").
		Select(`COALESCE(oi.product_id, 0) AS product_id,
			COALESCE(NULLIF(MAX(p.sku), ''), MAX(oi.variant_sku), '') AS code,
			COALESCE(MAX(p.name), MAX(oi.product_name), '') AS name,
			COALESCE(MAX(c.name), '') AS category_name,
			COALESCE(SUM(oi.quantity), 0) AS quantity,
			COALESCE(SUM(` + netLineExpr + `), 0) AS revenue,
			COALESCE(SUM(` + costExpr + ` * oi.quantity), 0) AS cost`).
		// Hàng đã xoá hẳn (product_id NULL) gộp theo tên lúc bán, như tab Hàng hoá.
		Group("oi.product_id, CASE WHEN oi.product_id IS NULL THEN oi.product_name END").
		Order("revenue DESC, quantity DESC, name ASC").
		Scan(&rows).Error
	return rows, err
}

// ProfitUnsold — mặt hàng ĐANG BÁN mà kỳ này chưa bán được cái nào, cùng ba ô
// lọc của bảng. "Đã bán" xét theo cùng chi nhánh / nguồn đơn của kỳ; danh mục
// hàng của chi nhánh theo quy ước product_shops như UnsoldProducts.
func (r *reportRepository) ProfitUnsold(ctx context.Context, p domain.ReportPeriod, f domain.GoodsFilter) ([]domain.ProfitRow, error) {
	sold := r.items(ctx, p).
		Select("DISTINCT oi.product_id").
		Where("oi.product_id IS NOT NULL")

	q := r.db.WithContext(ctx).Table("products p").
		Joins("LEFT JOIN categories c ON c.id = p.category_id").
		Where("p.deleted_at IS NULL AND p.is_active = 1").
		Where("p.id NOT IN (?)", sold)
	if p.ShopID > 0 {
		q = q.Where(`(
			EXISTS (SELECT 1 FROM product_shops ps WHERE ps.product_id = p.id AND ps.shop_id = ?)
			OR NOT EXISTS (SELECT 1 FROM product_shops ps2 WHERE ps2.product_id = p.id)
		)`, p.ShopID)
	}
	if len(f.CategoryIDs) > 0 {
		q = q.Where("p.category_id IN ?", f.CategoryIDs)
	}
	if f.ProductID > 0 {
		q = q.Where("p.id = ?", f.ProductID)
	}
	if f.Keyword != "" {
		kw := "%" + f.Keyword + "%"
		q = q.Where("(p.name LIKE ? OR p.sku LIKE ?)", kw, kw)
	}

	var rows []domain.ProfitRow
	err := q.Select(`p.id AS product_id, COALESCE(p.sku, '') AS code, p.name,
			COALESCE(c.name, '') AS category_name`).
		Order("p.sort ASC, p.id ASC").
		Scan(&rows).Error
	return rows, err
}

// ProfitBuckets cộng tiền bán / tiền vốn theo mốc thời gian (ngày / tuần ISO /
// tháng — cùng khoá với labels() bên service).
func (r *reportRepository) ProfitBuckets(ctx context.Context, p domain.ReportPeriod, f domain.GoodsFilter, groupBy string) (map[string]domain.ProfitBucket, error) {
	expr := groupExpr(groupBy)
	var rows []domain.ProfitBucket
	err := r.goodsItems(ctx, p, f).
		Select(expr + ` AS ` + "`key`" + `,
			COALESCE(SUM(` + netLineExpr + `), 0) AS revenue,
			COALESCE(SUM(` + costExpr + ` * oi.quantity), 0) AS cost`).
		Group(expr).
		Scan(&rows).Error
	if err != nil {
		return nil, err
	}

	out := make(map[string]domain.ProfitBucket, len(rows))
	for _, b := range rows {
		b.Profit = b.Revenue - b.Cost
		out[b.Key] = b
	}
	return out, nil
}
