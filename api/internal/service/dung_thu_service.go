package service

import (
	"context"
	"errors"
	"fmt"
	"regexp"
	"strconv"
	"strings"
	"time"

	"sass-api/internal/domain"
	"sass-api/internal/dto"
	"sass-api/pkg/hash"
	"sass-api/pkg/logger"

	"go.uber.org/zap"
)

// DungThuService là ba việc GHI của khu điều hành trên vòng đời một hợp đồng:
// mở tài khoản dùng thử, gia hạn (cũng chính là chuyển sang chính thức), và huỷ.
//
// Đây là bản dịch sang HTTP của `cmd/thue-bao ky | gia-han | huy`. Công cụ dòng
// lệnh vẫn còn và vẫn là đường làm việc trên máy chủ; cái mới ở đây là người bán
// không phải có SSH mới mở được tài khoản dùng thử cho khách.
//
// BẮC QUA HAI DATABASE. Cửa hàng và tài khoản đăng nhập nằm ở data plane, hợp
// đồng nằm ở control plane, và KHÔNG có giao dịch nào bao được cả hai. Thứ tự vì
// thế là một quyết định, không phải chuyện tuỳ tiện — xem chú thích trong Tao.
type DungThuService interface {
	// Tao mở một khách hàng mới: cửa hàng + tài khoản quản trị + hợp đồng dùng thử.
	Tao(ctx context.Context, quyen domain.QuyenApp, req dto.TaoDungThuRequest) (dto.TaoDungThuResponse, error)
	// TaoChinhThuc cũng dựng khách mới trọn gói, nhưng hợp đồng ra `active` ngay
	// và không có giai đoạn dùng thử. Dùng khi khách trả tiền từ đầu.
	//
	// Đi CHUNG một đường với Tao (xem taoKhachHangMoi) — khác đúng phần chốt kỳ
	// hạn. Chép cả trăm dòng ra hai bản thì hai bản đó lệch nhau ngay lần sửa
	// đầu tiên.
	TaoChinhThuc(ctx context.Context, quyen domain.QuyenApp, req dto.TaoChinhThucRequest) (dto.TaoDungThuResponse, error)
	// GiaHan đẩy hạn hợp đồng và đưa nó về 'active'. Gọi trên một hợp đồng đang
	// dùng thử chính là "chuyển sang chính thức".
	GiaHan(ctx context.Context, quyen domain.QuyenApp, id uint, soThang int) (dto.HopDongItem, error)
	// Huy đóng hợp đồng, ghi lý do vào note.
	Huy(ctx context.Context, quyen domain.QuyenApp, id uint, lyDo string) error
	// ChiTiet đọc trọn một hợp đồng kèm hồ sơ khách đứng sau nó.
	ChiTiet(ctx context.Context, quyen domain.QuyenApp, id uint) (dto.HopDongChiTiet, error)
	// Sua ghi phần sửa được: thông tin khách và ghi chú, KHÔNG phải điều khoản.
	Sua(ctx context.Context, quyen domain.QuyenApp, id uint, req dto.SuaHopDongRequest) (dto.HopDongChiTiet, error)
	// DoiMatKhau đặt lại mật khẩu tài khoản quản trị của khách.
	//
	// Có mặt vì tài khoản quản trị cửa hàng KHÔNG có đường tự khôi phục nào:
	// quên-mật-khẩu-qua-email chỉ tồn tại trong cụm storefront dành cho khách mua
	// sắm. Khách quên mật khẩu thì gọi cho nhà cung cấp, và đây là chỗ nhà cung
	// cấp làm được việc đó.
	DoiMatKhau(ctx context.Context, quyen domain.QuyenApp, id uint, matKhau string) (dto.QuanTriItem, error)
	// ThuTien ghi MỘT LẦN TIỀN VÀO sổ thu của hợp đồng.
	//
	// Tách hẳn khỏi GiaHan, và đó là chủ ý của cả thiết kế: gia hạn đẩy HẠN, thu
	// tiền ghi nhận TIỀN. Gộp lại thì mỗi lần gia hạn báo một khoản doanh thu
	// chưa ai trả, và khách trả trước mà chưa muốn đẩy hạn thì không ghi vào đâu
	// được. Xem thêm domain.Invoice.
	ThuTien(ctx context.Context, quyen domain.QuyenApp, id uint, req dto.ThuTienRequest) (dto.ThuTienResponse, error)
	// CuaHangChuaKy liệt kê cửa hàng ĐÃ CÓ ở data plane nhưng chưa có hợp đồng
	// còn hiệu lực cho phần mềm này — ứng viên để ký mới.
	CuaHangChuaKy(ctx context.Context, quyen domain.QuyenApp, maApp string) (dto.CuaHangCoSanResponse, error)
	// KyHopDong ký hợp đồng CHÍNH THỨC cho một cửa hàng đã tồn tại.
	//
	// Khác Tao ở chỗ không dựng gì bên data plane: cửa hàng và tài khoản đăng
	// nhập đã có sẵn, việc duy nhất là ghi hợp đồng bên control plane — nên nó
	// KHÔNG bắc qua hai database và không có phần hụt nào.
	KyHopDong(ctx context.Context, quyen domain.QuyenApp, req dto.KyHopDongRequest) (dto.HopDongItem, error)
}

// LoiTheoO gom lỗi theo TỪNG Ô của form để handler trả 422 kèm chi tiết, giống
// hệt lỗi validate của binding JSON.
//
// Cùng vai trò với PlanFeatureValidationError nhưng là kiểu riêng: hai form khác
// nhau, và gộp làm một thì câu Error() phải nói chung chung tới mức vô nghĩa
// trong log. Form "Thêm tài khoản dùng thử" có sáu ô — một câu "dữ liệu không
// hợp lệ" bắt người gõ tự đoán ô nào sai.
type LoiTheoO struct {
	Fields map[string]string
}

func (e *LoiTheoO) Error() string { return "dữ liệu gửi lên không hợp lệ" }

func loiO(fields map[string]string) error { return &LoiTheoO{Fields: fields} }

type dungThuService struct {
	plan     domain.PlanRepository
	hopDong  domain.HopDongRepository
	cuaHang  domain.CuaHangMoiRepository
	taiKhoan domain.TaiKhoanCuaHangRepository
}

func NewDungThuService(
	plan domain.PlanRepository,
	hopDong domain.HopDongRepository,
	cuaHang domain.CuaHangMoiRepository,
	taiKhoan domain.TaiKhoanCuaHangRepository,
) DungThuService {
	return &dungThuService{plan: plan, hopDong: hopDong, cuaHang: cuaHang, taiKhoan: taiKhoan}
}

// maCuaHangRe khớp `cmd/tao-admin` và ô đầu tiên của màn hình đăng nhập 3 ô.
// Lệch nhau giữa ba nơi thì cửa hàng tạo ở đây không đăng nhập được ở kia.
var maCuaHangRe = regexp.MustCompile(`^[a-z0-9][a-z0-9._-]{1,29}$`)

