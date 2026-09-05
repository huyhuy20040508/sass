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

type phieuDieuChuyenRepository struct{ db *gorm.DB }

func NewPhieuDieuChuyenRepository(db *gorm.DB) domain.PhieuDieuChuyenRepository {
	return &phieuDieuChuyenRepository{db: db}
}

// ---------- Liệt kê ----------

func applyDieuChuyenFilter(q *gorm.DB, f domain.DieuChuyenFilter) *gorm.DB {
	if kw := strings.TrimSpace(f.Keyword); kw != "" {
		like := "%" + kw + "%"
		q = q.Where("(transfer_code LIKE ? OR note LIKE ?)", like, like)
	}
	if st := trangThaiLoc(f.Status); len(st) > 0 {
		q = q.Where("status IN ?", st)
	}

	// CẢ HAI ĐẦU. Phiếu mà chi nhánh này gửi đi lẫn phiếu nó nhận về đều là việc
	// của nó: cắt một đầu thì kho nhận không tra ra chứng từ đã làm tồn của mình
	// tăng lên, và người đếm hàng ở đó không có cách nào biết số ở đâu ra.
	if f.ShopID > 0 {
		q = q.Where("(from_shop_id = ? OR to_shop_id = ?)", f.ShopID, f.ShopID)
	}

	if f.VariantID > 0 {
		q = q.Where(`EXISTS (SELECT 1 FROM stock_transfer_items i
			WHERE i.stock_transfer_id = stock_transfers.id AND i.product_variant_id = ?)`, f.VariantID)
	}

	// Ngày rỗng thì KHÔNG lọc — cùng quy ước với phiếu mua và phiếu trả.
	if from := strings.TrimSpace(f.FromDate); from != "" {
		q = q.Where("created_at >= ?", from+" 00:00:00")
	}
	if to := strings.TrimSpace(f.ToDate); to != "" {
		q = q.Where("created_at <= ?", to+" 23:59:59")
	}

	return q
}

func dieuChuyenOrderBy(sort string) string {
	if sort == "oldest" {
		return "stock_transfers.id ASC"
	}

	return "stock_transfers.id DESC"
}

func (r *phieuDieuChuyenRepository) List(
	ctx context.Context, f domain.DieuChuyenFilter,
) ([]domain.PhieuDieuChuyen, int64, error) {
	q := applyDieuChuyenFilter(r.db.WithContext(ctx).Model(&domain.PhieuDieuChuyen{}), f)

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

	var list []domain.PhieuDieuChuyen
	err := q.Preload("Items", func(q *gorm.DB) *gorm.DB { return q.Order("id ASC") }).
		Order(dieuChuyenOrderBy(f.Sort)).
		Offset((page - 1) * size).
		Limit(size).
		Find(&list).Error
	if err != nil {
		return nil, 0, err
	}

	return list, tong, r.napTen(ctx, list)
}

// napTen tra TÊN hai kho, người nhận và người lập cho cả trang — mỗi loại đúng
// một câu truy vấn.
//
// Không Preload quan hệ: `created_by` trỏ vào users, mà bảng đó còn mang mật
// khẩu băm và khoá phiên.
//
// Unscoped ở cả ba: chi nhánh có thể đã đóng, nhân viên có thể đã nghỉ — phiếu
// cũ vẫn phải in ra được tên, không thì bảng danh sách toàn dấu gạch.
func (r *phieuDieuChuyenRepository) napTen(ctx context.Context, list []domain.PhieuDieuChuyen) error {
	cnIDs := make([]uint, 0, len(list)*2)
	nvIDs := make([]uint, 0, len(list))
	nguoiIDs := make([]uint, 0, len(list))

	them := func(ds *[]uint, id uint) {
		if id > 0 && !slices.Contains(*ds, id) {
			*ds = append(*ds, id)
		}
	}
	for _, p := range list {
		them(&cnIDs, p.FromShopID)
		them(&cnIDs, p.ToShopID)
		if p.ReceiverID != nil {
			them(&nvIDs, *p.ReceiverID)
		}
		if p.CreatedBy != nil {
			them(&nguoiIDs, *p.CreatedBy)
		}
	}

	tenCN, err := r.tenTheoID(ctx, "shops", cnIDs)
	if err != nil {
		return err
	}
	tenNV, err := r.tenTheoID(ctx, "employees", nvIDs)
	if err != nil {
		return err
	}
	tenNguoi, err := r.tenTheoID(ctx, "users", nguoiIDs)
	if err != nil {
		return err
	}

	for i := range list {
		list[i].FromShopName = tenCN[list[i].FromShopID]
		list[i].ToShopName = tenCN[list[i].ToShopID]
		if list[i].ReceiverID != nil {
			list[i].ReceiverName = tenNV[*list[i].ReceiverID]
		}
		if list[i].CreatedBy != nil {
			list[i].CreatedByName = tenNguoi[*list[i].CreatedBy]
		}
	}

	return nil
}

