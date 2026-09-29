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

// PromotionProgramHandler — CRM → Chương trình khuyến mại (khuôn v2) và đường
// xem trước ở quầy.
type PromotionProgramHandler struct {
	svc service.PromotionProgramService
}

func NewPromotionProgramHandler(svc service.PromotionProgramService) *PromotionProgramHandler {
	return &PromotionProgramHandler{svc: svc}
}

func soCSV(v string) []uint {
	out := []uint{}
	for _, x := range strings.Split(v, ",") {
		if n, err := strconv.ParseUint(strings.TrimSpace(x), 10, 64); err == nil && n > 0 {
			out = append(out, uint(n))
		}
	}

	return out
}

// List godoc
//
//	@Summary		Danh sách chương trình khuyến mại
//	@Description	Lọc như màn v2: khoảng NGÀY TẠO, chi nhánh, mã, trạng thái.
//	@Tags			Admin - Chương trình khuyến mại
//	@Security		BearerAuth
//	@Param			from_date	query		string	false	"Ngày tạo từ (YYYY-MM-DD)"
//	@Param			to_date		query		string	false	"Ngày tạo đến (YYYY-MM-DD)"
//	@Param			shop_ids	query		string	false	"Id chi nhánh, ngăn bởi dấu phẩy"
//	@Param			codes		query		string	false	"Mã chương trình, ngăn bởi dấu phẩy"
//	@Param			statuses	query		string	false	"1 hoạt động, 0 không hoạt động, ngăn bởi dấu phẩy"
//	@Param			page		query		int		false	"Trang"
//	@Param			page_size	query		int		false	"Số dòng/trang"
//	@Success		200			{object}	response.Body{data=[]dto.PromotionProgramResponse}
//	@Router			/admin/chuong-trinh-khuyen-mai [get]
func (h *PromotionProgramHandler) List(c *gin.Context) {
	f := domain.PromotionProgramFilter{
		FromDate: c.Query("from_date"), ToDate: c.Query("to_date"),
		ShopIDs: soCSV(c.Query("shop_ids")),
		Page:    queryInt(c, "page", 1), PageSize: queryInt(c, "page_size", 10),
	}
	for _, m := range strings.Split(c.Query("codes"), ",") {
		if m = strings.TrimSpace(m); m != "" {
			f.Codes = append(f.Codes, m)
		}
	}
	for _, v := range strings.Split(c.Query("statuses"), ",") {
		switch strings.TrimSpace(v) {
		case "1":
			f.Statuses = append(f.Statuses, true)
		case "0":
			f.Statuses = append(f.Statuses, false)
		}
	}
	if f.PageSize < 1 || f.PageSize > 100 {
		f.PageSize = 10
	}
	if f.Page < 1 {
		f.Page = 1
	}
	items, total, err := h.svc.List(c.Request.Context(), f)
	if err != nil {
		handleServiceError(c, err)

		return
	}
	response.Paginated(c, items, response.Pagination{Page: f.Page, PageSize: f.PageSize, Total: total,
		TotalPages: int((total + int64(f.PageSize) - 1) / int64(f.PageSize))})
}

// Codes godoc
//
//	@Summary	Mọi mã chương trình khuyến mại — ô lọc "Mã khuyến mãi"
//	@Tags		Admin - Chương trình khuyến mại
//	@Security	BearerAuth
//	@Router		/admin/chuong-trinh-khuyen-mai/ma [get]
func (h *PromotionProgramHandler) Codes(c *gin.Context) {
	ds, err := h.svc.Codes(c.Request.Context())
	if err != nil {
		handleServiceError(c, err)

		return
	}
	response.OK(c, ds)
}

