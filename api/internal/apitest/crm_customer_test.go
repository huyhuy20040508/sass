package apitest

import (
	"encoding/json"
	"fmt"
	"net/http"
	"net/url"
	"testing"
	"time"
)

type khachCRM struct {
	ID                uint    `json:"id"`
	FullName          string  `json:"full_name"`
	TotalOrders       int64   `json:"total_orders"`
	TotalSpent        float64 `json:"total_spent"`
	LastPaymentAmount float64 `json:"last_payment_amount"`
	LastPaymentAt     string  `json:"last_payment_at"`
}

// docKhachCRM gọi danh sách khách với truy vấn cho trước, chỉ giữ khách mang dấu vết `vet`.
func docKhachCRM(t *testing.T, h *heThong, c *cuaHang, truyVan url.Values, vet string) []khachCRM {
	t.Helper()

	truyVan.Set("keyword", vet)
	truyVan.Set("page_size", "100")
	res := h.goi(t, c.token, http.MethodGet, "/api/v1/admin/customers?"+truyVan.Encode(), nil)
	if res.ma != http.StatusOK {
		t.Fatalf("danh sách khách trả %d\n%s", res.ma, catBot(res.than))
	}
	var out struct {
		Data []khachCRM `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &out); err != nil {
		t.Fatalf("không đọc được danh sách khách: %v", err)
	}

	return out.Data
}

func tenKhach(ds []khachCRM) []string {
	out := make([]string, 0, len(ds))
	for _, k := range ds {
		out = append(out, k.FullName)
	}

	return out
}

// TestCRMKhachHang_BoLocVaCotMoi — khung lọc của màn CRM → Khách hàng: giới tính
// (Khác gồm cả chưa khai), tuổi, sinh nhật sắp tới, địa chỉ, giao dịch gần nhất;
// sắp xếp theo số đơn; và cột "Thanh toán gần nhất" lấy cả đơn quầy chưa ghi sổ thu.
func TestCRMKhachHang_BoLocVaCotMoi(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	vet := fmt.Sprintf("crm%d", time.Now().UnixNano()%1e9)
	homNay := time.Now()

	tao := func(ten, gioiTinh string, ngaySinh time.Time, diaChi string) uint {
		body := map[string]any{"full_name": vet + " " + ten, "status": "active", "address": diaChi}
		if gioiTinh != "" {
			body["gender"] = gioiTinh
		}
		if !ngaySinh.IsZero() {
			body["date_of_birth"] = ngaySinh.Format("2006-01-02")
		}
		res := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/customers", body)
		if res.ma != http.StatusCreated && res.ma != http.StatusOK {
			t.Fatalf("tạo khách %s trả %d\n%s", ten, res.ma, catBot(res.than))
		}
		var out struct {
			Data struct {
				ID uint `json:"id"`
			} `json:"data"`
		}
		_ = json.Unmarshal([]byte(res.than), &out)

		return out.Data.ID
	}

	// An: nam, 30 tuổi, sinh nhật 3 ngày tới, ở Hà Nội, mua 2 đơn quầy.
	an := tao("An", "male", homNay.AddDate(-30, 0, 3), "1 Tràng Tiền, Hà Nội")
	// Bình: nữ, 20 tuổi, sinh nhật 2 tháng trước.
	tao("Binh", "female", homNay.AddDate(-20, -2, 0), "Quận 1, TP HCM")
	// Chi: chưa khai giới tính lẫn ngày sinh.
	tao("Chi", "", time.Time{}, "")

	for i := 0; i < 2; i++ {
		ban := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/orders/pos", map[string]any{
			"payment_method": "bank_transfer", "user_id": an,
			"items": []map[string]any{{"product_variant_id": a.bienThe, "quantity": 1}},
		})
		if ban.ma != http.StatusCreated {
			t.Fatalf("bán cho An trả %d\n%s", ban.ma, catBot(ban.than))
		}
	}

	// Sổ đơn (màn CRM → Danh sách đơn hàng) mang mã hồ sơ của khách mua.
	var hoSo struct {
		Data struct {
			Code string `json:"customer_code"`
		} `json:"data"`
	}
	_ = json.Unmarshal([]byte(h.goi(t, a.token, http.MethodGet, fmt.Sprintf("/api/v1/admin/customers/%d", an), nil).than), &hoSo)
	so := h.goi(t, a.token, http.MethodGet, "/api/v1/admin/orders/so-don?page_size=100", nil)
	var soDon struct {
		Data []struct {
			MaKhach string `json:"ma_khach"`
		} `json:"data"`
	}
	_ = json.Unmarshal([]byte(so.than), &soDon)
	dem := 0
	for _, d := range soDon.Data {
		if hoSo.Data.Code != "" && d.MaKhach == hoSo.Data.Code {
			dem++
		}
	}
	if dem != 2 {
		t.Fatalf("hai đơn của An (mã %q) phải mang mã khách trên sổ đơn, đếm được %d", hoSo.Data.Code, dem)
	}

	kiem := func(moTa string, q url.Values, muon ...string) []khachCRM {
		t.Helper()
		ds := docKhachCRM(t, h, a, q, vet)
		co := tenKhach(ds)
		if len(co) != len(muon) {
			t.Fatalf("%s: muốn %v, được %v", moTa, muon, co)
		}
		for i := range muon {
			if co[i] != vet+" "+muon[i] {
				t.Fatalf("%s: muốn %v, được %v", moTa, muon, co)
			}
		}

		return ds
	}

	kiem("giới tính Khác gồm khách chưa khai", url.Values{"genders": {"other"}}, "Chi")
	kiem("bỏ tick hết giới tính là bảng rỗng", url.Values{"genders": {""}})
	kiem("tuổi 25-35", url.Values{"age_from": {"25"}, "age_to": {"35"}}, "An")
	kiem("sinh nhật 7 ngày tới", url.Values{"birthday_days": {"7"}}, "An")
	kiem("địa chỉ", url.Values{"address": {"HCM"}}, "Binh")
	kiem("giao dịch gần nhất 7 ngày qua", url.Values{"last_tx_days": {"7"}}, "An")

	ds := kiem("sắp theo số đơn nhiều nhất", url.Values{"sort": {"orders_desc"}, "genders": {"male"}}, "An")
	if ds[0].TotalOrders != 2 {
		t.Fatalf("An phải có 2 đơn, đang là %d", ds[0].TotalOrders)
	}
	// Đơn quầy đã thu đủ nhưng không ghi sổ thu: vẫn là một lượt thanh toán.
	if ds[0].LastPaymentAmount <= 0 || ds[0].LastPaymentAt == "" {
		t.Fatalf("thanh toán gần nhất của An phải có, đang là %v / %q", ds[0].LastPaymentAmount, ds[0].LastPaymentAt)
	}
}
