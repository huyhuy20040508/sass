package repository

import (
	"context"
	"sort"
	"strconv"
	"strings"
	"time"

	"gorm.io/gorm"

	"sass-api/internal/domain"
)

// goodsReceiptRepository dựng "đợt nhập hàng" từ dữ liệu sẵn có, KHÔNG có bảng
// phiếu nhập riêng.
//
// Mỗi lần nhận hàng, purchase_order_repository.Receive ghi (trong CÙNG một
// transaction, dưới khoá dòng của phiếu):
//   - một loạt bút toán inventory_transactions (type=import, reference_type=purchase_order)
//   - rồi MỘT mốc lịch sử purchase_order_history với ghi chú "Nhận N sản phẩm…"
//
// Mốc lịch sử đó chính là danh tính của đợt nhận: đếm được, đánh số được, và vì
// nó luôn ghi SAU các bút toán nên các bút toán nằm giữa mốc trước và mốc này
// đúng là dòng hàng của đợt này. Nhờ vậy không phải đoán theo khoảng thời gian
// (hai đợt nhận cách nhau vài giây vẫn tách đúng), và mọi đợt đã nhận từ trước
// khi có trang này cũng hiện đủ.
type goodsReceiptRepository struct{ db *gorm.DB }

func NewGoodsReceiptRepository(db *gorm.DB) domain.GoodsReceiptRepository {
	return &goodsReceiptRepository{db: db}
}

// Khuôn ghi chú của mốc "đã nhận hàng" — nơi DUY NHẤT quy định khuôn này, dùng cả
// lúc ghi (Receive) và lúc đọc lại (trang Nhập hàng). Đổi khuôn ở đây thì cả hai
// đầu đổi theo, không lệch nhau.
const (
	receiveHistoryPrefix = "Nhận "
	receiveNoteSeparator = " — "
)

// receiveHistoryNote dựng ghi chú mốc lịch sử: "Nhận N sản phẩm[ — ghi chú]".
func receiveHistoryNote(quantity int, userNote string) string {
	note := receiveHistoryPrefix + strconv.Itoa(quantity) + " sản phẩm"
	if userNote = strings.TrimSpace(userNote); userNote != "" {
		note += receiveNoteSeparator + userNote
	}
	return note
}

// receiveUserNote lấy lại phần ghi chú người nhận tự viết, bỏ phần "Nhận N sản
// phẩm" đã hiển thị thành cột số lượng riêng.
func receiveUserNote(note string) string {
	if _, rest, ok := strings.Cut(note, receiveNoteSeparator); ok {
		return strings.TrimSpace(rest)
	}
	return ""
}

// receiptNote là ghi chú cho BÚT TOÁN kho của đợt nhập: nói rõ nhập theo phiếu
// nào, kèm ghi chú người nhận để sổ kho đọc được ngay. Cắt vừa cột VARCHAR(255).
func receiptNote(poCode, userNote string) string {
	note := "Nhập hàng theo phiếu " + poCode
	if userNote = strings.TrimSpace(userNote); userNote != "" {
		note += receiveNoteSeparator + userNote
	}
	if runes := []rune(note); len(runes) > 255 {
		note = strings.TrimSpace(string(runes[:254])) + "…"
	}
	return note
}

func (r *goodsReceiptRepository) List(ctx context.Context, f domain.GoodsReceiptFilter) ([]domain.GoodsReceipt, int64, error) {
	all, err := r.batches(ctx)
	if err != nil {
		return nil, 0, err
	}

	list := filterReceipts(all, f)
	total := int64(len(list))
	sortReceipts(list, f.Sort)

	page, pageSize := f.Page, f.PageSize
	if page < 1 {
		page = 1
	}
	if pageSize < 1 {
		pageSize = 20
	}
	start := (page - 1) * pageSize
	if start >= len(list) {
		return []domain.GoodsReceipt{}, total, nil
	}

	return list[start:min(start+pageSize, len(list))], total, nil
}

func (r *goodsReceiptRepository) Find(ctx context.Context, code string) (*domain.GoodsReceipt, error) {
	all, err := r.batches(ctx)
	if err != nil {
		return nil, err
	}

	want := strings.ToUpper(strings.TrimSpace(code))
	for i := range all {
		if strings.ToUpper(all[i].Code) != want {
			continue
		}
		items, err := r.items(ctx, all[i])
		if err != nil {
			return nil, err
		}
		found := all[i]
		found.Items = items
		return &found, nil
	}
	return nil, domain.ErrNotFound
}

