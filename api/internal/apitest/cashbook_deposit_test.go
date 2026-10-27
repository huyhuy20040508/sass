package apitest

import (
	"encoding/json"
	"fmt"
	"net/http"
	"strings"
	"testing"
	"time"
)

// Nộp tiền về công ty giữa ca (KB #20, migration 0096): phiếu chi tiền mặt mang
// kind deposit — két giảm, nhưng không phải chi phí trong báo cáo.

func nopVe(soTien float64, nguoi uint) map[string]any {
	return map[string]any{"type": 1, "amount": soTien, "payment_method": "cash", "kind": "deposit",
		"payer_type": "quan_ly", "payer_id": nguoi, "note": "Nộp tiền về công ty"}
}

// KB NV01–NV08
func TestThuChi_NopVeCongTy(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	moCa(t, h, a, 1000000)
	thuNgan := h.dangNhapVoi(t, a.ma, "nhanvien")
	lap := func(than map[string]any) (traLoi, phieuTC) {
		r := h.goi(t, thuNgan, http.MethodPost, duongTCQuay, than)
		var body struct {
			Data phieuTC `json:"data"`
		}
		_ = json.Unmarshal([]byte(r.than), &body)
		return r, body.Data
	}

	// NV01 nộp về: không cần phân loại, ghi kind deposit.
	r, p := lap(nopVe(500000, a.quanTri))
	if r.ma != http.StatusCreated || !strings.Contains(r.than, `"kind":"deposit"`) || !strings.Contains(r.than, `"category_id":null`) {
		t.Fatalf("NV01 nộp về phải 201 kind deposit, không phân loại; nhận %d %s", r.ma, catBot(r.than))
	}

	// NV02 luật của phiếu nộp về.
	for _, c := range []struct {
		ten, o string
		doi    map[string]any
	}{
		{"thiếu người nhận", "payer_id", map[string]any{"payer_type": "", "payer_id": 0}},
		{"người ngoài", "payer_id", map[string]any{"payer_type": "other"}},
		{"chuyển khoản", "payment_method", map[string]any{"payment_method": "transfer"}},
		{"phiếu thu", "type", map[string]any{"type": 0}},
	} {
		than := nopVe(100000, a.quanTri)
		for k, v := range c.doi {
			than[k] = v
		}
		if r, _ := lap(than); r.ma != http.StatusUnprocessableEntity || !strings.Contains(r.than, `"`+c.o+`"`) {
			t.Fatalf("NV02 %s phải 422 ô %s, nhận %d %s", c.ten, c.o, r.ma, catBot(r.than))
		}
	}
	// NV03 phiếu thường thiếu phân loại vẫn bị chặn.
	if r, _ := lap(chiTienMat(1000)); r.ma != http.StatusUnprocessableEntity || !strings.Contains(r.than, "Chọn phân loại cho phiếu.") {
		t.Fatalf("NV03 phiếu thường thiếu phân loại phải 422, nhận %d %s", r.ma, catBot(r.than))
	}

	// NV04 hạn mức phiếu chi không áp cho nộp về (người nhận ký biên nhận).
	h.goi(t, a.token, http.MethodPut, "/api/v1/admin/settings", map[string]any{"items": map[string]string{"pos_cashbook_expense_limit": "100000"}})
	if r, _ := lap(nopVe(300000, a.quanTri)); r.ma != http.StatusCreated {
		t.Fatalf("NV04 nộp về vượt hạn mức chi vẫn lập được, nhận %d %s", r.ma, catBot(r.than))
	}
	if r, _ := lapTCQuay(t, h, thuNgan, chiTienMat(200000)); r.ma != http.StatusUnprocessableEntity {
		t.Fatalf("NV04 phiếu chi thường vượt hạn mức vẫn phải đòi duyệt, nhận %d", r.ma)
	}
	h.goi(t, a.token, http.MethodPut, "/api/v1/admin/settings", map[string]any{"items": map[string]string{"pos_cashbook_expense_limit": "0"}})
	lapTCQuay(t, h, thuNgan, chiTienMat(20000)) // một khoản chi thật

	// NV05 ca: da_nop_ve = 800.000, két theo sổ đã trừ.
	var ca struct {
		Data struct {
			TongChi float64 `json:"tong_chi"`
			DaNopVe float64 `json:"da_nop_ve"`
		} `json:"data"`
	}
	docJSON(t, h.goi(t, a.token, http.MethodGet, "/api/v1/admin/ca-lam-viec/hien-tai", nil), &ca)
	if ca.Data.DaNopVe != 800000 || ca.Data.TongChi != 820000 {
		t.Fatalf("NV05 ca phải da_nop_ve 800.000, tổng chi két 820.000; nhận %+v", ca.Data)
	}

	// NV06 báo cáo cuối ngày: chi phí không gồm nộp về, quỹ tiền mặt có.
	var bc struct {
		Data struct {
			Cashbook struct {
				Expense      float64 `json:"expense"`
				ExpenseCount int64   `json:"expense_count"`
				CashExpense  float64 `json:"cash_expense"`
				Deposit      float64 `json:"deposit"`
				DepositCount int64   `json:"deposit_count"`
			} `json:"cashbook"`
		} `json:"data"`
	}
	docJSON(t, h.goi(t, a.token, http.MethodGet, "/api/v1/admin/reports/summary?date="+time.Now().Format("2006-01-02"), nil), &bc)
	if c := bc.Data.Cashbook; c.Expense != 20000 || c.ExpenseCount != 1 || c.CashExpense != 820000 || c.Deposit != 800000 || c.DepositCount != 2 {
		t.Fatalf("NV06 báo cáo phải tách nộp về khỏi chi phí: %+v", c)
	}

	// NV07 sửa phiếu nộp về: vẫn phải giữ luật (người nhận nhân viên), loại phiếu không đổi.
	duong := fmt.Sprintf("%s/%d", duongTCQuay, p.ID)
	sai := nopVe(450000, a.quanTri)
	sai["payer_type"], sai["reason"] = "other", "Đổi người"
	if r := h.goi(t, thuNgan, http.MethodPut, duong, sai); r.ma != http.StatusUnprocessableEntity || !strings.Contains(r.than, "payer_id") {
		t.Fatalf("NV07 sửa nộp về sang người ngoài phải 422, nhận %d %s", r.ma, catBot(r.than))
	}
	dung := nopVe(450000, a.quanTri)
	delete(dung, "kind")
	dung["reason"] = "Đếm lại"
	if r := h.goi(t, thuNgan, http.MethodPut, duong, dung); r.ma != http.StatusOK || !strings.Contains(r.than, `"kind":"deposit"`) {
		t.Fatalf("NV07 sửa số tiền nộp về phải 200, giữ kind; nhận %d %s", r.ma, catBot(r.than))
	}
	// NV08 phiếu thường gửi kind deposit lúc sửa cũng không đổi loại.
	_, thuong := lapTCQuay(t, h, thuNgan, chiTienMat(5000))
	x := voiLoaiTC(t, h, thuNgan, chiTienMat(6000))
	x["kind"] = "deposit"
	if r := h.goi(t, thuNgan, http.MethodPut, fmt.Sprintf("%s/%d", duongTCQuay, thuong.ID), x); r.ma != http.StatusOK || !strings.Contains(r.than, `"kind":""`) {
		t.Fatalf("NV08 phiếu thường không đổi thành nộp về, nhận %d %s", r.ma, catBot(r.than))
	}
}
