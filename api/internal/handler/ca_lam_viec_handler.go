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

type CaLamViecHandler struct {
	svc service.CaLamViecService
}

func NewCaLamViecHandler(svc service.CaLamViecService) *CaLamViecHandler {
	return &CaLamViecHandler{svc: svc}
}

// @Summary		Ca đang mở
// @Description	Ca trực két đang mở của CHI NHÁNH ĐANG LÀM VIỆC, kèm tổng thu / tổng chi tiền mặt và số lượt bán thu tiền mặt trong ca.
// @Description	Trả `data: null` khi chưa ai mở ca — đây là trạng thái BÌNH THƯỜNG, không phải lỗi: bán hàng không bao giờ bị chặn vì chưa mở ca, tiền vẫn vào sổ quỹ với `shift_id` rỗng và màn hình đóng ca sẽ chỉ chúng ra.
// @Tags			Admin - Ca làm việc
// @Produce		json
// @Success		200	{object}	response.Body{data=domain.CaLamViec}
// @Security		BearerAuth
// @Router			/admin/ca-lam-viec/hien-tai [get]
func (h *CaLamViecHandler) HienTai(c *gin.Context) {
	ca, err := h.svc.HienTai(c.Request.Context())
	if err != nil {
		respondCaError(c, err, "Lỗi đọc ca làm việc")
		return
	}
	response.OK(c, ca)
}

// @Summary		Mở ca
// @Description	Mở một ca trực két cho chi nhánh đang làm việc. `opening_cash` là tiền mặt người trực ĐẾM ĐƯỢC trong két lúc mở — không suy ra từ ca trước, vì giữa hai ca có thể đã có người rút tiền về.
// @Description	Mỗi chi nhánh chỉ được có MỘT ca mở; mở khi đang còn ca trả 409. Ràng buộc này nằm ở database nên hai người bấm cùng lúc cũng chỉ một người mở được.
// @Tags			Admin - Ca làm việc
// @Accept			json
// @Produce		json
// @Param			body	body		dto.MoCaRequest	true	"Tiền đầu ca"
// @Success		201		{object}	response.Body{data=domain.CaLamViec}
// @Failure		409		{object}	response.Body	"Chi nhánh đang có ca mở"
// @Security		BearerAuth
// @Router			/admin/ca-lam-viec/mo [post]
func (h *CaLamViecHandler) MoCa(c *gin.Context) {
	var req dto.MoCaRequest
	if !bindJSON(c, &req) {
		return
	}

	ca, err := h.svc.MoCa(c.Request.Context(), req, currentUserID(c))
	if err != nil {
		respondCaError(c, err, "Lỗi mở ca")
		return
	}
	response.Created(c, ca)
}

// @Summary		Đóng ca
// @Description	Chốt ca đang mở: cộng lại sổ quỹ DƯỚI KHOÁ rồi ghi ba con số — tiền đếm được, tiền theo sổ (đầu ca + thu − chi) và chênh lệch. Chênh lệch âm nghĩa là thiếu két.
// @Description	Trả kèm `ngoai_ca`: những khoản tiền mặt phát sinh trong giờ của ca nhưng lúc đó chưa ai mở ca. Chúng đã vào/ra két thật mà không nằm trong con số đối chiếu, nên phải được nhìn thấy chứ không im lặng bỏ qua.
// @Description	Ba con số được LƯU LẠI chứ không tính lại khi đọc: sổ có thể được ghi thêm sau đó, còn con số hai bên đã ký nhận hôm ấy thì phải giữ nguyên.
// @Tags			Admin - Ca làm việc
// @Accept			json
// @Produce		json
// @Param			body	body		dto.DongCaRequest	true	"Tiền đếm được"
// @Success		200		{object}	response.Body{data=dto.DongCaResponse}
// @Failure		409		{object}	response.Body	"Chưa mở ca, hoặc ca đã đóng"
// @Security		BearerAuth
// @Router			/admin/ca-lam-viec/dong [post]
func (h *CaLamViecHandler) DongCa(c *gin.Context) {
	var req dto.DongCaRequest
	if !bindJSON(c, &req) {
		return
	}

	res, err := h.svc.DongCa(c.Request.Context(), req, currentUserID(c))
	if err != nil {
		respondCaError(c, err, "Lỗi đóng ca")
		return
	}
	response.OKMessage(c, "Đã đóng ca", res)
}

