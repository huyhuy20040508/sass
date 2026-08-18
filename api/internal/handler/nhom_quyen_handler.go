package handler

import (
	"github.com/gin-gonic/gin"

	"sass-api/internal/domain"
	"sass-api/internal/dto"
	"sass-api/internal/middleware"
	"sass-api/internal/service"
	"sass-api/pkg/response"
)

// NhomQuyenHandler — phân quyền theo chức năng của cửa hàng đang đăng nhập.
//
// Cả nhóm nằm ở quyền `nhom-quyen.*`, trừ QuyenCuaToi: ai đăng nhập được cũng
// phải đọc được quyền của CHÍNH mình, nếu không trang quản trị không có cách nào
// biết nên vẽ những mục menu nào.
type NhomQuyenHandler struct {
	svc service.NhomQuyenService
}

func NewNhomQuyenHandler(svc service.NhomQuyenService) *NhomQuyenHandler {
	return &NhomQuyenHandler{svc: svc}
}

// DanhMuc godoc
//
//	@Summary		Cây quyền của phần mềm
//	@Description	Toàn bộ quyền có thể tick, xếp theo nhóm hiển thị đúng thứ tự thanh điều hướng.
//	@Description	Danh sách này nằm trong mã nguồn chứ không phải database: nó phải khớp từng chữ
//	@Description	với những đường mà máy chủ thật sự chặn.
//	@Tags			Admin - Nhóm quyền
//	@Produce		json
//	@Security		BearerAuth
//	@Success		200	{object}	response.Body{data=[]domain.NhomMucQuyen}
//	@Router			/admin/nhom-quyen/danh-muc [get]
func (h *NhomQuyenHandler) DanhMuc(c *gin.Context) {
	response.OK(c, h.svc.DanhMuc(c.Request.Context()))
}

// QuyenCuaToi godoc
//
//	@Summary		Quyền của chính người đang đăng nhập
//	@Description	Trang quản trị đọc một lần lúc đăng nhập để lọc menu. KHÔNG thay cho chốt ở
//	@Description	máy chủ — ẩn một mục menu chỉ là phép lịch sự, chặn thật nằm ở từng đường.
//	@Tags			Admin - Nhóm quyền
//	@Produce		json
//	@Security		BearerAuth
//	@Success		200	{object}	response.Body{data=dto.QuyenCuaToiResponse}
//	@Router			/admin/quyen-cua-toi [get]
func (h *NhomQuyenHandler) QuyenCuaToi(c *gin.Context) {
	// Super admin không tra bảng — trả thẳng toàn bộ danh mục.
	if c.GetString(middleware.CtxRole) == domain.RoleSuperAdmin {
		response.OK(c, dto.QuyenCuaToiResponse{ToanQuyen: true, Quyen: domain.TatCaQuyen()})

		return
	}

	bo, err := h.svc.BoQuyenCuaToi(c.Request.Context(),
		middleware.CurrentUserID(c), middleware.CurrentTenantID(c))
	if err != nil {
		handleServiceError(c, err)

		return
	}
	response.OK(c, dto.QuyenCuaToiResponse{ToanQuyen: bo.ToanQuyen, Quyen: bo.DanhSach()})
}

// List godoc
//
//	@Summary	Danh sách nhóm quyền
//	@Tags		Admin - Nhóm quyền
//	@Produce	json
//	@Security	BearerAuth
//	@Success	200	{object}	response.Body{data=[]dto.NhomQuyenResponse}
//	@Router		/admin/nhom-quyen [get]
func (h *NhomQuyenHandler) List(c *gin.Context) {
	list, err := h.svc.List(c.Request.Context())
	if err != nil {
		handleServiceError(c, err)

		return
	}
	response.OK(c, list)
}

// Get godoc
//
//	@Summary	Chi tiết một nhóm quyền
//	@Tags		Admin - Nhóm quyền
//	@Produce	json
//	@Security	BearerAuth
//	@Param		id	path		int	true	"ID nhóm"
//	@Success	200	{object}	response.Body{data=dto.NhomQuyenResponse}
//	@Failure	404	{object}	response.Body
//	@Router		/admin/nhom-quyen/{id} [get]
func (h *NhomQuyenHandler) Get(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}
	nq, err := h.svc.GetByID(c.Request.Context(), id)
	if err != nil {
		handleServiceError(c, err)

		return
	}
	response.OK(c, nq)
}

// Create godoc
//
//	@Summary		Thêm nhóm quyền
//	@Description	Bỏ trống `code` thì hệ thống tự đặt (nhom-1, nhom-2…).
//	@Tags			Admin - Nhóm quyền
//	@Accept			json
//	@Produce		json
//	@Security		BearerAuth
//	@Param			body	body		dto.NhomQuyenRequest	true	"Nhóm quyền"
//	@Success		201		{object}	response.Body{data=dto.NhomQuyenResponse}
//	@Failure		422		{object}	response.Body
//	@Router			/admin/nhom-quyen [post]
func (h *NhomQuyenHandler) Create(c *gin.Context) {
	var req dto.NhomQuyenRequest
	if !bindJSON(c, &req) {
		return
	}
	nq, err := h.svc.Create(c.Request.Context(), &req)
	if err != nil {
		handleServiceError(c, err)

		return
	}
	response.CreatedMessage(c, "Đã thêm nhóm quyền", nq)
}

