package repository

import (
	"context"
	"errors"
	"fmt"
	"slices"
	"time"

	"sass-api/internal/domain"

	"gorm.io/gorm"
	"gorm.io/gorm/clause"
)

type orderReturnRepository struct{ db *gorm.DB }

func NewOrderReturnRepository(db *gorm.DB) domain.OrderReturnRepository {
	return &orderReturnRepository{db: db}
}

// returnHoldsStatuses là các trạng thái phiếu vẫn đang "giữ chỗ" số lượng của
// dòng hàng: chừng nào phiếu chưa bị từ chối hoặc huỷ thì số hàng trong đó không
// được đem trả tiếp ở phiếu khác.
var returnHoldsStatuses = []string{
	domain.ReturnStatusPending,
	domain.ReturnStatusApproved,
	domain.ReturnStatusReceived,
	domain.ReturnStatusRefunded,
}

// returnedStatuses là các trạng thái mà hàng ĐÃ thực sự về kho — dùng để biết
// một đơn đã được trả hết hay chưa. Phiếu mới duyệt (approved) chưa tính vì hàng
// còn đang trên đường về.
var returnedStatuses = []string{
	domain.ReturnStatusReceived,
	domain.ReturnStatusRefunded,
}

// heldQuantities tổng hợp số lượng từng dòng hàng của đơn đang nằm trong các
// phiếu trả còn hiệu lực. excludeID > 0 thì bỏ qua chính phiếu đó (dùng khi sửa).
func heldQuantities(tx *gorm.DB, orderID uint, statuses []string, excludeID uint) (map[uint]int, error) {
	var rows []struct {
		OrderItemID uint
		Total       int
	}
	q := tx.Table("order_return_items AS ri").
		Joins("JOIN order_returns r ON r.id = ri.return_id").
		Select("ri.order_item_id AS order_item_id, COALESCE(SUM(ri.quantity), 0) AS total").
		Where("r.order_id = ? AND r.deleted_at IS NULL AND r.status IN ?", orderID, statuses).
		Group("ri.order_item_id")
	if excludeID > 0 {
		q = q.Where("r.id <> ?", excludeID)
	}
	if err := q.Scan(&rows).Error; err != nil {
		return nil, err
	}
	held := make(map[uint]int, len(rows))
	for _, row := range rows {
		held[row.OrderItemID] = row.Total
	}
	return held, nil
}

// returnableFrom ghép dòng hàng của đơn với số đang bị giữ để ra số CÒN trả được.
func returnableFrom(items []domain.OrderItem, held map[uint]int) []domain.ReturnableItem {
	out := make([]domain.ReturnableItem, 0, len(items))
	for _, it := range items {
		taken := held[it.ID]
		remain := it.Quantity - taken
		if remain < 0 {
			remain = 0
		}
		out = append(out, domain.ReturnableItem{
			OrderItemID:      it.ID,
			ProductID:        it.ProductID,
			ProductVariantID: it.ProductVariantID,
			ProductName:      it.ProductName,
			VariantSKU:       it.VariantSKU,
			Size:             it.Size,
			Color:            it.Color,
			Thumbnail:        it.Thumbnail,
			UnitPrice:        it.UnitPrice,
			Quantity:         it.Quantity,
			ReturnedQuantity: taken,
			RemainQuantity:   remain,
		})
	}
	return out
}

func (r *orderReturnRepository) Returnable(ctx context.Context, orderID uint) (*domain.Order, []domain.ReturnableItem, error) {
	db := r.db.WithContext(ctx)

	var o domain.Order
	err := db.Preload("Items", func(q *gorm.DB) *gorm.DB { return q.Order("id ASC") }).First(&o, orderID).Error
	if errors.Is(err, gorm.ErrRecordNotFound) {
		return nil, nil, domain.ErrNotFound
	}
	if err != nil {
		return nil, nil, err
	}

	held, err := heldQuantities(db, orderID, returnHoldsStatuses, 0)
	if err != nil {
		return nil, nil, err
	}
	return &o, returnableFrom(o.Items, held), nil
}

