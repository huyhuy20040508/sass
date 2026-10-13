package service

import (
	"context"
	"encoding/base64"
	"encoding/json"
	"errors"
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync"
	"testing"
	"time"

	"sass-api/internal/domain"
	"sass-api/internal/dto"
	"sass-api/pkg/bimat"
	"sass-api/pkg/sepayinvoice"
)

// Bài kiểm nhánh SePay của hoá đơn điện tử: chạy cả luồng của service trên một
// cổng SePay giả (httptest) và các sổ giả trong bộ nhớ. Thứ cần chắc nhất là
// thân hoá đơn gửi lên (mẫu số / ký hiệu tách đúng, hai mã thuế âm đảo đúng,
// tổng khớp số quầy đã thu) và trạng thái ghi vào sổ ở từng ngả của cổng.

// ---- sổ giả ----

type soEtaxGia struct {
	domain.EtaxRepository
	mu  sync.Mutex
	cn  *domain.EtaxConnection
	mau []domain.EtaxTemplate
	hd  *domain.EtaxInvoice
}

func (r *soEtaxGia) TheoChiNhanh(_ context.Context, _ uint) (*domain.EtaxConnection, error) {
	r.mu.Lock()
	defer r.mu.Unlock()
	if r.cn == nil {
		return nil, domain.ErrNotFound
	}
	cn := *r.cn
	cn.Templates = append([]domain.EtaxTemplate(nil), r.mau...)

	return &cn, nil
}

func (r *soEtaxGia) MaSoThueDaDung(context.Context, string, uint) (bool, error) { return false, nil }

// TaiKhoanDaDung: tên đăng nhập "da-dung" giả làm tài khoản chi nhánh khác đã nối.
func (r *soEtaxGia) TaiKhoanDaDung(_ context.Context, _, username string, _ uint) (bool, error) {
	return username == "da-dung", nil
}

func (r *soEtaxGia) Luu(_ context.Context, cn *domain.EtaxConnection) error {
	r.mu.Lock()
	defer r.mu.Unlock()
	if cn.ID == 0 {
		cn.ID = 7
	}
	ban := *cn
	ban.Templates = nil
	r.cn = &ban

	return nil
}

func (r *soEtaxGia) LuuMau(_ context.Context, _ uint, ds []domain.EtaxTemplate) error {
	r.mu.Lock()
	defer r.mu.Unlock()
	r.mau = append([]domain.EtaxTemplate(nil), ds...)

	return nil
}

func (r *soEtaxGia) HoaDonTheoDon(context.Context, uint) (*domain.EtaxInvoice, error) {
	r.mu.Lock()
	defer r.mu.Unlock()
	if r.hd == nil {
		return nil, domain.ErrNotFound
	}
	hd := *r.hd

	return &hd, nil
}

func (r *soEtaxGia) LuuHoaDon(_ context.Context, hd *domain.EtaxInvoice) error {
	r.mu.Lock()
	defer r.mu.Unlock()
	if hd.ID == 0 {
		hd.ID = 9
	}
	ban := *hd
	r.hd = &ban

	return nil
}

func (r *soEtaxGia) ThueSuatTheoMatHang(context.Context, []uint) (map[uint]int, error) {
	return map[uint]int{1: 10}, nil
}

type chiNhanhGia struct{ domain.ChiNhanhRepository }

func (chiNhanhGia) FindByID(_ context.Context, id uint) (*domain.ChiNhanh, error) {
	return &domain.ChiNhanh{ID: id, Code: "CN1", Name: "Chi nhánh 1"}, nil
}

type donGia struct {
	domain.OrderRepository
	don *domain.Order
}

func (d donGia) FindByID(context.Context, uint) (*domain.Order, error) {
	don := *d.don

	return &don, nil
}

// ---- cổng giả ----

type congSePayGia struct {
	mu sync.Mutex
	// soToken đếm lượt lấy token; hetTokenLuotTao = true thì lượt tạo đầu tiên
	// trả 401 để thử đường đăng nhập lại.
	soToken         int
	hetTokenLuotTao bool
	taiKhoanBat     int
	// ketQuaTao: Success | Failed | Pending — kết quả lượt hỏi /create/check.
	ketQuaTao    string
	coChiTiet    bool
	daPhatHanh   bool
	thanTao      []map[string]any
	luotTaoTrung bool
}

