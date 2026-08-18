// quyen — gieo NHÓM QUYỀN mặc định cho các cửa hàng đang có, rồi xếp người dùng
// vào nhóm theo vai trò họ đang mang.
//
// Chạy MỘT LẦN sau khi migration 0012 lên. Chạy lại được bao nhiêu lần cũng
// được: nhóm đã có thì bỏ qua, người đã gán nhóm thì không đụng tới.
//
//	go run ./cmd/quyen            # xem trước, không ghi gì
//	go run ./cmd/quyen -ghi       # ghi thật
//
// VÌ SAO LÀ MỘT LỆNH CHỨ KHÔNG PHẢI SQL TRONG MIGRATION: danh sách quyền của
// nhóm "Thu ngân" nằm trong mã nguồn Go (domain.QuyenThuNgan). Chép nó sang SQL
// là tạo bản thứ hai để hai bên lệch nhau ở lần sửa danh mục đầu tiên — mà lệch
// ở đây nghĩa là một cửa hàng nào đó có thu ngân thiếu hoặc thừa quyền.
//
// Kết nối đúng database ghi trong api/.env, y như cmd/tao-admin.
package main

import (
	"database/sql"
	"flag"
	"fmt"
	"os"
	"strings"

	"sass-api/config"
	"sass-api/internal/domain"

	_ "github.com/go-sql-driver/mysql"
)

// Vai trò nào vào nhóm nào. Đây là HỢP ĐỒNG của lượt di trú: sau khi chạy,
// không ai mất quyền và cũng không ai được thêm quyền so với hôm trước.
//
// super_admin (1) cũng được gán, dù lúc chạy nó đi thẳng không tra bảng — để
// trống thì màn hình Nhân sự hiện một ô Nhóm quyền rỗng cho chính chủ tiệm, và
// người đọc sẽ tưởng mình chưa được cấp gì.
var nhomTheoVaiTro = map[int]string{
	1: domain.NhomQuyenQuanLy,
	2: domain.NhomQuyenQuanLy,
	3: domain.NhomQuyenThuNgan,
	// 4 = khách hàng: không gán. Họ không có đường nào vào khu quản trị, và gán
	// nhóm cho vài nghìn người mua hàng là dựng một quan hệ không ai đọc.
}

func main() {
	ghi := flag.Bool("ghi", false, "ghi thật; bỏ trống thì chỉ xem trước")
	flag.Parse()

	cfg, err := config.Load()
	if err != nil {
		thoat("không đọc được cấu hình: %v", err)
	}

	dsn := fmt.Sprintf("%s:%s@tcp(%s:%s)/%s?parseTime=true&charset=utf8mb4",
		cfg.Database.User, cfg.Database.Password,
		cfg.Database.Host, cfg.Database.Port, cfg.Database.Name)

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		thoat("không mở được kết nối: %v", err)
	}
	defer db.Close()

	if err := db.Ping(); err != nil {
		thoat("không kết nối được database: %v", err)
	}

	fmt.Printf("\n  Database: %s @ %s:%s\n", cfg.Database.Name, cfg.Database.Host, cfg.Database.Port)
	if !*ghi {
		fmt.Println("  Chế độ  : XEM TRƯỚC (thêm -ghi để ghi thật)")
	}
	fmt.Println()

	tenants, err := docTenants(db)
	if err != nil {
		thoat("không đọc được danh sách cửa hàng: %v", err)
	}
	if len(tenants) == 0 {
		fmt.Println("  Chưa có cửa hàng nào. Không có gì để làm.")

		return
	}

	tongNhom, tongNguoi := 0, 0
	for _, tid := range tenants {
		nhom, nguoi, err := gieoChoCuaHang(db, tid, *ghi)
		if err != nil {
			thoat("cửa hàng %d: %v", tid, err)
		}
		tongNhom += nhom
		tongNguoi += nguoi
	}

	fmt.Printf("\n  Cửa hàng : %d\n", len(tenants))
	fmt.Printf("  Nhóm dựng: %d\n", tongNhom)
	fmt.Printf("  Người gán: %d\n", tongNguoi)
	if !*ghi {
		fmt.Println("\n  => Chưa ghi gì cả. Chạy lại với -ghi để thực hiện.")
	}
	fmt.Println()
}

func docTenants(db *sql.DB) ([]uint, error) {
	rows, err := db.Query("SELECT id FROM tenants ORDER BY id")
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var ds []uint
	for rows.Next() {
		var id uint
		if err := rows.Scan(&id); err != nil {
			return nil, err
		}
		ds = append(ds, id)
	}

	return ds, rows.Err()
}

