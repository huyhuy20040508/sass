package apitest

import (
	"net/http"
	"strings"
	"testing"
)

// KB NL01 — ô "Nhắc khi ca mở quá (giờ)": mặc định 24, nhóm Quầy bán hàng, 0 = tắt, trần 720.
func TestCaiDat_NhacCaMoQuaLau(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	if r := h.goi(t, a.token, http.MethodGet, "/api/v1/admin/settings?group=pos", nil); !strings.Contains(r.than, `"shift_open_alert_hours":"24"`) {
		t.Fatalf("nhóm pos phải có shift_open_alert_hours mặc định 24: %s", catBot(r.than))
	}
	luu := func(v string) traLoi {
		return h.goi(t, a.token, http.MethodPut, "/api/v1/admin/settings", map[string]any{"items": map[string]any{"shift_open_alert_hours": v}})
	}
	if r := luu("721"); r.ma != http.StatusUnprocessableEntity {
		t.Fatalf("quá 720 giờ phải 422, nhận %d %s", r.ma, catBot(r.than))
	}
	if r := luu("0"); r.ma != http.StatusOK {
		t.Fatalf("0 (tắt nhắc) phải lưu được, nhận %d %s", r.ma, catBot(r.than))
	}
}
