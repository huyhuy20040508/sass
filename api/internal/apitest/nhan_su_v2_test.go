package apitest

import (
	"encoding/json"
	"fmt"
	"net/http"
	"testing"
)

// Bài kiểm các luật nhân sự port từ v2 cũ: nhiều chi nhánh, trùng định danh,
// mật khẩu mặc định + đặt lại, chặn nghỉ việc khi còn ca, cờ ngoài phạm vi.

type hoSoTra struct {
	Data struct {
		ID               uint     `json:"id"`
		UserID           *uint    `json:"user_id"`
		ShopID           *uint    `json:"shop_id"`
		ShopIDs          string   `json:"shop_ids"`
		ShopNames        []string `json:"shop_names"`
		AllowOutsideArea bool     `json:"allow_outside_area"`
	} `json:"data"`
}

func docHoSo(t *testing.T, than string) hoSoTra {
	t.Helper()
	var ra hoSoTra
	if err := json.Unmarshal([]byte(than), &ra); err != nil {
		t.Fatalf("không đọc được phản hồi: %v\n%s", err, catBot(than))
	}

	return ra
}

func moChiNhanhThu(t *testing.T, h *heThong, a *cuaHang, ten string) uint {
	t.Helper()
	res := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/chi-nhanh", map[string]any{
		"name": ten + " " + a.vet, "phone": "0912345678", "address": "Hà Nội",
	})
	if res.ma != http.StatusCreated {
		t.Fatalf("mở chi nhánh phải trả 201, nhận %d\n%s", res.ma, catBot(res.than))
	}
	var cn struct {
		Data struct{ ID uint } `json:"data"`
	}
	_ = json.Unmarshal([]byte(res.than), &cn)

	return cn.Data.ID
}

// Một người làm được nhiều chi nhánh; chi nhánh chính là phần tử đầu.
func TestNhanSuNhieuChiNhanh(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	cn2 := moChiNhanhThu(t, h, a, "Kho 2")

	res := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/nhan-su", map[string]any{
		"full_name": "Người hai kho " + a.vet, "status": "dang_lam",
		"shop_ids": []uint{a.chiNhanh, cn2},
	})
	if res.ma != http.StatusCreated {
		t.Fatalf("thêm nhân sự hai chi nhánh phải trả 201, nhận %d\n%s", res.ma, catBot(res.than))
	}
	tao := docHoSo(t, res.than)
	muon := fmt.Sprintf("%d,%d", a.chiNhanh, cn2)
	if tao.Data.ShopID == nil || *tao.Data.ShopID != a.chiNhanh || tao.Data.ShopIDs != muon {
		t.Fatalf("chi nhánh ghi sai: shop_id=%v shop_ids=%q, muốn %d / %q", tao.Data.ShopID, tao.Data.ShopIDs, a.chiNhanh, muon)
	}
	if len(tao.Data.ShopNames) != 2 || !tao.Data.AllowOutsideArea {
		t.Fatalf("shop_names=%v allow=%v, muốn 2 tên và cho phép", tao.Data.ShopNames, tao.Data.AllowOutsideArea)
	}

	// Đứng ở chi nhánh 2 vẫn thấy người này trong danh sách.
	res = h.goiChiNhanh(t, a.token, cn2, http.MethodGet, "/api/v1/admin/nhan-su", nil)
	if res.ma != http.StatusOK {
		t.Fatalf("danh sách theo chi nhánh 2 trả %d\n%s", res.ma, catBot(res.than))
	}
	var ds struct {
		Data []struct{ ID uint } `json:"data"`
	}
	_ = json.Unmarshal([]byte(res.than), &ds)
	thay := false
	for _, r := range ds.Data {
		if r.ID == tao.Data.ID {
			thay = true
		}
	}
	if !thay {
		t.Fatalf("người làm cả kho 2 không hiện khi lọc kho 2: %s", catBot(res.than))
	}

	// Sửa: chỉ còn kho 2, tắt cờ ngoài phạm vi.
	duong := fmt.Sprintf("/api/v1/admin/nhan-su/%d", tao.Data.ID)
	res = h.goi(t, a.token, http.MethodPut, duong, map[string]any{
		"full_name": "Người hai kho " + a.vet, "status": "dang_lam",
		"shop_ids": []uint{cn2}, "allow_outside_area": false,
	})
	if res.ma != http.StatusOK {
		t.Fatalf("sửa phải trả 200, nhận %d\n%s", res.ma, catBot(res.than))
	}
	sua := docHoSo(t, res.than)
	if sua.Data.ShopID == nil || *sua.Data.ShopID != cn2 || sua.Data.ShopIDs != fmt.Sprintf("%d", cn2) || sua.Data.AllowOutsideArea {
		t.Fatalf("sửa chi nhánh sai: shop_id=%v shop_ids=%q allow=%v", sua.Data.ShopID, sua.Data.ShopIDs, sua.Data.AllowOutsideArea)
	}

	// Không gửi shop_ids thì shop_id vẫn đủ (đường gọi cũ).
	res = h.goi(t, a.token, http.MethodPost, "/api/v1/admin/nhan-su", map[string]any{
		"full_name": "Người một kho " + a.vet, "status": "dang_lam", "shop_id": a.chiNhanh,
	})
	if res.ma != http.StatusCreated {
		t.Fatalf("shop_id đơn phải vẫn tạo được, nhận %d\n%s", res.ma, catBot(res.than))
	}
	if x := docHoSo(t, res.than); x.Data.ShopIDs != fmt.Sprintf("%d", a.chiNhanh) {
		t.Fatalf("shop_ids suy từ shop_id sai: %q", x.Data.ShopIDs)
	}
}