// tenTheoID tra tên của một loạt id trong một bảng. `shops` dùng cột `name`, hai
// bảng người dùng `full_name` — gộp bằng COALESCE để nơi gọi không phải biết.
func (r *phieuDieuChuyenRepository) tenTheoID(
	ctx context.Context, bang string, ids []uint,
) (map[uint]string, error) {
	ten := make(map[uint]string, len(ids))
	if len(ids) == 0 {
		return ten, nil
	}

	cot := "COALESCE(full_name, '')"
	if bang == "shops" {
		cot = "COALESCE(name, '')"
	}

	var rows []struct {
		ID  uint
		Ten string
	}
	if err := r.db.WithContext(ctx).Unscoped().Table(bang).
		Select("id, "+cot+" AS ten").
		Where("id IN ?", ids).Scan(&rows).Error; err != nil {
		return nil, err
	}
	for _, n := range rows {
		ten[n.ID] = n.Ten
	}

	return ten, nil
}

func (r *phieuDieuChuyenRepository) Stats(
	ctx context.Context, f domain.DieuChuyenFilter,
) (domain.DieuChuyenStats, error) {
	var st domain.DieuChuyenStats

	// Đếm trên bộ lọc BỎ trạng thái: bốn con số ở đầu trang phải nói về cùng một
	// tập phiếu, nếu không thì bấm lọc "lưu tạm" xong ô "đã duyệt" hiện 0 và
	// người đọc tưởng cửa hàng chưa duyệt phiếu nào.
	khongTrangThai := f
	khongTrangThai.Status = ""

	var rows []struct {
		Status string
		So     int64
		Tien   float64
	}
	err := applyDieuChuyenFilter(r.db.WithContext(ctx).Model(&domain.PhieuDieuChuyen{}), khongTrangThai).
		Select("status, COUNT(*) AS so, COALESCE(SUM(items_amount), 0) AS tien").
		Group("status").Scan(&rows).Error
	if err != nil {
		return st, err
	}

	for _, row := range rows {
		st.Total += row.So
		switch row.Status {
		case domain.DieuChuyenDraft:
			st.Draft = row.So
		case domain.DieuChuyenApproved:
			st.Approved = row.So
			st.TransferredAmount = row.Tien
		}
	}

	return st, nil
}

func (r *phieuDieuChuyenRepository) FindByID(ctx context.Context, id uint) (*domain.PhieuDieuChuyen, error) {
	var pdc domain.PhieuDieuChuyen
	err := r.db.WithContext(ctx).
		Preload("Items", func(q *gorm.DB) *gorm.DB { return q.Order("id ASC") }).
		Where("id = ?", id).Take(&pdc).Error
	if errors.Is(err, gorm.ErrRecordNotFound) {
		return nil, domain.ErrNotFound
	}
	if err != nil {
		return nil, err
	}
	// HAI đầu đều là người trong cuộc: kho gửi và kho nhận cùng phải mở được phiếu,
	// còn chi nhánh thứ ba thì không — xem chanChungTuKhacChiNhanh.
	if err := chanChungTuKhacChiNhanh(ctx, r.db, pdc.FromShopID, pdc.ToShopID); err != nil {
		return nil, err
	}

	mot := []domain.PhieuDieuChuyen{pdc}
	if err := r.napTen(ctx, mot); err != nil {
		return nil, err
	}
	pdc = mot[0]

	// Tồn HÔM NAY ở kho xuất, cho màn sửa kẹp lại ô số lượng: phiếu lập tuần
	// trước có thể đã không còn đủ hàng để duyệt.
	if err := r.napTonKhoXuat(ctx, &pdc); err != nil {
		return nil, err
	}

	return &pdc, nil
}

