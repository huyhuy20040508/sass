package handler

import (
	"errors"
	"fmt"
	"math"
	"net/http"
	"strconv"

	"github.com/gin-gonic/gin"

	"sass-api/internal/domain"
	"sass-api/internal/dto"
	"sass-api/internal/middleware"
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
// @Param			opened_by	query		int		false	"Chỉ ca do người này mở"
// @Param			lech		query		int		false	"1 = chỉ ca đã đóng có chênh lệch két"
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

	openedBy, _ := strconv.ParseUint(c.Query("opened_by"), 10, 64)
	f := domain.CaFilter{
		Status:   c.Query("status"),
		FromDate: c.Query("from_date"),
		ToDate:   c.Query("to_date"),
		OpenedBy: uint(openedBy),
		ChiLech:  c.Query("lech") == "1",
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

// @Summary		Tiền mặt ngoài ca hôm nay
// @Description	Các dòng sổ quỹ hôm nay của chi nhánh đang làm KHÔNG gắn ca nào (bán lúc chưa ai mở ca). Đã vào/ra két thật nhưng không nằm trong con số đối chiếu của ca nào — người mở ca kế tiếp cần biết để đếm két.
// @Tags			Admin - Ca làm việc
// @Produce		json
// @Success		200	{object}	response.Body{data=[]domain.SoQuy}
// @Security		BearerAuth
// @Router			/admin/ca-lam-viec/ngoai-ca [get]
func (h *CaLamViecHandler) NgoaiCa(c *gin.Context) {
	ds, err := h.svc.NgoaiCaHomNay(c.Request.Context())
	if err != nil {
		respondCaError(c, err, "Lỗi đọc sổ quỹ ngoài ca")
		return
	}
	// meta cho nút "Gắn vào ca đang mở": người xem có quyền không, và khoản phát
	// sinh trước gan_tu (giờ đóng ca trước) thì không gắn được.
	moc, err := h.svc.MocGanNgoaiCa(c.Request.Context())
	if err != nil {
		respondCaError(c, err, "Lỗi đọc sổ quỹ ngoài ca")
		return
	}
	c.JSON(http.StatusOK, response.Body{Success: true, Data: ds, Meta: gin.H{
		"co_the_gan": middleware.CoQuyen(c, "so-quy.sua"), "gan_tu": moc,
	}})
}

type ganNgoaiCaRequest struct {
	IDs []uint `json:"ids" binding:"required,min=1,max=200"`
}

// GanNgoaiCa godoc
//
//	@Summary	Gắn khoản tiền mặt ngoài ca vào ca đang mở (ghi ai gắn, lúc nào)
//	@Tags		Admin - Ca làm việc
//	@Router		/admin/ca-lam-viec/ngoai-ca/gan [post]
func (h *CaLamViecHandler) GanNgoaiCa(c *gin.Context) {
	var req ganNgoaiCaRequest
	if !bindJSON(c, &req) {
		return
	}
	ca, err := h.svc.GanNgoaiCa(c.Request.Context(), req.IDs, currentUserID(c))
	if err != nil {
		respondCaError(c, err, "Không gắn được khoản ngoài ca")
		return
	}
	response.OKMessage(c, fmt.Sprintf("Đã gắn %d khoản vào ca #%d.", len(req.IDs), ca.ID), ca)
}

// @Summary		Người đã trực ca
// @Description	Những người đã từng mở ca ở chi nhánh đang làm — cho ô lọc "người trực" của lịch sử ca.
// @Tags			Admin - Ca làm việc
// @Produce		json
// @Success		200	{object}	response.Body{data=[]domain.NguoiTrucCa}
// @Security		BearerAuth
// @Router			/admin/ca-lam-viec/nguoi-truc [get]
func (h *CaLamViecHandler) NguoiTruc(c *gin.Context) {
	ds, err := h.svc.NguoiTruc(c.Request.Context(), chiNhanhLoc(c))
	if err != nil {
		respondCaError(c, err, "Lỗi đọc danh sách người trực")
		return
	}
	response.OK(c, ds)
}

// NhatKyNhanVien godoc
//
//	@Summary	Nhật ký của một nhân sự (mở / đóng ca) kèm số ca trực, số lần lệch
//	@Tags		Admin - Nhân sự
//	@Router		/admin/nhan-su/{id}/nhat-ky [get]
func (h *CaLamViecHandler) NhatKyNhanVien(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}
	page, _ := strconv.Atoi(c.DefaultQuery("page", "1"))
	pageSize, _ := strconv.Atoi(c.DefaultQuery("page_size", "20"))
	out, err := h.svc.NhatKyNhanVien(c.Request.Context(), id, page, pageSize)
	if err != nil {
		respondCaError(c, err, "Lỗi đọc nhật ký nhân viên")
		return
	}
	response.OK(c, out)
}

// NhanVienTruc godoc
//
//	@Summary	Nhân sự chọn được làm người trực két ở chi nhánh đang làm
//	@Tags		Admin - Ca làm việc
//	@Router		/admin/ca-lam-viec/nhan-vien-truc [get]
func (h *CaLamViecHandler) NhanVienTruc(c *gin.Context) {
	ds, err := h.svc.NhanVienTruc(c.Request.Context())
	if err != nil {
		respondCaError(c, err, "Lỗi đọc danh sách nhân sự")
		return
	}
	response.OK(c, ds)
}

func respondCaError(c *gin.Context, err error, fallback string) {
	if loiChiNhanh(c, err) {
		return
	}

	var am *domain.LoiKetAm
	var moCa *domain.LoiMoCa
	var dongCa *domain.LoiDongCa
	var gan *domain.LoiGanNgoaiCa
	switch {
	case errors.As(err, &gan):
		response.Error(c, http.StatusUnprocessableEntity, gan.Cau)
	case errors.As(err, &dongCa):
		cau := "Thông tin bàn giao két chưa hợp lệ."
		for _, k := range []string{"counted_count", "delivered_cash", "kept_cash"} {
			if v, co := dongCa.O[k]; co {
				cau = v
				break
			}
		}
		c.AbortWithStatusJSON(http.StatusUnprocessableEntity, response.Body{Success: false, Message: cau, Errors: dongCa.O})
	case errors.As(err, &moCa):
		// Câu chính là lỗi của ô đầu tiên: màn quầy chỉ đọc message.
		cau := "Thông tin mở ca chưa hợp lệ."
		for _, k := range []string{"employee_id", "opened_at", "opening_count"} {
			if v, co := moCa.O[k]; co {
				cau = v
				break
			}
		}
		c.AbortWithStatusJSON(http.StatusUnprocessableEntity, response.Body{Success: false, Message: cau, Errors: moCa.O})
	case errors.Is(err, domain.ErrChuaMoCa):
		response.ErrorMa(c, http.StatusConflict, response.MaChuaMoCa,
			"Chi nhánh chưa mở ca — mở ca rồi mới ghi thu / chi vào két.")
	case errors.As(err, &am):
		response.ErrorMa(c, http.StatusUnprocessableEntity, response.MaKetAm,
			"Két theo sổ chỉ còn "+tienVN(am.Con)+" — khoản chi lớn hơn tiền đang có. Kiểm lại số tiền, hoặc xác nhận để vẫn ghi.")
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

// tienVN in số tiền kiểu "1.234.000 đ" (số âm giữ dấu trừ) cho câu báo của ca.
func tienVN(v float64) string {
	am := v < 0
	s := strconv.FormatInt(int64(math.Abs(math.Round(v))), 10)
	for i := len(s) - 3; i > 0; i -= 3 {
		s = s[:i] + "." + s[i:]
	}
	if am {
		s = "−" + s
	}
	return s + " đ"
}
