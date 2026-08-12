package service

import (
	"context"
	"fmt"
	"strconv"
	"strings"
	"time"

	"sass-api/config"
	"sass-api/internal/domain"
	"sass-api/internal/dto"
	"sass-api/internal/realtime"
	"sass-api/pkg/logger"
	"sass-api/pkg/mailer"

	"go.uber.org/zap"
)

// orderFlow khai báo các trạng thái được phép chuyển tới từ mỗi trạng thái.
// Đơn đã hoàn tất / huỷ / hoàn hàng là điểm cuối, không đổi tiếp được.
var orderFlow = map[string][]string{
	domain.OrderStatusPending:    {domain.OrderStatusConfirmed, domain.OrderStatusCancelled},
	domain.OrderStatusConfirmed:  {domain.OrderStatusProcessing, domain.OrderStatusCancelled},
	domain.OrderStatusProcessing: {domain.OrderStatusShipping, domain.OrderStatusCancelled},
	domain.OrderStatusShipping:   {domain.OrderStatusDelivered, domain.OrderStatusReturned},
	domain.OrderStatusDelivered:  {domain.OrderStatusCompleted, domain.OrderStatusReturned},
	domain.OrderStatusCompleted:  {},
	domain.OrderStatusCancelled:  {},
	domain.OrderStatusReturned:   {},
}

// OrderDetail là đơn hàng kèm lịch sử chuyển trạng thái.
type OrderDetail struct {
	*domain.Order
	Histories []domain.OrderStatusHistory `json:"histories"`
	// NextStatuses là các trạng thái hợp lệ kế tiếp — admin dựng menu thao tác từ đây.
	NextStatuses []string `json:"next_statuses"`
	// CanCancel cho biết KHÁCH có được tự huỷ đơn này không. Storefront dựa vào đây
	// để hiện nút huỷ, khỏi phải chép lại luật vào giao diện rồi lệch với server.
	CanCancel bool `json:"can_cancel"`
}

type OrderService interface {
	List(ctx context.Context, filter domain.OrderFilter) ([]domain.Order, int64, error)
	GetByID(ctx context.Context, id uint) (*OrderDetail, error)
	Create(ctx context.Context, req *dto.OrderCreateRequest) (*OrderDetail, error)
	// Checkout — khách đặt hàng từ storefront (giá tra lại từ DB, trừ tồn kho).
	Checkout(ctx context.Context, req *dto.CheckoutRequest, userID uint) (*dto.CheckoutResponse, error)
	// Quote — đối chiếu giỏ hàng với giá + tồn kho hiện tại, không tạo đơn.
	Quote(ctx context.Context, req *dto.CartQuoteRequest) (*dto.CartQuoteResponse, error)
	// MyOrders — đơn của CHÍNH khách đang đăng nhập (storefront).
	MyOrders(ctx context.Context, userID uint, filter domain.OrderFilter) ([]domain.Order, int64, error)
	// MyOrder — chi tiết một đơn, chỉ trả về nếu đơn thuộc về khách đó.
	MyOrder(ctx context.Context, userID, id uint) (*OrderDetail, error)
	// CancelMine — khách tự huỷ đơn của mình khi đơn còn chưa được đóng gói.
	CancelMine(ctx context.Context, userID, id uint, reason string) (*OrderDetail, error)
	Update(ctx context.Context, id uint, req *dto.OrderUpdateRequest, actorID uint) (*OrderDetail, error)
	UpdateStatus(ctx context.Context, id uint, req *dto.OrderStatusRequest, actorID uint) (*OrderDetail, error)
	UpdatePayment(ctx context.Context, id uint, req *dto.OrderPaymentRequest) (*OrderDetail, error)
	UpdateShipping(ctx context.Context, id uint, req *dto.OrderShippingRequest) (*OrderDetail, error)
	UpdateNote(ctx context.Context, id uint, note string) (*OrderDetail, error)
	Lookup(ctx context.Context, code string) (*domain.Order, error)
	Stats(ctx context.Context) (domain.OrderStats, error)
	// Revenue — chuỗi doanh thu theo ngày cho biểu đồ trang tổng quan.
	Revenue(ctx context.Context, days int) (domain.RevenueSummary, error)
}

type orderService struct {
	orderRepo domain.OrderRepository
	// returnRepo chỉ dùng để biết đơn đã có phiếu trả hàng riêng hay chưa (xem
	// UpdateStatus). Có thể nil khi dựng service trong test.
	returnRepo domain.OrderReturnRepository
	mail       mailer.Mailer
	mailCfg    config.MailConfig
	// notify đẩy thông báo + tín hiệu làm mới màn hình xuống trình duyệt.
	// Có thể nil (test dựng service không cần realtime) — mọi chỗ dùng đều qua
	// các helper ở cuối file, đã kiểm tra nil.
	notify NotificationService
	// settings cung cấp phí vận chuyển, ngưỡng miễn phí, hotline và tên cửa hàng.
	// Đọc từ snapshot bộ nhớ nên không thêm truy vấn nào vào đường đặt hàng.
	// Có thể nil: settingText/settingNumber rơi về mặc định của registry.
	settings SettingService
	// payments tạo link thanh toán online cho đơn vừa đặt. Có thể nil (test, hoặc
	// bản cài chưa nối cổng) — đơn payos khi đó bị chặn ngay từ đầu Checkout.
	payments PaymentService
	// promos trừ chương trình khuyến mãi vào giá từng dòng hàng. Có thể nil (test) —
	// mọi lời gọi đều qua applyPromotions đã kiểm nil.
	promos PromotionService
	// vouchers kiểm mã giảm giá khách nhập tay. Có thể nil (test) — khi nil thì
	// khách gửi mã lên sẽ bị báo mã không tồn tại thay vì được giảm miễn phí.
	vouchers VoucherService
}

func NewOrderService(orderRepo domain.OrderRepository, returnRepo domain.OrderReturnRepository, mail mailer.Mailer, mailCfg config.MailConfig, notify NotificationService, settings SettingService, payments PaymentService, promos PromotionService, vouchers VoucherService) OrderService {
	return &orderService{orderRepo: orderRepo, returnRepo: returnRepo, mail: mail, mailCfg: mailCfg, notify: notify, settings: settings, payments: payments, promos: promos, vouchers: vouchers}
}

// applyVoucher kiểm mã khách nhập rồi ghi khoản giảm + bản chụp mã vào đơn, trả
// về yêu cầu tiêu lượt để repository chốt trong cùng giao dịch.
//
// Khách không nhập mã thì đây là chuyện không xảy ra gì (nil, nil). Nhập mã mà mã
// hỏng thì BÁO LỖI và cả đơn dừng lại — cố tình không "âm thầm bỏ mã đi rồi vẫn
// đặt": khách bấm đặt khi đang nhìn con số đã giảm, tính tiền khác đi mà không nói
// là trừ tiền sai so với thứ họ đồng ý trả.
func (s *orderService) applyVoucher(ctx context.Context, code string, o *domain.Order, subtotal float64, userID uint, phone string) (*domain.VoucherClaim, error) {
	code = strings.ToUpper(strings.TrimSpace(code))
	if code == "" {
		return nil, nil
	}
	if s.vouchers == nil {
		return nil, domain.ErrVoucherNotFound
	}

	v, discount, err := s.vouchers.Check(ctx, code, subtotal, userID, phone)
	if err != nil {
		return nil, err
	}

	o.VoucherID = &v.ID
	// VoucherCode là BẢN CHỤP: mã có thể bị sửa hoặc xoá sau này, còn đơn cũ phải
	// mãi mãi nói đúng cái mã khách đã dùng hôm đó.
	o.VoucherCode = v.Code
	o.DiscountAmount = discount

	claim := &domain.VoucherClaim{
		VoucherID: v.ID,
		// Số điện thoại đi cùng yêu cầu tiêu lượt: đây là danh tính để những lần đặt
		// sau nhận ra đúng khách này, kể cả khi họ không đăng nhập.
		Phone:             phone,
		Discount:          discount,
		UsageLimit:        v.UsageLimit,
		UsageLimitPerUser: v.UsageLimitPerUser,
	}
	if userID > 0 {
		uid := userID
		claim.UserID = &uid
	}
	return claim, nil
}

