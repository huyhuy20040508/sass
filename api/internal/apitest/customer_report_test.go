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

// Báo cáo khách hàng (tab Khách hàng của Báo cáo cuối ngày) —
// GET /admin/reports/customers với các cột và bộ lọc mới.
//
// Bám vào CON SỐ và vào sự KHỚP với CRM: mã khách phải là mã trên hồ sơ, công
// nợ tính cùng quy ước với cột "Còn nợ" của CRM (tổng mua − phần đơn đã thu).

type dongKhachBC struct {
	UserID       uint    `json:"user_id"`
	Name         string  `json:"name"`
	CustomerCode string  `json:"customer_code"`
	GroupName    string  `json:"group_name"`
	RankName     string  `json:"rank_name"`
	Points       int64   `json:"points"`
	Orders       int64   `json:"orders"`
	Revenue      float64 `json:"revenue"`
	Paid         float64 `json:"paid"`
	Debt         float64 `json:"debt"`
}

type baoCaoKhach struct {
	Totals struct {
		Orders       int64   `json:"orders"`
		GuestOrders  int64   `json:"guest_orders"`
		GuestRevenue float64 `json:"guest_revenue"`
		GuestPaid    float64 `json:"guest_paid"`
	} `json:"totals"`
	Top []dongKhachBC `json:"top"`
}

