package service

import (
	"context"
	"net/mail"
	"net/url"
	"regexp"
	"strconv"
	"strings"
	"sync"

	"sass-api/internal/domain"
	"sass-api/internal/dto"
	"sass-api/internal/tenant"
	"sass-api/pkg/logger"

	"go.uber.org/zap"
)

// Kiểu dữ liệu của một khoá cấu hình — quyết định cách validate và loại ô nhập
// mà giao diện admin dựng ra.
const (
	SettingTypeText   = "text"
	SettingTypeNumber = "number"
	SettingTypeEmail  = "email"
	SettingTypePhone  = "phone"
	SettingTypeImage  = "image"
	SettingTypeURL    = "url"
	// SettingTypeBool nhận đúng "1" hoặc "0" — giao diện dựng công tắc bật/tắt.
	// Lưu chuỗi chứ không thêm cột kiểu bool vì bảng settings là key-value thuần.
	SettingTypeBool = "bool"
)

// Khối trong một trang cấu hình. Nhóm quyết định trang nào, khối quyết định phần
// nào trong trang — trang thông tin cửa hàng có tới 13 ô, dàn phẳng thì không tìm
// nổi. Khối rỗng nghĩa là trang chỉ có một mạch, không cần chia.
const (
	SettingSectionBrand   = "brand"
	SettingSectionContact = "contact"
	SettingSectionSocial  = "social"
	SettingSectionMethods = "methods"
	SettingSectionBank    = "bank"
)

// Nhóm cấu hình — mỗi nhóm là MỘT trang bên trang quản trị, và là giá trị của bộ
// lọc `?group=`.
//
// Nhóm do registry dưới đây quyết định, KHÔNG phải cột `group` trong database:
// cột đó chỉ được ghi kèm cho dễ đọc khi mở database lên xem. Nhờ vậy đổi nhóm
// của một khoá là sửa đúng một dòng ở đây, không cần chạy lệnh cập nhật dữ liệu.
const (
	SettingGroupGeneral   = "general"
	SettingGroupShipping  = "shipping"
	SettingGroupPayment   = "payment"
	SettingGroupInventory = "inventory"
)

// Tên các khoá cấu hình. Nơi tiêu thụ tham chiếu hằng số này chứ không viết chuỗi
// thẳng, để đổi tên khoá là compiler báo chỗ hỏng.
const (
	SettingSiteName              = "site_name"
	SettingStoreSlogan           = "store_slogan"
	SettingStoreLogo             = "store_logo"
	SettingStoreFavicon          = "store_favicon"
	SettingContactEmail          = "contact_email"
	SettingContactPhone          = "contact_phone"
	SettingStoreAddress          = "store_address"
	SettingBusinessHours         = "business_hours"
	SettingStoreWebsite          = "store_website"
	SettingSocialFacebook        = "social_facebook"
	SettingSocialInstagram       = "social_instagram"
	SettingSocialTiktok          = "social_tiktok"
	SettingSocialMessenger       = "social_messenger"
	SettingDefaultShippingFee    = "default_shipping_fee"
	SettingFreeShippingThreshold = "free_shipping_threshold"
	SettingLowStockThreshold     = "low_stock_threshold"
	SettingPaymentCODEnabled     = "payment_cod_enabled"
	SettingPaymentBankEnabled    = "payment_bank_enabled"
	SettingPaymentPayOSEnabled   = "payment_payos_enabled"
	SettingPaymentSePayEnabled   = "payment_sepay_enabled"
	SettingBankName              = "bank_name"
	SettingBankAccountNumber     = "bank_account_number"
	SettingBankAccountName       = "bank_account_name"
	SettingBankTransferNote      = "bank_transfer_note"
	SettingBankQRImage           = "bank_qr_image"
)

// settingDef khai báo MỘT khoá được phép tồn tại.
//
// Registry này là nguồn sự thật duy nhất: khoá không có ở đây thì không đọc được
// và cũng không ghi được, kể cả khi database có sẵn dòng đó.
type settingDef struct {
	Key   string
	Group string
	// Section chia trang thành các khối; rỗng = trang không chia khối.
	Section  string
	Type     string
	Label    string
	Default  string
	Required bool // rỗng là không hợp lệ
	Public   bool // lộ ra ở GET /settings cho storefront
	Max      int  // độ dài tối đa (chỉ áp cho khoá dạng chữ)
	// MaxNum là trần của khoá dạng số (0 = không chặn trần).
	//
	// Có trần vì mấy khoá này là tiền: gõ thừa một chữ số vào phí vận chuyển là mọi
	// đơn sau đó tính sai tiền, mà không có gì báo động. Thà chặn ngay lúc lưu.
	MaxNum float64
}

