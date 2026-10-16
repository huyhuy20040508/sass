package apitest

import (
	"encoding/json"
	"fmt"
	"math"
	"net/http"
	"strings"
	"testing"

	"sass-api/internal/domain"
)

// PHÍ QUẸT THẺ sau lượt bán: phiếu chi tự sinh, hộp chi tiết đơn, ba báo cáo.

type phieuPhiThe struct {
	ID       uint
	Code     string
	Type     int
	Amount   float64
	Method   string
	Note     string
	Category string
	ShiftID  *uint
}

func phieuPhiTheCua(t *testing.T, h *heThong, orderID uint) []phieuPhiThe {
	t.Helper()
	var ds []phieuPhiThe
	err := h.db.WithContext(ctxThoat()).Raw(`SELECT ie.id, ie.code, ie.type, ie.amount, ie.payment_method AS method, ie.note,
			COALESCE(t.name, '') AS category, ie.shift_id
		FROM income_expenses ie LEFT JOIN income_expense_types t ON t.id = ie.category_id
		WHERE ie.source = ? AND ie.source_id = ? AND ie.deleted_at IS NULL`, domain.ThuChiTuPhiThe, orderID).Scan(&ds).Error
	if err != nil {
		t.Fatalf("không đọc được phiếu phí thẻ: %v", err)
	}

	return ds
}

// batQuetThe dựng một máy quẹt thẻ độc lập phí `tyLe`% và bật Quẹt thẻ.
func batQuetThe(t *testing.T, h *heThong, c *cuaHang, tenMay, tyLe string) uint {
	t.Helper()
	may := idTra(t, h.goi(t, c.token, http.MethodPost, "/api/v1/admin/payment-devices", map[string]any{"code": "PT-" + tenMay + c.vet, "name": tenMay, "is_physical": true}))
	qt, _, _ := docPM(t, h, c.token).pt(5)
	ct := idTra(t, h.goi(t, c.token, http.MethodPost, fmt.Sprintf("/api/v1/admin/payment-methods/%d/details", qt), map[string]any{
		"detail_type": "physical_device", "device_id": may,
		"data": map[string]string{"card_fee_physical": tyLe, "bank_id": "970436", "bank_account": "0071", "bank_account_name": "A"},
	}))
	if r := h.goi(t, c.token, http.MethodPut, fmt.Sprintf("/api/v1/admin/payment-methods/%d/status", qt), map[string]any{"status": true}); r.ma != http.StatusOK {
		t.Fatalf("bật quẹt thẻ trả %d", r.ma)
	}

	return ct
}

