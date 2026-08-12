// Package repository hiện thực các port trong domain bằng GORM (MySQL).
package repository

import (
	"fmt"

	"sass-api/config"

	"gorm.io/driver/mysql"
	"gorm.io/gorm"
	gormlogger "gorm.io/gorm/logger"
)

// NewDB mở kết nối tới DATA PLANE — database bán hàng của các cửa hàng — và gắn
// bộ lọc tenant vào đó.
//
// Bộ lọc (xem tenant_scope.go) là ranh giới bảo mật giữa các khách hàng, nên nó
// gắn ở ĐÂY chứ không phải ở nơi dựng từng repository: kết nối này ra khỏi hàm
// là đã mang sẵn bộ lọc, không có cách nào lấy được một *gorm.DB "trần" của data
// plane để đi vòng.
func NewDB(cfg config.DatabaseConfig, production bool) (*gorm.DB, error) {
	db, err := open(cfg, production)
	if err != nil {
		return nil, err
	}

	if err := db.Use(tenantScope{}); err != nil {
		return nil, fmt.Errorf("không gắn được bộ lọc tenant: %w", err)
	}

	return db, nil
}

// NewPlatformDB mở kết nối tới CONTROL PLANE — sổ cái nền tảng (khách nào, gói
// nào, tên miền nào).
//
// KHÔNG gắn bộ lọc tenant, và đó là chủ ý: bảng bên này không có cột tenant_id
// (chúng nói VỀ các khách hàng chứ không THUỘC khách hàng nào), còn khu điều
// hành thì bản chất là nhìn xuyên qua mọi khách. Gắn bộ lọc vào đây thì mọi câu
// truy vấn của khu điều hành đều hỏng vì thiếu cột.
//
// Vì vậy hai hàm này KHÔNG được dùng lẫn: đưa kết nối control plane vào một
// repository của data plane là bỏ ranh giới bảo mật đi mà trình biên dịch không
// bắt được — cả hai đều là *gorm.DB. Xem thêm đầu tệp domain/platform.go.
func NewPlatformDB(cfg config.DatabaseConfig, production bool) (*gorm.DB, error) {
	return open(cfg, production)
}

// open mở kết nối MySQL qua GORM và cấu hình connection pool.
func open(cfg config.DatabaseConfig, production bool) (*gorm.DB, error) {
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
