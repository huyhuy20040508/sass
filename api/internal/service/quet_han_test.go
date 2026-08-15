package service

import (
	"context"
	"errors"
	"strings"
	"testing"
	"time"

	"sass-api/internal/domain"
	"sass-api/internal/tenant"
)

// ---------- sổ giả ----------

// fakeHopDongQuet là bản ghi nhớ tối thiểu của HopDongRepository, đủ cho lượt
// quét hạn: mấy hàm còn lại của cổng không nằm trên đường đi này và trả zero.
type fakeHopDongQuet struct {
	quaHan    []domain.HopDongQuaHan
	conSong   []uint
	daDanh    []uint
	daDoiSo   []uint
	trangSo   string
	loiQuaHa  error
	loiKhoa   error
	sapHetHan []domain.HopDongQuaHan
	daNhac    map[uint]bool
}

func (f *fakeHopDongQuet) QuaHan(_ context.Context, _ time.Time) ([]domain.HopDongQuaHan, error) {
	return f.quaHan, f.loiQuaHa
}

func (f *fakeHopDongQuet) ConHopDongSong(_ context.Context, _ []uint, _ time.Time) ([]uint, error) {
	return f.conSong, nil
}

func (f *fakeHopDongQuet) DanhDauQuaHan(_ context.Context, ids []uint) (int64, error) {
	f.daDanh = append(f.daDanh, ids...)
	return int64(len(ids)), nil
}

func (f *fakeHopDongQuet) DoiTrangThaiKhach(_ context.Context, ids []uint, tt string) (int64, error) {
	if f.loiKhoa != nil {
		return 0, f.loiKhoa
	}
	f.daDoiSo = append(f.daDoiSo, ids...)
	f.trangSo = tt

	return int64(len(ids)), nil
}

func (f *fakeHopDongQuet) UpsertKhachHang(context.Context, domain.PlatformTenant) error { return nil }
func (f *fakeHopDongQuet) AiDangMangMa(context.Context, string, uint) (uint, error) {
	return 0, nil
}
func (f *fakeHopDongQuet) Tao(context.Context, *domain.Subscription) error              { return nil }
func (f *fakeHopDongQuet) Tim(context.Context, uint) (*domain.HopDongDayDu, error)      { return nil, nil }
func (f *fakeHopDongQuet) GiaHan(context.Context, uint, int) error                      { return nil }
func (f *fakeHopDongQuet) Huy(context.Context, uint, string) error                      { return nil }
func (f *fakeHopDongQuet) Sua(context.Context, uint, uint, domain.SuaHopDong) error     { return nil }
func (f *fakeHopDongQuet) KyCuoiDaThu(context.Context, uint) (*time.Time, error)        { return nil, nil }
func (f *fakeHopDongQuet) ThuTien(context.Context, *domain.Invoice) error               { return nil }
func (f *fakeHopDongQuet) TongDaThu(context.Context, uint) (float64, int, error)        { return 0, 0, nil }

func (f *fakeHopDongQuet) SapHetHan(context.Context, time.Time, time.Time) ([]domain.HopDongQuaHan, error) {
	return f.sapHetHan, nil
}
func (f *fakeHopDongQuet) DanhDauDaNhac(_ context.Context, id uint, _ time.Time, _ int) (bool, error) {
	if f.daNhac == nil {
		f.daNhac = map[uint]bool{}
	}
	if f.daNhac[id] {
		return false, nil
	}
	f.daNhac[id] = true

	return true, nil
}
func (f *fakeHopDongQuet) TenantDangCoHopDong(context.Context, string) ([]uint, error) {
	return nil, nil
}

// fakeCuaHangQuet ghi nhớ lượt khoá / mở khoá ở DATA PLANE — cột quyết định
// khách còn vào được phần mềm hay không.
type fakeCuaHangQuet struct {
	daDoi   []uint
	trang   string
	soLuot  int
	loiGhi  error
	daTao   bool
	daHoi   bool
	tonTaiM bool
}

