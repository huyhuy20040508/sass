package apitest

import (
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"strings"
	"testing"

	"sass-api/internal/tenant"
)

// ĐƠN NHÁP Ở QUẦY (pos_drafts): thêm món đầu tiên là có đơn kèm mã thật, mọi máy
// trong chi nhánh thấy, huỷ phải có lý do; thanh toán thì đơn thật mang đúng mã
// của nháp. Nháp không giữ kho và không vào sổ đơn.

type nhapQuay struct {
	ID            uint    `json:"id"`
	OrderCode     string  `json:"order_code"`
	Status        string  `json:"status"`
	CreatedBy     uint    `json:"created_by"`
	CreatedByName string  `json:"created_by_name"`
	ItemCount     int     `json:"item_count"`
	TamTinh       float64 `json:"tam_tinh"`
	OrderID       *uint   `json:"order_id"`
	CancelReason  string  `json:"cancel_reason"`
	Items         []struct {
		ProductVariantID uint   `json:"product_variant_id"`
		Quantity         int    `json:"quantity"`
		ProductName      string `json:"product_name"`
	} `json:"items"`
	DuLieu map[string]any `json:"du_lieu"`
}

func docNhap(t *testing.T, res traLoi) nhapQuay {
	t.Helper()
	var out struct {
		Data nhapQuay `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &out); err != nil {
		t.Fatalf("không đọc được đơn nháp: %v\n%s", err, catBot(res.than))
	}

	return out.Data
}

func dsNhap(t *testing.T, h *heThong, tok, q string) []nhapQuay {
	t.Helper()
	res := h.goi(t, tok, http.MethodGet, "/api/v1/admin/orders/pos/nhap"+q, nil)
	if res.ma != http.StatusOK {
		t.Fatalf("danh sách đơn nháp trả %d\n%s", res.ma, catBot(res.than))
	}
	var out struct {
		Data []nhapQuay `json:"data"`
	}
	_ = json.Unmarshal([]byte(res.than), &out)

	return out.Data
}

func coNhap(ds []nhapQuay, id uint) bool {
	for _, d := range ds {
		if d.ID == id {
			return true
		}
	}

	return false
}

func soDongSoDon(t *testing.T, h *heThong, tok string) int {
	t.Helper()
	res := h.goi(t, tok, http.MethodGet, "/api/v1/admin/orders/so-don?channel=pos&page_size=1", nil)
	var out struct {
		Meta struct {
			Total int `json:"total"`
		} `json:"meta"`
	}
	_ = json.Unmarshal([]byte(res.than), &out)

	return out.Meta.Total
}

// KB N01–N07.
func TestQuay_DonNhap(t *testing.T) {
	h := dungHeThong(t)
	a, b := haiCuaHang(t, h)
	moCa(t, h, a, 0)
	thuNgan := h.dangNhapVoi(t, a.ma, "nhanvien")

	ctx := tenant.WithoutScope(context.Background(), "test: bật quy tắc mã đơn cho chi nhánh gieo sẵn")
	if err := h.db.WithContext(ctx).Exec(`INSERT INTO code_rules
		(tenant_id, shop_id, doc_type, prefix, value_part, length, suffix, is_active, created_at, updated_at)
		VALUES (?, ?, 'don-hang', 'QT', 'so-thu-tu', 5, '', 1, NOW(), NOW())
		ON DUPLICATE KEY UPDATE prefix = 'QT', value_part = 'so-thu-tu', length = 5, suffix = '', is_active = 1`,
		a.id, a.chiNhanh).Error; err != nil {
		t.Fatalf("không bật được quy tắc mã: %v", err)
	}

	tonTruoc := tonBienThe(t, h, a.bienThe)
	soDonTruoc := soDongSoDon(t, h, a.token)
	gio := func(sl int) map[string]any {
		return map[string]any{
			"items":         []map[string]any{{"product_variant_id": a.bienThe, "quantity": sl, "product_name": "Hàng test", "unit_price": 100000}},
			"customer_name": "Khách " + a.vet,
			"tam_tinh":      100000 * sl,
			"du_lieu":       map[string]any{"giamDon": map[string]any{"kieu": "amount", "gt": 5000}},
		}
	}

	// N01 — món đầu tiên: có đơn nháp, mã thật, KHÔNG trừ kho, KHÔNG vào sổ đơn.
	r := h.goi(t, thuNgan, http.MethodPost, "/api/v1/admin/orders/pos/nhap", gio(1))
	if r.ma != http.StatusCreated {
		t.Fatalf("N01 mở hoá đơn trả %d\n%s", r.ma, catBot(r.than))
	}
	n1 := docNhap(t, r)
	if n1.Status != "open" || !strings.HasPrefix(n1.OrderCode, "QT") || n1.ItemCount != 1 || n1.CreatedByName == "" {
		t.Fatalf("N01 đơn nháp sai: %+v", n1)
	}
	if n1.DuLieu["giamDon"] == nil {
		t.Fatalf("N01 phải giữ nguyên du_lieu của màn quầy, đang là %+v", n1.DuLieu)
	}
	if got := tonBienThe(t, h, a.bienThe); got != tonTruoc {
		t.Fatalf("N01 đơn nháp không được trừ kho: %d → %d", tonTruoc, got)
	}
	if got := soDongSoDon(t, h, a.token); got != soDonTruoc {
		t.Fatalf("N01 đơn nháp không được vào sổ đơn: %d → %d", soDonTruoc, got)
	}

	// N02 — sửa giỏ (ghi đè), xem được ở danh sách; cua_toi chỉ lấy hoá đơn của mình.
	r = h.goi(t, thuNgan, http.MethodPut, fmt.Sprintf("/api/v1/admin/orders/pos/nhap/%d", n1.ID), gio(3))
	if r.ma != http.StatusOK || docNhap(t, r).ItemCount != 3 || docNhap(t, r).OrderCode != n1.OrderCode {
		t.Fatalf("N02 lưu hoá đơn trả %d\n%s", r.ma, catBot(r.than))
	}
	if !coNhap(dsNhap(t, h, thuNgan, "?status=open&cua_toi=1"), n1.ID) {
		t.Fatal("N02 hoá đơn của mình phải có trong cua_toi=1")
	}
	if !coNhap(dsNhap(t, h, a.token, "?status=open"), n1.ID) || coNhap(dsNhap(t, h, a.token, "?status=open&cua_toi=1"), n1.ID) {
		t.Fatal("N02 chủ tiệm thấy hoá đơn của thu ngân ở danh sách chi nhánh, nhưng không ở cua_toi=1")
	}

	// N06 — cửa hàng khác không đọc / sửa được.
	if r := h.goi(t, b.token, http.MethodGet, fmt.Sprintf("/api/v1/admin/orders/pos/nhap/%d", n1.ID), nil); r.ma != http.StatusNotFound {
		t.Fatalf("N06 cửa hàng khác đọc đơn nháp phải 404, nhận %d", r.ma)
	}
	if r := h.goi(t, b.token, http.MethodPut, fmt.Sprintf("/api/v1/admin/orders/pos/nhap/%d", n1.ID), gio(1)); r.ma != http.StatusNotFound {
		t.Fatalf("N06 cửa hàng khác sửa đơn nháp phải 404, nhận %d", r.ma)
	}

	// N05 — chốt hỏng (vượt tồn): hoá đơn MỞ LẠI, sửa và chốt tiếp được.
	r = h.goi(t, thuNgan, http.MethodPost, "/api/v1/admin/orders/pos", map[string]any{
		"payment_method": "cash", "draft_id": n1.ID,
		"items": []map[string]any{{"product_variant_id": a.bienThe, "quantity": 99}},
	})
	if r.ma == http.StatusCreated {
		t.Fatalf("N05 bán 99 cái phải bị từ chối (tồn %d)", tonTruoc)
	}
	if d := docNhap(t, h.goi(t, thuNgan, http.MethodGet, fmt.Sprintf("/api/v1/admin/orders/pos/nhap/%d", n1.ID), nil)); d.Status != "open" {
		t.Fatalf("N05 chốt hỏng thì hoá đơn phải mở lại, đang là %s", d.Status)
	}

	// N03 — chốt kèm draft_id: đơn mang ĐÚNG mã của nháp, kho trừ lúc này, nháp → converted.
	r = h.goi(t, thuNgan, http.MethodPost, "/api/v1/admin/orders/pos", map[string]any{
		"payment_method": "cash", "draft_id": n1.ID,
		"items": []map[string]any{{"product_variant_id": a.bienThe, "quantity": 2}},
	})
	if r.ma != http.StatusCreated {
		t.Fatalf("N03 chốt từ nháp trả %d\n%s", r.ma, catBot(r.than))
	}
	var ban struct {
		Data struct {
			OrderID   uint   `json:"order_id"`
			OrderCode string `json:"order_code"`
		} `json:"data"`
	}
	_ = json.Unmarshal([]byte(r.than), &ban)
	if ban.Data.OrderCode != n1.OrderCode {
		t.Fatalf("N03 đơn phải mang mã của nháp %s, đang là %s", n1.OrderCode, ban.Data.OrderCode)
	}
	if got := tonBienThe(t, h, a.bienThe); got != tonTruoc-2 {
		t.Fatalf("N03 chốt xong phải trừ 2: %d → %d", tonTruoc, got)
	}
	d := docNhap(t, h.goi(t, thuNgan, http.MethodGet, fmt.Sprintf("/api/v1/admin/orders/pos/nhap/%d", n1.ID), nil))
	if d.Status != "converted" || d.OrderID == nil || *d.OrderID != ban.Data.OrderID {
		t.Fatalf("N03 nháp phải chuyển converted kèm order_id %d, đang là %+v", ban.Data.OrderID, d)
	}
	if coNhap(dsNhap(t, h, thuNgan, "?status=open"), n1.ID) {
		t.Fatal("N03 hoá đơn đã chốt không được còn trong danh sách đang mở")
	}
	// Chốt lại / sửa hoá đơn đã thành đơn (máy thứ hai bấm muộn): 409, không lập đơn thứ hai.
	if r := h.goi(t, thuNgan, http.MethodPost, "/api/v1/admin/orders/pos", map[string]any{
		"payment_method": "cash", "draft_id": n1.ID,
		"items": []map[string]any{{"product_variant_id": a.bienThe, "quantity": 1}},
	}); r.ma != http.StatusConflict {
		t.Fatalf("N03 chốt lại hoá đơn đã thành đơn phải 409, nhận %d %s", r.ma, catBot(r.than))
	}
	if r := h.goi(t, thuNgan, http.MethodPut, fmt.Sprintf("/api/v1/admin/orders/pos/nhap/%d", n1.ID), gio(1)); r.ma != http.StatusConflict {
		t.Fatalf("N03 sửa hoá đơn đã thành đơn phải 409, nhận %d", r.ma)
	}
	if got := tonBienThe(t, h, a.bienThe); got != tonTruoc-2 {
		t.Fatalf("N03 lượt chốt bị từ chối không được trừ kho thêm: %d", got)
	}

	// N04 — huỷ: bắt buộc lý do; giữ lại dòng; sau đó không sửa / chốt được.
	n2 := docNhap(t, h.goi(t, thuNgan, http.MethodPost, "/api/v1/admin/orders/pos/nhap", gio(1)))
	if n2.OrderCode == "" || n2.OrderCode == n1.OrderCode {
		t.Fatalf("N04 hoá đơn thứ hai phải có mã riêng, đang là %q", n2.OrderCode)
	}
	if r := h.goi(t, thuNgan, http.MethodPost, fmt.Sprintf("/api/v1/admin/orders/pos/nhap/%d/huy", n2.ID), map[string]any{"ly_do": ""}); r.ma != http.StatusUnprocessableEntity && r.ma != http.StatusBadRequest {
		t.Fatalf("N04 huỷ không lý do phải bị chặn, nhận %d", r.ma)
	}
	r = h.goi(t, thuNgan, http.MethodPost, fmt.Sprintf("/api/v1/admin/orders/pos/nhap/%d/huy", n2.ID), map[string]any{"ly_do": "Khách đổi ý"})
	if r.ma != http.StatusOK || docNhap(t, r).Status != "cancelled" || docNhap(t, r).CancelReason != "Khách đổi ý" {
		t.Fatalf("N04 huỷ trả %d\n%s", r.ma, catBot(r.than))
	}
	if !coNhap(dsNhap(t, h, thuNgan, "?status=cancelled"), n2.ID) {
		t.Fatal("N04 hoá đơn đã huỷ phải còn trong danh sách cancelled")
	}
	if r := h.goi(t, thuNgan, http.MethodPost, "/api/v1/admin/orders/pos", map[string]any{
		"payment_method": "cash", "draft_id": n2.ID,
		"items": []map[string]any{{"product_variant_id": a.bienThe, "quantity": 1}},
	}); r.ma != http.StatusConflict {
		t.Fatalf("N04 chốt hoá đơn đã huỷ phải 409, nhận %d", r.ma)
	}

	// N07 — sau cả loạt: sổ đơn chỉ thêm đúng MỘT đơn (lượt chốt N03).
	if got := soDongSoDon(t, h, a.token); got != soDonTruoc+1 {
		t.Fatalf("N07 sổ đơn phải thêm đúng 1 đơn: %d → %d", soDonTruoc, got)
	}
}
