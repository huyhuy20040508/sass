package handler

import (
	"net/http"
	"strconv"

	"github.com/gin-gonic/gin"

	"sass-api/internal/domain"
	"sass-api/internal/dto"
	"sass-api/internal/service"
	"sass-api/pkg/response"
)

// CongNoHandler — Thu chi → Công nợ.
type CongNoHandler struct {
	svc service.CongNoService
}

func NewCongNoHandler(svc service.CongNoService) *CongNoHandler {
	return &CongNoHandler{svc: svc}
}

// List godoc
//
//	@Summary		Sổ công nợ
//	@Description	Khoản nợ nhà cung cấp: phiếu mua ĐÃ DUYỆT và hai bên đã thoả thuận cho nợ (`is_debt`). Chưa có nợ khách hàng vì chưa có bảng đơn bán.
//	@Description	`meta` gộp phân trang VỚI bốn con số của bốn nút lọc nhanh (tất cả / gần đến hạn / quá hạn / đến hạn hôm nay) và tổng tiền còn nợ.
//	@Description	Bốn con số ấy theo mọi bộ lọc đang bật NHƯNG bỏ qua `due` — bốn nút là để so với nhau, đổi theo mốc đang bấm thì không còn so được.
//	@Description	`days_left` âm là đã quá hạn ngần ấy ngày; null là phiếu không ghi hạn.
//	@Tags			Admin - Công nợ
//	@Produce		json
//	@Security		BearerAuth
//	@Param			supplier_name	query	string	false	"Tên nhà cung cấp, khớp một phần"
//	@Param			code			query	string	false	"Mã phiếu mua, khớp một phần"
//	@Param			supplier_id	query		string	false	"Id nhà cung cấp, ngăn bởi dấu phẩy"
//	@Param			created_by	query		string	false	"Id người lập phiếu, ngăn bởi dấu phẩy"
//	@Param			status		query		string	false	"unpaid | partial | paid, ngăn bởi dấu phẩy"
//	@Param			due			query		string	false	"all | near | over | today"
//	@Param			near_days	query		int		false	"Bề rộng mốc 'gần đến hạn', mặc định 7 ngày"
//	@Param			page		query		int		false	"Trang, mặc định 1"
//	@Param			page_size	query		int		false	"Số dòng mỗi trang, mặc định 10"
//	@Success		200			{object}	response.Body{data=[]domain.CongNo,meta=dto.CongNoMeta}
//	@Failure		401			{object}	response.Body
//	@Router			/admin/cong-no [get]
func (h *CongNoHandler) List(c *gin.Context) {
	page, _ := strconv.Atoi(c.DefaultQuery("page", "1"))
	size, _ := strconv.Atoi(c.DefaultQuery("page_size", "10"))
	if page < 1 {
		page = 1
	}
	// Kẹp trần như mọi đường danh sách khác: xin 100000 dòng là quét cả bảng.
	if size < 1 || size > 1000 {
		size = 10
	}

	// near_days lạ hoặc âm thì rơi về mặc định chứ không báo lỗi: bộ lọc hỏng
	// phải cho ra danh sách, không phải một câu lỗi.
	ngayGan, _ := strconv.Atoi(c.Query("near_days"))
	if ngayGan <= 0 || ngayGan > 365 {
		ngayGan = domain.CongNoSoNgayGan
	}

	f := domain.CongNoFilter{
		SupplierName: c.Query("supplier_name"),
		Code:         c.Query("code"),
		SupplierID:   c.Query("supplier_id"),
		CreatedBy:    c.Query("created_by"),
		Status:       c.Query("status"),
		Due:          c.Query("due"),
		SoNgayGan:    ngayGan,
		ShopID:       chiNhanhLoc(c),
		Page:         page,
		PageSize:     size,
	}

	list, total, tk, err := h.svc.List(c.Request.Context(), f)
	if err != nil {
		handleServiceError(c, err)

		return
	}

	// KHÔNG dùng response.Paginated: `meta` ở đây gộp phân trang với năm con số
	// của hàng nút lọc. Xem dto.CongNoMeta.
	c.JSON(http.StatusOK, response.Body{
		Success: true,
		Data:    list,
		Meta: dto.CongNoMeta{
			Pagination: response.Pagination{
				Page:       page,
				PageSize:   size,
				Total:      total,
				TotalPages: int((total + int64(size) - 1) / int64(size)),
			},
			CongNoTongKet: tk,
		},
	})
}

// LichSuTra godoc
//
//	@Summary		Sổ từng lượt trả của một khoản nợ
//	@Description	`id` là id PHIẾU MUA — công nợ không có id riêng, xem chú thích ở domain.CongNo.
//	@Description	Cũ trước mới sau. `amount` ÂM là lượt chữa lại con số đã ghi sai, không phải lượt trả.
//	@Tags			Admin - Công nợ
//	@Produce		json
//	@Security		BearerAuth
//	@Param			id	path		int	true	"Id phiếu mua hàng"
//	@Success		200	{object}	response.Body{data=[]domain.PurchasePayment}
//	@Failure		404	{object}	response.Body
//	@Router			/admin/cong-no/{id}/lich-su-tra [get]
func (h *CongNoHandler) LichSuTra(c *gin.Context) {
	id, err := strconv.ParseUint(c.Param("id"), 10, 64)
	if err != nil || id == 0 {
		response.Error(c, http.StatusBadRequest, "id không hợp lệ")

		return
	}

	ds, err := h.svc.LichSuTra(c.Request.Context(), uint(id))
	if err != nil {
		handleServiceError(c, err)

		return
	}

	response.OK(c, ds)
}
