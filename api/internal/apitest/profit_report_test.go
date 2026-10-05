package apitest

import (
	"context"
	"encoding/json"
	"fmt"
	"math"
	"net/http"
	"testing"
	"time"

	"sass-api/internal/domain"
	"sass-api/internal/tenant"
)

// Báo cáo chi phí & lợi nhuận — GET /admin/reports/profit.
//
// Bám vào CON SỐ: giá vốn lấy giá chụp lúc bán, lợi nhuận = bán − vốn (âm khi
// bán lỗ), biên lãi của dòng tổng tính lại chứ không cộng dồn, món ế có mặt với
// mọi cột 0, và biểu đồ cộng lại đúng tổng.

type dongLN struct {
	ProductID    uint    `json:"product_id"`
	Code         string  `json:"code"`
	Name         string  `json:"name"`
	CategoryName string  `json:"category_name"`
	Quantity     int64   `json:"quantity"`
	Revenue      float64 `json:"revenue"`
	Cost         float64 `json:"cost"`
	Profit       float64 `json:"profit"`
	Margin       float64 `json:"margin"`
}

type baoCaoLN struct {
	GroupBy string   `json:"group_by"`
	Rows    []dongLN `json:"rows"`
	Totals  dongLN   `json:"totals"`
	Chart   []struct {
		Key     string  `json:"key"`
		Revenue float64 `json:"revenue"`
		Cost    float64 `json:"cost"`
		Profit  float64 `json:"profit"`
	} `json:"chart"`
}

