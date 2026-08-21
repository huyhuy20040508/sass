package handler

import (
	"errors"
	"net/http"
	"strings"
	"unicode"

	"github.com/gin-gonic/gin"

	"sass-api/internal/dto"
	"sass-api/internal/service"
	"sass-api/pkg/minvoice"
	"sass-api/pkg/response"
)

// ĐỜI SAU CỦA MỘT TỜ HOÁ ĐƠN: ký, đồng bộ, thay thế, điều chỉnh, in, XML.
//
// Cả nhóm nằm dưới /admin/orders/:id/etax và ở quyền `manage` như chính lượt
// phát hành: mỗi lượt ở đây đều ghi hoặc sửa một chứng từ với cơ quan thuế.

// Ky godoc
//
//	@Summary		Ký hoá đơn nháp và gửi cơ quan thuế
//	@Description	Chỉ chạy được với chữ ký số MỀM (file p12 hoặc dịch vụ EASY/ICA/INTRUST).
//	@Description	Khách ký bằng USB token phải ký ở trang của nhà cung cấp.
//	@Tags			Admin - Hoá đơn điện tử
//	@Produce		json
//	@Security		BearerAuth
//	@Param			id	path		int	true	"ID đơn hàng"
//	@Success		200	{object}	response.Body{data=domain.EtaxInvoice}
//	@Failure		401	{object}	response.Body
//	@Failure		403	{object}	response.Body
//	@Failure		404	{object}	response.Body
//	@Failure		409	{object}	response.Body
//	@Failure		502	{object}	response.Body
//	@Router			/admin/orders/{id}/etax/sign [post]
func (h *EtaxHandler) Ky(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}
	hd, err := h.svc.Ky(c.Request.Context(), id)
	if err != nil {
		loiHoaDon(c, err)

		return
	}
	response.OKMessage(c, service.MoTaHoaDon(hd), hd)
}

// DongBoHoaDon godoc
//
//	@Summary		Hỏi lại cổng về trạng thái hoá đơn
//	@Description	Cơ quan thuế cấp mã không tức thì và không báo về, nên phải tự hỏi lại.
//	@Description	Có `tax_auth_code` thì hoá đơn mới thật sự có hiệu lực.
//	@Tags			Admin - Hoá đơn điện tử
//	@Produce		json
//	@Security		BearerAuth
//	@Param			id	path		int	true	"ID đơn hàng"
//	@Success		200	{object}	response.Body{data=domain.EtaxInvoice}
//	@Failure		401	{object}	response.Body
//	@Failure		403	{object}	response.Body
//	@Failure		404	{object}	response.Body
//	@Failure		502	{object}	response.Body
//	@Router			/admin/orders/{id}/etax/sync [post]
func (h *EtaxHandler) DongBoHoaDon(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}
	hd, err := h.svc.DongBo(c.Request.Context(), id)
	if err != nil {
		loiHoaDon(c, err)

		return
	}
	response.OKMessage(c, service.MoTaHoaDon(hd), hd)
}

// ThayThe godoc
//
//	@Summary		Thay thế hoá đơn
//	@Description	Xuất một tờ MỚI thay cho tờ hiện tại, dựng lại từ đơn hàng hôm nay.
//	@Description	Chỉ thay được tờ đã được cơ quan thuế cấp mã, và chỉ tờ Gốc hoặc tờ Thay thế.
//	@Tags			Admin - Hoá đơn điện tử
//	@Accept			json
//	@Produce		json
//	@Security		BearerAuth
//	@Param			id		path		int						true	"ID đơn hàng"
//	@Param			body	body		dto.EtaxThayTheRequest	true	"Lý do thay thế"
//	@Success		200		{object}	response.Body{data=domain.EtaxInvoice}
//	@Failure		401		{object}	response.Body
//	@Failure		403		{object}	response.Body
//	@Failure		404		{object}	response.Body
//	@Failure		409		{object}	response.Body
//	@Failure		422		{object}	response.Body
//	@Failure		502		{object}	response.Body
//	@Router			/admin/orders/{id}/etax/replace [post]
func (h *EtaxHandler) ThayThe(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}
	var req dto.EtaxThayTheRequest
	if !bindJSON(c, &req) {
		return
	}
	hd, err := h.svc.ThayThe(c.Request.Context(), id, &req)
	if err != nil {
		loiHoaDon(c, err)

		return
	}
	response.OKMessage(c, service.MoTaHoaDon(hd), hd)
}

