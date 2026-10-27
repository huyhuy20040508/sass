package apitest

import (
	"fmt"
	"net/http"
	"strings"
	"testing"
	"time"
)

// Hạn mức phiếu chi ở quầy: vượt thì chủ / quản trị phải nhập tài khoản để duyệt,
// phiếu ghi approved_by + approved_at. Phiếu thu và màn quản trị không bị chặn.

// KB HM01–HM10
func TestThuChiQuay_HanMucPhieuChi(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	moCa(t, h, a, 0)
	thuNgan := h.dangNhapVoi(t, a.ma, "nhanvien")
	dat := func(v string) {
		t.Helper()
		if r := h.goi(t, a.token, http.MethodPut, "/api/v1/admin/settings", map[string]any{"items": map[string]string{"pos_cashbook_expense_limit": v}}); r.ma != http.StatusOK {
			t.Fatalf("lưu hạn mức %s phải 200, nhận %d %s", v, r.ma, catBot(r.than))
		}
	}
	voiDuyet := func(than map[string]any, ten, mk string) map[string]any {
		than["approver_username"], than["approver_password"] = ten, mk
		return than
	}
	duyetCua := func(id uint) (by *uint, at *time.Time) {
		var row struct {
			ApprovedBy *uint
			ApprovedAt *time.Time
		}
		h.db.WithContext(ctxThoat()).Raw("SELECT approved_by, approved_at FROM income_expenses WHERE id = ?", id).Scan(&row)
		return row.ApprovedBy, row.ApprovedAt
	}

	// HM01 hạn mức 0 (mặc định) = không giới hạn.
	if r, _ := lapTCQuay(t, h, thuNgan, chiTienMat(5000000)); r.ma != http.StatusCreated {
		t.Fatalf("HM01 chưa đặt hạn mức thì chi bao nhiêu cũng được, nhận %d %s", r.ma, catBot(r.than))
	}

	dat("100000")
	// HM02 bằng hạn mức vẫn lập được, không ghi người duyệt.
	r, p1 := lapTCQuay(t, h, thuNgan, chiTienMat(100000))
	if by, _ := duyetCua(p1.ID); r.ma != http.StatusCreated || by != nil {
		t.Fatalf("HM02 chi đúng bằng hạn mức phải 201 không người duyệt, nhận %d by=%v", r.ma, by)
	}
	// HM03 vượt mà không có tài khoản duyệt → 422 CAN_DUYET_CHI.
	r, _ = lapTCQuay(t, h, thuNgan, chiTienMat(150000))
	if r.ma != http.StatusUnprocessableEntity || maLoi(t, r) != "CAN_DUYET_CHI" || !strings.Contains(r.than, "vượt hạn mức 100.000₫") {
		t.Fatalf("HM03 vượt hạn mức phải 422 CAN_DUYET_CHI, nhận %d %s", r.ma, catBot(r.than))
	}
	// HM04 phiếu THU không bị chặn.
	if r, _ := lapTCQuay(t, h, thuNgan, thuTienMat(900000)); r.ma != http.StatusCreated {
		t.Fatalf("HM04 phiếu thu không bị hạn mức, nhận %d", r.ma)
	}
	// HM05 thu ngân tự duyệt bằng tài khoản mình / sai mật khẩu chủ → SAI_DUYET_CHI.
	for _, c := range [][2]string{{"nhanvien", matKhauTest}, {"quantri", "sai-mat-khau"}} {
		if r, _ := lapTCQuay(t, h, thuNgan, voiDuyet(chiTienMat(150000), c[0], c[1])); r.ma != http.StatusUnprocessableEntity || maLoi(t, r) != "SAI_DUYET_CHI" {
			t.Fatalf("HM05 duyệt bằng %s phải 422 SAI_DUYET_CHI, nhận %d %s", c[0], r.ma, catBot(r.than))
		}
	}
	// HM06 chủ nhập đúng → 201, ghi người duyệt + lúc duyệt, trả tên người duyệt.
	r, p2 := lapTCQuay(t, h, thuNgan, voiDuyet(chiTienMat(150000), "quantri", matKhauTest))
	by, at := duyetCua(p2.ID)
	if r.ma != http.StatusCreated || by == nil || *by != a.quanTri || at == nil || !strings.Contains(r.than, `"approved_by_name":`) {
		t.Fatalf("HM06 chủ duyệt đúng phải 201 kèm approved_by=%d, nhận %d by=%v at=%v %s", a.quanTri, r.ma, by, at, catBot(r.than))
	}
	if strings.Contains(r.than, matKhauTest) {
		t.Fatal("HM06 phản hồi không được lặp lại mật khẩu người duyệt")
	}
	// HM07 chủ tự lập ở quầy: không hỏi, ghi chính chủ là người duyệt.
	r, p3 := lapTCQuay(t, h, a.token, chiTienMat(300000))
	if by, _ := duyetCua(p3.ID); r.ma != http.StatusCreated || by == nil || *by != a.quanTri {
		t.Fatalf("HM07 chủ lập vượt hạn mức phải 201 và tự là người duyệt, nhận %d by=%v", r.ma, by)
	}
	// HM08 màn quản trị (/admin/thu-chi) không áp hạn mức của quầy.
	if code, p4 := lapPhieuTC(t, h, a.token, chiTienMat(300000)); code != http.StatusCreated {
		t.Fatalf("HM08 màn quản trị không bị hạn mức quầy, nhận %d", code)
	} else if by, _ := duyetCua(p4.ID); by != nil {
		t.Fatalf("HM08 phiếu lập ở màn quản trị không ghi người duyệt, nhận %v", by)
	}

	// HM09 sửa: nâng phiếu 100.000 lên 200.000 phải duyệt; đã duyệt rồi sửa mô tả thì giữ người duyệt.
	sua := func(id uint, than map[string]any) traLoi {
		return h.goi(t, thuNgan, http.MethodPut, fmt.Sprintf("%s/%d", duongTCQuay, id), voiLoaiTC(t, h, thuNgan, than))
	}
	if r := sua(p1.ID, chiTienMat(200000)); r.ma != http.StatusUnprocessableEntity || maLoi(t, r) != "CAN_DUYET_CHI" {
		t.Fatalf("HM09 sửa vượt hạn mức phải 422 CAN_DUYET_CHI, nhận %d %s", r.ma, catBot(r.than))
	}
	if r := sua(p1.ID, voiDuyet(chiTienMat(200000), "quantri", matKhauTest)); r.ma != http.StatusOK {
		t.Fatalf("HM09 sửa có chủ duyệt phải 200, nhận %d %s", r.ma, catBot(r.than))
	}
	_, at1 := duyetCua(p1.ID)
	giam := chiTienMat(180000)
	giam["note"] = "sửa mô tả"
	if r := sua(p1.ID, giam); r.ma != http.StatusOK {
		t.Fatalf("HM09 phiếu đã duyệt, giảm tiền thì không hỏi lại, nhận %d %s", r.ma, catBot(r.than))
	}
	if by, at2 := duyetCua(p1.ID); by == nil || *by != a.quanTri || at2 == nil || !at2.Equal(*at1) {
		t.Fatalf("HM09 giữ người duyệt + lúc duyệt cũ, nhận by=%v at=%v (cũ %v)", by, at2, at1)
	}

	// HM10 sai 5 lần → khoá 15 phút: lần sau đúng mật khẩu vẫn 429.
	for i := 0; i < 5; i++ {
		lapTCQuay(t, h, thuNgan, voiDuyet(chiTienMat(150000), "quantri", "sai"))
	}
	r, _ = lapTCQuay(t, h, thuNgan, voiDuyet(chiTienMat(150000), "quantri", matKhauTest))
	if r.ma != http.StatusTooManyRequests || !strings.Contains(r.than, "quá nhiều lần") {
		t.Fatalf("HM10 sai quá 5 lần phải 429 dù lần này đúng, nhận %d %s", r.ma, catBot(r.than))
	}
	// Khoá theo NGƯỜI LẬP: chủ vẫn tự lập được.
	if r, _ := lapTCQuay(t, h, a.token, chiTienMat(300000)); r.ma != http.StatusCreated {
		t.Fatalf("HM10 khoá chỉ áp người nhập sai, chủ vẫn lập được; nhận %d", r.ma)
	}
}