// soThangToiDa chặn một lượt gia hạn vượt quá năm năm.
//
// Không phải luật kinh doanh mà là lưới chặn lỗi gõ: "12" thành "120" là mười
// năm miễn phí, và không có màn hình nào báo động chuyện đó — hợp đồng chỉ lặng
// lẽ biến mất khỏi danh sách sắp hết hạn.
const soThangToiDa = 60

// ngayDungThuToiDa — trần số ngày dùng thử khai tay, cùng lý do với soThangToiDa.
const ngayDungThuToiDa = 180

// chotKyHan quyết TRẠNG THÁI và HẠN của hợp đồng sắp ghi, dựa trên dòng bảng
// giá đã chọn.
//
// Đây là toàn bộ khác biệt giữa "mở tài khoản dùng thử" và "thêm hợp đồng chính
// thức": mọi bước còn lại — kiểm ô, tra bảng giá, chốt điều khoản, dựng cửa hàng
// và tài khoản đăng nhập — giống hệt nhau. Tách bằng một hàm nhỏ thay vì chép cả
// trăm dòng ra hai bản, vì hai bản đó sẽ lệch nhau ngay lần sửa đầu tiên.
//
// hetThu nil = hợp đồng KHÔNG có giai đoạn dùng thử.
type chotKyHan func(bg *domain.PlanWithApp, batDau time.Time) (trangThai string, ketThuc time.Time, hetThu *time.Time, err error)

// taoKhachHangMoi dựng khách mới: cửa hàng + tài khoản quản trị + hợp đồng.
//
// Dùng chung cho cả hai đường tạo khách. Xem chotKyHan về chỗ duy nhất chúng
// khác nhau.
func (s *dungThuService) taoKhachHangMoi(
	ctx context.Context, quyen domain.QuyenApp, req dto.KhachHangMoiChung, chot chotKyHan,
) (dto.TaoDungThuResponse, error) {
	var rong dto.TaoDungThuResponse

	moi, loi := chuanHoaCuaHang(req)
	if len(loi) > 0 {
		return rong, loiO(loi)
	}

	// 1. Dòng bảng giá. Đọc TRƯỚC khi tạo cửa hàng: mọi lý do từ chối đều nằm ở
	//    đây (gói ngừng bán, bảng giá thiếu hạn mức, giá "Liên hệ"), và từ chối
	//    sau khi đã dựng xong cửa hàng thì mã cửa hàng đã bị chiếm mất rồi.
	bg, err := s.plan.Find(ctx, req.PlanID)
	if err != nil {
		return rong, err
	}
	if !quyen.ChoPhep(bg.AppCode) {
		return rong, domain.ErrKhongPhuTrachApp
	}
	if bg.Status != domain.PlanStatusActive {
		return rong, domain.ErrGoiNgungBan
	}
	if bg.AppStatus != domain.AppActive {
		return rong, domain.ErrAppChuaBan
	}

	tinhNang, err := s.plan.Features(ctx, bg.ID)
	if err != nil {
		return rong, err
	}

	// 2. CHỐT điều khoản. Mỗi hạn mức chép từ bảng giá; bảng giá không quy định
	//    thì TỪ CHỐI chứ không đoán — chép 0 sang hợp đồng nghĩa là bán không
	//    giới hạn cho một gói mà bảng giá còn chưa nói gì.
	dieuKhoan, err := chotDieuKhoan(bg, tinhNang)
	if err != nil {
		return rong, err
	}

	// 3. Thời hạn — chỗ DUY NHẤT hai đường tạo khách khác nhau. Chốt TRƯỚC khi
	//    dựng cửa hàng: hỏng ở đây mà cửa hàng đã tạo rồi thì mã đã bị chiếm.
	batDau := time.Now()
	trangThai, ketThuc, hetThu, err := chot(bg, batDau)
	if err != nil {
		return rong, err
	}

	// 4. Mã cửa hàng còn trống không. Kiểm sớm để người đang gõ form biết ngay ô
	//    nào sai; khoá uq_tenants_code dưới database mới là chốt chặn thật.
	daCo, err := s.cuaHang.CoMa(ctx, moi.Ma)
	if err != nil {
		return rong, err
	}
	if daCo {
		return rong, domain.ErrCuaHangDaCo
	}

	bam, err := hash.Hash(req.MatKhau)
	if err != nil {
		return rong, err
	}
	moi.BamMatKhau = bam

	// 5. Dựng cửa hàng ở DATA PLANE trước, ghi hợp đồng ở CONTROL PLANE sau.
	//
	//    Thứ tự này bắt buộc, vì `subscriptions.tenant_id` phải là id do data
	//    plane cấp — không có id đó thì chưa ghi được hợp đồng.
	//
	//    Hệ quả: hỏng ở bước 6 để lại một cửa hàng KHÔNG có hợp đồng. Đó là phần
	//    hụt cố ý chọn, vì chiều ngược lại (hợp đồng trỏ vào cửa hàng không tồn
	//    tại) làm hỏng cả màn hình danh sách — JOIN sẽ nuốt mất dòng, và khách đã
	//    ký nằm ngoài mọi báo cáo. Cửa hàng thừa thì hiện ra ở data plane, đăng
	//    nhập được, và ký hợp đồng bù bằng `cmd/thue-bao ky` là xong.
	tenantID, err := s.cuaHang.Tao(ctx, moi)
	if err != nil {
		return rong, err
	}

	// 6. Chép khách sang sổ nền tảng rồi ghi hợp đồng.
	if err := s.hopDong.UpsertKhachHang(ctx, domain.PlatformTenant{
		ID:     tenantID,
		Code:   moi.Ma,
		Name:   moi.Ten,
		Status: domain.TenantActive,
		// Người liên lạc ghi vào SỔ KHÁCH HÀNG chứ không vào hợp đồng: hợp đồng đã
		// ký thì không đổi, còn người cầm máy thì đổi.
		//
		// ContactEmail lấy từ ô người bán gõ, KHÔNG lấy moi.Email: cái kia là
		// <tên đăng nhập>@<mã>.local do máy tự đặt cho cột NOT NULL của bảng
		// users. Chép nó sang đây thì sổ khách hàng đầy địa chỉ không gửi thư tới
		// được, mà nhìn vẫn y như email thật — bỏ trống còn đọc ra là chưa có.
		ContactName:  domain.StringOrNull(strings.TrimSpace(req.NguoiLienHe)),
		ContactPhone: domain.StringOrNull(strings.TrimSpace(req.DienThoai)),
		ContactEmail: domain.StringOrNull(strings.ToLower(strings.TrimSpace(req.EmailLienHe))),
	}); err != nil {
		logger.Error("đã dựng cửa hàng nhưng không chép được sang sổ nền tảng — cửa hàng này chưa có hợp đồng",
			zap.String("ma_cua_hang", moi.Ma), zap.Uint("tenant_id", tenantID), zap.Error(err))

		return rong, err
	}

	hd := domain.Subscription{
		TenantID: tenantID,
		AppID:    bg.AppID,
		Plan:     bg.Code,
		PlanID:   &bg.ID,
		Status:   trangThai,
		// Chu kỳ chép từ chính dòng bảng giá đã chọn: một gói bán theo tháng và
		// theo năm là HAI dòng, và dòng nào được chọn đã nói ra chu kỳ rồi.
		BillingCycle: bg.BillingCycle,
		Price:        dieuKhoan.Gia,
		MaxShops:     dieuKhoan.ChiNhanh,
		MaxUsers:     dieuKhoan.TaiKhoan,
		MaxProducts:  dieuKhoan.SanPham,
		OwnDomain:    dieuKhoan.TenMienRieng,
		StartedAt:    batDau,
		EndsAt:       ketThuc,
		TrialEndsAt:  hetThu,
		Note:         domain.StringOrNull(strings.TrimSpace(req.GhiChu)),
	}
	if err := s.hopDong.Tao(ctx, &hd); err != nil {
		logger.Error("đã dựng cửa hàng nhưng không ghi được hợp đồng",
			zap.String("ma_cua_hang", moi.Ma), zap.Uint("tenant_id", tenantID),
			zap.String("trang_thai", trangThai), zap.Error(err))

		return rong, err
	}

	return dto.TaoDungThuResponse{
		TenantID:       tenantID,
		HopDongID:      hd.ID,
		MaCuaHang:      moi.Ma,
		TenCuaHang:     moi.Ten,
		TenDangNhap:    moi.TenDangNhap,
		Goi:            bg.Name,
		TrangThai:      trangThai,
		HetHan:         ketThuc,
		NguonDieuKhoan: dieuKhoan.Nguon,
	}, nil
}

