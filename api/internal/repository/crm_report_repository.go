package repository

import (
	"context"
	"time"

	"gorm.io/gorm"

	"sass-api/internal/domain"
)

// ---------- Báo cáo CRM → Tổng quan ----------

// crmOrders — đơn còn hiệu lực trong kỳ; lọc loại khách thì chỉ còn đơn có hồ
// sơ đúng loại (users nối theo khoá chính, không nhân dòng).
func (r *reportRepository) crmOrders(ctx context.Context, p domain.ReportPeriod, f domain.CrmFilter) *gorm.DB {
	q := r.orders(ctx, p)
	if loai := f.LoaiKhach(); loai >= 0 {
		q = q.Joins("JOIN users cu ON cu.id = o.user_id").Where("cu.customer_type = ?", loai)
	}

	return q
}

// khachHang — hồ sơ khách còn sống, lọc loại nếu có. Bí danh `u`.
func (r *reportRepository) khachHang(ctx context.Context, f domain.CrmFilter) *gorm.DB {
	q := r.db.WithContext(ctx).Table("users u").
		Where("u.deleted_at IS NULL").
		Where("u.role_id = ?", domain.CustomerRoleID)
	if loai := f.LoaiKhach(); loai >= 0 {
		q = q.Where("u.customer_type = ?", loai)
	}

	return q
}

func (r *reportRepository) CrmOrderTotals(ctx context.Context, p domain.ReportPeriod, f domain.CrmFilter) (int64, float64, error) {
	var out struct {
		Orders  int64
		Revenue float64
	}
	err := r.crmOrders(ctx, p, f).
		Select("COUNT(*) AS orders, COALESCE(SUM(o.total_amount), 0) AS revenue").
		Scan(&out).Error

	return out.Orders, out.Revenue, err
}

// CrmBuyerOrders — từng đơn CÓ hồ sơ khách trong kỳ, cũ trước. "Còn nợ" tính
// như sổ khách (user_repository): tổng đơn trừ phần đã thu (daThuCuaDon).
func (r *reportRepository) CrmBuyerOrders(ctx context.Context, p domain.ReportPeriod, f domain.CrmFilter) ([]domain.CrmBuyerOrder, error) {
	var rows []domain.CrmBuyerOrder
	err := r.crmOrders(ctx, p, f).
		Where("o.user_id IS NOT NULL").
		Select(`o.user_id, o.created_at, o.total_amount AS total,
			o.total_amount - (` + daThuCuaDon("o") + `) AS unpaid`).
		Order("o.created_at, o.id").
		Scan(&rows).Error

	return rows, err
}

// CrmOrderValue — số đơn theo giá trị (bốn khoảng của v2).
func (r *reportRepository) CrmOrderValue(ctx context.Context, p domain.ReportPeriod, f domain.CrmFilter) ([]domain.CrmBucket, error) {
	var d struct{ A, B, C, D int64 }
	err := r.crmOrders(ctx, p, f).
		Select(`COALESCE(SUM(o.total_amount < 500000), 0) AS a,
			COALESCE(SUM(o.total_amount >= 500000 AND o.total_amount < 1000000), 0) AS b,
			COALESCE(SUM(o.total_amount >= 1000000 AND o.total_amount < 3000000), 0) AS c,
			COALESCE(SUM(o.total_amount >= 3000000), 0) AS d`).
		Scan(&d).Error

	return []domain.CrmBucket{
		{Key: "duoi_500k", Label: "Dưới 500k", Count: d.A},
		{Key: "500k_1tr", Label: "500k – 1tr", Count: d.B},
		{Key: "1tr_3tr", Label: "1tr – 3tr", Count: d.C},
		{Key: "tren_3tr", Label: "Trên 3tr", Count: d.D},
	}, err
}

