package service

import (
	"context"
	"errors"
	"testing"
	"time"

	"sass-api/internal/domain"
	"sass-api/pkg/bimat"
)

// KHÁCH TỰ GIA HẠN — bài kiểm tập trung vào chỗ mất tiền được.
//
// Hai chiều sai đều tốn tiền thật, và chúng ngược nhau:
//   · chốt đơn HAI LẦN  → khách trả một lần, hợp đồng dài gấp đôi;
//   · chốt đơn HỤT      → khách trả tiền rồi mà cửa hàng vẫn khoá.
//
// Vì vậy bài này không kiểm "hàm có chạy không" mà kiểm đúng hai điều đó, cộng
// với luật tiền phải đủ.

// ---------- sổ giả ----------

type fakeDonGiaHan struct {
	don      *domain.DonGiaHan
	soLanTra int
	daGan    uint
	trangCu  string
}

func (f *fakeDonGiaHan) Tao(_ context.Context, don *domain.DonGiaHan) error {
	don.ID, don.MaDon = 7, 7
	f.don = don

	return nil
}

func (f *fakeDonGiaHan) GanLink(context.Context, uint, domain.ThongTinTraTien) error { return nil }

func (f *fakeDonGiaHan) Tim(_ context.Context, tenantID, id uint) (*domain.DonGiaHan, error) {
	if f.don == nil || f.don.ID != id || f.don.TenantID != tenantID {
		return nil, domain.ErrNotFound
	}

	return f.don, nil
}

func (f *fakeDonGiaHan) TimTheoMaDon(_ context.Context, maDon uint) (*domain.DonGiaHan, error) {
	if f.don == nil || f.don.MaDon != maDon {
		return nil, domain.ErrNotFound
	}

	return f.don, nil
}

func (f *fakeDonGiaHan) DangCho(context.Context, uint) (*domain.DonGiaHan, error) { return nil, nil }

// DanhDauDaTra bắt chước ĐÚNG hành vi của câu UPDATE thật: chỉ đổi đơn đang chờ,
// và trả về số dòng đã đổi. Đây là chốt chặn chống chốt hai lần, nên sổ giả phải
// mô phỏng nó chứ không được luôn trả 1.
func (f *fakeDonGiaHan) DanhDauDaTra(_ context.Context, _, _ uint) (int64, error) {
	if f.don == nil || f.don.TrangThai != domain.DonChoThanhToan {
		return 0, nil
	}
	f.don.TrangThai = domain.DonDaThanhToan
	f.soLanTra++

	return 1, nil
}

func (f *fakeDonGiaHan) GanHoaDon(_ context.Context, _, invoiceID uint) error {
	f.daGan = invoiceID

	return nil
}

func (f *fakeDonGiaHan) DoiTrangThai(_ context.Context, _ uint, trangThai string) error {
	f.trangCu = trangThai
	if f.don != nil {
		f.don.TrangThai = trangThai
	}

	return nil
}

// fakeHopDongGiaHan ghi nhớ hai việc quan trọng nhất: đã ghi sổ thu chưa, và đã
// đẩy hạn mấy lần.
type fakeHopDongGiaHan struct {
	soLanGiaHan int
	soThangCong int
	soLanThu    int
	moKhoaSo    []uint
	loiGiaHan   error
	sapHetHan   []domain.HopDongQuaHan
	daNhac      map[uint]bool
}

func (f *fakeHopDongGiaHan) GiaHan(_ context.Context, _ uint, soThang int) error {
	if f.loiGiaHan != nil {
		return f.loiGiaHan
	}
	f.soLanGiaHan++
	f.soThangCong += soThang

	return nil
}

func (f *fakeHopDongGiaHan) ThuTien(_ context.Context, hd *domain.Invoice) error {
	f.soLanThu++
	hd.ID = 99

	return nil
}

func (f *fakeHopDongGiaHan) DoiTrangThaiKhach(_ context.Context, ids []uint, _ string) (int64, error) {
	f.moKhoaSo = append(f.moKhoaSo, ids...)

	return int64(len(ids)), nil
}

