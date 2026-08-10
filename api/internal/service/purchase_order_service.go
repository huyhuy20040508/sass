package service

import (
	"context"
	"strconv"
	"strings"
	"time"

	"sass-api/internal/domain"
	"sass-api/internal/dto"
)

// purchaseFlow khai báo các trạng thái được phép chuyển tới BẰNG TAY từ mỗi
// trạng thái của phiếu đặt hàng nhập.
//
//	nháp ──đặt hàng──> đã đặt ──(nhận hàng)──> nhận một phần ──(nhận nốt)──> đã nhận đủ
//	  └──────────────── huỷ ─────────────────────────┘
//
// Cố ý KHÔNG có đường tay nào tới "received": đánh dấu đã nhận mà không đi qua
// luồng nhận hàng là phiếu đóng xong mà kho không có hàng. Hai trạng thái
// "partial"/"received" chỉ do repository đặt sau khi đã cộng tồn kho thật.
var purchaseFlow = map[string][]string{
	domain.PurchaseStatusDraft:     {domain.PurchaseStatusOrdered, domain.PurchaseStatusCancelled},
	domain.PurchaseStatusOrdered:   {domain.PurchaseStatusCancelled},
	domain.PurchaseStatusPartial:   {domain.PurchaseStatusCancelled},
	domain.PurchaseStatusReceived:  {},
	domain.PurchaseStatusCancelled: {},
}

// PurchaseDetail là phiếu đặt hàng kèm lịch sử và các thao tác hợp lệ kế tiếp.
type PurchaseDetail struct {
	*domain.PurchaseOrder
	Histories []domain.PurchaseOrderHistory `json:"histories"`
	// NextStatuses là các trạng thái hợp lệ kế tiếp — admin dựng menu thao tác từ
	// đây thay vì chép lại luật vào giao diện rồi lệch với server.
	NextStatuses []string `json:"next_statuses"`
	// CanEdit = true khi phiếu còn sửa được (chưa nhận đợt hàng nào).
	CanEdit bool `json:"can_edit"`
	// CanReceive = true khi phiếu còn hàng để nhận.
	CanReceive bool `json:"can_receive"`
}

type PurchaseOrderService interface {
	List(ctx context.Context, f domain.PurchaseFilter) ([]domain.PurchaseOrder, int64, error)
	GetByID(ctx context.Context, id uint) (*PurchaseDetail, error)
	Stats(ctx context.Context) (domain.PurchaseStats, error)
	// SearchVariants tìm biến thể để đưa vào phiếu (dropdown chọn hàng).
	SearchVariants(ctx context.Context, keyword string, limit int) ([]domain.PurchaseVariant, error)

	Create(ctx context.Context, req *dto.PurchaseCreateRequest, actorID uint) (*PurchaseDetail, error)
	Update(ctx context.Context, id uint, req *dto.PurchaseUpdateRequest, actorID uint) (*PurchaseDetail, error)
	UpdateStatus(ctx context.Context, id uint, req *dto.PurchaseStatusRequest, actorID uint) (*PurchaseDetail, error)
	// Receive ghi nhận một đợt hàng về kho.
	Receive(ctx context.Context, id uint, req *dto.PurchaseReceiveRequest, actorID uint) (*PurchaseDetail, error)
	UpdatePayment(ctx context.Context, id uint, req *dto.PurchasePaymentRequest, actorID uint) (*PurchaseDetail, error)
	Delete(ctx context.Context, id uint) error
}

type purchaseOrderService struct {
	repo     domain.PurchaseOrderRepository
	supplier domain.SupplierRepository
}

func NewPurchaseOrderService(repo domain.PurchaseOrderRepository, supplier domain.SupplierRepository) PurchaseOrderService {
	return &purchaseOrderService{repo: repo, supplier: supplier}
}

func (s *purchaseOrderService) List(ctx context.Context, f domain.PurchaseFilter) ([]domain.PurchaseOrder, int64, error) {
	return s.repo.List(ctx, f)
}

