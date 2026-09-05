package repository

import (
	"context"
	"errors"
	"strings"
	"time"

	"gorm.io/gorm"
	"gorm.io/gorm/clause"

	"sass-api/internal/domain"
)

type voucherRepository struct{ db *gorm.DB }

func NewVoucherRepository(db *gorm.DB) domain.VoucherRepository {
	return &voucherRepository{db: db}
}

// Mấy mệnh đề dùng lại ở cả bộ lọc lẫn bảng đếm. Khai một lần để hai chỗ không
// bao giờ hiểu "hết hạn" theo hai kiểu khác nhau.
//
// Cả hai mốc thời gian đều CHO PHÉP NULL (= không giới hạn phía đó), nên mọi so
// sánh phải kèm nhánh IS NULL — thiếu nó là voucher vô thời hạn biến mất khỏi
// mọi bộ lọc.
const (
	vNotEnded  = "(end_at IS NULL OR end_at >= ?)"
	vEnded     = "(end_at IS NOT NULL AND end_at < ?)"
	vNotUsedUp = "(usage_limit IS NULL OR used_count < usage_limit)"
	vUsedUp    = "(usage_limit IS NOT NULL AND used_count >= usage_limit)"
	vStarted   = "(start_at IS NULL OR start_at <= ?)"
	vNotYet    = "(start_at IS NOT NULL AND start_at > ?)"
)

// applyVoucherStatus dựng mệnh đề lọc cho năm nhóm trạng thái.
//
// Năm nhóm này LOẠI TRỪ NHAU và phủ hết mọi dòng, theo đúng thứ tự ưu tiên mà
// voucherStatus() bên service dùng: hết hạn → hết lượt → tắt tay → chờ tới ngày
// → đang chạy. Lệch thứ tự giữa hai nơi là dải thẻ đếm ra một đằng, bảng hiện
// một nẻo.
func applyVoucherStatus(q *gorm.DB, status string, now time.Time) *gorm.DB {
	switch status {
	case "ended":
		return q.Where(vEnded, now)
	case "used_up":
		return q.Where(vNotEnded, now).Where(vUsedUp)
	case "paused":
		return q.Where(vNotEnded, now).Where(vNotUsedUp).Where("is_active = 0")
	case "scheduled":
		return q.Where(vNotEnded, now).Where(vNotUsedUp).Where("is_active = 1").Where(vNotYet, now)
	case "running":
		return q.Where(vNotEnded, now).Where(vNotUsedUp).Where("is_active = 1").Where(vStarted, now)
	default:
		return q
	}
}

func (r *voucherRepository) List(ctx context.Context, f domain.VoucherFilter) ([]domain.Voucher, int64, error) {
	now := time.Now()
	q := r.db.WithContext(ctx).Model(&domain.Voucher{})
	q = locGanChiNhanh(q, ctx, r.db, "voucher_shops", "voucher_id", "vouchers")

	if kw := strings.TrimSpace(f.Keyword); kw != "" {
		like := "%" + kw + "%"
		q = q.Where("code LIKE ? OR description LIKE ?", like, like)
	}
	if f.Type == domain.DiscountPercentage || f.Type == domain.DiscountFixed {
		q = q.Where("discount_type = ?", f.Type)
	}
	q = applyVoucherStatus(q, f.Status, now)

	// Lọc theo khoảng ngày = "mã có hiệu lực ngày nào trong khoảng này không", chứ
	// không phải "bắt đầu trong khoảng này": mã chạy cả quý vẫn phải hiện ra khi
	// người bán xem một tuần giữa quý. Mốc để trống là không giới hạn nên luôn lọt.
	if d := strings.TrimSpace(f.FromDate); d != "" {
		if t, err := time.ParseInLocation("2006-01-02", d, time.Local); err == nil {
			q = q.Where("end_at IS NULL OR end_at >= ?", t)
		}
	}
	if d := strings.TrimSpace(f.ToDate); d != "" {
		if t, err := time.ParseInLocation("2006-01-02", d, time.Local); err == nil {
			q = q.Where("start_at IS NULL OR start_at <= ?", t.Add(24*time.Hour-time.Millisecond))
		}
	}

	var total int64
	if err := q.Count(&total).Error; err != nil {
		return nil, 0, err
	}

	switch f.Sort {
	case "oldest":
		q = q.Order("id ASC")
	case "code_asc":
		q = q.Order("code ASC, id ASC")
	case "used_desc":
		q = q.Order("used_count DESC, id DESC")
	case "end_asc":
		// Mã vô thời hạn xuống cuối: "sắp hết hạn nhất" mà xếp mã không bao giờ hết
		// hạn lên đầu thì cột này vô dụng.
		q = q.Order("end_at IS NULL ASC, end_at ASC, id DESC")
	default:
		q = q.Order("id DESC")
	}

	if f.PageSize > 0 {
		q = q.Limit(f.PageSize).Offset((f.Page - 1) * f.PageSize)
	}

	var items []domain.Voucher
	// Nạp CẢ Shops — xem ghi chú cùng chỗ ở PromotionRepository.List.
	if err := q.Preload("Shops").Find(&items).Error; err != nil {
		return nil, 0, err
	}
	return items, total, nil
}

