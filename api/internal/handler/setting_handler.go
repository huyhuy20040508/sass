package handler

import (
	"errors"

	"github.com/gin-gonic/gin"

	"sass-api/internal/dto"
	"sass-api/internal/service"
	"sass-api/pkg/response"
)

type SettingHandler struct {
	svc service.SettingService
}

func NewSettingHandler(svc service.SettingService) *SettingHandler {
	return &SettingHandler{svc: svc}
}

// List godoc
//
//	@Summary		Danh sách cấu hình hệ thống
//	@Description	Trả về `values` (map phẳng key → value) và `fields` (siêu dữ liệu từng
//	@Description	khoá: nhóm, kiểu, nhãn, mặc định) để form admin dựng ô nhập đúng kiểu.
//	@Description	Khoá chưa có dòng trong database vẫn xuất hiện, mang giá trị mặc định.
//	@Tags			Admin - Settings
//	@Produce		json
//	@Security		BearerAuth
//	@Param			group	query		string	false	"Lọc theo nhóm: general | shipping | payment | inventory. Bỏ trống = lấy hết"
//	@Success		200		{object}	response.Body{data=dto.SettingsResponse}
//	@Failure		401		{object}	response.Body
//	@Failure		403		{object}	response.Body
//	@Failure		422		{object}	response.Body
//	@Router			/admin/settings [get]
func (h *SettingHandler) List(c *gin.Context) {
	res, err := h.svc.List(c.Request.Context(), c.Query("group"))
	if err != nil {
		handleSettingError(c, err)
		return
	}
	response.OK(c, res)
}

// Update godoc
//
//	@Summary		Cập nhật cấu hình hệ thống
//	@Description	Ghi nhiều khoá trong một lần gọi; khoá không gửi lên giữ nguyên giá trị cũ.
//	@Description	Khoá lạ hoặc giá trị sai kiểu làm cả yêu cầu bị từ chối (422) — không ghi
//	@Description	xuống database một phần. Trả về toàn bộ cấu hình sau khi ghi.
//	@Description	Nhóm thanh toán còn có luật chéo: phải bật ít nhất một hình thức, và bật
//	@Description	chuyển khoản thì phải khai đủ ngân hàng / số tài khoản / chủ tài khoản.
//	@Tags			Admin - Settings
//	@Accept			json
//	@Produce		json
//	@Security		BearerAuth
//	@Param			body	body		dto.SettingsUpdateRequest	true	"Các khoá cần ghi"
//	@Success		200		{object}	response.Body{data=dto.SettingsResponse}
//	@Failure		400		{object}	response.Body
//	@Failure		401		{object}	response.Body
//	@Failure		403		{object}	response.Body
//	@Failure		422		{object}	response.Body
//	@Router			/admin/settings [put]
func (h *SettingHandler) Update(c *gin.Context) {
	var req dto.SettingsUpdateRequest
	if !bindJSON(c, &req) {
		return
	}
	res, err := h.svc.Update(c.Request.Context(), req.Items)
	if err != nil {
		handleSettingError(c, err)
		return
	}
	response.OKMessage(c, "Đã lưu cấu hình", res)
}

// Public godoc
//
//	@Summary		Cấu hình công khai
//	@Description	Chỉ các khoá được đánh dấu công khai (tên cửa hàng, liên hệ, phí vận
//	@Description	chuyển) — storefront đọc để không phải chép lại cấu hình của server.
//	@Description	Đọc từ snapshot trong bộ nhớ nên không tốn truy vấn database.
//	@Tags			Settings
//	@Produce		json
//	@Success		200	{object}	response.Body{data=map[string]string}
//	@Router			/settings [get]
func (h *SettingHandler) Public(c *gin.Context) {
	response.OK(c, h.svc.Public(c.Request.Context()))
}

// handleSettingError trả 422 kèm lỗi từng khoá khi payload không hợp lệ, còn lại
// nhường cho bộ ánh xạ lỗi dùng chung.
func handleSettingError(c *gin.Context, err error) {
	var ve *service.SettingValidationError
	if errors.As(err, &ve) {
		response.ValidationError(c, ve.Fields)
		return
	}
	handleServiceError(c, err)
}
