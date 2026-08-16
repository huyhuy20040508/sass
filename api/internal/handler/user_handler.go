package handler

import (
	"strconv"
	"time"

	"github.com/gin-gonic/gin"

	"sass-api/internal/domain"
	"sass-api/internal/dto"
	"sass-api/internal/middleware"
	"sass-api/internal/service"
	"sass-api/pkg/response"
)

type UserHandler struct {
	svc service.UserService
}

func NewUserHandler(svc service.UserService) *UserHandler {
	return &UserHandler{svc: svc}
}

// List godoc
//
//	@Summary		Danh sách tài khoản nội bộ
//	@Description	Tài khoản quản trị và nhân viên (mọi vai trò TRỪ khách hàng — khách hàng
//	@Description	nằm ở /admin/customers). Lọc theo từ khoá, vai trò, trạng thái, khoảng
//	@Description	ngày tạo; có sắp xếp và phân trang.
//	@Tags			Admin - Users
//	@Produce		json
//	@Security		BearerAuth
//	@Param			keyword		query		string	false	"Tìm theo tên / email / số điện thoại"
//	@Param			role_id		query		int		false	"Lọc theo vai trò (1 super_admin, 2 admin, 3 staff)"
//	@Param			status		query		string	false	"all | active | inactive"
//	@Param			from_date	query		string	false	"Ngày tạo từ (YYYY-MM-DD)"
//	@Param			to_date		query		string	false	"Ngày tạo đến (YYYY-MM-DD)"
//	@Param			sort		query		string	false	"newest | oldest | name_asc | name_desc"
//	@Param			page		query		int		false	"Trang (mặc định 1)"
//	@Param			page_size	query		int		false	"Số dòng/trang (mặc định 20, tối đa 100)"
//	@Success		200			{object}	response.Body{data=[]dto.UserResponse,meta=response.Pagination}
//	@Failure		401			{object}	response.Body
//	@Failure		403			{object}	response.Body
//	@Failure		500			{object}	response.Body
//	@Router			/admin/users [get]
func (h *UserHandler) List(c *gin.Context) {
	page, _ := strconv.Atoi(c.DefaultQuery("page", "1"))
	pageSize, _ := strconv.Atoi(c.DefaultQuery("page_size", "20"))
	if page < 1 {
		page = 1
	}
	if pageSize < 1 || pageSize > 100 {
		pageSize = 20
	}
	roleID, _ := strconv.ParseUint(c.Query("role_id"), 10, 64)

	filter := domain.InternalUserFilter{
		Keyword:  c.Query("keyword"),
		RoleID:   uint(roleID),
		Status:   c.Query("status"),
		FromDate: parseDayStart(c.Query("from_date")),
		ToDate:   parseDayEnd(c.Query("to_date")),
		Sort:     c.Query("sort"),
		Page:     page,
		PageSize: pageSize,
	}

	items, total, err := h.svc.List(c.Request.Context(), filter)
	if err != nil {
		handleServiceError(c, err)
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

// Stats godoc
//
//	@Summary		Thống kê tài khoản nội bộ
//	@Description	Đếm tài khoản quản trị/nhân viên theo trạng thái, KHÔNG phụ thuộc bộ lọc
//	@Description	đang xem (phục vụ các ô số liệu đầu trang).
//	@Tags			Admin - Users
//	@Produce		json
//	@Security		BearerAuth
//	@Success		200	{object}	response.Body{data=domain.InternalUserStats}
//	@Failure		401	{object}	response.Body
//	@Failure		403	{object}	response.Body
//	@Failure		500	{object}	response.Body
//	@Router			/admin/users/stats [get]
func (h *UserHandler) Stats(c *gin.Context) {
	stats, err := h.svc.Stats(c.Request.Context())
	if err != nil {
		handleServiceError(c, err)
		return
	}
	response.OK(c, stats)
}

// Get godoc
//
//	@Summary		Chi tiết tài khoản nội bộ
//	@Description	Lấy một tài khoản quản trị/nhân viên theo id. Id của khách hàng trả 404 —
//	@Description	đường này không đọc được dữ liệu khách hàng.
//	@Tags			Admin - Users
//	@Produce		json
//	@Security		BearerAuth
//	@Param			id	path		int	true	"ID tài khoản"
//	@Success		200	{object}	response.Body{data=dto.UserResponse}
//	@Failure		401	{object}	response.Body
//	@Failure		403	{object}	response.Body
//	@Failure		404	{object}	response.Body
//	@Router			/admin/users/{id} [get]
func (h *UserHandler) Get(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}

	res, err := h.svc.GetByID(c.Request.Context(), id)
	if err != nil {
		handleServiceError(c, err)
		return
	}
	response.OK(c, res)
}

// Create godoc
//
//	@Summary		Thêm tài khoản nội bộ
//	@Description	Tạo tài khoản quản trị hoặc nhân viên. Bỏ trống `password` thì hệ thống cấp
//	@Description	mật khẩu mặc định. Chỉ super admin mới tạo được tài khoản super admin;
//	@Description	`role_id` là vai trò khách hàng sẽ bị từ chối.
//	@Description	Trả 409 khi cửa hàng đã dùng hết hạn mức tài khoản của hợp đồng (`max_users`) —
//	@Description	tài khoản đang khoá vẫn tính là một chỗ.
//	@Tags			Admin - Users
//	@Accept			json
//	@Produce		json
//	@Security		BearerAuth
//	@Param			body	body		dto.UserRequest	true	"Thông tin tài khoản"
//	@Success		201		{object}	response.Body{data=dto.UserResponse}
//	@Failure		400		{object}	response.Body
//	@Failure		401		{object}	response.Body
//	@Failure		403		{object}	response.Body
//	@Failure		409		{object}	response.Body
//	@Failure		422		{object}	response.Body
//	@Router			/admin/users [post]
func (h *UserHandler) Create(c *gin.Context) {
	var req dto.UserRequest
	if !bindJSON(c, &req) {
		return
	}

	res, err := h.svc.Create(c.Request.Context(), &req, currentActor(c))
	if err != nil {
		handleServiceError(c, err)
		return
	}
	response.Created(c, res)
}

// Update godoc
//
//	@Summary		Cập nhật tài khoản nội bộ
//	@Description	Sửa họ tên, email, số điện thoại, ảnh, vai trò và trạng thái.
//	@Description	Không được tự đổi vai trò hoặc tự đổi trạng thái của CHÍNH MÌNH, và không
//	@Description	được hạ vai trò / khoá người super admin đang hoạt động cuối cùng.
//	@Tags			Admin - Users
//	@Accept			json
//	@Produce		json
//	@Security		BearerAuth
//	@Param			id		path		int				true	"ID tài khoản"
//	@Param			body	body		dto.UserRequest	true	"Thông tin cập nhật"
//	@Success		200		{object}	response.Body{data=dto.UserResponse}
//	@Failure		400		{object}	response.Body
//	@Failure		401		{object}	response.Body
//	@Failure		403		{object}	response.Body
//	@Failure		404		{object}	response.Body
//	@Failure		409		{object}	response.Body
//	@Failure		422		{object}	response.Body
//	@Router			/admin/users/{id} [put]
func (h *UserHandler) Update(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}

	var req dto.UserRequest
	if !bindJSON(c, &req) {
		return
	}

	res, err := h.svc.Update(c.Request.Context(), id, &req, currentActor(c))
	if err != nil {
		handleServiceError(c, err)
		return
	}
	response.OK(c, res)
}

