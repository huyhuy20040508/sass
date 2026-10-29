package handler

import (
	"github.com/gin-gonic/gin"

	"sass-api/internal/domain"
	"sass-api/internal/middleware"
	"sass-api/internal/service"
	"sass-api/pkg/response"
)

// TichHopHandler — khoá tích hợp của web bán hàng. Nằm ở khu quản trị (token
// của người có quyền cấu hình); web bán hàng gọi các đường này bằng chính phiên
// POS của chủ tiệm khi họ bấm "Kết nối" ở trang quản trị web.
type TichHopHandler struct {
	svc service.TichHopService
}

func NewTichHopHandler(svc service.TichHopService) *TichHopHandler {
	return &TichHopHandler{svc: svc}
}

// TrangThaiWeb godoc
//
//	@Summary		Trạng thái kết nối web bán hàng
//	@Tags			Admin - Tích hợp
//	@Produce		json
//	@Security		BearerAuth
//	@Success		200	{object}	response.Body{data=service.TrangThaiTichHop}
//	@Router			/admin/tich-hop/web-ban-hang [get]
func (h *TichHopHandler) TrangThaiWeb(c *gin.Context) {
	res, err := h.svc.TrangThai(c.Request.Context(), domain.AppWebBanHang)
	if err != nil {
		handleServiceError(c, err)
		return
	}
	response.OK(c, res)
}

// CapKhoaWeb godoc
//
//	@Summary		Cấp khoá tích hợp cho web bán hàng
//	@Description	Thu hồi khoá cũ (nếu có) và cấp khoá mới. Khoá chỉ trả về ĐÚNG MỘT LẦN ở
//	@Description	đây — POS chỉ lưu băm, không xem lại được.
//	@Tags			Admin - Tích hợp
//	@Produce		json
//	@Security		BearerAuth
//	@Success		201	{object}	response.Body{data=service.KhoaVuaCap}
//	@Router			/admin/tich-hop/web-ban-hang [post]
func (h *TichHopHandler) CapKhoaWeb(c *gin.Context) {
	res, err := h.svc.Cap(c.Request.Context(), domain.AppWebBanHang, c.GetUint(middleware.CtxUserID))
	if err != nil {
		handleServiceError(c, err)
		return
	}
	response.CreatedMessage(c, "Đã cấp khoá tích hợp cho web bán hàng", res)
}

// ThuHoiKhoaWeb godoc
//
//	@Summary		Ngắt kết nối web bán hàng (thu hồi khoá)
//	@Tags			Admin - Tích hợp
//	@Produce		json
//	@Security		BearerAuth
//	@Success		200	{object}	response.Body
//	@Router			/admin/tich-hop/web-ban-hang [delete]
func (h *TichHopHandler) ThuHoiKhoaWeb(c *gin.Context) {
	n, err := h.svc.ThuHoi(c.Request.Context(), domain.AppWebBanHang)
	if err != nil {
		handleServiceError(c, err)
		return
	}
	response.OKMessage(c, "Đã ngắt kết nối web bán hàng", gin.H{"thu_hoi": n})
}