func (r *voucherRepository) Stats(ctx context.Context) (domain.VoucherStats, error) {
	var s domain.VoucherStats
	now := time.Now()

	// Một lượt quét, đếm cả năm nhóm bằng SUM(CASE...): năm câu COUNT riêng thì năm
	// lần đọc bảng cho một dải thẻ nhỏ trên đầu trang.
	var row struct {
		Total     int64
		Running   int64
		Scheduled int64
		Ended     int64
		UsedUp    int64
		Paused    int64
	}
	err := r.db.WithContext(ctx).Model(&domain.Voucher{}).
		Select(`COUNT(*) AS total,
			SUM(CASE WHEN `+vNotEnded+` AND `+vNotUsedUp+` AND is_active = 1 AND `+vStarted+` THEN 1 ELSE 0 END) AS running,
			SUM(CASE WHEN `+vNotEnded+` AND `+vNotUsedUp+` AND is_active = 1 AND `+vNotYet+` THEN 1 ELSE 0 END) AS scheduled,
			SUM(CASE WHEN `+vEnded+` THEN 1 ELSE 0 END) AS ended,
			SUM(CASE WHEN `+vNotEnded+` AND `+vUsedUp+` THEN 1 ELSE 0 END) AS used_up,
			SUM(CASE WHEN `+vNotEnded+` AND `+vNotUsedUp+` AND is_active = 0 THEN 1 ELSE 0 END) AS paused`,
			now, now, now, now, now, now, now).
		Scan(&row).Error
	if err != nil {
		return s, err
	}

	s.Total, s.Running, s.Scheduled = row.Total, row.Running, row.Scheduled
	s.Ended, s.UsedUp, s.Paused = row.Ended, row.UsedUp, row.Paused
	return s, nil
}

func (r *voucherRepository) FindByID(ctx context.Context, id uint) (*domain.Voucher, error) {
	var v domain.Voucher
	err := r.db.WithContext(ctx).Preload("Shops").First(&v, id).Error
	if errors.Is(err, gorm.ErrRecordNotFound) {
		return nil, domain.ErrNotFound
	}
	if err != nil {
		return nil, err
	}
	// Gán rỗng = dùng được mọi nơi, nên chỉ chặn khi có gán mà không có mình.
	if len(v.Shops) > 0 {
		ids := make([]uint, 0, len(v.Shops))
		for _, cn := range v.Shops {
			ids = append(ids, cn.ID)
		}
		if err := chanChungTuKhacChiNhanh(ctx, r.db, ids...); err != nil {
			return nil, err
		}
	}

	return &v, nil
}

// ReplaceShops đặt lại danh sách chi nhánh dùng được mã này. Xem
// PromotionRepository.ReplaceShops — cùng lý do phải viết tay.
func (r *voucherRepository) ReplaceShops(ctx context.Context, voucherID uint, shopIDs []uint) error {
	return r.db.WithContext(ctx).Transaction(func(tx *gorm.DB) error {
		if err := tx.Where("voucher_id = ?", voucherID).
			Delete(&domain.VoucherShop{}).Error; err != nil {
			return err
		}
		if len(shopIDs) == 0 {
			return nil
		}

		rows := make([]domain.VoucherShop, 0, len(shopIDs))
		for _, id := range shopIDs {
			if id > 0 {
				rows = append(rows, domain.VoucherShop{VoucherID: voucherID, ShopID: id})
			}
		}
		if len(rows) == 0 {
			return nil
		}

		return tx.Clauses(clause.OnConflict{DoNothing: true}).Create(&rows).Error
	})
}

