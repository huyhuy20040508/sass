// Package apitest chạy API THẬT: cùng router, cùng middleware, cùng bộ lọc
// tenant, cùng MySQL như lúc chạy production. Chỉ khác đúng một chỗ — request đi
// vào bằng httptest thay vì qua socket.
//
// VÌ SAO PHẢI Ở TẦNG NÀY chứ không thêm test vào repository: bộ lọc tenant nằm
// dưới cùng (callback GORM) và đã có 16 bài kiểm nó. Nhưng cái người dùng thật
// làm được là SỬA ID TRÊN URL, và giữa URL với câu truy vấn còn ba tầng nữa —
// middleware rót tenant vào ctx, handler đọc tham số, service tra dữ liệu. Một
// handler quên `WithContext(c.Request.Context())` và dùng context.Background()
// là đủ để bộ lọc dưới kia không còn tác dụng, mà mọi test ở tầng repository vẫn
// xanh.
//
// Cách chạy (dùng chung database test với internal/repository):
//
//	mysql -u root -e "CREATE DATABASE IF NOT EXISTS selliotech_tenant_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
//	cd api && DB_NAME=selliotech_tenant_test go run ./cmd/migrate chay -y
//	TEST_DB_DSN="root:@tcp(127.0.0.1:3306)/selliotech_tenant_test?parseTime=true" go test ./internal/apitest/
//
// Không đặt TEST_DB_DSN thì cả gói tự bỏ qua.
//
// Nhóm bài kiểm CỤM BÁN HÀNG CHO KHÁCH cần thêm database control plane (sổ tên
// miền). Tên của nó suy ra từ tên database trên: <tên>_nen_tang.
//
//	cd api && PLATFORM_DB_NAME=selliotech_tenant_test_nen_tang go run ./cmd/migrate -nen-tang chay -y
//
// Chưa dựng thì chỉ nhóm đó bỏ qua, kèm đúng câu lệnh trên trong thông báo.
package apitest

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"regexp"
	"strings"
	"testing"
	"time"

	"github.com/gin-gonic/gin"
	"gorm.io/gorm"

	"sass-api/config"
	"sass-api/internal/domain"
	"sass-api/internal/handler"
	"sass-api/internal/realtime"
	"sass-api/internal/repository"
	"sass-api/internal/router"
	"sass-api/internal/service"
	"sass-api/internal/tenant"
	"sass-api/pkg/bimat"
	"sass-api/pkg/facebook"
	"sass-api/pkg/google"
	"sass-api/pkg/jwt"
	"sass-api/pkg/mailer"
	"sass-api/pkg/minvoice"
	"sass-api/pkg/payos"
	"sass-api/pkg/sepay"
)

// Mật khẩu dùng chung cho mọi tài khoản test. Nằm trong repo là cố ý: database
// test được dựng lại bất cứ lúc nào và không chứa dữ liệu của ai.
const matKhauTest = "matkhau-thu-2026"

// dsnRe tách TEST_DB_DSN ra thành các mảnh config.DatabaseConfig cần.
//
// Phải đi vòng thế này vì repository.NewDB nhận DatabaseConfig chứ không nhận
// DSN — và PHẢI gọi nó chứ không tự mở kết nối: bộ lọc tenant (tenantScope) là
// kiểu không xuất khẩu, gói khác không gắn được. Đó cũng là điều mong muốn: kết
// nối data plane duy nhất mà code ngoài dựng được là kết nối ĐÃ CÓ bộ lọc.
var dsnRe = regexp.MustCompile(`^([^:]*):([^@]*)@tcp\(([^:)]+):([^)]+)\)/([^?]+)`)

// hauToNenTang là phần thêm vào tên database test để ra tên database CONTROL
// PLANE của bộ test.
//
// Suy ra từ TEST_DB_DSN thay vì bắt khai thêm một biến môi trường nữa: hai lược
// đồ luôn đi thành cặp, mà một biến thứ hai thì sẽ có người quên đặt và nhận về
// một bài kiểm âm thầm bỏ qua.
const hauToNenTang = "_nen_tang"

