// migrate — công cụ migration có phiên bản cho lược đồ ở ../database.
//
// Kết nối đúng database ghi trong api/.env (cục bộ, thử hay thật là do tệp .env
// quyết định — công cụ không tự đoán), rồi đối chiếu thư mục
// ../database/migrations với bảng schema_migrations trong database đó.
//
//	go run ./cmd/migrate                 # xem trạng thái (không đụng gì)
//	go run ./cmd/migrate chay            # chạy các migration còn lại
//	go run ./cmd/migrate danh-dau        # ghi "đã chạy" mà KHÔNG chạy (database cũ)
//	go run ./cmd/migrate sua-van-tay     # tệp đã chạy chỉ đổi CHÚ THÍCH: ghi lại vân tay
//	go run ./cmd/migrate tao-moi <tên>   # tạo tệp migration mới
//	go run ./cmd/migrate van-tay         # in vân tay lược đồ để so hai môi trường
//	go run ./cmd/migrate van-tay --ghi t.json     # ghi vân tay ra tệp
//	go run ./cmd/migrate so-sanh t.json           # so lược đồ hiện tại với tệp đó
//
// Thêm -y để bỏ qua câu hỏi xác nhận (dùng trong script).
package main

import (
	"bufio"
	"database/sql"
	"encoding/json"
	"errors"
	"flag"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"time"

	"sass-api/config"
	"sass-api/internal/migrate"

	_ "github.com/go-sql-driver/mysql"
)

// thuMucMacDinh — đường dẫn tương đối từ api/ sang thư mục migrations.
//
// Chạy lệnh từ thư mục khác api/ thì chỉ đường bằng cờ -thu-muc.
const thuMucMacDinh = "../database/migrations"

func main() {
	var (
		thuMuc  = flag.String("thu-muc", thuMucMacDinh, "thư mục chứa tệp .sql")
		dongY   = flag.Bool("y", false, "trả lời Có cho mọi câu hỏi xác nhận")
		chiTiet = flag.Bool("chi-tiet", false, "in đầy đủ thay vì tóm tắt")
		ghiRa   = flag.String("ghi", "", "van-tay: ghi kết quả ra tệp JSON")
	)
	flag.Parse()

	// Thư viện flag dừng ngay khi gặp chữ đầu tiên không phải cờ, nên `migrate
	// chay -y` sẽ bỏ sót -y. Lấy tên lệnh ra rồi phân tích tiếp phần sau nó.
	lenh, args := "trang-thai", flag.Args()
	if len(args) > 0 {
		lenh = args[0]
		if err := flag.CommandLine.Parse(args[1:]); err != nil {
			os.Exit(2)
		}
		args = append([]string{lenh}, flag.Args()...)
	}

	if err := chay(lenh, args, *thuMuc, *dongY, *chiTiet, *ghiRa); err != nil {
		fmt.Fprintln(os.Stderr, "\n  LỖI: "+err.Error())
		os.Exit(1)
	}
}