// KB P01–P03 — bán quẹt thẻ: một phiếu chi đúng phí, chuyển khoản, đúng loại, khoá sửa xoá;
// tiền mặt không có phiếu; hộp chi tiết đơn có phí + máy + mã phiếu.
func TestPhiThe_PhieuChiVaChiTietDon(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	moCa(t, h, a, 0)
	// Cửa hàng thật được gieo sẵn loại này (0057 / lượt tạo cửa hàng); bộ test thì không.
	if r := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/loai-thu-chi", map[string]any{"type": 1, "name": domain.LoaiChiPhiThanhToan}); r.ma != http.StatusCreated {
		t.Fatalf("tạo loại chi trả %d %s", r.ma, catBot(r.than))
	}
	batQuetThe(t, h, a, "May VCB", "1.8")

	r, d := banQuayTT(t, h, a.token, a, map[string]any{"payment_method": "card"})
	if r.ma != http.StatusCreated || d.CardFee == 0 {
		t.Fatalf("bán quẹt thẻ phải 201 kèm phí, nhận %d %s", r.ma, catBot(r.than))
	}
	if want := math.Round(d.Total * 1.8 / 100); d.CardFee != want {
		t.Fatalf("phí phải %.0f, nhận %.0f", want, d.CardFee)
	}

	ds := phieuPhiTheCua(t, h, d.OrderID)
	if len(ds) != 1 {
		t.Fatalf("mỗi đơn quẹt thẻ đúng một phiếu phí, có %d", len(ds))
	}
	p := ds[0]
	if p.Type != int(domain.ThuChiPhieuChi) || p.Amount != d.CardFee || p.Method != domain.ThuChiChuyenKhoan {
		t.Fatalf("phiếu phí phải là phiếu chi %.0f chuyển khoản, nhận type=%d %.0f %s", d.CardFee, p.Type, p.Amount, p.Method)
	}
	if p.Category != domain.LoaiChiPhiThanhToan || !strings.Contains(p.Note, d.OrderCode) || !strings.Contains(p.Note, "May VCB") {
		t.Fatalf("phiếu phải thuộc loại phí thanh toán, mô tả có mã đơn + tên máy; nhận %q %q", p.Category, p.Note)
	}
	// Phiếu tự sinh: không sửa, không xoá.
	if r := h.goi(t, a.token, http.MethodDelete, fmt.Sprintf("/api/v1/admin/thu-chi/%d", p.ID), nil); r.ma < 400 {
		t.Fatalf("xoá phiếu phí thẻ phải bị chặn, nhận %d", r.ma)
	}

	// Hộp chi tiết đơn.
	r = h.goi(t, a.token, http.MethodGet, fmt.Sprintf("/api/v1/admin/orders/%d", d.OrderID), nil)
	var ct struct {
		Data struct {
			PhiThe *domain.PhiTheDon `json:"phi_the"`
		} `json:"data"`
	}
	_ = json.Unmarshal([]byte(r.than), &ct)
	if pt := ct.Data.PhiThe; pt == nil || pt.CardFee != d.CardFee || pt.TenMay != "May VCB" || pt.MaPhieuChi != p.Code {
		t.Fatalf("chi tiết đơn phải có phí %.0f, máy, mã phiếu %s; nhận %+v", d.CardFee, p.Code, ct.Data.PhiThe)
	}

	// Tiền mặt: không phí, không phiếu, không khối phi_the.
	_, dm := banQuayTT(t, h, a.token, a, map[string]any{"payment_method": "cash"})
	if len(phieuPhiTheCua(t, h, dm.OrderID)) != 0 {
		t.Fatalf("đơn tiền mặt không được có phiếu phí thẻ")
	}
	r = h.goi(t, a.token, http.MethodGet, fmt.Sprintf("/api/v1/admin/orders/%d", dm.OrderID), nil)
	if strings.Contains(r.than, `"phi_the"`) {
		t.Fatalf("đơn tiền mặt không được có phi_the: %s", catBot(r.than))
	}

	// Lọc sổ thu chi theo nguồn "card_fee": chỉ phiếu phí, kèm mã đơn gốc.
	r = h.goi(t, a.token, http.MethodGet, "/api/v1/admin/thu-chi?source=card_fee&from_date="+homNayDT()+"&to_date="+homNayDT(), nil)
	if r.ma != http.StatusOK || !strings.Contains(r.than, fmt.Sprintf(`"id":%d,`, p.ID)) || !strings.Contains(r.than, `"source_code":"`+d.OrderCode+`"`) {
		t.Fatalf("lọc nguồn card_fee phải ra phiếu %s trỏ về %s, nhận %d %s", p.Code, d.OrderCode, r.ma, catBot(r.than))
	}
	if strings.Contains(r.than, `"source":"order"`) {
		t.Fatalf("lọc card_fee không được lẫn phiếu bán hàng")
	}
}

// KB P04 — đơn QR đổi sang quẹt thẻ: phiếu phí ghi đúng một lần.
func TestPhiThe_DoiQRSangThe(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	moCa(t, h, a, 0)
	ketNoiSePay(t, h, a)
	ct := batQuetThe(t, h, a, "May 2", "2")

	_, d := banQuayTT(t, h, a.token, a, map[string]any{"payment_method": "sepay"})
	if len(phieuPhiTheCua(t, h, d.OrderID)) != 0 {
		t.Fatalf("đơn QR đang chờ không được có phiếu phí")
	}
	r := h.goi(t, a.token, http.MethodPost, fmt.Sprintf("/api/v1/admin/orders/pos/%d/doi-hinh-thuc", d.OrderID), map[string]any{"payment_method": "card", "payment_detail_id": ct})
	if r.ma != http.StatusOK {
		t.Fatalf("đổi sang quẹt thẻ phải 200, nhận %d %s", r.ma, catBot(r.than))
	}
	ds := phieuPhiTheCua(t, h, d.OrderID)
	if len(ds) != 1 || ds[0].Amount != math.Round(d.Total*2/100) {
		t.Fatalf("đổi sang thẻ phải có đúng một phiếu phí 2%%, nhận %+v", ds)
	}
}

