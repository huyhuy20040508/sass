package handler

import (
	"errors"
	"net/http"
	"strconv"
	"strings"

	"github.com/gin-gonic/gin"

	"sass-api/internal/domain"
	"sass-api/internal/dto"
	"sass-api/internal/middleware"
	"sass-api/internal/service"
	"sass-api/pkg/response"
)

type OrderHandler struct {
	svc service.OrderService
}

func NewOrderHandler(svc service.OrderService) *OrderHandler {
	return &OrderHandler{svc: svc}
}

// @Summary		Tạo đơn hàng thủ công
// @Description	Admin tạo đơn cho một KHÁCH HÀNG CÓ SẴN (user_id bắt buộc & phải tồn tại). Server tự tính tiền hàng, tổng tiền từ đơn giá × số lượng; đơn khởi tạo ở trạng thái "chờ xác nhận", "chưa thanh toán". TỒN KHO bị trừ ngay trong cùng transaction (có khoá biến thể + ghi sổ kho) như đơn khách đặt trên web; thiếu hàng thì trả 400 và không tạo đơn.
// @Description	Có recipient_email thì gửi email xác nhận đơn cho khách (chạy nền, gửi hỏng chỉ ghi log chứ không làm hỏng đơn).
// @Tags			Admin - Orders
// @Accept			json
// @Produce		json
// @Param			body	body		dto.OrderCreateRequest	true	"Thông tin đơn hàng"
// @Success		201		{object}	response.Body{data=service.OrderDetail}
// @Failure		400		{object}	response.Body
// @Failure		404		{object}	response.Body
// @Failure		422		{object}	response.Body
// @Security		BearerAuth
// @Router			/admin/orders [post]
func (h *OrderHandler) Create(c *gin.Context) {
	var req dto.OrderCreateRequest
	if !bindJSON(c, &req) {
		return
	}

	res, err := h.svc.Create(c.Request.Context(), &req)
	if err != nil {
		respondOrderError(c, err, "Lỗi tạo đơn hàng")
		return
	}
	response.Created(c, res)
}

// @Summary		Sửa đơn hàng
// @Description	Sửa thông tin người nhận, địa chỉ giao, phương thức thanh toán, phí ship, giảm giá, ghi chú và DANH SÁCH SẢN PHẨM của một đơn có sẵn. Không đổi khách hàng, mã đơn, trạng thái hay tình trạng thanh toán ở đây. Server tính lại tiền hàng & tổng tiền, đồng thời CHỈNH TỒN KHO theo đúng phần chênh: tăng số lượng thì trừ thêm, giảm hoặc bỏ sản phẩm thì hoàn lại kho (ghi bút toán `adjustment` vào sổ kho); thiếu hàng cho phần tăng thêm thì trả 400. Chỉ sửa được khi đơn còn ở giai đoạn đầu (chờ xác nhận / đã xác nhận / đang chuẩn bị).
// @Tags			Admin - Orders
// @Accept			json
// @Produce		json
// @Param			id		path		int						true	"ID đơn hàng"
// @Param			body	body		dto.OrderUpdateRequest	true	"Thông tin đơn hàng cần sửa"
// @Success		200		{object}	response.Body{data=service.OrderDetail}
// @Failure		400		{object}	response.Body
// @Failure		404		{object}	response.Body
// @Failure		422		{object}	response.Body
// @Security		BearerAuth
// @Router			/admin/orders/{id} [put]
func (h *OrderHandler) Update(c *gin.Context) {
	id, err := orderID(c)
	if err != nil {
		response.Error(c, http.StatusBadRequest, "ID đơn hàng không hợp lệ")
		return
	}

	var req dto.OrderUpdateRequest
	if !bindJSON(c, &req) {
		return
	}

	res, err := h.svc.Update(c.Request.Context(), id, &req, currentUserID(c))
	if err != nil {
		// Đơn đã qua giai đoạn cho sửa: trả thông báo riêng thay vì message chung.
		if errors.Is(err, domain.ErrInvalidStatus) {
			response.Error(c, http.StatusUnprocessableEntity, "Đơn ở trạng thái này không sửa được nữa. Chỉ sửa được khi đơn còn chờ xác nhận / đã xác nhận / đang chuẩn bị.")
			return
		}
		respondOrderError(c, err, "Lỗi sửa đơn hàng")
		return
	}
	response.OKMessage(c, "Đã cập nhật đơn hàng", res)
}