func chay(lenh string, args []string, thuMuc string, dongY, chiTiet bool, ghiRa string) error {
	// `tao-moi` chỉ đụng tới tệp, không cần database — làm trước để tạo được
	// migration ngay cả lúc MySQL chưa chạy.
	if lenh == "tao-moi" {
		return taoMoi(thuMuc, args)
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

	fmt.Printf("  Database: %s @ %s:%s\n\n", cfg.Database.Name, cfg.Database.Host, cfg.Database.Port)

	switch lenh {
	case "van-tay":
		return vanTay(db, cfg.Database.Name, chiTiet, ghiRa)
	case "so-sanh":
		return soSanh(db, cfg.Database.Name, args)
	}

	if err := migrate.TaoBang(db); err != nil {
		return err
	}

	tt, ds, err := trangThai(db, thuMuc)
	if err != nil {
		return err
	}

	switch lenh {
	case "trang-thai":
		return inTrangThai(tt, chiTiet)
	case "chay":
		return lenhChay(db, tt, dongY)
	case "danh-dau":
		return danhDau(db, tt, ds, dongY)
	case "sua-van-tay":
		return suaVanTay(db, tt, ds, dongY)
	default:
		return fmt.Errorf("lệnh %q không có. Các lệnh: trang-thai, chay, danh-dau, sua-van-tay, tao-moi, van-tay, so-sanh", lenh)
	}
}

// moDB mở kết nối riêng cho công cụ này.
//
// KHÔNG dùng chung repository.NewDB vì cần multiStatements=true: mỗi tệp
// migration là nhiều lệnh SQL gửi trong một lượt. Bật cờ này cho API chính thì
// mở đường cho SQL injection kiểu nối thêm lệnh, nên chỉ bật đúng ở đây.
func moDB(cfg config.DatabaseConfig) (*sql.DB, error) {
	dsn := cfg.DSN() + "&multiStatements=true"

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		return nil, fmt.Errorf("không mở được kết nối: %w", err)
	}
	if err := db.Ping(); err != nil {
		return nil, fmt.Errorf("không kết nối được MySQL (%s@%s:%s/%s): %w",
			cfg.User, cfg.Host, cfg.Port, cfg.Name, err)
	}

	return db, nil
}

func trangThai(db *sql.DB, thuMuc string) (migrate.TrangThai, []migrate.Migration, error) {
	ds, err := migrate.Doc(os.DirFS(thuMuc), ".")
	if err != nil {
		return migrate.TrangThai{}, nil, err
	}

	daChay, err := migrate.LayDaChay(db)
	if err != nil {
		return migrate.TrangThai{}, nil, err
	}

	return migrate.So(ds, daChay), ds, nil
}

// ------------------------------------------------------------------ các lệnh

func inTrangThai(tt migrate.TrangThai, chiTiet bool) error {
	fmt.Printf("  Đã chạy : %d\n", len(tt.DaChay))
	if chiTiet {
		for _, d := range tt.DaChay {
			fmt.Printf("            %04d_%-40s %s (%d ms)\n",
				d.Version, d.Ten, d.ChayLuc.Format("2006-01-02 15:04"), d.MiliGiay)
		}
	}

	fmt.Printf("  Còn lại : %d\n", len(tt.ConLai))
	for _, m := range tt.ConLai {
		fmt.Printf("            %s\n", m.TepTin)
	}

	if len(tt.LechVanTay) > 0 {
		fmt.Println("\n  NỘI DUNG TỆP ĐÃ BỊ SỬA SAU KHI CHẠY:")

		coChuThich, coKhongRo := false, false
		for _, l := range tt.LechVanTay {
			fmt.Printf("            %04d_%s\n", l.Version, l.Ten)
			fmt.Printf("              lúc chạy : %s\n", l.VanTayDB[:16])
			fmt.Printf("              tệp hiện : %s\n", l.VanTayTep[:16])

			switch {
			case l.ChiChuThich:
				fmt.Println("              phần lệnh SQL: KHÔNG đổi, chỉ chú thích")
				coChuThich = true
			case !l.CoBangChung:
				fmt.Println("              phần lệnh SQL: không rõ (dòng ghi trước khi có cột van_tay_sql)")
				coKhongRo = true
			default:
				fmt.Println("              phần lệnh SQL: ĐÃ ĐỔI")
			}
		}

		fmt.Println("  Database này đang giữ bản CŨ của tệp đó, còn máy chưa chạy sẽ nhận bản mới.")
		if coChuThich || coKhongRo {
			// Chỉ chú thích đổi thì lược đồ vẫn đúng, bắt viết migration mới là
			// vô nghĩa — chỉ cần ghi lại sổ.
			fmt.Println("  Cách chữa: `migrate sua-van-tay` nếu chỉ chú thích đổi;")
			fmt.Println("             trả tệp về nguyên trạng + viết migration MỚI nếu lệnh SQL đổi.")
		} else {
			fmt.Println("  Cách chữa: trả tệp về nguyên trạng và viết một migration MỚI cho phần muốn thêm.")
		}
	}

	if len(tt.ThieuTep) > 0 {
		fmt.Println("\n  DATABASE GHI ĐÃ CHẠY NHƯNG KHÔNG CÒN TỆP:")
		for _, d := range tt.ThieuTep {
			fmt.Printf("            %04d_%s\n", d.Version, d.Ten)
		}
		fmt.Println("  Tệp bị xoá hoặc đổi tên. Migration đã chạy ở đâu đó thì không được xoá.")
	}

	fmt.Println()
	if tt.Sach() {
		fmt.Println("  => Lược đồ khớp với thư mục migrations.")

		return nil
	}
	if len(tt.ConLai) > 0 && len(tt.LechVanTay) == 0 && len(tt.ThieuTep) == 0 {
		fmt.Println("  => Chạy `migrate chay` để cập nhật.")
	}

	// Lệch thì THOÁT VỚI MÃ LỖI, không chỉ in ra cho vui: lệnh này còn được
	// deploy.sh gọi để chặn deploy khi database chưa chạy migration mới.
	return migrate.ErrLech
}