// applyPromotions trừ khuyến mãi đang chạy vào giá các dòng hàng sắp tính tiền.
func (s *orderService) applyPromotions(ctx context.Context, found map[uint]domain.CheckoutVariant) {
	if s.promos == nil {
		return
	}
	s.promos.ApplyToCheckout(ctx, found)
}

// storeName — tên cửa hàng in trong thân email. Lấy từ cấu hình hệ thống; chưa
// khai thì dùng tên hiển thị của hộp thư gửi đi.
func (s *orderService) storeName(ctx context.Context) string {
	if s.settings != nil {
		if v := strings.TrimSpace(s.settings.Get(ctx, SettingSiteName)); v != "" {
			return v
		}
	}
	return s.mailCfg.FromName
}

func (s *orderService) List(ctx context.Context, filter domain.OrderFilter) ([]domain.Order, int64, error) {
	return s.orderRepo.List(ctx, filter)
}

func (s *orderService) GetByID(ctx context.Context, id uint) (*OrderDetail, error) {
	o, err := s.orderRepo.FindByID(ctx, id)
	if err != nil {
		return nil, err
	}
	return s.detail(ctx, o)
}

// Checkout xử lý đơn khách đặt từ website.
//
// Giá, tên và SKU đều tra lại từ database — payload của client chỉ nói "mua biến
// thể nào, mấy cái". Tồn kho được kiểm và trừ ngay trong transaction của
// repository nên hai khách bấm đặt cùng lúc không bán vượt hàng.
// userID = 0 nghĩa là khách vãng lai (đơn không gắn tài khoản).
func (s *orderService) Checkout(ctx context.Context, req *dto.CheckoutRequest, userID uint) (*dto.CheckoutResponse, error) {
	if len(req.Items) == 0 {
		return nil, domain.ErrEmptyCart
	}
	// Hình thức thanh toán do cửa hàng bật/tắt ở trang Cài đặt. Chặn ngay tại đây
	// chứ không chỉ ẩn nút bên storefront: trang khách đang mở có thể đã tải từ
	// trước lúc cửa hàng tắt, và đây còn là API công khai.
	if !paymentMethodAvailable(ctx, s.settings, req.PaymentMethod) {
		return nil, domain.ErrPaymentMethodDisabled
	}
	// Thanh toán online còn cần chính cổng đã được nối vào bản chạy này. Chặn TRƯỚC
	// khi trừ kho: đơn tạo xong mới phát hiện không mở được cổng thì hàng đã bị giữ
	// cho một đơn khách không trả tiền được.
	if domain.OnlinePaymentMethod(req.PaymentMethod) &&
		(s.payments == nil || !s.payments.Available(req.PaymentMethod)) {
		return nil, domain.ErrPaymentMethodDisabled
	}

	lines := make([]domain.CheckoutLine, 0, len(req.Items))
	for _, it := range req.Items {
		lines = append(lines, domain.CheckoutLine{
			VariantID:          it.ProductVariantID,
			Slug:               it.Slug,
			Size:               it.Size,
			Color:              it.Color,
			Quantity:           it.Quantity,
			CustomPlayerName:   it.CustomPlayerName,
			CustomPlayerNumber: it.CustomPlayerNumber,
		})
	}

	order, err := s.orderRepo.Checkout(ctx, lines, func(found map[uint]domain.CheckoutVariant) (*domain.Order, *domain.VoucherClaim, error) {
		// Trừ chương trình khuyến mãi NGAY TRONG giao dịch đặt hàng: giá thu tiền
		// phải là giá tại đúng thời điểm khách bấm đặt. Tính ở ngoài rồi truyền vào
		// là có khe cho đợt vừa hết hạn vẫn được giảm, hoặc ngược lại.
		s.applyPromotions(ctx, found)

		// Gộp các dòng trùng biến thể để kiểm tồn kho theo TỔNG số lượng,
		// tránh trường hợp 2 dòng cùng biến thể đều lọt qua rồi trừ âm kho.
		type picked struct {
			cv   domain.CheckoutVariant
			qty  int
			name string
			num  string
		}
		merged := make(map[uint]*picked, len(lines))
		order := make([]uint, 0, len(lines))

		for _, l := range lines {
			cv, ok := matchVariant(found, l)
			if !ok {
				return nil, nil, fmt.Errorf("%w: %s", domain.ErrVariantNotFound, strings.TrimSpace(l.Slug))
			}
			if p, exists := merged[cv.VariantID]; exists {
				p.qty += l.Quantity
			} else {
				merged[cv.VariantID] = &picked{cv: cv, qty: l.Quantity, name: l.CustomPlayerName, num: l.CustomPlayerNumber}
				order = append(order, cv.VariantID)
			}
		}

		now := time.Now()
		o := &domain.Order{
			RecipientName:    strings.TrimSpace(req.RecipientName),
			RecipientPhone:   strings.TrimSpace(req.RecipientPhone),
			RecipientEmail:   strings.TrimSpace(req.RecipientEmail),
			ShippingProvince: strings.TrimSpace(req.ShippingProvince),
			ShippingDistrict: strings.TrimSpace(req.ShippingDistrict),
			ShippingWard:     strings.TrimSpace(req.ShippingWard),
			ShippingAddress:  strings.TrimSpace(req.ShippingAddress),
			PaymentMethod:    req.PaymentMethod,
			PaymentStatus:    "pending",
			Status:           domain.OrderStatusPending,
			Note:             strings.TrimSpace(req.Note),
			PlacedAt:         &now,
		}
		if userID > 0 {
			uid := userID
			o.UserID = &uid
		}

		var subtotal float64
		for _, vid := range order {
			p := merged[vid]
			if p.cv.Stock < p.qty {
				return nil, nil, fmt.Errorf("%w: %s (còn %d)", domain.ErrOutOfStock, p.cv.ProductName, p.cv.Stock)
			}
			line := p.cv.Price * float64(p.qty)
			subtotal += line

			pid := p.cv.ProductID
			varID := p.cv.VariantID
			o.Items = append(o.Items, domain.OrderItem{
				ProductID:          &pid,
				ProductVariantID:   &varID,
				ProductName:        p.cv.ProductName,
				VariantSKU:         p.cv.SKU,
				Size:               p.cv.Size,
				Color:              p.cv.Color,
				Thumbnail:          p.cv.Thumbnail,
				UnitPrice:          p.cv.Price,
				Quantity:           p.qty,
				TotalPrice:         line,
				CustomPlayerName:   strings.TrimSpace(p.name),
				CustomPlayerNumber: strings.TrimSpace(p.num),
			})
		}

		o.SubtotalAmount = subtotal
		o.ShippingFee = s.shippingFeeFor(ctx, subtotal)

		// Mã giảm giá: kiểm và tính lại NGAY TẠI ĐÂY, trên số tiền hàng vừa tính từ
		// giá đã khoá — không tin con số trình duyệt gửi lên. Cùng lý do với khuyến
		// mãi ở đầu hàm: giữa lúc khách xem giỏ và lúc bấm đặt, mã có thể vừa hết
		// hạn, hết lượt, hoặc giỏ đổi làm đơn tụt xuống dưới mức tối thiểu.
		claim, err := s.applyVoucher(ctx, req.VoucherCode, o, subtotal, userID, o.RecipientPhone)
		if err != nil {
			return nil, nil, err
		}

		// Phí ship tính trên tiền hàng TRƯỚC giảm giá: mã giảm giá là ưu đãi của cửa
		// hàng, không phải cái làm khách mất luôn ngưỡng miễn phí vận chuyển họ đã
		// đạt được.
		o.TotalAmount = subtotal - o.DiscountAmount + o.ShippingFee
		return o, claim, nil
	})
	if err != nil {
		return nil, err
	}

	// Đơn đã tạo và kho đã trừ xong: gửi thư ở goroutine riêng để khách không phải
	// đợi SMTP, và SMTP hỏng cũng không làm hỏng đơn đã đặt.
	s.sendOrderMail(ctx, order)

	// Báo cho nhân viên đang mở trang admin — đây là lý do chính của tính năng:
	// đơn khách đặt phải hiện lên ngay, không đợi ai bấm tải lại trang.
	s.notifyAdmin(ctx, "order_created",
		"Đơn hàng mới "+order.OrderCode,
		orderSummary(order),
		order)
	// Khách đã đăng nhập thì đơn cũng vào chuông thông báo của chính khách.
	s.notifyCustomer(ctx, order, "order_created",
		"Đã đặt đơn "+order.OrderCode,
		"Đơn hàng đang chờ cửa hàng xác nhận. Chúng tôi sẽ báo lại ngay khi có cập nhật.")
	s.signalOrder(ctx, order)
	// Đơn vừa lấy hàng khỏi kho — mặt hàng nào tụt xuống mức cần nhập thêm thì báo ngay.
	s.notifyLowStock(ctx, order)

	msg := "Đặt hàng thành công! Chúng tôi sẽ gọi xác nhận trong giờ làm việc."
	// Đơn chuyển khoản: khách vừa đặt xong là lúc họ mở app ngân hàng, nên thông
	// tin tài khoản phải đi kèm ngay trong câu trả lời chứ không để họ đi tìm.
	var bank *dto.CheckoutBankTransfer
	if order.PaymentMethod == "bank_transfer" {
		msg = "Đặt hàng thành công! Vui lòng chuyển khoản theo thông tin bên dưới, chúng tôi xác nhận ngay khi nhận được tiền."
		bank = &dto.CheckoutBankTransfer{
			BankName:      settingText(ctx, s.settings, SettingBankName),
			AccountNumber: settingText(ctx, s.settings, SettingBankAccountNumber),
			AccountName:   settingText(ctx, s.settings, SettingBankAccountName),
			Content:       order.OrderCode,
			QRImage:       settingText(ctx, s.settings, SettingBankQRImage),
			Note:          settingText(ctx, s.settings, SettingBankTransferNote),
		}
	}
	// Đơn thanh toán online: mở cổng ngay để khách trả tiền liền mạch, không phải
	// quay lại tìm đơn.
	//
	// Cổng hỏng thì KHÔNG huỷ đơn: hàng đã trừ kho, khách đã điền xong địa chỉ, và
	// tiền vẫn thu được bằng cách khác. Nói thẳng ra là chưa mở được và mời họ liên
	// hệ, hơn là bắt đặt lại từ đầu.
	var pay *dto.CheckoutPayment
	if domain.OnlinePaymentMethod(order.PaymentMethod) {
		var err error
		if pay, err = s.payments.Start(ctx, order); err != nil {
			logger.Error("đơn đã tạo nhưng không mở được cổng thanh toán",
				zap.String("order_code", order.OrderCode), zap.Error(err))
			msg = "Đã đặt hàng thành công nhưng chưa mở được cổng thanh toán. Vui lòng gọi " +
				settingText(ctx, s.settings, SettingContactPhone) + " để cửa hàng hỗ trợ thanh toán."
		} else {
			msg = "Đã tạo đơn. Vui lòng hoàn tất thanh toán để cửa hàng chuẩn bị hàng cho bạn."
		}
	}

	if strings.TrimSpace(order.RecipientEmail) != "" && s.mail.Enabled() {
		// "sẽ gửi" chứ không phải "đã gửi": thư đi ở goroutine riêng, lúc trả lời khách
		// thì SMTP còn chưa chắc nhận.
		msg += " Email xác nhận sẽ được gửi tới " + strings.TrimSpace(order.RecipientEmail) + "."
	}

	return &dto.CheckoutResponse{
		OrderID:       order.ID,
		OrderCode:     order.OrderCode,
		Subtotal:      order.SubtotalAmount,
		ShippingFee:   order.ShippingFee,
		Discount:      order.DiscountAmount,
		VoucherCode:   order.VoucherCode,
		Total:         order.TotalAmount,
		PaymentMethod: order.PaymentMethod,
		Status:        order.Status,
		Message:       msg,
		BankTransfer:  bank,
		Payment:       pay,
	}, nil
}

