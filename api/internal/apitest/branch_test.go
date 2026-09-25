package apitest

import (
	"encoding/json"
	"fmt"
	"net/http"
	"testing"
)

// Bài kiểm ĐƯỜNG CHI NHÁNH chạy qua API thật và MySQL thật.
//
// Vì sao cần tầng này chứ không chỉ kiểm service bằng sổ giả: phần dễ hỏng nhất
// của một bảng mới không nằm ở luật nghiệp vụ mà nằm ở chỗ nối — entity map
// đúng tên cột chưa (`shops` có `phone`/`address` NULL được), bộ lọc tenant có
// tự điền `tenant_id` cho dòng mới không, mã chi nhánh có thật sự chỉ duy nhất
// TRONG một cửa hàng không. Sổ giả trả lời "có" cho cả ba mà không chứng minh gì.
func TestChiNhanhMoDuocThemDiemBan(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	res := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/chi-nhanh", map[string]any{
		"name": "Kho miền Bắc " + a.vet, "phone": "0912345678", "address": "Hà Nội",
	})
	if res.ma != http.StatusCreated {
		t.Fatalf("mở chi nhánh phải trả 201, nhận %d\n%s", res.ma, catBot(res.than))
	}

	// Mã tự sinh: chi nhánh đầu tiên luôn là 'mac-dinh', nên cái thứ hai là
	// 'chi-nhanh-2'.
	var tao struct {
		Data struct {
			ID   uint   `json:"id"`
			Code string `json:"code"`
			// Hai ô NULL được dưới database — đọc lại để chắc rằng chúng không vỡ ở
			// tầng driver và không bị ghi thành chuỗi rỗng.
			Phone   string `json:"phone"`
			Address string `json:"address"`
		} `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &tao); err != nil {
		t.Fatalf("không đọc được phản hồi: %v\n%s", err, catBot(res.than))
	}
	if tao.Data.Code != "chi-nhanh-2" {
		t.Fatalf("mã tự sinh là %q, đáng lẽ chi-nhanh-2", tao.Data.Code)
	}
	if tao.Data.Phone != "0912345678" || tao.Data.Address != "Hà Nội" {
		t.Fatalf("số điện thoại/địa chỉ không ghi đúng: %+v", tao.Data)
	}

	// Danh sách phải thấy đủ hai chi nhánh của CHÍNH mình.
	res = h.goi(t, a.token, http.MethodGet, "/api/v1/admin/chi-nhanh", nil)
	if res.ma != http.StatusOK {
		t.Fatalf("danh sách chi nhánh phải trả 200, nhận %d\n%s", res.ma, catBot(res.than))
	}
	var ds struct {
		Data []struct {
			Code string `json:"code"`
		} `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &ds); err != nil {
		t.Fatalf("không đọc được danh sách: %v\n%s", err, catBot(res.than))
	}
	if len(ds.Data) != 2 {
		t.Fatalf("phải thấy đúng 2 chi nhánh của mình, nhận %d\n%s", len(ds.Data), catBot(res.than))
	}
}

// Mã chi nhánh chỉ duy nhất TRONG MỘT cửa hàng (uq_shops_tenant_code).
//
// Hai vế, và vế thứ hai là vế dễ làm sai: trùng mã trong cùng tiệm phải bị từ
// chối, còn trùng mã với tiệm KHÁC thì phải cho qua — 'kho-1' là cái tên ai cũng
// đặt, và chặn nó nghĩa là khách này bị giới hạn bởi lựa chọn của khách kia.
func TestChiNhanhMaChiDuyNhatTrongMotCuaHang(t *testing.T) {
	h := dungHeThong(t)
	a, b := haiCuaHang(t, h)

	res := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/chi-nhanh", map[string]any{
		"code": "kho-1", "name": "Kho 1 " + a.vet,
	})
	if res.ma != http.StatusCreated {
		t.Fatalf("mở chi nhánh phải trả 201, nhận %d\n%s", res.ma, catBot(res.than))
	}

	// Cùng cửa hàng, cùng mã → từ chối.
	res = h.goi(t, a.token, http.MethodPost, "/api/v1/admin/chi-nhanh", map[string]any{
		"code": "kho-1", "name": "Kho 1 lần hai",
	})
	if res.ma != http.StatusUnprocessableEntity {
		t.Fatalf("mã trùng trong cùng cửa hàng phải trả 422, nhận %d\n%s", res.ma, catBot(res.than))
	}

	// Cửa hàng KHÁC, cùng mã → phải mở được.
	res = h.goi(t, b.token, http.MethodPost, "/api/v1/admin/chi-nhanh", map[string]any{
		"code": "kho-1", "name": "Kho 1 " + b.vet,
	})
	if res.ma != http.StatusCreated {
		t.Fatalf("cửa hàng khác dùng lại mã 'kho-1' phải được, nhận %d\n%s", res.ma, catBot(res.than))
	}
}

