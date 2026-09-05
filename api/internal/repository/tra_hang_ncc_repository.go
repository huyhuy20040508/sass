package repository

import (
	"context"
	"fmt"
	"math"
	"slices"
	"strings"
	"time"

	"gorm.io/gorm"
	"gorm.io/gorm/clause"

	"sass-api/internal/domain"
)

type supplierReturnRepository struct {
	db *gorm.DB
}

func NewSupplierReturnRepository(db *gorm.DB) domain.SupplierReturnRepository {
	return &supplierReturnRepository{db: db}
}

// ---------- Liệt kê ----------

func applySupplierReturnFilter(q *gorm.DB, f domain.SupplierReturnFilter) *gorm.DB {
	if kw := strings.TrimSpace(f.Keyword); kw != "" {
		like := "%" + kw + "%"
		q = q.Where("(return_code LIKE ? OR supplier_code LIKE ? OR supplier_name LIKE ? OR note LIKE ?)",
			like, like, like, like)
	}
	if st := trangThaiLoc(f.Status); len(st) > 0 {
		q = q.Where("status IN ?", st)
	}
	if f.SupplierID > 0 {
		q = q.Where("supplier_id = ?", f.SupplierID)
	}
	// Cắt theo chi nhánh phát sinh chứng từ. Bỏ qua khi = 0 (xem gộp cả cửa
	// hàng) — chỉ có ở báo cáo và khi người dùng chủ động chọn "tất cả".
	if f.ShopID > 0 {
		q = q.Where("shop_id = ?", f.ShopID)
	}
	// Ngày rỗng thì KHÔNG lọc — xem chú thích cùng chỗ bên phiếu mua.
	if from := strings.TrimSpace(f.FromDate); from != "" {
		q = q.Where("created_at >= ?", from+" 00:00:00")
	}
	if to := strings.TrimSpace(f.ToDate); to != "" {
		q = q.Where("created_at <= ?", to+" 23:59:59")
	}

	return q
}

func supplierReturnOrderBy(sort string) string {
	switch sort {
	case "oldest":
		return "supplier_returns.id ASC"
	case "total_desc":
		return "supplier_returns.total_amount DESC, supplier_returns.id DESC"
	case "total_asc":
		return "supplier_returns.total_amount ASC, supplier_returns.id DESC"
	case "document_desc":
		// Ngày chứng từ trống xuống cuối, không lên đầu như MySQL xếp NULL.
		return "supplier_returns.document_date IS NULL, supplier_returns.document_date DESC, supplier_returns.id DESC"
	default:
		return "supplier_returns.id DESC"
	}
}

func (r *supplierReturnRepository) List(ctx context.Context, f domain.SupplierReturnFilter) ([]domain.SupplierReturn, int64, error) {
	q := applySupplierReturnFilter(r.db.WithContext(ctx).Model(&domain.SupplierReturn{}), f)

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

	var list []domain.SupplierReturn
	err := q.Preload("Items", func(q *gorm.DB) *gorm.DB { return q.Order("id ASC") }).
		Order(supplierReturnOrderBy(f.Sort)).
		Offset((page - 1) * size).
		Limit(size).
		Find(&list).Error
	if err != nil {
		return nil, 0, err
	}

	return list, tong, r.napTen(ctx, list)
}