func docLoiNhuan(t *testing.T, h *heThong, c *cuaHang, tu, den, them string) baoCaoLN {
	t.Helper()

	res := h.goi(t, c.token, http.MethodGet, "/api/v1/admin/reports/profit?from="+tu+"&to="+den+them, nil)
	if res.ma != http.StatusOK {
		t.Fatalf("báo cáo lợi nhuận trả %d\n%s", res.ma, catBot(res.than))
	}
	var out struct {
		Data baoCaoLN `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &out); err != nil {
		t.Fatalf("không đọc được báo cáo lợi nhuận: %v\n%s", err, catBot(res.than))
	}
	return out.Data
}

func dongLNCua(bc baoCaoLN, sanPham uint) (dongLN, bool) {
	for _, r := range bc.Rows {
		if r.ProductID == sanPham {
			return r, true
		}
	}
	return dongLN{}, false
}

// gieoHangCoVon dựng một mặt hàng trong nhóm mẫu của c và một đơn quầy đã thu
// bán `soLuong` cái giá `gia`, giá vốn chụp lúc bán `von`.
func gieoHangCoVon(t *testing.T, h *heThong, c *cuaHang, hau string, soLuong int, gia, von float64) uint {
	t.Helper()

	ctx := tenant.WithID(context.Background(), c.id)
	now := time.Now()

	sp := &domain.Product{
		CategoryID: c.danhMuc, Name: "Hàng " + hau + " " + c.vet, Slug: "sp-" + hau + "-" + c.vet, SKU: "sku-" + hau + "-" + c.vet,
		BasePrice: gia, Status: domain.ProductStatusActive, IsActive: true,
	}
	tao(t, h.db, ctx, sp)
	if soLuong == 0 {
		return sp.ID
	}

	tien := gia * float64(soLuong)
	don := &domain.Order{
		ShopID: c.chiNhanh, OrderCode: "ln-" + hau + "-" + c.vet, Channel: domain.OrderChannelPOS,
		SubtotalAmount: tien, TotalAmount: tien,
		PaymentMethod: domain.PaymentMethodCash, PaymentStatus: domain.OrderPaymentPaid,
		Status: domain.OrderStatusCompleted, PlacedAt: &now,
	}
	tao(t, h.db, ctx, don)
	tao(t, h.db, ctx, &domain.OrderItem{
		OrderID: don.ID, ProductID: &sp.ID, ProductName: sp.Name, VariantSKU: sp.SKU,
		UnitPrice: gia, Quantity: soLuong, TotalPrice: tien, CostPrice: conTro(von),
	})
	return sp.ID
}

// TestBaoCaoLoiNhuan_SoLieu — lãi, lỗ, biên lãi và dòng tổng.
func TestBaoCaoLoiNhuan_SoLieu(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	hom := homNayDT()

	lai := gieoHangCoVon(t, h, a, "lai", 3, 100000, 70000) // bán 300.000, vốn 210.000
	lo := gieoHangCoVon(t, h, a, "lo", 2, 50000, 60000)    // bán 100.000, vốn 120.000
	bc := docLoiNhuan(t, h, a, hom, hom, "")

	r, _ := dongLNCua(bc, lai)
	if r.Quantity != 3 || r.Revenue != 300000 || r.Cost != 210000 || r.Profit != 90000 || math.Abs(r.Margin-30) > 1e-9 {
		t.Errorf("hàng lãi phải 3 / 300000 / 210000 / 90000 / 30%%, đang là %+v", r)
	}
	if r.CategoryName != "Danh mục "+a.vet || r.Code != "sku-lai-"+a.vet {
		t.Errorf("mã / nhóm phải lấy của mặt hàng, đang là %q / %q", r.Code, r.CategoryName)
	}
	r, _ = dongLNCua(bc, lo)
	if r.Profit != -20000 || math.Abs(r.Margin+20) > 1e-9 {
		t.Errorf("bán lỗ phải ra lợi nhuận -20000 / biên -20%%, đang là %+v", r)
	}

	var tong dongLN
	for _, d := range bc.Rows {
		tong.Quantity += d.Quantity
		tong.Revenue += d.Revenue
		tong.Cost += d.Cost
		if d.Profit != d.Revenue-d.Cost {
			t.Errorf("%q: lợi nhuận %v phải = %v − %v", d.Name, d.Profit, d.Revenue, d.Cost)
		}
	}
	if tong.Quantity != bc.Totals.Quantity || tong.Revenue != bc.Totals.Revenue || tong.Cost != bc.Totals.Cost {
		t.Errorf("dòng tổng %+v phải bằng cộng các dòng %+v", bc.Totals, tong)
	}
	if bc.Totals.Profit != bc.Totals.Revenue-bc.Totals.Cost ||
		math.Abs(bc.Totals.Margin-bc.Totals.Profit/bc.Totals.Revenue*100) > 1e-9 {
		t.Errorf("biên lãi dòng tổng phải tính lại từ tổng, đang là %+v", bc.Totals)
	}
}

// TestBaoCaoLoiNhuan_HangE — món đang bán mà chưa bán được nằm cuối bảng với 0.
func TestBaoCaoLoiNhuan_HangE(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	hom := homNayDT()

	lai := gieoHangCoVon(t, h, a, "lai", 1, 100000, 70000)
	e := gieoHangCoVon(t, h, a, "e", 0, 80000, 0)
	bc := docLoiNhuan(t, h, a, hom, hom, "")

	r, co := dongLNCua(bc, e)
	if !co || r.Quantity != 0 || r.Revenue != 0 || r.Margin != 0 || r.Name != "Hàng e "+a.vet {
		t.Fatalf("món ế phải có mặt với mọi cột 0, đang là %+v (có mặt: %v)", r, co)
	}
	viTri := map[uint]int{}
	for i, d := range bc.Rows {
		viTri[d.ProductID] = i
	}
	if viTri[e] < viTri[lai] {
		t.Errorf("món ế phải đứng sau món đã bán")
	}

	// Ô tìm lọc cả món ế; lọc một mặt hàng thì chỉ còn nó.
	if tim := docLoiNhuan(t, h, a, hom, hom, "&keyword=sku-e-"+a.vet); len(tim.Rows) != 1 || tim.Rows[0].ProductID != e {
		t.Errorf("tìm mã món ế phải ra đúng nó, đang là %+v", tim.Rows)
	}
	if mot := docLoiNhuan(t, h, a, hom, hom, fmt.Sprintf("&product_id=%d", lai)); len(mot.Rows) != 1 || mot.Rows[0].ProductID != lai {
		t.Errorf("lọc một mặt hàng chỉ còn dòng của nó, đang là %+v", mot.Rows)
	}

	// Bán rồi thì không còn là món ế: không có hai dòng cho cùng mặt hàng.
	dem := 0
	for _, d := range bc.Rows {
		if d.ProductID == lai {
			dem++
		}
	}
	if dem != 1 {
		t.Errorf("mặt hàng đã bán phải đúng một dòng, đang có %d", dem)
	}
}

// TestBaoCaoLoiNhuan_BieuDo — đủ mốc theo group_by, cộng lại đúng tổng.
func TestBaoCaoLoiNhuan_BieuDo(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	gieoHangCoVon(t, h, a, "lai", 3, 100000, 70000)

	den := time.Now()
	tu := den.AddDate(0, 0, -9)
	bc := docLoiNhuan(t, h, a, tu.Format("2006-01-02"), den.Format("2006-01-02"), "")
	if bc.GroupBy != "day" || len(bc.Chart) != 10 {
		t.Fatalf("kỳ 10 ngày phải chia theo ngày, đủ 10 mốc; đang là %q / %d", bc.GroupBy, len(bc.Chart))
	}
	var thu, von float64
	for _, m := range bc.Chart {
		thu += m.Revenue
		von += m.Cost
		if m.Profit != m.Revenue-m.Cost {
			t.Errorf("mốc %s: lợi nhuận phải = bán − vốn, đang là %+v", m.Key, m)
		}
	}
	if thu != bc.Totals.Revenue || von != bc.Totals.Cost {
		t.Errorf("biểu đồ cộng lại (%v / %v) phải bằng dòng tổng (%v / %v)", thu, von, bc.Totals.Revenue, bc.Totals.Cost)
	}
	if bc.Chart[len(bc.Chart)-1].Key != den.Format("2006-01-02") {
		t.Errorf("mốc cuối phải là hôm nay, đang là %s", bc.Chart[len(bc.Chart)-1].Key)
	}

	thang := docLoiNhuan(t, h, a, tu.Format("2006-01-02"), den.Format("2006-01-02"), "&group_by=month")
	if thang.GroupBy != "month" || len(thang.Chart) < 1 || len(thang.Chart) > 2 {
		t.Errorf("group_by=month phải ra 1–2 mốc tháng, đang là %q / %d", thang.GroupBy, len(thang.Chart))
	}

	// Ô tìm không đổi biểu đồ.
	tim := docLoiNhuan(t, h, a, tu.Format("2006-01-02"), den.Format("2006-01-02"), "&keyword=khong-co-gi")
	if len(tim.Rows) != 0 || fmt.Sprint(tim.Chart) != fmt.Sprint(bc.Chart) {
		t.Errorf("ô tìm chỉ lọc bảng, biểu đồ phải giữ nguyên")
	}
}

// TestBaoCaoLoiNhuan_KhongLanCuaHang — cửa hàng B không thấy hàng của A.
func TestBaoCaoLoiNhuan_KhongLanCuaHang(t *testing.T) {
	h := dungHeThong(t)
	a, b := haiCuaHang(t, h)
	hom := homNayDT()
	bTruoc := docLoiNhuan(t, h, b, hom, hom, "&shop_id=0")

	lai := gieoHangCoVon(t, h, a, "lai", 1, 100000, 70000)

	bSau := docLoiNhuan(t, h, b, hom, hom, "&shop_id=0")
	if bSau.Totals != bTruoc.Totals || len(bSau.Rows) != len(bTruoc.Rows) {
		t.Fatalf("A bán mà bảng của B đổi: trước %+v, sau %+v", bTruoc.Totals, bSau.Totals)
	}
	if _, co := dongLNCua(bSau, lai); co {
		t.Fatalf("B thấy mặt hàng của A")
	}
}
