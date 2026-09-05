package apitest

import (
	"encoding/json"
	"fmt"
	"net/http"
	"strings"
	"testing"
)

// Bài kiểm PHIẾU MUA HÀNG qua API thật và MySQL thật.
//
// Chỗ đáng hỏng của chứng từ này không phải CRUD mà là ĐƯỜNG GHI KHO:
//
//   - phiếu lưu tạm không được đụng tới kho;
//   - duyệt cộng đúng số ĐÃ QUY ĐỔI (mua 2 thùng 24 cái = 48 cái vào kho);
//   - duyệt hai lần chỉ cộng một lần;
//   - phiếu đã duyệt không sửa được nữa — đây đúng là chỗ bản order v2 cộng
//     kho lặp mỗi lượt lưu (xem migration 0041);
//   - hai cửa hàng không đọc/ghi được phiếu của nhau.

type phieuMua struct {
	ID            uint    `json:"id"`
	POCode        string  `json:"po_code"`
	Status        string  `json:"status"`
	ItemsAmount   float64 `json:"items_amount"`
	VATAmount     float64 `json:"vat_amount"`
	TotalAmount   float64 `json:"total_amount"`
	PaidAmount    float64 `json:"paid_amount"`
	PaymentStatus string  `json:"payment_status"`
	PaymentMethod string  `json:"payment_method"`
	IsDebt        bool    `json:"is_debt"`
	DebtDueDate   *string `json:"debt_due_date"`
	DebtName      string  `json:"debt_contact_name"`
	DebtPhone     string  `json:"debt_contact_phone"`
	PayAttachment string  `json:"payment_attachment"`
	SupplierName  string  `json:"supplier_name"`
	CanEdit       bool    `json:"can_edit"`
	CanApprove    bool    `json:"can_approve"`
	CanPay        bool    `json:"can_pay"`
	Payments      []struct {
		Amount        float64 `json:"amount"`
		PaidAfter     float64 `json:"paid_after"`
		PaymentMethod string  `json:"payment_method"`
		Note          string  `json:"note"`
	} `json:"payments"`
	Items []struct {
		Quantity     int     `json:"quantity"`
		BaseQuantity int     `json:"base_quantity"`
		UnitRatio    float64 `json:"unit_ratio"`
		UnitCost     float64 `json:"unit_cost"`
		VATPercent   int     `json:"vat_percent"`
		TotalCost    float64 `json:"total_cost"`
		LotNumber    string  `json:"lot_number"`
		ExpireDate   *string `json:"expire_date"`
	} `json:"items"`
}

const duongPhieuMua = "/api/v1/admin/phieu-mua-hang"

func lapPhieu(t *testing.T, h *heThong, token string, than map[string]any) (int, phieuMua) {
	t.Helper()

	res := h.goi(t, token, http.MethodPost, duongPhieuMua, than)

	return doPhieu(res)
}

// lapPhieuTaiChiNhanh lập phiếu KÈM header chi nhánh đang làm việc.
//
// Cần từ lúc đường ghi thôi tự đoán chi nhánh: cửa hàng có từ hai chi nhánh trở
// lên mà không khai mình đứng ở đâu thì API trả 409 chứ không lặng lẽ ghi vào
// chi nhánh id nhỏ nhất nữa (xem repository.chiNhanhCuaRequest).
func lapPhieuTaiChiNhanh(
	t *testing.T, h *heThong, token string, shopID uint, than map[string]any,
) (int, phieuMua) {
	t.Helper()

	res := h.goiChiNhanh(t, token, shopID, http.MethodPost, duongPhieuMua, than)

	return doPhieu(res)
}

func doPhieu(res traLoi) (int, phieuMua) {

	var body struct {
		Data phieuMua `json:"data"`
	}
	_ = json.Unmarshal([]byte(res.than), &body)

	return res.ma, body.Data
}