func lenhChay(db *sql.DB, tt migrate.TrangThai, dongY bool) error {
	if len(tt.LechVanTay) > 0 || len(tt.ThieuTep) > 0 {
		_ = inTrangThai(tt, false)

		return errors.New("dừng lại: phải xử lý phần lệch ở trên trước khi chạy tiếp")
	}
	if len(tt.ConLai) == 0 {
		fmt.Println("  Không có migration nào cần chạy.")

		return nil
	}

	fmt.Printf("  Sắp chạy %d tệp:\n", len(tt.ConLai))
	for _, m := range tt.ConLai {
		fmt.Printf("    %s\n", m.TepTin)
	}
	if !dongY && !hoi("\n  Chạy các tệp trên?") {
		return errors.New("đã huỷ")
	}
	fmt.Println()

	return migrate.Chay(db, tt.ConLai,
		func(m migrate.Migration) { fmt.Printf("  → %s ... ", m.TepTin) },
		func(_ migrate.Migration, d time.Duration) { fmt.Printf("xong (%d ms)\n", d.Milliseconds()) })
}

// danhDau ghi "đã chạy" mà không thực sự chạy — dùng đúng một lần cho các
// database đã có sẵn lược đồ từ trước khi có công cụ này.
func danhDau(db *sql.DB, tt migrate.TrangThai, ds []migrate.Migration, dongY bool) error {
	if len(tt.ConLai) == 0 {
		fmt.Println("  Không có gì để đánh dấu.")

		return nil
	}

	fmt.Printf("  Sẽ ghi %d tệp là ĐÃ CHẠY mà KHÔNG chạy lệnh nào:\n", len(tt.ConLai))
	for _, m := range tt.ConLai {
		fmt.Printf("    %s\n", m.TepTin)
	}
	fmt.Println("\n  Chỉ làm việc này khi database ĐÃ có sẵn đúng lược đồ trong các tệp đó")
	fmt.Println("  (database dựng từ trước khi có công cụ migration). Đánh dấu nhầm là")
	fmt.Println("  lược đồ thiếu bảng/cột mà công cụ tưởng đã đủ.")

	if !dongY && !hoi("\n  Đánh dấu đã chạy?") {
		return errors.New("đã huỷ")
	}

	_ = ds
	for _, m := range tt.ConLai {
		if err := migrate.GhiDaChay(db, m, 0); err != nil {
			return err
		}
		fmt.Printf("  ✓ %s\n", m.TepTin)
	}

	return nil
}

