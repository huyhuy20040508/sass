package service

import (
	"context"
	"errors"
	"fmt"
	"testing"

	"sass-api/internal/domain"
	"sass-api/internal/dto"
	"sass-api/pkg/hash"
)

// fakeUserRepo cài đủ UserRepository nhưng chỉ phần tài khoản nội bộ chạy thật —
// mấy hàm của luồng khách hàng trả rỗng vì service này không đụng tới.
type fakeUserRepo struct {
	users   map[uint]*domain.User
	deleted []uint
	nextID  uint
}

func newFakeUserRepo(users ...*domain.User) *fakeUserRepo {
	r := &fakeUserRepo{users: map[uint]*domain.User{}, nextID: 100}
	for _, u := range users {
		r.users[u.ID] = u
	}
	return r
}

func (r *fakeUserRepo) Create(_ context.Context, u *domain.User) error {
	r.nextID++
	u.ID = r.nextID
	r.users[u.ID] = u
	return nil
}

func (r *fakeUserRepo) Update(_ context.Context, u *domain.User) error {
	r.users[u.ID] = u
	return nil
}

func (r *fakeUserRepo) Delete(_ context.Context, id uint) error {
	r.deleted = append(r.deleted, id)
	delete(r.users, id)
	return nil
}

func (r *fakeUserRepo) FindByID(_ context.Context, id uint) (*domain.User, error) {
	if u, ok := r.users[id]; ok {
		return u, nil
	}
	return nil, domain.ErrNotFound
}

func (r *fakeUserRepo) FindByEmail(_ context.Context, email string) (*domain.User, error) {
	for _, u := range r.users {
		if u.Email == email {
			return u, nil
		}
	}
	return nil, domain.ErrNotFound
}

func (r *fakeUserRepo) FindByFacebookID(_ context.Context, fbID string) (*domain.User, error) {
	if fbID == "" {
		return nil, domain.ErrNotFound
	}
	for _, u := range r.users {
		if string(u.FacebookID) == fbID {
			return u, nil
		}
	}
	return nil, domain.ErrNotFound
}

func (r *fakeUserRepo) FindByGoogleID(_ context.Context, ggID string) (*domain.User, error) {
	if ggID == "" {
		return nil, domain.ErrNotFound
	}
	for _, u := range r.users {
		if string(u.GoogleID) == ggID {
			return u, nil
		}
	}
	return nil, domain.ErrNotFound
}

func (r *fakeUserRepo) ExistsByEmail(_ context.Context, email string) (bool, error) {
	_, err := r.FindByEmail(context.Background(), email)
	return err == nil, nil
}

func (r *fakeUserRepo) ExistsByEmailExcept(_ context.Context, email string, excludeID uint) (bool, error) {
	for _, u := range r.users {
		if u.Email == email && u.ID != excludeID {
			return true, nil
		}
	}
	return false, nil
}

func (r *fakeUserRepo) CountActiveByRole(_ context.Context, roleID, excludeID uint) (int64, error) {
	var n int64
	for _, u := range r.users {
		if u.RoleID == roleID && u.Status == "active" && u.ID != excludeID {
			n++
		}
	}
	return n, nil
}

func (r *fakeUserRepo) ListInternal(_ context.Context, _ domain.InternalUserFilter) ([]domain.User, int64, error) {
	return nil, 0, nil
}

func (r *fakeUserRepo) InternalStats(_ context.Context) (domain.InternalUserStats, error) {
	return domain.InternalUserStats{}, nil
}

func (r *fakeUserRepo) ListCustomers(_ context.Context, _ domain.CustomerFilter) ([]domain.User, int64, error) {
	return nil, 0, nil
}

func (r *fakeUserRepo) CustomerStats(_ context.Context) (domain.CustomerStats, error) {
	return domain.CustomerStats{}, nil
}

func (r *fakeUserRepo) AggregateCustomerOrders(_ context.Context, _ []uint) (map[uint]domain.CustomerAggregate, error) {
	return map[uint]domain.CustomerAggregate{}, nil
}

func (r *fakeUserRepo) DefaultAddresses(_ context.Context, _ []uint) (map[uint]string, error) {
	return map[uint]string{}, nil
}

func (r *fakeUserRepo) SaveDefaultAddress(_ context.Context, _ *domain.User, _ string) error {
	return nil
}

type fakeRoleRepo struct{}

func (fakeRoleRepo) FindByName(_ context.Context, name string) (*domain.Role, error) {
	return &domain.Role{Name: name}, nil
}

func (fakeRoleRepo) FindByID(_ context.Context, id uint) (*domain.Role, error) {
	return &domain.Role{ID: id, Name: "admin", DisplayName: "Quản trị viên"}, nil
}

