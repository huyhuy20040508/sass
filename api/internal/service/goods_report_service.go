package service

import (
	"cmp"
	"context"
	"slices"
	"strings"

	"sass-api/internal/domain"
)

// Hai ô "Top" của biểu đồ hàng hoá chỉ có 5 / 10 / 15 như v2; số lạ lùi về 5.
func soTop(n int) int {
	if n == 10 || n == 15 {
		return n
	}
	return 5
}

// goodsFilter nở nhóm được chọn thành nhóm + mọi nhóm con/cháu, như màn Hàng hoá.
func (s *reportService) goodsFilter(ctx context.Context, q ReportQuery) (domain.GoodsFilter, error) {
	f := domain.GoodsFilter{ProductID: q.ProductID, Keyword: strings.TrimSpace(q.Keyword)}
	if q.CategoryID == 0 {
		return f, nil
	}

	cats, err := s.repo.CategoryTree(ctx)
	if err != nil {
		return f, err
	}
	f.CategoryIDs = descendantCategoryIDs(q.CategoryID, cats)
	return f, nil
}

// Goods — báo cáo hàng hoá của một kỳ.
func (s *reportService) Goods(ctx context.Context, q ReportQuery) (domain.GoodsReport, error) {
	p, _, _ := s.normalize(q)
	p.Channel = nguonDon(q.Channel)

	out := domain.GoodsReport{From: p.FromDate(), To: p.ToDate()}

	f, err := s.goodsFilter(ctx, q)
	if err != nil {
		return out, err
	}
	// Biểu đồ không theo ô tìm — lấy bảng đủ trước, có từ khoá mới đọc lại bảng.
	tim := f.Keyword
	f.Keyword = ""

	all, err := s.repo.GoodsRows(ctx, p, f)
	if err != nil {
		return out, err
	}
	out.Rows = all
	if tim != "" {
		loc := f
		loc.Keyword = tim
		if out.Rows, err = s.repo.GoodsRows(ctx, p, loc); err != nil {
			return out, err
		}
	}
	for _, r := range out.Rows {
		out.Totals.Cong(r)
	}

	// Top bán chạy xếp theo SỐ LƯỢNG — cùng cách hiểu với bảng và biểu đồ theo
	// thứ; bằng số lượng thì món nhiều tiền đứng trước. asc = N món bán ít nhất.
	top := slices.Clone(all)
	slices.SortStableFunc(top, func(a, b domain.GoodsRow) int {
		c := cmp.Or(cmp.Compare(b.Quantity, a.Quantity), cmp.Compare(b.Total, a.Total))
		if q.Sort == "asc" {
			return -c
		}
		return c
	})
	out.Top = top[:min(soTop(q.Limit), len(top))]

	byHour, err := s.repo.GoodsUnitsBy(ctx, p, f, "HOUR(o.created_at)")
	if err != nil {
		return out, err
	}
	out.ByHour = fillKeys(byHour, soKhoa(0, 23))

	byMonth, err := s.repo.GoodsUnitsBy(ctx, p, f, "MONTH(o.created_at)")
	if err != nil {
		return out, err
	}
	out.ByMonth = fillKeys(byMonth, soKhoa(1, 12))

	// Theo thứ: N món bán NHIỀU nhất theo số lượng (all đã xếp sẵn như vậy).
	// Món đã xoá hẳn không có id để lọc nên đứng ngoài.
	var ids []uint
	ten := map[uint]string{}
	for _, r := range all {
		if len(ids) == soTop(q.TopWeekday) {
			break
		}
		if r.ProductID > 0 {
			ids = append(ids, r.ProductID)
			ten[r.ProductID] = r.Name
		}
	}
	theoThu, err := s.repo.GoodsWeekday(ctx, p, f, ids)
	if err != nil {
		return out, err
	}
	out.ByWeekday = make([]domain.GoodsSeries, 0, len(ids))
	for _, id := range ids {
		out.ByWeekday = append(out.ByWeekday, domain.GoodsSeries{ProductID: id, Name: ten[id], Data: theoThu[id]})
	}

	return out, nil
}

// GoodsOrders — các hoá đơn có bán mặt hàng q.ProductID trong kỳ (hộp chi tiết).
func (s *reportService) GoodsOrders(ctx context.Context, q ReportQuery) ([]domain.GoodsOrderRow, error) {
	if q.ProductID == 0 {
		return []domain.GoodsOrderRow{}, nil
	}
	p, _, _ := s.normalize(q)
	p.Channel = nguonDon(q.Channel)

	return s.repo.GoodsOrders(ctx, p, q.ProductID)
}
