// thue-bao — ký, gia hạn và huỷ HỢP ĐỒNG của khách trên sổ nền tảng.
//
//	go run ./cmd/thue-bao danh-sach [--app order]
//	go run ./cmd/thue-bao ky      --ma-cua-hang quochuy --goi chuoi --gia 9990000 --chi-nhanh 5 --tai-khoan 0 --san-pham 0
//	go run ./cmd/thue-bao gia-han --ma-cua-hang quochuy --thang 12
//	go run ./cmd/thue-bao huy     --ma-cua-hang quochuy --ly-do "khách dừng dịch vụ"
//	go run ./cmd/thue-bao thu-tien --ma-cua-hang quochuy --ma-giao-dich FT2608130001
//
// VÌ SAO LỆNH NÀY TỒN TẠI — nó là NƠI DUY NHẤT thực hiện việc CHÉP:
//
//	plans + plan_features = bảng giá HIỆN HÀNH, được phép đổi
//	subscriptions         = bản ĐÃ CHỐT với khách, không đổi theo
//	Lúc ký thì CHÉP sang, sau đó KHÔNG đọc ngược nữa.
//
// Một hợp đồng phải ghi đủ SÁU thứ: giá, ba hạn mức (chi nhánh · tài khoản ·
// sản phẩm), tên miền riêng, và dòng bảng giá đã ký. Gõ SQL tay thì cả sáu phụ
// thuộc vào trí nhớ của người gõ lúc nửa đêm, mà mặc định dưới database đều
// nghiêng về phía "cho ít" — quên một cái là khách trả tiền gói Chuỗi rồi chỉ
// mở được một chi nhánh, một tài khoản, một sản phẩm.
//
// BẢNG GIÁ IM LẶNG THÌ LỆNH NÀY TỪ CHỐI, không tự điền. Gói Chuỗi không niêm
// yết số chi nhánh (landing ghi "từ hai cửa hàng trở lên"), nên ký gói đó bắt
// buộc phải khai tay từng con số. Đoán hộ một con số trong hợp đồng là việc
// không ai được phép làm, kể cả cái lệnh này.
//
// Công cụ chạm CẢ HAI database: đọc sổ cửa hàng ở data plane (.env DB_*) để
// biết mã cửa hàng là ai, rồi ghi hợp đồng ở control plane (.env PLATFORM_DB_*).
package main

import (
	"database/sql"
	"errors"
	"flag"
	"fmt"
	"os"
	"strconv"
	"strings"
	"text/tabwriter"
	"time"

	"sass-api/config"
	"sass-api/internal/domain"

	_ "github.com/go-sql-driver/mysql"
)

// theoBangGia là giá trị mặc định của các cờ hạn mức: "không khai tay, lấy
// theo bảng giá".
//
// Phải là -1 chứ không phải 0, vì 0 đã mang nghĩa KHÔNG GIỚI HẠN (xem
// migration 0012). Dùng 0 làm "chưa khai" thì không có cách nào bán một gói
// không giới hạn.
const theoBangGia = -1

func main() {
	lenh := "danh-sach"
	if len(os.Args) > 1 {
		lenh = os.Args[1]
	}

	cd := flag.NewFlagSet(lenh, flag.ExitOnError)
	var (
		maCuaHang  = cd.String("ma-cua-hang", "", "mã cửa hàng (tenants.code)")
		app        = cd.String("app", domain.AppOrder, "mã phần mềm (apps.code)")
		goi        = cd.String("goi", "", "mã gói trong bảng giá của app đó, vd: cua_hang")
		chuKy      = cd.String("chu-ky", domain.CycleThang, "thang | nam")
		gia        = cd.Float64("gia", theoBangGia, "giá đã chốt cho MỘT chu kỳ (bỏ trống = theo bảng giá)")
		chiNhanh   = cd.Int("chi-nhanh", theoBangGia, "số chi nhánh đã chốt; 0 = không giới hạn")
		taiKhoan   = cd.Int("tai-khoan", theoBangGia, "số tài khoản đã chốt; 0 = không giới hạn")
		sanPham    = cd.Int("san-pham", theoBangGia, "số sản phẩm đã chốt; 0 = không giới hạn")
		tenMien    = cd.String("ten-mien-rieng", "", "co | khong (bỏ trống = theo bảng giá)")
		dungThu    = cd.Int("dung-thu", theoBangGia, "số ngày dùng thử; 0 = ký trả tiền luôn")
		soThang    = cd.Int("thang", 0, "hợp đồng dài mấy tháng (mặc định: 1 chu kỳ)")
		ghiChu     = cd.String("ghi-chu", "", "ghi vào cột note của hợp đồng")
		lyDo       = cd.String("ly-do", "", "lý do huỷ — ghi vào sổ")
		trangThai  = cd.String("trang-thai", "", "danh-sach: lọc theo trial | active | past_due | canceled")
		soTien     = cd.Float64("so-tien", theoBangGia, "thu-tien: số tiền THẬT đã nhận (bỏ trống = giá hợp đồng)")
		kyTu       = cd.String("ky-tu", "", "thu-tien: chu kỳ được trả cho, từ ngày (2006-01-02)")
		kyDen      = cd.String("ky-den", "", "thu-tien: chu kỳ được trả cho, tới ngày (2006-01-02)")
		hinhThuc   = cd.String("hinh-thuc", "chuyen_khoan", "chuyen_khoan | tien_mat | khac")
		maGiaoDich = cd.String("ma-giao-dich", "", "mã chuyển khoản / số phiếu thu — để đối chiếu sao kê")
	)
	if len(os.Args) > 2 {
		_ = cd.Parse(os.Args[2:])
	}

	err := chay(lenh, thamSo{
		MaCuaHang: *maCuaHang, App: *app, Goi: *goi, ChuKy: *chuKy,
		Gia: *gia, ChiNhanh: *chiNhanh, TaiKhoan: *taiKhoan, SanPham: *sanPham,
		TenMien: *tenMien, DungThu: *dungThu, SoThang: *soThang,
		GhiChu: *ghiChu, LyDo: *lyDo, TrangThai: *trangThai,
		SoTien: *soTien, KyTu: *kyTu, KyDen: *kyDen,
		HinhThuc: *hinhThuc, MaGiaoDich: *maGiaoDich,
	})
	if err != nil {
		fmt.Fprintln(os.Stderr, "\n  LỖI: "+err.Error())
		os.Exit(1)
	}
}