func (r *goodsReceiptRepository) Stats(ctx context.Context) (domain.GoodsReceiptStats, error) {
	var stats domain.GoodsReceiptStats

	all, err := r.batches(ctx)
	if err != nil {
		return stats, err
	}

	// Mốc "hôm nay" theo giờ máy chủ (Truncate tính theo UTC nên lệch ở múi +07).
	now := time.Now()
	y, m, d := now.Date()
	dayStart := time.Date(y, m, d, 0, 0, 0, 0, now.Location())

	for _, b := range all {
		stats.TotalReceipts++
		stats.TotalQuantity += int64(b.Quantity)
		stats.TotalAmount += b.Amount
		if !b.ReceivedAt.Before(dayStart) {
			stats.TodayReceipts++
			stats.TodayQuantity += int64(b.Quantity)
		}
	}

	// Việc còn phải làm: phiếu đã gửi nhà cung cấp mà hàng chưa về đủ.
	var pending struct {
		Orders   int64
		Quantity int64
	}
	err = r.db.WithContext(ctx).
		Table("purchase_orders AS po").
		Joins("JOIN purchase_order_items i ON i.purchase_order_id = po.id").
		Where("po.deleted_at IS NULL AND po.status IN ?",
			[]string{domain.PurchaseStatusOrdered, domain.PurchaseStatusPartial}).
		Select(`COUNT(DISTINCT po.id) AS orders,
			COALESCE(SUM(GREATEST(i.quantity - i.received_quantity, 0)), 0) AS quantity`).
		Scan(&pending).Error
	if err != nil {
		return stats, err
	}
	stats.PendingOrders = pending.Orders
	stats.PendingQuantity = pending.Quantity

	return stats, nil
}

// receiveMark là một mốc lịch sử "đã nhận hàng" của phiếu — tức một đợt nhập.
type receiveMark struct {
	ID              uint
	PurchaseOrderID uint
	POCode          string
	SupplierID      *uint
	SupplierName    string
	POStatus        string
	CreatedAt       time.Time
	Note            string
	CreatedByName   string
}

// receiptLine là một bút toán nhập kho, chỉ giữ các cột cần để cộng dồn theo đợt.
type receiptLine struct {
	PurchaseOrderID uint
	CreatedAt       time.Time
	Quantity        int
	Amount          float64
}

// batches đọc toàn bộ đợt nhập, đã đánh số đợt theo từng phiếu.
//
// Đọc HẾT chứ không lọc phía SQL vì việc đánh số "đợt thứ mấy của phiếu" cần thấy
// mọi đợt của phiếu đó, kể cả đợt nằm ngoài bộ lọc ngày mà giao diện đang xem.
func (r *goodsReceiptRepository) batches(ctx context.Context) ([]domain.GoodsReceipt, error) {
	var marks []receiveMark
	err := r.db.WithContext(ctx).
		Table("purchase_order_history AS h").
		Joins("JOIN purchase_orders po ON po.id = h.purchase_order_id AND po.deleted_at IS NULL").
		Joins("LEFT JOIN users u ON u.id = h.changed_by").
		Where("h.to_status IN ? AND h.note LIKE ?",
			[]string{domain.PurchaseStatusPartial, domain.PurchaseStatusReceived},
			receiveHistoryPrefix+"%").
		Select(`h.id, h.purchase_order_id, po.po_code, po.supplier_id, po.supplier_name,
			po.status AS po_status, h.created_at, COALESCE(h.note, '') AS note,
			COALESCE(u.full_name, '') AS created_by_name`).
		Order("h.purchase_order_id ASC, h.created_at ASC, h.id ASC").
		Scan(&marks).Error
	if err != nil {
		return nil, err
	}
	if len(marks) == 0 {
		return []domain.GoodsReceipt{}, nil
	}

	var lines []receiptLine
	err = r.db.WithContext(ctx).
		Table("inventory_transactions AS t").
		Where("t.type = 'import' AND t.reference_type = 'purchase_order'").
		Select(`t.reference_id AS purchase_order_id, t.created_at, t.quantity,
			t.quantity * COALESCE(t.unit_cost, 0) AS amount`).
		Order("t.reference_id ASC, t.created_at ASC, t.id ASC").
		Scan(&lines).Error
	if err != nil {
		return nil, err
	}

	linesByPO := make(map[uint][]receiptLine, len(marks))
	for _, l := range lines {
		linesByPO[l.PurchaseOrderID] = append(linesByPO[l.PurchaseOrderID], l)
	}

	batchNo := make(map[uint]int, len(marks))
	cursor := make(map[uint]int, len(marks))
	prevAt := make(map[uint]time.Time, len(marks))
	lastIdx := make(map[uint]int, len(marks))

	out := make([]domain.GoodsReceipt, 0, len(marks))
	for _, m := range marks {
		batchNo[m.PurchaseOrderID]++
		batch := batchNo[m.PurchaseOrderID]

		g := domain.GoodsReceipt{
			Code:            m.POCode + "-N" + strconv.Itoa(batch),
			Batch:           batch,
			PurchaseOrderID: m.PurchaseOrderID,
			POCode:          m.POCode,
			SupplierID:      m.SupplierID,
			SupplierName:    m.SupplierName,
			POStatus:        m.POStatus,
			ReceivedAt:      m.CreatedAt,
			FromAt:          prevAt[m.PurchaseOrderID],
			CreatedByName:   m.CreatedByName,
			Note:            receiveUserNote(m.Note),
		}

		// Bút toán ghi trước mốc này (và sau mốc trước) là dòng hàng của đợt này.
		pl := linesByPO[m.PurchaseOrderID]
		i := cursor[m.PurchaseOrderID]
		for i < len(pl) && !pl[i].CreatedAt.After(m.CreatedAt) {
			g.LineCount++
			g.Quantity += pl[i].Quantity
			g.Amount += pl[i].Amount
			i++
		}
		cursor[m.PurchaseOrderID] = i
		prevAt[m.PurchaseOrderID] = m.CreatedAt

		out = append(out, g)
		lastIdx[m.PurchaseOrderID] = len(out) - 1
	}

	// Bút toán nằm SAU mốc cuối cùng của phiếu: không xảy ra với luồng hiện tại (mốc
	// luôn ghi sau bút toán trong cùng transaction), nhưng nếu có thì dồn vào đợt
	// cuối để tổng số nhập của trang vẫn khớp sổ kho, không âm thầm thiếu hàng.
	for poID, pl := range linesByPO {
		idx, ok := lastIdx[poID]
		if !ok {
			continue
		}
		for i := cursor[poID]; i < len(pl); i++ {
			out[idx].LineCount++
			out[idx].Quantity += pl[i].Quantity
			out[idx].Amount += pl[i].Amount
			if pl[i].CreatedAt.After(out[idx].ReceivedAt) {
				out[idx].ReceivedAt = pl[i].CreatedAt
			}
		}
	}

	return out, nil
}

