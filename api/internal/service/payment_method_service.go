package service

import (
	"context"
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"time"
	"unicode"

	"go.uber.org/zap"
	"golang.org/x/text/unicode/norm"

	"sass-api/config"
	"sass-api/internal/domain"
	"sass-api/internal/tenant"
	"sass-api/pkg/bimat"
	"sass-api/pkg/logger"
	"sass-api/pkg/nextpay"
	"sass-api/pkg/payos"
	"sass-api/pkg/sepay"
)

// PaymentMethodService — Cài đặt → Phương thức thanh toán, theo từng chi nhánh.
//
// Luật lấy từ ordertable v2 (System\PaymentMethodController), có ba chỗ cố ý khác:
//   - Không có thao tác "bỏ mặc định". Mặc định luôn là đúng một dòng trong số
//     các dòng đang bật; muốn đổi thì đặt dòng khác. v2 cho bỏ rồi tự chuyển khi
//     có 2 dòng nhưng từ chối khi có từ 3 — hai câu trả lời cho cùng một thao tác.
//   - Phương thức đang tắt không được đặt làm mặc định ở quầy; tắt phương thức
//     đang mặc định thì mặc định về Tiền mặt (v2 để chi nhánh không có mặc định,
//     quầy khi đó cũng mở vào Tiền mặt — nói thẳng ra cho khớp).
//   - Mỗi cổng QR chỉ một dòng mỗi chi nhánh (v2 chỉ chặn mPos QR).
type PaymentMethodService interface {
	DanhSach(ctx context.Context) (*PMDanhSach, error)
	DatTrangThai(ctx context.Context, methodID uint, bat bool) error
	DatMacDinh(ctx context.Context, methodID uint) error
	TaoChiTiet(ctx context.Context, methodID uint, req PMChiTietRequest) (uint, error)
	SuaChiTiet(ctx context.Context, methodID, detailID uint, req PMChiTietRequest) error
	DatTrangThaiChiTiet(ctx context.Context, methodID, detailID uint, bat bool) error
	DatMacDinhChiTiet(ctx context.Context, methodID, detailID uint) error
	XoaChiTiet(ctx context.Context, methodID, detailID uint) error
	TaoMay(ctx context.Context, req PMMayRequest) (*domain.PaymentDevice, error)
	// SuaMay đổi POS ID / tên / ghi chú; loại máy (mPOS hay độc lập) giữ nguyên.
	SuaMay(ctx context.Context, id uint, req PMMayRequest) (*domain.PaymentDevice, error)
	// XoaMay chặn khi máy đang gắn với một cấu hình quẹt thẻ.
	XoaMay(ctx context.Context, id uint) error
	ChoQuay(ctx context.Context) ([]PMQuay, error)
	// KiemTraKetNoi thử cổng QR bằng khoá đã lưu. webhookURL là URL riêng của
	// cổng (Shop Admin dựng từ địa chỉ công khai của API — API không tự biết).
	KiemTraKetNoi(ctx context.Context, methodID, detailID uint, webhookURL string) (*PMKiemTra, error)
	// ChonChoQuay kiểm hình thức + chi tiết thu ngân chọn cho một đơn quầy ở chi
	// nhánh đang làm. nil = không có chi tiết để ghi (tiền mặt, chuyển khoản tay).
	ChonChoQuay(ctx context.Context, method string, detailID uint) (*ChonQuay, error)
	// GhiDonQuay ghi đơn đã thu bằng chi tiết nào — lỗi chỉ ghi log.
	GhiDonQuay(ctx context.Context, orderID uint, chon *ChonQuay, phi float64)
	// GanCauHinhCong đưa phần cấu hình KHÔNG bí mật của hai cổng (địa chỉ máy
	// chủ cổng, trang quay về, hạn link) để dựng client theo khoá của từng tiệm.
	GanCauHinhCong(p config.PayOSConfig, sp config.SePayConfig)

	NguonThanhToanWeb
	NguonCongThanhToan
}

// NguonCongThanhToan — khoá cổng QR theo cửa hàng, cho PaymentService.
type NguonCongThanhToan interface {
	// CongWeb: cổng của chi nhánh trung tâm, ĐANG BẬT và đã khai đủ khoá riêng.
	// nil = cửa hàng chưa tự kết nối, dùng khoá chung trong .env.
	CongWeb(ctx context.Context, loai string) *CongRieng
	// CongChiNhanh: cổng ĐANG BẬT của một chi nhánh cụ thể (đơn quầy dùng cổng của
	// chính chi nhánh bán). nil = chưa khai khoá riêng, dùng khoá chung.
	CongChiNhanh(ctx context.Context, shopID uint, loai string) *CongRieng
	// CongTheoMa: cổng theo mã trong URL webhook. ctx trả về đã gắn cửa hàng.
	CongTheoMa(ctx context.Context, ma, loai string) (context.Context, *CongRieng, error)
	GhiNhanBao(ctx context.Context, detailID uint)
	// CongTheoChiTiet: client của đúng một dòng chi tiết (máy mPOS đã đẩy lệnh,
	// để huỷ lệnh / tính phí theo đúng máy đó).
	CongTheoChiTiet(ctx context.Context, shopID, detailID uint) *CongRieng
	// GhiPhiThe ghi phí quẹt thẻ (tính khi NextPay báo loại thẻ) vào đơn.
	GhiPhiThe(ctx context.Context, orderID, detailID uint, phi float64)
}

// CongRieng — client dựng từ khoá của một cổng QR cụ thể.
type CongRieng struct {
	DetailID uint
	PayOS    *payos.Client
	SePay    *sepay.Client
	// NextPay — QR mPOS và máy quẹt thẻ mPOS. Muid / KieuQR cho QR; Phi là 8 mức
	// phí theo loại thẻ của máy (card_fee_*), tính khi NextPay báo về.
	NextPay *nextpay.Client
	Muid    string
	KieuQR  string
	Phi     map[string]float64
}

// ChonQuay — chi tiết thanh toán đã chọn cho một đơn quầy.
type ChonQuay struct {
	DetailID uint
	// Loai — detail_type của dòng đã chọn. "mpos_device" = máy mPOS: đơn CHỜ máy
	// báo quẹt xong, không ghi đã thu ngay.
	Loai string
	// PosID / Cong — máy mPOS: số serial để đẩy lệnh và client NextPay của máy.
	PosID string
	Cong  *CongRieng
	// TyLePhi: % phí quẹt thẻ cửa hàng chịu (máy độc lập). Máy mPOS tính phí theo
	// loại thẻ do cổng báo — cổng chưa nối nên để 0.
	TyLePhi float64
}

// PMKiemTra — kết quả nút "Kiểm tra kết nối".
type PMKiemTra struct {
	OK         bool   `json:"ok"`
	Cau        string `json:"message"`
	LanBaoCuoi string `json:"last_webhook_at,omitempty"`
}

// NguonThanhToanWeb — website hỏi chi nhánh trung tâm thay cho trang Cài đặt cũ.
// ok=false: không đọc được sổ (lỗi DB) → nơi gọi lùi về cài đặt cũ.
type NguonThanhToanWeb interface {
	CoNhanWeb(ctx context.Context, method string) (nhan bool, ok bool)
	TaiKhoanWeb(ctx context.Context) (tk TaiKhoanNhan, ok bool)
}

// TaiKhoanNhan — tài khoản in cho khách chuyển khoản.
type TaiKhoanNhan struct {
	BIN       string
	NganHang  string
	SoTK      string
	ChuTK     string
	SuaSoTien bool
}

// LoiPT — lỗi nghiệp vụ của màn phương thức thanh toán, handler trả nguyên câu.
type LoiPT struct {
	Ma  int
	Cau string
	O   map[string]string
}

func (e *LoiPT) Error() string { return e.Cau }

func loiPT(ma int, cau string) error { return &LoiPT{Ma: ma, Cau: cau} }

func loiPTO(o map[string]string) error {
	return &LoiPT{Ma: 422, Cau: "Thông tin chưa hợp lệ", O: o}
}

// ---- Khuôn dữ liệu ra / vào ----

type PMDanhSach struct {
	ShopID  uint                   `json:"shop_id"`
	Methods []PMView               `json:"methods"`
	Devices []domain.PaymentDevice `json:"devices"`
	Banks   []domain.NganHang      `json:"banks"`
	// WebShopID: chi nhánh mà website lấy tài khoản — màn hình nói cho người sửa
	// biết thay đổi ở chi nhánh này có chạm tới website hay không.
	WebShopID uint `json:"web_shop_id"`
}

