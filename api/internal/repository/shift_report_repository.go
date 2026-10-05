package repository

import (
	"context"
	"strings"
	"time"

	"sass-api/internal/domain"
)

// BaoCaoKetCa — báo cáo kết ca, xem domain/shift_report.go.
//
// Ba bước, mỗi bước một câu:
//  1. Lấy id của MỌI ca khớp bộ lọc (mới trước). Ca của một cửa hàng đếm bằng
//     vài ca mỗi ngày nên danh sách id nhỏ; có nó thì tổng số dòng, trang đang
//     xem và dòng tổng cả kỳ cùng đi từ một nguồn, không lệch nhau được.
//  2. Đọc thông tin của các ca trong TRANG đang xem (tên người, chi nhánh, mã
//     nhân sự).
//  3. Gộp đơn quầy theo từng ca cho TẤT CẢ id ở bước 1 — trang đang xem lấy
//     phần của mình, phần còn lại cộng vào dòng tổng.
//
// Cả ba câu đi qua Table("… ws") / Table("orders o"), nên bộ lọc cửa hàng
// (tenant_scope.go) chèn điều kiện vào bảng chính. Các bảng JOIN thêm (users,
// employees, shops) đều nối theo KHOÁ CHÍNH / khoá duy nhất của dòng đã lọc, nên
// không kéo được dữ liệu của cửa hàng khác vào.
func (r *caLamViecRepository) BaoCaoKetCa(
	ctx context.Context, f domain.BaoCaoCaFilter,
) ([]domain.BaoCaoCaDong, int64, domain.BaoCaoCaTong, error) {
	var tong domain.BaoCaoCaTong

	// ---- 1. id của mọi ca khớp bộ lọc ----
	q := r.db.WithContext(ctx).Table("work_shifts ws").
		Where("ws.opened_at >= ? AND ws.opened_at < ?", f.From, f.To)
	if f.ShopID > 0 {
		q = q.Where("ws.shop_id = ?", f.ShopID)
	}
	if f.UserID > 0 {
		q = q.Where("ws.opened_by = ?", f.UserID)
	}
	if kw := strings.TrimSpace(f.Keyword); kw != "" {
		like := "%" + kw + "%"
		q = q.Joins("LEFT JOIN users u ON u.id = ws.opened_by").
			Joins("LEFT JOIN employees e ON e.user_id = ws.opened_by").
			Where("e.code LIKE ? OR e.full_name LIKE ? OR u.full_name LIKE ?", like, like, like)
	}

	var ids []uint
	if err := q.Order("ws.opened_at DESC, ws.id DESC").Pluck("ws.id", &ids).Error; err != nil {
		return nil, 0, tong, err
	}
	total := int64(len(ids))
	if total == 0 {
		return []domain.BaoCaoCaDong{}, 0, tong, nil
	}

	// ---- 3 (làm trước). Gộp đơn quầy theo ca ----
	gop, err := r.gopDonTheoCa(ctx, ids)
	if err != nil {
		return nil, 0, tong, err
	}
	for _, g := range gop {
		tong.TotalRevenue += g.TotalRevenue
		tong.OrderCount += g.OrderCount
	}

	// ---- 2. Thông tin các ca của trang đang xem ----
	page, pageSize := f.Page, f.PageSize
	if page < 1 {
		page = 1
	}
	if pageSize < 1 {
		pageSize = 10
	}
	dau := (page - 1) * pageSize
	if dau >= len(ids) {
		return []domain.BaoCaoCaDong{}, total, tong, nil
	}
	trang := ids[dau:min(dau+pageSize, len(ids))]

	var rows []struct {
		ID           uint
		ShopID       uint
		ShopName     string
		OpenedAt     time.Time
		ClosedAt     *time.Time
		OpenedBy     uint
		OpenedByName string
		ClosedBy     *uint
		ClosedByName string
		EmployeeCode string
		EmployeeName string
		OpeningCash  float64
		ExpectedCash *float64
		CountedCash  *float64
		Difference   *float64
		Note         *string
		CloseNote    *string
	}
	err = r.db.WithContext(ctx).Table("work_shifts ws").
		Select(`ws.id, ws.shop_id, s.name AS shop_name,
			ws.opened_at, ws.closed_at, ws.opened_by, ws.closed_by,
			COALESCE(uo.full_name, '') AS opened_by_name,
			COALESCE(uc.full_name, '') AS closed_by_name,
			COALESCE(e.code, '') AS employee_code,
			COALESCE(e.full_name, '') AS employee_name,
			ws.opening_cash, ws.expected_cash, ws.counted_cash, ws.difference,
			ws.note, ws.close_note`).
		Joins("LEFT JOIN shops s ON s.id = ws.shop_id").
		Joins("LEFT JOIN users uo ON uo.id = ws.opened_by").
		Joins("LEFT JOIN users uc ON uc.id = ws.closed_by").
		Joins("LEFT JOIN employees e ON e.user_id = ws.opened_by").
		Where("ws.id IN ?", trang).
		Order("ws.opened_at DESC, ws.id DESC").
		Scan(&rows).Error
	if err != nil {
		return nil, 0, tong, err
	}

	out := make([]domain.BaoCaoCaDong, 0, len(rows))
	for _, x := range rows {
		d := domain.BaoCaoCaDong{
			ID: x.ID, ShopID: x.ShopID, ShopName: x.ShopName,
			OpenedAt: x.OpenedAt, ClosedAt: x.ClosedAt,
			OpenedBy: x.OpenedBy, OpenedByName: x.OpenedByName,
			ClosedBy: x.ClosedBy, ClosedByName: x.ClosedByName,
			EmployeeCode: x.EmployeeCode, EmployeeName: x.EmployeeName,
			OpeningCash:  x.OpeningCash,
			ExpectedCash: x.ExpectedCash, CountedCash: x.CountedCash, Difference: x.Difference,
		}
		if x.Note != nil {
			d.OpenNote = *x.Note
		}
		if x.CloseNote != nil {
			d.CloseNote = *x.CloseNote
		}
		if g, ok := gop[x.ID]; ok {
			d.OrderCount, d.TotalRevenue = g.OrderCount, g.TotalRevenue
			d.TotalCash, d.TotalTransfer, d.TotalAutoQR = g.TotalCash, g.TotalTransfer, g.TotalAutoQR
		}
		out = append(out, d)
	}

	return out, total, tong, nil
}

