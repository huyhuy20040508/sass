package service

import (
	"errors"
	"testing"

	"sass-api/internal/domain"
)

func dongThue(tien float64, vat *int, productID uint) domain.OrderItem {
	it := domain.OrderItem{TotalPrice: tien, VAT: vat}
	if productID > 0 {
		it.ProductID = &productID
	}

	return it
}

// Thuế tính trên tiền SAU KHI chia giảm giá cả đơn về từng dòng — cùng cách hoá
// đơn điện tử kê từng dòng, nên tổng thuế quầy thu bằng tổng thuế trên hoá đơn.
func TestThueCuaDon_ChiaGiamGiaRoiMoiTinhThue(t *testing.T) {
	don := &domain.Order{
		DiscountAmount: 15000,
		Items: []domain.OrderItem{
			dongThue(100000, thueSuatChup(10), 1),
			dongThue(50000, thueSuatChup(8), 2),
		},
	}

	dong, tong := thueCuaDon(don, nil)

	// 15.000 chia theo tỉ trọng 2:1 → 10.000 và 5.000.
	// Dòng 1: 90.000 × 10% = 9.000. Dòng 2: 45.000 × 8% = 3.600.
	if dong[0] != 9000 || dong[1] != 3600 || tong != 12600 {
		t.Fatalf("thuế từng dòng phải là [9000 3600] tổng 12600, đang là %v tổng %v", dong, tong)
	}
}

// Dòng chưa có thuế suất chụp (đơn cũ) lùi về mức hiện tại của mặt hàng; hai mã
// âm KCT / KKKNT không sinh thuế.
func TestThueCuaDon_DongCuVaMaThueAm(t *testing.T) {
	don := &domain.Order{Items: []domain.OrderItem{
		dongThue(200000, nil, 7),
		dongThue(100000, thueSuatChup(mucKhongChiuThue), 8),
		dongThue(100000, thueSuatChup(mucKhongKeKhai), 9),
	}}

	_, tong := thueCuaDon(don, map[uint]int{7: 5})
	if tong != 10000 {
		t.Fatalf("chỉ dòng 5%% của 200.000 sinh thuế (10.000), đang là %v", tong)
	}
}

func TestGiamTayCaDon(t *testing.T) {
	cases := []struct {
		ten                                     string
		phanTram, soTien, tienHang, daGiam, han float64
		mongDoi                                 float64
		vuotQuyen                               bool
	}{
		{"theo phần trăm, làm tròn tới đồng", 5, 0, 123457, 0, 10, 6173, false},
		{"phần trăm thắng số tiền", 5, 90000, 100000, 0, 10, 5000, false},
		{"số tiền trong hạn", 0, 10000, 100000, 0, 10, 10000, false},
		{"số tiền vượt hạn quy ra phần trăm", 0, 10001, 100000, 0, 10, 0, true},
		{"phần trăm vượt hạn", 11, 0, 100000, 0, 10, 0, true},
		{"chủ tiệm gõ quá tiền hàng thì kẹp về phần còn lại", 0, 150000, 100000, 30000, 100, 70000, false},
		{"không giảm", 0, 0, 100000, 0, 5, 0, false},
	}

	for _, c := range cases {
		giam, err := giamTayCaDon(c.phanTram, c.soTien, c.tienHang, c.daGiam, c.han)
		if c.vuotQuyen {
			if !errors.Is(err, domain.ErrDiscountTooHigh) {
				t.Errorf("%s: phải báo vượt quyền, đang là %v", c.ten, err)
			}

			continue
		}
		if err != nil || giam != c.mongDoi {
			t.Errorf("%s: mong %v, nhận %v (lỗi %v)", c.ten, c.mongDoi, giam, err)
		}
	}
}
