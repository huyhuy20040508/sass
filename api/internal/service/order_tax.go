package service

import (
	"fmt"
	"math"
	"strconv"

	"sass-api/internal/domain"
)

// THUẾ VÀ GIẢM TAY CỦA MỘT ĐƠN — dùng chung cho quầy bán hàng và hoá đơn điện tử.
//
// Hai nơi ấy PHẢI ra cùng một con số: quầy thu tiền thuế theo hàm này, rồi hoá
// đơn điện tử kê lại từng dòng thuế bằng chính các hàm chiaGiamGia / tinhThue
// bên dưới. Tách thành hai bản tính là có ngày tờ hoá đơn lệch số khách đã trả
// vài đồng, và cổng từ chối cả tờ.

// thueSuatChup trả con trỏ tới thuế suất để CHỤP vào dòng đơn.
func thueSuatChup(muc int) *int { return &muc }

// mucThueDong là thuế suất áp cho một dòng: mức đã CHỤP lúc bán nếu có, không thì
// mức hiện tại của mặt hàng (dòng bán trước migration 0067 không có mức chụp).
func mucThueDong(it domain.OrderItem, hienTai map[uint]int) int {
	if it.VAT != nil {
		return *it.VAT
	}
	if it.ProductID != nil {
		return hienTai[*it.ProductID]
	}

	return 0
}

// thueCuaDon tính tiền thuế từng dòng và tổng thuế của đơn.
//
// Tiền chịu thuế của một dòng là TotalPrice (đã trừ phần bớt của chính dòng đó)
// trừ tiếp suất giảm giá cả đơn chia về dòng ấy — đúng như dungHoaDon kê lên hoá
// đơn. `hienTai` chỉ cần cho dòng chưa có thuế suất chụp; nil là đủ cho đơn vừa
// dựng ở quầy.
func thueCuaDon(don *domain.Order, hienTai map[uint]int) ([]float64, float64) {
	chia := chiaGiamGia(don)
	ra := make([]float64, len(don.Items))

	var tong float64
	for i, it := range don.Items {
		ra[i] = tinhThue(lamTron(it.TotalPrice-chia[i]), mucThueDong(it, hienTai))
		tong += ra[i]
	}

	return ra, tong
}

// giamTayCaDon tính số tiền GIẢM TAY trên cả đơn mà người bán bấm ở quầy.
//
//   - phanTram > 0 thắng soTien: màn hình chỉ gửi một trong hai, gửi cả hai là
//     dữ liệu lạ và phần trăm là thứ kiểm được hạn quyền trực tiếp.
//   - Hạn quyền là CÙNG con số với giảm từng dòng. Gõ số tiền thì quy ra phần
//     trăm của tiền hàng — không thì chặn 5% mà gõ "90.000" cho đơn 100.000 vẫn qua.
//   - Không giảm quá phần tiền hàng còn lại sau mã giảm giá (daGiam): tổng giảm
//     lớn hơn tiền hàng là một đơn âm tiền.
func giamTayCaDon(phanTram, soTien, tienHang, daGiam, hanMuc float64) (float64, error) {
	han := strconv.FormatFloat(hanMuc, 'f', -1, 64)
	if phanTram > hanMuc {
		return 0, fmt.Errorf("%w: giảm cả đơn tối đa %s%% — nhờ quản lý duyệt mức cao hơn",
			domain.ErrDiscountTooHigh, han)
	}

	var giam float64
	switch {
	case phanTram > 0:
		giam = math.Round(tienHang * phanTram / 100)
	case soTien > 0:
		giam = math.Round(soTien)
		if tran := math.Round(tienHang * hanMuc / 100); hanMuc < 100 && giam > tran {
			return 0, fmt.Errorf("%w: giảm cả đơn tối đa %s%% (%s) — nhờ quản lý duyệt mức cao hơn",
				domain.ErrDiscountTooHigh, han, formatVND(tran))
		}
	}

	if con := tienHang - daGiam; giam > con {
		giam = math.Max(0, con)
	}

	return giam, nil
}
