package service

import (
	"context"
	"strings"
	"time"

	"sass-api/internal/domain"
	"sass-api/internal/dto"
	"sass-api/pkg/bimat"
	"sass-api/pkg/minvoice"
	"sass-api/pkg/sepayinvoice"
)

// EtaxService — kết nối cổng HOÁ ĐƠN ĐIỆN TỬ của từng chi nhánh.
//
// Tệp này lo việc NỐI DÂY: đăng nhập, kéo về danh sách ký hiệu đã đăng ký với
// cơ quan thuế, chọn ký hiệu dùng để phát hành, và ngắt. Việc xuất hoá đơn cho
// một đơn hàng nằm ở etax_phat_hanh.go.
//
// Hai nhà cung cấp: M-Invoice và SePay. Phần riêng của SePay (tài khoản nhà
// cung cấp, hoá đơn bất đồng bộ) nằm ở etax_sepay.go.
type EtaxService interface {
	// Xem trả kết nối của một chi nhánh kèm danh sách mẫu. ErrNotFound = chưa nối.
	Xem(ctx context.Context, shopID uint) (*domain.EtaxConnection, error)
	// KetNoi đăng nhập rồi lưu tài khoản. Gọi lại trên chi nhánh đã nối = khai
	// lại tài khoản (đổi mật khẩu, đổi mã số thuế).
	KetNoi(ctx context.Context, shopID uint, req *dto.EtaxKetNoiRequest) (*domain.EtaxConnection, error)
	// CapNhat đổi ký hiệu phát hành và hai công tắc tự động.
	CapNhat(ctx context.Context, shopID uint, req *dto.EtaxCaiDatRequest) (*domain.EtaxConnection, error)
	// DongBoMau kéo lại danh sách ký hiệu từ nhà cung cấp.
	DongBoMau(ctx context.Context, shopID uint) (*domain.EtaxConnection, error)
	NgatKetNoi(ctx context.Context, shopID uint) error

	// XemHoaDon trả hoá đơn đã phát hành của một đơn. ErrNotFound = chưa có.
	XemHoaDon(ctx context.Context, orderID uint) (*domain.EtaxInvoice, error)
	// PhatHanh xuất hoá đơn cho một đơn hàng — xem etax_phat_hanh.go.
	PhatHanh(ctx context.Context, orderID uint) (*domain.EtaxInvoice, error)
	// PhatHanhKemNguoiMua ghi người mua vào đơn rồi mới phát hành.
	PhatHanhKemNguoiMua(ctx context.Context, orderID uint, req *dto.EtaxPhatHanhRequest) (*domain.EtaxInvoice, error)
	// TuPhatHanh là đường cho chỗ ĐƠN VỪA THU TIỀN gọi tới; nuốt mọi lỗi.
	TuPhatHanh(ctx context.Context, orderID uint)

	// Đời sau của một tờ hoá đơn — xem etax_hoa_don.go.

	// Ky ký một tờ đã lưu nháp rồi gửi cơ quan thuế.
	Ky(ctx context.Context, orderID uint) (*domain.EtaxInvoice, error)
	// DongBo hỏi lại cổng xem cơ quan thuế đã cấp mã chưa.
	DongBo(ctx context.Context, orderID uint) (*domain.EtaxInvoice, error)
	// ThayThe xuất một tờ THAY CHO tờ hiện tại, dựng lại từ đơn hàng hôm nay.
	ThayThe(ctx context.Context, orderID uint, req *dto.EtaxThayTheRequest) (*domain.EtaxInvoice, error)
	// DieuChinh xuất một tờ điều chỉnh; không khai dòng nào = điều chỉnh về 0.
	DieuChinh(ctx context.Context, orderID uint, req *dto.EtaxDieuChinhRequest) (*domain.EtaxInvoice, error)
	// BanIn tải bản PDF; chuyenDoi = bản hoá đơn chuyển đổi ra giấy.
	BanIn(ctx context.Context, orderID uint, chuyenDoi bool) ([]byte, error)
	// BanXML tải bản XML gốc đã ký.
	BanXML(ctx context.Context, orderID uint) ([]byte, error)
	// TraCuuMST tra tên và địa chỉ đăng ký của một mã số thuế.
	TraCuuMST(ctx context.Context, mst string) (*minvoice.ThongTinMST, error)

	// DanhSachHoaDon — sổ hoá đơn của màn "Hoá đơn điện tử". Chỉ đọc sổ trong
	// database, không gọi ra cổng: đồng bộ trạng thái là lượt bấm riêng.
	DanhSachHoaDon(ctx context.Context, f domain.HoaDonFilter) ([]domain.DongHoaDon, int64, domain.DemHoaDon, error)
}

