package apitest

import (
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"testing"

	"sass-api/internal/domain"
	"sass-api/internal/tenant"
)

// Bài kiểm THU CHI Ở QUẦY (/admin/quay/thu-chi) — bản gọn của sổ thu chi cho
// module Thu ngân. Ba luật do máy chủ ép, không để trình duyệt tự giác:
//
//   - chỉ thấy phiếu MÌNH lập, và chỉ phiếu lập tay;
//   - chưa mở ca thì không lập / sửa / xoá được (409 CHUA_MO_CA);
//   - ghi bằng quyền `so-quy.them`, không cần bộ `thu-chi.*` của sổ quản trị.

const duongTCQuay = "/api/v1/admin/quay/thu-chi"

type metaTCQuay struct {
	Total      int64   `json:"total"`
	TongThu    float64 `json:"total_income"`
	TongChi    float64 `json:"total_expense"`
	TienMatThu float64 `json:"cash_income"`
	TienMatChi float64 `json:"cash_expense"`
	SoThu      int64   `json:"income_count"`
	SoChi      int64   `json:"expense_count"`
	CaDangMo   *uint   `json:"ca_dang_mo"`
	CoTheLap   bool    `json:"co_the_lap"`
}

func docTCQuay(t *testing.T, h *heThong, token, query string) ([]phieuTC, metaTCQuay) {
	t.Helper()

	duong := duongTCQuay
	if query != "" {
		duong += "?" + query
	}
	res := h.goi(t, token, http.MethodGet, duong, nil)
	if res.ma != http.StatusOK {
		t.Fatalf("đọc thu chi quầy phải trả 200, nhận %d\n%s", res.ma, catBot(res.than))
	}

	var body struct {
		Data []phieuTC  `json:"data"`
		Meta metaTCQuay `json:"meta"`
	}
	if err := json.Unmarshal([]byte(res.than), &body); err != nil {
		t.Fatalf("không đọc được phản hồi: %v\n%s", err, catBot(res.than))
	}

	return body.Data, body.Meta
}

func lapTCQuay(t *testing.T, h *heThong, token string, than map[string]any) (traLoi, phieuTC) {
	t.Helper()

	res := h.goi(t, token, http.MethodPost, duongTCQuay, voiLoaiTC(t, h, token, than))
	var body struct {
		Data phieuTC `json:"data"`
	}
	_ = json.Unmarshal([]byte(res.than), &body)

	return res, body.Data
}

func maLoi(t *testing.T, res traLoi) string {
	t.Helper()

	var body struct {
		Errors struct {
			Ma string `json:"ma"`
		} `json:"errors"`
	}
	_ = json.Unmarshal([]byte(res.than), &body)

	return body.Errors.Ma
}

// TestThuChiQuay_ChuaMoCaThiChanGhi — luật 14/10: chưa mở ca thì mọi thu chi ở
// quầy dừng lại. Đọc thì vẫn được, và `ca_dang_mo` = null để màn khoá nút lập.
func TestThuChiQuay_ChuaMoCaThiChanGhi(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	thuNgan := h.dangNhapVoi(t, a.ma, "nhanvien")

	res, _ := lapTCQuay(t, h, thuNgan, chiTienMat(50000))
	if res.ma != http.StatusConflict || maLoi(t, res) != "CHUA_MO_CA" {
		t.Fatalf("chưa mở ca: lập phiếu ở quầy phải 409 CHUA_MO_CA, nhận %d\n%s", res.ma, catBot(res.than))
	}

	// Màn quản trị KHÔNG theo luật này — phiếu ngoài ca vẫn lập được ở đó.
	if ma, _ := lapPhieuTC(t, h, a.token, chiTienMat(50000)); ma != http.StatusCreated {
		t.Fatalf("màn quản trị lập phiếu khi chưa mở ca phải 201, nhận %d", ma)
	}

	_, meta := docTCQuay(t, h, thuNgan, "")
	if meta.CaDangMo != nil {
		t.Fatalf("chưa mở ca thì ca_dang_mo phải null, nhận %d", *meta.CaDangMo)
	}
	if !meta.CoTheLap {
		t.Fatal("thu ngân có so-quy.them thì co_the_lap phải true")
	}

	moCa(t, h, a, 0)
	res, p := lapTCQuay(t, h, thuNgan, chiTienMat(50000))
	if res.ma != http.StatusCreated {
		t.Fatalf("đã mở ca: lập phiếu ở quầy phải 201, nhận %d\n%s", res.ma, catBot(res.than))
	}
	if p.ShiftID == nil || p.Source != "manual" {
		t.Fatalf("phiếu quầy phải mang ca đang mở và nguồn manual, nhận %+v", p)
	}

	// Đóng ca rồi thì sửa / xoá cũng phải chặn ở bước ca (trước cả lớp khoá phiếu).
	dongCaNgay(t, h, a)
	duong := fmt.Sprintf("%s/%d", duongTCQuay, p.ID)
	for _, method := range []string{http.MethodPut, http.MethodDelete} {
		var than map[string]any
		if method == http.MethodPut {
			than = voiLoaiTC(t, h, thuNgan, chiTienMat(60000))
		}
		if r := h.goi(t, thuNgan, method, duong, than); r.ma != http.StatusConflict || maLoi(t, r) != "CHUA_MO_CA" {
			t.Fatalf("%s khi chưa mở ca phải 409 CHUA_MO_CA, nhận %d\n%s", method, r.ma, catBot(r.than))
		}
	}
}

