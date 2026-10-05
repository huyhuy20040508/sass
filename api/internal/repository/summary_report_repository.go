package repository

import (
	"context"

	"gorm.io/gorm"

	"sass-api/internal/domain"
)

// Phần tiền bán hàng, số món và doanh thu theo giờ dùng lại Totals /
// ByPaymentMethod / ByHour. Ở đây chỉ có ba phép gộp mà bốn báo cáo kia chưa có.

// ItemKinds đếm số MẶT HÀNG khác nhau đã bán. Biến thể bị xoá sau khi bán thì
// dòng hàng mất product_variant_id — lùi về tên đã chụp trên dòng.
func (r *reportRepository) ItemKinds(ctx context.Context, p domain.ReportPeriod) (int64, error) {
	var n int64
	err := r.items(ctx, p).
		Select("COUNT(DISTINCT COALESCE(CAST(oi.product_variant_id AS CHAR), CONCAT('ten:', oi.product_name)))").
		Scan(&n).Error
	return n, err
}

// CashbookTotals cộng phiếu thu / phiếu chi lập trong kỳ. Không lọc nguồn đơn:
// phiếu thu chi là sổ của cả quầy. Table() nên phải tự khai deleted_at.
func (r *reportRepository) CashbookTotals(ctx context.Context, p domain.ReportPeriod) (domain.SummaryCashbook, error) {
	var out domain.SummaryCashbook

	q := r.db.WithContext(ctx).Table("income_expenses ie").
		Where("ie.deleted_at IS NULL").
		Where("ie.created_at >= ? AND ie.created_at < ?", p.From, p.To)
	if p.ShopID > 0 {
		q = q.Where("ie.shop_id = ?", p.ShopID)
	}

	err := q.Select(`
			COALESCE(SUM(CASE WHEN ie.type = ? THEN 1 ELSE 0 END), 0) AS income_count,
			COALESCE(SUM(CASE WHEN ie.type = ? THEN 1 ELSE 0 END), 0) AS expense_count,
			COALESCE(SUM(CASE WHEN ie.type = ? THEN ie.amount ELSE 0 END), 0) AS income,
			COALESCE(SUM(CASE WHEN ie.type = ? THEN ie.amount ELSE 0 END), 0) AS expense`,
		domain.ThuChiPhieuThu, domain.ThuChiPhieuChi, domain.ThuChiPhieuThu, domain.ThuChiPhieuChi).
		Scan(&out).Error
	return out, err
}

// returnStatuses — phiếu trả ĐÃ có hàng quay về.
var returnStatuses = []string{domain.ReturnStatusReceived, domain.ReturnStatusRefunded}

// ReturnTotals gộp hàng bị trả trong kỳ. Hai câu: gộp chung thì tiền hoàn của
// mỗi phiếu bị nhân lên theo số dòng hàng của nó.
func (r *reportRepository) ReturnTotals(ctx context.Context, p domain.ReportPeriod) (domain.SummaryReturns, error) {
	var out domain.SummaryReturns

	loc := func(q *gorm.DB) *gorm.DB {
		q = q.Where("r.deleted_at IS NULL").
			Where("r.status IN ?", returnStatuses).
			Where("r.created_at >= ? AND r.created_at < ?", p.From, p.To)
		if p.ShopID > 0 {
			q = q.Where("r.shop_id = ?", p.ShopID)
		}
		// Lọc nguồn theo ĐƠN GỐC của phiếu trả.
		if p.Channel != "" {
			q = q.Joins("JOIN orders o ON o.id = r.order_id").Where("o.channel = ?", p.Channel)
		}
		return q
	}

	var dau struct {
		ReturnCount int64
		Refund      float64
	}
	err := loc(r.db.WithContext(ctx).Table("order_returns r")).
		Select("COUNT(*) AS return_count, COALESCE(SUM(r.refund_amount), 0) AS refund").
		Scan(&dau).Error
	if err != nil {
		return out, err
	}

	var dong struct {
		LineCount int64
		Units     int64
	}
	err = loc(r.db.WithContext(ctx).Table("order_return_items ri").
		Joins("JOIN order_returns r ON r.id = ri.return_id")).
		Select("COUNT(*) AS line_count, COALESCE(SUM(ri.quantity), 0) AS units").
		Scan(&dong).Error
	if err != nil {
		return out, err
	}

	return domain.SummaryReturns{Returns: dau.ReturnCount, Lines: dong.LineCount, Units: dong.Units, Refund: dau.Refund}, nil
}
