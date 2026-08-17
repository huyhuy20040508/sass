package handler

import (
	"errors"

	"github.com/gin-gonic/gin"

	"sass-api/internal/domain"
	"sass-api/internal/dto"
	"sass-api/internal/middleware"
	"sass-api/internal/service"
	"sass-api/pkg/response"
)

// DungThuHandler là ba đường GHI trên vòng đời hợp đồng của khu điều hành: mở
// tài khoản dùng thử, gia hạn (cũng là chuyển sang chính thức), huỷ.
//
// Tách khỏi KhachHangHandler — nơi chỉ có đường đọc — vì cả ba đường ở đây đều
// qua HAI cửa: vai trò phải ghi được (owner/operator), và phần mềm phải nằm
// trong phân công của người gọi. Trộn chung với các đường đọc là để lần sau có
// người thêm một hàm vào giữa và quên mất cửa thứ nhất.
type DungThuHandler struct {
	svc service.DungThuService
}

func NewDungThuHandler(svc service.DungThuService) *DungThuHandler {
	return &DungThuHandler{svc: svc}
}

// Tao godoc
//
//	@Summary		Mở tài khoản dùng thử cho khách mới
//	@Description	Dựng TRỌN GÓI một khách hàng mới: cửa hàng + chi nhánh mặc định + tài
//	@Description	khoản quản trị (data plane), rồi ký hợp đồng dùng thử (control plane).
//	@Description	Bản HTTP của `cmd/thue-bao ky --dung-thu`, khác một điểm: KHÔNG cho khai
//	@Description	tay giá và hạn mức — hợp đồng thử chạy đúng theo gói đang bán. Thoả thuận
//	@Description	riêng vẫn đi qua công cụ dòng lệnh.
//	@Description	Điều khoản CHÉP từ bảng giá lúc ký rồi sống độc lập: sửa bảng giá sau đó
//	@Description	không đụng tới khách này. Bảng giá không quy định một hạn mức nào thì lượt
//	@Description	ký bị TỪ CHỐI (422) chứ không đoán hộ con số.
//	@Tags			Platform - Khách hàng
//	@Accept			json
//	@Produce		json
//	@Security		BearerAuth
//	@Param			body	body		dto.TaoDungThuRequest	true	"Cửa hàng, tài khoản quản trị và dòng bảng giá"
//	@Success		201		{object}	response.Body{data=dto.TaoDungThuResponse}
//	@Failure		401		{object}	response.Body
//	@Failure		403		{object}	response.Body
//	@Failure		409		{object}	response.Body
//	@Failure		422		{object}	response.Body
//	@Router			/platform/dung-thu [post]
//
// DangKy godoc
//
//	@Summary		Khách tự mở tài khoản dùng thử
//	@Description	ĐƯỜNG CÔNG KHAI, không cần token: form đăng ký trên trang giới thiệu gọi thẳng vào đây.
//	@Description	Gói do máy chủ chọn (Khởi đầu, chu kỳ tháng) — payload KHÔNG có `plan_id`, gửi lên cũng bị bỏ qua.
//	@Description	Chặn lạm dụng bằng giới hạn tần suất theo IP ở tầng route và một ô bẫy (`website`) trong form.
//	@Tags			Đăng ký
//	@Accept			json
//	@Produce		json
//	@Param			body	body		dto.DangKyRequest	true	"Thông tin cửa hàng mới"
//	@Success		201		{object}	response.Body{data=dto.DangKyResponse}
//	@Failure		422		{object}	response.Body
//	@Failure		429		{object}	response.Body
//	@Failure		503		{object}	response.Body
//	@Router			/dang-ky [post]
func (h *DungThuHandler) DangKy(c *gin.Context) {
	var req dto.DangKyRequest
	if !bindJSON(c, &req) {
		return
	}

	res, err := h.svc.DangKy(c.Request.Context(), req)
	if err != nil {
		handleDungThuError(c, err)

		return
	}

	response.CreatedMessage(c, "Đã mở tài khoản dùng thử", res)
}

