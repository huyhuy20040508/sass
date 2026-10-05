package service

import (
	"context"
	"fmt"
	"slices"
	"strings"
	"time"

	"sass-api/internal/domain"
)

// Bốn ô "Phương thức thanh toán" của v2 và các hình thức đơn mỗi ô gom vào.
// Quẹt thẻ chưa có hình thức nào ở quầy — lọc riêng ô này là ra rỗng, đúng
// nghĩa "không có đơn quẹt thẻ".
var oHinhThuc = map[string][]string{
	"cash":          {domain.PaymentMethodCash},
	"bank_transfer": {domain.PaymentMethodBank},
	"card":          {"card"},
	"auto_qr":       {domain.PaymentMethodPayOS, domain.PaymentMethodSePay},
}

// hinhThucLoc đổi "cash,auto_qr" thành danh sách payment_method để lọc.
// Rỗng, hoặc tích đủ cả bốn ô, là KHÔNG lọc: tích đủ nghĩa là "tất cả", và đơn
// web thu hộ (cod…) không thuộc ô nào vẫn phải có mặt. Mã lạ thì bỏ qua.
func hinhThucLoc(v string) []string {
	chon := map[string]bool{}
	for _, m := range strings.Split(v, ",") {
		if _, co := oHinhThuc[strings.TrimSpace(m)]; co {
			chon[strings.TrimSpace(m)] = true
		}
	}
	if len(chon) == 0 || len(chon) == len(oHinhThuc) {
		return nil
	}

	out := []string{}
	for o := range chon {
		out = append(out, oHinhThuc[o]...)
	}
	slices.Sort(out)
	return out
}

// Sales — báo cáo doanh thu của một kỳ.
func (s *reportService) Sales(ctx context.Context, q ReportQuery) (domain.SalesReport, error) {
	p, _, _ := s.normalize(q)
	p.Channel = nguonDon(q.Channel)
	p.PaymentMethods = hinhThucLoc(q.Methods)

	out := domain.SalesReport{From: p.FromDate(), To: p.ToDate()}

	days, err := s.repo.SalesDays(ctx, p)
	if err != nil {
		return out, err
	}
	tra, err := s.repo.ReturnsByDay(ctx, p)
	if err != nil {
		return out, err
	}

	// Ghép tiền trả hàng vào đúng ngày. Ngày chỉ có trả hàng mà không bán gì vẫn
	// phải có dòng — không thì tiền hoàn ngày đó biến mất khỏi bảng.
	for i := range days {
		days[i].Returns = tra[days[i].Date]
		delete(tra, days[i].Date)
	}
	for ngay, tien := range tra {
		days = append(days, domain.SalesDay{Date: ngay, Returns: tien})
	}
	slices.SortFunc(days, func(a, b domain.SalesDay) int { return strings.Compare(b.Date, a.Date) })

	for _, d := range days {
		out.Totals.Cong(d)
	}
	out.Days = days

	// Biểu đồ theo ngày: đủ mọi ngày của kỳ, ngày không bán là 0.
	buckets, err := s.repo.Buckets(ctx, p, domain.ReportGroupDay)
	if err != nil {
		return out, err
	}
	out.ByDay = make([]domain.ReportSlice, 0, p.Days())
	for _, nhan := range labels(p, domain.ReportGroupDay) {
		b := buckets[nhan]
		out.ByDay = append(out.ByDay, domain.ReportSlice{Key: nhan, Orders: b.Orders, Revenue: b.Revenue})
	}

	byHour, err := s.repo.ByHour(ctx, p)
	if err != nil {
		return out, err
	}
	out.ByHour = fillKeys(byHour, soKhoa(0, 23))

	byWeekday, err := s.repo.ByWeekday(ctx, p)
	if err != nil {
		return out, err
	}
	out.ByWeekday = fillKeys(byWeekday, soKhoa(1, 7))

	byMonth, err := s.repo.ByMonth(ctx, p)
	if err != nil {
		return out, err
	}
	out.ByMonth = fillKeys(byMonth, soKhoa(1, 12))

	return out, nil
}

// SalesOrders — từng hoá đơn của MỘT ngày (q.Date), cho hộp chi tiết khi bấm
// vào ngày trên bảng. Ngày sai định dạng thì lùi về hôm nay.
func (s *reportService) SalesOrders(ctx context.Context, q ReportQuery) ([]domain.SalesOrderRow, error) {
	loc := time.Now().Location()
	now := time.Now().In(loc)
	ngay := time.Date(now.Year(), now.Month(), now.Day(), 0, 0, 0, 0, loc)
	if t, err := time.ParseInLocation("2006-01-02", strings.TrimSpace(q.Date), loc); err == nil {
		ngay = t
	}

	return s.repo.SalesOrders(ctx, domain.ReportPeriod{
		From: ngay, To: ngay.AddDate(0, 0, 1), ShopID: q.ShopID,
		Channel: nguonDon(q.Channel), PaymentMethods: hinhThucLoc(q.Methods),
	})
}

// soKhoa trả dãy khoá "tu".."den" cho fillKeys.
func soKhoa(tu, den int) []string {
	out := make([]string, 0, den-tu+1)
	for i := tu; i <= den; i++ {
		out = append(out, fmt.Sprint(i))
	}
	return out
}
