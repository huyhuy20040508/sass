package service

import (
	"testing"

	"sass-api/internal/domain"
	"sass-api/internal/dto"
	"sass-api/pkg/minvoice"
)

// Bài kiểm phần TÍNH TOÁN của hoá đơn điện tử.
//
// Không có mock nào ở đây: mấy hàm này thuần tuý là số và JSON, mà chúng lại là
// chỗ hỏng đắt nhất — tổng lệch vài đồng thì cổng từ chối cả tờ, và mã thuế sai
// thì hoá đơn ghi sai nghĩa vụ với cơ quan thuế.

func donVoiDong(giam float64, tien ...float64) *domain.Order {
	don := &domain.Order{DiscountAmount: giam}
	for _, t := range tien {
		don.Items = append(don.Items, domain.OrderItem{TotalPrice: t})
	}

	return don
}

// Tổng phần chia PHẢI bằng đúng khoản giảm giá. Chia theo tỉ lệ rồi làm tròn
// từng dòng là chỗ đẻ ra chênh lệch một hai đồng, và cổng cộng lại không khớp
// thì từ chối cả hoá đơn.
func TestChiaGiamGiaKhongLamLechTong(t *testing.T) {
	ca := []struct {
		ten  string
		giam float64
		tien []float64
	}{
		{"chia hết", 100, []float64{100, 200, 300}},
		{"chia lẻ", 100, []float64{333, 333, 334}},
		{"lẻ tới từng đồng", 7, []float64{10, 10, 10}},
		{"một dòng", 5000, []float64{20000}},
	}

	for _, c := range ca {
		t.Run(c.ten, func(t *testing.T) {
			ds := chiaGiamGia(donVoiDong(c.giam, c.tien...))
			var tong float64
			for _, v := range ds {
				tong += v
			}
			if tong != c.giam {
				t.Fatalf("tổng phần chia phải bằng %v, nhận %v (%v)", c.giam, tong, ds)
			}
		})
	}
}

func TestChiaGiamGiaKhongCoGiThiKhongChia(t *testing.T) {
	ds := chiaGiamGia(donVoiDong(0, 100, 200))
	for i, v := range ds {
		if v != 0 {
			t.Fatalf("không giảm giá thì dòng %d phải là 0, nhận %v", i, v)
		}
	}
}

// Giảm giá lớn hơn tiền hàng là dữ liệu hỏng. Bớt tối đa bằng tiền hàng chứ
// đừng gửi lên cổng một dòng âm — hoá đơn âm là một chứng từ không tồn tại.
func TestChiaGiamGiaKhongVuotTienHang(t *testing.T) {
	ds := chiaGiamGia(donVoiDong(999999, 100, 200))
	var tong float64
	for _, v := range ds {
		tong += v
	}
	if tong != 300 {
		t.Fatalf("phần chia không được vượt 300, nhận %v", tong)
	}
}

// API 2.0 nhận ĐÚNG bảng số. Chữ viết tắt "KCT"/"KKKNT" là quy ước của bản cũ.
func TestMaThueTheoBangSoCuaCong(t *testing.T) {
	ca := map[int]string{10: "10", 8: "8", 5: "5", 0: "0", -1: "-1", -2: "-2", -9: "0"}
	for muc, mong := range ca {
		if got := maThue(muc); got != mong {
			t.Fatalf("mức %d phải ra %q, nhận %q", muc, mong, got)
		}
	}
}

// MÃ CƠ QUAN THUẾ quyết định tờ hoá đơn đã có hiệu lực hay chưa, không phải
// `trang_thai`: "đã gửi" mà báo "đã phát hành" là bảo người bán đưa cho khách
// một tờ chưa được cấp mã.
func TestApDungTrangThaiTheoMaCoQuanThue(t *testing.T) {
	ca := []struct {
		ten  string
		tt   minvoice.ThongTinHoaDon
		mong string
	}{
		{"có mã là xong", minvoice.ThongTinHoaDon{MaCQT: "M1-26-X", TrangThai: 3, TrangThaiHD: -1}, domain.HoaDonDaPhatHanh},
		{"thành công", minvoice.ThongTinHoaDon{TrangThai: minvoice.TrangThaiThanhCong, TrangThaiHD: -1}, domain.HoaDonDaPhatHanh},
		{"đã gửi, chưa cấp mã", minvoice.ThongTinHoaDon{TrangThai: minvoice.TrangThaiDaGui, TrangThaiHD: -1}, domain.HoaDonDaGui},
		{"đang ký", minvoice.ThongTinHoaDon{TrangThai: minvoice.TrangThaiDangKy, TrangThaiHD: -1}, domain.HoaDonDaGui},
		{"chờ ký", minvoice.ThongTinHoaDon{TrangThai: minvoice.TrangThaiChoKy, TrangThaiHD: -1}, domain.HoaDonNhap},
		{"cổng báo lỗi", minvoice.ThongTinHoaDon{TrangThai: minvoice.TrangThaiCoLoi, TrangThaiHD: -1}, domain.HoaDonHong},
	}

	for _, c := range ca {
		t.Run(c.ten, func(t *testing.T) {
			hd := &domain.EtaxInvoice{Status: domain.HoaDonNhap}
			apDungTrangThai(hd, &c.tt)
			if hd.Status != c.mong {
				t.Fatalf("phải là %q, nhận %q", c.mong, hd.Status)
			}
		})
	}
}

