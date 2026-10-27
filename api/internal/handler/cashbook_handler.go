package handler

import (
	"errors"
	"net/http"
	"strconv"

	"github.com/gin-gonic/gin"

	"sass-api/internal/domain"
	"sass-api/internal/dto"
	"sass-api/internal/middleware"
	"sass-api/internal/service"
	"sass-api/pkg/response"
)

// ThuChiHandler — Thu chi → Quản lý thu chi.
type ThuChiHandler struct {
	svc service.ThuChiService
}

func NewThuChiHandler(svc service.ThuChiService) *ThuChiHandler {
	return &ThuChiHandler{svc: svc}
}

// nguoiXemThuChi dựng danh tính người gọi cho lớp khoá "phiếu của người khác".
//
// "Quản lý" ở đây KHÔNG phải một vai trò mà là MỘT QUYỀN LẺ được giao
// (`thu-chi.sua-moi-nguoi`). Đọc theo vai thì lớp khoá này chết ngay: nhóm route
// `manage` vốn đã chỉ cho vai admin đi qua, nên mọi người tới được màn hình đều
// là admin và ai cũng sửa được phiếu của ai. Đọc theo quyền thì chủ tiệm quyết
// từng người một, đúng ý bản v2 (bên đó dò `system.branch.update`).
//
// Super admin đi thẳng: đó là tài khoản gốc của cửa hàng, khoá nó là mất luôn
// đường vào để sửa — cùng ngoại lệ mà middleware quyền đang áp.
//
// Hai lớp còn lại — phiếu tự sinh và phiếu thuộc ca đã đóng — KHÔNG ai qua, kể
// cả người có quyền này.
// nguoiXemQuay — ở quầy ai lập người ấy sửa: quyền sửa phiếu người khác của màn
// quản trị không theo vào đây, kể cả với chủ tiệm.
func nguoiXemQuay(c *gin.Context) domain.ThuChiNguoiXem {
	return domain.ThuChiNguoiXem{UserID: currentUserID(c)}
}

func nguoiXemThuChi(c *gin.Context) domain.ThuChiNguoiXem {
	quanLy := c.GetString(middleware.CtxRole) == domain.RoleSuperAdmin

	if !quanLy {
		if v, co := c.Get(middleware.CtxQuyen); co {
			if bo, ok := v.(domain.BoQuyen); ok {
				quanLy = bo.Co(domain.QuyenSuaThuChiMoiNguoi)
			}
		}
	}

	return domain.ThuChiNguoiXem{UserID: currentUserID(c), QuanLy: quanLy}
}

// List godoc
//
//	@Summary		Sổ thu chi
//	@Description	Trả về trang đang xem trong `data`, còn `meta` gộp phân trang VỚI bốn ô quỹ (đầu kỳ, tổng thu, tổng chi, cuối kỳ) tính trên CẢ bộ lọc chứ không riêng trang.
//	@Description	Quỹ đầu kỳ cộng từ mọi phiếu trước `from_date`; bỏ trống `from_date` thì đầu kỳ bằng 0.
//	@Description	Mỗi phiếu kèm `locked` + `locked_reason`: giao diện dựa vào đó để ẩn nút Sửa/Xoá, và máy chủ chặn lại bằng đúng luật ấy khi ghi.
//	@Tags			Admin - Thu chi
//	@Produce		json
//	@Security		BearerAuth
//	@Param			keyword		query		string	false	"Mã phiếu"
//	@Param			type		query		string	false	"0 = phiếu thu, 1 = phiếu chi. Nhiều giá trị ngăn bởi dấu phẩy"
//	@Param			category_id	query		string	false	"Id phân loại, ngăn bởi dấu phẩy"
//	@Param			source		query		string	false	"manual | order | purchase | return. `return` gồm cả trả hàng bán lẫn trả hàng NCC"
//	@Param			created_by	query		string	false	"Id người lập, ngăn bởi dấu phẩy"
//	@Param			from_date	query		string	false	"YYYY-MM-DD"
//	@Param			to_date		query		string	false	"YYYY-MM-DD"
//	@Param			page		query		int		false	"Trang, mặc định 1"
//	@Param			page_size	query		int		false	"Số dòng mỗi trang, mặc định 10"
//	@Success		200			{object}	response.Body{data=[]domain.ThuChi,meta=dto.ThuChiMeta}
//	@Failure		401			{object}	response.Body
//	@Router			/admin/thu-chi [get]
func (h *ThuChiHandler) List(c *gin.Context) {
	page, size := trangThuChi(c)

	f := domain.ThuChiFilter{
		Keyword:    c.Query("keyword"),
		Type:       c.Query("type"),
		CategoryID: c.Query("category_id"),
		Source:     c.Query("source"),
		CreatedBy:  c.Query("created_by"),
		ShopID:     chiNhanhLoc(c),
		FromDate:   c.Query("from_date"),
		ToDate:     c.Query("to_date"),
		Page:       page,
		PageSize:   size,
		// Đối sao kê: chỉ phiếu ghi vào một tài khoản ngân hàng.
		BankAccountID: uintQuery(c, "bank_account_id"),
	}

	list, total, tk, err := h.svc.List(c.Request.Context(), f, nguoiXemThuChi(c))
	if err != nil {
		handleServiceError(c, err)

		return
	}

	// KHÔNG dùng response.Paginated: `meta` ở đây gộp phân trang với bốn ô quỹ.
	// Xem chú thích ở dto.ThuChiMeta vì sao không thêm khoá `summary` riêng.
	c.JSON(http.StatusOK, response.Body{Success: true, Data: list, Meta: metaThuChi(page, size, total, tk)})
}

