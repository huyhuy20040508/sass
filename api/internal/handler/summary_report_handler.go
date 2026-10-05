package handler

import (
	"net/http"

	"github.com/gin-gonic/gin"

	"sass-api/pkg/response"
)

// @Summary		Báo cáo tổng hợp
// @Description	Bức tranh MỘT ngày: phiếu thu/chi, tổng kết bán hàng theo hình thức, số mặt hàng và số món đã bán, hàng bị trả lại và doanh thu theo từng giờ. `by_hour` luôn đủ 24 mốc.
// @Tags			Admin - Reports
// @Produce		json
// @Param			date	query		string	false	"Ngày xem (YYYY-MM-DD), mặc định hôm nay"
// @Param			channel	query		string	false	"Nguồn đơn; bỏ trống = mọi nguồn"	Enums(pos, web)
// @Param			shop_id	query		int		false	"Bỏ trống = chi nhánh đang làm việc, 0 = cả cửa hàng"
// @Success		200		{object}	response.Body{data=domain.SummaryReport}
// @Security		BearerAuth
// @Router			/admin/reports/summary [get]
func (h *ReportHandler) Summary(c *gin.Context) {
	q := reportQuery(c)
	q.Date = c.Query("date")
	q.Channel = c.Query("channel")

	res, err := h.svc.Summary(c.Request.Context(), q)
	if err != nil {
		response.Error(c, http.StatusInternalServerError, "Lỗi lập báo cáo tổng hợp")
		return
	}
	response.OK(c, res)
}
