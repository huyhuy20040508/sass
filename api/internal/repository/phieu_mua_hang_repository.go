package repository

import (
	"context"
	"encoding/json"
	"fmt"
	"slices"
	"strings"
	"time"

	"gorm.io/gorm"
	"gorm.io/gorm/clause"

	"sass-api/internal/domain"
)

type purchaseOrderRepository struct {
	db *gorm.DB
}

func NewPurchaseOrderRepository(db *gorm.DB) domain.PurchaseOrderRepository {
	return &purchaseOrderRepository{db: db}
}

// ---------- Liệt kê ----------

func applyPurchaseFilter(q *gorm.DB, f domain.PurchaseFilter) *gorm.DB {
	if kw := strings.TrimSpace(f.Keyword); kw != "" {
		like := "%" + kw + "%"
		q = q.Where("(po_code LIKE ? OR supplier_name LIKE ? OR note LIKE ?)", like, like, like)
	}

	// Nhiều trạng thái ngăn bởi dấu phẩy: bộ lọc ngoài bảng là các ô tick, tick
	// hai ô thì phải ra hợp của hai tập chứ không phải rỗng.
	if st := trangThaiLoc(f.Status); len(st) > 0 {
		q = q.Where("status IN ?", st)
	}
	if ps := trangThaiLoc(f.PaymentStatus); len(ps) > 0 {
		q = q.Where("payment_status IN ?", ps)
	}
	if f.SupplierID > 0 {
		q = q.Where("supplier_id = ?", f.SupplierID)
	}
	// Cắt theo chi nhánh phát sinh chứng từ. Bỏ qua khi = 0 (xem gộp cả cửa
	// hàng) — chỉ có ở báo cáo và khi người dùng chủ động chọn "tất cả".
	if f.ShopID > 0 {
		q = q.Where("shop_id = ?", f.ShopID)
	}
	if len(f.VariantIDs) > 0 {
		q = q.Where("EXISTS (SELECT 1 FROM purchase_order_items i"+
			" WHERE i.purchase_order_id = purchase_orders.id AND i.product_variant_id IN ?)", f.VariantIDs)
	}

	// Số lô khớp MỘT PHẦN: người dùng nhớ "L2026" chứ hiếm khi nhớ đủ cả chuỗi.
	if lo := strings.TrimSpace(f.LotNumber); lo != "" {
		q = q.Where("EXISTS (SELECT 1 FROM purchase_order_items i"+
			" WHERE i.purchase_order_id = purchase_orders.id AND i.lot_number LIKE ?)", "%"+lo+"%")
	}

	// Ngày rỗng thì KHÔNG lọc. Bản v2 nhét thẳng chuỗi rỗng vào whereDate và
	// Carbon hiểu là "bây giờ", nên gọi API không kèm ngày là bảng chỉ còn phiếu
	// lập hôm nay.
	if from := strings.TrimSpace(f.FromDate); from != "" {
		q = q.Where("created_at >= ?", from+" 00:00:00")
	}
	if to := strings.TrimSpace(f.ToDate); to != "" {
		q = q.Where("created_at <= ?", to+" 23:59:59")
	}

	return q
}

// trangThaiLoc tách chuỗi lọc thành danh sách. "" và "all" = không lọc.
func trangThaiLoc(v string) []string {
	v = strings.TrimSpace(v)
	if v == "" || v == "all" {
		return nil
	}

	out := make([]string, 0, 4)
	for _, phan := range strings.Split(v, ",") {
		if phan = strings.TrimSpace(phan); phan != "" {
			out = append(out, phan)
		}
	}

	return out
}

func purchaseOrderBy(sort string) string {
	switch sort {
	case "oldest":
		return "purchase_orders.id ASC"
	case "total_desc":
		return "purchase_orders.total_amount DESC, purchase_orders.id DESC"
	case "total_asc":
		return "purchase_orders.total_amount ASC, purchase_orders.id DESC"
	case "document_desc":
		// Ngày chứng từ có thể trống (phiếu gõ vội), những dòng ấy xuống cuối chứ
		// đừng lên đầu như MySQL vẫn xếp NULL.
		return "purchase_orders.document_date IS NULL, purchase_orders.document_date DESC, purchase_orders.id DESC"
	default:
		return "purchase_orders.id DESC"
	}
}

func (r *purchaseOrderRepository) List(ctx context.Context, f domain.PurchaseFilter) ([]domain.PurchaseOrder, int64, error) {
	q := applyPurchaseFilter(r.db.WithContext(ctx).Model(&domain.PurchaseOrder{}), f)

	var tong int64
	if err := q.Count(&tong).Error; err != nil {
		return nil, 0, err
	}

	page, size := f.Page, f.PageSize
	if page < 1 {
		page = 1
	}
	if size < 1 {
		size = 20
	}

	var list []domain.PurchaseOrder
	err := q.Preload("Items", func(q *gorm.DB) *gorm.DB { return q.Order("id ASC") }).
		Order(purchaseOrderBy(f.Sort)).
		Offset((page - 1) * size).
		Limit(size).
		Find(&list).Error
	if err != nil {
		return nil, 0, err
	}

	return list, tong, r.napTenNguoiLap(ctx, list)
}

