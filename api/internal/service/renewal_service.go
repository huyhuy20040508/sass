package service

import (
	"context"
	"fmt"
	"strconv"
	"strings"
	"time"

	"go.uber.org/zap"

	"sass-api/config"
	"sass-api/internal/domain"
	"sass-api/internal/dto"
	"sass-api/pkg/bimat"
	"sass-api/pkg/logger"
	"sass-api/pkg/payos"
)

// GiaHanService — KHÁCH TỰ GIA HẠN: bấm gói, trả tiền qua cổng, hợp đồng dài
// thêm mà không ai phải có mặt.
//
// Ba đường vào, và chúng khác nhau về NGƯỜI GỌI chứ không chỉ về việc:
//
//	Dat        — chủ tiệm, sau token của cửa hàng. Chốt số tiền và sinh link.
//	TrangThai  — chủ tiệm, trang thanh toán hỏi lại mỗi vài giây.
//	XuLyWebhook— CỔNG THANH TOÁN, không có token nào. Chữ ký là thứ duy nhất
//	             phân biệt "PayOS báo đã thu tiền" với "ai đó gửi một request".
//
// HAI ĐƯỜNG CÙNG CHỐT ĐƠN (webhook và TrangThai), và đó là chủ ý chứ không phải
// thừa: webhook không tới được máy chạy ở localhost, còn ở máy thật thì nó có
// thể tới trễ hoặc trượt. Cả hai đi qua đúng một hàm `chotDon`, và hàm đó chỉ
// làm việc MỘT LẦN cho mỗi đơn — xem DanhDauDaTra.
//
// TIỀN VÀO KHÔNG PHẢI LÚC NÀO CŨNG ĐẨY HẠN: số tiền phải khớp với số đã chốt lúc
// tạo đơn. Lệch thì ghi nhật ký để người bán đối soát tay, KHÔNG tự gia hạn —
// giống hệt cách cụm bán hàng cho khách đang xử lý tiền lệch của cửa hàng.
type GiaHanService interface {
	// Dat tạo đơn gia hạn cho hợp đồng hiện tại của cửa hàng và sinh link thanh toán.
	Dat(ctx context.Context, tenantID, planID uint, soThang int) (dto.DonGiaHanResponse, error)
	// TrangThai đọc đơn; đơn còn chờ thì HỎI LẠI CỔNG xem tiền vào chưa, và chốt
	// ngay tại đây nếu đã vào.
	TrangThai(ctx context.Context, tenantID, donID uint) (dto.DonGiaHanResponse, error)
	// XuLyWebhook nhận gói dữ liệu cổng gửi về. raw là body nguyên văn — chữ ký
	// tính trên đó, nên KHÔNG được parse rồi dựng lại trước khi kiểm.
	XuLyWebhook(ctx context.Context, raw []byte) error
}

// soThangToiDaKhach giới hạn một lần khách tự gia hạn.
//
// 24 tháng: dài hơn thì đó là một thoả thuận cần người nói chuyện với nhau (giá
// riêng, hợp đồng riêng), không phải một ô chọn trên màn hình. Chặn ở đây cũng
// là chặn một lượt gõ nhầm thành hoá đơn vài chục triệu.
const soThangToiDaKhach = 24

// HanLinkThanhToan là thời gian một đơn được giữ chỗ trước khi tự huỷ.
//
// NĂM PHÚT, và con số này là một quyết định nghiệp vụ chứ không phải kỹ thuật:
// đủ để khách mở app ngân hàng, quét mã, xác nhận — nhưng không giữ một mã QR
// sống lâu hơn phiên làm việc của người đang nhìn nó.
//
// Ngắn có cái giá của nó: khách bị gọi điện giữa chừng quay lại là link đã chết
// và phải bấm gia hạn lần nữa. Đổi lại, không có mã QR nào nằm lang thang trong
// lịch sử trình duyệt hay ảnh chụp màn hình mà vẫn thu được tiền — mà tiền vào
// một đơn khách đã quên thì phải hoàn lại bằng tay.
//
// Cùng giá trị được gửi sang cổng (expiredAt) và ghi vào đơn (het_han_luc): hai
// nơi lệch nhau thì có khoảng thời gian cổng vẫn nhận tiền còn mình đã coi đơn
// là chết — tiền vào mà không đơn nào được chốt.
const HanLinkThanhToan = 5 * time.Minute

