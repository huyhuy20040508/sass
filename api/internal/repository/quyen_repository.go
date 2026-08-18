package repository

import (
	"context"
	"errors"

	"gorm.io/gorm"

	"sass-api/internal/domain"
	"sass-api/internal/tenant"
)

type quyenRepository struct{ db *gorm.DB }

// NewQuyenRepository dựng sổ phân quyền trên DATA PLANE (kết nối CÓ bộ lọc tenant).
func NewQuyenRepository(db *gorm.DB) domain.QuyenRepository {
	return &quyenRepository{db: db}
}

// dongQuyen là hình dạng thô của lượt đọc nóng.
//
// Con trỏ ở cả hai cột: LEFT JOIN nên người chưa gán nhóm cho NULL, và NULL
// khác hẳn `false` — cái sau nghĩa là có nhóm nhưng nhóm ấy không toàn quyền.
type dongQuyen struct {
	FullAccess *bool
	Permission *string
}

// BoQuyenCuaNguoi đọc tập quyền của một tài khoản — lượt đọc chạy ở MỌI request
// vào một đường có gắn quyền.
//
// Một lượt đi database. Đi từ `users` sang `permission_groups` rồi `..._items`,
// tất cả trên khoá chính hoặc index phủ.
//
// Người mang nhiều nhóm thì câu này trả về nhiều dòng, và tập quyền là HỢP của
// chúng — cờ full_access chỉ cần MỘT nhóm có là đủ.
//
// DÒNG NGUY HIỂM NHẤT của cả thiết kế là hai điều kiện `tenant_id = ?` trong
// mệnh đề JOIN. tenant.WithoutScope tắt bộ lọc tự động của GORM cho cả câu, nên
// thiếu chúng thì một dòng trỏ chéo cửa hàng (dữ liệu hỏng, hoặc ai đó ghi tay)
// sẽ nạp quyền của TIỆM KHÁC. Có bài kiểm riêng cho đúng chỗ này.
func (r *quyenRepository) BoQuyenCuaNguoi(ctx context.Context, userID, tenantID uint) (domain.BoQuyen, error) {
	ctx = tenant.WithoutScope(ctx, "đọc quyền của tài khoản: điều kiện tenant_id khai tường minh trong câu")

	var rows []dongQuyen
	err := r.db.WithContext(ctx).
		Table("users AS u").
		Joins("LEFT JOIN user_permission_groups ug ON ug.user_id = u.id AND ug.tenant_id = ?", tenantID).
		Joins("LEFT JOIN permission_groups g ON g.id = ug.group_id AND g.tenant_id = ?", tenantID).
		Joins("LEFT JOIN permission_group_items i ON i.group_id = g.id").
		Select("g.full_access AS full_access, i.permission AS permission").
		Where("u.id = ? AND u.tenant_id = ? AND u.deleted_at IS NULL", userID, tenantID).
		Find(&rows).Error
	if err != nil {
		return domain.BoQuyen{}, err
	}

	// Không dòng nào = tài khoản không còn. Trả bộ RỖNG: không quyền nào. Phiên
	// đã bị chặn từ trước bởi KiemPhien, đây chỉ là để không ai lọt qua ngả này.
	toanQuyen := false
	ds := make([]string, 0, len(rows))
	for _, d := range rows {
		if d.FullAccess != nil && *d.FullAccess {
			toanQuyen = true
		}
		if d.Permission != nil {
			ds = append(ds, *d.Permission)
		}
	}

	return domain.NewBoQuyen(toanQuyen, ds), nil
}

func (r *quyenRepository) List(ctx context.Context) ([]domain.NhomQuyen, error) {
	var list []domain.NhomQuyen
	// Nhóm hệ thống đứng trước, rồi tới nhóm cửa hàng tự tạo xếp theo tên.
	err := r.db.WithContext(ctx).
		Order("is_system DESC, full_access DESC, name ASC").
		Find(&list).Error
	if err != nil {
		return nil, err
	}

	return list, nil
}

func (r *quyenRepository) FindByID(ctx context.Context, id uint) (*domain.NhomQuyen, error) {
	var nq domain.NhomQuyen
	err := r.db.WithContext(ctx).First(&nq, id).Error
	if errors.Is(err, gorm.ErrRecordNotFound) {
		return nil, domain.ErrNotFound
	}
	if err != nil {
		return nil, err
	}

	return &nq, nil
}