type PMView struct {
	ID        uint            `json:"id"`
	Type      uint8           `json:"type"`
	Status    bool            `json:"status"`
	IsDefault bool            `json:"is_default"`
	Details   []PMChiTietView `json:"details"`
}

type PMChiTietView struct {
	ID uint `json:"id"`
	// WebhookCode: phần cuối URL webhook riêng của cổng — Shop Admin ghép thành URL
	// đầy đủ cho chủ tiệm dán sang SePay / PayOS.
	WebhookCode string            `json:"webhook_code,omitempty"`
	DetailType  string            `json:"detail_type"`
	Status      bool              `json:"status"`
	IsDefault   bool              `json:"is_default"`
	DeviceID    *uint             `json:"device_id"`
	Data        map[string]string `json:"data"`
	// DaCoKhoa: khoá bí mật nào đã lưu. Khoá KHÔNG bao giờ trả ra; ô nhập bỏ
	// trống lúc sửa nghĩa là giữ nguyên.
	DaCoKhoa map[string]bool `json:"saved_secrets"`
}

type PMChiTietRequest struct {
	DetailType string            `json:"detail_type"`
	Status     *bool             `json:"status"`
	IsDefault  bool              `json:"is_default"`
	DeviceID   uint              `json:"device_id"`
	Data       map[string]string `json:"data"`
}

type PMMayRequest struct {
	Code        string `json:"code"`
	Name        string `json:"name"`
	Description string `json:"description"`
	IsPhysical  bool   `json:"is_physical"`
}

// PMQuay — phương thức đang bật cho màn thu ngân, không kèm khoá bí mật.
type PMQuay struct {
	Type      uint8           `json:"type"`
	IsDefault bool            `json:"is_default"`
	Details   []PMQuayChiTiet `json:"details"`
}

type PMQuayChiTiet struct {
	ID              uint              `json:"id"`
	DetailType      string            `json:"detail_type"`
	IsDefault       bool              `json:"is_default"`
	BankBIN         string            `json:"bank_bin,omitempty"`
	BankName        string            `json:"bank_name,omitempty"`
	BankAccount     string            `json:"bank_account,omitempty"`
	BankAccountName string            `json:"bank_account_name,omitempty"`
	EditAmount      bool              `json:"edit_amount,omitempty"`
	DeviceName      string            `json:"device_name,omitempty"`
	CardFee         map[string]string `json:"card_fee,omitempty"`
}

// ---- Luật từng loại chi tiết ----

var (
	khoaBiMat = map[string]bool{
		"mpos_secret_key": true, "sepay_api_key": true, "payos_api_key": true,
		"payos_checksum_key": true, "momo_access_key": true, "momo_secret_key": true,
		"sepay_api_token": true,
	}
	oNganHang = []string{"bank_id", "bank_account", "bank_account_name"}
	phiMPOS   = []string{
		"card_fee_local", "card_fee_domestic", "card_fee_foreign", "card_fee_remote",
		"card_fee_mvisa", "card_fee_qrpay", "card_fee_vimo", "card_fee_momo",
	}
	// oCuaLoai: mọi ô một loại nhận; ô lạ gửi lên bị bỏ qua chứ không cất vào JSON.
	oCuaLoai = map[string][]string{
		"bank_transfer":   {"bank_id", "bank_account", "bank_account_name", "edit_amount"},
		"mpos_device":     append(append([]string{"mpos_merchant_id", "mpos_muid", "mpos_secret_key"}, oNganHang...), phiMPOS...),
		"physical_device": append([]string{"card_fee_physical"}, oNganHang...),
		"sepay":           {"bank_id", "sepay_account", "bank_account_name", "sepay_api_key", "sepay_api_token"},
		"payos":           {"payos_client_id", "payos_api_key", "payos_checksum_key"},
		"mpos_qr":         {"mpos_merchant_id", "mpos_muid", "mpos_secret_key", "mpos_type"},
		"momo":            {"momo_partner_code", "momo_access_key", "momo_secret_key"},
	}
	// batBuocCuaLoai: ô phải có. Khoá bí mật bắt buộc chỉ tính lúc TẠO — sửa mà
	// bỏ trống là giữ khoá cũ.
	batBuocCuaLoai = map[string][]string{
		"bank_transfer":   oNganHang,
		"mpos_device":     append([]string{"mpos_merchant_id", "mpos_muid", "mpos_secret_key"}, oNganHang...),
		"physical_device": oNganHang,
		"sepay":           {"bank_id", "sepay_account", "bank_account_name"},
		"mpos_qr":         {"mpos_merchant_id", "mpos_muid", "mpos_secret_key", "mpos_type"},
		"momo":            {"momo_partner_code", "momo_access_key", "momo_secret_key"},
	}
	reSoTK  = regexp.MustCompile(`^[A-Za-z0-9]+$`)
	reChuTK = regexp.MustCompile(`^[A-Z0-9 ]+$`)
	tenOPT  = map[string]string{
		"bank_id": "ngân hàng", "bank_account": "số tài khoản", "bank_account_name": "tên chủ tài khoản",
		"sepay_account": "số tài khoản nhận", "mpos_merchant_id": "Merchant ID", "mpos_muid": "Mobile User",
		"mpos_secret_key": "Secret Key", "mpos_type": "kiểu nhận tiền", "momo_partner_code": "Partner Code",
		"momo_access_key": "Access Key", "momo_secret_key": "Secret Key", "payos_client_id": "Client ID",
		"payos_api_key": "API Key", "payos_checksum_key": "Checksum Key", "sepay_api_key": "API key",
		"sepay_api_token": "API token",
	}
	tenPT = map[uint8]string{
		domain.PTTienMat: "Tiền mặt", domain.PTChuyenKhoan: "Chuyển khoản",
		domain.PTQuetThe: "Quẹt thẻ", domain.PTQRTuDong: "QR tự động",
	}
	donViPT = map[uint8]string{domain.PTChuyenKhoan: "tài khoản", domain.PTQuetThe: "máy quẹt thẻ", domain.PTQRTuDong: "cổng QR"}
	tenQR   = map[string]string{"sepay": "SePay", "payos": "PayOS", "mpos_qr": "mPos QR", "momo": "Ví MoMo"}
)

// coMacDinhCon: phương thức có "dòng mặc định" (tài khoản in QR, máy chọn sẵn).
func coMacDinhCon(t uint8) bool { return t == domain.PTChuyenKhoan || t == domain.PTQuetThe }

// BoDauHoa — "Nguyễn Văn Đức" → "NGUYEN VAN DUC", đúng như app ngân hàng in.
func BoDauHoa(s string) string {
	s = strings.NewReplacer("đ", "d", "Đ", "D").Replace(s)
	var b strings.Builder
	for _, r := range norm.NFD.String(s) {
		if unicode.Is(unicode.Mn, r) {
			continue
		}
		b.WriteRune(r)
	}

	return strings.ToUpper(strings.Join(strings.Fields(b.String()), " "))
}

type paymentMethodService struct {
	repo     domain.PaymentMethodRepository
	settings SettingService
	hop      *bimat.Hop
	now      func() time.Time
	payosCfg config.PayOSConfig
	sepayCfg config.SePayConfig
}

func (s *paymentMethodService) GanCauHinhCong(p config.PayOSConfig, sp config.SePayConfig) {
	s.payosCfg, s.sepayCfg = p, sp
}

// coWebhook: cổng nhận báo tiền qua URL riêng (mPOS / MoMo chưa nối).
func coWebhook(loai string) bool {
	switch loai {
	case "sepay", "payos", "mpos_qr", "mpos_device":
		return true
	}

	return false
}

func maWebhookMoi() string {
	b := make([]byte, 16)
	_, _ = rand.Read(b)

	return hex.EncodeToString(b)
}

func NewPaymentMethodService(repo domain.PaymentMethodRepository, settings SettingService, hop *bimat.Hop) PaymentMethodService {
	return &paymentMethodService{repo: repo, settings: settings, hop: hop, now: time.Now}
}

// ---- Dựng sẵn ----

