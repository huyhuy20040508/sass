package handler

import (
	"net/http"
	"strconv"

	"github.com/gin-gonic/gin"

	"sass-api/pkg/response"
)

// @Summary		Báo cáo ca
// @Description	Mỗi dòng là doanh thu của MỘT nhân viên trong MỘT ca trong MỘT ngày (mới trước): loại dòng (`shift` = trong ca, `none` = đơn quầy lúc không có ca nào mở, `online` = đơn web do nhân viên lập), số đơn, doanh thu gồm VAT và giá trị trung bình một đơn. Kèm dòng tổng và doanh thu theo nhân viên cho biểu đồ.
// @Description	Chỉ tính đơn ĐÃ THU TIỀN, còn hiệu lực. Đơn quầy gắn vào ca theo khoảng giờ mở–đóng ca của cùng chi nhánh (như Báo cáo kết ca). Nhân viên của dòng là người lập đơn; đơn cũ chưa ghi người lập thì lấy người mở ca.
// @Tags			Admin - Reports
// @Produce		json
// @Param			from		query		string	false	"Ngày đầu kỳ (YYYY-MM-DD)"
// @Param			to			query		string	false	"Ngày cuối kỳ (YYYY-MM-DD)"
// @Param			shop_id		query		int		false	"Bỏ trống = chi nhánh đang làm việc, 0 = cả cửa hàng"
// @Param			channel		query		string	false	"Nguồn đơn; bỏ trống = mọi nguồn"	Enums(pos, web)
// @Param			area		query		string	false	"Nhóm nhân viên theo cửa vào của tài khoản"	Enums(quan_ly, thu_ngan)
// @Param			user_id		query		int		false	"Một nhân viên (id tài khoản)"
// @Param			keyword		query		string	false	"Tìm theo mã / tên nhân viên"
// @Success		200			{object}	response.Body{data=domain.StaffReport}
// @Failure		401			{object}	response.Body
// @Failure		500			{object}	response.Body
// @Security		BearerAuth
// @Router			/admin/reports/staff [get]
func (h *ReportHandler) Staff(c *gin.Context) {
	q := reportQuery(c)
	q.Channel = c.Query("channel")
	q.Keyword = c.Query("keyword")
	q.Area = c.Query("area")
	if id, err := strconv.ParseUint(c.Query("user_id"), 10, 64); err == nil {
		q.StaffID = uint(id)
	}

	res, err := h.svc.Staff(c.Request.Context(), q)
	if err != nil {
		response.Error(c, http.StatusInternalServerError, "Lỗi lập báo cáo ca")
		return
	}
	response.OK(c, res)
}
