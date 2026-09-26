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

type phieuDieuChinhRepository struct{ db *gorm.DB }

func NewPhieuDieuChinhRepository(db *gorm.DB) domain.PhieuDieuChinhRepository {
	return &phieuDieuChinhRepository{db: db}
}

// ---------- Liệt kê ----------

// trangThaiTuKho dịch bộ lọc "Trạng thái kho" của màn hình về trạng thái phiếu.
//
// Kho chỉ đổi lúc duyệt nên hai khái niệm không độc lập: "đã xử lý" = phiếu đã
// duyệt, "đã từ chối" = phiếu bị từ chối, "chưa xử lý" = phiếu còn ở lưu tạm
// hoặc chờ duyệt.
func trangThaiTuKho(v string) []string {
	switch strings.TrimSpace(v) {
	case domain.DieuChinhKhoXong:
		return []string{domain.DieuChinhApproved}
	case domain.DieuChinhKhoTuChoi:
		return []string{domain.DieuChinhRejected}
	case domain.DieuChinhKhoChoXuLy:
		return []string{domain.DieuChinhDraft, domain.DieuChinhPending}
	}

	return nil
}

// idTuChuoi tách chuỗi "3,7,12" thành danh sách id. Giá trị lạ bị bỏ, không báo
// lỗi: bộ lọc hỏng thì trang phải hiện danh sách chứ không phải một câu lỗi.
func idTuChuoi(v string) []uint {
	v = strings.TrimSpace(v)
	if v == "" {
		return nil
	}

	out := make([]uint, 0, 4)
	for _, phan := range strings.Split(v, ",") {
		var id uint
		if _, err := fmt.Sscanf(strings.TrimSpace(phan), "%d", &id); err == nil && id > 0 {
			out = append(out, id)
		}
	}

	return out
}

func applyDieuChinhFilter(q *gorm.DB, f domain.DieuChinhFilter) *gorm.DB {
	if kw := strings.TrimSpace(f.Keyword); kw != "" {
		like := "%" + kw + "%"
		q = q.Where("(adjustment_code LIKE ? OR note LIKE ?)", like, like)
	}
	if lo := strings.TrimSpace(f.Type); lo != "" && lo != "all" {
		q = q.Where("type = ?", lo)
	}
	if st := trangThaiLoc(f.Status); len(st) > 0 {
		q = q.Where("status IN ?", st)
	}
	// Lọc theo cột "Trạng thái kho" chồng lên lọc trạng thái phiếu: hai ô cùng
	// bật thì phiếu phải khớp CẢ HAI.
	if st := trangThaiTuKho(f.WarehouseStatus); len(st) > 0 {
		q = q.Where("status IN ?", st)
	}
	if ids := idTuChuoi(f.CreatedBy); len(ids) > 0 {
		q = q.Where("created_by IN ?", ids)
	}

	// Phiếu điều chỉnh thuộc về ĐÚNG MỘT kho — khác phiếu điều chuyển hai đầu.
	if f.ShopID > 0 {
		q = q.Where("shop_id = ?", f.ShopID)
	}

	// Ngày rỗng thì KHÔNG lọc — cùng quy ước với các phiếu khác.
	if from := strings.TrimSpace(f.FromDate); from != "" {
		q = q.Where("created_at >= ?", from+" 00:00:00")
	}
	if to := strings.TrimSpace(f.ToDate); to != "" {
		q = q.Where("created_at <= ?", to+" 23:59:59")
	}

	return q
}

func dieuChinhOrderBy(sort string) string {
	switch sort {
	case "oldest":
		return "stock_adjustments.id ASC"
	case "code_asc":
		return "stock_adjustments.adjustment_code ASC"
	case "code_desc":
		return "stock_adjustments.adjustment_code DESC"
	}

	return "stock_adjustments.id DESC"
}

func (r *phieuDieuChinhRepository) List(
	ctx context.Context, f domain.DieuChinhFilter,
) ([]domain.PhieuDieuChinh, int64, error) {
	q := applyDieuChinhFilter(r.db.WithContext(ctx).Model(&domain.PhieuDieuChinh{}), f)

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

	var list []domain.PhieuDieuChinh
	err := q.Preload("Items", func(q *gorm.DB) *gorm.DB { return q.Order("id ASC") }).
		Order(dieuChinhOrderBy(f.Sort)).
		Offset((page - 1) * size).
		Limit(size).
		Find(&list).Error
	if err != nil {
		return nil, 0, err
	}

	return list, tong, r.napTen(ctx, list)
}

