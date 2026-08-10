// Package repository hiện thực các port trong domain bằng GORM (MySQL).
package repository

import (
	"sass-api/config"

	"gorm.io/driver/mysql"
	"gorm.io/gorm"
	gormlogger "gorm.io/gorm/logger"
)

// NewDB mở kết nối MySQL qua GORM và cấu hình connection pool.
func NewDB(cfg config.DatabaseConfig, production bool) (*gorm.DB, error) {
	logLevel := gormlogger.Info
	if production {
		logLevel = gormlogger.Warn
	}

	db, err := gorm.Open(mysql.Open(cfg.DSN()), &gorm.Config{
		Logger:                 gormlogger.Default.LogMode(logLevel),
		SkipDefaultTransaction: true,
		TranslateError:         true, // 1062 -> gorm.ErrDuplicatedKey, 1451/1452 -> gorm.ErrForeignKeyViolated
	})
	if err != nil {
		return nil, err
	}

	sqlDB, err := db.DB()
	if err != nil {
		return nil, err
	}
	sqlDB.SetMaxOpenConns(cfg.MaxOpenConns)
	sqlDB.SetMaxIdleConns(cfg.MaxIdleConns)
	sqlDB.SetConnMaxLifetime(cfg.ConnMaxLifetime)
	// MySQL đóng kết nối nhàn rỗi sau wait_timeout (XAMPP mặc định 180s). Tự bỏ
	// kết nối rảnh sớm hơn để không lấy phải kết nối đã chết -> "invalid connection".
	sqlDB.SetConnMaxIdleTime(cfg.ConnMaxIdleTime)

	// Kiểm tra kết nối
	if err := sqlDB.Ping(); err != nil {
		return nil, err
	}
	return db, nil
}