// Quote đối chiếu giỏ hàng của khách với dữ liệu hiện tại: trả về ĐƠN GIÁ đang áp
// dụng, số lượng còn đặt được và số tiền server sẽ tính nếu khách đặt ngay.
//
// Giỏ hàng nằm ở localStorage nên giá trong đó là bản chụp lúc khách thêm hàng.
// Admin đổi giá trong lúc khách còn để hàng trong giỏ thì con số khách nhìn thấy
// khác con số server tính. Storefront gọi hàm này khi mở trang thanh toán để cập
// nhật lại giỏ trước, thay vì để khách chỉ phát hiện ở màn đặt hàng thành công.
//
// Không tạo đơn, không giữ hàng, không khoá dòng: đây chỉ là ảnh chụp tại thời
// điểm hỏi. Số tiền chính thức vẫn do luồng đặt hàng tính lại.
func (s *orderService) Quote(ctx context.Context, req *dto.CartQuoteRequest) (*dto.CartQuoteResponse, error) {
	if len(req.Items) == 0 {
		return nil, domain.ErrEmptyCart
	}

	lines := make([]domain.CheckoutLine, 0, len(req.Items))
	for _, it := range req.Items {
		lines = append(lines, domain.CheckoutLine{
			VariantID: it.ProductVariantID,
			Slug:      it.Slug,
			Size:      it.Size,
			Color:     it.Color,
			Quantity:  it.Quantity,
		})
	}

	found, err := s.orderRepo.QuoteVariants(ctx, lines)
	if err != nil {
		return nil, err
	}
	// Cùng một hàm với lúc đặt hàng, nên con số báo cho khách xem giỏ đúng bằng số
	// sẽ thu.
	s.applyPromotions(ctx, found)

	res := &dto.CartQuoteResponse{
		Items:             make([]dto.CartQuoteItem, 0, len(lines)),
		FreeShipThreshold: settingNumber(ctx, s.settings, SettingFreeShippingThreshold),
	}

	// Tồn kho được chia theo thứ tự các dòng: hai dòng cùng biến thể (khác tên in
	// áo) không cùng nhận trọn số hàng còn lại rồi báo đủ hàng cho cả hai.
	left := make(map[uint]int, len(found))

	for _, l := range lines {
		item := dto.CartQuoteItem{
			Slug:              strings.TrimSpace(l.Slug),
			Size:              strings.TrimSpace(l.Size),
			Color:             strings.TrimSpace(l.Color),
			RequestedQuantity: l.Quantity,
		}

		cv, ok := matchVariant(found, l)
		if !ok {
			item.Issue = "unavailable"
			res.Items = append(res.Items, item)
			res.HasIssue = true
			continue
		}

		if _, seen := left[cv.VariantID]; !seen {
			left[cv.VariantID] = cv.Stock
		}
		qty := min(l.Quantity, max(0, left[cv.VariantID]))
		left[cv.VariantID] -= qty

		item.ProductVariantID = cv.VariantID
		item.ProductName = cv.ProductName
		item.Thumbnail = cv.Thumbnail
		item.Size = cv.Size
		item.Color = cv.Color
		item.UnitPrice = cv.Price
		item.Stock = cv.Stock
		item.Quantity = qty
		item.LineTotal = cv.Price * float64(qty)
		item.Available = qty > 0

		switch {
		case qty == 0:
			item.Issue = "out_of_stock"
			res.HasIssue = true
		case qty < l.Quantity:
			item.Issue = "limited"
			res.HasIssue = true
		}

		res.Subtotal += item.LineTotal
		res.Items = append(res.Items, item)
	}

	res.ShippingFee = s.shippingFeeFor(ctx, res.Subtotal)
	res.Total = res.Subtotal + res.ShippingFee
	return res, nil
}

// MyOrders trả đơn của CHÍNH khách đang đăng nhập.
//
// UserID trong filter luôn bị ghi đè bằng id lấy từ access token — client không
// thể truyền user_id để xem đơn của người khác. Ghi chú nội bộ của admin cũng
// được xoá khỏi kết quả, y như luồng tra cứu công khai.
func (s *orderService) MyOrders(ctx context.Context, userID uint, filter domain.OrderFilter) ([]domain.Order, int64, error) {
	if userID == 0 {
		return nil, 0, domain.ErrForbidden
	}
	uid := userID
	filter.UserID = &uid
	// Các bộ lọc chỉ dành cho admin: khách chỉ được lọc theo trạng thái đơn.
	filter.Keyword = ""
	filter.PaymentStatus = ""
	filter.PaymentMethod = ""

	orders, total, err := s.orderRepo.List(ctx, filter)
	if err != nil {
		return nil, 0, err
	}
	for i := range orders {
		orders[i].AdminNote = ""
	}
	return orders, total, nil
}

