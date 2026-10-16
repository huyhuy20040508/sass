package repository

import (
	"context"
	"errors"
	"fmt"
	"strings"
	"time"

	"sass-api/internal/domain"

	"gorm.io/gorm"
	"gorm.io/gorm/clause"
)

type caLamViecRepository struct{ db *gorm.DB }

func NewCaLamViecRepository(db *gorm.DB) domain.CaLamViecRepository {
	return &caLamViecRepository{db: db}
}

// ghiSoQuy ghi MỘT dòng tiền mặt vào sổ quỹ và tự gắn nó vào ca đang mở của chi
// nhánh.
//
// Là hàm tự do (không phải phương thức) để các luồng khác trong gói repository
// gọi được TRONG CHÍNH transaction của chúng — bán hàng, hoàn tiền trả hàng,
// đổi hàng. Tiền vào két và đơn hàng phải cùng sống hoặc cùng chết: ghi sổ ở một
// transaction riêng nghĩa là một lần sập giữa chừng sẽ để lại đơn đã bán mà két
// không ghi nhận đồng nào, và đó đúng là loại lệch không ai dò ra được.
//
// Không tìm thấy ca đang mở thì vẫn ghi, với shift_id NULL. Bán hàng không bao
// giờ dừng lại vì chưa ai mở ca — xem domain.SoQuy.ShiftID.
//
// CreatedBy để trống với các dòng SINH RA TỰ ĐỘNG (bán hàng, hoàn tiền), và đó
// không phải chỗ còn thiếu: trách nhiệm về két thuộc về người ĐANG TRỰC CA, tức
// là work_shifts.opened_by — chính là điều cả khái niệm "ca" dựng ra để trả lời.
// Ghi thêm một cái tên vào từng dòng chỉ tạo ra hai nguồn sự thật cho cùng một
// câu hỏi. Dòng ghi TAY thì có CreatedBy, vì đó là hành động của một người cụ
// thể chứ không phải hệ quả của một lượt bán.
func ghiSoQuy(tx *gorm.DB, e *domain.SoQuy) error {
	if e.ShopID == 0 {
		// Lỗi LẬP TRÌNH: nơi gọi chưa biết chi nhánh mà đã ghi quỹ. Hỏng ồn ào ở
		// đây tốt hơn một dòng tiền rơi vào chi nhánh do database tự đoán.
		return errors.New("ghi sổ quỹ mà chưa biết chi nhánh nào")
	}
	if e.Amount <= 0 {
		// Không ghi dòng 0 đồng: nó không nói lên điều gì mà vẫn làm dài sổ, và
		// người đối chiếu phải đọc qua nó.
		return nil
	}

	var ca domain.CaLamViec
	err := tx.Where("shop_id = ? AND closed_at IS NULL", e.ShopID).
		Order("id DESC").First(&ca).Error
	switch {
	case err == nil:
		id := ca.ID
		e.ShiftID = &id
	case errors.Is(err, gorm.ErrRecordNotFound):
		e.ShiftID = nil
	default:
		return err
	}

	return tx.Create(e).Error
}

func (r *caLamViecRepository) CaDangMoCua(ctx context.Context, shopID uint) (*domain.CaLamViec, error) {
	var ca domain.CaLamViec
	err := r.db.WithContext(ctx).
		Where("shop_id = ? AND closed_at IS NULL", shopID).
		Order("id DESC").First(&ca).Error
	if errors.Is(err, gorm.ErrRecordNotFound) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	return &ca, nil
}

// MoCa mở ca mới.
//
// KHÔNG kiểm "đã có ca mở chưa" bằng một câu SELECT rồi mới INSERT: hai người
// bấm cùng lúc thì cả hai cùng thấy chưa có ca và cùng mở được. Cứ chèn, để
// ràng buộc duy nhất uq_work_shifts_open(shop_id, closed_mark) của database
// phán — đó là thứ duy nhất đúng dưới đồng thời.
func (r *caLamViecRepository) MoCa(ctx context.Context, ca *domain.CaLamViec) error {
	err := r.db.WithContext(ctx).Transaction(func(tx *gorm.DB) error {
		if err := tx.Create(ca).Error; err != nil {
			return err
		}
		dauCa := ca.OpeningCash
		return ghiNhatKyCa(tx, ca, domain.NhatKyMoCa, ca.OpenedBy, &dauCa, nil, ca.Note, ca.OpenedAt)
	})
	// gorm.ErrDuplicatedKey chứ không phải so chuỗi "1062": GORM đã dịch mã lỗi
	// của driver sang lỗi chuẩn của nó, nên thông báo trả về là "duplicated key
	// not allowed" và không còn chứa con số nào để dò. So chuỗi ở đây là cách
	// hỏng im lặng — người dùng nhận 500 thay vì câu "chi nhánh đang có ca mở".
	if errors.Is(err, gorm.ErrDuplicatedKey) {
		return domain.ErrCaDangMo
	}
	return err
}

