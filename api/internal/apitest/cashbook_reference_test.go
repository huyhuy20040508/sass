package apitest

import (
	"fmt"
	"net/http"
	"strings"
	"testing"
)

// TestThuChi_ThamChieuPhaiThuocCuaHangVaDungVe — KB #79: phân loại của cửa hàng
// khác, phân loại sai vế, id không tồn tại, người nộp của cửa hàng khác — đều
// 422 theo ô ở cả sổ quản trị lẫn thu chi quầy, lúc lập lẫn lúc sửa.
func TestThuChi_ThamChieuPhaiThuocCuaHangVaDungVe(t *testing.T) {
	h := dungHeThong(t)
	a, b := haiCuaHang(t, h)
	moCa(t, h, a, 0)
	thuNgan := h.dangNhapVoi(t, a.ma, "nhanvien")

	loai := func(tok string, ve int) uint {
		return voiLoaiTC(t, h, tok, map[string]any{"type": ve})["category_id"].(uint)
	}
	chiA, thuA, chiB := loai(a.token, 1), loai(a.token, 0), loai(b.token, 1)
	nguoiB := uint(doc(t, h.goi(t, b.token, http.MethodPost, "/api/v1/admin/nguoi-nop-thu-chi",
		map[string]any{"name": "Người của B " + b.vet}))["id"].(float64))

	phieu := func(them map[string]any) map[string]any {
		than := map[string]any{"type": 1, "amount": 20000, "payment_method": "cash", "note": "Mua nước", "category_id": chiA, "reason": "Sửa thử"}
		for k, v := range them {
			than[k] = v
		}
		return than
	}
	sai := []struct {
		ten, o, cau string
		than        map[string]any
	}{
		{"phân loại của cửa hàng khác", "category_id", "không thuộc cửa hàng", phieu(map[string]any{"category_id": chiB})},
		{"phân loại sai vế", "category_id", "loại thu, phiếu đang là phiếu chi", phieu(map[string]any{"category_id": thuA})},
		{"phân loại không tồn tại", "category_id", "không tồn tại", phieu(map[string]any{"category_id": 99999999})},
		{"người nộp của cửa hàng khác", "payer_id", "không thuộc cửa hàng", phieu(map[string]any{"payer_type": "other", "payer_id": nguoiB})},
		{"người nộp không tồn tại", "payer_id", "không tồn tại", phieu(map[string]any{"payer_type": "supplier", "payer_id": 99999999})},
	}
	kiem := func(viec string, r traLoi, o, cau string) {
		t.Helper()
		if r.ma != http.StatusUnprocessableEntity || !strings.Contains(r.than, `"`+o+`"`) || !strings.Contains(r.than, cau) {
			t.Errorf("%s: phải 422 ở ô %s (%q), nhận %d\n%s", viec, o, cau, r.ma, catBot(r.than))
		}
	}

	for _, duong := range []string{"/api/v1/admin/thu-chi", duongTCQuay} {
		tok := a.token
		if duong == duongTCQuay {
			tok = thuNgan
		}
		r := h.goi(t, tok, http.MethodPost, duong, phieu(nil))
		if r.ma != http.StatusCreated {
			t.Fatalf("%s: phiếu đúng phải lập được, nhận %d\n%s", duong, r.ma, catBot(r.than))
		}
		id := uint(doc(t, r)["id"].(float64))
		for _, c := range sai {
			kiem(duong+" lập · "+c.ten, h.goi(t, tok, http.MethodPost, duong, c.than), c.o, c.cau)
			kiem(duong+" sửa · "+c.ten, h.goi(t, tok, http.MethodPut, fmt.Sprintf("%s/%d", duong, id), c.than), c.o, c.cau)
		}
	}

	// Phân loại bị xoá sau khi lập: sửa ô khác của phiếu cũ vẫn được, giữ nguyên phân loại.
	r := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/thu-chi", phieu(nil))
	id := uint(doc(t, r)["id"].(float64))
	if err := h.db.WithContext(ctxThoat()).Exec("UPDATE income_expense_types SET deleted_at = NOW() WHERE id = ?", chiA).Error; err != nil {
		t.Fatalf("không xoá được phân loại: %v", err)
	}
	if r := h.goi(t, a.token, http.MethodPut, fmt.Sprintf("/api/v1/admin/thu-chi/%d", id), phieu(map[string]any{"amount": 25000})); r.ma != http.StatusOK {
		t.Fatalf("sửa số tiền phiếu có phân loại đã xoá phải 200, nhận %d\n%s", r.ma, catBot(r.than))
	}
}