// MyOrder trả chi tiết một đơn của khách đang đăng nhập.
//
// Đơn của người khác trả về ErrNotFound chứ không phải ErrForbidden: báo "không
// có quyền" là đã xác nhận đơn đó tồn tại, cho phép dò mã đơn bằng cách thử ID.
func (s *orderService) MyOrder(ctx context.Context, userID, id uint) (*OrderDetail, error) {
	if userID == 0 {
		return nil, domain.ErrForbidden
	}
	o, err := s.orderRepo.FindByID(ctx, id)
	if err != nil {
		return nil, err
	}
	if o.UserID == nil || *o.UserID != userID {
		return nil, domain.ErrNotFound
	}
	o.AdminNote = ""

	histories, err := s.orderRepo.Histories(ctx, o.ID)
	if err != nil {
		return nil, err
	}
	// Không trả NextStatuses: đổi trạng thái là việc của admin, khách không có
	// thao tác nào từ danh sách đó — thao tác duy nhất của khách là tự huỷ.
	return &OrderDetail{Order: o, Histories: histories, CanCancel: customerCancellable(o.Status)}, nil
}

// customerCancellable cho biết khách có được TỰ huỷ đơn ở trạng thái này không.
//
// Chỉ mở tới "đã xác nhận": từ "đang chuẩn bị" trở đi hàng đã được soạn/dán nhãn
// hoặc bàn giao vận chuyển, huỷ một chiều từ web sẽ lệch với thực tế ngoài kho.
// Các bước sau đó vẫn huỷ được nhưng phải qua nhân viên (luồng admin).
func customerCancellable(status string) bool {
	return status == domain.OrderStatusPending || status == domain.OrderStatusConfirmed
}

// CancelMine cho khách tự huỷ đơn của chính mình.
//
// Kiểm tra chủ sở hữu và trạng thái đều nằm TRONG khoá dòng của LockAndUpdate,
// nên không có kẽ hở giữa lúc đọc và lúc ghi: khách bấm huỷ đúng lúc admin chuyển
// sang "đang giao" thì chỉ một trong hai thao tác đi qua được.
//
// Hàng được trả về kho đúng bằng số đơn đã lấy (StockRelease), y như admin huỷ.
func (s *orderService) CancelMine(ctx context.Context, userID, id uint, reason string) (*OrderDetail, error) {
	if userID == 0 {
		return nil, domain.ErrForbidden
	}

	o, err := s.orderRepo.LockAndUpdate(ctx, id, func(o *domain.Order) (*domain.OrderStatusHistory, []string, *domain.StockRelease, error) {
		// Đơn của người khác trả ErrNotFound — xem giải thích ở MyOrder.
		if o.UserID == nil || *o.UserID != userID {
			return nil, nil, nil, domain.ErrNotFound
		}
		if o.Status == domain.OrderStatusCancelled {
			return nil, nil, nil, domain.ErrConflict
		}
		if !customerCancellable(o.Status) {
			return nil, nil, nil, domain.ErrCancelNotAllowed
		}

		from := o.Status
		now := time.Now()
		note := strings.TrimSpace(reason)
		if note == "" {
			note = "Khách tự huỷ đơn"
		}

		o.Status = domain.OrderStatusCancelled
		o.CancelledAt = &now
		o.CancelReason = note

		history := &domain.OrderStatusHistory{
			OrderID:    o.ID,
			FromStatus: from,
			ToStatus:   o.Status,
			Note:       "Khách tự huỷ từ website: " + note,
			ChangedBy:  &userID,
		}
		return history, []string{"Status", "CancelledAt", "CancelReason"}, &domain.StockRelease{Type: "return", Note: "Khách tự huỷ"}, nil
	})
	if err != nil {
		return nil, err
	}

	// Thư xác nhận đã huỷ, cùng mẫu với khi admin huỷ.
	s.sendOrderStatusMail(ctx, o)

	// Khách tự huỷ là việc nhân viên cần biết ngay (hàng đã soạn, đã trả về kho).
	s.notifyAdmin(ctx, "order_cancelled",
		"Khách huỷ đơn "+o.OrderCode,
		orderSummary(o)+" — lý do: "+o.CancelReason,
		o)
	s.notifyCustomer(ctx, o, "order_status",
		"Đơn "+o.OrderCode+" đã huỷ",
		"Đơn hàng đã được huỷ theo yêu cầu của bạn, toàn bộ sản phẩm đã trả lại kho.")
	s.signalOrder(ctx, o)

	o.AdminNote = ""
	histories, err := s.orderRepo.Histories(ctx, o.ID)
	if err != nil {
		return nil, err
	}
	return &OrderDetail{Order: o, Histories: histories}, nil
}

// paymentMethodLabel đổi mã phương thức thanh toán thành câu chữ cho khách đọc.
func paymentMethodLabel(m string) string {
	switch m {
	case "cod":
		return "Thanh toán khi nhận hàng (COD)"
	case "bank_transfer":
		return "Chuyển khoản ngân hàng"
	case "vnpay":
		return "Thanh toán qua VNPAY"
	case "momo":
		return "Thanh toán qua MoMo"
	case domain.PaymentMethodPayOS, domain.PaymentMethodSePay:
		return "Thanh toán online (QR ngân hàng)"
	default:
		return m
	}
}

// fullAddress gộp địa chỉ nhận hàng thành một dòng, bỏ phần trống.
func fullAddress(o *domain.Order) string {
	parts := make([]string, 0, 4)
	for _, p := range []string{o.ShippingAddress, o.ShippingWard, o.ShippingDistrict, o.ShippingProvince} {
		if v := strings.TrimSpace(p); v != "" {
			parts = append(parts, v)
		}
	}
	return strings.Join(parts, ", ")
}

// sendOrderMail gửi email xác nhận đơn cho khách, CHẠY NỀN.
//
// Đơn đã nằm trong database và kho đã trừ trước khi hàm này được gọi, nên thư
// hỏng chỉ được ghi log chứ không bao giờ làm hỏng đơn: bắt khách đặt lại vì lỗi
// SMTP sẽ tạo đơn trùng và trừ kho hai lần.
//
// Bỏ qua khi khách không để lại email (trường này không bắt buộc) hoặc chưa cấu
// hình SMTP.
func (s *orderService) sendOrderMail(ctx context.Context, o *domain.Order) {
	// Chốt o != nil TRƯỚC rồi mới đọc trường bên trong. Viết ngược lại — lấy
	// o.RecipientEmail rồi mới kiểm tra o == nil — thì cú đọc đầu tiên đã panic
	// mất rồi, và vế `o == nil` không bao giờ có cơ hội chạy.
	if o == nil || s.mail == nil || !s.mail.Enabled() {
		return
	}
	email := strings.TrimSpace(o.RecipientEmail)
	if email == "" {
		return
	}

	// Chụp lại dữ liệu cần dùng trước khi rời hàm — goroutine không được đụng vào
	// con trỏ đơn mà nơi gọi vẫn đang dùng.
	data := mailer.OrderData{
		StoreName:     s.storeName(ctx),
		StoreURL:      s.mailCfg.StoreURL,
		Hotline:       settingText(ctx, s.settings, SettingContactPhone),
		Year:          time.Now().Year(),
		OrderCode:     o.OrderCode,
		PlacedAt:      placedAtText(o),
		Name:          o.RecipientName,
		Phone:         o.RecipientPhone,
		Address:       fullAddress(o),
		PaymentMethod: paymentMethodLabel(o.PaymentMethod),
		Note:          strings.TrimSpace(o.Note),
		Subtotal:      o.SubtotalAmount,
		Discount:      o.DiscountAmount,
		ShippingFee:   o.ShippingFee,
		Total:         o.TotalAmount,
		Items:         make([]mailer.OrderItemData, 0, len(o.Items)),
	}
	// Chỉ đơn chuyển khoản mới kèm hướng dẫn — đơn COD nhận thêm số tài khoản chỉ
	// tổ làm khách phân vân không biết có phải chuyển trước không.
	if o.PaymentMethod == "bank_transfer" && paymentMethodAvailable(ctx, s.settings, "bank_transfer") {
		data.BankName = settingText(ctx, s.settings, SettingBankName)
		data.BankAccountNumber = settingText(ctx, s.settings, SettingBankAccountNumber)
		data.BankAccountName = settingText(ctx, s.settings, SettingBankAccountName)
		data.BankTransferNote = settingText(ctx, s.settings, SettingBankTransferNote)
	}
	for _, it := range o.Items {
		opts := make([]string, 0, 2)
		if v := strings.TrimSpace(it.Size); v != "" {
			opts = append(opts, "Size "+v)
		}
		if v := strings.TrimSpace(it.Color); v != "" {
			opts = append(opts, v)
		}
		custom := strings.TrimSpace(strings.TrimSpace(it.CustomPlayerName) + " " + strings.TrimSpace(it.CustomPlayerNumber))
		data.Items = append(data.Items, mailer.OrderItemData{
			Name:    it.ProductName,
			Options: strings.Join(opts, " / "),
			Custom:  custom,
			Qty:     it.Quantity,
			Price:   it.UnitPrice,
			Total:   it.TotalPrice,
		})
	}

	go func() {
		html, text, err := mailer.RenderOrderConfirmation(data)
		if err != nil {
			logger.Error("dựng email xác nhận đơn thất bại",
				zap.String("order_code", data.OrderCode), zap.Error(err))
			return
		}
		subject := fmt.Sprintf("Đơn hàng %s đã được ghi nhận — %s", data.OrderCode, data.StoreName)

		msgID, err := s.mail.Send(email, data.Name, subject, html, text)
		if err != nil {
			// Chỉ ghi log: đơn vẫn hợp lệ, nhân viên gọi xác nhận theo số điện thoại.
			logger.Error("gửi email xác nhận đơn thất bại",
				zap.String("order_code", data.OrderCode), zap.String("email", email), zap.Error(err))
			return
		}
		// Ghi Message-ID để tra lại trong hộp thư khi khách báo "không thấy mail".
		logger.Info("đã gửi email xác nhận đơn",
			zap.String("order_code", data.OrderCode), zap.String("email", email), zap.String("message_id", msgID))
	}()
}

