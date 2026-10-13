package service

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"strconv"
	"strings"
	"time"

	"sass-api/internal/domain"
	"sass-api/pkg/sepayinvoice"
)

// HOÁ ĐƠN ĐIỆN TỬ QUA SEPAY.
//
// Cùng sổ, cùng luật với M-Invoice (một đơn một hoá đơn, chỉ đơn đã thu tiền,
// ký hiệu chữ "1" đầu là GTGT); khác ở ba chỗ:
//
//   - Ký hiệu lưu là MẪU SỐ + KÝ HIỆU ("1C26TSE"): SePay cấp cùng một ký hiệu cho
//     cả mẫu GTGT lẫn mẫu bán hàng, nên riêng ký hiệu không chọn được tờ nào.
//     Ghép lại thì vừa không trùng, vừa giữ luật "chữ đầu là 1 = GTGT".
//   - reference_code là MÃ ĐƠN: cổng chặn trùng theo mã này (409), nên một lượt
//     gửi hỏng giữa chừng rồi bấm lại không sinh ra tờ thứ hai.
//   - Tạo / phát hành là bất đồng bộ: gửi xong hỏi lại vài giây; cổng còn đang
//     xử lý thì ghi "đã gửi" và để nút Đồng bộ hỏi tiếp.
//
// Thay thế / điều chỉnh chưa làm với SePay — trả ErrETaxChuaHoTro.

// choSePay là thời gian tối đa ngồi hỏi kết quả ngay sau khi gửi. Đường tự phát
// hành chạy ngay trong lượt thu tiền ở quầy nên không được treo lâu; quá hạn
// này thì để nút Đồng bộ hỏi sau.
var (
	choSePay     = 8 * time.Second
	nhipHoiSePay = 1500 * time.Millisecond
)

// vetSePay là thứ cất vào cột `response`: mã theo dõi của lượt tạo và lượt phát
// hành (để còn hỏi lại khi cổng chưa xong) cùng nguyên văn trả lời cuối.
type vetSePay struct {
	MaTao      string          `json:"tracking_create,omitempty"`
	MaPhatHanh string          `json:"tracking_issue,omitempty"`
	TraLoi     json.RawMessage `json:"tra_loi,omitempty"`
}

func docVetSePay(hd *domain.EtaxInvoice) vetSePay {
	var v vetSePay
	_ = json.Unmarshal([]byte(strings.TrimSpace(string(hd.Response))), &v)

	return v
}

func (v vetSePay) ghiVao(hd *domain.EtaxInvoice) {
	if du, err := json.Marshal(v); err == nil {
		hd.Response = domain.StringOrNull(du)
	}
}

// ---------------------------------------------------------------------
// Kết nối
// ---------------------------------------------------------------------

// chonTaiKhoanSePay chốt tài khoản nhà cung cấp mà hoá đơn sẽ phát hành trên đó.
//
// Khách chỉ khai tên đăng nhập / mật khẩu, nên bình thường `ref` rỗng và nhận
// cái đang bật DUY NHẤT. Có nhiều cái thì từ chối kèm danh sách — đoán bừa là
// phát hành dưới một pháp nhân khác; API vẫn nhận `account_ref` cho ca đó.
func chonTaiKhoanSePay(ctx context.Context, sp *sepayinvoice.Client, token, ref string) (string, error) {
	if ref = strings.TrimSpace(ref); ref != "" {
		tk, err := sp.ChiTietTaiKhoan(ctx, token, ref)
		if err != nil {
			if sepayinvoice.LaKhongThay(err) {
				return "", loiO(map[string]string{"account_ref": "Không có tài khoản hoá đơn điện tử này trong SePay"})
			}

			return "", err
		}
		if !tk.Active {
			return "", loiO(map[string]string{"account_ref": "Tài khoản hoá đơn điện tử này đang tắt bên SePay"})
		}

		return tk.ID, nil
	}

	ds, err := sp.DanhSachTaiKhoan(ctx, token)
	if err != nil {
		return "", err
	}
	var bat []sepayinvoice.TaiKhoan
	for _, tk := range ds {
		if tk.Active {
			bat = append(bat, tk)
		}
	}

	switch len(bat) {
	case 0:
		return "", errors.New("sepay: tài khoản chưa có tài khoản hoá đơn điện tử nào đang hoạt động — đăng ký trên my.sepay.vn trước")
	case 1:
		return bat[0].ID, nil
	}

	ten := make([]string, 0, len(bat))
	for _, tk := range bat {
		ten = append(ten, tk.ID+" ("+tk.Provider+")")
	}

	return "", errors.New("sepay: tài khoản có " + strconv.Itoa(len(bat)) + " tài khoản hoá đơn đang hoạt động (" +
		strings.Join(ten, ", ") + ") — liên hệ hỗ trợ để chọn tài khoản phát hành")
}

