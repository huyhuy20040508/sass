package apitest

import (
	"encoding/json"
	"fmt"
	"net/http"
	"strings"
	"testing"
)

type ctkmThu struct {
	ID       uint   `json:"id"`
	Code     string `json:"code"`
	Approved bool   `json:"approved"`
	Used     int    `json:"used"`
}

func docCTKM(t *testing.T, r traLoi) ctkmThu {
	t.Helper()
	var out struct {
		Data ctkmThu `json:"data"`
	}
	if err := json.Unmarshal([]byte(r.than), &out); err != nil {
		t.Fatalf("không đọc được chương trình: %v\n%s", err, catBot(r.than))
	}

	return out.Data
}

func ctkmThan(a *cuaHang, ten string, loai int, duyet bool, dong []map[string]any) map[string]any {
	return map[string]any{
		"name": ten + " " + a.vet, "type": loai, "status": true, "approved": duyet, "no_time_limit": true,
		"days_of_week": []int{1, 2, 3, 4, 5, 6, 7}, "all_shops": true, "details": dong,
	}
}

// TestChuongTrinhKhuyenMai_V2 — vòng đời và cách tính của v2: bậc cao nhất đạt
// được, "tiền" trừ thẳng, "%" có trần, hàng tặng 0đ, lượt dùng, không xoá được
// khi đã dùng, kiểm trùng khi Duyệt, huỷ duyệt mới sửa, không dùng cùng đồng giá.
func TestChuongTrinhKhuyenMai_V2(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	goc := "/api/v1/admin/chuong-trinh-khuyen-mai"

	// Loại 0 — phiếu bán hàng: bậc thấp (đơn ≥ 10.000đ) giảm 5.000đ + tặng 1 cái; bậc cao không ai đạt.
	p0r := h.goi(t, a.token, http.MethodPost, goc, ctkmThan(a, "Phieu", 0, true, []map[string]any{
		{"total_apply": 10000, "formality": 1, "value": 5000, "gifts": []map[string]any{{"product_variant_id": a.bienThe, "quantity": 1}}},
		{"total_apply": 900000000, "formality": 1, "value": 99000},
	}))
	if p0r.ma != http.StatusCreated {
		t.Fatalf("tạo chương trình loại 0 trả %d\n%s", p0r.ma, p0r.than)
	}
	p0 := docCTKM(t, p0r)
	if !strings.HasPrefix(p0.Code, "CTKM") {
		t.Fatalf("mã phải do hệ thống cấp (CTKM…), đang là %q", p0.Code)
	}

	// Loại 3 — danh sách hàng: mua từ 2 cái sản phẩm mẫu giảm 10%, tối đa 3.000đ.
	p3r := h.goi(t, a.token, http.MethodPost, goc, ctkmThan(a, "Mon", 3, true, []map[string]any{
		{"object_id": a.sanPham, "quantity": 2, "formality": 0, "value": 10, "max_value": 3000},
	}))
	if p3r.ma != http.StatusCreated {
		t.Fatalf("tạo chương trình loại 3 trả %d\n%s", p3r.ma, catBot(p3r.than))
	}
	p3 := docCTKM(t, p3r)

	// Cùng món, cùng lúc, đang bật: Duyệt thì bị chặn; Lưu tạm thì được (như v2).
	trung := h.goi(t, a.token, http.MethodPost, goc, ctkmThan(a, "Trung", 3, true, []map[string]any{
		{"object_id": a.sanPham, "quantity": 5, "formality": 1, "value": 1000},
	}))
	if trung.ma != http.StatusUnprocessableEntity || !strings.Contains(trung.than, "Đã tồn tại chương trình") {
		t.Fatalf("trùng món khi Duyệt phải 422, đang là %d\n%s", trung.ma, catBot(trung.than))
	}
	luuTam := h.goi(t, a.token, http.MethodPost, goc, ctkmThan(a, "Trung", 3, false, []map[string]any{
		{"object_id": a.sanPham, "quantity": 5, "formality": 1, "value": 1000},
	}))
	if luuTam.ma != http.StatusCreated {
		t.Fatalf("Lưu tạm thì không kiểm trùng, đang là %d\n%s", luuTam.ma, catBot(luuTam.than))
	}

	xem := func(sl int, chon []uint) (ids []uint, giam float64, qua int) {
		r := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/orders/pos/khuyen-mai", map[string]any{
			"items": []map[string]any{{"product_variant_id": a.bienThe, "quantity": sl}}, "program_ids": chon,
		})
		var out struct {
			Data struct {
				ChuongTrinh []struct {
					ID uint `json:"id"`
				} `json:"chuong_trinh"`
				Giam float64 `json:"giam"`
				Qua  []struct {
					Quantity int `json:"quantity"`
				} `json:"qua"`
			} `json:"data"`
		}
		_ = json.Unmarshal([]byte(r.than), &out)
		for _, c := range out.Data.ChuongTrinh {
			ids = append(ids, c.ID)
		}
		for _, q := range out.Data.Qua {
			qua += q.Quantity
		}

		return ids, out.Data.Giam, qua
	}

	if ids, _, _ := xem(1, nil); fmt.Sprint(ids) != fmt.Sprint([]uint{p0.ID}) {
		t.Fatalf("mua 1 cái: chỉ chương trình loại 0 đủ điều kiện, đang ra %v", ids)
	}
	if _, giam, qua := xem(2, []uint{p0.ID, p3.ID}); giam != 8000 || qua != 1 {
		t.Fatalf("chọn cả hai: giảm 5.000 + 3.000 (trần) = 8.000, tặng 1 — đang là %v / %d", giam, qua)
	}

	ban := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/orders/pos", map[string]any{
		"payment_method": "bank_transfer", "promotion_program_ids": []uint{p0.ID, p3.ID},
		"items": []map[string]any{{"product_variant_id": a.bienThe, "quantity": 2}},
	})
	if ban.ma != http.StatusCreated {
		t.Fatalf("bán có khuyến mãi trả %d\n%s", ban.ma, catBot(ban.than))
	}
	var kq struct {
		Data struct {
			OrderID           uint    `json:"order_id"`
			PromotionDiscount float64 `json:"promotion_discount"`
		} `json:"data"`
	}
	_ = json.Unmarshal([]byte(ban.than), &kq)
	if kq.Data.PromotionDiscount != 8000 {
		t.Fatalf("lượt bán phải giảm đúng 8.000 như xem trước, đang là %v", kq.Data.PromotionDiscount)
	}
	don := donQuay(t, h, a, kq.Data.OrderID)
	if dong, _ := don["items"].([]any); len(dong) != 2 || dong[1].(map[string]any)["is_gift"] != true {
		t.Fatalf("đơn phải có 1 dòng bán + 1 dòng tặng, đang là %v", don["items"])
	}

	if u := docCTKM(t, h.goi(t, a.token, http.MethodGet, fmt.Sprintf("%s/%d", goc, p0.ID), nil)); u.Used != 1 {
		t.Fatalf("lượt dùng phải tăng lên 1, đang là %d", u.Used)
	}
	if r := h.goi(t, a.token, http.MethodDelete, fmt.Sprintf("%s/%d", goc, p0.ID), nil); r.ma != http.StatusConflict {
		t.Fatalf("đã có đơn dùng thì không xoá được (409), đang là %d", r.ma)
	}

	// Đã duyệt thì phải huỷ duyệt mới sửa.
	sua := ctkmThan(a, "Mon", 3, false, []map[string]any{{"object_id": a.sanPham, "quantity": 3, "formality": 0, "value": 5, "max_value": 0}})
	if r := h.goi(t, a.token, http.MethodPut, fmt.Sprintf("%s/%d", goc, p3.ID), sua); r.ma != http.StatusUnprocessableEntity {
		t.Fatalf("đã duyệt mà sửa phải 422, đang là %d", r.ma)
	}
	h.goi(t, a.token, http.MethodPost, fmt.Sprintf("%s/%d/huy-duyet", goc, p3.ID), nil)
	if r := h.goi(t, a.token, http.MethodPut, fmt.Sprintf("%s/%d", goc, p3.ID), sua); r.ma != http.StatusOK {
		t.Fatalf("huỷ duyệt rồi phải sửa được, đang là %d\n%s", r.ma, catBot(r.than))
	}

	nb := docCTKM(t, h.goi(t, a.token, http.MethodPost, fmt.Sprintf("%s/%d/nhan-ban", goc, p0.ID), nil))
	if nb.ID == 0 || nb.Code == p0.Code || nb.Approved {
		t.Fatalf("bản sao phải có mã mới và ở trạng thái Lưu tạm, đang là %+v", nb)
	}

	gop := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/orders/pos", map[string]any{
		"payment_method": "bank_transfer", "promotion_program_ids": []uint{p0.ID}, "fixed_price_ids": []uint{1},
		"items": []map[string]any{{"product_variant_id": a.bienThe, "quantity": 2}},
	})
	if gop.ma != http.StatusBadRequest {
		t.Fatalf("khuyến mãi cùng đồng giá phải bị chặn 400, đang là %d\n%s", gop.ma, catBot(gop.than))
	}
}