func (r *phieuDieuChuyenRepository) napTonKhoXuat(ctx context.Context, pdc *domain.PhieuDieuChuyen) error {
	ids := make([]uint, 0, len(pdc.Items))
	for _, it := range pdc.Items {
		if it.ProductVariantID != nil && *it.ProductVariantID > 0 {
			ids = append(ids, *it.ProductVariantID)
		}
	}

	ton, err := tonCuaChiNhanh(r.db.WithContext(ctx), pdc.FromShopID, ids)
	if err != nil {
		return err
	}
	for i := range pdc.Items {
		if vid := pdc.Items[i].ProductVariantID; vid != nil {
			pdc.Items[i].RemainingStock = ton[*vid]
		}
	}

	return nil
}

// ---------- Lập phiếu ----------

// Create ghi phiếu ở trạng thái lưu tạm. KHÔNG đụng tới kho — hàng chỉ đổi kho
// ở Approve.
//
// Mã phiếu dùng mã tạm để lấy ID (ràng buộc UNIQUE), sau đó đổi thành mã hiển
// thị — cùng cách sinh mã với phiếu mua và phiếu trả. Quy tắc đánh số tra theo
// KHO XUẤT: phiếu là việc của kho gửi trước tiên, và mã in trên chứng từ đi
// theo nó.
func (r *phieuDieuChuyenRepository) Create(ctx context.Context, pdc *domain.PhieuDieuChuyen) error {
	return r.db.WithContext(ctx).Transaction(func(tx *gorm.DB) error {
		items := pdc.Items
		pdc.Items = nil
		pdc.TransferCode = fmt.Sprintf("TMP%d", time.Now().UnixNano())

		if err := tx.Create(pdc).Error; err != nil {
			return err
		}

		ma, err := maChungTu(ctx, tx, domain.LoaiDieuChuyen, pdc.FromShopID, &domain.PhieuDieuChuyen{}, "transfer_code",
			fmt.Sprintf("PDC%s%04d", time.Now().Format("20060102"), pdc.ID))
		if err != nil {
			return err
		}
		pdc.TransferCode = ma
		if err := tx.Model(pdc).Update("transfer_code", ma).Error; err != nil {
			return err
		}

		for i := range items {
			items[i].ID = 0
			items[i].StockTransferID = pdc.ID
		}
		if len(items) > 0 {
			if err := tx.Create(&items).Error; err != nil {
				return err
			}
		}
		pdc.Items = items

		return tx.Create(&domain.PhieuDieuChuyenHistory{
			StockTransferID: pdc.ID,
			FromStatus:      "",
			ToStatus:        domain.DieuChuyenDraft,
			Note:            "Lập phiếu điều chuyển",
			ChangedBy:       pdc.CreatedBy,
		}).Error
	})
}

// ---------- Sửa ----------

func (r *phieuDieuChuyenRepository) Update(
	ctx context.Context, id uint,
	mutate func(pdc *domain.PhieuDieuChuyen) ([]string, []domain.PhieuDieuChuyenItem, error),
) (*domain.PhieuDieuChuyen, error) {
	var result *domain.PhieuDieuChuyen

	err := r.db.WithContext(ctx).Transaction(func(tx *gorm.DB) error {
		var pdc domain.PhieuDieuChuyen
		err := tx.Clauses(clause.Locking{Strength: "UPDATE"}).
			Preload("Items", func(q *gorm.DB) *gorm.DB { return q.Order("id ASC") }).
			Where("id = ?", id).Take(&pdc).Error
		if errors.Is(err, gorm.ErrRecordNotFound) {
			return domain.ErrNotFound
		}
		if err != nil {
			return err
		}

		if err := chanChungTuKhacChiNhanh(ctx, tx, pdc.FromShopID, pdc.ToShopID); err != nil {
			return err
		}

		// Điều kiện sửa được kiểm TRONG khoá dòng: kiểm trước khi gọi thì một lượt
		// duyệt chen vào giữa sẽ bị danh sách hàng mới xoá đè lên, và kho đã đổi
		// theo danh sách cũ.
		cols, items, err := mutate(&pdc)
		if err != nil {
			return err
		}

		if err := tx.Model(&pdc).Select(cols).Updates(&pdc).Error; err != nil {
			return err
		}

		if err := tx.Where("stock_transfer_id = ?", pdc.ID).
			Delete(&domain.PhieuDieuChuyenItem{}).Error; err != nil {
			return err
		}
		for i := range items {
			items[i].ID = 0
			items[i].StockTransferID = pdc.ID
		}
		if len(items) > 0 {
			if err := tx.Create(&items).Error; err != nil {
				return err
			}
		}
		pdc.Items = items
		result = &pdc

		return nil
	})
	if err != nil {
		return nil, err
	}

	return result, nil
}

