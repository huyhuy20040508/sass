package handler

import (
	"sass-api/internal/dto"
	"sass-api/internal/service"
	"sass-api/pkg/response"

	"github.com/gin-gonic/gin"
)

type AuthHandler struct {
	svc service.AuthService
}

func NewAuthHandler(svc service.AuthService) *AuthHandler {
	return &AuthHandler{svc: svc}
}

// Register godoc
//
//	@Summary		Đăng ký khách hàng
//	@Description	Tạo tài khoản khách hàng (trạng thái chờ xác thực) và gửi mã 6 số qua email.
//	@Description	Chưa trả token — cần gọi tiếp /auth/verify-email với mã nhận được trong thư.
//	@Description	Nếu email đã đăng ký nhưng chưa xác thực thì tự gửi lại mã mới.
//	@Tags			Auth
//	@Accept			json
//	@Produce		json
//	@Param			body	body		dto.RegisterRequest	true	"Thông tin đăng ký"
//	@Success		201		{object}	response.Body{data=dto.RegisterResponse}
//	@Failure		409		{object}	response.Body	"Email đã được sử dụng"
//	@Failure		422		{object}	response.Body
//	@Failure		502		{object}	response.Body	"Không gửi được email xác thực"
//	@Router			/auth/register [post]
func (h *AuthHandler) Register(c *gin.Context) {
	var req dto.RegisterRequest
	if !bindJSON(c, &req) {
		return
	}
	res, err := h.svc.Register(c.Request.Context(), req)
	if err != nil {
		handleServiceError(c, err)
		return
	}
	response.Created(c, res)
}

// VerifyEmail godoc
//
//	@Summary		Xác thực email bằng mã
//	@Description	Đối chiếu mã 6 số đã gửi qua email. Đúng mã thì kích hoạt tài khoản và trả về JWT ngay.
//	@Description	Mã hết hạn sau MAIL_CODE_TTL (mặc định 10 phút) và bị khoá sau 5 lần nhập sai.
//	@Tags			Auth
//	@Accept			json
//	@Produce		json
//	@Param			body	body		dto.VerifyEmailRequest	true	"Email và mã xác thực"
//	@Success		200		{object}	response.Body{data=dto.AuthResponse}
//	@Failure		400		{object}	response.Body	"Mã sai / hết hạn / nhập sai quá nhiều lần"
//	@Failure		422		{object}	response.Body
//	@Router			/auth/verify-email [post]
func (h *AuthHandler) VerifyEmail(c *gin.Context) {
	var req dto.VerifyEmailRequest
	if !bindJSON(c, &req) {
		return
	}
	res, err := h.svc.VerifyEmail(c.Request.Context(), req)
	if err != nil {
		handleServiceError(c, err)
		return
	}
	response.OK(c, res)
}

// ResendVerification godoc
//
//	@Summary		Gửi lại mã xác thực
//	@Description	Sinh mã mới và gửi lại qua email. Phải chờ MAIL_RESEND_AFTER (mặc định 60 giây) giữa 2 lần gửi.
//	@Tags			Auth
//	@Accept			json
//	@Produce		json
//	@Param			body	body		dto.ResendCodeRequest	true	"Email cần gửi lại mã"
//	@Success		200		{object}	response.Body{data=dto.RegisterResponse}
//	@Failure		404		{object}	response.Body	"Email chưa đăng ký"
//	@Failure		409		{object}	response.Body	"Email đã xác thực rồi"
//	@Failure		429		{object}	response.Body	"Gửi lại quá sớm"
//	@Failure		502		{object}	response.Body	"Không gửi được email"
//	@Router			/auth/resend-verification [post]
func (h *AuthHandler) ResendVerification(c *gin.Context) {
	var req dto.ResendCodeRequest
	if !bindJSON(c, &req) {
		return
	}
	res, err := h.svc.ResendVerification(c.Request.Context(), req)
	if err != nil {
		handleServiceError(c, err)
		return
	}
	response.OK(c, res)
}

