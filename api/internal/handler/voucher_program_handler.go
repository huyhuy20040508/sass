package handler

import (
	"strconv"
	"strings"

	"github.com/gin-gonic/gin"

	"sass-api/internal/domain"
	"sass-api/internal/dto"
	"sass-api/internal/service"
	"sass-api/pkg/response"
)

// VoucherProgramHandler — CRM → Chương trình khuyến mãi → tab Voucher/Coupon.
type VoucherProgramHandler struct{ svc service.VoucherProgramService }

func NewVoucherProgramHandler(svc service.VoucherProgramService) *VoucherProgramHandler {
	return &VoucherProgramHandler{svc: svc}
}

func phanTrang(c *gin.Context) (int, int) {
	page, size := queryInt(c, "page", 1), queryInt(c, "page_size", 10)
	if size < 1 || size > 100 {
		size = 10
	}
	if page < 1 {
		page = 1
	}

	return page, size
}

// List godoc
//
//	@Summary	Danh sách chương trình Voucher/Coupon
//	@Tags		Admin - Voucher/Coupon
//	@Security	BearerAuth
//	@Param		shop_ids	query		string	false	"Id chi nhánh, ngăn bởi dấu phẩy"
//	@Param		statuses	query		string	false	"1 = chưa phát hành, 2 = phát hành; ngăn bởi dấu phẩy"
//	@Param		page		query		int		false	"Trang"
//	@Param		page_size	query		int		false	"Số dòng/trang (tối đa 100)"
//	@Success	200			{object}	response.Body{data=[]domain.VoucherProgram}
//	@Router		/admin/voucher-coupon [get]
func (h *VoucherProgramHandler) List(c *gin.Context) {
	f := domain.VoucherProgramFilter{}
	f.Page, f.PageSize = phanTrang(c)
	for _, v := range strings.Split(c.Query("shop_ids"), ",") {
		if n, err := strconv.ParseUint(strings.TrimSpace(v), 10, 64); err == nil && n > 0 {
			f.ShopIDs = append(f.ShopIDs, uint(n))
		}
	}
	for _, v := range strings.Split(c.Query("statuses"), ",") {
		if n, err := strconv.ParseUint(strings.TrimSpace(v), 10, 8); err == nil && (n == 1 || n == 2) {
			f.Statuses = append(f.Statuses, uint8(n))
		}
	}

	items, total, err := h.svc.List(c.Request.Context(), f)
	if err != nil {
		handleServiceError(c, err)

		return
	}
	response.Paginated(c, items, response.Pagination{Page: f.Page, PageSize: f.PageSize, Total: total,
		TotalPages: int((total + int64(f.PageSize) - 1) / int64(f.PageSize))})
}

// Get godoc
//
//	@Summary	Một chương trình Voucher/Coupon
//	@Tags		Admin - Voucher/Coupon
//	@Security	BearerAuth
//	@Param		id	path		int	true	"ID"
//	@Success	200	{object}	response.Body{data=domain.VoucherProgram}
//	@Router		/admin/voucher-coupon/{id} [get]
func (h *VoucherProgramHandler) Get(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}
	p, err := h.svc.Get(c.Request.Context(), id)
	if err != nil {
		handleServiceError(c, err)

		return
	}
	response.OK(c, p)
}

// Create godoc
//
//	@Summary		Tạo chương trình Voucher/Coupon
//	@Description	`release` = true là bấm "Phát hành": lưu xong sinh luôn `quantity` mã (tiền tố + 5 ký tự ngẫu nhiên + hậu tố). false là "Lưu" — chưa có mã.
//	@Tags			Admin - Voucher/Coupon
//	@Accept			json
//	@Security		BearerAuth
//	@Param			body	body		dto.VoucherProgramRequest	true	"Chương trình"
//	@Success		201		{object}	response.Body{data=domain.VoucherProgram}
//	@Router			/admin/voucher-coupon [post]
func (h *VoucherProgramHandler) Create(c *gin.Context) {
	var req dto.VoucherProgramRequest
	if !bindJSON(c, &req) {
		return
	}
	p, err := h.svc.Save(c.Request.Context(), 0, req, currentUserID(c))
	if err != nil {
		handleServiceError(c, err)

		return
	}
	response.CreatedMessage(c, "Tạo chương trình thành công", p)
}

