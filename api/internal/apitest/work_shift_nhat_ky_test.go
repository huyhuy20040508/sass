package apitest

import (
	"fmt"
	"net/http"
	"testing"
	"time"

	"sass-api/internal/domain"
)

// NHẬT KÝ NHÂN VIÊN: mở / đóng ca ghi một dòng cho người trực két; tab Nhật ký
// đếm số ca trực, số lần lệch.

type nhatKyTrang struct {
	Data struct {
		Tong struct {
			SoCa      int64   `json:"so_ca"`
			SoLanLech int64   `json:"so_lan_lech"`
			TongThieu float64 `json:"tong_thieu"`
			TongDu    float64 `json:"tong_du"`
		} `json:"tong"`
		Items []struct {
			Event      string    `json:"event"`
			ShiftID    uint      `json:"shift_id"`
			Amount     *float64  `json:"amount"`
			Difference *float64  `json:"difference"`
			Note       string    `json:"note"`
			HappenedAt time.Time `json:"happened_at"`
			ShopName   string    `json:"shop_name"`
			UserName   string    `json:"user_name"`
		} `json:"items"`
		Total int64 `json:"total"`
	} `json:"data"`
}

func docNhatKy(t *testing.T, h *heThong, tok string, nv uint) (nhatKyTrang, traLoi) {
	t.Helper()
	var out nhatKyTrang
	r := h.goi(t, tok, http.MethodGet, fmt.Sprintf("/api/v1/admin/nhan-su/%d/nhat-ky", nv), nil)
	if r.ma == http.StatusOK {
		docJSON(t, r, &out)
	}
	return out, r
}

// KB NK01–NK05
func TestNhatKyNhanVien_MoDongCa(t *testing.T) {
	h := dungHeThong(t)
	a, b := haiCuaHang(t, h)
	toi := gieoNhanSu(t, h, a, "Toi", &a.quanTri, "", domain.NhanSuDangLam)
	thuNgan := gieoNhanSu(t, h, a, "ThuNgan", nil, "", domain.NhanSuDangLam)

	// Ca 1: quản trị mở hộ thu ngân, lùi giờ 2 tiếng, đầu ca 300.000; đóng đếm 250.000 → thiếu 50.000.
	gioMo := time.Now().Add(-2 * time.Hour).Truncate(time.Minute)
	var ca1 caMo
	docJSON(t, h.goi(t, a.token, http.MethodPost, "/api/v1/admin/ca-lam-viec/mo", map[string]any{
		"opening_cash": 300000, "employee_id": thuNgan, "opened_at": gioMo.Format("2006-01-02 15:04"), "note": "nhận két ca sáng",
	}), &ca1)
	h.goi(t, a.token, http.MethodPost, "/api/v1/admin/ca-lam-viec/dong", map[string]any{"counted_cash": 250000, "note": "thối nhầm"})

	// Ca 2: không chọn người trực → hồ sơ của người bấm; đóng khớp két.
	var ca2 caMo
	docJSON(t, h.goi(t, a.token, http.MethodPost, "/api/v1/admin/ca-lam-viec/mo", map[string]any{"opening_cash": 0}), &ca2)
	h.goi(t, a.token, http.MethodPost, "/api/v1/admin/ca-lam-viec/dong", map[string]any{"counted_cash": 0})

	nk, r := docNhatKy(t, h, a.token, thuNgan)
	if r.ma != http.StatusOK || nk.Data.Total != 2 || len(nk.Data.Items) != 2 {
		t.Fatalf("thu ngân phải có 2 dòng nhật ký (mở + đóng ca 1), nhận %d %s", r.ma, catBot(r.than))
	}
	dong, mo := nk.Data.Items[0], nk.Data.Items[1]
	if dong.Event != "shift_close" || dong.ShiftID != ca1.Data.ID || dong.Amount == nil || *dong.Amount != 250000 ||
		dong.Difference == nil || *dong.Difference != -50000 || dong.Note != "thối nhầm" {
		t.Fatalf("dòng đóng ca sai: %+v", dong)
	}
	if mo.Event != "shift_open" || mo.Amount == nil || *mo.Amount != 300000 || mo.Difference != nil || mo.Note != "nhận két ca sáng" {
		t.Fatalf("dòng mở ca sai: %+v", mo)
	}
	if d := mo.HappenedAt.Sub(gioMo); d < -time.Second || d > time.Second {
		t.Fatalf("giờ mở trong nhật ký phải là giờ mở bù %s, nhận %s", gioMo, mo.HappenedAt)
	}
	if mo.ShopName == "" || mo.UserName != "Quản trị "+a.vet {
		t.Fatalf("dòng nhật ký phải có tên chi nhánh + người bấm (quản trị mở hộ), nhận %q %q", mo.ShopName, mo.UserName)
	}
	if tg := nk.Data.Tong; tg.SoCa != 1 || tg.SoLanLech != 1 || tg.TongThieu != -50000 || tg.TongDu != 0 {
		t.Fatalf("tổng của thu ngân: 1 ca, lệch 1 lần, thiếu 50.000; nhận %+v", tg)
	}

	nk, _ = docNhatKy(t, h, a.token, toi)
	if tg := nk.Data.Tong; nk.Data.Total != 2 || tg.SoCa != 1 || tg.SoLanLech != 0 {
		t.Fatalf("người bấm (ca 2, khớp két): 2 dòng, 1 ca, 0 lần lệch; nhận total=%d %+v", nk.Data.Total, tg)
	}

	// Thu ngân không có quyền xem nhân sự; cửa hàng khác không thấy gì.
	if _, r := docNhatKy(t, h, h.dangNhapVoi(t, a.ma, "nhanvien"), thuNgan); r.ma != http.StatusForbidden {
		t.Fatalf("thu ngân xem nhật ký nhân sự phải 403, nhận %d", r.ma)
	}
	if nk, r := docNhatKy(t, h, b.token, thuNgan); r.ma != http.StatusOK || nk.Data.Total != 0 {
		t.Fatalf("cửa hàng khác không được thấy nhật ký, nhận %d total=%d", r.ma, nk.Data.Total)
	}
}
