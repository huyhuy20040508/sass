package apitest

import (
	"encoding/json"
	"fmt"
	"io"
	"math"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync"
	"testing"

	"sass-api/internal/domain"
	"sass-api/pkg/nextpay"
)

// NEXTPAY mPOS — QR mPOS và đẩy số tiền xuống máy quẹt thẻ, chạy với một NextPay
// GIẢ LẬP: giải gói bằng đúng Secret Key của tiệm (AES như v2), ghi lại lệnh.

const khoaNextPayTest = "khoa-np-tiem-a12"

type lenhNP struct {
	Service string
	OrderID string
	Amount  float64
	PosID   string
	Muid    string
}

type nextPayGia struct {
	mu     sync.Mutex
	lenh   []lenhNP
	tuChoi bool
}

func (g *nextPayGia) ds() []lenhNP {
	g.mu.Lock()
	defer g.mu.Unlock()

	return append([]lenhNP(nil), g.lenh...)
}

func (g *nextPayGia) co(service, orderID string) *lenhNP {
	for _, l := range g.ds() {
		if l.Service == service && l.OrderID == orderID {
			return &l
		}
	}

	return nil
}

// dungNextPayGia dựng máy chủ giả và trỏ client NextPay của API vào nó.
func dungNextPayGia(t *testing.T) *nextPayGia {
	t.Helper()
	g := &nextPayGia{}
	np := nextpay.New(nextpay.Config{MerchantID: "x", SecretKey: khoaNextPayTest})
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		raw, _ := io.ReadAll(r.Body)
		var vo struct {
			MerchantID string `json:"merchantId"`
			ReqData    string `json:"reqData"`
		}
		_ = json.Unmarshal(raw, &vo)
		thuong, err := np.Giai(vo.ReqData)
		if err != nil {
			_ = json.NewEncoder(w).Encode(map[string]any{"resCode": 401, "message": "Sai chữ ký"})
			return
		}
		var d map[string]any
		_ = json.Unmarshal(thuong, &d)
		l := lenhNP{}
		l.Service, _ = d["serviceName"].(string)
		l.OrderID, _ = d["orderId"].(string)
		l.Amount, _ = d["amount"].(float64)
		l.PosID, _ = d["posId"].(string)
		l.Muid, _ = d["muid"].(string)
		g.mu.Lock()
		g.lenh = append(g.lenh, l)
		tuChoi := g.tuChoi
		g.mu.Unlock()
		if tuChoi {
			_ = json.NewEncoder(w).Encode(map[string]any{"resCode": 400, "message": "Merchant không tồn tại"})
			return
		}
		out := map[string]any{"resCode": 200, "message": "Success"}
		if l.Service == "CREATE_QR" {
			b, _ := json.Marshal(map[string]any{"qrCode": "00020101021238570010A000000727" + l.OrderID})
			out["resData"], _ = np.Ma(b)
		}
		_ = json.NewEncoder(w).Encode(out)
	}))
	t.Cleanup(srv.Close)
	t.Setenv("NEXTPAY_BASE_URL", srv.URL)

	return g
}

// maWebhookCua đọc mã webhook của dòng chi tiết đầu tiên có loại này.
func maWebhookCua(t *testing.T, h *heThong, c *cuaHang, loai string) (uint, string) {
	t.Helper()
	res := h.goi(t, c.token, http.MethodGet, "/api/v1/admin/payment-methods", nil)
	var body struct {
		Data struct {
			Methods []struct {
				Details []struct {
					ID          uint   `json:"id"`
					DetailType  string `json:"detail_type"`
					WebhookCode string `json:"webhook_code"`
				} `json:"details"`
			} `json:"methods"`
		} `json:"data"`
	}
	_ = json.Unmarshal([]byte(res.than), &body)
	for _, m := range body.Data.Methods {
		for _, d := range m.Details {
			if d.DetailType == loai && d.WebhookCode != "" {
				return d.ID, d.WebhookCode
			}
		}
	}
	t.Fatalf("không thấy mã webhook của %s: %s", loai, catBot(res.than))

	return 0, ""
}

func baoTienNP(t *testing.T, h *heThong, ma, khoa string, goi map[string]any) traLoi {
	t.Helper()
	raw, _ := json.Marshal(goi)
	req, _ := nextpay.New(nextpay.Config{MerchantID: "x", SecretKey: khoa}).Ma(raw)

	return h.goiVoiHeader(t, "", http.MethodPost, "/api/v1/payments/mpos/webhook/"+ma, map[string]any{"reqData": req}, nil)
}

