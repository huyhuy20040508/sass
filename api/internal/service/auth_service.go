// Package service chứa toàn bộ business logic (tầng nghiệp vụ).
package service

import (
	"context"
	"crypto/rand"
	"fmt"
	"math/big"
	"strings"
	"time"

	"sass-api/config"
	"sass-api/internal/domain"
	"sass-api/internal/dto"
	"sass-api/internal/tenant"
	"sass-api/pkg/facebook"
	"sass-api/pkg/google"
	"sass-api/pkg/hash"
	"sass-api/pkg/jwt"
	"sass-api/pkg/logger"
	"sass-api/pkg/mailer"

	"go.uber.org/zap"
)

const (
	verifyPurposeRegister = "register"
	// verifyPurposeReset — mã 6 số của luồng quên mật khẩu.
	//
	// Dùng CHUNG bảng email_verifications với mã đăng ký nhưng khác `purpose`, nên
	// hai mã sống song song mà không đè nhau: khách đang dở bước xác thực đăng ký
	// rồi bấm quên mật khẩu thì cả hai mã đều còn dùng được. Đổi lại, mọi chỗ đọc
	// mã BẮT BUỘC truyền đúng purpose — tra nhầm là nhận mã của luồng kia và cho
	// đổi mật khẩu bằng mã vốn chỉ dùng để kích hoạt tài khoản.
	verifyPurposeReset = "reset_password"
)

// AuthService định nghĩa nghiệp vụ xác thực.
type AuthService interface {
	Register(ctx context.Context, req dto.RegisterRequest) (*dto.RegisterResponse, error)
	VerifyEmail(ctx context.Context, req dto.VerifyEmailRequest) (*dto.AuthResponse, error)
	ResendVerification(ctx context.Context, req dto.ResendCodeRequest) (*dto.RegisterResponse, error)
	Login(ctx context.Context, req dto.LoginRequest) (*dto.AuthResponse, error)
	// LoginShop là đăng nhập 3 ô của Shop Admin: mã cửa hàng + tên đăng nhập + mật khẩu.
	LoginShop(ctx context.Context, req dto.ShopLoginRequest) (*dto.AuthResponse, error)
	// LoginPlatform là đăng nhập của khu điều hành nền tảng: email + mật khẩu,
	// chỉ nhận super_admin và không cần biết trước cửa hàng nào.
	LoginPlatform(ctx context.Context, req dto.LoginRequest) (*dto.AuthResponse, error)
	ForgotPassword(ctx context.Context, req dto.ForgotPasswordRequest) (*dto.RegisterResponse, error)
	ResetPassword(ctx context.Context, req dto.ResetPasswordRequest) error
	LoginWithFacebook(ctx context.Context, req dto.FacebookLoginRequest) (*dto.AuthResponse, error)
	LoginWithGoogle(ctx context.Context, req dto.GoogleLoginRequest) (*dto.AuthResponse, error)
	Refresh(ctx context.Context, refreshToken string) (*dto.AuthResponse, error)
	Me(ctx context.Context, userID uint) (*domain.User, error)
}

type authService struct {
	users domain.UserRepository
	// tenants tra cửa hàng theo mã người dùng gõ ở ô đầu của màn hình đăng nhập 3 ô.
	tenants  domain.TenantRepository
	roles    domain.RoleRepository
	verifies domain.EmailVerificationRepository
	mail     mailer.Mailer
	jwt      *jwt.Manager
	cfg      config.JWTConfig
	mailCfg  config.MailConfig
	devMode  bool
	// settings cung cấp tên cửa hàng + hotline in trong email mã xác thực.
	// Có thể nil: settingText rơi về mặc định của registry.
	settings SettingService
	// fb là client Facebook Login. Có thể nil (bản dựng không cần đăng nhập mạng
	// xã hội) — LoginWithFacebook trả ErrFacebookDisabled.
	fb *facebook.Client
	// gg là client Google Sign-In. Cũng có thể nil — LoginWithGoogle trả
	// ErrGoogleDisabled.
	gg *google.Client
}

func NewAuthService(
	users domain.UserRepository,
	tenants domain.TenantRepository,
	roles domain.RoleRepository,
	verifies domain.EmailVerificationRepository,
	mail mailer.Mailer,
	jwtMgr *jwt.Manager,
	cfg config.JWTConfig,
	mailCfg config.MailConfig,
	devMode bool,
	settings SettingService,
	fb *facebook.Client,
	gg *google.Client,
) AuthService {
	return &authService{
		users: users, tenants: tenants, roles: roles, verifies: verifies, mail: mail,
		jwt: jwtMgr, cfg: cfg, mailCfg: mailCfg, devMode: devMode,
		// settings TỪNG BỊ BỎ QUÊN ở đây: tham số nhận vào nhưng không gán, mà Go
		// không báo lỗi tham số thừa nên chẳng ai thấy. Hậu quả là mọi email mã
		// xác thực đều in tên cửa hàng lấy từ MAIL_FROM_NAME và KHÔNG có hotline,
		// dù trang Cài đặt đã khai đủ.
		settings: settings,
		fb:       fb, gg: gg,
	}
}

