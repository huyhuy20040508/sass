package service

import (
	"cmp"
	"context"
	"slices"
	"time"

	"sass-api/internal/domain"
)

// Crm — màn CRM → Tổng quan của một kỳ.
func (s *reportService) Crm(ctx context.Context, q ReportQuery) (domain.CrmReport, error) {
	p, _, _ := s.normalize(q)
	f := domain.CrmFilter{}
	if q.CustomerType == domain.CrmKhachCaNhan || q.CustomerType == domain.CrmKhachDoanhNghiep {
		f.CustomerType = q.CustomerType
	}
	now := time.Now()
	cuoiKy := p.To.AddDate(0, 0, -1)

	f.Year = q.Year
	if f.Year < 2000 || f.Year > now.Year() {
		f.Year = cuoiKy.Year()
	}
	f.RegMonth = time.Date(cuoiKy.Year(), cuoiKy.Month(), 1, 0, 0, 0, 0, p.From.Location())
	if t, err := time.ParseInLocation("2006-01", q.RegMonth, p.From.Location()); err == nil && !t.After(now) {
		f.RegMonth = t
	}

	prev := p.Prev()
	out := domain.CrmReport{
		From: p.FromDate(), To: p.ToDate(), PrevFrom: prev.FromDate(), PrevTo: prev.ToDate(),
		CustomerType: f.CustomerType, Year: f.Year, RegMonth: f.RegMonth.Format("2006-01"),
	}

	if err := s.crmKpis(ctx, p, f, &out); err != nil {
		return out, err
	}

	don, err := s.repo.CrmBuyerOrders(ctx, p, f)
	if err != nil {
		return out, err
	}
	khach := gopTheoKhach(don)
	out.Kpis.Buyers = int64(len(khach))
	for _, k := range khach {
		switch k.soDon {
		case 1:
			out.Kpis.Once++
		case 2:
			out.Kpis.Twice++
		}
	}
	out.Kpis.RepeatRate = tiLeQuayLai(khach)
	out.PurchaseCount = soLanMua(khach)
	out.VisitGap = khoangQuayLai(khach)

	ids := make([]uint, 0, len(khach))
	for id := range khach {
		ids = append(ids, id)
	}
	info, err := s.repo.CrmCustomerInfo(ctx, ids)
	if err != nil {
		return out, err
	}
	out.TopSpend = topKhach(khach, info, func(k *khachKy) float64 { return k.tien })
	out.TopDebt = topKhach(khach, info, func(k *khachKy) float64 { return k.no })
	out.Gender, out.Age = chanDung(khach, info, f, cuoiKy)

	if out.OrderValue, err = s.repo.CrmOrderValue(ctx, p, f); err != nil {
		return out, err
	}
	if out.Growth, err = s.crmTangTruong(ctx, f, now); err != nil {
		return out, err
	}
	if out.RegDaily, err = s.crmDangKyNgay(ctx, f, now); err != nil {
		return out, err
	}
	if out.Ranks, err = s.repo.CrmRanks(ctx, p, f); err != nil {
		return out, err
	}
	tinh, err := s.repo.CrmProvinces(ctx, p, f)
	if err != nil {
		return out, err
	}
	out.Provinces = gopTop(tinh, 5)
	if out.Care, err = s.repo.CrmCare(ctx, p, f, now); err != nil {
		return out, err
	}
	if out.YearMin, err = s.repo.CrmFirstCustomerYear(ctx, f); err != nil {
		return out, err
	}
	if out.YearMin == 0 || out.YearMin > now.Year() {
		out.YearMin = now.Year()
	}

	return out, nil
}

// crmKpis — doanh thu (so cùng khoảng tháng trước / năm trước như v2), số đơn,
// TB/đơn, đăng ký mới và khách mua / tỉ lệ quay lại của kỳ liền trước.
func (s *reportService) crmKpis(ctx context.Context, p domain.ReportPeriod, f domain.CrmFilter, out *domain.CrmReport) error {
	k := &out.Kpis
	var err error
	if k.Orders, k.Revenue, err = s.repo.CrmOrderTotals(ctx, p, f); err != nil {
		return err
	}
	if k.Orders > 0 {
		k.AOV = k.Revenue / float64(k.Orders)
	}
	thangTruoc := p
	thangTruoc.From, thangTruoc.To = luiThang(p.From, 1), luiThang(p.To, 1)
	if _, k.RevenueLastMonth, err = s.repo.CrmOrderTotals(ctx, thangTruoc, f); err != nil {
		return err
	}
	namTruoc := p
	namTruoc.From, namTruoc.To = luiThang(p.From, 12), luiThang(p.To, 12)
	if _, k.RevenueLastYear, err = s.repo.CrmOrderTotals(ctx, namTruoc, f); err != nil {
		return err
	}

	prev := p.Prev()
	var tienTruoc float64
	if k.OrdersPrev, tienTruoc, err = s.repo.CrmOrderTotals(ctx, prev, f); err != nil {
		return err
	}
	if k.OrdersPrev > 0 {
		k.AOVPrev = tienTruoc / float64(k.OrdersPrev)
	}
	donTruoc, err := s.repo.CrmBuyerOrders(ctx, prev, f)
	if err != nil {
		return err
	}
	khachTruoc := gopTheoKhach(donTruoc)
	k.BuyersPrev = int64(len(khachTruoc))
	k.RepeatRatePrev = tiLeQuayLai(khachTruoc)

	if k.NewCustomers, err = s.repo.CrmRegistrations(ctx, p.From, p.To, f); err != nil {
		return err
	}
	k.NewCustomersPrev, err = s.repo.CrmRegistrations(ctx, prev.From, prev.To, f)

	return err
}

