package handler

import (
	"errors"
	"net/http"
	"strconv"

	"github.com/gin-gonic/gin"

	"sass-api/internal/domain"
	"sass-api/internal/dto"
	"sass-api/internal/middleware"
	"sass-api/internal/service"
	"sass-api/pkg/response"
)

type OrderReturnHandler struct {
	svc service.OrderReturnService
}

func NewOrderReturnHandler(svc service.OrderReturnService) *OrderReturnHandler {
	return &OrderReturnHandler{svc: svc}
}

// @Summary		Danh sách phiếu trả hàng
// @Description	Lọc theo từ khoá (mã phiếu / mã đơn / tên / SĐT người nhận), trạng thái phiếu, lý do trả, đơn hàng, khách hàng và khoảng ngày tạo; hỗ trợ sắp xếp và phân trang. Tham số `status` nhận NHIỀU trạng thái ngăn cách bởi dấu phẩy (VD: `pending,approved`) để lấy nhóm phiếu "đang xử lý".
// @Tags			Admin - Returns
// @Accept			json
// @Produce		json
// @Param			keyword		query		string	false	"Mã phiếu / mã đơn / tên / SĐT người nhận"
// @Param			status		query		string	false	"all|pending|approved|received|refunded|rejected|cancelled — cho phép nhiều giá trị ngăn cách bởi dấu phẩy"
// @Param			reason		query		string	false	"all|defective|wrong_item|wrong_size|not_as_described|changed_mind|other"
// @Param			order_id	query		int		false	"Lọc phiếu của một đơn hàng"
// @Param			user_id		query		int		false	"Lọc phiếu của một khách hàng"
// @Param			from_date	query		string	false	"Từ ngày (YYYY-MM-DD)"
// @Param			to_date		query		string	false	"Đến ngày (YYYY-MM-DD)"
// @Param			sort		query		string	false	"newest|oldest|amount_desc|amount_asc"
// @Param			page		query		int		false	"Trang (mặc định 1)"
// @Param			page_size	query		int		false	"Số item/trang (mặc định 20, tối đa 100)"
// @Success		200			{object}	response.Body{meta=response.Pagination}
// @Failure		401			{object}	response.Body
// @Failure		500			{object}	response.Body
// @Security		BearerAuth
// @Router			/admin/returns [get]
func (h *OrderReturnHandler) List(c *gin.Context) {
	filter, page, pageSize := returnFilterFrom(c, 20, 100)
	if id, err := strconv.ParseUint(c.Query("order_id"), 10, 64); err == nil && id > 0 {
		v := uint(id)
		filter.OrderID = &v
	}
	if id, err := strconv.ParseUint(c.Query("user_id"), 10, 64); err == nil && id > 0 {
		v := uint(id)
		filter.UserID = &v
	}

	list, total, err := h.svc.List(c.Request.Context(), filter)
	if err != nil {
		response.Error(c, http.StatusInternalServerError, "Lỗi truy vấn danh sách phiếu trả hàng")
		return
	}
	respondReturnList(c, list, total, page, pageSize)
}

// @Summary		Thống kê trả hàng
// @Description	Đếm phiếu theo trạng thái và tổng tiền ĐÃ hoàn thực tế (chỉ cộng phiếu đã hoàn tiền xong, không cộng phiếu đang chờ).
// @Tags			Admin - Returns
// @Accept			json
// @Produce		json
// @Success		200	{object}	response.Body{data=domain.ReturnStats}
// @Failure		401	{object}	response.Body
// @Security		BearerAuth
// @Router			/admin/returns/stats [get]
func (h *OrderReturnHandler) Stats(c *gin.Context) {
	stats, err := h.svc.Stats(c.Request.Context())
	if err != nil {
		response.Error(c, http.StatusInternalServerError, "Lỗi thống kê trả hàng")
		return
	}
	response.OK(c, stats)
}

