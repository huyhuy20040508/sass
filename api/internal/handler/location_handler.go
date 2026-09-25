package handler

import (
	"github.com/gin-gonic/gin"

	"sass-api/internal/domain"
	"sass-api/internal/dto"
	"sass-api/internal/service"
	"sass-api/pkg/response"
)

// ViTriHandler — Hàng hóa → Vị trí.
type ViTriHandler struct {
	svc service.ViTriService
}

func NewViTriHandler(svc service.ViTriService) *ViTriHandler {
	return &ViTriHandler{svc: svc}
}

// List godoc
//
//	@Summary		Danh sách vị trí
//	@Description	Tìm theo tên hoặc mã. Đặt `active=true` để chỉ lấy vị trí đang bật — ô chọn vị trí lúc khai mặt hàng dùng tham số này.
//	@Description	Cố ý KHÔNG phân trang: danh sách vị trí của một cửa hàng chỉ vài chục dòng, trang quản trị tự cắt trang.
//	@Tags			Admin - Vị trí
//	@Produce		json
//	@Security		BearerAuth
//	@Param			keyword	query		string	false	"Tên hoặc mã vị trí"
//	@Param			active	query		bool	false	"true = chỉ vị trí đang bật"
//	@Success		200		{object}	response.Body{data=[]domain.ViTri}
//	@Failure		401		{object}	response.Body
//	@Router			/admin/vi-tri [get]
func (h *ViTriHandler) List(c *gin.Context) {
	list, err := h.svc.List(c.Request.Context(), domain.ViTriFilter{
		Keyword:    c.Query("keyword"),
		OnlyActive: c.Query("active") == "true",
		// Mặc định chỉ bày kệ của CHI NHÁNH ĐANG LÀM VIỆC; `shop_id=0` để xem
		// hết. Kệ là chỗ vật lý của một mặt bằng — trộn kệ của mọi quận vào một ô
		// chọn là mời người soạn hàng đi tới một cái kho ở quận khác.
		ShopID: chiNhanhLoc(c),
	})
	if err != nil {
		handleServiceError(c, err)

		return
	}
	response.OK(c, list)
}

// Get godoc
//
//	@Summary	Chi tiết một vị trí
//	@Tags		Admin - Vị trí
//	@Produce	json
//	@Security	BearerAuth
//	@Param		id	path		int	true	"ID vị trí"
//	@Success	200	{object}	response.Body{data=domain.ViTri}
//	@Failure	404	{object}	response.Body
//	@Router		/admin/vi-tri/{id} [get]
func (h *ViTriHandler) Get(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}

	vt, err := h.svc.GetByID(c.Request.Context(), id)
	if err != nil {
		handleServiceError(c, err)

		return
	}
	response.OK(c, vt)
}

// Create godoc
//
//	@Summary		Thêm vị trí
//	@Description	Mã tự viết hoa và phải khác mọi mã đã có TRONG CỬA HÀNG NÀY, kể cả mã của vị trí đã xoá. Tên so không phân biệt hoa thường nhưng có phân biệt dấu.
//	@Tags			Admin - Vị trí
//	@Accept			json
//	@Produce		json
//	@Security		BearerAuth
//	@Param			body	body		dto.ViTriRequest	true	"Thông tin vị trí"
//	@Success		201		{object}	response.Body{data=domain.ViTri}
//	@Failure		401		{object}	response.Body
//	@Failure		403		{object}	response.Body
//	@Failure		422		{object}	response.Body
//	@Router			/admin/vi-tri [post]
func (h *ViTriHandler) Create(c *gin.Context) {
	var req dto.ViTriRequest
	if !bindJSON(c, &req) {
		return
	}

	vt, err := h.svc.Create(c.Request.Context(), &req)
	if err != nil {
		handleServiceError(c, err)

		return
	}
	response.Created(c, vt)
}

// Update godoc
//
//	@Summary	Sửa vị trí
//	@Tags		Admin - Vị trí
//	@Accept		json
//	@Produce	json
//	@Security	BearerAuth
//	@Param		id		path		int					true	"ID vị trí"
//	@Param		body	body		dto.ViTriRequest	true	"Thông tin vị trí"
//	@Success	200		{object}	response.Body{data=domain.ViTri}
//	@Failure	404		{object}	response.Body
//	@Failure	422		{object}	response.Body
//	@Router		/admin/vi-tri/{id} [put]
func (h *ViTriHandler) Update(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}

	var req dto.ViTriRequest
	if !bindJSON(c, &req) {
		return
	}

	vt, err := h.svc.Update(c.Request.Context(), id, &req)
	if err != nil {
		handleServiceError(c, err)

		return
	}
	response.OKMessage(c, "Đã cập nhật vị trí", vt)
}

// DoiTrangThai godoc
//
//	@Summary		Bật/tắt một vị trí
//	@Description	Tắt = thôi bày ở ô chọn vị trí lúc khai mặt hàng. Dòng vẫn còn nên mặt hàng cũ vẫn tra ra được tên vị trí.
//	@Tags			Admin - Vị trí
//	@Accept			json
//	@Produce		json
//	@Security		BearerAuth
//	@Param			id		path		int							true	"ID vị trí"
//	@Param			body	body		dto.TrangThaiViTriRequest	true	"Trạng thái"
//	@Success		200		{object}	response.Body{data=domain.ViTri}
//	@Failure		404		{object}	response.Body
//	@Router			/admin/vi-tri/{id}/trang-thai [put]
func (h *ViTriHandler) DoiTrangThai(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}

	var req dto.TrangThaiViTriRequest
	if !bindJSON(c, &req) {
		return
	}

	vt, err := h.svc.DoiTrangThai(c.Request.Context(), id, *req.IsActive)
	if err != nil {
		handleServiceError(c, err)

		return
	}
	response.OKMessage(c, "Đã cập nhật trạng thái", vt)
}

// Delete godoc
//
//	@Summary		Xoá vị trí
//	@Description	Xoá mềm. Vị trí đã thôi dùng nhưng còn muốn tra lại thì nên TẮT thay vì xoá.
//	@Tags			Admin - Vị trí
//	@Produce		json
//	@Security		BearerAuth
//	@Param			id	path		int	true	"ID vị trí"
//	@Success		200	{object}	response.Body
//	@Failure		404	{object}	response.Body
//	@Router			/admin/vi-tri/{id} [delete]
func (h *ViTriHandler) Delete(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}

	if err := h.svc.Delete(c.Request.Context(), id); err != nil {
		handleServiceError(c, err)

		return
	}
	response.OKMessage(c, "Đã xoá vị trí", nil)
}
