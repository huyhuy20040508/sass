package repository

import (
	"context"

	"gorm.io/gorm"

	"sass-api/internal/domain"
)

// khachHangRepository đọc sổ KHÁCH HÀNG · HỢP ĐỒNG · TIỀN ĐÃ THU của CONTROL
// PLANE.
//
// Kết nối truyền vào PHẢI là repository.NewPlatformDB — cùng ràng buộc với
// planRepository và appRepository.
//
// KHÔNG có bộ lọc tenant trên kết nối này, và ở đây điều đó đáng nói hơn mọi
// repo khác: ba hàm dưới đây đọc XUYÊN MỌI KHÁCH HÀNG. Đó là bản chất của khu
// điều hành, nhưng cũng nghĩa là không có lưới nào phía dưới đỡ hộ — bộ lọc duy
// nhất là LocKhuDieuHanh.MaApp, và nó phải được áp ở CẢ BA hàm.
type khachHangRepository struct{ db *gorm.DB }

// NewKhachHangRepository nhận kết nối CONTROL PLANE (NewPlatformDB).
func NewKhachHangRepository(platformDB *gorm.DB) domain.KhachHangRepository {
	return &khachHangRepository{db: platformDB}
}

// locApp gắn điều kiện phạm vi phần mềm vào một câu truy vấn đã JOIN `apps AS a`.
//
// Ba trạng thái, và trạng thái thứ hai là chỗ dễ sai nhất:
//
//   - nil        → KHÔNG lọc. Chỉ owner đi đường này.
//   - rỗng       → không phần mềm nào, tức KHÔNG dòng nào. Người chưa được giao
//     gì phải nhận danh sách rỗng, không phải nhận tất.
//   - có phần tử → lọc theo đúng danh sách.
//
// Viết `IN (?)` với slice rỗng thì GORM sinh `IN (NULL)` — vẫn ra 0 dòng, nhưng
// đó là may chứ không phải thiết kế. Chặn tường minh bằng `1 = 0`.
func locApp(q *gorm.DB, maApp []string) *gorm.DB {
	if maApp == nil {
		return q
	}
	if len(maApp) == 0 {
		return q.Where("1 = 0")
	}

	return q.Where("a.code IN ?", maApp)
}

func (r *khachHangRepository) KhachHang(ctx context.Context, loc domain.LocKhuDieuHanh) ([]domain.KhachHangTrongSo, error) {
	// JOIN chứ không LEFT JOIN: đây là "khách hàng CỦA phần mềm này", mà quan hệ
	// đó chỉ tồn tại qua hợp đồng. Khách chưa mua gì thì chưa thuộc phần mềm nào
	// — họ vẫn nằm trong `tenants`, chỉ là không có chỗ trên màn hình đang lọc
	// theo app.
	q := r.db.WithContext(ctx).
		Table("tenants AS t").
		Joins("JOIN subscriptions s ON s.tenant_id = t.id").
		Joins("JOIN apps a ON a.id = s.app_id").
		Select("t.*, COUNT(DISTINCT s.id) AS so_hop_dong").
		Group("t.id").
		Order("t.code")
	q = locApp(q, loc.MaApp)
	if loc.TrangThai != "" {
		q = q.Where("s.status = ?", loc.TrangThai)
	} else {
		// Mặc định KHÔNG đếm hợp đồng đã huỷ: "khách hàng của phần mềm này" nghĩa
		// là khách đang dùng. Muốn xem cả khách cũ thì lọc `trang_thai=canceled`
		// tường minh.
		q = q.Where("s.status <> ?", domain.SubscriptionCanceled)
	}

	var rows []domain.KhachHangTrongSo
	if err := q.Scan(&rows).Error; err != nil {
		return nil, err
	}

	return rows, nil
}

func (r *khachHangRepository) HopDong(ctx context.Context, loc domain.LocKhuDieuHanh) ([]domain.HopDongDayDu, error) {
	q := r.db.WithContext(ctx).
		Table("subscriptions AS s").
		Joins("JOIN tenants t ON t.id = s.tenant_id").
		Joins("JOIN apps a ON a.id = s.app_id").
		Select("s.*, t.code AS ma_cua_hang, t.name AS ten_cua_hang, a.code AS ma_app, a.name AS ten_app").
		// Hợp đồng sắp hết hạn phải nằm gần đầu: đó là danh sách người ta mở màn
		// hình này để xem.
		Order("s.ends_at ASC, t.code")
	q = locApp(q, loc.MaApp)
	if loc.TrangThai != "" {
		q = q.Where("s.status = ?", loc.TrangThai)
	}

	var rows []domain.HopDongDayDu
	if err := q.Scan(&rows).Error; err != nil {
		return nil, err
	}

	return rows, nil
}

// DoanhThu cộng tiền ĐÃ THU, không phải tiền đáng lẽ phải thu.
//
// Nguồn là `invoices` (0014) — mỗi dòng một lần tiền vào. Cộng
// `subscriptions.price` thay cho nó là báo cáo một con số chưa ai trả.
//
// Lọc theo `paid_at` (ngày tiền vào) chứ không theo chu kỳ được trả cho: câu
// hỏi của màn hình này là "tháng vừa rồi thu được bao nhiêu".
func (r *khachHangRepository) DoanhThu(ctx context.Context, loc domain.LocKhuDieuHanh) ([]domain.DoanhThuTheoQuan, error) {
	q := r.db.WithContext(ctx).
		Table("invoices AS i").
		Joins("JOIN subscriptions s ON s.id = i.subscription_id").
		Joins("JOIN tenants t ON t.id = s.tenant_id").
		Joins("JOIN apps a ON a.id = s.app_id").
		Select(`t.id AS tenant_id, t.code AS ma_cua_hang, t.name AS ten_cua_hang,
		        COUNT(i.id) AS so_lan_thu, SUM(i.amount) AS tong_tien,
		        DATE_FORMAT(MAX(i.paid_at), '%d/%m/%Y') AS lan_cuoi`).
		Group("t.id, t.code, t.name").
		Order("tong_tien DESC")
	q = locApp(q, loc.MaApp)
	if loc.Tu != nil {
		q = q.Where("i.paid_at >= ?", *loc.Tu)
	}
	if loc.Den != nil {
		q = q.Where("i.paid_at < ?", *loc.Den)
	}

	var rows []domain.DoanhThuTheoQuan
	if err := q.Scan(&rows).Error; err != nil {
		return nil, err
	}

	return rows, nil
}