// Tao mở tài khoản DÙNG THỬ cho khách mới.
func (s *dungThuService) Tao(ctx context.Context, quyen domain.QuyenApp, req dto.TaoDungThuRequest) (dto.TaoDungThuResponse, error) {
	return s.taoKhachHangMoi(ctx, quyen, req.KhachHangMoiChung,
		func(bg *domain.PlanWithApp, batDau time.Time) (string, time.Time, *time.Time, error) {
			// Bỏ trống số ngày = lấy theo bảng giá. Gói không khai ngày dùng thử
			// nào thì đường này không dùng được — mở "tài khoản dùng thử" 0 ngày là
			// tạo ra một hợp đồng quá hạn ngay từ giây đầu.
			ngayThu := bg.TrialDays
			if req.SoNgayDungThu != nil {
				ngayThu = *req.SoNgayDungThu
			}
			if ngayThu == 0 {
				return "", time.Time{}, nil, loiO(map[string]string{
					"so_ngay_dung_thu": "Gói này không có ngày dùng thử trong bảng giá — nhập số ngày đã thoả thuận với khách",
				})
			}
			if ngayThu > ngayDungThuToiDa {
				return "", time.Time{}, nil, loiO(map[string]string{
					"so_ngay_dung_thu": fmt.Sprintf("Tối đa %d ngày", ngayDungThuToiDa),
				})
			}

			// Hạn hợp đồng CHÍNH LÀ ngày hết dùng thử. Không đẩy ends_at ra xa hơn:
			// quá ngày đó mà khách chưa trả tiền thì hợp đồng phải quá hạn, chứ
			// không im lặng chạy tiếp thêm một tháng.
			ketThuc := batDau.AddDate(0, 0, int(ngayThu))

			return domain.SubscriptionTrial, ketThuc, &ketThuc, nil
		})
}

// TaoChinhThuc dựng khách mới kèm hợp đồng CHÍNH THỨC — không qua dùng thử.
//
// Cùng một đường với Tao, khác đúng phần chốt kỳ hạn: thời hạn tính bằng tháng,
// trạng thái ra `active` ngay, và KHÔNG có mốc hết dùng thử. Dùng khi khách trả
// tiền từ đầu.
func (s *dungThuService) TaoChinhThuc(ctx context.Context, quyen domain.QuyenApp, req dto.TaoChinhThucRequest) (dto.TaoDungThuResponse, error) {
	return s.taoKhachHangMoi(ctx, quyen, req.KhachHangMoiChung,
		func(bg *domain.PlanWithApp, batDau time.Time) (string, time.Time, *time.Time, error) {
			// Bỏ trống = MỘT CHU KỲ của gói. Bắt gõ lại con số đó ở mọi lượt ký là
			// bắt làm việc máy đã biết.
			soThang := req.SoThang
			if soThang <= 0 {
				soThang = 1
				if bg.BillingCycle == domain.CycleNam {
					soThang = 12
				}
			}
			if soThang > soThangToiDa {
				return "", time.Time{}, nil, loiO(map[string]string{
					"so_thang": fmt.Sprintf("Tối đa %d tháng", soThangToiDa),
				})
			}

			// TrialEndsAt nil: hợp đồng này không có giai đoạn thử. Để lại một mốc
			// ở đó thì hợp đồng vừa `active` vừa mang ngày hết dùng thử, và mọi báo
			// cáo đọc hai cột ấy sẽ trả lời khác nhau.
			return domain.SubscriptionActive, batDau.AddDate(0, soThang, 0), nil, nil
		})
}

func (s *dungThuService) GiaHan(ctx context.Context, quyen domain.QuyenApp, id uint, soThang int) (dto.HopDongItem, error) {
	var rong dto.HopDongItem

	if soThang <= 0 || soThang > soThangToiDa {
		return rong, loiO(map[string]string{
			"so_thang": fmt.Sprintf("Số tháng phải trong khoảng 1–%d", soThangToiDa),
		})
	}

	hd, err := s.timTrongPhamVi(ctx, quyen, id)
	if err != nil {
		return rong, err
	}
	// Hợp đồng đã huỷ: gia hạn sẽ hồi sinh nó thành 'active' và có thể đụng khoá
	// uq_subscriptions_current với hợp đồng đang chạy của cùng khách. Khách quay
	// lại là ký hợp đồng MỚI, không phải mở lại cái cũ.
	if hd.Status == domain.SubscriptionCanceled {
		return rong, domain.ErrHopDongDaHuy
	}

	if err := s.hopDong.GiaHan(ctx, id, soThang); err != nil {
		return rong, err
	}

	// MỞ KHOÁ cửa hàng ngay sau khi đẩy hạn.
	//
	// Bắt buộc phải có kể từ lúc lượt quét hạn tự khoá khách (xem
	// QuetHanService): khách hết hạn hôm qua, hôm nay trả tiền, mà cửa hàng vẫn
	// `suspended` thì họ đăng nhập vào đúng câu "cửa hàng đang tạm khoá, liên hệ
	// nhà cung cấp" — người vừa thu tiền của họ.
	//
	// ĐÂY LÀ NGOẠI LỆ có chủ ý của luật ghi ở UpsertKhachHang ("ký hợp đồng không
	// mở khoá hộ"). Luật đó chặn việc một lượt ghi hồ sơ vô tình đảo ngược quyết
	// định khoá của con người. Ở đây thì ngược lại: cái khoá do CHÍNH hợp đồng
	// này sinh ra, nên chính nó phải gỡ được. Cái giá phải trả là một cửa hàng bị
	// khoá tay vì lý do khác (lạm dụng, tranh chấp) sẽ mở lại khi có người gia
	// hạn — đổi lấy việc không lượt gia hạn nào bỏ quên khách trong trạng thái
	// khoá, thứ xảy ra ở MỌI lượt gia hạn của khách quá hạn.
	s.moKhoa(ctx, hd.TenantID)

	// Đọc lại thay vì tự tính ngày mới ở Go: mốc gia hạn là
	// GREATEST(ends_at, NOW(3)) do MySQL tính, và tính lại ở đây là chép cùng một
	// luật ra hai chỗ để chúng lệch nhau.
	moi, err := s.hopDong.Tim(ctx, id)
	if err != nil {
		return rong, err
	}

	return hopDongItem(*moi, time.Now()), nil
}

