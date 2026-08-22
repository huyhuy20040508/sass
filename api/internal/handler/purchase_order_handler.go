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

type PurchaseOrderHandler struct {
	svc service.PurchaseOrderService
}

func NewPurchaseOrderHandler(svc service.PurchaseOrderService) *PurchaseOrderHandler {
	return &PurchaseOrderHandler{svc: svc}
}

// @Summary		Danh sách phiếu đặt hàng nhập
// @Description	Lọc theo từ khoá (mã phiếu / tên nhà cung cấp / ghi chú), trạng thái phiếu, tình trạng thanh toán, nhà cung cấp và khoảng ngày tạo; hỗ trợ sắp xếp và phân trang. Tham số `status` nhận NHIỀU trạng thái ngăn cách bởi dấu phẩy (VD: `ordered,partial`) để lấy nhóm phiếu "đang chờ hàng".
// @Tags			Admin - Purchases
// @Accept			json
// @Produce		json
// @Param			keyword			query		string	false	"Mã phiếu / tên nhà cung cấp / ghi chú"
// @Param			status			query		string	false	"all|draft|ordered|partial|received|cancelled — cho phép nhiều giá trị ngăn cách bởi dấu phẩy"
// @Param			payment_status	query		string	false	"all|unpaid|partial|paid"
// @Param			from_date		query		string	false	"Từ ngày (YYYY-MM-DD)"
// @Param			to_date			query		string	false	"Đến ngày (YYYY-MM-DD)"
// @Param			sort			query		string	false	"newest|oldest|total_desc|total_asc|expected_asc"
// @Param			page			query		int		false	"Trang (mặc định 1)"
// @Param			page_size		query		int		false	"Số item/trang (mặc định 20, tối đa 100)"
// @Success		200				{object}	response.Body{meta=response.Pagination}
// @Failure		401				{object}	response.Body
// @Failure		500				{object}	response.Body
// @Security		BearerAuth
// @Router			/admin/purchases [get]
func (h *PurchaseOrderHandler) List(c *gin.Context) {
	page, _ := strconv.Atoi(c.DefaultQuery("page", "1"))
	pageSize, _ := strconv.Atoi(c.DefaultQuery("page_size", "20"))
	if page < 1 {
		page = 1
	}
	if pageSize < 1 || pageSize > 100 {
		pageSize = 20
	}

	filter := domain.PurchaseFilter{
		Keyword:       c.Query("keyword"),
		Status:        c.Query("status"),
		PaymentStatus: c.Query("payment_status"),
		FromDate:      c.Query("from_date"),
		ToDate:        c.Query("to_date"),
		Sort:          c.Query("sort"),
		Page:          page,
		PageSize:      pageSize,
	}

	list, total, err := h.svc.List(c.Request.Context(), filter)
	if err != nil {
		response.Error(c, http.StatusInternalServerError, "Lỗi truy vấn danh sách phiếu đặt hàng")
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

// @Summary		Thống kê đặt hàng nhập
// @Description	Đếm phiếu theo trạng thái, kèm SỐ HÀNG ĐANG TRÊN ĐƯỜNG (phần còn thiếu của các phiếu đang chờ, không phải cả số đặt), giá trị lô hàng đó theo giá nhập, và công nợ còn phải trả nhà cung cấp.
// @Tags			Admin - Purchases
// @Accept			json
// @Produce		json
// @Success		200	{object}	response.Body{data=domain.PurchaseStats}
// @Failure		401	{object}	response.Body
// @Security		BearerAuth
// @Router			/admin/purchases/stats [get]
func (h *PurchaseOrderHandler) Stats(c *gin.Context) {
	stats, err := h.svc.Stats(c.Request.Context())
	if err != nil {
		response.Error(c, http.StatusInternalServerError, "Lỗi thống kê đặt hàng nhập")
		return
	}
	response.OK(c, stats)
}

// @Summary		Tìm sản phẩm để đưa vào phiếu đặt hàng
// @Description	Trả về BIẾN THỂ đang bán kèm tồn kho hiện tại và giá vốn đang khai — màn hình lập phiếu dùng giá vốn làm giá nhập gợi ý. Hàng sắp hết xếp lên đầu vì đó chính là thứ cần đặt thêm.
// @Tags			Admin - Purchases
// @Accept			json
// @Produce		json
// @Param			keyword	query		string	false	"Tên sản phẩm / SKU biến thể / SKU sản phẩm"
// @Param			limit	query		int		false	"Số dòng tối đa (mặc định 20, tối đa 50)"
// @Success		200		{object}	response.Body{data=[]domain.PurchaseVariant}
// @Failure		401		{object}	response.Body
// @Security		BearerAuth
// @Router			/admin/purchases/variants [get]
func (h *PurchaseOrderHandler) SearchVariants(c *gin.Context) {
	limit, _ := strconv.Atoi(c.DefaultQuery("limit", "20"))
	if limit < 1 || limit > 50 {
		limit = 20
	}

	list, err := h.svc.SearchVariants(c.Request.Context(), c.Query("keyword"), limit)
	if err != nil {
		response.Error(c, http.StatusInternalServerError, "Lỗi tìm sản phẩm")
		return
	}
	response.OK(c, list)
}

// @Summary		Chi tiết phiếu đặt hàng nhập
// @Description	Phiếu kèm danh sách hàng (có số ĐÃ NHẬN của từng dòng), nhà cung cấp, lịch sử thao tác, các trạng thái hợp lệ kế tiếp và hai cờ `can_edit` / `can_receive`.
// @Tags			Admin - Purchases
// @Accept			json
// @Produce		json
// @Param			id	path		int	true	"ID phiếu đặt hàng"
// @Success		200	{object}	response.Body{data=service.PurchaseDetail}
// @Failure		404	{object}	response.Body
// @Security		BearerAuth
// @Router			/admin/purchases/{id} [get]
func (h *PurchaseOrderHandler) Get(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}

	res, err := h.svc.GetByID(c.Request.Context(), id)
	if err != nil {
		respondPurchaseError(c, err, "Lỗi truy vấn phiếu đặt hàng")
		return
	}
	response.OK(c, res)
}

// @Summary		Lập phiếu đặt hàng nhập
// @Description	Tạo phiếu mua hàng từ nhà cung cấp. `status` = `draft` để soạn tiếp, `ordered` khi đã chốt với nhà cung cấp (bỏ trống = draft).
// @Description	Tên, SKU, size, màu của từng dòng do server chụp lại từ biến thể — client chỉ gửi `variant_id`, số lượng và GIÁ NHẬP (giá do người mua thoả thuận nên nhận từ client).
// @Description	Tiền hàng server tự tính từ đơn giá × số lượng; tổng phiếu = tiền hàng − chiết khấu + cước vận chuyển.
// @Description	Lập phiếu KHÔNG đụng tới tồn kho: hàng mới chỉ được đặt, chưa về tới kho.
// @Tags			Admin - Purchases
// @Accept			json
// @Produce		json
// @Param			body	body		dto.PurchaseCreateRequest	true	"Nội dung phiếu đặt hàng"
// @Success		201		{object}	response.Body{data=service.PurchaseDetail}
// @Failure		400		{object}	response.Body
// @Failure		404		{object}	response.Body	"Không tìm thấy nhà cung cấp"
// @Failure		422		{object}	response.Body
// @Security		BearerAuth
// @Router			/admin/purchases [post]
func (h *PurchaseOrderHandler) Create(c *gin.Context) {
	var req dto.PurchaseCreateRequest
	if !bindJSON(c, &req) {
		return
	}

	res, err := h.svc.Create(c.Request.Context(), &req, currentUserID(c))
	if err != nil {
		respondPurchaseError(c, err, "Lỗi lập phiếu đặt hàng")
		return
	}
	response.Created(c, res)
}

// @Summary		Sửa phiếu đặt hàng nhập
// @Description	Sửa thông tin + THAY TOÀN BỘ danh sách hàng của phiếu. Chỉ sửa được khi phiếu còn ở "nháp"/"đã đặt" và CHƯA nhận đợt hàng nào — đã có hàng về là các con số của phiếu đã đối chiếu với bút toán kho, sửa tiếp thì hai sổ lệch nhau (trả 409).
// @Description	Điều kiện này được kiểm tra ngay dưới khoá dòng phiếu, nên một đợt nhận hàng chen vào giữa không thể bị danh sách mới xoá mất.
// @Tags			Admin - Purchases
// @Accept			json
// @Produce		json
// @Param			id		path		int							true	"ID phiếu đặt hàng"
// @Param			body	body		dto.PurchaseUpdateRequest	true	"Nội dung phiếu đặt hàng"
// @Success		200		{object}	response.Body{data=service.PurchaseDetail}
// @Failure		404		{object}	response.Body
// @Failure		409		{object}	response.Body
// @Failure		422		{object}	response.Body
// @Security		BearerAuth
// @Router			/admin/purchases/{id} [put]
func (h *PurchaseOrderHandler) Update(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}

	var req dto.PurchaseUpdateRequest
	if !bindJSON(c, &req) {
		return
	}

	res, err := h.svc.Update(c.Request.Context(), id, &req, currentUserID(c))
	if err != nil {
		respondPurchaseError(c, err, "Lỗi cập nhật phiếu đặt hàng")
		return
	}
	response.OKMessage(c, "Đã cập nhật phiếu đặt hàng", res)
}