func (r *orderReturnRepository) List(ctx context.Context, f domain.ReturnFilter) ([]domain.OrderReturn, int64, error) {
	q := r.db.WithContext(ctx).Model(&domain.OrderReturn{})

	if f.Keyword != "" {
		kw := "%" + f.Keyword + "%"
		// Tìm cả theo dữ liệu của đơn gốc (mã đơn, người nhận) nên phải join sang
		// orders; bọc nhóm OR trong một Where để không nuốt điều kiện khác.
		q = q.Joins("JOIN orders o ON o.id = order_returns.order_id").
			Where("(order_returns.return_code LIKE ? OR o.order_code LIKE ? OR o.recipient_name LIKE ? OR o.recipient_phone LIKE ?)",
				kw, kw, kw, kw)
	}
	if f.Status != "" && f.Status != "all" {
		if list := splitCSV(f.Status); len(list) > 1 {
			q = q.Where("order_returns.status IN ?", list)
		} else {
			q = q.Where("order_returns.status = ?", f.Status)
		}
	}
	if f.Reason != "" && f.Reason != "all" {
		q = q.Where("order_returns.reason = ?", f.Reason)
	}
	if f.OrderID != nil {
		q = q.Where("order_returns.order_id = ?", *f.OrderID)
	}
	if f.UserID != nil {
		q = q.Where("order_returns.user_id = ?", *f.UserID)
	}
	if f.FromDate != "" {
		q = q.Where("order_returns.created_at >= ?", f.FromDate+" 00:00:00")
	}
	if f.ToDate != "" {
		q = q.Where("order_returns.created_at <= ?", f.ToDate+" 23:59:59")
	}

	var total int64
	if err := q.Count(&total).Error; err != nil {
		return nil, 0, err
	}

	switch f.Sort {
	case "oldest":
		q = q.Order("order_returns.id ASC")
	case "amount_desc":
		q = q.Order("order_returns.refund_amount DESC")
	case "amount_asc":
		q = q.Order("order_returns.refund_amount ASC")
	default:
		q = q.Order("order_returns.id DESC")
	}

	page := f.Page
	if page < 1 {
		page = 1
	}
	pageSize := f.PageSize
	if pageSize < 1 {
		pageSize = 20
	}
	q = q.Offset((page - 1) * pageSize).Limit(pageSize)

	var list []domain.OrderReturn
	// Preload Order để bảng danh sách hiện được mã đơn + người nhận mà không phải
	// gọi thêm một vòng request cho từng dòng.
	err := q.Preload("Items").Preload("Order").Find(&list).Error
	return list, total, err
}

func (r *orderReturnRepository) FindByID(ctx context.Context, id uint) (*domain.OrderReturn, error) {
	var rt domain.OrderReturn
	err := r.db.WithContext(ctx).
		Preload("Items", func(q *gorm.DB) *gorm.DB { return q.Order("id ASC") }).
		Preload("Order").
		Preload("Order.Items").
		First(&rt, id).Error
	if errors.Is(err, gorm.ErrRecordNotFound) {
		return nil, domain.ErrNotFound
	}
	return &rt, err
}

func (r *orderReturnRepository) Stats(ctx context.Context) (domain.ReturnStats, error) {
	var stats domain.ReturnStats

	var rows []struct {
		Status string
		Total  int64
	}
	err := r.db.WithContext(ctx).Model(&domain.OrderReturn{}).
		Select("status, COUNT(*) AS total").
		Group("status").
		Scan(&rows).Error
	if err != nil {
		return stats, err
	}
	for _, row := range rows {
		stats.Total += row.Total
		switch row.Status {
		case domain.ReturnStatusPending:
			stats.Pending = row.Total
		case domain.ReturnStatusApproved:
			stats.Approved = row.Total
		case domain.ReturnStatusReceived:
			stats.Received = row.Total
		case domain.ReturnStatusRefunded:
			stats.Refunded = row.Total
		case domain.ReturnStatusRejected:
			stats.Rejected = row.Total
		case domain.ReturnStatusCancelled:
			stats.Cancelled = row.Total
		}
	}

	// Chỉ cộng tiền của phiếu đã hoàn xong: phiếu đang chờ duyệt chưa chi đồng nào.
	var refunded struct{ Total float64 }
	err = r.db.WithContext(ctx).Model(&domain.OrderReturn{}).
		Select("COALESCE(SUM(refund_amount), 0) AS total").
		Where("status = ?", domain.ReturnStatusRefunded).
		Scan(&refunded).Error
	stats.RefundedAmount = refunded.Total

	return stats, err
}

