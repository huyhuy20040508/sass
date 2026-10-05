package apitest

import (
	"encoding/json"
	"fmt"
	"net/http"
	"testing"
	"time"
)

// Báo cáo tổng hợp — GET /admin/reports/summary.
//
// Bám vào CON SỐ và vào sự KHỚP NHAU giữa các màn: phần thu/chi phải bằng đúng
// màn Thu chi của cùng ngày, phần bán hàng phải bằng đúng báo cáo doanh thu của
// cùng ngày. Lệch nhau là người xem không biết tin màn nào.

type baoCaoTongHop struct {
	Date     string `json:"date"`
	Cashbook struct {
		IncomeCount  int64   `json:"income_count"`
		ExpenseCount int64   `json:"expense_count"`
		Income       float64 `json:"income"`
		Expense      float64 `json:"expense"`
	} `json:"cashbook"`
	Totals struct {
		Orders  int64   `json:"orders"`
		Revenue float64 `json:"revenue"`
		Units   int64   `json:"units"`
	} `json:"totals"`
	ItemKinds       int64 `json:"item_kinds"`
	ByPaymentMethod []struct {
		Key     string  `json:"key"`
		Orders  int64   `json:"orders"`
		Revenue float64 `json:"revenue"`
	} `json:"by_payment_method"`
	ByHour []struct {
		Key     string  `json:"key"`
		Orders  int64   `json:"orders"`
		Revenue float64 `json:"revenue"`
	} `json:"by_hour"`
	Returns struct {
		Returns int64   `json:"returns"`
		Lines   int64   `json:"lines"`
		Units   int64   `json:"units"`
		Refund  float64 `json:"refund"`
	} `json:"returns"`
}

