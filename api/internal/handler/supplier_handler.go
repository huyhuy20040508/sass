package handler

import (
	"errors"
	"net/http"

	"github.com/gin-gonic/gin"

	"sass-api/internal/domain"
	"sass-api/internal/dto"
	"sass-api/internal/service"
	"sass-api/pkg/response"
)

type SupplierHandler struct {
	svc service.SupplierService
}

func NewSupplierHandler(svc service.SupplierService) *SupplierHandler {
	return &SupplierHandler{svc: svc}
}

// @Summary		Danh sách nhà cung cấp
// @Description	Tìm theo tên / mã / SĐT / người liên hệ. Mỗi phần tử kèm `purchase_count` — số phiếu đặt hàng đang trỏ tới nhà cung cấp đó, dùng để cảnh báo trước khi xoá.
// @Description	Kèm sẵn số liệu mua hàng để trang quản lý không phải gọi thêm: `purchase_amount` (tổng giá trị đã đặt), `debt_amount` (còn nợ), `last_order_at` (lần đặt gần nhất) — cả ba KHÔNG tính phiếu nháp và phiếu đã huỷ.
// @Description	Đặt `active=true` để chỉ lấy nhà cung cấp đang hợp tác (dropdown lập phiếu dùng tham số này).
// @Tags			Admin - Suppliers
// @Accept			json
// @Produce		json
// @Param			keyword	query		string	false	"Tên / mã / SĐT / người liên hệ"
// @Param			active	query		bool	false	"true = chỉ nhà cung cấp đang hợp tác"
// @Success		200		{object}	response.Body{data=[]domain.Supplier}
// @Failure		401		{object}	response.Body
// @Security		BearerAuth
// @Router			/admin/suppliers [get]
func (h *SupplierHandler) List(c *gin.Context) {
	list, err := h.svc.List(c.Request.Context(), domain.SupplierFilter{
		Keyword:    c.Query("keyword"),
		OnlyActive: c.Query("active") == "true",
	})
	if err != nil {
		response.Error(c, http.StatusInternalServerError, "Lỗi truy vấn danh sách nhà cung cấp")
		return
	}
	response.OK(c, list)
}

// @Summary		Chi tiết nhà cung cấp
// @Tags			Admin - Suppliers
// @Accept			json
// @Produce		json
// @Param			id	path		int	true	"ID nhà cung cấp"
// @Success		200	{object}	response.Body{data=domain.Supplier}
// @Failure		404	{object}	response.Body
// @Security		BearerAuth
// @Router			/admin/suppliers/{id} [get]
func (h *SupplierHandler) Get(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}

	sup, err := h.svc.GetByID(c.Request.Context(), id)
	if err != nil {
		respondSupplierError(c, err, "Lỗi truy vấn nhà cung cấp")
		return
	}
	response.OK(c, sup)
}

// @Summary		Thêm nhà cung cấp
// @Description	Bỏ trống `code` thì server tự sinh mã kế tiếp dạng NCC001. Mã trùng (kể cả với nhà cung cấp đã xoá) trả về 409 vì mã bị ràng buộc duy nhất.
// @Tags			Admin - Suppliers
// @Accept			json
// @Produce		json
// @Param			body	body		dto.SupplierRequest	true	"Thông tin nhà cung cấp"
// @Success		201		{object}	response.Body{data=domain.Supplier}
// @Failure		400		{object}	response.Body
// @Failure		409		{object}	response.Body
// @Failure		422		{object}	response.Body
// @Security		BearerAuth
// @Router			/admin/suppliers [post]
func (h *SupplierHandler) Create(c *gin.Context) {
	var req dto.SupplierRequest
	if !bindJSON(c, &req) {
		return
	}

	sup, err := h.svc.Create(c.Request.Context(), &req)
	if err != nil {
		respondSupplierError(c, err, "Lỗi thêm nhà cung cấp")
		return
	}
	response.Created(c, sup)
}

// @Summary		Sửa nhà cung cấp
// @Description	Bỏ trống `code` = giữ nguyên mã cũ (mã đã in trên chứng từ giấy, không tự đổi).
// @Tags			Admin - Suppliers
// @Accept			json
// @Produce		json
// @Param			id		path		int					true	"ID nhà cung cấp"
// @Param			body	body		dto.SupplierRequest	true	"Thông tin nhà cung cấp"
// @Success		200		{object}	response.Body{data=domain.Supplier}
// @Failure		404		{object}	response.Body
// @Failure		409		{object}	response.Body
// @Failure		422		{object}	response.Body
// @Security		BearerAuth
// @Router			/admin/suppliers/{id} [put]
func (h *SupplierHandler) Update(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}

	var req dto.SupplierRequest
	if !bindJSON(c, &req) {
		return
	}

	sup, err := h.svc.Update(c.Request.Context(), id, &req)
	if err != nil {
		respondSupplierError(c, err, "Lỗi cập nhật nhà cung cấp")
		return
	}
	response.OKMessage(c, "Đã cập nhật nhà cung cấp", sup)
}

// @Summary		Xoá nhà cung cấp
// @Description	Chỉ xoá được nhà cung cấp CHƯA có phiếu đặt hàng nào; còn phiếu thì trả 409 (nên tắt "đang hợp tác" thay vì xoá, để phiếu cũ giữ được đầu mối liên hệ).
// @Tags			Admin - Suppliers
// @Accept			json
// @Produce		json
// @Param			id	path		int	true	"ID nhà cung cấp"
// @Success		200	{object}	response.Body
// @Failure		404	{object}	response.Body
// @Failure		409	{object}	response.Body
// @Security		BearerAuth
// @Router			/admin/suppliers/{id} [delete]
func (h *SupplierHandler) Delete(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}

	if err := h.svc.Delete(c.Request.Context(), id); err != nil {
		respondSupplierError(c, err, "Lỗi xoá nhà cung cấp")
		return
	}
	response.OKMessage(c, "Đã xoá nhà cung cấp", nil)
}

func respondSupplierError(c *gin.Context, err error, fallback string) {
	switch {
	case errors.Is(err, domain.ErrNotFound):
		response.Error(c, http.StatusNotFound, "Không tìm thấy nhà cung cấp")
	case errors.Is(err, domain.ErrConflict):
		response.Error(c, http.StatusConflict,
			"Mã nhà cung cấp đã tồn tại, hoặc nhà cung cấp đang có phiếu đặt hàng nên không xoá được")
	default:
		response.Error(c, http.StatusInternalServerError, fallback)
	}
}