// statusMail mô tả nội dung email cho một trạng thái đơn.
type statusMail struct {
	label    string
	headline string
	detail   string
	danger   bool
}

// statusMails — trạng thái nào đáng báo cho khách và báo bằng câu chữ gì.
//
// Cố ý KHÔNG có "processing": đó là bước nội bộ của kho, khách vừa nhận thư "đã
// xác nhận" mà lại nhận thêm thư "đang chuẩn bị hàng" thì chỉ thành phiền.
var statusMails = map[string]statusMail{
	domain.OrderStatusConfirmed: {
		label:    "Đã xác nhận",
		headline: "Đơn hàng đã được xác nhận",
		detail:   "Chúng tôi đã xác nhận đơn của bạn và đang chuẩn bị hàng. Bạn sẽ nhận được thông báo tiếp theo khi đơn được giao cho đơn vị vận chuyển.",
	},
	domain.OrderStatusShipping: {
		label:    "Đang giao hàng",
		headline: "Đơn hàng đang trên đường tới bạn",
		detail:   "Đơn đã được bàn giao cho đơn vị vận chuyển. Vui lòng để ý điện thoại để shipper liên hệ khi giao hàng.",
	},
	domain.OrderStatusDelivered: {
		label:    "Đã giao hàng",
		headline: "Đơn hàng đã được giao",
		detail:   "Cảm ơn bạn đã mua sắm tại cửa hàng. Nếu sản phẩm có bất kỳ vấn đề gì, hãy liên hệ trong vòng 3 ngày để được đổi size hoặc đổi hàng.",
	},
	domain.OrderStatusCompleted: {
		label:    "Hoàn tất",
		headline: "Đơn hàng đã hoàn tất",
		detail:   "Đơn hàng đã hoàn tất và được ghi nhận thanh toán. Rất mong được phục vụ bạn lần sau!",
	},
	domain.OrderStatusCancelled: {
		label:    "Đã huỷ",
		headline: "Đơn hàng đã được huỷ",
		detail:   "Đơn hàng của bạn đã được huỷ và toàn bộ sản phẩm đã được trả lại kho. Nếu đây là nhầm lẫn, vui lòng liên hệ để chúng tôi hỗ trợ đặt lại.",
		danger:   true,
	},
	domain.OrderStatusReturned: {
		label:    "Đã hoàn hàng",
		headline: "Đơn hàng đã được ghi nhận hoàn",
		detail:   "Chúng tôi đã nhận lại hàng của đơn này. Nếu đơn đã thanh toán, bộ phận chăm sóc khách hàng sẽ liên hệ để hoàn tiền.",
		danger:   true,
	},
}

// sendOrderStatusMail báo cho khách biết đơn vừa đổi trạng thái, CHẠY NỀN.
//
// Trạng thái đã được ghi vào database trước khi gọi hàm này, nên thư hỏng chỉ ghi
// log: không được để lỗi SMTP làm admin tưởng thao tác thất bại rồi bấm lại.
func (s *orderService) sendOrderStatusMail(ctx context.Context, o *domain.Order) {
	if o == nil {
		return
	}
	email := strings.TrimSpace(o.RecipientEmail)
	if email == "" || s.mail == nil || !s.mail.Enabled() {
		return
	}
	tpl, ok := statusMails[o.Status]
	if !ok {
		return
	}

	data := mailer.OrderStatusData{
		StoreName:      s.storeName(ctx),
		StoreURL:       s.mailCfg.StoreURL,
		Hotline:        settingText(ctx, s.settings, SettingContactPhone),
		Year:           time.Now().Year(),
		OrderCode:      o.OrderCode,
		Name:           o.RecipientName,
		Address:        fullAddress(o),
		Label:          tpl.label,
		Headline:       tpl.headline,
		Detail:         tpl.detail,
		Danger:         tpl.danger,
		ShippingMethod: strings.TrimSpace(o.ShippingMethod),
		TrackingNumber: strings.TrimSpace(o.TrackingNumber),
		Total:          o.TotalAmount,
	}
	if o.Status == domain.OrderStatusCancelled {
		data.Reason = strings.TrimSpace(o.CancelReason)
	}

	go func() {
		html, text, err := mailer.RenderOrderStatus(data)
		if err != nil {
			logger.Error("dựng email cập nhật đơn thất bại",
				zap.String("order_code", data.OrderCode), zap.Error(err))
			return
		}
		subject := fmt.Sprintf("Đơn hàng %s: %s — %s", data.OrderCode, strings.ToLower(data.Label), data.StoreName)

		msgID, err := s.mail.Send(email, data.Name, subject, html, text)
		if err != nil {
			logger.Error("gửi email cập nhật đơn thất bại",
				zap.String("order_code", data.OrderCode), zap.String("status", data.Label), zap.Error(err))
			return
		}
		logger.Info("đã gửi email cập nhật đơn",
			zap.String("order_code", data.OrderCode), zap.String("status", data.Label),
			zap.String("email", email), zap.String("message_id", msgID))
	}()
}

// ---------- Thông báo & realtime ----------
//
// Ba helper dưới đây là toàn bộ chỗ order service chạm tới realtime. Chúng đều
// im lặng khi notify == nil (service dựng trong test không cần realtime) và
// không bao giờ trả lỗi: đơn đã ghi vào database rồi, một thông báo hỏng không
// được phép biến thao tác thành công thành lỗi 500 trên màn hình.

// orderPayload — dữ liệu tối thiểu đủ để giao diện biết "đơn nào, đang ra sao".
// Cố ý KHÔNG gửi cả đơn: gói tin này đi tới mọi tab admin đang mở, không nên
// mang theo địa chỉ, ghi chú nội bộ hay danh sách hàng.
func orderPayload(o *domain.Order) map[string]any {
	return map[string]any{
		"order_id":       o.ID,
		"order_code":     o.OrderCode,
		"status":         o.Status,
		"payment_status": o.PaymentStatus,
		"total_amount":   o.TotalAmount,
		"recipient_name": o.RecipientName,
	}
}

// orderSummary là một dòng tóm tắt đơn để đọc trong chuông thông báo.
func orderSummary(o *domain.Order) string {
	var qty int
	for _, it := range o.Items {
		qty += it.Quantity
	}
	s := o.RecipientName + " · " + formatVND(o.TotalAmount)
	if qty > 0 {
		s += " · " + strconv.Itoa(qty) + " sản phẩm"
	}
	return s
}

// formatVND in số tiền kiểu Việt Nam: 1.250.000₫.
func formatVND(v float64) string {
	n := strconv.FormatInt(int64(v), 10)
	neg := strings.HasPrefix(n, "-")
	n = strings.TrimPrefix(n, "-")

	var b strings.Builder
	for i, c := range n {
		if i > 0 && (len(n)-i)%3 == 0 {
			b.WriteByte('.')
		}
		b.WriteRune(c)
	}
	if neg {
		return "-" + b.String() + "₫"
	}
	return b.String() + "₫"
}

