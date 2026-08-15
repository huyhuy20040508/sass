package repository

import (
	"context"
	"database/sql"
	"errors"
	"time"

	"gorm.io/gorm"
	"gorm.io/gorm/clause"

	"sass-api/internal/domain"
)

// hopDongRepository GHI vào sổ hợp đồng của CONTROL PLANE.
//
// Kết nối truyền vào PHẢI là repository.NewPlatformDB — cùng ràng buộc với
// khachHangRepository, và ở đây hậu quả của việc đưa nhầm nặng hơn: bảng
// `tenants` có mặt ở CẢ HAI lược đồ, nên một câu UPSERT chạy nhầm sang data
// plane vẫn thành công và sẽ ghi đè tên của một cửa hàng đang chạy.
//
// KHÔNG hàm nào ở đây tự xét phạm vi phần mềm. Đó là chủ ý: phạm vi cần biết mã
// app của hợp đồng, mà biết được điều đó thì đã phải đọc hợp đồng lên rồi — nên
// chốt chặn nằm ở service, nơi có sẵn cả `quyen` lẫn dòng vừa đọc. Đừng gọi
// thẳng repository này từ handler.
type hopDongRepository struct{ db *gorm.DB }

// NewHopDongRepository nhận kết nối CONTROL PLANE (NewPlatformDB).
func NewHopDongRepository(platformDB *gorm.DB) domain.HopDongRepository {
	return &hopDongRepository{db: platformDB}
}

// UpsertKhachHang chép cửa hàng sang sổ nền tảng.
//
// KHÔNG đụng `status` khi dòng đã có: cột đó là quyết định của khu điều hành (mở
// / khoá), còn lượt ghi này chỉ đang chép danh tính và cách liên lạc sang. Khách
// đang bị khoá mà một lượt ký hợp đồng mở khoá hộ thì đó là thay đổi không ai
// yêu cầu — và là thay đổi cho khách đăng nhập lại được.
//
// Dòng mới vẫn cần `status`, nên nó nằm trong bộ giá trị INSERT, chỉ là không
// nằm trong danh sách cột được cập nhật khi trùng.
func (r *hopDongRepository) UpsertKhachHang(ctx context.Context, kh domain.PlatformTenant) error {
	if kh.ID == 0 {
		// Bảng này KHÔNG auto-increment: id là số chung với data plane. Để 0 lọt
		// xuống thì MySQL nhận đúng id 0 và mọi hợp đồng sau đó trỏ vào một khách
		// không tồn tại.
		return errors.New("upsert khách hàng vào sổ nền tảng mà thiếu id của data plane")
	}

	return r.db.WithContext(ctx).
		Clauses(clause.OnConflict{
			Columns: []clause.Column{{Name: "id"}},
			DoUpdates: clause.AssignmentColumns([]string{
				"code", "name", "contact_name", "contact_phone", "contact_email", "updated_at",
			}),
		}).
		Create(&kh).Error
}

func (r *hopDongRepository) Tao(ctx context.Context, s *domain.Subscription) error {
	err := r.db.WithContext(ctx).Create(s).Error

	// uq_subscriptions_current (tenant_id, app_id, current_mark) là khoá duy nhất
	// duy nhất của bảng, nên "trùng khoá" ở đây chỉ có đúng một nghĩa. Trả lỗi
	// nghiệp vụ thay vì lỗi thô: người bấm nút cần biết phải gia hạn hay huỷ hợp
	// đồng cũ, chứ tên một khoá MySQL thì không nói được gì cho họ.
	if errors.Is(err, gorm.ErrDuplicatedKey) {
		return domain.ErrHopDongDangChay
	}

	return err
}

func (r *hopDongRepository) Tim(ctx context.Context, id uint) (*domain.HopDongDayDu, error) {
	var hd domain.HopDongDayDu
	err := r.db.WithContext(ctx).
		Table("subscriptions AS s").
		Joins("JOIN tenants t ON t.id = s.tenant_id").
		Joins("JOIN apps a ON a.id = s.app_id").
		// LEFT JOIN chỉ để lấy TÊN gói — xem chú thích cùng câu bên
		// khachHangRepository.HopDong.
		Joins("LEFT JOIN plans p ON p.id = s.plan_id").
		Select(`s.*, t.code AS ma_cua_hang, t.name AS ten_cua_hang,
		        t.contact_name AS nguoi_lien_he, t.contact_phone AS dien_thoai,
		        t.contact_email AS email,
		        a.code AS ma_app, a.name AS ten_app, p.name AS ten_goi,
		        t.note AS ghi_chu_khach, t.created_at AS ngay_vao_so,
		        t.status AS trang_thai_cua_hang`).
		Where("s.id = ?", id).
		Take(&hd).Error
	if errors.Is(err, gorm.ErrRecordNotFound) {
		return nil, domain.ErrNotFound
	}
	if err != nil {
		return nil, err
	}

	return &hd, nil
}