// mauSePay kéo danh sách mẫu / ký hiệu của tài khoản nhà cung cấp đã chốt.
func (s *etaxService) mauSePay(ctx context.Context, cn *domain.EtaxConnection) ([]domain.EtaxTemplate, error) {
	if strings.TrimSpace(cn.AccountRef) == "" {
		return nil, errors.New("sepay: kết nối chưa chốt tài khoản hoá đơn — hãy kết nối lại")
	}

	tk, err := s.sp.Cho(cn.Username).ChiTietTaiKhoan(ctx, cn.Token, cn.AccountRef)
	if err != nil {
		return nil, err
	}

	ds := make([]domain.EtaxTemplate, 0, len(tk.Mau))
	for _, m := range tk.Mau {
		maMau, kyHieu := strings.TrimSpace(m.MaMau), strings.TrimSpace(m.KyHieu)
		if kyHieu == "" {
			continue
		}
		ds = append(ds, domain.EtaxTemplate{
			Symbol:   maMau + kyHieu,
			FormNo:   maMau,
			TypeName: tenLoaiSePay(m.Nhan),
		})
	}

	return ds, nil
}

// tenLoaiSePay cắt "1 - C26TSE - Hóa đơn giá trị gia tăng" về phần tên loại:
// mẫu số và ký hiệu đã có cột riêng, in lại là thừa.
func tenLoaiSePay(nhan string) string {
	phan := strings.SplitN(nhan, " - ", 3)
	if len(phan) == 3 {
		return strings.TrimSpace(phan[2])
	}

	return strings.TrimSpace(nhan)
}

// ---------------------------------------------------------------------
// Phát hành
// ---------------------------------------------------------------------

type nguoiMuaSePay struct {
	Type      string `json:"type"`
	Name      string `json:"name,omitempty"`
	LegalName string `json:"legal_name,omitempty"`
	TaxCode   string `json:"tax_code,omitempty"`
	Address   string `json:"address,omitempty"`
	Email     string `json:"email,omitempty"`
	Phone     string `json:"phone,omitempty"`
}

type dongSePay struct {
	LineNumber     int     `json:"line_number"`
	LineType       int     `json:"line_type"`
	ItemCode       string  `json:"item_code,omitempty"`
	ItemName       string  `json:"item_name"`
	Quantity       int     `json:"quantity"`
	UnitPrice      float64 `json:"unit_price"`
	DiscountAmount float64 `json:"discount_amount,omitempty"`
	// Hai trường thuế chỉ gửi với mẫu GTGT: mẫu bán hàng thì cổng bỏ qua chúng.
	TaxRate   *int   `json:"tax_rate,omitempty"`
	TaxAmount *int64 `json:"tax_amount,omitempty"`
}

type hoaDonSePay struct {
	TemplateCode      string        `json:"template_code"`
	InvoiceSeries     string        `json:"invoice_series"`
	IssuedDate        string        `json:"issued_date"`
	Currency          string        `json:"currency"`
	ProviderAccountID string        `json:"provider_account_id"`
	ReferenceCode     string        `json:"reference_code"`
	PaymentMethod     string        `json:"payment_method"`
	IsDraft           bool          `json:"is_draft"`
	Buyer             nguoiMuaSePay `json:"buyer"`
	Items             []dongSePay   `json:"items"`
	Notes             string        `json:"notes,omitempty"`
	// TotalAmount gửi TƯỜNG MINH: cổng dùng đúng con số này, nên tổng trên hoá
	// đơn khớp từng đồng với số quầy đã thu thay vì để cổng tự làm tròn lại.
	TotalAmount int64 `json:"total_amount"`
}

// mauDangChon trả mẫu ứng với ký hiệu chi nhánh đang chọn.
func mauDangChon(cn *domain.EtaxConnection) (domain.EtaxTemplate, bool) {
	for _, m := range cn.Templates {
		if m.Symbol == cn.TemplateSymbol {
			return m, true
		}
	}

	return domain.EtaxTemplate{}, false
}

