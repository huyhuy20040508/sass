package repository

import (
	"context"
	"errors"

	"sass-api/internal/domain"

	"gorm.io/gorm"
)

type emailVerificationRepository struct{ db *gorm.DB }

func NewEmailVerificationRepository(db *gorm.DB) domain.EmailVerificationRepository {
	return &emailVerificationRepository{db: db}
}

func (r *emailVerificationRepository) Create(ctx context.Context, v *domain.EmailVerification) error {
	return r.db.WithContext(ctx).Create(v).Error
}

func (r *emailVerificationRepository) Update(ctx context.Context, v *domain.EmailVerification) error {
	return r.db.WithContext(ctx).Save(v).Error
}

// FindLatestActive lấy bản ghi mới nhất chưa xác thực của email (không lọc hạn dùng
// để service phân biệt được "mã sai" và "mã hết hạn").
func (r *emailVerificationRepository) FindLatestActive(ctx context.Context, email, purpose string) (*domain.EmailVerification, error) {
	var v domain.EmailVerification
	err := r.db.WithContext(ctx).
		Where("email = ? AND purpose = ? AND verified_at IS NULL", email, purpose).
		Order("id DESC").
		First(&v).Error
	if err != nil {
		if errors.Is(err, gorm.ErrRecordNotFound) {
			return nil, domain.ErrNotFound
		}
		return nil, err
	}
	return &v, nil
}

func (r *emailVerificationRepository) InvalidateByUser(ctx context.Context, userID uint, purpose string) error {
	return r.db.WithContext(ctx).
		Model(&domain.EmailVerification{}).
		Where("user_id = ? AND purpose = ? AND verified_at IS NULL", userID, purpose).
		Update("verified_at", gorm.Expr("NOW(3)")).Error
}
