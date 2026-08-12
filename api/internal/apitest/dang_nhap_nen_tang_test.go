package apitest

import (
	"context"
	"encoding/json"
	"net/http"
	"testing"
	"time"

	"sass-api/internal/domain"
	"sass-api/internal/tenant"
	"sass-api/pkg/hash"
)

// Đăng nhập KHU ĐIỀU HÀNH NỀN TẢNG chạy trên API thật.
//
// VÌ SAO KHÔNG ĐỦ NẾU CHỈ CÓ BÀI KIỂM Ở TẦNG SERVICE: repo giả trong
// internal/service bỏ qua ctx, nên bài kiểm ở đó chứng minh được luật vai trò mà
// KHÔNG chứng minh được điều quan trọng nhất — rằng câu tra email chạy lọt qua
// plugin lọc tenant khi request chưa xác định được cửa hàng nào. Ở đây thì có:
// cùng router, cùng middleware, cùng plugin GORM như lúc chạy thật. Bỏ
// tenant.WithoutScope trong LoginPlatform ra là bài kiểm này đỏ ngay với 500
// "chưa xác định được cửa hàng cho câu truy vấn này".

// gieoNguoiDieuHanh tạo một super_admin thuộc cửa hàng c.
//
// super_admin vẫn phải có tenant_id vì cột đó NOT NULL và mọi tài khoản đều sinh
// ra bên trong một cửa hàng — quyền xuyên cửa hàng của người điều hành đến từ
// VAI TRÒ chứ không đến từ việc đứng ngoài mọi cửa hàng.
func gieoNguoiDieuHanh(t *testing.T, h *heThong, c *cuaHang, email string) {
	t.Helper()

	bam, err := hash.Hash(matKhauTest)
	if err != nil {
		t.Fatalf("không băm được mật khẩu: %v", err)
	}
	now := time.Now()

	// Gieo bằng ctx CÓ cửa hàng chứ không phải ctxThoat(): ctx thoát tắt bộ lọc,
	// mà tắt bộ lọc thì plugin cũng không điền tenant_id vào câu INSERT.
	tao(t, h.db, tenant.WithID(context.Background(), c.id), &domain.User{
		Username: domain.StringOrNull("nguoidieuhanh"), RoleID: domain.SuperAdminRoleID,
		FullName: "Người điều hành " + c.vet, Email: email,
		PasswordHash: bam, Status: "active", EmailVerifiedAt: &now,
	})
}

// dangNhapNenTang gọi đúng đường mà SaaS Console gọi: KHÔNG token, và Host là
// một tên miền không có trong sổ — tức là request không có cách nào biết cửa
// hàng nào cả. Đó là hình dạng thật của lượt đăng nhập từ khu điều hành.
func dangNhapNenTang(t *testing.T, h *heThong, email, matKhau string) traLoi {
	t.Helper()

	return h.goi(t, "", http.MethodPost, "/api/v1/auth/platform-login", map[string]any{
		"email":    email,
		"password": matKhau,
	})
}

