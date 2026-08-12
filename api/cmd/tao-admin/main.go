// tao-admin — tạo tài khoản quản trị đầu tiên của một cửa hàng.
//
// Từ khi màn hình đăng nhập có 3 ô, "tài khoản quản trị" không còn là một dòng
// users đứng một mình: nó là bộ ba CỬA HÀNG · TÊN ĐĂNG NHẬP · MẬT KHẨU. Công cụ
// này dựng đủ cả bộ — vai trò RBAC (nếu database còn trắng), cửa hàng, rồi tài
// khoản — nên sau khi chạy là đăng nhập được ngay, không phải gõ SQL tay.
//
// Thay cho database/seed.sql: tệp đó đã bị tắt toàn bộ (xem chú thích trong tệp),
// và kể cả bật lại thì nó cũng chỉ tạo được tài khoản của MỘT cửa hàng cố định
// với mật khẩu nằm công khai trong repo.
//
//	go run ./cmd/tao-admin                                      # hỏi từng ô
//	go run ./cmd/tao-admin --ma-cua-hang quochuy --ten-dang-nhap admin --mat-khau '...'
//	go run ./cmd/tao-admin --ma-cua-hang quochuy --ten-dang-nhap admin --doi-mat-khau
//
// Kết nối đúng database ghi trong api/.env — cục bộ, thử hay thật là do tệp .env
// quyết định, công cụ không tự đoán.
package main

import (
	"bufio"
	"database/sql"
	"errors"
	"flag"
	"fmt"
	"os"
	"regexp"
	"strings"

	"sass-api/config"
	"sass-api/pkg/hash"

	_ "github.com/go-sql-driver/mysql"
)

// Bộ ký tự của hai ô đầu. Giống hệt bên tầng nghiệp vụ (service.usernameRe) và
// bên Shop Admin: chuẩn hoá lệch nhau giữa ba nơi thì tài khoản tạo ra ở đây
// không đăng nhập được ở kia.
var (
	maCuaHangRe   = regexp.MustCompile(`^[a-z0-9][a-z0-9._-]{1,29}$`)
	tenDangNhapRe = regexp.MustCompile(`^[a-z0-9._-]{3,50}$`)
)

// matKhauToiThieu khớp với ràng buộc `min=6` của API (dto.UserRequest).
const matKhauToiThieu = 6

// Bốn vai trò cố định, khớp seed.sql cũ và các hằng số trong domain
// (SuperAdminRoleID = 1...). Code tham chiếu thẳng bằng id nên id phải đúng.
var vaiTroChuan = []struct {
	ID      int
	Ten     string
	HienThi string
	MoTa    string
}{
	{1, "super_admin", "Super Admin", "Toàn quyền hệ thống"},
	{2, "admin", "Quản trị viên", "Quản lý sản phẩm, đơn hàng"},
	{3, "staff", "Nhân viên", "Xử lý đơn hàng, kho"},
	{4, "customer", "Khách hàng", "Người dùng cuối"},
}

func main() {
	var (
		maCuaHang   = flag.String("ma-cua-hang", "", "ô 1 — mã cửa hàng (tenants.code), vd: quochuy")
		tenCuaHang  = flag.String("ten-cua-hang", "", "tên cửa hàng hiển thị (mặc định: lấy theo mã)")
		tenDangNhap = flag.String("ten-dang-nhap", "", "ô 2 — tên đăng nhập, vd: admin")
		matKhau     = flag.String("mat-khau", "", "ô 3 — mật khẩu (bỏ trống thì hỏi)")
		hoTen       = flag.String("ho-ten", "", "họ tên hiển thị (mặc định: Quản trị viên)")
		email       = flag.String("email", "", "email liên lạc (mặc định: <tên đăng nhập>@<mã cửa hàng>.local)")
		vaiTro      = flag.String("vai-tro", "super_admin", "super_admin | admin | staff")
		doiMatKhau  = flag.Bool("doi-mat-khau", false, "tài khoản đã có: đặt lại mật khẩu thay vì báo lỗi")
	)
	flag.Parse()

	if err := chay(*maCuaHang, *tenCuaHang, *tenDangNhap, *matKhau, *hoTen, *email, *vaiTro, *doiMatKhau); err != nil {
		fmt.Fprintln(os.Stderr, "\n  LỖI: "+err.Error())
		os.Exit(1)
	}
}

