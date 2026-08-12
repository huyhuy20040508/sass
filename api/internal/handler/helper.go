// Package handler là tầng HTTP (Gin) — chỉ nhận request, gọi service, trả response.
package handler

import (
	"errors"
	"strconv"
	"strings"

	"sass-api/internal/domain"
	"sass-api/internal/middleware"
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