// Cửa hàng phải luôn còn ít nhất một chi nhánh đang hoạt động: mọi bảng giao
// dịch mang `shop_id`, nên không còn điểm bán nào là không còn chỗ ghi đơn hàng.
func TestChiNhanhKhongDongDuocDiemBanCuoiCung(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	duong := fmt.Sprintf("/api/v1/admin/chi-nhanh/%d", a.chiNhanh)

	res := h.goi(t, a.token, http.MethodDelete, duong, nil)
	if res.ma != http.StatusConflict {
		t.Fatalf("xoá chi nhánh cuối cùng phải trả 409, nhận %d\n%s", res.ma, catBot(res.than))
	}

	res = h.goi(t, a.token, http.MethodPut, duong, map[string]any{
		"name": "Chi nhánh " + a.vet, "is_active": false,
	})
	if res.ma != http.StatusConflict {
		t.Fatalf("tắt chi nhánh hoạt động cuối cùng phải trả 409, nhận %d\n%s", res.ma, catBot(res.than))
	}

	// Có chi nhánh thứ hai rồi thì đóng cái đầu tiên là chuyện bình thường —
	// chốt chặn trên không được chặn oan.
	if res = h.goi(t, a.token, http.MethodPost, "/api/v1/admin/chi-nhanh", map[string]any{
		"name": "Kho phụ " + a.vet,
	}); res.ma != http.StatusCreated {
		t.Fatalf("mở chi nhánh thứ hai phải trả 201, nhận %d\n%s", res.ma, catBot(res.than))
	}
	if res = h.goi(t, a.token, http.MethodDelete, duong, nil); res.ma != http.StatusOK {
		t.Fatalf("còn chi nhánh khác mà xoá vẫn bị chặn: %d\n%s", res.ma, catBot(res.than))
	}
}

// Nhân viên KHÔNG được đụng vào chi nhánh: mở thêm một điểm bán ăn vào hạn mức
// hợp đồng, tức là quyết định có tiền đứng sau. Cùng mức riêng tư với trang
// Người dùng và Cài đặt.
func TestChiNhanhNhanVienKhongVaoDuoc(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	// Đăng nhập bằng chính ô "nhân viên" của cửa hàng — không tự ký token với vai
	// trò staff: token thật đi qua đúng luồng cấp quyền, còn token tự ký chỉ kiểm
	// được đoạn code đọc nó.
	res := h.goi(t, "", http.MethodPost, "/api/v1/auth/shop-login", map[string]any{
		"shop_code": a.ma, "username": "nhanvien", "password": matKhauTest,
	})
	if res.ma != http.StatusOK {
		t.Fatalf("nhân viên đăng nhập phải trả 200, nhận %d\n%s", res.ma, catBot(res.than))
	}
	var dn struct {
		Data struct {
			AccessToken string `json:"access_token"`
		} `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &dn); err != nil {
		t.Fatalf("không đọc được token: %v\n%s", err, catBot(res.than))
	}
	token := dn.Data.AccessToken

	res = h.goi(t, token, http.MethodGet, "/api/v1/admin/chi-nhanh", nil)
	if res.ma != http.StatusForbidden {
		t.Fatalf("nhân viên đọc danh sách chi nhánh phải nhận 403, nhận %d\n%s", res.ma, catBot(res.than))
	}

	res = h.goi(t, token, http.MethodPost, "/api/v1/admin/chi-nhanh", map[string]any{"name": "Kho lén"})
	if res.ma != http.StatusForbidden {
		t.Fatalf("nhân viên mở chi nhánh phải nhận 403, nhận %d\n%s", res.ma, catBot(res.than))
	}
}