func (r *caLamViecRepository) DongCa(
	ctx context.Context, id uint, countedCash float64, dem domain.BangDem, giao, giu float64, note string, closedBy uint,
) (*domain.CaLamViec, error) {
	var result *domain.CaLamViec

	err := r.db.WithContext(ctx).Transaction(func(tx *gorm.DB) error {
		var ca domain.CaLamViec
		// Khoá dòng ca TRƯỚC khi cộng sổ: một lượt bán chen vào giữa lúc cộng và
		// lúc ghi là con số đối chiếu sai ngay tại thời điểm hai bên ký nhận.
		err := tx.Clauses(clause.Locking{Strength: "UPDATE"}).First(&ca, id).Error
		if errors.Is(err, gorm.ErrRecordNotFound) {
			return domain.ErrNotFound
		}
		if err != nil {
			return err
		}
		if !ca.DangMo() {
			return domain.ErrCaDaDong
		}

		tk, err := tongKetCa(tx, ca.ID)
		if err != nil {
			return err
		}

		now := time.Now()
		expected := ca.OpeningCash + tk.TongThu - tk.TongChi
		diff := countedCash - expected

		ca.ClosedAt = &now
		ca.ClosedBy = &closedBy
		ca.CountedCash = &countedCash
		ca.CountedCount = dem
		ca.DeliveredCash, ca.KeptCash = &giao, &giu
		ca.ExpectedCash = &expected
		ca.Difference = &diff
		// Ghi chú đóng ca vào cột riêng, không đè ghi chú lúc mở (migration 0073).
		ca.CloseNote = strings.TrimSpace(note)

		if err := tx.Model(&ca).
			Select("ClosedAt", "ClosedBy", "CountedCash", "CountedCount", "DeliveredCash", "KeptCash", "ExpectedCash", "Difference", "CloseNote").
			Updates(&ca).Error; err != nil {
			return err
		}

		if err := ghiNhatKyCa(tx, &ca, domain.NhatKyDongCa, closedBy, &countedCash, &diff, ca.CloseNote, now); err != nil {
			return err
		}

		ca.TongThu, ca.TongChi, ca.SoDonTienMat = tk.TongThu, tk.TongChi, tk.SoDonTienMat
		result = &ca
		return nil
	})
	return result, err
}

// tongKetCa cộng sổ quỹ của một ca. Dùng chung cho lúc đóng ca (trong khoá) và
// lúc xem lại (ngoài khoá) để hai chỗ không bao giờ cộng theo hai cách.
func tongKetCa(tx *gorm.DB, caID uint) (domain.TongKetSoQuy, error) {
	var out domain.TongKetSoQuy

	var rows []struct {
		Direction string
		Tong      float64
		So        int64
	}
	err := tx.Model(&domain.SoQuy{}).
		Select("direction, COALESCE(SUM(amount), 0) AS tong, COUNT(*) AS so").
		Where("shift_id = ?", caID).
		Group("direction").
		Scan(&rows).Error
	if err != nil {
		return out, err
	}
	for _, row := range rows {
		if row.Direction == domain.SoQuyThu {
			out.TongThu = row.Tong
		} else {
			out.TongChi = row.Tong
		}
	}

	// Số lượt bán thu tiền mặt đếm riêng: người đóng ca đối chiếu con số này với
	// xấp hoá đơn trên tay, nên nó phải là số ĐƠN chứ không phải số dòng sổ.
	err = tx.Model(&domain.SoQuy{}).
		Where("shift_id = ? AND direction = ? AND reference_type = ?",
			caID, domain.SoQuyThu, domain.SoQuyTuDonHang).
		Count(&out.SoDonTienMat).Error

	return out, err
}

func (r *caLamViecRepository) TongKet(ctx context.Context, caID uint) (domain.TongKetSoQuy, error) {
	return tongKetCa(r.db.WithContext(ctx), caID)
}

