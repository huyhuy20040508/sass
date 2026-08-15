package repository

import (
	"context"
	"errors"
	"strings"
	"time"

	"gorm.io/gorm"
	"gorm.io/gorm/clause"

	"sass-api/internal/domain"
)

// planRepository đọc/ghi BẢNG GIÁ của CONTROL PLANE.
//
// Kết nối truyền vào PHẢI là repository.NewPlatformDB. Không có cách nào để
// trình biên dịch ép điều đó — cả hai đều là *gorm.DB — nên nó được nhắc ở tên
// hàm khởi tạo và ở đây. Đưa nhầm kết nối data plane vào thì bảng `plans` không
// tồn tại bên đó và mọi lượt gọi đều lỗi; ồn ào, nên nhầm ở đây dễ thấy hơn
// nhầm ở tenantDomainRepository (bên kia cũng có bảng `tenants`).
//
// KHÔNG có bộ lọc tenant trên kết nối này, đúng bản chất: bảng giá là của nền
// tảng, không thuộc cửa hàng nào. Vì vậy MỌI đường dẫn tới đây phải tự canh
// quyền — xem middleware.XacThucNenTang.
type planRepository struct{ db *gorm.DB }

// NewPlanRepository nhận kết nối CONTROL PLANE (NewPlatformDB).
func NewPlanRepository(platformDB *gorm.DB) domain.PlanRepository {
	return &planRepository{db: platformDB}
}

// truyVanBangGia dựng câu đọc bảng giá kèm app của từng dòng.
//
// JOIN được phép ở đây vì cả hai bảng nằm trong CÙNG lược đồ control plane —
// khác hẳn việc ghép dữ liệu hai plane, thứ luôn phải làm bằng tay ở tầng Go.
//
// Sắp theo (a.code, p.id): thứ tự id trong một app chính là thứ tự bảng giá
// trên landing (xem migration 0003), nên màn hình khỏi phải tự sắp lại.
func (r *planRepository) truyVanBangGia(ctx context.Context) *gorm.DB {
	return r.db.WithContext(ctx).
		Table("plans AS p").
		Joins("JOIN apps a ON a.id = p.app_id").
		Select("p.*, a.code AS app_code, a.name AS app_name, a.status AS app_status").
		Order("a.code, p.id")
}

func (r *planRepository) List(ctx context.Context, appCode string) ([]domain.PlanWithApp, error) {
	q := r.truyVanBangGia(ctx)
	if appCode != "" {
		q = q.Where("a.code = ?", appCode)
	}

	// Không lọc theo status: dòng 'retired' vẫn phải hiện trong khu điều hành —
	// thuê bao cũ còn tra tên gói ở đó, và người điều hành cần thấy nó để biết vì
	// sao một khách đang ở gói không còn bán nữa.
	var rows []domain.PlanWithApp
	if err := q.Scan(&rows).Error; err != nil {
		return nil, err
	}

	return rows, nil
}

func (r *planRepository) Find(ctx context.Context, id uint) (*domain.PlanWithApp, error) {
	var row domain.PlanWithApp
	err := r.truyVanBangGia(ctx).Where("p.id = ?", id).Limit(1).Scan(&row).Error
	if err != nil {
		return nil, err
	}
	// Scan KHÔNG trả ErrRecordNotFound khi không có dòng nào (khác First) — nó để
	// nguyên struct rỗng. Thiếu bước này thì id không tồn tại sẽ ra một gói id 0
	// tên rỗng, và màn hình hiện một dòng trắng thay vì báo không tìm thấy.
	if row.ID == 0 {
		return nil, domain.ErrNotFound
	}

	return &row, nil
}

func (r *planRepository) Features(ctx context.Context, planID uint) (map[string]string, error) {
	var rows []domain.PlanFeature
	if err := r.db.WithContext(ctx).
		Where("plan_id = ?", planID).
		Order("feature_key").
		Find(&rows).Error; err != nil {
		return nil, err
	}

	out := make(map[string]string, len(rows))
	for _, row := range rows {
		out[row.Key] = row.Value
	}

	return out, nil
}