// GiaHan đẩy hạn thêm soThang tháng và đưa hợp đồng về 'active'.
//
// GREATEST(ends_at, NOW(3)) chứ không phải ends_at: hợp đồng đã quá hạn ba tháng
// mà cộng dồn từ ngày cũ thì khách trả tiền xong vẫn còn quá hạn — cái sai đó
// không báo lỗi ở đâu cả, nó chỉ hiện ra thành một dòng đỏ trên màn hình sau khi
// đã thu tiền.
//
// trial_ends_at = NULL: đây cũng là đường CHUYỂN DÙNG THỬ SANG CHÍNH THỨC. Để
// lại cái mốc đó thì hợp đồng vừa 'active' vừa mang ngày hết dùng thử, và mọi
// báo cáo đọc hai cột ấy sẽ trả lời khác nhau.
func (r *hopDongRepository) GiaHan(ctx context.Context, id uint, soThang int) error {
	res := r.db.WithContext(ctx).
		Model(&domain.Subscription{}).
		Where("id = ?", id).
		Updates(map[string]any{
			"ends_at":       gorm.Expr("DATE_ADD(GREATEST(ends_at, NOW(3)), INTERVAL ? MONTH)", soThang),
			"status":        domain.SubscriptionActive,
			"trial_ends_at": nil,
			"updated_at":    time.Now(),
		})
	if res.Error != nil {
		return res.Error
	}
	if res.RowsAffected == 0 {
		return domain.ErrNotFound
	}

	return nil
}

// Sua ghi phần sửa được của hợp đồng VÀ của khách đứng sau nó.
//
// MỘT GIAO DỊCH cho hai bảng — làm được vì `tenants` và `subscriptions` của
// control plane nằm cùng lược đồ. Khác hẳn lúc mở tài khoản dùng thử, nơi phải
// bắc qua hai database và không có giao dịch chung nào (xem DungThuService).
//
// Ghi ĐÈ chứ không nối thêm: đây là ô người dùng sửa trực tiếp, nội dung họ thấy
// trên màn hình chính là nội dung sẽ được lưu. Khác Huy, nơi lý do được nối vào
// note vì nó là một sự kiện chồng lên nội dung sẵn có.
//
// Chuỗi rỗng → NULL nhờ StringOrNull: xoá trắng ô người liên hệ phải ra "chưa
// có", không phải một chuỗi rỗng trông y hệt nhưng không phải NULL.
func (r *hopDongRepository) Sua(ctx context.Context, id, tenantID uint, hs domain.SuaHopDong) error {
	return r.db.WithContext(ctx).Transaction(func(tx *gorm.DB) error {
		res := tx.Model(&domain.PlatformTenant{}).
			Where("id = ?", tenantID).
			Updates(map[string]any{
				"name":          hs.TenCuaHang,
				"contact_name":  domain.StringOrNull(hs.NguoiLienHe),
				"contact_phone": domain.StringOrNull(hs.DienThoai),
				"contact_email": domain.StringOrNull(hs.Email),
				"note":          domain.StringOrNull(hs.GhiChuKhach),
				"updated_at":    time.Now(),
			})
		if res.Error != nil {
			return res.Error
		}
		if res.RowsAffected == 0 {
			// Khách biến mất giữa lúc đọc và lúc ghi. Hiếm, nhưng ghi tiếp vào
			// subscriptions thì hợp đồng trỏ vào một khách không còn ai.
			return domain.ErrNotFound
		}

		dat := map[string]any{
			"note":       domain.StringOrNull(hs.GhiChuHopDong),
			"updated_at": time.Now(),
		}
		// Đổi hạn: chỉ dùng thử mới tới được đây (service chặn trước). Ghi CẢ HAI
		// cột — trial_ends_at là mốc hết dùng thử, ends_at là hạn hợp đồng, và với
		// một hợp đồng thử thì chúng là cùng một ngày. Ghi lệch nhau thì hai màn
		// hình đọc hai cột sẽ trả lời khác nhau.
		if hs.HetHan != nil {
			dat["ends_at"] = *hs.HetHan
			dat["trial_ends_at"] = *hs.HetHan
			// VỀ LẠI 'trial'. Chỉ hợp đồng dùng thử tới được nhánh này (service
			// chặn trước), và nó có thể đang mang `past_due` do lượt quét hạn đánh
			// dấu. Để nguyên `past_due` với một cái hạn nằm ở tương lai thì lượt
			// quét sau không nhặt nó nữa (xem QuaHan) — khách được gia hạn thêm ba
			// ngày rồi dùng vô thời hạn, vì không còn ai canh cái hạn mới ấy.
			dat["status"] = domain.SubscriptionTrial
		}

		res = tx.Model(&domain.Subscription{}).Where("id = ?", id).Updates(dat)
		if res.Error != nil {
			return res.Error
		}
		if res.RowsAffected == 0 {
			return domain.ErrNotFound
		}

		return nil
	})
}