// gopCa — số đơn quầy và tiền theo hình thức của MỘT ca.
type gopCa struct {
	ShiftID       uint
	OrderCount    int64
	TotalRevenue  float64
	TotalCash     float64
	TotalTransfer float64
	TotalAutoQR   float64
}

// gopDonTheoCa gộp đơn quầy vào từng ca theo KHOẢNG THỜI GIAN của ca.
//
// Ca chưa đóng thì cắt ở "bây giờ" — truyền từ Go chứ không dùng NOW() của
// MySQL: giờ của database và giờ của ứng dụng lệch nhau là đơn vừa bán rơi ra
// ngoài ca đang mở.
//
// Chi nhánh chỉ có MỘT ca mở một lúc (uq_work_shifts_open), nên các khoảng ca
// của cùng chi nhánh không chồng lên nhau và một đơn không bị tính cho hai ca.
func (r *caLamViecRepository) gopDonTheoCa(ctx context.Context, ids []uint) (map[uint]gopCa, error) {
	var rows []gopCa
	err := r.db.WithContext(ctx).Table("orders o").
		Select(`ws.id AS shift_id,
			COUNT(o.id) AS order_count,
			COALESCE(SUM(o.total_amount), 0) AS total_revenue,
			COALESCE(SUM(CASE WHEN o.payment_method = ? THEN o.total_amount ELSE 0 END), 0) AS total_cash,
			COALESCE(SUM(CASE WHEN o.payment_method = ? THEN o.total_amount ELSE 0 END), 0) AS total_transfer,
			COALESCE(SUM(CASE WHEN o.payment_method IN ? THEN o.total_amount ELSE 0 END), 0) AS total_auto_qr`,
			domain.PaymentMethodCash, domain.PaymentMethodBank,
			[]string{domain.PaymentMethodPayOS, domain.PaymentMethodSePay}).
		Joins(`JOIN work_shifts ws ON ws.shop_id = o.shop_id
			AND o.created_at >= ws.opened_at
			AND o.created_at <= COALESCE(ws.closed_at, ?)`, time.Now()).
		Where("ws.id IN ?", ids).
		Where("o.deleted_at IS NULL").
		Where("o.channel = ?", domain.OrderChannelPOS).
		Where("o.payment_status = ?", domain.OrderPaymentPaid).
		Where("o.status NOT IN ?", deadStatuses).
		Group("ws.id").
		Scan(&rows).Error
	if err != nil {
		return nil, err
	}

	out := make(map[uint]gopCa, len(rows))
	for _, g := range rows {
		out[g.ShiftID] = g
	}
	return out, nil
}