// thamSo gom cờ dòng lệnh. Gom lại vì `ky` cần tới mười tham số, và một hàm
// mười tham số thì gọi nhầm thứ tự là chuyện sớm muộn.
type thamSo struct {
	MaCuaHang string
	App       string
	Goi       string
	ChuKy     string
	Gia       float64
	ChiNhanh  int
	TaiKhoan  int
	SanPham   int
	TenMien   string
	DungThu   int
	SoThang   int
	GhiChu    string
	LyDo      string
	TrangThai string

	// Nhóm cờ của `thu-tien`.
	SoTien     float64
	KyTu       string
	KyDen      string
	HinhThuc   string
	MaGiaoDich string
}

func dungCachDung() {
	fmt.Fprintln(os.Stderr, `
  Hợp đồng của khách trên sổ nền tảng.

    go run ./cmd/thue-bao danh-sach [--app <mã>] [--trang-thai trial|active|past_due|canceled]
    go run ./cmd/thue-bao ky      --ma-cua-hang <mã> --goi <mã gói> [--app <mã>] [--chu-ky thang|nam]
                                  [--gia <số>] [--chi-nhanh <số>] [--tai-khoan <số>] [--san-pham <số>]
                                  [--ten-mien-rieng co|khong] [--dung-thu <ngày>] [--thang <số>] [--ghi-chu "..."]
    go run ./cmd/thue-bao gia-han --ma-cua-hang <mã> [--app <mã>] --thang <số>
    go run ./cmd/thue-bao huy     --ma-cua-hang <mã> [--app <mã>] --ly-do "<lý do>"
    go run ./cmd/thue-bao thu-tien --ma-cua-hang <mã> [--app <mã>] [--so-tien <số>]
                                  [--ky-tu 2026-08-01] [--ky-den 2026-09-01]
                                  [--hinh-thuc chuyen_khoan|tien_mat|khac] [--ma-giao-dich <mã>]

  THU TIỀN là sổ riêng: doanh thu đọc ở đó, KHÔNG suy từ giá hợp đồng. Gia hạn
  và thu tiền là hai việc khác nhau — khách trả trước hay trả sau đều được, nên
  lệnh này không tự đẩy hạn, và gia-han không tự ghi tiền.

  KÝ = CHÉP bảng giá vào hợp đồng. Từ lúc đó hợp đồng sống độc lập: đổi bảng
  giá tháng sau KHÔNG đổi thứ khách đã mua.

  Bảng giá không quy định con số nào thì phải khai tay con số đó — lệnh này
  không đoán hộ. Số 0 nghĩa là KHÔNG GIỚI HẠN, và phải gõ tay đúng số 0.`)
}

func chay(lenh string, ts thamSo) error {
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

	ts.App = strings.ToLower(strings.TrimSpace(ts.App))
	ts.MaCuaHang = strings.ToLower(strings.TrimSpace(ts.MaCuaHang))

	if lenh == "danh-sach" {
		return danhSach(nenTang, ts)
	}

	if ts.MaCuaHang == "" {
		dungCachDung()
		return errors.New("thiếu --ma-cua-hang")
	}

	// Chỉ lệnh `ky` cần data plane (để biết mã cửa hàng là ai và chép dòng cửa
	// hàng sang sổ nền tảng). Gia hạn và huỷ làm việc trên hợp đồng đã có, nên
	// chúng chạy được cả khi database bán hàng đang tắt.
	switch lenh {
	case "ky":
		banHang, err := moDB(cfg.Database)
		if err != nil {
			return fmt.Errorf("database bán hàng (%s): %w", cfg.Database.Name, err)
		}
		defer banHang.Close()

		return ky(banHang, nenTang, ts)
	case "gia-han":
		return giaHan(nenTang, ts)
	case "huy":
		return huy(nenTang, ts)
	case "thu-tien":
		return thuTien(nenTang, ts)
	default:
		dungCachDung()
		return fmt.Errorf("không hiểu lệnh %q", lenh)
	}
}

// kiemTraLuocDo chặn sớm khi control plane chưa chạy đủ migration.
//
// Kiểm bằng sự CÓ MẶT của `subscriptions.own_domain` (0013) chứ không chỉ kiểm
// bảng: lệnh này ghi đúng cột đó, và một database mới chạy tới 0012 vẫn có đủ
// bảng nhưng thiếu cột — lỗi sẽ nổ ở giữa câu INSERT, sau khi đã in ra bảng đối
// chiếu và làm người chạy tưởng mọi thứ ổn.
func kiemTraLuocDo(db *sql.DB) error {
	var so int
	err := db.QueryRow(
		`SELECT COUNT(*) FROM information_schema.columns
		  WHERE table_schema = DATABASE()
		    AND table_name = 'subscriptions'
		    AND column_name = 'own_domain'`).Scan(&so)
	if err != nil {
		return fmt.Errorf("không đọc được lược đồ control plane: %w", err)
	}
	if so == 0 {
		return errors.New("control plane chưa chạy migration 0013 (subscriptions thiếu cột own_domain) — " +
			"chạy `go run ./cmd/migrate -nen-tang chay` trước")
	}

	return nil
}