func (s *purchaseOrderService) Stats(ctx context.Context) (domain.PurchaseStats, error) {
	return s.repo.Stats(ctx)
}

func (s *purchaseOrderService) SearchVariants(ctx context.Context, keyword string, limit int) ([]domain.PurchaseVariant, error) {
	return s.repo.SearchVariants(ctx, keyword, limit)
}

func (s *purchaseOrderService) GetByID(ctx context.Context, id uint) (*PurchaseDetail, error) {
	po, err := s.repo.FindByID(ctx, id)
	if err != nil {
		return nil, err
	}
	return s.detail(ctx, po)
}

// ---------- Dựng dòng hàng ----------

// buildPurchaseLines chụp lại thông tin sản phẩm từ biến thể cho các dòng client
// gửi lên và tính tiền hàng của phiếu.
//
// Tên/SKU/size/màu lấy từ DB chứ không nhận từ client: phiếu in ra và bút toán
// kho phải nói về đúng một món hàng. Giá nhập thì ngược lại — do người mua thoả
// thuận với nhà cung cấp nên nhận từ client, mặc định gợi ý theo giá vốn đang khai.
func buildPurchaseLines(items []dto.PurchaseItemRequest, found map[uint]domain.PurchaseVariant) ([]domain.PurchaseOrderItem, float64, error) {
	// Gộp dòng trùng: cùng một biến thể chọn hai lần thì cộng số lượng, giá lấy
	// theo lần khai sau — để phiếu không có hai dòng cùng SKU với hai giá khác nhau.
	merged := make(map[uint]dto.PurchaseItemRequest, len(items))
	order := make([]uint, 0, len(items))
	for _, it := range items {
		if it.Quantity <= 0 {
			continue
		}
		prev, seen := merged[it.VariantID]
		if !seen {
			order = append(order, it.VariantID)
			merged[it.VariantID] = it
			continue
		}
		prev.Quantity += it.Quantity
		prev.UnitCost = it.UnitCost
		merged[it.VariantID] = prev
	}
	if len(order) == 0 {
		return nil, 0, domain.ErrPurchaseEmpty
	}

	lines := make([]domain.PurchaseOrderItem, 0, len(order))
	var amount float64
	for _, vid := range order {
		src, ok := found[vid]
		if !ok {
			return nil, 0, domain.ErrVariantNotFound
		}
		req := merged[vid]

		total := req.UnitCost * float64(req.Quantity)
		amount += total

		productID := src.ProductID
		variantID := src.VariantID
		lines = append(lines, domain.PurchaseOrderItem{
			ProductID:        &productID,
			ProductVariantID: &variantID,
			ProductName:      src.ProductName,
			VariantSKU:       src.SKU,
			Size:             src.Size,
			Color:            src.Color,
			Thumbnail:        src.Thumbnail,
			UnitCost:         req.UnitCost,
			Quantity:         req.Quantity,
			TotalCost:        total,
		})
	}
	return lines, amount, nil
}

// purchaseTotal — tiền hàng trừ chiết khấu cộng cước vận chuyển, không bao giờ âm.
func purchaseTotal(items, discount, shipping float64) float64 {
	v := items - discount + shipping
	if v < 0 {
		return 0
	}
	return v
}

// paymentStatusOf suy tình trạng thanh toán từ số đã trả.
//
// So sánh có biên 1₫ để sai số dấu phẩy động không giữ phiếu ở "trả một phần"
// dù thực tế đã trả đủ.
func paymentStatusOf(paid, total float64) string {
	switch {
	case paid <= 0:
		return domain.PurchasePaymentUnpaid
	case paid+1 >= total:
		return domain.PurchasePaymentPaid
	default:
		return domain.PurchasePaymentPartial
	}
}

// parseExpected đọc ngày hẹn giao dạng YYYY-MM-DD; rỗng = chưa hẹn ngày.
func parseExpected(v string) *time.Time {
	v = strings.TrimSpace(v)
	if v == "" {
		return nil
	}
	d, err := time.Parse("2006-01-02", v)
	if err != nil {
		return nil
	}
	return &d
}

