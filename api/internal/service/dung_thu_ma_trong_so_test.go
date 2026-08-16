package service

import (
	"context"
	"errors"
	"testing"

	"sass-api/internal/domain"
	"sass-api/internal/dto"
)

// Cảnh có thật trên prod ngày 15/08/2026: khách cũ mang mã "test1" bị xoá ở khu
// order nhưng dòng trong SỔ NỀN TẢNG còn nguyên (id=3). Người bán tạo lại khách
// cùng mã, data plane cấp id mới (1), rồi lượt chép khách đụng uq_tenants_code
// bên sổ — MySQL đi cập nhật dòng id=3 và BÁO THÀNH CÔNG, dòng id=1 không bao
// giờ ra đời. Hợp đồng ghi ngay sau đó trỏ vào id=1 và vỡ khoá ngoại, để lại
// một cửa hàng dựng xong mà không có hợp đồng.
//
// Hai bài dưới đây giữ hai vế của cách chữa: phát hiện được, và phát hiện TRƯỚC
// KHI dựng cửa hàng.

// fakeHopDongSo ghi nhớ vừa đủ cho hai bài này: một dòng "mã đang thuộc về ai"
// và dấu vết đã chép khách hay chưa.
type fakeHopDongSo struct {
	fakeHopDongQuet // 15 hàm còn lại của cổng, không bài nào ở đây đụng tới

	maCuaAi  map[string]uint // code -> id đang giữ nó trong sổ
	daChep   []domain.PlatformTenant
	daKyBaoN int
}

func (f *fakeHopDongSo) AiDangMangMa(_ context.Context, ma string, ngoaiTru uint) (uint, error) {
	if id, co := f.maCuaAi[ma]; co && id != ngoaiTru {
		return id, nil
	}

	return 0, nil
}

func (f *fakeHopDongSo) UpsertKhachHang(_ context.Context, kh domain.PlatformTenant) error {
	f.daChep = append(f.daChep, kh)

	return nil
}

func (f *fakeHopDongSo) Tao(_ context.Context, _ *domain.Subscription) error {
	f.daKyBaoN++

	return nil
}

// fakeBangGiaKy trả về đúng một gói bán được, đủ điều khoản để lượt ký đi tiếp.
type fakeBangGiaKy struct{}

func (fakeBangGiaKy) List(context.Context, string) ([]domain.PlanWithApp, error) { return nil, nil }

func (fakeBangGiaKy) Find(_ context.Context, id uint) (*domain.PlanWithApp, error) {
	gia := 19000.0

	return &domain.PlanWithApp{
		Plan: domain.Plan{
			ID: id, AppID: 1, Code: "khoi_dau", Name: "Khởi đầu",
			BillingCycle: domain.CycleThang, Price: &gia, TrialDays: 14,
			Status: domain.PlanStatusActive,
		},
		AppCode: "order", AppName: "Sellio Order", AppStatus: domain.AppActive,
	}, nil
}

func (fakeBangGiaKy) Features(context.Context, uint) (map[string]string, error) {
	return map[string]string{
		domain.FeatureMaxShops:    "1",
		domain.FeatureMaxUsers:    "2",
		domain.FeatureMaxProducts: "500",
	}, nil
}

func (fakeBangGiaKy) FeaturesOf(context.Context, []uint) (map[uint]map[string]string, error) {
	return nil, nil
}
func (fakeBangGiaKy) SaveFeatures(context.Context, uint, map[string]string, []string) error {
	return nil
}
func (fakeBangGiaKy) Sua(context.Context, uint, domain.SuaPlan) error { return nil }

// fakeCuaHangMoi đếm số lần DỰNG cửa hàng — con số quan trọng nhất của bài thứ
// nhất: lượt tạo bị từ chối mà vẫn dựng cửa hàng là để lại cửa hàng mồ côi và
// chiếm mất mã.
type fakeCuaHangMoi struct {
	soLanTao int
	capID    uint
}

func (f *fakeCuaHangMoi) CoMa(context.Context, string) (bool, error) { return false, nil }

