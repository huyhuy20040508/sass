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

// PhieuDieuChinhHandler — Quản lý kho → Điều chỉnh tồn kho.
type PhieuDieuChinhHandler struct {
	svc service.PhieuDieuChinhService
}

func NewPhieuDieuChinhHandler(svc service.PhieuDieuChinhService) *PhieuDieuChinhHandler {
	return &PhieuDieuChinhHandler{svc: svc}
}

func (h *PhieuDieuChinhHandler) locTuQuery(c *gin.Context) domain.DieuChinhFilter {
	page, _ := strconv.Atoi(c.DefaultQuery("page", "1"))
	size, _ := strconv.Atoi(c.DefaultQuery("page_size", "20"))
	if page < 1 {
		page = 1
	}
	// Kẹp trần như mọi đường danh sách khác: truy vấn này còn Preload cả dòng hàng.
	if size < 1 || size > 1000 {
		size = 20
	}

	return domain.DieuChinhFilter{
		Keyword:         c.Query("keyword"),
		Type:            c.Query("type"),
		Status:          c.Query("status"),
		WarehouseStatus: c.Query("warehouse_status"),
		CreatedBy:       c.Query("created_by"),
		ShopID:          chiNhanhLoc(c),
		FromDate:        c.Query("from_date"),
		ToDate:          c.Query("to_date"),
		Sort:            c.Query("sort"),
		Page:            page,
		PageSize:        size,
	}
}

// List godoc
//
//	@Summary		Danh sách phiếu điều chỉnh tồn kho
//	@Description	Lọc theo từ khoá (mã phiếu / ghi chú), loại, trạng thái phiếu, trạng thái kho, người lập và khoảng ngày lập.
//	@Description	`status` và `created_by` nhận nhiều giá trị ngăn bởi dấu phẩy. Không gửi `shop_id` thì cắt theo chi nhánh đang làm việc.
//	@Tags			Admin - Điều chỉnh tồn kho
//	@Produce		json
//	@Security		BearerAuth
//	@Param			keyword				query		string	false	"Mã phiếu hoặc ghi chú"
//	@Param			type				query		string	false	"adjust | balance"
//	@Param			status				query		string	false	"draft | pending | approved | rejected (ngăn bởi dấu phẩy)"
//	@Param			warehouse_status	query		string	false	"pending | done | rejected"
//	@Param			created_by			query		string	false	"id người lập, ngăn bởi dấu phẩy"
//	@Param			shop_id				query		int		false	"Chi nhánh; 0 = mọi chi nhánh"
//	@Param			from_date			query		string	false	"YYYY-MM-DD"
//	@Param			to_date				query		string	false	"YYYY-MM-DD"
//	@Param			sort				query		string	false	"newest | oldest | code_asc | code_desc"
//	@Param			page				query		int		false	"Trang, mặc định 1"
//	@Param			page_size			query		int		false	"Số dòng mỗi trang, mặc định 20"
//	@Success		200					{object}	response.Body{data=[]domain.PhieuDieuChinh}
//	@Failure		401					{object}	response.Body
//	@Router			/admin/dieu-chinh-ton-kho [get]
func (h *PhieuDieuChinhHandler) List(c *gin.Context) {
	f := h.locTuQuery(c)

	list, tong, err := h.svc.List(c.Request.Context(), f)
	if err != nil {
		handleServiceError(c, err)

		return
	}

	response.Paginated(c, list, response.Pagination{
		Page:       f.Page,
		PageSize:   f.PageSize,
		Total:      tong,
		TotalPages: int((tong + int64(f.PageSize) - 1) / int64(f.PageSize)),
	})
}

// HangAm godoc
//
//	@Summary		Lô đang âm chờ cân đối
//	@Description	Các lô có tồn âm tại kho của request, đã trừ phần các phiếu cân đối chưa duyệt. Kho không có lô âm nào → 404.
//	@Tags			Admin - Điều chỉnh tồn kho
//	@Produce		json
//	@Security		BearerAuth
//	@Param			shop_id	query		int	false	"Chi nhánh; bỏ trống = chi nhánh đang làm việc"
//	@Success		200		{object}	response.Body{data=[]domain.HangAm}
//	@Failure		404		{object}	response.Body
//	@Router			/admin/dieu-chinh-ton-kho/hang-am [get]
func (h *PhieuDieuChinhHandler) HangAm(c *gin.Context) {
	ds, err := h.svc.HangAm(c.Request.Context(), chiNhanhLoc(c))
	if err != nil {
		handleServiceError(c, err)

		return
	}
	response.OK(c, ds)
}

