package apitest

import (
	"encoding/json"
	"fmt"
	"net/http"
	"testing"
)

// Bài kiểm TRẢ HÀNG NHÀ CUNG CẤP qua API thật và MySQL thật.
//
// Chỗ đáng hỏng của chứng từ này cũng là đường ghi kho, chỉ ngược chiều:
//
//   - phiếu lưu tạm không được đụng tới kho;
//   - duyệt TRỪ đúng số đã quy đổi (trả 1 thùng 24 cái = 24 cái rời kho);
//   - duyệt hai lần chỉ trừ một lần;
//   - phiếu đã duyệt không sửa/xoá được nữa;
//   - không trả quá số ĐÃ MUA, tính cộng dồn qua mọi phiếu trả đã duyệt — đây
//     đúng là chỗ bản order v2 bỏ ngỏ (đoạn tính bị chú thích lại), nên một
//     phiếu mua trả được vô số lần;
//   - không trả quá số kho ĐANG CÓ;
//   - hai cửa hàng không đọc/ghi được phiếu của nhau.

const duongTraHang = "/api/v1/admin/tra-hang-nha-cung-cap"

type phieuTra struct {
	ID           uint    `json:"id"`
	ReturnCode   string  `json:"return_code"`
	Status       string  `json:"status"`
	ItemsAmount  float64 `json:"items_amount"`
	VATAmount    float64 `json:"vat_amount"`
	TotalAmount  float64 `json:"total_amount"`
	SupplierName string  `json:"supplier_name"`
	SupplierCode string  `json:"supplier_code"`
	CanEdit      bool    `json:"can_edit"`
	CanApprove   bool    `json:"can_approve"`
	Items        []struct {
		PurchaseOrderItemID uint    `json:"purchase_order_item_id"`
		Quantity            int     `json:"quantity"`
		BaseQuantity        int     `json:"base_quantity"`
		UnitRatio           float64 `json:"unit_ratio"`
		UnitCost            float64 `json:"unit_cost"`
		VATPercent          int     `json:"vat_percent"`
		LineAmount          float64 `json:"line_amount"`
		TotalCost           float64 `json:"total_cost"`
		LotNumber           string  `json:"lot_number"`
		PurchaseQuantity    int     `json:"purchase_quantity"`
	} `json:"items"`
}

type dongTraDuoc struct {
	PurchaseItemID uint    `json:"purchase_item_id"`
	Quantity       int     `json:"quantity"`
	UnitCost       float64 `json:"unit_cost"`
	UnitRatio      float64 `json:"unit_ratio"`
	Returned       int     `json:"returned"`
	Stock          int     `json:"stock"`
	Returnable     int     `json:"returnable"`
}

// ---------- helper riêng của nhóm bài kiểm này ----------

// benBan khai một nhà cung cấp rồi trả id — phiếu trả bắt buộc có bên bán.
func benBan(t *testing.T, h *heThong, c *cuaHang, ten string) uint {
	t.Helper()

	ma, ncc := themNCC(t, h, c.token, map[string]any{
		"name":    ten + " " + c.vet,
		"address": "So 1 duong " + c.vet,
		"phone":   "0900000001",
	})
	if ma != http.StatusCreated {
		t.Fatalf("tạo nhà cung cấp %q trả %d", ten, ma)
	}

	return ncc.ID
}

// muaVaDuyet lập một phiếu mua của NCC rồi duyệt luôn — dựng sẵn lô hàng để trả.
func muaVaDuyet(t *testing.T, h *heThong, c *cuaHang, nccID uint, dong ...map[string]any) phieuMua {
	t.Helper()

	than := map[string]any{
		"supplier_id":   nccID,
		"supplier_name": "Cong ty " + c.vet,
		"items":         dongBatKy(dong),
	}
	ma, p := lapPhieu(t, h, c.token, than)
	if ma != http.StatusCreated {
		t.Fatalf("lập phiếu mua trả %d", ma)
	}
	duyet(t, h, c, p.ID)

	return docPhieu(t, h, c.token, p.ID)
}

func dongBatKy(dong []map[string]any) []any {
	out := make([]any, 0, len(dong))
	for _, d := range dong {
		out = append(out, d)
	}

	return out
}

