package handler

import (
	"strconv"

	"github.com/gin-gonic/gin"

	"sass-api/internal/domain"
	"sass-api/internal/dto"
	"sass-api/internal/service"
	"sass-api/pkg/response"
)

// PhieuDieuChuyenHandler — Quản lý kho → Phiếu điều chuyển.
type PhieuDieuChuyenHandler struct {
	svc service.PhieuDieuChuyenService
}

func NewPhieuDieuChuyenHandler(svc service.PhieuDieuChuyenService) *PhieuDieuChuyenHandler {
	return &PhieuDieuChuyenHandler{svc: svc}
}

// locTuQuery dựng bộ lọc dùng chung cho List và Stats — hai đường phải nói về
// CÙNG một tập phiếu, nên chúng phải đọc query theo cùng một cách.
func (h *PhieuDieuChuyenHandler) locTuQuery(c *gin.Context) domain.DieuChuyenFilter {
	page, _ := strconv.Atoi(c.DefaultQuery("page", "1"))
	size, _ := strconv.Atoi(c.DefaultQuery("page_size", "20"))
	if page < 1 {
		page = 1
	}
	// Kẹp trần như mọi đường danh sách khác: truy vấn này còn Preload cả dòng hàng.
	if size < 1 || size > 100 {
		size = 20
	}
	variantID, _ := strconv.ParseUint(c.Query("variant_id"), 10, 64)

	return domain.DieuChuyenFilter{
		Keyword:   c.Query("keyword"),
		Status:    c.Query("status"),
		ShopID:    chiNhanhLoc(c),
		VariantID: uint(variantID),
		FromDate:  c.Query("from_date"),
		ToDate:    c.Query("to_date"),
		Sort:      c.Query("sort"),
		Page:      page,
		PageSize:  size,
	}
}

// List godoc
//
//	@Summary		Danh sách phiếu điều chuyển
//	@Description	Lọc theo từ khoá (mã phiếu / ghi chú), trạng thái, mặt hàng và khoảng ngày lập.
//	@Description	`status` nhận nhiều giá trị ngăn bởi dấu phẩy — bộ lọc ngoài bảng là các ô tick.
//	@Description	Không gửi `shop_id` thì cắt theo chi nhánh đang làm việc, và cắt theo CẢ HAI ĐẦU: phiếu chi nhánh đó gửi đi lẫn phiếu nó nhận về.
//	@Tags			Admin - Phiếu điều chuyển
//	@Produce		json
//	@Security		BearerAuth
//	@Param			keyword		query		string	false	"Mã phiếu hoặc ghi chú"
//	@Param			status		query		string	false	"draft | approved (ngăn bởi dấu phẩy)"
//	@Param			shop_id		query		int		false	"Chi nhánh; 0 = mọi chi nhánh"
//	@Param			variant_id	query		int		false	"Lọc phiếu có chứa mặt hàng này"
//	@Param			from_date	query		string	false	"YYYY-MM-DD"
//	@Param			to_date		query		string	false	"YYYY-MM-DD"
//	@Param			sort		query		string	false	"newest | oldest"
//	@Param			page		query		int		false	"Trang, mặc định 1"
//	@Param			page_size	query		int		false	"Số dòng mỗi trang, mặc định 20, tối đa 100"
//	@Success		200			{object}	response.Body{data=[]domain.PhieuDieuChuyen}
//	@Failure		401			{object}	response.Body
//	@Router			/admin/phieu-dieu-chuyen [get]
func (h *PhieuDieuChuyenHandler) List(c *gin.Context) {
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

// Stats godoc
//
//	@Summary		Con số đầu trang phiếu điều chuyển
//	@Description	Đếm theo CÙNG bộ lọc với danh sách, chỉ bỏ `status` — bốn con số phải nói về cùng một tập phiếu.
//	@Tags			Admin - Phiếu điều chuyển
//	@Produce		json
//	@Security		BearerAuth
//	@Success		200	{object}	response.Body{data=domain.DieuChuyenStats}
//	@Failure		401	{object}	response.Body
//	@Router			/admin/phieu-dieu-chuyen/stats [get]
func (h *PhieuDieuChuyenHandler) Stats(c *gin.Context) {
	st, err := h.svc.Stats(c.Request.Context(), h.locTuQuery(c))
	if err != nil {
		handleServiceError(c, err)

		return
	}
	response.OK(c, st)
}

// Get godoc
//
//	@Summary	Chi tiết một phiếu điều chuyển
//	@Tags		Admin - Phiếu điều chuyển
//	@Produce	json
//	@Security	BearerAuth
//	@Param		id	path		int	true	"ID phiếu"
//	@Success	200	{object}	response.Body{data=service.DieuChuyenDetail}
//	@Failure	404	{object}	response.Body
//	@Router		/admin/phieu-dieu-chuyen/{id} [get]
func (h *PhieuDieuChuyenHandler) Get(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}

	pdc, err := h.svc.GetByID(c.Request.Context(), id)
	if err != nil {
		handleServiceError(c, err)

		return
	}
	response.OK(c, pdc)
}

