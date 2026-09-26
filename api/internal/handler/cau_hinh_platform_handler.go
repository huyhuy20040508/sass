package handler

import (
	"github.com/gin-gonic/gin"

	"sass-api/internal/domain"
	"sass-api/internal/dto"
	"sass-api/internal/middleware"
	"sass-api/internal/service"
	"sass-api/pkg/response"
)

// CauHinhNenTangHandler phục vụ màn hình Cài đặt của KHU ĐIỀU HÀNH.
//
// Đây là cấu hình của NHÀ CUNG CẤP, không của cửa hàng nào — đừng nhầm với
// SettingHandler (cấu hình từng cửa hàng, nhóm /admin). Hai màn hình giống nhau
// về hình dạng và khác nhau hoàn toàn về chủ sở hữu dữ liệu.
//
// Mọi đường nằm sau middleware.XacThucNenTang. Riêng đường GHI còn xét vai trò:
// `support` là vai trò chỉ đọc, và số tài khoản nhận tiền của cả nền tảng đúng
// là thứ người trực hỗ trợ không nên sửa được giữa lúc đang nghe điện thoại.
type CauHinhNenTangHandler struct {
	svc service.CauHinhNenTangService
}

func NewCauHinhNenTangHandler(svc service.CauHinhNenTangService) *CauHinhNenTangHandler {
	return &CauHinhNenTangHandler{svc: svc}
}

// Doc godoc
//
//	@Summary		Cấu hình của nhà cung cấp
//	@Description	Trả về `values` (map khoá → giá trị, đã ghép mặc định của registry) và
//	@Description	`fields` (siêu dữ liệu từng ô: kiểu, nhãn, gợi ý, bắt buộc hay không) để
//	@Description	màn hình dựng form mà không chép lại bảng khoá.
//	@Description	Hôm nay gồm bộ thông tin NHẬN CHUYỂN KHOẢN dùng cho việc khách gia hạn.
//	@Tags			Platform - Cấu hình
//	@Produce		json
//	@Security		BearerAuth
//	@Success		200	{object}	response.Body{data=dto.CauHinhNenTangResponse}
//	@Failure		401	{object}	response.Body
//	@Failure		403	{object}	response.Body
//	@Router			/platform/cau-hinh [get]
func (h *CauHinhNenTangHandler) Doc(c *gin.Context) {
	res, err := h.svc.Doc(c.Request.Context())
	if err != nil {
		handlePlanError(c, err)
		return
	}
	response.OK(c, res)
}

// Ghi godoc
//
//	@Summary		Sửa cấu hình của nhà cung cấp
//	@Description	Ghi nhiều khoá trong một lần gọi; khoá không gửi lên giữ nguyên. Khoá lạ
//	@Description	làm cả yêu cầu bị từ chối (422), không ghi xuống một phần.
//	@Description	BẬT nhận chuyển khoản thì tên ngân hàng, số tài khoản, chủ tài khoản và
//	@Description	mẫu nội dung đều bắt buộc — và mẫu nội dung phải chứa {ma_cua_hang}, thứ
//	@Description	duy nhất nói tiền vừa vào là của khách nào.
//	@Description	CHỈ vai trò owner/operator ghi được; support chỉ đọc.
//	@Tags			Platform - Cấu hình
//	@Accept			json
//	@Produce		json
//	@Security		BearerAuth
//	@Param			body	body		dto.CauHinhNenTangUpdateRequest	true	"Các khoá cần ghi"
//	@Success		200		{object}	response.Body{data=dto.CauHinhNenTangResponse}
//	@Failure		400		{object}	response.Body
//	@Failure		401		{object}	response.Body
//	@Failure		403		{object}	response.Body
//	@Failure		422		{object}	response.Body
//	@Router			/platform/cau-hinh [put]
func (h *CauHinhNenTangHandler) Ghi(c *gin.Context) {
	// Vai trò xét ở handler chứ không thêm middleware cho cả nhóm: nhóm này có cả
	// đường đọc lẫn đường ghi, và `support` phải đi qua được đường đọc — họ cần
	// đọc số tài khoản để trả lời khách đang gọi.
	if !domain.PlatformRoleGhiDuoc(middleware.PlatformRole(c)) {
		response.Error(c, 403, "Vai trò của bạn trong khu điều hành chỉ được xem, không sửa được cấu hình")
		return
	}

	var req dto.CauHinhNenTangUpdateRequest
	if !bindJSON(c, &req) {
		return
	}

	res, err := h.svc.Ghi(c.Request.Context(), req.Items)
	if err != nil {
		handlePlanError(c, err)
		return
	}
	response.OKMessage(c, "Đã lưu cấu hình", res)
}