// @Summary		Danh sách đơn hàng
// @Description	Lọc theo từ khoá (mã đơn/tên/SĐT/email người nhận), trạng thái đơn, tình trạng & phương thức thanh toán, khoảng ngày đặt; hỗ trợ sắp xếp và phân trang. Tham số `status` nhận NHIỀU trạng thái ngăn cách bởi dấu phẩy (VD: `pending,confirmed,shipping`) để lấy nhóm đơn như "chưa hoàn tất".
// @Tags			Admin - Orders
// @Accept			json
// @Produce		json
// @Param			keyword			query		string	false	"Mã đơn / tên / SĐT / email người nhận"
// @Param			status			query		string	false	"all|pending|confirmed|processing|shipping|delivered|completed|cancelled|returned — cho phép nhiều giá trị ngăn cách bởi dấu phẩy"
// @Param			payment_status	query		string	false	"all|pending|paid|failed|refunded"
// @Param			payment_method	query		string	false	"all|cod|vnpay|momo|bank_transfer"
// @Param			user_id			query		int		false	"Lọc đơn của một khách hàng"
// @Param			from_date		query		string	false	"Từ ngày (YYYY-MM-DD)"
// @Param			to_date			query		string	false	"Đến ngày (YYYY-MM-DD)"
// @Param			sort			query		string	false	"newest|oldest|total_desc|total_asc"
// @Param			page			query		int		false	"Trang (mặc định 1)"
// @Param			page_size		query		int		false	"Số item/trang (mặc định 20, tối đa 100)"
// @Success		200				{object}	response.Body{meta=response.Pagination}
// @Failure		401				{object}	response.Body
// @Failure		500				{object}	response.Body
// @Security		BearerAuth
// @Router			/admin/orders [get]
func (h *OrderHandler) List(c *gin.Context) {
	page, _ := strconv.Atoi(c.DefaultQuery("page", "1"))
	pageSize, _ := strconv.Atoi(c.DefaultQuery("page_size", "20"))
	if page < 1 {
		page = 1
	}
	if pageSize < 1 || pageSize > 100 {
		pageSize = 20
	}

	filter := domain.OrderFilter{
		Keyword:       c.Query("keyword"),
		Status:        c.Query("status"),
		PaymentStatus: c.Query("payment_status"),
		PaymentMethod: c.Query("payment_method"),
		FromDate:      c.Query("from_date"),
		ToDate:        c.Query("to_date"),
		Sort:          c.Query("sort"),
		Page:          page,
		PageSize:      pageSize,
	}
	if uid, err := strconv.ParseUint(c.Query("user_id"), 10, 64); err == nil && uid > 0 {
		id := uint(uid)
		filter.UserID = &id
	}

	orders, total, err := h.svc.List(c.Request.Context(), filter)
	if err != nil {
		response.Error(c, http.StatusInternalServerError, "Lỗi truy vấn danh sách đơn hàng")
		return
	}

	totalPages := 1
	if total > 0 {
		totalPages = int((total + int64(pageSize) - 1) / int64(pageSize))
	}

	response.Paginated(c, orders, response.Pagination{
		Page:       page,
		PageSize:   pageSize,
		Total:      total,
		TotalPages: totalPages,
	})
}

// @Summary		Thống kê đơn hàng
// @Description	Đếm đơn theo nhóm trạng thái và tổng doanh thu (không tính đơn huỷ/hoàn).
// @Tags			Admin - Orders
// @Accept			json
// @Produce		json
// @Success		200	{object}	response.Body{data=domain.OrderStats}
// @Failure		401	{object}	response.Body
// @Security		BearerAuth
// @Router			/admin/orders/stats [get]
func (h *OrderHandler) Stats(c *gin.Context) {
	stats, err := h.svc.Stats(c.Request.Context())
	if err != nil {
		response.Error(c, http.StatusInternalServerError, "Lỗi thống kê đơn hàng")
		return
	}
	response.OK(c, stats)
}

