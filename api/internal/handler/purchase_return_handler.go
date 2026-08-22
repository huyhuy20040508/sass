package handler

import (
	"errors"
	"net/http"
	"strconv"

	"github.com/gin-gonic/gin"

	"sass-api/internal/domain"
	"sass-api/internal/dto"
	"sass-api/internal/service"
	"sass-api/pkg/response"
)

type PurchaseReturnHandler struct {
	svc service.PurchaseReturnService
}

func NewPurchaseReturnHandler(svc service.PurchaseReturnService) *PurchaseReturnHandler {
	return &PurchaseReturnHandler{svc: svc}
}

// @Summary		Danh sách phiếu trả hàng nhập
// @Description	Liệt kê phiếu TRẢ HÀNG LẠI NHÀ CUNG CẤP (chiều ngược của nhập hàng). Lọc theo từ khoá (mã phiếu trả / mã phiếu đặt / nhà cung cấp / ghi chú), trạng thái, tình trạng hoàn tiền, lý do, nhà cung cấp và khoảng ngày lập phiếu.
// @Description	`status` nhận NHIỀU giá trị ngăn cách bởi dấu phẩy (VD: `draft,returned`).
// @Tags			Admin - Purchase Returns
// @Accept			json
// @Produce		json
// @Param			keyword			query		string	false	"Mã phiếu trả / mã phiếu đặt / nhà cung cấp / ghi chú"
// @Param			status			query		string	false	"all|draft|returned|refunded|cancelled — cho phép nhiều giá trị ngăn cách bởi dấu phẩy"
// @Param			refund_status	query		string	false	"all|unpaid|partial|paid"
// @Param			reason			query		string	false	"all|defect|wrong_item|over_stock|expired|other"
// @Param			from_date		query		string	false	"Từ ngày lập phiếu (YYYY-MM-DD)"
// @Param			to_date			query		string	false	"Đến ngày lập phiếu (YYYY-MM-DD)"
// @Param			sort			query		string	false	"newest|oldest|amount_desc|amount_asc"
// @Param			page			query		int		false	"Trang (mặc định 1)"
// @Param			page_size		query		int		false	"Số item/trang (mặc định 20, tối đa 100)"
// @Success		200				{object}	response.Body{data=[]domain.PurchaseReturn,meta=response.Pagination}
// @Failure		401				{object}	response.Body
// @Failure		500				{object}	response.Body
// @Security		BearerAuth
// @Router			/admin/purchase-returns [get]
func (h *PurchaseReturnHandler) List(c *gin.Context) {
	page, _ := strconv.Atoi(c.DefaultQuery("page", "1"))
	pageSize, _ := strconv.Atoi(c.DefaultQuery("page_size", "20"))
	if page < 1 {
		page = 1
	}
	if pageSize < 1 || pageSize > 100 {
		pageSize = 20
	}

	filter := domain.PurchaseReturnFilter{
		Keyword:      c.Query("keyword"),
		Status:       c.Query("status"),
		RefundStatus: c.Query("refund_status"),
		Reason:       c.Query("reason"),
		FromDate:     c.Query("from_date"),
		ToDate:       c.Query("to_date"),
		Sort:         c.Query("sort"),
		Page:         page,
		PageSize:     pageSize,
	}

	list, total, err := h.svc.List(c.Request.Context(), filter)
	if err != nil {
		response.Error(c, http.StatusInternalServerError, "Lỗi truy vấn danh sách phiếu trả hàng nhập")
		return
	}

	totalPages := 1
	if total > 0 {
		totalPages = int((total + int64(pageSize) - 1) / int64(pageSize))
	}
	response.Paginated(c, list, response.Pagination{
		Page:       page,
		PageSize:   pageSize,
		Total:      total,
		TotalPages: totalPages,
	})
}

