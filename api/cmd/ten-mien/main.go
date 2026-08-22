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
	"sass-api/internal/domain"
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
		dacCach   = cd.String("dac-cach", "", "cấp NGOÀI điều kiện gói — phải kèm lý do, lý do được ghi vào sổ")
		xemTruoc  = cd.Bool("xem-truoc", false, "tu-dong: chỉ in tên miền sẽ cấp, KHÔNG ghi gì cả")
	)
	_ = cd.Parse(os.Args[2:])

	if err := chay(lenh, *maCuaHang, *host, *chinh, *dacCach, *xemTruoc); err != nil {
		fmt.Fprintln(os.Stderr, "\n  LỖI: "+err.Error())
		os.Exit(1)
	}
}

func dungCachDung() {
	fmt.Fprintln(os.Stderr, `
  Quản lý sổ tên miền của nền tảng.

    go run ./cmd/ten-mien danh-sach
    go run ./cmd/ten-mien tu-dong --ma-cua-hang <mã> [--xem-truoc]
    go run ./cmd/ten-mien them --ma-cua-hang <mã> --host <tên miền> [--chinh]
    go run ./cmd/ten-mien them --ma-cua-hang <mã> --host <tên miền> --dac-cach "<lý do>"
    go run ./cmd/ten-mien xoa --host <tên miền>

  tu-dong sinh tên miền TỪ TÊN CỬA HÀNG, không dấu và không khoảng trắng:
  "Quốc Huy" → quochuy.selliotech.store. Nhãn trùng thì thêm hậu tố -2, -3.

  Tên miền riêng là tính năng của GÓI: chỉ cửa hàng đang có thuê bao đã trả
  tiền của một gói bật cờ own_domain (hôm nay là gói Chuỗi) mới được cấp.
  Cấp ngoài điều kiện đó thì dùng --dac-cach kèm lý do — lý do vào sổ.`)
}

