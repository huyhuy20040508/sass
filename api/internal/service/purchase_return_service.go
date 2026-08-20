package service

import (
	"context"
	"strings"
	"time"

	"sass-api/internal/domain"
	"sass-api/internal/dto"
)

// PurchaseReturnService — nghiệp vụ trả hàng lại NHÀ CUNG CẤP.
//
// Luật giữ ở đây (không ở giao diện):
//   - Chỉ trả được hàng ĐÃ NHẬN của một phiếu đặt, và không quá số còn trả được
//     (đã nhận trừ phần đã nằm trong các phiếu trả khác chưa huỷ).
//   - Tồn kho chỉ bị TRỪ đúng một lần, lúc chốt "đã trả NCC".
//   - Phiếu đã trả thì không sửa/huỷ/xoá: hàng đã ra khỏi kho, muốn nhận lại phải
//     lập phiếu đặt hàng nhập mới để có chứng từ.
type PurchaseReturnService interface {
	List(ctx context.Context, f domain.PurchaseReturnFilter) ([]domain.PurchaseReturn, int64, error)
	GetByID(ctx context.Context, id uint) (*PurchaseReturnDetail, error)
	Stats(ctx context.Context) (domain.PurchaseReturnStats, error)
	Returnable(ctx context.Context, purchaseOrderID uint) ([]domain.PurchaseReturnable, error)
	Create(ctx context.Context, req *dto.PurchaseReturnRequest, actorID uint) (*PurchaseReturnDetail, error)
	Update(ctx context.Context, id uint, req *dto.PurchaseReturnRequest, actorID uint) (*PurchaseReturnDetail, error)
	UpdateStatus(ctx context.Context, id uint, status, note string, actorID uint) (*PurchaseReturnDetail, error)
	UpdateRefund(ctx context.Context, id uint, amount float64, actorID uint) (*PurchaseReturnDetail, error)
	Delete(ctx context.Context, id uint) error
}

// PurchaseReturnDetail là phiếu trả kèm lịch sử và các bước kế tiếp — giao diện
// hiện đúng nút được phép bấm, không tự đoán luật.
type PurchaseReturnDetail struct {
	*domain.PurchaseReturn
	Histories    []domain.PurchaseReturnHistory `json:"histories"`
	NextStatuses []string                       `json:"next_statuses"`
	CanEdit      bool                           `json:"can_edit"`
	CanRefund    bool                           `json:"can_refund"`
}

type purchaseReturnService struct {
	repo         domain.PurchaseReturnRepository
	purchaseRepo domain.PurchaseOrderRepository
}

func NewPurchaseReturnService(
	repo domain.PurchaseReturnRepository,
	purchaseRepo domain.PurchaseOrderRepository,
) PurchaseReturnService {
	return &purchaseReturnService{repo: repo, purchaseRepo: purchaseRepo}
}

// NextPurchaseReturnStatuses trả các trạng thái kế tiếp hợp lệ.
//
// Nháp đi tiếp thành "đã trả NCC" hoặc bị huỷ. Đã trả thì chỉ còn chờ NCC hoàn
// tiền. Hai điểm cuối không đi đâu nữa.
func NextPurchaseReturnStatuses(status string) []string {
	switch status {
	case domain.PurchaseReturnStatusDraft:
		return []string{domain.PurchaseReturnStatusReturned, domain.PurchaseReturnStatusCancelled}
	case domain.PurchaseReturnStatusReturned:
		return []string{domain.PurchaseReturnStatusRefunded}
	default:
		return []string{}
	}
}

var purchaseReturnReasons = map[string]bool{
	domain.PurchaseReturnReasonDefect:    true,
	domain.PurchaseReturnReasonWrongItem: true,
	domain.PurchaseReturnReasonOverStock: true,
	domain.PurchaseReturnReasonExpired:   true,
	domain.PurchaseReturnReasonOther:     true,
}

func (s *purchaseReturnService) List(ctx context.Context, f domain.PurchaseReturnFilter) ([]domain.PurchaseReturn, int64, error) {
	return s.repo.List(ctx, f)
}

func (s *purchaseReturnService) GetByID(ctx context.Context, id uint) (*PurchaseReturnDetail, error) {
	rt, err := s.repo.FindByID(ctx, id)
	if err != nil {
		return nil, err
	}
	return s.detail(ctx, rt)
}

func (s *purchaseReturnService) Stats(ctx context.Context) (domain.PurchaseReturnStats, error) {
	return s.repo.Stats(ctx)
}

func (s *purchaseReturnService) Returnable(ctx context.Context, purchaseOrderID uint) ([]domain.PurchaseReturnable, error) {
	if _, err := s.purchaseRepo.FindByID(ctx, purchaseOrderID); err != nil {
		return nil, err
	}
	return s.repo.Returnable(ctx, purchaseOrderID)
}

