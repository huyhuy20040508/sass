package apitest

import (
	"encoding/json"
	"net/http"
	"testing"
)

// Báo cáo kết ca — GET /admin/reports/shifts.
//
// Bám vào CON SỐ: một ca bán hai hình thức, đóng ca lệch két, rồi đọc báo cáo
// xem từng cột có đúng tiền không. Thêm ba điều dễ sai mà trang vẫn 200: ca còn
// mở không được in số đối chiếu, đơn bán lúc chưa mở ca không thuộc ca nào, và
// cửa hàng này không thấy ca của cửa hàng kia.

// caBaoCao là một dòng của báo cáo, chỉ những trường bài kiểm đọc tới.
type caBaoCao struct {
	ID            uint     `json:"id"`
	ClosedAt      *string  `json:"closed_at"`
	OrderCount    int64    `json:"order_count"`
	TotalCash     float64  `json:"total_cash"`
	TotalTransfer float64  `json:"total_transfer"`
	TotalRevenue  float64  `json:"total_revenue"`
	OpeningCash   float64  `json:"opening_cash"`
	ExpectedCash  *float64 `json:"expected_cash"`
	CountedCash   *float64 `json:"counted_cash"`
	Difference    *float64 `json:"difference"`
	OpenNote      string   `json:"open_note"`
	CloseNote     string   `json:"close_note"`
	OpenedByName  string   `json:"opened_by_name"`
}

type baoCaoCa struct {
	Data []caBaoCao `json:"data"`
	Meta struct {
		Page       int   `json:"page"`
		PageSize   int   `json:"page_size"`
		Total      int64 `json:"total"`
		TotalPages int   `json:"total_pages"`
		Tong       struct {
			TotalRevenue float64 `json:"total_revenue"`
			OrderCount   int64   `json:"order_count"`
		} `json:"tong"`
	} `json:"meta"`
}

func docBaoCaoCa(t *testing.T, h *heThong, c *cuaHang, query string) baoCaoCa {
	t.Helper()

	res := h.goi(t, c.token, http.MethodGet, "/api/v1/admin/reports/shifts"+query, nil)
	if res.ma != http.StatusOK {
		t.Fatalf("báo cáo kết ca trả %d\n%s", res.ma, catBot(res.than))
	}
	var out baoCaoCa
	if err := json.Unmarshal([]byte(res.than), &out); err != nil {
		t.Fatalf("không đọc được báo cáo kết ca: %v\n%s", err, catBot(res.than))
	}
	return out
}

func banQuay(t *testing.T, h *heThong, c *cuaHang, hinhThuc string, soLuong int) {
	t.Helper()

	res := h.goi(t, c.token, http.MethodPost, "/api/v1/admin/orders/pos", map[string]any{
		"payment_method": hinhThuc,
		"items":          []map[string]any{{"product_variant_id": c.bienThe, "quantity": soLuong}},
	})
	if res.ma != http.StatusCreated {
		t.Fatalf("bán tại quầy (%s) trả %d\n%s", hinhThuc, res.ma, catBot(res.than))
	}
}

// TestBaoCaoKetCa_SoLieuMotCa — một ca đã đóng: tiền theo hình thức, đối chiếu
// két và hai ghi chú nằm đúng cột.
func TestBaoCaoKetCa_SoLieuMotCa(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	res := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/ca-lam-viec/mo",
		map[string]any{"opening_cash": 500000, "note": "Két đủ tiền lẻ"})
	if res.ma != http.StatusCreated {
		t.Fatalf("mở ca trả %d\n%s", res.ma, catBot(res.than))
	}

	banQuay(t, h, a, "cash", 2)          // 180.000 tiền mặt
	banQuay(t, h, a, "bank_transfer", 1) // 90.000 chuyển khoản

	// Theo sổ: 500.000 + 180.000 = 680.000. Đếm được 675.000 → thiếu 5.000.
	res = h.goi(t, a.token, http.MethodPost, "/api/v1/admin/ca-lam-viec/dong",
		map[string]any{"counted_cash": 675000, "note": "Thiếu 5 nghìn"})
	if res.ma != http.StatusOK {
		t.Fatalf("đóng ca trả %d\n%s", res.ma, catBot(res.than))
	}

	bc := docBaoCaoCa(t, h, a, "")
	if len(bc.Data) != 1 || bc.Meta.Total != 1 {
		t.Fatalf("phải có đúng 1 ca, đang có %d dòng / total %d", len(bc.Data), bc.Meta.Total)
	}
	ca := bc.Data[0]

	if ca.OrderCount != 2 {
		t.Errorf("số đơn phải là 2, đang là %d", ca.OrderCount)
	}
	if ca.TotalCash != 180000 || ca.TotalTransfer != 90000 || ca.TotalRevenue != 270000 {
		t.Errorf("tiền mặt/chuyển khoản/doanh thu phải là 180000/90000/270000, đang là %v/%v/%v",
			ca.TotalCash, ca.TotalTransfer, ca.TotalRevenue)
	}
	if ca.OpeningCash != 500000 {
		t.Errorf("tiền đầu ca phải là 500000, đang là %v", ca.OpeningCash)
	}
	if ca.ExpectedCash == nil || *ca.ExpectedCash != 680000 ||
		ca.CountedCash == nil || *ca.CountedCash != 675000 ||
		ca.Difference == nil || *ca.Difference != -5000 {
		t.Errorf("đối chiếu két phải là 680000/675000/-5000, đang là %v/%v/%v",
			ca.ExpectedCash, ca.CountedCash, ca.Difference)
	}
	// Ghi chú đóng ca KHÔNG được đè ghi chú mở ca nữa.
	if ca.OpenNote != "Két đủ tiền lẻ" || ca.CloseNote != "Thiếu 5 nghìn" {
		t.Errorf("ghi chú mở/đóng phải là %q/%q, đang là %q/%q",
			"Két đủ tiền lẻ", "Thiếu 5 nghìn", ca.OpenNote, ca.CloseNote)
	}
	if ca.OpenedByName == "" {
		t.Error("thiếu tên người mở ca")
	}
	if bc.Meta.Tong.TotalRevenue != 270000 || bc.Meta.Tong.OrderCount != 2 {
		t.Errorf("dòng tổng phải là 270000 / 2 đơn, đang là %v / %d",
			bc.Meta.Tong.TotalRevenue, bc.Meta.Tong.OrderCount)
	}
}