// napTen tra TÊN người lập, nhân viên mua và chi nhánh cho cả trang — mỗi loại
// đúng một câu truy vấn.
//
// Không Preload quan hệ: `created_by` trỏ vào users, mà bảng đó còn mang mật
// khẩu băm và khoá phiên.
func (r *supplierReturnRepository) napTen(ctx context.Context, list []domain.SupplierReturn) error {
	nguoiIDs := make([]uint, 0, len(list))
	nvIDs := make([]uint, 0, len(list))
	cnIDs := make([]uint, 0, len(list))
	for _, sr := range list {
		if sr.CreatedBy != nil && *sr.CreatedBy > 0 && !slices.Contains(nguoiIDs, *sr.CreatedBy) {
			nguoiIDs = append(nguoiIDs, *sr.CreatedBy)
		}
		if sr.PurchaserID != nil && *sr.PurchaserID > 0 && !slices.Contains(nvIDs, *sr.PurchaserID) {
			nvIDs = append(nvIDs, *sr.PurchaserID)
		}
		if sr.ShopID > 0 && !slices.Contains(cnIDs, sr.ShopID) {
			cnIDs = append(cnIDs, sr.ShopID)
		}
	}

	// Unscoped ở cả ba: người lập có thể đã nghỉ, chi nhánh có thể đã đóng —
	// phiếu cũ vẫn phải in ra được tên.
	tenNguoi := make(map[uint]string, len(nguoiIDs))
	if len(nguoiIDs) > 0 {
		var rows []struct {
			ID       uint
			FullName string
		}
		if err := r.db.WithContext(ctx).Unscoped().Table("users").
			Select("id, COALESCE(full_name, '') AS full_name").
			Where("id IN ?", nguoiIDs).Scan(&rows).Error; err != nil {
			return err
		}
		for _, n := range rows {
			tenNguoi[n.ID] = n.FullName
		}
	}

	type nhanVien struct {
		ID       uint
		Code     string
		FullName string
	}
	tenNV := make(map[uint]nhanVien, len(nvIDs))
	if len(nvIDs) > 0 {
		var rows []nhanVien
		if err := r.db.WithContext(ctx).Unscoped().Table("employees").
			Select("id, COALESCE(code, '') AS code, COALESCE(full_name, '') AS full_name").
			Where("id IN ?", nvIDs).Scan(&rows).Error; err != nil {
			return err
		}
		for _, n := range rows {
			tenNV[n.ID] = n
		}
	}

	tenCN := make(map[uint]string, len(cnIDs))
	if len(cnIDs) > 0 {
		var rows []struct {
			ID   uint
			Name string
		}
		if err := r.db.WithContext(ctx).Unscoped().Table("shops").
			Select("id, COALESCE(name, '') AS name").
			Where("id IN ?", cnIDs).Scan(&rows).Error; err != nil {
			return err
		}
		for _, n := range rows {
			tenCN[n.ID] = n.Name
		}
	}

	for i := range list {
		if list[i].CreatedBy != nil {
			list[i].CreatedByName = tenNguoi[*list[i].CreatedBy]
		}
		if list[i].PurchaserID != nil {
			nv := tenNV[*list[i].PurchaserID]
			list[i].PurchaserCode = nv.Code
			list[i].PurchaserName = nv.FullName
		}
		list[i].BranchName = tenCN[list[i].ShopID]
	}

	return nil
}

func (r *supplierReturnRepository) FindByID(ctx context.Context, id uint) (*domain.SupplierReturn, error) {
	var sr domain.SupplierReturn
	err := r.db.WithContext(ctx).
		Preload("Items", func(q *gorm.DB) *gorm.DB { return q.Order("id ASC") }).
		Where("id = ?", id).Take(&sr).Error
	if err == gorm.ErrRecordNotFound {
		return nil, domain.ErrNotFound
	}
	if err != nil {
		return nil, err
	}
	// Phiếu của chi nhánh khác thì coi như không có — xem chanChungTuKhacChiNhanh.
	if err := chanChungTuKhacChiNhanh(ctx, r.db, sr.ShopID); err != nil {
		return nil, err
	}

	mot := []domain.SupplierReturn{sr}
	if err := r.napTen(ctx, mot); err != nil {
		return nil, err
	}
	sr = mot[0]

	if err := r.napSoLieuDong(ctx, &sr); err != nil {
		return nil, err
	}

	return &sr, nil
}

