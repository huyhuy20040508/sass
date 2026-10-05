package service

import (
	"cmp"
	"context"
	"slices"
	"strings"

	"sass-api/internal/domain"
)

// Staff — báo cáo ca của một kỳ.
func (s *reportService) Staff(ctx context.Context, q ReportQuery) (domain.StaffReport, error) {
	p, _, _ := s.normalize(q)
	p.Channel = nguonDon(q.Channel)

	out := domain.StaffReport{From: p.FromDate(), To: p.ToDate()}

	// Nhóm lạ coi như không lọc — trang XEM, tham số gõ sai không đáng một lỗi.
	f := domain.StaffReportFilter{UserID: q.StaffID, Keyword: strings.TrimSpace(q.Keyword)}
	if domain.CuaVaoHopLe[q.Area] {
		f.Area = q.Area
	}

	rows, err := s.repo.StaffShiftRows(ctx, p, f)
	if err != nil {
		return out, err
	}

	theoNguoi := map[uint]*domain.StaffRevenue{}
	for i := range rows {
		rows[i].TinhTB()
		out.Totals.OrderCount += rows[i].OrderCount
		out.Totals.Revenue += rows[i].Revenue

		n, co := theoNguoi[rows[i].UserID]
		if !co {
			n = &domain.StaffRevenue{UserID: rows[i].UserID, Name: rows[i].Name}
			theoNguoi[rows[i].UserID] = n
		}
		n.OrderCount += rows[i].OrderCount
		n.Revenue += rows[i].Revenue
	}
	out.Totals.TinhTB()
	out.Rows = rows

	out.ByStaff = make([]domain.StaffRevenue, 0, len(theoNguoi))
	for _, n := range theoNguoi {
		if n.OrderCount > 0 {
			n.AvgOrder = n.Revenue / float64(n.OrderCount)
		}
		out.ByStaff = append(out.ByStaff, *n)
	}
	slices.SortFunc(out.ByStaff, func(a, b domain.StaffRevenue) int {
		if c := cmp.Compare(b.Revenue, a.Revenue); c != 0 {
			return c
		}
		return cmp.Compare(a.UserID, b.UserID)
	})

	return out, nil
}