func (h *DungThuHandler) Tao(c *gin.Context) {
	if !ghiDuoc(c) {
		return
	}

	var req dto.TaoDungThuRequest
	if !bindJSON(c, &req) {
		return
	}

	res, err := h.svc.Tao(c.Request.Context(), middleware.PlatformApps(c), req)
	if err != nil {
		handleDungThuError(c, err)
		return
	}

	// 201 chứ không 200: lượt gọi này tạo ra ba dòng dữ liệu ở hai database, và
	// nơi gọi cần phân biệt được "đã tạo" với "đã sửa" khi đọc log.
	response.CreatedMessage(c, "Đã mở tài khoản dùng thử", res)
}

// TaoChinhThuc godoc
//
//	@Summary		Thêm khách mới kèm hợp đồng chính thức
//	@Description	Dựng TRỌN GÓI một khách hàng mới — cửa hàng + chi nhánh mặc định + tài
//	@Description	khoản quản trị (data plane) + hợp đồng (control plane) — y hệt
//	@Description	POST /platform/dung-thu, khác đúng một chỗ: hợp đồng ra `active` ngay và
//	@Description	KHÔNG có giai đoạn dùng thử. Thời hạn tính bằng THÁNG.
//	@Description	`so_thang` bỏ trống = một chu kỳ của gói (1 tháng, hoặc 12 nếu gói theo năm).
//	@Description	Giá và ba hạn mức CHÉP từ bảng giá lúc ký rồi sống độc lập — không có ô
//	@Description	nào khai tay. Bảng giá thiếu hạn mức thì lượt tạo bị TỪ CHỐI (422).
//	@Description	Việc này KHÔNG ghi tiền vào sổ thu; thu tiền là đường riêng.
//	@Tags			Platform - Khách hàng
//	@Accept			json
//	@Produce		json
//	@Security		BearerAuth
//	@Param			body	body		dto.TaoChinhThucRequest	true	"Cửa hàng, tài khoản quản trị và dòng bảng giá"
//	@Success		201		{object}	response.Body{data=dto.TaoDungThuResponse}
//	@Failure		401		{object}	response.Body
//	@Failure		403		{object}	response.Body
//	@Failure		409		{object}	response.Body
//	@Failure		422		{object}	response.Body
//	@Router			/platform/chinh-thuc [post]
func (h *DungThuHandler) TaoChinhThuc(c *gin.Context) {
	if !ghiDuoc(c) {
		return
	}

	var req dto.TaoChinhThucRequest
	if !bindJSON(c, &req) {
		return
	}

	res, err := h.svc.TaoChinhThuc(c.Request.Context(), middleware.PlatformApps(c), req)
	if err != nil {
		handleDungThuError(c, err)
		return
	}

	response.CreatedMessage(c, "Đã thêm hợp đồng chính thức cho "+res.TenCuaHang, res)
}

// CuaHangChuaKy godoc
//
//	@Summary		Cửa hàng đã có nhưng chưa ký hợp đồng
//	@Description	Ứng viên để KÝ HỢP ĐỒNG CHÍNH THỨC: cửa hàng đã tồn tại ở database bán
//	@Description	hàng nhưng chưa có hợp đồng còn hiệu lực cho phần mềm này.
//	@Description	Phép trừ làm ở tầng Go vì hai danh sách nằm ở hai database khác nhau —
//	@Description	không câu truy vấn nào JOIN được chúng.
//	@Description	"Còn hiệu lực" gồm cả `trial` và `past_due`, không riêng `active`: cả hai
//	@Description	đều đang giữ chỗ của khoá "mỗi khách mỗi phần mềm một hợp đồng".
//	@Tags			Platform - Khách hàng
//	@Produce		json
//	@Security		BearerAuth
//	@Param			app	query		string	false	"Mã phần mềm; bỏ trống = mọi phần mềm được giao"
//	@Success		200	{object}	response.Body{data=dto.CuaHangCoSanResponse}
//	@Failure		401	{object}	response.Body
//	@Failure		403	{object}	response.Body
//	@Router			/platform/cua-hang-chua-ky [get]
func (h *DungThuHandler) CuaHangChuaKy(c *gin.Context) {
	res, err := h.svc.CuaHangChuaKy(c.Request.Context(), middleware.PlatformApps(c), c.Query("app"))
	if err != nil {
		handleDungThuError(c, err)
		return
	}

	response.OK(c, res)
}

