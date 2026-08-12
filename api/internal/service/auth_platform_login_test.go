package service

import (
	"context"
	"errors"
	"testing"

	"sass-api/internal/domain"
	"sass-api/internal/dto"
	"sass-api/pkg/hash"
)

// ---------- dựng cảnh ----------

// nguoiDieuHanh dựng một tài khoản đăng nhập bằng EMAIL, vai trò tuỳ chọn.
//
// Nhận cả vai trò chứ không cố định super_admin vì phần lớn bài kiểm dưới đây
// làm đúng một việc: đưa vào đây một người KHÔNG phải super_admin rồi xem cửa có
// mở không.
func nguoiDieuHanh(id, tenantID uint, email, matKhau, vaiTro string) *domain.User {
	h, _ := hash.Hash(matKhau)

	return &domain.User{
		ID:           id,
		TenantOwned:  domain.TenantOwned{TenantID: tenantID},
		Email:        email,
		FullName:     "Người dùng " + email,
		PasswordHash: h,
		Status:       "active",
		Role:         &domain.Role{Name: vaiTro},
	}
}

// dungAuthNenTang dựng service cho các bài kiểm đăng nhập nền tảng. Không cần
// bảng cửa hàng: luồng này cố ý không tra cửa hàng nào cả.
func dungAuthNenTang(users *fakeUserRepo) AuthService {
	return dungAuthServiceDangNhap(users, &fakeTenantRepo{})
}

// ---------- test ----------

// Đường đi bình thường: super_admin gõ đúng email + mật khẩu thì vào được, kể cả
// khi ctx KHÔNG mang cửa hàng nào — đó chính là điều đường này sinh ra để làm.
func TestDangNhapNenTang_SuperAdminVaoDuoc(t *testing.T) {
	users := newFakeUserRepo(nguoiDieuHanh(1, 1, "sep@nentang.test", "MatKhau@123", domain.RoleSuperAdmin))
	svc := dungAuthNenTang(users)

	res, err := svc.LoginPlatform(context.Background(), dto.LoginRequest{
		Email: "sep@nentang.test", Password: "MatKhau@123",
	})
	if err != nil {
		t.Fatalf("super_admin gõ đúng mà không vào được: %v", err)
	}
	if res.AccessToken == "" || res.RefreshToken == "" {
		t.Fatalf("đăng nhập xong mà thiếu token: %+v", res)
	}
	if res.User == nil || res.User.ID != 1 {
		t.Fatalf("trả về sai người: %+v", res.User)
	}
}

// Email gõ hoa hoặc dính dấu cách vẫn vào được: bàn phím điện thoại tự viết hoa
// chữ đầu, và người ta hay quét chọn email rồi dán kèm dấu cách ở cuối.
func TestDangNhapNenTang_EmailHoaVaThuaDauCachVanVaoDuoc(t *testing.T) {
	users := newFakeUserRepo(nguoiDieuHanh(1, 1, "sep@nentang.test", "MatKhau@123", domain.RoleSuperAdmin))
	svc := dungAuthNenTang(users)

	if _, err := svc.LoginPlatform(context.Background(), dto.LoginRequest{
		Email: "  Sep@NenTang.test ", Password: "MatKhau@123",
	}); err != nil {
		t.Fatalf("email hoa/thừa dấu cách phải vào được: %v", err)
	}
}

// Chủ cửa hàng, nhân viên và khách mua sắm ĐỀU không vào được — họ có phần mềm
// của riêng mình (Shop Admin, trang bán hàng), khu điều hành nền tảng không phải
// chỗ của họ.
func TestDangNhapNenTang_KhongPhaiSuperAdminThiChan(t *testing.T) {
	for _, vaiTro := range []string{domain.RoleAdmin, domain.RoleStaff, domain.RoleCustomer} {
		t.Run(vaiTro, func(t *testing.T) {
			users := newFakeUserRepo(nguoiDieuHanh(1, 1, "ai@shop.test", "MatKhau@123", vaiTro))
			svc := dungAuthNenTang(users)

			_, err := svc.LoginPlatform(context.Background(), dto.LoginRequest{
				Email: "ai@shop.test", Password: "MatKhau@123",
			})
			if !errors.Is(err, domain.ErrInvalidCredentials) {
				t.Fatalf("vai trò %s phải bị chặn bằng ErrInvalidCredentials, nhận: %v", vaiTro, err)
			}
		})
	}
}