func (f *fakeHopDongGiaHan) UpsertKhachHang(context.Context, domain.PlatformTenant) error { return nil }
func (f *fakeHopDongGiaHan) Tao(context.Context, *domain.Subscription) error              { return nil }
func (f *fakeHopDongGiaHan) Tim(context.Context, uint) (*domain.HopDongDayDu, error) {
	return nil, nil
}
func (f *fakeHopDongGiaHan) Huy(context.Context, uint, string) error                  { return nil }
func (f *fakeHopDongGiaHan) Sua(context.Context, uint, uint, domain.SuaHopDong) error { return nil }
func (f *fakeHopDongGiaHan) KyCuoiDaThu(context.Context, uint) (*time.Time, error)    { return nil, nil }
func (f *fakeHopDongGiaHan) TongDaThu(context.Context, uint) (float64, int, error)    { return 0, 0, nil }
func (f *fakeHopDongGiaHan) TenantDangCoHopDong(context.Context, string) ([]uint, error) {
	return nil, nil
}
func (f *fakeHopDongGiaHan) QuaHan(context.Context, time.Time) ([]domain.HopDongQuaHan, error) {
	return nil, nil
}
func (f *fakeHopDongGiaHan) ConHopDongSong(context.Context, []uint, time.Time) ([]uint, error) {
	return nil, nil
}

func (f *fakeHopDongGiaHan) SapHetHan(context.Context, time.Time, time.Time) ([]domain.HopDongQuaHan, error) {
	return f.sapHetHan, nil
}
func (f *fakeHopDongGiaHan) DanhDauDaNhac(_ context.Context, id uint, _ time.Time, _ int) (bool, error) {
	if f.daNhac == nil {
		f.daNhac = map[uint]bool{}
	}
	if f.daNhac[id] {
		return false, nil
	}
	f.daNhac[id] = true

	return true, nil
}
func (f *fakeHopDongGiaHan) DanhDauQuaHan(context.Context, []uint) (int64, error) { return 0, nil }

// fakeCuaHangGiaHan chỉ quan tâm tới lượt mở khoá ở DATA PLANE — chốt chặn thật
// quyết định khách còn đăng nhập được hay không.
type fakeCuaHangGiaHan struct{ moKhoa []uint }

func (f *fakeCuaHangGiaHan) DoiTrangThai(_ context.Context, ids []uint, _ string) (int64, error) {
	f.moKhoa = append(f.moKhoa, ids...)

	return int64(len(ids)), nil
}
func (f *fakeCuaHangGiaHan) CoMa(context.Context, string) (bool, error) { return false, nil }
func (f *fakeCuaHangGiaHan) Tao(context.Context, domain.CuaHangMoi) (uint, error) {
	return 0, nil
}
func (f *fakeCuaHangGiaHan) DanhSach(context.Context) ([]domain.CuaHangCoSan, error) {
	return nil, nil
}
func (f *fakeCuaHangGiaHan) TimTheoMa(context.Context, string) (*domain.CuaHangCoSan, error) {
	return nil, nil
}

// dungGiaHan dựng service kèm một đơn ĐANG CHỜ trị giá 1.500.000đ cho 3 tháng.
func dungGiaHan() (*giaHanService, *fakeDonGiaHan, *fakeHopDongGiaHan, *fakeCuaHangGiaHan) {
	donRepo := &fakeDonGiaHan{don: &domain.DonGiaHan{
		ID: 7, MaDon: 7, TenantID: 23, SubscriptionID: 39,
		SoThang: 3, SoTien: 1_500_000, TrangThai: domain.DonChoThanhToan,
	}}
	hopDong := &fakeHopDongGiaHan{}
	cuaHang := &fakeCuaHangGiaHan{}

	svc := &giaHanService{
		don:     donRepo,
		hopDong: hopDong,
		cuaHang: cuaHang,
		hop:     bimat.New("khoa-thu-nghiem"),
		maApp:   domain.AppOrder,
	}

	return svc, donRepo, hopDong, cuaHang
}

// ---------- bài kiểm ----------

