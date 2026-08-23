package handler

import (
	"strconv"

	"github.com/gin-gonic/gin"

	"sass-api/internal/domain"
	"sass-api/internal/dto"
	"sass-api/internal/service"
	"sass-api/pkg/response"
)

// soTrang — số trang, tối thiểu 1 để trang rỗng vẫn đọc lên là "1/1".
func soTrang(tong int64, size int) int {
	if size < 1 {
		size = 20
	}
	n := int((tong + int64(size) - 1) / int64(size))
	if n < 1 {
		return 1
	}

	return n
}

// PhieuMuaHangHandler — Quản lý kho → Phiếu mua hàng.
type PhieuMuaHangHandler struct {
	svc service.PhieuMuaHangService
}

func NewPhieuMuaHangHandler(svc service.PhieuMuaHangService) *PhieuMuaHangHandler {
	return &PhieuMuaHangHandler{svc: svc}
}

// List godoc
//
//	@Summary		Danh sách phiếu mua hàng
//	@Description	Lọc theo từ khoá (mã phiếu / bên bán / ghi chú), trạng thái, tình trạng thanh toán, nhà cung cấp, mặt hàng và khoảng ngày lập.
//	@Description	`status` và `payment_status` nhận nhiều giá trị ngăn bởi dấu phẩy — bộ lọc ngoài bảng là các ô tick.
//	@Tags			Admin - Phiếu mua hàng
//	@Produce		json
//	@Security		BearerAuth
//	@Param			keyword			query		string	false	"Mã phiếu, tên bên bán hoặc ghi chú"
//	@Param			status			query		string	false	"draft | approved | cancelled (ngăn bởi dấu phẩy)"
//	@Param			payment_status	query		string	false	"unpaid | partial | paid (ngăn bởi dấu phẩy)"
//	@Param			supplier_id		query		int		false	"Lọc theo nhà cung cấp"
//	@Param			variant_id		query		int		false	"Chỉ phiếu có chứa mặt hàng này"
//	@Param			from_date		query		string	false	"YYYY-MM-DD"
//	@Param			to_date			query		string	false	"YYYY-MM-DD"
//	@Param			sort			query		string	false	"newest | oldest | total_desc | total_asc | document_desc"
//	@Param			page			query		int		false	"Trang, mặc định 1"
//	@Param			page_size		query		int		false	"Số dòng mỗi trang, mặc định 20, tối đa 100"
//	@Success		200				{object}	response.Body{data=[]domain.PurchaseOrder}
//	@Failure		401				{object}	response.Body
//	@Router			/admin/phieu-mua-hang [get]
func (h *PhieuMuaHangHandler) List(c *gin.Context) {
	page, _ := strconv.Atoi(c.DefaultQuery("page", "1"))
	size, _ := strconv.Atoi(c.DefaultQuery("page_size", "20"))
	if page < 1 {
		page = 1
	}
	// Kẹp trần như mọi đường danh sách khác. Không kẹp thì một lượt gọi
	// page_size=1000000 quét sạch bảng, mà truy vấn này còn Preload cả dòng hàng.
	if size < 1 || size > 100 {
		size = 20
	}
	supplierID, _ := strconv.ParseUint(c.Query("supplier_id"), 10, 64)
	variantID, _ := strconv.ParseUint(c.Query("variant_id"), 10, 64)

	list, tong, err := h.svc.List(c.Request.Context(), domain.PurchaseFilter{
		Keyword:       c.Query("keyword"),
		Status:        c.Query("status"),
		PaymentStatus: c.Query("payment_status"),
		SupplierID:    uint(supplierID),
		VariantID:     uint(variantID),
		FromDate:      c.Query("from_date"),
		ToDate:        c.Query("to_date"),
		Sort:          c.Query("sort"),
		Page:          page,
		PageSize:      size,
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
//	@Summary		Con số đầu trang phiếu mua hàng
//	@Description	Đếm phiếu theo trạng thái, tiền hàng đã mua và tiền còn nợ nhà cung cấp. Tiền chỉ cộng trên phiếu ĐÃ DUYỆT.
//	@Tags			Admin - Phiếu mua hàng
//	@Produce		json
//	@Security		BearerAuth
//	@Success		200	{object}	response.Body{data=domain.PurchaseStats}
//	@Failure		401	{object}	response.Body
//	@Router			/admin/phieu-mua-hang/stats [get]
func (h *PhieuMuaHangHandler) Stats(c *gin.Context) {
	s, err := h.svc.Stats(c.Request.Context())
	if err != nil {
		handleServiceError(c, err)

		return
	}

	response.OK(c, s)
}

// Variants godoc
//
//	@Summary		Tìm mặt hàng để đưa vào phiếu
//	@Description	Mỗi dòng kèm giá vốn gợi ý, tồn của ĐÚNG kho sẽ nhận hàng, thuế suất của mặt hàng và danh sách đơn vị mua được (đơn vị chính + khối quy đổi).
//	@Tags			Admin - Phiếu mua hàng
//	@Produce		json
//	@Security		BearerAuth
//	@Param			keyword		query	string	false	"Tên hàng hoặc SKU"
//	@Param			category_id	query	int		false	"Chỉ hàng thuộc nhóm này"
//	@Param			limit		query	int		false	"Số dòng tối đa, mặc định 20"
//	@Success		200		{object}	response.Body{data=[]domain.PurchaseVariant}
//	@Failure		401		{object}	response.Body
//	@Router			/admin/phieu-mua-hang/mat-hang [get]
func (h *PhieuMuaHangHandler) Variants(c *gin.Context) {
	limit, _ := strconv.Atoi(c.DefaultQuery("limit", "20"))
	categoryID, _ := strconv.ParseUint(c.Query("category_id"), 10, 64)

	list, err := h.svc.SearchVariants(c.Request.Context(), c.Query("keyword"), uint(categoryID), limit)
	if err != nil {
		handleServiceError(c, err)

		return
	}

	response.OK(c, list)
}

// NhomHang godoc
//
//	@Summary		Nhóm hàng có hàng mua được
//	@Description	CHỈ những nhóm đang có ít nhất một mặt hàng còn bán — ô lọc nhóm trong hộp lập phiếu đổ từ đây. Nhóm rỗng không bày ra, vì chọn vào chỉ ra bảng trắng.
//	@Tags			Admin - Phiếu mua hàng
//	@Produce		json
//	@Security		BearerAuth
//	@Success		200	{object}	response.Body{data=[]domain.PurchaseNhomHang}
//	@Failure		401	{object}	response.Body
//	@Router			/admin/phieu-mua-hang/nhom-hang [get]
func (h *PhieuMuaHangHandler) NhomHang(c *gin.Context) {
	list, err := h.svc.NhomHangCoHang(c.Request.Context())
	if err != nil {
		handleServiceError(c, err)

		return
	}

	response.OK(c, list)
}

// Get godoc
//
//	@Summary	Chi tiết một phiếu mua hàng
//	@Tags		Admin - Phiếu mua hàng
//	@Produce	json
//	@Security	BearerAuth
//	@Param		id	path		int	true	"ID phiếu"
//	@Success	200	{object}	response.Body{data=service.PhieuMuaHangDetail}
//	@Failure	404	{object}	response.Body
//	@Router		/admin/phieu-mua-hang/{id} [get]
func (h *PhieuMuaHangHandler) Get(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}

	po, err := h.svc.GetByID(c.Request.Context(), id)
	if err != nil {
		handleServiceError(c, err)

		return
	}

	response.OK(c, po)
}

// Create godoc
//
//	@Summary		Lập phiếu mua hàng
//	@Description	Phiếu lập ra luôn là phiếu LƯU TẠM, chưa đụng tới kho. Muốn hàng vào kho thì gọi tiếp POST {id}/duyet — đường riêng vì nó có quyền riêng canh.
//	@Tags			Admin - Phiếu mua hàng
//	@Accept			json
//	@Produce		json
//	@Security		BearerAuth
//	@Param			body	body		dto.PurchaseCreateRequest	true	"Nội dung phiếu"
//	@Success		201		{object}	response.Body{data=service.PhieuMuaHangDetail}
//	@Failure		403		{object}	response.Body
//	@Failure		422		{object}	response.Body
//	@Router			/admin/phieu-mua-hang [post]
func (h *PhieuMuaHangHandler) Create(c *gin.Context) {
	var req dto.PurchaseCreateRequest
	if !bindJSON(c, &req) {
		return
	}

	po, err := h.svc.Create(c.Request.Context(), &req, currentUserID(c))
	if err != nil {
		handleServiceError(c, err)

		return
	}

	response.CreatedMessage(c, "Đã lập phiếu mua hàng", po)
}

// Update godoc
//
//	@Summary		Sửa phiếu mua hàng
//	@Description	Chỉ phiếu LƯU TẠM sửa được. Phiếu đã duyệt là kho đã đổi theo nó nên khoá lại — muốn chữa số đã vào kho thì cân đối ở màn Tồn kho.
//	@Tags			Admin - Phiếu mua hàng
//	@Accept			json
//	@Produce		json
//	@Security		BearerAuth
//	@Param			id		path		int							true	"ID phiếu"
//	@Param			body	body		dto.PurchaseUpdateRequest	true	"Nội dung phiếu"
//	@Success		200		{object}	response.Body{data=service.PhieuMuaHangDetail}
//	@Failure		409		{object}	response.Body
//	@Failure		422		{object}	response.Body
//	@Router			/admin/phieu-mua-hang/{id} [put]
func (h *PhieuMuaHangHandler) Update(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}

	var req dto.PurchaseUpdateRequest
	if !bindJSON(c, &req) {
		return
	}

	po, err := h.svc.Update(c.Request.Context(), id, &req, currentUserID(c))
	if err != nil {
		handleServiceError(c, err)

		return
	}

	response.OKMessage(c, "Đã cập nhật phiếu mua hàng", po)
}

// Approve godoc
//
//	@Summary		Duyệt phiếu và nhập kho
//	@Description	Lúc DUY NHẤT phiếu mua chạm vào tồn kho: cộng hàng vào kho của chi nhánh đã lập phiếu và ghi bút toán sổ kho. Duyệt xong phiếu khoá lại.
//	@Description	`update_cost` bỏ trống = true: giá vốn mặt hàng nhận luôn giá vừa mua (đã quy về đơn vị tính chính).
//	@Tags			Admin - Phiếu mua hàng
//	@Accept			json
//	@Produce		json
//	@Security		BearerAuth
//	@Param			id		path		int							true	"ID phiếu"
//	@Param			body	body		dto.PurchaseApproveRequest	false	"Tuỳ chọn lượt duyệt"
//	@Success		200		{object}	response.Body{data=service.PhieuMuaHangDetail}
//	@Failure		409		{object}	response.Body
//	@Router			/admin/phieu-mua-hang/{id}/duyet [post]
func (h *PhieuMuaHangHandler) Approve(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}

	// Thân rỗng là hợp lệ: bấm Duyệt không kèm tuỳ chọn nào là đường đi thường ngày.
	var req dto.PurchaseApproveRequest
	if c.Request.ContentLength > 0 && !bindJSON(c, &req) {
		return
	}

	po, err := h.svc.Approve(c.Request.Context(), id, &req, currentUserID(c))
	if err != nil {
		handleServiceError(c, err)

		return
	}

	response.OKMessage(c, "Đã duyệt phiếu — hàng đã vào kho", po)
}