// mocCauHinh dựng cấu hình cho bản chạy test.
//
// banHang = true: bật cụm bán hàng cho khách (storefront). Khi đó cấu hình
// Platform trỏ sang database control plane của bộ test — không có nó thì không
// tên miền nào phân giải được và cả cụm đứng im.
func mocCauHinh(t *testing.T, banHang bool) *config.Config {
	t.Helper()

	dsn := os.Getenv("TEST_DB_DSN")
	if dsn == "" {
		t.Skip("bỏ qua: chưa đặt TEST_DB_DSN")
	}
	m := dsnRe.FindStringSubmatch(dsn)
	if m == nil {
		t.Fatalf("TEST_DB_DSN không đọc được (cần dạng user:pass@tcp(host:port)/db): %s", dsn)
	}

	return &config.Config{
		App: config.AppConfig{
			Name: "apitest",
			// Mã phần mềm của tiến trình test, khớp `apps.code` mà migration nạp
			// sẵn. Thiếu nó thì repo tên miền từ chối mọi host (xem
			// NewTenantDomainRepository) và cả cụm bán hàng cho khách đứng im.
			Code:           domain.AppOrder,
			Env:            "test",
			Port:           "0",
			BaseURL:        "http://localhost",
			TrustedProxies: []string{"127.0.0.1", "::1"},
			// Mặc định TẮT, đúng như production của dự án này. Nhóm bài kiểm cụm bán
			// hàng cho khách (co_lap_storefront_test.go) tự bật lên.
			EnableStorefront: banHang,
		},
		// SQLModeThem: cộng thêm mấy luật nghiêm vào phiên kết nối của bài kiểm, bất
		// kể MySQL dưới máy người chạy đang để lỏng tới đâu — nhưng KHÔNG gỡ luật
		// nào máy chủ đang có. Xem config.SQLModeKiemThu, kể cả phần giải thích vì
		// sao ONLY_FULL_GROUP_BY cố ý không nằm trong đó mà để CI gác.
		Database: config.DatabaseConfig{
			User: m[1], Password: m[2], Host: m[3], Port: m[4], Name: m[5],
			MaxOpenConns: 10, MaxIdleConns: 2,
			ConnMaxLifetime: time.Hour, ConnMaxIdleTime: time.Minute,
			SQLModeThem: config.SQLModeKiemThu,
		},
		Platform: config.DatabaseConfig{
			User: m[1], Password: m[2], Host: m[3], Port: m[4], Name: m[5] + hauToNenTang,
			MaxOpenConns: 5, MaxIdleConns: 1,
			ConnMaxLifetime: time.Hour, ConnMaxIdleTime: time.Minute,
			SQLModeThem: config.SQLModeKiemThu,
		},
		JWT: config.JWTConfig{
			Secret: "bi-mat-cua-bai-kiem-co-lap", AccessTTL: 15 * time.Minute, RefreshTTL: time.Hour,
		},
		// TẮT hạn mức: cả gói này gọi hàng trăm lượt từ CÙNG một địa chỉ 127.0.0.1,
		// bật lên thì chính bài kiểm tự khoá mình ở lượt thứ mười và mọi thứ sau đó
		// trả 429 — nhìn hệt như "đã chặn được", mà thực ra chưa kiểm gì cả.
		RateLimit: config.RateLimitConfig{Enabled: false},
	}
}

// heThong là bản API đã dựng đủ, kèm kết nối database để bài kiểm tự gieo dữ liệu.
type heThong struct {
	r  *gin.Engine
	db *gorm.DB
	// nenTang là kết nối CONTROL PLANE, chỉ có ở bản bật cụm bán hàng cho khách.
	// nil ở bản thường — dùng nó khi nil sẽ panic ngay tại chỗ gọi, đúng hơn là
	// âm thầm không gieo được tên miền nào.
	nenTang *gorm.DB
}

