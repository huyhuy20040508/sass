package handler

import (
	"errors"
	"io"
	"net/http"

	"github.com/gin-gonic/gin"

	"sass-api/internal/dto"
	"sass-api/internal/service"
	"sass-api/internal/tenant"
	"sass-api/pkg/payos"
	"sass-api/pkg/response"
)

// GiaHanHandler phục vụ luồng KHÁCH TỰ GIA HẠN.
//
// Hai loại đường trong cùng một handler, và chúng khác nhau ở chỗ quan trọng
// nhất — AI GỌI:
//
//   - Dat / TrangThai: chủ tiệm, sau token cửa hàng. Cửa hàng nào thì đọc từ
//     context của request, KHÔNG nhận từ body.
//   - Webhook: CỔNG THANH TOÁN, không có token nào. Thứ chứng minh gói dữ liệu
//     đến từ PayOS là chữ ký HMAC trên body nguyên văn.
//
// Hai đường đầu nằm trong danh sách CHO PHÉP KHI CỬA HÀNG BỊ KHOÁ (xem
// middleware.choPhepKhiCuaHangKhoa): khách hết hạn chính là người cần trả tiền
// nhất, đóng đường thanh toán của họ là tự cắt doanh thu của mình.
type GiaHanHandler struct {
	svc service.GiaHanService
}

func NewGiaHanHandler(svc service.GiaHanService) *GiaHanHandler {
	return &GiaHanHandler{svc: svc}
}

// Dat godoc
//
//	@Summary		Đặt đơn gia hạn và lấy link thanh toán
//	@Description	Tạo đơn gia hạn cho hợp đồng hiện tại của cửa hàng đang đăng nhập rồi sinh
//	@Description	link thanh toán. Số tiền do MÁY CHỦ tính (giá bảng giá × số tháng) và chốt
//	@Description	vào đơn — bảng giá đổi sau đó không làm đổi số khách phải trả.
//	@Description	Đơn cũ còn hiệu lực cho cùng gói và cùng số tháng thì trả lại đơn đó thay vì
//	@Description	sinh link mới.
//	@Description	Gói "Liên hệ" (chưa công bố giá) và gói đã ngừng bán đều bị từ chối.
//	@Tags			Admin - Gói dịch vụ
//	@Accept			json
//	@Produce		json
//	@Security		BearerAuth
//	@Param			body	body		dto.DatGiaHanRequest	true	"Gói và số tháng"
//	@Success		200		{object}	response.Body{data=dto.DonGiaHanResponse}
//	@Failure		401		{object}	response.Body
//	@Failure		403		{object}	response.Body
//	@Failure		404		{object}	response.Body
//	@Failure		422		{object}	response.Body
//	@Failure		503		{object}	response.Body
//	@Router			/admin/goi-dich-vu/dat [post]
func (h *GiaHanHandler) Dat(c *gin.Context) {
	tenantID, ok := tenant.ID(c.Request.Context())
	if !ok {
		response.Error(c, 401, "Chưa xác định được cửa hàng cho yêu cầu này")
		return
	}

	var req dto.DatGiaHanRequest
	if !bindJSON(c, &req) {
		return
	}

	res, err := h.svc.Dat(c.Request.Context(), tenantID, req.PlanID, req.SoThang)
	if err != nil {
		handleServiceError(c, err)
		return
	}
	response.OK(c, res)
}

