// ten-mien — quản lý sổ TÊN MIỀN của nền tảng.
//
// Từ đợt phân giải theo tên miền, đây là bảng quyết định "vào địa chỉ này thì
// đang mở tiệm nào" cho KHÁCH VÃNG LAI (xem middleware.TenantFromHost). Bảng
// trống thì không tên miền nào phân giải được và cụm bán hàng cho khách chỉ phục
// vụ người đã đăng nhập — tức là gần như không ai.
//
//	go run ./cmd/ten-mien danh-sach
//	go run ./cmd/ten-mien them --ma-cua-hang quochuy --host quochuy.selliotech.store --chinh
//	go run ./cmd/ten-mien xoa  --host quochuy.selliotech.store
//
// Công cụ chạm CẢ HAI database: đọc sổ cửa hàng ở data plane (.env DB_*) để biết
// mã cửa hàng là ai, rồi ghi sổ tên miền ở control plane (.env PLATFORM_DB_*).
//
// KHÔNG có đường nào cho CHÍNH KHÁCH tự khai tên miền, và đó là chủ ý: host là
// khoá unique toàn bảng, ai khai trước giữ trước — mở cho khách tự khai là mở
// đường một người chiếm tên miền của người khác. Vì vậy repository đọc bảng này
// coi mọi dòng có mặt là dòng đã được duyệt.
package main

import (
	"database/sql"
	"errors"
	"flag"
	"fmt"
	"os"
	"strings"
	"text/tabwriter"

	"sass-api/config"
	"sass-api/internal/repository"

	_ "github.com/go-sql-driver/mysql"
)

func main() {
	if len(os.Args) < 2 {
		dungCachDung()
		os.Exit(2)
	}

	lenh := os.Args[1]
	cd := flag.NewFlagSet(lenh, flag.ExitOnError)
	var (
		maCuaHang = cd.String("ma-cua-hang", "", "mã cửa hàng (tenants.code) — lệnh `them`")
		host      = cd.String("host", "", "tên miền, không scheme, không cổng")
		chinh     = cd.Bool("chinh", false, "đặt làm tên miền CHÍNH (dùng dựng link trong email, hoá đơn)")
	)
	_ = cd.Parse(os.Args[2:])

	if err := chay(lenh, *maCuaHang, *host, *chinh); err != nil {
		fmt.Fprintln(os.Stderr, "\n  LỖI: "+err.Error())
		os.Exit(1)
	}
}

func dungCachDung() {
	fmt.Fprintln(os.Stderr, `
  Quản lý sổ tên miền của nền tảng.

    go run ./cmd/ten-mien danh-sach
    go run ./cmd/ten-mien them --ma-cua-hang <mã> --host <tên miền> [--chinh]
    go run ./cmd/ten-mien xoa --host <tên miền>`)
}

func chay(lenh, maCuaHang, host string, chinh bool) error {
	cfg, err := config.Load()
	if err != nil {
		return fmt.Errorf("không nạp được cấu hình (.env): %w", err)
	}

	nenTang, err := moDB(cfg.Platform)
	if err != nil {
		return fmt.Errorf("control plane (%s): %w — chạy `go run ./cmd/migrate -nen-tang chay` trước", cfg.Platform.Name, err)
	}
	defer nenTang.Close()

	if err := kiemTraLuocDo(nenTang); err != nil {
		return err
	}

	switch lenh {
	case "danh-sach":
		return danhSach(nenTang)

	case "them":
		banHang, err := moDB(cfg.Database)
		if err != nil {
			return fmt.Errorf("database bán hàng (%s): %w", cfg.Database.Name, err)
		}
		defer banHang.Close()
		return them(banHang, nenTang, maCuaHang, host, chinh)

	case "xoa":
		return xoa(nenTang, host)

	default:
		dungCachDung()
		return fmt.Errorf("không hiểu lệnh %q", lenh)
	}
}