// napVaiTroChuan bảo đảm bảng `roles` có đủ bốn dòng của nhà máy.
//
// PHẢI làm ở đây vì bốn dòng đó KHÔNG nằm trong migration nào: `database/seed.sql`
// đã tắt hẳn, và trên máy thật chúng do `selliotech-tao-admin` nạp lúc dựng cửa
// hàng đầu tiên (xem napVaiTro trong cmd/tao-admin). Nghĩa là một database vừa
// chạy xong migration vẫn có bảng roles TRỐNG — trong khi middleware phân quyền
// và mọi lượt gieo người dùng ở đây đều tham chiếu thẳng tới id 1–4.
//
// Thiếu bước này thì bộ test chỉ xanh trên máy của người đã lỡ chạy tao-admin
// vào đúng database đó. Ai làm theo đúng ba câu lệnh ghi ở đầu tệp sẽ nhận về
// "violates foreign key constraint" mà không có gì chỉ ra nguyên nhân — và CI,
// nơi database nào cũng mới tinh, thì đỏ mọi lượt. Đây là lỗi đã xảy ra thật ở
// lượt dựng CI đầu tiên.
//
// INSERT IGNORE chứ không phải INSERT: mọi test đều gọi hàm này, và bảng roles
// dùng chung cho cả gói.
func napVaiTroChuan(t *testing.T, db *gorm.DB) {
	t.Helper()

	// Giữ khớp với vaiTroChuan trong cmd/tao-admin/main.go. Chép lại thay vì
	// dùng chung, đúng như phần nối dây ở dungHeThongVoi: bộ test phải mô tả
	// được trạng thái nó cần mà không kéo theo một gói lệnh vào đây.
	vaiTro := []struct {
		id      int
		ten     string
		hienThi string
		moTa    string
	}{
		{1, "super_admin", "Super Admin", "Toàn quyền hệ thống"},
		{2, "admin", "Quản trị viên", "Quản lý sản phẩm, đơn hàng"},
		{3, "staff", "Thu ngân", "Bán tại quầy, đơn hàng, ca làm việc"},
		{4, "customer", "Khách hàng", "Người dùng cuối"},
	}

	for _, v := range vaiTro {
		err := db.WithContext(ctxThoat()).Exec(
			"INSERT IGNORE INTO roles (id, name, display_name, description, created_at, updated_at) "+
				"VALUES (?, ?, ?, ?, NOW(3), NOW(3))",
			v.id, v.ten, v.hienThi, v.moTa,
		).Error
		if err != nil {
			t.Fatalf("không nạp được vai trò %s: %v", v.ten, err)
		}
	}
}

// dungHeThong dựng API với cụm bán hàng cho khách TẮT — đúng cấu hình production
// hôm nay của dự án.
func dungHeThong(t *testing.T) *heThong {
	t.Helper()

	return dungHeThongVoi(t, false, false)
}

// dungHeThongBanHang dựng API với cụm bán hàng cho khách BẬT, tức là có phân
// giải cửa hàng theo tên miền.
//
// Cần database CONTROL PLANE của bộ test. Chưa dựng thì bỏ qua kèm câu lệnh phải
// chạy — không làm cả gói hỏng, vì phần kiểm khu quản trị không phụ thuộc nó.
func dungHeThongBanHang(t *testing.T) *heThong {
	t.Helper()

	return dungHeThongVoi(t, true, false)
}

// dungHeThongDieuHanh dựng API có KHU ĐIỀU HÀNH NỀN TẢNG (nhóm /platform), cụm
// bán hàng cho khách vẫn TẮT như production.
//
// Cũng cần database CONTROL PLANE của bộ test — bảng giá và sổ người điều hành
// đều nằm bên đó.
func dungHeThongDieuHanh(t *testing.T) *heThong {
	t.Helper()

	return dungHeThongVoi(t, false, true)
}

