package service

import (
	"context"
	"errors"
	"testing"
	"time"

	"sass-api/config"
	"sass-api/internal/domain"
	"sass-api/internal/dto"
	"sass-api/pkg/hash"
	"sass-api/pkg/jwt"
)

// ---------- dựng cảnh ----------

// nhanVien dựng một tài khoản NỘI BỘ của cửa hàng tenantID.
func nhanVien(id, tenantID uint, username, matKhau, vaiTro string) *domain.User {
	h, _ := hash.Hash(matKhau)
	return &domain.User{
		ID:           id,
		TenantOwned:  domain.TenantOwned{TenantID: tenantID},
		Username:     domain.StringOrNull(username),
		Email:        username + "@shop.local",
		FullName:     "Nhân viên " + username,
		PasswordHash: h,
		Status:       "active",
		Role:         &domain.Role{Name: vaiTro},
	}
}

func cuaHang(id uint, code, status string) *domain.Tenant {
	return &domain.Tenant{ID: id, Code: code, Name: "Cửa hàng " + code, Status: status}
}

// dungAuthServiceDangNhap khác dungAuthService ở chỗ có jwt.Manager thật: đăng
// nhập THÀNH CÔNG thì phải phát được token, không như mấy test quên mật khẩu.
func dungAuthServiceDangNhap(users *fakeUserRepo, tenants *fakeTenantRepo) AuthService {
	return dungAuthServiceDangNhapVoi(users, tenants, nil)
}

// dungAuthServiceDangNhapVoi thêm sổ NGƯỜI ĐIỀU HÀNH NỀN TẢNG.
//
// nil = máy chủ chưa nối control plane, và đó là mặc định của mọi bài kiểm đăng
// nhập cửa hàng: hai luồng không dùng chung một dòng dữ liệu nào, nên bài kiểm
// bên này không được vô tình phụ thuộc vào sổ bên kia.
func dungAuthServiceDangNhapVoi(users *fakeUserRepo, tenants *fakeTenantRepo, nenTang domain.PlatformUserRepository) AuthService {
	return NewAuthService(
		users, nenTang, tenants, fakeRoleRepo{}, newFakeVerifyRepo(), &fakeMailer{},
		jwt.NewManager("bi-mat-test", 15*time.Minute, 24*time.Hour),
		config.JWTConfig{AccessTTL: 15 * time.Minute},
		config.MailConfig{},
		false, nil, nil, nil,
	)
}

// ---------- test ----------

// Đường đi bình thường: gõ đúng ba ô thì vào được, và câu trả lời phải kèm cửa
// hàng — Shop Admin lấy tên ở đó để hiện lên thanh tiêu đề.
func TestDangNhap3O_DungThiVaoDuoc(t *testing.T) {
	users := newFakeUserRepo(nhanVien(10, 1, "admin", "MatKhau@123", domain.RoleAdmin))
	tenants := &fakeTenantRepo{tenants: []*domain.Tenant{cuaHang(1, "cuahang", domain.TenantActive)}}
	svc := dungAuthServiceDangNhap(users, tenants)

	res, err := svc.LoginShop(context.Background(), dto.ShopLoginRequest{
		ShopCode: "cuahang", Username: "admin", Password: "MatKhau@123",
	})
	if err != nil {
		t.Fatalf("đăng nhập đúng mà không vào được: %v", err)
	}
	if res.AccessToken == "" || res.RefreshToken == "" {
		t.Fatal("thiếu token trong câu trả lời")
	}
	if res.Tenant == nil || res.Tenant.Code != "cuahang" {
		t.Fatalf("phải trả kèm cửa hàng vừa đăng nhập, nhận: %+v", res.Tenant)
	}
	if res.User == nil || res.User.LastLoginAt == nil {
		t.Fatal("phải ghi nhận lần đăng nhập gần nhất")
	}
}