// napSoLieuDong gắn "số đã mua" và "tồn còn lại" vào từng dòng.
//
// Hai con số này không nằm trong bảng vì chúng đổi theo thời gian: màn SỬA phiếu
// phải kẹp ô nhập theo tình hình HÔM NAY, không phải lúc phiếu được lập.
func (r *supplierReturnRepository) napSoLieuDong(ctx context.Context, sr *domain.SupplierReturn) error {
	if len(sr.Items) == 0 {
		return nil
	}

	poiIDs := make([]uint, 0, len(sr.Items))
	vIDs := make([]uint, 0, len(sr.Items))
	for _, it := range sr.Items {
		if it.PurchaseOrderItemID != nil && *it.PurchaseOrderItemID > 0 {
			poiIDs = append(poiIDs, *it.PurchaseOrderItemID)
		}
		if it.ProductVariantID != nil && *it.ProductVariantID > 0 && !slices.Contains(vIDs, *it.ProductVariantID) {
			vIDs = append(vIDs, *it.ProductVariantID)
		}
	}

	daMua := make(map[uint]int, len(poiIDs))
	if len(poiIDs) > 0 {
		var rows []struct {
			ID       uint
			Quantity int
		}
		if err := r.db.WithContext(ctx).Table("purchase_order_items").
			Select("id, quantity").Where("id IN ?", poiIDs).Scan(&rows).Error; err != nil {
			return err
		}
		for _, row := range rows {
			daMua[row.ID] = row.Quantity
		}
	}

	ton, err := tonCuaChiNhanh(r.db.WithContext(ctx), sr.ShopID, vIDs)
	if err != nil {
		return err
	}

	for i := range sr.Items {
		it := &sr.Items[i]
		if it.PurchaseOrderItemID != nil {
			it.PurchaseQuantity = daMua[*it.PurchaseOrderItemID]
		}
		it.RemainingStock = 0
		if it.ProductVariantID != nil {
			it.RemainingStock = quyVeDonViTra(ton[*it.ProductVariantID], it.UnitRatio)
		}
	}

	return nil
}

// quyVeDonViTra đổi số đơn vị tính CHÍNH sang đơn vị của dòng phiếu, làm tròn
// XUỐNG — kho còn 5 cái mà một thùng 24 cái thì trả được 0 thùng, không phải 1.
func quyVeDonViTra(soDonViChinh int, tyLe float64) int {
	if tyLe <= 0 {
		return soDonViChinh
	}

	return int(math.Floor(float64(soDonViChinh) / tyLe))
}

func (r *supplierReturnRepository) Stats(ctx context.Context) (domain.SupplierReturnStats, error) {
	var s domain.SupplierReturnStats

	// Tiền chỉ cộng trên phiếu ĐÃ DUYỆT: phiếu lưu tạm chưa trả gì cả.
	err := r.db.WithContext(ctx).Model(&domain.SupplierReturn{}).
		Select(`COUNT(*) AS total,
			SUM(status = ?) AS draft,
			SUM(status = ?) AS approved,
			COALESCE(SUM(CASE WHEN status = ? THEN total_amount ELSE 0 END), 0) AS returned_amount`,
			domain.SupplierReturnDraft, domain.SupplierReturnApproved, domain.SupplierReturnApproved).
		Scan(&s).Error

	return s, err
}

func (r *supplierReturnRepository) Histories(ctx context.Context, returnID uint) ([]domain.SupplierReturnHistory, error) {
	var out []domain.SupplierReturnHistory
	err := r.db.WithContext(ctx).
		Where("supplier_return_id = ?", returnID).
		Order("id ASC").Find(&out).Error

	return out, err
}

// ---------- Phiếu mua trả được ----------

func (r *supplierReturnRepository) PhieuMuaTraDuoc(ctx context.Context, supplierID uint, limit int) ([]domain.SupplierReturnPurchase, error) {
	if limit < 1 || limit > 500 {
		limit = 200
	}

	out := make([]domain.SupplierReturnPurchase, 0, limit)
	// Chỉ phiếu ĐÃ DUYỆT: phiếu lưu tạm chưa đưa hàng vào kho nên chẳng có gì để
	// trả lại.
	err := r.db.WithContext(ctx).Model(&domain.PurchaseOrder{}).
		Where("supplier_id = ? AND status = ?", supplierID, domain.PurchaseStatusApproved).
		Select("id, po_code, document_date, total_amount, approved_at").
		Order("id DESC").
		Limit(limit).
		Scan(&out).Error

	return out, err
}

