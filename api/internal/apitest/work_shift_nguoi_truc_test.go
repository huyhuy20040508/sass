package apitest

import (
	"context"
	"fmt"
	"net/http"
	"strings"
	"testing"
	"time"

	"sass-api/internal/domain"
	"sass-api/internal/tenant"
)

// HỘP MỞ CA: chọn người trực két (khác người mở máy) và mở bù giờ khi quên.

func gieoNhanSu(t *testing.T, h *heThong, c *cuaHang, ten string, nguoi *uint, shopIDs string, trangThai string) uint {
	t.Helper()
	nv := &domain.NhanVien{
		UserID: nguoi, Code: "NS-" + ten + "-" + c.vet, FullName: ten + " " + c.vet,
		Position: domain.ChucDanhBanHang, Status: trangThai,
	}
	if shopIDs != "" {
		nv.ShopIDs = domain.StringOrNull(shopIDs)
	}
	tao(t, h.db, tenant.WithID(context.Background(), c.id), nv)

	return nv.ID
}

type caMo struct {
	Data struct {
		ID           uint      `json:"id"`
		OpenedBy     uint      `json:"opened_by"`
		EmployeeID   *uint     `json:"employee_id"`
		EmployeeName string    `json:"employee_name"`
		OpenedName   string    `json:"opened_by_name"`
		OpenedAt     time.Time `json:"opened_at"`
	} `json:"data"`
}

func dongCaNgay(t *testing.T, h *heThong, c *cuaHang) {
	t.Helper()
	if r := h.goi(t, c.token, http.MethodPost, "/api/v1/admin/ca-lam-viec/dong", map[string]any{"counted_cash": 0}); r.ma != http.StatusOK {
		t.Fatalf("đóng ca trả %d %s", r.ma, catBot(r.than))
	}
}

// KB NT01–NT04 — danh sách nhân sự trực, mặc định người bấm, chọn người khác, chặn người ngoài chi nhánh.
func TestMoCa_NguoiTrucKet(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	toi := gieoNhanSu(t, h, a, "Toi", &a.quanTri, "", domain.NhanSuDangLam)
	thuNgan := gieoNhanSu(t, h, a, "ThuNgan", nil, fmt.Sprint(a.chiNhanh), domain.NhanSuDangLam)
	khac := gieoNhanSu(t, h, a, "ChiNhanhKhac", nil, fmt.Sprint(a.chiNhanh+100000), domain.NhanSuDangLam)
	nghi := gieoNhanSu(t, h, a, "DaNghi", nil, "", domain.NhanSuDaNghi)

	r := h.goi(t, a.token, http.MethodGet, "/api/v1/admin/ca-lam-viec/nhan-vien-truc", nil)
	for _, id := range []uint{toi, thuNgan} {
		if !strings.Contains(r.than, fmt.Sprintf(`"id":%d,`, id)) {
			t.Fatalf("danh sách trực phải có nhân sự %d, nhận %s", id, catBot(r.than))
		}
	}
	for _, id := range []uint{khac, nghi} {
		if strings.Contains(r.than, fmt.Sprintf(`"id":%d,`, id)) {
			t.Fatalf("không được có nhân sự %d (chi nhánh khác / đã nghỉ): %s", id, catBot(r.than))
		}
	}

	// Không chọn: người trực = hồ sơ của người bấm.
	var ca caMo
	docJSON(t, h.goi(t, a.token, http.MethodPost, "/api/v1/admin/ca-lam-viec/mo", map[string]any{"opening_cash": 0}), &ca)
	if ca.Data.EmployeeID == nil || *ca.Data.EmployeeID != toi || ca.Data.OpenedBy != a.quanTri || ca.Data.EmployeeName != "Toi "+a.vet {
		t.Fatalf("mở ca không chọn người trực phải lấy hồ sơ của người bấm, nhận %+v", ca.Data)
	}
	dongCaNgay(t, h, a)

	// Chọn thu ngân khác: người trực đổi, người mở máy giữ.
	docJSON(t, h.goi(t, a.token, http.MethodPost, "/api/v1/admin/ca-lam-viec/mo", map[string]any{"opening_cash": 0, "employee_id": thuNgan}), &ca)
	if ca.Data.EmployeeID == nil || *ca.Data.EmployeeID != thuNgan || ca.Data.OpenedBy != a.quanTri || ca.Data.OpenedName == "" {
		t.Fatalf("người trực phải là thu ngân được chọn, người mở là người bấm; nhận %+v", ca.Data)
	}
	if g := h.goi(t, a.token, http.MethodGet, fmt.Sprintf("/api/v1/admin/ca-lam-viec/%d", ca.Data.ID), nil); !strings.Contains(g.than, `"employee_name":"ThuNgan `+a.vet+`"`) {
		t.Fatalf("chi tiết ca phải có tên người trực, nhận %s", catBot(g.than))
	}
	dongCaNgay(t, h, a)

	for _, id := range []uint{khac, nghi, 999999} {
		if r := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/ca-lam-viec/mo", map[string]any{"opening_cash": 0, "employee_id": id}); r.ma != http.StatusUnprocessableEntity || !strings.Contains(r.than, `"employee_id"`) {
			t.Fatalf("người trực %d ngoài chi nhánh / đã nghỉ / lạ phải 422 ở ô employee_id, nhận %d %s", id, r.ma, catBot(r.than))
		}
	}

	// Người bấm chưa có hồ sơ nhân sự: vẫn mở được, người trực để trống.
	h.db.WithContext(tenant.WithID(context.Background(), a.id)).Where("id = ?", toi).Delete(&domain.NhanVien{})
	docJSON(t, h.goi(t, a.token, http.MethodPost, "/api/v1/admin/ca-lam-viec/mo", map[string]any{"opening_cash": 0}), &ca)
	if ca.Data.EmployeeID != nil {
		t.Fatalf("người bấm không có hồ sơ thì người trực để trống, nhận %v", *ca.Data.EmployeeID)
	}
}