// @Summary		Thống kê trả hàng nhập
// @Description	Đếm phiếu theo trạng thái, kèm số lượng và giá trị hàng ĐÃ TRẢ THẬT (phiếu nháp chưa trả, phiếu huỷ không tính) và số tiền nhà cung cấp CÒN PHẢI HOÀN.
// @Tags			Admin - Purchase Returns
// @Accept			json
// @Produce		json
// @Success		200	{object}	response.Body{data=domain.PurchaseReturnStats}
// @Failure		401	{object}	response.Body
// @Failure		500	{object}	response.Body
// @Security		BearerAuth
// @Router			/admin/purchase-returns/stats [get]
func (h *PurchaseReturnHandler) Stats(c *gin.Context) {
	stats, err := h.svc.Stats(c.Request.Context())
	if err != nil {
		response.Error(c, http.StatusInternalServerError, "Lỗi truy vấn thống kê trả hàng nhập")
		return
	}
	response.OK(c, stats)
}

// @Summary		Hàng còn trả lại được của một phiếu đặt
// @Description	Liệt kê các dòng của phiếu đặt hàng nhập còn có thể trả lại nhà cung cấp: chỉ dòng ĐÃ NHẬN hàng, `remain` = đã nhận − phần đã nằm trong các phiếu trả chưa huỷ (kể cả phiếu nháp, vì nháp đang giữ chỗ số hàng đó).
// @Description	`stock` là tồn kho hiện tại của biến thể — trả nhiều hơn số đang có trong kho sẽ bị chặn lúc chốt phiếu, vì kho bị trừ thật ở bước đó.
// @Tags			Admin - Purchase Returns
// @Accept			json
// @Produce		json
// @Param			id	path		int	true	"ID phiếu đặt hàng nhập"
// @Success		200	{object}	response.Body{data=[]domain.PurchaseReturnable}
// @Failure		401	{object}	response.Body
// @Failure		404	{object}	response.Body
// @Security		BearerAuth
// @Router			/admin/purchase-returns/returnable/{id} [get]
func (h *PurchaseReturnHandler) Returnable(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}

	list, err := h.svc.Returnable(c.Request.Context(), id)
	if err != nil {
		respondPurchaseReturnError(c, err, "Lỗi truy vấn hàng còn trả được")
		return
	}
	response.OK(c, list)
}

// @Summary		Chi tiết phiếu trả hàng nhập
// @Description	Phiếu kèm dòng hàng, lịch sử thao tác, các bước kế tiếp hợp lệ (`next_statuses`) và cờ `can_edit` / `can_refund` — giao diện hiện đúng nút được phép bấm, không tự đoán luật.
// @Tags			Admin - Purchase Returns
// @Accept			json
// @Produce		json
// @Param			id	path		int	true	"ID phiếu trả"
// @Success		200	{object}	response.Body{data=service.PurchaseReturnDetail}
// @Failure		401	{object}	response.Body
// @Failure		404	{object}	response.Body
// @Security		BearerAuth
// @Router			/admin/purchase-returns/{id} [get]
func (h *PurchaseReturnHandler) Get(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}

	detail, err := h.svc.GetByID(c.Request.Context(), id)
	if err != nil {
		respondPurchaseReturnError(c, err, "Lỗi truy vấn phiếu trả hàng")
		return
	}
	response.OK(c, detail)
}