func (r *supplierReturnRepository) DongPhieuMua(ctx context.Context, purchaseID uint) (*domain.SupplierReturnPurchaseDetail, error) {
	var po domain.PurchaseOrder
	err := r.db.WithContext(ctx).
		Preload("Items", func(q *gorm.DB) *gorm.DB { return q.Order("id ASC") }).
		Where("id = ?", purchaseID).Take(&po).Error
	if err == gorm.ErrRecordNotFound {
		return nil, domain.ErrNotFound
	}
	if err != nil {
		return nil, err
	}
	// Phiếu chưa duyệt thì hàng chưa vào kho — không có gì để trả.
	if po.Status != domain.PurchaseStatusApproved {
		return nil, domain.ErrSupplierReturnNoPurchase
	}

	poiIDs := make([]uint, 0, len(po.Items))
	vIDs := make([]uint, 0, len(po.Items))
	for _, it := range po.Items {
		poiIDs = append(poiIDs, it.ID)
		if it.ProductVariantID != nil && *it.ProductVariantID > 0 && !slices.Contains(vIDs, *it.ProductVariantID) {
			vIDs = append(vIDs, *it.ProductVariantID)
		}
	}

	daTra, err := r.DaTraTheoDongMua(ctx, poiIDs, 0)
	if err != nil {
		return nil, err
	}

	// Tồn của ĐÚNG kho đã nhận hàng về, không phải kho người đang mở màn hình.
	ton, err := tonCuaChiNhanh(r.db.WithContext(ctx), po.ShopID, vIDs)
	if err != nil {
		return nil, err
	}

	sup := uint(0)
	if po.SupplierID != nil {
		sup = *po.SupplierID
	}
	nvMua := uint(0)
	if po.PurchaserID != nil {
		nvMua = *po.PurchaserID
	}

	ct := &domain.SupplierReturnPurchaseDetail{
		ID:           po.ID,
		POCode:       po.POCode,
		SupplierID:   sup,
		PurchaserID:  nvMua,
		VATMode:      po.VATMode,
		VATPercent:   po.VATPercent,
		DocumentDate: po.DocumentDate,
		Lines:        make([]domain.SupplierReturnLine, 0, len(po.Items)),
	}

	for _, it := range po.Items {
		vid, uid := uint(0), uint(0)
		if it.ProductVariantID != nil {
			vid = *it.ProductVariantID
		}
		if it.UnitID != nil {
			uid = *it.UnitID
		}
		pid := uint(0)
		if it.ProductID != nil {
			pid = *it.ProductID
		}

		var han *string
		if it.ExpireDate != nil {
			s := it.ExpireDate.Format("2006-01-02")
			han = &s
		}

		conTraDuoc := it.Quantity - daTra[it.ID]
		tonTheoDonVi := quyVeDonViTra(ton[vid], it.UnitRatio)
		if tonTheoDonVi < conTraDuoc {
			conTraDuoc = tonTheoDonVi
		}
		if conTraDuoc < 0 {
			conTraDuoc = 0
		}

		ct.Lines = append(ct.Lines, domain.SupplierReturnLine{
			PurchaseItemID: it.ID,
			VariantID:      vid,
			ProductID:      pid,
			ProductName:    it.ProductName,
			VariantSKU:     it.VariantSKU,
			VariantName:    it.VariantName,
			Thumbnail:      it.Thumbnail,
			UnitID:         uid,
			UnitName:       it.UnitName,
			UnitRatio:      it.UnitRatio,
			LotNumber:      it.LotNumber,
			ExpireDate:     han,
			Quantity:       it.Quantity,
			UnitCost:       it.UnitCost,
			VATPercent:     it.VATPercent,
			Returned:       daTra[it.ID],
			Stock:          tonTheoDonVi,
			Returnable:     conTraDuoc,
		})
	}

	return ct, nil
}

