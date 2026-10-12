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

// CRM → Tổng quan — GET /admin/reports/crm.
//
// Bám vào CON SỐ: khách mua / mua 1 lần / 2 lần, tỉ lệ quay lại, khoảng quay
// lại, top khách theo chi tiêu và công nợ, giới tính, độ tuổi, lọc loại khách,
// đăng ký mới, sinh nhật sắp tới, khách đang nợ.

type crmBucketThu struct {
	Key   string `json:"key"`
	Count int64  `json:"count"`
}

type crmTopThu struct {
	UserID uint    `json:"user_id"`
	Name   string  `json:"name"`
	Value  float64 `json:"value"`
	Orders int64   `json:"orders"`
}

type crmThu struct {
	Kpis struct {
		Revenue      float64 `json:"revenue"`
		Orders       int64   `json:"orders"`
		Buyers       int64   `json:"buyers"`
		Once         int64   `json:"once"`
		Twice        int64   `json:"twice"`
		RepeatRate   float64 `json:"repeat_rate"`
		NewCustomers int64   `json:"new_customers"`
	} `json:"kpis"`
	TopSpend      []crmTopThu    `json:"top_spend"`
	TopDebt       []crmTopThu    `json:"top_debt"`
	VisitGap      []crmBucketThu `json:"visit_gap"`
	PurchaseCount []crmBucketThu `json:"purchase_count"`
	OrderValue    []crmBucketThu `json:"order_value"`
	Gender        []crmBucketThu `json:"gender"`
	Age           []crmBucketThu `json:"age"`
	RegDaily      []crmBucketThu `json:"reg_daily"`
	Growth        []struct {
		Month string `json:"month"`
		New   int64  `json:"new"`
		Total int64  `json:"total"`
	} `json:"growth"`
	Ranks []struct {
		RankID uint  `json:"rank_id"`
		New    int64 `json:"new"`
	} `json:"ranks"`
	Care struct {
		Birthdays7d int64   `json:"birthdays_7d"`
		Debtors     int64   `json:"debtors"`
		DebtAmount  float64 `json:"debt_amount"`
	} `json:"care"`
}

