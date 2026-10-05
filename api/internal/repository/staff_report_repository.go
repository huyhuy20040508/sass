package repository

import (
	"context"
	"strings"
	"time"

	"sass-api/internal/domain"
)

// ---------- Báo cáo ca ----------

// StaffShiftRows gộp đơn đã thu tiền theo (ngày × ca × nhân viên), mới trước.
//
// Gắn đơn quầy vào ca theo khoảng giờ như gopDonTheoCa (Báo cáo kết ca): cùng
// chi nhánh, created_at nằm trong [opened_at, closed_at], ca chưa đóng thì cắt ở
// "bây giờ" lấy từ Go. Chi nhánh chỉ có MỘT ca mở một lúc nên một đơn không rơi
// vào hai ca. Đơn web không nối với ca (điều kiện o.channel = pos nằm ngay trong
// ON) nên luôn ra loại online.
//
// users / employees nối theo khoá chính và khoá duy nhất (uq_employees_user) từ
// dòng đơn đã lọc theo cửa hàng, nên không kéo dữ liệu cửa hàng khác vào và
// không nhân dòng.
func (r *reportRepository) StaffShiftRows(ctx context.Context, p domain.ReportPeriod, f domain.StaffReportFilter) ([]domain.StaffShiftRow, error) {
	q := r.orders(ctx, p).
		Where("o.payment_status = ?", domain.OrderPaymentPaid).
		Joins(`LEFT JOIN work_shifts ws ON o.channel = ?
			AND ws.shop_id = o.shop_id
			AND o.created_at >= ws.opened_at
			AND o.created_at <= COALESCE(ws.closed_at, ?)`, domain.OrderChannelPOS, time.Now()).
		// Người lập đơn; đơn cũ chưa ghi người lập thì lùi về người mở ca.
		Joins("LEFT JOIN users u ON u.id = COALESCE(o.created_by, ws.opened_by)").
		Joins("LEFT JOIN employees e ON e.user_id = u.id AND e.deleted_at IS NULL").
		// Đơn web chỉ tính khi người lập là nhân viên — khách tự đặt thì không.
		Where("(o.channel = ? OR u.role_id IN ?)", domain.OrderChannelPOS, domain.InternalRoleIDs)

	if f.Area != "" {
		// Cùng luật với domain.CuaVao: cột rỗng (tài khoản trước migration 0015)
		// thì cửa vào suy từ vai trò — admin đi cả hai cửa, staff chỉ có quầy.
		var vaiTro []uint
		for _, r := range domain.InternalRoleIDs {
			if domain.CoCua("", r, f.Area) {
				vaiTro = append(vaiTro, r)
			}
		}
		q = q.Where("(FIND_IN_SET(?, u.access_areas) > 0 OR (COALESCE(u.access_areas, '') = '' AND u.role_id IN ?))",
			f.Area, vaiTro)
	}
	if f.UserID > 0 {
		q = q.Where("u.id = ?", f.UserID)
	}
	if kw := strings.TrimSpace(f.Keyword); kw != "" {
		like := "%" + kw + "%"
		q = q.Where("(e.code LIKE ? OR e.full_name LIKE ? OR u.full_name LIKE ?)", like, like, like)
	}

	var rows []domain.StaffShiftRow
	err := q.Select(`DATE_FORMAT(o.created_at, '%Y-%m-%d') AS date,
			CASE WHEN o.channel <> ? THEN ? WHEN ws.id IS NULL THEN ? ELSE ? END AS kind,
			COALESCE(ws.id, 0) AS shift_id,
			COALESCE(u.id, 0) AS user_id,
			COALESCE(MAX(e.code), '') AS employee_code,
			COALESCE(MAX(NULLIF(e.full_name, '')), MAX(u.full_name), '') AS name,
			COUNT(*) AS order_count,
			COALESCE(SUM(o.total_amount), 0) AS revenue`,
		domain.OrderChannelPOS, domain.CaLoaiOnline, domain.CaLoaiNgoaiCa, domain.CaLoaiCa).
		Group("date, kind, shift_id, user_id").
		Order("date DESC, shift_id DESC, revenue DESC").
		Scan(&rows).Error
	return rows, err
}