func khoaMPOS(them map[string]string) map[string]string {
	d := map[string]string{"mpos_merchant_id": "MC01", "mpos_muid": "muid01", "mpos_secret_key": khoaNextPayTest}
	for k, v := range them {
		d[k] = v
	}

	return d
}

// KB NP01–NP05 — QR mPOS ở quầy: tạo mã qua NextPay, webhook đúng khoá → đơn tự xong,
// sai khoá bị từ chối; huỷ mã gửi REMOVE_QR; Kiểm tra kết nối tạo thử rồi huỷ.
func TestMpos_QRTaiQuay(t *testing.T) {
	g := dungNextPayGia(t)
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	moCa(t, h, a, 0)
	qr := docPMQR(t, h, a)
	if r := h.goi(t, a.token, http.MethodPost, fmt.Sprintf("/api/v1/admin/payment-methods/%d/details", qr), map[string]any{
		"detail_type": "mpos_qr", "data": khoaMPOS(map[string]string{"mpos_type": "VAQR"}),
	}); r.ma != http.StatusOK {
		t.Fatalf("khai mPos QR trả %d %s", r.ma, catBot(r.than))
	}
	h.goi(t, a.token, http.MethodPut, fmt.Sprintf("/api/v1/admin/payment-methods/%d/status", qr), map[string]any{"status": true})
	ct, ma := maWebhookCua(t, h, a, "mpos_qr")
	thuNgan := h.dangNhapVoi(t, a.ma, "nhanvien")

	r, d := banQuayTT(t, h, thuNgan, a, map[string]any{"payment_method": "mpos_qr"})
	if r.ma != http.StatusCreated || d.Status != "pending" || d.Payment == nil || !strings.HasPrefix(d.Payment.QRImage, "data:image/png") {
		t.Fatalf("QR mPOS phải tạo đơn chờ tiền kèm ảnh QR, nhận %d %s", r.ma, catBot(r.than))
	}
	if l := g.co("CREATE_QR", d.OrderCode); l == nil || l.Amount != d.Total || l.Muid != "muid01" {
		t.Fatalf("NextPay phải nhận CREATE_QR đúng mã đơn, số tiền, muid; nhận %+v", g.ds())
	}

	// Gói giả (khoá khác): từ chối, đơn vẫn chờ.
	if r := baoTienNP(t, h, ma, "khoa-gia-mao-123", map[string]any{"transStatus": 104, "orderId": d.OrderCode, "amount": d.Total}); r.ma != http.StatusBadRequest {
		t.Fatalf("gói sai khoá phải 400, nhận %d %s", r.ma, catBot(r.than))
	}
	if st, _, _, _ := cotDon(t, h, d.OrderID); st != "pending" {
		t.Fatalf("gói sai khoá không được hoàn tất đơn, nhận %s", st)
	}
	r = baoTienNP(t, h, ma, khoaNextPayTest, map[string]any{"transStatus": 104, "orderId": d.OrderCode, "amount": d.Total, "transCode": "NP1"})
	if r.ma != http.StatusOK || !strings.Contains(r.than, `"resCode":200`) {
		t.Fatalf("NextPay báo QR xong phải được trả lời resCode 200, nhận %d %s", r.ma, catBot(r.than))
	}
	if st, pay, detail, _ := cotDon(t, h, d.OrderID); st != "completed" || pay != "paid" || detail == nil || *detail != ct {
		t.Fatalf("QR mPOS tiền về phải hoàn tất đơn + ghi cổng %d, nhận %s %s %v", ct, st, pay, detail)
	}
	if tt := h.goi(t, thuNgan, http.MethodGet, "/api/v1/admin/orders/pos/payments/"+d.Payment.Transaction, nil); !strings.Contains(tt.than, `"paid":true`) {
		t.Fatalf("màn quầy hỏi phải thấy đã trả: %s", catBot(tt.than))
	}
	// Tiền QR về sau lúc tạo đơn: vẫn phải có phiếu thu (chuyển khoản — không qua két).
	if so, tong, ht := phieuThuDon(t, h, d.OrderID); so != 1 || tong != d.Total || ht != domain.ThuChiChuyenKhoan {
		t.Fatalf("QR về phải sinh 1 phiếu thu chuyển khoản %.0f, nhận %d / %v / %s", d.Total, so, tong, ht)
	}

	// Huỷ mã: đơn huỷ, NextPay nhận REMOVE_QR.
	_, d2 := banQuayTT(t, h, thuNgan, a, map[string]any{"payment_method": "mpos_qr"})
	if r := h.goi(t, thuNgan, http.MethodPost, fmt.Sprintf("/api/v1/admin/orders/pos/%d/huy-qr", d2.OrderID), nil); r.ma != http.StatusOK {
		t.Fatalf("huỷ mã QR mPOS phải 200, nhận %d %s", r.ma, catBot(r.than))
	}
	if g.co("REMOVE_QR", d2.OrderCode) == nil {
		t.Fatalf("huỷ mã phải gửi REMOVE_QR cho NextPay: %+v", g.ds())
	}
	baoTienNP(t, h, ma, khoaNextPayTest, map[string]any{"transStatus": 104, "orderId": d2.OrderCode, "amount": d2.Total})
	if st, pay, _, _ := cotDon(t, h, d2.OrderID); st != "cancelled" || pay == "paid" {
		t.Fatalf("tiền tới sau khi huỷ không được làm sống lại đơn, nhận %s %s", st, pay)
	}

	// Báo về lệch số tiền: không tự ghi nhận, đơn vẫn chờ để thu ngân xử lý.
	_, d4 := banQuayTT(t, h, thuNgan, a, map[string]any{"payment_method": "mpos_qr"})
	baoTienNP(t, h, ma, khoaNextPayTest, map[string]any{"transStatus": 104, "orderId": d4.OrderCode, "amount": d4.Total - 1000})
	if st, pay, _, _ := cotDon(t, h, d4.OrderID); st != "pending" || pay == "paid" {
		t.Fatalf("lệch số tiền không được hoàn tất đơn, nhận %s %s", st, pay)
	}
	// Màn quầy vẫn thấy mã đang chờ, kèm số cổng báo lệch để nhắc thu ngân.
	tt := h.goi(t, thuNgan, http.MethodGet, "/api/v1/admin/orders/pos/payments/"+d4.Payment.Transaction, nil)
	if !strings.Contains(tt.than, `"status":"pending"`) || !strings.Contains(tt.than, fmt.Sprintf(`"lech_tien":%v`, d4.Total-1000)) ||
		!strings.Contains(tt.than, "kiểm lại với khách") {
		t.Fatalf("sau gói lệch, quầy phải thấy mã còn chờ + số tiền lệch + câu nhắc, nhận %s", catBot(tt.than))
	}
	// Gói ĐÚNG số tiền tới sau (khách quét lại, cổng gửi lại): phải được ghi nhận.
	baoTienNP(t, h, ma, khoaNextPayTest, map[string]any{"transStatus": 104, "orderId": d4.OrderCode, "amount": d4.Total, "transCode": "NP4"})
	if st, pay, _, _ := cotDon(t, h, d4.OrderID); st != "completed" || pay != "paid" {
		t.Fatalf("gói đúng số tiền tới sau gói lệch phải hoàn tất đơn, nhận %s %s", st, pay)
	}
	if tt := h.goi(t, thuNgan, http.MethodGet, "/api/v1/admin/orders/pos/payments/"+d4.Payment.Transaction, nil); !strings.Contains(tt.than, `"paid":true`) || strings.Contains(tt.than, "lech_tien") {
		t.Fatalf("đã trả đủ thì hết câu nhắc lệch tiền, nhận %s", catBot(tt.than))
	}

	// Mã QR đang chờ mà thu tay ở màn Đơn hàng: huỷ mã ở cổng trước (không thu hai
	// lần), rồi đơn quầy hoàn tất.
	_, d5 := banQuayTT(t, h, thuNgan, a, map[string]any{"payment_method": "mpos_qr"})
	if r := h.goi(t, a.token, http.MethodPost, fmt.Sprintf("/api/v1/admin/orders/%d/payments", d5.OrderID), map[string]any{"amount": d5.Total, "payment_method": "cash"}); r.ma >= 300 {
		t.Fatalf("thu tay đơn chờ QR trả %d %s", r.ma, catBot(r.than))
	}
	if g.co("REMOVE_QR", d5.OrderCode) == nil {
		t.Fatalf("thu tay đơn chờ QR phải huỷ mã ở NextPay: %+v", g.ds())
	}
	if st, pay, _, _ := cotDon(t, h, d5.OrderID); st != "completed" || pay != "paid" {
		t.Fatalf("thu đủ đơn chờ QR phải hoàn tất, nhận %s %s", st, pay)
	}
	baoTienNP(t, h, ma, khoaNextPayTest, map[string]any{"transStatus": 104, "orderId": d5.OrderCode, "amount": d5.Total, "transCode": "NP5"})
	if so, _, _ := phieuThuDon(t, h, d5.OrderID); so != 1 {
		t.Fatalf("QR tới muộn sau khi đã thu tay không được sinh phiếu thu thứ hai, nhận %d phiếu", so)
	}

	// Kiểm tra kết nối: tạo thử rồi huỷ ngay một mã 3.000đ.
	kt := h.goi(t, a.token, http.MethodPost, fmt.Sprintf("/api/v1/admin/payment-methods/%d/details/%d/test", qr, ct), map[string]any{"webhook_url": "https://x/api/v1/payments/mpos/webhook/" + ma})
	if kt.ma != http.StatusOK || !strings.Contains(kt.than, `"ok":true`) {
		t.Fatalf("kiểm tra kết nối phải ok, nhận %d %s", kt.ma, catBot(kt.than))
	}
	var thu, huy int
	for _, l := range g.ds() {
		if strings.HasPrefix(l.OrderID, "KTKN") && l.Amount == 3000 {
			if l.Service == "CREATE_QR" {
				thu++
			} else if l.Service == "REMOVE_QR" {
				huy++
			}
		}
	}
	if thu != 1 || huy != 1 {
		t.Fatalf("kiểm tra phải tạo thử 1 mã và huỷ 1 mã, nhận %d/%d", thu, huy)
	}
	g.mu.Lock()
	g.tuChoi = true
	g.mu.Unlock()
	kt = h.goi(t, a.token, http.MethodPost, fmt.Sprintf("/api/v1/admin/payment-methods/%d/details/%d/test", qr, ct), map[string]any{"webhook_url": "https://x/api/v1/payments/mpos/webhook/" + ma})
	if !strings.Contains(kt.than, `"ok":false`) || !strings.Contains(kt.than, "Merchant không tồn tại") {
		t.Fatalf("NextPay từ chối thì kiểm tra phải báo nguyên lý do, nhận %s", catBot(kt.than))
	}
	// NextPay hỏng: tạo QR thất bại → 502, đơn vừa tạo bị huỷ.
	r, _ = banQuayTT(t, h, thuNgan, a, map[string]any{"payment_method": "mpos_qr"})
	if r.ma != http.StatusBadGateway {
		t.Fatalf("NextPay từ chối tạo QR phải 502, nhận %d %s", r.ma, catBot(r.than))
	}
}

