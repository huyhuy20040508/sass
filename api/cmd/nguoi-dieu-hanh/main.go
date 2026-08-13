// nguoi-dieu-hanh — sổ NGƯỜI CỦA NỀN TẢNG: ai được vào khu điều hành.
//
// Đây là nơi DUY NHẤT tạo được tài khoản khu điều hành. Không có đường HTTP nào
// tự thêm người vào sổ này, và đó là chủ ý: lệnh phải chạy trên máy chủ, bằng
// tay người có quyền vào máy chủ.
//
//	go run ./cmd/nguoi-dieu-hanh danh-sach
//	go run ./cmd/nguoi-dieu-hanh them --email sep@congty.vn --ho-ten "Nguyễn Văn A" --vai-tro owner
//	go run ./cmd/nguoi-dieu-hanh dat-mat-khau --email sep@congty.vn
//	go run ./cmd/nguoi-dieu-hanh doi-vai-tro  --email sep@congty.vn --vai-tro support
//	go run ./cmd/nguoi-dieu-hanh khoa         --email sep@congty.vn
//	go run ./cmd/nguoi-dieu-hanh mo-khoa      --email sep@congty.vn
//	go run ./cmd/nguoi-dieu-hanh giao-app     --email sep@congty.vn --app bida
//	go run ./cmd/nguoi-dieu-hanh thu-app      --email sep@congty.vn --app bida
//
// VÌ SAO KHU ĐIỀU HÀNH CÓ SỔ RIÊNG — đọc trước khi định "cho tiện" bằng cách
// dùng lại tài khoản cửa hàng:
//
// Cách cũ là đăng nhập bằng một tài khoản `super_admin` của một cửa hàng. Vai
// trò đó là vai trò cao nhất TRONG MỘT TIỆM, mà tiệm nào cũng có một người như
// vậy — chính là chủ shop. Nghĩa là chìa vào khu điều hành nằm trong bảng tài
// khoản của khách hàng: ai đổi được mật khẩu trong một tiệm bất kỳ cũng đổi
// được chìa. Ranh giới giữa "khách hàng" và "nhà cung cấp phần mềm" đi ngang
// qua đúng chỗ không được phép đi qua. Xem migration 0007.
//
// Công cụ này CHỈ chạm control plane (.env PLATFORM_DB_*). Nó không mở kết nối
// nào tới database bán hàng, và điều đó kiểm chứng được bằng chính danh sách
// import của tệp.
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
	"text/tabwriter"

	"sass-api/config"
	"sass-api/internal/domain"
	"sass-api/pkg/hash"

	_ "github.com/go-sql-driver/mysql"
)

// Ba vai trò, khớp ENUM `platform_users.role` và hằng số trong domain.
//
// `support` chỉ ĐỌC: người trực hỗ trợ cần nhìn thấy khách đang ở gói nào để
// trả lời điện thoại, nhưng không cần đổi bảng giá của cả nền tảng giữa lúc
// đang nghe máy. Luật đó nằm ở domain.PlatformRoleGhiDuoc.
var vaiTroHopLe = map[string]string{
	domain.PlatformRoleOwner:    "chủ nền tảng — toàn quyền",
	domain.PlatformRoleOperator: "điều hành — sửa được bảng giá, gói, cửa hàng",
	domain.PlatformRoleSupport:  "hỗ trợ — CHỈ ĐỌC",
}

// matKhauToiThieu khớp ràng buộc của API cho mật khẩu người dùng.
const matKhauToiThieu = 6

// emailRe đủ chặt để bắt lỗi gõ nhầm, không cố đúng RFC.
//
// Email ở đây là TÊN ĐĂNG NHẬP (khoá duy nhất của bảng), nên gõ nhầm một chữ là
// tạo ra một tài khoản thứ hai chứ không phải sửa tài khoản cũ.
var emailRe = regexp.MustCompile(`^[^@\s]+@[^@\s]+\.[^@\s]+$`)