func (s *purchaseReturnService) Create(ctx context.Context, req *dto.PurchaseReturnRequest, actorID uint) (*PurchaseReturnDetail, error) {
	po, err := s.purchaseRepo.FindByID(ctx, req.PurchaseOrderID)
	if err != nil {
		return nil, err
	}

	items, amount, err := s.buildLines(ctx, req, 0)
	if err != nil {
		return nil, err
	}

	status := domain.PurchaseReturnStatusDraft
	if req.Status == domain.PurchaseReturnStatusReturned {
		status = domain.PurchaseReturnStatusReturned
	}

	poID := po.ID
	rt := &domain.PurchaseReturn{
		PurchaseOrderID: &poID,
		POCode:          po.POCode,
		SupplierID:      po.SupplierID,
		SupplierName:    po.SupplierName,
		// Luôn tạo ở trạng thái NHÁP rồi mới chốt: việc trừ kho chỉ có một đường duy
		// nhất là MarkReturned, kể cả khi người dùng bấm "Trả hàng ngay".
		Status:       domain.PurchaseReturnStatusDraft,
		Reason:       normalizeReason(req.Reason),
		ItemsAmount:  amount,
		RefundStatus: domain.PurchaseRefundUnpaid,
		Note:         strings.TrimSpace(req.Note),
		Items:        items,
	}
	if actorID > 0 {
		actor := actorID
		rt.CreatedBy = &actor
	}

	if err := s.repo.Create(ctx, rt); err != nil {
		return nil, err
	}

	if status == domain.PurchaseReturnStatusReturned {
		returned, err := s.repo.MarkReturned(ctx, rt.ID, actorID)
		if err != nil {
			return nil, err
		}
		rt = returned
	}

	return s.detail(ctx, rt)
}

func (s *purchaseReturnService) Update(ctx context.Context, id uint, req *dto.PurchaseReturnRequest, actorID uint) (*PurchaseReturnDetail, error) {
	current, err := s.repo.FindByID(ctx, id)
	if err != nil {
		return nil, err
	}
	if current.Status != domain.PurchaseReturnStatusDraft {
		return nil, domain.ErrPurchaseReturnLocked
	}

	// Bỏ qua phần đang nằm ở CHÍNH phiếu này khi tính số còn trả được — nếu không
	// sửa phiếu giữ nguyên số lượng cũ cũng bị báo vượt.
	items, amount, err := s.buildLines(ctx, req, id)
	if err != nil {
		return nil, err
	}

	rt, err := s.repo.Update(ctx, id, func(rt *domain.PurchaseReturn) ([]string, []domain.PurchaseReturnItem, error) {
		if rt.Status != domain.PurchaseReturnStatusDraft {
			return nil, nil, domain.ErrPurchaseReturnLocked
		}
		rt.Reason = normalizeReason(req.Reason)
		rt.Note = strings.TrimSpace(req.Note)
		rt.ItemsAmount = amount
		cols := []string{"Reason", "Note", "ItemsAmount"}
		if actorID > 0 {
			actor := actorID
			rt.HandledBy = &actor
			cols = append(cols, "HandledBy")
		}
		return cols, items, nil
	})
	if err != nil {
		return nil, err
	}
	return s.detail(ctx, rt)
}

func (s *purchaseReturnService) UpdateStatus(ctx context.Context, id uint, status, note string, actorID uint) (*PurchaseReturnDetail, error) {
	current, err := s.repo.FindByID(ctx, id)
	if err != nil {
		return nil, err
	}
	if !containsString(NextPurchaseReturnStatuses(current.Status), status) {
		return nil, domain.ErrPurchaseReturnLocked
	}

	switch status {
	case domain.PurchaseReturnStatusReturned:
		rt, err := s.repo.MarkReturned(ctx, id, actorID)
		if err != nil {
			return nil, err
		}
		return s.detail(ctx, rt)

	case domain.PurchaseReturnStatusRefunded:
		// Đánh dấu NCC đã hoàn đủ: ghi luôn số hoàn bằng tiền hàng để sổ không lệch.
		return s.applyStatus(ctx, id, status, note, actorID, func(rt *domain.PurchaseReturn) []string {
			now := time.Now()
			rt.RefundAmount = rt.ItemsAmount
			rt.RefundStatus = domain.PurchaseRefundPaid
			rt.RefundedAt = &now
			return []string{"RefundAmount", "RefundStatus", "RefundedAt"}
		})

	case domain.PurchaseReturnStatusCancelled:
		if strings.TrimSpace(note) == "" {
			// Huỷ phiếu phải có lý do: phiếu huỷ vẫn nằm trong sổ, người sau đọc phải
			// biết vì sao huỷ.
			return nil, domain.ErrPurchaseReturnNoReason
		}
		return s.applyStatus(ctx, id, status, note, actorID, func(rt *domain.PurchaseReturn) []string {
			now := time.Now()
			rt.CancelReason = strings.TrimSpace(note)
			rt.CancelledAt = &now
			return []string{"CancelReason", "CancelledAt"}
		})
	}

	return nil, domain.ErrPurchaseReturnLocked
}

