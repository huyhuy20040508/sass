// Package handler là tầng HTTP (Gin) — chỉ nhận request, gọi service, trả response.
package handler

import (
	"errors"
	"strconv"
	"strings"

	"sass-api/internal/domain"
	"sass-api/internal/middleware"
	"sass-api/internal/service"
	"sass-api/pkg/response"

	"github.com/gin-gonic/gin"
	"github.com/go-playground/validator/v10"
)

// bindJSON parse body JSON, tự trả lỗi 422 nếu không hợp lệ.
func bindJSON(c *gin.Context, req interface{}) bool {
	if err := c.ShouldBindJSON(req); err != nil {
		var ve validator.ValidationErrors
		if errors.As(err, &ve) {
			out := make(map[string]string, len(ve))
			for _, fe := range ve {
				out[fe.Field()] = validationMessage(fe)
			}
			response.ValidationError(c, out)
		} else {
			response.Error(c, 400, "Dữ liệu gửi lên không hợp lệ")
		}
		return false
	}
	return true
}

func validationMessage(fe validator.FieldError) string {
	switch fe.Tag() {
	case "required":
		return "Trường này là bắt buộc"
	case "email":
		return "Email không hợp lệ"
	case "min":
		return "Độ dài tối thiểu là " + fe.Param()
	case "max":
		return "Độ dài tối đa là " + fe.Param()
	default:
		return "Giá trị không hợp lệ"
	}
}

// parseUintParam đọc tham số path dạng uint.
func parseUintParam(c *gin.Context, name string) (uint, bool) {
	v, err := strconv.ParseUint(c.Param(name), 10, 64)
	if err != nil {
		response.Error(c, 400, "Tham số "+name+" không hợp lệ")
		return 0, false
	}
	return uint(v), true
}

// currentUserID lấy user id đã set bởi middleware JWTAuth.
func currentUserID(c *gin.Context) uint {
	if v, ok := c.Get(middleware.CtxUserID); ok {
		if id, ok := v.(uint); ok {
			return id
		}
	}
	return 0
}

// voucherUseError trả lời các lỗi khi KHÁCH dùng mã giảm giá — khác hẳn nhóm lỗi
// lúc quản trị tạo/sửa mã (trùng mã, sai định dạng…) nằm trong handleServiceError.
//
// Trả về true nghĩa là err thuộc nhóm này và response đã ghi xong. Gom vào một hàm
// vì cả ô nhập mã ở giỏ hàng lẫn lúc bấm đặt đều phải trả về CÙNG một câu chữ:
// khách thấy "hết lượt" lúc gõ mã rồi thấy câu khác lúc đặt là tưởng hai lỗi khác nhau.
//
// Mỗi lỗi nói rõ phải làm gì tiếp, vì cách xử lý khác hẳn nhau — bỏ mã đi, chờ tới
// ngày, hay mua thêm cho đủ đơn tối thiểu.
func voucherUseError(c *gin.Context, err error) bool {
	switch {
	case errors.Is(err, domain.ErrVoucherNotFound):
		response.Error(c, 422, "Mã giảm giá không tồn tại, vui lòng kiểm tra lại")
	case errors.Is(err, domain.ErrVoucherInactive):
		response.Error(c, 422, "Mã giảm giá này đang tạm dừng")
	case errors.Is(err, domain.ErrVoucherNotStarted):
		response.Error(c, 422, "Mã giảm giá chưa tới ngày sử dụng")
	case errors.Is(err, domain.ErrVoucherExpired):
		response.Error(c, 422, "Mã giảm giá đã hết hạn")
	case errors.Is(err, domain.ErrVoucherOutOfUses):
		response.Error(c, 422, "Mã giảm giá đã hết lượt sử dụng")
	case errors.Is(err, domain.ErrVoucherUserLimitReached):
		response.Error(c, 422, "Bạn đã dùng hết số lượt của mã này")
	case errors.Is(err, domain.ErrVoucherMinOrder):
		// err đã kèm số tiền còn thiếu
		response.Error(c, 422, "Đơn hàng chưa đủ điều kiện dùng mã — cần mua thêm "+
			strings.TrimPrefix(err.Error(), domain.ErrVoucherMinOrder.Error()+": "))
	default:
		return false
	}
	return true
}