// thueSuatSePay đổi quy ước Product.VAT sang bảng thuế suất của SePay.
//
// HAI MÃ ÂM BỊ ĐẢO so với M-Invoice: SePay dùng -2 cho KHÔNG CHỊU THUẾ và -1
// cho KHÔNG KÊ KHAI, còn Product.VAT (và M-Invoice) thì ngược lại.
func thueSuatSePay(muc int) int {
	switch {
	case muc == mucKhongChiuThue:
		return -2
	case muc == mucKhongKeKhai:
		return -1
	case muc < 0:
		return 0
	}

	return muc
}

// dungHoaDonSePay dựng thân yêu cầu tạo hoá đơn, và trả kèm tổng tiền thuế.
func dungHoaDonSePay(don *domain.Order, ketNoi *domain.EtaxConnection, mau domain.EtaxTemplate, ds []dongHoaDon, gio time.Time) (*hoaDonSePay, float64) {
	coThue := strings.HasPrefix(ketNoi.TemplateSymbol, kyHieuGTGT)

	dong := make([]dongSePay, 0, len(ds))
	var tongThue, tongChuaThue float64
	for i, x := range ds {
		d := dongSePay{
			LineNumber:     i + 1,
			LineType:       1,
			ItemCode:       x.Ma,
			ItemName:       x.Ten,
			Quantity:       x.SoLuong,
			UnitPrice:      x.DonGia,
			DiscountAmount: x.Bot,
		}
		if coThue {
			ts := thueSuatSePay(x.MucThue)
			tien := int64(lamTron(x.TienThue))
			d.TaxRate, d.TaxAmount = &ts, &tien
			tongThue += x.TienThue
		}
		dong = append(dong, d)
		tongChuaThue += x.ChuaThue
	}

	nguoiMua := nguoiMuaSePay{
		Type:    "personal",
		Name:    strings.TrimSpace(don.RecipientName),
		Address: diaChiNguoiMua(don),
		Email:   strings.TrimSpace(don.RecipientEmail),
		Phone:   strings.TrimSpace(don.RecipientPhone),
	}
	if mst := strings.TrimSpace(don.BuyerTaxCode); mst != "" {
		nguoiMua.Type = "company"
		nguoiMua.TaxCode = mst
		nguoiMua.LegalName = strings.TrimSpace(don.BuyerCompany)
	}

	return &hoaDonSePay{
		TemplateCode:      mau.FormNo,
		InvoiceSeries:     strings.TrimPrefix(mau.Symbol, mau.FormNo),
		IssuedDate:        gio.In(gioVN).Format("2006-01-02 15:04:05"),
		Currency:          "VND",
		ProviderAccountID: ketNoi.AccountRef,
		ReferenceCode:     don.OrderCode,
		PaymentMethod:     tenPhuongThuc(don.PaymentMethod),
		// Không bật Tự phát hành thì lưu NHÁP — cùng nghĩa với M-Invoice: người bán
		// xem lại rồi bấm Ký ở màn Đơn hàng.
		IsDraft:     !ketNoi.AutoRelease,
		Buyer:       nguoiMua,
		Items:       dong,
		Notes:       "Đơn " + don.OrderCode,
		TotalAmount: int64(lamTron(tongChuaThue + tongThue)),
	}, lamTron(tongThue)
}

// gioVN — SePay chỉ nhận ngày lập là HÔM NAY theo giờ Việt Nam; máy chủ chạy
// UTC thì từ 0h tới 7h sáng ngày theo UTC vẫn là hôm qua.
var gioVN = func() *time.Location {
	if l, err := time.LoadLocation("Asia/Ho_Chi_Minh"); err == nil {
		return l
	}

	return time.FixedZone("ICT", 7*3600)
}()

