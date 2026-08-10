package service

import (
	"context"
	"strings"
	"testing"
	"time"

	"sass-api/config"
	"sass-api/internal/domain"
	"sass-api/internal/dto"
	"sass-api/pkg/hash"
)

// ---------- đồ giả ----------

// fakeVerifyRepo giữ mã xác thực trong bộ nhớ, tách theo purpose đúng như bảng thật.
type fakeVerifyRepo struct {
	recs   []*domain.EmailVerification
	nextID uint
}

func newFakeVerifyRepo() *fakeVerifyRepo { return &fakeVerifyRepo{nextID: 1} }

func (r *fakeVerifyRepo) Create(_ context.Context, v *domain.EmailVerification) error {
	v.ID = r.nextID
	r.nextID++
	if v.CreatedAt.IsZero() {
		v.CreatedAt = time.Now()
	}
	r.recs = append(r.recs, v)
	return nil
}

func (r *fakeVerifyRepo) Update(_ context.Context, v *domain.EmailVerification) error {
	for i, rec := range r.recs {
		if rec.ID == v.ID {
			r.recs[i] = v
			return nil
		}
	}
	return domain.ErrNotFound
}

// FindLatestActive bắt chước bản thật: mã mới nhất CHƯA dùng của đúng email +
// purpose (kể cả đã hết hạn, để phân biệt "sai mã" với "mã hết hạn").
func (r *fakeVerifyRepo) FindLatestActive(_ context.Context, email, purpose string) (*domain.EmailVerification, error) {
	var found *domain.EmailVerification
	for _, rec := range r.recs {
		if rec.Email != email || rec.Purpose != purpose || rec.VerifiedAt != nil {
			continue
		}
		if found == nil || rec.ID > found.ID {
			found = rec
		}
	}
	if found == nil {
		return nil, domain.ErrNotFound
	}
	return found, nil
}

func (r *fakeVerifyRepo) InvalidateByUser(_ context.Context, userID uint, purpose string) error {
	now := time.Now()
	for _, rec := range r.recs {
		if rec.UserID == userID && rec.Purpose == purpose && rec.VerifiedAt == nil {
			rec.VerifiedAt = &now
		}
	}
	return nil
}

// fakeMailer ghi lại thư đã gửi thay vì mở kết nối SMTP.
type fakeMailer struct {
	subjects []string
	bodies   []string
}

func (m *fakeMailer) Send(_, _, subject, htmlBody, _ string) (string, error) {
	m.subjects = append(m.subjects, subject)
	m.bodies = append(m.bodies, htmlBody)
	return "<test@local>", nil
}

func (m *fakeMailer) Enabled() bool { return true }

// ---------- dựng cảnh ----------

func khachHang(id uint, email, matKhau string) *domain.User {
	h, _ := hash.Hash(matKhau)
	return &domain.User{
		ID:           id,
		Email:        email,
		FullName:     "Nguyễn Văn A",
		PasswordHash: h,
		Status:       "active",
		Role:         &domain.Role{Name: domain.RoleCustomer},
	}
}

func dungAuthService(users *fakeUserRepo, verifies *fakeVerifyRepo, mail *fakeMailer) AuthService {
	return NewAuthService(
		users, fakeRoleRepo{}, verifies, mail,
		nil, // jwt.Manager: chỉ cần khi phát token, các test dưới đây không đụng tới
		config.JWTConfig{},
		config.MailConfig{CodeTTL: 10 * time.Minute, ResendAfter: 60 * time.Second, FromName: "Cửa hàng"},
		false, nil, nil, nil,
	)
}

// docMa lấy mã 6 số vừa phát ra từ tiêu đề thư (bản thật cũng in mã ở đó).
func docMa(t *testing.T, m *fakeMailer) string {
	t.Helper()
	if len(m.subjects) == 0 {
		t.Fatal("chưa gửi thư nào")
	}
	subject := m.subjects[len(m.subjects)-1]
	code := strings.Fields(subject)[0]
	if len(code) != 6 {
		t.Fatalf("tiêu đề không mở đầu bằng mã 6 số: %q", subject)
	}
	return code
}

// ---------- các test ----------