func chay(maCuaHang, tenCuaHang, tenDangNhap, matKhau, hoTen, email, vaiTro string, doiMatKhau bool) error {
	doc := bufio.NewReader(os.Stdin)

	maCuaHang = strings.ToLower(strings.TrimSpace(maCuaHang))
	if maCuaHang == "" {
		maCuaHang = strings.ToLower(hoi(doc, "Mã cửa hàng (ô 1)"))
	}
	if !maCuaHangRe.MatchString(maCuaHang) {
		return errors.New("mã cửa hàng chỉ gồm chữ thường không dấu, số, dấu chấm, gạch ngang hoặc gạch dưới (2–30 ký tự), bắt đầu bằng chữ hoặc số")
	}

	tenDangNhap = strings.ToLower(strings.TrimSpace(tenDangNhap))
	if tenDangNhap == "" {
		tenDangNhap = strings.ToLower(hoi(doc, "Tên đăng nhập (ô 2)"))
	}
	if !tenDangNhapRe.MatchString(tenDangNhap) {
		return errors.New("tên đăng nhập chỉ gồm chữ thường không dấu, số, dấu chấm, gạch ngang hoặc gạch dưới (3–50 ký tự)")
	}

	// Mật khẩu HIỆN RA trên màn hình khi phải hỏi: ẩn nó cần thêm thư viện đọc
	// bàn phím thô, mà công cụ này chạy trên console máy chủ lúc cài đặt chứ
	// không phải nơi công cộng. Muốn kín thì truyền sẵn qua --mat-khau.
	if matKhau == "" && !doiMatKhau {
		matKhau = hoi(doc, "Mật khẩu (ô 3, sẽ hiện ra màn hình)")
	}
	if matKhau == "" && doiMatKhau {
		matKhau = hoi(doc, "Mật khẩu mới (sẽ hiện ra màn hình)")
	}
	if len([]rune(matKhau)) < matKhauToiThieu {
		return fmt.Errorf("mật khẩu phải từ %d ký tự trở lên", matKhauToiThieu)
	}

	if !hopLeVaiTro(vaiTro) {
		return errors.New("vai trò phải là super_admin, admin hoặc staff")
	}
	if tenCuaHang == "" {
		tenCuaHang = "Cửa hàng " + maCuaHang
	}
	if hoTen == "" {
		hoTen = "Quản trị viên"
	}
	if email == "" {
		email = tenDangNhap + "@" + maCuaHang + ".local"
	}

	cfg, err := config.Load()
	if err != nil {
		return fmt.Errorf("không nạp được cấu hình (.env): %w", err)
	}
	db, err := moDB(cfg.Database)
	if err != nil {
		return err
	}
	defer db.Close()

	fmt.Printf("\n  Database: %s@%s:%s/%s\n\n", cfg.Database.User, cfg.Database.Host, cfg.Database.Port, cfg.Database.Name)

	if err := kiemTraLuocDo(db); err != nil {
		return err
	}
	if err := napVaiTro(db); err != nil {
		return err
	}

	tenantID, moi, err := baoDamCuaHang(db, maCuaHang, tenCuaHang)
	if err != nil {
		return err
	}
	if moi {
		fmt.Printf("  + đã tạo cửa hàng '%s' (%s), tenant_id = %d\n", maCuaHang, tenCuaHang, tenantID)
	} else {
		fmt.Printf("  · cửa hàng '%s' đã có, tenant_id = %d\n", maCuaHang, tenantID)
	}

	bam, err := hash.Hash(matKhau)
	if err != nil {
		return fmt.Errorf("không băm được mật khẩu: %w", err)
	}

	userID, daCo, err := timTaiKhoan(db, tenantID, tenDangNhap)
	if err != nil {
		return err
	}

	switch {
	case daCo && !doiMatKhau:
		return fmt.Errorf("cửa hàng '%s' đã có tài khoản '%s' (id %d). "+
			"Muốn đặt lại mật khẩu thì chạy lại kèm --doi-mat-khau", maCuaHang, tenDangNhap, userID)

	case daCo:
		// Đặt lại mật khẩu VÀ mở khoá: người phải dùng tới lệnh này là người đang
		// đứng ngoài hệ thống, mà tài khoản bị khoá thì mật khẩu mới cũng vô ích.
		if err := datLaiMatKhau(db, userID, bam); err != nil {
			return err
		}
		fmt.Printf("  ✎ đã đặt lại mật khẩu cho '%s' (id %d) và mở khoá tài khoản\n", tenDangNhap, userID)

	default:
		userID, err = taoTaiKhoan(db, tenantID, tenDangNhap, hoTen, email, bam, vaiTro)
		if err != nil {
			return err
		}
		fmt.Printf("  + đã tạo tài khoản '%s' (id %d, vai trò %s)\n", tenDangNhap, userID, vaiTro)
	}

	fmt.Printf("\n  Đăng nhập Shop Admin bằng 3 ô:\n")
	fmt.Printf("    mã cửa hàng   : %s\n", maCuaHang)
	fmt.Printf("    tên đăng nhập : %s\n", tenDangNhap)
	fmt.Printf("    mật khẩu      : (chuỗi vừa nhập)\n\n")

	return nil
}

