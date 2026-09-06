package handler

import (
	"github.com/gin-gonic/gin"

	"sass-api/internal/domain"
	"sass-api/internal/dto"
	"sass-api/internal/service"
	"sass-api/pkg/response"
)

// NhanSuHandler — hồ sơ nhân viên của cửa hàng đang đăng nhập.
//
// Cả nhóm nằm ở quyền `manage` (thu ngân KHÔNG vào), cùng mức riêng tư với Người
// dùng và Chi nhánh: hồ sơ nhân sự có lương và số căn cước của đồng nghiệp.
type NhanSuHandler struct {
	svc service.NhanSuService
}

func NewNhanSuHandler(svc service.NhanSuService) *NhanSuHandler {
	return &NhanSuHandler{svc: svc}
}

// List godoc
//
//	@Summary		Danh sách nhân sự
//	@Description	Hồ sơ người đi làm của cửa hàng, sắp theo trạng thái (đang làm trước) rồi tới tên.
//	@Description	Không phân trang: số người của một cửa hàng đếm bằng hàng chục.
//	@Tags			Admin - Nhân sự
//	@Produce		json
//	@Security		BearerAuth
//	@Param			keyword			query		string	false	"Tìm theo tên, mã nhân viên hoặc số điện thoại"
//	@Param			status			query		string	false	"dang_lam | tam_nghi | da_nghi"
//	@Param			position		query		string	false	"Chức danh công việc"
//	@Param			contract_type	query		string	false	"Loại hợp đồng"
//	@Param			shop_id			query		int		false	"Chi nhánh"
//	@Success		200				{object}	response.Body{data=[]dto.NhanSuResponse}
//	@Failure		401				{object}	response.Body
//	@Failure		403				{object}	response.Body
//	@Router			/admin/nhan-su [get]
func (h *NhanSuHandler) List(c *gin.Context) {
	// Mặc định cắt theo chi nhánh đang làm việc; `shop_id=0` để xem cả cửa hàng.
	shopID := chiNhanhLoc(c)

	list, err := h.svc.List(c.Request.Context(), domain.NhanSuFilter{
		Keyword:      c.Query("keyword"),
		Status:       c.Query("status"),
		Position:     c.Query("position"),
		WorkShift:    c.Query("work_shift"),
		ContractType: c.Query("contract_type"),
		ShopID:       shopID,
	})
	if err != nil {
		handleServiceError(c, err)

		return
	}
	response.OK(c, list)
}

// Get godoc
//
//	@Summary	Chi tiết một hồ sơ nhân sự
//	@Tags		Admin - Nhân sự
//	@Produce	json
//	@Security	BearerAuth
//	@Param		id	path		int	true	"ID hồ sơ"
//	@Success	200	{object}	response.Body{data=dto.NhanSuResponse}
//	@Failure	401	{object}	response.Body
//	@Failure	403	{object}	response.Body
//	@Failure	404	{object}	response.Body
//	@Router		/admin/nhan-su/{id} [get]
func (h *NhanSuHandler) Get(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}
	nv, err := h.svc.GetByID(c.Request.Context(), id)
	if err != nil {
		handleServiceError(c, err)

		return
	}
	response.OK(c, nv)
}

// Create godoc
//
//	@Summary		Thêm hồ sơ nhân viên
//	@Description	Bỏ trống `code` thì hệ thống tự đặt (NV0001, NV0002…).
//	@Description	Gửi kèm khối `tai_khoan` để cấp luôn tài khoản đăng nhập cho người này —
//	@Description	khi đó hồ sơ phải có `email` (bảng tài khoản bắt buộc và đặt UNIQUE lên email)
//	@Description	và `role_id` là quyền đặt cho tài khoản đó.
//	@Tags			Admin - Nhân sự
//	@Accept			json
//	@Produce		json
//	@Security		BearerAuth
//	@Param			body	body		dto.NhanSuRequest	true	"Hồ sơ nhân viên"
//	@Success		201		{object}	response.Body{data=dto.NhanSuResponse}
//	@Failure		400		{object}	response.Body
//	@Failure		401		{object}	response.Body
//	@Failure		403		{object}	response.Body
//	@Failure		409		{object}	response.Body
//	@Failure		422		{object}	response.Body
//	@Router			/admin/nhan-su [post]
func (h *NhanSuHandler) Create(c *gin.Context) {
	var req dto.NhanSuRequest
	if !bindJSON(c, &req) {
		return
	}
	nv, err := h.svc.Create(c.Request.Context(), &req, currentActor(c))
	if err != nil {
		handleServiceError(c, err)

		return
	}
	response.CreatedMessage(c, "Đã thêm hồ sơ nhân viên", nv)
}