// Email lạ vẫn phải trả về thành công y hệt email có thật, nếu không thì đường
// quên mật khẩu trở thành công cụ dò danh sách khách của cửa hàng.
func TestQuenMatKhauKhongLoEmailCoTonTai(t *testing.T) {
	users := newFakeUserRepo(khachHang(1, "co-that@example.com", "matkhaucu"))
	mail := &fakeMailer{}
	svc := dungAuthService(users, newFakeVerifyRepo(), mail)

	coThat, err := svc.ForgotPassword(context.Background(), dto.ForgotPasswordRequest{Email: "co-that@example.com"})
	if err != nil {
		t.Fatalf("email có thật: %v", err)
	}
	khongCo, err := svc.ForgotPassword(context.Background(), dto.ForgotPasswordRequest{Email: "khong-co@example.com"})
	if err != nil {
		t.Fatalf("email lạ phải trả thành công, nhận: %v", err)
	}

	// So sánh phần KHUÔN của câu trả lời, sau khi bỏ địa chỉ đã che đi: địa chỉ
	// đó lấy từ chính ô người dùng vừa gõ nên có khác nhau cũng không lộ gì.
	// Cái phải giống hệt là mọi thứ còn lại — chỉ cần thừa ra một chữ ở nhánh
	// "email lạ" là đủ để dò xem địa chỉ nào có tài khoản.
	khuon := func(res *dto.RegisterResponse) string {
		return strings.Replace(res.Message, maskEmail(res.Email), "<email>", 1)
	}
	if khuon(coThat) != khuon(khongCo) {
		t.Errorf("hai câu trả lời khác khuôn nên đoán được email nào có tài khoản:\ncó: %q\nlạ: %q",
			coThat.Message, khongCo.Message)
	}
	if !strings.Contains(khuon(coThat), "<email>") {
		t.Errorf("câu trả lời không chứa địa chỉ đã che như mong đợi: %q", coThat.Message)
	}
	if len(mail.subjects) != 1 {
		t.Errorf("chỉ được gửi thư cho email có thật, đã gửi %d thư", len(mail.subjects))
	}
}

// Tài khoản nhân viên KHÔNG được tự đặt lại mật khẩu qua đường công khai:
// chiếm được hộp thư của admin là chiếm được cả cửa hàng.
func TestQuenMatKhauBoQuaTaiKhoanNhanVien(t *testing.T) {
	admin := khachHang(1, "admin@example.com", "matkhaucu")
	admin.Role = &domain.Role{Name: domain.RoleAdmin}
	mail := &fakeMailer{}
	svc := dungAuthService(newFakeUserRepo(admin), newFakeVerifyRepo(), mail)

	if _, err := svc.ForgotPassword(context.Background(), dto.ForgotPasswordRequest{Email: "admin@example.com"}); err != nil {
		t.Fatalf("phải im lặng trả thành công, nhận: %v", err)
	}
	if len(mail.subjects) != 0 {
		t.Errorf("đã gửi mã đặt lại cho tài khoản nhân viên: %v", mail.subjects)
	}
}

func TestDatLaiMatKhauThanhCong(t *testing.T) {
	user := khachHang(1, "khach@example.com", "matkhaucu")
	users := newFakeUserRepo(user)
	mail := &fakeMailer{}
	svc := dungAuthService(users, newFakeVerifyRepo(), mail)

	if _, err := svc.ForgotPassword(context.Background(), dto.ForgotPasswordRequest{Email: "khach@example.com"}); err != nil {
		t.Fatalf("xin mã: %v", err)
	}
	code := docMa(t, mail)

	err := svc.ResetPassword(context.Background(), dto.ResetPasswordRequest{
		Email: "khach@example.com", Code: code, NewPassword: "matkhaumoi",
	})
	if err != nil {
		t.Fatalf("đặt lại: %v", err)
	}

	if !hash.Check("matkhaumoi", users.users[1].PasswordHash) {
		t.Error("mật khẩu mới chưa được ghi")
	}
	if hash.Check("matkhaucu", users.users[1].PasswordHash) {
		t.Error("mật khẩu cũ vẫn dùng được")
	}
}