func (f *fakeCuaHangQuet) DoiTrangThai(_ context.Context, ids []uint, tt string) (int64, error) {
	if f.loiGhi != nil {
		return 0, f.loiGhi
	}
	f.soLuot++
	f.daDoi = append(f.daDoi, ids...)
	f.trang = tt

	return int64(len(ids)), nil
}

func (f *fakeCuaHangQuet) CoMa(context.Context, string) (bool, error) {
	f.daHoi = true
	return f.tonTaiM, nil
}

func (f *fakeCuaHangQuet) Tao(context.Context, domain.CuaHangMoi) (uint, error) {
	f.daTao = true
	return 1, nil
}

func (f *fakeCuaHangQuet) DanhSach(context.Context) ([]domain.CuaHangCoSan, error) {
	return nil, nil
}

func (f *fakeCuaHangQuet) TimTheoMa(context.Context, string) (*domain.CuaHangCoSan, error) {
	return nil, domain.ErrNotFound
}

func hopDongChet(id, tenantID uint, ma string, dungThu bool) domain.HopDongQuaHan {
	return domain.HopDongQuaHan{
		ID: id, TenantID: tenantID, MaCuaHang: ma, MaApp: "order",
		HetHan: time.Now().Add(-24 * time.Hour), DungThu: dungThu,
	}
}

// ---------- test ----------

// Đây là chính cái lỗi phải sửa: tài khoản dùng thử hết hạn mà vẫn ở trong phần
// mềm. Khoá `tenants.status` ở DATA PLANE là thứ DUY NHẤT đá được họ ra — cả
// đường đăng nhập lẫn middleware đều đọc đúng cột đó.
func TestQuetHan_HetHanThiKhoaCuaHang(t *testing.T) {
	hd := &fakeHopDongQuet{quaHan: []domain.HopDongQuaHan{hopDongChet(7, 42, "order1", true)}}
	ch := &fakeCuaHangQuet{}

	kq, err := NewQuetHanService(hd, ch, nil).QuetMotLuot(context.Background())
	if err != nil {
		t.Fatalf("lượt quét hỏng: %v", err)
	}

	if kq.QuaHan != 1 || kq.DaKhoa != 1 {
		t.Fatalf("phải khoá đúng một cửa hàng, nhận: %+v", kq)
	}
	if len(ch.daDoi) != 1 || ch.daDoi[0] != 42 {
		t.Fatalf("phải khoá cửa hàng 42 ở data plane, nhận: %v", ch.daDoi)
	}
	if ch.trang != domain.TenantSuspended {
		t.Fatalf("trạng thái ghi xuống phải là %q, nhận %q", domain.TenantSuspended, ch.trang)
	}
	if len(hd.daDanh) != 1 || hd.daDanh[0] != 7 {
		t.Fatalf("hợp đồng phải được đánh dấu quá hạn, nhận: %v", hd.daDanh)
	}
	if len(hd.daDoiSo) != 1 || hd.trangSo != domain.TenantSuspended {
		t.Fatalf("sổ nền tảng cũng phải ghi khoá, nhận: %v / %q", hd.daDoiSo, hd.trangSo)
	}
}