func (r *caLamViecRepository) FindByID(ctx context.Context, id uint) (*domain.CaLamViec, error) {
	var ca domain.CaLamViec
	err := r.db.WithContext(ctx).First(&ca, id).Error
	if errors.Is(err, gorm.ErrRecordNotFound) {
		return nil, domain.ErrNotFound
	}
	if err != nil {
		return nil, err
	}
	// Ca của chi nhánh khác thì coi như không có. Cái két là vật lý, nó nằm ở một
	// chỗ — mở sổ quỹ của quầy khác ra xem là chuyện không nên làm được.
	if err := chanChungTuKhacChiNhanh(ctx, r.db, ca.ShopID); err != nil {
		return nil, err
	}

	return &ca, nil
}

func (r *caLamViecRepository) List(ctx context.Context, f domain.CaFilter) ([]domain.CaLamViec, int64, error) {
	q := r.db.WithContext(ctx).Model(&domain.CaLamViec{})

	if f.ShopID > 0 {
		q = q.Where("shop_id = ?", f.ShopID)
	}
	switch f.Status {
	case domain.CaDangMo:
		q = q.Where("closed_at IS NULL")
	case domain.CaDaDong:
		q = q.Where("closed_at IS NOT NULL")
	}
	// Ca đã đóng thuộc kỳ chứa giờ ĐÓNG: ca bỏ quên mở từ tháng trước, hôm nay mới
	// đóng phải nằm trong "Hôm nay" — người vừa chốt ca tìm nó ở đó. Ca đang mở
	// thuộc mọi kỳ từ lúc mở.
	if f.FromDate != "" {
		q = q.Where("(closed_at IS NULL OR closed_at >= ?)", f.FromDate+" 00:00:00")
	}
	if f.ToDate != "" {
		den := f.ToDate + " 23:59:59"
		q = q.Where("((closed_at IS NULL AND opened_at <= ?) OR closed_at <= ?)", den, den)
	}
	if f.OpenedBy > 0 {
		q = q.Where("opened_by = ?", f.OpenedBy)
	}
	if f.ChiLech {
		q = q.Where("closed_at IS NOT NULL AND difference <> 0")
	}

	var total int64
	if err := q.Count(&total).Error; err != nil {
		return nil, 0, err
	}

	page, pageSize := f.Page, f.PageSize
	if page < 1 {
		page = 1
	}
	if pageSize < 1 {
		pageSize = 20
	}

	var list []domain.CaLamViec
	err := q.Order("closed_at IS NULL DESC, closed_at DESC, id DESC").
		Offset((page - 1) * pageSize).Limit(pageSize).Find(&list).Error
	return list, total, err
}

func (r *caLamViecRepository) SoQuyCuaCa(ctx context.Context, caID uint) ([]domain.SoQuy, error) {
	var list []domain.SoQuy
	err := r.db.WithContext(ctx).
		Where("shift_id = ?", caID).
		Order("id ASC").Find(&list).Error
	return list, err
}

func (r *caLamViecRepository) SoQuyNgoaiCa(
	ctx context.Context, shopID uint, tu, den time.Time,
) ([]domain.SoQuy, error) {
	var list []domain.SoQuy
	err := r.db.WithContext(ctx).
		Where("shop_id = ? AND shift_id IS NULL", shopID).
		Where("created_at >= ? AND created_at <= ?", tu, den).
		Order("id ASC").Find(&list).Error
	return list, err
}

func (r *caLamViecRepository) GhiTay(ctx context.Context, e *domain.SoQuy, choAm bool) error {
	return r.db.WithContext(ctx).Transaction(func(tx *gorm.DB) error {
		// Khoá dòng ca rồi mới cộng sổ: hai người cùng ghi chi một lúc thì người sau
		// phải thấy khoản của người trước. Chưa mở ca thì không ghi tay vào két.
		var ca domain.CaLamViec
		err := tx.Clauses(clause.Locking{Strength: "UPDATE"}).
			Where("shop_id = ? AND closed_at IS NULL", e.ShopID).
			Order("id DESC").First(&ca).Error
		if errors.Is(err, gorm.ErrRecordNotFound) {
			return domain.ErrChuaMoCa
		}
		if err != nil {
			return err
		}
		if e.Direction == domain.SoQuyChi && !choAm {
			tk, err := tongKetCa(tx, ca.ID)
			if err != nil {
				return err
			}
			if con := ca.OpeningCash + tk.TongThu - tk.TongChi; e.Amount > con {
				return &domain.LoiKetAm{Con: con}
			}
		}

		return ghiSoQuy(tx, e)
	})
}

