package repository

import (
	"context"

	"sass-api/internal/domain"
)

// ---------- Báo cáo doanh thu ----------
//
// Theo giờ / theo thứ dùng lại ByHour / ByWeekday, theo ngày dùng lại Buckets.
// Ở đây là bốn phép gộp riêng của tab doanh thu.

// hinhThucQR — hai cổng thanh toán QR, gộp thành cột "QR tự động".
var hinhThucQR = []string{domain.PaymentMethodPayOS, domain.PaymentMethodSePay}

// SalesDays gộp đơn theo NGÀY đặt. Một câu cho cả mười cột: mọi cột tính trên
// cùng một tập đơn nên không có chuyện hai cột lệch nhau.
func (r *reportRepository) SalesDays(ctx context.Context, p domain.ReportPeriod) ([]domain.SalesDay, error) {
	var rows []domain.SalesDay
	err := r.orders(ctx, p).
		Select(`DATE_FORMAT(o.created_at, '%Y-%m-%d') AS date,
			COUNT(*) AS orders,
			COALESCE(SUM(o.total_amount - o.vat_amount), 0) AS revenue,
			COALESCE(SUM(o.total_amount), 0) AS revenue_vat,
			COALESCE(SUM(CASE WHEN o.payment_status = ? THEN o.total_amount ELSE 0 END), 0) AS paid,
			COALESCE(SUM(CASE WHEN o.payment_status = ? AND o.payment_method = ? THEN o.total_amount ELSE 0 END), 0) AS cash,
			COALESCE(SUM(CASE WHEN o.payment_status = ? AND o.payment_method = ? THEN o.total_amount ELSE 0 END), 0) AS transfer,
			COALESCE(SUM(CASE WHEN o.payment_status = ? AND o.payment_method IN ? THEN o.total_amount ELSE 0 END), 0) AS auto_qr,
			COALESCE(SUM(CASE WHEN o.payment_status = ? AND o.payment_method NOT IN ? THEN o.total_amount ELSE 0 END), 0) AS other`,
			domain.OrderPaymentPaid,
			domain.OrderPaymentPaid, domain.PaymentMethodCash,
			domain.OrderPaymentPaid, domain.PaymentMethodBank,
			domain.OrderPaymentPaid, hinhThucQR,
			domain.OrderPaymentPaid, append([]string{domain.PaymentMethodCash, domain.PaymentMethodBank}, hinhThucQR...)).
		Group("date").
		Order("date DESC").
		Scan(&rows).Error
	if err != nil {
		return nil, err
	}

	for i := range rows {
		rows[i].Debt = max(rows[i].RevenueVAT-rows[i].Paid, 0)
	}
	return rows, nil
}

// ReturnsByDay cộng tiền hoàn của phiếu trả hàng theo NGÀY lập phiếu. Cùng tập
// phiếu với báo cáo tổng hợp (returnStatuses): chỉ phiếu đã có hàng quay về.
//
// Lọc nguồn đơn / hình thức theo ĐƠN GỐC của phiếu: chọn "Tiền mặt" thì hàng
// trả của đơn chuyển khoản không được lẫn vào.
func (r *reportRepository) ReturnsByDay(ctx context.Context, p domain.ReportPeriod) (map[string]float64, error) {
	q := r.db.WithContext(ctx).Table("order_returns r").
		Where("r.deleted_at IS NULL").
		Where("r.status IN ?", returnStatuses).
		Where("r.created_at >= ? AND r.created_at < ?", p.From, p.To)
	if p.ShopID > 0 {
		q = q.Where("r.shop_id = ?", p.ShopID)
	}
	if p.Channel != "" || p.PaymentMethods != nil {
		q = q.Joins("JOIN orders o ON o.id = r.order_id")
		if p.Channel != "" {
			q = q.Where("o.channel = ?", p.Channel)
		}
		if p.PaymentMethods != nil {
			q = q.Where("o.payment_method IN ?", p.PaymentMethods)
		}
	}

	var rows []struct {
		Date   string
		Refund float64
	}
	err := q.Select("DATE_FORMAT(r.created_at, '%Y-%m-%d') AS date, COALESCE(SUM(r.refund_amount), 0) AS refund").
		Group("date").
		Scan(&rows).Error
	if err != nil {
		return nil, err
	}

	out := make(map[string]float64, len(rows))
	for _, row := range rows {
		out[row.Date] = row.Refund
	}
	return out, nil
}

