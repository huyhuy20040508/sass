// Package migrate — công cụ migration có phiên bản cho database.
//
// Vì sao cần: trước đây mọi thay đổi lược đồ nằm trong các tệp alter-*.sql chạy
// TAY theo thứ tự người chạy tự nhớ, không có chỗ nào ghi "database này đã chạy
// tới đâu". Hậu quả là lược đồ của máy cục bộ, bản thử và bản thật có thể lệch
// nhau mà không ai biết, cho tới lúc một truy vấn gãy trên đúng bản thật.
//
// Cách làm ở đây là cách chuẩn của mọi công cụ migration:
//   - Mỗi thay đổi là MỘT tệp .sql đánh số tăng dần, không bao giờ sửa lại sau
//     khi đã chạy ở đâu đó.
//   - Bảng `schema_migrations` trong chính database đó ghi lại đã chạy tệp nào,
//     lúc nào, và VÂN TAY (SHA-256) của nội dung tệp lúc chạy.
//   - Vân tay để phát hiện tệp đã chạy bị sửa nội dung — trường hợp nguy hiểm
//     nhất, vì máy chưa chạy sẽ nhận bản mới còn máy đã chạy giữ bản cũ.
//
// Ba điều CỐ Ý khác với thói quen cũ của dự án:
//
//  1. Tệp migration KHÔNG được chứa `CREATE DATABASE` hay `USE <tên>`. Tên
//     database là của môi trường (cục bộ, thử, thật đều khác nhau), lấy từ .env.
//     Tệp cũ nướng cứng `USE selliotech` nên nạp vào bản thật là ghi nhầm
//     sang một database khác — xem Validate().
//
//  2. MySQL KHÔNG có DDL trong transaction: mỗi lệnh ALTER/CREATE tự chốt ngay.
//     Nên không thể "quay lui cả tệp" khi lỗi giữa chừng. Bù lại bằng cách chạy
//     từng tệp một, dừng ngay khi lỗi và nói rõ tệp nào đang dở.
//
//  3. Không có `down`. Trên database thật, lùi lược đồ gần như luôn kèm mất dữ
//     liệu; cách chữa đúng là viết một migration mới đi tiếp.
package migrate

import (
	"crypto/sha256"
	"database/sql"
	"encoding/hex"
	"errors"
	"fmt"
	"io/fs"
	"path"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"time"
)

// TenBang là bảng ghi lịch sử migration, nằm trong chính database được migrate.
const TenBang = "schema_migrations"

// tenTepHopLe: 0001_ten-viec.sql — số thứ tự 4 chữ số + gạch dưới + tên.
var tenTepHopLe = regexp.MustCompile(`^(\d{4})_([a-z0-9][a-z0-9._-]*)\.sql$`)

// cauLenhCam bắt các lệnh không được phép nằm trong tệp migration.
var cauLenhCam = []struct {
	mau *regexp.Regexp
	vi  string
}{
	{regexp.MustCompile(`(?im)^\s*USE\s+`), "USE <database>"},
	{regexp.MustCompile(`(?im)^\s*CREATE\s+DATABASE`), "CREATE DATABASE"},
	{regexp.MustCompile(`(?im)^\s*DROP\s+DATABASE`), "DROP DATABASE"},
}

// Migration là một tệp .sql đã đọc xong.
type Migration struct {
	Version   int    // 1, 2, 3...
	Ten       string // "nen-2026-08-10"
	TepTin    string // "0001_nen-2026-08-10.sql"
	SQL       string
	VanTay    string // SHA-256 toàn bộ nội dung tệp, dạng hex
	VanTaySQL string // SHA-256 phần lệnh (đã bỏ chú thích), dạng hex
}

// DaChay là một dòng trong bảng schema_migrations.
type DaChay struct {
	Version  int
	Ten      string
	VanTay   string
	ChayLuc  time.Time
	MiliGiay int64

	// VanTaySQL rỗng với các dòng ghi trước khi có cột van_tay_sql — nghĩa là
	// không có bằng chứng để nói phần lệnh có đổi hay không.
	VanTaySQL string
}