// KyHopDong godoc
//
//	@Summary		Ký hợp đồng chính thức cho cửa hàng đã có
//	@Description	Ghi một hợp đồng `active` cho cửa hàng ĐÃ TỒN TẠI. Khác POST /platform/dung-thu
//	@Description	ở chỗ không dựng gì bên database bán hàng — cửa hàng và tài khoản đăng
//	@Description	nhập đã có sẵn, nên đường này không bắc qua hai database.
//	@Description	Giá và ba hạn mức CHÉP từ bảng giá lúc ký rồi sống độc lập; không có ô nào
//	@Description	khai tay. Thoả thuận riêng vẫn đi qua `cmd/thue-bao ky`.
//	@Description	`so_thang` bỏ trống = một chu kỳ của gói (1 tháng, hoặc 12 nếu gói theo năm).
//	@Tags			Platform - Khách hàng
//	@Accept			json
//	@Produce		json
//	@Security		BearerAuth
//	@Param			body	body		dto.KyHopDongRequest	true	"Cửa hàng và dòng bảng giá"
//	@Success		201		{object}	response.Body{data=dto.HopDongMotItem}
//	@Failure		401		{object}	response.Body
//	@Failure		403		{object}	response.Body
//	@Failure		409		{object}	response.Body
//	@Failure		422		{object}	response.Body
//	@Router			/platform/hop-dong [post]
func (h *DungThuHandler) KyHopDong(c *gin.Context) {
	if !ghiDuoc(c) {
		return
	}

	var req dto.KyHopDongRequest
	if !bindJSON(c, &req) {
		return
	}

	hd, err := h.svc.KyHopDong(c.Request.Context(), middleware.PlatformApps(c), req)
	if err != nil {
		handleDungThuError(c, err)
		return
	}

	response.CreatedMessage(c, "Đã ký hợp đồng cho "+hd.TenCuaHang, dto.HopDongMotItem{HopDong: hd})
}

// ChiTiet godoc
//
//	@Summary		Chi tiết một hợp đồng
//	@Description	Trọn hợp đồng kèm hồ sơ khách đứng sau nó: điều khoản đã chốt, hai mốc
//	@Description	của kỳ dùng thử, ghi chú hợp đồng và ghi chú khách, ngày vào sổ.
//	@Description	`sua_duoc_han` nói màn hình có được hiện ô đổi ngày hết hạn không —
//	@Description	luật do máy chủ quyết, giao diện chỉ đọc cờ.
//	@Description	Chỉ phần mềm người gọi được phụ trách.
//	@Tags			Platform - Khách hàng
//	@Produce		json
//	@Security		BearerAuth
//	@Param			id	path		int	true	"ID hợp đồng"
//	@Success		200	{object}	response.Body{data=dto.HopDongChiTietResponse}
//	@Failure		401	{object}	response.Body
//	@Failure		403	{object}	response.Body
//	@Failure		404	{object}	response.Body
//	@Router			/platform/subscriptions/{id} [get]
func (h *DungThuHandler) ChiTiet(c *gin.Context) {
	// KHÔNG qua ghiDuoc: đây là đường ĐỌC, và `support` phải xem được mọi màn hình
	// của khu điều hành — xem chú thích ở ghiDuoc.
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}

	hd, err := h.svc.ChiTiet(c.Request.Context(), middleware.PlatformApps(c), id)
	if err != nil {
		handleDungThuError(c, err)
		return
	}

	response.OK(c, dto.HopDongChiTietResponse{HopDong: hd})
}

