package handler

import (
	"net/http"
	"strconv"
	"strings"

	"github.com/gin-gonic/gin"

	"sass-api/internal/domain"
	"sass-api/internal/dto"
	"sass-api/internal/service"
	"sass-api/pkg/response"
)

// EtaxHandler — kết nối cổng HOÁ ĐƠN ĐIỆN TỬ của từng chi nhánh.
//
// Cả nhóm nằm dưới /admin/chi-nhanh/:id/etax và ở quyền `manage`: mật khẩu cổng
// HĐĐT phát hành được hoá đơn đứng tên cửa hàng, không phải thứ nhân viên quầy
// đụng tới.
type EtaxHandler struct {
	svc service.EtaxService
}

func NewEtaxHandler(svc service.EtaxService) *EtaxHandler {
	return &EtaxHandler{svc: svc}
}

// Get godoc
//
//	@Summary		Kết nối HĐĐT của một chi nhánh
//	@Description	Trả về tài khoản đang nối kèm danh sách ký hiệu hoá đơn đã đăng ký.
//	@Description	404 = chi nhánh này chưa kết nối.
//	@Description	Mật khẩu và token KHÔNG bao giờ trả ra.
//	@Tags			Admin - Hoá đơn điện tử
//	@Produce		json
//	@Security		BearerAuth
//	@Param			id	path		int	true	"ID chi nhánh"
//	@Success		200	{object}	response.Body{data=domain.EtaxConnection}
//	@Failure		401	{object}	response.Body
//	@Failure		403	{object}	response.Body
//	@Failure		404	{object}	response.Body
//	@Router			/admin/chi-nhanh/{id}/etax [get]
func (h *EtaxHandler) Get(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}
	cn, err := h.svc.Xem(c.Request.Context(), id)
	if err != nil {
		handleServiceError(c, err)

		return
	}
	response.OK(c, cn)
}

// Connect godoc
//
//	@Summary		Kết nối cổng HĐĐT
//	@Description	Đăng nhập THẬT vào cổng trước khi lưu, rồi kéo luôn danh sách ký hiệu về.
//	@Description	Gọi lại trên chi nhánh đã nối = khai lại tài khoản.
//	@Tags			Admin - Hoá đơn điện tử
//	@Accept			json
//	@Produce		json
//	@Security		BearerAuth
//	@Param			id		path		int						true	"ID chi nhánh"
//	@Param			body	body		dto.EtaxKetNoiRequest	true	"Tài khoản cổng HĐĐT"
//	@Success		200		{object}	response.Body{data=domain.EtaxConnection}
//	@Failure		400		{object}	response.Body
//	@Failure		401		{object}	response.Body
//	@Failure		403		{object}	response.Body
//	@Failure		404		{object}	response.Body
//	@Failure		409		{object}	response.Body
//	@Failure		422		{object}	response.Body
//	@Router			/admin/chi-nhanh/{id}/etax [post]
func (h *EtaxHandler) Connect(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}
	var req dto.EtaxKetNoiRequest
	if !bindJSON(c, &req) {
		return
	}
	cn, err := h.svc.KetNoi(c.Request.Context(), id, &req)
	if err != nil {
		handleServiceError(c, err)

		return
	}
	response.OKMessage(c, "Đã kết nối "+tenNhaCungCap(cn.Provider), cn)
}

// Update godoc
//
//	@Summary		Cài đặt phát hành hoá đơn
//	@Description	Chọn ký hiệu dùng để phát hành và hai công tắc tự phát hành / tự in.
//	@Tags			Admin - Hoá đơn điện tử
//	@Accept			json
//	@Produce		json
//	@Security		BearerAuth
//	@Param			id		path		int						true	"ID chi nhánh"
//	@Param			body	body		dto.EtaxCaiDatRequest	true	"Cài đặt"
//	@Success		200		{object}	response.Body{data=domain.EtaxConnection}
//	@Failure		401		{object}	response.Body
//	@Failure		403		{object}	response.Body
//	@Failure		404		{object}	response.Body
//	@Failure		422		{object}	response.Body
//	@Router			/admin/chi-nhanh/{id}/etax [put]
func (h *EtaxHandler) Update(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}
	var req dto.EtaxCaiDatRequest
	if !bindJSON(c, &req) {
		return
	}
	cn, err := h.svc.CapNhat(c.Request.Context(), id, &req)
	if err != nil {
		handleServiceError(c, err)

		return
	}
	response.OKMessage(c, "Đã lưu cài đặt hoá đơn điện tử", cn)
}

