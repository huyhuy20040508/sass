package service

import (
	"context"
	"errors"
	"strings"
	"testing"

	"sass-api/internal/domain"
	"sass-api/internal/dto"
	"sass-api/pkg/hash"
	"sass-api/pkg/jwt"
)

// Bộ kiểm ĐĂNG NHẬP KHU ĐIỀU HÀNH sau khi nó có sổ của riêng mình.
//
// Bản trước của luồng này tra `selliotech.users` rồi đòi vai trò `super_admin`.
// Vai trò đó là vai trò cao nhất TRONG MỘT CỬA HÀNG, mà tiệm nào cũng có một
// người như vậy — chủ shop. Nghĩa là chìa vào khu điều hành nằm trong bảng tài
// khoản của khách hàng, và ai đổi được mật khẩu trong một tiệm bất kỳ cũng đổi
// được chìa.
//
// Nay luồng này KHÔNG chạm data plane. Bài kiểm quan trọng nhất của tệp vì thế
// là TestDangNhapNenTang_TaiKhoanCuaHangKhongVaoDuoc: một tài khoản cửa hàng,
// dù mang vai trò gì, cũng không có đường nào vào đây nữa.

// ---------- dựng cảnh ----------

// fakeNguoiDieuHanh là sổ người điều hành trong bộ nhớ.
//
// Chỉ trả về dòng ĐANG HOẠT ĐỘNG và CHƯA XOÁ, đúng như hiện thực thật (điều
// kiện nằm trong câu truy vấn). Bài kiểm nào muốn thử người bị khoá thì đặt
// Status = "locked" và mong đợi "không tìm thấy".
type fakeNguoiDieuHanh struct {
	rows []*domain.PlatformUser
	// ghiDangNhap đếm số lượt GhiLanDangNhap chạy được.
	ghiDangNhap int
	// loi khác nil = giả lập database trục trặc.
	loi error
}

func (f *fakeNguoiDieuHanh) timTheo(dieuKien func(*domain.PlatformUser) bool) (*domain.PlatformUser, error) {
	if f.loi != nil {
		return nil, f.loi
	}
	for _, r := range f.rows {
		if r.Status != "active" || r.DeletedAt != nil {
			continue
		}
		if dieuKien(r) {
			return r, nil
		}
	}

	return nil, domain.ErrNotFound
}

func (f *fakeNguoiDieuHanh) FindByEmail(_ context.Context, email string) (*domain.PlatformUser, error) {
	email = strings.ToLower(strings.TrimSpace(email))

	return f.timTheo(func(r *domain.PlatformUser) bool { return r.Email == email })
}

func (f *fakeNguoiDieuHanh) FindByID(_ context.Context, id uint) (*domain.PlatformUser, error) {
	return f.timTheo(func(r *domain.PlatformUser) bool { return r.ID == id })
}

func (f *fakeNguoiDieuHanh) GhiLanDangNhap(_ context.Context, _ uint) error {
	f.ghiDangNhap++

	return nil
}

// QuyenApp: bộ kiểm đăng nhập không đụng tới phân công phần mềm, nhưng phải
// hiện thực đủ port. Trả về đúng luật thật (owner = toàn quyền) để không ai đọc
// nhầm rằng đăng nhập cấp sẵn quyền gì.
func (f *fakeNguoiDieuHanh) QuyenApp(_ context.Context, nguoi *domain.PlatformUser) (domain.QuyenApp, error) {
	if nguoi != nil && nguoi.Role == domain.PlatformRoleOwner {
		return domain.QuyenApp{ToanQuyen: true}, nil
	}

	return domain.QuyenApp{}, nil
}

// nguoiNenTang dựng một dòng sổ điều hành đã đặt mật khẩu.
func nguoiNenTang(id uint, email, matKhau, vaiTro string) *domain.PlatformUser {
	h, _ := hash.Hash(matKhau)

	return &domain.PlatformUser{
		ID:           id,
		Email:        email,
		FullName:     "Người điều hành " + email,
		PasswordHash: &h,
		Role:         vaiTro,
		Status:       "active",
	}
}

// dungAuthNenTang dựng service với sổ điều hành cho trước.
//
// Sổ tài khoản cửa hàng để RỖNG ở phần lớn bài kiểm: luồng này không được phép
// chạm tới đó, và một sổ rỗng làm điều đó thành sự thật kiểm được chứ không chỉ
// là lời hứa trong chú thích.
func dungAuthNenTang(nenTang domain.PlatformUserRepository) AuthService {
	return dungAuthServiceDangNhapVoi(newFakeUserRepo(), &fakeTenantRepo{}, nenTang)
}

// ---------- test ----------

