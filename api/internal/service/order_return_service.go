package service

import (
	"context"
	"strconv"
	"strings"
	"time"

	"sass-api/internal/domain"
	"sass-api/internal/dto"
	"sass-api/internal/realtime"
)

// returnFlow khai báo các trạng thái được phép chuyển tới từ mỗi trạng thái của
// phiếu trả hàng. Đã hoàn tiền / bị từ chối / bị huỷ là điểm cuối.
//
//	chờ duyệt ──duyệt──> đã duyệt ──nhận hàng──> đã nhận ──hoàn tiền──> đã hoàn tiền
//	    └── từ chối / huỷ ──┘ (cùng nhánh kết thúc)
var returnFlow = map[string][]string{
	domain.ReturnStatusPending:   {domain.ReturnStatusApproved, domain.ReturnStatusRejected, domain.ReturnStatusCancelled},
	domain.ReturnStatusApproved:  {domain.ReturnStatusReceived, domain.ReturnStatusRejected, domain.ReturnStatusCancelled},
	domain.ReturnStatusReceived:  {domain.ReturnStatusRefunded},
	domain.ReturnStatusRefunded:  {},
	domain.ReturnStatusRejected:  {},
	domain.ReturnStatusCancelled: {},
}

// returnWindowDays — số ngày kể từ lúc giao hàng mà khách còn gửi yêu cầu trả
// được. Hết hạn thì luồng tự phục vụ đóng lại, khách phải gọi cửa hàng (nhân
// viên vẫn lập phiếu tay được).
const returnWindowDays = 7

// ReturnDetail là phiếu trả hàng kèm lịch sử và các thao tác hợp lệ kế tiếp.
type ReturnDetail struct {
	*domain.OrderReturn
	Histories []domain.OrderReturnHistory `json:"histories"`
	// NextStatuses là các trạng thái hợp lệ kế tiếp — admin dựng menu thao tác từ
	// đây thay vì chép lại luật vào giao diện rồi lệch với server.
	NextStatuses []string `json:"next_statuses"`
	// CanCancel cho biết KHÁCH có được tự rút yêu cầu này không.
	CanCancel bool `json:"can_cancel"`
}

// ReturnableOrder là đơn hàng kèm danh sách món còn trả được — màn hình tạo phiếu
// dựng form từ đây.
type ReturnableOrder struct {
	Order *domain.Order           `json:"order"`
	Items []domain.ReturnableItem `json:"items"`
	// Returnable = false nghĩa là đơn không nằm trong diện trả hàng; Reason nói rõ vì sao.
	Returnable bool   `json:"returnable"`
	Reason     string `json:"reason"`
}

type OrderReturnService interface {
	List(ctx context.Context, f domain.ReturnFilter) ([]domain.OrderReturn, int64, error)
	GetByID(ctx context.Context, id uint) (*ReturnDetail, error)
	Stats(ctx context.Context) (domain.ReturnStats, error)
	// Returnable trả các món còn trả được của một đơn (luồng admin).
	Returnable(ctx context.Context, orderID uint) (*ReturnableOrder, error)
	// MyReturnable như trên nhưng chỉ cho đơn thuộc về khách đang đăng nhập.
	MyReturnable(ctx context.Context, userID, orderID uint) (*ReturnableOrder, error)

	// CreateByAdmin — nhân viên lập phiếu trả tại quầy, phiếu vào thẳng "đã duyệt".
	CreateByAdmin(ctx context.Context, req *dto.ReturnAdminCreateRequest, actorID uint) (*ReturnDetail, error)
	// CreateMine — khách gửi yêu cầu trả hàng cho đơn của chính mình.
	CreateMine(ctx context.Context, userID uint, req *dto.ReturnCreateRequest) (*ReturnDetail, error)
	// MyReturns / MyReturn — phiếu trả của chính khách đang đăng nhập.
	MyReturns(ctx context.Context, userID uint, f domain.ReturnFilter) ([]domain.OrderReturn, int64, error)
	MyReturn(ctx context.Context, userID, id uint) (*ReturnDetail, error)
	// CancelMine — khách rút yêu cầu khi cửa hàng chưa duyệt.
	CancelMine(ctx context.Context, userID, id uint, reason string) (*ReturnDetail, error)

	UpdateStatus(ctx context.Context, id uint, req *dto.ReturnStatusRequest, actorID uint) (*ReturnDetail, error)
	Settle(ctx context.Context, id uint, req *dto.ReturnSettleRequest, actorID uint) (*ReturnDetail, error)
	UpdateNote(ctx context.Context, id uint, note string) (*ReturnDetail, error)
}