func docBaoCaoKhach(t *testing.T, h *heThong, c *cuaHang, q url.Values) baoCaoKhach {
	t.Helper()

	hom := time.Now().Format("2006-01-02")
	q.Set("from", hom)
	q.Set("to", hom)
	q.Set("limit", "100")
	res := h.goi(t, c.token, http.MethodGet, "/api/v1/admin/reports/customers?"+q.Encode(), nil)
	if res.ma != http.StatusOK {
		t.Fatalf("báo cáo khách hàng trả %d\n%s", res.ma, catBot(res.than))
	}
	var out struct {
		Data baoCaoKhach `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &out); err != nil {
		t.Fatalf("không đọc được báo cáo khách hàng: %v\n%s", err, catBot(res.than))
	}
	return out.Data
}

func timKhach(ds []dongKhachBC, id uint) *dongKhachBC {
	for i := range ds {
		if ds[i].UserID == id {
			return &ds[i]
		}
	}
	return nil
}

// taoKhachCoNhom tạo một nhóm khách và một khách thuộc nhóm đó, trả id khách.
func taoKhachCoNhom(t *testing.T, h *heThong, c *cuaHang, tenNhom, tenKhach string) uint {
	t.Helper()

	// Gieo thẳng vào DB: bộ dựng API của test (app_test.go) chưa nối handler
	// nhóm khách, gọi /admin/customer-groups ở đây là lỗi con trỏ nil.
	nhom := &domain.CustomerGroup{Name: tenNhom, Status: 1}
	tao(t, h.db, tenant.WithID(context.Background(), c.id), nhom)
	nhomID := nhom.ID

	khach := h.goi(t, c.token, http.MethodPost, "/api/v1/admin/customers", map[string]any{
		"full_name": tenKhach, "status": "active", "customer_group_id": nhomID,
	})
	if khach.ma != http.StatusCreated && khach.ma != http.StatusOK {
		t.Fatalf("tạo khách trả %d\n%s", khach.ma, catBot(khach.than))
	}
	return uint(doc(t, khach)["id"].(float64))
}

func banChoKhach(t *testing.T, h *heThong, c *cuaHang, khach uint, hinhThuc string) {
	t.Helper()

	res := h.goi(t, c.token, http.MethodPost, "/api/v1/admin/orders/pos", map[string]any{
		"payment_method": hinhThuc, "user_id": khach,
		"items": []map[string]any{{"product_variant_id": c.bienThe, "quantity": 1}},
	})
	if res.ma != http.StatusCreated {
		t.Fatalf("bán cho khách trả %d\n%s", res.ma, catBot(res.than))
	}
}

// TestBaoCaoKhachHang_CotMoi — mã, nhóm, thanh toán, công nợ của từng khách.
func TestBaoCaoKhachHang_CotMoi(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	vet := fmt.Sprintf("bck%d", time.Now().UnixNano()%1e9)

	an := taoKhachCoNhom(t, h, a, "Nhóm "+vet, vet+" An")
	banChoKhach(t, h, a, an, "cash")
	banChoKhach(t, h, a, an, "bank_transfer")

	bc := docBaoCaoKhach(t, h, a, url.Values{})

	dong := timKhach(bc.Top, an)
	if dong == nil {
		t.Fatalf("không thấy khách An trong bảng")
	}
	if dong.Orders != 2 || dong.Revenue != 180000 {
		t.Errorf("An phải 2 đơn / 180000, đang là %d / %v", dong.Orders, dong.Revenue)
	}
	if dong.Paid != 180000 || dong.Debt != 0 {
		t.Errorf("đơn quầy đã thu đủ: thanh toán 180000, công nợ 0 — đang là %v / %v", dong.Paid, dong.Debt)
	}
	if dong.GroupName != "Nhóm "+vet {
		t.Errorf("tên nhóm phải là %q, đang là %q", "Nhóm "+vet, dong.GroupName)
	}

	// Mã khách phải là mã trên hồ sơ CRM, không phải mã tự bịa.
	hoSo := doc(t, h.goi(t, a.token, http.MethodGet, fmt.Sprintf("/api/v1/admin/customers/%d", an), nil))
	if ma, _ := hoSo["customer_code"].(string); ma == "" || dong.CustomerCode != ma {
		t.Errorf("mã khách phải là %q (hồ sơ CRM), đang là %q", ma, dong.CustomerCode)
	}

	// Khách mẫu có đơn COD CHƯA thu — công nợ = tổng mua − phần đã thu, và > 0.
	if k := timKhach(bc.Top, a.khach); k != nil {
		if k.Debt != k.Revenue-k.Paid || k.Debt <= 0 {
			t.Errorf("khách có đơn chưa thu: công nợ phải = %v − %v và > 0, đang là %v", k.Revenue, k.Paid, k.Debt)
		}
	} else {
		t.Errorf("không thấy khách mẫu (có đơn COD chưa thu) trong bảng")
	}

	// Điểm tích luỹ lấy đúng hồ sơ.
	if diem, _ := hoSo["total_points"].(float64); int64(diem) != dong.Points {
		t.Errorf("điểm tích luỹ phải là %v (hồ sơ), đang là %d", diem, dong.Points)
	}
}

// TestBaoCaoKhachHang_BoLoc — lọc nhóm, từ khoá, nguồn đơn.
func TestBaoCaoKhachHang_BoLoc(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	vet := fmt.Sprintf("bcl%d", time.Now().UnixNano()%1e9)

	an := taoKhachCoNhom(t, h, a, "Nhóm "+vet, vet+" An")
	banChoKhach(t, h, a, an, "cash")

	// Lấy id nhóm vừa tạo qua hồ sơ khách.
	hoSo := doc(t, h.goi(t, a.token, http.MethodGet, fmt.Sprintf("/api/v1/admin/customers/%d", an), nil))
	nhom := fmt.Sprint(int64(hoSo["customer_group_id"].(float64)))

	theoNhom := docBaoCaoKhach(t, h, a, url.Values{"customer_group_id": {nhom}})
	if len(theoNhom.Top) != 1 || theoNhom.Top[0].UserID != an {
		t.Errorf("lọc theo nhóm phải chỉ còn An, đang có %d dòng", len(theoNhom.Top))
	}

	if bc := docBaoCaoKhach(t, h, a, url.Values{"keyword": {vet}}); len(bc.Top) != 1 || bc.Top[0].UserID != an {
		t.Errorf("tìm theo tên phải ra đúng An, đang có %d dòng", len(bc.Top))
	}
	if bc := docBaoCaoKhach(t, h, a, url.Values{"user_id": {fmt.Sprint(an)}}); len(bc.Top) != 1 || bc.Top[0].UserID != an {
		t.Errorf("lọc theo một khách phải ra đúng An, đang có %d dòng", len(bc.Top))
	}
	if bc := docBaoCaoKhach(t, h, a, url.Values{"keyword": {"khong-ai-ten-nay"}}); len(bc.Top) != 0 {
		t.Errorf("từ khoá không khớp phải rỗng, đang có %d dòng", len(bc.Top))
	}

	// An chỉ mua ở quầy: lọc Online thì không có An; lọc Tại quầy thì có.
	if bc := docBaoCaoKhach(t, h, a, url.Values{"channel": {"web"}}); timKhach(bc.Top, an) != nil {
		t.Errorf("nguồn Online không được có khách chỉ mua ở quầy")
	}
	if bc := docBaoCaoKhach(t, h, a, url.Values{"channel": {"pos"}}); timKhach(bc.Top, an) == nil {
		t.Errorf("nguồn Tại quầy phải có An")
	}
}

// TestBaoCaoKhachHang_KhachLe — khối tổng có phần khách lẻ đã trả.
func TestBaoCaoKhachHang_KhachLe(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	truoc := docBaoCaoKhach(t, h, a, url.Values{})
	banQuay(t, h, a, "cash", 1) // khách lẻ, đã thu 90.000
	sau := docBaoCaoKhach(t, h, a, url.Values{})

	if d := sau.Totals.GuestOrders - truoc.Totals.GuestOrders; d != 1 {
		t.Errorf("số đơn khách lẻ phải tăng 1, đang tăng %d", d)
	}
	if d := sau.Totals.GuestPaid - truoc.Totals.GuestPaid; d != 90000 {
		t.Errorf("khách lẻ đã trả phải tăng 90000, đang tăng %v", d)
	}
	if sau.Totals.GuestPaid > sau.Totals.GuestRevenue {
		t.Errorf("khách lẻ đã trả (%v) không được vượt doanh thu khách lẻ (%v)",
			sau.Totals.GuestPaid, sau.Totals.GuestRevenue)
	}
}