// @Summary		Lập phiếu trả hàng nhập
// @Description	Tạo phiếu trả cho một phiếu đặt hàng nhập. Tên/SKU/tên biến thể/giá nhập do server chụp lại từ dòng phiếu đặt gốc, client chỉ gửi `purchase_order_item_id` + `quantity`.
// @Description	`status=returned` là "trả hàng ngay": phiếu được lập rồi chốt luôn trong một lần gọi — tồn kho bị TRỪ ở bước chốt đó. Bỏ trống hoặc `draft` thì chỉ lưu nháp, chưa đụng tới kho.
// @Description	Trả vượt số còn trả được của dòng phiếu đặt → 409. Kho không đủ hàng để trừ → 409.
// @Tags			Admin - Purchase Returns
// @Accept			json
// @Produce		json
// @Param			body	body		dto.PurchaseReturnRequest	true	"Thông tin phiếu trả"
// @Success		201		{object}	response.Body{data=service.PurchaseReturnDetail}
// @Failure		400		{object}	response.Body
// @Failure		404		{object}	response.Body
// @Failure		409		{object}	response.Body
// @Failure		422		{object}	response.Body
// @Security		BearerAuth
// @Router			/admin/purchase-returns [post]
func (h *PurchaseReturnHandler) Create(c *gin.Context) {
	var req dto.PurchaseReturnRequest
	if !bindJSON(c, &req) {
		return
	}

	detail, err := h.svc.Create(c.Request.Context(), &req, currentUserID(c))
	if err != nil {
		respondPurchaseReturnError(c, err, "Lỗi lập phiếu trả hàng")
		return
	}
	response.Created(c, detail)
}

// @Summary		Sửa phiếu trả hàng nhập
// @Description	Chỉ sửa được phiếu còn NHÁP (chưa trừ kho); phiếu đã trả hàng thì khoá — hàng đã ra khỏi kho. Danh sách dòng hàng gửi lên THAY TOÀN BỘ dòng cũ.
// @Tags			Admin - Purchase Returns
// @Accept			json
// @Produce		json
// @Param			id		path		int							true	"ID phiếu trả"
// @Param			body	body		dto.PurchaseReturnRequest	true	"Thông tin phiếu trả"
// @Success		200		{object}	response.Body{data=service.PurchaseReturnDetail}
// @Failure		404		{object}	response.Body
// @Failure		409		{object}	response.Body
// @Failure		422		{object}	response.Body
// @Security		BearerAuth
// @Router			/admin/purchase-returns/{id} [put]
func (h *PurchaseReturnHandler) Update(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}

	var req dto.PurchaseReturnRequest
	if !bindJSON(c, &req) {
		return
	}

	detail, err := h.svc.Update(c.Request.Context(), id, &req, currentUserID(c))
	if err != nil {
		respondPurchaseReturnError(c, err, "Lỗi cập nhật phiếu trả hàng")
		return
	}
	response.OKMessage(c, "Đã cập nhật phiếu trả hàng", detail)
}

// @Summary		Chuyển trạng thái phiếu trả hàng nhập
// @Description	`returned` = đã trả hàng cho nhà cung cấp: TRỪ tồn kho và ghi bút toán sổ kho (`type=export`, `reference_type=purchase_return`) — chỉ chạy được một lần, kho không đủ hàng thì trả 409 và không ghi gì.
// @Description	`refunded` = nhà cung cấp đã hoàn đủ tiền (ghi luôn `refund_amount` = tiền hàng). `cancelled` = huỷ phiếu, BẮT BUỘC có `note` làm lý do và chỉ huỷ được phiếu còn nháp.
// @Tags			Admin - Purchase Returns
// @Accept			json
// @Produce		json
// @Param			id		path		int									true	"ID phiếu trả"
// @Param			body	body		dto.PurchaseReturnStatusRequest		true	"Trạng thái mới"
// @Success		200		{object}	response.Body{data=service.PurchaseReturnDetail}
// @Failure		404		{object}	response.Body
// @Failure		409		{object}	response.Body
// @Failure		422		{object}	response.Body
// @Security		BearerAuth
// @Router			/admin/purchase-returns/{id}/status [put]
func (h *PurchaseReturnHandler) UpdateStatus(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}

	var req dto.PurchaseReturnStatusRequest
	if !bindJSON(c, &req) {
		return
	}

	detail, err := h.svc.UpdateStatus(c.Request.Context(), id, req.Status, req.Note, currentUserID(c))
	if err != nil {
		respondPurchaseReturnError(c, err, "Lỗi chuyển trạng thái phiếu trả hàng")
		return
	}
	response.OKMessage(c, "Đã cập nhật phiếu trả hàng", detail)
}