type orderReturnService struct {
	repo domain.OrderReturnRepository
	// notify có thể nil (test dựng service không cần realtime) — mọi chỗ dùng đều
	// qua helper đã kiểm tra nil ở cuối file.
	notify NotificationService
	// settings cung cấp hotline in trong thông báo gửi khách. Có thể nil:
	// settingText rơi về mặc định của registry.
	settings SettingService
}

func NewOrderReturnService(repo domain.OrderReturnRepository, notify NotificationService, settings SettingService) OrderReturnService {
	return &orderReturnService{repo: repo, notify: notify, settings: settings}
}

func (s *orderReturnService) List(ctx context.Context, f domain.ReturnFilter) ([]domain.OrderReturn, int64, error) {
	return s.repo.List(ctx, f)
}

func (s *orderReturnService) Stats(ctx context.Context) (domain.ReturnStats, error) {
	return s.repo.Stats(ctx)
}

func (s *orderReturnService) GetByID(ctx context.Context, id uint) (*ReturnDetail, error) {
	rt, err := s.repo.FindByID(ctx, id)
	if err != nil {
		return nil, err
	}
	return s.detail(ctx, rt)
}

// ---------- Điều kiện được trả hàng ----------

// returnEligibility kiểm tra một đơn có nằm trong diện trả hàng không và trả về
// câu giải thích khi không.
//
// Chỉ mở từ lúc hàng đã tới tay khách: đơn chưa giao thì huỷ đơn mới là thao tác
// đúng, không phải trả hàng. Đơn đã hoàn hết cũng không còn gì để trả.
func (s *orderReturnService) returnEligibility(o *domain.Order, selfService bool) (bool, string) {
	switch o.Status {
	case domain.OrderStatusDelivered, domain.OrderStatusCompleted:
	case domain.OrderStatusReturned:
		return false, "Đơn hàng này đã được hoàn toàn bộ."
	default:
		return false, "Đơn hàng chưa được giao nên chưa thể trả hàng. Nếu muốn bỏ đơn, hãy dùng chức năng huỷ đơn."
	}

	// Hạn đổi trả chỉ áp cho luồng khách tự gửi yêu cầu. Nhân viên vẫn lập phiếu
	// được cho các trường hợp đã thoả thuận riêng qua điện thoại.
	if !selfService {
		return true, ""
	}
	if o.DeliveredAt != nil {
		deadline := o.DeliveredAt.AddDate(0, 0, returnWindowDays)
		if time.Now().After(deadline) {
			return false, "Đã quá hạn đổi trả " + strconv.Itoa(returnWindowDays) + " ngày kể từ ngày nhận hàng. Vui lòng gọi " + s.hotline() + " để được hỗ trợ."
		}
	}
	return true, ""
}

func (s *orderReturnService) Returnable(ctx context.Context, orderID uint) (*ReturnableOrder, error) {
	return s.returnable(ctx, orderID, false)
}

func (s *orderReturnService) MyReturnable(ctx context.Context, userID, orderID uint) (*ReturnableOrder, error) {
	if userID == 0 {
		return nil, domain.ErrForbidden
	}
	res, err := s.returnable(ctx, orderID, true)
	if err != nil {
		return nil, err
	}
	// Đơn của người khác trả ErrNotFound chứ không phải ErrForbidden: báo "không
	// có quyền" là đã xác nhận đơn tồn tại, cho phép dò đơn bằng cách thử ID.
	if res.Order.UserID == nil || *res.Order.UserID != userID {
		return nil, domain.ErrNotFound
	}
	res.Order.AdminNote = ""
	return res, nil
}

