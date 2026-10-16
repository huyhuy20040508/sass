package handler

import (
	"errors"

	"github.com/gin-gonic/gin"

	"sass-api/internal/domain"
	"sass-api/internal/middleware"
	"sass-api/internal/service"
	"sass-api/pkg/response"
)

// ThietBiHandler — Cài đặt → Thông số chung → Cài đặt thiết bị, mọi đường dưới
// /admin/thiet-bi, theo chi nhánh của header X-Chi-Nhanh. Máy quẹt thẻ KHÔNG ở
// đây: màn hình dùng chung đường /admin/payment-devices của Phương thức thanh toán.
type ThietBiHandler struct {
	svc service.ThietBiService
}

func NewThietBiHandler(svc service.ThietBiService) *ThietBiHandler {
	return &ThietBiHandler{svc: svc}
}

func (h *ThietBiHandler) loi(c *gin.Context, err error) {
	var l *service.LoiPT
	if errors.As(err, &l) {
		response.Error(c, l.Ma, l.Cau)

		return
	}
	if errors.Is(err, domain.ErrChuaChonChiNhanh) {
		response.Error(c, 409, "Chọn một chi nhánh ở thanh trên cùng để cấu hình — mỗi chi nhánh có bộ thiết bị riêng.")

		return
	}
	handleServiceError(c, err)
}

type tbBatTatRequest struct {
	IsActive *bool `json:"is_active" binding:"required"`
}

// List godoc
//
//	@Summary		Thiết bị in của chi nhánh
//	@Description	Máy in, phiếu nào in ra máy nào, nút in, mẫu in (đã gộp mặc định) và các danh mục để vẽ màn.
//	@Tags			Admin - Cài đặt thiết bị
//	@Produce		json
//	@Security		BearerAuth
//	@Success		200	{object}	response.Body{data=service.TBDanhSach}
//	@Router			/admin/thiet-bi [get]
func (h *ThietBiHandler) List(c *gin.Context) {
	ds, err := h.svc.DanhSach(c.Request.Context())
	if err != nil {
		h.loi(c, err)

		return
	}
	// Màn hình khoá công tắc theo cờ này; chặn thật vẫn là việc của đường ghi.
	response.OK(c, gin.H{
		"shop_id": ds.ShopID, "printers": ds.Printers, "routes": ds.Routes, "buttons": ds.Buttons,
		"templates": ds.Templates, "slips": ds.Slips, "groups": ds.Groups, "label_sizes": ds.LabelSize,
		"can_edit": middleware.CoQuyen(c, "thiet-bi.sua"),
	})
}

// CreatePrinter godoc
//
//	@Summary	Thêm máy in
//	@Tags		Admin - Cài đặt thiết bị
//	@Param		body	body	service.TBMayInRequest	true	"Máy in"
//	@Router		/admin/thiet-bi/may-in [post]
func (h *ThietBiHandler) CreatePrinter(c *gin.Context) {
	var req service.TBMayInRequest
	if !bindJSON(c, &req) {
		return
	}
	m, err := h.svc.TaoMayIn(c.Request.Context(), req)
	if err != nil {
		h.loi(c, err)

		return
	}
	response.Created(c, m)
}

// UpdatePrinter godoc
//
//	@Summary	Sửa máy in
//	@Tags		Admin - Cài đặt thiết bị
//	@Param		id		path	int						true	"ID máy in"
//	@Param		body	body	service.TBMayInRequest	true	"Máy in"
//	@Router		/admin/thiet-bi/may-in/{id} [put]
func (h *ThietBiHandler) UpdatePrinter(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}
	var req service.TBMayInRequest
	if !bindJSON(c, &req) {
		return
	}
	m, err := h.svc.SuaMayIn(c.Request.Context(), id, req)
	if err != nil {
		h.loi(c, err)

		return
	}
	response.OK(c, m)
}

// PrinterStatus godoc
//
//	@Summary	Bật / tắt máy in
//	@Tags		Admin - Cài đặt thiết bị
//	@Param		id	path	int	true	"ID máy in"
//	@Router		/admin/thiet-bi/may-in/{id}/status [put]
func (h *ThietBiHandler) PrinterStatus(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}
	var req tbBatTatRequest
	if !bindJSON(c, &req) {
		return
	}
	if err := h.svc.BatTatMayIn(c.Request.Context(), id, *req.IsActive); err != nil {
		h.loi(c, err)

		return
	}
	response.OK(c, nil)
}

// DeletePrinter godoc
//
//	@Summary	Xoá máy in (409 khi máy còn được gán phiếu)
//	@Tags		Admin - Cài đặt thiết bị
//	@Param		id	path	int	true	"ID máy in"
//	@Router		/admin/thiet-bi/may-in/{id} [delete]
func (h *ThietBiHandler) DeletePrinter(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}
	if err := h.svc.XoaMayIn(c.Request.Context(), id); err != nil {
		h.loi(c, err)

		return
	}
	response.OK(c, nil)
}