// Mã cửa hàng và tên đăng nhập gõ hoa hoặc lẫn khoảng trắng vẫn phải vào được:
// bàn phím điện thoại tự viết hoa chữ đầu, mà nhân viên gõ ba ô này mỗi ca làm.
func TestDangNhap3O_ChuHoaVaKhoangTrangVanVaoDuoc(t *testing.T) {
	users := newFakeUserRepo(nhanVien(10, 1, "admin", "MatKhau@123", domain.RoleAdmin))
	tenants := &fakeTenantRepo{tenants: []*domain.Tenant{cuaHang(1, "cuahang", domain.TenantActive)}}
	svc := dungAuthServiceDangNhap(users, tenants)

	if _, err := svc.LoginShop(context.Background(), dto.ShopLoginRequest{
		ShopCode: "  CuaHang ", Username: " Admin ", Password: "MatKhau@123",
	}); err != nil {
		t.Fatalf("chuẩn hoá hoa/thường hỏng: %v", err)
	}
}

// Sai bất kỳ ô nào cũng chỉ nhận về ĐÚNG MỘT câu lỗi chung. Nói riêng "không có
// cửa hàng này" là trả lời miễn phí cho người ngoài câu hỏi mình có những khách
// hàng nào; nói riêng "sai mật khẩu" là xác nhận tên đăng nhập đó có thật.
func TestDangNhap3O_SaiONaoCungMotCauLoi(t *testing.T) {
	users := newFakeUserRepo(nhanVien(10, 1, "admin", "MatKhau@123", domain.RoleAdmin))
	tenants := &fakeTenantRepo{tenants: []*domain.Tenant{cuaHang(1, "cuahang", domain.TenantActive)}}
	svc := dungAuthServiceDangNhap(users, tenants)

	canhs := map[string]dto.ShopLoginRequest{
		"sai mã cửa hàng":   {ShopCode: "khongcothat", Username: "admin", Password: "MatKhau@123"},
		"sai tên đăng nhập": {ShopCode: "cuahang", Username: "khongcoai", Password: "MatKhau@123"},
		"sai mật khẩu":      {ShopCode: "cuahang", Username: "admin", Password: "sai-bet"},
		"bỏ trống hết":      {},
	}
	for ten, req := range canhs {
		if _, err := svc.LoginShop(context.Background(), req); !errors.Is(err, domain.ErrShopLoginFailed) {
			t.Fatalf("%s: phải trả ErrShopLoginFailed, nhận: %v", ten, err)
		}
	}
}

// Hai cửa hàng cùng có tài khoản 'admin' là chuyện bình thường (khoá unique là
// cặp tenant_id + username). Gõ mật khẩu của cửa hàng này vào mã của cửa hàng
// kia mà lọt thì mọi ranh giới dữ liệu giữa hai khách hàng coi như không có.
func TestDangNhap3O_KhongLotSangCuaHangKhac(t *testing.T) {
	users := newFakeUserRepo(
		nhanVien(10, 1, "admin", "MatKhauShop1", domain.RoleAdmin),
		nhanVien(20, 2, "admin", "MatKhauShop2", domain.RoleAdmin),
	)
	tenants := &fakeTenantRepo{tenants: []*domain.Tenant{
		cuaHang(1, "shopmot", domain.TenantActive),
		cuaHang(2, "shophai", domain.TenantActive),
	}}
	svc := dungAuthServiceDangNhap(users, tenants)

	if _, err := svc.LoginShop(context.Background(), dto.ShopLoginRequest{
		ShopCode: "shophai", Username: "admin", Password: "MatKhauShop1",
	}); !errors.Is(err, domain.ErrShopLoginFailed) {
		t.Fatalf("mật khẩu của shop 1 không được mở shop 2, nhận: %v", err)
	}

	res, err := svc.LoginShop(context.Background(), dto.ShopLoginRequest{
		ShopCode: "shophai", Username: "admin", Password: "MatKhauShop2",
	})
	if err != nil {
		t.Fatalf("đúng cặp shop 2 mà không vào được: %v", err)
	}
	if res.User.ID != 20 {
		t.Fatalf("vào nhầm tài khoản: nhận id %d, phải là 20", res.User.ID)
	}
}