// dungHeThongVoi nối dây y hệt cmd/api/main.go.
//
// Cố ý CHÉP LẠI phần nối dây thay vì tách hàm dùng chung với main: chép thì bài
// kiểm này chỉ hỏng khi chữ ký constructor đổi (trình biên dịch báo ngay), còn
// tách hàm chung thì một ngày nào đó main.go bọc thêm middleware mà bài kiểm
// không có — và bài kiểm sẽ kiểm một hệ thống không tồn tại.
func dungHeThongVoi(t *testing.T, banHang, dieuHanh bool) *heThong {
	t.Helper()
	gin.SetMode(gin.TestMode)

	cfg := mocCauHinh(t, banHang)

	// production=true chỉ để HẠ MỨC LOG của GORM xuống Warn. Bài kiểm này bắn vài
	// trăm lượt gọi, mỗi lượt vài câu truy vấn — bật log Info thì thông báo lỗi
	// thật bị chôn dưới hàng nghìn dòng SQL. Không có tác dụng nào khác lên hành vi.
	db, err := repository.NewDB(cfg.Database, true)
	if err != nil {
		t.Fatalf("không kết nối được database test: %v", err)
	}
	t.Cleanup(func() {
		if sqlDB, err := db.DB(); err == nil {
			_ = sqlDB.Close()
		}
	})

	napVaiTroChuan(t, db)

	// Control plane: chỉ mở khi cần: sổ tên miền là thứ DUY NHẤT của luồng phục vụ
	// request đọc sang lược đồ đó.
	var (
		nenTang           *gorm.DB
		tenMienRepo       domain.TenantDomainRepository
		nguoiDieuHanhRepo domain.PlatformUserRepository
		planHandler       *handler.PlanHandler
		khachHangHandler  *handler.KhachHangHandler
	)
	if banHang || dieuHanh {
		nenTang, err = repository.NewPlatformDB(cfg.Platform, true)
		if err != nil {
			t.Skipf("bỏ qua: chưa dựng control plane của bộ test (%s).\n"+
				"Dựng bằng: cd api && PLATFORM_DB_NAME=%s go run ./cmd/migrate -nen-tang chay -y\nLỗi: %v",
				cfg.Platform.Name, cfg.Platform.Name, err)
		}
		t.Cleanup(func() {
			if sqlDB, err := nenTang.DB(); err == nil {
				_ = sqlDB.Close()
			}
		})
		if err := nenTang.WithContext(context.Background()).
			Model(&domain.PlatformTenant{}).Count(new(int64)).Error; err != nil {
			t.Skipf("bỏ qua: control plane của bộ test (%s) chưa có lược đồ.\n"+
				"Dựng bằng: cd api && PLATFORM_DB_NAME=%s go run ./cmd/migrate -nen-tang chay -y\nLỗi: %v",
				cfg.Platform.Name, cfg.Platform.Name, err)
		}
		// Sổ tên miền chỉ dựng cho bản BÁN HÀNG, y như main.go: nó là thứ phục vụ
		// khách vãng lai, không liên quan tới khu điều hành.
		if banHang {
			tenMienRepo = repository.NewTenantDomainRepository(nenTang, cfg.App.Code)
		}
		nguoiDieuHanhRepo = repository.NewPlatformUserRepository(nenTang)
		planHandler = handler.NewPlanHandler(service.NewPlanService(
			repository.NewPlanRepository(nenTang), repository.NewAppRepository(nenTang)))
		khachHangHandler = handler.NewKhachHangHandler(
			service.NewKhachHangService(repository.NewKhachHangRepository(nenTang)))
	}

	jwtMgr := jwt.NewManager(cfg.JWT.Secret, cfg.JWT.AccessTTL, cfg.JWT.RefreshTTL)
	mailSender := mailer.New(cfg.Mail)
	payosClient := payos.New(cfg.PayOS)
	sepayClient := sepay.New(cfg.SePay)
	fbClient := facebook.New(cfg.Facebook)
	ggClient := google.New(cfg.Google)

	userRepo := repository.NewUserRepository(db)
	tenantRepo := repository.NewTenantRepository(db)
	roleRepo := repository.NewRoleRepository(db)
	verifyRepo := repository.NewEmailVerificationRepository(db)
	categoryRepo := repository.NewCategoryRepository(db)
	productRepo := repository.NewProductRepository(db)
	orderRepo := repository.NewOrderRepository(db)
	paymentRepo := repository.NewPaymentRepository(db)
	returnRepo := repository.NewOrderReturnRepository(db)
	notifRepo := repository.NewNotificationRepository(db)
	inventoryRepo := repository.NewInventoryRepository(db)
	purchaseRepo := repository.NewPurchaseOrderRepository(db)
	receiptRepo := repository.NewGoodsReceiptRepository(db)
	pReturnRepo := repository.NewPurchaseReturnRepository(db)
	settingRepo := repository.NewSettingRepository(db)
	bannerRepo := repository.NewBannerRepository(db)
	reportRepo := repository.NewReportRepository(db)
	promotionRepo := repository.NewPromotionRepository(db)
	voucherRepo := repository.NewVoucherRepository(db)
	chiNhanhRepo := repository.NewChiNhanhRepository(db)
	caRepo := repository.NewCaLamViecRepository(db)
	contactRepo := repository.NewContactRepository(db)
	newsletterRepo := repository.NewNewsletterRepository(db)
	quyTacMaRepo := repository.NewQuyTacMaRepository(db)
	thueRepo := repository.NewThueRepository(db)
	donViTinhRepo := repository.NewDonViTinhRepository(db)
	viTriRepo := repository.NewViTriRepository(db)
	thuocTinhRepo := repository.NewThuocTinhRepository(db)

	settingSvc := service.NewSettingService(settingRepo)
	authSvc := service.NewAuthService(userRepo, nguoiDieuHanhRepo, tenantRepo, roleRepo, verifyRepo, mailSender, jwtMgr,
		cfg.JWT, cfg.Mail, true, settingSvc, fbClient, ggClient)
	categorySvc := service.NewCategoryService(categoryRepo, quyTacMaRepo)
	bannerSvc := service.NewBannerService(bannerRepo)
	// Cửa xét hạn mức hợp đồng chỉ có khi có control plane, y như main.go. Bộ
	// kiểm này gieo dữ liệu THẲNG QUA GORM chứ không qua service, nên hạn mức
	// không đứng chắn đường bài kiểm nào; nó có mặt ở đây để bản nối dây không
	// lệch với main.go — nếu lệch thì bài kiểm đang kiểm một hệ thống khác.
	var hanMucSvc service.HanMucService
	if nenTang != nil {
		hanMucSvc = service.NewHanMucService(
			repository.NewThueBaoCuaKhachRepository(nenTang),
			productRepo, userRepo, chiNhanhRepo, cfg.App.Code)
	}

	productSvc := service.NewProductService(productRepo, categoryRepo, hanMucSvc, quyTacMaRepo, viTriRepo, donViTinhRepo, thuocTinhRepo, chiNhanhRepo)
	promotionSvc := service.NewPromotionService(promotionRepo, categoryRepo)
	voucherSvc := service.NewVoucherService(voucherRepo)
	customerSvc := service.NewCustomerService(userRepo)
	userSvc := service.NewUserService(userRepo, roleRepo, hanMucSvc)
	hub := realtime.NewHub()
	notifSvc := service.NewNotificationService(notifRepo, hub)
	// Hoá đơn điện tử: hộp mã hoá rỗng và client trỏ máy chủ thật. Bộ test hiện
	// chưa chạm nhóm này; dựng sớm vì đơn hàng và thanh toán móc vào nó.
	etaxSvc := service.NewEtaxService(
		repository.NewEtaxRepository(db), chiNhanhRepo, orderRepo, bimat.New(""), minvoice.New())
	paymentSvc := service.NewPaymentService(paymentRepo, orderRepo, payosClient, cfg.PayOS, sepayClient, notifSvc, etaxSvc)
	orderSvc := service.NewOrderService(orderRepo, returnRepo, mailSender, cfg.Mail, notifSvc, settingSvc, paymentSvc, promotionSvc, voucherSvc, etaxSvc)
	returnSvc := service.NewOrderReturnService(returnRepo, notifSvc, settingSvc)
	inventorySvc := service.NewInventoryService(inventoryRepo)
	contactSvc := service.NewContactService(contactRepo, newsletterRepo)
	purchaseSvc := service.NewPurchaseOrderService(purchaseRepo)
	receiptSvc := service.NewGoodsReceiptService(receiptRepo)
	pReturnSvc := service.NewPurchaseReturnService(pReturnRepo, purchaseRepo)
	reportSvc := service.NewReportService(reportRepo)

	r := router.New(cfg, jwtMgr, tenMienRepo, nguoiDieuHanhRepo, nil, chiNhanhRepo, repository.NewQuyenRepository(db), router.Handlers{
		Health:   handler.NewHealthHandler("test"),
		Auth:     handler.NewAuthHandler(authSvc),
		Category: handler.NewCategoryHandler(categorySvc),
		Product:  handler.NewProductHandler(productSvc, promotionSvc),
		Customer: handler.NewCustomerHandler(customerSvc),
		Order:    handler.NewOrderHandler(orderSvc),
		Return:   handler.NewOrderReturnHandler(returnSvc),
		Notif:    handler.NewNotificationHandler(notifSvc, hub),
		Stock:    handler.NewInventoryHandler(inventorySvc),
		Purchase: handler.NewPurchaseOrderHandler(purchaseSvc),
		Receipt:  handler.NewGoodsReceiptHandler(receiptSvc),
		PReturn:  handler.NewPurchaseReturnHandler(pReturnSvc),
		Setting:  handler.NewSettingHandler(settingSvc),
		User:     handler.NewUserHandler(userSvc),
		ChiNhanh: handler.NewChiNhanhHandler(service.NewChiNhanhService(chiNhanhRepo, hanMucSvc, quyTacMaRepo)),
		ETax:     handler.NewEtaxHandler(etaxSvc),
		NhanSu: handler.NewNhanSuHandler(service.NewNhanSuService(
			repository.NewNhanVienRepository(db), chiNhanhRepo, userSvc,
			repository.NewQuyenRepository(db), quyTacMaRepo)),
		NhomQuyen: handler.NewNhomQuyenHandler(
			service.NewNhomQuyenService(repository.NewQuyenRepository(db), userRepo)),
		QuyTacMa: handler.NewQuyTacMaHandler(
			service.NewQuyTacMaService(quyTacMaRepo, chiNhanhRepo)),
		Thue:      handler.NewThueHandler(service.NewThueService(thueRepo)),
		DonViTinh: handler.NewDonViTinhHandler(service.NewDonViTinhService(donViTinhRepo, quyTacMaRepo)),
		ViTri:     handler.NewViTriHandler(service.NewViTriService(viTriRepo, quyTacMaRepo)),
		ThuocTinh: handler.NewThuocTinhHandler(service.NewThuocTinhService(thuocTinhRepo, quyTacMaRepo)),
		Ca:        handler.NewCaLamViecHandler(service.NewCaLamViecService(caRepo, userRepo, chiNhanhRepo)),
		Payment:   handler.NewPaymentHandler(paymentSvc),
		Banner:    handler.NewBannerHandler(bannerSvc),
		Report:    handler.NewReportHandler(reportSvc),
		Promo:     handler.NewPromotionHandler(promotionSvc),
		Voucher:   handler.NewVoucherHandler(voucherSvc),
		Contact:   handler.NewContactHandler(contactSvc),
		Plan:      planHandler,
		KhachHang: khachHangHandler,
	})

	return &heThong{r: r, db: db, nenTang: nenTang}
}

