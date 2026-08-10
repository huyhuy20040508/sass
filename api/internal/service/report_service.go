package service

import (
	"context"
	"fmt"
	"strings"
	"time"

	"sass-api/internal/domain"
)

// ReportService — nghiệp vụ của nhóm trang Báo cáo.
//
// Tầng này KHÔNG tự cộng số: mọi phép gộp nằm dưới repository (SQL). Việc của nó
// là ba thứ mà SQL làm vụng: chuẩn hoá tham số (khoảng ngày, cách chia trục, số
// dòng), BÙ MỐC TRỐNG cho chuỗi thời gian, và điền những trường luôn phải có mặt
// dù kỳ không phát sinh gì (đủ 8 trạng thái đơn, đủ 24 giờ, đủ 7 thứ).
//
// Bù mốc trống là bắt buộc chứ không phải làm đẹp: thiếu mốc thì biểu đồ nối
// thẳng qua chỗ trống và vẽ ra một đường xu hướng không có thật.
type ReportService interface {
	Revenue(ctx context.Context, q ReportQuery) (domain.RevenueReport, error)
	Orders(ctx context.Context, q ReportQuery) (domain.OrderReport, error)
	Products(ctx context.Context, q ReportQuery) (domain.ProductReport, error)
	Customers(ctx context.Context, q ReportQuery) (domain.CustomerReport, error)
}

// ReportQuery — tham số thô lấy từ query string, chưa kiểm tra gì.
// Chuẩn hoá nằm ở normalize() để bốn báo cáo dùng chung đúng một bộ quy tắc.
type ReportQuery struct {
	From    string // YYYY-MM-DD, rỗng = 30 ngày gần nhất
	To      string // YYYY-MM-DD, rỗng = hôm nay
	GroupBy string // day | week | month
	Sort    string // chỉ báo cáo sản phẩm dùng
	Limit   int
}

// Ràng buộc khoảng xem. Trên 731 ngày (2 năm) thì biểu đồ theo ngày có hơn 700
// mốc — vẽ ra là một vệt đặc, và câu gộp cũng bắt đầu nặng.
const (
	reportMaxDays     = 731
	reportDefaultDays = 30
	reportMaxLimit    = 100
	reportProvinceTop = 12
	reportSliceTop    = 12
)

type reportService struct {
	repo domain.ReportRepository
}

func NewReportService(repo domain.ReportRepository) ReportService {
	return &reportService{repo: repo}
}

// normalize quy tham số thô về một kỳ hợp lệ + cách chia trục hợp lệ.
//
// Ngày không đọc được thì lùi về mặc định thay vì báo lỗi: báo cáo là trang xem,
// một tham số gõ sai không đáng để người dùng nhận màn hình trắng.
func (s *reportService) normalize(q ReportQuery) (domain.ReportPeriod, string, int) {
	loc := time.Now().Location()
	now := time.Now().In(loc)
	today := time.Date(now.Year(), now.Month(), now.Day(), 0, 0, 0, 0, loc)

	parse := func(v string) (time.Time, bool) {
		t, err := time.ParseInLocation("2006-01-02", strings.TrimSpace(v), loc)
		if err != nil {
			return time.Time{}, false
		}
		return t, true
	}

	to, okTo := parse(q.To)
	if !okTo {
		to = today
	}
	from, okFrom := parse(q.From)
	if !okFrom {
		from = to.AddDate(0, 0, -(reportDefaultDays - 1))
	}
	if from.After(to) {
		from, to = to, from
	}
	// To của ReportPeriod là mốc MỞ: 00:00 của ngày sau ngày cuối kỳ, để đơn đặt
	// lúc 23:59 ngày cuối vẫn được tính.
	p := domain.ReportPeriod{From: from, To: to.AddDate(0, 0, 1)}
	if p.Days() > reportMaxDays {
		p.From = p.To.AddDate(0, 0, -reportMaxDays)
	}

	groupBy := strings.TrimSpace(q.GroupBy)
	switch groupBy {
	case domain.ReportGroupWeek, domain.ReportGroupMonth, domain.ReportGroupDay:
	default:
		// Không khai thì tự chọn theo độ dài kỳ: xem cả năm mà chia theo ngày là
		// 365 cột dính nhau, xem một tuần mà chia theo tháng là đúng một cột.
		switch {
		case p.Days() > 180:
			groupBy = domain.ReportGroupMonth
		case p.Days() > 62:
			groupBy = domain.ReportGroupWeek
		default:
			groupBy = domain.ReportGroupDay
		}
	}

	limit := q.Limit
	if limit <= 0 {
		limit = 20
	}
	if limit > reportMaxLimit {
		limit = reportMaxLimit
	}

	return p, groupBy, limit
}

