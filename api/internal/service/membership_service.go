package service

import (
	"context"
	"strings"

	"sass-api/internal/domain"
	"sass-api/internal/dto"
)

// MembershipService — CRM → Thẻ thành viên (khuôn crm/membership-rank của v2):
// bảng hạng, quy đổi điểm, trang chi tiết một hạng.
type MembershipService interface {
	ListRanks(ctx context.Context) ([]domain.MembershipRank, error)
	GetRank(ctx context.Context, id uint) (*domain.MembershipRank, error)
	SaveRank(ctx context.Context, id uint, req dto.MembershipRankRequest) (*domain.MembershipRank, error)
	SetRankStatus(ctx context.Context, id uint, on bool) error
	DeleteRanks(ctx context.Context, ids []uint) error
	Members(ctx context.Context, rankID uint, keyword string, page, pageSize int) ([]domain.RankMember, int64, error)
	Conversion(ctx context.Context) (*domain.PointConversion, error)
	SaveConversion(ctx context.Context, req dto.PointConversionRequest) (*domain.PointConversion, error)
}

type membershipService struct {
	repo  domain.MembershipRepository
	users domain.UserRepository
}

func NewMembershipService(repo domain.MembershipRepository, users domain.UserRepository) MembershipService {
	return &membershipService{repo: repo, users: users}
}

func (s *membershipService) ListRanks(ctx context.Context) ([]domain.MembershipRank, error) {
	return s.repo.ListRanks(ctx)
}

func (s *membershipService) GetRank(ctx context.Context, id uint) (*domain.MembershipRank, error) {
	return s.repo.FindRank(ctx, id)
}

func (s *membershipService) SaveRank(ctx context.Context, id uint, req dto.MembershipRankRequest) (*domain.MembershipRank, error) {
	h := &domain.MembershipRank{}
	if id > 0 {
		cu, err := s.repo.FindRank(ctx, id)
		if err != nil {
			return nil, err
		}
		h = cu
	}
	loi := map[string]string{}
	h.Name = strings.TrimSpace(req.Name)
	h.Point = req.Point
	h.DiscountType = req.DiscountType
	h.DiscountValue = req.DiscountValue
	if h.DiscountType == domain.RankGiamPhanTram && h.DiscountValue > 100 {
		loi["discount_value"] = "Giảm theo phần trăm tối đa 100%"
	}
	h.Status = boolOrDefault(req.Status, true)
	h.ApplyAllOrderValues = req.ApplyAllOrderValues
	h.MinOrderValue, h.MaxOrderValue = nil, nil
	if !req.ApplyAllOrderValues {
		h.MinOrderValue, h.MaxOrderValue = req.MinOrderValue, req.MaxOrderValue
		if h.MinOrderValue == nil && h.MaxOrderValue == nil {
			loi["min_order_value"] = "Nhập giá trị đơn hàng áp dụng"
		}
		if h.MinOrderValue != nil && h.MaxOrderValue != nil && *h.MaxOrderValue < *h.MinOrderValue {
			loi["max_order_value"] = "Giá trị đến phải lớn hơn hoặc bằng giá trị từ"
		}
	}
	if len(loi) > 0 {
		return nil, loiO(loi)
	}
	if err := s.repo.SaveRank(ctx, h); err != nil {
		if err == domain.ErrHangTrungDiem {
			return nil, loiO(map[string]string{"point": err.Error()})
		}
		return nil, err
	}
	if err := s.repo.XepHangLai(ctx); err != nil {
		return nil, err
	}

	return s.repo.FindRank(ctx, h.ID)
}

func (s *membershipService) SetRankStatus(ctx context.Context, id uint, on bool) error {
	h, err := s.repo.FindRank(ctx, id)
	if err != nil {
		return err
	}
	h.Status = on
	if err := s.repo.SaveRank(ctx, h); err != nil {
		return err
	}

	return s.repo.XepHangLai(ctx)
}

func (s *membershipService) DeleteRanks(ctx context.Context, ids []uint) error {
	if err := s.repo.DeleteRanks(ctx, ids); err != nil {
		return err
	}

	return s.repo.XepHangLai(ctx)
}

func (s *membershipService) Members(ctx context.Context, rankID uint, keyword string, page, pageSize int) ([]domain.RankMember, int64, error) {
	if page < 1 {
		page = 1
	}
	ds, total, err := s.repo.Members(ctx, rankID, keyword, page, pageSize)
	if err != nil || len(ds) == 0 {
		return ds, total, err
	}
	ids := make([]uint, 0, len(ds))
	for _, m := range ds {
		ids = append(ids, m.ID)
	}
	diaChi, err := s.users.DefaultAddresses(ctx, ids)
	if err != nil {
		return nil, 0, err
	}
	for i := range ds {
		ds[i].Address = diaChi[ds[i].ID]
	}

	return ds, total, nil
}

func (s *membershipService) Conversion(ctx context.Context) (*domain.PointConversion, error) {
	return s.repo.Conversion(ctx)
}

// SaveConversion lưu MỘT trong hai khối (req.Kind): "earn" tiền → điểm, hoặc
// "redeem" điểm → tiền. Khối kia giữ nguyên như hai hộp riêng của v2.
func (s *membershipService) SaveConversion(ctx context.Context, req dto.PointConversionRequest) (*domain.PointConversion, error) {
	c, err := s.repo.Conversion(ctx)
	if err != nil {
		return nil, err
	}
	switch req.Kind {
	case "earn":
		c.EarnMoney, c.EarnPoint, c.EarnEnabled = req.Money, req.Point, req.Enabled
	default:
		c.RedeemMoney, c.RedeemPoint, c.RedeemEnabled = req.Money, req.Point, req.Enabled
	}
	if req.Enabled && (req.Money <= 0 || req.Point == 0) {
		return nil, loiO(map[string]string{"money": "Nhập số tiền và số điểm lớn hơn 0"})
	}
	if err := s.repo.SaveConversion(ctx, c); err != nil {
		return nil, err
	}

	return s.repo.Conversion(ctx)
}
