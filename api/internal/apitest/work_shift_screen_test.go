package apitest

import (
	"encoding/json"
	"fmt"
	"net/http"
	"testing"
)

// Màn Điều phối ca của quầy — những gì màn đó đọc thêm ngoài danh sách ca:
// mã chứng từ + tên người ghi trên từng dòng sổ, doanh thu ca theo hình thức,
// tiền mặt ngoài ca hôm nay, lọc người trực / lệch két, danh sách người trực.

type dongSoQuy struct {
	Direction     string  `json:"direction"`
	Amount        float64 `json:"amount"`
	ReferenceType string  `json:"reference_type"`
	ReferenceCode string  `json:"reference_code"`
	CreatedName   string  `json:"created_by_name"`
}

func docJSON(t *testing.T, res traLoi, dich any) {
	t.Helper()
	if res.ma != http.StatusOK && res.ma != http.StatusCreated {
		t.Fatalf("trả %d\n%s", res.ma, catBot(res.than))
	}
	if err := json.Unmarshal([]byte(res.than), dich); err != nil {
		t.Fatalf("không đọc được JSON: %v\n%s", err, catBot(res.than))
	}
}

func TestManCa_SoQuyDoanhThuNgoaiCaVaBoLoc(t *testing.T) {
	h := dungHeThong(t)
	a, b := haiCuaHang(t, h)

	// Dòng tiền mặt không thuộc ca nào (dữ liệu từ trước khi chặn bán ngoài ca).
	banQuayNgoaiCa(t, h, a, "cash", 1) // 90.000

	var ngoai struct{ Data []dongSoQuy }
	docJSON(t, h.goi(t, a.token, http.MethodGet, "/api/v1/admin/ca-lam-viec/ngoai-ca", nil), &ngoai)
	if len(ngoai.Data) != 1 || ngoai.Data[0].ReferenceType != "order" || ngoai.Data[0].ReferenceCode == "" || ngoai.Data[0].Amount != 90000 {
		t.Fatalf("ngoài ca phải có đúng 1 đơn 90000 kèm mã đơn, đang là %+v", ngoai.Data)
	}

	var mo struct{ Data struct{ ID uint } }
	docJSON(t, h.goi(t, a.token, http.MethodPost, "/api/v1/admin/ca-lam-viec/mo", map[string]any{"opening_cash": 500000}), &mo)

	banQuay(t, h, a, "cash", 1)          // 90.000 tiền mặt → vào sổ
	banQuay(t, h, a, "bank_transfer", 2) // 180.000 chuyển khoản → không vào sổ
	if res := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/so-quy",
		map[string]any{"direction": "out", "amount": 20000, "reason": "Mua nước"}); res.ma != http.StatusCreated {
		t.Fatalf("ghi tay trả %d\n%s", res.ma, catBot(res.than))
	}

	var ct struct {
		Data struct {
			SoQuy    []dongSoQuy `json:"so_quy"`
			DoanhThu struct {
				OrderCount    int64   `json:"order_count"`
				TotalCash     float64 `json:"total_cash"`
				TotalTransfer float64 `json:"total_transfer"`
				TotalRevenue  float64 `json:"total_revenue"`
			} `json:"doanh_thu"`
		}
	}
	docJSON(t, h.goi(t, a.token, http.MethodGet, fmt.Sprintf("/api/v1/admin/ca-lam-viec/%d", mo.Data.ID), nil), &ct)
	if len(ct.Data.SoQuy) != 2 {
		t.Fatalf("sổ ca phải có 2 dòng (đơn tiền mặt + ghi tay), đang có %+v", ct.Data.SoQuy)
	}
	don, tay := ct.Data.SoQuy[0], ct.Data.SoQuy[1]
	if don.ReferenceType != "order" || don.ReferenceCode == "" || don.ReferenceCode == ngoai.Data[0].ReferenceCode {
		t.Errorf("dòng bán phải mang mã đơn của chính nó, đang là %+v", don)
	}
	if tay.ReferenceType != "manual" || tay.CreatedName == "" || tay.ReferenceCode != "" {
		t.Errorf("dòng ghi tay phải có tên người ghi, không có mã, đang là %+v", tay)
	}
	dt := ct.Data.DoanhThu
	if dt.OrderCount != 2 || dt.TotalCash != 90000 || dt.TotalTransfer != 180000 || dt.TotalRevenue != 270000 {
		t.Errorf("doanh thu ca phải là 2 đơn / 90000 TM / 180000 CK / 270000, đang là %+v", dt)
	}

	// Theo sổ 500.000 + 90.000 − 20.000 = 570.000; đếm 560.000 → lệch.
	if res := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/ca-lam-viec/dong",
		map[string]any{"counted_cash": 560000, "note": "Thiếu 10 nghìn"}); res.ma != http.StatusOK {
		t.Fatalf("đóng ca trả %d\n%s", res.ma, catBot(res.than))
	}
	docJSON(t, h.goi(t, a.token, http.MethodPost, "/api/v1/admin/ca-lam-viec/mo", map[string]any{"opening_cash": 560000}), &mo)
	if res := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/ca-lam-viec/dong",
		map[string]any{"counted_cash": 560000}); res.ma != http.StatusOK {
		t.Fatalf("đóng ca thứ hai trả %d\n%s", res.ma, catBot(res.than))
	}

	dem := func(query string) int64 {
		t.Helper()
		var ds struct {
			Meta struct{ Total int64 }
		}
		docJSON(t, h.goi(t, a.token, http.MethodGet, "/api/v1/admin/ca-lam-viec?status=da_dong"+query, nil), &ds)
		return ds.Meta.Total
	}
	if n := dem(""); n != 2 {
		t.Errorf("phải có 2 ca đã đóng, đang có %d", n)
	}
	if n := dem("&lech=1"); n != 1 {
		t.Errorf("lọc lệch két phải ra 1 ca, đang ra %d", n)
	}
	if n := dem(fmt.Sprintf("&opened_by=%d", a.quanTri)); n != 2 {
		t.Errorf("lọc theo người mở phải ra 2 ca, đang ra %d", n)
	}
	if n := dem("&opened_by=999999"); n != 0 {
		t.Errorf("người chưa từng mở ca phải ra 0, đang ra %d", n)
	}

	var nguoi struct {
		Data []struct {
			ID   uint
			Name string
		}
	}
	docJSON(t, h.goi(t, a.token, http.MethodGet, "/api/v1/admin/ca-lam-viec/nguoi-truc", nil), &nguoi)
	if len(nguoi.Data) != 1 || nguoi.Data[0].ID != a.quanTri || nguoi.Data[0].Name == "" {
		t.Errorf("người trực phải là đúng 1 người đã mở ca, đang là %+v", nguoi.Data)
	}

	// Cửa hàng khác không thấy gì của cửa hàng này.
	docJSON(t, h.goi(t, b.token, http.MethodGet, "/api/v1/admin/ca-lam-viec/ngoai-ca", nil), &ngoai)
	docJSON(t, h.goi(t, b.token, http.MethodGet, "/api/v1/admin/ca-lam-viec/nguoi-truc", nil), &nguoi)
	if len(ngoai.Data) != 0 || len(nguoi.Data) != 0 {
		t.Errorf("cửa hàng B thấy dữ liệu của A: ngoài ca %d dòng, người trực %d", len(ngoai.Data), len(nguoi.Data))
	}
}