// Khách mua hai phần mềm, hết hạn một cái: `tenants.status` là của CẢ khách
// hàng, nên khoá luôn là cắt nốt phần mềm họ đang trả tiền.
func TestQuetHan_ConHopDongSongThiKhongKhoa(t *testing.T) {
	hd := &fakeHopDongQuet{
		quaHan:  []domain.HopDongQuaHan{hopDongChet(7, 42, "order1", false)},
		conSong: []uint{42},
	}
	ch := &fakeCuaHangQuet{}

	kq, err := NewQuetHanService(hd, ch, nil).QuetMotLuot(context.Background())
	if err != nil {
		t.Fatalf("lượt quét hỏng: %v", err)
	}

	if kq.GiuLai != 1 || kq.DaKhoa != 0 {
		t.Fatalf("khách còn hợp đồng sống thì không được khoá, nhận: %+v", kq)
	}
	if len(ch.daDoi) != 0 {
		t.Fatalf("không được đụng tới data plane, nhận: %v", ch.daDoi)
	}
	// Hợp đồng thì VẪN phải đánh dấu quá hạn: nó quá hạn thật, chỉ là chưa tới
	// mức cắt quyền vào phần mềm.
	if len(hd.daDanh) != 1 {
		t.Fatalf("hợp đồng quá hạn vẫn phải được đánh dấu, nhận: %v", hd.daDanh)
	}
}

// Lượt quét sau không được nhặt lại hợp đồng đã xử lý — nếu không, mỗi nhịp
// quét lại ghi một dòng nhật ký "vừa khoá cửa hàng X" tới ngày khách quay lại.
func TestQuetHan_KhongConGiThiKhongGhiGi(t *testing.T) {
	hd := &fakeHopDongQuet{}
	ch := &fakeCuaHangQuet{}

	kq, err := NewQuetHanService(hd, ch, nil).QuetMotLuot(context.Background())
	if err != nil {
		t.Fatalf("lượt quét hỏng: %v", err)
	}
	if kq.QuaHan != 0 || ch.soLuot != 0 || len(hd.daDanh) != 0 {
		t.Fatalf("không có hợp đồng quá hạn thì không được ghi gì, nhận: %+v", kq)
	}
}

// Khoá hụt ở data plane thì TUYỆT ĐỐI không được đánh dấu hợp đồng: `past_due`
// chính là thứ loại nó khỏi lượt quét sau, nên ghi trước là để khách hết hạn
// dùng tiếp vĩnh viễn mà trong sổ vẫn hiện quá hạn đàng hoàng.
func TestQuetHan_KhoaHongThiKhongDanhDauHopDong(t *testing.T) {
	hd := &fakeHopDongQuet{quaHan: []domain.HopDongQuaHan{hopDongChet(7, 42, "order1", true)}}
	ch := &fakeCuaHangQuet{loiGhi: errors.New("mất kết nối data plane")}

	if _, err := NewQuetHanService(hd, ch, nil).QuetMotLuot(context.Background()); err == nil {
		t.Fatal("khoá hụt mà lượt quét báo thành công")
	}
	if len(hd.daDanh) != 0 {
		t.Fatalf("chưa khoá được thì không được đánh dấu hợp đồng, nhận: %v", hd.daDanh)
	}
}

// Cờ gửi ra màn hình: hợp đồng thử VỪA HẾT HẠN mang trạng thái `past_due`, và
// "cho khách thêm ba ngày" phải còn làm được — đó là việc duy nhất người bán
// muốn làm với một hợp đồng thử vừa chết.
func TestSuaDuocHan_ThuQuaHanVanDoiDuocNgay(t *testing.T) {
	moc := time.Now()

	cases := []struct {
		ten    string
		row    domain.HopDongDayDu
		muonCo bool
	}{
		{"đang dùng thử", domain.HopDongDayDu{Subscription: domain.Subscription{
			Status: domain.SubscriptionTrial, TrialEndsAt: &moc}}, true},
		{"thử vừa quá hạn", domain.HopDongDayDu{Subscription: domain.Subscription{
			Status: domain.SubscriptionPastDue, TrialEndsAt: &moc}}, true},
		{"đã trả tiền, quá hạn", domain.HopDongDayDu{Subscription: domain.Subscription{
			Status: domain.SubscriptionPastDue}}, false},
		{"đang chạy chính thức", domain.HopDongDayDu{Subscription: domain.Subscription{
			Status: domain.SubscriptionActive}}, false},
		{"đã huỷ", domain.HopDongDayDu{Subscription: domain.Subscription{
			Status: domain.SubscriptionCanceled}}, false},
	}

	for _, c := range cases {
		if got := suaDuocHan(c.row); got != c.muonCo {
			t.Errorf("%s: suaDuocHan = %v, muốn %v", c.ten, got, c.muonCo)
		}
	}
}

