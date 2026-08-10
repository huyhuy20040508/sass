package payos

import (
	"context"
	"os"
	"testing"
	"time"

	"sass-api/config"
)

// Kiểm thử THẬT: gọi sang PayOS bằng bộ khoá trong .env, tạo một link rồi huỷ ngay.
// Chỉ chạy khi được gọi đích danh (go test -run TestLivePayOS -tags= ...).
func TestLivePayOS(t *testing.T) {
	if os.Getenv("PAYOS_LIVE") == "" {
		t.Skip("bỏ qua: đặt PAYOS_LIVE=1 để chạy kiểm thử gọi thật")
	}
	// config.Load tìm .env ở thư mục hiện hành, mà `go test` chạy trong thư mục của
	// gói. Trả lại chỗ cũ sau khi xong, không thì các test khác trong cùng gói chạy
	// tiếp ở sai thư mục.
	cwd, err := os.Getwd()
	if err != nil {
		t.Fatalf("getwd: %v", err)
	}
	t.Cleanup(func() { _ = os.Chdir(cwd) })
	if err := os.Chdir("../.."); err != nil {
		t.Fatalf("chdir: %v", err)
	}
	cfg, err := config.Load()
	if err != nil {
		t.Fatalf("nạp cấu hình: %v", err)
	}
	c := New(cfg.PayOS)
	if !c.Enabled() {
		t.Fatal("chưa khai đủ khoá PayOS")
	}

	code := time.Now().UnixMilli() % 1_000_000_000
	ctx := context.Background()

	link, err := c.CreateLink(ctx, CreateRequest{
		OrderCode:   code,
		Amount:      2000,
		Description: "KIEM THU KET NOI",
		BuyerName:   "Nguyễn Văn Test",
		Items:       []Item{{Name: "Áo đấu kiểm thử", Quantity: 1, Price: 2000}},
		CancelURL:   cfg.PayOS.CancelURL,
		ReturnURL:   cfg.PayOS.ReturnURL,
		ExpiredAt:   time.Now().Add(10 * time.Minute).Unix(),
	})
	if err != nil {
		t.Fatalf("CreateLink lỗi: %v", err)
	}
	t.Logf("OK tạo link: orderCode=%d status=%s checkoutUrl=%s qrCode(%d ký tự)",
		link.OrderCode, link.Status, link.CheckoutURL, len(link.QRCode))

	info, err := c.GetLink(ctx, code)
	if err != nil {
		t.Fatalf("GetLink lỗi: %v", err)
	}
	t.Logf("OK tra link: status=%s amount=%d amountPaid=%d", info.Status, info.Amount, info.AmountPaid)

	cancelled, err := c.CancelLink(ctx, code, "Kiểm thử kết nối")
	if err != nil {
		t.Fatalf("CancelLink lỗi: %v", err)
	}
	t.Logf("OK huỷ link: status=%s", cancelled.Status)
	if !cancelled.Closed() {
		t.Fatalf("huỷ xong mà trạng thái vẫn là %s", cancelled.Status)
	}
}
