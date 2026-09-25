package service

import (
	"context"
	"strings"

	"sass-api/internal/domain"
	"sass-api/internal/dto"
)

// CustomerGroupService — nhóm khách hàng, bảng tra của màn Khách hàng.
type CustomerGroupService interface {
	List(ctx context.Context, onlyActive bool, loai *uint) ([]dto.CustomerGroupResponse, error)
	Create(ctx context.Context, req *dto.CustomerGroupRequest) (*dto.CustomerGroupResponse, error)
	Update(ctx context.Context, id uint, req *dto.CustomerGroupRequest) (*dto.CustomerGroupResponse, error)
	Delete(ctx context.Context, id uint) error
}

type nhomKhachHangService struct {
	repo domain.CustomerGroupRepository
}

func NewCustomerGroupService(repo domain.CustomerGroupRepository) CustomerGroupService {
	return &nhomKhachHangService{repo: repo}
}

func (s *nhomKhachHangService) List(ctx context.Context, onlyActive bool, loai *uint) ([]dto.CustomerGroupResponse, error) {
	ds, err := s.repo.List(ctx, onlyActive, loai)
	if err != nil {
		return nil, err
	}

	out := make([]dto.CustomerGroupResponse, 0, len(ds))
	for i := range ds {
		out = append(out, buildNhomKhach(&ds[i]))
	}

	return out, nil
}

func (s *nhomKhachHangService) Create(ctx context.Context, req *dto.CustomerGroupRequest) (*dto.CustomerGroupResponse, error) {
	ten := strings.TrimSpace(req.Name)

	// Chặn trùng tên ở đây chứ không ở database: bảng xoá mềm nên UNIQUE KEY
	// không đặt được cho tử tế — xem migration 0061.
	trung, err := s.repo.ExistsByName(ctx, req.Type, ten, 0)
	if err != nil {
		return nil, err
	}
	if trung {
		return nil, domain.ErrConflict
	}

	g := &domain.CustomerGroup{
		Type:   req.Type,
		Name:   ten,
		Note:   domain.StringOrNull(strings.TrimSpace(req.Note)),
		Status: 1,
	}
	if req.Status != nil {
		g.Status = *req.Status
	}

	if err := s.repo.Create(ctx, g); err != nil {
		return nil, err
	}

	res := buildNhomKhach(g)

	return &res, nil
}

func (s *nhomKhachHangService) Update(ctx context.Context, id uint, req *dto.CustomerGroupRequest) (*dto.CustomerGroupResponse, error) {
	g, err := s.repo.FindByID(ctx, id)
	if err != nil {
		return nil, err
	}

	ten := strings.TrimSpace(req.Name)
	// Xét trùng theo LOẠI ĐANG CÓ của nhóm, không theo loại gửi lên: loại là thứ
	// quyết định nhóm nằm ở ô chọn nào, đổi nó là kéo nhóm sang danh sách khác
	// trong khi khách cũ vẫn trỏ vào — nên lượt sửa không đụng tới nó.
	trung, err := s.repo.ExistsByName(ctx, g.Type, ten, id)
	if err != nil {
		return nil, err
	}
	if trung {
		return nil, domain.ErrConflict
	}

	g.Name = ten
	g.Note = domain.StringOrNull(strings.TrimSpace(req.Note))
	if req.Status != nil {
		g.Status = *req.Status
	}

	if err := s.repo.Update(ctx, g); err != nil {
		return nil, err
	}

	res := buildNhomKhach(g)

	return &res, nil
}

// Delete từ chối khi nhóm còn khách: xoá đi thì mấy khách ấy mất tên nhóm mà
// màn hình không nói gì. Muốn thôi dùng thì tắt `status`.
func (s *nhomKhachHangService) Delete(ctx context.Context, id uint) error {
	if _, err := s.repo.FindByID(ctx, id); err != nil {
		return err
	}

	n, err := s.repo.CountCustomers(ctx, id)
	if err != nil {
		return err
	}
	if n > 0 {
		return domain.ErrConflict
	}

	return s.repo.Delete(ctx, id)
}

func buildNhomKhach(g *domain.CustomerGroup) dto.CustomerGroupResponse {
	return dto.CustomerGroupResponse{
		ID:     g.ID,
		Type:   g.Type,
		Name:   g.Name,
		Note:   string(g.Note),
		Status: g.Status,
	}
}