func (s *orderReturnService) returnable(ctx context.Context, orderID uint, selfService bool) (*ReturnableOrder, error) {
	o, items, err := s.repo.Returnable(ctx, orderID)
	if err != nil {
		return nil, err
	}

	ok, reason := s.returnEligibility(o, selfService)
	if ok {
		// Đơn hợp lệ nhưng mọi món đã nằm trong phiếu trả khác thì cũng không tạo
		// thêm được — nói thẳng ra thay vì để form mở với toàn ô số lượng bằng 0.
		remain := 0
		for _, it := range items {
			remain += it.RemainQuantity
		}
		if remain == 0 {
			ok = false
			reason = "Mọi sản phẩm của đơn đã nằm trong phiếu trả hàng khác."
		}
	}

	return &ReturnableOrder{Order: o, Items: items, Returnable: ok, Reason: reason}, nil
}

// ---------- Tạo phiếu ----------

// buildLines chụp lại thông tin sản phẩm từ đơn gốc cho các dòng client chọn và
// tính tiền hàng của phiếu.
//
// Giá lấy từ order_items chứ không nhận từ client: khách khai giá là khách tự
// quyết định mình được hoàn bao nhiêu.
func buildLines(items []dto.ReturnItemRequest, remain map[uint]domain.ReturnableItem) ([]domain.OrderReturnItem, float64, error) {
	// Gộp dòng trùng để kiểm tra theo TỔNG số lượng, tránh hai dòng cùng một món
	// đều lọt qua rồi trả vượt số đã mua.
	merged := make(map[uint]int, len(items))
	order := make([]uint, 0, len(items))
	for _, it := range items {
		if it.Quantity <= 0 {
			continue
		}
		if _, seen := merged[it.OrderItemID]; !seen {
			order = append(order, it.OrderItemID)
		}
		merged[it.OrderItemID] += it.Quantity
	}
	if len(order) == 0 {
		return nil, 0, domain.ErrReturnEmpty
	}

	lines := make([]domain.OrderReturnItem, 0, len(order))
	var amount float64
	for _, oid := range order {
		src, ok := remain[oid]
		if !ok {
			return nil, 0, domain.ErrReturnItemNotFound
		}
		qty := merged[oid]
		if qty > src.RemainQuantity {
			return nil, 0, domain.ErrReturnQtyExceeded
		}

		total := src.UnitPrice * float64(qty)
		amount += total
		lines = append(lines, domain.OrderReturnItem{
			OrderItemID:      src.OrderItemID,
			ProductID:        src.ProductID,
			ProductVariantID: src.ProductVariantID,
			ProductName:      src.ProductName,
			VariantSKU:       src.VariantSKU,
			Size:             src.Size,
			Color:            src.Color,
			Thumbnail:        src.Thumbnail,
			UnitPrice:        src.UnitPrice,
			Quantity:         qty,
			TotalPrice:       total,
		})
	}
	return lines, amount, nil
}

// refundTotal — tiền hàng cộng phí ship hoàn lại, trừ khấu trừ, không bao giờ âm.
func refundTotal(items, shipping, deduction float64) float64 {
	v := items + shipping - deduction
	if v < 0 {
		return 0
	}
	return v
}

