package apitest

import (
	"encoding/json"
	"net/http"
	"testing"

	"sass-api/internal/domain"
)

// Giai đoạn 2 của quầy: quét mã thay vì gõ tên hàng, và bớt giá từng món trong
// tầm quyền của người đang đứng bán.
//
// Tách khỏi ban_tai_quay_test.go (giai đoạn 1 — bán được một lượt trọn vẹn) vì
// hai tệp trả lời hai câu hỏi khác nhau: bên kia hỏi "quầy bán được chưa", bên
// này hỏi "dùng cả ngày có chịu nổi không".

// danMaVach dán mã vạch lên biến thể gieo sẵn, đi thẳng vào database.
//
// Không đi qua API sửa sản phẩm: bài kiểm ở đây nói về đường QUÉT. Dựng dữ liệu
// bằng đường ngắn nhất thì lúc nó đỏ, thứ đỏ chắc chắn là thứ đang kiểm.
func danMaVach(t *testing.T, h *heThong, variantID uint, ma string) {
	t.Helper()

	err := h.db.WithContext(ctxThoat()).Model(&domain.ProductVariant{}).
		Where("id = ?", variantID).Update("barcode", ma).Error
	if err != nil {
		t.Fatalf("không dán được mã vạch: %v", err)
	}
}

// quet gọi đường quét mã, trả về mã HTTP kèm phần data đã đọc.
func quet(t *testing.T, h *heThong, c *cuaHang, ma string) (int, map[string]any) {
	t.Helper()

	res := h.goi(t, c.token, http.MethodGet, "/api/v1/admin/orders/pos/scan?code="+ma, nil)
	var out struct {
		Data map[string]any `json:"data"`
	}
	_ = json.Unmarshal([]byte(res.than), &out)

	return res.ma, out.Data
}

// TestQuetMa_TraDungMonVaDungGia — quét ra đúng biến thể, với giá mà đơn sẽ thu.
//
// Điều đáng kiểm nhất không phải "tìm thấy hay không" mà là CON SỐ. Nếu đường
// quét tự viết một câu truy vấn giá riêng thì nó sẽ quên khuyến mãi, và quầy đọc
// cho khách nghe một giá rồi máy tính một giá khác — kiểu sai không ai phát hiện
// cho tới lúc khách cãi.
func TestQuetMa_TraDungMonVaDungGia(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	danMaVach(t, h, a.bienThe, "8938505970012")

	ma, d := quet(t, h, a, "8938505970012")
	if ma != http.StatusOK {
		t.Fatalf("quét mã vạch trả %d", ma)
	}
	if uint(d["product_variant_id"].(float64)) != a.bienThe {
		t.Fatalf("quét ra nhầm biến thể: %v (phải là %d)", d["product_variant_id"], a.bienThe)
	}
	// Hàng gieo giá 100.000, khuyến mãi của sản phẩm giảm 10%.
	if d["price"].(float64) != 90000 {
		t.Fatalf("giá phải là 90000 (đã trừ khuyến mãi), đang là %v", d["price"])
	}
	// Tồn của CHI NHÁNH đang bán, không phải bản cộng của cả cửa hàng.
	if int(d["stock"].(float64)) != 20 {
		t.Fatalf("tồn phải là 20, đang là %v", d["stock"])
	}
}

// TestQuetMa_QuetDuocCaTemTiemTuIn — SKU cũng quét được.
//
// Tiệm nhỏ tự in tem SKU dán lên hàng lẻ vì hàng đó không có mã vạch của nhà sản
// xuất. Chỉ nhận mã vạch thì cái máy quét nằm không đúng với nhóm hàng hay phải
// bán nhất.
func TestQuetMa_QuetDuocCaTemTiemTuIn(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	ma, d := quet(t, h, a, "sku-"+a.vet+"-m")
	if ma != http.StatusOK {
		t.Fatalf("quét theo SKU trả %d", ma)
	}
	if uint(d["product_variant_id"].(float64)) != a.bienThe {
		t.Fatalf("quét SKU ra nhầm biến thể: %v", d["product_variant_id"])
	}
}

