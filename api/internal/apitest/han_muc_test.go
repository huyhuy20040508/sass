package apitest

import (
	"context"
	"fmt"
	"net/http"
	"testing"

	"sass-api/internal/domain"
)

// Bài kiểm HẠN MỨC HỢP ĐỒNG chạy qua CẢ HAI database: trần đọc ở sổ nền tảng
// (control plane), số đang dùng đếm ở dữ liệu cửa hàng (data plane).
//
// Đây là thứ mà bài kiểm ở tầng service không chứng minh được: bên đó dùng sổ
// giả nên nó chỉ nói phép so sánh viết đúng. Còn câu hỏi thật là hai kết nối có
// gặp nhau đúng chỗ không — service của data plane có thật sự đọc được hợp đồng
// của đúng cửa hàng đang gọi hay không.
//
// Dùng bản dựng CÓ CONTROL PLANE, cụm bán hàng cho khách vẫn tắt như production.

// gieoHopDong ghi một hợp đồng cho cửa hàng trong sổ nền tảng.
//
// Ghi thẳng bằng SQL chứ không qua đường ký hợp đồng của khu điều hành: bài kiểm
// này nói về CHỖ ÉP hạn mức, và đi vòng qua ba màn hình nữa chỉ làm nó hỏng vì
// những lý do chẳng liên quan.
func gieoHopDong(t *testing.T, h *heThong, c *cuaHang, chiNhanh, taiKhoan, sanPham uint) {
	t.Helper()
	if h.nenTang == nil {
		t.Fatal("gieoHopDong cần bản dựng có control plane — dùng dungHeThongDieuHanh")
	}

	nen := context.Background()
	// Khách phải có mặt trong sổ trước: `subscriptions.tenant_id` trỏ về đây, và
	// id là số chung với data plane nên chép thẳng sang.
	err := h.nenTang.WithContext(nen).Exec(
		`INSERT INTO tenants (id, code, name, status, created_at, updated_at)
		 VALUES (?, ?, ?, ?, NOW(3), NOW(3))
		 ON DUPLICATE KEY UPDATE status = VALUES(status), name = VALUES(name)`,
		c.id, c.ma, "Cửa hàng "+c.vet, domain.TenantActive).Error
	if err != nil {
		t.Fatalf("không ghi được cửa hàng %s vào sổ nền tảng: %v", c.ma, err)
	}

	err = h.nenTang.WithContext(nen).Exec(
		`INSERT INTO subscriptions
		   (tenant_id, app_id, plan, status, billing_cycle, price,
		    max_shops, max_users, max_products, own_domain,
		    started_at, ends_at, created_at, updated_at)
		 SELECT ?, a.id, ?, ?, ?, 0, ?, ?, ?, 0,
		        NOW(3), DATE_ADD(NOW(3), INTERVAL 30 DAY), NOW(3), NOW(3)
		 FROM apps a WHERE a.code = ?`,
		c.id, domain.PlanCuaHang, domain.SubscriptionActive, domain.CycleThang,
		chiNhanh, taiKhoan, sanPham, domain.AppOrder).Error
	if err != nil {
		t.Fatalf("không ghi được hợp đồng cho %s: %v", c.ma, err)
	}
}