// KB P05–P07 — báo cáo doanh thu (ngày + hoá đơn), tổng hợp, lợi nhuận đọc phí thẻ;
// lợi nhuận đang lọc mặt hàng thì không trừ phí.
func TestPhiThe_BaoCao(t *testing.T) {
	h := dungHeThong(t)
	a, b := haiCuaHang(t, h)
	moCa(t, h, a, 0)
	batQuetThe(t, h, a, "May A", "1.5")
	batQuetThe(t, h, b, "May B", "3")

	_, d1 := banQuayTT(t, h, a.token, a, map[string]any{"payment_method": "card"})
	_, d2 := banQuayTT(t, h, a.token, a, map[string]any{"payment_method": "card"})
	banQuayTT(t, h, a.token, a, map[string]any{"payment_method": "cash"})
	banQuayTT(t, h, b.token, b, map[string]any{"payment_method": "card"})
	phi := d1.CardFee + d2.CardFee
	if phi == 0 {
		t.Fatalf("hai đơn quẹt thẻ phải có phí")
	}

	hom := homNayDT()
	var dt struct {
		Data struct {
			Days []struct {
				Date    string
				CardFee float64 `json:"card_fee"`
				Paid    float64 `json:"paid"`
			} `json:"days"`
			Totals struct {
				CardFee float64 `json:"card_fee"`
				Paid    float64 `json:"paid"`
				Card    float64 `json:"card"`
			} `json:"totals"`
		} `json:"data"`
	}
	_ = json.Unmarshal([]byte(h.goi(t, a.token, http.MethodGet, "/api/v1/admin/reports/sales?from="+hom+"&to="+hom, nil).than), &dt)
	if dt.Data.Totals.CardFee != phi || len(dt.Data.Days) != 1 || dt.Data.Days[0].CardFee != phi {
		t.Fatalf("báo cáo doanh thu: phí thẻ phải %.0f (không lẫn cửa hàng B), nhận %+v", phi, dt.Data)
	}
	if dt.Data.Totals.Card != d1.Total+d2.Total {
		t.Fatalf("cột Quẹt thẻ không được trừ phí, nhận %.0f", dt.Data.Totals.Card)
	}

	var hd struct {
		Data []struct {
			Code    string
			CardFee float64 `json:"card_fee"`
		} `json:"data"`
	}
	_ = json.Unmarshal([]byte(h.goi(t, a.token, http.MethodGet, "/api/v1/admin/reports/sales/orders?date="+hom, nil).than), &hd)
	tong := 0.0
	for _, o := range hd.Data {
		tong += o.CardFee
		if o.Code == d1.OrderCode && o.CardFee != d1.CardFee {
			t.Fatalf("hoá đơn %s phải có phí %.0f, nhận %.0f", o.Code, d1.CardFee, o.CardFee)
		}
	}
	if tong != phi {
		t.Fatalf("cộng phí các hoá đơn trong ngày phải %.0f, nhận %.0f", phi, tong)
	}

	var th struct {
		Data struct {
			Totals struct {
				CardFee float64 `json:"card_fee"`
			} `json:"totals"`
			Cashbook struct {
				ExpenseCount int64   `json:"expense_count"`
				Expense      float64 `json:"expense"`
				CashExpense  float64 `json:"cash_expense"`
			} `json:"cashbook"`
		} `json:"data"`
	}
	_ = json.Unmarshal([]byte(h.goi(t, a.token, http.MethodGet, "/api/v1/admin/reports/summary?date="+hom, nil).than), &th)
	if th.Data.Totals.CardFee != phi || th.Data.Cashbook.ExpenseCount != 2 || th.Data.Cashbook.Expense != phi || th.Data.Cashbook.CashExpense != 0 {
		t.Fatalf("tổng hợp: phí %.0f, 2 phiếu chi không qua két; nhận %+v", phi, th.Data)
	}

	var ln struct {
		Data struct {
			CardFee float64 `json:"card_fee"`
		} `json:"data"`
	}
	_ = json.Unmarshal([]byte(h.goi(t, a.token, http.MethodGet, "/api/v1/admin/reports/profit?from="+hom+"&to="+hom, nil).than), &ln)
	if ln.Data.CardFee != phi {
		t.Fatalf("lợi nhuận phải trả phí thẻ %.0f, nhận %.0f", phi, ln.Data.CardFee)
	}
	ln.Data.CardFee = -1
	_ = json.Unmarshal([]byte(h.goi(t, a.token, http.MethodGet, "/api/v1/admin/reports/profit?from="+hom+"&to="+hom+"&keyword=xyz", nil).than), &ln)
	if ln.Data.CardFee != 0 {
		t.Fatalf("lợi nhuận đang lọc mặt hàng không được trừ phí của cả đơn, nhận %.0f", ln.Data.CardFee)
	}

	// Biểu đồ: phí thẻ theo mốc (ô tìm không chặn — biểu đồ vốn bỏ ô tìm), lọc nhóm thì 0.
	mocPhi := func(them string) float64 {
		var bd struct {
			Data struct {
				Chart []struct {
					Key     string  `json:"key"`
					CardFee float64 `json:"card_fee"`
				} `json:"chart"`
			} `json:"data"`
		}
		_ = json.Unmarshal([]byte(h.goi(t, a.token, http.MethodGet, "/api/v1/admin/reports/profit?from="+hom+"&to="+hom+"&group_by=day"+them, nil).than), &bd)
		for _, m := range bd.Data.Chart {
			if m.Key == hom {
				return m.CardFee
			}
		}
		t.Fatalf("biểu đồ thiếu mốc %s: %+v", hom, bd.Data.Chart)
		return 0
	}
	if got := mocPhi("&keyword=xyz"); got != phi {
		t.Fatalf("mốc hôm nay phải có phí thẻ %.0f, nhận %.0f", phi, got)
	}
	if got := mocPhi(fmt.Sprintf("&category_id=%d", a.danhMuc)); got != 0 {
		t.Fatalf("biểu đồ đang lọc nhóm hàng không được kèm phí thẻ, nhận %.0f", got)
	}
}
