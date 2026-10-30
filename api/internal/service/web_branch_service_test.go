package service

import (
	"context"
	"errors"
	"testing"
	"time"

	"sass-api/internal/domain"
	"sass-api/internal/dto"
)

// fakeSoHopDong trả một hợp đồng cố định cho Tim và nhớ hợp đồng vừa ký.
type fakeSoHopDong struct {
	fakeHopDongQuet
	hd     *domain.HopDongDayDu
	daKy   *domain.Subscription
	daChep int
}

func (f *fakeSoHopDong) Tim(context.Context, uint) (*domain.HopDongDayDu, error) {
	if f.daKy != nil {
		return &domain.HopDongDayDu{Subscription: *f.daKy, MaApp: domain.AppWeb}, nil
	}
	if f.hd == nil {
		return nil, domain.ErrNotFound
	}

	return f.hd, nil
}

func (f *fakeSoHopDong) UpsertKhachHang(context.Context, domain.PlatformTenant) error {
	f.daChep++

	return nil
}

func (f *fakeSoHopDong) Tao(_ context.Context, s *domain.Subscription) error {
	s.ID = 99
	f.daKy = s

	return nil
}

// fakeWebRepo: chi nhánh của MỘT cửa hàng + sổ tên miền trong bộ nhớ.
type fakeWebRepo struct {
	chiNhanh []domain.ChiNhanhCuaKhach
	so       []domain.TenMienWeb
}

func (f *fakeWebRepo) ChiNhanh(context.Context, uint) ([]domain.ChiNhanhCuaKhach, error) {
	return f.chiNhanh, nil
}

func (f *fakeWebRepo) TenMien(_ context.Context, tenantID, appID uint) ([]domain.TenMienWeb, error) {
	var kq []domain.TenMienWeb
	for _, t := range f.so {
		if t.TenantID == tenantID && t.AppID == appID {
			kq = append(kq, t)
		}
	}

	return kq, nil
}

func (f *fakeWebRepo) ChuCuaHost(_ context.Context, host string) (uint, error) {
	for _, t := range f.so {
		if t.Host == host {
			return t.TenantID, nil
		}
	}

	return 0, nil
}

func (f *fakeWebRepo) Them(_ context.Context, tm domain.TenMienWeb) error {
	f.so = append(f.so, tm)

	return nil
}

func (f *fakeWebRepo) Xoa(_ context.Context, tenantID, appID, shopID uint) (int64, error) {
	var n int64
	giu := f.so[:0]
	for _, t := range f.so {
		if t.TenantID == tenantID && t.AppID == appID && t.ShopID == shopID {
			n++
			continue
		}
		giu = append(giu, t)
	}
	f.so = giu

	return n, nil
}

func hopDongWebMau(maxShops uint) *domain.HopDongDayDu {
	return &domain.HopDongDayDu{
		Subscription: domain.Subscription{ID: 9, TenantID: 2, AppID: 2, Status: domain.SubscriptionActive, MaxShops: maxShops},
		MaApp:        domain.AppWeb,
	}
}

func chiNhanhMau() []domain.ChiNhanhCuaKhach {
	return []domain.ChiNhanhCuaKhach{
		{ID: 2, Ma: "mac-dinh", Ten: "Quốc Huy", DangMo: true},
		{ID: 5, Ma: "chi-nhanh-2", Ten: "Chi nhánh 2", DangMo: true},
		{ID: 7, Ma: "kho-cu", Ten: "Kho cũ", DangMo: false},
	}
}

var toanQuyen = domain.QuyenApp{ToanQuyen: true}

func canLoiO(t *testing.T, err error, o string) {
	t.Helper()
	var ve *LoiTheoO
	if !errors.As(err, &ve) || ve.Fields[o] == "" {
		t.Fatalf("muốn lỗi ở ô %q, nhận: %v", o, err)
	}
}

func TestWebChiNhanh_MoDatTenTheoChiNhanh(t *testing.T) {
	repo := &fakeWebRepo{chiNhanh: chiNhanhMau()}
	svc := NewWebChiNhanhService(&fakeSoHopDong{hd: hopDongWebMau(0)}, repo, "web.selliotech.store.")

	kq, err := svc.Mo(context.Background(), toanQuyen, 9, 5)
	if err != nil {
		t.Fatal(err)
	}
	if kq.DangMo != 1 || kq.ChiNhanh[1].TenMien == nil || *kq.ChiNhanh[1].TenMien != "chinhanh2.web.selliotech.store" {
		t.Fatalf("tên miền phải là tên chi nhánh không dấu, nhận: %+v", kq)
	}
	if repo.so[0].ShopID != 5 || repo.so[0].TenantID != 2 || repo.so[0].AppID != 2 {
		t.Errorf("dòng sổ sai: %+v", repo.so[0])
	}

	// Bấm lại: không cấp thêm địa chỉ thứ hai.
	if _, err := svc.Mo(context.Background(), toanQuyen, 9, 5); err != nil || len(repo.so) != 1 {
		t.Errorf("mở lại phải giữ nguyên một tên miền, lỗi %v, sổ %d dòng", err, len(repo.so))
	}
}

func TestWebChiNhanh_TrungTenThiThemHauTo(t *testing.T) {
	repo := &fakeWebRepo{chiNhanh: chiNhanhMau(), so: []domain.TenMienWeb{
		{TenantID: 8, AppID: 2, ShopID: 30, Host: "chinhanh2.web.selliotech.store"},
		{TenantID: 8, AppID: 2, ShopID: 31, Host: "chinhanh2-2.web.selliotech.store"},
	}}
	svc := NewWebChiNhanhService(&fakeSoHopDong{hd: hopDongWebMau(0)}, repo, "web.selliotech.store")

	kq, err := svc.Mo(context.Background(), toanQuyen, 9, 5)
	if err != nil {
		t.Fatal(err)
	}
	if got := *kq.ChiNhanh[1].TenMien; got != "chinhanh2-3.web.selliotech.store" {
		t.Errorf("trùng tên với cửa hàng khác phải thêm hậu tố, nhận %s", got)
	}
}