// dungSan bảo đảm chi nhánh có đủ 4 phương thức. Lượt dựng đầu tiên chuyển luôn
// cấu hình của trang Cài đặt cũ sang: tài khoản ngân hàng (cũ là MỘT tài khoản
// cho cả cửa hàng, nên chi nhánh nào cũng nhận một bản), công tắc PayOS/SePay.
// Nhờ vậy quầy và website chạy y như hôm qua cho tới khi chủ tiệm sửa.
func (s *paymentMethodService) dungSan(ctx context.Context, shopID uint) error {
	ds, err := s.repo.PhuongThuc(ctx, shopID)
	if err != nil {
		return err
	}
	if len(ds) >= len(domain.CacLoaiPT) {
		return nil
	}

	cu := s.cauHinhCu(ctx)
	moi := make([]domain.PaymentMethod, 0, len(domain.CacLoaiPT))
	for _, t := range domain.CacLoaiPT {
		m := domain.PaymentMethod{ShopID: shopID, Type: t, Status: t == domain.PTTienMat, IsDefault: t == domain.PTTienMat}
		if t == domain.PTChuyenKhoan {
			m.Status = cu.bat && cu.tk != nil
		}
		if t == domain.PTQRTuDong {
			m.Status = cu.payos || cu.sepay
		}
		moi = append(moi, m)
	}
	_, err = s.repo.TaoSan(ctx, shopID, moi, func(id map[uint8]uint) []domain.PaymentMethodDetail {
		var ct []domain.PaymentMethodDetail
		if cu.tk != nil {
			ct = append(ct, s.chiTietMoi(shopID, id[domain.PTChuyenKhoan], "bank_transfer", cu.bat, true, cu.tk))
		}
		// PayOS/SePay cũ: khoá nằm ở .env, dòng chuyển sang để trống khoá — đúng
		// nghĩa "chi nhánh chưa khai thì dùng khoá chung".
		if cu.payos {
			ct = append(ct, s.chiTietMoi(shopID, id[domain.PTQRTuDong], "payos", true, false, map[string]string{}))
		}
		if cu.sepay {
			ct = append(ct, s.chiTietMoi(shopID, id[domain.PTQRTuDong], "sepay", true, false, map[string]string{}))
		}

		return ct
	})

	return err
}

func (s *paymentMethodService) chiTietMoi(shopID, methodID uint, loai string, bat, md bool, data map[string]string) domain.PaymentMethodDetail {
	raw, _ := json.Marshal(data)
	str := string(raw)

	d := domain.PaymentMethodDetail{
		ShopID: shopID, PaymentMethodID: methodID, DetailType: loai,
		Status: bat, IsDefault: md && bat, Data: &str,
	}
	if coWebhook(loai) {
		ma := maWebhookMoi()
		d.WebhookCode = &ma
	}

	return d
}

type cauHinhCu struct {
	bat          bool
	tk           map[string]string
	payos, sepay bool
}

func (s *paymentMethodService) cauHinhCu(ctx context.Context) cauHinhCu {
	var cu cauHinhCu
	if s.settings == nil {
		return cu
	}
	ten := strings.TrimSpace(s.settings.Get(ctx, SettingBankName))
	so := strings.TrimSpace(s.settings.Get(ctx, SettingBankAccountNumber))
	chu := strings.TrimSpace(s.settings.Get(ctx, SettingBankAccountName))
	if ten != "" && so != "" && chu != "" {
		cu.tk = map[string]string{
			"bank_id": DoBIN(ten), "bank_account": so, "bank_account_name": BoDauHoa(chu),
		}
		if cu.tk["bank_id"] == "" {
			// Tên gõ tự do không khớp ngân hàng nào: giữ tên cũ để quầy và website
			// vẫn in được, màn hình bắt chọn lại ngân hàng khi sửa.
			cu.tk["bank_name_old"] = ten
		}
	}
	cu.bat = s.settings.Bool(ctx, SettingPaymentBankEnabled)
	cu.payos = s.settings.Bool(ctx, SettingPaymentPayOSEnabled)
	cu.sepay = s.settings.Bool(ctx, SettingPaymentSePayEnabled)

	return cu
}

var biDanhNH = map[string]string{
	"vcb": "970436", "ngoaithuong": "970436", "ctg": "970415", "congthuong": "970415",
	"tcb": "970407", "kythuong": "970407", "mb": "970422", "quandoi": "970422",
	"vpb": "970432", "stb": "970403", "agri": "970405", "bvbank": "970454", "banviet": "970454",
	"lienviet": "970449", "lpb": "970449", "dongnama": "970440", "achau": "970416",
}

// DoBIN đoán mã BIN từ tên ngân hàng gõ tự do ("Vietcombank — CN Tân Bình",
// "MB Bank", "VCB"). Không chắc thì trả "" — thà bắt chọn lại còn hơn in QR vào
// ngân hàng khác.
func DoBIN(ten string) string {
	chuan := func(s string) string {
		var b strings.Builder
		for _, r := range strings.ToLower(BoDauHoa(s)) {
			if unicode.IsLetter(r) || unicode.IsDigit(r) {
				b.WriteRune(r)
			}
		}

		return b.String()
	}
	t := chuan(ten)
	if t == "" {
		return ""
	}
	tot, dai := "", 0
	for _, n := range domain.NganHangVN {
		if c := chuan(n.Ten); strings.Contains(t, c) && len(c) > dai {
			tot, dai = n.BIN, len(c)
		}
	}
	if tot != "" {
		return tot
	}
	for k, bin := range biDanhNH {
		if t == k || strings.HasPrefix(t, k) && len(k) >= 3 {
			return bin
		}
	}

	return ""
}

// ---- Đọc ----

func (s *paymentMethodService) docChiNhanh(ctx context.Context, shopID uint) ([]domain.PaymentMethod, []domain.PaymentMethodDetail, error) {
	if err := s.dungSan(ctx, shopID); err != nil {
		return nil, nil, err
	}
	ms, err := s.repo.PhuongThuc(ctx, shopID)
	if err != nil {
		return nil, nil, err
	}
	ct, err := s.repo.ChiTiet(ctx, shopID)

	return ms, ct, err
}

func (s *paymentMethodService) DanhSach(ctx context.Context) (*PMDanhSach, error) {
	shopID, err := s.repo.ChiNhanhGhi(ctx)
	if err != nil {
		return nil, err
	}
	ms, ct, err := s.docChiNhanh(ctx, shopID)
	if err != nil {
		return nil, err
	}
	may, err := s.repo.MayQuetThe(ctx, shopID)
	if err != nil {
		return nil, err
	}
	web, _ := s.repo.ChiNhanhTrungTam(ctx)

	out := &PMDanhSach{ShopID: shopID, Devices: may, Banks: domain.NganHangVN, WebShopID: web}
	for _, m := range ms {
		v := PMView{ID: m.ID, Type: m.Type, Status: m.Status, IsDefault: m.IsDefault, Details: []PMChiTietView{}}
		for _, d := range ct {
			if d.PaymentMethodID != m.ID {
				continue
			}
			// Cổng tạo trước migration 0078 chưa có mã: gán ngay lúc có người mở màn.
			if coWebhook(d.DetailType) && (d.WebhookCode == nil || *d.WebhookCode == "") {
				ma := maWebhookMoi()
				if err := s.repo.GanMaWebhook(ctx, d.ID, ma); err != nil {
					return nil, err
				}
				d.WebhookCode = &ma
			}
			v.Details = append(v.Details, s.xemChiTiet(d))
		}
		out.Methods = append(out.Methods, v)
	}

	return out, nil
}

func (s *paymentMethodService) xemChiTiet(d domain.PaymentMethodDetail) PMChiTietView {
	data := giaiData(d.Data)
	v := PMChiTietView{
		ID: d.ID, DetailType: d.DetailType, Status: d.Status, IsDefault: d.IsDefault,
		DeviceID: d.DeviceID, Data: map[string]string{}, DaCoKhoa: map[string]bool{},
	}
	if d.WebhookCode != nil {
		v.WebhookCode = *d.WebhookCode
	}
	for k, val := range data {
		if khoaBiMat[k] {
			v.DaCoKhoa[k] = val != ""
			continue
		}
		v.Data[k] = val
	}
	if bin := data["bank_id"]; bin != "" {
		v.Data["bank_name"] = domain.TenNganHang(bin)
	} else if cu := data["bank_name_old"]; cu != "" {
		v.Data["bank_name"] = cu
	}

	return v
}

func giaiData(raw *string) map[string]string {
	out := map[string]string{}
	if raw != nil && *raw != "" {
		_ = json.Unmarshal([]byte(*raw), &out)
	}

	return out
}

// ---- Phương thức ----

func timPT(ms []domain.PaymentMethod, id uint) *domain.PaymentMethod {
	for i := range ms {
		if ms[i].ID == id {
			return &ms[i]
		}
	}

	return nil
}

func (s *paymentMethodService) ghi(ctx context.Context, fn func(shopID uint, tx domain.PaymentMethodTx) error) error {
	shopID, err := s.repo.ChiNhanhGhi(ctx)
	if err != nil {
		return err
	}
	if err := s.dungSan(ctx, shopID); err != nil {
		return err
	}

	return s.repo.Ghi(ctx, shopID, func(tx domain.PaymentMethodTx) error { return fn(shopID, tx) })
}

