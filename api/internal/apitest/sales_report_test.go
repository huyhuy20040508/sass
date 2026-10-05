package apitest

import (
	"encoding/json"
	"fmt"
	"net/http"
	"testing"
	"time"
)

// Báo cáo doanh thu — GET /admin/reports/sales và /admin/reports/sales/orders.
//
// Bám vào CON SỐ và sự KHỚP: các cột hình thức cộng lại đúng bằng Đã thanh toán,
// công nợ = doanh thu − đã thanh toán, biểu đồ theo ngày cộng lại đúng tổng, và
// hộp chi tiết của một ngày ra đúng những hoá đơn làm nên dòng ngày đó.

type ngayDT struct {
	Date       string  `json:"date"`
	Orders     int64   `json:"orders"`
	Revenue    float64 `json:"revenue"`
	RevenueVAT float64 `json:"revenue_vat"`
	Returns    float64 `json:"returns"`
	Paid       float64 `json:"paid"`
	Cash       float64 `json:"cash"`
	Transfer   float64 `json:"transfer"`
	Card       float64 `json:"card"`
	AutoQR     float64 `json:"auto_qr"`
	Other      float64 `json:"other"`
	Debt       float64 `json:"debt"`
}

type moc struct {
	Key     string  `json:"key"`
	Orders  int64   `json:"orders"`
	Revenue float64 `json:"revenue"`
}

type baoCaoDT struct {
	Days      []ngayDT `json:"days"`
	Totals    ngayDT   `json:"totals"`
	ByDay     []moc    `json:"by_day"`
	ByHour    []moc    `json:"by_hour"`
	ByWeekday []moc    `json:"by_weekday"`
	ByMonth   []moc    `json:"by_month"`
}

type hoaDonNgay struct {
	ID            uint    `json:"id"`
	Code          string  `json:"code"`
	Channel       string  `json:"channel"`
	PaymentMethod string  `json:"payment_method"`
	Units         int64   `json:"units"`
	Total         float64 `json:"total"`
	Paid          float64 `json:"paid"`
	Debt          float64 `json:"debt"`
	Returns       float64 `json:"returns"`
	CustomerName  string  `json:"customer_name"`
}

func homNayDT() string { return time.Now().Format("2006-01-02") }

func docDoanhThu(t *testing.T, h *heThong, c *cuaHang, them string) baoCaoDT {
	t.Helper()

	hom := homNayDT()
	res := h.goi(t, c.token, http.MethodGet, "/api/v1/admin/reports/sales?from="+hom+"&to="+hom+them, nil)
	if res.ma != http.StatusOK {
		t.Fatalf("báo cáo doanh thu trả %d\n%s", res.ma, catBot(res.than))
	}
	var out struct {
		Data baoCaoDT `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &out); err != nil {
		t.Fatalf("không đọc được báo cáo doanh thu: %v\n%s", err, catBot(res.than))
	}
	return out.Data
}

func ngayHomNay(bc baoCaoDT) ngayDT {
	for _, d := range bc.Days {
		if d.Date == homNayDT() {
			return d
		}
	}
	return ngayDT{}
}

func docHoaDonNgay(t *testing.T, h *heThong, c *cuaHang, them string) []hoaDonNgay {
	t.Helper()

	res := h.goi(t, c.token, http.MethodGet, "/api/v1/admin/reports/sales/orders?date="+homNayDT()+them, nil)
	if res.ma != http.StatusOK {
		t.Fatalf("hoá đơn của ngày trả %d\n%s", res.ma, catBot(res.than))
	}
	var out struct {
		Data []hoaDonNgay `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &out); err != nil {
		t.Fatalf("không đọc được hoá đơn của ngày: %v\n%s", err, catBot(res.than))
	}
	return out.Data
}

