package service

import (
	"context"
	"strings"

	"sass-api/internal/domain"
	"sass-api/internal/dto"
)

// DefaultLowStock là ngưỡng "sắp hết" mặc định khi client không gửi kèm.
// Trang tổng quan của admin dùng đúng con số này, để hai chỗ không đếm lệch nhau.
const DefaultLowStock = 5

// maxLowStock chặn ngưỡng do client gửi lên ở mức hợp lý — ngưỡng quá lớn biến
// mọi mặt hàng thành "sắp hết" và cảnh báo mất hết ý nghĩa.
const maxLowStock = 1000

// InventoryDetail là một dòng tồn kho kèm sổ kho gần nhất — modal chi tiết dựng
// từ đây thay vì gọi hai lần.
type InventoryDetail struct {
	Item      *domain.InventoryItem     `json:"item"`
	Histories []domain.InventoryHistory `json:"histories"`
	Total     int64                     `json:"total"`
}

type InventoryService interface {
	List(ctx context.Context, f domain.InventoryFilter) ([]domain.InventoryItem, int64, error)
	Stats(ctx context.Context, lowStock int) (domain.InventoryStats, error)
	// Get trả thông tin biến thể kèm 20 bút toán kho gần nhất.
	Get(ctx context.Context, variantID uint) (*InventoryDetail, error)
	Histories(ctx context.Context, variantID uint, page, pageSize int) ([]domain.InventoryHistory, int64, error)

	// Adjust chỉnh tồn kho một biến thể và ghi sổ kho.
	Adjust(ctx context.Context, variantID uint, req *dto.InventoryAdjustRequest, actorID uint) (*domain.InventoryAdjustResult, error)
	// BulkAdjust chỉnh nhiều biến thể trong một lần kiểm kê (tất-cả-hoặc-không).
	BulkAdjust(ctx context.Context, req *dto.InventoryBulkAdjustRequest, actorID uint) ([]domain.InventoryAdjustResult, error)

	// SetCosts khai giá vốn cho nhiều biến thể (tất-cả-hoặc-không). Trả số dòng đổi.
	SetCosts(ctx context.Context, req *dto.InventoryCostRequest) (int64, error)
}

type inventoryService struct {
	repo domain.InventoryRepository
}

func NewInventoryService(repo domain.InventoryRepository) InventoryService {
	return &inventoryService{repo: repo}
}

// NormalizeLowStock đưa ngưỡng sắp hết về khoảng dùng được. Xuất khẩu để handler
// và service dùng chung một luật.
func NormalizeLowStock(v int) int {
	if v <= 0 {
		return DefaultLowStock
	}
	if v > maxLowStock {
		return maxLowStock
	}
	return v
}

func (s *inventoryService) List(ctx context.Context, f domain.InventoryFilter) ([]domain.InventoryItem, int64, error) {
	f.LowStock = NormalizeLowStock(f.LowStock)
	switch f.Stock {
	case "in", "low", "out":
	default:
		f.Stock = "all"
	}
	switch f.Cost {
	case "missing", "set":
	default:
		f.Cost = "all"
	}
	return s.repo.List(ctx, f)
}

// SetCosts khai giá vốn hàng loạt.
//
// Không kiểm tra gì thêm ngoài ràng buộc của DTO: giá vốn cao hơn giá bán là hợp
// lệ (hàng thanh lý bán dưới giá vốn có thật), và null là ý định xoá chứ không
// phải thiếu dữ liệu.
func (s *inventoryService) SetCosts(ctx context.Context, req *dto.InventoryCostRequest) (int64, error) {
	items := make([]domain.VariantCost, 0, len(req.Items))
	for _, it := range req.Items {
		items = append(items, domain.VariantCost{VariantID: it.VariantID, CostPrice: it.CostPrice})
	}
	return s.repo.SetCostPrices(ctx, items)
}

