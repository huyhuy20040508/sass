package apitest

import (
	"encoding/json"
	"fmt"
	"net/http"
	"testing"
)

// Bài kiểm NHÀ CUNG CẤP qua API thật và MySQL thật.
//
// Ba chỗ đáng hỏng của danh mục này: mã tự sinh có chạy không, công tắc hợp tác
// có ghi lẫn sang các ô khác không (bản v2 `fill($request->all())` nên có), và
// hai cửa hàng có nhìn thấy danh mục của nhau không.

// nhaCungCap là một dòng trên bảng Nhà cung cấp.
type nhaCungCap struct {
	ID       uint   `json:"id"`
	Code     string `json:"code"`
	Name     string `json:"name"`
	Address  string `json:"address"`
	Phone    string `json:"phone"`
	IsActive bool   `json:"is_active"`

	TotalPurchases float64 `json:"total_purchases"`
	TotalPayment   float64 `json:"total_payment"`
	StillInDebt    float64 `json:"still_in_debt"`
}

func themNCC(t *testing.T, h *heThong, token string, than map[string]any) (int, nhaCungCap) {
	t.Helper()

	res := h.goi(t, token, http.MethodPost, "/api/v1/admin/nha-cung-cap", than)

	var body struct {
		Data nhaCungCap `json:"data"`
	}
	_ = json.Unmarshal([]byte(res.than), &body)

	return res.ma, body.Data
}

