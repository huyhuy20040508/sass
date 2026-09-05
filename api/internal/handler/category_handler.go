package handler

import (
	"github.com/gin-gonic/gin"

	"sass-api/internal/dto"
	"sass-api/internal/service"
	"sass-api/pkg/response"
)

type CategoryHandler struct {
	svc service.CategoryService
}

func NewCategoryHandler(svc service.CategoryService) *CategoryHandler {
	return &CategoryHandler{svc: svc}
}

// List godoc
//
//	@Summary	Danh sách danh mục
//	@Tags		Categories
//	@Produce	json
//	@Param		all				query		bool	false	"true = lấy cả danh mục ẩn"
//	@Param		has_products	query		bool	false	"true = chỉ nhóm đang có mặt hàng"
//	@Success	200				{object}	response.Body
//	@Router		/categories [get]
func (h *CategoryHandler) List(c *gin.Context) {
	onlyActive := c.Query("all") != "true"

	// has_products dành cho các Ô LỌC nhóm: chúng đứng cạnh một bảng hàng hoá,
	// bày ra nhóm rỗng là mời người dùng bấm vào để nhận bảng trắng. Danh sách
	// dùng để CHỌN NHÓM lúc khai mặt hàng thì không truyền tham số này — nhóm
	// mới lập chưa có hàng nào mà biến mất khỏi ô chọn là không khai được hàng.
	if c.Query("has_products") == "true" {
		cats, err := h.svc.ListCoHang(c.Request.Context(), onlyActive)
		if err != nil {
			handleServiceError(c, err)
			return
		}
		response.OK(c, cats)

		return
	}

	cats, err := h.svc.List(c.Request.Context(), onlyActive)
	if err != nil {
		handleServiceError(c, err)
		return
	}
	response.OK(c, cats)
}

// Get godoc
//
//	@Summary	Chi tiết danh mục
//	@Tags		Categories
//	@Produce	json
//	@Param		id	path		int	true	"ID danh mục"
//	@Success	200	{object}	response.Body
//	@Failure	404	{object}	response.Body
//	@Router		/categories/{id} [get]
func (h *CategoryHandler) Get(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}
	cat, err := h.svc.Get(c.Request.Context(), id)
	if err != nil {
		handleServiceError(c, err)
		return
	}
	response.OK(c, cat)
}

// Create godoc
//
//	@Summary	Tạo danh mục
//	@Tags		Admin - Categories
//	@Accept		json
//	@Produce	json
//	@Security	BearerAuth
//	@Param		body	body		dto.CategoryRequest	true	"Dữ liệu danh mục"
//	@Success	201		{object}	response.Body
//	@Failure	400		{object}	response.Body
//	@Failure	401		{object}	response.Body
//	@Failure	403		{object}	response.Body
//	@Failure	409		{object}	response.Body
//	@Failure	422		{object}	response.Body
//	@Router		/admin/categories [post]
func (h *CategoryHandler) Create(c *gin.Context) {
	var req dto.CategoryRequest
	if !bindJSON(c, &req) {
		return
	}
	cat, err := h.svc.Create(c.Request.Context(), req)
	if err != nil {
		handleServiceError(c, err)
		return
	}
	response.Created(c, cat)
}

// Update godoc
//
//	@Summary	Cập nhật danh mục
//	@Tags		Admin - Categories
//	@Accept		json
//	@Produce	json
//	@Security	BearerAuth
//	@Param		id		path		int					true	"ID danh mục"
//	@Param		body	body		dto.CategoryRequest	true	"Dữ liệu danh mục"
//	@Success	200		{object}	response.Body
//	@Failure	400		{object}	response.Body
//	@Failure	401		{object}	response.Body
//	@Failure	403		{object}	response.Body
//	@Failure	404		{object}	response.Body
//	@Failure	422		{object}	response.Body
//	@Router		/admin/categories/{id} [put]
func (h *CategoryHandler) Update(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}
	var req dto.CategoryRequest
	if !bindJSON(c, &req) {
		return
	}
	cat, err := h.svc.Update(c.Request.Context(), id, req)
	if err != nil {
		handleServiceError(c, err)
		return
	}
	response.OKMessage(c, "Cập nhật thành công", cat)
}

// Delete godoc
//
//	@Summary	Xóa danh mục
//	@Tags		Admin - Categories
//	@Produce	json
//	@Security	BearerAuth
//	@Param		id	path		int	true	"ID danh mục"
//	@Success	200	{object}	response.Body
//	@Failure	400	{object}	response.Body
//	@Failure	401	{object}	response.Body
//	@Failure	403	{object}	response.Body
//	@Failure	404	{object}	response.Body
//	@Router		/admin/categories/{id} [delete]
func (h *CategoryHandler) Delete(c *gin.Context) {
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
