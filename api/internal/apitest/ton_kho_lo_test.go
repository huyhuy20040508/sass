package apitest

import (
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"testing"
	"time"

	"gorm.io/gorm"

	"sass-api/internal/domain"
	"sass-api/internal/tenant"
)

// Bài kiểm SỐ LÔ LÀ MỘT CHIỀU CỦA TỒN KHO — qua API thật và MySQL thật.
//
// Từ migration 0047, mỗi lô là một dòng tồn riêng và lượt bán tự rút lô theo
// FIFO/FEFO. Chỗ đáng hỏng không phải việc ghi được lô, mà là:
//
//   - BẤT BIẾN variant_stocks.quantity = SUM(stock_lots.quantity). Gãy cái này
//     thì trang tồn kho nói một số, bảng lô nói số khác, và không ai biết số nào
//     mới là hàng thật trong kho;
//   - rút ĐÚNG THỨ TỰ: FIFO lấy lô vào trước, FEFO lấy lô hết hạn sớm;
//   - hoàn hàng về ĐÚNG LÔ đã lấy, không dồn hết vào "Không xác định" — dồn thì
//     mỗi vòng bán–trả bào mòn một ít khỏi các lô có thật;
//   - công tắc chặn hàng quá hạn thật sự chặn.

// lo là một dòng tồn theo lô, đọc thẳng từ database.
type lo struct {
	LotNumber  string
	Quantity   int
	ExpireDate *time.Time
}

// docLo đọc mọi lô của một biến thể tại một chi nhánh, xếp theo id (thứ tự vào kho).
func docLo(t *testing.T, db *gorm.DB, c *cuaHang, shopID, variantID uint) []lo {
	t.Helper()

	ctx := tenant.WithID(context.Background(), c.id)
	var rows []lo
	err := db.WithContext(ctx).Model(&domain.TonKhoLo{}).
		Where("shop_id = ? AND product_variant_id = ?", shopID, variantID).
		Order("id ASC").
		Select("lot_number, quantity, expire_date").
		Scan(&rows).Error
	if err != nil {
		t.Fatalf("không đọc được bảng lô: %v", err)
	}

	return rows
}

// soCuaLo trả số hàng của một lô; lô không có dòng thì 0.
func soCuaLo(ds []lo, so string) int {
	for _, l := range ds {
		if l.LotNumber == so {
			return l.Quantity
		}
	}

	return 0
}

// khopTongVaLo canh BẤT BIẾN của cả module: tổng ở variant_stocks phải đúng bằng
// tổng các lô. Gọi ở cuối MỌI bài kiểm động tới kho.
func khopTongVaLo(t *testing.T, h *heThong, c *cuaHang, shopID, variantID uint) {
	t.Helper()

	tong := tonCua(t, h, c, shopID, variantID)
	cong := 0
	for _, l := range docLo(t, h.db, c, shopID, variantID) {
		cong += l.Quantity
	}
	if tong != cong {
		t.Fatalf("lệch giữa tổng tồn và các lô: variant_stocks = %d, SUM(stock_lots) = %d", tong, cong)
	}
}

// datLuatXuatKho bật/tắt hai thông số điều khiển lượt rút lô.
func datLuatXuatKho(t *testing.T, h *heThong, c *cuaHang, cach string, chanHetHan bool) {
	t.Helper()

	chan_ := "0"
	if chanHetHan {
		chan_ = "1"
	}
	res := h.goi(t, c.token, http.MethodPut, "/api/v1/admin/settings", map[string]any{
		"items": map[string]string{
			"lot_issue_method":    cach,
			"block_expired_stock": chan_,
		},
	})
	if res.ma != http.StatusOK {
		t.Fatalf("lưu cấu hình xuất kho trả %d\n%s", res.ma, catBot(res.than))
	}
}