// hoi đọc một dòng từ bàn phím. Câu hỏi bỏ trống thì hỏi lại — mọi ô ở đây đều
// bắt buộc, nhận chuỗi rỗng chỉ để rồi báo lỗi ở dưới là hành người dùng.
func hoi(doc *bufio.Reader, nhan string) string {
	for {
		fmt.Printf("  %s: ", nhan)
		dong, err := doc.ReadString('\n')
		if err != nil && strings.TrimSpace(dong) == "" {
			// Không có bàn phím (chạy trong script mà quên truyền cờ): thoát hẳn
			// thay vì quay vòng vô tận.
			fmt.Fprintln(os.Stderr, "\n  LỖI: thiếu tham số mà không có bàn phím để hỏi — truyền đủ các cờ --ma-cua-hang, --ten-dang-nhap, --mat-khau")
			os.Exit(2)
		}
		if v := strings.TrimSpace(dong); v != "" {
			return v
		}
	}
}

func hopLeVaiTro(ten string) bool {
	return ten == "super_admin" || ten == "admin" || ten == "staff"
}

func moDB(cfg config.DatabaseConfig) (*sql.DB, error) {
	db, err := sql.Open("mysql", cfg.DSN())
	if err != nil {
		return nil, fmt.Errorf("không mở được kết nối: %w", err)
	}
	if err := db.Ping(); err != nil {
		return nil, fmt.Errorf("không kết nối được MySQL (%s@%s:%s/%s): %w",
			cfg.User, cfg.Host, cfg.Port, cfg.Name, err)
	}
	return db, nil
}

// kiemTraLuocDo chặn sớm khi database chưa chạy migration.
//
// Không có bước này thì lỗi hiện ra là "Table 'tenants' doesn't exist" giữa
// chừng, sau khi đã kịp ghi vài dòng — người chạy không biết mình quên gì.
func kiemTraLuocDo(db *sql.DB) error {
	// Hỏi qua information_schema chứ không phải `SHOW TABLES LIKE ?`: SHOW không
	// nhận tham số trong câu lệnh chuẩn bị sẵn, MariaDB trả thẳng lỗi cú pháp ở
	// dấu '?'.
	for _, bang := range []string{"roles", "tenants", "shops", "users"} {
		var so int
		err := db.QueryRow(
			`SELECT COUNT(*) FROM information_schema.tables
			 WHERE table_schema = DATABASE() AND table_name = ?`, bang).Scan(&so)
		if err != nil {
			return fmt.Errorf("không đọc được danh sách bảng: %w", err)
		}
		if so == 0 {
			return fmt.Errorf("database chưa có bảng %s — chạy `go run ./cmd/migrate chay` trước", bang)
		}
	}
	return nil
}