// ---------- NHẮC HẠN: chuông + realtime, 5 ngày trước ----------

// fakeThongBao ghi lại từng lời nhắc kèm CỬA HÀNG trong ctx — vế thứ hai mới là
// vế đáng kiểm: thông báo nằm ở data plane và bị lọc theo tenant, nên rót nhầm
// cửa hàng nghĩa là lời nhắc của tiệm này rơi vào chuông tiệm khác.
type fakeThongBao struct {
	daDay []loiNhac
}

type loiNhac struct {
	tenantID uint
	loai     string
	tieuDe   string
	noiDung  string
}

func (f *fakeThongBao) Push(
	ctx context.Context, userID *uint, nType, title, content string, _ map[string]any,
) *domain.Notification {
	id, _ := tenant.ID(ctx)
	f.daDay = append(f.daDay, loiNhac{tenantID: id, loai: nType, tieuDe: title, noiDung: content})

	return &domain.Notification{ID: uint(len(f.daDay)), Type: nType, Title: title}
}

func (f *fakeThongBao) List(context.Context, domain.NotificationFilter) (*NotificationList, error) {
	return nil, nil
}
func (f *fakeThongBao) UnreadCount(context.Context, *uint) (int64, error) { return 0, nil }
func (f *fakeThongBao) MarkRead(context.Context, uint, *uint) error       { return nil }
func (f *fakeThongBao) MarkAllRead(context.Context, *uint) (int64, error) { return 0, nil }
func (f *fakeThongBao) Signal(context.Context, string, string, any)       {}
func (f *fakeThongBao) SignalAdmin(context.Context, string, any)          {}

// hopDongSapChet dựng một hợp đồng còn sống, hết hạn sau `ngay` ngày nữa.
func hopDongSapChet(id, tenantID uint, ma string, ngay int, dungThu bool) domain.HopDongQuaHan {
	return domain.HopDongQuaHan{
		ID: id, TenantID: tenantID, MaCuaHang: ma, MaApp: domain.AppOrder,
		HetHan: time.Now().Add(time.Duration(ngay)*24*time.Hour + time.Hour), DungThu: dungThu,
	}
}

// Khách sắp hết hạn nhận thông báo vào chuông CỦA CHÍNH HỌ.
func TestNhacHan_DayThongBaoVaoDungCuaHang(t *testing.T) {
	hd := &fakeHopDongQuet{sapHetHan: []domain.HopDongQuaHan{
		hopDongSapChet(7, 42, "order1", 3, false),
	}}
	tb := &fakeThongBao{}

	kq, err := NewQuetHanService(hd, &fakeCuaHangQuet{}, tb).QuetMotLuot(context.Background())
	if err != nil {
		t.Fatalf("lượt quét hỏng: %v", err)
	}

	if kq.DaNhac != 1 {
		t.Fatalf("mong nhắc 1 khách, nhận %d", kq.DaNhac)
	}
	if len(tb.daDay) != 1 {
		t.Fatalf("mong 1 thông báo, nhận %d", len(tb.daDay))
	}
	n := tb.daDay[0]
	if n.tenantID != 42 {
		t.Errorf("thông báo phải rơi vào cửa hàng 42, nhận %d", n.tenantID)
	}
	if n.loai != LoaiThongBaoNhacHan {
		t.Errorf("loại thông báo phải là %q, nhận %q", LoaiThongBaoNhacHan, n.loai)
	}
	if !strings.Contains(n.noiDung, "4 ngày") {
		// Còn 3 ngày + 1 giờ → làm tròn LÊN thành 4. Nói ít hơn thực tế là sai theo
		// chiều làm khách hoảng, và lần sau họ không tin con số nữa.
		t.Errorf("nội dung phải nói số ngày làm tròn lên, nhận %q", n.noiDung)
	}
}