// Vai trò để trống (dữ liệu hỏng, hoặc quên Preload) cũng bị chặn.
//
// Kiểm tường minh vì đây là kiểu lỗi mở toang cửa mà không ai thấy: nếu code viết
// `user.Role.Name != super_admin` mà quên nhánh nil thì bản ghi thiếu vai trò sẽ
// làm chương trình panic, còn viết lỏng hơn một chút là nó lọt.
func TestDangNhapNenTang_ThieuVaiTroThiChan(t *testing.T) {
	u := nguoiDieuHanh(1, 1, "trong@nentang.test", "MatKhau@123", domain.RoleSuperAdmin)
	u.Role = nil
	svc := dungAuthNenTang(newFakeUserRepo(u))

	_, err := svc.LoginPlatform(context.Background(), dto.LoginRequest{
		Email: "trong@nentang.test", Password: "MatKhau@123",
	})
	if !errors.Is(err, domain.ErrInvalidCredentials) {
		t.Fatalf("tài khoản thiếu vai trò phải bị chặn, nhận: %v", err)
	}
}

// BA KIỂU HỎNG PHẢI GIỐNG HỆT NHAU. Đây mới là bài kiểm quan trọng nhất của tệp:
// nếu "email không có" khác với "email có nhưng không phải super_admin" thì trang
// đăng nhập trở thành máy dò — gõ lần lượt email của khách hàng vào là biết được
// ai trong hệ thống là người điều hành, và từ đó biết nên nhắm mật khẩu vào ai.
func TestDangNhapNenTang_MoiKieuHongTraCungMotLoi(t *testing.T) {
	users := newFakeUserRepo(
		nguoiDieuHanh(1, 1, "sep@nentang.test", "MatKhau@123", domain.RoleSuperAdmin),
		nguoiDieuHanh(2, 1, "chushop@shop.test", "MatKhau@123", domain.RoleAdmin),
	)
	svc := dungAuthNenTang(users)

	canh := []struct {
		ten   string
		email string
		mk    string
	}{
		{"email không tồn tại", "khongco@nentang.test", "MatKhau@123"},
		{"đúng email nhưng sai mật khẩu", "sep@nentang.test", "SaiMatKhau@1"},
		{"đúng mật khẩu nhưng không phải super_admin", "chushop@shop.test", "MatKhau@123"},
	}
	for _, c := range canh {
		t.Run(c.ten, func(t *testing.T) {
			_, err := svc.LoginPlatform(context.Background(), dto.LoginRequest{Email: c.email, Password: c.mk})
			if !errors.Is(err, domain.ErrInvalidCredentials) {
				t.Fatalf("phải là ErrInvalidCredentials để không lộ gì, nhận: %v", err)
			}
		})
	}
}

// Tài khoản bị khoá thì báo đúng lý do — nhưng chỉ sau khi đã gõ đúng mật khẩu.
// Đúng thứ tự của LoginShop: câu "tài khoản đang không hoạt động" là thông tin
// dành cho chủ tài khoản, không phải cho người đang gõ thử.
func TestDangNhapNenTang_TaiKhoanBiKhoa(t *testing.T) {
	u := nguoiDieuHanh(1, 1, "sep@nentang.test", "MatKhau@123", domain.RoleSuperAdmin)
	u.Status = "inactive"
	svc := dungAuthNenTang(newFakeUserRepo(u))

	if _, err := svc.LoginPlatform(context.Background(), dto.LoginRequest{
		Email: "sep@nentang.test", Password: "MatKhau@123",
	}); !errors.Is(err, domain.ErrUserInactive) {
		t.Fatalf("tài khoản khoá phải báo ErrUserInactive, nhận: %v", err)
	}

	// Cùng tài khoản khoá đó nhưng gõ SAI mật khẩu thì quay về câu chung, không
	// được lộ ra rằng email này có thật.
	if _, err := svc.LoginPlatform(context.Background(), dto.LoginRequest{
		Email: "sep@nentang.test", Password: "SaiMatKhau@1",
	}); !errors.Is(err, domain.ErrInvalidCredentials) {
		t.Fatalf("sai mật khẩu phải là ErrInvalidCredentials, nhận: %v", err)
	}
}