// @Summary		Chi tiết phiếu trả hàng
// @Description	Phiếu kèm danh sách món trả, đơn hàng gốc, lịch sử xử lý và các trạng thái hợp lệ kế tiếp.
// @Tags			Admin - Returns
// @Accept			json
// @Produce		json
// @Param			id	path		int	true	"ID phiếu trả hàng"
// @Success		200	{object}	response.Body{data=service.ReturnDetail}
// @Failure		404	{object}	response.Body
// @Security		BearerAuth
// @Router			/admin/returns/{id} [get]
func (h *OrderReturnHandler) Get(c *gin.Context) {
	id, err := orderID(c)
	if err != nil {
		response.Error(c, http.StatusBadRequest, "ID phiếu trả hàng không hợp lệ")
		return
	}

	res, err := h.svc.GetByID(c.Request.Context(), id)
	if err != nil {
		respondReturnError(c, err, "Lỗi truy vấn phiếu trả hàng")
		return
	}
	response.OK(c, res)
}

// @Summary		Sản phẩm còn trả được của một đơn
// @Description	Liệt kê từng dòng hàng của đơn kèm số đã mua, số đang/đã nằm trong phiếu trả khác và SỐ CÒN TRẢ ĐƯỢC — màn hình lập phiếu dựng form từ đây thay vì tự tính.
// @Description	Cờ `returnable` cho biết đơn có nằm trong diện trả hàng không; `reason` là câu giải thích khi không (chưa giao hàng, đã hoàn toàn bộ, mọi món đã nằm trong phiếu khác).
// @Tags			Admin - Returns
// @Accept			json
// @Produce		json
// @Param			id	path		int	true	"ID đơn hàng"
// @Success		200	{object}	response.Body{data=service.ReturnableOrder}
// @Failure		404	{object}	response.Body
// @Security		BearerAuth
// @Router			/admin/orders/{id}/returnable [get]
func (h *OrderReturnHandler) Returnable(c *gin.Context) {
	id, err := orderID(c)
	if err != nil {
		response.Error(c, http.StatusBadRequest, "ID đơn hàng không hợp lệ")
		return
	}

	res, err := h.svc.Returnable(c.Request.Context(), id)
	if err != nil {
		respondReturnError(c, err, "Lỗi tải sản phẩm còn trả được")
		return
	}
	response.OK(c, res)
}

// @Summary		Lập phiếu trả hàng (nhân viên)
// @Description	Nhân viên lập phiếu trả tại quầy cho một đơn ĐÃ GIAO. Phiếu vào thẳng trạng thái "đã duyệt" vì nhân viên đang cầm hàng trên tay — bước còn lại là xác nhận đã nhận hàng (nhập kho) rồi hoàn tiền.
// @Description	Số lượng trả của từng dòng được đối chiếu với số CÒN trả được ngay dưới khoá dòng đơn, nên hai phiếu lập cùng lúc không thể cùng trả nốt phần còn lại. Giá và tên sản phẩm server chụp lại từ đơn gốc, KHÔNG nhận từ client.
// @Description	Tiền hoàn = tiền hàng các món trả + phí ship hoàn lại − khấu trừ, không bao giờ âm.
// @Tags			Admin - Returns
// @Accept			json
// @Produce		json
// @Param			body	body		dto.ReturnAdminCreateRequest	true	"Thông tin phiếu trả hàng"
// @Success		201		{object}	response.Body{data=service.ReturnDetail}
// @Failure		400		{object}	response.Body
// @Failure		404		{object}	response.Body
// @Failure		422		{object}	response.Body
// @Security		BearerAuth
// @Router			/admin/returns [post]
func (h *OrderReturnHandler) Create(c *gin.Context) {
	var req dto.ReturnAdminCreateRequest
	if !bindJSON(c, &req) {
		return
	}

	res, err := h.svc.CreateByAdmin(c.Request.Context(), &req, currentUserID(c))
	if err != nil {
		respondReturnError(c, err, "Lỗi lập phiếu trả hàng")
		return
	}
	response.Created(c, res)
}

