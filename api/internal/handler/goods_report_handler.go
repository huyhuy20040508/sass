package handler

import (
	"net/http"
	"strconv"

	"github.com/gin-gonic/gin"

	"sass-api/internal/service"
	"sass-api/pkg/response"
)

// goodsQuery đọc tham số chung của hai đường báo cáo hàng hoá.
func goodsQuery(c *gin.Context) service.ReportQuery {
	q := reportQuery(c)
	q.Channel = c.Query("channel")
	q.Keyword = c.Query("keyword")
	if id, err := strconv.ParseUint(c.Query("category_id"), 10, 64); err == nil {
		q.CategoryID = uint(id)
	}
	if id, err := strconv.ParseUint(c.Query("product_id"), 10, 64); err == nil {
		q.ProductID = uint(id)
	}
	q.Limit, _ = strconv.Atoi(c.Query("top"))
	q.TopWeekday, _ = strconv.Atoi(c.Query("top_weekday"))
	return q
}

// @Summary		Báo cáo hàng hoá
// @Description	Mỗi MẶT HÀNG một dòng trong khoảng `from`–`to` (bán nhiều trước): mã, tên, số lượng, tổng giá bán (chưa VAT = tiền dòng đã trừ phần bớt của dòng) và thành tiền gồm VAT. Kèm dòng tổng và số liệu cho bốn biểu đồ: top bán chạy theo tiền, số lượng theo giờ, theo thứ (từng món bán nhiều nhất) và theo tháng.
// @Description	Cùng quy ước với các báo cáo khác: KHÔNG tính đơn huỷ/hoàn, mốc là lúc đặt. Giảm giá cả đơn và phí ship không chia về mặt hàng. `keyword` chỉ lọc bảng, biểu đồ không theo.
// @Tags			Admin - Reports
// @Produce		json
// @Param			from		query		string	false	"Ngày đầu kỳ (YYYY-MM-DD)"
// @Param			to			query		string	false	"Ngày cuối kỳ (YYYY-MM-DD)"
// @Param			shop_id		query		int		false	"Bỏ trống = chi nhánh đang làm việc, 0 = cả cửa hàng"
// @Param			channel		query		string	false	"Nguồn đơn; bỏ trống = mọi nguồn"	Enums(pos, web)
// @Param			category_id	query		int		false	"Nhóm hàng — gồm cả nhóm con/cháu"
// @Param			product_id	query		int		false	"Một mặt hàng"
// @Param			keyword		query		string	false	"Tìm theo tên / mã hàng (chỉ lọc bảng)"
// @Param			top			query		int		false	"Số món của biểu đồ top bán chạy"	Enums(5, 10, 15)
// @Param			sort		query		string	false	"desc = bán nhiều tiền nhất, asc = ít nhất"	Enums(desc, asc)
// @Param			top_weekday	query		int		false	"Số món của biểu đồ theo thứ"	Enums(5, 10, 15)
// @Success		200			{object}	response.Body{data=domain.GoodsReport}
// @Failure		401			{object}	response.Body
// @Failure		500			{object}	response.Body
// @Security		BearerAuth
// @Router			/admin/reports/goods [get]
func (h *ReportHandler) Goods(c *gin.Context) {
	res, err := h.svc.Goods(c.Request.Context(), goodsQuery(c))
	if err != nil {
		response.Error(c, http.StatusInternalServerError, "Lỗi lập báo cáo hàng hoá")
		return
	}
	response.OK(c, res)
}

// @Summary		Hoá đơn của một mặt hàng (báo cáo hàng hoá)
// @Description	Mỗi hoá đơn còn hiệu lực trong kỳ có bán mặt hàng `product_id` một dòng, mới trước — hộp "Chi tiết hàng hóa" khi bấm vào mã hàng trên bảng. Cùng bộ lọc chi nhánh / nguồn đơn với bảng.
// @Tags			Admin - Reports
// @Produce		json
// @Param			product_id	query		int		true	"Mặt hàng"
// @Param			from		query		string	false	"Ngày đầu kỳ (YYYY-MM-DD)"
// @Param			to			query		string	false	"Ngày cuối kỳ (YYYY-MM-DD)"
// @Param			shop_id		query		int		false	"Bỏ trống = chi nhánh đang làm việc, 0 = cả cửa hàng"
// @Param			channel		query		string	false	"Nguồn đơn"	Enums(pos, web)
// @Success		200			{object}	response.Body{data=[]domain.GoodsOrderRow}
// @Failure		401			{object}	response.Body
// @Failure		500			{object}	response.Body
// @Security		BearerAuth
// @Router			/admin/reports/goods/orders [get]
func (h *ReportHandler) GoodsOrders(c *gin.Context) {
	rows, err := h.svc.GoodsOrders(c.Request.Context(), goodsQuery(c))
	if err != nil {
		response.Error(c, http.StatusInternalServerError, "Lỗi đọc hoá đơn của mặt hàng")
		return
	}
	response.OK(c, rows)
}