// Get godoc
//
//	@Summary	Một chương trình khuyến mại (kèm bậc, hàng tặng)
//	@Tags		Admin - Chương trình khuyến mại
//	@Security	BearerAuth
//	@Param		id	path	int	true	"ID"
//	@Router		/admin/chuong-trinh-khuyen-mai/{id} [get]
func (h *PromotionProgramHandler) Get(c *gin.Context) {
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
//	@Summary		Tạo chương trình khuyến mại
//	@Description	`approved` = true là "Duyệt", false là "Lưu tạm". Mã do hệ thống cấp (CTKM00001…).
//	@Tags			Admin - Chương trình khuyến mại
//	@Accept			json
//	@Security		BearerAuth
//	@Param			body	body	dto.PromotionProgramRequest	true	"Chương trình"
//	@Router			/admin/chuong-trinh-khuyen-mai [post]
func (h *PromotionProgramHandler) Create(c *gin.Context) {
	var req dto.PromotionProgramRequest
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
//	@Summary	Sửa chương trình khuyến mại (đã duyệt thì phải huỷ duyệt trước)
//	@Tags		Admin - Chương trình khuyến mại
//	@Accept		json
//	@Security	BearerAuth
//	@Param		id		path	int								true	"ID"
//	@Param		body	body	dto.PromotionProgramRequest	true	"Chương trình"
//	@Router		/admin/chuong-trinh-khuyen-mai/{id} [put]
func (h *PromotionProgramHandler) Update(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}
	var req dto.PromotionProgramRequest
	if !bindJSON(c, &req) {
		return
	}
	res, err := h.svc.Save(c.Request.Context(), id, req)
	if err != nil {
		handleServiceError(c, err)

		return
	}
	response.OKMessage(c, "Đã lưu chương trình khuyến mại", res)
}

// Duplicate godoc
//
//	@Summary	Nhân bản chương trình khuyến mại (bản sao ở trạng thái Lưu tạm)
//	@Tags		Admin - Chương trình khuyến mại
//	@Security	BearerAuth
//	@Param		id	path	int	true	"ID"
//	@Router		/admin/chuong-trinh-khuyen-mai/{id}/nhan-ban [post]
func (h *PromotionProgramHandler) Duplicate(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}
	res, err := h.svc.Duplicate(c.Request.Context(), id)
	if err != nil {
		handleServiceError(c, err)

		return
	}
	response.CreatedMessage(c, "Đã nhân bản chương trình", res)
}

// Status godoc
//
//	@Summary	Bật / tắt chương trình khuyến mại
//	@Tags		Admin - Chương trình khuyến mại
//	@Security	BearerAuth
//	@Param		id		path	int									true	"ID"
//	@Param		body	body	dto.PromotionProgramStatusRequest	true	"Trạng thái"
//	@Router		/admin/chuong-trinh-khuyen-mai/{id}/status [put]
func (h *PromotionProgramHandler) Status(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}
	var req dto.PromotionProgramStatusRequest
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
//	@Summary	Huỷ duyệt — đưa về Lưu tạm để sửa được
//	@Tags		Admin - Chương trình khuyến mại
//	@Security	BearerAuth
//	@Param		id	path	int	true	"ID"
//	@Router		/admin/chuong-trinh-khuyen-mai/{id}/huy-duyet [post]
func (h *PromotionProgramHandler) HuyDuyet(c *gin.Context) {
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
//	@Summary	Xoá chương trình khuyến mại (đã có đơn dùng thì 409)
//	@Tags		Admin - Chương trình khuyến mại
//	@Security	BearerAuth
//	@Param		id	path	int	true	"ID"
//	@Router		/admin/chuong-trinh-khuyen-mai/{id} [delete]
func (h *PromotionProgramHandler) Delete(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}
	if err := h.svc.Delete(c.Request.Context(), id); err != nil {
		handleServiceError(c, err)

		return
	}
	response.OKMessage(c, "Đã xoá chương trình khuyến mại", nil)
}

// POSXemTruoc godoc
//
//	@Summary		Khuyến mãi tại quầy — chương trình đủ điều kiện và số giảm khi chọn
//	@Description	Chỉ trả chương trình đã duyệt, đang bật, đúng ngày/thứ/chi nhánh VÀ đơn đạt ít nhất một bậc.
//	@Tags			Admin - Orders
//	@Accept			json
//	@Security		BearerAuth
//	@Param			body	body		dto.POSKhuyenMaiRequest	true	"Giỏ hàng"
//	@Success		200		{object}	response.Body{data=dto.POSKhuyenMaiResponse}
//	@Router			/admin/orders/pos/khuyen-mai [post]
func (h *PromotionProgramHandler) POSXemTruoc(c *gin.Context) {
	var req dto.POSKhuyenMaiRequest
	if !bindJSON(c, &req) {
		return
	}
	res, err := h.svc.XemTruoc(c.Request.Context(), req)
	if err != nil {
		response.Error(c, http.StatusInternalServerError, "Không tính được khuyến mãi")

		return
	}
	response.OK(c, res)
}
