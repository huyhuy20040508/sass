package mailer

import (
	"strings"
	"testing"
)

func sampleOrder() OrderData {
	return OrderData{
		StoreName: "Jersey House", StoreURL: "https://jerseyhouse.vn",
		Hotline: "0796666468", Year: 2026,
		OrderCode: "DH202607270042", PlacedAt: "14:05 27/07/2026",
		Name: "Nguyễn Văn A", Phone: "0912345678",
		Address:       "22 Đồng Nai, Phường Hoà Cường, Đà Nẵng",
		PaymentMethod: "Thanh toán khi nhận hàng (COD)",
		Note:          "Giao giờ hành chính",
		Items: []OrderItemData{
			{Name: "Áo MU sân nhà 2026", Options: "Size M / Đỏ", Custom: "RASHFORD 10", Qty: 2, Price: 750000, Total: 1500000},
			{Name: "Tất thi đấu", Qty: 1, Price: 90000, Total: 90000},
		},
		Subtotal: 1590000, ShippingFee: 0, Total: 1590000,
	}
}

func TestFormatVND(t *testing.T) {
	cases := map[float64]string{
		0: "0₫", 999: "999₫", 1000: "1.000₫",
		90000: "90.000₫", 1590000: "1.590.000₫", 12345678: "12.345.678₫",
		-5: "0₫", // số âm không bao giờ hiện ra tiền âm trong thư
	}
	for in, want := range cases {
		if got := FormatVND(in); got != want {
			t.Errorf("FormatVND(%v) = %q, mong đợi %q", in, got, want)
		}
	}
}

// Thư xác nhận phải có đủ những gì khách cần đối chiếu: mã đơn, từng dòng hàng,
// tổng tiền và địa chỉ nhận. Thiếu một trong số đó là thư vô dụng.
func TestRenderOrderConfirmation(t *testing.T) {
	html, text, err := RenderOrderConfirmation(sampleOrder())
	if err != nil {
		t.Fatalf("render lỗi: %v", err)
	}

	for _, want := range []string{
		"DH202607270042", "14:05 27/07/2026", "Nguyễn Văn A", "0912345678",
		"22 Đồng Nai", "Thanh toán khi nhận hàng (COD)", "Giao giờ hành chính",
		"Áo MU sân nhà 2026", "Size M / Đỏ", "RASHFORD 10", "Tất thi đấu",
		"1.500.000₫", "90.000₫", "1.590.000₫", "Miễn phí",
	} {
		if !strings.Contains(html, want) {
			t.Errorf("bản HTML thiếu %q", want)
		}
		if !strings.Contains(text, want) {
			t.Errorf("bản text thiếu %q", want)
		}
	}
}

// Phí ship > 0 phải hiện số tiền chứ không phải chữ "Miễn phí".
func TestShippingTextCoPhi(t *testing.T) {
	d := sampleOrder()
	d.ShippingFee = 30000
	d.Total = 1620000

	if got := d.ShippingText(); got != "30.000₫" {
		t.Errorf("ShippingText = %q, mong đợi 30.000₫", got)
	}
	html, _, err := RenderOrderConfirmation(d)
	if err != nil {
		t.Fatal(err)
	}
	if strings.Contains(html, "Miễn phí") {
		t.Error("đơn có phí ship vẫn hiện 'Miễn phí'")
	}
}

// Đơn không có ghi chú / không in áo thì không được để lại nhãn trống trong thư.
func TestRenderOrderBoQuaTruongRong(t *testing.T) {
	d := sampleOrder()
	d.Note = ""
	d.Items = []OrderItemData{{Name: "Tất thi đấu", Qty: 1, Price: 90000, Total: 90000}}

	html, text, err := RenderOrderConfirmation(d)
	if err != nil {
		t.Fatal(err)
	}
	for _, bad := range []string{"Ghi chú:", "In áo:"} {
		if strings.Contains(html, bad) {
			t.Errorf("bản HTML còn nhãn thừa %q", bad)
		}
		if strings.Contains(text, bad) {
			t.Errorf("bản text còn nhãn thừa %q", bad)
		}
	}
}

// Đơn chuyển khoản: thư phải in đủ số tài khoản và nội dung cần ghi — cả bản HTML
// lẫn bản chữ thuần, vì khách đọc thư bằng máy chặn HTML mà chỉ thấy dòng "Thanh
// toán: Chuyển khoản ngân hàng" thì coi như không nhận được hướng dẫn nào.
func TestRenderOrderChuyenKhoan(t *testing.T) {
	d := sampleOrder()
	d.PaymentMethod = "Chuyển khoản ngân hàng"
	d.BankName = "Vietcombank — CN Tân Bình"
	d.BankAccountNumber = "1023456789"
	d.BankAccountName = "NGUYEN VAN HUY"
	d.BankTransferNote = "Chuyển xong nhắn Zalo giúp shop."

	html, text, err := RenderOrderConfirmation(d)
	if err != nil {
		t.Fatalf("render lỗi: %v", err)
	}
	for _, want := range []string{
		"Vietcombank", "1023456789", "NGUYEN VAN HUY",
		"Chuyển xong nhắn Zalo giúp shop.",
		// Nội dung chuyển khoản chính là mã đơn — thứ duy nhất đối soát được.
		"DH202607270042",
	} {
		if !strings.Contains(html, want) {
			t.Errorf("bản HTML thiếu %q", want)
		}
		if !strings.Contains(text, want) {
			t.Errorf("bản text thiếu %q", want)
		}
	}
}

