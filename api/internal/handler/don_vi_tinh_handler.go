package handler

import (
	"github.com/gin-gonic/gin"

	"sass-api/internal/domain"
	"sass-api/internal/dto"
	"sass-api/internal/service"
	"sass-api/pkg/response"
)

// DonViTinhHandler — Hàng hóa → Đơn vị.
type DonViTinhHandler struct {
	svc service.DonViTinhService
}

func NewDonViTinhHandler(svc service.DonViTinhService) *DonViTinhHandler {
	return &DonViTinhHandler{svc: svc}
}

// List godoc
//
//	@Summary		Danh sách đơn vị tính
//	@Description	Tìm theo tên hoặc mã. Đặt `active=true` để chỉ lấy đơn vị đang bật — ô chọn đơn vị lúc khai mặt hàng dùng tham số này.
//	@Description	Cố ý KHÔNG phân trang: danh sách đơn vị của một cửa hàng chỉ vài chục dòng, trang quản trị tự cắt trang.
//	@Tags			Admin - Đơn vị tính
//	@Produce		json
//	@Security		BearerAuth
//	@Param			keyword	query		string	false	"Tên hoặc mã đơn vị"
//	@Param			active	query		bool	false	"true = chỉ đơn vị đang bật"
//	@Success		200		{object}	response.Body{data=[]domain.DonViTinh}
//	@Failure		401		{object}	response.Body
//	@Router			/admin/don-vi-tinh [get]
func (h *DonViTinhHandler) List(c *gin.Context) {
	list, err := h.svc.List(c.Request.Context(), domain.DonViTinhFilter{
		Keyword:    c.Query("keyword"),
		OnlyActive: c.Query("active") == "true",
	})
	if err != nil {
		handleServiceError(c, err)

		return
	}
	response.OK(c, list)
}

// Get godoc
//
//	@Summary	Chi tiết một đơn vị tính
//	@Tags		Admin - Đơn vị tính
//	@Produce	json
//	@Security	BearerAuth
//	@Param		id	path		int	true	"ID đơn vị tính"
//	@Success	200	{object}	response.Body{data=domain.DonViTinh}
//	@Failure	404	{object}	response.Body
//	@Router		/admin/don-vi-tinh/{id} [get]
func (h *DonViTinhHandler) Get(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}

	dv, err := h.svc.GetByID(c.Request.Context(), id)
	if err != nil {
		handleServiceError(c, err)

		return
	}
	response.OK(c, dv)
}

// Create godoc
//
//	@Summary		Thêm đơn vị tính
//	@Description	Mã tự viết hoa và phải khác mọi mã đã có TRONG CỬA HÀNG NÀY, kể cả mã của đơn vị đã xoá. Tên so không phân biệt hoa thường nhưng có phân biệt dấu.
//	@Tags			Admin - Đơn vị tính
//	@Accept			json
//	@Produce		json
//	@Security		BearerAuth
//	@Param			body	body		dto.DonViTinhRequest	true	"Thông tin đơn vị tính"
//	@Success		201		{object}	response.Body{data=domain.DonViTinh}
//	@Failure		401		{object}	response.Body
//	@Failure		403		{object}	response.Body
//	@Failure		422		{object}	response.Body
//	@Router			/admin/don-vi-tinh [post]
func (h *DonViTinhHandler) Create(c *gin.Context) {
	var req dto.DonViTinhRequest
	if !bindJSON(c, &req) {
		return
	}

	dv, err := h.svc.Create(c.Request.Context(), &req)
	if err != nil {
		handleServiceError(c, err)

		return
	}
	response.Created(c, dv)
}

// Update godoc
//
//	@Summary	Sửa đơn vị tính
//	@Tags		Admin - Đơn vị tính
//	@Accept		json
//	@Produce	json
//	@Security	BearerAuth
//	@Param		id		path		int						true	"ID đơn vị tính"
//	@Param		body	body		dto.DonViTinhRequest	true	"Thông tin đơn vị tính"
//	@Success	200		{object}	response.Body{data=domain.DonViTinh}
//	@Failure	404		{object}	response.Body
//	@Failure	422		{object}	response.Body
//	@Router		/admin/don-vi-tinh/{id} [put]
func (h *DonViTinhHandler) Update(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}

	var req dto.DonViTinhRequest
	if !bindJSON(c, &req) {
		return
	}

	dv, err := h.svc.Update(c.Request.Context(), id, &req)
	if err != nil {
		handleServiceError(c, err)

		return
	}
	response.OKMessage(c, "Đã cập nhật đơn vị tính", dv)
}

// DoiTrangThai godoc
//
//	@Summary		Bật/tắt một đơn vị tính
//	@Description	Tắt = thôi bày ở ô chọn đơn vị lúc khai mặt hàng. Dòng vẫn còn nên mặt hàng cũ vẫn tra ra được tên đơn vị.
//	@Tags			Admin - Đơn vị tính
//	@Accept			json
//	@Produce		json
//	@Security		BearerAuth
//	@Param			id		path		int								true	"ID đơn vị tính"
//	@Param			body	body		dto.TrangThaiDonViTinhRequest	true	"Trạng thái"
//	@Success		200		{object}	response.Body{data=domain.DonViTinh}
//	@Failure		404		{object}	response.Body
//	@Router			/admin/don-vi-tinh/{id}/trang-thai [put]
func (h *DonViTinhHandler) DoiTrangThai(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}

	var req dto.TrangThaiDonViTinhRequest
	if !bindJSON(c, &req) {
		return
	}

	dv, err := h.svc.DoiTrangThai(c.Request.Context(), id, *req.IsActive)
	if err != nil {
		handleServiceError(c, err)

		return
	}
	response.OKMessage(c, "Đã cập nhật trạng thái", dv)
}

// Delete godoc
//
//	@Summary		Xoá đơn vị tính
//	@Description	Xoá mềm. Đơn vị đã thôi dùng nhưng còn muốn tra lại thì nên TẮT thay vì xoá.
//	@Tags			Admin - Đơn vị tính
//	@Produce		json
//	@Security		BearerAuth
//	@Param			id	path		int	true	"ID đơn vị tính"
//	@Success		200	{object}	response.Body
//	@Failure		404	{object}	response.Body
//	@Router			/admin/don-vi-tinh/{id} [delete]
func (h *DonViTinhHandler) Delete(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}

	if err := h.svc.Delete(c.Request.Context(), id); err != nil {
		handleServiceError(c, err)

		return
	}
	response.OKMessage(c, "Đã xoá đơn vị tính", nil)
}
