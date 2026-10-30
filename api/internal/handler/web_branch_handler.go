package handler

import (
	"github.com/gin-gonic/gin"

	"sass-api/internal/dto"
	"sass-api/internal/middleware"
	"sass-api/internal/service"
	"sass-api/pkg/response"
)

// WebChiNhanhHandler — mở / gỡ web cho từng chi nhánh của hợp đồng Website bán hàng.
type WebChiNhanhHandler struct{ svc service.WebChiNhanhService }

func NewWebChiNhanhHandler(svc service.WebChiNhanhService) *WebChiNhanhHandler {
	return &WebChiNhanhHandler{svc: svc}
}

// DanhSach godoc
//
//	@Summary		Chi nhánh và web của một hợp đồng Website bán hàng
//	@Tags			Platform - Khách hàng
//	@Produce		json
//	@Security		BearerAuth
//	@Param			id	path		int	true	"ID hợp đồng"
//	@Success		200	{object}	response.Body{data=dto.WebChiNhanhResponse}
//	@Failure		403	{object}	response.Body
//	@Failure		404	{object}	response.Body
//	@Failure		422	{object}	response.Body
//	@Router			/platform/subscriptions/{id}/web-chi-nhanh [get]
func (h *WebChiNhanhHandler) DanhSach(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}
	kq, err := h.svc.DanhSach(c.Request.Context(), middleware.PlatformApps(c), id)
	if err != nil {
		handleDungThuError(c, err)
		return
	}
	response.OK(c, kq)
}

// Mo godoc
//
//	@Summary		Mở web cho một chi nhánh
//	@Description	Cấp tên miền <tên chi nhánh>.<WEB_BASE_DOMAIN> (trùng thì thêm -2, -3).
//	@Description	Không vượt số chi nhánh của hợp đồng (max_shops, 0 = không giới hạn).
//	@Tags			Platform - Khách hàng
//	@Accept			json
//	@Produce		json
//	@Security		BearerAuth
//	@Param			id		path		int							true	"ID hợp đồng"
//	@Param			body	body		dto.MoWebChiNhanhRequest	true	"Chi nhánh"
//	@Success		200		{object}	response.Body{data=dto.WebChiNhanhResponse}
//	@Failure		403		{object}	response.Body
//	@Failure		422		{object}	response.Body
//	@Router			/platform/subscriptions/{id}/web-chi-nhanh [post]
func (h *WebChiNhanhHandler) Mo(c *gin.Context) {
	if !ghiDuoc(c) {
		return
	}
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}
	var req dto.MoWebChiNhanhRequest
	if !bindJSON(c, &req) {
		return
	}
	kq, err := h.svc.Mo(c.Request.Context(), middleware.PlatformApps(c), id, req.ShopID)
	if err != nil {
		handleDungThuError(c, err)
		return
	}
	response.OKMessage(c, "Đã mở web cho chi nhánh", kq)
}

// Go godoc
//
//	@Summary		Gỡ web của một chi nhánh
//	@Tags			Platform - Khách hàng
//	@Produce		json
//	@Security		BearerAuth
//	@Param			id		path		int	true	"ID hợp đồng"
//	@Param			shopId	path		int	true	"ID chi nhánh"
//	@Success		200		{object}	response.Body{data=dto.WebChiNhanhResponse}
//	@Failure		403		{object}	response.Body
//	@Router			/platform/subscriptions/{id}/web-chi-nhanh/{shopId} [delete]
func (h *WebChiNhanhHandler) Go(c *gin.Context) {
	if !ghiDuoc(c) {
		return
	}
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}
	shopID, ok := parseUintParam(c, "shopId")
	if !ok {
		return
	}
	kq, err := h.svc.Go(c.Request.Context(), middleware.PlatformApps(c), id, shopID)
	if err != nil {
		handleDungThuError(c, err)
		return
	}
	response.OKMessage(c, "Đã gỡ web của chi nhánh", kq)
}
