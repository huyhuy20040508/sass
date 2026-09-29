package repository

import (
	"context"
	"errors"
	"strings"
	"time"

	"gorm.io/gorm"

	"sass-api/internal/domain"
)

type promotionProgramRepository struct{ db *gorm.DB }

func NewPromotionProgramRepository(db *gorm.DB) domain.PromotionProgramRepository {
	return &promotionProgramRepository{db: db}
}

func (r *promotionProgramRepository) List(ctx context.Context, f domain.PromotionProgramFilter) ([]domain.PromotionProgram, int64, error) {
	q := r.db.WithContext(ctx).Model(&domain.PromotionProgram{})

	// Lọc NGÀY TẠO như v2 (controller:81-86), trọn ngày cuối.
	if t, err := time.ParseInLocation("2006-01-02", strings.TrimSpace(f.FromDate), time.Local); err == nil {
		q = q.Where("created_at >= ?", t)
	}
	if t, err := time.ParseInLocation("2006-01-02", strings.TrimSpace(f.ToDate), time.Local); err == nil {
		q = q.Where("created_at < ?", t.AddDate(0, 0, 1))
	}
	if len(f.ShopIDs) > 0 {
		q = q.Where(`(all_shops = 1 OR EXISTS (SELECT 1 FROM promotion_program_shops s
			WHERE s.program_id = promotion_programs.id AND s.tenant_id = promotion_programs.tenant_id AND s.shop_id IN ?))`, f.ShopIDs)
	}
	if len(f.Codes) > 0 {
		q = q.Where("code IN ?", f.Codes)
	}
	if len(f.Statuses) > 0 {
		q = q.Where("status IN ?", f.Statuses)
	}

	var total int64
	if err := q.Count(&total).Error; err != nil {
		return nil, 0, err
	}
	if f.PageSize > 0 {
		q = q.Limit(f.PageSize).Offset((f.Page - 1) * f.PageSize)
	}

	var items []domain.PromotionProgram
	if err := q.Order("id DESC").Preload("Details.Gifts").Find(&items).Error; err != nil {
		return nil, 0, err
	}

	return items, total, r.napChiNhanh(ctx, items)
}

func (r *promotionProgramRepository) napChiNhanh(ctx context.Context, items []domain.PromotionProgram) error {
	if len(items) == 0 {
		return nil
	}
	ids := make([]uint, 0, len(items))
	for _, it := range items {
		ids = append(ids, it.ID)
	}
	var rows []domain.PromotionProgramShop
	if err := r.db.WithContext(ctx).Where("program_id IN ?", ids).Order("id").Find(&rows).Error; err != nil {
		return err
	}
	theo := map[uint][]uint{}
	for _, s := range rows {
		theo[s.ProgramID] = append(theo[s.ProgramID], s.ShopID)
	}
	for i := range items {
		items[i].ShopIDs = theo[items[i].ID]
		if items[i].ShopIDs == nil {
			items[i].ShopIDs = []uint{}
		}
	}

	return nil
}

func (r *promotionProgramRepository) Codes(ctx context.Context) ([]string, error) {
	var ds []string
	err := r.db.WithContext(ctx).Model(&domain.PromotionProgram{}).Order("id DESC").Pluck("code", &ds).Error

	return ds, err
}

func (r *promotionProgramRepository) FindByID(ctx context.Context, id uint) (*domain.PromotionProgram, error) {
	var p domain.PromotionProgram
	err := r.db.WithContext(ctx).Preload("Details.Gifts").First(&p, id).Error
	if errors.Is(err, gorm.ErrRecordNotFound) {
		return nil, domain.ErrNotFound
	}
	if err != nil {
		return nil, err
	}
	ds := []domain.PromotionProgram{p}
	if err := r.napChiNhanh(ctx, ds); err != nil {
		return nil, err
	}

	return &ds[0], nil
}

