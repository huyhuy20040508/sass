package service

import (
	"context"
	"fmt"

	"sass-api/internal/domain"
	"sass-api/internal/dto"
)

// GiaChiNhanhService — khai và gỡ GIÁ BÁN RIÊNG của từng chi nhánh.
//
// Không có hàm "đọc giá đang áp dụng": giá đọc ra ở đường bán hàng và đường
// danh sách mặt hàng, cùng chỗ với tồn kho (xem loadCheckoutVariants và
// giaExprSanPham). Dựng thêm một đường đọc thứ hai ở đây là mở cửa cho hai chỗ
// tính giá khác nhau — đúng cái lỗi mà bảng này sinh ra để tránh.
type GiaChiNhanhService interface {
	// TheoBienThe liệt kê những chi nhánh ĐÃ khai giá riêng cho biến thể này.
	TheoBienThe(ctx context.Context, variantID uint) ([]domain.GiaChiNhanh, error)
	Dat(ctx context.Context, variantID uint, req *dto.GiaChiNhanhRequest) error
	Xoa(ctx context.Context, variantID, shopID uint) error
}

type giaChiNhanhService struct {
	repo domain.GiaChiNhanhRepository
	// chiNhanhRepo để chốt chi nhánh có thật và thuộc cửa hàng này — cùng lý do
	// với phiếu điều chuyển: ô chọn trên màn không phải hàng rào.
	chiNhanhRepo domain.ChiNhanhRepository
}

func NewGiaChiNhanhService(
	repo domain.GiaChiNhanhRepository, chiNhanhRepo domain.ChiNhanhRepository,
) GiaChiNhanhService {
	return &giaChiNhanhService{repo: repo, chiNhanhRepo: chiNhanhRepo}
}

func (s *giaChiNhanhService) TheoBienThe(
	ctx context.Context, variantID uint,
) ([]domain.GiaChiNhanh, error) {
	list, err := s.repo.TheoBienThe(ctx, variantID)
	if err != nil {
		return nil, err
	}

	// Điền tên chi nhánh: màn khai giá bày tên chứ không bày id. Chi nhánh đã
	// đóng vẫn in ra tên — giá cũ của nó còn nằm đó và người dùng cần thấy để gỡ.
	for i := range list {
		if cn, err := s.chiNhanhRepo.FindByID(ctx, list[i].ShopID); err == nil {
			list[i].ShopName = cn.Name
		}
	}

	return list, nil
}

func (s *giaChiNhanhService) Dat(
	ctx context.Context, variantID uint, req *dto.GiaChiNhanhRequest,
) error {
	if _, err := s.chiNhanhRepo.FindByID(ctx, req.ShopID); err != nil {
		return fmt.Errorf("%w: chi nhánh #%d không thuộc cửa hàng này", domain.ErrNotFound, req.ShopID)
	}

	return s.repo.Dat(ctx, req.ShopID, variantID, req.Price)
}

func (s *giaChiNhanhService) Xoa(ctx context.Context, variantID, shopID uint) error {
	return s.repo.Xoa(ctx, shopID, variantID)
}