func (g *congSePayGia) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	g.mu.Lock()
	defer g.mu.Unlock()
	tra := func(ma int, than string) {
		w.WriteHeader(ma)
		_, _ = io.WriteString(w, than)
	}
	if r.URL.Path != "/v1/token" && !strings.HasPrefix(r.Header.Get("Authorization"), "Bearer tok-") {
		tra(401, `{"success":false,"error":{"code":"UNAUTHORIZED","message":"thiếu token"}}`)

		return
	}

	switch {
	case r.URL.Path == "/v1/token":
		if id, bm, _ := r.BasicAuth(); id != "cid" || bm != "sec" {
			tra(401, `{"success":false,"error":{"code":"BAD_REQUEST","message":"sai"}}`)

			return
		}
		g.soToken++
		tra(200, `{"success":true,"data":{"access_token":"tok-`+string(rune('0'+g.soToken))+`"}}`)

	case r.URL.Path == "/v1/provider-accounts":
		ds := []string{`{"id":"acc-1","provider":"matbao","active":true}`, `{"id":"acc-0","provider":"matbao","active":false}`}
		if g.taiKhoanBat > 1 {
			ds = append(ds, `{"id":"acc-2","provider":"viettel","active":true}`)
		}
		tra(200, `{"data":{"items":[`+strings.Join(ds, ",")+`]}}`)

	case r.URL.Path == "/v1/provider-accounts/acc-1":
		tra(200, `{"success":true,"data":{"id":"acc-1","provider":"matbao","active":true,"templates":[
			{"template_code":"1","invoice_series":"C26TSE","invoice_label":"1 - C26TSE - Hóa đơn giá trị gia tăng"},
			{"template_code":"2","invoice_series":"C26TSE","invoice_label":"2 - C26TSE - Hóa đơn bán hàng"}]}}`)

	case r.URL.Path == "/v1/invoices/create":
		if g.hetTokenLuotTao {
			g.hetTokenLuotTao = false
			tra(401, `{"success":false,"error":{"code":"TOKEN_EXPIRED","message":"hết hạn"}}`)

			return
		}
		var than map[string]any
		_ = json.NewDecoder(r.Body).Decode(&than)
		g.thanTao = append(g.thanTao, than)
		if g.luotTaoTrung {
			tra(409, `{"success":false,"error":{"code":"EINVOICE_DOCUMENT_EXISTED","message":"đã có"}}`)

			return
		}
		tra(200, `{"success":true,"data":{"tracking_code":"trk-c","tracking_url":"x","message":"ok"}}`)

	case r.URL.Path == "/v1/invoices/create/check/trk-c":
		switch g.ketQuaTao {
		case sepayinvoice.ThatBai:
			tra(200, `{"success":true,"data":{"reference_code":"DH001","status":"Failed","message":"Mã số thuế người mua không hợp lệ"}}`)
		case sepayinvoice.DangXuLy:
			tra(200, `{"success":true,"data":{"reference_code":"DH001","status":"Pending","message":"đang xử lý"}}`)
		default:
			g.coChiTiet = true
			tra(200, `{"success":true,"data":{"reference_code":"DH001","status":"Success","message":"ok",
				"invoice":{"reference_code":"DH001","invoice_number":"0","status":"draft"}}}`)
		}

	case r.URL.Path == "/v1/invoices/issue":
		g.daPhatHanh = true
		tra(200, `{"success":true,"data":{"tracking_code":"trk-i","tracking_url":"x"}}`)

	case r.URL.Path == "/v1/invoices/issue/check/trk-i":
		tra(200, `{"success":true,"data":{"reference_code":"DH001","status":"Success","message":"ok",
			"invoice":{"reference_code":"DH001","invoice_number":"589","status":"issued"}}}`)

	case r.URL.Path == "/v1/invoices/DH001":
		if !g.coChiTiet {
			tra(404, `{"success":false,"error":{"code":"NOT_FOUND","message":"không có"}}`)

			return
		}
		if !g.daPhatHanh {
			tra(200, `{"success":true,"data":{"reference_code":"DH001","invoice_number":"0","status":"draft","provider_status":"1"}}`)

			return
		}
		tra(200, `{"success":true,"data":{"reference_code":"DH001","invoice_number":"589","status":"issued",
			"tax_authority_code":"M1-26-ABCDE","provider_status":"4"}}`)

	case r.URL.Path == "/v1/invoices/DH001/download":
		tra(200, `{"success":true,"data":{"file_type":"pdf","content":"`+base64.StdEncoding.EncodeToString([]byte("%PDF hd"))+`"}}`)

	default:
		tra(404, `{"success":false,"error":{"code":"NOT_FOUND","message":"`+r.URL.Path+`"}}`)
	}
}

