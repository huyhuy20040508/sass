// Package bimat mã hoá những giá trị KHÔNG được nằm nguyên văn trong database.
//
// Hôm nay đúng một nhóm dùng tới: khoá cổng thanh toán của nền tảng
// (PayOS client id / api key / checksum key), cất trong bảng `platform_settings`.
//
// VÌ SAO PHẢI MÃ HOÁ, trong khi số tài khoản ngân hàng thì không: số tài khoản
// là thông tin công khai — in trên hoá đơn, dán ở quầy. Khoá cổng thanh toán thì
// ngược lại, ai cầm được nó thì tạo được link thu tiền đứng tên mình và ký giả
// được webhook báo "đã trả tiền". Bảng cấu hình thì nằm nguyên văn trong MỌI bản
// sao lưu database, và bản sao lưu đi qua nhiều tay hơn hẳn máy chủ.
//
// RANH GIỚI CỦA CÁCH LÀM NÀY, nói thẳng: khoá mã hoá nằm ở .env cùng máy chủ.
// Ai vào được máy chủ thì đọc được cả hai, nên đây KHÔNG phải lớp bảo vệ chống
// người đã chiếm máy. Nó bảo vệ đúng một tình huống — và là tình huống hay xảy
// ra nhất: một bản dump database lọt ra ngoài (gửi nhầm, sao lưu để hớ, máy dev
// khôi phục bản thật về xem). Muốn hơn nữa thì phải có KMS/HSM, và lúc đó chỗ
// thay là đúng gói này.
package bimat

import (
	"crypto/aes"
	"crypto/cipher"
	"crypto/rand"
	"crypto/sha256"
	"encoding/base64"
	"errors"
	"io"
	"strings"
)

// TienTo đánh dấu chuỗi đã mã hoá, kèm SỐ HIỆU PHIÊN BẢN.
//
// Có tiền tố thì phân biệt được ngay giá trị đã mã hoá với giá trị plaintext còn
// sót lại từ trước — không có nó, một chuỗi base64 và một khoá API thật trông
// giống hệt nhau, và code sẽ phải đoán. Số hiệu để ngày đổi thuật toán thì bản
// cũ vẫn giải được thay vì hỏng cả bảng.
const TienTo = "enc:v1:"

var (
	// ErrChuaCoKhoa: chưa khai PLATFORM_SECRET_KEY. Nơi gọi phải TỪ CHỐI GHI chứ
	// đừng lặng lẽ ghi plaintext — ghi plaintext là biến một lỗi cấu hình thấy
	// được thành một lỗ hổng không ai thấy.
	ErrChuaCoKhoa = errors.New("chưa khai khoá mã hoá của nền tảng")
	// ErrHong: chuỗi không giải được — sai khoá, hoặc dữ liệu bị sửa.
	ErrHong = errors.New("không giải mã được giá trị đã lưu")
)

// Hop giữ khoá mã hoá đã dẫn xuất. Rỗng (chưa khai khoá) vẫn dùng được, chỉ là
// mọi lượt mã hoá / giải mã đều trả ErrChuaCoKhoa.
type Hop struct {
	aead cipher.AEAD
}

// New dẫn xuất khoá 32 byte từ chuỗi bí mật bằng SHA-256.
//
// Băm chứ không đòi đúng 32 byte: khoá trong .env do người gõ, và bắt họ đếm ký
// tự thì cái sai sẽ là một khoá ngắn cho đủ chứ không phải một khoá dài. Chuỗi
// rỗng = chưa khai, trả về hộp không dùng được (xem ErrChuaCoKhoa).
func New(biMat string) *Hop {
	biMat = strings.TrimSpace(biMat)
	if biMat == "" {
		return &Hop{}
	}

	tong := sha256.Sum256([]byte(biMat))
	khoi, err := aes.NewCipher(tong[:])
	if err != nil {
		return &Hop{}
	}
	aead, err := cipher.NewGCM(khoi)
	if err != nil {
		return &Hop{}
	}

	return &Hop{aead: aead}
}

// SanSang cho biết đã khai khoá chưa. Màn hình dùng nó để nói đúng lý do thay vì
// báo "lưu thất bại" chung chung.
func (h *Hop) SanSang() bool { return h != nil && h.aead != nil }

// Ma mã hoá một chuỗi. Kết quả luôn mang TienTo.
//
// GCM chứ không phải CBC: nó vừa giấu nội dung vừa CHỐNG SỬA — sửa một byte
// trong database thì lượt giải mã báo lỗi, thay vì trả về một chuỗi rác mà code
// phía sau đem đi gọi API thật.
//
// Mỗi lượt mã hoá dùng nonce ngẫu nhiên riêng, nên cùng một khoá API mã hoá hai
// lần ra hai chuỗi khác nhau. Đó là điều đúng: giống nhau thì người xem database
// biết được hai môi trường đang dùng chung một khoá.
func (h *Hop) Ma(thuong string) (string, error) {
	if !h.SanSang() {
		return "", ErrChuaCoKhoa
	}

	nonce := make([]byte, h.aead.NonceSize())
	if _, err := io.ReadFull(rand.Reader, nonce); err != nil {
		return "", err
	}
	kin := h.aead.Seal(nonce, nonce, []byte(thuong), nil)

	return TienTo + base64.StdEncoding.EncodeToString(kin), nil
}

// Giai giải mã chuỗi do Ma sinh ra.
//
// Chuỗi KHÔNG mang tiền tố được trả về nguyên trạng: đó là giá trị plaintext còn
// sót từ trước khi có gói này (hoặc do người sửa tay trong database). Trả nguyên
// văn để hệ thống vẫn chạy, và lượt ghi tiếp theo sẽ mã hoá nó.
func (h *Hop) Giai(daMa string) (string, error) {
	if !strings.HasPrefix(daMa, TienTo) {
		return daMa, nil
	}
	if !h.SanSang() {
		return "", ErrChuaCoKhoa
	}

	raw, err := base64.StdEncoding.DecodeString(strings.TrimPrefix(daMa, TienTo))
	if err != nil {
		return "", ErrHong
	}
	n := h.aead.NonceSize()
	if len(raw) < n {
		return "", ErrHong
	}

	thuong, err := h.aead.Open(nil, raw[:n], raw[n:], nil)
	if err != nil {
		return "", ErrHong
	}

	return string(thuong), nil
}

// DaMa cho biết một giá trị đang ở dạng đã mã hoá hay chưa.
func DaMa(v string) bool { return strings.HasPrefix(v, TienTo) }

// Che dựng bản CHE của một bí mật để hiển thị: bốn ký tự cuối, phần còn lại là
// chấm tròn.
//
// Bốn ký tự cuối chứ không phải đầu: người bán cần đối chiếu khoá đang lưu với
// khoá trên trang quản trị của PayOS, mà cả hai đều hiện đuôi. Chuỗi quá ngắn
// thì che sạch — che một khoá 6 ký tự mà hở bốn thì còn gì để che.
func Che(thuong string) string {
	if thuong == "" {
		return ""
	}
	r := []rune(thuong)
	if len(r) <= 8 {
		return strings.Repeat("•", len(r))
	}

	return strings.Repeat("•", 8) + string(r[len(r)-4:])
}
