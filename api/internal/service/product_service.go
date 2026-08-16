package service

import (
	"context"
	"fmt"
	"time"

	"sass-api/internal/domain"
	"sass-api/internal/dto"
)

// ProductService định nghĩa nghiệp vụ sản phẩm.
type ProductService interface {
	List(ctx context.Context, f domain.ProductFilter) ([]domain.Product, int64, error)
	Get(ctx context.Context, id uint) (*domain.Product, error)
	GetBySlug(ctx context.Context, slug string) (*domain.Product, error)
	Create(ctx context.Context, req dto.ProductRequest) (*domain.Product, error)
	Update(ctx context.Context, id uint, req dto.ProductRequest) (*domain.Product, error)
	SetStatus(ctx context.Context, id uint, status string) (*domain.Product, error)
	Duplicate(ctx context.Context, id uint) (*domain.Product, error)
	Delete(ctx context.Context, id uint) error
	// DeleteMany xoá nhiều sản phẩm trong một giao dịch, trả về số dòng đã xoá.
	DeleteMany(ctx context.Context, ids []uint) (int64, error)
}

type productService struct {
	repo         domain.ProductRepository
	categoryRepo domain.CategoryRepository
	brandRepo    domain.BrandRepository
	// hanMuc xét hạn mức sản phẩm của hợp đồng. nil = máy chủ chưa nối được
	// control plane, khi đó không có hợp đồng nào đọc được nên không ép gì cả.
	hanMuc HanMucService
}

func NewProductService(
	repo domain.ProductRepository,
	categoryRepo domain.CategoryRepository,
	brandRepo domain.BrandRepository,
	hanMuc HanMucService,
) ProductService {
	return &productService{repo: repo, categoryRepo: categoryRepo, brandRepo: brandRepo, hanMuc: hanMuc}
}

func (s *productService) List(ctx context.Context, f domain.ProductFilter) ([]domain.Product, int64, error) {
	if f.Page < 1 {
		f.Page = 1
	}
	if f.PageSize < 1 || f.PageSize > 100 {
		f.PageSize = 20
	}
	// Lọc theo danh mục thì gộp luôn sản phẩm của mọi danh mục con/cháu —
	// cây danh mục có thể 3 cấp (Câu lạc bộ → giải đấu → CLB), khách xem
	// trang "giải đấu" phải thấy áo của tất cả CLB thuộc giải.
	if f.CategoryID != nil {
		if cats, err := s.categoryRepo.List(ctx, false); err == nil {
			f.CategoryIDs = descendantCategoryIDs(*f.CategoryID, cats)
		}
	}
	return s.repo.List(ctx, f)
}

// descendantCategoryIDs trả về id danh mục gốc kèm toàn bộ id con/cháu (BFS,
// không giới hạn độ sâu — phòng khi sau này cây sâu hơn 3 cấp).
func descendantCategoryIDs(rootID uint, cats []domain.Category) []uint {
	childrenOf := make(map[uint][]uint, len(cats))
	for _, c := range cats {
		if c.ParentID != nil {
			childrenOf[*c.ParentID] = append(childrenOf[*c.ParentID], c.ID)
		}
	}
	ids := []uint{rootID}
	for queue := []uint{rootID}; len(queue) > 0; {
		id := queue[0]
		queue = queue[1:]
		for _, child := range childrenOf[id] {
			ids = append(ids, child)
			queue = append(queue, child)
		}
	}
	return ids
}

func (s *productService) Get(ctx context.Context, id uint) (*domain.Product, error) {
	return s.repo.FindByID(ctx, id)
}

func (s *productService) GetBySlug(ctx context.Context, slug string) (*domain.Product, error) {
	p, err := s.repo.FindBySlug(ctx, slug)
	if err != nil {
		return nil, err
	}
	// Tăng lượt xem (bỏ qua lỗi để không ảnh hưởng response).
	_ = s.repo.IncrementView(ctx, p.ID)
	return p, nil
}