// TestBaoCaoKetCa_CaDangMo — ca chưa đóng vẫn đếm đơn tới lúc xem, nhưng ba con
// số đối chiếu két là null: chưa ai đếm két thì không có "chênh 0".
func TestBaoCaoKetCa_CaDangMo(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	// Bán lúc CHƯA mở ca — lượt này không thuộc ca nào.
	banQuay(t, h, a, "cash", 1)

	moCa(t, h, a, 200000)
	banQuay(t, h, a, "cash", 1)

	bc := docBaoCaoCa(t, h, a, "")
	if len(bc.Data) != 1 {
		t.Fatalf("phải có đúng 1 ca, đang có %d", len(bc.Data))
	}
	ca := bc.Data[0]

	if ca.ClosedAt != nil {
		t.Errorf("ca đang mở mà closed_at = %v", *ca.ClosedAt)
	}
	if ca.OrderCount != 1 || ca.TotalRevenue != 90000 {
		t.Errorf("chỉ đơn bán SAU lúc mở ca mới thuộc ca: phải là 1 đơn / 90000, đang là %d / %v",
			ca.OrderCount, ca.TotalRevenue)
	}
	if ca.ExpectedCash != nil || ca.CountedCash != nil || ca.Difference != nil {
		t.Errorf("ca đang mở không được có số đối chiếu két, đang là %v/%v/%v",
			ca.ExpectedCash, ca.CountedCash, ca.Difference)
	}
}

// TestBaoCaoKetCa_PhanTrangVaTongCaKy — dòng tổng cộng mọi ca, không riêng trang.
func TestBaoCaoKetCa_PhanTrangVaTongCaKy(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	for i := 0; i < 2; i++ {
		moCa(t, h, a, 0)
		banQuay(t, h, a, "cash", 1)
		res := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/ca-lam-viec/dong",
			map[string]any{"counted_cash": 90000})
		if res.ma != http.StatusOK {
			t.Fatalf("đóng ca lần %d trả %d\n%s", i+1, res.ma, catBot(res.than))
		}
	}

	bc := docBaoCaoCa(t, h, a, "?page_size=1")
	if len(bc.Data) != 1 || bc.Meta.Total != 2 || bc.Meta.TotalPages != 2 {
		t.Fatalf("trang 1 cỡ 1: phải 1 dòng / total 2 / 2 trang, đang là %d / %d / %d",
			len(bc.Data), bc.Meta.Total, bc.Meta.TotalPages)
	}
	if bc.Meta.Tong.TotalRevenue != 180000 || bc.Meta.Tong.OrderCount != 2 {
		t.Errorf("dòng tổng phải cộng CẢ HAI ca (180000 / 2 đơn), đang là %v / %d",
			bc.Meta.Tong.TotalRevenue, bc.Meta.Tong.OrderCount)
	}

	trang2 := docBaoCaoCa(t, h, a, "?page_size=1&page=2")
	if len(trang2.Data) != 1 || trang2.Data[0].ID == bc.Data[0].ID {
		t.Errorf("trang 2 phải là ca còn lại")
	}
}

// TestBaoCaoKetCa_BoLoc — kỳ không có ca và từ khoá không khớp đều ra rỗng.
func TestBaoCaoKetCa_BoLoc(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	moCa(t, h, a, 0)

	if bc := docBaoCaoCa(t, h, a, "?from=2000-01-01&to=2000-01-31"); bc.Meta.Total != 0 || len(bc.Data) != 0 {
		t.Errorf("kỳ năm 2000 phải rỗng, đang có %d ca", bc.Meta.Total)
	}
	if bc := docBaoCaoCa(t, h, a, "?keyword=khong-ai-ten-nay"); bc.Meta.Total != 0 {
		t.Errorf("từ khoá không khớp phải rỗng, đang có %d ca", bc.Meta.Total)
	}
	// Đối chứng: không lọc thì thấy đúng ca vừa mở.
	if bc := docBaoCaoCa(t, h, a, ""); bc.Meta.Total != 1 {
		t.Errorf("không lọc phải thấy 1 ca, đang thấy %d", bc.Meta.Total)
	}
}

// TestBaoCaoKetCa_KhongLanCuaHang — cửa hàng B không thấy ca của cửa hàng A.
func TestBaoCaoKetCa_KhongLanCuaHang(t *testing.T) {
	h := dungHeThong(t)
	a, b := haiCuaHang(t, h)

	moCa(t, h, a, 100000)
	banQuay(t, h, a, "cash", 1)

	if bc := docBaoCaoCa(t, h, b, "?shop_id=0"); bc.Meta.Total != 0 || bc.Meta.Tong.TotalRevenue != 0 {
		t.Fatalf("cửa hàng B thấy %d ca / %v đồng của cửa hàng A", bc.Meta.Total, bc.Meta.Tong.TotalRevenue)
	}
	// Đối chứng: A thấy ca của chính mình.
	if bc := docBaoCaoCa(t, h, a, ""); bc.Meta.Total != 1 {
		t.Fatalf("cửa hàng A phải thấy 1 ca, đang thấy %d", bc.Meta.Total)
	}
}
