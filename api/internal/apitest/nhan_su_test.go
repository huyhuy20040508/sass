package apitest

import (
	"encoding/json"
	"fmt"
	"net/http"
	"testing"
)

// Bài kiểm ĐƯỜNG NHÂN SỰ chạy qua API thật và MySQL thật.
//
// Phần dễ hỏng nhất của một bảng mới không nằm ở luật nghiệp vụ mà nằm ở chỗ
// nối: entity có map đúng tên cột không, mấy cột ENUM và DATE có nhận đúng thứ
// tầng Go gửi xuống không, bộ lọc tenant có tự điền `tenant_id` không, và mã
// nhân viên có thật sự chỉ duy nhất TRONG một cửa hàng không. Sổ giả trả lời
// "có" cho cả bốn mà không chứng minh gì.
func TestNhanSuThemDuocHoSo(t *testing.T) {
	h := dungHeThong(t)
	a, b := haiCuaHang(t, h)

	res := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/nhan-su", map[string]any{
		"full_name":     "Nguyễn Văn An " + a.vet,
		"position":      "thu_ngan",
		"status":        "dang_lam",
		"shop_id":       a.chiNhanh,
		"phone":         "0912345678",
		"birth_date":    "1998-08-23",
		"hired_on":      "2026-08-17",
		"contract_type": "thu_viec",
		"salary_type":   "thang",
		"salary":        8000000,
	})
	if res.ma != http.StatusCreated {
		t.Fatalf("thêm nhân sự phải trả 201, nhận %d\n%s", res.ma, catBot(res.than))
	}

	var tao struct {
		Data struct {
			ID   uint   `json:"id"`
			Code string `json:"code"`
			// Mấy ô NULL được dưới database — đọc lại để chắc chúng không vỡ ở tầng
			// driver và không bị ghi thành chuỗi rỗng.
			Phone        string  `json:"phone"`
			ContractType string  `json:"contract_type"`
			Salary       float64 `json:"salary"`
			// Username rỗng: hồ sơ này không xin cấp tài khoản.
			Username string `json:"username"`
		} `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &tao); err != nil {
		t.Fatalf("không đọc được phản hồi: %v\n%s", err, catBot(res.than))
	}
	// Cửa hàng mới chưa có ai nên mã tự sinh là NV0001.
	if tao.Data.Code != "NV0001" {
		t.Fatalf("mã tự sinh là %q, đáng lẽ NV0001", tao.Data.Code)
	}
	if tao.Data.Phone != "0912345678" || tao.Data.ContractType != "thu_viec" || tao.Data.Salary != 8000000 {
		t.Fatalf("dữ liệu ghi không đúng: %+v", tao.Data)
	}
	if tao.Data.Username != "" {
		t.Fatalf("hồ sơ không xin tài khoản mà lại có tên đăng nhập %q", tao.Data.Username)
	}

	// Mã chỉ duy nhất TRONG một cửa hàng: cửa hàng B tạo hồ sơ đầu tiên cũng phải
	// nhận NV0001, không bị mã của A chặn.
	res = h.goi(t, b.token, http.MethodPost, "/api/v1/admin/nhan-su", map[string]any{
		"full_name": "Trần Thị Bình " + b.vet, "position": "quan_ly", "status": "dang_lam",
		"shop_id": b.chiNhanh,
	})
	if res.ma != http.StatusCreated {
		t.Fatalf("cửa hàng B thêm nhân sự phải trả 201, nhận %d\n%s", res.ma, catBot(res.than))
	}
	var taoB struct {
		Data struct{ Code string } `json:"data"`
	}
	_ = json.Unmarshal([]byte(res.than), &taoB)
	if taoB.Data.Code != "NV0001" {
		t.Fatalf("mã của cửa hàng B là %q, đáng lẽ cũng NV0001 (mã chỉ duy nhất trong một tenant)", taoB.Data.Code)
	}

	// Danh sách của A chỉ thấy người của A.
	res = h.goi(t, a.token, http.MethodGet, "/api/v1/admin/nhan-su", nil)
	if res.ma != http.StatusOK {
		t.Fatalf("danh sách nhân sự phải trả 200, nhận %d\n%s", res.ma, catBot(res.than))
	}
	var ds struct {
		Data []struct {
			ID       uint   `json:"id"`
			FullName string `json:"full_name"`
		} `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &ds); err != nil {
		t.Fatalf("không đọc được danh sách: %v\n%s", err, catBot(res.than))
	}
	if len(ds.Data) != 1 || ds.Data[0].ID != tao.Data.ID {
		t.Fatalf("danh sách của A phải có đúng hồ sơ vừa tạo, nhận %+v", ds.Data)
	}

	// Trùng mã trong CÙNG cửa hàng thì chặn, và chặn bằng 422 kèm tên ô — không
	// phải 500 của MySQL.
	res = h.goi(t, a.token, http.MethodPost, "/api/v1/admin/nhan-su", map[string]any{
		"full_name": "Người thứ hai", "position": "quan_ly", "status": "dang_lam", "code": "NV0001",
		"shop_id": a.chiNhanh,
	})
	if res.ma != http.StatusConflict && res.ma != http.StatusUnprocessableEntity {
		t.Fatalf("trùng mã phải trả 409/422, nhận %d\n%s", res.ma, catBot(res.than))
	}
}

// Chức danh lạ phải bị chặn ở tầng Go kèm tên ô.
//
// Nếu để lọt xuống cột ENUM thì MySQL (chế độ nghiêm) ném lỗi 1265 và người dùng
// nhận về "Đã có lỗi xảy ra" — đúng lúc họ cần biết ô nào sai nhất.
func TestNhanSuChanChucDanhLa(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	res := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/nhan-su", map[string]any{
		"full_name": "Người lạ", "position": "giam_doc_dieu_hanh", "status": "dang_lam",
		"shop_id": a.chiNhanh,
	})
	if res.ma != http.StatusUnprocessableEntity {
		t.Fatalf("chức danh lạ phải trả 422, nhận %d\n%s", res.ma, catBot(res.than))
	}
	if !contains(res.than, "position") {
		t.Fatalf("lỗi 422 phải chỉ đúng ô position, nhận: %s", catBot(res.than))
	}
}

