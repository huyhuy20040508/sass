package handler

import (
	"net/http"
	"strconv"

	"github.com/gin-gonic/gin"

	"sass-api/pkg/response"
)

// @Summary		Báo cáo chương trình khuyến mại
// @Description	Mỗi CHƯƠNG TRÌNH một dòng (giảm nhiều trước): số đơn trong kỳ, tiền hàng và doanh thu của các đơn đó, số hàng áp dụng / hàng tặng, tiền giảm. Chương trình đã duyệt, chạy trong kỳ mà chưa đơn nào dùng vẫn có dòng với số 0.
// @Description	`tab`: `hoa-don` (phiếu bán hàng — theo tổng tiền hàng), `hang-hoa` (theo sản phẩm), `nhom-hang` (theo nhóm hàng), `dong-gia` (đồng giá — `base_amount` là tiền theo giá gốc), `voucher` (Voucher/Coupon — `code_count`, `code_used`).
// @Description	Chỉ tính đơn còn hiệu lực (không huỷ / hoàn), tạo trong kỳ. Dòng `totals` đếm số đơn, tiền hàng, doanh thu MỖI ĐƠN MỘT LẦN.
// @Tags			Admin - Reports
// @Produce		json
// @Param			from		query		string	false	"Ngày đầu kỳ (YYYY-MM-DD)"
// @Param			to			query		string	false	"Ngày cuối kỳ (YYYY-MM-DD)"
// @Param			shop_id		query		int		false	"Bỏ trống = chi nhánh đang làm việc, 0 = cả cửa hàng"
// @Param			tab			query		string	false	"Tab"	Enums(hoa-don, hang-hoa, nhom-hang, dong-gia, voucher)
// @Param			keyword		query		string	false	"Tìm theo mã / tên chương trình"
// @Success		200			{object}	response.Body{data=domain.PromotionReport}
// @Failure		401			{object}	response.Body
// @Failure		500			{object}	response.Body
// @Security		BearerAuth
// @Router			/admin/reports/promotions [get]
func (h *ReportHandler) Promotions(c *gin.Context) {
	q := reportQuery(c)
	q.Tab = c.Query("tab")
	q.Keyword = c.Query("keyword")

	res, err := h.svc.Promotions(c.Request.Context(), q)
	if err != nil {
		response.Error(c, http.StatusInternalServerError, "Lỗi lập báo cáo chương trình khuyến mại")
		return
	}
	response.OK(c, res)
}

// @Summary		Đơn của một chương trình khuyến mại
// @Description	Từng đơn trong kỳ đã dùng chương trình `program_id` của tab, mới trước: tiền hàng, phần chương trình giảm (`discount`), tổng giảm của đơn, khách trả.
// @Tags			Admin - Reports
// @Produce		json
// @Param			from		query		string	false	"Ngày đầu kỳ (YYYY-MM-DD)"
// @Param			to			query		string	false	"Ngày cuối kỳ (YYYY-MM-DD)"
// @Param			shop_id		query		int		false	"Bỏ trống = chi nhánh đang làm việc, 0 = cả cửa hàng"
// @Param			tab			query		string	false	"Tab"	Enums(hoa-don, hang-hoa, nhom-hang, dong-gia, voucher)
// @Param			program_id	query		int		true	"Id chương trình"
// @Success		200			{object}	response.Body{data=[]domain.PromotionOrderRow}
// @Failure		401			{object}	response.Body
// @Failure		422			{object}	response.Body
// @Failure		500			{object}	response.Body
// @Security		BearerAuth
// @Router			/admin/reports/promotions/orders [get]
func (h *ReportHandler) PromotionOrders(c *gin.Context) {
	q := reportQuery(c)
	q.Tab = c.Query("tab")
	id, err := strconv.ParseUint(c.Query("program_id"), 10, 64)
	if err != nil || id == 0 {
		response.Error(c, http.StatusUnprocessableEntity, "Thiếu chương trình cần xem")
		return
	}
	q.ProgramID = uint(id)

	res, err := h.svc.PromotionOrders(c.Request.Context(), q)
	if err != nil {
		response.Error(c, http.StatusInternalServerError, "Lỗi tải đơn của chương trình")
		return
	}
	response.OK(c, res)
}
