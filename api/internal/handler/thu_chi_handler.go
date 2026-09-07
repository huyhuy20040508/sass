package handler

import (
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
	page, _ := strconv.Atoi(c.DefaultQuery("page", "1"))
	size, _ := strconv.Atoi(c.DefaultQuery("page_size", "10"))
	if page < 1 {
		page = 1
	}
	// Kẹp trần như mọi đường danh sách khác: một lượt gọi xin 100000 dòng là một
	// lượt quét cả bảng, và không màn hình nào cần tới thế.
	if size < 1 || size > 1000 {
		size = 10
	}

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
	}

	list, total, tk, err := h.svc.List(c.Request.Context(), f, nguoiXemThuChi(c))
	if err != nil {
		handleServiceError(c, err)

		return
	}

	// KHÔNG dùng response.Paginated: `meta` ở đây gộp phân trang với bốn ô quỹ.
	// Xem chú thích ở dto.ThuChiMeta vì sao không thêm khoá `summary` riêng.
	c.JSON(http.StatusOK, response.Body{
		Success: true,
		Data:    list,
		Meta: dto.ThuChiMeta{
			Pagination: response.Pagination{
				Page:       page,
				PageSize:   size,
				Total:      total,
				TotalPages: int((total + int64(size) - 1) / int64(size)),
			},
			ThuChiTongKet: tk,
		},
	})
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
func (h *ThuChiHandler) Create(c *gin.Context) {
	var req dto.ThuChiRequest
	if !bindJSON(c, &req) {
		return
	}

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
func (h *ThuChiHandler) Update(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}

	var req dto.ThuChiRequest
	if !bindJSON(c, &req) {
		return
	}

	t, err := h.svc.Update(c.Request.Context(), id, &req, nguoiXemThuChi(c))
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
func (h *ThuChiHandler) Delete(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}

	if err := h.svc.Delete(c.Request.Context(), id, nguoiXemThuChi(c)); err != nil {
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
