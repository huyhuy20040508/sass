package apitest

import (
	"context"
	"encoding/json"
	"net/http"
	"testing"

	"gorm.io/gorm"

	"sass-api/internal/domain"
	"sass-api/pkg/hash"
)

// Đăng nhập KHU ĐIỀU HÀNH NỀN TẢNG chạy trên API thật.
//
// ĐÂY LÀ BỘ KIỂM CỦA MỘT RANH GIỚI, không phải của một màn hình đăng nhập.
//
// Bản trước: khu điều hành tra `selliotech.users` rồi đòi vai trò
// `super_admin`. Vai trò đó là vai trò cao nhất TRONG MỘT CỬA HÀNG, mà tiệm nào
// cũng có một người như vậy — chủ shop. Nghĩa là chìa vào khu điều hành nằm
// trong bảng tài khoản của khách hàng: ai đổi được mật khẩu trong một tiệm bất
// kỳ cũng đổi được chìa, và người điều hành với người mua phần mềm dùng chung
// một danh tính.
//
// Bản này: sổ riêng (`platform_users` bên control plane), mật khẩu riêng, token
// riêng. Hai loại token loại trừ nhau, và bộ kiểm phải chứng minh CẢ HAI CHIỀU
// — chỉ kiểm một chiều là bỏ ngỏ đúng chiều còn lại:
//
//   - token cửa hàng → khu điều hành: 403 (TestTokenCuaHangKhongMoDuocKhuDieuHanh)
//   - token nền tảng → khu cửa hàng : 401 (TestTokenNenTangKhongMoDuocKhuCuaHang)
//
// Cần database CONTROL PLANE của bộ test.

// ---------- phụ trợ dùng chung cho mọi bài kiểm khu điều hành ----------

// gieoNguoiDieuHanh ghi một dòng vào SỔ NGƯỜI ĐIỀU HÀNH của control plane.
//
// matKhau rỗng = tài khoản CHƯA đặt mật khẩu (password_hash NULL). Đó là trạng
// thái thật của một dòng vừa được `cmd/nguoi-dieu-hanh them` tạo ra, và nó phải
// KHÔNG đăng nhập được — nên bộ kiểm cần dựng được nó.
func gieoNguoiDieuHanh(t *testing.T, h *heThong, email, vaiTro, matKhau string) uint {
	t.Helper()
	if h.nenTang == nil {
		t.Fatal("bài kiểm này cần control plane — dựng hệ thống bằng dungHeThongDieuHanh")
	}

	xoaNguoiDieuHanh(t, h, email)

	nguoi := domain.PlatformUser{
		Email:    email,
		FullName: "Người điều hành " + email,
		Role:     vaiTro,
		Status:   "active",
	}
	if matKhau != "" {
		bam, err := hash.Hash(matKhau)
		if err != nil {
			t.Fatalf("không băm được mật khẩu: %v", err)
		}
		nguoi.PasswordHash = &bam
	}
	if err := h.nenTang.WithContext(context.Background()).Create(&nguoi).Error; err != nil {
		t.Fatalf("không gieo được người điều hành %s: %v", email, err)
	}
	t.Cleanup(func() { xoaNguoiDieuHanh(t, h, email) })

	return nguoi.ID
}

// xoaNguoiDieuHanh xoá HẲN các dòng theo email.
//
// Xoá hẳn chứ không xoá mềm: dòng xoá mềm vẫn giữ khoá duy nhất (email,
// deleted_mark) của lần chạy trước, và lần chạy sau sẽ vướng vào chính nó.
func xoaNguoiDieuHanh(t *testing.T, h *heThong, emails ...string) {
	t.Helper()
	if h.nenTang == nil || len(emails) == 0 {
		return
	}

	if err := h.nenTang.WithContext(context.Background()).
		Exec("DELETE FROM platform_users WHERE email IN (?)", emails).Error; err != nil {
		t.Fatalf("không dọn được sổ người điều hành: %v", err)
	}
}

// datTrangThaiNguoiDieuHanh khoá / mở khoá một người điều hành.
func datTrangThaiNguoiDieuHanh(t *testing.T, nenTang *gorm.DB, id uint, trangThai string) {
	t.Helper()

	if err := nenTang.WithContext(context.Background()).
		Exec("UPDATE platform_users SET status = ?, updated_at = NOW(3) WHERE id = ?", trangThai, id).
		Error; err != nil {
		t.Fatalf("không đổi được trạng thái người điều hành: %v", err)
	}
}

// dangNhapNenTang gọi đúng đường mà SaaS Console gọi: KHÔNG token, Host là một
// tên miền không có trong sổ — hình dạng thật của một lượt đăng nhập điều hành.
func (h *heThong) dangNhapNenTang(t *testing.T, email, matKhau string) traLoi {
	t.Helper()

	return h.goi(t, "", http.MethodPost, "/api/v1/auth/platform-login", map[string]any{
		"email":    email,
		"password": matKhau,
	})
}