// settingRegistry — thứ tự khai báo cũng là thứ tự hiển thị trên form admin.
//
// Giá trị Default là mức đang được nướng cứng trong code trước khi có trang cấu
// hình, nên khoá chưa có dòng trong database vẫn cho ra đúng hành vi cũ.
var settingRegistry = []settingDef{
	// ----- Nhận diện -----
	{
		Key: SettingSiteName, Group: SettingGroupGeneral, Section: SettingSectionBrand,
		Type: SettingTypeText, Label: "Tên cửa hàng", Default: "Selliotech",
		Required: true, Public: true, Max: 100,
	},
	{
		Key: SettingStoreSlogan, Group: SettingGroupGeneral, Section: SettingSectionBrand,
		Type: SettingTypeText, Label: "Câu giới thiệu ngắn",
		Default: "Bán hàng gọn nhẹ, quản lý một chỗ.",
		Public:  true, Max: 160,
	},
	{
		Key: SettingStoreLogo, Group: SettingGroupGeneral, Section: SettingSectionBrand,
		Type: SettingTypeImage, Label: "Logo cửa hàng", Default: "",
		Public: true, Max: 255,
	},
	{
		Key: SettingStoreFavicon, Group: SettingGroupGeneral, Section: SettingSectionBrand,
		Type: SettingTypeImage, Label: "Biểu tượng trên tab (favicon)", Default: "",
		Public: true, Max: 255,
	},

	// ----- Liên hệ -----
	{
		Key: SettingContactEmail, Group: SettingGroupGeneral, Section: SettingSectionContact,
		Type: SettingTypeEmail, Label: "Email liên hệ", Default: "support@selliotech.local",
		Required: true, Public: true, Max: 150,
	},
	{
		Key: SettingContactPhone, Group: SettingGroupGeneral, Section: SettingSectionContact,
		Type: SettingTypePhone, Label: "Hotline", Default: "0796666468",
		Required: true, Public: true, Max: 20,
	},
	{
		Key: SettingStoreAddress, Group: SettingGroupGeneral, Section: SettingSectionContact,
		Type: SettingTypeText, Label: "Địa chỉ cửa hàng", Default: "",
		Public: true, Max: 255,
	},
	{
		Key: SettingBusinessHours, Group: SettingGroupGeneral, Section: SettingSectionContact,
		Type: SettingTypeText, Label: "Giờ mở cửa", Default: "08:00 – 21:00, Thứ 2 – Chủ nhật",
		Public: true, Max: 100,
	},
	{
		Key: SettingStoreWebsite, Group: SettingGroupGeneral, Section: SettingSectionContact,
		Type: SettingTypeURL, Label: "Địa chỉ website", Default: "",
		Public: true, Max: 255,
	},

	// ----- Mạng xã hội -----
	// Bỏ trống thì storefront ẩn hẳn biểu tượng tương ứng, không để nút trỏ vào "#"
	// như trước — nút bấm vào không đi đâu là nút hỏng.
	{
		Key: SettingSocialFacebook, Group: SettingGroupGeneral, Section: SettingSectionSocial,
		Type: SettingTypeURL, Label: "Facebook", Default: "",
		Public: true, Max: 255,
	},
	{
		Key: SettingSocialInstagram, Group: SettingGroupGeneral, Section: SettingSectionSocial,
		Type: SettingTypeURL, Label: "Instagram", Default: "",
		Public: true, Max: 255,
	},
	{
		Key: SettingSocialTiktok, Group: SettingGroupGeneral, Section: SettingSectionSocial,
		Type: SettingTypeURL, Label: "TikTok", Default: "",
		Public: true, Max: 255,
	},
	{
		Key: SettingSocialMessenger, Group: SettingGroupGeneral, Section: SettingSectionSocial,
		Type: SettingTypeURL, Label: "Messenger", Default: "",
		Public: true, Max: 255,
	},

	{
		Key: SettingDefaultShippingFee, Group: SettingGroupShipping, Type: SettingTypeNumber,
		Label: "Phí vận chuyển mặc định", Default: "30000",
		Required: true, Public: true, MaxNum: 1_000_000,
	},
	{
		Key: SettingFreeShippingThreshold, Group: SettingGroupShipping, Type: SettingTypeNumber,
		Label: "Ngưỡng miễn phí vận chuyển", Default: "1000000",
		Required: true, Public: true, MaxNum: 100_000_000,
	},
	// ----- Thanh toán: hình thức nhận tiền -----
	// Mặc định bật cả hai để bản cài mới giữ đúng hai lựa chọn storefront vẫn đang
	// hiện. Chuyển khoản chỉ THỰC SỰ được chào ra khi thông tin tài khoản đã đủ —
	// xem BankTransferReady: bật cờ mà chưa điền số tài khoản thì khách chuyển vào
	// đâu?
	{
		Key: SettingPaymentCODEnabled, Group: SettingGroupPayment, Section: SettingSectionMethods,
		Type: SettingTypeBool, Label: "Thanh toán khi nhận hàng (COD)", Default: "1",
		Public: true,
	},
	{
		Key: SettingPaymentBankEnabled, Group: SettingGroupPayment, Section: SettingSectionMethods,
		Type: SettingTypeBool, Label: "Chuyển khoản ngân hàng", Default: "1",
		Public: true,
	},
	// Mặc định TẮT, khác hai hình thức trên: bộ khoá PayOS nằm ở .env, bản cài mới
	// chưa có khoá nào. Bật sẵn thì storefront chào ra một hình thức không dùng
	// được. Bật lên mà chưa khai khoá cũng vẫn bị ẩn — xem paymentMethodAvailable.
	{
		Key: SettingPaymentPayOSEnabled, Group: SettingGroupPayment, Section: SettingSectionMethods,
		Type: SettingTypeBool, Label: "Thanh toán online qua PayOS (QR ngân hàng)", Default: "0",
		Public: true,
	},
	// SePay cũng là QR ngân hàng nhưng đi đường khác: tiền vào thẳng tài khoản của
	// cửa hàng, SePay chỉ đọc biến động số dư rồi báo về. Cùng mặc định TẮT vì cũng
	// cần khai cấu hình trong .env trước.
	{
		Key: SettingPaymentSePayEnabled, Group: SettingGroupPayment, Section: SettingSectionMethods,
		Type: SettingTypeBool, Label: "Chuyển khoản tự động qua SePay (QR ngân hàng)", Default: "0",
		Public: true,
	},

	// ----- Thanh toán: tài khoản nhận chuyển khoản -----
	{
		Key: SettingBankName, Group: SettingGroupPayment, Section: SettingSectionBank,
		Type: SettingTypeText, Label: "Ngân hàng", Default: "",
		Public: true, Max: 100,
	},
	{
		Key: SettingBankAccountNumber, Group: SettingGroupPayment, Section: SettingSectionBank,
		Type: SettingTypeText, Label: "Số tài khoản", Default: "",
		Public: true, Max: 32,
	},
	{
		Key: SettingBankAccountName, Group: SettingGroupPayment, Section: SettingSectionBank,
		Type: SettingTypeText, Label: "Chủ tài khoản", Default: "",
		Public: true, Max: 100,
	},
	{
		Key: SettingBankQRImage, Group: SettingGroupPayment, Section: SettingSectionBank,
		Type: SettingTypeImage, Label: "Ảnh mã QR", Default: "",
		Public: true, Max: 255,
	},
	{
		Key: SettingBankTransferNote, Group: SettingGroupPayment, Section: SettingSectionBank,
		Type: SettingTypeText, Label: "Dặn dò thêm cho khách", Default: "",
		Public: true, Max: 255,
	},

	{
		Key: SettingLowStockThreshold, Group: SettingGroupInventory, Type: SettingTypeNumber,
		// Mặc định 5 — đúng bằng InventoryController::LOW_STOCK và DashboardController::LOW_STOCK
		// đang chạy, để bản cài mới không tự đổi cách đếm hàng sắp hết.
		Label: "Ngưỡng cảnh báo sắp hết hàng", Default: "5",
		// Chỉ dùng trong trang tồn kho của admin — không lộ ra storefront.
		Required: true, MaxNum: 10_000,
	},
}