// GanMaChungTu tra mã chứng từ theo từng loại nguồn — mỗi loại MỘT câu, không
// một câu cho mỗi dòng: sổ của một ca đông khách dài vài trăm dòng.
func (r *caLamViecRepository) GanMaChungTu(ctx context.Context, ds []domain.SoQuy) error {
	bang := map[string]struct{ table, col string }{
		domain.SoQuyTuDonHang: {"orders", "order_code"},
		domain.SoQuyTuTraHang: {"order_returns", "return_code"},
		domain.SoQuyTuThuChi:  {"income_expenses", "code"},
	}
	ids := map[string][]uint{}
	var nguoi []uint
	for _, e := range ds {
		if e.ReferenceID != nil {
			if _, ok := bang[e.ReferenceType]; ok {
				ids[e.ReferenceType] = append(ids[e.ReferenceType], *e.ReferenceID)
			}
		}
		if e.CreatedBy != nil {
			nguoi = append(nguoi, *e.CreatedBy)
		}
		if e.AttachedBy != nil {
			nguoi = append(nguoi, *e.AttachedBy)
		}
	}

	ma := map[string]map[uint]string{}
	for loai, ds := range ids {
		var rows []struct {
			ID uint
			Ma string
		}
		b := bang[loai]
		if err := r.db.WithContext(ctx).Table(b.table).
			Select("id, "+b.col+" AS ma").Where("id IN ?", ds).Scan(&rows).Error; err != nil {
			return err
		}
		ma[loai] = make(map[uint]string, len(rows))
		for _, x := range rows {
			ma[loai][x.ID] = x.Ma
		}
	}

	ten := map[uint]string{}
	if len(nguoi) > 0 {
		var rows []struct {
			ID       uint
			FullName string
		}
		if err := r.db.WithContext(ctx).Table("users").
			Select("id, full_name").Where("id IN ?", nguoi).Scan(&rows).Error; err != nil {
			return err
		}
		for _, x := range rows {
			ten[x.ID] = x.FullName
		}
	}

	for i := range ds {
		if ds[i].ReferenceID != nil {
			ds[i].ReferenceCode = ma[ds[i].ReferenceType][*ds[i].ReferenceID]
		}
		if ds[i].CreatedBy != nil {
			ds[i].CreatedName = ten[*ds[i].CreatedBy]
		}
		if ds[i].AttachedBy != nil {
			ds[i].AttachedName = ten[*ds[i].AttachedBy]
		}
	}
	return nil
}

// DoanhThuCa dùng lại đúng câu gộp của báo cáo kết ca, để màn ca và báo cáo
// không bao giờ ra hai con số khác nhau cho cùng một ca.
func (r *caLamViecRepository) DoanhThuCa(ctx context.Context, caID uint) (domain.DoanhThuCa, error) {
	gop, err := r.gopDonTheoCa(ctx, []uint{caID})
	if err != nil {
		return domain.DoanhThuCa{}, err
	}
	g := gop[caID]
	return domain.DoanhThuCa{
		OrderCount: g.OrderCount, TotalRevenue: g.TotalRevenue,
		TotalCash: g.TotalCash, TotalTransfer: g.TotalTransfer,
		TotalCard: g.TotalCard, TotalAutoQR: g.TotalAutoQR,
	}, nil
}

func (r *caLamViecRepository) NguoiMoCa(ctx context.Context, shopID uint) ([]domain.NguoiTrucCa, error) {
	q := r.db.WithContext(ctx).Table("work_shifts ws").
		Select("DISTINCT ws.opened_by AS id, COALESCE(u.full_name, '') AS name").
		Joins("LEFT JOIN users u ON u.id = ws.opened_by")
	if shopID > 0 {
		q = q.Where("ws.shop_id = ?", shopID)
	}
	var out []domain.NguoiTrucCa
	err := q.Order("name").Scan(&out).Error
	return out, err
}