func (s *productService) Create(ctx context.Context, req dto.ProductRequest) (*domain.Product, error) {
	if err := s.validateRefs(ctx, req); err != nil {
		return nil, err
	}
	if err := s.checkUnique(ctx, req, 0); err != nil {
		return nil, err
	}
	// Hạn mức xét SAU cùng, ngay trước lượt ghi: người dùng phải biết form của
	// mình sai chỗ nào trước đã. Báo "hết hạn mức" cho một request lẽ ra bị từ
	// chối vì trùng SKU là đẩy họ đi nâng gói để sửa một lỗi gõ nhầm.
	if err := conChoTao(ctx, s.hanMuc, domain.HanMucSanPham); err != nil {
		return nil, err
	}
	p := &domain.Product{}
	applyProductRequest(p, req, true)
	if err := s.repo.Create(ctx, p); err != nil {
		return nil, err
	}
	if req.Variants != nil {
		if err := s.repo.ReplaceVariants(ctx, p.ID, buildVariants(*req.Variants)); err != nil {
			return nil, err
		}
	}
	if req.Images != nil {
		if err := s.repo.ReplaceImages(ctx, p.ID, buildImages(*req.Images)); err != nil {
			return nil, err
		}
	}
	return s.repo.FindByID(ctx, p.ID)
}

func (s *productService) Update(ctx context.Context, id uint, req dto.ProductRequest) (*domain.Product, error) {
	p, err := s.repo.FindByID(ctx, id)
	if err != nil {
		return nil, err
	}
	if err := s.validateRefs(ctx, req); err != nil {
		return nil, err
	}
	if err := s.checkUnique(ctx, req, id); err != nil {
		return nil, err
	}
	applyProductRequest(p, req, false)
	if err := s.repo.Update(ctx, p); err != nil {
		return nil, err
	}
	if req.Variants != nil {
		if err := s.repo.ReplaceVariants(ctx, p.ID, buildVariants(*req.Variants)); err != nil {
			return nil, err
		}
	}
	if req.Images != nil {
		if err := s.repo.ReplaceImages(ctx, p.ID, buildImages(*req.Images)); err != nil {
			return nil, err
		}
	}
	return s.repo.FindByID(ctx, p.ID)
}

// SetStatus đổi trạng thái kinh doanh của sản phẩm — chỉ ghi status + is_active,
// không đụng tới biến thể, ảnh hay giá.
func (s *productService) SetStatus(ctx context.Context, id uint, status string) (*domain.Product, error) {
	if !domain.IsValidProductStatus(status) {
		return nil, domain.ErrProductStatusInvalid
	}
	if err := s.repo.SetStatus(ctx, id, status); err != nil {
		return nil, err
	}
	return s.repo.FindByID(ctx, id)
}

// DeleteMany xoá hàng loạt. Lọc trùng và bỏ id rác ngay tại đây để tầng dưới chỉ
// nhận danh sách sạch.
func (s *productService) DeleteMany(ctx context.Context, ids []uint) (int64, error) {
	seen := make(map[uint]bool, len(ids))
	clean := make([]uint, 0, len(ids))
	for _, id := range ids {
		if id == 0 || seen[id] {
			continue
		}
		seen[id] = true
		clean = append(clean, id)
	}
	if len(clean) == 0 {
		return 0, nil
	}
	return s.repo.DeleteMany(ctx, clean)
}

// checkUnique kiểm tra slug và SKU trước khi ghi.
//
// Cả hai cột đều có UNIQUE index. Không kiểm tra ở đây thì lỗi rơi xuống MySQL,
// nhảy vào nhánh mặc định của handler và người dùng nhận về "Đã có lỗi xảy ra,
// vui lòng thử lại" — không nói được là trùng cái gì, cũng không sửa được.
func (s *productService) checkUnique(ctx context.Context, req dto.ProductRequest, excludeID uint) error {
	exists, err := s.repo.ExistsBySlug(ctx, req.Slug, excludeID)
	if err != nil {
		return err
	}
	if exists {
		return domain.ErrSlugExists
	}

	exists, err = s.repo.ExistsBySKU(ctx, req.SKU, excludeID)
	if err != nil {
		return err
	}
	if exists {
		return domain.ErrSKUExists
	}
	return nil
}