// Update godoc
//
//	@Summary		Sửa chương trình Voucher/Coupon chưa phát hành
//	@Description	Chương trình đã phát hành thì 409.
//	@Tags			Admin - Voucher/Coupon
//	@Accept			json
//	@Security		BearerAuth
//	@Param			id		path		int							true	"ID"
//	@Param			body	body		dto.VoucherProgramRequest	true	"Chương trình"
//	@Success		200		{object}	response.Body{data=domain.VoucherProgram}
//	@Router			/admin/voucher-coupon/{id} [put]
func (h *VoucherProgramHandler) Update(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}
	var req dto.VoucherProgramRequest
	if !bindJSON(c, &req) {
		return
	}
	p, err := h.svc.Save(c.Request.Context(), id, req, currentUserID(c))
	if err != nil {
		handleServiceError(c, err)

		return
	}
	response.OKMessage(c, "Cập nhật chương trình thành công", p)
}

// Delete godoc
//
//	@Summary	Xoá chương trình chưa phát hành (đã phát hành thì 409)
//	@Tags		Admin - Voucher/Coupon
//	@Security	BearerAuth
//	@Param		id	path	int	true	"ID"
//	@Router		/admin/voucher-coupon/{id} [delete]
func (h *VoucherProgramHandler) Delete(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}
	if err := h.svc.Delete(c.Request.Context(), id); err != nil {
		handleServiceError(c, err)

		return
	}
	response.OKMessage(c, "Xoá chương trình thành công", nil)
}

// Codes godoc
//
//	@Summary	Danh sách mã của một chương trình ("Danh sách mã chi tiết")
//	@Tags		Admin - Voucher/Coupon
//	@Security	BearerAuth
//	@Param		id			path		int		true	"ID chương trình"
//	@Param		keyword		query		string	false	"Tìm theo mã"
//	@Param		page		query		int		false	"Trang"
//	@Param		page_size	query		int		false	"Số dòng/trang (tối đa 100)"
//	@Success	200			{object}	response.Body{data=[]domain.Voucher}
//	@Router		/admin/voucher-coupon/{id}/ma [get]
func (h *VoucherProgramHandler) Codes(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}
	f := domain.VoucherCodeFilter{ProgramID: id, Keyword: c.Query("keyword")}
	f.Page, f.PageSize = phanTrang(c)
	items, total, err := h.svc.Codes(c.Request.Context(), f)
	if err != nil {
		handleServiceError(c, err)

		return
	}
	response.Paginated(c, items, response.Pagination{Page: f.Page, PageSize: f.PageSize, Total: total,
		TotalPages: int((total + int64(f.PageSize) - 1) / int64(f.PageSize))})
}

// CodeStatus godoc
//
//	@Summary	Bật / tắt một mã của chương trình
//	@Tags		Admin - Voucher/Coupon
//	@Security	BearerAuth
//	@Param		id		path	int								true	"ID mã (vouchers.id)"
//	@Param		body	body	dto.VoucherCodeStatusRequest	true	"Trạng thái"
//	@Router		/admin/voucher-coupon-ma/{id}/status [put]
func (h *VoucherProgramHandler) CodeStatus(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}
	var req dto.VoucherCodeStatusRequest
	if !bindJSON(c, &req) {
		return
	}
	if err := h.svc.SetCodeActive(c.Request.Context(), id, *req.IsActive); err != nil {
		handleServiceError(c, err)

		return
	}
	response.OKMessage(c, "Cập nhật trạng thái thành công", nil)
}

// CodeUses godoc
//
//	@Summary	Các lượt đã dùng một mã (nhân viên, thời gian, chi nhánh)
//	@Tags		Admin - Voucher/Coupon
//	@Security	BearerAuth
//	@Param		id	path		int	true	"ID mã (vouchers.id)"
//	@Success	200	{object}	response.Body{data=[]domain.VoucherCodeUse}
//	@Router		/admin/voucher-coupon-ma/{id}/lich-su [get]
func (h *VoucherProgramHandler) CodeUses(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}
	ds, err := h.svc.CodeUses(c.Request.Context(), id)
	if err != nil {
		handleServiceError(c, err)

		return
	}
	response.OK(c, ds)
}
