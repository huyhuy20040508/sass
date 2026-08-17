package repository

import (
	"context"
	"errors"
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
	err := r.db.WithContext(ctx).Create(ca).Error
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
	ctx context.Context, id uint, countedCash float64, note string, closedBy uint,
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
		ca.ExpectedCash = &expected
		ca.Difference = &diff
		if n := strings.TrimSpace(note); n != "" {
			ca.Note = n
		}

		if err := tx.Model(&ca).
			Select("ClosedAt", "ClosedBy", "CountedCash", "ExpectedCash", "Difference", "Note").
			Updates(&ca).Error; err != nil {
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
	if f.FromDate != "" {
		q = q.Where("opened_at >= ?", f.FromDate+" 00:00:00")
	}
	if f.ToDate != "" {
		q = q.Where("opened_at <= ?", f.ToDate+" 23:59:59")
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
	err := q.Order("id DESC").Offset((page - 1) * pageSize).Limit(pageSize).Find(&list).Error
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

func (r *caLamViecRepository) GhiTay(ctx context.Context, e *domain.SoQuy) error {
	return r.db.WithContext(ctx).Transaction(func(tx *gorm.DB) error {
		return ghiSoQuy(tx, e)
	})
}
