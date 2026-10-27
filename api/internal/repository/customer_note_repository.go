package repository

import (
	"context"
	"errors"

	"gorm.io/gorm"

	"sass-api/internal/domain"
)

type ghiChuKhachRepository struct{ db *gorm.DB }

func NewGhiChuKhachRepository(db *gorm.DB) domain.GhiChuKhachRepository {
	return &ghiChuKhachRepository{db: db}
}

// coSoGhiChu — DB đã chạy 0097 chưa. Chưa thì sổ rỗng và nút lọc hẹn không ra ai,
// thay vì cả màn khách hàng báo lỗi.
func coSoGhiChu(db *gorm.DB) bool {
	return coCot(db, &domain.GhiChuKhach{}, "due_date")
}

func (r *ghiChuKhachRepository) CuaKhach(ctx context.Context, customerID uint) ([]domain.GhiChuKhach, error) {
	db := r.db.WithContext(ctx)
	ds := []domain.GhiChuKhach{}
	if !coSoGhiChu(db) {
		return ds, nil
	}
	if err := db.Where("customer_id = ?", customerID).Order("created_at DESC, id DESC").Limit(200).Find(&ds).Error; err != nil {
		return nil, err
	}

	nguoi, cn := []uint{}, []uint{}
	for _, g := range ds {
		if g.CreatedBy != nil {
			nguoi = append(nguoi, *g.CreatedBy)
		}
		if g.ShopID != nil {
			cn = append(cn, *g.ShopID)
		}
	}
	ten, err := tenNguoiDung(ctx, r.db, nguoi)
	if err != nil {
		return nil, err
	}
	tenCN := map[uint]string{}
	if len(cn) > 0 {
		var rows []struct {
			ID   uint
			Name string
		}
		if err := db.Model(&domain.ChiNhanh{}).Unscoped().Where("id IN ?", cn).Select("id, name").Scan(&rows).Error; err != nil {
			return nil, err
		}
		for _, x := range rows {
			tenCN[x.ID] = x.Name
		}
	}
	for i := range ds {
		ganNgayHen(&ds[i])
		if ds[i].CreatedBy != nil {
			ds[i].NguoiGhi = ten[*ds[i].CreatedBy]
		}
		if ds[i].ShopID != nil {
			ds[i].TenChiNhanh = tenCN[*ds[i].ShopID]
		}
	}

	return ds, nil
}

func ganNgayHen(g *domain.GhiChuKhach) {
	if g.HenNgay != nil {
		g.NgayHen = g.HenNgay.Format("2006-01-02")
	}
}

func (r *ghiChuKhachRepository) Them(ctx context.Context, g *domain.GhiChuKhach) error {
	db := r.db.WithContext(ctx)
	if !coSoGhiChu(db) {
		return domain.ErrChuaCoSoGhiChu
	}
	if g.ShopID == nil {
		if cn := chiNhanhDoc(ctx, r.db); cn > 0 {
			g.ShopID = &cn
		}
	}
	if err := db.Create(g).Error; err != nil {
		return err
	}
	ganNgayHen(g)
	// Trả về đủ như một dòng của sổ: màn quầy chèn thẳng vào đầu danh sách.
	if g.CreatedBy != nil {
		if ten, err := tenNguoiDung(ctx, r.db, []uint{*g.CreatedBy}); err == nil {
			g.NguoiGhi = ten[*g.CreatedBy]
		}
	}
	if g.ShopID != nil {
		var cn domain.ChiNhanh
		if db.Unscoped().Select("id, name").First(&cn, *g.ShopID).Error == nil {
			g.TenChiNhanh = cn.Name
		}
	}

	return nil
}

func (r *ghiChuKhachRepository) Tim(ctx context.Context, id uint) (*domain.GhiChuKhach, error) {
	db := r.db.WithContext(ctx)
	if !coSoGhiChu(db) {
		return nil, domain.ErrNotFound
	}
	var g domain.GhiChuKhach
	if err := db.First(&g, id).Error; err != nil {
		if errors.Is(err, gorm.ErrRecordNotFound) {
			return nil, domain.ErrNotFound
		}
		return nil, err
	}

	return &g, nil
}

func (r *ghiChuKhachRepository) Xoa(ctx context.Context, id uint) error {
	return r.db.WithContext(ctx).Delete(&domain.GhiChuKhach{}, id).Error
}

func (r *ghiChuKhachRepository) LaKhach(ctx context.Context, id uint) (bool, error) {
	var n int64
	err := r.db.WithContext(ctx).Model(&domain.User{}).Where("id = ? AND role_id = ?", id, domain.CustomerRoleID).Count(&n).Error

	return n > 0, err
}