// @Summary		Chuyển trạng thái phiếu trả hàng
// @Description	Chuyển phiếu sang trạng thái kế tiếp hợp lệ: chờ duyệt → đã duyệt → đã nhận hàng → đã hoàn tiền, hoặc rẽ sang từ chối / huỷ. Mỗi lần chuyển đều được ghi vào lịch sử phiếu.
// @Description	Chuyển sang `received` sẽ NHẬP LẠI KHO đúng số lượng từng món (trừ khi phiếu đánh dấu `restock = false` vì hàng lỗi), trừ lượt bán tương ứng của sản phẩm, và tự chuyển ĐƠN sang "hoàn hàng" nếu mọi món của đơn đã được trả hết.
// @Description	Chuyển sang `refunded` ghi một dòng chi hoàn tiền vào sổ thanh toán và đánh dấu đơn "đã hoàn tiền" khi tổng tiền hoàn đã bù đủ giá trị đơn.
// @Description	Khi từ chối, trường `note` là lý do từ chối và BẮT BUỘC phải có.
// @Tags			Admin - Returns
// @Accept			json
// @Produce		json
// @Param			id		path		int						true	"ID phiếu trả hàng"
// @Param			body	body		dto.ReturnStatusRequest	true	"Trạng thái mới"
// @Success		200		{object}	response.Body{data=service.ReturnDetail}
// @Failure		400		{object}	response.Body
// @Failure		404		{object}	response.Body
// @Failure		409		{object}	response.Body
// @Failure		422		{object}	response.Body
// @Security		BearerAuth
// @Router			/admin/returns/{id}/status [put]
func (h *OrderReturnHandler) UpdateStatus(c *gin.Context) {
	id, err := orderID(c)
	if err != nil {
		response.Error(c, http.StatusBadRequest, "ID phiếu trả hàng không hợp lệ")
		return
	}

	var req dto.ReturnStatusRequest
	if !bindJSON(c, &req) {
		return
	}

	res, err := h.svc.UpdateStatus(c.Request.Context(), id, &req, currentUserID(c))
	if err != nil {
		respondReturnError(c, err, "Lỗi cập nhật trạng thái phiếu trả hàng")
		return
	}
	response.OKMessage(c, "Đã cập nhật phiếu trả hàng", res)
}

// @Summary		Chốt số tiền hoàn của phiếu trả hàng
// @Description	Điều chỉnh phí ship hoàn lại, phần khấu trừ, hình thức hoàn tiền và cờ nhập lại kho. TIỀN HÀNG của các món trả là con số server tính từ đơn gốc, không sửa được ở đây.
// @Description	Chỉ chốt được khi phiếu còn ở "chờ duyệt", "đã duyệt" hoặc "đã nhận hàng" — phiếu đã hoàn tiền / bị từ chối / bị huỷ là sổ đã chốt. Cờ `restock` không đổi được nữa sau khi hàng đã nhập kho.
// @Tags			Admin - Returns
// @Accept			json
// @Produce		json
// @Param			id		path		int						true	"ID phiếu trả hàng"
// @Param			body	body		dto.ReturnSettleRequest	true	"Số tiền hoàn"
// @Success		200		{object}	response.Body{data=service.ReturnDetail}
// @Failure		400		{object}	response.Body
// @Failure		404		{object}	response.Body
// @Failure		422		{object}	response.Body
// @Security		BearerAuth
// @Router			/admin/returns/{id}/settle [put]
func (h *OrderReturnHandler) Settle(c *gin.Context) {
	id, err := orderID(c)
	if err != nil {
		response.Error(c, http.StatusBadRequest, "ID phiếu trả hàng không hợp lệ")
		return
	}

	var req dto.ReturnSettleRequest
	if !bindJSON(c, &req) {
		return
	}

	res, err := h.svc.Settle(c.Request.Context(), id, &req, currentUserID(c))
	if err != nil {
		respondReturnError(c, err, "Lỗi chốt số tiền hoàn")
		return
	}
	response.OKMessage(c, "Đã chốt số tiền hoàn", res)
}

