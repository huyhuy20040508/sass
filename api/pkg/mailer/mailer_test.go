package mailer

import (
	"encoding/base64"
	"strings"
	"testing"

	"sass-api/config"
)

func testCfg() config.MailConfig {
	return config.MailConfig{
		Host: "smtp.gmail.com", Port: "587",
		Username: "shop@gmail.com", Password: "x",
		FromName: "Jersey House", FromAddress: "shop@gmail.com",
	}
}

// Thư phải có đủ header bắt buộc; thiếu Message-ID/Date là điểm trừ với bộ lọc spam.
func TestBuildMessageHeaders(t *testing.T) {
	msg, err := buildMessage(testCfg(), "khach@gmail.com", "Nguyễn Văn A",
		"123456 là mã xác thực", "<p>xin chào</p>", "xin chào", "<abc@gmail.com>")
	if err != nil {
		t.Fatalf("buildMessage lỗi: %v", err)
	}
	s := string(msg)

	for _, h := range []string{
		"From: Jersey House <shop@gmail.com>", "Reply-To: shop@gmail.com",
		"Message-ID: <abc@gmail.com>", "Date: ", "MIME-Version: 1.0",
		"Content-Type: multipart/alternative;",
	} {
		if !strings.Contains(s, h) {
			t.Errorf("thiếu header %q", h)
		}
	}

	// Tên người nhận có dấu phải mã hoá RFC 2047 (chữ thô sẽ hiện thành ký tự lỗi)
	if !strings.Contains(s, "To: =?UTF-8?q?") {
		t.Error("tên người nhận có dấu chưa được mã hoá RFC 2047")
	}
	// Tiêu đề tiếng Việt cũng vậy
	if strings.Contains(s, "Subject: 123456 là mã") {
		t.Error("tiêu đề chưa mã hoá RFC 2047")
	}
}

// Phần thân mã hoá base64 phải giải mã ra đúng nội dung gốc (chữ có dấu không hỏng).
func TestBuildMessageBodyRoundTrip(t *testing.T) {
	html := "<p>Mã của bạn là 482913 — hiệu lực 10 phút</p>"
	text := "Mã của bạn là 482913 — hiệu lực 10 phút"

	msg, err := buildMessage(testCfg(), "khach@gmail.com", "Khách", "Mã xác thực", html, text, "<id@gmail.com>")
	if err != nil {
		t.Fatalf("buildMessage lỗi: %v", err)
	}

	parts := strings.Split(string(msg), "Content-Transfer-Encoding: base64\r\n\r\n")
	if len(parts) != 3 {
		t.Fatalf("cần 2 phần base64, nhận %d", len(parts)-1)
	}

	decode := func(chunk string) string {
		end := strings.Index(chunk, "\r\n--bnd_")
		if end < 0 {
			end = len(chunk)
		}
		raw := strings.ReplaceAll(chunk[:end], "\r\n", "")
		out, err := base64.StdEncoding.DecodeString(raw)
		if err != nil {
			t.Fatalf("giải mã base64 lỗi: %v", err)
		}
		return string(out)
	}

	if got := decode(parts[1]); !strings.Contains(got, text) {
		t.Errorf("bản text sai sau khi giải mã: %q", got)
	}
	if got := decode(parts[2]); !strings.Contains(got, html) {
		t.Errorf("bản HTML sai sau khi giải mã: %q", got)
	}
}

// Mỗi dòng base64 không được vượt 76 ký tự (RFC 2045).
func TestBase64LineLength(t *testing.T) {
	long := strings.Repeat("Mã xác thực rất dài ", 50)
	msg, _ := buildMessage(testCfg(), "a@b.com", "A", "S", long, long, "<id@x>")

	for _, line := range strings.Split(string(msg), "\r\n") {
		if len(line) > 78 {
			t.Errorf("dòng dài %d ký tự, vượt giới hạn: %.40s...", len(line), line)
		}
	}
}

// Message-ID phải lấy tên miền theo địa chỉ gửi và không trùng nhau.
func TestNewMessageID(t *testing.T) {
	a := newMessageID("shop@jerseyhouse.vn")
	b := newMessageID("shop@jerseyhouse.vn")

	if !strings.HasSuffix(a, "@jerseyhouse.vn>") {
		t.Errorf("Message-ID sai tên miền: %s", a)
	}
	if a == b {
		t.Error("2 Message-ID liên tiếp bị trùng")
	}
}

// Link tới localhost phải bị bỏ khỏi thư — đây là tín hiệu spam mạnh
// và người nhận cũng không mở được.
func TestShowLinkChanLocalhost(t *testing.T) {
	cases := map[string]bool{
		"http://localhost:8000":   false,
		"http://127.0.0.1:8000":   false,
		"http://192.168.1.5:8000": false,
		"":                        false,
		"https://jerseyhouse.vn":  true,
	}
	for url, want := range cases {
		d := VerificationData{StoreURL: url}
		if got := d.ShowLink(); got != want {
			t.Errorf("ShowLink(%q) = %v, mong đợi %v", url, got, want)
		}
	}

	html, text, err := RenderVerification(VerificationData{
		StoreName: "Jersey House", StoreURL: "http://localhost:8000",
		Name: "A", Code: "123456", Minutes: 10, Hotline: "0796666468", Year: 2026,
	})
	if err != nil {
		t.Fatal(err)
	}
	if strings.Contains(html, "localhost") {
		t.Error("bản HTML vẫn còn link localhost")
	}
	if strings.Contains(text, "localhost") {
		t.Error("bản text vẫn còn link localhost")
	}
}
