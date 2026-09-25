package repository

import (
	"context"
	"errors"
	"fmt"
	"strconv"
	"strings"

	"gorm.io/gorm"

	"sass-api/internal/domain"
)

type donViTinhRepository struct{ db *gorm.DB }

func NewDonViTinhRepository(db *gorm.DB) domain.DonViTinhRepository {
	return &donViTinhRepository{db: db}
}

func (r *donViTinhRepository) List(ctx context.Context, f domain.DonViTinhFilter) ([]domain.DonViTinh, error) {
	// Mới nhất lên đầu, đúng thứ tự của bản cũ (orderBy id desc): người ta vừa
	// thêm một đơn vị thì muốn thấy nó ngay, không phải dò xuống cuối bảng.
	q := r.db.WithContext(ctx).Order("id DESC")
	if f.OnlyActive {
		q = q.Where("is_active = ?", true)
	}
	if kw := strings.TrimSpace(f.Keyword); kw != "" {
		like := "%" + kw + "%"
		q = q.Where("(name LIKE ? OR code LIKE ?)", like, like)
	}

	var list []domain.DonViTinh
	if err := q.Find(&list).Error; err != nil {
		return nil, err
	}

	return list, nil
}

func (r *donViTinhRepository) FindByID(ctx context.Context, id uint) (*domain.DonViTinh, error) {
	var dv domain.DonViTinh
	err := r.db.WithContext(ctx).First(&dv, id).Error
	if errors.Is(err, gorm.ErrRecordNotFound) {
		return nil, domain.ErrNotFound
	}
	if err != nil {
		return nil, err
	}

	return &dv, nil
}

// ExistsByCode dùng Unscoped: mã của đơn vị đã xoá mềm vẫn giữ chỗ trong UNIQUE
// index, báo trùng ở đây thân thiện hơn là để MySQL ném lỗi khi ghi.
func (r *donViTinhRepository) ExistsByCode(ctx context.Context, code string, excludeID uint) (bool, error) {
	var count int64
	q := r.db.WithContext(ctx).Unscoped().Model(&domain.DonViTinh{}).Where("code = ?", code)
	if excludeID > 0 {
		q = q.Where("id <> ?", excludeID)
	}
	err := q.Count(&count).Error

	return count > 0, err
}

// ExistsByName so bằng LOWER(...) COLLATE utf8mb4_bin.
//
// Đối chiếu mặc định của cột là utf8mb4_unicode_ci — nó bỏ qua CẢ hoa thường
// LẪN dấu, nên "Đường" và "Duong" bị coi là một. Ép sang utf8mb4_bin thì so
// từng byte (phân biệt dấu), còn LOWER() ở hai vế trả lại phần bỏ qua hoa
// thường. Đây là luật bản cũ v2 định làm nhưng chỉ áp cho tên CÓ dấu; ở đây một
// luật cho mọi tên.
func (r *donViTinhRepository) ExistsByName(ctx context.Context, name string, excludeID uint) (bool, error) {
	var count int64
	q := r.db.WithContext(ctx).Model(&domain.DonViTinh{}).
		Where("LOWER(name) COLLATE utf8mb4_bin = LOWER(?)", name)
	if excludeID > 0 {
		q = q.Where("id <> ?", excludeID)
	}
	err := q.Count(&count).Error

	return count > 0, err
}

// NextCode sinh mã kế tiếp dạng DV001.
//
// Lấy số lớn nhất trong các mã ĐÚNG KHUÔN DV + chữ số rồi cộng một, tính cả
// dòng đã xoá mềm — mã cũ vẫn chiếm chỗ trong UNIQUE index. Mã do người dùng tự
// đặt (KG, THUNG…) không tham gia, nên khai tay xen kẽ cũng không làm hỏng dãy.
func (r *donViTinhRepository) NextCode(ctx context.Context) (string, error) {
	var codes []string
	if err := r.db.WithContext(ctx).Unscoped().
		Model(&domain.DonViTinh{}).
		Where("code REGEXP ?", "^DV[0-9]+$").
		Pluck("code", &codes).Error; err != nil {
		return "", err
	}

	max := 0
	for _, c := range codes {
		if n, err := strconv.Atoi(strings.TrimPrefix(c, "DV")); err == nil && n > max {
			max = n
		}
	}

	return fmt.Sprintf("DV%03d", max+1), nil
}

func (r *donViTinhRepository) Create(ctx context.Context, dv *domain.DonViTinh) error {
	return translateDonViTinhErr(r.db.WithContext(ctx).Create(dv).Error)
}

func (r *donViTinhRepository) Update(ctx context.Context, dv *domain.DonViTinh) error {
	return translateDonViTinhErr(r.db.WithContext(ctx).Save(dv).Error)
}

// Delete xoá mềm. Chưa có bảng nào trỏ tới product_units nên không phải chặn
// như bên nhà cung cấp; gắn đơn vị vào mặt hàng thì thêm lượt đếm ở đây.
func (r *donViTinhRepository) Delete(ctx context.Context, id uint) error {
	return translateDonViTinhErr(r.db.WithContext(ctx).Delete(&domain.DonViTinh{}, id).Error)
}

// translateDonViTinhErr chuyển lỗi DB thô sang lỗi nghiệp vụ để handler trả mã
// HTTP thân thiện.
func translateDonViTinhErr(err error) error {
	switch {
	case err == nil:
		return nil
	case errors.Is(err, gorm.ErrDuplicatedKey):
		return domain.ErrConflict
	case errors.Is(err, gorm.ErrForeignKeyViolated):
		return domain.ErrConflict
	default:
		return err
	}
}
