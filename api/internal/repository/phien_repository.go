package repository

import (
	"context"

	"gorm.io/gorm"

	"sass-api/internal/domain"
	"sass-api/internal/tenant"
)

// phienRepository trả lời câu hỏi của middleware xác thực: chiếc token này còn
// ứng với một tài khoản sống trong một cửa hàng đang mở không.
//
// CHẠY TRÊN DATA PLANE (repository.NewDB).
//
// Đây là lượt đọc chạy ở MỌI request đã đăng nhập của Shop Admin, nên nó được
// viết để rẻ nhất có thể: một câu, hai cột, hai lần tra khoá chính.
type phienRepository struct{ db *gorm.DB }

// NewPhienRepository nhận kết nối DATA PLANE (NewDB).
func NewPhienRepository(db *gorm.DB) domain.PhienRepository { return &phienRepository{db: db} }

// ketQuaPhien là hình dạng thô của một dòng trả về.
//
// Con trỏ ở UserStatus: LEFT JOIN nên tài khoản đã bị xoá sẽ cho NULL, và NULL
// khác hẳn chuỗi rỗng — cái sau nghĩa là có dòng nhưng cột trống.
type ketQuaPhien struct {
	UserStatus   *string
	TenantStatus *string
}

// KiemPhien tra tình trạng của (tài khoản, cửa hàng) trong MỘT câu.
//
// Đi từ `tenants` rồi LEFT JOIN sang `users`, không phải chiều ngược lại: cửa
// hàng bị xoá và tài khoản bị xoá là hai chuyện khác nhau, và chỉ chiều này mới
// phân biệt được. Bắt đầu từ `users` thì cả hai đều ra "không có dòng nào".
//
// tenant.WithoutScope: bộ lọc tenant của GORM đọc tenant từ ctx, mà hàm này
// chạy TRƯỚC khi ctx có tenant — chính nó là bước quyết định có gắn tenant vào
// ctx hay không. Điều kiện `u.tenant_id = ?` vì thế được khai TƯỜNG MINH ngay
// trong câu, và tenantID đến từ claims đã xác minh chữ ký chứ không từ dữ liệu
// người gọi gửi lên.
func (r *phienRepository) KiemPhien(ctx context.Context, userID, tenantID uint) (domain.TinhTrangPhien, error) {
	var ra domain.TinhTrangPhien

	ctx = tenant.WithoutScope(ctx, "kiểm phiên đăng nhập: chạy trước khi ctx có tenant, điều kiện tenant_id khai tường minh trong câu")

	var kq ketQuaPhien
	err := r.db.WithContext(ctx).
		Table("tenants AS t").
		// deleted_at IS NULL: users xoá mềm. Dòng vẫn nằm đó và khoá UNIQUE vẫn
		// giữ chỗ, nhưng người đó không còn được vào.
		Joins("LEFT JOIN users u ON u.id = ? AND u.tenant_id = t.id AND u.deleted_at IS NULL", userID).
		Select("u.status AS user_status, t.status AS tenant_status").
		Where("t.id = ?", tenantID).
		Take(&kq).Error
	if err != nil {
		// Không có dòng nào = cửa hàng đã bị xoá khỏi sổ. Đó là một câu trả lời
		// hợp lệ ("phiên hết hiệu lực"), không phải lỗi — trả lỗi ở đây thì
		// middleware phải đoán, và nó sẽ đoán sai về phía mở cửa.
		if err == gorm.ErrRecordNotFound {
			return ra, nil
		}

		return ra, err
	}

	ra.CuaHangHoatDong = kq.TenantStatus != nil && *kq.TenantStatus == domain.TenantActive
	ra.CoNguoiDung = kq.UserStatus != nil
	ra.NguoiDungHoatDong = ra.CoNguoiDung && *kq.UserStatus == "active"

	return ra, nil
}