func main() {
	lenh := "danh-sach"
	if len(os.Args) > 1 {
		lenh = os.Args[1]
	}

	cd := flag.NewFlagSet(lenh, flag.ExitOnError)
	var (
		email   = cd.String("email", "", "email — cũng là tên đăng nhập vào khu điều hành")
		hoTen   = cd.String("ho-ten", "", "họ tên hiển thị (lệnh `them`)")
		vaiTro  = cd.String("vai-tro", domain.PlatformRoleOperator, "owner | operator | support")
		app     = cd.String("app", "", "mã phần mềm (apps.code) — lệnh `giao-app` / `thu-app`")
		matKhau = cd.String("mat-khau", "", "mật khẩu (bỏ trống thì hỏi ở bàn phím)")
	)
	if len(os.Args) > 2 {
		_ = cd.Parse(os.Args[2:])
	}

	if err := chay(lenh, *email, *hoTen, *vaiTro, *matKhau, *app); err != nil {
		fmt.Fprintln(os.Stderr, "\n  LỖI: "+err.Error())
		os.Exit(1)
	}
}

func dungCachDung() {
	fmt.Fprintln(os.Stderr, `
  Sổ người điều hành nền tảng — ai được vào khu điều hành.

    go run ./cmd/nguoi-dieu-hanh danh-sach
    go run ./cmd/nguoi-dieu-hanh them          --email <email> --ho-ten "<tên>" [--vai-tro owner|operator|support] [--mat-khau <mk>]
    go run ./cmd/nguoi-dieu-hanh dat-mat-khau  --email <email> [--mat-khau <mk>]
    go run ./cmd/nguoi-dieu-hanh doi-vai-tro   --email <email> --vai-tro <vai trò>
    go run ./cmd/nguoi-dieu-hanh khoa          --email <email>
    go run ./cmd/nguoi-dieu-hanh mo-khoa       --email <email>
    go run ./cmd/nguoi-dieu-hanh giao-app      --email <email> --app <mã phần mềm>
    go run ./cmd/nguoi-dieu-hanh thu-app       --email <email> --app <mã phần mềm>

  PHỤ TRÁCH PHẦN MỀM: owner nhìn mọi phần mềm và không cần giao. operator và
  support chỉ vào được phần mềm đã giao — chưa giao gì thì đăng nhập được
  nhưng mọi danh sách đều rỗng.

  Đây là tài khoản RIÊNG của khu điều hành, không liên quan tới tài khoản của
  cửa hàng nào. Tài khoản cửa hàng — kể cả super_admin của một tiệm — không
  vào được khu điều hành.

  Sổ trống = không ai vào được. Đó là mặc định an toàn, không phải hỏng.`)
}

func chay(lenh, email, hoTen, vaiTro, matKhau, app string) error {
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

	fmt.Printf("\n  Sổ điều hành: %s @ %s:%s\n", cfg.Platform.Name, cfg.Platform.Host, cfg.Platform.Port)

	if lenh == "danh-sach" {
		return danhSach(nenTang)
	}

	// Mọi lệnh còn lại đều thao tác trên MỘT người, xác định bằng email.
	email = strings.ToLower(strings.TrimSpace(email))
	if !emailRe.MatchString(email) {
		dungCachDung()
		return errors.New("thiếu --email, hoặc email không hợp lệ")
	}

	switch lenh {
	case "them":
		return them(nenTang, email, hoTen, vaiTro, matKhau)
	case "dat-mat-khau":
		return datMatKhau(nenTang, email, matKhau)
	case "doi-vai-tro":
		return doiVaiTro(nenTang, email, vaiTro)
	case "khoa":
		return datTrangThai(nenTang, email, "locked")
	case "mo-khoa":
		return datTrangThai(nenTang, email, "active")
	case "giao-app":
		return giaoApp(nenTang, email, app)
	case "thu-app":
		return thuApp(nenTang, email, app)
	default:
		dungCachDung()
		return fmt.Errorf("không hiểu lệnh %q", lenh)
	}
}

