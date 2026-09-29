package handler

import (
	"github.com/gin-gonic/gin"

	"sass-api/internal/dto"
	"sass-api/internal/service"
	"sass-api/pkg/response"
)

// MembershipHandler — CRM → Thẻ thành viên: hạng, quy đổi điểm, khách theo hạng.
type MembershipHandler struct{ svc service.MembershipService }

func NewMembershipHandler(svc service.MembershipService) *MembershipHandler {
	return &MembershipHandler{svc: svc}
}

// List godoc
//
//	@Summary	Các hạng thành viên (kèm số khách mỗi hạng) và cấu hình quy đổi điểm
//	@Tags		Admin - Thẻ thành viên
//	@Security	BearerAuth
//	@Router		/admin/the-thanh-vien [get]
func (h *MembershipHandler) List(c *gin.Context) {
	ds, err := h.svc.ListRanks(c.Request.Context())
	if err != nil {
		handleServiceError(c, err)

		return
	}
	cf, err := h.svc.Conversion(c.Request.Context())
	if err != nil {
		handleServiceError(c, err)

		return
	}
	response.OK(c, gin.H{"ranks": ds, "conversion": cf})
}

// Get godoc
//
//	@Summary	Một hạng thành viên
//	@Tags		Admin - Thẻ thành viên
//	@Security	BearerAuth
//	@Param		id	path	int	true	"ID"
//	@Router		/admin/the-thanh-vien/{id} [get]
func (h *MembershipHandler) Get(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}
	r, err := h.svc.GetRank(c.Request.Context(), id)
	if err != nil {
		handleServiceError(c, err)

		return
	}
	response.OK(c, r)
}

// Members godoc
//
//	@Summary	Khách đang ở một hạng (trang chi tiết hạng)
//	@Tags		Admin - Thẻ thành viên
//	@Security	BearerAuth
//	@Param		id			path	int		true	"ID hạng"
//	@Param		keyword		query	string	false	"Mã / tên / SĐT khách"
//	@Param		page		query	int		false	"Trang"
//	@Param		page_size	query	int		false	"Số dòng/trang"
//	@Router		/admin/the-thanh-vien/{id}/khach [get]
func (h *MembershipHandler) Members(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}
	page, size := phanTrang(c)
	ds, total, err := h.svc.Members(c.Request.Context(), id, c.Query("keyword"), page, size)
	if err != nil {
		handleServiceError(c, err)

		return
	}
	response.Paginated(c, ds, response.Pagination{Page: page, PageSize: size, Total: total,
		TotalPages: int((total + int64(size) - 1) / int64(size))})
}

// Create godoc
//
//	@Summary	Thêm hạng thành viên (xếp hạng lại mọi khách)
//	@Tags		Admin - Thẻ thành viên
//	@Security	BearerAuth
//	@Param		body	body	dto.MembershipRankRequest	true	"Hạng"
//	@Router		/admin/the-thanh-vien [post]
func (h *MembershipHandler) Create(c *gin.Context) {
	var req dto.MembershipRankRequest
	if !bindJSON(c, &req) {
		return
	}
	r, err := h.svc.SaveRank(c.Request.Context(), 0, req)
	if err != nil {
		handleServiceError(c, err)

		return
	}
	response.CreatedMessage(c, "Thêm hạng thành viên thành công", r)
}

// Update godoc
//
//	@Summary	Sửa hạng thành viên (xếp hạng lại mọi khách)
//	@Tags		Admin - Thẻ thành viên
//	@Security	BearerAuth
//	@Param		id		path	int							true	"ID"
//	@Param		body	body	dto.MembershipRankRequest	true	"Hạng"
//	@Router		/admin/the-thanh-vien/{id} [put]
func (h *MembershipHandler) Update(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}
	var req dto.MembershipRankRequest
	if !bindJSON(c, &req) {
		return
	}
	r, err := h.svc.SaveRank(c.Request.Context(), id, req)
	if err != nil {
		handleServiceError(c, err)

		return
	}
	response.OKMessage(c, "Cập nhật hạng thành viên thành công", r)
}

// Status godoc
//
//	@Summary	Bật / tắt hạng thành viên
//	@Tags		Admin - Thẻ thành viên
//	@Security	BearerAuth
//	@Param		id		path	int									true	"ID"
//	@Param		body	body	dto.MembershipRankStatusRequest	true	"Trạng thái"
//	@Router		/admin/the-thanh-vien/{id}/status [put]
func (h *MembershipHandler) Status(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}
	var req dto.MembershipRankStatusRequest
	if !bindJSON(c, &req) {
		return
	}
	if err := h.svc.SetRankStatus(c.Request.Context(), id, *req.Status); err != nil {
		handleServiceError(c, err)

		return
	}
	response.OKMessage(c, "Cập nhật trạng thái thành công", nil)
}

// Delete godoc
//
//	@Summary	Xoá một hoặc nhiều hạng (khách tụt về hạng thấp hơn còn lại)
//	@Tags		Admin - Thẻ thành viên
//	@Security	BearerAuth
//	@Param		body	body	dto.MembershipRankDeleteRequest	true	"Các hạng"
//	@Router		/admin/the-thanh-vien/xoa [post]
func (h *MembershipHandler) Delete(c *gin.Context) {
	var req dto.MembershipRankDeleteRequest
	if !bindJSON(c, &req) {
		return
	}
	if err := h.svc.DeleteRanks(c.Request.Context(), req.IDs); err != nil {
		handleServiceError(c, err)

		return
	}
	response.OKMessage(c, "Xoá thành công", nil)
}

// Conversion godoc
//
//	@Summary	Lưu quy đổi điểm: earn (tiền → điểm) hoặc redeem (điểm → tiền)
//	@Tags		Admin - Thẻ thành viên
//	@Security	BearerAuth
//	@Param		body	body	dto.PointConversionRequest	true	"Quy đổi"
//	@Router		/admin/the-thanh-vien/quy-doi [put]
func (h *MembershipHandler) Conversion(c *gin.Context) {
	var req dto.PointConversionRequest
	if !bindJSON(c, &req) {
		return
	}
	cf, err := h.svc.SaveConversion(c.Request.Context(), req)
	if err != nil {
		handleServiceError(c, err)

		return
	}
	response.OKMessage(c, "Lưu quy đổi điểm thành công", cf)
}

// POS godoc
//
//	@Summary	Hạng đang bật và quy đổi điểm — màn quầy tính trước giảm theo hạng / đổi điểm
//	@Tags		POS
//	@Security	BearerAuth
//	@Router		/admin/orders/pos/thanh-vien [get]
func (h *MembershipHandler) POS(c *gin.Context) {
	ds, err := h.svc.ListRanks(c.Request.Context())
	if err != nil {
		handleServiceError(c, err)

		return
	}
	cf, err := h.svc.Conversion(c.Request.Context())
	if err != nil {
		handleServiceError(c, err)

		return
	}
	dangBat := make([]map[string]any, 0, len(ds))
	for _, r := range ds {
		if r.Status {
			dangBat = append(dangBat, map[string]any{"id": r.ID, "name": r.Name, "point": r.Point,
				"discount_type": r.DiscountType, "discount_value": r.DiscountValue,
				"apply_all_order_values": r.ApplyAllOrderValues, "min_order_value": r.MinOrderValue, "max_order_value": r.MaxOrderValue})
		}
	}
	response.OK(c, gin.H{"ranks": dangBat, "money_per_point": cf.TienMoiDiem()})
}
