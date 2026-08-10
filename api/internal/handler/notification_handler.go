package handler

import (
	"encoding/json"
	"io"
	"strconv"
	"time"

	"sass-api/internal/domain"
	"sass-api/internal/middleware"
	"sass-api/internal/realtime"
	"sass-api/internal/service"
	"sass-api/pkg/logger"
	"sass-api/pkg/response"

	"github.com/gin-gonic/gin"
	"go.uber.org/zap"
)

type NotificationHandler struct {
	svc service.NotificationService
	hub *realtime.Hub
}

func NewNotificationHandler(svc service.NotificationService, hub *realtime.Hub) *NotificationHandler {
	return &NotificationHandler{svc: svc, hub: hub}
}

// channelOf xác định kênh thông báo của người đang gọi.
//
// Nhân viên quản trị đọc kênh chung (nil); khách hàng chỉ đọc đúng kênh của id
// trong token. Không có tham số nào từ client tham gia vào quyết định này.
func channelOf(c *gin.Context) *uint {
	if isAdminRole(c.GetString(middleware.CtxRole)) {
		return nil
	}
	id := currentUserID(c)
	return &id
}

// isAdminRole giữ đúng danh sách vai trò mà nhóm route /admin cho phép — kênh
// thông báo quản trị không được rộng hơn quyền vào trang quản trị.
func isAdminRole(role string) bool {
	return role == domain.RoleSuperAdmin || role == domain.RoleAdmin
}

// List godoc
//
//	@Summary		Danh sách thông báo của tôi
//	@Description	Trả thông báo theo kênh của người gọi: nhân viên quản trị nhận thông báo vận hành (đơn mới, khách huỷ đơn, cảnh báo tồn kho), khách hàng nhận thông báo đơn của chính mình. Kèm `unread_count` để vẽ badge trên chuông.
//	@Description	Các giá trị `type`: `order_created`, `order_cancelled`, `order_status`, `order_payment`, `return_created`, `return_status`, `stock_low`, `stock_out`, `system`. Cột `data` là JSON tự do, giao diện dùng nó để mở đúng trang: `order_code` / `return_code` / `variant_sku` + `stock_level`.
//	@Tags			Notifications
//	@Produce		json
//	@Security		BearerAuth
//	@Param			unread		query		bool	false	"true = chỉ lấy thông báo chưa đọc"
//	@Param			page		query		int		false	"Trang (mặc định 1)"
//	@Param			page_size	query		int		false	"Số dòng mỗi trang (mặc định 20, tối đa 100)"
//	@Success		200			{object}	response.Body
//	@Failure		401			{object}	response.Body
//	@Router			/notifications [get]
func (h *NotificationHandler) List(c *gin.Context) {
	page, _ := strconv.Atoi(c.DefaultQuery("page", "1"))
	size, _ := strconv.Atoi(c.DefaultQuery("page_size", "20"))

	res, err := h.svc.List(c.Request.Context(), domain.NotificationFilter{
		UserID:     channelOf(c),
		OnlyUnread: c.Query("unread") == "true",
		Page:       page,
		PageSize:   size,
	})
	if err != nil {
		handleServiceError(c, err)
		return
	}
	response.OK(c, res)
}

// MarkRead godoc
//
//	@Summary		Đánh dấu một thông báo đã đọc
//	@Tags			Notifications
//	@Produce		json
//	@Security		BearerAuth
//	@Param			id	path		int	true	"ID thông báo"
//	@Success		200	{object}	response.Body
//	@Failure		401	{object}	response.Body
//	@Failure		404	{object}	response.Body
//	@Router			/notifications/{id}/read [put]
func (h *NotificationHandler) MarkRead(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}
	if err := h.svc.MarkRead(c.Request.Context(), id, channelOf(c)); err != nil {
		handleServiceError(c, err)
		return
	}
	unread, err := h.svc.UnreadCount(c.Request.Context(), channelOf(c))
	if err != nil {
		handleServiceError(c, err)
		return
	}
	response.OK(c, gin.H{"unread_count": unread})
}

// MarkAllRead godoc
//
//	@Summary		Đánh dấu tất cả thông báo đã đọc
//	@Tags			Notifications
//	@Produce		json
//	@Security		BearerAuth
//	@Success		200	{object}	response.Body
//	@Failure		401	{object}	response.Body
//	@Router			/notifications/read-all [post]
func (h *NotificationHandler) MarkAllRead(c *gin.Context) {
	n, err := h.svc.MarkAllRead(c.Request.Context(), channelOf(c))
	if err != nil {
		handleServiceError(c, err)
		return
	}
	response.OK(c, gin.H{"marked": n, "unread_count": 0})
}