func docPhieu(t *testing.T, h *heThong, token string, id uint) phieuMua {
	t.Helper()

	res := h.goi(t, token, http.MethodGet, fmt.Sprintf("%s/%d", duongPhieuMua, id), nil)
	if res.ma != http.StatusOK {
		t.Fatalf("đọc phiếu %d trả %d\n%s", id, res.ma, catBot(res.than))
	}

	var body struct {
		Data phieuMua `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &body); err != nil {
		t.Fatalf("không đọc được phản hồi: %v\n%s", err, catBot(res.than))
	}

	return body.Data
}

// dongHang dựng một dòng hàng cho payload.
func dongHang(bienThe uint, sl int, gia float64) map[string]any {
	return map[string]any{"variant_id": bienThe, "quantity": sl, "unit_cost": gia}
}

// TestPhieuMua_LuuTamKhongDungKho — phiếu lưu tạm chỉ là tờ giấy, kho chưa đổi.
//
// Đây là ranh giới quan trọng nhất của màn này: nếu lập phiếu đã cộng kho thì
// mọi phiếu gõ dở, gõ nhầm, gõ thử đều thành hàng thật trong kho.
func TestPhieuMua_LuuTamKhongDungKho(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	truoc := tonCua(t, h, a, a.chiNhanh, a.bienThe)

	ma, p := lapPhieu(t, h, a.token, map[string]any{
		"supplier_name": "Cong ty " + a.vet,
		"items":         []any{dongHang(a.bienThe, 5, 10000)},
	})
	if ma != http.StatusCreated {
		t.Fatalf("lập phiếu phải trả 201, nhận %d", ma)
	}
	if p.Status != "draft" {
		t.Fatalf("phiếu mới lập phải là phiếu lưu tạm, nhận %q", p.Status)
	}
	if p.POCode == "" {
		t.Fatal("phiếu phải có mã — không có mã thì không ai gọi tên nó được")
	}
	if p.TotalAmount != 50000 {
		t.Fatalf("tổng tiền phải là 50.000, nhận %v", p.TotalAmount)
	}

	if sau := tonCua(t, h, a, a.chiNhanh, a.bienThe); sau != truoc {
		t.Fatalf("phiếu LƯU TẠM không được đụng tới kho: trước %d, sau %d", truoc, sau)
	}
}

// TestPhieuMua_DuyetCongDungSoQuyDoi — mua theo THÙNG, kho cộng theo CÁI.
//
// Mặt hàng khai "1 Thùng = 24 Cái"; mua 2 thùng thì kho phải cộng 48, và dòng
// phiếu vẫn in ra "2" đúng như hoá đơn bên bán.
func TestPhieuMua_DuyetCongDungSoQuyDoi(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	thung := taoDonVi(t, h, a, "Thung "+a.vet)
	khaiQuyDoi(t, h, a, thung, 24)

	truoc := tonCua(t, h, a, a.chiNhanh, a.bienThe)

	ma, p := lapPhieu(t, h, a.token, map[string]any{
		"supplier_name": "Cong ty " + a.vet,
		"items": []any{map[string]any{
			"variant_id": a.bienThe, "unit_id": thung, "quantity": 2, "unit_cost": 240000,
		}},
	})
	if ma != http.StatusCreated {
		t.Fatalf("lập phiếu trả %d", ma)
	}
	if len(p.Items) != 1 {
		t.Fatalf("phiếu phải có đúng 1 dòng, nhận %d", len(p.Items))
	}
	if p.Items[0].Quantity != 2 {
		t.Fatalf("dòng phiếu phải giữ số MUA là 2 thùng, nhận %d", p.Items[0].Quantity)
	}
	if p.Items[0].BaseQuantity != 48 {
		t.Fatalf("số vào kho phải là 48 (2 × 24), nhận %d", p.Items[0].BaseQuantity)
	}

	duyet(t, h, a, p.ID)

	sau := tonCua(t, h, a, a.chiNhanh, a.bienThe)
	if sau-truoc != 48 {
		t.Fatalf("duyệt phải cộng 48 vào kho, thực tế cộng %d", sau-truoc)
	}
}

// TestPhieuMua_DuyetHaiLanChiCongMotLan — chốt chặn duyệt trùng.
//
// Hai người cùng mở một phiếu và cùng bấm Duyệt là chuyện thường ngày; không có
// chốt này thì lô hàng vào kho hai lần và không ai biết cho tới lúc đếm hàng.
func TestPhieuMua_DuyetHaiLanChiCongMotLan(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	truoc := tonCua(t, h, a, a.chiNhanh, a.bienThe)

	_, p := lapPhieu(t, h, a.token, map[string]any{
		"supplier_name": "Cong ty " + a.vet,
		"items":         []any{dongHang(a.bienThe, 7, 1000)},
	})
	duyet(t, h, a, p.ID)

	res := h.goi(t, a.token, http.MethodPost, fmt.Sprintf("%s/%d/duyet", duongPhieuMua, p.ID), nil)
	if res.ma != http.StatusConflict {
		t.Fatalf("duyệt lần hai phải trả 409, nhận %d\n%s", res.ma, catBot(res.than))
	}

	if sau := tonCua(t, h, a, a.chiNhanh, a.bienThe); sau-truoc != 7 {
		t.Fatalf("duyệt hai lần chỉ được cộng 7, thực tế cộng %d", sau-truoc)
	}
}

// TestPhieuMua_DaDuyetThiKhoaLai — sửa và xoá phiếu đã duyệt đều bị từ chối.
//
// Bản order v2 cho sửa tiếp, và mỗi lượt lưu lại CỘNG KHO THÊM một lần nữa mà
// không trừ số cũ đi. Bài kiểm này canh đúng chỗ đó.
func TestPhieuMua_DaDuyetThiKhoaLai(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	_, p := lapPhieu(t, h, a.token, map[string]any{
		"supplier_name": "Cong ty " + a.vet,
		"items":         []any{dongHang(a.bienThe, 3, 5000)},
	})
	duyet(t, h, a, p.ID)

	sauDuyet := tonCua(t, h, a, a.chiNhanh, a.bienThe)

	res := h.goi(t, a.token, http.MethodPut, fmt.Sprintf("%s/%d", duongPhieuMua, p.ID), map[string]any{
		"supplier_name": "Cong ty " + a.vet,
		"items":         []any{dongHang(a.bienThe, 30, 5000)},
	})
	if res.ma != http.StatusConflict {
		t.Fatalf("sửa phiếu đã duyệt phải trả 409, nhận %d\n%s", res.ma, catBot(res.than))
	}

	if sau := tonCua(t, h, a, a.chiNhanh, a.bienThe); sau != sauDuyet {
		t.Fatalf("lượt sửa bị từ chối mà kho vẫn đổi: %d → %d", sauDuyet, sau)
	}

	res = h.goi(t, a.token, http.MethodDelete, fmt.Sprintf("%s/%d", duongPhieuMua, p.ID), nil)
	if res.ma != http.StatusConflict {
		t.Fatalf("xoá phiếu đã duyệt phải trả 409, nhận %d\n%s", res.ma, catBot(res.than))
	}

	// Cờ trả về phải nói đúng sự thật — giao diện dựng nút từ đây.
	sauCung := docPhieu(t, h, a.token, p.ID)
	if sauCung.CanEdit || sauCung.CanApprove {
		t.Fatalf("phiếu đã duyệt phải trả can_edit=false, can_approve=false; nhận %v/%v",
			sauCung.CanEdit, sauCung.CanApprove)
	}
}

// TestPhieuMua_QuyDoiRaSoLe — "1 thùng = 0,5 tạ" mua lẻ 1 thùng thì kho không
// có chỗ ghi phần lẻ. Từ chối lúc lập phiếu, không tự làm tròn.
func TestPhieuMua_QuyDoiRaSoLe(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	ta := taoDonVi(t, h, a, "Ta "+a.vet)
	khaiQuyDoi(t, h, a, ta, 0.5)

	res := h.goi(t, a.token, http.MethodPost, duongPhieuMua, map[string]any{
		"supplier_name": "Cong ty " + a.vet,
		"items": []any{map[string]any{
			"variant_id": a.bienThe, "unit_id": ta, "quantity": 1, "unit_cost": 100000,
		}},
	})
	if res.ma != http.StatusUnprocessableEntity {
		t.Fatalf("quy đổi ra số lẻ phải trả 422, nhận %d\n%s", res.ma, catBot(res.than))
	}
}

// TestPhieuMua_ThanhToanVaCongNo — số đã trả là số LUỸ KẾ, và không được vượt
// tổng tiền phiếu.
//
// Bản v2 tin con số client gửi kèm nên sửa vài ô trên trình duyệt là ghi được
// một phiếu "đã trả đủ" mà chưa trả đồng nào.
func TestPhieuMua_ThanhToanVaCongNo(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	_, p := lapPhieu(t, h, a.token, map[string]any{
		"supplier_name": "Cong ty " + a.vet,
		"items":         []any{dongHang(a.bienThe, 10, 10000)},
	})
	duyet(t, h, a, p.ID)

	duong := fmt.Sprintf("%s/%d/thanh-toan", duongPhieuMua, p.ID)

	if got := docPhieu(t, h, a.token, p.ID); !got.CanPay {
		t.Fatalf("phiếu đã duyệt chưa trả đồng nào phải cho trả tiền")
	}

	res := h.goi(t, a.token, http.MethodPost, duong, map[string]any{"paid_amount": 40000})
	if res.ma != http.StatusOK {
		t.Fatalf("ghi nhận thanh toán trả %d\n%s", res.ma, catBot(res.than))
	}
	// Trả một phần rồi thì khoản nợ ấy thuộc về màn CÔNG NỢ — màn phiếu mua
	// không bày nút trả tiếp nữa, không thì hai chỗ cùng sửa một khoản nợ.
	if got := docPhieu(t, h, a.token, p.ID); got.CanPay {
		t.Fatalf("phiếu đã trả một phần KHÔNG được bày nút thanh toán nữa")
	}
	if got := docPhieu(t, h, a.token, p.ID); got.PaymentStatus != "partial" || got.PaidAmount != 40000 {
		t.Fatalf("trả 40.000/100.000 phải là partial, nhận %q với %v", got.PaymentStatus, got.PaidAmount)
	}

	res = h.goi(t, a.token, http.MethodPost, duong, map[string]any{"paid_amount": 100000})
	if res.ma != http.StatusOK {
		t.Fatalf("trả nốt trả %d\n%s", res.ma, catBot(res.than))
	}
	if got := docPhieu(t, h, a.token, p.ID); got.PaymentStatus != "paid" {
		t.Fatalf("trả đủ phải là paid, nhận %q", got.PaymentStatus)
	}

	res = h.goi(t, a.token, http.MethodPost, duong, map[string]any{"paid_amount": 999999})
	if res.ma != http.StatusUnprocessableEntity {
		t.Fatalf("trả quá tổng tiền phải trả 422, nhận %d\n%s", res.ma, catBot(res.than))
	}
}

// TestPhieuMua_SoTraTien — sổ từng lượt trả tiền, bảng `purchase_payments`.
//
// `paid_amount` chỉ nói TỔNG. Sổ này nói tổng ấy tới từ mấy lượt, mỗi lượt bao
// nhiêu và bằng hình thức gì. Bất biến phải giữ: SUM(amount) = paid_amount.
func TestPhieuMua_SoTraTien(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	_, p := lapPhieu(t, h, a.token, map[string]any{
		"supplier_name": "Cong ty " + a.vet,
		"items":         []any{dongHang(a.bienThe, 10, 10000)},
	})
	duyet(t, h, a, p.ID)

	duong := fmt.Sprintf("%s/%d/thanh-toan", duongPhieuMua, p.ID)

	if got := docPhieu(t, h, a.token, p.ID); len(got.Payments) != 0 {
		t.Fatalf("phiếu chưa trả đồng nào thì sổ phải rỗng, nhận %d dòng", len(got.Payments))
	}

	// Lượt một: trả 40.000 bằng tiền mặt.
	res := h.goi(t, a.token, http.MethodPost, duong, map[string]any{
		"paid_amount": 40000, "payment_method": "cash", "note": "dot 1",
		"is_debt": true, "debt_due_date": "2026-12-31",
		"debt_contact_name": "Anh Ba", "debt_contact_phone": "0900000000",
	})
	if res.ma != http.StatusOK {
		t.Fatalf("lượt trả một trả %d\n%s", res.ma, catBot(res.than))
	}

	// Lượt hai: sửa mỗi hạn nợ, KHÔNG đụng tới tiền — sổ không được đẻ thêm dòng.
	res = h.goi(t, a.token, http.MethodPost, duong, map[string]any{
		"paid_amount": 40000, "payment_method": "cash",
		"is_debt": true, "debt_due_date": "2027-01-31",
		"debt_contact_name": "Anh Ba", "debt_contact_phone": "0911111111",
	})
	if res.ma != http.StatusOK {
		t.Fatalf("sửa hạn nợ trả %d\n%s", res.ma, catBot(res.than))
	}

	got := docPhieu(t, h, a.token, p.ID)
	if len(got.Payments) != 1 {
		t.Fatalf("sửa hạn nợ KHÔNG được đẻ dòng sổ mới; nhận %d dòng", len(got.Payments))
	}

	// Lượt ba: trả nốt bằng chuyển khoản.
	res = h.goi(t, a.token, http.MethodPost, duong, map[string]any{
		"paid_amount": 100000, "payment_method": "transfer", "note": "dot 2",
	})
	if res.ma != http.StatusOK {
		t.Fatalf("trả nốt trả %d\n%s", res.ma, catBot(res.than))
	}

	got = docPhieu(t, h, a.token, p.ID)
	if len(got.Payments) != 2 {
		t.Fatalf("sổ phải có đúng hai lượt trả, nhận %d", len(got.Payments))
	}

	// Cũ -> mới, mỗi dòng mang số CHÊNH và số luỹ kế sau lượt ấy.
	if got.Payments[0].Amount != 40000 || got.Payments[0].PaidAfter != 40000 {
		t.Fatalf("lượt một phải là 40.000 / còn 40.000, nhận %v / %v",
			got.Payments[0].Amount, got.Payments[0].PaidAfter)
	}
	if got.Payments[1].Amount != 60000 || got.Payments[1].PaidAfter != 100000 {
		t.Fatalf("lượt hai phải là CHÊNH 60.000 / luỹ kế 100.000, nhận %v / %v",
			got.Payments[1].Amount, got.Payments[1].PaidAfter)
	}
	if got.Payments[0].PaymentMethod != "cash" || got.Payments[1].PaymentMethod != "transfer" {
		t.Fatalf("hình thức từng lượt phải giữ riêng, nhận %q / %q",
			got.Payments[0].PaymentMethod, got.Payments[1].PaymentMethod)
	}
	if got.Payments[0].Note != "dot 1" || got.Payments[1].Note != "dot 2" {
		t.Fatalf("ghi chú từng lượt phải giữ riêng, nhận %q / %q",
			got.Payments[0].Note, got.Payments[1].Note)
	}

	// Bất biến: cộng cả sổ đúng bằng số luỹ kế trên phiếu.
	var cong float64
	for _, x := range got.Payments {
		cong += x.Amount
	}
	if cong != got.PaidAmount {
		t.Fatalf("SUM(amount)=%v phải bằng paid_amount=%v", cong, got.PaidAmount)
	}

	// Lượt CHỮA lại con số ghi sai phải ra số ÂM, không phải một lượt trả mới.
	res = h.goi(t, a.token, http.MethodPost, duong, map[string]any{"paid_amount": 70000})
	if res.ma != http.StatusOK {
		t.Fatalf("chữa lại số đã trả trả %d\n%s", res.ma, catBot(res.than))
	}

	got = docPhieu(t, h, a.token, p.ID)
	if len(got.Payments) != 3 || got.Payments[2].Amount != -30000 {
		t.Fatalf("lượt chữa phải ghi -30.000, nhận %d dòng với %v",
			len(got.Payments), got.Payments[len(got.Payments)-1].Amount)
	}
}

// TestPhieuMua_GhiNo — thoả thuận cho nợ, dựng theo hộp thanh toán của bản v2.
//
// Bên v2 mọi luật dưới đây chỉ nằm trong JS của hộp thoại, nên gọi thẳng đường
// API là ghi được một khoản nợ không hạn và không biết đòi ai. Bài này canh cho
// server tự giữ lấy luật của nó.
func TestPhieuMua_GhiNo(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	_, p := lapPhieu(t, h, a.token, map[string]any{
		"supplier_name": "Cong ty " + a.vet,
		"items":         []any{dongHang(a.bienThe, 10, 10000)},
	})
	duyet(t, h, a, p.ID)

	duong := fmt.Sprintf("%s/%d/thanh-toan", duongPhieuMua, p.ID)

	// Thiếu hạn nợ.
	res := h.goi(t, a.token, http.MethodPost, duong, map[string]any{
		"paid_amount": 40000, "is_debt": true,
		"debt_contact_name": "Anh Ba", "debt_contact_phone": "0900000000",
	})
	if res.ma != http.StatusUnprocessableEntity {
		t.Fatalf("ghi nợ không hẹn ngày phải trả 422, nhận %d\n%s", res.ma, catBot(res.than))
	}

	// Thiếu người đại diện.
	res = h.goi(t, a.token, http.MethodPost, duong, map[string]any{
		"paid_amount": 40000, "is_debt": true, "debt_due_date": "2026-12-31",
	})
	if res.ma != http.StatusUnprocessableEntity {
		t.Fatalf("ghi nợ không có người đòi phải trả 422, nhận %d\n%s", res.ma, catBot(res.than))
	}

	// Trả đủ mà vẫn bật ghi nợ — hai việc chỏi nhau, không còn đồng nào để nợ.
	res = h.goi(t, a.token, http.MethodPost, duong, map[string]any{
		"paid_amount": 100000, "is_debt": true, "debt_due_date": "2026-12-31",
		"debt_contact_name": "Anh Ba", "debt_contact_phone": "0900000000",
	})
	if res.ma != http.StatusUnprocessableEntity {
		t.Fatalf("trả đủ mà ghi nợ phải trả 422, nhận %d\n%s", res.ma, catBot(res.than))
	}

	// Đủ cả thì nhận, và giữ đúng từng trường.
	res = h.goi(t, a.token, http.MethodPost, duong, map[string]any{
		"paid_amount": 40000, "is_debt": true, "debt_due_date": "2026-12-31",
		"debt_contact_name": "Anh Ba", "debt_contact_phone": "0900000000",
		"payment_method": "transfer", "payment_attachment": "uploads/uy-nhiem-chi.jpg",
	})
	if res.ma != http.StatusOK {
		t.Fatalf("ghi nợ đủ giấy tờ trả %d\n%s", res.ma, catBot(res.than))
	}

	got := docPhieu(t, h, a.token, p.ID)
	if !got.IsDebt || got.DebtDueDate == nil || !strings.HasPrefix(*got.DebtDueDate, "2026-12-31") {
		t.Fatalf("hạn nợ phải là 2026-12-31, nhận %v (is_debt=%v)", got.DebtDueDate, got.IsDebt)
	}
	if got.DebtName != "Anh Ba" || got.DebtPhone != "0900000000" {
		t.Fatalf("người đại diện phải giữ nguyên, nhận %q / %q", got.DebtName, got.DebtPhone)
	}
	if got.PaymentMethod != "transfer" || got.PayAttachment != "uploads/uy-nhiem-chi.jpg" {
		t.Fatalf("hình thức trả và ảnh chứng từ phải giữ nguyên, nhận %q / %q",
			got.PaymentMethod, got.PayAttachment)
	}

	// Trả nốt và TẮT ghi nợ: ba trường đi kèm phải được dọn sạch, không để lại
	// hạn nợ và người đòi của một khoản nợ không còn tồn tại.
	res = h.goi(t, a.token, http.MethodPost, duong, map[string]any{
		"paid_amount": 100000, "payment_method": "cash",
	})
	if res.ma != http.StatusOK {
		t.Fatalf("trả nốt trả %d\n%s", res.ma, catBot(res.than))
	}

	got = docPhieu(t, h, a.token, p.ID)
	if got.IsDebt || got.DebtDueDate != nil || got.DebtName != "" || got.DebtPhone != "" {
		t.Fatalf("tắt ghi nợ phải dọn sạch hạn và người đòi, nhận %v / %v / %q / %q",
			got.IsDebt, got.DebtDueDate, got.DebtName, got.DebtPhone)
	}
	if got.PaymentStatus != "paid" {
		t.Fatalf("trả đủ phải là paid, nhận %q", got.PaymentStatus)
	}
}

// TestPhieuMua_HuyChiTuLuuTam — huỷ phải nói lý do, và chỉ huỷ được phiếu chưa
// vào kho.
func TestPhieuMua_HuyChiTuLuuTam(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	_, p := lapPhieu(t, h, a.token, map[string]any{
		"supplier_name": "Cong ty " + a.vet,
		"items":         []any{dongHang(a.bienThe, 2, 1000)},
	})

	duong := fmt.Sprintf("%s/%d/huy", duongPhieuMua, p.ID)

	res := h.goi(t, a.token, http.MethodPost, duong, map[string]any{})
	if res.ma != http.StatusUnprocessableEntity {
		t.Fatalf("huỷ mà không nói lý do phải trả 422, nhận %d\n%s", res.ma, catBot(res.than))
	}

	res = h.goi(t, a.token, http.MethodPost, duong, map[string]any{"note": "NCC bao het hang"})
	if res.ma != http.StatusOK {
		t.Fatalf("huỷ phiếu lưu tạm trả %d\n%s", res.ma, catBot(res.than))
	}
	if got := docPhieu(t, h, a.token, p.ID); got.Status != "cancelled" {
		t.Fatalf("phiếu phải chuyển sang cancelled, nhận %q", got.Status)
	}

	// Đã huỷ rồi thì không duyệt lại được — hàng đã không mua nữa.
	res = h.goi(t, a.token, http.MethodPost, fmt.Sprintf("%s/%d/duyet", duongPhieuMua, p.ID), nil)
	if res.ma != http.StatusConflict {
		t.Fatalf("duyệt phiếu đã huỷ phải trả 409, nhận %d\n%s", res.ma, catBot(res.than))
	}
}

// TestPhieuMua_SoLoTachDongVaVaoSoKho — hai lô của cùng một mặt hàng là HAI
// dòng trên phiếu, nhưng vẫn cộng vào MỘT dòng tồn kho.
//
// Số lô ở đây là bản chụp trên chứng từ, không phải một chiều của tồn kho (xem
// migration 0042). Bài kiểm này canh đúng ranh giới đó: gộp nhầm hai dòng là
// phiếu in ra không khớp hóa đơn bên bán, còn tách tồn theo lô là đổi cả mô
// hình kho mà không ai yêu cầu.
func TestPhieuMua_SoLoTachDongVaVaoSoKho(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	truoc := tonCua(t, h, a, a.chiNhanh, a.bienThe)

	ma, p := lapPhieu(t, h, a.token, map[string]any{
		"supplier_name": "Cong ty " + a.vet,
		"items": []any{
			map[string]any{
				"variant_id": a.bienThe, "quantity": 4, "unit_cost": 1000,
				"lot_number": "L2026-08", "expire_date": "2027-08-22",
			},
			map[string]any{
				"variant_id": a.bienThe, "quantity": 6, "unit_cost": 1200,
				"lot_number": "L2026-09",
			},
		},
	})
	if ma != http.StatusCreated {
		t.Fatalf("lập phiếu trả %d", ma)
	}
	if len(p.Items) != 2 {
		t.Fatalf("hai số lô phải thành hai dòng, nhận %d dòng", len(p.Items))
	}
	if p.Items[0].LotNumber != "L2026-08" || p.Items[1].LotNumber != "L2026-09" {
		t.Fatalf("số lô ghi sai: %q và %q", p.Items[0].LotNumber, p.Items[1].LotNumber)
	}
	if p.Items[0].ExpireDate == nil {
		t.Fatal("dòng có khai hạn dùng mà đọc lên vẫn rỗng")
	}
	if p.Items[1].ExpireDate != nil {
		t.Fatal("dòng không khai hạn dùng phải để trống, không tự đặt ngày")
	}

	duyet(t, h, a, p.ID)

	// Kho vẫn là MỘT dòng tồn cho mặt hàng đó: 4 + 6.
	if sau := tonCua(t, h, a, a.chiNhanh, a.bienThe); sau-truoc != 10 {
		t.Fatalf("hai lô phải cộng chung 10 vào một dòng tồn, thực tế cộng %d", sau-truoc)
	}
}

// TestPhieuMua_CungLoThiGopDong — chọn lại đúng món, đúng đơn vị, đúng lô thì
// cộng số lượng vào dòng cũ chứ không đẻ dòng thứ hai.
func TestPhieuMua_CungLoThiGopDong(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	_, p := lapPhieu(t, h, a.token, map[string]any{
		"supplier_name": "Cong ty " + a.vet,
		"items": []any{
			map[string]any{"variant_id": a.bienThe, "quantity": 3, "unit_cost": 1000, "lot_number": "L1"},
			map[string]any{"variant_id": a.bienThe, "quantity": 5, "unit_cost": 1000, "lot_number": "L1"},
		},
	})
	if len(p.Items) != 1 {
		t.Fatalf("cùng lô phải gộp thành một dòng, nhận %d dòng", len(p.Items))
	}
	if p.Items[0].Quantity != 8 {
		t.Fatalf("gộp xong số lượng phải là 8, nhận %d", p.Items[0].Quantity)
	}
}

// TestPhieuMua_ThueMotDongKhaiRieng — dòng cố ý để 0% phải được giữ đúng 0%.
//
// Lỗi đã xảy ra thật: kiểu int không phân biệt "khách gửi số 0" với "khách
// không gửi gì", nên dòng khai 0% bị thay bằng thuế suất của mặt hàng. Con số
// trên màn hình và con số lưu xuống nói hai chuyện khác nhau.
func TestPhieuMua_ThueMotDongKhaiRieng(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	khaiThue(t, h, a, 8)

	// Dòng 1 khai thẳng 0%, dòng 2 bỏ trống hẳn để lấy thuế của mặt hàng.
	ma, p := lapPhieu(t, h, a.token, map[string]any{
		"supplier_name": "Cong ty " + a.vet,
		"vat_mode":      "goods",
		"items": []any{
			map[string]any{"variant_id": a.bienThe, "quantity": 1, "unit_cost": 100000,
				"vat_percent": 0, "lot_number": "KHONG-THUE"},
			map[string]any{"variant_id": a.bienThe, "quantity": 1, "unit_cost": 100000,
				"lot_number": "THEO-HANG"},
		},
	})
	if ma != http.StatusCreated {
		t.Fatalf("lập phiếu trả %d", ma)
	}
	if len(p.Items) != 2 {
		t.Fatalf("phải có 2 dòng, nhận %d", len(p.Items))
	}
	if p.Items[0].VATPercent != 0 {
		t.Fatalf("dòng khai thẳng 0%% phải giữ 0, nhận %d", p.Items[0].VATPercent)
	}
	if p.Items[1].VATPercent != 8 {
		t.Fatalf("dòng bỏ trống phải lấy thuế của mặt hàng (8), nhận %d", p.Items[1].VATPercent)
	}
	if p.VATAmount != 8000 {
		t.Fatalf("thuế cả phiếu chỉ tính trên dòng thứ hai = 8.000, nhận %v", p.VATAmount)
	}
}

// TestPhieuMua_GiaVonBinhQuanGiaQuyen — một phiếu mua cùng món hai giá thì giá
// vốn là bình quân theo số lượng, không phải giá của dòng cuối.
func TestPhieuMua_GiaVonBinhQuanGiaQuyen(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	_, p := lapPhieu(t, h, a.token, map[string]any{
		"supplier_name": "Cong ty " + a.vet,
		"items": []any{
			map[string]any{"variant_id": a.bienThe, "quantity": 10, "unit_cost": 10000, "lot_number": "LO-RE"},
			map[string]any{"variant_id": a.bienThe, "quantity": 90, "unit_cost": 20000, "lot_number": "LO-DAT"},
		},
	})
	duyet(t, h, a, p.ID)

	// (10 × 10.000 + 90 × 20.000) / 100 = 19.000
	res := h.goi(t, a.token, http.MethodGet,
		fmt.Sprintf("/api/v1/admin/inventory/%d/history", a.bienThe), nil)
	var lich struct {
		Data []struct {
			ReferenceType string   `json:"reference_type"`
			ReferenceCode string   `json:"reference_code"`
			UnitCost      *float64 `json:"unit_cost"`
		} `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &lich); err != nil {
		t.Fatalf("không đọc được sổ kho: %v\n%s", err, catBot(res.than))
	}

	var nhap *float64
	maChungTu := ""
	for _, d := range lich.Data {
		if d.ReferenceType == "purchase_order" {
			nhap = d.UnitCost
			maChungTu = d.ReferenceCode
		}
	}
	if nhap == nil {
		t.Fatalf("sổ kho không có bút toán nhập nào của phiếu\n%s", catBot(res.than))
	}
	if *nhap != 19000 {
		t.Fatalf("giá vốn phải là bình quân 19.000, nhận %v", *nhap)
	}

	// Và sổ kho phải tra ngược được ra mã phiếu — cột đó từng rỗng vì thiếu join.
	if maChungTu != p.POCode {
		t.Fatalf("sổ kho phải chỉ về phiếu %q, nhận %q", p.POCode, maChungTu)
	}
}

