package service

import (
	"context"
	"strings"

	"sass-api/internal/domain"
	"sass-api/internal/dto"
)

// CategoryService định nghĩa nghiệp vụ danh mục.
type CategoryService interface {
	List(ctx context.Context, onlyActive bool) ([]domain.Category, error)
	Get(ctx context.Context, id uint) (*domain.Category, error)
	Create(ctx context.Context, req dto.CategoryRequest) (*domain.Category, error)
	Update(ctx context.Context, id uint, req dto.CategoryRequest) (*domain.Category, error)
	Delete(ctx context.Context, id uint) error
}

type categoryService struct {
	repo domain.CategoryRepository
	// quyTac là quy tắc đánh số của cửa hàng. Chưa bật thì mã nhóm vẫn là dải
	// NH0001 mà trang quản trị đang dùng.
	quyTac domain.QuyTacMaRepository
}

func NewCategoryService(repo domain.CategoryRepository, quyTac domain.QuyTacMaRepository) CategoryService {
	return &categoryService{repo: repo, quyTac: quyTac}
}

func (s *categoryService) List(ctx context.Context, onlyActive bool) ([]domain.Category, error) {
	return s.repo.List(ctx, onlyActive)
}

func (s *categoryService) Get(ctx context.Context, id uint) (*domain.Category, error) {
	return s.repo.FindByID(ctx, id)
}

func (s *categoryService) Create(ctx context.Context, req dto.CategoryRequest) (*domain.Category, error) {
	// Mã nhóm bỏ trống = để hệ thống đặt. Trang quản trị luôn đi đường này: mã
	// nhóm không phải thứ người dùng gõ.
	if strings.TrimSpace(req.Slug) == "" {
		ma, err := s.maTuSinh(ctx)
		if err != nil {
			return nil, err
		}
		req.Slug = ma
	}

	exists, err := s.repo.ExistsBySlug(ctx, req.Slug, 0)
	if err != nil {
		return nil, err
	}
	if exists {
		return nil, domain.ErrSlugExists
	}
	c := &domain.Category{
		ParentID:    req.ParentID,
		Name:        req.Name,
		Slug:        req.Slug,
		Description: req.Description,
		Image:       req.Image,
		SortOrder:   req.SortOrder,
		IsActive:    boolOrDefault(req.IsActive, true),
	}
	if err := s.repo.Create(ctx, c); err != nil {
		return nil, err
	}
	return c, nil
}

// maTuSinh đặt mã cho nhóm mới: theo quy tắc của cửa hàng nếu đã bật, không thì
// giữ dải NH0001 sẵn có.
func (s *categoryService) maTuSinh(ctx context.Context) (string, error) {
	ma, err := s.quyTac.SinhMa(ctx, domain.LoaiNhomHangHoa, 0, func(ma string) (bool, error) {
		return s.repo.ExistsBySlug(ctx, ma, 0)
	})
	if err != nil {
		return "", err
	}
	if ma != "" {
		return ma, nil
	}

	return s.repo.NextCode(ctx)
}

func (s *categoryService) Update(ctx context.Context, id uint, req dto.CategoryRequest) (*domain.Category, error) {
	c, err := s.repo.FindByID(ctx, id)
	if err != nil {
		return nil, err
	}
	exists, err := s.repo.ExistsBySlug(ctx, req.Slug, id)
	if err != nil {
		return nil, err
	}
	if exists {
		return nil, domain.ErrSlugExists
	}
	c.ParentID = req.ParentID
	c.Name = req.Name
	c.Slug = req.Slug
	c.Description = req.Description
	c.Image = req.Image
	c.SortOrder = req.SortOrder
	c.IsActive = boolOrDefault(req.IsActive, c.IsActive)
	if err := s.repo.Update(ctx, c); err != nil {
		return nil, err
	}
	return c, nil
}

func (s *categoryService) Delete(ctx context.Context, id uint) error {
	if _, err := s.repo.FindByID(ctx, id); err != nil {
		return err
	}
	return s.repo.Delete(ctx, id)
}

func boolOrDefault(v *bool, def bool) bool {
	if v == nil {
		return def
	}
	return *v
}
