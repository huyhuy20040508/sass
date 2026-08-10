package repository

import (
	"context"

	"sass-api/internal/domain"

	"gorm.io/gorm"
)

type notificationRepository struct{ db *gorm.DB }

func NewNotificationRepository(db *gorm.DB) domain.NotificationRepository {
	return &notificationRepository{db: db}
}

func (r *notificationRepository) Create(ctx context.Context, n *domain.Notification) error {
	return r.db.WithContext(ctx).Create(n).Error
}

// scopeChannel giới hạn truy vấn vào đúng một kênh.
//
// Điều kiện kênh luôn được ghép TRỰC TIẾP vào mọi câu lệnh (kể cả UPDATE) chứ
// không kiểm tra ở tầng trên: đây là chỗ duy nhất chặn việc khách hàng đọc hoặc
// đánh dấu thông báo của người khác.
func scopeChannel(q *gorm.DB, userID *uint) *gorm.DB {
	if userID == nil {
		return q.Where("user_id IS NULL")
	}
	return q.Where("user_id = ?", *userID)
}

func (r *notificationRepository) List(ctx context.Context, f domain.NotificationFilter) ([]domain.Notification, int64, error) {
	q := scopeChannel(r.db.WithContext(ctx).Model(&domain.Notification{}), f.UserID)
	if f.OnlyUnread {
		q = q.Where("is_read = ?", false)
	}

	var total int64
	if err := q.Count(&total).Error; err != nil {
		return nil, 0, err
	}

	page, size := f.Page, f.PageSize
	if page < 1 {
		page = 1
	}
	if size < 1 || size > 100 {
		size = 20
	}

	var items []domain.Notification
	err := q.Order("id DESC").Offset((page - 1) * size).Limit(size).Find(&items).Error
	if err != nil {
		return nil, 0, err
	}
	return items, total, nil
}

func (r *notificationRepository) UnreadCount(ctx context.Context, userID *uint) (int64, error) {
	var n int64
	err := scopeChannel(r.db.WithContext(ctx).Model(&domain.Notification{}), userID).
		Where("is_read = ?", false).
		Count(&n).Error
	return n, err
}

func (r *notificationRepository) MarkRead(ctx context.Context, id uint, userID *uint) error {
	res := scopeChannel(r.db.WithContext(ctx).Model(&domain.Notification{}).Where("id = ?", id), userID).
		Updates(map[string]any{"is_read": true, "read_at": gorm.Expr("NOW(3)")})
	if res.Error != nil {
		return res.Error
	}
	// 0 dòng = thông báo không tồn tại HOẶC thuộc kênh khác — cùng một câu trả lời,
	// để không thể dò sự tồn tại của thông báo người khác bằng cách thử id.
	if res.RowsAffected == 0 {
		return domain.ErrNotFound
	}
	return nil
}

func (r *notificationRepository) MarkAllRead(ctx context.Context, userID *uint) (int64, error) {
	res := scopeChannel(r.db.WithContext(ctx).Model(&domain.Notification{}), userID).
		Where("is_read = ?", false).
		Updates(map[string]any{"is_read": true, "read_at": gorm.Expr("NOW(3)")})
	return res.RowsAffected, res.Error
}