// DaTraTheoDongMua cộng số đã trả của các phiếu ĐÃ DUYỆT theo từng dòng phiếu mua.
//
// Chỉ đếm phiếu đã duyệt: phiếu lưu tạm chưa trừ kho, giữ chỗ theo nó thì một
// phiếu nháp bỏ quên khoá luôn quyền trả hàng của cả dòng đó.
func (r *supplierReturnRepository) DaTraTheoDongMua(ctx context.Context, purchaseItemIDs []uint, boQua uint) (map[uint]int, error) {
	out := make(map[uint]int, len(purchaseItemIDs))
	if len(purchaseItemIDs) == 0 {
		return out, nil
	}

	q := r.db.WithContext(ctx).Table("supplier_return_items AS i").
		Joins("JOIN supplier_returns s ON s.id = i.supplier_return_id AND s.deleted_at IS NULL").
		Where("i.purchase_order_item_id IN ?", purchaseItemIDs).
		Where("s.status = ?", domain.SupplierReturnApproved)
	if boQua > 0 {
		q = q.Where("s.id <> ?", boQua)
	}

	var rows []struct {
		PurchaseOrderItemID uint
		DaTra               int
	}
	err := q.Select("i.purchase_order_item_id, COALESCE(SUM(i.quantity), 0) AS da_tra").
		Group("i.purchase_order_item_id").
		Scan(&rows).Error
	if err != nil {
		return nil, err
	}
	for _, row := range rows {
		out[row.PurchaseOrderItemID] = row.DaTra
	}

	return out, nil
}

// ---------- Tạo ----------

// Create tạo phiếu trong MỘT transaction.
//
// Mã phiếu dùng mã tạm để lấy ID (ràng buộc UNIQUE), sau đó đổi thành mã hiển
// thị — cùng cách sinh mã với phiếu mua và đơn hàng.
//
// KHÔNG đụng tới tồn kho: phiếu mới lập luôn là phiếu lưu tạm, hàng chỉ rời kho
// ở Approve.
func (r *supplierReturnRepository) Create(ctx context.Context, sr *domain.SupplierReturn) error {
	shopID, err := chiNhanhCuaRequest(ctx, r.db)
	if err != nil {
		return err
	}
	sr.ShopID = shopID

	return r.db.WithContext(ctx).Transaction(func(tx *gorm.DB) error {
		items := sr.Items
		sr.Items = nil
		sr.ReturnCode = fmt.Sprintf("TMP%d", time.Now().UnixNano())

		if err := tx.Create(sr).Error; err != nil {
			return err
		}

		ma, err := maChungTu(ctx, tx, domain.LoaiTraHangNCC, sr.ShopID, &domain.SupplierReturn{}, "return_code",
			fmt.Sprintf("PTH%s%04d", time.Now().Format("20060102"), sr.ID))
		if err != nil {
			return err
		}
		sr.ReturnCode = ma
		if err := tx.Model(sr).Update("return_code", sr.ReturnCode).Error; err != nil {
			return err
		}

		for i := range items {
			items[i].SupplierReturnID = sr.ID
		}
		if len(items) > 0 {
			if err := tx.Create(&items).Error; err != nil {
				return err
			}
		}
		sr.Items = items

		return tx.Create(&domain.SupplierReturnHistory{
			SupplierReturnID: sr.ID,
			FromStatus:       "",
			ToStatus:         sr.Status,
			Note:             "Lập phiếu trả, lưu tạm",
			ChangedBy:        sr.CreatedBy,
		}).Error
	})
}

// ---------- Sửa ----------