func (r *orderReturnRepository) Histories(ctx context.Context, returnID uint) ([]domain.OrderReturnHistory, error) {
	var list []domain.OrderReturnHistory
	err := r.db.WithContext(ctx).
		Where("return_id = ?", returnID).
		Order("id ASC").
		Find(&list).Error
	return list, err
}

func (r *orderReturnRepository) CountActive(ctx context.Context, orderID uint) (int64, error) {
	var count int64
	err := r.db.WithContext(ctx).Model(&domain.OrderReturn{}).
		Where("order_id = ? AND status IN ?", orderID, returnHoldsStatuses).
		Count(&count).Error
	return count, err
}

// Create tạo phiếu trả hàng trong một transaction.
//
// Đơn được KHOÁ trước khi tính số còn trả được, nên hai yêu cầu gửi cùng lúc
// không thể cùng nhìn thấy "còn 1 cái" rồi cùng tạo phiếu — cái sau tính lại
// trên số đã bị cái trước chiếm.
//
// Mã phiếu dùng mã tạm để lấy ID (ràng buộc UNIQUE), sau đó đổi thành mã hiển
// thị theo ngày + ID, giống cách sinh mã đơn hàng.
func (r *orderReturnRepository) Create(
	ctx context.Context,
	orderID uint,
	build func(o *domain.Order, remain map[uint]domain.ReturnableItem) (*domain.OrderReturn, error),
) (*domain.OrderReturn, error) {
	var created *domain.OrderReturn

	err := r.db.WithContext(ctx).Transaction(func(tx *gorm.DB) error {
		var o domain.Order
		err := tx.Clauses(clause.Locking{Strength: "UPDATE"}).
			Preload("Items", func(q *gorm.DB) *gorm.DB { return q.Order("id ASC") }).
			First(&o, orderID).Error
		if errors.Is(err, gorm.ErrRecordNotFound) {
			return domain.ErrNotFound
		}
		if err != nil {
			return err
		}

		held, err := heldQuantities(tx, orderID, returnHoldsStatuses, 0)
		if err != nil {
			return err
		}
		remain := make(map[uint]domain.ReturnableItem, len(o.Items))
		for _, it := range returnableFrom(o.Items, held) {
			remain[it.OrderItemID] = it
		}

		rt, err := build(&o, remain)
		if rt != nil {
			// Phiếu trả thuộc về chi nhánh của ĐƠN GỐC, không phải chi nhánh người
			// đang duyệt: hàng quay về đúng kho đã xuất nó ra.
			rt.ShopID = o.ShopID
		}
		if err != nil {
			return err
		}

		items := rt.Items
		rt.Items = nil
		rt.OrderID = o.ID
		rt.ReturnCode = fmt.Sprintf("TMP%d", time.Now().UnixNano())
		if err := tx.Create(rt).Error; err != nil {
			return err
		}
		rt.ReturnCode = fmt.Sprintf("RT%s%04d", time.Now().Format("20060102"), rt.ID)
		if err := tx.Model(rt).Update("return_code", rt.ReturnCode).Error; err != nil {
			return err
		}

		for i := range items {
			items[i].ReturnID = rt.ID
		}
		if len(items) > 0 {
			if err := tx.Create(&items).Error; err != nil {
				return err
			}
		}
		rt.Items = items

		note := "Khách gửi yêu cầu trả hàng"
		if rt.RequestedBy == "admin" {
			note = "Nhân viên lập phiếu trả hàng"
		}
		history := &domain.OrderReturnHistory{
			ReturnID:   rt.ID,
			FromStatus: "",
			ToStatus:   rt.Status,
			Note:       note,
			ChangedBy:  rt.HandledBy,
		}
		if err := tx.Create(history).Error; err != nil {
			return err
		}

		rt.Order = &o
		created = rt
		return nil
	})

	return created, err
}

