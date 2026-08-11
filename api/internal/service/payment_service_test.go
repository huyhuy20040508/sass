package service

import (
	"bytes"
	"encoding/base64"
	"image/png"
	"strings"
	"testing"
	"time"
)

// Ảnh QR phải giải mã được thành PNG thật: client gán thẳng vào <img>, hỏng thì
// khách thấy ô ảnh vỡ ngay giữa bước trả tiền.
func TestQRImageDataURILaPNGHopLe(t *testing.T) {
	// Chuỗi VietQR rút gọn — đủ dài để buộc bộ mã hoá chọn phiên bản lớn.
	const payload = "00020101021238570010A00000072701270006970422011301109990001080208QRIBFTTA53037045802VN62130809DH2026073163044A0B"

	uri := qrImageDataURI(payload)
	const prefix = "data:image/png;base64,"
	if !strings.HasPrefix(uri, prefix) {
		t.Fatalf("thiếu tiền tố data URI, nhận: %.40s", uri)
	}

	raw, err := base64.StdEncoding.DecodeString(strings.TrimPrefix(uri, prefix))
	if err != nil {
		t.Fatalf("base64 hỏng: %v", err)
	}
	img, err := png.Decode(bytes.NewReader(raw))
	if err != nil {
		t.Fatalf("không phải PNG hợp lệ: %v", err)
	}
	if b := img.Bounds(); b.Dx() < 200 || b.Dy() < 200 {
		t.Fatalf("ảnh quá nhỏ để quét: %dx%d", b.Dx(), b.Dy())
	}
}

// Không có chuỗi QR thì trả rỗng chứ không dựng một tấm ảnh trống — client đã có
// đường dự phòng là link sang trang của cổng.
func TestQRImageDataURIRongThiTraRong(t *testing.T) {
	for _, in := range []string{"", "   "} {
		if got := qrImageDataURI(in); got != "" {
			t.Fatalf("đầu vào %q phải cho chuỗi rỗng, nhận %.30s", in, got)
		}
	}
}

// Mã giao dịch gửi sang cổng phải đọc ngược ra được id đơn, và hai đơn khác nhau
// không bao giờ trùng mã — trùng là PayOS từ chối cả lần đặt hàng.
func TestGatewayOrderCodeDoiNguocRaDonVaKhongTrung(t *testing.T) {
	now := time.Date(2026, 7, 31, 22, 20, 35, 951_000_000, time.Local)

	code := gatewayOrderCode(49, now)
	if got := uint(code / 100_000); got != 49 {
		t.Fatalf("không đọc ngược ra id đơn: code=%d suy ra %d", code, got)
	}
	if other := gatewayOrderCode(50, now); other == code {
		t.Fatalf("hai đơn khác nhau cùng lúc lại trùng mã: %d", code)
	}
	// Cùng một đơn, hai thời điểm khác nhau (khách trả lại sau khi link cũ hết hạn).
	if again := gatewayOrderCode(49, now.Add(1500*time.Millisecond)); again == code {
		t.Fatalf("hai lần thử của cùng đơn lại trùng mã: %d", code)
	}
}

// Mô tả gửi sang cổng chính là nội dung chuyển khoản khách thấy trong app ngân
// hàng, và PayOS chặn ở 25 ký tự. Cắt phải theo KÝ TỰ, không theo byte.
func TestTruncateRunesKhongLamHongTiengViet(t *testing.T) {
	got := truncateRunes("Áo đấu Real Madrid sân nhà 2024", 10)
	if []rune(got)[0] != 'Á' {
		t.Fatalf("cắt hỏng ký tự đầu: %q", got)
	}
	if n := len([]rune(got)); n > 10 {
		t.Fatalf("cắt xong vẫn dài %d ký tự: %q", n, got)
	}
	if !utf8Valid(got) {
		t.Fatalf("chuỗi sau khi cắt không còn hợp lệ UTF-8: %q", got)
	}
}

func utf8Valid(s string) bool {
	for _, r := range s {
		if r == '�' {
			return false
		}
	}
	return true
}
