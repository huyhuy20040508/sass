package repository

import (
	"context"
	"errors"

	"gorm.io/gorm"

	"sass-api/internal/domain"
)

type quyTacMaRepository struct{ db *gorm.DB }

// NewQuyTacMaRepository dựng sổ quy tắc đánh số trên DATA PLANE.
func NewQuyTacMaRepository(db *gorm.DB) domain.QuyTacMaRepository {
	return &quyTacMaRepository{db: db}
}

func (r *quyTacMaRepository) List(ctx context.Context) ([]domain.QuyTacMa, error) {
	var ds []domain.QuyTacMa
	err := r.db.WithContext(ctx).Model(&domain.QuyTacMa{}).
		Order("shop_id ASC, doc_type ASC").Find(&ds).Error
	if err != nil {
		return nil, err
	}

	return ds, nil
}

func (r *quyTacMaRepository) DangBat(ctx context.Context, docType string, shopID uint) (*domain.QuyTacMa, error) {
	var q domain.QuyTacMa
	err := r.db.WithContext(ctx).
		Where("doc_type = ? AND shop_id = ? AND is_active = ?", docType, shopID, true).
		Take(&q).Error
	if errors.Is(err, gorm.ErrRecordNotFound) {
		return nil, domain.ErrNotFound
	}
	if err != nil {
		return nil, err
	}

	return &q, nil
}

// Luu ghi cả lượt trong MỘT transaction: màn hình gửi lên trạng thái cuối cùng
// của các phạm vi nó đang hiện, nên ghi dở nửa chừng là cấu hình nói hai chuyện.
func (r *quyTacMaRepository) Luu(ctx context.Context, phamVi []uint, ds []domain.QuyTacMa) error {
	return r.db.WithContext(ctx).Transaction(func(tx *gorm.DB) error {
		for _, moi := range ds {
			if err := ghiMot(tx, moi); err != nil {
				return err
			}
		}

		// Loại nào thuộc phạm vi này mà không gửi lên = người dùng vừa bỏ tick.
		// Tắt cờ chứ không xoá hàng: tick lại là tiền tố cũ còn nguyên.
		for _, shopID := range phamVi {
			q := tx.Model(&domain.QuyTacMa{}).
				Where("shop_id = ? AND is_active = ?", shopID, true)
			if con := loaiCuaPhamVi(ds, shopID); len(con) > 0 {
				q = q.Where("doc_type NOT IN ?", con)
			}
			if err := q.Update("is_active", false).Error; err != nil {
				return err
			}
		}

		return nil
	})
}

// ghiMot tạo mới hoặc cập nhật quy tắc của một (phạm vi, loại).
func ghiMot(tx *gorm.DB, moi domain.QuyTacMa) error {
	var cu domain.QuyTacMa
	err := tx.Where("shop_id = ? AND doc_type = ?", moi.ShopID, moi.DocType).Take(&cu).Error

	if errors.Is(err, gorm.ErrRecordNotFound) {
		moi.IsActive = true

		return tx.Create(&moi).Error
	}
	if err != nil {
		return err
	}

	cu.Prefix = moi.Prefix
	cu.ValuePart = moi.ValuePart
	cu.Length = moi.Length
	cu.Suffix = moi.Suffix
	cu.IsActive = true

	return tx.Save(&cu).Error
}

// loaiCuaPhamVi trả về các mã loại được gửi lên cho một phạm vi.
func loaiCuaPhamVi(ds []domain.QuyTacMa, shopID uint) []string {
	var con []string
	for _, q := range ds {
		if q.ShopID == shopID {
			con = append(con, q.DocType)
		}
	}

	return con
}