// Cửa hàng bị khoá (hết hạn / ngừng trả tiền) thì không vào được — nhưng chỉ nói
// lý do thật SAU KHI đã gõ đúng mật khẩu. Người ngoài dò mã cửa hàng chỉ nhận
// được câu lỗi chung, không biết mã đó có thật hay khách đó còn trả tiền không.
func TestDangNhap3O_CuaHangBiKhoa(t *testing.T) {
	users := newFakeUserRepo(nhanVien(10, 1, "admin", "MatKhau@123", domain.RoleAdmin))
	tenants := &fakeTenantRepo{tenants: []*domain.Tenant{cuaHang(1, "cuahang", "suspended")}}
	svc := dungAuthServiceDangNhap(users, tenants)

	if _, err := svc.LoginShop(context.Background(), dto.ShopLoginRequest{
		ShopCode: "cuahang", Username: "admin", Password: "MatKhau@123",
	}); !errors.Is(err, domain.ErrTenantSuspended) {
		t.Fatalf("cửa hàng bị khoá phải trả ErrTenantSuspended, nhận: %v", err)
	}

	if _, err := svc.LoginShop(context.Background(), dto.ShopLoginRequest{
		ShopCode: "cuahang", Username: "admin", Password: "sai-bet",
	}); !errors.Is(err, domain.ErrShopLoginFailed) {
		t.Fatalf("sai mật khẩu thì chưa được lộ chuyện cửa hàng bị khoá, nhận: %v", err)
	}
}

// Khách mua sắm không có tên đăng nhập, nhưng nếu một dòng username bị gán nhầm
// cho tài khoản vai trò customer thì đường này KHÔNG được biến thành cửa sau vào
// trang quản trị.
func TestDangNhap3O_KhachHangKhongVaoDuoc(t *testing.T) {
	khach := nhanVien(30, 1, "khachle", "MatKhau@123", domain.RoleCustomer)
	users := newFakeUserRepo(khach)
	tenants := &fakeTenantRepo{tenants: []*domain.Tenant{cuaHang(1, "cuahang", domain.TenantActive)}}
	svc := dungAuthServiceDangNhap(users, tenants)

	if _, err := svc.LoginShop(context.Background(), dto.ShopLoginRequest{
		ShopCode: "cuahang", Username: "khachle", Password: "MatKhau@123",
	}); !errors.Is(err, domain.ErrShopLoginFailed) {
		t.Fatalf("tài khoản khách hàng không được vào Shop Admin, nhận: %v", err)
	}
}

// Tài khoản bị cửa hàng khoá thì báo đúng lý do (khác hẳn "sai mật khẩu"): người
// bị khoá cần biết phải đi hỏi quản lý chứ không phải ngồi thử lại mật khẩu.
func TestDangNhap3O_TaiKhoanBiKhoa(t *testing.T) {
	u := nhanVien(10, 1, "nhanvien", "MatKhau@123", domain.RoleStaff)
	u.Status = "inactive"
	users := newFakeUserRepo(u)
	tenants := &fakeTenantRepo{tenants: []*domain.Tenant{cuaHang(1, "cuahang", domain.TenantActive)}}
	svc := dungAuthServiceDangNhap(users, tenants)

	if _, err := svc.LoginShop(context.Background(), dto.ShopLoginRequest{
		ShopCode: "cuahang", Username: "nhanvien", Password: "MatKhau@123",
	}); !errors.Is(err, domain.ErrUserInactive) {
		t.Fatalf("tài khoản bị khoá phải trả ErrUserInactive, nhận: %v", err)
	}
}
