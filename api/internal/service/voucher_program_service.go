package service

import (
	"context"
	"crypto/rand"
	"math/big"
	"regexp"
	"strconv"
	"strings"
	"time"

	"sass-api/internal/domain"
	"sass-api/internal/dto"
)

// VoucherProgramService — CRM → Voucher/Coupon (khuôn crm/voucher-coupon của v2).
//
// Chương trình chỉ là "khuôn" sinh mã: phát hành xong thì mỗi mã là một Voucher
// thường, và quầy / website áp mã qua VoucherService như mọi mã khác.
type VoucherProgramService interface {
	List(ctx context.Context, f domain.VoucherProgramFilter) ([]domain.VoucherProgram, int64, error)
	Get(ctx context.Context, id uint) (*domain.VoucherProgram, error)
	// Save lưu chương trình (id = 0 là tạo); req.Release thì phát hành luôn.
	Save(ctx context.Context, id uint, req dto.VoucherProgramRequest, userID uint) (*domain.VoucherProgram, error)
	Delete(ctx context.Context, id uint) error
	Codes(ctx context.Context, f domain.VoucherCodeFilter) ([]domain.Voucher, int64, error)
	SetCodeActive(ctx context.Context, voucherID uint, on bool) error
	CodeUses(ctx context.Context, voucherID uint) ([]domain.VoucherCodeUse, error)
}

type voucherProgramService struct {
	repo domain.VoucherProgramRepository
}

func NewVoucherProgramService(repo domain.VoucherProgramRepository) VoucherProgramService {
	return &voucherProgramService{repo: repo}
}

func (s *voucherProgramService) List(ctx context.Context, f domain.VoucherProgramFilter) ([]domain.VoucherProgram, int64, error) {
	if f.Page < 1 {
		f.Page = 1
	}

	return s.repo.List(ctx, f)
}

func (s *voucherProgramService) Get(ctx context.Context, id uint) (*domain.VoucherProgram, error) {
	return s.repo.FindByID(ctx, id)
}

func (s *voucherProgramService) Save(ctx context.Context, id uint, req dto.VoucherProgramRequest, userID uint) (*domain.VoucherProgram, error) {
	p := &domain.VoucherProgram{Status: domain.VoucherProgramChuaPhatHanh}
	if id > 0 {
		cu, err := s.repo.FindByID(ctx, id)
		if err != nil {
			return nil, err
		}
		if cu.Status == domain.VoucherProgramPhatHanh {
			return nil, domain.ErrVCDaPhatHanh
		}
		p = cu
	} else {
		// Mã chương trình do HỆ THỐNG cấp, nối tiếp theo cửa hàng.
		ma, err := s.repo.MaKeTiep(ctx)
		if err != nil {
			return nil, err
		}
		p.Code = ma
		if userID > 0 {
			uid := userID
			p.CreatedBy = &uid
		}
	}

	if err := dienVoucherProgram(p, req); err != nil {
		return nil, err
	}
	if err := s.repo.Save(ctx, p); err != nil {
		return nil, err
	}
	if req.Release {
		if err := s.phatHanh(ctx, p); err != nil {
			return nil, err
		}
	}

	return s.repo.FindByID(ctx, p.ID)
}

// chiChuSo: tiền tố / hậu tố chỉ nhận chữ Latinh và số — mã là thứ khách GÕ TAY
// ở ô thanh toán, cùng luật với mã lẻ ở màn Mã giảm giá.
var chiChuSo = regexp.MustCompile(`^[A-Z0-9]*$`)

func dienVoucherProgram(p *domain.VoucherProgram, req dto.VoucherProgramRequest) error {
	loi := map[string]string{}

	p.Name = strings.TrimSpace(req.Name)
	p.Description = strings.TrimSpace(req.Description)
	p.DiscountType = req.DiscountType
	p.DiscountValue = req.DiscountValue
	p.MaxDiscountAmount = nil
	if req.DiscountType == domain.DiscountPercentage {
		if req.DiscountValue > 100 {
			loi["discount_value"] = "Giá trị không được vượt quá 100%"
		}
		if req.MaxDiscountAmount != nil && *req.MaxDiscountAmount > 0 {
			m := *req.MaxDiscountAmount
			p.MaxDiscountAmount = &m
		}
	}
	p.MinOrderAmount = *req.MinOrderAmount

	p.NoTimeLimit = req.NoTimeLimit
	p.StartDate, p.EndDate = nil, nil
	if !req.NoTimeLimit {
		tu, e1 := time.ParseInLocation("2006-01-02", req.StartDate, time.Local)
		den, e2 := time.ParseInLocation("2006-01-02", req.EndDate, time.Local)
		switch {
		case e1 != nil || e2 != nil:
			loi["start_date"] = "Chọn ngày áp dụng, hoặc tick Không giới hạn thời gian"
		case den.Before(tu):
			loi["end_date"] = "Ngày kết thúc phải sau hoặc bằng ngày bắt đầu"
		default:
			p.StartDate, p.EndDate = &tu, &den
		}
	}

	p.AllShops = req.AllShops
	p.ShopIDs = locTrung(req.ShopIDs)
	if p.AllShops {
		p.ShopIDs = nil
	} else if len(p.ShopIDs) == 0 {
		loi["shop_ids"] = "Chọn chi nhánh áp dụng"
	}
	p.AllCategories = req.AllCategories
	p.CategoryIDs = locTrung(req.CategoryIDs)
	if p.AllCategories {
		p.CategoryIDs = nil
	} else if len(p.CategoryIDs) == 0 {
		loi["category_ids"] = "Chọn danh mục hàng hóa"
	}

	p.Prefix = strings.ToUpper(strings.TrimSpace(req.Prefix))
	p.Suffix = strings.ToUpper(strings.TrimSpace(req.Suffix))
	if !chiChuSo.MatchString(p.Prefix) || !chiChuSo.MatchString(p.Suffix) {
		loi["prefix"] = "Tiền tố, hậu tố chỉ gồm chữ không dấu và số"
	}
	p.Quantity = req.Quantity
	p.UsageLimit = req.UsageLimit

	if len(loi) > 0 {
		return loiO(loi)
	}

	return nil
}