// suaVanTay ghi lại vân tay cho những tệp đã chạy mà nội dung bị sửa sau đó.
//
// Có đúng MỘT trường hợp làm việc này là hợp lệ: phần lệnh SQL y nguyên, chỉ
// chú thích hoặc thụt lề đổi (đổi tên dự án, sửa lỗi chính tả trong phần giải
// thích). Lược đồ trong database vẫn đúng, chỉ có sổ ghi chép là lạc hậu — mà
// công cụ thì chặn cả `chay` lẫn deploy vì cái sổ đó.
//
// Sửa SQL thật thì vẫn phải viết migration MỚI: cập nhật vân tay không chạy
// lệnh nào cả, database sẽ thiếu đúng phần vừa thêm mà công cụ lại báo sạch.
func suaVanTay(db *sql.DB, tt migrate.TrangThai, ds []migrate.Migration, dongY bool) error {
	if len(tt.LechVanTay) == 0 {
		fmt.Println("  Không có vân tay nào lệch.")

		return nil
	}

	theoVersion := map[int]migrate.Migration{}
	for _, m := range ds {
		theoVersion[m.Version] = m
	}

	// chuThich: chứng minh được là chỉ chú thích đổi.
	// khongRo  : dòng ghi từ trước khi có cột van_tay_sql, không có gì để đối chiếu.
	var chuThich, khongRo []migrate.Migration
	for _, l := range tt.LechVanTay {
		m, co := theoVersion[l.Version]
		if !co {
			continue
		}

		switch {
		case l.ChiChuThich:
			chuThich = append(chuThich, m)
		case !l.CoBangChung:
			khongRo = append(khongRo, m)
		default:
			// Có bằng chứng, và bằng chứng nói phần LỆNH đã đổi. Từ chối cả
			// lượt: cập nhật vân tay ở đây là xoá dấu vết của một thay đổi lược
			// đồ chưa từng chạy trên database này.
			return fmt.Errorf(
				"tệp %s bị sửa cả phần LỆNH SQL, không phải chỉ chú thích — không cập nhật vân tay.\n"+
					"        Trả tệp về đúng bản đã chạy rồi viết migration mới cho phần muốn thêm:\n"+
					"            go run ./cmd/migrate tao-moi <viec-can-lam>", m.TepTin)
		}
	}

	if len(chuThich) > 0 {
		fmt.Println("  CHỈ CHÚ THÍCH ĐỔI — phần lệnh SQL y hệt lúc chạy:")
		for _, m := range chuThich {
			fmt.Printf("    %s\n", m.TepTin)
		}
	}

	if len(khongRo) > 0 {
		fmt.Println("\n  KHÔNG KIỂM CHỨNG ĐƯỢC — dòng này ghi từ trước khi công cụ lưu vân tay")
		fmt.Println("  riêng cho phần lệnh, nên không có gì để đối chiếu:")
		for _, m := range khongRo {
			fmt.Printf("    %s\n", m.TepTin)
		}
		fmt.Println("\n  Tự đối chiếu trước khi đồng ý, ví dụ:")
		fmt.Println("      git log -p -- database/migrations/<tệp>")
		fmt.Println("  Chỉ đồng ý khi thay đổi nằm hoàn toàn trong phần chú thích. Nếu có câu SQL")
		fmt.Println("  nào đổi thì database này đang THIẾU thay đổi đó, và cập nhật vân tay sẽ")
		fmt.Println("  giấu luôn việc thiếu.")
	}

	fmt.Println("\n  Việc này KHÔNG chạy lệnh SQL nào, chỉ ghi lại vân tay trong bảng lịch sử.")

	if !dongY && !hoi("\n  Cập nhật vân tay?") {
		return errors.New("đã huỷ")
	}

	for _, m := range append(chuThich, khongRo...) {
		if err := migrate.CapNhatVanTay(db, m); err != nil {
			return err
		}
		fmt.Printf("  ✓ %s\n", m.TepTin)
	}

	return nil
}