// lookupLines tra biến thể của mọi dòng trong một lần truy vấn rồi dựng dòng phiếu.
func (s *purchaseOrderService) lookupLines(ctx context.Context, items []dto.PurchaseItemRequest) ([]domain.PurchaseOrderItem, float64, error) {
	ids := make([]uint, 0, len(items))
	for _, it := range items {
		ids = append(ids, it.VariantID)
	}
	found, err := s.repo.LookupVariants(ctx, ids)
	if err != nil {
		return nil, 0, err
	}
	return buildPurchaseLines(items, found)
}

// ---------- Tạo & sửa ----------

func (s *purchaseOrderService) Create(ctx context.Context, req *dto.PurchaseCreateRequest, actorID uint) (*PurchaseDetail, error) {
	sup, err := s.supplier.FindByID(ctx, req.SupplierID)
	if err != nil {
		return nil, err
	}

	lines, amount, err := s.lookupLines(ctx, req.Items)
	if err != nil {
		return nil, err
	}

	status := req.Status
	if status == "" {
		status = domain.PurchaseStatusDraft
	}

	supplierID := sup.ID
	po := &domain.PurchaseOrder{
		SupplierID:     &supplierID,
		SupplierName:   sup.Name,
		Status:         status,
		ExpectedDate:   parseExpected(req.ExpectedDate),
		ItemsAmount:    amount,
		DiscountAmount: req.DiscountAmount,
		ShippingFee:    req.ShippingFee,
		TotalAmount:    purchaseTotal(amount, req.DiscountAmount, req.ShippingFee),
		PaymentStatus:  domain.PurchasePaymentUnpaid,
		Note:           strings.TrimSpace(req.Note),
		Items:          lines,
	}
	if status == domain.PurchaseStatusOrdered {
		now := time.Now()
		po.OrderedAt = &now
	}
	if actorID > 0 {
		id := actorID
		po.CreatedBy = &id
		po.HandledBy = &id
	}

	if err := s.repo.Create(ctx, po); err != nil {
		return nil, err
	}

	created, err := s.repo.FindByID(ctx, po.ID)
	if err != nil {
		return nil, err
	}
	return s.detail(ctx, created)
}

func (s *purchaseOrderService) Update(ctx context.Context, id uint, req *dto.PurchaseUpdateRequest, actorID uint) (*PurchaseDetail, error) {
	sup, err := s.supplier.FindByID(ctx, req.SupplierID)
	if err != nil {
		return nil, err
	}

	lines, amount, err := s.lookupLines(ctx, req.Items)
	if err != nil {
		return nil, err
	}

	po, err := s.repo.Update(ctx, id, func(po *domain.PurchaseOrder) ([]string, []domain.PurchaseOrderItem, error) {
		// Điều kiện sửa được kiểm tra TRONG khoá dòng: nếu chỉ kiểm tra trước khi
		// gọi thì một đợt nhận hàng chen vào giữa sẽ bị danh sách mới xoá mất.
		if !canEditPurchase(po) {
			return nil, nil, domain.ErrPurchaseLocked
		}

		supplierID := sup.ID
		po.SupplierID = &supplierID
		po.SupplierName = sup.Name
		po.ExpectedDate = parseExpected(req.ExpectedDate)
		po.ItemsAmount = amount
		po.DiscountAmount = req.DiscountAmount
		po.ShippingFee = req.ShippingFee
		po.TotalAmount = purchaseTotal(amount, req.DiscountAmount, req.ShippingFee)
		po.PaymentStatus = paymentStatusOf(po.PaidAmount, po.TotalAmount)
		po.Note = strings.TrimSpace(req.Note)

		cols := []string{"SupplierID", "SupplierName", "ExpectedDate", "ItemsAmount",
			"DiscountAmount", "ShippingFee", "TotalAmount", "PaymentStatus", "Note"}
		if actorID > 0 {
			actor := actorID
			po.HandledBy = &actor
			cols = append(cols, "HandledBy")
		}
		return cols, lines, nil
	})
	if err != nil {
		return nil, err
	}

	updated, err := s.repo.FindByID(ctx, po.ID)
	if err != nil {
		return nil, err
	}
	return s.detail(ctx, updated)
}

