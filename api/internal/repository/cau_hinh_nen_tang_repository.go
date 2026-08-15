package repository

import (
	"context"

	"gorm.io/gorm"
	"gorm.io/gorm/clause"

	"sass-api/internal/domain"
)

// cauHinhNenTangRepository đọc/ghi CẤU HÌNH CỦA NHÀ CUNG CẤP.
//
// Kết nối truyền vào PHẢI là repository.NewPlatformDB — cùng ràng buộc với
// planRepository. Ở đây kiểu nhầm lại dễ thấy: bảng `platform_settings` không có
// bên data plane nên mọi lượt gọi hỏng ngay, không có nhánh nào âm thầm ghi vào
// bảng `settings` của một cửa hàng.
type cauHinhNenTangRepository struct{ db *gorm.DB }

// NewCauHinhNenTangRepository nhận kết nối CONTROL PLANE (NewPlatformDB).
func NewCauHinhNenTangRepository(platformDB *gorm.DB) domain.PlatformSettingRepository {
	return &cauHinhNenTangRepository{db: platformDB}
}

// All đọc toàn bộ dòng đang có.
//
// KHÔNG điền hộ giá trị mặc định cho khoá thiếu dòng: mặc định là chuyện của
// registry bên service. Trộn hai nguồn ở tầng này thì không ai phân biệt được
// "người bán đã khai đúng bằng mặc định" với "chưa ai khai bao giờ" — mà đó
// chính là hai câu trả lời khác nhau cho câu hỏi "đã cấu hình xong chưa".
func (r *cauHinhNenTangRepository) All(ctx context.Context) (map[string]string, error) {
	var rows []domain.PlatformSetting
	if err := r.db.WithContext(ctx).Find(&rows).Error; err != nil {
		return nil, err
	}

	out := make(map[string]string, len(rows))
	for _, row := range rows {
		out[row.Key] = row.Value
	}

	return out, nil
}

// Save ghi nhiều khoá trong MỘT giao dịch.
//
// Upsert theo khoá chính: khoá chưa có dòng thì thêm, có rồi thì đè. Đây là lý
// do bảng không cần dòng khởi tạo sẵn cho từng khoá.
//
// Tất-cả-hoặc-không, và với riêng bộ thông tin chuyển khoản thì đó không phải
// sự cẩn thận thừa: ghi được số tài khoản mới mà hụt tên chủ tài khoản nghĩa là
// màn hình của khách hiện một cặp thông tin không thuộc về ai, và tiền chuyển
// theo đó thì đi mất.
func (r *cauHinhNenTangRepository) Save(ctx context.Context, items map[string]string) error {
	if len(items) == 0 {
		return nil
	}

	rows := make([]domain.PlatformSetting, 0, len(items))
	for key, value := range items {
		rows = append(rows, domain.PlatformSetting{Key: key, Value: value})
	}

	return r.db.WithContext(ctx).
		Clauses(clause.OnConflict{
			Columns:   []clause.Column{{Name: "setting_key"}},
			DoUpdates: clause.AssignmentColumns([]string{"value", "updated_at"}),
		}).
		Create(&rows).Error
}
