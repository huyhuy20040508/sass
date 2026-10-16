package service

import (
	"context"
	"fmt"
	"math"
	"sort"
	"strings"
	"time"

	"sass-api/internal/chinhanh"
	"sass-api/internal/domain"
	"sass-api/internal/dto"
)

// CaLamViecService — mở ca, đóng ca và sổ quỹ tiền mặt.
//
// Cụm này tồn tại để trả lời đúng một câu vào cuối ngày: TIỀN TRONG KÉT có khớp
// với SỔ không, và nếu lệch thì lệch trong lượt trực của ai. Mọi thứ ở đây đều
// quy về câu đó — cái gì không phục vụ nó thì không thuộc về đây.
type CaLamViecService interface {
	// HienTai trả ca đang mở của chi nhánh đang làm việc, kèm số cộng dồn. nil =
	// chưa mở ca nào.
	HienTai(ctx context.Context) (*domain.CaLamViec, error)
	MoCa(ctx context.Context, req dto.MoCaRequest, actorID uint) (*domain.CaLamViec, error)
	DongCa(ctx context.Context, req dto.DongCaRequest, actorID uint) (*dto.DongCaResponse, error)
	List(ctx context.Context, f domain.CaFilter) ([]domain.CaLamViec, int64, error)
	// ChiTiet trả ca kèm toàn bộ dòng sổ quỹ của nó.
	ChiTiet(ctx context.Context, id uint) (*dto.CaChiTietResponse, error)
	// GhiTay ghi một khoản thu/chi tiền mặt do người trực nhập.
	GhiTay(ctx context.Context, req dto.GhiSoQuyRequest, actorID uint) (*domain.SoQuy, error)
	// NgoaiCaHomNay — tiền mặt hôm nay của chi nhánh đang làm mà không thuộc ca
	// nào: người mở ca kế tiếp phải biết để đếm két cho đúng.
	NgoaiCaHomNay(ctx context.Context) ([]domain.SoQuy, error)
	// NguoiTruc — những người đã mở ca ở chi nhánh đang làm (cho ô lọc).
	NguoiTruc(ctx context.Context, shopID uint) ([]domain.NguoiTrucCa, error)
	// NhanVienTruc — nhân sự chọn được làm người trực két ở chi nhánh đang làm.
	NhanVienTruc(ctx context.Context) ([]domain.NhanVienTruc, error)
	// NhatKyNhanVien — tab Nhật ký của một nhân sự: phần tổng ca trực + một trang dòng.
	NhatKyNhanVien(ctx context.Context, employeeID uint, page, pageSize int) (*NhatKyNhanVienTrang, error)
	// GanNgoaiCa gắn các khoản tiền mặt ngoài ca vào ca đang mở của chi nhánh.
	GanNgoaiCa(ctx context.Context, ids []uint, actorID uint) (*domain.CaLamViec, error)
	// MocGanNgoaiCa — khoản phát sinh sau mốc này mới gắn được (giờ đóng ca trước); nil = mọi khoản.
	MocGanNgoaiCa(ctx context.Context) (*time.Time, error)
	// DungCanhBaoLech gắn chuông + cài đặt ngưỡng — gọi một lần lúc dựng ứng dụng.
	DungCanhBaoLech(notify NotificationService, caiDat SettingService)
	// BaoCaoKetCa — báo cáo kết ca: một trang ca, tổng số ca và dòng tổng cả kỳ.
	BaoCaoKetCa(ctx context.Context, q BaoCaoCaQuery) ([]domain.BaoCaoCaDong, int64, domain.BaoCaoCaTong, error)
}

type caLamViecService struct {
	repo domain.CaLamViecRepository
	// nguoi để tra tên người mở/đóng ca. Có thể nil (test) — mọi chỗ dùng đều qua
	// helper đã kiểm nil, và khi đó màn hình chỉ mất mấy cái tên.
	nguoi domain.UserRepository
	shops domain.ChiNhanhRepository
	// notify / caiDat — báo lệch két cho quản lý; nil (test cũ) thì không báo.
	notify NotificationService
	caiDat SettingService
}

