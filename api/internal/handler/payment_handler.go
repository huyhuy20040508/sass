package handler

import (
	"errors"
	"io"
	"net/http"

	"github.com/gin-gonic/gin"
	"go.uber.org/zap"

	"sass-api/internal/domain"
	"sass-api/internal/service"
	"sass-api/pkg/logger"
	"sass-api/pkg/payos"
	"sass-api/pkg/response"
	"sass-api/pkg/sepay"
)

type PaymentHandler struct {
	svc service.PaymentService
}

func NewPaymentHandler(svc service.PaymentService) *PaymentHandler {
	return &PaymentHandler{svc: svc}
}

// PayOSWebhook godoc
//
//	@Summary		Webhook PayOS báo kết quả thanh toán (công khai)
//	@Description	PayOS gọi vào đây mỗi khi có tiền vào một link thanh toán. Đường này KHÔNG có token:
//	@Description	thứ chứng minh gói dữ liệu đến từ PayOS là chữ ký HMAC-SHA256 ký bằng checksum key
//	@Description	của kênh thanh toán, và mọi gói sai chữ ký đều bị từ chối.
//	@Description	Ghi nhận là idempotent — PayOS gửi lại cùng một giao dịch nhiều lần thì chỉ lần đầu
//	@Description	được tính, các lần sau vẫn nhận 200.
//	@Description	Số tiền phải khớp đúng đơn; lệch thì hệ thống ghi nhận là thất bại để nhân viên đối soát tay.
//	@Description	Địa chỉ này phải công khai trên Internet mới nhận được webhook — chạy ở máy local thì
//	@Description	PayOS không gọi vào được, lúc đó đơn được xác nhận qua GET /payments/{order_code}.
//	@Tags			Payments
//	@Accept			json
//	@Produce		json
//	@Success		200	{object}	response.Body	"Đã ghi nhận (hoặc đã bỏ qua vì trùng lặp)"
//	@Failure		400	{object}	response.Body	"Chữ ký không hợp lệ hoặc body sai định dạng"
//	@Router			/payments/payos/webhook [post]
func (h *PaymentHandler) PayOSWebhook(c *gin.Context) {
	// Đọc thô: chữ ký được tính trên đúng khối `data` như PayOS gửi, nên không thể
	// để Gin parse rồi dựng lại — dựng lại là đổi thứ tự khoá và chữ ký sai hết.
	raw, err := io.ReadAll(io.LimitReader(c.Request.Body, 1<<20))
	if err != nil {
		response.Error(c, http.StatusBadRequest, "Không đọc được dữ liệu webhook")
		return
	}

	if err := h.svc.HandlePayOSWebhook(c.Request.Context(), raw); err != nil {
		switch {
		case errors.Is(err, payos.ErrSignature):
			// Ghi log ở mức cảnh báo: hoặc có người đang thử gửi webhook giả, hoặc
			// checksum key trong .env không khớp kênh thanh toán đang dùng.
			logger.Warn("webhook PayOS sai chữ ký, đã từ chối")
			response.Error(c, http.StatusBadRequest, "Chữ ký không hợp lệ")
		case errors.Is(err, domain.ErrPaymentMethodDisabled):
			response.Error(c, http.StatusBadRequest, "Cổng thanh toán chưa được cấu hình")
		default:
			// Trả 500 để PayOS gửi lại sau: lỗi ở phía mình (database chẳng hạn) thì
			// gói dữ liệu này vẫn hợp lệ và không được phép mất.
			logger.Error("xử lý webhook PayOS lỗi", zap.Error(err))
			response.Error(c, http.StatusInternalServerError, "Lỗi xử lý webhook")
		}
		return
	}

	response.OKMessage(c, "Đã nhận", nil)
}

