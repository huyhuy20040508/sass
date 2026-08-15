package service

import (
	"context"
	"encoding/base64"
	"encoding/json"
	"errors"
	"fmt"
	"math"
	"strconv"
	"strings"
	"sync"
	"time"

	"github.com/skip2/go-qrcode"
	"go.uber.org/zap"

	"sass-api/config"
	"sass-api/internal/domain"
	"sass-api/internal/dto"
	"sass-api/internal/realtime"
	"sass-api/pkg/logger"
	"sass-api/pkg/payos"
	"sass-api/pkg/sepay"
)

// PaymentService là cầu nối giữa đơn hàng và các cổng thanh toán trực tuyến.
//
// Hai cổng đang nối vào làm việc theo hai lối khác hẳn nhau:
//
//   - PayOS giữ vai trò cổng thật: mình gọi sang xin một link thanh toán, mỗi lần
//     một mã giao dịch riêng, có hạn, huỷ được, và tra ngược được trạng thái.
//   - SePay không giữ tiền và không cấp gì cả — nó chỉ đọc biến động số dư tài
//     khoản ngân hàng của cửa hàng rồi báo về. Mã QR chỉ là một lệnh chuyển tiền
//     dựng sẵn, không hết hạn, và việc gắn tiền vào đơn dựa hoàn toàn vào NỘI DUNG
//     chuyển khoản (chính là mã đơn).
//
// Vì vậy service này không cố ép hai bên vào một khuôn: nó tách nhánh theo
// provider ở ba chỗ (dựng mã, tra trạng thái, nhận webhook), còn phần chốt tiền
// và báo cho đơn hàng thì dùng chung.
//
// Nó KHÔNG phụ thuộc vào OrderService mà làm việc thẳng với OrderRepository —
// nếu không thì hai service tham chiếu vòng nhau (đặt hàng cần tạo mã, ghi nhận
// tiền lại cần sửa đơn) và không dựng được cái nào trước.
type PaymentService interface {
	// Available cho biết một hình thức thanh toán online đã đủ cấu hình để dùng
	// thật hay chưa.
	Available(method string) bool
	// Start dựng thông tin thanh toán cho một đơn vừa đặt (mã QR, link, số tài
	// khoản). Đơn còn mã cũ chưa hết hạn thì trả lại đúng mã đó.
	Start(ctx context.Context, o *domain.Order) (*dto.CheckoutPayment, error)
	// HandlePayOSWebhook xử lý webhook PayOS gửi tới (gồm cả bước kiểm chữ ký).
	HandlePayOSWebhook(ctx context.Context, raw []byte) error
	// HandleSePayWebhook xử lý webhook SePay báo tài khoản có tiền vào.
	// authHeader là header Authorization nguyên văn — nơi mang khoá API.
	HandleSePayWebhook(ctx context.Context, authHeader string, raw []byte) error
	// Status tra tình trạng thanh toán theo MÃ GIAO DỊCH phía cổng.
	Status(ctx context.Context, gatewayCode string) (*dto.PaymentStatusResponse, error)
}

// gatewayLookupEvery — khoảng cách tối thiểu giữa hai lần HỎI THẲNG cổng về cùng
// một giao dịch.
//
// Trang của khách hỏi trạng thái vài giây một lần để màn hình đổi cho nhanh, nhưng
// mỗi lượt hỏi mà đi tiếp sang cổng thì một khách đứng chờ đã là hàng chục lời gọi
// mỗi phút — cổng sẽ chặn vì tưởng bị lạm dụng. Nhịp hỏi CỦA TRANG và nhịp gọi
// SANG CỔNG vì thế tách rời: trang cứ hỏi dày, còn cổng chỉ bị gọi theo nhịp này.
//
// Không làm chậm việc báo thành công: webhook mới là đường nhanh, nó ghi thẳng vào
// database và lượt hỏi kế tiếp của trang đọc được ngay mà không cần gọi cổng.
const gatewayLookupEvery = 5 * time.Second