// @Summary		Doanh thu theo ngày
// @Description	Chuỗi doanh thu + số đơn của TỪNG NGÀY trong `days` ngày gần nhất (tính cả hôm nay), dùng vẽ biểu đồ ở trang tổng quan. Ngày không có đơn vẫn xuất hiện với giá trị 0 để đường biểu đồ không nối tắt qua ngày trống.
// @Description	Trả kèm tổng doanh thu & số đơn của KỲ TRƯỚC cùng độ dài (`prev_revenue`, `prev_orders`) để giao diện tính mức tăng/giảm mà không phải gọi thêm lần nữa.
// @Description	Cùng quy ước với thống kê đơn hàng: KHÔNG tính đơn huỷ và đơn hoàn hàng vào doanh thu.
// @Tags			Admin - Orders
// @Accept			json
// @Produce		json
// @Param			days	query		int	false	"Số ngày muốn xem (mặc định 30, tối thiểu 1, tối đa 365)"
// @Success		200		{object}	response.Body{data=domain.RevenueSummary}
// @Failure		401		{object}	response.Body
// @Failure		500		{object}	response.Body
// @Security		BearerAuth
// @Router			/admin/orders/revenue [get]
func (h *OrderHandler) Revenue(c *gin.Context) {
	days, _ := strconv.Atoi(c.DefaultQuery("days", "30"))
	if days < 1 || days > 365 {
		days = 30
	}

	res, err := h.svc.Revenue(c.Request.Context(), days)
	if err != nil {
		response.Error(c, http.StatusInternalServerError, "Lỗi thống kê doanh thu")
		return
	}
	response.OK(c, res)
}

// @Summary		Chi tiết đơn hàng
// @Description	Lấy đơn hàng kèm danh sách sản phẩm, lịch sử chuyển trạng thái và các trạng thái hợp lệ kế tiếp.
// @Tags			Admin - Orders
// @Accept			json
// @Produce		json
// @Param			id	path		int	true	"ID đơn hàng"
// @Success		200	{object}	response.Body{data=service.OrderDetail}
// @Failure		404	{object}	response.Body
// @Security		BearerAuth
// @Router			/admin/orders/{id} [get]
func (h *OrderHandler) Get(c *gin.Context) {
	id, err := orderID(c)
	if err != nil {
		response.Error(c, http.StatusBadRequest, "ID đơn hàng không hợp lệ")
		return
	}

	res, err := h.svc.GetByID(c.Request.Context(), id)
	if err != nil {
		respondOrderError(c, err, "Lỗi truy vấn đơn hàng")
		return
	}
	response.OK(c, res)
}

// @Summary		Chuyển trạng thái đơn hàng
// @Description	Chuyển đơn sang trạng thái kế tiếp hợp lệ theo luồng xử lý. Khi huỷ đơn, trường `note` là lý do huỷ. Mỗi lần chuyển đều được ghi vào lịch sử đơn. Chuyển sang `cancelled` hoặc `returned` sẽ TRẢ HÀNG VỀ KHO đúng bằng số đơn đã lấy khỏi kho (ghi bút toán `return` vào sổ kho); đơn chưa từng trừ kho thì không cộng khống.
// @Description	Đơn có recipient_email thì khách nhận email báo trạng thái mới (chạy nền) với các mốc: confirmed, shipping, delivered, completed, cancelled, returned — riêng `processing` là bước nội bộ nên không gửi.
// @Tags			Admin - Orders
// @Accept			json
// @Produce		json
// @Param			id		path		int						true	"ID đơn hàng"
// @Param			body	body		dto.OrderStatusRequest	true	"Trạng thái mới"
// @Success		200		{object}	response.Body{data=service.OrderDetail}
// @Failure		400		{object}	response.Body
// @Failure		404		{object}	response.Body
// @Failure		409		{object}	response.Body
// @Failure		422		{object}	response.Body
// @Security		BearerAuth
// @Router			/admin/orders/{id}/status [put]
func (h *OrderHandler) UpdateStatus(c *gin.Context) {
	id, err := orderID(c)
	if err != nil {
		response.Error(c, http.StatusBadRequest, "ID đơn hàng không hợp lệ")
		return
	}

	var req dto.OrderStatusRequest
	if !bindJSON(c, &req) {
		return
	}

	// currentUserID lấy từ middleware JWTAuth — ghi nhận ai đã đổi trạng thái.
	res, err := h.svc.UpdateStatus(c.Request.Context(), id, &req, currentUserID(c))
	if err != nil {
		respondOrderError(c, err, "Lỗi cập nhật trạng thái đơn hàng")
		return
	}
	response.OKMessage(c, "Đã cập nhật trạng thái đơn hàng", res)
}