// donKho đưa tồn của biến thể về 0 trước khi dựng kịch bản lô.
//
// Cửa hàng test được gieo sẵn 20 hàng nằm ở lô "Không xác định" — hàng có từ
// trước khi bật tính năng lô. Lô ấy vào kho SỚM NHẤT và KHÔNG có hạn dùng, nên
// FIFO sẽ vét nó trước và mọi bài kiểm thứ tự rút lô đo phải chính nó thay vì
// mấy lô vừa nhập. Dọn sạch rồi mới dựng kịch bản.
func donKho(t *testing.T, h *heThong, c *cuaHang) {
	t.Helper()

	res := h.goiChiNhanh(t, c.token, c.chiNhanh, http.MethodPut,
		fmt.Sprintf("/api/v1/admin/inventory/%d", c.bienThe),
		map[string]any{"mode": "set", "quantity": 0})
	if res.ma != http.StatusOK {
		t.Fatalf("dọn kho về 0 trả %d\n%s", res.ma, catBot(res.than))
	}
	if con := tonCua(t, h, c, c.chiNhanh, c.bienThe); con != 0 {
		t.Fatalf("dọn kho xong phải còn 0, nhận %d", con)
	}
}

// nhapLo duyệt một phiếu mua đưa `sl` hàng của một lô vào kho.
func nhapLo(t *testing.T, h *heThong, c *cuaHang, soLo string, sl int, han string) {
	t.Helper()

	dong := map[string]any{"variant_id": c.bienThe, "quantity": sl, "unit_cost": 10000, "lot_number": soLo}
	if han != "" {
		dong["expire_date"] = han
	}

	// Khai rõ chi nhánh: bài kiểm lô có bài mở thêm kho thứ hai, và từ đó lượt
	// ghi không khai chi nhánh sẽ bị từ chối.
	ma, p := lapPhieuTaiChiNhanh(t, h, c.token, c.chiNhanh, map[string]any{
		"supplier_name": "Cong ty " + c.vet,
		"items":         []any{dong},
	})
	if ma != http.StatusCreated {
		t.Fatalf("lập phiếu nhập lô %q trả %d", soLo, ma)
	}
	duyet(t, h, c, p.ID)
}

