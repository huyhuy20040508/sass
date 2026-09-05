package handler

import (
	"net/http"
	"strconv"

	"github.com/gin-gonic/gin"

	"sass-api/internal/service"
	"sass-api/pkg/response"
)

// ReportHandler — bốn báo cáo của trang quản trị.
//
// Cả bốn nhận cùng một bộ tham số khoảng thời gian nên gom về một chỗ đọc
// (query): thêm báo cáo thứ năm thì không phải chép lại cách hiểu tham số, và
// người dùng đổi qua lại giữa các báo cáo cũng giữ nguyên được khoảng đang xem.
type ReportHandler struct {
	svc service.ReportService
}

func NewReportHandler(svc service.ReportService) *ReportHandler {
	return &ReportHandler{svc: svc}
}

// query đọc tham số chung. Không kiểm tra gì ở đây — mọi chuẩn hoá (ngày sai
// định dạng, kỳ đảo đầu đuôi, kỳ quá dài, số dòng quá lớn) nằm ở tầng service để
// bốn báo cáo không mỗi cái hiểu một kiểu.
func reportQuery(c *gin.Context) service.ReportQuery {
	limit, _ := strconv.Atoi(c.Query("limit"))
	return service.ReportQuery{
		From:    c.Query("from"),
		To:      c.Query("to"),
		GroupBy: c.Query("group_by"),
		Sort:    c.Query("sort"),
		Limit:   limit,
		// Mặc định là chi nhánh ĐANG LÀM VIỆC. Chủ tiệm muốn xem cả cửa hàng thì
		// gửi shop_id=0 — cùng quy ước với mọi màn danh sách khác.
		ShopID: chiNhanhLoc(c),
	}
}

// @Summary		Báo cáo doanh thu
// @Description	Tiền vào theo thời gian trong khoảng `from`–`to`: chuỗi theo mốc (ngày/tuần/tháng), tổng của kỳ và tổng của KỲ TRƯỚC cùng độ dài để tính mức tăng/giảm, kèm cơ cấu theo hình thức thanh toán và tình trạng thu tiền.
// @Description	Quy ước tính tiền giống trang Tổng quan: KHÔNG tính đơn huỷ và đơn hoàn hàng. Mốc thời gian của đơn là lúc đặt (`created_at`).
// @Description	`buckets` luôn ĐỦ mốc của cả kỳ — mốc không phát sinh đơn vẫn có mặt với giá trị 0, nếu thiếu thì biểu đồ nối thẳng qua và vẽ sai đường xu hướng.
// @Description	`profit` là lợi nhuận GỘP = `subtotal` − `discount` − `cost`: phí vận chuyển là tiền thu hộ nhà xe nên không tính là lãi. Sản phẩm chưa khai giá vốn được tính vốn bằng 0, khi đó lợi nhuận bằng đúng doanh thu.
// @Tags			Admin - Reports
// @Accept			json
// @Produce		json
// @Param			from		query		string	false	"Ngày đầu kỳ (YYYY-MM-DD), mặc định 30 ngày trước ngày cuối kỳ"
// @Param			to			query		string	false	"Ngày cuối kỳ (YYYY-MM-DD), mặc định hôm nay"
// @Param			group_by	query		string	false	"Cách chia trục thời gian; bỏ trống thì tự chọn theo độ dài kỳ"	Enums(day, week, month)
// @Success		200			{object}	response.Body{data=domain.RevenueReport}
// @Failure		401			{object}	response.Body
// @Failure		500			{object}	response.Body
// @Security		BearerAuth
// @Router			/admin/reports/revenue [get]
func (h *ReportHandler) Revenue(c *gin.Context) {
	res, err := h.svc.Revenue(c.Request.Context(), reportQuery(c))
	if err != nil {
		response.Error(c, http.StatusInternalServerError, "Lỗi thống kê doanh thu")
		return
	}
	response.OK(c, res)
}

// @Summary		Báo cáo đơn hàng
// @Description	Đơn ra vào thế nào trong khoảng `from`–`to`: tổng đơn (KỂ CẢ huỷ/hoàn), phần còn hiệu lực, tỷ lệ huỷ, tiền chưa thu, cùng các lát cắt theo trạng thái / khung giờ / thứ trong tuần / tỉnh thành / kênh bán / hình thức vận chuyển.
// @Description	Khác báo cáo doanh thu ở mẫu số: `total` đếm MỌI đơn phát sinh trong kỳ, còn mọi con số tiền vẫn chỉ tính trên đơn còn hiệu lực. Tỷ lệ huỷ chỉ có nghĩa khi mẫu số là tổng đơn đã đặt.
// @Description	`by_status` luôn đủ 8 trạng thái, `by_hour` đủ 24 mốc (key = "0".."23"), `by_weekday` đủ 7 mốc (key = "1".."7", 1 = Thứ Hai) — kể cả khi bằng 0, để bảng không nhảy dòng giữa hai lần xem.
// @Tags			Admin - Reports
// @Accept			json
// @Produce		json
// @Param			from		query		string	false	"Ngày đầu kỳ (YYYY-MM-DD)"
// @Param			to			query		string	false	"Ngày cuối kỳ (YYYY-MM-DD)"
// @Param			group_by	query		string	false	"Cách chia trục thời gian"	Enums(day, week, month)
// @Success		200			{object}	response.Body{data=domain.OrderReport}
// @Failure		401			{object}	response.Body
// @Failure		500			{object}	response.Body
// @Security		BearerAuth
// @Router			/admin/reports/orders [get]
func (h *ReportHandler) Orders(c *gin.Context) {
	res, err := h.svc.Orders(c.Request.Context(), reportQuery(c))
	if err != nil {
		response.Error(c, http.StatusInternalServerError, "Lỗi thống kê đơn hàng")
		return
	}
	response.OK(c, res)
}

