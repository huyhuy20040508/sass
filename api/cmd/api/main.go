// selliotech-api — điểm khởi động ứng dụng.
// Wiring: config -> logger -> db -> repository -> service -> handler -> router.
package main

import (
	"context"
	"errors"
	"net/http"
	"net/url"
	"os"
	"os/signal"
	"syscall"
	"time"

	"go.uber.org/zap"
	"gorm.io/gorm"

	"sass-api/config"
	"sass-api/docs"
	"sass-api/internal/domain"
	"sass-api/internal/handler"
	"sass-api/internal/realtime"
	"sass-api/internal/repository"
	"sass-api/internal/router"
	"sass-api/internal/service"
	"sass-api/pkg/facebook"
	"sass-api/pkg/google"
	"sass-api/pkg/jwt"
	"sass-api/pkg/logger"
	"sass-api/pkg/mailer"
	"sass-api/pkg/payos"
	"sass-api/pkg/sepay"
)

const version = "0.1.0"

// @title						Selliotech API
// @version					0.1.0
// @description				API của nền tảng bán hàng Selliotech — Clean Architecture (Go + Gin + GORM).
// @description				Định dạng response: { "success": bool, "message": string, "data": any, "meta": any, "errors": any }.
// @contact.name				Selliotech
// @host						localhost:8080
// @BasePath					/api/v1
// @schemes					http https
// @securityDefinitions.apikey	BearerAuth
// @in							header
// @name						Authorization
// @description				Xác thực JWT. Nhập theo định dạng: **Bearer {access_token}**
func main() {
	// 1. Cấu hình
	cfg, err := config.Load()
	if err != nil {
		panic("không nạp được cấu hình: " + err.Error())
	}

	// 2. Logger
	if err := logger.Init(cfg.App.IsProduction()); err != nil {
		panic("không khởi tạo được logger: " + err.Error())
	}
	defer logger.Sync()

	// 2b. Swagger trỏ về đúng máy chủ đang chạy.
	// Annotation @host/@schemes bị nướng cứng vào docs/ lúc `swag init`, nên khi
	// deploy lên tên miền thật trang /swagger vẫn bắn request về localhost:8080.
	// Ghi đè theo APP_BASE_URL để không phải sinh lại docs cho từng môi trường.
	applySwaggerHost(cfg.App.BaseURL)

	// 3. Database
	db, err := repository.NewDB(cfg.Database, cfg.App.IsProduction())
	if err != nil {
		panic("không kết nối được database: " + err.Error())
	}
	logger.Info("đã kết nối MySQL", zap.String("db", cfg.Database.Name))

	// 3b. Control plane — KẾT NỐI THỨ HAI, sang lược đồ selliotech_platform.
	//
	// Hai kết nối vì hai database: dữ liệu bán hàng của khách ở một bên, sổ cái
	// nền tảng (khách nào, gói nào, tên miền nào, tài khoản khu điều hành) ở bên
	// kia. Không có khoá ngoại nào bắc qua hai bên, nên cũng không có truy vấn
	// nào JOIN được hai bên — ghép dữ liệu là việc của tầng Go.
	//
	// CHƯA CÓ REQUEST NÀO PHỤ THUỘC VÀO KẾT NỐI NÀY. Vì vậy hỏng thì CẢNH BÁO
	// rồi chạy tiếp, không panic: sập nguyên API bán hàng chỉ vì cái sổ chưa
	// dựng xong là đổi một thứ chưa ai dùng lấy toàn bộ doanh thu của khách.
	// ĐỔI THÀNH panic ngay khi có đường đăng nhập / kiểm tra thuê bao đi qua đây
	// — lúc đó chạy tiếp mà thiếu sổ mới là thứ nguy hiểm.
	platformDB, err := repository.NewPlatformDB(cfg.Platform, cfg.App.IsProduction())
	if err != nil {
		logger.Warn("chưa kết nối được control plane — khu điều hành nền tảng sẽ không dùng được, phần bán hàng vẫn chạy bình thường",
			zap.String("db", cfg.Platform.Name),
			zap.String("cach_chua", "cd api && go run ./cmd/migrate -nen-tang chay"),
			zap.Error(err))
	} else {
		// Đếm thử một bảng: kết nối được KHÔNG có nghĩa là lược đồ đã dựng. Một
		// database trắng vẫn cho Ping thành công, và cái sai đó chỉ lộ ra ở lượt
		// gọi đầu tiên của khu điều hành, rất lâu sau lúc khởi động.
		var soTenant int64
		if err := platformDB.WithContext(context.Background()).
			Model(&domain.PlatformTenant{}).Count(&soTenant).Error; err != nil {
			logger.Warn("control plane kết nối được nhưng chưa có lược đồ",
				zap.String("db", cfg.Platform.Name),
				zap.String("cach_chua", "cd api && go run ./cmd/migrate -nen-tang chay"),
				zap.Error(err))
		} else {
			logger.Info("đã kết nối control plane",
				zap.String("db", cfg.Platform.Name), zap.Int64("so_tenant", soTenant))
		}

		// Đóng lúc thoát vì HIỆN CHƯA repository nào cầm kết nối này: thả trôi thì
		// pool vẫn giữ kết nối nhàn rỗi tới tận lúc process chết. Bỏ dòng này đi
		// khi repository của khu điều hành nhận platformDB.
		defer dongKetNoi(platformDB, "control plane")
	}

	// 4. JWT manager
	jwtMgr := jwt.NewManager(cfg.JWT.Secret, cfg.JWT.AccessTTL, cfg.JWT.RefreshTTL)

	// 4b. Mailer (SMTP) — mã xác thực khi đăng ký + email xác nhận đơn hàng
	mailSender := mailer.New(cfg.Mail)
	if cfg.Mail.Enabled() {
		logger.Info("đã bật gửi email", zap.String("smtp", cfg.Mail.Addr()), zap.String("from", cfg.Mail.FromAddress))
	} else {
		logger.Warn("chưa cấu hình SMTP — đăng ký sẽ báo lỗi gửi mã, đơn hàng không có email xác nhận (đặt MAIL_USERNAME/MAIL_PASSWORD trong .env)")
	}

	// 4c. Hai cổng thanh toán trực tuyến. Khách quét QR trả tiền, cổng báo về bằng
	// webhook. PayOS giữ vai trò cổng thật (cấp link, giữ giao dịch); SePay chỉ đọc
	// biến động số dư tài khoản ngân hàng của cửa hàng rồi báo có.
	payosClient := payos.New(cfg.PayOS)
	sepayClient := sepay.New(cfg.SePay)

	// 4d. Đăng nhập mạng xã hội (Facebook, Google). Chưa khai khoá thì storefront tự
	// ẩn nút đi (xem /settings/public), API vẫn chạy bình thường.
	fbClient := facebook.New(cfg.Facebook)
	if fbClient.Enabled() {
		logger.Info("đã bật đăng nhập Facebook", zap.String("app_id", cfg.Facebook.AppID))
	}
	ggClient := google.New(cfg.Google)
	if ggClient.Enabled() {
		logger.Info("đã bật đăng nhập Google", zap.String("client_id", cfg.Google.ClientID))
	}
	if sepayClient.Enabled() {
		logger.Info("đã bật thanh toán SePay",
			zap.String("bank", cfg.SePay.Bank),
			zap.String("so_tai_khoan", cfg.SePay.AccountNumber),
			zap.Bool("nhan_webhook", cfg.SePay.WebhookEnabled()),
			zap.Bool("tra_cuu_duoc_sao_ke", cfg.SePay.CanQuery()))
		if !cfg.SePay.CanQuery() {
			logger.Warn("chưa khai SEPAY_API_TOKEN — chỉ trông vào webhook, chạy ở máy local sẽ không tự xác nhận được đơn")
		}
		if !cfg.SePay.WebhookEnabled() {
			logger.Warn("chưa khai SEPAY_WEBHOOK_API_KEY — webhook SePay bị từ chối hết, hệ thống chỉ tự dò sao kê (đủ dùng ở máy local)")
		}
	} else {
		logger.Warn("chưa cấu hình SePay — hình thức chuyển khoản tự động bị ẩn khỏi storefront (đặt SEPAY_ACCOUNT_NUMBER/SEPAY_BANK/SEPAY_WEBHOOK_API_KEY trong .env)")
	}
	if payosClient.Enabled() {
		logger.Info("đã bật thanh toán PayOS",
			zap.String("return_url", cfg.PayOS.ReturnURL),
			zap.Duration("han_link", cfg.PayOS.Expire))
	} else {
		logger.Warn("chưa cấu hình PayOS — hình thức thanh toán online bị ẩn khỏi storefront (đặt PAYOS_CLIENT_ID/PAYOS_API_KEY/PAYOS_CHECKSUM_KEY trong .env)")
	}

	// 5. Repository
	userRepo := repository.NewUserRepository(db)
	tenantRepo := repository.NewTenantRepository(db)
	roleRepo := repository.NewRoleRepository(db)
	verifyRepo := repository.NewEmailVerificationRepository(db)
	categoryRepo := repository.NewCategoryRepository(db)
	brandRepo := repository.NewBrandRepository(db)
	productRepo := repository.NewProductRepository(db)
	orderRepo := repository.NewOrderRepository(db)
	paymentRepo := repository.NewPaymentRepository(db)
	returnRepo := repository.NewOrderReturnRepository(db)
	notifRepo := repository.NewNotificationRepository(db)
	inventoryRepo := repository.NewInventoryRepository(db)
	supplierRepo := repository.NewSupplierRepository(db)
	purchaseRepo := repository.NewPurchaseOrderRepository(db)
	receiptRepo := repository.NewGoodsReceiptRepository(db)
	pReturnRepo := repository.NewPurchaseReturnRepository(db)
	settingRepo := repository.NewSettingRepository(db)
	bannerRepo := repository.NewBannerRepository(db)
	reportRepo := repository.NewReportRepository(db)
	promotionRepo := repository.NewPromotionRepository(db)
	voucherRepo := repository.NewVoucherRepository(db)
	contactRepo := repository.NewContactRepository(db)
	newsletterRepo := repository.NewNewsletterRepository(db)

	// 6. Service
	// Cấu hình hệ thống KHÔNG còn nạp sẵn lúc khởi động: từ khi mỗi cửa hàng có
	// một bộ cấu hình riêng, "nạp trước" nghĩa là nạp của ai? Lúc này chưa có
	// request nào nên chưa có câu trả lời. Snapshot giờ nạp lười theo từng cửa
	// hàng ở lượt đọc đầu tiên (xem setting_service.go).
	settingSvc := service.NewSettingService(settingRepo)
	// Bộ khoá PayOS nằm ở .env chứ không ở bảng settings, nên registry không tự
	// biết cổng đã sẵn sàng chưa. Không nạp vào đây thì trang Cài đặt bật được một
	// hình thức thanh toán mà hệ thống chưa gọi nổi.
	settingSvc.SetPayOSReady(payosClient.Enabled())
	settingSvc.SetSePayReady(sepayClient.Enabled())

	authSvc := service.NewAuthService(userRepo, tenantRepo, roleRepo, verifyRepo, mailSender, jwtMgr, cfg.JWT, cfg.Mail, !cfg.App.IsProduction(), settingSvc, fbClient, ggClient)
	categorySvc := service.NewCategoryService(categoryRepo)
	brandSvc := service.NewBrandService(brandRepo)
	bannerSvc := service.NewBannerService(bannerRepo)
	productSvc := service.NewProductService(productRepo, categoryRepo, brandRepo)
	// Chương trình khuyến mãi: vừa là module quản trị, vừa là thứ tính giá sau giảm
	// cho cả trang bán hàng lẫn lúc đặt hàng. categoryRepo để chương trình khai ở
	// danh mục cha phủ được tới sản phẩm nằm trong danh mục cháu.
	promotionSvc := service.NewPromotionService(promotionRepo, categoryRepo)
	// Voucher: hiện mới là module quản trị (phát mã, đặt hạn, đặt lượt). Phần khách
	// nhập mã lúc thanh toán chưa nối vào luồng đặt hàng.
	voucherSvc := service.NewVoucherService(voucherRepo)
	customerSvc := service.NewCustomerService(userRepo)
	// Tài khoản nội bộ (quản trị & nhân viên) + vai trò — dùng chung userRepo với
	// khách hàng nhưng lọc ngược vai trò nên hai luồng không thấy dữ liệu của nhau.
	userSvc := service.NewUserService(userRepo, roleRepo)
	// Hub SSE + service thông báo: đơn mới hiện ngay trên trang admin và trạng thái
	// đơn hiện ngay ở trang tài khoản của khách, không cần tải lại trang.
	hub := realtime.NewHub()
	notifSvc := service.NewNotificationService(notifRepo, hub)
	// Cổng thanh toán PayOS. Dựng TRƯỚC orderSvc vì đặt hàng cần nó để xin link;
	// chiều ngược lại paymentSvc chỉ dùng orderRepo nên hai bên không tham chiếu vòng.
	paymentSvc := service.NewPaymentService(paymentRepo, orderRepo, payosClient, cfg.PayOS, sepayClient, notifSvc)
	// Mailer để gửi email xác nhận sau khi khách đặt hàng (chạy nền, hỏng không chặn đơn).
	// returnRepo để chặn hoàn cả đơn khi đơn đã có phiếu trả hàng riêng.
	// settingSvc cấp phí vận chuyển, ngưỡng miễn phí ship, hotline và tên cửa hàng.
	// promotionSvc để giá thu tiền đúng bằng giá khách nhìn thấy ngoài cửa hàng.
	orderSvc := service.NewOrderService(orderRepo, returnRepo, mailSender, cfg.Mail, notifSvc, settingSvc, paymentSvc, promotionSvc, voucherSvc)
	returnSvc := service.NewOrderReturnService(returnRepo, notifSvc, settingSvc)
	inventorySvc := service.NewInventoryService(inventoryRepo)
	supplierSvc := service.NewSupplierService(supplierRepo)
	// Yêu cầu khách gửi từ storefront (Liên hệ / Thu mua) + danh sách nhận tin.
	contactSvc := service.NewContactService(contactRepo, newsletterRepo)
	// purchaseSvc cần supplierRepo để chụp tên nhà cung cấp vào phiếu ngay lúc lập:
	// nhà cung cấp đổi tên sau đó thì phiếu cũ vẫn đọc đúng như lúc ký.
	purchaseSvc := service.NewPurchaseOrderService(purchaseRepo, supplierRepo)
	// Trang Nhập hàng chỉ ĐỌC lại các đợt hàng đã về; việc cộng tồn kho vẫn nằm
	// duy nhất ở purchaseSvc.Receive.
	receiptSvc := service.NewGoodsReceiptService(receiptRepo)
	// pReturnSvc cần purchaseRepo để đọc phiếu đặt gốc: chỉ trả lại được hàng ĐÃ NHẬN
	// của phiếu đó, và giá nhập lấy theo đúng dòng phiếu đặt.
	pReturnSvc := service.NewPurchaseReturnService(pReturnRepo, purchaseRepo)
	// Báo cáo chỉ đọc và gộp lại dữ liệu đã có, không phụ thuộc service nào khác —
	// nó đi thẳng xuống repository riêng của mình để các phép gộp chạy bằng SQL
	// thay vì nạp đơn ra bộ nhớ rồi cộng bằng Go.
	reportSvc := service.NewReportService(reportRepo)

	// 7. Handler
	handlers := router.Handlers{
		Health:   handler.NewHealthHandler(version),
		Auth:     handler.NewAuthHandler(authSvc),
		Category: handler.NewCategoryHandler(categorySvc),
		Brand:    handler.NewBrandHandler(brandSvc),
		Product:  handler.NewProductHandler(productSvc, promotionSvc),
		Customer: handler.NewCustomerHandler(customerSvc),
		Order:    handler.NewOrderHandler(orderSvc),
		Return:   handler.NewOrderReturnHandler(returnSvc),
		Notif:    handler.NewNotificationHandler(notifSvc, hub),
		Stock:    handler.NewInventoryHandler(inventorySvc),
		Supplier: handler.NewSupplierHandler(supplierSvc),
		Purchase: handler.NewPurchaseOrderHandler(purchaseSvc),
		Receipt:  handler.NewGoodsReceiptHandler(receiptSvc),
		PReturn:  handler.NewPurchaseReturnHandler(pReturnSvc),
		Setting:  handler.NewSettingHandler(settingSvc),
		User:     handler.NewUserHandler(userSvc),
		Payment:  handler.NewPaymentHandler(paymentSvc),
		Banner:   handler.NewBannerHandler(bannerSvc),
		Report:   handler.NewReportHandler(reportSvc),
		Promo:    handler.NewPromotionHandler(promotionSvc),
		Voucher:  handler.NewVoucherHandler(voucherSvc),
		Contact:  handler.NewContactHandler(contactSvc),
	}

	// 8. Router + HTTP server
	r := router.New(cfg, jwtMgr, handlers)
	srv := &http.Server{
		Addr:        announcedAddr(cfg.App.Port),
		Handler:     r,
		ReadTimeout: 15 * time.Second,
		// KHÔNG đặt WriteTimeout: nó tính từ lúc bắt đầu ghi response chứ không
		// phải giữa hai lần ghi, nên mọi giá trị hữu hạn đều cắt đứt luồng SSE
		// (/events) đúng sau chừng ấy giây. IdleTimeout vẫn dọn kết nối keep-alive
		// không dùng tới, còn kết nối SSE chết được phát hiện qua nhịp ping.
		IdleTimeout: 60 * time.Second,
	}

	// 9. Chạy server (goroutine) + graceful shutdown
	go func() {
		logger.Info("selliotech-api đang chạy", zap.String("addr", srv.Addr))
		if err := srv.ListenAndServe(); err != nil && !errors.Is(err, http.ErrServerClosed) {
			logger.Fatal("server dừng bất thường", zap.Error(err))
		}
	}()

	quit := make(chan os.Signal, 1)
	signal.Notify(quit, syscall.SIGINT, syscall.SIGTERM)
	<-quit
	logger.Info("đang tắt server...")

	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()
	if err := srv.Shutdown(ctx); err != nil {
		logger.Error("shutdown lỗi", zap.Error(err))
	}
	logger.Info("server đã tắt an toàn")
}