// TestThuChiQuay_ChiThayPhieuMinhLapTay — sổ quầy không phải sổ cửa hàng: phiếu
// của chủ tiệm và phiếu tự sinh không lọt vào, kể cả khi trình duyệt gửi
// created_by / source khác.
func TestThuChiQuay_ChiThayPhieuMinhLapTay(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	moCa(t, h, a, 0)
	thuNgan := h.dangNhapVoi(t, a.ma, "nhanvien")

	lapPhieuTC(t, h, a.token, thuTienMat(700000))
	_, cuaToi := lapTCQuay(t, h, thuNgan, chiTienMat(40000))

	ds, meta := docTCQuay(t, h, thuNgan, fmt.Sprintf("created_by=%d&source=order", a.quanTri))
	if len(ds) != 1 || ds[0].ID != cuaToi.ID || meta.Total != 1 {
		t.Fatalf("thu ngân chỉ được thấy đúng 1 phiếu của mình, nhận %d dòng (total %d)", len(ds), meta.Total)
	}
	if meta.TongThu != 0 || meta.TongChi != 40000 {
		t.Fatalf("tổng phải tính trên phiếu của mình: thu 0 / chi 40000, nhận %v / %v", meta.TongThu, meta.TongChi)
	}

	dsQT, _ := docTCQuay(t, h, a.token, "")
	if len(dsQT) != 1 || dsQT[0].Amount != 700000 {
		t.Fatalf("chủ tiệm mở màn quầy cũng chỉ thấy phiếu của chính mình, nhận %+v", dsQT)
	}
}

// TestThuChiQuay_TongTienMatVaLoc — ba con số đầu màn và hai ô lọc riêng của quầy.
func TestThuChiQuay_TongTienMatVaLoc(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	ca := moCa(t, h, a, 0)
	caID := uint(ca["id"].(float64))
	thuNgan := h.dangNhapVoi(t, a.ma, "nhanvien")

	resNop := h.goi(t, thuNgan, http.MethodPost, "/api/v1/admin/quay/nguoi-nop-thu-chi",
		map[string]any{"name": "Anh Tú xe ôm", "phone": "0987112233"})
	if resNop.ma != http.StatusCreated {
		t.Fatalf("thêm người nộp ở quầy phải 201, nhận %d\n%s", resNop.ma, catBot(resNop.than))
	}
	nop := uint(doc(t, resNop)["id"].(float64))

	lapTCQuay(t, h, thuNgan, map[string]any{"type": 0, "amount": 300000, "payment_method": "cash", "note": "Khách đặt cọc"})
	lapTCQuay(t, h, thuNgan, map[string]any{
		"type": 1, "amount": 120000, "payment_method": "cash", "note": "Trả ship",
		"payer_type": "other", "payer_id": nop,
	})
	lapTCQuay(t, h, thuNgan, map[string]any{"type": 0, "amount": 900000, "payment_method": "transfer", "note": "Thanh lý kệ"})

	_, meta := docTCQuay(t, h, thuNgan, fmt.Sprintf("shift_id=%d", caID))
	if meta.Total != 3 || meta.TongThu != 1200000 || meta.TongChi != 120000 {
		t.Fatalf("ca này: 3 phiếu, thu 1.200.000, chi 120.000 — nhận %d / %v / %v", meta.Total, meta.TongThu, meta.TongChi)
	}
	if meta.TienMatThu != 300000 || meta.TienMatChi != 120000 {
		t.Fatalf("tiền mặt qua két: thu 300.000, chi 120.000 (chuyển khoản không tính) — nhận %v / %v", meta.TienMatThu, meta.TienMatChi)
	}
	if meta.SoThu != 2 || meta.SoChi != 1 {
		t.Fatalf("số phiếu: 2 thu, 1 chi — nhận %d / %d", meta.SoThu, meta.SoChi)
	}
	if meta.CaDangMo == nil || *meta.CaDangMo != caID {
		t.Fatalf("ca_dang_mo phải là %d, nhận %v", caID, meta.CaDangMo)
	}

	if ds, _ := docTCQuay(t, h, thuNgan, fmt.Sprintf("shift_id=%d", caID+999)); len(ds) != 0 {
		t.Fatalf("lọc ca khác phải ra rỗng, nhận %d dòng", len(ds))
	}
	for q, can := range map[string]int{"ship": 1, "Anh%20T%C3%BA": 1, "0987112": 1, "khongcogi": 0} {
		if ds, _ := docTCQuay(t, h, thuNgan, "q="+q); len(ds) != can {
			t.Fatalf("tìm %q phải ra %d phiếu, nhận %d", q, can, len(ds))
		}
	}
	if ds, _ := docTCQuay(t, h, thuNgan, "type=1"); len(ds) != 1 {
		t.Fatalf("tab Phiếu chi phải ra 1 phiếu, nhận %d", len(ds))
	}
}