// TrangThai là kết quả so sánh giữa thư mục migrations và database.
type TrangThai struct {
	DaChay     []DaChay     // đã ghi trong database, theo thứ tự version
	ConLai     []Migration  // có tệp nhưng database chưa chạy
	LechVanTay []LechVanTay // đã chạy nhưng nội dung tệp bây giờ khác lúc chạy
	ThieuTep   []DaChay     // database ghi đã chạy nhưng không còn tệp tương ứng
}

// LechVanTay: tệp đã chạy rồi mà nội dung bị sửa sau đó.
type LechVanTay struct {
	Version   int
	Ten       string
	VanTayDB  string
	VanTayTep string

	// CoBangChung: dòng trong database có lưu vân tay phần lệnh, nên trả lời
	// được chắc chắn câu "phần SQL có đổi không". Dòng ghi từ trước khi có cột
	// van_tay_sql thì không, và lúc đó ChiChuThich không có ý nghĩa gì.
	CoBangChung bool

	// ChiChuThich: phần lệnh SQL y hệt lúc chạy, chỉ chú thích hoặc khoảng
	// trắng đổi — lược đồ trong database vẫn đúng.
	ChiChuThich bool
}

// Sach trả về true khi database khớp hoàn toàn với thư mục migrations.
func (t TrangThai) Sach() bool {
	return len(t.ConLai) == 0 && len(t.LechVanTay) == 0 && len(t.ThieuTep) == 0
}

// ---------------------------------------------------------------- đọc tệp

// Doc nạp toàn bộ tệp .sql trong thư mục, sắp xếp theo số thứ tự.
//
// Tệp không đúng quy tắc đặt tên bị coi là LỖI chứ không phải bỏ qua im lặng:
// một tệp gõ nhầm tên mà bị lờ đi thì thay đổi lược đồ biến mất không dấu vết.
func Doc(fsys fs.FS, thuMuc string) ([]Migration, error) {
	entries, err := fs.ReadDir(fsys, thuMuc)
	if err != nil {
		return nil, fmt.Errorf("không đọc được thư mục %s: %w", thuMuc, err)
	}

	var ds []Migration
	thayVersion := map[int]string{}

	for _, e := range entries {
		if e.IsDir() {
			continue
		}
		ten := e.Name()
		if !strings.HasSuffix(strings.ToLower(ten), ".sql") {
			continue
		}

		khop := tenTepHopLe.FindStringSubmatch(ten)
		if khop == nil {
			return nil, fmt.Errorf(
				"tên tệp %q sai quy tắc — phải là dạng 0001_ten-viec.sql (4 chữ số, gạch dưới, chữ thường không dấu)", ten)
		}

		version, _ := strconv.Atoi(khop[1])
		if version == 0 {
			return nil, fmt.Errorf("tệp %q dùng số thứ tự 0000; đánh số bắt đầu từ 0001", ten)
		}
		if truoc, trung := thayVersion[version]; trung {
			return nil, fmt.Errorf("hai tệp cùng số thứ tự %04d: %q và %q", version, truoc, ten)
		}
		thayVersion[version] = ten

		noiDung, err := fs.ReadFile(fsys, path.Join(thuMuc, ten))
		if err != nil {
			return nil, fmt.Errorf("không đọc được %s: %w", ten, err)
		}

		sqlText := string(noiDung)
		if err := Validate(ten, sqlText); err != nil {
			return nil, err
		}

		ds = append(ds, Migration{
			Version:   version,
			Ten:       khop[2],
			TepTin:    ten,
			SQL:       sqlText,
			VanTay:    vanTay(sqlText),
			VanTaySQL: vanTaySQL(sqlText),
		})
	}

	sort.Slice(ds, func(i, j int) bool { return ds[i].Version < ds[j].Version })

	return ds, nil
}

