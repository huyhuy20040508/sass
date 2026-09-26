package repository

import (
	"context"
	"strings"
	"testing"

	"gorm.io/driver/mysql"
	"gorm.io/gorm"

	"sass-api/internal/domain"
	"sass-api/internal/tenant"
)

// Sổ hoá đơn điện tử nối sang `orders` và `users` bằng JOIN viết tay — đúng
// kiểu chỗ mà bộ lọc cửa hàng dễ rơi mất. Bài này chạy KHÔ: dựng câu gốc của
// sổ rồi đòi nó lọc theo cửa hàng ở bảng chính, và hai bảng nối phải khớp
// tenant ngay trong ON (plugin chỉ chèn điều kiện cho bảng chính).
func TestDanhSachHoaDon_LocTheoCuaHang(t *testing.T) {
	db, err := gorm.Open(mysql.New(mysql.Config{
		DSN:                       "u:p@tcp(127.0.0.1:3306)/khong_ket_noi",
		SkipInitializeWithVersion: true,
	}), &gorm.Config{DryRun: true, DisableAutomaticPing: true})
	if err != nil {
		t.Fatalf("không dựng được phiên GORM chạy khô: %v", err)
	}
	if err := db.Use(tenantScope{}); err != nil {
		t.Fatalf("không gắn được plugin tenant: %v", err)
	}

	repo := &etaxRepository{db: db}
	ctx := tenant.WithID(context.Background(), 42)
	stmt := repo.cauHoaDon(ctx, domain.HoaDonFilter{KhachHang: "an", MaDon: "DH"}).
		Find(&[]domain.DongHoaDon{}).Statement
	sql := stmt.SQL.String()

	if !strings.Contains(sql, "`etax_invoices`.`tenant_id` = ?") {
		t.Fatalf("câu lệnh không lọc theo cửa hàng ở bảng chính:\n%s", sql)
	}
	dem := 0
	for _, v := range stmt.Vars {
		if id, ok := v.(uint); ok && id == 42 {
			dem++
		}
	}
	if dem != 1 {
		t.Fatalf("phải truyền tenant 42 đúng một lần cho bảng chính, đếm được %d: %v", dem, stmt.Vars)
	}
	for _, on := range []string{"o.tenant_id = etax_invoices.tenant_id", "nt.tenant_id = o.tenant_id"} {
		if !strings.Contains(sql, on) {
			t.Errorf("bảng nối phải khớp tenant ngay trong ON (%s):\n%s", on, sql)
		}
	}
}