// kiemTraLuocDo chặn sớm khi control plane chưa chạy migration 0007 — không có
// bước này thì lỗi hiện ra là "Unknown column 'tenant_id'" ở giữa chừng.
//
// Kiểm bằng sự VẮNG MẶT của cột `tenant_id`: cột đó là cầu tạm của 0006, và
// 0007 bỏ nó đi. Còn cột nghĩa là database đang ở giữa đợt sửa, mà lúc đó tài
// khoản tạo ra ở đây chưa đăng nhập được — bản API đang chạy vẫn xác thực theo
// cách cũ.
func kiemTraLuocDo(db *sql.DB) error {
	var coCauTam int
	err := db.QueryRow(
		`SELECT COUNT(*) FROM information_schema.columns
		  WHERE table_schema = DATABASE()
		    AND table_name = 'platform_users'
		    AND column_name = 'tenant_id'`).Scan(&coCauTam)
	if err != nil {
		return fmt.Errorf("không đọc được lược đồ control plane: %w", err)
	}
	if coCauTam > 0 {
		return errors.New("control plane chưa chạy migration 0007 (bảng platform_users vẫn còn cột tenant_id) — " +
			"chạy `go run ./cmd/migrate -nen-tang chay` trước")
	}

	return nil
}

func them(nenTang *sql.DB, email, hoTen, vaiTro, matKhau string) error {
	vaiTro = strings.ToLower(strings.TrimSpace(vaiTro))
	if _, ok := vaiTroHopLe[vaiTro]; !ok {
		return fmt.Errorf("vai trò %q không hợp lệ — nhận owner | operator | support", vaiTro)
	}

	doc := bufio.NewReader(os.Stdin)
	hoTen = strings.TrimSpace(hoTen)
	if hoTen == "" {
		hoTen = hoi(doc, "Họ tên hiển thị")
	}

	// Đã có dòng thì nói rõ phải làm gì tiếp, đừng để khoá duy nhất của MySQL trả
	// về một câu lỗi không chỉ được ai đi đâu.
	var (
		vaiTroCu  string
		trangThai string
	)
	err := nenTang.QueryRow(
		`SELECT role, status FROM platform_users WHERE email = ? AND deleted_at IS NULL`, email).
		Scan(&vaiTroCu, &trangThai)
	switch {
	case err == nil:
		return fmt.Errorf(
			"email %s đã có trong sổ điều hành (vai trò %q, trạng thái %q).\n"+
				"        Đổi vai trò bằng `doi-vai-tro`, đặt lại mật khẩu bằng `dat-mat-khau`,\n"+
				"        hoặc cắt quyền bằng `khoa`.", email, vaiTroCu, trangThai)
	case !errors.Is(err, sql.ErrNoRows):
		return fmt.Errorf("không tra được sổ điều hành: %w", err)
	}

	// Mật khẩu hỏi NGAY lúc tạo: một tài khoản chưa có mật khẩu là tài khoản
	// chưa dùng được, và người tạo nó là người duy nhất biết mình vừa tạo.
	bam, err := banMatKhau(doc, matKhau, "Mật khẩu (sẽ hiện ra màn hình)")
	if err != nil {
		return err
	}

	if _, err := nenTang.Exec(
		`INSERT INTO platform_users (email, full_name, password_hash, role, status, created_at, updated_at)
		 VALUES (?, ?, ?, ?, 'active', NOW(3), NOW(3))`,
		email, hoTen, bam, vaiTro); err != nil {
		return fmt.Errorf("không ghi được vào sổ điều hành: %w", err)
	}

	fmt.Printf("\n  Đã thêm %s (%s) làm %s.\n", hoTen, email, vaiTro)
	fmt.Println("  Đăng nhập khu điều hành bằng CHÍNH email và mật khẩu vừa đặt —")
	fmt.Println("  không dùng tài khoản của cửa hàng nào nữa.")

	return danhSach(nenTang)
}

func datMatKhau(nenTang *sql.DB, email, matKhau string) error {
	bam, err := banMatKhau(bufio.NewReader(os.Stdin), matKhau, "Mật khẩu mới (sẽ hiện ra màn hình)")
	if err != nil {
		return err
	}

	kq, err := nenTang.Exec(
		`UPDATE platform_users SET password_hash = ?, updated_at = NOW(3)
		  WHERE email = ? AND deleted_at IS NULL`, bam, email)
	if err != nil {
		return fmt.Errorf("không đặt được mật khẩu: %w", err)
	}
	if so, _ := kq.RowsAffected(); so == 0 {
		return fmt.Errorf("email %s chưa có trong sổ điều hành — thêm bằng `them` trước", email)
	}

	fmt.Printf("\n  Đã đặt mật khẩu mới cho %s.\n", email)
	fmt.Println("  Phiên đang mở của người này VẪN CÒN HIỆU LỰC tới khi token hết hạn —")
	fmt.Println("  cần cắt ngay thì dùng `khoa`.")

	return nil
}