func donMau() *domain.Order {
	p := func(v uint) *uint { return &v }
	i := func(v int) *int { return &v }

	return &domain.Order{
		ID: 5, ShopID: 1, OrderCode: "DH001", PaymentStatus: domain.OrderPaymentPaid, PaymentMethod: "cash",
		RecipientName: "Nguyễn A", RecipientPhone: "0900000000", RecipientEmail: "a@x.vn",
		ShippingFee: 30000, DiscountAmount: 10000, TotalAmount: 0,
		Items: []domain.OrderItem{
			// 10% lấy từ mặt hàng hiện tại (repo giả trả 10 cho product 1).
			{ProductID: p(1), ProductName: "Áo", VariantSKU: "AO-1", Quantity: 2, UnitPrice: 100000, TotalPrice: 200000},
			// KCT chụp lúc bán (-1) → SePay là -2.
			{ProductName: "Sách", Quantity: 1, UnitPrice: 50000, TotalPrice: 50000, VAT: i(-1)},
			// KKKNT (-2) → SePay là -1.
			{ProductName: "Phí khác", Quantity: 1, UnitPrice: 20000, TotalPrice: 20000, VAT: i(-2)},
		},
	}
}

func dungSePay(t *testing.T, g *congSePayGia) (*etaxService, *soEtaxGia) {
	t.Helper()
	sv := httptest.NewServer(g)
	t.Cleanup(sv.Close)

	cu1, cu2 := choSePay, nhipHoiSePay
	choSePay, nhipHoiSePay = 300*time.Millisecond, 10*time.Millisecond
	t.Cleanup(func() { choSePay, nhipHoiSePay = cu1, cu2 })

	so := &soEtaxGia{}
	s := NewEtaxService(so, chiNhanhGia{}, donGia{don: donMau()}, bimat.New("khoa-thu-cua-bai-kiem"), nil,
		sepayinvoice.New(sv.URL)).(*etaxService)

	return s, so
}

func ketNoiSePay(t *testing.T, s *etaxService, kyHieu string, tuPhatHanh bool) {
	t.Helper()
	ctx := context.Background()
	cn, err := s.KetNoi(ctx, 1, &dto.EtaxKetNoiRequest{Provider: "sepay", Username: "cid", Password: "sec", MaDVCS: "VP"})
	if err != nil {
		t.Fatalf("kết nối SePay: %v", err)
	}
	if cn.AccountRef != "acc-1" || cn.MaDVCS != "" || cn.Provider != domain.NhaCungCapSePay {
		t.Fatalf("kết nối phải chốt acc-1, bỏ mã đơn vị: %+v", cn)
	}
	if len(cn.Templates) != 2 || cn.Templates[0].Symbol != "1C26TSE" || cn.Templates[0].FormNo != "1" ||
		cn.Templates[0].TypeName != "Hóa đơn giá trị gia tăng" || cn.Templates[1].Symbol != "2C26TSE" {
		t.Fatalf("mẫu phải ghép mẫu số + ký hiệu: %+v", cn.Templates)
	}
	if _, err := s.CapNhat(ctx, 1, &dto.EtaxCaiDatRequest{TemplateSymbol: kyHieu, AutoRelease: &tuPhatHanh}); err != nil {
		t.Fatalf("chọn ký hiệu: %v", err)
	}
}

