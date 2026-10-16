package apitest

import (
	"encoding/json"
	"fmt"
	"net/http"
	"net/url"
	"testing"
	"time"
)

// Báo cáo chương trình khuyến mại — GET /admin/reports/promotions.
//
// Bám vào CON SỐ: đơn áp hai chương trình thì mỗi chương trình nhận đúng phần
// của nó và cộng lại bằng promotion_discount; sửa chương trình (bậc tạo lại
// id mới) không làm mất số đã bán; đồng giá tính giảm theo giá gốc; voucher
// đếm mã phát hành / đã dùng; chương trình chưa ai dùng vẫn có dòng.

type dongBCKM struct {
	ProgramID    uint    `json:"program_id"`
	Code         string  `json:"code"`
	Name         string  `json:"name"`
	Deleted      bool    `json:"deleted"`
	OrderCount   int64   `json:"order_count"`
	GoodsAmount  float64 `json:"goods_amount"`
	Quantity     int64   `json:"quantity"`
	GiftQuantity int64   `json:"gift_quantity"`
	BaseAmount   float64 `json:"base_amount"`
	Discount     float64 `json:"discount"`
	CodeCount    int64   `json:"code_count"`
	CodeUsed     int64   `json:"code_used"`
}

type baoCaoKM struct {
	Rows   []dongBCKM `json:"rows"`
	Totals dongBCKM   `json:"totals"`
}

