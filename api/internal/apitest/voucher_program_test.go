package apitest

import (
	"encoding/json"
	"fmt"
	"net/http"
	"strings"
	"testing"
)

// vcThan dựng thân một chương trình Coupon 10% (trần 5.000đ), mỗi mã dùng 1 lần.
func vcThan(a *cuaHang, danhMuc []uint, phatHanh bool) map[string]any {
	return map[string]any{
		"name": "Voucher " + a.vet, "discount_type": "percentage", "discount_value": 10,
		"max_discount_amount": 5000, "min_order_amount": 0,
		"all_shops": true, "all_categories": len(danhMuc) == 0, "category_ids": danhMuc,
		"no_time_limit": true, "prefix": "ab", "suffix": "z", "quantity": 3, "usage_limit": 1,
		"release": phatHanh,
	}
}

type vcThu struct {
	ID     uint   `json:"id"`
	Code   string `json:"code"`
	Status int    `json:"status"`
}

func docVC(t *testing.T, r traLoi) vcThu {
	t.Helper()
	var out struct {
		Data vcThu `json:"data"`
	}
	_ = json.Unmarshal([]byte(r.than), &out)

	return out.Data
}

type maThu struct {
	ID       uint   `json:"id"`
	Code     string `json:"code"`
	IsActive bool   `json:"is_active"`
}

func docMa(t *testing.T, h *heThong, token string, id uint) []maThu {
	t.Helper()
	r := h.goi(t, token, http.MethodGet, fmt.Sprintf("/api/v1/admin/voucher-coupon/%d/ma", id), nil)
	var out struct {
		Data []maThu `json:"data"`
	}
	_ = json.Unmarshal([]byte(r.than), &out)

	return out.Data
}

// TestVoucherCoupon_VongDoi — như v2: Lưu thì chưa có mã, còn sửa / xoá được;
// Phát hành thì sinh đủ mã (tiền tố + 5 ký tự + hậu tố) và khoá chương trình.
func TestVoucherCoupon_VongDoi(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	goc := "/api/v1/admin/voucher-coupon"

	luu := h.goi(t, a.token, http.MethodPost, goc, vcThan(a, nil, false))
	if luu.ma != http.StatusCreated {
		t.Fatalf("lưu chương trình trả %d\n%s", luu.ma, catBot(luu.than))
	}
	p := docVC(t, luu)
	if !strings.HasPrefix(p.Code, "VC") || p.Status != 1 {
		t.Fatalf("mã chương trình phải do hệ thống cấp (VC…) và ở Chưa phát hành, đang là %+v", p)
	}
	if ds := docMa(t, h, a.token, p.ID); len(ds) != 0 {
		t.Fatalf("chưa phát hành thì chưa có mã, đang có %d", len(ds))
	}
	duong := fmt.Sprintf("%s/%d", goc, p.ID)
	if r := h.goi(t, a.token, http.MethodPut, duong, vcThan(a, nil, false)); r.ma != http.StatusOK {
		t.Fatalf("chưa phát hành thì sửa được, đang là %d\n%s", r.ma, catBot(r.than))
	}
	if r := h.goi(t, a.token, http.MethodDelete, duong, nil); r.ma != http.StatusOK {
		t.Fatalf("chưa phát hành thì xoá được, đang là %d", r.ma)
	}

	quaTram := vcThan(a, nil, true)
	quaTram["discount_value"] = 120
	if r := h.goi(t, a.token, http.MethodPost, goc, quaTram); r.ma != http.StatusUnprocessableEntity {
		t.Fatalf("coupon trên 100%% phải 422, đang là %d", r.ma)
	}

	ph := h.goi(t, a.token, http.MethodPost, goc, vcThan(a, nil, true))
	if ph.ma != http.StatusCreated {
		t.Fatalf("phát hành trả %d\n%s", ph.ma, catBot(ph.than))
	}
	p = docVC(t, ph)
	ma := docMa(t, h, a.token, p.ID)
	if p.Status != 2 || len(ma) != 3 {
		t.Fatalf("phát hành phải sinh đủ 3 mã, đang trạng thái %d, %d mã", p.Status, len(ma))
	}
	for _, m := range ma {
		if len(m.Code) != 8 || !strings.HasPrefix(m.Code, "AB") || !strings.HasSuffix(m.Code, "Z") || !m.IsActive {
			t.Fatalf("mã phải là AB + 5 ký tự + Z và đang bật, đang là %+v", m)
		}
	}
	duong = fmt.Sprintf("%s/%d", goc, p.ID)
	if r := h.goi(t, a.token, http.MethodPut, duong, vcThan(a, nil, false)); r.ma != http.StatusConflict {
		t.Fatalf("đã phát hành thì không sửa được (409), đang là %d", r.ma)
	}
	if r := h.goi(t, a.token, http.MethodDelete, duong, nil); r.ma != http.StatusConflict {
		t.Fatalf("đã phát hành thì không xoá được (409), đang là %d", r.ma)
	}
	// Mã do chương trình phát ra không đổ vào màn Mã giảm giá (mã lẻ).
	if r := h.goi(t, a.token, http.MethodGet, "/api/v1/admin/vouchers?keyword="+ma[0].Code, nil); !strings.Contains(r.than, `"total":0`) {
		t.Fatalf("mã của chương trình không được hiện ở danh sách mã lẻ\n%s", catBot(r.than))
	}
}