type giaHanService struct {
	don     domain.DonGiaHanRepository
	thueBao domain.ThueBaoCuaKhachRepository
	plans   domain.PlanRepository
	hopDong domain.HopDongRepository
	cuaHang domain.CuaHangMoiRepository
	cauHinh domain.PlatformSettingRepository
	hop     *bimat.Hop
	maApp   string
	// shopURL là gốc địa chỉ Shop Admin, dùng dựng returnUrl/cancelUrl gửi cho
	// cổng. KHÔNG nhận từ request: để client tự khai địa chỉ quay về nghĩa là mở
	// một đường chuyển hướng sang bất cứ đâu, ký sẵn tên miền của mình.
	shopURL string
}

func NewGiaHanService(
	don domain.DonGiaHanRepository,
	thueBao domain.ThueBaoCuaKhachRepository,
	plans domain.PlanRepository,
	hopDong domain.HopDongRepository,
	cuaHang domain.CuaHangMoiRepository,
	cauHinh domain.PlatformSettingRepository,
	hop *bimat.Hop,
	maApp, shopURL string,
) GiaHanService {
	return &giaHanService{
		don: don, thueBao: thueBao, plans: plans, hopDong: hopDong,
		cuaHang: cuaHang, cauHinh: cauHinh, hop: hop,
		maApp: maApp, shopURL: strings.TrimRight(shopURL, "/"),
	}
}

// ---------- Đặt đơn ----------

