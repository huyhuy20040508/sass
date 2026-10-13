package handler

import (
	"errors"

	"github.com/gin-gonic/gin"

	"sass-api/internal/domain"
	"sass-api/internal/middleware"
	"sass-api/internal/service"
	"sass-api/pkg/response"
)

// PaymentMethodHandler — Cài đặt → Phương thức thanh toán của chi nhánh đang làm.
//
// Chi nhánh lấy từ header X-Chi-Nhanh như mọi màn theo chi nhánh; mọi đường đều
// dưới /admin/payment-methods. Đường /admin/orders/pos/payment-methods là bản
// rút gọn cho quầy: chỉ dòng đang bật, không kèm khoá bí mật.
type PaymentMethodHandler struct {
	svc service.PaymentMethodService
}

func NewPaymentMethodHandler(svc service.PaymentMethodService) *PaymentMethodHandler {
	return &PaymentMethodHandler{svc: svc}
}

// loiPT trả lỗi nghiệp vụ nguyên câu; lỗi khác đi đường chung.
func (h *PaymentMethodHandler) loi(c *gin.Context, err error) {
	var l *service.LoiPT
	if errors.As(err, &l) {
		if len(l.O) > 0 {
			response.ValidationError(c, l.O)

			return
		}
		response.Error(c, l.Ma, l.Cau)

		return
	}
	// Câu chung của lỗi này nói về "chứng từ thuộc kho nào" — sai ngữ cảnh ở đây.
	if errors.Is(err, domain.ErrChuaChonChiNhanh) {
		response.Error(c, 409, "Chọn một chi nhánh ở thanh trên cùng để cấu hình — mỗi chi nhánh có bộ phương thức thanh toán riêng.")

		return
	}
	handleServiceError(c, err)
}

// List godoc
//
//	@Summary		Phương thức thanh toán của chi nhánh
//	@Description	4 phương thức (1 tiền mặt, 4 chuyển khoản, 5 quẹt thẻ, 6 QR tự động) kèm chi tiết,
//	@Description	máy quẹt thẻ và danh sách ngân hàng VietQR. Khoá bí mật không bao giờ trả ra.
//	@Tags			Admin - Phương thức thanh toán
//	@Produce		json
//	@Security		BearerAuth
//	@Success		200	{object}	response.Body{data=service.PMDanhSach}
//	@Router			/admin/payment-methods [get]
func (h *PaymentMethodHandler) List(c *gin.Context) {
	ds, err := h.svc.DanhSach(c.Request.Context())
	if err != nil {
		h.loi(c, err)

		return
	}
	// Màn hình cần biết người xem có sửa được không để khoá công tắc — chặn
	// thật vẫn là việc của từng đường ghi.
	response.OK(c, gin.H{
		"shop_id": ds.ShopID, "web_shop_id": ds.WebShopID, "methods": ds.Methods,
		"devices": ds.Devices, "banks": ds.Banks,
		"can_edit": middleware.CoQuyen(c, "phuong-thuc-tt.sua"),
	})
}

type pmBatTatRequest struct {
	Status *bool `json:"status" binding:"required"`
}

// Status godoc
//
//	@Summary	Bật / tắt một phương thức
//	@Tags		Admin - Phương thức thanh toán
//	@Param		id	path	int	true	"ID phương thức"
//	@Router		/admin/payment-methods/{id}/status [put]
func (h *PaymentMethodHandler) Status(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}
	var req pmBatTatRequest
	if !bindJSON(c, &req) {
		return
	}
	if err := h.svc.DatTrangThai(c.Request.Context(), id, *req.Status); err != nil {
		h.loi(c, err)

		return
	}
	response.OK(c, nil)
}

// Default godoc
//
//	@Summary	Đặt phương thức mở sẵn khi thanh toán ở quầy
//	@Tags		Admin - Phương thức thanh toán
//	@Param		id	path	int	true	"ID phương thức"
//	@Router		/admin/payment-methods/{id}/default [put]
func (h *PaymentMethodHandler) Default(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}
	if err := h.svc.DatMacDinh(c.Request.Context(), id); err != nil {
		h.loi(c, err)

		return
	}
	response.OK(c, nil)
}

// CreateDetail godoc
//
//	@Summary	Thêm tài khoản / máy quẹt thẻ / cổng QR
//	@Tags		Admin - Phương thức thanh toán
//	@Param		id		path	int							true	"ID phương thức"
//	@Param		body	body	service.PMChiTietRequest	true	"Chi tiết"
//	@Router		/admin/payment-methods/{id}/details [post]
func (h *PaymentMethodHandler) CreateDetail(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}
	var req service.PMChiTietRequest
	if !bindJSON(c, &req) {
		return
	}
	moi, err := h.svc.TaoChiTiet(c.Request.Context(), id, req)
	if err != nil {
		h.loi(c, err)

		return
	}
	response.OK(c, gin.H{"id": moi})
}

// UpdateDetail godoc
//
//	@Summary	Sửa một chi tiết. Ô khoá bí mật bỏ trống = giữ khoá đã lưu.
//	@Tags		Admin - Phương thức thanh toán
//	@Router		/admin/payment-methods/{id}/details/{detailId} [put]
func (h *PaymentMethodHandler) UpdateDetail(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}
	did, ok := parseUintParam(c, "detailId")
	if !ok {
		return
	}
	var req service.PMChiTietRequest
	if !bindJSON(c, &req) {
		return
	}
	if err := h.svc.SuaChiTiet(c.Request.Context(), id, did, req); err != nil {
		h.loi(c, err)

		return
	}
	response.OK(c, nil)
}

