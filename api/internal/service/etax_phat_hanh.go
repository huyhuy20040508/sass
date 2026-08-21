package service

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"math"
	"strconv"
	"strings"
	"time"

	"go.uber.org/zap"

	"sass-api/internal/domain"
	"sass-api/pkg/logger"
)

// PHÁT HÀNH HOÁ ĐƠN cho một đơn hàng.
//
// Hai đường vào: người dùng bấm nút trên màn Đơn hàng, hoặc công tắc "Tự phát
// hành" bật và đơn vừa thu tiền xong. Cả hai đi chung một hàm.
//
// LUẬT CỦA KÝ HIỆU: ký tự ĐẦU của ký hiệu hoá đơn quyết định loại chứng từ —
// "1" là hoá đơn GTGT (tách thuế), còn lại là hoá đơn bán hàng (không tách).
// Đây là quy định của cơ quan thuế chứ không phải quy ước của M-Invoice, và gửi
// trường thuế lên một ký hiệu bán hàng là cổng từ chối cả hoá đơn.
const kyHieuGTGT = "1"

// Hai mã thuế đặc biệt của Product.VAT — số âm là MÃ chứ không phải phần trăm.
const (
	mucKhongChiuThue = -1
	mucKhongKeKhai   = -2
)

// XemHoaDon trả hoá đơn đã phát hành của một đơn. ErrNotFound = chưa phát hành.
func (s *etaxService) XemHoaDon(ctx context.Context, orderID uint) (*domain.EtaxInvoice, error) {
	if _, err := s.donHang.FindByID(ctx, orderID); err != nil {
		return nil, err
	}

	return s.repo.HoaDonTheoDon(ctx, orderID)
}

// PhatHanh xuất hoá đơn cho một đơn hàng.
//
// Bốn chốt chặn trước khi gọi ra ngoài, và cả bốn đều là luật nghiệp vụ chứ
// không phải kiểm tra dữ liệu: đơn có thật, đã thu tiền, chi nhánh đã nối cổng
// và đã chọn ký hiệu, và đơn chưa từng có hoá đơn.
func (s *etaxService) PhatHanh(ctx context.Context, orderID uint) (*domain.EtaxInvoice, error) {
	don, err := s.donHang.FindByID(ctx, orderID)
	if err != nil {
		return nil, err
	}
	if don.PaymentStatus != domain.OrderPaymentPaid {
		return nil, domain.ErrDonChuaThuTien
	}

	// Đã phát hành rồi thì dừng — trừ lượt trước HỎNG, khi đó bấm lại là thử
	// lại chứ không phải xuất hoá đơn thứ hai.
	cu, err := s.repo.HoaDonTheoDon(ctx, orderID)
	if err != nil && !errors.Is(err, domain.ErrNotFound) {
		return nil, err
	}
	if cu != nil && cu.Status != domain.HoaDonHong {
		return nil, domain.ErrHoaDonDaPhatHanh
	}

	ketNoi, err := s.repo.TheoChiNhanh(ctx, don.ShopID)
	if errors.Is(err, domain.ErrNotFound) {
		return nil, domain.ErrETaxChuaNoi
	}
	if err != nil {
		return nil, err
	}
	if strings.TrimSpace(ketNoi.TemplateSymbol) == "" {
		return nil, domain.ErrETaxChuaChonKyHieu
	}

	chiNhanh, err := s.chiNhanh.FindByID(ctx, don.ShopID)
	if err != nil {
		return nil, err
	}

	hoaDon, tienThue, err := s.dungHoaDon(ctx, don, ketNoi, chiNhanh)
	if err != nil {
		return nil, err
	}

	// Bản ghi dựng TRƯỚC lượt gọi và lưu lại cả khi hỏng: một hoá đơn bị cổng từ
	// chối vẫn phải để lại dấu vết, nếu không thì lần sau người ta bấm lại và
	// gặp đúng lỗi cũ mà không biết vì sao.
	ghi := &domain.EtaxInvoice{
		ShopID:       don.ShopID,
		OrderID:      don.ID,
		ConnectionID: &ketNoi.ID,
		Provider:     ketNoi.Provider,
		Symbol:       ketNoi.TemplateSymbol,
		TotalAmount:  don.TotalAmount,
		VatAmount:    tienThue,
	}
	if cu != nil {
		ghi.ID = cu.ID
		ghi.CreatedAt = cu.CreatedAt
	}
	if du, err := json.Marshal(hoaDon); err == nil {
		ghi.Payload = string(du)
	}

	ketQua, err := s.mi.PhatHanh(ctx, ketNoi.TaxCode, ketNoi.Token, hoaDon, ketNoi.AutoRelease)
	if err != nil {
		// Token hết hạn là lỗi hay gặp nhất và tự chữa được — thử lại đúng MỘT
		// lần sau khi đăng nhập lại.
		if loi := s.lamMoiToken(ctx, ketNoi); loi == nil {
			ketQua, err = s.mi.PhatHanh(ctx, ketNoi.TaxCode, ketNoi.Token, hoaDon, ketNoi.AutoRelease)
		}
	}
	if err != nil {
		ghi.Status = domain.HoaDonHong
		ghi.Error = catNgan(err.Error(), 500)
		_ = s.repo.LuuHoaDon(ctx, ghi)

		return nil, err
	}

	gio := time.Now()
	ghi.Error = ""
	ghi.InvoiceNo = ketQua.SoHoaDon
	ghi.InvoiceID = ketQua.MaHoaDon
	ghi.Response = string(ketQua.Tho)
	ghi.IssuedAt = &gio
	// Lưu nháp thì CHƯA có giá trị pháp lý: nói đúng trạng thái thay vì báo "đã
	// phát hành" cho một tờ chưa ký.
	ghi.Status = domain.HoaDonNhap
	if ketNoi.AutoRelease {
		ghi.Status = domain.HoaDonDaPhatHanh
	}

	if err := s.repo.LuuHoaDon(ctx, ghi); err != nil {
		return nil, err
	}

	return ghi, nil
}