func docBaoCaoKM(t *testing.T, h *heThong, c *cuaHang, tab, them string) baoCaoKM {
	t.Helper()

	hom := homNayDT()
	res := h.goi(t, c.token, http.MethodGet, "/api/v1/admin/reports/promotions?shop_id=0&from="+hom+"&to="+hom+"&tab="+tab+them, nil)
	if res.ma != http.StatusOK {
		t.Fatalf("báo cáo khuyến mại tab %s trả %d\n%s", tab, res.ma, catBot(res.than))
	}
	var out struct {
		Data baoCaoKM `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &out); err != nil {
		t.Fatalf("không đọc được báo cáo: %v\n%s", err, catBot(res.than))
	}

	return out.Data
}

func dongKM(t *testing.T, bc baoCaoKM, id uint) dongBCKM {
	t.Helper()
	for _, r := range bc.Rows {
		if r.ProgramID == id {
			return r
		}
	}
	t.Fatalf("không thấy chương trình %d trong báo cáo: %+v", id, bc.Rows)

	return dongBCKM{}
}

func TestBaoCaoKhuyenMai(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	moCa(t, h, a, 0)
	goc := "/api/v1/admin/chuong-trinh-khuyen-mai"

	p0 := docCTKM(t, h.goi(t, a.token, http.MethodPost, goc, ctkmThan(a, "Phieu", 0, true, []map[string]any{
		{"total_apply": 10000, "formality": 1, "value": 5000, "gifts": []map[string]any{{"product_variant_id": a.bienThe, "quantity": 1}}},
	})))
	p3 := docCTKM(t, h.goi(t, a.token, http.MethodPost, goc, ctkmThan(a, "Mon", 3, true, []map[string]any{
		{"object_id": a.sanPham, "quantity": 2, "formality": 0, "value": 10, "max_value": 3000},
	})))
	if p0.ID == 0 || p3.ID == 0 {
		t.Fatalf("tạo chương trình hỏng: %+v %+v", p0, p3)
	}

	ban := func(than map[string]any) uint {
		t.Helper()
		than["payment_method"] = "bank_transfer"
		r := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/orders/pos", than)
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
	hang := func(sl int) []map[string]any {
		return []map[string]any{{"product_variant_id": a.bienThe, "quantity": sl}}
	}

	// Đơn 1: 2 cái, cả hai chương trình → p0 giảm 5.000 + tặng 1, p3 giảm 3.000 (trần) trên 2 cái.
	don1 := ban(map[string]any{"items": hang(2), "promotion_program_ids": []uint{p0.ID, p3.ID}})
	// Đơn 2: 1 cái, chỉ p0.
	ban(map[string]any{"items": hang(1), "promotion_program_ids": []uint{p0.ID}})
	// Đơn không khuyến mại — không được lọt vào báo cáo.
	ban(map[string]any{"items": hang(1)})

	d1 := donQuay(t, h, a, don1)
	if d1["promotion_discount"].(float64) != 8000 {
		t.Fatalf("đơn 1 phải giảm 8.000, đang là %v", d1["promotion_discount"])
	}
	gia := d1["subtotal_amount"].(float64) / 2

	hd := docBaoCaoKM(t, h, a, "hoa-don", "")
	r0 := dongKM(t, hd, p0.ID)
	if r0.OrderCount != 2 || r0.Discount != 10000 || r0.GiftQuantity != 2 || r0.GoodsAmount != 3*gia {
		t.Fatalf("p0: 2 đơn, giảm 10.000, tặng 2, tiền hàng 3 cái — đang là %+v", r0)
	}
	if hd.Totals.OrderCount != 2 || hd.Totals.Discount != 10000 {
		t.Fatalf("dòng tổng tab hoá đơn sai: %+v", hd.Totals)
	}
	for _, r := range hd.Rows {
		if r.ProgramID == p3.ID {
			t.Fatalf("chương trình loại 3 không thuộc tab hoá đơn")
		}
	}

	hh := docBaoCaoKM(t, h, a, "hang-hoa", "")
	r3 := dongKM(t, hh, p3.ID)
	if r3.OrderCount != 1 || r3.Discount != 3000 || r3.Quantity != 2 {
		t.Fatalf("p3: 1 đơn, giảm 3.000 trên 2 cái — đang là %+v", r3)
	}

	for _, r := range docBaoCaoKM(t, h, a, "nhom-hang", "").Rows {
		if r.ProgramID == p3.ID {
			t.Fatalf("chương trình theo sản phẩm (loại 3) không thuộc tab nhóm hàng")
		}
	}

	// Sửa p3 (bậc bị xoá rồi tạo lại id mới): số đã bán vẫn còn, tên theo bản mới.
	h.goi(t, a.token, http.MethodPost, fmt.Sprintf("%s/%d/huy-duyet", goc, p3.ID), nil)
	sua := ctkmThan(a, "MonMoi", 3, false, []map[string]any{{"object_id": a.sanPham, "quantity": 3, "formality": 1, "value": 1000}})
	if r := h.goi(t, a.token, http.MethodPut, fmt.Sprintf("%s/%d", goc, p3.ID), sua); r.ma != http.StatusOK {
		t.Fatalf("sửa p3 trả %d\n%s", r.ma, catBot(r.than))
	}
	r3 = dongKM(t, docBaoCaoKM(t, h, a, "hang-hoa", ""), p3.ID)
	if r3.Discount != 3000 || r3.Name != "MonMoi "+a.vet {
		t.Fatalf("sau khi sửa: vẫn giảm 3.000, tên mới — đang là %+v", r3)
	}

	// Tìm theo mã: chỉ còn p3, dòng tổng theo đúng p3.
	tim := docBaoCaoKM(t, h, a, "hang-hoa", "&keyword="+url.QueryEscape(p3.Code))
	if len(tim.Rows) != 1 || tim.Totals.OrderCount != 1 || tim.Totals.Discount != 3000 {
		t.Fatalf("tìm theo mã p3 sai: %+v", tim)
	}

	// Hộp chi tiết: đơn 1 có trong p0 với đúng phần 5.000 của p0.
	ct := h.goi(t, a.token, http.MethodGet, fmt.Sprintf("/api/v1/admin/reports/promotions/orders?shop_id=0&from=%s&to=%s&tab=hoa-don&program_id=%d", homNayDT(), homNayDT(), p0.ID), nil)
	var dsDon struct {
		Data []struct {
			ID            uint    `json:"id"`
			Discount      float64 `json:"discount"`
			TotalDiscount float64 `json:"total_discount"`
		} `json:"data"`
	}
	_ = json.Unmarshal([]byte(ct.than), &dsDon)
	if len(dsDon.Data) != 2 {
		t.Fatalf("p0 phải có 2 đơn trong hộp chi tiết\n%s", catBot(ct.than))
	}
	for _, d := range dsDon.Data {
		if d.Discount != 5000 {
			t.Fatalf("mỗi đơn p0 giảm 5.000, đang là %+v", d)
		}
		if d.ID == don1 && d.TotalDiscount != 8000 {
			t.Fatalf("tổng giảm của đơn 1 là 8.000, đang là %+v", d)
		}
	}
	if r := h.goi(t, a.token, http.MethodGet, "/api/v1/admin/reports/promotions/orders?tab=hoa-don", nil); r.ma != http.StatusUnprocessableEntity {
		t.Fatalf("thiếu program_id phải 422, đang là %d", r.ma)
	}

	// Đồng giá: mua 2 cái, mỗi cái 1.000 thay vì giá niêm yết, tặng 1. Đơn đồng
	// giá không nhận giảm giá tự áp, nên giá gốc là giá niêm yết chứ không phải
	// giá sau khuyến mãi tự áp (gia) như các đơn trên.
	dg := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/dong-gia", dgThan(a, "BC", true))
	var dgCT struct {
		Data struct {
			ID uint `json:"id"`
		} `json:"data"`
	}
	_ = json.Unmarshal([]byte(dg.than), &dgCT)
	ban(map[string]any{"items": hang(2), "fixed_price_ids": []uint{dgCT.Data.ID}})
	rdg := dongKM(t, docBaoCaoKM(t, h, a, "dong-gia", ""), dgCT.Data.ID)
	if rdg.OrderCount != 1 || rdg.Quantity != 2 || rdg.GiftQuantity != 1 || rdg.BaseAmount <= 2*gia || rdg.Discount != rdg.BaseAmount-2000 {
		t.Fatalf("đồng giá: 1 đơn, 2 cái, tặng 1, giảm = gốc − 2.000 — đang là %+v", rdg)
	}

	// Voucher: một chương trình dùng 1 mã, một chương trình phát hành mà chưa ai dùng.
	vcGoc := "/api/v1/admin/voucher-coupon"
	vc := docVC(t, h.goi(t, a.token, http.MethodPost, vcGoc, vcThan(a, nil, true)))
	vcRong := docVC(t, h.goi(t, a.token, http.MethodPost, vcGoc, vcThan(a, nil, true)))
	ban(map[string]any{"items": hang(2), "voucher_code": docMa(t, h, a.token, vc.ID)[0].Code})
	bcVC := docBaoCaoKM(t, h, a, "voucher", "")
	if r := dongKM(t, bcVC, vc.ID); r.OrderCount != 1 || r.Discount != 5000 || r.CodeCount != 3 || r.CodeUsed != 1 {
		t.Fatalf("voucher: 1 đơn, giảm 5.000, 3 mã, dùng 1 — đang là %+v", r)
	}
	if r := dongKM(t, bcVC, vcRong.ID); r.OrderCount != 0 || r.CodeCount != 3 {
		t.Fatalf("voucher chưa dùng vẫn phải có dòng 0 đơn, 3 mã — đang là %+v", r)
	}

	// Kỳ khác (năm ngoái) thì không có đơn nào.
	cu := h.goi(t, a.token, http.MethodGet, "/api/v1/admin/reports/promotions?shop_id=0&from=2020-01-01&to=2020-01-31&tab=hoa-don", nil)
	if cu.ma != http.StatusOK {
		t.Fatalf("kỳ cũ trả %d", cu.ma)
	}
	var bcCu struct {
		Data baoCaoKM `json:"data"`
	}
	_ = json.Unmarshal([]byte(cu.than), &bcCu)
	if bcCu.Data.Totals.OrderCount != 0 {
		t.Fatalf("kỳ 2020 không được có đơn: %+v", bcCu.Data.Totals)
	}
}

// TestBaoCaoKhuyenMai_ChuaTonTaiThiKhongBay — chương trình "không giới hạn thời
// gian" (và voucher) chỉ bày ra ở kỳ mà nó đã tồn tại: xem năm ngoái thì chương
// trình vừa tạo hôm nay không được hiện, kể cả dòng 0.
func TestBaoCaoKhuyenMai_ChuaTonTaiThiKhongBay(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	moCa(t, h, a, 0)

	p0 := docCTKM(t, h.goi(t, a.token, http.MethodPost, "/api/v1/admin/chuong-trinh-khuyen-mai",
		ctkmThan(a, "Moi", 0, true, []map[string]any{{"total_apply": 10000, "formality": 1, "value": 5000}})))
	vc := docVC(t, h.goi(t, a.token, http.MethodPost, "/api/v1/admin/voucher-coupon", vcThan(a, nil, true)))
	if p0.ID == 0 || vc.ID == 0 {
		t.Fatalf("tạo chương trình hỏng: %+v %+v", p0, vc)
	}

	co := func(tab, tu, den string, id uint) bool {
		t.Helper()
		res := h.goi(t, a.token, http.MethodGet, "/api/v1/admin/reports/promotions?shop_id=0&tab="+tab+"&from="+tu+"&to="+den, nil)
		if res.ma != http.StatusOK {
			t.Fatalf("báo cáo tab %s trả %d\n%s", tab, res.ma, catBot(res.than))
		}
		var out struct {
			Data baoCaoKM `json:"data"`
		}
		if err := json.Unmarshal([]byte(res.than), &out); err != nil {
			t.Fatalf("không đọc được báo cáo: %v", err)
		}
		for _, r := range out.Data.Rows {
			if r.ProgramID == id {
				return true
			}
		}
		return false
	}

	nam := time.Now().Year() - 1
	tu, den := fmt.Sprintf("%d-01-01", nam), fmt.Sprintf("%d-12-31", nam)
	hom := homNayDT()
	if co("hoa-don", tu, den, p0.ID) {
		t.Errorf("kỳ năm %d không được bày chương trình vừa tạo hôm nay", nam)
	}
	if co("voucher", tu, den, vc.ID) {
		t.Errorf("kỳ năm %d không được bày voucher vừa phát hành hôm nay", nam)
	}
	if !co("hoa-don", hom, hom, p0.ID) || !co("voucher", hom, hom, vc.ID) {
		t.Errorf("kỳ hôm nay vẫn phải bày cả hai chương trình (dòng 0)")
	}
}