// ---------- Duyệt: hàng đổi kho ----------

// Approve là lúc DUY NHẤT tồn kho đổi vì phiếu này, và nó đổi HAI ĐẦU trong
// CÙNG một transaction.
//
// Ghi được một đầu rồi hỏng đầu kia nghĩa là hàng bốc hơi (trừ kho gửi mà không
// vào kho nhận) hoặc tự sinh ra (ngược lại) — cả hai đều là kiểu sai không lộ
// ra ở đâu cho tới lúc có người đếm hàng thật.
func (r *phieuDieuChuyenRepository) Approve(
	ctx context.Context, id uint, a domain.DieuChuyenApproval,
) (*domain.PhieuDieuChuyen, error) {
	var result *domain.PhieuDieuChuyen

	err := r.db.WithContext(ctx).Transaction(func(tx *gorm.DB) error {
		var pdc domain.PhieuDieuChuyen
		err := tx.Clauses(clause.Locking{Strength: "UPDATE"}).
			Preload("Items", func(q *gorm.DB) *gorm.DB { return q.Order("id ASC") }).
			Where("id = ?", id).Take(&pdc).Error
		if errors.Is(err, gorm.ErrRecordNotFound) {
			return domain.ErrNotFound
		}
		if err != nil {
			return err
		}

		if err := chanChungTuKhacChiNhanh(ctx, tx, pdc.FromShopID, pdc.ToShopID); err != nil {
			return err
		}

		// Kiểm TRONG khoá dòng: hai người cùng bấm Duyệt thì người thứ hai đọc được
		// trạng thái người thứ nhất vừa ghi và dừng lại. Kiểm ngoài khoá là chuyển
		// kho hai lần cho một phiếu.
		if pdc.Status != domain.DieuChuyenDraft {
			return domain.ErrDieuChuyenLocked
		}
		if len(pdc.Items) == 0 {
			return domain.ErrDieuChuyenEmpty
		}

		// Kiểm lại KHO NHẬN ngay trước khi ghi kho: giữa lúc lập phiếu và lúc bấm
		// duyệt, mặt hàng có thể đã bị gán riêng đi chi nhánh khác. Duyệt lúc ấy là
		// đẩy hàng vào một kho không được phép bán nó — hàng nằm chết ở đó.
		if err := chanHangKhacChiNhanh(ctx, tx, pdc.ToShopID, bienTheCuaDieuChuyen(pdc.Items)); err != nil {
			return err
		}

		if err := chuyenKhoHaiDau(tx, &pdc, a); err != nil {
			return err
		}

		now := time.Now()
		pdc.Status = domain.DieuChuyenApproved
		pdc.ApprovedAt = &now
		cols := []string{"Status", "ApprovedAt"}
		if a.ActorID > 0 {
			actor := a.ActorID
			pdc.HandledBy = &actor
			cols = append(cols, "HandledBy")
		}
		if err := tx.Model(&pdc).Select(cols).Updates(&pdc).Error; err != nil {
			return err
		}

		note := strings.TrimSpace(a.Note)
		if note == "" {
			note = "Duyệt phiếu điều chuyển — hàng đổi kho"
		}
		if err := tx.Create(&domain.PhieuDieuChuyenHistory{
			StockTransferID: pdc.ID,
			FromStatus:      domain.DieuChuyenDraft,
			ToStatus:        domain.DieuChuyenApproved,
			Note:            note,
			ChangedBy:       actorRef(a.ActorID),
		}).Error; err != nil {
			return err
		}

		result = &pdc

		return nil
	})
	if err != nil {
		return nil, err
	}

	return r.FindByID(ctx, result.ID)
}