func taoMoi(thuMuc string, args []string) error {
	if len(args) < 2 {
		return errors.New("thiếu tên. Ví dụ: migrate tao-moi them-cot-ma-van-don")
	}

	ds, err := migrate.Doc(os.DirFS(thuMuc), ".")
	if err != nil {
		return err
	}

	ten, err := migrate.TenTepMoi(ds, strings.Join(args[1:], "-"))
	if err != nil {
		return err
	}

	duongDan := filepath.Join(thuMuc, ten)
	if _, err := os.Stat(duongDan); err == nil {
		return fmt.Errorf("tệp %s đã có", duongDan)
	}

	mau := fmt.Sprintf(`-- =====================================================================
--  %s
--  Ngày: %s
-- =====================================================================
--  KHÔNG viết CREATE DATABASE hay USE ở đây: công cụ đã kết nối sẵn đúng
--  database của môi trường đang chạy (cục bộ / thử / thật đều khác tên).
--
--  MySQL không cho DDL nằm trong transaction, nên tệp chạy dở là dở thật.
--  Viết sao cho chạy lại được nếu có thể: IF NOT EXISTS, IF EXISTS...
--
--  Tệp này đã chạy ở đâu đó rồi thì TUYỆT ĐỐI không sửa nội dung nữa —
--  công cụ giữ vân tay và sẽ báo lệch. Cần thêm gì thì viết tệp mới.
-- =====================================================================

`, ten, time.Now().Format("02/01/2006"))

	if err := os.WriteFile(duongDan, []byte(mau), 0o644); err != nil {
		return err
	}
	fmt.Printf("  Đã tạo %s\n", duongDan)

	return nil
}

func vanTay(db *sql.DB, tenDB string, chiTiet bool, ghiRa string) error {
	vt, err := migrate.LayVanTay(db, tenDB)
	if err != nil {
		return err
	}

	fmt.Printf("  Số bảng : %d\n", len(vt.Bang))
	fmt.Printf("  Vân tay : %s\n", vt.VanTay)
	fmt.Println("\n  So chuỗi vân tay này giữa hai môi trường: giống nhau là lược đồ y hệt.")

	if chiTiet {
		fmt.Println()
		for _, b := range vt.Bang {
			fmt.Printf("  %s (%d cột, %d khoá)\n", b.Ten, len(b.Cot), len(b.Khoa))
		}
	}

	if ghiRa != "" {
		data, err := json.MarshalIndent(vt, "", "  ")
		if err != nil {
			return err
		}
		if err := os.WriteFile(ghiRa, data, 0o644); err != nil {
			return err
		}
		fmt.Printf("\n  Đã ghi %s — chép sang máy kia rồi chạy: migrate so-sanh %s\n", ghiRa, ghiRa)
	}

	return nil
}

func soSanh(db *sql.DB, tenDB string, args []string) error {
	if len(args) < 2 {
		return errors.New("thiếu tệp để so. Ví dụ: migrate so-sanh van-tay-prod.json")
	}

	data, err := os.ReadFile(args[1])
	if err != nil {
		return fmt.Errorf("không đọc được %s: %w", args[1], err)
	}

	var kia migrate.VanTayLuocDo
	if err := json.Unmarshal(data, &kia); err != nil {
		return fmt.Errorf("tệp %s không phải kết quả của lệnh van-tay: %w", args[1], err)
	}

	nay, err := migrate.LayVanTay(db, tenDB)
	if err != nil {
		return err
	}

	if nay.VanTay == kia.VanTay {
		fmt.Println("  Hai lược đồ GIỐNG HỆT nhau.")

		return nil
	}

	khac := migrate.SoVanTay(nay, kia, "máy này", args[1])
	fmt.Printf("  Lược đồ KHÁC nhau — %d điểm:\n\n", len(khac))
	for _, k := range khac {
		fmt.Printf("    · %s\n", k)
	}

	return migrate.ErrLech
}

func hoi(cauHoi string) bool {
	fmt.Print(cauHoi + " [c/K] ")
	dong, _ := bufio.NewReader(os.Stdin).ReadString('\n')
	dong = strings.ToLower(strings.TrimSpace(dong))

	return dong == "c" || dong == "co" || dong == "có" || dong == "y" || dong == "yes"
}
