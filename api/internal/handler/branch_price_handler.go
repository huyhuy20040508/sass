package handler

import (
	"github.com/gin-gonic/gin"

	"sass-api/internal/dto"
	"sass-api/internal/service"
	"sass-api/pkg/response"
)

// GiaChiNhanhHandler — Hàng hoá → giá bán riêng của từng chi nhánh.
//
// Ba đường, gắn vào một BIẾN THỂ: liệt kê những chi nhánh đã khai giá riêng,
// khai một chi nhánh, gỡ một chi nhánh. Không có đường "đọc giá đang áp dụng" —
// giá hiện ra cùng chỗ với mặt hàng (`shop_price` trên từng biến thể).
type GiaChiNhanhHandler struct {
	svc service.GiaChiNhanhService
}

func NewGiaChiNhanhHandler(svc service.GiaChiNhanhService) *GiaChiNhanhHandler {
	return &GiaChiNhanhHandler{svc: svc}
}

// List godoc
//
//	@Summary		Giá riêng theo chi nhánh của một biến thể
//	@Description	Chỉ liệt kê chi nhánh ĐÃ khai giá riêng. Chi nhánh không có trong danh sách là chi nhánh đang dùng giá gốc.
//	@Tags			Admin - Giá theo chi nhánh
//	@Produce		json
//	@Security		BearerAuth
//	@Param			id	path		int	true	"ID biến thể"
//	@Success		200	{object}	response.Body{data=[]domain.GiaChiNhanh}
//	@Failure		401	{object}	response.Body
//	@Router			/admin/bien-the/{id}/gia-chi-nhanh [get]
func (h *GiaChiNhanhHandler) List(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}

	list, err := h.svc.TheoBienThe(c.Request.Context(), id)
	if err != nil {
		handleServiceError(c, err)

		return
	}
	response.OK(c, list)
}

// Set godoc
//
//	@Summary		Khai giá riêng cho một chi nhánh
//	@Description	Khai lại chi nhánh đã có thì ghi đè. Muốn trở lại giá gốc thì XOÁ, đừng khai bằng đúng giá gốc.
//	@Tags			Admin - Giá theo chi nhánh
//	@Accept			json
//	@Produce		json
//	@Security		BearerAuth
//	@Param			id		path		int						true	"ID biến thể"
//	@Param			body	body		dto.GiaChiNhanhRequest	true	"Chi nhánh và giá"
//	@Success		200		{object}	response.Body
//	@Failure		422		{object}	response.Body
//	@Router			/admin/bien-the/{id}/gia-chi-nhanh [post]
func (h *GiaChiNhanhHandler) Set(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}

	var req dto.GiaChiNhanhRequest
	if !bindJSON(c, &req) {
		return
	}

	if err := h.svc.Dat(c.Request.Context(), id, &req); err != nil {
		handleServiceError(c, err)

		return
	}
	response.OK(c, gin.H{"saved": true})
}

// Delete godoc
//
//	@Summary		Gỡ giá riêng của một chi nhánh
//	@Description	Chi nhánh trở lại dùng giá gốc. Gỡ một thứ vốn không có cũng trả 200 — đó là kết quả người dùng muốn.
//	@Tags			Admin - Giá theo chi nhánh
//	@Produce		json
//	@Security		BearerAuth
//	@Param			id		path		int	true	"ID biến thể"
//	@Param			shop_id	path		int	true	"ID chi nhánh"
//	@Success		200		{object}	response.Body
//	@Router			/admin/bien-the/{id}/gia-chi-nhanh/{shop_id} [delete]
func (h *GiaChiNhanhHandler) Delete(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}
	shopID, ok := parseUintParam(c, "shop_id")
	if !ok {
		return
	}

	if err := h.svc.Xoa(c.Request.Context(), id, shopID); err != nil {
		handleServiceError(c, err)

		return
	}
	response.OK(c, gin.H{"deleted": true})
}