// MatHang godoc
//
//	@Summary		Hồ sơ + tồn + mọi lô của các mặt hàng tại kho
//	@Description	Màn lập phiếu gọi sau khi chọn hàng (vai `getMenu` của v2). Trả cả lô "Không xác định" (lot_number rỗng) và lô đang âm.
//	@Tags			Admin - Điều chỉnh tồn kho
//	@Produce		json
//	@Security		BearerAuth
//	@Param			ids		query		string	true	"id biến thể, ngăn bởi dấu phẩy"
//	@Param			shop_id	query		int		false	"Chi nhánh; bỏ trống = chi nhánh đang làm việc"
//	@Success		200		{object}	response.Body{data=[]domain.DieuChinhHang}
//	@Failure		422		{object}	response.Body
//	@Router			/admin/dieu-chinh-ton-kho/mat-hang [get]
func (h *PhieuDieuChinhHandler) MatHang(c *gin.Context) {
	ids := make([]uint, 0, 8)
	for _, phan := range strings.Split(c.Query("ids"), ",") {
		if v, err := strconv.ParseUint(strings.TrimSpace(phan), 10, 64); err == nil && v > 0 {
			ids = append(ids, uint(v))
		}
	}
	if len(ids) == 0 {
		response.ValidationError(c, map[string]string{"ids": "Chưa chọn mặt hàng nào"})

		return
	}
	// Kẹp trần như "Thêm tất cả hàng từ nhóm": một nhóm vài trăm mặt hàng là đủ.
	if len(ids) > 500 {
		ids = ids[:500]
	}

	ds, err := h.svc.MatHang(c.Request.Context(), chiNhanhLoc(c), ids)
	if err != nil {
		handleServiceError(c, err)

		return
	}
	response.OK(c, ds)
}

// Get godoc
//
//	@Summary	Chi tiết một phiếu điều chỉnh
//	@Tags		Admin - Điều chỉnh tồn kho
//	@Produce	json
//	@Security	BearerAuth
//	@Param		id	path		int	true	"ID phiếu"
//	@Success	200	{object}	response.Body{data=domain.PhieuDieuChinh}
//	@Failure	404	{object}	response.Body
//	@Router		/admin/dieu-chinh-ton-kho/{id} [get]
func (h *PhieuDieuChinhHandler) Get(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}

	p, err := h.svc.GetByID(c.Request.Context(), id)
	if err != nil {
		handleServiceError(c, err)

		return
	}
	response.OK(c, p)
}

// Create godoc
//
//	@Summary		Lập phiếu điều chỉnh tồn kho
//	@Description	`status` nói nút nào được bấm: draft (lưu tạm), pending (gửi duyệt), approved (duyệt luôn — kho đổi ngay).
//	@Tags			Admin - Điều chỉnh tồn kho
//	@Accept			json
//	@Produce		json
//	@Security		BearerAuth
//	@Param			body	body		dto.DieuChinhCreateRequest	true	"Phiếu"
//	@Success		201		{object}	response.Body{data=domain.PhieuDieuChinh}
//	@Failure		422		{object}	response.Body
//	@Router			/admin/dieu-chinh-ton-kho [post]
func (h *PhieuDieuChinhHandler) Create(c *gin.Context) {
	var req dto.DieuChinhCreateRequest
	if !bindJSON(c, &req) {
		return
	}

	p, err := h.svc.Create(c.Request.Context(), &req, currentUserID(c))
	if err != nil {
		handleServiceError(c, err)

		return
	}
	response.Created(c, p)
}

// Update godoc
//
//	@Summary		Sửa phiếu điều chỉnh
//	@Description	Chỉ phiếu LƯU TẠM sửa được. `status` pending / approved = lưu xong thì gửi duyệt / duyệt luôn.
//	@Tags			Admin - Điều chỉnh tồn kho
//	@Accept			json
//	@Produce		json
//	@Security		BearerAuth
//	@Param			id		path		int							true	"ID phiếu"
//	@Param			body	body		dto.DieuChinhUpdateRequest	true	"Phiếu"
//	@Success		200		{object}	response.Body{data=domain.PhieuDieuChinh}
//	@Failure		409		{object}	response.Body
//	@Router			/admin/dieu-chinh-ton-kho/{id} [put]
func (h *PhieuDieuChinhHandler) Update(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}

	var req dto.DieuChinhUpdateRequest
	if !bindJSON(c, &req) {
		return
	}

	p, err := h.svc.Update(c.Request.Context(), id, &req, currentUserID(c))
	if err != nil {
		handleServiceError(c, err)

		return
	}
	response.OK(c, p)
}