// Create godoc
//
//	@Summary		Lập phiếu điều chuyển
//	@Description	Phiếu lập ra LUÔN ở trạng thái lưu tạm, chưa đụng tới kho. Duyệt đi đường riêng.
//	@Tags			Admin - Phiếu điều chuyển
//	@Accept			json
//	@Produce		json
//	@Security		BearerAuth
//	@Param			body	body		dto.DieuChuyenCreateRequest	true	"Phiếu"
//	@Success		201		{object}	response.Body{data=service.DieuChuyenDetail}
//	@Failure		422		{object}	response.Body
//	@Router			/admin/phieu-dieu-chuyen [post]
func (h *PhieuDieuChuyenHandler) Create(c *gin.Context) {
	var req dto.DieuChuyenCreateRequest
	if !bindJSON(c, &req) {
		return
	}

	pdc, err := h.svc.Create(c.Request.Context(), &req, currentUserID(c))
	if err != nil {
		handleServiceError(c, err)

		return
	}
	response.Created(c, pdc)
}

// Update godoc
//
//	@Summary		Sửa phiếu điều chuyển
//	@Description	Chỉ phiếu LƯU TẠM sửa được — phiếu đã duyệt khoá lại vì kho hai đầu đã đổi theo nó.
//	@Tags			Admin - Phiếu điều chuyển
//	@Accept			json
//	@Produce		json
//	@Security		BearerAuth
//	@Param			id		path		int							true	"ID phiếu"
//	@Param			body	body		dto.DieuChuyenUpdateRequest	true	"Phiếu"
//	@Success		200		{object}	response.Body{data=service.DieuChuyenDetail}
//	@Failure		409		{object}	response.Body
//	@Router			/admin/phieu-dieu-chuyen/{id} [put]
func (h *PhieuDieuChuyenHandler) Update(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}

	var req dto.DieuChuyenUpdateRequest
	if !bindJSON(c, &req) {
		return
	}

	pdc, err := h.svc.Update(c.Request.Context(), id, &req, currentUserID(c))
	if err != nil {
		handleServiceError(c, err)

		return
	}
	response.OK(c, pdc)
}

// Approve godoc
//
//	@Summary		Duyệt phiếu điều chuyển
//	@Description	Hàng RỜI kho xuất và VÀO kho nhập, cả hai trong cùng một transaction. Sau bước này phiếu khoá lại.
//	@Tags			Admin - Phiếu điều chuyển
//	@Accept			json
//	@Produce		json
//	@Security		BearerAuth
//	@Param			id		path		int								true	"ID phiếu"
//	@Param			body	body		dto.DieuChuyenApproveRequest	false	"Ghi chú"
//	@Success		200		{object}	response.Body{data=service.DieuChuyenDetail}
//	@Failure		409		{object}	response.Body
//	@Router			/admin/phieu-dieu-chuyen/{id}/duyet [post]
func (h *PhieuDieuChuyenHandler) Approve(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}

	// Thân rỗng vẫn hợp lệ: ghi chú là tuỳ chọn, và bắt gửi `{}` chỉ để duyệt một
	// phiếu là một cái bẫy không cần thiết.
	var req dto.DieuChuyenApproveRequest
	_ = c.ShouldBindJSON(&req)

	pdc, err := h.svc.Approve(c.Request.Context(), id, &req, currentUserID(c))
	if err != nil {
		handleServiceError(c, err)

		return
	}
	response.OK(c, pdc)
}

// Delete godoc
//
//	@Summary		Xoá phiếu điều chuyển
//	@Description	Chỉ phiếu LƯU TẠM xoá được. Phiếu đã duyệt nằm lại trong sổ vì kho hai đầu đã đổi theo nó.
//	@Tags			Admin - Phiếu điều chuyển
//	@Produce		json
//	@Security		BearerAuth
//	@Param			id	path		int	true	"ID phiếu"
//	@Success		200	{object}	response.Body
//	@Failure		409	{object}	response.Body
//	@Router			/admin/phieu-dieu-chuyen/{id} [delete]
func (h *PhieuDieuChuyenHandler) Delete(c *gin.Context) {
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
