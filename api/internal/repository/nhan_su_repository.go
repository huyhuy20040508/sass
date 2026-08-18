package repository

import (
	"context"
	"errors"
	"strings"

	"gorm.io/gorm"

	"sass-api/internal/domain"
)

type nhanVienRepository struct{ db *gorm.DB }

// NewNhanVienRepository dựng sổ nhân sự trên DATA PLANE (kết nối CÓ bộ lọc tenant).
func NewNhanVienRepository(db *gorm.DB) domain.NhanVienRepository {
	return &nhanVienRepository{db: db}
}

// List lọc ngay trong SQL và sắp theo trạng thái trước, tên sau — FIELD() cho ra
// đúng thứ tự nghiệp vụ thay vì thứ tự bảng chữ cái của chuỗi ENUM.
func (r *nhanVienRepository) List(ctx context.Context, f domain.NhanSuFilter) ([]domain.NhanVien, error) {
	q := r.db.WithContext(ctx).Model(&domain.NhanVien{})

	if kw := strings.TrimSpace(f.Keyword); kw != "" {
		like := "%" + kw + "%"
		q = q.Where("full_name LIKE ? OR code LIKE ? OR phone LIKE ?", like, like, like)
	}
	if f.Status != "" {
		q = q.Where("status = ?", f.Status)
	}
	if f.Position != "" {
		q = q.Where("position = ?", f.Position)
	}
	// FIND_IN_SET chứ không `=`: cột SET chứa "sang,chieu" thì so bằng với "sang"
	// trượt, và người trực cả sáng lẫn chiều biến mất khỏi lượt lọc ca sáng.
	if f.WorkShift != "" {
		q = q.Where("FIND_IN_SET(?, work_shift)", f.WorkShift)
	}
	if f.ContractType != "" {
		q = q.Where("contract_type = ?", f.ContractType)
	}
	if f.ShopID > 0 {
		q = q.Where("shop_id = ?", f.ShopID)
	}

	var list []domain.NhanVien
	err := q.Order("FIELD(status,'dang_lam','tam_nghi','da_nghi'), full_name ASC").Find(&list).Error
	if err != nil {
		return nil, err
	}

	return list, nil
}

func (r *nhanVienRepository) FindByID(ctx context.Context, id uint) (*domain.NhanVien, error) {
	var nv domain.NhanVien
	err := r.db.WithContext(ctx).First(&nv, id).Error
	if errors.Is(err, gorm.ErrRecordNotFound) {
		return nil, domain.ErrNotFound
	}
	if err != nil {
		return nil, err
	}

	return &nv, nil
}

// ExistsByCode dùng Unscoped: mã của hồ sơ đã xoá mềm vẫn giữ chỗ trong
// uq_employees_tenant_code. Bộ lọc tenant vẫn chạy.
func (r *nhanVienRepository) ExistsByCode(ctx context.Context, code string, excludeID uint) (bool, error) {
	var count int64
	q := r.db.WithContext(ctx).Unscoped().Model(&domain.NhanVien{}).Where("code = ?", code)
	if excludeID > 0 {
		q = q.Where("id <> ?", excludeID)
	}
	err := q.Count(&count).Error

	return count > 0, err
}

// ExistsByUser KHÔNG Unscoped, ngược với ExistsByCode: hồ sơ đã xoá nhả tài
// khoản ra cho hồ sơ mới nhận — xem chú thích ở Delete.
func (r *nhanVienRepository) ExistsByUser(ctx context.Context, userID, excludeID uint) (bool, error) {
	var count int64
	q := r.db.WithContext(ctx).Model(&domain.NhanVien{}).Where("user_id = ?", userID)
	if excludeID > 0 {
		q = q.Where("id <> ?", excludeID)
	}
	err := q.Count(&count).Error

	return count > 0, err
}

func (r *nhanVienRepository) Create(ctx context.Context, nv *domain.NhanVien) error {
	return translateNhanSuErr(r.db.WithContext(ctx).Create(nv).Error)
}

func (r *nhanVienRepository) Update(ctx context.Context, nv *domain.NhanVien) error {
	return translateNhanSuErr(r.db.WithContext(ctx).Save(nv).Error)
}

// Delete xoá MỀM và nhả tài khoản (user_id = NULL). Phải nhả vì uq_employees_user
// không có điều kiện deleted_at: giữ lại thì tài khoản đó vĩnh viễn không hồ sơ
// nào nhận được, mà danh sách chẳng thấy ai đang giữ nó.
func (r *nhanVienRepository) Delete(ctx context.Context, id uint) error {
	return r.db.WithContext(ctx).Transaction(func(tx *gorm.DB) error {
		if err := tx.Model(&domain.NhanVien{}).Where("id = ?", id).
			Update("user_id", nil).Error; err != nil {
			return translateNhanSuErr(err)
		}

		return translateNhanSuErr(tx.Delete(&domain.NhanVien{}, id).Error)
	})
}

// MaLonNhat lấy mã lớn nhất, kể cả hồ sơ đã xoá mềm (mã của họ vẫn chiếm chỗ
// trong khoá duy nhất). Sắp theo ĐỘ DÀI trước: sắp chuỗi thuần thì "NV9" đứng
// trên "NV10" và người thứ mười một nhận lại mã NV10.
func (r *nhanVienRepository) MaLonNhat(ctx context.Context) (string, error) {
	var ma string
	err := r.db.WithContext(ctx).Unscoped().Model(&domain.NhanVien{}).
		Select("code").Order("LENGTH(code) DESC, code DESC").Limit(1).Scan(&ma).Error
	if err != nil {
		return "", err
	}

	return ma, nil
}

// translateNhanSuErr chuyển lỗi DB thô sang lỗi nghiệp vụ: hai lượt tạo song song
// vẫn có thể cùng lọt qua lượt kiểm ở service rồi đụng nhau ở khoá.
func translateNhanSuErr(err error) error {
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

// RangBuocCuaTaiKhoan — xem NhanVienRepository.
//
// Đếm CÓ HAY KHÔNG chứ không đếm bao nhiêu: câu trả lời chỉ dùng để chặn hay
// không chặn, mà `LIMIT 1` thì database dừng ngay khi gặp dòng đầu tiên.
func (r *nhanVienRepository) RangBuocCuaTaiKhoan(ctx context.Context, userID uint) (bool, bool, error) {
	var caID uint
	err := r.db.WithContext(ctx).
		Table("work_shifts").
		Select("id").
		Where("opened_by = ? AND closed_at IS NULL", userID).
		Limit(1).
		Scan(&caID).Error
	if err != nil {
		return false, false, err
	}

	var soQuyID uint
	err = r.db.WithContext(ctx).
		Table("cash_entries").
		Select("id").
		Where("created_by = ?", userID).
		Limit(1).
		Scan(&soQuyID).Error
	if err != nil {
		return false, false, err
	}

	return caID > 0, soQuyID > 0, nil
}