func chay(lenh, maCuaHang, host string, chinh bool, dacCach string, xemTruoc bool) error {
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
		return them(banHang, nenTang, maCuaHang, host, chinh, dacCach)

	case "tu-dong":
		banHang, err := moDB(cfg.Database)
		if err != nil {
			return fmt.Errorf("database bán hàng (%s): %w", cfg.Database.Name, err)
		}
		defer banHang.Close()
		return tuDong(banHang, nenTang, maCuaHang, dacCach, xemTruoc)

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
	// KHÔNG có `plans` và `plan_features` trong danh sách, và đó là điều đáng
	// chú ý chứ không phải thiếu sót: từ 0013 lệnh này đọc điều khoản ở HỢP ĐỒNG
	// (`subscriptions.own_domain`), nên nó chạy được kể cả khi bảng giá chưa
	// dựng. Thêm hai bảng đó vào đây là dấu hiệu ai đó vừa nối lại đường đọc
	// ngược về bảng giá.
	for _, bang := range []string{"tenants", "tenant_domains", "apps", "subscriptions"} {
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
		`SELECT d.host, a.code, d.kind, d.is_primary, t.code, t.name, t.status,
		        d.verified_at IS NOT NULL, IFNULL(d.note, '')
		   FROM tenant_domains d
		   JOIN tenants t ON t.id = d.tenant_id
		   JOIN apps a    ON a.id = d.app_id
		  ORDER BY a.code, t.code, d.is_primary DESC, d.host`)
	if err != nil {
		return fmt.Errorf("không đọc được sổ tên miền: %w", err)
	}
	defer rows.Close()

	w := tabwriter.NewWriter(os.Stdout, 0, 0, 2, ' ', 0)
	// Cột PHẦN MỀM có mặt từ 0009: sổ này chứa tên miền của MỌI phần mềm, và một
	// địa chỉ chỉ phân giải được ở tiến trình API của đúng phần mềm đó.
	fmt.Fprintln(w, "\nTÊN MIỀN\tPHẦN MỀM\tLOẠI\tCHÍNH\tCỬA HÀNG\tTRẠNG THÁI\tĐÃ XÁC MINH DNS\tĐẶC CÁCH")

	var so int
	for rows.Next() {
		var (
			host, maApp, kind, code, ten, trangThai, note string
			laChinh, daXacMinh                            bool
		)
		if err := rows.Scan(&host, &maApp, &kind, &laChinh, &code, &ten, &trangThai, &daXacMinh, &note); err != nil {
			return err
		}
		so++
		fmt.Fprintf(w, "%s\t%s\t%s\t%s\t%s (%s)\t%s\t%s\t%s\n",
			host, maApp, kind, danhDau(laChinh), code, ten, trangThai, danhDau(daXacMinh), note)
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
//
// dacCach khác rỗng = cấp NGOÀI điều kiện gói, và chuỗi đó là lý do — nó được
// ghi vào cột note của dòng tên miền chứ không chỉ in ra rồi thôi.
func them(banHang, nenTang *sql.DB, maCuaHang, host string, chinh bool, dacCach string) error {
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

	// Gói của cửa hàng có kèm tên miền riêng không? Xét TRƯỚC mọi lượt ghi: các
	// bước dưới đây có chép dòng cửa hàng sang sổ nền tảng, mà từ chối sau khi đã
	// chép nghĩa là lệnh hỏng vẫn để lại dấu vết.
	if err := xetGoi(nenTang, id, maCuaHang, dacCach); err != nil {
		return err
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

	// Tên miền chính là duy nhất trong một cửa hàng CỦA MỘT PHẦN MỀM (khoá
	// uq_tenant_domains_primary từ 0009), nên phải hạ cờ của tên miền cũ TRƯỚC
	// khi dựng cờ cho tên miền mới.
	//
	// Vế `app_id` không được quên: hạ cờ trên mọi tên miền của cửa hàng là gỡ
	// luôn địa chỉ chính của phần mềm khác mà họ đang dùng, và link trong email
	// của phần mềm đó mất chỗ dựng.
	if chinh {
		if _, err := nenTang.Exec(
			`UPDATE tenant_domains SET is_primary = 0, updated_at = NOW(3)
			  WHERE tenant_id = ? AND host <> ?
			    AND app_id = (SELECT id FROM apps WHERE code = ?)`,
			id, host, appCuaLenh); err != nil {
			return fmt.Errorf("không hạ được cờ tên miền chính cũ: %w", err)
		}
	}

	// note NULL khi cấp đúng luật: cột đó dành riêng cho ngoại lệ, nên một dòng
	// có note là một dòng đáng đọc kỹ chứ không phải một dòng có ghi chú.
	var note any
	if dacCach != "" {
		note = dacCach
	}

	// app_id lấy từ danh mục theo MÃ, không gõ số: id là số tự sinh của từng
	// database. Không có dòng app nào khớp thì INSERT ... SELECT ghi 0 dòng và
	// khoá ngoại không kêu — nên phải tự kiểm, xem ngay dưới.
	kq, err := nenTang.Exec(
		`INSERT INTO tenant_domains (tenant_id, app_id, host, kind, is_primary, note, created_at, updated_at)
		 SELECT ?, a.id, ?, ?, ?, ?, NOW(3), NOW(3) FROM apps a WHERE a.code = ?
		 ON DUPLICATE KEY UPDATE is_primary = VALUES(is_primary), note = VALUES(note), updated_at = NOW(3)`,
		id, host, loaiTenMien(host), chinh, note, appCuaLenh)
	if err != nil {
		return fmt.Errorf("không ghi được tên miền: %w", err)
	}
	if so, _ := kq.RowsAffected(); so == 0 {
		return fmt.Errorf(
			"danh mục nền tảng chưa có phần mềm %q, nên chưa biết tên miền này phục vụ cái gì.\n"+
				"        Chạy `go run ./cmd/migrate -nen-tang chay` để nạp danh mục app.", appCuaLenh)
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

// tenMienGoc — tên miền mình cấp subdomain ở dưới.
//
// Hằng số chứ không phải cấu hình: đổi nó là đổi địa chỉ của mọi cửa hàng đang
// chạy, một việc phải đi kèm DNS, nginx và chứng chỉ mới — không phải thứ để
// một dòng .env gõ nhầm làm được.
const tenMienGoc = "selliotech.store"

// appCuaLenh — lệnh này cấp tên miền cho PHẦN MỀM NÀO.
//
// Từ migration 0008, một khách mua được nhiều phần mềm và mỗi cái có thuê bao
// riêng, nên câu "gói của khách này có kèm tên miền không" phải hỏi kèm tên
// phần mềm. Hôm nay chỉ có một app bán ra, và subdomain sinh ở đây trỏ vào cụm
// bán hàng của chính nó.
//
// Hằng số chứ không phải cờ dòng lệnh: thêm một cờ mà chỉ có đúng một giá trị
// hợp lệ là bắt người ta gõ thêm một thứ không có lựa chọn nào. Ngày có app thứ
// hai bán kèm tên miền thì đổi chỗ này thành cờ `--app`.
const appCuaLenh = "order"

// tuDong cấp tên miền cho cửa hàng, TỰ SINH từ tên cửa hàng.
//
// Đây là đường dành cho lúc khách mua gói Chuỗi: người vận hành không phải nghĩ
// ra địa chỉ, không phải gõ đúng chính tả tên khách, và hai người cùng làm việc
// này cho cùng một khách sẽ ra cùng một địa chỉ.
//
// Quy tắc sinh nhãn nằm ở domain.NhanTenMienTuTen — cố ý KHÔNG viết ở đây, để
// ngày có luồng đăng ký tự phục vụ thì nó gọi đúng hàm đó và ra đúng kết quả
// này. Hai chỗ tự bỏ dấu theo cách riêng là hai địa chỉ khác nhau cho một khách.
//
// Ba thứ hàm này quyết định, đều ghi lý do ngay tại chỗ: nhãn thay thế khi tên
// không rút được chữ nào, hậu tố khi nhãn đã có chủ, và có đặt làm tên miền
// chính hay không.
func tuDong(banHang, nenTang *sql.DB, maCuaHang, dacCach string, xemTruoc bool) error {
	maCuaHang = strings.ToLower(strings.TrimSpace(maCuaHang))
	if maCuaHang == "" {
		return errors.New("thiếu --ma-cua-hang")
	}

	var (
		id  int64
		ten string
		tt  string
	)
	err := banHang.QueryRow("SELECT id, name, status FROM tenants WHERE code = ?", maCuaHang).
		Scan(&id, &ten, &tt)
	if errors.Is(err, sql.ErrNoRows) {
		return fmt.Errorf("không có cửa hàng nào mang mã %q trong database bán hàng", maCuaHang)
	}
	if err != nil {
		return fmt.Errorf("không tra được cửa hàng: %w", err)
	}

	// Cửa hàng đã có địa chỉ mình cấp rồi thì DỪNG, không cấp thêm cái thứ hai.
	//
	// Chạy lại lệnh này là chuyện thường (script deploy chạy hai lượt, người vận
	// hành không nhớ đã làm chưa), và mỗi lượt lại đẻ ra một địa chỉ nữa thì cửa
	// hàng có ba tên miền mà không ai biết cái nào là cái đang dùng. Muốn thêm
	// địa chỉ thứ hai thật thì `ten-mien them --host` — chỗ đó là hành động có ý
	// thức chứ không phải tác dụng phụ của một lệnh chạy lại.
	var daCo string
	err = nenTang.QueryRow(
		"SELECT host FROM tenant_domains WHERE tenant_id = ? AND kind = 'subdomain' ORDER BY is_primary DESC, id LIMIT 1", id).
		Scan(&daCo)
	switch {
	case err == nil:
		fmt.Printf("\n  Cửa hàng %s (%s) đã có tên miền mình cấp: %s\n", ten, maCuaHang, daCo)
		fmt.Println("  Không cấp thêm. Cần địa chỉ thứ hai thì dùng `ten-mien them --host <tên miền>`.")

		return nil
	case !errors.Is(err, sql.ErrNoRows):
		return fmt.Errorf("không tra được tên miền hiện có: %w", err)
	}

	// Nhãn gốc từ TÊN cửa hàng. Tên toàn ký hiệu (hoặc rỗng) thì lùi về mã cửa
	// hàng — mã luôn có, luôn hợp lệ, và khách đã quen gõ nó ở ô 1 lúc đăng nhập.
	nhan := domain.NhanTenMienTuTen(ten)
	if nhan == "" {
		nhan = maCuaHang
		fmt.Printf("\n  · tên %q không rút được chữ nào — dùng mã cửa hàng làm nhãn\n", ten)
	}

	host, err := nhanConTrong(nenTang, nhan, id)
	if err != nil {
		return err
	}

	fmt.Printf("\n  Cửa hàng : %s (%s)\n", ten, maCuaHang)
	fmt.Printf("  Tên miền : %s\n", host)

	if xemTruoc {
		fmt.Println("\n  --xem-truoc: KHÔNG ghi gì cả. Bỏ cờ này để cấp thật.")

		return nil
	}

	// Tên miền chính chỉ đặt khi cửa hàng CHƯA có cái nào: cấp thêm một địa chỉ
	// mà lặng lẽ hạ cờ của địa chỉ khách đang in trên hoá đơn là đổi thứ họ đã
	// đưa cho người mua.
	var daCoChinh bool
	if err := nenTang.QueryRow(
		"SELECT EXISTS(SELECT 1 FROM tenant_domains WHERE tenant_id = ? AND is_primary = 1)", id).
		Scan(&daCoChinh); err != nil {
		return fmt.Errorf("không tra được tên miền chính hiện có: %w", err)
	}
	if daCoChinh {
		fmt.Println("  · cửa hàng đã có tên miền chính — cấp thêm cái này làm phụ")
	}

	return them(banHang, nenTang, maCuaHang, host, !daCoChinh, dacCach)
}

// nhanConTrong tìm tên miền chưa có chủ, bắt đầu từ nhãn gốc.
//
// Trùng thì thêm hậu tố -2, -3... chứ KHÔNG báo lỗi bắt người ta tự nghĩ tên
// khác: hai cửa hàng trùng tên là chuyện thường ("Tạp hoá Dì Tư" ở hai tỉnh), và
// người thứ hai không làm gì sai để phải bị chặn.
//
// Nhãn đã thuộc về CHÍNH cửa hàng này thì trả lại luôn — chạy lệnh hai lần
// không đẩy khách sang một địa chỉ mới.
func nhanConTrong(nenTang *sql.DB, nhanGoc string, tenantID int64) (string, error) {
	for i := 1; i <= 50; i++ {
		nhan := nhanGoc
		if i > 1 {
			nhan = fmt.Sprintf("%s-%d", nhanGoc, i)
		}

		// Nhãn của nền tảng (order, admin, api...) xử y như nhãn đã có chủ: đổi
		// sang cái khác. Khách tên "Order" không làm gì sai để bị từ chối.
		if domain.LaNhanDatTruoc(nhan) {
			continue
		}

		host := nhan + "." + tenMienGoc

		var chu int64
		err := nenTang.QueryRow("SELECT tenant_id FROM tenant_domains WHERE host = ?", host).Scan(&chu)
		switch {
		case errors.Is(err, sql.ErrNoRows):
			return host, nil
		case err != nil:
			return "", fmt.Errorf("không tra được tên miền %s: %w", host, err)
		case chu == tenantID:
			return host, nil
		}
		// Có chủ khác — thử hậu tố tiếp theo.
	}

	return "", fmt.Errorf(
		"đã thử 50 biến thể của nhãn %q mà đều có chủ — đặt tay bằng `ten-mien them --host <tên miền>`", nhanGoc)
}

// xetGoi kiểm tra cửa hàng có được cấp tên miền riêng theo gói đang mua không.
//
// TÊN MIỀN RIÊNG LÀ TÍNH NĂNG CỦA GÓI, và luật nằm trong DỮ LIỆU chứ không nằm
// trong hàm này. Ở đây chỉ đọc ra và xử.
//
// ĐỌC Ở HỢP ĐỒNG, KHÔNG ĐỌC Ở BẢNG GIÁ — `subscriptions.own_domain`, không phải
// `plan_features`. Ranh giới của cả cụm control plane:
//
//	plans + plan_features = bảng giá HIỆN HÀNH, được phép đổi
//	subscriptions         = bản ĐÃ CHỐT với khách, không đổi theo
//
// Hàm này TỪNG tra ngược về bảng giá (subscriptions → plans → plan_features), và
// đó là một lỗi thật: quyền lợi của khách đang trả tiền bị quyết bởi bảng giá
// của hôm nay. Bỏ own_domain khỏi gói Chuỗi vào tháng sau là khách Chuỗi đã ký
// mất luôn quyền được cấp tên miền — không lỗi nào nổi lên, chỉ một lệnh từ chối
// nghe rất hợp lý. Migration 0013 chép điều khoản đó vào hợp đồng.
//
// Vì vậy tệp lệnh này KHÔNG được nhắc tới `plans` hay `plan_features` nữa. Thấy
// hai cái tên đó xuất hiện trở lại ở đây nghĩa là có người vừa đọc ngược.
//
// Ba điều kiện, thiếu một là từ chối:
//
//   - có thuê bao CÒN HIỆU LỰC (chưa huỷ) trong sổ nền tảng;
//   - thuê bao đó `status = 'active'` — đã trả tiền. 'trial' không được, đó
//     chính là câu "khách dùng thử thì dùng chung shop.selliotech.store";
//     'past_due' cũng không: đang nợ thì không cấp THÊM địa chỉ mới, còn địa
//     chỉ đã cấp vẫn chạy cho tới lúc khoá hẳn cửa hàng.
//   - hợp đồng đó ghi `own_domain = 1`. Mặc định dưới database là 0, nên hợp
//     đồng không nói gì cũng là không — mặc định của một gói là không kèm tên
//     miền.
//
// dacCach khác rỗng thì bỏ qua cả ba, nhưng vẫn ĐỌC và IN ra tình trạng thật —
// người cấp đặc cách phải nhìn thấy mình đang phá luật nào.
//
// Câu truy vấn chỉ chạm ĐÚNG MỘT BẢNG: `subscriptions`. Không JOIN đi đâu cả —
// mọi thứ cần để xử đều đã nằm trong hợp đồng.
//
// XÉT THUÊ BAO CỦA APP NÀO: từ 0008 một khách mua được nhiều phần mềm, mỗi cái
// một thuê bao. Lệnh này cấp tên miền cho SELLIO ORDER (tên miền nó sinh ra là
// <nhãn>.selliotech.store, và cụm bán hàng chạy sau tên miền đó), nên nó hỏi
// đúng thuê bao của app 'order'. Ngày cấp tên miền cho phần mềm thứ hai thì
// thêm cờ `--app` và truyền xuống đây, đừng bỏ điều kiện này đi — bỏ đi là lấy
// bừa thuê bao nào MySQL trả trước, tức là gói của một phần mềm khác quyết định
// tên miền của phần mềm này.
func xetGoi(nenTang *sql.DB, tenantID int64, maCuaHang, dacCach string) error {
	var (
		goi       string
		trangThai string
		coTenMien bool
	)
	err := nenTang.QueryRow(
		`SELECT s.plan, s.status, s.own_domain
		   FROM subscriptions s
		  WHERE s.tenant_id = ? AND s.app_id = (SELECT id FROM apps WHERE code = ?)
		    AND s.status <> 'canceled'`, tenantID, appCuaLenh).
		Scan(&goi, &trangThai, &coTenMien)

	switch {
	case errors.Is(err, sql.ErrNoRows):
		if dacCach != "" {
			fmt.Printf("\n  ! ĐẶC CÁCH: cửa hàng %s chưa có thuê bao %s nào trong sổ nền tảng.\n    Lý do ghi vào sổ: %s\n",
				maCuaHang, appCuaLenh, dacCach)

			return nil
		}

		return fmt.Errorf(
			"cửa hàng %q chưa có thuê bao %s nào trong sổ nền tảng, nên chưa chứng minh được đã mua gói gì.\n"+
				"        Tên miền riêng là tính năng của gói — hôm nay là gói Chuỗi đã trả tiền.\n"+
				"        Đăng ký thuê bao cho cửa hàng này trước, hoặc cấp ngoài luật bằng:\n"+
				"            go run ./cmd/ten-mien them --ma-cua-hang %s --host <tên miền> --dac-cach \"<lý do>\"",
			maCuaHang, appCuaLenh, maCuaHang)

	case err != nil:
		return fmt.Errorf("không tra được thuê bao của cửa hàng: %w", err)
	}

	if dacCach != "" {
		fmt.Printf("\n  ! ĐẶC CÁCH: cửa hàng %s đang ở gói %q (%s), own_domain = %s.\n    Lý do ghi vào sổ: %s\n",
			maCuaHang, goi, trangThai, danhDau(coTenMien), dacCach)

		return nil
	}

	if trangThai != "active" {
		return fmt.Errorf(
			"thuê bao của cửa hàng %q đang ở trạng thái %q, chưa phải %q.\n"+
				"        Khách dùng thử và khách đang nợ dùng chung shop.selliotech.store, không cấp tên miền riêng.",
			maCuaHang, trangThai, "active")
	}
	if !coTenMien {
		return fmt.Errorf(
			"hợp đồng %q của cửa hàng %q không kèm tên miền riêng (subscriptions.own_domain = 0).\n"+
				"        Cửa hàng này dùng chung shop.selliotech.store và gõ mã cửa hàng lúc đăng nhập.\n"+
				"        SỬA BẢNG GIÁ Ở ĐÂY KHÔNG CÓ TÁC DỤNG: hợp đồng đã ký không đi theo bảng giá.\n"+
				"        Bật ô \"Tên miền riêng\" ở màn hình Tính năng gói chỉ áp cho hợp đồng ký TỪ NAY\n"+
				"        VỀ SAU. Bán thêm quyền này cho khách hiện tại là sửa CHÍNH hợp đồng của họ:\n"+
				"            UPDATE subscriptions SET own_domain = 1, updated_at = NOW(3)\n"+
				"             WHERE tenant_id = <id cửa hàng>\n"+
				"               AND app_id = (SELECT id FROM apps WHERE code = '%s')\n"+
				"               AND status <> 'canceled';",
			goi, maCuaHang, appCuaLenh)
	}

	fmt.Printf("\n  · gói %q (%s) có kèm tên miền riêng — hợp lệ\n", goi, trangThai)

	return nil
}

// loaiTenMien đoán cột `kind` theo đuôi tên miền.
//
// Chỉ là nhãn để đọc, không có tầng nào phân xử theo nó — nên đoán ở đây là vô
// hại, còn bắt người chạy khai thêm một cờ nữa thì chỉ tổ gõ nhầm.
func loaiTenMien(host string) string {
	if strings.HasSuffix(host, "."+tenMienGoc) {
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
