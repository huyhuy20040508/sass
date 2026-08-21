package service

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"strings"
	"time"

	"sass-api/internal/domain"
	"sass-api/internal/dto"
	"sass-api/pkg/minvoice"
)

// ĐỜI SAU CỦA MỘT TỜ HOÁ ĐƠN: ký, tra lại trạng thái, thay thế, điều chỉnh,
// lấy bản in và bản XML.
//
// Việc dựng tờ hoá đơn đầu tiên từ một đơn hàng nằm ở etax_phat_hanh.go.
//
// LUẬT CHUNG CỦA CẢ TỆP: cơ quan thuế chỉ cho sửa một tờ ĐÃ ĐƯỢC CẤP MÃ, và
// sửa nghĩa là phát hành một tờ MỚI đè lên chỗ của tờ cũ chứ không phải sửa tại
// chỗ. Tờ cũ vẫn nằm trong sổ của cơ quan thuế và khách vẫn giữ bản in của nó,
// nên số của nó đi vào `history` chứ không biến mất.

// Ky ký một tờ đã lưu nháp rồi gửi cơ quan thuế.
func (s *etaxService) Ky(ctx context.Context, orderID uint) (*domain.EtaxInvoice, error) {
	hd, ketNoi, err := s.hoaDonVaKetNoi(ctx, orderID)
	if err != nil {
		return nil, err
	}
	if hd.Status == domain.HoaDonDaGui || hd.Status == domain.HoaDonDaPhatHanh {
		return nil, domain.ErrHoaDonDaKy
	}

	err = s.thuLai(ctx, ketNoi, func() error {
		return s.mi.Ky(ctx, ketNoi.TaxCode, ketNoi.Token, hd.InvoiceID)
	})
	if err != nil {
		hd.Error = catNgan(err.Error(), 500)
		_ = s.repo.LuuHoaDon(ctx, hd)

		return nil, err
	}

	gio := time.Now()
	hd.Error = ""
	hd.Status = domain.HoaDonDaGui
	hd.IssuedAt = &gio
	s.dongBoTuCong(ctx, ketNoi, hd)

	if err := s.repo.LuuHoaDon(ctx, hd); err != nil {
		return nil, err
	}
	hd.AutoPrint = ketNoi.AutoPrint

	return hd, nil
}

// DongBo tra lại tờ hoá đơn ở cổng và ghi trạng thái mới vào sổ.
//
// Đây là đường người dùng bấm khi màn hình còn nói "đã gửi": cơ quan thuế cấp
// mã không tức thì, và không có ai báo về nên phải tự hỏi lại.
func (s *etaxService) DongBo(ctx context.Context, orderID uint) (*domain.EtaxInvoice, error) {
	hd, ketNoi, err := s.hoaDonVaKetNoi(ctx, orderID)
	if err != nil {
		return nil, err
	}

	truoc := hd.Status
	s.dongBoTuCong(ctx, ketNoi, hd)
	if hd.Status == truoc && hd.TaxAuthCode == "" && hd.GatewayStatus == nil {
		return nil, fmt.Errorf("chưa hỏi được cổng về hoá đơn này — thử lại sau ít phút")
	}

	if err := s.repo.LuuHoaDon(ctx, hd); err != nil {
		return nil, err
	}

	return hd, nil
}