// handleServiceError ánh xạ lỗi nghiệp vụ sang mã HTTP phù hợp.
func handleServiceError(c *gin.Context, err error) {
	// Lỗi theo TỪNG Ô đứng trước mọi nhánh khác: nó mang sẵn tên ô và câu chữ,
	// nên chỉ việc trả 422 y như lỗi validate của binding. Trước đây chỉ handler
	// dùng thử bắt kiểu này, và mọi service khác trả nó về đây đều rơi xuống
	// nhánh mặc định — người dùng nhận 500 cho một lỗi nhập liệu.
	var theoO *service.LoiTheoO
	if errors.As(err, &theoO) {
		response.ValidationError(c, theoO.Fields)

		return
	}

	switch {
	case errors.Is(err, domain.ErrNotFound):
		response.Error(c, 404, "Không tìm thấy dữ liệu")
	case errors.Is(err, domain.ErrEmailExists):
		response.Error(c, 409, "Email đã được sử dụng")
	case errors.Is(err, domain.ErrSlugExists):
		response.Error(c, 409, "Đường dẫn (slug) này đã có sản phẩm khác dùng, vui lòng đổi tên hoặc sửa slug")
	case errors.Is(err, domain.ErrSKUExists):
		response.Error(c, 409, "SKU này đã có sản phẩm khác dùng, vui lòng đặt SKU khác")
	case errors.Is(err, domain.ErrProductStatusInvalid):
		response.ValidationError(c, map[string]string{"status": "Trạng thái sản phẩm không hợp lệ"})
	case errors.Is(err, domain.ErrConflict):
		response.Error(c, 409, "Dữ liệu đang được tham chiếu hoặc bị trùng, không thể thực hiện")
	case errors.Is(err, domain.ErrInvalidCredentials):
		response.Error(c, 401, "Email hoặc mật khẩu không đúng")
	case errors.Is(err, domain.ErrShopLoginFailed):
		response.Error(c, 401, "Mã cửa hàng, tên đăng nhập hoặc mật khẩu không đúng")
	case errors.Is(err, domain.ErrTenantSuspended):
		response.Error(c, 403, "Cửa hàng đang tạm khoá, vui lòng liên hệ nhà cung cấp phần mềm")
	case errors.Is(err, domain.ErrUsernameExists):
		response.Error(c, 409, "Tên đăng nhập này đã có người dùng, vui lòng đặt tên khác")
	case errors.Is(err, domain.ErrUsernameInvalid):
		response.ValidationError(c, map[string]string{
			"username": "Tên đăng nhập chỉ gồm chữ thường không dấu, số, dấu chấm, gạch ngang hoặc gạch dưới (3–50 ký tự)",
		})
	case errors.Is(err, domain.ErrUserInactive):
		response.Error(c, 403, "Tài khoản đang không hoạt động, vui lòng liên hệ cửa hàng")
	case errors.Is(err, domain.ErrPaymentMethodDisabled):
		response.Error(c, 422, "Cửa hàng hiện không nhận hình thức thanh toán này, vui lòng chọn cách khác")
	case errors.Is(err, domain.ErrForbidden):
		response.Error(c, 403, "Không có quyền thực hiện")
	case errors.Is(err, domain.ErrBannerPositionInvalid):
		response.ValidationError(c, map[string]string{"position": "Vị trí hiển thị không hợp lệ"})
	case errors.Is(err, domain.ErrBannerScheduleInvalid):
		response.ValidationError(c, map[string]string{"end_at": "Ngày kết thúc phải sau ngày bắt đầu"})
	// 422 chứ không phải 401: phiên đăng nhập vẫn tốt, chỉ ô "mật khẩu hiện tại"
	// gõ sai. Trả 401 thì trang quản trị hiểu là token chết và đá người dùng ra
	// màn hình đăng nhập chỉ vì một lần gõ nhầm.
	case errors.Is(err, domain.ErrPasswordIncorrect):
		response.ValidationError(c, map[string]string{"current_password": "Mật khẩu hiện tại không đúng"})
	case errors.Is(err, domain.ErrPasswordSame):
		response.ValidationError(c, map[string]string{"new_password": "Mật khẩu mới trùng với mật khẩu đang dùng"})
	// 409 chứ không phải 422: dữ liệu gửi lên không có gì sai, form không có ô
	// nào để tô đỏ — cửa hàng chỉ đơn giản là hết chỗ trong gói. err đã kèm tên
	// hạn mức và cặp "đang dùng/trần", in nguyên ra vì người đọc cần biết trần
	// là bao nhiêu mới quyết được nên dọn bớt hay nâng gói.
	case errors.Is(err, domain.ErrVuotHanMuc):
		response.Error(c, 409, "Gói dịch vụ của cửa hàng đã hết chỗ ("+
			strings.TrimPrefix(err.Error(), domain.ErrVuotHanMuc.Error()+": ")+
			"). Xoá bớt hoặc liên hệ nhà cung cấp để nâng gói.")
	// Chi nhánh. Ba lỗi, ba cách chữa khác hẳn nhau — nên không gộp làm một.
	case errors.Is(err, domain.ErrMaChiNhanhDaCo):
		response.ValidationError(c, map[string]string{
			"code": "Mã này đã có chi nhánh khác dùng, vui lòng đặt mã khác",
		})
	case errors.Is(err, domain.ErrMaChiNhanhInvalid):
		response.ValidationError(c, map[string]string{
			"code": "Mã chi nhánh chỉ gồm chữ thường không dấu, số, dấu chấm, gạch ngang hoặc gạch dưới (2–30 ký tự)",
		})
	case errors.Is(err, domain.ErrChiNhanhCuoiCung):
		response.Error(c, 409, "Đây là chi nhánh đang hoạt động cuối cùng — đóng nó xong thì cửa hàng không còn điểm bán nào để ghi đơn hàng hay tồn kho")
	// Nhân sự. Ba lỗi, ba cách chữa khác nhau: đổi mã, chọn người khác, hoặc bỏ
	// tick cấp tài khoản — nên không gộp chúng vào một câu chung.
	case errors.Is(err, domain.ErrMaNhanVienDaCo):
		response.ValidationError(c, map[string]string{
			"code": "Mã này đã có nhân viên khác dùng, vui lòng đặt mã khác",
		})
	case errors.Is(err, domain.ErrTaiKhoanDaGanNhanSu):
		response.Error(c, 409, "Tài khoản này đã gắn với một hồ sơ nhân sự khác")
	case errors.Is(err, domain.ErrNhanSuDaCoTaiKhoan):
		response.Error(c, 409, "Hồ sơ này đã có tài khoản đăng nhập — đổi mật khẩu hay đổi quyền là thao tác riêng, không cấp thêm tài khoản thứ hai")
	// Phân quyền theo chức năng. Ba lỗi, ba cách chữa khác nhau: sửa lại chuỗi
	// gõ sai, chuyển người sang nhóm khác, hoặc thôi đừng xoá nhóm hệ thống.
	case errors.Is(err, domain.ErrQuyenLa):
		response.ValidationError(c, map[string]string{
			"quyen": "Có quyền không nằm trong danh mục của phần mềm (" +
				strings.TrimPrefix(err.Error(), domain.ErrQuyenLa.Error()+": ") + ")",
		})
	case errors.Is(err, domain.ErrMaNhomQuyenDaCo):
		response.ValidationError(c, map[string]string{
			"code": "Mã này đã có nhóm quyền khác dùng, vui lòng đặt mã khác",
		})
	case errors.Is(err, domain.ErrNhomQuyenHeThong):
		response.Error(c, 422, "Đây là nhóm quyền hệ thống dựng sẵn — sửa được tên và quyền, nhưng không xoá được")
	case errors.Is(err, domain.ErrNhanSuDangMoCa):
		response.Error(c, 409, "Nhân viên này còn một ca chưa đóng. Đóng ca đó trước đã — xoá bây giờ là khoá luôn tài khoản của chính người đang giữ két")
	case errors.Is(err, domain.ErrNhanSuDaGhiSoQuy):
		response.Error(c, 409, "Nhân viên này đã ghi sổ quỹ nên hồ sơ phải giữ lại để đối chiếu tiền. Nghỉ việc thì đặt trạng thái \"Đã nghỉ\" — tài khoản vẫn bị khoá")
	case errors.Is(err, domain.ErrTuDanhDauNghiViec):
		response.Error(c, 409, "Đây là hồ sơ gắn với tài khoản bạn đang đăng nhập — đánh dấu nghỉ việc sẽ khoá luôn tài khoản này và đá bạn ra ngoài. Nhờ một quản trị viên khác làm giúp")
	case errors.Is(err, domain.ErrLastSuperAdmin):
		response.Error(c, 409, "Đây là super admin đang hoạt động cuối cùng — khoá hoặc xoá xong sẽ không còn ai quản trị được hệ thống")
	case errors.Is(err, domain.ErrEmailNotVerified):
		response.Error(c, 403, "Email chưa được xác thực, vui lòng nhập mã đã gửi tới hộp thư của bạn")
	case errors.Is(err, domain.ErrCodeInvalid):
		response.Error(c, 400, "Mã xác thực không đúng")
	case errors.Is(err, domain.ErrCodeExpired):
		response.Error(c, 400, "Mã xác thực đã hết hạn, vui lòng bấm gửi lại mã")
	case errors.Is(err, domain.ErrTooManyAttempts):
		response.Error(c, 400, "Nhập sai mã quá 5 lần, vui lòng bấm gửi lại mã mới")
	case errors.Is(err, domain.ErrResendTooSoon):
		// err đã kèm số giây còn phải chờ
		response.Error(c, 429, "Vui lòng chờ "+strings.TrimPrefix(err.Error(), domain.ErrResendTooSoon.Error()+": ")+" rồi thử lại")
	case errors.Is(err, domain.ErrKhongPhuTrachApp):
		response.Error(c, 403, "Bạn không phụ trách phần mềm này")
	// Hai lỗi của luồng KHÁCH TỰ GIA HẠN. Tách hẳn nhau vì người đọc phải làm hai
	// việc khác nhau: một bên là gọi cho nhà cung cấp (chưa bật cổng), một bên là
	// thử lại sau ít phút (cổng vừa trục trặc).
	case errors.Is(err, domain.ErrChuaBatCongThanhToan):
		response.Error(c, 503, "Nhà cung cấp chưa bật thanh toán trực tuyến — vui lòng liên hệ để gia hạn")
	case errors.Is(err, domain.ErrCongThanhToanLoi):
		response.Error(c, 502, "Cổng thanh toán đang không phản hồi, vui lòng thử lại sau ít phút")
	case errors.Is(err, domain.ErrPlatformUnavailable):
		response.Error(c, 503, "Khu điều hành nền tảng chưa sẵn sàng — máy chủ chưa nối được sổ nền tảng")

	// --- Ký hợp đồng từ khu điều hành ---
	// 409 cho hai lỗi TRÙNG (thứ đã có người chiếm) và 422 cho các lỗi bảng giá
	// (thứ phải đi sửa nơi khác rồi quay lại). Người bấm nút xử lý hai nhóm này
	// bằng hai cách hoàn toàn khác nhau, nên chúng không được cùng một mã.
	case errors.Is(err, domain.ErrCuaHangDaCo):
		response.ValidationError(c, map[string]string{
			"ma_cua_hang": "Mã cửa hàng này đã có người dùng, vui lòng đặt mã khác",
		})
	case errors.Is(err, domain.ErrMaConTrongSoNenTang):
		// Gắn vào ô `ma_cua_hang` như lỗi trùng mã thường, nhưng nói RÕ là dòng cũ
		// nằm ở sổ nền tảng: người bấm nút bên khu order sẽ tìm mã đó trong danh
		// sách khách và không thấy gì, rồi tưởng máy chủ hỏng.
		response.ValidationError(c, map[string]string{
			"ma_cua_hang": "Mã này còn trong sổ nền tảng dưới một khách cũ — xoá hẳn khách đó rồi tạo lại, hoặc đặt mã khác",
		})
	case errors.Is(err, domain.ErrHopDongDangChay):
		response.Error(c, 409, "Cửa hàng này đã có hợp đồng còn hiệu lực cho phần mềm đó — gia hạn hợp đồng cũ, hoặc huỷ nó trước rồi ký lại")
	case errors.Is(err, domain.ErrGoiNgungBan):
		response.ValidationError(c, map[string]string{
			"plan_id": "Gói này đã ngừng bán nên không ký mới được, vui lòng chọn gói khác",
		})
	case errors.Is(err, domain.ErrAppChuaBan):
		response.Error(c, 422, "Phần mềm này chưa ở trạng thái đang bán")
	case errors.Is(err, domain.ErrBangGiaChuaCoGia):
		response.ValidationError(c, map[string]string{
			"plan_id": "Bảng giá ghi \"Liên hệ\" cho gói này nên chưa có giá để chép sang hợp đồng",
		})
	case errors.Is(err, domain.ErrBangGiaThieuHanMuc):
		// err đã kèm tên hạn mức còn thiếu — in nguyên ra, vì người sửa cần biết
		// phải điền ô nào ở màn hình Tính năng gói.
		response.ValidationError(c, map[string]string{
			"plan_id": "Bảng giá của gói này chưa khai đủ hạn mức (" +
				strings.TrimPrefix(err.Error(), domain.ErrBangGiaThieuHanMuc.Error()+": ") + ")",
		})
	case errors.Is(err, domain.ErrDaThuKyNay):
		response.Error(c, 409, "Kỳ này đã có một lần thu ghi trong sổ. Nếu đây là lần thu của kỳ khác thì khai rõ ngày đầu và ngày cuối kỳ.")
	case errors.Is(err, domain.ErrKhongConKyDeThu):
		response.Error(c, 409, "Hợp đồng đã trả tiền tới hết hạn hiện tại — gia hạn trước, rồi mới có kỳ mới để thu")
	case errors.Is(err, domain.ErrHopDongDaHuy):
		response.Error(c, 409, "Hợp đồng này đã huỷ — khách quay lại thì ký hợp đồng mới, không mở lại hợp đồng cũ")
	case errors.Is(err, domain.ErrFacebookDisabled):
		response.Error(c, 503, "Cửa hàng chưa bật đăng nhập bằng Facebook")
	case errors.Is(err, domain.ErrFacebookAuthFailed):
		response.Error(c, 401, "Không xác minh được tài khoản Facebook, vui lòng thử lại")
	case errors.Is(err, domain.ErrFacebookNoEmail):
		response.Error(c, 422, "Tài khoản Facebook của bạn không chia sẻ email nên chưa tạo được tài khoản. Vui lòng đăng ký bằng email.")
	case errors.Is(err, domain.ErrPromotionTimeRange):
		response.Error(c, 422, "Thời gian chạy không hợp lệ: ngày kết thúc phải sau ngày bắt đầu")
	case errors.Is(err, domain.ErrPromotionPercentRange):
		response.Error(c, 422, "Phần trăm giảm phải trong khoảng 1–100")
	case errors.Is(err, domain.ErrPromotionNoScope):
		response.Error(c, 422, "Vui lòng chọn ít nhất một sản phẩm, danh mục hoặc thương hiệu để áp dụng")
	case errors.Is(err, domain.ErrVoucherCodeExists):
		response.Error(c, 409, "Mã voucher này đã tồn tại, vui lòng đặt mã khác")
	case errors.Is(err, domain.ErrVoucherCodeInvalid):
		response.ValidationError(c, map[string]string{
			"code": "Mã chỉ gồm chữ không dấu, số, gạch ngang hoặc gạch dưới (3–50 ký tự)",
		})
	case errors.Is(err, domain.ErrVoucherTimeRange):
		response.Error(c, 422, "Thời gian hiệu lực không hợp lệ: ngày kết thúc phải sau ngày bắt đầu")
	case errors.Is(err, domain.ErrVoucherPercentRange):
		response.Error(c, 422, "Phần trăm giảm phải trong khoảng 1–100")
	case errors.Is(err, domain.ErrVoucherLimitBelowUsed):
		response.Error(c, 422, "Tổng lượt dùng đang nhỏ hơn số lượt đã phát ra — mã sẽ hết lượt ngay lúc lưu")
	case errors.Is(err, domain.ErrGoogleDisabled):
		response.Error(c, 503, "Cửa hàng chưa bật đăng nhập bằng Google")
	case errors.Is(err, domain.ErrGoogleAuthFailed):
		response.Error(c, 401, "Không xác minh được tài khoản Google, vui lòng thử lại")
	case errors.Is(err, domain.ErrGoogleNoEmail):
		response.Error(c, 422, "Tài khoản Google của bạn không có email đã xác minh nên chưa tạo được tài khoản. Vui lòng đăng ký bằng email.")
	case errors.Is(err, domain.ErrMailSendFailed):
		response.Error(c, 502, "Không gửi được email xác thực, vui lòng thử lại hoặc liên hệ hotline")
	default:
		response.Error(c, 500, "Đã có lỗi xảy ra, vui lòng thử lại")
	}
}
