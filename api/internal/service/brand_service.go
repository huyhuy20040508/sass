package service

import (
	"context"

	"sass-api/internal/domain"
	"sass-api/internal/dto"
)

// BrandService định nghĩa nghiệp vụ thương hiệu.
type BrandService interface {
	List(ctx context.Context, onlyActive bool) ([]domain.Brand, error)
	Get(ctx context.Context, id uint) (*domain.Brand, error)
	Create(ctx context.Context, req dto.BrandRequest) (*domain.Brand, error)
	Update(ctx context.Context, id uint, req dto.BrandRequest) (*domain.Brand, error)
	Delete(ctx context.Context, id uint) error
}

type brandService struct {
	repo domain.BrandRepository
}

func NewBrandService(repo domain.BrandRepository) BrandService {
	return &brandService{repo: repo}
}

func (s *brandService) List(ctx context.Context, onlyActive bool) ([]domain.Brand, error) {
	return s.repo.List(ctx, onlyActive)
}

func (s *brandService) Get(ctx context.Context, id uint) (*domain.Brand, error) {
	return s.repo.FindByID(ctx, id)
}

func (s *brandService) Create(ctx context.Context, req dto.BrandRequest) (*domain.Brand, error) {
	exists, err := s.repo.ExistsBySlug(ctx, req.Slug, 0)
	if err != nil {
		return nil, err
	}
	if exists {
		return nil, domain.ErrSlugExists
	}
	b := &domain.Brand{
		Name:        req.Name,
		Slug:        req.Slug,
		Logo:        req.Logo,
		Description: req.Description,
		IsActive:    boolOrDefault(req.IsActive, true),
	}
	if err := s.repo.Create(ctx, b); err != nil {
		return nil, err
	}
	return b, nil
}

func (s *brandService) Update(ctx context.Context, id uint, req dto.BrandRequest) (*domain.Brand, error) {
	b, err := s.repo.FindByID(ctx, id)
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
	b.Name = req.Name
	b.Slug = req.Slug
	b.Logo = req.Logo
	b.Description = req.Description
	b.IsActive = boolOrDefault(req.IsActive, b.IsActive)
	if err := s.repo.Update(ctx, b); err != nil {
		return nil, err
	}
	return b, nil
}

func (s *brandService) Delete(ctx context.Context, id uint) error {
	if _, err := s.repo.FindByID(ctx, id); err != nil {
		return err
	}
	return s.repo.Delete(ctx, id)
}