func (r *orderReturnRepository) LockAndUpdate(
	ctx context.Context,
	id uint,
	apply func(rt *domain.OrderReturn) (*domain.OrderReturnHistory, []string, *domain.ReturnEffects, error),
) (*domain.OrderReturn, error) {
	var result *domain.OrderReturn

	err := r.db.WithContext(ctx).Transaction(func(tx *gorm.DB) error {
		var rt domain.OrderReturn
		// Khoá dòng phiếu: hai nhân viên cùng bấm "đã nhận hàng" thì chỉ một lượt
		// đi qua được, nên kho không bao giờ bị nhập lại hai lần.
		err := tx.Clauses(clause.Locking{Strength: "UPDATE"}).
			Preload("Items", func(q *gorm.DB) *gorm.DB { return q.Order("id ASC") }).
			First(&rt, id).Error
		if errors.Is(err, gorm.ErrRecordNotFound) {
			return domain.ErrNotFound
		}
		if err != nil {
			return err
		}

		history, cols, effects, err := apply(&rt)
		if err != nil {
			return err
		}

		if len(cols) > 0 {
			if err := tx.Model(&rt).Select(cols).Updates(&rt).Error; err != nil {
				return err
			}
		}
		if history != nil {
			if err := tx.Create(history).Error; err != nil {
				return err
			}
		}

		if effects != nil {
			if effects.Restock {
				if err := restockReturn(tx, &rt); err != nil {
					return err
				}
			}
			if effects.SettleOrder {
				if err := settleOrderIfFullyReturned(tx, &rt, effects.ActorID); err != nil {
					return err
				}
			}
			if effects.RecordRefund {
				if err := recordRefund(tx, &rt); err != nil {
					return err
				}
			}
		}

		var order domain.Order
		if err := tx.Preload("Items").First(&order, rt.OrderID).Error; err == nil {
			rt.Order = &order
		}

		result = &rt
		return nil
	})

	return result, err
}

