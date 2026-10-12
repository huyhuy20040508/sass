package repository

import (
	"context"
	"strconv"
	"strings"
	"time"

	"gorm.io/gorm"

	"sass-api/internal/domain"
)

// ---------- Báo cáo chương trình khuyến mại ----------

// promotionOrders — đơn còn hiệu lực trong kỳ nối với chương trình đã áp của
// tab. Một đơn chỉ có một dòng cho mỗi chương trình, nên gộp theo chương trình
// không nhân tiền đơn lên.
func (r *reportRepository) promotionOrders(ctx context.Context, p domain.ReportPeriod, f domain.PromotionReportFilter) *gorm.DB {
	kind, loai := f.KindVaLoai()
	q := r.orders(ctx, p).
		Joins("JOIN order_promotions op ON op.order_id = o.id").
		Where("op.kind = ?", kind)
	if loai != nil {
		q = q.Where("op.program_type IN ?", loai)
	}
	if f.ProgramID > 0 {
		q = q.Where("op.program_id = ?", f.ProgramID)
	}
	if f.ProgramIDs != nil {
		q = q.Where("op.program_id IN ?", f.ProgramIDs)
	}

	return q
}

// voucherOrders — đơn còn hiệu lực trong kỳ đã tiêu một mã do chương trình
// Voucher/Coupon phát ra. vouchers nối theo khoá chính từ lượt dùng.
func (r *reportRepository) voucherOrders(ctx context.Context, p domain.ReportPeriod, f domain.PromotionReportFilter) *gorm.DB {
	q := r.orders(ctx, p).
		Joins("JOIN voucher_usages vu ON vu.order_id = o.id").
		Joins("JOIN vouchers v ON v.id = vu.voucher_id").
		Where("v.program_id IS NOT NULL")
	if f.ProgramID > 0 {
		q = q.Where("v.program_id = ?", f.ProgramID)
	}
	if f.ProgramIDs != nil {
		q = q.Where("v.program_id IN ?", f.ProgramIDs)
	}

	return q
}

// PromotionUsage — mỗi chương trình có đơn trong kỳ một dòng. Mã / tên / loại
// lấy theo bản chụp của đơn MỚI NHẤT; service đè bằng đầu chương trình nếu nó
// còn.
func (r *reportRepository) PromotionUsage(ctx context.Context, p domain.ReportPeriod, f domain.PromotionReportFilter) ([]domain.PromotionReportRow, error) {
	var rows []domain.PromotionReportRow
	if f.Tab == domain.KMBaoCaoVoucher {
		err := r.voucherOrders(ctx, p, f).
			Select(`v.program_id,
				COUNT(DISTINCT o.id) AS order_count,
				COALESCE(SUM(o.subtotal_amount), 0) AS goods_amount,
				COALESCE(SUM(o.total_amount), 0) AS revenue,
				COALESCE(SUM(vu.discount_amount), 0) AS discount`).
			Group("v.program_id").
			Scan(&rows).Error

		return rows, err
	}

	err := r.promotionOrders(ctx, p, f).
		Select(`op.program_id,
			SUBSTRING_INDEX(GROUP_CONCAT(op.program_code ORDER BY o.id DESC SEPARATOR '\n'), '\n', 1) AS code,
			SUBSTRING_INDEX(GROUP_CONCAT(op.program_name ORDER BY o.id DESC SEPARATOR '\n'), '\n', 1) AS name,
			MAX(op.program_type) AS type,
			COUNT(DISTINCT o.id) AS order_count,
			COALESCE(SUM(o.subtotal_amount), 0) AS goods_amount,
			COALESCE(SUM(o.total_amount), 0) AS revenue,
			COALESCE(SUM(op.quantity), 0) AS quantity,
			COALESCE(SUM(op.gift_quantity), 0) AS gift_quantity,
			COALESCE(SUM(op.base_amount), 0) AS base_amount,
			COALESCE(SUM(op.discount_amount), 0) AS discount`).
		Group("op.program_id").
		Scan(&rows).Error

	return rows, err
}

// PromotionUsageTotals — số đơn, tiền hàng, doanh thu của CẢ TAB, mỗi đơn một
// lần (đơn áp hai chương trình cùng tab chỉ đếm một).
func (r *reportRepository) PromotionUsageTotals(ctx context.Context, p domain.ReportPeriod, f domain.PromotionReportFilter) (domain.PromotionReportRow, error) {
	var ids *gorm.DB
	if f.Tab == domain.KMBaoCaoVoucher {
		ids = r.voucherOrders(ctx, p, f).Distinct("o.id")
	} else {
		ids = r.promotionOrders(ctx, p, f).Distinct("o.id")
	}

	var out domain.PromotionReportRow
	err := r.db.WithContext(ctx).Table("orders t").
		Where("t.id IN (?)", ids).
		Select(`COUNT(*) AS order_count,
			COALESCE(SUM(t.subtotal_amount), 0) AS goods_amount,
			COALESCE(SUM(t.total_amount), 0) AS revenue`).
		Scan(&out).Error

	return out, err
}

