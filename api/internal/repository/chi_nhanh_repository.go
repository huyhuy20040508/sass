package repository

import (
	"context"
	"errors"

	"gorm.io/gorm"

	"sass-api/internal/domain"
)

type chiNhanhRepository struct{ db *gorm.DB }

// NewChiNhanhRepository dựng sổ chi nhánh trên DATA PLANE (repository.NewDB —
// kết nối CÓ bộ lọc tenant).
//
// Đưa nhầm kết nối control plane vào là kiểu nhầm IM LẶNG chứ không ồn ào: bên
// đó cũng có bảng `tenants` nhưng không có `shops`, nên mọi lượt đọc thành lỗi
// "table doesn't exist" — ồn, dễ thấy. Nguy hiểm hơn là chạy đúng kết nối mà
// thiếu tenant trong ctx: bộ lọc sẽ tự báo lỗi, và đó là chủ ý (xem tenant_scope).
func NewChiNhanhRepository(db *gorm.DB) domain.ChiNhanhRepository {
	return &chiNhanhRepository{db: db}
}

func (r *chiNhanhRepository) List(ctx context.Context, onlyActive bool) ([]domain.ChiNhanh, error) {
	q := r.db.WithContext(ctx).Model(&domain.ChiNhanh{}).Order("code ASC")
	if onlyActive {
		q = q.Where("is_active = ?", true)
	}

	var list []domain.ChiNhanh
	if err := q.Find(&list).Error; err != nil {
		return nil, err
	}

	return list, nil
}

func (r *chiNhanhRepository) FindByID(ctx context.Context, id uint) (*domain.ChiNhanh, error) {
	var cn domain.ChiNhanh
	err := r.db.WithContext(ctx).First(&cn, id).Error
	if errors.Is(err, gorm.ErrRecordNotFound) {
		return nil, domain.ErrNotFound
	}
	if err != nil {
		return nil, err
	}

	return &cn, nil
}

// ExistsByCode dùng Unscoped: mã của chi nhánh đã xoá mềm vẫn giữ chỗ trong
// uq_shops_tenant_code. Bộ lọc tenant VẪN chạy (Unscoped chỉ tắt điều kiện
// deleted_at), nên câu này không nhìn sang mã của cửa hàng khác — mã chi nhánh
// chỉ cần duy nhất trong một tenant.
func (r *chiNhanhRepository) ExistsByCode(ctx context.Context, code string, excludeID uint) (bool, error) {
	var count int64
	q := r.db.WithContext(ctx).Unscoped().Model(&domain.ChiNhanh{}).Where("code = ?", code)
	if excludeID > 0 {
		q = q.Where("id <> ?", excludeID)
	}
	err := q.Count(&count).Error

	return count > 0, err
}

func (r *chiNhanhRepository) Create(ctx context.Context, cn *domain.ChiNhanh) error {
	return translateChiNhanhErr(r.db.WithContext(ctx).Create(cn).Error)
}

func (r *chiNhanhRepository) Update(ctx context.Context, cn *domain.ChiNhanh) error {
	return translateChiNhanhErr(r.db.WithContext(ctx).Save(cn).Error)
}

// Delete xoá MỀM.
//
// Không xoá cứng, và lý do không phải là thói quen: mọi bảng giao dịch đều mang
// `shop_id` với khoá ngoại trỏ về đây (đơn hàng, phiếu nhập, tồn kho). Xoá cứng
// một chi nhánh có dữ liệu thì hoặc MySQL từ chối, hoặc — tệ hơn — dữ liệu cũ
// mất đầu mối về nơi nó đã phát sinh.
func (r *chiNhanhRepository) Delete(ctx context.Context, id uint) error {
	return translateChiNhanhErr(r.db.WithContext(ctx).Delete(&domain.ChiNhanh{}, id).Error)
}

// Count đếm chi nhánh còn trong sổ — con số của hạn mức `max_shops`.
//
// TÍNH CẢ chi nhánh đang ngừng hoạt động: nó còn giữ mã, còn đứng trong danh
// sách, và mở lại chỉ là một cú bấm. Đếm mỗi chi nhánh đang chạy thì tắt tạm
// một cái là mở ra một chỗ trống — cùng lỗ hổng với việc đếm tài khoản theo
// trạng thái (xem HanMucService).
//
// KHÔNG Unscoped: chi nhánh đã xoá thì không chiếm chỗ của ai.
func (r *chiNhanhRepository) Count(ctx context.Context) (int64, error) {
	var count int64
	err := r.db.WithContext(ctx).Model(&domain.ChiNhanh{}).Count(&count).Error

	return count, err
}

// BanOnline: chi nhánh đang mở có id nhỏ nhất — xem chú thích ở port.
//
// Sắp theo `id` chứ không theo `created_at`: hai chi nhánh dựng trong cùng một
// mili giây (kịch bản gieo dữ liệu, hoặc một lượt nhập liệu tự động) sẽ cho thứ
// tự không xác định, và "nơi bán online" đổi qua lại giữa hai lượt gọi là thứ
// không ai gỡ ra được từ nhật ký.
func (r *chiNhanhRepository) BanOnline(ctx context.Context) (*domain.ChiNhanh, error) {
	var cn domain.ChiNhanh
	err := r.db.WithContext(ctx).Model(&domain.ChiNhanh{}).
		Where("is_active = ?", true).Order("id ASC").Take(&cn).Error
	if errors.Is(err, gorm.ErrRecordNotFound) {
		return nil, domain.ErrNotFound
	}
	if err != nil {
		return nil, err
	}

	return &cn, nil
}

func (r *chiNhanhRepository) CountActiveExcept(ctx context.Context, excludeID uint) (int64, error) {
	var count int64
	q := r.db.WithContext(ctx).Model(&domain.ChiNhanh{}).Where("is_active = ?", true)
	if excludeID > 0 {
		q = q.Where("id <> ?", excludeID)
	}
	err := q.Count(&count).Error

	return count, err
}

// translateChiNhanhErr chuyển lỗi DB thô sang lỗi nghiệp vụ.
//
// Trùng mã đáng lẽ đã bị chặn ở tầng service, nhưng hai lượt tạo chạy song song
// vẫn có thể cùng lọt qua lượt kiểm rồi đụng nhau ở khoá — lúc đó người dùng
// phải nhận "mã đã có người dùng", không phải một câu lỗi của MySQL.
func translateChiNhanhErr(err error) error {
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