// kiemTraLuocDo chặn sớm khi control plane chưa chạy migration — không có bước
// này thì lỗi hiện ra là "Table 'tenant_domains' doesn't exist" ở giữa chừng.
func kiemTraLuocDo(db *sql.DB) error {
	for _, bang := range []string{"tenants", "tenant_domains"} {
		var so int
		err := db.QueryRow(
			`SELECT COUNT(*) FROM information_schema.tables
			  WHERE table_schema = DATABASE() AND table_name = ?`, bang).Scan(&so)
		if err != nil {
			return fmt.Errorf("không đọc được danh sách bảng: %w", err)
		}
		if so == 0 {
			return fmt.Errorf("control plane chưa có bảng %s — chạy `go run ./cmd/migrate -nen-tang chay` trước", bang)
		}
	}
	return nil
}

func danhSach(nenTang *sql.DB) error {
	rows, err := nenTang.Query(
		`SELECT d.host, d.kind, d.is_primary, t.code, t.name, t.status,
		        d.verified_at IS NOT NULL
		   FROM tenant_domains d
		   JOIN tenants t ON t.id = d.tenant_id
		  ORDER BY t.code, d.is_primary DESC, d.host`)
	if err != nil {
		return fmt.Errorf("không đọc được sổ tên miền: %w", err)
	}
	defer rows.Close()

	w := tabwriter.NewWriter(os.Stdout, 0, 0, 2, ' ', 0)
	fmt.Fprintln(w, "\nTÊN MIỀN\tLOẠI\tCHÍNH\tCỬA HÀNG\tTRẠNG THÁI\tĐÃ XÁC MINH DNS")

	var so int
	for rows.Next() {
		var (
			host, kind, code, ten, trangThai string
			laChinh, daXacMinh               bool
		)
		if err := rows.Scan(&host, &kind, &laChinh, &code, &ten, &trangThai, &daXacMinh); err != nil {
			return err
		}
		so++
		fmt.Fprintf(w, "%s\t%s\t%s\t%s (%s)\t%s\t%s\n",
			host, kind, danhDau(laChinh), code, ten, trangThai, danhDau(daXacMinh))
	}
	if err := rows.Err(); err != nil {
		return err
	}
	_ = w.Flush()

	if so == 0 {
		fmt.Println("\n  Sổ trống — chưa tên miền nào phân giải được, khách vãng lai chưa mua được ở đâu cả.")
	}
	fmt.Println()

	return nil
}

func danhDau(v bool) string {
	if v {
		return "x"
	}
	return "-"
}