// Submit godoc
//
//	@Summary		Gửi duyệt phiếu điều chỉnh
//	@Description	Lưu tạm → chờ duyệt. Chưa đụng tới kho.
//	@Tags			Admin - Điều chỉnh tồn kho
//	@Produce		json
//	@Security		BearerAuth
//	@Param			id	path		int	true	"ID phiếu"
//	@Success		200	{object}	response.Body{data=domain.PhieuDieuChinh}
//	@Failure		409	{object}	response.Body
//	@Router			/admin/dieu-chinh-ton-kho/{id}/gui-duyet [post]
func (h *PhieuDieuChinhHandler) Submit(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}

	p, err := h.svc.Submit(c.Request.Context(), id, currentUserID(c))
	if err != nil {
		handleServiceError(c, err)

		return
	}
	response.OK(c, p)
}

// Approve godoc
//
//	@Summary		Duyệt phiếu điều chỉnh
//	@Description	Số tồn của kho đổi theo từng dòng phiếu, trong một transaction. Sau bước này phiếu khoá lại.
//	@Tags			Admin - Điều chỉnh tồn kho
//	@Accept			json
//	@Produce		json
//	@Security		BearerAuth
//	@Param			id		path		int							true	"ID phiếu"
//	@Param			body	body		dto.DieuChinhApproveRequest	false	"Ghi chú"
//	@Success		200		{object}	response.Body{data=domain.PhieuDieuChinh}
//	@Failure		409		{object}	response.Body
//	@Router			/admin/dieu-chinh-ton-kho/{id}/duyet [post]
func (h *PhieuDieuChinhHandler) Approve(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}

	// Thân rỗng vẫn hợp lệ: ghi chú là tuỳ chọn.
	var req dto.DieuChinhApproveRequest
	_ = c.ShouldBindJSON(&req)

	p, err := h.svc.Approve(c.Request.Context(), id, &req, currentUserID(c))
	if err != nil {
		handleServiceError(c, err)

		return
	}
	response.OK(c, p)
}

// Reject godoc
//
//	@Summary		Từ chối phiếu điều chỉnh
//	@Description	Lưu tạm / chờ duyệt → từ chối, lý do bắt buộc. Không đụng tới kho.
//	@Tags			Admin - Điều chỉnh tồn kho
//	@Accept			json
//	@Produce		json
//	@Security		BearerAuth
//	@Param			id		path		int							true	"ID phiếu"
//	@Param			body	body		dto.DieuChinhRejectRequest	true	"Lý do"
//	@Success		200		{object}	response.Body{data=domain.PhieuDieuChinh}
//	@Failure		409		{object}	response.Body
//	@Router			/admin/dieu-chinh-ton-kho/{id}/tu-choi [post]
func (h *PhieuDieuChinhHandler) Reject(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}

	var req dto.DieuChinhRejectRequest
	if !bindJSON(c, &req) {
		return
	}

	p, err := h.svc.Reject(c.Request.Context(), id, &req, currentUserID(c))
	if err != nil {
		handleServiceError(c, err)

		return
	}
	response.OK(c, p)
}

// Delete godoc
//
//	@Summary		Xoá phiếu điều chỉnh
//	@Description	Chỉ phiếu LƯU TẠM xoá được.
//	@Tags			Admin - Điều chỉnh tồn kho
//	@Produce		json
//	@Security		BearerAuth
//	@Param			id	path		int	true	"ID phiếu"
//	@Success		200	{object}	response.Body
//	@Failure		409	{object}	response.Body
//	@Router			/admin/dieu-chinh-ton-kho/{id} [delete]
func (h *PhieuDieuChinhHandler) Delete(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}

	if err := h.svc.Delete(c.Request.Context(), id); err != nil {
		handleServiceError(c, err)

		return
	}
	response.OK(c, gin.H{"deleted": true})
}