func (r *reportRepository) CrmCustomerInfo(ctx context.Context, ids []uint) (map[uint]domain.CrmCustomerInfo, error) {
	out := map[uint]domain.CrmCustomerInfo{}
	if len(ids) == 0 {
		return out, nil
	}
	var rows []struct {
		ID           uint
		Name         string
		Code         string
		CustomerType int
		RankName     string
		Deleted      bool
		Gender       string
		DateOfBirth  *time.Time
	}
	err := r.db.WithContext(ctx).Table("users u").
		Joins("LEFT JOIN membership_ranks mr ON mr.id = u.rank_id").
		Where("u.id IN ?", ids).
		Select(`u.id, COALESCE(u.full_name, '') AS name, COALESCE(u.customer_code, '') AS code,
			COALESCE(u.customer_type, 0) AS customer_type, COALESCE(mr.name, '') AS rank_name,
			COALESCE(u.gender, '') AS gender, u.date_of_birth, u.deleted_at IS NOT NULL AS deleted`).
		Scan(&rows).Error
	for _, row := range rows {
		out[row.ID] = domain.CrmCustomerInfo{ID: row.ID, Name: row.Name, Code: row.Code, CustomerType: row.CustomerType,
			RankName: row.RankName, Deleted: row.Deleted, Gender: row.Gender, DateOfBirth: row.DateOfBirth}
	}

	return out, err
}

func (r *reportRepository) CrmRegistrations(ctx context.Context, from, to time.Time, f domain.CrmFilter) (int64, error) {
	var n int64
	err := r.khachHang(ctx, f).Where("u.created_at >= ? AND u.created_at < ?", from, to).Count(&n).Error

	return n, err
}

// CrmRegistrationsBy — hồ sơ mới theo mốc DATE_FORMAT (`%Y-%m` / `%Y-%m-%d`).
// Chỉ có mốc phát sinh; service bù mốc trống.
func (r *reportRepository) CrmRegistrationsBy(ctx context.Context, from, to time.Time, f domain.CrmFilter, format string) (map[string]int64, error) {
	var rows []struct {
		K string
		N int64
	}
	expr := "DATE_FORMAT(u.created_at, '" + format + "')"
	err := r.khachHang(ctx, f).
		Where("u.created_at >= ? AND u.created_at < ?", from, to).
		Select(expr + " AS k, COUNT(*) AS n").
		Group(expr).
		Scan(&rows).Error
	out := make(map[string]int64, len(rows))
	for _, row := range rows {
		out[row.K] = row.N
	}

	return out, err
}

func (r *reportRepository) CrmFirstCustomerYear(ctx context.Context, f domain.CrmFilter) (int, error) {
	var y *int
	err := r.khachHang(ctx, f).Select("MIN(YEAR(u.created_at))").Scan(&y).Error
	if y == nil {
		return 0, err
	}

	return *y, err
}

// CrmRanks — mỗi hạng (cả hạng tắt, sắp theo điểm) + dòng "chưa có hạng"
// (RankID 0) ở đầu: số hồ sơ tạo trước kỳ / trong kỳ, tiền đơn trong kỳ.
func (r *reportRepository) CrmRanks(ctx context.Context, p domain.ReportPeriod, f domain.CrmFilter) ([]domain.CrmRankRow, error) {
	var hang []domain.MembershipRank
	if err := r.db.WithContext(ctx).Order("point, id").Find(&hang).Error; err != nil {
		return nil, err
	}

	var dem []struct {
		RankID   uint
		Existing int64
		New      int64
	}
	err := r.khachHang(ctx, f).
		Where("u.created_at < ?", p.To).
		Select(`COALESCE(u.rank_id, 0) AS rank_id,
			COALESCE(SUM(u.created_at < ?), 0) AS existing,
			COALESCE(SUM(u.created_at >= ?), 0) AS new`, p.From, p.From).
		Group("COALESCE(u.rank_id, 0)").
		Scan(&dem).Error
	if err != nil {
		return nil, err
	}

	var tien []struct {
		RankID uint
		Spend  float64
	}
	err = r.crmOrders(ctx, p, f).
		Joins("JOIN users ru ON ru.id = o.user_id").
		Select("COALESCE(ru.rank_id, 0) AS rank_id, COALESCE(SUM(o.total_amount), 0) AS spend").
		Group("COALESCE(ru.rank_id, 0)").
		Scan(&tien).Error
	if err != nil {
		return nil, err
	}

	out := []domain.CrmRankRow{{RankID: 0, Name: "Chưa có hạng"}}
	viTri := map[uint]int{0: 0}
	for _, h := range hang {
		viTri[h.ID] = len(out)
		out = append(out, domain.CrmRankRow{RankID: h.ID, Name: h.Name})
	}
	for _, d := range dem {
		if k, co := viTri[d.RankID]; co {
			out[k].Existing, out[k].New = d.Existing, d.New
		}
	}
	for _, t := range tien {
		if k, co := viTri[t.RankID]; co {
			out[k].Spend = t.Spend
		}
	}

	return out, nil
}