// TestPhieuMua_TenBenBanLayTheoDanhMuc — gửi mỗi supplier_id thì server tự chụp
// tên bên bán, không để phiếu in ra trống trơn.
func TestPhieuMua_TenBenBanLayTheoDanhMuc(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	res := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/nha-cung-cap", map[string]any{
		"name": "Cong ty Sua " + a.vet, "address": "12 Le Loi",
	})
	var ncc struct {
		Data struct {
			ID   uint   `json:"id"`
			Name string `json:"name"`
		} `json:"data"`
	}
	_ = json.Unmarshal([]byte(res.than), &ncc)
	if ncc.Data.ID == 0 {
		t.Fatalf("tạo NCC hỏng %d\n%s", res.ma, catBot(res.than))
	}

	_, p := lapPhieu(t, h, a.token, map[string]any{
		"supplier_id": ncc.Data.ID,
		"items":       []any{dongHang(a.bienThe, 1, 1000)},
	})
	got := docPhieu(t, h, a.token, p.ID)
	if got.SupplierName != ncc.Data.Name {
		t.Fatalf("tên bên bán phải tự lấy %q, nhận %q", ncc.Data.Name, got.SupplierName)
	}
}

// TestPhieuMua_KepTranSoDongMoiTrang — page_size khổng lồ bị kẹp về mặc định.
func TestPhieuMua_KepTranSoDongMoiTrang(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	res := h.goi(t, a.token, http.MethodGet, duongPhieuMua+"?page_size=1000000", nil)
	if res.ma != http.StatusOK {
		t.Fatalf("đọc danh sách trả %d", res.ma)
	}

	var body struct {
		Meta struct {
			PageSize int `json:"page_size"`
		} `json:"meta"`
	}
	_ = json.Unmarshal([]byte(res.than), &body)
	if body.Meta.PageSize != 20 {
		t.Fatalf("page_size quá trần phải kẹp về 20, nhận %d", body.Meta.PageSize)
	}
}

