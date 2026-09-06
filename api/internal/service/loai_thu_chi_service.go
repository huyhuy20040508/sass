package service

import (
	"context"
	"strings"

	"sass-api/internal/domain"
	"sass-api/internal/dto"
)

// LoaiThuChiService — nghiệp vụ loại thu chi (Thu chi → Loại thu chi).
type LoaiThuChiService interface {
	List(ctx context.Context, f domain.LoaiThuChiFilter) ([]domain.LoaiThuChi, error)
	GetByID(ctx context.Context, id uint) (*domain.LoaiThuChi, error)
	Create(ctx context.Context, req *dto.LoaiThuChiRequest) (*domain.LoaiThuChi, error)
	// Update chỉ đổi TÊN — xem chú thích ở hàm.
	Update(ctx context.Context, id uint, req *dto.SuaLoaiThuChiRequest) (*domain.LoaiThuChi, error)
	Delete(ctx context.Context, id uint) error
}

type loaiThuChiService struct {
	repo domain.LoaiThuChiRepository
}

func NewLoaiThuChiService(repo domain.LoaiThuChiRepository) LoaiThuChiService {
	return &loaiThuChiService{repo: repo}
}

func (s *loaiThuChiService) List(ctx context.Context, f domain.LoaiThuChiFilter) ([]domain.LoaiThuChi, error) {
	return s.repo.List(ctx, f)
}

func (s *loaiThuChiService) GetByID(ctx context.Context, id uint) (*domain.LoaiThuChi, error) {
	return s.repo.FindByID(ctx, id)
}

func (s *loaiThuChiService) Create(ctx context.Context, req *dto.LoaiThuChiRequest) (*domain.LoaiThuChi, error) {
	name := strings.TrimSpace(req.Name)
	// Con trỏ đã qua được `binding:"required,oneof=0 1"` nên chắc chắn khác nil.
	loai := *req.Type

	trung, err := s.repo.ExistsByName(ctx, loai, name, 0)
	if err != nil {
		return nil, err
	}
	if trung {
		return nil, domain.ErrLoaiThuChiTrungTen
	}

	l := &domain.LoaiThuChi{Type: loai, Name: name}
	if err := s.repo.Create(ctx, l); err != nil {
		return nil, err
	}

	return l, nil
}

// Update đổi ĐÚNG cột name.
//
// Không cho chuyển một loại từ thu sang chi: phiếu đã lập đang trỏ vào dòng
// này, đổi vế là mọi phiếu thu cũ biến thành phiếu chi trong mọi bảng cộng dồn
// — một lượt sửa tên vô hại kéo theo số liệu sai mà không ai thấy. Muốn đổi vế
// thì khai một loại mới bên kia.
func (s *loaiThuChiService) Update(ctx context.Context, id uint, req *dto.SuaLoaiThuChiRequest) (*domain.LoaiThuChi, error) {
	l, err := s.repo.FindByID(ctx, id)
	if err != nil {
		return nil, err
	}
	// Loại hệ thống dựng: phiếu tự sinh trỏ vào và đọc tên từ đây, đổi tên là
	// phiếu cũ mang tên mới mà không ai chủ ý.
	if l.IsDefault {
		return nil, domain.ErrLoaiThuChiMacDinh
	}

	name := strings.TrimSpace(req.Name)

	trung, err := s.repo.ExistsByName(ctx, l.Type, name, id)
	if err != nil {
		return nil, err
	}
	if trung {
		return nil, domain.ErrLoaiThuChiTrungTen
	}

	l.Name = name
	if err := s.repo.Update(ctx, l); err != nil {
		return nil, err
	}

	return l, nil
}

func (s *loaiThuChiService) Delete(ctx context.Context, id uint) error {
	l, err := s.repo.FindByID(ctx, id)
	if err != nil {
		return err
	}
	if l.IsDefault {
		return domain.ErrLoaiThuChiMacDinh
	}

	return s.repo.Delete(ctx, id)
}
