package handler

import (
	"errors"

	"github.com/gin-gonic/gin"

	"sass-api/internal/domain"
	"sass-api/internal/dto"
	"sass-api/internal/middleware"
	"sass-api/internal/service"
	"sass-api/pkg/response"
)

// PlanHandler phục vụ KHU ĐIỀU HÀNH NỀN TẢNG, không phải khu quản trị cửa hàng.
//
// Mọi đường của nó nằm sau middleware.XacThucNenTang — nơi trả lời câu "người này
// có trong sổ người điều hành không". Bỏ middleware đó ra thì cả nhóm này thành
// đường cho chủ bất kỳ cửa hàng nào sửa bảng giá của nền tảng; xem chú thích ở
// chính middleware.
type PlanHandler struct {
	svc service.PlanService
}

func NewPlanHandler(svc service.PlanService) *PlanHandler {
	return &PlanHandler{svc: svc}
}

// Apps godoc
//
//	@Summary		Danh mục phần mềm của nền tảng
//	@Description	Chỉ những phần mềm NGƯỜI GỌI được phụ trách (owner thấy hết). Bộ chọn
//	@Description	phần mềm ở đầu khu điều hành dựng từ đây.
//	@Description	`so_goi_dang_ban` tách khỏi `status`: app đang chạy mà 0 gói đang bán thì
//	@Description	vẫn chưa ai mua được. App 'planned' và 'retired' vẫn xuất hiện — đây là
//	@Description	màn hình quản lý vòng đời của chúng.
//	@Tags			Platform - Apps
//	@Produce		json
//	@Security		BearerAuth
//	@Success		200	{object}	response.Body{data=dto.AppsResponse}
//	@Failure		401	{object}	response.Body
//	@Failure		403	{object}	response.Body
//	@Router			/platform/apps [get]
func (h *PlanHandler) Apps(c *gin.Context) {
	res, err := h.svc.Apps(c.Request.Context(), middleware.PlatformApps(c))
	if err != nil {
		handlePlanError(c, err)
		return
	}
	response.OK(c, res)
}

// List godoc
//
//	@Summary		Bảng giá của nền tảng
//	@Description	Trả về `plans` (mỗi dòng là một mức giá: gói + app + chu kỳ, kèm `features`
//	@Description	là hạn mức/tính năng đã khai của gói đó) và `fields` (siêu dữ liệu từng khoá
//	@Description	tính năng: kiểu, nhãn, đơn vị, trần) để màn hình dựng ô nhập đúng kiểu.
//	@Description	Khoá KHÔNG có trong `features` nghĩa là bảng giá không quy định — khác hẳn
//	@Description	giá trị 0. Gói đã ngừng bán (`retired`) vẫn xuất hiện.
//	@Tags			Platform - Plans
//	@Produce		json
//	@Security		BearerAuth
//	@Param			app	query		string	false	"Lọc theo mã app, vd: order. Bỏ trống = mọi app"
//	@Success		200	{object}	response.Body{data=dto.PlansResponse}
//	@Failure		401	{object}	response.Body
//	@Failure		403	{object}	response.Body
//	@Failure		503	{object}	response.Body
//	@Router			/platform/plans [get]
func (h *PlanHandler) List(c *gin.Context) {
	res, err := h.svc.List(c.Request.Context(), middleware.PlatformApps(c), c.Query("app"))
	if err != nil {
		handlePlanError(c, err)
		return
	}
	response.OK(c, res)
}

// Features godoc
//
//	@Summary		Tính năng của một gói
//	@Description	Một dòng bảng giá kèm hạn mức/tính năng đã khai của nó. Đây là dữ liệu của
//	@Description	màn hình Tính năng gói.
//	@Tags			Platform - Plans
//	@Produce		json
//	@Security		BearerAuth
//	@Param			id	path		int	true	"ID dòng bảng giá"
//	@Success		200	{object}	response.Body{data=dto.PlanFeaturesResponse}
//	@Failure		401	{object}	response.Body
//	@Failure		403	{object}	response.Body
//	@Failure		404	{object}	response.Body
//	@Router			/platform/plans/{id}/features [get]
func (h *PlanHandler) Features(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}
	res, err := h.svc.Features(c.Request.Context(), middleware.PlatformApps(c), id)
	if err != nil {
		handlePlanError(c, err)
		return
	}
	response.OK(c, res)
}

// UpdateFeatures godoc
//
//	@Summary		Sửa tính năng của một gói
//	@Description	Ghi nhiều khoá trong một lần gọi; khoá không gửi lên giữ nguyên. Gửi GIÁ TRỊ
//	@Description	RỖNG là XOÁ khoá đó, nghĩa là "bảng giá không quy định hạn mức này" — khác
//	@Description	hẳn gửi "0". Hạn mức không giới hạn thì gửi `vo_han`.
//	@Description	Khoá lạ hoặc giá trị sai kiểu làm cả yêu cầu bị từ chối (422), không ghi
//	@Description	xuống một phần. Trả về gói sau khi ghi.
//	@Description	CHỈ vai trò owner/operator của khu điều hành ghi được; support chỉ đọc.
//	@Description	Sửa ở đây KHÔNG đụng tới khách đang dùng: thuê bao đã ký chép hạn mức ra
//	@Description	lúc ký và sống độc lập với bảng giá.
//	@Tags			Platform - Plans
//	@Accept			json
//	@Produce		json
//	@Security		BearerAuth
//	@Param			id		path		int								true	"ID dòng bảng giá"
//	@Param			body	body		dto.PlanFeaturesUpdateRequest	true	"Các khoá cần ghi"
//	@Success		200		{object}	response.Body{data=dto.PlanFeaturesResponse}
//	@Failure		400		{object}	response.Body
//	@Failure		401		{object}	response.Body
//	@Failure		403		{object}	response.Body
//	@Failure		404		{object}	response.Body
//	@Failure		422		{object}	response.Body
//	@Router			/platform/plans/{id}/features [put]
func (h *PlanHandler) UpdateFeatures(c *gin.Context) {
	// Vai trò xét ở handler chứ không thêm một middleware nữa: nhóm này có cả
	// đường đọc lẫn đường ghi, và `support` phải đi qua được đường đọc. Một
	// middleware chặn cả nhóm sẽ đóng luôn phần người trực hỗ trợ cần.
	if !domain.PlatformRoleGhiDuoc(middleware.PlatformRole(c)) {
		response.Error(c, 403, "Vai trò của bạn trong khu điều hành chỉ được xem, không sửa được bảng giá")
		return
	}

	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}
	var req dto.PlanFeaturesUpdateRequest
	if !bindJSON(c, &req) {
		return
	}

	res, err := h.svc.UpdateFeatures(c.Request.Context(), middleware.PlatformApps(c), id, req.Items)
	if err != nil {
		handlePlanError(c, err)
		return
	}
	response.OKMessage(c, "Đã lưu tính năng gói", res)
}

// handlePlanError trả 422 kèm lỗi từng khoá khi payload không hợp lệ, còn lại
// nhường cho bộ ánh xạ lỗi dùng chung.
func handlePlanError(c *gin.Context, err error) {
	var ve *service.PlanFeatureValidationError
	if errors.As(err, &ve) {
		response.ValidationError(c, ve.Fields)
		return
	}
	handleServiceError(c, err)
}
