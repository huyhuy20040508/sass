package handler

import (
	"github.com/gin-gonic/gin"

	"sass-api/internal/domain"
	"sass-api/internal/dto"
	"sass-api/internal/service"
	"sass-api/pkg/response"
)

type VoucherHandler struct {
	svc service.VoucherService
}

func NewVoucherHandler(svc service.VoucherService) *VoucherHandler {
	return &VoucherHandler{svc: svc}
}

// Check godoc
//
//	@Summary		Kiểm mã giảm giá (storefront)
//	@Description	Khách gõ mã ở giỏ hàng để xem trước số tiền được giảm. Không tiêu lượt của mã — lượt chỉ bị tiêu khi đơn được tạo thật.
//	@Description	Gửi kèm access token thì hạn mức "mỗi khách bao nhiêu lượt" được kiểm luôn; khách vãng lai không kiểm được phần đó.
//	@Description	Con số trả về chỉ để hiển thị: lúc đặt hàng API tính lại trên giá tại thời điểm bấm đặt.
//	@Tags			Storefront - Vouchers
//	@Accept			json
//	@Produce		json
//	@Param			body	body		dto.VoucherCheckRequest	true	"Mã và tiền hàng hiện tại"
//	@Success		200		{object}	response.Body{data=dto.VoucherCheckResponse}
//	@Failure		422		{object}	response.Body	"Mã không tồn tại, hết hạn, hết lượt, hoặc đơn chưa đạt tối thiểu"
//	@Router			/vouchers/check [post]
func (h *VoucherHandler) Check(c *gin.Context) {
	var req dto.VoucherCheckRequest
	if !bindJSON(c, &req) {
		return
	}

	// currentUserID trả 0 khi khách chưa đăng nhập — OptionalJWTAuth cho qua cả hai
	// trường hợp, và tầng service tự bỏ qua hạn mức theo khách khi không biết là ai.
	v, discount, err := h.svc.Check(c.Request.Context(), req.Code, req.Subtotal, currentUserID(c), req.Phone)
	if err != nil {
		if !voucherUseError(c, err) {
			handleServiceError(c, err)
		}
		return
	}

	response.OK(c, dto.VoucherCheckResponse{
		Code:        v.Code,
		Description: v.Description,
		Discount:    discount,
		Subtotal:    req.Subtotal,
		Total:       req.Subtotal - discount,
	})
}

// Available godoc
//
//	@Summary		Mã giảm giá gợi ý cho giỏ hàng (storefront)
//	@Description	Danh sách mã ĐẠI TRÀ (`is_public = true`) còn hiệu lực, để hiện thẳng ở ô nhập mã cho khách bấm chọn thay vì phải nhớ và gõ tay.
//	@Description	Mã gửi tay cho riêng một khách (`is_public = false`) KHÔNG bao giờ xuất hiện ở đây — chỉ ai biết mã mới gõ được.
//	@Description	Mã chưa đạt đơn tối thiểu vẫn trả về, kèm `missing_amount` để giao diện nói rõ còn thiếu bao nhiêu. Mã khách đã dùng hết lượt riêng thì bị loại.
//	@Tags			Storefront - Vouchers
//	@Accept			json
//	@Produce		json
//	@Param			body	body		dto.VoucherAvailableRequest	true	"Tiền hàng của giỏ hiện tại"
//	@Success		200		{object}	response.Body{data=[]dto.VoucherAvailableItem}
//	@Router			/vouchers/available [post]
func (h *VoucherHandler) Available(c *gin.Context) {
	var req dto.VoucherAvailableRequest
	if !bindJSON(c, &req) {
		return
	}

	items, err := h.svc.Available(c.Request.Context(), req.Subtotal, currentUserID(c), req.Phone)
	if err != nil {
		handleServiceError(c, err)
		return
	}
	response.OK(c, items)
}