// TuPhatHanh là đường cho hai chỗ ĐƠN VỪA THU TIỀN gọi tới.
//
// Nuốt mọi lỗi và chỉ ghi nhật ký, CÓ CHỦ Ý: cổng HĐĐT sập không được phép làm
// hỏng một lượt bán đã thu tiền xong. Người bán vẫn thấy đơn hoàn tất và bấm
// phát hành lại được ở màn Đơn hàng.
//
// Chạy đồng bộ ngay sau khi đơn đã ghi xong (ngoài transaction) — cùng chỗ với
// các thông báo khác, không phải trong lúc còn giữ khoá dòng.
func (s *etaxService) TuPhatHanh(ctx context.Context, orderID uint) {
	don, err := s.donHang.FindByID(ctx, orderID)
	if err != nil {
		return
	}

	ketNoi, err := s.repo.TheoChiNhanh(ctx, don.ShopID)
	if err != nil || !ketNoi.AutoRelease || strings.TrimSpace(ketNoi.TemplateSymbol) == "" {
		return
	}

	if _, err := s.PhatHanh(ctx, orderID); err != nil {
		logger.Warn("tự phát hành hoá đơn điện tử không thành công",
			zap.Uint("order_id", orderID), zap.Error(err),
			zap.String("cach_chua", "vào màn Đơn hàng bấm Phát hành hoá đơn để thử lại"))
	}
}

// dungHoaDon dựng payload M-Invoice từ một đơn hàng, và trả kèm tổng tiền thuế.
func (s *etaxService) dungHoaDon(
	ctx context.Context,
	don *domain.Order,
	ketNoi *domain.EtaxConnection,
	chiNhanh *domain.ChiNhanh,
) (map[string]any, float64, error) {
	coThue := strings.HasPrefix(ketNoi.TemplateSymbol, kyHieuGTGT)

	ids := make([]uint, 0, len(don.Items))
	for _, it := range don.Items {
		if it.ProductID != nil {
			ids = append(ids, *it.ProductID)
		}
	}
	thueSuat, err := s.repo.ThueSuatTheoMatHang(ctx, ids)
	if err != nil {
		return nil, 0, err
	}

	dong := make([]map[string]any, 0, len(don.Items)+1)
	var tongThue, tongChuaThue float64

	for i, it := range don.Items {
		ten := strings.TrimSpace(it.ProductName)
		if it.VariantName != "" {
			ten += " (" + it.VariantName + ")"
		}

		// TotalPrice đã trừ phần bớt của chính dòng đó (xem OrderItem.TotalPrice),
		// nên tiền chưa thuế của dòng chính là nó — đừng trừ chiết khấu lần nữa.
		chuaThue := it.TotalPrice
		mucThue := 0
		if it.ProductID != nil {
			mucThue = thueSuat[*it.ProductID]
		}
		tienThue := tinhThue(chuaThue, mucThue)

		d := map[string]any{
			"tchat":                     "1",
			"stt_rec0":                  i + 1,
			"inv_itemCode":              it.VariantSKU,
			"inv_itemName":              ten,
			"inv_unitCode":              "",
			"inv_quantity":              it.Quantity,
			"inv_unitPrice":             it.UnitPrice,
			"amount":                    lamTron(it.UnitPrice * float64(it.Quantity)),
			"inv_discountPercentage":    0,
			"inv_discountAmount":        lamTron(it.DiscountAmount),
			"ptppv":                     0,
			"phidichvu":                 0,
			"inv_TotalAmountWithoutVat": lamTron(chuaThue),
		}
		if coThue {
			d["ma_thue"] = maThue(mucThue)
			d["inv_vatAmount"] = lamTron(tienThue)
			d["inv_TotalAmount"] = lamTron(chuaThue + tienThue)
		}
		dong = append(dong, d)

		tongThue += tienThue
		tongChuaThue += chuaThue
	}

	// Phí giao hàng đi thành MỘT DÒNG của hoá đơn, không gộp vào tổng: khách
	// đối chiếu hoá đơn với đơn hàng phải thấy đủ từng khoản đã trả.
	if don.ShippingFee > 0 {
		d := map[string]any{
			"tchat":                     "1",
			"stt_rec0":                  len(dong) + 1,
			"inv_itemCode":              "",
			"inv_itemName":              "Phí giao hàng",
			"inv_unitCode":              "",
			"inv_quantity":              1,
			"inv_unitPrice":             don.ShippingFee,
			"amount":                    lamTron(don.ShippingFee),
			"inv_discountPercentage":    0,
			"inv_discountAmount":        0,
			"ptppv":                     0,
			"phidichvu":                 0,
			"inv_TotalAmountWithoutVat": lamTron(don.ShippingFee),
		}
		if coThue {
			// Phí giao hàng không mang thuế suất của mặt hàng nào — để KCT thay vì
			// mượn bừa mức của dòng cuối cùng.
			d["ma_thue"] = maThue(mucKhongChiuThue)
			d["inv_vatAmount"] = 0
			d["inv_TotalAmount"] = lamTron(don.ShippingFee)
		}
		dong = append(dong, d)
		tongChuaThue += don.ShippingFee
	}

	hoaDon := map[string]any{
		"inv_invoiceSeries":     ketNoi.TemplateSymbol,
		"inv_invoiceIssuedDate": time.Now().Format("2006-01-02"),
		"inv_currencyCode":      "VND",
		"inv_exchangeRate":      1,
		// Chỗ M-Invoice cho nhét mã tham chiếu của bên bán — để mã đơn vào đây thì
		// tra ngược từ hoá đơn về đơn hàng được.
		"so_benh_an":            don.OrderCode,
		"inv_paymentMethodName": tenPhuongThuc(don.PaymentMethod),
		"inv_buyerDisplayName":  don.RecipientName,
		"inv_buyerAddressLine":  diaChiGiao(don),
		"inv_buyerEmail":        don.RecipientEmail,
		"buyerTel":              don.RecipientPhone,
		"ma_ch":                 chiNhanh.Code,
		"ten_ch":                chiNhanh.Name,
		"dchicuahang":           string(chiNhanh.Address),
		"amount":                lamTron(don.SubtotalAmount + don.ShippingFee),
		"inv_discountAmount":    lamTron(don.DiscountAmount),
		"inv_TotalAmount":       lamTron(don.TotalAmount + tongThue),
		"serviceCharge":         0,
		"details": []map[string]any{
			{"data": dong},
		},
	}
	if coThue {
		hoaDon["inv_TotalAmountWithoutVat"] = lamTron(tongChuaThue)
		hoaDon["inv_vatAmount"] = lamTron(tongThue)
	}

	return hoaDon, lamTron(tongThue), nil
}