// notifyAdmin đưa một thông báo vào chuông của mọi nhân viên quản trị.
func (s *orderService) notifyAdmin(ctx context.Context, nType, title, content string, o *domain.Order) {
	if s.notify == nil {
		return
	}
	s.notify.Push(ctx, nil, nType, title, content, orderPayload(o))
}

// notifyCustomer đưa thông báo vào chuông của khách sở hữu đơn.
// Đơn của khách vãng lai (không gắn tài khoản) không có chuông nào để đẩy tới.
func (s *orderService) notifyCustomer(ctx context.Context, o *domain.Order, nType, title, content string) {
	if s.notify == nil || o.UserID == nil {
		return
	}
	s.notify.Push(ctx, o.UserID, nType, title, content, orderPayload(o))
}

// signalOrder bắn tín hiệu "đơn này vừa đổi" để màn hình đang mở tự làm mới.
// Khác notify*: không lưu database, không hiện trong chuông.
func (s *orderService) signalOrder(ctx context.Context, o *domain.Order) {
	if s.notify == nil {
		return
	}
	payload := orderPayload(o)
	s.notify.Signal(ctx, realtime.TopicAdmin, realtime.EventOrder, payload)
	if o.UserID != nil {
		s.notify.Signal(ctx, realtime.TopicUser(*o.UserID), realtime.EventOrder, payload)
	}
}

// ---------- Cảnh báo tồn kho ----------

// lowStockAlert là một biến thể vừa tụt xuống mức cần nhập thêm.
type lowStockAlert struct {
	label string // "Áo Real Madrid 2024 (M / Trắng)"
	sku   string
	stock int // số còn lại sau khi trừ
}

// lowStockAlerts lọc ra những biến thể VỪA CHẠM mức cảnh báo vì đơn này trừ kho.
//
// Chỉ lấy đúng lúc vượt qua ranh giới chứ không lấy mọi biến thể đang nằm dưới
// ngưỡng: hàng sắp hết từ hôm qua mà mỗi đơn mới lại kêu thêm một lần thì chuông
// chỉ còn toàn thông báo nhắc lại cùng một việc, và người ta sẽ ngừng đọc nó.
//
// Hai ranh giới, mỗi biến thể chỉ rơi vào một:
//   - hết sạch: đang còn hàng mà sau đơn về 0 (kể cả khi trước đó đã dưới ngưỡng)
//   - sắp hết: đang trên ngưỡng mà sau đơn tụt xuống ngưỡng, vẫn còn hàng
func lowStockAlerts(o *domain.Order, threshold int) (out, low []lowStockAlert) {
	if len(o.StockMoves) == 0 {
		return nil, nil
	}

	// Tên hiển thị lấy từ chính dòng hàng của đơn: biến thể nào vừa bị trừ kho thì
	// chắc chắn có mặt trong đơn, khỏi phải hỏi lại database.
	labels := make(map[uint]string, len(o.Items))
	for _, it := range o.Items {
		if it.ProductVariantID != nil {
			labels[*it.ProductVariantID] = orderItemLabel(it)
		}
	}

	for _, m := range o.StockMoves {
		if m.After >= m.Before {
			continue // hoàn hàng về kho — không phải chuyện cần cảnh báo
		}

		a := lowStockAlert{label: labels[m.VariantID], sku: m.SKU, stock: m.After}
		if a.label == "" {
			a.label = m.SKU
		}
		switch {
		case m.Before > 0 && m.After <= 0:
			out = append(out, a)
		case m.Before > threshold && m.After <= threshold:
			low = append(low, a)
		}
	}
	return out, low
}

// orderItemLabel dựng tên đọc được cho một dòng hàng: "Áo đấu (M / Trắng)".
func orderItemLabel(it domain.OrderItem) string {
	name := strings.TrimSpace(it.ProductName)
	if name == "" {
		name = it.VariantSKU
	}
	opts := make([]string, 0, 2)
	if it.Size != "" {
		opts = append(opts, it.Size)
	}
	if it.Color != "" {
		opts = append(opts, it.Color)
	}
	if len(opts) > 0 {
		name += " (" + strings.Join(opts, " / ") + ")"
	}
	return name
}

// stockLeftText mô tả số hàng còn lại sau khi đơn trừ kho.
func stockLeftText(a lowStockAlert) string {
	if a.stock <= 0 {
		return "đã hết sạch"
	}
	return "còn " + strconv.Itoa(a.stock) + " sản phẩm"
}

// notifyLowStock báo cho quản trị những biến thể vừa chạm mức cần nhập thêm.
//
// Đây là lý do tính năng tồn tại: trước đó phải tự mở trang Tồn kho mới biết hàng
// đã cạn, nên hàng thường hết trước khi có ai nhận ra.
func (s *orderService) notifyLowStock(ctx context.Context, o *domain.Order) {
	if s.notify == nil {
		return
	}
	out, low := lowStockAlerts(o, DefaultLowStock)
	s.pushStockAlert(ctx, o, "out", out)
	s.pushStockAlert(ctx, o, "low", low)
}

// pushStockAlert đẩy MỘT thông báo cho cả nhóm biến thể cùng mức.
//
// Gộp chứ không bắn từng biến thể: một đơn mua năm mặt hàng đang cạn sẽ thành năm
// tiếng chuông liên tiếp nói cùng một việc.
func (s *orderService) pushStockAlert(ctx context.Context, o *domain.Order, level string, alerts []lowStockAlert) {
	if len(alerts) == 0 {
		return
	}

	nType, one, many := "stock_low", "Sắp hết hàng: ", " biến thể sắp hết hàng"
	if level == "out" {
		nType, one, many = "stock_out", "Hết hàng: ", " biến thể đã hết hàng"
	}

	// stock_level + variant_sku là thứ giao diện dùng để mở đúng trang Tồn kho khi
	// bấm vào thông báo (xem targetUrl trong admin/public/js/realtime.js).
	data := map[string]any{
		"stock_level": level,
		"count":       len(alerts),
		"order_code":  o.OrderCode,
	}

	var title, content string
	if len(alerts) == 1 {
		a := alerts[0]
		data["variant_sku"] = a.sku
		title = one + a.label
		content = "Kho " + stockLeftText(a) + " · SKU " + a.sku + " · đơn " + o.OrderCode
	} else {
		title = strconv.Itoa(len(alerts)) + many
		parts := make([]string, 0, len(alerts))
		for _, a := range alerts {
			parts = append(parts, a.label+": "+stockLeftText(a))
		}
		content = strings.Join(parts, " · ") + " · đơn " + o.OrderCode
	}

	s.notify.Push(ctx, nil, nType, title, content, data)
}

// placedAtText định dạng thời điểm đặt hàng theo kiểu Việt Nam.
func placedAtText(o *domain.Order) string {
	t := time.Now()
	if o.PlacedAt != nil {
		t = *o.PlacedAt
	}
	return t.Format("15:04 02/01/2006")
}

// matchVariant tìm biến thể đã tra được ứng với một dòng giỏ hàng.
func matchVariant(found map[uint]domain.CheckoutVariant, l domain.CheckoutLine) (domain.CheckoutVariant, bool) {
	if l.VariantID > 0 {
		cv, ok := found[l.VariantID]
		return cv, ok
	}
	slug := strings.TrimSpace(l.Slug)
	size := strings.TrimSpace(l.Size)
	color := strings.TrimSpace(l.Color)
	for _, cv := range found {
		if cv.Slug != slug {
			continue
		}
		if size != "" && cv.Size != size {
			continue
		}
		if color != "" && cv.Color != color {
			continue
		}
		return cv, true
	}
	return domain.CheckoutVariant{}, false
}

// shippingFeeFor: miễn phí ship từ ngưỡng free_shipping_threshold, dưới ngưỡng
// thu phí phẳng default_shipping_fee. Cả hai lấy từ cấu hình hệ thống.
//
// Ngưỡng bằng 0 nghĩa là miễn phí vận chuyển cho mọi đơn — đó là cách tắt phí ship
// từ trang cấu hình, không phải lỗi.
func (s *orderService) shippingFeeFor(ctx context.Context, subtotal float64) float64 {
	if subtotal >= settingNumber(ctx, s.settings, SettingFreeShippingThreshold) {
		return 0
	}
	return settingNumber(ctx, s.settings, SettingDefaultShippingFee)
}