// Update sửa phiếu + THAY TOÀN BỘ danh sách hàng dưới khoá dòng.
//
// Thay hết dòng cũ thay vì đối chiếu từng dòng: phiếu chỉ sửa được khi còn LƯU
// TẠM (điều kiện do tầng service kiểm trong mutate), mà phiếu lưu tạm thì chưa
// có gì bám vào dòng hàng của nó.
func (r *supplierReturnRepository) Update(
	ctx context.Context,
	id uint,
	mutate func(sr *domain.SupplierReturn) ([]string, []domain.SupplierReturnItem, error),
) (*domain.SupplierReturn, error) {
	var result *domain.SupplierReturn

	err := r.db.WithContext(ctx).Transaction(func(tx *gorm.DB) error {
		var sr domain.SupplierReturn
		err := tx.Clauses(clause.Locking{Strength: "UPDATE"}).
			Preload("Items", func(q *gorm.DB) *gorm.DB { return q.Order("id ASC") }).
			Where("id = ?", id).Take(&sr).Error
		if err == gorm.ErrRecordNotFound {
			return domain.ErrNotFound
		}
		if err != nil {
			return err
		}

		if err := chanChungTuKhacChiNhanh(ctx, tx, sr.ShopID); err != nil {
			return err
		}

		// Điều kiện sửa được kiểm TRONG khoá dòng: kiểm trước khi gọi thì một lượt
		// duyệt chen vào giữa sẽ bị danh sách hàng mới xoá đè lên.
		cols, items, err := mutate(&sr)
		if err != nil {
			return err
		}

		if err := tx.Model(&sr).Select(cols).Updates(&sr).Error; err != nil {
			return err
		}

		if err := tx.Where("supplier_return_id = ?", sr.ID).
			Delete(&domain.SupplierReturnItem{}).Error; err != nil {
			return err
		}
		for i := range items {
			items[i].ID = 0
			items[i].SupplierReturnID = sr.ID
		}
		if len(items) > 0 {
			if err := tx.Create(&items).Error; err != nil {
				return err
			}
		}
		sr.Items = items

		result = &sr

		return nil
	})
	if err != nil {
		return nil, err
	}

	return result, nil
}

// ---------- Duyệt: chỗ DUY NHẤT phiếu trả chạm vào kho ----------

func (r *supplierReturnRepository) Approve(ctx context.Context, id uint, a domain.SupplierReturnApproval) (*domain.SupplierReturn, error) {
	var result *domain.SupplierReturn

	err := r.db.WithContext(ctx).Transaction(func(tx *gorm.DB) error {
		var sr domain.SupplierReturn
		err := tx.Clauses(clause.Locking{Strength: "UPDATE"}).
			Preload("Items", func(q *gorm.DB) *gorm.DB { return q.Order("id ASC") }).
			Where("id = ?", id).Take(&sr).Error
		if err == gorm.ErrRecordNotFound {
			return domain.ErrNotFound
		}
		if err != nil {
			return err
		}

		if err := chanChungTuKhacChiNhanh(ctx, tx, sr.ShopID); err != nil {
			return err
		}

		// Kiểm TRONG khoá dòng: hai người cùng bấm Duyệt thì người thứ hai đọc được
		// trạng thái người thứ nhất vừa ghi và dừng lại. Kiểm ngoài khoá là trừ kho
		// hai lần cho một phiếu.
		if sr.Status != domain.SupplierReturnDraft {
			return domain.ErrSupplierReturnLocked
		}
		if len(sr.Items) == 0 {
			return domain.ErrSupplierReturnEmpty
		}

		// Kiểm lại trần trả hàng NGAY TRƯỚC khi trừ kho: giữa lúc lập phiếu và lúc
		// bấm duyệt, một phiếu trả khác có thể đã duyệt xong và ăn hết phần còn lại.
		if err := kiemTranTraHang(tx, &sr); err != nil {
			return err
		}

		if err := truKhoTraHang(tx, &sr, a); err != nil {
			return err
		}

		now := time.Now()
		sr.Status = domain.SupplierReturnApproved
		sr.ApprovedAt = &now
		cols := []string{"Status", "ApprovedAt"}
		if a.ActorID > 0 {
			actor := a.ActorID
			sr.HandledBy = &actor
			cols = append(cols, "HandledBy")
		}
		if err := tx.Model(&sr).Select(cols).Updates(&sr).Error; err != nil {
			return err
		}

		note := strings.TrimSpace(a.Note)
		if note == "" {
			note = "Duyệt phiếu trả — hàng rời kho"
		}
		if err := tx.Create(&domain.SupplierReturnHistory{
			SupplierReturnID: sr.ID,
			FromStatus:       domain.SupplierReturnDraft,
			ToStatus:         domain.SupplierReturnApproved,
			Note:             note,
			ChangedBy:        actorRef(a.ActorID),
		}).Error; err != nil {
			return err
		}

		result = &sr

		return nil
	})
	if err != nil {
		return nil, err
	}

	return result, nil
}

