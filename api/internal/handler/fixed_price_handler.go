package handler

import (
	"net/http"
	"strconv"
	"strings"

	"github.com/gin-gonic/gin"

	"sass-api/internal/domain"
	"sass-api/internal/dto"
	"sass-api/internal/service"
	"sass-api/pkg/response"
)

// FixedPriceHandler — CRM → Khuyến mại đồng giá, và đường xem trước của quầy.
type FixedPriceHandler struct{ svc service.FixedPriceService }

func NewFixedPriceHandler(svc service.FixedPriceService) *FixedPriceHandler {
	return &FixedPriceHandler{svc: svc}
}

// List godoc
//
//	@Summary		Danh sách chương trình đồng giá
//	@Tags			Admin - Đồng giá
//	@Produce		json
//	@Security		BearerAuth
//	@Param			keyword		query		string	false	"Mã hoặc tên"
//	@Param			from_date	query		string	false	"YYYY-MM-DD — có hiệu lực ngày nào trong khoảng"
//	@Param			to_date		query		string	false	"YYYY-MM-DD"
//	@Param			shop_ids	query		string	false	"Id chi nhánh, ngăn bởi dấu phẩy"
//	@Param			codes		query		string	false	"Mã chương trình, ngăn bởi dấu phẩy"
//	@Param			page		query		int		false	"Trang"
//	@Param			page_size	query		int		false	"Số dòng/trang (tối đa 100)"
//	@Success		200			{object}	response.Body{data=[]dto.FixedPriceResponse}
//	@Router			/admin/dong-gia [get]
func (h *FixedPriceHandler) List(c *gin.Context) {
	f := domain.FixedPriceFilter{
		Keyword:  c.Query("keyword"),
		FromDate: c.Query("from_date"),
		ToDate:   c.Query("to_date"),
		Page:     queryInt(c, "page", 1),
		PageSize: queryInt(c, "page_size", 10),
	}
	if f.PageSize < 1 || f.PageSize > 100 {
		f.PageSize = 10
	}
	if f.Page < 1 {
		f.Page = 1
	}
	for _, v := range strings.Split(c.Query("shop_ids"), ",") {
		if n, err := strconv.ParseUint(strings.TrimSpace(v), 10, 64); err == nil && n > 0 {
			f.ShopIDs = append(f.ShopIDs, uint(n))
		}
	}
	for _, m := range strings.Split(c.Query("codes"), ",") {
		if m = strings.TrimSpace(m); m != "" {
			f.Codes = append(f.Codes, m)
		}
	}

	items, total, err := h.svc.List(c.Request.Context(), f)
	if err != nil {
		handleServiceError(c, err)

		return
	}
	response.Paginated(c, items, response.Pagination{
		Page: f.Page, PageSize: f.PageSize, Total: total,
		TotalPages: int((total + int64(f.PageSize) - 1) / int64(f.PageSize)),
	})
}

// Codes godoc
//
//	@Summary	Mọi mã chương trình đồng giá — ô lọc "Mã khuyến mãi"
//	@Tags		Admin - Đồng giá
//	@Security	BearerAuth
//	@Router		/admin/dong-gia/ma [get]
func (h *FixedPriceHandler) Codes(c *gin.Context) {
	ds, err := h.svc.Codes(c.Request.Context())
	if err != nil {
		handleServiceError(c, err)

		return
	}
	response.OK(c, ds)
}

// Get godoc
//
//	@Summary	Một chương trình đồng giá (kèm dòng và hàng tặng)
//	@Tags		Admin - Đồng giá
//	@Security	BearerAuth
//	@Param		id	path		int	true	"ID"
//	@Success	200	{object}	response.Body{data=dto.FixedPriceResponse}
//	@Router		/admin/dong-gia/{id} [get]
func (h *FixedPriceHandler) Get(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}
	res, err := h.svc.Get(c.Request.Context(), id)
	if err != nil {
		handleServiceError(c, err)

		return
	}
	response.OK(c, res)
}

// Create godoc
//
//	@Summary		Tạo chương trình đồng giá
//	@Description	`approved` = true là bấm "Duyệt", false là "Lưu tạm". Chương trình đang bật mà trùng món / nhóm hàng với chương trình khác đang bật cùng chi nhánh, ngày, thứ thì trả 422.
//	@Tags			Admin - Đồng giá
//	@Accept			json
//	@Security		BearerAuth
//	@Param			body	body		dto.FixedPriceRequest	true	"Chương trình"
//	@Success		201		{object}	response.Body{data=dto.FixedPriceResponse}
//	@Router			/admin/dong-gia [post]
func (h *FixedPriceHandler) Create(c *gin.Context) {
	var req dto.FixedPriceRequest
	if !bindJSON(c, &req) {
		return
	}
	res, err := h.svc.Save(c.Request.Context(), 0, req)
	if err != nil {
		handleServiceError(c, err)

		return
	}
	response.Created(c, res)
}

