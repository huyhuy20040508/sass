package repository

import (
	"context"
	"errors"
	"strings"
	"time"

	"gorm.io/gorm"

	"sass-api/internal/domain"
)

type voucherProgramRepository struct{ db *gorm.DB }

func NewVoucherProgramRepository(db *gorm.DB) domain.VoucherProgramRepository {
	return &voucherProgramRepository{db: db}
}

func (r *voucherProgramRepository) List(ctx context.Context, f domain.VoucherProgramFilter) ([]domain.VoucherProgram, int64, error) {
	q := r.db.WithContext(ctx).Model(&domain.VoucherProgram{})
	if len(f.Statuses) > 0 {
		q = q.Where("status IN ?", f.Statuses)
	}
	if len(f.ShopIDs) > 0 {
		q = q.Where(`(all_shops = 1 OR EXISTS (SELECT 1 FROM voucher_program_shops s
			WHERE s.program_id = voucher_programs.id AND s.tenant_id = voucher_programs.tenant_id AND s.shop_id IN ?))`, f.ShopIDs)
	}

	var total int64
	if err := q.Count(&total).Error; err != nil {
		return nil, 0, err
	}
	if f.PageSize > 0 {
		q = q.Limit(f.PageSize).Offset((f.Page - 1) * f.PageSize)
	}

	var items []domain.VoucherProgram
	if err := q.Order("id DESC").Find(&items).Error; err != nil {
		return nil, 0, err
	}

	return items, total, r.napThem(ctx, items)
}

// napThem điền chi nhánh, danh mục và tên người tạo cho cả trang bằng ba lượt đọc.
func (r *voucherProgramRepository) napThem(ctx context.Context, items []domain.VoucherProgram) error {
	if len(items) == 0 {
		return nil
	}
	ids := make([]uint, 0, len(items))
	nguoi := []uint{}
	for _, it := range items {
		ids = append(ids, it.ID)
		if it.CreatedBy != nil {
			nguoi = append(nguoi, *it.CreatedBy)
		}
	}

	var shops []domain.VoucherProgramShop
	if err := r.db.WithContext(ctx).Where("program_id IN ?", ids).Order("id").Find(&shops).Error; err != nil {
		return err
	}
	var cats []domain.VoucherProgramCategory
	if err := r.db.WithContext(ctx).Where("program_id IN ?", ids).Order("id").Find(&cats).Error; err != nil {
		return err
	}
	ten, err := tenNguoiDung(ctx, r.db, nguoi)
	if err != nil {
		return err
	}

	theoShop, theoCat := map[uint][]uint{}, map[uint][]uint{}
	for _, s := range shops {
		theoShop[s.ProgramID] = append(theoShop[s.ProgramID], s.ShopID)
	}
	for _, c := range cats {
		theoCat[c.ProgramID] = append(theoCat[c.ProgramID], c.CategoryID)
	}
	for i := range items {
		items[i].ShopIDs = append([]uint{}, theoShop[items[i].ID]...)
		items[i].CategoryIDs = append([]uint{}, theoCat[items[i].ID]...)
		if items[i].CreatedBy != nil {
			items[i].CreatedByName = ten[*items[i].CreatedBy]
		}
	}

	return nil
}

// tenNguoiDung tra họ tên một nhóm người dùng (kể cả người đã nghỉ).
func tenNguoiDung(ctx context.Context, db *gorm.DB, ids []uint) (map[uint]string, error) {
	out := map[uint]string{}
	if len(ids) == 0 {
		return out, nil
	}
	var rows []struct {
		ID       uint
		FullName string
	}
	if err := db.WithContext(ctx).Unscoped().Model(&domain.User{}).Select("id", "full_name").Where("id IN ?", ids).Find(&rows).Error; err != nil {
		return nil, err
	}
	for _, u := range rows {
		out[u.ID] = u.FullName
	}

	return out, nil
}

