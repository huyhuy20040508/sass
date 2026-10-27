package apitest

import (
	"encoding/json"
	"fmt"
	"net/http"
	"strings"
	"testing"
)

// Phiếu thu chi chuyển khoản ghi TÀI KHOẢN (KB #19, migration 0095).

func ckTienChuyen(soTien float64, tk uint) map[string]any {
	b := map[string]any{"type": 1, "amount": soTien, "payment_method": "transfer", "note": "Chuyển khoản"}
	if tk > 0 {
		b["bank_account_id"] = tk
	}
	return b
}

// KB TK01–TK09
func TestThuChi_TaiKhoanChuyenKhoan(t *testing.T) {
	h := dungHeThong(t)
	a, b := haiCuaHang(t, h)
	moCa(t, h, a, 0)
	thuNgan := h.dangNhapVoi(t, a.ma, "nhanvien")
	tkDB := func(id uint) *uint {
		var v *uint
		h.db.WithContext(ctxThoat()).Raw("SELECT bank_account_id FROM income_expenses WHERE id = ?", id).Scan(&v)
		return v
	}

	// TK01 chi nhánh chưa khai tài khoản: chuyển khoản không bắt chọn.
	if r, _ := lapTCQuay(t, h, thuNgan, ckTienChuyen(10000, 0)); r.ma != http.StatusCreated {
		t.Fatalf("TK01 chưa có tài khoản thì lập chuyển khoản tự do, nhận %d %s", r.ma, catBot(r.than))
	}

	ck, _, _ := docPM(t, h, a.token).pt(4)
	them := func(tok string, method uint, bin, so, chu string) uint {
		return idTra(t, h.goi(t, tok, http.MethodPost, fmt.Sprintf("/api/v1/admin/payment-methods/%d/details", method), taiKhoan(bin, so, chu, nil)))
	}
	tk1 := them(a.token, ck, "970436", "0071001234567", "NGUYEN VAN DUC")
	tk2 := them(a.token, ck, "970407", "190366889900", "CONG TY A")
	ckB, _, _ := docPM(t, h, b.token).pt(4)
	tkB := them(b.token, ckB, "970418", "2151", "CUA HANG B")

	// TK02 danh mục quầy trả 2 tài khoản đang bật, có một mặc định.
	r := h.goi(t, thuNgan, http.MethodGet, duongTCQuay+"/danh-muc", nil)
	var dm struct {
		Data struct {
			TaiKhoan []struct {
				ID      uint   `json:"id"`
				Nhan    string `json:"label"`
				MacDinh bool   `json:"is_default"`
			} `json:"tai_khoan"`
		} `json:"data"`
	}
	_ = json.Unmarshal([]byte(r.than), &dm)
	if len(dm.Data.TaiKhoan) != 2 || !strings.Contains(dm.Data.TaiKhoan[0].Nhan+dm.Data.TaiKhoan[1].Nhan, "Vietcombank · 0071001234567 · NGUYEN VAN DUC") {
		t.Fatalf("TK02 danh mục phải có 2 tài khoản kèm nhãn, nhận %s", catBot(r.than))
	}

	// TK03 đã có tài khoản: chuyển khoản thiếu tài khoản → 422.
	if r, _ := lapTCQuay(t, h, thuNgan, ckTienChuyen(20000, 0)); r.ma != http.StatusUnprocessableEntity || !strings.Contains(r.than, "Chọn tài khoản") {
		t.Fatalf("TK03 thiếu tài khoản phải 422, nhận %d %s", r.ma, catBot(r.than))
	}
	// TK04 chọn tk2 → lưu id + nhãn; tiền mặt gửi kèm tài khoản thì bỏ.
	r, p2 := lapTCQuay(t, h, thuNgan, ckTienChuyen(30000, tk2))
	if r.ma != http.StatusCreated || !strings.Contains(r.than, `"bank_account_label":"`) || !strings.Contains(r.than, "190366889900") || tkDB(p2.ID) == nil || *tkDB(p2.ID) != tk2 {
		t.Fatalf("TK04 phải lưu tài khoản %d, nhận %d %s", tk2, r.ma, catBot(r.than))
	}
	tm := chiTienMat(5000)
	tm["bank_account_id"] = tk2
	if _, p := lapTCQuay(t, h, thuNgan, tm); tkDB(p.ID) != nil {
		t.Fatal("TK04 phiếu tiền mặt không được ghi tài khoản")
	}
	// TK05 tài khoản của cửa hàng khác → 422.
	if r, _ := lapTCQuay(t, h, thuNgan, ckTienChuyen(1000, tkB)); r.ma != http.StatusUnprocessableEntity || !strings.Contains(r.than, "không thuộc chi nhánh") {
		t.Fatalf("TK05 tài khoản cửa hàng khác phải 422, nhận %d %s", r.ma, catBot(r.than))
	}

	// TK06 tắt tk1: phiếu mới không chọn được, phiếu cũ đang ghi tk1 vẫn sửa giữ được.
	_, p1 := lapTCQuay(t, h, thuNgan, ckTienChuyen(40000, tk1))
	h.goi(t, a.token, http.MethodPut, fmt.Sprintf("/api/v1/admin/payment-methods/%d/details/%d/status", ck, tk1), map[string]any{"status": false})
	if r, _ := lapTCQuay(t, h, thuNgan, ckTienChuyen(1000, tk1)); r.ma != http.StatusUnprocessableEntity || !strings.Contains(r.than, "đang tắt") {
		t.Fatalf("TK06 tài khoản đã tắt phải 422, nhận %d %s", r.ma, catBot(r.than))
	}
	sua := func(id uint, than map[string]any) traLoi {
		return h.goi(t, thuNgan, http.MethodPut, fmt.Sprintf("%s/%d", duongTCQuay, id), voiLoaiTC(t, h, thuNgan, than))
	}
	if r := sua(p1.ID, ckTienChuyen(45000, tk1)); r.ma != http.StatusOK || *tkDB(p1.ID) != tk1 {
		t.Fatalf("TK06 phiếu cũ giữ tài khoản đã tắt phải sửa được, nhận %d %s", r.ma, catBot(r.than))
	}
	// TK07 đổi phiếu sang tiền mặt → bỏ tài khoản.
	if r := sua(p1.ID, chiTienMat(45000)); r.ma != http.StatusOK || tkDB(p1.ID) != nil {
		t.Fatalf("TK07 đổi sang tiền mặt phải bỏ tài khoản, nhận %d", r.ma)
	}

	// TK08 màn quản trị: lọc theo tài khoản; danh sách tài khoản cho hộp lập (đang bật) và cho ô lọc (đủ).
	var ds struct {
		Data []struct {
			ID uint `json:"id"`
		} `json:"data"`
	}
	docJSON(t, h.goi(t, a.token, http.MethodGet, fmt.Sprintf("/api/v1/admin/thu-chi?bank_account_id=%d", tk2), nil), &ds)
	if len(ds.Data) != 1 || ds.Data[0].ID != p2.ID {
		t.Fatalf("TK08 lọc theo tài khoản phải ra đúng phiếu %d, nhận %+v", p2.ID, ds.Data)
	}
	docJSON(t, h.goi(t, a.token, http.MethodGet, "/api/v1/admin/thu-chi/tai-khoan?cho=lap", nil), &ds)
	if len(ds.Data) != 1 || ds.Data[0].ID != tk2 {
		t.Fatalf("TK08 hộp lập chỉ chào tài khoản đang bật, nhận %+v", ds.Data)
	}
	docJSON(t, h.goi(t, a.token, http.MethodGet, "/api/v1/admin/thu-chi/tai-khoan", nil), &ds)
	if len(ds.Data) != 2 {
		t.Fatalf("TK08 ô lọc phải có cả tài khoản đã tắt, nhận %+v", ds.Data)
	}

	// TK09 tài khoản đã có phiếu dùng thì không xoá được.
	if r := h.goi(t, a.token, http.MethodDelete, fmt.Sprintf("/api/v1/admin/payment-methods/%d/details/%d", ck, tk2), nil); r.ma != http.StatusConflict || !strings.Contains(r.than, "phiếu thu chi") {
		t.Fatalf("TK09 xoá tài khoản có phiếu dùng phải 409, nhận %d %s", r.ma, catBot(r.than))
	}
}