// Cấp tài khoản NGAY trong hồ sơ: người mới vào làm có luôn chỗ đăng nhập.
//
// Đây là lý do module nhân sự thay được trang "Người dùng & vai trò": nếu khối
// này không chạy thì chủ tiệm tuyển người xong vẫn phải đi tìm một màn hình khác
// để cấp tài khoản, mà màn hình đó đã bị bỏ khỏi menu.
func TestNhanSuCapTaiKhoanTrongHoSo(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	tenDangNhap := "an.thungan"
	res := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/nhan-su", map[string]any{
		"full_name": "Nguyễn Văn An " + a.vet,
		"position":  "thu_ngan",
		"status":    "dang_lam",
		"shop_id":   a.chiNhanh,
		"email":     "an." + a.vet + "@cua-hang-a.test",
		"role_id":   3,
		"tai_khoan": map[string]any{
			"username": tenDangNhap,
			"password": "MatKhau@123",
		},
	})
	if res.ma != http.StatusCreated {
		t.Fatalf("thêm nhân sự kèm tài khoản phải trả 201, nhận %d\n%s", res.ma, catBot(res.than))
	}

	var tao struct {
		Data struct {
			ID       uint   `json:"id"`
			UserID   *uint  `json:"user_id"`
			Username string `json:"username"`
			RoleID   uint   `json:"role_id"`
		} `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &tao); err != nil {
		t.Fatalf("không đọc được phản hồi: %v\n%s", err, catBot(res.than))
	}
	if tao.Data.UserID == nil || tao.Data.Username != tenDangNhap || tao.Data.RoleID != 3 {
		t.Fatalf("hồ sơ chưa gắn đúng tài khoản vừa cấp: %+v", tao.Data)
	}

	// Và tài khoản đó ĐĂNG NHẬP ĐƯỢC thật — phần duy nhất chứng minh khối này có
	// nghĩa. Ghi được một dòng users mà không vào nổi phần mềm thì vô ích.
	res = h.goi(t, "", http.MethodPost, "/api/v1/auth/shop-login", map[string]any{
		"shop_code": a.ma, "username": tenDangNhap, "password": "MatKhau@123",
	})
	if res.ma != http.StatusOK {
		t.Fatalf("tài khoản vừa cấp phải đăng nhập được, nhận %d\n%s", res.ma, catBot(res.than))
	}

	// Hồ sơ không có email thì KHÔNG cấp được tài khoản: bảng users bắt buộc
	// email. Từ chối kèm tên ô, tuyệt đối không tự bịa một địa chỉ nội bộ.
	res = h.goi(t, a.token, http.MethodPost, "/api/v1/admin/nhan-su", map[string]any{
		"full_name": "Người không email",
		"position":  "quan_ly",
		"status":    "dang_lam",
		"shop_id":   a.chiNhanh,
		"role_id":   3,
		"tai_khoan": map[string]any{"username": "khong.email"},
	})
	if res.ma != http.StatusUnprocessableEntity || !contains(res.than, "email") {
		t.Fatalf("cấp tài khoản mà thiếu email phải trả 422 chỉ vào ô email, nhận %d\n%s", res.ma, catBot(res.than))
	}
}

// Công tắc trạng thái trên bảng danh sách: gạt một cái đổi đúng một cột.
//
// Kiểm cả chiều NGƯỢC LẠI nữa — sau lượt gạt, mọi trường khác của hồ sơ phải
// giữ nguyên. Đó là toàn bộ lý do đường này tồn tại thay vì dùng lại PUT hồ sơ:
// một cú bấm công tắc không được phép ghi đè lương hay chi nhánh bằng bản dữ
// liệu cũ mà màn hình đang cầm.
func TestNhanSuCongTacTrangThai(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	res := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/nhan-su", map[string]any{
		"full_name": "Nguyễn Văn An " + a.vet, "position": "thu_ngan", "status": "dang_lam",
		"shop_id": a.chiNhanh, "salary": 8000000, "phone": "0912345678",
	})
	if res.ma != http.StatusCreated {
		t.Fatalf("thêm nhân sự phải trả 201, nhận %d\n%s", res.ma, catBot(res.than))
	}
	var tao struct {
		Data struct {
			ID uint `json:"id"`
		} `json:"data"`
	}
	_ = json.Unmarshal([]byte(res.than), &tao)

	duong := fmt.Sprintf("/api/v1/admin/nhan-su/%d/trang-thai", tao.Data.ID)
	res = h.goi(t, a.token, http.MethodPut, duong, map[string]any{"status": "da_nghi"})
	if res.ma != http.StatusOK {
		t.Fatalf("đổi trạng thái phải trả 200, nhận %d\n%s", res.ma, catBot(res.than))
	}

	var sau struct {
		Data struct {
			Status   string  `json:"status"`
			Salary   float64 `json:"salary"`
			Phone    string  `json:"phone"`
			FullName string  `json:"full_name"`
		} `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &sau); err != nil {
		t.Fatalf("không đọc được phản hồi: %v\n%s", err, catBot(res.than))
	}
	if sau.Data.Status != "da_nghi" {
		t.Fatalf("trạng thái sau khi gạt là %q, đáng lẽ da_nghi", sau.Data.Status)
	}
	if sau.Data.Salary != 8000000 || sau.Data.Phone != "0912345678" {
		t.Fatalf("lượt gạt công tắc đã đụng vào phần còn lại của hồ sơ: %+v", sau.Data)
	}

	// Trạng thái lạ thì chặn kèm tên ô, không để lọt xuống cột ENUM.
	res = h.goi(t, a.token, http.MethodPut, duong, map[string]any{"status": "nghi_choi"})
	if res.ma != http.StatusUnprocessableEntity || !contains(res.than, "status") {
		t.Fatalf("trạng thái lạ phải trả 422 chỉ vào ô status, nhận %d\n%s", res.ma, catBot(res.than))
	}
}

// Chi nhánh là BẮT BUỘC: hồ sơ không khai nơi làm việc thì báo cáo theo chi
// nhánh và bảng chấm công sau này không xếp được người đó vào đâu cả.
func TestNhanSuBatBuocChiNhanh(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	res := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/nhan-su", map[string]any{
		"full_name": "Người chưa có chỗ", "position": "quan_ly", "status": "dang_lam",
	})
	if res.ma != http.StatusUnprocessableEntity || !contains(res.than, "shop_id") {
		t.Fatalf("thiếu chi nhánh phải trả 422 chỉ vào ô shop_id, nhận %d\n%s", res.ma, catBot(res.than))
	}

	// Và chi nhánh của cửa hàng KHÁC thì vẫn là không tìm thấy, không phải "được
	// nhưng gắn nhầm".
	res = h.goi(t, a.token, http.MethodPost, "/api/v1/admin/nhan-su", map[string]any{
		"full_name": "Người của tiệm khác", "position": "quan_ly", "status": "dang_lam",
		"shop_id": 999999,
	})
	if res.ma != http.StatusNotFound {
		t.Fatalf("chi nhánh lạ phải trả 404, nhận %d\n%s", res.ma, catBot(res.than))
	}
}