// TestVoucherCoupon_TaiQuay — mã phát hành dùng được ở quầy như mọi mã giảm giá;
// tắt mã thì hết dùng; mã theo danh mục chỉ giảm tiền hàng của danh mục đó.
func TestVoucherCoupon_TaiQuay(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	goc := "/api/v1/admin/voucher-coupon"
	ban := func(code string) traLoi {
		return h.goi(t, a.token, http.MethodPost, "/api/v1/admin/orders/pos", map[string]any{
			"payment_method": "bank_transfer", "voucher_code": code,
			"items": []map[string]any{{"product_variant_id": a.bienThe, "quantity": 2}},
		})
	}

	p := docVC(t, h.goi(t, a.token, http.MethodPost, goc, vcThan(a, []uint{a.danhMuc}, true)))
	ma := docMa(t, h, a.token, p.ID)
	if len(ma) != 3 {
		t.Fatalf("phải có 3 mã, đang có %d", len(ma))
	}

	r := ban(ma[0].Code)
	if r.ma != http.StatusCreated {
		t.Fatalf("bán kèm mã trả %d\n%s", r.ma, catBot(r.than))
	}
	var kq struct {
		Data struct {
			Discount float64 `json:"discount_amount"`
		} `json:"data"`
	}
	_ = json.Unmarshal([]byte(r.than), &kq)
	if kq.Data.Discount != 5000 {
		t.Fatalf("10%% của 200.000 là 20.000 nhưng trần 5.000, đang giảm %v", kq.Data.Discount)
	}
	if r := ban(ma[0].Code); r.ma != http.StatusUnprocessableEntity {
		t.Fatalf("mã dùng 1 lần thì lượt thứ hai phải 422, đang là %d", r.ma)
	}
	ls := h.goi(t, a.token, http.MethodGet, fmt.Sprintf("/api/v1/admin/voucher-coupon-ma/%d/lich-su", ma[0].ID), nil)
	if !strings.Contains(ls.than, `"order_code"`) || strings.Count(ls.than, `"order_id"`) != 1 {
		t.Fatalf("lịch sử mã phải có đúng 1 lượt dùng\n%s", catBot(ls.than))
	}

	if r := h.goi(t, a.token, http.MethodPut, fmt.Sprintf("/api/v1/admin/voucher-coupon-ma/%d/status", ma[1].ID), map[string]any{"is_active": false}); r.ma != http.StatusOK {
		t.Fatalf("tắt mã trả %d", r.ma)
	}
	if r := ban(ma[1].Code); r.ma != http.StatusUnprocessableEntity || !strings.Contains(r.than, "tạm dừng") {
		t.Fatalf("mã đã tắt phải 422 tạm dừng, đang là %d\n%s", r.ma, catBot(r.than))
	}

	khac := docVC(t, h.goi(t, a.token, http.MethodPost, goc, vcThan(a, []uint{a.danhMuc + 100000}, true)))
	if r := ban(docMa(t, h, a.token, khac.ID)[0].Code); r.ma != http.StatusUnprocessableEntity || !strings.Contains(r.than, "không áp dụng cho hàng") {
		t.Fatalf("mã của danh mục khác phải 422, đang là %d\n%s", r.ma, catBot(r.than))
	}
}