// @Summary		Cập nhật tình trạng thanh toán
// @Description	Đánh dấu đơn đã thanh toán / chờ / thất bại / hoàn tiền.
// @Tags			Admin - Orders
// @Accept			json
// @Produce		json
// @Param			id		path		int						true	"ID đơn hàng"
// @Param			body	body		dto.OrderPaymentRequest	true	"Tình trạng thanh toán"
// @Success		200		{object}	response.Body{data=service.OrderDetail}
// @Failure		400		{object}	response.Body
// @Failure		404		{object}	response.Body
// @Failure		422		{object}	response.Body
// @Security		BearerAuth
// @Router			/admin/orders/{id}/payment [put]
func (h *OrderHandler) UpdatePayment(c *gin.Context) {
	id, err := orderID(c)
	if err != nil {
		response.Error(c, http.StatusBadRequest, "ID đơn hàng không hợp lệ")
		return
	}

	var req dto.OrderPaymentRequest
	if !bindJSON(c, &req) {
		return
	}

	res, err := h.svc.UpdatePayment(c.Request.Context(), id, &req)
	if err != nil {
		respondOrderError(c, err, "Lỗi cập nhật thanh toán")
		return
	}
	response.OKMessage(c, "Đã cập nhật tình trạng thanh toán", res)
}

// @Summary		Ghi chú nội bộ cho đơn hàng
// @Description	Lưu ghi chú của admin/nhân viên trên đơn (khách hàng không nhìn thấy).
// @Tags			Admin - Orders
// @Accept			json
// @Produce		json
// @Param			id		path		int					true	"ID đơn hàng"
// @Param			body	body		dto.OrderNoteRequest	true	"Ghi chú nội bộ"
// @Success		200		{object}	response.Body{data=service.OrderDetail}
// @Failure		400		{object}	response.Body
// @Failure		404		{object}	response.Body
// @Failure		422		{object}	response.Body
// @Security		BearerAuth
// @Router			/admin/orders/{id}/note [put]
func (h *OrderHandler) UpdateNote(c *gin.Context) {
	id, err := orderID(c)
	if err != nil {
		response.Error(c, http.StatusBadRequest, "ID đơn hàng không hợp lệ")
		return
	}

	var req dto.OrderNoteRequest
	if !bindJSON(c, &req) {
		return
	}

	res, err := h.svc.UpdateNote(c.Request.Context(), id, req.AdminNote)
	if err != nil {
		respondOrderError(c, err, "Lỗi lưu ghi chú")
		return
	}
	response.OKMessage(c, "Đã lưu ghi chú", res)
}