type paymentService struct {
	repo      domain.PaymentRepository
	orderRepo domain.OrderRepository
	payos     *payos.Client
	payosCfg  config.PayOSConfig
	sepay     *sepay.Client
	// lastLookup nhớ lần cuối hỏi cổng về từng giao dịch. Để trong bộ nhớ chứ không
	// xuống database: mất khi khởi động lại cũng chẳng sao, cùng lắm là hỏi cổng
	// sớm hơn một nhịp.
	lookupMu   sync.Mutex
	lastLookup map[uint]time.Time
	// notify đẩy thông báo "đơn đã thanh toán" vào chuông của nhân viên và của
	// khách. Có thể nil khi dựng service trong test.
	notify NotificationService
}

func NewPaymentService(
	repo domain.PaymentRepository,
	orderRepo domain.OrderRepository,
	payosClient *payos.Client,
	payosCfg config.PayOSConfig,
	sepayClient *sepay.Client,
	notify NotificationService,
) PaymentService {
	return &paymentService{
		repo:       repo,
		orderRepo:  orderRepo,
		payos:      payosClient,
		payosCfg:   payosCfg,
		sepay:      sepayClient,
		notify:     notify,
		lastLookup: make(map[uint]time.Time),
	}
}

func (s *paymentService) Available(method string) bool {
	switch method {
	case domain.PaymentMethodPayOS:
		return s.payos != nil && s.payos.Enabled()
	case domain.PaymentMethodSePay:
		return s.sepay != nil && s.sepay.Enabled()
	default:
		return false
	}
}

// ---------- Dựng thông tin thanh toán ----------

// Start là cửa vào chung: đơn còn mã cũ dùng được thì trả lại, không thì dựng mới
// theo đúng cách của cổng tương ứng.
func (s *paymentService) Start(ctx context.Context, o *domain.Order) (*dto.CheckoutPayment, error) {
	if !s.Available(o.PaymentMethod) {
		return nil, domain.ErrPaymentMethodDisabled
	}

	// Đơn còn mã chưa hết hạn thì mở lại đúng mã đó. Sinh mã mới cho cùng một khoản
	// tiền sẽ để lại hai mã QR cùng sống, và khách rất dễ quét nhầm cái đã bỏ.
	if p, err := s.repo.FindOpenByOrder(ctx, o.ID, time.Now()); err == nil {
		return s.paymentDTO(p, o), nil
	} else if !errors.Is(err, domain.ErrNotFound) {
		return nil, err
	}

	amount := int64(math.Round(o.TotalAmount))
	if amount <= 0 {
		return nil, fmt.Errorf("%w: số tiền phải lớn hơn 0", domain.ErrPaymentGateway)
	}

	switch o.PaymentMethod {
	case domain.PaymentMethodSePay:
		return s.startSePay(ctx, o, amount)
	default:
		return s.startPayOS(ctx, o, amount)
	}
}

// startSePay không gọi sang đâu cả: SePay chỉ theo dõi tài khoản ngân hàng, nên
// việc duy nhất phải làm là ghi nhớ "đơn này đang chờ một khoản tiền mang nội
// dung là mã đơn" rồi đưa cho khách mã QR chuyển tiền.
//
// Mã giao dịch chính là MÃ ĐƠN, không sinh mã riêng như PayOS: khách phải gõ (hoặc
// quét) đúng chuỗi đó vào nội dung chuyển khoản, và nó cũng là thứ nhân viên đọc
// được trên sao kê. Mã QR không hết hạn nên mỗi đơn chỉ cần đúng một dòng.
func (s *paymentService) startSePay(ctx context.Context, o *domain.Order, amount int64) (*dto.CheckoutPayment, error) {
	p := &domain.Payment{
		OrderID:         o.ID,
		TransactionCode: o.OrderCode,
		QRCode:          s.sepay.QRImageURL(amount, o.OrderCode),
		Provider:        domain.PaymentMethodSePay,
		Amount:          o.TotalAmount,
		Currency:        "VND",
		Status:          domain.PaymentStatusPending,
	}
	if err := s.repo.Create(ctx, p); err != nil {
		logger.Error("không lưu được lần thử thanh toán SePay",
			zap.String("order_code", o.OrderCode), zap.Error(err))
		return nil, fmt.Errorf("%w: %v", domain.ErrPaymentGateway, err)
	}

	logger.Info("đã mở mã QR SePay cho đơn",
		zap.String("order_code", o.OrderCode), zap.Int64("amount", amount))
	return s.paymentDTO(p, o), nil
}