// Sync godoc
//
//	@Summary		Đồng bộ mẫu hoá đơn
//	@Description	Kéo lại danh sách ký hiệu từ nhà cung cấp; token hết hạn thì tự đăng nhập lại.
//	@Tags			Admin - Hoá đơn điện tử
//	@Produce		json
//	@Security		BearerAuth
//	@Param			id	path		int	true	"ID chi nhánh"
//	@Success		200	{object}	response.Body{data=domain.EtaxConnection}
//	@Failure		401	{object}	response.Body
//	@Failure		403	{object}	response.Body
//	@Failure		404	{object}	response.Body
//	@Router			/admin/chi-nhanh/{id}/etax/sync [post]
func (h *EtaxHandler) Sync(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}
	cn, err := h.svc.DongBoMau(c.Request.Context(), id)
	if err != nil {
		handleServiceError(c, err)

		return
	}
	response.OKMessage(c, "Đã đồng bộ mẫu hoá đơn", cn)
}

// Delete godoc
//
//	@Summary		Ngắt kết nối HĐĐT
//	@Description	Xoá hẳn tài khoản khỏi sổ; mẫu hoá đơn đã kéo về đi theo.
//	@Tags			Admin - Hoá đơn điện tử
//	@Produce		json
//	@Security		BearerAuth
//	@Param			id	path		int	true	"ID chi nhánh"
//	@Success		200	{object}	response.Body
//	@Failure		401	{object}	response.Body
//	@Failure		403	{object}	response.Body
//	@Failure		404	{object}	response.Body
//	@Router			/admin/chi-nhanh/{id}/etax [delete]
func (h *EtaxHandler) Delete(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}
	if err := h.svc.NgatKetNoi(c.Request.Context(), id); err != nil {
		handleServiceError(c, err)

		return
	}
	response.OKMessage(c, "Đã ngắt kết nối hoá đơn điện tử", nil)
}

func tenNhaCungCap(ma string) string {
	if ten, co := domain.NhaCungCapETax[ma]; co {
		return ten
	}

	return "cổng hoá đơn điện tử"
}

// HoaDon godoc
//
//	@Summary		Hoá đơn điện tử của một đơn hàng
//	@Description	404 = đơn này chưa phát hành hoá đơn.
//	@Tags			Admin - Hoá đơn điện tử
//	@Produce		json
//	@Security		BearerAuth
//	@Param			id	path		int	true	"ID đơn hàng"
//	@Success		200	{object}	response.Body{data=domain.EtaxInvoice}
//	@Failure		401	{object}	response.Body
//	@Failure		403	{object}	response.Body
//	@Failure		404	{object}	response.Body
//	@Router			/admin/orders/{id}/etax [get]
func (h *EtaxHandler) HoaDon(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}
	hd, err := h.svc.XemHoaDon(c.Request.Context(), id)
	if err != nil {
		handleServiceError(c, err)

		return
	}
	response.OK(c, hd)
}

// PhatHanh godoc
//
//	@Summary		Phát hành hoá đơn cho một đơn hàng
//	@Description	Chi nhánh của đơn phải đã kết nối cổng HĐĐT và đã chọn ký hiệu.
//	@Description	Công tắc "Tự phát hành" quyết định lưu nháp hay ký luôn.
//	@Description	Mỗi đơn phát hành ĐÚNG MỘT hoá đơn; bấm lại chỉ được khi lượt trước hỏng.
//	@Tags			Admin - Hoá đơn điện tử
//	@Produce		json
//	@Security		BearerAuth
//	@Param			id	path		int	true	"ID đơn hàng"
//	@Success		200	{object}	response.Body{data=domain.EtaxInvoice}
//	@Failure		401	{object}	response.Body
//	@Failure		403	{object}	response.Body
//	@Failure		404	{object}	response.Body
//	@Failure		409	{object}	response.Body
//	@Failure		422	{object}	response.Body
//	@Router			/admin/orders/{id}/etax [post]
func (h *EtaxHandler) PhatHanh(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}
	hd, err := h.svc.PhatHanh(c.Request.Context(), id)
	if err != nil {
		handleServiceError(c, err)

		return
	}
	response.OKMessage(c, service.MoTaHoaDon(hd), hd)
}