// Register tạo tài khoản ở trạng thái chờ xác thực rồi gửi mã 6 số qua email.
// Chưa trả token — khách phải nhập mã ở bước VerifyEmail.
func (s *authService) Register(ctx context.Context, req dto.RegisterRequest) (*dto.RegisterResponse, error) {
	email := normalizeEmail(req.Email)

	// Email đã đăng ký nhưng chưa xác thực → coi như xin gửi lại mã, không báo trùng.
	if existing, err := s.users.FindByEmail(ctx, email); err == nil && existing != nil {
		if existing.EmailVerifiedAt != nil {
			return nil, domain.ErrEmailExists
		}
		return s.issueCode(ctx, existing, verifyPurposeRegister)
	} else if err != nil && err != domain.ErrNotFound {
		return nil, err
	}

	// Chặn cả email đã xoá mềm (UNIQUE index vẫn giữ chỗ).
	exists, err := s.users.ExistsByEmail(ctx, email)
	if err != nil {
		return nil, err
	}
	if exists {
		return nil, domain.ErrEmailExists
	}

	role, err := s.roles.FindByName(ctx, domain.RoleCustomer)
	if err != nil {
		return nil, err
	}

	hashed, err := hash.Hash(req.Password)
	if err != nil {
		return nil, err
	}

	user := &domain.User{
		RoleID:       role.ID,
		FullName:     strings.TrimSpace(req.FullName),
		Email:        email,
		Phone:        req.Phone,
		PasswordHash: hashed,
		// Chưa xác thực email thì chưa cho đăng nhập
		Status: "inactive",
	}
	if err := s.users.Create(ctx, user); err != nil {
		return nil, err
	}
	user.Role = role

	return s.issueCode(ctx, user, verifyPurposeRegister)
}

// VerifyEmail đối chiếu mã, kích hoạt tài khoản và trả token đăng nhập luôn.
func (s *authService) VerifyEmail(ctx context.Context, req dto.VerifyEmailRequest) (*dto.AuthResponse, error) {
	email := normalizeEmail(req.Email)

	rec, err := s.verifies.FindLatestActive(ctx, email, verifyPurposeRegister)
	if err != nil {
		if err == domain.ErrNotFound {
			return nil, domain.ErrCodeInvalid
		}
		return nil, err
	}
	if rec.Attempts >= 5 {
		return nil, domain.ErrTooManyAttempts
	}
	if time.Now().After(rec.ExpiresAt) {
		return nil, domain.ErrCodeExpired
	}
	if !hash.Check(strings.TrimSpace(req.Code), rec.CodeHash) {
		rec.Attempts++
		_ = s.verifies.Update(ctx, rec)
		if rec.Attempts >= 5 {
			return nil, domain.ErrTooManyAttempts
		}
		return nil, domain.ErrCodeInvalid
	}

	user, err := s.users.FindByID(ctx, rec.UserID)
	if err != nil {
		return nil, err
	}

	now := time.Now()
	rec.VerifiedAt = &now
	if err := s.verifies.Update(ctx, rec); err != nil {
		return nil, err
	}

	user.EmailVerifiedAt = &now
	user.Status = "active"
	user.LastLoginAt = &now
	if err := s.users.Update(ctx, user); err != nil {
		return nil, err
	}

	return s.buildAuthResponse(ctx, user)
}

// ResendVerification gửi lại mã, có chặn spam theo MAIL_RESEND_AFTER.
func (s *authService) ResendVerification(ctx context.Context, req dto.ResendCodeRequest) (*dto.RegisterResponse, error) {
	email := normalizeEmail(req.Email)

	user, err := s.users.FindByEmail(ctx, email)
	if err != nil {
		if err == domain.ErrNotFound {
			return nil, domain.ErrNotFound
		}
		return nil, err
	}
	if user.EmailVerifiedAt != nil {
		return nil, domain.ErrEmailExists
	}

	// Mã vừa gửi cách đây chưa lâu thì bắt chờ
	if last, err := s.verifies.FindLatestActive(ctx, email, verifyPurposeRegister); err == nil {
		if wait := s.mailCfg.ResendAfter - time.Since(last.CreatedAt); wait > 0 {
			return nil, fmt.Errorf("%w: %d giây", domain.ErrResendTooSoon, int(wait.Seconds())+1)
		}
	}

	return s.issueCode(ctx, user, verifyPurposeRegister)
}