// napTenNguoiLap tra TÊN người lập cho cả trang trong MỘT câu truy vấn.
//
// Không Preload quan hệ: `created_by` trỏ vào users, mà bảng đó còn mang mật
// khẩu băm và khoá phiên — kéo nguyên dòng về chỉ để lấy cái tên là mang theo
// những thứ không nên rời khỏi tầng dưới.
func (r *purchaseOrderRepository) napTenNguoiLap(ctx context.Context, list []domain.PurchaseOrder) error {
	ids := make([]uint, 0, len(list))
	for _, po := range list {
		if po.CreatedBy != nil && *po.CreatedBy > 0 && !slices.Contains(ids, *po.CreatedBy) {
			ids = append(ids, *po.CreatedBy)
		}
	}
	if len(ids) == 0 {
		return nil
	}

	type dong struct {
		ID       uint
		FullName string
	}
	var nguoi []dong
	// Unscoped: người lập có thể đã nghỉ và bị xoá mềm, phiếu cũ vẫn phải in
	// ra được tên họ.
	if err := r.db.WithContext(ctx).Unscoped().Table("users").
		Select("id, COALESCE(full_name, '') AS full_name").
		Where("id IN ?", ids).Scan(&nguoi).Error; err != nil {
		return err
	}

	ten := make(map[uint]string, len(nguoi))
	for _, n := range nguoi {
		ten[n.ID] = n.FullName
	}
	for i := range list {
		if list[i].CreatedBy != nil {
			list[i].CreatedByName = ten[*list[i].CreatedBy]
		}
	}

	return nil
}

func (r *purchaseOrderRepository) FindByID(ctx context.Context, id uint) (*domain.PurchaseOrder, error) {
	var po domain.PurchaseOrder
	err := r.db.WithContext(ctx).
		Preload("Items", func(q *gorm.DB) *gorm.DB { return q.Order("id ASC") }).
		Where("id = ?", id).Take(&po).Error
	if err == gorm.ErrRecordNotFound {
		return nil, domain.ErrNotFound
	}
	if err != nil {
		return nil, err
	}
	// Phiếu của chi nhánh khác thì coi như không có: id chạy tuần tự nên không
	// chặn ở đây là mở toang sổ mua hàng của mọi kho cho bất kỳ ai đăng nhập.
	if err := chanChungTuKhacChiNhanh(ctx, r.db, po.ShopID); err != nil {
		return nil, err
	}

	mot := []domain.PurchaseOrder{po}
	if err := r.napTenNguoiLap(ctx, mot); err != nil {
		return nil, err
	}

	return &mot[0], nil
}

// Stats — một lượt quét, không phải năm câu đếm.
func (r *purchaseOrderRepository) Stats(ctx context.Context) (domain.PurchaseStats, error) {
	var s domain.PurchaseStats

	// Tiền chỉ cộng trên phiếu ĐÃ DUYỆT: phiếu lưu tạm chưa mua gì, phiếu huỷ
	// thì không bao giờ mua. Cộng cả hai vào là con số đầu trang nói dối.
	err := r.db.WithContext(ctx).Model(&domain.PurchaseOrder{}).
		Select(`COUNT(*) AS total,
			SUM(status = ?) AS draft,
			SUM(status = ?) AS approved,
			SUM(status = ?) AS cancelled,
			COALESCE(SUM(CASE WHEN status = ? THEN total_amount ELSE 0 END), 0) AS purchased_amount,
			COALESCE(SUM(CASE WHEN status = ? THEN total_amount - paid_amount ELSE 0 END), 0) AS debt_amount`,
			domain.PurchaseStatusDraft, domain.PurchaseStatusApproved, domain.PurchaseStatusCancelled,
			domain.PurchaseStatusApproved, domain.PurchaseStatusApproved).
		Scan(&s).Error

	return s, err
}

func (r *purchaseOrderRepository) Histories(ctx context.Context, purchaseID uint) ([]domain.PurchaseOrderHistory, error) {
	var out []domain.PurchaseOrderHistory
	err := r.db.WithContext(ctx).
		Where("purchase_order_id = ?", purchaseID).
		Order("id ASC").Find(&out).Error

	return out, err
}