// startPayOS xin PayOS cấp một link thanh toán mới cho đơn.
func (s *paymentService) startPayOS(ctx context.Context, o *domain.Order, amount int64) (*dto.CheckoutPayment, error) {
	gatewayCode := gatewayOrderCode(o.ID, time.Now())
	expiredAt := time.Now().Add(s.payosCfg.Expire)

	link, err := s.payos.CreateLink(ctx, payos.CreateRequest{
		OrderCode: gatewayCode,
		Amount:    amount,
		// Nội dung chuyển khoản khách thấy trong app ngân hàng. Dùng chính mã đơn vì
		// đó là thứ duy nhất đối soát được với sao kê.
		Description:  truncateRunes(o.OrderCode, payos.DescriptionMax),
		BuyerName:    o.RecipientName,
		BuyerEmail:   o.RecipientEmail,
		BuyerPhone:   o.RecipientPhone,
		BuyerAddress: o.ShippingAddress,
		Items:        payosItems(o),
		CancelURL:    s.payosCfg.CancelURL,
		ReturnURL:    s.payosCfg.ReturnURL,
		ExpiredAt:    expiredAt.Unix(),
	})
	if err != nil {
		logger.Error("không tạo được link thanh toán PayOS",
			zap.String("order_code", o.OrderCode), zap.Error(err))
		return nil, fmt.Errorf("%w: %v", domain.ErrPaymentGateway, err)
	}

	p := &domain.Payment{
		OrderID:         o.ID,
		TransactionCode: strconv.FormatInt(gatewayCode, 10),
		PaymentLinkID:   link.PaymentLinkID,
		CheckoutURL:     link.CheckoutURL,
		QRCode:          link.QRCode,
		Provider:        domain.PaymentMethodPayOS,
		Amount:          o.TotalAmount,
		Currency:        "VND",
		Status:          domain.PaymentStatusPending,
		ExpiredAt:       &expiredAt,
	}
	if err := s.repo.Create(ctx, p); err != nil {
		// Ghi hỏng thì webhook về sau không lần ra được đơn nào, nên link này coi như
		// bỏ đi — huỷ luôn ở cổng để khách không quét trúng một mã QR mồ côi.
		logger.Error("không lưu được lần thử thanh toán, huỷ link vừa tạo",
			zap.String("order_code", o.OrderCode), zap.Int64("gateway_code", gatewayCode), zap.Error(err))
		if _, cancelErr := s.payos.CancelLink(ctx, gatewayCode, "Lỗi lưu dữ liệu phía cửa hàng"); cancelErr != nil {
			logger.Warn("huỷ link mồ côi không thành công",
				zap.Int64("gateway_code", gatewayCode), zap.Error(cancelErr))
		}
		return nil, fmt.Errorf("%w: %v", domain.ErrPaymentGateway, err)
	}

	logger.Info("đã tạo link thanh toán PayOS",
		zap.String("order_code", o.OrderCode),
		zap.Int64("gateway_code", gatewayCode),
		zap.Int64("amount", amount))
	return s.paymentDTO(p, o), nil
}

// gatewayOrderCode sinh mã giao dịch gửi sang PayOS.
//
// PayOS đòi một SỐ NGUYÊN chưa từng dùng trên kênh thanh toán — dùng lại mã cũ bị
// từ chối, kể cả khi link cũ đã huỷ. Ghép id đơn với phần mili giây trong vòng 100
// giây gần nhất cho ra mã vừa duy nhất vừa đọc ngược ra đơn được (code/100000 =
// id đơn), rất đáng giá khi phải dò một giao dịch trên trang PayOS.
func gatewayOrderCode(orderID uint, now time.Time) int64 {
	return int64(orderID)*100_000 + now.UnixMilli()%100_000
}