// tokenNenTang đăng nhập và lấy access token, hỏng thì dừng bài kiểm.
func (h *heThong) tokenNenTang(t *testing.T, email, matKhau string) string {
	t.Helper()

	res := h.dangNhapNenTang(t, email, matKhau)
	if res.ma != http.StatusOK {
		t.Fatalf("đăng nhập khu điều hành hỏng: %d\n%s", res.ma, catBot(res.than))
	}

	return docTokenNenTang(t, res.than).AccessToken
}

// thanNenTang là phần data của câu trả lời đăng nhập điều hành.
type thanNenTang struct {
	AccessToken  string `json:"access_token"`
	RefreshToken string `json:"refresh_token"`
	User         struct {
		ID    uint   `json:"id"`
		Email string `json:"email"`
		// Role là CHUỖI (owner|operator|support), không phải đối tượng vai trò như
		// bên cửa hàng — khu điều hành không có bảng RBAC riêng.
		Role string `json:"role"`
	} `json:"user"`
}

func docTokenNenTang(t *testing.T, than string) thanNenTang {
	t.Helper()

	var body struct {
		Data thanNenTang `json:"data"`
	}
	if err := json.Unmarshal([]byte(than), &body); err != nil {
		t.Fatalf("không đọc được câu trả lời: %v — %s", err, catBot(than))
	}

	return body.Data
}

// ---------- test ----------

// Đường đi bình thường, và câu trả lời phải mô tả một NGƯỜI CỦA NỀN TẢNG chứ
// không phải một tài khoản cửa hàng.
func TestDangNhapNenTang_NguoiTrongSoVaoDuoc(t *testing.T) {
	h := dungHeThongDieuHanh(t)
	const email = "sep@nentang.test"
	gieoNguoiDieuHanh(t, h, email, domain.PlatformRoleOwner, matKhauTest)

	res := h.dangNhapNenTang(t, email, matKhauTest)
	if res.ma != http.StatusOK {
		t.Fatalf("người trong sổ phải vào được, nhận %d\n%s", res.ma, catBot(res.than))
	}

	data := docTokenNenTang(t, res.than)
	if data.AccessToken == "" || data.RefreshToken == "" {
		t.Fatalf("trả 200 nhưng thiếu token: %s", catBot(res.than))
	}
	if data.User.Email != email || data.User.Role != domain.PlatformRoleOwner {
		t.Fatalf("trả về sai người: %+v", data.User)
	}

	// Token dùng được ngay ở khu điều hành — không có vế này thì mọi bài kiểm
	// "phải bị chặn" bên dưới vẫn xanh kể cả khi không ai vào được cả.
	if ds := h.goi(t, data.AccessToken, http.MethodGet, "/api/v1/platform/plans", nil); ds.ma != http.StatusOK {
		t.Fatalf("token vừa cấp phải mở được khu điều hành, nhận %d\n%s", ds.ma, catBot(ds.than))
	}

	// Hồ sơ đọc lại từ sổ, không phải từ token.
	me := h.goi(t, data.AccessToken, http.MethodGet, "/api/v1/auth/platform-me", nil)
	if me.ma != http.StatusOK {
		t.Fatalf("/auth/platform-me phải 200, nhận %d\n%s", me.ma, catBot(me.than))
	}
	if !chuaDauVet(me.than, email) {
		t.Fatalf("/auth/platform-me trả về người khác:\n%s", catBot(me.than))
	}
}

// CHỦ CỬA HÀNG KHÔNG VÀO ĐƯỢC — bài kiểm quan trọng nhất của cả tệp.
//
// Người trong bài này là super_admin THẬT của một cửa hàng thật: đúng email,
// đúng mật khẩu, và vế đối chứng chứng minh anh ta vào được Shop Admin ngay
// sau đó. Trước đợt sửa, chính anh ta cũng vào được khu điều hành.
func TestDangNhapNenTang_ChuCuaHangKhongVaoDuoc(t *testing.T) {
	h := dungHeThongDieuHanh(t)
	a, _ := haiCuaHang(t, h)

	var chuShop domain.User
	if err := h.db.WithContext(ctxThoat()).First(&chuShop, a.quanTri).Error; err != nil {
		t.Fatalf("không đọc được tài khoản quản trị của cửa hàng: %v", err)
	}

	if res := h.dangNhapNenTang(t, chuShop.Email, matKhauTest); res.ma != http.StatusUnauthorized {
		t.Fatalf("tài khoản cửa hàng phải bị chặn 401, nhận %d\n%s", res.ma, catBot(res.than))
	}

	// Đối chứng: cùng bộ thông tin đó vào Shop Admin thì vào được. Không có vế
	// này thì bài trên chỉ đang chứng minh mật khẩu sai.
	if vao := h.goi(t, "", http.MethodPost, "/api/v1/auth/shop-login", map[string]any{
		"shop_code": a.ma, "username": "quantri", "password": matKhauTest,
	}); vao.ma != http.StatusOK {
		t.Fatalf("chính tài khoản đó phải vào được Shop Admin, nhận %d\n%s", vao.ma, catBot(vao.than))
	}
}