// TestPush godoc
//
//	@Summary		Bắn một thông báo thử vào kênh quản trị
//	@Description	Chỉ dùng để kiểm tra đường truyền realtime khi cài đặt: tạo một thông báo thật trong kênh quản trị và đẩy kèm một tín hiệu `order`. KHÔNG được đăng ký khi APP_ENV=production.
//	@Tags			Notifications
//	@Produce		json
//	@Security		BearerAuth
//	@Success		200	{object}	response.Body
//	@Failure		401	{object}	response.Body
//	@Failure		403	{object}	response.Body
//	@Router			/admin/notifications/test [post]
func (h *NotificationHandler) TestPush(c *gin.Context) {
	n := h.svc.Push(c.Request.Context(), nil, "system",
		"Thông báo thử",
		"Nếu bạn thấy dòng này hiện lên mà không tải lại trang thì realtime đang chạy đúng.",
		map[string]any{"test": true})
	if n == nil {
		response.Error(c, 500, "Không lưu được thông báo thử")
		return
	}

	// Kèm luôn tín hiệu `order` để kiểm tra cả nhánh làm mới bảng đơn hàng.
	h.svc.Signal(c.Request.Context(), realtime.TopicAdmin, realtime.EventOrder, gin.H{"test": true})

	response.OK(c, gin.H{"notification": n, "clients": h.hub.Count()})
}

// Stream godoc
//
//	@Summary		Luồng sự kiện thời gian thực (SSE)
//	@Description	Giữ một kết nối mở và đẩy sự kiện xuống trình duyệt: `notification` (thông báo mới, hiện lên chuông — gồm cả cảnh báo hàng sắp hết / hết hàng) và `order` (đơn vừa tạo/đổi trạng thái, màn hình nên tự làm mới). Vì EventSource không đặt được header Authorization nên endpoint này nhận thêm access token qua query `?token=`.
//	@Tags			Notifications
//	@Produce		text/event-stream
//	@Security		BearerAuth
//	@Param			token	query		string	false	"Access token (thay cho header Authorization)"
//	@Success		200		{string}	string	"Luồng SSE"
//	@Failure		401		{object}	response.Body
//	@Router			/events [get]
func (h *NotificationHandler) Stream(c *gin.Context) {
	userID := currentUserID(c)
	role := c.GetString(middleware.CtxRole)

	// Kênh nghe được quyết định hoàn toàn từ token: client không gửi lên topic nào.
	topics := []string{realtime.TopicUser(userID)}
	if isAdminRole(role) {
		topics = append(topics, realtime.TopicAdmin)
	}

	sub := h.hub.Subscribe(topics...)
	defer sub.Close()

	c.Header("Content-Type", "text/event-stream")
	c.Header("Cache-Control", "no-cache")
	c.Header("Connection", "keep-alive")
	// Tắt buffering của nginx — có buffer thì sự kiện bị giữ lại và "thời gian
	// thực" biến thành "vài chục giây sau".
	c.Header("X-Accel-Buffering", "no")

	logger.Info("mở luồng SSE", zap.Uint("user_id", userID), zap.String("role", role), zap.Int("clients", h.hub.Count()))

	ping := time.NewTicker(realtime.HeartbeatInterval)
	defer ping.Stop()

	// Gói tin đầu tiên báo cho client biết đã nối được, để giao diện hiện đúng
	// trạng thái kết nối thay vì đợi mơ hồ tới sự kiện thật đầu tiên.
	c.SSEvent("ready", gin.H{"topics": topics})
	c.Writer.Flush()

	ctx := c.Request.Context()
	c.Stream(func(w io.Writer) bool {
		select {
		case ev, ok := <-sub.C:
			if !ok {
				return false
			}
			// Payload đi xuống dạng JSON để client chỉ cần JSON.parse(e.data).
			raw, err := json.Marshal(ev.Payload)
			if err != nil {
				logger.Error("mã hoá sự kiện SSE thất bại", zap.String("type", ev.Type), zap.Error(err))
				return true
			}
			c.SSEvent(ev.Type, string(raw))
			return true
		case <-ping.C:
			// Comment SSE: giữ kết nối sống, client bỏ qua dòng này.
			_, err := w.Write([]byte(": ping\n\n"))
			return err == nil
		case <-ctx.Done():
			return false
		}
	})
}