func (r *voucherRepository) CodeTaken(ctx context.Context, code string, exceptID uint) (bool, error) {
	q := r.db.WithContext(ctx).Model(&domain.Voucher{}).Where("code = ?", code)
	if exceptID > 0 {
		q = q.Where("id <> ?", exceptID)
	}

	// Không dùng Count: chỉ cần biết CÓ hay KHÔNG, mà bảng này có khoá duy nhất trên
	// code nên nhiều nhất cũng chỉ một dòng.
	var ids []uint
	err := q.Limit(1).Pluck("id", &ids).Error
	return len(ids) > 0, err
}

func (r *voucherRepository) Create(ctx context.Context, v *domain.Voucher) error {
	return r.db.WithContext(ctx).Create(v).Error
}

func (r *voucherRepository) Update(ctx context.Context, v *domain.Voucher) error {
	// Select tường minh: used_count và created_at KHÔNG nằm trong danh sách nên
	// không thể bị biểu mẫu quản trị ghi đè về 0. Số lượt đã phát là dữ liệu do đơn
	// hàng ghi ra, sửa mô tả voucher không được phép reset nó.
	//
	// Select cũng là thứ khiến các cột nullable ghi được giá trị rỗng: Updates(struct)
	// mặc định bỏ qua mọi trường zero, nên bỏ trần giảm hoặc bỏ giới hạn lượt sẽ
	// không ăn — cột đã khai trong Select thì nil vẫn được ghi thành NULL.
	res := r.db.WithContext(ctx).Model(&domain.Voucher{}).
		Where("id = ?", v.ID).
		Select("code", "description", "discount_type", "discount_value",
			"max_discount_amount", "min_order_amount", "usage_limit",
			"usage_limit_per_user", "start_at", "end_at", "is_active", "is_public").
		Updates(v)
	if res.Error != nil {
		return res.Error
	}
	// Có thể là id không tồn tại, cũng có thể là bấm Lưu mà không sửa gì — xem conDong.
	if res.RowsAffected == 0 {
		return conDong(ctx, r.db, &domain.Voucher{}, v.ID)
	}
	return nil
}

func (r *voucherRepository) SetActive(ctx context.Context, id uint, active bool) error {
	res := r.db.WithContext(ctx).Model(&domain.Voucher{}).
		Where("id = ?", id).Update("is_active", active)
	if res.Error != nil {
		return res.Error
	}
	// 0 dòng có thể là id không tồn tại, cũng có thể là mã đã ở đúng trạng thái
	// này rồi — xem conDong.
	if res.RowsAffected == 0 {
		return conDong(ctx, r.db, &domain.Voucher{}, id)
	}
	return nil
}

// FindByCode là đường ÁP DỤNG — nơi mã thật sự trừ tiền của đơn.
//
// Cắt chi nhánh ở đây, và trả đúng ErrVoucherNotFound khi mã không dùng được ở
// kho này: với người gõ mã thì "mã này không dùng ở đây" và "không có mã này" là
// cùng một kết quả, mà câu sau không hé lộ rằng mã ấy có thật ở kho khác.
func (r *voucherRepository) FindByCode(ctx context.Context, code string) (*domain.Voucher, error) {
	q := r.db.WithContext(ctx).Where("code = ?", code)
	q = locGanChiNhanh(q, ctx, r.db, "voucher_shops", "voucher_id", "vouchers")

	var v domain.Voucher
	err := q.First(&v).Error
	if errors.Is(err, gorm.ErrRecordNotFound) {
		return nil, domain.ErrVoucherNotFound
	}

	return &v, err
}