// payosItems liệt kê hàng trong đơn để trang thanh toán của cổng hiện ra đúng thứ
// khách đang mua, thay vì chỉ một con số tiền trơ trọi.
func payosItems(o *domain.Order) []payos.Item {
	if len(o.Items) == 0 {
		return nil
	}
	items := make([]payos.Item, 0, len(o.Items))
	for _, it := range o.Items {
		items = append(items, payos.Item{
			Name:     truncateRunes(orderItemLabel(it), 100),
			Quantity: it.Quantity,
			Price:    int64(math.Round(it.UnitPrice)),
		})
	}
	return items
}

// paymentDTO gói một lần thử thành thứ storefront dựng được màn hình quét mã.
//
// Chỗ khác nhau giữa hai cổng gói gọn ở đây: PayOS trả về CHUỖI VietQR nên phải tự
// vẽ ra ảnh, còn SePay cho sẵn ĐỊA CHỈ ảnh nên gán thẳng. Nhờ vậy client chỉ thấy
// một trường qr_image duy nhất và không cần biết cổng nào đang chạy.
func (s *paymentService) paymentDTO(p *domain.Payment, o *domain.Order) *dto.CheckoutPayment {
	out := &dto.CheckoutPayment{
		Provider:        p.Provider,
		CheckoutURL:     p.CheckoutURL,
		TransactionCode: p.TransactionCode,
		Content:         o.OrderCode,
		Amount:          p.Amount,
	}

	if p.Provider == domain.PaymentMethodSePay {
		out.QRImage = p.QRCode
		if s.sepay != nil {
			out.AccountNumber, out.BankName, out.AccountName = s.sepay.Account()
		}
	} else {
		out.QRCode = p.QRCode
		out.QRImage = qrImageDataURI(p.QRCode)
	}

	if p.ExpiredAt != nil {
		out.ExpiresAt = p.ExpiredAt.Format(time.RFC3339)
	}
	return out
}

// qrImageDataURI vẽ chuỗi VietQR thành ảnh PNG nhúng thẳng vào JSON.
//
// Vẽ ở server để trang web không phải nhúng thư viện QR nào: ảnh gửi kèm luôn
// trong câu trả lời đặt hàng, khách thấy mã ngay mà không đợi thêm lượt tải nào.
// Mức sửa lỗi Medium là mức VietQR dùng — đủ để quét được khi ảnh hơi mờ hoặc
// màn hình bám vân tay.
//
// Vẽ hỏng thì trả chuỗi rỗng: client vẫn còn chuỗi QR thô và link sang trang của
// cổng, không đáng làm hỏng cả đơn hàng vì một tấm ảnh.
func qrImageDataURI(payload string) string {
	if strings.TrimSpace(payload) == "" {
		return ""
	}
	png, err := qrcode.Encode(payload, qrcode.Medium, 512)
	if err != nil {
		logger.Warn("không vẽ được ảnh QR", zap.Error(err))
		return ""
	}
	return "data:image/png;base64," + base64.StdEncoding.EncodeToString(png)
}

// ---------- Webhook ----------