// kiemTranTraHang so số trên phiếu với phần CÒN ĐƯỢC TRẢ của từng dòng phiếu mua.
//
// Chạy trong cùng transaction với lượt trừ kho, sau khi phiếu đã bị khoá dòng.
func kiemTranTraHang(tx *gorm.DB, sr *domain.SupplierReturn) error {
	poiIDs := make([]uint, 0, len(sr.Items))
	for _, it := range sr.Items {
		if it.PurchaseOrderItemID != nil && *it.PurchaseOrderItemID > 0 {
			poiIDs = append(poiIDs, *it.PurchaseOrderItemID)
		}
	}
	if len(poiIDs) == 0 {
		return nil
	}

	var muaRows []struct {
		ID       uint
		Quantity int
	}
	if err := tx.Table("purchase_order_items").
		Select("id, quantity").Where("id IN ?", poiIDs).Scan(&muaRows).Error; err != nil {
		return err
	}
	daMua := make(map[uint]int, len(muaRows))
	for _, row := range muaRows {
		daMua[row.ID] = row.Quantity
	}

	var traRows []struct {
		PurchaseOrderItemID uint
		DaTra               int
	}
	err := tx.Table("supplier_return_items AS i").
		Joins("JOIN supplier_returns s ON s.id = i.supplier_return_id AND s.deleted_at IS NULL").
		Where("i.purchase_order_item_id IN ?", poiIDs).
		Where("s.status = ? AND s.id <> ?", domain.SupplierReturnApproved, sr.ID).
		Select("i.purchase_order_item_id, COALESCE(SUM(i.quantity), 0) AS da_tra").
		Group("i.purchase_order_item_id").
		Scan(&traRows).Error
	if err != nil {
		return err
	}
	daTra := make(map[uint]int, len(traRows))
	for _, row := range traRows {
		daTra[row.PurchaseOrderItemID] = row.DaTra
	}

	// Cộng dồn theo dòng phiếu mua: một phiếu trả có thể tách cùng một dòng mua
	// thành hai dòng trả, và trần là của cả hai cộng lại.
	dangTra := make(map[uint]int, len(sr.Items))
	for _, it := range sr.Items {
		if it.PurchaseOrderItemID != nil {
			dangTra[*it.PurchaseOrderItemID] += it.Quantity
		}
	}

	for poiID, so := range dangTra {
		if so > daMua[poiID]-daTra[poiID] {
			return domain.ErrSupplierReturnQuaSo
		}
	}

	return nil
}