func locTrung(ids []uint) []uint {
	out := []uint{}
	seen := map[uint]bool{}
	for _, id := range ids {
		if id > 0 && !seen[id] {
			seen[id] = true
			out = append(out, id)
		}
	}

	return out
}

// kyTuMa: 5 ký tự ngẫu nhiên giữa tiền tố và hậu tố — như VoucherCodeGenerator của v2.
const kyTuMa = "0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ"

func phanNgauNhien() string {
	b := make([]byte, 5)
	for i := range b {
		n, _ := rand.Int(rand.Reader, big.NewInt(int64(len(kyTuMa))))
		b[i] = kyTuMa[n.Int64()]
	}

	return string(b)
}

// phatHanh sinh đủ Quantity mã không trùng rồi chốt chương trình sang Phát hành.
func (s *voucherProgramService) phatHanh(ctx context.Context, p *domain.VoucherProgram) error {
	chon := map[string]bool{}
	for lan := 0; lan < 20 && len(chon) < p.Quantity; lan++ {
		ungVien := []string{}
		for len(ungVien) < (p.Quantity-len(chon))*2 {
			c := p.Prefix + phanNgauNhien() + p.Suffix
			if !chon[c] {
				ungVien = append(ungVien, c)
			}
		}
		daCo, err := s.repo.MaDaCo(ctx, ungVien)
		if err != nil {
			return err
		}
		for _, c := range ungVien {
			if len(chon) >= p.Quantity {
				break
			}
			if !daCo[c] {
				chon[c] = true
			}
		}
	}
	if len(chon) < p.Quantity {
		return domain.ErrVCHetMa
	}

	var batDau, ketThuc *time.Time
	if !p.NoTimeLimit && p.StartDate != nil && p.EndDate != nil {
		tu := time.Date(p.StartDate.Year(), p.StartDate.Month(), p.StartDate.Day(), 0, 0, 0, 0, time.Local)
		den := time.Date(p.EndDate.Year(), p.EndDate.Month(), p.EndDate.Day(), 23, 59, 59, int(999*time.Millisecond), time.Local)
		batDau, ketThuc = &tu, &den
	}
	dm := make([]string, 0, len(p.CategoryIDs))
	for _, id := range p.CategoryIDs {
		dm = append(dm, strconv.FormatUint(uint64(id), 10))
	}
	luot := uint(p.UsageLimit)

	ds := make([]domain.Voucher, 0, len(chon))
	for c := range chon {
		pid := p.ID
		ds = append(ds, domain.Voucher{
			ProgramID:         &pid,
			Code:              c,
			Description:       p.Name,
			DiscountType:      p.DiscountType,
			DiscountValue:     p.DiscountValue,
			MaxDiscountAmount: p.MaxDiscountAmount,
			MinOrderAmount:    p.MinOrderAmount,
			CategoryIDs:       strings.Join(dm, ","),
			UsageLimit:        &luot,
			StartAt:           batDau,
			EndAt:             ketThuc,
			IsActive:          true,
		})
	}

	return s.repo.PhatHanh(ctx, p, ds)
}

func (s *voucherProgramService) Delete(ctx context.Context, id uint) error {
	p, err := s.repo.FindByID(ctx, id)
	if err != nil {
		return err
	}
	if p.Status == domain.VoucherProgramPhatHanh {
		return domain.ErrVCDaPhatHanh
	}

	return s.repo.Delete(ctx, id)
}

func (s *voucherProgramService) Codes(ctx context.Context, f domain.VoucherCodeFilter) ([]domain.Voucher, int64, error) {
	if f.Page < 1 {
		f.Page = 1
	}

	return s.repo.Codes(ctx, f)
}

func (s *voucherProgramService) SetCodeActive(ctx context.Context, voucherID uint, on bool) error {
	return s.repo.SetCodeActive(ctx, voucherID, on)
}

func (s *voucherProgramService) CodeUses(ctx context.Context, voucherID uint) ([]domain.VoucherCodeUse, error) {
	return s.repo.CodeUses(ctx, voucherID)
}