// crmTangTruong — khách mới từng tháng của năm f.Year và tổng khách cuối tháng.
// Năm nay thì dừng ở tháng hiện tại.
func (s *reportService) crmTangTruong(ctx context.Context, f domain.CrmFilter, now time.Time) ([]domain.CrmGrowthMonth, error) {
	loc := now.Location()
	dauNam := time.Date(f.Year, 1, 1, 0, 0, 0, 0, loc)
	truoc, err := s.repo.CrmRegistrations(ctx, time.Date(1970, 1, 1, 0, 0, 0, 0, loc), dauNam, f)
	if err != nil {
		return nil, err
	}
	theoThang, err := s.repo.CrmRegistrationsBy(ctx, dauNam, dauNam.AddDate(1, 0, 0), f, "%Y-%m")
	if err != nil {
		return nil, err
	}
	soThang := 12
	if f.Year == now.Year() {
		soThang = int(now.Month())
	}
	out := make([]domain.CrmGrowthMonth, 0, soThang)
	tong := truoc
	for m := 1; m <= soThang; m++ {
		ma := time.Date(f.Year, time.Month(m), 1, 0, 0, 0, 0, loc).Format("2006-01")
		tong += theoThang[ma]
		out = append(out, domain.CrmGrowthMonth{Month: ma, New: theoThang[ma], Total: tong})
	}

	return out, nil
}

// crmDangKyNgay — hồ sơ mới từng ngày của tháng f.RegMonth (đủ ngày, tháng này
// thì tới hôm nay).
func (s *reportService) crmDangKyNgay(ctx context.Context, f domain.CrmFilter, now time.Time) ([]domain.CrmBucket, error) {
	dau := f.RegMonth
	cuoi := dau.AddDate(0, 1, 0)
	theoNgay, err := s.repo.CrmRegistrationsBy(ctx, dau, cuoi, f, "%Y-%m-%d")
	if err != nil {
		return nil, err
	}
	out := []domain.CrmBucket{}
	for d := dau; d.Before(cuoi) && !d.After(now); d = d.AddDate(0, 0, 1) {
		ma := d.Format("2006-01-02")
		out = append(out, domain.CrmBucket{Key: ma, Label: d.Format("02/01"), Count: theoNgay[ma]})
	}

	return out, nil
}

// luiThang lùi n tháng, KHÔNG tràn sang tháng sau (31/03 lùi một tháng là
// 28/02 chứ không phải 03/03) — subMonthNoOverflow của v2.
func luiThang(t time.Time, n int) time.Time {
	dau := time.Date(t.Year(), t.Month(), 1, t.Hour(), t.Minute(), t.Second(), t.Nanosecond(), t.Location()).AddDate(0, -n, 0)
	cuoiThang := dau.AddDate(0, 1, -1).Day()

	return dau.AddDate(0, 0, min(t.Day(), cuoiThang)-1)
}

type khachKy struct {
	id    uint
	soDon int
	lan1  time.Time
	lan2  time.Time
	tien  float64
	no    float64
}

// gopTheoKhach — đơn (cũ trước) gộp theo khách.
func gopTheoKhach(don []domain.CrmBuyerOrder) map[uint]*khachKy {
	out := map[uint]*khachKy{}
	for _, d := range don {
		k := out[d.UserID]
		if k == nil {
			k = &khachKy{id: d.UserID, lan1: d.CreatedAt}
			out[d.UserID] = k
		} else if k.soDon == 1 {
			k.lan2 = d.CreatedAt
		}
		k.soDon++
		k.tien += d.Total
		k.no += d.Unpaid
	}

	return out
}