// UpdateRefund ghi số tiền nhà cung cấp đã hoàn (LUỸ KẾ, không phải số cộng thêm).
// Hoàn đủ thì phiếu tự sang "đã hoàn tiền" — không phải bấm thêm một bước nữa.
func (s *purchaseReturnService) UpdateRefund(ctx context.Context, id uint, amount float64, actorID uint) (*PurchaseReturnDetail, error) {
	if amount < 0 {
		return nil, domain.ErrPurchaseReturnLocked
	}

	rt, err := s.repo.LockAndUpdate(ctx, id, func(rt *domain.PurchaseReturn) (*domain.PurchaseReturnHistory, []string, error) {
		// Phiếu nháp chưa trả hàng nên chưa có gì để đòi; phiếu huỷ là sổ đã đóng.
		if rt.Status == domain.PurchaseReturnStatusDraft || rt.Status == domain.PurchaseReturnStatusCancelled {
			return nil, nil, domain.ErrPurchaseReturnLocked
		}

		before := rt.RefundAmount
		rt.RefundAmount = amount
		rt.RefundStatus = refundStatusOf(amount, rt.ItemsAmount)
		cols := []string{"RefundAmount", "RefundStatus"}

		if rt.RefundStatus == domain.PurchaseRefundPaid {
			now := time.Now()
			rt.RefundedAt = &now
			cols = append(cols, "RefundedAt")
			if rt.Status == domain.PurchaseReturnStatusReturned {
				rt.Status = domain.PurchaseReturnStatusRefunded
				cols = append(cols, "Status")
			}
		}
		if actorID > 0 {
			actor := actorID
			rt.HandledBy = &actor
			cols = append(cols, "HandledBy")
		}

		history := &domain.PurchaseReturnHistory{
			PurchaseReturnID: rt.ID,
			FromStatus:       rt.Status,
			ToStatus:         rt.Status,
			Note: "NCC hoàn tiền: " + formatVND(before) + " → " + formatVND(rt.RefundAmount) +
				" / " + formatVND(rt.ItemsAmount),
		}
		if actorID > 0 {
			actor := actorID
			history.ChangedBy = &actor
		}
		return history, cols, nil
	})
	if err != nil {
		return nil, err
	}
	return s.detail(ctx, rt)
}

// Delete chỉ xoá được phiếu NHÁP: phiếu đã trả hàng phải để lại trong sổ.
func (s *purchaseReturnService) Delete(ctx context.Context, id uint) error {
	rt, err := s.repo.FindByID(ctx, id)
	if err != nil {
		return err
	}
	if rt.Status != domain.PurchaseReturnStatusDraft {
		return domain.ErrPurchaseReturnLocked
	}
	return s.repo.Delete(ctx, id)
}

// ---------- Helper ----------