// settingDefs tra cứu nhanh theo khoá, dựng một lần lúc nạp package.
var settingDefs = func() map[string]settingDef {
	m := make(map[string]settingDef, len(settingRegistry))
	for _, d := range settingRegistry {
		m[d.Key] = d
	}
	return m
}()

// SettingValidationError gom lỗi theo từng khoá để handler trả 422 kèm chi tiết,
// giống hệt lỗi validate của binding JSON.
type SettingValidationError struct {
	Fields map[string]string
}

func (e *SettingValidationError) Error() string { return "cấu hình không hợp lệ" }

// SettingService đọc/ghi cấu hình hệ thống.
//
// Giá trị được giữ trong snapshot bộ nhớ nên các service khác — order_service
// khi tính phí ship chẳng hạn — đọc cấu hình mà không phải truy vấn database ở
// từng lượt gọi.
//
// SNAPSHOT LÀ CỦA TỪNG CỬA HÀNG, không phải của hệ thống. Đây là chỗ dễ sai nhất
// trong cả tệp: bảng settings chứa tên cửa hàng, logo, hotline và SỐ TÀI KHOẢN
// NGÂN HÀNG. Một snapshot dùng chung nghĩa là cửa hàng nào lưu cấu hình sau cùng
// thì số tài khoản của họ hiện ra trong email xác nhận đơn của mọi cửa hàng khác
// — khách trả tiền vào nhầm tài khoản, và không có lỗi nào nổi lên ở đâu cả.
//
// Vì vậy mọi hàm đọc đều nhận ctx: cửa hàng được xác định từ đó, đúng cùng một
// nguồn mà bộ lọc tenant dưới tầng repository dùng. ctx không xác định được cửa
// hàng thì trả về giá trị MẶC ĐỊNH của registry — không chạm database, không trả
// nhầm dữ liệu của ai.
type SettingService interface {
	// List trả về cấu hình cho trang admin; group rỗng = mọi nhóm.
	List(ctx context.Context, group string) (dto.SettingsResponse, error)
	// Update ghi nhiều khoá rồi làm mới snapshot, trả về toàn bộ cấu hình sau khi ghi.
	Update(ctx context.Context, items map[string]string) (dto.SettingsResponse, error)
	// Public trả về map phẳng chỉ gồm khoá đánh dấu công khai (cho storefront).
	Public(ctx context.Context) map[string]string

	// SetPayOSReady / PayOSReady / SetSePayReady / SePayReady nói cho phần cấu hình
	// biết từng cổng đã có đủ khoá hay chưa.
	//
	// Khoá của cả hai cổng nằm ở .env chứ không phải bảng settings — bảng đó đọc ra
	// được qua API cấu hình, mà lộ checksum key (PayOS) hay khoá webhook (SePay) thì
	// ai cũng giả được một cái báo "đã thu tiền". Registry vì thế không tự biết cổng
	// đã sẵn sàng chưa; main nạp vào một lần lúc khởi động.
	SetPayOSReady(ready bool)
	PayOSReady() bool
	SetSePayReady(ready bool)
	SePayReady() bool

	// Các hàm đọc nhanh từ snapshot của cửa hàng trong ctx. Chỉ chạm database ở
	// lượt đọc ĐẦU TIÊN của mỗi cửa hàng.
	Get(ctx context.Context, key string) string
	Float(ctx context.Context, key string) float64
	Int(ctx context.Context, key string) int
	Bool(ctx context.Context, key string) bool
}

