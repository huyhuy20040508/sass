package handler

import (
	"strconv"

	"github.com/gin-gonic/gin"

	"sass-api/internal/domain"
	"sass-api/internal/dto"
	"sass-api/internal/service"
	"sass-api/pkg/response"
)

// TraHangNCCHandler — Quản lý kho → Trả hàng nhà cung cấp.
type TraHangNCCHandler struct {
	svc service.TraHangNCCService
}

func NewTraHangNCCHandler(svc service.TraHangNCCService) *TraHangNCCHandler {
	return &TraHangNCCHandler{svc: svc}
}

// List godoc
//
//	@Summary		Danh sách phiếu trả hàng nhà cung cấp
//	@Description	Lọc theo từ khoá (mã phiếu / mã hoặc tên bên bán / ghi chú), trạng thái, nhà cung cấp và khoảng ngày lập.
//	@Description	`status` nhận nhiều giá trị ngăn bởi dấu phẩy — bộ lọc ngoài bảng là các ô tick.
//	@Tags			Admin - Trả hàng nhà cung cấp
//	@Produce		json
//	@Security		BearerAuth
//	@Param			keyword		query		string	false	"Mã phiếu, mã/tên bên bán hoặc ghi chú"
//	@Param			status		query		string	false	"draft | approved (ngăn bởi dấu phẩy)"
//	@Param			supplier_id	query		int		false	"Lọc theo nhà cung cấp"
//	@Param			from_date	query		string	false	"YYYY-MM-DD"
//	@Param			to_date		query		string	false	"YYYY-MM-DD"
//	@Param			sort		query		string	false	"newest | oldest | total_desc | total_asc | document_desc"
//	@Param			page		query		int		false	"Trang, mặc định 1"
//	@Param			page_size	query		int		false	"Số dòng mỗi trang, mặc định 20, tối đa 100"
//	@Success		200			{object}	response.Body{data=[]domain.SupplierReturn}
//	@Failure		401			{object}	response.Body
//	@Router			/admin/tra-hang-nha-cung-cap [get]
func (h *TraHangNCCHandler) List(c *gin.Context) {
	page, _ := strconv.Atoi(c.DefaultQuery("page", "1"))
	size, _ := strconv.Atoi(c.DefaultQuery("page_size", "20"))
	if page < 1 {
		page = 1
	}
	// Kẹp trần như mọi đường danh sách khác: truy vấn này còn Preload cả dòng hàng.
	if size < 1 || size > 100 {
		size = 20
	}
	supplierID, _ := strconv.ParseUint(c.Query("supplier_id"), 10, 64)

	list, tong, err := h.svc.List(c.Request.Context(), domain.SupplierReturnFilter{
		Keyword:    c.Query("keyword"),
		Status:     c.Query("status"),
		SupplierID: uint(supplierID),
		ShopID:     chiNhanhLoc(c),
		FromDate:   c.Query("from_date"),
		ToDate:     c.Query("to_date"),
		Sort:       c.Query("sort"),
		Page:       page,
		PageSize:   size,
	})
	if err != nil {
		handleServiceError(c, err)

		return
	}

	response.Paginated(c, list, response.Pagination{
		Page:       page,
		PageSize:   size,
		Total:      tong,
		TotalPages: soTrang(tong, size),
	})
}

// Stats godoc
//
//	@Summary		Con số đầu trang trả hàng nhà cung cấp
//	@Description	Đếm phiếu theo trạng thái và tổng tiền đã trả lại. Tiền chỉ cộng trên phiếu ĐÃ DUYỆT.
//	@Tags			Admin - Trả hàng nhà cung cấp
//	@Produce		json
//	@Security		BearerAuth
//	@Success		200	{object}	response.Body{data=domain.SupplierReturnStats}
//	@Failure		401	{object}	response.Body
//	@Router			/admin/tra-hang-nha-cung-cap/stats [get]
func (h *TraHangNCCHandler) Stats(c *gin.Context) {
	s, err := h.svc.Stats(c.Request.Context())
	if err != nil {
		handleServiceError(c, err)

		return
	}

	response.OK(c, s)
}