// Validate chặn những lệnh không được phép nằm trong tệp migration.
func Validate(tenTep, sqlText string) error {
	khongChuThich := boChuThich(sqlText)

	for _, c := range cauLenhCam {
		if c.mau.MatchString(khongChuThich) {
			return fmt.Errorf(
				"tệp %s có lệnh %s — không được phép: tên database là của môi trường (cục bộ / thử / thật đều khác nhau) "+
					"và công cụ đã kết nối sẵn đúng database theo .env. Để nguyên lệnh này thì chạy trên máy thật là "+
					"ghi nhầm sang database khác", tenTep, c.vi)
		}
	}

	if strings.TrimSpace(khongChuThich) == "" {
		return fmt.Errorf(
			"tệp %s mới chỉ có chú thích, chưa có lệnh SQL nào — viết SQL vào rồi chạy lại "+
				"(hoặc xoá tệp đi nếu không dùng nữa)", tenTep)
	}

	return nil
}

// boChuThich xoá chú thích để không bắt nhầm lệnh nằm trong phần giải thích.
// Chỉ cần đủ tốt cho việc soát lệnh cấm, không phải bộ phân tích SQL đầy đủ.
func boChuThich(s string) string {
	// Chú thích khối /* ... */
	var b strings.Builder
	for {
		i := strings.Index(s, "/*")
		if i < 0 {
			b.WriteString(s)
			break
		}
		b.WriteString(s[:i])
		j := strings.Index(s[i+2:], "*/")
		if j < 0 {
			break
		}
		s = s[i+2+j+2:]
	}

	var dong []string
	for _, l := range strings.Split(b.String(), "\n") {
		t := strings.TrimSpace(l)
		if strings.HasPrefix(t, "--") || strings.HasPrefix(t, "#") {
			continue
		}
		dong = append(dong, l)
	}

	return strings.Join(dong, "\n")
}

func vanTay(s string) string {
	// Chuẩn hoá xuống dòng: cùng một tệp checkout trên Windows (CRLF) và Linux
	// (LF) phải ra cùng vân tay, không thì mỗi lần deploy lại báo lệch.
	s = strings.ReplaceAll(s, "\r\n", "\n")
	tong := sha256.Sum256([]byte(s))

	return hex.EncodeToString(tong[:])
}

// vanTaySQL băm riêng PHẦN LỆNH của tệp: bỏ chú thích, bỏ khoảng trắng thừa.
//
// vanTay băm cả tệp nên sửa một dòng chú thích cũng bị báo lệch y như sửa lược
// đồ, mà hai việc đó khác nhau một trời một vực: chú thích không đổi cái gì
// trong database. Có thêm vân tay này thì `sua-van-tay` mới chứng minh được
// "chỉ chú thích đổi thôi" thay vì bắt người dùng tự hứa.
//
// Vẫn KHÔNG dùng nó thay cho vanTay: tệp migration đã chạy thì mặc định là
// không được đụng vào, kể cả chú thích — báo lệch rồi xác nhận là đúng quy trình.
func vanTaySQL(s string) string {
	s = strings.ReplaceAll(s, "\r\n", "\n")

	var dong []string
	for _, l := range strings.Split(boChuThich(s), "\n") {
		// Gộp khoảng trắng: thụt lề lại cho đẹp không phải là đổi lược đồ.
		if t := strings.Join(strings.Fields(l), " "); t != "" {
			dong = append(dong, t)
		}
	}

	tong := sha256.Sum256([]byte(strings.Join(dong, "\n")))

	return hex.EncodeToString(tong[:])
}

// ---------------------------------------------------------------- database

// DB là phần giao tiếp database mà gói này cần — để test thay bằng bản giả.
type DB interface {
	Exec(query string, args ...any) (sql.Result, error)
	Query(query string, args ...any) (*sql.Rows, error)
	QueryRow(query string, args ...any) *sql.Row
}