// TestPhieuMua_NhomHangChiHienNhomCoHang — ô lọc nhóm chỉ bày nhóm CÓ hàng.
//
// Bày ra một nhóm rỗng thì chọn vào là bảng trắng, và người dùng không có cách
// nào biết đó là nhóm rỗng hay là lỗi. Điều kiện lọc phải khớp từng chữ với ô
// tìm hàng — lệch một điều kiện là ô chọn hứa một đằng, ô tìm trả một nẻo.
func TestPhieuMua_NhomHangChiHienNhomCoHang(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	// Nhóm rỗng: dựng ra rồi không cho mặt hàng nào vào.
	res := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/categories", map[string]any{
		"name": "Nhom rong " + a.vet, "slug": "nhom-rong-" + a.vet,
	})
	if res.ma != http.StatusCreated {
		t.Fatalf("tạo nhóm rỗng trả %d\n%s", res.ma, catBot(res.than))
	}

	nhom := nhomHangCoHang(t, h, a)

	coHang := false
	for _, n := range nhom {
		if n.Name == "Nhom rong "+a.vet {
			t.Fatalf("nhóm rỗng lọt vào ô chọn: %+v", n)
		}
		if n.ID == a.danhMuc {
			coHang = true
			if n.SoMatHang < 1 {
				t.Fatalf("nhóm có hàng phải đếm ra ít nhất 1 mặt hàng, nhận %d", n.SoMatHang)
			}
		}
	}
	if !coHang {
		t.Fatalf("nhóm đang có hàng phải nằm trong ô chọn, nhận %+v", nhom)
	}

	// Mọi nhóm bày ra đều phải tra ra được hàng — đó là cả lý do lọc.
	for _, n := range nhom {
		res := h.goi(t, a.token, http.MethodGet,
			fmt.Sprintf("%s/mat-hang?category_id=%d", duongPhieuMua, n.ID), nil)
		var body struct {
			Data []struct {
				VariantID uint `json:"variant_id"`
			} `json:"data"`
		}
		_ = json.Unmarshal([]byte(res.than), &body)
		if len(body.Data) == 0 {
			t.Fatalf("nhóm %q bày ra ô chọn nhưng ô tìm hàng không ra dòng nào", n.Name)
		}
	}
}