// issueCode sinh mã mới, lưu bcrypt và gửi email.
//
// purpose quyết định cả chỗ lưu (cột email_verifications.purpose) lẫn lời văn
// của thư — xem verifyPurposeRegister / verifyPurposeReset.
func (s *authService) issueCode(ctx context.Context, user *domain.User, purpose string) (*dto.RegisterResponse, error) {
	code, err := randomCode()
	if err != nil {
		return nil, err
	}
	codeHash, err := hash.Hash(code)
	if err != nil {
		return nil, err
	}

	// Vô hiệu hoá mã cũ để chỉ còn đúng một mã sống
	_ = s.verifies.InvalidateByUser(ctx, user.ID, purpose)

	ttl := s.mailCfg.CodeTTL
	if ttl <= 0 {
		ttl = 10 * time.Minute
	}
	rec := &domain.EmailVerification{
		UserID:    user.ID,
		Email:     user.Email,
		CodeHash:  codeHash,
		Purpose:   purpose,
		ExpiresAt: time.Now().Add(ttl),
	}
	if err := s.verifies.Create(ctx, rec); err != nil {
		return nil, err
	}

	// Môi trường dev: ghi mã ra log để test không cần mở hộp thư.
	// Production tuyệt đối không ghi (mã OTP là bí mật) — xem cờ devMode trong main.
	if s.devMode {
		logger.Info("mã xác thực (chỉ hiện ở môi trường dev)",
			zap.String("email", user.Email), zap.String("code", code))
	}

	msgID, err := s.sendVerificationMail(ctx, user, code, ttl, purpose)
	if err != nil {
		logger.Error("gửi email xác thực thất bại", zap.String("email", user.Email), zap.Error(err))
		return nil, domain.ErrMailSendFailed
	}
	// Ghi Message-ID để tra lại trong hộp thư khi khách báo "không thấy mail"
	// (Gmail: tìm bằng rfc822msgid:<id>, tìm được cả trong Spam và All Mail).
	logger.Info("đã gửi email xác thực",
		zap.String("email", user.Email), zap.String("message_id", msgID))

	return &dto.RegisterResponse{
		Email:              user.Email,
		RequiresValidation: true,
		ExpiresInSeconds:   int64(ttl.Seconds()),
		ResendAfterSeconds: int64(s.mailCfg.ResendAfter.Seconds()),
		Message:            "Đã gửi mã xác thực tới " + maskEmail(user.Email),
	}, nil
}

// storeName — tên cửa hàng in trong email. Lấy từ cấu hình hệ thống; chưa khai
// thì dùng tên hiển thị của hộp thư gửi đi.
func (s *authService) storeName(ctx context.Context) string {
	if s.settings != nil {
		if v := strings.TrimSpace(s.settings.Get(ctx, SettingSiteName)); v != "" {
			return v
		}
	}
	return s.mailCfg.FromName
}

func (s *authService) sendVerificationMail(ctx context.Context, user *domain.User, code string, ttl time.Duration, purpose string) (string, error) {
	store := s.storeName(ctx)

	data := mailer.VerificationData{
		StoreName: store,
		StoreURL:  s.mailCfg.StoreURL,
		Name:      user.FullName,
		Code:      code,
		Minutes:   int(ttl.Minutes()),
		Hotline:   settingText(ctx, s.settings, SettingContactPhone),
		Year:      time.Now().Year(),
	}
	// Tiêu đề kèm mã giúp khách thấy ngay ở danh sách thư, đỡ phải mở
	subject := fmt.Sprintf("%s là mã xác thực tài khoản %s", code, store)

	if purpose == verifyPurposeReset {
		data.Badge = "Đặt lại mật khẩu"
		data.CodeLabel = "Mã đặt lại mật khẩu"
		data.Intro = "Chúng tôi nhận được yêu cầu đặt lại mật khẩu cho tài khoản " + store +
			" của bạn. Nhập mã bên dưới để chọn mật khẩu mới."
		// Câu dặn dò của thư đặt lại mật khẩu KHÁC hẳn thư đăng ký: người nhận
		// thư này mà không phải mình yêu cầu thì có nghĩa ai đó đang nhắm vào tài
		// khoản của họ, nên phải nói rõ việc cần làm chứ không chỉ "bỏ qua".
		data.Warn = "Không chia sẻ mã này cho bất kỳ ai — kể cả người tự xưng là nhân viên " + store +
			". Nếu không phải bạn yêu cầu, hãy bỏ qua email này; mật khẩu hiện tại vẫn còn nguyên hiệu lực."
		subject = fmt.Sprintf("%s là mã đặt lại mật khẩu %s", code, store)
	}

	html, text, err := mailer.RenderVerification(data)
	if err != nil {
		return "", err
	}
	return s.mail.Send(user.Email, user.FullName, subject, html, text)
}