// buildImages chuyển dữ liệu request sang entity ảnh.
func buildImages(reqs []dto.ImageRequest) []domain.ProductImage {
	out := make([]domain.ProductImage, 0, len(reqs))
	for i, img := range reqs {
		sort := img.SortOrder
		if sort == 0 {
			sort = i
		}
		out = append(out, domain.ProductImage{
			ID:        img.ID,
			URL:       img.URL,
			Alt:       img.Alt,
			SortOrder: sort,
			IsPrimary: img.IsPrimary,
		})
	}
	return out
}

// buildVariants chuyển dữ liệu request sang entity biến thể.
//
// StockQuantity cố ý để zero-value: ReplaceVariants bỏ qua cột này nên dòng
// thêm mới lấy DEFAULT 0 của DB, dòng cũ giữ nguyên tồn kho đang có.
func buildVariants(reqs []dto.VariantRequest) []domain.ProductVariant {
	out := make([]domain.ProductVariant, 0, len(reqs))
	for _, v := range reqs {
		out = append(out, domain.ProductVariant{
			ID:         v.ID,
			SKU:        v.SKU,
			Size:       v.Size,
			Color:      v.Color,
			Price:      v.Price,
			CostPrice:  v.CostPrice,
			WeightGram: v.WeightGram,
			Image:      v.Image,
			IsActive:   boolOrDefault(v.IsActive, true),
		})
	}
	return out
}

func (s *productService) Duplicate(ctx context.Context, id uint) (*domain.Product, error) {
	orig, err := s.repo.FindByID(ctx, id)
	if err != nil {
		return nil, err
	}
	// Nhân bản cũng là một sản phẩm mới trong sổ, nên cũng ăn một chỗ của hạn
	// mức. Đây là đường dễ quên nhất: nó không đi qua Create ở trên, và cái nút
	// "Nhân bản" là cách nhanh nhất để thêm hàng loạt.
	if err := conChoTao(ctx, s.hanMuc, domain.HanMucSanPham); err != nil {
		return nil, err
	}

	now := time.Now().Unix()
	newSlug := fmt.Sprintf("%s-copy-%d", orig.Slug, now)
	newSKU := fmt.Sprintf("%s-COPY-%d", orig.SKU, now%10000)
	newName := fmt.Sprintf("%s (Bản sao)", orig.Name)

	newProduct := &domain.Product{
		CategoryID:       orig.CategoryID,
		BrandID:          orig.BrandID,
		Name:             newName,
		Slug:             newSlug,
		SKU:              newSKU,
		ShortDescription: orig.ShortDescription,
		Description:      orig.Description,
		Team:             orig.Team,
		Season:           orig.Season,
		KitType:          orig.KitType,
		BasePrice:        orig.BasePrice,
		SalePrice:        orig.SalePrice,
		CostPrice:        orig.CostPrice,
		Thumbnail:        orig.Thumbnail,
		// Bản sao luôn ở tạm ẩn: nó còn thiếu ảnh riêng, tên riêng, giá riêng —
		// bày ra ngay là khách thấy hai sản phẩm trùng tên nhau.
		Status:          domain.ProductStatusHidden,
		IsActive:        false,
		IsFeatured:      orig.IsFeatured,
		MetaTitle:       orig.MetaTitle,
		MetaDescription: orig.MetaDescription,
	}

	if err := s.repo.Create(ctx, newProduct); err != nil {
		return nil, err
	}

	if len(orig.Variants) > 0 {
		// Bản sao KHÔNG kế thừa tồn kho: hàng không tự nhân đôi trong kho, phải
		// nhập hàng cho sản phẩm mới thì mới có tồn.
		newVariants := make([]domain.ProductVariant, 0, len(orig.Variants))
		for _, v := range orig.Variants {
			newVariants = append(newVariants, domain.ProductVariant{
				SKU:        fmt.Sprintf("%s-COPY-%d", v.SKU, now%10000),
				Size:       v.Size,
				Color:      v.Color,
				Price:      v.Price,
				CostPrice:  v.CostPrice,
				WeightGram: v.WeightGram,
				Image:      v.Image,
				IsActive:   v.IsActive,
			})
		}
		_ = s.repo.ReplaceVariants(ctx, newProduct.ID, newVariants)
	}

	if len(orig.Images) > 0 {
		newImages := make([]domain.ProductImage, 0, len(orig.Images))
		for _, img := range orig.Images {
			newImages = append(newImages, domain.ProductImage{
				URL:       img.URL,
				Alt:       img.Alt,
				SortOrder: img.SortOrder,
				IsPrimary: img.IsPrimary,
			})
		}
		_ = s.repo.ReplaceImages(ctx, newProduct.ID, newImages)
	}

	return s.repo.FindByID(ctx, newProduct.ID)
}

