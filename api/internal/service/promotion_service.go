package service

import (
	"context"
	"strings"
	"time"

	"sass-api/internal/domain"
	"sass-api/internal/dto"
)

// promotionTimeLayout khớp đúng định dạng ô <input type="datetime-local"> của
// trang quản trị, để hai bên không phải đổi qua đổi lại.
const promotionTimeLayout = "2006-01-02T15:04"

// PromotionService — nghiệp vụ chương trình khuyến mãi.
//
// Gồm hai phần tách bạch:
//   - Quản trị: thêm/sửa/xoá/bật tắt các đợt giảm giá.
//   - Tính giá: gắn giá sau giảm vào sản phẩm lúc khách xem hàng, và trừ đúng số
//     đó lúc khách đặt. Hai đường này BẮT BUỘC dùng chung một hàm tính (bestFor)
//     — tính hai nơi là sớm muộn giá hiển thị lệch giá thu tiền.
type PromotionService interface {
	List(ctx context.Context, f domain.PromotionFilter) ([]dto.PromotionResponse, int64, error)
	Stats(ctx context.Context) (domain.PromotionStats, error)
	Get(ctx context.Context, id uint) (*dto.PromotionResponse, error)
	Create(ctx context.Context, req dto.PromotionRequest) (*dto.PromotionResponse, error)
	Update(ctx context.Context, id uint, req dto.PromotionRequest) (*dto.PromotionResponse, error)
	SetActive(ctx context.Context, id uint, active bool) error
	Delete(ctx context.Context, id uint) error

	// DecorateProducts điền FinalPrice / PromotionName cho danh sách sản phẩm sắp
	// trả ra cho khách. Lỗi được nuốt (chỉ là mất phần giảm giá) — chương trình
	// khuyến mãi hỏng không được phép làm sập trang bán hàng.
	DecorateProducts(ctx context.Context, products []domain.Product)
	// DecorateProduct — bản dùng cho trang chi tiết một sản phẩm.
	DecorateProduct(ctx context.Context, p *domain.Product)
	// ApplyToCheckout trừ khuyến mãi vào giá của các dòng khách sắp đặt. Chạy bên
	// trong giao dịch đặt hàng nên giá thu tiền luôn là giá tại đúng thời điểm bấm
	// đặt, không phải giá trình duyệt gửi lên.
	ApplyToCheckout(ctx context.Context, resolved map[uint]domain.CheckoutVariant)
}

type promotionService struct {
	repo         domain.PromotionRepository
	categoryRepo domain.CategoryRepository
}

func NewPromotionService(repo domain.PromotionRepository, categoryRepo domain.CategoryRepository) PromotionService {
	return &promotionService{repo: repo, categoryRepo: categoryRepo}
}

// ---------- Quản trị ----------

func (s *promotionService) List(ctx context.Context, f domain.PromotionFilter) ([]dto.PromotionResponse, int64, error) {
	if f.Page < 1 {
		f.Page = 1
	}
	if f.PageSize < 1 || f.PageSize > 100 {
		f.PageSize = 20
	}

	items, total, err := s.repo.List(ctx, f)
	if err != nil {
		return nil, 0, err
	}

	out := make([]dto.PromotionResponse, 0, len(items))
	for i := range items {
		out = append(out, s.toResponse(ctx, &items[i]))
	}
	return out, total, nil
}

func (s *promotionService) Stats(ctx context.Context) (domain.PromotionStats, error) {
	return s.repo.Stats(ctx)
}

func (s *promotionService) Get(ctx context.Context, id uint) (*dto.PromotionResponse, error) {
	p, err := s.repo.FindByID(ctx, id)
	if err != nil {
		return nil, err
	}
	res := s.toResponse(ctx, p)
	return &res, nil
}