// moKhoa mở lại cửa hàng ở CẢ HAI plane sau khi hợp đồng được đẩy hạn.
//
// KHÔNG trả lỗi ra, chỉ ghi nhật ký. Chủ ý: lượt gia hạn đã ghi xong và đã
// thành công — hợp đồng dài thêm ba tháng nằm dưới database rồi. Ném lỗi lên ở
// đây thì màn hình báo "gia hạn thất bại" cho một việc đã làm xong, và người
// bấm sẽ bấm lại, đẩy hạn thêm ba tháng nữa. Cửa hàng chưa mở được là chuyện
// nhỏ hơn hẳn, và có ba đường tự chữa: bấm gia hạn lại (mở khoá chạy lại), lượt
// quét hạn sau không còn nhặt hợp đồng này nữa nên không khoá lại, và nhật ký
// dưới đây nói rõ phải mở tay cửa hàng nào.
func (s *dungThuService) moKhoa(ctx context.Context, tenantID uint) {
	// Data plane trước — cùng thứ tự và cùng lý do với lượt khoá, chỉ ngược
	// chiều: cột bên này là thứ quyết định khách vào được hay không.
	if _, err := s.cuaHang.DoiTrangThai(ctx, []uint{tenantID}, domain.TenantActive); err != nil {
		logger.Error("gia hạn xong nhưng KHÔNG mở khoá được cửa hàng — khách vẫn chưa đăng nhập được, cần mở tay",
			zap.Uint("tenant_id", tenantID), zap.Error(err))

		return
	}

	if _, err := s.hopDong.DoiTrangThaiKhach(ctx, []uint{tenantID}, domain.TenantActive); err != nil {
		// Khách đã vào lại được rồi; chỉ mỗi sổ nền tảng còn ghi "đang khoá".
		logger.Warn("đã mở khoá cửa hàng nhưng sổ nền tảng còn ghi trạng thái cũ",
			zap.Uint("tenant_id", tenantID), zap.Error(err))
	}
}

func (s *dungThuService) Huy(ctx context.Context, quyen domain.QuyenApp, id uint, lyDo string) error {
	hd, err := s.timTrongPhamVi(ctx, quyen, id)
	if err != nil {
		return err
	}
	// Huỷ hai lần: chặn lại thay vì ghi đè canceled_at. Cái mốc đó là ngày khách
	// thật sự dừng, và lượt bấm nhầm thứ hai sẽ dời nó tới hôm nay.
	if hd.Status == domain.SubscriptionCanceled {
		return domain.ErrHopDongDaHuy
	}

	return s.hopDong.Huy(ctx, id, strings.TrimSpace(lyDo))
}

func (s *dungThuService) ChiTiet(ctx context.Context, quyen domain.QuyenApp, id uint) (dto.HopDongChiTiet, error) {
	hd, err := s.timTrongPhamVi(ctx, quyen, id)
	if err != nil {
		return dto.HopDongChiTiet{}, err
	}

	ct := chiTietHopDong(*hd)
	ct.QuanTri = s.quanTri(ctx, hd.TenantID)

	return ct, nil
}

// quanTri đọc tài khoản quản trị của khách từ DATA PLANE.
//
// Hỏng thì trả nil chứ KHÔNG làm hỏng cả màn hình: hợp đồng và điều khoản nằm ở
// control plane và đã đọc xong: chúng vẫn đúng, vẫn phải hiện ra. Mất một khối
// phụ thì màn hình nói "chưa đọc được", còn ném lỗi lên thì người dùng mất luôn
// thứ họ mở trang này để xem.
func (s *dungThuService) quanTri(ctx context.Context, tenantID uint) *dto.QuanTriItem {
	qt, err := s.taiKhoan.QuanTri(ctx, tenantID)
	if err != nil {
		if !errors.Is(err, domain.ErrNotFound) {
			logger.Warn("không đọc được tài khoản quản trị của cửa hàng",
				zap.Uint("tenant_id", tenantID), zap.Error(err))
		}

		return nil
	}

	return &dto.QuanTriItem{
		TenDangNhap: qt.TenDangNhap,
		HoTen:       qt.HoTen,
		Email:       qt.Email,
		TrangThai:   qt.TrangThai,
	}
}

// DoiMatKhau đặt lại mật khẩu tài khoản quản trị của khách.
//
// Đi qua id HỢP ĐỒNG chứ không id cửa hàng: phạm vi phần mềm của người gọi được
// xét trên hợp đồng (timTrongPhamVi), và đó là chốt chặn duy nhất giữ cho người
// phụ trách phần mềm này không đổi được mật khẩu khách của phần mềm kia.
func (s *dungThuService) DoiMatKhau(ctx context.Context, quyen domain.QuyenApp, id uint, matKhau string) (dto.QuanTriItem, error) {
	var rong dto.QuanTriItem

	if len([]rune(matKhau)) < 6 {
		return rong, loiO(map[string]string{"mat_khau": "Mật khẩu tối thiểu 6 ký tự"})
	}

	hd, err := s.timTrongPhamVi(ctx, quyen, id)
	if err != nil {
		return rong, err
	}

	qt, err := s.taiKhoan.QuanTri(ctx, hd.TenantID)
	if err != nil {
		return rong, err
	}

	bam, err := hash.Hash(matKhau)
	if err != nil {
		return rong, err
	}
	if err := s.taiKhoan.DoiMatKhau(ctx, hd.TenantID, qt.ID, bam); err != nil {
		return rong, err
	}

	// Trả lại tài khoản vừa đổi để màn hình đọc TÊN ĐĂNG NHẬP ra cho khách nghe —
	// mật khẩu mới thì người bấm vừa gõ, còn tên đăng nhập thì họ có thể không
	// nhớ, và hai thứ đó phải đi cùng nhau mới đăng nhập được.
	return dto.QuanTriItem{
		TenDangNhap: qt.TenDangNhap,
		HoTen:       qt.HoTen,
		Email:       qt.Email,
		TrangThai:   qt.TrangThai,
	}, nil
}