func (fakeRoleRepo) List(_ context.Context) ([]domain.Role, error) { return nil, nil }

func (fakeRoleRepo) Update(_ context.Context, _ *domain.Role) error { return nil }

func (fakeRoleRepo) CountUsers(_ context.Context) (map[uint]int64, error) {
	return map[uint]int64{}, nil
}

func staff(id uint, roleID uint, status string) *domain.User {
	return &domain.User{
		ID: id, RoleID: roleID, Status: status,
		FullName: "Nhân sự", Email: fmt.Sprintf("u%d@shop.local", id),
	}
}

func newUserSvc(users ...*domain.User) (UserService, *fakeUserRepo) {
	repo := newFakeUserRepo(users...)
	return NewUserService(repo, fakeRoleRepo{}), repo
}

// Khoá / xoá / hạ vai trò người super admin đang hoạt động CUỐI CÙNG là tự nhốt
// mình ngoài hệ thống: không còn ai đủ quyền mở lại.
func TestUserChanKhoaSuperAdminCuoiCung(t *testing.T) {
	ctx := context.Background()
	boss := staff(1, domain.SuperAdminRoleID, "active")
	other := staff(2, domain.AdminRoleID, "active")
	svc, repo := newUserSvc(boss, other)

	actor := Actor{ID: 2, Role: domain.RoleSuperAdmin}

	if _, err := svc.UpdateStatus(ctx, 1, "inactive", actor); !errors.Is(err, domain.ErrLastSuperAdmin) {
		t.Fatalf("khoá super admin cuối cùng phải bị chặn, nhận: %v", err)
	}
	if err := svc.Delete(ctx, 1, actor); !errors.Is(err, domain.ErrLastSuperAdmin) {
		t.Fatalf("xoá super admin cuối cùng phải bị chặn, nhận: %v", err)
	}
	if len(repo.deleted) != 0 {
		t.Fatal("không được xoá gì khi đã chặn")
	}

	// Có super admin thứ hai đang hoạt động thì thao tác mở lại bình thường.
	repo.users[3] = staff(3, domain.SuperAdminRoleID, "active")
	if _, err := svc.UpdateStatus(ctx, 1, "inactive", actor); err != nil {
		t.Fatalf("còn super admin khác thì phải khoá được, nhận: %v", err)
	}
}

// Quản trị viên thường không được đụng vào tài khoản super admin, cũng không được
// tự tạo ra một super admin mới — nếu không thì tự nâng quyền chỉ mất một cú bấm.
func TestUserAdminKhongDungDuocSuperAdmin(t *testing.T) {
	ctx := context.Background()
	boss := staff(1, domain.SuperAdminRoleID, "active")
	boss2 := staff(4, domain.SuperAdminRoleID, "active")
	svc, _ := newUserSvc(boss, boss2)

	actor := Actor{ID: 9, Role: domain.RoleAdmin}

	if err := svc.Delete(ctx, 1, actor); !errors.Is(err, domain.ErrForbidden) {
		t.Fatalf("admin xoá super admin phải bị chặn, nhận: %v", err)
	}
	if _, err := svc.UpdateStatus(ctx, 1, "inactive", actor); !errors.Is(err, domain.ErrForbidden) {
		t.Fatalf("admin khoá super admin phải bị chặn, nhận: %v", err)
	}

	_, err := svc.Create(ctx, &dto.UserRequest{
		FullName: "Kẻ tự phong", Email: "moi@shop.local",
		RoleID: domain.SuperAdminRoleID, Status: "active",
	}, actor)
	if !errors.Is(err, domain.ErrForbidden) {
		t.Fatalf("admin tạo super admin phải bị chặn, nhận: %v", err)
	}
}

// Tự khoá / tự xoá / tự hạ vai trò mình đều là thao tác một chiều: sau đó chính
// người bấm không còn quyền sửa lại.
func TestUserKhongTuHaQuyenChinhMinh(t *testing.T) {
	ctx := context.Background()
	me := staff(5, domain.AdminRoleID, "active")
	boss := staff(1, domain.SuperAdminRoleID, "active")
	svc, _ := newUserSvc(me, boss)

	actor := Actor{ID: 5, Role: domain.RoleAdmin}

	if _, err := svc.UpdateStatus(ctx, 5, "inactive", actor); !errors.Is(err, domain.ErrForbidden) {
		t.Fatalf("tự khoá mình phải bị chặn, nhận: %v", err)
	}
	if err := svc.Delete(ctx, 5, actor); !errors.Is(err, domain.ErrForbidden) {
		t.Fatalf("tự xoá mình phải bị chặn, nhận: %v", err)
	}

	_, err := svc.Update(ctx, 5, &dto.UserRequest{
		FullName: "Tôi", Email: me.Email,
		RoleID: domain.StaffRoleID, Status: "active",
	}, actor)
	if !errors.Is(err, domain.ErrForbidden) {
		t.Fatalf("tự hạ vai trò mình phải bị chặn, nhận: %v", err)
	}
}