func (s *paymentMethodService) DatTrangThai(ctx context.Context, methodID uint, bat bool) error {
	return s.ghi(ctx, func(_ uint, tx domain.PaymentMethodTx) error {
		ms, err := tx.PhuongThuc()
		if err != nil {
			return err
		}
		m := timPT(ms, methodID)
		if m == nil {
			return domain.ErrNotFound
		}
		if m.Type == domain.PTTienMat {
			if !bat {
				return loiPT(409, "Tiền mặt luôn bật ở quầy.")
			}
			return nil
		}
		if bat {
			ct, err := tx.ChiTiet()
			if err != nil {
				return err
			}
			cua := locChiTiet(ct, m.ID)
			if len(cua) == 0 {
				return loiPT(409, "Cần thêm ít nhất một "+donViPT[m.Type]+" trước khi bật "+tenPT[m.Type]+".")
			}
			// v2: bật lại mà không còn dòng nào bật thì tự bật dòng cũ nhất.
			if len(dangBat(cua)) == 0 {
				cua[0].Status = true
				if err := tx.LuuChiTiet(&cua[0]); err != nil {
					return err
				}
			}
			if err := chuanHoaMacDinh(tx, m.Type, m.ID); err != nil {
				return err
			}
		}
		m.Status = bat
		if !bat && m.IsDefault {
			m.IsDefault = false
			for i := range ms {
				if ms[i].Type == domain.PTTienMat {
					ms[i].IsDefault = true
					if err := tx.LuuPhuongThuc(&ms[i]); err != nil {
						return err
					}
				}
			}
		}

		return tx.LuuPhuongThuc(m)
	})
}

func (s *paymentMethodService) DatMacDinh(ctx context.Context, methodID uint) error {
	return s.ghi(ctx, func(_ uint, tx domain.PaymentMethodTx) error {
		ms, err := tx.PhuongThuc()
		if err != nil {
			return err
		}
		m := timPT(ms, methodID)
		if m == nil {
			return domain.ErrNotFound
		}
		if !m.Status {
			return loiPT(409, "Không thể đặt làm mặc định khi "+tenPT[m.Type]+" đang tắt.")
		}
		for i := range ms {
			ms[i].IsDefault = ms[i].ID == m.ID
			if err := tx.LuuPhuongThuc(&ms[i]); err != nil {
				return err
			}
		}

		return nil
	})
}

// ---- Chi tiết ----

func locChiTiet(ct []domain.PaymentMethodDetail, methodID uint) []domain.PaymentMethodDetail {
	var ra []domain.PaymentMethodDetail
	for _, d := range ct {
		if d.PaymentMethodID == methodID {
			ra = append(ra, d)
		}
	}

	return ra
}

func dangBat(ct []domain.PaymentMethodDetail) []domain.PaymentMethodDetail {
	var ra []domain.PaymentMethodDetail
	for _, d := range ct {
		if d.Status {
			ra = append(ra, d)
		}
	}

	return ra
}

// chuanHoaMacDinh giữ bất biến của v2 (normalizeDefaultDetail): trong các dòng
// ĐANG BẬT có đúng một mặc định; dòng tắt không bao giờ là mặc định. Không dòng
// nào được đánh dấu thì dòng bật cũ nhất nhận.
func chuanHoaMacDinh(tx domain.PaymentMethodTx, loai uint8, methodID uint) error {
	if !coMacDinhCon(loai) {
		return nil
	}
	ct, err := tx.ChiTiet()
	if err != nil {
		return err
	}
	cua := locChiTiet(ct, methodID)
	giu := uint(0)
	for _, d := range cua {
		if d.Status && d.IsDefault {
			giu = d.ID
			break
		}
	}
	if giu == 0 {
		for _, d := range cua {
			if d.Status {
				giu = d.ID
				break
			}
		}
	}
	for i := range cua {
		muon := cua[i].ID == giu
		if cua[i].IsDefault != muon {
			cua[i].IsDefault = muon
			if err := tx.LuuChiTiet(&cua[i]); err != nil {
				return err
			}
		}
	}

	return nil
}

func (s *paymentMethodService) TaoChiTiet(ctx context.Context, methodID uint, req PMChiTietRequest) (uint, error) {
	var idMoi uint
	err := s.ghi(ctx, func(shopID uint, tx domain.PaymentMethodTx) error {
		ms, err := tx.PhuongThuc()
		if err != nil {
			return err
		}
		m := timPT(ms, methodID)
		if m == nil {
			return domain.ErrNotFound
		}
		ct, err := tx.ChiTiet()
		if err != nil {
			return err
		}
		d := domain.PaymentMethodDetail{ShopID: shopID, PaymentMethodID: m.ID, Status: true}
		if err := s.apChiTiet(tx, m, ct, &d, req, true); err != nil {
			return err
		}
		if coWebhook(d.DetailType) {
			ma := maWebhookMoi()
			d.WebhookCode = &ma
		}
		// Dòng bật đầu tiên của một danh sách luôn là mặc định — giống hộp thoại
		// tự tích sẵn ô Mặc định khi danh sách còn trống.
		if coMacDinhCon(m.Type) && d.Status && len(dangBat(locChiTiet(ct, m.ID))) == 0 {
			d.IsDefault = true
		}
		if err := tx.LuuChiTiet(&d); err != nil {
			return err
		}
		idMoi = d.ID

		return s.sauKhiGhi(tx, m, d)
	})

	return idMoi, err
}

func (s *paymentMethodService) SuaChiTiet(ctx context.Context, methodID, detailID uint, req PMChiTietRequest) error {
	return s.ghi(ctx, func(_ uint, tx domain.PaymentMethodTx) error {
		m, d, ct, err := s.timChiTiet(tx, methodID, detailID)
		if err != nil {
			return err
		}
		if req.DetailType != "" && req.DetailType != d.DetailType {
			return loiPTO(map[string]string{"detail_type": "Không đổi được loại — xoá rồi thêm dòng mới."})
		}
		req.DetailType = d.DetailType
		if req.Status != nil && !*req.Status && d.Status && m.Status && len(dangBat(locChiTiet(ct, m.ID))) == 1 {
			return loiPT(409, "Đây là "+donViPT[m.Type]+" duy nhất đang bật — không tắt được khi "+tenPT[m.Type]+" đang bật.")
		}
		if err := s.apChiTiet(tx, m, ct, d, req, false); err != nil {
			return err
		}
		if err := tx.LuuChiTiet(d); err != nil {
			return err
		}

		return s.sauKhiGhi(tx, m, *d)
	})
}

// sauKhiGhi: dòng vừa ghi được tích mặc định thì gỡ cờ ở các dòng khác, rồi
// chuẩn hoá lại cả danh sách.
func (s *paymentMethodService) sauKhiGhi(tx domain.PaymentMethodTx, m *domain.PaymentMethod, d domain.PaymentMethodDetail) error {
	if coMacDinhCon(m.Type) && d.IsDefault {
		ct, err := tx.ChiTiet()
		if err != nil {
			return err
		}
		for _, o := range locChiTiet(ct, m.ID) {
			if o.ID != d.ID && o.IsDefault {
				o.IsDefault = false
				if err := tx.LuuChiTiet(&o); err != nil {
					return err
				}
			}
		}
	}

	return chuanHoaMacDinh(tx, m.Type, m.ID)
}

func (s *paymentMethodService) timChiTiet(tx domain.PaymentMethodTx, methodID, detailID uint) (*domain.PaymentMethod, *domain.PaymentMethodDetail, []domain.PaymentMethodDetail, error) {
	ms, err := tx.PhuongThuc()
	if err != nil {
		return nil, nil, nil, err
	}
	m := timPT(ms, methodID)
	if m == nil {
		return nil, nil, nil, domain.ErrNotFound
	}
	ct, err := tx.ChiTiet()
	if err != nil {
		return nil, nil, nil, err
	}
	for i := range ct {
		if ct[i].ID == detailID && ct[i].PaymentMethodID == m.ID {
			return m, &ct[i], ct, nil
		}
	}

	return nil, nil, nil, domain.ErrNotFound
}

