package service

import (
	"context"
	"strings"

	"sass-api/internal/domain"
	"sass-api/internal/dto"
)

// SupplierService — nghiệp vụ nhà cung cấp (bên bán hàng cho cửa hàng).
type SupplierService interface {
	List(ctx context.Context, f domain.SupplierFilter) ([]domain.Supplier, error)
	GetByID(ctx context.Context, id uint) (*domain.Supplier, error)
	Create(ctx context.Context, req *dto.SupplierRequest) (*domain.Supplier, error)
	Update(ctx context.Context, id uint, req *dto.SupplierRequest) (*domain.Supplier, error)
	Delete(ctx context.Context, id uint) error
}

type supplierService struct {
	repo domain.SupplierRepository
	// quyTac là quy tắc đánh số của cửa hàng. Chưa bật thì mã vẫn đặt theo cách
	// cũ (NCC001…), nên cửa hàng không đụng màn cấu hình không thấy gì khác đi.
	quyTac domain.QuyTacMaRepository
}

func NewSupplierService(repo domain.SupplierRepository, quyTac domain.QuyTacMaRepository) SupplierService {
	return &supplierService{repo: repo, quyTac: quyTac}
}

func (s *supplierService) List(ctx context.Context, f domain.SupplierFilter) ([]domain.Supplier, error) {
	return s.repo.List(ctx, f)
}

func (s *supplierService) GetByID(ctx context.Context, id uint) (*domain.Supplier, error) {
	return s.repo.FindByID(ctx, id)
}

func (s *supplierService) Create(ctx context.Context, req *dto.SupplierRequest) (*domain.Supplier, error) {
	code := strings.ToUpper(strings.TrimSpace(req.Code))
	if code == "" {
		next, err := s.maTuSinh(ctx)
		if err != nil {
			return nil, err
		}
		code = next
	}

	exists, err := s.repo.ExistsByCode(ctx, code, 0)
	if err != nil {
		return nil, err
	}
	if exists {
		return nil, domain.ErrConflict
	}

	sup := &domain.Supplier{
		Code:        code,
		Name:        strings.TrimSpace(req.Name),
		ContactName: strings.TrimSpace(req.ContactName),
		Phone:       strings.TrimSpace(req.Phone),
		Email:       strings.TrimSpace(req.Email),
		Address:     strings.TrimSpace(req.Address),
		TaxCode:     strings.TrimSpace(req.TaxCode),
		Note:        strings.TrimSpace(req.Note),
		IsActive:    req.IsActive == nil || *req.IsActive,
	}
	if err := s.repo.Create(ctx, sup); err != nil {
		return nil, err
	}
	return s.repo.FindByID(ctx, sup.ID)
}

func (s *supplierService) Update(ctx context.Context, id uint, req *dto.SupplierRequest) (*domain.Supplier, error) {
	sup, err := s.repo.FindByID(ctx, id)
	if err != nil {
		return nil, err
	}

	// Mã bỏ trống khi sửa = giữ nguyên mã cũ, không sinh mã mới: mã nhà cung cấp
	// đã in trên chứng từ giấy, tự đổi là hồ sơ hai bên lệch nhau.
	if code := strings.ToUpper(strings.TrimSpace(req.Code)); code != "" && code != sup.Code {
		exists, err := s.repo.ExistsByCode(ctx, code, id)
		if err != nil {
			return nil, err
		}
		if exists {
			return nil, domain.ErrConflict
		}
		sup.Code = code
	}

	sup.Name = strings.TrimSpace(req.Name)
	sup.ContactName = strings.TrimSpace(req.ContactName)
	sup.Phone = strings.TrimSpace(req.Phone)
	sup.Email = strings.TrimSpace(req.Email)
	sup.Address = strings.TrimSpace(req.Address)
	sup.TaxCode = strings.TrimSpace(req.TaxCode)
	sup.Note = strings.TrimSpace(req.Note)
	if req.IsActive != nil {
		sup.IsActive = *req.IsActive
	}

	if err := s.repo.Update(ctx, sup); err != nil {
		return nil, err
	}
	return s.repo.FindByID(ctx, id)
}

// maTuSinh đặt mã cho nhà cung cấp mới: theo quy tắc của cửa hàng nếu đã bật,
// không thì giữ dải NCC001 sẵn có.
func (s *supplierService) maTuSinh(ctx context.Context) (string, error) {
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

func (s *supplierService) Delete(ctx context.Context, id uint) error {
	if _, err := s.repo.FindByID(ctx, id); err != nil {
		return err
	}
	return s.repo.Delete(ctx, id)
}