// TestHanMucHopDongChanKhiHetCho — ba hạn mức đã ký phải chặn được lượt tạo thứ
// N+1, ở cả ba loại.
//
// Trước bản này chúng chỉ là chữ in trên màn hình: hợp đồng ghi "1 chi nhánh, 20
// sản phẩm" mà không chỗ nào từ chối cái thứ hai, nên ba gói giá khác nhau cho
// ra đúng một phần mềm giống hệt nhau.
func TestHanMucHopDongChanKhiHetCho(t *testing.T) {
	h := dungHeThongDieuHanh(t)
	a, _ := haiCuaHang(t, h)

	// Gieo cửa hàng đã có sẵn: 1 chi nhánh, 3 tài khoản nội bộ (quản trị + nhân
	// viên = 2), 1 sản phẩm. Hợp đồng chốt đúng bằng số đang dùng ở hai đầu để
	// lượt tạo tiếp theo chạm trần.
	gieoHopDong(t, h, a, 1, 2, 1)

	cases := []struct {
		ten   string
		duong string
		than  map[string]any
	}{
		{
			"chi nhánh thứ hai của gói một chi nhánh",
			"/api/v1/admin/chi-nhanh",
			map[string]any{"name": "Kho phụ " + a.vet},
		},
		{
			"tài khoản thứ ba của gói hai tài khoản",
			"/api/v1/admin/users",
			map[string]any{
				"full_name": "Nhân viên mới " + a.vet, "username": "nhanvien2",
				"email": "nv2@" + a.vet + ".test", "role_id": 3, "status": "active",
			},
		},
		{
			"sản phẩm thứ hai của gói một sản phẩm",
			"/api/v1/admin/products",
			map[string]any{
				"name": "Sản phẩm mới " + a.vet, "slug": "sp-moi-" + a.vet,
				"sku": "sku-moi-" + a.vet, "category_id": a.danhMuc, "base_price": 100000,
			},
		},
	}

	for _, x := range cases {
		t.Run(x.ten, func(t *testing.T) {
			res := h.goi(t, a.token, http.MethodPost, x.duong, x.than)
			if res.ma != http.StatusConflict {
				t.Fatalf("hết hạn mức mà vẫn tạo được: %s trả %d\n%s",
					x.duong, res.ma, catBot(res.than))
			}
			// Câu trả lời phải nói ra con số, không phải "không tạo được": người đọc
			// cần biết trần là bao nhiêu mới quyết được nên dọn bớt hay nâng gói.
			if !chuaDauVet(res.than, "hết chỗ") {
				t.Fatalf("thông báo không nói rõ là hết hạn mức gói:\n%s", catBot(res.than))
			}
		})
	}

	// Nhân bản sản phẩm cũng ăn một chỗ — đường dễ quên nhất, vì nó không đi qua
	// lượt tạo ở trên.
	t.Run("nhân bản sản phẩm cũng bị chặn", func(t *testing.T) {
		res := h.goi(t, a.token, http.MethodPost,
			fmt.Sprintf("/api/v1/admin/products/%d/duplicate", a.sanPham), nil)
		if res.ma != http.StatusConflict {
			t.Fatalf("hết hạn mức mà vẫn nhân bản được: trả %d\n%s", res.ma, catBot(res.than))
		}
	})
}

// TestHanMucHopDongConChoThiVanTao — chốt chặn trên không được chặn oan.
//
// Đối chứng bắt buộc: một bản vá làm mọi lượt tạo trả 409 cũng qua được bài kiểm
// bên trên, và nó sẽ khoá cứng phần mềm của mọi khách hàng.
func TestHanMucHopDongConChoThiVanTao(t *testing.T) {
	h := dungHeThongDieuHanh(t)
	a, _ := haiCuaHang(t, h)

	// Gói rộng rãi: 5 chi nhánh, 10 tài khoản, và sản phẩm KHÔNG GIỚI HẠN (0 =
	// bản dịch của 'vo_han' bên bảng giá).
	gieoHopDong(t, h, a, 5, 10, 0)

	res := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/chi-nhanh",
		map[string]any{"name": "Kho phụ " + a.vet})
	if res.ma != http.StatusCreated {
		t.Fatalf("gói 5 chi nhánh mà mở cái thứ hai vẫn bị chặn: %d\n%s", res.ma, catBot(res.than))
	}

	res = h.goi(t, a.token, http.MethodPost, "/api/v1/admin/products", map[string]any{
		"name": "Sản phẩm mới " + a.vet, "slug": "sp-moi-" + a.vet,
		"sku": "sku-moi-" + a.vet, "category_id": a.danhMuc, "base_price": 100000,
	})
	if res.ma != http.StatusCreated {
		t.Fatalf("gói không giới hạn sản phẩm mà vẫn bị chặn: %d\n%s", res.ma, catBot(res.than))
	}
}

// TestHanMucChuaCoHopDongThiKhongEp — cửa hàng dựng tay trước khi có sổ hợp đồng
// vẫn phải dùng được bình thường.
//
// Không hợp đồng thì không có điều khoản nào để ép, và đó là trạng thái HỢP LỆ.
// Đây cũng là tình huống của mọi cửa hàng đang chạy hôm nay chưa được vào sổ —
// chặn nhầm ở đây là khoá cứng phần mềm của họ.
func TestHanMucChuaCoHopDongThiKhongEp(t *testing.T) {
	h := dungHeThongDieuHanh(t)
	a, _ := haiCuaHang(t, h)

	res := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/chi-nhanh",
		map[string]any{"name": "Kho phụ " + a.vet})
	if res.ma != http.StatusCreated {
		t.Fatalf("chưa có hợp đồng mà đã chặn mở chi nhánh: %d\n%s", res.ma, catBot(res.than))
	}
}