// ForgotPassword godoc
//
//	@Summary		Quên mật khẩu — xin mã đặt lại
//	@Description	Gửi mã 6 số về email để khách tự đặt lại mật khẩu.
//	@Description
//	@Description	LUÔN trả 200 kể cả khi email chưa có tài khoản, hoặc là tài khoản
//	@Description	nhân viên quản trị (nhóm này phải nhờ quản trị viên khác đặt lại).
//	@Description	Báo thẳng "email chưa đăng ký" sẽ biến đường này thành công cụ dò
//	@Description	xem ai là khách của cửa hàng.
//	@Tags			Auth
//	@Accept			json
//	@Produce		json
//	@Param			body	body		dto.ForgotPasswordRequest	true	"Email nhận mã"
//	@Success		200		{object}	response.Body{data=dto.RegisterResponse}
//	@Failure		422		{object}	response.Body
//	@Failure		429		{object}	response.Body	"Xin mã lại quá sớm, hoặc gọi quá dày"
//	@Failure		502		{object}	response.Body	"Không gửi được email"
//	@Router			/auth/forgot-password [post]
func (h *AuthHandler) ForgotPassword(c *gin.Context) {
	var req dto.ForgotPasswordRequest
	if !bindJSON(c, &req) {
		return
	}
	res, err := h.svc.ForgotPassword(c.Request.Context(), req)
	if err != nil {
		handleServiceError(c, err)
		return
	}
	response.OK(c, res)
}

// ResetPassword godoc
//
//	@Summary		Quên mật khẩu — đặt mật khẩu mới
//	@Description	Đối chiếu mã 6 số vừa gửi rồi ghi mật khẩu mới. Sai mã 5 lần thì mã bị khoá, phải xin mã khác.
//	@Description
//	@Description	KHÔNG trả token: đặt lại xong khách đăng nhập bằng mật khẩu mới như bình thường.
//	@Tags			Auth
//	@Accept			json
//	@Produce		json
//	@Param			body	body		dto.ResetPasswordRequest	true	"Email + mã + mật khẩu mới"
//	@Success		200		{object}	response.Body
//	@Failure		400		{object}	response.Body	"Mã sai / hết hạn / nhập sai quá nhiều lần"
//	@Failure		403		{object}	response.Body	"Không phải tài khoản khách hàng"
//	@Failure		422		{object}	response.Body
//	@Failure		429		{object}	response.Body	"Gọi quá dày"
//	@Router			/auth/reset-password [post]
func (h *AuthHandler) ResetPassword(c *gin.Context) {
	var req dto.ResetPasswordRequest
	if !bindJSON(c, &req) {
		return
	}
	if err := h.svc.ResetPassword(c.Request.Context(), req); err != nil {
		handleServiceError(c, err)
		return
	}
	response.OKMessage(c, "Đặt lại mật khẩu thành công. Mời bạn đăng nhập lại.", nil)
}

// Login godoc
//
//	@Summary		Đăng nhập
//	@Description	Đăng nhập bằng email + mật khẩu, trả về access token và refresh token.
//	@Tags			Auth
//	@Accept			json
//	@Produce		json
//	@Param			body	body		dto.LoginRequest	true	"Thông tin đăng nhập"
//	@Success		200		{object}	response.Body
//	@Failure		401		{object}	response.Body
//	@Failure		422		{object}	response.Body
//	@Router			/auth/login [post]
func (h *AuthHandler) Login(c *gin.Context) {
	var req dto.LoginRequest
	if !bindJSON(c, &req) {
		return
	}
	res, err := h.svc.Login(c.Request.Context(), req)
	if err != nil {
		handleServiceError(c, err)
		return
	}
	response.OKMessage(c, "Đăng nhập thành công", res)
}

// ShopLogin godoc
//
//	@Summary		Đăng nhập cửa hàng (3 ô)
//	@Description	Đăng nhập trang quản trị cửa hàng bằng mã cửa hàng + tên đăng nhập + mật khẩu.
//	@Description
//	@Description	`shop_code` là mã KHÁCH HÀNG (bảng tenants), không phải mã chi nhánh.
//	@Description	Sai bất kỳ ô nào cũng chỉ nhận về đúng một câu 401 chung — nói rõ ô nào sai
//	@Description	là biến màn hình đăng nhập thành công cụ dò danh sách cửa hàng và tài khoản.
//	@Description	Tài khoản khách mua sắm không vào được đường này (dùng /auth/login).
//	@Tags			Auth
//	@Accept			json
//	@Produce		json
//	@Param			body	body		dto.ShopLoginRequest	true	"Mã cửa hàng, tên đăng nhập, mật khẩu"
//	@Success		200		{object}	response.Body{data=dto.AuthResponse}
//	@Failure		401		{object}	response.Body	"Sai mã cửa hàng, tên đăng nhập hoặc mật khẩu"
//	@Failure		403		{object}	response.Body	"Cửa hàng đang tạm khoá, hoặc tài khoản bị khoá"
//	@Failure		422		{object}	response.Body
//	@Router			/auth/shop-login [post]
func (h *AuthHandler) ShopLogin(c *gin.Context) {
	var req dto.ShopLoginRequest
	if !bindJSON(c, &req) {
		return
	}
	res, err := h.svc.LoginShop(c.Request.Context(), req)
	if err != nil {
		handleServiceError(c, err)
		return
	}
	response.OKMessage(c, "Đăng nhập thành công", res)
}