// UpdateStatus godoc
//
//	@Summary		Bật/khoá tài khoản nội bộ
//	@Description	Chuyển nhanh giữa `active` và `inactive`. Không tự khoá được chính mình và
//	@Description	không khoá được super admin đang hoạt động cuối cùng.
//	@Tags			Admin - Users
//	@Accept			json
//	@Produce		json
//	@Security		BearerAuth
//	@Param			id		path		int						true	"ID tài khoản"
//	@Param			body	body		dto.UserStatusRequest	true	"Trạng thái mới"
//	@Success		200		{object}	response.Body{data=dto.UserResponse}
//	@Failure		400		{object}	response.Body
//	@Failure		401		{object}	response.Body
//	@Failure		403		{object}	response.Body
//	@Failure		404		{object}	response.Body
//	@Failure		409		{object}	response.Body
//	@Failure		422		{object}	response.Body
//	@Router			/admin/users/{id}/status [put]
func (h *UserHandler) UpdateStatus(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}

	var req dto.UserStatusRequest
	if !bindJSON(c, &req) {
		return
	}

	res, err := h.svc.UpdateStatus(c.Request.Context(), id, req.Status, currentActor(c))
	if err != nil {
		handleServiceError(c, err)
		return
	}
	response.OK(c, res)
}

