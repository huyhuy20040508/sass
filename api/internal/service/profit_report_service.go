package service

import (
	"context"

	"sass-api/internal/domain"
)

// Profit — báo cáo chi phí & lợi nhuận của một kỳ.
func (s *reportService) Profit(ctx context.Context, q ReportQuery) (domain.ProfitReport, error) {
	p, groupBy, _ := s.normalize(q)
	p.Channel = nguonDon(q.Channel)

	out := domain.ProfitReport{From: p.FromDate(), To: p.ToDate(), GroupBy: groupBy}

	f, err := s.goodsFilter(ctx, q)
	if err != nil {
		return out, err
	}

	// Bảng: món đã bán (nhiều tiền trước) rồi tới món ế của kỳ.
	ban, err := s.repo.ProfitRows(ctx, p, f)
	if err != nil {
		return out, err
	}
	e, err := s.repo.ProfitUnsold(ctx, p, f)
	if err != nil {
		return out, err
	}
	out.Rows = append(ban, e...)
	for i := range out.Rows {
		out.Rows[i].TinhBien()
		out.Totals.Cong(out.Rows[i])
	}
	out.Totals.TinhBien()

	// Biểu đồ không theo ô tìm, như v2 — ô tìm chỉ để dò một dòng trong bảng.
	f.Keyword = ""
	moc, err := s.repo.ProfitBuckets(ctx, p, f, groupBy)
	if err != nil {
		return out, err
	}
	nhan := labels(p, groupBy)
	out.Chart = make([]domain.ProfitBucket, 0, len(nhan))
	for _, k := range nhan {
		b := moc[k]
		b.Key = k
		out.Chart = append(out.Chart, b)
	}

	return out, nil
}