// @Summary		Ghi nhận nhà cung cấp hoàn tiền
// @Description	`refund_amount` là TỔNG số tiền nhà cung cấp đã hoàn/đối trừ (luỹ kế), không phải số vừa hoàn thêm. Hoàn đủ thì phiếu tự chuyển sang "đã hoàn tiền".
// @Description	Phiếu còn nháp (chưa trả hàng) hoặc đã huỷ thì không ghi được: chưa có gì để đòi / sổ đã đóng.
// @Tags			Admin - Purchase Returns
// @Accept			json
// @Produce		json
// @Param			id		path		int									true	"ID phiếu trả"
// @Param			body	body		dto.PurchaseReturnRefundRequest		true	"Số tiền đã hoàn (luỹ kế)"
// @Success		200		{object}	response.Body{data=service.PurchaseReturnDetail}
// @Failure		404		{object}	response.Body
// @Failure		409		{object}	response.Body
// @Failure		422		{object}	response.Body
// @Security		BearerAuth
// @Router			/admin/purchase-returns/{id}/refund [put]
func (h *PurchaseReturnHandler) UpdateRefund(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}

	var req dto.PurchaseReturnRefundRequest
	if !bindJSON(c, &req) {
		return
	}

	detail, err := h.svc.UpdateRefund(c.Request.Context(), id, req.RefundAmount, currentUserID(c))
	if err != nil {
		respondPurchaseReturnError(c, err, "Lỗi ghi nhận hoàn tiền")
		return
	}
	response.OKMessage(c, "Đã ghi nhận nhà cung cấp hoàn tiền", detail)
}

// @Summary		Xoá phiếu trả hàng nhập
// @Description	Chỉ xoá được phiếu còn NHÁP (chưa trừ kho). Phiếu đã trả hàng phải giữ lại trong sổ — muốn dừng thì không có đường xoá, vì bút toán kho đã ghi.
// @Tags			Admin - Purchase Returns
// @Accept			json
// @Produce		json
// @Param			id	path		int	true	"ID phiếu trả"
// @Success		200	{object}	response.Body
// @Failure		404	{object}	response.Body
// @Failure		409	{object}	response.Body
// @Security		BearerAuth
// @Router			/admin/purchase-returns/{id} [delete]
func (h *PurchaseReturnHandler) Delete(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}

	if err := h.svc.Delete(c.Request.Context(), id); err != nil {
		respondPurchaseReturnError(c, err, "Lỗi xoá phiếu trả hàng")
		return
	}
	response.OKMessage(c, "Đã xoá phiếu trả hàng nháp", nil)
}

func respondPurchaseReturnError(c *gin.Context, err error, fallback string) {
	switch {
	case errors.Is(err, domain.ErrNotFound):
		response.Error(c, http.StatusNotFound, "Không tìm thấy phiếu trả hàng hoặc dòng hàng cần trả")
	case errors.Is(err, domain.ErrPurchaseReturnEmpty):
		response.Error(c, http.StatusUnprocessableEntity, domain.ErrPurchaseReturnEmpty.Error())
	case errors.Is(err, domain.ErrPurchaseReturnNoReason):
		response.Error(c, http.StatusUnprocessableEntity, domain.ErrPurchaseReturnNoReason.Error())
	case errors.Is(err, domain.ErrPurchaseReturnQtyExceeded):
		response.Error(c, http.StatusConflict, domain.ErrPurchaseReturnQtyExceeded.Error())
	case errors.Is(err, domain.ErrOutOfStock):
		response.Error(c, http.StatusConflict,
			"Tồn kho không đủ để trả lại số lượng này — kiểm tra lại tồn kho của các dòng hàng")
	case errors.Is(err, domain.ErrPurchaseReturnLocked):
		response.Error(c, http.StatusConflict, domain.ErrPurchaseReturnLocked.Error())
	default:
		response.Error(c, http.StatusInternalServerError, fallback)
	}
}