// DanhSach godoc
//
//	@Summary		Sổ hoá đơn điện tử
//	@Description	Mọi tờ hoá đơn đã phát hành (kể cả nháp và hỏng) của chi nhánh đang làm việc, mới nhất trước.
//	@Description	`meta.dem` là số hoá đơn theo trạng thái, đếm TRƯỚC khi áp ô `status` — cho hàng nút lọc.
//	@Description	Chỉ đọc sổ trong database; muốn hỏi lại cổng thì gọi `/orders/{id}/etax/sync`.
//	@Tags			Admin - Hoá đơn điện tử
//	@Produce		json
//	@Security		BearerAuth
//	@Param			symbol		query		string	false	"Ký hiệu"
//	@Param			invoice_no	query		string	false	"Số hoá đơn"
//	@Param			code		query		string	false	"Mã đơn hàng hoặc mã cơ quan thuế"
//	@Param			customer	query		string	false	"Tên / SĐT / email người mua"
//	@Param			status		query		string	false	"draft|sent|issued|failed, ngăn bởi dấu phẩy"
//	@Param			created_by	query		string	false	"Id người lập đơn, ngăn bởi dấu phẩy"
//	@Param			from_date	query		string	false	"Từ ngày phát hành (YYYY-MM-DD)"
//	@Param			to_date		query		string	false	"Đến ngày phát hành (YYYY-MM-DD)"
//	@Param			page		query		int		false	"Trang (mặc định 1)"
//	@Param			page_size	query		int		false	"Số dòng/trang (mặc định 20, tối đa 100)"
//	@Success		200			{object}	response.Body{data=[]domain.DongHoaDon,meta=dto.HoaDonMeta}
//	@Failure		401			{object}	response.Body
//	@Failure		403			{object}	response.Body
//	@Router			/admin/etax/hoa-don [get]
func (h *EtaxHandler) DanhSach(c *gin.Context) {
	page, _ := strconv.Atoi(c.DefaultQuery("page", "1"))
	pageSize, _ := strconv.Atoi(c.DefaultQuery("page_size", "20"))
	if page < 1 {
		page = 1
	}
	if pageSize < 1 || pageSize > 100 {
		pageSize = 20
	}

	rows, tong, dem, err := h.svc.DanhSachHoaDon(c.Request.Context(), domain.HoaDonFilter{
		KyHieu:    strings.TrimSpace(c.Query("symbol")),
		SoHoaDon:  strings.TrimSpace(c.Query("invoice_no")),
		MaDon:     strings.TrimSpace(c.Query("code")),
		KhachHang: strings.TrimSpace(c.Query("customer")),
		TrangThai: c.Query("status"),
		CreatedBy: c.Query("created_by"),
		FromDate:  c.Query("from_date"),
		ToDate:    c.Query("to_date"),
		// Cắt theo chi nhánh đang làm việc, cùng luật với sổ đơn hàng: người
		// đứng ở một quầy không đọc sổ hoá đơn của quầy khác.
		ShopID:   chiNhanhLoc(c),
		Page:     page,
		PageSize: pageSize,
	})
	if err != nil {
		handleServiceError(c, err)

		return
	}

	c.JSON(http.StatusOK, response.Body{
		Success: true,
		Data:    rows,
		Meta: dto.HoaDonMeta{
			Pagination: response.Pagination{
				Page:       page,
				PageSize:   pageSize,
				Total:      tong,
				TotalPages: max(int((tong+int64(pageSize)-1)/int64(pageSize)), 1),
			},
			Dem: dem,
		},
	})
}