func (s *paymentService) HandlePayOSWebhook(ctx context.Context, raw []byte) error {
	if !s.Available(domain.PaymentMethodPayOS) {
		return domain.ErrPaymentMethodDisabled
	}

	data, err := s.payos.ParseWebhook(raw)
	if err != nil {
		return err
	}

	code := strconv.FormatInt(data.OrderCode, 10)
	p, err := s.repo.FindByTransactionCode(ctx, code)
	if errors.Is(err, domain.ErrNotFound) {
		// PayOS gửi một gói dữ liệu mẫu (orderCode 123) khi đăng ký địa chỉ webhook,
		// và gói đó không ứng với đơn nào. Chữ ký đã hợp lệ nên đây không phải kẻ
		// giả mạo — ghi log rồi coi như xong, tuyệt đối không báo lỗi về cho PayOS
		// vì họ sẽ hiểu là địa chỉ webhook hỏng và ngừng gửi.
		logger.Info("webhook PayOS không khớp lần thử nào (có thể là gói kiểm tra)",
			zap.String("gateway_code", code))
		return nil
	}
	if err != nil {
		return err
	}

	if !data.Succeeded() {
		logger.Warn("webhook PayOS báo giao dịch không thành công",
			zap.String("gateway_code", code), zap.String("code", data.Code), zap.String("desc", data.Desc))
		return s.settle(ctx, p, domain.PaymentStatusFailed, raw, nil)
	}

	// Số tiền phải khớp đúng đơn. Lệch thì KHÔNG tự ghi nhận đã trả đủ: để nhân
	// viên đối soát tay còn hơn giao hàng cho một khoản tiền không đúng.
	if want := int64(math.Round(p.Amount)); data.Amount != want {
		logger.Error("webhook PayOS lệch số tiền, không ghi nhận tự động",
			zap.String("gateway_code", code),
			zap.Int64("nhan_duoc", data.Amount), zap.Int64("phai_la", want))
		return s.settle(ctx, p, domain.PaymentStatusFailed, raw, nil)
	}

	paidAt := parseGatewayTime(data.TransactionDateTime)
	return s.settle(ctx, p, domain.PaymentStatusSuccess, raw, &paidAt)
}

// HandleSePayWebhook xử lý báo có của SePay.
//
// Khác PayOS ở chỗ gói dữ liệu này KHÔNG biết gì về đơn hàng — nó chỉ nói "tài
// khoản vừa nhận X đồng, nội dung là Y". Việc tìm ra đơn nào là của mình: dò xem
// mã giao dịch nào đang chờ có mặt trong nội dung đó.
func (s *paymentService) HandleSePayWebhook(ctx context.Context, authHeader string, raw []byte) error {
	if !s.Available(domain.PaymentMethodSePay) {
		return domain.ErrPaymentMethodDisabled
	}

	data, err := s.sepay.ParseWebhook(authHeader, raw)
	if err != nil {
		return err
	}

	// Tiền RA cũng bắn về cùng địa chỉ này. Bỏ qua ngay, không thì mỗi lần cửa hàng
	// chi tiền lại có một đơn được đánh dấu đã thanh toán.
	if !data.IsIncoming() {
		return nil
	}

	content := sepay.NormalizeContent(data.Content + " " + data.Code + " " + data.Description)
	p, err := s.repo.FindPendingByContent(ctx, domain.PaymentMethodSePay, content)
	if errors.Is(err, domain.ErrNotFound) {
		// Tiền vào tài khoản nhưng không mang mã đơn nào đang chờ: khách chuyển nhầm
		// nội dung, hoặc đơn giản là một khoản tiền không liên quan tới website. Ghi
		// log cho nhân viên đối soát tay rồi trả 200 — báo lỗi về SePay chỉ khiến họ
		// gửi lại gói này suốt 5 tiếng.
		logger.Info("SePay báo tiền vào nhưng không khớp đơn nào",
			zap.String("noi_dung", data.Content),
			zap.Float64("so_tien", data.TransferAmount),
			zap.String("ma_giao_dich", data.ReferenceCode))
		return nil
	}
	if err != nil {
		return err
	}

	// Số tiền phải khớp đúng đơn. Lệch thì KHÔNG tự ghi nhận: khách chuyển thiếu là
	// chuyện có thật, và giao hàng cho một khoản tiền không đủ thì cửa hàng chịu lỗ.
	if want, got := math.Round(p.Amount), math.Round(data.TransferAmount); got != want {
		logger.Error("SePay lệch số tiền, không ghi nhận tự động",
			zap.String("ma_don", p.TransactionCode),
			zap.Float64("nhan_duoc", got), zap.Float64("phai_la", want))
		return s.settle(ctx, p, domain.PaymentStatusFailed, raw, nil)
	}

	paidAt := parseGatewayTime(data.TransactionDate)
	return s.settle(ctx, p, domain.PaymentStatusSuccess, raw, &paidAt)
}

