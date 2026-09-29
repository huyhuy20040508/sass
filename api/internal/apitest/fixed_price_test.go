package apitest

import (
	"encoding/json"
	"fmt"
	"net/http"
	"strings"
	"testing"
	"time"
)

// dgThan dựng thân một chương trình đồng giá theo sản phẩm: mua từ 2 cái sản
// phẩm mẫu thì mỗi cái 1.000đ, tặng 1 cái biến thể mẫu.
func dgThan(a *cuaHang, ma string, duyet bool) map[string]any {
	return map[string]any{
		"name": "Đồng giá " + a.vet + " " + ma, "type": 2,
		"status": true, "approved": duyet, "no_time_limit": true,
		"days_of_week": []int{1, 2, 3, 4, 5, 6, 7}, "all_shops": true,
		"details": []map[string]any{{
			"object_id": a.sanPham, "quantity": 2, "price": 1000,
			"gifts": []map[string]any{{"product_variant_id": a.bienThe, "quantity": 1}},
		}},
	}
}

// TestDongGia_QuanTri — vòng đời của v2: lưu tạm / duyệt, đã duyệt thì không
// sửa, không xoá cho tới khi huỷ duyệt; hai chương trình đang bật cùng phủ một
// món thì chặn.
func TestDongGia_QuanTri(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	ma := fmt.Sprintf("DG%d", time.Now().UnixNano()%1e8)

	tao := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/dong-gia", dgThan(a, ma, true))
	if tao.ma != http.StatusCreated {
		t.Fatalf("tạo đồng giá trả %d\n%s", tao.ma, catBot(tao.than))
	}
	var ct struct {
		Data struct {
			ID      uint   `json:"id"`
			Code    string `json:"code"`
			Details []struct {
				ObjectName string `json:"object_name"`
				Gifts      []struct {
					Name string `json:"name"`
				} `json:"gifts"`
			} `json:"details"`
		} `json:"data"`
	}
	_ = json.Unmarshal([]byte(tao.than), &ct)
	if len(ct.Data.Details) != 1 || ct.Data.Details[0].ObjectName == "" || ct.Data.Details[0].Gifts[0].Name == "" {
		t.Fatalf("phản hồi phải kèm tên sản phẩm và tên hàng tặng\n%s", catBot(tao.than))
	}
	id := ct.Data.ID
	if !strings.HasPrefix(ct.Data.Code, "DG") {
		t.Fatalf("mã đồng giá phải do hệ thống cấp (DG…), đang là %q", ct.Data.Code)
	}

	if r := h.goi(t, a.token, http.MethodGet, "/api/v1/admin/dong-gia/ma", nil); r.ma != http.StatusOK || !strings.Contains(r.than, ct.Data.Code) {
		t.Fatalf("danh sách mã phải có %s, đang là %d\n%s", ct.Data.Code, r.ma, catBot(r.than))
	}
	if r := h.goi(t, a.token, http.MethodGet, "/api/v1/admin/dong-gia?codes="+ct.Data.Code, nil); !strings.Contains(r.than, `"total":1`) {
		t.Fatalf("lọc theo mã %s phải ra đúng 1 chương trình\n%s", ct.Data.Code, catBot(r.than))
	}
	if r := h.goi(t, a.token, http.MethodGet, "/api/v1/admin/dong-gia?codes=KHONG-CO", nil); !strings.Contains(r.than, `"total":0`) {
		t.Fatalf("lọc theo mã không có phải ra 0 chương trình\n%s", catBot(r.than))
	}

	trung := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/dong-gia", dgThan(a, ma+"B", false))
	if trung.ma != http.StatusUnprocessableEntity || !strings.Contains(trung.than, "đã có trong chương trình đồng giá khác") {
		t.Fatalf("hai chương trình đang bật cùng phủ một món phải bị chặn 422, đang là %d\n%s", trung.ma, catBot(trung.than))
	}

	duong := fmt.Sprintf("/api/v1/admin/dong-gia/%d", id)
	if r := h.goi(t, a.token, http.MethodPut, duong, dgThan(a, ma, false)); r.ma != http.StatusUnprocessableEntity {
		t.Fatalf("đã duyệt thì phải huỷ duyệt trước khi sửa, đang là %d\n%s", r.ma, catBot(r.than))
	}
	if r := h.goi(t, a.token, http.MethodDelete, duong, nil); r.ma != http.StatusConflict {
		t.Fatalf("đã duyệt thì không xoá được (409), đang là %d", r.ma)
	}
	if r := h.goi(t, a.token, http.MethodPost, duong+"/huy-duyet", nil); r.ma != http.StatusOK {
		t.Fatalf("huỷ duyệt trả %d", r.ma)
	}
	if r := h.goi(t, a.token, http.MethodPut, duong, dgThan(a, ma, false)); r.ma != http.StatusOK {
		t.Fatalf("huỷ duyệt rồi phải sửa được, đang là %d\n%s", r.ma, catBot(r.than))
	}
	if r := h.goi(t, a.token, http.MethodDelete, duong, nil); r.ma != http.StatusOK {
		t.Fatalf("lưu tạm thì xoá được, đang là %d", r.ma)
	}
}