// Nghỉ việc thì KHÔNG đăng nhập được nữa.
//
// Đây là bài kiểm quan trọng nhất của module: trước khi có nó, gạt công tắc sang
// "đã nghỉ" chỉ đổi cột employees.status, còn dòng trong `users` vẫn active —
// người nghỉ hôm qua vẫn mở được quầy bán bằng mật khẩu cũ. Bài này chứng minh
// bằng thứ duy nhất đáng tin: thử ĐĂNG NHẬP THẬT sau mỗi lượt gạt.
//
// Chiều ngược lại cố ý không đối xứng: nhận người cũ làm lại KHÔNG tự mở tài
// khoản, phải nói rõ `mo_tai_khoan`.
func TestNhanSuNghiViecThiKhoaTaiKhoan(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	tenDangNhap := "an.nghiviec"
	matKhau := "MatKhau@123"
	res := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/nhan-su", map[string]any{
		"full_name": "Nguyễn Văn An " + a.vet,
		"position":  "thu_ngan",
		"status":    "dang_lam",
		"shop_id":   a.chiNhanh,
		"email":     "an.nghiviec." + a.vet + "@cua-hang-a.test",
		"role_id":   3,
		"tai_khoan": map[string]any{"username": tenDangNhap, "password": matKhau},
	})
	if res.ma != http.StatusCreated {
		t.Fatalf("thêm nhân sự kèm tài khoản phải trả 201, nhận %d\n%s", res.ma, catBot(res.than))
	}
	var tao struct {
		Data struct {
			ID         uint   `json:"id"`
			UserStatus string `json:"user_status"`
		} `json:"data"`
	}
	_ = json.Unmarshal([]byte(res.than), &tao)
	if tao.Data.UserStatus != "active" {
		t.Fatalf("người vừa tuyển phải có tài khoản đang mở, nhận %q", tao.Data.UserStatus)
	}

	dangNhap := func() int {
		return h.goi(t, "", http.MethodPost, "/api/v1/auth/shop-login", map[string]any{
			"shop_code": a.ma, "username": tenDangNhap, "password": matKhau,
		}).ma
	}
	if ma := dangNhap(); ma != http.StatusOK {
		t.Fatalf("người đang làm phải đăng nhập được, nhận %d", ma)
	}

	duong := fmt.Sprintf("/api/v1/admin/nhan-su/%d/trang-thai", tao.Data.ID)
	res = h.goi(t, a.token, http.MethodPut, duong, map[string]any{"status": "da_nghi"})
	if res.ma != http.StatusOK {
		t.Fatalf("đánh dấu nghỉ việc phải trả 200, nhận %d\n%s", res.ma, catBot(res.than))
	}
	var sau struct {
		Data struct {
			Status     string `json:"status"`
			UserStatus string `json:"user_status"`
		} `json:"data"`
	}
	_ = json.Unmarshal([]byte(res.than), &sau)
	if sau.Data.Status != "da_nghi" || sau.Data.UserStatus != "inactive" {
		t.Fatalf("nghỉ việc phải kéo theo khoá tài khoản, nhận %+v", sau.Data)
	}
	// Phần chứng minh: mật khẩu cũ không còn mở được cửa nữa.
	if ma := dangNhap(); ma == http.StatusOK {
		t.Fatal("người đã nghỉ việc vẫn đăng nhập được — tài khoản chưa bị khoá")
	}

	// Nhận lại người cũ mà KHÔNG nói gì thêm: hồ sơ đi làm lại, tài khoản vẫn khoá.
	res = h.goi(t, a.token, http.MethodPut, duong, map[string]any{"status": "dang_lam"})
	if res.ma != http.StatusOK {
		t.Fatalf("đặt lại đang làm phải trả 200, nhận %d\n%s", res.ma, catBot(res.than))
	}
	_ = json.Unmarshal([]byte(res.than), &sau)
	if sau.Data.Status != "dang_lam" || sau.Data.UserStatus != "inactive" {
		t.Fatalf("mở lại tài khoản phải là quyết định riêng, không tự chạy theo: %+v", sau.Data)
	}
	if ma := dangNhap(); ma == http.StatusOK {
		t.Fatal("tài khoản tự mở lại chỉ vì hồ sơ được đặt lại thành đang làm")
	}

	// Nói rõ thì mới mở.
	res = h.goi(t, a.token, http.MethodPut, duong, map[string]any{
		"status": "dang_lam", "mo_tai_khoan": true,
	})
	if res.ma != http.StatusOK {
		t.Fatalf("mở lại tài khoản phải trả 200, nhận %d\n%s", res.ma, catBot(res.than))
	}
	_ = json.Unmarshal([]byte(res.than), &sau)
	if sau.Data.UserStatus != "active" {
		t.Fatalf("tài khoản chưa mở lại: %+v", sau.Data)
	}
	if ma := dangNhap(); ma != http.StatusOK {
		t.Fatalf("người đi làm lại phải đăng nhập được, nhận %d", ma)
	}
}

// Đường SỬA HỒ SƠ cũng đặt được "đã nghỉ" — và phải khoá tài khoản y hệt công
// tắc ngoài bảng. Hai đường vào cùng một việc mà cho ra hai kết quả khác nhau thì
// lỗ hổng vừa vá lại mở ra ở cửa bên cạnh.
func TestNhanSuSuaHoSoSangDaNghiCungKhoaTaiKhoan(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	tenDangNhap := "binh.suahoso"
	hoSo := map[string]any{
		"full_name": "Trần Thị Bình " + a.vet,
		"position":  "thu_ngan",
		"status":    "dang_lam",
		"shop_id":   a.chiNhanh,
		"email":     "binh.suahoso." + a.vet + "@cua-hang-a.test",
		"role_id":   3,
		"tai_khoan": map[string]any{"username": tenDangNhap, "password": "MatKhau@123"},
	}
	res := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/nhan-su", hoSo)
	if res.ma != http.StatusCreated {
		t.Fatalf("thêm nhân sự kèm tài khoản phải trả 201, nhận %d\n%s", res.ma, catBot(res.than))
	}
	var tao struct {
		Data struct {
			ID uint `json:"id"`
		} `json:"data"`
	}
	_ = json.Unmarshal([]byte(res.than), &tao)

	// Lượt sửa gửi lại cả hồ sơ nhưng KHÔNG xin cấp tài khoản nữa (hồ sơ đã có).
	delete(hoSo, "tai_khoan")
	hoSo["status"] = "da_nghi"
	res = h.goi(t, a.token, http.MethodPut, fmt.Sprintf("/api/v1/admin/nhan-su/%d", tao.Data.ID), hoSo)
	if res.ma != http.StatusOK {
		t.Fatalf("sửa hồ sơ phải trả 200, nhận %d\n%s", res.ma, catBot(res.than))
	}
	var sau struct {
		Data struct {
			Status     string `json:"status"`
			UserStatus string `json:"user_status"`
		} `json:"data"`
	}
	_ = json.Unmarshal([]byte(res.than), &sau)
	if sau.Data.Status != "da_nghi" || sau.Data.UserStatus != "inactive" {
		t.Fatalf("sửa hồ sơ sang đã nghỉ mà tài khoản còn mở: %+v", sau.Data)
	}

	ma := h.goi(t, "", http.MethodPost, "/api/v1/auth/shop-login", map[string]any{
		"shop_code": a.ma, "username": tenDangNhap, "password": "MatKhau@123",
	}).ma
	if ma == http.StatusOK {
		t.Fatal("người bị sửa hồ sơ sang đã nghỉ vẫn đăng nhập được")
	}
}

