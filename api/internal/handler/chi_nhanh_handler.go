package handler

import (
	"github.com/gin-gonic/gin"

	"sass-api/internal/dto"
	"sass-api/internal/service"
	"sass-api/pkg/response"
)

// ChiNhanhHandler — các ĐIỂM BÁN của cửa hàng đang đăng nhập.
//
// "Chi nhánh" = bảng `shops`, KHÔNG phải cửa hàng (tenant) — xem domain.ChiNhanh.
//
// Cả nhóm nằm ở quyền `manage` (nhân viên KHÔNG vào), cùng mức với Người dùng và
// Cài đặt: mở thêm một điểm bán là quyết định của chủ tiệm, và nó ăn thẳng vào
// hạn mức hợp đồng.
type ChiNhanhHandler struct {
	svc service.ChiNhanhService
}

func NewChiNhanhHandler(svc service.ChiNhanhService) *ChiNhanhHandler {
	return &ChiNhanhHandler{svc: svc}
}

// List godoc
//
//	@Summary		Danh sách chi nhánh
//	@Description	Các điểm bán của chính cửa hàng đang đăng nhập, sắp theo mã.
//	@Description	Đặt `active=true` để bỏ chi nhánh đã ngừng hoạt động (các ô chọn nơi bán dùng tham số này).
//	@Tags			Admin - Chi nhánh
//	@Produce		json
//	@Security		BearerAuth
//	@Param			active	query		bool	false	"true = chỉ chi nhánh đang hoạt động"
//	@Success		200		{object}	response.Body{data=[]domain.ChiNhanh}
//	@Failure		401		{object}	response.Body
//	@Failure		403		{object}	response.Body
//	@Router			/admin/chi-nhanh [get]
func (h *ChiNhanhHandler) List(c *gin.Context) {
	list, err := h.svc.List(c.Request.Context(), c.Query("active") == "true")
	if err != nil {
		handleServiceError(c, err)

		return
	}
	response.OK(c, list)
}

// Get godoc
//
//	@Summary	Chi tiết một chi nhánh
//	@Tags		Admin - Chi nhánh
//	@Produce	json
//	@Security	BearerAuth
//	@Param		id	path		int	true	"ID chi nhánh"
//	@Success	200	{object}	response.Body{data=domain.ChiNhanh}
//	@Failure	401	{object}	response.Body
//	@Failure	403	{object}	response.Body
//	@Failure	404	{object}	response.Body
//	@Router		/admin/chi-nhanh/{id} [get]
func (h *ChiNhanhHandler) Get(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}
	cn, err := h.svc.GetByID(c.Request.Context(), id)
	if err != nil {
		handleServiceError(c, err)

		return
	}
	response.OK(c, cn)
}

// Create godoc
//
//	@Summary		Mở thêm chi nhánh
//	@Description	Bỏ trống `code` thì hệ thống tự đặt (chi-nhanh-2, chi-nhanh-3…).
//	@Description	Trả 409 khi cửa hàng đã dùng hết hạn mức chi nhánh của hợp đồng (`max_shops`) —
//	@Description	đây chính là quyền lợi phân biệt gói Chuỗi với hai gói còn lại.
//	@Tags			Admin - Chi nhánh
//	@Accept			json
//	@Produce		json
//	@Security		BearerAuth
//	@Param			body	body		dto.ChiNhanhRequest	true	"Thông tin chi nhánh"
//	@Success		201		{object}	response.Body{data=domain.ChiNhanh}
//	@Failure		400		{object}	response.Body
//	@Failure		401		{object}	response.Body
//	@Failure		403		{object}	response.Body
//	@Failure		409		{object}	response.Body
//	@Failure		422		{object}	response.Body
//	@Router			/admin/chi-nhanh [post]
func (h *ChiNhanhHandler) Create(c *gin.Context) {
	var req dto.ChiNhanhRequest
	if !bindJSON(c, &req) {
		return
	}
	cn, err := h.svc.Create(c.Request.Context(), &req)
	if err != nil {
		handleServiceError(c, err)

		return
	}
	response.CreatedMessage(c, "Đã mở chi nhánh mới", cn)
}

// Update godoc
//
//	@Summary		Sửa chi nhánh
//	@Description	Bỏ trống `code` = giữ nguyên mã cũ (mã đã đi vào chứng từ của chi nhánh này).
//	@Description	Trả 409 khi đây là chi nhánh đang hoạt động cuối cùng mà lượt sửa lại tắt nó đi.
//	@Tags			Admin - Chi nhánh
//	@Accept			json
//	@Produce		json
//	@Security		BearerAuth
//	@Param			id		path		int					true	"ID chi nhánh"
//	@Param			body	body		dto.ChiNhanhRequest	true	"Thông tin chi nhánh"
//	@Success		200		{object}	response.Body{data=domain.ChiNhanh}
//	@Failure		400		{object}	response.Body
//	@Failure		401		{object}	response.Body
//	@Failure		403		{object}	response.Body
//	@Failure		404		{object}	response.Body
//	@Failure		409		{object}	response.Body
//	@Failure		422		{object}	response.Body
//	@Router			/admin/chi-nhanh/{id} [put]
func (h *ChiNhanhHandler) Update(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}
	var req dto.ChiNhanhRequest
	if !bindJSON(c, &req) {
		return
	}
	cn, err := h.svc.Update(c.Request.Context(), id, &req)
	if err != nil {
		handleServiceError(c, err)

		return
	}
	response.OKMessage(c, "Đã cập nhật chi nhánh", cn)
}

// Delete godoc
//
//	@Summary		Xoá chi nhánh
//	@Description	Xoá MỀM: dữ liệu cũ phát sinh tại chi nhánh này vẫn tra lại được.
//	@Description	Trả 409 khi đây là chi nhánh đang hoạt động cuối cùng — cửa hàng phải luôn còn một điểm bán.
//	@Tags			Admin - Chi nhánh
//	@Produce		json
//	@Security		BearerAuth
//	@Param			id	path		int	true	"ID chi nhánh"
//	@Success		200	{object}	response.Body
//	@Failure		401	{object}	response.Body
//	@Failure		403	{object}	response.Body
//	@Failure		404	{object}	response.Body
//	@Failure		409	{object}	response.Body
//	@Router			/admin/chi-nhanh/{id} [delete]
func (h *ChiNhanhHandler) Delete(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}
	if err := h.svc.Delete(c.Request.Context(), id); err != nil {
		handleServiceError(c, err)

		return
	}
	response.OKMessage(c, "Đã xoá chi nhánh", nil)
}