func (s *caLamViecService) DungCanhBaoLech(notify NotificationService, caiDat SettingService) {
	s.notify, s.caiDat = notify, caiDat
}

func NewCaLamViecService(
	repo domain.CaLamViecRepository, nguoi domain.UserRepository, shops domain.ChiNhanhRepository,
) CaLamViecService {
	return &caLamViecService{repo: repo, nguoi: nguoi, shops: shops}
}

// chiNhanhDangLam lấy chi nhánh của request.
//
// Ca làm việc LUÔN thuộc về một chi nhánh cụ thể: cái két là vật lý, nó nằm ở
// một chỗ. Không xác định được chi nhánh thì không mở ca được — thà dừng ở đây
// còn hơn mở một ca treo lơ lửng rồi tiền của mọi quầy đổ chung vào.
func (s *caLamViecService) chiNhanhDangLam(ctx context.Context) (uint, error) {
	if id, ok := chinhanh.ID(ctx); ok {
		return id, nil
	}
	// Không có header chi nhánh: tiệm một chi nhánh (gần như mọi khách hôm nay).
	// Lấy chi nhánh đang mở duy nhất — cùng cách với luồng bán hàng, để ca và đơn
	// không bao giờ rơi vào hai chi nhánh khác nhau.
	ds, err := s.shops.List(ctx, true)
	if err != nil {
		return 0, err
	}
	if len(ds) == 0 {
		return 0, domain.ErrNotFound
	}
	return ds[0].ID, nil
}

func (s *caLamViecService) HienTai(ctx context.Context) (*domain.CaLamViec, error) {
	shopID, err := s.chiNhanhDangLam(ctx)
	if err != nil {
		return nil, err
	}

	ca, err := s.repo.CaDangMoCua(ctx, shopID)
	if err != nil || ca == nil {
		return nil, err
	}
	return s.trangTri(ctx, ca)
}

// trangTri điền các con số cộng dồn và tên người vào một ca trước khi trả ra.
func (s *caLamViecService) trangTri(ctx context.Context, ca *domain.CaLamViec) (*domain.CaLamViec, error) {
	tk, err := s.repo.TongKet(ctx, ca.ID)
	if err != nil {
		return nil, err
	}
	ca.TongThu, ca.TongChi, ca.SoDonTienMat = tk.TongThu, tk.TongChi, tk.SoDonTienMat
	s.gánTên(ctx, ca)
	return ca, nil
}

// gánTên tra tên người mở/đóng ca. Hỏng thì bỏ qua: thiếu một cái tên không đáng
// làm cả màn hình đóng ca không mở được.
func (s *caLamViecService) gánTên(ctx context.Context, ca *domain.CaLamViec) {
	if s.nguoi == nil {
		return
	}
	if u, err := s.nguoi.FindByID(ctx, ca.OpenedBy); err == nil && u != nil {
		ca.OpenedName = u.FullName
	}
	if ca.EmployeeID != nil {
		ca.EmployeeName = s.repo.TenNhanVien(ctx, *ca.EmployeeID)
	}
	if ca.ClosedBy != nil {
		if u, err := s.nguoi.FindByID(ctx, *ca.ClosedBy); err == nil && u != nil {
			ca.ClosedName = u.FullName
		}
	}
}

// moBuToiDa — mở bù khi quên: lùi giờ mở tối đa chừng này so với bây giờ.
const moBuToiDa = 12 * time.Hour

