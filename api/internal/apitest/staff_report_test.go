package apitest

import (
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"testing"
	"time"

	"sass-api/internal/domain"
	"sass-api/internal/tenant"
)

// Báo cáo ca — GET /admin/reports/staff.
//
// Bám vào việc GẮN ĐƠN: đơn quầy vào đúng ca đang mở, đơn bán lúc không có ca
// thành dòng "ngoài ca", đơn web do nhân viên lập thành dòng online còn đơn
// khách tự đặt thì không tính; dòng tổng và biểu đồ theo nhân viên khớp bảng.

type dongNV struct {
	Date         string  `json:"date"`
	Kind         string  `json:"kind"`
	ShiftID      uint    `json:"shift_id"`
	UserID       uint    `json:"user_id"`
	EmployeeCode string  `json:"employee_code"`
	Name         string  `json:"name"`
	OrderCount   int64   `json:"order_count"`
	Revenue      float64 `json:"revenue"`
	AvgOrder     float64 `json:"avg_order"`
}

type baoCaoNV struct {
	Rows    []dongNV `json:"rows"`
	Totals  dongNV   `json:"totals"`
	ByStaff []struct {
		UserID     uint    `json:"user_id"`
		Name       string  `json:"name"`
		OrderCount int64   `json:"order_count"`
		Revenue    float64 `json:"revenue"`
	} `json:"by_staff"`
}

