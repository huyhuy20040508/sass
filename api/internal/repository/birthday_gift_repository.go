package repository

import (
	"context"
	"errors"
	"time"

	"gorm.io/gorm"

	"sass-api/internal/domain"
)

type quaSinhNhatRepository struct{ db *gorm.DB }

func NewQuaSinhNhatRepository(db *gorm.DB) domain.QuaSinhNhatRepository {
	return &quaSinhNhatRepository{db: db}
}

// coSoSinhNhat — DB đã chạy 0098 chưa. Chưa thì không ai "đã chúc", không mã nào
// là mã riêng, và nút Tạo mã báo cần chạy migration.
func coSoSinhNhat(db *gorm.DB) bool {
	return coCot(db, &domain.QuaSinhNhat{}, "voucher_id")
}

func (r *quaSinhNhatRepository) GanNhat(ctx context.Context, customerID uint, tu time.Time) (*domain.QuaSinhNhat, error) {
	db := r.db.WithContext(ctx)
	if !coSoSinhNhat(db) {
		return nil, nil
	}
	var q domain.QuaSinhNhat
	err := db.Where("customer_id = ? AND created_at >= ?", customerID, tu).Order("created_at DESC, id DESC").First(&q).Error
	if errors.Is(err, gorm.ErrRecordNotFound) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	if q.VoucherID != nil {
		var v domain.Voucher
		if db.Unscoped().First(&v, *q.VoucherID).Error == nil {
			q.Voucher = &v
		}
	}
	return &q, nil
}

func (r *quaSinhNhatRepository) Tang(ctx context.Context, q *domain.QuaSinhNhat, v *domain.Voucher, sinhMa func() string) error {
	db := r.db.WithContext(ctx)
	if !coSoSinhNhat(db) {
		return domain.ErrChuaCoSoSinhNhat
	}
	if q.ShopID == nil {
		if cn := chiNhanhDoc(ctx, r.db); cn > 0 {
			q.ShopID = &cn
		}
	}

	return db.Transaction(func(tx *gorm.DB) error {
		if v != nil {
			// Dò cả mã đã xoá mềm: khoá duy nhất trên code vẫn tính chúng.
			for lan := 0; ; lan++ {
				v.Code = sinhMa()
				var n int64
				if err := tx.Unscoped().Model(&domain.Voucher{}).Where("code = ?", v.Code).Count(&n).Error; err != nil {
					return err
				}
				if n == 0 {
					break
				}
				if lan >= 20 {
					return domain.ErrVoucherCodeExists
				}
			}
			if err := tx.Create(v).Error; err != nil {
				return err
			}
			q.VoucherID = &v.ID
			q.Voucher = v
		}
		return tx.Create(q).Error
	})
}

func (r *quaSinhNhatRepository) NgaySinh(ctx context.Context, customerID uint) (*time.Time, string, error) {
	var u domain.User
	err := r.db.WithContext(ctx).Select("id", "full_name", "date_of_birth").
		Where("id = ? AND role_id = ?", customerID, domain.CustomerRoleID).First(&u).Error
	if errors.Is(err, gorm.ErrRecordNotFound) {
		return nil, "", domain.ErrNotFound
	}
	if err != nil {
		return nil, "", err
	}
	return u.DateOfBirth, u.FullName, nil
}

// ChuMaRieng — khách được tặng mã này (mã sinh nhật); 0 = mã chung ai cũng dùng.
func (r *voucherRepository) ChuMaRieng(ctx context.Context, voucherID uint) (uint, error) {
	db := r.db.WithContext(ctx)
	if !coSoSinhNhat(db) {
		return 0, nil
	}
	var ids []uint
	err := db.Model(&domain.QuaSinhNhat{}).Where("voucher_id = ?", voucherID).Limit(1).Pluck("customer_id", &ids).Error
	if err != nil || len(ids) == 0 {
		return 0, err
	}
	return ids[0], nil
}