// PlatformLogin godoc
//
//	@Summary		Đăng nhập khu điều hành nền tảng
//	@Description	Email + mật khẩu của một tài khoản trong sổ `platform_users` — sổ RIÊNG của
//	@Description	nền tảng, không liên quan tới tài khoản của cửa hàng nào. Tài khoản cửa
//	@Description	hàng (kể cả super_admin của một tiệm) KHÔNG vào được đường này.
//	@Description	Token trả về mang cờ nền tảng và tenant_id = 0, nên nó chỉ mở được nhóm
//	@Description	/platform và không mở được đường nào của khu cửa hàng.
//	@Tags			Auth
//	@Accept			json
//	@Produce		json
//	@Param			body	body		dto.LoginRequest	true	"Email + mật khẩu của người điều hành"
//	@Success		200		{object}	response.Body{data=dto.PlatformAuthResponse}
//	@Failure		401		{object}	response.Body	"Sai email hoặc mật khẩu, hoặc tài khoản chưa đặt mật khẩu"
//	@Failure		422		{object}	response.Body
//	@Failure		503		{object}	response.Body	"Máy chủ chưa nối được sổ nền tảng"
//	@Router			/auth/platform-login [post]
func (h *AuthHandler) PlatformLogin(c *gin.Context) {
	var req dto.LoginRequest
	if !bindJSON(c, &req) {
		return
	}
	res, err := h.svc.LoginPlatform(c.Request.Context(), req)
	if err != nil {
		handleServiceError(c, err)
		return
	}
	response.OKMessage(c, "Đăng nhập thành công", res)
}

// PlatformRefresh godoc
//
//	@Summary		Làm mới token khu điều hành
//	@Description	Đường riêng vì /auth/refresh cố ý TỪ CHỐI mọi token không thuộc cửa hàng
//	@Description	nào, mà token của khu điều hành thì luôn như vậy. Mỗi lượt làm mới đều đọc
//	@Description	lại người điều hành trong sổ: người vừa bị khoá không gia hạn thêm được.
//	@Tags			Auth
//	@Accept			json
//	@Produce		json
//	@Param			body	body		dto.RefreshRequest	true	"Refresh token"
//	@Success		200		{object}	response.Body{data=dto.PlatformAuthResponse}
//	@Failure		401		{object}	response.Body
//	@Failure		503		{object}	response.Body
//	@Router			/auth/platform-refresh [post]
func (h *AuthHandler) PlatformRefresh(c *gin.Context) {
	var req dto.RefreshRequest
	if !bindJSON(c, &req) {
		return
	}
	res, err := h.svc.RefreshPlatform(c.Request.Context(), req.RefreshToken)
	if err != nil {
		handleServiceError(c, err)
		return
	}
	response.OK(c, res)
}

// PlatformMe godoc
//
//	@Summary		Hồ sơ người điều hành đang đăng nhập
//	@Description	Đọc từ sổ `platform_users` theo id trong token nền tảng.
//	@Tags			Auth
//	@Produce		json
//	@Security		BearerAuth
//	@Success		200	{object}	response.Body{data=domain.PlatformUser}
//	@Failure		401	{object}	response.Body
//	@Failure		403	{object}	response.Body
//	@Router			/auth/platform-me [get]
func (h *AuthHandler) PlatformMe(c *gin.Context) {
	res, err := h.svc.MePlatform(c.Request.Context(), currentUserID(c))
	if err != nil {
		handleServiceError(c, err)
		return
	}
	response.OK(c, res)
}