// Create tạo đơn thủ công cho một khách hàng CÓ SẴN. Server tự tính tiền hàng và
// tổng tiền từ đơn giá × số lượng (không tin tổng do client gửi); trạng thái khởi
// tạo là "chờ xác nhận" và "chưa thanh toán".
//
// Kho bị trừ y như đơn khách đặt trên web: cùng một transaction, có khoá biến thể
// và ghi sổ kho. Thiếu hàng thì trả ErrOutOfStock và không tạo đơn.
func (s *orderService) Create(ctx context.Context, req *dto.OrderCreateRequest) (*OrderDetail, error) {
	exists, err := s.orderRepo.UserExists(ctx, req.UserID)
	if err != nil {
		return nil, err
	}
	if !exists {
		return nil, domain.ErrNotFound
	}

	now := time.Now()
	uid := req.UserID
	o := &domain.Order{
		UserID:           &uid,
		RecipientName:    strings.TrimSpace(req.RecipientName),
		RecipientPhone:   strings.TrimSpace(req.RecipientPhone),
		RecipientEmail:   strings.TrimSpace(req.RecipientEmail),
		ShippingProvince: strings.TrimSpace(req.ShippingProvince),
		ShippingDistrict: strings.TrimSpace(req.ShippingDistrict),
		ShippingWard:     strings.TrimSpace(req.ShippingWard),
		ShippingAddress:  strings.TrimSpace(req.ShippingAddress),
		DiscountAmount:   req.DiscountAmount,
		ShippingFee:      req.ShippingFee,
		PaymentMethod:    req.PaymentMethod,
		PaymentStatus:    "pending",
		Status:           domain.OrderStatusPending,
		ShippingMethod:   strings.TrimSpace(req.ShippingMethod),
		Note:             strings.TrimSpace(req.Note),
		PlacedAt:         &now,
	}

	var subtotal float64
	for _, it := range req.Items {
		line := it.UnitPrice * float64(it.Quantity)
		subtotal += line
		vid := it.ProductVariantID
		o.Items = append(o.Items, domain.OrderItem{
			ProductID:          it.ProductID,
			ProductVariantID:   &vid,
			ProductName:        strings.TrimSpace(it.ProductName),
			VariantSKU:         strings.TrimSpace(it.VariantSKU),
			Size:               strings.TrimSpace(it.Size),
			Color:              strings.TrimSpace(it.Color),
			Thumbnail:          strings.TrimSpace(it.Thumbnail),
			UnitPrice:          it.UnitPrice,
			Quantity:           it.Quantity,
			TotalPrice:         line,
			CustomPlayerName:   strings.TrimSpace(it.CustomPlayerName),
			CustomPlayerNumber: strings.TrimSpace(it.CustomPlayerNumber),
		})
	}

	o.SubtotalAmount = subtotal
	total := subtotal - req.DiscountAmount + req.ShippingFee
	if total < 0 {
		total = 0
	}
	o.TotalAmount = total

	if err := s.orderRepo.Create(ctx, o); err != nil {
		return nil, err
	}
	// Đơn admin đặt hộ (khách gọi điện) cũng cần thư xác nhận như đơn đặt trên web,
	// để khách có mã đơn mà tra cứu.
	s.sendOrderMail(ctx, o)
	// Không đẩy thông báo vào chuông của admin: đơn này do chính nhân viên vừa tạo,
	// báo lại cho họ chỉ là nhiễu. Chỉ bắn tín hiệu để bảng đơn ở các máy khác
	// (và tab khác) tự làm mới.
	s.notifyCustomer(ctx, o, "order_created",
		"Đã tạo đơn "+o.OrderCode,
		"Cửa hàng vừa tạo đơn theo yêu cầu của bạn. Đơn đang chờ xác nhận.")
	s.signalOrder(ctx, o)
	// Cảnh báo kho thì vẫn đẩy cho admin: nhân viên tạo đơn không nhất thiết là
	// người phụ trách nhập hàng, và họ cũng không thấy số tồn còn lại khi tạo đơn.
	s.notifyLowStock(ctx, o)
	return s.detail(ctx, o)
}

// Update sửa thông tin người nhận, giao hàng, giảm giá và danh sách sản phẩm của
// một đơn CÓ SẴN. Không đổi khách hàng, mã đơn, trạng thái hay tình trạng thanh
// toán. Chỉ sửa được khi đơn còn ở giai đoạn đầu (chờ xác nhận / đã xác nhận /
// đang chuẩn bị) — sau khi đã giao/huỷ/hoàn thì khoá để không sai lệch sổ sách.
// Server tính lại tiền hàng & tổng tiền từ đơn giá × số lượng, đồng thời chỉnh tồn
// kho theo đúng phần chênh: tăng số lượng thì trừ thêm, giảm hoặc bỏ sản phẩm thì
// hoàn lại kho. Thiếu hàng cho phần tăng thêm thì trả ErrOutOfStock.
func (s *orderService) Update(ctx context.Context, id uint, req *dto.OrderUpdateRequest, actorID uint) (*OrderDetail, error) {
	o, err := s.orderRepo.UpdateDetail(ctx, id, func(o *domain.Order) ([]string, []domain.OrderItem, *domain.OrderStatusHistory, error) {
		if !orderEditable(o.Status) {
			return nil, nil, nil, domain.ErrInvalidStatus
		}

		o.RecipientName = strings.TrimSpace(req.RecipientName)
		o.RecipientPhone = strings.TrimSpace(req.RecipientPhone)
		o.RecipientEmail = strings.TrimSpace(req.RecipientEmail)
		o.ShippingProvince = strings.TrimSpace(req.ShippingProvince)
		o.ShippingDistrict = strings.TrimSpace(req.ShippingDistrict)
		o.ShippingWard = strings.TrimSpace(req.ShippingWard)
		o.ShippingAddress = strings.TrimSpace(req.ShippingAddress)
		o.PaymentMethod = req.PaymentMethod
		o.ShippingMethod = strings.TrimSpace(req.ShippingMethod)
		o.ShippingFee = req.ShippingFee
		o.DiscountAmount = req.DiscountAmount
		o.Note = strings.TrimSpace(req.Note)

		var subtotal float64
		items := make([]domain.OrderItem, 0, len(req.Items))
		for _, it := range req.Items {
			line := it.UnitPrice * float64(it.Quantity)
			subtotal += line
			vid := it.ProductVariantID
			items = append(items, domain.OrderItem{
				ProductID:          it.ProductID,
				ProductVariantID:   &vid,
				ProductName:        strings.TrimSpace(it.ProductName),
				VariantSKU:         strings.TrimSpace(it.VariantSKU),
				Size:               strings.TrimSpace(it.Size),
				Color:              strings.TrimSpace(it.Color),
				Thumbnail:          strings.TrimSpace(it.Thumbnail),
				UnitPrice:          it.UnitPrice,
				Quantity:           it.Quantity,
				TotalPrice:         line,
				CustomPlayerName:   strings.TrimSpace(it.CustomPlayerName),
				CustomPlayerNumber: strings.TrimSpace(it.CustomPlayerNumber),
			})
		}

		o.SubtotalAmount = subtotal
		total := subtotal - req.DiscountAmount + req.ShippingFee
		if total < 0 {
			total = 0
		}
		o.TotalAmount = total

		cols := []string{
			"RecipientName", "RecipientPhone", "RecipientEmail",
			"ShippingProvince", "ShippingDistrict", "ShippingWard", "ShippingAddress",
			"PaymentMethod", "ShippingMethod", "ShippingFee", "DiscountAmount",
			"Note", "SubtotalAmount", "TotalAmount",
		}

		history := &domain.OrderStatusHistory{
			OrderID:    o.ID,
			FromStatus: o.Status,
			ToStatus:   o.Status,
			Note:       "Sửa thông tin đơn hàng",
		}
		if actorID > 0 {
			history.ChangedBy = &actorID
		}
		return cols, items, history, nil
	})
	if err != nil {
		return nil, err
	}
	s.signalOrder(ctx, o)
	// Sửa đơn cũng chạm kho: thêm hàng vào đơn có thể làm biến thể tụt xuống ngưỡng.
	s.notifyLowStock(ctx, o)
	return s.detail(ctx, o)
}