// SetPassword godoc
//
//	@Summary		Đặt lại mật khẩu tài khoản nội bộ
//	@Description	Đặt mật khẩu đăng nhập trang quản trị (băm bcrypt trước khi lưu). Quản trị
//	@Description	viên thường không đặt lại được mật khẩu của super admin.
//	@Tags			Admin - Users
//	@Accept			json
//	@Produce		json
//	@Security		BearerAuth
//	@Param			id		path		int						true	"ID tài khoản"
//	@Param			body	body		dto.UserPasswordRequest	true	"Mật khẩu mới"
//	@Success		200		{object}	response.Body{data=dto.UserResponse}
//	@Failure		400		{object}	response.Body
//	@Failure		401		{object}	response.Body
//	@Failure		403		{object}	response.Body
//	@Failure		404		{object}	response.Body
//	@Failure		422		{object}	response.Body
//	@Router			/admin/users/{id}/password [put]
func (h *UserHandler) SetPassword(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}

	var req dto.UserPasswordRequest
	if !bindJSON(c, &req) {
		return
	}

	res, err := h.svc.SetPassword(c.Request.Context(), id, req.Password, currentActor(c))
	if err != nil {
		handleServiceError(c, err)
		return
	}
	response.OKMessage(c, "Đã đặt lại mật khẩu", res)
}

// Delete godoc
//
//	@Summary		Xoá tài khoản nội bộ
//	@Description	Xoá mềm tài khoản. Không tự xoá được chính mình và không xoá được super
//	@Description	admin đang hoạt động cuối cùng. Email của tài khoản đã xoá vẫn bị giữ chỗ
//	@Description	nên không dùng lại được cho tài khoản mới.
//	@Tags			Admin - Users
//	@Produce		json
//	@Security		BearerAuth
//	@Param			id	path		int	true	"ID tài khoản"
//	@Success		200	{object}	response.Body
//	@Failure		401	{object}	response.Body
//	@Failure		403	{object}	response.Body
//	@Failure		404	{object}	response.Body
//	@Failure		409	{object}	response.Body
//	@Router			/admin/users/{id} [delete]
func (h *UserHandler) Delete(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}

	if err := h.svc.Delete(c.Request.Context(), id, currentActor(c)); err != nil {
		handleServiceError(c, err)
		return
	}
	response.OKMessage(c, "Đã xoá tài khoản", nil)
}

// Me godoc
//
//	@Summary		Hồ sơ của tôi
//	@Description	Tài khoản đang đăng nhập, đọc theo access token. Khác /admin/users/{id} ở
//	@Description	chỗ MỌI vai trò nội bộ đều gọi được, kể cả nhân viên — họ không vào được
//	@Description	nhóm endpoint quản lý người dùng nhưng vẫn phải xem được hồ sơ của mình.
//	@Tags			Admin - Profile
//	@Produce		json
//	@Security		BearerAuth
//	@Success		200	{object}	response.Body{data=dto.UserResponse}
//	@Failure		401	{object}	response.Body
//	@Failure		403	{object}	response.Body
//	@Failure		404	{object}	response.Body
//	@Router			/admin/me [get]
func (h *UserHandler) Me(c *gin.Context) {
	res, err := h.svc.Profile(c.Request.Context(), currentUserID(c))
	if err != nil {
		handleServiceError(c, err)
		return
	}
	response.OK(c, res)
}

// UpdateMe godoc
//
//	@Summary		Sửa hồ sơ của tôi
//	@Description	Đổi họ tên, số điện thoại và ảnh đại diện của chính tài khoản đang đăng
//	@Description	nhập. Vai trò và trạng thái không sửa được ở đây; email là tên đăng nhập
//	@Description	nên phải nhờ quản trị viên đổi qua /admin/users/{id}.
//	@Tags			Admin - Profile
//	@Accept			json
//	@Produce		json
//	@Security		BearerAuth
//	@Param			body	body		dto.ProfileRequest	true	"Thông tin hồ sơ"
//	@Success		200		{object}	response.Body{data=dto.UserResponse}
//	@Failure		400		{object}	response.Body
//	@Failure		401		{object}	response.Body
//	@Failure		403		{object}	response.Body
//	@Failure		404		{object}	response.Body
//	@Failure		422		{object}	response.Body
//	@Router			/admin/me [put]
func (h *UserHandler) UpdateMe(c *gin.Context) {
	var req dto.ProfileRequest
	if !bindJSON(c, &req) {
		return
	}

	res, err := h.svc.UpdateProfile(c.Request.Context(), currentUserID(c), &req)
	if err != nil {
		handleServiceError(c, err)
		return
	}
	response.OKMessage(c, "Đã lưu hồ sơ", res)
}