// Một lượt chốt đủ: ghi sổ thu, đẩy hạn ĐÚNG số tháng của đơn, mở khoá ở CẢ HAI
// plane. Thiếu vế mở khoá thì khách trả tiền xong vẫn nhận câu "cửa hàng đang
// tạm khoá, liên hệ nhà cung cấp" — từ chính người vừa nhận tiền của họ.
func TestChotDon_GhiSoThuDayHanVaMoKhoa(t *testing.T) {
	svc, donRepo, hopDong, cuaHang := dungGiaHan()

	if err := svc.chotDon(context.Background(), donRepo.don); err != nil {
		t.Fatalf("không mong lỗi: %v", err)
	}

	if hopDong.soLanThu != 1 {
		t.Errorf("phải ghi đúng 1 dòng sổ thu, nhận %d", hopDong.soLanThu)
	}
	if donRepo.daGan != 99 {
		t.Errorf("đơn phải nối với dòng sổ thu vừa ghi, nhận id %d", donRepo.daGan)
	}
	if hopDong.soLanGiaHan != 1 || hopDong.soThangCong != 3 {
		t.Errorf("phải đẩy hạn đúng 1 lần × 3 tháng, nhận %d lần / %d tháng",
			hopDong.soLanGiaHan, hopDong.soThangCong)
	}
	if len(cuaHang.moKhoa) != 1 || cuaHang.moKhoa[0] != 23 {
		t.Errorf("phải mở khoá cửa hàng 23 ở data plane, nhận %v", cuaHang.moKhoa)
	}
	if len(hopDong.moKhoaSo) != 1 || hopDong.moKhoaSo[0] != 23 {
		t.Errorf("phải mở khoá cửa hàng 23 ở sổ nền tảng, nhận %v", hopDong.moKhoaSo)
	}
}

// WEBHOOK TỚI HAI LẦN cho cùng một giao dịch là THIẾT KẾ của cổng, không phải sự
// cố. Lượt thứ hai không được đẩy hạn thêm lần nữa — nếu không, khách trả một
// lần mà hợp đồng dài gấp đôi.
func TestChotDon_GoiHaiLanChiDayHanMotLan(t *testing.T) {
	svc, donRepo, hopDong, _ := dungGiaHan()

	_ = svc.chotDon(context.Background(), donRepo.don)
	if err := svc.chotDon(context.Background(), donRepo.don); err != nil {
		t.Fatalf("lượt lặp lại KHÔNG được coi là lỗi: %v", err)
	}

	if hopDong.soLanGiaHan != 1 {
		t.Errorf("chỉ được đẩy hạn 1 lần, nhận %d", hopDong.soLanGiaHan)
	}
	if hopDong.soThangCong != 3 {
		t.Errorf("chỉ được cộng 3 tháng, nhận %d", hopDong.soThangCong)
	}
	if hopDong.soLanThu != 1 {
		t.Errorf("sổ thu chỉ được nhận 1 dòng, nhận %d", hopDong.soLanThu)
	}
	if donRepo.soLanTra != 1 {
		t.Errorf("đơn chỉ được chốt 1 lần, nhận %d", donRepo.soLanTra)
	}
}

// Đẩy hạn hỏng: PHẢI trả lỗi ra ngoài.
//
// Đây là lúc khách đã trả tiền mà không nhận được thứ họ mua, nên nó không được
// nuốt im lặng — webhook nhận lỗi sẽ gửi lại, và nhật ký có đủ dấu vết để gia
// hạn tay.
func TestChotDon_DayHanHongThiBaoLoi(t *testing.T) {
	svc, donRepo, hopDong, _ := dungGiaHan()
	hopDong.loiGiaHan = errors.New("database sập")

	err := svc.chotDon(context.Background(), donRepo.don)
	if err == nil {
		t.Fatal("đẩy hạn hỏng mà không báo lỗi thì không ai biết khách đã trả tiền hụt")
	}
	// Sổ thu vẫn phải có dòng tiền: khách đã trả thật.
	if hopDong.soLanThu != 1 {
		t.Errorf("tiền đã vào thì sổ thu phải có dòng, nhận %d", hopDong.soLanThu)
	}
}