// ---------- gọi API ----------

// traLoi là một lượt gọi đã xong, giữ đủ thứ cần để báo lỗi cho người đọc.
type traLoi struct {
	ma   int
	than string
}

// hostKhongCoTrongSo là Host mặc định của mọi lượt gọi không nói rõ tên miền.
//
// Cố ý là một tên miền KHÔNG có trong sổ: khu quản trị hôm nay chạy trên một tên
// miền chưa vào sổ tên miền, nên đây mới là hình dạng thật của request. Đặt sẵn
// tên miền của một cửa hàng vào đây thì mọi bài kiểm bên trên sẽ được phân giải
// hộ và không bài nào còn kiểm được rằng token là thứ quyết định cửa hàng.
const hostKhongCoTrongSo = "khu-quan-tri.test"

func (h *heThong) goi(t *testing.T, token, method, duong string, body any) traLoi {
	t.Helper()

	return h.goiTuHost(t, hostKhongCoTrongSo, token, method, duong, body)
}

// goiTuHost gọi API như thể request đi vào từ một tên miền cụ thể.
//
// Host là thứ quyết định cửa hàng cho khách vãng lai (middleware.TenantFromHost),
// nên nó phải đặt được từ bài kiểm — không có nó thì cụm bán hàng cho khách chỉ
// kiểm được bằng token, tức là kiểm đúng cái đường đã có sẵn từ trước.
// goiVoiHeader gọi API kèm header tự khai — dùng cho những thứ trình duyệt gửi
// lên ngoài token, hôm nay là chi nhánh đang làm việc.
func (h *heThong) goiVoiHeader(
	t *testing.T, token, method, duong string, body any, headers map[string]string,
) traLoi {
	t.Helper()

	return h.goiDayDu(t, hostKhongCoTrongSo, token, method, duong, body, headers)
}

