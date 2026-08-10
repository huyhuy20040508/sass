package service

import (
	"context"
	"encoding/json"
	"strings"

	"sass-api/internal/domain"
	"sass-api/internal/realtime"
	"sass-api/pkg/logger"

	"go.uber.org/zap"
)

// NotificationList là kết quả trả cho chuông thông báo: danh sách + số chưa đọc.
// Trả kèm số chưa đọc để giao diện chỉ cần một request cho cả badge lẫn danh sách.
type NotificationList struct {
	Items       []domain.Notification `json:"items"`
	Total       int64                 `json:"total"`
	UnreadCount int64                 `json:"unread_count"`
}

type NotificationService interface {
	List(ctx context.Context, f domain.NotificationFilter) (*NotificationList, error)
	UnreadCount(ctx context.Context, userID *uint) (int64, error)
	MarkRead(ctx context.Context, id uint, userID *uint) error
	MarkAllRead(ctx context.Context, userID *uint) (int64, error)

	// Push lưu một thông báo rồi đẩy ngay xuống các trình duyệt đang mở của kênh
	// tương ứng. userID = nil là kênh quản trị.
	Push(ctx context.Context, userID *uint, nType, title, content string, data map[string]any) *domain.Notification
	// Signal đẩy một tín hiệu KHÔNG lưu database (vd: "đơn vừa đổi, làm mới bảng đi").
	Signal(ctx context.Context, topic string, evType string, payload any)
}

type notificationService struct {
	repo domain.NotificationRepository
	hub  *realtime.Hub
}

func NewNotificationService(repo domain.NotificationRepository, hub *realtime.Hub) NotificationService {
	return &notificationService{repo: repo, hub: hub}
}

func (s *notificationService) List(ctx context.Context, f domain.NotificationFilter) (*NotificationList, error) {
	items, total, err := s.repo.List(ctx, f)
	if err != nil {
		return nil, err
	}
	unread, err := s.repo.UnreadCount(ctx, f.UserID)
	if err != nil {
		return nil, err
	}
	return &NotificationList{Items: items, Total: total, UnreadCount: unread}, nil
}

func (s *notificationService) UnreadCount(ctx context.Context, userID *uint) (int64, error) {
	return s.repo.UnreadCount(ctx, userID)
}

func (s *notificationService) MarkRead(ctx context.Context, id uint, userID *uint) error {
	return s.repo.MarkRead(ctx, id, userID)
}

func (s *notificationService) MarkAllRead(ctx context.Context, userID *uint) (int64, error) {
	return s.repo.MarkAllRead(ctx, userID)
}

// Push lưu thông báo rồi phát đi.
//
// Lỗi ghi database chỉ được ghi log và trả nil: thông báo là phần phụ trợ, không
// được phép làm hỏng thao tác đã thành công (đơn đã đặt, trạng thái đã đổi) chỉ
// vì bảng notifications có vấn đề.
func (s *notificationService) Push(ctx context.Context, userID *uint, nType, title, content string, data map[string]any) *domain.Notification {
	n := &domain.Notification{
		UserID:  userID,
		Type:    nType,
		Title:   strings.TrimSpace(title),
		Content: strings.TrimSpace(content),
	}
	if len(data) > 0 {
		if raw, err := json.Marshal(data); err == nil {
			n.Data = string(raw)
		}
	}

	if err := s.repo.Create(ctx, n); err != nil {
		logger.Error("lưu thông báo thất bại",
			zap.String("type", nType), zap.String("title", n.Title), zap.Error(err))
		return nil
	}

	topic := realtime.TopicAdmin
	if userID != nil {
		topic = realtime.TopicUser(*userID)
	}
	s.hub.Publish(topic, realtime.Event{Type: realtime.EventNotification, Payload: n})
	return n
}

func (s *notificationService) Signal(_ context.Context, topic string, evType string, payload any) {
	s.hub.Publish(topic, realtime.Event{Type: evType, Payload: payload})
}