func (s *dungThuService) Sua(ctx context.Context, quyen domain.QuyenApp, id uint, req dto.SuaHopDongRequest) (dto.HopDongChiTiet, error) {
	var rong dto.HopDongChiTiet

	hd, err := s.timTrongPhamVi(ctx, quyen, id)
	if err != nil {
		return rong, err
	}
	// Hợp đồng đã huỷ là bản ghi lịch sử. Sửa nó là sửa lại quá khứ, và không ai
	// đang cần nội dung đó đúng lên — khách quay lại thì ký hợp đồng mới.
	if hd.Status == domain.SubscriptionCanceled {
		return rong, domain.ErrHopDongDaHuy
	}

	ten := strings.TrimSpace(req.TenCuaHang)
	if ten == "" {
		return rong, loiO(map[string]string{"ten_cua_hang": "Trường này là bắt buộc"})
	}

	hs := domain.SuaHopDong{
		TenCuaHang:    ten,
		NguoiLienHe:   strings.TrimSpace(req.NguoiLienHe),
		DienThoai:     strings.TrimSpace(req.DienThoai),
		Email:         strings.ToLower(strings.TrimSpace(req.Email)),
		GhiChuKhach:   strings.TrimSpace(req.GhiChuKhach),
		GhiChuHopDong: strings.TrimSpace(req.GhiChuHopDong),
	}

	if raw := strings.TrimSpace(req.HetHan); raw != "" {
		// Đổi hạn TRỰC TIẾP chỉ dành cho hợp đồng DÙNG THỬ. Hợp đồng đã trả tiền
		// muốn dài thêm thì đi đường gia hạn — ở đó mốc mới tính từ hạn cũ hoặc
		// hôm nay, và trạng thái được đưa về 'active' cùng lúc. Cho sửa tay ở đây
		// là mở đường đẩy hạn ra xa mà không ai đối chiếu với đường tiền.
		if !suaDuocHan(*hd) {
			return rong, loiO(map[string]string{
				"het_han": "Chỉ hợp đồng đang dùng thử mới đổi ngày hết hạn trực tiếp. Hợp đồng chính thức thì dùng Gia hạn.",
			})
		}

		moc, err := docMocHetHan(raw)
		if err != nil {
			return rong, loiO(map[string]string{"het_han": err.Error()})
		}
		if moc.Before(time.Now()) {
			return rong, loiO(map[string]string{"het_han": "Thời điểm hết hạn phải ở tương lai"})
		}
		hs.HetHan = &moc
	}

	if err := s.hopDong.Sua(ctx, id, hd.TenantID, hs); err != nil {
		return rong, err
	}

	// Đẩy hạn dùng thử ra tương lai cũng là một lượt "cho khách dùng tiếp", nên
	// nó cũng phải mở khoá — hợp đồng vừa quá hạn hôm qua thì cửa hàng đang bị
	// lượt quét khoá lại rồi. Xem chú thích ở GiaHan.
	if hs.HetHan != nil {
		s.moKhoa(ctx, hd.TenantID)
	}

	// Đọc lại thay vì tự ghép câu trả lời từ req: chỉ lượt đọc mới nói được thứ
	// THẬT SỰ nằm dưới database sau khi ghi.
	moi, err := s.hopDong.Tim(ctx, id)
	if err != nil {
		return rong, err
	}

	return chiTietHopDong(*moi), nil
}

// CuaHangChuaKy liệt kê cửa hàng đã có ở data plane nhưng chưa có hợp đồng còn
// hiệu lực cho phần mềm này.
//
// PHÉP TRỪ LÀM Ở ĐÂY, không phải dưới database: danh sách cửa hàng nằm ở data
// plane còn danh sách hợp đồng nằm ở control plane, và không câu truy vấn nào
// JOIN được hai lược đồ. Đây đúng là chỗ dành cho việc ghép hai plane — service
// là tầng duy nhất cầm cả hai kết nối.
func (s *dungThuService) CuaHangChuaKy(ctx context.Context, quyen domain.QuyenApp, maApp string) (dto.CuaHangCoSanResponse, error) {
	ra := dto.CuaHangCoSanResponse{CuaHang: []dto.CuaHangCoSanItem{}}

	if maApp != "" && !quyen.ChoPhep(maApp) {
		return ra, domain.ErrKhongPhuTrachApp
	}

	tatCa, err := s.cuaHang.DanhSach(ctx)
	if err != nil {
		return ra, err
	}

	daKy, err := s.hopDong.TenantDangCoHopDong(ctx, maApp)
	if err != nil {
		return ra, err
	}
	bo := make(map[uint]bool, len(daKy))
	for _, id := range daKy {
		bo[id] = true
	}

	for _, ch := range tatCa {
		if bo[ch.ID] {
			continue
		}
		ra.CuaHang = append(ra.CuaHang, dto.CuaHangCoSanItem{
			Ma:        ch.Ma,
			Ten:       ch.Ten,
			TrangThai: ch.TrangThai,
		})
	}

	return ra, nil
}