func (s *productService) Delete(ctx context.Context, id uint) error {
	if _, err := s.repo.FindByID(ctx, id); err != nil {
		return err
	}
	return s.repo.Delete(ctx, id)
}

// validateRefs kiểm tra category_id (và brand_id nếu có) tồn tại.
func (s *productService) validateRefs(ctx context.Context, req dto.ProductRequest) error {
	if _, err := s.categoryRepo.FindByID(ctx, req.CategoryID); err != nil {
		return err // ErrNotFound -> handler trả 404
	}
	if req.BrandID != nil {
		if _, err := s.brandRepo.FindByID(ctx, *req.BrandID); err != nil {
			return err
		}
	}
	return nil
}

// applyProductRequest gán dữ liệu từ request vào entity.
// isCreate=true chỉ khi tạo mới (đặt mặc định cho các cờ boolean khi nil).
func applyProductRequest(p *domain.Product, req dto.ProductRequest, isCreate bool) {
	p.CategoryID = req.CategoryID
	p.BrandID = req.BrandID
	p.Name = req.Name
	p.Slug = req.Slug
	p.SKU = req.SKU
	p.ShortDescription = req.ShortDescription
	p.Description = req.Description
	p.Team = req.Team
	p.Season = req.Season
	p.KitType = domain.EnumOrNull(req.KitType)
	p.BasePrice = req.BasePrice
	p.SalePrice = req.SalePrice
	p.CostPrice = req.CostPrice
	p.Thumbnail = req.Thumbnail
	p.MetaTitle = req.MetaTitle
	p.MetaDescription = req.MetaDescription

	if isCreate {
		p.IsFeatured = boolOrDefault(req.IsFeatured, false)
	} else {
		p.IsFeatured = boolOrDefault(req.IsFeatured, p.IsFeatured)
	}

	// Trạng thái là nguồn sự thật; is_active chỉ là bản rút gọn của nó để các
	// truy vấn bán hàng lọc cho nhanh. Luôn suy ra, không bao giờ nhận thẳng từ
	// request — nhận cả hai là có ngày chúng lệch nhau.
	p.Status = resolveProductStatus(req, p, isCreate)
	p.IsActive = p.Status == domain.ProductStatusActive
}

// resolveProductStatus chọn trạng thái cuối cùng cho sản phẩm.
//
// Ưu tiên trường `status` mới. Vẫn đọc `is_active` để tương thích ngược: bản
// quản trị cũ (và các script gọi API sẵn có) chỉ biết gửi cờ bật/tắt.
func resolveProductStatus(req dto.ProductRequest, p *domain.Product, isCreate bool) string {
	if domain.IsValidProductStatus(req.Status) {
		return req.Status
	}

	current := p.Status
	if isCreate || !domain.IsValidProductStatus(current) {
		current = domain.ProductStatusActive
	}

	if req.IsActive == nil {
		return current
	}
	if *req.IsActive {
		return domain.ProductStatusActive
	}
	// Tắt hiển thị mà đang ngừng kinh doanh thì giữ nguyên — người dùng chỉ gửi
	// cờ cũ, không có ý hạ cấp "ngừng kinh doanh" xuống thành "tạm ẩn".
	if current == domain.ProductStatusDiscontinued {
		return current
	}
	return domain.ProductStatusHidden
}
