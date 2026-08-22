package service

import (
	"context"
	"strings"

	"sass-api/internal/domain"
	"sass-api/internal/dto"
)

// NhaCungCapService — nghiệp vụ danh mục nhà cung cấp.
type NhaCungCapService interface {
	List(ctx context.Context, f domain.NhaCungCapFilter) ([]domain.NhaCungCap, error)
	GetByID(ctx context.Context, id uint) (*domain.NhaCungCap, error)
	Create(ctx context.Context, req *dto.NhaCungCapRequest) (*domain.NhaCungCap, error)
	Update(ctx context.Context, id uint, req *dto.NhaCungCapRequest) (*domain.NhaCungCap, error)
	DoiTrangThai(ctx context.Context, id uint, batLen bool) (*domain.NhaCungCap, error)
	Delete(ctx context.Context, id uint) error
}

type nhaCungCapService struct {
	repo domain.NhaCungCapRepository
	// quyTac là quy tắc đánh số của cửa hàng. Chưa bật thì mã vẫn theo dải NCC001.
	quyTac domain.QuyTacMaRepository
}

func NewNhaCungCapService(repo domain.NhaCungCapRepository, quyTac domain.QuyTacMaRepository) NhaCungCapService {
	return &nhaCungCapService{repo: repo, quyTac: quyTac}
}

func (s *nhaCungCapService) List(ctx context.Context, f domain.NhaCungCapFilter) ([]domain.NhaCungCap, error) {
	return s.repo.List(ctx, f)
}

func (s *nhaCungCapService) GetByID(ctx context.Context, id uint) (*domain.NhaCungCap, error) {
	return s.repo.FindByID(ctx, id)
}

func (s *nhaCungCapService) Create(ctx context.Context, req *dto.NhaCungCapRequest) (*domain.NhaCungCap, error) {
	code, err := s.chotMa(ctx, strings.ToUpper(strings.TrimSpace(req.Code)), 0)
	if err != nil {
		return nil, err
	}

	ncc := &domain.NhaCungCap{Code: code, IsActive: req.IsActive == nil || *req.IsActive}
	dienTruong(ncc, req)

	if err := s.repo.Create(ctx, ncc); err != nil {
		return nil, err
	}

	return ncc, nil
}

func (s *nhaCungCapService) Update(ctx context.Context, id uint, req *dto.NhaCungCapRequest) (*domain.NhaCungCap, error) {
	ncc, err := s.repo.FindByID(ctx, id)
	if err != nil {
		return nil, err
	}

	// Mã bỏ trống khi sửa = GIỮ NGUYÊN mã cũ, không sinh mã mới: mã đã in trên
	// chứng từ giấy và đơn đặt hàng gửi cho bên bán.
	code := strings.ToUpper(strings.TrimSpace(req.Code))
	if code == "" {
		code = ncc.Code
	} else if code != ncc.Code {
		trung, err := s.repo.ExistsByCode(ctx, code, id)
		if err != nil {
			return nil, err
		}
		if trung {
			return nil, domain.ErrNhaCungCapTrungMa
		}
	}

	ncc.Code = code
	dienTruong(ncc, req)
	if req.IsActive != nil {
		ncc.IsActive = *req.IsActive
	}

	if err := s.repo.Update(ctx, ncc); err != nil {
		return nil, err
	}

	return ncc, nil
}

// DoiTrangThai chỉ ghi đúng cột is_active — công tắc trên bảng không được phép
// mang theo tên, mã hay địa chỉ.
func (s *nhaCungCapService) DoiTrangThai(ctx context.Context, id uint, batLen bool) (*domain.NhaCungCap, error) {
	ncc, err := s.repo.FindByID(ctx, id)
	if err != nil {
		return nil, err
	}

	ncc.IsActive = batLen
	if err := s.repo.Update(ctx, ncc); err != nil {
		return nil, err
	}

	return ncc, nil
}

// Delete chặn khi còn phiếu đặt hàng trỏ tới: xoá đi là phiếu cũ mất đầu mối
// liên hệ. Muốn dừng nhập hàng thì tắt hợp tác.
func (s *nhaCungCapService) Delete(ctx context.Context, id uint) error {
	ncc, err := s.repo.FindByID(ctx, id)
	if err != nil {
		return err
	}
	if ncc.PurchaseCount > 0 {
		return domain.ErrNhaCungCapDangDung
	}

	return s.repo.Delete(ctx, id)
}

// chotMa quyết mã cho bên mới: gõ tay thì kiểm trùng, bỏ trống thì phần mềm đặt
// theo quy tắc đánh số của cửa hàng, chưa bật quy tắc thì giữ dải NCC001.
func (s *nhaCungCapService) chotMa(ctx context.Context, code string, excludeID uint) (string, error) {
	if code == "" {
		ma, err := s.quyTac.SinhMa(ctx, domain.LoaiNhaCungCap, 0, func(ma string) (bool, error) {
			return s.repo.ExistsByCode(ctx, ma, 0)
		})
		if err != nil {
			return "", err
		}
		if ma != "" {
			return ma, nil
		}

		return s.repo.NextCode(ctx)
	}

	trung, err := s.repo.ExistsByCode(ctx, code, excludeID)
	if err != nil {
		return "", err
	}
	if trung {
		return "", domain.ErrNhaCungCapTrungMa
	}

	return code, nil
}

// dienTruong chép các ô người dùng gõ, đã cắt khoảng trắng thừa.
func dienTruong(ncc *domain.NhaCungCap, req *dto.NhaCungCapRequest) {
	ncc.Name = strings.TrimSpace(req.Name)
	ncc.ShortName = strings.TrimSpace(req.ShortName)
	ncc.TaxCode = strings.TrimSpace(req.TaxCode)
	ncc.Phone = strings.TrimSpace(req.Phone)
	ncc.Email = strings.TrimSpace(req.Email)
	ncc.Address = strings.TrimSpace(req.Address)
	ncc.AddressLine2 = strings.TrimSpace(req.AddressLine2)
	ncc.RepresentativeName = strings.TrimSpace(req.RepresentativeName)
	ncc.RepresentativePhone = strings.TrimSpace(req.RepresentativePhone)
	ncc.Image = strings.TrimSpace(req.Image)
	ncc.Note = strings.TrimSpace(req.Note)
}