// FeaturesOf đọc điều khoản của nhiều gói bằng MỘT câu truy vấn.
//
// Màn hình danh sách gọi hàm này thay vì Features trong vòng lặp: bảng giá hôm
// nay có ba dòng nên khác biệt không đo được, nhưng vòng lặp truy vấn là thứ
// không ai sửa lại cho tới ngày nó chậm.
//
// Gói không có dòng nào KHÔNG có mặt trong map trả về — "không có dòng" là một
// trạng thái có nghĩa (bảng giá không quy định), không phải map rỗng cần bịa ra.
func (r *planRepository) FeaturesOf(ctx context.Context, planIDs []uint) (map[uint]map[string]string, error) {
	out := make(map[uint]map[string]string, len(planIDs))
	if len(planIDs) == 0 {
		return out, nil
	}

	var rows []domain.PlanFeature
	if err := r.db.WithContext(ctx).
		Where("plan_id IN ?", planIDs).
		Order("plan_id, feature_key").
		Find(&rows).Error; err != nil {
		return nil, err
	}

	for _, row := range rows {
		if out[row.PlanID] == nil {
			out[row.PlanID] = map[string]string{}
		}
		out[row.PlanID][row.Key] = row.Value
	}

	return out, nil
}

// SaveFeatures ghi và xoá trong MỘT giao dịch.
//
// Vì sao phải là giao dịch dù chỉ vài dòng: một lượt lưu của màn hình Tính năng
// gói thường vừa đặt khoá này vừa xoá khoá kia (bỏ trống ô = xoá). Chạy nửa
// chừng rồi hỏng thì gói đó mang hạn mức nửa mới nửa cũ — trạng thái chưa bao
// giờ được ai duyệt, và không có gì trên màn hình nói rằng nó đang như vậy.
func (r *planRepository) SaveFeatures(ctx context.Context, planID uint, dat map[string]string, xoa []string) error {
	if len(dat) == 0 && len(xoa) == 0 {
		return nil
	}

	return r.db.WithContext(ctx).Transaction(func(tx *gorm.DB) error {
		if len(xoa) > 0 {
			if err := tx.Where("plan_id = ? AND feature_key IN ?", planID, xoa).
				Delete(&domain.PlanFeature{}).Error; err != nil {
				return err
			}
		}
		if len(dat) == 0 {
			return nil
		}

		rows := make([]domain.PlanFeature, 0, len(dat))
		for key, value := range dat {
			rows = append(rows, domain.PlanFeature{PlanID: planID, Key: key, Value: value})
		}

		// Khoá thật là (plan_id, feature_key). MySQL không nhận đích xung đột nên
		// GORM bỏ qua danh sách Columns và để database tự bắt theo khoá unique nào
		// vỡ; vẫn khai đủ cho khớp lược đồ, khai thiếu thì người đọc tưởng một khoá
		// chỉ tồn tại một lần trong cả bảng.
		return tx.Clauses(clause.OnConflict{
			Columns:   []clause.Column{{Name: "plan_id"}, {Name: "feature_key"}},
			DoUpdates: clause.AssignmentColumns([]string{"value", "updated_at"}),
		}).Create(&rows).Error
	})
}

// Sua ghi các cột thương mại của MỘT dòng bảng giá.
//
// Dùng Updates(map) chứ không Save(struct) vì hai lý do, và cả hai đều là lỗi
// im lặng nếu làm cách kia:
//
//   - Save() ghi TOÀN BỘ cột, kể cả app_id/code/billing_cycle lấy từ struct
//     trong bộ nhớ. Một lượt sửa giá khi đó có quyền đổi danh tính của dòng.
//   - GORM bỏ qua trường zero khi cập nhật bằng struct, nên `price = nil`
//     ("Liên hệ") và `trial_days = 0` ("không cho dùng thử") sẽ không được ghi —
//     màn hình báo đã lưu còn database giữ nguyên giá cũ.
//
// KHÔNG coi RowsAffected == 0 là lỗi: MySQL trả 0 khi giá trị mới trùng y hệt
// giá trị cũ, và bấm Lưu mà không đổi gì thì không phải hỏng. Việc "gói có tồn
// tại không" đã do service hỏi bằng Find trước đó.
func (r *planRepository) Sua(ctx context.Context, id uint, dat domain.SuaPlan) error {
	// Tagline rỗng ghi NULL chứ không ghi chuỗi rỗng: cột đó NULL-able và các
	// dòng do migration tạo ra đang để NULL khi không có mô tả. Hai cách viết
	// cùng một ý nghĩa trong một cột là thứ mọi câu truy vấn sau này phải nhớ xử
	// lý cả hai.
	var tagline any
	if s := strings.TrimSpace(dat.Tagline); s != "" {
		tagline = s
	}

	return r.db.WithContext(ctx).
		Model(&domain.Plan{}).
		Where("id = ?", id).
		Updates(map[string]any{
			"name":       strings.TrimSpace(dat.Name),
			"tagline":    tagline,
			"price":      dat.Price,
			"trial_days": dat.TrialDays,
			"status":     dat.Status,
			"updated_at": time.Now(),
		}).Error
}