// ---------------------------------------------------------------- ký hợp đồng

// dongBangGia là MỘT dòng bảng giá cùng các điều khoản của nó.
type dongBangGia struct {
	ID        int64
	AppID     int64
	AppTen    string
	Ma        string
	Ten       string
	Gia       sql.NullFloat64
	TrialNgay int
	// TinhNang là plan_features của chính dòng này: khoá → giá trị.
	TinhNang map[string]string
}

func ky(banHang, nenTang *sql.DB, ts thamSo) error {
	if strings.TrimSpace(ts.Goi) == "" {
		dungCachDung()
		return errors.New("thiếu --goi (mã gói trong bảng giá)")
	}
	if ts.ChuKy != domain.CycleThang && ts.ChuKy != domain.CycleNam {
		return fmt.Errorf("--chu-ky nhận %q hoặc %q, nhận được %q", domain.CycleThang, domain.CycleNam, ts.ChuKy)
	}

	// 1. Cửa hàng là ai — đọc ở data plane, nơi cấp mã cửa hàng.
	var (
		tenantID    int64
		tenCuaHang  string
		trangThaiCH string
	)
	err := banHang.QueryRow("SELECT id, name, status FROM tenants WHERE code = ?", ts.MaCuaHang).
		Scan(&tenantID, &tenCuaHang, &trangThaiCH)
	switch {
	case errors.Is(err, sql.ErrNoRows):
		return fmt.Errorf("không có cửa hàng nào mã %q trong database bán hàng", ts.MaCuaHang)
	case err != nil:
		return fmt.Errorf("không tra được cửa hàng: %w", err)
	}

	// 2. Dòng bảng giá đang bán.
	bg, err := traBangGia(nenTang, ts.App, ts.Goi, ts.ChuKy)
	if err != nil {
		return err
	}

	// 3. CHỐT từng điều khoản: bảng giá nói gì, người ký khai gì.
	//
	// Đây là toàn bộ phần việc của lệnh này. Mỗi dòng dưới đây trả về cả giá trị
	// lẫn NGUỒN của nó, để bảng đối chiếu in ra cuối cùng nói được "con số này
	// đến từ đâu" — thứ mà một câu INSERT gõ tay không bao giờ nói.
	giaChot, nguonGia, err := chotGia(bg, ts.Gia)
	if err != nil {
		return err
	}
	chiNhanh, nguonChiNhanh, err := chotHanMuc(bg, domain.FeatureMaxShops, "chi nhánh", "--chi-nhanh", ts.ChiNhanh)
	if err != nil {
		return err
	}
	taiKhoan, nguonTaiKhoan, err := chotHanMuc(bg, domain.FeatureMaxUsers, "tài khoản", "--tai-khoan", ts.TaiKhoan)
	if err != nil {
		return err
	}
	sanPham, nguonSanPham, err := chotHanMuc(bg, domain.FeatureMaxProducts, "sản phẩm", "--san-pham", ts.SanPham)
	if err != nil {
		return err
	}
	tenMienRieng, nguonTenMien, err := chotTenMien(bg, ts.TenMien)
	if err != nil {
		return err
	}

	// 4. Thời hạn.
	soThang := ts.SoThang
	if soThang <= 0 {
		soThang = 1
		if ts.ChuKy == domain.CycleNam {
			soThang = 12
		}
	}
	ngayDungThu := ts.DungThu
	if ngayDungThu == theoBangGia {
		ngayDungThu = bg.TrialNgay
	}

	batDau := time.Now()
	trangThai := domain.SubscriptionActive
	ketThuc := batDau.AddDate(0, soThang, 0)
	var thuDenNgay any
	if ngayDungThu > 0 {
		// Dùng thử: hạn hợp đồng CHÍNH LÀ ngày hết dùng thử. Không đẩy ends_at ra
		// xa hơn — quá ngày đó mà chưa trả tiền thì hợp đồng phải quá hạn, chứ
		// không im lặng chạy tiếp thêm một tháng.
		trangThai = domain.SubscriptionTrial
		ketThuc = batDau.AddDate(0, 0, ngayDungThu)
		thuDenNgay = ketThuc
	}

	// 5. Cửa hàng phải có mặt bên sổ nền tảng — subscriptions có khoá ngoại trỏ
	// vào `tenants` của CHÍNH lược đồ này, mà id thì không tự tăng: nó là số
	// chung với data plane.
	if _, err := nenTang.Exec(
		`INSERT INTO tenants (id, code, name, status, created_at, updated_at)
		 VALUES (?, ?, ?, ?, NOW(3), NOW(3))
		 ON DUPLICATE KEY UPDATE code = VALUES(code), name = VALUES(name)`,
		tenantID, ts.MaCuaHang, tenCuaHang, trangThaiCH); err != nil {
		return fmt.Errorf("không ghi được cửa hàng vào sổ nền tảng: %w", err)
	}

	var ghiChu any
	if strings.TrimSpace(ts.GhiChu) != "" {
		ghiChu = strings.TrimSpace(ts.GhiChu)
	}

	_, err = nenTang.Exec(
		`INSERT INTO subscriptions
		   (tenant_id, app_id, plan, plan_id, status, billing_cycle, price,
		    max_shops, max_users, max_products, own_domain,
		    started_at, ends_at, trial_ends_at, note, created_at, updated_at)
		 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(3), NOW(3))`,
		tenantID, bg.AppID, bg.Ma, bg.ID, trangThai, ts.ChuKy, giaChot,
		chiNhanh, taiKhoan, sanPham, tenMienRieng,
		batDau, ketThuc, thuDenNgay, ghiChu)
	if err != nil {
		// Khoá uq_subscriptions_current: mỗi khách mỗi phần mềm một hợp đồng còn
		// hiệu lực. Nói rõ phải làm gì tiếp thay vì ném câu lỗi của MySQL ra.
		if strings.Contains(err.Error(), "uq_subscriptions_current") {
			return fmt.Errorf(
				"cửa hàng %q đã có hợp đồng còn hiệu lực cho phần mềm %q.\n"+
					"        Gia hạn:  go run ./cmd/thue-bao gia-han --ma-cua-hang %s --app %s --thang 12\n"+
					"        Đổi gói:  huỷ hợp đồng cũ trước, rồi ký lại:\n"+
					"                  go run ./cmd/thue-bao huy --ma-cua-hang %s --app %s --ly-do \"đổi gói\"",
				ts.MaCuaHang, ts.App, ts.MaCuaHang, ts.App, ts.MaCuaHang, ts.App)
		}
		return fmt.Errorf("không ghi được hợp đồng: %w", err)
	}

	// 6. Biên bản chép: bảng giá bên trái, hợp đồng bên phải.
	fmt.Printf("\n  Đã ký hợp đồng: %s (%s) · %s · gói %s · %s\n",
		tenCuaHang, ts.MaCuaHang, bg.AppTen, bg.Ten, ts.ChuKy)

	w := tabwriter.NewWriter(os.Stdout, 0, 0, 2, ' ', 0)
	fmt.Fprintln(w, "\n  ĐIỀU KHOẢN\tBẢNG GIÁ HIỆN HÀNH\tHỢP ĐỒNG ĐÃ GHI\tNGUỒN")
	fmt.Fprintf(w, "  giá\t%s\t%s\t%s\n", tienBangGia(bg.Gia), tien(giaChot), nguonGia)
	fmt.Fprintf(w, "  chi nhánh\t%s\t%s\t%s\n", bangGiaHanMuc(bg, domain.FeatureMaxShops), soHanMuc(chiNhanh), nguonChiNhanh)
	fmt.Fprintf(w, "  tài khoản\t%s\t%s\t%s\n", bangGiaHanMuc(bg, domain.FeatureMaxUsers), soHanMuc(taiKhoan), nguonTaiKhoan)
	fmt.Fprintf(w, "  sản phẩm\t%s\t%s\t%s\n", bangGiaHanMuc(bg, domain.FeatureMaxProducts), soHanMuc(sanPham), nguonSanPham)
	fmt.Fprintf(w, "  tên miền riêng\t%s\t%s\t%s\n",
		bangGiaHanMuc(bg, domain.FeatureOwnDomain), coKhong(tenMienRieng), nguonTenMien)
	_ = w.Flush()

	fmt.Printf("\n  Trạng thái: %s · từ %s tới %s\n",
		trangThai, batDau.Format("02/01/2006"), ketThuc.Format("02/01/2006"))
	if trangThaiCH != domain.TenantActive {
		fmt.Printf("  ! cửa hàng đang ở trạng thái %q — đã ký hợp đồng nhưng họ vẫn chưa dùng được\n", trangThaiCH)
	}
	fmt.Println("\n  Từ giây phút này hợp đồng SỐNG ĐỘC LẬP với bảng giá: sửa bảng giá không đổi")
	fmt.Println("  thứ khách vừa mua. Bán thêm quyền lợi cho khách này là sửa chính hợp đồng của họ.")
	fmt.Println()

	return nil
}