// Đổi VAI TRÒ của tài khoản ngay trong lượt sửa hồ sơ.
//
// Trước khi có đường này, ô phân quyền trên màn hình chỉ có nghĩa lúc CẤP tài
// khoản: chủ tiệm sửa hồ sơ, chọn quyền khác, bấm Lưu, màn hình báo "đã cập
// nhật" — còn dưới CSDL không có gì đổi, và huy hiệu ngoài bảng vẫn nguyên như
// cũ. Bài này đọc lại role_id sau lượt sửa nên nó bắt được đúng chỗ đó.
func TestNhanSuDoiVaiTroTaiKhoan(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	hoSo := map[string]any{
		"full_name": "Nguyễn Văn An " + a.vet,
		"position":  "thu_ngan",
		"status":    "dang_lam",
		"shop_id":   a.chiNhanh,
		"email":     "an.doiquyen." + a.vet + "@cua-hang-a.test",
		"role_id":   3,
		"tai_khoan": map[string]any{"username": "an.doiquyen", "password": "MatKhau@123"},
	}
	res := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/nhan-su", hoSo)
	if res.ma != http.StatusCreated {
		t.Fatalf("thêm nhân sự kèm tài khoản phải trả 201, nhận %d\n%s", res.ma, catBot(res.than))
	}
	var tao struct {
		Data struct {
			ID     uint `json:"id"`
			RoleID uint `json:"role_id"`
		} `json:"data"`
	}
	_ = json.Unmarshal([]byte(res.than), &tao)
	if tao.Data.RoleID != 3 {
		t.Fatalf("tài khoản vừa cấp phải mang quyền vừa chọn, nhận %d", tao.Data.RoleID)
	}

	// Lượt sửa: giữ nguyên chức danh thu ngân, nâng quyền lên quản lý. Hai thứ đó
	// tách được nhau — đó là toàn bộ lý do ô quyền là ô chọn chứ không phải chữ.
	delete(hoSo, "tai_khoan")
	hoSo["role_id"] = 2
	duong := fmt.Sprintf("/api/v1/admin/nhan-su/%d", tao.Data.ID)
	res = h.goi(t, a.token, http.MethodPut, duong, hoSo)
	if res.ma != http.StatusOK {
		t.Fatalf("sửa hồ sơ phải trả 200, nhận %d\n%s", res.ma, catBot(res.than))
	}
	var sau struct {
		Data struct {
			Position string `json:"position"`
			RoleID   uint   `json:"role_id"`
			Username string `json:"username"`
		} `json:"data"`
	}
	_ = json.Unmarshal([]byte(res.than), &sau)
	if sau.Data.RoleID != 2 {
		t.Fatalf("quyền chưa đổi: %+v", sau.Data)
	}
	if sau.Data.Position != "thu_ngan" || sau.Data.Username != "an.doiquyen" {
		t.Fatalf("lượt đổi quyền đã đụng vào chức danh hoặc tên đăng nhập: %+v", sau.Data)
	}

	// Đọc lại từ danh sách: đó mới là con số huy hiệu ngoài bảng dùng.
	res = h.goi(t, a.token, http.MethodGet, "/api/v1/admin/nhan-su", nil)
	var ds struct {
		Data []struct {
			ID     uint `json:"id"`
			RoleID uint `json:"role_id"`
		} `json:"data"`
	}
	_ = json.Unmarshal([]byte(res.than), &ds)
	for _, ns := range ds.Data {
		if ns.ID == tao.Data.ID && ns.RoleID != 2 {
			t.Fatalf("danh sách vẫn trả quyền cũ %d", ns.RoleID)
		}
	}

	// Quyền lạ thì chặn: super admin không phát ra từ màn hình nhân sự.
	hoSo["role_id"] = 1
	res = h.goi(t, a.token, http.MethodPut, duong, hoSo)
	if res.ma != http.StatusForbidden {
		t.Fatalf("gán quyền super admin phải bị từ chối, nhận %d\n%s", res.ma, catBot(res.than))
	}
}

// contains — tìm chuỗi con, viết tay để không kéo thêm import cho một phép so.
func contains(s, sub string) bool {
	return len(s) >= len(sub) && (func() bool {
		for i := 0; i+len(sub) <= len(s); i++ {
			if s[i:i+len(sub)] == sub {
				return true
			}
		}

		return false
	})()
}

