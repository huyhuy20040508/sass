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
	"sass-api/pkg/bimat"
	"sass-api/pkg/facebook"
	"sass-api/pkg/google"
	"sass-api/pkg/jwt"
	"sass-api/pkg/logger"
	"sass-api/pkg/mailer"
	"sass-api/pkg/minvoice"
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
	// tenMienRepo còn nil nghĩa là chưa phân giải được cửa hàng theo tên miền —
	// router nhận nil và cụm bán hàng cho khách chỉ phục vụ người đã đăng nhập.
	// nguoiDieuHanhRepo + planHandler là KHU ĐIỀU HÀNH: sổ người của nền tảng và
	// bảng giá. Cả hai còn nil nghĩa là nhóm /platform không được đăng ký — khu
	// điều hành nhận 404, đúng sự thật là máy chủ này chưa nối được sổ.
	var (
		tenMienRepo       domain.TenantDomainRepository
		nguoiDieuHanhRepo domain.PlatformUserRepository
		planHandler       *handler.PlanHandler
		khachHangHandler  *handler.KhachHangHandler
		dungThuHandler    *handler.DungThuHandler
		// goiDichVuHandler là trang "Các gói dịch vụ" của Shop Admin — chủ tiệm tự
		// tra hợp đồng của chính mình. Cũng đọc control plane nên cũng nil khi chưa
		// nối được sổ; lúc đó Shop Admin nói rõ là chưa tra được, thay vì hiện một
		// trang trống.
		goiDichVuHandler *handler.GoiDichVuHandler
		// cauHinhHandler là màn hình Cài đặt của khu điều hành — hôm nay giữ thông
		// tin nhận chuyển khoản để khách tự gia hạn.
		cauHinhHandler *handler.CauHinhNenTangHandler
		// giaHanHandler là luồng KHÁCH TỰ GIA HẠN: đặt đơn, hỏi trạng thái, nhận
		// webhook của cổng thanh toán. Cầm CẢ HAI kết nối — hợp đồng và sổ thu ở
		// control plane, còn lượt mở khoá cửa hàng thì ở data plane.
		giaHanHandler *handler.GiaHanHandler
		// quetHan là lượt quét nền: khoá cửa hàng của hợp đồng đã hết hạn, và nhắc
		// khách trước khi hợp đồng chết. Còn nil nghĩa là chưa nối được control
		// plane — khi đó KHÔNG có hợp đồng nào đọc được, nên cũng không có gì để quét.
		quetHan         service.QuetHanService
		quetHopDongRepo domain.HopDongRepository
		quetCuaHangRepo domain.CuaHangMoiRepository
		// Hai sổ mà TRANG GÓI DỊCH VỤ và CỬA XÉT HẠN MỨC cùng đọc. Giữ lại ở đây
		// thay vì dựng tại chỗ vì cả hai thứ dùng chúng đều phải đợi tới mục 6:
		// chúng cần thêm repository của data plane (đếm sản phẩm, đếm tài khoản)
		// để nói được "đang dùng bao nhiêu trên bao nhiêu".
		//
		// Còn nil = chưa nối được control plane, và khi đó KHÔNG ép hạn mức nào cả:
		// không đọc được hợp đồng thì không biết trần của ai là bao nhiêu, mà chặn
		// theo phỏng đoán là khoá nhầm cửa hàng của người đang trả tiền.
		thueBaoRepo domain.ThueBaoCuaKhachRepository
		bangGiaRepo domain.PlanRepository
	)

	platformDB, err := repository.NewPlatformDB(cfg.Platform, cfg.App.IsProduction())
	if err != nil {
		// Từ khi cụm bán hàng cho khách phân giải cửa hàng theo TÊN MIỀN, control
		// plane không còn là "cái sổ chưa ai dùng": nó nằm trên đường đi của mọi
		// request của khách vãng lai. Bật storefront mà thiếu nó thì không tên miền
		// nào phân giải được — cả trang bán hàng đứng im, nhưng process vẫn sống và
		// vẫn báo khoẻ, nên hỏng kiểu đó chỉ lộ ra qua lời khách phàn nàn.
		if cfg.App.EnableStorefront {
			panic("đã bật STOREFRONT_API_ENABLED nhưng không kết nối được control plane (" +
				cfg.Platform.Name + "): không phân giải được tên miền nào — " + err.Error())
		}
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
			if cfg.App.EnableStorefront {
				panic("đã bật STOREFRONT_API_ENABLED nhưng control plane (" + cfg.Platform.Name +
					") chưa có lược đồ — chạy `cd api && go run ./cmd/migrate -nen-tang chay`: " + err.Error())
			}
			logger.Warn("control plane kết nối được nhưng chưa có lược đồ",
				zap.String("db", cfg.Platform.Name),
				zap.String("cach_chua", "cd api && go run ./cmd/migrate -nen-tang chay"),
				zap.Error(err))
		} else {
			logger.Info("đã kết nối control plane",
				zap.String("db", cfg.Platform.Name), zap.Int64("so_tenant", soTenant))

			// Sổ tên miền: thứ cho khách VÃNG LAI biết mình đang đứng ở tiệm nào.
			// Chỉ dựng khi lược đồ có thật — dựng trên một database trắng thì mọi
			// tên miền đều "không tìm thấy" và trang bán hàng im lặng đứng yên.
			tenMienRepo = repository.NewTenantDomainRepository(platformDB, cfg.App.Code)

			// Khu điều hành: sổ người của nền tảng (canh cửa nhóm /platform) và bảng
			// giá. Dựng CÙNG nhau vì thiếu vế đầu thì vế sau không có ai canh —
			// middleware nhận nil sẽ đóng cửa, nhưng đóng cửa một nhóm đã đăng ký thì
			// khó hiểu hơn hẳn là không đăng ký nó.
			nguoiDieuHanhRepo = repository.NewPlatformUserRepository(platformDB)
			planHandler = handler.NewPlanHandler(service.NewPlanService(
				repository.NewPlanRepository(platformDB),
				repository.NewAppRepository(platformDB),
			))
			// Hộp mã hoá cho các ô BÍ MẬT của cấu hình nền tảng (khoá PayOS).
			// Chưa khai PLATFORM_SECRET_KEY thì hộp rỗng: màn hình cài đặt vẫn mở,
			// vẫn sửa được số tài khoản, chỉ TỪ CHỐI lưu khoá bí mật kèm lý do —
			// từ chối chứ không ghi plaintext.
			hopBiMat := bimat.New(cfg.Platform.SecretKey)
			if !hopBiMat.SanSang() {
				logger.Warn("chưa khai PLATFORM_SECRET_KEY — khu điều hành sẽ không lưu được khoá cổng thanh toán",
					zap.String("cach_chua", "thêm PLATFORM_SECRET_KEY vào api/.env rồi khởi động lại"))
			}
			cauHinhNenTangRepo := repository.NewCauHinhNenTangRepository(platformDB)
			cauHinhHandler = handler.NewCauHinhNenTangHandler(
				service.NewCauHinhNenTangService(cauHinhNenTangRepo, hopBiMat),
			)

			// Khách tự gia hạn. Khoá cổng thanh toán đọc từ chính bảng cấu hình trên
			// (đã mã hoá), nên đổi khoá ở màn hình Cài đặt là có hiệu lực ngay, không
			// đợi khởi động lại máy chủ.
			giaHanHandler = handler.NewGiaHanHandler(service.NewGiaHanService(
				repository.NewDonGiaHanRepository(platformDB),
				repository.NewThueBaoCuaKhachRepository(platformDB),
				repository.NewPlanRepository(platformDB),
				repository.NewHopDongRepository(platformDB),
				repository.NewCuaHangMoiRepository(db),
				cauHinhNenTangRepo,
				hopBiMat,
				cfg.App.Code,
				cfg.App.ShopAdminURL,
			))
			khachHangHandler = handler.NewKhachHangHandler(service.NewKhachHangService(
				repository.NewKhachHangRepository(platformDB),
			))

			// Ba đường GHI trên vòng đời hợp đồng. Đây là chỗ DUY NHẤT hai kết nối
			// gặp nhau trong một service: mở tài khoản dùng thử nghĩa là dựng cửa
			// hàng + tài khoản đăng nhập bên `db` (data plane) rồi ký hợp đồng bên
			// `platformDB`. Không có giao dịch nào bao được cả hai — thứ tự và phần
			// hụt được nói rõ trong service.DungThuService.
			dungThuHandler = handler.NewDungThuHandler(service.NewDungThuService(
				repository.NewPlanRepository(platformDB),
				repository.NewHopDongRepository(platformDB),
				repository.NewCuaHangMoiRepository(db),
				repository.NewTaiKhoanCuaHangRepository(db),
				cfg.App.Code,
				cfg.App.ShopAdminURL,
			))

			// Hai sổ của control plane mà TRANG GÓI DỊCH VỤ và CỬA XÉT HẠN MỨC cùng
			// đọc: hợp đồng của đúng một khách, và bảng giá. Cửa hẹp hơn hẳn khu
			// điều hành — đúng một khách, đúng phần mềm mà tiến trình này phục vụ
			// (cfg.App.Code), xem service.GoiDichVuService.
			//
			// Hai thứ dùng chúng đều dựng Ở MỤC 6 chứ không ở đây, vì cả hai còn cần
			// repository của data plane để đếm số đang dùng.
			thueBaoRepo = repository.NewThueBaoCuaKhachRepository(platformDB)
			bangGiaRepo = repository.NewPlanRepository(platformDB)

			// Lượt quét hạn: cầm đúng cặp repository của DungThuService, và cũng bắc
			// qua hai plane. Nó là mảnh còn thiếu của cơ chế chặn khách hết hạn —
			// `tenants.status` từ trước tới nay chỉ có người ghi bằng tay, nên hợp
			// đồng dùng thử hết hạn không đá được ai ra khỏi phần mềm.
			// Hai repository của lượt quét hạn. Bản thân service dựng ở mục 6, sau
			// khi có sổ thông báo — nó cần đẩy lời nhắc "sắp hết hạn" vào chuông của
			// khách, mà chuông thì nằm ở data plane.
			quetHopDongRepo = repository.NewHopDongRepository(platformDB)
			quetCuaHangRepo = repository.NewCuaHangMoiRepository(db)
		}

		// Kết nối này giờ ĐANG được repository trên cầm, nhưng vẫn đóng lúc thoát:
		// tới đây server đã tắt xong, không còn request nào chạm vào nó.
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
	quyTacMaRepo := repository.NewQuyTacMaRepository(db)
	thueRepo := repository.NewThueRepository(db)
	donViTinhRepo := repository.NewDonViTinhRepository(db)
	viTriRepo := repository.NewViTriRepository(db)
	thuocTinhRepo := repository.NewThuocTinhRepository(db)
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
	chiNhanhRepo := repository.NewChiNhanhRepository(db)
	nhanSuRepo := repository.NewNhanVienRepository(db)
	caRepo := repository.NewCaLamViecRepository(db)
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

	// nguoiDieuHanhRepo có thể nil (chưa dựng control plane): khi đó đăng nhập
	// khu điều hành trả 503 nói đúng lý do, chứ KHÔNG rơi về cách cũ là mượn
	// super_admin của một cửa hàng — cách đó chính là lỗ hổng đã đóng ở 0007.
	authSvc := service.NewAuthService(userRepo, nguoiDieuHanhRepo, tenantRepo, roleRepo, verifyRepo, mailSender, jwtMgr, cfg.JWT, cfg.Mail, !cfg.App.IsProduction(), settingSvc, fbClient, ggClient)
	categorySvc := service.NewCategoryService(categoryRepo, quyTacMaRepo)
	bannerSvc := service.NewBannerService(bannerRepo)
	// Cửa xét HẠN MỨC HỢP ĐỒNG — chỗ ba con số đã ký (chi nhánh / tài khoản /
	// sản phẩm) lần đầu có hiệu lực thật, thay vì chỉ được in ra màn hình.
	//
	// Còn nil khi chưa nối được control plane, và mọi service nhận nó đều hiểu
	// nil là "không ép gì cả": không đọc được hợp đồng thì không biết trần của
	// khách nào là bao nhiêu, mà chặn theo phỏng đoán là khoá nhầm cửa hàng của
	// người đang trả tiền. Xem service.HanMucService.
	var hanMucSvc service.HanMucService
	if thueBaoRepo != nil {
		hanMucSvc = service.NewHanMucService(
			thueBaoRepo, productRepo, userRepo, chiNhanhRepo, cfg.App.Code)

		// Trang "Các gói dịch vụ" của Shop Admin dựng ở đây vì nó in kèm số ĐANG
		// DÙNG cạnh mỗi hạn mức — con số đó đọc bằng đúng cửa đếm mà lượt chặn ở
		// trên dùng, nên hai chỗ không bao giờ nói hai câu khác nhau.
		goiDichVuHandler = handler.NewGoiDichVuHandler(service.NewGoiDichVuService(
			thueBaoRepo, bangGiaRepo, hanMucSvc, cfg.App.Code,
		))
	}

	// viTriRepo để kiểm vị trí gán cho mặt hàng có thật và thuộc đúng cửa hàng.
	productSvc := service.NewProductService(productRepo, categoryRepo, hanMucSvc, quyTacMaRepo, viTriRepo, donViTinhRepo, thuocTinhRepo, chiNhanhRepo)
	// Chi nhánh: các ĐIỂM BÁN trong một cửa hàng. Đây là thứ gói Chuỗi bán, và
	// cũng là nơi hạn mức `max_shops` lần đầu có việc để làm — trước nó, con số
	// ấy canh một thao tác mà không màn hình nào làm được.
	// quyTacMaRepo để mã bỏ trống được đặt theo quy tắc đánh số của cửa hàng.
	chiNhanhSvc := service.NewChiNhanhService(chiNhanhRepo, hanMucSvc, quyTacMaRepo)
	// Hoá đơn điện tử: tài khoản cổng HĐĐT của TỪNG chi nhánh. hopETax mã hoá mật
	// khẩu trước khi ghi — chưa khai ETAX_SECRET_KEY thì lượt kết nối bị từ chối
	// kèm lý do, chứ không ghi plaintext.
	hopETax := bimat.New(cfg.ETax.SecretKey)
	if !hopETax.SanSang() {
		logger.Warn("chưa khai ETAX_SECRET_KEY — cửa hàng sẽ không kết nối được hoá đơn điện tử",
			zap.String("cach_chua", "thêm ETAX_SECRET_KEY vào api/.env rồi khởi động lại"))
	}
	etaxSvc := service.NewEtaxService(
		repository.NewEtaxRepository(db), chiNhanhRepo, orderRepo, hopETax, minvoice.New(),
	)
	caSvc := service.NewCaLamViecService(caRepo, userRepo, chiNhanhRepo)
	// Quy tắc đánh số chứng từ — chiNhanhRepo để chốt chi nhánh có thật trước khi
	// ghi một bộ quy tắc không màn hình nào đọc tới.
	quyTacMaSvc := service.NewQuyTacMaService(quyTacMaRepo, chiNhanhRepo)
	// Thuế suất — bốn loại dựng sẵn khi cửa hàng mở màn hình lần đầu.
	thueSvc := service.NewThueService(thueRepo)
	// Đơn vị tính — bảng tra của riêng từng cửa hàng, không seed sẵn dòng nào.
	// quyTacMaRepo để mã bỏ trống được đặt theo quy tắc đánh số của cửa hàng.
	donViTinhSvc := service.NewDonViTinhService(donViTinhRepo, quyTacMaRepo)
	// Vị trí — cùng khuôn với đơn vị tính: bảng tra mã + tên của riêng cửa hàng.
	viTriSvc := service.NewViTriService(viTriRepo, quyTacMaRepo)
	// Thuộc tính — cùng khuôn với đơn vị tính, thêm tầng giá trị con.
	thuocTinhSvc := service.NewThuocTinhService(thuocTinhRepo, quyTacMaRepo)
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
	userSvc := service.NewUserService(userRepo, roleRepo, hanMucSvc)
	// Nhân sự: HỒ SƠ người đi làm. Nhận userSvc chứ không phải userRepo — khối
	// "cấp tài khoản" trong hồ sơ phải đi qua đủ luật của module tài khoản (vai
	// trò cấp được, tên đăng nhập, email trùng, hạn mức tài khoản của hợp đồng),
	// và chép lại năm luật đó ở tầng nhân sự là chép thiếu một cái.
	quyenRepo := repository.NewQuyenRepository(db)
	nhanSuSvc := service.NewNhanSuService(nhanSuRepo, chiNhanhRepo, userSvc, quyenRepo, quyTacMaRepo)
	nhomQuyenSvc := service.NewNhomQuyenService(quyenRepo, userRepo)
	// Hub SSE + service thông báo: đơn mới hiện ngay trên trang admin và trạng thái
	// đơn hiện ngay ở trang tài khoản của khách, không cần tải lại trang.
	hub := realtime.NewHub()
	notifSvc := service.NewNotificationService(notifRepo, hub)
	// Cổng thanh toán PayOS. Dựng TRƯỚC orderSvc vì đặt hàng cần nó để xin link;
	// chiều ngược lại paymentSvc chỉ dùng orderRepo nên hai bên không tham chiếu vòng.
	paymentSvc := service.NewPaymentService(paymentRepo, orderRepo, payosClient, cfg.PayOS, sepayClient, notifSvc, etaxSvc)
	// Mailer để gửi email xác nhận sau khi khách đặt hàng (chạy nền, hỏng không chặn đơn).
	// returnRepo để chặn hoàn cả đơn khi đơn đã có phiếu trả hàng riêng.
	// settingSvc cấp phí vận chuyển, ngưỡng miễn phí ship, hotline và tên cửa hàng.
	// promotionSvc để giá thu tiền đúng bằng giá khách nhìn thấy ngoài cửa hàng.
	orderSvc := service.NewOrderService(orderRepo, returnRepo, mailSender, cfg.Mail, notifSvc, settingSvc, paymentSvc, promotionSvc, voucherSvc, etaxSvc)
	returnSvc := service.NewOrderReturnService(returnRepo, notifSvc, settingSvc)
	inventorySvc := service.NewInventoryService(inventoryRepo)
	supplierSvc := service.NewSupplierService(supplierRepo, quyTacMaRepo)
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

	// Lượt quét hạn dựng ở ĐÂY chứ không ở mục 3b: nó cần notifSvc để đẩy lời
	// nhắc "sắp hết hạn" vào chuông của khách, mà sổ thông báo chỉ có sau khi
	// dựng xong tầng service. Thiếu control plane thì hai repository còn nil và
	// lượt quét không được bật — đúng như trước.
	if quetHopDongRepo != nil && quetCuaHangRepo != nil {
		quetHan = service.NewQuetHanService(quetHopDongRepo, quetCuaHangRepo, notifSvc)
	}

	// 7. Handler
	handlers := router.Handlers{
		Health:    handler.NewHealthHandler(version),
		Auth:      handler.NewAuthHandler(authSvc),
		Category:  handler.NewCategoryHandler(categorySvc),
		Product:   handler.NewProductHandler(productSvc, promotionSvc),
		Customer:  handler.NewCustomerHandler(customerSvc),
		Order:     handler.NewOrderHandler(orderSvc),
		Return:    handler.NewOrderReturnHandler(returnSvc),
		Notif:     handler.NewNotificationHandler(notifSvc, hub),
		Stock:     handler.NewInventoryHandler(inventorySvc),
		Supplier:  handler.NewSupplierHandler(supplierSvc),
		Purchase:  handler.NewPurchaseOrderHandler(purchaseSvc),
		Receipt:   handler.NewGoodsReceiptHandler(receiptSvc),
		PReturn:   handler.NewPurchaseReturnHandler(pReturnSvc),
		Setting:   handler.NewSettingHandler(settingSvc),
		User:      handler.NewUserHandler(userSvc),
		ChiNhanh:  handler.NewChiNhanhHandler(chiNhanhSvc),
		ETax:      handler.NewEtaxHandler(etaxSvc),
		NhanSu:    handler.NewNhanSuHandler(nhanSuSvc),
		NhomQuyen: handler.NewNhomQuyenHandler(nhomQuyenSvc),
		QuyTacMa:  handler.NewQuyTacMaHandler(quyTacMaSvc),
		Thue:      handler.NewThueHandler(thueSvc),
		DonViTinh: handler.NewDonViTinhHandler(donViTinhSvc),
		ViTri:     handler.NewViTriHandler(viTriSvc),
		ThuocTinh: handler.NewThuocTinhHandler(thuocTinhSvc),
		Ca:        handler.NewCaLamViecHandler(caSvc),
		Payment:   handler.NewPaymentHandler(paymentSvc),
		Banner:    handler.NewBannerHandler(bannerSvc),
		Report:    handler.NewReportHandler(reportSvc),
		Promo:     handler.NewPromotionHandler(promotionSvc),
		Voucher:   handler.NewVoucherHandler(voucherSvc),
		Contact:   handler.NewContactHandler(contactSvc),
		Plan:      planHandler,
		KhachHang: khachHangHandler,
		DungThu:   dungThuHandler,
		GoiDichVu: goiDichVuHandler,
		CauHinh:   cauHinhHandler,
		GiaHan:    giaHanHandler,
	}

	// 8. Router + HTTP server
	//
	// NewPhienRepository là lượt tra "tài khoản và cửa hàng của token này còn dùng
	// được không", chạy ở mọi request đã đăng nhập. Không có nó thì khoá hay xoá
	// một cửa hàng chỉ chặn được lượt ĐĂNG NHẬP MỚI — ai đang mở phiên vẫn dùng
	// tiếp cho tới khi access token hết hạn. Xem middleware.JWTAuth.
	r := router.New(cfg, jwtMgr, tenMienRepo, nguoiDieuHanhRepo,
		repository.NewPhienRepository(db), chiNhanhRepo,
		quyenRepo, handlers)
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

	// 8b. Lượt quét hợp đồng quá hạn, chạy nền suốt vòng đời máy chủ.
	//
	// Không có nó thì hợp đồng hết hạn chỉ là một dòng đỏ trong khu điều hành:
	// khách dùng thử hết hạn vẫn đăng nhập và bán hàng bình thường, vì
	// `tenants.status` — cột mà cả đường đăng nhập lẫn middleware đều đọc — không
	// có ai ghi xuống. Xem service.QuetHanService.
	ngungQuet := func() {}
	if quetHan != nil {
		ctxQuet, huyQuet := context.WithCancel(context.Background())
		ngungQuet = huyQuet
		go quetHan.Chay(ctxQuet, service.NhipQuetMacDinh)
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

	// Dừng lượt quét TRƯỚC khi đóng kết nối: một câu UPDATE đang chạy dở trên
	// một kết nối vừa bị đóng thì chỉ để lại một dòng lỗi khó hiểu trong nhật ký
	// tắt máy.
	ngungQuet()

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