type settingService struct {
	repo domain.SettingRepository

	mu sync.RWMutex
	// snaps giữ cấu hình theo TỪNG cửa hàng. Nạp lười: cửa hàng nào chưa có mặt
	// trong map thì lượt đọc đầu tiên của nó đi hỏi database rồi cất lại.
	//
	// Không có cơ chế hết hạn, đúng như snapshot cũ: cấu hình chỉ đổi qua Update
	// của chính tiến trình này, và Update nạp lại ngay sau khi ghi. Sửa thẳng
	// dưới database thì phải khởi động lại API — cũng y như trước.
	snaps map[uint]map[string]string
	// payosReady / sepayReady là chuyện của .env, chung cho cả tiến trình chứ
	// không theo cửa hàng, nên đứng riêng ngoài snaps.
	payosReady bool
	sepayReady bool
}

func NewSettingService(repo domain.SettingRepository) SettingService {
	return &settingService{repo: repo, snaps: map[uint]map[string]string{}}
}

// defaultSnapshot dựng snapshot toàn giá trị mặc định — dùng làm điểm khởi đầu
// nên mọi hàm đọc luôn có giá trị dùng được, kể cả trước khi Load chạy.
func defaultSnapshot() map[string]string {
	m := make(map[string]string, len(settingRegistry))
	for _, d := range settingRegistry {
		m[d.Key] = d.Default
	}
	return m
}

// reload đọc lại cấu hình của cửa hàng trong ctx từ database và cất vào bộ nhớ.
func (s *settingService) reload(ctx context.Context) (map[string]string, error) {
	id, ok := tenant.ID(ctx)
	if !ok {
		// Không xác định được cửa hàng thì không có gì để hỏi. Trả mặc định và
		// KHÔNG cất vào map — cất là tự tạo ra một mục "cửa hàng số 0".
		return defaultSnapshot(), nil
	}

	stored, err := s.repo.Map(ctx)
	if err != nil {
		return nil, err
	}
	next := mergeSnapshot(stored)

	s.mu.Lock()
	s.snaps[id] = next
	s.mu.Unlock()

	return next, nil
}

