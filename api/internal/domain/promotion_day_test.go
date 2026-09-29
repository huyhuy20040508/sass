package domain

import (
	"testing"
	"time"
)

func TestPromotionChayVaoThu(t *testing.T) {
	// 28/09/2026 là Thứ Hai, 04/10/2026 là Chủ Nhật.
	thu2 := time.Date(2026, 9, 28, 10, 0, 0, 0, time.Local)
	cn := time.Date(2026, 10, 4, 10, 0, 0, 0, time.Local)

	p := Promotion{IsActive: true, StartAt: thu2.AddDate(0, 0, -1), EndAt: cn.AddDate(0, 0, 1)}
	if !p.Running(thu2) || !p.Running(cn) {
		t.Fatal("không chọn thứ nào = chạy mọi ngày")
	}

	p.DaysOfWeek = "1,3,5"
	if !p.Running(thu2) {
		t.Fatal("chọn Thứ Hai thì Thứ Hai phải chạy")
	}
	if p.Running(cn) {
		t.Fatal("không chọn Chủ Nhật thì Chủ Nhật không được chạy")
	}

	p.DaysOfWeek = "7"
	if !p.Running(cn) {
		t.Fatal("Chủ Nhật là 7 theo ISO")
	}
}
