package apitest

import (
	"encoding/json"
	"fmt"
	"net/http"
	"testing"

	"sass-api/internal/domain"
)

// Chuỗi đầy đủ của phân quyền theo chức năng, chạy qua API thật và MySQL thật:
// cửa hàng tự tạo một nhóm, tick đúng một quyền, gán cho nhân viên, rồi nhân
// viên đó mở được đúng phần việc ấy và không mở được gì khác.
//
// Đây là bài chứng minh cả tính năng có nghĩa. Từng mảnh riêng lẻ có thể xanh mà
// chuỗi vẫn đứt: nhóm ghi được nhưng quyền không tới người, hoặc quyền tới người
// nhưng chốt không đọc ra.
func TestNhomQuyenChuoiDayDu(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	admin := h.dangNhapVoi(t, a.ma, "quantri")

	// 1. Cây quyền — thứ màn hình vẽ ô tick.
	res := h.goi(t, admin, http.MethodGet, "/api/v1/admin/nhom-quyen/danh-muc", nil)
	if res.ma != http.StatusOK {
		t.Fatalf("đọc danh mục quyền phải trả 200, nhận %d\n%s", res.ma, catBot(res.than))
	}
	if !contains(res.than, "ton-kho") {
		t.Fatalf("danh mục quyền thiếu nhóm kho: %s", catBot(res.than))
	}
	// Tầng trên cùng là KHU: màn Phân quyền khoá cả khu Quản trị với người chỉ
	// có cửa quầy. Trộn hai khu vào một cây phẳng là bảng tick mở toang trở lại
	// mà không có gì đỏ lên.
	if !contains(res.than, `"ma":"thu_ngan"`) || !contains(res.than, `"ma":"quan_ly"`) {
		t.Fatalf("danh mục quyền chưa chia theo khu làm việc: %s", catBot(res.than))
	}

	// 2. Cửa hàng tự tạo nhóm "Thủ kho" — đúng thứ mà hệ hai vai trò cũ không
	// diễn đạt nổi: xem kho nhưng không đụng tới lương hay giá vốn.
	res = h.goi(t, admin, http.MethodPost, "/api/v1/admin/nhom-quyen", map[string]any{
		"name":  "Thủ kho " + a.vet,
		"quyen": []string{"ton-kho.xem"},
	})
	if res.ma != http.StatusCreated {
		t.Fatalf("tạo nhóm quyền phải trả 201, nhận %d\n%s", res.ma, catBot(res.than))
	}
	var tao struct {
		Data struct {
			ID         uint     `json:"id"`
			Code       string   `json:"code"`
			Quyen      []string `json:"quyen"`
			FullAccess bool     `json:"full_access"`
			IsSystem   bool     `json:"is_system"`
		} `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &tao); err != nil {
		t.Fatalf("không đọc được phản hồi: %v", err)
	}
	if tao.Data.Code == "" || len(tao.Data.Quyen) != 1 || tao.Data.Quyen[0] != "ton-kho.xem" {
		t.Fatalf("nhóm vừa tạo không đúng: %+v", tao.Data)
	}
	if tao.Data.FullAccess || tao.Data.IsSystem {
		t.Fatalf("nhóm cửa hàng tự tạo không được mang cờ hệ thống hay toàn quyền: %+v", tao.Data)
	}

	// 3. Quyền lạ bị chặn kèm tên ô — không ghi xuống rồi im lặng.
	res = h.goi(t, admin, http.MethodPut,
		fmt.Sprintf("/api/v1/admin/nhom-quyen/%d/quyen", tao.Data.ID),
		map[string]any{"quyen": []string{"ton-kho.xem", "ton-kho.xemm"}})
	if res.ma != http.StatusUnprocessableEntity || !contains(res.than, "quyen") {
		t.Fatalf("chuỗi quyền gõ sai phải trả 422 chỉ vào ô quyen, nhận %d\n%s", res.ma, catBot(res.than))
	}

	// 4. Cấp quyền THẲNG cho nhân viên (migration 0017): danh sách gửi lên là
	// toàn bộ quyền của người đó sau lượt này. Lấy bộ thu ngân cũ rồi thêm một
	// quyền kho — đúng việc màn hình làm khi tick thêm một ô.
	duongQuyen := fmt.Sprintf("/api/v1/admin/users/%d/quyen", a.nhanVien)
	res = h.goi(t, admin, http.MethodPut, duongQuyen, map[string]any{
		"quyen": append(domain.QuyenThuNgan(), "ton-kho.xem"),
	})
	if res.ma != http.StatusOK {
		t.Fatalf("cấp quyền phải trả 200, nhận %d\n%s", res.ma, catBot(res.than))
	}

	// 5. Và đây là phần chứng minh: nhân viên mở được kho, vẫn bán được hàng, và
	// KHÔNG đụng được vào nhân sự.
	nv := h.dangNhapVoi(t, a.ma, "nhanvien")

	// Chuỗi nối liền: tick trên màn hình -> user_permissions -> tập quyền lúc chạy.
	res = h.goi(t, nv, http.MethodGet, "/api/v1/admin/quyen-cua-toi", nil)
	if res.ma != http.StatusOK {
		t.Fatalf("đọc quyền của chính mình phải trả 200, nhận %d\n%s", res.ma, catBot(res.than))
	}
	if !contains(res.than, "ton-kho.xem") {
		t.Fatalf("quyền vừa tick thêm không tới được người dùng: %s", catBot(res.than))
	}
	if !contains(res.than, "don-hang.xem") {
		t.Fatalf("lượt cấp đã lấy mất quyền cũ gửi kèm trong danh sách: %s", catBot(res.than))
	}

	// Đường quầy vẫn mở (nhóm Thu ngân), đường nhân sự vẫn đóng.
	if res := h.goi(t, nv, http.MethodGet, "/api/v1/admin/orders", nil); res.ma == http.StatusForbidden {
		t.Errorf("gán thêm nhóm đã lấy mất quyền bán hàng của nhóm Thu ngân")
	}
	if res := h.goi(t, nv, http.MethodGet, "/api/v1/admin/nhan-su", nil); res.ma != http.StatusForbidden {
		t.Errorf("nhóm Thủ kho không được mở đường nhân sự, nhận %d", res.ma)
	}

	// 6. CHƯA kiểm được ở đây: /admin/inventory vẫn nằm sau lớp RequireRoles cũ
	// (nhóm `manage` = super_admin + admin), nên thu ngân mang nhóm Thủ kho vẫn
	// bị chặn dù đã có đúng quyền `ton-kho.xem`.
	//
	// Đó là trạng thái CÓ CHỦ Ý của giai đoạn hai lớp: lớp cũ chưa gỡ nên lượt
	// bật chốt mới không thể nới lỏng thứ gì. Khi gỡ RequireRoles khỏi `manage`,
	// thêm vào đây một khẳng định `/admin/inventory` KHÔNG còn 403 — nếu lúc đó
	// nó vẫn 403 thì nghĩa là quyền chưa thật sự mở được đường nào.

	// 7. Nhóm chỉ là MẪU nên xoá lúc nào cũng được — không lấy đi quyền của ai.
	res = h.goi(t, admin, http.MethodDelete,
		fmt.Sprintf("/api/v1/admin/nhom-quyen/%d", tao.Data.ID), nil)
	if res.ma != http.StatusOK {
		t.Fatalf("xoá nhóm mẫu phải trả 200, nhận %d\n%s", res.ma, catBot(res.than))
	}

	// Và người đã được cấp quyền vẫn giữ nguyên quyền của họ.
	res = h.goi(t, nv, http.MethodGet, "/api/v1/admin/quyen-cua-toi", nil)
	if !contains(res.than, "ton-kho.xem") {
		t.Fatalf("xoá nhóm mẫu đã lấy mất quyền của người dùng: %s", catBot(res.than))
	}
}

// Nhóm hệ thống sửa được nhưng KHÔNG xoá được — xoá nhóm cuối cùng còn quyền
// quản lý là tự khoá mình ra khỏi chính cửa hàng mình.
func TestNhomQuyenHeThongKhongXoaDuoc(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	admin := h.dangNhapVoi(t, a.ma, "quantri")
	res := h.goi(t, admin, http.MethodDelete,
		fmt.Sprintf("/api/v1/admin/nhom-quyen/%d", a.nhomQuanLy), nil)
	if res.ma != http.StatusUnprocessableEntity && res.ma != http.StatusConflict {
		t.Fatalf("xoá nhóm hệ thống phải bị từ chối, nhận %d\n%s", res.ma, catBot(res.than))
	}
}

// Nhóm quyền của tiệm này KHÔNG được rò sang tiệm khác — bộ lọc tenant phải
// chặn cả lượt đọc lẫn lượt gán.
func TestNhomQuyenCoLapGiuaHaiCuaHang(t *testing.T) {
	h := dungHeThong(t)
	a, b := haiCuaHang(t, h)

	tokenA := h.dangNhapVoi(t, a.ma, "quantri")

	// Đọc nhóm của tiệm B bằng token tiệm A.
	res := h.goi(t, tokenA, http.MethodGet,
		fmt.Sprintf("/api/v1/admin/nhom-quyen/%d", b.nhomThuNgan), nil)
	if res.ma != http.StatusNotFound {
		t.Fatalf("đọc nhóm của tiệm khác phải trả 404, nhận %d", res.ma)
	}

	// Và cấp quyền cho người của tiệm B bằng token tiệm A: phải hỏng, không được
	// lặng lẽ nhận. Đây là đường mà một id đoán bừa có thể mở toang quyền ở tiệm
	// khác.
	res = h.goi(t, tokenA, http.MethodPut,
		fmt.Sprintf("/api/v1/admin/users/%d/quyen", b.nhanVien),
		map[string]any{"quyen": []string{"ton-kho.xem"}})
	if res.ma != http.StatusNotFound {
		t.Fatalf("cấp quyền cho người của tiệm khác phải trả 404, nhận %d\n%s", res.ma, catBot(res.than))
	}
}

// Tài khoản mới cấp KHÔNG tự có quyền nào, và quyền chỉ tới sau khi có người
// tick cho họ.
//
// Bài này canh đúng kiểu lỗi nguy hiểm nhất của một hệ phân quyền: người mới
// lặng lẽ nhận sẵn một bộ quyền mà không ai định cấp.
func TestTaiKhoanMoiKhongTuCoQuyen(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	admin := h.dangNhapVoi(t, a.ma, "quantri")

	res := h.goi(t, admin, http.MethodPost, "/api/v1/admin/nhan-su", map[string]any{
		"full_name": "Trần Thị Bình " + a.vet,
		"position":  "thu_ngan",
		"status":    "dang_lam",
		"shop_id":   a.chiNhanh,
		"email":     "binh.nq." + a.vet + "@cua-hang-a.test",
		"role_id":   3,
		"tai_khoan": map[string]any{"username": "binh.nq", "password": "MatKhau@123"},
	})
	if res.ma != http.StatusCreated {
		t.Fatalf("thêm nhân sự kèm tài khoản phải trả 201, nhận %d: %s", res.ma, catBot(res.than))
	}

	var tao struct {
		Data struct {
			ID     uint  `json:"id"`
			UserID *uint `json:"user_id"`
		} `json:"data"`
	}
	_ = json.Unmarshal([]byte(res.than), &tao)
	if tao.Data.UserID == nil {
		t.Fatalf("hồ sơ chưa gắn tài khoản: %+v", tao.Data)
	}

	duong := fmt.Sprintf("/api/v1/admin/users/%d/quyen", *tao.Data.UserID)

	res = h.goi(t, admin, http.MethodGet, duong, nil)
	if res.ma != http.StatusOK {
		t.Fatalf("đọc quyền của tài khoản phải trả 200, nhận %d: %s", res.ma, catBot(res.than))
	}

	var doc struct {
		Data struct {
			ToanQuyen bool     `json:"toan_quyen"`
			Quyen     []string `json:"quyen"`
		} `json:"data"`
	}
	_ = json.Unmarshal([]byte(res.than), &doc)
	if doc.Data.ToanQuyen || len(doc.Data.Quyen) != 0 {
		t.Fatalf("tài khoản mới cấp không được tự có quyền nào, nhận %+v", doc.Data)
	}

	// Tick cho họ hai quyền rồi đọc lại — đúng đường mà màn hình đi.
	res = h.goi(t, admin, http.MethodPut, duong,
		map[string]any{"quyen": []string{"don-hang.xem", "ton-kho.xem"}})
	if res.ma != http.StatusOK {
		t.Fatalf("cấp quyền phải trả 200, nhận %d: %s", res.ma, catBot(res.than))
	}

	res = h.goi(t, admin, http.MethodGet, duong, nil)
	doc.Data.Quyen = nil
	_ = json.Unmarshal([]byte(res.than), &doc)
	if len(doc.Data.Quyen) != 2 {
		t.Fatalf("phải đọc lại đúng hai quyền vừa tick, nhận %v", doc.Data.Quyen)
	}
}

// Không ai tự sửa quyền của CHÍNH MÌNH: phiên đang chạy bằng token cũ nên màn
// hình vẫn trông bình thường, tới lần đăng nhập sau mới phát hiện mất đường vào.
func TestKhongTuSuaQuyenCuaChinhMinh(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	admin := h.dangNhapVoi(t, a.ma, "quantri")

	res := h.goi(t, admin, http.MethodPut,
		fmt.Sprintf("/api/v1/admin/users/%d/quyen", a.quanTri),
		map[string]any{"quyen": []string{"don-hang.xem"}})
	if res.ma != http.StatusForbidden {
		t.Fatalf("tự sửa quyền của mình phải trả 403, nhận %d\n%s", res.ma, catBot(res.than))
	}
}
