package handler

import (
	"net/http"
	"strconv"
	"time"

	"github.com/gin-gonic/gin"

	"sass-api/internal/domain"
	"sass-api/internal/dto"
	"sass-api/internal/service"
	"sass-api/pkg/response"
)

type ContactHandler struct {
	svc service.ContactService
}

func NewContactHandler(svc service.ContactService) *ContactHandler {
	return &ContactHandler{svc: svc}
}

// ---------- Công khai (storefront gọi) ----------

// Create godoc
//
//	@Summary		Gửi yêu cầu liên hệ / thu mua
//	@Description	Nhận nội dung khách điền ở form Liên hệ hoặc form Thu mua áo đấu trên storefront.
//	@Description
//	@Description	`type` để trống thì hiểu là `lien-he`. `images` là danh sách URL ảnh —
//	@Description	storefront tự nhận tệp, kiểm tra rồi cất vào ổ đĩa của nó, đường này KHÔNG nhận tệp.
//	@Tags			Contact
//	@Accept			json
//	@Produce		json
//	@Param			body	body		dto.CreateContactRequest	true	"Nội dung yêu cầu"
//	@Success		201		{object}	response.Body{data=dto.ContactRequestResponse}
//	@Failure		422		{object}	response.Body
//	@Failure		429		{object}	response.Body	"Gửi quá dày"
//	@Router			/contact-requests [post]
func (h *ContactHandler) Create(c *gin.Context) {
	var req dto.CreateContactRequest
	if !bindJSON(c, &req) {
		return
	}

	res, err := h.svc.Create(c.Request.Context(), req, c.ClientIP())
	if err != nil {
		handleServiceError(c, err)
		return
	}
	response.Created(c, res)
}

// Subscribe godoc
//
//	@Summary		Đăng ký nhận tin
//	@Description	Thêm email vào danh sách nhận tin ở chân trang.
//	@Description
//	@Description	Email đã có trong danh sách vẫn trả 200: người dùng không nhớ mình từng
//	@Description	đăng ký hay chưa, mà báo "email này đã đăng ký rồi" thì vừa vô ích vừa lộ
//	@Description	ra ai đang có trong danh sách.
//	@Tags			Contact
//	@Accept			json
//	@Produce		json
//	@Param			body	body		dto.SubscribeNewsletterRequest	true	"Email đăng ký"
//	@Success		200		{object}	response.Body
//	@Failure		422		{object}	response.Body
//	@Failure		429		{object}	response.Body	"Gửi quá dày"
//	@Router			/newsletter/subscribe [post]
func (h *ContactHandler) Subscribe(c *gin.Context) {
	var req dto.SubscribeNewsletterRequest
	if !bindJSON(c, &req) {
		return
	}

	if err := h.svc.Subscribe(c.Request.Context(), req, c.ClientIP()); err != nil {
		handleServiceError(c, err)
		return
	}
	response.OKMessage(c, "Cảm ơn bạn đã đăng ký nhận tin!", nil)
}

// ---------- Quản trị ----------

// List godoc
//
//	@Summary		Danh sách yêu cầu của khách
//	@Description	Yêu cầu gửi từ form Liên hệ và form Thu mua, mới nhất lên trước.
//	@Tags			Admin - Contact
//	@Accept			json
//	@Produce		json
//	@Param			keyword		query		string	false	"Tên / SĐT / email / chủ đề / nội dung"
//	@Param			type		query		string	false	"all | lien-he | thu-mua"
//	@Param			status		query		string	false	"all | moi | dang-xu-ly | da-xong"
//	@Param			from		query		string	false	"Từ ngày (YYYY-MM-DD)"
//	@Param			to			query		string	false	"Đến ngày (YYYY-MM-DD)"
//	@Param			page		query		int		false	"Trang, mặc định 1"
//	@Param			page_size	query		int		false	"Số dòng mỗi trang, mặc định 20"
//	@Success		200			{object}	response.Body{data=[]dto.ContactRequestResponse}
//	@Failure		401			{object}	response.Body
//	@Security		BearerAuth
//	@Router			/admin/contact-requests [get]
func (h *ContactHandler) List(c *gin.Context) {
	page, _ := strconv.Atoi(c.DefaultQuery("page", "1"))
	size, _ := strconv.Atoi(c.DefaultQuery("page_size", "20"))

	f := domain.ContactFilter{
		Keyword:  c.Query("keyword"),
		Type:     c.Query("type"),
		Status:   c.Query("status"),
		From:     parseNgay(c.Query("from"), false),
		To:       parseNgay(c.Query("to"), true),
		Page:     page,
		PageSize: size,
	}

	list, total, err := h.svc.List(c.Request.Context(), f)
	if err != nil {
		response.Error(c, http.StatusInternalServerError, "Lỗi truy vấn danh sách yêu cầu")
		return
	}

	if f.Page < 1 {
		f.Page = 1
	}
	if f.PageSize < 1 {
		f.PageSize = 20
	}
	response.Paginated(c, list, response.Pagination{
		Page:       f.Page,
		PageSize:   f.PageSize,
		Total:      total,
		TotalPages: int((total + int64(f.PageSize) - 1) / int64(f.PageSize)),
	})
}

// Stats godoc
//
//	@Summary		Đếm yêu cầu theo trạng thái
//	@Description	Trả về số yêu cầu ở mỗi trạng thái (moi / dang-xu-ly / da-xong) để hiện huy hiệu "chưa xử lý" trên sidebar.
//	@Tags			Admin - Contact
//	@Produce		json
//	@Success		200	{object}	response.Body
//	@Failure		401	{object}	response.Body
//	@Security		BearerAuth
//	@Router			/admin/contact-requests/stats [get]
func (h *ContactHandler) Stats(c *gin.Context) {
	stats, err := h.svc.Stats(c.Request.Context())
	if err != nil {
		response.Error(c, http.StatusInternalServerError, "Lỗi thống kê yêu cầu")
		return
	}
	response.OK(c, stats)
}