// Lượt quét chạy 5 phút/lần: KHÔNG được nhắc lại trong cùng ngày.
//
// Thiếu chốt này thì khách nhận 288 thông báo giống hệt mỗi ngày, và cái chuông
// lập tức thành thứ không ai bấm nữa — kể cả khi có việc thật.
func TestNhacHan_MotNgayChiNhacMotLan(t *testing.T) {
	hd := &fakeHopDongQuet{sapHetHan: []domain.HopDongQuaHan{
		hopDongSapChet(7, 42, "order1", 3, false),
	}}
	tb := &fakeThongBao{}
	svc := NewQuetHanService(hd, &fakeCuaHangQuet{}, tb)

	_, _ = svc.QuetMotLuot(context.Background())
	kq, _ := svc.QuetMotLuot(context.Background())

	if kq.DaNhac != 0 {
		t.Errorf("lượt thứ hai không được nhắc lại, nhận %d", kq.DaNhac)
	}
	if len(tb.daDay) != 1 {
		t.Errorf("chỉ được đẩy 1 thông báo, nhận %d", len(tb.daDay))
	}
}

// Hợp đồng DÙNG THỬ nói câu khác: khách chưa trả đồng nào, nên "gia hạn" không
// phải việc họ đang nghĩ tới — họ đang quyết định có mua hay không.
func TestNhacHan_DungThuNoiCauKhac(t *testing.T) {
	hd := &fakeHopDongQuet{sapHetHan: []domain.HopDongQuaHan{
		hopDongSapChet(7, 42, "order1", 2, true),
	}}
	tb := &fakeThongBao{}

	_, _ = NewQuetHanService(hd, &fakeCuaHangQuet{}, tb).QuetMotLuot(context.Background())

	if len(tb.daDay) != 1 {
		t.Fatalf("mong 1 thông báo, nhận %d", len(tb.daDay))
	}
	if !strings.Contains(tb.daDay[0].tieuDe, "dùng thử") {
		t.Errorf("tiêu đề phải nói về bản dùng thử, nhận %q", tb.daDay[0].tieuDe)
	}
}

// Không có sổ thông báo (chưa dựng, hoặc tắt): phần KHOÁ CỬA HÀNG vẫn phải chạy.
// Nhắc là việc phụ; khoá khách hết hạn mới là việc chính.
func TestNhacHan_ThieuSoThongBaoVanKhoaCuaHang(t *testing.T) {
	hd := &fakeHopDongQuet{
		quaHan:    []domain.HopDongQuaHan{hopDongChet(7, 42, "order1", true)},
		sapHetHan: []domain.HopDongQuaHan{hopDongSapChet(8, 43, "order2", 3, false)},
	}
	ch := &fakeCuaHangQuet{}

	kq, err := NewQuetHanService(hd, ch, nil).QuetMotLuot(context.Background())
	if err != nil {
		t.Fatalf("lượt quét hỏng: %v", err)
	}
	if kq.DaKhoa != 1 {
		t.Errorf("vẫn phải khoá cửa hàng quá hạn, nhận %d", kq.DaKhoa)
	}
	if kq.DaNhac != 0 {
		t.Errorf("không có sổ thông báo thì không nhắc ai, nhận %d", kq.DaNhac)
	}
}

// Nhắc trước ĐÚNG 5 ngày — con số nghiệp vụ, đổi nó là đổi thói quen của khách.
func TestSoNgayNhacTruoc_LaNam(t *testing.T) {
	if SoNgayNhacTruoc != 5 {
		t.Errorf("mong 5 ngày, nhận %d", SoNgayNhacTruoc)
	}
}