// DieuChinh godoc
//
//	@Summary		Điều chỉnh hoá đơn
//	@Description	Không gửi `dong` = điều chỉnh VỀ 0 (tương đương huỷ): đảo dấu đúng các dòng đã ghi trên tờ cũ.
//	@Description	Gửi `dong` = tự khai phần chênh lệch (số âm là giảm, số dương là tăng).
//	@Tags			Admin - Hoá đơn điện tử
//	@Accept			json
//	@Produce		json
//	@Security		BearerAuth
//	@Param			id		path		int							true	"ID đơn hàng"
//	@Param			body	body		dto.EtaxDieuChinhRequest	true	"Lý do và các dòng điều chỉnh"
//	@Success		200		{object}	response.Body{data=domain.EtaxInvoice}
//	@Failure		401		{object}	response.Body
//	@Failure		403		{object}	response.Body
//	@Failure		404		{object}	response.Body
//	@Failure		409		{object}	response.Body
//	@Failure		422		{object}	response.Body
//	@Failure		502		{object}	response.Body
//	@Router			/admin/orders/{id}/etax/adjust [post]
func (h *EtaxHandler) DieuChinh(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}
	var req dto.EtaxDieuChinhRequest
	if !bindJSON(c, &req) {
		return
	}
	hd, err := h.svc.DieuChinh(c.Request.Context(), id, &req)
	if err != nil {
		loiHoaDon(c, err)

		return
	}
	response.OKMessage(c, service.MoTaHoaDon(hd), hd)
}

// BanIn godoc
//
//	@Summary		Bản PDF của hoá đơn
//	@Description	Trả về THẲNG tệp PDF, không bọc JSON — màn hình mở nó trong tab mới.
//	@Description	`chuyen_doi=1` lấy bản hoá đơn chuyển đổi ra giấy (có chữ ký và dấu bên bán).
//	@Tags			Admin - Hoá đơn điện tử
//	@Produce		application/pdf
//	@Security		BearerAuth
//	@Param			id			path		int		true	"ID đơn hàng"
//	@Param			chuyen_doi	query		string	false	"1 = bản chuyển đổi"
//	@Success		200			{file}		binary
//	@Failure		401			{object}	response.Body
//	@Failure		403			{object}	response.Body
//	@Failure		404			{object}	response.Body
//	@Failure		502			{object}	response.Body
//	@Router			/admin/orders/{id}/etax/pdf [get]
func (h *EtaxHandler) BanIn(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}
	chuyenDoi := c.Query("chuyen_doi") == "1" || c.Query("chuyen_doi") == "true"

	du, err := h.svc.BanIn(c.Request.Context(), id, chuyenDoi)
	if err != nil {
		loiHoaDon(c, err)

		return
	}

	// inline chứ không attachment: người bán bấm "Xem hoá đơn" là muốn nhìn nó
	// ngay, không phải đi tìm một tệp vừa rơi vào thư mục Tải về.
	c.Header("Content-Disposition", `inline; filename="hoa-don.pdf"`)
	c.Data(http.StatusOK, "application/pdf", du)
}

