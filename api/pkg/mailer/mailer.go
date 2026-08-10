// Package mailer gửi email qua SMTP (Gmail dùng mật khẩu ứng dụng).
// Dùng net/smtp trong thư viện chuẩn nên không thêm phụ thuộc ngoài.
package mailer

import (
	"bytes"
	"crypto/rand"
	"crypto/tls"
	"encoding/base64"
	"encoding/hex"
	"errors"
	"fmt"
	"mime"
	"net/smtp"
	"strings"
	"time"

	"sass-api/config"
)

// Mailer gửi thư đi. Send trả về Message-ID để tra cứu lại trong hộp thư
// (Gmail: gõ rfc822msgid:<id> vào ô tìm kiếm).
type Mailer interface {
	Send(to, toName, subject, htmlBody, textBody string) (string, error)
	Enabled() bool
}

type smtpMailer struct {
	cfg config.MailConfig
}

// New tạo mailer từ cấu hình.
func New(cfg config.MailConfig) Mailer { return &smtpMailer{cfg: cfg} }

func (m *smtpMailer) Enabled() bool { return m.cfg.Enabled() }

// Send gửi thư multipart/alternative (bản text + bản HTML) qua SMTP + STARTTLS.
func (m *smtpMailer) Send(to, toName, subject, htmlBody, textBody string) (string, error) {
	if !m.cfg.Enabled() {
		return "", errors.New("chưa cấu hình SMTP (MAIL_USERNAME/MAIL_PASSWORD)")
	}

	msgID := newMessageID(m.cfg.FromAddress)
	msg, err := buildMessage(m.cfg, to, toName, subject, htmlBody, textBody, msgID)
	if err != nil {
		return "", err
	}

	auth := smtp.PlainAuth("", m.cfg.Username, m.cfg.Password, m.cfg.Host)

	client, err := smtp.Dial(m.cfg.Addr())
	if err != nil {
		return "", fmt.Errorf("kết nối SMTP: %w", err)
	}
	defer client.Close()

	if ok, _ := client.Extension("STARTTLS"); ok {
		if err := client.StartTLS(&tls.Config{ServerName: m.cfg.Host, MinVersion: tls.VersionTLS12}); err != nil {
			return "", fmt.Errorf("STARTTLS: %w", err)
		}
	}
	if err := client.Auth(auth); err != nil {
		return "", fmt.Errorf("xác thực SMTP thất bại (kiểm tra mật khẩu ứng dụng): %w", err)
	}
	if err := client.Mail(m.cfg.FromAddress); err != nil {
		return "", fmt.Errorf("MAIL FROM: %w", err)
	}
	if err := client.Rcpt(to); err != nil {
		return "", fmt.Errorf("RCPT TO: %w", err)
	}
	w, err := client.Data()
	if err != nil {
		return "", fmt.Errorf("DATA: %w", err)
	}
	if _, err = w.Write(msg); err != nil {
		return "", fmt.Errorf("ghi nội dung thư: %w", err)
	}
	if err = w.Close(); err != nil {
		return "", fmt.Errorf("máy chủ từ chối thư: %w", err)
	}
	return msgID, client.Quit()
}

// newMessageID sinh Message-ID theo tên miền của địa chỉ gửi.
// Thiếu header này là một tín hiệu spam với nhiều bộ lọc.
func newMessageID(from string) string {
	domainPart := "localhost"
	if at := strings.LastIndex(from, "@"); at >= 0 && at+1 < len(from) {
		domainPart = from[at+1:]
	}
	var b [8]byte
	_, _ = rand.Read(b[:])
	return fmt.Sprintf("<%d.%s@%s>", time.Now().UnixNano(), hex.EncodeToString(b[:]), domainPart)
}

// buildMessage dựng thư RFC 5322 dạng multipart/alternative.
// Dùng base64 cho phần thân: nội dung tiếng Việt qua 8bit dễ bị máy chủ trung gian
// cắt dòng/đổi mã và làm hỏng chữ hoặc bị bộ lọc chấm điểm xấu.
func buildMessage(cfg config.MailConfig, to, toName, subject, htmlBody, textBody, msgID string) ([]byte, error) {
	var b bytes.Buffer
	boundary := fmt.Sprintf("bnd_%d", time.Now().UnixNano())

	header := func(k, v string) { b.WriteString(k + ": " + v + "\r\n") }

	header("From", formatAddress(cfg.FromName, cfg.FromAddress))
	header("To", formatAddress(toName, to))
	header("Reply-To", cfg.FromAddress)
	header("Subject", mime.QEncoding.Encode("UTF-8", subject))
	header("Message-ID", msgID)
	header("Date", time.Now().Format(time.RFC1123Z))
	header("MIME-Version", "1.0")
	// Đánh dấu thư giao dịch tự động (RFC 3834) — chặn auto-reply, không phải quảng cáo.
	// Không đặt X-Mailer: tên phần mềm gửi lạ là một tín hiệu bị bộ lọc chấm điểm xấu.
	header("Auto-Submitted", "auto-generated")
	header("Content-Type", `multipart/alternative; boundary="`+boundary+`"`)
	b.WriteString("\r\n")

	part := func(contentType, body string) {
		b.WriteString("--" + boundary + "\r\n")
		b.WriteString("Content-Type: " + contentType + "; charset=\"UTF-8\"\r\n")
		b.WriteString("Content-Transfer-Encoding: base64\r\n\r\n")
		b.WriteString(wrap76(base64.StdEncoding.EncodeToString([]byte(normalizeNewlines(body)))))
		b.WriteString("\r\n")
	}

	part("text/plain", textBody) // bản text cho ứng dụng không đọc HTML
	part("text/html", htmlBody)

	b.WriteString("--" + boundary + "--\r\n")
	return b.Bytes(), nil
}

// wrap76 ngắt chuỗi base64 thành dòng 76 ký tự theo yêu cầu của RFC 2045.
func wrap76(s string) string {
	var b strings.Builder
	for i := 0; i < len(s); i += 76 {
		end := i + 76
		if end > len(s) {
			end = len(s)
		}
		b.WriteString(s[i:end])
		b.WriteString("\r\n")
	}
	return b.String()
}

// formatAddress mã hoá tên hiển thị có dấu tiếng Việt theo RFC 2047.
func formatAddress(name, addr string) string {
	if strings.TrimSpace(name) == "" {
		return addr
	}
	return mime.QEncoding.Encode("UTF-8", name) + " <" + addr + ">"
}

func normalizeNewlines(s string) string {
	s = strings.ReplaceAll(s, "\r\n", "\n")
	return strings.ReplaceAll(s, "\n", "\r\n")
}
