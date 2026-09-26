package repository

import (
	"context"
	"fmt"
	"testing"
	"time"

	"gorm.io/gorm"

	"sass-api/internal/domain"
	"sass-api/internal/tenant"
)

// Bài kiểm ĐƯỜNG DỰNG CỬA HÀNG MỚI.
//
// Đường này trước nay không có bài kiểm nào, mà nó lại là chỗ duy nhất gieo dữ
// liệu khởi điểm cho một khách hàng mới. Gieo hụt thì màn Loại thu chi của khách
// vừa mở mở ra trống trơn — không ai báo lỗi, chỉ là một cửa hàng thiếu đồ.
//
// Cách chạy: xem chú thích TEST_DB_DSN ở product_repository_test.go.

// TestCuaHangMoi_GieoLoaiThuChiKhoiDiem — dựng cửa hàng xong phải có sẵn danh
// sách phân loại thu chi, và danh sách ấy phải mang ĐÚNG tenant_id của cửa hàng
// vừa dựng chứ không rơi sang khách khác.
//
// Chỗ dễ hỏng: lượt ghi là Create trên một LÁT (batch insert). tenantScope phải
// đóng dấu tenant_id cho từng phần tử; đóng dấu hụt thì câu INSERT hoặc bị chặn
// kèm ErrNoTenant, hoặc tệ hơn là ghi vào cửa hàng đang có trong ctx.
func TestCuaHangMoi_GieoLoaiThuChiKhoiDiem(t *testing.T) {
	db := testDB(t)
	repo := NewCuaHangMoiRepository(db)

	// Mã phải khác mọi cửa hàng đang có: `tenants.code` là duy nhất toàn hệ thống.
	ma := fmt.Sprintf("thu-gieo-%d", time.Now().UnixNano()%1_000_000_000)

	tenantID, err := repo.Tao(context.Background(), domain.CuaHangMoi{
		Ma:          ma,
		Ten:         "Cửa hàng thử gieo",
		TenDangNhap: "quantri-" + ma,
		HoTen:       "Quản trị thử",
		Email:       "quantri@" + ma + ".test",
		// Chuỗi bcrypt hợp lệ bất kỳ — bài kiểm này không đăng nhập.
		BamMatKhau: "$2a$10$abcdefghijklmnopqrstuvABCDEFGHIJKLMNOPQRSTUVWXYZ0123456",
	})
	if err != nil {
		t.Fatalf("dựng cửa hàng mới phải xong, nhận lỗi: %v", err)
	}
	t.Cleanup(func() { xoaCuaHangThu(t, db, tenantID) })

	var ds []domain.LoaiThuChi
	// Đọc bằng ctx TRỐNG cộng Unscoped của tenantScope là không được — ở đây
	// đọc thẳng bằng câu SQL lọc tay để bài kiểm tự nhìn cột tenant_id, thay vì
	// tin vào chính bộ lọc mà nó đang kiểm.
	nen := tenant.WithoutScope(context.Background(), "bài kiểm tự soi cột tenant_id")
	if err := db.WithContext(nen).Raw(
		"SELECT id, tenant_id, type, name, is_default FROM income_expense_types WHERE tenant_id = ?",
		tenantID).Scan(&ds).Error; err != nil {
		t.Fatalf("không đọc được loại thu chi vừa gieo: %v", err)
	}

	if len(ds) != len(loaiThuChiKhoiDiem) {
		t.Fatalf("cửa hàng mới phải có %d phân loại gieo sẵn, nhận %d", len(loaiThuChiKhoiDiem), len(ds))
	}

	var thu, chi int
	for _, l := range ds {
		if l.TenantID != tenantID {
			t.Fatalf("dòng %q mang tenant_id %d, phải là %d", l.Name, l.TenantID, tenantID)
		}
		// Gieo sẵn KHÔNG mang cờ hệ thống: chủ tiệm không dùng dòng nào thì phải
		// xoá được. Bản cũ v2 khoá cứng cả danh sách này.
		if l.IsDefault {
			t.Fatalf("dòng gieo sẵn %q không được mang cờ hệ thống", l.Name)
		}
		switch l.Type {
		case domain.LoaiThu:
			thu++
		case domain.LoaiChi:
			chi++
		default:
			t.Fatalf("dòng %q mang type lạ: %d", l.Name, l.Type)
		}
	}
	if thu == 0 || chi == 0 {
		t.Fatalf("phải gieo cả hai vế, nhận %d thu và %d chi", thu, chi)
	}
}

// xoaCuaHangThu dọn sạch cửa hàng bài kiểm vừa dựng.
//
// Tắt kiểm khoá ngoại rồi xoá theo danh sách bảng tự dò, cùng cách bộ apitest
// làm: xoá TRỌN VẸN một cửa hàng nên không để lại dòng mồ côi nào.
func xoaCuaHangThu(t *testing.T, db *gorm.DB, tenantID uint) {
	t.Helper()

	nen := tenant.WithoutScope(context.Background(), "dọn cửa hàng của bài kiểm")
	db = db.WithContext(nen)

	var bang []string
	if err := db.Raw(
		`SELECT table_name FROM information_schema.columns
		  WHERE table_schema = DATABASE() AND column_name = 'tenant_id'`).Scan(&bang).Error; err != nil {
		t.Fatalf("không liệt kê được bảng có tenant_id: %v", err)
	}

	if err := db.Exec("SET FOREIGN_KEY_CHECKS = 0").Error; err != nil {
		t.Fatalf("không tắt được kiểm khoá ngoại: %v", err)
	}
	defer func() { _ = db.Exec("SET FOREIGN_KEY_CHECKS = 1").Error }()

	for _, b := range bang {
		if err := db.Exec(fmt.Sprintf("DELETE FROM `%s` WHERE tenant_id = ?", b), tenantID).Error; err != nil {
			t.Fatalf("không xoá được dữ liệu thử ở bảng %s: %v", b, err)
		}
	}
	if err := db.Exec("DELETE FROM tenants WHERE id = ?", tenantID).Error; err != nil {
		t.Fatalf("không xoá được cửa hàng thử: %v", err)
	}
}
