package service

import (
	"context"
	"strings"
	"time"

	"sass-api/internal/domain"
)

// BaoCaoCaQuery — tham số thô của báo cáo kết ca, đọc thẳng từ URL.
type BaoCaoCaQuery struct {
	From     string // YYYY-MM-DD
	To       string // YYYY-MM-DD
	ShopID   uint
	UserID   uint
	Keyword  string
	Page     int
	PageSize int
}

// BaoCaoKetCa chuẩn hoá bộ lọc rồi hỏi repository.
//
// Ngày sai định dạng thì lùi về mặc định chứ không báo lỗi — trang XEM, cùng
// cách với nhóm báo cáo (reportService.normalize). Mặc định là THÁNG NÀY, đúng
// mốc mở trang của bản v2. Kỳ dài quá reportMaxDays thì cắt bớt đầu.
func (s *caLamViecService) BaoCaoKetCa(
	ctx context.Context, q BaoCaoCaQuery,
) ([]domain.BaoCaoCaDong, int64, domain.BaoCaoCaTong, error) {
	loc := time.Now().Location()
	now := time.Now().In(loc)
	homNay := time.Date(now.Year(), now.Month(), now.Day(), 0, 0, 0, 0, loc)

	doc := func(v string) (time.Time, bool) {
		t, err := time.ParseInLocation("2006-01-02", strings.TrimSpace(v), loc)
		return t, err == nil
	}

	den, ok := doc(q.To)
	if !ok {
		den = homNay
	}
	tu, ok := doc(q.From)
	if !ok {
		tu = time.Date(den.Year(), den.Month(), 1, 0, 0, 0, 0, loc)
	}
	if tu.After(den) {
		tu, den = den, tu
	}
	// To là mốc MỞ: 00:00 của ngày sau ngày cuối kỳ, để ca mở lúc 23:59 ngày
	// cuối vẫn được tính.
	den = den.AddDate(0, 0, 1)
	if den.Sub(tu) > reportMaxDays*24*time.Hour {
		tu = den.AddDate(0, 0, -reportMaxDays)
	}

	return s.repo.BaoCaoKetCa(ctx, domain.BaoCaoCaFilter{
		From:     tu,
		To:       den,
		ShopID:   q.ShopID,
		UserID:   q.UserID,
		Keyword:  strings.TrimSpace(q.Keyword),
		Page:     q.Page,
		PageSize: q.PageSize,
	})
}