// @Summary		Chuyển trạng thái phiếu đặt hàng
// @Description	Hai đích: `ordered` (gửi phiếu cho nhà cung cấp) và `cancelled` (huỷ phiếu). Mỗi lần chuyển đều được ghi vào lịch sử phiếu.
// @Description	KHÔNG có đường tay nào tới "đã nhận đủ": đánh dấu đã nhận mà không đi qua luồng nhận hàng là phiếu đóng xong mà kho không có hàng. Muốn đóng phiếu thì nhận nốt số còn lại qua `/admin/purchases/{id}/receive`.
// @Description	Khi huỷ, trường `note` là lý do huỷ và BẮT BUỘC phải có. Huỷ phiếu đã nhận một phần không rút hàng đã về ra khỏi kho — số hàng đó là có thật.
// @Tags			Admin - Purchases
// @Accept			json
// @Produce		json
// @Param			id		path		int							true	"ID phiếu đặt hàng"
// @Param			body	body		dto.PurchaseStatusRequest	true	"Trạng thái mới"
// @Success		200		{object}	response.Body{data=service.PurchaseDetail}
// @Failure		404		{object}	response.Body
// @Failure		409		{object}	response.Body
// @Failure		422		{object}	response.Body
// @Security		BearerAuth
// @Router			/admin/purchases/{id}/status [put]
func (h *PurchaseOrderHandler) UpdateStatus(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}

	var req dto.PurchaseStatusRequest
	if !bindJSON(c, &req) {
		return
	}

	res, err := h.svc.UpdateStatus(c.Request.Context(), id, &req, currentUserID(c))
	if err != nil {
		respondPurchaseError(c, err, "Lỗi cập nhật trạng thái phiếu đặt hàng")
		return
	}
	response.OKMessage(c, "Đã cập nhật phiếu đặt hàng", res)
}

