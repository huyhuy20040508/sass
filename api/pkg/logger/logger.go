// Package logger khởi tạo logger toàn cục dựa trên Zap.
package logger

import (
	"go.uber.org/zap"
)

var log *zap.Logger

// Init khởi tạo logger. production=true dùng cấu hình JSON, ngược lại dùng dev console.
func Init(production bool) error {
	var (
		l   *zap.Logger
		err error
	)
	if production {
		l, err = zap.NewProduction()
	} else {
		l, err = zap.NewDevelopment()
	}
	if err != nil {
		return err
	}
	log = l
	return nil
}

// L trả về logger toàn cục (fallback về NopLogger nếu chưa Init).
func L() *zap.Logger {
	if log == nil {
		return zap.NewNop()
	}
	return log
}

func Sync() { _ = L().Sync() }

func Info(msg string, fields ...zap.Field)  { L().Info(msg, fields...) }
func Warn(msg string, fields ...zap.Field)  { L().Warn(msg, fields...) }
func Error(msg string, fields ...zap.Field) { L().Error(msg, fields...) }
func Fatal(msg string, fields ...zap.Field) { L().Fatal(msg, fields...) }