// TaoBang tạo bảng lịch sử nếu chưa có.
func TaoBang(db DB) error {
	_, err := db.Exec(`
CREATE TABLE IF NOT EXISTS ` + TenBang + ` (
  version     INT UNSIGNED NOT NULL COMMENT 'số thứ tự trong tên tệp',
  ten         VARCHAR(191) NOT NULL COMMENT 'phần tên sau số thứ tự',
  van_tay     CHAR(64)     NOT NULL COMMENT 'SHA-256 nội dung tệp lúc chạy',
  van_tay_sql CHAR(64)     NOT NULL DEFAULT '' COMMENT 'SHA-256 phần lệnh, đã bỏ chú thích',
  chay_luc    DATETIME(3)  NOT NULL,
  mili_giay   BIGINT       NOT NULL DEFAULT 0 COMMENT 'thời gian chạy tệp',
  PRIMARY KEY (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Lịch sử migration — do selliotech-api/cmd/migrate ghi, đừng sửa tay'`)
	if err != nil {
		return fmt.Errorf("không tạo được bảng %s: %w", TenBang, err)
	}

	// van_tay_sql thêm vào sau, database dựng trước đó chỉ có 4 cột và
	// CREATE TABLE IF NOT EXISTS ở trên im lặng bỏ qua. Bảng lịch sử migration
	// thì không tự migrate được bằng chính công cụ này, nên vá tay ngay đây.
	// MySQL không có ADD COLUMN IF NOT EXISTS nên phải hỏi information_schema.
	var co int
	if err := db.QueryRow(`
SELECT COUNT(*) FROM information_schema.columns
 WHERE table_schema = DATABASE() AND table_name = ? AND column_name = 'van_tay_sql'`,
		TenBang).Scan(&co); err != nil {
		return fmt.Errorf("không kiểm tra được cột van_tay_sql của %s: %w", TenBang, err)
	}
	if co == 0 {
		if _, err := db.Exec(`ALTER TABLE ` + TenBang + `
  ADD COLUMN van_tay_sql CHAR(64) NOT NULL DEFAULT '' COMMENT 'SHA-256 phần lệnh, đã bỏ chú thích'
  AFTER van_tay`); err != nil {
			return fmt.Errorf("không thêm được cột van_tay_sql vào %s: %w", TenBang, err)
		}
	}

	return nil
}

// LayDaChay đọc lịch sử đã ghi trong database.
func LayDaChay(db DB) ([]DaChay, error) {
	rows, err := db.Query(`SELECT version, ten, van_tay, van_tay_sql, chay_luc, mili_giay FROM ` + TenBang + ` ORDER BY version`)
	if err != nil {
		return nil, fmt.Errorf("không đọc được bảng %s: %w", TenBang, err)
	}
	defer rows.Close()

	var ds []DaChay
	for rows.Next() {
		var d DaChay
		if err := rows.Scan(&d.Version, &d.Ten, &d.VanTay, &d.VanTaySQL, &d.ChayLuc, &d.MiliGiay); err != nil {
			return nil, err
		}
		ds = append(ds, d)
	}

	return ds, rows.Err()
}

// So sánh danh sách tệp với lịch sử trong database.
func So(ds []Migration, daChay []DaChay) TrangThai {
	theoVersion := map[int]DaChay{}
	for _, d := range daChay {
		theoVersion[d.Version] = d
	}
	coTep := map[int]bool{}

	tt := TrangThai{DaChay: daChay}
	for _, m := range ds {
		coTep[m.Version] = true

		d, roi := theoVersion[m.Version]
		if !roi {
			tt.ConLai = append(tt.ConLai, m)

			continue
		}
		if d.VanTay != m.VanTay {
			tt.LechVanTay = append(tt.LechVanTay, LechVanTay{
				Version: m.Version, Ten: m.Ten,
				VanTayDB: d.VanTay, VanTayTep: m.VanTay,
				CoBangChung: d.VanTaySQL != "",
				ChiChuThich: d.VanTaySQL != "" && d.VanTaySQL == m.VanTaySQL,
			})
		}
	}

	for _, d := range daChay {
		if !coTep[d.Version] {
			tt.ThieuTep = append(tt.ThieuTep, d)
		}
	}

	return tt
}