func docNCC(t *testing.T, h *heThong, token, query string) []nhaCungCap {
	t.Helper()

	duong := "/api/v1/admin/nha-cung-cap"
	if query != "" {
		duong += "?" + query
	}

	res := h.goi(t, token, http.MethodGet, duong, nil)
	if res.ma != http.StatusOK {
		t.Fatalf("đọc danh sách NCC phải trả 200, nhận %d\n%s", res.ma, catBot(res.than))
	}

	var body struct {
		Data []nhaCungCap `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &body); err != nil {
		t.Fatalf("không đọc được phản hồi: %v\n%s", err, catBot(res.than))
	}

	return body.Data
}

// TestNhaCungCap_TaoRoiDocLai — bỏ trống mã thì phần mềm đặt NCC001, và bên mới
// mặc định đang hợp tác.
func TestNhaCungCap_TaoRoiDocLai(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	ma, ncc := themNCC(t, h, a.token, map[string]any{
		"name":    "Công ty An Bình",
		"address": "12 Lê Lợi",
	})
	if ma != http.StatusCreated {
		t.Fatalf("tạo NCC phải trả 201, nhận %d", ma)
	}
	if ncc.Code != "NCC001" {
		t.Fatalf("bỏ trống mã thì phải tự đặt NCC001, nhận %q", ncc.Code)
	}
	if !ncc.IsActive {
		t.Fatal("bên mới phải đang hợp tác — không thì vừa khai xong đã không chọn được")
	}

	// Mã gõ tay được viết hoa và không đụng dãy tự sinh.
	_, hai := themNCC(t, h, a.token, map[string]any{
		"code": "anbinh2", "name": "Kho An Bình 2", "address": "34 Hai Bà Trưng",
	})
	if hai.Code != "ANBINH2" {
		t.Fatalf("mã gõ tay phải viết hoa thành ANBINH2, nhận %q", hai.Code)
	}

	_, ba := themNCC(t, h, a.token, map[string]any{"name": "Minh Phát", "address": "5 Trần Phú"})
	if ba.Code != "NCC002" {
		t.Fatalf("dãy tự sinh phải tiếp tục ở NCC002, nhận %q", ba.Code)
	}

	if ds := docNCC(t, h, a.token, ""); len(ds) != 3 {
		t.Fatalf("danh sách phải có 3 bên, nhận %d", len(ds))
	}
}

// TestNhaCungCap_ChanTrungMa — mã trùng tô đỏ đúng ô mã (422), không phải 500.
func TestNhaCungCap_ChanTrungMa(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	themNCC(t, h, a.token, map[string]any{"code": "NCCX", "name": "Bên thứ nhất", "address": "1 A"})

	ma, _ := themNCC(t, h, a.token, map[string]any{"code": "nccx", "name": "Bên thứ hai", "address": "2 B"})
	if ma != http.StatusUnprocessableEntity {
		t.Fatalf("trùng mã phải trả 422, nhận %d", ma)
	}
}

// TestNhaCungCap_ThieuTenHoacDiaChi — đúng hai ô bắt buộc, như màn của v2.
func TestNhaCungCap_ThieuTenHoacDiaChi(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	if ma, _ := themNCC(t, h, a.token, map[string]any{"address": "1 A"}); ma != http.StatusUnprocessableEntity {
		t.Fatalf("thiếu tên phải trả 422, nhận %d", ma)
	}
	if ma, _ := themNCC(t, h, a.token, map[string]any{"name": "Không địa chỉ"}); ma != http.StatusUnprocessableEntity {
		t.Fatalf("thiếu địa chỉ phải trả 422, nhận %d", ma)
	}
}

// TestNhaCungCap_CongTacKhongGhiLanOKhac — đường đổi trạng thái CHỈ ghi is_active.
//
// Bản v2 `fill($request->all())` ở đúng chỗ này, nên gạt một công tắc là ghi đè
// được cả tên lẫn địa chỉ nếu người gọi gửi kèm.
func TestNhaCungCap_CongTacKhongGhiLanOKhac(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	_, ncc := themNCC(t, h, a.token, map[string]any{
		"name": "Giữ nguyên tên", "address": "77 Nguyễn Huệ", "phone": "0912345678",
	})

	res := h.goi(t, a.token, http.MethodPut,
		fmt.Sprintf("/api/v1/admin/nha-cung-cap/%d/trang-thai", ncc.ID),
		map[string]any{"is_active": false, "name": "TÊN BỊ GHI ĐÈ", "address": "", "phone": ""})
	if res.ma != http.StatusOK {
		t.Fatalf("đổi trạng thái phải trả 200, nhận %d\n%s", res.ma, catBot(res.than))
	}

	ds := docNCC(t, h, a.token, "")
	if len(ds) != 1 {
		t.Fatalf("phải còn đúng 1 bên, nhận %d", len(ds))
	}
	if ds[0].IsActive {
		t.Fatal("công tắc phải tắt được hợp tác")
	}
	if ds[0].Name != "Giữ nguyên tên" || ds[0].Address != "77 Nguyễn Huệ" || ds[0].Phone != "0912345678" {
		t.Fatalf("đường đổi trạng thái không được ghi sang ô khác, nhận %+v", ds[0])
	}

	// Đã tắt thì biến khỏi danh sách của ô chọn lúc lập phiếu.
	if ds := docNCC(t, h, a.token, "active=true"); len(ds) != 0 {
		t.Fatalf("active=true không được trả bên đã ngừng hợp tác, nhận %d dòng", len(ds))
	}
}

// TestNhaCungCap_SuaBoTrongMaThiGiuMaCu — mã đã in trên chứng từ nên không tự đổi.
func TestNhaCungCap_SuaBoTrongMaThiGiuMaCu(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	_, ncc := themNCC(t, h, a.token, map[string]any{"code": "NCCOLD", "name": "Tên cũ", "address": "1 A"})

	res := h.goi(t, a.token, http.MethodPut, fmt.Sprintf("/api/v1/admin/nha-cung-cap/%d", ncc.ID),
		map[string]any{"name": "Tên mới", "address": "2 B"})
	if res.ma != http.StatusOK {
		t.Fatalf("sửa phải trả 200, nhận %d\n%s", res.ma, catBot(res.than))
	}

	ds := docNCC(t, h, a.token, "")
	if ds[0].Code != "NCCOLD" || ds[0].Name != "Tên mới" {
		t.Fatalf("phải giữ mã cũ và đổi tên, nhận %+v", ds[0])
	}
}

// TestNhaCungCap_Xoa — xoá mềm một bên, và bên không có thật trả 404.
func TestNhaCungCap_Xoa(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	_, ncc := themNCC(t, h, a.token, map[string]any{"name": "Bên sẽ xoá", "address": "1 A"})

	res := h.goi(t, a.token, http.MethodDelete, fmt.Sprintf("/api/v1/admin/nha-cung-cap/%d", ncc.ID), nil)
	if res.ma != http.StatusOK {
		t.Fatalf("xoá nhà cung cấp phải trả 200, nhận %d\n%s", res.ma, catBot(res.than))
	}

	// Xoá lần hai: dòng đã đi khỏi danh mục nên đường xoá phải trả 404.
	res = h.goi(t, a.token, http.MethodDelete, fmt.Sprintf("/api/v1/admin/nha-cung-cap/%d", ncc.ID), nil)
	if res.ma != http.StatusNotFound {
		t.Fatalf("xoá lại bên đã xoá phải trả 404, nhận %d\n%s", res.ma, catBot(res.than))
	}
}

// TestNhaCungCap_CoLapGiuaHaiCuaHang — B không đọc, không sửa, không xoá được
// danh mục của A dù gõ đúng id trên URL.
func TestNhaCungCap_CoLapGiuaHaiCuaHang(t *testing.T) {
	h := dungHeThong(t)
	a, b := haiCuaHang(t, h)

	_, cuaA := themNCC(t, h, a.token, map[string]any{"name": "Riêng của A", "address": "1 A"})

	if ds := docNCC(t, h, b.token, ""); len(ds) != 0 {
		t.Fatalf("B không được thấy danh mục của A, nhận %d dòng", len(ds))
	}

	duong := fmt.Sprintf("/api/v1/admin/nha-cung-cap/%d", cuaA.ID)
	if res := h.goi(t, b.token, http.MethodGet, duong, nil); res.ma != http.StatusNotFound {
		t.Fatalf("B đọc chi tiết của A phải trả 404, nhận %d", res.ma)
	}
	if res := h.goi(t, b.token, http.MethodPut, duong,
		map[string]any{"name": "Bị B sửa", "address": "x"}); res.ma != http.StatusNotFound {
		t.Fatalf("B sửa của A phải trả 404, nhận %d", res.ma)
	}
	if res := h.goi(t, b.token, http.MethodDelete, duong, nil); res.ma != http.StatusNotFound {
		t.Fatalf("B xoá của A phải trả 404, nhận %d", res.ma)
	}

	if ds := docNCC(t, h, a.token, ""); len(ds) != 1 || ds[0].Name != "Riêng của A" {
		t.Fatalf("dòng của A phải còn nguyên, nhận %+v", ds)
	}
}

// TestNhaCungCap_BaSoTienChiTinhPhieuDaDuyet — ba cột tiền trên trang danh sách.
//
// Chỗ đáng hỏng: đếm cả phiếu lưu tạm. Phiếu gõ dở chưa mua gì, cộng nó vào là
// bịa ra một khoản nợ mà bên bán không hề đòi.
func TestNhaCungCap_BaSoTienChiTinhPhieuDaDuyet(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	_, ncc := themNCC(t, h, a.token, map[string]any{"name": "Ben ban " + a.vet, "address": "Ha Noi"})

	// Phiếu ĐÃ DUYỆT 100.000, trả trước 40.000.
	_, daDuyet := lapPhieu(t, h, a.token, map[string]any{
		"supplier_id": ncc.ID,
		"items":       []any{dongHang(a.bienThe, 10, 10000)},
	})
	duyet(t, h, a, daDuyet.ID)
	res := h.goi(t, a.token, http.MethodPost,
		fmt.Sprintf("%s/%d/thanh-toan", duongPhieuMua, daDuyet.ID), map[string]any{"paid_amount": 40000})
	if res.ma != http.StatusOK {
		t.Fatalf("ghi nhận thanh toán trả %d\n%s", res.ma, catBot(res.than))
	}

	// Phiếu LƯU TẠM 500.000 của cùng bên — không được lọt vào con số nào.
	lapPhieu(t, h, a.token, map[string]any{
		"supplier_id": ncc.ID,
		"items":       []any{dongHang(a.bienThe, 50, 10000)},
	})

	ds := docNCC(t, h, a.token, "")
	if len(ds) != 1 {
		t.Fatalf("danh mục phải có đúng 1 dòng, nhận %d", len(ds))
	}
	got := ds[0]
	if got.TotalPurchases != 100000 || got.TotalPayment != 40000 || got.StillInDebt != 60000 {
		t.Fatalf("ba số tiền phải là 100.000 / 40.000 / 60.000, nhận %v / %v / %v",
			got.TotalPurchases, got.TotalPayment, got.StillInDebt)
	}
}