// Luồng chính: nối → chọn mẫu GTGT → phát hành nháp → ký → tải PDF.
func TestSePay_LuongNhapRoiKy(t *testing.T) {
	g := &congSePayGia{}
	s, so := dungSePay(t, g)
	ctx := context.Background()
	ketNoiSePay(t, s, "1C26TSE", false)

	hd, err := s.PhatHanh(ctx, 5)
	if err != nil {
		t.Fatalf("phát hành: %v", err)
	}
	if hd.Status != domain.HoaDonNhap || hd.InvoiceID != "DH001" || hd.InvoiceNo != "" {
		t.Fatalf("không bật tự phát hành thì phải là nháp, số rỗng: %+v", hd)
	}

	than := g.thanTao[0]
	if than["template_code"] != "1" || than["invoice_series"] != "C26TSE" || than["provider_account_id"] != "acc-1" ||
		than["reference_code"] != "DH001" || than["is_draft"] != true || than["payment_method"] != "TM" {
		t.Fatalf("đầu phiếu sai: %v", than)
	}
	if !strings.HasPrefix(than["issued_date"].(string), time.Now().In(gioVN).Format("2006-01-02")) {
		t.Fatalf("ngày lập phải là hôm nay giờ VN: %v", than["issued_date"])
	}
	dong := than["items"].([]any)
	if len(dong) != 4 {
		t.Fatalf("3 mặt hàng + phí giao hàng = 4 dòng, nhận %d", len(dong))
	}
	ts := func(i int) float64 { return dong[i].(map[string]any)["tax_rate"].(float64) }
	if ts(0) != 10 || ts(1) != -2 || ts(2) != -1 || ts(3) != -2 {
		t.Fatalf("thuế suất phải 10, -2 (KCT), -1 (KKKNT), -2 (phí giao): %v %v %v %v", ts(0), ts(1), ts(2), ts(3))
	}
	// Giảm 10.000 toàn đơn chia theo tỉ trọng 200k/50k/20k: dòng đầu chịu 7.407,
	// còn 192.593 → thuế 19.259. Tổng = 270.000 - 10.000 + 30.000 + 19.259.
	if d0 := dong[0].(map[string]any); d0["discount_amount"] != 7407.0 || d0["tax_amount"] != 19259.0 {
		t.Fatalf("dòng đầu phải bớt 7407, thuế 19259: %v", d0)
	}
	if than["total_amount"] != 309259.0 {
		t.Fatalf("tổng phải 309259, nhận %v", than["total_amount"])
	}
	if hd.VatAmount != 19259 {
		t.Fatalf("tiền thuế ghi sổ phải 19259, nhận %v", hd.VatAmount)
	}

	// Phát hành lần hai bị chặn: một đơn một hoá đơn.
	if _, err := s.PhatHanh(ctx, 5); !errors.Is(err, domain.ErrHoaDonDaPhatHanh) {
		t.Fatalf("phát hành lại phải ErrHoaDonDaPhatHanh, nhận %v", err)
	}
	// Thay thế chưa làm với SePay.
	if _, err := s.ThayThe(ctx, 5, &dto.EtaxThayTheRequest{}); !errors.Is(err, domain.ErrETaxChuaHoTro) {
		t.Fatalf("thay thế phải ErrETaxChuaHoTro, nhận %v", err)
	}

	hd, err = s.Ky(ctx, 5)
	if err != nil {
		t.Fatalf("ký: %v", err)
	}
	if hd.Status != domain.HoaDonDaPhatHanh || hd.InvoiceNo != "589" || hd.TaxAuthCode != "M1-26-ABCDE" || hd.GatewayStatus == nil || *hd.GatewayStatus != 4 {
		t.Fatalf("ký xong phải đã phát hành, số 589, có mã CQT: %+v", hd)
	}
	if _, err := s.Ky(ctx, 5); !errors.Is(err, domain.ErrHoaDonDaKy) {
		t.Fatalf("ký lần hai phải ErrHoaDonDaKy, nhận %v", err)
	}

	pdf, err := s.BanIn(ctx, 5, false)
	if err != nil || string(pdf) != "%PDF hd" {
		t.Fatalf("tải PDF: %q %v", pdf, err)
	}
	if so.hd.Response == "" {
		t.Fatal("phải cất mã theo dõi vào response")
	}
}