// KyHopDong ký hợp đồng chính thức cho một cửa hàng đã tồn tại.
func (s *dungThuService) KyHopDong(ctx context.Context, quyen domain.QuyenApp, req dto.KyHopDongRequest) (dto.HopDongItem, error) {
	var rong dto.HopDongItem

	ma := strings.ToLower(strings.TrimSpace(req.MaCuaHang))
	if ma == "" {
		return rong, loiO(map[string]string{"ma_cua_hang": "Trường này là bắt buộc"})
	}

	// Bảng giá đọc TRƯỚC: mọi lý do từ chối nằm ở đây (gói ngừng bán, giá "Liên
	// hệ", bảng giá thiếu hạn mức), và biết sớm thì không phải dọn gì cả.
	bg, err := s.plan.Find(ctx, req.PlanID)
	if err != nil {
		return rong, err
	}
	if !quyen.ChoPhep(bg.AppCode) {
		return rong, domain.ErrKhongPhuTrachApp
	}
	if bg.Status != domain.PlanStatusActive {
		return rong, domain.ErrGoiNgungBan
	}
	if bg.AppStatus != domain.AppActive {
		return rong, domain.ErrAppChuaBan
	}

	tinhNang, err := s.plan.Features(ctx, bg.ID)
	if err != nil {
		return rong, err
	}
	dieuKhoan, err := chotDieuKhoan(bg, tinhNang)
	if err != nil {
		return rong, err
	}

	ch, err := s.cuaHang.TimTheoMa(ctx, ma)
	if err != nil {
		if errors.Is(err, domain.ErrNotFound) {
			return rong, loiO(map[string]string{
				"ma_cua_hang": "Không có cửa hàng nào mang mã này. Khách mới thì dùng \"Thêm tài khoản dùng thử\".",
			})
		}

		return rong, err
	}

	// Độ dài mặc định là MỘT CHU KỲ của gói: gói theo tháng thì 1 tháng, theo
	// năm thì 12. Bắt gõ lại con số đó ở mọi lượt ký là bắt làm việc máy đã biết.
	soThang := req.SoThang
	if soThang <= 0 {
		soThang = 1
		if bg.BillingCycle == domain.CycleNam {
			soThang = 12
		}
	}

	batDau := time.Now()
	hd := domain.Subscription{
		TenantID: ch.ID,
		AppID:    bg.AppID,
		Plan:     bg.Code,
		PlanID:   &bg.ID,
		// `active` ngay, KHÔNG qua `trial`: đây là hợp đồng đã bán, không phải kỳ
		// dùng thử. Và trial_ends_at để nil — hợp đồng này không có giai đoạn thử.
		Status:       domain.SubscriptionActive,
		BillingCycle: bg.BillingCycle,
		Price:        dieuKhoan.Gia,
		MaxShops:     dieuKhoan.ChiNhanh,
		MaxUsers:     dieuKhoan.TaiKhoan,
		MaxProducts:  dieuKhoan.SanPham,
		OwnDomain:    dieuKhoan.TenMienRieng,
		StartedAt:    batDau,
		EndsAt:       batDau.AddDate(0, soThang, 0),
		Note:         domain.StringOrNull(strings.TrimSpace(req.GhiChu)),
	}

	// Chép khách sang sổ nền tảng trước — subscriptions có khoá ngoại trỏ vào
	// `tenants` của CHÍNH lược đồ control plane, và khách này có thể chưa từng
	// vào sổ đó (cửa hàng dựng bằng `cmd/tao-admin` chẳng hạn).
	if err := s.hopDong.UpsertKhachHang(ctx, domain.PlatformTenant{
		ID:     ch.ID,
		Code:   ch.Ma,
		Name:   ch.Ten,
		Status: ch.TrangThai,
	}); err != nil {
		return rong, err
	}

	if err := s.hopDong.Tao(ctx, &hd); err != nil {
		return rong, err
	}

	moi, err := s.hopDong.Tim(ctx, hd.ID)
	if err != nil {
		return rong, err
	}

	return hopDongItem(*moi, time.Now()), nil
}

// ThuTien ghi một lần tiền vào sổ thu.
//
// Bản dịch sang HTTP của `cmd/thue-bao thu-tien`, giữ nguyên mọi luật của nó —
// nhất là cách tính chu kỳ, thứ quyết định sổ thu có chồng lấn hay hở khoảng.
func (s *dungThuService) ThuTien(ctx context.Context, quyen domain.QuyenApp, id uint, req dto.ThuTienRequest) (dto.ThuTienResponse, error) {
	var rong dto.ThuTienResponse

	hd, err := s.timTrongPhamVi(ctx, quyen, id)
	if err != nil {
		return rong, err
	}
	// Hợp đồng đã huỷ vẫn giữ nguyên sổ thu cũ để tra lại, nhưng không nhận thêm
	// tiền: khách quay lại thì ký hợp đồng mới, và tiền của họ thuộc về hợp đồng
	// đó chứ không phải hợp đồng đã đóng.
	if hd.Status == domain.SubscriptionCanceled {
		return rong, domain.ErrHopDongDaHuy
	}

	// Số tiền mặc định là GIÁ HỢP ĐỒNG, không phải giá bảng giá hiện hành —
	// khách trả theo thứ đã ký. Khai số khác khi thu thiếu, thu gộp, hoặc có
	// chiết khấu một lần.
	soTien := req.SoTien
	if soTien == 0 {
		soTien = hd.Price
	}
	if soTien <= 0 {
		return rong, loiO(map[string]string{"so_tien": "Số tiền phải lớn hơn 0"})
	}

	hinhThuc := strings.TrimSpace(req.HinhThuc)
	if hinhThuc == "" {
		hinhThuc = domain.PaymentChuyenKhoan
	}

	// Chu kỳ được trả cho: từ ĐIỂM CUỐI CỦA KỲ ĐÃ TRẢ GẦN NHẤT (hoặc ngày bắt
	// đầu hợp đồng nếu chưa trả lần nào) tới hạn hiện tại. Hai lần thu liên tiếp
	// nhờ vậy không chồng lấn nhau, và cũng không để hở khoảng nào ở giữa.
	//
	// CẮT VỀ NGÀY, bỏ giờ: `period_start`/`period_end` dưới database là DATE còn
	// `started_at`/`ends_at` là DATETIME. So hai thứ đó nguyên vẹn thì một kỳ
	// "14/08 → 14/08" vẫn lọt qua mọi lượt kiểm chỉ vì lệch nhau vài giờ, và sổ
	// thu nhận một dòng dài 0 ngày.
	kyTu, kyDen := catNgay(hd.StartedAt), catNgay(hd.EndsAt)
	tuDong := true

	kyCuoi, err := s.hopDong.KyCuoiDaThu(ctx, id)
	if err != nil {
		return rong, err
	}
	if kyCuoi != nil && catNgay(*kyCuoi).After(kyTu) {
		kyTu = catNgay(*kyCuoi)
	}

	if raw := strings.TrimSpace(req.KyTu); raw != "" {
		t, err := time.ParseInLocation("2006-01-02", raw, time.Local)
		if err != nil {
			return rong, loiO(map[string]string{"ky_tu": "Ngày phải theo dạng 2006-01-02"})
		}
		kyTu, tuDong = t, false
	}
	if raw := strings.TrimSpace(req.KyDen); raw != "" {
		t, err := time.ParseInLocation("2006-01-02", raw, time.Local)
		if err != nil {
			return rong, loiO(map[string]string{"ky_den": "Ngày phải theo dạng 2006-01-02"})
		}
		kyDen, tuDong = t, false
	}

	if !kyDen.After(kyTu) {
		// Trường hợp thường gặp nhất của nhánh này KHÔNG phải gõ nhầm ngày, mà là
		// hợp đồng đã được trả tiền tới đúng hạn hiện tại. Nói thẳng điều đó, kèm
		// việc phải làm tiếp — báo "ngày cuối phải sau ngày đầu" thì người thu
		// tiền sẽ đi sửa ngày, và sửa xong là ghi ra một kỳ chồng lấn.
		if tuDong {
			return rong, domain.ErrKhongConKyDeThu
		}

		return rong, loiO(map[string]string{"ky_den": "Ngày cuối kỳ phải sau ngày đầu kỳ"})
	}

	if err := s.hopDong.ThuTien(ctx, &domain.Invoice{
		SubscriptionID: id,
		Amount:         soTien,
		PeriodStart:    kyTu,
		PeriodEnd:      kyDen,
		PaidAt:         time.Now(),
		Method:         hinhThuc,
		Reference:      domain.StringOrNull(strings.TrimSpace(req.MaGiaoDich)),
		Note:           domain.StringOrNull(strings.TrimSpace(req.GhiChu)),
	}); err != nil {
		return rong, err
	}

	tong, soLan, err := s.hopDong.TongDaThu(ctx, id)
	if err != nil {
		return rong, err
	}

	return dto.ThuTienResponse{
		SoTien:    soTien,
		HinhThuc:  hinhThuc,
		KyTu:      kyTu,
		KyDen:     kyDen,
		TongDaThu: tong,
		SoLanThu:  soLan,
	}, nil
}