// Số tháng của ĐƠN quyết định độ dài gia hạn, không phải một hằng số nào khác.
func TestChotDon_DungSoThangCuaDon(t *testing.T) {
	svc, donRepo, hopDong, _ := dungGiaHan()
	donRepo.don.SoThang = 12

	_ = svc.chotDon(context.Background(), donRepo.don)

	if hopDong.soThangCong != 12 {
		t.Errorf("đơn 12 tháng phải cộng 12 tháng, nhận %d", hopDong.soThangCong)
	}
}

// ---------- Giá tính theo KỲ của gói ----------

// fakeThueBaoDat trả về một hợp đồng đang chạy để Dat() có chỗ đẩy hạn.
type fakeThueBaoDat struct{}

func (fakeThueBaoDat) HienTai(context.Context, uint, string) (*domain.HopDongDayDu, error) {
	return &domain.HopDongDayDu{
		Subscription: domain.Subscription{ID: 39, AppID: 1},
		MaCuaHang:    "order1",
		TenApp:       "Sellio Order",
	}, nil
}

// fakeBangGiaDat trả về ĐÚNG một dòng bảng giá.
type fakeBangGiaDat struct {
	gia   float64
	chuKy string
}

func (f *fakeBangGiaDat) Find(context.Context, uint) (*domain.PlanWithApp, error) {
	return &domain.PlanWithApp{
		Plan: domain.Plan{
			ID: 2, Code: "cua_hang", Name: "Cửa hàng",
			BillingCycle: f.chuKy, Price: &f.gia, Status: domain.PlanStatusActive,
		},
		AppCode: domain.AppOrder,
	}, nil
}

func (f *fakeBangGiaDat) List(context.Context, string) ([]domain.PlanWithApp, error) {
	return nil, nil
}
func (f *fakeBangGiaDat) Features(context.Context, uint) (map[string]string, error) { return nil, nil }
func (f *fakeBangGiaDat) FeaturesOf(context.Context, []uint) (map[uint]map[string]string, error) {
	return nil, nil
}
func (f *fakeBangGiaDat) SaveFeatures(context.Context, uint, map[string]string, []string) error {
	return nil
}
func (f *fakeBangGiaDat) Sua(context.Context, uint, domain.SuaPlan) error { return nil }

// dungDat dựng service đủ để chạy tới bước CHỐT SỐ TIỀN rồi dừng.
//
// cauHinh nil nên bước gọi cổng thanh toán sẽ hỏng — cố ý: bài này chỉ hỏi "đơn
// được chốt bao nhiêu tiền", và số đó đã nằm trong sổ trước khi cổng được gọi.
func dungDat(gia float64, chuKy string) (*giaHanService, *fakeDonGiaHan) {
	donRepo := &fakeDonGiaHan{}
	svc := &giaHanService{
		don:     donRepo,
		thueBao: fakeThueBaoDat{},
		plans:   &fakeBangGiaDat{gia: gia, chuKy: chuKy},
		cauHinh: &fakeCauHinhNenTang{},
		hop:     bimat.New("khoa-thu-nghiem"),
		maApp:   domain.AppOrder,
	}

	return svc, donRepo
}

// GÓI THÁNG: giá × số tháng.
func TestDat_GoiThangTinhTheoThang(t *testing.T) {
	svc, donRepo := dungDat(499_000, domain.CycleThang)

	_, _ = svc.Dat(context.Background(), 23, 2, 3)

	if donRepo.don == nil {
		t.Fatal("phải chốt đơn trước khi gọi cổng")
	}
	if donRepo.don.SoTien != 1_497_000 {
		t.Errorf("3 tháng × 499.000 phải ra 1.497.000, nhận %.0f", donRepo.don.SoTien)
	}
	if donRepo.don.SoThang != 3 {
		t.Errorf("hợp đồng phải cộng 3 tháng, nhận %d", donRepo.don.SoThang)
	}
}

