package handler

import (
	"time"

	"github.com/gin-gonic/gin"

	"sass-api/internal/middleware"
	"sass-api/internal/service"
	"sass-api/pkg/response"
)

// KhachHangHandler phục vụ ba màn hình còn lại của KHU ĐIỀU HÀNH: khách hàng,
// hợp đồng (Người dùng thử · Người dùng chính thức), doanh thu theo quán.
//
// Mọi đường nằm sau middleware.XacThucNenTang, và mọi hàm truyền `quyen` xuống
// service — đây là ba đường đọc XUYÊN MỌI KHÁCH HÀNG, nên không có lưới nào
// phía dưới đỡ hộ nếu quên.
type KhachHangHandler struct {
	svc service.KhachHangService
}

func NewKhachHangHandler(svc service.KhachHangService) *KhachHangHandler {
	return &KhachHangHandler{svc: svc}
}

// KhachHang godoc
//
//	@Summary		Khách hàng của một phần mềm
//	@Description	Chỉ khách CÓ hợp đồng: quan hệ "khách của phần mềm này" tồn tại qua hợp
//	@Description	đồng, không qua bảng khách hàng. Mặc định bỏ hợp đồng đã huỷ — muốn xem
//	@Description	khách cũ thì lọc `?trang_thai=canceled`.
//	@Description	Chỉ phần mềm người gọi được phụ trách (owner thấy hết).
//	@Tags			Platform - Khách hàng
//	@Produce		json
//	@Security		BearerAuth
//	@Param			app			query		string	false	"Mã phần mềm; bỏ trống = mọi phần mềm được giao"
//	@Param			trang_thai	query		string	false	"trial | active | past_due | canceled"
//	@Success		200			{object}	response.Body{data=dto.KhachHangResponse}
//	@Failure		401			{object}	response.Body
//	@Failure		403			{object}	response.Body
//	@Router			/platform/tenants [get]
func (h *KhachHangHandler) KhachHang(c *gin.Context) {
	res, err := h.svc.KhachHang(c.Request.Context(), middleware.PlatformApps(c),
		c.Query("app"), c.Query("trang_thai"))
	if err != nil {
		handleServiceError(c, err)
		return
	}
	response.OK(c, res)
}

// HopDong godoc
//
//	@Summary		Hợp đồng đã ký
//	@Description	Màn hình "Người dùng thử" là `?trang_thai=trial`, "Người dùng chính thức"
//	@Description	là `?trang_thai=active`. Sắp theo ngày hết hạn gần nhất trước —
//	@Description	`con_lai_ngay` âm nghĩa là ĐÃ QUÁ HẠN.
//	@Description	Hạn mức trong câu trả lời lấy THẲNG từ hợp đồng, không tra bảng giá: bảng
//	@Description	giá được phép đổi, hợp đồng đã ký thì không. Giá trị 0 = không giới hạn.
//	@Tags			Platform - Khách hàng
//	@Produce		json
//	@Security		BearerAuth
//	@Param			app			query		string	false	"Mã phần mềm; bỏ trống = mọi phần mềm được giao"
//	@Param			trang_thai	query		string	false	"trial | active | past_due | canceled"
//	@Success		200			{object}	response.Body{data=dto.HopDongResponse}
//	@Failure		401			{object}	response.Body
//	@Failure		403			{object}	response.Body
//	@Router			/platform/subscriptions [get]
func (h *KhachHangHandler) HopDong(c *gin.Context) {
	res, err := h.svc.HopDong(c.Request.Context(), middleware.PlatformApps(c),
		c.Query("app"), c.Query("trang_thai"))
	if err != nil {
		handleServiceError(c, err)
		return
	}
	response.OK(c, res)
}

// DoanhThu godoc
//
//	@Summary		Doanh thu theo quán
//	@Description	TIỀN ĐÃ VÀO (sổ thu `invoices`), KHÔNG phải tiền đáng lẽ phải thu theo
//	@Description	hợp đồng. Sổ thu trống thì doanh thu là 0 kể cả khi có hợp đồng đang chạy
//	@Description	— và đó là câu trả lời đúng.
//	@Description	Lọc theo NGÀY TIỀN VÀO. `tu` tính từ 00:00 ngày đó, `den` là mốc CHẶN
//	@Description	TRÊN không bao gồm — muốn trọn tháng 8 thì tu=2026-08-01&den=2026-09-01.
//	@Tags			Platform - Khách hàng
//	@Produce		json
//	@Security		BearerAuth
//	@Param			app	query		string	false	"Mã phần mềm; bỏ trống = mọi phần mềm được giao"
//	@Param			tu	query		string	false	"Từ ngày, dạng 2006-01-02"
//	@Param			den	query		string	false	"Tới ngày (không bao gồm), dạng 2006-01-02"
//	@Success		200	{object}	response.Body{data=dto.DoanhThuResponse}
//	@Failure		401	{object}	response.Body
//	@Failure		403	{object}	response.Body
//	@Failure		422	{object}	response.Body
//	@Router			/platform/doanh-thu [get]
func (h *KhachHangHandler) DoanhThu(c *gin.Context) {
	// Ngày tháng parse ở TẦNG HTTP, không đẩy chuỗi xuống service: định dạng của
	// tham số URL là chuyện của tầng này, và service nhận *time.Time thì không
	// có cách nào truyền nhầm một chuỗi chưa kiểm.
	tu, ok := ngayQuery(c, "tu")
	if !ok {
		return
	}
	den, ok := ngayQuery(c, "den")
	if !ok {
		return
	}

	res, err := h.svc.DoanhThu(c.Request.Context(), middleware.PlatformApps(c), c.Query("app"), tu, den)
	if err != nil {
		handleServiceError(c, err)
		return
	}
	response.OK(c, res)
}

// ngayQuery đọc một tham số ngày dạng 2006-01-02.
//
// Trả về (nil, true) khi tham số vắng mặt — vắng nghĩa là KHÔNG giới hạn đầu
// đó, khác hẳn với gõ sai định dạng (422). Gộp hai thứ đó lại thì một ngày gõ
// nhầm sẽ âm thầm thành "từ trước tới nay" và báo cáo ra một con số to bất ngờ.
func ngayQuery(c *gin.Context, ten string) (*time.Time, bool) {
	raw := c.Query(ten)
	if raw == "" {
		return nil, true
	}

	// time.Local chứ không UTC: mốc "ngày 1 tháng 8" của người đọc báo cáo là
	// nửa đêm theo giờ Việt Nam, và `paid_at` cũng được ghi theo giờ máy chủ.
	t, err := time.ParseInLocation("2006-01-02", raw, time.Local)
	if err != nil {
		response.ValidationError(c, map[string]string{ten: "Ngày phải theo dạng 2006-01-02"})
		return nil, false
	}

	return &t, true
}
