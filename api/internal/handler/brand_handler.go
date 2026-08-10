package handler

import (
	"github.com/gin-gonic/gin"

	"sass-api/internal/dto"
	"sass-api/internal/service"
	"sass-api/pkg/response"
)

type BrandHandler struct {
	svc service.BrandService
}

func NewBrandHandler(svc service.BrandService) *BrandHandler {
	return &BrandHandler{svc: svc}
}

// List godoc
//
//	@Summary		Danh sách thương hiệu
//	@Description	Mỗi thương hiệu kèm `product_count` — số sản phẩm (chưa xoá) đang gắn thương hiệu đó.
//	@Tags			Brands
//	@Produce		json
//	@Param			all	query		bool	false	"true = lấy cả thương hiệu ẩn"
//	@Success		200	{object}	response.Body
//	@Router			/brands [get]
func (h *BrandHandler) List(c *gin.Context) {
	onlyActive := c.Query("all") != "true"
	brands, err := h.svc.List(c.Request.Context(), onlyActive)
	if err != nil {
		handleServiceError(c, err)
		return
	}
	response.OK(c, brands)
}

// Get godoc
//
//	@Summary		Chi tiết thương hiệu
//	@Description	Kèm `product_count` — số sản phẩm (chưa xoá) đang gắn thương hiệu này.
//	@Tags			Brands
//	@Produce		json
//	@Param			id	path		int	true	"ID thương hiệu"
//	@Success		200	{object}	response.Body
//	@Failure		404	{object}	response.Body
//	@Router			/brands/{id} [get]
func (h *BrandHandler) Get(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}
	b, err := h.svc.Get(c.Request.Context(), id)
	if err != nil {
		handleServiceError(c, err)
		return
	}
	response.OK(c, b)
}

// Create godoc
//
//	@Summary	Tạo thương hiệu
//	@Tags		Admin - Brands
//	@Accept		json
//	@Produce	json
//	@Security	BearerAuth
//	@Param		body	body		dto.BrandRequest	true	"Dữ liệu thương hiệu"
//	@Success	201		{object}	response.Body
//	@Failure	400		{object}	response.Body
//	@Failure	401		{object}	response.Body
//	@Failure	403		{object}	response.Body
//	@Failure	409		{object}	response.Body
//	@Failure	422		{object}	response.Body
//	@Router		/admin/brands [post]
func (h *BrandHandler) Create(c *gin.Context) {
	var req dto.BrandRequest
	if !bindJSON(c, &req) {
		return
	}
	b, err := h.svc.Create(c.Request.Context(), req)
	if err != nil {
		handleServiceError(c, err)
		return
	}
	response.Created(c, b)
}

// Update godoc
//
//	@Summary	Cập nhật thương hiệu
//	@Tags		Admin - Brands
//	@Accept		json
//	@Produce	json
//	@Security	BearerAuth
//	@Param		id		path		int					true	"ID thương hiệu"
//	@Param		body	body		dto.BrandRequest	true	"Dữ liệu thương hiệu"
//	@Success	200		{object}	response.Body
//	@Failure	400		{object}	response.Body
//	@Failure	401		{object}	response.Body
//	@Failure	403		{object}	response.Body
//	@Failure	404		{object}	response.Body
//	@Failure	422		{object}	response.Body
//	@Router		/admin/brands/{id} [put]
func (h *BrandHandler) Update(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}
	var req dto.BrandRequest
	if !bindJSON(c, &req) {
		return
	}
	b, err := h.svc.Update(c.Request.Context(), id, req)
	if err != nil {
		handleServiceError(c, err)
		return
	}
	response.OKMessage(c, "Cập nhật thành công", b)
}

// Delete godoc
//
//	@Summary	Xóa thương hiệu
//	@Tags		Admin - Brands
//	@Produce	json
//	@Security	BearerAuth
//	@Param		id	path		int	true	"ID thương hiệu"
//	@Success	200	{object}	response.Body
//	@Failure	400	{object}	response.Body
//	@Failure	401	{object}	response.Body
//	@Failure	403	{object}	response.Body
//	@Failure	404	{object}	response.Body
//	@Failure	409	{object}	response.Body
//	@Router		/admin/brands/{id} [delete]
func (h *BrandHandler) Delete(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}
	if err := h.svc.Delete(c.Request.Context(), id); err != nil {
		handleServiceError(c, err)
		return
	}
	response.OKMessage(c, "Xóa thành công", nil)
}