// ---------- Tra trạng thái ----------

// Status là đường xác nhận DỰ PHÒNG cho webhook, chạy lúc khách quay về website.
//
// Cần nó vì webhook chỉ tới được máy chủ có địa chỉ công khai: chạy ở máy local
// thì PayOS không gọi vào localhost được, và đơn sẽ nằm mãi ở "chờ thanh toán"
// dù khách đã trả tiền. Ở đây ta hỏi thẳng cổng thay vì đợi cổng gọi tới.
func (s *paymentService) Status(ctx context.Context, gatewayCode string) (*dto.PaymentStatusResponse, error) {
	code := strings.TrimSpace(gatewayCode)
	p, err := s.repo.FindByTransactionCode(ctx, code)
	if err != nil {
		return nil, err
	}
	if !s.Available(p.Provider) {
		return nil, domain.ErrPaymentMethodDisabled
	}

	o, err := s.orderRepo.FindByID(ctx, p.OrderID)
	if err != nil {
		return nil, err
	}

	// Đã chốt xong từ trước (webhook về kịp, hoặc khách bấm kiểm tra lần hai).
	if p.Status != domain.PaymentStatusPending {
		return statusDTO(o, p.Status, ""), nil
	}

	if p.Provider == domain.PaymentMethodSePay {
		return s.statusSePay(ctx, p, o)
	}
	return s.statusPayOS(ctx, p, o, code)
}

// shouldAskGateway cho biết đã tới lượt hỏi thẳng cổng về giao dịch này chưa.
//
// Dọn bớt khi bảng ghi phình to: mỗi giao dịch chỉ được hỏi trong lúc khách còn
// ngồi chờ, xong là không ai đụng tới nữa nhưng dòng nhớ vẫn nằm đó mãi.
func (s *paymentService) shouldAskGateway(paymentID uint) bool {
	s.lookupMu.Lock()
	defer s.lookupMu.Unlock()

	now := time.Now()
	if last, ok := s.lastLookup[paymentID]; ok && now.Sub(last) < gatewayLookupEvery {
		return false
	}

	if len(s.lastLookup) > 500 {
		for id, t := range s.lastLookup {
			if now.Sub(t) > time.Hour {
				delete(s.lastLookup, id)
			}
		}
	}

	s.lastLookup[paymentID] = now
	return true
}

// statusSePay dò sao kê tài khoản xem đã có khoản tiền mang mã đơn này chưa.
//
// Chỉ chạy được khi đã khai SEPAY_API_TOKEN. Không có token thì đành chờ webhook —
// và ở máy local (SePay không gọi vào localhost được) nghĩa là phải vào trang quản
// trị xác nhận tay.
func (s *paymentService) statusSePay(ctx context.Context, p *domain.Payment, o *domain.Order) (*dto.PaymentStatusResponse, error) {
	if !s.sepay.CanQuery() || !s.shouldAskGateway(p.ID) {
		return statusDTO(o, domain.PaymentStatusPending, ""), nil
	}

	// Quét từ lúc mở mã, lùi thêm chút cho lệch giờ — đừng quét cả cuốn sao kê.
	tx, err := s.sepay.FindIncoming(ctx, p.TransactionCode, p.CreatedAt)
	if err != nil {
		// Không hỏi được thì trả về những gì đang biết: thà nói "chưa thấy tiền về"
		// còn hơn ném lỗi kỹ thuật vào mặt khách đang ngồi chờ.
		logger.Warn("không tra được sao kê SePay",
			zap.String("ma_don", p.TransactionCode), zap.Error(err))
		return statusDTO(o, domain.PaymentStatusPending, ""), nil
	}
	if tx == nil {
		return statusDTO(o, domain.PaymentStatusPending, ""), nil
	}

	if want, got := math.Round(p.Amount), math.Round(tx.AmountInVND()); got != want {
		logger.Error("sao kê SePay lệch số tiền, không ghi nhận tự động",
			zap.String("ma_don", p.TransactionCode),
			zap.Float64("nhan_duoc", got), zap.Float64("phai_la", want))
		return statusDTO(o, domain.PaymentStatusPending, ""), nil
	}

	paidAt := parseGatewayTime(tx.TransactionDate)
	if err := s.settle(ctx, p, domain.PaymentStatusSuccess, mustJSON(tx), &paidAt); err != nil {
		return nil, err
	}
	return statusDTO(o, domain.PaymentStatusSuccess, ""), nil
}