// TestBaoCaoDoanhThu_SoLieuNgay — bán hai hình thức: dòng hôm nay tăng đúng tiền,
// các cột khớp nhau.
func TestBaoCaoDoanhThu_SoLieuNgay(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	moCa(t, h, a, 0)

	// Cửa hàng mẫu đã có sẵn đơn web trong ngày — so PHẦN CHÊNH trước/sau.
	truoc := ngayHomNay(docDoanhThu(t, h, a, ""))
	banQuay(t, h, a, "cash", 2)          // 180.000
	banQuay(t, h, a, "bank_transfer", 1) // 90.000
	bc := docDoanhThu(t, h, a, "")
	sau := ngayHomNay(bc)

	if sau.Orders-truoc.Orders != 2 || sau.RevenueVAT-truoc.RevenueVAT != 270000 {
		t.Errorf("hôm nay phải thêm 2 đơn / 270000, đang thêm %d / %v",
			sau.Orders-truoc.Orders, sau.RevenueVAT-truoc.RevenueVAT)
	}
	if sau.Cash-truoc.Cash != 180000 || sau.Transfer-truoc.Transfer != 90000 || sau.Paid-truoc.Paid != 270000 {
		t.Errorf("tiền mặt/chuyển khoản/đã thu phải thêm 180000/90000/270000, đang thêm %v/%v/%v",
			sau.Cash-truoc.Cash, sau.Transfer-truoc.Transfer, sau.Paid-truoc.Paid)
	}

	for _, d := range append(bc.Days, bc.Totals) {
		if hinhThuc := d.Cash + d.Transfer + d.Card + d.AutoQR + d.Other; hinhThuc != d.Paid {
			t.Errorf("%q: các cột hình thức (%v) phải cộng đúng bằng đã thanh toán (%v)", d.Date, hinhThuc, d.Paid)
		}
		if d.Debt != max(d.RevenueVAT-d.Paid, 0) {
			t.Errorf("%q: công nợ %v phải = doanh thu %v − đã thu %v", d.Date, d.Debt, d.RevenueVAT, d.Paid)
		}
		if d.Revenue > d.RevenueVAT {
			t.Errorf("%q: doanh thu chưa VAT (%v) không được lớn hơn gồm VAT (%v)", d.Date, d.Revenue, d.RevenueVAT)
		}
	}
	if sau.Debt <= 0 {
		t.Errorf("cửa hàng mẫu có đơn COD chưa thu hôm nay — công nợ phải > 0, đang là %v", sau.Debt)
	}
}

// TestBaoCaoDoanhThu_BieuDo — đủ mốc, và biểu đồ theo ngày cộng lại đúng tổng.
func TestBaoCaoDoanhThu_BieuDo(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	moCa(t, h, a, 0)
	banQuay(t, h, a, "cash", 1)

	bc := docDoanhThu(t, h, a, "")
	if len(bc.ByDay) != 1 || len(bc.ByHour) != 24 || len(bc.ByWeekday) != 7 || len(bc.ByMonth) != 12 {
		t.Fatalf("số mốc phải là 1/24/7/12, đang là %d/%d/%d/%d",
			len(bc.ByDay), len(bc.ByHour), len(bc.ByWeekday), len(bc.ByMonth))
	}
	for _, ds := range [][]moc{bc.ByDay, bc.ByHour, bc.ByWeekday, bc.ByMonth} {
		var cong float64
		for _, m := range ds {
			cong += m.Revenue
		}
		if cong != bc.Totals.RevenueVAT {
			t.Errorf("cộng một biểu đồ (%v) phải bằng doanh thu cả kỳ (%v)", cong, bc.Totals.RevenueVAT)
		}
	}
	if thang := bc.ByMonth[time.Now().Month()-1]; thang.Key != fmt.Sprint(int(time.Now().Month())) || thang.Revenue != bc.Totals.RevenueVAT {
		t.Errorf("tháng này phải chứa cả doanh thu kỳ, đang là %+v", thang)
	}
}