// ChangePassword godoc
//
//	@Summary		Đổi mật khẩu của tôi
//	@Description	Người đang đăng nhập tự đổi mật khẩu, phải nhập đúng mật khẩu hiện tại.
//	@Description	Mật khẩu hiện tại sai trả 422 (KHÔNG phải 401) — phiên đăng nhập vẫn còn
//	@Description	hiệu lực, chỉ mỗi ô nhập là sai.
//	@Tags			Admin - Profile
//	@Accept			json
//	@Produce		json
//	@Security		BearerAuth
//	@Param			body	body		dto.ChangePasswordRequest	true	"Mật khẩu hiện tại & mật khẩu mới"
//	@Success		200		{object}	response.Body
//	@Failure		400		{object}	response.Body
//	@Failure		401		{object}	response.Body
//	@Failure		403		{object}	response.Body
//	@Failure		404		{object}	response.Body
//	@Failure		422		{object}	response.Body
//	@Router			/admin/me/password [put]
func (h *UserHandler) ChangePassword(c *gin.Context) {
	var req dto.ChangePasswordRequest
	if !bindJSON(c, &req) {
		return
	}

	if err := h.svc.ChangePassword(c.Request.Context(), currentUserID(c), &req); err != nil {
		handleServiceError(c, err)
		return
	}
	response.OKMessage(c, "Đã đổi mật khẩu", nil)
}

// Roles godoc
//
//	@Summary		Danh sách vai trò
//	@Description	Bốn vai trò của hệ thống kèm số tài khoản đang mang vai trò đó. `internal`
//	@Description	= true là vai trò gán được cho tài khoản nội bộ; vai trò khách hàng thì không.
//	@Tags			Admin - Users
//	@Produce		json
//	@Security		BearerAuth
//	@Success		200	{object}	response.Body{data=[]dto.RoleResponse}
//	@Failure		401	{object}	response.Body
//	@Failure		403	{object}	response.Body
//	@Failure		500	{object}	response.Body
//	@Router			/admin/roles [get]
func (h *UserHandler) Roles(c *gin.Context) {
	items, err := h.svc.Roles(c.Request.Context())
	if err != nil {
		handleServiceError(c, err)
		return
	}
	response.OK(c, items)
}

// UpdateRole godoc
//
//	@Summary		Sửa vai trò
//	@Description	Chỉ đổi được tên hiển thị và mô tả. Mã vai trò (`name`) là thứ hệ thống so
//	@Description	khớp để phân quyền nên không mở cho sửa.
//	@Tags			Admin - Users
//	@Accept			json
//	@Produce		json
//	@Security		BearerAuth
//	@Param			id		path		int						true	"ID vai trò"
//	@Param			body	body		dto.RoleUpdateRequest	true	"Tên hiển thị & mô tả"
//	@Success		200		{object}	response.Body{data=dto.RoleResponse}
//	@Failure		400		{object}	response.Body
//	@Failure		401		{object}	response.Body
//	@Failure		403		{object}	response.Body
//	@Failure		404		{object}	response.Body
//	@Failure		422		{object}	response.Body
//	@Router			/admin/roles/{id} [put]
func (h *UserHandler) UpdateRole(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}

	var req dto.RoleUpdateRequest
	if !bindJSON(c, &req) {
		return
	}

	res, err := h.svc.UpdateRole(c.Request.Context(), id, &req)
	if err != nil {
		handleServiceError(c, err)
		return
	}
	response.OK(c, res)
}

// currentActor dựng thông tin người đang thao tác từ token đã qua JWTAuth.
func currentActor(c *gin.Context) service.Actor {
	return service.Actor{
		ID:   currentUserID(c),
		Role: c.GetString(middleware.CtxRole),
	}
}

// parseDayStart đọc "YYYY-MM-DD" thành mốc 00:00:00 của ngày đó; rỗng/sai -> nil.
func parseDayStart(s string) *time.Time {
	t, err := time.ParseInLocation("2006-01-02", s, time.Local)
	if err != nil {
		return nil
	}
	return &t
}

// parseDayEnd đọc "YYYY-MM-DD" thành mốc cuối ngày.
//
// Phải là cuối ngày chứ không phải 00:00: lọc "đến 30/07" mà cắt ở 0 giờ thì mọi
// tài khoản tạo trong chính ngày 30/07 đều rơi ra ngoài kết quả.
func parseDayEnd(s string) *time.Time {
	t, err := time.ParseInLocation("2006-01-02", s, time.Local)
	if err != nil {
		return nil
	}
	end := t.Add(24*time.Hour - time.Nanosecond)
	return &end
}