// Cancel godoc
//
//	@Summary		Huỷ phiếu mua hàng
//	@Description	Chỉ huỷ được phiếu lưu tạm, và phải nói lý do — vài tuần sau không ai nhớ vì sao phiếu chết.
//	@Tags			Admin - Phiếu mua hàng
//	@Accept			json
//	@Produce		json
//	@Security		BearerAuth
//	@Param			id		path		int							true	"ID phiếu"
//	@Param			body	body		dto.PurchaseCancelRequest	true	"Lý do huỷ"
//	@Success		200		{object}	response.Body{data=service.PhieuMuaHangDetail}
//	@Failure		409		{object}	response.Body
//	@Router			/admin/phieu-mua-hang/{id}/huy [post]
func (h *PhieuMuaHangHandler) Cancel(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}

	var req dto.PurchaseCancelRequest
	if !bindJSON(c, &req) {
		return
	}

	po, err := h.svc.Cancel(c.Request.Context(), id, &req, currentUserID(c))
	if err != nil {
		handleServiceError(c, err)

		return
	}

	response.OKMessage(c, "Đã huỷ phiếu mua hàng", po)
}

// Pay godoc
//
//	@Summary		Ghi nhận tiền đã trả nhà cung cấp
//	@Description	`paid_amount` là số LUỸ KẾ đã trả cho phiếu, không phải số vừa trả thêm. Server so với tổng tiền ĐANG lưu chứ không tin con số client gửi kèm.
//	@Tags			Admin - Phiếu mua hàng
//	@Accept			json
//	@Produce		json
//	@Security		BearerAuth
//	@Param			id		path		int							true	"ID phiếu"
//	@Param			body	body		dto.PurchasePaymentRequest	true	"Số đã trả"
//	@Success		200		{object}	response.Body{data=service.PhieuMuaHangDetail}
//	@Failure		409		{object}	response.Body
//	@Failure		422		{object}	response.Body
//	@Router			/admin/phieu-mua-hang/{id}/thanh-toan [post]
func (h *PhieuMuaHangHandler) Pay(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}

	var req dto.PurchasePaymentRequest
	if !bindJSON(c, &req) {
		return
	}

	po, err := h.svc.Pay(c.Request.Context(), id, &req, currentUserID(c))
	if err != nil {
		handleServiceError(c, err)

		return
	}

	response.OKMessage(c, "Đã ghi nhận thanh toán", po)
}

// Delete godoc
//
//	@Summary		Xoá phiếu mua hàng
//	@Description	Chỉ xoá được phiếu lưu tạm. Phiếu đã duyệt nằm lại trong sổ vì kho đã đổi theo nó; phiếu đã huỷ nằm lại để còn đọc được lý do.
//	@Tags			Admin - Phiếu mua hàng
//	@Produce		json
//	@Security		BearerAuth
//	@Param			id	path		int	true	"ID phiếu"
//	@Success		200	{object}	response.Body
//	@Failure		409	{object}	response.Body
//	@Router			/admin/phieu-mua-hang/{id} [delete]
func (h *PhieuMuaHangHandler) Delete(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}

	if err := h.svc.Delete(c.Request.Context(), id); err != nil {
		handleServiceError(c, err)

		return
	}

	response.OKMessage(c, "Đã xoá phiếu mua hàng", nil)
}
