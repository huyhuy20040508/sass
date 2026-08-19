package repository

import (
	"context"
	"errors"
	"strings"
	"time"

	"gorm.io/gorm"

	"sass-api/internal/domain"
)

type promotionRepository struct{ db *gorm.DB }

func NewPromotionRepository(db *gorm.DB) domain.PromotionRepository {
	return &promotionRepository{db: db}
}

// applyStatus dựng mệnh đề lọc cho bốn nhóm trạng thái mà người bán thật sự hỏi.
//
// "Đang chạy" KHÔNG chỉ là is_active = 1: một chương trình bật sẵn nhưng chưa tới
// ngày thì vẫn chưa giảm cho ai. Trộn hai chuyện đó vào nhau là nguồn gốc của câu
// "bật rồi mà sao giá không đổi".
func applyStatus(q *gorm.DB, status string, now time.Time) *gorm.DB {
	switch status {
	case "running":
		return q.Where("is_active = 1 AND start_at <= ? AND end_at >= ?", now, now)
	case "scheduled":
		return q.Where("is_active = 1 AND start_at > ?", now)
	case "ended":
		return q.Where("end_at < ?", now)
	case "paused":
		// Tắt tay mà vẫn còn trong hạn — hết hạn rồi thì thuộc nhóm "đã kết thúc".
		return q.Where("is_active = 0 AND end_at >= ?", now)
	default:
		return q
	}
}

func (r *promotionRepository) List(ctx context.Context, f domain.PromotionFilter) ([]domain.Promotion, int64, error) {
	now := time.Now()
	q := r.db.WithContext(ctx).Model(&domain.Promotion{})

	if kw := strings.TrimSpace(f.Keyword); kw != "" {
		like := "%" + kw + "%"
		q = q.Where("name LIKE ? OR description LIKE ?", like, like)
	}
	q = applyStatus(q, f.Status, now)

	// Lọc theo khoảng ngày = "chương trình có chạy ngày nào trong khoảng này không",
	// chứ không phải "bắt đầu trong khoảng này": đợt kéo dài cả tháng vẫn phải hiện
	// ra khi người bán xem tuần giữa tháng.
	if d := strings.TrimSpace(f.FromDate); d != "" {
		if t, err := time.ParseInLocation("2006-01-02", d, time.Local); err == nil {
			q = q.Where("end_at >= ?", t)
		}
	}
	if d := strings.TrimSpace(f.ToDate); d != "" {
		if t, err := time.ParseInLocation("2006-01-02", d, time.Local); err == nil {
			q = q.Where("start_at <= ?", t.Add(24*time.Hour-time.Millisecond))
		}
	}

	var total int64
	if err := q.Count(&total).Error; err != nil {
		return nil, 0, err
	}

	switch f.Sort {
	case "oldest":
		q = q.Order("id ASC")
	case "start_asc":
		q = q.Order("start_at ASC, id ASC")
	case "start_desc":
		q = q.Order("start_at DESC, id DESC")
	case "name_asc":
		q = q.Order("name ASC, id ASC")
	default:
		q = q.Order("id DESC")
	}

	if f.PageSize > 0 {
		q = q.Limit(f.PageSize).Offset((f.Page - 1) * f.PageSize)
	}

	var items []domain.Promotion
	if err := q.Preload("Targets").Find(&items).Error; err != nil {
		return nil, 0, err
	}
	return items, total, nil
}

func (r *promotionRepository) Stats(ctx context.Context) (domain.PromotionStats, error) {
	var s domain.PromotionStats
	now := time.Now()

	// Một lượt quét, đếm cả bốn nhóm bằng SUM(CASE...): bốn câu COUNT riêng thì bốn
	// lần đọc bảng cho một dải thẻ nhỏ trên đầu trang.
	var row struct {
		Total     int64
		Running   int64
		Scheduled int64
		Ended     int64
		Paused    int64
	}
	err := r.db.WithContext(ctx).Model(&domain.Promotion{}).
		Select(`COUNT(*) AS total,
			SUM(CASE WHEN is_active = 1 AND start_at <= ? AND end_at >= ? THEN 1 ELSE 0 END) AS running,
			SUM(CASE WHEN is_active = 1 AND start_at > ? THEN 1 ELSE 0 END) AS scheduled,
			SUM(CASE WHEN end_at < ? THEN 1 ELSE 0 END) AS ended,
			SUM(CASE WHEN is_active = 0 AND end_at >= ? THEN 1 ELSE 0 END) AS paused`,
			now, now, now, now, now).
		Scan(&row).Error
	if err != nil {
		return s, err
	}

	s.Total, s.Running, s.Scheduled = row.Total, row.Running, row.Scheduled
	s.Ended, s.Paused = row.Ended, row.Paused
	return s, nil
}

