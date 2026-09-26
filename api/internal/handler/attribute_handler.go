package handler

import (
	"github.com/gin-gonic/gin"

	"sass-api/internal/domain"
	"sass-api/internal/dto"
	"sass-api/internal/service"
	"sass-api/pkg/response"
)

// ThuocTinhHandler — Hàng hóa → Thuộc tính.
type ThuocTinhHandler struct {
	svc service.ThuocTinhService
}

func NewThuocTinhHandler(svc service.ThuocTinhService) *ThuocTinhHandler {
	return &ThuocTinhHandler{svc: svc}
}

// List godoc
//
//	@Summary		Danh sách thuộc tính
//	@Description	Mỗi dòng trả kèm luôn danh sách giá trị (`values`) — thuộc tính không có giá trị nào thì chẳng dùng được vào việc gì, tách thành lượt gọi riêng chỉ tổ khiến màn hình phải gọi hai lần.
//	@Description	Đặt `active=true` để chỉ lấy thuộc tính đang bật (ô chọn lúc khai mặt hàng).
//	@Description	Cố ý KHÔNG phân trang: danh sách thuộc tính của một cửa hàng chỉ vài chục dòng, trang quản trị tự cắt trang.
//	@Tags			Admin - Thuộc tính
//	@Produce		json
//	@Security		BearerAuth
//	@Param			keyword			query		string	false	"Tên hoặc mã thuộc tính"
//	@Param			active			query		bool	false	"true = chỉ thuộc tính đang bật"
//	@Success		200				{object}	response.Body{data=[]domain.ThuocTinh}
//	@Failure		401				{object}	response.Body
//	@Router			/admin/thuoc-tinh [get]
func (h *ThuocTinhHandler) List(c *gin.Context) {
	list, err := h.svc.List(c.Request.Context(), domain.ThuocTinhFilter{
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
//	@Summary	Chi tiết một thuộc tính
//	@Tags		Admin - Thuộc tính
//	@Produce	json
//	@Security	BearerAuth
//	@Param		id	path		int	true	"ID thuộc tính"
//	@Success	200	{object}	response.Body{data=domain.ThuocTinh}
//	@Failure	404	{object}	response.Body
//	@Router		/admin/thuoc-tinh/{id} [get]
func (h *ThuocTinhHandler) Get(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}

	tt, err := h.svc.GetByID(c.Request.Context(), id)
	if err != nil {
		handleServiceError(c, err)

		return
	}
	response.OK(c, tt)
}

// Create godoc
//
//	@Summary		Thêm thuộc tính
//	@Description	Mã tự viết hoa và phải khác mọi mã đã có TRONG CỬA HÀNG NÀY, kể cả mã của thuộc tính đã xoá. Bỏ trống thì server đặt hộ (theo quy tắc đánh số của cửa hàng nếu đã bật, không thì dải TT001).
//	@Description	`values` là danh sách giá trị khai kèm; giá trị nào bỏ trống mã thì server đặt hộ theo dạng SIZE01, SIZE02…
//	@Tags			Admin - Thuộc tính
//	@Accept			json
//	@Produce		json
//	@Security		BearerAuth
//	@Param			body	body		dto.ThuocTinhRequest	true	"Thông tin thuộc tính"
//	@Success		201		{object}	response.Body{data=domain.ThuocTinh}
//	@Failure		401		{object}	response.Body
//	@Failure		403		{object}	response.Body
//	@Failure		422		{object}	response.Body
//	@Router			/admin/thuoc-tinh [post]
func (h *ThuocTinhHandler) Create(c *gin.Context) {
	var req dto.ThuocTinhRequest
	if !bindJSON(c, &req) {
		return
	}

	tt, err := h.svc.Create(c.Request.Context(), &req)
	if err != nil {
		handleServiceError(c, err)

		return
	}
	response.Created(c, tt)
}

// Update godoc
//
//	@Summary		Sửa thuộc tính
//	@Description	`values` là trạng thái CUỐI CÙNG của bảng giá trị: dòng có id thì sửa, không id thì thêm, giá trị cũ vắng mặt trong danh sách nghĩa là bị xoá.
//	@Description	Bỏ HẲN trường `values` thì bảng giá trị không bị đụng tới; gửi mảng rỗng mới là xoá hết.
//	@Tags			Admin - Thuộc tính
//	@Accept			json
//	@Produce		json
//	@Security		BearerAuth
//	@Param			id		path		int						true	"ID thuộc tính"
//	@Param			body	body		dto.ThuocTinhRequest	true	"Thông tin thuộc tính"
//	@Success		200		{object}	response.Body{data=domain.ThuocTinh}
//	@Failure		404		{object}	response.Body
//	@Failure		422		{object}	response.Body
//	@Router			/admin/thuoc-tinh/{id} [put]
func (h *ThuocTinhHandler) Update(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}

	var req dto.ThuocTinhRequest
	if !bindJSON(c, &req) {
		return
	}

	tt, err := h.svc.Update(c.Request.Context(), id, &req)
	if err != nil {
		handleServiceError(c, err)

		return
	}
	response.OKMessage(c, "Đã cập nhật thuộc tính", tt)
}

// DoiTrangThai godoc
//
//	@Summary		Bật/tắt một thuộc tính
//	@Description	Tắt = thôi bày ở ô chọn thuộc tính lúc khai mặt hàng. Dòng vẫn còn nên mặt hàng cũ vẫn tra ra được tên thuộc tính.
//	@Tags			Admin - Thuộc tính
//	@Accept			json
//	@Produce		json
//	@Security		BearerAuth
//	@Param			id		path		int								true	"ID thuộc tính"
//	@Param			body	body		dto.TrangThaiThuocTinhRequest	true	"Trạng thái"
//	@Success		200		{object}	response.Body{data=domain.ThuocTinh}
//	@Failure		404		{object}	response.Body
//	@Router			/admin/thuoc-tinh/{id}/trang-thai [put]
func (h *ThuocTinhHandler) DoiTrangThai(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}

	var req dto.TrangThaiThuocTinhRequest
	if !bindJSON(c, &req) {
		return
	}

	tt, err := h.svc.DoiTrangThai(c.Request.Context(), id, *req.IsActive)
	if err != nil {
		handleServiceError(c, err)

		return
	}
	response.OKMessage(c, "Đã cập nhật trạng thái", tt)
}

// Delete godoc
//
//	@Summary		Xoá thuộc tính
//	@Description	Xoá mềm thuộc tính và xoá hẳn các giá trị của nó. Thuộc tính đã thôi dùng nhưng còn muốn tra lại thì nên TẮT thay vì xoá.
//	@Tags			Admin - Thuộc tính
//	@Produce		json
//	@Security		BearerAuth
//	@Param			id	path		int	true	"ID thuộc tính"
//	@Success		200	{object}	response.Body
//	@Failure		404	{object}	response.Body
//	@Router			/admin/thuoc-tinh/{id} [delete]
func (h *ThuocTinhHandler) Delete(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}

	if err := h.svc.Delete(c.Request.Context(), id); err != nil {
		handleServiceError(c, err)

		return
	}
	response.OKMessage(c, "Đã xoá thuộc tính", nil)
}