// phatHanhSePay là nhánh SePay của PhatHanh — các chốt chặn đã qua ở đó.
func (s *etaxService) phatHanhSePay(ctx context.Context, don *domain.Order, cu *domain.EtaxInvoice, ketNoi *domain.EtaxConnection) (*domain.EtaxInvoice, error) {
	mau, co := mauDangChon(ketNoi)
	if !co {
		return nil, domain.ErrETaxKyHieuLa
	}

	ds, err := s.dongTuDon(ctx, don)
	if err != nil {
		return nil, err
	}
	than, tienThue := dungHoaDonSePay(don, ketNoi, mau, ds, time.Now())

	ghi := &domain.EtaxInvoice{
		ShopID:       don.ShopID,
		OrderID:      don.ID,
		ConnectionID: &ketNoi.ID,
		Provider:     ketNoi.Provider,
		Symbol:       ketNoi.TemplateSymbol,
		InvoiceID:    than.ReferenceCode,
		TotalAmount:  don.TotalAmount,
		VatAmount:    tienThue,
	}
	if cu != nil {
		ghi.ID = cu.ID
		ghi.CreatedAt = cu.CreatedAt
	}
	if du, err := json.Marshal(than); err == nil {
		ghi.Payload = domain.StringOrNull(du)
	}

	var yc *sepayinvoice.YeuCau
	err = s.thuLaiSePay(ctx, ketNoi, func() error {
		var loi error
		yc, loi = s.sp.Cho(ketNoi.Username).TaoHoaDon(ctx, ketNoi.Token, than)

		return loi
	})
	if sepayinvoice.LaDaCo(err) {
		// Cổng đã có tờ cho mã đơn này — lượt trước gửi tới nơi nhưng bên mình
		// không ghi được kết quả. Nhận lại tờ đó thay vì báo lỗi.
		if h, loi := s.chiTietSePay(ctx, ketNoi, than.ReferenceCode); loi == nil {
			apDungHoaDonSePay(ghi, h)

			return s.luuHoaDonSePay(ctx, ghi, ketNoi)
		}
	}
	if err != nil {
		ghi.Status = domain.HoaDonHong
		ghi.Error = catNgan(err.Error(), 500)
		_ = s.repo.LuuHoaDon(ctx, ghi)

		return nil, err
	}

	vet := vetSePay{MaTao: yc.MaTheoDoi}
	kq := s.choKetQua(ctx, ketNoi, func() (*sepayinvoice.TrangThaiXuLy, error) {
		return s.sp.Cho(ketNoi.Username).KiemTraTao(ctx, ketNoi.Token, yc.MaTheoDoi)
	})
	if err := s.apDungKetQua(ctx, ketNoi, ghi, &vet, kq); err != nil {
		return nil, err
	}

	return s.luuHoaDonSePay(ctx, ghi, ketNoi)
}

// kySePay phát hành một tờ đang là nháp.
func (s *etaxService) kySePay(ctx context.Context, hd *domain.EtaxInvoice, ketNoi *domain.EtaxConnection) (*domain.EtaxInvoice, error) {
	var yc *sepayinvoice.YeuCau
	err := s.thuLaiSePay(ctx, ketNoi, func() error {
		var loi error
		yc, loi = s.sp.Cho(ketNoi.Username).PhatHanhNhap(ctx, ketNoi.Token, hd.InvoiceID)

		return loi
	})
	if err != nil {
		hd.Error = catNgan(err.Error(), 500)
		_ = s.repo.LuuHoaDon(ctx, hd)

		return nil, err
	}

	vet := docVetSePay(hd)
	vet.MaPhatHanh = yc.MaTheoDoi
	kq := s.choKetQua(ctx, ketNoi, func() (*sepayinvoice.TrangThaiXuLy, error) {
		return s.sp.Cho(ketNoi.Username).KiemTraPhatHanh(ctx, ketNoi.Token, yc.MaTheoDoi)
	})
	if err := s.apDungKetQua(ctx, ketNoi, hd, &vet, kq); err != nil {
		return nil, err
	}

	return s.luuHoaDonSePay(ctx, hd, ketNoi)
}