// Sua godoc
//
//	@Summary		Sửa thông tin khách và ghi chú của hợp đồng
//	@Description	Sửa THÔNG TIN, không sửa ĐIỀU KHOẢN. Gói, chu kỳ, giá và ba hạn mức
//	@Description	không có ô nào ở đây: chúng đã chốt lúc ký và cả hệ thống dựng trên
//	@Description	nguyên tắc chúng không đổi. Bán thêm quyền lợi cho một khách vẫn là việc
//	@Description	của `cmd/thue-bao`.
//	@Description	`het_han` chỉ nhận khi hợp đồng đang dùng thử — hạn của kỳ thử là quyết
//	@Description	định bán hàng, còn hạn của hợp đồng đã trả tiền thì đi đường Gia hạn để
//	@Description	đường tiền và đường hạn không tách nhau. Bỏ trống = giữ nguyên.
//	@Description	Hợp đồng đã huỷ không sửa được: đó là bản ghi lịch sử.
//	@Tags			Platform - Khách hàng
//	@Accept			json
//	@Produce		json
//	@Security		BearerAuth
//	@Param			id		path		int						true	"ID hợp đồng"
//	@Param			body	body		dto.SuaHopDongRequest	true	"Thông tin khách và ghi chú"
//	@Success		200		{object}	response.Body{data=dto.HopDongChiTietResponse}
//	@Failure		401		{object}	response.Body
//	@Failure		403		{object}	response.Body
//	@Failure		404		{object}	response.Body
//	@Failure		409		{object}	response.Body
//	@Failure		422		{object}	response.Body
//	@Router			/platform/subscriptions/{id} [put]
func (h *DungThuHandler) Sua(c *gin.Context) {
	if !ghiDuoc(c) {
		return
	}
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}

	var req dto.SuaHopDongRequest
	if !bindJSON(c, &req) {
		return
	}

	hd, err := h.svc.Sua(c.Request.Context(), middleware.PlatformApps(c), id, req)
	if err != nil {
		handleDungThuError(c, err)
		return
	}

	response.OKMessage(c, "Đã lưu thay đổi", dto.HopDongChiTietResponse{HopDong: hd})
}

// ThuTien godoc
//
//	@Summary		Ghi nhận một lần tiền vào
//	@Description	Ghi MỘT LẦN TIỀN VÀO sổ thu, KHÔNG đẩy hạn hợp đồng. Hai việc đó cố ý
//	@Description	tách rời: gộp lại thì mỗi lần gia hạn báo một khoản doanh thu chưa ai
//	@Description	trả, và khách trả trước mà chưa muốn đẩy hạn thì không ghi vào đâu được.
//	@Description	Chu kỳ bỏ trống thì máy chủ tự tính: từ điểm cuối của kỳ đã trả gần nhất
//	@Description	(hoặc ngày bắt đầu hợp đồng) tới hạn hiện tại — nhờ vậy hai lần thu liên
//	@Description	tiếp không chồng lấn và cũng không để hở khoảng nào.
//	@Description	Số tiền bỏ trống = giá đã ký của hợp đồng.
//	@Description	Một hợp đồng · một chu kỳ · một lần thu (409 nếu ghi trùng kỳ).
//	@Tags			Platform - Khách hàng
//	@Accept			json
//	@Produce		json
//	@Security		BearerAuth
//	@Param			id		path		int					true	"ID hợp đồng"
//	@Param			body	body		dto.ThuTienRequest	true	"Số tiền, hình thức, chu kỳ"
//	@Success		200		{object}	response.Body{data=dto.ThuTienResponse}
//	@Failure		401		{object}	response.Body
//	@Failure		403		{object}	response.Body
//	@Failure		404		{object}	response.Body
//	@Failure		409		{object}	response.Body
//	@Failure		422		{object}	response.Body
//	@Router			/platform/subscriptions/{id}/thu-tien [post]
func (h *DungThuHandler) ThuTien(c *gin.Context) {
	if !ghiDuoc(c) {
		return
	}
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}

	var req dto.ThuTienRequest
	if !bindJSON(c, &req) {
		return
	}

	res, err := h.svc.ThuTien(c.Request.Context(), middleware.PlatformApps(c), id, req)
	if err != nil {
		handleDungThuError(c, err)
		return
	}

	response.OKMessage(c, "Đã ghi nhận tiền vào sổ thu", res)
}