// chuyenKhoHaiDau trừ kho gửi rồi cộng kho nhận, cho từng biến thể.
//
// THỨ TỰ CỐ Ý: trừ TRƯỚC. Kho gửi là nơi có luật "không cho âm", nên nó phải từ
// chối trước khi kho nhận kịp tăng — ngược lại thì một phiếu quá tồn đã cộng
// hàng vào kho nhận rồi mới bị chặn, và tuy giao dịch cuộn lại nhưng luật đã
// nằm sai chỗ.
//
// LÔ ĐI THEO HÀNG. Dòng nào khai số lô thì cả hai đầu dùng đúng số ấy: hàng qua
// kho khác vẫn là lô đó, hạn đó, và lần kiểm kê sau ở kho nhận vẫn tra ra nguồn
// gốc. Không khai lô thì kho gửi rút theo luật kho (FIFO/FEFO) còn kho nhận vào
// lô không xác định — đúng như mọi lượt nhập không biết lô.
func chuyenKhoHaiDau(tx *gorm.DB, pdc *domain.PhieuDieuChuyen, a domain.DieuChuyenApproval) error {
	type gop struct {
		so   int
		tien float64
		lo   []domain.LoNhapSo
		soLo []string
	}

	theoBienThe := make(map[uint]*gop, len(pdc.Items))
	ids := make([]uint, 0, len(pdc.Items))

	for _, it := range pdc.Items {
		if it.ProductVariantID == nil || it.Quantity <= 0 {
			continue
		}
		vid := *it.ProductVariantID
		g, co := theoBienThe[vid]
		if !co {
			g = &gop{}
			theoBienThe[vid] = g
			ids = append(ids, vid)
		}
		g.so += it.Quantity
		g.tien += it.UnitCost * float64(it.Quantity)

		soLo := strings.TrimSpace(it.LotNumber)
		if soLo != "" {
			g.lo = append(g.lo, domain.LoNhapSo{
				LoNhap: domain.LoNhap{
					LotNumber:  soLo,
					ExpireDate: it.ExpireDate,
					UnitCost:   it.UnitCost,
				},
				Quantity: it.Quantity,
			})
			if !slices.Contains(g.soLo, soLo) {
				g.soLo = append(g.soLo, soLo)
			}
		}
	}
	if len(ids) == 0 {
		return domain.ErrDieuChuyenEmpty
	}

	// Sắp id rồi khoá theo đúng thứ tự đó: hai phiếu chuyển chéo nhau cùng lúc mà
	// khoá theo thứ tự khác nhau là kẹt chết (deadlock).
	slices.Sort(ids)

	// Unscoped: mặt hàng có thể đã ngừng bán sau khi phiếu được lập. Hàng vẫn
	// nằm trong kho nên vẫn phải chuyển đi được.
	var variants []domain.ProductVariant
	if err := tx.Unscoped().Clauses(clause.Locking{Strength: "UPDATE"}).
		Where("id IN ?", ids).Order("id ASC").Find(&variants).Error; err != nil {
		return err
	}
	coThat := make(map[uint]bool, len(variants))
	for _, v := range variants {
		coThat[v.ID] = true
	}

	pdcID := pdc.ID
	actor := actorRef(a.ActorID)

	for _, vid := range ids {
		if !coThat[vid] {
			// Biến thể đã bị xoá cứng: không còn dòng tồn nào để trừ, cũng không có
			// gì để cộng sang. Bỏ qua thay vì chặn cả lượt duyệt.
			continue
		}
		g := theoBienThe[vid]
		ghiChu := ghiChuDieuChuyen(pdc.TransferCode, a.Note, g.soLo)

		// --- Đầu GỬI: hàng rời kho xuất ---
		truoc, sau, err := ghiTonChiNhanhLo(tx, pdc.FromShopID, vid, ChuyenKho{
			Delta:   -g.so,
			Lo:      g.lo,
			RefType: domain.KhoRefDieuChuyen,
			RefID:   pdcID,
		})
		if err != nil {
			// Kho không đủ hàng: dịch sang lỗi của chính nghiệp vụ này để câu trả lời
			// nói được "kho xuất không đủ", chứ không phải một câu chung chung.
			if errors.Is(err, domain.ErrOutOfStock) {
				return fmt.Errorf("%w: mặt hàng %s", domain.ErrDieuChuyenThieuTon, tenBienThe(tx, vid))
			}

			return err
		}

		giaMotDonVi := 0.0
		if g.so > 0 {
			giaMotDonVi = g.tien / float64(g.so)
		}
		gia := giaMotDonVi

		if err := tx.Create(&domain.InventoryTransaction{
			ShopID:           pdc.FromShopID,
			ProductVariantID: vid,
			Type:             "export",
			Quantity:         g.so,
			QuantityBefore:   truoc,
			QuantityAfter:    sau,
			ReferenceType:    domain.KhoRefDieuChuyen,
			ReferenceID:      &pdcID,
			UnitCost:         &gia,
			Note:             ghiChu + " → " + tenChiNhanh(tx, pdc.ToShopID),
			CreatedBy:        actor,
		}).Error; err != nil {
			return err
		}

		// --- Đầu NHẬN: hàng vào kho nhập ---
		//
		// ChoPhepAm không cần vì delta dương. Lô truyền y nguyên cụm của đầu gửi:
		// cùng số lô, cùng hạn, cùng giá vốn.
		truoc2, sau2, err := ghiTonChiNhanhLo(tx, pdc.ToShopID, vid, ChuyenKho{
			Delta:   g.so,
			Lo:      g.lo,
			RefType: domain.KhoRefDieuChuyen,
			RefID:   pdcID,
		})
		if err != nil {
			return err
		}

		if err := tx.Create(&domain.InventoryTransaction{
			ShopID:           pdc.ToShopID,
			ProductVariantID: vid,
			Type:             "import",
			Quantity:         g.so,
			QuantityBefore:   truoc2,
			QuantityAfter:    sau2,
			ReferenceType:    domain.KhoRefDieuChuyen,
			ReferenceID:      &pdcID,
			UnitCost:         &gia,
			Note:             ghiChu + " ← " + tenChiNhanh(tx, pdc.FromShopID),
			CreatedBy:        actor,
		}).Error; err != nil {
			return err
		}
	}

	return nil
}