// banTaiQuay bán `sl` món ở quầy, trả về id đơn.
func banTaiQuay(t *testing.T, h *heThong, c *cuaHang, sl int) uint {
	t.Helper()

	res := h.goi(t, c.token, http.MethodPost, "/api/v1/admin/orders/pos", map[string]any{
		"payment_method":  "cash",
		"amount_tendered": 10_000_000,
		"customer_name":   "Khách lẻ",
		"items":           []map[string]any{{"product_variant_id": c.bienThe, "quantity": sl}},
	})
	if res.ma != http.StatusCreated {
		t.Fatalf("bán tại quầy trả %d\n%s", res.ma, catBot(res.than))
	}

	var out struct {
		Data struct {
			OrderID uint `json:"order_id"`
		} `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &out); err != nil {
		t.Fatalf("không đọc được kết quả bán: %v\n%s", err, catBot(res.than))
	}

	return out.Data.OrderID
}

// TestLo_DuyetPhieuTachDungHaiLo — một phiếu mua hai lô thì kho ra hai dòng lô.
//
// Đây là điều kiện cần của mọi thứ còn lại: nhập gộp thành một cục thì không còn
// gì để rút theo thứ tự, và cũng không tra ngược được lô nào về theo phiếu nào.
func TestLo_DuyetPhieuTachDungHaiLo(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	truoc := tonCua(t, h, a, a.chiNhanh, a.bienThe)

	ma, p := lapPhieu(t, h, a.token, map[string]any{
		"supplier_name": "Cong ty " + a.vet,
		"items": []any{
			map[string]any{"variant_id": a.bienThe, "quantity": 5, "unit_cost": 10000,
				"lot_number": "LO-A", "expire_date": "2030-01-31"},
			map[string]any{"variant_id": a.bienThe, "quantity": 3, "unit_cost": 12000,
				"lot_number": "LO-B", "expire_date": "2029-01-31"},
		},
	})
	if ma != http.StatusCreated {
		t.Fatalf("lập phiếu trả %d", ma)
	}
	duyet(t, h, a, p.ID)

	ds := docLo(t, h.db, a, a.chiNhanh, a.bienThe)
	if got := soCuaLo(ds, "LO-A"); got != 5 {
		t.Fatalf("lô LO-A phải có 5, nhận %d", got)
	}
	if got := soCuaLo(ds, "LO-B"); got != 3 {
		t.Fatalf("lô LO-B phải có 3, nhận %d", got)
	}
	if sau := tonCua(t, h, a, a.chiNhanh, a.bienThe); sau != truoc+8 {
		t.Fatalf("tổng tồn phải tăng 8, trước %d sau %d", truoc, sau)
	}
	khopTongVaLo(t, h, a, a.chiNhanh, a.bienThe)
}

// TestLo_BanRutFIFO — lô vào kho trước thì ra trước.
func TestLo_BanRutFIFO(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	donKho(t, h, a)
	datLuatXuatKho(t, h, a, "fifo", false)

	nhapLo(t, h, a, "FIFO-1", 4, "2030-12-31")
	nhapLo(t, h, a, "FIFO-2", 4, "2029-12-31") // hết hạn SỚM hơn nhưng vào kho SAU

	banTaiQuay(t, h, a, 5)

	ds := docLo(t, h.db, a, a.chiNhanh, a.bienThe)
	// FIFO: vét sạch lô vào trước (4), rồi lấy 1 của lô sau.
	if got := soCuaLo(ds, "FIFO-1"); got != 0 {
		t.Fatalf("FIFO phải vét lô vào trước xuống 0, nhận %d", got)
	}
	if got := soCuaLo(ds, "FIFO-2"); got != 3 {
		t.Fatalf("lô vào sau phải còn 3, nhận %d", got)
	}
	khopTongVaLo(t, h, a, a.chiNhanh, a.bienThe)
}

// TestLo_BanRutFEFO — lô hết hạn sớm thì ra trước, dù vào kho sau.
//
// Đúng cặp dữ liệu của bài FIFO ở trên, chỉ đổi thông số: hai bài cạnh nhau cho
// thấy thông số thật sự đổi hành vi chứ không phải trùng hợp.
func TestLo_BanRutFEFO(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	donKho(t, h, a)
	datLuatXuatKho(t, h, a, "fefo", false)

	nhapLo(t, h, a, "FEFO-1", 4, "2030-12-31")
	nhapLo(t, h, a, "FEFO-2", 4, "2029-12-31") // hết hạn sớm hơn

	banTaiQuay(t, h, a, 5)

	ds := docLo(t, h.db, a, a.chiNhanh, a.bienThe)
	if got := soCuaLo(ds, "FEFO-2"); got != 0 {
		t.Fatalf("FEFO phải vét lô hết hạn sớm xuống 0, nhận %d", got)
	}
	if got := soCuaLo(ds, "FEFO-1"); got != 3 {
		t.Fatalf("lô hạn dài phải còn 3, nhận %d", got)
	}
	khopTongVaLo(t, h, a, a.chiNhanh, a.bienThe)
}

// TestLo_HangKhongHanXuongCuoiTrongFEFO — hàng không có hạn dùng không chen lên đầu.
//
// MySQL xếp NULL lên đầu, nên viết `ORDER BY expire_date` không thôi là FEFO đem
// bán hàng không hạn trước cả lô sắp hết hạn — ngược hẳn ý của FEFO.
func TestLo_HangKhongHanXuongCuoiTrongFEFO(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	donKho(t, h, a)
	datLuatXuatKho(t, h, a, "fefo", false)

	nhapLo(t, h, a, "KHONG-HAN", 4, "")
	nhapLo(t, h, a, "CO-HAN", 4, "2029-12-31")

	banTaiQuay(t, h, a, 4)

	ds := docLo(t, h.db, a, a.chiNhanh, a.bienThe)
	if got := soCuaLo(ds, "CO-HAN"); got != 0 {
		t.Fatalf("FEFO phải bán lô CÓ HẠN trước, lô đó phải về 0, nhận %d", got)
	}
	if got := soCuaLo(ds, "KHONG-HAN"); got != 4 {
		t.Fatalf("lô không hạn phải còn nguyên 4, nhận %d", got)
	}
	khopTongVaLo(t, h, a, a.chiNhanh, a.bienThe)
}

// TestLo_ChanHangQuaHan — bật công tắc thì lô quá hạn không bán ra được.
//
// Kho vẫn còn hàng, nên lượt bán KHÔNG được lặng lẽ lấy hàng hết hạn ra bán.
func TestLo_ChanHangQuaHan(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	donKho(t, h, a)
	datLuatXuatKho(t, h, a, "fifo", true)

	homQua := time.Now().AddDate(0, 0, -1).Format("2006-01-02")
	nhapLo(t, h, a, "HET-HAN", 5, homQua)
	nhapLo(t, h, a, "CON-HAN", 2, "2030-12-31")

	banTaiQuay(t, h, a, 2)

	ds := docLo(t, h.db, a, a.chiNhanh, a.bienThe)
	if got := soCuaLo(ds, "HET-HAN"); got != 5 {
		t.Fatalf("lô quá hạn phải nằm nguyên trong kho, nhận %d", got)
	}
	if got := soCuaLo(ds, "CON-HAN"); got != 0 {
		t.Fatalf("phải bán lô còn hạn, lô đó phải về 0, nhận %d", got)
	}
	khopTongVaLo(t, h, a, a.chiNhanh, a.bienThe)
}

// TestLo_HuyDonHoanVeDungLo — huỷ đơn thì hàng về đúng lô đã lấy.
//
// Đây là lý do sổ stock_lot_moves tồn tại. Hoàn đại vào lô "Không xác định" thì
// mỗi vòng bán–trả bào mòn một ít khỏi các lô có thật, và sau vài tháng bảng lô
// chỉ còn một cục vô danh — đúng thứ tính năng này sinh ra để tránh.
func TestLo_HuyDonHoanVeDungLo(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	donKho(t, h, a)
	datLuatXuatKho(t, h, a, "fifo", false)

	nhapLo(t, h, a, "HOAN-1", 6, "2030-12-31")

	// Đơn ĐẶT (không phải bán tại quầy): đơn quầy chốt xong là `completed` và
	// không huỷ được nữa, mà cái cần đo ở đây là đường hoàn kho.
	res := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/orders", map[string]any{
		"user_id": a.khach, "recipient_name": "Khach " + a.vet,
		"recipient_phone": "0900000009", "shipping_address": "x", "payment_method": "cod",
		"items": []map[string]any{{
			"product_variant_id": a.bienThe, "product_name": "Hang " + a.vet,
			"unit_price": 20000, "quantity": 4,
		}},
	})
	if res.ma != http.StatusCreated {
		t.Fatalf("đặt đơn trả %d\n%s", res.ma, catBot(res.than))
	}
	var dat struct {
		Data struct {
			ID uint `json:"id"`
		} `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &dat); err != nil {
		t.Fatalf("không đọc được đơn vừa đặt: %v\n%s", err, catBot(res.than))
	}
	donID := dat.Data.ID

	if got := soCuaLo(docLo(t, h.db, a, a.chiNhanh, a.bienThe), "HOAN-1"); got != 2 {
		t.Fatalf("bán 4 xong lô phải còn 2, nhận %d", got)
	}

	res = h.goi(t, a.token, http.MethodPut,
		fmt.Sprintf("/api/v1/admin/orders/%d/status", donID),
		map[string]any{"status": "cancelled"})
	if res.ma != http.StatusOK {
		t.Fatalf("huỷ đơn trả %d\n%s", res.ma, catBot(res.than))
	}

	ds := docLo(t, h.db, a, a.chiNhanh, a.bienThe)
	if got := soCuaLo(ds, "HOAN-1"); got != 6 {
		t.Fatalf("huỷ đơn phải hoàn về ĐÚNG lô đã lấy (6), nhận %d", got)
	}
	if got := soCuaLo(ds, domain.LoKhongXacDinh); got != 0 {
		t.Fatalf("không được dồn hàng hoàn vào lô Không xác định, nhận %d", got)
	}
	khopTongVaLo(t, h, a, a.chiNhanh, a.bienThe)
}