// dongBoSePay hỏi lại cổng: xem tờ hoá đơn theo mã đơn; cổng chưa có tờ nào thì
// hỏi tiếp yêu cầu đang chờ (nếu có) để biết nó còn chạy hay đã hỏng.
func (s *etaxService) dongBoSePay(ctx context.Context, hd *domain.EtaxInvoice, ketNoi *domain.EtaxConnection) (*domain.EtaxInvoice, error) {
	h, err := s.chiTietSePay(ctx, ketNoi, hd.InvoiceID)
	if err == nil {
		apDungHoaDonSePay(hd, h)
		if err := s.repo.LuuHoaDon(ctx, hd); err != nil {
			return nil, err
		}

		return hd, nil
	}
	if !sepayinvoice.LaKhongThay(err) {
		return nil, err
	}

	vet := docVetSePay(hd)
	var kiemTra func() (*sepayinvoice.TrangThaiXuLy, error)
	switch {
	case vet.MaPhatHanh != "":
		kiemTra = func() (*sepayinvoice.TrangThaiXuLy, error) {
			return s.sp.Cho(ketNoi.Username).KiemTraPhatHanh(ctx, ketNoi.Token, vet.MaPhatHanh)
		}
	case vet.MaTao != "":
		kiemTra = func() (*sepayinvoice.TrangThaiXuLy, error) {
			return s.sp.Cho(ketNoi.Username).KiemTraTao(ctx, ketNoi.Token, vet.MaTao)
		}
	default:
		return nil, errors.New("sepay: chưa ghi nhận hoá đơn nào cho đơn này — hãy phát hành lại")
	}

	var kq *sepayinvoice.TrangThaiXuLy
	if err := s.thuLaiSePay(ctx, ketNoi, func() error {
		var loi error
		kq, loi = kiemTra()

		return loi
	}); err != nil {
		return nil, err
	}
	if kq.TrangThai == sepayinvoice.DangXuLy {
		return nil, errors.New("sepay: còn đang xử lý hoá đơn này — thử lại sau ít phút")
	}
	if err := s.apDungKetQua(ctx, ketNoi, hd, &vet, kq); err != nil {
		return nil, err
	}
	if err := s.repo.LuuHoaDon(ctx, hd); err != nil {
		return nil, err
	}

	return hd, nil
}

// tepSePay tải bản PDF / XML. Đường tải nhận "mã theo dõi của hoá đơn"; tài liệu
// không nói rõ là mã của lượt nào, nên thử mã đơn trước rồi tới hai mã đã cất.
func (s *etaxService) tepSePay(ctx context.Context, hd *domain.EtaxInvoice, ketNoi *domain.EtaxConnection, loai string) ([]byte, error) {
	vet := docVetSePay(hd)

	var loiDau error
	for _, ma := range []string{hd.InvoiceID, vet.MaTao, vet.MaPhatHanh} {
		if strings.TrimSpace(ma) == "" {
			continue
		}
		var du []byte
		err := s.thuLaiSePay(ctx, ketNoi, func() error {
			var loi error
			du, loi = s.sp.Cho(ketNoi.Username).TaiTep(ctx, ketNoi.Token, ma, loai)

			return loi
		})
		if err == nil {
			return du, nil
		}
		if loiDau == nil {
			loiDau = err
		}
		if !sepayinvoice.LaKhongThay(err) {
			break
		}
	}

	return nil, loiDau
}

// ---------------------------------------------------------------------
// Phần dùng chung của nhánh SePay
// ---------------------------------------------------------------------

// thuLaiSePay chạy một lượt gọi; token hết hạn (401) thì đăng nhập lại rồi thử
// ĐÚNG một lần nữa. Khác thuLai của M-Invoice: lỗi khác 401 KHÔNG gọi lại, vì
// gọi lại một lượt tạo hoá đơn bị từ chối vì dữ liệu chỉ đổi lấy cùng câu trả lời.
func (s *etaxService) thuLaiSePay(ctx context.Context, cn *domain.EtaxConnection, chay func() error) error {
	err := chay()
	if !sepayinvoice.LaHetToken(err) {
		return err
	}
	if loi := s.lamMoiToken(ctx, cn); loi != nil {
		return err
	}

	return chay()
}

func (s *etaxService) chiTietSePay(ctx context.Context, cn *domain.EtaxConnection, ma string) (*sepayinvoice.HoaDon, error) {
	var h *sepayinvoice.HoaDon
	err := s.thuLaiSePay(ctx, cn, func() error {
		var loi error
		h, loi = s.sp.Cho(cn.Username).ChiTiet(ctx, cn.Token, ma)

		return loi
	})

	return h, err
}

// choKetQua hỏi kết quả một yêu cầu cho tới khi xong hoặc hết choSePay. Lỗi khi
// hỏi KHÔNG làm hỏng lượt gửi: cổng đã nhận yêu cầu rồi, mình chỉ chưa biết kết
// quả — trả nil để nơi gọi ghi "đã gửi" và để nút Đồng bộ hỏi sau.
func (s *etaxService) choKetQua(ctx context.Context, cn *domain.EtaxConnection, kiemTra func() (*sepayinvoice.TrangThaiXuLy, error)) *sepayinvoice.TrangThaiXuLy {
	het := time.Now().Add(choSePay)
	var kq *sepayinvoice.TrangThaiXuLy
	for {
		err := s.thuLaiSePay(ctx, cn, func() error {
			var loi error
			kq, loi = kiemTra()

			return loi
		})
		if err == nil && kq.TrangThai != sepayinvoice.DangXuLy {
			return kq
		}
		if time.Now().Add(nhipHoiSePay).After(het) {
			return kq
		}

		select {
		case <-ctx.Done():
			return kq
		case <-time.After(nhipHoiSePay):
		}
	}
}

