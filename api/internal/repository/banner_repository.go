package repository

import (
	"context"
	"database/sql"
	"errors"
	"time"

	"gorm.io/gorm"

	"sass-api/internal/domain"
)

type bannerRepository struct{ db *gorm.DB }

func NewBannerRepository(db *gorm.DB) domain.BannerRepository {
	return &bannerRepository{db: db}
}

func (r *bannerRepository) List(ctx context.Context, f domain.BannerFilter) ([]domain.Banner, error) {
	var banners []domain.Banner

	// Thứ tự đọc phải khớp thứ tự hiển thị trên storefront: theo vị trí, rồi tới
	// sort_order do người bán sắp, cuối cùng lấy id làm mốc phá hoà (hai banner
	// cùng sort_order vẫn phải ra cùng một thứ tự ở mọi lần tải trang).
	q := r.db.WithContext(ctx).Order("position ASC, sort_order ASC, id ASC")

	if f.Position != "" {
		q = q.Where("position = ?", f.Position)
	}
	if f.OnlyLive {
		now := time.Now()
		// Dấu ngoặc là bắt buộc: thiếu nó thì mệnh đề OR nuốt luôn điều kiện
		// is_active và banner đang tắt vẫn lọt ra storefront.
		q = q.Where("is_active = ?", true).
			Where("(start_at IS NULL OR start_at <= ?)", now).
			Where("(end_at IS NULL OR end_at >= ?)", now)
	}

	if err := q.Find(&banners).Error; err != nil {
		return nil, err
	}
	return banners, nil
}

func (r *bannerRepository) FindByID(ctx context.Context, id uint) (*domain.Banner, error) {
	var b domain.Banner
	err := r.db.WithContext(ctx).First(&b, id).Error
	if errors.Is(err, gorm.ErrRecordNotFound) {
		return nil, domain.ErrNotFound
	}
	if err != nil {
		return nil, err
	}
	return &b, nil
}

func (r *bannerRepository) Create(ctx context.Context, b *domain.Banner) error {
	return r.db.WithContext(ctx).Create(b).Error
}

func (r *bannerRepository) Update(ctx context.Context, b *domain.Banner) error {
	// Save() bỏ qua trường zero khi dùng struct, nên phải chỉ đích danh các cột
	// được phép ghi: xoá lịch chạy (về NULL) hay bỏ tiêu đề (về "") đều là thao
	// tác hợp lệ và phải lưu được.
	return r.db.WithContext(ctx).Model(&domain.Banner{}).
		Where("id = ?", b.ID).
		Updates(map[string]any{
			"title":      b.Title,
			"image":      b.Image,
			"link":       b.Link,
			"position":   b.Position,
			"sort_order": b.SortOrder,
			"is_active":  b.IsActive,
			"start_at":   b.StartAt,
			"end_at":     b.EndAt,
			"updated_at": time.Now(),
		}).Error
}

func (r *bannerRepository) SetActive(ctx context.Context, id uint, active bool) error {
	return r.db.WithContext(ctx).Model(&domain.Banner{}).
		Where("id = ?", id).
		Updates(map[string]any{"is_active": active, "updated_at": time.Now()}).Error
}

func (r *bannerRepository) SetSortOrders(ctx context.Context, orders map[uint]int) error {
	if len(orders) == 0 {
		return nil
	}
	now := time.Now()

	return r.db.WithContext(ctx).Transaction(func(tx *gorm.DB) error {
		for id, order := range orders {
			if err := tx.Model(&domain.Banner{}).
				Where("id = ?", id).
				Updates(map[string]any{"sort_order": order, "updated_at": now}).Error; err != nil {
				return err
			}
		}
		return nil
	})
}

func (r *bannerRepository) Delete(ctx context.Context, id uint) error {
	// Bảng banners không có deleted_at — đây là xoá thật. Banner là dữ liệu quảng
	// cáo, không có chứng từ nào tham chiếu ngược lại nên không cần giữ lại xác.
	return r.db.WithContext(ctx).Delete(&domain.Banner{}, id).Error
}

func (r *bannerRepository) NextSortOrder(ctx context.Context, position string) (int, error) {
	// sql.NullInt64 chứ không phải int: vị trí chưa có banner nào thì MAX() trả
	// NULL, quét thẳng vào int là lỗi "converting NULL to int".
	var max sql.NullInt64
	err := r.db.WithContext(ctx).Model(&domain.Banner{}).
		Where("position = ?", position).
		Select("MAX(sort_order)").
		Scan(&max).Error
	if err != nil {
		return 0, err
	}
	if !max.Valid {
		return 0, nil
	}
	return int(max.Int64) + 1, nil
}
