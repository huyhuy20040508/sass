package service

import (
	"context"
	"errors"
	"testing"
	"time"

	"sass-api/internal/domain"
)

// ---------- sổ giả ----------

// fakeThueBaoCuaKhach ghi nhớ THAM SỐ nó được gọi cùng — đó mới là thứ đáng
// kiểm ở đây: quên `tenant_id` là chủ tiệm này đọc được hợp đồng của tiệm kia,
// và cái sai đó không hiện ra ở bất cứ đâu trên màn hình.
type fakeThueBaoCuaKhach struct {
	hopDong  *domain.HopDongDayDu
	loi      error
	goiVoiID uint
	goiVoiMa string
}

func (f *fakeThueBaoCuaKhach) HienTai(
	_ context.Context, tenantID uint, maApp string,
) (*domain.HopDongDayDu, error) {
	f.goiVoiID, f.goiVoiMa = tenantID, maApp
	if f.loi != nil {
		return nil, f.loi
	}

	return f.hopDong, nil
}

// fakeBangGia là bản tối thiểu của PlanRepository: hai hàm đọc nằm trên đường đi
// của GoiDichVuService, ba hàm còn lại (ghi) thì không.
type fakeBangGia struct {
	rows     []domain.PlanWithApp
	features map[uint]map[string]string
	goiVoiMa string
	hoiIDs   []uint
}

func (f *fakeBangGia) List(_ context.Context, appCode string) ([]domain.PlanWithApp, error) {
	f.goiVoiMa = appCode
	return f.rows, nil
}

func (f *fakeBangGia) FeaturesOf(_ context.Context, ids []uint) (map[uint]map[string]string, error) {
	f.hoiIDs = ids
	return f.features, nil
}

func (f *fakeBangGia) Find(context.Context, uint) (*domain.PlanWithApp, error) { return nil, nil }
func (f *fakeBangGia) Features(context.Context, uint) (map[string]string, error) {
	return nil, nil
}
func (f *fakeBangGia) SaveFeatures(context.Context, uint, map[string]string, []string) error {
	return nil
}
func (f *fakeBangGia) Sua(context.Context, uint, domain.SuaPlan) error { return nil }

func goi(id uint, code, status string) domain.PlanWithApp {
	return domain.PlanWithApp{
		Plan:    domain.Plan{ID: id, Code: code, Name: code, BillingCycle: domain.CycleThang, Status: status},
		AppCode: domain.AppOrder,
		AppName: "Sellio Order",
	}
}

// ---------- bài kiểm ----------

func TestGoiDichVuCuaToi_LocTheoDungCuaHangVaApp(t *testing.T) {
	thueBao := &fakeThueBaoCuaKhach{loi: domain.ErrNotFound}
	bangGia := &fakeBangGia{}
	svc := NewGoiDichVuService(thueBao, bangGia, domain.AppOrder)

	if _, err := svc.CuaToi(context.Background(), 7); err != nil {
		t.Fatalf("không mong có lỗi: %v", err)
	}
	if thueBao.goiVoiID != 7 {
		t.Errorf("hợp đồng phải đọc theo đúng cửa hàng 7, nhận %d", thueBao.goiVoiID)
	}
	if thueBao.goiVoiMa != domain.AppOrder || bangGia.goiVoiMa != domain.AppOrder {
		t.Errorf("phải khoá theo app của tiến trình, nhận (%q, %q)", thueBao.goiVoiMa, bangGia.goiVoiMa)
	}
}

// Cửa hàng chưa xác định thì KHÔNG đọc gì cả — trả về một bảng giá kèm "chưa có
// hợp đồng" cho một request lẽ ra phải bị từ chối là câu trả lời sai mà trông
// hợp lệ.
func TestGoiDichVuCuaToi_KhongCoCuaHangThiTuChoi(t *testing.T) {
	thueBao := &fakeThueBaoCuaKhach{}
	svc := NewGoiDichVuService(thueBao, &fakeBangGia{}, domain.AppOrder)

	_, err := svc.CuaToi(context.Background(), 0)
	if !errors.Is(err, domain.ErrForbidden) {
		t.Fatalf("mong ErrForbidden, nhận %v", err)
	}
	if thueBao.goiVoiID != 0 || thueBao.goiVoiMa != "" {
		t.Error("không được chạm vào sổ nền tảng khi chưa xác định cửa hàng")
	}
}

// Khách chưa có hợp đồng vẫn phải thấy bảng giá: đó chính là thứ họ cần nhìn.
func TestGoiDichVuCuaToi_ChuaCoHopDongVanTraBangGia(t *testing.T) {
	bangGia := &fakeBangGia{rows: []domain.PlanWithApp{
		goi(1, "khoi_dau", domain.PlanStatusActive),
		goi(2, "cua_hang", domain.PlanStatusActive),
	}}
	svc := NewGoiDichVuService(&fakeThueBaoCuaKhach{loi: domain.ErrNotFound}, bangGia, domain.AppOrder)

	res, err := svc.CuaToi(context.Background(), 7)
	if err != nil {
		t.Fatalf("không mong có lỗi: %v", err)
	}
	if res.HopDong != nil {
		t.Error("chưa có hợp đồng thì hop_dong phải là null")
	}
	if len(res.BangGia) != 2 {
		t.Errorf("mong 2 gói đang bán, nhận %d", len(res.BangGia))
	}
	if len(res.Fields) == 0 {
		t.Error("phải trả kèm siêu dữ liệu hạn mức để màn hình khỏi chép lại bảng khoá")
	}
}

