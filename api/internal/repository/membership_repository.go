package repository

import (
	"context"
	"errors"
	"math"
	"strings"
	"time"

	"gorm.io/gorm"
	"gorm.io/gorm/clause"

	"sass-api/internal/domain"
)

type membershipRepository struct{ db *gorm.DB }

func NewMembershipRepository(db *gorm.DB) domain.MembershipRepository {
	return &membershipRepository{db: db}
}

func (r *membershipRepository) ListRanks(ctx context.Context) ([]domain.MembershipRank, error) {
	var ds []domain.MembershipRank
	if err := r.db.WithContext(ctx).Order("point").Find(&ds).Error; err != nil {
		return nil, err
	}
	if len(ds) == 0 {
		return ds, nil
	}
	var dem []struct {
		RankID uint
		N      int64
	}
	if err := r.db.WithContext(ctx).Model(&domain.User{}).Select("rank_id, COUNT(*) AS n").
		Where("rank_id IS NOT NULL").Group("rank_id").Scan(&dem).Error; err != nil {
		return nil, err
	}
	theo := map[uint]int64{}
	for _, d := range dem {
		theo[d.RankID] = d.N
	}
	for i := range ds {
		ds[i].MemberCount = theo[ds[i].ID]
	}

	return ds, nil
}

func (r *membershipRepository) FindRank(ctx context.Context, id uint) (*domain.MembershipRank, error) {
	var h domain.MembershipRank
	err := r.db.WithContext(ctx).First(&h, id).Error
	if errors.Is(err, gorm.ErrRecordNotFound) {
		return nil, domain.ErrNotFound
	}
	if err != nil {
		return nil, err
	}
	if err := r.db.WithContext(ctx).Model(&domain.User{}).Where("rank_id = ?", id).Count(&h.MemberCount).Error; err != nil {
		return nil, err
	}

	return &h, nil
}

func (r *membershipRepository) SaveRank(ctx context.Context, h *domain.MembershipRank) error {
	var trung int64
	q := r.db.WithContext(ctx).Model(&domain.MembershipRank{}).Where("point = ?", h.Point)
	if h.ID > 0 {
		q = q.Where("id <> ?", h.ID)
	}
	if err := q.Count(&trung).Error; err != nil {
		return err
	}
	if trung > 0 {
		return domain.ErrHangTrungDiem
	}
	if h.ID == 0 {
		return r.db.WithContext(ctx).Create(h).Error
	}

	return r.db.WithContext(ctx).Model(&domain.MembershipRank{ID: h.ID}).
		Select("name", "point", "discount_type", "discount_value", "status", "apply_all_order_values", "min_order_value", "max_order_value").
		Updates(h).Error
}

func (r *membershipRepository) DeleteRanks(ctx context.Context, ids []uint) error {
	if len(ids) == 0 {
		return nil
	}
	// Khoá ngoại users.rank_id ON DELETE SET NULL gỡ hạng khỏi khách; XepHangLai
	// ngay sau đó xếp họ vào hạng thấp hơn còn lại.
	return r.db.WithContext(ctx).Where("id IN ?", ids).Delete(&domain.MembershipRank{}).Error
}

func (r *membershipRepository) XepHangLai(ctx context.Context) error {
	return r.db.WithContext(ctx).Transaction(func(tx *gorm.DB) error {
		var hang []domain.MembershipRank
		if err := tx.Where("status = 1").Order("point").Find(&hang).Error; err != nil {
			return err
		}
		if err := tx.Model(&domain.User{}).Where("rank_id IS NOT NULL").Update("rank_id", nil).Error; err != nil {
			return err
		}
		// Hạng thấp trước, hạng cao đè sau: mỗi khách dừng ở hạng cao nhất đạt được.
		for _, h := range hang {
			if err := tx.Model(&domain.User{}).Where("total_points >= ?", h.Point).Update("rank_id", h.ID).Error; err != nil {
				return err
			}
		}

		return nil
	})
}

