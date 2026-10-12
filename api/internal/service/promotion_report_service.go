package service

import (
	"cmp"
	"context"
	"slices"
	"strings"

	"sass-api/internal/domain"
)

// tabKhuyenMai nhận một trong năm tab; lạ thì về tab đầu — trang XEM, tham số
// lạ không đáng một lỗi.
func tabKhuyenMai(v string) string {
	switch v {
	case domain.KMBaoCaoHangHoa, domain.KMBaoCaoNhomHang, domain.KMBaoCaoDongGia, domain.KMBaoCaoVoucher:
		return v
	}
	return domain.KMBaoCaoHoaDon
}

// Promotions — báo cáo chương trình khuyến mại của một tab trong kỳ.
func (s *reportService) Promotions(ctx context.Context, q ReportQuery) (domain.PromotionReport, error) {
	p, _, _ := s.normalize(q)
	f := domain.PromotionReportFilter{Tab: tabKhuyenMai(q.Tab)}
	out := domain.PromotionReport{From: p.FromDate(), To: p.ToDate(), Tab: f.Tab, Rows: []domain.PromotionReportRow{}}

	dung, err := s.repo.PromotionUsage(ctx, p, f)
	if err != nil {
		return out, err
	}
	ds, err := s.repo.PromotionCatalog(ctx, f.Tab)
	if err != nil {
		return out, err
	}
	dauCT := make(map[uint]domain.PromotionCatalogItem, len(ds))
	for _, c := range ds {
		dauCT[c.ID] = c
	}

	daCo := map[uint]bool{}
	for _, r := range dung {
		if c, ok := dauCT[r.ProgramID]; ok {
			dienDauCT(&r, c)
		} else {
			r.Deleted = true
		}
		daCo[r.ProgramID] = true
		out.Rows = append(out.Rows, r)
	}
	// Chương trình đã duyệt / phát hành, chạy trong kỳ, áp cho chi nhánh đang
	// xem mà chưa đơn nào dùng — vẫn bày ra với số 0.
	for _, c := range ds {
		if daCo[c.ID] || !c.Approved || !c.ChayTrongKy(p.From, p.To) || !c.ApChiNhanh(p.ShopID) {
			continue
		}
		r := domain.PromotionReportRow{ProgramID: c.ID}
		dienDauCT(&r, c)
		out.Rows = append(out.Rows, r)
	}

	timKiem := strings.TrimSpace(q.Keyword) != ""
	if timKiem {
		kw := strings.ToLower(strings.TrimSpace(q.Keyword))
		out.Rows = slices.DeleteFunc(out.Rows, func(r domain.PromotionReportRow) bool {
			return !strings.Contains(strings.ToLower(r.Code), kw) && !strings.Contains(strings.ToLower(r.Name), kw)
		})
	}

	// Giảm nhiều trước như v2; bằng nhau thì nhiều đơn trước, rồi theo mã.
	slices.SortStableFunc(out.Rows, func(a, b domain.PromotionReportRow) int {
		if c := cmp.Compare(b.Discount, a.Discount); c != 0 {
			return c
		}
		if c := cmp.Compare(b.OrderCount, a.OrderCount); c != 0 {
			return c
		}
		return cmp.Compare(a.Code, b.Code)
	})

	coDon := []uint{}
	for _, r := range out.Rows {
		out.Totals.Quantity += r.Quantity
		out.Totals.GiftQuantity += r.GiftQuantity
		out.Totals.BaseAmount += r.BaseAmount
		out.Totals.Discount += r.Discount
		out.Totals.CodeCount += r.CodeCount
		out.Totals.CodeUsed += r.CodeUsed
		if r.OrderCount > 0 {
			coDon = append(coDon, r.ProgramID)
		}
	}
	if len(coDon) == 0 {
		return out, nil
	}
	if timKiem {
		f.ProgramIDs = coDon
	}
	tong, err := s.repo.PromotionUsageTotals(ctx, p, f)
	if err != nil {
		return out, err
	}
	out.Totals.OrderCount = tong.OrderCount
	out.Totals.GoodsAmount = tong.GoodsAmount
	out.Totals.Revenue = tong.Revenue

	return out, nil
}

// PromotionOrders — các đơn của một chương trình trong kỳ (hộp chi tiết).
func (s *reportService) PromotionOrders(ctx context.Context, q ReportQuery) ([]domain.PromotionOrderRow, error) {
	p, _, _ := s.normalize(q)
	if q.ProgramID == 0 {
		return []domain.PromotionOrderRow{}, nil
	}
	rows, err := s.repo.PromotionOrders(ctx, p, domain.PromotionReportFilter{Tab: tabKhuyenMai(q.Tab), ProgramID: q.ProgramID})
	if rows == nil {
		rows = []domain.PromotionOrderRow{}
	}

	return rows, err
}

// dienDauCT — mã, tên, loại, trạng thái và thời gian áp dụng HIỆN TẠI của
// chương trình. Voucher không có loại nên giữ 0.
func dienDauCT(r *domain.PromotionReportRow, c domain.PromotionCatalogItem) {
	r.Code, r.Name, r.Type = c.Code, c.Name, c.Type
	r.Status, r.Approved, r.NoTimeLimit = c.Status, c.Approved, c.NoTimeLimit
	r.CodeCount, r.CodeUsed = c.CodeCount, c.CodeUsed
	if c.StartDate != nil {
		v := c.StartDate.Format("2006-01-02")
		r.StartDate = &v
	}
	if c.EndDate != nil {
		v := c.EndDate.Format("2006-01-02")
		r.EndDate = &v
	}
}