// TOKEN CỦA CỬA HÀNG KHÔNG MỞ ĐƯỢC KHU ĐIỀU HÀNH.
//
// Chiều thứ nhất của ranh giới. Token ở đây hoàn toàn hợp lệ và mang vai trò
// cao nhất của một tiệm — nó chỉ thiếu đúng một thứ: cờ nền tảng, thứ chỉ
// /auth/platform-login cấp và không ai tự gắn vào được (sửa một bit là hỏng chữ
// ký).
func TestDangNhapNenTang_TokenCuaHangKhongMoDuocKhuDieuHanh(t *testing.T) {
	h := dungHeThongDieuHanh(t)
	a, _ := haiCuaHang(t, h)

	// a.token là token của tài khoản "quantri" (vai trò admin). Dựng thêm một
	// token của SUPER_ADMIN để không ai nói rằng bài kiểm chỉ chặn được vai trò
	// thấp.
	nangVaiTro(t, h, a.quanTri, domain.SuperAdminRoleID)
	tokenSuperAdmin := h.dangNhap(t, a.ma)

	for ten, token := range map[string]string{
		"token super_admin của một cửa hàng": tokenSuperAdmin,
		"không token":                        "",
	} {
		res := h.goi(t, token, http.MethodGet, "/api/v1/platform/plans", nil)
		muon := http.StatusForbidden
		if token == "" {
			muon = http.StatusUnauthorized
		}
		if res.ma != muon {
			t.Fatalf("%s: phải nhận %d ở khu điều hành, nhận %d\n%s", ten, muon, res.ma, catBot(res.than))
		}
	}
}

// TOKEN NỀN TẢNG KHÔNG MỞ ĐƯỢC KHU CỬA HÀNG.
//
// Chiều thứ hai, và là chiều dễ quên: nếu token điều hành cũng đi được vào
// /admin thì người điều hành trở thành một tài khoản có quyền trong MỌI cửa
// hàng — hoặc tệ hơn, trong một cửa hàng ngẫu nhiên nào đó mà bộ lọc tenant
// chọn hộ.
func TestDangNhapNenTang_TokenNenTangKhongMoDuocKhuCuaHang(t *testing.T) {
	h := dungHeThongDieuHanh(t)
	const email = "sep-ranh-gioi@nentang.test"
	gieoNguoiDieuHanh(t, h, email, domain.PlatformRoleOwner, matKhauTest)

	token := h.tokenNenTang(t, email, matKhauTest)

	for _, duong := range []string{
		"/api/v1/admin/orders",
		"/api/v1/admin/users",
		"/api/v1/admin/settings",
		"/api/v1/auth/me",
	} {
		if res := h.goi(t, token, http.MethodGet, duong, nil); res.ma != http.StatusUnauthorized {
			t.Fatalf("token nền tảng gọi %s phải bị từ chối 401, nhận %d\n%s",
				duong, res.ma, catBot(res.than))
		}
	}
}

// Mọi kiểu hỏng trả về CÙNG một câu, để màn hình đăng nhập không thành máy dò
// xem ai là người điều hành.
//
// So sánh thứ NGƯỜI NGOÀI thật sự nhìn thấy: mã HTTP và từng chữ trong thân
// phản hồi.
func TestDangNhapNenTang_MoiKieuHongGiongHetNhau(t *testing.T) {
	h := dungHeThongDieuHanh(t)

	const (
		emailTot     = "sep-tot@nentang.test"
		emailChuaMK  = "sep-chua-mat-khau@nentang.test"
		emailBiKhoa  = "sep-bi-khoa@nentang.test"
		emailKhongCo = "khongcoainhuvay@nentang.test"
	)
	gieoNguoiDieuHanh(t, h, emailTot, domain.PlatformRoleOwner, matKhauTest)
	gieoNguoiDieuHanh(t, h, emailChuaMK, domain.PlatformRoleOperator, "")
	idKhoa := gieoNguoiDieuHanh(t, h, emailBiKhoa, domain.PlatformRoleSupport, matKhauTest)
	datTrangThaiNguoiDieuHanh(t, h.nenTang, idKhoa, "locked")

	canh := []struct{ ten, email, mk string }{
		{"email không tồn tại", emailKhongCo, matKhauTest},
		{"đúng email nhưng sai mật khẩu", emailTot, "mat-khau-sai-hoan-toan"},
		{"tài khoản chưa đặt mật khẩu", emailChuaMK, matKhauTest},
		{"tài khoản bị khoá, mật khẩu đúng", emailBiKhoa, matKhauTest},
	}

	var mau string
	for i, c := range canh {
		res := h.dangNhapNenTang(t, c.email, c.mk)
		if res.ma != http.StatusUnauthorized {
			t.Fatalf("%s: phải 401, nhận %d\n%s", c.ten, res.ma, catBot(res.than))
		}
		if i == 0 {
			mau = res.than
			continue
		}
		if res.than != mau {
			t.Fatalf("%s: câu trả lời khác lượt đầu — người ngoài dò được ai có thật.\nlượt đầu: %s\nlượt này: %s",
				c.ten, catBot(mau), catBot(res.than))
		}
	}
}