// ghiChuDieuChuyen dựng ghi chú cho bút toán: luôn có mã phiếu để dò ngược, kèm
// số lô và lời người duyệt nếu có.
func ghiChuDieuChuyen(code, note string, lots []string) string {
	ghi := "Điều chuyển theo phiếu " + code
	if len(lots) > 0 {
		ghi += " (lô " + strings.Join(lots, ", ") + ")"
	}
	if note = strings.TrimSpace(note); note != "" {
		ghi += " — " + note
	}

	return ghi
}

// tenChiNhanh / tenBienThe chỉ dùng để ghép chữ vào ghi chú và câu lỗi. Tra
// hỏng thì trả chuỗi rỗng chứ không làm gãy lượt duyệt: một cái tên thiếu trong
// ghi chú không đáng để cuộn lại cả giao dịch chuyển kho.
func tenChiNhanh(tx *gorm.DB, id uint) string {
	var ten string
	_ = tx.Unscoped().Table("shops").Select("COALESCE(name, '')").
		Where("id = ?", id).Limit(1).Scan(&ten).Error

	return ten
}

func tenBienThe(tx *gorm.DB, id uint) string {
	var ten string
	_ = tx.Unscoped().Table("product_variants AS v").
		Joins("JOIN products p ON p.id = v.product_id").
		Select("COALESCE(p.name, '')").
		Where("v.id = ?", id).Limit(1).Scan(&ten).Error

	return ten
}

// ---------- Xoá ----------

