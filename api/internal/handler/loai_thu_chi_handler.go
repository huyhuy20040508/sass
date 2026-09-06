package handler

import (
	"strconv"

	"github.com/gin-gonic/gin"

	"sass-api/internal/domain"
	"sass-api/internal/dto"
	"sass-api/internal/service"
	"sass-api/pkg/response"
)

// LoaiThuChiHandler — Thu chi → Loại thu chi.
type LoaiThuChiHandler struct {
	svc service.LoaiThuChiService
}

func NewLoaiThuChiHandler(svc service.LoaiThuChiService) *LoaiThuChiHandler {
	return &LoaiThuChiHandler{svc: svc}
}

// List godoc
//
//	@Summary		Danh sách loại thu chi
//	@Description	Trả về CẢ loại thu lẫn loại chi trong một lượt — màn quản trị dựng hai bảng cạnh nhau nên gọi hai lượt chỉ tổ chậm. Lọc riêng một vế bằng `type` (0 thu, 1 chi).
//	@Description	Cố ý KHÔNG phân trang: danh sách của một cửa hàng chỉ vài chục dòng.
//	@Tags			Admin - Loại thu chi
//	@Produce		json
//	@Security		BearerAuth
//	@Param			type	query		int		false	"0 = loại thu, 1 = loại chi. Bỏ trống = cả hai"
//	@Param			keyword	query		string	false	"Tên phân loại"
//	@Success		200		{object}	response.Body{data=[]domain.LoaiThuChi}
//	@Failure		401		{object}	response.Body
//	@Router			/admin/loai-thu-chi [get]
func (h *LoaiThuChiHandler) List(c *gin.Context) {
	f := domain.LoaiThuChiFilter{Keyword: c.Query("keyword")}

	// Giá trị lạ ở `type` bị TỪ CHỐI chứ không lặng lẽ bỏ qua: bỏ qua thì người
	// gọi xin riêng loại chi lại nhận về cả hai vế và cộng nhầm.
	if raw := c.Query("type"); raw != "" {
		n, err := strconv.ParseUint(raw, 10, 8)
		if err != nil || (uint8(n) != domain.LoaiThu && uint8(n) != domain.LoaiChi) {
			response.ValidationError(c, map[string]string{
				"type": "Loại chỉ nhận 0 (thu) hoặc 1 (chi)",
			})

			return
		}
		loai := uint8(n)
		f.Type = &loai
	}

	list, err := h.svc.List(c.Request.Context(), f)
	if err != nil {
		handleServiceError(c, err)

		return
	}
	response.OK(c, list)
}

// Get godoc
//
//	@Summary	Chi tiết một loại thu chi
//	@Tags		Admin - Loại thu chi
//	@Produce	json
//	@Security	BearerAuth
//	@Param		id	path		int	true	"ID loại thu chi"
//	@Success	200	{object}	response.Body{data=domain.LoaiThuChi}
//	@Failure	404	{object}	response.Body
//	@Router		/admin/loai-thu-chi/{id} [get]
func (h *LoaiThuChiHandler) Get(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}

	l, err := h.svc.GetByID(c.Request.Context(), id)
	if err != nil {
		handleServiceError(c, err)

		return
	}
	response.OK(c, l)
}

// Create godoc
//
//	@Summary		Thêm loại thu chi
//	@Description	Tên phải khác mọi tên đã có TRONG CÙNG VẾ thu hoặc chi của cửa hàng này. "Tiền điện" bên thu và "Tiền điện" bên chi là hai loại khác nhau nên đều khai được.
//	@Tags			Admin - Loại thu chi
//	@Accept			json
//	@Produce		json
//	@Security		BearerAuth
//	@Param			body	body		dto.LoaiThuChiRequest	true	"Thông tin loại thu chi"
//	@Success		201		{object}	response.Body{data=domain.LoaiThuChi}
//	@Failure		401		{object}	response.Body
//	@Failure		422		{object}	response.Body
//	@Router			/admin/loai-thu-chi [post]
func (h *LoaiThuChiHandler) Create(c *gin.Context) {
	var req dto.LoaiThuChiRequest
	if !bindJSON(c, &req) {
		return
	}

	l, err := h.svc.Create(c.Request.Context(), &req)
	if err != nil {
		handleServiceError(c, err)

		return
	}
	response.Created(c, l)
}

// Update godoc
//
//	@Summary		Đổi tên loại thu chi
//	@Description	CHỈ đổi tên. Không chuyển được một loại từ vế thu sang vế chi: phiếu đã lập đang trỏ vào dòng này, đổi vế là mọi phiếu cũ nhảy sang bên kia trong mọi bảng cộng dồn. Muốn đổi vế thì khai một loại mới.
//	@Description	Loại mặc định của hệ thống không sửa được.
//	@Tags			Admin - Loại thu chi
//	@Accept			json
//	@Produce		json
//	@Security		BearerAuth
//	@Param			id		path		int							true	"ID loại thu chi"
//	@Param			body	body		dto.SuaLoaiThuChiRequest	true	"Tên mới"
//	@Success		200		{object}	response.Body{data=domain.LoaiThuChi}
//	@Failure		404		{object}	response.Body
//	@Failure		409		{object}	response.Body
//	@Failure		422		{object}	response.Body
//	@Router			/admin/loai-thu-chi/{id} [put]
func (h *LoaiThuChiHandler) Update(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}

	var req dto.SuaLoaiThuChiRequest
	if !bindJSON(c, &req) {
		return
	}

	l, err := h.svc.Update(c.Request.Context(), id, &req)
	if err != nil {
		handleServiceError(c, err)

		return
	}
	response.OKMessage(c, "Đã cập nhật loại thu chi", l)
}

// Delete godoc
//
//	@Summary		Xoá loại thu chi
//	@Description	Xoá mềm — phiếu thu chi lập trước đó vẫn tra ra được tên loại của nó. Loại mặc định của hệ thống không xoá được.
//	@Tags			Admin - Loại thu chi
//	@Produce		json
//	@Security		BearerAuth
//	@Param			id	path		int	true	"ID loại thu chi"
//	@Success		200	{object}	response.Body
//	@Failure		404	{object}	response.Body
//	@Failure		409	{object}	response.Body
//	@Router			/admin/loai-thu-chi/{id} [delete]
func (h *LoaiThuChiHandler) Delete(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}

	if err := h.svc.Delete(c.Request.Context(), id); err != nil {
		handleServiceError(c, err)

		return
	}
	response.OKMessage(c, "Đã xoá loại thu chi", nil)
}
