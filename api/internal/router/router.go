// Package router khai báo toàn bộ route và gắn middleware.
package router

import (
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
	Payment  *handler.PaymentHandler
	Banner   *handler.BannerHandler
	Report   *handler.ReportHandler
	Promo    *handler.PromotionHandler
	Voucher  *handler.VoucherHandler
	Contact  *handler.ContactHandler
}

// New tạo *gin.Engine đã cấu hình đầy đủ middleware và route.
func New(cfg *config.Config, jwtMgr *jwt.Manager, h Handlers) *gin.Engine {
	if cfg.App.IsProduction() {
		gin.SetMode(gin.ReleaseMode)
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
		auth := v1.Group("/auth")
		{
			// Ba đường này khu QUẢN TRỊ gọi (ApiClient: /auth/login, /auth/refresh,
			// /auth/me) nên luôn bật, kể cả khi đã tắt phần bán cho khách.
			auth.POST("/login", han("dang-nhap", 10, 5*time.Minute), h.Auth.Login)
			// Rộng tay hơn hẳn: access token sống 15 phút, khách mở nhiều tab thì
			// mỗi tab tự làm mới một lượt — siết chỗ này là đá văng người đang mua hàng.
			auth.POST("/refresh", han("lam-moi-token", 60, 5*time.Minute), h.Auth.Refresh)
			auth.GET("/me", middleware.JWTAuth(jwtMgr), h.Auth.Me)

			// Phần còn lại chỉ dành cho khách tự đăng ký ngoài cửa hàng. Tài khoản
			// nhân viên do quản trị viên tạo ở /admin/users, không đi đường này.
			if cfg.App.EnableStorefront {
				auth.POST("/register", han("dang-ky", 5, 10*time.Minute), h.Auth.Register)
				auth.POST("/verify-email", han("xac-thuc", 10, 5*time.Minute), h.Auth.VerifyEmail)
				// Gửi lại mã là đường DUY NHẤT khiến API tự gửi thư ra ngoài, nên siết
				// chặt nhất: không có nó thì bất kỳ ai cũng dội được hàng nghìn thư vào
				// hộp thư của người khác, và địa chỉ gửi của cửa hàng bị đánh dấu spam.
				auth.POST("/resend-verification", han("gui-lai-ma", 5, 10*time.Minute), h.Auth.ResendVerification)
				auth.POST("/forgot-password", han("quen-mat-khau", 5, 10*time.Minute), h.Auth.ForgotPassword)
				auth.POST("/reset-password", han("dat-lai-mat-khau", 10, 5*time.Minute), h.Auth.ResetPassword)
				auth.POST("/facebook", han("dang-nhap-mxh", 20, 5*time.Minute), h.Auth.FacebookLogin)
				auth.POST("/google", han("dang-nhap-mxh", 20, 5*time.Minute), h.Auth.GoogleLogin)
			}
		}

		// --- Catalog (công khai, chỉ đọc) ---
		v1.GET("/categories", h.Category.List)
		v1.GET("/categories/:id", h.Category.Get)
		v1.GET("/brands", h.Brand.List)
		v1.GET("/brands/:id", h.Brand.Get)
		// Đọc token nếu có: cùng một endpoint, nhưng chỉ nhân viên quản trị mới thấy
		// giá vốn. Khách và người lạ nhận đúng dữ liệu như trước.
		v1.GET("/products", middleware.OptionalJWTAuth(jwtMgr), h.Product.List)
		v1.GET("/products/:slug", middleware.OptionalJWTAuth(jwtMgr), h.Product.GetBySlug)

		// ====================================================================
		// CỤM BÁN HÀNG CHO KHÁCH (storefront) — TẮT mặc định ở dự án này.
		//
		// Bật lại bằng STOREFRONT_API_ENABLED=true trong .env. Toàn bộ handler và
		// service phía dưới vẫn còn nguyên, đây chỉ là chỗ đăng ký đường dẫn.
		//
		// Khu quản trị KHÔNG gọi đường nào trong cụm này — đã đối chiếu với danh
		// sách URI trong football-admin/app/Services/ApiClient.php.
		// ====================================================================
		if cfg.App.EnableStorefront {
			// --- Banner trang chủ (công khai, chỉ đọc) ---
			// Đường này CHỈ trả banner đang bật và trong lịch chạy — bản đầy đủ cho
			// trang quản trị nằm ở /admin/banners. "positions" đứng trước để không bị
			// hiểu nhầm thành tham số nào khác nếu sau này thêm route con.
			v1.GET("/banners/positions", h.Banner.Positions)
			v1.GET("/banners", h.Banner.Public)

			// --- Cấu hình công khai (storefront đọc tên cửa hàng, liên hệ, phí ship) ---
			v1.GET("/settings", h.Setting.Public)

			// --- Yêu cầu khách gửi từ storefront (form Liên hệ / Thu mua / nhận tin) ---
			// Siết tay vì đây là hai đường AI CŨNG GỬI ĐƯỢC, không cần tài khoản: bỏ ngỏ
			// thì một vòng lặp là đủ nhồi hàng nghìn dòng rác, và nhân viên phải ngồi
			// lọc tay giữa đống đó để tìm khách thật.
			v1.POST("/contact-requests", han("gui-yeu-cau", 5, 10*time.Minute), h.Contact.Create)
			v1.POST("/newsletter/subscribe", han("dang-ky-nhan-tin", 5, 10*time.Minute), h.Contact.Subscribe)

			// --- Tra cứu đơn hàng công khai (khách không cần đăng nhập) ---
			// Có hạn mức vì đường này trả THÔNG TIN CÁ NHÂN (tên, số điện thoại, địa
			// chỉ giao) chỉ dựa trên mã đơn: để gọi thoải mái là dò được mã đơn hàng
			// loạt rồi gom sạch danh sách khách của cửa hàng.
			v1.GET("/public/orders/lookup", han("tra-cuu-don", 20, 5*time.Minute), h.Order.Lookup)
			// Đặt hàng từ storefront — công khai, nhưng đọc token nếu có để gắn đơn vào tài khoản.
			// Hạn mức ở đây chặn kiểu phá bằng cách tạo hàng loạt đơn ảo để giữ sạch tồn kho.
			v1.POST("/orders", han("dat-hang", 10, 10*time.Minute), middleware.OptionalJWTAuth(jwtMgr), h.Order.Checkout)
			// Đối chiếu giá + tồn kho của giỏ trước khi đặt (chỉ đọc, không tạo đơn).
			// Trang thanh toán tự gọi mỗi lần khách sửa giỏ nên phải để rộng tay.
			v1.POST("/orders/quote", han("doi-chieu-gio", 60, 5*time.Minute), h.Order.Quote)

			// Xem trước mã giảm giá ở giỏ hàng. OptionalJWTAuth để mã giới hạn "mỗi
			// khách N lượt" kiểm được ngay lúc gõ với khách đã đăng nhập, mà khách vãng
			// lai vẫn dùng được ô nhập mã.
			// Có hạn mức vì đây là đường DÒ MÃ: gõ thử mã nào cũng biết ngay đúng hay
			// sai, gọi thoải mái thì quét hết được kho mã giảm giá của cửa hàng.
			v1.POST("/vouchers/check", han("kiem-ma", 30, 5*time.Minute), middleware.OptionalJWTAuth(jwtMgr), h.Voucher.Check)
			// Mã đại trà gợi ý sẵn cho khách bấm chọn. Cũng OptionalJWTAuth để loại được
			// mã khách đã dùng hết lượt riêng, thay vì khoe rồi báo lỗi lúc họ bấm vào.
			v1.POST("/vouchers/available", middleware.OptionalJWTAuth(jwtMgr), h.Voucher.Available)

			// --- Thanh toán online (PayOS) ---
			// Cả hai đều CÔNG KHAI và bắt buộc phải vậy: webhook do máy chủ PayOS gọi
			// (không có tài khoản nào để đăng nhập), còn trang tra kết quả thì khách vãng
			// lai cũng phải xem được. Cái bảo vệ webhook là chữ ký HMAC trong chính gói
			// dữ liệu, không phải token.
			v1.POST("/payments/payos/webhook", h.Payment.PayOSWebhook)
			v1.POST("/payments/sepay/webhook", h.Payment.SePayWebhook)
			v1.GET("/payments/:code", h.Payment.Status)
			// Đơn hàng của chính khách đang đăng nhập (storefront)
			me := v1.Group("/orders/me", middleware.JWTAuth(jwtMgr))
			{
				me.GET("", h.Order.MyList)
				me.GET("/:id", h.Order.MyGet)
				me.POST("/:id/cancel", h.Order.MyCancel)
				// Món còn trả được của đơn — trang tài khoản dựng form trả hàng từ đây.
				me.GET("/:id/returnable", h.Return.MyReturnable)
			}

			// --- Trả hàng của chính khách đang đăng nhập (storefront) ---
			myReturns := v1.Group("/returns/me", middleware.JWTAuth(jwtMgr))
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
		notif := v1.Group("/notifications", middleware.JWTAuth(jwtMgr))
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
		admin.Use(middleware.JWTAuth(jwtMgr), middleware.RequireRoles(
			domain.RoleSuperAdmin, domain.RoleAdmin, domain.RoleStaff,
		))
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
			manage.GET("/users", h.User.List)
			manage.POST("/users", h.User.Create)
			manage.GET("/users/stats", h.User.Stats)
			manage.GET("/users/:id", h.User.Get)
			manage.PUT("/users/:id", h.User.Update)
			manage.PUT("/users/:id/status", h.User.UpdateStatus)
			manage.PUT("/users/:id/password", h.User.SetPassword)
			manage.DELETE("/users/:id", h.User.Delete)

			manage.GET("/roles", h.User.Roles)
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
	}

	return r
}