// @Summary		Ghi chú nội bộ cho phiếu trả hàng
// @Description	Lưu ghi chú của admin/nhân viên trên phiếu (khách hàng không nhìn thấy).
// @Tags			Admin - Returns
// @Accept			json
// @Produce		json
// @Param			id		path		int						true	"ID phiếu trả hàng"
// @Param			body	body		dto.ReturnNoteRequest	true	"Ghi chú nội bộ"
// @Success		200		{object}	response.Body{data=service.ReturnDetail}
// @Failure		400		{object}	response.Body
// @Failure		404		{object}	response.Body
// @Failure		422		{object}	response.Body
// @Security		BearerAuth
// @Router			/admin/returns/{id}/note [put]
func (h *OrderReturnHandler) UpdateNote(c *gin.Context) {
	id, err := orderID(c)
	if err != nil {
		response.Error(c, http.StatusBadRequest, "ID phiếu trả hàng không hợp lệ")
		return
	}

	var req dto.ReturnNoteRequest
	if !bindJSON(c, &req) {
		return
	}

	res, err := h.svc.UpdateNote(c.Request.Context(), id, req.AdminNote)
	if err != nil {
		respondReturnError(c, err, "Lỗi lưu ghi chú")
		return
	}
	response.OKMessage(c, "Đã lưu ghi chú", res)
}

// ---------- Luồng của khách (storefront) ----------

// MyReturnable godoc
//
// @Summary		Sản phẩm còn trả được của đơn tôi (khách đã đăng nhập)
// @Description	Dùng để dựng form "Yêu cầu trả hàng" ở trang tài khoản: mỗi dòng hàng kèm số đã mua và số CÒN trả được.
// @Description	Cờ `returnable` = false nghĩa là đơn chưa/không còn trả được, `reason` nói rõ vì sao (chưa giao hàng, quá hạn đổi trả, đã trả hết).
// @Description	Đơn KHÔNG thuộc tài khoản đang đăng nhập trả về 404 (không phải 403) để không lộ đơn của người khác qua việc thử ID.
// @Tags			Returns
// @Accept			json
// @Produce		json
// @Param			id	path		int	true	"ID đơn hàng"
// @Success		200	{object}	response.Body{data=service.ReturnableOrder}
// @Failure		401	{object}	response.Body
// @Failure		404	{object}	response.Body
// @Security		BearerAuth
// @Router			/orders/me/{id}/returnable [get]
func (h *OrderReturnHandler) MyReturnable(c *gin.Context) {
	id, err := orderID(c)
	if err != nil {
		response.Error(c, http.StatusBadRequest, "ID đơn hàng không hợp lệ")
		return
	}

	res, err := h.svc.MyReturnable(c.Request.Context(), c.GetUint(middleware.CtxUserID), id)
	if err != nil {
		respondReturnError(c, err, "Lỗi tải sản phẩm còn trả được")
		return
	}
	response.OK(c, res)
}

// MyCreate godoc
//
// @Summary		Gửi yêu cầu trả hàng (khách đã đăng nhập)
// @Description	Khách chọn món cần trả trong đơn của CHÍNH mình, nêu lý do và cách nhận tiền hoàn. Phiếu tạo ra ở trạng thái "chờ duyệt" và nhân viên sẽ xử lý.
// @Description	Chỉ gửi được cho đơn ĐÃ GIAO / HOÀN TẤT và trong vòng 7 ngày kể từ ngày nhận hàng; quá hạn thì khách phải liên hệ cửa hàng (trả 409).
// @Description	Số lượng trả được đối chiếu với số CÒN trả được ngay dưới khoá dòng đơn nên không thể trả cùng một món hai lần qua hai yêu cầu. Giá do server chụp lại từ đơn gốc.
// @Description	Đơn không thuộc tài khoản đang đăng nhập trả 404 để không lộ đơn của người khác.
// @Tags			Returns
// @Accept			json
// @Produce		json
// @Param			body	body		dto.ReturnCreateRequest	true	"Nội dung yêu cầu trả hàng"
// @Success		201		{object}	response.Body{data=service.ReturnDetail}
// @Failure		400		{object}	response.Body
// @Failure		401		{object}	response.Body
// @Failure		404		{object}	response.Body
// @Failure		409		{object}	response.Body	"Đơn không nằm trong diện trả hàng hoặc đã quá hạn"
// @Failure		422		{object}	response.Body
// @Security		BearerAuth
// @Router			/returns/me [post]
func (h *OrderReturnHandler) MyCreate(c *gin.Context) {
	var req dto.ReturnCreateRequest
	if !bindJSON(c, &req) {
		return
	}

	res, err := h.svc.CreateMine(c.Request.Context(), c.GetUint(middleware.CtxUserID), &req)
	if err != nil {
		respondReturnError(c, err, "Lỗi gửi yêu cầu trả hàng")
		return
	}
	response.Created(c, res)
}

