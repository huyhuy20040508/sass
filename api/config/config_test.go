package config

import (
	"strings"
	"testing"
)

// DSN phải CỘNG THÊM luật sql_mode, không được đặt đè.
//
// Chỗ này trông vụn nhưng là chi tiết dễ bị "dọn cho gọn" nhất trong tệp: viết
// thẳng sql_mode='STRICT_TRANS_TABLES,...' ngắn hơn hẳn và chạy vẫn ra xanh trên
// máy phát triển. Cái mất đi thì không thấy ngay — `SET sql_mode` THAY THẾ cả
// danh sách, nên trên CI (MySQL 8) nó gỡ luôn ONLY_FULL_GROUP_BY khỏi phiên
// test, tức là tắt đúng cái chốt đã tìm ra lỗi trang Báo cáo → theo size.
//
// Bài kiểm này để lần đó xảy ra thì có người nói trước.
func TestDSN_CongThemSQLModeChuKhongDatDe(t *testing.T) {
	d := DatabaseConfig{
		User: "u", Password: "p", Host: "h", Port: "3306", Name: "db",
		SQLModeThem: SQLModeKiemThu,
	}

	dsn := d.DSN()

	// Tham số đi qua url.QueryEscape nên so trên bản đã giải mã cho dễ đọc lỗi.
	giaiMa := strings.NewReplacer("%28", "(", "%29", ")", "%40", "@", "%27", "'",
		"%2C", ",", "%3D", "=", "+", " ").Replace(dsn)

	if !strings.Contains(giaiMa, "CONCAT(@@sql_mode") {
		t.Fatalf("DSN phải cộng thêm vào @@sql_mode đang có, không đặt đè.\nDSN: %s", giaiMa)
	}
	if !strings.Contains(giaiMa, SQLModeKiemThu) {
		t.Fatalf("DSN thiếu chính mấy luật cần cộng vào.\nDSN: %s", giaiMa)
	}
	// ONLY_FULL_GROUP_BY không được tự chui vào: hai hệ hiểu nó khác nhau nên nó
	// là việc của CI, xem chú thích ở SQLModeKiemThu.
	if strings.Contains(SQLModeKiemThu, "ONLY_FULL_GROUP_BY") {
		t.Fatal("ONLY_FULL_GROUP_BY không được nằm trong SQLModeKiemThu — nó báo oan trên MariaDB")
	}
}

// Không khai gì thì DSN không được đụng tới sql_mode của máy chủ.
//
// Đây là đường đi của app chạy thật. Lỡ tay đặt mặc định khác rỗng là mã nguồn
// âm thầm ghi đè cấu hình máy chủ, và người sửa my.cnf sẽ không hiểu vì sao đổi
// mãi không có tác dụng.
func TestDSN_KhongKhaiThiKhongDungToiSQLMode(t *testing.T) {
	d := DatabaseConfig{User: "u", Password: "p", Host: "h", Port: "3306", Name: "db"}

	if strings.Contains(d.DSN(), "sql_mode") {
		t.Fatalf("DSN của app chạy thật không được nhắc tới sql_mode: %s", d.DSN())
	}
	if strings.Contains(d.DSNMayChu(), "sql_mode") {
		t.Fatalf("DSNMayChu không được nhắc tới sql_mode: %s", d.DSNMayChu())
	}
}
