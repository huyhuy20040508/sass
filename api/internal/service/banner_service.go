package service

import (
	"context"
	"strings"
	"time"

	"sass-api/internal/domain"
	"sass-api/internal/dto"
)

// bannerTimeLayout là định dạng lịch chạy nhận từ trang quản trị — đúng thứ mà ô
// <input type="datetime-local"> gửi lên, không kèm múi giờ nên được hiểu theo giờ
// máy chủ (cùng múi với dữ liệu đã lưu trong MySQL, xem loc=Local ở config).
const bannerTimeLayout = "2006-01-02T15:04"

// BannerService định nghĩa nghiệp vụ banner trang chủ.
type BannerService interface {
	// List dùng cho trang quản trị: thấy cả banner đang tắt lẫn banner hẹn lịch.
	List(ctx context.Context, position string) ([]domain.Banner, error)
	// Live dùng cho storefront: chỉ banner đang thực sự hiện với khách.
	Live(ctx context.Context, position string) ([]domain.Banner, error)
	Get(ctx context.Context, id uint) (*domain.Banner, error)
	Create(ctx context.Context, req dto.BannerRequest) (*domain.Banner, error)
	Update(ctx context.Context, id uint, req dto.BannerRequest) (*domain.Banner, error)
	SetActive(ctx context.Context, id uint, active bool) (*domain.Banner, error)
	// Sort sắp xếp lại banner: thứ tự trong mảng ids là thứ tự hiển thị.
	Sort(ctx context.Context, ids []uint) error
	Delete(ctx context.Context, id uint) error
}

type bannerService struct {
	repo domain.BannerRepository
}

func NewBannerService(repo domain.BannerRepository) BannerService {
	return &bannerService{repo: repo}
}

func (s *bannerService) List(ctx context.Context, position string) ([]domain.Banner, error) {
	position = strings.TrimSpace(position)
	if position != "" && !domain.IsValidBannerPosition(position) {
		return nil, domain.ErrBannerPositionInvalid
	}
	return s.repo.List(ctx, domain.BannerFilter{Position: position})
}

func (s *bannerService) Live(ctx context.Context, position string) ([]domain.Banner, error) {
	position = strings.TrimSpace(position)
	// Vị trí lạ thì trả danh sách rỗng chứ không báo lỗi: storefront hỏi một khối
	// chưa được khai thì khối đó tự ẩn, không phải làm hỏng cả trang chủ.
	if position != "" && !domain.IsValidBannerPosition(position) {
		return []domain.Banner{}, nil
	}
	return s.repo.List(ctx, domain.BannerFilter{Position: position, OnlyLive: true})
}

func (s *bannerService) Get(ctx context.Context, id uint) (*domain.Banner, error) {
	return s.repo.FindByID(ctx, id)
}

func (s *bannerService) Create(ctx context.Context, req dto.BannerRequest) (*domain.Banner, error) {
	position, err := validBannerPosition(req.Position)
	if err != nil {
		return nil, err
	}
	start, end, err := parseBannerSchedule(req)
	if err != nil {
		return nil, err
	}

	// Không khai thứ tự thì banner mới xuống cuối vị trí đó — chen vào giữa dải
	// đang chạy là việc người bán phải chủ động làm, không phải mặc định.
	var sortOrder int
	if req.SortOrder != nil {
		sortOrder = *req.SortOrder
	} else if sortOrder, err = s.repo.NextSortOrder(ctx, position); err != nil {
		return nil, err
	}

	b := &domain.Banner{
		Title:     strings.TrimSpace(req.Title),
		Image:     strings.TrimSpace(req.Image),
		Link:      strings.TrimSpace(req.Link),
		Position:  position,
		SortOrder: sortOrder,
		IsActive:  boolOrDefault(req.IsActive, true),
		StartAt:   start,
		EndAt:     end,
	}
	if err := s.repo.Create(ctx, b); err != nil {
		return nil, err
	}
	return b, nil
}