// MyList godoc
//
// @Summary		Phiếu trả hàng của tôi (khách đã đăng nhập)
// @Description	Danh sách phiếu trả của CHÍNH tài khoản đang đăng nhập, mới nhất trước. Id tài khoản lấy từ access token, không nhận `user_id` từ client. Ghi chú nội bộ của cửa hàng được ẩn khỏi kết quả.
// @Tags			Returns
// @Accept			json
// @Produce		json
// @Param			status		query		string	false	"Lọc trạng thái: all|pending|approved|received|refunded|rejected|cancelled — nhiều giá trị ngăn cách bởi dấu phẩy"
// @Param			page		query		int		false	"Trang (mặc định 1)"
// @Param			page_size	query		int		false	"Số phiếu/trang (mặc định 10, tối đa 50)"
// @Success		200			{object}	response.Body{meta=response.Pagination}
// @Failure		401			{object}	response.Body
// @Security		BearerAuth
// @Router			/returns/me [get]
func (h *OrderReturnHandler) MyList(c *gin.Context) {
	filter, page, pageSize := returnFilterFrom(c, 10, 50)

	list, total, err := h.svc.MyReturns(c.Request.Context(), c.GetUint(middleware.CtxUserID), filter)
	if err != nil {
		respondReturnError(c, err, "Lỗi truy vấn phiếu trả hàng của bạn")
		return
	}
	respondReturnList(c, list, total, page, pageSize)
}

// MyGet godoc
//
// @Summary		Chi tiết phiếu trả hàng của tôi (khách đã đăng nhập)
// @Description	Phiếu kèm danh sách món trả, đơn gốc và lịch sử xử lý. Phiếu KHÔNG thuộc tài khoản đang đăng nhập trả về 404 để không lộ phiếu của người khác qua việc thử ID.
// @Tags			Returns
// @Accept			json
// @Produce		json
// @Param			id	path		int	true	"ID phiếu trả hàng"
// @Success		200	{object}	response.Body{data=service.ReturnDetail}
// @Failure		401	{object}	response.Body
// @Failure		404	{object}	response.Body
// @Security		BearerAuth
// @Router			/returns/me/{id} [get]
func (h *OrderReturnHandler) MyGet(c *gin.Context) {
	id, err := orderID(c)
	if err != nil {
		response.Error(c, http.StatusBadRequest, "ID phiếu trả hàng không hợp lệ")
		return
	}

	res, err := h.svc.MyReturn(c.Request.Context(), c.GetUint(middleware.CtxUserID), id)
	if err != nil {
		respondReturnError(c, err, "Lỗi tải phiếu trả hàng")
		return
	}
	response.OK(c, res)
}

