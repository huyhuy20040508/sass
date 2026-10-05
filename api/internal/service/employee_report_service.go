package service

import (
	"cmp"
	"context"
	"slices"
	"strings"

	"sass-api/internal/domain"
)

// Employees — báo cáo nhân viên (hoa hồng) của một kỳ.
func (s *reportService) Employees(ctx context.Context, q ReportQuery) (domain.EmployeeReport, error) {
	p, _, _ := s.normalize(q)
	p.Channel = nguonDon(q.Channel)

	out := domain.EmployeeReport{From: p.FromDate(), To: p.ToDate()}

	// Cùng ô lọc nhân viên với báo cáo ca; nhóm lạ coi như không lọc.
	f := domain.StaffReportFilter{UserID: q.StaffID, Keyword: strings.TrimSpace(q.Keyword)}
	if domain.CuaVaoHopLe[q.Area] {
		f.Area = q.Area
	}

	don, err := s.repo.EmployeeOrders(ctx, p, f)
	if err != nil {
		return out, err
	}

	// Gộp theo người, giữ thứ tự đơn mới trước như repo trả.
	theoNguoi := map[uint]int{}
	for _, o := range don {
		o.TinhHoaHong(o.Rate)
		k, co := theoNguoi[o.UserID]
		if !co {
			k = len(out.Rows)
			theoNguoi[o.UserID] = k
			out.Rows = append(out.Rows, domain.EmployeeRow{
				UserID: o.UserID, EmployeeCode: o.EmployeeCode, Name: o.Name, CommissionRate: o.Rate,
			})
		}
		out.Rows[k].Cong(o)
		out.Rows[k].Orders = append(out.Rows[k].Orders, o)
		out.Totals.Cong(o)
	}
	if out.Rows == nil {
		out.Rows = []domain.EmployeeRow{}
	}

	// Nhiều hoa hồng trước như v2; bằng nhau thì nhiều doanh thu trước.
	slices.SortStableFunc(out.Rows, func(a, b domain.EmployeeRow) int {
		if c := cmp.Compare(b.Commission, a.Commission); c != 0 {
			return c
		}
		return cmp.Compare(b.RevenueVAT, a.RevenueVAT)
	})

	return out, nil
}
