package bimat

import (
	"errors"
	"strings"
	"testing"
)

func TestMaGiai_VongTron(t *testing.T) {
	h := New("khoa-thu-nghiem")
	goc := "PAYOS-API-KEY-1234567890"

	kin, err := h.Ma(goc)
	if err != nil {
		t.Fatalf("không mã hoá được: %v", err)
	}
	if !strings.HasPrefix(kin, TienTo) {
		t.Errorf("chuỗi đã mã hoá phải mang tiền tố %q, nhận %q", TienTo, kin)
	}
	if strings.Contains(kin, goc) {
		t.Error("giá trị gốc lộ nguyên văn trong chuỗi đã mã hoá")
	}

	lai, err := h.Giai(kin)
	if err != nil {
		t.Fatalf("không giải mã được: %v", err)
	}
	if lai != goc {
		t.Errorf("giải ra %q, mong %q", lai, goc)
	}
}

// Cùng một giá trị mã hoá hai lần phải ra hai chuỗi khác nhau. Giống nhau thì
// người xem database biết được hai môi trường đang dùng chung một khoá.
func TestMa_HaiLanKhacNhau(t *testing.T) {
	h := New("khoa-thu-nghiem")

	a, _ := h.Ma("cung-mot-gia-tri")
	b, _ := h.Ma("cung-mot-gia-tri")
	if a == b {
		t.Error("hai lượt mã hoá cùng giá trị không được ra cùng một chuỗi")
	}
}

// Sai khoá thì phải BÁO LỖI, không được trả về chuỗi rác — code phía sau đem
// chuỗi rác đi gọi PayOS thật thì lỗi hiện ra ở một chỗ chẳng liên quan gì.
func TestGiai_SaiKhoaThiBaoLoi(t *testing.T) {
	kin, _ := New("khoa-mot").Ma("bi-mat")

	if _, err := New("khoa-hai").Giai(kin); !errors.Is(err, ErrHong) {
		t.Fatalf("mong ErrHong, nhận %v", err)
	}
}

// Dữ liệu bị sửa một byte cũng phải bị bắt — đó là điểm khác của GCM so với chỉ
// mã hoá suông.
func TestGiai_DuLieuBiSuaThiBaoLoi(t *testing.T) {
	h := New("khoa-thu-nghiem")
	kin, _ := h.Ma("bi-mat")

	hong := kin[:len(kin)-2] + "AA"
	if _, err := h.Giai(hong); !errors.Is(err, ErrHong) {
		t.Fatalf("mong ErrHong khi dữ liệu bị sửa, nhận %v", err)
	}
}

// Chưa khai khoá: TỪ CHỐI mã hoá. Nơi gọi phải dừng lại chứ đừng ghi plaintext —
// ghi plaintext là biến một lỗi cấu hình thấy được thành một lỗ hổng không ai thấy.
func TestChuaKhaiKhoa(t *testing.T) {
	h := New("   ")

	if h.SanSang() {
		t.Error("khoá rỗng thì không được coi là sẵn sàng")
	}
	if _, err := h.Ma("bi-mat"); !errors.Is(err, ErrChuaCoKhoa) {
		t.Fatalf("mong ErrChuaCoKhoa, nhận %v", err)
	}
}

// Giá trị plaintext còn sót từ trước khi có gói này: trả nguyên trạng để hệ
// thống vẫn chạy, lượt ghi sau sẽ mã hoá nó.
func TestGiai_ChuoiChuaMaThiTraNguyen(t *testing.T) {
	ra, err := New("khoa").Giai("khoa-cu-de-nguyen-van")
	if err != nil {
		t.Fatalf("không mong lỗi: %v", err)
	}
	if ra != "khoa-cu-de-nguyen-van" {
		t.Errorf("nhận %q", ra)
	}
}

func TestChe(t *testing.T) {
	cas := map[string]string{
		"":                   "",
		"abc":                "•••",
		"12345678":           "••••••••",
		"PAYOS-KEY-ABCD1234": "••••••••1234",
	}
	for vao, mong := range cas {
		if ra := Che(vao); ra != mong {
			t.Errorf("Che(%q) = %q, mong %q", vao, ra, mong)
		}
	}
}
