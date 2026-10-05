package apitest

import (
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"net/url"
	"testing"
	"time"

	"sass-api/internal/domain"
	"sass-api/internal/tenant"
)

// Báo cáo hàng hoá — GET /admin/reports/goods và /admin/reports/goods/orders.
//
// Bám vào CON SỐ và sự KHỚP: dòng mặt hàng tăng đúng số lượng / tiền đã bán,
// Thành tiền (VAT) = Tổng giá bán + thuế của dòng, biểu đồ số lượng cộng lại
// đúng tổng, lọc nhóm nở cả nhóm con, và hộp chi tiết ra đúng những hoá đơn làm
// nên dòng mặt hàng.

type dongHH struct {
	ProductID uint    `json:"product_id"`
	Code      string  `json:"code"`
	Name      string  `json:"name"`
	Quantity  int64   `json:"quantity"`
	Amount    float64 `json:"amount"`
	Total     float64 `json:"total"`
}

type mocHH struct {
	Key   string `json:"key"`
	Units int64  `json:"units"`
}

type baoCaoHH struct {
	Rows      []dongHH `json:"rows"`
	Totals    dongHH   `json:"totals"`
	Top       []dongHH `json:"top"`
	ByHour    []mocHH  `json:"by_hour"`
	ByMonth   []mocHH  `json:"by_month"`
	ByWeekday []struct {
		ProductID uint     `json:"product_id"`
		Name      string   `json:"name"`
		Data      [7]int64 `json:"data"`
	} `json:"by_weekday"`
}

type hoaDonHH struct {
	ID       uint    `json:"id"`
	Code     string  `json:"code"`
	Channel  string  `json:"channel"`
	ItemCode string  `json:"item_code"`
	Quantity int64   `json:"quantity"`
	Total    float64 `json:"total"`
}

func docHangHoa(t *testing.T, h *heThong, c *cuaHang, them string) baoCaoHH {
	t.Helper()

	hom := homNayDT()
	res := h.goi(t, c.token, http.MethodGet, "/api/v1/admin/reports/goods?from="+hom+"&to="+hom+them, nil)
	if res.ma != http.StatusOK {
		t.Fatalf("báo cáo hàng hoá trả %d\n%s", res.ma, catBot(res.than))
	}
	var out struct {
		Data baoCaoHH `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &out); err != nil {
		t.Fatalf("không đọc được báo cáo hàng hoá: %v\n%s", err, catBot(res.than))
	}
	return out.Data
}

func docHoaDonHH(t *testing.T, h *heThong, c *cuaHang, sanPham uint, them string) []hoaDonHH {
	t.Helper()

	hom := homNayDT()
	res := h.goi(t, c.token, http.MethodGet,
		fmt.Sprintf("/api/v1/admin/reports/goods/orders?product_id=%d&from=%s&to=%s%s", sanPham, hom, hom, them), nil)
	if res.ma != http.StatusOK {
		t.Fatalf("hoá đơn của mặt hàng trả %d\n%s", res.ma, catBot(res.than))
	}
	var out struct {
		Data []hoaDonHH `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &out); err != nil {
		t.Fatalf("không đọc được hoá đơn của mặt hàng: %v\n%s", err, catBot(res.than))
	}
	return out.Data
}

func dongCua(bc baoCaoHH, sanPham uint) dongHH {
	for _, r := range bc.Rows {
		if r.ProductID == sanPham {
			return r
		}
	}
	return dongHH{}
}

// gieoHangNhomCon dựng một nhóm CON dưới nhóm mẫu của c, một mặt hàng trong đó
// và một đơn quầy đã thu bán 3 cái: tiền dòng 300.000, thuế 24.000.
func gieoHangNhomCon(t *testing.T, h *heThong, c *cuaHang) (nhom, sanPham uint) {
	t.Helper()

	ctx := tenant.WithID(context.Background(), c.id)
	now := time.Now()

	con := &domain.Category{Name: "Nhóm con " + c.vet, Slug: "dm-con-" + c.vet, ParentID: &c.danhMuc, IsActive: true}
	tao(t, h.db, ctx, con)
	sp := &domain.Product{
		CategoryID: con.ID, Name: "Hàng nhóm con " + c.vet, Slug: "sp-con-" + c.vet, SKU: "sku-con-" + c.vet,
		BasePrice: 100000, Status: domain.ProductStatusActive, IsActive: true,
	}
	tao(t, h.db, ctx, sp)

	don := &domain.Order{
		ShopID: c.chiNhanh, OrderCode: "hh-" + c.vet, Channel: domain.OrderChannelPOS,
		SubtotalAmount: 300000, VatAmount: 24000, TotalAmount: 324000,
		PaymentMethod: domain.PaymentMethodCash, PaymentStatus: domain.OrderPaymentPaid,
		Status: domain.OrderStatusCompleted, PlacedAt: &now,
	}
	tao(t, h.db, ctx, don)
	tao(t, h.db, ctx, &domain.OrderItem{
		OrderID: don.ID, ProductID: &sp.ID, ProductName: sp.Name, VariantSKU: sp.SKU,
		UnitPrice: 100000, Quantity: 3, TotalPrice: 300000, VatAmount: 24000,
	})

	return con.ID, sp.ID
}