// Payments trả sổ từng lượt trả tiền của phiếu, cũ -> mới, kèm tên người ghi.
func (r *purchaseOrderRepository) Payments(ctx context.Context, purchaseID uint) ([]domain.PurchasePayment, error) {
	var out []domain.PurchasePayment
	if err := r.db.WithContext(ctx).
		Where("purchase_order_id = ?", purchaseID).
		Order("id ASC").Find(&out).Error; err != nil {
		return nil, err
	}

	ids := make([]uint, 0, len(out))
	for _, t := range out {
		if t.CreatedBy != nil {
			ids = append(ids, *t.CreatedBy)
		}
	}
	if len(ids) == 0 {
		return out, nil
	}

	type dong struct {
		ID       uint
		FullName string
	}
	var nguoi []dong
	// Unscoped: người ghi sổ có thể đã nghỉ và bị xoá mềm, dòng sổ cũ vẫn phải
	// đọc ra được tên họ.
	if err := r.db.WithContext(ctx).Unscoped().Table("users").
		Select("id, COALESCE(full_name, '') AS full_name").
		Where("id IN ?", ids).Scan(&nguoi).Error; err != nil {
		return nil, err
	}

	ten := make(map[uint]string, len(nguoi))
	for _, n := range nguoi {
		ten[n.ID] = n.FullName
	}
	for i := range out {
		if out[i].CreatedBy != nil {
			out[i].CreatedByName = ten[*out[i].CreatedBy]
		}
	}

	return out, nil
}

// ---------- Tra mặt hàng ----------

// purchaseVariantSelect — cột dựng nên một dòng chọn hàng.
//
// Giá gợi ý là giá VỐN hiệu lực (biến thể ghi đè sản phẩm cha) chứ không phải
// giá bán — đây là chiều mua vào.
//
// `ton` truyền vào chứ không ghi cứng: cột "còn" trên màn lập phiếu phải là tồn
// CỦA KHO SẼ NHẬN HÀNG VỀ, xem tonPhieuMua.
func purchaseVariantSelect(ton string) string {
	return `v.id AS variant_id, v.product_id, p.name AS product_name,
	v.sku, COALESCE(v.name, '') AS variant_name,
	COALESCE(NULLIF(v.image, ''), NULLIF(p.thumbnail, ''), '') AS thumbnail,
	` + effectiveCostExpr + ` AS cost_price,
	` + ton + ` AS stock,
	COALESCE(p.vat, 0) AS vat_percent,
	COALESCE(p.unit_id, 0) AS base_unit_id`
}

// tonPhieuMua trả về mệnh đề JOIN và biểu thức tồn của ĐÚNG chi nhánh mà phiếu
// sẽ nhận hàng về.
//
// Chi nhánh chọn bằng chính hàm mà Create dùng để đóng dấu `purchase_orders.
// shop_id` — người lập phiếu thấy "kho này còn 3 cái" thì lượt duyệt cũng cộng
// vào đúng kho ấy.
func (r *purchaseOrderRepository) tonPhieuMua(ctx context.Context) (joinTon, ton string, err error) {
	shopID, err := chiNhanhCuaRequest(ctx, r.db)
	if err != nil {
		return "", "", err
	}

	// LEFT JOIN: biến thể chưa từng có hàng ở kho này vẫn phải tra ra được — đó
	// chính là món người ta đang định mua về.
	//
	// %d của một uint đã qua chiNhanhCuaRequest (tra sổ trong đúng cửa hàng của
	// ctx) không sinh ra được ký tự nào ngoài chữ số.
	return fmt.Sprintf(
		"LEFT JOIN variant_stocks vs ON vs.product_variant_id = v.id AND vs.shop_id = %d", shopID,
	), tonTheoChiNhanh, nil
}

func (r *purchaseOrderRepository) SearchVariants(ctx context.Context, keyword string, categoryID uint, limit int) ([]domain.PurchaseVariant, error) {
	if limit < 1 {
		limit = 20
	}

	joinTon, ton, err := r.tonPhieuMua(ctx)
	if err != nil {
		return nil, err
	}

	// Chỉ hàng còn bán được mới mua thêm: nhập kho một món không ai bán được thì
	// tiền nằm chết trong kho.
	q := r.db.WithContext(ctx).
		Table("product_variants AS v").
		Joins("JOIN products p ON p.id = v.product_id AND p.deleted_at IS NULL").
		Joins(joinTon).
		Where("v.deleted_at IS NULL AND v.is_active = 1 AND p.is_active = 1")

	if kw := strings.TrimSpace(keyword); kw != "" {
		like := "%" + kw + "%"
		q = q.Where("(p.name LIKE ? OR v.sku LIKE ? OR p.sku LIKE ?)", like, like, like)
	}
	if categoryID > 0 {
		q = q.Where("p.category_id = ?", categoryID)
	}

	out := make([]domain.PurchaseVariant, 0, limit)
	// Hàng sắp hết lên đầu: đó chính là thứ người lập phiếu đang cần mua thêm.
	err = q.Select(purchaseVariantSelect(ton)).
		Order(ton + " ASC, p.name ASC, v.id ASC").
		Limit(limit).
		Scan(&out).Error
	if err != nil {
		return nil, err
	}

	if err := r.napDonVi(ctx, out); err != nil {
		return nil, err
	}

	return out, r.napLo(ctx, out)
}