// labels liệt kê ĐỦ nhãn mốc của kỳ theo đúng thứ tự thời gian.
//
// Nhãn phải trùng từng ký tự với nhãn mà MySQL sinh ra trong câu gộp, nếu không
// mốc có dữ liệu sẽ không khớp mốc nào và cả biểu đồ về 0. Tuần dùng ISOWeek()
// của Go để khớp với DATE_FORMAT(..., '%x-W%v').
func labels(p domain.ReportPeriod, groupBy string) []string {
	out := []string{}
	seen := map[string]bool{}
	add := func(label string) {
		if !seen[label] {
			seen[label] = true
			out = append(out, label)
		}
	}

	for d := p.From; d.Before(p.To); d = d.AddDate(0, 0, 1) {
		switch groupBy {
		case domain.ReportGroupWeek:
			y, w := d.ISOWeek()
			add(fmt.Sprintf("%04d-W%02d", y, w))
		case domain.ReportGroupMonth:
			add(d.Format("2006-01"))
		default:
			add(d.Format("2006-01-02"))
		}
	}
	return out
}

// fillBuckets ghép chuỗi mốc đầy đủ với dữ liệu thật, mốc trống về 0.
func fillBuckets(p domain.ReportPeriod, groupBy string, data map[string]domain.ReportBucket) []domain.ReportBucket {
	all := labels(p, groupBy)
	out := make([]domain.ReportBucket, 0, len(all))
	for _, label := range all {
		if b, ok := data[label]; ok {
			out = append(out, b)
			continue
		}
		out = append(out, domain.ReportBucket{Label: label})
	}
	return out
}

// sliceMap đánh chỉ mục lát cắt theo khoá để tra nhanh khi điền mốc còn thiếu.
func sliceMap(list []domain.ReportSlice) map[string]domain.ReportSlice {
	out := make(map[string]domain.ReportSlice, len(list))
	for _, s := range list {
		out[s.Key] = s
	}
	return out
}

// fillKeys trả về lát cắt theo ĐÚNG danh sách khoá cho trước, khoá không có dữ
// liệu vẫn xuất hiện với giá trị 0.
//
// Dùng cho những chiều mà tập khoá là cố định và đã biết trước (trạng thái đơn,
// 24 giờ, 7 thứ trong tuần): bảng mà nhảy dòng theo từng kỳ xem thì không so
// sánh được hai lần xem với nhau.
func fillKeys(list []domain.ReportSlice, keys []string) []domain.ReportSlice {
	have := sliceMap(list)
	out := make([]domain.ReportSlice, 0, len(keys))
	for _, k := range keys {
		if s, ok := have[k]; ok {
			out = append(out, s)
			continue
		}
		out = append(out, domain.ReportSlice{Key: k})
	}
	return out
}

// ---------- 1. Doanh thu ----------

func (s *reportService) Revenue(ctx context.Context, q ReportQuery) (domain.RevenueReport, error) {
	p, groupBy, _ := s.normalize(q)
	prev := p.Prev()

	out := domain.RevenueReport{
		From: p.FromDate(), To: p.ToDate(),
		PrevFrom: prev.FromDate(), PrevTo: prev.ToDate(),
		GroupBy: groupBy,
	}

	data, err := s.repo.Buckets(ctx, p, groupBy)
	if err != nil {
		return out, err
	}
	out.Buckets = fillBuckets(p, groupBy, data)

	if out.Totals, err = s.repo.Totals(ctx, p); err != nil {
		return out, err
	}
	if out.Prev, err = s.repo.Totals(ctx, prev); err != nil {
		return out, err
	}
	if out.ByPaymentMethod, err = s.repo.ByPaymentMethod(ctx, p); err != nil {
		return out, err
	}
	if out.ByPaymentStatus, err = s.repo.ByPaymentStatus(ctx, p); err != nil {
		return out, err
	}
	return out, nil
}

// ---------- 2. Đơn hàng ----------

// orderStatusKeys — thứ tự trạng thái theo LUỒNG XỬ LÝ, không theo bảng chữ cái
// và không theo số lượng: bảng trạng thái đọc được là bảng đi đúng đường đơn chạy.
var orderStatusKeys = []string{
	domain.OrderStatusPending,
	domain.OrderStatusConfirmed,
	domain.OrderStatusProcessing,
	domain.OrderStatusShipping,
	domain.OrderStatusDelivered,
	domain.OrderStatusCompleted,
	domain.OrderStatusCancelled,
	domain.OrderStatusReturned,
}