// TestQuetMa_MaLaThiBaoKhongCo — mã không có trong sổ trả 400, không trả bừa món nào.
func TestQuetMa_MaLaThiBaoKhongCo(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	if ma, _ := quet(t, h, a, "0000000000000"); ma != http.StatusBadRequest {
		t.Fatalf("mã lạ phải trả 400, đang trả %d", ma)
	}
}

// TestQuetMa_KhongThayHangCuaCuaHangKhac — quét mã của cửa hàng B từ cửa hàng A
// thì không ra gì.
//
// Đường quét nhận một chuỗi tự do rồi tra thẳng vào bảng biến thể, nên nó đúng
// kiểu đường dễ quên lọc theo cửa hàng — và quên thì thành một lỗ để dò mã vạch
// của hàng xóm trên cùng hệ thống.
func TestQuetMa_KhongThayHangCuaCuaHangKhac(t *testing.T) {
	h := dungHeThong(t)
	a, b := haiCuaHang(t, h)
	danMaVach(t, h, b.bienThe, "8938505970099")

	if ma, _ := quet(t, h, a, "8938505970099"); ma != http.StatusBadRequest {
		t.Fatalf("RÒ DỮ LIỆU: cửa hàng A quét được mã vạch của cửa hàng B (trả %d)", ma)
	}
	// SKU là đường tra thứ hai và phải được lọc y hệt đường thứ nhất.
	if ma, _ := quet(t, h, a, "sku-"+b.vet+"-m"); ma != http.StatusBadRequest {
		t.Fatalf("RÒ DỮ LIỆU: cửa hàng A quét được SKU của cửa hàng B (trả %d)", ma)
	}
}

// TestQuetMa_MaGoTuFormSanPhamThiQuetDuoc — khép vòng từ form sản phẩm tới quầy.
//
// Ba tầng phải cùng đúng thì mã vạch mới có ích: form gửi lên được, repository
// ghi xuống được, đường quét đọc lại được. Bài kiểm mỗi tầng một mình thì cả ba
// vẫn xanh trong khi cái mã gõ vào lúc chiều không quét ra gì lúc tối — đó mới
// là thứ người dùng gặp.
func TestQuetMa_MaGoTuFormSanPhamThiQuetDuoc(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	res := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/products", map[string]any{
		"category_id": a.danhMuc,
		"name":        "Áo mới " + a.vet,
		"slug":        "ao-moi-" + a.vet,
		"sku":         "AM-" + a.vet,
		"base_price":  250000,
		"status":      "active",
		"variants": []map[string]any{
			{"size": "L", "sku": "AM-" + a.vet + "-L", "barcode": "8935001100777"},
		},
	})
	if res.ma != http.StatusCreated {
		t.Fatalf("tạo sản phẩm kèm mã vạch trả %d\n%s", res.ma, catBot(res.than))
	}

	ma, d := quet(t, h, a, "8935001100777")
	if ma != http.StatusOK {
		t.Fatalf("mã vừa gõ trong form sản phẩm phải quét được, đang trả %d", ma)
	}
	if d["product_name"] != "Áo mới "+a.vet {
		t.Fatalf("quét ra nhầm sản phẩm: %v", d["product_name"])
	}
	// Giá nguyên 250.000: đợt khuyến mãi gieo sẵn nhắm ĐÚNG một sản phẩm khác,
	// nên hàng mới này không thuộc phạm vi của nó. Kiểm cả chiều ngược lại như
	// vậy mới có nghĩa — một đường quét áp bừa mọi khuyến mãi cho mọi món cũng
	// làm bài "giá đã trừ khuyến mãi" ở trên xanh.
	if d["price"].(float64) != 250000 {
		t.Fatalf("giá phải là 250000 (không có khuyến mãi nào áp cho hàng này), đang là %v", d["price"])
	}
}