// Update godoc
//
//	@Summary		Sửa nhóm quyền
//	@Description	Mã nhóm KHÔNG đổi được. Bỏ hẳn khoá `quyen` = giữ nguyên danh sách quyền;
//	@Description	gửi mảng rỗng = bỏ hết tick.
//	@Tags			Admin - Nhóm quyền
//	@Accept			json
//	@Produce		json
//	@Security		BearerAuth
//	@Param			id		path		int						true	"ID nhóm"
//	@Param			body	body		dto.NhomQuyenRequest	true	"Nhóm quyền"
//	@Success		200		{object}	response.Body{data=dto.NhomQuyenResponse}
//	@Failure		404		{object}	response.Body
//	@Failure		422		{object}	response.Body
//	@Router			/admin/nhom-quyen/{id} [put]
func (h *NhomQuyenHandler) Update(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}
	var req dto.NhomQuyenRequest
	if !bindJSON(c, &req) {
		return
	}
	nq, err := h.svc.Update(c.Request.Context(), id, &req)
	if err != nil {
		handleServiceError(c, err)

		return
	}
	response.OKMessage(c, "Đã cập nhật nhóm quyền", nq)
}

// DatQuyen godoc
//
//	@Summary		Đặt danh sách quyền của một nhóm
//	@Description	Thay TOÀN BỘ danh sách. Nhóm đang mang cờ "toàn quyền" thì lượt này gỡ cờ đi:
//	@Description	người dùng vừa nói rõ họ muốn một danh sách cụ thể.
//	@Tags			Admin - Nhóm quyền
//	@Accept			json
//	@Produce		json
//	@Security		BearerAuth
//	@Param			id		path		int							true	"ID nhóm"
//	@Param			body	body		dto.NhomQuyenQuyenRequest	true	"Danh sách quyền"
//	@Success		200		{object}	response.Body{data=dto.NhomQuyenResponse}
//	@Failure		404		{object}	response.Body
//	@Failure		422		{object}	response.Body
//	@Router			/admin/nhom-quyen/{id}/quyen [put]
func (h *NhomQuyenHandler) DatQuyen(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}
	var req dto.NhomQuyenQuyenRequest
	if !bindJSON(c, &req) {
		return
	}
	nq, err := h.svc.DatQuyen(c.Request.Context(), id, req.Quyen)
	if err != nil {
		handleServiceError(c, err)

		return
	}
	response.OKMessage(c, "Đã cập nhật quyền của nhóm", nq)
}

// Delete godoc
//
//	@Summary		Xoá nhóm quyền
//	@Description	Nhóm hệ thống và nhóm còn người mang đều KHÔNG xoá được.
//	@Tags			Admin - Nhóm quyền
//	@Produce		json
//	@Security		BearerAuth
//	@Param			id	path		int	true	"ID nhóm"
//	@Success		200	{object}	response.Body
//	@Failure		404	{object}	response.Body
//	@Failure		409	{object}	response.Body
//	@Failure		422	{object}	response.Body
//	@Router			/admin/nhom-quyen/{id} [delete]
func (h *NhomQuyenHandler) Delete(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}
	if err := h.svc.Delete(c.Request.Context(), id); err != nil {
		handleServiceError(c, err)

		return
	}
	response.OKMessage(c, "Đã xoá nhóm quyền", nil)
}

// NhomCuaNguoi godoc
//
//	@Summary	Các nhóm quyền của một tài khoản
//	@Tags		Admin - Nhóm quyền
//	@Produce	json
//	@Security	BearerAuth
//	@Param		id	path		int	true	"ID tài khoản"
//	@Success	200	{object}	response.Body{data=[]int}
//	@Router		/admin/users/{id}/nhom-quyen [get]
func (h *NhomQuyenHandler) NhomCuaNguoi(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}
	ds, err := h.svc.NhomCuaNguoi(c.Request.Context(), id)
	if err != nil {
		handleServiceError(c, err)

		return
	}
	response.OK(c, ds)
}

// DatNhomChoNguoi godoc
//
//	@Summary		Đặt nhóm quyền cho một tài khoản
//	@Description	Thay TOÀN BỘ danh sách nhóm. Mảng rỗng = thu hết quyền (tài khoản vẫn đăng
//	@Description	nhập được, chỉ là không mở được trang nào).
//	@Description	KHÔNG tự đặt cho chính mình: phiên đang chạy bằng token cũ nên màn hình vẫn
//	@Description	trông bình thường, tới lần đăng nhập sau mới phát hiện mất đường vào.
//	@Tags			Admin - Nhóm quyền
//	@Accept			json
//	@Produce		json
//	@Security		BearerAuth
//	@Param			id		path		int							true	"ID tài khoản"
//	@Param			body	body		dto.GanNhomQuyenRequest		true	"Danh sách nhóm"
//	@Success		200		{object}	response.Body
//	@Failure		403		{object}	response.Body
//	@Failure		404		{object}	response.Body
//	@Router			/admin/users/{id}/nhom-quyen [put]
func (h *NhomQuyenHandler) DatNhomChoNguoi(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}
	var req dto.GanNhomQuyenRequest
	if !bindJSON(c, &req) {
		return
	}
	if err := h.svc.DatNhomChoNguoi(c.Request.Context(), id, req.NhomQuyen, currentActor(c)); err != nil {
		handleServiceError(c, err)

		return
	}
	response.OKMessage(c, "Đã cập nhật nhóm quyền của tài khoản", nil)
}