// KB NT05–NT08 — giờ mở bù: trong 12 giờ, không quá hiện tại, không sớm hơn giờ đóng ca trước.
func TestMoCa_MoBuGio(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	mo := func(gio time.Time) traLoi {
		return h.goi(t, a.token, http.MethodPost, "/api/v1/admin/ca-lam-viec/mo", map[string]any{"opening_cash": 0, "opened_at": gio.Format("2006-01-02 15:04")})
	}
	now := time.Now()

	if r := mo(now.Add(10 * time.Minute)); r.ma != http.StatusUnprocessableEntity || !strings.Contains(r.than, "sau thời điểm hiện tại") {
		t.Fatalf("giờ mở ở tương lai phải 422, nhận %d %s", r.ma, catBot(r.than))
	}
	if r := mo(now.Add(-13 * time.Hour)); r.ma != http.StatusUnprocessableEntity || !strings.Contains(r.than, "12 giờ") {
		t.Fatalf("lùi quá 12 giờ phải 422, nhận %d %s", r.ma, catBot(r.than))
	}
	if r := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/ca-lam-viec/mo", map[string]any{"opening_cash": 0, "opened_at": "hôm qua"}); r.ma != http.StatusUnprocessableEntity {
		t.Fatalf("giờ mở sai dạng phải 422, nhận %d", r.ma)
	}

	var ca caMo
	gio := now.Add(-3 * time.Hour).Truncate(time.Minute)
	docJSON(t, mo(gio), &ca)
	if d := ca.Data.OpenedAt.Sub(gio); d < -time.Second || d > time.Second {
		t.Fatalf("giờ mở phải lưu đúng %s, nhận %s", gio, ca.Data.OpenedAt)
	}
	dongCaNgay(t, h, a) // ca này đóng ngay bây giờ

	if r := mo(now.Add(-2 * time.Hour)); r.ma != http.StatusUnprocessableEntity || !strings.Contains(r.than, "Ca trước đóng lúc") {
		t.Fatalf("giờ mở sớm hơn giờ đóng ca trước phải 422, nhận %d %s", r.ma, catBot(r.than))
	}
	// Không gửi giờ: mở bây giờ như cũ.
	docJSON(t, h.goi(t, a.token, http.MethodPost, "/api/v1/admin/ca-lam-viec/mo", map[string]any{"opening_cash": 0}), &ca)
	if d := time.Since(ca.Data.OpenedAt); d < 0 || d > time.Minute {
		t.Fatalf("không gửi giờ thì mở lúc này, nhận %s", ca.Data.OpenedAt)
	}
}