// chanChuaMoCa — luật của v2: chi nhánh chưa có ca mở thì không bán / thu tiền ở
// quầy. Gọi trong transaction của chính lượt bán, nên ca vừa đóng ở máy khác
// cũng chặn được.
func chanChuaMoCa(tx *gorm.DB, shopID uint) error {
	var n int64
	if err := tx.Model(&domain.CaLamViec{}).
		Where("shop_id = ? AND closed_at IS NULL", shopID).Count(&n).Error; err != nil {
		return err
	}
	if n == 0 {
		return domain.ErrChuaMoCa
	}
	return nil
}

func (r *orderRepository) CoCaMo(ctx context.Context, shopID uint) error {
	return chanChuaMoCa(r.db.WithContext(ctx), shopID)
}

func (r *caLamViecRepository) NhanVienTruc(ctx context.Context, shopID uint) ([]domain.NhanVienTruc, error) {
	var out []domain.NhanVienTruc
	err := r.db.WithContext(ctx).Model(&domain.NhanVien{}).
		Select("id, code, full_name AS name, user_id").
		Where("status = ?", domain.NhanSuDangLam).
		Where("(shop_ids IS NULL OR shop_ids = '' OR FIND_IN_SET(?, shop_ids) > 0)", shopID).
		Order("full_name").
		Scan(&out).Error
	return out, err
}

func (r *caLamViecRepository) TenNhanVien(ctx context.Context, id uint) string {
	var ten []string
	r.db.WithContext(ctx).Model(&domain.NhanVien{}).Unscoped().
		Where("id = ?", id).Limit(1).Pluck("full_name", &ten)
	if len(ten) == 0 {
		return ""
	}
	return ten[0]
}

func (r *caLamViecRepository) GioDongCaTruoc(ctx context.Context, shopID uint) (*time.Time, error) {
	var ca domain.CaLamViec
	err := r.db.WithContext(ctx).
		Where("shop_id = ? AND closed_at IS NOT NULL", shopID).
		Order("closed_at DESC").First(&ca).Error
	if errors.Is(err, gorm.ErrRecordNotFound) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	return ca.ClosedAt, nil
}

// ghiNhatKyCa ghi một dòng nhật ký nhân viên trong CÙNG transaction với ca: ca
// mở / đóng được thì nhật ký phải có, không có chuyện ca có mà nhật ký trống.
// Nhật ký thuộc người TRỰC KÉT; ca chưa có người trực thì lấy hồ sơ nhân sự của
// người mở ca (như lượt ghi bù trong migration 0089).
func ghiNhatKyCa(tx *gorm.DB, ca *domain.CaLamViec, event string, userID uint, amount, diff *float64, note string, luc time.Time) error {
	nv := ca.EmployeeID
	if nv == nil {
		var ids []uint
		if err := tx.Model(&domain.NhanVien{}).Where("user_id = ?", ca.OpenedBy).
			Order("id").Limit(1).Pluck("id", &ids).Error; err != nil {
			return err
		}
		if len(ids) > 0 {
			nv = &ids[0]
		}
	}
	shop, caID := ca.ShopID, ca.ID
	var nguoi *uint
	if userID > 0 {
		nguoi = &userID
	}
	return tx.Create(&domain.NhatKyNhanVien{
		ShopID: &shop, EmployeeID: nv, UserID: nguoi, Event: event, ShiftID: &caID,
		Amount: amount, Difference: diff, Note: note, HappenedAt: luc,
	}).Error
}

func (r *caLamViecRepository) NhatKyNhanVien(ctx context.Context, employeeID uint, page, pageSize int) ([]domain.NhatKyNhanVien, int64, error) {
	q := r.db.WithContext(ctx).Model(&domain.NhatKyNhanVien{}).Where("employee_id = ?", employeeID)
	var total int64
	if err := q.Count(&total).Error; err != nil {
		return nil, 0, err
	}
	var ds []domain.NhatKyNhanVien
	if err := q.Order("happened_at DESC, id DESC").Offset((page - 1) * pageSize).Limit(pageSize).Find(&ds).Error; err != nil {
		return nil, 0, err
	}

	shopIDs, userIDs := []uint{}, []uint{}
	for _, d := range ds {
		if d.ShopID != nil {
			shopIDs = append(shopIDs, *d.ShopID)
		}
		if d.UserID != nil {
			userIDs = append(userIDs, *d.UserID)
		}
	}
	tenCN, tenNguoi := map[uint]string{}, map[uint]string{}
	if len(shopIDs) > 0 {
		var rows []struct {
			ID   uint
			Name string
		}
		r.db.WithContext(ctx).Model(&domain.ChiNhanh{}).Unscoped().Where("id IN ?", shopIDs).Select("id, name").Scan(&rows)
		for _, x := range rows {
			tenCN[x.ID] = x.Name
		}
	}
	if len(userIDs) > 0 {
		var rows []struct {
			ID       uint
			FullName string
		}
		r.db.WithContext(ctx).Model(&domain.User{}).Unscoped().Where("id IN ?", userIDs).Select("id, full_name").Scan(&rows)
		for _, x := range rows {
			tenNguoi[x.ID] = x.FullName
		}
	}
	for i := range ds {
		if ds[i].ShopID != nil {
			ds[i].ShopName = tenCN[*ds[i].ShopID]
		}
		if ds[i].UserID != nil {
			ds[i].UserName = tenNguoi[*ds[i].UserID]
		}
	}
	return ds, total, nil
}

