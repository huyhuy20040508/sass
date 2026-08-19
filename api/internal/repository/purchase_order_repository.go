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

type purchaseOrderRepository struct{ db *gorm.DB }

func NewPurchaseOrderRepository(db *gorm.DB) domain.PurchaseOrderRepository {
	return &purchaseOrderRepository{db: db}
}

// purchaseOpenStatuses là các trạng thái phiếu vẫn còn hiệu lực: hàng chưa về
// hết và tiền còn có thể phải trả. Dùng cho số liệu "hàng đang trên đường" và
// công nợ — phiếu huỷ không còn nợ ai, phiếu nháp thì chưa đặt thật.
var purchaseOpenStatuses = []string{
	domain.PurchaseStatusOrdered,
	domain.PurchaseStatusPartial,
}

func applyPurchaseFilter(q *gorm.DB, f domain.PurchaseFilter) *gorm.DB {
	if kw := strings.TrimSpace(f.Keyword); kw != "" {
		like := "%" + kw + "%"
		q = q.Where("(purchase_orders.po_code LIKE ? OR purchase_orders.supplier_name LIKE ? OR purchase_orders.note LIKE ?)",
			like, like, like)
	}
	if f.Status != "" && f.Status != "all" {
		if list := splitCSV(f.Status); len(list) > 1 {
			q = q.Where("purchase_orders.status IN ?", list)
		} else {
			q = q.Where("purchase_orders.status = ?", f.Status)
		}
	}
	if f.PaymentStatus != "" && f.PaymentStatus != "all" {
		q = q.Where("purchase_orders.payment_status = ?", f.PaymentStatus)
	}
	if f.SupplierID != nil {
		q = q.Where("purchase_orders.supplier_id = ?", *f.SupplierID)
	}
	if f.FromDate != "" {
		q = q.Where("purchase_orders.created_at >= ?", f.FromDate+" 00:00:00")
	}
	if f.ToDate != "" {
		q = q.Where("purchase_orders.created_at <= ?", f.ToDate+" 23:59:59")
	}
	return q
}

