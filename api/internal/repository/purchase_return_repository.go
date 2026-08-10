package repository

import (
	"context"
	"errors"
	"fmt"
	"slices"
	"strings"
	"time"

	"gorm.io/gorm"
	"gorm.io/gorm/clause"

	"sass-api/internal/domain"
)

type purchaseReturnRepository struct{ db *gorm.DB }

func NewPurchaseReturnRepository(db *gorm.DB) domain.PurchaseReturnRepository {
	return &purchaseReturnRepository{db: db}
}

// purchaseReturnLiveStatuses là các trạng thái phiếu trả CÒN HIỆU LỰC — dùng khi
// tính số đã trả của một dòng phiếu đặt. Phiếu nháp cũng tính: nó đang "giữ chỗ"
// số hàng đó, nếu không hai phiếu nháp cùng đòi trả một món thì tới lúc trừ kho
// mới phát hiện thiếu.
var purchaseReturnLiveStatuses = []string{
	domain.PurchaseReturnStatusDraft,
	domain.PurchaseReturnStatusReturned,
	domain.PurchaseReturnStatusRefunded,
}

func applyPurchaseReturnFilter(q *gorm.DB, f domain.PurchaseReturnFilter) *gorm.DB {
	if kw := strings.TrimSpace(f.Keyword); kw != "" {
		like := "%" + kw + "%"
		q = q.Where(`(purchase_returns.return_code LIKE ? OR purchase_returns.po_code LIKE ?
			OR purchase_returns.supplier_name LIKE ? OR purchase_returns.note LIKE ?)`,
			like, like, like, like)
	}
	if f.Status != "" && f.Status != "all" {
		if list := splitCSV(f.Status); len(list) > 1 {
			q = q.Where("purchase_returns.status IN ?", list)
		} else {
			q = q.Where("purchase_returns.status = ?", f.Status)
		}
	}
	if f.RefundStatus != "" && f.RefundStatus != "all" {
		q = q.Where("purchase_returns.refund_status = ?", f.RefundStatus)
	}
	if f.Reason != "" && f.Reason != "all" {
		q = q.Where("purchase_returns.reason = ?", f.Reason)
	}
	if f.SupplierID != nil {
		q = q.Where("purchase_returns.supplier_id = ?", *f.SupplierID)
	}
	if f.FromDate != "" {
		q = q.Where("purchase_returns.created_at >= ?", f.FromDate+" 00:00:00")
	}
	if f.ToDate != "" {
		q = q.Where("purchase_returns.created_at <= ?", f.ToDate+" 23:59:59")
	}
	return q
}

func (r *purchaseReturnRepository) List(ctx context.Context, f domain.PurchaseReturnFilter) ([]domain.PurchaseReturn, int64, error) {
	q := applyPurchaseReturnFilter(r.db.WithContext(ctx).Model(&domain.PurchaseReturn{}), f)

	var total int64
	if err := q.Count(&total).Error; err != nil {
		return nil, 0, err
	}

	switch f.Sort {
	case "oldest":
		q = q.Order("purchase_returns.id ASC")
	case "amount_desc":
		q = q.Order("purchase_returns.items_amount DESC")
	case "amount_asc":
		q = q.Order("purchase_returns.items_amount ASC")
	default:
		q = q.Order("purchase_returns.id DESC")
	}

	page, pageSize := f.Page, f.PageSize
	if page < 1 {
		page = 1
	}
	if pageSize < 1 {
		pageSize = 20
	}

	var list []domain.PurchaseReturn
	err := q.Offset((page-1)*pageSize).Limit(pageSize).
		Preload("Items", func(q *gorm.DB) *gorm.DB { return q.Order("id ASC") }).
		Find(&list).Error
	return list, total, err
}

func (r *purchaseReturnRepository) FindByID(ctx context.Context, id uint) (*domain.PurchaseReturn, error) {
	var rt domain.PurchaseReturn
	err := r.db.WithContext(ctx).
		Preload("Items", func(q *gorm.DB) *gorm.DB { return q.Order("id ASC") }).
		First(&rt, id).Error
	if errors.Is(err, gorm.ErrRecordNotFound) {
		return nil, domain.ErrNotFound
	}
	return &rt, err
}