// gieoChoCuaHang dựng hai nhóm mặc định rồi xếp người vào, cho MỘT cửa hàng.
func gieoChoCuaHang(db *sql.DB, tenantID uint, ghi bool) (int, int, error) {
	soNhom, soNguoi := 0, 0
	maNhomID := map[string]uint{}

	for _, nm := range domain.NhomDungSan() {
		id, coSan, err := timHoacTaoNhom(db, tenantID, nm, ghi)
		if err != nil {
			return 0, 0, err
		}
		maNhomID[nm.Code] = id
		if !coSan {
			soNhom++
			fmt.Printf("  [cửa hàng %d] dựng nhóm %-9s (%d quyền)\n",
				tenantID, nm.Code, soQuyen(nm))
		}
	}

	for roleID, ma := range nhomTheoVaiTro {
		n, err := ganNhom(db, tenantID, roleID, maNhomID[ma], ghi)
		if err != nil {
			return 0, 0, err
		}
		if n > 0 {
			fmt.Printf("  [cửa hàng %d] vai trò %d -> nhóm %-9s : %d tài khoản\n",
				tenantID, roleID, ma, n)
		}
		soNguoi += n
	}

	return soNhom, soNguoi, nil
}

// timHoacTaoNhom trả về id nhóm, và cho biết nó đã có sẵn hay vừa dựng.
func timHoacTaoNhom(db *sql.DB, tenantID uint, nm domain.NhomMacDinh, ghi bool) (uint, bool, error) {
	var id uint
	err := db.QueryRow(
		"SELECT id FROM permission_groups WHERE tenant_id = ? AND code = ?",
		tenantID, nm.Code,
	).Scan(&id)
	if err == nil {
		return id, true, nil
	}
	if err != sql.ErrNoRows {
		return 0, false, err
	}
	if !ghi {
		return 0, false, nil
	}

	res, err := db.Exec(`INSERT INTO permission_groups
		(tenant_id, code, name, description, is_system, full_access, created_at, updated_at)
		VALUES (?, ?, ?, ?, 1, ?, NOW(3), NOW(3))`,
		tenantID, nm.Code, nm.Name, nm.MoTa, nm.FullAccess)
	if err != nil {
		return 0, false, err
	}
	last, err := res.LastInsertId()
	if err != nil {
		return 0, false, err
	}
	id = uint(last)

	// Nhóm toàn quyền KHÔNG có hàng quyền nào, và đó là đúng — xem chú thích cột
	// full_access ở migration 0012.
	for _, q := range nm.Quyen {
		if _, err := db.Exec(`INSERT INTO permission_group_items
			(tenant_id, group_id, permission, created_at, updated_at)
			VALUES (?, ?, ?, NOW(3), NOW(3))`, tenantID, id, q); err != nil {
			return 0, false, err
		}
	}

	return id, false, nil
}

// ganNhom xếp những tài khoản CHƯA thuộc nhóm nào vào nhóm ứng với vai trò.
//
// Một người mang được nhiều nhóm, nên lệnh này chỉ gieo cho ai chưa có nhóm nào
// — nó là lượt di trú, không phải công cụ phân quyền.
func ganNhom(db *sql.DB, tenantID uint, roleID int, groupID uint, ghi bool) (int, error) {
	// `NOT EXISTS` là điều kiện làm cho lệnh chạy lại được: ai đã được xếp vào
	// nhóm nào rồi — kể cả cửa hàng tự đổi sang nhóm khác — thì không bị kéo về.
	dieuKien := `FROM users u
		WHERE u.tenant_id = ? AND u.role_id = ? AND u.deleted_at IS NULL
		  AND NOT EXISTS (SELECT 1 FROM user_permission_groups g WHERE g.user_id = u.id)`

	if !ghi || groupID == 0 {
		var n int
		err := db.QueryRow("SELECT COUNT(*) "+dieuKien, tenantID, roleID).Scan(&n)

		return n, err
	}

	res, err := db.Exec(`INSERT INTO user_permission_groups
		(tenant_id, user_id, group_id, created_at, updated_at)
		SELECT u.tenant_id, u.id, ?, NOW(3), NOW(3) `+dieuKien,
		groupID, tenantID, roleID)
	if err != nil {
		return 0, err
	}
	n, err := res.RowsAffected()

	return int(n), err
}

func soQuyen(nm domain.NhomMacDinh) int {
	if nm.FullAccess {
		return len(domain.TatCaQuyen())
	}

	return len(nm.Quyen)
}

func thoat(dinhDang string, v ...any) {
	fmt.Fprintf(os.Stderr, "\n  LỖI: %s\n\n", strings.TrimSpace(fmt.Sprintf(dinhDang, v...)))
	os.Exit(1)
}