// traBangGia đọc dòng bảng giá + toàn bộ tính năng của nó.
func traBangGia(nenTang *sql.DB, maApp, maGoi, chuKy string) (*dongBangGia, error) {
	bg := &dongBangGia{TinhNang: map[string]string{}}

	var trangThaiGoi, trangThaiApp string
	err := nenTang.QueryRow(
		`SELECT p.id, p.app_id, a.name, p.code, p.name, p.price, p.trial_days, p.status, a.status
		   FROM plans p JOIN apps a ON a.id = p.app_id
		  WHERE a.code = ? AND p.code = ? AND p.billing_cycle = ?`,
		maApp, maGoi, chuKy).
		Scan(&bg.ID, &bg.AppID, &bg.AppTen, &bg.Ma, &bg.Ten, &bg.Gia, &bg.TrialNgay, &trangThaiGoi, &trangThaiApp)
	switch {
	case errors.Is(err, sql.ErrNoRows):
		return nil, fmt.Errorf(
			"bảng giá của phần mềm %q không có gói %q chu kỳ %q.\n"+
				"        Xem có gì đang bán:  go run ./cmd/thue-bao danh-sach --app %s\n"+
				"        hoặc mở màn hình Các gói dịch vụ của khu điều hành.",
			maApp, maGoi, chuKy, maApp)
	case err != nil:
		return nil, fmt.Errorf("không đọc được bảng giá: %w", err)
	}

	// Gói đã ngừng bán vẫn tra ra được (dòng không bị xoá — xem 0003), nhưng
	// không được ký MỚI. Khách cũ giữ hợp đồng của họ; đó là ý nghĩa của
	// 'retired'.
	if trangThaiGoi != domain.PlanStatusActive {
		return nil, fmt.Errorf("gói %q đang ở trạng thái %q — không ký mới được, chỉ khách cũ dùng tiếp", maGoi, trangThaiGoi)
	}
	if trangThaiApp != domain.AppActive {
		return nil, fmt.Errorf("phần mềm %q đang ở trạng thái %q — chưa bán được", maApp, trangThaiApp)
	}

	rows, err := nenTang.Query(
		`SELECT feature_key, value FROM plan_features WHERE plan_id = ?`, bg.ID)
	if err != nil {
		return nil, fmt.Errorf("không đọc được tính năng gói: %w", err)
	}
	defer rows.Close()

	for rows.Next() {
		var k, v string
		if err := rows.Scan(&k, &v); err != nil {
			return nil, err
		}
		bg.TinhNang[k] = v
	}

	return bg, rows.Err()
}

