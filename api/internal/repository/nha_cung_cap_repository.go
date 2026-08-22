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

type nhaCungCapRepository struct{ db *gorm.DB }

func NewNhaCungCapRepository(db *gorm.DB) domain.NhaCungCapRepository {
	return &nhaCungCapRepository{db: db}
}

func (r *nhaCungCapRepository) List(ctx context.Context, f domain.NhaCungCapFilter) ([]domain.NhaCungCap, error) {
	// Mới thêm lên đầu, cùng thứ tự với các danh mục khác; Shop Admin tự sắp lại
	// theo lựa chọn của người xem.
	q := r.db.WithContext(ctx).Order("id DESC")
	if f.OnlyActive {
		q = q.Where("is_active = ?", true)
	}
	if kw := strings.TrimSpace(f.Keyword); kw != "" {
		like := "%" + kw + "%"
		q = q.Where("(name LIKE ? OR code LIKE ? OR short_name LIKE ? OR phone LIKE ? OR tax_code LIKE ?)",
			like, like, like, like, like)
	}

	var list []domain.NhaCungCap
	if err := q.Find(&list).Error; err != nil {
		return nil, err
	}

	return list, r.ThongKeMua(ctx, list)
}

func (r *nhaCungCapRepository) FindByID(ctx context.Context, id uint) (*domain.NhaCungCap, error) {
	var ncc domain.NhaCungCap
	err := r.db.WithContext(ctx).First(&ncc, id).Error
	if errors.Is(err, gorm.ErrRecordNotFound) {
		return nil, domain.ErrNotFound
	}
	if err != nil {
		return nil, err
	}

	mot := []domain.NhaCungCap{ncc}
	if err := r.ThongKeMua(ctx, mot); err != nil {
		return nil, err
	}

	return &mot[0], nil
}

// ExistsByCode dùng Unscoped: mã của bên đã xoá mềm vẫn giữ chỗ trong UNIQUE
// index, báo trùng ở đây thân thiện hơn là để MySQL ném lỗi lúc ghi.
func (r *nhaCungCapRepository) ExistsByCode(ctx context.Context, code string, excludeID uint) (bool, error) {
	var count int64
	q := r.db.WithContext(ctx).Unscoped().Model(&domain.NhaCungCap{}).Where("code = ?", code)
	if excludeID > 0 {
		q = q.Where("id <> ?", excludeID)
	}
	err := q.Count(&count).Error

	return count > 0, err
}