// GÓI NĂM: giá là giá MỘT NĂM, không phải một tháng.
//
// Đây là lỗi thật đã có trong bản đầu: nhân giá × số tháng thì khách mua gói năm
// bị thu gấp 12 lần, và không có gì báo — đơn vẫn tạo được, link vẫn mở được.
func TestDat_GoiNamTinhTheoNam(t *testing.T) {
	svc, donRepo := dungDat(4_990_000, domain.CycleNam)

	_, _ = svc.Dat(context.Background(), 23, 2, 12)

	if donRepo.don == nil {
		t.Fatal("phải chốt đơn trước khi gọi cổng")
	}
	if donRepo.don.SoTien != 4_990_000 {
		t.Errorf("1 năm của gói năm phải thu đúng 4.990.000, nhận %.0f", donRepo.don.SoTien)
	}
	// Hợp đồng vẫn cộng theo THÁNG — đó là đơn vị của mọi lượt gia hạn.
	if donRepo.don.SoThang != 12 {
		t.Errorf("hợp đồng phải cộng 12 tháng, nhận %d", donRepo.don.SoThang)
	}
}

func TestDat_GoiNamHaiNam(t *testing.T) {
	svc, donRepo := dungDat(4_990_000, domain.CycleNam)

	_, _ = svc.Dat(context.Background(), 23, 2, 24)

	if donRepo.don == nil || donRepo.don.SoTien != 9_980_000 {
		t.Errorf("2 năm phải thu 9.980.000, nhận %v", donRepo.don)
	}
}

// Gói năm KHÔNG bán lẻ vài tháng: số tiền lẻ đó sẽ nằm mãi trong sổ thu chẳng
// khớp với kỳ nào.
func TestDat_GoiNamTuChoiThangLe(t *testing.T) {
	svc, donRepo := dungDat(4_990_000, domain.CycleNam)

	_, err := svc.Dat(context.Background(), 23, 2, 3)

	if err == nil {
		t.Fatal("gói năm mà gia hạn 3 tháng phải bị từ chối")
	}
	if donRepo.don != nil {
		t.Error("bị từ chối thì không được ghi đơn nào xuống sổ")
	}
}

// ---------- Hạn 5 phút của link ----------

// Quá hạn thì TrangThai đóng đơn ngay, và KHÔNG hỏi cổng nữa.
//
// Không có bước này thì đơn nằm mãi ở `cho_thanh_toan` và màn hình quay vòng chờ
// một khoản tiền không bao giờ tới được nữa.
func TestTrangThai_QuaHanThiDongDonNgay(t *testing.T) {
	svc, donRepo, _, _ := dungGiaHan()
	cu := time.Now().Add(-time.Minute)
	donRepo.don.HetHanLuc = &cu
	// cauHinh nil: hỏi cổng sẽ hỏng. Bài này chứng minh nhánh quá hạn KHÔNG hỏi
	// cổng — nó trả lời bằng chính cái đồng hồ.
	svc.cauHinh = nil
	svc.thueBao = fakeThueBaoDat{}

	res, err := svc.TrangThai(context.Background(), 23, 7)
	if err != nil {
		t.Fatalf("không mong lỗi: %v", err)
	}

	if res.TrangThai != domain.DonHetHan {
		t.Errorf("đơn quá hạn phải thành %q, nhận %q", domain.DonHetHan, res.TrangThai)
	}
	if donRepo.trangCu != domain.DonHetHan {
		t.Errorf("phải ghi trạng thái xuống sổ, nhận %q", donRepo.trangCu)
	}
	if res.DaTra {
		t.Error("đơn hết hạn không được coi là đã trả")
	}
}

// Hạn link là NĂM PHÚT — con số nghiệp vụ, và nó phải giống nhau ở hai nơi: mốc
// ghi vào đơn và mốc gửi sang cổng. Lệch nhau thì có khoảng thời gian cổng vẫn
// nhận tiền còn mình đã coi đơn là chết.
func TestHanLinkThanhToan_LaNamPhut(t *testing.T) {
	if HanLinkThanhToan != 5*time.Minute {
		t.Errorf("mong 5 phút, nhận %v", HanLinkThanhToan)
	}
}