// ForgotPassword gửi mã 6 số về email để khách tự đặt lại mật khẩu.
//
// KHÔNG BAO GIỜ tiết lộ email có tài khoản hay không: mọi trường hợp đều trả về
// cùng một câu trả lời thành công. Báo thẳng "email này chưa đăng ký" thì trang
// quên mật khẩu thành công cụ dò xem ai là khách của cửa hàng, chỉ cần thử lần
// lượt từng địa chỉ.
//
// CHỈ áp dụng cho tài khoản KHÁCH. Nhân viên quản trị quên mật khẩu thì nhờ một
// quản trị viên khác đặt lại (Người dùng → Đổi mật khẩu): mở đường tự phục hồi
// cho tài khoản admin nghĩa là chiếm được hộp thư của admin là chiếm được cả
// cửa hàng, đổi lấy chút tiện lợi cho vài người không đáng.
func (s *authService) ForgotPassword(ctx context.Context, req dto.ForgotPasswordRequest) (*dto.RegisterResponse, error) {
	email := normalizeEmail(req.Email)

	// Câu trả lời dùng chung cho MỌI nhánh, kể cả nhánh không gửi gì cả.
	ttl := s.mailCfg.CodeTTL
	if ttl <= 0 {
		ttl = 10 * time.Minute
	}
	imLang := &dto.RegisterResponse{
		Email:              email,
		RequiresValidation: true,
		ExpiresInSeconds:   int64(ttl.Seconds()),
		ResendAfterSeconds: int64(s.mailCfg.ResendAfter.Seconds()),
		Message:            "Nếu địa chỉ này có tài khoản, mã đặt lại mật khẩu đã được gửi tới " + maskEmail(email),
	}

	user, err := s.users.FindByEmail(ctx, email)
	if err != nil {
		if err == domain.ErrNotFound {
			return imLang, nil
		}
		return nil, err
	}
	// Tài khoản của nhân viên, hoặc đang bị khoá — im lặng như trường hợp không có.
	if user.Role == nil || user.Role.Name != domain.RoleCustomer || user.Status == "banned" {
		return imLang, nil
	}

	// Mã vừa gửi cách đây chưa lâu thì bắt chờ. Chỗ này CÓ báo lỗi thật vì nó
	// không tiết lộ gì thêm: kẻ dò email chỉ biết chính nó vừa bấm cách đây mấy giây.
	if last, err := s.verifies.FindLatestActive(ctx, email, verifyPurposeReset); err == nil {
		if wait := s.mailCfg.ResendAfter - time.Since(last.CreatedAt); wait > 0 {
			return nil, fmt.Errorf("%w: %d giây", domain.ErrResendTooSoon, int(wait.Seconds())+1)
		}
	}

	res, err := s.issueCode(ctx, user, verifyPurposeReset)
	if err != nil {
		// Gửi thư hỏng thì phải báo thật, nếu không khách ngồi chờ mãi một mã
		// không bao giờ tới.
		return nil, err
	}
	res.Message = imLang.Message

	return res, nil
}

// ResetPassword đối chiếu mã rồi ghi mật khẩu mới.
//
// Nhập đúng mã cũng đồng thời chứng minh khách đang cầm hộp thư đó, nên nhân
// tiện kích hoạt luôn tài khoản chưa xác thực email — bắt họ quay lại làm thêm
// một vòng nhập mã nữa là hành người dùng mà chẳng an toàn thêm chút nào.
func (s *authService) ResetPassword(ctx context.Context, req dto.ResetPasswordRequest) error {
	email := normalizeEmail(req.Email)

	rec, err := s.verifies.FindLatestActive(ctx, email, verifyPurposeReset)
	if err != nil {
		if err == domain.ErrNotFound {
			return domain.ErrCodeInvalid
		}
		return err
	}
	if rec.Attempts >= 5 {
		return domain.ErrTooManyAttempts
	}
	if time.Now().After(rec.ExpiresAt) {
		return domain.ErrCodeExpired
	}
	if !hash.Check(strings.TrimSpace(req.Code), rec.CodeHash) {
		rec.Attempts++
		_ = s.verifies.Update(ctx, rec)
		if rec.Attempts >= 5 {
			return domain.ErrTooManyAttempts
		}
		return domain.ErrCodeInvalid
	}

	user, err := s.users.FindByID(ctx, rec.UserID)
	if err != nil {
		return err
	}
	// Chặn lần nữa ở đây, không chỉ ở ForgotPassword: mã cấp cho khách rồi mà
	// tài khoản bị nâng quyền lên nhân viên trong lúc đó thì mã cũ không được
	// phép dùng để đổi mật khẩu tài khoản quản trị.
	if user.Role == nil || user.Role.Name != domain.RoleCustomer {
		return domain.ErrForbidden
	}

	hashed, err := hash.Hash(req.NewPassword)
	if err != nil {
		return err
	}

	now := time.Now()
	// Tiêu mã TRƯỚC khi đổi mật khẩu: ghi mật khẩu xong mà bước này hỏng thì mã
	// vẫn sống và dùng lại được lần nữa.
	rec.VerifiedAt = &now
	if err := s.verifies.Update(ctx, rec); err != nil {
		return err
	}

	user.PasswordHash = hashed
	if user.EmailVerifiedAt == nil {
		user.EmailVerifiedAt = &now
	}
	if user.Status == "inactive" {
		user.Status = "active"
	}

	return s.users.Update(ctx, user)
}

// randomCode sinh mã 6 chữ số bằng nguồn ngẫu nhiên mật mã.
func randomCode() (string, error) {
	n, err := rand.Int(rand.Reader, big.NewInt(1000000))
	if err != nil {
		return "", err
	}
	return fmt.Sprintf("%06d", n.Int64()), nil
}