func trangThuChi(c *gin.Context) (page, size int) {
	page, _ = strconv.Atoi(c.DefaultQuery("page", "1"))
	size, _ = strconv.Atoi(c.DefaultQuery("page_size", "10"))
	if page < 1 {
		page = 1
	}
	// Kẹp trần như mọi đường danh sách khác: một lượt gọi xin 100000 dòng là một
	// lượt quét cả bảng, và không màn hình nào cần tới thế.
	if size < 1 || size > 1000 {
		size = 10
	}

	return page, size
}

func metaThuChi(page, size int, total int64, tk domain.ThuChiTongKet) dto.ThuChiMeta {
	return dto.ThuChiMeta{
		Pagination: response.Pagination{
			Page:       page,
			PageSize:   size,
			Total:      total,
			TotalPages: int((total + int64(size) - 1) / int64(size)),
		},
		ThuChiTongKet: tk,
	}
}

// ---------- Quầy ----------
//
// Màn Thu chi của module Thu ngân. Gọn hơn màn quản trị ở ba chỗ, và cả ba do
// máy chủ ép chứ không để trình duyệt tự giác: chỉ phiếu MÌNH lập, chỉ phiếu
// lập tay, và mọi lượt ghi đòi ca đang mở (409 CHUA_MO_CA) như mọi khoản tiền
// khác ở quầy. Quyền ghi là `so-quy.them` — thu ngân vốn có quyền đó để ghi két,
// cấp thêm bộ `thu-chi.*` là mở luôn sổ thu chi của cả cửa hàng cho họ.

const quyenLapThuChiQuay = "so-quy.them"

// ListQuay godoc
//
//	@Summary		Sổ thu chi ở quầy
//	@Description	Chỉ phiếu lập tay do chính người gọi lập, ở chi nhánh đang làm. `meta` như /admin/thu-chi, thêm `ca_dang_mo` và `co_the_lap`.
//	@Tags			Thu ngân - Thu chi
//	@Produce		json
//	@Security		BearerAuth
//	@Param			q			query		string	false	"Mã phiếu, mô tả, tên / SĐT người nộp hoặc nhận"
//	@Param			type		query		string	false	"0 = thu, 1 = chi"
//	@Param			shift_id	query		int		false	"Chỉ phiếu của một ca"
//	@Param			from_date	query		string	false	"YYYY-MM-DD"
//	@Param			to_date		query		string	false	"YYYY-MM-DD"
//	@Param			page		query		int		false	"Trang, mặc định 1"
//	@Param			page_size	query		int		false	"Số dòng mỗi trang, mặc định 10"
//	@Success		200			{object}	response.Body{data=[]domain.ThuChi,meta=dto.ThuChiQuayMeta}
//	@Router			/admin/quay/thu-chi [get]
func (h *ThuChiHandler) ListQuay(c *gin.Context) {
	page, size := trangThuChi(c)
	nguoi := nguoiXemQuay(c)
	ca, _ := strconv.ParseUint(c.Query("shift_id"), 10, 64)

	// pham_vi=ca: phiếu của CẢ ca (két chung) — chỉ khi đã chọn một ca; còn lại
	// là phiếu mình lập. Phiếu người khác vẫn khoá sửa/xoá (nguoiXemQuay).
	taoBoi := strconv.FormatUint(uint64(nguoi.UserID), 10)
	if c.Query("pham_vi") == "ca" && ca > 0 {
		taoBoi = ""
	}

	f := domain.ThuChiFilter{
		TimRong:   c.Query("q"),
		Type:      c.Query("type"),
		Source:    domain.ThuChiTuTay,
		CreatedBy: taoBoi,
		ShiftID:   uint(ca),
		ShopID:    chiNhanhLoc(c),
		FromDate:  c.Query("from_date"),
		ToDate:    c.Query("to_date"),
		Page:      page,
		PageSize:  size,
	}

	list, total, tk, err := h.svc.List(c.Request.Context(), f, nguoi)
	if err != nil {
		handleServiceError(c, err)

		return
	}

	meta := dto.ThuChiQuayMeta{ThuChiMeta: metaThuChi(page, size, total, tk), CoTheLap: middleware.CoQuyen(c, quyenLapThuChiQuay)}
	switch id, err := h.svc.CaQuay(c.Request.Context()); {
	case err == nil:
		meta.CaDangMo = &id
	case !errors.Is(err, domain.ErrChuaMoCa):
		handleServiceError(c, err)

		return
	}

	c.JSON(http.StatusOK, response.Body{Success: true, Data: list, Meta: meta})
}