// @Summary		Danh sách ca làm việc
// @Description	Lịch sử các ca, mới nhất trước. Lọc theo chi nhánh, trạng thái và khoảng ngày MỞ ca.
// @Tags			Admin - Ca làm việc
// @Produce		json
// @Param			shop_id		query		int		false	"Lọc theo chi nhánh (bỏ trống = mọi chi nhánh)"
// @Param			status		query		string	false	"dang_mo|da_dong"
// @Param			from_date	query		string	false	"Từ ngày (YYYY-MM-DD)"
// @Param			to_date		query		string	false	"Đến ngày (YYYY-MM-DD)"
// @Param			page		query		int		false	"Trang (mặc định 1)"
// @Param			page_size	query		int		false	"Số ca/trang (mặc định 20, tối đa 100)"
// @Success		200			{object}	response.Body{meta=response.Pagination}
// @Security		BearerAuth
// @Router			/admin/ca-lam-viec [get]
func (h *CaLamViecHandler) List(c *gin.Context) {
	page, _ := strconv.Atoi(c.DefaultQuery("page", "1"))
	pageSize, _ := strconv.Atoi(c.DefaultQuery("page_size", "20"))
	if page < 1 {
		page = 1
	}
	if pageSize < 1 || pageSize > 100 {
		pageSize = 20
	}

	f := domain.CaFilter{
		Status:   c.Query("status"),
		FromDate: c.Query("from_date"),
		ToDate:   c.Query("to_date"),
		Page:     page,
		PageSize: pageSize,
	}
	// Mặc định là CHI NHÁNH ĐANG LÀM VIỆC, không phải "tất cả". Trước đây chỉ cắt
	// khi client tự gửi shop_id, nên không gửi là thấy ca và tiền két của mọi quầy.
	// chiNhanhLoc vẫn cho chủ tiệm xem cả cửa hàng bằng shop_id=0.
	f.ShopID = chiNhanhLoc(c)

	list, total, err := h.svc.List(c.Request.Context(), f)
	if err != nil {
		respondCaError(c, err, "Lỗi truy vấn ca làm việc")
		return
	}

	totalPages := int((total + int64(pageSize) - 1) / int64(pageSize))
	response.Paginated(c, list, response.Pagination{
		Page: page, PageSize: pageSize, Total: total, TotalPages: totalPages,
	})
}

// @Summary		Chi tiết một ca
// @Description	Ca kèm TOÀN BỘ dòng sổ quỹ của nó, cũ trước. Đây là màn hình dùng để truy lại một khoản chênh: đọc từng lần tiền vào/ra theo đúng thứ tự đã xảy ra.
// @Tags			Admin - Ca làm việc
// @Produce		json
// @Param			id	path		int	true	"ID ca"
// @Success		200	{object}	response.Body{data=dto.CaChiTietResponse}
// @Failure		404	{object}	response.Body
// @Security		BearerAuth
// @Router			/admin/ca-lam-viec/{id} [get]
func (h *CaLamViecHandler) Get(c *gin.Context) {
	id, err := strconv.ParseUint(c.Param("id"), 10, 64)
	if err != nil || id == 0 {
		response.Error(c, http.StatusBadRequest, "ID ca không hợp lệ")
		return
	}

	res, err := h.svc.ChiTiet(c.Request.Context(), uint(id))
	if err != nil {
		respondCaError(c, err, "Lỗi đọc ca làm việc")
		return
	}
	response.OK(c, res)
}

// @Summary		Ghi tay một khoản thu/chi tiền mặt
// @Description	Ghi vào sổ quỹ một khoản KHÔNG đi qua đơn hàng: mua nước, trả tiền ship, chủ rút bớt tiền mặt về. Khoản này tự gắn vào ca đang mở của chi nhánh; chưa mở ca thì vẫn ghi nhưng không thuộc ca nào.
// @Description	`amount` luôn DƯƠNG — chiều tiền nằm ở `direction` (`in` = vào két, `out` = ra khỏi két). `reason` bắt buộc: một khoản ra khỏi két không có lý do thì đúng bằng mất tiền, chỉ khác là có ghi lại.
// @Description	CHỈ TIỀN MẶT. Chuyển khoản không đi qua két nên không thuộc sổ này; ghi vào đây là con số đối chiếu cuối ca không còn khớp tiền đếm được.
// @Tags			Admin - Ca làm việc
// @Accept			json
// @Produce		json
// @Param			body	body		dto.GhiSoQuyRequest	true	"Khoản thu/chi"
// @Success		201		{object}	response.Body{data=domain.SoQuy}
// @Security		BearerAuth
// @Router			/admin/so-quy [post]
func (h *CaLamViecHandler) GhiSoQuy(c *gin.Context) {
	var req dto.GhiSoQuyRequest
	if !bindJSON(c, &req) {
		return
	}

	e, err := h.svc.GhiTay(c.Request.Context(), req, currentUserID(c))
	if err != nil {
		respondCaError(c, err, "Lỗi ghi sổ quỹ")
		return
	}
	response.Created(c, e)
}

func respondCaError(c *gin.Context, err error, fallback string) {
	if loiChiNhanh(c, err) {
		return
	}

	switch {
	case errors.Is(err, domain.ErrCaDangMo):
		response.Error(c, http.StatusConflict,
			"Chi nhánh này đang có ca mở. Đóng ca đó trước khi mở ca mới.")
	case errors.Is(err, domain.ErrKhongCoCa):
		response.Error(c, http.StatusConflict,
			"Chi nhánh này chưa mở ca nào để đóng.")
	case errors.Is(err, domain.ErrCaDaDong):
		response.Error(c, http.StatusConflict, "Ca này đã đóng rồi.")
	case errors.Is(err, domain.ErrNotFound):
		response.Error(c, http.StatusNotFound, "Không tìm thấy ca làm việc")
	default:
		response.Error(c, http.StatusInternalServerError, fallback)
	}
}