func (s *promotionService) Create(ctx context.Context, req dto.PromotionRequest) (*dto.PromotionResponse, error) {
	p, err := buildPromotion(&domain.Promotion{}, req)
	if err != nil {
		return nil, err
	}
	if err := s.repo.Create(ctx, p); err != nil {
		return nil, err
	}
	res := s.toResponse(ctx, p)
	return &res, nil
}

func (s *promotionService) Update(ctx context.Context, id uint, req dto.PromotionRequest) (*dto.PromotionResponse, error) {
	existing, err := s.repo.FindByID(ctx, id)
	if err != nil {
		return nil, err
	}
	p, err := buildPromotion(existing, req)
	if err != nil {
		return nil, err
	}
	if err := s.repo.Update(ctx, p); err != nil {
		return nil, err
	}
	res := s.toResponse(ctx, p)
	return &res, nil
}

func (s *promotionService) SetActive(ctx context.Context, id uint, active bool) error {
	return s.repo.SetActive(ctx, id, active)
}

func (s *promotionService) Delete(ctx context.Context, id uint) error {
	return s.repo.Delete(ctx, id)
}

// buildPromotion đổ dữ liệu yêu cầu vào entity và kiểm tra những ràng buộc mà thẻ
// binding không diễn đạt nổi (chúng phụ thuộc lẫn nhau).
func buildPromotion(p *domain.Promotion, req dto.PromotionRequest) (*domain.Promotion, error) {
	start, err := time.ParseInLocation(promotionTimeLayout, req.StartAt, time.Local)
	if err != nil {
		return nil, domain.ErrPromotionTimeRange
	}
	end, err := time.ParseInLocation(promotionTimeLayout, req.EndAt, time.Local)
	if err != nil {
		return nil, domain.ErrPromotionTimeRange
	}
	// Kết thúc trước khi bắt đầu = chương trình không bao giờ chạy, nhưng nhìn danh
	// sách vẫn thấy nó nằm đó như thật.
	if !end.After(start) {
		return nil, domain.ErrPromotionTimeRange
	}

	if req.DiscountType == domain.DiscountPercentage && req.DiscountValue > 100 {
		return nil, domain.ErrPromotionPercentRange
	}

	targets := buildTargets(req)
	if len(targets) == 0 {
		return nil, domain.ErrPromotionNoScope
	}

	p.Name = strings.TrimSpace(req.Name)
	p.Description = strings.TrimSpace(req.Description)
	p.DiscountType = req.DiscountType
	p.DiscountValue = req.DiscountValue
	// Trần giảm chỉ có nghĩa khi giảm theo %. Giữ lại ở kiểu "giảm số tiền" thì nó
	// nằm im trong database rồi một ngày nào đó có người tưởng nó đang có tác dụng.
	if req.DiscountType == domain.DiscountPercentage {
		p.MaxDiscountAmount = req.MaxDiscountAmount
	} else {
		p.MaxDiscountAmount = nil
	}
	p.StartAt = start
	p.EndAt = end
	p.IsActive = boolOrDefault(req.IsActive, true)
	p.Targets = targets
	return p, nil
}

// buildTargets gộp ba danh sách id thành các dòng phạm vi, bỏ id 0 và id trùng.
func buildTargets(req dto.PromotionRequest) []domain.PromotionTarget {
	var targets []domain.PromotionTarget
	add := func(kind string, ids []uint) {
		seen := make(map[uint]bool, len(ids))
		for _, id := range ids {
			if id == 0 || seen[id] {
				continue
			}
			seen[id] = true
			targets = append(targets, domain.PromotionTarget{TargetType: kind, TargetID: id})
		}
	}
	add(domain.PromotionTargetProduct, req.ProductIDs)
	add(domain.PromotionTargetCategory, req.CategoryIDs)
	return targets
}