// canEditPurchase — phiếu còn sửa được khi chưa nhận đợt hàng nào.
//
// Đã có hàng về là các con số của phiếu đã được đối chiếu với bút toán kho; sửa
// tiếp thì sổ kho nói một đằng, phiếu nói một nẻo.
func canEditPurchase(po *domain.PurchaseOrder) bool {
	if po.Status != domain.PurchaseStatusDraft && po.Status != domain.PurchaseStatusOrdered {
		return false
	}
	for _, it := range po.Items {
		if it.ReceivedQuantity > 0 {
			return false
		}
	}
	return true
}

// ---------- Chuyển trạng thái ----------

func (s *purchaseOrderService) UpdateStatus(ctx context.Context, id uint, req *dto.PurchaseStatusRequest, actorID uint) (*PurchaseDetail, error) {
	// Huỷ mà không nói lý do thì vài tuần sau không ai biết vì sao phiếu chết —
	// chặn ngay ở đây thay vì để giao diện tự nhớ.
	if req.Status == domain.PurchaseStatusCancelled && strings.TrimSpace(req.Note) == "" {
		return nil, domain.ErrInvalidStatus
	}

	po, err := s.repo.LockAndUpdate(ctx, id, func(po *domain.PurchaseOrder) (*domain.PurchaseOrderHistory, []string, error) {
		from := po.Status
		to := req.Status
		if from == to {
			return nil, nil, domain.ErrConflict
		}
		if !canMovePurchaseTo(from, to) {
			return nil, nil, domain.ErrInvalidStatus
		}

		now := time.Now()
		po.Status = to
		cols := []string{"Status"}
		if actorID > 0 {
			actor := actorID
			po.HandledBy = &actor
			cols = append(cols, "HandledBy")
		}

		switch to {
		case domain.PurchaseStatusOrdered:
			po.OrderedAt = &now
			cols = append(cols, "OrderedAt")
		case domain.PurchaseStatusCancelled:
			po.CancelledAt = &now
			po.CancelReason = strings.TrimSpace(req.Note)
			cols = append(cols, "CancelledAt", "CancelReason")
		}

		note := strings.TrimSpace(req.Note)
		if note == "" && to == domain.PurchaseStatusOrdered {
			note = "Đã gửi phiếu đặt hàng cho nhà cung cấp"
		}
		history := &domain.PurchaseOrderHistory{
			PurchaseOrderID: po.ID,
			FromStatus:      from,
			ToStatus:        to,
			Note:            note,
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
	return s.detail(ctx, po)
}

// ---------- Nhận hàng ----------

func (s *purchaseOrderService) Receive(ctx context.Context, id uint, req *dto.PurchaseReceiveRequest, actorID uint) (*PurchaseDetail, error) {
	lines := make([]domain.PurchaseReceiptLine, 0, len(req.Items))
	for _, it := range req.Items {
		if it.Quantity <= 0 {
			// Form nhận hàng gửi lên MỌI dòng của phiếu; dòng đợt này chưa về có số 0.
			continue
		}
		lines = append(lines, domain.PurchaseReceiptLine{ItemID: it.ItemID, Quantity: it.Quantity})
	}
	if len(lines) == 0 {
		return nil, domain.ErrPurchaseNothingToReceive
	}

	po, err := s.repo.Receive(ctx, id, domain.PurchaseReceipt{
		Lines:      lines,
		UpdateCost: req.UpdateCost == nil || *req.UpdateCost,
		Note:       req.Note,
		ActorID:    actorID,
	})
	if err != nil {
		return nil, err
	}

	received, err := s.repo.FindByID(ctx, po.ID)
	if err != nil {
		return nil, err
	}
	return s.detail(ctx, received)
}

// ---------- Thanh toán cho nhà cung cấp ----------

func (s *purchaseOrderService) UpdatePayment(ctx context.Context, id uint, req *dto.PurchasePaymentRequest, actorID uint) (*PurchaseDetail, error) {
	po, err := s.repo.LockAndUpdate(ctx, id, func(po *domain.PurchaseOrder) (*domain.PurchaseOrderHistory, []string, error) {
		// Phiếu nháp chưa đặt thật nên chưa nợ ai; phiếu đã huỷ là sổ đã đóng.
		if po.Status == domain.PurchaseStatusDraft || po.Status == domain.PurchaseStatusCancelled {
			return nil, nil, domain.ErrPurchaseLocked
		}

		before := po.PaidAmount
		// PaidAmount là số LUỸ KẾ đã trả, không phải số cộng thêm — nhân viên nhìn
		// thấy tổng đã trả trên màn hình và sửa đúng con số đó.
		po.PaidAmount = req.PaidAmount
		po.PaymentStatus = paymentStatusOf(po.PaidAmount, po.TotalAmount)
		cols := []string{"PaidAmount", "PaymentStatus"}
		if actorID > 0 {
			actor := actorID
			po.HandledBy = &actor
			cols = append(cols, "HandledBy")
		}

		history := &domain.PurchaseOrderHistory{
			PurchaseOrderID: po.ID,
			FromStatus:      po.Status,
			ToStatus:        po.Status,
			Note: "Cập nhật thanh toán: " + formatVND(before) + " → " + formatVND(po.PaidAmount) +
				" / " + formatVND(po.TotalAmount),
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
	return s.detail(ctx, po)
}

// ---------- Xoá ----------

// Delete chỉ xoá được phiếu NHÁP: phiếu đã gửi nhà cung cấp phải huỷ (có lý do,
// còn trong lịch sử) chứ không biến mất khỏi sổ.
func (s *purchaseOrderService) Delete(ctx context.Context, id uint) error {
	po, err := s.repo.FindByID(ctx, id)
	if err != nil {
		return err
	}
	if po.Status != domain.PurchaseStatusDraft {
		return domain.ErrPurchaseLocked
	}
	return s.repo.Delete(ctx, id)
}

// ---------- Helper ----------

func (s *purchaseOrderService) detail(ctx context.Context, po *domain.PurchaseOrder) (*PurchaseDetail, error) {
	histories, err := s.repo.Histories(ctx, po.ID)
	if err != nil {
		return nil, err
	}
	return &PurchaseDetail{
		PurchaseOrder: po,
		Histories:     histories,
		NextStatuses:  NextPurchaseStatuses(po.Status),
		CanEdit:       canEditPurchase(po),
		CanReceive:    canReceivePurchase(po),
	}, nil
}

// NextPurchaseStatuses trả các trạng thái hợp lệ tiếp theo của một phiếu đặt hàng.
func NextPurchaseStatuses(status string) []string {
	next, ok := purchaseFlow[status]
	if !ok {
		return []string{}
	}
	return next
}

func canMovePurchaseTo(from, to string) bool {
	for _, s := range purchaseFlow[from] {
		if s == to {
			return true
		}
	}
	return false
}

// canReceivePurchase — phiếu còn hàng để nhận khi đã gửi nhà cung cấp và vẫn còn
// dòng thiếu số.
func canReceivePurchase(po *domain.PurchaseOrder) bool {
	if po.Status != domain.PurchaseStatusOrdered && po.Status != domain.PurchaseStatusPartial {
		return false
	}
	for _, it := range po.Items {
		if it.ReceivedQuantity < it.Quantity {
			return true
		}
	}
	return false
}

// PurchaseSummary là một dòng tóm tắt phiếu để đọc trong thông báo/nhật ký.
func PurchaseSummary(po *domain.PurchaseOrder) string {
	var qty int
	for _, it := range po.Items {
		qty += it.Quantity
	}
	return po.SupplierName + " · " + strconv.Itoa(qty) + " sản phẩm · " + formatVND(po.TotalAmount)
}