func (r *membershipRepository) Members(ctx context.Context, rankID uint, keyword string, page, pageSize int) ([]domain.RankMember, int64, error) {
	q := r.db.WithContext(ctx).Model(&domain.User{}).Where("rank_id = ?", rankID)
	if kw := strings.TrimSpace(keyword); kw != "" {
		like := "%" + kw + "%"
		q = q.Where("(full_name LIKE ? OR customer_code LIKE ? OR phone LIKE ?)", like, like, like)
	}
	var total int64
	if err := q.Count(&total).Error; err != nil {
		return nil, 0, err
	}
	var ds []domain.RankMember
	err := q.Select("id", "customer_code AS code", "full_name", "phone", "total_points", "points").
		Order("total_points DESC, id").Limit(pageSize).Offset((page - 1) * pageSize).Scan(&ds).Error

	return ds, total, err
}

func (r *membershipRepository) Conversion(ctx context.Context) (*domain.PointConversion, error) {
	var c domain.PointConversion
	err := r.db.WithContext(ctx).Limit(1).Find(&c).Error

	return &c, err
}

func (r *membershipRepository) SaveConversion(ctx context.Context, c *domain.PointConversion) error {
	cu, err := r.Conversion(ctx)
	if err != nil {
		return err
	}
	if cu.ID == 0 {
		return r.db.WithContext(ctx).Create(c).Error
	}
	c.ID = cu.ID

	return r.db.WithContext(ctx).Model(&domain.PointConversion{ID: c.ID}).
		Select("earn_money", "earn_point", "earn_enabled", "redeem_point", "redeem_money", "redeem_enabled").
		Updates(c).Error
}

func (r *membershipRepository) KhachDiem(ctx context.Context, userID uint) (*domain.KhachDiem, error) {
	var u domain.User
	if err := r.db.WithContext(ctx).Select("id", "total_points", "points", "rank_id").First(&u, userID).Error; err != nil {
		if errors.Is(err, gorm.ErrRecordNotFound) {
			return nil, domain.ErrNotFound
		}
		return nil, err
	}
	k := &domain.KhachDiem{UserID: u.ID, TotalPoints: u.TotalPoints, Points: u.Points}
	if u.RankID != nil {
		var h domain.MembershipRank
		if err := r.db.WithContext(ctx).First(&h, *u.RankID).Error; err == nil {
			k.Rank = &h
		}
	}

	return k, nil
}

func (r *membershipRepository) TenHang(ctx context.Context) (map[uint]string, error) {
	var ds []domain.MembershipRank
	if err := r.db.WithContext(ctx).Select("id", "name").Find(&ds).Error; err != nil {
		return nil, err
	}
	out := make(map[uint]string, len(ds))
	for _, h := range ds {
		out[h.ID] = h.Name
	}

	return out, nil
}

// doiDiem ghi một biến động điểm của khách TRONG giao dịch tx (đơn hàng): khoá
// dòng khách, cộng / trừ điểm còn dùng (conLai) và điểm tích luỹ (tichLuy), xếp
// lại hạng, ghi sổ điểm.
//
// Đổi điểm (DiemDoi) mà không đủ thì trả ErrKhongDuDiem — cả đơn rollback. Hoàn
// lại / trừ khi trả hàng thì kẹp về 0: điểm khách đã tiêu mất thì không đòi lại.
func doiDiem(tx *gorm.DB, userID uint, orderID uint, loai string, conLai, tichLuy int, tien float64) error {
	if conLai == 0 && tichLuy == 0 {
		return nil
	}
	var u domain.User
	if err := tx.Clauses(clause.Locking{Strength: "UPDATE"}).Select("id", "total_points", "points").First(&u, userID).Error; err != nil {
		return err
	}
	moi := int(u.Points) + conLai
	if moi < 0 {
		if loai == domain.DiemDoi {
			return domain.ErrKhongDuDiem
		}
		moi = 0
	}
	tong := int(u.TotalPoints) + tichLuy
	if tong < 0 {
		tong = 0
	}

	var hang []uint
	if err := tx.Model(&domain.MembershipRank{}).Where("status = 1 AND point <= ?", tong).
		Order("point DESC").Limit(1).Pluck("id", &hang).Error; err != nil {
		return err
	}
	var rankID *uint
	if len(hang) > 0 {
		rankID = &hang[0]
	}
	if err := tx.Model(&domain.User{}).Where("id = ?", userID).
		Updates(map[string]any{"points": moi, "total_points": tong, "rank_id": rankID}).Error; err != nil {
		return err
	}

	oid := orderID
	return tx.Create(&domain.PointHistory{
		UserID: userID, OrderID: &oid, Kind: loai, Points: moi - int(u.Points), Amount: tien, Balance: moi, CreatedAt: time.Now(),
	}).Error
}

