package apitest

import (
	"encoding/json"
	"fmt"
	"net/http"
	"testing"
)

// Bài kiểm NHÀ CUNG CẤP qua API thật và MySQL thật.
//
// Bốn chỗ đáng hỏng của danh mục này: mã tự sinh có chạy không, công tắc hợp tác
// có ghi lẫn sang các ô khác không (bản v2 `fill($request->all())` nên có), bên
// còn phiếu đặt hàng có bị chặn xoá không, và hai cửa hàng có nhìn thấy danh mục
// của nhau không.

// nhaCungCap là một dòng trên bảng Nhà cung cấp.
type nhaCungCap struct {
	ID             uint    `json:"id"`
	Code           string  `json:"code"`
	Name           string  `json:"name"`
	Address        string  `json:"address"`
	Phone          string  `json:"phone"`
	IsActive       bool    `json:"is_active"`
	PurchaseCount  int64   `json:"purchase_count"`
	TotalPurchases float64 `json:"total_purchases"`
	PaidAmount     float64 `json:"paid_amount"`
	DebtAmount     float64 `json:"debt_amount"`
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

// gieoPhieuMua nhét thẳng một phiếu đặt hàng trỏ tới nhà cung cấp.
//
// Đi thẳng xuống database thay vì gọi API lập phiếu: màn đặt hàng nhập hiện chưa
// gửi supplier_id (ô chọn bên bán vẫn là ô gõ tên), mà thứ cần kiểm ở đây là
// đường ĐỌC — tổng tiền và lượt chặn xoá.
func gieoPhieuMua(t *testing.T, h *heThong, c *cuaHang, nccID uint, trangThai string, tong, daTra float64) {
	t.Helper()

	err := h.db.WithContext(ctxThoat()).Exec(
		"INSERT INTO purchase_orders (tenant_id, shop_id, supplier_id, po_code, supplier_name, status, "+
			"items_amount, discount_amount, shipping_fee, total_amount, paid_amount, payment_status, created_at, updated_at) "+
			"VALUES (?, ?, ?, ?, '', ?, ?, 0, 0, ?, ?, 'unpaid', NOW(3), NOW(3))",
		c.id, c.chiNhanh, nccID, fmt.Sprintf("PO-%d-%s-%d", nccID, trangThai, int64(tong)),
		trangThai, tong, tong, daTra,
	).Error
	if err != nil {
		t.Fatalf("không gieo được phiếu đặt hàng: %v", err)
	}
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

// TestNhaCungCap_SoTienVaChanXoa — ba cột tiền cộng đúng, và bên còn phiếu thì
// không xoá được (409) chứ không xoá xong mới biết.
func TestNhaCungCap_SoTienVaChanXoa(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	_, ncc := themNCC(t, h, a.token, map[string]any{"name": "Bên có phiếu", "address": "1 A"})
	_, rong := themNCC(t, h, a.token, map[string]any{"name": "Bên chưa mua gì", "address": "2 B"})

	gieoPhieuMua(t, h, a, ncc.ID, "received", 1_000_000, 400_000)
	gieoPhieuMua(t, h, a, ncc.ID, "ordered", 500_000, 0)
	// Hai phiếu dưới đây KHÔNG được tính tiền: nháp chưa đặt thật, huỷ thì không
	// còn nợ ai. Nhưng vẫn tính là "còn phiếu" nên vẫn chặn xoá.
	gieoPhieuMua(t, h, a, ncc.ID, "draft", 9_000_000, 0)
	gieoPhieuMua(t, h, a, ncc.ID, "cancelled", 8_000_000, 0)

	var co nhaCungCap
	for _, d := range docNCC(t, h, a.token, "") {
		if d.ID == ncc.ID {
			co = d
		}
	}

	if co.PurchaseCount != 4 {
		t.Fatalf("số phiếu phải đếm cả nháp và huỷ (4), nhận %d", co.PurchaseCount)
	}
	if co.TotalPurchases != 1_500_000 {
		t.Fatalf("tổng mua phải là 1.500.000 (bỏ nháp + huỷ), nhận %.0f", co.TotalPurchases)
	}
	if co.PaidAmount != 400_000 {
		t.Fatalf("đã trả phải là 400.000, nhận %.0f", co.PaidAmount)
	}
	if co.DebtAmount != 1_100_000 {
		t.Fatalf("còn nợ phải là 1.100.000, nhận %.0f", co.DebtAmount)
	}

	res := h.goi(t, a.token, http.MethodDelete, fmt.Sprintf("/api/v1/admin/nha-cung-cap/%d", ncc.ID), nil)
	if res.ma != http.StatusConflict {
		t.Fatalf("xoá bên còn phiếu phải trả 409, nhận %d\n%s", res.ma, catBot(res.than))
	}

	res = h.goi(t, a.token, http.MethodDelete, fmt.Sprintf("/api/v1/admin/nha-cung-cap/%d", rong.ID), nil)
	if res.ma != http.StatusOK {
		t.Fatalf("xoá bên chưa có phiếu phải trả 200, nhận %d\n%s", res.ma, catBot(res.than))
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
