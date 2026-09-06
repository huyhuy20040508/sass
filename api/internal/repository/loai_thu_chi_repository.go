package repository

import (
	"context"
	"errors"
	"strings"

	"gorm.io/gorm"

	"sass-api/internal/domain"
)

type loaiThuChiRepository struct{ db *gorm.DB }

func NewLoaiThuChiRepository(db *gorm.DB) domain.LoaiThuChiRepository {
	return &loaiThuChiRepository{db: db}
}

// List xếp CŨ TRƯỚC (id tăng dần) — ngược với đơn vị tính.
//
// Lý do: bảng này gieo sẵn một danh sách khởi điểm lúc mở cửa hàng, và người
// dùng đọc nó như một danh mục cố định chứ không như một sổ ghi việc mới. Xếp
// mới nhất lên đầu thì mỗi lần thêm một dòng là cả danh sách nhảy chỗ.
func (r *loaiThuChiRepository) List(ctx context.Context, f domain.LoaiThuChiFilter) ([]domain.LoaiThuChi, error) {
	q := r.db.WithContext(ctx).Order("id ASC")
	if f.Type != nil {
		q = q.Where("type = ?", *f.Type)
	}
	if kw := strings.TrimSpace(f.Keyword); kw != "" {
		q = q.Where("name LIKE ?", "%"+kw+"%")
	}

	var list []domain.LoaiThuChi
	if err := q.Find(&list).Error; err != nil {
		return nil, err
	}

	return list, nil
}

func (r *loaiThuChiRepository) FindByID(ctx context.Context, id uint) (*domain.LoaiThuChi, error) {
	var l domain.LoaiThuChi
	err := r.db.WithContext(ctx).First(&l, id).Error
	if errors.Is(err, gorm.ErrRecordNotFound) {
		return nil, domain.ErrNotFound
	}
	if err != nil {
		return nil, err
	}

	return &l, nil
}

// ExistsByName so bằng LOWER(...) COLLATE utf8mb4_bin, cùng luật với đơn vị tính:
// đối chiếu mặc định của cột bỏ qua CẢ hoa thường LẪN dấu nên "Thuê" và "Thue"
// bị coi là một. Ép utf8mb4_bin thì so từng byte (phân biệt dấu), LOWER() ở hai
// vế trả lại phần bỏ qua hoa thường.
//
// KHÔNG Unscoped: dòng đã xoá mềm không giữ chỗ, xoá "Tiền điện" rồi khai lại
// đúng tên ấy là việc bình thường. Bảng cũng cố ý không có khoá duy nhất dưới
// database vì lý do đó — xem migration 0057.
func (r *loaiThuChiRepository) ExistsByName(ctx context.Context, loai uint8, name string, excludeID uint) (bool, error) {
	var count int64
	q := r.db.WithContext(ctx).Model(&domain.LoaiThuChi{}).
		Where("type = ?", loai).
		Where("LOWER(name) COLLATE utf8mb4_bin = LOWER(?)", name)
	if excludeID > 0 {
		q = q.Where("id <> ?", excludeID)
	}
	err := q.Count(&count).Error

	return count > 0, err
}

func (r *loaiThuChiRepository) Create(ctx context.Context, l *domain.LoaiThuChi) error {
	return translateLoaiThuChiErr(r.db.WithContext(ctx).Create(l).Error)
}

func (r *loaiThuChiRepository) Update(ctx context.Context, l *domain.LoaiThuChi) error {
	return translateLoaiThuChiErr(r.db.WithContext(ctx).Save(l).Error)
}

// Delete xoá mềm: phiếu thu chi lập trước đó vẫn phải tra ra được tên loại của
// nó. Khi màn phiếu thu chi có rồi thì thêm lượt đếm phiếu ở đây để chặn xoá
// loại đang được dùng, giống nhà cung cấp.
func (r *loaiThuChiRepository) Delete(ctx context.Context, id uint) error {
	return translateLoaiThuChiErr(r.db.WithContext(ctx).Delete(&domain.LoaiThuChi{}, id).Error)
}

func translateLoaiThuChiErr(err error) error {
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