// ThayThe phát hành một tờ THAY CHO tờ hiện tại, dựng lại từ đơn hàng HÔM NAY.
//
// Đây là đường cho ca hay gặp nhất: hoá đơn sai thông tin khách hoặc sai dòng
// hàng, người bán sửa đơn rồi xuất lại. Tờ mới mang toàn bộ nội dung mới, tờ cũ
// bị cơ quan thuế đánh dấu "bị thay thế".
func (s *etaxService) ThayThe(ctx context.Context, orderID uint, req *dto.EtaxThayTheRequest) (*domain.EtaxInvoice, error) {
	hd, ketNoi, err := s.hoaDonVaKetNoi(ctx, orderID)
	if err != nil {
		return nil, err
	}
	if err := coTheSua(hd); err != nil {
		return nil, err
	}
	// Chỉ tờ Gốc và tờ Thay thế mới thay tiếp được — luật của cơ quan thuế, và
	// cổng từ chối bằng mã 29508 nếu mình gửi bừa.
	if hd.DocStatus != nil && *hd.DocStatus != domain.ToGoc && *hd.DocStatus != domain.ToThayThe {
		return nil, domain.ErrHoaDonKhongThayTheDuoc
	}

	don, err := s.donHang.FindByID(ctx, orderID)
	if err != nil {
		return nil, err
	}
	chiNhanh, err := s.chiNhanh.FindByID(ctx, don.ShopID)
	if err != nil {
		return nil, err
	}

	moi, tienThue, err := s.dungHoaDon(ctx, don, ketNoi, chiNhanh)
	if err != nil {
		return nil, err
	}
	moi["inv_originalId"] = hd.InvoiceID
	moi["ghi_chu"] = strings.TrimSpace(req.LyDo)
	moi["ngayvb"] = time.Now().Format("2006-01-02")
	if sv := strings.TrimSpace(req.SoVanBan); sv != "" {
		moi["sovb"] = sv
	}
	// key_api đã dùng cho tờ gốc rồi; gửi lại cùng một mã là cổng bắt trùng.
	moi["key_api"] = don.OrderCode + "-TT" + time.Now().Format("150405")

	return s.doiToHoaDon(ctx, hd, ketNoi, moi, tienThue, "thay thế", func() (*minvoice.KetQuaPhatHanh, error) {
		return s.mi.ThayThe(ctx, ketNoi.TaxCode, ketNoi.Token, moi)
	})
}

// DieuChinh phát hành một tờ ĐIỀU CHỈNH cho tờ hiện tại.
//
// Không có dòng nào trong yêu cầu = điều chỉnh VỀ 0, tương đương huỷ tờ cũ:
// dựng lại đúng các dòng đã gửi rồi đảo dấu, kèm một dòng diễn giải nói rõ đang
// điều chỉnh tờ nào. Cách ghi này M-Invoice có hướng dẫn nhưng nói trước là
// không nằm trong quy định của cơ quan thuế — người dùng hỏi cơ quan thuế của
// mình trước khi dùng.
func (s *etaxService) DieuChinh(ctx context.Context, orderID uint, req *dto.EtaxDieuChinhRequest) (*domain.EtaxInvoice, error) {
	hd, ketNoi, err := s.hoaDonVaKetNoi(ctx, orderID)
	if err != nil {
		return nil, err
	}
	if err := coTheSua(hd); err != nil {
		return nil, err
	}

	dong, tienThue, err := dongDieuChinh(hd, req)
	if err != nil {
		return nil, err
	}

	than := map[string]any{
		"inv_InvoiceAuth_id":    hd.InvoiceID,
		"inv_invoiceIssuedDate": time.Now().Format("2006-01-02"),
		"ghi_chu":               strings.TrimSpace(req.LyDo),
		"data":                  dong,
	}

	return s.doiToHoaDon(ctx, hd, ketNoi, than, tienThue, "điều chỉnh", func() (*minvoice.KetQuaPhatHanh, error) {
		return s.mi.DieuChinh(ctx, ketNoi.TaxCode, ketNoi.Token, than)
	})
}

// BanIn tải bản PDF của hoá đơn một đơn hàng.
func (s *etaxService) BanIn(ctx context.Context, orderID uint, chuyenDoi bool) ([]byte, error) {
	hd, ketNoi, err := s.hoaDonVaKetNoi(ctx, orderID)
	if err != nil {
		return nil, err
	}

	var du []byte
	err = s.thuLai(ctx, ketNoi, func() error {
		var loi error
		du, loi = s.mi.BanIn(ctx, ketNoi.TaxCode, ketNoi.Token, ketNoi.MaDVCS, hd.InvoiceID, chuyenDoi)

		return loi
	})
	if err != nil {
		return nil, err
	}

	return du, nil
}