// napLo gắn danh sách lô đang còn hàng vào từng mặt hàng của ô tìm hàng.
//
// Hỏng thì bỏ qua chứ không chặn cả lượt tìm: thiếu danh sách lô thì người dùng
// gõ tay số lô như trước, còn chặn lượt tìm là không lập được phiếu nào.
func (r *purchaseOrderRepository) napLo(ctx context.Context, ds []domain.PurchaseVariant) error {
	if len(ds) == 0 {
		return nil
	}

	shopID, err := chiNhanhCuaRequest(ctx, r.db)
	if err != nil {
		return nil
	}

	ids := make([]uint, 0, len(ds))
	for _, v := range ds {
		ids = append(ids, v.VariantID)
	}

	theoBienThe, err := loCuaBienThe(r.db.WithContext(ctx), shopID, ids)
	if err != nil {
		return nil
	}

	for i := range ds {
		for _, l := range theoBienThe[ds[i].VariantID] {
			han := ""
			if l.ExpireDate != nil {
				han = l.ExpireDate.Format("2006-01-02")
			}
			ds[i].Lots = append(ds[i].Lots, domain.PurchaseLot{
				LotNumber:  l.LotNumber,
				ExpireDate: han,
				Quantity:   l.Quantity,
				UnitCost:   l.UnitCost,
			})
		}
	}

	return nil
}

// NhomHangCoHang liệt kê nhóm hàng đang có hàng mua được.
//
// Điều kiện phải khớp TỪNG CHỮ với SearchVariants. Lọc theo một bộ điều kiện
// khác là ô chọn bày ra một nhóm mà ô tìm hàng không tra ra dòng nào — đúng cái
// bảng trắng mà ô lọc này sinh ra để tránh.
func (r *purchaseOrderRepository) NhomHangCoHang(ctx context.Context) ([]domain.PurchaseNhomHang, error) {
	out := make([]domain.PurchaseNhomHang, 0, 16)

	err := r.db.WithContext(ctx).
		Table("product_variants AS v").
		Joins("JOIN products p ON p.id = v.product_id AND p.deleted_at IS NULL").
		Joins("JOIN categories c ON c.id = p.category_id AND c.deleted_at IS NULL").
		Where("v.deleted_at IS NULL AND v.is_active = 1 AND p.is_active = 1").
		Select("c.id, c.name, COUNT(DISTINCT p.id) AS so_mat_hang").
		Group("c.id, c.name").
		Order("c.name ASC").
		Scan(&out).Error

	return out, err
}

// LookupVariants tra thông tin mặt hàng theo ID biến thể để chụp snapshot.
//
// Không lọc is_active: mặt hàng có thể bị ẩn sau khi phiếu đã lập, lúc đó vẫn
// phải đọc/sửa được phiếu cũ. Việc chặn mua hàng đã ẩn nằm ở SearchVariants
// (lúc chọn), không nằm ở đây.
func (r *purchaseOrderRepository) LookupVariants(ctx context.Context, ids []uint) (map[uint]domain.PurchaseVariant, error) {
	out := make(map[uint]domain.PurchaseVariant, len(ids))
	if len(ids) == 0 {
		return out, nil
	}

	joinTon, ton, err := r.tonPhieuMua(ctx)
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
	if err := r.napDonVi(ctx, rows); err != nil {
		return nil, err
	}

	for _, row := range rows {
		out[row.VariantID] = row
	}

	return out, nil
}