// TestBaoCaoHangHoa_SoLieu — bán hai lượt: dòng mặt hàng tăng đúng, tổng và
// biểu đồ khớp bảng.
func TestBaoCaoHangHoa_SoLieu(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	moCa(t, h, a, 0)

	// Cửa hàng mẫu đã có sẵn đơn web trong ngày — so PHẦN CHÊNH trước/sau.
	truoc := dongCua(docHangHoa(t, h, a, ""), a.sanPham)
	banQuay(t, h, a, "cash", 2)          // 180.000
	banQuay(t, h, a, "bank_transfer", 1) // 90.000
	bc := docHangHoa(t, h, a, "")
	sau := dongCua(bc, a.sanPham)

	if sau.Quantity-truoc.Quantity != 3 || sau.Amount-truoc.Amount != 270000 {
		t.Errorf("mặt hàng mẫu phải thêm 3 cái / 270000, đang thêm %d / %v",
			sau.Quantity-truoc.Quantity, sau.Amount-truoc.Amount)
	}
	if sau.Code != "sku-"+a.vet || sau.Name != "Sản phẩm "+a.vet {
		t.Errorf("mã / tên phải lấy của mặt hàng, đang là %q / %q", sau.Code, sau.Name)
	}

	var tong dongHH
	for _, r := range bc.Rows {
		tong.Quantity += r.Quantity
		tong.Amount += r.Amount
		tong.Total += r.Total
		if r.Total < r.Amount {
			t.Errorf("%q: thành tiền VAT (%v) không được nhỏ hơn giá bán (%v)", r.Name, r.Total, r.Amount)
		}
	}
	if tong.Quantity != bc.Totals.Quantity || tong.Amount != bc.Totals.Amount || tong.Total != bc.Totals.Total {
		t.Errorf("dòng tổng %+v phải bằng cộng các dòng %+v", bc.Totals, tong)
	}

	if len(bc.ByHour) != 24 || len(bc.ByMonth) != 12 {
		t.Fatalf("số mốc phải là 24/12, đang là %d/%d", len(bc.ByHour), len(bc.ByMonth))
	}
	for _, ds := range [][]mocHH{bc.ByHour, bc.ByMonth} {
		var cong int64
		for _, m := range ds {
			cong += m.Units
		}
		if cong != bc.Totals.Quantity {
			t.Errorf("cộng một biểu đồ số lượng (%d) phải bằng tổng số lượng (%d)", cong, bc.Totals.Quantity)
		}
	}

	// Theo thứ: mặt hàng mẫu có mặt, cộng bảy ngày đúng bằng số lượng của nó.
	var coMat bool
	for _, s := range bc.ByWeekday {
		if s.ProductID != a.sanPham {
			continue
		}
		coMat = true
		var cong int64
		for _, v := range s.Data {
			cong += v
		}
		if cong != sau.Quantity || s.Data[(int(time.Now().Weekday())+6)%7] != sau.Quantity {
			t.Errorf("theo thứ của mặt hàng mẫu %v phải dồn cả %d cái vào hôm nay", s.Data, sau.Quantity)
		}
	}
	if !coMat {
		t.Errorf("biểu đồ theo thứ phải có mặt hàng mẫu, đang là %+v", bc.ByWeekday)
	}
}

// TestBaoCaoHangHoa_VATVaTop — thuế của dòng vào cột VAT; top xếp theo tiền hai chiều.
func TestBaoCaoHangHoa_VATVaTop(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	_, spCon := gieoHangNhomCon(t, h, a)

	bc := docHangHoa(t, h, a, "")
	con := dongCua(bc, spCon)
	if con.Quantity != 3 || con.Amount != 300000 || con.Total != 324000 {
		t.Errorf("hàng nhóm con phải 3 cái / 300000 / 324000 (gồm 24000 thuế), đang là %+v", con)
	}

	if len(bc.Top) == 0 || len(bc.Top) > 5 {
		t.Fatalf("top mặc định 5 món, đang có %d", len(bc.Top))
	}
	for i := 1; i < len(bc.Top); i++ {
		if bc.Top[i].Total > bc.Top[i-1].Total {
			t.Errorf("top desc phải giảm dần theo tiền: %+v", bc.Top)
		}
	}
	tang := docHangHoa(t, h, a, "&sort=asc&top=10")
	for i := 1; i < len(tang.Top); i++ {
		if tang.Top[i].Total < tang.Top[i-1].Total {
			t.Errorf("top asc phải tăng dần theo tiền: %+v", tang.Top)
		}
	}
	if len(tang.Top) != min(10, len(tang.Rows)) {
		t.Errorf("top=10 phải lấy %d món, đang lấy %d", min(10, len(tang.Rows)), len(tang.Top))
	}
}