// MyCancel godoc
//
// @Summary		Rút yêu cầu trả hàng (khách đã đăng nhập)
// @Description	Khách tự rút yêu cầu của CHÍNH mình. Chỉ rút được khi phiếu còn ở "chờ duyệt" — cửa hàng đã duyệt thì hàng có thể đang trên đường về kho nên phải liên hệ nhân viên (trả 422).
// @Description	Phiếu không thuộc tài khoản đang đăng nhập trả 404 để không lộ phiếu của người khác.
// @Tags			Returns
// @Accept			json
// @Produce		json
// @Param			id		path		int							true	"ID phiếu trả hàng"
// @Param			body	body		dto.ReturnCancelRequest	false	"Lý do rút (không bắt buộc)"
// @Success		200		{object}	response.Body{data=service.ReturnDetail}
// @Failure		401		{object}	response.Body
// @Failure		404		{object}	response.Body
// @Failure		409		{object}	response.Body
// @Failure		422		{object}	response.Body
// @Security		BearerAuth
// @Router			/returns/me/{id}/cancel [post]
func (h *OrderReturnHandler) MyCancel(c *gin.Context) {
	id, err := orderID(c)
	if err != nil {
		response.Error(c, http.StatusBadRequest, "ID phiếu trả hàng không hợp lệ")
		return
	}

	// Lý do không bắt buộc nên body rỗng vẫn hợp lệ.
	var req dto.ReturnCancelRequest
	if c.Request.ContentLength > 0 && !bindJSON(c, &req) {
		return
	}

	res, err := h.svc.CancelMine(c.Request.Context(), c.GetUint(middleware.CtxUserID), id, req.Reason)
	if err != nil {
		respondReturnError(c, err, "Lỗi rút yêu cầu trả hàng")
		return
	}
	response.OKMessage(c, "Đã rút yêu cầu trả hàng", res)
}

// ---------- Helper ----------

// returnFilterFrom đọc bộ lọc chung của hai luồng (admin & khách) từ query.
func returnFilterFrom(c *gin.Context, defaultSize, maxSize int) (domain.ReturnFilter, int, int) {
	page, _ := strconv.Atoi(c.DefaultQuery("page", "1"))
	pageSize, _ := strconv.Atoi(c.DefaultQuery("page_size", strconv.Itoa(defaultSize)))
	if page < 1 {
		page = 1
	}
	if pageSize < 1 || pageSize > maxSize {
		pageSize = defaultSize
	}

	return domain.ReturnFilter{
		Keyword:  c.Query("keyword"),
		Status:   c.Query("status"),
		Reason:   c.Query("reason"),
		FromDate: c.Query("from_date"),
		ToDate:   c.Query("to_date"),
		Sort:     c.Query("sort"),
		Page:     page,
		PageSize: pageSize,
	}, page, pageSize
}

func respondReturnList(c *gin.Context, list []domain.OrderReturn, total int64, page, pageSize int) {
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

func respondReturnError(c *gin.Context, err error, fallback string) {
	switch {
	case errors.Is(err, domain.ErrNotFound):
		response.Error(c, http.StatusNotFound, "Không tìm thấy phiếu trả hàng hoặc đơn hàng")
	case errors.Is(err, domain.ErrReturnNotAllowed):
		response.Error(c, http.StatusConflict,
			"Đơn hàng này không nằm trong diện trả hàng (chưa giao, đã hoàn hết hoặc đã quá hạn đổi trả)")
	case errors.Is(err, domain.ErrReturnQtyExceeded):
		response.Error(c, http.StatusUnprocessableEntity,
			"Số lượng trả vượt quá số còn trả được. Vui lòng tải lại trang để xem số mới nhất.")
	case errors.Is(err, domain.ErrReturnEmpty):
		response.Error(c, http.StatusUnprocessableEntity, "Vui lòng chọn ít nhất một sản phẩm để trả")
	case errors.Is(err, domain.ErrReturnItemNotFound):
		response.Error(c, http.StatusUnprocessableEntity, "Có sản phẩm không thuộc đơn hàng này")
	case errors.Is(err, domain.ErrInvalidStatus):
		response.Error(c, http.StatusUnprocessableEntity, "Không thể chuyển phiếu sang trạng thái này")
	case errors.Is(err, domain.ErrConflict):
		response.Error(c, http.StatusConflict, "Phiếu trả hàng đang ở trạng thái này rồi")
	case errors.Is(err, domain.ErrForbidden):
		response.Error(c, http.StatusForbidden, "Không có quyền thực hiện thao tác này")
	default:
		response.Error(c, http.StatusInternalServerError, fallback)
	}
}