// SĐT / CCCD / email không trùng người khác và phải đúng khuôn, như v2.
func TestNhanSuChanTrungDinhDanh(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	res := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/nhan-su", map[string]any{
		"full_name": "Người thứ nhất " + a.vet, "status": "dang_lam", "shop_id": a.chiNhanh,
		"phone": "0912345678", "id_number": "079200001234", "email": "mot." + a.vet + "@cua-hang-a.test",
	})
	if res.ma != http.StatusCreated {
		t.Fatalf("thêm người thứ nhất phải trả 201, nhận %d\n%s", res.ma, catBot(res.than))
	}

	loi := func(body map[string]any, o string) {
		t.Helper()
		body["full_name"] = "Người thứ hai " + a.vet
		body["status"] = "dang_lam"
		body["shop_id"] = a.chiNhanh
		x := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/nhan-su", body)
		if x.ma != http.StatusUnprocessableEntity {
			t.Fatalf("%s phải trả 422, nhận %d\n%s", o, x.ma, catBot(x.than))
		}
		var r struct {
			Errors map[string]any `json:"errors"`
		}
		_ = json.Unmarshal([]byte(x.than), &r)
		if _, ok := r.Errors[o]; !ok {
			t.Fatalf("lỗi phải nằm ở ô %q: %s", o, catBot(x.than))
		}
	}
	loi(map[string]any{"phone": "0912345678"}, "phone")
	loi(map[string]any{"id_number": "079200001234"}, "id_number")
	loi(map[string]any{"email": "mot." + a.vet + "@cua-hang-a.test"}, "email")
	loi(map[string]any{"phone": "12345"}, "phone")
	loi(map[string]any{"id_number": "07A200"}, "id_number")
}

