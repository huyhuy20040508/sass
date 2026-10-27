package handler

import (
	"errors"
	"net/http"
	"time"

	"github.com/gin-gonic/gin"

	"sass-api/internal/domain"
	"sass-api/internal/service"
	"sass-api/pkg/response"
)

// QuaSinhNhatHandler — "Tạo mã sinh nhật" / "Đã chúc" ở màn Khách hàng của quầy.
type QuaSinhNhatHandler struct {
	svc service.QuaSinhNhatService
}

func NewQuaSinhNhatHandler(svc service.QuaSinhNhatService) *QuaSinhNhatHandler {
	return &QuaSinhNhatHandler{svc: svc}
}

func traLoiSinhNhat(c *gin.Context, err error, macDinh string) {
	var cu *service.LoiDaChuc
	switch {
	case errors.As(err, &cu):
		// 409 kèm mốc cũ: màn quầy làm mờ dòng và nói đã chúc ngày nào, mã gì.
		c.AbortWithStatusJSON(http.StatusConflict, response.Body{
			Success: false, Message: "Khách đã được chúc mùa sinh nhật này", Data: cu.Cu,
		})
	case errors.Is(err, domain.ErrNotFound):
		response.Error(c, http.StatusNotFound, "Không tìm thấy khách")
	case errors.Is(err, domain.ErrKhongTrongMuaSN):
		response.Error(c, http.StatusUnprocessableEntity, "Khách không có sinh nhật trong 7 ngày quanh hôm nay")
	case errors.Is(err, domain.ErrChuaKhaiMauSN):
		response.Error(c, http.StatusUnprocessableEntity, "Chưa khai mẫu mã sinh nhật — nhờ chủ cửa hàng đặt mức giảm ở Cài đặt → Quầy bán hàng")
	case errors.Is(err, domain.ErrMauSNSai):
		response.Error(c, http.StatusUnprocessableEntity, "Mẫu mã sinh nhật ở Cài đặt chưa hợp lệ: giảm theo phần trăm phải từ 1 đến 100")
	case errors.Is(err, domain.ErrChuaCoSoSinhNhat):
		response.Error(c, http.StatusServiceUnavailable, "Sổ quà sinh nhật chưa sẵn sàng — cần chạy migration 0098")
	default:
		response.Error(c, http.StatusInternalServerError, macDinh)
	}
}

// @Summary		Tạo mã sinh nhật cho khách
// @Description	Sinh MỘT voucher dùng một lần, chỉ đúng khách này dùng được, theo mẫu ở Cài đặt → Quầy bán hàng (kiểu giảm, mức giảm, giảm tối đa, đơn tối thiểu, hạn dùng), và ghi mốc đã tặng. Chỉ khi sinh nhật cách hôm nay ≤ 7 ngày; đã chúc mùa này thì 409 kèm mốc cũ.
// @Tags			Admin - Customers
// @Produce		json
// @Param			id	path		int	true	"ID khách"
// @Success		201	{object}	response.Body{data=domain.QuaSinhNhat}
// @Failure		409	{object}	response.Body	"Đã chúc mùa sinh nhật này"
// @Failure		422	{object}	response.Body
// @Security		BearerAuth
// @Router			/admin/orders/pos/khach-hang/{id}/ma-sinh-nhat [post]
func (h *QuaSinhNhatHandler) TangMa(c *gin.Context) {
	id, ok := soParam(c, "id")
	if !ok {
		return
	}
	q, err := h.svc.TangMa(c.Request.Context(), id, currentUserID(c), time.Now())
	if err != nil {
		traLoiSinhNhat(c, err, "Lỗi tạo mã sinh nhật")
		return
	}
	response.Created(c, q)
}

// @Summary		Đánh dấu đã chúc sinh nhật
// @Description	Ghi mốc đã chúc mà không phát mã — khách rời danh sách Sinh nhật 7 ngày tới mùa năm sau.
// @Tags			Admin - Customers
// @Produce		json
// @Param			id	path		int	true	"ID khách"
// @Success		201	{object}	response.Body{data=domain.QuaSinhNhat}
// @Failure		409	{object}	response.Body
// @Security		BearerAuth
// @Router			/admin/orders/pos/khach-hang/{id}/da-chuc [post]
func (h *QuaSinhNhatHandler) DaChuc(c *gin.Context) {
	id, ok := soParam(c, "id")
	if !ok {
		return
	}
	q, err := h.svc.DaChuc(c.Request.Context(), id, currentUserID(c), time.Now())
	if err != nil {
		traLoiSinhNhat(c, err, "Lỗi ghi đã chúc")
		return
	}
	response.Created(c, q)
}