// @Summary		Cập nhật thông tin vận chuyển đơn hàng
// @Description	Lưu đơn vị vận chuyển và mã vận đơn (tracking number) để admin theo dõi/giao hàng.
// @Tags			Admin - Orders
// @Accept			json
// @Produce		json
// @Param			id		path		int							true	"ID đơn hàng"
// @Param			body	body		dto.OrderShippingRequest	true	"Thông tin vận chuyển"
// @Success		200		{object}	response.Body{data=service.OrderDetail}
// @Failure		400		{object}	response.Body
// @Failure		404		{object}	response.Body
// @Failure		422		{object}	response.Body
// @Security		BearerAuth
// @Router			/admin/orders/{id}/shipping [put]
func (h *OrderHandler) UpdateShipping(c *gin.Context) {
	id, err := orderID(c)
	if err != nil {
		response.Error(c, http.StatusBadRequest, "ID đơn hàng không hợp lệ")
		return
	}

	var req dto.OrderShippingRequest
	if !bindJSON(c, &req) {
		return
	}

	res, err := h.svc.UpdateShipping(c.Request.Context(), id, &req)
	if err != nil {
		respondOrderError(c, err, "Lỗi cập nhật vận chuyển")
		return
	}
	response.OKMessage(c, "Đã cập nhật thông tin vận chuyển", res)
}

// Checkout godoc
//
// @Summary		Đặt hàng từ website (công khai)
// @Description	Khách ở storefront đặt hàng, không bắt buộc đăng nhập. Gửi kèm access token thì đơn được gắn vào tài khoản.
// @Description	Mỗi dòng hàng chỉ cần nói mua biến thể nào (product_variant_id, hoặc slug + size + color) và số lượng —
// @Description	TÊN, GIÁ, SKU đều được server tra lại từ database, giá client gửi lên bị bỏ qua hoàn toàn.
// @Description	Nên gửi product_variant_id: slug + size + color KHÔNG chỉ đúng một biến thể khi sản phẩm có nhiều phiên bản (FAN / PLAYER) cùng size và màu — thiếu ID thì server lấy biến thể có ID nhỏ nhất.
// @Description	Biến thể đã tắt bán (is_active = 0) bị từ chối kể cả khi client gửi thẳng ID của nó.
// @Description	Tồn kho được khoá và trừ ngay trong transaction nên nhiều khách đặt cùng lúc không bán vượt hàng.
// @Description	Phí vận chuyển: miễn phí từ 1.000.000₫, dưới ngưỡng thu 30.000₫. Đơn tạo ra ở trạng thái "chờ xác nhận".
// @Description	Có recipient_email thì hệ thống gửi email xác nhận đơn (chạy nền): gửi hỏng chỉ ghi log, đơn vẫn hợp lệ để khách không phải đặt lại và bị trừ kho hai lần.
// @Description	payment_method phải là hình thức cửa hàng đang nhận (Cài đặt → Thanh toán), nếu không trả 422. Chuyển khoản chỉ được nhận khi cửa hàng đã khai đủ ngân hàng, số tài khoản và chủ tài khoản; đơn chuyển khoản nhận lại hướng dẫn chuyển tiền trong `message` và trong email.
// @Description	payment_method = `payos` (thanh toán online): hệ thống xin cổng cấp link ngay trong lời gọi này và trả về ở trường `payos`. Cách dùng gọn nhất là gán `payos.qr_image` (ảnh PNG dạng data URI, đã vẽ sẵn) vào thẻ img cho khách quét tại chỗ; `payos.checkout_url` là trang của cổng dành cho khách dùng điện thoại không tự quét màn hình được, còn `payos.qr_code` là chuỗi VietQR thô nếu client muốn tự vẽ. Chỉ nhận được khi cửa hàng đã bật PayOS VÀ máy chủ API đã khai bộ khoá PayOS trong .env.
// @Description	Sau đó client hỏi `GET /payments/{code}` (với code = `payos.order_code`) vài giây một lần để biết tiền đã về chưa; chính lời gọi đó cũng là nơi hệ thống đối chiếu với cổng và đánh dấu đơn đã thanh toán.
// @Description	Cổng hỏng thì đơn VẪN được tạo (hàng đã trừ kho, địa chỉ đã điền) nhưng trường `payos` vắng mặt và `message` nói rõ phải liên hệ cửa hàng — không bắt khách đặt lại từ đầu.
// @Tags			Orders
// @Accept			json
// @Produce		json
// @Param			body	body		dto.CheckoutRequest	true	"Thông tin nhận hàng và danh sách sản phẩm"
// @Success		201		{object}	response.Body{data=dto.CheckoutResponse}
// @Failure		400		{object}	response.Body	"Hết hàng hoặc sản phẩm không còn bán"
// @Failure		422		{object}	response.Body	"Dữ liệu không hợp lệ, hoặc hình thức thanh toán cửa hàng không nhận"
// @Router			/orders [post]
func (h *OrderHandler) Checkout(c *gin.Context) {
	var req dto.CheckoutRequest
	if !bindJSON(c, &req) {
		return
	}

	// OptionalJWTAuth đặt sẵn user id nếu khách có đăng nhập; 0 = khách vãng lai.
	userID := c.GetUint(middleware.CtxUserID)

	res, err := h.svc.Checkout(c.Request.Context(), &req, userID)
	if err != nil {
		respondOrderError(c, err, "Lỗi đặt hàng")
		return
	}
	response.Created(c, res)
}