// DoiMatKhau godoc
//
//	@Summary		Đặt lại mật khẩu tài khoản quản trị của khách
//	@Description	Đặt lại mật khẩu cho tài khoản quản trị (super_admin cũ nhất) của cửa
//	@Description	hàng đứng sau hợp đồng này.
//	@Description	Có đường này vì tài khoản quản trị cửa hàng KHÔNG có cách tự khôi phục:
//	@Description	quên-mật-khẩu-qua-email chỉ tồn tại trong cụm storefront dành cho khách
//	@Description	mua sắm. Khách quên mật khẩu thì gọi nhà cung cấp, và đây là chỗ nhà cung
//	@Description	cấp làm được việc đó.
//	@Description	CHỈ đổi mật khẩu — tài khoản đang bị khoá thì vẫn khoá sau khi đổi.
//	@Description	Câu trả lời kèm tên đăng nhập để đọc lại cho khách nghe.
//	@Tags			Platform - Khách hàng
//	@Accept			json
//	@Produce		json
//	@Security		BearerAuth
//	@Param			id		path		int						true	"ID hợp đồng"
//	@Param			body	body		dto.DoiMatKhauRequest	true	"Mật khẩu mới"
//	@Success		200		{object}	response.Body{data=dto.QuanTriItem}
//	@Failure		401		{object}	response.Body
//	@Failure		403		{object}	response.Body
//	@Failure		404		{object}	response.Body
//	@Failure		422		{object}	response.Body
//	@Router			/platform/subscriptions/{id}/doi-mat-khau [post]
func (h *DungThuHandler) DoiMatKhau(c *gin.Context) {
	if !ghiDuoc(c) {
		return
	}
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}

	var req dto.DoiMatKhauRequest
	if !bindJSON(c, &req) {
		return
	}

	qt, err := h.svc.DoiMatKhau(c.Request.Context(), middleware.PlatformApps(c), id, req.MatKhau)
	if err != nil {
		handleDungThuError(c, err)
		return
	}

	response.OKMessage(c, "Đã đổi mật khẩu cho tài khoản "+qt.TenDangNhap, qt)
}

// GiaHan godoc
//
//	@Summary		Gia hạn hợp đồng (cũng là chuyển dùng thử sang chính thức)
//	@Description	Đẩy hạn thêm `so_thang` tháng tính từ GREATEST(ngày hết hạn, hôm nay) —
//	@Description	hợp đồng đã quá hạn ba tháng mà cộng dồn từ ngày cũ thì khách trả tiền
//	@Description	xong vẫn còn quá hạn.
//	@Description	Trạng thái chuyển sang `active` và mốc hết dùng thử bị xoá, nên gọi trên
//	@Description	một hợp đồng `trial` CHÍNH LÀ chuyển khách sang chính thức. Không có
//	@Description	endpoint riêng cho việc đó: hai việc là một.
//	@Description	KHÔNG ghi vào sổ thu — tiền vào là `cmd/thue-bao thu-tien`, việc khác.
//	@Tags			Platform - Khách hàng
//	@Accept			json
//	@Produce		json
//	@Security		BearerAuth
//	@Param			id		path		int					true	"ID hợp đồng"
//	@Param			body	body		dto.GiaHanRequest	true	"Số tháng gia hạn"
//	@Success		200		{object}	response.Body{data=dto.HopDongMotItem}
//	@Failure		401		{object}	response.Body
//	@Failure		403		{object}	response.Body
//	@Failure		404		{object}	response.Body
//	@Failure		422		{object}	response.Body
//	@Router			/platform/subscriptions/{id}/gia-han [post]
func (h *DungThuHandler) GiaHan(c *gin.Context) {
	if !ghiDuoc(c) {
		return
	}
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}

	var req dto.GiaHanRequest
	if !bindJSON(c, &req) {
		return
	}

	hd, err := h.svc.GiaHan(c.Request.Context(), middleware.PlatformApps(c), id, req.SoThang)
	if err != nil {
		handleDungThuError(c, err)
		return
	}

	response.OKMessage(c, "Đã gia hạn hợp đồng", dto.HopDongMotItem{HopDong: hd})
}

