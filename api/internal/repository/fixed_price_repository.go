package repository

import (
	"context"
	"errors"
	"strings"
	"time"

	"gorm.io/gorm"

	"sass-api/internal/domain"
)

type fixedPriceRepository struct{ db *gorm.DB }

func NewFixedPriceRepository(db *gorm.DB) domain.FixedPriceRepository {
	return &fixedPriceRepository{db: db}
}

func (r *fixedPriceRepository) List(ctx context.Context, f domain.FixedPriceFilter) ([]domain.FixedPrice, int64, error) {
	q := r.db.WithContext(ctx).Model(&domain.FixedPrice{})

	if kw := strings.TrimSpace(f.Keyword); kw != "" {
		like := "%" + kw + "%"
		q = q.Where("(code LIKE ? OR name LIKE ?)", like, like)
	}
	// Khoảng ngày = "có hiệu lực ngày nào trong khoảng này không". Chương trình
	// không giới hạn thời gian thì luôn khớp — v2 cũng để nó lọt qua mọi khoảng.
	if t, err := time.ParseInLocation("2006-01-02", strings.TrimSpace(f.FromDate), time.Local); err == nil {
		q = q.Where("(no_time_limit = 1 OR end_date >= ?)", t.Format("2006-01-02"))
	}
	if t, err := time.ParseInLocation("2006-01-02", strings.TrimSpace(f.ToDate), time.Local); err == nil {
		q = q.Where("(no_time_limit = 1 OR start_date <= ?)", t.Format("2006-01-02"))
	}
	if len(f.Codes) > 0 {
		q = q.Where("code IN ?", f.Codes)
	}
	if len(f.ShopIDs) > 0 {
		q = q.Where(`(all_shops = 1 OR EXISTS (SELECT 1 FROM fixed_price_shops s
			WHERE s.fixed_price_id = fixed_prices.id AND s.tenant_id = fixed_prices.tenant_id AND s.shop_id IN ?))`, f.ShopIDs)
	}

	var total int64
	if err := q.Count(&total).Error; err != nil {
		return nil, 0, err
	}
	if f.PageSize > 0 {
		q = q.Limit(f.PageSize).Offset((f.Page - 1) * f.PageSize)
	}

	var items []domain.FixedPrice
	if err := q.Order("id DESC").Preload("Details.Gifts").Find(&items).Error; err != nil {
		return nil, 0, err
	}

	return items, total, r.napChiNhanh(ctx, items)
}

func (r *fixedPriceRepository) Codes(ctx context.Context) ([]string, error) {
	var ds []string
	err := r.db.WithContext(ctx).Model(&domain.FixedPrice{}).Order("id DESC").Pluck("code", &ds).Error

	return ds, err
}

// napChiNhanh điền ShopIDs cho cả danh sách bằng MỘT lượt đọc bảng nối.
func (r *fixedPriceRepository) napChiNhanh(ctx context.Context, items []domain.FixedPrice) error {
	if len(items) == 0 {
		return nil
	}
	ids := make([]uint, 0, len(items))
	for _, it := range items {
		ids = append(ids, it.ID)
	}
	var rows []domain.FixedPriceShop
	if err := r.db.WithContext(ctx).Where("fixed_price_id IN ?", ids).Order("id").Find(&rows).Error; err != nil {
		return err
	}
	theo := map[uint][]uint{}
	for _, s := range rows {
		theo[s.FixedPriceID] = append(theo[s.FixedPriceID], s.ShopID)
	}
	for i := range items {
		items[i].ShopIDs = theo[items[i].ID]
		if items[i].ShopIDs == nil {
			items[i].ShopIDs = []uint{}
		}
	}

	return nil
}

func (r *fixedPriceRepository) FindByID(ctx context.Context, id uint) (*domain.FixedPrice, error) {
	var f domain.FixedPrice
	err := r.db.WithContext(ctx).Preload("Details.Gifts").First(&f, id).Error
	if errors.Is(err, gorm.ErrRecordNotFound) {
		return nil, domain.ErrNotFound
	}
	if err != nil {
		return nil, err
	}
	ds := []domain.FixedPrice{f}
	if err := r.napChiNhanh(ctx, ds); err != nil {
		return nil, err
	}

	return &ds[0], nil
}

