package repository

import (
	"context"

	"gorm.io/gorm"
	"gorm.io/gorm/clause"

	"sass-api/internal/domain"
)

type settingRepository struct{ db *gorm.DB }

func NewSettingRepository(db *gorm.DB) domain.SettingRepository {
	return &settingRepository{db: db}
}

// Map đọc toàn bộ cấu hình thành map key -> value.
//
// Không có hàm lọc theo nhóm: nhóm của một khoá do registry bên service quyết
// định, cột `group` dưới database chỉ là bản sao cho dễ đọc và có thể còn mang
// tên nhóm cũ. Lọc theo cột đó sẽ làm mất giá trị đã lưu của những khoá vừa đổi
// nhóm. `key` và `group` là từ khoá của MySQL nên phải bọc backtick trong ORDER BY.
func (r *settingRepository) Map(ctx context.Context) (map[string]string, error) {
	var rows []domain.Setting
	if err := r.db.WithContext(ctx).Order("`group` ASC, `key` ASC").Find(&rows).Error; err != nil {
		return nil, err
	}

	out := make(map[string]string, len(rows))
	for _, row := range rows {
		out[row.Key] = row.Value
	}
	return out, nil
}

// Upsert ghi nhiều khoá trong MỘT câu lệnh INSERT ... ON DUPLICATE KEY UPDATE.
// Khoá đã có thì cập nhật giá trị, chưa có thì thêm mới — nên khoá mới khai trong
// registry của service chạy được ngay mà không cần chèn seed trước.
func (r *settingRepository) Upsert(ctx context.Context, items []domain.Setting) error {
	if len(items) == 0 {
		return nil
	}
	return r.db.WithContext(ctx).
		Clauses(clause.OnConflict{
			Columns:   []clause.Column{{Name: "key"}},
			DoUpdates: clause.AssignmentColumns([]string{"value", "group", "updated_at"}),
		}).
		Create(&items).Error
}