// @Summary		Báo cáo sản phẩm
// @Description	Mặt hàng nào kéo doanh thu trong khoảng `from`–`to`: bảng xếp hạng theo doanh thu / số lượng / lợi nhuận, cơ cấu theo danh mục, thương hiệu và size, kèm số sản phẩm đang bán mà cả kỳ không bán được món nào (`unsold_products`).
// @Description	`revenue` ở báo cáo này là tiền HÀNG (tổng `total_price` của các dòng đơn) nên KHÔNG bằng doanh thu ở báo cáo doanh thu: nó không gồm phí vận chuyển và chưa trừ giảm giá cấp đơn. Đây là chủ ý — xếp hạng mặt hàng thì không chia được phần giảm giá của cả đơn về từng dòng.
// @Description	Mỗi dòng kèm `stock` là tồn kho HIỆN TẠI (không phải tồn tại thời điểm bán) để thấy ngay mặt hàng bán chạy mà sắp hết hàng.
// @Tags			Admin - Reports
// @Accept			json
// @Produce		json
// @Param			from	query		string	false	"Ngày đầu kỳ (YYYY-MM-DD)"
// @Param			to		query		string	false	"Ngày cuối kỳ (YYYY-MM-DD)"
// @Param			sort	query		string	false	"Xếp hạng theo (mặc định revenue)"	Enums(revenue, units, profit)
// @Param			limit	query		int		false	"Số dòng của bảng xếp hạng (mặc định 20, tối đa 100)"
// @Success		200		{object}	response.Body{data=domain.ProductReport}
// @Failure		401		{object}	response.Body
// @Failure		500		{object}	response.Body
// @Security		BearerAuth
// @Router			/admin/reports/products [get]
func (h *ReportHandler) Products(c *gin.Context) {
	res, err := h.svc.Products(c.Request.Context(), reportQuery(c))
	if err != nil {
		response.Error(c, http.StatusInternalServerError, "Lỗi thống kê sản phẩm")
		return
	}
	response.OK(c, res)
}

// @Summary		Báo cáo khách hàng
// @Description	Ai đang mua trong khoảng `from`–`to`: số khách mua, khách MỚI (lần mua đầu tiên rơi vào kỳ) và khách quay lại, số tài khoản đăng ký mới, cơ cấu hội viên / vãng lai, bảng xếp hạng chi tiêu và phân bố theo tỉnh thành.
// @Description	`buyers` chỉ đếm khách CÓ TÀI KHOẢN: đơn khách vãng lai không gắn `user_id` nên không có cách nào biết hai đơn có phải cùng một người. Phần vãng lai vẫn được đếm riêng ở `guest_orders` / `guest_revenue` để không biến mất khỏi báo cáo.
// @Description	`by_province` thì ngược lại — gộp theo địa chỉ nhận hàng nên tính CẢ đơn vãng lai, vì muốn biết nên mở rộng ở đâu thì phải nhìn hết người mua.
// @Tags			Admin - Reports
// @Accept			json
// @Produce		json
// @Param			from		query		string	false	"Ngày đầu kỳ (YYYY-MM-DD)"
// @Param			to			query		string	false	"Ngày cuối kỳ (YYYY-MM-DD)"
// @Param			group_by	query		string	false	"Cách chia trục thời gian"	Enums(day, week, month)
// @Param			limit		query		int		false	"Số dòng của bảng xếp hạng chi tiêu (mặc định 20, tối đa 100)"
// @Success		200			{object}	response.Body{data=domain.CustomerReport}
// @Failure		401			{object}	response.Body
// @Failure		500			{object}	response.Body
// @Security		BearerAuth
// @Router			/admin/reports/customers [get]
func (h *ReportHandler) Customers(c *gin.Context) {
	res, err := h.svc.Customers(c.Request.Context(), reportQuery(c))
	if err != nil {
		response.Error(c, http.StatusInternalServerError, "Lỗi thống kê khách hàng")
		return
	}
	response.OK(c, res)
}
