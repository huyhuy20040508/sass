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

// Báo cáo nhân viên (hoa hồng) — GET /admin/reports/employees.
//
// Bám vào CON SỐ: tiền của người bán, tiền trả hàng trừ khỏi doanh thu tính hoa
// hồng, hoa hồng = cơ sở × tỉ lệ hồ sơ; đơn khách tự đặt không thuộc ai; từng
// đơn trong hộp chi tiết cộng lại đúng dòng của người đó.

type donNVHH struct {
	ID             uint    `json:"id"`
	Code           string  `json:"code"`
	Products       string  `json:"products"`
	Quantity       int64   `json:"quantity"`
	RevenueVAT     float64 `json:"revenue_vat"`
	Returned       float64 `json:"returned"`
	CommissionBase float64 `json:"commission_base"`
	Commission     float64 `json:"commission"`
}

type dongNVHH struct {
	UserID         uint      `json:"user_id"`
	EmployeeCode   string    `json:"employee_code"`
	Name           string    `json:"name"`
	CommissionRate float64   `json:"commission_rate"`
	OrderCount     int64     `json:"order_count"`
	Revenue        float64   `json:"revenue"`
	RevenueVAT     float64   `json:"revenue_vat"`
	Returned       float64   `json:"returned"`
	CommissionBase float64   `json:"commission_base"`
	Commission     float64   `json:"commission"`
	Orders         []donNVHH `json:"orders"`
}

type baoCaoNVHH struct {
	Rows   []dongNVHH `json:"rows"`
	Totals dongNVHH   `json:"totals"`
}