func (s *orderReturnService) CreateMine(ctx context.Context, userID uint, req *dto.ReturnCreateRequest) (*ReturnDetail, error) {
	if userID == 0 {
		return nil, domain.ErrForbidden
	}

	rt, err := s.repo.Create(ctx, req.OrderID, func(o *domain.Order, remain map[uint]domain.ReturnableItem) (*domain.OrderReturn, error) {
		// Chủ sở hữu và điều kiện trả hàng đều được kiểm tra TRONG khoá dòng của
		// repository, nên không có kẽ hở giữa lúc đọc và lúc ghi.
		if o.UserID == nil || *o.UserID != userID {
			return nil, domain.ErrNotFound
		}
		if ok, _ := s.returnEligibility(o, true); !ok {
			return nil, domain.ErrReturnNotAllowed
		}

		lines, amount, err := buildLines(req.Items, remain)
		if err != nil {
			return nil, err
		}

		uid := userID
		method := req.RefundMethod
		if method == "" {
			method = "bank_transfer"
		}
		return &domain.OrderReturn{
			UserID:       &uid,
			Status:       domain.ReturnStatusPending,
			Reason:       req.Reason,
			ReasonNote:   strings.TrimSpace(req.ReasonNote),
			RequestedBy:  "customer",
			RefundMethod: method,
			BankAccount:  strings.TrimSpace(req.BankAccount),
			BankHolder:   strings.TrimSpace(req.BankHolder),
			BankName:     strings.TrimSpace(req.BankName),
			ItemsAmount:  amount,
			RefundAmount: amount,
			Restock:      true,
			Items:        lines,
		}, nil
	})
	if err != nil {
		return nil, err
	}

	// Yêu cầu trả hàng là việc nhân viên phải xử lý trong ngày — đẩy thẳng vào
	// chuông quản trị chứ không đợi ai mở trang.
	s.notifyAdmin(ctx, rt, "return_created",
		"Yêu cầu trả hàng "+rt.ReturnCode,
		returnSummary(rt)+" — lý do: "+returnReasonLabel(rt.Reason))
	s.notifyCustomer(ctx, rt, "return_status",
		"Đã gửi yêu cầu trả hàng "+rt.ReturnCode,
		"Cửa hàng sẽ xem xét và phản hồi trong vòng 24 giờ làm việc.")
	s.signal(ctx, rt)

	return s.detail(ctx, rt)
}

func (s *orderReturnService) CreateByAdmin(ctx context.Context, req *dto.ReturnAdminCreateRequest, actorID uint) (*ReturnDetail, error) {
	rt, err := s.repo.Create(ctx, req.OrderID, func(o *domain.Order, remain map[uint]domain.ReturnableItem) (*domain.OrderReturn, error) {
		if ok, _ := s.returnEligibility(o, false); !ok {
			return nil, domain.ErrReturnNotAllowed
		}

		lines, amount, err := buildLines(req.Items, remain)
		if err != nil {
			return nil, err
		}

		restock := true
		if req.Restock != nil {
			restock = *req.Restock
		}
		method := req.RefundMethod
		if method == "" {
			method = "bank_transfer"
		}

		rt := &domain.OrderReturn{
			UserID: o.UserID,
			// Nhân viên đang cầm hàng trên tay khi lập phiếu — không có gì để duyệt
			// nữa, phiếu vào thẳng "đã duyệt" và chỉ còn chờ nhập kho.
			Status:       domain.ReturnStatusApproved,
			Reason:       req.Reason,
			ReasonNote:   strings.TrimSpace(req.ReasonNote),
			RequestedBy:  "admin",
			RefundMethod: method,
			BankAccount:  strings.TrimSpace(req.BankAccount),
			BankHolder:   strings.TrimSpace(req.BankHolder),
			BankName:     strings.TrimSpace(req.BankName),
			ItemsAmount:  amount,
			ShippingFee:  req.ShippingFee,
			Deduction:    req.Deduction,
			RefundAmount: refundTotal(amount, req.ShippingFee, req.Deduction),
			Restock:      restock,
			AdminNote:    strings.TrimSpace(req.AdminNote),
			Items:        lines,
		}
		now := time.Now()
		rt.ApprovedAt = &now
		if actorID > 0 {
			id := actorID
			rt.HandledBy = &id
		}
		return rt, nil
	})
	if err != nil {
		return nil, err
	}

	// Không báo vào chuông quản trị: phiếu do chính nhân viên vừa lập.
	s.notifyCustomer(ctx, rt, "return_status",
		"Cửa hàng đã lập phiếu trả hàng "+rt.ReturnCode,
		"Phiếu trả hàng đã được ghi nhận, số tiền hoàn dự kiến "+formatVND(rt.RefundAmount)+".")
	s.signal(ctx, rt)

	return s.detail(ctx, rt)
}