func docBaoCaoNV(t *testing.T, h *heThong, c *cuaHang, them string) baoCaoNV {
	t.Helper()

	hom := homNayDT()
	res := h.goi(t, c.token, http.MethodGet, "/api/v1/admin/reports/staff?from="+hom+"&to="+hom+them, nil)
	if res.ma != http.StatusOK {
		t.Fatalf("báo cáo ca trả %d\n%s", res.ma, catBot(res.than))
	}
	var out struct {
		Data baoCaoNV `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &out); err != nil {
		t.Fatalf("không đọc được báo cáo ca: %v\n%s", err, catBot(res.than))
	}
	return out.Data
}

// dongLoai trả dòng hôm nay của một loại + người (và ca, nếu > 0).
func dongLoai(bc baoCaoNV, loai string, nguoi, ca uint) (dongNV, bool) {
	for _, r := range bc.Rows {
		if r.Kind == loai && r.UserID == nguoi && (ca == 0 || r.ShiftID == ca) && r.Date == homNayDT() {
			return r, true
		}
	}
	return dongNV{}, false
}

// gieoDonWebDaThu dựng một đơn web đã thu tiền, do `nguoiLap` lập.
func gieoDonWebDaThu(t *testing.T, h *heThong, c *cuaHang, ma string, nguoiLap uint, tien float64) {
	t.Helper()

	ctx := tenant.WithID(context.Background(), c.id)
	now := time.Now()
	tao(t, h.db, ctx, &domain.Order{
		ShopID: c.chiNhanh, OrderCode: ma + "-" + c.vet, Channel: domain.OrderChannelWeb,
		UserID: &c.khach, CreatedBy: &nguoiLap,
		RecipientName: "Người nhận", RecipientPhone: "0900000009", ShippingAddress: "Địa chỉ",
		SubtotalAmount: tien, TotalAmount: tien,
		PaymentMethod: domain.PaymentMethodBank, PaymentStatus: domain.OrderPaymentPaid,
		Status: domain.OrderStatusCompleted, PlacedAt: &now,
	})
}

// TestBaoCaoCa_TrongCa — đơn quầy vào đúng ca đang mở, số tiền và giá trị TB đúng.
func TestBaoCaoCa_TrongCa(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	ca := uint(moCa(t, h, a, 0)["id"].(float64))

	banQuay(t, h, a, "cash", 2)          // 180.000
	banQuay(t, h, a, "bank_transfer", 1) // 90.000
	bc := docBaoCaoNV(t, h, a, "")

	r, co := dongLoai(bc, domain.CaLoaiCa, a.quanTri, ca)
	if !co {
		t.Fatalf("phải có dòng của ca %d / người bán %d, đang là %+v", ca, a.quanTri, bc.Rows)
	}
	if r.OrderCount != 2 || r.Revenue != 270000 || r.AvgOrder != 135000 || r.Name != "Quản trị "+a.vet {
		t.Errorf("dòng ca phải 2 đơn / 270000 / TB 135000 / tên người bán, đang là %+v", r)
	}

	var don int64
	var tien float64
	for _, d := range bc.Rows {
		don += d.OrderCount
		tien += d.Revenue
	}
	if don != bc.Totals.OrderCount || tien != bc.Totals.Revenue || bc.Totals.AvgOrder != tien/float64(don) {
		t.Errorf("dòng tổng %+v phải bằng cộng các dòng (%d / %v), TB = tiền / đơn", bc.Totals, don, tien)
	}
	var theoNguoi float64
	for _, n := range bc.ByStaff {
		theoNguoi += n.Revenue
	}
	if theoNguoi != bc.Totals.Revenue {
		t.Errorf("biểu đồ theo nhân viên cộng lại (%v) phải bằng dòng tổng (%v)", theoNguoi, bc.Totals.Revenue)
	}
}

// TestBaoCaoCa_NgoaiCaVaOnline — đơn lúc không có ca, đơn web nhân viên lập,
// và đơn khách tự đặt (không tính).
func TestBaoCaoCa_NgoaiCaVaOnline(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	// Quầy bán được khi chưa ai mở ca.
	banQuay(t, h, a, "cash", 1)
	gieoDonWebDaThu(t, h, a, "dh-nv", a.nhanVien, 50000)
	gieoDonWebDaThu(t, h, a, "dh-khach", a.khach, 70000)
	bc := docBaoCaoNV(t, h, a, "")

	if r, co := dongLoai(bc, domain.CaLoaiNgoaiCa, a.quanTri, 0); !co || r.OrderCount != 1 || r.Revenue != 90000 || r.ShiftID != 0 {
		t.Errorf("đơn quầy lúc không có ca phải thành dòng ngoài ca 1 đơn / 90000, đang là %+v (có: %v)", r, co)
	}
	if r, co := dongLoai(bc, domain.CaLoaiOnline, a.nhanVien, 0); !co || r.OrderCount != 1 || r.Revenue != 50000 {
		t.Errorf("đơn web nhân viên lập phải thành dòng online 1 đơn / 50000, đang là %+v (có: %v)", r, co)
	}
	for _, r := range bc.Rows {
		if r.UserID == a.khach {
			t.Errorf("đơn khách tự đặt không thuộc nhân viên nào, không được có dòng: %+v", r)
		}
	}

	// Nguồn Tại quầy: không còn dòng online.
	for _, r := range docBaoCaoNV(t, h, a, "&channel=pos").Rows {
		if r.Kind == domain.CaLoaiOnline {
			t.Errorf("lọc Tại quầy mà còn dòng online: %+v", r)
		}
	}
}

// TestBaoCaoCa_DongCaRoiBan — ca đã đóng thì đơn bán sau đó không vào ca ấy.
func TestBaoCaoCa_DongCaRoiBan(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	ca := uint(moCa(t, h, a, 0)["id"].(float64))
	banQuay(t, h, a, "cash", 1)
	if res := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/ca-lam-viec/dong",
		map[string]any{"counted_cash": 90000}); res.ma != http.StatusOK {
		t.Fatalf("đóng ca trả %d\n%s", res.ma, catBot(res.than))
	}
	banQuay(t, h, a, "cash", 2)

	bc := docBaoCaoNV(t, h, a, "")
	if r, _ := dongLoai(bc, domain.CaLoaiCa, a.quanTri, ca); r.OrderCount != 1 || r.Revenue != 90000 {
		t.Errorf("ca đã đóng chỉ giữ đơn bán trong ca (1 / 90000), đang là %+v", r)
	}
	if r, _ := dongLoai(bc, domain.CaLoaiNgoaiCa, a.quanTri, 0); r.OrderCount != 1 || r.Revenue != 180000 {
		t.Errorf("đơn bán sau khi đóng ca phải ra ngoài ca (1 / 180000), đang là %+v", r)
	}
}

// TestBaoCaoCa_Loc — nhóm nhân viên, một nhân viên, ô tìm.
func TestBaoCaoCa_Loc(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	moCa(t, h, a, 0)
	banQuay(t, h, a, "cash", 1)
	gieoDonWebDaThu(t, h, a, "dh-nv", a.nhanVien, 50000)

	ctx := tenant.WithID(context.Background(), a.id)
	if err := h.db.WithContext(ctx).Model(&domain.User{}).Where("id = ?", a.nhanVien).
		Update("access_areas", domain.CuaThuNgan).Error; err != nil {
		t.Fatalf("không gán cửa thu ngân: %v", err)
	}

	// Quản trị viên mẫu để trống cột cửa vào: vai trò admin nên mở được cả hai
	// cửa (domain.CuaVao) — có mặt ở cả hai nhóm. Nhân viên chỉ có cửa thu ngân.
	if thuNgan := docBaoCaoNV(t, h, a, "&area=thu_ngan"); len(thuNgan.Rows) != 2 {
		t.Errorf("nhóm Thu ngân phải có cả nhân viên lẫn quản trị (cột trống, suy từ vai trò), đang là %+v", thuNgan.Rows)
	}
	quanLy := docBaoCaoNV(t, h, a, "&area=quan_ly")
	if len(quanLy.Rows) != 1 || quanLy.Rows[0].UserID != a.quanTri {
		t.Errorf("nhóm Quản lý chỉ còn quản trị %d, đang là %+v", a.quanTri, quanLy.Rows)
	}
	if mot := docBaoCaoNV(t, h, a, fmt.Sprintf("&user_id=%d", a.quanTri)); len(mot.Rows) != 1 || mot.Rows[0].UserID != a.quanTri {
		t.Errorf("lọc một nhân viên chỉ còn dòng của người đó, đang là %+v", mot.Rows)
	}
	if tim := docBaoCaoNV(t, h, a, "&keyword=Nh%C3%A2n+vi%C3%AAn"); len(tim.Rows) != 1 || tim.Rows[0].UserID != a.nhanVien {
		t.Errorf("tìm \"Nhân viên\" chỉ còn dòng của nhân viên, đang là %+v", tim.Rows)
	}
	// Nhóm lạ = không lọc.
	if la := docBaoCaoNV(t, h, a, "&area=bep"); len(la.Rows) != 2 {
		t.Errorf("nhóm lạ phải coi như không lọc (2 dòng), đang ra %d", len(la.Rows))
	}
}

// TestBaoCaoCa_KhongLanCuaHang — cửa hàng B không thấy ca / đơn của A.
func TestBaoCaoCa_KhongLanCuaHang(t *testing.T) {
	h := dungHeThong(t)
	a, b := haiCuaHang(t, h)
	bTruoc := docBaoCaoNV(t, h, b, "&shop_id=0")

	moCa(t, h, a, 0)
	banQuay(t, h, a, "cash", 1)
	gieoDonWebDaThu(t, h, a, "dh-nv", a.nhanVien, 50000)

	if bSau := docBaoCaoNV(t, h, b, "&shop_id=0"); bSau.Totals != bTruoc.Totals || len(bSau.Rows) != len(bTruoc.Rows) {
		t.Fatalf("A bán mà báo cáo ca của B đổi: trước %+v, sau %+v", bTruoc.Totals, bSau.Totals)
	}
}