func (r *fixedPriceRepository) Save(ctx context.Context, f *domain.FixedPrice) error {
	return r.db.WithContext(ctx).Transaction(func(tx *gorm.DB) error {
		details := f.Details
		f.Details = nil
		if f.ID == 0 {
			if err := tx.Create(f).Error; err != nil {
				return err
			}
		} else {
			if err := tx.Model(&domain.FixedPrice{ID: f.ID}).
				Select("name", "description", "type", "status", "approved", "no_time_limit",
					"start_date", "end_date", "days_of_week", "all_shops").
				Updates(f).Error; err != nil {
				return err
			}
			// Thay trọn dòng và hàng tặng: khoá ngoại ON DELETE CASCADE dọn hàng
			// tặng theo dòng.
			if err := tx.Where("fixed_price_id = ?", f.ID).Delete(&domain.FixedPriceDetail{}).Error; err != nil {
				return err
			}
			if err := tx.Where("fixed_price_id = ?", f.ID).Delete(&domain.FixedPriceShop{}).Error; err != nil {
				return err
			}
		}

		for i := range details {
			details[i].ID = 0
			details[i].FixedPriceID = f.ID
			gifts := details[i].Gifts
			details[i].Gifts = nil
			if err := tx.Create(&details[i]).Error; err != nil {
				return err
			}
			for j := range gifts {
				gifts[j].ID = 0
				gifts[j].FixedPriceDetailID = details[i].ID
			}
			if len(gifts) > 0 {
				if err := tx.Create(&gifts).Error; err != nil {
					return err
				}
			}
			details[i].Gifts = gifts
		}
		f.Details = details

		if !f.AllShops {
			for _, sid := range f.ShopIDs {
				if err := tx.Create(&domain.FixedPriceShop{FixedPriceID: f.ID, ShopID: sid}).Error; err != nil {
					return err
				}
			}
		}

		return nil
	})
}

func (r *fixedPriceRepository) capNhat(ctx context.Context, id uint, cot string, v bool) error {
	res := r.db.WithContext(ctx).Model(&domain.FixedPrice{}).Where("id = ?", id).Update(cot, v)
	if res.Error != nil {
		return res.Error
	}
	if res.RowsAffected == 0 {
		return conDong(ctx, r.db, &domain.FixedPrice{}, id)
	}

	return nil
}

func (r *fixedPriceRepository) SetStatus(ctx context.Context, id uint, on bool) error {
	return r.capNhat(ctx, id, "status", on)
}

func (r *fixedPriceRepository) SetApproved(ctx context.Context, id uint, on bool) error {
	return r.capNhat(ctx, id, "approved", on)
}

func (r *fixedPriceRepository) Delete(ctx context.Context, id uint) error {
	res := r.db.WithContext(ctx).Delete(&domain.FixedPrice{}, id)
	if res.Error != nil {
		return res.Error
	}
	if res.RowsAffected == 0 {
		return domain.ErrNotFound
	}

	return nil
}

func (r *fixedPriceRepository) DangBat(ctx context.Context) ([]domain.FixedPrice, error) {
	var items []domain.FixedPrice
	if err := r.db.WithContext(ctx).Where("status = 1").Preload("Details.Gifts").Find(&items).Error; err != nil {
		return nil, err
	}

	return items, r.napChiNhanh(ctx, items)
}

func (r *fixedPriceRepository) TenSanPham(ctx context.Context, ids []uint) (map[uint]string, error) {
	out := map[uint]string{}
	if len(ids) == 0 {
		return out, nil
	}
	var rows []domain.Product
	if err := r.db.WithContext(ctx).Unscoped().Select("id", "name").Where("id IN ?", ids).Find(&rows).Error; err != nil {
		return nil, err
	}
	for _, p := range rows {
		out[p.ID] = p.Name
	}

	return out, nil
}

func (r *fixedPriceRepository) TenBienThe(ctx context.Context, ids []uint) (map[uint]string, error) {
	out := map[uint]string{}
	if len(ids) == 0 {
		return out, nil
	}
	var rows []struct {
		ID      uint
		Ten     string
		BienThe string
	}
	err := r.db.WithContext(ctx).Model(&domain.ProductVariant{}).
		Joins("JOIN products p ON p.id = product_variants.product_id AND p.tenant_id = product_variants.tenant_id").
		Select("product_variants.id AS id, p.name AS ten, product_variants.name AS bien_the").
		Where("product_variants.id IN ?", ids).Scan(&rows).Error
	if err != nil {
		return nil, err
	}
	for _, v := range rows {
		ten := v.Ten
		if strings.TrimSpace(v.BienThe) != "" {
			ten += " (" + v.BienThe + ")"
		}
		out[v.ID] = ten
	}

	return out, nil
}

func (r *fixedPriceRepository) ChiNhanhQuay(ctx context.Context) uint {
	return chiNhanhDoc(ctx, r.db)
}

func (r *fixedPriceRepository) DanhMucSanPham(ctx context.Context, ids []uint) (map[uint]uint, error) {
	out := map[uint]uint{}
	var rows []domain.Product
	if err := r.db.WithContext(ctx).Unscoped().Select("id", "category_id").Where("id IN ?", ids).Find(&rows).Error; err != nil {
		return nil, err
	}
	for _, p := range rows {
		if p.CategoryID > 0 {
			out[p.ID] = p.CategoryID
		}
	}

	return out, nil
}

func (r *fixedPriceRepository) MaKeTiep(ctx context.Context) (string, error) {
	return maNoiTiep(ctx, r.db, &domain.FixedPrice{}, "DG")
}