// TestThuChiQuay_DanhMuc — một lượt đủ ba danh mục; nhân viên mang payer_type
// theo cửa vào, kể cả tài khoản cũ có cột access_areas rỗng.
func TestThuChiQuay_DanhMuc(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	thuNgan := h.dangNhapVoi(t, a.ma, "nhanvien")

	res := h.goi(t, thuNgan, http.MethodGet, duongTCQuay+"/danh-muc", nil)
	if res.ma != http.StatusOK {
		t.Fatalf("danh mục quầy phải 200, nhận %d\n%s", res.ma, catBot(res.than))
	}
	var body struct {
		Data struct {
			PhanLoai []struct {
				ID   uint  `json:"id"`
				Type uint8 `json:"type"`
			} `json:"phan_loai"`
			NhanVien []struct {
				ID        uint   `json:"id"`
				PayerType string `json:"payer_type"`
			} `json:"nhan_vien"`
			NguoiNop []any `json:"nguoi_nop"`
		} `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &body); err != nil {
		t.Fatalf("không đọc được danh mục: %v", err)
	}

	vai := map[uint]string{}
	for _, nv := range body.Data.NhanVien {
		vai[nv.ID] = nv.PayerType
	}
	if vai[a.nhanVien] != "thu_ngan" || vai[a.quanTri] != "quan_ly" {
		t.Fatalf("nhân viên → thu_ngan, quản trị → quan_ly; nhận %v", vai)
	}
	if _, co := vai[a.khach]; co {
		t.Fatal("tài khoản khách hàng không được lọt vào danh sách nhân viên")
	}
	if body.Data.NguoiNop == nil {
		t.Fatal("nguoi_nop phải là mảng (rỗng cũng được), không phải null")
	}
}

// TestThuChiQuay_ThieuQuyenGhiThiChiXem — thu ngân bị bỏ `so-quy.them`: vẫn mở
// được màn (đọc theo ca-lam-viec.xem) nhưng co_the_lap = false và lượt ghi 403.
func TestThuChiQuay_ThieuQuyenGhiThiChiXem(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	moCa(t, h, a, 0)

	ctx := tenant.WithID(context.Background(), a.id)
	if err := h.db.WithContext(ctx).Where("user_id = ? AND permission = ?", a.nhanVien, "so-quy.them").
		Delete(&domain.QuyenRieng{}).Error; err != nil {
		t.Fatalf("không thu được quyền so-quy.them: %v", err)
	}
	thuNgan := h.dangNhapVoi(t, a.ma, "nhanvien")

	_, meta := docTCQuay(t, h, thuNgan, "")
	if meta.CoTheLap {
		t.Fatal("thiếu so-quy.them thì co_the_lap phải false")
	}
	if res, _ := lapTCQuay(t, h, thuNgan, chiTienMat(10000)); res.ma != http.StatusForbidden {
		t.Fatalf("thiếu so-quy.them: lập phiếu phải 403, nhận %d", res.ma)
	}
	if res := h.goi(t, thuNgan, http.MethodGet, duongTCQuay+"/danh-muc", nil); res.ma != http.StatusForbidden {
		t.Fatalf("thiếu so-quy.them: danh mục phải 403, nhận %d", res.ma)
	}
}
