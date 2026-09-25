package apitest

import (
	"context"
	"net/http"
	"sort"
	"strings"
	"testing"

	"sass-api/internal/domain"
	"sass-api/internal/router"
	"sass-api/internal/tenant"
)

// BÀI KIỂM CHỐNG BỎ SÓT — đây là thứ đáng giá nhất của lượt chuyển sang phân
// quyền theo chức năng.
//
// Một đường quản trị quên gắn quyền không báo lỗi gì cả: nó chạy, nó trả dữ
// liệu, và nó mở cho mọi tài khoản nội bộ. Lỗ hổng kiểu đó chỉ lộ ra khi có
// người thật đi vào đúng đường bị quên — thường là muộn.
//
// Hai bài dưới đây soi từ hai phía: bảng route THẬT của Gin, và sổ đăng ký mà
// router ghi lại lúc dựng. Lệch nhau ở đâu là đỏ ở đó.

// duongMienQuyen — những đường của khu quản trị CỐ Ý không gắn quyền.
//
// Danh sách khai tường minh, không phải sự im lặng: thêm một đường vào đây là
// một quyết định phải viết ra và phải giải thích được.
var duongMienQuyen = map[string]string{
	// Hồ sơ của CHÍNH người đang đăng nhập. Khoá đường đổi mật khẩu lại là dựng
	// lại đúng cảnh nhân viên phải nhờ quản trị viên đặt hộ rồi đọc mật khẩu qua
	// tin nhắn — xem chú thích tại chỗ đăng ký trong router.go.
	"GET /api/v1/admin/me":          "hồ sơ của chính mình",
	"PUT /api/v1/admin/me":          "hồ sơ của chính mình",
	"PUT /api/v1/admin/me/password": "tự đổi mật khẩu của chính mình",
	"GET /api/v1/admin/settings":    "quầy bán phải đọc được tên tiệm và hạn mức bớt giá",

	// Quyền của CHÍNH mình: không đọc được thì trang quản trị chẳng biết nên vẽ
	// mục menu nào. Nó chỉ trả về đúng tập quyền của người gọi, không của ai khác.
	"GET /api/v1/admin/quyen-cua-toi": "quyền của chính mình",
}

// duongMienQuyenTuyCauHinh — cũng miễn quyền, nhưng CHỈ được đăng ký ở bản có
// bật cụm gói dịch vụ, nên bài kiểm "đường miễn phải có thật" không soi chúng.
//
// Ba đường này là lối thoát DUY NHẤT của một cửa hàng đã hết hạn. Bắt thêm một
// tick quyền ở đây là dựng ra cách để chủ tiệm tự khoá mình khỏi chính đường
// trả tiền — tức tự cắt luôn doanh thu của nhà cung cấp phần mềm.
var duongMienQuyenTuyCauHinh = map[string]string{
	"GET /api/v1/admin/goi-dich-vu":          "lối thoát của cửa hàng hết hạn",
	"POST /api/v1/admin/goi-dich-vu/gia-han": "lối thoát của cửa hàng hết hạn",
	"GET /api/v1/admin/goi-dich-vu/gia-han":  "lối thoát của cửa hàng hết hạn",
}

// TestMoiDuongQuanTriDeuKhaiQuyen đối chiếu bảng route của Gin với sổ đăng ký.
//
// Thêm một đường vào khu quản trị mà quên gắn quyền thì bài này đỏ ngay, ở máy
// người vừa sửa, chứ không phải ở bản thật ba tuần sau.
func TestMoiDuongQuanTriDeuKhaiQuyen(t *testing.T) {
	h := dungHeThong(t)

	so := router.QuyenTheoDuong()
	var thieu []string

	for _, rt := range h.r.Routes() {
		if !strings.HasPrefix(rt.Path, "/api/v1/admin") {
			continue
		}
		khoa := rt.Method + " " + rt.Path
		if _, mien := duongMienQuyen[khoa]; mien {
			continue
		}
		if _, mien := duongMienQuyenTuyCauHinh[khoa]; mien {
			continue
		}
		if _, da := so[khoa]; !da {
			thieu = append(thieu, khoa)
		}
	}

	if len(thieu) > 0 {
		sort.Strings(thieu)
		t.Fatalf("%d đường quản trị CHƯA GẮN QUYỀN — chúng đang mở cho mọi tài khoản nội bộ:\n  %s\n\n"+
			"Gắn bằng q.Dat(...) trong internal/router/router.go, hoặc khai vào duongMienQuyen "+
			"kèm lý do nếu thật sự phải mở.", len(thieu), strings.Join(thieu, "\n  "))
	}
}

// TestKhongKhaiThuaDuong bắt chiều ngược lại: sổ có đường mà router không còn.
//
// Xảy ra khi ai đó xoá một route nhưng để lại dòng khai quyền — sổ thừa thì
// bài kiểm ở trên vẫn xanh, nhưng bảng ánh xạ đã bắt đầu nói dối.
func TestKhongKhaiThuaDuong(t *testing.T) {
	h := dungHeThong(t)

	coThat := map[string]bool{}
	for _, rt := range h.r.Routes() {
		coThat[rt.Method+" "+rt.Path] = true
	}

	var thua []string
	for khoa := range router.QuyenTheoDuong() {
		if !coThat[khoa] {
			thua = append(thua, khoa)
		}
	}

	if len(thua) > 0 {
		sort.Strings(thua)
		t.Fatalf("sổ quyền còn %d đường mà router không còn đăng ký:\n  %s",
			len(thua), strings.Join(thua, "\n  "))
	}
}

