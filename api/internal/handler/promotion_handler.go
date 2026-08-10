package handler

import (
	"github.com/gin-gonic/gin"

	"sass-api/internal/domain"
	"sass-api/internal/dto"
	"sass-api/internal/service"
	"sass-api/pkg/response"
)

type PromotionHandler struct {
	svc service.PromotionService
}

func NewPromotionHandler(svc service.PromotionService) *PromotionHandler {
	return &PromotionHandler{svc: svc}
}

// List godoc
//
//	@Summary		Danh sách chương trình khuyến mãi
//	@Description	Trả toàn bộ chương trình kèm phạm vi áp dụng đã tách sẵn thành ba danh sách id (sản phẩm / danh mục / thương hiệu).
//	@Description	`status` của mỗi dòng được suy ra từ ngày giờ + trạng thái bật tắt: running | scheduled | ended | paused.
//	@Tags			Admin - Promotions
//	@Produce		json
//	@Security		BearerAuth
//	@Param			keyword		query		string	false	"Tìm theo tên chương trình"
//	@Param			status		query		string	false	"all | running | scheduled | ended | paused"
//	@Param			from_date	query		string	false	"Từ ngày (YYYY-MM-DD) — chương trình có chạy trong khoảng"
//	@Param			to_date		query		string	false	"Đến ngày (YYYY-MM-DD)"
//	@Param			sort		query		string	false	"newest|oldest|start_asc|start_desc|name_asc"
//	@Param			page		query		int		false	"Trang (mặc định 1)"
//	@Param			page_size	query		int		false	"Số item/trang (mặc định 20, tối đa 100)"
//	@Success		200			{object}	response.Body{data=[]dto.PromotionResponse}
//	@Failure		401			{object}	response.Body
//	@Router			/admin/promotions [get]
func (h *PromotionHandler) List(c *gin.Context) {
	f := domain.PromotionFilter{
		Keyword:  c.Query("keyword"),
		Status:   c.Query("status"),
		FromDate: c.Query("from_date"),
		ToDate:   c.Query("to_date"),
		Sort:     c.Query("sort"),
		Page:     queryInt(c, "page", 1),
		PageSize: queryInt(c, "page_size", 20),
	}
	if f.Page < 1 {
		f.Page = 1
	}
	if f.PageSize < 1 || f.PageSize > 100 {
		f.PageSize = 20
	}

	items, total, err := h.svc.List(c.Request.Context(), f)
	if err != nil {
		handleServiceError(c, err)
		return
	}

	totalPages := int((total + int64(f.PageSize) - 1) / int64(f.PageSize))
	response.Paginated(c, items, response.Pagination{
		Page:       f.Page,
		PageSize:   f.PageSize,
		Total:      total,
		TotalPages: totalPages,
	})
}

// Stats godoc
//
//	@Summary		Số đếm chương trình khuyến mãi theo trạng thái
//	@Description	Dùng cho dải thẻ tóm tắt trên đầu trang. Không phụ thuộc bộ lọc đang chọn.
//	@Tags			Admin - Promotions
//	@Produce		json
//	@Security		BearerAuth
//	@Success		200	{object}	response.Body{data=domain.PromotionStats}
//	@Failure		401	{object}	response.Body
//	@Router			/admin/promotions/stats [get]
func (h *PromotionHandler) Stats(c *gin.Context) {
	s, err := h.svc.Stats(c.Request.Context())
	if err != nil {
		handleServiceError(c, err)
		return
	}
	response.OK(c, s)
}

// Get godoc
//
//	@Summary		Chi tiết một chương trình khuyến mãi
//	@Tags			Admin - Promotions
//	@Produce		json
//	@Security		BearerAuth
//	@Param			id	path		int	true	"ID chương trình"
//	@Success		200	{object}	response.Body{data=dto.PromotionResponse}
//	@Failure		401	{object}	response.Body
//	@Failure		404	{object}	response.Body
//	@Router			/admin/promotions/{id} [get]
func (h *PromotionHandler) Get(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}
	p, err := h.svc.Get(c.Request.Context(), id)
	if err != nil {
		handleServiceError(c, err)
		return
	}
	response.OK(c, p)
}