// PromotionOrders — từng đơn của một chương trình trong kỳ, mới trước.
func (r *reportRepository) PromotionOrders(ctx context.Context, p domain.ReportPeriod, f domain.PromotionReportFilter) ([]domain.PromotionOrderRow, error) {
	var q *gorm.DB
	var rieng string
	if f.Tab == domain.KMBaoCaoVoucher {
		q = r.voucherOrders(ctx, p, f)
		rieng = `0 AS quantity, 0 AS gift_quantity, 0 AS base_amount,
			vu.discount_amount AS discount, v.code AS voucher_code`
	} else {
		q = r.promotionOrders(ctx, p, f)
		rieng = `op.quantity, op.gift_quantity, op.base_amount,
			op.discount_amount AS discount, '' AS voucher_code`
	}

	var rows []domain.PromotionOrderRow
	err := q.
		// Một đơn một khách — nối theo khoá chính, không nhân dòng.
		Joins("LEFT JOIN users u ON u.id = o.user_id").
		Select(`o.id, o.order_code AS code, o.created_at,
			COALESCE(NULLIF(u.full_name, ''), o.recipient_name, '') AS customer_name,
			COALESCE(NULLIF(u.phone, ''), o.recipient_phone, '') AS phone,
			o.subtotal_amount AS goods_amount,
			o.discount_amount AS total_discount,
			o.total_amount AS total, ` + rieng).
		Order("o.created_at DESC, o.id DESC").
		Scan(&rows).Error

	return rows, err
}

// PromotionCatalog — đầu chương trình của tab, kèm chi nhánh áp dụng. Bảng
// chương trình nhỏ (vài chục dòng) nên đọc hết rồi lọc ở service.
func (r *reportRepository) PromotionCatalog(ctx context.Context, tab string) ([]domain.PromotionCatalogItem, error) {
	var rows []struct {
		domain.PromotionCatalogItem
		Shops string
	}
	db := r.db.WithContext(ctx)
	var err error
	switch tab {
	case domain.KMBaoCaoVoucher:
		err = db.Table("voucher_programs c").
			Select(`c.id, c.code, c.name, 0 AS type, 1 AS status, c.status = ? AS approved,
				c.no_time_limit, c.start_date, c.end_date, c.all_shops,
				COALESCE(c.released_at, c.created_at) AS co_tu_luc,
				(SELECT GROUP_CONCAT(s.shop_id) FROM voucher_program_shops s WHERE s.program_id = c.id) AS shops,
				(SELECT COUNT(*) FROM vouchers v WHERE v.program_id = c.id AND v.deleted_at IS NULL) AS code_count,
				(SELECT COUNT(*) FROM vouchers v WHERE v.program_id = c.id AND v.deleted_at IS NULL AND v.used_count > 0) AS code_used`,
				domain.VoucherProgramPhatHanh).
			Scan(&rows).Error
	case domain.KMBaoCaoDongGia:
		err = db.Table("fixed_prices c").
			Select(`c.id, c.code, c.name, c.type, c.status, c.approved,
				c.no_time_limit, c.start_date, c.end_date, c.all_shops, c.created_at AS co_tu_luc,
				(SELECT GROUP_CONCAT(s.shop_id) FROM fixed_price_shops s WHERE s.fixed_price_id = c.id) AS shops`).
			Scan(&rows).Error
	default:
		_, loai := domain.PromotionReportFilter{Tab: tab}.KindVaLoai()
		err = db.Table("promotion_programs c").
			Where("c.type IN ?", loai).
			Select(`c.id, c.code, c.name, c.type, c.status, c.approved,
				c.no_time_limit, c.start_date, c.end_date, c.all_shops, c.created_at AS co_tu_luc,
				(SELECT GROUP_CONCAT(s.shop_id) FROM promotion_program_shops s WHERE s.program_id = c.id) AS shops`).
			Scan(&rows).Error
	}
	if err != nil {
		return nil, err
	}

	out := make([]domain.PromotionCatalogItem, len(rows))
	for i, row := range rows {
		out[i] = row.PromotionCatalogItem
		for _, v := range strings.Split(row.Shops, ",") {
			if id, err := strconv.ParseUint(strings.TrimSpace(v), 10, 64); err == nil {
				out[i].ShopIDs = append(out[i].ShopIDs, uint(id))
			}
		}
		out[i].StartDate = ngayCuc(out[i].StartDate)
		out[i].EndDate = ngayCuc(out[i].EndDate)
		out[i].CoTuLuc = gioMay(out[i].CoTuLuc)
	}

	return out, nil
}

// ngayCuc — cột DATE đọc ra mốc 00:00 UTC; đưa về 00:00 giờ máy để so với kỳ
// báo cáo (cũng là giờ máy), không thì lệch một ngày ở hai đầu.
func ngayCuc(t *time.Time) *time.Time {
	if t == nil {
		return nil
	}
	d := time.Date(t.Year(), t.Month(), t.Day(), 0, 0, 0, 0, time.Local)

	return &d
}

// gioMay — như ngayCuc nhưng giữ cả giờ: cột DATETIME đọc ra theo múi của DSN
// (DSN không khai loc thì là UTC), đọc lại đúng mặt số trên đồng hồ theo giờ máy.
func gioMay(t *time.Time) *time.Time {
	if t == nil {
		return nil
	}
	d := time.Date(t.Year(), t.Month(), t.Day(), t.Hour(), t.Minute(), t.Second(), t.Nanosecond(), time.Local)

	return &d
}