func announcedAddr(port string) string {
	return ":" + port
}

// dongKetNoi trả kết nối về hệ điều hành lúc tắt.
//
// Hỏng thì chỉ ghi nhật ký: đây là lúc process sắp chết, mà kernel đóng hộ mọi
// socket còn lại — không có gì để cứu, cũng không đáng làm lệnh tắt thất bại.
func dongKetNoi(db *gorm.DB, ten string) {
	sqlDB, err := db.DB()
	if err != nil {
		logger.Warn("không lấy được kết nối để đóng", zap.String("ket_noi", ten), zap.Error(err))
		return
	}
	if err := sqlDB.Close(); err != nil {
		logger.Warn("đóng kết nối lỗi", zap.String("ket_noi", ten), zap.Error(err))
	}
}

// applySwaggerHost đặt host/scheme của Swagger theo APP_BASE_URL.
// Cấu hình hỏng thì giữ nguyên giá trị sinh từ annotation thay vì làm sập app —
// trang tài liệu không phải chức năng sống còn của API.
func applySwaggerHost(baseURL string) {
	u, err := url.Parse(baseURL)
	if err != nil || u.Host == "" {
		logger.Warn("APP_BASE_URL không hợp lệ, Swagger giữ host mặc định",
			zap.String("app_base_url", baseURL))
		return
	}
	docs.SwaggerInfo.Host = u.Host
	if u.Scheme != "" {
		docs.SwaggerInfo.Schemes = []string{u.Scheme}
	}
}