func (r *purchaseReturnRepository) Stats(ctx context.Context) (domain.PurchaseReturnStats, error) {
	var stats domain.PurchaseReturnStats

	var rows []struct {
		Status string
		Total  int64
	}
	err := r.db.WithContext(ctx).Model(&domain.PurchaseReturn{}).
		Select("status, COUNT(*) AS total").
		Group("status").
		Scan(&rows).Error
	if err != nil {
		return stats, err
	}
	for _, row := range rows {
		stats.Total += row.Total
		switch row.Status {
		case domain.PurchaseReturnStatusDraft:
			stats.Draft = row.Total
		case domain.PurchaseReturnStatusReturned:
			stats.Returned = row.Total
		case domain.PurchaseReturnStatusRefunded:
			stats.Refunded = row.Total
		case domain.PurchaseReturnStatusCancelled:
			stats.Cancelled = row.Total
		}
	}

	// Hàng đã trả thật + tiền NCC còn phải hoàn: chỉ tính phiếu đã trả (kể cả đã
	// hoàn tiền). Phiếu nháp chưa trả, phiếu huỷ thì không còn gì để đòi.
	done := []string{domain.PurchaseReturnStatusReturned, domain.PurchaseReturnStatusRefunded}
	var money struct {
		Amount  float64
		Pending float64
	}
	err = r.db.WithContext(ctx).Model(&domain.PurchaseReturn{}).
		Where("status IN ?", done).
		Select(`COALESCE(SUM(items_amount), 0) AS amount,
			COALESCE(SUM(GREATEST(items_amount - refund_amount, 0)), 0) AS pending`).
		Scan(&money).Error
	if err != nil {
		return stats, err
	}
	stats.ReturnedAmount = money.Amount
	stats.PendingRefund = money.Pending

	var qty struct{ Total int64 }
	err = r.db.WithContext(ctx).
		Table("purchase_return_items AS i").
		Joins("JOIN purchase_returns rt ON rt.id = i.purchase_return_id AND rt.deleted_at IS NULL").
		Where("rt.status IN ?", done).
		Select("COALESCE(SUM(i.quantity), 0) AS total").
		Scan(&qty).Error
	stats.ReturnedQuantity = qty.Total

	return stats, err
}

func (r *purchaseReturnRepository) Histories(ctx context.Context, id uint) ([]domain.PurchaseReturnHistory, error) {
	var list []domain.PurchaseReturnHistory
	err := r.db.WithContext(ctx).
		Where("purchase_return_id = ?", id).
		Order("id ASC").
		Find(&list).Error
	return list, err
}

// Returnable liệt kê các dòng của phiếu đặt còn trả lại được.
//
// Chỉ dòng ĐÃ NHẬN mới trả được (received_quantity > 0): hàng chưa về tay cửa
// hàng thì không có gì mà trả. Số đã nằm trong phiếu trả khác bị trừ ra để không
// trả trùng, và kèm tồn kho hiện tại vì lúc chốt phiếu kho sẽ bị trừ thật.
func (r *purchaseReturnRepository) Returnable(ctx context.Context, purchaseOrderID uint) ([]domain.PurchaseReturnable, error) {
	var rows []domain.PurchaseReturnable
	err := r.db.WithContext(ctx).
		Table("purchase_order_items AS i").
		Joins("JOIN purchase_orders po ON po.id = i.purchase_order_id AND po.deleted_at IS NULL").
		Joins("LEFT JOIN product_variants v ON v.id = i.product_variant_id").
		Where("i.purchase_order_id = ? AND i.received_quantity > 0", purchaseOrderID).
		Select(`i.id AS purchase_order_item_id, i.product_id, i.product_variant_id,
			i.product_name, COALESCE(i.variant_sku, '') AS variant_sku,
			COALESCE(i.size, '') AS size, COALESCE(i.color, '') AS color,
			COALESCE(i.thumbnail, '') AS thumbnail, i.unit_cost,
			i.received_quantity AS received,
			COALESCE((
				SELECT SUM(ri.quantity) FROM purchase_return_items ri
				JOIN purchase_returns rt ON rt.id = ri.purchase_return_id
					AND rt.deleted_at IS NULL AND rt.status IN (?)
				WHERE ri.purchase_order_item_id = i.id
			), 0) AS returned,
			COALESCE(v.stock_quantity, 0) AS stock`, purchaseReturnLiveStatuses).
		Order("i.id ASC").
		Scan(&rows).Error
	if err != nil {
		return nil, err
	}

	for i := range rows {
		rows[i].Remain = max(rows[i].Received-rows[i].Returned, 0)
	}
	return rows, nil
}