// napTen tra TÊN kho, người lập và người duyệt cho cả trang — mỗi loại đúng một
// câu truy vấn.
//
// Không Preload quan hệ: `created_by` trỏ vào users, mà bảng đó còn mang mật
// khẩu băm và khoá phiên. Unscoped vì chi nhánh có thể đã đóng, người dùng có
// thể đã nghỉ — phiếu cũ vẫn phải in ra được tên.
func (r *phieuDieuChinhRepository) napTen(ctx context.Context, list []domain.PhieuDieuChinh) error {
	cnIDs := make([]uint, 0, len(list))
	nguoiIDs := make([]uint, 0, len(list)*2)

	them := func(ds *[]uint, id uint) {
		if id > 0 && !slices.Contains(*ds, id) {
			*ds = append(*ds, id)
		}
	}
	for _, p := range list {
		them(&cnIDs, p.ShopID)
		if p.CreatedBy != nil {
			them(&nguoiIDs, *p.CreatedBy)
		}
		if p.HandledBy != nil {
			them(&nguoiIDs, *p.HandledBy)
		}
	}

	tenCN, err := r.tenTheoID(ctx, "shops", cnIDs)
	if err != nil {
		return err
	}
	tenNguoi, err := r.tenTheoID(ctx, "users", nguoiIDs)
	if err != nil {
		return err
	}

	for i := range list {
		list[i].ShopName = tenCN[list[i].ShopID]
		if list[i].CreatedBy != nil {
			list[i].CreatedByName = tenNguoi[*list[i].CreatedBy]
		}
		// Người DUYỆT chỉ có nghĩa khi phiếu đã duyệt: cột `handled_by` cũng ghi
		// người từ chối, và in tên họ vào ô "Người duyệt" là nói sai chuyện đã xảy ra.
		if list[i].Status == domain.DieuChinhApproved && list[i].HandledBy != nil {
			list[i].ApproverName = tenNguoi[*list[i].HandledBy]
		}
		list[i].WarehouseStatus = list[i].TrangThaiKho()

		// Trạng thái nhập kho của từng dòng suy từ phiếu: kho đổi một lần cho cả
		// phiếu nên không dòng nào lệch khỏi dòng khác.
		trangThaiDong := "pending"
		if list[i].Status == domain.DieuChinhApproved {
			trangThaiDong = "stocked"
		}
		for j := range list[i].Items {
			list[i].Items[j].InventoryStatus = trangThaiDong
		}
	}

	return nil
}