// TaiKhoan godoc
//
//	@Summary	Tài khoản ngân hàng cho phiếu thu chi chuyển khoản
//	@Description	`?cho=lap`: tài khoản ĐANG BẬT của chi nhánh sẽ lập phiếu (hộp lập phiếu). Không có: mọi tài khoản trong phạm vi chi nhánh đang lọc, kể cả đã tắt (ô lọc đối sao kê).
//	@Tags		Admin - Thu chi
//	@Produce	json
//	@Security	BearerAuth
//	@Success	200	{object}	response.Body{data=[]domain.TaiKhoanThuChi}
//	@Router		/admin/thu-chi/tai-khoan [get]
func (h *ThuChiHandler) TaiKhoan(c *gin.Context) {
	ds, err := h.svc.TaiKhoan(c.Request.Context(), c.Query("cho") == "lap", chiNhanhLoc(c))
	if err != nil {
		handleServiceError(c, err)

		return
	}
	response.OK(c, ds)
}

// GetQuay godoc
//
//	@Summary	Một phiếu thu chi ở quầy (để in biên nhận)
//	@Description	Cùng phạm vi với danh sách quầy: chỉ phiếu LẬP TAY do chính người gọi lập, ở chi nhánh đang làm. Ngoài phạm vi trả 404 như không có.
//	@Tags		Thu ngân - Thu chi
//	@Produce	json
//	@Security	BearerAuth
//	@Param		id	path		int	true	"ID phiếu"
//	@Success	200	{object}	response.Body{data=domain.ThuChi}
//	@Router		/admin/quay/thu-chi/{id} [get]
func (h *ThuChiHandler) GetQuay(c *gin.Context) {
	if t, ok := h.phieuQuay(c); ok {
		response.OK(c, t)
	}
}

// NhatKyQuay — nhật ký sửa / xoá của một phiếu, cùng phạm vi với GetQuay.
func (h *ThuChiHandler) NhatKyQuay(c *gin.Context) {
	if t, ok := h.phieuQuay(c); ok {
		h.traNhatKy(c, t.ID)
	}
}

// NhatKy godoc
//
//	@Summary	Nhật ký sửa / xoá của một phiếu thu chi
//	@Tags		Admin - Thu chi
//	@Produce	json
//	@Security	BearerAuth
//	@Param		id	path		int	true	"ID phiếu"
//	@Success	200	{object}	response.Body{data=[]domain.NhatKyThuChi}
//	@Router		/admin/thu-chi/{id}/nhat-ky [get]
func (h *ThuChiHandler) NhatKy(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}
	if _, err := h.svc.GetByID(c.Request.Context(), id, nguoiXemThuChi(c)); err != nil {
		handleServiceError(c, err)

		return
	}
	h.traNhatKy(c, id)
}

func (h *ThuChiHandler) traNhatKy(c *gin.Context, id uint) {
	ds, err := h.svc.NhatKy(c.Request.Context(), id)
	if err != nil {
		handleServiceError(c, err)

		return
	}
	response.OK(c, ds)
}