func (h *heThong) goiTuHost(t *testing.T, host, token, method, duong string, body any) traLoi {
	t.Helper()

	return h.goiDayDu(t, host, token, method, duong, body, nil)
}

func (h *heThong) goiDayDu(
	t *testing.T, host, token, method, duong string, body any, headers map[string]string,
) traLoi {
	t.Helper()

	var doc io.Reader
	if body != nil {
		raw, err := json.Marshal(body)
		if err != nil {
			t.Fatalf("không dựng được body cho %s %s: %v", method, duong, err)
		}
		doc = bytes.NewReader(raw)
	}

	req := httptest.NewRequest(method, duong, doc)
	req.Host = host
	if body != nil {
		req.Header.Set("Content-Type", "application/json")
	}
	if token != "" {
		req.Header.Set("Authorization", "Bearer "+token)
	}
	for k, v := range headers {
		req.Header.Set(k, v)
	}

	w := httptest.NewRecorder()
	h.r.ServeHTTP(w, req)

	return traLoi{ma: w.Code, than: w.Body.String()}
}

// dangNhap đi đúng đường mà Shop Admin đi: 3 ô mã cửa hàng / tên đăng nhập / mật
// khẩu. Token lấy được ở đây là token THẬT, mang tid thật — không tự ký lấy, vì
// thứ đang kiểm gồm cả việc luồng đăng nhập rót đúng cửa hàng vào token.
func (h *heThong) dangNhap(t *testing.T, maCuaHang string) string {
	t.Helper()

	return h.dangNhapVoi(t, maCuaHang, "quantri")
}