type etaxService struct {
	repo     domain.EtaxRepository
	chiNhanh domain.ChiNhanhRepository
	// hop mã hoá mật khẩu cổng HĐĐT. Chưa khai khoá thì mọi lượt kết nối bị từ
	// chối kèm lý do — xem domain.ErrETaxChuaCoKhoa.
	hop *bimat.Hop
	mi  *minvoice.Client
	sp  *sepayinvoice.Client
	// donHang để đọc đơn cần phát hành hoá đơn.
	donHang domain.OrderRepository
}

func NewEtaxService(
	repo domain.EtaxRepository,
	chiNhanh domain.ChiNhanhRepository,
	donHang domain.OrderRepository,
	hop *bimat.Hop,
	mi *minvoice.Client,
	sp *sepayinvoice.Client,
) EtaxService {
	if sp == nil {
		sp = sepayinvoice.New("")
	}

	return &etaxService{repo: repo, chiNhanh: chiNhanh, donHang: donHang, hop: hop, mi: mi, sp: sp}
}

func (s *etaxService) Xem(ctx context.Context, shopID uint) (*domain.EtaxConnection, error) {
	if _, err := s.chiNhanh.FindByID(ctx, shopID); err != nil {
		return nil, err
	}

	return s.repo.TheoChiNhanh(ctx, shopID)
}