// dongTra dựng payload một dòng trả.
func dongTra(purchaseItemID uint, sl int) map[string]any {
	return map[string]any{"purchase_item_id": purchaseItemID, "quantity": sl}
}

func lapPhieuTra(t *testing.T, h *heThong, token string, than map[string]any) (int, phieuTra) {
	t.Helper()

	res := h.goi(t, token, http.MethodPost, duongTraHang, than)

	var body struct {
		Data phieuTra `json:"data"`
	}
	_ = json.Unmarshal([]byte(res.than), &body)

	return res.ma, body.Data
}

func docPhieuTra(t *testing.T, h *heThong, token string, id uint) phieuTra {
	t.Helper()

	res := h.goi(t, token, http.MethodGet, fmt.Sprintf("%s/%d", duongTraHang, id), nil)
	if res.ma != http.StatusOK {
		t.Fatalf("đọc phiếu trả %d trả %d\n%s", id, res.ma, catBot(res.than))
	}

	var body struct {
		Data phieuTra `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &body); err != nil {
		t.Fatalf("không đọc được phản hồi: %v\n%s", err, catBot(res.than))
	}

	return body.Data
}

// duyetTra bấm nút Duyệt của phiếu trả và đòi 200.
func duyetTra(t *testing.T, h *heThong, c *cuaHang, id uint) {
	t.Helper()

	res := h.goi(t, c.token, http.MethodPost, fmt.Sprintf("%s/%d/duyet", duongTraHang, id), nil)
	if res.ma != http.StatusOK {
		t.Fatalf("duyệt phiếu trả %d trả %d\n%s", id, res.ma, catBot(res.than))
	}
}

// dongCuaPhieuMua đọc các dòng trả được của một phiếu mua.
func dongCuaPhieuMua(t *testing.T, h *heThong, c *cuaHang, purchaseID uint) []dongTraDuoc {
	t.Helper()

	res := h.goi(t, c.token, http.MethodGet,
		fmt.Sprintf("%s/dong-phieu-mua?purchase_id=%d", duongTraHang, purchaseID), nil)
	if res.ma != http.StatusOK {
		t.Fatalf("đọc dòng phiếu mua trả %d\n%s", res.ma, catBot(res.than))
	}

	var body struct {
		Data struct {
			Lines []dongTraDuoc `json:"lines"`
		} `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &body); err != nil {
		t.Fatalf("không đọc được dòng phiếu mua: %v\n%s", err, catBot(res.than))
	}

	return body.Data.Lines
}

// ---------- bài kiểm ----------