// List godoc
//
//	@Summary		Danh sách voucher
//	@Description	Mã giảm giá khách tự nhập lúc thanh toán, giảm trên TỔNG ĐƠN (khác `promotions` — giảm tự động trên từng sản phẩm).
//	@Description	`status` của mỗi dòng được suy ra từ ngày hiệu lực + lượt đã dùng + trạng thái bật tắt: running | scheduled | ended | used_up | paused.
//	@Tags			Admin - Vouchers
//	@Produce		json
//	@Security		BearerAuth
//	@Param			keyword		query		string	false	"Tìm theo mã hoặc mô tả"
//	@Param			status		query		string	false	"all | running | scheduled | ended | used_up | paused"
//	@Param			type		query		string	false	"percentage | fixed"
//	@Param			from_date	query		string	false	"Từ ngày (YYYY-MM-DD) — mã có hiệu lực trong khoảng"
//	@Param			to_date		query		string	false	"Đến ngày (YYYY-MM-DD)"
//	@Param			sort		query		string	false	"newest|oldest|code_asc|used_desc|end_asc"
//	@Param			page		query		int		false	"Trang (mặc định 1)"
//	@Param			page_size	query		int		false	"Số item/trang (mặc định 20, tối đa 100)"
//	@Success		200			{object}	response.Body{data=[]dto.VoucherResponse}
//	@Failure		401			{object}	response.Body
//	@Router			/admin/vouchers [get]
func (h *VoucherHandler) List(c *gin.Context) {
	f := domain.VoucherFilter{
		Keyword:  c.Query("keyword"),
		Status:   c.Query("status"),
		Type:     c.Query("type"),
		FromDate: c.Query("from_date"),
		ToDate:   c.Query("to_date"),
		Sort:     c.Query("sort"),
		Page:     queryInt(c, "page", 1),
		PageSize: queryInt(c, "page_size", 20),
	}
	if f.Page < 1 {
		f.Page = 1
	}
	if f.PageSize < 1 || f.PageSize > 100 {
		f.PageSize = 20
	}

	items, total, err := h.svc.List(c.Request.Context(), f)
	if err != nil {
		handleServiceError(c, err)
		return
	}

	totalPages := int((total + int64(f.PageSize) - 1) / int64(f.PageSize))
	response.Paginated(c, items, response.Pagination{
		Page:       f.Page,
		PageSize:   f.PageSize,
		Total:      total,
		TotalPages: totalPages,
	})
}

// Stats godoc
//
//	@Summary		Số đếm voucher theo trạng thái
//	@Description	Dùng cho dải thẻ tóm tắt trên đầu trang. Không phụ thuộc bộ lọc đang chọn.
//	@Tags			Admin - Vouchers
//	@Produce		json
//	@Security		BearerAuth
//	@Success		200	{object}	response.Body{data=domain.VoucherStats}
//	@Failure		401	{object}	response.Body
//	@Router			/admin/vouchers/stats [get]
func (h *VoucherHandler) Stats(c *gin.Context) {
	s, err := h.svc.Stats(c.Request.Context())
	if err != nil {
		handleServiceError(c, err)
		return
	}
	response.OK(c, s)
}

// Get godoc
//
//	@Summary		Chi tiết một voucher
//	@Tags			Admin - Vouchers
//	@Produce		json
//	@Security		BearerAuth
//	@Param			id	path		int	true	"ID voucher"
//	@Success		200	{object}	response.Body{data=dto.VoucherResponse}
//	@Failure		401	{object}	response.Body
//	@Failure		404	{object}	response.Body
//	@Router			/admin/vouchers/{id} [get]
func (h *VoucherHandler) Get(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}
	v, err := h.svc.Get(c.Request.Context(), id)
	if err != nil {
		handleServiceError(c, err)
		return
	}
	response.OK(c, v)
}