// sameCustomer dựng mệnh đề "cùng một khách" cho bảng voucher_usages.
//
// Nhận diện bằng tài khoản HOẶC số điện thoại, nối bằng OR: khách hôm nay đặt lúc
// đã đăng nhập, hôm sau đặt kiểu vãng lai bằng đúng số đó thì vẫn là một người.
//
// Cố ý KHÔNG đếm theo "user_id IS NULL" — làm vậy là gộp mọi khách vãng lai thành
// một người và mã dùng một lần sẽ chết ngay sau đơn đầu tiên của cả cửa hàng.
// Trả về nil khi không biết khách là ai, phía gọi hiểu là không có gì để đếm.
func sameCustomer(db *gorm.DB, userID uint, phone string) *gorm.DB {
	phone = digitsOnly(phone)
	switch {
	case userID > 0 && phone != "":
		return db.Where("user_id = ? OR recipient_phone = ?", userID, phone)
	case userID > 0:
		return db.Where("user_id = ?", userID)
	case phone != "":
		return db.Where("recipient_phone = ?", phone)
	default:
		return nil
	}
}

// digitsOnly bóc số điện thoại còn đúng chữ số, để "0912 345 678" và "0912345678"
// được coi là một người.
func digitsOnly(s string) string {
	var b strings.Builder
	for _, c := range s {
		if c >= '0' && c <= '9' {
			b.WriteRune(c)
		}
	}
	return b.String()
}

func (r *voucherRepository) CountUsageByUser(ctx context.Context, voucherID, userID uint, phone string) (int64, error) {
	who := sameCustomer(r.db.Session(&gorm.Session{NewDB: true}), userID, phone)
	if who == nil {
		return 0, nil
	}

	var n int64
	err := r.db.WithContext(ctx).Model(&domain.VoucherUsage{}).
		Where("voucher_id = ?", voucherID).
		Where(who).
		Count(&n).Error
	return n, err
}

func (r *voucherRepository) ListPublic(ctx context.Context, at time.Time, limit int) ([]domain.Voucher, error) {
	q := r.db.WithContext(ctx).
		Where("is_public = 1 AND is_active = 1").
		Where(vNotEnded, at).
		Where(vStarted, at).
		Where(vNotUsedUp)
	// Không khoe mã của kho khác ra ô nhập mã: gõ vào cũng bị FindByCode từ chối.
	q = locGanChiNhanh(q, ctx, r.db, "voucher_shops", "voucher_id", "vouchers")

	var items []domain.Voucher
	err := q.
		// Mã sắp hết hạn lên trước: đó là mã khách nên dùng ngay kẻo lỡ. Mã vô thời
		// hạn xuống cuối vì lúc nào dùng cũng được.
		Order("end_at IS NULL ASC, end_at ASC, id DESC").
		Limit(limit).
		Find(&items).Error
	return items, err
}

func (r *voucherRepository) CountUsageByUserBulk(ctx context.Context, voucherIDs []uint, userID uint, phone string) (map[uint]int64, error) {
	out := make(map[uint]int64, len(voucherIDs))
	who := sameCustomer(r.db.Session(&gorm.Session{NewDB: true}), userID, phone)
	if who == nil || len(voucherIDs) == 0 {
		return out, nil
	}

	var rows []struct {
		VoucherID uint
		N         int64
	}
	err := r.db.WithContext(ctx).Model(&domain.VoucherUsage{}).
		Select("voucher_id, COUNT(*) AS n").
		Where("voucher_id IN ?", voucherIDs).
		Where(who).
		Group("voucher_id").
		Scan(&rows).Error
	if err != nil {
		return nil, err
	}
	for _, r := range rows {
		out[r.VoucherID] = r.N
	}
	return out, nil
}

func (r *voucherRepository) Delete(ctx context.Context, id uint) error {
	// Xoá MỀM, và đây là điều bắt buộc chứ không phải sở thích: orders.voucher_id
	// trỏ tới bảng này, orders.voucher_code chỉ là bản chụp lúc đặt. Xoá thật thì
	// khoá ngoại quét voucher_id của mọi đơn cũ về NULL và lịch sử "đơn này dùng mã
	// nào" mất sạch.
	res := r.db.WithContext(ctx).Delete(&domain.Voucher{}, id)
	if res.Error != nil {
		return res.Error
	}
	if res.RowsAffected == 0 {
		return domain.ErrNotFound
	}
	return nil
}