func (r *voucherProgramRepository) FindByID(ctx context.Context, id uint) (*domain.VoucherProgram, error) {
	var p domain.VoucherProgram
	err := r.db.WithContext(ctx).First(&p, id).Error
	if errors.Is(err, gorm.ErrRecordNotFound) {
		return nil, domain.ErrNotFound
	}
	if err != nil {
		return nil, err
	}
	ds := []domain.VoucherProgram{p}
	if err := r.napThem(ctx, ds); err != nil {
		return nil, err
	}

	return &ds[0], nil
}

func (r *voucherProgramRepository) Save(ctx context.Context, p *domain.VoucherProgram) error {
	return r.db.WithContext(ctx).Transaction(func(tx *gorm.DB) error {
		if p.ID == 0 {
			if err := tx.Create(p).Error; err != nil {
				return err
			}
		} else {
			if err := tx.Model(&domain.VoucherProgram{ID: p.ID}).
				Select("name", "description", "discount_type", "discount_value", "max_discount_amount", "min_order_amount",
					"all_shops", "all_categories", "no_time_limit", "start_date", "end_date",
					"prefix", "suffix", "quantity", "usage_limit").
				Updates(p).Error; err != nil {
				return err
			}
			if err := tx.Where("program_id = ?", p.ID).Delete(&domain.VoucherProgramShop{}).Error; err != nil {
				return err
			}
			if err := tx.Where("program_id = ?", p.ID).Delete(&domain.VoucherProgramCategory{}).Error; err != nil {
				return err
			}
		}

		if !p.AllShops && len(p.ShopIDs) > 0 {
			rows := make([]domain.VoucherProgramShop, 0, len(p.ShopIDs))
			for _, id := range p.ShopIDs {
				rows = append(rows, domain.VoucherProgramShop{ProgramID: p.ID, ShopID: id})
			}
			if err := tx.Create(&rows).Error; err != nil {
				return err
			}
		}
		if !p.AllCategories && len(p.CategoryIDs) > 0 {
			rows := make([]domain.VoucherProgramCategory, 0, len(p.CategoryIDs))
			for _, id := range p.CategoryIDs {
				rows = append(rows, domain.VoucherProgramCategory{ProgramID: p.ID, CategoryID: id})
			}
			if err := tx.Create(&rows).Error; err != nil {
				return err
			}
		}

		return nil
	})
}

func (r *voucherProgramRepository) Delete(ctx context.Context, id uint) error {
	res := r.db.WithContext(ctx).Delete(&domain.VoucherProgram{}, id)
	if res.Error != nil {
		return res.Error
	}
	if res.RowsAffected == 0 {
		return domain.ErrNotFound
	}

	return nil
}

func (r *voucherProgramRepository) MaKeTiep(ctx context.Context) (string, error) {
	return maNoiTiep(ctx, r.db, &domain.VoucherProgram{}, "VC")
}

func (r *voucherProgramRepository) MaDaCo(ctx context.Context, codes []string) (map[string]bool, error) {
	out := map[string]bool{}
	if len(codes) == 0 {
		return out, nil
	}
	var ds []string
	// Unscoped: mã đã xoá mềm vẫn giữ chỗ trong khoá duy nhất (tenant_id, code).
	if err := r.db.WithContext(ctx).Unscoped().Model(&domain.Voucher{}).Where("code IN ?", codes).Pluck("code", &ds).Error; err != nil {
		return nil, err
	}
	for _, c := range ds {
		out[strings.ToUpper(c)] = true
	}

	return out, nil
}

func (r *voucherProgramRepository) PhatHanh(ctx context.Context, p *domain.VoucherProgram, vouchers []domain.Voucher) error {
	return r.db.WithContext(ctx).Transaction(func(tx *gorm.DB) error {
		// Chỉ chuyển từ Chưa phát hành: hai lượt bấm Phát hành cùng lúc thì lượt
		// sau không sinh thêm một bộ mã thứ hai.
		now := time.Now()
		res := tx.Model(&domain.VoucherProgram{}).
			Where("id = ? AND status = ?", p.ID, domain.VoucherProgramChuaPhatHanh).
			Updates(map[string]any{"status": domain.VoucherProgramPhatHanh, "released_at": now})
		if res.Error != nil {
			return res.Error
		}
		if res.RowsAffected == 0 {
			return domain.ErrVCDaPhatHanh
		}
		p.Status, p.ReleasedAt = domain.VoucherProgramPhatHanh, &now

		if len(vouchers) == 0 {
			return nil
		}
		if err := tx.CreateInBatches(&vouchers, 100).Error; err != nil {
			return err
		}
		if p.AllShops || len(p.ShopIDs) == 0 {
			return nil
		}
		gan := make([]domain.VoucherShop, 0, len(vouchers)*len(p.ShopIDs))
		for _, v := range vouchers {
			for _, s := range p.ShopIDs {
				gan = append(gan, domain.VoucherShop{VoucherID: v.ID, ShopID: s})
			}
		}

		return tx.CreateInBatches(&gan, 200).Error
	})
}