// Save tạo mới hoặc thay TRỌN bậc, hàng tặng, chi nhánh — như update của v2
// (xoá hết dòng cũ rồi ghi lại), trong một giao dịch.
func (r *promotionProgramRepository) Save(ctx context.Context, p *domain.PromotionProgram) error {
	return r.db.WithContext(ctx).Transaction(func(tx *gorm.DB) error {
		details := p.Details
		p.Details = nil
		if p.ID == 0 {
			if err := tx.Create(p).Error; err != nil {
				return err
			}
		} else {
			if err := tx.Model(&domain.PromotionProgram{ID: p.ID}).
				Select("name", "description", "type", "status", "approved", "no_time_limit",
					"start_date", "end_date", "days_of_week", "all_shops").
				Updates(p).Error; err != nil {
				return err
			}
			if err := tx.Where("program_id = ?", p.ID).Delete(&domain.PromotionProgramDetail{}).Error; err != nil {
				return err
			}
			if err := tx.Where("program_id = ?", p.ID).Delete(&domain.PromotionProgramShop{}).Error; err != nil {
				return err
			}
		}

		for i := range details {
			details[i].ID = 0
			details[i].ProgramID = p.ID
			gifts := details[i].Gifts
			details[i].Gifts = nil
			if err := tx.Create(&details[i]).Error; err != nil {
				return err
			}
			for j := range gifts {
				gifts[j].ID = 0
				gifts[j].DetailID = details[i].ID
			}
			if len(gifts) > 0 {
				if err := tx.Create(&gifts).Error; err != nil {
					return err
				}
			}
			details[i].Gifts = gifts
		}
		p.Details = details

		if !p.AllShops {
			for _, sid := range p.ShopIDs {
				if err := tx.Create(&domain.PromotionProgramShop{ProgramID: p.ID, ShopID: sid}).Error; err != nil {
					return err
				}
			}
		}

		return nil
	})
}

func (r *promotionProgramRepository) capNhat(ctx context.Context, id uint, cot string, v bool) error {
	res := r.db.WithContext(ctx).Model(&domain.PromotionProgram{}).Where("id = ?", id).Update(cot, v)
	if res.Error != nil {
		return res.Error
	}
	if res.RowsAffected == 0 {
		return conDong(ctx, r.db, &domain.PromotionProgram{}, id)
	}

	return nil
}

func (r *promotionProgramRepository) SetStatus(ctx context.Context, id uint, on bool) error {
	return r.capNhat(ctx, id, "status", on)
}

func (r *promotionProgramRepository) SetApproved(ctx context.Context, id uint, on bool) error {
	return r.capNhat(ctx, id, "approved", on)
}

func (r *promotionProgramRepository) Delete(ctx context.Context, id uint) error {
	res := r.db.WithContext(ctx).Delete(&domain.PromotionProgram{}, id)
	if res.Error != nil {
		return res.Error
	}
	if res.RowsAffected == 0 {
		return domain.ErrNotFound
	}

	return nil
}

func (r *promotionProgramRepository) MaKeTiep(ctx context.Context) (string, error) {
	return maNoiTiep(ctx, r.db, &domain.PromotionProgram{}, "CTKM")
}

func (r *promotionProgramRepository) DangBat(ctx context.Context) ([]domain.PromotionProgram, error) {
	var items []domain.PromotionProgram
	if err := r.db.WithContext(ctx).Where("status = 1").Preload("Details.Gifts").Find(&items).Error; err != nil {
		return nil, err
	}

	return items, r.napChiNhanh(ctx, items)
}

func (r *promotionProgramRepository) TangLuotDung(ctx context.Context, ids []uint) error {
	if len(ids) == 0 {
		return nil
	}

	return r.db.WithContext(ctx).Model(&domain.PromotionProgram{}).Where("id IN ?", ids).
		UpdateColumn("used", gorm.Expr("used + 1")).Error
}

func (r *promotionProgramRepository) ChiNhanhQuay(ctx context.Context) uint {
	return chiNhanhDoc(ctx, r.db)
}