// phieuQuay đọc một phiếu trong phạm vi của quầy; ngoài phạm vi trả 404 như không có.
func (h *ThuChiHandler) phieuQuay(c *gin.Context) (*domain.ThuChi, bool) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return nil, false
	}
	nguoi := nguoiXemQuay(c)
	t, err := h.svc.GetByID(c.Request.Context(), id, nguoi)
	if err == nil {
		cn := chiNhanhLoc(c)
		cuaToi := t.CreatedBy != nil && *t.CreatedBy == nguoi.UserID
		// Phiếu người khác trong một ca: chế độ "Cả ca" đã cho xem thì cũng in được.
		if t.Source != domain.ThuChiTuTay || (!cuaToi && t.ShiftID == nil) || (cn > 0 && t.ShopID != cn) {
			err = domain.ErrNotFound
		}
	}
	if err != nil {
		handleServiceError(c, err)

		return nil, false
	}
	return t, true
}

// DanhMucQuay godoc
//
//	@Summary	Danh mục cho hộp lập phiếu ở quầy
//	@Description	Phân loại thu chi, nhân viên (kèm `payer_type`) và người nộp / nhận khác — trong một lượt.
//	@Tags		Thu ngân - Thu chi
//	@Produce	json
//	@Security	BearerAuth
//	@Success	200	{object}	response.Body{data=domain.ThuChiDanhMucQuay}
//	@Router		/admin/quay/thu-chi/danh-muc [get]
func (h *ThuChiHandler) DanhMucQuay(c *gin.Context) {
	dm, err := h.svc.DanhMucQuay(c.Request.Context())
	if err != nil {
		handleServiceError(c, err)

		return
	}
	response.OK(c, dm)
}

// CreateQuay godoc
//
//	@Summary		Lập phiếu thu chi ở quầy
//	@Description	Như POST /admin/thu-chi, nhưng chưa mở ca thì 409 `errors.ma = CHUA_MO_CA`.
//	@Tags			Thu ngân - Thu chi
//	@Accept			json
//	@Produce		json
//	@Security		BearerAuth
//	@Param			body	body		dto.ThuChiRequest	true	"Thông tin phiếu"
//	@Success		201		{object}	response.Body{data=domain.ThuChi}
//	@Failure		409		{object}	response.Body
//	@Failure		422		{object}	response.Body
//	@Router			/admin/quay/thu-chi [post]
func (h *ThuChiHandler) CreateQuay(c *gin.Context) {
	if !h.canCaQuay(c) {
		return
	}
	h.lap(c, true)
}

// UpdateQuay godoc
//
//	@Summary	Sửa phiếu thu chi ở quầy
//	@Description	Cùng ba lớp khoá với PUT /admin/thu-chi/{id}; chưa mở ca thì 409 CHUA_MO_CA.
//	@Tags		Thu ngân - Thu chi
//	@Accept		json
//	@Produce	json
//	@Security	BearerAuth
//	@Param		id		path		int					true	"ID phiếu"
//	@Param		body	body		dto.ThuChiRequest	true	"Thông tin phiếu"
//	@Success	200		{object}	response.Body{data=domain.ThuChi}
//	@Failure	409		{object}	response.Body
//	@Router		/admin/quay/thu-chi/{id} [put]
func (h *ThuChiHandler) UpdateQuay(c *gin.Context) {
	if !h.canCaQuay(c) {
		return
	}
	h.sua(c, true)
}

// DeleteQuay godoc
//
//	@Summary	Xoá phiếu thu chi ở quầy
//	@Description	Cùng ba lớp khoá với DELETE /admin/thu-chi/{id}; chưa mở ca thì 409 CHUA_MO_CA.
//	@Tags		Thu ngân - Thu chi
//	@Produce	json
//	@Security	BearerAuth
//	@Param		id	path		int	true	"ID phiếu"
//	@Success	200	{object}	response.Body
//	@Failure	409	{object}	response.Body
//	@Router		/admin/quay/thu-chi/{id} [delete]
func (h *ThuChiHandler) DeleteQuay(c *gin.Context) {
	if !h.canCaQuay(c) {
		return
	}
	h.xoa(c, nguoiXemQuay(c))
}

func (h *ThuChiHandler) canCaQuay(c *gin.Context) bool {
	_, err := h.svc.CaQuay(c.Request.Context())
	switch {
	case err == nil:
		return true
	case errors.Is(err, domain.ErrChuaMoCa):
		response.ErrorMa(c, http.StatusConflict, response.MaChuaMoCa,
			"Chi nhánh chưa mở ca — mở ca rồi mới lập phiếu thu chi ở quầy.")
	default:
		handleServiceError(c, err)
	}

	return false
}