// PhieuMua godoc
//
//	@Summary		Phiếu mua trả hàng được của một nhà cung cấp
//	@Description	Chỉ phiếu ĐÃ DUYỆT: phiếu lưu tạm chưa đưa hàng vào kho nên chưa có gì để trả.
//	@Tags			Admin - Trả hàng nhà cung cấp
//	@Produce		json
//	@Security		BearerAuth
//	@Param			supplier_id	query		int	true	"ID nhà cung cấp"
//	@Param			limit		query		int	false	"Số phiếu tối đa, mặc định 200"
//	@Success		200			{object}	response.Body{data=[]domain.SupplierReturnPurchase}
//	@Failure		422			{object}	response.Body
//	@Router			/admin/tra-hang-nha-cung-cap/phieu-mua [get]
func (h *TraHangNCCHandler) PhieuMua(c *gin.Context) {
	supplierID, _ := strconv.ParseUint(c.Query("supplier_id"), 10, 64)
	if supplierID == 0 {
		response.Error(c, 422, "Chưa chọn nhà cung cấp")

		return
	}
	limit, _ := strconv.Atoi(c.DefaultQuery("limit", "200"))

	ds, err := h.svc.PhieuMua(c.Request.Context(), uint(supplierID), limit)
	if err != nil {
		handleServiceError(c, err)

		return
	}

	response.OK(c, ds)
}

// DongPhieuMua godoc
//
//	@Summary		Dòng hàng của một phiếu mua, kèm phần còn trả được
//	@Description	Mỗi dòng trả kèm `returned` (đã trả ở các phiếu ĐÃ DUYỆT), `stock` (tồn còn lại tại kho của phiếu mua, quy về đơn vị của dòng) và `returnable` = min(mua - đã trả, tồn).
//	@Description	`returnable` là con số màn hình kẹp ô nhập, và cũng là con số API kiểm lại lúc lưu rồi kiểm lần nữa lúc duyệt.
//	@Tags			Admin - Trả hàng nhà cung cấp
//	@Produce		json
//	@Security		BearerAuth
//	@Param			purchase_id	query		int	true	"ID phiếu mua"
//	@Success		200			{object}	response.Body{data=domain.SupplierReturnPurchaseDetail}
//	@Failure		404			{object}	response.Body
//	@Failure		422			{object}	response.Body
//	@Router			/admin/tra-hang-nha-cung-cap/dong-phieu-mua [get]
func (h *TraHangNCCHandler) DongPhieuMua(c *gin.Context) {
	purchaseID, _ := strconv.ParseUint(c.Query("purchase_id"), 10, 64)
	if purchaseID == 0 {
		response.Error(c, 422, "Chưa chọn phiếu mua")

		return
	}

	ct, err := h.svc.DongPhieuMua(c.Request.Context(), uint(purchaseID))
	if err != nil {
		handleServiceError(c, err)

		return
	}

	response.OK(c, ct)
}

// Get godoc
//
//	@Summary		Chi tiết phiếu trả hàng
//	@Description	Kèm dòng hàng, lịch sử thao tác và hai cờ `can_edit` / `can_approve` để trang quản trị dựng nút mà không phải chép lại luật.
//	@Tags			Admin - Trả hàng nhà cung cấp
//	@Produce		json
//	@Security		BearerAuth
//	@Param			id	path		int	true	"ID phiếu"
//	@Success		200	{object}	response.Body{data=service.TraHangNCCDetail}
//	@Failure		404	{object}	response.Body
//	@Router			/admin/tra-hang-nha-cung-cap/{id} [get]
func (h *TraHangNCCHandler) Get(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}

	sr, err := h.svc.GetByID(c.Request.Context(), id)
	if err != nil {
		handleServiceError(c, err)

		return
	}

	response.OK(c, sr)
}

// Create godoc
//
//	@Summary		Lập phiếu trả hàng nhà cung cấp
//	@Description	Phiếu lập ra LUÔN là phiếu lưu tạm, chưa đụng tới kho. Muốn trừ kho thì gọi tiếp POST {id}/duyet.
//	@Description	Client chỉ gửi `purchase_item_id` và `quantity`: giá nhập, đơn vị, số lô, thuế suất đều lấy lại từ dòng phiếu mua gốc.
//	@Tags			Admin - Trả hàng nhà cung cấp
//	@Accept			json
//	@Produce		json
//	@Security		BearerAuth
//	@Param			body	body		dto.SupplierReturnCreateRequest	true	"Nội dung phiếu"
//	@Success		201		{object}	response.Body{data=service.TraHangNCCDetail}
//	@Failure		403		{object}	response.Body
//	@Failure		422		{object}	response.Body
//	@Router			/admin/tra-hang-nha-cung-cap [post]
func (h *TraHangNCCHandler) Create(c *gin.Context) {
	var req dto.SupplierReturnCreateRequest
	if !bindJSON(c, &req) {
		return
	}

	sr, err := h.svc.Create(c.Request.Context(), &req, currentUserID(c))
	if err != nil {
		handleServiceError(c, err)

		return
	}

	response.CreatedMessage(c, "Đã lập phiếu trả hàng", sr)
}