// TestLo_TraNhaCungCapTruDungLo — trả lô nào thì trừ đúng lô ấy.
//
// Để FIFO tự chọn ở đây là trả lô A cho bên bán mà trong sổ lô B vơi đi, và lần
// kiểm kê sau không ai hiểu vì sao.
func TestLo_TraNhaCungCapTruDungLo(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	donKho(t, h, a)

	// Phiếu trả bắt buộc trỏ về một nhà cung cấp và một phiếu mua có thật.
	res := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/nha-cung-cap", map[string]any{
		"name": "Cong ty Lo " + a.vet, "address": "12 Le Loi",
	})
	var ncc struct {
		Data struct {
			ID uint `json:"id"`
		} `json:"data"`
	}
	_ = json.Unmarshal([]byte(res.than), &ncc)
	if ncc.Data.ID == 0 {
		t.Fatalf("tạo nhà cung cấp hỏng %d\n%s", res.ma, catBot(res.than))
	}

	// Một phiếu, hai lô: lô đầu KHÔNG trả, lô sau trả 2.
	ma, p := lapPhieu(t, h, a.token, map[string]any{
		"supplier_id": ncc.Data.ID,
		"items": []any{
			map[string]any{"variant_id": a.bienThe, "quantity": 5, "unit_cost": 10000, "lot_number": "TRA-CU"},
			map[string]any{"variant_id": a.bienThe, "quantity": 5, "unit_cost": 10000, "lot_number": "TRA-MOI"},
		},
	})
	if ma != http.StatusCreated {
		t.Fatalf("lập phiếu mua trả %d", ma)
	}
	duyet(t, h, a, p.ID)

	// Dòng phiếu mua của lô sẽ trả.
	var poiID uint
	ctx := tenant.WithID(context.Background(), a.id)
	err := h.db.WithContext(ctx).Model(&domain.PurchaseOrderItem{}).
		Where("purchase_order_id = ? AND lot_number = ?", p.ID, "TRA-MOI").
		Limit(1).Pluck("id", &poiID).Error
	if err != nil || poiID == 0 {
		t.Fatalf("không tìm được dòng phiếu mua của lô TRA-MOI: %v", err)
	}

	res = h.goi(t, a.token, http.MethodPost, "/api/v1/admin/tra-hang-nha-cung-cap", map[string]any{
		"supplier_id":       ncc.Data.ID,
		"purchase_order_id": p.ID,
		"items":             []any{map[string]any{"purchase_item_id": poiID, "quantity": 2}},
	})
	if res.ma != http.StatusCreated {
		t.Fatalf("lập phiếu trả NCC trả %d\n%s", res.ma, catBot(res.than))
	}

	var tao struct {
		Data struct {
			ID uint `json:"id"`
		} `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &tao); err != nil {
		t.Fatalf("không đọc được phiếu trả: %v\n%s", err, catBot(res.than))
	}

	res = h.goi(t, a.token, http.MethodPost,
		fmt.Sprintf("/api/v1/admin/tra-hang-nha-cung-cap/%d/duyet", tao.Data.ID), nil)
	if res.ma != http.StatusOK {
		t.Fatalf("duyệt phiếu trả NCC trả %d\n%s", res.ma, catBot(res.than))
	}

	ds := docLo(t, h.db, a, a.chiNhanh, a.bienThe)
	if got := soCuaLo(ds, "TRA-CU"); got != 5 {
		t.Fatalf("lô KHÔNG trả phải còn nguyên 5, nhận %d", got)
	}
	if got := soCuaLo(ds, "TRA-MOI"); got != 3 {
		t.Fatalf("lô đã trả 2 phải còn 3, nhận %d", got)
	}
	khopTongVaLo(t, h, a, a.chiNhanh, a.bienThe)
}

// TestLo_HaiCuaHangKhongLanLo — lô trùng tên ở hai cửa hàng là hai lô khác nhau.
//
// Hai tiệm đều đặt lô "A" là chuyện thường. Lẫn nguồn thì bán ở tiệm này trừ vào
// lô của tiệm kia — kiểu hỏng không bao giờ lộ ra trên màn hình của ai cả.
func TestLo_HaiCuaHangKhongLanLo(t *testing.T) {
	h := dungHeThong(t)
	a, b := haiCuaHang(t, h)

	nhapLo(t, h, a, "TRUNG-TEN", 5, "2030-12-31")
	nhapLo(t, h, b, "TRUNG-TEN", 7, "2030-12-31")

	if got := soCuaLo(docLo(t, h.db, a, a.chiNhanh, a.bienThe), "TRUNG-TEN"); got != 5 {
		t.Fatalf("lô của cửa hàng A phải là 5, nhận %d", got)
	}
	if got := soCuaLo(docLo(t, h.db, b, b.chiNhanh, b.bienThe), "TRUNG-TEN"); got != 7 {
		t.Fatalf("lô của cửa hàng B phải là 7, nhận %d", got)
	}
	khopTongVaLo(t, h, a, a.chiNhanh, a.bienThe)
	khopTongVaLo(t, h, b, b.chiNhanh, b.bienThe)
}