// items đọc các dòng hàng của đúng một đợt: cùng phiếu, ghi sau mốc đợt trước và
// không sau mốc của đợt này.
func (r *goodsReceiptRepository) items(ctx context.Context, b domain.GoodsReceipt) ([]domain.GoodsReceiptItem, error) {
	q := r.db.WithContext(ctx).
		Table("inventory_transactions AS t").
		Joins("LEFT JOIN product_variants v ON v.id = t.product_variant_id").
		Joins("LEFT JOIN products p ON p.id = v.product_id").
		Where(`t.type = 'import' AND t.reference_type = 'purchase_order'
			AND t.reference_id = ? AND t.created_at <= ?`, b.PurchaseOrderID, b.ReceivedAt)
	if !b.FromAt.IsZero() {
		q = q.Where("t.created_at > ?", b.FromAt)
	}

	var rows []domain.GoodsReceiptItem
	err := q.Select(`t.id AS transaction_id, t.product_variant_id AS variant_id, v.product_id,
			COALESCE(p.name, '') AS product_name, COALESCE(v.sku, '') AS sku,
			COALESCE(v.size, '') AS size, COALESCE(v.color, '') AS color,
			COALESCE(NULLIF(v.image, ''), NULLIF(p.thumbnail, ''), '') AS thumbnail,
			t.quantity, COALESCE(t.unit_cost, 0) AS unit_cost,
			t.quantity * COALESCE(t.unit_cost, 0) AS amount,
			t.quantity_before, t.quantity_after`).
		Order("t.id ASC").
		Scan(&rows).Error
	if err != nil {
		return nil, err
	}
	return rows, nil
}

// filterReceipts lọc tại chỗ — danh sách đợt nhập nhỏ và đã nằm trong bộ nhớ.
func filterReceipts(all []domain.GoodsReceipt, f domain.GoodsReceiptFilter) []domain.GoodsReceipt {
	kw := strings.ToLower(strings.TrimSpace(f.Keyword))
	out := make([]domain.GoodsReceipt, 0, len(all))

	for _, b := range all {
		if f.SupplierID > 0 && (b.SupplierID == nil || *b.SupplierID != f.SupplierID) {
			continue
		}
		day := b.ReceivedAt.Format("2006-01-02")
		if f.FromDate != "" && day < f.FromDate {
			continue
		}
		if f.ToDate != "" && day > f.ToDate {
			continue
		}
		if kw != "" && !strings.Contains(strings.ToLower(strings.Join([]string{
			b.Code, b.POCode, b.SupplierName, b.CreatedByName, b.Note,
		}, " ")), kw) {
			continue
		}
		out = append(out, b)
	}
	return out
}

func sortReceipts(list []domain.GoodsReceipt, sortKey string) {
	switch sortKey {
	case "oldest":
		sort.SliceStable(list, func(i, j int) bool { return list[i].ReceivedAt.Before(list[j].ReceivedAt) })
	case "qty_desc":
		sort.SliceStable(list, func(i, j int) bool { return list[i].Quantity > list[j].Quantity })
	case "amount_desc":
		sort.SliceStable(list, func(i, j int) bool { return list[i].Amount > list[j].Amount })
	default: // newest
		sort.SliceStable(list, func(i, j int) bool { return list[i].ReceivedAt.After(list[j].ReceivedAt) })
	}
}