// Cổng không nói gì về trạng thái (-1) thì GIỮ NGUYÊN thứ đang có, đừng hạ một
// tờ đã phát hành xuống nháp chỉ vì một lượt tra không trả lời.
func TestApDungTrangThaiKhongBietThiGiuNguyen(t *testing.T) {
	hd := &domain.EtaxInvoice{Status: domain.HoaDonDaGui}
	apDungTrangThai(hd, &minvoice.ThongTinHoaDon{TrangThai: -1, TrangThaiHD: -1})
	if hd.Status != domain.HoaDonDaGui {
		t.Fatalf("phải giữ nguyên 'sent', nhận %q", hd.Status)
	}
	if hd.GatewayStatus != nil {
		t.Fatalf("không biết trạng thái thì không được ghi gì, nhận %v", *hd.GatewayStatus)
	}
}

// Điều chỉnh về 0 phải đọc lại đúng các dòng ĐÃ GỬI rồi đảo dấu — không dựng
// lại từ đơn hàng hôm nay, vì đơn có thể đã bị sửa sau khi xuất hoá đơn.
func TestDieuChinhVeKhongDaoDauDongDaGui(t *testing.T) {
	hd := &domain.EtaxInvoice{
		Symbol:    "1C26TYY",
		InvoiceNo: "10",
		Payload: domain.StringOrNull(`{"inv_invoiceSeries":"1C26TYY","details":[{"data":[
			{"inv_itemName":"Quần bò","inv_quantity":1,"inv_unitPrice":250000,
			 "inv_Amount":250000,"inv_TotalAmountWithoutVat":250000,
			 "inv_vatAmount":25000,"inv_TotalAmount":275000,"ma_thue":"10"}]}]}`),
	}

	dong, tongThue, err := dongDieuChinh(hd, &dto.EtaxDieuChinhRequest{LyDo: "Xuất nhầm"})
	if err != nil {
		t.Fatalf("dựng dòng điều chỉnh: %v", err)
	}

	// Một dòng hàng đảo dấu + một dòng diễn giải.
	if len(dong) != 2 {
		t.Fatalf("phải có 2 dòng (hàng + diễn giải), nhận %d", len(dong))
	}
	if dong[0]["inv_TotalAmountWithoutVat"] != -250000.0 {
		t.Fatalf("thành tiền phải đảo dấu, nhận %v", dong[0]["inv_TotalAmountWithoutVat"])
	}
	if dong[0]["inv_vatAmount"] != -25000.0 {
		t.Fatalf("tiền thuế phải đảo dấu, nhận %v", dong[0]["inv_vatAmount"])
	}
	if dong[0]["ma_thue"] != "10" {
		t.Fatalf("mã thuế phải giữ nguyên, nhận %v", dong[0]["ma_thue"])
	}
	if tongThue != -25000 {
		t.Fatalf("tổng thuế điều chỉnh phải là -25000, nhận %v", tongThue)
	}

	// Dòng diễn giải (tchat 4) nói rõ đang điều chỉnh tờ nào.
	if dong[1]["tchat"] != 4 {
		t.Fatalf("dòng cuối phải là diễn giải tchat 4, nhận %v", dong[1]["tchat"])
	}
}

// Không đọc được payload cũ thì NÓI RA, đừng gửi lên cổng một tờ điều chỉnh
// rỗng — nó vẫn là một chứng từ được cấp số.
func TestDieuChinhVeKhongThieuPayloadThiTuChoi(t *testing.T) {
	hd := &domain.EtaxInvoice{Symbol: "1C26TYY"}
	if _, _, err := dongDieuChinh(hd, &dto.EtaxDieuChinhRequest{LyDo: "x"}); err == nil {
		t.Fatal("thiếu payload cũ thì phải báo lỗi")
	}
}

// Khai tay từng dòng thì dùng đúng thứ người dùng khai, không đụng tới tờ cũ.
func TestDieuChinhTheoDongKhaiTay(t *testing.T) {
	hd := &domain.EtaxInvoice{Symbol: "1C26TYY"}
	req := &dto.EtaxDieuChinhRequest{
		LyDo: "Giảm đơn giá",
		Dong: []dto.EtaxDongDieuChinhDTO{
			{TenHang: "Quần bò", SoLuong: 1, DonGia: -50000, ThanhTien: -50000, TienThue: -5000, MaThue: "10"},
		},
	}

	dong, tongThue, err := dongDieuChinh(hd, req)
	if err != nil {
		t.Fatalf("dựng dòng điều chỉnh: %v", err)
	}
	if len(dong) != 1 || dong[0]["inv_itemName"] != "Quần bò" {
		t.Fatalf("phải dùng đúng dòng đã khai, nhận %v", dong)
	}
	if tongThue != -5000 {
		t.Fatalf("tổng thuế phải là -5000, nhận %v", tongThue)
	}
}

// Chỉ tờ ĐÃ ĐƯỢC CẤP MÃ mới sửa được: tờ nháp thì sửa thẳng rồi phát hành lại.
func TestCoTheSuaChiKhiDaCoMaCoQuanThue(t *testing.T) {
	if err := coTheSua(&domain.EtaxInvoice{Status: domain.HoaDonDaGui}); err == nil {
		t.Fatal("tờ mới gửi, chưa có mã thì chưa sửa được")
	}
	if err := coTheSua(&domain.EtaxInvoice{Status: domain.HoaDonDaPhatHanh}); err == nil {
		t.Fatal("trạng thái issued nhưng thiếu mã CQT thì vẫn chưa sửa được")
	}
	hd := &domain.EtaxInvoice{Status: domain.HoaDonDaPhatHanh, TaxAuthCode: "M1-26-X"}
	if err := coTheSua(hd); err != nil {
		t.Fatalf("tờ đã cấp mã phải sửa được, nhận %v", err)
	}
}