// TestNhanSuCaLamNhieuCa — ô "Ca làm việc" thay chỗ chức danh trên màn hình, và
// nó lưu vào cột SET `employees.work_shift`.
//
// Hai chuyện chỉ MySQL thật mới trả lời được, sổ giả thì không:
//
//   - Cột SET có nhận đúng chuỗi "sang,chieu" tầng Go ghép ra không. Sai một ly
//     là lỗi 1265 lúc ghi, và bài kiểm dùng sổ giả sẽ xanh trong khi màn hình
//     thật hỏng ở lượt lưu đầu tiên.
//   - Lọc theo MỘT ca có bắt được người trực NHIỀU ca không. Đây là chỗ dễ sai
//     nhất: `work_shift = 'sang'` chạy qua mọi bài kiểm một-ca rồi lặng lẽ làm
//     người trực "sang,chieu" biến mất khỏi lượt lọc ca sáng.
func TestNhanSuCaLamNhieuCa(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	res := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/nhan-su", map[string]any{
		"full_name":  "Người trực hai ca " + a.vet,
		"status":     "dang_lam",
		"shop_id":    a.chiNhanh,
		"work_shift": []string{"sang", "chieu"},
	})
	if res.ma != http.StatusCreated {
		t.Fatalf("thêm nhân sự phải trả 201, nhận %d\n%s", res.ma, catBot(res.than))
	}

	var tao struct {
		Data struct {
			ID        uint   `json:"id"`
			WorkShift string `json:"work_shift"`
			// Màn hình không gửi chức danh nữa; cột ENUM NOT NULL phải rơi về mặc
			// định của nó chứ không được nhận chuỗi rỗng.
			Position string `json:"position"`
		} `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &tao); err != nil {
		t.Fatalf("không đọc được phản hồi: %v\n%s", err, catBot(res.than))
	}
	if tao.Data.WorkShift != "sang,chieu" {
		t.Fatalf("ca làm ghi xuống là %q, đáng lẽ \"sang,chieu\"", tao.Data.WorkShift)
	}
	if tao.Data.Position != "ban_hang" {
		t.Fatalf("bỏ trống chức danh phải rơi về mặc định ban_hang, nhận %q", tao.Data.Position)
	}

	// Người trực hai ca phải lọt vào lượt lọc của TỪNG ca trong hai ca đó.
	for _, ca := range []string{"sang", "chieu"} {
		loc := h.goi(t, a.token, http.MethodGet, "/api/v1/admin/nhan-su?work_shift="+ca, nil)
		if loc.ma != http.StatusOK {
			t.Fatalf("lọc ca %s phải trả 200, nhận %d\n%s", ca, loc.ma, catBot(loc.than))
		}
		if !contains(loc.than, "Người trực hai ca "+a.vet) {
			t.Fatalf("lọc ca %s làm mất người trực cả hai ca:\n%s", ca, catBot(loc.than))
		}
	}

	// Còn ca không trực thì không được lôi người ta vào.
	loc := h.goi(t, a.token, http.MethodGet, "/api/v1/admin/nhan-su?work_shift=ca_ngay", nil)
	if contains(loc.than, "Người trực hai ca "+a.vet) {
		t.Fatalf("lọc ca_ngay không được trả người chỉ trực sáng và chiều:\n%s", catBot(loc.than))
	}

	// Ca lạ bị chặn ở tầng Go, không để MySQL ném lỗi 1265 khó đọc.
	xau := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/nhan-su", map[string]any{
		"full_name":  "Người ca đêm " + a.vet,
		"status":     "dang_lam",
		"shop_id":    a.chiNhanh,
		"work_shift": []string{"ca_dem"},
	})
	if xau.ma != http.StatusUnprocessableEntity {
		t.Fatalf("ca lạ phải trả 422, nhận %d\n%s", xau.ma, catBot(xau.than))
	}
	if !contains(xau.than, "work_shift") {
		t.Fatalf("lỗi 422 phải chỉ đúng ô work_shift, nhận: %s", catBot(xau.than))
	}
}

// TestNhanSuCaNgayLoaiTruBuoi — "Cả ngày" ĐÃ GỒM sáng và chiều nên không đứng
// chung với hai ca kia được.
//
// Màn hình khoá ô lại khi tick, nhưng luật phải sống ở đây nữa: cột SET của MySQL
// vui vẻ nhận "sang,ca_ngay" — nó chỉ kiểm từng giá trị có nằm trong danh sách
// không, chứ không biết giá trị này bao trùm giá trị kia. Để lọt thì bảng chấm
// công không trả lời được câu hỏi đơn giản nhất: người này trực mấy buổi?
func TestNhanSuCaNgayLoaiTruBuoi(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	xau := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/nhan-su", map[string]any{
		"full_name":  "Người tham ca " + a.vet,
		"status":     "dang_lam",
		"shop_id":    a.chiNhanh,
		"work_shift": []string{"sang", "ca_ngay"},
	})
	if xau.ma != http.StatusUnprocessableEntity {
		t.Fatalf("cả ngày kèm ca sáng phải trả 422, nhận %d\n%s", xau.ma, catBot(xau.than))
	}
	if !contains(xau.than, "work_shift") {
		t.Fatalf("lỗi 422 phải chỉ đúng ô work_shift, nhận: %s", catBot(xau.than))
	}

	// Một mình "cả ngày" thì bình thường.
	tot := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/nhan-su", map[string]any{
		"full_name":  "Người trực cả ngày " + a.vet,
		"status":     "dang_lam",
		"shop_id":    a.chiNhanh,
		"work_shift": []string{"ca_ngay"},
	})
	if tot.ma != http.StatusCreated {
		t.Fatalf("một mình cả ngày phải trả 201, nhận %d\n%s", tot.ma, catBot(tot.than))
	}
}

// TestNhanSuTichGiVaoDuocNay — hai cửa ĐỘC LẬP, không lồng nhau nữa.
//
// Trước migration 0015, vai `admin` mặc nhiên đi qua cả khu quản trị lẫn quầy
// bán, nên chủ tiệm tích một ô hay hai ô đều ra cùng một kết quả — và không có
// cách nào giao khu quản trị cho ai đó mà vẫn giữ họ ngoài quầy.
//
// Bài này dựng đúng người ấy (chỉ tích "quan_ly"), đăng nhập bằng chính tài
// khoản của họ, rồi gõ vào một đường của quầy. Phải nhận 403 — chặn thật ở tầng
// API, không phải chỉ ẩn nút đi trên giao diện.
func TestNhanSuTichGiVaoDuocNay(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	res := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/nhan-su", map[string]any{
		"full_name": "Người chỉ quản lý " + a.vet,
		"status":    "dang_lam",
		"shop_id":   a.chiNhanh,
		"email":     "chi.quanly." + a.vet + "@cua-hang-a.test",
		"role_id":   2,
		// CHỈ cửa quản trị. Không có "thu_ngan".
		"quyen":     []string{"quan_ly"},
		"tai_khoan": map[string]any{"username": "chi.quanly", "password": matKhauTest},
	})
	if res.ma != http.StatusCreated {
		t.Fatalf("thêm nhân sự kèm tài khoản phải trả 201, nhận %d\n%s", res.ma, catBot(res.than))
	}

	var tao struct {
		Data struct {
			ID    uint     `json:"id"`
			Quyen []string `json:"quyen"`
		} `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &tao); err != nil {
		t.Fatalf("không đọc được phản hồi: %v\n%s", err, catBot(res.than))
	}
	// Phản hồi phải trả về ĐÚNG một cửa — đây là thứ bảng dựng huy hiệu, và suy
	// từ role_id thì nó sẽ ra hai cái mà chủ tiệm chưa từng tích.
	if len(tao.Data.Quyen) != 1 || tao.Data.Quyen[0] != domainCuaQuanLy {
		t.Fatalf("phải trả đúng một cửa quan_ly, nhận %v", tao.Data.Quyen)
	}

	// Đăng nhập bằng chính tài khoản vừa cấp: vai trò trong claims do luồng đăng
	// nhập rót vào, và đó mới là thứ middleware đọc.
	tokenQuanLy := h.dangNhapVoi(t, maA, "chi.quanly")

	// Khu quản trị: KHÔNG bị chặn vì sai cửa.
	//
	// Không đòi 200 ở đây, cố ý: tài khoản vừa cấp chưa được giao nhóm quyền nào
	// nên nó còn vướng lượt kiểm quyền theo chức năng. Đó là một chốt KHÁC, mã lỗi
	// khác (THIEU_QUYEN), và trộn hai chốt vào một lượt khẳng định thì bài kiểm
	// đổi màu vì lý do chẳng liên quan gì tới cửa vào.
	if r := h.goi(t, tokenQuanLy, http.MethodGet, "/api/v1/admin/nhan-su", nil); contains(r.than, "SAI_CUA") {
		t.Fatalf("người có cửa quan_ly không được bị chặn vì sai cửa:\n%s", catBot(r.than))
	}

	// Quầy bán: CHẶN, và chặn ĐÚNG VÌ SAI CỬA. Quét mã là lượt nhẹ nhất của quầy,
	// không đổi dữ liệu gì.
	r := h.goi(t, tokenQuanLy, http.MethodGet, "/api/v1/admin/orders/pos/scan?code=khong-co", nil)
	if r.ma != http.StatusForbidden || !contains(r.than, "SAI_CUA") {
		t.Fatalf("người KHÔNG có cửa thu_ngan phải bị chặn khỏi quầy, nhận %d\n%s", r.ma, catBot(r.than))
	}

	// Mở ca cũng vậy — đường ghi, và là thứ mở đầu một ca bán.
	r = h.goi(t, tokenQuanLy, http.MethodPost, "/api/v1/admin/ca-lam-viec/mo", map[string]any{
		"tien_dau_ca": 0,
	})
	if r.ma != http.StatusForbidden || !contains(r.than, "SAI_CUA") {
		t.Fatalf("người KHÔNG có cửa thu_ngan phải bị chặn khỏi mở ca, nhận %d\n%s", r.ma, catBot(r.than))
	}

	// Tích thêm cửa quầy thì vào được NGAY — không phải đăng nhập lại, vì cửa đọc
	// từ database chứ không từ token.
	duong := fmt.Sprintf("/api/v1/admin/nhan-su/%d", tao.Data.ID)
	res = h.goi(t, a.token, http.MethodPut, duong, map[string]any{
		"full_name": "Người chỉ quản lý " + a.vet,
		"status":    "dang_lam",
		"shop_id":   a.chiNhanh,
		"email":     "chi.quanly." + a.vet + "@cua-hang-a.test",
		"role_id":   2,
		"quyen":     []string{"quan_ly", "thu_ngan"},
	})
	if res.ma != http.StatusOK {
		t.Fatalf("sửa hồ sơ phải trả 200, nhận %d\n%s", res.ma, catBot(res.than))
	}

	r = h.goi(t, tokenQuanLy, http.MethodGet, "/api/v1/admin/orders/pos/scan?code=khong-co", nil)
	if contains(r.than, "SAI_CUA") {
		t.Fatalf("tích thêm cửa thu_ngan rồi mà vẫn chặn vì sai cửa "+
			"(token cũ không được phép quyết định điều này):\n%s", catBot(r.than))
	}
}