// TestPhieuMua_NhomHangAnHangDaTatThiBienMat — ẩn hết hàng của một nhóm thì
// nhóm đó rời khỏi ô chọn.
func TestPhieuMua_NhomHangAnHangDaTatThiBienMat(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	if len(nhomHangCoHang(t, h, a)) == 0 {
		t.Fatal("chưa lọc gì mà ô chọn đã rỗng")
	}

	// Tắt mặt hàng duy nhất của cửa hàng.
	res := h.goi(t, a.token, http.MethodPut, fmt.Sprintf("/api/v1/admin/products/%d", a.sanPham),
		map[string]any{
			"category_id": a.danhMuc,
			"name":        "Hang hoa " + a.vet,
			"slug":        a.slug,
			"is_active":   false,
		})
	if res.ma != http.StatusOK {
		t.Fatalf("tắt mặt hàng trả %d\n%s", res.ma, catBot(res.than))
	}

	for _, n := range nhomHangCoHang(t, h, a) {
		if n.ID == a.danhMuc {
			t.Fatalf("hàng đã tắt mà nhóm %q vẫn nằm trong ô chọn", n.Name)
		}
	}
}

func nhomHangCoHang(t *testing.T, h *heThong, c *cuaHang) []struct {
	ID        uint   `json:"id"`
	Name      string `json:"name"`
	SoMatHang int    `json:"so_mat_hang"`
} {
	t.Helper()

	res := h.goi(t, c.token, http.MethodGet, duongPhieuMua+"/nhom-hang", nil)
	if res.ma != http.StatusOK {
		t.Fatalf("đọc nhóm hàng trả %d\n%s", res.ma, catBot(res.than))
	}

	var body struct {
		Data []struct {
			ID        uint   `json:"id"`
			Name      string `json:"name"`
			SoMatHang int    `json:"so_mat_hang"`
		} `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &body); err != nil {
		t.Fatalf("không đọc được nhóm hàng: %v\n%s", err, catBot(res.than))
	}

	return body.Data
}

// TestPhieuMua_CoLapGiuaHaiCuaHang — cửa hàng B không đọc, không sửa, không
// duyệt được phiếu của cửa hàng A.
func TestPhieuMua_CoLapGiuaHaiCuaHang(t *testing.T) {
	h := dungHeThong(t)
	a, b := haiCuaHang(t, h)

	_, p := lapPhieu(t, h, a.token, map[string]any{
		"supplier_name": "Cong ty " + a.vet,
		"items":         []any{dongHang(a.bienThe, 1, 1000)},
	})

	res := h.goi(t, b.token, http.MethodGet, fmt.Sprintf("%s/%d", duongPhieuMua, p.ID), nil)
	if res.ma != http.StatusNotFound {
		t.Fatalf("cửa hàng khác đọc phiếu phải trả 404, nhận %d\n%s", res.ma, catBot(res.than))
	}

	res = h.goi(t, b.token, http.MethodPost, fmt.Sprintf("%s/%d/duyet", duongPhieuMua, p.ID), nil)
	if res.ma != http.StatusNotFound {
		t.Fatalf("cửa hàng khác duyệt phiếu phải trả 404, nhận %d\n%s", res.ma, catBot(res.than))
	}

	res = h.goi(t, b.token, http.MethodDelete, fmt.Sprintf("%s/%d", duongPhieuMua, p.ID), nil)
	if res.ma != http.StatusNotFound {
		t.Fatalf("cửa hàng khác xoá phiếu phải trả 404, nhận %d\n%s", res.ma, catBot(res.than))
	}

	// Và danh sách của B không được lộ dấu vết của A.
	res = h.goi(t, b.token, http.MethodGet, duongPhieuMua, nil)
	if res.ma != http.StatusOK {
		t.Fatalf("đọc danh sách phiếu của B trả %d", res.ma)
	}
	if chuaDauVet(res.than, a.vet) {
		t.Fatalf("danh sách phiếu của B lộ dấu vết cửa hàng A (%s)", a.vet)
	}
}

// ---------- helper riêng của nhóm bài kiểm này ----------

// duyet bấm nút Duyệt và đòi 200.
func duyet(t *testing.T, h *heThong, c *cuaHang, id uint) {
	t.Helper()

	res := h.goi(t, c.token, http.MethodPost, fmt.Sprintf("%s/%d/duyet", duongPhieuMua, id), nil)
	if res.ma != http.StatusOK {
		t.Fatalf("duyệt phiếu %d trả %d\n%s", id, res.ma, catBot(res.than))
	}
}

// taoDonVi khai một đơn vị tính mới rồi trả id.
func taoDonVi(t *testing.T, h *heThong, c *cuaHang, ten string) uint {
	t.Helper()

	res := h.goi(t, c.token, http.MethodPost, "/api/v1/admin/don-vi-tinh", map[string]any{"name": ten})
	if res.ma != http.StatusCreated {
		t.Fatalf("tạo đơn vị tính trả %d\n%s", res.ma, catBot(res.than))
	}

	var body struct {
		Data struct {
			ID uint `json:"id"`
		} `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &body); err != nil {
		t.Fatalf("không đọc được đơn vị vừa tạo: %v\n%s", err, catBot(res.than))
	}

	return body.Data.ID
}

