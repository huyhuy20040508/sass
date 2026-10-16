package apitest

import (
	"fmt"
	"net/http"
	"testing"
	"time"
)

// TestCaLamViec_KyTinhTheoGioDongCa — KB #73: ca bỏ quên mở từ tháng trước, hôm
// nay mới đóng phải nằm trong kỳ "Hôm nay" của lịch sử ca và báo cáo kết ca (kỳ
// tính theo giờ ĐÓNG); ca còn mở thuộc mọi kỳ từ lúc mở.
func TestCaLamViec_KyTinhTheoGioDongCa(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	caCu := uint(moCa(t, h, a, 0)["id"].(float64))
	if res := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/ca-lam-viec/dong",
		map[string]any{"counted_cash": 0}); res.ma != http.StatusOK {
		t.Fatalf("đóng ca trả %d\n%s", res.ma, catBot(res.than))
	}
	caMo := uint(moCa(t, h, a, 0)["id"].(float64))
	// Lùi giờ mở của cả hai ca về 40 ngày trước — cảnh ca #1 mở 17/08, đóng 13/10.
	if err := h.db.WithContext(ctxThoat()).Exec(
		"UPDATE work_shifts SET opened_at = DATE_SUB(opened_at, INTERVAL 40 DAY) WHERE id IN (?, ?)", caCu, caMo,
	).Error; err != nil {
		t.Fatalf("không lùi được giờ mở ca: %v", err)
	}
	homNay := time.Now().Format("2006-01-02")
	ngayMo := time.Now().AddDate(0, 0, -40).Format("2006-01-02")

	dsCa := func(query string) []uint {
		t.Helper()
		var ds struct {
			Data []struct{ ID uint }
		}
		docJSON(t, h.goi(t, a.token, http.MethodGet, "/api/v1/admin/ca-lam-viec?page_size=50"+query, nil), &ds)
		ids := []uint{}
		for _, c := range ds.Data {
			ids = append(ids, c.ID)
		}
		return ids
	}
	baoCao := func(tu, den string) []uint {
		t.Helper()
		ids := []uint{}
		for _, c := range docBaoCaoCa(t, h, a, fmt.Sprintf("?from=%s&to=%s", tu, den)).Data {
			ids = append(ids, c.ID)
		}
		return ids
	}
	giong := func(ten string, co, muon []uint) {
		t.Helper()
		if fmt.Sprint(co) != fmt.Sprint(muon) {
			t.Errorf("%s: phải là %v, đang là %v", ten, muon, co)
		}
	}

	homNayQ := fmt.Sprintf("&from_date=%s&to_date=%s", homNay, homNay)
	ngayMoQ := fmt.Sprintf("&from_date=%s&to_date=%s", ngayMo, ngayMo)
	giong("lịch sử ca đã đóng — Hôm nay", dsCa("&status=da_dong"+homNayQ), []uint{caCu})
	giong("lịch sử ca đã đóng — ngày mở (ca đóng hôm nay không thuộc ngày đó)", dsCa("&status=da_dong"+ngayMoQ), []uint{})
	// Ca đang mở đứng đầu, rồi tới ca đóng gần nhất.
	giong("mọi ca — Hôm nay", dsCa(homNayQ), []uint{caMo, caCu})
	giong("mọi ca — ngày mở", dsCa(ngayMoQ), []uint{caMo})

	giong("báo cáo kết ca — Hôm nay", baoCao(homNay, homNay), []uint{caMo, caCu})
	giong("báo cáo kết ca — ngày mở", baoCao(ngayMo, ngayMo), []uint{caMo})
}