// domainCuaQuanLy nhắc lại hằng số của domain để bài kiểm không phải nhập cả gói
// chỉ vì một chuỗi.
const domainCuaQuanLy = "quan_ly"

// TestNhanSuLuuDuocAnh — đường dẫn ảnh đi trọn vòng: gửi lên, ghi xuống cột, đọc
// lại nguyên vẹn ở cả lượt xem một hồ sơ lẫn lượt lấy danh sách.
//
// Bảng nhân sự dựng thẻ <img> từ chính chuỗi này, nên nó rơi mất ở bất kỳ chặng
// nào cũng ra cùng một triệu chứng trên màn hình: ô ảnh trống. Mà "trống" thì
// không phân biệt được là chưa tải ảnh, tải hỏng, hay API nuốt mất cột — ba
// nguyên nhân chữa bằng ba cách khác nhau.
//
// API chỉ cất CHUỖI, không ôm tệp: ảnh nằm trên ổ đĩa của Shop Admin (xem
// migration 0016). Nên bài này kiểm đúng phần API chịu trách nhiệm.
func TestNhanSuLuuDuocAnh(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	duongAnh := "http://localhost:8001/storage/nhan-su/" + a.vet + ".jpg"

	res := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/nhan-su", map[string]any{
		"full_name": "Người có ảnh " + a.vet,
		"status":    "dang_lam",
		"shop_id":   a.chiNhanh,
		"avatar":    duongAnh,
	})
	if res.ma != http.StatusCreated {
		t.Fatalf("thêm nhân sự phải trả 201, nhận %d\n%s", res.ma, catBot(res.than))
	}

	var tao struct {
		Data struct {
			ID     uint   `json:"id"`
			Avatar string `json:"avatar"`
		} `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &tao); err != nil {
		t.Fatalf("không đọc được phản hồi: %v\n%s", err, catBot(res.than))
	}
	if tao.Data.Avatar != duongAnh {
		t.Fatalf("ảnh trả về là %q, đáng lẽ %q", tao.Data.Avatar, duongAnh)
	}

	// Danh sách mới là chỗ bảng đọc để dựng thẻ <img>.
	ds := h.goi(t, a.token, http.MethodGet, "/api/v1/admin/nhan-su", nil)
	if !contains(ds.than, duongAnh) {
		t.Fatalf("danh sách không mang theo đường dẫn ảnh:\n%s", catBot(ds.than))
	}

	// Bỏ ảnh đi thì cột về RỖNG, không giữ lại đường cũ — người dùng bấm "Gỡ ảnh"
	// rồi Lưu mà ảnh vẫn còn thì họ bấm lại lần nữa, và lần nữa.
	duong := fmt.Sprintf("/api/v1/admin/nhan-su/%d", tao.Data.ID)
	res = h.goi(t, a.token, http.MethodPut, duong, map[string]any{
		"full_name": "Người có ảnh " + a.vet,
		"status":    "dang_lam",
		"shop_id":   a.chiNhanh,
		"avatar":    "",
	})
	if res.ma != http.StatusOK {
		t.Fatalf("sửa hồ sơ phải trả 200, nhận %d\n%s", res.ma, catBot(res.than))
	}
	var sau struct {
		Data struct {
			Avatar string `json:"avatar"`
		} `json:"data"`
	}
	_ = json.Unmarshal([]byte(res.than), &sau)
	if sau.Data.Avatar != "" {
		t.Fatalf("gỡ ảnh rồi mà cột vẫn giữ %q", sau.Data.Avatar)
	}
}

// TestNhanSuXoaThiKhoaTaiKhoan — xoá hồ sơ thì tài khoản đăng nhập cũng đóng.
//
// Lỗ hổng này KÍN hơn hẳn cái mà migration 0011 đã bịt cho trạng thái "đã nghỉ":
// repository nhả `user_id` ra trước khi xoá mềm, nên từ giây đó không còn gì nối
// tài khoản kia với hồ sơ nào nữa. Nó nằm lại trong `users` với status 'active',
// không hiện ở màn hình nhân sự vì hồ sơ đã biến mất — và người vừa bị xoá vẫn
// đăng nhập được bằng mật khẩu cũ. Không ai phát hiện ra, vì không còn chỗ nào
// để nhìn.
//
// Bài này kiểm bằng lượt ĐĂNG NHẬP THẬT, không phải bằng cột status: cột đúng mà
// vẫn vào được thì cột ấy chẳng nói lên điều gì.
func TestNhanSuXoaThiKhoaTaiKhoan(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	tenDangNhap := "an.sapxoa"
	res := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/nhan-su", map[string]any{
		"full_name": "Người sắp bị xoá " + a.vet,
		"status":    "dang_lam",
		"shop_id":   a.chiNhanh,
		"email":     "an.sapxoa." + a.vet + "@cua-hang-a.test",
		"role_id":   3,
		"quyen":     []string{"thu_ngan"},
		"tai_khoan": map[string]any{"username": tenDangNhap, "password": matKhauTest},
	})
	if res.ma != http.StatusCreated {
		t.Fatalf("thêm nhân sự kèm tài khoản phải trả 201, nhận %d\n%s", res.ma, catBot(res.than))
	}
	var tao struct {
		Data struct{ ID uint } `json:"data"`
	}
	_ = json.Unmarshal([]byte(res.than), &tao)

	// Trước khi xoá: đăng nhập được.
	dn := h.goi(t, "", http.MethodPost, "/api/v1/auth/shop-login", map[string]any{
		"shop_code": a.ma, "username": tenDangNhap, "password": matKhauTest,
	})
	if dn.ma != http.StatusOK {
		t.Fatalf("tài khoản vừa cấp phải đăng nhập được, nhận %d\n%s", dn.ma, catBot(dn.than))
	}

	// Xoá hồ sơ.
	duong := fmt.Sprintf("/api/v1/admin/nhan-su/%d", tao.Data.ID)
	if x := h.goi(t, a.token, http.MethodDelete, duong, nil); x.ma != http.StatusOK {
		t.Fatalf("xoá hồ sơ phải trả 200, nhận %d\n%s", x.ma, catBot(x.than))
	}

	// Sau khi xoá: KHÔNG đăng nhập được nữa.
	dn = h.goi(t, "", http.MethodPost, "/api/v1/auth/shop-login", map[string]any{
		"shop_code": a.ma, "username": tenDangNhap, "password": matKhauTest,
	})
	if dn.ma == http.StatusOK {
		t.Fatalf("xoá hồ sơ rồi mà tài khoản vẫn đăng nhập được:\n%s", catBot(dn.than))
	}
}

// TestNhanSuChanXoaKhiConCaChuaDong — còn ca chưa đóng thì không xoá được hồ sơ.
//
// Xoá là KHOÁ tài khoản, nên xoá đúng người đang giữ két là lấy mất đường đóng ca
// của chính họ: ca treo lơ lửng, và số tiền trong đó không ai đối chiếu được nữa.
//
// Sau khi đóng ca thì lượt xoá lại đi được — chốt này chặn một TÌNH HUỐNG, không
// phải chặn vĩnh viễn một con người.
func TestNhanSuChanXoaKhiConCaChuaDong(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	tenDangNhap := "an.giuket"
	res := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/nhan-su", map[string]any{
		"full_name": "Người giữ két " + a.vet,
		"status":    "dang_lam",
		"shop_id":   a.chiNhanh,
		"email":     "an.giuket." + a.vet + "@cua-hang-a.test",
		"role_id":   3,
		"quyen":     []string{"thu_ngan"},
		"tai_khoan": map[string]any{"username": tenDangNhap, "password": matKhauTest},
	})
	if res.ma != http.StatusCreated {
		t.Fatalf("thêm nhân sự kèm tài khoản phải trả 201, nhận %d\n%s", res.ma, catBot(res.than))
	}
	var tao struct {
		Data struct {
			ID     uint  `json:"id"`
			UserID *uint `json:"user_id"`
		} `json:"data"`
	}
	_ = json.Unmarshal([]byte(res.than), &tao)
	if tao.Data.UserID == nil {
		t.Fatalf("hồ sơ chưa gắn tài khoản: %s", catBot(res.than))
	}

	// Ghi thẳng một ca CHƯA ĐÓNG do người này mở. Đi qua database chứ không qua
	// API mở ca: đường kia đòi cửa thu ngân và nhóm quyền, mà bài này nói về lượt
	// XOÁ, không nói về phân quyền.
	if err := h.db.WithContext(ctxThoat()).Exec(
		`INSERT INTO work_shifts (tenant_id, shop_id, opened_by, opened_at, opening_cash, created_at, updated_at)
		 VALUES (?, ?, ?, NOW(3), 0, NOW(3), NOW(3))`,
		a.id, a.chiNhanh, *tao.Data.UserID,
	).Error; err != nil {
		t.Fatalf("không dựng được ca đang mở: %v", err)
	}

	duong := fmt.Sprintf("/api/v1/admin/nhan-su/%d", tao.Data.ID)
	x := h.goi(t, a.token, http.MethodDelete, duong, nil)
	if x.ma != http.StatusConflict {
		t.Fatalf("còn ca chưa đóng thì xoá phải trả 409, nhận %d\n%s", x.ma, catBot(x.than))
	}

	// Đóng ca xong thì xoá được — chốt chặn một tình huống, không chặn một người.
	if err := h.db.WithContext(ctxThoat()).Exec(
		`UPDATE work_shifts SET closed_at = NOW(3), closed_by = ? WHERE opened_by = ?`,
		*tao.Data.UserID, *tao.Data.UserID,
	).Error; err != nil {
		t.Fatalf("không đóng được ca: %v", err)
	}
	if x = h.goi(t, a.token, http.MethodDelete, duong, nil); x.ma != http.StatusOK {
		t.Fatalf("đóng ca rồi thì xoá phải trả 200, nhận %d\n%s", x.ma, catBot(x.than))
	}
}

// TestNhanSuChanXoaKhiDaGhiSoQuy — đã ghi sổ quỹ thì hồ sơ ở lại.
//
// Xoá mềm thì hàng vẫn nằm trong bảng thật, nhưng danh sách không thấy — và lúc
// đối chiếu quỹ thì "không thấy tên người ghi" với "không có người ghi" là một.
func TestNhanSuChanXoaKhiDaGhiSoQuy(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	res := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/nhan-su", map[string]any{
		"full_name": "Người ghi sổ " + a.vet,
		"status":    "dang_lam",
		"shop_id":   a.chiNhanh,
		"email":     "an.ghiso." + a.vet + "@cua-hang-a.test",
		"role_id":   3,
		"quyen":     []string{"thu_ngan"},
		"tai_khoan": map[string]any{"username": "an.ghiso", "password": matKhauTest},
	})
	if res.ma != http.StatusCreated {
		t.Fatalf("thêm nhân sự phải trả 201, nhận %d\n%s", res.ma, catBot(res.than))
	}
	var tao struct {
		Data struct {
			ID     uint  `json:"id"`
			UserID *uint `json:"user_id"`
		} `json:"data"`
	}
	_ = json.Unmarshal([]byte(res.than), &tao)

	if err := h.db.WithContext(ctxThoat()).Exec(
		`INSERT INTO cash_entries (tenant_id, shop_id, direction, amount, reason, created_by, created_at, updated_at)
		 VALUES (?, ?, 'in', 100000, 'kiểm thử', ?, NOW(3), NOW(3))`,
		a.id, a.chiNhanh, *tao.Data.UserID,
	).Error; err != nil {
		t.Fatalf("không dựng được dòng sổ quỹ: %v", err)
	}

	duong := fmt.Sprintf("/api/v1/admin/nhan-su/%d", tao.Data.ID)
	x := h.goi(t, a.token, http.MethodDelete, duong, nil)
	if x.ma != http.StatusConflict {
		t.Fatalf("đã ghi sổ quỹ thì xoá phải trả 409, nhận %d\n%s", x.ma, catBot(x.than))
	}

	// Nhưng đánh dấu NGHỈ VIỆC thì vẫn được — đó là đường đúng, và câu từ chối ở
	// trên chỉ người dùng sang đúng đường này.
	tt := h.goi(t, a.token, http.MethodPut, duong+"/trang-thai", map[string]any{"status": "da_nghi"})
	if tt.ma != http.StatusOK {
		t.Fatalf("đánh dấu nghỉ việc phải trả 200, nhận %d\n%s", tt.ma, catBot(tt.than))
	}
}

// XOÁ HỒ SƠ THÌ NHẢ LẠI EMAIL VÀ TÊN ĐĂNG NHẬP.
//
// Trước migration 0056, ba UNIQUE của `users` không xét `deleted_at`: tài khoản
// đã xoá giữ email và tên đăng nhập của nó vĩnh viễn. Xoá NV2 xong khai lại đúng
// người ấy — hoặc chỉ là gõ nhầm rồi xoá đi khai lại — đều nhận 422 "Email đã
// được sử dụng", mà nhìn danh sách thì không ai đang dùng email đó cả. Không có
// đường nào gỡ trừ vào thẳng database.
//
// Bài này khai lại CẢ HAI thứ, vì hai khoá khác nhau và trước đây cùng chắn.
func TestNhanSuXoaRoiKhaiLaiDuocEmail(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	email := "khai.lai." + a.vet + "@cua-hang-a.test"
	tenDangNhap := "khai.lai." + a.vet
	hoSo := func(ten string) map[string]any {
		return map[string]any{
			"full_name": ten,
			"status":    "dang_lam",
			"shop_id":   a.chiNhanh,
			"email":     email,
			"role_id":   3,
			"quyen":     []string{"thu_ngan"},
			"tai_khoan": map[string]any{"username": tenDangNhap, "password": matKhauTest},
		}
	}

	res := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/nhan-su", hoSo("Người cũ "+a.vet))
	if res.ma != http.StatusCreated {
		t.Fatalf("thêm nhân sự lần đầu phải trả 201, nhận %d\n%s", res.ma, catBot(res.than))
	}
	var tao struct {
		Data struct{ ID uint } `json:"data"`
	}
	_ = json.Unmarshal([]byte(res.than), &tao)

	// Còn hồ sơ thì vẫn phải chặn trùng — luật cũ không được nới cho người đang sống.
	trung := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/nhan-su", hoSo("Người trùng "+a.vet))
	if trung.ma != http.StatusUnprocessableEntity {
		t.Fatalf("email đang có người dùng phải bị chặn 422, nhận %d\n%s", trung.ma, catBot(trung.than))
	}

	duong := fmt.Sprintf("/api/v1/admin/nhan-su/%d", tao.Data.ID)
	if x := h.goi(t, a.token, http.MethodDelete, duong, nil); x.ma != http.StatusOK {
		t.Fatalf("xoá hồ sơ phải trả 200, nhận %d\n%s", x.ma, catBot(x.than))
	}

	// Xoá xong: khai lại đúng email và tên đăng nhập ấy.
	lai := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/nhan-su", hoSo("Người cũ tuyển lại "+a.vet))
	if lai.ma != http.StatusCreated {
		t.Fatalf("xoá hồ sơ rồi phải khai lại được email/tên đăng nhập cũ, nhận %d\n%s", lai.ma, catBot(lai.than))
	}

	// Và tài khoản khai lại là tài khoản MỚI, đăng nhập được bằng mật khẩu vừa đặt.
	dn := h.goi(t, "", http.MethodPost, "/api/v1/auth/shop-login", map[string]any{
		"shop_code": a.ma, "username": tenDangNhap, "password": matKhauTest,
	})
	if dn.ma != http.StatusOK {
		t.Fatalf("tài khoản khai lại phải đăng nhập được, nhận %d\n%s", dn.ma, catBot(dn.than))
	}
}