// platformUserRepository tra người điều hành nền tảng. Cũng chạy trên CONTROL
// PLANE (NewPlatformDB).
type platformUserRepository struct{ db *gorm.DB }

// NewPlatformUserRepository nhận kết nối CONTROL PLANE (NewPlatformDB).
func NewPlatformUserRepository(platformDB *gorm.DB) domain.PlatformUserRepository {
	return &platformUserRepository{db: platformDB}
}

// conSong lọc ra những dòng CÒN DÙNG ĐƯỢC: chưa xoá mềm và đang hoạt động.
//
// Hai điều kiện luôn đi cùng nhau ở mọi lượt tra, nên chúng nằm chung một chỗ:
// tách ra là sớm muộn có một câu truy vấn quên mất một vế, và cái quên đó cho
// người vừa bị khoá đi tiếp.
func (r *platformUserRepository) conSong(ctx context.Context) *gorm.DB {
	return r.db.WithContext(ctx).
		Where("status = ?", "active").
		Where("deleted_at IS NULL")
}

func (r *platformUserRepository) FindByEmail(ctx context.Context, email string) (*domain.PlatformUser, error) {
	email = strings.ToLower(strings.TrimSpace(email))
	if email == "" {
		return nil, domain.ErrNotFound
	}

	var u domain.PlatformUser
	err := r.conSong(ctx).Where("email = ?", email).First(&u).Error
	if errors.Is(err, gorm.ErrRecordNotFound) {
		return nil, domain.ErrNotFound
	}
	if err != nil {
		return nil, err
	}

	return &u, nil
}

func (r *platformUserRepository) FindByID(ctx context.Context, id uint) (*domain.PlatformUser, error) {
	if id == 0 {
		return nil, domain.ErrNotFound
	}

	var u domain.PlatformUser
	err := r.conSong(ctx).Where("id = ?", id).First(&u).Error
	if errors.Is(err, gorm.ErrRecordNotFound) {
		return nil, domain.ErrNotFound
	}
	if err != nil {
		return nil, err
	}

	return &u, nil
}

// QuyenApp đọc tập phần mềm được giao.
//
// owner đi tắt, KHÔNG hỏi database: họ nhìn mọi phần mềm theo định nghĩa, kể cả
// phần mềm vừa thêm vào danh mục vài giây trước — mà một bảng gán thì không thể
// biết trước điều đó. Xem migration 0010.
func (r *platformUserRepository) QuyenApp(ctx context.Context, nguoi *domain.PlatformUser) (domain.QuyenApp, error) {
	if nguoi == nil {
		return domain.QuyenApp{}, domain.ErrNotFound
	}
	if nguoi.Role == domain.PlatformRoleOwner {
		return domain.QuyenApp{ToanQuyen: true}, nil
	}

	var ma []string
	err := r.db.WithContext(ctx).
		Table("platform_user_apps AS ua").
		Joins("JOIN apps a ON a.id = ua.app_id").
		Where("ua.platform_user_id = ?", nguoi.ID).
		Order("a.code").
		Pluck("a.code", &ma).Error
	if err != nil {
		return domain.QuyenApp{}, err
	}

	// Danh sách rỗng KHÔNG được nâng thành toàn quyền. Đây là chỗ một dòng "tiện
	// tay" (`if len(ma) == 0 { toàn quyền }`) sẽ biến người vừa thêm vào sổ thành
	// người sửa được bảng giá của mọi sản phẩm.
	return domain.QuyenApp{Ma: ma}, nil
}

// GhiLanDangNhap cập nhật đúng một cột.
//
// Không Save() cả bản ghi: bản ghi đó vừa được đọc ra để so mật khẩu, ghi lại
// toàn bộ nghĩa là một lượt đăng nhập cũng có thể ghi đè vai trò hay trạng thái
// bằng giá trị đã cũ vài mili giây.
func (r *platformUserRepository) GhiLanDangNhap(ctx context.Context, id uint) error {
	return r.db.WithContext(ctx).
		Model(&domain.PlatformUser{}).
		Where("id = ?", id).
		UpdateColumn("last_login_at", time.Now()).Error
}