func normalizeEmail(s string) string { return strings.ToLower(strings.TrimSpace(s)) }

// maskEmail che bớt địa chỉ khi hiển thị lại cho người dùng: ng***n@gmail.com
func maskEmail(email string) string {
	at := strings.LastIndex(email, "@")
	if at <= 0 {
		return email
	}
	name, domainPart := email[:at], email[at:]
	if len(name) <= 2 {
		return name[:1] + "***" + domainPart
	}
	return name[:2] + "***" + name[len(name)-1:] + domainPart
}

func (s *authService) Login(ctx context.Context, req dto.LoginRequest) (*dto.AuthResponse, error) {
	user, err := s.users.FindByEmail(ctx, normalizeEmail(req.Email))
	if err != nil {
		if err == domain.ErrNotFound {
			return nil, domain.ErrInvalidCredentials
		}
		return nil, err
	}
	if !hash.Check(req.Password, user.PasswordHash) {
		return nil, domain.ErrInvalidCredentials
	}
	// Đúng mật khẩu nhưng chưa xác thực email → báo riêng để giao diện mở bước nhập mã
	if user.EmailVerifiedAt == nil && user.Role != nil && user.Role.Name == domain.RoleCustomer {
		return nil, domain.ErrEmailNotVerified
	}
	if user.Status != "active" {
		return nil, domain.ErrUserInactive
	}

	now := time.Now()
	user.LastLoginAt = &now
	_ = s.users.Update(ctx, user)

	return s.buildAuthResponse(ctx, user)
}

// LoginShop là đăng nhập của Shop Admin: mã cửa hàng + tên đăng nhập + mật khẩu.
//
// VÌ SAO KHÔNG DÙNG EMAIL: nhân viên bán hàng phần lớn không có email, bắt đăng
// nhập bằng email nghĩa là chủ shop phải bịa địa chỉ giả cho từng người. Tên đăng
// nhập chỉ cần duy nhất TRONG MỘT cửa hàng, nên mọi chủ shop đều được làm 'admin'.
//
// THỨ TỰ KIỂM TRA có chủ ý:
//  1. Tra cửa hàng theo mã — KHÔNG xét trạng thái vội.
//  2. Tra tài khoản trong đúng cửa hàng đó, đối chiếu mật khẩu, xét vai trò.
//  3. ĐÚNG MẬT KHẨU RỒI mới báo cửa hàng đang bị khoá.
//
// Bước 3 đặt cuối vì báo "cửa hàng đang tạm khoá" ngay từ ô đầu tiên là trả lời
// miễn phí cho người ngoài hai câu hỏi: mã này có phải khách của mình không, và
// khách đó còn trả tiền không. Đổi lại, nhân viên của cửa hàng bị khoá mà gõ sai
// mật khẩu sẽ thấy câu "sai thông tin đăng nhập" — chấp nhận được, vì gõ đúng
// một lần là biết ngay lý do thật.
func (s *authService) LoginShop(ctx context.Context, req dto.ShopLoginRequest) (*dto.AuthResponse, error) {
	shop, err := s.tenants.FindByCode(ctx, normalizeShopCode(req.ShopCode))
	if err != nil {
		if err == domain.ErrNotFound {
			return nil, domain.ErrShopLoginFailed
		}
		return nil, err
	}

	// Từ đây trở xuống, mọi câu truy vấn của lượt đăng nhập này bị khoá trong
	// đúng cửa hàng vừa tra ra. Đây là chỗ DUY NHẤT trong luồng phục vụ request
	// mà tenant đến từ dữ liệu người dùng gõ vào chứ không từ token — nên nó nằm
	// SAU khi mã cửa hàng đã được đối chiếu với database, và mật khẩu vẫn còn
	// phải kiểm ở dưới.
	ctx = tenant.WithID(ctx, shop.ID)

	user, err := s.users.FindByTenantUsername(ctx, shop.ID, NormalizeUsername(req.Username))
	if err != nil {
		if err == domain.ErrNotFound {
			return nil, domain.ErrShopLoginFailed
		}
		return nil, err
	}
	if !hash.Check(req.Password, user.PasswordHash) {
		return nil, domain.ErrShopLoginFailed
	}
	// Khách mua sắm không có tên đăng nhập nên gần như không lọt tới đây, nhưng
	// chặn tường minh: cửa sau vào trang quản trị mà dựa vào "dữ liệu chắc là NULL"
	// thì chỉ cần một dòng username gán nhầm là mở toang.
	if user.Role == nil || user.Role.Name == domain.RoleCustomer {
		return nil, domain.ErrShopLoginFailed
	}

	if shop.Status != domain.TenantActive {
		return nil, domain.ErrTenantSuspended
	}
	if user.Status != "active" {
		return nil, domain.ErrUserInactive
	}

	now := time.Now()
	user.LastLoginAt = &now
	_ = s.users.Update(ctx, user)

	res, err := s.buildAuthResponse(ctx, user)
	if err != nil {
		return nil, err
	}
	res.Tenant = shop

	return res, nil
}