// BanXML tải bản XML gốc đã ký — thứ kế toán nộp và lưu trữ.
func (s *etaxService) BanXML(ctx context.Context, orderID uint) ([]byte, error) {
	hd, ketNoi, err := s.hoaDonVaKetNoi(ctx, orderID)
	if err != nil {
		return nil, err
	}
	// Tờ nháp chưa ký thì chưa có bản XML nào — nói thẳng thay vì để cổng trả về
	// một câu khó hiểu.
	if hd.Status == domain.HoaDonNhap {
		return nil, domain.ErrHoaDonChuaKy
	}

	var du []byte
	err = s.thuLai(ctx, ketNoi, func() error {
		var loi error
		du, loi = s.mi.BanXML(ctx, ketNoi.TaxCode, ketNoi.Token, hd.InvoiceID)

		return loi
	})
	if err != nil {
		return nil, err
	}

	return du, nil
}

// TraCuuMST tra tên và địa chỉ đăng ký của một mã số thuế.
//
// Đi thẳng máy chủ tra cứu dùng chung của M-Invoice, KHÔNG cần kết nối của chi
// nhánh nào: nó chỉ đọc dữ liệu công khai của cơ quan thuế.
func (s *etaxService) TraCuuMST(ctx context.Context, mst string) (*minvoice.ThongTinMST, error) {
	return s.mi.TraCuuMST(ctx, mst)
}

// ---------------------------------------------------------------------
// Phần dùng chung
// ---------------------------------------------------------------------

// hoaDonVaKetNoi lấy tờ hoá đơn của một đơn cùng kết nối đã phát hành nó, và
// chặn sẵn ba ca không làm gì tiếp được: đơn chưa có hoá đơn, hoá đơn chưa có
// mã bên cổng, chi nhánh đã ngắt kết nối.
func (s *etaxService) hoaDonVaKetNoi(ctx context.Context, orderID uint) (*domain.EtaxInvoice, *domain.EtaxConnection, error) {
	hd, err := s.XemHoaDon(ctx, orderID)
	if err != nil {
		if errors.Is(err, domain.ErrNotFound) {
			return nil, nil, domain.ErrHoaDonChuaLap
		}

		return nil, nil, err
	}
	if strings.TrimSpace(hd.InvoiceID) == "" {
		return nil, nil, domain.ErrHoaDonThieuMa
	}

	ketNoi, err := s.repo.TheoChiNhanh(ctx, hd.ShopID)
	if err != nil {
		if errors.Is(err, domain.ErrNotFound) {
			return nil, nil, domain.ErrETaxChuaNoi
		}

		return nil, nil, err
	}

	return hd, ketNoi, nil
}

// coTheSua kiểm tờ hoá đơn đã đủ điều kiện thay thế / điều chỉnh chưa.
func coTheSua(hd *domain.EtaxInvoice) error {
	if hd.Status != domain.HoaDonDaPhatHanh || strings.TrimSpace(hd.TaxAuthCode) == "" {
		return domain.ErrHoaDonKhongSuaDuoc
	}

	return nil
}

// thuLai chạy một lượt gọi cổng, hỏng thì đăng nhập lại rồi thử ĐÚNG một lần
// nữa. Token của cổng sống ngắn, và bắt người dùng bấm lại vì hết token là bắt
// họ làm việc của máy.
//
// Đăng nhập lại không được thì trả về lỗi ĐẦU TIÊN: "cổng từ chối vì sai dữ
// liệu" có ích hơn nhiều so với "đăng nhập không thành công".
func (s *etaxService) thuLai(ctx context.Context, cn *domain.EtaxConnection, chay func() error) error {
	err := chay()
	if err == nil {
		return nil
	}
	if loi := s.lamMoiToken(ctx, cn); loi != nil {
		return err
	}

	return chay()
}