// Update godoc
//
//	@Summary		Sửa hồ sơ nhân viên
//	@Description	Bỏ trống `code` = giữ nguyên mã cũ (mã đã đi vào bảng lương).
//	@Description	Khối `tai_khoan` chỉ dùng để cấp tài khoản cho hồ sơ CHƯA có; đổi mật khẩu
//	@Description	là đường riêng của module tài khoản.
//	@Description	Hồ sơ ĐÃ có tài khoản thì `role_id` là lệnh đổi vai trò cho tài khoản đó.
//	@Description	`status` = `da_nghi` khoá luôn tài khoản gắn kèm; đặt lại `dang_lam` chỉ mở
//	@Description	tài khoản khi gửi kèm `mo_tai_khoan: true`.
//	@Tags			Admin - Nhân sự
//	@Accept			json
//	@Produce		json
//	@Security		BearerAuth
//	@Param			id		path		int					true	"ID hồ sơ"
//	@Param			body	body		dto.NhanSuRequest	true	"Hồ sơ nhân viên"
//	@Success		200		{object}	response.Body{data=dto.NhanSuResponse}
//	@Failure		400		{object}	response.Body
//	@Failure		401		{object}	response.Body
//	@Failure		403		{object}	response.Body
//	@Failure		404		{object}	response.Body
//	@Failure		409		{object}	response.Body
//	@Failure		422		{object}	response.Body
//	@Router			/admin/nhan-su/{id} [put]
func (h *NhanSuHandler) Update(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}
	var req dto.NhanSuRequest
	if !bindJSON(c, &req) {
		return
	}
	nv, err := h.svc.Update(c.Request.Context(), id, &req, currentActor(c))
	if err != nil {
		handleServiceError(c, err)

		return
	}
	response.OKMessage(c, "Đã cập nhật hồ sơ nhân viên", nv)
}

// DoiTrangThai godoc
//
//	@Summary		Bật/tắt trạng thái làm việc
//	@Description	Đường riêng cho công tắc trên bảng danh sách: chỉ đổi cột `status`,
//	@Description	không đụng tới phần còn lại của hồ sơ.
//	@Description	Đặt `da_nghi` sẽ KHOÁ luôn tài khoản đăng nhập gắn với hồ sơ (nếu có) —
//	@Description	nghỉ việc mà tài khoản còn mở thì người đó vẫn vào được quầy bán bằng
//	@Description	mật khẩu cũ. Chiều ngược lại không tự mở: gửi kèm `mo_tai_khoan: true`.
//	@Tags			Admin - Nhân sự
//	@Accept			json
//	@Produce		json
//	@Security		BearerAuth
//	@Param			id		path		int							true	"ID hồ sơ"
//	@Param			body	body		dto.NhanSuTrangThaiRequest	true	"Trạng thái mới"
//	@Success		200		{object}	response.Body{data=dto.NhanSuResponse}
//	@Failure		401		{object}	response.Body
//	@Failure		403		{object}	response.Body
//	@Failure		404		{object}	response.Body
//	@Failure		409		{object}	response.Body
//	@Failure		422		{object}	response.Body
//	@Router			/admin/nhan-su/{id}/trang-thai [put]
func (h *NhanSuHandler) DoiTrangThai(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}
	var req dto.NhanSuTrangThaiRequest
	if !bindJSON(c, &req) {
		return
	}
	nv, err := h.svc.DoiTrangThai(c.Request.Context(), id, &req, currentActor(c))
	if err != nil {
		handleServiceError(c, err)

		return
	}
	response.OKMessage(c, "Đã cập nhật trạng thái làm việc", nv)
}

// DatLaiMatKhau godoc
//
//	@Summary		Đặt lại mật khẩu mặc định cho tài khoản của hồ sơ
//	@Description	Mật khẩu về giá trị cấu hình `staff_default_password` (rỗng thì mặc định phần mềm).
//	@Tags			Admin - Nhân sự
//	@Produce		json
//	@Security		BearerAuth
//	@Param			id	path		int	true	"ID hồ sơ"
//	@Success		200	{object}	response.Body
//	@Failure		404	{object}	response.Body
//	@Failure		409	{object}	response.Body
//	@Router			/admin/nhan-su/{id}/dat-lai-mat-khau [post]
func (h *NhanSuHandler) DatLaiMatKhau(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}
	if err := h.svc.DatLaiMatKhau(c.Request.Context(), id, currentActor(c)); err != nil {
		handleServiceError(c, err)

		return
	}
	response.OKMessage(c, "Đã đặt lại mật khẩu mặc định cho tài khoản này", nil)
}

// Delete godoc
//
//	@Summary		Xoá hồ sơ nhân viên
//	@Description	Xoá MỀM, và KHOÁ luôn tài khoản đăng nhập gắn kèm: xoá là hành động mạnh hơn
//	@Description	"đã nghỉ" nên không thể lỏng hơn. Xoá hồ sơ của chính mình thì bị từ chối.
//	@Description	Người nghỉ việc thì nên đặt trạng thái `da_nghi` thay vì xoá — hồ sơ còn để tra lương cũ.
//	@Tags			Admin - Nhân sự
//	@Produce		json
//	@Security		BearerAuth
//	@Param			id	path		int	true	"ID hồ sơ"
//	@Success		200	{object}	response.Body
//	@Failure		401	{object}	response.Body
//	@Failure		403	{object}	response.Body
//	@Failure		404	{object}	response.Body
//	@Router			/admin/nhan-su/{id} [delete]
func (h *NhanSuHandler) Delete(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}
	if err := h.svc.Delete(c.Request.Context(), id, currentActor(c)); err != nil {
		handleServiceError(c, err)

		return
	}
	response.OKMessage(c, "Đã xoá hồ sơ nhân viên", nil)
}
