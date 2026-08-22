package handler

import (
	"github.com/gin-gonic/gin"

	"sass-api/internal/domain"
	"sass-api/internal/dto"
	"sass-api/internal/service"
	"sass-api/pkg/response"
)

// NhaCungCapHandler — Quản lý kho → Nhà cung cấp.
type NhaCungCapHandler struct {
	svc service.NhaCungCapService
}

func NewNhaCungCapHandler(svc service.NhaCungCapService) *NhaCungCapHandler {
	return &NhaCungCapHandler{svc: svc}
}

// List godoc
//
//	@Summary		Danh sách nhà cung cấp
//	@Description	Tìm theo tên, mã, tên viết tắt, SĐT hoặc MST. Đặt `active=true` để chỉ lấy bên đang hợp tác — ô chọn lúc lập phiếu dùng tham số này.
//	@Description	Mỗi dòng kèm số phiếu đã đặt và ba số tiền tổng hợp từ phiếu đặt hàng nhập (bỏ phiếu nháp và phiếu đã huỷ).
//	@Description	Cố ý KHÔNG phân trang: danh mục của một cửa hàng chỉ vài chục dòng, trang quản trị tự cắt trang.
//	@Tags			Admin - Nhà cung cấp
//	@Produce		json
//	@Security		BearerAuth
//	@Param			keyword	query		string	false	"Tên, mã, tên viết tắt, SĐT hoặc MST"
//	@Param			active	query		bool	false	"true = chỉ bên đang hợp tác"
//	@Success		200		{object}	response.Body{data=[]domain.NhaCungCap}
//	@Failure		401		{object}	response.Body
//	@Router			/admin/nha-cung-cap [get]
func (h *NhaCungCapHandler) List(c *gin.Context) {
	list, err := h.svc.List(c.Request.Context(), domain.NhaCungCapFilter{
		Keyword:    c.Query("keyword"),
		OnlyActive: c.Query("active") == "true",
	})
	if err != nil {
		handleServiceError(c, err)

		return
	}
	response.OK(c, list)
}

// Get godoc
//
//	@Summary	Chi tiết một nhà cung cấp
//	@Tags		Admin - Nhà cung cấp
//	@Produce	json
//	@Security	BearerAuth
//	@Param		id	path		int	true	"ID nhà cung cấp"
//	@Success	200	{object}	response.Body{data=domain.NhaCungCap}
//	@Failure	404	{object}	response.Body
//	@Router		/admin/nha-cung-cap/{id} [get]
func (h *NhaCungCapHandler) Get(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}

	ncc, err := h.svc.GetByID(c.Request.Context(), id)
	if err != nil {
		handleServiceError(c, err)

		return
	}
	response.OK(c, ncc)
}

// Create godoc
//
//	@Summary		Thêm nhà cung cấp
//	@Description	Mã tự viết hoa và phải khác mọi mã đã có TRONG CỬA HÀNG NÀY, kể cả mã của bên đã xoá. Bỏ trống mã thì server tự đặt.
//	@Tags			Admin - Nhà cung cấp
//	@Accept			json
//	@Produce		json
//	@Security		BearerAuth
//	@Param			body	body		dto.NhaCungCapRequest	true	"Thông tin nhà cung cấp"
//	@Success		201		{object}	response.Body{data=domain.NhaCungCap}
//	@Failure		401		{object}	response.Body
//	@Failure		403		{object}	response.Body
//	@Failure		422		{object}	response.Body
//	@Router			/admin/nha-cung-cap [post]
func (h *NhaCungCapHandler) Create(c *gin.Context) {
	var req dto.NhaCungCapRequest
	if !bindJSON(c, &req) {
		return
	}

	ncc, err := h.svc.Create(c.Request.Context(), &req)
	if err != nil {
		handleServiceError(c, err)

		return
	}
	response.Created(c, ncc)
}

// Update godoc
//
//	@Summary		Sửa nhà cung cấp
//	@Description	Bỏ trống mã = giữ nguyên mã cũ; mã đã in trên chứng từ nên không tự đổi.
//	@Tags			Admin - Nhà cung cấp
//	@Accept			json
//	@Produce		json
//	@Security		BearerAuth
//	@Param			id		path		int						true	"ID nhà cung cấp"
//	@Param			body	body		dto.NhaCungCapRequest	true	"Thông tin nhà cung cấp"
//	@Success		200		{object}	response.Body{data=domain.NhaCungCap}
//	@Failure		404		{object}	response.Body
//	@Failure		422		{object}	response.Body
//	@Router			/admin/nha-cung-cap/{id} [put]
func (h *NhaCungCapHandler) Update(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}

	var req dto.NhaCungCapRequest
	if !bindJSON(c, &req) {
		return
	}

	ncc, err := h.svc.Update(c.Request.Context(), id, &req)
	if err != nil {
		handleServiceError(c, err)

		return
	}
	response.OKMessage(c, "Đã cập nhật nhà cung cấp", ncc)
}

// DoiTrangThai godoc
//
//	@Summary		Bật/tắt hợp tác
//	@Description	Tắt = bên này thôi bày ở ô chọn lúc lập phiếu. Dòng vẫn còn nên phiếu cũ vẫn tra ra được tên và số máy.
//	@Tags			Admin - Nhà cung cấp
//	@Accept			json
//	@Produce		json
//	@Security		BearerAuth
//	@Param			id		path		int								true	"ID nhà cung cấp"
//	@Param			body	body		dto.TrangThaiNhaCungCapRequest	true	"Trạng thái"
//	@Success		200		{object}	response.Body{data=domain.NhaCungCap}
//	@Failure		404		{object}	response.Body
//	@Router			/admin/nha-cung-cap/{id}/trang-thai [put]
func (h *NhaCungCapHandler) DoiTrangThai(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}

	var req dto.TrangThaiNhaCungCapRequest
	if !bindJSON(c, &req) {
		return
	}

	ncc, err := h.svc.DoiTrangThai(c.Request.Context(), id, *req.IsActive)
	if err != nil {
		handleServiceError(c, err)

		return
	}
	response.OKMessage(c, "Đã cập nhật trạng thái", ncc)
}

// Delete godoc
//
//	@Summary		Xoá nhà cung cấp
//	@Description	Xoá mềm, và bị chặn khi còn phiếu đặt hàng nhập trỏ tới — phiếu cũ sẽ mất đầu mối liên hệ. Muốn dừng nhập hàng thì tắt hợp tác.
//	@Tags			Admin - Nhà cung cấp
//	@Produce		json
//	@Security		BearerAuth
//	@Param			id	path		int	true	"ID nhà cung cấp"
//	@Success		200	{object}	response.Body
//	@Failure		404	{object}	response.Body
//	@Failure		409	{object}	response.Body
//	@Router			/admin/nha-cung-cap/{id} [delete]
func (h *NhaCungCapHandler) Delete(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}

	if err := h.svc.Delete(c.Request.Context(), id); err != nil {
		handleServiceError(c, err)

		return
	}
	response.OKMessage(c, "Đã xoá nhà cung cấp", nil)
}