func (f *fakeCuaHangMoi) Tao(context.Context, domain.CuaHangMoi) (uint, error) {
	f.soLanTao++

	return f.capID, nil
}

func (f *fakeCuaHangMoi) DanhSach(context.Context) ([]domain.CuaHangCoSan, error) { return nil, nil }

func (f *fakeCuaHangMoi) TimTheoMa(context.Context, string) (*domain.CuaHangCoSan, error) {
	return nil, domain.ErrNotFound
}
func (f *fakeCuaHangMoi) DoiTrangThai(context.Context, []uint, string) (int64, error) { return 0, nil }

type fakeTaiKhoanCuaHang struct{}

func (fakeTaiKhoanCuaHang) QuanTri(context.Context, uint) (*domain.QuanTriCuaHang, error) {
	return nil, domain.ErrNotFound
}
func (fakeTaiKhoanCuaHang) DoiMatKhau(context.Context, uint, uint, string) error { return nil }

func donTaoKhach(ma string) dto.TaoDungThuRequest {
	return dto.TaoDungThuRequest{KhachHangMoiChung: dto.KhachHangMoiChung{
		PlanID:      1,
		MaCuaHang:   ma,
		TenCuaHang:  "Cửa hàng thử",
		TenDangNhap: "admin",
		MatKhau:     "MatKhau@123",
	}}
}

func TestTaoKhachTuChoiKhiMaConTrongSoNenTang(t *testing.T) {
	hopDong := &fakeHopDongSo{maCuaAi: map[string]uint{"test1": 3}}
	cuaHang := &fakeCuaHangMoi{capID: 1}

	svc := NewDungThuService(fakeBangGiaKy{}, hopDong, cuaHang, fakeTaiKhoanCuaHang{}, domain.AppOrder, "http://localhost:8001")

	_, err := svc.Tao(context.Background(), domain.QuyenApp{ToanQuyen: true}, donTaoKhach("test1"))
	if !errors.Is(err, domain.ErrMaConTrongSoNenTang) {
		t.Fatalf("mã còn trong sổ nền tảng phải bị từ chối rõ ràng, nhận: %v", err)
	}

	// Vế quan trọng hơn cả câu lỗi: KHÔNG được dựng gì cả. Dựng rồi mới phát hiện
	// nghĩa là mã bị chiếm ở data plane, và lần thử thứ hai sẽ đổi sang báo "mã đã
	// có người dùng" — một câu chẳng dính gì tới lỗi thật.
	if cuaHang.soLanTao != 0 {
		t.Errorf("đã dựng cửa hàng %d lần dù lượt tạo bị từ chối", cuaHang.soLanTao)
	}
	if len(hopDong.daChep) != 0 || hopDong.daKyBaoN != 0 {
		t.Errorf("đã đụng vào sổ nền tảng dù lượt tạo bị từ chối")
	}
}

func TestTaoKhachChayBinhThuongKhiSoNenTangSach(t *testing.T) {
	// Cùng mã, nhưng sổ không còn dòng cũ nào giữ nó.
	hopDong := &fakeHopDongSo{maCuaAi: map[string]uint{}}
	cuaHang := &fakeCuaHangMoi{capID: 1}

	svc := NewDungThuService(fakeBangGiaKy{}, hopDong, cuaHang, fakeTaiKhoanCuaHang{}, domain.AppOrder, "http://localhost:8001")

	res, err := svc.Tao(context.Background(), domain.QuyenApp{ToanQuyen: true}, donTaoKhach("test1"))
	if err != nil {
		t.Fatalf("sổ sạch thì lượt tạo phải chạy được, nhận lỗi: %v", err)
	}
	if res.TenantID != 1 {
		t.Errorf("tenant_id = %d, muốn 1 (id do data plane cấp)", res.TenantID)
	}
	if len(hopDong.daChep) != 1 || hopDong.daChep[0].ID != 1 {
		t.Fatalf("phải chép đúng một khách với id của data plane, nhận: %+v", hopDong.daChep)
	}
	if hopDong.daKyBaoN != 1 {
		t.Errorf("phải ghi đúng một hợp đồng, nhận %d", hopDong.daKyBaoN)
	}
}