// Mẫu bán hàng: không gửi trường thuế; tự phát hành bật thì is_draft=false.
func TestSePay_MauBanHangKhongGuiThue(t *testing.T) {
	g := &congSePayGia{}
	s, _ := dungSePay(t, g)
	ketNoiSePay(t, s, "2C26TSE", true)
	g.daPhatHanh = true

	hd, err := s.PhatHanh(context.Background(), 5)
	if err != nil {
		t.Fatalf("phát hành: %v", err)
	}
	than := g.thanTao[0]
	if than["template_code"] != "2" || than["is_draft"] != false {
		t.Fatalf("mẫu 2, phát hành luôn: %v", than)
	}
	for _, d := range than["items"].([]any) {
		if _, co := d.(map[string]any)["tax_rate"]; co {
			t.Fatalf("mẫu bán hàng không được gửi tax_rate: %v", d)
		}
	}
	if than["total_amount"] != 290000.0 || hd.VatAmount != 0 {
		t.Fatalf("tổng không thuế phải 290000, nhận %v / %v", than["total_amount"], hd.VatAmount)
	}
}

// Token quá 24 giờ: đăng nhập lại ĐÚNG một lần rồi gửi tiếp.
func TestSePay_HetTokenThiDangNhapLai(t *testing.T) {
	g := &congSePayGia{}
	s, _ := dungSePay(t, g)
	ketNoiSePay(t, s, "1C26TSE", false)
	g.hetTokenLuotTao = true

	if _, err := s.PhatHanh(context.Background(), 5); err != nil {
		t.Fatalf("hết token phải tự đăng nhập lại: %v", err)
	}
	if g.soToken != 2 || len(g.thanTao) != 1 {
		t.Fatalf("phải lấy token 2 lần, gửi tạo 1 lần tới nơi; nhận %d / %d", g.soToken, len(g.thanTao))
	}
}

// Cổng báo Failed: lưu tờ hỏng kèm câu của cổng; bấm lại mà cổng đã có tờ (409)
// thì nhận lại tờ đó chứ không báo lỗi.
func TestSePay_ThatBaiRoiBamLai(t *testing.T) {
	g := &congSePayGia{ketQuaTao: sepayinvoice.ThatBai}
	s, so := dungSePay(t, g)
	ctx := context.Background()
	ketNoiSePay(t, s, "1C26TSE", false)

	_, err := s.PhatHanh(ctx, 5)
	if err == nil || !strings.Contains(err.Error(), "Mã số thuế người mua không hợp lệ") || !strings.HasPrefix(err.Error(), "sepay: ") {
		t.Fatalf("phải trả câu của cổng (có tiền tố sepay:), nhận %v", err)
	}
	if so.hd == nil || so.hd.Status != domain.HoaDonHong || !strings.Contains(so.hd.Error, "Mã số thuế") {
		t.Fatalf("tờ hỏng phải nằm trong sổ kèm lỗi: %+v", so.hd)
	}

	g.luotTaoTrung, g.coChiTiet = true, true
	hd, err := s.PhatHanh(ctx, 5)
	if err != nil || hd.Status != domain.HoaDonNhap || hd.ID != so.hd.ID {
		t.Fatalf("409 phải nhận lại tờ đã có trên cùng bản ghi: %+v %v", hd, err)
	}
}

// Cổng còn đang xử lý quá thời gian chờ: ghi "đã gửi"; Đồng bộ hỏi tiếp tới khi
// có kết quả.
func TestSePay_DangXuLyRoiDongBo(t *testing.T) {
	g := &congSePayGia{ketQuaTao: sepayinvoice.DangXuLy}
	s, _ := dungSePay(t, g)
	ctx := context.Background()
	ketNoiSePay(t, s, "1C26TSE", false)

	hd, err := s.PhatHanh(ctx, 5)
	if err != nil || hd.Status != domain.HoaDonDaGui {
		t.Fatalf("đang xử lý phải ghi đã gửi, không lỗi: %+v %v", hd, err)
	}
	if _, err := s.DongBo(ctx, 5); err == nil || !strings.Contains(err.Error(), "đang xử lý") {
		t.Fatalf("cổng chưa xong thì Đồng bộ phải báo đang xử lý, nhận %v", err)
	}

	g.mu.Lock()
	g.ketQuaTao = sepayinvoice.ThanhCong
	g.mu.Unlock()
	hd, err = s.DongBo(ctx, 5)
	if err != nil || hd.Status != domain.HoaDonNhap {
		t.Fatalf("cổng xong thì Đồng bộ phải ra nháp: %+v %v", hd, err)
	}
}

