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

type CustomerHandler struct {
	svc service.CustomerService
}

func NewCustomerHandler(svc service.CustomerService) *CustomerHandler {
	return &CustomerHandler{svc: svc}
}

// @Summary		Danh sách khách hàng
// @Description	Lọc theo từ khóa (tên/email/SĐT), trạng thái, giới tính, sắp xếp & phân trang. Mỗi khách hàng kèm địa chỉ mặc định, số đơn và tổng chi tiêu.
// @Tags			Admin - Customers
// @Accept			json
// @Produce		json
// @Param			keyword		query		string	false	"Tìm theo tên/email/sđt"
// @Param			status		query		string	false	"all|active|inactive"
// @Param			gender		query		string	false	"all|male|female|other"
// @Param			sort		query		string	false	"newest|oldest|name_asc|name_desc|spent_desc"
// @Param			page		query		int		false	"Trang (mặc định 1)"
// @Param			page_size	query		int		false	"Số item/trang (mặc định 10)"
// @Success		200			{object}	response.Body{data=[]dto.CustomerResponse,meta=response.Pagination}
// @Failure		401			{object}	response.Body
// @Failure		500			{object}	response.Body
// @Security		BearerAuth
// @Router			/admin/customers [get]
func (h *CustomerHandler) List(c *gin.Context) {
	page, _ := strconv.Atoi(c.DefaultQuery("page", "1"))
	pageSize, _ := strconv.Atoi(c.DefaultQuery("page_size", "10"))
	if page < 1 {
		page = 1
	}
	if pageSize < 1 || pageSize > 100 {
		pageSize = 10
	}

	filter := domain.CustomerFilter{
		Keyword:  c.Query("keyword"),
		Status:   c.Query("status"),
		Gender:   c.Query("gender"),
		Sort:     c.Query("sort"),
		Page:     page,
		PageSize: pageSize,
	}

	items, total, err := h.svc.List(c.Request.Context(), filter)
	if err != nil {
		response.Error(c, http.StatusInternalServerError, "Lỗi truy vấn danh sách khách hàng")
		return
	}

	totalPages := 1
	if total > 0 {
		totalPages = int((total + int64(pageSize) - 1) / int64(pageSize))
	}

	response.Paginated(c, items, response.Pagination{
		Page:       page,
		PageSize:   pageSize,
		Total:      total,
		TotalPages: totalPages,
	})
}

// @Summary		Thống kê khách hàng
// @Description	Đếm tổng số khách hàng theo trạng thái tài khoản (phục vụ stat cards trang quản trị).
// @Tags			Admin - Customers
// @Accept			json
// @Produce		json
// @Success		200	{object}	response.Body{data=domain.CustomerStats}
// @Failure		401	{object}	response.Body
// @Failure		500	{object}	response.Body
// @Security		BearerAuth
// @Router			/admin/customers/stats [get]
func (h *CustomerHandler) Stats(c *gin.Context) {
	stats, err := h.svc.Stats(c.Request.Context())
	if err != nil {
		response.Error(c, http.StatusInternalServerError, "Lỗi thống kê khách hàng")
		return
	}
	response.OK(c, stats)
}

// @Summary		Chi tiết khách hàng
// @Description	Lấy thông tin chi tiết một khách hàng theo ID (kèm địa chỉ mặc định & số liệu mua hàng).
// @Tags			Admin - Customers
// @Accept			json
// @Produce		json
// @Param			id	path		int	true	"ID khách hàng"
// @Success		200	{object}	response.Body{data=dto.CustomerResponse}
// @Failure		404	{object}	response.Body
// @Security		BearerAuth
// @Router			/admin/customers/{id} [get]
func (h *CustomerHandler) Get(c *gin.Context) {
	id, err := customerID(c)
	if err != nil {
		response.Error(c, http.StatusBadRequest, "ID khách hàng không hợp lệ")
		return
	}

	res, err := h.svc.GetByID(c.Request.Context(), id)
	if err != nil {
		respondCustomerError(c, err, "Lỗi truy vấn khách hàng")
		return
	}
	response.OK(c, res)
}

// @Summary		Thêm mới khách hàng
// @Description	Tạo tài khoản khách hàng mới. Bỏ trống `password` thì hệ thống cấp mật khẩu mặc định.
// @Tags			Admin - Customers
// @Accept			json
// @Produce		json
// @Param			body	body		dto.CustomerRequest	true	"Thông tin khách hàng"
// @Success		201		{object}	response.Body{data=dto.CustomerResponse}
// @Failure		400		{object}	response.Body
// @Failure		409		{object}	response.Body
// @Failure		422		{object}	response.Body
// @Security		BearerAuth
// @Router			/admin/customers [post]
func (h *CustomerHandler) Create(c *gin.Context) {
	var req dto.CustomerRequest
	if !bindJSON(c, &req) {
		return
	}

	res, err := h.svc.Create(c.Request.Context(), &req)
	if err != nil {
		respondCustomerError(c, err, "Không thể tạo tài khoản khách hàng")
		return
	}
	response.Created(c, res)
}