// kiemMoCa chọn người trực két và giờ mở.
//
// Người trực: phải là nhân sự đang làm ở chi nhánh; bỏ trống thì lấy hồ sơ nhân
// sự gắn với tài khoản đang bấm (chưa có hồ sơ thì để trống — phiếu in tên người mở).
//
// Giờ mở: trong 12 giờ qua, không quá hiện tại, và không sớm hơn giờ đóng ca trước
// của chi nhánh — hai ca chồng giờ thì một khoản tiền thuộc về hai ca.
func (s *caLamViecService) kiemMoCa(ctx context.Context, shopID uint, req dto.MoCaRequest, actorID uint) (*uint, time.Time, error) {
	o := map[string]string{}
	ds, err := s.repo.NhanVienTruc(ctx, shopID)
	if err != nil {
		return nil, time.Time{}, err
	}
	var nguoiTruc *uint
	if req.EmployeeID != nil && *req.EmployeeID > 0 {
		for _, nv := range ds {
			if nv.ID == *req.EmployeeID {
				id := nv.ID
				nguoiTruc = &id
			}
		}
		if nguoiTruc == nil {
			o["employee_id"] = "Người trực phải là nhân sự đang làm ở chi nhánh này."
		}
	} else {
		for _, nv := range ds {
			if nv.UserID != nil && *nv.UserID == actorID {
				id := nv.ID
				nguoiTruc = &id
				break
			}
		}
	}

	now := time.Now()
	gioMo := now
	if v := strings.TrimSpace(req.OpenedAt); v != "" {
		t, ok := docGioMo(v)
		switch {
		case !ok:
			o["opened_at"] = "Giờ mở không đúng dạng."
		case t.After(now.Add(time.Minute)):
			o["opened_at"] = "Giờ mở không được sau thời điểm hiện tại."
		case t.Before(now.Add(-moBuToiDa)):
			o["opened_at"] = "Chỉ mở bù trong vòng 12 giờ qua."
		default:
			if t.Before(now) {
				gioMo = t
			}
			dong, err := s.repo.GioDongCaTruoc(ctx, shopID)
			if err != nil {
				return nil, time.Time{}, err
			}
			if dong != nil && gioMo.Before(*dong) {
				o["opened_at"] = "Ca trước đóng lúc " + dong.Format("15:04 02/01") + " — giờ mở không được sớm hơn."
			}
		}
	}
	if len(o) > 0 {
		return nil, time.Time{}, &domain.LoiMoCa{O: o}
	}
	return nguoiTruc, gioMo, nil
}

func docGioMo(v string) (time.Time, bool) {
	for _, mau := range []string{"2006-01-02 15:04:05", "2006-01-02 15:04", "2006-01-02T15:04:05", "2006-01-02T15:04"} {
		if t, err := time.ParseInLocation(mau, v, time.Local); err == nil {
			return t, true
		}
	}
	return time.Time{}, false
}

func (s *caLamViecService) MoCa(ctx context.Context, req dto.MoCaRequest, actorID uint) (*domain.CaLamViec, error) {
	shopID, err := s.chiNhanhDangLam(ctx)
	if err != nil {
		return nil, err
	}

	nguoiTruc, gioMo, err := s.kiemMoCa(ctx, shopID, req, actorID)
	if err != nil {
		return nil, err
	}
	dem, cau := lamBangDem(req.OpeningCount, req.OpeningCash)
	if cau != "" {
		return nil, &domain.LoiMoCa{O: map[string]string{"opening_count": cau}}
	}

	ca := &domain.CaLamViec{
		ShopID:       shopID,
		OpenedBy:     actorID,
		EmployeeID:   nguoiTruc,
		OpenedAt:     gioMo,
		OpeningCash:  req.OpeningCash,
		OpeningCount: dem,
		Note:         strings.TrimSpace(req.Note),
	}
	if err := s.repo.MoCa(ctx, ca); err != nil {
		return nil, err
	}
	return s.trangTri(ctx, ca)
}

