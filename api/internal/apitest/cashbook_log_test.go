package apitest

import (
	"encoding/json"
	"fmt"
	"net/http"
	"strings"
	"testing"
)

// Nhật ký sửa / xoá phiếu thu chi (KB #17): bắt buộc lý do, ghi activity_logs,
// phiếu trả last_edit, đường đọc nhật ký, chi tiết ca liệt kê phiếu bị sửa / xoá.

type nhatKyTra struct {
	PhieuID  uint   `json:"phieu_id"`
	HanhDong string `json:"action"`
	NguoiSua string `json:"by_name"`
	LyDo     string `json:"reason"`
	ThayDoi  struct {
		Ma         string   `json:"ma"`
		Ca         *uint    `json:"ca"`
		SoTienCu   float64  `json:"so_tien_cu"`
		SoTienMoi  *float64 `json:"so_tien_moi"`
		PhanLoaiCu string   `json:"phan_loai_cu"`
		MoTaMoi    *string  `json:"mo_ta_moi"`
	} `json:"changes"`
}

func docNhatKyTC(t *testing.T, r traLoi) []nhatKyTra {
	t.Helper()
	var body struct {
		Data []nhatKyTra `json:"data"`
	}
	if r.ma != http.StatusOK || json.Unmarshal([]byte(r.than), &body) != nil {
		t.Fatalf("đọc nhật ký phải 200, nhận %d %s", r.ma, catBot(r.than))
	}
	return body.Data
}