func docBaoCaoNVHH(t *testing.T, h *heThong, c *cuaHang, them string) baoCaoNVHH {
	t.Helper()

	hom := homNayDT()
	res := h.goi(t, c.token, http.MethodGet, "/api/v1/admin/reports/employees?from="+hom+"&to="+hom+them, nil)
	if res.ma != http.StatusOK {
		t.Fatalf("báo cáo nhân viên trả %d\n%s", res.ma, catBot(res.than))
	}
	var out struct {
		Data baoCaoNVHH `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &out); err != nil {
		t.Fatalf("không đọc được báo cáo nhân viên: %v\n%s", err, catBot(res.than))
	}
	return out.Data
}

func dongCuaNguoi(bc baoCaoNVHH, nguoi uint) (dongNVHH, bool) {
	for _, r := range bc.Rows {
		if r.UserID == nguoi {
			return r, true
		}
	}
	return dongNVHH{}, false
}

// gieoHoSoHoaHong gắn hồ sơ nhân sự (mã, tên, tỉ lệ hoa hồng) cho tài khoản.
func gieoHoSoHoaHong(t *testing.T, h *heThong, c *cuaHang, nguoi uint, ma string, tiLe float64) {
	t.Helper()

	tao(t, h.db, tenant.WithID(context.Background(), c.id), &domain.NhanVien{
		UserID: &nguoi, Code: ma + "-" + c.vet, FullName: "Hồ sơ " + ma,
		Position: domain.ChucDanhBanHang, Status: "dang_lam", CommissionRate: tiLe,
	})
}

// TestBaoCaoNhanVien_HoaHong — tiền, hoa hồng và hộp chi tiết của người bán.
func TestBaoCaoNhanVien_HoaHong(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	gieoHoSoHoaHong(t, h, a, a.quanTri, "NVQT", 5)
	moCa(t, h, a, 0)

	banQuay(t, h, a, "cash", 2)          // 180.000
	banQuay(t, h, a, "bank_transfer", 1) // 90.000
	bc := docBaoCaoNVHH(t, h, a, "")

	r, co := dongCuaNguoi(bc, a.quanTri)
	if !co {
		t.Fatalf("phải có dòng của người bán %d, đang là %+v", a.quanTri, bc.Rows)
	}
	if r.OrderCount != 2 || r.RevenueVAT != 270000 || r.Returned != 0 || r.CommissionBase != 270000 || r.Commission != 13500 {
		t.Errorf("dòng người bán phải 2 đơn / 270000 / trả 0 / cơ sở 270000 / hoa hồng 13500 (5%%), đang là %+v", r)
	}
	if r.EmployeeCode != "NVQT-"+a.vet || r.Name != "Hồ sơ NVQT" || r.CommissionRate != 5 {
		t.Errorf("mã / tên / tỉ lệ phải lấy từ hồ sơ nhân sự, đang là %q / %q / %v", r.EmployeeCode, r.Name, r.CommissionRate)
	}

	// Hộp chi tiết: từng đơn cộng lại đúng dòng, đủ số món và tên món.
	var tien, hh float64
	var mon int64
	for _, o := range r.Orders {
		tien += o.RevenueVAT
		hh += o.Commission
		mon += o.Quantity
		if o.Code == "" || o.Products != "Sản phẩm "+a.vet {
			t.Errorf("đơn %d thiếu mã hoặc sai tên món: %+v", o.ID, o)
		}
	}
	if len(r.Orders) != 2 || tien != r.RevenueVAT || hh != r.Commission || mon != 3 {
		t.Errorf("hộp chi tiết phải 2 đơn / 3 món / cộng đúng dòng, đang là %d / %d / %v / %v", len(r.Orders), mon, tien, hh)
	}

	var tong float64
	for _, d := range bc.Rows {
		tong += d.Commission
	}
	if tong != bc.Totals.Commission || bc.Totals.Orders != nil {
		t.Errorf("dòng tổng hoa hồng %v phải bằng cộng các dòng %v (và không kèm đơn)", bc.Totals.Commission, tong)
	}
}

// TestBaoCaoNhanVien_TraHang — tiền hoàn của phiếu trả trừ khỏi doanh thu tính hoa hồng.
func TestBaoCaoNhanVien_TraHang(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	gieoHoSoHoaHong(t, h, a, a.quanTri, "NVQT", 10)
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
	if r := h.goi(t, a.token, http.MethodPut, fmt.Sprintf("/api/v1/admin/returns/%d/status", phieu),
		map[string]any{"status": "received"}); r.ma != http.StatusOK {
		t.Fatalf("chuyển phiếu trả sang received trả %d\n%s", r.ma, catBot(r.than))
	}

	r, _ := dongCuaNguoi(docBaoCaoNVHH(t, h, a, ""), a.quanTri)
	if r.RevenueVAT != 180000 || r.Returned != 90000 || r.CommissionBase != 90000 || r.Commission != 9000 {
		t.Errorf("bán 180000 trả 90000 → cơ sở 90000, hoa hồng 9000 (10%%); đang là %+v", r)
	}
}

// TestBaoCaoNhanVien_DonWeb — đơn web nhân viên lập có, khách tự đặt thì không;
// chưa có hồ sơ nhân sự thì tỉ lệ 0, vẫn có dòng doanh thu.
func TestBaoCaoNhanVien_DonWeb(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	gieoDonWebDaThu(t, h, a, "dh-nv", a.nhanVien, 50000)
	gieoDonWebDaThu(t, h, a, "dh-khach", a.khach, 70000)
	bc := docBaoCaoNVHH(t, h, a, "")

	r, co := dongCuaNguoi(bc, a.nhanVien)
	if !co || r.OrderCount != 1 || r.RevenueVAT != 50000 || r.CommissionRate != 0 || r.Commission != 0 {
		t.Errorf("đơn web nhân viên lập: 1 đơn / 50000 / tỉ lệ 0 / hoa hồng 0, đang là %+v (có: %v)", r, co)
	}
	if _, co := dongCuaNguoi(bc, a.khach); co {
		t.Errorf("đơn khách tự đặt không thuộc nhân viên nào")
	}
}

// TestBaoCaoNhanVien_Loc — một nhân viên, nhóm, ô tìm theo mã đơn.
func TestBaoCaoNhanVien_Loc(t *testing.T) {
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

	if mot := docBaoCaoNVHH(t, h, a, fmt.Sprintf("&user_id=%d", a.nhanVien)); len(mot.Rows) != 1 || mot.Rows[0].UserID != a.nhanVien {
		t.Errorf("lọc một nhân viên chỉ còn người đó, đang là %+v", mot.Rows)
	}
	if ql := docBaoCaoNVHH(t, h, a, "&area=quan_ly"); len(ql.Rows) != 1 || ql.Rows[0].UserID != a.quanTri {
		t.Errorf("nhóm Quản lý chỉ còn quản trị (cột trống, suy từ vai trò), đang là %+v", ql.Rows)
	}
	tim := docBaoCaoNVHH(t, h, a, "&keyword=dh-nv-"+a.vet)
	if len(tim.Rows) != 1 || tim.Rows[0].UserID != a.nhanVien || len(tim.Rows[0].Orders) != 1 {
		t.Errorf("tìm theo mã đơn chỉ còn đúng đơn đó, đang là %+v", tim.Rows)
	}
}

// TestBaoCaoNhanVien_KhongLanCuaHang — cửa hàng B không thấy người bán của A.
func TestBaoCaoNhanVien_KhongLanCuaHang(t *testing.T) {
	h := dungHeThong(t)
	a, b := haiCuaHang(t, h)
	bTruoc := docBaoCaoNVHH(t, h, b, "&shop_id=0")

	moCa(t, h, a, 0)
	banQuay(t, h, a, "cash", 1)
	gieoDonWebDaThu(t, h, a, "dh-nv", a.nhanVien, 50000)

	bSau := docBaoCaoNVHH(t, h, b, "&shop_id=0")
	if fmt.Sprint(bSau.Totals) != fmt.Sprint(bTruoc.Totals) || len(bSau.Rows) != len(bTruoc.Rows) {
		t.Fatalf("A bán mà báo cáo nhân viên của B đổi: trước %+v, sau %+v", bTruoc.Totals, bSau.Totals)
	}
}