func (r *quyenRepository) FindByCode(ctx context.Context, code string) (*domain.NhomQuyen, error) {
	var nq domain.NhomQuyen
	err := r.db.WithContext(ctx).Where("code = ?", code).First(&nq).Error
	if errors.Is(err, gorm.ErrRecordNotFound) {
		return nil, domain.ErrNotFound
	}
	if err != nil {
		return nil, err
	}

	return &nq, nil
}

func (r *quyenRepository) ExistsByCode(ctx context.Context, code string, excludeID uint) (bool, error) {
	var count int64
	q := r.db.WithContext(ctx).Model(&domain.NhomQuyen{}).Where("code = ?", code)
	if excludeID > 0 {
		q = q.Where("id <> ?", excludeID)
	}
	err := q.Count(&count).Error

	return count > 0, err
}

func (r *quyenRepository) Create(ctx context.Context, nq *domain.NhomQuyen) error {
	return translateQuyenErr(r.db.WithContext(ctx).Create(nq).Error)
}

func (r *quyenRepository) Update(ctx context.Context, nq *domain.NhomQuyen) error {
	return translateQuyenErr(r.db.WithContext(ctx).Save(nq).Error)
}

// Delete xoá CỨNG. Nhóm còn người mang thì fk_users_permission_group chặn lại và
// translateQuyenErr đổi nó thành ErrNhomQuyenDangDung — tầng nghiệp vụ đếm số
// người rồi nói ra con số đó.
func (r *quyenRepository) Delete(ctx context.Context, id uint) error {
	return translateQuyenErr(r.db.WithContext(ctx).Delete(&domain.NhomQuyen{}, id).Error)
}

func (r *quyenRepository) QuyenCuaNhom(ctx context.Context, groupID uint) ([]string, error) {
	var ds []string
	err := r.db.WithContext(ctx).Model(&domain.NhomQuyenItem{}).
		Where("group_id = ?", groupID).
		Order("permission ASC").
		Pluck("permission", &ds).Error
	if err != nil {
		return nil, err
	}

	return ds, nil
}

// DatQuyenChoNhom thay TOÀN BỘ danh sách quyền của nhóm, trong một giao dịch.
//
// "Xoá sạch rồi ghi lại" thay vì so từng dòng: danh sách chỉ vài chục hàng, còn
// phép so chênh lệch là chỗ đẻ ra lỗi kiểu "bỏ tick mà quyền vẫn còn".
//
// Không phải chép xuống ai cả — quyền nằm ở nhóm, người chỉ trỏ tới nhóm. Đó là
// lý do lượt thu quyền này có hiệu lực ngay ở request kế tiếp của mọi thành viên.
func (r *quyenRepository) DatQuyenChoNhom(ctx context.Context, groupID uint, quyen []string) error {
	tenantID, err := tenantBatBuoc(ctx)
	if err != nil {
		return err
	}

	return r.db.WithContext(ctx).Transaction(func(tx *gorm.DB) error {
		if err := tx.Where("group_id = ?", groupID).
			Delete(&domain.NhomQuyenItem{}).Error; err != nil {
			return translateQuyenErr(err)
		}
		if len(quyen) == 0 {
			return nil
		}

		items := make([]domain.NhomQuyenItem, 0, len(quyen))
		for _, q := range quyen {
			it := domain.NhomQuyenItem{GroupID: groupID, Permission: q}
			it.TenantID = tenantID
			items = append(items, it)
		}

		return translateQuyenErr(tx.Create(&items).Error)
	})
}

func (r *quyenRepository) DemThanhVien(ctx context.Context, groupID uint) (int64, error) {
	var n int64
	err := r.db.WithContext(ctx).Model(&domain.NhomQuyenCuaNguoi{}).
		Where("group_id = ?", groupID).Distinct("user_id").Count(&n).Error

	return n, err
}

func (r *quyenRepository) NhomCuaNguoi(ctx context.Context, userID uint) ([]uint, error) {
	var ds []uint
	err := r.db.WithContext(ctx).Model(&domain.NhomQuyenCuaNguoi{}).
		Where("user_id = ?", userID).
		Order("group_id ASC").
		Pluck("group_id", &ds).Error
	if err != nil {
		return nil, err
	}

	return ds, nil
}

// NhomTheoNguoi đọc nhóm của nhiều người trong một lượt.
func (r *quyenRepository) NhomTheoNguoi(ctx context.Context, userIDs []uint) (map[uint][]uint, error) {
	ra := map[uint][]uint{}
	if len(userIDs) == 0 {
		return ra, nil
	}

	var rows []domain.NhomQuyenCuaNguoi
	err := r.db.WithContext(ctx).
		Where("user_id IN ?", userIDs).
		Order("user_id ASC, group_id ASC").
		Find(&rows).Error
	if err != nil {
		return nil, err
	}
	for _, d := range rows {
		ra[d.UserID] = append(ra[d.UserID], d.GroupID)
	}

	return ra, nil
}

