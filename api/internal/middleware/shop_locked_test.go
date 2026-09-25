package middleware

import (
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"testing"

	"github.com/gin-gonic/gin"

	"sass-api/internal/domain"
	"sass-api/pkg/jwt"
)

// CỬA HÀNG HẾT HẠN HỢP ĐỒNG — ai còn đi được tới đâu.
//
// Chốt này quyết định cả hai chiều của một quyết định kinh doanh: khách không
// trả tiền thì không bán hàng được nữa (chiều đóng), nhưng người có thể trả tiền
// phải đọc được trang nói cho họ biết cần trả bao nhiêu (chiều mở). Sai chiều
// đóng là mất doanh thu; sai chiều mở là khách hết hạn không có cách nào quay
// lại ngoài gọi điện — mà gọi cho ai thì không màn hình nào nói.

// phienKhoa là sổ phiên giả: cửa hàng đã bị khoá, tài khoản vẫn sống.
type phienKhoa struct{}

func (phienKhoa) KiemPhien(context.Context, uint, uint) (domain.TinhTrangPhien, error) {
	return domain.TinhTrangPhien{
		CuaHangHoatDong:   false,
		CoNguoiDung:       true,
		NguoiDungHoatDong: true,
	}, nil
}

// dungEngineKhoa dựng engine có ĐÚNG hai đường: một đường được phép khi khoá
// (trang gói dịch vụ) và một đường nghiệp vụ thường.
func dungEngineKhoa() *gin.Engine {
	r := gin.New()
	mw := JWTAuth(mgrTest(), phienKhoa{}, "/api/v1/admin/goi-dich-vu")
	r.GET("/api/v1/admin/goi-dich-vu", mw, func(c *gin.Context) { c.String(http.StatusOK, "goi") })
	r.GET("/api/v1/admin/orders", mw, func(c *gin.Context) { c.String(http.StatusOK, "don") })

	return r
}

func goiDuong(r *gin.Engine, duong, token string) *httptest.ResponseRecorder {
	req := httptest.NewRequest(http.MethodGet, duong, nil)
	req.Header.Set("Authorization", "Bearer "+token)
	w := httptest.NewRecorder()
	r.ServeHTTP(w, req)

	return w
}

func tokenVaiTro(t *testing.T, vaiTro string) string {
	t.Helper()
	token, _, err := mgrTest().Generate(7, 42, vaiTro, jwt.AccessToken)
	if err != nil {
		t.Fatalf("không cấp được token: %v", err)
	}

	return token
}

func TestCuaHangKhoa_QuanLyVanDocDuocTrangGoiDichVu(t *testing.T) {
	r := dungEngineKhoa()

	for _, vaiTro := range []string{domain.RoleSuperAdmin, domain.RoleAdmin} {
		if w := goiDuong(r, "/api/v1/admin/goi-dich-vu", tokenVaiTro(t, vaiTro)); w.Code != http.StatusOK {
			t.Errorf("%s phải đọc được trang gói dịch vụ khi cửa hàng khoá, nhận %d", vaiTro, w.Code)
		}
	}
}

// Ngoại lệ chỉ mở ĐÚNG một đường. Mọi đường còn lại vẫn đóng, và đóng NGAY TẠI
// middleware — trước khi chạm handler, nên không có chỗ nào cho một lượt ghi lọt
// qua.
func TestCuaHangKhoa_DuongKhacVanDong(t *testing.T) {
	w := goiDuong(dungEngineKhoa(), "/api/v1/admin/orders", tokenVaiTro(t, domain.RoleAdmin))

	if w.Code != http.StatusForbidden {
		t.Fatalf("đường nghiệp vụ phải bị chặn 403, nhận %d", w.Code)
	}

	// 403 CHỨ KHÔNG PHẢI 401, và phải kèm mã máy đọc được: Shop Admin rẽ nhánh
	// theo mã này để đưa người dùng về trang gói dịch vụ. Trả 401 thì bên đó xoá
	// session và đá ra màn hình đăng nhập — đúng cái phiên hạn chế này sinh ra để
	// tránh.
	var body struct {
		Errors struct {
			Ma string `json:"ma"`
		} `json:"errors"`
	}
	if err := json.Unmarshal(w.Body.Bytes(), &body); err != nil {
		t.Fatalf("response không phải JSON đọc được: %v", err)
	}
	if body.Errors.Ma != "CUA_HANG_KHOA" {
		t.Errorf("mong mã CUA_HANG_KHOA, nhận %q", body.Errors.Ma)
	}
}

// Nhân viên KHÔNG gia hạn được gì, nên họ mất phiên như trước: 401 để Shop Admin
// đưa họ ra màn hình đăng nhập, nơi câu chữ nói rõ phải hỏi ai.
func TestCuaHangKhoa_NhanVienMatPhien(t *testing.T) {
	r := dungEngineKhoa()

	if w := goiDuong(r, "/api/v1/admin/goi-dich-vu", tokenVaiTro(t, domain.RoleStaff)); w.Code != http.StatusUnauthorized {
		t.Errorf("nhân viên phải nhận 401 ngay ở trang gói dịch vụ, nhận %d", w.Code)
	}
	if w := goiDuong(r, "/api/v1/admin/orders", tokenVaiTro(t, domain.RoleStaff)); w.Code != http.StatusUnauthorized {
		t.Errorf("nhân viên phải nhận 401 ở đường nghiệp vụ, nhận %d", w.Code)
	}
}