// ---------- Luồng của khách ----------

func (s *orderReturnService) MyReturns(ctx context.Context, userID uint, f domain.ReturnFilter) ([]domain.OrderReturn, int64, error) {
	if userID == 0 {
		return nil, 0, domain.ErrForbidden
	}
	uid := userID
	f.UserID = &uid
	// Bộ lọc chỉ dành cho admin — khách chỉ được lọc theo trạng thái phiếu.
	f.Keyword = ""
	f.OrderID = nil

	list, total, err := s.repo.List(ctx, f)
	if err != nil {
		return nil, 0, err
	}
	for i := range list {
		hideInternal(&list[i])
	}
	return list, total, nil
}

func (s *orderReturnService) MyReturn(ctx context.Context, userID, id uint) (*ReturnDetail, error) {
	if userID == 0 {
		return nil, domain.ErrForbidden
	}
	rt, err := s.repo.FindByID(ctx, id)
	if err != nil {
		return nil, err
	}
	if rt.UserID == nil || *rt.UserID != userID {
		return nil, domain.ErrNotFound
	}
	hideInternal(rt)

	histories, err := s.repo.Histories(ctx, rt.ID)
	if err != nil {
		return nil, err
	}
	// Không trả NextStatuses: duyệt phiếu là việc của nhân viên. Thao tác duy nhất
	// của khách là rút yêu cầu.
	return &ReturnDetail{
		OrderReturn: rt,
		Histories:   histories,
		CanCancel:   rt.Status == domain.ReturnStatusPending,
	}, nil
}

func (s *orderReturnService) CancelMine(ctx context.Context, userID, id uint, reason string) (*ReturnDetail, error) {
	if userID == 0 {
		return nil, domain.ErrForbidden
	}

	rt, err := s.repo.LockAndUpdate(ctx, id, func(rt *domain.OrderReturn) (*domain.OrderReturnHistory, []string, *domain.ReturnEffects, error) {
		if rt.UserID == nil || *rt.UserID != userID {
			return nil, nil, nil, domain.ErrNotFound
		}
		if rt.Status == domain.ReturnStatusCancelled {
			return nil, nil, nil, domain.ErrConflict
		}
		// Cửa hàng đã duyệt là hàng có thể đang trên đường về kho — rút một chiều
		// từ web sẽ lệch với thực tế, phải qua nhân viên.
		if rt.Status != domain.ReturnStatusPending {
			return nil, nil, nil, domain.ErrInvalidStatus
		}

		from := rt.Status
		now := time.Now()
		note := strings.TrimSpace(reason)
		if note == "" {
			note = "Khách rút yêu cầu trả hàng"
		}

		rt.Status = domain.ReturnStatusCancelled
		rt.ClosedAt = &now

		return &domain.OrderReturnHistory{
				ReturnID:   rt.ID,
				FromStatus: from,
				ToStatus:   rt.Status,
				Note:       "Khách rút yêu cầu từ website: " + note,
				ChangedBy:  &userID,
			},
			[]string{"Status", "ClosedAt"},
			nil, nil
	})
	if err != nil {
		return nil, err
	}

	s.notifyAdmin(ctx, rt, "return_cancelled",
		"Khách rút yêu cầu trả hàng "+rt.ReturnCode,
		returnSummary(rt))
	s.signal(ctx, rt)

	hideInternal(rt)
	histories, err := s.repo.Histories(ctx, rt.ID)
	if err != nil {
		return nil, err
	}
	return &ReturnDetail{OrderReturn: rt, Histories: histories}, nil
}

// ---------- Luồng của nhân viên ----------

// hotlinePlaceholder — chỗ trống cho số hotline trong các câu chữ khai ở var cấp
// package. Cấu hình chỉ có lúc chạy, còn var được nạp trước đó, nên số thật được
// thay vào ngay trước khi gửi (xem fillHotline).
const hotlinePlaceholder = "{hotline}"