// restockReturn nhập lại kho đúng số lượng từng món của phiếu và trừ lượt bán
// tương ứng của sản phẩm.
//
// Bút toán ghi với reference_type='order_return' — TÁCH khỏi sổ 'order' của đơn
// hàng: sổ đơn là nguồn sự thật cho "đơn đang giữ bao nhiêu hàng của kho", nếu
// ghi chung thì luồng sửa/huỷ đơn sẽ tưởng đơn đã trả bớt hàng và tính sai phần
// chênh cần đồng bộ.
//
// Biến thể được khoá theo thứ tự ID tăng dần để không khoá chéo với luồng đơn hàng.
func restockReturn(tx *gorm.DB, rt *domain.OrderReturn) error {
	if !rt.Restock {
		// Hàng lỗi/hỏng: vẫn ghi nhận đã nhận về, nhưng không đưa lại lên kệ.
		return nil
	}

	qty := make(map[uint]int, len(rt.Items))
	for _, it := range rt.Items {
		if it.ProductVariantID == nil || it.Quantity <= 0 {
			continue
		}
		qty[*it.ProductVariantID] += it.Quantity
	}
	if len(qty) == 0 {
		return nil
	}

	ids := make([]uint, 0, len(qty))
	for vid := range qty {
		ids = append(ids, vid)
	}
	slices.Sort(ids)

	// Unscoped: biến thể có thể đã ngừng bán sau khi khách đặt. Hàng vẫn phải được
	// ghi nhận về kho, nếu không nhân viên sẽ không đóng được phiếu trả.
	var variants []domain.ProductVariant
	if err := tx.Unscoped().Clauses(clause.Locking{Strength: "UPDATE"}).
		Where("id IN ?", ids).Order("id ASC").Find(&variants).Error; err != nil {
		return err
	}

	found := make(map[uint]domain.ProductVariant, len(variants))
	for _, v := range variants {
		found[v.ID] = v
	}

	// Hàng quay về ĐÚNG KHO ĐÃ XUẤT NÓ RA, tức chi nhánh của phiếu trả (chép từ
	// đơn gốc lúc lập phiếu). Cộng vào kho của người đang duyệt thì hai chi nhánh
	// cùng lệch: nơi bán thiếu mãi, nơi duyệt thừa ra một món chưa từng nhập.
	shopID := rt.ShopID
	if shopID == 0 {
		var err error
		if shopID, err = chiNhanhCuaRequest(tx.Statement.Context, tx); err != nil {
			return err
		}
	}

	for _, vid := range ids {
		_, ok := found[vid]
		if !ok {
			// Biến thể đã bị xoá cứng: không còn chỗ nào để cộng tồn kho. Bỏ qua
			// dòng này thay vì chặn cả phiếu — hàng thực tế đã nằm trong kho rồi.
			continue
		}
		// choPhepAm = true: đây là lượt hàng VÀO (qty > 0) nên không chạm mốc 0,
		// nhưng kho đang âm sẵn từ dữ liệu cũ thì lượt trả hàng vẫn phải chạy —
		// chặn nó lại là giữ hàng thật ngoài sổ.
		before, after, err := ghiTonChiNhanh(tx, shopID, vid, qty[vid], true)
		if err != nil {
			return err
		}

		returnID := rt.ID
		if err := tx.Create(&domain.InventoryTransaction{
			ShopID:           shopID,
			ProductVariantID: vid,
			Type:             "return",
			Quantity:         qty[vid],
			QuantityBefore:   before,
			QuantityAfter:    after,
			ReferenceType:    "order_return",
			ReferenceID:      &returnID,
			Note:             "Nhận hàng trả " + rt.ReturnCode,
			CreatedBy:        rt.HandledBy,
		}).Error; err != nil {
			return err
		}
	}

	return decreaseSoldCount(tx, rt)
}

// decreaseSoldCount trừ products.sold_count đúng phần hàng khách trả lại.
//
// Chỉ trừ khi đơn đang ở nhóm trạng thái ĐƯỢC TÍNH là đã bán (đã giao / hoàn
// tất) — cũng chính là điều kiện để mở luồng trả hàng, nhưng vẫn kiểm tra lại ở
// đây để đơn đã bị chuyển sang "returned" bằng luồng khác không bị trừ hai lần.
func decreaseSoldCount(tx *gorm.DB, rt *domain.OrderReturn) error {
	var status string
	if err := tx.Table("orders").Select("status").Where("id = ?", rt.OrderID).Scan(&status).Error; err != nil {
		return err
	}
	if status != domain.OrderStatusDelivered && status != domain.OrderStatusCompleted {
		return nil
	}

	qty := make(map[uint]int, len(rt.Items))
	for _, it := range rt.Items {
		if it.ProductID == nil || it.Quantity <= 0 {
			continue
		}
		qty[*it.ProductID] += it.Quantity
	}
	ids := make([]uint, 0, len(qty))
	for id := range qty {
		ids = append(ids, id)
	}
	slices.Sort(ids)

	for _, id := range ids {
		// sold_count là UNSIGNED: chặn ở 0 để dữ liệu cũ (đơn giao trước khi có
		// tính năng đếm lượt bán) không làm tràn âm.
		if err := tx.Unscoped().Model(&domain.Product{}).
			Where("id = ?", id).
			Update("sold_count", gorm.Expr("GREATEST(CAST(sold_count AS SIGNED) - ?, 0)", qty[id])).Error; err != nil {
			return err
		}
	}
	return nil
}