// ByMonth gộp theo tháng trong năm, key "1".."12" — biểu đồ "Doanh thu theo
// tháng" của v2 luôn bày đủ T1…T12.
func (r *reportRepository) ByMonth(ctx context.Context, p domain.ReportPeriod) ([]domain.ReportSlice, error) {
	return r.sliceByOrderColumn(ctx, p, "MONTH(o.created_at)", 0)
}

// SalesOrders liệt kê từng hoá đơn của kỳ (hộp chi tiết của MỘT ngày), mới trước.
//
// Số món và tiền trả hàng đếm bằng câu riêng: nối thẳng order_items hay
// order_returns vào câu trên thì mỗi đơn nhân lên theo số dòng và cột tiền đội lên.
func (r *reportRepository) SalesOrders(ctx context.Context, p domain.ReportPeriod) ([]domain.SalesOrderRow, error) {
	var rows []domain.SalesOrderRow
	err := r.orders(ctx, p).
		// Một đơn một khách — nối theo khoá chính, không nhân dòng.
		Joins("LEFT JOIN users u ON u.id = o.user_id").
		Select(`o.id, o.order_code AS code,
			COALESCE(NULLIF(o.channel, ''), ?) AS channel,
			o.payment_method,
			o.subtotal_amount AS subtotal,
			o.surcharge_amount AS surcharge,
			o.discount_amount AS discount,
			o.total_amount - o.vat_amount AS revenue,
			o.vat_amount AS vat,
			o.total_amount AS total,
			CASE WHEN o.payment_status = ? THEN o.total_amount ELSE 0 END AS paid,
			COALESCE(u.customer_code, '') AS customer_code,
			COALESCE(u.full_name, o.recipient_name, '') AS customer_name,
			COALESCE(NULLIF(u.phone, ''), o.recipient_phone, '') AS phone,
			o.created_at`, domain.OrderChannelWeb, domain.OrderPaymentPaid).
		Order("o.created_at DESC, o.id DESC").
		Scan(&rows).Error
	if err != nil || len(rows) == 0 {
		return rows, err
	}

	ids := make([]uint, len(rows))
	for i, row := range rows {
		ids[i] = row.ID
	}
	var mon []struct {
		OrderID uint
		Units   int64
	}
	err = r.db.WithContext(ctx).Table("order_items oi").
		Select("oi.order_id, COALESCE(SUM(oi.quantity), 0) AS units").
		Where("oi.order_id IN ?", ids).
		Group("oi.order_id").
		Scan(&mon).Error
	if err != nil {
		return nil, err
	}
	soMon := make(map[uint]int64, len(mon))
	for _, m := range mon {
		soMon[m.OrderID] = m.Units
	}

	var tra []struct {
		OrderID uint
		Refund  float64
	}
	err = r.db.WithContext(ctx).Table("order_returns r").
		Select("r.order_id, COALESCE(SUM(r.refund_amount), 0) AS refund").
		Where("r.order_id IN ?", ids).
		Where("r.deleted_at IS NULL").
		Where("r.status IN ?", returnStatuses).
		Group("r.order_id").
		Scan(&tra).Error
	if err != nil {
		return nil, err
	}
	tienTra := make(map[uint]float64, len(tra))
	for _, t := range tra {
		tienTra[t.OrderID] = t.Refund
	}

	for i := range rows {
		rows[i].Units = soMon[rows[i].ID]
		rows[i].Returns = tienTra[rows[i].ID]
		rows[i].Debt = max(rows[i].Total-rows[i].Paid, 0)
	}
	return rows, nil
}