// MyList godoc
//
// @Summary		Đơn hàng của tôi (khách đã đăng nhập)
// @Description	Danh sách đơn của CHÍNH tài khoản đang đăng nhập, mới nhất trước, kèm sản phẩm từng đơn.
// @Description	Khách hàng chỉ thấy đơn của mình: id tài khoản lấy từ access token, không nhận `user_id` từ client.
// @Description	Ghi chú nội bộ của admin được ẩn khỏi kết quả.
// @Tags			Orders
// @Accept			json
// @Produce		json
// @Param			status		query		string	false	"Lọc trạng thái: all|pending|confirmed|processing|shipping|delivered|completed|cancelled|returned — nhiều giá trị ngăn cách bởi dấu phẩy"
// @Param			page		query		int		false	"Trang (mặc định 1)"
// @Param			page_size	query		int		false	"Số đơn/trang (mặc định 10, tối đa 50)"
// @Success		200			{object}	response.Body{meta=response.Pagination}
// @Failure		401			{object}	response.Body
// @Security		BearerAuth
// @Router			/orders/me [get]
func (h *OrderHandler) MyList(c *gin.Context) {
	page, _ := strconv.Atoi(c.DefaultQuery("page", "1"))
	pageSize, _ := strconv.Atoi(c.DefaultQuery("page_size", "10"))
	if page < 1 {
		page = 1
	}
	if pageSize < 1 || pageSize > 50 {
		pageSize = 10
	}

	orders, total, err := h.svc.MyOrders(c.Request.Context(), c.GetUint(middleware.CtxUserID), domain.OrderFilter{
		Status:   c.Query("status"),
		Sort:     c.Query("sort"),
		Page:     page,
		PageSize: pageSize,
	})
	if err != nil {
		respondOrderError(c, err, "Lỗi truy vấn đơn hàng của bạn")
		return
	}

	totalPages := 1
	if total > 0 {
		totalPages = int((total + int64(pageSize) - 1) / int64(pageSize))
	}
	response.Paginated(c, orders, response.Pagination{
		Page: page, PageSize: pageSize, Total: total, TotalPages: totalPages,
	})
}

// MyGet godoc
//
// @Summary		Chi tiết đơn hàng của tôi (khách đã đăng nhập)
// @Description	Đơn kèm sản phẩm và lịch sử chuyển trạng thái. Đơn KHÔNG thuộc tài khoản đang đăng nhập trả về 404 (không phải 403) để không lộ đơn của người khác qua việc thử ID.
// @Tags			Orders
// @Accept			json
// @Produce		json
// @Param			id	path		int	true	"ID đơn hàng"
// @Success		200	{object}	response.Body{data=service.OrderDetail}
// @Failure		401	{object}	response.Body
// @Failure		404	{object}	response.Body
// @Security		BearerAuth
// @Router			/orders/me/{id} [get]
func (h *OrderHandler) MyGet(c *gin.Context) {
	id, err := orderID(c)
	if err != nil {
		response.Error(c, http.StatusBadRequest, "ID đơn hàng không hợp lệ")
		return
	}

	res, err := h.svc.MyOrder(c.Request.Context(), c.GetUint(middleware.CtxUserID), id)
	if err != nil {
		respondOrderError(c, err, "Lỗi tải đơn hàng")
		return
	}
	response.OK(c, res)
}