// KB NK01–NK08
func TestThuChi_NhatKySuaXoa(t *testing.T) {
	h := dungHeThong(t)
	a, b := haiCuaHang(t, h)
	ca := uint(moCa(t, h, a, 0)["id"].(float64))
	thuNgan := h.dangNhapVoi(t, a.ma, "nhanvien")
	_, p := lapTCQuay(t, h, thuNgan, chiTienMat(200000))
	duongQ := fmt.Sprintf("%s/%d", duongTCQuay, p.ID)

	// NK01 sửa không lý do → 422 ô reason; phiếu giữ nguyên.
	than := voiLoaiTC(t, h, thuNgan, chiTienMat(320000))
	delete(than, "reason")
	if r := h.goi(t, thuNgan, http.MethodPut, duongQ, than); r.ma != http.StatusUnprocessableEntity || !strings.Contains(r.than, `"reason":"Ghi lý do sửa phiếu."`) {
		t.Fatalf("NK01 sửa thiếu lý do phải 422 reason, nhận %d %s", r.ma, catBot(r.than))
	}
	// NK02 sửa có lý do → 200, last_edit 200.000 → 320.000.
	than["reason"] = "Gõ thiếu một số 0"
	if r := h.goi(t, thuNgan, http.MethodPut, duongQ, than); r.ma != http.StatusOK {
		t.Fatalf("NK02 sửa có lý do phải 200, nhận %d %s", r.ma, catBot(r.than))
	}
	r := h.goi(t, thuNgan, http.MethodGet, fmt.Sprintf("%s?shift_id=%d", duongTCQuay, ca), nil)
	if !strings.Contains(r.than, `"last_edit":{"at":`) || !strings.Contains(r.than, `"amount_old":200000,"amount_new":320000,"reason":"Gõ thiếu một số 0"`) {
		t.Fatalf("NK02 danh sách phải kèm last_edit 200000 → 320000: %s", catBot(r.than))
	}

	// NK03 nhật ký ở quầy: đủ số cũ / mới, ca, tên phân loại, người sửa.
	ds := docNhatKyTC(t, h.goi(t, thuNgan, http.MethodGet, duongQ+"/nhat-ky", nil))
	if len(ds) != 1 || ds[0].HanhDong != "thu_chi.sua" || ds[0].ThayDoi.SoTienCu != 200000 || ds[0].ThayDoi.SoTienMoi == nil || *ds[0].ThayDoi.SoTienMoi != 320000 ||
		ds[0].ThayDoi.Ca == nil || *ds[0].ThayDoi.Ca != ca || ds[0].ThayDoi.PhanLoaiCu != "Phân loại thử" || ds[0].NguoiSua == "" || ds[0].LyDo != "Gõ thiếu một số 0" {
		t.Fatalf("NK03 nhật ký sai: %+v", ds)
	}

	// NK04 chủ sửa ở màn quản trị → dòng thứ hai, mới nhất trước.
	if r := h.goi(t, a.token, http.MethodPut, fmt.Sprintf("/api/v1/admin/thu-chi/%d", p.ID), voiLoaiTC(t, h, a.token, map[string]any{"type": 1, "amount": 320000, "payment_method": "cash", "note": "Mô tả mới", "reason": "Ghi rõ mô tả"})); r.ma != http.StatusOK {
		t.Fatalf("NK04 chủ sửa phải 200, nhận %d %s", r.ma, catBot(r.than))
	}
	ds = docNhatKyTC(t, h.goi(t, a.token, http.MethodGet, fmt.Sprintf("/api/v1/admin/thu-chi/%d/nhat-ky", p.ID), nil))
	if len(ds) != 2 || ds[0].LyDo != "Ghi rõ mô tả" || ds[0].ThayDoi.MoTaMoi == nil || *ds[0].ThayDoi.MoTaMoi != "Mô tả mới" {
		t.Fatalf("NK04 nhật ký phải 2 dòng, mới nhất là lượt của chủ: %+v", ds)
	}
	// NK05 cửa hàng khác không đọc được nhật ký.
	if r := h.goi(t, b.token, http.MethodGet, fmt.Sprintf("/api/v1/admin/thu-chi/%d/nhat-ky", p.ID), nil); r.ma != http.StatusNotFound {
		t.Fatalf("NK05 cửa hàng khác phải 404, nhận %d", r.ma)
	}

	// NK06 xoá không lý do → 422; có lý do (thân JSON) → 200.
	if r := h.goi(t, thuNgan, http.MethodDelete, duongQ, nil); r.ma != http.StatusUnprocessableEntity || !strings.Contains(r.than, "Ghi lý do xoá phiếu.") {
		t.Fatalf("NK06 xoá thiếu lý do phải 422, nhận %d %s", r.ma, catBot(r.than))
	}
	if r := h.goi(t, thuNgan, http.MethodDelete, duongQ, map[string]any{"reason": "Lập trùng"}); r.ma != http.StatusOK {
		t.Fatalf("NK06 xoá có lý do phải 200, nhận %d %s", r.ma, catBot(r.than))
	}
	// NK07 lý do qua ?reason= cũng nhận.
	_, p2 := lapTCQuay(t, h, thuNgan, thuTienMat(50000))
	if r := h.goi(t, thuNgan, http.MethodDelete, fmt.Sprintf("%s/%d?reason=%s", duongTCQuay, p2.ID, "L%E1%BA%ADp%20nh%E1%BA%A7m"), nil); r.ma != http.StatusOK {
		t.Fatalf("NK07 xoá với ?reason= phải 200, nhận %d %s", r.ma, catBot(r.than))
	}

	// NK08 chi tiết ca liệt kê đủ 4 lượt (2 sửa + 2 xoá), mới nhất trước; DB ghi đúng chi nhánh.
	var ct struct {
		Data struct {
			ThuChiSua []nhatKyTra `json:"thu_chi_sua"`
		} `json:"data"`
	}
	docJSON(t, h.goi(t, a.token, http.MethodGet, fmt.Sprintf("/api/v1/admin/ca-lam-viec/%d", ca), nil), &ct)
	hd := []string{}
	for _, n := range ct.Data.ThuChiSua {
		hd = append(hd, n.HanhDong+":"+n.LyDo)
	}
	if strings.Join(hd, "|") != "thu_chi.xoa:Lập nhầm|thu_chi.xoa:Lập trùng|thu_chi.sua:Ghi rõ mô tả|thu_chi.sua:Gõ thiếu một số 0" {
		t.Fatalf("NK08 chi tiết ca phải liệt kê 4 lượt sửa / xoá, nhận %v", hd)
	}
	var sai int64
	h.db.WithContext(ctxThoat()).Raw("SELECT COUNT(*) FROM activity_logs WHERE tenant_id = ? AND subject_type = 'income_expense' AND shop_id <> ?", a.id, a.chiNhanh).Scan(&sai)
	if sai != 0 {
		t.Fatalf("NK08 nhật ký phải ghi đúng chi nhánh của phiếu, %d dòng sai", sai)
	}
}
