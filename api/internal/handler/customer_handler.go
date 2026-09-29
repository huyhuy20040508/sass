package handler

import (
	"errors"
	"net/http"
	"strconv"
	"strings"
	"time"

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
// @Param			sort		query		string	false	"newest|oldest|name_asc|name_desc|spent_desc|spent_asc|orders_desc|orders_asc|paid_desc|paid_asc|debt_desc|debt_asc"
// @Param			genders			query	string	false	"male,female,other — gửi rỗng là bảng rỗng; other gồm cả khách chưa khai"
// @Param			created_from	query	string	false	"Ngày tạo từ (YYYY-MM-DD)"
// @Param			created_to		query	string	false	"Ngày tạo đến (YYYY-MM-DD)"
// @Param			address			query	string	false	"Một phần địa chỉ"
// @Param			age_from		query	int		false	"Tuổi từ"
// @Param			age_to			query	int		false	"Tuổi đến"
// @Param			birthday_days	query	int		false	"Sinh nhật trong N ngày tới, tính cả hôm nay"
// @Param			birthday_from	query	string	false	"Sinh nhật từ ngày (YYYY-MM-DD, chỉ xét tháng-ngày)"
// @Param			birthday_to		query	string	false	"Sinh nhật đến ngày (YYYY-MM-DD, chỉ xét tháng-ngày)"
// @Param			last_tx_days	query	int		false	"Đơn gần nhất trong N ngày qua"
// @Param			last_tx_from	query	string	false	"Đơn gần nhất từ ngày (YYYY-MM-DD)"
// @Param			last_tx_to		query	string	false	"Đơn gần nhất đến ngày (YYYY-MM-DD)"
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
		Types:    loaiKhachLoc(c),
		Page:     page,
		PageSize: pageSize,
	}
	if id, err := strconv.ParseUint(c.Query("group_id"), 10, 64); err == nil && id > 0 {
		filter.GroupID = uint(id)
	}
	locCRM(c, &filter, time.Now())

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

// @Summary		Thêm khách mới tại quầy
// @Description	Nút + "Khách mới" của màn quầy: tên, số điện thoại, email, địa chỉ. Mở cho cửa Thu ngân (quyền bán hàng), không cần quyền khu Khách hàng.
// @Description	Số điện thoại đã có hồ sơ thì KHÔNG tạo bản thứ hai — trả 200 kèm hồ sơ có sẵn và `existed = true` để quầy chọn luôn khách đó.
// @Tags			Admin - Orders
// @Accept			json
// @Produce		json
// @Param			body	body		dto.POSKhachMoiRequest	true	"Khách mới"
// @Success		201		{object}	response.Body{data=dto.POSKhachMoiResponse}
// @Success		200		{object}	response.Body{data=dto.POSKhachMoiResponse}	"Số điện thoại đã có hồ sơ"
// @Failure		409		{object}	response.Body	"Email đã có người dùng"
// @Failure		422		{object}	response.Body
// @Security		BearerAuth
// @Router			/admin/orders/pos/khach-hang [post]
func (h *CustomerHandler) TaoTaiQuay(c *gin.Context) {
	var req dto.POSKhachMoiRequest
	if !bindJSON(c, &req) {
		return
	}
	ctx := c.Request.Context()

	// Người đứng quầy hay bấm "Khách mới" cho cả khách quen chỉ vì gõ tìm chưa ra.
	// Hai hồ sơ cùng một số điện thoại là lịch sử mua và công nợ chia đôi — nên
	// trùng số thì chọn hồ sơ có sẵn, không tạo thêm.
	if strings.TrimSpace(req.Phone) != "" {
		co, err := h.svc.TimTheoSoDienThoai(ctx, req.Phone)
		if err != nil {
			response.Error(c, http.StatusInternalServerError, "Lỗi tra khách hàng")

			return
		}
		if co != nil {
			response.OKMessage(c, "Số "+strings.TrimSpace(req.Phone)+" đã có hồ sơ khách "+co.FullName+" — đã chọn khách này.",
				dto.POSKhachMoiResponse{Customer: co, Existed: true})

			return
		}
	}

	res, err := h.svc.Create(ctx, &dto.CustomerRequest{
		FullName:     req.FullName,
		Phone:        req.Phone,
		Email:        req.Email,
		Address:      req.Address,
		CustomerType: req.CustomerType,
		TaxCode:      req.TaxCode,
		Status:       "active",
	})
	if err != nil {
		respondCustomerError(c, err, "Không thể thêm khách hàng")

		return
	}
	response.CreatedMessage(c, "Đã thêm khách "+res.FullName+".", dto.POSKhachMoiResponse{Customer: res})
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
	if loiChiNhanh(c, err) {
		return
	}

	switch {
	case errors.Is(err, domain.ErrNotFound):
		response.Error(c, http.StatusNotFound, "Không tìm thấy khách hàng")
	case errors.Is(err, domain.ErrEmailExists):
		response.Error(c, http.StatusConflict, "Email đã được sử dụng")
	default:
		response.Error(c, http.StatusInternalServerError, fallback)
	}
}