// Mã chỉ được tiêu đúng một lần: không thì ai đọc lén được thư cũ vẫn đổi mật
// khẩu lại lần nữa sau khi khách đã đặt xong.
func TestMaDatLaiChiDungDuocMotLan(t *testing.T) {
	users := newFakeUserRepo(khachHang(1, "khach@example.com", "matkhaucu"))
	mail := &fakeMailer{}
	svc := dungAuthService(users, newFakeVerifyRepo(), mail)

	_, _ = svc.ForgotPassword(context.Background(), dto.ForgotPasswordRequest{Email: "khach@example.com"})
	code := docMa(t, mail)
	req := dto.ResetPasswordRequest{Email: "khach@example.com", Code: code, NewPassword: "matkhaumoi"}

	if err := svc.ResetPassword(context.Background(), req); err != nil {
		t.Fatalf("lần đầu: %v", err)
	}
	if err := svc.ResetPassword(context.Background(), req); err == nil {
		t.Error("dùng lại mã cũ vẫn đổi được mật khẩu")
	}
}

// Sai mã 5 lần thì khoá, kể cả sau đó có gõ đúng.
func TestSaiMaQuaNhieuLanThiKhoa(t *testing.T) {
	users := newFakeUserRepo(khachHang(1, "khach@example.com", "matkhaucu"))
	mail := &fakeMailer{}
	svc := dungAuthService(users, newFakeVerifyRepo(), mail)

	_, _ = svc.ForgotPassword(context.Background(), dto.ForgotPasswordRequest{Email: "khach@example.com"})
	code := docMa(t, mail)

	sai := "000000"
	if sai == code {
		sai = "111111"
	}
	for i := 0; i < 5; i++ {
		_ = svc.ResetPassword(context.Background(), dto.ResetPasswordRequest{
			Email: "khach@example.com", Code: sai, NewPassword: "matkhaumoi",
		})
	}

	err := svc.ResetPassword(context.Background(), dto.ResetPasswordRequest{
		Email: "khach@example.com", Code: code, NewPassword: "matkhaumoi",
	})
	if err != domain.ErrTooManyAttempts {
		t.Errorf("gõ đúng mã sau 5 lần sai vẫn qua được, lỗi nhận: %v", err)
	}
	if hash.Check("matkhaumoi", users.users[1].PasswordHash) {
		t.Error("mật khẩu đã bị đổi dù mã đang bị khoá")
	}
}

// Mã của luồng ĐĂNG KÝ không được phép dùng để đổi mật khẩu — hai luồng dùng
// chung một bảng, chỉ khác cột purpose.
func TestMaDangKyKhongDoiDuocMatKhau(t *testing.T) {
	users := newFakeUserRepo(khachHang(1, "khach@example.com", "matkhaucu"))
	verifies := newFakeVerifyRepo()
	mail := &fakeMailer{}
	svc := dungAuthService(users, verifies, mail)

	// Dựng sẵn một mã của luồng đăng ký.
	codeHash, _ := hash.Hash("123456")
	_ = verifies.Create(context.Background(), &domain.EmailVerification{
		UserID: 1, Email: "khach@example.com", CodeHash: codeHash,
		Purpose: verifyPurposeRegister, ExpiresAt: time.Now().Add(10 * time.Minute),
	})

	err := svc.ResetPassword(context.Background(), dto.ResetPasswordRequest{
		Email: "khach@example.com", Code: "123456", NewPassword: "matkhaumoi",
	})
	if err != domain.ErrCodeInvalid {
		t.Errorf("mã đăng ký dùng được cho đặt lại mật khẩu, lỗi nhận: %v", err)
	}
}

// Thư đặt lại mật khẩu phải nói đúng việc của nó, không được bê nguyên lời văn
// của thư đăng ký ("Cảm ơn bạn đã đăng ký").
func TestThuDatLaiMatKhauDungLoiVan(t *testing.T) {
	users := newFakeUserRepo(khachHang(1, "khach@example.com", "matkhaucu"))
	mail := &fakeMailer{}
	svc := dungAuthService(users, newFakeVerifyRepo(), mail)

	_, _ = svc.ForgotPassword(context.Background(), dto.ForgotPasswordRequest{Email: "khach@example.com"})

	body := mail.bodies[0]
	if !strings.Contains(body, "Đặt lại mật khẩu") {
		t.Error("thư thiếu nhãn Đặt lại mật khẩu")
	}
	if strings.Contains(body, "Cảm ơn bạn đã đăng ký") {
		t.Error("thư đặt lại mật khẩu đang dùng lời văn của thư đăng ký")
	}
	if !strings.Contains(mail.subjects[0], "mã đặt lại mật khẩu") {
		t.Errorf("tiêu đề chưa nói rõ việc: %q", mail.subjects[0])
	}
}