// Update godoc
//
//	@Summary		Sửa phiếu trả hàng
//	@Description	Chỉ phiếu LƯU TẠM sửa được. Phiếu đã duyệt là kho đã trừ theo nó nên khoá lại.
//	@Tags			Admin - Trả hàng nhà cung cấp
//	@Accept			json
//	@Produce		json
//	@Security		BearerAuth
//	@Param			id		path		int								true	"ID phiếu"
//	@Param			body	body		dto.SupplierReturnUpdateRequest	true	"Nội dung phiếu"
//	@Success		200		{object}	response.Body{data=service.TraHangNCCDetail}
//	@Failure		409		{object}	response.Body
//	@Failure		422		{object}	response.Body
//	@Router			/admin/tra-hang-nha-cung-cap/{id} [put]
func (h *TraHangNCCHandler) Update(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}

	var req dto.SupplierReturnUpdateRequest
	if !bindJSON(c, &req) {
		return
	}

	sr, err := h.svc.Update(c.Request.Context(), id, &req, currentUserID(c))
	if err != nil {
		handleServiceError(c, err)

		return
	}

	response.OKMessage(c, "Đã cập nhật phiếu trả hàng", sr)
}

// Approve godoc
//
//	@Summary		Duyệt phiếu trả và xuất kho
//	@Description	Lúc DUY NHẤT phiếu trả chạm vào tồn kho: trừ hàng khỏi kho của chi nhánh đã lập phiếu và ghi bút toán sổ kho (type='export', reference_type='supplier_return'). Duyệt xong phiếu khoá lại.
//	@Description	Trần trả hàng được kiểm LẠI ngay trước khi trừ kho — giữa lúc lập phiếu và lúc bấm duyệt, một phiếu trả khác có thể đã ăn hết phần còn lại.
//	@Tags			Admin - Trả hàng nhà cung cấp
//	@Accept			json
//	@Produce		json
//	@Security		BearerAuth
//	@Param			id		path		int								true	"ID phiếu"
//	@Param			body	body		dto.SupplierReturnApproveRequest	false	"Ghi chú lượt duyệt"
//	@Success		200		{object}	response.Body{data=service.TraHangNCCDetail}
//	@Failure		409		{object}	response.Body
//	@Failure		422		{object}	response.Body
//	@Router			/admin/tra-hang-nha-cung-cap/{id}/duyet [post]
func (h *TraHangNCCHandler) Approve(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}

	// Thân rỗng là hợp lệ: bấm Duyệt không kèm ghi chú là đường đi thường ngày.
	var req dto.SupplierReturnApproveRequest
	if c.Request.ContentLength > 0 && !bindJSON(c, &req) {
		return
	}

	sr, err := h.svc.Approve(c.Request.Context(), id, &req, currentUserID(c))
	if err != nil {
		handleServiceError(c, err)

		return
	}

	response.OKMessage(c, "Đã duyệt phiếu trả — hàng đã rời kho", sr)
}

// Delete godoc
//
//	@Summary		Xoá phiếu trả hàng
//	@Description	Chỉ xoá được phiếu lưu tạm. Phiếu đã duyệt nằm lại trong sổ vì kho đã đổi theo nó.
//	@Tags			Admin - Trả hàng nhà cung cấp
//	@Produce		json
//	@Security		BearerAuth
//	@Param			id	path		int	true	"ID phiếu"
//	@Success		200	{object}	response.Body
//	@Failure		409	{object}	response.Body
//	@Router			/admin/tra-hang-nha-cung-cap/{id} [delete]
func (h *TraHangNCCHandler) Delete(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}

	if err := h.svc.Delete(c.Request.Context(), id); err != nil {
		handleServiceError(c, err)

		return
	}

	response.OKMessage(c, "Đã xoá phiếu trả hàng", nil)
}