func (s *giaHanService) Dat(ctx context.Context, tenantID, planID uint, soThang int) (dto.DonGiaHanResponse, error) {
	var rong dto.DonGiaHanResponse

	if tenantID == 0 {
		return rong, domain.ErrForbidden
	}
	if soThang <= 0 || soThang > soThangToiDaKhach {
		return rong, loiO(map[string]string{
			"so_thang": fmt.Sprintf("Số tháng phải trong khoảng 1–%d", soThangToiDaKhach),
		})
	}

	hd, err := s.thueBao.HienTai(ctx, tenantID, s.maApp)
	if err != nil {
		// Không có hợp đồng nào để đẩy hạn: khách này chưa từng được ký hợp đồng,
		// hoặc hợp đồng đã huỷ hẳn. Cả hai đều cần người bán mở lại, không phải
		// một lượt thanh toán.
		return rong, err
	}

	plan, err := s.plans.Find(ctx, planID)
	if err != nil {
		return rong, err
	}
	// Gói của phần mềm KHÁC: chặn tại đây. Không có nó thì một cửa hàng Order trả
	// tiền theo bảng giá của sản phẩm khác, và hợp đồng Order của họ vẫn được đẩy
	// hạn bằng số tiền đó.
	if plan.AppCode != s.maApp {
		return rong, domain.ErrNotFound
	}
	if plan.Status != domain.PlanStatusActive {
		return rong, domain.ErrGoiNgungBan
	}
	// Gói "Liên hệ" (giá nil) không tự mua được: chưa có giá công khai thì không
	// có số nào để thu. Đây là gói Chuỗi — bán được nhưng phải nói chuyện trước.
	if plan.Price == nil || *plan.Price <= 0 {
		return rong, domain.ErrBangGiaChuaCoGia
	}

	// GIÁ TÍNH THEO KỲ CỦA GÓI, KHÔNG THEO THÁNG.
	//
	// Đây là chỗ dễ tính sai nhất của cả luồng: `so_thang` là độ dài gia hạn
	// (hợp đồng cộng thêm bấy nhiêu tháng), còn `plans.price` là giá MỘT KỲ —
	// mà kỳ của gói bán theo năm dài 12 tháng. Nhân thẳng giá × số tháng thì gói
	// năm bị thu gấp 12 lần, và cái sai đó không có gì báo: đơn vẫn tạo được,
	// link vẫn mở được, khách chỉ phát hiện lúc nhìn số tiền phải trả.
	soThangMotKy := 1
	if plan.BillingCycle == domain.CycleNam {
		soThangMotKy = 12
	}
	// Gói bán theo năm chỉ gia hạn theo NĂM TRÒN. Cho mua 3 tháng của một gói năm
	// nghĩa là bán một phần tư cái không ai định bán lẻ, và số tiền lẻ đó sẽ nằm
	// mãi trong sổ thu chẳng khớp với kỳ nào.
	if soThang%soThangMotKy != 0 {
		return rong, loiO(map[string]string{
			"so_thang": "Gói này bán theo năm, chỉ gia hạn được theo năm tròn",
		})
	}
	soKy := soThang / soThangMotKy

	// ĐƠN CŨ CÒN HIỆU LỰC THÌ DÙNG LẠI, không sinh link mới. Khách bấm gia hạn
	// rồi quay lại bấm tiếp là chuyện thường; mỗi lần một link thì sổ đơn đầy rác
	// và sẽ có người trả nhầm hai cái.
	if cu, err := s.don.DangCho(ctx, hd.ID); err == nil && cu != nil && cu.PlanID != nil &&
		*cu.PlanID == planID && cu.SoThang == uint(soThang) {
		return s.dungResponse(*cu, hd), nil
	}

	don := domain.DonGiaHan{
		TenantID:       tenantID,
		AppID:          hd.AppID,
		SubscriptionID: hd.ID,
		PlanID:         &planID,
		SoThang:        uint(soThang),
		// Số tiền CHỐT tại đây. Bảng giá đổi giữa lúc khách đang mở trang thanh
		// toán là chuyện có thật, và khách phải trả đúng số đã nhìn thấy.
		SoTien:    *plan.Price * float64(soKy),
		TrangThai: domain.DonChoThanhToan,
		Cong:      domain.CongPayOS,
	}
	if err := s.don.Tao(ctx, &don); err != nil {
		return rong, err
	}

	link, hetHan, err := s.taoLink(ctx, don, *hd)
	if err != nil {
		// Đơn đã nằm dưới database nhưng không có link: đánh dấu huỷ luôn để nó
		// không chắn đường lượt bấm sau (DangCho sẽ trả về nó mãi).
		_ = s.don.DoiTrangThai(ctx, don.ID, domain.DonHuy)

		return rong, err
	}

	tt := domain.ThongTinTraTien{
		LinkID:      link.PaymentLinkID,
		CheckoutURL: link.CheckoutURL,
		QRCode:      link.QRCode,
		NganHangBIN: link.Bin,
		SoTaiKhoan:  link.AccountNumber,
		ChuTaiKhoan: link.AccountName,
		NoiDung:     link.Description,
		HetHan:      hetHan,
	}
	if err := s.don.GanLink(ctx, don.ID, tt); err != nil {
		return rong, err
	}
	don.LinkID = domain.StringOrNull(tt.LinkID)
	don.CheckoutURL = domain.StringOrNull(tt.CheckoutURL)
	don.QRCode = domain.StringOrNull(tt.QRCode)
	don.NganHangBIN = domain.StringOrNull(tt.NganHangBIN)
	don.SoTaiKhoan = domain.StringOrNull(tt.SoTaiKhoan)
	don.ChuTaiKhoan = domain.StringOrNull(tt.ChuTaiKhoan)
	don.NoiDung = domain.StringOrNull(tt.NoiDung)
	don.HetHanLuc = hetHan

	return s.dungResponse(don, hd), nil
}