// Đặt lại mật khẩu về mặc định (cấu hình hoặc mặc định phần mềm) và đổi mật
// khẩu cho tài khoản đã có qua ô mat_khau_moi.
func TestNhanSuDatLaiMatKhauMacDinh(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	tenDangNhap := "an.matkhau"
	res := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/nhan-su", map[string]any{
		"full_name": "Người đổi mật khẩu " + a.vet, "status": "dang_lam", "shop_id": a.chiNhanh,
		"email": "an.matkhau." + a.vet + "@cua-hang-a.test", "role_id": 3, "quyen": []string{"thu_ngan"},
		"tai_khoan": map[string]any{"username": tenDangNhap, "password": matKhauTest},
	})
	if res.ma != http.StatusCreated {
		t.Fatalf("thêm nhân sự kèm tài khoản phải trả 201, nhận %d\n%s", res.ma, catBot(res.than))
	}
	tao := docHoSo(t, res.than)

	dangNhap := func(matKhau string) int {
		t.Helper()
		x := h.goi(t, "", http.MethodPost, "/api/v1/auth/shop-login", map[string]any{
			"shop_code": a.ma, "username": tenDangNhap, "password": matKhau,
		})

		return x.ma
	}

	duongReset := fmt.Sprintf("/api/v1/admin/nhan-su/%d/dat-lai-mat-khau", tao.Data.ID)
	if x := h.goi(t, a.token, http.MethodPost, duongReset, nil); x.ma != http.StatusOK {
		t.Fatalf("đặt lại mật khẩu phải trả 200, nhận %d\n%s", x.ma, catBot(x.than))
	}
	if ma := dangNhap("Nhanvien@123"); ma != http.StatusOK {
		t.Fatalf("mật khẩu mặc định phần mềm phải đăng nhập được, nhận %d", ma)
	}

	// Cửa hàng cấu hình mật khẩu mặc định riêng.
	x := h.goi(t, a.token, http.MethodPut, "/api/v1/admin/settings", map[string]any{
		"items": map[string]string{"staff_default_password": "Tiem@2026"},
	})
	if x.ma != http.StatusOK {
		t.Fatalf("ghi cấu hình mật khẩu mặc định phải trả 200, nhận %d\n%s", x.ma, catBot(x.than))
	}
	if x = h.goi(t, a.token, http.MethodPost, duongReset, nil); x.ma != http.StatusOK {
		t.Fatalf("đặt lại lần hai phải trả 200, nhận %d\n%s", x.ma, catBot(x.than))
	}
	if ma := dangNhap("Tiem@2026"); ma != http.StatusOK {
		t.Fatalf("mật khẩu mặc định của cửa hàng phải đăng nhập được, nhận %d", ma)
	}
	if ma := dangNhap("Nhanvien@123"); ma == http.StatusOK {
		t.Fatalf("mật khẩu cũ vẫn vào được sau khi đặt lại")
	}

	// Đổi mật khẩu qua ô mat_khau_moi ở lượt sửa hồ sơ.
	x = h.goi(t, a.token, http.MethodPut, fmt.Sprintf("/api/v1/admin/nhan-su/%d", tao.Data.ID), map[string]any{
		"full_name": "Người đổi mật khẩu " + a.vet, "status": "dang_lam", "shop_id": a.chiNhanh,
		"email": "an.matkhau." + a.vet + "@cua-hang-a.test", "mat_khau_moi": "Moi@123456",
	})
	if x.ma != http.StatusOK {
		t.Fatalf("sửa kèm mật khẩu mới phải trả 200, nhận %d\n%s", x.ma, catBot(x.than))
	}
	if ma := dangNhap("Moi@123456"); ma != http.StatusOK {
		t.Fatalf("mật khẩu mới phải đăng nhập được, nhận %d", ma)
	}

	// Hồ sơ không có tài khoản thì không có gì để đặt lại.
	res = h.goi(t, a.token, http.MethodPost, "/api/v1/admin/nhan-su", map[string]any{
		"full_name": "Người không tài khoản " + a.vet, "status": "dang_lam", "shop_id": a.chiNhanh,
	})
	if res.ma != http.StatusCreated {
		t.Fatalf("thêm hồ sơ không tài khoản phải trả 201, nhận %d\n%s", res.ma, catBot(res.than))
	}
	khong := docHoSo(t, res.than)
	if x = h.goi(t, a.token, http.MethodPost, fmt.Sprintf("/api/v1/admin/nhan-su/%d/dat-lai-mat-khau", khong.Data.ID), nil); x.ma != http.StatusConflict {
		t.Fatalf("hồ sơ không tài khoản đặt lại mật khẩu phải trả 409, nhận %d\n%s", x.ma, catBot(x.than))
	}
}

// Còn ca chưa đóng thì chưa cho nghỉ việc — v2 chặn ở update/changeStatus.
func TestNhanSuChanNghiViecKhiConCa(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	res := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/nhan-su", map[string]any{
		"full_name": "Người còn ca " + a.vet, "status": "dang_lam", "shop_id": a.chiNhanh,
		"email": "an.conca." + a.vet + "@cua-hang-a.test", "role_id": 3, "quyen": []string{"thu_ngan"},
		"tai_khoan": map[string]any{"username": "an.conca", "password": matKhauTest},
	})
	if res.ma != http.StatusCreated {
		t.Fatalf("thêm nhân sự kèm tài khoản phải trả 201, nhận %d\n%s", res.ma, catBot(res.than))
	}
	tao := docHoSo(t, res.than)
	if tao.Data.UserID == nil {
		t.Fatalf("hồ sơ chưa gắn tài khoản: %s", catBot(res.than))
	}

	if err := h.db.WithContext(ctxThoat()).Exec(
		`INSERT INTO work_shifts (tenant_id, shop_id, opened_by, opened_at, opening_cash, created_at, updated_at)
		 VALUES (?, ?, ?, NOW(3), 0, NOW(3), NOW(3))`,
		a.id, a.chiNhanh, *tao.Data.UserID,
	).Error; err != nil {
		t.Fatalf("không dựng được ca đang mở: %v", err)
	}

	duong := fmt.Sprintf("/api/v1/admin/nhan-su/%d/trang-thai", tao.Data.ID)
	x := h.goi(t, a.token, http.MethodPut, duong, map[string]any{"status": "da_nghi"})
	if x.ma != http.StatusConflict {
		t.Fatalf("còn ca chưa đóng thì cho nghỉ phải trả 409, nhận %d\n%s", x.ma, catBot(x.than))
	}

	if err := h.db.WithContext(ctxThoat()).Exec(
		`UPDATE work_shifts SET closed_at = NOW(3), closed_by = ? WHERE opened_by = ?`,
		*tao.Data.UserID, *tao.Data.UserID,
	).Error; err != nil {
		t.Fatalf("không đóng được ca: %v", err)
	}
	if x = h.goi(t, a.token, http.MethodPut, duong, map[string]any{"status": "da_nghi"}); x.ma != http.StatusOK {
		t.Fatalf("đóng ca rồi thì cho nghỉ phải trả 200, nhận %d\n%s", x.ma, catBot(x.than))
	}
}
