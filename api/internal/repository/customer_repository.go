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
	//
	// HỢP ĐỒNG ĐÃ HUỶ VẪN GIỮ KHÁCH LẠI TRONG SỔ, chỉ không được ĐẾM.
	//
	// Trước đây điều kiện `s.status <> 'canceled'` nằm ở WHERE, nên khách có đúng
	// một hợp đồng và hợp đồng đó bị huỷ thì cả DÒNG biến mất khỏi sổ. Hai chỗ
	// hỏng vì việc đó: sổ khách hàng là nơi tra lại khách CŨ, mà khách cũ thì
	// theo định nghĩa không còn hợp đồng nào; và chính chú thích của
	// KhachHangTrongSo nói "khách có mặt trong sổ mà 0 hợp đồng là khách đã dừng
	// hẳn" — một trạng thái mà câu truy vấn cũ không bao giờ tạo ra được.
	//
	// Điều kiện chuyển vào trong COUNT: dòng ở lại, con số nói đúng.
	q := r.db.WithContext(ctx).
		Table("tenants AS t").
		Joins("JOIN subscriptions s ON s.tenant_id = t.id").
		Joins("JOIN apps a ON a.id = s.app_id").
		Select("t.*, COUNT(DISTINCT CASE WHEN s.status <> ? THEN s.id END) AS so_hop_dong",
			domain.SubscriptionCanceled).
		Group("t.id").
		Order("t.code")
	q = locApp(q, loc.MaApp)
	if loc.TrangThai != "" {
		q = q.Where("s.status = ?", loc.TrangThai)
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
		// LEFT JOIN, và chỉ lấy MỖI CÁI TÊN: hợp đồng thoả thuận riêng không có
		// plan_id, và dòng bảng giá cũng có thể đã bị xoá — cả hai đều không được
		// làm biến mất một hợp đồng khỏi danh sách. Giá và hạn mức vẫn đọc ở `s.*`,
		// không bao giờ ở đây.
		Joins("LEFT JOIN plans p ON p.id = s.plan_id").
		// Câu con `da_thu_den` gộp trên `invoices` CỦA TỪNG HỢP ĐỒNG — khác hẳn
		// hàm DoanhThu ngay dưới, nơi sổ thu được gộp theo CỬA HÀNG. Ở đây câu hỏi
		// là "hợp đồng này đã trả tiền tới ngày nào", và nó quyết định màn hình có
		// còn chìa nút Thu tiền ra hay không.
		//
		// Đọc kèm ở đây thay vì gọi KyCuoiDaThu cho từng dòng: một danh sách 50
		// khách sẽ thành 51 lượt đi database, và cột này thì không đáng giá bằng đó.
		Select(`s.*, t.code AS ma_cua_hang, t.name AS ten_cua_hang,
		        t.contact_name AS nguoi_lien_he, t.contact_phone AS dien_thoai,
		        t.contact_email AS email,
		        a.code AS ma_app, a.name AS ten_app, p.name AS ten_goi,
		        t.note AS ghi_chu_khach, t.created_at AS ngay_vao_so,
		        t.status AS trang_thai_cua_hang,
		        (SELECT MAX(i.period_end) FROM invoices i
		          WHERE i.subscription_id = s.id) AS da_thu_den`).
		// Hợp đồng sắp hết hạn phải nằm gần đầu: đó là danh sách người ta mở màn
		// hình này để xem.
		//
		// ĐÃ HUỶ XUỐNG CUỐI, tách hẳn khỏi thứ tự theo hạn. Hợp đồng đóng từ năm
		// ngoái có `ends_at` xa nhất về quá khứ, nên chỉ sắp theo hạn thôi là dồn
		// hết khách cũ lên đầu bảng và đẩy những khách CÒN phải gọi điện xuống
		// dưới — đúng ngược với việc màn hình này phục vụ.
		Order("(s.status = '" + domain.SubscriptionCanceled + "') ASC, s.ends_at ASC, t.code")
	q = locApp(q, loc.MaApp)
	if loc.TrangThai != "" {
		q = q.Where("s.status = ?", loc.TrangThai)
	}

	// Nhóm — xem domain.NhomDungThu về việc vì sao hai màn hình lọc theo đây chứ
	// không theo trạng thái. Giá trị lạ thì KHÔNG lọc, và cái sai đó hiện ra ngay
	// trên màn hình dưới dạng hai nhóm trộn vào nhau.
	switch loc.Nhom {
	case domain.NhomDungThu:
		q = q.Where("s.trial_ends_at IS NOT NULL")
	case domain.NhomChinhThuc:
		q = q.Where("s.trial_ends_at IS NULL")
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