// taoLink gọi cổng thanh toán bằng khoá đọc từ CẤU HÌNH NỀN TẢNG.
//
// Khoá không nằm ở .env như cổng của cửa hàng: nó là khoá của NHÀ CUNG CẤP, khai
// trên màn hình Cài đặt và cất ở dạng mã hoá (xem CauHinhNenTangService). Đọc
// mỗi lần tạo link chứ không nạp sẵn lúc khởi động — đổi khoá xong phải có hiệu
// lực ngay, không đợi khởi động lại máy chủ.
func (s *giaHanService) taoLink(
	ctx context.Context, don domain.DonGiaHan, hd domain.HopDongDayDu,
) (*payos.Link, *time.Time, error) {
	cong, err := s.congPayOS(ctx)
	if err != nil {
		return nil, nil, err
	}

	// Nội dung chuyển khoản khách nhìn thấy trong app ngân hàng — PayOS giới hạn
	// 25 ký tự. Mã cửa hàng đứng trong đó vì đây cũng là thứ đối soát tay khi
	// phải lần lại một giao dịch.
	moTa := truncate25("GIAHAN " + strings.ToUpper(hd.MaCuaHang))

	hetHan := time.Now().Add(HanLinkThanhToan)

	ve := s.shopURL + "/admin/goi-dich-vu/thanh-toan/" + strconv.FormatUint(uint64(don.ID), 10)
	link, err := cong.CreateLink(ctx, payos.CreateRequest{
		OrderCode:   int64(don.MaDon),
		Amount:      int64(don.SoTien),
		Description: moTa,
		Items: []payos.Item{{
			Name:     truncate25(hd.TenApp + " " + string(hd.TenGoi)),
			Quantity: int(don.SoThang),
			Price:    int64(don.SoTien) / int64(max1(int(don.SoThang))),
		}},
		ReturnURL: ve,
		CancelURL: ve + "?huy=1",
		ExpiredAt: hetHan.Unix(),
	})
	if err != nil {
		logger.Error("không tạo được link thanh toán gia hạn",
			zap.Uint("don", don.ID), zap.Uint("tenant", don.TenantID), zap.Error(err))

		return nil, nil, domain.ErrCongThanhToanLoi
	}

	return link, &hetHan, nil
}

// congPayOS dựng client PayOS từ cấu hình nền tảng đã mã hoá.
func (s *giaHanService) congPayOS(ctx context.Context) (*payos.Client, error) {
	values, err := s.cauHinh.All(ctx)
	if err != nil {
		return nil, err
	}
	if values[CauHinhPayOSBat] != "1" {
		return nil, domain.ErrChuaBatCongThanhToan
	}

	khoa := make(map[string]string, 3)
	for _, k := range []string{CauHinhPayOSClientID, CauHinhPayOSAPIKey, CauHinhPayOSChecksumKey} {
		thuong, err := s.hop.Giai(values[k])
		if err != nil || strings.TrimSpace(thuong) == "" {
			// Giải không được (đổi PLATFORM_SECRET_KEY sau khi đã lưu) đọc ra cùng
			// một kết luận với chưa khai: cổng không dùng được. Nói đúng câu đó thay
			// vì để lượt gọi PayOS thất bại với một lỗi chữ ký khó hiểu.
			logger.Error("khoá PayOS của nền tảng không dùng được", zap.String("khoa", k), zap.Error(err))

			return nil, domain.ErrChuaBatCongThanhToan
		}
		khoa[k] = thuong
	}

	return payos.New(config.PayOSConfig{
		ClientID:    khoa[CauHinhPayOSClientID],
		APIKey:      khoa[CauHinhPayOSAPIKey],
		ChecksumKey: khoa[CauHinhPayOSChecksumKey],
	}), nil
}

// ---------- Trạng thái & chốt đơn ----------