func (r *purchaseOrderRepository) List(ctx context.Context, f domain.PurchaseFilter) ([]domain.PurchaseOrder, int64, error) {
	q := applyPurchaseFilter(r.db.WithContext(ctx).Model(&domain.PurchaseOrder{}), f)

	var total int64
	if err := q.Count(&total).Error; err != nil {
		return nil, 0, err
	}

	switch f.Sort {
	case "oldest":
		q = q.Order("purchase_orders.id ASC")
	case "total_desc":
		q = q.Order("purchase_orders.total_amount DESC")
	case "total_asc":
		q = q.Order("purchase_orders.total_amount ASC")
	case "expected_asc":
		// Hàng hẹn về sớm nhất lên đầu; phiếu chưa hẹn ngày đẩy xuống cuối thay vì
		// nằm trên đầu bảng như MySQL vẫn xếp NULL.
		q = q.Order("purchase_orders.expected_date IS NULL ASC, purchase_orders.expected_date ASC, purchase_orders.id DESC")
	default:
		q = q.Order("purchase_orders.id DESC")
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

	var list []domain.PurchaseOrder
	err := q.Preload("Items", func(q *gorm.DB) *gorm.DB { return q.Order("id ASC") }).Find(&list).Error
	return list, total, err
}

func (r *purchaseOrderRepository) FindByID(ctx context.Context, id uint) (*domain.PurchaseOrder, error) {
	var po domain.PurchaseOrder
	err := r.db.WithContext(ctx).
		Preload("Items", func(q *gorm.DB) *gorm.DB { return q.Order("id ASC") }).
		Preload("Supplier").
		First(&po, id).Error
	if errors.Is(err, gorm.ErrRecordNotFound) {
		return nil, domain.ErrNotFound
	}
	return &po, err
}

func (r *purchaseOrderRepository) Stats(ctx context.Context) (domain.PurchaseStats, error) {
	var stats domain.PurchaseStats

	var rows []struct {
		Status string
		Total  int64
	}
	err := r.db.WithContext(ctx).Model(&domain.PurchaseOrder{}).
		Select("status, COUNT(*) AS total").
		Group("status").
		Scan(&rows).Error
	if err != nil {
		return stats, err
	}
	for _, row := range rows {
		stats.Total += row.Total
		switch row.Status {
		case domain.PurchaseStatusDraft:
			stats.Draft = row.Total
		case domain.PurchaseStatusOrdered:
			stats.Ordered = row.Total
		case domain.PurchaseStatusPartial:
			stats.Partial = row.Total
		case domain.PurchaseStatusReceived:
			stats.Received = row.Total
		case domain.PurchaseStatusCancelled:
			stats.Cancelled = row.Total
		}
	}

	// Hàng đang trên đường = phần CÒN THIẾU của các phiếu đang chờ, không phải cả
	// số đặt: phiếu nhận một phần thì chỗ đã về đã nằm trong tồn kho rồi.
	var incoming struct {
		Quantity int64
		Value    float64
	}
	err = r.db.WithContext(ctx).
		Table("purchase_order_items AS i").
		Joins("JOIN purchase_orders po ON po.id = i.purchase_order_id AND po.deleted_at IS NULL").
		Where("po.status IN ?", purchaseOpenStatuses).
		Select(`COALESCE(SUM(GREATEST(i.quantity - i.received_quantity, 0)), 0) AS quantity,
			COALESCE(SUM(GREATEST(i.quantity - i.received_quantity, 0) * i.unit_cost), 0) AS value`).
		Scan(&incoming).Error
	if err != nil {
		return stats, err
	}
	stats.IncomingQuantity = incoming.Quantity
	stats.IncomingValue = incoming.Value

	// Công nợ: phần chưa trả của mọi phiếu đã đặt (kể cả phiếu đã nhận đủ mà chưa
	// thanh toán xong). Phiếu nháp chưa đặt thật, phiếu huỷ không còn nợ ai.
	var debt struct{ Total float64 }
	err = r.db.WithContext(ctx).Model(&domain.PurchaseOrder{}).
		Select("COALESCE(SUM(GREATEST(total_amount - paid_amount, 0)), 0) AS total").
		Where("status NOT IN ?", []string{domain.PurchaseStatusDraft, domain.PurchaseStatusCancelled}).
		Scan(&debt).Error
	stats.DebtAmount = debt.Total

	return stats, err
}

func (r *purchaseOrderRepository) Histories(ctx context.Context, purchaseID uint) ([]domain.PurchaseOrderHistory, error) {
	var list []domain.PurchaseOrderHistory
	err := r.db.WithContext(ctx).
		Where("purchase_order_id = ?", purchaseID).
		Order("id ASC").
		Find(&list).Error
	return list, err
}

// purchaseVariantSelect là các cột dùng chung khi tra biến thể để đưa vào phiếu.
// Giá gợi ý là giá VỐN hiệu lực (biến thể ghi đè sản phẩm cha) chứ không phải giá
// bán — đây là chiều mua vào.
//
// `ton` truyền vào chứ không ghi cứng `v.stock_quantity`: cột "còn" trên màn hình
// lập phiếu phải là tồn CỦA KHO SẼ NHẬN HÀNG VỀ, xem tonPhieuNhap.
func purchaseVariantSelect(ton string) string {
	return `v.id AS variant_id, v.product_id, p.name AS product_name,
	v.sku, v.size, v.color,
	COALESCE(NULLIF(v.image, ''), NULLIF(p.thumbnail, ''), '') AS thumbnail,
	` + effectiveCostExpr + ` AS cost_price,
	` + ton + ` AS stock`
}

// tonPhieuNhap trả về mệnh đề JOIN và biểu thức tồn của ĐÚNG chi nhánh mà phiếu
// nhập sẽ nhận hàng về.
//
// Chi nhánh chọn bằng chính hàm mà Create dùng để đóng dấu `purchase_orders.
// shop_id` — người lập phiếu thấy "kho này còn 3 cái" thì lượt nhận hàng cũng
// cộng vào đúng kho ấy. Đọc bản cộng của cả cửa hàng ở đây là mời người ta đặt
// hàng theo con số của một kho khác.
//
// Trả về MẢNH GHÉP chứ không dựng sẵn cả truy vấn: hai đường gọi khác nhau ở chỗ
// có loại sản phẩm đã xoá mềm hay không (xem LookupVariants), và gói cả JOIN
// products vào đây thì một trong hai phải đi gỡ nó ra.
func (r *purchaseOrderRepository) tonPhieuNhap(ctx context.Context) (joinTon, ton string, err error) {
	shopID, err := chiNhanhCuaRequest(ctx, r.db)
	if err != nil {
		return "", "", err
	}

	// LEFT JOIN: biến thể chưa từng có hàng ở kho này vẫn phải tra ra được — đó
	// chính là món người ta đang định đặt về.
	//
	// %d của một uint đã qua chiNhanhCuaRequest (tra sổ trong đúng cửa hàng của
	// ctx) không sinh ra được ký tự nào ngoài chữ số — cùng lý do như bên
	// inventoryRepository.baseQuery.
	return fmt.Sprintf(
		"LEFT JOIN variant_stocks vs ON vs.product_variant_id = v.id AND vs.shop_id = %d", shopID,
	), tonTheoChiNhanh, nil
}

func (r *purchaseOrderRepository) SearchVariants(ctx context.Context, keyword string, limit int) ([]domain.PurchaseVariant, error) {
	if limit < 1 {
		limit = 20
	}

	joinTon, ton, err := r.tonPhieuNhap(ctx)
	if err != nil {
		return nil, err
	}

	// Chỉ hàng còn bán được mới nhập thêm: sản phẩm/biến thể đã ẩn mà vẫn đặt hàng
	// về là nhập kho một món không ai bán được.
	q := r.db.WithContext(ctx).
		Table("product_variants AS v").
		Joins("JOIN products p ON p.id = v.product_id AND p.deleted_at IS NULL").
		Joins(joinTon).
		Where("v.deleted_at IS NULL AND v.is_active = 1 AND p.is_active = 1")

	if kw := strings.TrimSpace(keyword); kw != "" {
		like := "%" + kw + "%"
		q = q.Where("(p.name LIKE ? OR v.sku LIKE ? OR p.sku LIKE ?)", like, like, like)
	}

	out := make([]domain.PurchaseVariant, 0, limit)
	// Hàng sắp hết lên đầu: đó chính là thứ người lập phiếu đang cần đặt thêm.
	//
	// Sắp theo CÙNG biểu thức tồn đang hiển thị. Sắp theo bản cộng của cả cửa hàng
	// trong khi cột "còn" in tồn của một kho thì danh sách đọc lên như bị xáo bậy:
	// dòng ghi 0 nằm dưới dòng ghi 12.
	err = q.Select(purchaseVariantSelect(ton)).
		Order(ton + " ASC, p.name ASC, v.id ASC").
		Limit(limit).
		Scan(&out).Error
	return out, err
}

// LookupVariants tra thông tin biến thể theo ID để chụp snapshot vào phiếu.
//
// Không lọc is_active: biến thể có thể bị ẩn sau khi phiếu đã lập, lúc đó vẫn
// phải đọc/sửa được phiếu cũ. Việc chặn đặt hàng cho hàng đã ẩn nằm ở
// SearchVariants (lúc chọn), không nằm ở đây.
func (r *purchaseOrderRepository) LookupVariants(ctx context.Context, ids []uint) (map[uint]domain.PurchaseVariant, error) {
	out := make(map[uint]domain.PurchaseVariant, len(ids))
	if len(ids) == 0 {
		return out, nil
	}

	joinTon, ton, err := r.tonPhieuNhap(ctx)
	if err != nil {
		return nil, err
	}

	var rows []domain.PurchaseVariant
	// JOIN products KHÔNG kèm `deleted_at IS NULL` như bên SearchVariants: sản
	// phẩm có thể bị xoá mềm sau khi phiếu đã lập, mà phiếu cũ thì vẫn phải mở ra
	// đọc được.
	err = r.db.WithContext(ctx).
		Table("product_variants AS v").
		Joins("JOIN products p ON p.id = v.product_id").
		Joins(joinTon).
		Where("v.id IN ?", ids).
		Select(purchaseVariantSelect(ton)).
		Scan(&rows).Error
	if err != nil {
		return nil, err
	}
	for _, row := range rows {
		out[row.VariantID] = row
	}
	return out, nil
}

// Create tạo phiếu đặt hàng trong một transaction.
//
// Mã phiếu dùng mã tạm để lấy ID (ràng buộc UNIQUE), sau đó đổi thành mã hiển
// thị theo ngày + ID — cùng cách sinh mã với đơn hàng và phiếu trả hàng.
//
// KHÔNG đụng tới tồn kho: hàng mới chỉ được đặt, chưa về tới kho.
func (r *purchaseOrderRepository) Create(ctx context.Context, po *domain.PurchaseOrder) error {
	// Chi nhánh ĐẶT hàng — cũng là kho hàng sẽ về khi nhận (xem nơi cộng tồn).
	shopID, err := chiNhanhCuaRequest(ctx, r.db)
	if err != nil {
		return err
	}
	po.ShopID = shopID

	return r.db.WithContext(ctx).Transaction(func(tx *gorm.DB) error {
		items := po.Items
		po.Items = nil
		po.POCode = fmt.Sprintf("TMP%d", time.Now().UnixNano())

		if err := tx.Create(po).Error; err != nil {
			return err
		}
		ma, err := maChungTu(ctx, tx, domain.LoaiPhieuDatMua, po.ShopID, &domain.PurchaseOrder{}, "po_code",
			fmt.Sprintf("PO%s%04d", time.Now().Format("20060102"), po.ID))
		if err != nil {
			return err
		}
		po.POCode = ma
		if err := tx.Model(po).Update("po_code", po.POCode).Error; err != nil {
			return err
		}

		for i := range items {
			items[i].PurchaseOrderID = po.ID
		}
		if len(items) > 0 {
			if err := tx.Create(&items).Error; err != nil {
				return err
			}
		}
		po.Items = items

		note := "Tạo phiếu nháp"
		if po.Status == domain.PurchaseStatusOrdered {
			note = "Lập và gửi phiếu đặt hàng cho nhà cung cấp"
		}
		return tx.Create(&domain.PurchaseOrderHistory{
			PurchaseOrderID: po.ID,
			FromStatus:      "",
			ToStatus:        po.Status,
			Note:            note,
			ChangedBy:       po.CreatedBy,
		}).Error
	})
}

// Update sửa phiếu + THAY TOÀN BỘ danh sách hàng dưới khoá dòng.
//
// Thay hết dòng cũ (xoá cứng rồi tạo lại) thay vì đối chiếu từng dòng: phiếu chỉ
// sửa được khi CHƯA nhận đợt hàng nào (điều kiện do tầng service kiểm tra trong
// mutate), nên không có số đã nhận nào bị mất theo.
func (r *purchaseOrderRepository) Update(
	ctx context.Context,
	id uint,
	mutate func(po *domain.PurchaseOrder) ([]string, []domain.PurchaseOrderItem, error),
) (*domain.PurchaseOrder, error) {
	var result *domain.PurchaseOrder

	err := r.db.WithContext(ctx).Transaction(func(tx *gorm.DB) error {
		var po domain.PurchaseOrder
		err := tx.Clauses(clause.Locking{Strength: "UPDATE"}).
			Preload("Items", func(q *gorm.DB) *gorm.DB { return q.Order("id ASC") }).
			First(&po, id).Error
		if errors.Is(err, gorm.ErrRecordNotFound) {
			return domain.ErrNotFound
		}
		if err != nil {
			return err
		}

		cols, items, err := mutate(&po)
		if err != nil {
			return err
		}

		if len(cols) > 0 {
			if err := tx.Model(&po).Select(cols).Updates(&po).Error; err != nil {
				return err
			}
		}

		if err := tx.Where("purchase_order_id = ?", po.ID).Delete(&domain.PurchaseOrderItem{}).Error; err != nil {
			return err
		}
		for i := range items {
			items[i].ID = 0
			items[i].PurchaseOrderID = po.ID
		}
		if len(items) > 0 {
			if err := tx.Create(&items).Error; err != nil {
				return err
			}
		}
		po.Items = items

		result = &po
		return nil
	})

	return result, err
}

func (r *purchaseOrderRepository) LockAndUpdate(
	ctx context.Context,
	id uint,
	apply func(po *domain.PurchaseOrder) (*domain.PurchaseOrderHistory, []string, error),
) (*domain.PurchaseOrder, error) {
	var result *domain.PurchaseOrder

	err := r.db.WithContext(ctx).Transaction(func(tx *gorm.DB) error {
		var po domain.PurchaseOrder
		// Khoá dòng phiếu: hai nhân viên cùng bấm "đặt hàng"/"huỷ" thì chỉ một lượt
		// đi qua được, lịch sử phiếu không có hai mốc trùng nhau.
		err := tx.Clauses(clause.Locking{Strength: "UPDATE"}).
			Preload("Items", func(q *gorm.DB) *gorm.DB { return q.Order("id ASC") }).
			First(&po, id).Error
		if errors.Is(err, gorm.ErrRecordNotFound) {
			return domain.ErrNotFound
		}
		if err != nil {
			return err
		}

		history, cols, err := apply(&po)
		if err != nil {
			return err
		}

		if len(cols) > 0 {
			if err := tx.Model(&po).Select(cols).Updates(&po).Error; err != nil {
				return err
			}
		}
		if history != nil {
			if err := tx.Create(history).Error; err != nil {
				return err
			}
		}

		result = &po
		return nil
	})

	return result, err
}

// Receive ghi nhận một ĐỢT nhận hàng: cộng số đã nhận của từng dòng, cộng tồn
// kho và ghi bút toán, rồi chuyển trạng thái phiếu.
//
// Mọi thứ nằm trong MỘT transaction và tất-cả-hoặc-không: nhận vượt số đặt thì
// không dòng nào được ghi. Một đợt nhận hàng là một lần cân đối kho — ghi được
// nửa chừng rồi báo lỗi còn khó dọn hơn là không ghi gì.
//
// Biến thể được khoá theo ID tăng dần, cùng thứ tự với luồng đặt hàng và trả
// hàng, nên hai bên chạy song song không khoá chéo nhau.
func (r *purchaseOrderRepository) Receive(ctx context.Context, id uint, rc domain.PurchaseReceipt) (*domain.PurchaseOrder, error) {
	var result *domain.PurchaseOrder

	err := r.db.WithContext(ctx).Transaction(func(tx *gorm.DB) error {
		var po domain.PurchaseOrder
		err := tx.Clauses(clause.Locking{Strength: "UPDATE"}).
			Preload("Items", func(q *gorm.DB) *gorm.DB { return q.Order("id ASC") }).
			First(&po, id).Error
		if errors.Is(err, gorm.ErrRecordNotFound) {
			return domain.ErrNotFound
		}
		if err != nil {
			return err
		}

		// Chỉ phiếu đã gửi cho nhà cung cấp mới có hàng để nhận. Phiếu nháp chưa đặt
		// thật, phiếu đã đủ/đã huỷ thì không còn gì để nhận.
		if po.Status != domain.PurchaseStatusOrdered && po.Status != domain.PurchaseStatusPartial {
			return domain.ErrPurchaseLocked
		}

		itemByID := make(map[uint]*domain.PurchaseOrderItem, len(po.Items))
		for i := range po.Items {
			itemByID[po.Items[i].ID] = &po.Items[i]
		}

		// Gộp dòng trùng để kiểm tra theo TỔNG số nhận: hai dòng cùng một món đều
		// lọt qua riêng lẻ thì cộng lại vẫn có thể vượt số đặt.
		merged := make(map[uint]int, len(rc.Lines))
		order := make([]uint, 0, len(rc.Lines))
		for _, l := range rc.Lines {
			if l.Quantity <= 0 {
				continue
			}
			if _, seen := merged[l.ItemID]; !seen {
				order = append(order, l.ItemID)
			}
			merged[l.ItemID] += l.Quantity
		}
		if len(order) == 0 {
			return domain.ErrPurchaseNothingToReceive
		}

		// Số nhận theo BIẾN THỂ (một biến thể có thể nằm ở nhiều dòng phiếu) — kho
		// chỉ nên cộng đúng một lần cho mỗi biến thể.
		byVariant := make(map[uint]int, len(order))
		costOf := make(map[uint]float64, len(order))
		for _, itemID := range order {
			item, ok := itemByID[itemID]
			if !ok {
				return domain.ErrNotFound
			}
			qty := merged[itemID]
			if item.ReceivedQuantity+qty > item.Quantity {
				return domain.ErrPurchaseQtyExceeded
			}
			item.ReceivedQuantity += qty
			if err := tx.Model(&domain.PurchaseOrderItem{}).
				Where("id = ?", itemID).
				Update("received_quantity", item.ReceivedQuantity).Error; err != nil {
				return err
			}
			if item.ProductVariantID != nil {
				byVariant[*item.ProductVariantID] += qty
				costOf[*item.ProductVariantID] = item.UnitCost
			}
		}

		if err := receiveIntoStock(tx, &po, byVariant, costOf, rc); err != nil {
			return err
		}

		// Trạng thái mới suy từ TOÀN BỘ dòng hàng, không chỉ đợt vừa nhận.
		full := true
		for _, it := range po.Items {
			if it.ReceivedQuantity < it.Quantity {
				full = false
				break
			}
		}

		from := po.Status
		now := time.Now()
		cols := []string{"Status"}
		if full {
			po.Status = domain.PurchaseStatusReceived
			po.ReceivedAt = &now
			cols = append(cols, "ReceivedAt")
		} else {
			po.Status = domain.PurchaseStatusPartial
		}
		if rc.ActorID > 0 {
			actor := rc.ActorID
			po.HandledBy = &actor
			cols = append(cols, "HandledBy")
		}
		if err := tx.Model(&po).Select(cols).Updates(&po).Error; err != nil {
			return err
		}

		var received int
		for _, q := range merged {
			received += q
		}
		history := &domain.PurchaseOrderHistory{
			PurchaseOrderID: po.ID,
			FromStatus:      from,
			ToStatus:        po.Status,
			// Mốc này là DANH TÍNH của đợt nhận: trang Nhập hàng đếm và đánh số đợt
			// theo nó, nên khuôn ghi chú do receiveHistoryNote giữ (đọc lại bằng
			// receiveUserNote), đừng ghép chuỗi tại chỗ.
			Note: receiveHistoryNote(received, rc.Note),
		}
		if rc.ActorID > 0 {
			actor := rc.ActorID
			history.ChangedBy = &actor
		}
		if err := tx.Create(history).Error; err != nil {
			return err
		}

		result = &po
		return nil
	})

	return result, err
}

// receiveIntoStock cộng tồn kho và ghi bút toán cho một đợt nhận hàng.
//
// Bút toán ghi với type='import', reference_type='purchase_order' — tách hẳn khỏi
// sổ 'order'/'order_return' để sổ kho nói rõ được hàng vào kho theo đường nào.
func receiveIntoStock(
	tx *gorm.DB,
	po *domain.PurchaseOrder,
	byVariant map[uint]int,
	costOf map[uint]float64,
	rc domain.PurchaseReceipt,
) error {
	if len(byVariant) == 0 {
		return nil
	}

	ids := make([]uint, 0, len(byVariant))
	for vid := range byVariant {
		ids = append(ids, vid)
	}
	slices.Sort(ids)

	// Unscoped: biến thể có thể đã ngừng bán sau khi phiếu được đặt. Hàng đã về tay
	// cửa hàng thì vẫn phải ghi nhận vào kho, nếu không nhân viên không đóng được phiếu.
	var variants []domain.ProductVariant
	if err := tx.Unscoped().Clauses(clause.Locking{Strength: "UPDATE"}).
		Where("id IN ?", ids).Order("id ASC").Find(&variants).Error; err != nil {
		return err
	}
	found := make(map[uint]domain.ProductVariant, len(variants))
	for _, v := range variants {
		found[v.ID] = v
	}

	poID := po.ID
	actor := actorRef(rc.ActorID)

	// Hàng về kho của CHI NHÁNH ĐÃ ĐẶT, chốt từ lúc lập phiếu — không phải kho
	// của người đang bấm nút nhận. Thủ kho ở Quận 1 nhận giúp một đợt hàng đặt
	// cho Quận 7 là chuyện thường; hàng vẫn phải vào sổ của Quận 7.
	shopID := po.ShopID
	if shopID == 0 {
		var err error
		if shopID, err = chiNhanhCuaRequest(tx.Statement.Context, tx); err != nil {
			return err
		}
	}

	for _, vid := range ids {
		_, ok := found[vid]
		if !ok {
			// Biến thể đã bị xoá cứng: không còn chỗ nào để cộng tồn kho. Bỏ qua dòng
			// này thay vì chặn cả đợt nhận — hàng thực tế đã nằm trong kho rồi.
			continue
		}

		before, after, err := ghiTonChiNhanh(tx, shopID, vid, byVariant[vid], true)
		if err != nil {
			return err
		}

		// Giá vốn mới nhất là giá vừa mua. Ghi vào mức biến thể (chỗ trang tồn kho
		// đọc để tính giá trị kho) chứ không đụng tới sản phẩm cha — mỗi biến thể có
		// giá nhập riêng.
		if rc.UpdateCost {
			cost := costOf[vid]
			if err := tx.Unscoped().Model(&domain.ProductVariant{}).
				Where("id = ?", vid).
				Update("cost_price", cost).Error; err != nil {
				return err
			}
		}

		unitCost := costOf[vid]
		if err := tx.Create(&domain.InventoryTransaction{
			ShopID:           shopID,
			ProductVariantID: vid,
			Type:             "import",
			Quantity:         byVariant[vid],
			QuantityBefore:   before,
			QuantityAfter:    after,
			ReferenceType:    "purchase_order",
			ReferenceID:      &poID,
			UnitCost:         &unitCost,
			// Kèm luôn ghi chú người nhận: trang Nhập hàng dựng đợt nhận từ chính các
			// bút toán này, ghi chú nằm ở đây thì đọc lại được mà không phải dò lịch sử phiếu.
			Note:      receiptNote(po.POCode, rc.Note),
			CreatedBy: actor,
		}).Error; err != nil {
			return err
		}
	}

	return nil
}

// Delete xoá mềm phiếu. Tầng service chỉ cho gọi với phiếu nháp — phiếu đã đặt
// thật phải huỷ (có lý do, còn trong lịch sử) chứ không biến mất khỏi sổ.
func (r *purchaseOrderRepository) Delete(ctx context.Context, id uint) error {
	res := r.db.WithContext(ctx).Delete(&domain.PurchaseOrder{}, id)
	if res.Error != nil {
		return res.Error
	}
	if res.RowsAffected == 0 {
		return domain.ErrNotFound
	}
	return nil
}