// Create godoc
//
//	@Summary		Tạo chương trình khuyến mãi
//	@Description	Phải chọn ít nhất một sản phẩm, danh mục hoặc thương hiệu — chương trình không phạm vi thì không giảm cho ai.
//	@Description	Chọn danh mục cha là phủ luôn mọi danh mục con/cháu. Sản phẩm dính nhiều chương trình thì lấy mức giảm CÓ LỢI NHẤT, không cộng dồn.
//	@Tags			Admin - Promotions
//	@Accept			json
//	@Produce		json
//	@Security		BearerAuth
//	@Param			body	body		dto.PromotionRequest	true	"Dữ liệu chương trình"
//	@Success		201		{object}	response.Body{data=dto.PromotionResponse}
//	@Failure		401		{object}	response.Body
//	@Failure		422		{object}	response.Body	"Sai thời gian chạy, sai % giảm, hoặc chưa chọn phạm vi"
//	@Router			/admin/promotions [post]
func (h *PromotionHandler) Create(c *gin.Context) {
	var req dto.PromotionRequest
	if !bindJSON(c, &req) {
		return
	}
	p, err := h.svc.Create(c.Request.Context(), req)
	if err != nil {
		handleServiceError(c, err)
		return
	}
	response.Created(c, p)
}

// Update godoc
//
//	@Summary		Cập nhật chương trình khuyến mãi
//	@Description	Phạm vi áp dụng bị THAY TOÀN BỘ theo ba danh sách id gửi lên, không phải cộng thêm.
//	@Tags			Admin - Promotions
//	@Accept			json
//	@Produce		json
//	@Security		BearerAuth
//	@Param			id		path		int						true	"ID chương trình"
//	@Param			body	body		dto.PromotionRequest	true	"Dữ liệu chương trình"
//	@Success		200		{object}	response.Body{data=dto.PromotionResponse}
//	@Failure		401		{object}	response.Body
//	@Failure		404		{object}	response.Body
//	@Failure		422		{object}	response.Body
//	@Router			/admin/promotions/{id} [put]
func (h *PromotionHandler) Update(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}
	var req dto.PromotionRequest
	if !bindJSON(c, &req) {
		return
	}
	p, err := h.svc.Update(c.Request.Context(), id, req)
	if err != nil {
		handleServiceError(c, err)
		return
	}
	response.OKMessage(c, "Đã cập nhật chương trình khuyến mãi", p)
}

// UpdateStatus godoc
//
//	@Summary		Bật / tắt chương trình khuyến mãi
//	@Description	Dừng một đợt giữa chừng mà không phải sửa ngày kết thúc.
//	@Tags			Admin - Promotions
//	@Accept			json
//	@Produce		json
//	@Security		BearerAuth
//	@Param			id		path		int							true	"ID chương trình"
//	@Param			body	body		dto.PromotionStatusRequest	true	"Trạng thái"
//	@Success		200		{object}	response.Body
//	@Failure		401		{object}	response.Body
//	@Failure		404		{object}	response.Body
//	@Router			/admin/promotions/{id}/status [put]
func (h *PromotionHandler) UpdateStatus(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}
	var req dto.PromotionStatusRequest
	if !bindJSON(c, &req) {
		return
	}
	if err := h.svc.SetActive(c.Request.Context(), id, *req.IsActive); err != nil {
		handleServiceError(c, err)
		return
	}
	msg := "Đã tạm dừng chương trình"
	if *req.IsActive {
		msg = "Đã bật chương trình"
	}
	response.OKMessage(c, msg, nil)
}

// Delete godoc
//
//	@Summary		Xoá chương trình khuyến mãi
//	@Description	Xoá mềm. Phạm vi áp dụng đi theo, giá sản phẩm tự về như cũ ngay lập tức.
//	@Tags			Admin - Promotions
//	@Produce		json
//	@Security		BearerAuth
//	@Param			id	path		int	true	"ID chương trình"
//	@Success		200	{object}	response.Body
//	@Failure		401	{object}	response.Body
//	@Failure		404	{object}	response.Body
//	@Router			/admin/promotions/{id} [delete]
func (h *PromotionHandler) Delete(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}
	if err := h.svc.Delete(c.Request.Context(), id); err != nil {
		handleServiceError(c, err)
		return
	}
	response.OKMessage(c, "Đã xoá chương trình khuyến mãi", nil)
}