// Update godoc
//
//	@Summary		Sửa chương trình đồng giá
//	@Description	Chương trình đã duyệt phải Huỷ duyệt trước (422 ở ô approved). Mã giữ nguyên.
//	@Tags			Admin - Đồng giá
//	@Accept			json
//	@Security		BearerAuth
//	@Param			id		path		int						true	"ID"
//	@Param			body	body		dto.FixedPriceRequest	true	"Chương trình"
//	@Success		200		{object}	response.Body{data=dto.FixedPriceResponse}
//	@Router			/admin/dong-gia/{id} [put]
func (h *FixedPriceHandler) Update(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}
	var req dto.FixedPriceRequest
	if !bindJSON(c, &req) {
		return
	}
	res, err := h.svc.Save(c.Request.Context(), id, req)
	if err != nil {
		handleServiceError(c, err)

		return
	}
	response.OKMessage(c, "Đã lưu chương trình đồng giá", res)
}

// Status godoc
//
//	@Summary	Bật / tắt chương trình đồng giá
//	@Tags		Admin - Đồng giá
//	@Security	BearerAuth
//	@Param		id		path		int							true	"ID"
//	@Param		body	body		dto.FixedPriceStatusRequest	true	"Trạng thái"
//	@Router		/admin/dong-gia/{id}/status [put]
func (h *FixedPriceHandler) Status(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}
	var req dto.FixedPriceStatusRequest
	if !bindJSON(c, &req) {
		return
	}
	if err := h.svc.SetStatus(c.Request.Context(), id, *req.Status); err != nil {
		handleServiceError(c, err)

		return
	}
	response.OKMessage(c, "Đã cập nhật trạng thái", nil)
}

// HuyDuyet godoc
//
//	@Summary	Huỷ duyệt — đưa chương trình về Lưu tạm để sửa được
//	@Tags		Admin - Đồng giá
//	@Security	BearerAuth
//	@Param		id	path	int	true	"ID"
//	@Router		/admin/dong-gia/{id}/huy-duyet [post]
func (h *FixedPriceHandler) HuyDuyet(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}
	if err := h.svc.HuyDuyet(c.Request.Context(), id); err != nil {
		handleServiceError(c, err)

		return
	}
	response.OKMessage(c, "Đã huỷ duyệt", nil)
}

// Delete godoc
//
//	@Summary	Xoá chương trình đồng giá (chương trình đã duyệt thì 409)
//	@Tags		Admin - Đồng giá
//	@Security	BearerAuth
//	@Param		id	path	int	true	"ID"
//	@Router		/admin/dong-gia/{id} [delete]
func (h *FixedPriceHandler) Delete(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}
	if err := h.svc.Delete(c.Request.Context(), id); err != nil {
		handleServiceError(c, err)

		return
	}
	response.OKMessage(c, "Đã xoá chương trình đồng giá", nil)
}

// POSXemTruoc godoc
//
//	@Summary		Đồng giá tại quầy — chương trình đủ điều kiện và giá sau khi chọn
//	@Description	Chỉ trả chương trình đã duyệt, đang bật, đúng ngày/thứ/chi nhánh VÀ giỏ đủ số lượng. Gửi kèm `fixed_price_ids` để biết giá từng món và hàng tặng nếu chọn chúng.
//	@Tags			Admin - Orders
//	@Accept			json
//	@Security		BearerAuth
//	@Param			body	body		dto.POSDongGiaRequest	true	"Giỏ hàng"
//	@Success		200		{object}	response.Body{data=dto.POSDongGiaResponse}
//	@Router			/admin/orders/pos/dong-gia [post]
func (h *FixedPriceHandler) POSXemTruoc(c *gin.Context) {
	var req dto.POSDongGiaRequest
	if !bindJSON(c, &req) {
		return
	}
	res, err := h.svc.XemTruoc(c.Request.Context(), req)
	if err != nil {
		response.Error(c, http.StatusInternalServerError, "Không tính được đồng giá")

		return
	}
	response.OK(c, res)
}