// napDonVi gắn danh sách đơn vị MUA ĐƯỢC vào từng dòng mặt hàng.
//
// Đơn vị tính chính luôn đứng đầu với hệ số 1, rồi tới khối quy đổi đã khai ở
// mặt hàng ("1 Thùng = 24 Cái"). Đọc `products.unit_conversions` một lượt cho
// mọi mặt hàng rồi tra tên đơn vị một lượt nữa — không hỏi vòng trong vòng như
// bản v2 gọi OdrMenuUnit::find() cho từng dòng.
func (r *purchaseOrderRepository) napDonVi(ctx context.Context, rows []domain.PurchaseVariant) error {
	if len(rows) == 0 {
		return nil
	}

	productIDs := make([]uint, 0, len(rows))
	for _, row := range rows {
		if !slices.Contains(productIDs, row.ProductID) {
			productIDs = append(productIDs, row.ProductID)
		}
	}

	type dongQuyDoi struct {
		ID              uint
		UnitID          uint
		UnitConversions []byte
	}
	var sp []dongQuyDoi
	err := r.db.WithContext(ctx).Table("products").
		Select("id, COALESCE(unit_id, 0) AS unit_id, unit_conversions").
		Where("id IN ?", productIDs).Scan(&sp).Error
	if err != nil {
		return err
	}

	quyDoi := make(map[uint]domain.DanhSachQuyDoi, len(sp))
	canTen := make([]uint, 0, len(sp))
	for _, row := range sp {
		var ds domain.DanhSachQuyDoi
		if len(row.UnitConversions) > 0 {
			// Cột JSON hỏng (dữ liệu vá tay) thì coi như mặt hàng không khai quy đổi:
			// vẫn mua được theo đơn vị chính, hơn là cả trang lập phiếu chết.
			_ = json.Unmarshal(row.UnitConversions, &ds)
		}
		quyDoi[row.ID] = ds

		if row.UnitID > 0 && !slices.Contains(canTen, row.UnitID) {
			canTen = append(canTen, row.UnitID)
		}
		for _, d := range ds {
			if d.UnitID > 0 && !slices.Contains(canTen, d.UnitID) {
				canTen = append(canTen, d.UnitID)
			}
		}
	}

	ten := make(map[uint]string, len(canTen))
	if len(canTen) > 0 {
		var dv []domain.DonViTinh
		// Unscoped: đơn vị có thể đã xoá mềm mà mặt hàng vẫn khai theo nó — phiếu
		// cũ và ô chọn đều cần đọc ra tên.
		if err := r.db.WithContext(ctx).Unscoped().
			Where("id IN ?", canTen).Find(&dv).Error; err != nil {
			return err
		}
		for _, d := range dv {
			ten[d.ID] = d.Name
		}
	}

	for i := range rows {
		row := &rows[i]
		row.BaseUnitName = ten[row.BaseUnitID]

		// Đơn vị chính luôn có mặt, kể cả khi mặt hàng chưa khai đơn vị nào: dòng
		// hàng vẫn phải mua được, chỉ là ô đơn vị hiện dấu gạch.
		row.Units = []domain.PurchaseUnit{{UnitID: row.BaseUnitID, Name: row.BaseUnitName, Ratio: 1}}
		for _, d := range quyDoi[row.ProductID] {
			if d.UnitID == 0 || d.Quantity <= 0 || d.UnitID == row.BaseUnitID {
				continue
			}
			row.Units = append(row.Units, domain.PurchaseUnit{
				UnitID: d.UnitID, Name: ten[d.UnitID], Ratio: d.Quantity,
			})
		}
	}

	return nil
}

// ---------- Tạo ----------

// Create tạo phiếu trong MỘT transaction.
//
// Mã phiếu dùng mã tạm để lấy ID (ràng buộc UNIQUE), sau đó đổi thành mã hiển
// thị theo ngày + ID — cùng cách sinh mã với đơn hàng và phiếu trả hàng.
//
// KHÔNG đụng tới tồn kho: phiếu mới lập luôn là phiếu lưu tạm, hàng chỉ vào kho
// ở Approve.
func (r *purchaseOrderRepository) Create(ctx context.Context, po *domain.PurchaseOrder) error {
	// Chi nhánh LẬP phiếu — cũng là kho hàng sẽ về khi duyệt.
	shopID, err := chiNhanhCuaRequest(ctx, r.db)
	if err != nil {
		return err
	}
	po.ShopID = shopID

	if err := chanHangKhacChiNhanh(ctx, r.db, shopID, bienTheCuaPhieu(po.Items)); err != nil {
		return err
	}

	return r.db.WithContext(ctx).Transaction(func(tx *gorm.DB) error {
		items := po.Items
		po.Items = nil
		po.POCode = fmt.Sprintf("TMP%d", time.Now().UnixNano())

		if err := tx.Create(po).Error; err != nil {
			return err
		}

		ma, err := maChungTu(ctx, tx, domain.LoaiPhieuMuaHang, po.ShopID, &domain.PurchaseOrder{}, "po_code",
			fmt.Sprintf("PMH%s%04d", time.Now().Format("20060102"), po.ID))
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

		return tx.Create(&domain.PurchaseOrderHistory{
			PurchaseOrderID: po.ID,
			FromStatus:      "",
			ToStatus:        po.Status,
			Note:            "Lập phiếu, lưu tạm",
			ChangedBy:       po.CreatedBy,
		}).Error
	})
}

// ---------- Sửa ----------

