package handler

import (
	"net/http"
	"strconv"

	"github.com/gin-gonic/gin"

	"sass-api/pkg/response"
)

// ConNoKhach godoc
//
// @Summary		Đơn còn nợ của một khách (quầy)
// @Description	Các đơn khách còn nợ, CŨ TRƯỚC, kèm tổng còn nợ — cùng công thức cột "Còn nợ" của CRM. Khung chi tiết khách ở quầy dùng để bày nút "Thu" từng đơn và nút "Thu nợ" cho đơn cũ nhất.
// @Description	`thu_duoc` = false: đơn của chi nhánh khác, thu ở đây sẽ bị từ chối — vẫn trả về để tổng khớp con số trên danh sách.
// @Tags			Admin - Orders
// @Produce		json
// @Param			id	path		int	true	"ID khách hàng"
// @Success		200	{object}	response.Body
// @Security		BearerAuth
// @Router			/admin/orders/pos/khach-hang/{id}/con-no [get]
func (h *OrderHandler) ConNoKhach(c *gin.Context) {
	id, err := strconv.ParseUint(c.Param("id"), 10, 64)
	if err != nil || id == 0 {
		response.Error(c, http.StatusBadRequest, "ID khách hàng không hợp lệ")

		return
	}

	rows, err := h.svc.DonConNoCuaKhach(c.Request.Context(), uint(id))
	if err != nil {
		respondOrderError(c, err, "Lỗi đọc công nợ khách")

		return
	}

	var tong float64
	for _, r := range rows {
		tong += r.ConNo
	}
	response.OK(c, gin.H{"tong_con_no": tong, "don": rows})
}