// BanXML godoc
//
//	@Summary		Bản XML gốc của hoá đơn
//	@Description	Tệp XML đã ký — thứ kế toán lưu trữ. Hoá đơn chưa ký thì chưa có.
//	@Tags			Admin - Hoá đơn điện tử
//	@Produce		application/xml
//	@Security		BearerAuth
//	@Param			id	path		int	true	"ID đơn hàng"
//	@Success		200	{file}		binary
//	@Failure		401	{object}	response.Body
//	@Failure		403	{object}	response.Body
//	@Failure		404	{object}	response.Body
//	@Failure		409	{object}	response.Body
//	@Failure		502	{object}	response.Body
//	@Router			/admin/orders/{id}/etax/xml [get]
func (h *EtaxHandler) BanXML(c *gin.Context) {
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}
	du, err := h.svc.BanXML(c.Request.Context(), id)
	if err != nil {
		loiHoaDon(c, err)

		return
	}

	c.Header("Content-Disposition", `attachment; filename="hoa-don.xml"`)
	c.Data(http.StatusOK, "application/xml", du)
}

// TraCuuMST godoc
//
//	@Summary		Tra cứu mã số thuế
//	@Description	Trả tên đơn vị và địa chỉ đã đăng ký với cơ quan thuế, để điền hộ thông tin người mua.
//	@Description	M-Invoice chỉ mở API này cho các địa chỉ IP đã đăng ký trước — 503 nghĩa là máy chủ chưa được mở.
//	@Tags			Admin - Hoá đơn điện tử
//	@Produce		json
//	@Security		BearerAuth
//	@Param			mst	query		string	true	"Mã số thuế cần tra"
//	@Success		200	{object}	response.Body{data=minvoice.ThongTinMST}
//	@Failure		401	{object}	response.Body
//	@Failure		403	{object}	response.Body
//	@Failure		404	{object}	response.Body
//	@Failure		422	{object}	response.Body
//	@Failure		503	{object}	response.Body
//	@Router			/admin/etax/tra-cuu-mst [get]
func (h *EtaxHandler) TraCuuMST(c *gin.Context) {
	mst := strings.TrimSpace(c.Query("mst"))
	if mst == "" {
		response.ValidationError(c, map[string]string{"mst": "Nhập mã số thuế cần tra"})

		return
	}

	tt, err := h.svc.TraCuuMST(c.Request.Context(), mst)
	if err != nil {
		// Chưa mở quyền là việc phải đi ĐĂNG KÝ IP với nhà cung cấp, không phải
		// gõ sai mã số thuế — hai câu khác nhau dẫn tới hai việc khác nhau.
		if errors.Is(err, minvoice.ErrChuaMoTraCuu) {
			response.Error(c, 503, "Máy chủ chưa được M-Invoice mở quyền tra cứu mã số thuế — cần đăng ký địa chỉ IP với nhà cung cấp")

			return
		}
		response.Error(c, 404, "Không tìm thấy mã số thuế "+mst)

		return
	}
	response.OK(c, tt)
}

// loiHoaDon trả lời một lỗi của cổng hoá đơn.
//
// Câu của nhà cung cấp phải đi tới người bấm nút: "ngày hoá đơn không được nhỏ
// hơn ngày của tờ khai" nói được phải làm gì, còn "đã có lỗi xảy ra" thì không.
// Lỗi nghiệp vụ của mình vẫn đi đường chung để giữ đúng mã HTTP.
func loiHoaDon(c *gin.Context, err error) {
	cau := err.Error()
	if !strings.Contains(cau, "minvoice:") {
		handleServiceError(c, err)

		return
	}

	// 502: chỗ hỏng nằm ở nhà cung cấp hoặc ở dữ liệu mình gửi cho họ, không
	// phải ở máy chủ này.
	response.Error(c, 502, hoaDauCau(strings.ReplaceAll(cau, "minvoice: ", "")))
}

// hoaDauCau viết hoa chữ đầu để câu lỗi đọc ra như một câu.
func hoaDauCau(s string) string {
	if s == "" {
		return s
	}
	r := []rune(s)

	return string(unicode.ToUpper(r[0])) + string(r[1:])
}