// Tự đổi mật khẩu phải chứng minh mình là chủ tài khoản: gõ sai mật khẩu hiện
// tại thì không đổi được, và mật khẩu cũ trong database phải còn nguyên.
func TestUserDoiMatKhauPhaiDungMatKhauHienTai(t *testing.T) {
	ctx := context.Background()
	me := staff(5, domain.AdminRoleID, "active")
	old, err := hash.Hash("CuKy@123")
	if err != nil {
		t.Fatalf("băm mật khẩu: %v", err)
	}
	me.PasswordHash = old
	svc, repo := newUserSvc(me)

	err = svc.ChangePassword(ctx, 5, &dto.ChangePasswordRequest{
		CurrentPassword: "GoNham@123", NewPassword: "MoiToanh@123",
	})
	if !errors.Is(err, domain.ErrPasswordIncorrect) {
		t.Fatalf("sai mật khẩu hiện tại phải bị chặn, nhận: %v", err)
	}
	if repo.users[5].PasswordHash != old {
		t.Fatal("bị chặn rồi thì mật khẩu trong database phải giữ nguyên")
	}

	// Gõ lại đúng mật khẩu đang dùng: chặn để không báo "đã đổi" cho một lần lưu
	// chẳng thay đổi gì.
	err = svc.ChangePassword(ctx, 5, &dto.ChangePasswordRequest{
		CurrentPassword: "CuKy@123", NewPassword: "CuKy@123",
	})
	if !errors.Is(err, domain.ErrPasswordSame) {
		t.Fatalf("đặt lại đúng mật khẩu cũ phải bị chặn, nhận: %v", err)
	}

	if err := svc.ChangePassword(ctx, 5, &dto.ChangePasswordRequest{
		CurrentPassword: "CuKy@123", NewPassword: "MoiToanh@123",
	}); err != nil {
		t.Fatalf("đúng mật khẩu hiện tại thì phải đổi được, nhận: %v", err)
	}
	if !hash.Check("MoiToanh@123", repo.users[5].PasswordHash) {
		t.Fatal("mật khẩu mới chưa được ghi xuống")
	}
}

// Hồ sơ tự sửa chỉ đụng tới họ tên / điện thoại / ảnh. Vai trò, trạng thái và
// email đi qua đây được thì tự nâng quyền chỉ mất một request.
func TestUserSuaHoSoKhongDoiDuocQuyen(t *testing.T) {
	ctx := context.Background()
	me := staff(5, domain.StaffRoleID, "active")
	svc, repo := newUserSvc(me)

	res, err := svc.UpdateProfile(ctx, 5, &dto.ProfileRequest{
		FullName: "  Nguyễn Văn A  ", Phone: " 0900123456 ",
	})
	if err != nil {
		t.Fatalf("sửa hồ sơ của mình phải chạy được, nhận: %v", err)
	}
	if res.FullName != "Nguyễn Văn A" || res.Phone != "0900123456" {
		t.Fatalf("khoảng trắng thừa chưa được cắt: %+v", res)
	}

	saved := repo.users[5]
	if saved.RoleID != domain.StaffRoleID {
		t.Fatalf("vai trò bị đổi qua đường hồ sơ: %d", saved.RoleID)
	}
	if saved.Status != "active" || saved.Email != me.Email {
		t.Fatalf("trạng thái/email bị đổi qua đường hồ sơ: %+v", saved)
	}
}

// Endpoint tài khoản nội bộ không được thành cửa sau để sửa/xoá khách hàng.
func TestUserKhongChamVaoKhachHang(t *testing.T) {
	ctx := context.Background()
	customer := staff(7, domain.CustomerRoleID, "active")
	svc, _ := newUserSvc(customer)

	actor := Actor{ID: 1, Role: domain.RoleSuperAdmin}

	if _, err := svc.GetByID(ctx, 7); !errors.Is(err, domain.ErrNotFound) {
		t.Fatalf("id khách hàng phải trả 404, nhận: %v", err)
	}
	if err := svc.Delete(ctx, 7, actor); !errors.Is(err, domain.ErrNotFound) {
		t.Fatalf("không được xoá khách hàng qua đường này, nhận: %v", err)
	}

	_, err := svc.Create(ctx, &dto.UserRequest{
		FullName: "Khách", Email: "khach@shop.local",
		RoleID: domain.CustomerRoleID, Status: "active",
	}, actor)
	if !errors.Is(err, domain.ErrForbidden) {
		t.Fatalf("không được tạo khách hàng qua đường này, nhận: %v", err)
	}
}