func (s *caLamViecService) DongCa(ctx context.Context, req dto.DongCaRequest, actorID uint) (*dto.DongCaResponse, error) {
	shopID, err := s.chiNhanhDangLam(ctx)
	if err != nil {
		return nil, err
	}

	dangMo, err := s.repo.CaDangMoCua(ctx, shopID)
	if err != nil {
		return nil, err
	}
	if dangMo == nil {
		return nil, domain.ErrKhongCoCa
	}

	giao, giu, err := chiaBanGiao(req)
	if err != nil {
		return nil, err
	}
	dem, cau := lamBangDem(req.CountedCount, req.CountedCash)
	if cau != "" {
		return nil, &domain.LoiDongCa{O: map[string]string{"counted_count": cau}}
	}
	ca, err := s.repo.DongCa(ctx, dangMo.ID, req.CountedCash, dem, giao, giu, req.Note, actorID)
	if err != nil {
		return nil, err
	}
	s.gánTên(ctx, ca)

	// Tiền mặt phát sinh trong khoảng thời gian của ca nhưng KHÔNG gắn ca nào.
	//
	// Có nghĩa là chúng đã vào/ra két thật nhưng không nằm trong con số đối chiếu
	// ở trên. Im lặng bỏ qua là để lại một khoản chênh không ai giải thích được;
	// chỉ ra ở đây thì người đóng ca biết ngay phải hỏi ai.
	s.baoLechKet(ctx, ca)

	ngoai, err := s.repo.SoQuyNgoaiCa(ctx, shopID, ca.OpenedAt, *ca.ClosedAt)
	if err != nil {
		return nil, err
	}

	return &dto.DongCaResponse{Ca: ca, NgoaiCa: ngoai}, nil
}

func (s *caLamViecService) List(ctx context.Context, f domain.CaFilter) ([]domain.CaLamViec, int64, error) {
	list, total, err := s.repo.List(ctx, f)
	if err != nil {
		return nil, 0, err
	}
	for i := range list {
		s.gánTên(ctx, &list[i])
	}
	return list, total, nil
}

func (s *caLamViecService) ChiTiet(ctx context.Context, id uint) (*dto.CaChiTietResponse, error) {
	ca, err := s.repo.FindByID(ctx, id)
	if err != nil {
		return nil, err
	}
	if _, err := s.trangTri(ctx, ca); err != nil {
		return nil, err
	}

	dong, err := s.repo.SoQuyCuaCa(ctx, ca.ID)
	if err != nil {
		return nil, err
	}
	if err := s.repo.GanMaChungTu(ctx, dong); err != nil {
		return nil, err
	}
	dt, err := s.repo.DoanhThuCa(ctx, ca.ID)
	if err != nil {
		return nil, err
	}
	return &dto.CaChiTietResponse{Ca: ca, SoQuy: dong, DoanhThu: &dt}, nil
}

func (s *caLamViecService) NgoaiCaHomNay(ctx context.Context) ([]domain.SoQuy, error) {
	shopID, err := s.chiNhanhDangLam(ctx)
	if err != nil {
		return nil, err
	}
	now := time.Now()
	dauNgay := time.Date(now.Year(), now.Month(), now.Day(), 0, 0, 0, 0, now.Location())
	ds, err := s.repo.SoQuyNgoaiCa(ctx, shopID, dauNgay, now)
	if err != nil {
		return nil, err
	}
	if err := s.repo.GanMaChungTu(ctx, ds); err != nil {
		return nil, err
	}
	return ds, nil
}

func (s *caLamViecService) NhanVienTruc(ctx context.Context) ([]domain.NhanVienTruc, error) {
	shopID, err := s.chiNhanhDangLam(ctx)
	if err != nil {
		return nil, err
	}
	return s.repo.NhanVienTruc(ctx, shopID)
}

func (s *caLamViecService) NguoiTruc(ctx context.Context, shopID uint) ([]domain.NguoiTrucCa, error) {
	return s.repo.NguoiMoCa(ctx, shopID)
}