func (r *caLamViecRepository) TongNhatKyCa(ctx context.Context, employeeID uint) (domain.TongNhatKyCa, error) {
	var out domain.TongNhatKyCa
	err := r.db.WithContext(ctx).Model(&domain.NhatKyNhanVien{}).
		Where("employee_id = ? AND event = ?", employeeID, domain.NhatKyDongCa).
		Select(`COUNT(*) AS so_ca,
			COALESCE(SUM(CASE WHEN difference <> 0 THEN 1 ELSE 0 END), 0) AS so_lan_lech,
			COALESCE(SUM(CASE WHEN difference < 0 THEN difference ELSE 0 END), 0) AS tong_thieu,
			COALESCE(SUM(CASE WHEN difference > 0 THEN difference ELSE 0 END), 0) AS tong_du`).
		Scan(&out).Error
	return out, err
}

// GanNgoaiCa: khoản phát sinh TRƯỚC giờ đóng ca trước thuộc trách nhiệm ca ấy —
// gắn vào ca này là đẩy trách nhiệm sang người khác, nên từ chối.
func (r *caLamViecRepository) GanNgoaiCa(ctx context.Context, shopID uint, ids []uint, userID uint) (*domain.CaLamViec, error) {
	var out *domain.CaLamViec
	err := r.db.WithContext(ctx).Transaction(func(tx *gorm.DB) error {
		var ca domain.CaLamViec
		err := tx.Clauses(clause.Locking{Strength: "UPDATE"}).
			Where("shop_id = ? AND closed_at IS NULL", shopID).Order("id DESC").First(&ca).Error
		if errors.Is(err, gorm.ErrRecordNotFound) {
			return &domain.LoiGanNgoaiCa{Cau: "Chi nhánh chưa có ca đang mở — mở ca rồi mới gắn được."}
		}
		if err != nil {
			return err
		}

		var truoc domain.CaLamViec
		coTruoc := true
		if err := tx.Where("shop_id = ? AND closed_at IS NOT NULL", shopID).Order("closed_at DESC").First(&truoc).Error; err != nil {
			if !errors.Is(err, gorm.ErrRecordNotFound) {
				return err
			}
			coTruoc = false
		}

		var ds []domain.SoQuy
		if err := tx.Clauses(clause.Locking{Strength: "UPDATE"}).
			Where("id IN ? AND shop_id = ? AND shift_id IS NULL", ids, shopID).Find(&ds).Error; err != nil {
			return err
		}
		if len(ds) != len(ids) {
			return &domain.LoiGanNgoaiCa{Cau: "Có khoản đã được gắn vào ca hoặc không thuộc chi nhánh này — tải lại danh sách rồi thử lại."}
		}
		for _, e := range ds {
			if coTruoc && !e.CreatedAt.After(*truoc.ClosedAt) {
				return &domain.LoiGanNgoaiCa{Cau: fmt.Sprintf("Khoản lúc %s phát sinh trước khi ca #%d đóng (%s) — thuộc ca trước, không gắn vào ca này.",
					e.CreatedAt.Format("15:04 02/01"), truoc.ID, truoc.ClosedAt.Format("15:04 02/01"))}
			}
		}

		now := time.Now()
		if err := tx.Model(&domain.SoQuy{}).Where("id IN ?", ids).
			Updates(map[string]any{"shift_id": ca.ID, "attached_by": userID, "attached_at": now}).Error; err != nil {
			return err
		}
		out = &ca
		return nil
	})
	return out, err
}