// statusPayOS hỏi thẳng PayOS xem link đã được trả tiền chưa.
func (s *paymentService) statusPayOS(ctx context.Context, p *domain.Payment, o *domain.Order, code string) (*dto.PaymentStatusResponse, error) {
	if !s.shouldAskGateway(p.ID) {
		return statusDTO(o, domain.PaymentStatusPending, p.CheckoutURL), nil
	}

	n, err := strconv.ParseInt(code, 10, 64)
	if err != nil {
		return nil, domain.ErrNotFound
	}
	info, err := s.payos.GetLink(ctx, n)
	if err != nil {
		// Không hỏi được cổng thì trả về những gì đang biết, kèm link để khách trả
		// tiếp — thà nói "chưa thấy tiền về" còn hơn báo lỗi kỹ thuật cho khách.
		logger.Warn("không tra được trạng thái link PayOS",
			zap.String("gateway_code", code), zap.Error(err))
		return statusDTO(o, domain.PaymentStatusPending, p.CheckoutURL), nil
	}

	switch {
	case info.Paid():
		paidAt := parseGatewayTime(lastTransactionTime(info))
		if err := s.settle(ctx, p, domain.PaymentStatusSuccess, mustJSON(info), &paidAt); err != nil {
			return nil, err
		}
		return statusDTO(o, domain.PaymentStatusSuccess, ""), nil

	case info.Closed():
		if err := s.settle(ctx, p, domain.PaymentStatusCancelled, mustJSON(info), nil); err != nil {
			return nil, err
		}
		return statusDTO(o, domain.PaymentStatusCancelled, ""), nil

	default:
		return statusDTO(o, domain.PaymentStatusPending, p.CheckoutURL), nil
	}
}

func statusDTO(o *domain.Order, status, checkoutURL string) *dto.PaymentStatusResponse {
	res := &dto.PaymentStatusResponse{
		OrderCode:   o.OrderCode,
		Status:      status,
		Paid:        status == domain.PaymentStatusSuccess,
		Amount:      o.TotalAmount,
		CheckoutURL: checkoutURL,
	}
	switch status {
	case domain.PaymentStatusSuccess:
		res.Message = "Đã nhận được thanh toán. Cửa hàng sẽ xác nhận và giao đơn cho bạn."
	case domain.PaymentStatusCancelled:
		res.Message = "Giao dịch đã huỷ hoặc link đã hết hạn. Bạn có thể đặt lại hoặc gọi cửa hàng để thanh toán khi nhận hàng."
	case domain.PaymentStatusFailed:
		res.Message = "Giao dịch không thành công. Vui lòng liên hệ cửa hàng để được hỗ trợ."
	default:
		res.Message = "Chưa nhận được thanh toán cho đơn này."
	}
	return res
}

// ---------- Chốt kết quả ----------

// settle ghi kết quả một lần thử và, nếu là thành công, đánh dấu đơn đã trả tiền.
//
// MarkSettled trả về false khi không còn dòng nào ở trạng thái chờ để đổi — nghĩa
// là ai đó (webhook gửi lặp, hoặc khách bấm kiểm tra đúng lúc webhook về) đã xử lý
// xong. Dừng tại đó, không thì khách nhận hai lần thông báo cho cùng một khoản tiền.
func (s *paymentService) settle(ctx context.Context, p *domain.Payment, status string, gatewayResponse []byte, paidAt *time.Time) error {
	first, err := s.repo.MarkSettled(ctx, p.ID, status, string(gatewayResponse), paidAt)
	if err != nil {
		return err
	}
	if !first || status != domain.PaymentStatusSuccess {
		return nil
	}
	return s.markOrderPaid(ctx, p.OrderID)
}