func TestWebChiNhanh_KhongVuotSoChiNhanhHopDong(t *testing.T) {
	repo := &fakeWebRepo{chiNhanh: chiNhanhMau()}
	svc := NewWebChiNhanhService(&fakeSoHopDong{hd: hopDongWebMau(1)}, repo, "web.selliotech.store")

	if _, err := svc.Mo(context.Background(), toanQuyen, 9, 2); err != nil {
		t.Fatal(err)
	}
	_, err := svc.Mo(context.Background(), toanQuyen, 9, 5)
	canLoiO(t, err, "shop_id")
	if len(repo.so) != 1 {
		t.Errorf("vượt hạn mức mà vẫn ghi sổ: %d dòng", len(repo.so))
	}

	// Gỡ chi nhánh cũ thì mở được chi nhánh khác.
	if _, err := svc.Go(context.Background(), toanQuyen, 9, 2); err != nil {
		t.Fatal(err)
	}
	if kq, err := svc.Mo(context.Background(), toanQuyen, 9, 5); err != nil || kq.DangMo != 1 {
		t.Errorf("gỡ xong phải mở được chi nhánh khác: %v %+v", err, kq)
	}
}

func TestWebChiNhanh_TuChoi(t *testing.T) {
	ctx := context.Background()
	repo := &fakeWebRepo{chiNhanh: chiNhanhMau()}

	svc := NewWebChiNhanhService(&fakeSoHopDong{hd: hopDongWebMau(0)}, repo, "web.selliotech.store")
	_, err := svc.Mo(ctx, toanQuyen, 9, 7)
	canLoiO(t, err, "shop_id") // chi nhánh đã tắt bên POS
	_, err = svc.Mo(ctx, toanQuyen, 9, 404)
	canLoiO(t, err, "shop_id") // chi nhánh không thuộc cửa hàng

	huy := hopDongWebMau(0)
	huy.Status = domain.SubscriptionCanceled
	_, err = NewWebChiNhanhService(&fakeSoHopDong{hd: huy}, repo, "web.selliotech.store").Mo(ctx, toanQuyen, 9, 5)
	canLoiO(t, err, "hop_dong")

	order := hopDongWebMau(0)
	order.MaApp = domain.AppOrder
	_, err = NewWebChiNhanhService(&fakeSoHopDong{hd: order}, repo, "web.selliotech.store").DanhSach(ctx, toanQuyen, 9)
	canLoiO(t, err, "hop_dong")

	_, err = svc.DanhSach(ctx, domain.QuyenApp{Ma: []string{domain.AppOrder}}, 9)
	if !errors.Is(err, domain.ErrKhongPhuTrachApp) {
		t.Errorf("người không phụ trách app web phải bị chặn, nhận %v", err)
	}
	if len(repo.so) != 0 {
		t.Errorf("bị từ chối mà vẫn ghi sổ: %+v", repo.so)
	}
}

// fakeCuaHangCo: cửa hàng đã có, để ký hợp đồng cho nó.
type fakeCuaHangCo struct{ fakeCuaHangMoi }

func (fakeCuaHangCo) TimTheoMa(context.Context, string) (*domain.CuaHangCoSan, error) {
	return &domain.CuaHangCoSan{ID: 2, Ma: "test", Ten: "Quốc Huy", TrangThai: "active"}, nil
}

func TestKyHopDong_DungThuTheoNgayCuaGoi(t *testing.T) {
	so := &fakeSoHopDong{}
	svc := NewDungThuService(fakeBangGiaKy{}, so, &fakeCuaHangCo{}, fakeTaiKhoanCuaHang{}, domain.AppOrder, "")

	truoc := time.Now()
	if _, err := svc.KyHopDong(context.Background(), toanQuyen, dto.KyHopDongRequest{PlanID: 1, MaCuaHang: "test", SoThang: 12, DungThu: true}); err != nil {
		t.Fatal(err)
	}
	hd := so.daKy
	if hd.Status != domain.SubscriptionTrial || hd.TrialEndsAt == nil {
		t.Fatalf("chọn dùng thử phải ra trial có ngày hết thử, nhận %+v", hd)
	}
	ngay := hd.EndsAt.Sub(truoc).Hours() / 24
	if ngay < 13.9 || ngay > 14.1 || !hd.TrialEndsAt.Equal(hd.EndsAt) {
		t.Errorf("hạn phải = 14 ngày thử của gói (bỏ qua 12 tháng), nhận %.1f ngày", ngay)
	}
}

func TestKyHopDong_KhongDungThuVanNhuCu(t *testing.T) {
	so := &fakeSoHopDong{}
	svc := NewDungThuService(fakeBangGiaKy{}, so, &fakeCuaHangCo{}, fakeTaiKhoanCuaHang{}, domain.AppOrder, "")

	if _, err := svc.KyHopDong(context.Background(), toanQuyen, dto.KyHopDongRequest{PlanID: 1, MaCuaHang: "test", SoThang: 2}); err != nil {
		t.Fatal(err)
	}
	hd := so.daKy
	if hd.Status != domain.SubscriptionActive || hd.TrialEndsAt != nil {
		t.Fatalf("không chọn dùng thử thì active, không có ngày thử: %+v", hd)
	}
	if want := hd.StartedAt.AddDate(0, 2, 0); !hd.EndsAt.Equal(want) {
		t.Errorf("hạn phải là 2 tháng, nhận %v", hd.EndsAt)
	}
}