// Get godoc
//
//	@Summary	Chi tiết một phiếu thu chi
//	@Tags		Admin - Thu chi
//	@Produce	json
//	@Security	BearerAuth
//	@Param		id	path		int	true	"ID phiếu"
//	@Success	200	{object}	response.Body{data=domain.ThuChi}
//	@Failure	404	{object}	response.Body
//	@Router		/admin/thu-chi/{id} [get]
func (h *ThuChiHandler) Get(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}

	t, err := h.svc.GetByID(c.Request.Context(), id, nguoiXemThuChi(c))
	if err != nil {
		handleServiceError(c, err)

		return
	}
	response.OK(c, t)
}

// Create godoc
//
//	@Summary		Lập phiếu thu / phiếu chi
//	@Description	Mã phiếu, chi nhánh, ca trực và người lập đều do máy chủ đặt — không nhận từ payload. Phiếu lập ở đây luôn có `source = manual`, tức là sửa/xoá được; phiếu tự sinh chỉ do các luồng bán hàng / nhập hàng tạo ra.
//	@Description	Phiếu TIỀN MẶT còn ghi thêm một dòng vào sổ quỹ của ca đang trực, vì đó là tiền thật ra vào két.
//	@Tags			Admin - Thu chi
//	@Accept			json
//	@Produce		json
//	@Security		BearerAuth
//	@Param			body	body		dto.ThuChiRequest	true	"Thông tin phiếu"
//	@Success		201		{object}	response.Body{data=domain.ThuChi}
//	@Failure		401		{object}	response.Body
//	@Failure		422		{object}	response.Body
//	@Router			/admin/thu-chi [post]
func (h *ThuChiHandler) Create(c *gin.Context) { h.lap(c, false) }

// lap / sua — quay = đường của quầy: áp "Hạn mức phiếu chi ở quầy".
func (h *ThuChiHandler) lap(c *gin.Context, quay bool) {
	var req dto.ThuChiRequest
	if !bindJSON(c, &req) {
		return
	}
	req.Quay, req.VaiTro = quay, c.GetString(middleware.CtxRole)

	t, err := h.svc.Create(c.Request.Context(), &req, currentUserID(c))
	if err != nil {
		handleServiceError(c, err)

		return
	}
	response.CreatedMessage(c, "Đã lập phiếu", t)
}

// Update godoc
//
//	@Summary		Sửa phiếu thu chi
//	@Description	Ba lớp khoá, xét theo thứ tự: phiếu tự sinh từ chứng từ khác (không ai sửa), phiếu thuộc ca đã đóng (không ai sửa, kể cả chủ tiệm), phiếu do người khác lập (chỉ vai quản lý sửa được).
//	@Description	Mã phiếu, chi nhánh, ca và người lập KHÔNG đổi — chúng chốt lúc lập.
//	@Tags			Admin - Thu chi
//	@Accept			json
//	@Produce		json
//	@Security		BearerAuth
//	@Param			id		path		int					true	"ID phiếu"
//	@Param			body	body		dto.ThuChiRequest	true	"Thông tin phiếu"
//	@Success		200		{object}	response.Body{data=domain.ThuChi}
//	@Failure		404		{object}	response.Body
//	@Failure		409		{object}	response.Body
//	@Failure		422		{object}	response.Body
//	@Router			/admin/thu-chi/{id} [put]
func (h *ThuChiHandler) Update(c *gin.Context) { h.sua(c, false) }

func (h *ThuChiHandler) sua(c *gin.Context, quay bool) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}

	var req dto.ThuChiRequest
	if !bindJSON(c, &req) {
		return
	}
	req.Quay, req.VaiTro = quay, c.GetString(middleware.CtxRole)

	nguoi := nguoiXemThuChi(c)
	if quay {
		nguoi = nguoiXemQuay(c)
	}
	t, err := h.svc.Update(c.Request.Context(), id, &req, nguoi)
	if err != nil {
		handleServiceError(c, err)

		return
	}
	response.OKMessage(c, "Đã lưu phiếu", t)
}