func (s *etaxService) KetNoi(ctx context.Context, shopID uint, req *dto.EtaxKetNoiRequest) (*domain.EtaxConnection, error) {
	// Chi nhánh phải có thật VÀ thuộc cửa hàng này — repo đã lọc theo tenant.
	if _, err := s.chiNhanh.FindByID(ctx, shopID); err != nil {
		return nil, err
	}

	nhaCC := strings.TrimSpace(req.Provider)
	if nhaCC == "" {
		nhaCC = domain.NhaCungCapMInvoice
	}
	if _, co := domain.NhaCungCapETax[nhaCC]; !co {
		return nil, domain.ErrETaxNhaCungCapLa
	}

	// Từ chối TRƯỚC khi gọi ra ngoài: đăng nhập thành công rồi mới phát hiện
	// không lưu được mật khẩu là bắt người dùng gõ lại một lượt vô ích.
	if !s.hop.SanSang() {
		return nil, domain.ErrETaxChuaCoKhoa
	}

	// Một tài khoản cổng chỉ cho một chi nhánh: M-Invoice nhận ra tài khoản qua
	// mã số thuế, SePay qua tên đăng nhập (khách không phải khai mã số thuế).
	mst := strings.TrimSpace(req.TaxCode)
	var daDung bool
	var err error
	if nhaCC == domain.NhaCungCapSePay {
		daDung, err = s.repo.TaiKhoanDaDung(ctx, nhaCC, strings.TrimSpace(req.Username), shopID)
	} else {
		if mst == "" {
			return nil, loiO(map[string]string{"tax_code": "Nhập mã số thuế"})
		}
		daDung, err = s.repo.MaSoThueDaDung(ctx, mst, shopID)
	}
	if err != nil {
		return nil, err
	}
	if daDung {
		if nhaCC == domain.NhaCungCapSePay {
			return nil, domain.ErrETaxTaiKhoanDaDung
		}

		return nil, domain.ErrETaxMSTDaDung
	}

	// Mã đơn vị cơ sở chỉ M-Invoice mới có.
	maDVCS := strings.TrimSpace(req.MaDVCS)
	if maDVCS == "" && nhaCC == domain.NhaCungCapMInvoice {
		maDVCS = "VP"
	}
	if nhaCC != domain.NhaCungCapMInvoice {
		maDVCS = ""
	}

	// Đăng nhập THẬT trước khi ghi: lưu một tài khoản chưa từng đăng nhập được
	// nghĩa là màn hình báo "đã kết nối" trong khi tới lúc phát hành mới vỡ.
	token, err := s.dangNhap(ctx, nhaCC, mst, strings.TrimSpace(req.Username), req.Password, maDVCS)
	if err != nil {
		return nil, err
	}

	// SePay: chốt luôn tài khoản nhà cung cấp — cũng là lượt thử token vừa lấy.
	var taiKhoan string
	if nhaCC == domain.NhaCungCapSePay {
		if taiKhoan, err = chonTaiKhoanSePay(ctx, s.sp.Cho(req.Username), token, req.AccountRef); err != nil {
			return nil, err
		}
	}

	daMa, err := s.hop.Ma(req.Password)
	if err != nil {
		return nil, err
	}

	// Nối lại trên chi nhánh đã có = khai lại tài khoản: giữ id để không tạo
	// dòng thứ hai, nhưng bỏ ký hiệu đang chọn vì nó thuộc tài khoản cũ.
	cn := &domain.EtaxConnection{ShopID: shopID}
	if cu, err := s.repo.TheoChiNhanh(ctx, shopID); err == nil {
		cn.ID = cu.ID
		cn.CreatedAt = cu.CreatedAt
		if cu.Provider == nhaCC && cu.TaxCode == mst && cu.Username == strings.TrimSpace(req.Username) && cu.AccountRef == taiKhoan {
			cn.TemplateSymbol = cu.TemplateSymbol
			cn.AutoRelease = cu.AutoRelease
			cn.AutoPrint = cu.AutoPrint
		}
	}

	gio := time.Now()
	cn.Provider = nhaCC
	cn.TaxCode = mst
	cn.Username = strings.TrimSpace(req.Username)
	cn.Password = daMa
	cn.MaDVCS = maDVCS
	cn.AccountRef = taiKhoan
	cn.Token = token
	cn.TokenSyncedAt = &gio
	cn.IsActive = true

	if err := s.repo.Luu(ctx, cn); err != nil {
		return nil, err
	}

	// Kéo mẫu ngay: hộp thoại chi tiết mở ra là phải có sẵn ô chọn ký hiệu.
	// Hỏng bước này KHÔNG huỷ lượt kết nối — tài khoản vẫn đúng, người dùng bấm
	// "Đồng bộ mẫu" là xong; huỷ cả lượt thì họ phải gõ lại mật khẩu.
	_ = s.keoMau(ctx, cn)

	return s.repo.TheoChiNhanh(ctx, shopID)
}

func (s *etaxService) CapNhat(ctx context.Context, shopID uint, req *dto.EtaxCaiDatRequest) (*domain.EtaxConnection, error) {
	cn, err := s.Xem(ctx, shopID)
	if err != nil {
		return nil, err
	}

	kyHieu := strings.TrimSpace(req.TemplateSymbol)
	if kyHieu != "" && !coKyHieu(cn.Templates, kyHieu) {
		return nil, domain.ErrETaxKyHieuLa
	}
	cn.TemplateSymbol = kyHieu

	if req.AutoRelease != nil {
		cn.AutoRelease = *req.AutoRelease
	}
	if req.AutoPrint != nil {
		cn.AutoPrint = *req.AutoPrint
	}

	if err := s.repo.Luu(ctx, cn); err != nil {
		return nil, err
	}

	return s.repo.TheoChiNhanh(ctx, shopID)
}