// them đăng ký một tên miền cho cửa hàng.
//
// Chép luôn dòng cửa hàng sang sổ nền tảng nếu bên đó chưa có: tenant_domains có
// khoá ngoại trỏ vào tenants của CHÍNH lược đồ này, mà id thì không tự tăng — nó
// là số chung với data plane. Không chép thì lệnh hỏng vì vi phạm khoá ngoại, và
// người chạy phải tự gõ SQL để dựng dòng đó.
func them(banHang, nenTang *sql.DB, maCuaHang, host string, chinh bool) error {
	maCuaHang = strings.ToLower(strings.TrimSpace(maCuaHang))
	if maCuaHang == "" {
		return errors.New("thiếu --ma-cua-hang")
	}

	// Chuẩn hoá bằng ĐÚNG hàm mà middleware dùng lúc đọc header Host. Hai bên lệch
	// nhau một dấu chấm hay một chữ hoa là tên miền vào sổ rồi mà không phân giải
	// được, và không có gì trên màn hình gợi ý vì sao.
	host = repository.ChuanHoaHost(host)
	if host == "" || !strings.Contains(host, ".") {
		return errors.New("--host phải là tên miền đầy đủ, vd: quochuy.selliotech.store")
	}

	var (
		id        int64
		ten       string
		trangThai string
	)
	err := banHang.QueryRow("SELECT id, name, status FROM tenants WHERE code = ?", maCuaHang).
		Scan(&id, &ten, &trangThai)
	if errors.Is(err, sql.ErrNoRows) {
		return fmt.Errorf("không có cửa hàng nào mang mã %q trong database bán hàng", maCuaHang)
	}
	if err != nil {
		return fmt.Errorf("không tra được cửa hàng: %w", err)
	}

	// Ai đang giữ tên miền này? Trả lời trước khi ghi, để thông báo nói đúng
	// chuyện thay vì ném ra một lỗi trùng khoá.
	var chuCu string
	err = nenTang.QueryRow(
		`SELECT t.code FROM tenant_domains d JOIN tenants t ON t.id = d.tenant_id WHERE d.host = ?`, host).
		Scan(&chuCu)
	switch {
	case err == nil && chuCu != maCuaHang:
		return fmt.Errorf("tên miền %s đang thuộc cửa hàng %q — gỡ bằng `ten-mien xoa --host %s` trước", host, chuCu, host)
	case err == nil:
		fmt.Printf("  · %s đã thuộc cửa hàng %s rồi\n", host, maCuaHang)
	case !errors.Is(err, sql.ErrNoRows):
		return fmt.Errorf("không tra được tên miền: %w", err)
	}

	if _, err := nenTang.Exec(
		`INSERT INTO tenants (id, code, name, status, created_at, updated_at)
		 VALUES (?, ?, ?, ?, NOW(3), NOW(3))
		 ON DUPLICATE KEY UPDATE code = VALUES(code), name = VALUES(name)`,
		id, maCuaHang, ten, trangThai); err != nil {
		return fmt.Errorf("không ghi được cửa hàng vào sổ nền tảng: %w", err)
	}

	// Tên miền chính là duy nhất trong một cửa hàng (khoá uq_tenant_domains_primary),
	// nên phải hạ cờ của tên miền cũ TRƯỚC khi dựng cờ cho tên miền mới.
	if chinh {
		if _, err := nenTang.Exec(
			"UPDATE tenant_domains SET is_primary = 0, updated_at = NOW(3) WHERE tenant_id = ? AND host <> ?",
			id, host); err != nil {
			return fmt.Errorf("không hạ được cờ tên miền chính cũ: %w", err)
		}
	}

	if _, err := nenTang.Exec(
		`INSERT INTO tenant_domains (tenant_id, host, kind, is_primary, created_at, updated_at)
		 VALUES (?, ?, ?, ?, NOW(3), NOW(3))
		 ON DUPLICATE KEY UPDATE is_primary = VALUES(is_primary), updated_at = NOW(3)`,
		id, host, loaiTenMien(host), chinh); err != nil {
		return fmt.Errorf("không ghi được tên miền: %w", err)
	}

	fmt.Printf("\n  + %s → cửa hàng %s (%s), tenant_id = %d%s\n", host, maCuaHang, ten, id, keo(chinh, " [chính]", ""))
	if trangThai != "active" {
		fmt.Printf("  ! cửa hàng đang ở trạng thái %q nên trang bán hàng vẫn đóng (403)\n", trangThai)
	}
	fmt.Printf("\n  Còn hai việc NGOÀI database mới vào được bằng địa chỉ này:\n")
	fmt.Printf("    1. DNS của %s phải trỏ về máy chủ này.\n", host)
	fmt.Printf("    2. nginx phải nhận server_name đó và có chứng chỉ HTTPS cho nó.\n\n")

	return nil
}

// loaiTenMien đoán cột `kind` theo đuôi tên miền.
//
// Chỉ là nhãn để đọc, không có tầng nào phân xử theo nó — nên đoán ở đây là vô
// hại, còn bắt người chạy khai thêm một cờ nữa thì chỉ tổ gõ nhầm.
func loaiTenMien(host string) string {
	if strings.HasSuffix(host, ".selliotech.store") {
		return "subdomain"
	}
	return "custom"
}

func keo(dieuKien bool, co, khong string) string {
	if dieuKien {
		return co
	}
	return khong
}

func xoa(nenTang *sql.DB, host string) error {
	host = repository.ChuanHoaHost(host)
	if host == "" {
		return errors.New("thiếu --host")
	}

	res, err := nenTang.Exec("DELETE FROM tenant_domains WHERE host = ?", host)
	if err != nil {
		return fmt.Errorf("không xoá được tên miền: %w", err)
	}
	n, _ := res.RowsAffected()
	if n == 0 {
		return fmt.Errorf("không có tên miền %q trong sổ", host)
	}

	fmt.Printf("\n  - đã gỡ %s khỏi sổ. Khách vào địa chỉ này từ giờ không phân giải được cửa hàng nào.\n\n", host)

	return nil
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
