package handler

import (
	"github.com/gin-gonic/gin"

	"sass-api/internal/dto"
	"sass-api/internal/service"
	"sass-api/pkg/response"
)

// ThueHandler — thuế suất (Hàng hóa → Thuế).
type ThueHandler struct {
	svc service.ThueService
}

func NewThueHandler(svc service.ThueService) *ThueHandler {
	return &ThueHandler{svc: svc}
}

// List godoc
//
//	@Summary		Danh sách thuế suất
//	@Description	Bốn loại cố định, mỗi loại kèm bộ mức đang bật và bộ mức cho chọn.
//	@Description	Cửa hàng chưa có dòng nào thì lượt gọi này dựng sẵn theo mức mặc định.
//	@Tags			Admin - Thuế
//	@Produce		json
//	@Security		BearerAuth
//	@Success		200	{object}	response.Body{data=[]dto.ThueItem}
//	@Failure		401	{object}	response.Body
//	@Failure		403	{object}	response.Body
//	@Router			/admin/thue [get]
func (h *ThueHandler) List(c *gin.Context) {
	data, err := h.svc.DanhSach(c.Request.Context())
	if err != nil {
		handleServiceError(c, err)

		return
	}
	response.OK(c, data)
}

// Update godoc
//
//	@Summary		Sửa bộ mức của một loại thuế
//	@Description	Gửi lên TRẠNG THÁI CUỐI CÙNG của ô chọn. Mức nằm ngoài bộ cho chọn của loại đó bị từ chối.
//	@Tags			Admin - Thuế
//	@Accept			json
//	@Produce		json
//	@Security		BearerAuth
//	@Param			id		path		int							true	"ID dòng thuế"
//	@Param			body	body		dto.CapNhatThueRequest	true	"Bộ mức"
//	@Success		200		{object}	response.Body{data=dto.ThueItem}
//	@Failure		401		{object}	response.Body
//	@Failure		403		{object}	response.Body
//	@Failure		404		{object}	response.Body
//	@Failure		422		{object}	response.Body
//	@Router			/admin/thue/{id} [put]
func (h *ThueHandler) Update(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}

	var req dto.CapNhatThueRequest
	if !bindJSON(c, &req) {
		return
	}

	data, err := h.svc.CapNhat(c.Request.Context(), id, req.Muc)
	if err != nil {
		handleServiceError(c, err)

		return
	}
	response.OKMessage(c, "Đã cập nhật mức thuế", data)
}

// DoiTrangThai godoc
//
//	@Summary		Bật/tắt một loại thuế
//	@Description	Tắt = màn nghiệp vụ thôi bày ô chọn thuế của loại đó. Dòng vẫn còn, bật lại là bộ mức cũ nguyên vẹn.
//	@Tags			Admin - Thuế
//	@Accept			json
//	@Produce		json
//	@Security		BearerAuth
//	@Param			id		path		int							true	"ID dòng thuế"
//	@Param			body	body		dto.TrangThaiThueRequest	true	"Trạng thái"
//	@Success		200		{object}	response.Body{data=dto.ThueItem}
//	@Failure		401		{object}	response.Body
//	@Failure		403		{object}	response.Body
//	@Failure		404		{object}	response.Body
//	@Router			/admin/thue/{id}/trang-thai [put]
func (h *ThueHandler) DoiTrangThai(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}

	var req dto.TrangThaiThueRequest
	if !bindJSON(c, &req) {
		return
	}

	data, err := h.svc.DoiTrangThai(c.Request.Context(), id, *req.IsActive)
	if err != nil {
		handleServiceError(c, err)

		return
	}
	response.OKMessage(c, "Đã cập nhật trạng thái", data)
}