func docCrm(t *testing.T, h *heThong, c *cuaHang, them string) crmThu {
	t.Helper()
	hom := homNayDT()
	res := h.goi(t, c.token, http.MethodGet, "/api/v1/admin/reports/crm?shop_id=0&from="+hom+"&to="+hom+them, nil)
	if res.ma != http.StatusOK {
		t.Fatalf("báo cáo CRM trả %d\n%s", res.ma, catBot(res.than))
	}
	var out struct {
		Data crmThu `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &out); err != nil {
		t.Fatalf("không đọc được báo cáo CRM: %v\n%s", err, catBot(res.than))
	}

	return out.Data
}

func demKey(ds []crmBucketThu, key string) int64 {
	for _, b := range ds {
		if b.Key == key {
			return b.Count
		}
	}
	return -1
}

func TestCrmTongQuan(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	vet := fmt.Sprintf("crm%d", time.Now().UnixNano()%1e9)

	taoKhach := func(than map[string]any) uint {
		t.Helper()
		than["status"] = "active"
		r := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/customers", than)
		if r.ma != http.StatusCreated && r.ma != http.StatusOK {
			t.Fatalf("tạo khách trả %d\n%s", r.ma, catBot(r.than))
		}
		return uint(doc(t, r)["id"].(float64))
	}
	// Cửa hàng mẫu đã có sẵn khách và đơn trong ngày — so với ảnh chụp trước khi
	// tạo dữ liệu của bài này, chỉ soi phần chênh.
	goc := docCrm(t, h, a, "")
	gocDN := docCrm(t, h, a, "&customer_type=company")
	gocCN := docCrm(t, h, a, "&customer_type=individual")
	tang := func(sau, truoc []crmBucketThu, key string) int64 { return demKey(sau, key) - demKey(truoc, key) }

	// An: nữ, sinh hôm nay 31 năm trước → sinh nhật trong 7 ngày tới, 25–35 tuổi.
	sinh := time.Now().AddDate(-31, 0, 0).Format("2006-01-02")
	an := taoKhach(map[string]any{"full_name": vet + " An", "gender": "female", "date_of_birth": sinh})
	binh := taoKhach(map[string]any{"full_name": vet + " Bình", "gender": "male"})
	cty := taoKhach(map[string]any{"full_name": vet + " Công ty", "customer_type": 1})

	ban := func(khach uint) uint {
		t.Helper()
		r := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/orders/pos", map[string]any{
			"payment_method": "cash", "user_id": khach,
			"items": []map[string]any{{"product_variant_id": a.bienThe, "quantity": 1}},
		})
		if r.ma != http.StatusCreated {
			t.Fatalf("bán trả %d\n%s", r.ma, catBot(r.than))
		}
		var kq struct {
			Data struct {
				OrderID uint `json:"order_id"`
			} `json:"data"`
		}
		_ = json.Unmarshal([]byte(r.than), &kq)
		return kq.Data.OrderID
	}
	ban(an)
	ban(an)
	donBinh := ban(binh)
	ban(cty)
	ban(0) // khách lẻ

	// Đơn của Bình thành ghi nợ.
	ctx := tenant.WithID(context.Background(), a.id)
	if err := h.db.WithContext(ctx).Model(&domain.Order{}).Where("id = ?", donBinh).
		Update("payment_status", domain.OrderPaymentPending).Error; err != nil {
		t.Fatalf("không chuyển được đơn sang ghi nợ: %v", err)
	}

	bc := docCrm(t, h, a, "")
	k, k0 := bc.Kpis, goc.Kpis
	if k.Orders-k0.Orders != 5 {
		t.Fatalf("thêm 5 đơn (kể cả khách lẻ), đang là %d → %d", k0.Orders, k.Orders)
	}
	gia := (k.Revenue - k0.Revenue) / 5
	if k.Buyers-k0.Buyers != 3 || k.Once-k0.Once != 2 || k.Twice-k0.Twice != 1 {
		t.Fatalf("thêm 3 khách mua: 2 khách 1 lần, 1 khách 2 lần — trước %+v, sau %+v", k0, k)
	}
	if k.NewCustomers-k0.NewCustomers != 3 {
		t.Fatalf("thêm 3 hồ sơ mới, đang là %d → %d", k0.NewCustomers, k.NewCustomers)
	}
	if tang(bc.PurchaseCount, goc.PurchaseCount, "1") != 2 || tang(bc.PurchaseCount, goc.PurchaseCount, "2") != 1 {
		t.Fatalf("số lần mua sai: trước %+v, sau %+v", goc.PurchaseCount, bc.PurchaseCount)
	}
	if tang(bc.VisitGap, goc.VisitGap, "1w") != 1 || tang(bc.VisitGap, goc.VisitGap, "none") != 2 {
		t.Fatalf("An quay lại trong ngày → 'trong 1 tuần'; hai khách chưa quay lại — trước %+v, sau %+v", goc.VisitGap, bc.VisitGap)
	}
	timTop := func(ds []crmTopThu, id uint) *crmTopThu {
		for i := range ds {
			if ds[i].UserID == id {
				return &ds[i]
			}
		}
		return nil
	}
	if r := timTop(bc.TopSpend, an); r == nil || r.Value != 2*gia || r.Orders != 2 {
		t.Fatalf("top chi tiêu phải có An 2 đơn / %v — đang là %+v", 2*gia, bc.TopSpend)
	}
	if r := timTop(bc.TopDebt, binh); r == nil || r.Value != gia {
		t.Fatalf("top công nợ phải có Bình nợ %v — đang là %+v", gia, bc.TopDebt)
	}
	if timTop(bc.TopDebt, an) != nil {
		t.Fatalf("An đã trả đủ, không được nằm trong top công nợ")
	}
	if tang(bc.Gender, goc.Gender, "female") != 1 || tang(bc.Gender, goc.Gender, "male") != 1 || tang(bc.Gender, goc.Gender, "other") != 1 {
		t.Fatalf("giới tính sai: trước %+v, sau %+v", goc.Gender, bc.Gender)
	}
	if tang(bc.Age, goc.Age, "25-35") != 1 {
		t.Fatalf("An 31 tuổi vào nhóm 25–35: trước %+v, sau %+v", goc.Age, bc.Age)
	}
	if tang(bc.OrderValue, goc.OrderValue, "duoi_500k") != 5 {
		t.Fatalf("thêm 5 đơn dưới 500k: trước %+v, sau %+v", goc.OrderValue, bc.OrderValue)
	}
	if bc.Care.Birthdays7d-goc.Care.Birthdays7d != 1 || bc.Care.Debtors-goc.Care.Debtors != 1 || bc.Care.DebtAmount-goc.Care.DebtAmount != gia {
		t.Fatalf("cần chăm sóc: thêm 1 sinh nhật, 1 khách nợ %v — trước %+v, sau %+v", gia, goc.Care, bc.Care)
	}
	if n := len(bc.RegDaily); n == 0 || bc.RegDaily[n-1].Count-goc.RegDaily[n-1].Count != 3 {
		t.Fatalf("đăng ký mới hôm nay phải tăng 3: %+v", bc.RegDaily)
	}
	if n := len(bc.Growth); n == 0 || bc.Growth[n-1].New-goc.Growth[n-1].New != 3 || bc.Growth[n-1].Total-goc.Growth[n-1].Total != 3 {
		t.Fatalf("tăng trưởng tháng này phải tăng 3: %+v", bc.Growth)
	}
	if len(bc.Ranks) == 0 || bc.Ranks[0].RankID != 0 || bc.Ranks[0].New-goc.Ranks[0].New != 3 {
		t.Fatalf("ba khách mới chưa có hạng: %+v", bc.Ranks)
	}

	dn := docCrm(t, h, a, "&customer_type=company")
	if dn.Kpis.Orders-gocDN.Kpis.Orders != 1 || dn.Kpis.Buyers-gocDN.Kpis.Buyers != 1 || dn.Kpis.NewCustomers-gocDN.Kpis.NewCustomers != 1 {
		t.Fatalf("lọc doanh nghiệp: thêm 1 đơn, 1 khách, 1 hồ sơ — trước %+v, sau %+v", gocDN.Kpis, dn.Kpis)
	}
	for _, b := range dn.Age {
		if b.Count != 0 {
			t.Fatalf("doanh nghiệp không có độ tuổi: %+v", dn.Age)
		}
	}

	cn := docCrm(t, h, a, "&customer_type=individual")
	if cn.Kpis.Orders-gocCN.Kpis.Orders != 3 || cn.Kpis.Buyers-gocCN.Kpis.Buyers != 2 {
		t.Fatalf("lọc cá nhân: thêm 3 đơn của An + Bình — trước %+v, sau %+v", gocCN.Kpis, cn.Kpis)
	}

	// Kỳ năm 2020: không đơn nào.
	cu := h.goi(t, a.token, http.MethodGet, "/api/v1/admin/reports/crm?shop_id=0&from=2020-01-01&to=2020-01-31", nil)
	var bcCu struct {
		Data crmThu `json:"data"`
	}
	_ = json.Unmarshal([]byte(cu.than), &bcCu)
	if cu.ma != http.StatusOK || bcCu.Data.Kpis.Orders != 0 || len(bcCu.Data.TopSpend) != 0 {
		t.Fatalf("kỳ 2020 phải trống: %d %+v", cu.ma, bcCu.Data.Kpis)
	}
	_ = cty
}