func doiVaiTro(nenTang *sql.DB, email, vaiTro string) error {
	vaiTro = strings.ToLower(strings.TrimSpace(vaiTro))
	if _, ok := vaiTroHopLe[vaiTro]; !ok {
		return fmt.Errorf("vai trò %q không hợp lệ — nhận owner | operator | support", vaiTro)
	}

	kq, err := nenTang.Exec(
		`UPDATE platform_users SET role = ?, updated_at = NOW(3)
		  WHERE email = ? AND deleted_at IS NULL`, vaiTro, email)
	if err != nil {
		return fmt.Errorf("không đổi được vai trò: %w", err)
	}
	if so, _ := kq.RowsAffected(); so == 0 {
		return fmt.Errorf("email %s chưa có trong sổ điều hành — thêm bằng `them` trước", email)
	}

	// Vai trò được API đọc lại ở TỪNG request, nên đổi ở đây là có hiệu lực ngay
	// với cả phiên đang mở. Nói ra vì người chạy lệnh thường cần biết đúng điều
	// đó khi họ đang hạ quyền của ai.
	fmt.Printf("\n  %s giờ là %s — có hiệu lực ngay với cả phiên đang mở.\n", email, vaiTro)

	return danhSach(nenTang)
}

// datTrangThai khoá / mở khoá mà KHÔNG xoá dòng.
//
// Xoá mềm dành cho người nghỉ hẳn; khoá là thứ dùng khi cần cắt ngay rồi tính
// sau — và nó để lại dấu vết rằng người này TỪNG có quyền, thứ mà một dòng bị
// xoá không nói được.
func datTrangThai(nenTang *sql.DB, email, trangThai string) error {
	kq, err := nenTang.Exec(
		`UPDATE platform_users SET status = ?, updated_at = NOW(3)
		  WHERE email = ? AND deleted_at IS NULL`, trangThai, email)
	if err != nil {
		return fmt.Errorf("không đổi được trạng thái: %w", err)
	}
	if so, _ := kq.RowsAffected(); so == 0 {
		return fmt.Errorf("email %s chưa có trong sổ điều hành", email)
	}

	if trangThai == "locked" {
		fmt.Printf("\n  Đã khoá %s — token đang cầm mất hiệu lực NGAY ở lượt gọi tiếp theo.\n", email)
	} else {
		fmt.Printf("\n  Đã mở khoá %s.\n", email)
	}

	return danhSach(nenTang)
}

