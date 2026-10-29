package handler

import (
	"strings"

	"github.com/gin-gonic/gin"

	"sass-api/internal/dto"
	"sass-api/internal/service"
	"sass-api/pkg/response"
)

// WebKhachHandler — khách của WEB BÁN HÀNG đăng ký tài khoản.
//
// Web bán hàng giữ phần đăng nhập (mật khẩu, phiên) ở database của nó; POS chỉ
// giữ HỒ SƠ KHÁCH. Đường này là cầu nối: đăng ký xong trên web thì ở POS có
// ngay một khách hàng, bán tại quầy tra ra đúng người ấy.
type WebKhachHandler struct {
	svc service.CustomerService
}

func NewWebKhachHandler(svc service.CustomerService) *WebKhachHandler {
	return &WebKhachHandler{svc: svc}
}

// DangKy godoc
//
//	@Summary		Khách web đăng ký — tạo hồ sơ khách trong POS
//	@Description	Số điện thoại là danh tính: đã có khách mang số này thì TRẢ VỀ hồ sơ cũ (`moi=false`), không tạo thêm bản ghi thứ hai. Khách quen mua tại quầy rồi mới mở tài khoản web vẫn là một người.
//	@Tags			Web bán hàng
//	@Accept			json
//	@Produce		json
//	@Param			body	body		dto.WebKhachRequest	true	"Hồ sơ khách"
//	@Success		200		{object}	response.Body{data=dto.WebKhachResponse}
//	@Failure		401		{object}	response.Body	"thiếu khoá tích hợp"
//	@Failure		422		{object}	response.Body
//	@Router			/web-ban/khach [post]
func (h *WebKhachHandler) DangKy(c *gin.Context) {
	var req dto.WebKhachRequest
	if !bindJSON(c, &req) {
		return
	}

	phone := strings.TrimSpace(req.Phone)
	cu, err := h.svc.TimTheoSoDienThoai(c.Request.Context(), phone)
	if err != nil {
		handleServiceError(c, err)

		return
	}
	if cu != nil {
		response.OK(c, dto.WebKhachResponse{ID: cu.ID, FullName: cu.FullName, Phone: cu.Phone, Email: cu.Email, Moi: false})

		return
	}

	// Hồ sơ khách tạo từ web: chỉ ba ô người ta vừa khai. Phần còn lại (nhóm
	// khách, hạn mức nợ, địa chỉ) để quầy bổ sung khi bán — ép khai ở màn đăng ký
	// là bắt khách gõ thứ họ chưa cần.
	moi, err := h.svc.Create(c.Request.Context(), &dto.CustomerRequest{
		FullName: strings.TrimSpace(req.FullName),
		Phone:    phone,
		Email:    strings.TrimSpace(req.Email),
		Status:   "active",
	})
	if err != nil {
		handleServiceError(c, err)

		return
	}
	response.CreatedMessage(c, "Đã tạo hồ sơ khách", dto.WebKhachResponse{
		ID: moi.ID, FullName: moi.FullName, Phone: moi.Phone, Email: moi.Email, Moi: true,
	})
}