// CrmProvinces — khách theo tỉnh / thành của đơn giao hàng (web), đếm mỗi khách
// một lần (hồ sơ, không có hồ sơ thì theo số điện thoại người nhận).
func (r *reportRepository) CrmProvinces(ctx context.Context, p domain.ReportPeriod, f domain.CrmFilter) ([]domain.CrmBucket, error) {
	var rows []struct {
		K string
		N int64
	}
	err := r.crmOrders(ctx, p, f).
		Where("o.channel = ?", domain.OrderChannelWeb).
		Where("COALESCE(o.shipping_province, '') <> ''").
		Select("o.shipping_province AS k, COUNT(DISTINCT COALESCE(CONCAT('u', o.user_id), CONCAT('p', o.recipient_phone))) AS n").
		Group("o.shipping_province").
		Order("n DESC, k").
		Scan(&rows).Error
	out := make([]domain.CrmBucket, 0, len(rows))
	for _, row := range rows {
		out = append(out, domain.CrmBucket{Key: row.K, Label: row.K, Count: row.N})
	}

	return out, err
}

// CrmCare — bốn nhóm khách cần chăm sóc. Tính trên hồ sơ, không theo kỳ đang
// xem: "sinh nhật 7 ngày tới" hay "đang nợ" là chuyện của hôm nay.
func (r *reportRepository) CrmCare(ctx context.Context, p domain.ReportPeriod, f domain.CrmFilter, now time.Time) (domain.CrmCare, error) {
	var out domain.CrmCare

	ngay := make([]string, 0, 7)
	for i := 0; i < 7; i++ {
		ngay = append(ngay, now.AddDate(0, 0, i).Format("01-02"))
	}
	if err := r.khachHang(ctx, f).
		Where("u.date_of_birth IS NOT NULL").
		Where("DATE_FORMAT(u.date_of_birth, '%m-%d') IN ?", ngay).
		Count(&out.Birthdays7d).Error; err != nil {
		return out, err
	}

	// Đơn gần nhất (còn hiệu lực, mọi chi nhánh) quá 60 ngày.
	moc := now.AddDate(0, 0, -60)
	if err := r.khachHang(ctx, f).
		Where(`(SELECT MAX(lo.created_at) FROM orders lo
			WHERE lo.user_id = u.id AND lo.deleted_at IS NULL AND lo.status NOT IN ?) < ?`, deadStatuses, moc).
		Count(&out.Lapsed60d).Error; err != nil {
		return out, err
	}

	no := r.db.WithContext(ctx).Table("orders o").
		Where("o.deleted_at IS NULL").
		Where("o.status NOT IN ?", deadStatuses).
		Where("o.user_id IS NOT NULL").
		Where("o.total_amount > (" + daThuCuaDon("o") + ")")
	if p.ShopID > 0 {
		no = no.Where("o.shop_id = ?", p.ShopID)
	}
	if loai := f.LoaiKhach(); loai >= 0 {
		no = no.Joins("JOIN users cu ON cu.id = o.user_id").Where("cu.customer_type = ?", loai)
	}
	var tienNo struct {
		N   int64
		Sum float64
	}
	if err := no.Select("COUNT(DISTINCT o.user_id) AS n, COALESCE(SUM(o.total_amount - (" + daThuCuaDon("o") + ")), 0) AS sum").Scan(&tienNo).Error; err != nil {
		return out, err
	}
	out.Debtors, out.DebtAmount = tienNo.N, tienNo.Sum

	// Sắp lên hạng: điểm tích luỹ đạt 90% mà chưa tới ngưỡng của một hạng
	// đang bật (cùng luật xếp hạng XepHangLai: total_points >= point).
	var hang []domain.MembershipRank
	if err := r.db.WithContext(ctx).Where("status = 1 AND point > 0").Find(&hang).Error; err != nil {
		return out, err
	}
	if len(hang) > 0 {
		q := r.khachHang(ctx, f)
		dk := r.db.Session(&gorm.Session{NewDB: true})
		for i, h := range hang {
			moc := float64(h.Point) * 0.9
			if i == 0 {
				dk = dk.Where("u.total_points >= ? AND u.total_points < ?", moc, h.Point)
			} else {
				dk = dk.Or("u.total_points >= ? AND u.total_points < ?", moc, h.Point)
			}
		}
		if err := q.Where(dk).Count(&out.NearRank).Error; err != nil {
			return out, err
		}
	}

	return out, nil
}