// dongBoTuCong tra lại tờ hoá đơn ở cổng rồi ghi trạng thái mới vào bản ghi.
//
// KHÔNG trả lỗi: đây luôn là việc phụ đi kèm một việc chính (vừa ký xong, vừa
// phát hành xong). Cổng không trả lời thì bản ghi giữ nguyên trạng thái cũ và
// người dùng bấm "Đồng bộ" sau, chứ không làm hỏng lượt vừa rồi.
func (s *etaxService) dongBoTuCong(ctx context.Context, cn *domain.EtaxConnection, hd *domain.EtaxInvoice) {
	if strings.TrimSpace(hd.InvoiceID) == "" {
		return
	}

	var tt *minvoice.ThongTinHoaDon
	err := s.thuLai(ctx, cn, func() error {
		var loi error
		tt, loi = s.mi.TraHoaDon(ctx, cn.TaxCode, cn.Token, hd.InvoiceID)

		return loi
	})
	if err != nil || tt == nil {
		return
	}

	apDungTrangThai(hd, tt)
}

// apDungTrangThai đổi thứ cổng vừa nói thành trạng thái trong sổ của mình.
//
// MÃ CƠ QUAN THUẾ là thứ quyết định, không phải `trang_thai`: có mã nghĩa là tờ
// hoá đơn đã có hiệu lực, dù cổng còn đang nói gì đi nữa.
func apDungTrangThai(hd *domain.EtaxInvoice, tt *minvoice.ThongTinHoaDon) {
	if tt.SoHoaDon != "" {
		hd.InvoiceNo = tt.SoHoaDon
	}
	if tt.MaCQT != "" {
		hd.TaxAuthCode = tt.MaCQT
	}
	if tt.MaTraCuu != "" {
		hd.LookupCode = tt.MaTraCuu
	}
	if tt.TrangThai >= 0 {
		tr := tt.TrangThai
		hd.GatewayStatus = &tr
	}
	if tt.TrangThaiHD >= 0 {
		tr := tt.TrangThaiHD
		hd.DocStatus = &tr
	}

	switch {
	case hd.TaxAuthCode != "" || tt.TrangThai == minvoice.TrangThaiThanhCong:
		hd.Status = domain.HoaDonDaPhatHanh
		hd.Error = ""
	case tt.TrangThai == minvoice.TrangThaiCoLoi:
		hd.Status = domain.HoaDonHong
		if hd.Error == "" {
			hd.Error = "cổng báo hoá đơn có lỗi — mở trang nhà cung cấp để xem chi tiết"
		}
	case tt.TrangThai == minvoice.TrangThaiDaKy ||
		tt.TrangThai == minvoice.TrangThaiDaGui ||
		tt.TrangThai == minvoice.TrangThaiDangKy:
		hd.Status = domain.HoaDonDaGui
	case tt.TrangThai == minvoice.TrangThaiChoDuyet || tt.TrangThai == minvoice.TrangThaiChoKy:
		hd.Status = domain.HoaDonNhap
	}
}

// doiToHoaDon gửi một lượt thay thế / điều chỉnh rồi ghi tờ MỚI đè lên bản ghi
// cũ, sau khi đã cất tờ cũ vào `history`.
func (s *etaxService) doiToHoaDon(
	ctx context.Context,
	hd *domain.EtaxInvoice,
	ketNoi *domain.EtaxConnection,
	payload map[string]any,
	tienThue float64,
	viec string,
	goi func() (*minvoice.KetQuaPhatHanh, error),
) (*domain.EtaxInvoice, error) {
	var kq *minvoice.KetQuaPhatHanh
	err := s.thuLai(ctx, ketNoi, func() error {
		var loi error
		kq, loi = goi()

		return loi
	})
	if err != nil {
		// KHÔNG đổi trạng thái bản ghi: tờ cũ vẫn đang có hiệu lực, lượt sửa mới
		// là thứ hỏng. Ghi lỗi để người dùng đọc rồi thôi.
		hd.Error = catNgan(err.Error(), 500)
		_ = s.repo.LuuHoaDon(ctx, hd)

		return nil, fmt.Errorf("%s hoá đơn không thành công: %w", viec, err)
	}

	catToCu(hd, viec)

	gio := time.Now()
	hd.Error = ""
	hd.InvoiceNo = kq.SoHoaDon
	hd.InvoiceID = kq.MaHoaDon
	// Tờ mới chưa có mã của riêng nó — xoá mã của tờ cũ đi, để trống là đúng cho
	// tới lượt tra tiếp theo.
	hd.TaxAuthCode = ""
	hd.LookupCode = ""
	hd.GatewayStatus = nil
	hd.DocStatus = nil
	hd.Status = domain.HoaDonDaGui
	hd.VatAmount = tienThue
	hd.IssuedAt = &gio
	hd.Response = domain.StringOrNull(kq.Tho)
	if du, err := json.Marshal(payload); err == nil {
		hd.Payload = domain.StringOrNull(du)
	}

	s.dongBoTuCong(ctx, ketNoi, hd)

	if err := s.repo.LuuHoaDon(ctx, hd); err != nil {
		return nil, err
	}
	hd.AutoPrint = ketNoi.AutoPrint

	return hd, nil
}