// Huy godoc
//
//	@Summary		Huỷ hợp đồng
//	@Description	Đóng hợp đồng và nối lý do vào `note` — KHÔNG xoá dòng: khách cũ vẫn phải
//	@Description	tra được mình đã dùng gì, và báo cáo doanh thu của những tháng đã qua vẫn
//	@Description	phải đứng nguyên.
//	@Description	Huỷ xong là khoá "mỗi khách mỗi phần mềm một hợp đồng" được nhả ra, nên
//	@Description	đây cũng là bước bắt buộc trước khi ký lại cho khách đổi gói.
//	@Tags			Platform - Khách hàng
//	@Accept			json
//	@Produce		json
//	@Security		BearerAuth
//	@Param			id		path		int				true	"ID hợp đồng"
//	@Param			body	body		dto.HuyRequest	false	"Lý do huỷ"
//	@Success		200		{object}	response.Body
//	@Failure		401		{object}	response.Body
//	@Failure		403		{object}	response.Body
//	@Failure		404		{object}	response.Body
//	@Failure		422		{object}	response.Body
//	@Router			/platform/subscriptions/{id}/huy [post]
func (h *DungThuHandler) Huy(c *gin.Context) {
	if !ghiDuoc(c) {
		return
	}
	id, ok := parseUintParam(c, "id")
	if !ok {
		return
	}

	// Body không bắt buộc: huỷ không kèm lý do vẫn là một lượt huỷ hợp lệ. Bind
	// thẳng thì thân rỗng thành lỗi 400 và người bấm nút không hiểu vì sao.
	var req dto.HuyRequest
	if c.Request.ContentLength > 0 && !bindJSON(c, &req) {
		return
	}

	if err := h.svc.Huy(c.Request.Context(), middleware.PlatformApps(c), id, req.LyDo); err != nil {
		handleDungThuError(c, err)
		return
	}

	response.OKMessage(c, "Đã huỷ hợp đồng", nil)
}

// ghiDuoc chặn vai trò chỉ-được-xem ngay ở tầng HTTP.
//
// Xét ở handler chứ không ở middleware, cùng lý do với PlanHandler.UpdateFeatures:
// `support` vẫn phải ĐỌC được mọi màn hình của khu điều hành, nên cửa này chỉ
// dựng trước các đường ghi. Đặt vào middleware của cả nhóm /platform là khoá luôn
// phần đọc.
func ghiDuoc(c *gin.Context) bool {
	if domain.PlatformRoleGhiDuoc(middleware.PlatformRole(c)) {
		return true
	}
	response.Error(c, 403, "Vai trò của bạn trong khu điều hành chỉ được xem, không mở hay sửa được hợp đồng")

	return false
}

// handleDungThuError trả 422 kèm lỗi TỪNG Ô khi form không hợp lệ, còn lại nhường
// cho bộ ánh xạ lỗi dùng chung.
//
// Form này có tám ô. Trả một câu chung thì người bán phải tự dò xem mã cửa hàng
// sai hay mật khẩu ngắn — mà họ đang ngồi trước mặt khách.
func handleDungThuError(c *gin.Context, err error) {
	var ve *service.LoiTheoO
	if errors.As(err, &ve) {
		response.ValidationError(c, ve.Fields)
		return
	}
	handleServiceError(c, err)
}
