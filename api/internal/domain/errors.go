package domain

import "errors"

// Lỗi nghiệp vụ dùng chung — tầng service trả về, handler ánh xạ sang mã HTTP.
var (
	ErrNotFound           = errors.New("không tìm thấy dữ liệu")
	ErrEmailExists        = errors.New("email đã được sử dụng")
	ErrInvalidCredentials = errors.New("email hoặc mật khẩu không đúng")
	ErrUserInactive       = errors.New("tài khoản đang không hoạt động, vui lòng liên hệ cửa hàng")
	ErrForbidden          = errors.New("không có quyền thực hiện")
	ErrSlugExists         = errors.New("slug đã tồn tại")
	ErrConflict           = errors.New("dữ liệu bị xung đột")
	ErrInvalidStatus      = errors.New("không thể chuyển sang trạng thái này")

	// SKU trùng. Trước đây chỉ có UNIQUE ở DB đỡ, nên người dùng nhận về đúng một
	// câu "Đã có lỗi xảy ra" mà không biết phải sửa gì — trong khi SKU tự sinh
	// theo đội bóng · loại áo · mùa giải thì đụng nhau là chuyện thường ngày.
	ErrSKUExists = errors.New("SKU đã tồn tại")

	// Trạng thái sản phẩm ngoài danh sách active | hidden | discontinued.
	ErrProductStatusInvalid = errors.New("trạng thái sản phẩm không hợp lệ")

	// Tài khoản nội bộ: khoá/xoá/hạ vai trò người super admin đang hoạt động cuối
	// cùng sẽ không còn ai mở lại được hệ thống.
	ErrLastSuperAdmin = errors.New("phải còn ít nhất một super admin đang hoạt động")

	// Đăng nhập 3 ô của Shop Admin (mã cửa hàng / tên đăng nhập / mật khẩu).
	//
	// MỘT lỗi chung cho cả ba ô, cố ý: nói riêng "mã cửa hàng không tồn tại" thì
	// màn hình đăng nhập thành công cụ dò xem mình có những khách hàng nào, và
	// nói riêng "sai mật khẩu" là xác nhận tên đăng nhập đó có thật.
	ErrShopLoginFailed = errors.New("mã cửa hàng, tên đăng nhập hoặc mật khẩu không đúng")
	// Cửa hàng bị khoá (tenants.status = suspended) — hết hạn hoặc ngừng trả tiền.
	// Chỉ báo SAU khi đã đúng mật khẩu, xem authService.LoginShop.
	ErrTenantSuspended = errors.New("cửa hàng đang tạm khoá")

	// Tên đăng nhập của tài khoản nội bộ.
	ErrUsernameExists = errors.New("tên đăng nhập đã được sử dụng")
	// Tên đăng nhập phải gõ được trên bàn phím điện thoại, không dấu, không
	// khoảng trắng — nhân viên gõ nó mỗi ca làm.
	ErrUsernameInvalid = errors.New("tên đăng nhập chỉ gồm chữ thường không dấu, số, dấu chấm, gạch ngang hoặc gạch dưới (3–50 ký tự)")

	// Tự đổi mật khẩu. Tách khỏi ErrInvalidCredentials (401 — token hỏng, phải
	// đăng nhập lại): ở đây token vẫn tốt, chỉ mỗi ô "mật khẩu hiện tại" gõ sai,
	// trả 401 thì trang quản trị sẽ đá người dùng ra màn hình đăng nhập oan.
	ErrPasswordIncorrect = errors.New("mật khẩu hiện tại không đúng")
	ErrPasswordSame      = errors.New("mật khẩu mới trùng với mật khẩu đang dùng")

	// Xác thực email khi đăng ký
	ErrEmailNotVerified = errors.New("email chưa được xác thực")
	ErrCodeInvalid      = errors.New("mã xác thực không đúng")
	ErrCodeExpired      = errors.New("mã xác thực đã hết hạn")
	ErrTooManyAttempts  = errors.New("nhập sai mã quá nhiều lần")
	ErrResendTooSoon    = errors.New("vui lòng chờ trước khi gửi lại mã")
	ErrMailSendFailed   = errors.New("không gửi được email xác thực")

	// Đăng nhập bằng Facebook
	// Chưa khai FACEBOOK_APP_ID / FACEBOOK_APP_SECRET ở .env của API.
	// ErrKhongPhuTrachApp — người điều hành đang đụng vào một PHẦN MỀM không
	// được giao cho họ (xem migration 0010).
	//
	// Lỗi riêng chứ không gộp vào ErrForbidden: câu trả lời cho người dùng phải
	// nói đúng chuyện gì đang xảy ra ("bạn không phụ trách phần mềm này"), khác
	// hẳn "vai trò của bạn chỉ được xem". Hai thứ đó chữa bằng hai cách khác
	// nhau — một cái là nhờ giao thêm phần mềm, một cái là nhờ nâng vai trò.
	ErrKhongPhuTrachApp = errors.New("không phụ trách phần mềm này")

	// ErrPlatformUnavailable — máy chủ chưa nối được control plane, nên khu điều
	// hành không xác thực được ai cả.
	//
	// Trả lỗi RIÊNG chứ không gộp vào "sai mật khẩu": đây là lỗi cấu hình máy
	// chủ, và người gõ đúng mật khẩu mà bị bảo là sai sẽ đi đổi mật khẩu vòng vo
	// hàng giờ. Cũng KHÔNG được lặng lẽ rơi về cách đăng nhập cũ (mượn
	// super_admin của một cửa hàng) — cách đó chính là lỗ hổng đã đóng.
	ErrPlatformUnavailable = errors.New("khu điều hành nền tảng chưa sẵn sàng")

	ErrFacebookDisabled = errors.New("chưa bật đăng nhập bằng Facebook")
	// Code/token không đổi được hoặc do app khác cấp — coi như không xác minh được ai.
	ErrFacebookAuthFailed = errors.New("không xác minh được tài khoản Facebook")
	// Facebook không trả email (khách đăng ký bằng số điện thoại, hoặc bỏ tick quyền
	// email). Không có email thì không dựng được tài khoản vì email là khoá duy nhất.
	ErrFacebookNoEmail = errors.New("tài khoản Facebook không chia sẻ email")

	// Đăng nhập bằng Google
	// Chưa khai GOOGLE_CLIENT_ID / GOOGLE_CLIENT_SECRET ở .env của API.
	ErrGoogleDisabled = errors.New("chưa bật đăng nhập bằng Google")
	// Code/token không đổi được hoặc do app khác cấp — coi như không xác minh được ai.
	ErrGoogleAuthFailed = errors.New("không xác minh được tài khoản Google")
	// Google không trả email, hoặc email chưa được Google xác minh (tài khoản
	// Workspace tự quản lý tên miền). Không có email ĐÃ XÁC MINH thì không dựng được
	// tài khoản: email là khoá duy nhất, mà ghép theo email chưa xác minh là mở
	// đường chiếm tài khoản người khác.
	ErrGoogleNoEmail = errors.New("tài khoản Google không có email đã xác minh")

	// Chương trình khuyến mãi
	// Ngày kết thúc không sau ngày bắt đầu (hoặc sai định dạng) — đợt sẽ không bao
	// giờ chạy nhưng nhìn danh sách vẫn thấy nó nằm đó như thật.
	ErrPromotionTimeRange = errors.New("thời gian chạy khuyến mãi không hợp lệ")
	// Giảm theo % mà khai quá 100: giá thành số âm.
	ErrPromotionPercentRange = errors.New("phần trăm giảm phải trong khoảng 1–100")
	// Không chọn sản phẩm/danh mục/thương hiệu nào — chương trình không giảm cho ai.
	ErrPromotionNoScope = errors.New("chưa chọn phạm vi áp dụng cho khuyến mãi")

	// Voucher
	// Mã đã có người khác dùng. So sánh KHÔNG phân biệt hoa thường vì mã được chuẩn
	// hoá về chữ hoa trước khi lưu — "SALE10" và "sale10" là cùng một mã.
	ErrVoucherCodeExists = errors.New("mã voucher đã tồn tại")
	// Mã có dấu, có khoảng trắng hoặc ký tự lạ. Khách phải GÕ TAY mã này ở ô thanh
	// toán, nên mọi thứ ngoài A–Z, 0–9, gạch ngang và gạch dưới đều là mã mà một
	// phần khách sẽ không nhập nổi.
	ErrVoucherCodeInvalid = errors.New("mã voucher chỉ gồm chữ không dấu, số, gạch ngang hoặc gạch dưới (3–50 ký tự)")
	// Ngày kết thúc không sau ngày bắt đầu — voucher sẽ không bao giờ dùng được.
	ErrVoucherTimeRange = errors.New("thời gian hiệu lực của voucher không hợp lệ")
	// Giảm theo % mà khai quá 100: đơn thành số âm.
	ErrVoucherPercentRange = errors.New("phần trăm giảm phải trong khoảng 1–100")
	// Hạ tổng lượt dùng xuống thấp hơn số lượt ĐÃ dùng: voucher chết ngay lúc lưu
	// mà người khai không hề biết mình vừa làm gì.
	ErrVoucherLimitBelowUsed = errors.New("tổng lượt dùng không được nhỏ hơn số lượt đã dùng")

	// Khách nhập mã lúc thanh toán. Tách thành từng lỗi riêng thay vì một câu
	// "mã không dùng được": khách cần biết nên bỏ mã đi hay quay lại mua thêm cho
	// đủ đơn tối thiểu — hai việc hoàn toàn khác nhau.
	ErrVoucherNotFound         = errors.New("mã giảm giá không tồn tại")
	ErrVoucherInactive         = errors.New("mã giảm giá đang tạm dừng")
	ErrVoucherNotStarted       = errors.New("mã giảm giá chưa tới ngày sử dụng")
	ErrVoucherExpired          = errors.New("mã giảm giá đã hết hạn")
	ErrVoucherOutOfUses        = errors.New("mã giảm giá đã hết lượt sử dụng")
	ErrVoucherUserLimitReached = errors.New("bạn đã dùng hết số lượt của mã này")
	// Đơn chưa đạt mức tối thiểu. Lỗi này được bọc kèm số tiền còn thiếu để khách
	// biết phải mua thêm bao nhiêu.
	ErrVoucherMinOrder = errors.New("đơn hàng chưa đạt giá trị tối thiểu của mã")

	// Đặt hàng từ storefront
	ErrVariantNotFound = errors.New("sản phẩm không còn bán hoặc đã đổi phiên bản")
	ErrOutOfStock      = errors.New("sản phẩm không đủ hàng")
	ErrEmptyCart       = errors.New("giỏ hàng trống")
	// Hình thức thanh toán bị cửa hàng tắt ở trang Cài đặt, hoặc bật chuyển khoản
	// nhưng chưa khai đủ thông tin tài khoản nhận tiền.
	ErrPaymentMethodDisabled = errors.New("cửa hàng hiện không nhận hình thức thanh toán này")
	// Cổng thanh toán từ chối hoặc không gọi tới được. Đơn VẪN được tạo — chỉ là
	// chưa có link để trả tiền, khách trả sau hoặc đổi hình thức khác.
	ErrPaymentGateway = errors.New("không tạo được link thanh toán")
	// Link thanh toán đã khép lại (khách bấm huỷ, hoặc để quá hạn).
	ErrPaymentLinkClosed = errors.New("link thanh toán không còn hiệu lực")
	// Khách tự huỷ đơn: đơn đã qua giai đoạn được phép tự huỷ (đang chuẩn bị trở đi).
	ErrCancelNotAllowed = errors.New("đơn không còn ở giai đoạn khách tự huỷ được")

	// Trả hàng
	// Đơn chưa giao tới tay khách, hoặc đã quá hạn đổi trả.
	ErrReturnNotAllowed = errors.New("đơn hàng này không nằm trong diện trả hàng")
	// Số lượng trả vượt quá số đã mua trừ đi số đã trả ở các phiếu trước.
	ErrReturnQtyExceeded = errors.New("số lượng trả vượt quá số còn trả được")
	// Không chọn món nào, hoặc mọi món chọn đều số lượng 0.
	ErrReturnEmpty = errors.New("chưa chọn sản phẩm nào để trả")
	// Dòng hàng gửi lên không thuộc đơn đang trả.
	ErrReturnItemNotFound = errors.New("sản phẩm không có trong đơn hàng này")
	// Đơn đang có phiếu trả hàng riêng nên không được hoàn cả đơn bằng luồng đơn hàng.
	ErrReturnInProgress = errors.New("đơn đang có phiếu trả hàng cần xử lý")

	// Đặt hàng nhập
	// Phiếu không có dòng hàng nào, hoặc mọi dòng đều số lượng 0.
	ErrPurchaseEmpty = errors.New("phiếu đặt hàng chưa có sản phẩm nào")
	// Sửa/huỷ/xoá phiếu đã qua giai đoạn cho phép (đã nhận hàng, đã huỷ).
	ErrPurchaseLocked = errors.New("phiếu đặt hàng không còn ở giai đoạn sửa được")
	// Số nhận của một đợt vượt quá phần còn thiếu của dòng hàng.
	ErrPurchaseQtyExceeded = errors.New("số lượng nhận vượt quá số còn lại của phiếu")
	// Đợt nhận hàng không chọn dòng nào, hoặc mọi dòng đều nhận 0.
	ErrPurchaseNothingToReceive = errors.New("chưa chọn sản phẩm nào để nhận")

	// Trả hàng nhập
	// Phiếu trả không có dòng nào, hoặc mọi dòng đều số lượng 0.
	ErrPurchaseReturnEmpty = errors.New("phiếu trả hàng chưa có sản phẩm nào")
	// Sửa/huỷ/xoá phiếu trả đã qua giai đoạn cho phép (đã trả NCC, đã huỷ).
	ErrPurchaseReturnLocked = errors.New("phiếu trả hàng không còn ở giai đoạn sửa được")
	// Trả nhiều hơn số đã nhận (đã trừ phần nằm trong các phiếu trả khác).
	ErrPurchaseReturnQtyExceeded = errors.New("số lượng trả vượt quá số còn trả được của phiếu đặt")
	// Huỷ phiếu trả mà không nhập lý do.
	ErrPurchaseReturnNoReason = errors.New("vui lòng nhập lý do huỷ phiếu trả hàng")

	// Banner trang chủ
	// Vị trí không nằm trong danh sách khối storefront đang dựng được.
	ErrBannerPositionInvalid = errors.New("vị trí banner không hợp lệ")
	// Ngày kết thúc đứng trước ngày bắt đầu — banner sẽ không hiện ngày nào.
	ErrBannerScheduleInvalid = errors.New("lịch chạy banner không hợp lệ")

	// Tồn kho
	// Chỉnh kho không làm tồn thay đổi, hoặc chế độ chỉnh không hợp lệ.
	ErrStockNoChange = errors.New("số lượng chỉnh kho không hợp lệ")
	// Kiểm kê nhập số âm — tồn kho không bao giờ được âm.
	ErrStockNegative = errors.New("tồn kho không được là số âm")
)