// Update sửa phiếu + THAY TOÀN BỘ danh sách hàng dưới khoá dòng.
//
// Thay hết dòng cũ (xoá cứng rồi tạo lại) thay vì đối chiếu từng dòng: phiếu chỉ
// sửa được khi còn LƯU TẠM (điều kiện do tầng service kiểm tra trong mutate), mà
// phiếu lưu tạm thì chưa có gì bám vào dòng hàng của nó.
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
			Where("id = ?", id).Take(&po).Error
		if err == gorm.ErrRecordNotFound {
			return domain.ErrNotFound
		}
		if err != nil {
			return err
		}
		if err := chanChungTuKhacChiNhanh(ctx, tx, po.ShopID); err != nil {
			return err
		}

		// Điều kiện sửa được kiểm TRONG khoá dòng: kiểm trước khi gọi thì một lượt
		// duyệt chen vào giữa sẽ bị danh sách hàng mới xoá đè lên.
		cols, items, err := mutate(&po)
		if err != nil {
			return err
		}

		// Sửa phiếu ĐỔI ĐƯỢC danh sách hàng, nên phải chặn lại y như lúc lập: kiểm
		// theo chi nhánh ĐÃ CHỐT trên phiếu, không phải chi nhánh người đang sửa
		// đứng — hàng sẽ về kho của phiếu.
		if err := chanHangKhacChiNhanh(ctx, tx, po.ShopID, bienTheCuaPhieu(items)); err != nil {
			return err
		}

		if err := tx.Model(&po).Select(cols).Updates(&po).Error; err != nil {
			return err
		}

		if err := tx.Where("purchase_order_id = ?", po.ID).
			Delete(&domain.PurchaseOrderItem{}).Error; err != nil {
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
	if err != nil {
		return nil, err
	}

	return result, nil
}

// LockAndUpdate đọc-sửa-ghi phiếu dưới khoá dòng, KHÔNG đụng tới kho.
//
// Dùng cho huỷ phiếu và ghi nhận thanh toán. Đường duyệt đi riêng qua Approve vì
// nó còn phải cộng kho và ghi bút toán trong cùng transaction.
func (r *purchaseOrderRepository) LockAndUpdate(
	ctx context.Context,
	id uint,
	apply func(po *domain.PurchaseOrder) (*domain.PurchaseOrderHistory, *domain.PurchasePayment, []string, error),
) (*domain.PurchaseOrder, error) {
	var result *domain.PurchaseOrder

	err := r.db.WithContext(ctx).Transaction(func(tx *gorm.DB) error {
		var po domain.PurchaseOrder
		err := tx.Clauses(clause.Locking{Strength: "UPDATE"}).
			Preload("Items", func(q *gorm.DB) *gorm.DB { return q.Order("id ASC") }).
			Where("id = ?", id).Take(&po).Error
		if err == gorm.ErrRecordNotFound {
			return domain.ErrNotFound
		}
		if err != nil {
			return err
		}

		history, tra, cols, err := apply(&po)
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
		if tra != nil {
			// Chép tenant và chi nhánh từ chính phiếu: dòng sổ này thuộc về đúng
			// nơi đã chi tiền, không phải nơi người bấm đang đứng.
			tra.TenantID = po.TenantID
			tra.ShopID = po.ShopID
			tra.PurchaseOrderID = po.ID
			if err := tx.Create(tra).Error; err != nil {
				return err
			}
		}

		result = &po

		return nil
	})
	if err != nil {
		return nil, err
	}

	return result, nil
}

// ---------- Duyệt: chỗ DUY NHẤT phiếu mua chạm vào kho ----------

func (r *purchaseOrderRepository) Approve(ctx context.Context, id uint, a domain.PurchaseApproval) (*domain.PurchaseOrder, error) {
	var result *domain.PurchaseOrder

	err := r.db.WithContext(ctx).Transaction(func(tx *gorm.DB) error {
		var po domain.PurchaseOrder
		err := tx.Clauses(clause.Locking{Strength: "UPDATE"}).
			Preload("Items", func(q *gorm.DB) *gorm.DB { return q.Order("id ASC") }).
			Where("id = ?", id).Take(&po).Error
		if err == gorm.ErrRecordNotFound {
			return domain.ErrNotFound
		}
		if err != nil {
			return err
		}

		if err := chanChungTuKhacChiNhanh(ctx, tx, po.ShopID); err != nil {
			return err
		}

		// Kiểm TRONG khoá dòng: hai người cùng bấm Duyệt thì người thứ hai đọc được
		// trạng thái người thứ nhất vừa ghi, và dừng lại. Kiểm ngoài khoá là cộng
		// kho hai lần cho một phiếu.
		if po.Status != domain.PurchaseStatusDraft {
			return domain.ErrPurchaseLocked
		}
		if len(po.Items) == 0 {
			return domain.ErrPurchaseEmpty
		}

		if err := congVaoKho(tx, &po, a); err != nil {
			return err
		}

		now := time.Now()
		po.Status = domain.PurchaseStatusApproved
		po.ApprovedAt = &now
		cols := []string{"Status", "ApprovedAt"}
		if a.ActorID > 0 {
			actor := a.ActorID
			po.HandledBy = &actor
			cols = append(cols, "HandledBy")
		}
		if err := tx.Model(&po).Select(cols).Updates(&po).Error; err != nil {
			return err
		}

		note := strings.TrimSpace(a.Note)
		if note == "" {
			note = "Duyệt phiếu — hàng vào kho"
		}
		if err := tx.Create(&domain.PurchaseOrderHistory{
			PurchaseOrderID: po.ID,
			FromStatus:      domain.PurchaseStatusDraft,
			ToStatus:        domain.PurchaseStatusApproved,
			Note:            note,
			ChangedBy:       actorRef(a.ActorID),
		}).Error; err != nil {
			return err
		}

		result = &po

		return nil
	})
	if err != nil {
		return nil, err
	}

	return result, nil
}