// catNgay bỏ phần giờ, giữ lại đúng ngày theo giờ máy chủ.
func catNgay(t time.Time) time.Time {
	return time.Date(t.Year(), t.Month(), t.Day(), 0, 0, 0, 0, time.Local)
}

// docMocHetHan đọc mốc hết hạn người dùng gõ, nhận cả có giờ lẫn ngày trần.
//
// time.Local ở mọi dạng, không phải UTC: người gõ "30/09 17:30" đang nghĩ theo
// giờ Việt Nam, và `ends_at` cũng được ghi theo giờ máy chủ. Đọc bằng UTC thì
// hạn lệch đi bảy tiếng — đủ để một hợp đồng chết sớm hơn nửa buổi làm việc mà
// không ai hiểu vì sao.
//
// Ngày trần lấy CUỐI ngày, không phải 00:00: "hết hạn ngày 30" trong đầu người
// nói nghĩa là hết ngày 30. Hiểu theo cách kia là cắt của khách trọn một ngày,
// và gõ hạn đúng hôm nay thì hợp đồng quá hạn ngay lúc bấm Lưu.
func docMocHetHan(raw string) (time.Time, error) {
	// Thứ tự quan trọng: dạng có giây trước dạng không giây, và cả hai trước ngày
	// trần. `2006-01-02T15:04:05` mà thử bằng layout `2006-01-02` sẽ hỏng, nhưng
	// `2006-01-02` thử bằng layout có giờ cũng hỏng — nên chỉ cần thử đủ, không
	// cần cầu kỳ.
	for _, layout := range []string{"2006-01-02T15:04:05", "2006-01-02T15:04"} {
		if t, err := time.ParseInLocation(layout, raw, time.Local); err == nil {
			return t, nil
		}
	}

	if t, err := time.ParseInLocation("2006-01-02", raw, time.Local); err == nil {
		return t.Add(24*time.Hour - time.Second), nil
	}

	return time.Time{}, errors.New("Thời điểm hết hạn phải theo dạng 2006-01-02T15:04 hoặc 2006-01-02")
}

// chiTietHopDong dịch một dòng hợp đồng sang DTO chi tiết.
//
// Dựng trên hopDongItem chứ không chép lại phép dịch: danh sách và chi tiết phải
// in CÙNG một con số cho cùng một cột, và hai bản dịch song song là hai cơ hội
// để chúng lệch nhau.
func chiTietHopDong(row domain.HopDongDayDu) dto.HopDongChiTiet {
	return dto.HopDongChiTiet{
		HopDongItem:      hopDongItem(row, time.Now()),
		HetDungThu:       row.TrialEndsAt,
		GhiChuHopDong:    string(row.Note),
		GhiChuKhach:      string(row.GhiChuKhach),
		NgayVaoSo:        row.NgayVaoSo,
		TrangThaiCuaHang: row.TrangThaiCuaHang,
		// Luật quyết ở đây, một chỗ — màn hình chỉ đọc cờ này để biết có hiện ô đổi
		// hạn không. Chép luật lên Blade là để giao diện và máy chủ lệch nhau, và
		// người dùng gặp cái lệch đó dưới dạng một ô nhập bị từ chối lúc bấm Lưu.
		SuaDuocHan: suaDuocHan(row),
	}
}

// suaDuocHan: hợp đồng này có được đổi ngày hết hạn trực tiếp không.
//
// MỘT chỗ cho cả hai nơi cần biết — cờ gửi ra màn hình và lượt kiểm lúc ghi.
// Hai bản của cùng một luật thì bản nào lệch cũng cho ra cùng một triệu chứng:
// màn hình hiện ô nhập rồi máy chủ từ chối đúng thứ nó vừa mời người ta gõ.
//
// `past_due` được tính vào, và đó là phần dễ bỏ sót: từ khi lượt quét hạn tự
// đánh dấu (xem QuetHanService), một hợp đồng thử vừa hết hạn KHÔNG còn mang
// trạng thái `trial` nữa. Xét mỗi `trial` thì "cho khách thêm ba ngày" — việc
// duy nhất người bán muốn làm với một hợp đồng thử vừa chết — lại là việc màn
// hình không cho làm.
//
// TrialEndsAt là thứ phân biệt hợp đồng thử quá hạn với hợp đồng ĐÃ TRẢ TIỀN
// quá hạn: cột đó chỉ có ở hợp đồng có giai đoạn thử, và GiaHan xoá nó đi đúng
// lúc chuyển sang chính thức.
func suaDuocHan(row domain.HopDongDayDu) bool {
	switch row.Status {
	case domain.SubscriptionTrial:
		return true
	case domain.SubscriptionPastDue:
		return row.TrialEndsAt != nil
	default:
		return false
	}
}

// timTrongPhamVi đọc hợp đồng và đối chiếu phạm vi phần mềm của người gọi.
//
// MỘT chỗ duy nhất cho cả hai đường ghi. Chép luật này vào từng hàm là cách chắc
// chắn để hàm thứ ba quên nó, và hàm quên sẽ cho người phụ trách phần mềm này
// huỷ hợp đồng của phần mềm kia.
func (s *dungThuService) timTrongPhamVi(ctx context.Context, quyen domain.QuyenApp, id uint) (*domain.HopDongDayDu, error) {
	hd, err := s.hopDong.Tim(ctx, id)
	if err != nil {
		return nil, err
	}
	if !quyen.ChoPhep(hd.MaApp) {
		return nil, domain.ErrKhongPhuTrachApp
	}

	return hd, nil
}

// dieuKhoanChot là bộ điều khoản đã chốt để ghi vào hợp đồng.
type dieuKhoanChot struct {
	Gia          float64
	ChiNhanh     uint
	TaiKhoan     uint
	SanPham      uint
	TenMienRieng bool
	// Nguon nói mỗi con số đến từ đâu ("bảng giá" / "khai tay"), để màn hình in
	// lại biên bản chép giống bảng đối chiếu của `cmd/thue-bao ky`. Ghi một hợp
	// đồng mà không nói được con số ở đâu ra là thứ không ai kiểm lại được.
	Nguon map[string]string
}

