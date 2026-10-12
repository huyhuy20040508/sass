package handler

import (
	"net/http"
	"strconv"

	"github.com/gin-gonic/gin"

	"sass-api/pkg/response"
)

// @Summary		CRM → Tổng quan
// @Description	Số liệu khách hàng của kỳ theo CrmDashboardService của bản v2: KPI (doanh thu so cùng khoảng tháng trước / năm trước, số đơn, TB/đơn, khách mua, mua 1 / 2 lần, tỉ lệ quay lại, đăng ký mới — kèm kỳ liền trước), top 10 khách theo chi tiêu / công nợ, tăng trưởng khách theo tháng (`year`), khoảng quay lại, số lần mua, phân bố giá trị đơn, đăng ký mới theo ngày (`reg_month`), hạng thành viên, giới tính, độ tuổi, tỉnh thành đơn giao hàng, và bốn nhóm cần chăm sóc.
// @Description	Đơn được tính: còn hiệu lực (không huỷ / hoàn), tạo trong kỳ, đúng chi nhánh. Lọc loại khách thì chỉ còn đơn có hồ sơ đúng loại. Hồ sơ khách không có chi nhánh nên đăng ký mới / tăng trưởng / hạng không cắt theo chi nhánh.
// @Tags			Admin - Reports
// @Produce		json
// @Param			from			query		string	false	"Ngày đầu kỳ (YYYY-MM-DD), mặc định 30 ngày gần nhất"
// @Param			to				query		string	false	"Ngày cuối kỳ (YYYY-MM-DD)"
// @Param			shop_id			query		int		false	"Bỏ trống = chi nhánh đang làm việc, 0 = cả cửa hàng"
// @Param			customer_type	query		string	false	"Loại khách; bỏ trống = tất cả"	Enums(individual, company)
// @Param			year			query		int		false	"Năm của biểu đồ tăng trưởng; mặc định năm của ngày cuối kỳ"
// @Param			reg_month		query		string	false	"Tháng (YYYY-MM) của biểu đồ đăng ký mới theo ngày; mặc định tháng của ngày cuối kỳ"
// @Success		200				{object}	response.Body{data=domain.CrmReport}
// @Failure		401				{object}	response.Body
// @Failure		500				{object}	response.Body
// @Security		BearerAuth
// @Router			/admin/reports/crm [get]
func (h *ReportHandler) Crm(c *gin.Context) {
	q := reportQuery(c)
	q.CustomerType = c.Query("customer_type")
	q.Year, _ = strconv.Atoi(c.Query("year"))
	q.RegMonth = c.Query("reg_month")

	res, err := h.svc.Crm(c.Request.Context(), q)
	if err != nil {
		response.Error(c, http.StatusInternalServerError, "Lỗi lập báo cáo CRM")
		return
	}
	response.OK(c, res)
}