func danhSach(nenTang *sql.DB) error {
	rows, err := nenTang.Query(
		`SELECT u.full_name, u.email, u.role, u.status,
		        u.password_hash IS NOT NULL,
		        IFNULL(DATE_FORMAT(u.last_login_at, '%d/%m/%Y %H:%i'), '—'),
		        IFNULL(GROUP_CONCAT(a.code ORDER BY a.code SEPARATOR ', '), '')
		   FROM platform_users u
		   LEFT JOIN platform_user_apps ua ON ua.platform_user_id = u.id
		   LEFT JOIN apps a ON a.id = ua.app_id
		  WHERE u.deleted_at IS NULL
		  GROUP BY u.id, u.full_name, u.email, u.role, u.status, u.password_hash, u.last_login_at
		  ORDER BY u.role, u.email`)
	if err != nil {
		return fmt.Errorf("không đọc được sổ điều hành: %w", err)
	}
	defer rows.Close()

	w := tabwriter.NewWriter(os.Stdout, 0, 0, 2, ' ', 0)
	fmt.Fprintln(w, "\nHỌ TÊN\tEMAIL\tVAI TRÒ\tPHỤ TRÁCH\tTRẠNG THÁI\tĐÃ ĐẶT MẬT KHẨU\tĐĂNG NHẬP CUỐI")

	var so, coMatKhau, chuaGiaoApp int
	for rows.Next() {
		var (
			hoTen, email, vaiTro, trangThai, dangNhapCuoi, dsApp string
			coMK                                                 bool
		)
		if err := rows.Scan(&hoTen, &email, &vaiTro, &trangThai, &coMK, &dangNhapCuoi, &dsApp); err != nil {
			return err
		}
		so++
		if coMK {
			coMatKhau++
		}

		// owner không có dòng giao việc nào và điều đó là ĐÚNG — in "mọi phần mềm"
		// chứ không in một ô trống, vì ô trống ở cột này có nghĩa ngược lại hẳn.
		phuTrach := dsApp
		switch {
		case vaiTro == domain.PlatformRoleOwner:
			phuTrach = "(mọi phần mềm)"
		case dsApp == "":
			phuTrach = "— CHƯA GIAO"
			chuaGiaoApp++
		}

		fmt.Fprintf(w, "%s\t%s\t%s\t%s\t%s\t%s\t%s\n",
			hoTen, email, vaiTro, phuTrach, trangThai, danhDau(coMK), dangNhapCuoi)
	}
	if err := rows.Err(); err != nil {
		return err
	}
	_ = w.Flush()

	switch {
	case so == 0:
		fmt.Println("\n  Sổ trống — chưa ai vào được khu điều hành nền tảng.")
		fmt.Println("  Thêm người đầu tiên:")
		fmt.Println(`      go run ./cmd/nguoi-dieu-hanh them --email <email> --ho-ten "<tên>" --vai-tro owner`)
	case coMatKhau == 0:
		// Cảnh báo vì đây đúng là trạng thái "cửa khoá và chìa chưa cắt": có tên
		// trong sổ nhưng không ai đăng nhập được.
		fmt.Println("\n  ! Chưa dòng nào đặt mật khẩu — hiện KHÔNG ai đăng nhập được vào khu điều hành.")
		fmt.Println("    Đặt bằng: go run ./cmd/nguoi-dieu-hanh dat-mat-khau --email <email>")
	case chuaGiaoApp > 0:
		// Cùng loại cảnh báo: người này vào được khu điều hành nhưng mọi danh sách
		// đều rỗng, và màn hình trống trông y hệt "nền tảng chưa bán gì".
		fmt.Printf("\n  ! %d người chưa được giao phần mềm nào — họ đăng nhập được nhưng không thấy gì.\n", chuaGiaoApp)
		fmt.Println("    Giao bằng: go run ./cmd/nguoi-dieu-hanh giao-app --email <email> --app <mã phần mềm>")
	}
	fmt.Println()

	return nil
}

// banMatKhau hỏi (nếu cần) rồi băm mật khẩu.
func banMatKhau(doc *bufio.Reader, matKhau, nhan string) (string, error) {
	matKhau = strings.TrimSpace(matKhau)
	if matKhau == "" {
		matKhau = hoi(doc, nhan)
	}
	if len([]rune(matKhau)) < matKhauToiThieu {
		return "", fmt.Errorf("mật khẩu phải từ %d ký tự trở lên", matKhauToiThieu)
	}

	bam, err := hash.Hash(matKhau)
	if err != nil {
		return "", fmt.Errorf("không băm được mật khẩu: %w", err)
	}

	return bam, nil
}

// hoi đọc một dòng từ bàn phím, hỏi lại nếu bỏ trống.
//
// Mật khẩu HIỆN RA màn hình — giống `cmd/tao-admin`. Che nó cần thư viện đọc
// terminal, mà lệnh này chạy trên máy chủ, thường trong một phiên chỉ có một
// người ngồi; nói thẳng trong câu hỏi vẫn hơn là im lặng để người ta tưởng đã
// được che.
func hoi(doc *bufio.Reader, nhan string) string {
	for {
		fmt.Printf("  %s: ", nhan)
		dong, err := doc.ReadString('\n')
		if err != nil && strings.TrimSpace(dong) == "" {
			fmt.Fprintln(os.Stderr, "\n  LỖI: không đọc được dữ liệu nhập")
			os.Exit(1)
		}
		if v := strings.TrimSpace(dong); v != "" {
			return v
		}
	}
}