// snapshot trả về cấu hình của cửa hàng trong ctx, nạp từ database ở lượt gọi
// đầu tiên.
//
// Không trả lỗi, đúng như bộ hàm đọc cũ: cấu hình không phải chức năng sống còn,
// và một lượt đọc hỏng thì rơi về mặc định của registry — cùng bộ giá trị từng
// được nướng cứng trong code trước khi có trang Cài đặt. Nhưng có GHI LOG, vì
// "cửa hàng thấy tên mặc định thay vì tên của mình" là thứ phải điều tra được.
func (s *settingService) snapshot(ctx context.Context) map[string]string {
	id, ok := tenant.ID(ctx)
	if !ok {
		return defaultSnapshot()
	}

	s.mu.RLock()
	snap, cached := s.snaps[id]
	s.mu.RUnlock()
	if cached {
		return snap
	}

	snap, err := s.reload(ctx)
	if err != nil {
		logger.Warn("không nạp được cấu hình cửa hàng, dùng giá trị mặc định",
			zap.Uint("tenant_id", id), zap.Error(err))

		return defaultSnapshot()
	}

	return snap
}

// mergeSnapshot ghép giá trị từ database lên nền mặc định: khoá thiếu dòng thì
// giữ mặc định, khoá lạ trong database bị bỏ qua (registry mới là nguồn sự thật).
func mergeSnapshot(stored map[string]string) map[string]string {
	next := defaultSnapshot()
	for _, d := range settingRegistry {
		v, ok := stored[d.Key]
		if !ok {
			continue
		}
		// Dòng rỗng của khoá bắt buộc coi như chưa khai — quay về mặc định thay vì
		// để phí ship thành 0 hoặc email gửi khách mất chữ ký.
		if d.Required && strings.TrimSpace(v) == "" {
			continue
		}
		next[d.Key] = v
	}

	return next
}

func (s *settingService) List(ctx context.Context, group string) (dto.SettingsResponse, error) {
	group = strings.TrimSpace(group)
	if group != "" && !knownSettingGroup(group) {
		return dto.SettingsResponse{}, &SettingValidationError{
			Fields: map[string]string{"group": "Nhóm cấu hình không tồn tại"},
		}
	}

	// Đọc TOÀN BỘ rồi lọc theo registry, không lọc bằng cột `group` dưới database:
	// nhóm của một khoá do registry quyết định, dòng cũ trong database có thể còn
	// mang tên nhóm trước khi đổi. Lọc theo cột đó thì giá trị đã lưu biến mất khỏi
	// form và người dùng tưởng cấu hình bị reset.
	stored, err := s.repo.Map(ctx)
	if err != nil {
		return dto.SettingsResponse{}, err
	}

	return buildSettingsResponse(group, func(d settingDef) string {
		if v, ok := stored[d.Key]; ok {
			return v
		}
		return d.Default
	}), nil
}

func (s *settingService) Update(ctx context.Context, items map[string]string) (dto.SettingsResponse, error) {
	rows, verr := validateSettings(items)
	if verr != nil {
		return dto.SettingsResponse{}, verr
	}
	// Luật chéo chạy SAU luật từng khoá: nói "phải điền số tài khoản" trong khi ô đó
	// đang chứa giá trị sai kiểu thì người sửa nhận hai câu lỗi đá nhau.
	current := func(key string) string { return s.Get(ctx, key) }
	if verr := validatePaymentSetup(items, current, s.PayOSReady(), s.SePayReady()); verr != nil {
		return dto.SettingsResponse{}, verr
	}
	if err := s.repo.Upsert(ctx, rows); err != nil {
		return dto.SettingsResponse{}, err
	}
	// Đọc lại từ database rồi mới thay snapshot: bảo đảm bộ nhớ khớp đúng những gì
	// đã ghi xuống, không phải khớp với payload người dùng gửi.
	saved, err := s.reload(ctx)
	if err != nil {
		return dto.SettingsResponse{}, err
	}

	return buildSettingsResponse("", func(d settingDef) string {
		return saved[d.Key]
	}), nil
}