func tiLeQuayLai(khach map[uint]*khachKy) float64 {
	if len(khach) == 0 {
		return 0
	}
	n := 0
	for _, k := range khach {
		if k.soDon >= 2 {
			n++
		}
	}

	return float64(n) * 100 / float64(len(khach))
}

func soLanMua(khach map[uint]*khachKy) []domain.CrmBucket {
	out := []domain.CrmBucket{
		{Key: "1", Label: "Mua 1 lần"}, {Key: "2", Label: "Mua 2 lần"},
		{Key: "3", Label: "Mua 3 lần"}, {Key: "4+", Label: "Từ 4 lần"},
	}
	for _, k := range khach {
		out[min(k.soDon, 4)-1].Count++
	}

	return out
}

// khoangQuayLai — số ngày từ lần mua đầu tới lần thứ hai trong kỳ (mốc của v2).
func khoangQuayLai(khach map[uint]*khachKy) []domain.CrmBucket {
	out := []domain.CrmBucket{
		{Key: "1w", Label: "Trong 1 tuần"}, {Key: "2w", Label: "1 – 2 tuần"},
		{Key: "1m", Label: "2 tuần – 1 tháng"}, {Key: "2m", Label: "1 – 2 tháng"},
		{Key: "none", Label: "Chưa quay lại"},
	}
	for _, k := range khach {
		i := 4
		if k.soDon >= 2 {
			switch ngay := int(k.lan2.Sub(k.lan1).Hours() / 24); {
			case ngay <= 7:
				i = 0
			case ngay <= 14:
				i = 1
			case ngay <= 30:
				i = 2
			case ngay <= 60:
				i = 3
			}
		}
		out[i].Count++
	}

	return out
}

func topKhach(khach map[uint]*khachKy, info map[uint]domain.CrmCustomerInfo, giaTri func(*khachKy) float64) []domain.CrmTopCustomer {
	ds := make([]*khachKy, 0, len(khach))
	for _, k := range khach {
		if giaTri(k) > 0 {
			ds = append(ds, k)
		}
	}
	slices.SortFunc(ds, func(a, b *khachKy) int {
		if c := cmp.Compare(giaTri(b), giaTri(a)); c != 0 {
			return c
		}
		return cmp.Compare(a.id, b.id)
	})
	out := []domain.CrmTopCustomer{}
	for _, k := range ds[:min(10, len(ds))] {
		i := info[k.id]
		i.ID = k.id
		out = append(out, domain.CrmTopCustomer{CrmCustomerInfo: i, Value: giaTri(k), Orders: int64(k.soDon)})
	}

	return out
}

// chanDung — giới tính của khách mua trong kỳ; độ tuổi (tại cuối kỳ) của khách
// CÁ NHÂN có ngày sinh — mốc của v2: <20, 20–<25, 25–35, >35.
func chanDung(khach map[uint]*khachKy, info map[uint]domain.CrmCustomerInfo, f domain.CrmFilter, cuoiKy time.Time) ([]domain.CrmBucket, []domain.CrmBucket) {
	gioi := []domain.CrmBucket{{Key: "female", Label: "Nữ"}, {Key: "male", Label: "Nam"}, {Key: "other", Label: "Chưa rõ"}}
	tuoi := []domain.CrmBucket{{Key: "<20", Label: "Dưới 20"}, {Key: "20-25", Label: "20 – 25"}, {Key: "25-35", Label: "25 – 35"}, {Key: ">35", Label: "Trên 35"}}
	for id := range khach {
		i := info[id]
		switch i.Gender {
		case "female":
			gioi[0].Count++
		case "male":
			gioi[1].Count++
		default:
			gioi[2].Count++
		}
		if f.CustomerType == domain.CrmKhachDoanhNghiep || i.CustomerType == 1 || i.DateOfBirth == nil {
			continue
		}
		t := cuoiKy.Year() - i.DateOfBirth.Year()
		if cuoiKy.Month() < i.DateOfBirth.Month() || (cuoiKy.Month() == i.DateOfBirth.Month() && cuoiKy.Day() < i.DateOfBirth.Day()) {
			t--
		}
		switch {
		case t < 20:
			tuoi[0].Count++
		case t < 25:
			tuoi[1].Count++
		case t <= 35:
			tuoi[2].Count++
		default:
			tuoi[3].Count++
		}
	}

	return gioi, tuoi
}

// gopTop — giữ n mục đầu, phần còn lại gộp thành "Khác".
func gopTop(ds []domain.CrmBucket, n int) []domain.CrmBucket {
	if len(ds) <= n {
		return ds
	}
	khac := domain.CrmBucket{Key: "other", Label: "Khác"}
	for _, d := range ds[n:] {
		khac.Count += d.Count
	}

	return append(ds[:n:n], khac)
}