// toCu là một tờ đời trước, cất trong cột `history`.
type toCu struct {
	Viec        string `json:"viec"`
	Symbol      string `json:"symbol"`
	InvoiceNo   string `json:"invoice_no"`
	InvoiceID   string `json:"invoice_id"`
	TaxAuthCode string `json:"tax_auth_code"`
	LookupCode  string `json:"lookup_code"`
	At          string `json:"at"`
}

// catToCu đẩy tờ đang giữ vào `history` trước khi tờ mới đè lên.
//
// Hỏng khi đọc history cũ thì BỎ nó đi chứ không dừng cả lượt sửa: giữ được sổ
// là tốt, nhưng một cột JSON méo không đáng để chặn một tờ hoá đơn đang phải ra.
func catToCu(hd *domain.EtaxInvoice, viec string) {
	var ds []toCu
	if s := strings.TrimSpace(string(hd.History)); s != "" {
		_ = json.Unmarshal([]byte(s), &ds)
	}

	ds = append(ds, toCu{
		Viec:        viec,
		Symbol:      hd.Symbol,
		InvoiceNo:   hd.InvoiceNo,
		InvoiceID:   hd.InvoiceID,
		TaxAuthCode: hd.TaxAuthCode,
		LookupCode:  hd.LookupCode,
		At:          time.Now().Format(time.RFC3339),
	})

	if du, err := json.Marshal(ds); err == nil {
		hd.History = domain.StringOrNull(du)
	}
}

// dongDieuChinh dựng mảng chi tiết của một tờ điều chỉnh.
//
// Không truyền dòng nào = ĐIỀU CHỈNH VỀ 0: đọc lại đúng các dòng đã gửi ở tờ
// gốc rồi đảo dấu. Đọc từ `payload` chứ không dựng lại từ đơn hàng hôm nay —
// đơn có thể đã bị sửa, và điều chỉnh phải trừ đi đúng thứ đã ghi trên tờ cũ.
func dongDieuChinh(hd *domain.EtaxInvoice, req *dto.EtaxDieuChinhRequest) ([]map[string]any, float64, error) {
	if len(req.Dong) > 0 {
		dong := make([]map[string]any, 0, len(req.Dong))
		var tongThue float64
		for i, d := range req.Dong {
			dong = append(dong, map[string]any{
				"tchat":                     1,
				"stt_rec0":                  i + 1,
				"inv_itemName":              d.TenHang,
				"inv_unitCode":              d.DonViTinh,
				"inv_quantity":              d.SoLuong,
				"inv_unitPrice":             d.DonGia,
				"inv_discountAmount":        0,
				"inv_Amount":                d.ThanhTien,
				"inv_TotalAmountWithoutVat": d.ThanhTien,
				"ma_thue":                   d.MaThue,
				"inv_vatAmount":             d.TienThue,
				"inv_TotalAmount":           d.ThanhTien + d.TienThue,
			})
			tongThue += d.TienThue
		}

		return dong, tongThue, nil
	}

	cu, err := dongDaGui(hd)
	if err != nil {
		return nil, 0, err
	}

	dong := make([]map[string]any, 0, len(cu)+1)
	var tongThue float64
	for i, d := range cu {
		dao := map[string]any{
			"tchat":                     1,
			"stt_rec0":                  i + 1,
			"inv_itemName":              d["inv_itemName"],
			"inv_unitCode":              d["inv_unitCode"],
			"inv_unitPrice":             d["inv_unitPrice"],
			"inv_quantity":              -so(d["inv_quantity"]),
			"inv_discountAmount":        0,
			"inv_Amount":                -so(d["inv_Amount"]),
			"inv_TotalAmountWithoutVat": -so(d["inv_TotalAmountWithoutVat"]),
			"inv_TotalAmount":           -so(d["inv_TotalAmount"]),
			"inv_vatAmount":             -so(d["inv_vatAmount"]),
		}
		if ma, co := d["ma_thue"]; co {
			dao["ma_thue"] = ma
		}
		dong = append(dong, dao)
		tongThue -= so(d["inv_vatAmount"])
	}

	// Dòng diễn giải (tchat 4) nói rõ đang điều chỉnh tờ nào — người đọc hoá đơn
	// điều chỉnh cần biết nó gắn với số nào.
	dong = append(dong, map[string]any{
		"tchat":        4,
		"stt_rec0":     len(dong) + 1,
		"inv_itemName": fmt.Sprintf("Điều chỉnh hoá đơn ký hiệu %s số %s về 0 giá trị", hd.Symbol, hd.InvoiceNo),
	})

	return dong, tongThue, nil
}

