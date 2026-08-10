package handler

import (
	"github.com/gin-gonic/gin"

	"sass-api/internal/domain"
	"sass-api/internal/dto"
	"sass-api/internal/service"
	"sass-api/pkg/response"
)

type BannerHandler struct {
	svc service.BannerService
}

func NewBannerHandler(svc service.BannerService) *BannerHandler {
	return &BannerHandler{svc: svc}
}

// Public godoc
//
//	@Summary		Banner đang hiển thị (storefront)
//	@Description	Chỉ trả banner ĐANG bật và NẰM TRONG lịch chạy, sắp sẵn theo thứ tự hiển thị.
//	@Description	Bỏ trống `position` để lấy mọi vị trí một lượt (trang chủ dựng cả 3 khối bằng một lần gọi).
//	@Tags			Banners
//	@Produce		json
//	@Param			position	query		string	false	"Vị trí: home_slider, home_poster, home_kids"
//	@Success		200			{object}	response.Body
//	@Router			/banners [get]
func (h *BannerHandler) Public(c *gin.Context) {
	banners, err := h.svc.Live(c.Request.Context(), c.Query("position"))
	if err != nil {
		handleServiceError(c, err)
		return
	}
	response.OK(c, banners)
}

// Positions godoc
//
//	@Summary		Danh sách vị trí banner hỗ trợ
//	@Description	Mã vị trí hợp lệ để tạo banner. Đây là danh sách ĐÓNG — mỗi mã ứng với một khối cố định trên trang chủ.
//	@Tags			Banners
//	@Produce		json
//	@Success		200	{object}	response.Body
//	@Router			/banners/positions [get]
func (h *BannerHandler) Positions(c *gin.Context) {
	response.OK(c, domain.BannerPositions)
}

// List godoc
//
//	@Summary		Danh sách banner (quản trị)
//	@Description	Trả TOÀN BỘ banner, kể cả banner đang tắt và banner hẹn lịch cho đợt sau.
//	@Tags			Admin - Banners
//	@Produce		json
//	@Security		BearerAuth
//	@Param			position	query		string	false	"Lọc theo vị trí: home_slider, home_poster, home_kids"
//	@Success		200			{object}	response.Body
//	@Failure		401			{object}	response.Body
//	@Failure		403			{object}	response.Body
//	@Failure		422			{object}	response.Body
//	@Router			/admin/banners [get]
func (h *BannerHandler) List(c *gin.Context) {
	banners, err := h.svc.List(c.Request.Context(), c.Query("position"))
	if err != nil {
		handleServiceError(c, err)
		return
	}
	response.OK(c, banners)
}

// Get godoc
//
//	@Summary	Chi tiết banner
//	@Tags		Admin - Banners
//	@Produce	json
//	@Security	BearerAuth
//	@Param		id	path		int	true	"ID banner"
//	@Success	200	{object}	response.Body
//	@Failure	401	{object}	response.Body
//	@Failure	403	{object}	response.Body
//	@Failure	404	{object}	response.Body
//	@Router		/admin/banners/{id} [get]
func (h *BannerHandler) Get(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}
	b, err := h.svc.Get(c.Request.Context(), id)
	if err != nil {
		handleServiceError(c, err)
		return
	}
	response.OK(c, b)
}

// Create godoc
//
//	@Summary		Tạo banner
//	@Description	Bỏ trống `sort_order` thì banner mới được xếp xuống CUỐI vị trí đó.
//	@Tags			Admin - Banners
//	@Accept			json
//	@Produce		json
//	@Security		BearerAuth
//	@Param			body	body		dto.BannerRequest	true	"Dữ liệu banner"
//	@Success		201		{object}	response.Body
//	@Failure		400		{object}	response.Body
//	@Failure		401		{object}	response.Body
//	@Failure		403		{object}	response.Body
//	@Failure		422		{object}	response.Body
//	@Router			/admin/banners [post]
func (h *BannerHandler) Create(c *gin.Context) {
	var req dto.BannerRequest
	if !bindJSON(c, &req) {
		return
	}
	b, err := h.svc.Create(c.Request.Context(), req)
	if err != nil {
		handleServiceError(c, err)
		return
	}
	response.Created(c, b)
}