// hotline đọc số hỗ trợ từ cấu hình hệ thống.
func (s *orderReturnService) hotline() string {
	return settingText(s.settings, SettingContactPhone)
}

// fillHotline thay chỗ trống bằng số hotline đang cấu hình.
func (s *orderReturnService) fillHotline(text string) string {
	return strings.ReplaceAll(text, hotlinePlaceholder, s.hotline())
}

// returnStatusTexts — mỗi trạng thái báo cho khách bằng câu chữ gì.
// Cố ý không có "cancelled": khách tự rút thì chính khách vừa bấm, báo lại chỉ
// là nhiễu.
var returnStatusTexts = map[string]struct{ label, detail string }{
	domain.ReturnStatusApproved: {
		label:  "đã được duyệt",
		detail: "Cửa hàng đã duyệt yêu cầu trả hàng. Vui lòng đóng gói sản phẩm kèm hoá đơn và gửi về theo hướng dẫn, hoặc chờ nhân viên liên hệ hẹn lấy hàng.",
	},
	domain.ReturnStatusReceived: {
		label:  "đã nhận được hàng",
		detail: "Chúng tôi đã nhận và kiểm tra hàng trả. Tiền hoàn sẽ được chuyển trong 1-3 ngày làm việc.",
	},
	domain.ReturnStatusRefunded: {
		label:  "đã hoàn tiền",
		detail: "Cửa hàng đã hoàn tiền cho phiếu trả hàng này. Nếu sau 3 ngày làm việc chưa thấy tiền về, vui lòng liên hệ " + hotlinePlaceholder + ".",
	},
	domain.ReturnStatusRejected: {
		label:  "bị từ chối",
		detail: "Rất tiếc, yêu cầu trả hàng không được chấp nhận. Xem lý do trong chi tiết phiếu hoặc liên hệ " + hotlinePlaceholder + " để được giải thích thêm.",
	},
}

func (s *orderReturnService) UpdateStatus(ctx context.Context, id uint, req *dto.ReturnStatusRequest, actorID uint) (*ReturnDetail, error) {
	// Từ chối mà không nói lý do thì khách không biết phải làm gì tiếp — chặn ngay
	// ở đây thay vì để giao diện tự nhớ.
	if req.Status == domain.ReturnStatusRejected && strings.TrimSpace(req.Note) == "" {
		return nil, domain.ErrInvalidStatus
	}

	rt, err := s.repo.LockAndUpdate(ctx, id, func(rt *domain.OrderReturn) (*domain.OrderReturnHistory, []string, *domain.ReturnEffects, error) {
		from := rt.Status
		to := req.Status
		if from == to {
			return nil, nil, nil, domain.ErrConflict
		}
		if !canMoveReturnTo(from, to) {
			return nil, nil, nil, domain.ErrInvalidStatus
		}

		now := time.Now()
		rt.Status = to
		cols := []string{"Status"}
		if actorID > 0 {
			id := actorID
			rt.HandledBy = &id
			cols = append(cols, "HandledBy")
		}

		effects := &domain.ReturnEffects{ActorID: actorID}
		switch to {
		case domain.ReturnStatusApproved:
			rt.ApprovedAt = &now
			cols = append(cols, "ApprovedAt")
		case domain.ReturnStatusReceived:
			rt.ReceivedAt = &now
			cols = append(cols, "ReceivedAt")
			// Hàng đã về tay cửa hàng: nhập lại kho, trừ lượt bán, và nếu cả đơn đã
			// được trả hết thì khép đơn lại. Tất cả chạy trong CÙNG transaction với
			// việc đổi trạng thái để không có tình trạng nửa vời.
			effects.Restock = true
			effects.SettleOrder = true
		case domain.ReturnStatusRefunded:
			rt.RefundedAt = &now
			cols = append(cols, "RefundedAt")
			// Phiếu đổi hàng (không hoàn tiền) không có gì để ghi vào sổ thanh toán.
			effects.RecordRefund = rt.RefundAmount > 0
		case domain.ReturnStatusRejected:
			rt.ClosedAt = &now
			rt.RejectReason = strings.TrimSpace(req.Note)
			cols = append(cols, "ClosedAt", "RejectReason")
		case domain.ReturnStatusCancelled:
			rt.ClosedAt = &now
			cols = append(cols, "ClosedAt")
		}

		history := &domain.OrderReturnHistory{
			ReturnID:   rt.ID,
			FromStatus: from,
			ToStatus:   to,
			Note:       strings.TrimSpace(req.Note),
		}
		if actorID > 0 {
			history.ChangedBy = &actorID
		}
		return history, cols, effects, nil
	})
	if err != nil {
		return nil, err
	}

	if tpl, ok := returnStatusTexts[rt.Status]; ok {
		s.notifyCustomer(ctx, rt, "return_status",
			"Phiếu trả hàng "+rt.ReturnCode+" "+tpl.label, s.fillHotline(tpl.detail))
	}
	s.signal(ctx, rt)
	return s.detail(ctx, rt)
}

