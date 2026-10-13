package service

import "testing"

// Bốn hình thức của quầy phải ra đúng chữ ở email đơn và hoá đơn điện tử —
// trước đây chuyển khoản / quẹt thẻ rơi về "TM/CK", email in mã thô "cash".
func TestNhanHinhThucThanhToan(t *testing.T) {
	for _, c := range []struct{ ma, email, hoaDon string }{
		{"cash", "Tiền mặt", "TM"},
		{"bank_transfer", "Chuyển khoản", "CK"},
		{"card", "Quẹt thẻ", "CK"},
		{"sepay", "QR tự động", "CK"},
		{"payos", "QR tự động", "CK"},
		{"la", "Khác", "TM/CK"},
	} {
		if got := paymentMethodLabel(c.ma); got != c.email {
			t.Errorf("email %q: phải %q, nhận %q", c.ma, c.email, got)
		}
		if got := tenPhuongThuc(c.ma); got != c.hoaDon {
			t.Errorf("hoá đơn %q: phải %q, nhận %q", c.ma, c.hoaDon, got)
		}
	}
}

// Phí quẹt thẻ theo loại thẻ NextPay báo: mã lạ / trống / loại máy chưa khai thì
// lấy mức cao nhất đã khai (kèm cờ để ghi cảnh báo), không lặng lẽ tính 0.
func TestPhiTheoLoaiThe(t *testing.T) {
	bang := map[string]float64{"card_fee_local": 0.5, "card_fee_foreign": 2.4, "card_fee_qrpay": 0}
	for _, c := range []struct {
		issuer string
		tyLe   float64
		ro     bool
	}{
		{"DOMESTIC", 0.5, true},
		{"visa", 2.4, true},
		{"VAQR", 0, true}, // khai 0% là cố ý — giữ nguyên
		{"LOAITHEMOI", 2.4, false},
		{"", 2.4, false},
		{"MOMO", 2.4, false}, // loại có trong bảng NextPay nhưng máy chưa khai mức
	} {
		tyLe, ro := phiTheoLoaiThe(bang, c.issuer)
		if tyLe != c.tyLe || ro != c.ro {
			t.Errorf("%q: phải %v%% / rõ=%v, nhận %v%% / rõ=%v", c.issuer, c.tyLe, c.ro, tyLe, ro)
		}
	}
}