// apChiTiet kiểm và chép dữ liệu yêu cầu vào d.
func (s *paymentMethodService) apChiTiet(
	tx domain.PaymentMethodTx, m *domain.PaymentMethod, ct []domain.PaymentMethodDetail,
	d *domain.PaymentMethodDetail, req PMChiTietRequest, tao bool,
) error {
	loai := strings.TrimSpace(req.DetailType)
	hop := false
	for _, l := range domain.LoaiChiTietPT[m.Type] {
		hop = hop || l == loai
	}
	if !hop {
		return loiPTO(map[string]string{"detail_type": "Loại này không thuộc " + tenPT[m.Type] + "."})
	}
	d.DetailType = loai
	if req.Status != nil {
		d.Status = *req.Status
	}
	if req.IsDefault && !d.Status {
		return loiPT(422, "Không thể đặt làm mặc định khi trạng thái đang tắt.")
	}
	if coMacDinhCon(m.Type) && req.IsDefault {
		d.IsDefault = true
	}

	cu := giaiData(d.Data)
	moi := map[string]string{}
	o := map[string]string{}
	vao := req.Data
	if vao == nil {
		vao = map[string]string{}
	}
	for _, k := range oCuaLoai[loai] {
		v := strings.TrimSpace(vao[k])
		if khoaBiMat[k] {
			if v == "" {
				// Bỏ trống lúc sửa = giữ khoá đã lưu.
				moi[k] = cu[k]
				continue
			}
			ma, err := s.hop.Ma(v)
			if err != nil {
				if errors.Is(err, bimat.ErrChuaCoKhoa) {
					return loiPT(503, "Máy chủ chưa khai khoá mã hoá (ETAX_SECRET_KEY) nên chưa lưu được khoá bí mật.")
				}
				return err
			}
			moi[k] = ma
			continue
		}
		moi[k] = v
	}
	for _, k := range batBuocCuaLoai[loai] {
		if moi[k] == "" {
			o[k] = "Chưa nhập " + tenOPT[k] + "."
		}
	}

	// Ngân hàng: mã BIN phải có trong danh sách VietQR; số và tên theo đúng luật v2.
	if _, co := moi["bank_id"]; co && moi["bank_id"] != "" && domain.TenNganHang(moi["bank_id"]) == "" {
		o["bank_id"] = "Ngân hàng không có trong danh sách."
	}
	for _, k := range []string{"bank_account", "sepay_account"} {
		if v := moi[k]; v != "" && (!reSoTK.MatchString(v) || len(v) > 50) {
			o[k] = "Số tài khoản chỉ gồm chữ và số, không khoảng trắng, tối đa 50 ký tự."
		}
	}
	if v, co := moi["bank_account_name"]; co && v != "" {
		v = BoDauHoa(v)
		moi["bank_account_name"] = v
		if !reChuTK.MatchString(v) || len([]rune(v)) > 100 {
			o["bank_account_name"] = "Tên chủ tài khoản chỉ gồm chữ, số và khoảng trắng."
		}
	}
	if _, co := moi["edit_amount"]; co {
		moi["edit_amount"] = boolChuoi(moi["edit_amount"])
	}
	if loai == "mpos_qr" && moi["mpos_type"] != "VAQR" && moi["mpos_type"] != "VAMC" {
		o["mpos_type"] = "Kiểu nhận tiền chỉ là VAQR hoặc VAMC."
	}
	// SePay tự kết nối: phải có khoá webhook hoặc API token — thiếu cả hai thì
	// không có cách nào biết tiền đã về.
	if loai == "sepay" && moi["sepay_api_key"] == "" && moi["sepay_api_token"] == "" {
		o["sepay_api_key"] = "Nhập API key webhook (hoặc API token) của SePay."
	}
	// PayOS: ba khoá đi cùng nhau, hoặc để trống cả ba (dùng khoá chung trong .env).
	if loai == "payos" {
		co := 0
		for _, k := range oCuaLoai["payos"] {
			if moi[k] != "" {
				co++
			}
		}
		if co > 0 && co < 3 {
			for _, k := range oCuaLoai["payos"] {
				if moi[k] == "" {
					o[k] = "Nhập đủ cả ba khoá PayOS, hoặc để trống cả ba để dùng khoá chung."
				}
			}
		}
	}
	for _, k := range append(append([]string{}, phiMPOS...), "card_fee_physical") {
		if _, co := moi[k]; !co {
			continue
		}
		phi, loi := chuanPhi(moi[k])
		if loi != "" {
			o[k] = loi
		}
		moi[k] = phi
	}

	// Không trùng trong chi nhánh: cùng tài khoản ngân hàng, cùng cổng QR.
	for _, k := range ct {
		if k.ID == d.ID || k.PaymentMethodID != m.ID {
			continue
		}
		if m.Type == domain.PTQRTuDong && k.DetailType == loai {
			o["detail_type"] = "Chi nhánh đã có cổng " + tenQR[loai] + " — sửa dòng đang có thay vì tạo thêm."
		}
		if loai == "bank_transfer" {
			kd := giaiData(k.Data)
			if kd["bank_id"] == moi["bank_id"] && kd["bank_account"] == moi["bank_account"] {
				o["bank_account"] = "Tài khoản này đã có trong danh sách."
			}
		}
	}

	// Máy quẹt thẻ: có thật, đúng loại máy, chưa gắn với cấu hình khác.
	if m.Type == domain.PTQuetThe {
		if req.DeviceID == 0 {
			o["device_id"] = "Chọn máy áp dụng."
		} else {
			may, err := tx.May(req.DeviceID)
			switch {
			case errors.Is(err, domain.ErrNotFound):
				o["device_id"] = "Máy không thuộc chi nhánh này."
			case err != nil:
				return err
			case may.IsPhysical != (loai == "physical_device"):
				o["device_id"] = "Máy này không đúng loại đã chọn."
			}
			for _, k := range ct {
				if k.ID != d.ID && k.DeviceID != nil && *k.DeviceID == req.DeviceID {
					o["device_id"] = "Máy này đã gắn với một cấu hình quẹt thẻ khác."
				}
			}
			id := req.DeviceID
			d.DeviceID = &id
		}
	}
	if len(o) > 0 {
		return loiPTO(o)
	}
	if !tao {
		// Giữ lại tên ngân hàng cũ (chưa dò ra mã) cho tới khi người dùng chọn ngân hàng.
		if cu["bank_name_old"] != "" && moi["bank_id"] == "" {
			moi["bank_name_old"] = cu["bank_name_old"]
		}
	}
	raw, err := json.Marshal(moi)
	if err != nil {
		return err
	}
	str := string(raw)
	d.Data = &str

	// Thêm/sửa một dòng TẮT trong khi phương thức đang bật mà không còn dòng
	// bật nào khác: phương thức sẽ bật mà không dùng được.
	if !d.Status && m.Status {
		con := 0
		for _, k := range dangBat(locChiTiet(ct, m.ID)) {
			if k.ID != d.ID {
				con++
			}
		}
		if con == 0 {
			return loiPT(409, "Phải còn ít nhất một "+donViPT[m.Type]+" đang bật khi "+tenPT[m.Type]+" đang bật.")
		}
	}

	return nil
}

func boolChuoi(v string) string {
	if v == "1" || strings.EqualFold(v, "true") || v == "on" {
		return "1"
	}

	return "0"
}

// chuanPhi: phần trăm 0–10, giữ hai số lẻ, nhận cả dấu phẩy. Trống = 0.
func chuanPhi(v string) (string, string) {
	v = strings.ReplaceAll(strings.TrimSpace(v), ",", ".")
	if v == "" {
		return "0.00", ""
	}
	f, err := strconv.ParseFloat(v, 64)
	if err != nil || f < 0 {
		return v, "Phí phải là số từ 0 đến 10."
	}
	if f > 10 {
		return v, "Phí không được lớn hơn 10%."
	}

	return fmt.Sprintf("%.2f", float64(int64(f*100+0.5))/100), ""
}

func (s *paymentMethodService) DatTrangThaiChiTiet(ctx context.Context, methodID, detailID uint, bat bool) error {
	return s.ghi(ctx, func(_ uint, tx domain.PaymentMethodTx) error {
		m, d, ct, err := s.timChiTiet(tx, methodID, detailID)
		if err != nil {
			return err
		}
		if !bat && d.Status && m.Status && len(dangBat(locChiTiet(ct, m.ID))) == 1 {
			return loiPT(409, "Đây là "+donViPT[m.Type]+" duy nhất đang bật của "+tenPT[m.Type]+". Tắt "+tenPT[m.Type]+" trước, hoặc bật dòng khác.")
		}
		d.Status = bat
		if !bat {
			d.IsDefault = false
		}
		if err := tx.LuuChiTiet(d); err != nil {
			return err
		}

		return chuanHoaMacDinh(tx, m.Type, m.ID)
	})
}