// cauHinhDiem đọc cấu hình quy đổi trong giao dịch tx (chưa có dòng = mọi thứ tắt).
func cauHinhDiem(tx *gorm.DB) (domain.PointConversion, error) {
	var c domain.PointConversion
	err := tx.Limit(1).Find(&c).Error

	return c, err
}

// diemKhiChotDon — lượt chốt đơn: trừ điểm đổi, và cộng điểm ngay nếu đơn sinh ra
// đã thu tiền xong (đơn quầy). Đơn giao hàng cộng điểm khi giao xong.
func diemKhiChotDon(tx *gorm.DB, o *domain.Order) error {
	if o.UserID == nil {
		return nil
	}
	if o.PointsUsed > 0 {
		if err := doiDiem(tx, *o.UserID, o.ID, domain.DiemDoi, -int(o.PointsUsed), 0, o.PointsAmount); err != nil {
			return err
		}
	}
	if soldCounted(o.Status) && o.PaymentStatus == domain.OrderPaymentPaid {
		return congDiemDon(tx, o)
	}

	return nil
}

// congDiemDon cộng điểm theo tiền khách thực trả (sau mọi khoản giảm, gồm thuế —
// như v2 "earn by actual collected amount"). Mỗi đơn chỉ cộng một lần.
func congDiemDon(tx *gorm.DB, o *domain.Order) error {
	if o.UserID == nil || o.PointsEarned > 0 {
		return nil
	}
	c, err := cauHinhDiem(tx)
	if err != nil {
		return err
	}
	diem := c.DiemTu(o.TotalAmount)
	if diem == 0 {
		return nil
	}
	if err := doiDiem(tx, *o.UserID, o.ID, domain.DiemTich, int(diem), int(diem), o.TotalAmount); err != nil {
		return err
	}
	o.PointsEarned = diem

	return tx.Model(&domain.Order{ID: o.ID}).Update("points_earned", diem).Error
}

// diemKhiDoiTrangThai — đơn giao hàng tới tay khách thì cộng điểm; đơn khép lại
// (huỷ / hoàn cả đơn) thì trả lại điểm đã đổi và rút điểm đã cộng.
func diemKhiDoiTrangThai(tx *gorm.DB, o *domain.Order, tu string) error {
	if o.UserID == nil {
		return nil
	}
	if soldCounted(o.Status) && !soldCounted(tu) {
		return congDiemDon(tx, o)
	}
	if o.Status != domain.OrderStatusCancelled && o.Status != domain.OrderStatusReturned {
		return nil
	}
	if o.PointsEarned > 0 {
		if err := doiDiem(tx, *o.UserID, o.ID, domain.DiemHoanLai, -int(o.PointsEarned), -int(o.PointsEarned), 0); err != nil {
			return err
		}
		o.PointsEarned = 0
		if err := tx.Model(&domain.Order{ID: o.ID}).Update("points_earned", 0).Error; err != nil {
			return err
		}
	}
	if o.PointsUsed > 0 {
		if err := doiDiem(tx, *o.UserID, o.ID, domain.DiemHoanLai, int(o.PointsUsed), 0, o.PointsAmount); err != nil {
			return err
		}
		o.PointsUsed = 0
		return tx.Model(&domain.Order{ID: o.ID}).Update("points_used", 0).Error
	}

	return nil
}

// diemKhiTraHang rút điểm đã cộng theo tỉ lệ tiền hoàn trên tiền đơn (v2
// DeductReturnPointsAction trừ điểm của phần trả).
func diemKhiTraHang(tx *gorm.DB, o *domain.Order, tienHoan float64) error {
	if o.UserID == nil || o.PointsEarned == 0 || tienHoan <= 0 || o.TotalAmount <= 0 {
		return nil
	}
	rut := uint(math.Round(float64(o.PointsEarned) * math.Min(1, tienHoan/o.TotalAmount)))
	if rut == 0 {
		return nil
	}
	if err := doiDiem(tx, *o.UserID, o.ID, domain.DiemHoanLai, -int(rut), -int(rut), tienHoan); err != nil {
		return err
	}

	return tx.Model(&domain.Order{ID: o.ID}).Update("points_earned", o.PointsEarned-rut).Error
}