// @Summary		Nhận hàng cho phiếu đặt (nhập kho)
// @Description	Ghi nhận MỘT đợt hàng về: cộng số đã nhận của từng dòng, CỘNG TỒN KHO của biến thể tương ứng và ghi bút toán sổ kho (`type=import`, `reference_type=purchase_order`) — tất cả trong cùng một transaction.
// @Description	Nhận nhiều đợt được (nhà cung cấp giao dần): phiếu tự chuyển sang "nhận một phần", và chỉ khi MỌI dòng đủ số thì mới thành "đã nhận đủ".
// @Description	Nhận vượt phần còn thiếu trả 422 và KHÔNG dòng nào được ghi — một đợt nhận hàng là một lần cân đối kho, ghi nửa chừng còn khó dọn hơn báo lỗi.
// @Description	`update_cost` (mặc định true) ghi luôn giá nhập của đợt này thành giá vốn của biến thể, để trang tồn kho tính giá trị kho theo giá vừa mua.
// @Tags			Admin - Purchases
// @Accept			json
// @Produce		json
// @Param			id		path		int							true	"ID phiếu đặt hàng"
// @Param			body	body		dto.PurchaseReceiveRequest	true	"Số lượng nhận của từng dòng"
// @Success		200		{object}	response.Body{data=service.PurchaseDetail}
// @Failure		404		{object}	response.Body
// @Failure		409		{object}	response.Body	"Phiếu không ở giai đoạn nhận hàng được"
// @Failure		422		{object}	response.Body
// @Security		BearerAuth
// @Router			/admin/purchases/{id}/receive [post]
func (h *PurchaseOrderHandler) Receive(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}

	var req dto.PurchaseReceiveRequest
	if !bindJSON(c, &req) {
		return
	}

	res, err := h.svc.Receive(c.Request.Context(), id, &req, currentUserID(c))
	if err != nil {
		respondPurchaseError(c, err, "Lỗi nhận hàng")
		return
	}
	response.OKMessage(c, "Đã nhận hàng và cập nhật tồn kho", res)
}