// Delete xoá mềm phiếu. Tầng service chỉ cho gọi với phiếu lưu tạm — phiếu đã
// duyệt phải nằm lại trong sổ vì kho hai đầu đã đổi theo nó.
func (r *phieuDieuChuyenRepository) Delete(ctx context.Context, id uint) error {
	// Đọc phiếu TRƯỚC khi xoá, chỉ để biết hai đầu kho là ai.
	var pdc domain.PhieuDieuChuyen
	err := r.db.WithContext(ctx).
		Select("id", "from_shop_id", "to_shop_id").Where("id = ?", id).Take(&pdc).Error
	if errors.Is(err, gorm.ErrRecordNotFound) {
		return domain.ErrNotFound
	}
	if err != nil {
		return err
	}
	if err := chanChungTuKhacChiNhanh(ctx, r.db, pdc.FromShopID, pdc.ToShopID); err != nil {
		return err
	}

	res := r.db.WithContext(ctx).Delete(&domain.PhieuDieuChuyen{}, id)
	if res.Error != nil {
		return res.Error
	}
	if res.RowsAffected == 0 {
		return domain.ErrNotFound
	}

	return nil
}

// ---------- Tra thông tin hàng ở kho xuất ----------

// ThongTinHang — xem docblock ở PhieuDieuChuyenRepository.
//
// Ba lượt đọc cho cả phiếu: hồ sơ mặt hàng, tồn tại kho xuất, và sổ lô tại kho
// xuất. Tra theo từng dòng thì một phiếu ba mươi dòng là chín mươi lượt đọc.
func (r *phieuDieuChuyenRepository) ThongTinHang(
	ctx context.Context, shopID uint, variantIDs []uint,
) (map[uint]domain.DieuChuyenHang, error) {
	out := make(map[uint]domain.DieuChuyenHang, len(variantIDs))
	if len(variantIDs) == 0 {
		return out, nil
	}

	// Không Unscoped: lập phiếu MỚI thì chỉ chuyển được hàng còn trong danh mục.
	// Mặt hàng đã xoá mềm mà vẫn nằm trong kho thì gỡ bằng đường chỉnh kho, chứ
	// đưa lại vào một chứng từ mới là dựng lại thứ vừa bỏ đi.
	var rows []struct {
		VariantID   uint
		ProductID   uint
		ProductName string
		SKU         string
		VariantName string
		UnitName    string
		CostPrice   float64
	}
	err := r.db.WithContext(ctx).
		Table("product_variants AS v").
		Joins("JOIN products p ON p.id = v.product_id AND p.deleted_at IS NULL").
		Joins("LEFT JOIN product_units u ON u.id = p.unit_id").
		Select(`v.id AS variant_id, p.id AS product_id,
			COALESCE(p.name, '') AS product_name,
			COALESCE(v.sku, '') AS sku,
			COALESCE(v.name, '') AS variant_name,
			COALESCE(u.name, '') AS unit_name,
			COALESCE(v.cost_price, p.cost_price, 0) AS cost_price`).
		Where("v.id IN ? AND v.deleted_at IS NULL", variantIDs).
		Scan(&rows).Error
	if err != nil {
		return nil, err
	}

	ton, err := tonCuaChiNhanh(r.db.WithContext(ctx), shopID, variantIDs)
	if err != nil {
		return nil, err
	}
	lo, err := loCuaBienThe(r.db.WithContext(ctx), shopID, variantIDs)
	if err != nil {
		return nil, err
	}

	for _, row := range rows {
		out[row.VariantID] = domain.DieuChuyenHang{
			VariantID:   row.VariantID,
			ProductID:   row.ProductID,
			ProductName: row.ProductName,
			SKU:         row.SKU,
			VariantName: row.VariantName,
			UnitName:    row.UnitName,
			CostPrice:   row.CostPrice,
			Stock:       ton[row.VariantID],
			Lots:        lo[row.VariantID],
		}
	}

	return out, nil
}

// ChanHangKhongThuoc — xem docblock ở PhieuDieuChuyenRepository.
func (r *phieuDieuChuyenRepository) ChanHangKhongThuoc(
	ctx context.Context, shopID uint, variantIDs []uint,
) error {
	return chanHangKhacChiNhanh(ctx, r.db, shopID, variantIDs)
}

// bienTheCuaDieuChuyen gom id biến thể của các dòng hàng — cùng dạng với
// bienTheCuaPhieu / bienTheCuaDon bên ton_kho_chi_nhanh.go.
func bienTheCuaDieuChuyen(items []domain.PhieuDieuChuyenItem) []uint {
	ids := make([]uint, 0, len(items))
	for _, it := range items {
		if it.ProductVariantID != nil && *it.ProductVariantID > 0 {
			ids = append(ids, *it.ProductVariantID)
		}
	}

	return ids
}
