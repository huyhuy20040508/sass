package service

import (
	"context"
	"strings"

	"sass-api/internal/domain"
)

// GoodsReceiptService — nghiệp vụ "nhập hàng" (đọc lại các đợt hàng đã về kho).
//
// Chỉ ĐỌC: việc nhập hàng thực sự nằm ở PurchaseOrderService.Receive — đó là chỗ
// duy nhất được cộng tồn kho, để không có hai đường ghi kho khác nhau.
type GoodsReceiptService interface {
	List(ctx context.Context, f domain.GoodsReceiptFilter) ([]domain.GoodsReceipt, int64, error)
	Find(ctx context.Context, code string) (*domain.GoodsReceipt, error)
	Stats(ctx context.Context) (domain.GoodsReceiptStats, error)
}

type goodsReceiptService struct {
	repo domain.GoodsReceiptRepository
}

func NewGoodsReceiptService(repo domain.GoodsReceiptRepository) GoodsReceiptService {
	return &goodsReceiptService{repo: repo}
}

// goodsReceiptSorts là các kiểu sắp xếp được phép; giá trị lạ rơi về "newest".
var goodsReceiptSorts = map[string]bool{
	"newest": true, "oldest": true, "qty_desc": true, "amount_desc": true,
}

func (s *goodsReceiptService) List(ctx context.Context, f domain.GoodsReceiptFilter) ([]domain.GoodsReceipt, int64, error) {
	if f.Page < 1 {
		f.Page = 1
	}
	switch {
	case f.PageSize < 1:
		f.PageSize = 20
	case f.PageSize > 100:
		f.PageSize = 100
	}
	if !goodsReceiptSorts[f.Sort] {
		f.Sort = "newest"
	}
	f.Keyword = strings.TrimSpace(f.Keyword)

	// Khoảng ngày ngược đầu (từ > đến) là lỗi gõ tay — đảo lại thay vì trả bảng rỗng.
	if f.FromDate != "" && f.ToDate != "" && f.FromDate > f.ToDate {
		f.FromDate, f.ToDate = f.ToDate, f.FromDate
	}

	return s.repo.List(ctx, f)
}

func (s *goodsReceiptService) Find(ctx context.Context, code string) (*domain.GoodsReceipt, error) {
	if strings.TrimSpace(code) == "" {
		return nil, domain.ErrNotFound
	}
	return s.repo.Find(ctx, code)
}

func (s *goodsReceiptService) Stats(ctx context.Context) (domain.GoodsReceiptStats, error) {
	return s.repo.Stats(ctx)
}