// TestDongGia_TaiQuay — chỉ chương trình giỏ ĐỦ số lượng mới hiện; chọn thì mọi
// cái bán đúng giá đồng giá, có dòng hàng tặng 0đ trừ kho; không gộp voucher /
// giảm tay.
func TestDongGia_TaiQuay(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	ma := fmt.Sprintf("DQ%d", time.Now().UnixNano()%1e8)

	tao := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/dong-gia", dgThan(a, ma, true))
	if tao.ma != http.StatusCreated {
		t.Fatalf("tạo đồng giá trả %d\n%s", tao.ma, catBot(tao.than))
	}
	var ct struct {
		Data struct {
			ID uint `json:"id"`
		} `json:"data"`
	}
	_ = json.Unmarshal([]byte(tao.than), &ct)

	xem := func(sl int, chon []uint) (ids []uint, gia float64, qua int) {
		r := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/orders/pos/dong-gia", map[string]any{
			"items":           []map[string]any{{"product_variant_id": a.bienThe, "quantity": sl}},
			"fixed_price_ids": chon,
		})
		if r.ma != http.StatusOK {
			t.Fatalf("xem trước đồng giá trả %d\n%s", r.ma, catBot(r.than))
		}
		var out struct {
			Data struct {
				ChuongTrinh []struct {
					ID uint `json:"id"`
				} `json:"chuong_trinh"`
				Gia []struct {
					Price float64 `json:"price"`
				} `json:"gia"`
				Qua []struct {
					Quantity int `json:"quantity"`
				} `json:"qua"`
			} `json:"data"`
		}
		_ = json.Unmarshal([]byte(r.than), &out)
		for _, c := range out.Data.ChuongTrinh {
			ids = append(ids, c.ID)
		}
		if len(out.Data.Gia) > 0 {
			gia = out.Data.Gia[0].Price
		}
		for _, q := range out.Data.Qua {
			qua += q.Quantity
		}

		return ids, gia, qua
	}

	if ids, _, _ := xem(1, nil); len(ids) != 0 {
		t.Fatalf("mua 1 cái chưa đủ ngưỡng 2 thì không được hiện chương trình, đang ra %v", ids)
	}
	ids, gia, qua := xem(2, []uint{ct.Data.ID})
	if len(ids) != 1 || ids[0] != ct.Data.ID || gia != 1000 || qua != 1 {
		t.Fatalf("mua 2 cái: phải hiện đúng chương trình, giá 1.000đ, tặng 1 — đang là %v / %v / %d", ids, gia, qua)
	}

	gop := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/orders/pos", map[string]any{
		"payment_method": "bank_transfer", "voucher_code": "ABC", "fixed_price_ids": []uint{ct.Data.ID},
		"items": []map[string]any{{"product_variant_id": a.bienThe, "quantity": 2}},
	})
	if gop.ma != http.StatusBadRequest {
		t.Fatalf("đồng giá kèm voucher phải bị từ chối 400, đang là %d\n%s", gop.ma, catBot(gop.than))
	}

	ban := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/orders/pos", map[string]any{
		"payment_method": "bank_transfer", "fixed_price_ids": []uint{ct.Data.ID},
		"items": []map[string]any{{"product_variant_id": a.bienThe, "quantity": 2}},
	})
	if ban.ma != http.StatusCreated {
		t.Fatalf("bán có đồng giá trả %d\n%s", ban.ma, catBot(ban.than))
	}
	var kq struct {
		Data struct {
			OrderID        uint    `json:"order_id"`
			SubtotalAmount float64 `json:"subtotal_amount"`
		} `json:"data"`
	}
	_ = json.Unmarshal([]byte(ban.than), &kq)
	if kq.Data.SubtotalAmount != 2000 {
		t.Fatalf("2 cái đồng giá 1.000đ thì tiền hàng phải là 2.000đ, đang là %v", kq.Data.SubtotalAmount)
	}

	don := donQuay(t, h, a, kq.Data.OrderID)
	dong, _ := don["items"].([]any)
	if len(dong) != 2 {
		t.Fatalf("đơn phải có 1 dòng bán + 1 dòng tặng, đang có %d", len(dong))
	}
	tang, _ := dong[1].(map[string]any)
	if tang["is_gift"] != true || tang["unit_price"].(float64) != 0 || tang["fixed_price_detail_id"] == nil {
		t.Fatalf("dòng cuối phải là hàng tặng 0đ gắn dòng đồng giá, đang là %v", tang)
	}
}