// SePayWebhook godoc
//
//	@Summary		Webhook SePay báo tài khoản có tiền vào (công khai)
//	@Description	SePay gọi vào đây mỗi khi tài khoản ngân hàng của cửa hàng có biến động số dư.
//	@Description	Khác PayOS ở bản chất: gói dữ liệu này KHÔNG biết gì về đơn hàng, nó chỉ nói
//	@Description	"tài khoản vừa nhận X đồng, nội dung là Y". Hệ thống dò xem mã đơn nào đang chờ
//	@Description	có mặt trong nội dung đó để biết tiền thuộc về đơn nào.
//	@Description	Thứ chứng minh gói dữ liệu đến từ SePay là khoá API trong header
//	@Description	`Authorization: Apikey <khoá>` (khai ở SEPAY_WEBHOOK_API_KEY), không phải chữ ký dữ liệu.
//	@Description	Giao dịch tiền RA bị bỏ qua; số tiền lệch với đơn thì ghi nhận là thất bại để
//	@Description	nhân viên đối soát tay, không tự đánh dấu đã thanh toán.
//	@Description	Tiền vào mà không khớp đơn nào (khách ghi sai nội dung) vẫn trả 200 kèm log —
//	@Description	trả lỗi chỉ khiến SePay gửi lại gói đó suốt 5 tiếng.
//	@Tags			Payments
//	@Accept			json
//	@Produce		json
//	@Param			Authorization	header		string	true	"Apikey <khoá webhook>"
//	@Success		200				{object}	response.Body	"Đã nhận (hoặc đã bỏ qua vì không khớp đơn nào)"
//	@Failure		401				{object}	response.Body	"Khoá webhook không hợp lệ"
//	@Router			/payments/sepay/webhook [post]
func (h *PaymentHandler) SePayWebhook(c *gin.Context) {
	raw, err := io.ReadAll(io.LimitReader(c.Request.Body, 1<<20))
	if err != nil {
		response.Error(c, http.StatusBadRequest, "Không đọc được dữ liệu webhook")
		return
	}

	if err := h.svc.HandleSePayWebhook(c.Request.Context(), c.GetHeader("Authorization"), raw); err != nil {
		switch {
		case errors.Is(err, sepay.ErrUnauthorized):
			// Hoặc có người đang thử gửi webhook giả, hoặc khoá trong .env không khớp
			// khoá đã khai bên trang quản lý SePay.
			logger.Warn("webhook SePay sai khoá, đã từ chối")
			response.Error(c, http.StatusUnauthorized, "Khoá không hợp lệ")
		case errors.Is(err, domain.ErrPaymentMethodDisabled), errors.Is(err, sepay.ErrNotConfigured):
			response.Error(c, http.StatusBadRequest, "Cổng thanh toán chưa được cấu hình")
		default:
			// 500 để SePay gửi lại sau: lỗi ở phía mình (database chẳng hạn) thì gói dữ
			// liệu này vẫn hợp lệ và không được phép mất.
			logger.Error("xử lý webhook SePay lỗi", zap.Error(err))
			response.Error(c, http.StatusInternalServerError, "Lỗi xử lý webhook")
		}
		return
	}

	// SePay coi là thành công khi nhận đúng {"success": true} kèm mã 2xx.
	c.JSON(http.StatusOK, gin.H{"success": true})
}

// Status godoc
//
//	@Summary		Tra tình trạng thanh toán của một giao dịch (công khai)
//	@Description	Dùng cho màn hình quét mã QR (hỏi lại vài giây một lần) và cho trang khách được
//	@Description	cổng đưa quay lại sau khi trả tiền. `code` là `transaction_code` nhận được lúc đặt
//	@Description	hàng — với PayOS là con số riêng của cổng, với SePay chính là mã đơn.
//	@Description	Giao dịch còn đang chờ thì hệ thống hỏi thẳng cổng chứ không đợi webhook (PayOS:
//	@Description	tra link; SePay: dò sao kê, cần SEPAY_API_TOKEN) — đây là đường xác nhận duy nhất
//	@Description	chạy được khi API nằm ở máy local, vì cổng không gọi webhook vào localhost được.
//	@Description	Tiền đã về thì đơn được đánh dấu đã thanh toán ngay tại lời gọi này.
//	@Tags			Payments
//	@Produce		json
//	@Param			code	path		string	true	"Mã giao dịch phía cổng (transaction_code)"
//	@Success		200		{object}	response.Body{data=dto.PaymentStatusResponse}
//	@Failure		404		{object}	response.Body	"Không có giao dịch nào mang mã này"
//	@Failure		422		{object}	response.Body	"Cổng thanh toán chưa được cấu hình"
//	@Router			/payments/{code} [get]
func (h *PaymentHandler) Status(c *gin.Context) {
	res, err := h.svc.Status(c.Request.Context(), c.Param("code"))
	if err != nil {
		switch {
		case errors.Is(err, domain.ErrNotFound):
			response.Error(c, http.StatusNotFound, "Không tìm thấy giao dịch này")
		case errors.Is(err, domain.ErrPaymentMethodDisabled):
			response.Error(c, http.StatusUnprocessableEntity, "Cổng thanh toán chưa được cấu hình")
		default:
			logger.Error("tra trạng thái thanh toán lỗi", zap.Error(err))
			response.Error(c, http.StatusInternalServerError, "Không tra được tình trạng thanh toán")
		}
		return
	}
	response.OK(c, res)
}
