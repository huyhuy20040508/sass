package apitest

import (
	"encoding/json"
	"fmt"
	"net/http"
	"strings"
	"testing"
	"time"
)

// Quà sinh nhật ở quầy (KB #24): mã dùng một lần cho đúng khách theo mẫu ở Cài
// đặt, mốc đã chúc giấu khách khỏi "Sinh nhật 7 ngày" tới mùa sau.

// KB SN01–SN09
func TestCRMQuay_QuaSinhNhat(t *testing.T) {
	h := dungHeThong(t)
	a, b := haiCuaHang(t, h)
	moCa(t, h, a, 0)
	thuNgan := h.dangNhapVoi(t, a.ma, "nhanvien")
	caiDat := func(m map[string]string) {
		if r := h.goi(t, a.token, http.MethodPut, "/api/v1/admin/settings", map[string]any{"items": m}); r.ma != http.StatusOK {
			t.Fatalf("lưu cài đặt %v trả %d %s", m, r.ma, catBot(r.than))
		}
	}
	khach := func(ten, sdt string, dob *time.Time) uint {
		body := map[string]any{"full_name": ten + " " + a.vet, "phone": sdt, "status": "active", "customer_type": 0}
		if dob != nil {
			body["date_of_birth"] = dob.Format("2006-01-02")
		}
		r := h.goi(t, thuNgan, http.MethodPost, "/api/v1/admin/orders/pos/khach-hang/day-du", body)
		if r.ma != http.StatusCreated {
			t.Fatalf("tạo khách trả %d %s", r.ma, catBot(r.than))
		}
		return uint(doc(t, r)["id"].(float64))
	}
	sn := func(soNgay int) *time.Time { d := time.Now().AddDate(-30, 0, soNgay); return &d }
	tang := func(tok string, id uint) traLoi {
		return h.goi(t, tok, http.MethodPost, fmt.Sprintf("/api/v1/admin/orders/pos/khach-hang/%d/ma-sinh-nhat", id), nil)
	}
	locSN := func(boDaChuc bool) map[uint]bool {
		u := "/api/v1/admin/orders/pos/khach-hang?birthday_days=7&page_size=100"
		if boDaChuc {
			u += "&bo_da_chuc=1"
		}
		var body struct {
			Data []struct {
				ID uint `json:"id"`
			} `json:"data"`
		}
		rr := h.goi(t, thuNgan, http.MethodGet, u, nil)
		if rr.ma != http.StatusOK {
			t.Fatalf("lọc sinh nhật trả %d %s", rr.ma, catBot(rr.than))
		}
		_ = json.Unmarshal([]byte(rr.than), &body)
		out := map[uint]bool{}
		for _, x := range body.Data {
			out[x.ID] = true
		}
		return out
	}

	mai := khach("Khách sinh nhật", "0909 100 201", sn(3))
	khac := khach("Khách khác", "0909 100 202", nil)
	xa := khach("Khách sinh nhật xa", "0909 100 203", sn(60))
	chuc := khach("Khách chỉ chúc", "0909 100 204", sn(5))

	// SN01 mẫu chưa khai (mức giảm 0 mặc định) → 422, không ghi mốc.
	if r := tang(thuNgan, mai); r.ma != http.StatusUnprocessableEntity || !strings.Contains(r.than, "Cài đặt") {
		t.Fatalf("SN01 chưa khai mẫu phải 422 chỉ sang Cài đặt, nhận %d %s", r.ma, catBot(r.than))
	}
	if !locSN(true)[mai] {
		t.Fatalf("SN01 tạo mã hỏng thì khách vẫn ở danh sách")
	}

	// SN07 mẫu phần trăm > 100 → 422.
	caiDat(map[string]string{"birthday_voucher_type": "percentage", "birthday_voucher_value": "150"})
	if r := tang(thuNgan, mai); r.ma != http.StatusUnprocessableEntity {
		t.Fatalf("SN07 mẫu 150%% phải 422, nhận %d", r.ma)
	}

	// SN02 mẫu 10%, tối đa 50.000, hạn 30 ngày → mã SNxxxxxx một lượt, đúng mẫu.
	caiDat(map[string]string{"birthday_voucher_value": "10", "birthday_voucher_max": "50000", "birthday_voucher_min_order": "0", "birthday_voucher_days": "30"})
	r := tang(thuNgan, mai)
	if r.ma != http.StatusCreated {
		t.Fatalf("SN02 tạo mã phải 201, nhận %d %s", r.ma, catBot(r.than))
	}
	var qua struct {
		Data struct {
			VoucherID *uint `json:"voucher_id"`
			Voucher   struct {
				Code          string   `json:"code"`
				Description   string   `json:"description"`
				DiscountType  string   `json:"discount_type"`
				DiscountValue float64  `json:"discount_value"`
				MaxDiscount   *float64 `json:"max_discount_amount"`
				UsageLimit    *uint    `json:"usage_limit"`
				IsPublic      bool     `json:"is_public"`
				EndAt         string   `json:"end_at"`
			} `json:"voucher"`
		} `json:"data"`
	}
	_ = json.Unmarshal([]byte(r.than), &qua)
	v := qua.Data.Voucher
	hetHan := time.Now().AddDate(0, 0, 29).Format("2006-01-02")
	if !strings.HasPrefix(v.Code, "SN") || len(v.Code) != 8 || v.DiscountType != "percentage" || v.DiscountValue != 10 ||
		v.MaxDiscount == nil || *v.MaxDiscount != 50000 || v.UsageLimit == nil || *v.UsageLimit != 1 || v.IsPublic ||
		!strings.HasPrefix(v.EndAt, hetHan) || !strings.Contains(v.Description, "Khách sinh nhật") {
		t.Fatalf("SN02 mã sai mẫu (hạn phải hết ngày %s): %+v", hetHan, v)
	}

	// SN03 đã tặng → rời danh sách có bo_da_chuc, danh sách Quản trị (không cờ) vẫn có.
	if l := locSN(true); l[mai] || !l[chuc] || l[xa] {
		t.Fatalf("SN03 lọc quầy: đã tặng phải rời, chưa chúc phải còn, sinh nhật xa không vào: %v", l)
	}
	if !locSN(false)[mai] {
		t.Fatalf("SN03 không có bo_da_chuc thì giữ nguyên danh sách cũ")
	}

	// SN04 tặng lần hai trong mùa → 409 kèm mã cũ.
	if r := tang(thuNgan, mai); r.ma != http.StatusConflict || !strings.Contains(r.than, v.Code) {
		t.Fatalf("SN04 tặng lại phải 409 kèm mã cũ %s, nhận %d %s", v.Code, r.ma, catBot(r.than))
	}

	// SN05 ngoài mùa sinh nhật / chưa khai ngày sinh → 422.
	for _, id := range []uint{xa, khac} {
		if r := tang(thuNgan, id); r.ma != http.StatusUnprocessableEntity {
			t.Fatalf("SN05 khách %d ngoài mùa phải 422, nhận %d", id, r.ma)
		}
	}

	// SN06 mã chỉ của đúng khách: xem trước sai khách / chưa chọn khách → 422; đúng khách → bán được, một lượt.
	gio := []map[string]any{{"product_variant_id": a.bienThe, "quantity": 1}}
	xem := func(uid uint) traLoi {
		return h.goi(t, thuNgan, http.MethodPost, "/api/v1/admin/orders/pos/voucher", map[string]any{"items": gio, "code": v.Code, "user_id": uid})
	}
	for _, uid := range []uint{khac, 0} {
		if r := xem(uid); r.ma != http.StatusUnprocessableEntity || !strings.Contains(r.than, "quà riêng") {
			t.Fatalf("SN06 áp mã cho khách %d phải 422 'quà riêng', nhận %d %s", uid, r.ma, catBot(r.than))
		}
	}
	if r := xem(mai); r.ma != http.StatusOK {
		t.Fatalf("SN06 đúng khách xem trước phải 200, nhận %d %s", r.ma, catBot(r.than))
	}
	ban := func(uid uint) traLoi {
		return h.goi(t, thuNgan, http.MethodPost, "/api/v1/admin/orders/pos", map[string]any{
			"payment_method": "cash", "amount_tendered": 10000000, "voucher_code": v.Code, "items": gio, "user_id": uid})
	}
	if r := ban(khac); r.ma == http.StatusCreated {
		t.Fatalf("SN06 chốt đơn khách khác với mã riêng phải bị chặn")
	}
	if r := ban(mai); r.ma != http.StatusCreated || doc(t, r)["discount_amount"].(float64) <= 0 {
		t.Fatalf("SN06 đúng khách chốt đơn phải 201 có giảm, nhận %d %s", r.ma, catBot(r.than))
	}
	if r := ban(mai); r.ma == http.StatusCreated {
		t.Fatalf("SN06 mã một lượt không dùng lần hai được")
	}

	// SN09 Đã chúc (không mã) → 201 voucher rỗng, khách rời danh sách; chúc lại → 409.
	r = h.goi(t, thuNgan, http.MethodPost, fmt.Sprintf("/api/v1/admin/orders/pos/khach-hang/%d/da-chuc", chuc), nil)
	if r.ma != http.StatusCreated || doc(t, r)["voucher_id"] != nil {
		t.Fatalf("SN09 đã chúc phải 201 không mã, nhận %d %s", r.ma, catBot(r.than))
	}
	if locSN(true)[chuc] {
		t.Fatalf("SN09 đã chúc phải rời danh sách")
	}
	if r := tang(thuNgan, chuc); r.ma != http.StatusConflict {
		t.Fatalf("SN09 đã chúc rồi tặng mã phải 409, nhận %d", r.ma)
	}

	// SN08 cửa hàng khác không tặng được cho khách của a.
	if r := tang(b.token, mai); r.ma != http.StatusNotFound {
		t.Fatalf("SN08 cửa hàng khác phải 404, nhận %d", r.ma)
	}
}