func (r *promotionRepository) FindByID(ctx context.Context, id uint) (*domain.Promotion, error) {
	var p domain.Promotion
	err := r.db.WithContext(ctx).Preload("Targets").First(&p, id).Error
	if errors.Is(err, gorm.ErrRecordNotFound) {
		return nil, domain.ErrNotFound
	}
	return &p, err
}

func (r *promotionRepository) Create(ctx context.Context, p *domain.Promotion) error {
	// GORM tự ghi Targets kèm theo nhờ quan hệ has-many, và cả hai nằm trong cùng
	// một giao dịch: chương trình không phạm vi thì không giảm cho ai.
	return r.db.WithContext(ctx).Create(p).Error
}

func (r *promotionRepository) Update(ctx context.Context, p *domain.Promotion) error {
	return r.db.WithContext(ctx).Transaction(func(tx *gorm.DB) error {
		// Thay TOÀN BỘ phạm vi: xoá sạch rồi ghi lại theo danh sách mới. Cố gắng
		// so khớp từng dòng để giữ id cũ không đem lại lợi ích gì — bảng này không
		// ai trỏ tới, mà lại dễ sót dòng thừa.
		if err := tx.Where("promotion_id = ?", p.ID).Delete(&domain.PromotionTarget{}).Error; err != nil {
			return err
		}

		targets := p.Targets
		p.Targets = nil
		// Select tường minh để không ghi đè created_at bằng giá trị rỗng.
		if err := tx.Model(&domain.Promotion{ID: p.ID}).
			Select("name", "description", "discount_type", "discount_value",
				"max_discount_amount", "start_at", "end_at", "is_active").
			Updates(p).Error; err != nil {
			return err
		}

		for i := range targets {
			targets[i].ID = 0
			targets[i].PromotionID = p.ID
		}
		if len(targets) > 0 {
			if err := tx.Create(&targets).Error; err != nil {
				return err
			}
		}
		p.Targets = targets
		return nil
	})
}

func (r *promotionRepository) SetActive(ctx context.Context, id uint, active bool) error {
	res := r.db.WithContext(ctx).Model(&domain.Promotion{}).
		Where("id = ?", id).Update("is_active", active)
	if res.Error != nil {
		return res.Error
	}
	// 0 dòng có thể là id không tồn tại, cũng có thể là chương trình đã bật/tắt
	// sẵn ở đúng trạng thái này — xem conDong.
	if res.RowsAffected == 0 {
		return conDong(ctx, r.db, &domain.Promotion{}, id)
	}
	return nil
}

func (r *promotionRepository) Delete(ctx context.Context, id uint) error {
	// Xoá mềm chương trình, nhưng phạm vi thì xoá HẲN.
	//
	// Khoá ngoại ON DELETE CASCADE chỉ nổ khi xoá thật; xoá mềm là một lệnh UPDATE
	// nên nó không chạy, và các dòng phạm vi sẽ nằm lại vĩnh viễn — không ai đọc
	// tới (chỉ chương trình chưa xoá mới được nạp) nhưng cứ thế phình ra.
	return r.db.WithContext(ctx).Transaction(func(tx *gorm.DB) error {
		res := tx.Delete(&domain.Promotion{}, id)
		if res.Error != nil {
			return res.Error
		}
		if res.RowsAffected == 0 {
			return domain.ErrNotFound
		}
		return tx.Where("promotion_id = ?", id).Delete(&domain.PromotionTarget{}).Error
	})
}

func (r *promotionRepository) Running(ctx context.Context, at time.Time) ([]domain.Promotion, error) {
	var items []domain.Promotion
	err := r.db.WithContext(ctx).
		Where("is_active = 1 AND start_at <= ? AND end_at >= ?", at, at).
		Preload("Targets").
		Find(&items).Error
	return items, err
}

func (r *promotionRepository) CountProducts(ctx context.Context, productIDs, categoryIDs []uint) (int64, error) {
	if len(productIDs) == 0 && len(categoryIDs) == 0 {
		return 0, nil
	}

	// Gom ba nhánh bằng OR trong MỘT nhóm ngoặc: viết rời ra thành ba Where liên
	// tiếp là chúng bị AND với nhau, đếm ra 0 ở mọi chương trình trộn nhiều loại.
	cond := r.db.Session(&gorm.Session{NewDB: true})
	first := true
	add := func(col string, ids []uint) {
		if len(ids) == 0 {
			return
		}
		if first {
			cond = cond.Where(col+" IN ?", ids)
			first = false
			return
		}
		cond = cond.Or(col+" IN ?", ids)
	}
	add("id", productIDs)
	add("category_id", categoryIDs)

	var n int64
	err := r.db.WithContext(ctx).Model(&domain.Product{}).
		Where("is_active = 1").
		Where(cond).
		Count(&n).Error
	return n, err
}
