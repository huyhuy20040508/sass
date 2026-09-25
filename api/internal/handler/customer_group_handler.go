package handler

import (
	"errors"
	"net/http"
	"strconv"

	"github.com/gin-gonic/gin"

	"sass-api/internal/domain"
	"sass-api/internal/dto"
	"sass-api/internal/service"
	"sass-api/pkg/response"
)

// CustomerGroupHandler — nhóm khách hàng, bảng tra của màn Khách hàng.
type CustomerGroupHandler struct {
	svc service.CustomerGroupService
}

func NewCustomerGroupHandler(svc service.CustomerGroupService) *CustomerGroupHandler {
	return &CustomerGroupHandler{svc: svc}
}

// @Summary		Danh sách nhóm khách hàng
// @Description	Bảng tra cho ô chọn "Nhóm khách hàng". `active=true` chỉ trả nhóm đang dùng.
// @Tags			Admin - Customers
// @Produce		json
// @Param			active	query		bool	false	"Chỉ nhóm đang dùng"
// @Param			type	query		int		false	"0 nhóm cá nhân · 1 nhóm doanh nghiệp; bỏ trống = cả hai"
// @Success		200		{object}	response.Body{data=[]dto.CustomerGroupResponse}
// @Security		BearerAuth
// @Router			/admin/customer-groups [get]
func (h *CustomerGroupHandler) List(c *gin.Context) {
	// Bỏ trống `type` = lấy cả hai danh sách; khai 0/1 thì chỉ đúng loại đó.
	var loai *uint
	if n, err := strconv.ParseUint(c.Query("type"), 10, 8); err == nil && n <= 1 {
		v := uint(n)
		loai = &v
	}

	ds, err := h.svc.List(c.Request.Context(), c.Query("active") == "true", loai)
	if err != nil {
		response.Error(c, http.StatusInternalServerError, "Lỗi truy vấn nhóm khách hàng")
		return
	}
	response.OK(c, ds)
}

// @Summary		Thêm nhóm khách hàng
// @Tags			Admin - Customers
// @Accept			json
// @Produce		json
// @Param			body	body		dto.CustomerGroupRequest	true	"Thông tin nhóm"
// @Success		201		{object}	response.Body{data=dto.CustomerGroupResponse}
// @Failure		409		{object}	response.Body
// @Security		BearerAuth
// @Router			/admin/customer-groups [post]
func (h *CustomerGroupHandler) Create(c *gin.Context) {
	var req dto.CustomerGroupRequest
	if !bindJSON(c, &req) {
		return
	}

	res, err := h.svc.Create(c.Request.Context(), &req)
	if err != nil {
		respondNhomKhachErr(c, err, "Không tạo được nhóm khách hàng")
		return
	}
	response.Created(c, res)
}

// @Summary		Sửa nhóm khách hàng
// @Tags			Admin - Customers
// @Accept			json
// @Produce		json
// @Param			id		path		int							true	"ID nhóm"
// @Param			body	body		dto.CustomerGroupRequest	true	"Thông tin nhóm"
// @Success		200		{object}	response.Body{data=dto.CustomerGroupResponse}
// @Failure		404		{object}	response.Body
// @Failure		409		{object}	response.Body
// @Security		BearerAuth
// @Router			/admin/customer-groups/{id} [put]
func (h *CustomerGroupHandler) Update(c *gin.Context) {
	id, err := strconv.ParseUint(c.Param("id"), 10, 64)
	if err != nil || id == 0 {
		response.Error(c, http.StatusBadRequest, "ID nhóm khách hàng không hợp lệ")
		return
	}

	var req dto.CustomerGroupRequest
	if !bindJSON(c, &req) {
		return
	}

	res, err := h.svc.Update(c.Request.Context(), uint(id), &req)
	if err != nil {
		respondNhomKhachErr(c, err, "Không sửa được nhóm khách hàng")
		return
	}
	response.OK(c, res)
}

// @Summary		Xoá nhóm khách hàng
// @Description	Nhóm còn khách thì KHÔNG xoá được — tắt trạng thái nếu chỉ muốn thôi dùng.
// @Tags			Admin - Customers
// @Produce		json
// @Param			id	path		int	true	"ID nhóm"
// @Success		200	{object}	response.Body
// @Failure		404	{object}	response.Body
// @Failure		409	{object}	response.Body
// @Security		BearerAuth
// @Router			/admin/customer-groups/{id} [delete]
func (h *CustomerGroupHandler) Delete(c *gin.Context) {
	id, err := strconv.ParseUint(c.Param("id"), 10, 64)
	if err != nil || id == 0 {
		response.Error(c, http.StatusBadRequest, "ID nhóm khách hàng không hợp lệ")
		return
	}

	if err := h.svc.Delete(c.Request.Context(), uint(id)); err != nil {
		respondNhomKhachErr(c, err, "Không xoá được nhóm khách hàng")
		return
	}
	response.OK(c, gin.H{"deleted": true})
}

// respondNhomKhachErr đổi lỗi nghiệp vụ sang mã HTTP kèm câu nói rõ vướng ở đâu.
func respondNhomKhachErr(c *gin.Context, err error, fallback string) {
	switch {
	case errors.Is(err, domain.ErrNotFound):
		response.Error(c, http.StatusNotFound, "Không tìm thấy nhóm khách hàng")
	case errors.Is(err, domain.ErrConflict):
		// Hai lối vào cùng một mã: trùng tên lúc ghi, và còn khách lúc xoá.
		response.Error(c, http.StatusConflict,
			"Tên nhóm đã có trong cửa hàng, hoặc nhóm vẫn còn khách đang dùng")
	default:
		response.Error(c, http.StatusInternalServerError, fallback)
	}
}