// NextCode sinh mã kế tiếp dạng NCC001.
//
// Chỉ đọc các mã ĐÚNG KHUÔN NCC + chữ số, tính cả dòng đã xoá mềm; mã người dùng
// tự gõ (ANBINH, VIETTIEN…) không tham gia nên khai tay xen kẽ không làm hỏng dãy.
func (r *nhaCungCapRepository) NextCode(ctx context.Context) (string, error) {
	var codes []string
	if err := r.db.WithContext(ctx).Unscoped().
		Model(&domain.NhaCungCap{}).
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

func (r *nhaCungCapRepository) Create(ctx context.Context, ncc *domain.NhaCungCap) error {
	return translateNhaCungCapErr(r.db.WithContext(ctx).Create(ncc).Error)
}

func (r *nhaCungCapRepository) Update(ctx context.Context, ncc *domain.NhaCungCap) error {
	return translateNhaCungCapErr(r.db.WithContext(ctx).Save(ncc).Error)
}

// Delete xoá mềm. Service đã chặn bên còn phiếu đặt hàng trước khi gọi vào đây.
func (r *nhaCungCapRepository) Delete(ctx context.Context, id uint) error {
	return translateNhaCungCapErr(r.db.WithContext(ctx).Delete(&domain.NhaCungCap{}, id).Error)
}

// thongKeDong — một dòng kết quả gộp của phiếu đặt hàng.
type thongKeDong struct {
	KhoaID  uint
	KhoaTen string
	So      int64
	Tong    float64
	DaTra   float64
	LanCuoi *time.Time
}

// Phiếu nháp và phiếu đã huỷ không tính tiền: nháp chưa đặt thật, huỷ thì không
// còn nợ ai. Số PHIẾU thì đếm hết vì nó là căn cứ chặn xoá.
const congThucTien = `COUNT(*) AS so,
	SUM(CASE WHEN status IN ('draft','cancelled') THEN 0 ELSE total_amount END) AS tong,
	SUM(CASE WHEN status IN ('draft','cancelled') THEN 0 ELSE paid_amount END) AS da_tra,
	MAX(CASE WHEN status IN ('draft','cancelled') THEN NULL ELSE created_at END) AS lan_cuoi`

// ThongKeMua gộp số liệu phiếu mua cho cả trang bằng HAI câu GROUP BY.
//
// Phải hai câu vì phiếu lập trong quãng danh mục bị gỡ (migration 0038 → 0039)
// chỉ còn `supplier_name`, không có `supplier_id` để trỏ về. Câu thứ hai vét
// đúng nhóm đó theo TÊN, nên số liệu cũ vẫn hiện ra thay vì về 0.
func (r *nhaCungCapRepository) ThongKeMua(ctx context.Context, list []domain.NhaCungCap) error {
	if len(list) == 0 {
		return nil
	}

	ids := make([]uint, 0, len(list))
	names := make([]string, 0, len(list))
	for _, ncc := range list {
		ids = append(ids, ncc.ID)
		if ten := strings.TrimSpace(ncc.Name); ten != "" {
			names = append(names, ten)
		}
	}

	theoID := make([]thongKeDong, 0, len(ids))
	err := r.db.WithContext(ctx).Model(&domain.PurchaseOrder{}).
		Select("supplier_id AS khoa_id, "+congThucTien).
		Where("supplier_id IN ?", ids).
		Group("supplier_id").
		Scan(&theoID).Error
	if err != nil {
		return err
	}

	theoTen := make([]thongKeDong, 0, len(names))
	if len(names) > 0 {
		err = r.db.WithContext(ctx).Model(&domain.PurchaseOrder{}).
			Select("supplier_name AS khoa_ten, "+congThucTien).
			Where("supplier_id IS NULL AND supplier_name IN ?", names).
			Group("supplier_name").
			Scan(&theoTen).Error
		if err != nil {
			return err
		}
	}

	bangID := make(map[uint]thongKeDong, len(theoID))
	for _, d := range theoID {
		bangID[d.KhoaID] = d
	}
	// Khoá theo tên viết thường: đối chiếu của cột bỏ qua hoa thường nên MySQL đã
	// gộp "An Bình" với "AN BÌNH" vào một dòng, Go phải tra lại cùng kiểu.
	bangTen := make(map[string]thongKeDong, len(theoTen))
	for _, d := range theoTen {
		bangTen[strings.ToLower(strings.TrimSpace(d.KhoaTen))] = d
	}

	for i := range list {
		gop(&list[i], bangID[list[i].ID])
		gop(&list[i], bangTen[strings.ToLower(strings.TrimSpace(list[i].Name))])
	}

	return nil
}

// gop cộng một dòng thống kê vào nhà cung cấp; còn nợ luôn kẹp về không âm — trả
// dư (đặt cọc rồi huỷ bớt hàng) là chuyện của sổ quỹ, không phải nợ âm ở đây.
func gop(ncc *domain.NhaCungCap, d thongKeDong) {
	if d.So == 0 && d.Tong == 0 && d.DaTra == 0 && d.LanCuoi == nil {
		return
	}

	ncc.PurchaseCount += d.So
	ncc.TotalPurchases += d.Tong
	ncc.PaidAmount += d.DaTra
	if con := ncc.TotalPurchases - ncc.PaidAmount; con > 0 {
		ncc.DebtAmount = con
	} else {
		ncc.DebtAmount = 0
	}
	if d.LanCuoi != nil && (ncc.LastOrderAt == nil || d.LanCuoi.After(*ncc.LastOrderAt)) {
		ncc.LastOrderAt = d.LanCuoi
	}
}

func translateNhaCungCapErr(err error) error {
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