// MyCancel godoc
//
// @Summary		Huỷ đơn hàng của tôi (khách đã đăng nhập)
// @Description	Khách tự huỷ đơn của CHÍNH mình. Chỉ huỷ được khi đơn còn ở "chờ xác nhận" hoặc "đã xác nhận" —
// @Description	từ "đang chuẩn bị" trở đi hàng đã được soạn hoặc bàn giao vận chuyển nên phải liên hệ nhân viên (trả 409).
// @Description	Huỷ thành công thì TRẢ HÀNG VỀ KHO đúng bằng số đơn đã lấy, ghi mốc lịch sử và gửi email báo huỷ cho khách.
// @Description	Đơn không thuộc tài khoản đang đăng nhập trả 404 để không lộ đơn của người khác.
// @Tags			Orders
// @Accept			json
// @Produce		json
// @Param			id		path		int						true	"ID đơn hàng"
// @Param			body	body		dto.OrderCancelRequest	false	"Lý do huỷ (không bắt buộc)"
// @Success		200		{object}	response.Body{data=service.OrderDetail}
// @Failure		401		{object}	response.Body
// @Failure		404		{object}	response.Body
// @Failure		409		{object}	response.Body	"Đơn đã qua giai đoạn được tự huỷ hoặc đã huỷ trước đó"
// @Security		BearerAuth
// @Router			/orders/me/{id}/cancel [post]
func (h *OrderHandler) MyCancel(c *gin.Context) {
	id, err := orderID(c)
	if err != nil {
		response.Error(c, http.StatusBadRequest, "ID đơn hàng không hợp lệ")
		return
	}

	// Lý do không bắt buộc nên body rỗng vẫn hợp lệ.
	var req dto.OrderCancelRequest
	if c.Request.ContentLength > 0 && !bindJSON(c, &req) {
		return
	}

	res, err := h.svc.CancelMine(c.Request.Context(), c.GetUint(middleware.CtxUserID), id, req.Reason)
	if err != nil {
		respondOrderError(c, err, "Lỗi huỷ đơn hàng")
		return
	}
	response.OKMessage(c, "Đã huỷ đơn hàng", res)
}

// Quote godoc
//
// @Summary		Đối chiếu giỏ hàng (công khai)
// @Description	Tra lại GIÁ và TỒN KHO hiện tại cho từng dòng giỏ hàng rồi tính thử số tiền server sẽ tính nếu khách đặt ngay bây giờ.
// @Description	Giỏ hàng của storefront nằm ở localStorage nên giá lưu trong đó là bản chụp lúc khách thêm hàng; admin đổi giá xong thì con số ấy đã cũ.
// @Description	Trang thanh toán gọi endpoint này khi mở để cập nhật lại giỏ, thay vì để khách phát hiện chênh lệch ở màn đặt hàng thành công.
// @Description	Mỗi dòng trả về issue: rỗng (bình thường), "limited" (không đủ số lượng), "out_of_stock" (hết hàng), "unavailable" (không còn bán).
// @Description	Nên gửi product_variant_id như khi đặt hàng — thiếu ID thì dòng nào có nhiều phiên bản cùng size/màu sẽ báo giá theo biến thể ID nhỏ nhất.
// @Description	KHÔNG tạo đơn, KHÔNG giữ hàng — số tiền chính thức vẫn do luồng đặt hàng tính lại.
// @Tags			Orders
// @Accept			json
// @Produce		json
// @Param			body	body		dto.CartQuoteRequest	true	"Danh sách sản phẩm trong giỏ"
// @Success		200		{object}	response.Body{data=dto.CartQuoteResponse}
// @Failure		400		{object}	response.Body	"Giỏ hàng trống"
// @Failure		422		{object}	response.Body	"Dữ liệu không hợp lệ"
// @Router			/orders/quote [post]
func (h *OrderHandler) Quote(c *gin.Context) {
	var req dto.CartQuoteRequest
	if !bindJSON(c, &req) {
		return
	}

	res, err := h.svc.Quote(c.Request.Context(), &req)
	if err != nil {
		respondOrderError(c, err, "Lỗi đối chiếu giỏ hàng")
		return
	}
	response.OK(c, res)
}