func (s *giaHanService) TrangThai(ctx context.Context, tenantID, donID uint) (dto.DonGiaHanResponse, error) {
	var rong dto.DonGiaHanResponse

	don, err := s.don.Tim(ctx, tenantID, donID)
	if err != nil {
		return rong, err
	}

	// QUÁ HẠN THÌ ĐÓNG ĐƠN NGAY, không hỏi cổng nữa.
	//
	// Đây là chỗ biến "link sống 5 phút" thành một trạng thái thật trong sổ, thay
	// vì một cái mốc chỉ dùng để lọc. Không có nó thì đơn nằm mãi ở
	// `cho_thanh_toan` và màn hình vẫn quay vòng chờ một khoản tiền không bao giờ
	// tới được nữa.
	//
	// Đặt TRƯỚC lượt hỏi cổng: cổng cũng sẽ trả về "đã hết hạn", nhưng hỏi nó là
	// một lượt gọi mạng cho một câu trả lời mà cái đồng hồ ở đây đã biết.
	if don.TrangThai == domain.DonChoThanhToan &&
		don.HetHanLuc != nil && don.HetHanLuc.Before(time.Now()) {
		_ = s.don.DoiTrangThai(ctx, don.ID, domain.DonHetHan)
		don.TrangThai = domain.DonHetHan
	}

	// Đơn còn chờ: HỎI THẲNG CỔNG. Đây là đường xác nhận dự phòng cho webhook —
	// ở máy local PayOS không gọi vào localhost được, còn ở máy thật webhook vẫn
	// có thể tới trễ. Không có nó thì khách trả tiền xong nhìn màn hình "đang chờ"
	// mãi và gọi điện.
	if don.TrangThai == domain.DonChoThanhToan {
		if daTra := s.hoiCong(ctx, *don); daTra {
			if err := s.chotDon(ctx, don); err != nil {
				return rong, err
			}
			// Đọc lại để trả về trạng thái sau khi chốt.
			if moi, err := s.don.Tim(ctx, tenantID, donID); err == nil {
				don = moi
			}
		}
	}

	hd, _ := s.thueBao.HienTai(ctx, tenantID, s.maApp)

	return s.dungResponse(*don, hd), nil
}

// hoiCong hỏi cổng xem đơn đã trả tiền chưa. Hỏng thì trả false — im lặng.
//
// Không ném lỗi lên: đây là lượt hỏi THÊM trong một request chỉ để hiển thị
// trạng thái. Cổng trục trặc mà làm cả trang thanh toán trắng xoá thì khách
// tưởng tiền của mình vừa mất.
func (s *giaHanService) hoiCong(ctx context.Context, don domain.DonGiaHan) bool {
	cong, err := s.congPayOS(ctx)
	if err != nil {
		return false
	}

	info, err := cong.GetLink(ctx, int64(don.MaDon))
	if err != nil {
		logger.Warn("không hỏi được trạng thái đơn gia hạn từ cổng",
			zap.Uint("don", don.ID), zap.Error(err))

		return false
	}
	if info.Closed() {
		_ = s.don.DoiTrangThai(ctx, don.ID, domain.DonHetHan)

		return false
	}
	if !info.Paid() {
		return false
	}

	// TIỀN PHẢI ĐỦ. Trả thiếu thì không đẩy hạn — ghi nhật ký để người bán đối
	// soát tay, giống hệt cách cụm bán hàng cho khách xử lý tiền lệch.
	if info.AmountPaid < int64(don.SoTien) {
		logger.Warn("đơn gia hạn trả THIẾU tiền, không tự gia hạn",
			zap.Uint("don", don.ID),
			zap.Int64("da_tra", info.AmountPaid), zap.Float64("phai_tra", don.SoTien))

		return false
	}

	return true
}

