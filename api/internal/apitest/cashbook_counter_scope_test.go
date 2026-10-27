package apitest

import (
	"fmt"
	"net/http"
	"strings"
	"testing"
)

// Công tắc "Cả ca / Của tôi" của màn Thu chi quầy: pham_vi=ca bỏ điều kiện người
// lập nhưng giữ ca + chi nhánh; phiếu người khác khoá sửa / xoá ở quầy.

// KB CC01–CC07
func TestThuChiQuay_CaCaHayCuaToi(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	ca := uint(moCa(t, h, a, 0)["id"].(float64))
	thuNgan := h.dangNhapVoi(t, a.ma, "nhanvien")

	_, cuaToi := lapTCQuay(t, h, thuNgan, chiTienMat(40000))
	_, cuaChu := lapTCQuay(t, h, a.token, thuTienMat(700000))
	lapPhieuTC(t, h, a.token, chiTienMat(10000)) // màn quản trị, cùng ca

	// CC01 mặc định / thiếu shift_id: chỉ phiếu của mình.
	for _, q := range []string{fmt.Sprintf("shift_id=%d", ca), "pham_vi=ca"} {
		if ds, _ := docTCQuay(t, h, thuNgan, q); len(ds) != 1 || ds[0].ID != cuaToi.ID {
			t.Fatalf("CC01 %q chỉ được thấy phiếu mình, nhận %d dòng", q, len(ds))
		}
	}

	// CC02 cả ca: đủ 3 phiếu của ca, tổng tính trên cả ca.
	ds, meta := docTCQuay(t, h, thuNgan, fmt.Sprintf("shift_id=%d&pham_vi=ca", ca))
	if len(ds) != 3 || meta.TongThu != 700000 || meta.TongChi != 50000 {
		t.Fatalf("CC02 cả ca phải 3 phiếu, thu 700000 / chi 50000; nhận %d, %v / %v", len(ds), meta.TongThu, meta.TongChi)
	}
	// CC03 phiếu người khác khoá, phiếu mình không; có tên người lập.
	for _, p := range ds {
		khoa := p.ID != cuaToi.ID
		if p.Locked != khoa || (khoa && !strings.Contains(p.LockedReason, "người khác lập")) {
			t.Fatalf("CC03 phiếu %d: locked phải %v (lý do người khác lập), nhận %v %q", p.ID, khoa, p.Locked, p.LockedReason)
		}
	}
	r := h.goi(t, thuNgan, http.MethodGet, fmt.Sprintf("%s?shift_id=%d&pham_vi=ca", duongTCQuay, ca), nil)
	if strings.Count(r.than, `"created_by_name":"`) != 3 {
		t.Fatalf("CC03 mỗi phiếu phải kèm tên người lập: %s", catBot(r.than))
	}

	// CC04 ca khác (không thuộc chi nhánh / không có phiếu) — không lộ gì thêm.
	if ds, _ := docTCQuay(t, h, thuNgan, "shift_id=999999&pham_vi=ca"); len(ds) != 0 {
		t.Fatalf("CC04 ca không có phiếu phải rỗng, nhận %d", len(ds))
	}

	// CC05 thu ngân sửa / xoá phiếu của chủ ở quầy → bị chặn.
	sua := func(tok string, id uint, than map[string]any) traLoi {
		return h.goi(t, tok, http.MethodPut, fmt.Sprintf("%s/%d", duongTCQuay, id), voiLoaiTC(t, h, tok, than))
	}
	if r := sua(thuNgan, cuaChu.ID, thuTienMat(1)); r.ma == http.StatusOK {
		t.Fatal("CC05 thu ngân không được sửa phiếu của chủ ở quầy")
	}
	if r := h.goi(t, thuNgan, http.MethodDelete, fmt.Sprintf("%s/%d", duongTCQuay, cuaChu.ID), nil); r.ma == http.StatusOK {
		t.Fatal("CC05 thu ngân không được xoá phiếu của chủ ở quầy")
	}

	// CC06 ở quầy ai lập người ấy sửa — kể cả chủ không sửa phiếu thu ngân qua đường quầy…
	if r := sua(a.token, cuaToi.ID, chiTienMat(45000)); r.ma == http.StatusOK {
		t.Fatalf("CC06 chủ sửa phiếu thu ngân qua đường quầy phải bị chặn, nhận %d", r.ma)
	}
	// …nhưng màn quản trị vẫn giữ quyền sửa phiếu người khác như cũ.
	if r := h.goi(t, a.token, http.MethodPut, fmt.Sprintf("/api/v1/admin/thu-chi/%d", cuaToi.ID), voiLoaiTC(t, h, a.token, chiTienMat(45000))); r.ma != http.StatusOK {
		t.Fatalf("CC06 màn quản trị vẫn sửa được phiếu người khác, nhận %d %s", r.ma, catBot(r.than))
	}

	// CC07 thu ngân vẫn sửa phiếu của mình bình thường.
	if r := sua(thuNgan, cuaToi.ID, chiTienMat(42000)); r.ma != http.StatusOK {
		t.Fatalf("CC07 sửa phiếu của mình phải 200, nhận %d %s", r.ma, catBot(r.than))
	}
}
