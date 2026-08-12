package apitest

import (
	"encoding/json"
	"net/http"
	"testing"

	"sass-api/internal/domain"
)

// TestNhanVaiTro_RiengTungCuaHang — đổi tên hiển thị của một vai trò chỉ được
// đổi trong cửa hàng của mình.
//
// Vì sao đây từng là một lỗ thật: bảng `roles` dùng chung cho mọi khách hàng và
// PHẢI như vậy (bốn dòng id cố định, code tham chiếu thẳng bằng
// domain.AdminRoleID = 2...), nhưng route PUT /admin/roles/{id} lại mở cho quản
// trị viên của TỪNG cửa hàng và ghi thẳng vào bảng đó. Cửa hàng A đổi "Nhân
// viên" thành "Thu ngân" là cửa hàng B mở trang lên thấy nhân viên của mình đổi
// tên, không thông báo, không ai hiểu vì sao.
//
// Cách chữa: bảng roles giữ bộ mặc định của nhà máy và từ nay chỉ được ĐỌC; tên
// riêng của mỗi cửa hàng nằm ở role_labels (migration 0004).
//
// Bài kiểm soi cả BA chỗ tên vai trò hiện ra, vì lỗi kiểu này hay được vá ở
// đúng một trang rồi bỏ sót hai trang kia — lúc đó cùng một vai trò in ra hai
// cái tên khác nhau trong cùng một phiên đăng nhập.
func TestNhanVaiTro_RiengTungCuaHang(t *testing.T) {
	h := dungHeThong(t)
	a, b := haiCuaHang(t, h)

	// Tên gốc trong bảng dùng chung, đọc trước khi ai kịp đổi gì.
	var goc domain.Role
	if err := h.db.WithContext(ctxThoat()).First(&goc, domain.AdminRoleID).Error; err != nil {
		t.Fatalf("không đọc được vai trò gốc: %v", err)
	}

	const tenCuaA = "Chủ tiệm (isoa đặt)"
	const tenCuaB = "Sếp (isob đặt)"

	dat := func(c *cuaHang, ten string) {
		t.Helper()
		res := h.goi(t, c.token, http.MethodPut, "/api/v1/admin/roles/2",
			map[string]any{"display_name": ten, "description": "mô tả của " + c.vet})
		if res.ma != http.StatusOK {
			t.Fatalf("cửa hàng %s đổi tên vai trò phải trả 200, nhận %d\n%s", c.ma, res.ma, catBot(res.than))
		}
	}

	dat(a, tenCuaA)
	kiemTraTenVaiTro(t, h, a, tenCuaA)
	// B chưa đặt gì: phải thấy tên mặc định của nhà máy, không phải tên của A.
	kiemTraTenVaiTro(t, h, b, goc.DisplayName)

	// B đặt tên riêng: A không được đổi theo.
	dat(b, tenCuaB)
	kiemTraTenVaiTro(t, h, b, tenCuaB)
	kiemTraTenVaiTro(t, h, a, tenCuaA)

	// Bảng dùng chung phải còn nguyên. Đây là chốt cuối: còn một đường nào ghi vào
	// `roles` thì mọi bài kiểm bên trên vẫn xanh cho tới khi có cửa hàng thứ ba.
	var sau domain.Role
	if err := h.db.WithContext(ctxThoat()).First(&sau, domain.AdminRoleID).Error; err != nil {
		t.Fatalf("không đọc lại được vai trò: %v", err)
	}
	if sau.DisplayName != goc.DisplayName || sau.Description != goc.Description {
		t.Fatalf("bảng roles (dùng chung) đã bị ghi: %q/%q -> %q/%q",
			goc.DisplayName, goc.Description, sau.DisplayName, sau.Description)
	}
}

// kiemTraTenVaiTro soi tên vai trò 2 ở cả ba chỗ nó hiện ra với người dùng:
// trang Vai trò, cột vai trò của trang Người dùng, và hồ sơ người đang đăng nhập
// (thứ thanh tiêu đề của Shop Admin in ra).
func kiemTraTenVaiTro(t *testing.T, h *heThong, c *cuaHang, mong string) {
	t.Helper()

	// 1. GET /admin/roles
	res := h.goi(t, c.token, http.MethodGet, "/api/v1/admin/roles", nil)
	if res.ma != http.StatusOK {
		t.Fatalf("[%s] danh sách vai trò trả %d", c.ma, res.ma)
	}
	var dsVaiTro struct {
		Data []struct {
			ID          uint   `json:"id"`
			DisplayName string `json:"display_name"`
		} `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &dsVaiTro); err != nil {
		t.Fatalf("[%s] không đọc được danh sách vai trò: %v", c.ma, err)
	}
	var thay string
	for _, v := range dsVaiTro.Data {
		if v.ID == domain.AdminRoleID {
			thay = v.DisplayName
		}
	}
	if thay != mong {
		t.Errorf("[%s] trang Vai trò: mong %q, nhận %q", c.ma, mong, thay)
	}

	// 2. GET /admin/users — cột vai trò của từng tài khoản
	res = h.goi(t, c.token, http.MethodGet, "/api/v1/admin/users?page_size=100", nil)
	if res.ma != http.StatusOK {
		t.Fatalf("[%s] danh sách tài khoản trả %d", c.ma, res.ma)
	}
	var dsUser struct {
		Data []struct {
			RoleID          uint   `json:"role_id"`
			RoleDisplayName string `json:"role_display_name"`
		} `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &dsUser); err != nil {
		t.Fatalf("[%s] không đọc được danh sách tài khoản: %v", c.ma, err)
	}
	var soDong int
	for _, u := range dsUser.Data {
		if u.RoleID != domain.AdminRoleID {
			continue
		}
		soDong++
		if u.RoleDisplayName != mong {
			t.Errorf("[%s] trang Người dùng: mong %q, nhận %q", c.ma, mong, u.RoleDisplayName)
		}
	}
	if soDong == 0 {
		t.Errorf("[%s] không có tài khoản nào mang vai trò admin — bài kiểm không soi được gì", c.ma)
	}

	// 3. GET /auth/me — nguồn của tên vai trò trên thanh tiêu đề
	res = h.goi(t, c.token, http.MethodGet, "/api/v1/auth/me", nil)
	if res.ma != http.StatusOK {
		t.Fatalf("[%s] /auth/me trả %d", c.ma, res.ma)
	}
	var hoSo struct {
		Data struct {
			Role struct {
				DisplayName string `json:"display_name"`
			} `json:"role"`
		} `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &hoSo); err != nil {
		t.Fatalf("[%s] không đọc được hồ sơ: %v", c.ma, err)
	}
	if hoSo.Data.Role.DisplayName != mong {
		t.Errorf("[%s] /auth/me: mong %q, nhận %q", c.ma, mong, hoSo.Data.Role.DisplayName)
	}
}
