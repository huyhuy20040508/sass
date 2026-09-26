package apitest

import (
	"context"
	"encoding/json"
	"net/http"
	"strings"
	"testing"

	"sass-api/internal/tenant"
)

type maDonGiu struct {
	OrderCode string `json:"order_code"`
	Token     string `json:"token"`
}

func giuMaDon(t *testing.T, h *heThong, token string) maDonGiu {
	t.Helper()

	res := h.goi(t, token, http.MethodPost, "/api/v1/admin/orders/pos/ma-don", nil)
	if res.ma != http.StatusOK {
		t.Fatalf("giữ mã đơn trả %d\n%s", res.ma, catBot(res.than))
	}
	var out struct {
		Data maDonGiu `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &out); err != nil {
		t.Fatalf("không đọc được mã giữ: %v\n%s", err, catBot(res.than))
	}

	return out.Data
}

func banVoiMa(t *testing.T, h *heThong, token string, bienThe uint, ma, chuKy string) string {
	t.Helper()

	res := h.goi(t, token, http.MethodPost, "/api/v1/admin/orders/pos", map[string]any{
		"payment_method":   "bank_transfer",
		"order_code":       ma,
		"order_code_token": chuKy,
		"items":            []map[string]any{{"product_variant_id": bienThe, "quantity": 1}},
	})
	if res.ma != http.StatusCreated {
		t.Fatalf("bán kèm mã giữ trước trả %d\n%s", res.ma, catBot(res.than))
	}
	var out struct {
		Data struct {
			OrderCode string `json:"order_code"`
		} `json:"data"`
	}
	_ = json.Unmarshal([]byte(res.than), &out)

	return out.Data.OrderCode
}

// TestBanTaiQuay_GiuMaDon — tab hoá đơn hiện mã đơn trước khi chốt, và đơn vào sổ
// ĐÚNG mã ấy. Mã giả chữ ký hoặc mã đã dùng thì lượt bán vẫn qua với mã mới.
func TestBanTaiQuay_GiuMaDon(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	thuNgan := h.dangNhapVoi(t, a.ma, "nhanvien")

	// Chưa bật quy tắc mã đơn: không có gì để cấp trước.
	if m := giuMaDon(t, h, thuNgan); m.OrderCode != "" || m.Token != "" {
		t.Fatalf("chưa bật quy tắc mã thì không được cấp mã, đang là %+v", m)
	}

	ctx := tenant.WithoutScope(context.Background(), "test: bật quy tắc mã đơn cho chi nhánh gieo sẵn")
	if err := h.db.WithContext(ctx).Exec(`INSERT INTO code_rules
		(tenant_id, shop_id, doc_type, prefix, value_part, length, suffix, is_active, created_at, updated_at)
		VALUES (?, ?, 'don-hang', 'QT', 'so-thu-tu', 5, '', 1, NOW(), NOW())
		ON DUPLICATE KEY UPDATE prefix = 'QT', value_part = 'so-thu-tu', length = 5, suffix = '', is_active = 1`,
		a.id, a.chiNhanh).Error; err != nil {
		t.Fatalf("không bật được quy tắc mã: %v", err)
	}

	m1 := giuMaDon(t, h, thuNgan)
	m2 := giuMaDon(t, h, thuNgan)
	if !strings.HasPrefix(m1.OrderCode, "QT") || !strings.HasPrefix(m2.OrderCode, "QT") || m1.OrderCode == m2.OrderCode || m1.Token == "" {
		t.Fatalf("hai hoá đơn phải nhận hai mã QT khác nhau kèm chữ ký, đang là %+v / %+v", m1, m2)
	}

	// Chốt hoá đơn thứ hai trước: đơn vào sổ đúng mã trên tab của nó.
	if ma := banVoiMa(t, h, thuNgan, a.bienThe, m2.OrderCode, m2.Token); ma != m2.OrderCode {
		t.Fatalf("đơn phải vào sổ đúng mã đã giữ %s, đang là %s", m2.OrderCode, ma)
	}

	// Mã của hoá đơn 1 nhưng chữ ký của hoá đơn 2: bỏ mã, vẫn bán, cấp mã mới.
	if ma := banVoiMa(t, h, thuNgan, a.bienThe, m1.OrderCode, m2.Token); ma == m1.OrderCode || !strings.HasPrefix(ma, "QT") {
		t.Fatalf("chữ ký không khớp thì phải cấp mã mới theo quy tắc, đang là %s", ma)
	}

	// Chốt lại một mã đã vào sổ: không đụng khoá duy nhất, cấp mã mới.
	if ma := banVoiMa(t, h, thuNgan, a.bienThe, m2.OrderCode, m2.Token); ma == m2.OrderCode || ma == "" {
		t.Fatalf("mã đã có đơn dùng thì phải cấp mã mới, đang là %s", ma)
	}
}