// TrangThai godoc
//
//	@Summary		Trạng thái một đơn gia hạn
//	@Description	Trang thanh toán hỏi lại đường này mỗi vài giây. Đơn còn chờ thì máy chủ HỎI
//	@Description	THẲNG CỔNG xem tiền vào chưa — đây là đường xác nhận dự phòng cho webhook,
//	@Description	cần cho cả lúc chạy ở máy local (cổng không gọi vào localhost được) lẫn lúc
//	@Description	webhook tới trễ.
//	@Description	Tiền đã vào thì chính lượt gọi này chốt đơn: ghi sổ thu, đẩy hạn hợp đồng và
//	@Description	mở khoá cửa hàng. Chốt đúng MỘT LẦN cho mỗi đơn, dù gọi bao nhiêu lần.
//	@Tags			Admin - Gói dịch vụ
//	@Produce		json
//	@Security		BearerAuth
//	@Param			id	path		int	true	"ID đơn gia hạn"
//	@Success		200	{object}	response.Body{data=dto.DonGiaHanResponse}
//	@Failure		401	{object}	response.Body
//	@Failure		403	{object}	response.Body
//	@Failure		404	{object}	response.Body
//	@Router			/admin/goi-dich-vu/don/{id} [get]
func (h *GiaHanHandler) TrangThai(c *gin.Context) {
	tenantID, ok := tenant.ID(c.Request.Context())
	if !ok {
		response.Error(c, 401, "Chưa xác định được cửa hàng cho yêu cầu này")
		return
	}

	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}

	res, err := h.svc.TrangThai(c.Request.Context(), tenantID, id)
	if err != nil {
		handleServiceError(c, err)
		return
	}
	response.OK(c, res)
}

// Webhook godoc
//
//	@Summary		Webhook PayOS báo tiền gia hạn đã vào (công khai)
//	@Description	PayOS gọi vào đây khi một đơn gia hạn được trả tiền. KHÔNG có token: thứ
//	@Description	chứng minh gói dữ liệu đến từ PayOS là chữ ký HMAC-SHA256 tính trên chính
//	@Description	body — sai chữ ký thì từ chối thẳng.
//	@Description	Tiền vào ĐỦ số của đơn thì hợp đồng được đẩy hạn và cửa hàng mở khoá ngay.
//	@Description	Trả thiếu thì chỉ ghi nhật ký để người bán đối soát tay, KHÔNG tự gia hạn.
//	@Description	Mã đơn lạ (của hệ thống khác dùng chung kênh) vẫn trả 200 kèm log — trả lỗi
//	@Description	chỉ khiến PayOS gửi lại gói đó suốt nhiều giờ.
//	@Tags			Platform - Gia hạn
//	@Accept			json
//	@Produce		json
//	@Success		200	{object}	response.Body	"Đã nhận (hoặc đã bỏ qua)"
//	@Failure		400	{object}	response.Body	"Không đọc được dữ liệu"
//	@Failure		401	{object}	response.Body	"Chữ ký không hợp lệ"
//	@Router			/platform/webhook/payos [post]
func (h *GiaHanHandler) Webhook(c *gin.Context) {
	// Đọc body NGUYÊN VĂN: chữ ký tính trên đúng chuỗi byte này, nên không được
	// parse rồi dựng lại trước khi kiểm.
	raw, err := io.ReadAll(io.LimitReader(c.Request.Body, 1<<20))
	if err != nil {
		response.Error(c, http.StatusBadRequest, "Không đọc được dữ liệu webhook")
		return
	}

	// PayOS gọi thử một lượt "ping" lúc đăng ký địa chỉ webhook, và gói đó không
	// mang đơn nào. Trả 200 để họ chấp nhận địa chỉ.
	if len(raw) == 0 {
		response.OKMessage(c, "Đã nhận", nil)
		return
	}

	if err := h.svc.XuLyWebhook(c.Request.Context(), raw); err != nil {
		switch {
		case errors.Is(err, payos.ErrSignature):
			// Chữ ký sai = coi như dữ liệu giả. 401 và KHÔNG nói gì thêm.
			response.Error(c, http.StatusUnauthorized, "Chữ ký webhook không hợp lệ")
		default:
			// Lỗi phía mình (database, cổng): trả 500 để PayOS gửi lại — lượt sau có
			// thể thành công, và cơ chế chốt đơn chỉ chạy một lần nên gửi lại là an toàn.
			response.Error(c, http.StatusInternalServerError, "Chưa xử lý được, vui lòng gửi lại")
		}

		return
	}

	response.OKMessage(c, "Đã nhận", nil)
}