// khaiThue đặt thuế suất cho mặt hàng của cửa hàng.
func khaiThue(t *testing.T, h *heThong, c *cuaHang, phanTram int) {
	t.Helper()

	res := h.goi(t, c.token, http.MethodPut, fmt.Sprintf("/api/v1/admin/products/%d", c.sanPham), map[string]any{
		"category_id": c.danhMuc,
		"name":        "Hang hoa " + c.vet,
		"slug":        c.slug,
		"vat":         phanTram,
	})
	if res.ma != http.StatusOK {
		t.Fatalf("khai thuế cho mặt hàng trả %d\n%s", res.ma, catBot(res.than))
	}
}

// khaiQuyDoi gắn khối "1 <đơn vị> = <hệ số> đơn vị tính chính" vào mặt hàng của
// cửa hàng, đi qua đúng đường màn Hàng hoá vẫn dùng.
func khaiQuyDoi(t *testing.T, h *heThong, c *cuaHang, donVi uint, heSo float64) {
	t.Helper()

	// Sửa mặt hàng là đường PUT toàn phần: phải gửi lại cả nhóm hàng, tên và
	// slug, không thì API trả 422 đòi ô còn thiếu.
	res := h.goi(t, c.token, http.MethodPut, fmt.Sprintf("/api/v1/admin/products/%d", c.sanPham), map[string]any{
		"category_id":      c.danhMuc,
		"name":             "Hang hoa " + c.vet,
		"slug":             c.slug,
		"unit_conversions": []any{map[string]any{"unit_id": donVi, "quantity": heSo}},
	})
	if res.ma != http.StatusOK {
		t.Fatalf("khai quy đổi đơn vị trả %d\n%s", res.ma, catBot(res.than))
	}
}
