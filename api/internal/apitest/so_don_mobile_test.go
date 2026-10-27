package apitest

import (
	"context"
	"encoding/json"
	"net/http"
	"testing"
	"time"

	"sass-api/internal/domain"
	"sass-api/internal/tenant"
)

// Sổ chứng từ cho app mobile: trạng thái xử lý, tóm tắt món, số theo tab, tổng nợ.
func TestSoDon_TrangThaiXuLyVaMon(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	ctx := tenant.WithID(context.Background(), a.id)
	now := time.Now()

	type dong struct {
		Loai         string   `json:"loai"`
		ID           uint     `json:"id"`
		TrangThaiDon string   `json:"trang_thai_don"`
		SoMon        int      `json:"so_mon"`
		SoLuong      int      `json:"so_luong"`
		TenMonDau    string   `json:"ten_mon_dau"`
		AnhMon       []string `json:"anh_mon"`
	}
	type ketQua struct {
		Data []dong `json:"data"`
		Meta struct {
			Total int64 `json:"total"`
			Tong  struct {
				ConNo float64          `json:"con_no"`
				Dem   map[string]int64 `json:"dem"`
			} `json:"tong"`
		} `json:"meta"`
	}
	doc := func(q string) ketQua {
		t.Helper()
		res := h.goi(t, a.token, http.MethodGet, "/api/v1/admin/orders/so-don?page_size=100"+q, nil)
		if res.ma != http.StatusOK {
			t.Fatalf("đọc sổ %q trả %d\n%s", q, res.ma, catBot(res.than))
		}
		var out ketQua
		if err := json.Unmarshal([]byte(res.than), &out); err != nil {
			t.Fatal(err)
		}

		return out
	}

	// Cửa hàng mẫu đã có sẵn vài đơn — so CHÊNH LỆCH với mốc trước khi tạo.
	moc := doc("")

	mon := func(ten, anh string, sl int) domain.OrderItem {
		return domain.OrderItem{ProductName: ten, Thumbnail: anh, Quantity: sl, UnitPrice: 10000, TotalPrice: float64(sl) * 10000}
	}
	don := func(ma, trangThai, thanhToan string, tong float64, items ...domain.OrderItem) uint {
		o := &domain.Order{
			ShopID: a.chiNhanh, OrderCode: ma + "-" + a.vet, Channel: domain.OrderChannelPOS,
			SubtotalAmount: tong, TotalAmount: tong,
			PaymentMethod: domain.PaymentMethodCash, PaymentStatus: thanhToan,
			Status: trangThai, PlacedAt: &now, Items: items,
		}
		tao(t, h.db, ctx, o)

		return o.ID
	}
	cho := don("m-cho", domain.OrderStatusPending, domain.OrderPaymentPending, 50000,
		mon("Áo thun", "/uploads/a.jpg", 2), mon("Quần", "", 1), mon("Nón", "/uploads/c.jpg", 1), mon("Giày", "/uploads/d.jpg", 1), mon("Vớ", "/uploads/e.jpg", 3))
	dang := don("m-dang", domain.OrderStatusProcessing, domain.OrderPaymentPaid, 20000, mon("Túi", "/uploads/t.jpg", 1))
	xong := don("m-xong", domain.OrderStatusCompleted, domain.OrderPaymentPaid, 30000, mon("Ví", "", 1))
	huy := don("m-huy", domain.OrderStatusCancelled, domain.OrderPaymentPending, 40000, mon("Kính", "", 1))

	tat := doc("")
	theoID := map[uint]dong{}
	for _, d := range tat.Data {
		if d.Loai == "don" {
			theoID[d.ID] = d
		}
	}
	c := theoID[cho]
	if c.TrangThaiDon != "pending" || c.SoMon != 5 || c.SoLuong != 8 || c.TenMonDau != "Áo thun" {
		t.Errorf("đơn chờ: %+v", c)
	}
	// Ba ảnh đầu, bỏ món không ảnh, theo thứ tự món.
	if want := []string{"/uploads/a.jpg", "/uploads/c.jpg", "/uploads/d.jpg"}; len(c.AnhMon) != 3 || c.AnhMon[0] != want[0] || c.AnhMon[2] != want[2] {
		t.Errorf("ảnh món = %v, mong %v", c.AnhMon, want)
	}
	if x := theoID[xong]; x.AnhMon == nil || len(x.AnhMon) != 0 {
		t.Errorf("đơn không ảnh phải trả mảng rỗng, được %v", x.AnhMon)
	}
	if theoID[huy].TrangThaiDon != "cancelled" || theoID[dang].TrangThaiDon != "processing" {
		t.Errorf("trạng thái xử lý sai: huỷ=%q đang=%q", theoID[huy].TrangThaiDon, theoID[dang].TrangThaiDon)
	}
	dem, dem0 := tat.Meta.Tong.Dem, moc.Meta.Tong.Dem
	for _, nhom := range []string{"pending", "doing", "done", "cancelled"} {
		if dem[nhom]-dem0[nhom] != 1 {
			t.Errorf("tab %s tăng %d, mong 1 (trước %v, sau %v)", nhom, dem[nhom]-dem0[nhom], dem0, dem)
		}
	}
	// Chỉ đơn chờ còn nợ; đơn huỷ không tính nợ.
	if no := tat.Meta.Tong.ConNo - moc.Meta.Tong.ConNo; no != 50000 {
		t.Errorf("tổng nợ tăng %v, mong 50000", no)
	}

	dangLam := doc("&trang_thai_don=doing")
	coDang := false
	for _, d := range dangLam.Data {
		coDang = coDang || d.ID == dang
		if d.TrangThaiDon != "confirmed" && d.TrangThaiDon != "processing" && d.TrangThaiDon != "shipping" {
			t.Errorf("lọc doing lẫn đơn %q", d.TrangThaiDon)
		}
	}
	if !coDang || int64(len(dangLam.Data)) != dem["doing"] {
		t.Errorf("lọc doing: có đơn mới=%v, %d dòng, tab ghi %d", coDang, len(dangLam.Data), dem["doing"])
	}
	// Số theo tab không đổi khi đang đứng ở một tab.
	if dangLam.Meta.Tong.Dem["pending"] != dem["pending"] || dangLam.Meta.Tong.Dem["done"] != dem["done"] {
		t.Errorf("số tab khi lọc doing = %v, mong như %v", dangLam.Meta.Tong.Dem, dem)
	}
	// Tab rỗng vẫn trả đủ số cho các tab khác.
	if r := doc("&trang_thai_don=returned"); r.Meta.Total != dem["returned"] || r.Meta.Tong.Dem["pending"] != dem["pending"] {
		t.Errorf("tab trả hàng: total=%d dem=%v", r.Meta.Total, r.Meta.Tong.Dem)
	}
	if r := doc("&trang_thai_don=khong-co"); r.Meta.Total != 0 {
		t.Errorf("nhóm lạ phải trả 0 dòng, được %d", r.Meta.Total)
	}
}