func (s *orderReturnService) Settle(ctx context.Context, id uint, req *dto.ReturnSettleRequest, actorID uint) (*ReturnDetail, error) {
	rt, err := s.repo.LockAndUpdate(ctx, id, func(rt *domain.OrderReturn) (*domain.OrderReturnHistory, []string, *domain.ReturnEffects, error) {
		// Phiếu đã hoàn tiền / bị từ chối / bị huỷ là sổ đã chốt, không sửa số nữa.
		switch rt.Status {
		case domain.ReturnStatusPending, domain.ReturnStatusApproved, domain.ReturnStatusReceived:
		default:
			return nil, nil, nil, domain.ErrInvalidStatus
		}

		rt.ShippingFee = req.ShippingFee
		rt.Deduction = req.Deduction
		// ItemsAmount là con số server tính từ đơn gốc — không nhận từ client.
		rt.RefundAmount = refundTotal(rt.ItemsAmount, req.ShippingFee, req.Deduction)
		cols := []string{"ShippingFee", "Deduction", "RefundAmount"}

		if req.RefundMethod != "" {
			rt.RefundMethod = req.RefundMethod
			rt.BankAccount = strings.TrimSpace(req.BankAccount)
			rt.BankHolder = strings.TrimSpace(req.BankHolder)
			rt.BankName = strings.TrimSpace(req.BankName)
			cols = append(cols, "RefundMethod", "BankAccount", "BankHolder", "BankName")
		}
		// Hàng đã nhập kho rồi thì cờ này không còn ý nghĩa gì để đổi.
		if req.Restock != nil && rt.Status != domain.ReturnStatusReceived {
			rt.Restock = *req.Restock
			cols = append(cols, "Restock")
		}
		if actorID > 0 {
			id := actorID
			rt.HandledBy = &id
			cols = append(cols, "HandledBy")
		}

		history := &domain.OrderReturnHistory{
			ReturnID:   rt.ID,
			FromStatus: rt.Status,
			ToStatus:   rt.Status,
			Note:       "Chốt số tiền hoàn: " + formatVND(rt.RefundAmount),
		}
		if actorID > 0 {
			history.ChangedBy = &actorID
		}
		return history, cols, nil, nil
	})
	if err != nil {
		return nil, err
	}
	s.signal(ctx, rt)
	return s.detail(ctx, rt)
}

func (s *orderReturnService) UpdateNote(ctx context.Context, id uint, note string) (*ReturnDetail, error) {
	rt, err := s.repo.LockAndUpdate(ctx, id, func(rt *domain.OrderReturn) (*domain.OrderReturnHistory, []string, *domain.ReturnEffects, error) {
		rt.AdminNote = strings.TrimSpace(note)
		return nil, []string{"AdminNote"}, nil, nil
	})
	if err != nil {
		return nil, err
	}
	return s.detail(ctx, rt)
}

// ---------- Helper ----------

