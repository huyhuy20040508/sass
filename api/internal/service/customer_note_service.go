package service

import (
	"context"
	"strings"
	"time"
	"unicode/utf8"

	"sass-api/internal/domain"
	"sass-api/internal/dto"
)

// GhiChuKhachService — sổ ghi chú khách của khung chi tiết khách ở quầy.
type GhiChuKhachService interface {
	CuaKhach(ctx context.Context, customerID uint) ([]domain.GhiChuKhach, error)
	// Them — homNay đưa vào để chặn ngày hẹn đã qua theo đúng ngày của cửa hàng.
	Them(ctx context.Context, customerID uint, req *dto.GhiChuKhachRequest, actorID uint, homNay time.Time) (*domain.GhiChuKhach, error)
	// Xoa — chỉ người ghi xoá được dòng của mình.
	Xoa(ctx context.Context, customerID, id, actorID uint) error
}

type ghiChuKhachService struct{ repo domain.GhiChuKhachRepository }

func NewGhiChuKhachService(repo domain.GhiChuKhachRepository) GhiChuKhachService {
	return &ghiChuKhachService{repo: repo}
}

func (s *ghiChuKhachService) laKhach(ctx context.Context, id uint) error {
	co, err := s.repo.LaKhach(ctx, id)
	if err != nil {
		return err
	}
	if !co {
		return domain.ErrNotFound
	}
	return nil
}

func (s *ghiChuKhachService) CuaKhach(ctx context.Context, customerID uint) ([]domain.GhiChuKhach, error) {
	if err := s.laKhach(ctx, customerID); err != nil {
		return nil, err
	}
	return s.repo.CuaKhach(ctx, customerID)
}

func (s *ghiChuKhachService) Them(ctx context.Context, customerID uint, req *dto.GhiChuKhachRequest, actorID uint, homNay time.Time) (*domain.GhiChuKhach, error) {
	if err := s.laKhach(ctx, customerID); err != nil {
		return nil, err
	}

	o := map[string]string{}
	noiDung := strings.TrimSpace(req.NoiDung)
	switch {
	case noiDung == "":
		o["noi_dung"] = "Nhập nội dung ghi chú."
	case utf8.RuneCountInString(noiDung) > 500:
		o["noi_dung"] = "Ghi chú tối đa 500 ký tự."
	}
	loai := strings.TrimSpace(req.Loai)
	if loai == "" {
		loai = domain.GhiChuChamSoc
	}
	if !domain.LoaiGhiChuHopLe(loai) {
		o["loai"] = "Loại ghi chú không hợp lệ."
	}
	var hen *time.Time
	if loai == domain.GhiChuHen {
		t, err := time.Parse("2006-01-02", strings.TrimSpace(req.NgayHen))
		hn := time.Date(homNay.Year(), homNay.Month(), homNay.Day(), 0, 0, 0, 0, time.UTC)
		switch {
		case err != nil:
			o["ngay_hen"] = "Chọn ngày hẹn."
		case t.Before(hn):
			o["ngay_hen"] = "Ngày hẹn đã qua."
		default:
			hen = &t
		}
	}
	if len(o) > 0 {
		return nil, &domain.LoiGhiChuKhach{O: o}
	}

	g := &domain.GhiChuKhach{CustomerID: customerID, Loai: loai, NoiDung: noiDung, HenNgay: hen, CreatedBy: nguoiTao(actorID)}
	if err := s.repo.Them(ctx, g); err != nil {
		return nil, err
	}

	return g, nil
}

func (s *ghiChuKhachService) Xoa(ctx context.Context, customerID, id, actorID uint) error {
	g, err := s.repo.Tim(ctx, id)
	if err != nil {
		return err
	}
	if g.CustomerID != customerID {
		return domain.ErrNotFound
	}
	if g.CreatedBy == nil || *g.CreatedBy != actorID {
		return domain.ErrForbidden
	}
	return s.repo.Xoa(ctx, id)
}