// chotDieuKhoan chép điều khoản từ bảng giá sang hợp đồng.
//
// Đường này KHÔNG cho khai tay, khác `cmd/thue-bao ky`. Chủ ý: đây là màn hình
// mở TÀI KHOẢN DÙNG THỬ, và hợp đồng thử thì chạy đúng theo gói đang bán. Thoả
// thuận riêng (chiết khấu, hạn mức đặc biệt) là việc của hợp đồng chính thức, và
// nó vẫn đi qua công cụ dòng lệnh — nơi có bảng đối chiếu in ra trước mắt người
// ký.
func chotDieuKhoan(bg *domain.PlanWithApp, tinhNang map[string]string) (dieuKhoanChot, error) {
	var dk dieuKhoanChot
	dk.Nguon = map[string]string{}

	// Giá NULL = "Liên hệ": chưa có giá công khai, KHÁC hẳn 0 (miễn phí). Không
	// có số để chép, và đoán hộ một con số tiền là việc không ai được phép làm.
	if bg.Price == nil {
		return dk, domain.ErrBangGiaChuaCoGia
	}
	dk.Gia = *bg.Price
	dk.Nguon["gia"] = "bảng giá"

	hanMuc := []struct {
		khoa string
		nhan string
		dich *uint
	}{
		{domain.FeatureMaxShops, "chi nhánh", &dk.ChiNhanh},
		{domain.FeatureMaxUsers, "tài khoản", &dk.TaiKhoan},
		{domain.FeatureMaxProducts, "sản phẩm", &dk.SanPham},
	}
	for _, hm := range hanMuc {
		n, nguon, err := chotMotHanMuc(tinhNang, hm.khoa, hm.nhan)
		if err != nil {
			return dk, err
		}
		*hm.dich = n
		dk.Nguon[hm.khoa] = nguon
	}

	// Tên miền riêng khác các hạn mức số: KHÔNG có dòng nghĩa là KHÔNG kèm (mặc
	// định của một gói), nên ở đây không phải từ chối.
	dk.TenMienRieng = tinhNang[domain.FeatureOwnDomain] == "1"
	dk.Nguon[domain.FeatureOwnDomain] = "bảng giá"

	return dk, nil
}

// chotMotHanMuc dịch một khoá của bảng giá thành con số ghi vào hợp đồng.
//
// BA TRẠNG THÁI, và cái thứ ba là lý do hàm này tồn tại:
//   - có số         → chép sang;
//   - 'vo_han'      → 0 (không giới hạn);
//   - KHÔNG có dòng → bảng giá không quy định, và đường này TỪ CHỐI.
func chotMotHanMuc(tinhNang map[string]string, khoa, nhan string) (uint, string, error) {
	v, co := tinhNang[khoa]
	if !co {
		return 0, "", fmt.Errorf("%w: số %s — khai ở màn hình Tính năng gói rồi ký lại", domain.ErrBangGiaThieuHanMuc, nhan)
	}
	if v == domain.VoHan {
		return 0, "bảng giá: không giới hạn", nil
	}

	n, err := strconv.ParseUint(v, 10, 32)
	if err != nil {
		return 0, "", fmt.Errorf("%w: số %s đang ghi %q, không đọc ra số được", domain.ErrBangGiaThieuHanMuc, nhan, v)
	}

	return uint(n), "bảng giá", nil
}

// chuanHoaCuaHang cắt khoảng trắng, hạ chữ thường những ô cần, và kiểm định dạng.
//
// Trả về lỗi THEO Ô chứ không phải một câu chung: form có sáu ô, và "dữ liệu
// không hợp lệ" thì người gõ phải tự đoán ô nào.
//
// Hạ chữ thường mã cửa hàng và tên đăng nhập: hai ô đó khách sẽ gõ lại ở màn
// hình đăng nhập, thường trên điện thoại có tự viết hoa chữ đầu. Không hạ ở đây
// thì "Quochuy" tạo ra một cửa hàng mà chính khách không đăng nhập vào được.
func chuanHoaCuaHang(req dto.KhachHangMoiChung) (domain.CuaHangMoi, map[string]string) {
	loi := map[string]string{}

	moi := domain.CuaHangMoi{
		Ma:          strings.ToLower(strings.TrimSpace(req.MaCuaHang)),
		Ten:         strings.TrimSpace(req.TenCuaHang),
		TenDangNhap: strings.ToLower(strings.TrimSpace(req.TenDangNhap)),
		HoTen:       strings.TrimSpace(req.HoTen),
	}

	if !maCuaHangRe.MatchString(moi.Ma) {
		loi["ma_cua_hang"] = "Mã cửa hàng gồm chữ thường không dấu, số, dấu chấm, gạch ngang hoặc gạch dưới (2–30 ký tự), bắt đầu bằng chữ hoặc số"
	}
	if moi.Ten == "" {
		loi["ten_cua_hang"] = "Trường này là bắt buộc"
	}
	if !usernameRe.MatchString(moi.TenDangNhap) {
		loi["ten_dang_nhap"] = "Tên đăng nhập chỉ gồm chữ thường không dấu, số, dấu chấm, gạch ngang hoặc gạch dưới (3–50 ký tự)"
	}
	if len([]rune(req.MatKhau)) < 6 {
		loi["mat_khau"] = "Mật khẩu tối thiểu 6 ký tự"
	}

	// Họ tên người quản trị: lấy theo NGƯỜI LIÊN HỆ nếu không khai riêng.
	//
	// Hai ô đó gần như luôn là một người — chủ tiệm vừa là người mình gọi vừa là
	// người ngồi gõ phần mềm. Bắt điền cả hai là bắt gõ cùng một cái tên hai lần,
	// rồi lần thứ hai gõ khác đi một chữ. Ô riêng vẫn còn trong DTO cho những nơi
	// gọi API thẳng và cần tách bạch.
	if moi.HoTen == "" {
		moi.HoTen = strings.TrimSpace(req.NguoiLienHe)
	}
	if moi.HoTen == "" {
		moi.HoTen = "Quản trị viên"
	}

	// `users.email` KHÔNG hỏi người bán — máy tự đặt, luôn luôn.
	//
	// Cột đó là NOT NULL nên phải có gì đó, nhưng nó không phải thông tin đăng
	// nhập: khách vào Sellio Order bằng ba ô (/auth/shop-login), còn đăng nhập
	// bằng email (/auth/login) và quên-mật-khẩu-qua-email chỉ tồn tại trong cụm
	// storefront dành cho KHÁCH MUA SẮM, cụm đang tắt mặc định.
	//
	// Đuôi .local theo đúng quy ước của `cmd/tao-admin`, và cố ý là một tên miền
	// không tồn tại: nó nói thẳng ra đây là địa chỉ máy đặt, không phải hộp thư
	// của ai. Email thật của khách nằm ở `tenants.contact_email` bên sổ khách
	// hàng, hỏi bằng một ô riêng.
	moi.Email = moi.TenDangNhap + "@" + moi.Ma + ".local"

	return moi, loi
}