// LoginPlatform là đăng nhập của KHU ĐIỀU HÀNH NỀN TẢNG (SaaS Console).
//
// VÌ SAO PHẢI CÓ ĐƯỜNG RIÊNG: Login (email) tra người dùng qua repo users, mà
// repo đó bị plugin GORM chèn `WHERE tenant_id` — nên nó chỉ chạy được khi ĐÃ
// biết cửa hàng, từ token hoặc từ tên miền. Người điều hành nền tảng thì không
// đứng ở cửa hàng nào: họ đăng nhập để nhìn tất cả. Ép họ đi đường cũ nghĩa là
// phải cấp cho khu điều hành một tên miền trỏ về một cửa hàng nào đó — dựng một
// lời nói dối ngay tại chốt xác thực, và từ đó mọi thứ phía sau đều lệch.
//
// ĐỔI LẠI, đây là đường DUY NHẤT của luồng đăng nhập tắt bộ lọc tenant, nên nó
// là bề mặt cần canh chừng nhất trong tệp này. Ba ràng buộc giữ nó không thành
// cửa sau:
//
//  1. ĐÚNG MỘT câu truy vấn chạy không lọc — tra tài khoản theo email. Mọi thứ
//     sau đó làm trên bản ghi đã đọc được, không mở thêm câu nào.
//  2. VAI TRÒ XÉT SAU MẬT KHẨU, và cả ba kiểu hỏng (không có email, sai mật
//     khẩu, không phải super_admin) trả về CÙNG một lỗi. Người gõ email chủ shop
//     vào đây không được biết mình vừa gõ trúng một email có thật.
//  3. Token phát ra vẫn mang tenant_id của chính tài khoản đó, không phải một
//     tấm vé "xem được mọi cửa hàng". Ngày mở nhóm /platform/*, quyền xem xuyên
//     cửa hàng phải do nhóm đó tự xét theo vai trò, chứ không phải do token này
//     rộng sẵn.
func (s *authService) LoginPlatform(ctx context.Context, req dto.LoginRequest) (*dto.AuthResponse, error) {
	ctxTim := tenant.WithoutScope(ctx,
		"đăng nhập khu điều hành nền tảng: tra super_admin theo email khi chưa biết cửa hàng nào")

	user, err := s.users.FindByEmail(ctxTim, normalizeEmail(req.Email))
	if err != nil {
		if err == domain.ErrNotFound {
			return nil, domain.ErrInvalidCredentials
		}
		return nil, err
	}
	if !hash.Check(req.Password, user.PasswordHash) {
		return nil, domain.ErrInvalidCredentials
	}
	if user.Role == nil || user.Role.Name != domain.RoleSuperAdmin {
		return nil, domain.ErrInvalidCredentials
	}
	// Trạng thái xét sau cùng, giống LoginShop: câu "tài khoản đang không hoạt
	// động" chỉ dành cho người đã chứng minh được mình là chủ tài khoản.
	if user.Status != "active" {
		return nil, domain.ErrUserInactive
	}

	// Ghi lần đăng nhập cuối vẫn phải đi bằng ctx không lọc: bản ghi vừa đọc ra
	// thuộc cửa hàng nào thì lúc này mới biết, và câu UPDATE của repo tìm theo
	// khoá chính của chính bản ghi đó.
	now := time.Now()
	user.LastLoginAt = &now
	_ = s.users.Update(ctxTim, user)

	return s.buildAuthResponse(ctx, user)
}

// normalizeShopCode chuẩn hoá mã cửa hàng người dùng gõ.
//
// Hạ chữ thường vì mã được cấp bằng chữ thường, mà bàn phím điện thoại thì tự
// viết hoa chữ đầu — không hạ thì nhân viên gõ "Cuahangabc" và bị từ chối mà
// không hiểu vì sao.
func normalizeShopCode(s string) string { return strings.ToLower(strings.TrimSpace(s)) }

// NormalizeUsername chuẩn hoá tên đăng nhập. Cùng lý do với normalizeShopCode,
// và phải dùng CHUNG cho cả lúc đăng nhập lẫn lúc quản trị viên đặt tên — chuẩn
// hoá lệch nhau giữa hai chỗ thì tài khoản tạo ra không đăng nhập được.
func NormalizeUsername(s string) string { return strings.ToLower(strings.TrimSpace(s)) }

// facebookEnabled cho biết đã khai đủ khoá app để đăng nhập Facebook hay chưa.
func (s *authService) facebookEnabled() bool { return s.fb != nil && s.fb.Enabled() }

// googleEnabled cho biết đã khai đủ khoá app để đăng nhập Google hay chưa.
func (s *authService) googleEnabled() bool { return s.gg != nil && s.gg.Enabled() }

