package apitest

import (
	"fmt"
	"net/http"
	"strings"
	"testing"
	"time"
)

// GẮN tiền mặt "ngoài ca" vào ca đang mở (quyền so-quy.sua), ghi ai gắn + lúc nào.

func dongNgoaiCa(t *testing.T, h *heThong, c *cuaHang) uint {
	t.Helper()
	var id uint
	h.db.WithContext(ctxThoat()).Raw("SELECT id FROM cash_entries WHERE tenant_id = ? AND shift_id IS NULL ORDER BY id DESC LIMIT 1", c.id).Scan(&id)
	if id == 0 {
		t.Fatal("không có dòng sổ quỹ ngoài ca nào")
	}
	return id
}

// KB GN01–GN07
func TestNgoaiCa_GanVaoCaDangMo(t *testing.T) {
	h := dungHeThong(t)
	a, b := haiCuaHang(t, h)
	gan := func(tok string, ids ...uint) traLoi {
		return h.goi(t, tok, http.MethodPost, "/api/v1/admin/ca-lam-viec/ngoai-ca/gan", map[string]any{"ids": ids})
	}

	banQuayNgoaiCa(t, h, a, "cash", 2) // 180.000 tiền mặt, không thuộc ca nào
	x := dongNgoaiCa(t, h, a)

	r := h.goi(t, a.token, http.MethodGet, "/api/v1/admin/ca-lam-viec/ngoai-ca", nil)
	if !strings.Contains(r.than, fmt.Sprintf(`"id":%d,`, x)) || !strings.Contains(r.than, `"co_the_gan":true`) {
		t.Fatalf("danh sách ngoài ca phải có dòng %d + meta co_the_gan cho quản trị: %s", x, catBot(r.than))
	}

	if r := gan(a.token, x); r.ma != http.StatusUnprocessableEntity || !strings.Contains(r.than, "chưa có ca đang mở") {
		t.Fatalf("chưa có ca mở thì không gắn được, nhận %d %s", r.ma, catBot(r.than))
	}

	ca := uint(moCa(t, h, a, 0)["id"].(float64))
	r = gan(a.token, x)
	if r.ma != http.StatusOK || !strings.Contains(r.than, `"tong_thu":180000`) {
		t.Fatalf("gắn xong ca phải cộng 180.000 vào tiền thu, nhận %d %s", r.ma, catBot(r.than))
	}
	var dong struct {
		ShiftID    uint
		AttachedBy uint
		AttachedAt *time.Time
	}
	h.db.WithContext(ctxThoat()).Raw("SELECT shift_id, attached_by, attached_at FROM cash_entries WHERE id = ?", x).Scan(&dong)
	if dong.ShiftID != ca || dong.AttachedBy != a.quanTri || dong.AttachedAt == nil {
		t.Fatalf("dòng phải thuộc ca %d, ghi người gắn %d + lúc gắn; nhận %+v", ca, a.quanTri, dong)
	}
	if r := gan(a.token, x); r.ma != http.StatusUnprocessableEntity || !strings.Contains(r.than, "đã được gắn") {
		t.Fatalf("gắn lại dòng đã gắn phải 422, nhận %d %s", r.ma, catBot(r.than))
	}

	// Đóng ca, rồi một dòng ngoài ca phát sinh TRƯỚC giờ đóng ấy: thuộc ca trước, không gắn được.
	h.goi(t, a.token, http.MethodPost, "/api/v1/admin/ca-lam-viec/dong", map[string]any{"counted_cash": 180000})
	banQuayNgoaiCa(t, h, a, "cash", 1)
	y := dongNgoaiCa(t, h, a)
	h.db.WithContext(ctxThoat()).Exec("UPDATE cash_entries SET created_at = DATE_SUB(NOW(3), INTERVAL 1 DAY) WHERE id = ?", y)
	moCa(t, h, a, 0)
	if r := gan(a.token, y); r.ma != http.StatusUnprocessableEntity || !strings.Contains(r.than, "thuộc ca trước") {
		t.Fatalf("khoản trước giờ đóng ca trước phải 422, nhận %d %s", r.ma, catBot(r.than))
	}

	// Thu ngân không có so-quy.sua; cửa hàng khác không gắn được dòng của mình.
	thuNgan := h.dangNhapVoi(t, a.ma, "nhanvien")
	if r := gan(thuNgan, y); r.ma != http.StatusForbidden {
		t.Fatalf("thu ngân gắn ngoài ca phải 403, nhận %d", r.ma)
	}
	if r := h.goi(t, thuNgan, http.MethodGet, "/api/v1/admin/ca-lam-viec/ngoai-ca", nil); !strings.Contains(r.than, `"co_the_gan":false`) {
		t.Fatalf("thu ngân xem ngoài ca phải có co_the_gan=false: %s", catBot(r.than))
	}
	moCa(t, h, b, 0)
	if r := gan(b.token, y); r.ma != http.StatusUnprocessableEntity {
		t.Fatalf("cửa hàng khác gắn dòng không phải của mình phải 422, nhận %d", r.ma)
	}
}
