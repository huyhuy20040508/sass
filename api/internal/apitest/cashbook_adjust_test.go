package apitest

import (
	"encoding/json"
	"fmt"
	"net/http"
	"strings"
	"testing"
)

// Phiếu điều chỉnh (KB #18): phiếu tay của ca đã đóng không sửa được, lập phiếu
// MỚI vào ca đang mở trỏ adjusts_id về phiếu gốc; ca cũ không đổi một con số.

type caSo struct {
	TongThu      float64  `json:"tong_thu"`
	TongChi      float64  `json:"tong_chi"`
	ExpectedCash *float64 `json:"expected_cash"`
	Difference   *float64 `json:"difference"`
}

func docCaSo(t *testing.T, h *heThong, tok string, id uint) caSo {
	t.Helper()
	var body struct {
		Data struct {
			Ca caSo `json:"ca"`
		} `json:"data"`
	}
	docJSON(t, h.goi(t, tok, http.MethodGet, fmt.Sprintf("/api/v1/admin/ca-lam-viec/%d", id), nil), &body)
	return body.Data.Ca
}

// KB DC01–DC07
func TestThuChi_PhieuDieuChinh(t *testing.T) {
	h := dungHeThong(t)
	a, b := haiCuaHang(t, h)
	caCu := uint(moCa(t, h, a, 0)["id"].(float64))
	thuNgan := h.dangNhapVoi(t, a.ma, "nhanvien")
	_, goc := lapTCQuay(t, h, thuNgan, chiTienMat(2000000))
	loaiChi := voiLoaiTC(t, h, thuNgan, map[string]any{"type": 1})["category_id"] // phân loại chi của phiếu gốc
	_, conMo := lapTCQuay(t, h, thuNgan, chiTienMat(10000))                       // cùng ca, sẽ khoá theo
	dongCaNgay(t, h, a)
	truoc := docCaSo(t, h, a.token, caCu)
	caMoi := uint(moCa(t, h, a, 0)["id"].(float64))
	_, phieuCaMoi := lapTCQuay(t, h, thuNgan, chiTienMat(5000))

	// DC01 phiếu của ca đã đóng: khoá + can_adjust; phiếu ca đang mở thì không.
	ds, _ := docTCQuay(t, h, thuNgan, fmt.Sprintf("shift_id=%d", caCu))
	if len(ds) != 2 || !ds[0].Locked {
		t.Fatalf("DC01 phiếu ca đã đóng phải khoá, nhận %+v", ds)
	}
	r := h.goi(t, thuNgan, http.MethodGet, fmt.Sprintf("%s?shift_id=%d", duongTCQuay, caCu), nil)
	if strings.Count(r.than, `"can_adjust":true`) != 2 {
		t.Fatalf("DC01 hai phiếu ca đã đóng phải can_adjust=true: %s", catBot(r.than))
	}
	if r := h.goi(t, thuNgan, http.MethodGet, fmt.Sprintf("%s?shift_id=%d", duongTCQuay, caMoi), nil); !strings.Contains(r.than, `"can_adjust":false`) {
		t.Fatalf("DC01 phiếu ca đang mở không điều chỉnh, nhận %s", catBot(r.than))
	}

	// DC02 lập điều chỉnh: vế ĐẢO (thu 1.800.000), giữ đúng phân loại chi của phiếu gốc.
	than := voiLoaiTC(t, h, thuNgan, thuTienMat(1800000))
	than["category_id"] = loaiChi
	than["note"] = "Điều chỉnh (chi 2.000.000 → đúng 200.000)"
	than["adjusts_id"] = goc.ID
	r, dc := lapTCQuay(t, h, thuNgan, than)
	var tra struct {
		Data struct {
			ShiftID     uint   `json:"shift_id"`
			AdjustsID   uint   `json:"adjusts_id"`
			AdjustsCode string `json:"adjusts_code"`
		} `json:"data"`
	}
	_ = json.Unmarshal([]byte(r.than), &tra)
	if r.ma != http.StatusCreated || tra.Data.ShiftID != caMoi || tra.Data.AdjustsID != goc.ID || tra.Data.AdjustsCode != goc.Code {
		t.Fatalf("DC02 phiếu điều chỉnh phải vào ca mới %d và trỏ về %d (%s), nhận %d %s", caMoi, goc.ID, goc.Code, r.ma, catBot(r.than))
	}
	// Không phải lượt điều chỉnh thì phân loại sai vế vẫn bị chặn như cũ.
	sai := voiLoaiTC(t, h, thuNgan, thuTienMat(1000))
	sai["category_id"] = loaiChi
	if r, _ := lapTCQuay(t, h, thuNgan, sai); r.ma != http.StatusUnprocessableEntity || !strings.Contains(r.than, "category_id") {
		t.Fatalf("DC02 phiếu thường dùng phân loại sai vế phải 422, nhận %d", r.ma)
	}

	// DC03 hai chiều: phiếu gốc liệt kê phiếu điều chỉnh.
	if r := h.goi(t, thuNgan, http.MethodGet, fmt.Sprintf("%s/%d", duongTCQuay, goc.ID), nil); !strings.Contains(r.than, fmt.Sprintf(`"adjusted_by":[{"id":%d,"code":"%s"}]`, dc.ID, dc.Code)) {
		t.Fatalf("DC03 phiếu gốc phải kèm adjusted_by → %d, nhận %s", dc.ID, catBot(r.than))
	}

	// DC04 ca cũ không đổi một con số.
	if sau := docCaSo(t, h, a.token, caCu); sau.TongThu != truoc.TongThu || sau.TongChi != truoc.TongChi || *sau.ExpectedCash != *truoc.ExpectedCash || *sau.Difference != *truoc.Difference {
		t.Fatalf("DC04 ca cũ phải giữ nguyên số: trước %+v, sau %+v", truoc, sau)
	}

	// DC05 không điều chỉnh được: phiếu ca đang mở, id lạ, phiếu của cửa hàng khác.
	_, cuaB := lapPhieuTC(t, h, b.token, chiTienMat(1000))
	for _, c := range []struct {
		ten string
		id  uint
		cau string
	}{
		{"phiếu ca đang mở", phieuCaMoi.ID, "chưa khoá"},
		{"id không tồn tại", 99999999, "không tồn tại"},
		{"cửa hàng khác", cuaB.ID, "không tồn tại"},
	} {
		x := voiLoaiTC(t, h, thuNgan, thuTienMat(1000))
		x["adjusts_id"] = c.id
		if r, _ := lapTCQuay(t, h, thuNgan, x); r.ma != http.StatusUnprocessableEntity || !strings.Contains(r.than, c.cau) {
			t.Fatalf("DC05 %s phải 422 %q, nhận %d %s", c.ten, c.cau, r.ma, catBot(r.than))
		}
	}

	// DC06 màn quản trị cũng lập được điều chỉnh (cùng vế khi số đúng lớn hơn).
	x := voiLoaiTC(t, h, a.token, chiTienMat(15000))
	x["adjusts_id"] = conMo.ID
	if code, p := lapPhieuTC(t, h, a.token, x); code != http.StatusCreated {
		t.Fatalf("DC06 màn quản trị lập điều chỉnh phải 201, nhận %d", code)
	} else if r := h.goi(t, a.token, http.MethodGet, fmt.Sprintf("/api/v1/admin/thu-chi/%d", conMo.ID), nil); !strings.Contains(r.than, fmt.Sprintf(`{"id":%d,`, p.ID)) {
		t.Fatalf("DC06 phiếu gốc phải liệt kê phiếu điều chỉnh của màn quản trị: %s", catBot(r.than))
	}

	// DC07 phiếu điều chỉnh vẫn sửa được (đang ở ca mở), liên kết giữ nguyên.
	sua := voiLoaiTC(t, h, thuNgan, thuTienMat(1700000))
	sua["category_id"] = loaiChi
	sua["adjusts_id"] = 12345 // gửi kèm cũng không đổi được liên kết
	if r := h.goi(t, thuNgan, http.MethodPut, fmt.Sprintf("%s/%d", duongTCQuay, dc.ID), sua); r.ma != http.StatusOK || !strings.Contains(r.than, fmt.Sprintf(`"adjusts_id":%d`, goc.ID)) {
		t.Fatalf("DC07 sửa phiếu điều chỉnh phải 200, giữ adjusts_id=%d; nhận %d %s", goc.ID, r.ma, catBot(r.than))
	}
}
