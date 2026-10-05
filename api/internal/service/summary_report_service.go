package service

import (
	"context"
	"fmt"
	"strings"
	"time"

	"sass-api/internal/domain"
)

// Summary — báo cáo tổng hợp của một ngày. Ngày sai định dạng thì lùi về hôm
// nay, nguồn đơn lạ thì coi như "mọi nguồn" — trang XEM, cùng cách với normalize().
func (s *reportService) Summary(ctx context.Context, q ReportQuery) (domain.SummaryReport, error) {
	loc := time.Now().Location()
	now := time.Now().In(loc)
	ngay := time.Date(now.Year(), now.Month(), now.Day(), 0, 0, 0, 0, loc)
	if t, err := time.ParseInLocation("2006-01-02", strings.TrimSpace(q.Date), loc); err == nil {
		ngay = t
	}

	p := domain.ReportPeriod{From: ngay, To: ngay.AddDate(0, 0, 1), ShopID: q.ShopID, Channel: nguonDon(q.Channel)}
	out := domain.SummaryReport{Date: p.FromDate()}

	var err error
	if out.Totals, err = s.repo.Totals(ctx, p); err != nil {
		return out, err
	}
	if out.ItemKinds, err = s.repo.ItemKinds(ctx, p); err != nil {
		return out, err
	}
	if out.ByPaymentMethod, err = s.repo.ByPaymentMethod(ctx, p); err != nil {
		return out, err
	}

	byHour, err := s.repo.ByHour(ctx, p)
	if err != nil {
		return out, err
	}
	gio := make([]string, 24)
	for h := range gio {
		gio[h] = fmt.Sprint(h)
	}
	out.ByHour = fillKeys(byHour, gio)

	if out.Cashbook, err = s.repo.CashbookTotals(ctx, p); err != nil {
		return out, err
	}
	if out.Returns, err = s.repo.ReturnTotals(ctx, p); err != nil {
		return out, err
	}

	return out, nil
}