// congVaoKho cộng hàng của phiếu vào tồn kho và ghi bút toán.
//
// Gộp theo BIẾN THỂ trước khi ghi: một phiếu mua hai dòng cùng một món (mua hai
// đợt giá khác nhau) chỉ được sinh MỘT bút toán, nếu không thì cặp số
// trước/sau của hai dòng chồng lên nhau và sổ kho đọc lên như bị nhảy cóc.
//
// Số cộng vào kho là BaseQuantity — số đã quy đổi ra đơn vị tính chính. Đây là
// chỗ bản v2 sai: nó nhân hệ số lần nữa ngay lúc ghi kho, trong khi dòng hàng đã
// lưu sẵn một con số quy đổi khác.
func congVaoKho(tx *gorm.DB, po *domain.PurchaseOrder, a domain.PurchaseApproval) error {
	theoBienThe := make(map[uint]int, len(po.Items))
	tienTheoBienThe := make(map[uint]float64, len(po.Items))
	lo := make(map[uint][]string, len(po.Items))
	// Hàng vào kho theo TỪNG LÔ: một phiếu mua cùng một mặt hàng có thể chia hai
	// lô hai hạn dùng, và từ migration 0047 mỗi lô là một dòng tồn riêng.
	loTheoBienThe := make(map[uint][]domain.LoNhapSo, len(po.Items))
	ids := make([]uint, 0, len(po.Items))

	for _, it := range po.Items {
		if it.ProductVariantID == nil || it.BaseQuantity <= 0 {
			continue
		}
		vid := *it.ProductVariantID
		if _, seen := theoBienThe[vid]; !seen {
			ids = append(ids, vid)
		}
		theoBienThe[vid] += it.BaseQuantity
		if so := strings.TrimSpace(it.LotNumber); so != "" && !slices.Contains(lo[vid], so) {
			lo[vid] = append(lo[vid], so)
		}

		// Giá của LÔ là giá một đơn vị chính, cùng cách quy đổi với giá vốn bên
		// dưới: mua 1 thùng 24 cái giá 240.000 thì lô ấy ghi 10.000 một cái.
		giaLo := it.UnitCost
		if it.UnitRatio > 0 {
			giaLo = it.UnitCost / it.UnitRatio
		}
		loTheoBienThe[vid] = append(loTheoBienThe[vid], domain.LoNhapSo{
			LoNhap: domain.LoNhap{
				LotNumber:  strings.TrimSpace(it.LotNumber),
				ExpireDate: it.ExpireDate,
				UnitCost:   giaLo,
			},
			Quantity: it.BaseQuantity,
		})

		// Giá vốn ghi lại là giá MỘT ĐƠN VỊ TÍNH CHÍNH, không phải giá một thùng.
		// Mua 1 thùng 24 cái giá 240.000 thì giá vốn một cái là 10.000 — ghi thẳng
		// 240.000 vào là mọi báo cáo lãi gộp sau đó đều lỗ.
		//
		// Cộng dồn THÀNH TIỀN chứ không gán đè: một phiếu có thể mua cùng một món
		// làm hai dòng với hai giá (hai lô, hai đơn vị). Gán đè là giá vốn của cả
		// lô hàng bằng giá của dòng cuối cùng — mua 10 cái giá 10.000 rồi 90 cái
		// giá 20.000 thì cả 100 cái ghi 20.000.
		motDonVi := it.UnitCost
		if it.UnitRatio > 0 {
			motDonVi = it.UnitCost / it.UnitRatio
		}
		tienTheoBienThe[vid] += motDonVi * float64(it.BaseQuantity)
	}
	if len(ids) == 0 {
		return nil
	}
	slices.Sort(ids)

	// Unscoped: mặt hàng có thể đã ngừng bán sau khi phiếu được lập. Hàng đã về
	// tay cửa hàng thì vẫn phải ghi nhận vào kho, không thì phiếu không đóng được.
	var variants []domain.ProductVariant
	if err := tx.Unscoped().Clauses(clause.Locking{Strength: "UPDATE"}).
		Where("id IN ?", ids).Order("id ASC").Find(&variants).Error; err != nil {
		return err
	}
	coThat := make(map[uint]bool, len(variants))
	for _, v := range variants {
		coThat[v.ID] = true
	}

	// Hàng về kho của CHI NHÁNH ĐÃ LẬP PHIẾU, chốt từ lúc lập — không phải kho
	// của người đang bấm nút duyệt. Thủ kho ở Quận 1 duyệt giúp một phiếu mua cho
	// Quận 7 là chuyện thường; hàng vẫn phải vào sổ của Quận 7.
	shopID := po.ShopID
	if shopID == 0 {
		var err error
		if shopID, err = chiNhanhCuaRequest(tx.Statement.Context, tx); err != nil {
			return err
		}
	}

	poID := po.ID
	actor := actorRef(a.ActorID)

	for _, vid := range ids {
		if !coThat[vid] {
			// Biến thể đã bị xoá cứng: không còn chỗ nào để cộng tồn. Bỏ qua dòng này
			// thay vì chặn cả lượt duyệt — hàng thực tế đã nằm trong kho rồi.
			continue
		}

		truoc, sau, err := ghiTonChiNhanhLo(tx, shopID, vid, ChuyenKho{
			Delta:     theoBienThe[vid],
			ChoPhepAm: true,
			Lo:        loTheoBienThe[vid],
			RefType:   domain.KhoRefPhieuMua,
			RefID:     poID,
		})
		if err != nil {
			return err
		}

		// Bình quân gia quyền theo số lượng thật vào kho.
		giaMotDonVi := 0.0
		if theoBienThe[vid] > 0 {
			giaMotDonVi = tienTheoBienThe[vid] / float64(theoBienThe[vid])
		}

		// Giá vốn mới nhất là giá vừa mua. Ghi ở mức biến thể (chỗ trang tồn kho
		// đọc để tính giá trị kho) chứ không đụng sản phẩm cha — mỗi biến thể có
		// giá nhập riêng.
		if a.UpdateCost {
			cost := giaMotDonVi
			if err := tx.Unscoped().Model(&domain.ProductVariant{}).
				Where("id = ?", vid).
				Update("cost_price", cost).Error; err != nil {
				return err
			}
		}

		unitCost := giaMotDonVi
		if err := tx.Create(&domain.InventoryTransaction{
			ShopID:           shopID,
			ProductVariantID: vid,
			Type:             "import",
			Quantity:         theoBienThe[vid],
			QuantityBefore:   truoc,
			QuantityAfter:    sau,
			ReferenceType:    "purchase_order",
			ReferenceID:      &poID,
			UnitCost:         &unitCost,
			Note:             ghiChuNhapKho(po.POCode, a.Note, lo[vid]),
			CreatedBy:        actor,
		}).Error; err != nil {
			return err
		}
	}

	return nil
}