// Update godoc
//
//	@Summary		Cập nhật banner
//	@Description	Bỏ trống `sort_order` khi ĐỔI vị trí thì banner được xếp xuống cuối vị trí mới.
//	@Tags			Admin - Banners
//	@Accept			json
//	@Produce		json
//	@Security		BearerAuth
//	@Param			id		path		int					true	"ID banner"
//	@Param			body	body		dto.BannerRequest	true	"Dữ liệu banner"
//	@Success		200		{object}	response.Body
//	@Failure		400		{object}	response.Body
//	@Failure		401		{object}	response.Body
//	@Failure		403		{object}	response.Body
//	@Failure		404		{object}	response.Body
//	@Failure		422		{object}	response.Body
//	@Router			/admin/banners/{id} [put]
func (h *BannerHandler) Update(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}
	var req dto.BannerRequest
	if !bindJSON(c, &req) {
		return
	}
	b, err := h.svc.Update(c.Request.Context(), id, req)
	if err != nil {
		handleServiceError(c, err)
		return
	}
	response.OKMessage(c, "Cập nhật thành công", b)
}

// UpdateStatus godoc
//
//	@Summary		Bật/tắt hiển thị banner
//	@Description	Chỉ đổi công tắc hiển thị, KHÔNG đụng tới lịch chạy: banner bật lên mà chưa tới ngày bắt đầu thì vẫn chưa hiện.
//	@Tags			Admin - Banners
//	@Accept			json
//	@Produce		json
//	@Security		BearerAuth
//	@Param			id		path		int							true	"ID banner"
//	@Param			body	body		dto.BannerStatusRequest		true	"Trạng thái hiển thị"
//	@Success		200		{object}	response.Body
//	@Failure		400		{object}	response.Body
//	@Failure		401		{object}	response.Body
//	@Failure		403		{object}	response.Body
//	@Failure		404		{object}	response.Body
//	@Failure		422		{object}	response.Body
//	@Router			/admin/banners/{id}/status [put]
func (h *BannerHandler) UpdateStatus(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}
	var req dto.BannerStatusRequest
	if !bindJSON(c, &req) {
		return
	}
	b, err := h.svc.SetActive(c.Request.Context(), id, *req.IsActive)
	if err != nil {
		handleServiceError(c, err)
		return
	}
	msg := "Đã ẩn banner khỏi cửa hàng"
	if *req.IsActive {
		msg = "Đã hiện banner trên cửa hàng"
	}
	response.OKMessage(c, msg, b)
}

// Sort godoc
//
//	@Summary		Sắp xếp lại banner
//	@Description	Thứ tự phần tử trong `ids` chính là thứ tự hiển thị. Gửi trọn danh sách của MỘT vị trí.
//	@Tags			Admin - Banners
//	@Accept			json
//	@Produce		json
//	@Security		BearerAuth
//	@Param			body	body		dto.BannerSortRequest	true	"Danh sách id theo thứ tự mong muốn"
//	@Success		200		{object}	response.Body
//	@Failure		400		{object}	response.Body
//	@Failure		401		{object}	response.Body
//	@Failure		403		{object}	response.Body
//	@Failure		422		{object}	response.Body
//	@Router			/admin/banners/sort [put]
func (h *BannerHandler) Sort(c *gin.Context) {
	var req dto.BannerSortRequest
	if !bindJSON(c, &req) {
		return
	}
	if err := h.svc.Sort(c.Request.Context(), req.IDs); err != nil {
		handleServiceError(c, err)
		return
	}
	response.OKMessage(c, "Đã lưu thứ tự hiển thị", nil)
}

// Delete godoc
//
//	@Summary	Xóa banner
//	@Tags		Admin - Banners
//	@Produce	json
//	@Security	BearerAuth
//	@Param		id	path		int	true	"ID banner"
//	@Success	200	{object}	response.Body
//	@Failure	400	{object}	response.Body
//	@Failure	401	{object}	response.Body
//	@Failure	403	{object}	response.Body
//	@Failure	404	{object}	response.Body
//	@Router		/admin/banners/{id} [delete]
func (h *BannerHandler) Delete(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}
	if err := h.svc.Delete(c.Request.Context(), id); err != nil {
		handleServiceError(c, err)
		return
	}
	response.OKMessage(c, "Xóa thành công", nil)
}
