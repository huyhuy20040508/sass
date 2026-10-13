package nextpay

import (
	"encoding/json"
	"testing"
)

// Mẫu chuẩn sinh bằng chính PHP: base64(openssl_encrypt(json, 'AES-128-ECB', khoá,
// OPENSSL_RAW_DATA)) — đúng cách ordertable v2 gọi NextPay. Lệch một byte là
// NextPay không đọc được gói của mình.
func TestMa_KhopOpenSSLCuaPHP(t *testing.T) {
	thuong := `{"serviceName":"CREATE_QR","amount":3000}`
	for _, c := range []struct{ khoa, muon string }{
		{"abcdefghijklmnop", "6cSklIWVgH0vMAOvYq4Azjx3YmbW9419OvVHolp1ny7kdGHgDvcOSmfV4DRFKQLS"},
		// Khoá ngắn: PHP đệm \0 cho đủ 16 byte.
		{"abc", "hZoIS8BYygFOxCJ9YaOSaDvjhZ/hTWjE1UFYVBjg2xse9fJLL001JfjfYUrfRuBr"},
		// Khoá dài: PHP cắt còn 16 byte.
		{"abcdefghijklmnopqrstu", "6cSklIWVgH0vMAOvYq4Azjx3YmbW9419OvVHolp1ny7kdGHgDvcOSmfV4DRFKQLS"},
	} {
		cl := New(Config{MerchantID: "m", SecretKey: c.khoa})
		got, err := cl.Ma([]byte(thuong))
		if err != nil || got != c.muon {
			t.Fatalf("khoá %q: mã ra %q, muốn %q (%v)", c.khoa, got, c.muon, err)
		}
		lai, err := cl.Giai(got)
		if err != nil || string(lai) != thuong {
			t.Fatalf("khoá %q: giải lại ra %q (%v)", c.khoa, lai, err)
		}
	}
}

func TestDocWebhook_SaiKhoaThiTuChoi(t *testing.T) {
	that := New(Config{MerchantID: "m", SecretKey: "khoa-dung-1234567"})
	goi, _ := that.Ma([]byte(`{"transStatus":104,"orderID":"DH1","amount":"50000","issuerCode":"vaqr"}`))
	raw, _ := json.Marshal(map[string]string{"reqData": goi})

	bt, err := that.DocWebhook(raw)
	if err != nil || !bt.QRXong() || bt.MaDon != "DH1" || bt.SoTien != 50000 || bt.IssuerCode != "VAQR" {
		t.Fatalf("đọc gói đúng khoá sai: %+v %v", bt, err)
	}
	gia := New(Config{MerchantID: "m", SecretKey: "khoa-sai-xxxxxxxx"})
	if _, err := gia.DocWebhook(raw); err != ErrGiaiMa {
		t.Fatalf("khoá sai phải ErrGiaiMa, nhận %v", err)
	}
}

func TestPhiTheoThe_TheoNhanTrenManHinh(t *testing.T) {
	for issuer, o := range map[string]string{
		"DOMESTIC": "card_fee_local", "VISA_LOCAL": "card_fee_domestic", "VISA": "card_fee_foreign",
		"VISA_LOCAL_ON": "card_fee_remote", "MVISA": "card_fee_mvisa", "ZALOPAY": "card_fee_qrpay",
		"DXNEXTPAY": "card_fee_vimo", "MOMO": "card_fee_momo", "LA": "",
	} {
		if got := PhiTheoThe(issuer); got != o {
			t.Errorf("%s → %s, muốn %s", issuer, got, o)
		}
	}
}