// dangNhapNhanVien lấy token của tài khoản NHÂN VIÊN (vai trò staff) gieo sẵn.
func dangNhapNhanVien(t *testing.T, h *heThong, maCuaHang string) string {
	t.Helper()

	res := h.goi(t, "", http.MethodPost, "/api/v1/auth/shop-login", map[string]any{
		"shop_code": maCuaHang,
		"username":  "nhanvien",
		"password":  matKhauTest,
	})
	if res.ma != http.StatusOK {
		t.Fatalf("đăng nhập nhân viên hỏng: %d %s", res.ma, catBot(res.than))
	}
	var body struct {
		Data struct {
			AccessToken string `json:"access_token"`
		} `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &body); err != nil {
		t.Fatalf("không đọc được token nhân viên: %v", err)
	}
	return body.Data.AccessToken
}

// banVoiGiam bán một lượt có bớt giá dòng, bằng token chỉ định.
func banVoiGiam(t *testing.T, h *heThong, token string, variantID uint, phanTram float64) traLoi {
	t.Helper()

	return h.goi(t, token, http.MethodPost, "/api/v1/admin/orders/pos", map[string]any{
		"payment_method": "cash",
		"items": []map[string]any{
			{"product_variant_id": variantID, "quantity": 1, "discount_percent": phanTram},
		},
	})
}

// TestGiamGiaDong_NhanVienBiChanOMucCauHinh — nhân viên bấm quá hạn quyền thì
// không bán được, và kho không suy suyển.
//
// Đây là điều kiện để cả tính năng có nghĩa: chặn ở MÀN HÌNH chỉ là phép lịch sự
// với người dùng, ai gửi thẳng request lên vẫn tự cho mình giảm 90% nếu server
// không kiểm. Bài này gửi thẳng request, đúng như người muốn lách sẽ làm.
func TestGiamGiaDong_NhanVienBiChanOMucCauHinh(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	tokenNV := dangNhapNhanVien(t, h, a.ma)

	truoc := tonCua(t, h, a, a.chiNhanh, a.bienThe)

	// Cấu hình mặc định cho nhân viên tối đa 10%.
	res := banVoiGiam(t, h, tokenNV, a.bienThe, 50)
	if res.ma != http.StatusForbidden {
		t.Fatalf("nhân viên giảm 50%% phải bị chặn (403), đang trả %d\n%s", res.ma, catBot(res.than))
	}
	if sau := tonCua(t, h, a, a.chiNhanh, a.bienThe); sau != truoc {
		t.Fatalf("RÒ KHO: lượt bán bị chặn mà kho vẫn tụt từ %d xuống %d", truoc, sau)
	}

	// Trong hạn quyền thì bán được — hạn quyền phải là một ranh giới, không phải
	// một cái khoá.
	if res := banVoiGiam(t, h, tokenNV, a.bienThe, 10); res.ma != http.StatusCreated {
		t.Fatalf("nhân viên giảm 10%% (đúng hạn mức) phải bán được, đang trả %d\n%s", res.ma, catBot(res.than))
	}
}

// TestGiamGiaDong_QuanTriKhongBiChan — quản trị viên bấm mức nào cũng được.
//
// Hạn quyền dành cho người làm thuê đứng quầy; chặn cả chủ thì mỗi lần muốn giảm
// sâu lại phải vào Cài đặt sửa con số rồi sửa lại.
func TestGiamGiaDong_QuanTriKhongBiChan(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	res := banVoiGiam(t, h, a.token, a.bienThe, 50)
	if res.ma != http.StatusCreated {
		t.Fatalf("quản trị viên giảm 50%% phải bán được, đang trả %d\n%s", res.ma, catBot(res.than))
	}

	var ban struct {
		Data struct {
			Subtotal     float64 `json:"subtotal_amount"`
			LineDiscount float64 `json:"line_discount_amount"`
			Total        float64 `json:"total_amount"`
		} `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &ban); err != nil {
		t.Fatalf("không đọc được kết quả bán: %v", err)
	}

	// Giá sau khuyến mãi 90.000, bớt thêm 50% còn 45.000.
	if ban.Data.LineDiscount != 45000 {
		t.Fatalf("phần bớt phải là 45000, đang là %v", ban.Data.LineDiscount)
	}
	if ban.Data.Subtotal != 45000 || ban.Data.Total != 45000 {
		t.Fatalf("tiền hàng/tổng phải là 45000/45000, đang là %v/%v", ban.Data.Subtotal, ban.Data.Total)
	}
}