// chotGia quyết giá của hợp đồng.
//
// Bảng giá ghi NULL nghĩa là "Liên hệ" (gói Chuỗi) — không có giá công khai,
// nên người ký BẮT BUỘC khai. Khai tay khi bảng giá đã có giá thì vẫn cho, đó
// là chiết khấu hay báo giá riêng, nhưng nguồn phải hiện ra trong biên bản.
func chotGia(bg *dongBangGia, khaiTay float64) (float64, string, error) {
	if khaiTay >= 0 {
		if bg.Gia.Valid && khaiTay != bg.Gia.Float64 {
			return khaiTay, "khai tay (khác bảng giá)", nil
		}
		return khaiTay, "khai tay", nil
	}
	if !bg.Gia.Valid {
		return 0, "", errors.New(
			"bảng giá ghi \"Liên hệ\" cho gói này nên không có giá để chép — khai bằng --gia <số>")
	}

	return bg.Gia.Float64, "bảng giá", nil
}

// chotHanMuc quyết một hạn mức số.
//
// BA TRẠNG THÁI của bảng giá, và cái thứ ba là lý do hàm này tồn tại:
//
//   - có số        → chép sang;
//   - 'vo_han'     → 0 (không giới hạn);
//   - KHÔNG có dòng → bảng giá không quy định, và lệnh này TỪ CHỐI. Đoán hộ
//     một con số trong hợp đồng là việc không ai được phép làm.
func chotHanMuc(bg *dongBangGia, khoa, nhan, co string, khaiTay int) (uint, string, error) {
	if khaiTay >= 0 {
		return uint(khaiTay), "khai tay", nil
	}

	v, co2 := bg.TinhNang[khoa]
	if !co2 {
		return 0, "", fmt.Errorf(
			"bảng giá không quy định số %s cho gói %q, nên không có gì để chép.\n"+
				"        Khai con số đã thoả thuận với khách:  %s <số>\n"+
				"        (%s 0 nghĩa là KHÔNG GIỚI HẠN)", nhan, bg.Ma, co, co)
	}
	if v == domain.VoHan {
		return 0, "bảng giá: không giới hạn", nil
	}

	n, err := strconv.ParseUint(v, 10, 32)
	if err != nil {
		return 0, "", fmt.Errorf(
			"bảng giá ghi %s = %q, không đọc ra số được — sửa ở màn hình Tính năng gói, hoặc khai tay %s <số>",
			nhan, v, co)
	}

	return uint(n), "bảng giá", nil
}

// chotTenMien quyết điều khoản tên miền riêng.
//
// Khác các hạn mức số: KHÔNG có dòng nghĩa là KHÔNG kèm (mặc định của một gói
// là không có tên miền riêng — xem 0004), nên ở đây không cần từ chối.
func chotTenMien(bg *dongBangGia, khaiTay string) (bool, string, error) {
	switch strings.ToLower(strings.TrimSpace(khaiTay)) {
	case "co", "có", "1", "true":
		return true, "khai tay", nil
	case "khong", "không", "0", "false":
		return false, "khai tay", nil
	case "":
		return bg.TinhNang[domain.FeatureOwnDomain] == "1", "bảng giá", nil
	default:
		return false, "", fmt.Errorf("--ten-mien-rieng nhận \"co\" hoặc \"khong\", nhận được %q", khaiTay)
	}
}

// ------------------------------------------------------- gia hạn · huỷ · xem

func giaHan(nenTang *sql.DB, ts thamSo) error {
	if ts.SoThang <= 0 {
		return errors.New("thiếu --thang (gia hạn thêm mấy tháng)")
	}

	id, cu, err := traHopDong(nenTang, ts.MaCuaHang, ts.App)
	if err != nil {
		return err
	}

	// GREATEST(ends_at, NOW()): hợp đồng đã quá hạn thì cộng từ HÔM NAY, không
	// cộng từ ngày hết hạn cũ — cộng từ ngày cũ là bán cho khách một khoảng thời
	// gian đã trôi qua.
	//
	// past_due và trial chuyển thành active: gia hạn là hành động trả tiền.
	_, err = nenTang.Exec(
		`UPDATE subscriptions
		    SET ends_at = DATE_ADD(GREATEST(ends_at, NOW(3)), INTERVAL ? MONTH),
		        status = 'active',
		        trial_ends_at = NULL,
		        updated_at = NOW(3)
		  WHERE id = ?`, ts.SoThang, id)
	if err != nil {
		return fmt.Errorf("không gia hạn được: %w", err)
	}

	_, moi, err := traHopDong(nenTang, ts.MaCuaHang, ts.App)
	if err != nil {
		return err
	}
	fmt.Printf("\n  Đã gia hạn %s · %s: %s → %s (%s → active)\n\n",
		ts.MaCuaHang, ts.App,
		cu.KetThuc.Format("02/01/2006"), moi.KetThuc.Format("02/01/2006"), cu.TrangThai)

	return nil
}