// truKhoTraHang trừ hàng của phiếu khỏi tồn kho và ghi bút toán.
//
// Gộp theo BIẾN THỂ trước khi ghi: một phiếu trả hai dòng cùng một món (hai lô)
// chỉ được sinh MỘT bút toán, không thì cặp số trước/sau của hai dòng chồng lên
// nhau và sổ kho đọc lên như bị nhảy cóc.
//
// choPhepAm = false: kho không đủ hàng thì cả lượt duyệt cuộn lại. Trả về hàng
// mình không có là một câu chuyện phải sửa ở đầu vào, không phải một dòng tồn âm.
func truKhoTraHang(tx *gorm.DB, sr *domain.SupplierReturn, a domain.SupplierReturnApproval) error {
	theoBienThe := make(map[uint]int, len(sr.Items))
	tienTheoBienThe := make(map[uint]float64, len(sr.Items))
	lo := make(map[uint][]string, len(sr.Items))
	// Trả lô nào thì trừ đúng lô ấy. Để FIFO tự chọn là trả lô A cho bên bán mà
	// trong sổ lô B vơi đi — và lần kiểm kê sau không ai hiểu vì sao.
	loTheoBienThe := make(map[uint][]domain.LoNhapSo, len(sr.Items))
	ids := make([]uint, 0, len(sr.Items))

	for _, it := range sr.Items {
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
		loTheoBienThe[vid] = append(loTheoBienThe[vid], domain.LoNhapSo{
			LoNhap:   domain.LoNhap{LotNumber: strings.TrimSpace(it.LotNumber)},
			Quantity: it.BaseQuantity,
		})

		// Giá ghi vào sổ kho là giá MỘT ĐƠN VỊ TÍNH CHÍNH, không phải giá một thùng
		// — cùng quy ước với lượt nhập ở phiếu mua.
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

	// Unscoped: mặt hàng có thể đã ngừng bán sau khi phiếu mua được duyệt. Hàng
	// vẫn nằm trong kho nên vẫn phải trả đi được.
	var variants []domain.ProductVariant
	if err := tx.Unscoped().Clauses(clause.Locking{Strength: "UPDATE"}).
		Where("id IN ?", ids).Order("id ASC").Find(&variants).Error; err != nil {
		return err
	}
	coThat := make(map[uint]bool, len(variants))
	for _, v := range variants {
		coThat[v.ID] = true
	}

	// Hàng rời kho của CHI NHÁNH ĐÃ LẬP PHIẾU, chốt từ lúc lập — không phải kho
	// của người đang bấm nút duyệt.
	shopID := sr.ShopID
	if shopID == 0 {
		var err error
		if shopID, err = chiNhanhCuaRequest(tx.Statement.Context, tx); err != nil {
			return err
		}
	}

	srID := sr.ID
	actor := actorRef(a.ActorID)

	for _, vid := range ids {
		if !coThat[vid] {
			// Biến thể đã bị xoá cứng: không còn dòng tồn nào để trừ. Bỏ qua thay vì
			// chặn cả lượt duyệt — hàng thực tế đã rời kho rồi.
			continue
		}

		truoc, sau, err := ghiTonChiNhanhLo(tx, shopID, vid, ChuyenKho{
			Delta:   -theoBienThe[vid],
			Lo:      loTheoBienThe[vid],
			RefType: domain.KhoRefTraNCC,
			RefID:   srID,
		})
		if err != nil {
			return err
		}

		giaMotDonVi := 0.0
		if theoBienThe[vid] > 0 {
			giaMotDonVi = tienTheoBienThe[vid] / float64(theoBienThe[vid])
		}
		unitCost := giaMotDonVi

		// KHÔNG đụng vào giá vốn: trả hàng đi không làm giá mua của cửa hàng đổi.
		if err := tx.Create(&domain.InventoryTransaction{
			ShopID:           shopID,
			ProductVariantID: vid,
			Type:             "export",
			Quantity:         theoBienThe[vid],
			QuantityBefore:   truoc,
			QuantityAfter:    sau,
			ReferenceType:    "supplier_return",
			ReferenceID:      &srID,
			UnitCost:         &unitCost,
			Note:             ghiChuTraHang(sr.ReturnCode, a.Note, lo[vid]),
			CreatedBy:        actor,
		}).Error; err != nil {
			return err
		}
	}

	return nil
}

// ghiChuTraHang dựng ghi chú cho bút toán: luôn có mã phiếu để dò ngược, kèm số
// lô và lời người duyệt nếu có.
func ghiChuTraHang(code, note string, lots []string) string {
	ghi := "Trả nhà cung cấp theo phiếu " + code
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
func (r *supplierReturnRepository) Delete(ctx context.Context, id uint) error {
	// Đọc phiếu TRƯỚC khi xoá, chỉ để biết nó thuộc kho nào — xoá thẳng theo id là
	// đường ngắn nhất để người ở kho 2 dọn sổ của kho 1.
	var sr domain.SupplierReturn
	err := r.db.WithContext(ctx).Select("id", "shop_id").Where("id = ?", id).Take(&sr).Error
	if err == gorm.ErrRecordNotFound {
		return domain.ErrNotFound
	}
	if err != nil {
		return err
	}
	if err := chanChungTuKhacChiNhanh(ctx, r.db, sr.ShopID); err != nil {
		return err
	}

	res := r.db.WithContext(ctx).Delete(&domain.SupplierReturn{}, id)
	if res.Error != nil {
		return res.Error
	}
	if res.RowsAffected == 0 {
		return domain.ErrNotFound
	}

	return nil
}
