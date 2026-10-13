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

// TestSoDon_BonCotHinhThuc — sổ đơn hàng chia tiền đã thu đúng bốn hình thức
// của quầy: SePay / PayOS vào QR tự động (không lẫn sang Chuyển khoản), quẹt
// thẻ vào cột riêng; dòng tổng cộng đúng từng cột.
func TestSoDon_BonCotHinhThuc(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	ctx := tenant.WithID(context.Background(), a.id)
	now := time.Now()

	tien := map[string]float64{
		domain.PaymentMethodCash: 10000, domain.PaymentMethodBank: 20000, domain.PaymentMethodCard: 40000,
		domain.PaymentMethodSePay: 80000, domain.PaymentMethodPayOS: 160000,
		// Mã cũ — sổ đơn và báo cáo phải cùng xếp vào "Khác".
		"vnpay": 320000,
	}
	don := map[string]uint{}
	for ht, so := range tien {
		o := &domain.Order{
			ShopID: a.chiNhanh, OrderCode: "sd-" + ht + "-" + a.vet, Channel: domain.OrderChannelPOS,
			SubtotalAmount: so, TotalAmount: so,
			PaymentMethod: ht, PaymentStatus: domain.OrderPaymentPaid,
			Status: domain.OrderStatusCompleted, PlacedAt: &now,
		}
		tao(t, h.db, ctx, o)
		don[ht] = o.ID
	}

	res := h.goi(t, a.token, http.MethodGet, "/api/v1/admin/orders/so-don?page_size=100", nil)
	if res.ma != http.StatusOK {
		t.Fatalf("đọc sổ đơn trả %d\n%s", res.ma, catBot(res.than))
	}
	type cot struct {
		TienMat     float64 `json:"tien_mat"`
		ChuyenKhoan float64 `json:"chuyen_khoan"`
		QuetThe     float64 `json:"quet_the"`
		QRTuDong    float64 `json:"qr_tu_dong"`
		Khac        float64 `json:"khac"`
	}
	var out struct {
		Data []struct {
			Loai string `json:"loai"`
			ID   uint   `json:"id"`
			cot
		} `json:"data"`
		Meta struct {
			Tong cot `json:"tong"`
		} `json:"meta"`
	}
	if err := json.Unmarshal([]byte(res.than), &out); err != nil {
		t.Fatalf("không đọc được sổ đơn: %v", err)
	}

	muon := map[string]cot{
		domain.PaymentMethodCash:  {TienMat: 10000},
		domain.PaymentMethodBank:  {ChuyenKhoan: 20000},
		domain.PaymentMethodCard:  {QuetThe: 40000},
		domain.PaymentMethodSePay: {QRTuDong: 80000},
		domain.PaymentMethodPayOS: {QRTuDong: 160000},
		"vnpay":                   {Khac: 320000},
	}
	var tong cot
	for _, d := range out.Data {
		tong.TienMat += d.TienMat
		tong.ChuyenKhoan += d.ChuyenKhoan
		tong.QuetThe += d.QuetThe
		tong.QRTuDong += d.QRTuDong
		tong.Khac += d.Khac
		for ht, id := range don {
			if d.Loai == "don" && d.ID == id && d.cot != muon[ht] {
				t.Errorf("đơn %s phải nằm ở %+v, đang là %+v", ht, muon[ht], d.cot)
			}
		}
	}
	if out.Meta.Tong != tong {
		t.Errorf("dòng tổng %+v phải bằng cộng các dòng %+v", out.Meta.Tong, tong)
	}

	// Ô lọc "QR tự động" gửi tên ô — ra đúng SePay + PayOS, không kéo đơn VNPay cũ.
	loc := h.goi(t, a.token, http.MethodGet, "/api/v1/admin/orders/so-don?page_size=100&payment_method=auto_qr", nil)
	var dsLoc struct {
		Data []struct {
			Loai string `json:"loai"`
			ID   uint   `json:"id"`
		} `json:"data"`
	}
	_ = json.Unmarshal([]byte(loc.than), &dsLoc)
	co := map[uint]bool{}
	for _, d := range dsLoc.Data {
		if d.Loai == "don" {
			co[d.ID] = true
		}
	}
	if !co[don[domain.PaymentMethodSePay]] || !co[don[domain.PaymentMethodPayOS]] || co[don["vnpay"]] || co[don[domain.PaymentMethodCard]] {
		t.Errorf("lọc QR tự động phải ra SePay + PayOS và không có VNPay / thẻ, đang có %v", co)
	}

	// Báo cáo bán hàng cùng ngày xếp giống hệt sổ đơn.
	ngay := ngayHomNay(docDoanhThu(t, h, a, ""))
	if ngay.AutoQR != 240000 || ngay.Other != 320000 || ngay.Card != 40000 {
		t.Errorf("báo cáo bán hàng: QR 240000 / Khác 320000 / thẻ 40000 như sổ đơn, đang là %v / %v / %v", ngay.AutoQR, ngay.Other, ngay.Card)
	}
}

// TestSoDon_GhiLuotThuQuetThe — hộp "Ghi nhận thanh toán" chọn Quẹt thẻ: API phải
// nhận (trước đây từ chối 'card'), tiền vào đúng cột Quẹt thẻ của sổ; mã cũ
// ngoài bốn hình thức (vnpay) bị từ chối.
func TestSoDon_GhiLuotThuQuetThe(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	ctx := tenant.WithID(context.Background(), a.id)
	now := time.Now()

	o := &domain.Order{
		ShopID: a.chiNhanh, OrderCode: "sd-thu-" + a.vet, Channel: domain.OrderChannelWeb,
		SubtotalAmount: 100000, TotalAmount: 100000,
		PaymentMethod: domain.PaymentMethodBank, PaymentStatus: domain.OrderPaymentPending,
		Status: domain.OrderStatusConfirmed, PlacedAt: &now,
	}
	tao(t, h.db, ctx, o)

	duong := fmt.Sprintf("/api/v1/admin/orders/%d/payments", o.ID)
	if r := h.goi(t, a.token, http.MethodPost, duong, map[string]any{"amount": 30000, "payment_method": "card"}); r.ma >= 300 {
		t.Fatalf("ghi lượt thu quẹt thẻ phải được nhận, nhận %d\n%s", r.ma, catBot(r.than))
	}
	if r := h.goi(t, a.token, http.MethodPost, duong, map[string]any{"amount": 1000, "payment_method": "vnpay"}); r.ma != http.StatusUnprocessableEntity && r.ma != http.StatusBadRequest {
		t.Errorf("mã vnpay phải bị từ chối, nhận %d", r.ma)
	}

	res := h.goi(t, a.token, http.MethodGet, "/api/v1/admin/orders/so-don?page_size=100", nil)
	var out struct {
		Data []struct {
			Loai    string  `json:"loai"`
			ID      uint    `json:"id"`
			QuetThe float64 `json:"quet_the"`
			CK      float64 `json:"chuyen_khoan"`
		} `json:"data"`
	}
	_ = json.Unmarshal([]byte(res.than), &out)
	for _, d := range out.Data {
		if d.Loai == "don" && d.ID == o.ID {
			if d.QuetThe != 30000 || d.CK != 0 {
				t.Errorf("lượt thu 30000 quẹt thẻ phải nằm ở cột Quẹt thẻ, đang là thẻ %v / CK %v", d.QuetThe, d.CK)
			}
			return
		}
	}
	t.Fatalf("sổ đơn không có đơn %d", o.ID)
}