func (r *purchaseReturnRepository) Create(ctx context.Context, rt *domain.PurchaseReturn) error {
	return r.db.WithContext(ctx).Transaction(func(tx *gorm.DB) error {
		items := rt.Items
		rt.Items = nil
		// Mã phiếu cần ID nên phải ghi hai lượt; mã tạm giữ chỗ ràng buộc UNIQUE.
		rt.ReturnCode = fmt.Sprintf("TMP%d", time.Now().UnixNano())

		if err := tx.Create(rt).Error; err != nil {
			return err
		}
		rt.ReturnCode = fmt.Sprintf("PR%s%04d", time.Now().Format("20060102"), rt.ID)
		if err := tx.Model(rt).Update("return_code", rt.ReturnCode).Error; err != nil {
			return err
		}

		for i := range items {
			items[i].PurchaseReturnID = rt.ID
		}
		if len(items) > 0 {
			if err := tx.Create(&items).Error; err != nil {
				return err
			}
		}
		rt.Items = items

		note := "Tạo phiếu trả hàng nháp"
		if rt.Status == domain.PurchaseReturnStatusReturned {
			note = "Lập phiếu và trả hàng cho nhà cung cấp"
		}
		return tx.Create(&domain.PurchaseReturnHistory{
			PurchaseReturnID: rt.ID,
			FromStatus:       "",
			ToStatus:         rt.Status,
			Note:             note,
			ChangedBy:        rt.CreatedBy,
		}).Error
	})
}

// Update thay TOÀN BỘ dòng hàng thay vì đối chiếu từng dòng: phiếu chỉ sửa được
// khi còn nháp (điều kiện do tầng service kiểm trong mutate), lúc đó chưa có số
// liệu kho nào gắn vào dòng cũ để mà mất theo.
func (r *purchaseReturnRepository) Update(
	ctx context.Context,
	id uint,
	mutate func(rt *domain.PurchaseReturn) ([]string, []domain.PurchaseReturnItem, error),
) (*domain.PurchaseReturn, error) {
	var result *domain.PurchaseReturn

	err := r.db.WithContext(ctx).Transaction(func(tx *gorm.DB) error {
		var rt domain.PurchaseReturn
		err := tx.Clauses(clause.Locking{Strength: "UPDATE"}).
			Preload("Items", func(q *gorm.DB) *gorm.DB { return q.Order("id ASC") }).
			First(&rt, id).Error
		if errors.Is(err, gorm.ErrRecordNotFound) {
			return domain.ErrNotFound
		}
		if err != nil {
			return err
		}

		cols, items, err := mutate(&rt)
		if err != nil {
			return err
		}

		if len(cols) > 0 {
			if err := tx.Model(&rt).Select(cols).Updates(&rt).Error; err != nil {
				return err
			}
		}

		if items != nil {
			if err := tx.Unscoped().Where("purchase_return_id = ?", rt.ID).
				Delete(&domain.PurchaseReturnItem{}).Error; err != nil {
				return err
			}
			for i := range items {
				items[i].ID = 0
				items[i].PurchaseReturnID = rt.ID
			}
			if len(items) > 0 {
				if err := tx.Create(&items).Error; err != nil {
					return err
				}
			}
			rt.Items = items
		}

		result = &rt
		return nil
	})
	if err != nil {
		return nil, err
	}
	return result, nil
}

// MarkReturned chốt phiếu: TRỪ tồn kho và ghi bút toán trong một transaction.
func (r *purchaseReturnRepository) MarkReturned(ctx context.Context, id uint, actorID uint) (*domain.PurchaseReturn, error) {
	var result *domain.PurchaseReturn

	err := r.db.WithContext(ctx).Transaction(func(tx *gorm.DB) error {
		var rt domain.PurchaseReturn
		err := tx.Clauses(clause.Locking{Strength: "UPDATE"}).
			Preload("Items", func(q *gorm.DB) *gorm.DB { return q.Order("id ASC") }).
			First(&rt, id).Error
		if errors.Is(err, gorm.ErrRecordNotFound) {
			return domain.ErrNotFound
		}
		if err != nil {
			return err
		}

		// Chỉ phiếu nháp mới trừ kho được — chốt hai lần là trừ kho hai lần.
		if rt.Status != domain.PurchaseReturnStatusDraft {
			return domain.ErrPurchaseReturnLocked
		}
		if len(rt.Items) == 0 {
			return domain.ErrPurchaseReturnEmpty
		}

		// Gộp theo BIẾN THỂ: một biến thể có thể nằm ở nhiều dòng, kho chỉ nên trừ
		// đúng một lần cho mỗi biến thể.
		byVariant := make(map[uint]int, len(rt.Items))
		costOf := make(map[uint]float64, len(rt.Items))
		for _, it := range rt.Items {
			if it.ProductVariantID == nil || it.Quantity <= 0 {
				continue
			}
			byVariant[*it.ProductVariantID] += it.Quantity
			costOf[*it.ProductVariantID] = it.UnitCost
		}

		if err := returnOutOfStock(tx, &rt, byVariant, costOf, actorID); err != nil {
			return err
		}

		now := time.Now()
		from := rt.Status
		rt.Status = domain.PurchaseReturnStatusReturned
		rt.ReturnedAt = &now
		cols := []string{"Status", "ReturnedAt"}
		if actorID > 0 {
			actor := actorID
			rt.HandledBy = &actor
			cols = append(cols, "HandledBy")
		}
		if err := tx.Model(&rt).Select(cols).Updates(&rt).Error; err != nil {
			return err
		}

		history := &domain.PurchaseReturnHistory{
			PurchaseReturnID: rt.ID,
			FromStatus:       from,
			ToStatus:         rt.Status,
			Note:             "Đã trả hàng cho nhà cung cấp và trừ khỏi tồn kho",
		}
		if actorID > 0 {
			actor := actorID
			history.ChangedBy = &actor
		}
		if err := tx.Create(history).Error; err != nil {
			return err
		}

		result = &rt
		return nil
	})

	return result, err
}