// Gói đã NGỪNG BÁN mà khách đang dùng thì vẫn phải có mặt — thiếu nó thì người
// đang dùng gói cũ mở trang ra và không thấy gói của mình đâu cả.
func TestGoiDichVuCuaToi_GiuLaiGoiDangDungDuDaNgungBan(t *testing.T) {
	dangDungID := uint(9)
	bangGia := &fakeBangGia{rows: []domain.PlanWithApp{
		goi(1, "khoi_dau", domain.PlanStatusActive),
		goi(dangDungID, "cua_hang_cu", domain.PlanStatusRetired),
		goi(3, "goi_cu_khac", domain.PlanStatusRetired),
	}}
	thueBao := &fakeThueBaoCuaKhach{hopDong: &domain.HopDongDayDu{
		Subscription: domain.Subscription{PlanID: &dangDungID, Plan: "cua_hang_cu"},
	}}
	svc := NewGoiDichVuService(thueBao, bangGia, domain.AppOrder)

	res, err := svc.CuaToi(context.Background(), 7)
	if err != nil {
		t.Fatalf("không mong có lỗi: %v", err)
	}
	if len(res.BangGia) != 2 {
		t.Fatalf("mong 2 dòng (1 đang bán + 1 đang dùng), nhận %d", len(res.BangGia))
	}
	if res.BangGia[1].ID != dangDungID {
		t.Errorf("dòng đang dùng phải được giữ lại, nhận id %d", res.BangGia[1].ID)
	}
	// Chỉ hỏi tính năng của những dòng thật sự trả ra — hỏi cả gói đã lọc bỏ là
	// một lượt đọc thừa mà không ai thấy.
	if len(bangGia.hoiIDs) != 2 {
		t.Errorf("mong hỏi tính năng của đúng 2 gói, nhận %v", bangGia.hoiIDs)
	}
}

func TestHopDongCuaToi_QuaHanRaSoAmVaGiuDauDungThu(t *testing.T) {
	homNay := time.Date(2026, 8, 15, 10, 0, 0, 0, time.UTC)
	hetDungThu := homNay.AddDate(0, 0, -3)
	row := domain.HopDongDayDu{
		Subscription: domain.Subscription{
			Plan:        "khoi_dau",
			Status:      domain.SubscriptionPastDue,
			EndsAt:      hetDungThu,
			TrialEndsAt: &hetDungThu,
			MaxShops:    1,
		},
		TenApp: "Sellio Order",
	}

	hd := hopDongCuaToi(row, homNay)
	if !hd.DaHetHan {
		t.Error("hợp đồng hết hạn ba ngày trước phải mang da_het_han")
	}
	if hd.ConLaiNgay != -3 {
		t.Errorf("hợp đồng quá hạn 3 ngày phải ra -3, nhận %d", hd.ConLaiNgay)
	}
	if !hd.DungThu {
		t.Error("còn trial_ends_at nghĩa là khách chưa trả tiền lần nào")
	}
	// Bảng giá không tra ra tên thì rơi về MÃ gói: xấu nhưng vẫn nói được cái gì
	// đó, còn ô trống thì không.
	if hd.TenGoi != "khoi_dau" {
		t.Errorf("mong rơi về mã gói, nhận %q", hd.TenGoi)
	}
}

// KHOẢNG 24 GIỜ QUANH MỐC HẾT HẠN — chỗ phép chia cắt cụt trả lời sai, và là
// chỗ màn hình phải báo động đúng nhất.
//
// Hợp đồng chết lúc 10:45 mà 10:47 vẫn hiện "còn 0 ngày", không đỏ, không hộp
// thoại: đó là lỗi thật đã gặp trên máy chạy thật, không phải tình huống nghĩ ra.
func TestHopDongCuaToi_QuanhMocHetHan(t *testing.T) {
	moc := time.Date(2026, 8, 15, 10, 45, 0, 0, time.UTC)

	cas := []struct {
		ten      string
		bayGio   time.Time
		daHetHan bool
		conLai   int
	}{
		{"vừa hết hạn 2 phút", moc.Add(2 * time.Minute), true, 0},
		{"còn đúng 2 phút", moc.Add(-2 * time.Minute), false, 1},
		{"quá hạn 25 giờ", moc.Add(25 * time.Hour), true, -1},
		{"còn 30 ngày", moc.AddDate(0, 0, -30), false, 30},
	}

	for _, c := range cas {
		hd := hopDongCuaToi(domain.HopDongDayDu{
			Subscription: domain.Subscription{Plan: "khoi_dau", EndsAt: moc},
		}, c.bayGio)

		if hd.DaHetHan != c.daHetHan {
			t.Errorf("%s: da_het_han mong %v, nhận %v", c.ten, c.daHetHan, hd.DaHetHan)
		}
		if hd.ConLaiNgay != c.conLai {
			t.Errorf("%s: con_lai_ngay mong %d, nhận %d", c.ten, c.conLai, hd.ConLaiNgay)
		}
	}
}
