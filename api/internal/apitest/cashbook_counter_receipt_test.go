package apitest

import (
	"fmt"
	"net/http"
	"strings"
	"testing"
)

// GET /quay/thu-chi/:id — đọc một phiếu để in biên nhận, cùng phạm vi với danh
// sách quầy: chỉ phiếu lập tay của chính mình, ở chi nhánh đang làm.

// KB BN01–BN04
func TestThuChiQuay_DocMotPhieuDeIn(t *testing.T) {
	h := dungHeThong(t)
	a, b := haiCuaHang(t, h)
	// Phiếu của chủ lập ở màn quản trị TRƯỚC khi mở ca → không thuộc ca nào.
	_, ngoaiCa := lapPhieuTC(t, h, a.token, thuTienMat(700000))
	moCa(t, h, a, 0)
	thuNgan := h.dangNhapVoi(t, a.ma, "nhanvien")
	doc := func(tok string, id uint) traLoi {
		return h.goi(t, tok, http.MethodGet, fmt.Sprintf("%s/%d", duongTCQuay, id), nil)
	}

	_, cuaToi := lapTCQuay(t, h, thuNgan, chiTienMat(40000))
	r := doc(thuNgan, cuaToi.ID)
	if r.ma != http.StatusOK || !strings.Contains(r.than, `"amount":40000`) || !strings.Contains(r.than, `"created_by_name":`) || !strings.Contains(r.than, `"category_name":"Phân loại thử"`) {
		t.Fatalf("BN01 thu ngân đọc phiếu của mình phải 200 kèm tên phân loại / người lập, nhận %d %s", r.ma, catBot(r.than))
	}

	if r := doc(thuNgan, ngoaiCa.ID); r.ma != http.StatusNotFound {
		t.Fatalf("BN02 phiếu người khác lập, ngoài ca phải 404, nhận %d", r.ma)
	}
	// Phiếu người khác TRONG ca: chế độ "Cả ca" xem được thì in được.
	_, cuaChu := lapTCQuay(t, h, a.token, thuTienMat(500000))
	if r := doc(thuNgan, cuaChu.ID); r.ma != http.StatusOK {
		t.Fatalf("BN02b phiếu người khác trong ca phải 200, nhận %d %s", r.ma, catBot(r.than))
	}
	if r := doc(b.token, cuaToi.ID); r.ma != http.StatusNotFound {
		t.Fatalf("BN03 cửa hàng khác phải 404, nhận %d", r.ma)
	}
	if r := doc(thuNgan, 99999999); r.ma != http.StatusNotFound {
		t.Fatalf("BN04 id không có phải 404, nhận %d", r.ma)
	}
}
