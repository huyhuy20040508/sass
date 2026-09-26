package repository

import (
	"context"
	"errors"

	"gorm.io/gorm"

	"sass-api/internal/domain"
)

// thueBaoCuaKhachRepository đọc hợp đồng của ĐÚNG MỘT khách trong sổ nền tảng.
//
// Kết nối truyền vào PHẢI là repository.NewPlatformDB — cùng ràng buộc với
// hopDongRepository. Đưa nhầm kết nối data plane vào thì câu truy vấn tìm bảng
// `subscriptions` bên đó và hỏng ngay, nên nhầm kiểu này lộ ra sớm.
//
// ĐÂY LÀ ĐƯỜNG ĐỌC CHẠY SAU TOKEN CỦA MỘT CỬA HÀNG, khác hẳn mọi repository
// control plane còn lại (chúng nằm sau middleware của khu điều hành). Bộ lọc
// tenant tự động không với tới đây — kết nối nền tảng không gắn plugin đó — nên
// `tenant_id = ?` phải nằm ngay trong câu truy vấn, và đó là điều kiện duy nhất
// ngăn chủ tiệm này đọc hợp đồng của tiệm khác.
type thueBaoCuaKhachRepository struct{ db *gorm.DB }

// NewThueBaoCuaKhachRepository nhận kết nối CONTROL PLANE (NewPlatformDB).
func NewThueBaoCuaKhachRepository(platformDB *gorm.DB) domain.ThueBaoCuaKhachRepository {
	return &thueBaoCuaKhachRepository{db: platformDB}
}

// HienTai trả về hợp đồng còn hiệu lực của một khách cho một phần mềm.
//
// Cùng bộ cột với hopDongRepository.Tim để hai bên dịch sang DTO bằng cùng một
// phép — lệch cột nghĩa là màn hình của khách và màn hình của khu điều hành nói
// hai con số khác nhau về cùng một hợp đồng.
//
// `status <> canceled` khớp định nghĩa của cột sinh `current_mark` mà khoá
// uq_subscriptions_current dựa vào, nên nhiều nhất một dòng thoả. ORDER BY vẫn
// có mặt để kết quả xác định trong trường hợp khoá đó bị gỡ ở một môi trường nào
// đó — im lặng chọn bừa một dòng là kiểu sai khó tìm nhất.
func (r *thueBaoCuaKhachRepository) HienTai(
	ctx context.Context, tenantID uint, maApp string,
) (*domain.HopDongDayDu, error) {
	if tenantID == 0 {
		// Không có cửa hàng nào mang số 0. Nhận nó nghĩa là nơi gọi đang truyền một
		// giá trị chưa khởi tạo, và bỏ qua thì câu truy vấn trả về "chưa có hợp
		// đồng" — sai mà trông y hệt một câu trả lời hợp lệ.
		return nil, errors.New("đọc hợp đồng của khách mà thiếu mã cửa hàng")
	}

	var hd domain.HopDongDayDu
	err := r.db.WithContext(ctx).
		Table("subscriptions AS s").
		Joins("JOIN tenants t ON t.id = s.tenant_id").
		Joins("JOIN apps a ON a.id = s.app_id").
		// LEFT JOIN chỉ để lấy TÊN gói — giá và hạn mức đọc thẳng ở dòng hợp đồng,
		// xem chú thích ở domain.Plan.
		Joins("LEFT JOIN plans p ON p.id = s.plan_id").
		Select(`s.*, t.code AS ma_cua_hang, t.name AS ten_cua_hang,
		        t.contact_name AS nguoi_lien_he, t.contact_phone AS dien_thoai,
		        t.contact_email AS email,
		        a.code AS ma_app, a.name AS ten_app, p.name AS ten_goi,
		        t.note AS ghi_chu_khach, t.created_at AS ngay_vao_so,
		        t.status AS trang_thai_cua_hang`).
		Where("s.tenant_id = ?", tenantID).
		Where("a.code = ?", maApp).
		Where("s.status <> ?", domain.SubscriptionCanceled).
		Order("s.ends_at DESC").
		Take(&hd).Error
	if errors.Is(err, gorm.ErrRecordNotFound) {
		return nil, domain.ErrNotFound
	}
	if err != nil {
		return nil, err
	}

	return &hd, nil
}