// validateSettings kiểm tra toàn bộ payload trước khi ghi: tất-cả-hoặc-không.
// Một khoá sai thì không khoá nào được ghi, tránh cấu hình nửa vời.
func validateSettings(items map[string]string) ([]domain.Setting, *SettingValidationError) {
	fields := make(map[string]string)
	rows := make([]domain.Setting, 0, len(items))

	// Đi theo registry chứ không theo map để thứ tự ghi ổn định giữa các lần gọi.
	for _, d := range settingRegistry {
		raw, ok := items[d.Key]
		if !ok {
			continue
		}
		value := strings.TrimSpace(raw)
		if msg := validateSettingValue(d, value); msg != "" {
			fields[d.Key] = msg
			continue
		}
		rows = append(rows, domain.Setting{Key: d.Key, Value: value, Group: d.Group})
	}

	// Khoá lạ: từ chối thẳng thay vì âm thầm ghi vào database rồi không ai đọc.
	for key := range items {
		if _, ok := settingDefs[key]; !ok {
			fields[key] = "Khoá cấu hình không tồn tại"
		}
	}

	if len(fields) > 0 {
		return nil, &SettingValidationError{Fields: fields}
	}
	return rows, nil
}

// paymentSetupKeys — những khoá mà luật chéo bên dưới soi tới.
var paymentSetupKeys = []string{
	SettingPaymentCODEnabled, SettingPaymentBankEnabled,
	SettingPaymentPayOSEnabled, SettingPaymentSePayEnabled,
	SettingBankName, SettingBankAccountNumber, SettingBankAccountName,
}

// validatePaymentSetup kiểm những quy tắc KHÔNG nằm gọn trong một khoá.
//
// Hai luật, cùng một lý do: cấu hình thanh toán hỏng thì khách chỉ phát hiện ra ở
// bước cuối cùng của việc mua hàng, lúc họ đã điền hết địa chỉ.
//   - Tắt hết mọi hình thức = không ai đặt được đơn nào.
//   - Bật chuyển khoản mà thiếu số tài khoản = bảo khách chuyển tiền vào hư không.
//   - Bật PayOS mà .env chưa có khoá = công tắc bật nhưng storefront vẫn không hiện.
//
// Chỉ chạy khi payload có ĐỤNG tới khoá thanh toán: cấu hình mặc định (bật chuyển
// khoản, chưa điền tài khoản) là trạng thái hợp lệ của bản cài mới, không được để
// nó chặn luôn việc lưu trang Vận chuyển hay trang Kho.
//
// current đọc giá trị đang lưu, dùng cho khoá không có trong payload — người dùng
// bấm tắt COD ở trang thanh toán thì luật vẫn phải biết chuyển khoản đang bật hay
// tắt để nói đúng chuyện. payosReady là tình trạng bộ khoá trong .env.
func validatePaymentSetup(items map[string]string, current func(string) string, payosReady, sepayReady bool) *SettingValidationError {
	touched := false
	for _, k := range paymentSetupKeys {
		if _, ok := items[k]; ok {
			touched = true
			break
		}
	}
	if !touched {
		return nil
	}

	effective := func(key string) string {
		if v, ok := items[key]; ok {
			return strings.TrimSpace(v)
		}
		return strings.TrimSpace(current(key))
	}

	fields := make(map[string]string)
	cod := effective(SettingPaymentCODEnabled) == "1"
	bank := effective(SettingPaymentBankEnabled) == "1"
	payos := effective(SettingPaymentPayOSEnabled) == "1"
	sepay := effective(SettingPaymentSePayEnabled) == "1"

	if !cod && !bank && !payos && !sepay {
		const msg = "Phải bật ít nhất một hình thức thanh toán, không thì khách không đặt được đơn nào"
		fields[SettingPaymentCODEnabled] = msg
		fields[SettingPaymentBankEnabled] = msg
		fields[SettingPaymentPayOSEnabled] = msg
		fields[SettingPaymentSePayEnabled] = msg
	}

	// Cảnh báo chứ không chặn: khoá PayOS do người quản trị máy chủ khai trong
	// .env, chủ shop ngồi ở trang này không tự thêm được. Chặn ở đây là khoá họ
	// lại trước một việc họ không có cách nào làm.
	if payos && !payosReady {
		fields[SettingPaymentPayOSEnabled] = "Chưa khai PAYOS_CLIENT_ID / PAYOS_API_KEY / PAYOS_CHECKSUM_KEY trong .env của máy chủ API nên hình thức này vẫn chưa hiện ra cho khách"
	}
	if sepay && !sepayReady {
		fields[SettingPaymentSePayEnabled] = "Chưa khai SEPAY_ACCOUNT_NUMBER / SEPAY_BANK / SEPAY_WEBHOOK_API_KEY trong .env của máy chủ API nên hình thức này vẫn chưa hiện ra cho khách"
	}

	if bank {
		for key, what := range map[string]string{
			SettingBankName:          "tên ngân hàng",
			SettingBankAccountNumber: "số tài khoản",
			SettingBankAccountName:   "tên chủ tài khoản",
		} {
			if effective(key) == "" {
				fields[key] = "Đang bật chuyển khoản thì phải điền " + what
			}
		}
	}

	if len(fields) > 0 {
		return &SettingValidationError{Fields: fields}
	}
	return nil
}