// Get godoc
//
//	@Summary		Chi tiết một yêu cầu
//	@Tags			Admin - Contact
//	@Produce		json
//	@Param			id	path		int	true	"ID yêu cầu"
//	@Success		200	{object}	response.Body{data=dto.ContactRequestResponse}
//	@Failure		404	{object}	response.Body
//	@Security		BearerAuth
//	@Router			/admin/contact-requests/{id} [get]
func (h *ContactHandler) Get(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}

	res, err := h.svc.Get(c.Request.Context(), id)
	if err != nil {
		handleServiceError(c, err)
		return
	}
	response.OK(c, res)
}

// UpdateStatus godoc
//
//	@Summary		Đổi trạng thái xử lý
//	@Description	Chuyển sang `da-xong` thì ghi kèm người xử lý và thời điểm; kéo về trạng thái khác thì xoá dấu vết đó đi.
//	@Tags			Admin - Contact
//	@Accept			json
//	@Produce		json
//	@Param			id		path		int								true	"ID yêu cầu"
//	@Param			body	body		dto.UpdateContactStatusRequest	true	"Trạng thái mới"
//	@Success		200		{object}	response.Body{data=dto.ContactRequestResponse}
//	@Failure		404		{object}	response.Body
//	@Failure		422		{object}	response.Body
//	@Security		BearerAuth
//	@Router			/admin/contact-requests/{id}/status [put]
func (h *ContactHandler) UpdateStatus(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}

	var req dto.UpdateContactStatusRequest
	if !bindJSON(c, &req) {
		return
	}

	res, err := h.svc.UpdateStatus(c.Request.Context(), id, req, currentUserID(c))
	if err != nil {
		handleServiceError(c, err)
		return
	}
	response.OK(c, res)
}

// Delete godoc
//
//	@Summary		Xoá một yêu cầu
//	@Description	Xoá mềm — dòng vẫn nằm trong CSDL, chỉ không hiện ra danh sách nữa.
//	@Tags			Admin - Contact
//	@Produce		json
//	@Param			id	path		int	true	"ID yêu cầu"
//	@Success		200	{object}	response.Body
//	@Failure		404	{object}	response.Body
//	@Security		BearerAuth
//	@Router			/admin/contact-requests/{id} [delete]
func (h *ContactHandler) Delete(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}

	if err := h.svc.Delete(c.Request.Context(), id); err != nil {
		handleServiceError(c, err)
		return
	}
	response.OKMessage(c, "Đã xoá yêu cầu", nil)
}

// Subscribers godoc
//
//	@Summary		Danh sách email nhận tin
//	@Tags			Admin - Contact
//	@Produce		json
//	@Param			keyword		query		string	false	"Lọc theo email"
//	@Param			status		query		string	false	"all | active | inactive"
//	@Param			page		query		int		false	"Trang, mặc định 1"
//	@Param			page_size	query		int		false	"Số dòng mỗi trang, mặc định 20"
//	@Success		200			{object}	response.Body{data=[]domain.NewsletterSubscriber}
//	@Failure		401			{object}	response.Body
//	@Security		BearerAuth
//	@Router			/admin/newsletter [get]
func (h *ContactHandler) Subscribers(c *gin.Context) {
	page, _ := strconv.Atoi(c.DefaultQuery("page", "1"))
	size, _ := strconv.Atoi(c.DefaultQuery("page_size", "20"))
	if page < 1 {
		page = 1
	}
	if size < 1 {
		size = 20
	}

	list, total, err := h.svc.ListSubscribers(c.Request.Context(), domain.NewsletterFilter{
		Keyword:  c.Query("keyword"),
		Status:   c.Query("status"),
		Page:     page,
		PageSize: size,
	})
	if err != nil {
		response.Error(c, http.StatusInternalServerError, "Lỗi truy vấn danh sách nhận tin")
		return
	}

	response.Paginated(c, list, response.Pagination{
		Page:       page,
		PageSize:   size,
		Total:      total,
		TotalPages: int((total + int64(size) - 1) / int64(size)),
	})
}

// Unsubscribe godoc
//
//	@Summary		Gỡ một email khỏi danh sách nhận tin
//	@Description	TẮT chứ không xoá dòng: xoá đi thì lần sau khách gõ lại email đó là được thêm mới như chưa từng huỷ.
//	@Tags			Admin - Contact
//	@Produce		json
//	@Param			id	path		int	true	"ID người đăng ký"
//	@Success		200	{object}	response.Body
//	@Failure		404	{object}	response.Body
//	@Security		BearerAuth
//	@Router			/admin/newsletter/{id}/unsubscribe [put]
func (h *ContactHandler) Unsubscribe(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}

	if err := h.svc.Unsubscribe(c.Request.Context(), id); err != nil {
		handleServiceError(c, err)
		return
	}
	response.OKMessage(c, "Đã gỡ khỏi danh sách nhận tin", nil)
}

// parseNgay đọc tham số ngày dạng YYYY-MM-DD.
//
// cuoiNgay=true thì đẩy tới 23:59:59.999 của ngày đó: lọc "đến ngày 05/08" mà
// lấy mốc 00:00 thì mất sạch yêu cầu gửi trong chính ngày 05/08.
func parseNgay(s string, cuoiNgay bool) *time.Time {
	if s == "" {
		return nil
	}
	t, err := time.ParseInLocation("2006-01-02", s, time.Local)
	if err != nil {
		return nil
	}
	if cuoiNgay {
		t = t.Add(24*time.Hour - time.Millisecond)
	}
	return &t
}
