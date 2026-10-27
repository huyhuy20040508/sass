package domain

import (
	"testing"
	"time"
)

// TestReportPeriodPrev — KB #86: kỳ mùng 1 gọn trong một tháng so cùng kỳ tháng
// trước; các kỳ khác so cùng độ dài liền trước.
func TestReportPeriodPrev(t *testing.T) {
	ngay := func(s string) time.Time {
		v, _ := time.ParseInLocation("2006-01-02", s, time.Local)
		return v
	}
	for _, c := range []struct{ ten, tu, den, prevTu, prevDen string }{
		{"tháng này đến 19/10", "2026-10-01", "2026-10-19", "2026-09-01", "2026-09-19"},
		{"trọn tháng 9 → trọn tháng 8", "2026-09-01", "2026-09-30", "2026-08-01", "2026-08-31"},
		{"trọn tháng 3 → trọn tháng 2", "2026-03-01", "2026-03-31", "2026-02-01", "2026-02-28"},
		{"31 ngày tháng 3 kẹp ở cuối tháng 2", "2026-03-01", "2026-03-30", "2026-02-01", "2026-02-28"},
		{"tháng 1 → tháng 12 năm trước", "2026-01-01", "2026-01-15", "2025-12-01", "2025-12-15"},
		{"hôm nay là mùng 1 → hôm qua", "2026-10-01", "2026-10-01", "2026-09-30", "2026-09-30"},
		{"7 ngày bắt đầu mùng 1 → 7 ngày liền trước", "2026-10-01", "2026-10-07", "2026-09-24", "2026-09-30"},
		{"kỳ không bắt đầu mùng 1", "2026-10-05", "2026-10-19", "2026-09-20", "2026-10-04"},
		{"kỳ vắt hai tháng", "2026-09-01", "2026-10-19", "2026-07-14", "2026-08-31"},
	} {
		p := ReportPeriod{From: ngay(c.tu), To: ngay(c.den).AddDate(0, 0, 1)}.Prev()
		if p.FromDate() != c.prevTu || p.ToDate() != c.prevDen {
			t.Errorf("%s: phải là %s → %s, đang là %s → %s", c.ten, c.prevTu, c.prevDen, p.FromDate(), p.ToDate())
		}
	}
}