// dongDaGui đọc lại các dòng hàng từ payload đã gửi của tờ hiện tại.
//
// Ba hình dạng vì ba đường sinh ra nó: tờ phát hành và tờ thay thế lưu payload
// PHẲNG (`details[0].data`), tờ điều chỉnh lưu `data` là chính mảng dòng. Đọc
// hết cả ba thay vì đoán một cái — đây là dữ liệu đã nằm trong sổ, không dựng
// lại được nếu đọc sai.
func dongDaGui(hd *domain.EtaxInvoice) ([]map[string]any, error) {
	thieu := fmt.Errorf("không đọc được chi tiết của hoá đơn cũ nên chưa điều chỉnh về 0 được — hãy nhập tay các dòng cần điều chỉnh")

	du := strings.TrimSpace(string(hd.Payload))
	if du == "" {
		return nil, thieu
	}

	var goi struct {
		Details []struct {
			Data []map[string]any `json:"data"`
		} `json:"details"`
		Data json.RawMessage `json:"data"`
	}
	if err := json.Unmarshal([]byte(du), &goi); err != nil {
		return nil, thieu
	}

	if len(goi.Details) > 0 && len(goi.Details[0].Data) > 0 {
		return goi.Details[0].Data, nil
	}

	// `data` là mảng dòng (tờ điều chỉnh) hoặc mảng hoá đơn (payload đã bọc).
	var dong []map[string]any
	if err := json.Unmarshal(goi.Data, &dong); err == nil && len(dong) > 0 {
		if _, laHang := dong[0]["inv_itemName"]; laHang {
			return dong, nil
		}
		var boc []struct {
			Details []struct {
				Data []map[string]any `json:"data"`
			} `json:"details"`
		}
		if err := json.Unmarshal(goi.Data, &boc); err == nil &&
			len(boc) > 0 && len(boc[0].Details) > 0 && len(boc[0].Details[0].Data) > 0 {
			return boc[0].Details[0].Data, nil
		}
	}

	return nil, thieu
}

// so đọc một giá trị số ra float64, bất kể JSON trả về kiểu gì. Không đọc được
// thì 0 — một dòng điều chỉnh 0 đồng vô hại hơn một lượt gọi gãy.
func so(v any) float64 {
	switch n := v.(type) {
	case float64:
		return n
	case float32:
		return float64(n)
	case int:
		return float64(n)
	case int64:
		return float64(n)
	case json.Number:
		f, _ := n.Float64()

		return f
	}

	return 0
}
