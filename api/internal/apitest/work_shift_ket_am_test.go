package apitest

import (
	"net/http"
	"strings"
	"testing"
)

// TestCaLamViec_ChiVuotKetPhaiXacNhan — ghi chi lớn hơn tiền két theo sổ bị chặn
// 422 kèm mã KET_AM và số két còn; chỉ ghi khi gửi lại `xac_nhan_am`. Chi trong
// tầm két thì ghi bình thường.
func TestCaLamViec_ChiVuotKetPhaiXacNhan(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	moCa(t, h, a, 500000)

	ghi := func(chieu string, so int, xacNhan bool) traLoi {
		t.Helper()
		return h.goi(t, a.token, http.MethodPost, "/api/v1/admin/so-quy",
			map[string]any{"direction": chieu, "amount": so, "reason": "kiểm két âm", "xac_nhan_am": xacNhan})
	}

	if r := ghi("in", 100000, false); r.ma != http.StatusCreated {
		t.Fatalf("thu tay trả %d %s", r.ma, catBot(r.than))
	}
	r := ghi("out", 900000, false)
	if r.ma != http.StatusUnprocessableEntity || !strings.Contains(r.than, `"ma":"KET_AM"`) || !strings.Contains(r.than, "600.000") {
		t.Fatalf("chi 900.000 khi két còn 600.000 phải 422 KET_AM kèm số còn, nhận %d %s", r.ma, catBot(r.than))
	}
	if r := ghi("out", 900000, true); r.ma != http.StatusCreated {
		t.Fatalf("đã xác nhận thì phải ghi được, nhận %d %s", r.ma, catBot(r.than))
	}
	// Két đang âm (−300.000): chi tiếp vẫn phải hỏi lại.
	if r := ghi("out", 50000, false); r.ma != http.StatusUnprocessableEntity || !strings.Contains(r.than, "−300.000") {
		t.Fatalf("két đã âm mà chi tiếp phải hỏi lại, nhận %d %s", r.ma, catBot(r.than))
	}
	// Chi trong tầm két: thu lại cho dương rồi chi nhỏ hơn.
	ghi("in", 1000000, false)
	if r := ghi("out", 200000, false); r.ma != http.StatusCreated {
		t.Fatalf("chi trong tầm két (còn 700.000) phải ghi bình thường, nhận %d %s", r.ma, catBot(r.than))
	}
}