// phonePattern — số điện thoại Việt Nam hoặc hotline dạng 1900 xxxx: chỉ chấp
// nhận chữ số cùng vài ký tự phân cách thường gặp.
var phonePattern = regexp.MustCompile(`^[0-9+][0-9 .()-]{7,19}$`)

func validateSettingValue(d settingDef, value string) string {
	if value == "" {
		if d.Required {
			return "Trường này là bắt buộc"
		}
		return ""
	}
	if d.Max > 0 && len([]rune(value)) > d.Max {
		return "Độ dài tối đa là " + strconv.Itoa(d.Max) + " ký tự"
	}

	switch d.Type {
	case SettingTypeBool:
		if value != "0" && value != "1" {
			return "Giá trị chỉ nhận 0 hoặc 1"
		}
	case SettingTypeNumber:
		n, err := strconv.ParseFloat(value, 64)
		if err != nil {
			return "Giá trị phải là số"
		}
		if n < 0 {
			return "Giá trị không được là số âm"
		}
		if d.MaxNum > 0 && n > d.MaxNum {
			return "Giá trị tối đa là " + formatMaxNum(d.MaxNum)
		}
	case SettingTypeEmail:
		if _, err := mail.ParseAddress(value); err != nil {
			return "Email không hợp lệ"
		}
	case SettingTypePhone:
		if !phonePattern.MatchString(value) {
			return "Số điện thoại không hợp lệ"
		}
	case SettingTypeURL:
		// Bắt buộc có http:// hoặc https:// và có tên miền: chuỗi kiểu
		// "facebook.com/shop" khi đưa vào href sẽ bị hiểu là đường dẫn tương đối
		// của chính website, bấm vào ra trang 404 của cửa hàng.
		u, err := url.Parse(value)
		if err != nil || u.Host == "" || (u.Scheme != "http" && u.Scheme != "https") {
			return "Đường dẫn phải bắt đầu bằng http:// hoặc https://"
		}
	}
	return ""
}

func (s *settingService) Public(ctx context.Context) map[string]string {
	snap := s.snapshot(ctx)

	out := make(map[string]string, len(settingRegistry))
	for _, d := range settingRegistry {
		if d.Public {
			out[d.Key] = snap[d.Key]
		}
	}
	// Bật công tắc PayOS nhưng .env chưa có khoá thì báo ra là TẮT. Storefront dựng
	// danh sách hình thức thanh toán từ đúng map này; nói dối ở đây là khách chọn
	// được một hình thức mà API sẽ từ chối, sau khi họ đã điền xong địa chỉ.
	if !s.PayOSReady() {
		out[SettingPaymentPayOSEnabled] = "0"
	}
	if !s.SePayReady() {
		out[SettingPaymentSePayEnabled] = "0"
	}
	return out
}

func (s *settingService) SetPayOSReady(ready bool) {
	s.mu.Lock()
	s.payosReady = ready
	s.mu.Unlock()
}

func (s *settingService) PayOSReady() bool {
	s.mu.RLock()
	defer s.mu.RUnlock()
	return s.payosReady
}

func (s *settingService) SetSePayReady(ready bool) {
	s.mu.Lock()
	s.sepayReady = ready
	s.mu.Unlock()
}

func (s *settingService) SePayReady() bool {
	s.mu.RLock()
	defer s.mu.RUnlock()
	return s.sepayReady
}

func (s *settingService) Get(ctx context.Context, key string) string {
	return s.snapshot(ctx)[key]
}

// Float đọc khoá dạng số. Giá trị hỏng (ai đó sửa tay trong database) rơi về mặc
// định của registry chứ không trả 0 — 0 sẽ thành "miễn phí ship cho mọi đơn".
func (s *settingService) Float(ctx context.Context, key string) float64 {
	if n, err := strconv.ParseFloat(s.Get(ctx, key), 64); err == nil {
		return n
	}
	if d, ok := settingDefs[key]; ok {
		n, _ := strconv.ParseFloat(d.Default, 64)
		return n
	}
	return 0
}

func (s *settingService) Int(ctx context.Context, key string) int {
	return int(s.Float(ctx, key))
}

// Bool đọc khoá dạng công tắc. Chỉ "1" là bật; giá trị lạ (ai đó sửa tay trong
// database) coi như tắt — với khoá thanh toán thì tắt nhầm chỉ mất một lựa chọn,
// còn bật nhầm là chào ra hình thức cửa hàng chưa nhận được tiền.
func (s *settingService) Bool(ctx context.Context, key string) bool {
	return strings.TrimSpace(s.Get(ctx, key)) == "1"
}