// Đường đi bình thường: super_admin vào được dù request không mang cửa hàng nào.
func TestDangNhapNenTang_VaoDuocKhiRequestChuaBietCuaHang(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	const email = "dieuhanh@nentang.test"
	gieoNguoiDieuHanh(t, h, a, email)

	res := dangNhapNenTang(t, h, email, matKhauTest)
	if res.ma != http.StatusOK {
		t.Fatalf("super_admin phải vào được, nhận %d\n%s", res.ma, catBot(res.than))
	}

	var than struct {
		Data struct {
			AccessToken string `json:"access_token"`
			User        struct {
				Email string `json:"email"`
				Role  struct {
					Name string `json:"name"`
				} `json:"role"`
			} `json:"user"`
		} `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &than); err != nil {
		t.Fatalf("không đọc được câu trả lời: %v — %s", err, catBot(res.than))
	}
	if than.Data.AccessToken == "" {
		t.Fatalf("trả 200 nhưng không có access_token: %s", catBot(res.than))
	}
	if than.Data.User.Email != email || than.Data.User.Role.Name != domain.RoleSuperAdmin {
		t.Fatalf("trả về sai người: %+v", than.Data.User)
	}
}

// Chủ cửa hàng KHÔNG đi vào được bằng đường này, dù gõ đúng email và mật khẩu.
//
// Đây là ranh giới giữa hai sản phẩm: khách hàng trả tiền có Shop Admin của
// riêng họ, còn khu điều hành là chỗ nhìn thấy mọi khách hàng. Một chủ shop lọt
// vào đây là nhìn thấy hàng xóm của mình.
func TestDangNhapNenTang_ChuCuaHangKhongVaoDuoc(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	var chuShop domain.User
	if err := h.db.WithContext(ctxThoat()).First(&chuShop, a.quanTri).Error; err != nil {
		t.Fatalf("không đọc được tài khoản quản trị của cửa hàng: %v", err)
	}

	res := dangNhapNenTang(t, h, chuShop.Email, matKhauTest)
	if res.ma != http.StatusUnauthorized {
		t.Fatalf("chủ cửa hàng phải bị chặn 401, nhận %d\n%s", res.ma, catBot(res.than))
	}
	// Cùng bộ thông tin đó vào Shop Admin thì phải vào được — nếu không, bài kiểm
	// trên chỉ đang chứng minh mật khẩu sai chứ không chứng minh được gì về vai trò.
	if vao := h.goi(t, "", http.MethodPost, "/api/v1/auth/shop-login", map[string]any{
		"shop_code": a.ma, "username": "quantri", "password": matKhauTest,
	}); vao.ma != http.StatusOK {
		t.Fatalf("chính tài khoản đó phải vào được Shop Admin, nhận %d\n%s", vao.ma, catBot(vao.than))
	}
}

// Ba kiểu hỏng trả về CÙNG một câu, để trang đăng nhập không thành máy dò xem ai
// là người điều hành. Bài kiểm ở tầng service so sánh giá trị lỗi; ở đây so sánh
// thứ NGƯỜI NGOÀI thật sự nhìn thấy: mã HTTP và câu chữ.
func TestDangNhapNenTang_MoiKieuHongGiongHetNhau(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	const email = "dieuhanh2@nentang.test"
	gieoNguoiDieuHanh(t, h, a, email)

	var chuShop domain.User
	if err := h.db.WithContext(ctxThoat()).First(&chuShop, a.quanTri).Error; err != nil {
		t.Fatalf("không đọc được tài khoản quản trị của cửa hàng: %v", err)
	}

	canh := []struct {
		ten   string
		email string
		mk    string
	}{
		{"email không tồn tại", "khongcoainhuvay@nentang.test", matKhauTest},
		{"đúng email nhưng sai mật khẩu", email, "mat-khau-sai-hoan-toan"},
		{"đúng mật khẩu nhưng không phải super_admin", chuShop.Email, matKhauTest},
	}

	var mau string
	for i, c := range canh {
		res := dangNhapNenTang(t, h, c.email, c.mk)
		if res.ma != http.StatusUnauthorized {
			t.Fatalf("%s: phải 401, nhận %d\n%s", c.ten, res.ma, catBot(res.than))
		}
		if i == 0 {
			mau = res.than
			continue
		}
		if res.than != mau {
			t.Fatalf("%s: câu trả lời khác với lượt đầu — người ngoài dò được ai có thật.\nlượt đầu: %s\nlượt này: %s",
				c.ten, catBot(mau), catBot(res.than))
		}
	}
}

// Token phát ra mang đúng cửa hàng của chính tài khoản đó, KHÔNG phải một tấm vé
// xem được mọi cửa hàng.
//
// Kiểm bằng cách cầm token đó gọi một đường quản trị bình thường: phải chỉ thấy
// dữ liệu của cửa hàng mình. Ngày mở nhóm /platform/*, quyền xuyên cửa hàng phải
// do nhóm đó tự xét theo vai trò — nếu ai đó nới token này ra cho tiện thì bài
// kiểm này đỏ.
func TestDangNhapNenTang_TokenVanBiKhoaTrongCuaHangCuaMinh(t *testing.T) {
	h := dungHeThong(t)
	a, b := haiCuaHang(t, h)

	const email = "dieuhanh3@nentang.test"
	gieoNguoiDieuHanh(t, h, a, email)

	res := dangNhapNenTang(t, h, email, matKhauTest)
	if res.ma != http.StatusOK {
		t.Fatalf("đăng nhập hỏng: %d\n%s", res.ma, catBot(res.than))
	}
	var than struct {
		Data struct {
			AccessToken string `json:"access_token"`
		} `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &than); err != nil {
		t.Fatalf("không đọc được token: %v — %s", err, catBot(res.than))
	}

	ds := h.goi(t, than.Data.AccessToken, http.MethodGet, "/api/v1/admin/orders?limit=100", nil)
	if ds.ma != http.StatusOK {
		t.Fatalf("người điều hành gọi danh sách đơn phải 200, nhận %d\n%s", ds.ma, catBot(ds.than))
	}
	if chuaDauVet(ds.than, b.vet) {
		t.Fatalf("token của người điều hành thuộc cửa hàng %s mà đọc được dữ liệu của %s:\n%s",
			a.ma, b.ma, catBot(ds.than))
	}
	if !chuaDauVet(ds.than, a.vet) {
		t.Fatalf("token phải đọc được dữ liệu của chính cửa hàng mình mà không thấy:\n%s", catBot(ds.than))
	}
}
