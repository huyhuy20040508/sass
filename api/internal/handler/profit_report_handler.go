package handler

import (
	"net/http"

	"github.com/gin-gonic/gin"

	"sass-api/pkg/response"
)

// @Summary		Báo cáo chi phí & lợi nhuận
// @Description	Mỗi MẶT HÀNG một dòng trong khoảng `from`–`to` (bán nhiều tiền trước, rồi tới mặt hàng đang bán mà kỳ này chưa bán được — mọi cột 0): mã, tên, nhóm, số lượng, tổng giá bán (chưa VAT), tổng giá vốn, lợi nhuận (có thể âm) và biên lợi nhuận %. Kèm dòng tổng và biểu đồ giá bán / giá vốn / lợi nhuận theo mốc (`group_by`).
// @Description	Cùng quy ước với tab Hàng hoá: KHÔNG tính đơn huỷ/hoàn, mốc là lúc đặt. Giá vốn ưu tiên giá CHỤP lúc bán. `keyword` chỉ lọc bảng, biểu đồ không theo.
// @Tags			Admin - Reports
// @Produce		json
// @Param			from		query		string	false	"Ngày đầu kỳ (YYYY-MM-DD)"
// @Param			to			query		string	false	"Ngày cuối kỳ (YYYY-MM-DD)"
// @Param			shop_id		query		int		false	"Bỏ trống = chi nhánh đang làm việc, 0 = cả cửa hàng"
// @Param			channel		query		string	false	"Nguồn đơn; bỏ trống = mọi nguồn"	Enums(pos, web)
// @Param			category_id	query		int		false	"Nhóm hàng — gồm cả nhóm con/cháu"
// @Param			product_id	query		int		false	"Một mặt hàng"
// @Param			keyword		query		string	false	"Tìm theo tên / mã hàng (chỉ lọc bảng)"
// @Param			group_by	query		string	false	"Mốc của biểu đồ; bỏ trống thì tự chọn theo độ dài kỳ"	Enums(day, week, month)
// @Success		200			{object}	response.Body{data=domain.ProfitReport}
// @Failure		401			{object}	response.Body
// @Failure		500			{object}	response.Body
// @Security		BearerAuth
// @Router			/admin/reports/profit [get]
func (h *ReportHandler) Profit(c *gin.Context) {
	res, err := h.svc.Profit(c.Request.Context(), goodsQuery(c))
	if err != nil {
		response.Error(c, http.StatusInternalServerError, "Lỗi lập báo cáo chi phí & lợi nhuận")
		return
	}
	response.OK(c, res)
}