// Delete godoc
//
//	@Summary		Xoá phiếu thu chi
//	@Description	Cùng ba lớp khoá với lượt sửa. Phiếu xoá mềm để tra lịch sử, còn dòng sổ quỹ dẫn xuất thì xoá hẳn — nó là tiền đang nằm trong két của ca.
//	@Tags			Admin - Thu chi
//	@Produce		json
//	@Security		BearerAuth
//	@Param			id	path		int	true	"ID phiếu"
//	@Success		200	{object}	response.Body
//	@Failure		404	{object}	response.Body
//	@Failure		409	{object}	response.Body
//	@Router			/admin/thu-chi/{id} [delete]
func (h *ThuChiHandler) Delete(c *gin.Context) { h.xoa(c, nguoiXemThuChi(c)) }

func (h *ThuChiHandler) xoa(c *gin.Context, nguoi domain.ThuChiNguoiXem) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}

	// Lý do xoá: thân JSON {"reason"} hoặc ?reason= (DELETE không phải ứng dụng nào cũng gửi thân).
	var than struct {
		Reason string `json:"reason"`
	}
	_ = c.ShouldBindJSON(&than)
	if than.Reason == "" {
		than.Reason = c.Query("reason")
	}
	if err := h.svc.Delete(c.Request.Context(), id, nguoi, than.Reason); err != nil {
		handleServiceError(c, err)

		return
	}
	response.OKMessage(c, "Đã xoá phiếu", nil)
}

// ListNguoiNop godoc
//
//	@Summary		Danh sách người nộp / người nhận vãng lai
//	@Description	Người trả hoặc nhận tiền mà không phải nhân viên, cũng không phải nhà cung cấp. Cố ý KHÔNG phân trang: danh sách của một cửa hàng chỉ vài chục dòng và ô chọn cần cả danh sách một lượt.
//	@Tags			Admin - Thu chi
//	@Produce		json
//	@Security		BearerAuth
//	@Param			keyword	query		string	false	"Tên hoặc số điện thoại"
//	@Success		200		{object}	response.Body{data=[]domain.NguoiNopThuChi}
//	@Failure		401		{object}	response.Body
//	@Router			/admin/nguoi-nop-thu-chi [get]
func (h *ThuChiHandler) ListNguoiNop(c *gin.Context) {
	list, err := h.svc.ListNguoiNop(c.Request.Context(), c.Query("keyword"))
	if err != nil {
		handleServiceError(c, err)

		return
	}
	response.OK(c, list)
}

// CreateNguoiNop godoc
//
//	@Summary		Thêm người nộp / người nhận
//	@Description	Ba ô: tên (bắt buộc), điện thoại, địa chỉ. Tên phải khác mọi tên đang có trong cửa hàng.
//	@Tags			Admin - Thu chi
//	@Accept			json
//	@Produce		json
//	@Security		BearerAuth
//	@Param			body	body		dto.NguoiNopThuChiRequest	true	"Thông tin người nộp"
//	@Success		201		{object}	response.Body{data=domain.NguoiNopThuChi}
//	@Failure		409		{object}	response.Body
//	@Failure		422		{object}	response.Body
//	@Router			/admin/nguoi-nop-thu-chi [post]
func (h *ThuChiHandler) CreateNguoiNop(c *gin.Context) {
	var req dto.NguoiNopThuChiRequest
	if !bindJSON(c, &req) {
		return
	}

	n, err := h.svc.CreateNguoiNop(c.Request.Context(), &req)
	if err != nil {
		handleServiceError(c, err)

		return
	}
	response.CreatedMessage(c, "Đã thêm người nộp", n)
}

// DeleteNguoiNop godoc
//
//	@Summary		Xoá người nộp / người nhận
//	@Description	Xoá MỀM. Phiếu cũ vẫn in ra đúng tên người nộp, và tên vừa xoá khai lại được. Danh mục này trước đây chỉ có đường thêm nên một cái tên gõ nhầm nằm lại vĩnh viễn.
//	@Tags			Admin - Thu chi
//	@Produce		json
//	@Security		BearerAuth
//	@Param			id	path		int	true	"ID người nộp"
//	@Success		200	{object}	response.Body
//	@Failure		404	{object}	response.Body
//	@Router			/admin/nguoi-nop-thu-chi/{id} [delete]
func (h *ThuChiHandler) DeleteNguoiNop(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}

	if err := h.svc.DeleteNguoiNop(c.Request.Context(), id); err != nil {
		handleServiceError(c, err)

		return
	}
	response.OKMessage(c, "Đã xoá người nộp", nil)
}

// uintQuery đọc số nguyên dương trên URL; sai dạng thì coi như không lọc.
func uintQuery(c *gin.Context, k string) uint {
	n, _ := strconv.ParseUint(c.Query(k), 10, 64)
	return uint(n)
}