func (s *promotionService) toResponse(ctx context.Context, p *domain.Promotion) dto.PromotionResponse {
	res := dto.PromotionResponse{
		ID:                p.ID,
		Name:              p.Name,
		Description:       p.Description,
		DiscountType:      p.DiscountType,
		DiscountValue:     p.DiscountValue,
		MaxDiscountAmount: p.MaxDiscountAmount,
		StartAt:           p.StartAt.Format(promotionTimeLayout),
		EndAt:             p.EndAt.Format(promotionTimeLayout),
		IsActive:          p.IsActive,
		Status:            promotionStatus(p, time.Now()),
		ProductIDs:        []uint{},
		CategoryIDs:       []uint{},
		CreatedAt:         p.CreatedAt.Format(time.RFC3339),
	}

	for _, t := range p.Targets {
		switch t.TargetType {
		case domain.PromotionTargetProduct:
			res.ProductIDs = append(res.ProductIDs, t.TargetID)
		case domain.PromotionTargetCategory:
			res.CategoryIDs = append(res.CategoryIDs, t.TargetID)
		}
	}

	// Đếm sản phẩm bị phủ: danh mục cha kéo theo cả cây con, đúng như lúc tính giá.
	cats := res.CategoryIDs
	if len(cats) > 0 {
		if all, err := s.categoryRepo.List(ctx, false); err == nil {
			expanded := make([]uint, 0, len(cats))
			for _, id := range cats {
				expanded = append(expanded, descendantCategoryIDs(id, all)...)
			}
			cats = expanded
		}
	}
	if n, err := s.repo.CountProducts(ctx, res.ProductIDs, cats); err == nil {
		res.ProductCount = n
	}

	return res
}

// promotionStatus quy trạng thái về đúng bốn nhóm mà người bán hỏi. Tính ở MỘT chỗ
// để trang quản trị không tự suy ra một kiểu khác.
func promotionStatus(p *domain.Promotion, now time.Time) string {
	switch {
	case now.After(p.EndAt):
		return "ended"
	case !p.IsActive:
		return "paused"
	case now.Before(p.StartAt):
		return "scheduled"
	default:
		return "running"
	}
}

// ---------- Tính giá ----------

// promoMatcher là bảng tra đã dựng sẵn cho MỘT lần tính giá: tra chương trình theo
// sản phẩm / danh mục / thương hiệu mà không phải quét lại danh sách mỗi lần.
type promoMatcher struct {
	promos     []domain.Promotion
	byProduct  map[uint][]int
	byCategory map[uint][]int
	// parentOf để leo ngược cây danh mục: chương trình khai ở danh mục cha phải phủ
	// tới sản phẩm nằm trong danh mục cháu.
	parentOf map[uint]uint
}

// matcher dựng bảng tra từ các chương trình đang chạy. Trả về nil khi không có
// chương trình nào — phía gọi khỏi phải làm gì thêm.
func (s *promotionService) matcher(ctx context.Context) *promoMatcher {
	promos, err := s.repo.Running(ctx, time.Now())
	if err != nil || len(promos) == 0 {
		return nil
	}

	m := &promoMatcher{
		promos:     promos,
		byProduct:  map[uint][]int{},
		byCategory: map[uint][]int{},
	}
	needCats := false
	for i, p := range promos {
		for _, t := range p.Targets {
			switch t.TargetType {
			case domain.PromotionTargetProduct:
				m.byProduct[t.TargetID] = append(m.byProduct[t.TargetID], i)
			case domain.PromotionTargetCategory:
				m.byCategory[t.TargetID] = append(m.byCategory[t.TargetID], i)
				needCats = true
			}
		}
	}

	// Chỉ đọc bảng danh mục khi thật sự có chương trình khai theo danh mục.
	if needCats {
		if cats, err := s.categoryRepo.List(ctx, false); err == nil {
			m.parentOf = make(map[uint]uint, len(cats))
			for _, c := range cats {
				if c.ParentID != nil {
					m.parentOf[c.ID] = *c.ParentID
				}
			}
		}
	}
	return m
}

