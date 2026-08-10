package repository

import (
	"context"
	"errors"
	"fmt"
	"strconv"
	"strings"
	"time"

	"gorm.io/gorm"

	"sass-api/internal/domain"
)

type supplierRepository struct{ db *gorm.DB }

func NewSupplierRepository(db *gorm.DB) domain.SupplierRepository {
	return &supplierRepository{db: db}
}

func (r *supplierRepository) List(ctx context.Context, f domain.SupplierFilter) ([]domain.Supplier, error) {
	q := r.db.WithContext(ctx).Order("name ASC")
	if f.OnlyActive {
		q = q.Where("is_active = ?", true)
	}
	if kw := strings.TrimSpace(f.Keyword); kw != "" {
		like := "%" + kw + "%"
		q = q.Where("(name LIKE ? OR code LIKE ? OR phone LIKE ? OR contact_name LIKE ?)", like, like, like, like)
	}

	var list []domain.Supplier
	if err := q.Find(&list).Error; err != nil {
		return nil, err
	}
	if err := r.fillPurchaseStats(ctx, list); err != nil {
		return nil, err
	}
	return list, nil
}

func (r *supplierRepository) FindByID(ctx context.Context, id uint) (*domain.Supplier, error) {
	var s domain.Supplier
	err := r.db.WithContext(ctx).First(&s, id).Error
	if errors.Is(err, gorm.ErrRecordNotFound) {
		return nil, domain.ErrNotFound
	}
	if err != nil {
		return nil, err
	}
	one := []domain.Supplier{s}
	if err := r.fillPurchaseStats(ctx, one); err != nil {
		return nil, err
	}
	return &one[0], nil
}

// fillPurchaseStats tổng hợp phiếu đặt hàng theo supplier_id bằng MỘT truy vấn
// gộp rồi gán vào từng phần tử (tránh N+1 khi danh sách nhà cung cấp dài).
//
// Số PHIẾU đếm tất cả (kể cả nháp/huỷ) vì nó dùng để chặn xoá — còn phiếu nào
// trỏ tới thì không được xoá. Ba số tiền/ngày còn lại loại phiếu nháp và phiếu
// huỷ: nháp chưa đặt thật, huỷ thì không còn nợ ai.
func (r *supplierRepository) fillPurchaseStats(ctx context.Context, list []domain.Supplier) error {
	if len(list) == 0 {
		return nil
	}

	ids := make([]uint, 0, len(list))
	for _, s := range list {
		ids = append(ids, s.ID)
	}

	var rows []struct {
		SupplierID  uint
		Total       int64
		Amount      float64
		Debt        float64
		LastOrderAt *time.Time
	}
	skip := []any{domain.PurchaseStatusDraft, domain.PurchaseStatusCancelled}
	args := append(append(append([]any{}, skip...), skip...), skip...)
	// Model(&domain.PurchaseOrder{}) để GORM tự thêm điều kiện deleted_at IS NULL.
	if err := r.db.WithContext(ctx).
		Model(&domain.PurchaseOrder{}).
		Select(`supplier_id,
			COUNT(*) AS total,
			COALESCE(SUM(CASE WHEN status NOT IN (?, ?) THEN total_amount ELSE 0 END), 0) AS amount,
			COALESCE(SUM(CASE WHEN status NOT IN (?, ?) THEN GREATEST(total_amount - paid_amount, 0) ELSE 0 END), 0) AS debt,
			MAX(CASE WHEN status NOT IN (?, ?) THEN COALESCE(ordered_at, created_at) END) AS last_order_at`, args...).
		Where("supplier_id IN ?", ids).
		Group("supplier_id").
		Scan(&rows).Error; err != nil {
		return err
	}

	byID := make(map[uint]int, len(rows))
	for i, row := range rows {
		byID[row.SupplierID] = i
	}
	for i := range list {
		row, ok := byID[list[i].ID]
		if !ok {
			continue
		}
		list[i].PurchaseCount = rows[row].Total
		list[i].PurchaseAmount = rows[row].Amount
		list[i].DebtAmount = rows[row].Debt
		list[i].LastOrderAt = rows[row].LastOrderAt
	}
	return nil
}

// ExistsByCode dùng Unscoped: mã của nhà cung cấp đã xoá mềm vẫn giữ chỗ trong
// UNIQUE index, báo trùng ở đây thân thiện hơn là để MySQL ném lỗi khi ghi.
func (r *supplierRepository) ExistsByCode(ctx context.Context, code string, excludeID uint) (bool, error) {
	var count int64
	q := r.db.WithContext(ctx).Unscoped().Model(&domain.Supplier{}).Where("code = ?", code)
	if excludeID > 0 {
		q = q.Where("id <> ?", excludeID)
	}
	err := q.Count(&count).Error
	return count > 0, err
}

// NextCode sinh mã kế tiếp dạng NCC001.
//
// Lấy số lớn nhất trong các mã ĐÚNG KHUÔN NCC + chữ số rồi cộng một, tính cả
// dòng đã xoá mềm — mã cũ vẫn chiếm chỗ trong UNIQUE index. Mã do người dùng tự
// đặt (khuôn khác) không tham gia, nên đặt tay xen kẽ cũng không làm hỏng dãy.
func (r *supplierRepository) NextCode(ctx context.Context) (string, error) {
	var codes []string
	if err := r.db.WithContext(ctx).Unscoped().
		Model(&domain.Supplier{}).
		Where("code REGEXP ?", "^NCC[0-9]+$").
		Pluck("code", &codes).Error; err != nil {
		return "", err
	}

	max := 0
	for _, c := range codes {
		if n, err := strconv.Atoi(strings.TrimPrefix(c, "NCC")); err == nil && n > max {
			max = n
		}
	}
	return fmt.Sprintf("NCC%03d", max+1), nil
}

func (r *supplierRepository) Create(ctx context.Context, s *domain.Supplier) error {
	return translateSupplierErr(r.db.WithContext(ctx).Create(s).Error)
}

func (r *supplierRepository) Update(ctx context.Context, s *domain.Supplier) error {
	return translateSupplierErr(r.db.WithContext(ctx).Save(s).Error)
}

// Delete xoá mềm nhà cung cấp, nhưng chỉ khi không còn phiếu đặt hàng nào trỏ
// tới: khoá ngoại đặt ON DELETE SET NULL nên xoá cứng sẽ âm thầm cắt đứt đầu mối
// liên hệ của mọi phiếu cũ. Còn phiếu thì nên tắt "đang hợp tác" thay vì xoá.
func (r *supplierRepository) Delete(ctx context.Context, id uint) error {
	var count int64
	if err := r.db.WithContext(ctx).Model(&domain.PurchaseOrder{}).
		Where("supplier_id = ?", id).Count(&count).Error; err != nil {
		return err
	}
	if count > 0 {
		return domain.ErrConflict
	}
	return translateSupplierErr(r.db.WithContext(ctx).Delete(&domain.Supplier{}, id).Error)
}

// translateSupplierErr chuyển lỗi DB thô sang lỗi nghiệp vụ để handler trả mã
// HTTP thân thiện.
func translateSupplierErr(err error) error {
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
