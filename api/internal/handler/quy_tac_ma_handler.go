package handler

import (
	"github.com/gin-gonic/gin"

	"sass-api/internal/dto"
	"sass-api/internal/service"
	"sass-api/pkg/response"
)

// QuyTacMaHandler — quy tắc đánh số chứng từ (Cài đặt → Thông số chung).
type QuyTacMaHandler struct {
	svc service.QuyTacMaService
}

func NewQuyTacMaHandler(svc service.QuyTacMaService) *QuyTacMaHandler {
	return &QuyTacMaHandler{svc: svc}
}

// List godoc
//
//	@Summary		Quy tắc đánh số chứng từ
//	@Description	Trả về danh mục loại đánh số được (khai trong mã nguồn) và MỌI quy tắc đã lưu của cửa hàng.
//	@Description	`shop_id = 0` là quy tắc dùng chung toàn cửa hàng (hàng hoá, nhà cung cấp, nhân viên).
//	@Tags			Admin - Quy tắc đánh số
//	@Produce		json
//	@Security		BearerAuth
//	@Success		200	{object}	response.Body{data=dto.QuyTacMaResponse}
//	@Failure		401	{object}	response.Body
//	@Failure		403	{object}	response.Body
//	@Router			/admin/quy-tac-ma [get]
func (h *QuyTacMaHandler) List(c *gin.Context) {
	data, err := h.svc.DanhSach(c.Request.Context())
	if err != nil {
		handleServiceError(c, err)

		return
	}
	response.OK(c, data)
}

// Update godoc
//
//	@Summary		Lưu quy tắc đánh số của một chi nhánh
//	@Description	Gửi lên TRẠNG THÁI CUỐI CÙNG: loại có trong `quy_tac` là bật, loại vắng mặt là tắt.
//	@Description	Lượt lưu chạm hai phạm vi — chi nhánh `shop_id` và phạm vi dùng chung.
//	@Tags			Admin - Quy tắc đánh số
//	@Accept			json
//	@Produce		json
//	@Security		BearerAuth
//	@Param			body	body		dto.LuuQuyTacMaRequest	true	"Bảng quy tắc"
//	@Success		200		{object}	response.Body
//	@Failure		401		{object}	response.Body
//	@Failure		403		{object}	response.Body
//	@Failure		404		{object}	response.Body
//	@Failure		422		{object}	response.Body
//	@Router			/admin/quy-tac-ma [put]
func (h *QuyTacMaHandler) Update(c *gin.Context) {
	var req dto.LuuQuyTacMaRequest
	if !bindJSON(c, &req) {
		return
	}
	if err := h.svc.Luu(c.Request.Context(), &req); err != nil {
		handleServiceError(c, err)

		return
	}
	response.OKMessage(c, "Đã lưu quy tắc đánh số chứng từ", nil)
}