func (s *etaxService) DongBoMau(ctx context.Context, shopID uint) (*domain.EtaxConnection, error) {
	cn, err := s.Xem(ctx, shopID)
	if err != nil {
		return nil, err
	}
	if err := s.keoMau(ctx, cn); err != nil {
		return nil, err
	}

	return s.repo.TheoChiNhanh(ctx, shopID)
}

func (s *etaxService) NgatKetNoi(ctx context.Context, shopID uint) error {
	cn, err := s.Xem(ctx, shopID)
	if err != nil {
		return err
	}

	return s.repo.Xoa(ctx, cn.ID)
}

// keoMau kéo danh sách ký hiệu về và ghi đè bảng mẫu của kết nối.
//
// Token hết hạn thì đăng nhập lại MỘT lần rồi thử tiếp: token của cổng HĐĐT
// sống ngắn, mà bắt người dùng gõ lại mật khẩu mỗi lần nó hết hạn thì họ sẽ
// thôi dùng ô đồng bộ.
func (s *etaxService) keoMau(ctx context.Context, cn *domain.EtaxConnection) error {
	ds, err := s.mauHoaDon(ctx, cn)
	if err != nil {
		if err = s.lamMoiToken(ctx, cn); err != nil {
			return err
		}
		if ds, err = s.mauHoaDon(ctx, cn); err != nil {
			return err
		}
	}

	for i := range ds {
		ds[i].ConnectionID = cn.ID
	}

	return s.repo.LuuMau(ctx, cn.ID, ds)
}

func (s *etaxService) mauHoaDon(ctx context.Context, cn *domain.EtaxConnection) ([]domain.EtaxTemplate, error) {
	if cn.Provider == domain.NhaCungCapSePay {
		return s.mauSePay(ctx, cn)
	}

	ds, err := s.mi.MauHoaDonDaDangKy(ctx, cn.TaxCode, cn.Token)
	if err != nil {
		return nil, err
	}
	rows := make([]domain.EtaxTemplate, 0, len(ds))
	for _, m := range ds {
		rows = append(rows, domain.EtaxTemplate{Symbol: m.KyHieu, FormNo: m.MauSo, TypeName: m.TenLoai})
	}

	return rows, nil
}

// lamMoiToken giải mật khẩu đã lưu, đăng nhập lại và ghi token mới.
func (s *etaxService) lamMoiToken(ctx context.Context, cn *domain.EtaxConnection) error {
	if !s.hop.SanSang() {
		return domain.ErrETaxChuaCoKhoa
	}
	matKhau, err := s.hop.Giai(cn.Password)
	if err != nil {
		return err
	}

	token, err := s.dangNhap(ctx, cn.Provider, cn.TaxCode, cn.Username, matKhau, cn.MaDVCS)
	if err != nil {
		return err
	}

	gio := time.Now()
	cn.Token = token
	cn.TokenSyncedAt = &gio

	return s.repo.Luu(ctx, cn)
}

// dangNhap gọi đúng client của nhà cung cấp. Thêm nhà cung cấp là thêm một
// nhánh ở đây.
func (s *etaxService) dangNhap(ctx context.Context, nhaCC, mst, tenDangNhap, matKhau, maDVCS string) (string, error) {
	switch nhaCC {
	case domain.NhaCungCapMInvoice:
		return s.mi.DangNhap(ctx, mst, tenDangNhap, matKhau, maDVCS)
	case domain.NhaCungCapSePay:
		return s.sp.Cho(tenDangNhap).LayToken(ctx, tenDangNhap, matKhau)
	default:
		return "", domain.ErrETaxNhaCungCapLa
	}
}

func coKyHieu(ds []domain.EtaxTemplate, kyHieu string) bool {
	for _, m := range ds {
		if m.Symbol == kyHieu {
			return true
		}
	}

	return false
}

// DanhSachHoaDon đi thẳng xuống repository: màn danh sách chỉ đọc sổ.
func (s *etaxService) DanhSachHoaDon(ctx context.Context, f domain.HoaDonFilter) ([]domain.DongHoaDon, int64, domain.DemHoaDon, error) {
	return s.repo.DanhSachHoaDon(ctx, f)
}
