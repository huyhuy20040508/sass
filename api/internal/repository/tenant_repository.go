package repository

import (
	"context"
	"errors"

	"sass-api/internal/domain"

	"gorm.io/gorm"
)

type tenantRepository struct{ db *gorm.DB }

func NewTenantRepository(db *gorm.DB) domain.TenantRepository {
	return &tenantRepository{db: db}
}

func (r *tenantRepository) FindByCode(ctx context.Context, code string) (*domain.Tenant, error) {
	var t domain.Tenant
	// Mã rỗng: chặn tại đây thay vì bắn truy vấn xuống CSDL mỗi lần có người gửi
	// form đăng nhập trống.
	if code == "" {
		return nil, domain.ErrNotFound
	}
	err := r.db.WithContext(ctx).Where("code = ?", code).First(&t).Error
	if errors.Is(err, gorm.ErrRecordNotFound) {
		return nil, domain.ErrNotFound
	}
	return &t, err
}

// TrangThaiTheoID đọc đúng MỘT cột `status`.
//
// Không dùng lại First(&Tenant) để đỡ kéo cả dòng: hàm này nằm trên đường làm
// mới token, chạy đều đặn cho mọi phiên đang mở.
//
// Cửa hàng không còn (đã bị xoá khỏi sổ) trả ErrNotFound, và nơi gọi phải coi
// đó là "phiên hết hiệu lực" — khác hẳn lỗi database, thứ không được phép đá
// người đang làm việc ra ngoài.
func (r *tenantRepository) TrangThaiTheoID(ctx context.Context, id uint) (string, error) {
	var status string
	err := r.db.WithContext(ctx).
		Model(&domain.Tenant{}).
		Select("status").
		Where("id = ?", id).
		Take(&status).Error
	if errors.Is(err, gorm.ErrRecordNotFound) {
		return "", domain.ErrNotFound
	}

	return status, err
}