// settleOrderIfFullyReturned chuyển đơn sang trạng thái "đã hoàn hàng" khi MỌI
// món của đơn đều đã được trả về kho.
//
// Đơn trả một phần vẫn giữ nguyên trạng thái cũ: nói cả đơn đã hoàn trong khi
// khách chỉ trả một áo là sai sổ sách và làm doanh thu tụt oan.
//
// Kho và lượt bán đã được phiếu trả xử lý theo từng món ở restockReturn, nên ở
// đây chỉ ghi trạng thái — cố ý KHÔNG đi qua luồng chuyển trạng thái của đơn
// hàng (nó sẽ trả TOÀN BỘ hàng của đơn về kho một lần nữa).
func settleOrderIfFullyReturned(tx *gorm.DB, rt *domain.OrderReturn, actorID uint) error {
	var o domain.Order
	if err := tx.Preload("Items").First(&o, rt.OrderID).Error; err != nil {
		return err
	}
	if o.Status != domain.OrderStatusDelivered && o.Status != domain.OrderStatusCompleted {
		return nil
	}

	returned, err := heldQuantities(tx, o.ID, returnedStatuses, 0)
	if err != nil {
		return err
	}
	for _, it := range o.Items {
		if returned[it.ID] < it.Quantity {
			return nil // còn món chưa trả hết -> đơn vẫn là đơn đã giao
		}
	}

	from := o.Status
	if err := tx.Model(&domain.Order{}).
		Where("id = ?", o.ID).
		Update("status", domain.OrderStatusReturned).Error; err != nil {
		return err
	}

	history := &domain.OrderStatusHistory{
		OrderID:    o.ID,
		FromStatus: from,
		ToStatus:   domain.OrderStatusReturned,
		Note:       "Toàn bộ đơn đã được trả qua phiếu " + rt.ReturnCode,
	}
	if actorID > 0 {
		history.ChangedBy = &actorID
	}
	return tx.Create(history).Error
}

// recordRefund ghi một dòng chi hoàn tiền vào bảng payments và đánh dấu đơn đã
// hoàn tiền khi tổng tiền hoàn đã bù đủ giá trị đơn.
//
// Ghi vào payments chứ không chỉ đổi orders.payment_status: kế toán cần biết
// hoàn bao nhiêu, hoàn lúc nào, theo phiếu nào — orders chỉ giữ được trạng thái
// cuối cùng.
func recordRefund(tx *gorm.DB, rt *domain.OrderReturn) error {
	var o domain.Order
	if err := tx.First(&o, rt.OrderID).Error; err != nil {
		return err
	}

	now := time.Now()
	// payments.provider là enum của các cổng THANH TOÁN, không có "tiền mặt" hay
	// "ví". Chuyển khoản khớp thẳng được, các cách hoàn còn lại ghi theo phương
	// thức khách đã trả tiền — mã phiếu và số tiền mới là thứ dùng để đối soát.
	provider := o.PaymentMethod
	if rt.RefundMethod == "bank_transfer" {
		provider = "bank_transfer"
	}

	if err := tx.Create(&domain.Payment{
		OrderID:         o.ID,
		TransactionCode: rt.ReturnCode,
		Provider:        provider,
		Amount:          rt.RefundAmount,
		Currency:        "VND",
		Status:          "refunded",
		PaidAt:          &now,
	}).Error; err != nil {
		return err
	}

	if o.PaymentStatus != "paid" {
		// Đơn COD chưa thu tiền thì không có gì để "hoàn" trên sổ thanh toán.
		return nil
	}

	var sum struct{ Total float64 }
	if err := tx.Model(&domain.OrderReturn{}).
		Select("COALESCE(SUM(refund_amount), 0) AS total").
		Where("order_id = ? AND status = ?", o.ID, domain.ReturnStatusRefunded).
		Scan(&sum).Error; err != nil {
		return err
	}
	// So sánh có biên 1₫ để sai số dấu phẩy động không giữ đơn ở "đã thanh toán"
	// dù thực tế đã hoàn đủ.
	if sum.Total+1 < o.TotalAmount {
		return nil
	}

	return tx.Model(&domain.Order{}).
		Where("id = ?", o.ID).
		Update("payment_status", "refunded").Error
}