// DetailStatus godoc
//
//	@Summary	Bật / tắt một chi tiết
//	@Tags		Admin - Phương thức thanh toán
//	@Router		/admin/payment-methods/{id}/details/{detailId}/status [put]
func (h *PaymentMethodHandler) DetailStatus(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}
	did, ok := parseUintParam(c, "detailId")
	if !ok {
		return
	}
	var req pmBatTatRequest
	if !bindJSON(c, &req) {
		return
	}
	if err := h.svc.DatTrangThaiChiTiet(c.Request.Context(), id, did, *req.Status); err != nil {
		h.loi(c, err)

		return
	}
	response.OK(c, nil)
}

// DetailDefault godoc
//
//	@Summary	Đặt tài khoản / máy mặc định
//	@Tags		Admin - Phương thức thanh toán
//	@Router		/admin/payment-methods/{id}/details/{detailId}/default [put]
func (h *PaymentMethodHandler) DetailDefault(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}
	did, ok := parseUintParam(c, "detailId")
	if !ok {
		return
	}
	if err := h.svc.DatMacDinhChiTiet(c.Request.Context(), id, did); err != nil {
		h.loi(c, err)

		return
	}
	response.OK(c, nil)
}

// DeleteDetail godoc
//
//	@Summary	Xoá một chi tiết
//	@Tags		Admin - Phương thức thanh toán
//	@Router		/admin/payment-methods/{id}/details/{detailId} [delete]
func (h *PaymentMethodHandler) DeleteDetail(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}
	did, ok := parseUintParam(c, "detailId")
	if !ok {
		return
	}
	if err := h.svc.XoaChiTiet(c.Request.Context(), id, did); err != nil {
		h.loi(c, err)

		return
	}
	response.OK(c, nil)
}

type pmKiemTraRequest struct {
	WebhookURL string `json:"webhook_url" binding:"required,max=500"`
}

// TestDetail godoc
//
//	@Summary		Kiểm tra kết nối một cổng QR
//	@Description	PayOS: gọi confirm-webhook bằng khoá đã lưu (PayOS gửi thử một gói tới URL riêng).
//	@Description	SePay: báo đã nhận webhook chưa; có API token thì thử tra sao kê.
//	@Tags			Admin - Phương thức thanh toán
//	@Router			/admin/payment-methods/{id}/details/{detailId}/test [post]
func (h *PaymentMethodHandler) TestDetail(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}
	did, ok := parseUintParam(c, "detailId")
	if !ok {
		return
	}
	var req pmKiemTraRequest
	if !bindJSON(c, &req) {
		return
	}
	kq, err := h.svc.KiemTraKetNoi(c.Request.Context(), id, did, req.WebhookURL)
	if err != nil {
		h.loi(c, err)

		return
	}
	response.OK(c, kq)
}

// CreateDevice godoc
//
//	@Summary	Thêm máy quẹt thẻ cho chi nhánh
//	@Tags		Admin - Phương thức thanh toán
//	@Param		body	body	service.PMMayRequest	true	"Máy"
//	@Router		/admin/payment-devices [post]
func (h *PaymentMethodHandler) CreateDevice(c *gin.Context) {
	var req service.PMMayRequest
	if !bindJSON(c, &req) {
		return
	}
	d, err := h.svc.TaoMay(c.Request.Context(), req)
	if err != nil {
		h.loi(c, err)

		return
	}
	response.OK(c, d)
}

// UpdateDevice godoc
//
//	@Summary	Sửa POS ID / tên / ghi chú của máy quẹt thẻ (loại máy giữ nguyên)
//	@Tags		Admin - Phương thức thanh toán
//	@Param		body	body	service.PMMayRequest	true	"Máy"
//	@Router		/admin/payment-devices/{id} [put]
func (h *PaymentMethodHandler) UpdateDevice(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}
	var req service.PMMayRequest
	if !bindJSON(c, &req) {
		return
	}
	d, err := h.svc.SuaMay(c.Request.Context(), id, req)
	if err != nil {
		h.loi(c, err)

		return
	}
	response.OK(c, d)
}

// DeleteDevice godoc
//
//	@Summary	Xoá máy quẹt thẻ chưa gắn cấu hình nào (đang gắn: 409)
//	@Tags		Admin - Phương thức thanh toán
//	@Router		/admin/payment-devices/{id} [delete]
func (h *PaymentMethodHandler) DeleteDevice(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}
	if err := h.svc.XoaMay(c.Request.Context(), id); err != nil {
		h.loi(c, err)

		return
	}
	response.OK(c, nil)
}

// ForPOS godoc
//
//	@Summary	Phương thức đang bật cho màn thu ngân
//	@Tags		Admin - Phương thức thanh toán
//	@Router		/admin/orders/pos/payment-methods [get]
func (h *PaymentMethodHandler) ForPOS(c *gin.Context) {
	ds, err := h.svc.ChoQuay(c.Request.Context())
	if err != nil {
		h.loi(c, err)

		return
	}
	response.OK(c, ds)
}