func (s *paymentMethodService) DatMacDinhChiTiet(ctx context.Context, methodID, detailID uint) error {
	return s.ghi(ctx, func(_ uint, tx domain.PaymentMethodTx) error {
		m, d, ct, err := s.timChiTiet(tx, methodID, detailID)
		if err != nil {
			return err
		}
		if !coMacDinhCon(m.Type) {
			return loiPT(422, tenPT[m.Type]+" không có dòng mặc định.")
		}
		if !d.Status {
			return loiPT(409, "Bật trạng thái trước rồi mới đặt làm mặc định.")
		}
		for _, k := range locChiTiet(ct, m.ID) {
			muon := k.ID == d.ID
			if k.IsDefault != muon {
				k.IsDefault = muon
				if err := tx.LuuChiTiet(&k); err != nil {
					return err
				}
			}
		}

		return nil
	})
}

func (s *paymentMethodService) XoaChiTiet(ctx context.Context, methodID, detailID uint) error {
	return s.ghi(ctx, func(_ uint, tx domain.PaymentMethodTx) error {
		m, d, ct, err := s.timChiTiet(tx, methodID, detailID)
		if err != nil {
			return err
		}
		if d.Status && m.Status && len(dangBat(locChiTiet(ct, m.ID))) == 1 {
			return loiPT(409, "Không xoá được "+donViPT[m.Type]+" duy nhất đang bật khi "+tenPT[m.Type]+" đang bật.")
		}
		if n, err := s.repo.SoDonDung(ctx, d.ID); err != nil {
			// Chưa chạy migration 0080 thì chưa có cột để đếm — coi như chưa có đơn.
			logger.Warn("không đếm được đơn dùng chi tiết thanh toán", zap.Uint("detail_id", d.ID), zap.Error(err))
		} else if n > 0 {
			return loiPT(409, fmt.Sprintf("Đã có %d đơn thu bằng dòng này nên không xoá được — tắt trạng thái thay vì xoá.", n))
		}
		if n, err := s.repo.SoPhieuThuChiDung(ctx, d.ID); err != nil {
			// Chưa chạy migration 0095 thì chưa có cột — coi như chưa có phiếu.
			logger.Warn("không đếm được phiếu thu chi dùng tài khoản", zap.Uint("detail_id", d.ID), zap.Error(err))
		} else if n > 0 {
			return loiPT(409, fmt.Sprintf("Đã có %d phiếu thu chi ghi tài khoản này nên không xoá được — tắt trạng thái thay vì xoá.", n))
		}
		if err := tx.XoaChiTiet(d.ID); err != nil {
			return err
		}

		return chuanHoaMacDinh(tx, m.Type, m.ID)
	})
}

func (s *paymentMethodService) TaoMay(ctx context.Context, req PMMayRequest) (*domain.PaymentDevice, error) {
	shopID, err := s.repo.ChiNhanhGhi(ctx)
	if err != nil {
		return nil, err
	}
	ds, err := s.repo.MayQuetThe(ctx, shopID)
	if err != nil {
		return nil, err
	}
	d := &domain.PaymentDevice{ShopID: shopID, IsPhysical: req.IsPhysical}
	if err := ganMay(d, req, ds); err != nil {
		return nil, err
	}

	return d, s.repo.TaoMay(ctx, d)
}

func (s *paymentMethodService) SuaMay(ctx context.Context, id uint, req PMMayRequest) (*domain.PaymentDevice, error) {
	var d *domain.PaymentDevice
	err := s.ghi(ctx, func(_ uint, tx domain.PaymentMethodTx) error {
		var err error
		if d, err = tx.May(id); err != nil {
			return err
		}
		ds, err := tx.DanhSachMay()
		if err != nil {
			return err
		}
		if err := ganMay(d, req, ds); err != nil {
			return err
		}

		return tx.LuuMay(d)
	})

	return d, err
}

func (s *paymentMethodService) XoaMay(ctx context.Context, id uint) error {
	return s.ghi(ctx, func(_ uint, tx domain.PaymentMethodTx) error {
		if _, err := tx.May(id); err != nil {
			return err
		}
		ct, err := tx.ChiTiet()
		if err != nil {
			return err
		}
		for _, d := range ct {
			if d.DeviceID != nil && *d.DeviceID == id {
				return loiPT(409, "Máy đang gắn với một cấu hình quẹt thẻ — xoá cấu hình đó (hoặc đổi sang máy khác) rồi mới xoá máy.")
			}
		}

		return tx.XoaMay(id)
	})
}

// ganMay kiểm và chép ô nhập vào máy. POS ID không trùng trong chi nhánh (so
// không phân biệt hoa thường, bỏ qua chính máy đang sửa).
func ganMay(d *domain.PaymentDevice, req PMMayRequest, ds []domain.PaymentDevice) error {
	o := map[string]string{}
	code, ten := strings.TrimSpace(req.Code), strings.TrimSpace(req.Name)
	if code == "" || len(code) > 50 {
		o["code"] = "Nhập POS ID (tối đa 50 ký tự)."
	}
	if ten == "" || len([]rune(ten)) > 100 {
		o["name"] = "Nhập tên máy (tối đa 100 ký tự)."
	}
	mt := strings.TrimSpace(req.Description)
	if len([]rune(mt)) > 255 {
		o["description"] = "Ghi chú tối đa 255 ký tự."
	}
	for _, k := range ds {
		if k.ID != d.ID && code != "" && strings.EqualFold(k.Code, code) {
			o["code"] = "Chi nhánh đã có máy mang POS ID này."
		}
	}
	if len(o) > 0 {
		return loiPTO(o)
	}
	d.Code, d.Name, d.Description = code, ten, domain.StringOrNull(mt)

	return nil
}

// ---- Quầy ----

func (s *paymentMethodService) ChoQuay(ctx context.Context) ([]PMQuay, error) {
	shopID, err := s.repo.ChiNhanhGhi(ctx)
	if err != nil {
		return nil, err
	}
	ms, ct, err := s.docChiNhanh(ctx, shopID)
	if err != nil {
		return nil, err
	}
	may, err := s.repo.MayQuetThe(ctx, shopID)
	if err != nil {
		return nil, err
	}
	tenMay := map[uint]string{}
	for _, d := range may {
		tenMay[d.ID] = d.Code + " · " + d.Name
	}

	out := []PMQuay{}
	for _, m := range ms {
		if !m.Status {
			continue
		}
		q := PMQuay{Type: m.Type, IsDefault: m.IsDefault, Details: []PMQuayChiTiet{}}
		for _, d := range dangBat(locChiTiet(ct, m.ID)) {
			data := giaiData(d.Data)
			c := PMQuayChiTiet{
				ID: d.ID, DetailType: d.DetailType, IsDefault: d.IsDefault,
				BankBIN: data["bank_id"], BankName: domain.TenNganHang(data["bank_id"]),
				BankAccount: firstNonEmpty(data["bank_account"], data["sepay_account"]), BankAccountName: data["bank_account_name"],
				EditAmount: data["edit_amount"] == "1",
			}
			if c.BankName == "" {
				c.BankName = data["bank_name_old"]
			}
			if d.DeviceID != nil {
				c.DeviceName = tenMay[*d.DeviceID]
			}
			if m.Type == domain.PTQuetThe {
				c.CardFee = map[string]string{}
				for k, v := range data {
					if strings.HasPrefix(k, "card_fee_") {
						c.CardFee[k] = v
					}
				}
			}
			q.Details = append(q.Details, c)
		}
		// Phương thức đang bật mà không còn dòng nào dùng được thì quầy không
		// chào ra — v2 cũng lọc như vậy (getPaymentMethod).
		if m.Type != domain.PTTienMat && len(q.Details) == 0 {
			continue
		}
		out = append(out, q)
	}
	sort.SliceStable(out, func(i, j int) bool { return out[i].Type < out[j].Type })

	return out, nil
}

func firstNonEmpty(v ...string) string {
	for _, s := range v {
		if s != "" {
			return s
		}
	}

	return ""
}

// ---- Website ----

// taiKhoanChuyenKhoan — tài khoản in cho khách đặt online: tài khoản mặc định của
// chi nhánh trung tâm; chưa nối sổ phương thức thì đọc trang Cài đặt cũ.
func taiKhoanChuyenKhoan(ctx context.Context, svc SettingService) (ten, so, chu string) {
	if n := nguonCua(svc); n != nil {
		if tk, ok := n.TaiKhoanWeb(ctx); ok {
			return tk.NganHang, tk.SoTK, tk.ChuTK
		}
	}

	return settingText(ctx, svc, SettingBankName), settingText(ctx, svc, SettingBankAccountNumber),
		settingText(ctx, svc, SettingBankAccountName)
}