// ghiChuNhapKho dựng ghi chú cho bút toán: luôn có mã phiếu để dò ngược, kèm
// số lô và lời người duyệt nếu có.
//
// Số lô nằm ở ĐÂY chứ không thành một chiều của tồn kho: sổ kho là chỗ duy nhất
// tra ngược được "lô này về theo phiếu nào" khi nhà cung cấp báo thu hồi.
func ghiChuNhapKho(poCode, note string, lots []string) string {
	ghi := "Nhập theo phiếu mua " + poCode
	if len(lots) > 0 {
		ghi += " (lô " + strings.Join(lots, ", ") + ")"
	}
	if note = strings.TrimSpace(note); note != "" {
		ghi += " — " + note
	}

	return ghi
}

// ---------- Xoá ----------

// Delete xoá mềm phiếu. Tầng service chỉ cho gọi với phiếu lưu tạm — phiếu đã
// duyệt phải nằm lại trong sổ vì kho đã đổi theo nó.
func (r *purchaseOrderRepository) Delete(ctx context.Context, id uint) error {
	// Đọc phiếu TRƯỚC khi xoá, chỉ để biết nó thuộc kho nào. Xoá thẳng theo id là
	// đường ngắn nhất để một người ở kho 2 dọn sạch sổ mua hàng của kho 1.
	var po domain.PurchaseOrder
	err := r.db.WithContext(ctx).Select("id", "shop_id").Where("id = ?", id).Take(&po).Error
	if err == gorm.ErrRecordNotFound {
		return domain.ErrNotFound
	}
	if err != nil {
		return err
	}
	if err := chanChungTuKhacChiNhanh(ctx, r.db, po.ShopID); err != nil {
		return err
	}

	res := r.db.WithContext(ctx).Delete(&domain.PurchaseOrder{}, id)
	if res.Error != nil {
		return res.Error
	}
	if res.RowsAffected == 0 {
		return domain.ErrNotFound
	}

	return nil
}
