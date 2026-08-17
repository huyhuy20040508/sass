// Package router khai báo toàn bộ route và gắn middleware.
package router

import (
	"strings"
	"time"

	"sass-api/config"
	_ "sass-api/docs" // swagger docs (sinh bởi swag init)
	"sass-api/internal/domain"
	"sass-api/internal/handler"
	"sass-api/internal/middleware"
	"sass-api/pkg/jwt"
	"sass-api/pkg/logger"

	"github.com/gin-gonic/gin"
	swaggerfiles "github.com/swaggo/files"
	ginswagger "github.com/swaggo/gin-swagger"
	"go.uber.org/zap"
)

// Tiền tố chung của mọi đường API và ĐƯỜNG GÓI DỊCH VỤ của khu quản trị.
//
// Hằng số chứ không phải chuỗi rời: đường này được viết ở HAI chỗ — lúc đăng ký
// route, và lúc khai ngoại lệ "cửa hàng hết hạn vẫn đọc được đường này" cho
// JWTAuth. Hai bản chuỗi rời nhau thì đổi một bên là ngoại lệ lặng lẽ mất tác
// dụng, không lỗi nào nổi lên.
const (
	apiV1 = "/api/v1"
	// DuongGoiDichVu là đường đọc gói dịch vụ của CHÍNH cửa hàng đang đăng nhập.
	DuongGoiDichVu = "/admin/goi-dich-vu"
	// Hai đường của luồng KHÁCH TỰ GIA HẠN. Cùng nằm trong danh sách cho phép khi
	// cửa hàng đã bị khoá vì hết hạn — khách hết hạn chính là người cần trả tiền
	// nhất, đóng đường thanh toán của họ là tự cắt doanh thu của mình.
	DuongDatGiaHan = "/admin/goi-dich-vu/dat"
	DuongDonGiaHan = "/admin/goi-dich-vu/don/:id"
)

// Handlers gom các handler cần thiết để đăng ký route.
type Handlers struct {
	Health   *handler.HealthHandler
	Auth     *handler.AuthHandler
	Category *handler.CategoryHandler
	Brand    *handler.BrandHandler
	Product  *handler.ProductHandler
	Customer *handler.CustomerHandler
	Order    *handler.OrderHandler
	Return   *handler.OrderReturnHandler
	Notif    *handler.NotificationHandler
	Stock    *handler.InventoryHandler
	Supplier *handler.SupplierHandler
	Purchase *handler.PurchaseOrderHandler
	Receipt  *handler.GoodsReceiptHandler
	PReturn  *handler.PurchaseReturnHandler
	Setting  *handler.SettingHandler
	User     *handler.UserHandler
	// ChiNhanh là các ĐIỂM BÁN của chính cửa hàng (bảng `shops`), không phải
	// khách hàng của nền tảng — xem domain.ChiNhanh. Luôn có mặt: đây là dữ liệu
	// data plane, không phụ thuộc control plane.
	ChiNhanh *handler.ChiNhanhHandler
	Payment  *handler.PaymentHandler
	Banner   *handler.BannerHandler
	Report   *handler.ReportHandler
	Promo    *handler.PromotionHandler
	Voucher  *handler.VoucherHandler
	Contact  *handler.ContactHandler
	// Plan phục vụ KHU ĐIỀU HÀNH NỀN TẢNG (danh mục phần mềm, bảng giá, tính
	// năng gói). nil = chưa dựng control plane; cả nhóm /platform không được
	// đăng ký.
	Plan *handler.PlanHandler
	// KhachHang phục vụ ba màn hình còn lại của khu điều hành: khách hàng, hợp
	// đồng, doanh thu. Dựng cùng lúc với Plan — cùng một kết nối control plane.
	KhachHang *handler.KhachHangHandler
	// DungThu là ba đường GHI trên vòng đời hợp đồng (mở tài khoản dùng thử, gia
	// hạn, huỷ). Khác hai cái trên ở chỗ nó cầm CẢ HAI kết nối: cửa hàng và tài
	// khoản đăng nhập của khách nằm ở data plane.
	DungThu *handler.DungThuHandler
	// GiaHan là luồng KHÁCH TỰ GIA HẠN: đặt đơn, hỏi trạng thái, và nhận webhook
	// của cổng thanh toán. Cần control plane nên cũng nil khi chưa dựng.
	GiaHan *handler.GiaHanHandler
	// CauHinh là cấu hình của NHÀ CUNG CẤP (hôm nay: thông tin nhận chuyển khoản
	// để khách gia hạn). Cùng kết nối control plane với Plan, và cũng nil khi
	// chưa dựng.
	CauHinh *handler.CauHinhNenTangHandler
	// GoiDichVu là đường đọc của CHỦ TIỆM về hợp đồng của chính họ. Cũng cần
	// control plane nên cũng nil khi chưa dựng, nhưng nó nằm trong nhóm /admin
	// chứ không phải /platform — xem handler.GoiDichVuHandler.
	GoiDichVu *handler.GoiDichVuHandler
}

