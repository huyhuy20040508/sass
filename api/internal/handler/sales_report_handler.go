package handler

import (
	"net/http"

	"github.com/gin-gonic/gin"

	"sass-api/internal/service"
	"sass-api/pkg/response"
)

// salesQuery đọc tham số chung của hai đường báo cáo doanh thu.
func salesQuery(c *gin.Context) service.ReportQuery {
	q := reportQuery(c)
	q.Channel = c.Query("channel")
	q.Methods = c.Query("methods")
	return q
}

// @Summary		Báo cáo doanh thu
// @Description	Mỗi NGÀY một dòng trong khoảng `from`–`to` (mới trước): số đơn, doanh thu chưa VAT và gồm VAT, tiền hoàn của phiếu trả hàng, đã thanh toán, tiền đã thu theo hình thức (tiền mặt / chuyển khoản / quẹt thẻ / QR tự động / khác) và công nợ. Kèm dòng tổng và số liệu cho bốn biểu đồ (theo ngày / giờ / thứ / tháng, doanh thu gồm VAT).
// @Description	Cùng quy ước với các báo cáo khác: KHÔNG tính đơn huỷ/hoàn, mốc là lúc đặt. Tiền trả hàng để cột riêng, không trừ vào doanh thu.
// @Tags			Admin - Reports
// @Produce		json
// @Param			from	query		string	false	"Ngày đầu kỳ (YYYY-MM-DD)"
// @Param			to		query		string	false	"Ngày cuối kỳ (YYYY-MM-DD)"
// @Param			shop_id	query		int		false	"Bỏ trống = chi nhánh đang làm việc, 0 = cả cửa hàng"
// @Param			channel	query		string	false	"Nguồn đơn; bỏ trống = mọi nguồn"	Enums(pos, web)
// @Param			methods	query		string	false	"Hình thức, phân cách bằng dấu phẩy: cash,bank_transfer,card,auto_qr; bỏ trống = tất cả"
// @Success		200		{object}	response.Body{data=domain.SalesReport}
// @Failure		401		{object}	response.Body
// @Failure		500		{object}	response.Body
// @Security		BearerAuth
// @Router			/admin/reports/sales [get]
func (h *ReportHandler) Sales(c *gin.Context) {
	res, err := h.svc.Sales(c.Request.Context(), salesQuery(c))
	if err != nil {
		response.Error(c, http.StatusInternalServerError, "Lỗi lập báo cáo doanh thu")
		return
	}
	response.OK(c, res)
}

// @Summary		Hoá đơn của một ngày (báo cáo doanh thu)
// @Description	Từng hoá đơn còn hiệu lực của ngày `date`, mới trước — hộp "Chi tiết báo cáo bán hàng" khi bấm vào một ngày trên bảng. Cùng bộ lọc chi nhánh / nguồn đơn / hình thức với bảng.
// @Tags			Admin - Reports
// @Produce		json
// @Param			date	query		string	false	"Ngày (YYYY-MM-DD), mặc định hôm nay"
// @Param			shop_id	query		int		false	"Bỏ trống = chi nhánh đang làm việc, 0 = cả cửa hàng"
// @Param			channel	query		string	false	"Nguồn đơn"	Enums(pos, web)
// @Param			methods	query		string	false	"Hình thức, như /admin/reports/sales"
// @Success		200		{object}	response.Body{data=[]domain.SalesOrderRow}
// @Failure		401		{object}	response.Body
// @Failure		500		{object}	response.Body
// @Security		BearerAuth
// @Router			/admin/reports/sales/orders [get]
func (h *ReportHandler) SalesOrders(c *gin.Context) {
	q := salesQuery(c)
	q.Date = c.Query("date")

	rows, err := h.svc.SalesOrders(c.Request.Context(), q)
	if err != nil {
		response.Error(c, http.StatusInternalServerError, "Lỗi đọc hoá đơn của ngày")
		return
	}
	response.OK(c, rows)
}