// TestDuongMienQuyenDeuCoThat giữ cho danh sách miễn trừ không thành nghĩa địa.
//
// Một đường đã xoá mà vẫn nằm trong danh sách miễn trừ là chỗ để đường MỚI cùng
// tên lặng lẽ thừa hưởng suất miễn của nó.
func TestDuongMienQuyenDeuCoThat(t *testing.T) {
	h := dungHeThong(t)

	coThat := map[string]bool{}
	for _, rt := range h.r.Routes() {
		coThat[rt.Method+" "+rt.Path] = true
	}

	for khoa, lyDo := range duongMienQuyen {
		if !coThat[khoa] {
			t.Errorf("miễn quyền cho một đường không còn tồn tại: %s (%s)", khoa, lyDo)
		}
	}
}

// TestThieuQuyenTraVe403 — chiều dương của cả hệ: một tài khoản KHÔNG có quyền
// thì bị chặn, và bị chặn bằng đúng 403 kèm mã máy đọc được.
//
// Dùng tài khoản super admin của cửa hàng thử để dựng bối cảnh, rồi hạ nó xuống
// một nhóm quyền rỗng — vì super admin đi thẳng không tra bảng, nên phải đổi cả
// vai trò thì mới kiểm được chốt.
func TestThieuQuyenTraVe403(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	// Tài khoản "nhanvien" của cửa hàng thử đang có bộ quyền thu ngân. Thu sạch
	// để dựng cảnh cửa hàng thật đi qua khi họ bỏ hết tick.
	ctx := tenant.WithID(context.Background(), a.id)
	if err := h.db.WithContext(ctx).Where("user_id = ?", a.nhanVien).
		Delete(&domain.QuyenRieng{}).Error; err != nil {
		t.Fatalf("không thu được quyền của tài khoản: %v", err)
	}

	token := h.dangNhapVoi(t, a.ma, "nhanvien")

	// Hai đường của cụm quầy: chúng nằm ở nhóm `admin`, tức lượt gọi đi thẳng
	// tới chốt QUYỀN mới và phải nhận 403 kèm mã máy đọc được.
	for _, duong := range []struct{ method, path string }{
		{http.MethodGet, "/api/v1/admin/orders"},
		{http.MethodGet, "/api/v1/admin/ca-lam-viec"},
	} {
		res := h.goi(t, token, duong.method, duong.path, nil)
		if res.ma != http.StatusForbidden {
			t.Errorf("%s %s: nhóm không còn quyền nào phải nhận 403, nhận %d\n%s",
				duong.method, duong.path, res.ma, catBot(res.than))
		}
		if !contains(res.than, "THIEU_QUYEN") {
			t.Errorf("%s %s: lỗi 403 phải kèm mã THIEU_QUYEN để trang quản trị rẽ nhánh được, nhận: %s",
				duong.method, duong.path, catBot(res.than))
		}
	}

	// Đường của nhóm `manage` cũng bị chặn, nhưng bởi lớp CŨ (RequireRoles) đứng
	// trước — nên nó chưa mang mã THIEU_QUYEN.
	//
	// Hai lớp cùng chạy là trạng thái CÓ CHỦ Ý của bước này: lớp nào chặn trước
	// cũng được, và giữ lớp cũ nghĩa là lượt bật chốt mới không thể nới lỏng thứ
	// gì. Khi gỡ RequireRoles khỏi `manage`, sửa bài kiểm này thành đòi mã như
	// hai đường trên — nếu lúc đó nó vẫn xanh mà không cần sửa, nghĩa là chốt
	// mới chưa thật sự chặn đường ấy.
	if res := h.goi(t, token, http.MethodGet, "/api/v1/admin/nhan-su", nil); res.ma != http.StatusForbidden {
		t.Errorf("GET /admin/nhan-su: thu ngân không có quyền nhân sự phải nhận 403, nhận %d", res.ma)
	}

	// Cấp lại ĐÚNG MỘT quyền thì đúng một đường mở ra — và mở NGAY với chính
	// token cũ. Đó là toàn bộ lý do lượt đọc quyền không có cache.
	if err := h.db.WithContext(ctx).Create(&domain.QuyenRieng{
		UserID: a.nhanVien, Permission: "ca-lam-viec.xem",
	}).Error; err != nil {
		t.Fatalf("không cấp được quyền: %v", err)
	}

	if res := h.goi(t, token, http.MethodGet, "/api/v1/admin/ca-lam-viec", nil); res.ma != http.StatusOK {
		t.Fatalf("cấp quyền xong phải vào được ngay bằng token cũ, nhận %d\n%s", res.ma, catBot(res.than))
	}
	if res := h.goi(t, token, http.MethodGet, "/api/v1/admin/orders", nil); res.ma != http.StatusForbidden {
		t.Fatalf("quyền ca làm việc không được mở luôn đường đơn hàng, nhận %d", res.ma)
	}
}

// TestQuanTriVanDayDuQuyen — chốt hồi quy của lượt di trú.
//
// Quản trị viên mang cờ toàn quyền trên chính tài khoản, tức MỌI quyền hiện có
// và sẽ có. Bài này
// bảo đảm lượt bật chốt không lấy mất gì của quản trị viên: họ vào được cả ba
// cụm mà hôm qua họ vào được.
func TestQuanTriVanDayDuQuyen(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	token := h.dangNhapVoi(t, a.ma, "quantri")
	for _, duong := range []string{
		"/api/v1/admin/nhan-su",
		"/api/v1/admin/inventory",
		"/api/v1/admin/reports/revenue",
	} {
		if res := h.goi(t, token, http.MethodGet, duong, nil); res.ma == http.StatusForbidden {
			t.Errorf("quản trị viên bị chặn ở %s — nhóm toàn quyền không còn đủ quyền", duong)
		}
	}
}