func docTongHop(t *testing.T, h *heThong, c *cuaHang, query string) baoCaoTongHop {
	t.Helper()

	res := h.goi(t, c.token, http.MethodGet, "/api/v1/admin/reports/summary"+query, nil)
	if res.ma != http.StatusOK {
		t.Fatalf("báo cáo tổng hợp trả %d\n%s", res.ma, catBot(res.than))
	}
	var out struct {
		Data baoCaoTongHop `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &out); err != nil {
		t.Fatalf("không đọc được báo cáo tổng hợp: %v\n%s", err, catBot(res.than))
	}
	return out.Data
}

// TestBaoCaoTongHop_SoLieuMotNgay — bán hai hình thức, lập phiếu thu chi, trả
// một món: từng khối của báo cáo ra đúng tiền.
func TestBaoCaoTongHop_SoLieuMotNgay(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	moCa(t, h, a, 0)

	// Cửa hàng mẫu đã có sẵn đơn web trong ngày — so PHẦN CHÊNH trước/sau.
	truoc := docTongHop(t, h, a, "")
	truocHT := map[string]float64{}
	for _, s := range truoc.ByPaymentMethod {
		truocHT[s.Key] = s.Revenue
	}

	banQuay(t, h, a, "cash", 2)          // 180.000 tiền mặt, 2 món
	banQuay(t, h, a, "bank_transfer", 1) // 90.000 chuyển khoản, 1 món

	if ma, _ := lapPhieuTC(t, h, a.token, thuTienMat(50000)); ma != http.StatusCreated {
		t.Fatalf("lập phiếu thu trả %d", ma)
	}
	if ma, _ := lapPhieuTC(t, h, a.token, chiTienMat(20000)); ma != http.StatusCreated {
		t.Fatalf("lập phiếu chi trả %d", ma)
	}

	bc := docTongHop(t, h, a, "")

	if bc.Date != time.Now().Format("2006-01-02") {
		t.Errorf("không khai ngày thì phải là hôm nay, đang là %q", bc.Date)
	}
	if d := bc.Totals.Orders - truoc.Totals.Orders; d != 2 {
		t.Errorf("số đơn phải tăng 2, đang tăng %d", d)
	}
	if d := bc.Totals.Revenue - truoc.Totals.Revenue; d != 270000 {
		t.Errorf("doanh thu phải tăng 270000, đang tăng %v", d)
	}
	if d := bc.Totals.Units - truoc.Totals.Units; d != 3 {
		t.Errorf("số món phải tăng 3, đang tăng %d", d)
	}
	if bc.ItemKinds < 1 {
		t.Errorf("phải có ít nhất 1 mặt hàng đã bán, đang là %d", bc.ItemKinds)
	}

	theoHinhThuc := map[string]float64{}
	for _, s := range bc.ByPaymentMethod {
		theoHinhThuc[s.Key] = s.Revenue
	}
	if theoHinhThuc["cash"]-truocHT["cash"] != 180000 || theoHinhThuc["bank_transfer"]-truocHT["bank_transfer"] != 90000 {
		t.Errorf("tiền mặt/chuyển khoản phải tăng 180000/90000, trước %v sau %v", truocHT, theoHinhThuc)
	}

	if len(bc.ByHour) != 24 {
		t.Fatalf("by_hour phải đủ 24 mốc, đang có %d", len(bc.ByHour))
	}
	// Cộng 24 mốc giờ phải ra đúng tổng cả ngày.
	var congGio float64
	for i, g := range bc.ByHour {
		if g.Key != fmt.Sprint(i) {
			t.Fatalf("mốc thứ %d phải có key %q, đang là %q", i, fmt.Sprint(i), g.Key)
		}
		congGio += g.Revenue
	}
	if congGio != bc.Totals.Revenue {
		t.Errorf("cộng 24 mốc giờ (%v) phải bằng doanh thu cả ngày (%v)", congGio, bc.Totals.Revenue)
	}

	// Phần bán hàng phải khớp báo cáo doanh thu của CÙNG ngày.
	hom0 := time.Now().Format("2006-01-02")
	dt := doc(t, h.goi(t, a.token, http.MethodGet,
		"/api/v1/admin/reports/revenue?from="+hom0+"&to="+hom0, nil))
	tongDT := dt["totals"].(map[string]any)
	if tongDT["revenue"].(float64) != bc.Totals.Revenue || int64(tongDT["orders"].(float64)) != bc.Totals.Orders {
		t.Errorf("báo cáo doanh thu cùng ngày ra %v đ / %v đơn, tổng hợp ra %v đ / %d đơn",
			tongDT["revenue"], tongDT["orders"], bc.Totals.Revenue, bc.Totals.Orders)
	}

	// Thu chi phải khớp ĐÚNG màn Thu chi của cùng ngày (kể cả phiếu tự sinh từ
	// đơn bán, nếu có).
	hom := time.Now().Format("2006-01-02")
	for _, loai := range []struct {
		ten      string
		typ      int
		soPhieu  int64
		soTien   float64
		truongMa string
	}{
		{"thu", 0, bc.Cashbook.IncomeCount, bc.Cashbook.Income, "total_income"},
		{"chi", 1, bc.Cashbook.ExpenseCount, bc.Cashbook.Expense, "total_expense"},
	} {
		res := h.goi(t, a.token, http.MethodGet,
			fmt.Sprintf("/api/v1/admin/thu-chi?type=%d&from_date=%s&to_date=%s", loai.typ, hom, hom), nil)
		var so struct {
			Meta map[string]any `json:"meta"`
		}
		_ = json.Unmarshal([]byte(res.than), &so)
		if int64(so.Meta["total"].(float64)) != loai.soPhieu || so.Meta[loai.truongMa].(float64) != loai.soTien {
			t.Errorf("phiếu %s: báo cáo %d phiếu / %v, màn Thu chi %v phiếu / %v",
				loai.ten, loai.soPhieu, loai.soTien, so.Meta["total"], so.Meta[loai.truongMa])
		}
	}
	if bc.Cashbook.ExpenseCount < 1 || bc.Cashbook.Expense < 20000 {
		t.Errorf("phải thấy ít nhất phiếu chi 20.000 vừa lập, đang là %d / %v",
			bc.Cashbook.ExpenseCount, bc.Cashbook.Expense)
	}
}

// TestBaoCaoTongHop_TraHang — chỉ phiếu trả đã nhận hàng / đã hoàn tiền mới tính.
func TestBaoCaoTongHop_TraHang(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	moCa(t, h, a, 0)

	res := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/orders/pos", map[string]any{
		"payment_method": "cash",
		"items":          []map[string]any{{"product_variant_id": a.bienThe, "quantity": 2}},
	})
	if res.ma != http.StatusCreated {
		t.Fatalf("bán tại quầy trả %d\n%s", res.ma, catBot(res.than))
	}
	donID := uint(doc(t, res)["order_id"].(float64))

	tra := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/returns", map[string]any{
		"order_id": donID, "reason": "other", "refund_method": "cash",
		"items": []map[string]any{{"order_item_id": dongHangDauTien(t, h, a, donID), "quantity": 1}},
	})
	if tra.ma != http.StatusCreated {
		t.Fatalf("lập phiếu trả trả %d\n%s", tra.ma, catBot(tra.than))
	}
	phieu := uint(doc(t, tra)["id"].(float64))

	// Phiếu còn chờ duyệt: chưa món nào quay về.
	if bc := docTongHop(t, h, a, ""); bc.Returns.Returns != 0 {
		t.Fatalf("phiếu trả đang chờ duyệt không được tính, đang có %d phiếu", bc.Returns.Returns)
	}

	// "Đã nhận hàng" là đủ để tính: hàng đã về kho. (Chưa chuyển tiếp sang "đã
	// hoàn tiền" vì hoàn tiền mặt cho đơn quầy đang lỗi ở payments.provider —
	// chuyện riêng của luồng trả hàng, không thuộc báo cáo này.)
	if r := h.goi(t, a.token, http.MethodPut, fmt.Sprintf("/api/v1/admin/returns/%d/status", phieu),
		map[string]any{"status": "received"}); r.ma != http.StatusOK {
		t.Fatalf("chuyển phiếu trả sang received trả %d\n%s", r.ma, catBot(r.than))
	}

	bc := docTongHop(t, h, a, "")
	if bc.Returns.Returns != 1 || bc.Returns.Lines != 1 || bc.Returns.Units != 1 || bc.Returns.Refund != 90000 {
		t.Errorf("trả 1 món 90.000: phải 1 phiếu / 1 dòng / 1 món / 90000, đang là %+v", bc.Returns)
	}
	// Chọn nguồn Online thì hàng trả của đơn QUẦY không được lẫn vào.
	if bc := docTongHop(t, h, a, "?channel=web"); bc.Returns.Returns != 0 {
		t.Errorf("nguồn Online không được thấy phiếu trả của đơn quầy, đang thấy %d", bc.Returns.Returns)
	}
}

// TestBaoCaoTongHop_NguonDonVaNgay — lọc nguồn đơn và ngày không có bán.
func TestBaoCaoTongHop_NguonDonVaNgay(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	moCa(t, h, a, 0)
	webTruoc := docTongHop(t, h, a, "?channel=web")
	banQuay(t, h, a, "cash", 1)

	if bc := docTongHop(t, h, a, "?channel=pos"); bc.Totals.Orders != 1 {
		t.Errorf("nguồn Tại quầy phải thấy đúng 1 đơn vừa bán, đang thấy %d", bc.Totals.Orders)
	}
	webSau := docTongHop(t, h, a, "?channel=web")
	if webSau.Totals.Orders != webTruoc.Totals.Orders || webSau.Totals.Revenue != webTruoc.Totals.Revenue {
		t.Errorf("bán tại quầy không được làm đổi số của nguồn Online: trước %d / %v, sau %d / %v",
			webTruoc.Totals.Orders, webTruoc.Totals.Revenue, webSau.Totals.Orders, webSau.Totals.Revenue)
	}
	tatCa := docTongHop(t, h, a, "")
	if tatCa.Totals.Orders != webSau.Totals.Orders+1 {
		t.Errorf("mọi nguồn = quầy + web: phải %d đơn, đang %d", webSau.Totals.Orders+1, tatCa.Totals.Orders)
	}
	bc := docTongHop(t, h, a, "?date=2000-01-01")
	if bc.Date != "2000-01-01" || bc.Totals.Orders != 0 || len(bc.ByHour) != 24 {
		t.Errorf("ngày 2000-01-01 phải rỗng mà vẫn đủ 24 mốc giờ, đang là %s / %d đơn / %d mốc",
			bc.Date, bc.Totals.Orders, len(bc.ByHour))
	}
	// Ngày gõ sai thì lùi về hôm nay chứ không báo lỗi.
	if bc := docTongHop(t, h, a, "?date=29-09-2026"); bc.Date != time.Now().Format("2006-01-02") {
		t.Errorf("ngày sai định dạng phải lùi về hôm nay, đang là %q", bc.Date)
	}
}

// TestBaoCaoTongHop_KhongLanCuaHang — cửa hàng B không thấy số của cửa hàng A.
func TestBaoCaoTongHop_KhongLanCuaHang(t *testing.T) {
	h := dungHeThong(t)
	a, b := haiCuaHang(t, h)
	moCa(t, h, a, 0)
	bTruoc := docTongHop(t, h, b, "?shop_id=0")
	aTruoc := docTongHop(t, h, a, "")

	banQuay(t, h, a, "cash", 1)
	lapPhieuTC(t, h, a.token, chiTienMat(10000))

	bSau := docTongHop(t, h, b, "?shop_id=0")
	if bSau.Totals != bTruoc.Totals || bSau.Cashbook != bTruoc.Cashbook {
		t.Fatalf("cửa hàng A bán và lập phiếu mà số của B đổi: trước %+v %+v, sau %+v %+v",
			bTruoc.Totals, bTruoc.Cashbook, bSau.Totals, bSau.Cashbook)
	}
	// Đối chứng: số của A có đổi.
	if aSau := docTongHop(t, h, a, ""); aSau.Totals.Orders != aTruoc.Totals.Orders+1 {
		t.Fatalf("cửa hàng A phải thêm 1 đơn, trước %d sau %d", aTruoc.Totals.Orders, aSau.Totals.Orders)
	}
}
