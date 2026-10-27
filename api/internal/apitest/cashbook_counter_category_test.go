package apitest

import (
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"reflect"
	"strings"
	"testing"

	"sass-api/internal/domain"
	"sass-api/internal/tenant"
)

// Dãy phân loại ở hộp lập phiếu của quầy: chủ ghim → hay dùng ở chi nhánh 30 ngày
// (chỉ phiếu lập tay) → loại "việc của quầy"; khoản chủ trả định kỳ không tự lên.

func docPhanLoaiNhanh(t *testing.T, h *heThong, token string) (nhanh, ghim []uint) {
	t.Helper()
	res := h.goi(t, token, http.MethodGet, duongTCQuay+"/danh-muc", nil)
	var body struct {
		Data struct {
			Nhanh []uint `json:"phan_loai_nhanh"`
			Ghim  []uint `json:"phan_loai_ghim"`
		} `json:"data"`
	}
	if res.ma != http.StatusOK || json.Unmarshal([]byte(res.than), &body) != nil {
		t.Fatalf("danh mục quầy phải 200, nhận %d\n%s", res.ma, catBot(res.than))
	}
	return body.Data.Nhanh, body.Data.Ghim
}

// KB PL01–PL07
func TestThuChiQuay_PhanLoaiNhanh(t *testing.T) {
	h := dungHeThong(t)
	a, b := haiCuaHang(t, h)
	thuNgan := h.dangNhapVoi(t, a.ma, "nhanvien")
	ctx := tenant.WithID(context.Background(), a.id)
	db := h.db.WithContext(ctx)
	// SQL viết tay tự khai tenant_id nên chạy ngoài phạm vi tenant.
	raw := h.db.WithContext(ctxThoat())

	// Danh mục sạch: chỉ những loại của bài này.
	raw.Exec("DELETE FROM income_expense_types WHERE tenant_id = ?", a.id)
	tao := func(loai uint8, ten string) uint {
		l := domain.LoaiThuChi{Type: loai, Name: ten}
		if err := db.Create(&l).Error; err != nil {
			t.Fatal(err)
		}
		return l.ID
	}
	luong, thue := tao(domain.LoaiChi, "Lương, thưởng"), tao(domain.LoaiChi, "Thuê mặt bằng")
	phiTT, ship := tao(domain.LoaiChi, "Phí thanh toán, phí ngân hàng"), tao(domain.LoaiChi, "Vận chuyển, giao hàng")
	nuoc, sua := tao(domain.LoaiChi, "Mua nước đá"), tao(domain.LoaiChi, "Sửa chữa, bảo dưỡng")
	ung, khauHao := tao(domain.LoaiChi, "Tạm ứng nhân viên"), tao(domain.LoaiChi, "Khấu hao tài sản")
	thuKhac, coc, lai := tao(domain.LoaiThu, "Các khoản thu khác"), tao(domain.LoaiThu, "Thu tiền cọc"), tao(domain.LoaiThu, "Lãi tiền gửi")
	_ = luong

	// PL01 chưa có phiếu nào: thu trước, chi sau; chi theo thứ tự việc của quầy, không có khoản của chủ.
	if nhanh, _ := docPhanLoaiNhanh(t, h, thuNgan); !reflect.DeepEqual(nhanh, []uint{thuKhac, coc, lai, ship, nuoc, ung, sua}) {
		t.Fatalf("PL01 dãy mặc định sai: %v", nhanh)
	}

	so := 0
	phieu := func(loai uint8, cat uint, nguon, luc string) {
		t.Helper()
		if err := raw.Exec(`INSERT INTO income_expenses (tenant_id, shop_id, code, type, category_id, amount, payer_type, payment_method, attachment, note, source, created_at, updated_at)
			VALUES (?, ?, ?, ?, ?, 10000, '', 'cash', '', '', ?, `+luc+`, NOW(3))`, a.id, a.chiNhanh, fmt.Sprintf("PLT%d", so), loai, cat, nguon).Error; err != nil {
			t.Fatal(err)
		}
		so++
	}
	// PL02 phí thẻ tự sinh 5 phiếu, phiếu tay quá 30 ngày: KHÔNG tính. Phiếu tay mới: khấu hao 2, nước 1.
	for i := 0; i < 5; i++ {
		raw.Exec(`INSERT INTO income_expenses (tenant_id, shop_id, code, type, category_id, amount, payer_type, payment_method, attachment, note, source, created_at, updated_at)
			VALUES (?, ?, ?, 1, ?, 1000, '', 'cash', '', '', 'card_fee', NOW(3), NOW(3))`, a.id, a.chiNhanh, fmt.Sprintf("PLF%d", i), phiTT)
	}
	for i := 0; i < 3; i++ {
		raw.Exec(`INSERT INTO income_expenses (tenant_id, shop_id, code, type, category_id, amount, payer_type, payment_method, attachment, note, source, created_at, updated_at)
			VALUES (?, ?, ?, 1, ?, 1000, '', 'cash', '', '', 'manual', NOW(3) - INTERVAL 31 DAY, NOW(3))`, a.id, a.chiNhanh, fmt.Sprintf("PLC%d", i), sua)
	}
	phieu(domain.LoaiChi, khauHao, "manual", "NOW(3) - INTERVAL 1 DAY")
	phieu(domain.LoaiChi, khauHao, "manual", "NOW(3) - INTERVAL 2 DAY")
	phieu(domain.LoaiChi, nuoc, "manual", "NOW(3)")
	// Khấu hao là khoản của chủ nhưng quầy DÙNG thật → lên đầu; sửa chữa (chỉ phiếu cũ) rơi khỏi dãy.
	if nhanh, _ := docPhanLoaiNhanh(t, h, thuNgan); !reflect.DeepEqual(nhanh, []uint{thuKhac, coc, lai, khauHao, nuoc, ship, ung}) {
		t.Fatalf("PL02 hay dùng phải đứng trước, không tính phí thẻ / phiếu quá 30 ngày: %v", nhanh)
	}

	// PL03 chủ ghim thuê mặt bằng + thu cọc (kèm một id không tồn tại): ghim đứng đầu vế của nó.
	dat := func(v string) traLoi {
		return h.goi(t, a.token, http.MethodPut, "/api/v1/admin/settings", map[string]any{"items": map[string]string{"pos_cashbook_pinned_types": v}})
	}
	if r := dat(fmt.Sprintf("%d,%d,999999", thue, coc)); r.ma != http.StatusOK {
		t.Fatalf("PL03 lưu ghim phải 200, nhận %d %s", r.ma, catBot(r.than))
	}
	nhanh, ghim := docPhanLoaiNhanh(t, h, thuNgan)
	if !reflect.DeepEqual(nhanh, []uint{coc, thuKhac, lai, thue, khauHao, nuoc, ship}) || !reflect.DeepEqual(ghim, []uint{coc, thue}) {
		t.Fatalf("PL03 ghim phải đứng đầu, id hỏng bị bỏ: nhanh %v ghim %v", nhanh, ghim)
	}

	// PL04 tối đa 6 viên mỗi vế dù ghim nhiều.
	if r := dat(fmt.Sprintf("%d,%d,%d,%d,%d,%d,%d", luong, thue, phiTT, sua, ung, khauHao, ship)); r.ma != http.StatusOK {
		t.Fatalf("PL04 lưu 7 ghim phải 200, nhận %d", r.ma)
	}
	if nhanh, _ := docPhanLoaiNhanh(t, h, thuNgan); !reflect.DeepEqual(nhanh[3:], []uint{luong, thue, phiTT, sua, ung, khauHao}) {
		t.Fatalf("PL04 vế chi phải dừng ở 6 viên ghim: %v", nhanh)
	}

	// PL05 ô cài đặt chỉ nhận danh sách số, không trùng, tối đa 12.
	for _, c := range []struct{ v, cau string }{
		{"1,abc", "chỉ gồm mã số"}, {"5,5", "hai lần"}, {"1,2,3,4,5,6,7,8,9,10,11,12,13", "tối đa 12"},
	} {
		if r := dat(c.v); r.ma != http.StatusUnprocessableEntity || !strings.Contains(r.than, c.cau) {
			t.Fatalf("PL05 %q phải 422 %q, nhận %d %s", c.v, c.cau, r.ma, catBot(r.than))
		}
	}
	// PL06 xoá ghim (chuỗi rỗng) → về hay dùng + mặc định.
	if r := dat(""); r.ma != http.StatusOK {
		t.Fatalf("PL06 xoá ghim phải 200, nhận %d", r.ma)
	}
	if r := h.goi(t, thuNgan, http.MethodGet, duongTCQuay+"/danh-muc", nil); !strings.Contains(r.than, `"phan_loai_ghim":[]`) {
		t.Fatalf("PL06 chưa ghim thì phan_loai_ghim là mảng rỗng, không null: %s", catBot(r.than))
	}
	if nhanh, ghim := docPhanLoaiNhanh(t, h, thuNgan); len(ghim) != 0 || nhanh[3] != khauHao {
		t.Fatalf("PL06 bỏ ghim thì về thứ tự hay dùng: nhanh %v ghim %v", nhanh, ghim)
	}

	// PL07 danh sách gieo sẵn lúc mở cửa hàng (cửa hàng b, chưa có phiếu): chỉ 3 loại
	// chi hợp quầy → dãy chi dừng ở 3, không lấp bằng lương / thuê / điện nước.
	ctxB := tenant.WithID(context.Background(), b.id)
	h.db.WithContext(ctxThoat()).Exec("DELETE FROM income_expense_types WHERE tenant_id = ?", b.id)
	var hop []uint
	for _, l := range []domain.LoaiThuChi{
		{Type: domain.LoaiThu, Name: "Các khoản thu khác"},
		{Type: domain.LoaiChi, Name: "Lương, thưởng, phụ cấp, bảo hiểm cho người lao động"},
		{Type: domain.LoaiChi, Name: "Thuê mặt bằng, thuê kho, thuê quầy, thuê thiết bị"},
		{Type: domain.LoaiChi, Name: "Điện, nước, Internet, điện thoại, viễn thông"},
		{Type: domain.LoaiChi, Name: "Phí thanh toán, phí ngân hàng, phí cổng thanh toán"},
		{Type: domain.LoaiChi, Name: "Marketing, quảng cáo, khuyến mại, chăm sóc khách hàng"},
		{Type: domain.LoaiChi, Name: "Sửa chữa, bảo dưỡng"},
		{Type: domain.LoaiChi, Name: "Phần mềm, dịch vụ thuê ngoài, tư vấn, quản trị"},
		{Type: domain.LoaiChi, Name: "Khấu hao tài sản cố định, công cụ dụng cụ"},
		{Type: domain.LoaiChi, Name: "Công tác phí, hội nghị, đào tạo"},
		{Type: domain.LoaiChi, Name: "Thuế, phí, lệ phí được phép tính vào chi phí"},
		{Type: domain.LoaiChi, Name: "Vận chuyển, giao hàng, phí đối tác giao hàng"},
	} {
		if err := h.db.WithContext(ctxB).Create(&l).Error; err != nil {
			t.Fatal(err)
		}
		hop = append(hop, l.ID)
	}
	// thu khác · vận chuyển · sửa chữa · marketing (theo thứ tự từ khoá việc quầy).
	if nhanh, _ := docPhanLoaiNhanh(t, h, h.dangNhapVoi(t, b.ma, "nhanvien")); !reflect.DeepEqual(nhanh, []uint{hop[0], hop[11], hop[6], hop[5]}) {
		t.Fatalf("PL07 danh sách gieo sẵn: dãy phải là thu khác, vận chuyển, sửa chữa, marketing — nhận %v (đủ: %v)", nhanh, hop)
	}
}