// @Summary		Cập nhật thông tin khách hàng
// @Description	Chỉnh sửa họ tên, email, SĐT, giới tính, ngày sinh, địa chỉ & trạng thái tài khoản.
// @Tags			Admin - Customers
// @Accept			json
// @Produce		json
// @Param			id		path		int					true	"ID khách hàng"
// @Param			body	body		dto.CustomerRequest	true	"Thông tin khách hàng cập nhật"
// @Success		200		{object}	response.Body{data=dto.CustomerResponse}
// @Failure		400		{object}	response.Body
// @Failure		404		{object}	response.Body
// @Failure		422		{object}	response.Body
// @Security		BearerAuth
// @Router			/admin/customers/{id} [put]
func (h *CustomerHandler) Update(c *gin.Context) {
	id, err := customerID(c)
	if err != nil {
		response.Error(c, http.StatusBadRequest, "ID khách hàng không hợp lệ")
		return
	}

	var req dto.CustomerRequest
	if !bindJSON(c, &req) {
		return
	}

	res, err := h.svc.Update(c.Request.Context(), id, &req)
	if err != nil {
		respondCustomerError(c, err, "Lỗi cập nhật thông tin khách hàng")
		return
	}
	response.OK(c, res)
}

// @Summary		Bật/tắt tài khoản khách hàng
// @Description	Chuyển nhanh tài khoản giữa hoạt động (active) và không hoạt động (inactive) mà không cần gửi toàn bộ thông tin.
// @Tags			Admin - Customers
// @Accept			json
// @Produce		json
// @Param			id		path		int							true	"ID khách hàng"
// @Param			body	body		dto.CustomerStatusRequest	true	"Trạng thái mới"
// @Success		200		{object}	response.Body{data=dto.CustomerResponse}
// @Failure		400		{object}	response.Body
// @Failure		404		{object}	response.Body
// @Failure		422		{object}	response.Body
// @Security		BearerAuth
// @Router			/admin/customers/{id}/status [put]
func (h *CustomerHandler) UpdateStatus(c *gin.Context) {
	id, err := customerID(c)
	if err != nil {
		response.Error(c, http.StatusBadRequest, "ID khách hàng không hợp lệ")
		return
	}

	var req dto.CustomerStatusRequest
	if !bindJSON(c, &req) {
		return
	}

	res, err := h.svc.UpdateStatus(c.Request.Context(), id, req.Status)
	if err != nil {
		respondCustomerError(c, err, "Lỗi cập nhật trạng thái khách hàng")
		return
	}
	response.OK(c, res)
}

// @Summary		Cấp mật khẩu đăng nhập cho khách hàng
// @Description	Đặt (hoặc đặt lại) mật khẩu để khách hàng đăng nhập storefront. Mật khẩu được băm bcrypt trước khi lưu.
// @Tags			Admin - Customers
// @Accept			json
// @Produce		json
// @Param			id		path		int							true	"ID khách hàng"
// @Param			body	body		dto.CustomerPasswordRequest	true	"Mật khẩu mới"
// @Success		200		{object}	response.Body{data=dto.CustomerResponse}
// @Failure		400		{object}	response.Body
// @Failure		404		{object}	response.Body
// @Failure		422		{object}	response.Body
// @Security		BearerAuth
// @Router			/admin/customers/{id}/password [put]
func (h *CustomerHandler) SetPassword(c *gin.Context) {
	id, err := customerID(c)
	if err != nil {
		response.Error(c, http.StatusBadRequest, "ID khách hàng không hợp lệ")
		return
	}

	var req dto.CustomerPasswordRequest
	if !bindJSON(c, &req) {
		return
	}

	res, err := h.svc.SetPassword(c.Request.Context(), id, req.Password)
	if err != nil {
		respondCustomerError(c, err, "Lỗi cấp mật khẩu đăng nhập")
		return
	}
	response.OKMessage(c, "Đã cấp mật khẩu đăng nhập", res)
}

// @Summary		Xóa khách hàng
// @Description	Xóa mềm tài khoản khách hàng theo ID.
// @Tags			Admin - Customers
// @Accept			json
// @Produce		json
// @Param			id	path		int	true	"ID khách hàng"
// @Success		200	{object}	response.Body
// @Failure		404	{object}	response.Body
// @Security		BearerAuth
// @Router			/admin/customers/{id} [delete]
func (h *CustomerHandler) Delete(c *gin.Context) {
	id, err := customerID(c)
	if err != nil {
		response.Error(c, http.StatusBadRequest, "ID khách hàng không hợp lệ")
		return
	}

	if err := h.svc.Delete(c.Request.Context(), id); err != nil {
		respondCustomerError(c, err, "Lỗi xóa khách hàng")
		return
	}
	response.OKMessage(c, "Đã xóa khách hàng", nil)
}

// customerID đọc tham số :id trên URL.
func customerID(c *gin.Context) (uint, error) {
	id, err := strconv.ParseUint(c.Param("id"), 10, 64)
	if err != nil || id == 0 {
		return 0, errors.New("id không hợp lệ")
	}
	return uint(id), nil
}

// respondCustomerError ánh xạ lỗi nghiệp vụ sang mã HTTP tương ứng.
func respondCustomerError(c *gin.Context, err error, fallback string) {
	switch {
	case errors.Is(err, domain.ErrNotFound):
		response.Error(c, http.StatusNotFound, "Không tìm thấy khách hàng")
	case errors.Is(err, domain.ErrEmailExists):
		response.Error(c, http.StatusConflict, "Email đã được sử dụng")
	default:
		response.Error(c, http.StatusInternalServerError, fallback)
	}
}