// LoginWithFacebook đổi code lấy hồ sơ Facebook rồi vào tài khoản tương ứng.
func (s *authService) LoginWithFacebook(ctx context.Context, req dto.FacebookLoginRequest) (*dto.AuthResponse, error) {
	if !s.facebookEnabled() {
		return nil, domain.ErrFacebookDisabled
	}

	prof, err := s.fb.LoginWithCode(ctx, req.Code, req.RedirectURI)
	if err != nil {
		// Lý do chi tiết chỉ ghi log: nói thẳng ra ngoài là chỉ điểm cho người dò.
		logger.Warn("đăng nhập Facebook thất bại", zap.Error(err))
		return nil, domain.ErrFacebookAuthFailed
	}

	return s.loginSocial(ctx,
		socialProfile{ID: prof.ID, Name: prof.Name, Email: prof.Email, Picture: prof.Picture},
		s.users.FindByFacebookID,
		func(u *domain.User, id string) { u.FacebookID = domain.StringOrNull(id) },
		domain.ErrFacebookNoEmail,
	)
}

// LoginWithGoogle đổi code lấy hồ sơ Google rồi vào tài khoản tương ứng.
func (s *authService) LoginWithGoogle(ctx context.Context, req dto.GoogleLoginRequest) (*dto.AuthResponse, error) {
	if !s.googleEnabled() {
		return nil, domain.ErrGoogleDisabled
	}

	prof, err := s.gg.LoginWithCode(ctx, req.Code, req.RedirectURI)
	if err != nil {
		logger.Warn("đăng nhập Google thất bại", zap.Error(err))
		return nil, domain.ErrGoogleAuthFailed
	}

	email := prof.Email
	// Email Google CHƯA xác minh thì coi như không có. Tài khoản Workspace tự quản
	// lý tên miền khai được email tuỳ ý; ghép tài khoản cửa hàng theo email đó là mở
	// đường cho người khác chiếm tài khoản của khách.
	if !prof.EmailVerified {
		email = ""
	}

	return s.loginSocial(ctx,
		socialProfile{ID: prof.ID, Name: prof.Name, Email: email, Picture: prof.Picture},
		s.users.FindByGoogleID,
		func(u *domain.User, id string) { u.GoogleID = domain.StringOrNull(id) },
		domain.ErrGoogleNoEmail,
	)
}

// socialProfile là hồ sơ ĐÃ XÁC MINH của khách, do client của từng nhà cung cấp
// (Facebook, Google) trả về sau khi tự tay đổi code lấy token.
type socialProfile struct {
	ID      string
	Name    string
	Email   string
	Picture string
}

// loginSocial là phần chung của mọi đường đăng nhập mạng xã hội. Chỉ hai thứ khác
// nhau giữa các nhà cung cấp được truyền vào: cách tra tài khoản theo id của họ
// (findByProviderID) và cách gắn id đó vào tài khoản (linkProviderID).
//
// Ba đường có thể xảy ra:
//  1. Đã liên kết trước đó (khớp facebook_id / google_id) → đăng nhập luôn.
//  2. Email trùng một tài khoản sẵn có → LIÊN KẾT vào tài khoản đó, không tạo tài
//     khoản thứ hai. Email này do nhà cung cấp xác nhận nên coi như đã xác thực,
//     khách đăng ký dở dang bằng email cũng vào thẳng được.
//  3. Chưa có gì → tạo tài khoản khách hàng mới, không mật khẩu (muốn đăng nhập
//     bằng mật khẩu thì dùng chức năng quên mật khẩu để đặt sau).
func (s *authService) loginSocial(
	ctx context.Context,
	prof socialProfile,
	findByProviderID func(context.Context, string) (*domain.User, error),
	linkProviderID func(*domain.User, string),
	errNoEmail error,
) (*dto.AuthResponse, error) {
	user, err := findByProviderID(ctx, prof.ID)
	if err != nil && err != domain.ErrNotFound {
		return nil, err
	}

	now := time.Now()

	if err == domain.ErrNotFound {
		email := normalizeEmail(prof.Email)
		if email == "" {
			return nil, errNoEmail
		}

		existing, findErr := s.users.FindByEmail(ctx, email)
		switch {
		case findErr == nil && existing != nil:
			linkProviderID(existing, prof.ID)
			if existing.Avatar == "" {
				existing.Avatar = prof.Picture
			}
			// Chỉ mở khoá cho tài khoản còn đang chờ xác thực email. Tài khoản bị cửa
			// hàng khoá (đã xác thực nhưng status inactive) thì đăng nhập mạng xã hội
			// không phải đường vòng để vào lại — kiểm tra status ngay bên dưới sẽ chặn.
			if existing.EmailVerifiedAt == nil {
				existing.EmailVerifiedAt = &now
				existing.Status = "active"
			}
			user = existing

		case findErr == domain.ErrNotFound:
			// Email đã bị tài khoản xoá mềm giữ chỗ trong UNIQUE index → tạo mới sẽ
			// lỗi khoá trùng, báo rõ ràng thay vì để lỗi SQL nổi lên.
			exists, existsErr := s.users.ExistsByEmail(ctx, email)
			if existsErr != nil {
				return nil, existsErr
			}
			if exists {
				return nil, domain.ErrEmailExists
			}

			role, roleErr := s.roles.FindByName(ctx, domain.RoleCustomer)
			if roleErr != nil {
				return nil, roleErr
			}

			user = &domain.User{
				RoleID:   role.ID,
				FullName: strings.TrimSpace(prof.Name),
				Email:    email,
				Avatar:   prof.Picture,
				// Không mật khẩu: bcrypt so khớp với chuỗi rỗng luôn trượt nên tài khoản
				// này chỉ vào được bằng chính mạng xã hội vừa dùng.
				PasswordHash:    "",
				Status:          "active",
				EmailVerifiedAt: &now,
			}
			linkProviderID(user, prof.ID)
			if createErr := s.users.Create(ctx, user); createErr != nil {
				return nil, createErr
			}
			user.Role = role

		default:
			return nil, findErr
		}
	}

	if user.Status != "active" {
		return nil, domain.ErrUserInactive
	}

	user.LastLoginAt = &now
	if updErr := s.users.Update(ctx, user); updErr != nil {
		return nil, updErr
	}

	return s.buildAuthResponse(ctx, user)
}