func (s *caLamViecService) GhiTay(ctx context.Context, req dto.GhiSoQuyRequest, actorID uint) (*domain.SoQuy, error) {
	shopID, err := s.chiNhanhDangLam(ctx)
	if err != nil {
		return nil, err
	}

	e := &domain.SoQuy{
		ShopID:        shopID,
		Direction:     req.Direction,
		Amount:        req.Amount,
		Reason:        strings.TrimSpace(req.Reason),
		ReferenceType: domain.SoQuyGhiTay,
		CreatedBy:     &actorID,
	}
	if err := s.repo.GhiTay(ctx, e, req.XacNhanAm); err != nil {
		return nil, err
	}
	return e, nil
}

// baoLechKet: ca vừa đóng lệch quá ngưỡng ở Cài đặt → một thông báo vào kênh
// quản trị (quản lý + chủ; thu ngân không thấy kênh này). Giao diện dựng link mở
// tờ ca từ data.shift_id. Báo hỏng không được làm hỏng lượt đóng ca.
func (s *caLamViecService) baoLechKet(ctx context.Context, ca *domain.CaLamViec) {
	if s.notify == nil || ca.Difference == nil || ca.ClosedAt == nil {
		return
	}
	nguong := 50000.0
	if s.caiDat != nil {
		nguong = s.caiDat.Float(ctx, SettingShiftDiffAlert)
	}
	lech := *ca.Difference
	if math.Abs(lech) <= nguong {
		return
	}

	truc := ca.EmployeeName
	if truc == "" {
		truc = ca.OpenedName
	}
	tenCN := ""
	if s.shops != nil {
		if cn, err := s.shops.FindByID(ctx, ca.ShopID); err == nil && cn != nil {
			tenCN = cn.Name
		}
	}
	kieu := "thiếu"
	if lech > 0 {
		kieu = "dư"
	}
	tieuDe := fmt.Sprintf("Ca #%d lệch két: %s %s", ca.ID, kieu, formatVND(math.Abs(lech)))
	noiDung := fmt.Sprintf("Người trực %s · đóng %s. Theo sổ %s, đếm được %s.",
		truc, ca.ClosedAt.Format("15:04 02/01"), formatVND(derefF(ca.ExpectedCash)), formatVND(derefF(ca.CountedCash)))
	if tenCN != "" {
		noiDung = tenCN + " · " + noiDung
	}
	if ca.CloseNote != "" {
		noiDung += " Ghi chú: " + ca.CloseNote
	}
	s.notify.Push(ctx, nil, "shift_diff", tieuDe, noiDung, map[string]any{
		"shift_id": ca.ID, "shop_id": ca.ShopID, "difference": lech,
	})
}

func derefF(v *float64) float64 {
	if v == nil {
		return 0
	}
	return *v
}

// NhatKyNhanVienTrang — trả về cho tab Nhật ký.
type NhatKyNhanVienTrang struct {
	Tong  domain.TongNhatKyCa     `json:"tong"`
	Items []domain.NhatKyNhanVien `json:"items"`
	Total int64                   `json:"total"`
	Page  int                     `json:"page"`
}

func (s *caLamViecService) NhatKyNhanVien(ctx context.Context, employeeID uint, page, pageSize int) (*NhatKyNhanVienTrang, error) {
	if page < 1 {
		page = 1
	}
	if pageSize < 1 || pageSize > 100 {
		pageSize = 20
	}
	ds, total, err := s.repo.NhatKyNhanVien(ctx, employeeID, page, pageSize)
	if err != nil {
		return nil, err
	}
	tong, err := s.repo.TongNhatKyCa(ctx, employeeID)
	if err != nil {
		return nil, err
	}
	return &NhatKyNhanVienTrang{Tong: tong, Items: ds, Total: total, Page: page}, nil
}

