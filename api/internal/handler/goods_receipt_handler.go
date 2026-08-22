package handler

import (
	"errors"
	"net/http"
	"strconv"

	"github.com/gin-gonic/gin"

	"sass-api/internal/domain"
	"sass-api/internal/service"
	"sass-api/pkg/response"
)

type GoodsReceiptHandler struct {
	svc service.GoodsReceiptService
}

func NewGoodsReceiptHandler(svc service.GoodsReceiptService) *GoodsReceiptHandler {
	return &GoodsReceiptHandler{svc: svc}
}

// @Summary		Danh sách đợt nhập hàng
// @Description	Liệt kê các ĐỢT hàng đã về kho theo phiếu đặt hàng nhập (mỗi lần bấm "Nhận hàng" là một đợt).
// @Description	Đợt nhập KHÔNG có bảng riêng: nó được dựng lại từ sổ kho (`inventory_transactions` với `type=import`, `reference_type=purchase_order`) bằng cách gom các bút toán cùng phiếu ghi sát nhau về thời gian. Nhờ vậy mọi đợt đã nhận từ trước đều hiện đủ.
// @Description	`code` là mã đợt dạng `<mã phiếu đặt>-N<đợt>` (VD: `PO202607300001-N2` là đợt nhận thứ hai của phiếu đó) — ổn định, dùng để tra cứu chi tiết.
// @Description	Đây là API CHỈ ĐỌC. Muốn nhập hàng thì gọi `POST /admin/purchases/{id}/receive` — chỗ duy nhất được cộng tồn kho.
// @Tags			Admin - Goods Receipts
// @Accept			json
// @Produce		json
// @Param			keyword		query		string	false	"Mã đợt / mã phiếu đặt / nhà cung cấp / người nhận / ghi chú"
// @Param			from_date	query		string	false	"Từ ngày nhận (YYYY-MM-DD)"
// @Param			to_date		query		string	false	"Đến ngày nhận (YYYY-MM-DD)"
// @Param			sort		query		string	false	"newest|oldest|qty_desc|amount_desc"
// @Param			page		query		int		false	"Trang (mặc định 1)"
// @Param			page_size	query		int		false	"Số item/trang (mặc định 20, tối đa 100)"
// @Success		200			{object}	response.Body{data=[]domain.GoodsReceipt,meta=response.Pagination}
// @Failure		401			{object}	response.Body
// @Failure		500			{object}	response.Body
// @Security		BearerAuth
// @Router			/admin/receipts [get]
func (h *GoodsReceiptHandler) List(c *gin.Context) {
	page, _ := strconv.Atoi(c.DefaultQuery("page", "1"))
	pageSize, _ := strconv.Atoi(c.DefaultQuery("page_size", "20"))
	if page < 1 {
		page = 1
	}
	if pageSize < 1 || pageSize > 100 {
		pageSize = 20
	}

	filter := domain.GoodsReceiptFilter{
		Keyword:  c.Query("keyword"),
		FromDate: c.Query("from_date"),
		ToDate:   c.Query("to_date"),
		Sort:     c.Query("sort"),
		Page:     page,
		PageSize: pageSize,
	}

	list, total, err := h.svc.List(c.Request.Context(), filter)
	if err != nil {
		response.Error(c, http.StatusInternalServerError, "Lỗi truy vấn danh sách đợt nhập hàng")
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

// @Summary		Thống kê nhập hàng
// @Description	Tổng số đợt nhập / số lượng / giá trị đã nhập (theo giá nhập trên phiếu), phần nhập trong NGÀY HÔM NAY, kèm việc còn phải làm: số phiếu đã đặt mà hàng chưa về đủ và số lượng còn thiếu. Số liệu tính trên toàn bộ dữ liệu, không phụ thuộc bộ lọc đang áp.
// @Tags			Admin - Goods Receipts
// @Accept			json
// @Produce		json
// @Success		200	{object}	response.Body{data=domain.GoodsReceiptStats}
// @Failure		401	{object}	response.Body
// @Failure		500	{object}	response.Body
// @Security		BearerAuth
// @Router			/admin/receipts/stats [get]
func (h *GoodsReceiptHandler) Stats(c *gin.Context) {
	stats, err := h.svc.Stats(c.Request.Context())
	if err != nil {
		response.Error(c, http.StatusInternalServerError, "Lỗi truy vấn thống kê nhập hàng")
		return
	}
	response.OK(c, stats)
}

// @Summary		Chi tiết đợt nhập hàng
// @Description	Đọc một đợt nhập theo mã đợt (`<mã phiếu đặt>-N<đợt>`), kèm từng dòng hàng đã nhập: số lượng, giá nhập, thành tiền và tồn kho TRƯỚC/SAU khi nhập để đối chiếu lại với sổ kho.
// @Tags			Admin - Goods Receipts
// @Accept			json
// @Produce		json
// @Param			code	path		string	true	"Mã đợt nhập, VD: PO202607300001-N1"
// @Success		200		{object}	response.Body{data=domain.GoodsReceipt}
// @Failure		401		{object}	response.Body
// @Failure		404		{object}	response.Body
// @Security		BearerAuth
// @Router			/admin/receipts/{code} [get]
func (h *GoodsReceiptHandler) Get(c *gin.Context) {
	receipt, err := h.svc.Find(c.Request.Context(), c.Param("code"))
	if err != nil {
		if errors.Is(err, domain.ErrNotFound) {
			response.Error(c, http.StatusNotFound, "Không tìm thấy đợt nhập hàng")
			return
		}
		response.Error(c, http.StatusInternalServerError, "Lỗi truy vấn đợt nhập hàng")
		return
	}
	response.OK(c, receipt)
}