// buildLines đối chiếu các dòng client gửi lên với số CÒN TRẢ ĐƯỢC của phiếu đặt,
// rồi chụp lại tên/SKU/size/màu/giá nhập từ DB.
//
// excludeReturnID > 0 khi đang SỬA một phiếu: phần đang nằm ở chính phiếu đó
// không tính là "đã trả", nếu không sửa mà giữ nguyên số lượng cũng bị báo vượt.
func (s *purchaseReturnService) buildLines(
	ctx context.Context,
	req *dto.PurchaseReturnRequest,
	excludeReturnID uint,
) ([]domain.PurchaseReturnItem, float64, error) {
	returnable, err := s.repo.Returnable(ctx, req.PurchaseOrderID)
	if err != nil {
		return nil, 0, err
	}
	byItem := make(map[uint]domain.PurchaseReturnable, len(returnable))
	for _, r := range returnable {
		byItem[r.PurchaseOrderItemID] = r
	}

	// Phần đang nằm ở chính phiếu đang sửa.
	mine := make(map[uint]int)
	if excludeReturnID > 0 {
		current, err := s.repo.FindByID(ctx, excludeReturnID)
		if err != nil {
			return nil, 0, err
		}
		for _, it := range current.Items {
			if it.PurchaseOrderItemID != nil {
				mine[*it.PurchaseOrderItemID] += it.Quantity
			}
		}
	}

	// Gộp dòng trùng: cùng một dòng phiếu đặt chọn hai lần thì cộng số lượng, để
	// không có hai dòng cùng SKU và tổng vượt số còn trả được.
	merged := make(map[uint]int, len(req.Items))
	order := make([]uint, 0, len(req.Items))
	for _, it := range req.Items {
		if it.Quantity <= 0 {
			continue
		}
		if _, seen := merged[it.PurchaseOrderItemID]; !seen {
			order = append(order, it.PurchaseOrderItemID)
		}
		merged[it.PurchaseOrderItemID] += it.Quantity
	}
	if len(order) == 0 {
		return nil, 0, domain.ErrPurchaseReturnEmpty
	}

	items := make([]domain.PurchaseReturnItem, 0, len(order))
	var amount float64
	for _, itemID := range order {
		src, ok := byItem[itemID]
		if !ok {
			// Dòng không thuộc phiếu đặt này, hoặc chưa nhận hàng nên không trả được.
			return nil, 0, domain.ErrNotFound
		}
		qty := merged[itemID]
		if qty > src.Remain+mine[itemID] {
			return nil, 0, domain.ErrPurchaseReturnQtyExceeded
		}

		total := src.UnitCost * float64(qty)
		amount += total
		items = append(items, domain.PurchaseReturnItem{
			PurchaseOrderItemID: &itemID,
			ProductID:           src.ProductID,
			ProductVariantID:    src.ProductVariantID,
			ProductName:         src.ProductName,
			VariantSKU:          src.VariantSKU,
			VariantName:         src.VariantName,
			Thumbnail:           src.Thumbnail,
			Quantity:            qty,
			UnitCost:            src.UnitCost,
			TotalCost:           total,
		})
	}

	return items, amount, nil
}

func (s *purchaseReturnService) applyStatus(
	ctx context.Context,
	id uint,
	status, note string,
	actorID uint,
	extra func(rt *domain.PurchaseReturn) []string,
) (*PurchaseReturnDetail, error) {
	rt, err := s.repo.LockAndUpdate(ctx, id, func(rt *domain.PurchaseReturn) (*domain.PurchaseReturnHistory, []string, error) {
		if !containsString(NextPurchaseReturnStatuses(rt.Status), status) {
			return nil, nil, domain.ErrPurchaseReturnLocked
		}
		from := rt.Status
		rt.Status = status
		cols := append([]string{"Status"}, extra(rt)...)
		if actorID > 0 {
			actor := actorID
			rt.HandledBy = &actor
			cols = append(cols, "HandledBy")
		}

		history := &domain.PurchaseReturnHistory{
			PurchaseReturnID: rt.ID,
			FromStatus:       from,
			ToStatus:         status,
			Note:             strings.TrimSpace(note),
		}
		if actorID > 0 {
			actor := actorID
			history.ChangedBy = &actor
		}
		return history, cols, nil
	})
	if err != nil {
		return nil, err
	}
	return s.detail(ctx, rt)
}

func (s *purchaseReturnService) detail(ctx context.Context, rt *domain.PurchaseReturn) (*PurchaseReturnDetail, error) {
	histories, err := s.repo.Histories(ctx, rt.ID)
	if err != nil {
		return nil, err
	}
	return &PurchaseReturnDetail{
		PurchaseReturn: rt,
		Histories:      histories,
		NextStatuses:   NextPurchaseReturnStatuses(rt.Status),
		CanEdit:        rt.Status == domain.PurchaseReturnStatusDraft,
		// Ghi nhận hoàn tiền chỉ có nghĩa khi hàng đã trả thật và NCC còn nợ tiền.
		CanRefund: (rt.Status == domain.PurchaseReturnStatusReturned || rt.Status == domain.PurchaseReturnStatusRefunded) &&
			rt.ItemsAmount > 0,
	}, nil
}

func normalizeReason(reason string) string {
	reason = strings.TrimSpace(reason)
	if purchaseReturnReasons[reason] {
		return reason
	}
	return domain.PurchaseReturnReasonOther
}

// refundStatusOf suy tình trạng hoàn tiền từ số đã hoàn — cùng cách tính với
// payment_status của phiếu đặt hàng.
func refundStatusOf(refunded, total float64) string {
	switch {
	case refunded <= 0:
		return domain.PurchaseRefundUnpaid
	case refunded+0.01 < total:
		return domain.PurchaseRefundPartial
	default:
		return domain.PurchaseRefundPaid
	}
}

func containsString(list []string, want string) bool {
	for _, v := range list {
		if v == want {
			return true
		}
	}
	return false
}