// Đường đi bình thường, và kiểm luôn HÌNH DẠNG CỦA TOKEN — phần dễ bỏ sót nhất:
// token nền tảng phải mang cờ riêng và KHÔNG thuộc cửa hàng nào. Thiếu một
// trong hai thì hoặc nó mở được cửa khu bán hàng, hoặc nó không mở được cửa
// chính nó.
func TestDangNhapNenTang_VaoDuocVaTokenDungHinhDang(t *testing.T) {
	so := &fakeNguoiDieuHanh{rows: []*domain.PlatformUser{
		nguoiNenTang(7, "sep@nentang.test", "MatKhau@123", domain.PlatformRoleOwner),
	}}
	svc := dungAuthNenTang(so)

	res, err := svc.LoginPlatform(context.Background(), dto.LoginRequest{
		Email: "sep@nentang.test", Password: "MatKhau@123",
	})
	if err != nil {
		t.Fatalf("người điều hành gõ đúng mà không vào được: %v", err)
	}
	if res.AccessToken == "" || res.RefreshToken == "" {
		t.Fatalf("đăng nhập xong mà thiếu token: %+v", res)
	}
	if res.User == nil || res.User.ID != 7 || res.User.Role != domain.PlatformRoleOwner {
		t.Fatalf("trả về sai người: %+v", res.User)
	}
	if so.ghiDangNhap != 1 {
		t.Fatalf("phải ghi lần đăng nhập cuối đúng một lượt, đếm được %d", so.ghiDangNhap)
	}

	mgr := jwt.NewManager("bi-mat-test", 0, 0)
	claims, err := mgr.Parse(res.AccessToken)
	if err != nil {
		t.Fatalf("token vừa cấp mà không đọc lại được: %v", err)
	}
	if !claims.Platform {
		t.Fatal("token thiếu cờ nền tảng — khu điều hành sẽ từ chối chính token của mình")
	}
	if claims.TenantID != 0 {
		t.Fatalf("token nền tảng mang tenant_id = %d; phải là 0, nếu không nó mở được cả khu cửa hàng",
			claims.TenantID)
	}
	if claims.UserID != 7 {
		t.Fatalf("token trỏ vào người khác: %d", claims.UserID)
	}
}

// TÀI KHOẢN CỬA HÀNG KHÔNG CÒN ĐƯỜNG NÀO VÀO — bài kiểm quan trọng nhất tệp này.
//
// Sổ tài khoản cửa hàng ở đây có đủ một super_admin đúng email đúng mật khẩu.
// Trước đợt sửa, chính người này vào được khu điều hành. Nay sổ điều hành không
// có tên anh ta, và đó là câu trả lời duy nhất cần thiết.
func TestDangNhapNenTang_TaiKhoanCuaHangKhongVaoDuoc(t *testing.T) {
	users := newFakeUserRepo(nhanVien(10, 1, "chushop", "MatKhau@123", domain.RoleSuperAdmin))
	so := &fakeNguoiDieuHanh{} // sổ điều hành TRỐNG
	svc := dungAuthServiceDangNhapVoi(users, &fakeTenantRepo{}, so)

	_, err := svc.LoginPlatform(context.Background(), dto.LoginRequest{
		Email: "chushop@shop.local", Password: "MatKhau@123",
	})
	if !errors.Is(err, domain.ErrInvalidCredentials) {
		t.Fatalf("super_admin của một cửa hàng phải bị từ chối, nhận: %v", err)
	}
}

// Email gõ hoa hoặc dính dấu cách vẫn vào được: bàn phím điện thoại tự viết hoa
// chữ đầu, và người ta hay quét chọn email rồi dán kèm dấu cách ở cuối.
func TestDangNhapNenTang_EmailHoaVaThuaDauCachVanVaoDuoc(t *testing.T) {
	so := &fakeNguoiDieuHanh{rows: []*domain.PlatformUser{
		nguoiNenTang(1, "sep@nentang.test", "MatKhau@123", domain.PlatformRoleOperator),
	}}

	if _, err := dungAuthNenTang(so).LoginPlatform(context.Background(), dto.LoginRequest{
		Email: "  Sep@NenTang.test ", Password: "MatKhau@123",
	}); err != nil {
		t.Fatalf("email hoa/thừa dấu cách phải vào được: %v", err)
	}
}

// CHƯA ĐẶT MẬT KHẨU LÀ CHƯA VÀO ĐƯỢC, không phải bỏ qua bước mật khẩu.
//
// Trạng thái này có thật và xảy ra thường: `cmd/nguoi-dieu-hanh them` tạo được
// dòng trước khi ai đó đặt mật khẩu. Nếu chỗ so mật khẩu quên nhánh nil thì mọi
// dòng vừa thêm vào sổ đều mở toang, và mở toang một cách im lặng.
func TestDangNhapNenTang_ChuaDatMatKhauThiChan(t *testing.T) {
	nguoi := nguoiNenTang(1, "moi@nentang.test", "MatKhau@123", domain.PlatformRoleOperator)
	nguoi.PasswordHash = nil
	so := &fakeNguoiDieuHanh{rows: []*domain.PlatformUser{nguoi}}

	for _, mk := range []string{"MatKhau@123", "", "bat-ky-thu-gi"} {
		if _, err := dungAuthNenTang(so).LoginPlatform(context.Background(), dto.LoginRequest{
			Email: "moi@nentang.test", Password: mk,
		}); !errors.Is(err, domain.ErrInvalidCredentials) {
			t.Fatalf("tài khoản chưa đặt mật khẩu phải bị chặn (thử %q), nhận: %v", mk, err)
		}
	}
}

