package apitest

import (
	"encoding/json"
	"fmt"
	"net/http"
	"testing"
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

	// 4. Gán nhóm cho nhân viên. Người này ĐANG mang nhóm Thu ngân — gán thêm chứ
	// không thay, vì một người mang được nhiều nhóm.
	duongGan := fmt.Sprintf("/api/v1/admin/users/%d/nhom-quyen", a.nhanVien)
	res = h.goi(t, admin, http.MethodPut, duongGan, map[string]any{
		"nhom_quyen": []uint{a.nhomThuNgan, tao.Data.ID},
	})
	if res.ma != http.StatusOK {
		t.Fatalf("gán nhóm phải trả 200, nhận %d\n%s", res.ma, catBot(res.than))
	}

	// 5. Và đây là phần chứng minh: nhân viên mở được kho, vẫn bán được hàng, và
	// KHÔNG đụng được vào nhân sự.
	nv := h.dangNhapVoi(t, a.ma, "nhanvien")

	// Quyền của chính mình phải là HỢP của hai nhóm. Đây là chỗ chứng minh chuỗi
	// nối liền: tick trên màn hình -> bảng nhóm -> bảng nối -> tập quyền lúc chạy.
	res = h.goi(t, nv, http.MethodGet, "/api/v1/admin/quyen-cua-toi", nil)
	if res.ma != http.StatusOK {
		t.Fatalf("đọc quyền của chính mình phải trả 200, nhận %d\n%s", res.ma, catBot(res.than))
	}
	if !contains(res.than, "ton-kho.xem") {
		t.Fatalf("quyền của nhóm Thủ kho vừa gán không tới được người dùng: %s", catBot(res.than))
	}
	if !contains(res.than, "don-hang.xem") {
		t.Fatalf("gán thêm nhóm đã lấy mất quyền của nhóm cũ — hai nhóm phải CỘNG vào nhau: %s",
			catBot(res.than))
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

	// 7. Nhóm đang có người mang thì không xoá được, và câu từ chối nói ra con số.
	res = h.goi(t, admin, http.MethodDelete,
		fmt.Sprintf("/api/v1/admin/nhom-quyen/%d", tao.Data.ID), nil)
	if res.ma != http.StatusConflict {
		t.Fatalf("xoá nhóm còn người dùng phải trả 409, nhận %d\n%s", res.ma, catBot(res.than))
	}
	if !contains(res.than, "1 tài khoản") {
		t.Fatalf("câu từ chối phải nói còn mấy người đang dùng, nhận: %s", catBot(res.than))
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

	// Và gán nhóm của tiệm B cho người của tiệm A: phải hỏng, không được lặng lẽ
	// nhận. Đây là đường mà một id đoán bừa có thể mở toang quyền theo bảng của
	// tiệm khác.
	res = h.goi(t, tokenA, http.MethodPut,
		fmt.Sprintf("/api/v1/admin/users/%d/nhom-quyen", a.nhanVien),
		map[string]any{"nhom_quyen": []uint{b.nhomQuanLy}})
	if res.ma != http.StatusNotFound {
		t.Fatalf("gán nhóm của tiệm khác phải trả 404, nhận %d\n%s", res.ma, catBot(res.than))
	}
}

// Hồ sơ nhân sự phải TRẢ VỀ nhóm quyền của tài khoản gắn với nó.
//
// Bài này canh đúng kiểu lỗi đã xảy ra một lần: dữ liệu ghi đúng dưới database
// nhưng mất trên đường về màn hình. Hộp thoại sửa dùng chính danh sách này để
// tick sẵn, nên thiếu nó thì mở hồ sơ ra rồi bấm Lưu là thu sạch quyền của
// người ta — một lượt bấm bình thường, không ai định làm vậy, không có gì báo.
func TestHoSoNhanSuTraKemNhomQuyen(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	admin := h.dangNhapVoi(t, a.ma, "quantri")

	// Hồ sơ có tài khoản — nhóm quyền chỉ có nghĩa khi người đó đăng nhập được.
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
			ID        uint   `json:"id"`
			UserID    *uint  `json:"user_id"`
			NhomQuyen []uint `json:"nhom_quyen"`
		} `json:"data"`
	}
	_ = json.Unmarshal([]byte(res.than), &tao)
	if tao.Data.UserID == nil {
		t.Fatalf("hồ sơ chưa gắn tài khoản: %+v", tao.Data)
	}
	// Tài khoản vừa cấp chưa được giao nhóm nào.
	if len(tao.Data.NhomQuyen) != 0 {
		t.Fatalf("tài khoản mới cấp không được tự mang nhóm nào, nhận %v", tao.Data.NhomQuyen)
	}

	// Giao HAI nhóm cùng lúc — đó là điều một ô chọn đơn không diễn đạt nổi.
	res = h.goi(t, admin, http.MethodPut,
		fmt.Sprintf("/api/v1/admin/users/%d/nhom-quyen", *tao.Data.UserID),
		map[string]any{"nhom_quyen": []uint{a.nhomThuNgan, a.nhomQuanLy}})
	if res.ma != http.StatusOK {
		t.Fatalf("gán nhóm phải trả 200, nhận %d: %s", res.ma, catBot(res.than))
	}

	// Và đọc lại từ DANH SÁCH — đúng đường mà màn hình đi.
	res = h.goi(t, admin, http.MethodGet, "/api/v1/admin/nhan-su", nil)
	if res.ma != http.StatusOK {
		t.Fatalf("danh sách nhân sự phải trả 200, nhận %d", res.ma)
	}

	var ds struct {
		Data []struct {
			ID        uint   `json:"id"`
			NhomQuyen []uint `json:"nhom_quyen"`
		} `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &ds); err != nil {
		t.Fatalf("không đọc được danh sách: %v", err)
	}

	thay := false
	for _, ns := range ds.Data {
		if ns.ID != tao.Data.ID {
			continue
		}
		thay = true
		if len(ns.NhomQuyen) != 2 {
			t.Fatalf("hồ sơ phải trả về đủ HAI nhóm vừa giao, nhận %v", ns.NhomQuyen)
		}
	}
	if !thay {
		t.Fatal("không thấy hồ sơ vừa tạo trong danh sách")
	}
}