// FacebookLogin godoc
//
//	@Summary		Đăng nhập bằng Facebook
//	@Description	Nhận `code` mà Facebook trả về sau khi khách bấm đồng ý ở hộp thoại đăng nhập,
//	@Description	đổi lấy access token bằng app secret của máy chủ rồi đọc hồ sơ (id, tên, email, ảnh).
//	@Description	Khớp `facebook_id` thì đăng nhập luôn; trùng email thì liên kết vào tài khoản sẵn có;
//	@Description	chưa có gì thì tạo tài khoản khách hàng mới (không mật khẩu, email coi như đã xác thực).
//	@Description	`redirect_uri` phải trùng từng ký tự với cái đã dùng lúc mở hộp thoại.
//	@Tags			Auth
//	@Accept			json
//	@Produce		json
//	@Param			body	body		dto.FacebookLoginRequest	true	"Mã đăng nhập Facebook"
//	@Success		200		{object}	response.Body{data=dto.AuthResponse}
//	@Failure		401		{object}	response.Body	"Không xác minh được tài khoản Facebook"
//	@Failure		403		{object}	response.Body	"Tài khoản đang không hoạt động"
//	@Failure		409		{object}	response.Body	"Email đã được sử dụng"
//	@Failure		422		{object}	response.Body	"Thiếu tham số, hoặc Facebook không chia sẻ email"
//	@Failure		503		{object}	response.Body	"Cửa hàng chưa bật đăng nhập Facebook"
//	@Router			/auth/facebook [post]
func (h *AuthHandler) FacebookLogin(c *gin.Context) {
	var req dto.FacebookLoginRequest
	if !bindJSON(c, &req) {
		return
	}
	res, err := h.svc.LoginWithFacebook(c.Request.Context(), req)
	if err != nil {
		handleServiceError(c, err)
		return
	}
	response.OKMessage(c, "Đăng nhập thành công", res)
}

// GoogleLogin godoc
//
//	@Summary		Đăng nhập bằng Google
//	@Description	Nhận `code` mà Google trả về sau khi khách chọn tài khoản và bấm đồng ý,
//	@Description	đổi lấy token bằng client secret của máy chủ rồi đọc hồ sơ (sub, tên, email, ảnh).
//	@Description	Khớp `google_id` thì đăng nhập luôn; trùng email thì liên kết vào tài khoản sẵn có;
//	@Description	chưa có gì thì tạo tài khoản khách hàng mới (không mật khẩu, email coi như đã xác thực).
//	@Description	`redirect_uri` phải trùng từng ký tự với cái đã dùng lúc mở màn hình chọn tài khoản.
//	@Tags			Auth
//	@Accept			json
//	@Produce		json
//	@Param			body	body		dto.GoogleLoginRequest	true	"Mã đăng nhập Google"
//	@Success		200		{object}	response.Body{data=dto.AuthResponse}
//	@Failure		401		{object}	response.Body	"Không xác minh được tài khoản Google"
//	@Failure		403		{object}	response.Body	"Tài khoản đang không hoạt động"
//	@Failure		409		{object}	response.Body	"Email đã được sử dụng"
//	@Failure		422		{object}	response.Body	"Thiếu tham số, hoặc tài khoản Google không có email đã xác minh"
//	@Failure		503		{object}	response.Body	"Cửa hàng chưa bật đăng nhập Google"
//	@Router			/auth/google [post]
func (h *AuthHandler) GoogleLogin(c *gin.Context) {
	var req dto.GoogleLoginRequest
	if !bindJSON(c, &req) {
		return
	}
	res, err := h.svc.LoginWithGoogle(c.Request.Context(), req)
	if err != nil {
		handleServiceError(c, err)
		return
	}
	response.OKMessage(c, "Đăng nhập thành công", res)
}

// Refresh godoc
//
//	@Summary		Làm mới token
//	@Description	Dùng refresh token để lấy cặp token mới.
//	@Tags			Auth
//	@Accept			json
//	@Produce		json
//	@Param			body	body		dto.RefreshRequest	true	"Refresh token"
//	@Success		200		{object}	response.Body
//	@Failure		401		{object}	response.Body
//	@Router			/auth/refresh [post]
func (h *AuthHandler) Refresh(c *gin.Context) {
	var req dto.RefreshRequest
	if !bindJSON(c, &req) {
		return
	}
	res, err := h.svc.Refresh(c.Request.Context(), req.RefreshToken)
	if err != nil {
		handleServiceError(c, err)
		return
	}
	response.OK(c, res)
}

// Me godoc
//
//	@Summary		Thông tin tài khoản
//	@Description	Lấy thông tin người dùng hiện tại (yêu cầu đăng nhập).
//	@Tags			Auth
//	@Produce		json
//	@Security		BearerAuth
//	@Success		200	{object}	response.Body
//	@Failure		401	{object}	response.Body
//	@Router			/auth/me [get]
func (h *AuthHandler) Me(c *gin.Context) {
	user, err := h.svc.Me(c.Request.Context(), currentUserID(c))
	if err != nil {
		handleServiceError(c, err)
		return
	}
	response.OK(c, user)
}