// UpdateStatus đổi trạng thái đơn theo đúng luồng cho phép, ghi lại lịch sử và
// đóng dấu thời gian tương ứng (xác nhận / giao / nhận / huỷ).
func (s *orderService) UpdateStatus(ctx context.Context, id uint, req *dto.OrderStatusRequest, actorID uint) (*OrderDetail, error) {
	// Đơn đã có phiếu trả hàng riêng thì không được chuyển cả đơn sang "hoàn hàng"
	// bằng đường này: phiếu trả nhập kho theo TỪNG MÓN, còn luồng đơn trả TOÀN BỘ
	// hàng đơn đã lấy — đi cả hai là kho được cộng khống. Muốn hoàn cả đơn thì xử
	// lý trên phiếu trả, nó tự khép đơn khi mọi món đã về kho.
	if req.Status == domain.OrderStatusReturned && s.returnRepo != nil {
		count, err := s.returnRepo.CountActive(ctx, id)
		if err != nil {
			return nil, err
		}
		if count > 0 {
			return nil, domain.ErrReturnInProgress
		}
	}

	// Toàn bộ đọc-sửa-ghi chạy dưới khoá dòng: kiểm tra luồng trạng thái trên bản
	// mới nhất, nên hai lần chuyển đồng thời không thể cùng qua từ một trạng thái đầu.
	o, err := s.orderRepo.LockAndUpdate(ctx, id, func(o *domain.Order) (*domain.OrderStatusHistory, []string, *domain.StockRelease, error) {
		from := o.Status
		to := req.Status
		if from == to {
			return nil, nil, nil, domain.ErrConflict
		}
		if !canMoveTo(from, to) {
			return nil, nil, nil, domain.ErrInvalidStatus
		}

		now := time.Now()
		o.Status = to
		cols := []string{"Status"}
		// Đơn khép lại thì hàng quay về kho — nếu không, mỗi lần huỷ là kho mất
		// hàng vĩnh viễn trên sổ sách.
		var release *domain.StockRelease
		switch to {
		case domain.OrderStatusConfirmed:
			o.ConfirmedAt = &now
			cols = append(cols, "ConfirmedAt")
		case domain.OrderStatusShipping:
			o.ShippedAt = &now
			cols = append(cols, "ShippedAt")
		case domain.OrderStatusDelivered:
			o.DeliveredAt = &now
			cols = append(cols, "DeliveredAt")
		case domain.OrderStatusCompleted:
			// Giao thành công thì coi như đã thu tiền (COD thanh toán khi nhận hàng).
			if o.PaymentStatus == "pending" {
				o.PaymentStatus = "paid"
				cols = append(cols, "PaymentStatus")
			}
		case domain.OrderStatusCancelled:
			o.CancelledAt = &now
			o.CancelReason = strings.TrimSpace(req.Note)
			cols = append(cols, "CancelledAt", "CancelReason")
			release = &domain.StockRelease{Type: "return", Note: "Huỷ đơn"}
		case domain.OrderStatusReturned:
			release = &domain.StockRelease{Type: "return", Note: "Khách hoàn hàng"}
		}

		history := &domain.OrderStatusHistory{
			OrderID:    o.ID,
			FromStatus: from,
			ToStatus:   to,
			Note:       strings.TrimSpace(req.Note),
		}
		if actorID > 0 {
			history.ChangedBy = &actorID
		}
		return history, cols, release, nil
	})
	if err != nil {
		return nil, err
	}
	// Trạng thái đã ghi xong: báo cho khách biết đơn đi tới đâu.
	s.sendOrderStatusMail(ctx, o)
	// Chuông của khách dùng lại đúng câu chữ của email — cùng một sự việc thì
	// không nên mô tả bằng hai giọng khác nhau. Trạng thái nội bộ (đang chuẩn bị)
	// không có trong statusMails nên cũng không làm phiền khách ở đây.
	if tpl, ok := statusMails[o.Status]; ok {
		s.notifyCustomer(ctx, o, "order_status",
			"Đơn "+o.OrderCode+": "+strings.ToLower(tpl.label), tpl.detail)
	}
	s.signalOrder(ctx, o)
	return s.detail(ctx, o)
}

func (s *orderService) UpdatePayment(ctx context.Context, id uint, req *dto.OrderPaymentRequest) (*OrderDetail, error) {
	o, err := s.orderRepo.LockAndUpdate(ctx, id, func(o *domain.Order) (*domain.OrderStatusHistory, []string, *domain.StockRelease, error) {
		o.PaymentStatus = req.PaymentStatus
		return nil, []string{"PaymentStatus"}, nil, nil
	})
	if err != nil {
		return nil, err
	}
	// Chỉ báo cho khách khi tiền đã được ghi nhận — các trạng thái còn lại là việc
	// nội bộ của kế toán, khách không làm gì với thông tin đó.
	if o.PaymentStatus == "paid" {
		s.notifyCustomer(ctx, o, "order_payment",
			"Đơn "+o.OrderCode+" đã ghi nhận thanh toán",
			"Cửa hàng đã nhận được thanh toán cho đơn hàng này.")
	}
	s.signalOrder(ctx, o)
	return s.detail(ctx, o)
}

func (s *orderService) UpdateShipping(ctx context.Context, id uint, req *dto.OrderShippingRequest) (*OrderDetail, error) {
	o, err := s.orderRepo.LockAndUpdate(ctx, id, func(o *domain.Order) (*domain.OrderStatusHistory, []string, *domain.StockRelease, error) {
		o.ShippingMethod = strings.TrimSpace(req.ShippingMethod)
		o.TrackingNumber = strings.TrimSpace(req.TrackingNumber)
		return nil, []string{"ShippingMethod", "TrackingNumber"}, nil, nil
	})
	if err != nil {
		return nil, err
	}
	s.signalOrder(ctx, o)
	return s.detail(ctx, o)
}

func (s *orderService) UpdateNote(ctx context.Context, id uint, note string) (*OrderDetail, error) {
	o, err := s.orderRepo.LockAndUpdate(ctx, id, func(o *domain.Order) (*domain.OrderStatusHistory, []string, *domain.StockRelease, error) {
		o.AdminNote = strings.TrimSpace(note)
		return nil, []string{"AdminNote"}, nil, nil
	})
	if err != nil {
		return nil, err
	}
	return s.detail(ctx, o)
}

// Lookup tra cứu đơn theo mã đơn cho storefront (không cần đăng nhập). Ẩn ghi
// chú nội bộ của admin để không lộ ra ngoài.
func (s *orderService) Lookup(ctx context.Context, code string) (*domain.Order, error) {
	o, err := s.orderRepo.FindByCode(ctx, strings.TrimSpace(code))
	if err != nil {
		return nil, err
	}
	o.AdminNote = ""
	return o, nil
}

func (s *orderService) Stats(ctx context.Context) (domain.OrderStats, error) {
	return s.orderRepo.Stats(ctx)
}

func (s *orderService) Revenue(ctx context.Context, days int) (domain.RevenueSummary, error) {
	return s.orderRepo.RevenueSeries(ctx, days)
}

func (s *orderService) detail(ctx context.Context, o *domain.Order) (*OrderDetail, error) {
	histories, err := s.orderRepo.Histories(ctx, o.ID)
	if err != nil {
		return nil, err
	}
	return &OrderDetail{
		Order:        o,
		Histories:    histories,
		NextStatuses: NextOrderStatuses(o.Status),
	}, nil
}

// NextOrderStatuses trả các trạng thái hợp lệ tiếp theo của một đơn.
func NextOrderStatuses(status string) []string {
	next, ok := orderFlow[status]
	if !ok {
		return []string{}
	}
	return next
}

// orderEditable cho biết một đơn còn được phép sửa thông tin/sản phẩm hay không.
// Chỉ cho sửa khi đơn còn ở giai đoạn đầu; sau khi giao/hoàn tất/huỷ/hoàn hàng thì
// khoá để giữ đúng sổ sách và tồn kho.
func orderEditable(status string) bool {
	switch status {
	case domain.OrderStatusPending, domain.OrderStatusConfirmed, domain.OrderStatusProcessing:
		return true
	default:
		return false
	}
}

func canMoveTo(from, to string) bool {
	for _, s := range orderFlow[from] {
		if s == to {
			return true
		}
	}
	return false
}