// KyCuoiDaThu đọc `period_end` xa nhất đã ghi trong sổ thu của hợp đồng.
//
// nil = chưa thu lần nào, và đó là một câu trả lời hợp lệ chứ không phải lỗi:
// hợp đồng vừa ký thì sổ thu của nó đương nhiên trống.
func (r *hopDongRepository) KyCuoiDaThu(ctx context.Context, id uint) (*time.Time, error) {
	// sql.NullTime chứ KHÔNG phải *time.Time: MAX() trên tập rỗng trả về NULL, và
	// driver không đổ được NULL vào một con trỏ thời gian ("unsupported Scan,
	// storing driver.Value type <nil> into type *time.Time"). Hợp đồng chưa thu
	// lần nào là trường hợp THƯỜNG GẶP NHẤT của hàm này — chính là lượt thu đầu
	// tiên — nên nhánh đó phải chạy được, không phải nhánh hiếm.
	var moc sql.NullTime
	err := r.db.WithContext(ctx).
		Model(&domain.Invoice{}).
		Where("subscription_id = ?", id).
		Select("MAX(period_end)").
		Scan(&moc).Error
	if err != nil {
		return nil, err
	}
	if !moc.Valid {
		return nil, nil
	}

	return &moc.Time, nil
}

// ThuTien ghi một dòng vào sổ thu.
func (r *hopDongRepository) ThuTien(ctx context.Context, hd domain.Invoice) error {
	err := r.db.WithContext(ctx).Create(&hd).Error

	// uq_invoices_ky (subscription_id, period_start) là khoá duy nhất duy nhất
	// của bảng, nên "trùng khoá" ở đây chỉ có một nghĩa.
	if errors.Is(err, gorm.ErrDuplicatedKey) {
		return domain.ErrDaThuKyNay
	}

	return err
}

// TongDaThu cộng tiền đã thu của một hợp đồng.
//
// COALESCE vì SUM trên tập rỗng trả NULL, mà Scan vào float64 sẽ hỏng — hợp
// đồng chưa thu lần nào phải ra 0, không phải ra lỗi.
func (r *hopDongRepository) TongDaThu(ctx context.Context, id uint) (float64, int, error) {
	var ra struct {
		Tong  float64
		SoLan int
	}
	err := r.db.WithContext(ctx).
		Model(&domain.Invoice{}).
		Where("subscription_id = ?", id).
		Select("COALESCE(SUM(amount), 0) AS tong, COUNT(*) AS so_lan").
		Scan(&ra).Error

	return ra.Tong, ra.SoLan, err
}

// TenantDangCoHopDong trả về id khách đang có hợp đồng còn hiệu lực cho một app.
//
// "Còn hiệu lực" = mọi trạng thái TRỪ `canceled`, khớp đúng định nghĩa của cột
// sinh `current_mark` mà khoá uq_subscriptions_current dựa vào. Lọc theo
// `status = 'active'` thôi là bỏ sót khách đang dùng thử và khách quá hạn — cả
// hai đều đang giữ chỗ, và ký đè lên sẽ vỡ khoá.
func (r *hopDongRepository) TenantDangCoHopDong(ctx context.Context, maApp string) ([]uint, error) {
	var ids []uint
	q := r.db.WithContext(ctx).
		Table("subscriptions AS s").
		Joins("JOIN apps a ON a.id = s.app_id").
		Where("s.status <> ?", domain.SubscriptionCanceled)
	if maApp != "" {
		q = q.Where("a.code = ?", maApp)
	}

	err := q.Distinct().Pluck("s.tenant_id", &ids).Error

	return ids, err
}