// TestTraHang_DongPhieuMuaKemNguoiMua — đường đọc dòng phiếu mua phải nói ra
// NHÂN VIÊN MUA HÀNG ghi trên phiếu mua ấy.
//
// Màn lập phiếu trả điền sẵn ô cùng tên theo con số này: trả lô hàng của phiếu
// nào thì người mua lô ấy là người biết chuyện. Thiếu nó thì màn hình chỉ còn
// cách bắt người dùng chọn lại từ đầu giữa cả danh sách nhân viên.
func TestTraHang_DongPhieuMuaKemNguoiMua(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	ncc := benBan(t, h, a, "Ben ban")

	const nguoiMua = 7
	ma, pm := lapPhieu(t, h, a.token, map[string]any{
		"supplier_id":   ncc,
		"supplier_name": "Cong ty " + a.vet,
		"purchaser_id":  nguoiMua,
		"items":         dongBatKy([]map[string]any{dongHang(a.bienThe, 5, 10000)}),
	})
	if ma != http.StatusCreated {
		t.Fatalf("lập phiếu mua trả %d", ma)
	}
	duyet(t, h, a, pm.ID)

	res := h.goi(t, a.token, http.MethodGet,
		fmt.Sprintf("%s/dong-phieu-mua?purchase_id=%d", duongTraHang, pm.ID), nil)
	if res.ma != http.StatusOK {
		t.Fatalf("đọc dòng phiếu mua trả %d\n%s", res.ma, catBot(res.than))
	}

	var body struct {
		Data struct {
			PurchaserID uint `json:"purchaser_id"`
		} `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &body); err != nil {
		t.Fatalf("không đọc được phản hồi: %v\n%s", err, catBot(res.than))
	}
	if body.Data.PurchaserID != nguoiMua {
		t.Fatalf("phải trả về người mua %d của phiếu mua, nhận %d", nguoiMua, body.Data.PurchaserID)
	}
}

// TestTraHang_LuuTamKhongDungKho — phiếu lưu tạm chỉ là tờ giấy, kho chưa đổi.
func TestTraHang_LuuTamKhongDungKho(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	ncc := benBan(t, h, a, "Cong ty")
	pm := muaVaDuyet(t, h, a, ncc, dongHang(a.bienThe, 10, 10000))

	sauMua := tonCua(t, h, a, a.chiNhanh, a.bienThe)

	dong := dongCuaPhieuMua(t, h, a, pm.ID)
	if len(dong) != 1 {
		t.Fatalf("phiếu mua phải có 1 dòng trả được, nhận %d", len(dong))
	}
	if dong[0].Returnable != 10 {
		t.Fatalf("chưa trả lần nào thì trả được đủ 10, nhận %d", dong[0].Returnable)
	}

	ma, p := lapPhieuTra(t, h, a.token, map[string]any{
		"supplier_id":       ncc,
		"purchase_order_id": pm.ID,
		"items":             []any{dongTra(dong[0].PurchaseItemID, 4)},
	})
	if ma != http.StatusCreated {
		t.Fatalf("lập phiếu trả phải trả 201, nhận %d", ma)
	}
	if p.Status != "draft" {
		t.Fatalf("phiếu mới lập phải là phiếu lưu tạm, nhận %q", p.Status)
	}
	if p.ReturnCode == "" {
		t.Fatal("phiếu trả phải có mã — không có mã thì không ai gọi tên nó được")
	}
	if p.TotalAmount != 40000 {
		t.Fatalf("tổng tiền phải là 40.000, nhận %v", p.TotalAmount)
	}
	// Hồ sơ bên bán chụp từ DB chứ không nhận từ client.
	if p.SupplierName == "" || p.SupplierCode == "" {
		t.Fatalf("phiếu phải chụp mã/tên bên bán, nhận %q / %q", p.SupplierCode, p.SupplierName)
	}

	if sau := tonCua(t, h, a, a.chiNhanh, a.bienThe); sau != sauMua {
		t.Fatalf("phiếu trả LƯU TẠM không được đụng tới kho: trước %d, sau %d", sauMua, sau)
	}
}

// TestTraHang_DuyetTruDungSoQuyDoi — trả theo THÙNG, kho trừ theo CÁI.
func TestTraHang_DuyetTruDungSoQuyDoi(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	thung := taoDonVi(t, h, a, "Thung "+a.vet)
	khaiQuyDoi(t, h, a, thung, 24)

	ncc := benBan(t, h, a, "Cong ty")
	pm := muaVaDuyet(t, h, a, ncc, map[string]any{
		"variant_id": a.bienThe, "unit_id": thung, "quantity": 3, "unit_cost": 240000,
	})

	sauMua := tonCua(t, h, a, a.chiNhanh, a.bienThe)

	dong := dongCuaPhieuMua(t, h, a, pm.ID)
	_, p := lapPhieuTra(t, h, a.token, map[string]any{
		"supplier_id":       ncc,
		"purchase_order_id": pm.ID,
		"items":             []any{dongTra(dong[0].PurchaseItemID, 1)},
	})
	if len(p.Items) != 1 {
		t.Fatalf("phiếu trả phải có đúng 1 dòng, nhận %d", len(p.Items))
	}
	if p.Items[0].Quantity != 1 {
		t.Fatalf("dòng phiếu phải giữ số TRẢ là 1 thùng, nhận %d", p.Items[0].Quantity)
	}
	if p.Items[0].BaseQuantity != 24 {
		t.Fatalf("số rời kho phải là 24 (1 × 24), nhận %d", p.Items[0].BaseQuantity)
	}
	// Giá nhập lấy lại từ dòng phiếu mua, client không gửi đồng nào.
	if p.Items[0].UnitCost != 240000 {
		t.Fatalf("giá nhập phải chụp từ phiếu mua (240.000), nhận %v", p.Items[0].UnitCost)
	}

	duyetTra(t, h, a, p.ID)

	sau := tonCua(t, h, a, a.chiNhanh, a.bienThe)
	if sauMua-sau != 24 {
		t.Fatalf("duyệt phải trừ 24 khỏi kho, thực tế trừ %d", sauMua-sau)
	}
}

// TestTraHang_DuyetHaiLanChiTruMotLan — chốt chặn duyệt trùng.
func TestTraHang_DuyetHaiLanChiTruMotLan(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	ncc := benBan(t, h, a, "Cong ty")
	pm := muaVaDuyet(t, h, a, ncc, dongHang(a.bienThe, 9, 1000))
	sauMua := tonCua(t, h, a, a.chiNhanh, a.bienThe)

	dong := dongCuaPhieuMua(t, h, a, pm.ID)
	_, p := lapPhieuTra(t, h, a.token, map[string]any{
		"supplier_id":       ncc,
		"purchase_order_id": pm.ID,
		"items":             []any{dongTra(dong[0].PurchaseItemID, 5)},
	})
	duyetTra(t, h, a, p.ID)

	res := h.goi(t, a.token, http.MethodPost, fmt.Sprintf("%s/%d/duyet", duongTraHang, p.ID), nil)
	if res.ma != http.StatusConflict {
		t.Fatalf("duyệt lần hai phải trả 409, nhận %d\n%s", res.ma, catBot(res.than))
	}

	if sau := tonCua(t, h, a, a.chiNhanh, a.bienThe); sauMua-sau != 5 {
		t.Fatalf("duyệt hai lần chỉ được trừ 5, thực tế trừ %d", sauMua-sau)
	}
}

// TestTraHang_DaDuyetThiKhoaLai — sửa và xoá phiếu đã duyệt đều bị từ chối.
func TestTraHang_DaDuyetThiKhoaLai(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	ncc := benBan(t, h, a, "Cong ty")
	pm := muaVaDuyet(t, h, a, ncc, dongHang(a.bienThe, 8, 5000))
	dong := dongCuaPhieuMua(t, h, a, pm.ID)

	_, p := lapPhieuTra(t, h, a.token, map[string]any{
		"supplier_id":       ncc,
		"purchase_order_id": pm.ID,
		"items":             []any{dongTra(dong[0].PurchaseItemID, 2)},
	})
	duyetTra(t, h, a, p.ID)

	sauDuyet := tonCua(t, h, a, a.chiNhanh, a.bienThe)

	res := h.goi(t, a.token, http.MethodPut, fmt.Sprintf("%s/%d", duongTraHang, p.ID), map[string]any{
		"supplier_id":       ncc,
		"purchase_order_id": pm.ID,
		"items":             []any{dongTra(dong[0].PurchaseItemID, 6)},
	})
	if res.ma != http.StatusConflict {
		t.Fatalf("sửa phiếu trả đã duyệt phải trả 409, nhận %d\n%s", res.ma, catBot(res.than))
	}
	if sau := tonCua(t, h, a, a.chiNhanh, a.bienThe); sau != sauDuyet {
		t.Fatalf("lượt sửa bị từ chối mà kho vẫn đổi: %d → %d", sauDuyet, sau)
	}

	res = h.goi(t, a.token, http.MethodDelete, fmt.Sprintf("%s/%d", duongTraHang, p.ID), nil)
	if res.ma != http.StatusConflict {
		t.Fatalf("xoá phiếu trả đã duyệt phải trả 409, nhận %d\n%s", res.ma, catBot(res.than))
	}

	// Cờ trả về phải nói đúng sự thật — giao diện dựng nút từ đây.
	sauCung := docPhieuTra(t, h, a.token, p.ID)
	if sauCung.CanEdit || sauCung.CanApprove {
		t.Fatalf("phiếu đã duyệt phải trả can_edit=false, can_approve=false; nhận %v/%v",
			sauCung.CanEdit, sauCung.CanApprove)
	}
}

// TestTraHang_KhongTraQuaSoDaMua — cộng dồn qua MỌI phiếu trả đã duyệt.
//
// Đây là chỗ bản order v2 bỏ ngỏ: đoạn trừ đi phần đã trả bị chú thích lại, nên
// một phiếu mua 10 cái trả được 10 cái nhiều lần liên tiếp.
func TestTraHang_KhongTraQuaSoDaMua(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	ncc := benBan(t, h, a, "Cong ty")
	// Mua hai lượt để kho dư hàng: trần phải chặn vì SỐ ĐÃ MUA của DÒNG, không
	// phải vì kho hết hàng.
	pm := muaVaDuyet(t, h, a, ncc, dongHang(a.bienThe, 10, 1000))
	muaVaDuyet(t, h, a, ncc, dongHang(a.bienThe, 50, 1000))

	dong := dongCuaPhieuMua(t, h, a, pm.ID)
	poi := dong[0].PurchaseItemID

	_, p1 := lapPhieuTra(t, h, a.token, map[string]any{
		"supplier_id": ncc, "purchase_order_id": pm.ID,
		"items": []any{dongTra(poi, 7)},
	})
	duyetTra(t, h, a, p1.ID)

	// Còn 3 — đọc lại phải nói đúng con số đó.
	dong = dongCuaPhieuMua(t, h, a, pm.ID)
	if dong[0].Returned != 7 || dong[0].Returnable != 3 {
		t.Fatalf("sau khi trả 7/10 thì phải là returned=7, returnable=3; nhận %d/%d",
			dong[0].Returned, dong[0].Returnable)
	}

	// Xin trả thêm 4 (tổng 11 > 10) — phải bị từ chối ngay lúc lập.
	res := h.goi(t, a.token, http.MethodPost, duongTraHang, map[string]any{
		"supplier_id": ncc, "purchase_order_id": pm.ID,
		"items": []any{dongTra(poi, 4)},
	})
	if res.ma != http.StatusUnprocessableEntity {
		t.Fatalf("trả quá số đã mua phải trả 422, nhận %d\n%s", res.ma, catBot(res.than))
	}

	// Trả nốt đúng 3 thì được.
	ma, p2 := lapPhieuTra(t, h, a.token, map[string]any{
		"supplier_id": ncc, "purchase_order_id": pm.ID,
		"items": []any{dongTra(poi, 3)},
	})
	if ma != http.StatusCreated {
		t.Fatalf("trả nốt 3 cái cuối phải được, nhận %d", ma)
	}
	duyetTra(t, h, a, p2.ID)

	dong = dongCuaPhieuMua(t, h, a, pm.ID)
	if dong[0].Returnable != 0 {
		t.Fatalf("trả hết rồi thì returnable phải là 0, nhận %d", dong[0].Returnable)
	}
}

// TestTraHang_KhongTraQuaTonKho — mua 10 nhưng kho chỉ còn 4 thì trả được 4.
//
// Trần là min(còn được trả, tồn còn lại): hàng đã bán bớt rồi thì không thể trả
// lại bên bán số mình không còn giữ.
func TestTraHang_KhongTraQuaTonKho(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	ncc := benBan(t, h, a, "Cong ty")
	pm := muaVaDuyet(t, h, a, ncc, dongHang(a.bienThe, 10, 1000))
	dong := dongCuaPhieuMua(t, h, a, pm.ID)
	poi := dong[0].PurchaseItemID
	tonBanDau := dong[0].Stock

	// Trả bớt 6 để kho hụt đi — cách gọn nhất để dựng cảnh "kho ít hơn số đã mua"
	// mà không phải đi qua màn bán hàng.
	_, pTruoc := lapPhieuTra(t, h, a.token, map[string]any{
		"supplier_id": ncc, "purchase_order_id": pm.ID,
		"items": []any{dongTra(poi, 6)},
	})
	duyetTra(t, h, a, pTruoc.ID)

	con := tonCua(t, h, a, a.chiNhanh, a.bienThe)
	if tonBanDau-con != 6 {
		t.Fatalf("trả 6 thì kho phải hụt 6, thực tế hụt %d", tonBanDau-con)
	}

	// Xin trả 5 nữa: dòng còn được trả 4, nên phải bị từ chối.
	res := h.goi(t, a.token, http.MethodPost, duongTraHang, map[string]any{
		"supplier_id": ncc, "purchase_order_id": pm.ID,
		"items": []any{dongTra(poi, 5)},
	})
	if res.ma != http.StatusUnprocessableEntity {
		t.Fatalf("trả quá phần còn lại phải trả 422, nhận %d\n%s", res.ma, catBot(res.than))
	}
}

// TestTraHang_PhieuMuaCuaBenBanKhac — trả hàng cho nhầm nhà cung cấp thì sổ
// công nợ và chứng từ in ra đều sai.
func TestTraHang_PhieuMuaCuaBenBanKhac(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	ncc1 := benBan(t, h, a, "Cong ty")

	ncc2 := benBan(t, h, a, "Cong ty hai")

	pm := muaVaDuyet(t, h, a, ncc1, dongHang(a.bienThe, 5, 1000))
	dong := dongCuaPhieuMua(t, h, a, pm.ID)

	res := h.goi(t, a.token, http.MethodPost, duongTraHang, map[string]any{
		"supplier_id": ncc2, "purchase_order_id": pm.ID,
		"items": []any{dongTra(dong[0].PurchaseItemID, 1)},
	})
	if res.ma != http.StatusUnprocessableEntity {
		t.Fatalf("phiếu mua của bên bán khác phải trả 422, nhận %d\n%s", res.ma, catBot(res.than))
	}
}

// TestTraHang_PhieuMuaChuaDuyetThiChuaTraDuoc — hàng chưa vào kho thì chưa có
// gì để trả.
func TestTraHang_PhieuMuaChuaDuyetThiChuaTraDuoc(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	ncc := benBan(t, h, a, "Cong ty")
	_, pm := lapPhieu(t, h, a.token, map[string]any{
		"supplier_id":   ncc,
		"supplier_name": "Cong ty " + a.vet,
		"items":         []any{dongHang(a.bienThe, 5, 1000)},
	})

	res := h.goi(t, a.token, http.MethodGet,
		fmt.Sprintf("%s/dong-phieu-mua?purchase_id=%d", duongTraHang, pm.ID), nil)
	if res.ma != http.StatusUnprocessableEntity {
		t.Fatalf("đọc dòng của phiếu mua chưa duyệt phải trả 422, nhận %d\n%s", res.ma, catBot(res.than))
	}
}

// TestTraHang_HaiCuaHangKhongThayNhau — cô lập tenant.
func TestTraHang_HaiCuaHangKhongThayNhau(t *testing.T) {
	h := dungHeThong(t)
	a, b := haiCuaHang(t, h)

	ncc := benBan(t, h, a, "Cong ty")
	pm := muaVaDuyet(t, h, a, ncc, dongHang(a.bienThe, 5, 1000))
	dong := dongCuaPhieuMua(t, h, a, pm.ID)

	_, p := lapPhieuTra(t, h, a.token, map[string]any{
		"supplier_id": ncc, "purchase_order_id": pm.ID,
		"items": []any{dongTra(dong[0].PurchaseItemID, 2)},
	})

	res := h.goi(t, b.token, http.MethodGet, fmt.Sprintf("%s/%d", duongTraHang, p.ID), nil)
	if res.ma != http.StatusNotFound {
		t.Fatalf("cửa hàng B đọc phiếu của A phải trả 404, nhận %d\n%s", res.ma, catBot(res.than))
	}

	res = h.goi(t, b.token, http.MethodPost, fmt.Sprintf("%s/%d/duyet", duongTraHang, p.ID), nil)
	if res.ma != http.StatusNotFound {
		t.Fatalf("cửa hàng B duyệt phiếu của A phải trả 404, nhận %d\n%s", res.ma, catBot(res.than))
	}

	res = h.goi(t, b.token, http.MethodGet, duongTraHang, nil)
	if res.ma != http.StatusOK {
		t.Fatalf("đọc danh sách phiếu trả của B trả %d", res.ma)
	}
	if chuaDauVet(res.than, a.vet) {
		t.Fatalf("danh sách phiếu trả của B lộ dấu vết cửa hàng A (%s)", a.vet)
	}
}