// chiaBanGiao tách tiền đếm được thành giao về chủ + giữ lại cho ca sau. Tính
// theo đồng (làm tròn) để 0,1 + 0,2 không lệch 1 đồng.
// lamBangDem kiểm bảng đếm theo mệnh giá rồi sắp lớn → nhỏ, bỏ dòng 0 tờ.
// Bảng phải cộng ra ĐÚNG số tiền đã nhập: lưu một bảng lệch với con số chốt
// thì lúc dò lệch két lại thêm một khoản lệch nữa.
func lamBangDem(ds []domain.DemTo, tong float64) (domain.BangDem, string) {
	hopLe := map[int64]bool{}
	for _, m := range domain.MenhGiaDem {
		hopLe[m] = true
	}
	gap := map[int64]bool{}
	var b domain.BangDem
	for _, d := range ds {
		switch {
		case !hopLe[d.MenhGia]:
			return nil, "Mệnh giá " + formatVND(float64(d.MenhGia)) + " không có trong bảng đếm."
		case gap[d.MenhGia]:
			return nil, "Mệnh giá " + formatVND(float64(d.MenhGia)) + " ghi hai lần."
		case d.SoTo < 0 || d.SoTo > 100000:
			return nil, "Số tờ " + formatVND(float64(d.MenhGia)) + " phải từ 0 đến 100.000."
		}
		gap[d.MenhGia] = true
		if d.SoTo > 0 {
			b = append(b, d)
		}
	}
	if len(b) == 0 {
		return nil, ""
	}
	sort.Slice(b, func(i, j int) bool { return b[i].MenhGia > b[j].MenhGia })
	if b.Tong() != math.Round(tong) {
		return nil, "Bảng đếm cộng ra " + formatVND(b.Tong()) + ", khác số tiền đã nhập " + formatVND(tong) + " — sửa một trong hai."
	}
	return b, ""
}

func chiaBanGiao(req dto.DongCaRequest) (giao, giu float64, err error) {
	dem := math.Round(req.CountedCash)
	o := map[string]string{}
	switch {
	case req.DeliveredCash == nil && req.KeptCash == nil:
		return dem, 0, nil
	case req.KeptCash == nil:
		giao = math.Round(*req.DeliveredCash)
		giu = dem - giao
	case req.DeliveredCash == nil:
		giu = math.Round(*req.KeptCash)
		giao = dem - giu
	default:
		giao, giu = math.Round(*req.DeliveredCash), math.Round(*req.KeptCash)
		if giao+giu != dem {
			o["kept_cash"] = "Giao về chủ + giữ lại = " + formatVND(giao+giu) + ", khác tiền đếm được " + formatVND(dem) + "."
		}
	}
	if giao < 0 {
		o["delivered_cash"] = "Giao về chủ không được âm — tối đa bằng tiền đếm được."
	}
	if giu < 0 {
		o["kept_cash"] = "Giữ lại không được âm — tối đa bằng tiền đếm được."
	}
	if len(o) > 0 {
		return 0, 0, &domain.LoiDongCa{O: o}
	}
	return giao, giu, nil
}

func (s *caLamViecService) GanNgoaiCa(ctx context.Context, ids []uint, actorID uint) (*domain.CaLamViec, error) {
	shopID, err := s.chiNhanhDangLam(ctx)
	if err != nil {
		return nil, err
	}
	gap := map[uint]bool{}
	var sach []uint
	for _, id := range ids {
		if id > 0 && !gap[id] {
			gap[id] = true
			sach = append(sach, id)
		}
	}
	if len(sach) == 0 {
		return nil, &domain.LoiGanNgoaiCa{Cau: "Chọn ít nhất một khoản để gắn."}
	}
	ca, err := s.repo.GanNgoaiCa(ctx, shopID, sach, actorID)
	if err != nil {
		return nil, err
	}
	return s.trangTri(ctx, ca)
}

func (s *caLamViecService) MocGanNgoaiCa(ctx context.Context) (*time.Time, error) {
	shopID, err := s.chiNhanhDangLam(ctx)
	if err != nil {
		return nil, err
	}
	return s.repo.GioDongCaTruoc(ctx, shopID)
}
