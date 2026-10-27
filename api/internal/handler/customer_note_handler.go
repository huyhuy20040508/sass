package handler

import (
	"errors"
	"net/http"
	"strconv"
	"time"

	"github.com/gin-gonic/gin"

	"sass-api/internal/domain"
	"sass-api/internal/dto"
	"sass-api/internal/service"
	"sass-api/pkg/response"
)

// GhiChuKhachHandler — sổ ghi chú khách (khung chi tiết khách ở quầy).
type GhiChuKhachHandler struct {
	svc service.GhiChuKhachService
}

func NewGhiChuKhachHandler(svc service.GhiChuKhachService) *GhiChuKhachHandler {
	return &GhiChuKhachHandler{svc: svc}
}

func soParam(c *gin.Context, ten string) (uint, bool) {
	n, err := strconv.ParseUint(c.Param(ten), 10, 64)
	if err != nil || n == 0 {
		response.Error(c, http.StatusBadRequest, "ID không hợp lệ")
		return 0, false
	}
	return uint(n), true
}

func traLoiGhiChu(c *gin.Context, err error, macDinh string) {
	var o *domain.LoiGhiChuKhach
	switch {
	case errors.As(err, &o):
		response.ValidationError(c, o.O)
	case errors.Is(err, domain.ErrNotFound):
		response.Error(c, http.StatusNotFound, "Không tìm thấy khách hoặc ghi chú")
	case errors.Is(err, domain.ErrForbidden):
		response.Error(c, http.StatusForbidden, "Chỉ người ghi mới xoá được ghi chú này")
	case errors.Is(err, domain.ErrChuaCoSoGhiChu):
		response.Error(c, http.StatusServiceUnavailable, "Sổ ghi chú khách chưa sẵn sàng — cần chạy migration 0097")
	default:
		response.Error(c, http.StatusInternalServerError, macDinh)
	}
}

// @Summary		Sổ ghi chú của khách
// @Description	Các dòng ghi chú (chăm sóc / hẹn / cảnh báo) của một khách, mới trước, kèm người ghi và chi nhánh. Ô customer_note của hồ sơ là ghi chú cố định, không nằm ở đây.
// @Tags			Admin - Customers
// @Produce		json
// @Param			id	path		int	true	"ID khách"
// @Success		200	{object}	response.Body{data=[]domain.GhiChuKhach}
// @Security		BearerAuth
// @Router			/admin/orders/pos/khach-hang/{id}/ghi-chu [get]
func (h *GhiChuKhachHandler) List(c *gin.Context) {
	id, ok := soParam(c, "id")
	if !ok {
		return
	}
	ds, err := h.svc.CuaKhach(c.Request.Context(), id)
	if err != nil {
		traLoiGhiChu(c, err, "Lỗi đọc ghi chú khách")
		return
	}
	response.OK(c, ds)
}

// @Summary		Thêm ghi chú cho khách
// @Description	loai: cham-soc (mặc định) | hen | canh-bao. Loại hen bắt buộc ngay_hen (YYYY-MM-DD, không trước hôm nay) — khách có hẹn hôm nay hiện ở nút lọc "Có hẹn hôm nay".
// @Tags			Admin - Customers
// @Accept			json
// @Produce		json
// @Param			id		path		int						true	"ID khách"
// @Param			body	body		dto.GhiChuKhachRequest	true	"Ghi chú"
// @Success		201		{object}	response.Body{data=domain.GhiChuKhach}
// @Failure		422		{object}	response.Body
// @Security		BearerAuth
// @Router			/admin/orders/pos/khach-hang/{id}/ghi-chu [post]
func (h *GhiChuKhachHandler) Them(c *gin.Context) {
	id, ok := soParam(c, "id")
	if !ok {
		return
	}
	var req dto.GhiChuKhachRequest
	if !bindJSON(c, &req) {
		return
	}
	g, err := h.svc.Them(c.Request.Context(), id, &req, currentUserID(c), time.Now())
	if err != nil {
		traLoiGhiChu(c, err, "Lỗi lưu ghi chú khách")
		return
	}
	response.Created(c, g)
}

// @Summary		Xoá ghi chú của mình
// @Tags			Admin - Customers
// @Produce		json
// @Param			id		path		int	true	"ID khách"
// @Param			note	path		int	true	"ID ghi chú"
// @Success		200		{object}	response.Body
// @Failure		403		{object}	response.Body	"Ghi chú của người khác"
// @Security		BearerAuth
// @Router			/admin/orders/pos/khach-hang/{id}/ghi-chu/{note} [delete]
func (h *GhiChuKhachHandler) Xoa(c *gin.Context) {
	id, ok := soParam(c, "id")
	if !ok {
		return
	}
	note, ok := soParam(c, "note")
	if !ok {
		return
	}
	if err := h.svc.Xoa(c.Request.Context(), id, note, currentUserID(c)); err != nil {
		traLoiGhiChu(c, err, "Lỗi xoá ghi chú khách")
		return
	}
	response.OK(c, gin.H{"id": note})
}