func (s *reportService) Orders(ctx context.Context, q ReportQuery) (domain.OrderReport, error) {
	p, groupBy, _ := s.normalize(q)
	prev := p.Prev()

	out := domain.OrderReport{
		From: p.FromDate(), To: p.ToDate(),
		PrevFrom: prev.FromDate(), PrevTo: prev.ToDate(),
		GroupBy: groupBy,
	}

	data, err := s.repo.Buckets(ctx, p, groupBy)
	if err != nil {
		return out, err
	}
	out.Buckets = fillBuckets(p, groupBy, data)

	if out.Totals, err = s.repo.OrderCounts(ctx, p); err != nil {
		return out, err
	}
	if out.Prev, err = s.repo.OrderCounts(ctx, prev); err != nil {
		return out, err
	}

	byStatus, err := s.repo.ByStatus(ctx, p)
	if err != nil {
		return out, err
	}
	out.ByStatus = fillKeys(byStatus, orderStatusKeys)

	byHour, err := s.repo.ByHour(ctx, p)
	if err != nil {
		return out, err
	}
	hourKeys := make([]string, 24)
	for h := 0; h < 24; h++ {
		hourKeys[h] = fmt.Sprint(h)
	}
	out.ByHour = fillKeys(byHour, hourKeys)

	byWeekday, err := s.repo.ByWeekday(ctx, p)
	if err != nil {
		return out, err
	}
	weekdayKeys := make([]string, 7)
	for d := 1; d <= 7; d++ {
		weekdayKeys[d-1] = fmt.Sprint(d)
	}
	out.ByWeekday = fillKeys(byWeekday, weekdayKeys)

	if out.ByProvince, err = s.repo.ByProvince(ctx, p, reportProvinceTop); err != nil {
		return out, err
	}
	// Hai kênh bán luôn có mặt: kênh không phát sinh đơn nào trong kỳ cũng là một
	// thông tin, ẩn đi thì người xem tưởng báo cáo chỉ theo dõi kênh còn lại.
	byChannel, err := s.repo.ByChannel(ctx, p)
	if err != nil {
		return out, err
	}
	out.ByChannel = fillKeys(byChannel, []string{"member", "guest"})

	if out.ByShipping, err = s.repo.ByShipping(ctx, p); err != nil {
		return out, err
	}
	return out, nil
}

// ---------- 3. Sản phẩm ----------

func (s *reportService) Products(ctx context.Context, q ReportQuery) (domain.ProductReport, error) {
	p, _, limit := s.normalize(q)
	prev := p.Prev()

	sort := strings.TrimSpace(q.Sort)
	switch sort {
	case domain.ProductSortUnits, domain.ProductSortProfit, domain.ProductSortRevenue:
	default:
		sort = domain.ProductSortRevenue
	}

	out := domain.ProductReport{
		From: p.FromDate(), To: p.ToDate(),
		PrevFrom: prev.FromDate(), PrevTo: prev.ToDate(),
		Sort: sort,
	}

	var err error
	if out.Totals, err = s.repo.ProductTotals(ctx, p); err != nil {
		return out, err
	}
	if out.Prev, err = s.repo.ProductTotals(ctx, prev); err != nil {
		return out, err
	}
	if out.Items, err = s.repo.ProductRows(ctx, p, sort, limit); err != nil {
		return out, err
	}
	if out.ByCategory, err = s.repo.ByCategory(ctx, p, reportSliceTop); err != nil {
		return out, err
	}
	if out.ByBrand, err = s.repo.ByBrand(ctx, p, reportSliceTop); err != nil {
		return out, err
	}
	if out.BySize, err = s.repo.BySize(ctx, p, reportSliceTop); err != nil {
		return out, err
	}
	if out.UnsoldProducts, err = s.repo.UnsoldProducts(ctx, p); err != nil {
		return out, err
	}
	return out, nil
}

// ---------- 4. Khách hàng ----------

func (s *reportService) Customers(ctx context.Context, q ReportQuery) (domain.CustomerReport, error) {
	p, groupBy, limit := s.normalize(q)
	prev := p.Prev()

	out := domain.CustomerReport{
		From: p.FromDate(), To: p.ToDate(),
		PrevFrom: prev.FromDate(), PrevTo: prev.ToDate(),
		GroupBy: groupBy,
	}

	var err error
	if out.Totals, err = s.repo.CustomerTotals(ctx, p); err != nil {
		return out, err
	}
	if out.Prev, err = s.repo.CustomerTotals(ctx, prev); err != nil {
		return out, err
	}
	if out.Top, err = s.repo.TopCustomers(ctx, p, limit); err != nil {
		return out, err
	}
	if out.ByProvince, err = s.repo.ByProvince(ctx, p, reportProvinceTop); err != nil {
		return out, err
	}

	data, err := s.repo.CustomerBuckets(ctx, p, groupBy)
	if err != nil {
		return out, err
	}
	all := labels(p, groupBy)
	out.Buckets = make([]domain.CustomerBucket, 0, len(all))
	for _, label := range all {
		if b, ok := data[label]; ok {
			out.Buckets = append(out.Buckets, b)
			continue
		}
		out.Buckets = append(out.Buckets, domain.CustomerBucket{Label: label})
	}
	return out, nil
}