// settingText / settingNumber là cửa đọc cấu hình cho các service KHÁC (đơn hàng,
// xác thực, trả hàng).
//
// Cả hai chịu được svc == nil: service dựng trong test không cần cắm cấu hình mà
// vẫn chạy đúng như trước, vì giá trị rơi về Default khai trong registry — đúng
// bằng những hằng số từng nướng cứng trong code.
func settingText(ctx context.Context, svc SettingService, key string) string {
	if svc != nil {
		if v := strings.TrimSpace(svc.Get(ctx, key)); v != "" {
			return v
		}
	}
	return settingDefs[key].Default
}

func settingBool(ctx context.Context, svc SettingService, key string) bool {
	if svc != nil {
		return svc.Bool(ctx, key)
	}
	return settingDefs[key].Default == "1"
}

// paymentMethodAvailable trả lời câu hỏi "cửa hàng có đang nhận hình thức này
// không" — dùng chung cho lúc chốt đơn và lúc dựng câu trả lời cho khách.
//
// Chuyển khoản cần ĐỦ ba thứ khách phải có mới chuyển được tiền: ngân hàng, số tài
// khoản, chủ tài khoản. Bật cờ mà thiếu một trong ba thì coi như chưa nhận —
// mặc định của bản cài mới rơi đúng vào trường hợp này, và thà không chào ra còn
// hơn để khách bấm chọn rồi không biết chuyển đi đâu.
func paymentMethodAvailable(ctx context.Context, svc SettingService, method string) bool {
	switch method {
	case "cod":
		return settingBool(ctx, svc, SettingPaymentCODEnabled)
	case "bank_transfer":
		if !settingBool(ctx, svc, SettingPaymentBankEnabled) {
			return false
		}
		for _, key := range []string{SettingBankName, SettingBankAccountNumber, SettingBankAccountName} {
			if strings.TrimSpace(settingText(ctx, svc, key)) == "" {
				return false
			}
		}
		return true
	case "payos":
		// Hai điều kiện độc lập: cửa hàng có MUỐN nhận (công tắc trong trang Cài đặt)
		// và hệ thống có ĐỦ KHOÁ để gọi cổng (.env). Thiếu vế nào cũng là không nhận.
		return settingBool(ctx, svc, SettingPaymentPayOSEnabled) && svc != nil && svc.PayOSReady()
	case "sepay":
		return settingBool(ctx, svc, SettingPaymentSePayEnabled) && svc != nil && svc.SePayReady()
	default:
		// vnpay/momo… là hình thức admin ghi tay cho đơn đặt qua điện thoại, không
		// nằm trong các công tắc của storefront.
		return true
	}
}

func settingNumber(ctx context.Context, svc SettingService, key string) float64 {
	if svc != nil {
		return svc.Float(ctx, key)
	}
	n, _ := strconv.ParseFloat(settingDefs[key].Default, 64)
	return n
}

// formatMaxNum in số trần theo kiểu Việt Nam (1000000 -> 1.000.000) để câu lỗi đọc
// được, khỏi phải đếm chữ số.
func formatMaxNum(n float64) string {
	digits := strconv.FormatFloat(n, 'f', -1, 64)
	var b strings.Builder
	for i, c := range digits {
		if i > 0 && (len(digits)-i)%3 == 0 {
			b.WriteByte('.')
		}
		b.WriteRune(c)
	}
	return b.String()
}

func knownSettingGroup(group string) bool {
	for _, d := range settingRegistry {
		if d.Group == group {
			return true
		}
	}
	return false
}

// buildSettingsResponse dựng response từ registry: chỉ những khoá thuộc `group`
// (rỗng = tất cả), giá trị lấy qua valueOf.
func buildSettingsResponse(group string, valueOf func(settingDef) string) dto.SettingsResponse {
	res := dto.SettingsResponse{
		Values: make(map[string]string, len(settingRegistry)),
		Fields: make([]dto.SettingField, 0, len(settingRegistry)),
	}
	for _, d := range settingRegistry {
		if group != "" && d.Group != group {
			continue
		}
		res.Values[d.Key] = valueOf(d)
		res.Fields = append(res.Fields, dto.SettingField{
			Key:      d.Key,
			Group:    d.Group,
			Section:  d.Section,
			Type:     d.Type,
			Label:    d.Label,
			Default:  d.Default,
			Required: d.Required,
			Public:   d.Public,
			MaxLen:   d.Max,
			MaxNum:   d.MaxNum,
		})
	}
	return res
}