// apDungKetQua ghi kết quả một yêu cầu tạo / phát hành vào tờ hoá đơn.
//
// Thất bại thì vẫn LƯU tờ (để còn dấu vết lỗi) rồi trả lỗi cho người bấm.
func (s *etaxService) apDungKetQua(ctx context.Context, ketNoi *domain.EtaxConnection, hd *domain.EtaxInvoice, vet *vetSePay, kq *sepayinvoice.TrangThaiXuLy) error {
	if kq != nil {
		vet.TraLoi = kq.Tho
	}
	vet.ghiVao(hd)

	switch {
	case kq == nil || kq.TrangThai == sepayinvoice.DangXuLy:
		// Cổng đã nhận nhưng chưa xong: "đã gửi", nút Đồng bộ hỏi tiếp.
		hd.Status = domain.HoaDonDaGui
		hd.Error = ""

	case kq.TrangThai == sepayinvoice.ThatBai:
		hd.Status = domain.HoaDonHong
		hd.Error = catNgan(kq.ThongDiep, 500)
		if hd.Error == "" {
			hd.Error = "SePay báo phát hành không thành công"
		}
		_ = s.repo.LuuHoaDon(ctx, hd)

		return fmt.Errorf("sepay: từ chối hoá đơn: %s", hd.Error)

	default:
		hd.Error = ""
		if kq.HoaDon != nil {
			apDungHoaDonSePay(hd, kq.HoaDon)
		}
		// Lượt /check không trả mã cơ quan thuế; tờ đã phát hành thì xem chi tiết
		// để lấy nó. Hỏng thì thôi — còn nút Đồng bộ.
		if hd.Status != domain.HoaDonNhap {
			if h, err := s.chiTietSePay(ctx, ketNoi, hd.InvoiceID); err == nil {
				apDungHoaDonSePay(hd, h)
			}
		}
	}

	return nil
}

// apDungHoaDonSePay đổi trạng thái của cổng thành trạng thái trong sổ.
//
// `status` của SePay chỉ nói nháp / đã phát hành / đã huỷ; còn cơ quan thuế đã
// cấp mã chưa thì phải đọc `provider_status` của nhà cung cấp phía sau.
func apDungHoaDonSePay(hd *domain.EtaxInvoice, h *sepayinvoice.HoaDon) {
	if so := strings.TrimSpace(string(h.SoHoaDon)); so != "" && so != "0" {
		hd.InvoiceNo = so
	}
	if h.MaCQT != "" {
		hd.TaxAuthCode = h.MaCQT
	}
	ncc := strings.TrimSpace(string(h.TrangThaiNCC))
	if n, err := strconv.Atoi(ncc); err == nil {
		hd.GatewayStatus = &n
	}
	if hd.IssuedAt == nil {
		gio := time.Now()
		hd.IssuedAt = &gio
	}

	switch h.TrangThai {
	case "draft":
		hd.Status = domain.HoaDonNhap
	case "cancelled":
		hd.Status = domain.HoaDonHong
		hd.Error = "hoá đơn đã bị huỷ trên SePay"
	case "issued":
		switch {
		// 2 lỗi ký, 6 không đủ điều kiện cấp mã.
		case ncc == "2" || ncc == "6":
			hd.Status = domain.HoaDonHong
			hd.Error = "nhà cung cấp báo lỗi ký hoặc không đủ điều kiện cấp mã — xem chi tiết trên my.sepay.vn"
		// Có mã CQT, hoặc 4 đã cấp mã, 9 CQT đã duyệt; lượt /check không có
		// provider_status thì tin "issued" của SePay.
		case hd.TaxAuthCode != "" || ncc == "4" || ncc == "9" || ncc == "":
			hd.Status = domain.HoaDonDaPhatHanh
			hd.Error = ""
		default:
			hd.Status = domain.HoaDonDaGui
		}
	}
}

func (s *etaxService) luuHoaDonSePay(ctx context.Context, hd *domain.EtaxInvoice, ketNoi *domain.EtaxConnection) (*domain.EtaxInvoice, error) {
	if err := s.repo.LuuHoaDon(ctx, hd); err != nil {
		return nil, err
	}
	hd.AutoPrint = ketNoi.AutoPrint

	return hd, nil
}
