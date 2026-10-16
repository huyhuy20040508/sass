package repository

import (
	"context"
	"errors"

	"gorm.io/gorm"
	"gorm.io/gorm/clause"

	"sass-api/internal/domain"
)

type thietBiRepository struct{ db *gorm.DB }

// NewThietBiRepository chạy trên DATA PLANE (có bộ lọc tenant). Mọi câu đều
// kèm shop_id: máy in của chi nhánh này không được hiện ở quầy chi nhánh khác.
func NewThietBiRepository(db *gorm.DB) domain.ThietBiRepository {
	return &thietBiRepository{db: db}
}

func (r *thietBiRepository) ChiNhanhGhi(ctx context.Context) (uint, error) {
	return chiNhanhCuaRequest(ctx, r.db)
}

func (r *thietBiRepository) ChiNhanhDoc(ctx context.Context) uint {
	return chiNhanhDoc(ctx, r.db)
}

func motDong[T any](q *gorm.DB) (*T, error) {
	var v T
	err := q.Take(&v).Error
	if errors.Is(err, gorm.ErrRecordNotFound) {
		return nil, domain.ErrNotFound
	}
	if err != nil {
		return nil, err
	}

	return &v, nil
}

func (r *thietBiRepository) MayIn(ctx context.Context, shopID uint) ([]domain.MayIn, error) {
	var ds []domain.MayIn
	err := r.db.WithContext(ctx).Where("shop_id = ?", shopID).Order("id ASC").Find(&ds).Error

	return ds, err
}

func (r *thietBiRepository) MotMayIn(ctx context.Context, shopID, id uint) (*domain.MayIn, error) {
	return motDong[domain.MayIn](r.db.WithContext(ctx).Where("shop_id = ? AND id = ?", shopID, id))
}

func (r *thietBiRepository) LuuMayIn(ctx context.Context, m *domain.MayIn) error {
	return r.db.WithContext(ctx).Save(m).Error
}

// XoaMayIn — máy còn được gán phiếu thì khoá ngoại chặn; service đã báo câu dễ
// hiểu trước khi tới đây, khoá ngoại chỉ là chốt cuối cho lượt chen ngang.
func (r *thietBiRepository) XoaMayIn(ctx context.Context, shopID, id uint) error {
	return r.db.WithContext(ctx).Where("shop_id = ? AND id = ?", shopID, id).Delete(&domain.MayIn{}).Error
}

func (r *thietBiRepository) GanPhieu(ctx context.Context, shopID uint) ([]domain.GanPhieuIn, error) {
	var ds []domain.GanPhieuIn
	err := r.db.WithContext(ctx).Where("shop_id = ?", shopID).Order("id ASC").Find(&ds).Error

	return ds, err
}

func (r *thietBiRepository) MotGanPhieu(ctx context.Context, shopID, id uint) (*domain.GanPhieuIn, error) {
	return motDong[domain.GanPhieuIn](r.db.WithContext(ctx).Where("shop_id = ? AND id = ?", shopID, id))
}

func (r *thietBiRepository) LuuGanPhieu(ctx context.Context, g *domain.GanPhieuIn) error {
	return r.db.WithContext(ctx).Save(g).Error
}

func (r *thietBiRepository) XoaGanPhieu(ctx context.Context, shopID, id uint) error {
	return r.db.WithContext(ctx).Where("shop_id = ? AND id = ?", shopID, id).Delete(&domain.GanPhieuIn{}).Error
}

func (r *thietBiRepository) NutIn(ctx context.Context, shopID uint) ([]domain.NutIn, error) {
	var ds []domain.NutIn
	err := r.db.WithContext(ctx).Where("shop_id = ?", shopID).Find(&ds).Error

	return ds, err
}

// LuuNutIn ghi đè theo khoá (chi nhánh, nút): dòng chưa có thì tạo — nút chưa
// cấu hình vốn không có dòng nào.
func (r *thietBiRepository) LuuNutIn(ctx context.Context, n *domain.NutIn) error {
	return r.db.WithContext(ctx).Clauses(clause.OnConflict{
		Columns:   []clause.Column{{Name: "tenant_id"}, {Name: "shop_id"}, {Name: "button"}},
		DoUpdates: clause.AssignmentColumns([]string{"slips", "is_active", "updated_at"}),
	}).Create(n).Error
}

func (r *thietBiRepository) MauIn(ctx context.Context, shopID uint) ([]domain.MauInLuu, error) {
	var ds []domain.MauInLuu
	err := r.db.WithContext(ctx).Where("shop_id = ?", shopID).Find(&ds).Error

	return ds, err
}

func (r *thietBiRepository) LuuMauIn(ctx context.Context, m *domain.MauInLuu) error {
	return r.db.WithContext(ctx).Clauses(clause.OnConflict{
		Columns:   []clause.Column{{Name: "tenant_id"}, {Name: "shop_id"}, {Name: "kind"}},
		DoUpdates: clause.AssignmentColumns([]string{"options", "settings", "updated_at"}),
	}).Create(m).Error
}

// XoaMauIn = về mặc định: không còn dòng thì đọc ra bộ mặc định của domain.
func (r *thietBiRepository) XoaMauIn(ctx context.Context, shopID uint, kind string) error {
	return r.db.WithContext(ctx).Where("shop_id = ? AND kind = ?", shopID, kind).Delete(&domain.MauInLuu{}).Error
}

func (r *thietBiRepository) NhomHangGoc(ctx context.Context) ([]domain.Category, error) {
	var ds []domain.Category
	err := r.db.WithContext(ctx).Select("id", "name").
		Where("parent_id IS NULL AND is_active = ?", true).
		Order("sort_order ASC, name ASC").Find(&ds).Error

	return ds, err
}