// Create godoc
//
//	@Summary		Tạo voucher
//	@Description	Mã được chuẩn hoá về CHỮ HOA trước khi lưu — khách gõ "sale10" vẫn trúng mã "SALE10".
//	@Description	`start_at` / `end_at` để trống là không giới hạn phía đó: mã dùng được ngay, hoặc dùng mãi tới khi bị tắt.
//	@Tags			Admin - Vouchers
//	@Accept			json
//	@Produce		json
//	@Security		BearerAuth
//	@Param			body	body		dto.VoucherRequest	true	"Dữ liệu voucher"
//	@Success		201		{object}	response.Body{data=dto.VoucherResponse}
//	@Failure		401		{object}	response.Body
//	@Failure		409		{object}	response.Body	"Mã đã tồn tại"
//	@Failure		422		{object}	response.Body	"Mã sai định dạng, sai thời gian hiệu lực, hoặc sai % giảm"
//	@Router			/admin/vouchers [post]
func (h *VoucherHandler) Create(c *gin.Context) {
	var req dto.VoucherRequest
	if !bindJSON(c, &req) {
		return
	}
	v, err := h.svc.Create(c.Request.Context(), req)
	if err != nil {
		handleServiceError(c, err)
		return
	}
	response.Created(c, v)
}

// Update godoc
//
//	@Summary		Cập nhật voucher
//	@Description	Số lượt ĐÃ dùng (`used_count`) không sửa được ở đây — nó do đơn hàng ghi ra.
//	@Description	Cũng không hạ được tổng lượt xuống dưới số lượt đã phát: mã sẽ chết ngay lúc lưu.
//	@Tags			Admin - Vouchers
//	@Accept			json
//	@Produce		json
//	@Security		BearerAuth
//	@Param			id		path		int					true	"ID voucher"
//	@Param			body	body		dto.VoucherRequest	true	"Dữ liệu voucher"
//	@Success		200		{object}	response.Body{data=dto.VoucherResponse}
//	@Failure		401		{object}	response.Body
//	@Failure		404		{object}	response.Body
//	@Failure		409		{object}	response.Body	"Mã đã thuộc về voucher khác"
//	@Failure		422		{object}	response.Body
//	@Router			/admin/vouchers/{id} [put]
func (h *VoucherHandler) Update(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}
	var req dto.VoucherRequest
	if !bindJSON(c, &req) {
		return
	}
	v, err := h.svc.Update(c.Request.Context(), id, req)
	if err != nil {
		handleServiceError(c, err)
		return
	}
	response.OKMessage(c, "Đã cập nhật voucher", v)
}

// UpdateStatus godoc
//
//	@Summary		Bật / tắt voucher
//	@Description	Ngừng phát một mã giữa chừng mà không phải sửa ngày kết thúc.
//	@Tags			Admin - Vouchers
//	@Accept			json
//	@Produce		json
//	@Security		BearerAuth
//	@Param			id		path		int							true	"ID voucher"
//	@Param			body	body		dto.VoucherStatusRequest	true	"Trạng thái"
//	@Success		200		{object}	response.Body
//	@Failure		401		{object}	response.Body
//	@Failure		404		{object}	response.Body
//	@Router			/admin/vouchers/{id}/status [put]
func (h *VoucherHandler) UpdateStatus(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}
	var req dto.VoucherStatusRequest
	if !bindJSON(c, &req) {
		return
	}
	if err := h.svc.SetActive(c.Request.Context(), id, *req.IsActive); err != nil {
		handleServiceError(c, err)
		return
	}
	msg := "Đã tạm dừng voucher"
	if *req.IsActive {
		msg = "Đã bật voucher"
	}
	response.OKMessage(c, msg, nil)
}

// Delete godoc
//
//	@Summary		Xoá voucher
//	@Description	Xoá mềm. Đơn cũ đã dùng mã vẫn giữ nguyên liên kết và mã đã lưu trong đơn.
//	@Tags			Admin - Vouchers
//	@Produce		json
//	@Security		BearerAuth
//	@Param			id	path		int	true	"ID voucher"
//	@Success		200	{object}	response.Body
//	@Failure		401	{object}	response.Body
//	@Failure		404	{object}	response.Body
//	@Router			/admin/vouchers/{id} [delete]
func (h *VoucherHandler) Delete(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}
	if err := h.svc.Delete(c.Request.Context(), id); err != nil {
		handleServiceError(c, err)
		return
	}
	response.OKMessage(c, "Đã xoá voucher", nil)
}
