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
	// ghép từ tên hàng thì đụng nhau là chuyện thường ngày.
	ErrSKUExists = errors.New("SKU đã tồn tại")

	// Đã ở đầu (hoặc cuối) danh sách nên không đổi chỗ được nữa. Không phải lỗi
	// hệ thống — nói thẳng cho người bấm biết là hết đường đi.
	ErrDaODau  = errors.New("mặt hàng đã ở đầu danh sách")
	ErrDaOCuoi = errors.New("mặt hàng đã ở cuối danh sách")
	// Hướng đổi chỗ ngoài hai giá trị up | down.
	ErrHuongKhongHopLe = errors.New("hướng di chuyển không hợp lệ")

	// Trạng thái sản phẩm ngoài danh sách active | hidden | discontinued.
	ErrProductStatusInvalid = errors.New("trạng thái sản phẩm không hợp lệ")

	// Tổ hợp thuộc tính của biến thể trỏ vào một giá trị không có thật, hoặc vào
	// giá trị của MỘT thuộc tính khác với thuộc tính khai kèm ("Màu" = "128GB").
	ErrBienTheSaiThuocTinh = errors.New("giá trị thuộc tính của biến thể không hợp lệ")

	// Một biến thể mang hai giá trị của CÙNG một thuộc tính ("128GB" và "256GB"
	// cùng lúc) — đó là dữ liệu hỏng chứ không phải một biến thể.
	ErrBienTheTrungThuocTinh = errors.New("một biến thể chỉ được mang một giá trị cho mỗi thuộc tính")

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
	// Khách tự huỷ đơn: đơn đã qua giai đoạn được phép tự huỷ (đang chuẩn bị trở đi).
	ErrCancelNotAllowed = errors.New("đơn không còn ở giai đoạn khách tự huỷ được")

	// Bán tại quầy
	// Tiền mặt khách đưa ít hơn số phải trả. Lỗi này được bọc kèm số còn thiếu để
	// người bán đọc thẳng trên màn hình thay vì tự trừ nhẩm.
	ErrTenderTooLow = errors.New("số tiền khách đưa chưa đủ")
	// Nhân viên bấm mức giảm vượt hạn quyền của mình. Bọc kèm mức tối đa được
	// phép để họ biết phải hạ xuống bao nhiêu, hoặc phải đi gọi ai.
	ErrDiscountTooHigh = errors.New("mức giảm giá vượt quyền của bạn")

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

	// --- Chi nhánh (bảng `shops`) ---

	// ErrMaChiNhanhDaCo — mã này đã có chi nhánh khác của CÙNG cửa hàng dùng.
	//
	// Tính cả chi nhánh đã xoá mềm: mã của chúng vẫn giữ chỗ trong
	// uq_shops_tenant_code, nên "mã còn trống" theo mắt người dùng vẫn có thể là
	// mã ghi xuống không được.
	ErrMaChiNhanhDaCo = errors.New("mã chi nhánh này đã có người dùng")

	// ErrMaChiNhanhInvalid — mã có dấu, có khoảng trắng hoặc ký tự lạ.
	//
	// Mã chi nhánh đi vào chứng từ và (về sau) vào đường dẫn, nên nó phải là thứ
	// gõ được ở mọi bàn phím — cùng luật với tên đăng nhập của nhân viên.
	ErrMaChiNhanhInvalid = errors.New("mã chi nhánh chỉ gồm chữ thường không dấu, số, dấu chấm, gạch ngang hoặc gạch dưới (2–30 ký tự)")

	// ErrChiNhanhCuoiCung — xoá (hoặc tắt) chi nhánh HOẠT ĐỘNG cuối cùng.
	//
	// Mọi bảng giao dịch đều mang `shop_id`: đơn hàng, phiếu nhập, tồn kho đều
	// phát sinh TẠI một chi nhánh. Cửa hàng không còn chi nhánh nào đang hoạt
	// động là cửa hàng không bán được gì nữa, và không có màn hình nào nói ra lý
	// do — nên chặn ngay tại đây, lúc người dùng còn hiểu mình vừa bấm gì.
	ErrChiNhanhCuoiCung = errors.New("phải còn ít nhất một chi nhánh đang hoạt động")

	// ErrVuotHanMuc — cửa hàng đã dùng hết một hạn mức của hợp đồng (xem
	// han_muc.go). err được bọc kèm tên hạn mức và cặp số đang dùng / trần, vì
	// người đọc cần biết mình đang đụng trần nào và trần đó là bao nhiêu — "không
	// tạo được" thì không sửa được gì.
	//
	// KHÔNG phải lỗi của dữ liệu vừa gửi lên: form điền đúng hết, chỉ là gói hết
	// chỗ. Nên câu trả lời phải chỉ sang việc nâng gói, không phải chỉ vào một ô
	// nhập nào cả.
	ErrVuotHanMuc = errors.New("đã đạt hạn mức của gói dịch vụ")

	// Tồn kho
	// Chỉnh kho không làm tồn thay đổi, hoặc chế độ chỉnh không hợp lệ.
	ErrStockNoChange = errors.New("số lượng chỉnh kho không hợp lệ")
	// Kiểm kê nhập số âm — tồn kho không bao giờ được âm.
	ErrStockNegative = errors.New("tồn kho không được là số âm")

	// --- Ký hợp đồng từ khu điều hành ---
	//
	// Nhóm này là bản dịch sang lỗi Go của những câu mà `cmd/thue-bao ky` in ra
	// màn hình. Công cụ dòng lệnh có chỗ để giải thích dài; một endpoint thì
	// không, nên mỗi tình huống phải là một lỗi RIÊNG — người bấm nút cần biết
	// nên sửa ô nào, chứ "không ký được" thì không sửa được gì.

	// ErrCuaHangDaCo — mã cửa hàng đã có người dùng bên data plane.
	//
	// Màn hình "Thêm tài khoản dùng thử" chỉ tạo KHÁCH MỚI, nên trùng mã là dừng
	// hẳn chứ không ghi đè: đằng sau cái mã đó là một cửa hàng đang chạy với dữ
	// liệu thật, và "tạo tài khoản dùng thử" không phải là lý do để đụng vào nó.
	ErrCuaHangDaCo = errors.New("mã cửa hàng này đã có người dùng")

	// ErrMaConTrongSoNenTang — mã cửa hàng còn nằm trong SỔ NỀN TẢNG dưới một id
	// khác, dù bên data plane mã đó đang trống.
	//
	// Nghĩa là hai lược đồ đã lệch nhau: khách cũ mang mã này bị xoá ở khu order
	// mà dòng trong sổ còn nguyên. Ghi tiếp là hỏng THẬT chứ không phải phiền
	// phức — `tenants` bên sổ có UNIQUE(code), nên lượt chép khách mới sẽ đụng
	// khoá đó và MySQL đi CẬP NHẬT DÒNG CŨ (id khác hẳn) rồi báo thành công. Kết
	// quả: khách mới không bao giờ vào sổ, hợp đồng của họ trỏ vào một id không
	// tồn tại, còn hồ sơ của khách cũ thì bị ghi đè tên.
	//
	// Cách gỡ nằm ở khu điều hành chứ không ở màn hình đang gõ: xoá hoặc đổi mã
	// dòng cũ trong sổ, rồi tạo lại.
	ErrMaConTrongSoNenTang = errors.New("mã cửa hàng này còn trong sổ nền tảng dưới một khách khác")

	// ErrHopDongDangChay — khách đã có hợp đồng còn hiệu lực cho phần mềm này.
	//
	// Do khoá uq_subscriptions_current dưới database giữ, không phải do tầng Go
	// tự nhớ. Mỗi khách mỗi phần mềm đúng một hợp đồng còn sống: muốn đổi gói thì
	// huỷ cái cũ trước, muốn dài thêm thì gia hạn.
	ErrHopDongDangChay = errors.New("cửa hàng này đã có hợp đồng còn hiệu lực cho phần mềm đó")

	// ErrChuaBatCongThanhToan — nhà cung cấp chưa bật (hoặc chưa khai đủ khoá)
	// cổng thanh toán, nên khách chưa tự trả tiền được. KHÁC ErrCongThanhToanLoi:
	// bên đó là cổng có thật nhưng vừa hỏng, còn đây là chưa cấu hình bao giờ —
	// hai câu trả lời khác nhau cho người đọc, và hai việc khác nhau để sửa.
	ErrChuaBatCongThanhToan = errors.New("chưa bật cổng thanh toán cho việc gia hạn")
	// ErrCongThanhToanLoi — gọi sang cổng thanh toán không thành công.
	ErrCongThanhToanLoi = errors.New("cổng thanh toán đang không phản hồi")
	// ErrGoiNgungBan — gói đang ở trạng thái 'retired'.
	//
	// Tra ra được nhưng không ký MỚI được: dòng bảng giá không bị xoá vì hợp đồng
	// cũ còn tra tên gói ở đó. Khách cũ dùng tiếp, đó là ý nghĩa của 'retired'.
	ErrGoiNgungBan = errors.New("gói này đã ngừng bán, không ký mới được")

	// ErrAppChuaBan — phần mềm chưa ở trạng thái 'active'.
	ErrAppChuaBan = errors.New("phần mềm này chưa bán được")

	// ErrBangGiaChuaCoGia — dòng bảng giá ghi NULL ("Liên hệ").
	//
	// Không có số để chép sang hợp đồng, và đoán hộ một con số tiền là việc không
	// ai được phép làm. Người ký phải tự khai giá đã thoả thuận.
	ErrBangGiaChuaCoGia = errors.New("bảng giá ghi \"Liên hệ\" cho gói này nên chưa có giá để chép")

	// ErrBangGiaThieuHanMuc — bảng giá không quy định một hạn mức nào đó.
	//
	// Trạng thái thứ ba của PlanFeature ("không có dòng"), và là lý do lỗi này
	// tồn tại: chép 0 sang hợp đồng nghĩa là bán KHÔNG GIỚI HẠN cho một gói mà
	// bảng giá còn chưa nói gì. err kèm tên hạn mức thiếu.
	ErrBangGiaThieuHanMuc = errors.New("bảng giá không quy định hạn mức")

	// ErrDaThuKyNay — sổ thu đã có một dòng cho đúng kỳ này.
	//
	// Do khoá uq_invoices_ky (subscription_id, period_start) giữ. Ghi trùng một
	// kỳ là cách dễ nhất để doanh thu tháng đó phồng gấp đôi mà không ai thấy
	// sai: nhìn vào sổ chỉ thấy hai dòng giống nhau, và không có gì nói dòng nào
	// là dòng thừa.
	ErrDaThuKyNay = errors.New("kỳ này đã có một lần thu ghi trong sổ")

	// ErrKhongConKyDeThu — hợp đồng đã được trả tiền tới đúng hạn hiện tại.
	//
	// Tách khỏi ErrDaThuKyNay vì cách chữa khác hẳn: cái kia là ghi trùng, còn
	// cái này nghĩa là phải GIA HẠN trước rồi mới có kỳ mới để thu.
	ErrKhongConKyDeThu = errors.New("hợp đồng đã trả tiền tới hết hạn hiện tại, chưa có kỳ mới để thu")

	// ErrHopDongDaHuy — thao tác trên một hợp đồng đã huỷ.
	//
	// Gia hạn một hợp đồng đã huỷ sẽ hồi sinh nó và có thể đụng khoá
	// uq_subscriptions_current với hợp đồng đang chạy của cùng khách. Ký lại là
	// một hợp đồng MỚI, không phải là mở lại cái cũ.
	ErrHopDongDaHuy = errors.New("hợp đồng này đã huỷ")
)