func (s *giaHanService) XuLyWebhook(ctx context.Context, raw []byte) error {
	cong, err := s.congPayOS(ctx)
	if err != nil {
		return err
	}

	// Chữ ký kiểm TRƯỚC MỌI THỨ. Đây là thứ duy nhất phân biệt "PayOS báo đã thu
	// tiền" với "ai đó gửi một request nói rằng đã thu tiền" — mà việc đứng sau
	// nó là đẩy hạn hợp đồng, tức là cho không phần mềm.
	data, err := cong.ParseWebhook(raw)
	if err != nil {
		return err
	}
	if !data.Succeeded() {
		// Giao dịch hỏng: không phải lỗi của mình, và cũng không có gì để làm.
		// Trả nil để cổng thôi gửi lại.
		return nil
	}

	don, err := s.don.TimTheoMaDon(ctx, uint(data.OrderCode))
	if err != nil {
		// Mã đơn không có trong sổ: cổng đang nói về một đơn của hệ thống khác
		// dùng chung kênh thanh toán. Ghi nhật ký rồi bỏ qua — trả lỗi chỉ khiến
		// cổng gửi lại gói đó suốt nhiều giờ.
		logger.Warn("webhook gia hạn nói về một mã đơn không có trong sổ",
			zap.Int64("ma_don", data.OrderCode))

		return nil
	}

	if int64(don.SoTien) > data.Amount {
		logger.Warn("webhook gia hạn báo số tiền nhỏ hơn đơn, không tự gia hạn",
			zap.Uint("don", don.ID),
			zap.Int64("bao_ve", data.Amount), zap.Float64("phai_tra", don.SoTien))

		return nil
	}

	return s.chotDon(ctx, don)
}

// chotDon là chỗ DUY NHẤT biến "tiền đã vào" thành "hợp đồng dài thêm".
//
// Thứ tự có chủ ý, và mỗi bước hỏng để lại hậu quả khác nhau:
//
//  1. ĐÁNH DẤU ĐƠN trước. Câu UPDATE chỉ đổi đơn đang `cho_thanh_toan` và trả về
//     số dòng — 0 nghĩa là lượt khác đã chốt xong, và ta dừng ngay tại đây. Đây
//     là toàn bộ cơ chế chống đẩy hạn hai lần khi webhook tới trùng lúc khách
//     bấm tải lại trang thanh toán.
//  2. GHI SỔ THU. Hỏng thì đơn đã đánh dấu trả rồi mà sổ thu thiếu một dòng —
//     doanh thu báo thiếu, nhưng khách KHÔNG mất tiền oan.
//  3. ĐẨY HẠN + MỞ KHOÁ. Đây là thứ khách trả tiền để lấy, nên nó đứng cuối:
//     mọi bước trước đã xong thì bước này còn chạy lại được bằng tay từ khu điều
//     hành, và có đủ dấu vết (đơn + sổ thu) để biết phải chạy lại cho ai.
func (s *giaHanService) chotDon(ctx context.Context, don *domain.DonGiaHan) error {
	// Ghi sổ thu TRƯỚC để có invoice id gắn vào đơn, nhưng chỉ ghi khi giành được
	// quyền chốt — nên thứ tự thật là: giành quyền (bước 1) → ghi sổ → gắn id.
	//
	// Giành quyền bằng chính DanhDauDaTra với invoiceID = 0, rồi cập nhật id sau:
	// một câu UPDATE có điều kiện trạng thái là khoá rẻ nhất và đúng nhất ở đây.
	rows, err := s.don.DanhDauDaTra(ctx, don.ID, 0)
	if err != nil {
		return err
	}
	if rows == 0 {
		// Lượt khác đã chốt xong. KHÔNG phải lỗi: webhook tới hai lần là thiết kế
		// của cổng, không phải sự cố.
		return nil
	}

	hoaDon := domain.Invoice{
		SubscriptionID: don.SubscriptionID,
		Amount:         don.SoTien,
		PeriodStart:    time.Now(),
		PeriodEnd:      time.Now().AddDate(0, int(don.SoThang), 0),
		PaidAt:         time.Now(),
		Method:         domain.PaymentChuyenKhoan,
		Reference:      domain.StringOrNull("payos:" + strconv.FormatUint(uint64(don.MaDon), 10)),
		Note:           domain.StringOrNull("khách tự gia hạn trên phần mềm"),
	}
	if err := s.hopDong.ThuTien(ctx, &hoaDon); err != nil {
		// Sổ thu thiếu một dòng thì doanh thu báo thiếu — đáng ghi nhật ký, nhưng
		// KHÔNG được chặn việc đẩy hạn: khách đã trả tiền rồi.
		logger.Error("không ghi được sổ thu cho đơn gia hạn",
			zap.Uint("don", don.ID), zap.Error(err))
	} else if hoaDon.ID > 0 {
		_ = s.don.GanHoaDon(ctx, don.ID, hoaDon.ID)
	}

	if err := s.hopDong.GiaHan(ctx, don.SubscriptionID, int(don.SoThang)); err != nil {
		logger.Error("ĐƠN ĐÃ TRẢ TIỀN NHƯNG KHÔNG ĐẨY ĐƯỢC HẠN — phải gia hạn tay",
			zap.Uint("don", don.ID), zap.Uint("hop_dong", don.SubscriptionID), zap.Error(err))

		return err
	}

	// Mở khoá cửa hàng ở CẢ HAI plane. Bắt buộc: khách hết hạn hôm qua, hôm nay
	// trả tiền, mà `tenants.status` vẫn `suspended` thì họ đăng nhập vào đúng câu
	// "cửa hàng đang tạm khoá, liên hệ nhà cung cấp" — người vừa nhận tiền của họ.
	if _, err := s.cuaHang.DoiTrangThai(ctx, []uint{don.TenantID}, domain.TenantActive); err != nil {
		logger.Error("gia hạn xong nhưng không mở được khoá cửa hàng ở data plane",
			zap.Uint("tenant", don.TenantID), zap.Error(err))
	}
	if _, err := s.hopDong.DoiTrangThaiKhach(ctx, []uint{don.TenantID}, domain.TenantActive); err != nil {
		logger.Error("gia hạn xong nhưng không mở được khoá cửa hàng ở sổ nền tảng",
			zap.Uint("tenant", don.TenantID), zap.Error(err))
	}

	logger.Info("khách tự gia hạn thành công",
		zap.Uint("don", don.ID), zap.Uint("tenant", don.TenantID),
		zap.Uint("so_thang", don.SoThang), zap.Float64("so_tien", don.SoTien))

	return nil
}