// locCRM đọc khung lọc của màn CRM → Khách hàng vào filter. `homNay` đưa vào
// làm tham số để bài kiểm chốt được ngày.
//
// Hai ô "N ngày" là lối tắt của cùng khoảng ngày — có cả hai thì khoảng tự chọn
// thắng, vì màn hình chỉ bật một lối mỗi lần.
func locCRM(c *gin.Context, f *domain.CustomerFilter, homNay time.Time) {
	if raw, co := c.GetQuery("genders"); co {
		f.Genders = []string{}
		for _, g := range strings.Split(raw, ",") {
			if g = strings.TrimSpace(g); g != "" {
				f.Genders = append(f.Genders, g)
			}
		}
	}

	ngay := func(ten string) *time.Time {
		t, err := time.ParseInLocation("2006-01-02", strings.TrimSpace(c.Query(ten)), homNay.Location())
		if err != nil {
			return nil
		}

		return &t
	}
	so := func(ten string) *int {
		n, err := strconv.Atoi(strings.TrimSpace(c.Query(ten)))
		if err != nil || n < 0 {
			return nil
		}

		return &n
	}

	f.CreatedFrom, f.CreatedTo = ngay("created_from"), ngay("created_to")
	f.Address = strings.TrimSpace(c.Query("address"))
	f.AgeFrom, f.AgeTo = so("age_from"), so("age_to")

	// Sinh nhật: chỉ xét tháng-ngày. Khoảng dài từ một năm trở lên thì mọi ngày
	// sinh đều rơi vào — chỉ còn là "đã khai ngày sinh".
	tu, den := ngay("birthday_from"), ngay("birthday_to")
	if tu == nil && den == nil {
		if n := so("birthday_days"); n != nil && *n > 0 {
			a, b := homNay, homNay.AddDate(0, 0, *n)
			tu, den = &a, &b
		}
	}
	if tu != nil && den != nil && !den.Before(*tu) {
		if den.Sub(*tu) >= 365*24*time.Hour {
			f.BirthdayFrom, f.BirthdayTo = "0101", "1231"
		} else {
			f.BirthdayFrom, f.BirthdayTo = tu.Format("0102"), den.Format("0102")
		}
	}

	f.PointFrom, f.PointTo = so("point_from"), so("point_to")
	if n := so("rank_id"); n != nil {
		f.RankID = uint(*n)
	}

	f.LastTxFrom, f.LastTxTo = ngay("last_tx_from"), ngay("last_tx_to")
	if f.LastTxFrom == nil && f.LastTxTo == nil {
		if n := so("last_tx_days"); n != nil && *n > 0 {
			a, b := homNay.AddDate(0, 0, -*n), homNay
			f.LastTxFrom, f.LastTxTo = &a, &b
		}
	}
}

// loaiKhachLoc đọc ô lọc "Loại khách hàng" — tick nhiều nên nhận cả hai lối:
// `types=0,1` (một chuỗi, lối chính) và `type[]=0&type[]=1` (lối của form HTML).
//
// KHÔNG gửi gì  -> nil        : không cắt theo loại.
// Gửi chuỗi rỗng -> lát cắt rỗng: người dùng bỏ tick hết, bảng phải rỗng theo.
// Hai chuyện đó khác hẳn nhau nên không gộp vào cùng một giá trị.
func loaiKhachLoc(c *gin.Context) []uint {
	raw, co := c.GetQuery("types")
	if !co {
		ds, coMang := c.GetQueryArray("type[]")
		if !coMang {
			return nil
		}
		raw = strings.Join(ds, ",")
	}

	out := []uint{}
	for _, v := range strings.Split(raw, ",") {
		v = strings.TrimSpace(v)
		if v == "" {
			continue
		}
		n, err := strconv.ParseUint(v, 10, 8)
		if err != nil || n > 1 {
			continue
		}
		out = append(out, uint(n))
	}

	return out
}