func (s *orderReturnService) detail(ctx context.Context, rt *domain.OrderReturn) (*ReturnDetail, error) {
	histories, err := s.repo.Histories(ctx, rt.ID)
	if err != nil {
		return nil, err
	}
	return &ReturnDetail{
		OrderReturn:  rt,
		Histories:    histories,
		NextStatuses: NextReturnStatuses(rt.Status),
		CanCancel:    rt.Status == domain.ReturnStatusPending,
	}, nil
}

// NextReturnStatuses trả các trạng thái hợp lệ tiếp theo của một phiếu trả hàng.
func NextReturnStatuses(status string) []string {
	next, ok := returnFlow[status]
	if !ok {
		return []string{}
	}
	return next
}

func canMoveReturnTo(from, to string) bool {
	for _, s := range returnFlow[from] {
		if s == to {
			return true
		}
	}
	return false
}

// hideInternal xoá các trường chỉ dành cho nội bộ trước khi trả về cho khách.
func hideInternal(rt *domain.OrderReturn) {
	rt.AdminNote = ""
	rt.HandledBy = nil
	if rt.Order != nil {
		rt.Order.AdminNote = ""
	}
}

// returnReasonLabel đổi mã lý do thành câu chữ tiếng Việt cho thông báo/email.
func returnReasonLabel(code string) string {
	switch code {
	case domain.ReturnReasonDefective:
		return "Sản phẩm lỗi/hỏng"
	case domain.ReturnReasonWrongItem:
		return "Giao sai sản phẩm"
	case domain.ReturnReasonWrongSize:
		return "Sai size"
	case domain.ReturnReasonNotAsDescribed:
		return "Không giống mô tả"
	case domain.ReturnReasonChangedMind:
		return "Đổi ý, không có nhu cầu nữa"
	default:
		return "Lý do khác"
	}
}

// returnSummary là một dòng tóm tắt phiếu để đọc trong chuông thông báo.
func returnSummary(rt *domain.OrderReturn) string {
	var qty int
	for _, it := range rt.Items {
		qty += it.Quantity
	}
	s := ""
	if rt.Order != nil {
		s = "Đơn " + rt.Order.OrderCode + " · "
	}
	s += strconv.Itoa(qty) + " sản phẩm · hoàn " + formatVND(rt.RefundAmount)
	return s
}

// returnPayload — dữ liệu tối thiểu đủ để giao diện biết "phiếu nào, đang ra sao".
// Cố ý KHÔNG gửi cả phiếu: gói tin này tới mọi tab admin đang mở, không nên mang
// theo số tài khoản ngân hàng hay ghi chú nội bộ.
func returnPayload(rt *domain.OrderReturn) map[string]any {
	p := map[string]any{
		"return_id":     rt.ID,
		"return_code":   rt.ReturnCode,
		"order_id":      rt.OrderID,
		"status":        rt.Status,
		"refund_amount": rt.RefundAmount,
	}
	if rt.Order != nil {
		p["order_code"] = rt.Order.OrderCode
		p["recipient_name"] = rt.Order.RecipientName
	}
	return p
}

func (s *orderReturnService) notifyAdmin(ctx context.Context, rt *domain.OrderReturn, nType, title, content string) {
	if s.notify == nil {
		return
	}
	s.notify.Push(ctx, nil, nType, title, content, returnPayload(rt))
}

func (s *orderReturnService) notifyCustomer(ctx context.Context, rt *domain.OrderReturn, nType, title, content string) {
	if s.notify == nil || rt.UserID == nil {
		return
	}
	s.notify.Push(ctx, rt.UserID, nType, title, content, returnPayload(rt))
}

// signal bắn tín hiệu "phiếu này vừa đổi" để màn hình đang mở tự làm mới.
// Khác notify*: không lưu database, không hiện trong chuông.
func (s *orderReturnService) signal(ctx context.Context, rt *domain.OrderReturn) {
	if s.notify == nil {
		return
	}
	payload := returnPayload(rt)
	s.notify.Signal(ctx, realtime.TopicAdmin, realtime.EventReturn, payload)
	if rt.UserID != nil {
		s.notify.Signal(ctx, realtime.TopicUser(*rt.UserID), realtime.EventReturn, payload)
	}
}
