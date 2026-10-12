package service

import (
	"testing"
	"time"

	"sass-api/internal/domain"
)

func TestLuiThang_KhongTranSangThangSau(t *testing.T) {
	cases := []struct {
		vao, ra string
		n       int
	}{
		{"2026-03-31", "2026-02-28", 1},
		{"2024-03-31", "2024-02-29", 1},
		{"2026-10-11", "2026-09-11", 1},
		{"2026-01-15", "2025-01-15", 12},
		{"2024-02-29", "2023-02-28", 12},
	}
	for _, c := range cases {
		vao, _ := time.Parse("2006-01-02", c.vao)
		if got := luiThang(vao, c.n).Format("2006-01-02"); got != c.ra {
			t.Errorf("luiThang(%s, %d) = %s, muốn %s", c.vao, c.n, got, c.ra)
		}
	}
}

func TestGopTheoKhach_SoLanVaKhoangQuayLai(t *testing.T) {
	ngay := func(d int) time.Time { return time.Date(2026, 10, d, 9, 0, 0, 0, time.UTC) }
	khach := gopTheoKhach([]domain.CrmBuyerOrder{
		{UserID: 1, CreatedAt: ngay(1), Total: 100},
		{UserID: 1, CreatedAt: ngay(5), Total: 200, Unpaid: 200},
		{UserID: 1, CreatedAt: ngay(20), Total: 50},
		{UserID: 2, CreatedAt: ngay(2), Total: 70},
		{UserID: 3, CreatedAt: ngay(1), Total: 10},
		{UserID: 3, CreatedAt: ngay(25), Total: 10},
	})
	if k := khach[1]; k.soDon != 3 || k.tien != 350 || k.no != 200 {
		t.Fatalf("khách 1: 3 đơn, 350, nợ 200 — đang là %+v", *k)
	}
	if r := tiLeQuayLai(khach); r < 66.6 || r > 66.7 {
		t.Fatalf("2/3 khách quay lại, đang là %v", r)
	}
	gap := khoangQuayLai(khach)
	// khách 1: lần 2 sau 4 ngày → 1w; khách 3: sau 24 ngày → 1m; khách 2: chưa quay lại.
	if gap[0].Count != 1 || gap[2].Count != 1 || gap[4].Count != 1 {
		t.Fatalf("khoảng quay lại sai: %+v", gap)
	}
	if lan := soLanMua(khach); lan[0].Count != 1 || lan[1].Count != 1 || lan[2].Count != 1 {
		t.Fatalf("số lần mua sai: %+v", lan)
	}
}
