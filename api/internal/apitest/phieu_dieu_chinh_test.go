package apitest

import (
	"encoding/json"
	"fmt"
	"net/http"
	"strings"
	"testing"
)

// PHIẾU ĐIỀU CHỈNH TỒN KHO — nắn số tồn của một kho theo chứng từ có duyệt.
//
// Bài kiểm canh đúng một câu: kho CHỈ đổi số lúc duyệt, và đổi đúng bằng số
// lệch ghi trên phiếu, vào đúng lô ghi trên phiếu. Lưu tạm, gửi duyệt, từ chối
// đều không được đụng tới kho.

const duongDieuChinh = "/api/v1/admin/dieu-chinh-ton-kho"

type phieuDieuChinhDoc struct {
	ID              uint   `json:"id"`
	Code            string `json:"code"`
	Status          string `json:"status"`
	WarehouseStatus string `json:"warehouse_status"`
	Items           []struct {
		LotNumber      string `json:"lot_number"`
		Quantity       int    `json:"quantity"`
		AdjustQuantity int    `json:"adjust_quantity"`
	} `json:"items"`
}

// lapDieuChinh lập một phiếu điều chỉnh tại chi nhánh của cửa hàng.
func lapDieuChinh(t *testing.T, h *heThong, c *cuaHang, than map[string]any) (int, phieuDieuChinhDoc) {
	t.Helper()

	res := h.goiChiNhanh(t, c.token, c.chiNhanh, http.MethodPost, duongDieuChinh, than)

	var body struct {
		Data phieuDieuChinhDoc `json:"data"`
	}
	_ = json.Unmarshal([]byte(res.than), &body)

	return res.ma, body.Data
}