// Đơn COD tuyệt đối không được kèm số tài khoản: khách sẽ phân vân không biết có
// phải chuyển tiền trước hay không.
func TestRenderOrderCODKhongCoTaiKhoan(t *testing.T) {
	html, text, err := RenderOrderConfirmation(sampleOrder())
	if err != nil {
		t.Fatalf("render lỗi: %v", err)
	}
	for _, bad := range []string{"Số tài khoản", "CHUYỂN KHOẢN"} {
		if strings.Contains(html, bad) {
			t.Errorf("thư COD (HTML) không được chứa %q", bad)
		}
		if strings.Contains(text, bad) {
			t.Errorf("thư COD (text) không được chứa %q", bad)
		}
	}
}

// Giảm giá chỉ hiện khi có; đơn khách tự đặt luôn là 0 nên không được để dòng thừa.
func TestRenderOrderGiamGia(t *testing.T) {
	d := sampleOrder()
	html, text, err := RenderOrderConfirmation(d)
	if err != nil {
		t.Fatal(err)
	}
	if strings.Contains(html, "Giảm giá") || strings.Contains(text, "Giảm giá") {
		t.Error("đơn không giảm giá vẫn hiện dòng giảm giá")
	}

	d.Discount = 50000
	d.Total = 1540000
	html, text, err = RenderOrderConfirmation(d)
	if err != nil {
		t.Fatal(err)
	}
	for _, want := range []string{"Giảm giá", "-50.000₫"} {
		if !strings.Contains(html, want) {
			t.Errorf("bản HTML thiếu %q", want)
		}
		if !strings.Contains(text, want) {
			t.Errorf("bản text thiếu %q", want)
		}
	}
}

func sampleStatus() OrderStatusData {
	return OrderStatusData{
		StoreName: "Jersey House", StoreURL: "https://jerseyhouse.vn",
		Hotline: "0796666468", Year: 2026,
		OrderCode: "DH202607270042", Name: "Nguyễn Văn A",
		Address:  "22 Đồng Nai, Phường Hoà Cường, Đà Nẵng",
		Label:    "Đang giao hàng",
		Headline: "Đơn hàng đang trên đường tới bạn",
		Detail:   "Đơn đã được bàn giao cho đơn vị vận chuyển.",
		Total:    1590000,
	}
}

// Thư cập nhật phải nói rõ đơn nào, trạng thái gì, giao về đâu.
func TestRenderOrderStatus(t *testing.T) {
	d := sampleStatus()
	d.ShippingMethod = "Giao hàng nhanh"
	d.TrackingNumber = "GHN123456"

	html, text, err := RenderOrderStatus(d)
	if err != nil {
		t.Fatalf("render lỗi: %v", err)
	}
	for _, want := range []string{
		"DH202607270042", "Đang giao hàng", "Nguyễn Văn A", "22 Đồng Nai",
		"1.590.000₫", "Giao hàng nhanh", "GHN123456",
	} {
		if !strings.Contains(html, want) {
			t.Errorf("bản HTML thiếu %q", want)
		}
		if !strings.Contains(text, want) {
			t.Errorf("bản text thiếu %q", want)
		}
	}
}

// Không có thông tin vận chuyển / lý do thì không được để nhãn trống.
func TestRenderOrderStatusBoQuaTruongRong(t *testing.T) {
	html, text, err := RenderOrderStatus(sampleStatus())
	if err != nil {
		t.Fatal(err)
	}
	for _, bad := range []string{"Vận chuyển:", "Lý do:"} {
		if strings.Contains(html, bad) {
			t.Errorf("bản HTML còn nhãn thừa %q", bad)
		}
		if strings.Contains(text, bad) {
			t.Errorf("bản text còn nhãn thừa %q", bad)
		}
	}
}

// Đơn huỷ dùng dải đỏ và phải nêu lý do huỷ.
func TestRenderOrderStatusHuyDon(t *testing.T) {
	d := sampleStatus()
	d.Label = "Đã huỷ"
	d.Headline = "Đơn hàng đã được huỷ"
	d.Danger = true
	d.Reason = "Khách đổi ý"

	if d.BarColor() != "#d0021b" {
		t.Errorf("đơn huỷ dùng màu %s, mong đợi đỏ", d.BarColor())
	}
	html, text, err := RenderOrderStatus(d)
	if err != nil {
		t.Fatal(err)
	}
	for _, want := range []string{"#d0021b", "Đã huỷ", "Khách đổi ý"} {
		if !strings.Contains(html, want) {
			t.Errorf("bản HTML thiếu %q", want)
		}
	}
	if !strings.Contains(text, "Khách đổi ý") {
		t.Error("bản text thiếu lý do huỷ")
	}
}

// Link localhost bị bỏ khỏi thư đơn hàng y như thư xác thực — xem ShowLink.
func TestOrderShowLinkChanLocalhost(t *testing.T) {
	d := sampleOrder()
	d.StoreURL = "http://localhost:8000"

	html, text, err := RenderOrderConfirmation(d)
	if err != nil {
		t.Fatal(err)
	}
	if strings.Contains(html, "localhost") || strings.Contains(text, "localhost") {
		t.Error("thư xác nhận vẫn còn link localhost")
	}

	sd := sampleStatus()
	sd.StoreURL = "http://localhost:8000"
	html, text, err = RenderOrderStatus(sd)
	if err != nil {
		t.Fatal(err)
	}
	if strings.Contains(html, "localhost") || strings.Contains(text, "localhost") {
		t.Error("thư cập nhật trạng thái vẫn còn link localhost")
	}
}