// Làm mới token của khu điều hành đi ĐƯỜNG RIÊNG, và đường chung phải tiếp tục
// từ chối nó: /auth/refresh chặn mọi token không thuộc cửa hàng nào, và điều
// kiện đó là chốt chặn token cũ từ thời chưa đa cửa hàng — không được nới ra.
func TestDangNhapNenTang_LamMoiToken(t *testing.T) {
	h := dungHeThongDieuHanh(t)
	const email = "sep-lam-moi@nentang.test"
	gieoNguoiDieuHanh(t, h, email, domain.PlatformRoleOperator, matKhauTest)

	dau := docTokenNenTang(t, h.dangNhapNenTang(t, email, matKhauTest).than)

	moi := h.goi(t, "", http.MethodPost, "/api/v1/auth/platform-refresh",
		map[string]any{"refresh_token": dau.RefreshToken})
	if moi.ma != http.StatusOK {
		t.Fatalf("làm mới token điều hành phải 200, nhận %d\n%s", moi.ma, catBot(moi.than))
	}
	sau := docTokenNenTang(t, moi.than)
	if sau.AccessToken == "" {
		t.Fatalf("làm mới xong không có access token: %s", catBot(moi.than))
	}
	if ds := h.goi(t, sau.AccessToken, http.MethodGet, "/api/v1/platform/plans", nil); ds.ma != http.StatusOK {
		t.Fatalf("token vừa làm mới phải dùng được, nhận %d\n%s", ds.ma, catBot(ds.than))
	}

	if chung := h.goi(t, "", http.MethodPost, "/api/v1/auth/refresh",
		map[string]any{"refresh_token": dau.RefreshToken}); chung.ma == http.StatusOK {
		t.Fatalf("/auth/refresh KHÔNG được nhận token nền tảng, nhận %d\n%s", chung.ma, catBot(chung.than))
	}
}

// Khoá một người điều hành có hiệu lực NGAY, không phải chờ token cũ hết hạn.
//
// Đây là lý do mỗi request tra lại sổ thay vì tin vai trò ghi trong token.
// Access token sống 15 phút — đủ dài cho một người vừa bị cho nghỉ việc.
func TestDangNhapNenTang_KhoaGiuaChungThiCatNgay(t *testing.T) {
	h := dungHeThongDieuHanh(t)
	const email = "sep-bi-cat@nentang.test"
	id := gieoNguoiDieuHanh(t, h, email, domain.PlatformRoleOwner, matKhauTest)

	token := h.tokenNenTang(t, email, matKhauTest)
	if res := h.goi(t, token, http.MethodGet, "/api/v1/platform/plans", nil); res.ma != http.StatusOK {
		t.Fatalf("trước khi khoá phải vào được, nhận %d\n%s", res.ma, catBot(res.than))
	}

	datTrangThaiNguoiDieuHanh(t, h.nenTang, id, "locked")

	if res := h.goi(t, token, http.MethodGet, "/api/v1/platform/plans", nil); res.ma != http.StatusForbidden {
		t.Fatalf("khoá rồi mà token cũ vẫn vào được (nhận %d) — vai trò đang lấy từ token thay vì từ sổ\n%s",
			res.ma, catBot(res.than))
	}
	// Và cũng không gia hạn thêm phiên nào được nữa.
	if res := h.goi(t, "", http.MethodPost, "/api/v1/auth/platform-refresh",
		map[string]any{"refresh_token": token}); res.ma == http.StatusOK {
		t.Fatalf("người bị khoá vẫn làm mới được phiên: %s", catBot(res.than))
	}
}

// nangVaiTro đổi vai trò của một tài khoản cửa hàng.
func nangVaiTro(t *testing.T, h *heThong, userID, roleID uint) {
	t.Helper()

	if err := h.db.WithContext(ctxThoat()).
		Exec("UPDATE users SET role_id = ? WHERE id = ?", roleID, userID).Error; err != nil {
		t.Fatalf("không đổi được vai trò tài khoản: %v", err)
	}
}