func docDieuChinh(t *testing.T, h *heThong, c *cuaHang, id uint) phieuDieuChinhDoc {
	t.Helper()

	res := h.goiChiNhanh(t, c.token, c.chiNhanh, http.MethodGet, fmt.Sprintf("%s/%d", duongDieuChinh, id), nil)
	if res.ma != http.StatusOK {
		t.Fatalf("đọc phiếu điều chỉnh %d trả %d\n%s", id, res.ma, catBot(res.than))
	}

	var body struct {
		Data phieuDieuChinhDoc `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &body); err != nil {
		t.Fatalf("không đọc được phiếu: %v", err)
	}

	return body.Data
}

// Đường đi bình thường: nhập 10 vào lô LO-DC, lập phiếu bớt 3 (lưu tạm), gửi
// duyệt, duyệt. Kho chỉ đổi ở nước cuối, và đổi đúng lô.
func TestDieuChinh_KhoChiDoiLucDuyet(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	donKho(t, h, a)
	nhapLo(t, h, a, "LO-DC", 10, "2030-06-30")

	truoc := tonCua(t, h, a, a.chiNhanh, a.bienThe)
	if truoc != 10 {
		t.Fatalf("kho phải có 10 trước khi chỉnh, nhận %d", truoc)
	}

	ma, p := lapDieuChinh(t, h, a, map[string]any{
		"status": "draft",
		"note":   "đếm thiếu 3",
		"items": []any{map[string]any{
			"variant_id": a.bienThe, "lot_number": "LO-DC", "adjust_quantity": -3.0,
		}},
	})
	if ma != http.StatusCreated {
		t.Fatalf("lập phiếu điều chỉnh trả %d", ma)
	}
	if p.Status != "draft" || p.Code == "" {
		t.Fatalf("phiếu mới phải ở lưu tạm và có mã, nhận %+v", p)
	}
	if len(p.Items) != 1 || p.Items[0].Quantity != 10 {
		t.Fatalf("dòng hàng phải chụp tồn lô = 10, nhận %+v", p.Items)
	}

	// Lưu tạm và gửi duyệt CHƯA đụng tới kho.
	if giua := tonCua(t, h, a, a.chiNhanh, a.bienThe); giua != truoc {
		t.Fatalf("phiếu lưu tạm không được đụng vào kho: trước %d, sau khi lập %d", truoc, giua)
	}
	res := h.goiChiNhanh(t, a.token, a.chiNhanh, http.MethodPost, fmt.Sprintf("%s/%d/gui-duyet", duongDieuChinh, p.ID), nil)
	if res.ma != http.StatusOK {
		t.Fatalf("gửi duyệt trả %d\n%s", res.ma, catBot(res.than))
	}
	if giua := tonCua(t, h, a, a.chiNhanh, a.bienThe); giua != truoc {
		t.Fatalf("gửi duyệt không được đụng vào kho: trước %d, sau %d", truoc, giua)
	}
	if d := docDieuChinh(t, h, a, p.ID); d.Status != "pending" || d.WarehouseStatus != "" {
		t.Fatalf("sau gửi duyệt phải là chờ duyệt, kho chưa xử lý; nhận %s / %q", d.Status, d.WarehouseStatus)
	}

	res = h.goiChiNhanh(t, a.token, a.chiNhanh, http.MethodPost, fmt.Sprintf("%s/%d/duyet", duongDieuChinh, p.ID), nil)
	if res.ma != http.StatusOK {
		t.Fatalf("duyệt phiếu trả %d\n%s", res.ma, catBot(res.than))
	}

	if sau := tonCua(t, h, a, a.chiNhanh, a.bienThe); sau != truoc-3 {
		t.Fatalf("kho phải vơi đúng 3: trước %d, sau %d", truoc, sau)
	}
	if so := soCuaLo(docLo(t, h.db, a, a.chiNhanh, a.bienThe), "LO-DC"); so != 7 {
		t.Fatalf("phải trừ đúng lô LO-DC về 7, nhận %d", so)
	}
	khopTongVaLo(t, h, a, a.chiNhanh, a.bienThe)

	if d := docDieuChinh(t, h, a, p.ID); d.Status != "approved" || d.WarehouseStatus != "done" {
		t.Fatalf("sau duyệt phải là đã duyệt / đã xử lý; nhận %s / %q", d.Status, d.WarehouseStatus)
	}
}

// Nút "Duyệt" ngay trên màn lập: một lượt gọi vừa lập vừa duyệt, kho cộng thêm
// vào đúng lô.
func TestDieuChinh_DuyetLuonLucLap(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	donKho(t, h, a)
	nhapLo(t, h, a, "LO-DC3", 4, "")

	ma, p := lapDieuChinh(t, h, a, map[string]any{
		"status": "approved",
		"items": []any{map[string]any{
			"variant_id": a.bienThe, "lot_number": "LO-DC3", "adjust_quantity": 5,
		}},
	})
	if ma != http.StatusCreated {
		t.Fatalf("lập + duyệt luôn trả %d", ma)
	}
	if p.Status != "approved" {
		t.Fatalf("phiếu phải ở đã duyệt, nhận %s", p.Status)
	}
	if sau := tonCua(t, h, a, a.chiNhanh, a.bienThe); sau != 9 {
		t.Fatalf("kho phải là 4 + 5 = 9, nhận %d", sau)
	}
	if so := soCuaLo(docLo(t, h.db, a, a.chiNhanh, a.bienThe), "LO-DC3"); so != 9 {
		t.Fatalf("lô LO-DC3 phải là 9, nhận %d", so)
	}
	khopTongVaLo(t, h, a, a.chiNhanh, a.bienThe)
}

// Duyệt hai lần: lượt thứ hai phải bị chặn, không phải đổi kho thêm một lần nữa.
func TestDieuChinh_KhongDuyetDuocHaiLan(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	donKho(t, h, a)
	nhapLo(t, h, a, "LO-DC4", 6, "")

	_, p := lapDieuChinh(t, h, a, map[string]any{
		"items": []any{map[string]any{"variant_id": a.bienThe, "lot_number": "LO-DC4", "adjust_quantity": -2}},
	})

	duong := fmt.Sprintf("%s/%d/duyet", duongDieuChinh, p.ID)
	if res := h.goiChiNhanh(t, a.token, a.chiNhanh, http.MethodPost, duong, nil); res.ma != http.StatusOK {
		t.Fatalf("lượt duyệt đầu phải được, nhận %d\n%s", res.ma, catBot(res.than))
	}
	sauLan1 := tonCua(t, h, a, a.chiNhanh, a.bienThe)

	if res := h.goiChiNhanh(t, a.token, a.chiNhanh, http.MethodPost, duong, nil); res.ma == http.StatusOK {
		t.Fatal("duyệt lần hai phải bị chặn")
	}
	if sau := tonCua(t, h, a, a.chiNhanh, a.bienThe); sau != sauLan1 {
		t.Fatalf("lượt duyệt hỏng không được đụng vào kho: %d -> %d", sauLan1, sau)
	}
}

// Bớt nhiều hơn số lô đang có: chặn ngay lúc lập, và kho không đổi.
func TestDieuChinh_KhongBotQuaTonLo(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	donKho(t, h, a)
	nhapLo(t, h, a, "LO-DC5", 3, "")

	ma, _ := lapDieuChinh(t, h, a, map[string]any{
		"items": []any{map[string]any{"variant_id": a.bienThe, "lot_number": "LO-DC5", "adjust_quantity": -4}},
	})
	if ma != http.StatusUnprocessableEntity {
		t.Fatalf("bớt 4 khi lô chỉ có 3 phải trả 422, nhận %d", ma)
	}
	if sau := tonCua(t, h, a, a.chiNhanh, a.bienThe); sau != 3 {
		t.Fatalf("kho không được đổi khi phiếu bị chặn, nhận %d", sau)
	}
}

// Từ chối: phiếu chờ duyệt về từ chối, có lý do, kho đứng yên; từ chối xong không
// duyệt được nữa.
func TestDieuChinh_TuChoiKhongDungKho(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	donKho(t, h, a)
	nhapLo(t, h, a, "LO-DC6", 5, "")

	_, p := lapDieuChinh(t, h, a, map[string]any{
		"status": "pending",
		"items":  []any{map[string]any{"variant_id": a.bienThe, "lot_number": "LO-DC6", "adjust_quantity": -1}},
	})

	res := h.goiChiNhanh(t, a.token, a.chiNhanh, http.MethodPost,
		fmt.Sprintf("%s/%d/tu-choi", duongDieuChinh, p.ID), map[string]any{"reject_reason": "đếm lại đi"})
	if res.ma != http.StatusOK {
		t.Fatalf("từ chối trả %d\n%s", res.ma, catBot(res.than))
	}
	if sau := tonCua(t, h, a, a.chiNhanh, a.bienThe); sau != 5 {
		t.Fatalf("từ chối không được đụng vào kho, nhận %d", sau)
	}
	if d := docDieuChinh(t, h, a, p.ID); d.Status != "rejected" || d.WarehouseStatus != "rejected" {
		t.Fatalf("phiếu phải ở từ chối; nhận %s / %q", d.Status, d.WarehouseStatus)
	}
	if res := h.goiChiNhanh(t, a.token, a.chiNhanh, http.MethodPost,
		fmt.Sprintf("%s/%d/duyet", duongDieuChinh, p.ID), nil); res.ma == http.StatusOK {
		t.Fatal("phiếu đã từ chối không được duyệt nữa")
	}
}

// Xoá: chỉ phiếu lưu tạm xoá được; phiếu đã duyệt nằm lại trong sổ.
func TestDieuChinh_ChiXoaDuocPhieuLuuTam(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	donKho(t, h, a)
	nhapLo(t, h, a, "LO-DC7", 5, "")

	_, nhap := lapDieuChinh(t, h, a, map[string]any{
		"items": []any{map[string]any{"variant_id": a.bienThe, "lot_number": "LO-DC7", "adjust_quantity": 1}},
	})
	if res := h.goiChiNhanh(t, a.token, a.chiNhanh, http.MethodDelete,
		fmt.Sprintf("%s/%d", duongDieuChinh, nhap.ID), nil); res.ma != http.StatusOK {
		t.Fatalf("xoá phiếu lưu tạm trả %d\n%s", res.ma, catBot(res.than))
	}

	_, duyet := lapDieuChinh(t, h, a, map[string]any{
		"status": "approved",
		"items":  []any{map[string]any{"variant_id": a.bienThe, "lot_number": "LO-DC7", "adjust_quantity": 1}},
	})
	if res := h.goiChiNhanh(t, a.token, a.chiNhanh, http.MethodDelete,
		fmt.Sprintf("%s/%d", duongDieuChinh, duyet.ID), nil); res.ma == http.StatusOK {
		t.Fatal("phiếu đã duyệt không được xoá")
	}
}

// Cân đối hàng âm: bán vượt tồn ở quầy làm lô "Không xác định" tụt xuống âm;
// đường hàng âm phải liệt kê nó, và phiếu cân đối duyệt xong đưa nó về 0.
func TestDieuChinh_CanDoiHangAm(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	donKho(t, h, a)

	// Chưa có gì âm → 404 kèm câu nói rõ.
	if res := h.goiChiNhanh(t, a.token, a.chiNhanh, http.MethodGet, duongDieuChinh+"/hang-am", nil); res.ma != http.StatusNotFound {
		t.Fatalf("kho không có hàng âm phải trả 404, nhận %d\n%s", res.ma, catBot(res.than))
	}

	// Đẩy lô "" xuống âm bằng đường chỉnh kho có chủ đích: một phiếu cân đối bớt
	// hàng ở lô "" (phiếu loại balance không bị trần tồn chặn).
	_, p := lapDieuChinh(t, h, a, map[string]any{
		"type": "balance", "status": "approved",
		"items": []any{map[string]any{"variant_id": a.bienThe, "lot_number": "Không xác định", "adjust_quantity": -2}},
	})
	if p.Status != "approved" {
		t.Fatalf("phiếu cân đối phải duyệt được, nhận %+v", p)
	}
	if so := soCuaLo(docLo(t, h.db, a, a.chiNhanh, a.bienThe), ""); so != -2 {
		t.Fatalf("lô Không xác định phải đang -2, nhận %d", so)
	}

	res := h.goiChiNhanh(t, a.token, a.chiNhanh, http.MethodGet, duongDieuChinh+"/hang-am", nil)
	if res.ma != http.StatusOK {
		t.Fatalf("đọc hàng âm trả %d\n%s", res.ma, catBot(res.than))
	}
	var body struct {
		Data []struct {
			VariantID uint   `json:"variant_id"`
			LotNumber string `json:"lot_number"`
			Quantity  int    `json:"quantity"`
		} `json:"data"`
	}
	_ = json.Unmarshal([]byte(res.than), &body)
	if len(body.Data) != 1 || body.Data[0].VariantID != a.bienThe || body.Data[0].Quantity != -2 {
		t.Fatalf("hàng âm phải ra đúng một dòng -2 của biến thể %d, nhận %s", a.bienThe, catBot(res.than))
	}

	ma, cd := lapDieuChinh(t, h, a, map[string]any{
		"type": "balance", "status": "approved",
		"items": []any{map[string]any{"variant_id": a.bienThe, "lot_number": "", "adjust_quantity": 2}},
	})
	if ma != http.StatusCreated || cd.Status != "approved" {
		t.Fatalf("phiếu cân đối về 0 trả %d / %s", ma, cd.Status)
	}
	if so := soCuaLo(docLo(t, h.db, a, a.chiNhanh, a.bienThe), ""); so != 0 {
		t.Fatalf("sau cân đối lô Không xác định phải về 0, nhận %d", so)
	}
	khopTongVaLo(t, h, a, a.chiNhanh, a.bienThe)

	if res := h.goiChiNhanh(t, a.token, a.chiNhanh, http.MethodGet, duongDieuChinh+"/hang-am", nil); res.ma != http.StatusNotFound {
		t.Fatalf("cân đối xong phải hết hàng âm, nhận %d", res.ma)
	}
}

// Đường mat-hang phải bày CẢ lô "Không xác định" đang âm — ô tìm hàng dùng chung
// của phiếu mua chỉ bày lô dương, mà màn này chính là chỗ chữa lô âm.
func TestDieuChinh_MatHangBayCaLoAm(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	donKho(t, h, a)
	nhapLo(t, h, a, "LO-DC9", 3, "")
	lapDieuChinh(t, h, a, map[string]any{
		"type": "balance", "status": "approved",
		"items": []any{map[string]any{"variant_id": a.bienThe, "lot_number": "", "adjust_quantity": -1}},
	})

	res := h.goiChiNhanh(t, a.token, a.chiNhanh, http.MethodGet,
		fmt.Sprintf("%s/mat-hang?ids=%d", duongDieuChinh, a.bienThe), nil)
	if res.ma != http.StatusOK {
		t.Fatalf("đọc mặt hàng trả %d\n%s", res.ma, catBot(res.than))
	}
	var body struct {
		Data []struct {
			VariantID uint `json:"variant_id"`
			Stock     int  `json:"stock"`
			Lots      []struct {
				LotNumber string `json:"lot_number"`
				Quantity  int    `json:"quantity"`
			} `json:"lots"`
		} `json:"data"`
	}
	_ = json.Unmarshal([]byte(res.than), &body)
	if len(body.Data) != 1 || body.Data[0].Stock != 2 {
		t.Fatalf("phải ra một mặt hàng tồn 2, nhận %s", catBot(res.than))
	}
	co := map[string]int{}
	for _, l := range body.Data[0].Lots {
		co[l.LotNumber] = l.Quantity
	}
	if co["LO-DC9"] != 3 || co[""] != -1 {
		t.Fatalf("phải bày lô LO-DC9 = 3 và lô \"\" = -1, nhận %v", co)
	}
}

// Cửa hàng B không được đọc hay duyệt phiếu của cửa hàng A.
func TestDieuChinh_KhongLotSangCuaHangKhac(t *testing.T) {
	h := dungHeThong(t)
	a, b := haiCuaHang(t, h)

	donKho(t, h, a)
	nhapLo(t, h, a, "LO-DC8", 2, "")

	_, p := lapDieuChinh(t, h, a, map[string]any{
		"items": []any{map[string]any{"variant_id": a.bienThe, "lot_number": "LO-DC8", "adjust_quantity": 1}},
	})

	if res := h.goiChiNhanh(t, b.token, b.chiNhanh, http.MethodGet,
		fmt.Sprintf("%s/%d", duongDieuChinh, p.ID), nil); res.ma == http.StatusOK {
		t.Fatal("cửa hàng B đọc được phiếu của cửa hàng A")
	}
	if res := h.goiChiNhanh(t, b.token, b.chiNhanh, http.MethodPost,
		fmt.Sprintf("%s/%d/duyet", duongDieuChinh, p.ID), nil); res.ma == http.StatusOK {
		t.Fatal("cửa hàng B duyệt được phiếu của cửa hàng A")
	}
}

// Lô có số mà kho chưa có thì bị từ chối ngay lúc lập — kể cả lưu tạm.
//
// Trước đây API nhận với tồn 0 (coi như khai lô mới), trong khi màn hình không
// có mục "lô mới": đường ấy chỉ đi được bằng tay, và một phiếu như thế duyệt lên
// là kho có một lô chưa từng nhập. Chủ tiệm chốt: phiếu điều chỉnh chỉ nắn số
// của lô đã có, lô mới vào kho bằng phiếu nhập.
func TestDieuChinh_TuChoiLoKhongCoTrongKho(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	donKho(t, h, a)
	nhapLo(t, h, a, "LO-CO", 5, "")

	ma, _ := lapDieuChinh(t, h, a, map[string]any{
		"status": "draft",
		"items":  []any{map[string]any{"variant_id": a.bienThe, "lot_number": "LO-KHONG-CO", "adjust_quantity": 1}},
	})
	if ma != http.StatusUnprocessableEntity {
		t.Fatalf("lô lạ phải bị từ chối 422, nhận %d", ma)
	}

	res := h.goiChiNhanh(t, a.token, a.chiNhanh, http.MethodPost, duongDieuChinh, map[string]any{
		"status": "draft",
		"items":  []any{map[string]any{"variant_id": a.bienThe, "lot_number": "LO-KHONG-CO", "adjust_quantity": 1}},
	})
	if !strings.Contains(res.than, "LO-KHONG-CO") {
		t.Fatalf("câu từ chối phải nêu số lô:\n%s", catBot(res.than))
	}

	// Lô có thật vẫn lập được như thường.
	ma, _ = lapDieuChinh(t, h, a, map[string]any{
		"status": "draft",
		"items":  []any{map[string]any{"variant_id": a.bienThe, "lot_number": "LO-CO", "adjust_quantity": 1}},
	})
	if ma != http.StatusCreated {
		t.Fatalf("lô có trong kho phải lập được, nhận %d", ma)
	}
}