// ErrLech báo database và thư mục migrations không khớp nhau.
var ErrLech = errors.New("lược đồ và thư mục migrations không khớp")

// Chay chạy các migration còn lại theo thứ tự, ghi lại sau mỗi tệp thành công.
//
// ghiLai được gọi trước mỗi tệp để in ra màn hình — gói này không tự in gì cả.
func Chay(db DB, conLai []Migration, batDau func(Migration), xong func(Migration, time.Duration)) error {
	for _, m := range conLai {
		if batDau != nil {
			batDau(m)
		}

		t0 := time.Now()
		if _, err := db.Exec(m.SQL); err != nil {
			return fmt.Errorf(
				"tệp %s chạy lỗi: %w\n"+
					"        MySQL không cho DDL nằm trong transaction, nên tệp này có thể đã chạy được MỘT PHẦN.\n"+
					"        Hãy mở tệp ra xem lệnh nào đã vào rồi sửa cho chạy lại được (thêm IF NOT EXISTS...), "+
					"KHÔNG đánh dấu đã chạy khi chưa xong", m.TepTin, err)
		}
		trong := time.Since(t0)

		if err := GhiDaChay(db, m, trong); err != nil {
			return err
		}
		if xong != nil {
			xong(m, trong)
		}
	}

	return nil
}

// GhiDaChay ghi một dòng vào bảng lịch sử.
func GhiDaChay(db DB, m Migration, trong time.Duration) error {
	_, err := db.Exec(
		`INSERT INTO `+TenBang+` (version, ten, van_tay, van_tay_sql, chay_luc, mili_giay) VALUES (?, ?, ?, ?, ?, ?)`,
		m.Version, m.Ten, m.VanTay, m.VanTaySQL, time.Now(), trong.Milliseconds())
	if err != nil {
		return fmt.Errorf(
			"tệp %s ĐÃ CHẠY XONG nhưng không ghi được vào bảng %s: %w\n"+
				"        Phải ghi tay dòng này, không thì lần chạy sau sẽ chạy lại tệp đó",
			m.TepTin, TenBang, err)
	}

	return nil
}

// CapNhatVanTay ghi đè vân tay đã lưu bằng vân tay của tệp hiện tại.
//
// KHÔNG chạy lại SQL, không đụng gì tới lược đồ — chỉ nói với công cụ "bản tệp
// bây giờ chính là bản đã chạy". Vì thế nó chỉ đúng khi phần lệnh không đổi;
// người gọi phải kiểm tra trước (xem LechVanTay.ChiChuThich).
func CapNhatVanTay(db DB, m Migration) error {
	ket, err := db.Exec(
		`UPDATE `+TenBang+` SET van_tay = ?, van_tay_sql = ? WHERE version = ?`,
		m.VanTay, m.VanTaySQL, m.Version)
	if err != nil {
		return fmt.Errorf("không cập nhật được vân tay của %s: %w", m.TepTin, err)
	}

	// Không có dòng nào khớp nghĩa là migration này chưa chạy — gọi nhầm chỗ,
	// và im lặng thì người dùng tưởng đã sửa xong.
	n, err := ket.RowsAffected()
	if err == nil && n == 0 {
		return fmt.Errorf("không có dòng nào cho version %d trong %s", m.Version, TenBang)
	}

	return nil
}

// TenTepMoi dựng tên tệp cho migration kế tiếp.
func TenTepMoi(ds []Migration, ten string) (string, error) {
	ten = strings.ToLower(strings.TrimSpace(ten))
	ten = regexp.MustCompile(`[^a-z0-9]+`).ReplaceAllString(ten, "-")
	ten = strings.Trim(ten, "-")
	if ten == "" {
		return "", errors.New("cần đặt tên cho migration, ví dụ: migrate tao-moi them-cot-ma-van-don")
	}

	keTiep := 1
	if len(ds) > 0 {
		keTiep = ds[len(ds)-1].Version + 1
	}

	return fmt.Sprintf("%04d_%s.sql", keTiep, ten), nil
}