func (s *authService) Refresh(ctx context.Context, refreshToken string) (*dto.AuthResponse, error) {
	claims, err := s.jwt.Parse(refreshToken)
	if err != nil {
		return nil, domain.ErrInvalidCredentials
	}
	if claims.Type != jwt.RefreshToken {
		return nil, domain.ErrInvalidCredentials
	}
	// Refresh token có chữ ký nên mã cửa hàng trong đó cũng không sửa được. Từ
	// chối token cũ chưa mang mã: lùi về một cửa hàng mặc định ở đây là mở đúng
	// cái cửa mà middleware vừa đóng.
	if claims.TenantID == 0 {
		return nil, domain.ErrInvalidCredentials
	}
	// Đường này KHÔNG đi qua middleware xác thực (nó nhận refresh token trong
	// body), nên ctx tới đây chưa có tenant — phải tự gắn trước khi chạm database.
	ctx = tenant.WithID(ctx, claims.TenantID)

	user, err := s.users.FindByID(ctx, claims.UserID)
	if err != nil {
		return nil, err
	}
	if user.Status != "active" {
		return nil, domain.ErrUserInactive
	}
	return s.buildAuthResponse(ctx, user)
}

func (s *authService) Me(ctx context.Context, userID uint) (*domain.User, error) {
	user, err := s.users.FindByID(ctx, userID)
	if err != nil {
		return nil, err
	}
	s.ganNhanVaiTro(ctx, user)

	return user, nil
}

// ganNhanVaiTro đè tên vai trò RIÊNG của cửa hàng lên user sắp trả ra ngoài.
//
// Quan hệ Role nạp kèm user là dòng của bảng dùng chung, tức tên mặc định của
// nhà máy. Cửa hàng đặt tên khác ("Thu ngân" thay cho "Nhân viên") thì tên đó
// nằm ở role_labels — không gắn vào đây là thanh tiêu đề và trang hồ sơ in một
// tên, còn trang Vai trò in tên khác.
//
// Hỏng thì GIỮ TÊN MẶC ĐỊNH chứ không trả lỗi: đây là một chữ trên màn hình,
// không phải lý do để một lượt đăng nhập thất bại.
func (s *authService) ganNhanVaiTro(ctx context.Context, user *domain.User) {
	if user == nil || user.Role == nil {
		return
	}
	role, err := s.roles.FindByID(ctx, user.RoleID)
	if err != nil {
		return
	}
	user.Role.DisplayName = role.DisplayName
	user.Role.Description = role.Description
}

// buildAuthResponse cấp cặp token cho một tài khoản đã xác thực xong.
//
// TenantID lấy từ CHÍNH DÒNG user đọc dưới database, không phải từ tham số nào
// khác: đó là nguồn sự thật về việc tài khoản này thuộc cửa hàng nào, và nó đi
// thẳng vào token để mọi lượt gọi sau đó bị khoá trong đúng cửa hàng ấy. Tài
// khoản không có tenant thì jwt.Generate từ chối — thà không đăng nhập được còn
// hơn phát ra một chiếc token không thuộc về đâu cả.
func (s *authService) buildAuthResponse(ctx context.Context, user *domain.User) (*dto.AuthResponse, error) {
	roleName := ""
	if user.Role != nil {
		roleName = user.Role.Name
	}
	access, _, err := s.jwt.Generate(user.ID, user.TenantID, roleName, jwt.AccessToken)
	if err != nil {
		return nil, err
	}
	refresh, _, err := s.jwt.Generate(user.ID, user.TenantID, roleName, jwt.RefreshToken)
	if err != nil {
		return nil, err
	}
	return &dto.AuthResponse{
		AccessToken:  access,
		RefreshToken: refresh,
		TokenType:    "Bearer",
		ExpiresIn:    int64(s.cfg.AccessTTL.Seconds()),
		User:         user,
	}, nil
}