// @Summary		Cập nhật thanh toán cho nhà cung cấp
// @Description	`paid_amount` là số LUỸ KẾ đã trả (không phải số cộng thêm) — nhân viên nhìn thấy tổng đã trả trên màn hình và sửa đúng con số đó. Tình trạng thanh toán (chưa trả / trả một phần / đã trả đủ) do server suy ra.
// @Description	Phiếu nháp chưa đặt thật nên chưa nợ ai, phiếu đã huỷ là sổ đã đóng — cả hai đều trả 409.
// @Tags			Admin - Purchases
// @Accept			json
// @Produce		json
// @Param			id		path		int							true	"ID phiếu đặt hàng"
// @Param			body	body		dto.PurchasePaymentRequest	true	"Số tiền đã trả (luỹ kế)"
// @Success		200		{object}	response.Body{data=service.PurchaseDetail}
// @Failure		404		{object}	response.Body
// @Failure		409		{object}	response.Body
// @Failure		422		{object}	response.Body
// @Security		BearerAuth
// @Router			/admin/purchases/{id}/payment [put]
func (h *PurchaseOrderHandler) UpdatePayment(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}

	var req dto.PurchasePaymentRequest
	if !bindJSON(c, &req) {
		return
	}

	res, err := h.svc.UpdatePayment(c.Request.Context(), id, &req, currentUserID(c))
	if err != nil {
		respondPurchaseError(c, err, "Lỗi cập nhật thanh toán")
		return
	}
	response.OKMessage(c, "Đã cập nhật thanh toán", res)
}

// @Summary		Xoá phiếu đặt hàng nháp
// @Description	Chỉ xoá được phiếu còn ở trạng thái NHÁP. Phiếu đã gửi nhà cung cấp phải HUỶ (có lý do, còn lại trong lịch sử) chứ không biến mất khỏi sổ — trả 409.
// @Tags			Admin - Purchases
// @Accept			json
// @Produce		json
// @Param			id	path		int	true	"ID phiếu đặt hàng"
// @Success		200	{object}	response.Body
// @Failure		404	{object}	response.Body
// @Failure		409	{object}	response.Body
// @Security		BearerAuth
// @Router			/admin/purchases/{id} [delete]
func (h *PurchaseOrderHandler) Delete(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}

	if err := h.svc.Delete(c.Request.Context(), id); err != nil {
		respondPurchaseError(c, err, "Lỗi xoá phiếu đặt hàng")
		return
	}
	response.OKMessage(c, "Đã xoá phiếu đặt hàng nháp", nil)
}

func respondPurchaseError(c *gin.Context, err error, fallback string) {
	switch {
	case errors.Is(err, domain.ErrNotFound):
		response.Error(c, http.StatusNotFound, "Không tìm thấy phiếu đặt hàng hoặc nhà cung cấp")
	case errors.Is(err, domain.ErrPurchaseLocked):
		response.Error(c, http.StatusConflict,
			"Phiếu đã qua giai đoạn cho phép thao tác này (đã nhận hàng, đã huỷ hoặc còn là phiếu nháp)")
	case errors.Is(err, domain.ErrPurchaseQtyExceeded):
		response.Error(c, http.StatusUnprocessableEntity,
			"Số lượng nhận vượt quá số còn lại của phiếu. Vui lòng tải lại để xem số mới nhất.")
	case errors.Is(err, domain.ErrPurchaseNothingToReceive):
		response.Error(c, http.StatusUnprocessableEntity, "Vui lòng nhập số lượng nhận cho ít nhất một sản phẩm")
	case errors.Is(err, domain.ErrPurchaseEmpty):
		response.Error(c, http.StatusUnprocessableEntity, "Phiếu đặt hàng phải có ít nhất một sản phẩm")
	case errors.Is(err, domain.ErrVariantNotFound):
		response.Error(c, http.StatusUnprocessableEntity, "Có sản phẩm không còn tồn tại, vui lòng chọn lại")
	case errors.Is(err, domain.ErrInvalidStatus):
		response.Error(c, http.StatusUnprocessableEntity, "Không thể chuyển phiếu sang trạng thái này")
	case errors.Is(err, domain.ErrConflict):
		response.Error(c, http.StatusConflict, "Phiếu đang ở trạng thái này rồi")
	default:
		response.Error(c, http.StatusInternalServerError, fallback)
	}
}