// tinhThue tính tiền thuế của một dòng. Hai mã âm (KCT, KKKNT) không sinh thuế.
func tinhThue(chuaThue float64, muc int) float64 {
	if muc <= 0 {
		return 0
	}

	return lamTron(chuaThue * float64(muc) / 100)
}

// maThue đổi quy ước Product.VAT sang mã thuế M-Invoice: số dương là phần trăm,
// còn hai mã âm gửi đúng chữ viết tắt của tờ khai.
func maThue(muc int) string {
	switch muc {
	case mucKhongChiuThue:
		return "KCT"
	case mucKhongKeKhai:
		return "KKKNT"
	default:
		if muc < 0 {
			return "0"
		}

		return strconv.Itoa(muc)
	}
}

// lamTron về đồng: hoá đơn không có đơn vị nhỏ hơn, và để lẻ thì tổng của các
// dòng không khớp tổng của hoá đơn.
func lamTron(v float64) float64 { return math.Round(v) }

func diaChiGiao(don *domain.Order) string {
	phan := []string{don.ShippingAddress, don.ShippingWard, don.ShippingDistrict, don.ShippingProvince}
	con := make([]string, 0, len(phan))
	for _, p := range phan {
		if strings.TrimSpace(p) != "" {
			con = append(con, strings.TrimSpace(p))
		}
	}

	return strings.Join(con, ", ")
}

// tenPhuongThuc đổi mã phương thức thanh toán sang chữ in trên hoá đơn. Mã lạ
// thì gửi "TM/CK" — đúng thứ cơ quan thuế nhận cho trường hợp không rõ.
func tenPhuongThuc(ma string) string {
	switch strings.ToLower(strings.TrimSpace(ma)) {
	case "cash", "cod":
		return "TM"
	case "bank", "banking", "transfer", "payos", "sepay", "vnpay", "momo":
		return "CK"
	default:
		return "TM/CK"
	}
}

func catNgan(s string, n int) string {
	if len(s) <= n {
		return s
	}

	return s[:n]
}

// MoTaHoaDon dựng câu báo cho người bấm nút — nói rõ đã KÝ hay mới lưu nháp.
func MoTaHoaDon(hd *domain.EtaxInvoice) string {
	if hd.Status == domain.HoaDonDaPhatHanh {
		if hd.InvoiceNo != "" {
			return fmt.Sprintf("Đã phát hành hoá đơn %s số %s", hd.Symbol, hd.InvoiceNo)
		}

		return "Đã phát hành hoá đơn " + hd.Symbol
	}

	return "Đã lưu hoá đơn nháp " + hd.Symbol + " — vào trang nhà cung cấp để ký phát hành"
}