func huy(nenTang *sql.DB, ts thamSo) error {
	if strings.TrimSpace(ts.LyDo) == "" {
		return errors.New("thiếu --ly-do — huỷ hợp đồng là việc phải giải thích được sáu tháng sau")
	}

	id, hd, err := traHopDong(nenTang, ts.MaCuaHang, ts.App)
	if err != nil {
		return err
	}

	// note nối thêm chứ không ghi đè: ghi chú lúc ký cũng là thứ cần đọc lại.
	_, err = nenTang.Exec(
		`UPDATE subscriptions
		    SET status = 'canceled', canceled_at = NOW(3),
		        note = TRIM(CONCAT(IFNULL(note, ''), IF(note IS NULL OR note = '', '', ' | '), ?)),
		        updated_at = NOW(3)
		  WHERE id = ?`, "huỷ: "+strings.TrimSpace(ts.LyDo), id)
	if err != nil {
		return fmt.Errorf("không huỷ được hợp đồng: %w", err)
	}

	fmt.Printf("\n  Đã huỷ hợp đồng %s · %s (gói %s).\n", ts.MaCuaHang, ts.App, hd.Goi)
	fmt.Println("  Dòng cũ thành LỊCH SỬ, không bị xoá — và chỗ trống vừa mở ra cho phép ký hợp đồng mới.")
	fmt.Println("\n  ! Huỷ hợp đồng KHÔNG tự thu hồi tên miền riêng đã cấp, cũng không khoá cửa hàng.")
	fmt.Printf("    Gỡ tên miền:  go run ./cmd/ten-mien danh-sach   rồi   ten-mien xoa --host <tên miền>\n\n")

	return nil
}

// hopDong là bản tóm tắt một hợp đồng còn hiệu lực.
type hopDong struct {
	Goi       string
	TrangThai string
	BatDau    time.Time
	KetThuc   time.Time
	// Gia là giá ĐÃ CHỐT trong hợp đồng, không phải giá bảng giá hiện hành —
	// `thu-tien` gợi ý con số này vì khách trả theo thứ đã ký.
	Gia float64
}

// traHopDong tìm hợp đồng CÒN HIỆU LỰC của một cửa hàng cho một phần mềm.
func traHopDong(nenTang *sql.DB, maCuaHang, maApp string) (int64, hopDong, error) {
	var (
		id int64
		hd hopDong
	)
	err := nenTang.QueryRow(
		`SELECT s.id, s.plan, s.status, s.started_at, s.ends_at, s.price
		   FROM subscriptions s
		   JOIN tenants t ON t.id = s.tenant_id
		   JOIN apps a    ON a.id = s.app_id
		  WHERE t.code = ? AND a.code = ? AND s.status <> 'canceled'`,
		maCuaHang, maApp).Scan(&id, &hd.Goi, &hd.TrangThai, &hd.BatDau, &hd.KetThuc, &hd.Gia)
	switch {
	case errors.Is(err, sql.ErrNoRows):
		return 0, hd, fmt.Errorf(
			"cửa hàng %q chưa có hợp đồng còn hiệu lực cho phần mềm %q.\n"+
				"        Ký mới:  go run ./cmd/thue-bao ky --ma-cua-hang %s --app %s --goi <mã gói>",
			maCuaHang, maApp, maCuaHang, maApp)
	case err != nil:
		return 0, hd, fmt.Errorf("không tra được hợp đồng: %w", err)
	}

	return id, hd, nil
}

func danhSach(nenTang *sql.DB, ts thamSo) error {
	dieuKien := []string{"1 = 1"}
	var doi []any
	if ts.App != "" {
		dieuKien = append(dieuKien, "a.code = ?")
		doi = append(doi, ts.App)
	}
	if ts.TrangThai != "" {
		dieuKien = append(dieuKien, "s.status = ?")
		doi = append(doi, ts.TrangThai)
	}

	rows, err := nenTang.Query(
		`SELECT t.code, t.name, a.code, s.plan, s.status, s.billing_cycle, s.price,
		        s.max_shops, s.max_users, s.max_products, s.own_domain,
		        s.ends_at, DATEDIFF(s.ends_at, NOW())
		   FROM subscriptions s
		   JOIN tenants t ON t.id = s.tenant_id
		   JOIN apps a    ON a.id = s.app_id
		  WHERE `+strings.Join(dieuKien, " AND ")+`
		  ORDER BY a.code, s.status, t.code`, doi...)
	if err != nil {
		return fmt.Errorf("không đọc được sổ hợp đồng: %w", err)
	}
	defer rows.Close()

	w := tabwriter.NewWriter(os.Stdout, 0, 0, 2, ' ', 0)
	fmt.Fprintln(w, "\nCỬA HÀNG\tPHẦN MỀM\tGÓI\tTRẠNG THÁI\tCHU KỲ\tGIÁ\tCHI NHÁNH\tTÀI KHOẢN\tSẢN PHẨM\tTÊN MIỀN\tHẾT HẠN")

	var so int
	for rows.Next() {
		var (
			ma, ten, maApp, goi, trangThai, chuKy string
			gia                                   float64
			chiNhanh, taiKhoan, sanPham           uint
			tenMien                               bool
			ketThuc                               time.Time
			conLai                                int
		)
		if err := rows.Scan(&ma, &ten, &maApp, &goi, &trangThai, &chuKy, &gia,
			&chiNhanh, &taiKhoan, &sanPham, &tenMien, &ketThuc, &conLai); err != nil {
			return err
		}
		so++

		hanChot := ketThuc.Format("02/01/2006")
		switch {
		case trangThai == domain.SubscriptionCanceled:
			hanChot += " (đã huỷ)"
		case conLai < 0:
			hanChot += fmt.Sprintf(" (QUÁ HẠN %d ngày)", -conLai)
		case conLai <= 7:
			hanChot += fmt.Sprintf(" (còn %d ngày)", conLai)
		}

		fmt.Fprintf(w, "%s (%s)\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\n",
			ma, ten, maApp, goi, trangThai, chuKy, tien(gia),
			soHanMuc(chiNhanh), soHanMuc(taiKhoan), soHanMuc(sanPham), coKhong(tenMien), hanChot)
	}
	if err := rows.Err(); err != nil {
		return err
	}
	_ = w.Flush()

	if so == 0 {
		fmt.Println("\n  Chưa có hợp đồng nào — nền tảng chưa bán được gì, hoặc chưa ai ghi vào sổ.")
		fmt.Println("  Ký hợp đồng đầu tiên:")
		fmt.Println("      go run ./cmd/thue-bao ky --ma-cua-hang <mã> --goi <mã gói>")
	}
	fmt.Println()

	return nil
}