// Nhiều tài khoản nhà cung cấp đang bật: không đoán, báo kèm danh sách.
func TestSePay_NhieuTaiKhoanThiKhongDoan(t *testing.T) {
	g := &congSePayGia{taiKhoanBat: 2}
	s, so := dungSePay(t, g)

	_, err := s.KetNoi(context.Background(), 1, &dto.EtaxKetNoiRequest{Provider: "sepay", Username: "cid", Password: "sec"})
	if err == nil || !strings.HasPrefix(err.Error(), "sepay: ") || !strings.Contains(err.Error(), "acc-2") {
		t.Fatalf("phải báo lỗi kèm danh sách tài khoản, nhận %v", err)
	}
	if so.cn != nil {
		t.Fatal("chưa chốt được tài khoản thì không được lưu kết nối")
	}

	// API vẫn nhận account_ref khai rõ.
	cn, err := s.KetNoi(context.Background(), 1, &dto.EtaxKetNoiRequest{Provider: "sepay", Username: "cid", Password: "sec", AccountRef: "acc-1"})
	if err != nil || cn.AccountRef != "acc-1" {
		t.Fatalf("khai acc-1 phải nhận: %+v %v", cn, err)
	}
}

// Khách chỉ khai tên đăng nhập + mật khẩu: sai thì lỗi đăng nhập; tài khoản đã
// nối ở chi nhánh khác thì chặn; M-Invoice vẫn bắt mã số thuế.
func TestSePay_ChiCanDangNhap(t *testing.T) {
	g := &congSePayGia{}
	s, so := dungSePay(t, g)
	ctx := context.Background()

	if _, err := s.KetNoi(ctx, 1, &dto.EtaxKetNoiRequest{Provider: "sepay", Username: "cid", Password: "sai"}); !errors.Is(err, sepayinvoice.ErrDangNhap) {
		t.Fatalf("sai mật khẩu phải ErrDangNhap, nhận %v", err)
	}
	if _, err := s.KetNoi(ctx, 1, &dto.EtaxKetNoiRequest{Provider: "sepay", Username: "da-dung", Password: "sec"}); !errors.Is(err, domain.ErrETaxTaiKhoanDaDung) {
		t.Fatalf("tài khoản đã nối chỗ khác phải ErrETaxTaiKhoanDaDung, nhận %v", err)
	}
	if so.cn != nil {
		t.Fatal("lượt bị từ chối không được lưu kết nối")
	}

	_, err := s.KetNoi(ctx, 1, &dto.EtaxKetNoiRequest{Provider: "minvoice", Username: "u", Password: "p"})
	var o *LoiTheoO
	if !errors.As(err, &o) || o.Fields["tax_code"] == "" {
		t.Fatalf("M-Invoice thiếu mã số thuế phải báo ở ô tax_code, nhận %v", err)
	}
}

func TestApDungHoaDonSePay_TheoTrangThaiNhaCungCap(t *testing.T) {
	ca := []struct {
		ten, trangThai, ncc, maCQT, mong string
	}{
		{"nháp", "draft", "1", "", domain.HoaDonNhap},
		{"đã cấp mã", "issued", "4", "M1", domain.HoaDonDaPhatHanh},
		{"chưa gửi CQT", "issued", "3", "", domain.HoaDonDaGui},
		{"CQT chưa phản hồi", "issued", "5", "", domain.HoaDonDaGui},
		{"lỗi ký", "issued", "2", "", domain.HoaDonHong},
		{"không đủ điều kiện cấp mã", "issued", "6", "", domain.HoaDonHong},
		{"check không có provider_status", "issued", "", "", domain.HoaDonDaPhatHanh},
		{"đã huỷ", "cancelled", "", "", domain.HoaDonHong},
	}
	for _, c := range ca {
		hd := &domain.EtaxInvoice{}
		raw := `{"status":"` + c.trangThai + `","provider_status":"` + c.ncc + `","tax_authority_code":"` + c.maCQT + `"}`
		var h sepayinvoice.HoaDon
		if err := json.Unmarshal([]byte(raw), &h); err != nil {
			t.Fatal(err)
		}
		apDungHoaDonSePay(hd, &h)
		if hd.Status != c.mong {
			t.Errorf("%s: phải %s, nhận %s", c.ten, c.mong, hd.Status)
		}
	}
}
