package apitest

import (
	"encoding/json"
	"net/http"
	"testing"
)

// TestQuayXemTruocMaGiam — số mã giảm giá bày ở quầy phải ĐÚNG BẰNG số ghi vào đơn.
//
// Vì sao có bài này: trước đây quầy không hỏi gì khi người bán gõ mã, màn hình
// vẫn bày tổng chưa trừ, tới lúc bấm thanh toán API mới trừ. Đơn ghi một số,
// khách trả một số, và với tiền mặt thì máy tính tiền thừa theo số sai nên thối
// nhầm đúng bằng phần chênh.
//
// Bài so hai con số của CÙNG một giỏ: xem trước và chốt thật. Hai nhánh ấy chỉ
// bằng nhau khi chúng đi chung một mạch tính — đó là điều cần canh, vì mai kia
// ai đó sửa cách tính ở một bên là bài này đỏ ngay.
func TestQuayXemTruocMaGiam(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	ma := "XEMTRUOC" + a.vet
	taoVoucher(t, h, a, ma, nil)

	gio := []map[string]any{{"product_variant_id": a.bienThe, "quantity": 2}}

	// 1. Xem trước: giỏ này + mã này thì trừ bao nhiêu.
	res := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/orders/pos/voucher", map[string]any{
		"items": gio, "code": ma,
	})
	if res.ma != http.StatusOK {
		t.Fatalf("xem trước mã trả %d\n%s", res.ma, catBot(res.than))
	}

	var xem struct {
		Data struct {
			Code string  `json:"code"`
			Giam float64 `json:"giam"`
		} `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &xem); err != nil {
		t.Fatalf("không đọc được kết quả xem trước: %v\n%s", err, catBot(res.than))
	}
	if xem.Data.Giam <= 0 {
		t.Fatalf("mã hợp lệ phải trả số giảm > 0, đang là %v", xem.Data.Giam)
	}

	// 2. Chốt thật CÙNG giỏ, cùng mã.
	res = h.goi(t, a.token, http.MethodPost, "/api/v1/admin/orders/pos", map[string]any{
		"payment_method": "cash", "amount_tendered": 10000000,
		"voucher_code": ma, "items": gio,
	})
	if res.ma != http.StatusCreated {
		t.Fatalf("bán tại quầy trả %d\n%s", res.ma, catBot(res.than))
	}

	var ban struct {
		Data struct {
			Discount float64 `json:"discount_amount"`
		} `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &ban); err != nil {
		t.Fatalf("không đọc được kết quả bán: %v\n%s", err, catBot(res.than))
	}

	if ban.Data.Discount != xem.Data.Giam {
		t.Fatalf("số bày ở quầy (%v) phải bằng số ghi vào đơn (%v)", xem.Data.Giam, ban.Data.Discount)
	}
}

// Xem trước KHÔNG được tiêu lượt dùng mã: người bán gõ mã rồi đổi ý, mã vẫn phải
// còn nguyên lượt cho lượt bán sau.
func TestQuayXemTruocMaGiam_KhongTieuLuot(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	ma := "KHONGTIEU" + a.vet
	id := taoVoucher(t, h, a, ma, nil)
	gio := []map[string]any{{"product_variant_id": a.bienThe, "quantity": 1}}

	for i := 0; i < 3; i++ {
		res := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/orders/pos/voucher", map[string]any{
			"items": gio, "code": ma,
		})
		if res.ma != http.StatusOK {
			t.Fatalf("lượt xem trước thứ %d trả %d\n%s", i+1, res.ma, catBot(res.than))
		}
	}

	res := h.goi(t, a.token, http.MethodGet, "/api/v1/admin/vouchers", nil)
	if res.ma != http.StatusOK {
		t.Fatalf("đọc danh sách mã trả %d\n%s", res.ma, catBot(res.than))
	}
	var ds struct {
		Data []struct {
			ID        uint `json:"id"`
			UsedCount int  `json:"used_count"`
		} `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &ds); err != nil {
		t.Fatalf("không đọc được danh sách mã: %v\n%s", err, catBot(res.than))
	}
	for _, v := range ds.Data {
		if v.ID == id && v.UsedCount != 0 {
			t.Fatalf("xem trước không được tiêu lượt, đang là %d", v.UsedCount)
		}
	}
}

// Mã hỏng thì xem trước BÁO LỖI chứ không trả giảm 0 — quầy phải biết ngay để
// bỏ mã, thay vì giữ một mã mà lúc chốt API sẽ từ chối.
func TestQuayXemTruocMaGiam_MaHongBaoLoi(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	res := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/orders/pos/voucher", map[string]any{
		"items": []map[string]any{{"product_variant_id": a.bienThe, "quantity": 1}},
		"code":  "MA-KHONG-CO-THAT",
	})
	if res.ma == http.StatusOK {
		t.Fatalf("mã không có thật mà vẫn trả 200\n%s", catBot(res.than))
	}

	// Giỏ rỗng cũng không tính được: không có gì để giảm.
	res = h.goi(t, a.token, http.MethodPost, "/api/v1/admin/orders/pos/voucher", map[string]any{
		"items": []map[string]any{}, "code": "BATKY",
	})
	if res.ma == http.StatusOK {
		t.Fatalf("giỏ rỗng mà vẫn trả 200\n%s", catBot(res.than))
	}
}
