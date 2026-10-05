package handler

import (
	"net/http"
	"strconv"

	"github.com/gin-gonic/gin"

	"sass-api/pkg/response"
)

// @Summary		Báo cáo nhân viên (hoa hồng)
// @Description	Mỗi NHÂN VIÊN một dòng (nhiều hoa hồng trước): số đơn, tổng doanh thu chưa VAT và gồm VAT, tiền trả hàng, doanh thu tính hoa hồng (= doanh thu gồm VAT − trả hàng, không âm), tỉ lệ % trên hồ sơ nhân sự và tiền hoa hồng; kèm từng đơn của người đó (`orders`, mới trước) và dòng tổng.
// @Description	Chỉ tính đơn ĐÃ THU TIỀN, còn hiệu lực, do nhân viên lập (`created_by` là tài khoản nội bộ). Tỉ lệ là mức hiện tại của hồ sơ.
// @Tags			Admin - Reports
// @Produce		json
// @Param			from		query		string	false	"Ngày đầu kỳ (YYYY-MM-DD)"
// @Param			to			query		string	false	"Ngày cuối kỳ (YYYY-MM-DD)"
// @Param			shop_id		query		int		false	"Bỏ trống = chi nhánh đang làm việc, 0 = cả cửa hàng"
// @Param			channel		query		string	false	"Nguồn đơn; bỏ trống = mọi nguồn"	Enums(pos, web)
// @Param			area		query		string	false	"Nhóm nhân viên theo cửa vào của tài khoản"	Enums(quan_ly, thu_ngan)
// @Param			user_id		query		int		false	"Một nhân viên (id tài khoản)"
// @Param			keyword		query		string	false	"Tìm theo mã / tên nhân viên hoặc mã đơn"
// @Success		200			{object}	response.Body{data=domain.EmployeeReport}
// @Failure		401			{object}	response.Body
// @Failure		500			{object}	response.Body
// @Security		BearerAuth
// @Router			/admin/reports/employees [get]
func (h *ReportHandler) Employees(c *gin.Context) {
	q := reportQuery(c)
	q.Channel = c.Query("channel")
	q.Keyword = c.Query("keyword")
	q.Area = c.Query("area")
	if id, err := strconv.ParseUint(c.Query("user_id"), 10, 64); err == nil {
		q.StaffID = uint(id)
	}

	res, err := h.svc.Employees(c.Request.Context(), q)
	if err != nil {
		response.Error(c, http.StatusInternalServerError, "Lỗi lập báo cáo nhân viên")
		return
	}
	response.OK(c, res)
}
