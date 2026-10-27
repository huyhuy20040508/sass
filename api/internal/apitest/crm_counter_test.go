package apitest

import (
	"encoding/json"
	"fmt"
	"net/http"
	"testing"
	"time"
)

// Bài kiểm CRM Ở QUẦY — các đường /admin/orders/pos/khach-hang/* dùng CHÍNH handler
// của Quản trị → Khách hàng, nên dữ liệu và luật phải y hệt: thu ngân thêm khách là
// khách đó hiện ở Quản trị, sửa thì vẫn theo quyền `khach-hang.sua` của Quản trị,
// và sổ khách đầy đủ (/admin/customers) vẫn đóng với họ.

func TestCRMQuay_ThemXemSuaCungDuLieuQuanTri(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	thuNgan := h.dangNhapVoi(t, a.ma, "nhanvien")

	res := h.goi(t, thuNgan, http.MethodPost, "/api/v1/admin/orders/pos/khach-hang/day-du", map[string]any{
		"full_name": "Khách quầy " + a.vet, "phone": "0909 123 456", "status": "active",
		"customer_type": 0, "gender": "female", "date_of_birth": "1994-10-22",
		"address": "12 Trần Thái Tông", "customer_note": "Thích màu xanh",
	})
	if res.ma != http.StatusCreated {
		t.Fatalf("thu ngân thêm khách đủ ô phải 201, nhận %d\n%s", res.ma, catBot(res.than))
	}
	id := uint(doc(t, res)["id"].(float64))

	// Cùng một hồ sơ ở Quản trị — không phải bản sao của quầy.
	qt := doc(t, h.goi(t, a.token, http.MethodGet, fmt.Sprintf("/api/v1/admin/customers/%d", id), nil))
	if qt["customer_note"] != "Thích màu xanh" || qt["gender"] != "female" || qt["customer_code"] == "" {
		t.Fatalf("Quản trị phải thấy đúng hồ sơ thu ngân vừa thêm (ghi chú, giới tính, mã tự sinh), nhận %v", qt)
	}

	xem := h.goi(t, thuNgan, http.MethodGet, fmt.Sprintf("/api/v1/admin/orders/pos/khach-hang/%d", id), nil)
	if xem.ma != http.StatusOK || doc(t, xem)["full_name"] != "Khách quầy "+a.vet {
		t.Fatalf("thu ngân xem một khách phải 200, nhận %d\n%s", xem.ma, catBot(xem.than))
	}

	sua := map[string]any{"full_name": "Khách quầy đã sửa " + a.vet, "phone": "0909123456", "status": "active", "customer_type": 0, "customer_note": "Đổi ghi chú"}
	duong := fmt.Sprintf("/api/v1/admin/orders/pos/khach-hang/%d", id)
	if r := h.goi(t, thuNgan, http.MethodPut, duong, sua); r.ma != http.StatusForbidden {
		t.Fatalf("chưa có khach-hang.sua thì sửa ở quầy phải 403, nhận %d", r.ma)
	}
	giaoQuyen(t, h, a, "nhanvien", "khach-hang.sua")
	thuNgan = h.dangNhapVoi(t, a.ma, "nhanvien")
	if r := h.goi(t, thuNgan, http.MethodPut, duong, sua); r.ma != http.StatusOK {
		t.Fatalf("có khach-hang.sua thì sửa ở quầy phải 200, nhận %d\n%s", r.ma, catBot(r.than))
	}
	if ten := doc(t, h.goi(t, a.token, http.MethodGet, fmt.Sprintf("/api/v1/admin/customers/%d", id), nil))["full_name"]; ten != "Khách quầy đã sửa "+a.vet {
		t.Fatalf("Quản trị phải thấy tên vừa sửa ở quầy, nhận %v", ten)
	}

	// Sổ khách đầy đủ và nhóm khách của Quản trị vẫn đóng; bản đọc nhóm ở quầy mở.
	if r := h.goi(t, thuNgan, http.MethodGet, "/api/v1/admin/customers", nil); r.ma != http.StatusForbidden {
		t.Fatalf("/admin/customers vẫn phải đóng với thu ngân, nhận %d", r.ma)
	}
	if r := h.goi(t, thuNgan, http.MethodGet, "/api/v1/admin/orders/pos/nhom-khach?type=0", nil); r.ma != http.StatusOK {
		t.Fatalf("thu ngân đọc nhóm khách phải 200, nhận %d", r.ma)
	}
}

// TestCRMQuay_LocCungBoLocQuanTri — nút lọc nhanh ở quầy là bộ lọc của danh sách
// Quản trị (sinh nhật N ngày tới, giao dịch cuối, xếp theo nợ) đi qua đường quầy.
func TestCRMQuay_LocCungBoLocQuanTri(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	thuNgan := h.dangNhapVoi(t, a.ma, "nhanvien")

	sn := time.Now().AddDate(-30, 0, 2).Format("2006-01-02")
	for _, k := range []map[string]any{
		{"full_name": "Sắp sinh nhật " + a.vet, "status": "active", "customer_type": 0, "date_of_birth": sn},
		{"full_name": "Không sinh nhật " + a.vet, "status": "active", "customer_type": 0, "date_of_birth": time.Now().AddDate(-30, 3, 0).Format("2006-01-02")},
		{"full_name": "Doanh nghiệp " + a.vet, "status": "active", "customer_type": 1, "tax_code": "0101234567"},
	} {
		if r := h.goi(t, thuNgan, http.MethodPost, "/api/v1/admin/orders/pos/khach-hang/day-du", k); r.ma != http.StatusCreated {
			t.Fatalf("gieo khách %v trả %d\n%s", k["full_name"], r.ma, catBot(r.than))
		}
	}

	doc := func(q string) []string {
		r := h.goi(t, thuNgan, http.MethodGet, "/api/v1/admin/orders/pos/khach-hang?keyword="+a.vet+"&"+q, nil)
		var body struct {
			Data []struct {
				FullName string `json:"full_name"`
			} `json:"data"`
		}
		_ = json.Unmarshal([]byte(r.than), &body)
		out := []string{}
		for _, d := range body.Data {
			out = append(out, d.FullName)
		}
		return out
	}
	if ds := doc("birthday_days=7"); len(ds) != 1 || ds[0] != "Sắp sinh nhật "+a.vet {
		t.Fatalf("sinh nhật 7 ngày tới phải ra đúng 1 khách, nhận %v", ds)
	}
	if ds := doc("types=1"); len(ds) != 1 || ds[0] != "Doanh nghiệp "+a.vet {
		t.Fatalf("tab doanh nghiệp phải ra 1 khách, nhận %v", ds)
	}
}