func (s *bannerService) Update(ctx context.Context, id uint, req dto.BannerRequest) (*domain.Banner, error) {
	b, err := s.repo.FindByID(ctx, id)
	if err != nil {
		return nil, err
	}
	position, err := validBannerPosition(req.Position)
	if err != nil {
		return nil, err
	}
	start, end, err := parseBannerSchedule(req)
	if err != nil {
		return nil, err
	}

	// Chuyển sang vị trí khác mà không khai thứ tự: xếp xuống cuối vị trí mới,
	// giữ nguyên số cũ sẽ làm nó nhảy vào giữa một dải chẳng liên quan.
	sortOrder := b.SortOrder
	switch {
	case req.SortOrder != nil:
		sortOrder = *req.SortOrder
	case position != b.Position:
		if sortOrder, err = s.repo.NextSortOrder(ctx, position); err != nil {
			return nil, err
		}
	}

	b.Title = strings.TrimSpace(req.Title)
	b.Image = strings.TrimSpace(req.Image)
	b.Link = strings.TrimSpace(req.Link)
	b.Position = position
	b.SortOrder = sortOrder
	b.IsActive = boolOrDefault(req.IsActive, b.IsActive)
	b.StartAt = start
	b.EndAt = end

	if err := s.repo.Update(ctx, b); err != nil {
		return nil, err
	}
	return b, nil
}

func (s *bannerService) SetActive(ctx context.Context, id uint, active bool) (*domain.Banner, error) {
	b, err := s.repo.FindByID(ctx, id)
	if err != nil {
		return nil, err
	}
	if err := s.repo.SetActive(ctx, id, active); err != nil {
		return nil, err
	}
	b.IsActive = active
	return b, nil
}

func (s *bannerService) Sort(ctx context.Context, ids []uint) error {
	if len(ids) == 0 {
		return nil
	}

	orders := make(map[uint]int, len(ids))
	for i, id := range ids {
		if id == 0 {
			continue
		}
		// Id gửi trùng nhau thì lần xuất hiện ĐẦU quyết định — người dùng kéo thả
		// không bao giờ tạo ra trùng, nhưng gọi API tay thì có.
		if _, dup := orders[id]; dup {
			continue
		}
		orders[id] = i
	}

	return s.repo.SetSortOrders(ctx, orders)
}

func (s *bannerService) Delete(ctx context.Context, id uint) error {
	if _, err := s.repo.FindByID(ctx, id); err != nil {
		return err
	}
	return s.repo.Delete(ctx, id)
}

// validBannerPosition chuẩn hoá và kiểm tra mã vị trí.
func validBannerPosition(p string) (string, error) {
	p = strings.TrimSpace(p)
	if !domain.IsValidBannerPosition(p) {
		return "", domain.ErrBannerPositionInvalid
	}
	return p, nil
}

// parseBannerSchedule đọc lịch chạy từ request. Chuỗi rỗng = không giới hạn (nil).
func parseBannerSchedule(req dto.BannerRequest) (*time.Time, *time.Time, error) {
	start, err := parseBannerTime(req.StartAt)
	if err != nil {
		return nil, nil, err
	}
	end, err := parseBannerTime(req.EndAt)
	if err != nil {
		return nil, nil, err
	}
	// Khai ngược khoảng thời gian thì banner không bao giờ hiện được ngày nào —
	// chặn ngay lúc lưu, đừng để người bán ngồi chờ một banner chết.
	if start != nil && end != nil && end.Before(*start) {
		return nil, nil, domain.ErrBannerScheduleInvalid
	}
	return start, end, nil
}

func parseBannerTime(v string) (*time.Time, error) {
	v = strings.TrimSpace(v)
	if v == "" {
		return nil, nil
	}
	t, err := time.ParseInLocation(bannerTimeLayout, v, time.Local)
	if err != nil {
		return nil, domain.ErrBannerScheduleInvalid
	}
	return &t, nil
}