// KB NP06–NP10 — máy mPOS: đẩy số tiền xuống máy, NextPay báo quẹt xong → đơn tự
// xong + phí theo loại thẻ + phiếu chi phí thẻ; khách đổi ý trả tiền mặt → huỷ lệnh
// trên máy; máy không nhận lệnh → đơn huỷ, hàng về kho.
func TestMpos_MayQuetThe(t *testing.T) {
	g := dungNextPayGia(t)
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	moCa(t, h, a, 0)
	may := idTra(t, h.goi(t, a.token, http.MethodPost, "/api/v1/admin/payment-devices", map[string]any{"code": "SN" + a.vet, "name": "mPOS quay 1", "is_physical": false}))
	qt, _, _ := docPM(t, h, a.token).pt(5)
	data := khoaMPOS(map[string]string{"bank_id": "970436", "bank_account": "0071", "bank_account_name": "A", "card_fee_foreign": "2.4", "card_fee_local": "0.5"})
	if r := h.goi(t, a.token, http.MethodPost, fmt.Sprintf("/api/v1/admin/payment-methods/%d/details", qt), map[string]any{
		"detail_type": "mpos_device", "device_id": may, "data": data,
	}); r.ma != http.StatusOK {
		t.Fatalf("khai máy mPOS trả %d %s", r.ma, catBot(r.than))
	}
	h.goi(t, a.token, http.MethodPut, fmt.Sprintf("/api/v1/admin/payment-methods/%d/status", qt), map[string]any{"status": true})
	ct, ma := maWebhookCua(t, h, a, "mpos_device")
	thuNgan := h.dangNhapVoi(t, a.ma, "nhanvien")

	ton := tonBienThe(t, h, a.bienThe)
	r, d := banQuayTT(t, h, thuNgan, a, map[string]any{"payment_method": "card"})
	if r.ma != http.StatusCreated || d.Status != "pending" || d.PaymentStatus != "pending" || d.Payment == nil {
		t.Fatalf("quẹt máy mPOS phải tạo đơn CHỜ máy báo, nhận %d %s", r.ma, catBot(r.than))
	}
	if l := g.co("ADD_ORDER_INFOR", d.OrderCode); l == nil || l.PosID != "SN"+a.vet || l.Amount != d.Total {
		t.Fatalf("NextPay phải nhận ADD_ORDER_INFOR đúng máy + số tiền, nhận %+v", g.ds())
	}
	if tonBienThe(t, h, a.bienThe) != ton-1 {
		t.Fatalf("đơn chờ máy vẫn phải giữ hàng")
	}
	// Giao dịch lỗi trên máy (status khác 100): đơn vẫn chờ.
	baoTienNP(t, h, ma, khoaNextPayTest, map[string]any{"transStatus": 101, "orderID": d.OrderCode, "amount": d.Total})
	if st, _, _, _ := cotDon(t, h, d.OrderID); st != "pending" {
		t.Fatalf("giao dịch lỗi không được hoàn tất đơn, nhận %s", st)
	}
	r = baoTienNP(t, h, ma, khoaNextPayTest, map[string]any{"transStatus": 100, "orderID": d.OrderCode, "amount": d.Total, "issuerCode": "VISA", "transCode": "T1"})
	if r.ma != http.StatusOK {
		t.Fatalf("báo quẹt xong trả %d %s", r.ma, catBot(r.than))
	}
	phiMuon := math.Round(d.Total * 2.4 / 100)
	if st, pay, detail, phi := cotDon(t, h, d.OrderID); st != "completed" || pay != "paid" || detail == nil || *detail != ct || phi != phiMuon {
		t.Fatalf("quẹt xong phải hoàn tất đơn, ghi máy %d, phí thẻ nước ngoài 2,4%% = %.0f; nhận %s %s %v %.0f", ct, phiMuon, st, pay, detail, phi)
	}
	var phieu float64
	h.db.WithContext(ctxThoat()).Raw("SELECT COALESCE(SUM(amount),0) FROM income_expenses WHERE source = 'card_fee' AND source_id = ?", d.OrderID).Scan(&phieu)
	if phieu != phiMuon {
		t.Fatalf("phí thẻ phải sinh phiếu chi %.0f, nhận %.0f", phiMuon, phieu)
	}
	// Báo lặp: không ghi phí / phiếu lần hai.
	baoTienNP(t, h, ma, khoaNextPayTest, map[string]any{"transStatus": 100, "orderID": d.OrderCode, "amount": d.Total, "issuerCode": "DOMESTIC"})
	if _, _, _, phi := cotDon(t, h, d.OrderID); phi != phiMuon {
		t.Fatalf("báo lặp không được đổi phí, nhận %.0f", phi)
	}

	// Loại thẻ lạ hoặc gói thiếu issuerCode: KHÔNG lặng lẽ tính phí 0 — tạm tính
	// theo mức cao nhất đã khai (2,4%) và vẫn có phiếu chi để kế toán đối soát.
	for i, goi := range []map[string]any{{"issuerCode": "LOAITHEMOI"}, {}} {
		_, dl := banQuayTT(t, h, thuNgan, a, map[string]any{"payment_method": "card"})
		goi["transStatus"], goi["orderID"], goi["amount"], goi["transCode"] = 100, dl.OrderCode, dl.Total, fmt.Sprintf("TL%d", i)
		baoTienNP(t, h, ma, khoaNextPayTest, goi)
		muon := math.Round(dl.Total * 2.4 / 100)
		var phieuL float64
		h.db.WithContext(ctxThoat()).Raw("SELECT COALESCE(SUM(amount),0) FROM income_expenses WHERE source = 'card_fee' AND source_id = ?", dl.OrderID).Scan(&phieuL)
		if st, pay, _, phi := cotDon(t, h, dl.OrderID); st != "completed" || pay != "paid" || phi != muon || phieuL != muon {
			t.Fatalf("gói %v: loại thẻ không rõ phải tính mức cao nhất %.0f + phiếu chi, nhận %s %s phí %.0f phiếu %.0f", goi, muon, st, pay, phi, phieuL)
		}
	}

	// Khách đổi ý: trả tiền mặt trên chính đơn đó → huỷ lệnh trên máy.
	_, d2 := banQuayTT(t, h, thuNgan, a, map[string]any{"payment_method": "card"})
	if r := h.goi(t, thuNgan, http.MethodPost, fmt.Sprintf("/api/v1/admin/orders/pos/%d/doi-hinh-thuc", d2.OrderID), map[string]any{"payment_method": "cash"}); r.ma != http.StatusOK {
		t.Fatalf("đổi lệnh mPOS sang tiền mặt phải 200, nhận %d %s", r.ma, catBot(r.than))
	}
	if g.co("REMOVE_ORDER_INFOR", d2.OrderCode) == nil {
		t.Fatalf("đổi sang tiền mặt phải huỷ lệnh trên máy: %+v", g.ds())
	}
	// Đổi sang tiền mặt ở quầy: phiếu thu tiền mặt + một dòng sổ quỹ (két) cho đơn.
	if so, tong, ht := phieuThuDon(t, h, d2.OrderID); so != 1 || tong != d2.Total || ht != domain.ThuChiTienMat {
		t.Fatalf("đổi sang tiền mặt phải sinh 1 phiếu thu tiền mặt %.0f, nhận %d / %v / %s", d2.Total, so, tong, ht)
	}
	var quy float64
	h.db.WithContext(ctxThoat()).Raw("SELECT COALESCE(SUM(amount),0) FROM cash_entries WHERE reference_id = ? AND direction = 'in'", d2.OrderID).Scan(&quy)
	if quy != d2.Total {
		t.Fatalf("đổi sang tiền mặt phải vào két (sổ quỹ) %.0f, nhận %.0f", d2.Total, quy)
	}
	baoTienNP(t, h, ma, khoaNextPayTest, map[string]any{"transStatus": 100, "orderID": d2.OrderCode, "amount": d2.Total, "issuerCode": "VISA"})
	if _, pay, _, phi := cotDon(t, h, d2.OrderID); pay != "paid" || phi != 0 {
		t.Fatalf("đơn đã thu tiền mặt: quẹt tới muộn không được ghi phí thẻ, nhận %s %.0f", pay, phi)
	}

	// Đang chờ máy mà bấm "Huỷ lệnh": đơn huỷ, hàng về kho.
	ton = tonBienThe(t, h, a.bienThe)
	_, d3 := banQuayTT(t, h, thuNgan, a, map[string]any{"payment_method": "card"})
	if r := h.goi(t, thuNgan, http.MethodPost, fmt.Sprintf("/api/v1/admin/orders/pos/%d/huy-qr", d3.OrderID), nil); r.ma != http.StatusOK {
		t.Fatalf("huỷ lệnh mPOS phải 200, nhận %d %s", r.ma, catBot(r.than))
	}
	if g.co("REMOVE_ORDER_INFOR", d3.OrderCode) == nil || tonBienThe(t, h, a.bienThe) != ton {
		t.Fatalf("huỷ lệnh phải báo NextPay và trả hàng về kho")
	}

	// Máy không nhận lệnh: 502, đơn huỷ, hàng về kho.
	g.mu.Lock()
	g.tuChoi = true
	g.mu.Unlock()
	ton = tonBienThe(t, h, a.bienThe)
	r, _ = banQuayTT(t, h, thuNgan, a, map[string]any{"payment_method": "card"})
	if r.ma != http.StatusBadGateway {
		t.Fatalf("NextPay không nhận lệnh phải 502, nhận %d %s", r.ma, catBot(r.than))
	}
	// Câu báo nói đúng việc: không gửi được xuống MÁY — không có mã QR nào ở đây.
	if !strings.Contains(r.than, "máy quẹt thẻ") || strings.Contains(r.than, "mã QR") {
		t.Fatalf("lỗi đẩy lệnh xuống máy phải nói về máy quẹt thẻ, không nói mã QR; nhận %s", catBot(r.than))
	}
	if tonBienThe(t, h, a.bienThe) != ton {
		t.Fatalf("đẩy lệnh hỏng phải trả hàng về kho")
	}
}