// @Summary		Tra cứu đơn hàng (công khai)
// @Description	Tra cứu tình trạng đơn theo mã đơn (order_code) cho khách hàng ở storefront — không cần đăng nhập. Trả về đơn kèm danh sách sản phẩm, đã ẩn ghi chú nội bộ.
// @Tags			Orders
// @Accept			json
// @Produce		json
// @Param			order_code	query		string	true	"Mã đơn hàng (VD: FB-2026-000123)"
// @Success		200			{object}	response.Body{data=domain.Order}
// @Failure		400			{object}	response.Body
// @Failure		404			{object}	response.Body
// @Router			/public/orders/lookup [get]
func (h *OrderHandler) Lookup(c *gin.Context) {
	code := strings.TrimSpace(c.Query("order_code"))
	if code == "" {
		response.Error(c, http.StatusBadRequest, "Vui lòng nhập mã đơn hàng")
		return
	}

	order, err := h.svc.Lookup(c.Request.Context(), code)
	if err != nil {
		respondOrderError(c, err, "Lỗi tra cứu đơn hàng")
		return
	}
	response.OK(c, order)
}

func orderID(c *gin.Context) (uint, error) {
	id, err := strconv.ParseUint(c.Param("id"), 10, 64)
	if err != nil || id == 0 {
		return 0, errors.New("id không hợp lệ")
	}
	return uint(id), nil
}

func respondOrderError(c *gin.Context, err error, fallback string) {
	// Mã giảm giá khách nhập: dùng chung câu chữ với ô kiểm mã ở giỏ hàng.
	if voucherUseError(c, err) {
		return
	}

	switch {
	case errors.Is(err, domain.ErrNotFound):
		response.Error(c, http.StatusNotFound, "Không tìm thấy đơn hàng")
	case errors.Is(err, domain.ErrInvalidStatus):
		response.Error(c, http.StatusUnprocessableEntity, "Không thể chuyển đơn sang trạng thái này")
	case errors.Is(err, domain.ErrConflict):
		response.Error(c, http.StatusConflict, "Đơn hàng đang ở trạng thái này rồi")
	case errors.Is(err, domain.ErrCancelNotAllowed):
		response.Error(c, http.StatusConflict,
			"Đơn đã được tiếp nhận xử lý nên không tự huỷ được, vui lòng gọi 079.6666.468 để nhân viên hỗ trợ")
	case errors.Is(err, domain.ErrReturnInProgress):
		response.Error(c, http.StatusConflict,
			"Đơn này đã có phiếu trả hàng riêng. Hãy xử lý trên phiếu trả — đơn tự chuyển sang \"hoàn hàng\" khi mọi sản phẩm đã về kho.")
	case errors.Is(err, domain.ErrForbidden):
		response.Error(c, http.StatusForbidden, "Không có quyền thực hiện thao tác này")
	case errors.Is(err, domain.ErrOutOfStock):
		// err đã kèm tên sản phẩm và số lượng còn lại
		response.Error(c, http.StatusBadRequest, "Không đủ hàng — "+
			strings.TrimPrefix(err.Error(), domain.ErrOutOfStock.Error()+": "))
	case errors.Is(err, domain.ErrVariantNotFound):
		response.Error(c, http.StatusBadRequest, "Sản phẩm trong giỏ không còn bán hoặc đã đổi phiên bản, vui lòng chọn lại")
	case errors.Is(err, domain.ErrEmptyCart):
		response.Error(c, http.StatusBadRequest, "Giỏ hàng đang trống")
	case errors.Is(err, domain.ErrPaymentMethodDisabled):
		response.Error(c, http.StatusUnprocessableEntity,
			"Cửa hàng hiện không nhận hình thức thanh toán này, vui lòng chọn cách khác")
	default:
		response.Error(c, http.StatusInternalServerError, fallback)
	}
}
