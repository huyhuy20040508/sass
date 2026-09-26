package handler

import (
	"github.com/gin-gonic/gin"

	"sass-api/internal/service"
	"sass-api/internal/tenant"
	"sass-api/pkg/response"
)

// GoiDichVuHandler phục vụ KHU QUẢN TRỊ CỬA HÀNG, không phải khu điều hành nền
// tảng — ngược hẳn với PlanHandler dù cả hai đọc chung bảng `plans`.
//
// Đường của nó nằm sau middleware.JWTAuth + RequireRoles(super_admin, admin):
// token của một cửa hàng, và chỉ hai vai trò quản lý. Nhân viên bán hàng không
// mở được, cùng lý do họ không mở được trang Khách hàng hay Cài đặt — đây là
// chuyện hợp đồng và tiền giữa chủ tiệm với nhà cung cấp phần mềm.
type GoiDichVuHandler struct {
	svc service.GoiDichVuService
}

func NewGoiDichVuHandler(svc service.GoiDichVuService) *GoiDichVuHandler {
	return &GoiDichVuHandler{svc: svc}
}

// CuaToi godoc
//
//	@Summary		Gói dịch vụ của cửa hàng đang đăng nhập
//	@Description	Hợp đồng hiện tại của CHÍNH cửa hàng này (gói, hạn mức đã ký, ngày hết
//	@Description	hạn, số ngày còn lại) kèm bảng giá đang bán của phần mềm để chủ tiệm biết
//	@Description	gia hạn hết bao nhiêu.
//	@Description	`hop_dong` = null nghĩa là cửa hàng chưa có hợp đồng nào trong sổ nền
//	@Description	tảng — trạng thái hợp lệ, không phải lỗi.
//	@Description	Hạn mức đọc THẲNG ở hợp đồng, không tra sang bảng giá: bảng giá đổi được,
//	@Description	hợp đồng đã ký thì không. Giá trị 0 nghĩa là không giới hạn.
//	@Tags			Admin - Gói dịch vụ
//	@Produce		json
//	@Security		BearerAuth
//	@Success		200	{object}	response.Body{data=dto.GoiDichVuCuaToiResponse}
//	@Failure		401	{object}	response.Body
//	@Failure		403	{object}	response.Body
//	@Router			/admin/goi-dich-vu [get]
func (h *GoiDichVuHandler) CuaToi(c *gin.Context) {
	// Cửa hàng đọc từ CONTEXT CỦA REQUEST, nơi middleware xác thực vừa đặt nó sau
	// khi kiểm chữ ký token — không nhận từ query hay body. Cho phép nói ra mình
	// là cửa hàng nào nghĩa là ai cũng đọc được hợp đồng của người khác.
	tenantID, ok := tenant.ID(c.Request.Context())
	if !ok {
		response.Error(c, 401, "Chưa xác định được cửa hàng cho yêu cầu này")
		return
	}

	res, err := h.svc.CuaToi(c.Request.Context(), tenantID)
	if err != nil {
		handleServiceError(c, err)
		return
	}
	response.OK(c, res)
}