func (s *paymentMethodService) docTrungTam(ctx context.Context) ([]domain.PaymentMethodDetail, []domain.PaymentMethod, bool) {
	shopID, err := s.repo.ChiNhanhTrungTam(ctx)
	if err != nil {
		return nil, nil, false
	}
	ms, ct, err := s.docChiNhanh(ctx, shopID)
	if err != nil {
		return nil, nil, false
	}

	return ct, ms, true
}

func (s *paymentMethodService) CoNhanWeb(ctx context.Context, method string) (bool, bool) {
	ct, ms, ok := s.docTrungTam(ctx)
	if !ok {
		return false, false
	}
	loai, chiTiet := domain.PTChuyenKhoan, ""
	switch method {
	case "bank_transfer":
	case "payos", "sepay":
		loai, chiTiet = domain.PTQRTuDong, method
	default:
		return false, false
	}
	for _, m := range ms {
		if m.Type != loai {
			continue
		}
		if !m.Status {
			return false, true
		}
		for _, d := range dangBat(locChiTiet(ct, m.ID)) {
			if chiTiet == "" || d.DetailType == chiTiet {
				return true, true
			}
		}

		return false, true
	}

	return false, true
}

func (s *paymentMethodService) TaiKhoanWeb(ctx context.Context) (TaiKhoanNhan, bool) {
	ct, ms, ok := s.docTrungTam(ctx)
	if !ok {
		return TaiKhoanNhan{}, false
	}
	for _, m := range ms {
		if m.Type != domain.PTChuyenKhoan || !m.Status {
			continue
		}
		for _, d := range dangBat(locChiTiet(ct, m.ID)) {
			if !d.IsDefault {
				continue
			}
			data := giaiData(d.Data)
			ten := domain.TenNganHang(data["bank_id"])
			if ten == "" {
				ten = data["bank_name_old"]
			}

			return TaiKhoanNhan{
				BIN: data["bank_id"], NganHang: ten, SoTK: data["bank_account"],
				ChuTK: data["bank_account_name"], SuaSoTien: data["edit_amount"] == "1",
			}, true
		}
	}

	return TaiKhoanNhan{}, true
}

// ---- Cổng theo cửa hàng ----

// dungCong dựng client từ khoá đã lưu của một cổng. nil = khoá chưa đủ (cửa
// hàng chưa tự kết nối) hoặc giải mã hỏng.
func (s *paymentMethodService) dungCong(d domain.PaymentMethodDetail) *CongRieng {
	data := giaiData(d.Data)
	giai := func(k string) string {
		v, err := s.hop.Giai(data[k])
		if err != nil {
			logger.Error("không giải mã được khoá cổng thanh toán",
				zap.Uint("detail_id", d.ID), zap.String("khoa", k), zap.Error(err))
			return ""
		}

		return v
	}
	switch d.DetailType {
	case "payos":
		c := s.payosCfg
		c.ClientID, c.APIKey, c.ChecksumKey = data["payos_client_id"], giai("payos_api_key"), giai("payos_checksum_key")
		if !c.Enabled() {
			return nil
		}

		return &CongRieng{DetailID: d.ID, PayOS: payos.New(c)}
	case "sepay":
		c := s.sepayCfg
		c.AccountNumber, c.Bank, c.AccountName = data["sepay_account"], domain.TenNganHang(data["bank_id"]), data["bank_account_name"]
		c.WebhookAPIKey, c.APIToken = giai("sepay_api_key"), giai("sepay_api_token")
		if !c.Enabled() {
			return nil
		}

		return &CongRieng{DetailID: d.ID, SePay: sepay.New(c)}
	case "mpos_qr", "mpos_device":
		np := nextpay.New(nextpay.Config{MerchantID: data["mpos_merchant_id"], SecretKey: giai("mpos_secret_key")})
		if !np.Enabled() {
			return nil
		}
		phi := map[string]float64{}
		for k, v := range data {
			if strings.HasPrefix(k, "card_fee_") {
				phi[k], _ = strconv.ParseFloat(v, 64)
			}
		}

		return &CongRieng{DetailID: d.ID, NextPay: np, Muid: data["mpos_muid"], KieuQR: data["mpos_type"], Phi: phi}
	}

	return nil
}

func (s *paymentMethodService) CongWeb(ctx context.Context, loai string) *CongRieng {
	ct, ms, ok := s.docTrungTam(ctx)
	if !ok {
		return nil
	}
	for _, m := range ms {
		if m.Type != domain.PTQRTuDong {
			continue
		}
		for _, d := range dangBat(locChiTiet(ct, m.ID)) {
			if d.DetailType == loai {
				return s.dungCong(d)
			}
		}
	}

	return nil
}

// CoKhoaRieng — chi nhánh trung tâm đã tự kết nối cổng này (khoá riêng đủ).
func (s *paymentMethodService) CoKhoaRieng(ctx context.Context, loai string) bool {
	return s.CongWeb(ctx, loai) != nil
}

func (s *paymentMethodService) CongTheoMa(ctx context.Context, ma, loai string) (context.Context, *CongRieng, error) {
	ma = strings.TrimSpace(ma)
	if len(ma) < 16 {
		return ctx, nil, domain.ErrNotFound
	}
	d, err := s.repo.TheoMaWebhook(ctx, ma)
	if err != nil {
		return ctx, nil, err
	}
	// Mã của cổng SePay mà gói lại gửi vào đường PayOS (hoặc ngược lại): coi như
	// không có — không để khoá của cổng này kiểm gói của cổng kia. NextPay báo cả
	// QR lẫn quẹt thẻ về MỘT địa chỉ của merchant, nên "mpos" nhận cả hai loại.
	cung := d.DetailType == loai || loai == "mpos" && (d.DetailType == "mpos_qr" || d.DetailType == "mpos_device")
	if !cung {
		return ctx, nil, domain.ErrNotFound
	}
	ctx = tenant.WithID(ctx, d.TenantID)
	cong := s.dungCong(*d)
	if cong == nil {
		return ctx, nil, domain.ErrPaymentMethodDisabled
	}

	return ctx, cong, nil
}

func (s *paymentMethodService) GhiNhanBao(ctx context.Context, detailID uint) {
	if err := s.repo.GhiLanBaoTien(ctx, detailID, s.now()); err != nil {
		logger.Warn("không ghi được lần báo tiền của cổng", zap.Uint("detail_id", detailID), zap.Error(err))
	}
}