// tenTheoID tra tên của một loạt id trong một bảng. `shops` dùng cột `name`,
// `users` dùng `full_name` — gộp bằng COALESCE để nơi gọi không phải biết.
func (r *phieuDieuChinhRepository) tenTheoID(
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

func (r *phieuDieuChinhRepository) FindByID(ctx context.Context, id uint) (*domain.PhieuDieuChinh, error) {
	var p domain.PhieuDieuChinh
	err := r.db.WithContext(ctx).
		Preload("Items", func(q *gorm.DB) *gorm.DB { return q.Order("id ASC") }).
		Where("id = ?", id).Take(&p).Error
	if errors.Is(err, gorm.ErrRecordNotFound) {
		return nil, domain.ErrNotFound
	}
	if err != nil {
		return nil, err
	}
	if err := chanChungTuKhacChiNhanh(ctx, r.db, p.ShopID); err != nil {
		return nil, err
	}

	mot := []domain.PhieuDieuChinh{p}
	if err := r.napTen(ctx, mot); err != nil {
		return nil, err
	}
	p = mot[0]

	// Tồn HÔM NAY và danh sách lô ở kho của phiếu: màn sửa kẹp lại ô số lượng và
	// đổ ô chọn lô. Phiếu lập tuần trước có thể đã không còn đủ hàng để duyệt.
	if err := r.napLoHienTai(ctx, &p); err != nil {
		return nil, err
	}

	return &p, nil
}

// napLoHienTai gắn danh sách lô đang có tại kho vào từng dòng, và cập nhật cột
// `Quantity` (tồn của lô) theo sổ HÔM NAY.
//
// Bản chụp lúc lập phiếu vẫn nằm trong database; cái ghi đè ở đây chỉ là con số
// trả cho màn hình — người sửa phiếu phải thấy tồn thật lúc họ đang sửa.
func (r *phieuDieuChinhRepository) napLoHienTai(ctx context.Context, p *domain.PhieuDieuChinh) error {
	ids := make([]uint, 0, len(p.Items))
	for _, it := range p.Items {
		if it.ProductVariantID != nil && *it.ProductVariantID > 0 {
			ids = append(ids, *it.ProductVariantID)
		}
	}
	if len(ids) == 0 {
		return nil
	}

	lo, err := moiLoCuaBienThe(r.db.WithContext(ctx), p.ShopID, ids)
	if err != nil {
		return err
	}

	for i := range p.Items {
		vid := p.Items[i].ProductVariantID
		if vid == nil {
			continue
		}
		p.Items[i].Lots = lo[*vid]

		// Phiếu ĐÃ DUYỆT giữ nguyên bản chụp: con số "tồn trước khi chỉnh" của nó
		// là dữ liệu lịch sử, thay bằng tồn hôm nay là nói sai chuyện đã xảy ra.
		if p.Status == domain.DieuChinhApproved || p.Status == domain.DieuChinhRejected {
			continue
		}
		for _, l := range lo[*vid] {
			if l.LotNumber == p.Items[i].LotNumber {
				p.Items[i].Quantity = l.Quantity

				break
			}
		}
	}

	return nil
}

// ---------- Lập phiếu ----------

// Create ghi phiếu ở trạng thái đã cho (lưu tạm hoặc chờ duyệt). KHÔNG đụng tới
// kho — kho chỉ đổi ở Approve.
//
// Mã phiếu dùng mã tạm để lấy ID (ràng buộc UNIQUE), sau đó đổi thành mã hiển
// thị — cùng cách sinh mã với các phiếu khác.
func (r *phieuDieuChinhRepository) Create(ctx context.Context, p *domain.PhieuDieuChinh) error {
	return r.db.WithContext(ctx).Transaction(func(tx *gorm.DB) error {
		items := p.Items
		p.Items = nil
		p.AdjustmentCode = fmt.Sprintf("TMP%d", time.Now().UnixNano())

		if err := tx.Create(p).Error; err != nil {
			return err
		}

		ma, err := maChungTu(ctx, tx, domain.LoaiDieuChinhTon, p.ShopID,
			&domain.PhieuDieuChinh{}, "adjustment_code",
			fmt.Sprintf("DCT%s%04d", time.Now().Format("20060102"), p.ID))
		if err != nil {
			return err
		}
		p.AdjustmentCode = ma
		if err := tx.Model(p).Update("adjustment_code", ma).Error; err != nil {
			return err
		}

		for i := range items {
			items[i].ID = 0
			items[i].StockAdjustmentID = p.ID
		}
		if len(items) > 0 {
			if err := tx.Create(&items).Error; err != nil {
				return err
			}
		}
		p.Items = items

		return tx.Create(&domain.PhieuDieuChinhHistory{
			StockAdjustmentID: p.ID,
			FromStatus:        "",
			ToStatus:          p.Status,
			Note:              "Lập phiếu điều chỉnh tồn kho",
			ChangedBy:         p.CreatedBy,
		}).Error
	})
}

// ---------- Sửa ----------

func (r *phieuDieuChinhRepository) Update(
	ctx context.Context, id uint,
	mutate func(p *domain.PhieuDieuChinh) ([]string, []domain.PhieuDieuChinhItem, error),
) (*domain.PhieuDieuChinh, error) {
	var result *domain.PhieuDieuChinh

	err := r.db.WithContext(ctx).Transaction(func(tx *gorm.DB) error {
		p, err := khoaPhieuDieuChinh(ctx, tx, id)
		if err != nil {
			return err
		}

		// Điều kiện sửa được kiểm TRONG khoá dòng: kiểm trước khi gọi thì một lượt
		// duyệt chen vào giữa sẽ bị danh sách hàng mới xoá đè lên, và kho đã đổi
		// theo danh sách cũ.
		cols, items, err := mutate(p)
		if err != nil {
			return err
		}

		if err := tx.Model(p).Select(cols).Updates(p).Error; err != nil {
			return err
		}

		// items == nil nghĩa là chỉ đổi cột phiếu, giữ nguyên dòng hàng.
		if items != nil {
			if err := tx.Where("stock_adjustment_id = ?", p.ID).
				Delete(&domain.PhieuDieuChinhItem{}).Error; err != nil {
				return err
			}
			for i := range items {
				items[i].ID = 0
				items[i].StockAdjustmentID = p.ID
			}
			if len(items) > 0 {
				if err := tx.Create(&items).Error; err != nil {
					return err
				}
			}
			p.Items = items
		}
		result = p

		return nil
	})
	if err != nil {
		return nil, err
	}

	return result, nil
}

// khoaPhieuDieuChinh đọc phiếu dưới khoá dòng và chốt nó thuộc chi nhánh đang
// làm việc. Mọi lượt đổi trạng thái đều bắt đầu từ đây.
func khoaPhieuDieuChinh(ctx context.Context, tx *gorm.DB, id uint) (*domain.PhieuDieuChinh, error) {
	var p domain.PhieuDieuChinh
	err := tx.Clauses(clause.Locking{Strength: "UPDATE"}).
		Preload("Items", func(q *gorm.DB) *gorm.DB { return q.Order("id ASC") }).
		Where("id = ?", id).Take(&p).Error
	if errors.Is(err, gorm.ErrRecordNotFound) {
		return nil, domain.ErrNotFound
	}
	if err != nil {
		return nil, err
	}
	if err := chanChungTuKhacChiNhanh(ctx, tx, p.ShopID); err != nil {
		return nil, err
	}

	return &p, nil
}

// ---------- Gửi duyệt ----------

func (r *phieuDieuChinhRepository) Submit(
	ctx context.Context, id uint, a domain.DieuChinhApproval,
) (*domain.PhieuDieuChinh, error) {
	return r.doiTrangThai(ctx, id, a, domain.DieuChinhPending,
		[]string{domain.DieuChinhDraft}, "Gửi phiếu đi duyệt")
}

// ---------- Từ chối ----------

func (r *phieuDieuChinhRepository) Reject(
	ctx context.Context, id uint, a domain.DieuChinhApproval,
) (*domain.PhieuDieuChinh, error) {
	return r.doiTrangThai(ctx, id, a, domain.DieuChinhRejected,
		[]string{domain.DieuChinhDraft, domain.DieuChinhPending}, "Từ chối phiếu điều chỉnh")
}

// doiTrangThai đổi trạng thái phiếu mà KHÔNG đụng tới kho — dùng cho gửi duyệt
// và từ chối.
//
// Kiểm trạng thái nguồn TRONG khoá dòng: hai người cùng bấm thì người thứ hai
// đọc được kết quả người thứ nhất vừa ghi và dừng lại.
func (r *phieuDieuChinhRepository) doiTrangThai(
	ctx context.Context, id uint, a domain.DieuChinhApproval,
	den string, tu []string, ghiChu string,
) (*domain.PhieuDieuChinh, error) {
	var result *domain.PhieuDieuChinh

	err := r.db.WithContext(ctx).Transaction(func(tx *gorm.DB) error {
		p, err := khoaPhieuDieuChinh(ctx, tx, id)
		if err != nil {
			return err
		}
		if !slices.Contains(tu, p.Status) {
			return domain.ErrDieuChinhSaiTrangThai
		}

		truoc := p.Status
		p.Status = den
		cols := []string{"Status"}
		if den == domain.DieuChinhRejected {
			p.RejectReason = strings.TrimSpace(a.Note)
			cols = append(cols, "RejectReason")
		}
		if a.ActorID > 0 {
			actor := a.ActorID
			p.HandledBy = &actor
			cols = append(cols, "HandledBy")
		}
		if err := tx.Model(p).Select(cols).Updates(p).Error; err != nil {
			return err
		}

		note := strings.TrimSpace(a.Note)
		if note == "" {
			note = ghiChu
		}
		if err := tx.Create(&domain.PhieuDieuChinhHistory{
			StockAdjustmentID: p.ID,
			FromStatus:        truoc,
			ToStatus:          den,
			Note:              note,
			ChangedBy:         actorRef(a.ActorID),
		}).Error; err != nil {
			return err
		}
		result = p

		return nil
	})
	if err != nil {
		return nil, err
	}

	return r.FindByID(ctx, result.ID)
}

// ---------- Duyệt: kho đổi số ----------

// Approve là lúc DUY NHẤT tồn kho đổi vì phiếu này.
//
// Nhận cả phiếu LƯU TẠM lẫn phiếu CHỜ DUYỆT: màn lập phiếu của v2 có nút "Duyệt"
// bấm thẳng từ lúc đang gõ, không bắt đi qua bước gửi duyệt.
func (r *phieuDieuChinhRepository) Approve(
	ctx context.Context, id uint, a domain.DieuChinhApproval,
) (*domain.PhieuDieuChinh, error) {
	var result *domain.PhieuDieuChinh

	err := r.db.WithContext(ctx).Transaction(func(tx *gorm.DB) error {
		p, err := khoaPhieuDieuChinh(ctx, tx, id)
		if err != nil {
			return err
		}

		// Kiểm TRONG khoá dòng: hai người cùng bấm Duyệt thì người thứ hai đọc được
		// trạng thái người thứ nhất vừa ghi và dừng lại. Kiểm ngoài khoá là cộng
		// kho hai lần cho một phiếu.
		if p.Status != domain.DieuChinhDraft && p.Status != domain.DieuChinhPending {
			return domain.ErrDieuChinhLocked
		}
		if len(p.Items) == 0 {
			return domain.ErrDieuChinhEmpty
		}

		if err := ghiKhoTheoPhieu(tx, p, a); err != nil {
			return err
		}

		now := time.Now()
		truoc := p.Status
		p.Status = domain.DieuChinhApproved
		p.ApprovedAt = &now
		cols := []string{"Status", "ApprovedAt"}
		if a.ActorID > 0 {
			actor := a.ActorID
			p.HandledBy = &actor
			cols = append(cols, "HandledBy")
		}
		if err := tx.Model(p).Select(cols).Updates(p).Error; err != nil {
			return err
		}

		note := strings.TrimSpace(a.Note)
		if note == "" {
			note = "Duyệt phiếu điều chỉnh — số tồn đã đổi"
		}
		if err := tx.Create(&domain.PhieuDieuChinhHistory{
			StockAdjustmentID: p.ID,
			FromStatus:        truoc,
			ToStatus:          domain.DieuChinhApproved,
			Note:              note,
			ChangedBy:         actorRef(a.ActorID),
		}).Error; err != nil {
			return err
		}
		result = p

		return nil
	})
	if err != nil {
		return nil, err
	}

	return r.FindByID(ctx, result.ID)
}

// ghiKhoTheoPhieu áp số lệch của từng dòng vào kho của phiếu.
//
// LÔ ĐI THEO DÒNG. Mỗi dòng nói rõ lô nào lệch bao nhiêu, nên lượt ghi kho chỉ
// định thẳng lô đó — KHÔNG để FIFO tự chọn: người vừa đếm kho biết chính xác lô
// nào thiếu, và rút nhầm lô khác là làm hỏng đúng con số họ vừa sửa.
//
// Mỗi biến thể tách làm hai lượt ghi — cụm BỚT rồi cụm CỘNG — vì ghiTonChiNhanhLo
// nhận một Delta có dấu kèm cụm lô cùng chiều. Bớt trước để trần tồn (nếu có)
// từ chối trước khi kho kịp tăng.
//
// CHO PHÉP TỒN ÂM ở mức tổng: đây là màn CHỮA số âm, không phải màn gây ra nó.
// Chặn ở đây thì lô đang âm sẵn (do bán vượt tồn ở quầy) không cân đối về 0
// được. Trần thật nằm ở kiemTonLo bên dưới: phiếu thường không được bớt quá số
// lô đang có, phiếu cân đối thì chỉ cộng nên không chạm trần.
func ghiKhoTheoPhieu(tx *gorm.DB, p *domain.PhieuDieuChinh, a domain.DieuChinhApproval) error {
	ids := make([]uint, 0, len(p.Items))
	for _, it := range p.Items {
		if it.ProductVariantID != nil && it.AdjustQuantity != 0 && !slices.Contains(ids, *it.ProductVariantID) {
			ids = append(ids, *it.ProductVariantID)
		}
	}
	if len(ids) == 0 {
		return domain.ErrDieuChinhEmpty
	}

	// Sắp id rồi khoá theo đúng thứ tự đó — cùng thứ tự với luồng bán và luồng
	// điều chuyển, nên chạy song song không khoá chéo nhau.
	slices.Sort(ids)

	// Unscoped: mặt hàng có thể đã ngừng bán sau khi phiếu được lập. Hàng vẫn nằm
	// trong kho nên vẫn phải chỉnh được.
	var variants []domain.ProductVariant
	if err := tx.Unscoped().Clauses(clause.Locking{Strength: "UPDATE"}).
		Where("id IN ?", ids).Order("id ASC").Find(&variants).Error; err != nil {
		return err
	}
	coThat := make(map[uint]bool, len(variants))
	for _, v := range variants {
		coThat[v.ID] = true
	}

	actor := actorRef(a.ActorID)

	for _, vid := range ids {
		if !coThat[vid] {
			// Biến thể đã bị xoá cứng: không còn dòng tồn nào để đụng vào.
			continue
		}

		them, bot, gia, soLo := chiaLoTheoChieu(p, vid)
		ghiChu := ghiChuDieuChinh(p.AdjustmentCode, a.Note, soLo)

		if len(bot) > 0 {
			if p.Type != domain.DieuChinhLoaiCanDoi {
				if err := kiemTonLo(tx, p.ShopID, vid, bot); err != nil {
					return err
				}
			}
			if err := ghiMotLuot(tx, p, vid, -tongLo(bot), bot, gia, ghiChu, actor); err != nil {
				return err
			}
		}
		if len(them) > 0 {
			if err := ghiMotLuot(tx, p, vid, tongLo(them), them, gia, ghiChu, actor); err != nil {
				return err
			}
		}
	}

	return nil
}

// chiaLoTheoChieu tách các dòng của một biến thể thành cụm CỘNG và cụm BỚT, kèm
// giá vốn bình quân của các dòng và danh sách số lô để ghi chú.
//
// Số lượng trong LoNhapSo luôn DƯƠNG: dấu nằm ở Delta của lượt ghi, và
// ghiTonChiNhanhLo đọc dấu ấy để biết cộng vào lô hay rút khỏi lô.
func chiaLoTheoChieu(p *domain.PhieuDieuChinh, vid uint) (them, bot []domain.LoNhapSo, gia float64, soLo []string) {
	var tongTien float64
	var tongSo int

	for _, it := range p.Items {
		if it.ProductVariantID == nil || *it.ProductVariantID != vid || it.AdjustQuantity == 0 {
			continue
		}
		so := it.AdjustQuantity
		chieu := &them
		if so < 0 {
			so = -so
			chieu = &bot
		}
		*chieu = append(*chieu, domain.LoNhapSo{
			LoNhap: domain.LoNhap{
				LotNumber:  it.LotNumber,
				ExpireDate: it.ExpireDate,
				UnitCost:   it.UnitCost,
			},
			Quantity: so,
		})
		tongTien += it.UnitCost * float64(so)
		tongSo += so
		if it.LotNumber != "" && !slices.Contains(soLo, it.LotNumber) {
			soLo = append(soLo, it.LotNumber)
		}
	}
	if tongSo > 0 {
		gia = tongTien / float64(tongSo)
	}

	return them, bot, gia, soLo
}

func tongLo(lo []domain.LoNhapSo) int {
	so := 0
	for _, l := range lo {
		so += l.Quantity
	}

	return so
}

// kiemTonLo chốt từng lô bị bớt vẫn còn đủ hàng NGAY LÚC DUYỆT, dưới khoá dòng.
//
// Lúc lập phiếu service đã kiểm một lần, nhưng giữa lúc lập và lúc bấm duyệt
// hàng có thể đã bán đi — chốt thật phải nằm ở nơi trừ kho.
func kiemTonLo(tx *gorm.DB, shopID, vid uint, bot []domain.LoNhapSo) error {
	for _, l := range bot {
		var dong domain.TonKhoLo
		err := tx.Clauses(clause.Locking{Strength: "UPDATE"}).
			Where("shop_id = ? AND product_variant_id = ? AND lot_number = ?", shopID, vid, l.LotNumber).
			Take(&dong).Error
		con := dong.Quantity
		if errors.Is(err, gorm.ErrRecordNotFound) {
			con = 0
		} else if err != nil {
			return err
		}
		if con-l.Quantity < 0 {
			ten := l.LotNumber
			if ten == "" {
				ten = "Không xác định"
			}

			return fmt.Errorf("%w: %s lô %s chỉ còn %d, phiếu đang bớt %d",
				domain.ErrDieuChinhThieuTon, tenBienThe(tx, vid), ten, con, l.Quantity)
		}
	}

	return nil
}

// ghiMotLuot ghi một lượt đổi kho (một chiều) và một bút toán sổ kho.
func ghiMotLuot(
	tx *gorm.DB, p *domain.PhieuDieuChinh, vid uint, delta int, lo []domain.LoNhapSo,
	gia float64, ghiChu string, actor *uint,
) error {
	phieuID := p.ID
	truoc, sau, err := ghiTonChiNhanhLo(tx, p.ShopID, vid, ChuyenKho{
		Delta:     delta,
		ChoPhepAm: true,
		Lo:        lo,
		RefType:   domain.KhoRefDieuChinh,
		RefID:     phieuID,
	})
	if err != nil {
		return err
	}

	return tx.Create(&domain.InventoryTransaction{
		ShopID:           p.ShopID,
		ProductVariantID: vid,
		Type:             "adjustment",
		Quantity:         delta,
		QuantityBefore:   truoc,
		QuantityAfter:    sau,
		ReferenceType:    domain.KhoRefDieuChinh,
		ReferenceID:      &phieuID,
		UnitCost:         &gia,
		Note:             ghiChu,
		CreatedBy:        actor,
	}).Error
}

// ghiChuDieuChinh dựng ghi chú cho bút toán: luôn có mã phiếu để dò ngược, kèm
// số lô và lời người duyệt nếu có.
func ghiChuDieuChinh(code, note string, lots []string) string {
	ghi := "Điều chỉnh tồn theo phiếu " + code
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
func (r *phieuDieuChinhRepository) Delete(ctx context.Context, id uint) error {
	var p domain.PhieuDieuChinh
	err := r.db.WithContext(ctx).
		Select("id", "shop_id").Where("id = ?", id).Take(&p).Error
	if errors.Is(err, gorm.ErrRecordNotFound) {
		return domain.ErrNotFound
	}
	if err != nil {
		return err
	}
	if err := chanChungTuKhacChiNhanh(ctx, r.db, p.ShopID); err != nil {
		return err
	}

	res := r.db.WithContext(ctx).Delete(&domain.PhieuDieuChinh{}, id)
	if res.Error != nil {
		return res.Error
	}
	if res.RowsAffected == 0 {
		return domain.ErrNotFound
	}

	return nil
}

// ---------- Tra thông tin hàng ----------

// ThongTinHang — xem docblock ở PhieuDieuChinhRepository.
func (r *phieuDieuChinhRepository) ThongTinHang(
	ctx context.Context, shopID uint, variantIDs []uint,
) (map[uint]domain.DieuChinhHang, error) {
	out := make(map[uint]domain.DieuChinhHang, len(variantIDs))
	if len(variantIDs) == 0 {
		return out, nil
	}

	// Không Unscoped: lập phiếu MỚI thì chỉ chỉnh được hàng còn trong danh mục.
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
	lo, err := moiLoCuaBienThe(r.db.WithContext(ctx), shopID, variantIDs)
	if err != nil {
		return nil, err
	}

	for _, row := range rows {
		out[row.VariantID] = domain.DieuChinhHang{
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

// moiLoCuaBienThe đọc MỌI lô đang có dòng tại kho, kể cả lô "" và lô đang ÂM.
//
// Khác loCuaBienThe (chỉ lô dương, có số): màn điều chỉnh chính là chỗ chữa
// những lô âm ấy, và lô "Không xác định" là nơi số âm hay dồn về nhất — lọc
// chúng đi thì người dùng không có dòng nào để bấm.
func moiLoCuaBienThe(db *gorm.DB, shopID uint, variantIDs []uint) (map[uint][]domain.TonKhoLo, error) {
	out := make(map[uint][]domain.TonKhoLo, len(variantIDs))
	if shopID == 0 || len(variantIDs) == 0 {
		return out, nil
	}

	var rows []domain.TonKhoLo
	err := db.Model(&domain.TonKhoLo{}).
		Where("shop_id = ? AND product_variant_id IN ?", shopID, variantIDs).
		Order("product_variant_id ASC, expire_date IS NULL, expire_date ASC, id ASC").
		Find(&rows).Error
	if err != nil {
		return nil, err
	}
	for _, r := range rows {
		out[r.ProductVariantID] = append(out[r.ProductVariantID], r)
	}

	return out, nil
}

// ---------- Hàng âm chờ cân đối ----------

// HangAm — xem docblock ở PhieuDieuChinhRepository.
//
// Hai lượt đọc: các lô đang âm tại kho, và tổng số lệch của những phiếu cân đối
// CHƯA duyệt đang nhắm vào chúng. Lô đã có phiếu chờ duyệt bù đủ thì không bày
// ra nữa — bày là người dùng lập thêm một phiếu trùng và kho bị cộng hai lần.
func (r *phieuDieuChinhRepository) HangAm(ctx context.Context, shopID uint) ([]domain.HangAm, error) {
	if shopID == 0 {
		return nil, nil
	}

	var rows []struct {
		VariantID   uint
		ProductID   uint
		SKU         string
		ProductName string
		VariantName string
		UnitName    string
		UnitID      uint
		LotNumber   string
		ExpireDate  *time.Time
		Quantity    int
	}
	err := r.db.WithContext(ctx).
		Table("stock_lots AS sl").
		Joins("JOIN product_variants v ON v.id = sl.product_variant_id").
		Joins("JOIN products p ON p.id = v.product_id").
		Joins("LEFT JOIN product_units u ON u.id = p.unit_id").
		Select(`sl.product_variant_id AS variant_id, p.id AS product_id,
			COALESCE(v.sku, '') AS sku,
			COALESCE(p.name, '') AS product_name,
			COALESCE(v.name, '') AS variant_name,
			COALESCE(u.name, '') AS unit_name,
			COALESCE(p.unit_id, 0) AS unit_id,
			sl.lot_number, sl.expire_date, sl.quantity`).
		Where("sl.shop_id = ? AND sl.quantity < 0", shopID).
		Order("p.name ASC, sl.lot_number ASC").
		Scan(&rows).Error
	if err != nil {
		return nil, err
	}
	if len(rows) == 0 {
		return nil, nil
	}

	cho, err := r.soLechChoDuyet(ctx, shopID)
	if err != nil {
		return nil, err
	}

	out := make([]domain.HangAm, 0, len(rows))
	for _, row := range rows {
		pending := cho[khoaLo{variant: row.VariantID, lo: row.LotNumber}]
		tuongLai := row.Quantity + pending
		// Còn âm sau khi tính hết phiếu đang chờ thì mới cần cân đối tiếp.
		if tuongLai >= 0 {
			continue
		}

		out = append(out, domain.HangAm{
			VariantID:      row.VariantID,
			ProductID:      row.ProductID,
			SKU:            row.SKU,
			ProductName:    row.ProductName,
			VariantName:    row.VariantName,
			UnitName:       row.UnitName,
			UnitID:         row.UnitID,
			LotNumber:      row.LotNumber,
			ExpireDate:     row.ExpireDate,
			Quantity:       row.Quantity,
			PendingAdjust:  pending,
			FutureQuantity: tuongLai,
		})
	}

	return out, nil
}

type khoaLo struct {
	variant uint
	lo      string
}

// ChiNhanhMacDinh — xem docblock ở PhieuDieuChinhRepository.
func (r *phieuDieuChinhRepository) ChiNhanhMacDinh(ctx context.Context) (uint, error) {
	return chiNhanhCuaRequest(ctx, r.db)
}

// soLechChoDuyet cộng số lệch của các phiếu điều chỉnh CHƯA duyệt tại kho, theo
// từng cặp (biến thể, lô).
func (r *phieuDieuChinhRepository) soLechChoDuyet(
	ctx context.Context, shopID uint,
) (map[khoaLo]int, error) {
	var rows []struct {
		VariantID uint
		LotNumber string
		Tong      int
	}
	err := r.db.WithContext(ctx).
		Table("stock_adjustment_items AS i").
		Joins("JOIN stock_adjustments a ON a.id = i.stock_adjustment_id AND a.deleted_at IS NULL").
		Select("i.product_variant_id AS variant_id, i.lot_number, COALESCE(SUM(i.adjust_quantity), 0) AS tong").
		Where("a.shop_id = ? AND a.status IN ?", shopID,
			[]string{domain.DieuChinhDraft, domain.DieuChinhPending}).
		Group("i.product_variant_id, i.lot_number").
		Scan(&rows).Error
	if err != nil {
		return nil, err
	}

	out := make(map[khoaLo]int, len(rows))
	for _, row := range rows {
		out[khoaLo{variant: row.VariantID, lo: row.LotNumber}] = row.Tong
	}

	return out, nil
}