// ---------- Dựng câu trả lời ----------

func (s *giaHanService) dungResponse(don domain.DonGiaHan, hd *domain.HopDongDayDu) dto.DonGiaHanResponse {
	res := dto.DonGiaHanResponse{
		ID:          don.ID,
		MaDon:       don.MaDon,
		SoThang:     don.SoThang,
		SoTien:      don.SoTien,
		TrangThai:   don.TrangThai,
		CheckoutURL: string(don.CheckoutURL),
		QRCode:      string(don.QRCode),
		NganHangBIN: string(don.NganHangBIN),
		SoTaiKhoan:  string(don.SoTaiKhoan),
		ChuTaiKhoan: string(don.ChuTaiKhoan),
		NoiDung:     string(don.NoiDung),
		HetHanLuc:   don.HetHanLuc,
		DaTra:       don.TrangThai == domain.DonDaThanhToan,
	}
	if hd != nil {
		res.TenGoi = string(hd.TenGoi)
		if res.TenGoi == "" {
			res.TenGoi = hd.Plan
		}
		res.HanMoi = hd.EndsAt

		// BÊN MUA. Đọc từ hợp đồng đang chạy chứ không từ token của người đang
		// đăng nhập: token nói "ai đang bấm", còn hoá đơn thì thu tiền của CỬA
		// HÀNG. Hai thứ đó khác nhau ở mọi tiệm có nhiều hơn một quản trị viên.
		res.TenApp = hd.TenApp
		res.MaCuaHang = hd.MaCuaHang
		res.TenCuaHang = hd.TenCuaHang
		res.NguoiLienHe = string(hd.NguoiLienHe)
		res.DienThoai = string(hd.DienThoai)
		res.Email = string(hd.Email)
	}

	return res
}

func truncate25(s string) string {
	s = strings.TrimSpace(s)
	if len([]rune(s)) <= payos.DescriptionMax {
		return s
	}

	return strings.TrimSpace(string([]rune(s)[:payos.DescriptionMax]))
}

func max1(n int) int {
	if n < 1 {
		return 1
	}

	return n
}
