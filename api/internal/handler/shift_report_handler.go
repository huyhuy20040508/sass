package handler

import (
	"net/http"
	"strconv"

	"github.com/gin-gonic/gin"

	"sass-api/internal/dto"
	"sass-api/internal/service"
	"sass-api/pkg/response"
)

// @Summary		Báo cáo kết ca
// @Description	Mỗi dòng một ca trong khoảng `from`–`to` (theo giờ MỞ ca), mới trước: người trực, giờ mở/đóng, số đơn QUẦY và tiền theo hình thức (tiền mặt, chuyển khoản, QR tự động), tiền đầu ca và ba con số đối chiếu két đã chốt lúc đóng ca.
// @Description	Đơn thuộc ca = đơn quầy của cùng chi nhánh, tạo trong khoảng mở–đóng ca (ca chưa đóng thì tới lúc xem), đã thu tiền, không huỷ/hoàn. `expected_cash` / `counted_cash` / `difference` là null khi ca chưa đóng.
// @Description	`meta.tong` cộng trên TẤT CẢ ca khớp bộ lọc, không riêng trang đang xem.
// @Tags			Admin - Reports
// @Produce		json
// @Param			from		query		string	false	"Ngày đầu kỳ (YYYY-MM-DD), mặc định ngày 1 của tháng chứa ngày cuối kỳ"
// @Param			to			query		string	false	"Ngày cuối kỳ (YYYY-MM-DD), mặc định hôm nay"
// @Param			shop_id		query		int		false	"Chi nhánh; bỏ trống = chi nhánh đang làm việc, 0 = cả cửa hàng"
// @Param			user_id		query		int		false	"Chỉ lấy ca do tài khoản này mở"
// @Param			keyword		query		string	false	"Tìm theo mã / tên nhân sự của người mở ca"
// @Param			page		query		int		false	"Trang (mặc định 1)"
// @Param			page_size	query		int		false	"Số ca mỗi trang (mặc định 10, tối đa 100)"
// @Success		200			{object}	response.Body{data=[]domain.BaoCaoCaDong,meta=dto.BaoCaoCaMeta}
// @Failure		401			{object}	response.Body
// @Failure		403			{object}	response.Body
// @Security		BearerAuth
// @Router			/admin/reports/shifts [get]
func (h *CaLamViecHandler) BaoCaoKetCa(c *gin.Context) {
	page, _ := strconv.Atoi(c.DefaultQuery("page", "1"))
	pageSize, _ := strconv.Atoi(c.DefaultQuery("page_size", "10"))
	if page < 1 {
		page = 1
	}
	// v2 mở ở 10 dòng; trần 100 như mọi danh sách khác.
	if pageSize < 1 || pageSize > 100 {
		pageSize = 10
	}
	userID, _ := strconv.ParseUint(c.Query("user_id"), 10, 64)

	rows, total, tong, err := h.svc.BaoCaoKetCa(c.Request.Context(), service.BaoCaoCaQuery{
		From:     c.Query("from"),
		To:       c.Query("to"),
		ShopID:   chiNhanhLoc(c),
		UserID:   uint(userID),
		Keyword:  c.Query("keyword"),
		Page:     page,
		PageSize: pageSize,
	})
	if err != nil {
		respondCaError(c, err, "Lỗi truy vấn báo cáo kết ca")
		return
	}

	c.JSON(http.StatusOK, response.Body{
		Success: true,
		Data:    rows,
		Meta: dto.BaoCaoCaMeta{
			Pagination: response.Pagination{
				Page:       page,
				PageSize:   pageSize,
				Total:      total,
				TotalPages: max(int((total+int64(pageSize)-1)/int64(pageSize)), 1),
			},
			Tong: tong,
		},
	})
}
