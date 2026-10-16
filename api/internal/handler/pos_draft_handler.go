package handler

import (
	"strconv"

	"github.com/gin-gonic/gin"

	"sass-api/internal/domain"
	"sass-api/internal/dto"
	"sass-api/internal/middleware"
	"sass-api/pkg/response"
)

// ĐƠN NHÁP Ở QUẦY — hoá đơn đang mở (pos_drafts). Xem service/pos_draft.go.

// @Summary		Danh sách hoá đơn đang mở / đơn nháp ở quầy
// @Description	Cắt theo chi nhánh đang làm, mới đụng tới lên đầu. `status` rỗng = mọi trạng thái (open, dang_chot, converted, cancelled; ngăn bởi dấu phẩy). `cua_toi=1` chỉ lấy hoá đơn chính mình mở.
// @Tags			Admin - Bán tại quầy
// @Produce		json
// @Param			status		query		string	false	"open|dang_chot|converted|cancelled, ngăn bởi dấu phẩy"
// @Param			cua_toi		query		int		false	"1 = chỉ hoá đơn của người đang đăng nhập"
// @Param			from_date	query		string	false	"YYYY-MM-DD (theo lúc mở hoá đơn)"
// @Param			to_date		query		string	false	"YYYY-MM-DD"
// @Param			limit		query		int		false	"Mặc định 200, tối đa 500"
// @Success		200			{object}	response.Body{data=[]dto.POSNhapResponse}
// @Security		BearerAuth
// @Router			/admin/orders/pos/nhap [get]
func (h *OrderHandler) POSDanhSachNhap(c *gin.Context) {
	f := domain.LocNhapQuay{
		Status:   c.Query("status"),
		FromDate: c.Query("from_date"),
		ToDate:   c.Query("to_date"),
	}
	if c.Query("cua_toi") == "1" {
		f.CreatedBy = c.GetUint(middleware.CtxUserID)
	}
	if n, err := strconv.Atoi(c.Query("limit")); err == nil {
		f.Limit = n
	}
	ds, err := h.svc.POSDanhSachNhap(c.Request.Context(), f)
	if err != nil {
		respondOrderError(c, err, "Lỗi đọc hoá đơn đang mở")

		return
	}
	response.OK(c, ds)
}

// @Summary		Một hoá đơn đang mở / đơn nháp
// @Tags			Admin - Bán tại quầy
// @Produce		json
// @Param			id	path		int	true	"ID đơn nháp"
// @Success		200	{object}	response.Body{data=dto.POSNhapResponse}
// @Failure		404	{object}	response.Body
// @Security		BearerAuth
// @Router			/admin/orders/pos/nhap/{id} [get]
func (h *OrderHandler) POSXemNhap(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}
	d, err := h.svc.POSXemNhap(c.Request.Context(), id)
	if err != nil {
		respondOrderError(c, err, "Lỗi đọc hoá đơn")

		return
	}
	response.OK(c, d)
}

// @Summary		Mở hoá đơn mới ở quầy (đơn nháp)
// @Description	Màn quầy gọi khi hoá đơn có món đầu tiên. Cấp mã đơn ngay (cùng bộ đếm với lượt chốt). Không giữ kho, không vào báo cáo — lượt chốt (POST /admin/orders/pos kèm draft_id) mới lập đơn thật.
// @Tags			Admin - Bán tại quầy
// @Accept			json
// @Produce		json
// @Param			body	body		dto.POSNhapRequest	true	"Toàn bộ hoá đơn"
// @Success		201		{object}	response.Body{data=dto.POSNhapResponse}
// @Failure		422		{object}	response.Body
// @Security		BearerAuth
// @Router			/admin/orders/pos/nhap [post]
func (h *OrderHandler) POSTaoNhap(c *gin.Context) {
	var req dto.POSNhapRequest
	if !bindJSON(c, &req) {
		return
	}
	d, err := h.svc.POSTaoNhap(c.Request.Context(), &req, c.GetUint(middleware.CtxUserID))
	if err != nil {
		respondOrderError(c, err, "Lỗi mở hoá đơn")

		return
	}
	response.Created(c, d)
}

// @Summary		Lưu hoá đơn đang mở (ghi đè cả hoá đơn)
// @Description	409 khi hoá đơn đã thanh toán / đã huỷ / đang được thanh toán ở máy khác.
// @Tags			Admin - Bán tại quầy
// @Accept			json
// @Produce		json
// @Param			id		path		int					true	"ID đơn nháp"
// @Param			body	body		dto.POSNhapRequest	true	"Toàn bộ hoá đơn"
// @Success		200		{object}	response.Body{data=dto.POSNhapResponse}
// @Failure		404		{object}	response.Body
// @Failure		409		{object}	response.Body
// @Security		BearerAuth
// @Router			/admin/orders/pos/nhap/{id} [put]
func (h *OrderHandler) POSSuaNhap(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}
	var req dto.POSNhapRequest
	if !bindJSON(c, &req) {
		return
	}
	d, err := h.svc.POSSuaNhap(c.Request.Context(), id, &req, c.GetUint(middleware.CtxUserID))
	if err != nil {
		respondOrderError(c, err, "Lỗi lưu hoá đơn")

		return
	}
	response.OK(c, d)
}

// @Summary		Huỷ hoá đơn đang mở
// @Description	Bắt buộc lý do. Hoá đơn được giữ lại ở trạng thái cancelled (như v2); mã đơn của nó không cấp lại.
// @Tags			Admin - Bán tại quầy
// @Accept			json
// @Produce		json
// @Param			id		path		int							true	"ID đơn nháp"
// @Param			body	body		dto.POSHuyNhapRequest		true	"Lý do"
// @Success		200		{object}	response.Body{data=dto.POSNhapResponse}
// @Failure		409		{object}	response.Body
// @Failure		422		{object}	response.Body
// @Security		BearerAuth
// @Router			/admin/orders/pos/nhap/{id}/huy [post]
func (h *OrderHandler) POSHuyNhap(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}
	var req dto.POSHuyNhapRequest
	if !bindJSON(c, &req) {
		return
	}
	d, err := h.svc.POSHuyNhap(c.Request.Context(), id, req.LyDo, c.GetUint(middleware.CtxUserID))
	if err != nil {
		respondOrderError(c, err, "Lỗi huỷ hoá đơn")

		return
	}
	response.OK(c, d)
}