// markOrderPaid đổi tình trạng thanh toán của đơn và báo cho cả hai phía.
//
// Chỉ đụng tới payment_status: trạng thái đơn (chờ xác nhận / đang chuẩn bị...)
// vẫn do nhân viên quyết định. Tiền đã về không có nghĩa hàng đã được kiểm và gói.
func (s *paymentService) markOrderPaid(ctx context.Context, orderID uint) error {
	o, err := s.orderRepo.LockAndUpdate(ctx, orderID, func(o *domain.Order) (*domain.OrderStatusHistory, []string, *domain.StockRelease, error) {
		if o.PaymentStatus == domain.OrderPaymentPaid {
			return nil, nil, nil, nil
		}
		o.PaymentStatus = domain.OrderPaymentPaid
		return nil, []string{"PaymentStatus"}, nil, nil
	})
	if err != nil {
		return err
	}

	logger.Info("đơn đã được ghi nhận thanh toán qua PayOS",
		zap.String("order_code", o.OrderCode), zap.Float64("amount", o.TotalAmount))

	if s.notify == nil {
		return nil
	}
	payload := orderPayload(o)
	s.notify.Push(ctx, nil, "order_payment",
		"Đơn "+o.OrderCode+" đã thanh toán",
		"Khách đã trả "+formatVND(o.TotalAmount)+" qua PayOS.", payload)
	if o.UserID != nil {
		s.notify.Push(ctx, o.UserID, "order_payment",
			"Đã nhận thanh toán đơn "+o.OrderCode,
			"Cửa hàng đã nhận được tiền và sẽ chuẩn bị hàng cho bạn.", payload)
	}
	s.signal(ctx, o)
	return nil
}

// signal bắn tín hiệu "đơn này vừa đổi" để màn hình đang mở tự làm mới.
func (s *paymentService) signal(ctx context.Context, o *domain.Order) {
	payload := orderPayload(o)
	s.notify.SignalAdmin(ctx, realtime.EventOrder, payload)
	if o.UserID != nil {
		s.notify.Signal(ctx, realtime.TopicUser(*o.UserID), realtime.EventOrder, payload)
	}
}

// ---------- Tiện ích ----------

// parseGatewayTime đọc mốc thời gian PayOS trả về ("2026-07-31 14:05:09").
// Không đọc được thì lấy thời điểm hiện tại: biết tiền đã về là đủ, sai vài giây
// trong cột "trả lúc nào" không đáng để bỏ cả lần ghi nhận.
func parseGatewayTime(s string) time.Time {
	s = strings.TrimSpace(s)
	for _, layout := range []string{"2006-01-02 15:04:05", time.RFC3339} {
		if t, err := time.ParseInLocation(layout, s, time.Local); err == nil {
			return t
		}
	}
	return time.Now()
}

func lastTransactionTime(info *payos.Info) string {
	if len(info.Transactions) == 0 {
		return ""
	}
	return info.Transactions[len(info.Transactions)-1].TransactionDateTime
}

// mustJSON đóng gói phản hồi của cổng để lưu nguyên văn vào payments.gateway_response.
// Hỏng thì trả chuỗi rỗng — cột này để đối soát, không đáng làm hỏng việc ghi nhận.
func mustJSON(v any) []byte {
	b, err := json.Marshal(v)
	if err != nil {
		return nil
	}
	return b
}

// truncateRunes cắt chuỗi theo KÝ TỰ chứ không theo byte: cắt giữa một ký tự tiếng
// Việt nhiều byte sẽ cho ra chuỗi hỏng font.
func truncateRunes(s string, max int) string {
	s = strings.TrimSpace(s)
	r := []rune(s)
	if len(r) <= max {
		return s
	}
	return strings.TrimSpace(string(r[:max]))
}
