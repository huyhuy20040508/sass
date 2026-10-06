package repository

import (
	"context"
	"strings"

	"sass-api/internal/domain"
)

// ---------- Báo cáo nhân viên (hoa hồng) ----------

// EmployeeOrders liệt kê từng đơn đã thu tiền do nhân viên lập trong kỳ, mới
// trước — service gộp theo người. Ba câu như SalesOrders: số món và tiền trả
// hàng đếm bằng câu riêng, nối thẳng order_items / order_returns vào câu đơn là
// mỗi đơn nhân lên theo số dòng và tiền đội lên.
//
// Mã, tên, tỉ lệ đọc bản CHỤP trên đơn (migration 0074); đơn không có bản chụp
// (người lập không có hồ sơ lúc bán) mới lùi về hồ sơ hiện tại.
//
// users (người lập, người mua) và employees nối theo khoá chính / khoá duy nhất
// (uq_employees_user) từ dòng đơn đã lọc theo cửa hàng — không nhân dòng, không
// kéo dữ liệu cửa hàng khác vào.
func (r *reportRepository) EmployeeOrders(ctx context.Context, p domain.ReportPeriod, f domain.StaffReportFilter) ([]domain.EmployeeOrder, error) {
	q := r.orders(ctx, p).
		Where("o.payment_status = ?", domain.OrderPaymentPaid).
		Joins("JOIN users u ON u.id = o.created_by").
		Joins("LEFT JOIN employees e ON e.user_id = u.id AND e.deleted_at IS NULL").
		Joins("LEFT JOIN users c ON c.id = o.user_id").
		// Người lập là nhân viên — đơn khách tự đặt không thuộc ai.
		Where("u.role_id IN ?", domain.InternalRoleIDs)

	if f.Area != "" {
		// Cùng luật với domain.CuaVao, như StaffShiftRows: cột rỗng thì cửa vào
		// suy từ vai trò.
		var vaiTro []uint
		for _, id := range domain.InternalRoleIDs {
			if domain.CoCua("", id, f.Area) {
				vaiTro = append(vaiTro, id)
			}
		}
		q = q.Where("(FIND_IN_SET(?, u.access_areas) > 0 OR (COALESCE(u.access_areas, '') = '' AND u.role_id IN ?))",
			f.Area, vaiTro)
	}
	if f.UserID > 0 {
		q = q.Where("u.id = ?", f.UserID)
	}
	if kw := strings.TrimSpace(f.Keyword); kw != "" {
		// Ô tìm của v2: "theo nhân viên, hoá đơn".
		like := "%" + kw + "%"
		q = q.Where("(o.staff_code LIKE ? OR o.staff_name LIKE ? OR e.code LIKE ? OR e.full_name LIKE ? OR u.full_name LIKE ? OR o.order_code LIKE ?)",
			like, like, like, like, like, like)
	}

	var rows []domain.EmployeeOrder
	err := q.Select(`o.id, o.order_code AS code, o.created_at,
			u.id AS user_id,
			COALESCE(o.staff_code, e.code, '') AS employee_code,
			COALESCE(NULLIF(o.staff_name, ''), NULLIF(e.full_name, ''), u.full_name, '') AS name,
			COALESCE(o.staff_commission_rate, e.commission_rate, 0) AS rate,
			COALESCE(NULLIF(c.full_name, ''), o.recipient_name, '') AS customer_name,
			o.total_amount - o.vat_amount AS revenue,
			o.total_amount AS revenue_vat`).
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
		OrderID  uint
		Quantity int64
		Products string
	}
	err = r.db.WithContext(ctx).Table("order_items oi").
		Select("oi.order_id, COALESCE(SUM(oi.quantity), 0) AS quantity, GROUP_CONCAT(DISTINCT oi.product_name ORDER BY oi.id SEPARATOR ', ') AS products").
		Where("oi.order_id IN ?", ids).
		Group("oi.order_id").
		Scan(&mon).Error
	if err != nil {
		return nil, err
	}
	theoDon := make(map[uint]int, len(mon))
	for i, m := range mon {
		theoDon[m.OrderID] = i
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
		if k, co := theoDon[rows[i].ID]; co {
			rows[i].Quantity = mon[k].Quantity
			rows[i].Products = mon[k].Products
		}
		rows[i].Returned = tienTra[rows[i].ID]
	}
	return rows, nil
}