// QuaHan liệt kê hợp đồng đang chạy mà hạn đã lùi về quá khứ.
//
// `status IN ('trial','active')` chứ không phải "khác canceled": hợp đồng đã
// đánh dấu `past_due` là hợp đồng lượt quét TRƯỚC đã xử lý xong, và nhặt lại nó
// mỗi phút nghĩa là mỗi phút ghi lại một dòng nhật ký "vừa khoá cửa hàng X" cho
// tới ngày khách quay lại. Nhật ký đó phải đọc được như một danh sách sự kiện,
// không phải như tiếng ồn.
//
// So bằng `ends_at` chứ không phải `trial_ends_at`, kể cả với hợp đồng dùng
// thử: hai cột đó là CÙNG một ngày ở hợp đồng thử (xem DungThuService.Tao), và
// `ends_at` là cột duy nhất có mặt ở mọi hợp đồng.
func (r *hopDongRepository) QuaHan(ctx context.Context, moc time.Time) ([]domain.HopDongQuaHan, error) {
	var rows []domain.HopDongQuaHan
	err := r.db.WithContext(ctx).
		Table("subscriptions AS s").
		Joins("JOIN tenants t ON t.id = s.tenant_id").
		Joins("JOIN apps a ON a.id = s.app_id").
		Select(`s.id, s.tenant_id, t.code AS ma_cua_hang, a.code AS ma_app,
		        s.ends_at AS het_han, s.trial_ends_at IS NOT NULL AS dung_thu`).
		Where("s.status IN ?", []string{domain.SubscriptionTrial, domain.SubscriptionActive}).
		Where("s.ends_at < ?", moc).
		Order("s.ends_at").
		Scan(&rows).Error

	return rows, err
}

// ConHopDongSong lọc ra khách vẫn còn một hợp đồng CHƯA tới hạn.
func (r *hopDongRepository) ConHopDongSong(ctx context.Context, tenantIDs []uint, moc time.Time) ([]uint, error) {
	if len(tenantIDs) == 0 {
		return nil, nil
	}

	var ids []uint
	err := r.db.WithContext(ctx).
		Model(&domain.Subscription{}).
		Where("tenant_id IN ?", tenantIDs).
		Where("status IN ?", []string{domain.SubscriptionTrial, domain.SubscriptionActive}).
		Where("ends_at >= ?", moc).
		Distinct().
		Pluck("tenant_id", &ids).Error

	return ids, err
}

// DanhDauQuaHan đưa hợp đồng sang `past_due`.
//
// Điều kiện trạng thái được lặp lại trong câu UPDATE, không chỉ nằm ở lượt đọc
// của QuaHan: giữa hai lượt đó, một hợp đồng có thể vừa được gia hạn hoặc huỷ
// bằng tay ở khu điều hành, và ghi đè lên quyết định vừa xảy ra là thứ không ai
// tìm lại được nguyên nhân.
func (r *hopDongRepository) DanhDauQuaHan(ctx context.Context, ids []uint) (int64, error) {
	if len(ids) == 0 {
		return 0, nil
	}

	res := r.db.WithContext(ctx).
		Model(&domain.Subscription{}).
		Where("id IN ?", ids).
		Where("status IN ?", []string{domain.SubscriptionTrial, domain.SubscriptionActive}).
		Updates(map[string]any{
			"status":     domain.SubscriptionPastDue,
			"updated_at": time.Now(),
		})

	return res.RowsAffected, res.Error
}

// DoiTrangThaiKhach ghi `tenants.status` của SỔ NỀN TẢNG.
func (r *hopDongRepository) DoiTrangThaiKhach(ctx context.Context, tenantIDs []uint, trangThai string) (int64, error) {
	if len(tenantIDs) == 0 {
		return 0, nil
	}

	res := r.db.WithContext(ctx).
		Model(&domain.PlatformTenant{}).
		Where("id IN ?", tenantIDs).
		Where("status <> ?", trangThai).
		Updates(map[string]any{
			"status":     trangThai,
			"updated_at": time.Now(),
		})

	return res.RowsAffected, res.Error
}

// Huy đóng hợp đồng và ghi lý do vào note.
//
// CONCAT vào note chứ không ghi đè: note đang giữ điều khoản riêng đã thoả thuận
// với khách, và lý do huỷ không phải là lý do để xoá chúng. COALESCE vì note cho
// phép NULL, mà CONCAT với NULL ra NULL — không có nó thì lượt huỷ đầu tiên của
// một hợp đồng không ghi chú sẽ xoá trắng cả ô.
//
// canceled_at + status='canceled' đi cùng nhau: current_mark là cột sinh từ
// status, và chính nó nhả khoá uq_subscriptions_current ra cho hợp đồng sau.
func (r *hopDongRepository) Huy(ctx context.Context, id uint, lyDo string) error {
	dat := map[string]any{
		"status":      domain.SubscriptionCanceled,
		"canceled_at": time.Now(),
		"updated_at":  time.Now(),
	}
	if lyDo != "" {
		dat["note"] = gorm.Expr("CONCAT(COALESCE(CONCAT(note, ' · '), ''), ?)", "huỷ: "+lyDo)
	}

	res := r.db.WithContext(ctx).
		Model(&domain.Subscription{}).
		Where("id = ?", id).
		Updates(dat)
	if res.Error != nil {
		return res.Error
	}
	if res.RowsAffected == 0 {
		return domain.ErrNotFound
	}

	return nil
}