// CreateRoute godoc
//
//	@Summary	Gán một loại phiếu cho máy in
//	@Tags		Admin - Cài đặt thiết bị
//	@Param		body	body	service.TBGanPhieuRequest	true	"Dòng gán"
//	@Router		/admin/thiet-bi/gan-phieu [post]
func (h *ThietBiHandler) CreateRoute(c *gin.Context) {
	var req service.TBGanPhieuRequest
	if !bindJSON(c, &req) {
		return
	}
	g, err := h.svc.TaoGanPhieu(c.Request.Context(), req)
	if err != nil {
		h.loi(c, err)

		return
	}
	response.Created(c, g)
}

// UpdateRoute godoc
//
//	@Summary	Sửa dòng gán phiếu
//	@Tags		Admin - Cài đặt thiết bị
//	@Param		id		path	int							true	"ID dòng gán"
//	@Param		body	body	service.TBGanPhieuRequest	true	"Dòng gán"
//	@Router		/admin/thiet-bi/gan-phieu/{id} [put]
func (h *ThietBiHandler) UpdateRoute(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}
	var req service.TBGanPhieuRequest
	if !bindJSON(c, &req) {
		return
	}
	g, err := h.svc.SuaGanPhieu(c.Request.Context(), id, req)
	if err != nil {
		h.loi(c, err)

		return
	}
	response.OK(c, g)
}

// RouteStatus godoc
//
//	@Summary	Bật / tắt dòng gán phiếu
//	@Tags		Admin - Cài đặt thiết bị
//	@Param		id	path	int	true	"ID dòng gán"
//	@Router		/admin/thiet-bi/gan-phieu/{id}/status [put]
func (h *ThietBiHandler) RouteStatus(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}
	var req tbBatTatRequest
	if !bindJSON(c, &req) {
		return
	}
	if err := h.svc.BatTatGanPhieu(c.Request.Context(), id, *req.IsActive); err != nil {
		h.loi(c, err)

		return
	}
	response.OK(c, nil)
}

// DeleteRoute godoc
//
//	@Summary	Gỡ dòng gán phiếu
//	@Tags		Admin - Cài đặt thiết bị
//	@Param		id	path	int	true	"ID dòng gán"
//	@Router		/admin/thiet-bi/gan-phieu/{id} [delete]
func (h *ThietBiHandler) DeleteRoute(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}
	if err := h.svc.XoaGanPhieu(c.Request.Context(), id); err != nil {
		h.loi(c, err)

		return
	}
	response.OK(c, nil)
}

// UpdateButton godoc
//
//	@Summary	Lưu phiếu in của một nút ở quầy
//	@Tags		Admin - Cài đặt thiết bị
//	@Param		ma		path	string					true	"thanh-toan | tam-tinh"
//	@Param		body	body	service.TBNutInRequest	true	"Phiếu in"
//	@Router		/admin/thiet-bi/nut-in/{ma} [put]
func (h *ThietBiHandler) UpdateButton(c *gin.Context) {
	var req service.TBNutInRequest
	if !bindJSON(c, &req) {
		return
	}
	if err := h.svc.LuuNutIn(c.Request.Context(), c.Param("ma"), req); err != nil {
		h.loi(c, err)

		return
	}
	response.OK(c, nil)
}

// UpdateTemplate godoc
//
//	@Summary	Lưu một mẫu in (dòng bật/tắt + khổ, số bản, chân trang…)
//	@Tags		Admin - Cài đặt thiết bị
//	@Param		ma		path	string					true	"hd | a5 | a4 | tem | tra | ca"
//	@Param		body	body	service.TBMauInRequest	true	"Mẫu"
//	@Router		/admin/thiet-bi/mau-in/{ma} [put]
func (h *ThietBiHandler) UpdateTemplate(c *gin.Context) {
	var req service.TBMauInRequest
	if !bindJSON(c, &req) {
		return
	}
	v, err := h.svc.LuuMauIn(c.Request.Context(), c.Param("ma"), req)
	if err != nil {
		h.loi(c, err)

		return
	}
	response.OK(c, v)
}

// ResetTemplate godoc
//
//	@Summary	Đưa một mẫu in về mặc định
//	@Tags		Admin - Cài đặt thiết bị
//	@Param		ma	path	string	true	"hd | a5 | a4 | tem | tra | ca"
//	@Router		/admin/thiet-bi/mau-in/{ma} [delete]
func (h *ThietBiHandler) ResetTemplate(c *gin.Context) {
	v, err := h.svc.MacDinhMauIn(c.Request.Context(), c.Param("ma"))
	if err != nil {
		h.loi(c, err)

		return
	}
	response.OK(c, v)
}

// TemplateForPOS godoc
//
//	@Summary	Mẫu in đang dùng ở quầy (cho phiếu in)
//	@Tags		Admin - Đơn tại quầy
//	@Param		ma	path	string	true	"hd | a5 | a4 | tem | tra | ca"
//	@Router		/admin/orders/pos/mau-in/{ma} [get]
func (h *ThietBiHandler) TemplateForPOS(c *gin.Context) {
	v, err := h.svc.MauChoQuay(c.Request.Context(), c.Param("ma"))
	if err != nil {
		h.loi(c, err)

		return
	}
	response.OK(c, v)
}