func (r *voucherProgramRepository) Codes(ctx context.Context, f domain.VoucherCodeFilter) ([]domain.Voucher, int64, error) {
	q := r.db.WithContext(ctx).Model(&domain.Voucher{}).Where("program_id = ?", f.ProgramID)
	if kw := strings.TrimSpace(f.Keyword); kw != "" {
		q = q.Where("code LIKE ?", "%"+kw+"%")
	}

	var total int64
	if err := q.Count(&total).Error; err != nil {
		return nil, 0, err
	}
	if f.PageSize > 0 {
		q = q.Limit(f.PageSize).Offset((f.Page - 1) * f.PageSize)
	}
	var items []domain.Voucher
	err := q.Order("id").Find(&items).Error

	return items, total, err
}

func (r *voucherProgramRepository) SetCodeActive(ctx context.Context, voucherID uint, on bool) error {
	res := r.db.WithContext(ctx).Model(&domain.Voucher{}).
		Where("id = ? AND program_id IS NOT NULL", voucherID).Update("is_active", on)
	if res.Error != nil {
		return res.Error
	}
	if res.RowsAffected == 0 {
		return conDong(ctx, r.db, &domain.Voucher{}, voucherID)
	}

	return nil
}

func (r *voucherProgramRepository) CodeUses(ctx context.Context, voucherID uint) ([]domain.VoucherCodeUse, error) {
	var uses []domain.VoucherUsage
	if err := r.db.WithContext(ctx).Where("voucher_id = ?", voucherID).Order("id").Find(&uses).Error; err != nil {
		return nil, err
	}
	out := []domain.VoucherCodeUse{}
	if len(uses) == 0 {
		return out, nil
	}

	donIDs := make([]uint, 0, len(uses))
	for _, u := range uses {
		donIDs = append(donIDs, u.OrderID)
	}
	var dons []domain.Order
	if err := r.db.WithContext(ctx).Unscoped().Select("id", "order_code", "shop_id", "created_by", "created_at").
		Where("id IN ?", donIDs).Find(&dons).Error; err != nil {
		return nil, err
	}
	theoDon := map[uint]domain.Order{}
	nguoi, kho := []uint{}, []uint{}
	for _, d := range dons {
		theoDon[d.ID] = d
		if d.CreatedBy != nil {
			nguoi = append(nguoi, *d.CreatedBy)
		}
		kho = append(kho, d.ShopID)
	}
	ten, err := tenNguoiDung(ctx, r.db, nguoi)
	if err != nil {
		return nil, err
	}
	var shops []domain.ChiNhanh
	if err := r.db.WithContext(ctx).Unscoped().Select("id", "name").Where("id IN ?", kho).Find(&shops).Error; err != nil {
		return nil, err
	}
	tenKho := map[uint]string{}
	for _, s := range shops {
		tenKho[s.ID] = s.Name
	}

	for _, u := range uses {
		d := theoDon[u.OrderID]
		dong := domain.VoucherCodeUse{OrderID: u.OrderID, OrderCode: d.OrderCode, Shop: tenKho[d.ShopID], Discount: u.DiscountAmount}
		if d.CreatedBy != nil {
			dong.Staff = ten[*d.CreatedBy]
		}
		if u.UsedAt != nil {
			dong.UsedAt = *u.UsedAt
		} else {
			dong.UsedAt = d.CreatedAt
		}
		out = append(out, dong)
	}

	return out, nil
}