// MỌI KIỂU HỎNG TRẢ CÙNG MỘT LỖI: nếu "không có email này" khác với "có nhưng
// sai mật khẩu" thì màn hình đăng nhập thành máy dò — gõ lần lượt email của
// khách vào là biết ai là người điều hành, rồi nhắm mật khẩu vào đúng người đó.
func TestDangNhapNenTang_MoiKieuHongTraCungMotLoi(t *testing.T) {
	biKhoa := nguoiNenTang(2, "nghi@nentang.test", "MatKhau@123", domain.PlatformRoleSupport)
	biKhoa.Status = "locked"
	so := &fakeNguoiDieuHanh{rows: []*domain.PlatformUser{
		nguoiNenTang(1, "sep@nentang.test", "MatKhau@123", domain.PlatformRoleOwner),
		biKhoa,
	}}
	svc := dungAuthNenTang(so)

	canh := []struct{ ten, email, mk string }{
		{"email không tồn tại", "khongco@nentang.test", "MatKhau@123"},
		{"đúng email nhưng sai mật khẩu", "sep@nentang.test", "SaiMatKhau@1"},
		{"tài khoản bị khoá, mật khẩu đúng", "nghi@nentang.test", "MatKhau@123"},
	}
	for _, c := range canh {
		t.Run(c.ten, func(t *testing.T) {
			if _, err := svc.LoginPlatform(context.Background(),
				dto.LoginRequest{Email: c.email, Password: c.mk}); !errors.Is(err, domain.ErrInvalidCredentials) {
				t.Fatalf("phải là ErrInvalidCredentials để không lộ gì, nhận: %v", err)
			}
		})
	}
}

// Chưa dựng control plane thì nói THẲNG là chưa sẵn sàng.
//
// Không gộp vào "sai mật khẩu": đây là lỗi cấu hình máy chủ, và người gõ đúng
// mật khẩu mà bị bảo là sai sẽ đi đổi mật khẩu vòng vo hàng giờ. Càng không
// được lặng lẽ rơi về cách cũ — cách cũ chính là lỗ hổng.
func TestDangNhapNenTang_ChuaDungControlPlane(t *testing.T) {
	svc := dungAuthNenTang(nil)

	if _, err := svc.LoginPlatform(context.Background(), dto.LoginRequest{
		Email: "sep@nentang.test", Password: "MatKhau@123",
	}); !errors.Is(err, domain.ErrPlatformUnavailable) {
		t.Fatalf("phải báo ErrPlatformUnavailable, nhận: %v", err)
	}
}

// Làm mới token: chỉ refresh token NỀN TẢNG mới đổi được, và người vừa bị gạch
// khỏi sổ thì không gia hạn thêm phiên nào nữa.
func TestLamMoiTokenNenTang(t *testing.T) {
	nguoi := nguoiNenTang(5, "sep@nentang.test", "MatKhau@123", domain.PlatformRoleOwner)
	so := &fakeNguoiDieuHanh{rows: []*domain.PlatformUser{nguoi}}
	svc := dungAuthNenTang(so)

	dau, err := svc.LoginPlatform(context.Background(), dto.LoginRequest{
		Email: "sep@nentang.test", Password: "MatKhau@123",
	})
	if err != nil {
		t.Fatalf("đăng nhập hỏng: %v", err)
	}

	moi, err := svc.RefreshPlatform(context.Background(), dau.RefreshToken)
	if err != nil {
		t.Fatalf("làm mới bằng refresh token hợp lệ mà hỏng: %v", err)
	}
	if moi.AccessToken == "" {
		t.Fatal("làm mới xong không có access token")
	}

	// Access token KHÔNG được dùng thay refresh token: nó sống ngắn hơn hẳn và
	// nằm ở nhiều chỗ hơn (header của mọi request), nên đổi được nó thành phiên
	// mới là xoá luôn ý nghĩa của việc chia hai loại.
	if _, err := svc.RefreshPlatform(context.Background(), dau.AccessToken); !errors.Is(err, domain.ErrInvalidCredentials) {
		t.Fatalf("access token không được làm mới phiên, nhận: %v", err)
	}

	// Token của một CỬA HÀNG cũng không được: nó không mang cờ nền tảng.
	mgr := jwt.NewManager("bi-mat-test", 0, 0)
	tokenShop, _, err := mgr.Generate(1, 1, domain.RoleSuperAdmin, jwt.RefreshToken)
	if err != nil {
		t.Fatalf("không dựng được token cửa hàng để thử: %v", err)
	}
	if _, err := svc.RefreshPlatform(context.Background(), tokenShop); !errors.Is(err, domain.ErrInvalidCredentials) {
		t.Fatalf("refresh token của cửa hàng phải bị từ chối, nhận: %v", err)
	}

	// Bị khoá giữa chừng: token cũ còn hạn nhưng không gia hạn được nữa.
	nguoi.Status = "locked"
	if _, err := svc.RefreshPlatform(context.Background(), dau.RefreshToken); !errors.Is(err, domain.ErrInvalidCredentials) {
		t.Fatalf("người bị khoá vẫn làm mới được phiên, nhận: %v", err)
	}
}