// bestFor trả về số tiền giảm nhiều nhất áp được cho một sản phẩm, kèm tên chương
// trình đó.
//
// Nhiều chương trình cùng phủ một sản phẩm thì KHÔNG cộng dồn — chọn đúng một cái
// có lợi nhất cho khách. Cộng dồn nghe thì hào phóng nhưng hai đợt 50% chồng nhau
// là bán không đồng, và không ai kịp nhận ra trước khi đơn về.
func (m *promoMatcher) bestFor(price float64, productID, categoryID uint) (float64, string) {
	if m == nil || price <= 0 {
		return 0, ""
	}

	seen := make(map[int]bool, 4)
	var best float64
	var bestName string

	try := func(idx int) {
		if seen[idx] {
			return
		}
		seen[idx] = true
		if d := m.promos[idx].Discount(price); d > best {
			best, bestName = d, m.promos[idx].Name
		}
	}

	for _, i := range m.byProduct[productID] {
		try(i)
	}
	// Leo từ danh mục của sản phẩm lên tới gốc. Vòng lặp có chặn số bước phòng dữ
	// liệu danh mục bị trỏ vòng (A là cha của B, B là cha của A) — không chặn thì
	// một dòng dữ liệu hỏng đủ treo cả trang bán hàng.
	for cid, step := categoryID, 0; cid != 0 && step < 20; step++ {
		for _, i := range m.byCategory[cid] {
			try(i)
		}
		cid = m.parentOf[cid]
	}
	return best, bestName
}

func (s *promotionService) DecorateProducts(ctx context.Context, products []domain.Product) {
	if len(products) == 0 {
		return
	}
	m := s.matcher(ctx)
	if m == nil {
		return
	}

	for i := range products {
		m.decorate(&products[i])
	}
}

func (s *promotionService) DecorateProduct(ctx context.Context, p *domain.Product) {
	if p == nil {
		return
	}
	if m := s.matcher(ctx); m != nil {
		m.decorate(p)
	}
}

func (m *promoMatcher) decorate(p *domain.Product) {
	// Giá nền để giảm là giá khách ĐANG thấy: sale_price nếu nó thực sự rẻ hơn,
	// không thì giá gốc. Giảm trên giá gốc rồi đem so với sale_price là có ngày
	// "khuyến mãi" đắt hơn giá thường.
	base := p.BasePrice
	if p.SalePrice != nil && *p.SalePrice > 0 && *p.SalePrice < base {
		base = *p.SalePrice
	}
	if d, name := m.bestFor(base, p.ID, p.CategoryID); d > 0 {
		final := base - d
		p.FinalPrice = &final
		p.PromotionName = name
	}

	// Biến thể khai giá riêng thì giá thu tiền là giá của NÓ, không phải giá sản
	// phẩm (xem loadCheckoutVariants). Tính sẵn giá sau khuyến mãi cho từng biến
	// thể theo đúng công thức đó, để trang chi tiết in ra đúng con số khách sẽ
	// trả khi đổi size — trước đây trang chỉ có một giá chung, chọn size có giá
	// riêng là khách nhìn một đằng trả một nẻo.
	for i := range p.Variants {
		vb := base
		if v := p.Variants[i].Price; v != nil && *v > 0 {
			vb = *v
		}
		final := vb
		if d, _ := m.bestFor(vb, p.ID, p.CategoryID); d > 0 {
			final = vb - d
		}
		p.Variants[i].FinalPrice = &final
	}
}

func (s *promotionService) ApplyToCheckout(ctx context.Context, resolved map[uint]domain.CheckoutVariant) {
	if len(resolved) == 0 {
		return
	}
	m := s.matcher(ctx)
	if m == nil {
		return
	}

	for id, cv := range resolved {
		d, name := m.bestFor(cv.Price, cv.ProductID, cv.CategoryID)
		if d <= 0 {
			continue
		}
		cv.Price -= d
		cv.PromotionName = name
		resolved[id] = cv
	}
}
