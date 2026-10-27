package apitest

import (
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"strings"
	"testing"

	"sass-api/internal/domain"
	"sass-api/internal/tenant"
)

// Mẫu phiếu nhanh ở quầy (Cài đặt → Quầy bán hàng → pos_cashbook_templates).

type mauTra struct {
	Ten      string  `json:"ten"`
	Loai     uint8   `json:"loai"`
	PhanLoai *uint   `json:"phan_loai"`
	MoTa     string  `json:"mo_ta"`
	SoTien   float64 `json:"so_tien"`
}

func docMauPhieu(t *testing.T, h *heThong, token string) (string, []mauTra) {
	t.Helper()
	r := h.goi(t, token, http.MethodGet, duongTCQuay+"/danh-muc", nil)
	var body struct {
		Data struct {
			Mau []mauTra `json:"mau_phieu"`
		} `json:"data"`
	}
	if r.ma != http.StatusOK || json.Unmarshal([]byte(r.than), &body) != nil {
		t.Fatalf("danh mục quầy phải 200, nhận %d %s", r.ma, catBot(r.than))
	}
	return r.than, body.Data.Mau
}

// KB MP01–MP05
func TestThuChiQuay_MauPhieuNhanh(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	thuNgan := h.dangNhapVoi(t, a.ma, "nhanvien")
	h.db.WithContext(ctxThoat()).Exec("DELETE FROM income_expense_types WHERE tenant_id = ?", a.id)
	tao := func(loai uint8, ten string) uint {
		l := domain.LoaiThuChi{Type: loai, Name: ten}
		if err := h.db.WithContext(tenant.WithID(context.Background(), a.id)).Create(&l).Error; err != nil {
			t.Fatal(err)
		}
		return l.ID
	}
	ship, luong, thuKhac := tao(domain.LoaiChi, "Vận chuyển, giao hàng"), tao(domain.LoaiChi, "Lương, thưởng"), tao(domain.LoaiThu, "Các khoản thu khác")
	id := func(p *uint) uint {
		if p == nil {
			return 0
		}
		return *p
	}

	// MP01 mặc định: 5 mẫu, phân loại dò theo gợi ý; không khớp thì để trống.
	_, ds := docMauPhieu(t, h, thuNgan)
	if len(ds) != 5 || ds[0].Ten != "Trả ship" || id(ds[0].PhanLoai) != ship || ds[0].SoTien != 30000 || ds[0].Loai != domain.LoaiChi {
		t.Fatalf("MP01 mẫu đầu phải Trả ship · chi · Vận chuyển · 30000, nhận %+v", ds)
	}
	if ds[1].PhanLoai != nil || id(ds[3].PhanLoai) != luong || ds[4].Ten != "Nộp tiền về chủ" || ds[4].SoTien != 0 {
		t.Fatalf("MP01 nước đá không khớp loại nào; tạm ứng → lương; nộp về chủ không gợi ý tiền; nhận %+v", ds)
	}

	dat := func(v string) traLoi {
		return h.goi(t, a.token, http.MethodPut, "/api/v1/admin/settings", map[string]any{"items": map[string]string{"pos_cashbook_templates": v}})
	}
	// MP02 chủ khai riêng: giữ id đúng vế, id sai vế / không tồn tại thì bỏ trống.
	tu := fmt.Sprintf(`[{"ten":"Thu cọc","loai":0,"phan_loai":%d,"mo_ta":"Khách đặt cọc","so_tien":100000},`+
		`{"ten":"Sai vế","loai":0,"phan_loai":%d,"mo_ta":"","so_tien":0},{"ten":"Mất loại","loai":1,"phan_loai":99999999,"mo_ta":"","so_tien":5000}]`, thuKhac, luong)
	if r := dat(tu); r.ma != http.StatusOK {
		t.Fatalf("MP02 lưu mẫu phải 200, nhận %d %s", r.ma, catBot(r.than))
	}
	_, ds = docMauPhieu(t, h, thuNgan)
	if len(ds) != 3 || id(ds[0].PhanLoai) != thuKhac || ds[0].MoTa != "Khách đặt cọc" || ds[1].PhanLoai != nil || ds[2].PhanLoai != nil {
		t.Fatalf("MP02 giải phân loại sai: %+v", ds)
	}

	// MP03 kiểm ô cài đặt.
	bay := strings.TrimSuffix(strings.Repeat(`{"ten":"A","loai":1,"phan_loai":0,"mo_ta":"","so_tien":0},`, 7), ",")
	for _, c := range []struct{ v, cau string }{
		{"[" + bay + "]", "Tối đa 6 mẫu"},
		{`[{"ten":"  ","loai":1}]`, "Mẫu 1: nhập tên"},
		{`[{"ten":"Một cái tên nút rất là dài quá ba mươi ký tự","loai":1}]`, "tối đa 30 ký tự"},
		{`[{"ten":"A","loai":3}]`, "thu hoặc chi"},
		{`[{"ten":"A","loai":1,"so_tien":-5}]`, "số tiền gợi ý"},
		{`không phải json`, "không đúng dạng"},
	} {
		if r := dat(c.v); r.ma != http.StatusUnprocessableEntity || !strings.Contains(r.than, c.cau) {
			t.Fatalf("MP03 %q phải 422 %q, nhận %d %s", c.v, c.cau, r.ma, catBot(r.than))
		}
	}

	// MP04 tắt hết: "[]" → mảng rỗng (không null).
	if r := dat("[]"); r.ma != http.StatusOK {
		t.Fatalf("MP04 lưu [] phải 200, nhận %d", r.ma)
	}
	if than, ds := docMauPhieu(t, h, thuNgan); len(ds) != 0 || !strings.Contains(than, `"mau_phieu":[]`) {
		t.Fatalf("MP04 tắt mẫu thì mau_phieu là [], nhận %s", catBot(than))
	}

	// MP05 thu ngân không sửa được mẫu (Cài đặt là việc của chủ).
	if r := h.goi(t, thuNgan, http.MethodPut, "/api/v1/admin/settings", map[string]any{"items": map[string]string{"pos_cashbook_templates": "[]"}}); r.ma != http.StatusForbidden {
		t.Fatalf("MP05 thu ngân sửa mẫu phải 403, nhận %d", r.ma)
	}
}