func (s *inventoryService) Stats(ctx context.Context, lowStock int) (domain.InventoryStats, error) {
	return s.repo.Stats(ctx, NormalizeLowStock(lowStock))
}

func (s *inventoryService) Get(ctx context.Context, variantID uint) (*InventoryDetail, error) {
	item, err := s.repo.FindItem(ctx, variantID)
	if err != nil {
		return nil, err
	}
	histories, total, err := s.repo.Histories(ctx, variantID, 1, 20)
	if err != nil {
		return nil, err
	}
	return &InventoryDetail{Item: item, Histories: histories, Total: total}, nil
}

func (s *inventoryService) Histories(ctx context.Context, variantID uint, page, pageSize int) ([]domain.InventoryHistory, int64, error) {
	// Biến thể không tồn tại thì báo 404 thay vì trả sổ rỗng — sổ rỗng nghĩa là
	// "có hàng nhưng chưa từng phát sinh", hai chuyện khác hẳn nhau.
	if _, err := s.repo.FindItem(ctx, variantID); err != nil {
		return nil, 0, err
	}
	return s.repo.Histories(ctx, variantID, page, pageSize)
}

func (s *inventoryService) Adjust(ctx context.Context, variantID uint, req *dto.InventoryAdjustRequest, actorID uint) (*domain.InventoryAdjustResult, error) {
	adj, err := buildAdjustment(variantID, req.Mode, req.Quantity, req.Type, req.UnitCost, req.Note)
	if err != nil {
		return nil, err
	}

	results, err := s.repo.Adjust(ctx, []domain.InventoryAdjustment{adj}, actorID)
	if err != nil {
		return nil, err
	}
	if len(results) == 0 {
		return nil, domain.ErrNotFound
	}
	return &results[0], nil
}

func (s *inventoryService) BulkAdjust(ctx context.Context, req *dto.InventoryBulkAdjustRequest, actorID uint) ([]domain.InventoryAdjustResult, error) {
	items := make([]domain.InventoryAdjustment, 0, len(req.Items))
	for _, it := range req.Items {
		adj, err := buildAdjustment(it.VariantID, it.Mode, it.Quantity, req.Type, req.UnitCost, req.Note)
		if err != nil {
			return nil, err
		}
		items = append(items, adj)
	}
	if len(items) == 0 {
		return nil, domain.ErrStockNoChange
	}
	return s.repo.Adjust(ctx, items, actorID)
}

// buildAdjustment kiểm tra và chuẩn hoá một dòng chỉnh kho.
//
// Loại bút toán được suy từ thao tác khi client không chỉ định, để sổ kho luôn
// đọc được ("nhập"/"xuất"/"kiểm kê") kể cả với dữ liệu do máy sinh ra.
func buildAdjustment(variantID uint, mode string, quantity int, kind string, unitCost *float64, note string) (domain.InventoryAdjustment, error) {
	adj := domain.InventoryAdjustment{
		VariantID: variantID,
		Mode:      mode,
		Quantity:  quantity,
		Note:      strings.TrimSpace(note),
		UnitCost:  unitCost,
	}

	switch mode {
	case domain.StockModeSet:
		if quantity < 0 {
			return adj, domain.ErrStockNegative
		}
	case domain.StockModeDelta:
		if quantity == 0 {
			return adj, domain.ErrStockNoChange
		}
	default:
		return adj, domain.ErrStockNoChange
	}

	adj.Type = kind
	if adj.Type == "" {
		switch {
		case mode == domain.StockModeSet:
			adj.Type = "adjustment"
		case quantity > 0:
			adj.Type = "import"
		default:
			adj.Type = "export"
		}
	}

	// Giá vốn chỉ có ý nghĩa với bút toán nhập; giữ lại ở dòng xuất/kiểm kê sẽ làm
	// mọi phép tính giá vốn về sau đọc nhầm nó là giá nhập.
	if adj.Type != "import" {
		adj.UnitCost = nil
	}
	return adj, nil
}