// DatNhomChoNguoi thay TOÀN BỘ danh sách nhóm của một người, trong một giao dịch.
//
// "Xoá sạch rồi ghi lại" thay vì so chênh lệch: một người mang vài nhóm là
// cùng, còn phép so là chỗ đẻ ra lỗi kiểu "bỏ tick mà nhóm vẫn còn".
//
// Danh sách rỗng = thu hết quyền. Khác hẳn khoá tài khoản: người đó vẫn đăng
// nhập được, chỉ là không mở được trang nào.
func (r *quyenRepository) DatNhomChoNguoi(ctx context.Context, userID uint, groupIDs []uint) error {
	tenantID, err := tenantBatBuoc(ctx)
	if err != nil {
		return err
	}

	return r.db.WithContext(ctx).Transaction(func(tx *gorm.DB) error {
		if err := tx.Where("user_id = ?", userID).
			Delete(&domain.NhomQuyenCuaNguoi{}).Error; err != nil {
			return translateQuyenErr(err)
		}
		if len(groupIDs) == 0 {
			return nil
		}

		rows := make([]domain.NhomQuyenCuaNguoi, 0, len(groupIDs))
		for _, gid := range groupIDs {
			r := domain.NhomQuyenCuaNguoi{UserID: userID, GroupID: gid}
			r.TenantID = tenantID
			rows = append(rows, r)
		}

		return translateQuyenErr(tx.Create(&rows).Error)
	})
}

// tenantBatBuoc lấy tenant từ ctx và TỪ CHỐI nếu không có.
//
// DatQuyenChoNhom dựng bản ghi hàng loạt và tự điền tenant_id thay vì nhờ plugin.
// Thiếu tenant mà vẫn ghi thì sinh ra hàng quyền vô chủ — thứ không bao giờ được
// phép tồn tại trong một database dùng chung cho nhiều cửa hàng.
func tenantBatBuoc(ctx context.Context) (uint, error) {
	id, ok := tenant.ID(ctx)
	if !ok {
		return 0, domain.ErrForbidden
	}

	return id, nil
}

// translateQuyenErr chuyển lỗi DB thô sang lỗi nghiệp vụ.
func translateQuyenErr(err error) error {
	switch {
	case err == nil:
		return nil
	case errors.Is(err, gorm.ErrDuplicatedKey):
		return domain.ErrConflict
	// Xoá một nhóm còn người mang: khoá ngoại từ `users` chặn lại. Đây là câu
	// trả lời nghiệp vụ, không phải sự cố.
	case errors.Is(err, gorm.ErrForeignKeyViolated):
		return domain.ErrNhomQuyenDangDung
	default:
		return err
	}
}

// CuaVaoCuaNguoi đọc cửa vào của một tài khoản — xem QuyenRepository.
//
// Cùng lối với BoQuyenCuaNguoi: tắt bộ lọc tenant tự động rồi KHAI TƯỜNG MINH
// `tenant_id = ?`. Đây là câu hỏi về bảo mật nên nó không được phụ thuộc vào
// việc ctx có mang tenant hay chưa.
func (r *quyenRepository) CuaVaoCuaNguoi(ctx context.Context, userID, tenantID uint) (string, uint, error) {
	ctx = tenant.WithoutScope(ctx, "đọc cửa vào của tài khoản: điều kiện tenant_id khai tường minh trong câu")

	var dong struct {
		AccessAreas *string
		RoleID      uint
	}
	err := r.db.WithContext(ctx).
		Table("users").
		Select("access_areas, role_id").
		Where("id = ? AND tenant_id = ? AND deleted_at IS NULL", userID, tenantID).
		Take(&dong).Error
	if err != nil {
		// Không còn dòng nào = tài khoản đã xoá. Trả rỗng (không cửa nào) thay vì
		// lỗi: phiên đã bị chặn từ trước bởi KiemPhien, đây chỉ là để không ai lọt
		// qua ngả này.
		if errors.Is(err, gorm.ErrRecordNotFound) {
			return "", 0, nil
		}

		return "", 0, err
	}

	if dong.AccessAreas == nil {
		return "", dong.RoleID, nil
	}

	return *dong.AccessAreas, dong.RoleID, nil
}