// TestBaoCaoDoanhThu_LocHinhThucVaNgay — lọc hình thức và hộp chi tiết một ngày.
func TestBaoCaoDoanhThu_LocHinhThucVaNgay(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	moCa(t, h, a, 0)
	banQuay(t, h, a, "cash", 2)
	banQuay(t, h, a, "bank_transfer", 1)

	chiTienMat := ngayHomNay(docDoanhThu(t, h, a, "&methods=cash"))
	if chiTienMat.Transfer != 0 || chiTienMat.Other != 0 || chiTienMat.Cash != chiTienMat.Paid {
		t.Errorf("lọc Tiền mặt: chỉ còn tiền mặt, đang là %+v", chiTienMat)
	}
	if bc := docDoanhThu(t, h, a, "&methods=card"); len(bc.Days) != 0 {
		t.Errorf("lọc Quẹt thẻ: chưa có đơn quẹt thẻ nào, đang có %d ngày", len(bc.Days))
	}
	// Tích đủ bốn ô = không lọc.
	du := docDoanhThu(t, h, a, "&methods=cash,bank_transfer,card,auto_qr")
	if ngayHomNay(du) != ngayHomNay(docDoanhThu(t, h, a, "")) {
		t.Errorf("tích đủ bốn hình thức phải bằng không lọc")
	}

	// Hộp chi tiết: đúng những hoá đơn làm nên dòng hôm nay.
	ds := docHoaDonNgay(t, h, a, "")
	hom := ngayHomNay(du)
	var tong float64
	for _, hd := range ds {
		tong += hd.Total
		if hd.Code == "" || hd.Debt != max(hd.Total-hd.Paid, 0) {
			t.Errorf("hoá đơn %d thiếu mã hoặc công nợ sai: %+v", hd.ID, hd)
		}
	}
	if int64(len(ds)) != hom.Orders || tong != hom.RevenueVAT {
		t.Errorf("hộp chi tiết phải ra %d hoá đơn / %v, đang ra %d / %v", hom.Orders, hom.RevenueVAT, len(ds), tong)
	}

	ck := docHoaDonNgay(t, h, a, "&methods=bank_transfer&channel=pos")
	if len(ck) != 1 || ck[0].Units != 1 || ck[0].PaymentMethod != "bank_transfer" || ck[0].Channel != "pos" {
		t.Errorf("lọc chuyển khoản tại quầy phải ra đúng 1 hoá đơn 1 món, đang là %+v", ck)
	}
}

// TestBaoCaoDoanhThu_TraHang — tiền hoàn của phiếu đã nhận hàng vào cột trả hàng.
func TestBaoCaoDoanhThu_TraHang(t *testing.T) {
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
	truoc := ngayHomNay(docDoanhThu(t, h, a, ""))

	tra := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/returns", map[string]any{
		"order_id": donID, "reason": "other", "refund_method": "cash",
		"items": []map[string]any{{"order_item_id": dongHangDauTien(t, h, a, donID), "quantity": 1}},
	})
	if tra.ma != http.StatusCreated {
		t.Fatalf("lập phiếu trả trả %d\n%s", tra.ma, catBot(tra.than))
	}
	phieu := uint(doc(t, tra)["id"].(float64))
	if r := h.goi(t, a.token, http.MethodPut, fmt.Sprintf("/api/v1/admin/returns/%d/status", phieu),
		map[string]any{"status": "received"}); r.ma != http.StatusOK {
		t.Fatalf("chuyển phiếu trả sang received trả %d\n%s", r.ma, catBot(r.than))
	}

	for _, hd := range docHoaDonNgay(t, h, a, "") {
		if hd.ID == donID && hd.Returns != 90000 {
			t.Errorf("hộp chi tiết: đơn %d phải có 90000 tiền trả hàng, đang là %v", donID, hd.Returns)
		}
	}

	sau := ngayHomNay(docDoanhThu(t, h, a, ""))
	if sau.Returns-truoc.Returns != 90000 {
		t.Errorf("tiền trả hàng hôm nay phải thêm 90000, đang thêm %v", sau.Returns-truoc.Returns)
	}
	// Chọn Chuyển khoản: phiếu trả của đơn TIỀN MẶT không được lẫn vào.
	if ck := ngayHomNay(docDoanhThu(t, h, a, "&methods=bank_transfer")); ck.Returns != 0 {
		t.Errorf("lọc chuyển khoản không được thấy phiếu trả của đơn tiền mặt, đang là %v", ck.Returns)
	}
}

// TestBaoCaoDoanhThu_KhongLanCuaHang — cửa hàng B không thấy tiền của A.
func TestBaoCaoDoanhThu_KhongLanCuaHang(t *testing.T) {
	h := dungHeThong(t)
	a, b := haiCuaHang(t, h)
	moCa(t, h, a, 0)
	bTruoc := docDoanhThu(t, h, b, "&shop_id=0")

	banQuay(t, h, a, "cash", 1)

	if bSau := docDoanhThu(t, h, b, "&shop_id=0"); bSau.Totals != bTruoc.Totals {
		t.Fatalf("A bán mà tổng của B đổi: trước %+v, sau %+v", bTruoc.Totals, bSau.Totals)
	}
	for _, hd := range docHoaDonNgay(t, h, b, "&shop_id=0") {
		for _, cuaA := range docHoaDonNgay(t, h, a, "") {
			if hd.ID == cuaA.ID {
				t.Fatalf("B thấy hoá đơn %d của A", hd.ID)
			}
		}
	}
}
