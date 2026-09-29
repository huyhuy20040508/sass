package apitest

import (
	"encoding/json"
	"fmt"
	"net/http"
	"strconv"
	"strings"
	"testing"
	"time"
)

// TestCRMKhuyenMai_MaVaThuTrongTuan — màn CRM → Chương trình khuyến mãi: mã do
// HỆ THỐNG cấp nối tiếp (KM00001…, không nhận mã gõ tay, sửa không đổi mã), thứ
// trong tuần lưu và đọc lại đúng, và hai ô lọc mới (bật/tắt, tìm theo mã).
func TestCRMKhuyenMai_MaVaThuTrongTuan(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	homNay := time.Now()

	than := func(ten string, thu []int, bat bool) map[string]any {
		return map[string]any{
			// Gửi kèm mã tay: phải bị bỏ qua.
			"code": "GOTAY", "name": "KM " + a.vet + " " + ten,
			"discount_type": "percentage", "discount_value": 10,
			"start_at":     homNay.AddDate(0, 0, -1).Format("2006-01-02T15:04"),
			"end_at":       homNay.AddDate(0, 0, 7).Format("2006-01-02T15:04"),
			"days_of_week": thu, "is_active": bat,
			"product_ids": []uint{a.sanPham},
		}
	}
	type kmThu struct {
		ID         uint   `json:"id"`
		Code       string `json:"code"`
		DaysOfWeek []int  `json:"days_of_week"`
		IsActive   bool   `json:"is_active"`
	}
	doc := func(res traLoi) kmThu {
		var out struct {
			Data kmThu `json:"data"`
		}
		_ = json.Unmarshal([]byte(res.than), &out)

		return out.Data
	}
	so := func(ma string) int {
		n, _ := strconv.Atoi(strings.TrimPrefix(ma, "KM"))
		return n
	}

	tao := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/promotions", than("mot", []int{5, 1, 3, 3}, true))
	if tao.ma != http.StatusCreated {
		t.Fatalf("tạo khuyến mãi trả %d\n%s", tao.ma, catBot(tao.than))
	}
	km := doc(tao)
	if !strings.HasPrefix(km.Code, "KM") || km.Code == "GOTAY" || fmt.Sprint(km.DaysOfWeek) != "[1 3 5]" {
		t.Fatalf("mã phải do hệ thống cấp (KM…) và thứ lưu đúng (đã sắp, bỏ trùng), đang là %q %v", km.Code, km.DaysOfWeek)
	}

	tuCap := doc(h.goi(t, a.token, http.MethodPost, "/api/v1/admin/promotions", than("hai", []int{1, 2, 3, 4, 5, 6, 7}, false)))
	if so(tuCap.Code) != so(km.Code)+1 {
		t.Fatalf("mã phải nối tiếp: sau %q là KM%05d, đang là %q", km.Code, so(km.Code)+1, tuCap.Code)
	}
	if len(tuCap.DaysOfWeek) != 0 {
		t.Fatalf("chọn đủ bảy thứ = mọi ngày, phải lưu rỗng, đang là %v", tuCap.DaysOfWeek)
	}

	// Sửa không đổi mã, kể cả khi gửi kèm mã khác.
	if res := h.goi(t, a.token, http.MethodPut, fmt.Sprintf("/api/v1/admin/promotions/%d", km.ID), than("mot", []int{7}, true)); doc(res).Code != km.Code {
		t.Fatalf("sửa phải giữ mã %q, trả %d\n%s", km.Code, res.ma, catBot(res.than))
	}

	dem := func(q string) int {
		res := h.goi(t, a.token, http.MethodGet, "/api/v1/admin/promotions?page_size=100&"+q, nil)
		var out struct {
			Data []kmThu `json:"data"`
		}
		_ = json.Unmarshal([]byte(res.than), &out)
		n := 0
		for _, d := range out.Data {
			if d.ID == km.ID || d.ID == tuCap.ID {
				n++
			}
		}

		return n
	}
	if n := dem("keyword=" + km.Code); n != 1 {
		t.Fatalf("tìm theo mã phải ra đúng 1 chương trình, ra %d", n)
	}
	if n := dem("active=0"); n != 1 {
		t.Fatalf("lọc 'không hoạt động' phải ra đúng chương trình đang tắt, ra %d", n)
	}
	if n := dem(fmt.Sprintf("shop_ids=%d", a.chiNhanh)); n != 2 {
		t.Fatalf("chương trình không gán chi nhánh phải khớp mọi chi nhánh, ra %d", n)
	}
}