// TestGiamGiaDong_GhiLaiCaMucVaSoTien — dòng hàng lưu cả phần trăm lẫn số tiền.
//
// Giữ mỗi phần trăm thì tiền phải tính lại từ đơn giá, mà đơn giá đổi sau mỗi
// đợt khuyến mãi; giữ mỗi số tiền thì mất dấu vết ai được phép duyệt mức đó.
// Cần cả hai mới dựng lại được một dòng hàng cũ lúc đối soát.
func TestGiamGiaDong_GhiLaiCaMucVaSoTien(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	res := banVoiGiam(t, h, a.token, a.bienThe, 20)
	if res.ma != http.StatusCreated {
		t.Fatalf("bán trả %d\n%s", res.ma, catBot(res.than))
	}
	var ban struct {
		Data struct {
			OrderID uint `json:"order_id"`
		} `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &ban); err != nil {
		t.Fatalf("không đọc được kết quả bán: %v", err)
	}

	don := donQuay(t, h, a, ban.Data.OrderID)
	items, _ := don["items"].([]any)
	if len(items) != 1 {
		t.Fatalf("đơn phải có 1 dòng hàng, đang có %d", len(items))
	}
	dong := items[0].(map[string]any)

	if dong["discount_percent"].(float64) != 20 {
		t.Fatalf("mức giảm phải ghi lại là 20, đang là %v", dong["discount_percent"])
	}
	if dong["discount_amount"].(float64) != 18000 {
		t.Fatalf("số tiền bớt phải là 18000 (20%% của 90000), đang là %v", dong["discount_amount"])
	}
	// Đơn giá giữ nguyên giá sau khuyến mãi: phần bớt là một con số RIÊNG, không
	// được trộn vào đơn giá rồi mất dấu.
	if dong["unit_price"].(float64) != 90000 {
		t.Fatalf("đơn giá phải giữ 90000, đang là %v", dong["unit_price"])
	}
	if dong["total_price"].(float64) != 72000 {
		t.Fatalf("thành tiền phải là 72000, đang là %v", dong["total_price"])
	}
}

// TestHanMucGiamGia_ManHinhHoiDungConSoCuaMinh — mỗi vai trò hỏi ra đúng hạn
// mức của chính họ.
//
// Màn hình quầy đọc con số này để giới hạn ô nhập. Chép lại luật vào giao diện
// là sớm muộn hai bên lệch nhau, và người bán sẽ gặp cảnh ô cho gõ 30% rồi bấm
// xong mới bị từ chối.
func TestHanMucGiamGia_ManHinhHoiDungConSoCuaMinh(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	doc := func(token string) float64 {
		res := h.goi(t, token, http.MethodGet, "/api/v1/admin/orders/pos/discount-limit", nil)
		if res.ma != http.StatusOK {
			t.Fatalf("hỏi hạn mức trả %d\n%s", res.ma, catBot(res.than))
		}
		var out struct {
			Data struct {
				LimitPercent float64 `json:"limit_percent"`
			} `json:"data"`
		}
		if err := json.Unmarshal([]byte(res.than), &out); err != nil {
			t.Fatalf("không đọc được hạn mức: %v", err)
		}
		return out.Data.LimitPercent
	}

	if got := doc(a.token); got != 100 {
		t.Fatalf("quản trị viên phải nhận 100 (không chặn), đang nhận %v", got)
	}
	if got := doc(dangNhapNhanVien(t, h, a.ma)); got != 10 {
		t.Fatalf("nhân viên phải nhận 10 (mặc định cấu hình), đang nhận %v", got)
	}
}