func danhDau(v bool) string {
	if v {
		return "x"
	}

	return "-"
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

// giaoApp giao một phần mềm cho người điều hành.
//
// owner bị TỪ CHỐI thay vì ghi một dòng vô hại: họ đã nhìn mọi phần mềm theo
// định nghĩa (xem migration 0010), nên một dòng giao việc cho owner là dòng
// không ai đọc — và ngày ai đó đọc nó, họ sẽ kết luận nhầm rằng owner chỉ phụ
// trách đúng phần mềm ghi ở đó.
func giaoApp(nenTang *sql.DB, email, app string) error {
	app = strings.ToLower(strings.TrimSpace(app))
	if app == "" {
		return errors.New("thiếu --app (mã phần mềm, vd: order)")
	}

	id, vaiTro, err := traNguoi(nenTang, email)
	if err != nil {
		return err
	}
	if vaiTro == domain.PlatformRoleOwner {
		return fmt.Errorf(
			"%s là owner nên đã nhìn thấy MỌI phần mềm, không cần giao từng cái.\n"+
				"        Muốn giới hạn phạm vi thì hạ vai trò trước:\n"+
				"            go run ./cmd/nguoi-dieu-hanh doi-vai-tro --email %s --vai-tro operator",
			email, email)
	}

	kq, err := nenTang.Exec(
		`INSERT INTO platform_user_apps (platform_user_id, app_id, created_at)
		 SELECT ?, a.id, NOW(3) FROM apps a WHERE a.code = ?
		 ON DUPLICATE KEY UPDATE platform_user_apps.app_id = platform_user_apps.app_id`,
		id, app)
	if err != nil {
		return fmt.Errorf("không giao được phần mềm: %w", err)
	}
	// INSERT ... SELECT ghi 0 dòng khi không có app nào khớp, và khoá ngoại
	// không kêu vì chẳng có dòng nào được ghi. Phải tự kiểm, nếu không lệnh báo
	// thành công cho một mã phần mềm gõ sai.
	if so, _ := kq.RowsAffected(); so == 0 {
		return fmt.Errorf("danh mục nền tảng không có phần mềm %q — xem `SELECT code FROM apps`", app)
	}

	fmt.Printf("\n  Đã giao %s cho %s — có hiệu lực ngay với cả phiên đang mở.\n", app, email)

	return danhSach(nenTang)
}

// thuApp thu lại một phần mềm.
func thuApp(nenTang *sql.DB, email, app string) error {
	app = strings.ToLower(strings.TrimSpace(app))
	if app == "" {
		return errors.New("thiếu --app (mã phần mềm, vd: order)")
	}

	id, _, err := traNguoi(nenTang, email)
	if err != nil {
		return err
	}

	kq, err := nenTang.Exec(
		`DELETE ua FROM platform_user_apps ua
		   JOIN apps a ON a.id = ua.app_id
		  WHERE ua.platform_user_id = ? AND a.code = ?`, id, app)
	if err != nil {
		return fmt.Errorf("không thu được phần mềm: %w", err)
	}
	if so, _ := kq.RowsAffected(); so == 0 {
		return fmt.Errorf("%s vốn không được giao phần mềm %q", email, app)
	}

	fmt.Printf("\n  Đã thu %s khỏi %s — lượt gọi tiếp theo của họ vào phần mềm này là 403.\n", app, email)

	return danhSach(nenTang)
}

// traNguoi tra id + vai trò của một người điều hành đang hoạt động.
func traNguoi(nenTang *sql.DB, email string) (uint, string, error) {
	var (
		id     uint
		vaiTro string
	)
	err := nenTang.QueryRow(
		`SELECT id, role FROM platform_users WHERE email = ? AND deleted_at IS NULL`, email).
		Scan(&id, &vaiTro)
	switch {
	case errors.Is(err, sql.ErrNoRows):
		return 0, "", fmt.Errorf("email %s chưa có trong sổ điều hành — thêm bằng `them` trước", email)
	case err != nil:
		return 0, "", fmt.Errorf("không tra được sổ điều hành: %w", err)
	}

	return id, vaiTro, nil
}