// napVaiTro bảo đảm đủ bốn vai trò RBAC.
//
// Cần thiết vì database/seed.sql đã tắt: bản cài trắng không có dòng nào trong
// bảng roles, mà middleware phân quyền tham chiếu thẳng tới id 1–4.
func napVaiTro(db *sql.DB) error {
	for _, v := range vaiTroChuan {
		_, err := db.Exec(
			`INSERT IGNORE INTO roles (id, name, display_name, description, created_at, updated_at)
			 VALUES (?, ?, ?, ?, NOW(3), NOW(3))`,
			v.ID, v.Ten, v.HienThi, v.MoTa,
		)
		if err != nil {
			return fmt.Errorf("không nạp được vai trò %s: %w", v.Ten, err)
		}
	}
	return nil
}

// baoDamCuaHang trả về tenant_id của mã cửa hàng, tạo mới nếu chưa có.
func baoDamCuaHang(db *sql.DB, ma, ten string) (int64, bool, error) {
	var id int64
	err := db.QueryRow("SELECT id FROM tenants WHERE code = ?", ma).Scan(&id)
	if err == nil {
		return id, false, nil
	}
	if !errors.Is(err, sql.ErrNoRows) {
		return 0, false, fmt.Errorf("không tra được cửa hàng: %w", err)
	}

	res, err := db.Exec(
		`INSERT INTO tenants (code, name, status, created_at, updated_at)
		 VALUES (?, ?, 'active', NOW(3), NOW(3))`, ma, ten)
	if err != nil {
		return 0, false, fmt.Errorf("không tạo được cửa hàng: %w", err)
	}
	id, err = res.LastInsertId()
	if err != nil {
		return 0, false, err
	}

	// Mỗi tenant phải có ít nhất một chi nhánh: bảng giao dịch mang shop_id, và
	// gói Khởi đầu / Cửa hàng = tenant có ĐÚNG MỘT shop (giao diện ẩn hẳn khái
	// niệm chi nhánh khi chỉ có một).
	if _, err := db.Exec(
		`INSERT INTO shops (tenant_id, code, name, is_active, created_at, updated_at)
		 VALUES (?, 'mac-dinh', ?, 1, NOW(3), NOW(3))`, id, ten); err != nil {
		return 0, false, fmt.Errorf("không tạo được chi nhánh mặc định: %w", err)
	}

	return id, true, nil
}

// timTaiKhoan tra theo đúng khoá uq_users_username: (tenant_id, username).
//
// Tính cả tài khoản đã xoá mềm — khoá unique không loại chúng ra, nên tạo đè lên
// sẽ vỡ khoá; thà báo "đã có" để người chạy quyết định.
func timTaiKhoan(db *sql.DB, tenantID int64, tenDangNhap string) (int64, bool, error) {
	var id int64
	err := db.QueryRow(
		"SELECT id FROM users WHERE tenant_id = ? AND username = ?", tenantID, tenDangNhap).Scan(&id)
	switch {
	case errors.Is(err, sql.ErrNoRows):
		return 0, false, nil
	case err != nil:
		return 0, false, fmt.Errorf("không tra được tài khoản: %w", err)
	default:
		return id, true, nil
	}
}

func taoTaiKhoan(db *sql.DB, tenantID int64, tenDangNhap, hoTen, email, bam, vaiTro string) (int64, error) {
	var roleID int
	for _, v := range vaiTroChuan {
		if v.Ten == vaiTro {
			roleID = v.ID
		}
	}

	res, err := db.Exec(
		`INSERT INTO users (tenant_id, username, role_id, full_name, email, password_hash,
		                    status, email_verified_at, created_at, updated_at)
		 VALUES (?, ?, ?, ?, ?, ?, 'active', NOW(3), NOW(3), NOW(3))`,
		tenantID, tenDangNhap, roleID, hoTen, email, bam)
	if err != nil {
		return 0, fmt.Errorf("không tạo được tài khoản (email '%s' có thể đã có người dùng): %w", email, err)
	}
	return res.LastInsertId()
}

func datLaiMatKhau(db *sql.DB, userID int64, bam string) error {
	_, err := db.Exec(
		`UPDATE users SET password_hash = ?, status = 'active', deleted_at = NULL, updated_at = NOW(3)
		 WHERE id = ?`, bam, userID)
	if err != nil {
		return fmt.Errorf("không đặt lại được mật khẩu: %w", err)
	}
	return nil
}
