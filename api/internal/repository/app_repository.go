package repository

import (
	"context"

	"gorm.io/gorm"

	"sass-api/internal/domain"
)

// appRepository đọc DANH MỤC PHẦN MỀM của CONTROL PLANE.
//
// Kết nối truyền vào PHẢI là repository.NewPlatformDB — cùng ràng buộc với
// planRepository, và không có cách nào để trình biên dịch ép nó (cả hai đều là
// *gorm.DB). Đưa nhầm kết nối data plane vào thì bảng `apps` không tồn tại bên
// đó và mọi lượt gọi đều lỗi.
//
// KHÔNG có bộ lọc tenant: danh mục sản phẩm là của nền tảng, không thuộc cửa
// hàng nào. Vì vậy đường dẫn tới đây phải tự canh quyền — xem
// middleware.XacThucNenTang, và phần lọc theo phân công phần mềm ở service.
type appRepository struct{ db *gorm.DB }

// NewAppRepository nhận kết nối CONTROL PLANE (NewPlatformDB).
func NewAppRepository(platformDB *gorm.DB) domain.AppRepository {
	return &appRepository{db: platformDB}
}

// List đọc danh mục kèm số gói ĐANG BÁN của từng phần mềm.
//
// Đếm bằng LEFT JOIN chứ không phải câu con chạy cho từng dòng: danh mục hôm
// nay có một app nên khác biệt không đo được, nhưng câu con trong vòng lặp là
// thứ không ai sửa lại cho tới ngày nó chậm.
//
// LEFT JOIN chứ không JOIN: app chưa có gói nào PHẢI xuất hiện, và nó chính là
// dòng người điều hành cần thấy nhất — phần mềm đã khai mà chưa dựng bảng giá
// thì chưa bán được cho ai.
//
// Điều kiện `status = 'active'` nằm trong mệnh đề JOIN chứ không ở WHERE: đặt ở
// WHERE thì mọi app có 0 gói đang bán biến mất khỏi danh mục — đúng cái LEFT
// JOIN vừa cứu.
func (r *appRepository) List(ctx context.Context) ([]domain.AppTrongDanhMuc, error) {
	var rows []domain.AppTrongDanhMuc
	err := r.db.WithContext(ctx).
		Table("apps AS a").
		Joins("LEFT JOIN plans p ON p.app_id = a.id AND p.status = ?", domain.PlanStatusActive).
		Select("a.*, COUNT(p.id) AS so_goi_dang_ban").
		Group("a.id").
		Order("a.code").
		Scan(&rows).Error
	if err != nil {
		return nil, err
	}

	return rows, nil
}