// dangNhapVoi đăng nhập bằng MỘT tài khoản cụ thể của cửa hàng.
//
// Tách ra khỏi dangNhap để bài kiểm phân quyền lấy được token của "nhanvien" —
// tài khoản vai trò staff mà gieo() dựng sẵn ở mọi cửa hàng. Phải là token đi
// qua đúng luồng đăng nhập chứ không phải token tự ký: vai trò trong claims do
// luồng đó rót vào, và đó chính là thứ chốt phân quyền đọc.
func (h *heThong) dangNhapVoi(t *testing.T, maCuaHang, tenDangNhap string) string {
	t.Helper()

	res := h.goi(t, "", http.MethodPost, "/api/v1/auth/shop-login", map[string]any{
		"shop_code": maCuaHang,
		"username":  tenDangNhap,
		"password":  matKhauTest,
	})
	if res.ma != http.StatusOK {
		t.Fatalf("đăng nhập %s vào cửa hàng %s hỏng: %d %s", tenDangNhap, maCuaHang, res.ma, res.than)
	}

	var body struct {
		Data struct {
			AccessToken string `json:"access_token"`
		} `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &body); err != nil {
		t.Fatalf("không đọc được token: %v — %s", err, res.than)
	}
	if body.Data.AccessToken == "" {
		t.Fatalf("đăng nhập trả 200 nhưng không có access_token: %s", res.than)
	}

	return body.Data.AccessToken
}

// ---------- dọn dẹp ----------

// ctxThoat là ctx dùng cho SQL dựng/dọn dữ liệu test — thứ duy nhất trong repo
// được phép đi vòng qua bộ lọc, và chỉ để xoá đúng dữ liệu do chính test tạo ra.
func ctxThoat() context.Context {
	return tenant.WithoutScope(context.Background(), "test: dựng và dọn dữ liệu của hai cửa hàng giả")
}

// xoaCuaHang xoá sạch dấu vết của các cửa hàng test.
//
// Danh sách bảng HỎI information_schema chứ không viết tay: thêm một bảng có
// tenant_id mà quên khai ở đây thì dữ liệu test đọng lại và lần chạy sau vỡ khoá
// unique — mà lỗi hiện ra sẽ nằm ở chỗ khác hẳn.
func xoaCuaHang(t *testing.T, db *gorm.DB, ids ...uint) {
	t.Helper()
	if len(ids) == 0 {
		return
	}
	ctx := ctxThoat()

	var bang []string
	err := db.WithContext(ctx).Raw(
		`SELECT table_name FROM information_schema.columns
		  WHERE table_schema = DATABASE() AND column_name = 'tenant_id'`).Scan(&bang).Error
	if err != nil {
		t.Fatalf("không liệt kê được bảng có tenant_id: %v", err)
	}

	// Tắt kiểm khoá ngoại: xoá theo đúng thứ tự phụ thuộc của hơn ba mươi bảng là
	// một danh sách nữa phải bảo trì, mà ở đây đang xoá TRỌN VẸN cả cửa hàng nên
	// không có dòng nào bị bỏ lại mồ côi.
	if err := db.WithContext(ctx).Exec("SET FOREIGN_KEY_CHECKS = 0").Error; err != nil {
		t.Fatalf("không tắt được kiểm khoá ngoại: %v", err)
	}
	defer func() { _ = db.WithContext(ctx).Exec("SET FOREIGN_KEY_CHECKS = 1").Error }()

	for _, b := range bang {
		if err := db.WithContext(ctx).Exec(
			fmt.Sprintf("DELETE FROM `%s` WHERE tenant_id IN (?)", b), ids).Error; err != nil {
			t.Fatalf("không xoá được dữ liệu test ở bảng %s: %v", b, err)
		}
	}
	if err := db.WithContext(ctx).Exec("DELETE FROM shops WHERE tenant_id IN (?)", ids).Error; err != nil {
		t.Fatalf("không xoá được chi nhánh test: %v", err)
	}
	if err := db.WithContext(ctx).Exec("DELETE FROM tenants WHERE id IN (?)", ids).Error; err != nil {
		t.Fatalf("không xoá được cửa hàng test: %v", err)
	}
}

// gieoTenMien đăng ký một tên miền cho cửa hàng, trong sổ của CONTROL PLANE.
//
// Phải ghi cả dòng `tenants` bên control plane: tenant_domains có khoá ngoại trỏ
// vào đó, và id thì KHÔNG tự tăng — nó là số chung với data plane, chép sang.
func gieoTenMien(t *testing.T, h *heThong, c *cuaHang, host string) {
	t.Helper()
	if h.nenTang == nil {
		t.Fatal("gieoTenMien cần bản dựng có control plane — dùng dungHeThongBanHang")
	}

	nen := context.Background()
	err := h.nenTang.WithContext(nen).Exec(
		`INSERT INTO tenants (id, code, name, status, created_at, updated_at)
		 VALUES (?, ?, ?, ?, NOW(3), NOW(3))
		 ON DUPLICATE KEY UPDATE status = VALUES(status), name = VALUES(name)`,
		c.id, c.ma, "Cửa hàng "+c.vet, domain.TenantActive).Error
	if err != nil {
		t.Fatalf("không ghi được cửa hàng %s vào sổ nền tảng: %v", c.ma, err)
	}

	gieoTenMienCuaApp(t, h, c, host, domain.AppOrder)
}

// gieoTenMienCuaApp đăng ký tên miền cho MỘT PHẦN MỀM cụ thể.
//
// Tách ra khỏi gieoTenMien để bài kiểm dựng được tình huống có thật từ khi một
// khách mua nhiều phần mềm: tên miền của phần mềm KHÁC cũng nằm trong cùng sổ,
// và tiến trình API này không được phục vụ nó.
func gieoTenMienCuaApp(t *testing.T, h *heThong, c *cuaHang, host, maApp string) {
	t.Helper()

	nen := context.Background()
	err := h.nenTang.WithContext(nen).Exec(
		`INSERT INTO tenant_domains (tenant_id, app_id, host, kind, is_primary, verified_at, created_at, updated_at)
		 SELECT ?, a.id, ?, 'subdomain', 0, NOW(3), NOW(3), NOW(3) FROM apps a WHERE a.code = ?
		 ON DUPLICATE KEY UPDATE tenant_id = VALUES(tenant_id), app_id = VALUES(app_id)`,
		c.id, host, maApp).Error
	if err != nil {
		t.Fatalf("không đăng ký được tên miền %s cho app %s: %v", host, maApp, err)
	}
}

// datTrangThaiNenTang đổi trạng thái cửa hàng trong sổ nền tảng — thứ mà
// middleware phân giải tên miền đọc để quyết định có mở trang bán hàng không.
func datTrangThaiNenTang(t *testing.T, h *heThong, c *cuaHang, trangThai string) {
	t.Helper()

	if err := h.nenTang.WithContext(context.Background()).
		Exec("UPDATE tenants SET status = ? WHERE id = ?", trangThai, c.id).Error; err != nil {
		t.Fatalf("không đổi được trạng thái cửa hàng %s: %v", c.ma, err)
	}
}

// xoaNenTang dọn dấu vết của các cửa hàng test trong sổ nền tảng.
func xoaNenTang(t *testing.T, nenTang *gorm.DB, ids ...uint) {
	t.Helper()
	if nenTang == nil || len(ids) == 0 {
		return
	}

	nen := context.Background()
	// tenant_domains trước, tenants sau — khoá ngoại đi theo chiều đó.
	for _, sql := range []string{
		"DELETE FROM tenant_domains WHERE tenant_id IN (?)",
		"DELETE FROM subscriptions WHERE tenant_id IN (?)",
		"DELETE FROM tenants WHERE id IN (?)",
	} {
		if err := nenTang.WithContext(nen).Exec(sql, ids).Error; err != nil {
			t.Fatalf("không dọn được sổ nền tảng (%s): %v", sql, err)
		}
	}
}

// timCuaHang tra id cửa hàng theo mã, 0 nếu chưa có.
func timCuaHang(t *testing.T, db *gorm.DB, ma string) uint {
	t.Helper()

	var id uint
	if err := db.WithContext(ctxThoat()).
		Raw("SELECT id FROM tenants WHERE code = ?", ma).Scan(&id).Error; err != nil {
		t.Fatalf("không tra được cửa hàng %s: %v", ma, err)
	}
	return id
}

// chuaDauVet cho biết thân phản hồi có mang dấu vết của một cửa hàng hay không.
func chuaDauVet(than, vet string) bool { return strings.Contains(than, vet) }