// returnOutOfStock trừ tồn kho cho một phiếu trả và ghi bút toán sổ kho.
//
// Kho không đủ hàng thì trả ErrOutOfStock và KHÔNG dòng nào được ghi: trả hàng
// cho nhà cung cấp là một lần cân đối kho, trừ được nửa chừng còn tệ hơn báo lỗi.
func returnOutOfStock(
	tx *gorm.DB,
	rt *domain.PurchaseReturn,
	byVariant map[uint]int,
	costOf map[uint]float64,
	actorID uint,
) error {
	if len(byVariant) == 0 {
		return nil
	}

	ids := make([]uint, 0, len(byVariant))
	for vid := range byVariant {
		ids = append(ids, vid)
	}
	slices.Sort(ids)

	// Unscoped: biến thể có thể đã ngừng bán sau khi hàng về. Hàng trả lại NCC thì
	// vẫn phải trừ khỏi kho, nếu không tồn kho báo có mà thực tế không còn.
	var variants []domain.ProductVariant
	if err := tx.Unscoped().Clauses(clause.Locking{Strength: "UPDATE"}).
		Where("id IN ?", ids).Order("id ASC").Find(&variants).Error; err != nil {
		return err
	}
	found := make(map[uint]domain.ProductVariant, len(variants))
	for _, v := range variants {
		found[v.ID] = v
	}

	returnID := rt.ID
	actor := actorRef(actorID)

	for _, vid := range ids {
		v, ok := found[vid]
		if !ok {
			// Biến thể đã bị xoá cứng: không còn chỗ nào để trừ. Bỏ qua dòng này thay
			// vì chặn cả phiếu — hàng thực tế đã ra khỏi cửa hàng rồi.
			continue
		}

		before := v.StockQuantity
		after := before - byVariant[vid]
		if after < 0 {
			// Kho không được âm: sổ kho là nguồn sự thật cho hàng đang có thật.
			return domain.ErrOutOfStock
		}

		if err := tx.Unscoped().Model(&domain.ProductVariant{}).
			Where("id = ?", vid).
			Update("stock_quantity", after).Error; err != nil {
			return err
		}

		unitCost := costOf[vid]
		if err := tx.Create(&domain.InventoryTransaction{
			ProductVariantID: vid,
			Type:             "export",
			// Quantity là số THAY ĐỔI, âm khi hàng ra khỏi kho (cùng quy ước với
			// điều chỉnh kho và xuất bán).
			Quantity:       -byVariant[vid],
			QuantityBefore: before,
			QuantityAfter:  after,
			ReferenceType:  "purchase_return",
			ReferenceID:    &returnID,
			UnitCost:       &unitCost,
			Note:           "Trả hàng nhập theo phiếu " + rt.ReturnCode,
			CreatedBy:      actor,
		}).Error; err != nil {
			return err
		}
	}

	return nil
}

func (r *purchaseReturnRepository) LockAndUpdate(
	ctx context.Context,
	id uint,
	apply func(rt *domain.PurchaseReturn) (*domain.PurchaseReturnHistory, []string, error),
) (*domain.PurchaseReturn, error) {
	var result *domain.PurchaseReturn

	err := r.db.WithContext(ctx).Transaction(func(tx *gorm.DB) error {
		var rt domain.PurchaseReturn
		err := tx.Clauses(clause.Locking{Strength: "UPDATE"}).
			Preload("Items", func(q *gorm.DB) *gorm.DB { return q.Order("id ASC") }).
			First(&rt, id).Error
		if errors.Is(err, gorm.ErrRecordNotFound) {
			return domain.ErrNotFound
		}
		if err != nil {
			return err
		}

		history, cols, err := apply(&rt)
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

		result = &rt
		return nil
	})

	return result, err
}

func (r *purchaseReturnRepository) Delete(ctx context.Context, id uint) error {
	res := r.db.WithContext(ctx).Delete(&domain.PurchaseReturn{}, id)
	if res.Error != nil {
		return res.Error
	}
	if res.RowsAffected == 0 {
		return domain.ErrNotFound
	}
	return nil
}