// New tạo *gin.Engine đã cấu hình đầy đủ middleware và route.
//
// tenMien là sổ tên miền của control plane, dùng để biết request đang mở cửa
// hàng nào khi người gọi chưa đăng nhập. Truyền nil = chưa dựng control plane:
// mọi thứ chạy y như trước, cụm bán hàng cho khách chỉ phục vụ người đã có token.
//
// nguoiDieuHanh là sổ NGƯỜI CỦA NỀN TẢNG, thứ canh nhóm /platform. nil thì nhóm
// đó trả 503 chứ không mở — xem middleware.XacThucNenTang.
//
// phien là lượt tra "tài khoản và cửa hàng của token này còn dùng được không",
// chạy ở mọi request đã đăng nhập — xem middleware.JWTAuth. Truyền nil thì
// middleware chỉ kiểm chữ ký như trước, và khoá/xoá một cửa hàng sẽ KHÔNG đá
// được phiên đang mở; chỉ dùng nil trong bài kiểm thử không có database.
func New(
	cfg *config.Config,
	jwtMgr *jwt.Manager,
	tenMien domain.TenantDomainRepository,
	nguoiDieuHanh domain.PlatformUserRepository,
	phien domain.PhienRepository,
	// chiNhanhRepo để xác minh chi nhánh mà trình duyệt khai trên header — xem
	// middleware.ChiNhanhDangLam. nil = không xác minh được ai nên không gắn chi
	// nhánh nào, và mọi lượt ghi kho rơi về chi nhánh bán online.
	chiNhanhRepo domain.ChiNhanhRepository,
	h Handlers,
) *gin.Engine {
	if cfg.App.IsProduction() {
		gin.SetMode(gin.ReleaseMode)
	}

	// Chưa bán hàng cho khách thì KHÔNG hỏi sổ tên miền, dù sổ có sẵn đó.
	//
	// Không phải để tiết kiệm một câu truy vấn, mà để không nới bán kính hỏng:
	// phân giải theo tên miền chỉ phục vụ khách vãng lai, trong khi đường nó nằm
	// trên (khachLa) cũng là đường khu quản trị đi qua. Cắm vào lúc chưa cần thì
	// control plane trục trặc là Shop Admin đăng nhập không được — một thành phần
	// mới hỏng kéo theo một thành phần cũ vốn chẳng liên quan gì tới nó.
	if !cfg.App.EnableStorefront {
		tenMien = nil
	}

	r := gin.New()

	// Chỉ tin nginx cùng máy khai hộ địa chỉ khách. Bỏ dòng này là gin tin MỌI
	// proxy, tức lấy nguyên X-Forwarded-For do khách tự gửi lên: nhật ký ghi địa
	// chỉ bịa, và mọi hạn mức theo IP bên dưới bị vượt qua chỉ bằng cách đổi
	// header mỗi lượt gọi.
	if err := r.SetTrustedProxies(cfg.App.TrustedProxies); err != nil {
		logger.Warn("danh sách trusted proxy không hợp lệ, giữ mặc định của gin",
			zap.Strings("gia_tri", cfg.App.TrustedProxies),
			zap.Error(err),
		)
	}

	r.Use(gin.Recovery())
	r.Use(middleware.RequestLogger())
	r.Use(middleware.CORS(cfg.CORS.AllowOrigins))

	// Swagger UI: http://localhost:<port>/swagger/index.html
	r.GET("/swagger/*any", ginswagger.WrapHandler(swaggerfiles.Handler))

	// --- Hạn mức gọi theo IP ------------------------------------------------
	//
	// Chỉ đặt lên những đường mà gọi dồn dập là có lợi cho kẻ xấu; đường đọc
	// hàng hoá (danh mục, sản phẩm, banner) để nguyên vì khách lướt nhanh qua
	// nhiều trang là chuyện bình thường, chặn vào đó chỉ làm phiền người mua.
	//
	// Đặt trong hàm gọn để tắt hết bằng một biến môi trường khi chạy máy cục bộ
	// (mọi lượt gọi ở đó cùng mang IP 127.0.0.1 nên dùng chung một hạn mức).
	han := func(name string, limit int, window time.Duration) gin.HandlerFunc {
		if !cfg.RateLimit.Enabled {
			return func(c *gin.Context) { c.Next() }
		}
		return middleware.RateLimit(name, limit, window)
	}

	v1 := r.Group("/api/v1")
	{
		v1.GET("/health", h.Health.Health)

		// --- Auth (công khai) ---
		//
		// Hạn mức đặt theo mức KHÁCH THẬT dùng tới, không phải theo mức "đủ chặn":
		// gõ sai mật khẩu 3-4 lần rồi nhớ ra là chuyện thường, nên 10 lượt/5 phút
		// vẫn thoải mái cho người quên, trong khi vòng lặp dò mật khẩu thì chết ngay.
		// khachLa gắn bộ middleware cho các đường CÔNG KHAI CÓ CHẠM DATABASE.
		//
		// OptionalJWTAuth để người đã đăng nhập mang theo cửa hàng của mình;
		// TenantRequired để người chưa đăng nhập nhận một câu trả lời đúng sự thật
		// ("chưa xác định được cửa hàng") thay vì lỗi 500 vọng lên từ bộ lọc tenant
		// dưới tầng GORM.
		//
		// Thứ tự ba middleware này là thứ tự của ba câu hỏi, và không đổi được:
		//
		//   1. OptionalJWTAuth — "có ai đăng nhập không?" Có thì mang theo cửa hàng
		//      của token.
		//   2. TenantFromHost  — "đang mở tên miền của tiệm nào?" Tên miền THẮNG
		//      token, và lệch nhau thì từ chối (xem middleware đó).
		//   3. TenantRequired  — "sau hai bước trên vẫn chưa biết tiệm nào?" Thì trả
		//      lời đúng sự thật thay vì để câu truy vấn vỡ tận dưới tầng GORM.
		//
		// Đảo 1 và 2 thì token không còn bị đối chiếu với tên miền, tức là mất luôn
		// chốt chặn "đứng ở trang tiệm B mà đọc dữ liệu tiệm A".
		khachLa := func(g *gin.RouterGroup) *gin.RouterGroup {
			return g.Group("",
				middleware.OptionalJWTAuth(jwtMgr),
				middleware.TenantFromHost(tenMien),
				middleware.TenantRequired(),
			)
		}

		// ĐĂNG KÝ CÔNG KHAI: khách tự mở tài khoản dùng thử từ trang giới thiệu.
		//
		// Đây là đường DUY NHẤT không cần token mà TẠO RA dữ liệu thật ở cả hai
		// database (cửa hàng + tài khoản đăng nhập bên data plane, hợp đồng bên sổ
		// nền tảng), nên nó có hai lớp chặn mà các đường công khai khác không cần:
		//
		//   - giới hạn tần suất theo IP ngay tại đây, chặt hơn hẳn mức chung: mở
		//     cửa hàng là việc người ta làm MỘT LẦN, nên vài lượt một giờ đã là
		//     rộng rãi. Số này cố ý không đọc từ cấu hình — một ô cấu hình lỡ tay
		//     đặt thành 0 sẽ TẮT chốt chặn mà không ai thấy (xem RateLimit).
		//   - ô bẫy `website` trong payload, chặn ở tầng service.
		//
		// Chỉ đăng ký khi h.DungThu có thật: thiếu control plane thì không có bảng
		// giá để chọn gói và không có sổ để ghi hợp đồng — trả 404 đúng sự thật là
		// máy chủ này chưa mở đường đăng ký, thay vì nhận form rồi hỏng giữa chừng
		// sau khi đã dựng xong cửa hàng.
		if h.DungThu != nil {
			v1.POST("/dang-ky", middleware.RateLimit("dang-ky", 5, time.Hour), h.DungThu.DangKy)
		}

		auth := v1.Group("/auth")
		{
			// Hai đường khu QUẢN TRỊ gọi (ApiClient: /auth/refresh, /auth/me) nên luôn
			// bật, kể cả khi đã tắt phần bán cho khách. Đăng nhập của khu quản trị đi
			// đường /auth/shop-login ngay dưới.
			//
			// /auth/login là đăng nhập bằng EMAIL của khách mua sắm, nên nó phải biết
			// đang đăng nhập vào cửa hàng nào — cùng lý do với cụm storefront.
			khachLa(auth).POST("/login", han("dang-nhap", 10, 5*time.Minute), h.Auth.Login)
			// Đăng nhập 3 ô của Shop Admin (mã cửa hàng / tên đăng nhập / mật khẩu).
			//
			// Hạn mức RIÊNG, không dùng chung khoá "dang-nhap": gộp chung thì một máy
			// dò mật khẩu nhân viên sẽ đồng thời khoá luôn đường đăng nhập của khách
			// mua hàng — hai nhóm người không liên quan gì tới nhau.
			auth.POST("/shop-login", han("dang-nhap-cua-hang", 10, 5*time.Minute), h.Auth.ShopLogin)
			// Đăng nhập KHU ĐIỀU HÀNH NỀN TẢNG (email + mật khẩu của một tài khoản
			// trong sổ `platform_users` — KHÔNG phải tài khoản của cửa hàng nào).
			//
			// KHÔNG bọc khachLa, và đó là toàn bộ lý do đường này tồn tại: người điều
			// hành nền tảng không đứng ở cửa hàng nào, nên bắt request phải xác định
			// được cửa hàng là bắt họ giả làm khách của một tiệm. Đường này cũng
			// không chạm data plane lần nào — xem AuthService.LoginPlatform, đọc nó
			// trước khi sửa gì ở dòng này.
			//
			// Hạn mức RIÊNG, cùng lý do với /auth/shop-login: gộp khoá với đường của
			// khách mua hàng thì một máy dò mật khẩu quản trị sẽ khoá luôn cửa hàng.
			auth.POST("/platform-login", han("dang-nhap-nen-tang", 10, 5*time.Minute), h.Auth.PlatformLogin)
			// Làm mới token của khu điều hành. Đường RIÊNG vì /auth/refresh ngay
			// dưới từ chối mọi token không thuộc cửa hàng nào — mà token nền tảng
			// thì luôn như vậy, và điều kiện đó bên kia phải giữ nguyên.
			auth.POST("/platform-refresh", han("lam-moi-token-nen-tang", 60, 5*time.Minute), h.Auth.PlatformRefresh)
			// Hồ sơ người điều hành. Đi qua chốt của khu điều hành chứ không phải
			// JWTAuth: token nền tảng không mang cửa hàng nào nên JWTAuth từ chối nó.
			auth.GET("/platform-me", middleware.XacThucNenTang(jwtMgr, nguoiDieuHanh), h.Auth.PlatformMe)
			// Rộng tay hơn hẳn: access token sống 15 phút, khách mở nhiều tab thì
			// mỗi tab tự làm mới một lượt — siết chỗ này là đá văng người đang mua hàng.
			auth.POST("/refresh", han("lam-moi-token", 60, 5*time.Minute), h.Auth.Refresh)
			auth.GET("/me", middleware.JWTAuth(jwtMgr, phien), h.Auth.Me)

			// Phần còn lại chỉ dành cho khách tự đăng ký ngoài cửa hàng. Tài khoản
			// nhân viên do quản trị viên tạo ở /admin/users, không đi đường này.
			if cfg.App.EnableStorefront {
				khach := khachLa(auth)
				khach.POST("/register", han("dang-ky", 5, 10*time.Minute), h.Auth.Register)
				khach.POST("/verify-email", han("xac-thuc", 10, 5*time.Minute), h.Auth.VerifyEmail)
				// Gửi lại mã là đường DUY NHẤT khiến API tự gửi thư ra ngoài, nên siết
				// chặt nhất: không có nó thì bất kỳ ai cũng dội được hàng nghìn thư vào
				// hộp thư của người khác, và địa chỉ gửi của cửa hàng bị đánh dấu spam.
				khach.POST("/resend-verification", han("gui-lai-ma", 5, 10*time.Minute), h.Auth.ResendVerification)
				khach.POST("/forgot-password", han("quen-mat-khau", 5, 10*time.Minute), h.Auth.ForgotPassword)
				khach.POST("/reset-password", han("dat-lai-mat-khau", 10, 5*time.Minute), h.Auth.ResetPassword)
				khach.POST("/facebook", han("dang-nhap-mxh", 20, 5*time.Minute), h.Auth.FacebookLogin)
				khach.POST("/google", han("dang-nhap-mxh", 20, 5*time.Minute), h.Auth.GoogleLogin)
			}
		}

		// --- Catalog (công khai, chỉ đọc) ---
		//
		// Đọc token nếu có: cùng một endpoint, nhưng chỉ nhân viên quản trị mới thấy
		// giá vốn. Khách và người lạ nhận đúng dữ liệu như trước.
		catalog := khachLa(v1)
		catalog.GET("/categories", h.Category.List)
		catalog.GET("/categories/:id", h.Category.Get)
		catalog.GET("/brands", h.Brand.List)
		catalog.GET("/brands/:id", h.Brand.Get)
		catalog.GET("/products", h.Product.List)
		catalog.GET("/products/:slug", h.Product.GetBySlug)

		// ====================================================================
		// CỤM BÁN HÀNG CHO KHÁCH (storefront) — TẮT mặc định ở dự án này.
		//
		// Bật lại bằng STOREFRONT_API_ENABLED=true trong .env. Toàn bộ handler và
		// service phía dưới vẫn còn nguyên, đây chỉ là chỗ đăng ký đường dẫn.
		//
		// Khu quản trị KHÔNG gọi đường nào trong cụm này — đã đối chiếu với danh
		// sách URI trong admin/app/Services/ApiClient.php.
		// ====================================================================
		if cfg.App.EnableStorefront {
			// Cả cụm dưới đây phục vụ khách mua sắm, tức là phải biết đang mở cửa
			// hàng nào. Hôm nay chỉ khách ĐÃ ĐĂNG NHẬP mới nói được điều đó (qua
			// token); khách vãng lai sẽ nhận 401 cho tới khi có phân giải theo tên
			// miền — kể cả webhook thanh toán, vì cổng thanh toán cũng không có
			// cách nào tự khai mình đang báo tiền cho cửa hàng nào.
			store := khachLa(v1)

			// --- Banner trang chủ (công khai, chỉ đọc) ---
			// Đường này CHỈ trả banner đang bật và trong lịch chạy — bản đầy đủ cho
			// trang quản trị nằm ở /admin/banners. "positions" đứng trước để không bị
			// hiểu nhầm thành tham số nào khác nếu sau này thêm route con.
			store.GET("/banners/positions", h.Banner.Positions)
			store.GET("/banners", h.Banner.Public)

			// --- Cấu hình công khai (storefront đọc tên cửa hàng, liên hệ, phí ship) ---
			store.GET("/settings", h.Setting.Public)

			// --- Yêu cầu khách gửi từ storefront (form Liên hệ / Thu mua / nhận tin) ---
			// Siết tay vì đây là hai đường AI CŨNG GỬI ĐƯỢC, không cần tài khoản: bỏ ngỏ
			// thì một vòng lặp là đủ nhồi hàng nghìn dòng rác, và nhân viên phải ngồi
			// lọc tay giữa đống đó để tìm khách thật.
			store.POST("/contact-requests", han("gui-yeu-cau", 5, 10*time.Minute), h.Contact.Create)
			store.POST("/newsletter/subscribe", han("dang-ky-nhan-tin", 5, 10*time.Minute), h.Contact.Subscribe)

			// --- Tra cứu đơn hàng công khai (khách không cần đăng nhập) ---
			// Có hạn mức vì đường này trả THÔNG TIN CÁ NHÂN (tên, số điện thoại, địa
			// chỉ giao) chỉ dựa trên mã đơn: để gọi thoải mái là dò được mã đơn hàng
			// loạt rồi gom sạch danh sách khách của cửa hàng.
			store.GET("/public/orders/lookup", han("tra-cuu-don", 20, 5*time.Minute), h.Order.Lookup)
			// Đặt hàng từ storefront — công khai, nhưng đọc token nếu có để gắn đơn vào tài khoản.
			// Hạn mức ở đây chặn kiểu phá bằng cách tạo hàng loạt đơn ảo để giữ sạch tồn kho.
			store.POST("/orders", han("dat-hang", 10, 10*time.Minute), h.Order.Checkout)
			// Đối chiếu giá + tồn kho của giỏ trước khi đặt (chỉ đọc, không tạo đơn).
			// Trang thanh toán tự gọi mỗi lần khách sửa giỏ nên phải để rộng tay.
			store.POST("/orders/quote", han("doi-chieu-gio", 60, 5*time.Minute), h.Order.Quote)

			// Xem trước mã giảm giá ở giỏ hàng. OptionalJWTAuth để mã giới hạn "mỗi
			// khách N lượt" kiểm được ngay lúc gõ với khách đã đăng nhập, mà khách vãng
			// lai vẫn dùng được ô nhập mã.
			// Có hạn mức vì đây là đường DÒ MÃ: gõ thử mã nào cũng biết ngay đúng hay
			// sai, gọi thoải mái thì quét hết được kho mã giảm giá của cửa hàng.
			store.POST("/vouchers/check", han("kiem-ma", 30, 5*time.Minute), h.Voucher.Check)
			// Mã đại trà gợi ý sẵn cho khách bấm chọn. Cũng OptionalJWTAuth để loại được
			// mã khách đã dùng hết lượt riêng, thay vì khoe rồi báo lỗi lúc họ bấm vào.
			store.POST("/vouchers/available", h.Voucher.Available)

			// --- Thanh toán online (PayOS) ---
			// Cả hai đều CÔNG KHAI và bắt buộc phải vậy: webhook do máy chủ PayOS gọi
			// (không có tài khoản nào để đăng nhập), còn trang tra kết quả thì khách vãng
			// lai cũng phải xem được. Cái bảo vệ webhook là chữ ký HMAC trong chính gói
			// dữ liệu, không phải token.
			store.POST("/payments/payos/webhook", h.Payment.PayOSWebhook)
			store.POST("/payments/sepay/webhook", h.Payment.SePayWebhook)
			store.GET("/payments/:code", h.Payment.Status)
			// Đơn hàng của chính khách đang đăng nhập (storefront)
			me := v1.Group("/orders/me", middleware.JWTAuth(jwtMgr, phien))
			{
				me.GET("", h.Order.MyList)
				me.GET("/:id", h.Order.MyGet)
				me.POST("/:id/cancel", h.Order.MyCancel)
				// Món còn trả được của đơn — trang tài khoản dựng form trả hàng từ đây.
				me.GET("/:id/returnable", h.Return.MyReturnable)
			}

			// --- Trả hàng của chính khách đang đăng nhập (storefront) ---
			myReturns := v1.Group("/returns/me", middleware.JWTAuth(jwtMgr, phien))
			{
				myReturns.GET("", h.Return.MyList)
				myReturns.POST("", h.Return.MyCreate)
				myReturns.GET("/:id", h.Return.MyGet)
				myReturns.POST("/:id/cancel", h.Return.MyCancel)
			}
		}

		// --- Thông báo & realtime (dùng chung admin lẫn khách hàng) ---
		// Cùng một bộ endpoint cho cả hai bên: kênh đọc được suy ra từ vai trò
		// trong token chứ không phải từ đường dẫn, nên không có cách nào gọi
		// nhầm sang kênh của người khác.
		notif := v1.Group("/notifications", middleware.JWTAuth(jwtMgr, phien))
		{
			notif.GET("", h.Notif.List)
			notif.POST("/read-all", h.Notif.MarkAllRead)
			notif.PUT("/:id/read", h.Notif.MarkRead)
		}
		// Luồng SSE — token truyền qua query vì EventSource không đặt được header.
		v1.GET("/events", middleware.JWTAuthStream(jwtMgr), h.Notif.Stream)

		// --- Admin (yêu cầu quyền quản trị) ---
		//
		// Hai tầng quyền:
		//   - admin : super_admin, admin, staff — nghiệp vụ hằng ngày (đơn, kho, sản phẩm).
		//   - manage: super_admin, admin — dữ liệu người và cấu hình hệ thống.
		//
		// Nhân viên (staff) làm được việc kho và đơn hàng nhưng KHÔNG xem được hồ sơ
		// khách hàng, không sửa cấu hình cửa hàng và không đụng vào tài khoản của
		// người khác. Đây là chặn theo NHÓM ENDPOINT, chưa phải phân quyền theo từng
		// chức năng — muốn chi tiết hơn thì phải dựng bảng permissions.
		admin := v1.Group("/admin")
		// DuongGoiDichVu đi cùng JWTAuth ở đây, chứ không nằm trong middleware:
		// đó là đường DUY NHẤT một cửa hàng đã hết hạn còn đọc được, và nó phải
		// khớp từng ký tự với chỗ đăng ký route bên dưới. Viết hằng số ở một chỗ
		// rồi dùng cho cả hai để không có bản nào lệch bản nào — lệch thì ngoại lệ
		// im lặng biến mất, và người hết hạn quay lại cảnh không mở được trang nào.
		admin.Use(middleware.JWTAuth(jwtMgr, phien,
			apiV1+DuongGoiDichVu, apiV1+DuongDatGiaHan, apiV1+DuongDonGiaHan,
		), middleware.RequireRoles(
			domain.RoleSuperAdmin, domain.RoleAdmin, domain.RoleStaff,
		))
		// Chi nhánh đang làm việc: đọc từ header ở MỌI đường của khu quản trị, kể
		// cả nhóm nghiệp vụ hằng ngày — nhân viên bán hàng và thủ kho mới là người
		// đứng ở một chi nhánh cụ thể, còn quản trị viên thì đổi qua lại.
		//
		// Đặt sau JWTAuth vì lượt tra chi nhánh chạy bằng ctx đã mang tenant: đó
		// chính là thứ ngăn một cửa hàng gửi lên id chi nhánh của cửa hàng khác.
		admin.Use(middleware.ChiNhanhDangLam(chiNhanhRepo))

		manage := admin.Group("")
		manage.Use(middleware.RequireRoles(domain.RoleSuperAdmin, domain.RoleAdmin))
		{
			// Hồ sơ của CHÍNH người đang đăng nhập — nằm ở nhóm `admin` chứ không
			// phải `manage`: nhân viên không vào được /admin/users, nếu đường này
			// cũng đóng thì họ không có cách nào tự đổi mật khẩu, phải nhờ quản trị
			// viên đặt hộ rồi đọc mật khẩu qua tin nhắn.
			admin.GET("/me", h.User.Me)
			admin.PUT("/me", h.User.UpdateMe)
			admin.PUT("/me/password", h.User.ChangePassword)

			admin.POST("/categories", h.Category.Create)
			admin.PUT("/categories/:id", h.Category.Update)
			admin.DELETE("/categories/:id", h.Category.Delete)

			admin.POST("/brands", h.Brand.Create)
			admin.PUT("/brands/:id", h.Brand.Update)
			admin.DELETE("/brands/:id", h.Brand.Delete)

			// Banner trang chủ — nội dung tiếp thị, cùng tầng quyền với sản phẩm.
			// "sort" phải đứng trước /:id, nếu không nó bị hiểu là id banner.
			admin.GET("/banners", h.Banner.List)
			admin.POST("/banners", h.Banner.Create)
			admin.PUT("/banners/sort", h.Banner.Sort)
			admin.GET("/banners/:id", h.Banner.Get)
			admin.PUT("/banners/:id", h.Banner.Update)
			admin.PUT("/banners/:id/status", h.Banner.UpdateStatus)
			admin.DELETE("/banners/:id", h.Banner.Delete)

			// Chương trình khuyến mãi — cùng tầng quyền với sản phẩm vì nó chính là
			// thứ quyết định giá bán. "stats" phải đứng trước /:id, nếu không nó bị
			// hiểu là id chương trình.
			admin.GET("/promotions", h.Promo.List)
			admin.GET("/promotions/stats", h.Promo.Stats)
			admin.POST("/promotions", h.Promo.Create)
			admin.GET("/promotions/:id", h.Promo.Get)
			admin.PUT("/promotions/:id", h.Promo.Update)
			admin.PUT("/promotions/:id/status", h.Promo.UpdateStatus)
			admin.DELETE("/promotions/:id", h.Promo.Delete)

			// Voucher — mã khách tự nhập lúc thanh toán, giảm trên tổng đơn. Cùng
			// tầng quyền với khuyến mãi vì cũng là tiền ra khỏi cửa hàng.
			admin.GET("/vouchers", h.Voucher.List)
			admin.GET("/vouchers/stats", h.Voucher.Stats)
			admin.POST("/vouchers", h.Voucher.Create)
			admin.GET("/vouchers/:id", h.Voucher.Get)
			admin.PUT("/vouchers/:id", h.Voucher.Update)
			admin.PUT("/vouchers/:id/status", h.Voucher.UpdateStatus)
			admin.DELETE("/vouchers/:id", h.Voucher.Delete)

			admin.POST("/products", h.Product.Create)
			// Xoá hàng loạt đặt TRƯỚC nhóm :id cho dễ đọc — một giao dịch thay vì
			// N lượt gọi nối đuôi nhau từ trang quản trị.
			admin.POST("/products/bulk-delete", h.Product.BulkDelete)
			admin.POST("/products/:id/duplicate", h.Product.Duplicate)
			// GET chi tiết: form sửa nạp lại dữ liệu mới nhất trước khi cho sửa,
			// thay vì làm việc trên bản đã nạp cùng danh sách (có thể đã cũ).
			admin.GET("/products/:id", h.Product.Get)
			admin.PUT("/products/:id", h.Product.Update)
			admin.PUT("/products/:id/status", h.Product.UpdateStatus)
			admin.DELETE("/products/:id", h.Product.Delete)

			// Khách hàng — hồ sơ cá nhân của người mua, chỉ quản trị viên xem được.
			manage.GET("/customers", h.Customer.List)
			manage.GET("/customers/stats", h.Customer.Stats)
			manage.GET("/customers/:id", h.Customer.Get)
			manage.POST("/customers", h.Customer.Create)
			manage.PUT("/customers/:id", h.Customer.Update)
			manage.PUT("/customers/:id/status", h.Customer.UpdateStatus)
			manage.PUT("/customers/:id/password", h.Customer.SetPassword)
			manage.DELETE("/customers/:id", h.Customer.Delete)

			// Đơn hàng
			admin.GET("/orders", h.Order.List)
			admin.POST("/orders", h.Order.Create)
			// Bán tại quầy — nằm ở nhóm `admin` chứ không phải `manage`: người đứng
			// quầy là nhân viên, và cả tính năng vô nghĩa nếu chỉ chủ cửa hàng bấm
			// được nút thu tiền.
			admin.POST("/orders/pos", h.Order.POSCheckout)
			admin.GET("/orders/stats", h.Order.Stats)
			admin.GET("/orders/revenue", h.Order.Revenue)
			admin.GET("/orders/:id", h.Order.Get)
			admin.PUT("/orders/:id", h.Order.Update)
			admin.PUT("/orders/:id/status", h.Order.UpdateStatus)
			admin.PUT("/orders/:id/payment", h.Order.UpdatePayment)
			admin.PUT("/orders/:id/shipping", h.Order.UpdateShipping)
			admin.PUT("/orders/:id/note", h.Order.UpdateNote)
			// Món còn trả được của một đơn — màn hình lập phiếu trả dựng form từ đây.
			admin.GET("/orders/:id/returnable", h.Return.Returnable)

			// Trả hàng
			admin.GET("/returns", h.Return.List)
			admin.POST("/returns", h.Return.Create)
			admin.GET("/returns/stats", h.Return.Stats)
			admin.GET("/returns/:id", h.Return.Get)
			admin.PUT("/returns/:id/status", h.Return.UpdateStatus)
			admin.PUT("/returns/:id/settle", h.Return.Settle)
			admin.PUT("/returns/:id/note", h.Return.UpdateNote)

			// Tồn kho — đơn vị là biến thể sản phẩm, không phải sản phẩm.
			// Hai đường tĩnh phải đứng trước /:id, nếu không "stats" và "adjust"
			// sẽ bị hiểu thành id biến thể.
			admin.GET("/inventory", h.Stock.List)
			admin.GET("/inventory/stats", h.Stock.Stats)
			admin.POST("/inventory/adjust", h.Stock.BulkAdjust)
			admin.PUT("/inventory/cost", h.Stock.SetCost)
			admin.GET("/inventory/:id", h.Stock.Get)
			admin.GET("/inventory/:id/history", h.Stock.History)
			admin.PUT("/inventory/:id", h.Stock.Adjust)

			// Nhà cung cấp — bên bán hàng cho cửa hàng, dùng cho phiếu đặt hàng nhập.
			admin.GET("/suppliers", h.Supplier.List)
			admin.POST("/suppliers", h.Supplier.Create)
			admin.GET("/suppliers/:id", h.Supplier.Get)
			admin.PUT("/suppliers/:id", h.Supplier.Update)
			admin.DELETE("/suppliers/:id", h.Supplier.Delete)

			// Đặt hàng nhập — chiều MUA VÀO của kho.
			// Hai đường tĩnh ("stats", "variants") phải đứng trước /:id, nếu không
			// chúng sẽ bị hiểu thành id phiếu.
			admin.GET("/purchases", h.Purchase.List)
			admin.POST("/purchases", h.Purchase.Create)
			admin.GET("/purchases/stats", h.Purchase.Stats)
			admin.GET("/purchases/variants", h.Purchase.SearchVariants)
			admin.GET("/purchases/:id", h.Purchase.Get)
			admin.PUT("/purchases/:id", h.Purchase.Update)
			admin.DELETE("/purchases/:id", h.Purchase.Delete)
			admin.PUT("/purchases/:id/status", h.Purchase.UpdateStatus)
			admin.PUT("/purchases/:id/payment", h.Purchase.UpdatePayment)
			// Nhận hàng là hành động tạo ra bút toán kho mới mỗi lần gọi (nhận nhiều
			// đợt), nên là POST chứ không phải PUT.
			admin.POST("/purchases/:id/receive", h.Purchase.Receive)

			// Nhập hàng — CHỈ ĐỌC lại các đợt hàng đã về (dựng từ sổ kho). Việc ghi
			// kho vẫn chỉ có một đường duy nhất là POST /purchases/:id/receive.
			// "stats" phải đứng trước /:code, nếu không nó bị hiểu là mã đợt nhập.
			// Trả hàng nhập — trả hàng lại nhà cung cấp (chiều ngược của nhập hàng).
			// "stats" và "returnable" phải đứng trước /:id để không bị hiểu là id phiếu.
			admin.GET("/purchase-returns", h.PReturn.List)
			admin.POST("/purchase-returns", h.PReturn.Create)
			admin.GET("/purchase-returns/stats", h.PReturn.Stats)
			admin.GET("/purchase-returns/returnable/:id", h.PReturn.Returnable)
			admin.GET("/purchase-returns/:id", h.PReturn.Get)
			admin.PUT("/purchase-returns/:id", h.PReturn.Update)
			admin.PUT("/purchase-returns/:id/status", h.PReturn.UpdateStatus)
			admin.PUT("/purchase-returns/:id/refund", h.PReturn.UpdateRefund)
			admin.DELETE("/purchase-returns/:id", h.PReturn.Delete)

			// Cấu hình hệ thống — key-value, ghi nhiều khoá một lần.
			//
			// ĐỌC mở cho cả nhân viên: logo, tên cửa hàng và ngưỡng tồn kho được
			// dùng ở khung giao diện và trang Tồn kho mà nhân viên vẫn vào được —
			// chặn đọc thì họ nhìn thấy logo mặc định và ngưỡng cảnh báo khác hẳn
			// quản trị viên, cùng một hệ thống mà hai người thấy hai kiểu.
			// GHI vẫn chỉ quản trị viên.
			// --- Yêu cầu của khách + danh sách nhận tin ---
			// "stats" đứng TRƯỚC ":id" để gin không hiểu nhầm nó là một ID.
			admin.GET("/contact-requests", h.Contact.List)
			admin.GET("/contact-requests/stats", h.Contact.Stats)
			admin.GET("/contact-requests/:id", h.Contact.Get)
			admin.PUT("/contact-requests/:id/status", h.Contact.UpdateStatus)
			admin.DELETE("/contact-requests/:id", h.Contact.Delete)

			admin.GET("/newsletter", h.Contact.Subscribers)
			admin.PUT("/newsletter/:id/unsubscribe", h.Contact.Unsubscribe)

			admin.GET("/settings", h.Setting.List)
			manage.PUT("/settings", h.Setting.Update)

			// Tài khoản NỘI BỘ (quản trị & nhân viên) + vai trò.
			// Khách hàng KHÔNG đi đường này — họ có /admin/customers riêng, và
			// service ở đây trả 404 nếu id trỏ vào một khách hàng.
			// "stats" phải đứng trước /:id, nếu không nó bị hiểu là id tài khoản.
			// Chi nhánh — mở/đóng điểm bán. Ở nhóm `manage` (nhân viên KHÔNG vào)
			// cùng mức với Người dùng và Cài đặt: mở thêm một điểm bán ăn thẳng vào
			// hạn mức `max_shops` của hợp đồng, tức là một quyết định có tiền đứng
			// sau chứ không phải việc hằng ngày.
			manage.GET("/chi-nhanh", h.ChiNhanh.List)
			manage.POST("/chi-nhanh", h.ChiNhanh.Create)
			manage.GET("/chi-nhanh/:id", h.ChiNhanh.Get)
			manage.PUT("/chi-nhanh/:id", h.ChiNhanh.Update)
			manage.DELETE("/chi-nhanh/:id", h.ChiNhanh.Delete)

			manage.GET("/users", h.User.List)
			manage.POST("/users", h.User.Create)
			manage.GET("/users/stats", h.User.Stats)
			manage.GET("/users/:id", h.User.Get)
			manage.PUT("/users/:id", h.User.Update)
			manage.PUT("/users/:id/status", h.User.UpdateStatus)
			manage.PUT("/users/:id/password", h.User.SetPassword)
			manage.DELETE("/users/:id", h.User.Delete)

			manage.GET("/roles", h.User.Roles)

			// Gói dịch vụ của CHÍNH cửa hàng này — đường đọc duy nhất đi từ phía
			// khách sang sổ nền tảng.
			//
			// Nằm ở nhóm `manage` (nhân viên KHÔNG vào): đây là chuyện hợp đồng và
			// tiền giữa chủ tiệm với nhà cung cấp phần mềm, cùng mức riêng tư với
			// trang Khách hàng và Cài đặt.
			//
			// h.GoiDichVu nil = chưa nối được control plane: không đăng ký đường nào,
			// trả 404 y như trước khi có tính năng. Đăng ký rồi trả 503 ở từng lượt
			// gọi thì màn hình trông như đang hỏng, trong khi máy chủ này đơn giản là
			// chưa có sổ để đọc.
			if h.GoiDichVu != nil {
				manage.GET(strings.TrimPrefix(DuongGoiDichVu, "/admin"), h.GoiDichVu.CuaToi)
			}

			// Khách tự gia hạn: đặt đơn và hỏi trạng thái. Cùng nhóm quyền `manage`
			// với đường đọc gói — trả tiền cho phần mềm là việc của chủ tiệm, không
			// phải của nhân viên bán hàng.
			if h.GiaHan != nil {
				manage.POST(strings.TrimPrefix(DuongDatGiaHan, "/admin"), h.GiaHan.Dat)
				manage.GET(strings.TrimPrefix(DuongDonGiaHan, "/admin"), h.GiaHan.TrangThai)
			}
			manage.PUT("/roles/:id", h.User.UpdateRole)

			admin.GET("/receipts", h.Receipt.List)
			admin.GET("/receipts/stats", h.Receipt.Stats)
			admin.GET("/receipts/:code", h.Receipt.Get)

			// Báo cáo — CHỈ ĐỌC, gộp lại dữ liệu đã có theo khoảng thời gian.
			//
			// Nằm ở nhóm `manage` (nhân viên KHÔNG vào) vì báo cáo phơi ra hai thứ
			// mà các trang nghiệp vụ không phơi: giá vốn / lợi nhuận từng mặt hàng,
			// và bảng chi tiêu kèm thông tin liên hệ của từng khách. Nhân viên đã
			// không mở được trang Khách hàng thì cũng không nên đọc được cùng dữ
			// liệu đó qua đường vòng là báo cáo.
			manage.GET("/reports/revenue", h.Report.Revenue)
			manage.GET("/reports/orders", h.Report.Orders)
			manage.GET("/reports/products", h.Report.Products)
			manage.GET("/reports/customers", h.Report.Customers)

			// Bắn thông báo thử — công cụ chẩn đoán lúc cài đặt, KHÔNG mở ở
			// production: nó tạo dữ liệu thật trong bảng notifications.
			if !cfg.App.IsProduction() {
				admin.POST("/notifications/test", h.Notif.TestPush)
			}
		}

		// --- Webhook của cổng thanh toán (CÔNG KHAI) ---
		//
		// KHÔNG nằm trong nhóm /platform bên dưới, dù đường dẫn trông giống: nhóm đó
		// đứng sau XacThucNenTang (đòi token của người điều hành), mà PayOS thì không
		// có token nào. Thứ chứng minh gói dữ liệu đến từ họ là CHỮ KÝ trên body —
		// xem GiaHanHandler.Webhook.
		//
		// Đặt hạn mức theo IP: đây là đường công khai duy nhất của control plane, và
		// một vòng lặp gửi chữ ký sai vào đây không được phép ăn hết kết nối database.
		if h.GiaHan != nil {
			v1.POST("/platform/webhook/payos", han("webhook-gia-han", 120, time.Minute), h.GiaHan.Webhook)
		}

		// --- Khu điều hành nền tảng ---
		//
		// Nhóm này đọc/ghi CONTROL PLANE: bảng giá, tính năng gói — dữ liệu của
		// nền tảng, không thuộc cửa hàng nào. Vì vậy nó KHÔNG được bộ lọc tenant
		// che chắn, và phải tự canh cửa.
		//
		// MỘT middleware duy nhất, và cố ý KHÔNG phải JWTAuth + RequireRoles:
		// `super_admin` là vai trò trong MỘT CỬA HÀNG, cửa hàng nào cũng có một
		// người như vậy. XacThucNenTang đòi TOKEN NỀN TẢNG — thứ chỉ
		// /auth/platform-login cấp sau khi đối chiếu mật khẩu trong sổ
		// `platform_users`. Đọc chú thích ở middleware đó trước khi đụng vào.
		//
		// h.Plan nil = chưa dựng control plane: không đăng ký đường nào cả, nhóm
		// trả 404 y như trước khi có nó. Khác với việc đăng ký rồi trả 503 ở từng
		// đường — một hệ thống chưa lắp bộ phận này thì đừng quảng cáo là có.
		if h.Plan != nil {
			nenTang := v1.Group("/platform", middleware.XacThucNenTang(jwtMgr, nguoiDieuHanh))
			// Danh mục phần mềm — thứ khu điều hành đọc đầu tiên để dựng bộ chọn
			// phần mềm, vì mọi màn hình phía sau đều tách theo app.
			nenTang.GET("/apps", h.Plan.Apps)
			// "features" nằm sau /:id nên không có đường tĩnh nào bị hiểu nhầm thành
			// id gói.
			// Cấu hình của nhà cung cấp — đọc mở cho cả `support`, ghi chỉ
			// owner/operator (xét trong handler).
			if h.CauHinh != nil {
				nenTang.GET("/cau-hinh", h.CauHinh.Doc)
				nenTang.PUT("/cau-hinh", h.CauHinh.Ghi)
			}

			nenTang.GET("/plans", h.Plan.List)
			nenTang.GET("/plans/:id/features", h.Plan.Features)
			nenTang.PUT("/plans/:id/features", h.Plan.UpdateFeatures)
			// Sửa chính DÒNG bảng giá (tên, giá, dùng thử, còn bán hay không) —
			// khác /features là điều khoản của gói. Hai đường riêng vì hai màn hình
			// riêng, và vì gộp lại thì một lượt sửa giá phải gửi kèm cả bộ hạn mức.
			nenTang.PUT("/plans/:id", h.Plan.Sua)

			// Ba màn hình còn lại. Tất cả đều ĐỌC XUYÊN MỌI KHÁCH HÀNG, và phạm vi
			// duy nhất giới hạn chúng là phân công phần mềm của người gọi.
			//
			// "Người dùng thử" và "Người dùng chính thức" là CÙNG một đường, khác
			// mỗi `?trang_thai=` — hai bảng dữ liệu giống hệt nhau chỉ khác một bộ
			// lọc thì tách làm hai endpoint là chép luật lọc ra hai bản.
			nenTang.GET("/tenants", h.KhachHang.KhachHang)
			nenTang.GET("/subscriptions", h.KhachHang.HopDong)
			nenTang.GET("/doanh-thu", h.KhachHang.DoanhThu)

			// Ba đường GHI trên vòng đời hợp đồng.
			//
			// Chỉ đăng ký khi h.DungThu có thật: nhóm này cần CẢ kết nối data plane
			// (cửa hàng, tài khoản đăng nhập của khách) lẫn control plane, nên nó
			// vắng mặt được trong khi phần đọc vẫn chạy.
			//
			// /dung-thu là đường RIÊNG chứ không phải POST /subscriptions: đường kia
			// đọc như "thêm một hợp đồng cho khách đã có", còn cái này dựng cả một
			// khách hàng mới. Hai việc khác nhau thì hai tên khác nhau — gộp lại là
			// ngày mai có người gọi nó với `tenant_id` sẵn có và không hiểu vì sao
			// nó lại đòi mật khẩu.
			if h.DungThu != nil {
				// Hai đường TẠO KHÁCH MỚI trọn gói (cửa hàng + tài khoản đăng nhập +
				// hợp đồng). Khác nhau đúng một chỗ: hợp đồng ra `trial` hay
				// `active`. Bên dưới chúng dùng chung một hàm — xem
				// service.taoKhachHangMoi.
				nenTang.POST("/dung-thu", h.DungThu.Tao)
				nenTang.POST("/chinh-thuc", h.DungThu.TaoChinhThuc)

				// Ký hợp đồng cho cửa hàng ĐÃ TỒN TẠI. Không dựng gì bên data plane,
				// nên nó là đường duy nhất dùng được cho khách CŨ quay lại — mã cửa
				// hàng của họ đã bị chiếm, hai đường trên sẽ từ chối.
				nenTang.GET("/cua-hang-chua-ky", h.DungThu.CuaHangChuaKy)
				nenTang.POST("/hop-dong", h.DungThu.KyHopDong)
				// Chi tiết + sửa của MỘT hợp đồng. GET không qua cửa vai trò
				// (`support` phải xem được), PUT thì có — chốt nằm trong handler.
				nenTang.GET("/subscriptions/:id", h.DungThu.ChiTiet)
				nenTang.PUT("/subscriptions/:id", h.DungThu.Sua)
				// POST chứ không PUT: đây là HÀNH ĐỘNG trên một hợp đồng (đẩy hạn,
				// đóng lại), không phải thay thế nội dung của nó. Hậu tố nằm sau /:id
				// nên không có đường tĩnh nào bị hiểu nhầm thành id.
				nenTang.POST("/subscriptions/:id/gia-han", h.DungThu.GiaHan)
				// Đặt lại mật khẩu tài khoản quản trị của khách. Đường DUY NHẤT của
				// khu điều hành ghi vào `users` của data plane — xem TaiKhoanCuaHangRepository.
				nenTang.POST("/subscriptions/:id/doi-mat-khau", h.DungThu.DoiMatKhau)
				// Ghi nhận tiền vào. Đường RIÊNG với gia-han, không phải tham số của
				// nó: đẩy hạn và ghi nhận tiền là hai việc, và gộp lại thì mỗi lần
				// gia hạn báo một khoản doanh thu chưa ai trả.
				nenTang.POST("/subscriptions/:id/thu-tien", h.DungThu.ThuTien)
				nenTang.POST("/subscriptions/:id/huy", h.DungThu.Huy)
			}
		}
	}

	return r
}