// TestBaoCaoHangHoa_Loc — nhóm nở cả nhóm con, một mặt hàng, ô tìm chỉ lọc bảng.
func TestBaoCaoHangHoa_Loc(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	moCa(t, h, a, 0)
	banQuay(t, h, a, "cash", 1)
	nhomCon, spCon := gieoHangNhomCon(t, h, a)

	cha := docHangHoa(t, h, a, fmt.Sprintf("&category_id=%d", a.danhMuc))
	if dongCua(cha, a.sanPham).Quantity == 0 || dongCua(cha, spCon).Quantity != 3 {
		t.Errorf("lọc nhóm cha phải có cả hàng của nhóm con, đang là %+v", cha.Rows)
	}
	con := docHangHoa(t, h, a, fmt.Sprintf("&category_id=%d", nhomCon))
	if len(con.Rows) != 1 || con.Rows[0].ProductID != spCon {
		t.Errorf("lọc nhóm con chỉ còn hàng của nó, đang là %+v", con.Rows)
	}

	mot := docHangHoa(t, h, a, fmt.Sprintf("&product_id=%d", a.sanPham))
	if len(mot.Rows) != 1 || mot.Rows[0].ProductID != a.sanPham || mot.Totals.Quantity != mot.Rows[0].Quantity {
		t.Errorf("lọc một mặt hàng chỉ còn dòng của nó, đang là %+v", mot.Rows)
	}

	// Ô tìm lọc bảng, còn biểu đồ vẫn là của cả kỳ.
	du := docHangHoa(t, h, a, "")
	tim := docHangHoa(t, h, a, "&keyword="+url.QueryEscape("nhóm con"))
	if len(tim.Rows) != 1 || tim.Rows[0].ProductID != spCon || tim.Totals.Quantity != 3 {
		t.Errorf("tìm \"nhóm con\" chỉ còn hàng nhóm con, đang là %+v", tim.Rows)
	}
	if fmt.Sprint(tim.Top) != fmt.Sprint(du.Top) || fmt.Sprint(tim.ByHour) != fmt.Sprint(du.ByHour) {
		t.Errorf("ô tìm không được đổi biểu đồ")
	}
	if ma := docHangHoa(t, h, a, "&keyword=sku-con-"+a.vet); len(ma.Rows) != 1 {
		t.Errorf("tìm theo mã phải ra đúng 1 dòng, đang ra %d", len(ma.Rows))
	}

	// Nguồn đơn Online: đơn quầy vừa bán không được lẫn vào.
	if web := docHangHoa(t, h, a, "&channel=web"); dongCua(web, spCon).Quantity != 0 {
		t.Errorf("lọc Online không được thấy hàng bán tại quầy")
	}
}

// TestBaoCaoHangHoa_HoaDon — hộp chi tiết ra đúng những hoá đơn làm nên dòng mặt hàng.
func TestBaoCaoHangHoa_HoaDon(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	moCa(t, h, a, 0)
	banQuay(t, h, a, "cash", 2)
	banQuay(t, h, a, "bank_transfer", 1)

	dong := dongCua(docHangHoa(t, h, a, ""), a.sanPham)
	ds := docHoaDonHH(t, h, a, a.sanPham, "")
	var sl int64
	var tien float64
	for _, hd := range ds {
		sl += hd.Quantity
		tien += hd.Total
		if hd.Code == "" || hd.ItemCode != "sku-"+a.vet {
			t.Errorf("hoá đơn %d thiếu mã hoặc sai mã hàng: %+v", hd.ID, hd)
		}
	}
	if sl != dong.Quantity || tien != dong.Total {
		t.Errorf("hộp chi tiết phải cộng ra %d cái / %v, đang ra %d / %v", dong.Quantity, dong.Total, sl, tien)
	}

	quay := docHoaDonHH(t, h, a, a.sanPham, "&channel=pos")
	if len(quay) != 2 {
		t.Errorf("tại quầy phải có đúng 2 hoá đơn, đang có %d", len(quay))
	}
	for _, hd := range quay {
		if hd.Channel != "pos" {
			t.Errorf("lọc tại quầy mà ra hoá đơn nguồn %q", hd.Channel)
		}
	}

	if r := docHoaDonHH(t, h, a, 0, ""); len(r) != 0 {
		t.Errorf("thiếu product_id phải ra rỗng, đang ra %d", len(r))
	}
}

// TestBaoCaoHangHoa_KhongLanCuaHang — cửa hàng B không thấy hàng của A.
func TestBaoCaoHangHoa_KhongLanCuaHang(t *testing.T) {
	h := dungHeThong(t)
	a, b := haiCuaHang(t, h)
	moCa(t, h, a, 0)
	bTruoc := docHangHoa(t, h, b, "&shop_id=0")

	banQuay(t, h, a, "cash", 1)

	if bSau := docHangHoa(t, h, b, "&shop_id=0"); bSau.Totals != bTruoc.Totals {
		t.Fatalf("A bán mà tổng của B đổi: trước %+v, sau %+v", bTruoc.Totals, bSau.Totals)
	}
	if ds := docHoaDonHH(t, h, b, a.sanPham, "&shop_id=0"); len(ds) != 0 {
		t.Fatalf("B mở được hoá đơn của mặt hàng bên A: %+v", ds)
	}
}