func (s *paymentMethodService) KiemTraKetNoi(ctx context.Context, methodID, detailID uint, webhookURL string) (*PMKiemTra, error) {
	shopID, err := s.repo.ChiNhanhGhi(ctx)
	if err != nil {
		return nil, err
	}
	ms, ct, err := s.docChiNhanh(ctx, shopID)
	if err != nil {
		return nil, err
	}
	m := timPT(ms, methodID)
	if m == nil {
		return nil, domain.ErrNotFound
	}
	var d *domain.PaymentMethodDetail
	for i := range ct {
		if ct[i].ID == detailID && ct[i].PaymentMethodID == m.ID {
			d = &ct[i]
		}
	}
	if d == nil {
		return nil, domain.ErrNotFound
	}
	lan := giaiData(d.Data)["last_webhook_at"]
	cong := s.dungCong(*d)

	switch d.DetailType {
	case "payos":
		if cong == nil {
			return &PMKiemTra{Cau: "Chưa khai đủ ba khoá PayOS — cổng này đang dùng khoá chung của máy chủ."}, nil
		}
		// confirm-webhook làm hai việc một lúc: PayOS kiểm Client ID / API key, rồi
		// gửi thử một gói tới URL riêng — gói đó đi qua đúng đường webhook thật,
		// nên đạt nghĩa là khoá, checksum và URL đều thông.
		if err := cong.PayOS.ConfirmWebhook(ctx, webhookURL); err != nil {
			var api *payos.APIError
			if errors.As(err, &api) {
				return &PMKiemTra{Cau: "PayOS từ chối: " + api.Desc + " — kiểm lại Client ID, API Key, và URL webhook phải truy cập được từ Internet.", LanBaoCuoi: lan}, nil
			}

			return &PMKiemTra{Cau: "Không gọi được PayOS: " + err.Error(), LanBaoCuoi: lan}, nil
		}

		return &PMKiemTra{OK: true, Cau: "PayOS đã nhận khoá và tự đăng ký URL webhook. Khách trả tiền xong, đơn sẽ tự chuyển “đã thanh toán”.", LanBaoCuoi: lan}, nil
	case "sepay":
		if cong == nil {
			return &PMKiemTra{Cau: "Chưa khai đủ tài khoản và API key — cổng này đang dùng cấu hình SePay chung của máy chủ."}, nil
		}
		if lan != "" {
			return &PMKiemTra{OK: true, Cau: "Đã nhận báo tiền từ SePay — webhook đang thông.", LanBaoCuoi: lan}, nil
		}
		if cong.SePay.CanQuery() {
			if _, err := cong.SePay.FindIncoming(ctx, "KIEMTRAKETNOI", s.now().Add(-time.Hour)); err != nil {
				return &PMKiemTra{Cau: "SePay từ chối API token: " + err.Error()}, nil
			}
		}

		return &PMKiemTra{Cau: "Chưa nhận được báo tiền nào từ SePay. Vào SePay → Webhooks, thêm URL bên dưới, kiểu xác thực “API Key” với đúng khoá đã khai, rồi bấm “Gửi thử” và kiểm tra lại."}, nil
	case "mpos_qr", "mpos_device":
		if cong == nil {
			return &PMKiemTra{Cau: "Chưa khai đủ Merchant ID và Secret Key của NextPay."}, nil
		}
		if cong.Muid == "" {
			// Máy mPOS không có MUID: không thử được bằng QR, và đẩy thử xuống máy
			// thì máy ngoài quầy kêu bíp — chỉ báo được là đã nhận báo tiền chưa.
			if lan != "" {
				return &PMKiemTra{OK: true, Cau: "Đã nhận báo tiền từ NextPay — webhook đang thông.", LanBaoCuoi: lan}, nil
			}
			return &PMKiemTra{Cau: "Đã có khoá. Gửi URL webhook bên dưới cho NextPay, rồi quẹt thử một giao dịch nhỏ để kiểm tra.", LanBaoCuoi: lan}, nil
		}
		// Thử thật: tạo một mã QR 3.000đ rồi huỷ ngay. NextPay nhận = Merchant ID,
		// Secret Key và MUID đều đúng.
		maThu := "KTKN" + strconv.FormatInt(s.now().UnixNano()%1_000_000_000, 10)
		if _, err := cong.NextPay.TaoQR(ctx, maThu, cong.Muid, 3000, cong.KieuQR, "Kiem tra ket noi"); err != nil {
			var api *nextpay.APIError
			if errors.As(err, &api) {
				return &PMKiemTra{Cau: "NextPay từ chối: " + api.Message + " — kiểm lại Merchant ID, MUID, Secret Key.", LanBaoCuoi: lan}, nil
			}
			return &PMKiemTra{Cau: "Không gọi được NextPay: " + err.Error(), LanBaoCuoi: lan}, nil
		}
		_ = cong.NextPay.HuyQR(ctx, maThu, cong.Muid, 3000)
		cau := "NextPay nhận khoá (đã tạo thử và huỷ một mã QR 3.000đ). "
		if lan == "" {
			cau += "Gửi URL webhook bên dưới cho NextPay để nhận báo tiền."
		} else {
			cau += "Webhook đang thông."
		}

		return &PMKiemTra{OK: true, Cau: cau, LanBaoCuoi: lan}, nil
	}

	return &PMKiemTra{Cau: "Cổng " + tenQR[d.DetailType] + " chưa được nối — chưa có gì để kiểm tra."}, nil
}

// ---- Quầy ----

var loaiPTTheoHinhThuc = map[string]uint8{
	domain.PaymentMethodBank: domain.PTChuyenKhoan, "card": domain.PTQuetThe,
	domain.PaymentMethodSePay: domain.PTQRTuDong, domain.PaymentMethodPayOS: domain.PTQRTuDong,
	domain.PaymentMethodMposQR: domain.PTQRTuDong,
}

func (s *paymentMethodService) ChonChoQuay(ctx context.Context, method string, detailID uint) (*ChonQuay, error) {
	loai, co := loaiPTTheoHinhThuc[method]
	if !co {
		return nil, nil
	}
	shopID, err := s.repo.ChiNhanhGhi(ctx)
	if err != nil {
		return nil, err
	}
	ms, ct, err := s.docChiNhanh(ctx, shopID)
	if err != nil {
		return nil, err
	}
	khong := func(cau string) error { return fmt.Errorf("%w: %s", domain.ErrPhuongThucQuay, cau) }
	var m *domain.PaymentMethod
	for i := range ms {
		if ms[i].Type == loai {
			m = &ms[i]
		}
	}
	if m == nil || !m.Status {
		// Chuyển khoản tay vẫn nhận như trước khi có màn cấu hình: tiền đã vào tay
		// người bán, quầy không có lý do từ chối. Các hình thức còn lại sống nhờ
		// cấu hình — tắt là không dùng được.
		if method == domain.PaymentMethodBank && detailID == 0 {
			return nil, nil
		}
		return nil, khong(tenPT[loai] + " đang tắt ở chi nhánh này.")
	}
	var chon *domain.PaymentMethodDetail
	for _, d := range dangBat(locChiTiet(ct, m.ID)) {
		if loai == domain.PTQRTuDong && d.DetailType != method {
			continue
		}
		dd := d
		if detailID > 0 && d.ID == detailID {
			chon = &dd
			break
		}
		if detailID == 0 && (chon == nil || d.IsDefault) {
			chon = &dd
		}
	}
	if chon == nil {
		if detailID > 0 {
			return nil, khong("Tài khoản / máy / cổng vừa chọn không còn dùng được — tải lại màn quầy.")
		}
		if method == domain.PaymentMethodBank {
			return nil, nil
		}
		return nil, khong("Chi nhánh chưa có " + donViPT[loai] + " nào đang bật.")
	}
	out := &ChonQuay{DetailID: chon.ID, Loai: chon.DetailType}
	if chon.DetailType == "physical_device" {
		out.TyLePhi, _ = strconv.ParseFloat(giaiData(chon.Data)["card_fee_physical"], 64)
	}
	// Máy mPOS: phải đẩy được lệnh xuống máy — thiếu khoá thì báo ngay, không để
	// tới lúc khách cầm thẻ đứng chờ.
	if chon.DetailType == "mpos_device" {
		if out.Cong = s.dungCong(*chon); out.Cong == nil {
			return nil, khong("Máy mPOS chưa khai đủ Merchant ID / Secret Key — xem Cài đặt → Phương thức thanh toán.")
		}
		may, err := s.repo.MayQuetThe(ctx, shopID)
		if err != nil {
			return nil, err
		}
		for _, m := range may {
			if chon.DeviceID != nil && m.ID == *chon.DeviceID {
				out.PosID = m.Code
			}
		}
		if out.PosID == "" {
			return nil, khong("Cấu hình mPOS chưa gắn máy nào.")
		}
	}

	return out, nil
}

func (s *paymentMethodService) GhiDonQuay(ctx context.Context, orderID uint, chon *ChonQuay, phi float64) {
	if chon == nil {
		return
	}
	// DetailID 0 = xoá dấu cũ (đơn QR đổi sang tiền mặt không còn đi qua cổng nào).
	var id *uint
	if chon.DetailID > 0 {
		d := chon.DetailID
		id = &d
	}
	if err := s.repo.GhiDonDung(ctx, orderID, id, phi); err != nil {
		logger.Warn("không ghi được chi tiết thanh toán của đơn quầy (đã chạy migration 0080 chưa?)",
			zap.Uint("order_id", orderID), zap.Error(err))
	}
}

func (s *paymentMethodService) CongChiNhanh(ctx context.Context, shopID uint, loai string) *CongRieng {
	ms, err := s.repo.PhuongThuc(ctx, shopID)
	if err != nil {
		return nil
	}
	ct, err := s.repo.ChiTiet(ctx, shopID)
	if err != nil {
		return nil
	}
	for _, m := range ms {
		if m.Type != domain.PTQRTuDong {
			continue
		}
		for _, d := range dangBat(locChiTiet(ct, m.ID)) {
			if d.DetailType == loai {
				return s.dungCong(d)
			}
		}
	}

	return nil
}

func (s *paymentMethodService) CongTheoChiTiet(ctx context.Context, shopID, detailID uint) *CongRieng {
	ct, err := s.repo.ChiTiet(ctx, shopID)
	if err != nil {
		return nil
	}
	for _, d := range ct {
		if d.ID == detailID {
			return s.dungCong(d)
		}
	}

	return nil
}

func (s *paymentMethodService) GhiPhiThe(ctx context.Context, orderID, detailID uint, phi float64) {
	s.GhiDonQuay(ctx, orderID, &ChonQuay{DetailID: detailID}, phi)
}
