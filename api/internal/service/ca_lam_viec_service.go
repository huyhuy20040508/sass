package service

import (
	"context"
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
}

type caLamViecService struct {
	repo domain.CaLamViecRepository
	// nguoi để tra tên người mở/đóng ca. Có thể nil (test) — mọi chỗ dùng đều qua
	// helper đã kiểm nil, và khi đó màn hình chỉ mất mấy cái tên.
	nguoi domain.UserRepository
	shops domain.ChiNhanhRepository
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
	if ca.ClosedBy != nil {
		if u, err := s.nguoi.FindByID(ctx, *ca.ClosedBy); err == nil && u != nil {
			ca.ClosedName = u.FullName
		}
	}
}

func (s *caLamViecService) MoCa(ctx context.Context, req dto.MoCaRequest, actorID uint) (*domain.CaLamViec, error) {
	shopID, err := s.chiNhanhDangLam(ctx)
	if err != nil {
		return nil, err
	}

	ca := &domain.CaLamViec{
		ShopID:      shopID,
		OpenedBy:    actorID,
		OpenedAt:    time.Now(),
		OpeningCash: req.OpeningCash,
		Note:        strings.TrimSpace(req.Note),
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

	ca, err := s.repo.DongCa(ctx, dangMo.ID, req.CountedCash, req.Note, actorID)
	if err != nil {
		return nil, err
	}
	s.gánTên(ctx, ca)

	// Tiền mặt phát sinh trong khoảng thời gian của ca nhưng KHÔNG gắn ca nào.
	//
	// Có nghĩa là chúng đã vào/ra két thật nhưng không nằm trong con số đối chiếu
	// ở trên. Im lặng bỏ qua là để lại một khoản chênh không ai giải thích được;
	// chỉ ra ở đây thì người đóng ca biết ngay phải hỏi ai.
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
	return &dto.CaChiTietResponse{Ca: ca, SoQuy: dong}, nil
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
	if err := s.repo.GhiTay(ctx, e); err != nil {
		return nil, err
	}
	return e, nil
}