// ---------------------------------------------------------------- in ấn

// soHanMuc in một hạn mức. 0 là KHÔNG GIỚI HẠN chứ không phải "không được cái
// nào" — in ra chữ để không ai đọc nhầm con số đó.
func soHanMuc(n uint) string {
	if n == 0 {
		return "không giới hạn"
	}

	return strconv.FormatUint(uint64(n), 10)
}

// bangGiaHanMuc in điều khoản BÊN BẢNG GIÁ, gồm cả trạng thái "không quy định".
func bangGiaHanMuc(bg *dongBangGia, khoa string) string {
	v, co := bg.TinhNang[khoa]
	if !co {
		if khoa == domain.FeatureOwnDomain {
			return "không"
		}
		return "(không quy định)"
	}
	switch {
	case khoa == domain.FeatureOwnDomain:
		return coKhong(v == "1")
	case v == domain.VoHan:
		return "không giới hạn"
	default:
		return v
	}
}

func coKhong(v bool) string {
	if v {
		return "có"
	}

	return "không"
}

func tienBangGia(v sql.NullFloat64) string {
	if !v.Valid {
		return "Liên hệ"
	}

	return tien(v.Float64)
}

// tien in số tiền kiểu Việt Nam: 9990000 → 9.990.000 đ.
func tien(v float64) string {
	so := strconv.FormatInt(int64(v), 10)
	var b strings.Builder
	for i, c := range so {
		if i > 0 && (len(so)-i)%3 == 0 {
			b.WriteByte('.')
		}
		b.WriteRune(c)
	}

	return b.String() + " đ"
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

// ---------------------------------------------------------------- thu tiền

// thuTien ghi MỘT LẦN TIỀN VÀO cho hợp đồng còn hiệu lực.
//
// KHÔNG đụng tới `ends_at`: thu tiền và gia hạn là hai sự kiện khác nhau, và
// chúng lệch nhau thường xuyên — khách trả trước ba tháng, hoặc trả sau nửa
// tháng. Gộp hai việc thành một lệnh là ép mọi khách vào đúng một cách trả.
//
// Số tiền và chu kỳ đều CÓ GỢI Ý nhưng vẫn in ra nguồn, cùng lối với `ky`:
// người thu tiền phải nhìn thấy mình đang ghi con số của ai.
func thuTien(nenTang *sql.DB, ts thamSo) error {
	id, hd, err := traHopDong(nenTang, ts.MaCuaHang, ts.App)
	if err != nil {
		return err
	}

	hinhThuc := strings.ToLower(strings.TrimSpace(ts.HinhThuc))
	switch hinhThuc {
	case "chuyen_khoan", "tien_mat", "khac":
	default:
		return fmt.Errorf("--hinh-thuc nhận chuyen_khoan | tien_mat | khac, nhận được %q", ts.HinhThuc)
	}

	// Số tiền: mặc định LÀ GIÁ HỢP ĐỒNG, không phải giá bảng giá hiện hành —
	// khách trả theo thứ đã ký. Khai tay khi thu thiếu, thu gộp, hoặc có chiết
	// khấu một lần.
	soTien, nguonTien := hd.Gia, "giá hợp đồng"
	if ts.SoTien >= 0 {
		soTien, nguonTien = ts.SoTien, "khai tay"
	}
	if soTien <= 0 {
		return errors.New("số tiền phải lớn hơn 0 — khai bằng --so-tien <số>")
	}

	// Chu kỳ được trả cho: từ ĐIỂM CUỐI CỦA KỲ ĐÃ TRẢ GẦN NHẤT (hoặc ngày bắt
	// đầu hợp đồng nếu chưa trả lần nào) tới hạn hiện tại. Nhờ vậy hai lần thu
	// liên tiếp không chồng lấn nhau, và không để hở khoảng nào ở giữa.
	// CẮT VỀ NGÀY, bỏ giờ. `period_start`/`period_end` dưới database là DATE, còn
	// `started_at`/`ends_at` là DATETIME — so hai thứ đó nguyên vẹn thì một kỳ
	// "13/08 → 13/08" vẫn lọt qua mọi kiểm tra chỉ vì lệch nhau vài giờ đồng hồ,
	// và sổ thu nhận một dòng dài 0 ngày.
	kyTu, kyDen, nguonKy := ngay(hd.BatDau), ngay(hd.KetThuc), "hợp đồng"
	var kyCuoi sql.NullTime
	if err := nenTang.QueryRow(
		`SELECT MAX(period_end) FROM invoices WHERE subscription_id = ?`, id).Scan(&kyCuoi); err != nil {
		return fmt.Errorf("không đọc được sổ thu: %w", err)
	}
	if kyCuoi.Valid && ngay(kyCuoi.Time).After(kyTu) {
		kyTu, nguonKy = ngay(kyCuoi.Time), "nối tiếp kỳ đã trả"
	}
	if ts.KyTu != "" {
		if kyTu, err = docNgay(ts.KyTu, "--ky-tu"); err != nil {
			return err
		}
		nguonKy = "khai tay"
	}
	if ts.KyDen != "" {
		if kyDen, err = docNgay(ts.KyDen, "--ky-den"); err != nil {
			return err
		}
		nguonKy = "khai tay"
	}
	if !kyDen.After(kyTu) {
		// Trường hợp thường gặp nhất của nhánh này KHÔNG phải gõ nhầm ngày, mà là
		// hợp đồng đã được trả tiền tới đúng hạn hiện tại. Nói thẳng điều đó thay
		// vì ném ra một câu về "ngày cuối phải sau ngày đầu" — người thu tiền sẽ
		// đi sửa cờ ngày, và sửa xong là ghi ra một kỳ chồng lấn.
		if nguonKy == "nối tiếp kỳ đã trả" {
			return fmt.Errorf(
				"hợp đồng này đã được trả tiền tới %s, đúng bằng hạn hiện tại — không còn kỳ nào để thu.\n"+
					"        Gia hạn trước, rồi thu tiếp:\n"+
					"            go run ./cmd/thue-bao gia-han --ma-cua-hang %s --app %s --thang <số>",
				kyTu.Format("02/01/2006"), ts.MaCuaHang, ts.App)
		}

		return fmt.Errorf("chu kỳ không hợp lệ: %s → %s (ngày cuối phải sau ngày đầu)",
			kyTu.Format("02/01/2006"), kyDen.Format("02/01/2006"))
	}

	var maGiaoDich, ghiChu any
	if v := strings.TrimSpace(ts.MaGiaoDich); v != "" {
		maGiaoDich = v
	}
	if v := strings.TrimSpace(ts.GhiChu); v != "" {
		ghiChu = v
	}

	_, err = nenTang.Exec(
		`INSERT INTO invoices
		   (subscription_id, amount, period_start, period_end, paid_at, method, reference, note, created_at, updated_at)
		 VALUES (?, ?, ?, ?, NOW(3), ?, ?, ?, NOW(3), NOW(3))`,
		id, soTien, kyTu, kyDen, hinhThuc, maGiaoDich, ghiChu)
	if err != nil {
		// Khoá uq_invoices_ky: một hợp đồng, một chu kỳ, một lần thu. Ghi trùng là
		// cách dễ nhất để doanh thu tháng đó phồng gấp đôi mà không ai thấy sai.
		if strings.Contains(err.Error(), "uq_invoices_ky") {
			return fmt.Errorf(
				"đã có lần thu nào đó ghi cho kỳ bắt đầu %s của hợp đồng này.\n"+
					"        Xem lại sổ:  go run ./cmd/thue-bao danh-sach --app %s\n"+
					"        Nếu đây là lần thu của kỳ KHÁC thì khai rõ: --ky-tu <ngày> --ky-den <ngày>",
				kyTu.Format("02/01/2006"), ts.App)
		}
		return fmt.Errorf("không ghi được lần thu: %w", err)
	}

	fmt.Printf("\n  Đã ghi thu tiền: %s · %s · gói %s\n", ts.MaCuaHang, ts.App, hd.Goi)

	w := tabwriter.NewWriter(os.Stdout, 0, 0, 2, ' ', 0)
	fmt.Fprintln(w, "\n  MỤC\tGIÁ TRỊ\tNGUỒN")
	fmt.Fprintf(w, "  số tiền\t%s\t%s\n", tien(soTien), nguonTien)
	fmt.Fprintf(w, "  trả cho kỳ\t%s → %s\t%s\n",
		kyTu.Format("02/01/2006"), kyDen.Format("02/01/2006"), nguonKy)
	fmt.Fprintf(w, "  hình thức\t%s\t%s\n", hinhThuc, "khai tay")
	_ = w.Flush()

	// Nói rõ điều lệnh này KHÔNG làm — nếu không, người thu tiền tưởng hợp đồng
	// đã được đẩy hạn và khách sẽ bị tính quá hạn oan.
	fmt.Printf("\n  Hợp đồng vẫn hết hạn ngày %s — thu tiền KHÔNG tự gia hạn.\n", hd.KetThuc.Format("02/01/2006"))
	fmt.Printf("      go run ./cmd/thue-bao gia-han --ma-cua-hang %s --app %s --thang <số>\n\n",
		ts.MaCuaHang, ts.App)

	return nil
}

// ngay cắt một mốc thời gian về 00:00 cùng ngày, theo giờ máy chủ.
//
// Chu kỳ tính theo NGÀY. Giữ lại phần giờ là để dành một lỗi lệch đúng một ngày
// ở hai đầu mọi báo cáo, và một kỳ dài 0 ngày lọt qua được mọi kiểm tra.
func ngay(t time.Time) time.Time {
	return time.Date(t.Year(), t.Month(), t.Day(), 0, 0, 0, 0, time.Local)
}

// docNgay đọc một cờ ngày dạng 2006-01-02 theo giờ máy chủ.
func docNgay(raw, co string) (time.Time, error) {
	t, err := time.ParseInLocation("2006-01-02", strings.TrimSpace(raw), time.Local)
	if err != nil {
		return time.Time{}, fmt.Errorf("%s phải theo dạng 2006-01-02, nhận được %q", co, raw)
	}

	return t, nil
}
