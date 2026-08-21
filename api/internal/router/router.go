// Package router khai báo toàn bộ route và gắn middleware.
package router

import (
	"net/http"
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
	// ETax — kết nối cổng hoá đơn điện tử của từng chi nhánh.
	ETax *handler.EtaxHandler
	// NhanSu là HỒ SƠ người đi làm (bảng `employees`), đứng cạnh User chứ không
	// thay: nhân viên không có tài khoản đăng nhập vẫn có hồ sơ ở đây.
	NhanSu *handler.NhanSuHandler
	// NhomQuyen — phân quyền theo chức năng: cây quyền, nhóm quyền của cửa hàng,
	// và việc gán nhóm cho từng tài khoản.
	NhomQuyen *handler.NhomQuyenHandler
	// QuyTacMa — quy tắc đánh số chứng từ (Cài đặt → Thông số chung).
	QuyTacMa *handler.QuyTacMaHandler
	// ThuocTinh — thuộc tính và giá trị của nó (Hàng hóa → Thuộc tính).
	ThuocTinh *handler.ThuocTinhHandler
	// DonViTinh — đơn vị tính (Hàng hóa → Đơn vị).
	DonViTinh *handler.DonViTinhHandler
	// ViTri — chỗ để hàng trong cửa hàng/kho (Hàng hóa → Vị trí).
	ViTri *handler.ViTriHandler
	// Thue — thuế suất (Hàng hóa → Thuế).
	Thue *handler.ThueHandler
	// Ca là ca làm việc + sổ quỹ tiền mặt — cụm trả lời câu hỏi cuối ngày: tiền
	// trong két có khớp sổ không, và nếu lệch thì lệch trong lượt trực của ai.
	Ca      *handler.CaLamViecHandler
	Payment *handler.PaymentHandler
	Banner  *handler.BannerHandler
	Report  *handler.ReportHandler
	Promo   *handler.PromotionHandler
	Voucher *handler.VoucherHandler
	Contact *handler.ContactHandler
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
	// quyenRepo đọc NHÓM QUYỀN của người gọi. Mỗi đường của khu quản trị khai
	// một chuỗi quyền (xem q.Dat bên dưới) và chốt này tra nó.
	//
	// nil = không tra được quyền của ai, nên mọi đường có gắn quyền đều đóng.
	// ĐỪNG truyền nil ở cmd/api.
	quyenRepo domain.QuyenRepository,
	h Handlers,
) *gin.Engine {
	// q gắn PHÂN QUYỀN THEO CHỨC NĂNG cho từng đường của khu quản trị, và giữ sổ
	// những đường đã gắn. Cái sổ là thứ để bài kiểm khẳng định không đường nào bị
	// bỏ quên — xem QuyenTheoDuong ở cuối tệp.
	q := middleware.NewKiemQuyen(quyenRepo)

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
		//   - admin : super_admin, admin, staff — MÀN HÌNH QUẦY và chỉ nó: bán tại
		//     quầy, đơn hàng, ca làm việc & sổ quỹ, hồ sơ của chính mình.
		//   - manage: super_admin, admin — toàn bộ phần còn lại.
		//
		// Nhân viên (staff) ở đây là THU NGÂN: họ bán hàng, tra lại đơn, mở/đóng ca
		// và đối chiếu két. Hàng hoá, giá bán, khuyến mãi, kho, mua vào, hồ sơ khách
		// và cấu hình đều KHÔNG mở — đó là việc của chủ tiệm, và phần lớn chúng vừa
		// phơi giá vốn vừa đổi được số tiền cửa hàng thu về.
		//
		// Đây là chặn theo NHÓM ENDPOINT, chưa phải phân quyền theo từng chức năng —
		// muốn chi tiết hơn thì phải dựng bảng permissions.
		//
		// LƯU Ý KHI THÊM ROUTE MỚI: dấu ngoặc { } bên dưới chỉ để mắt nhìn, nó KHÔNG
		// tạo phạm vi quyền nào. Quyền nằm ở chỗ chọn `admin.` hay `manage.` trên
		// từng dòng, nên mặc định của một đường mới là `manage.` — chỉ hạ xuống
		// `admin.` khi người đứng quầy thật sự cần bấm nó.
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

		// manage — khu quản trị. RequireRoles chặn theo LOẠI tài khoản (khách hàng
		// và vai staff không vào), q.Cua chặn theo CỬA ĐÃ GIAO: chủ tiệm bỏ tích
		// "Quản lý" của một người thì người đó mất khu này ngay, dù vai trò của họ
		// dưới `users.role_id` vẫn là admin. Hai lượt kiểm khác câu hỏi nên giữ cả
		// hai — xem migration 0015.
		manage := admin.Group("")
		manage.Use(
			middleware.RequireRoles(domain.RoleSuperAdmin, domain.RoleAdmin),
			q.Cua(domain.CuaQuanLy),
		)

		// quay — những lượt bấm CHỈ có nghĩa khi đang đứng ở quầy: thu tiền, quét
		// mã, mở/đóng ca, ghi sổ quỹ. Đòi cửa "Thu ngân".
		//
		// CỐ Ý không gồm mấy đường ĐỌC ca làm việc và đơn hàng: chủ tiệm không
		// đứng quầy vẫn phải soi lại được két và đơn đã bán. Đọc không phải là
		// đứng quầy.
		quay := admin.Group("")
		quay.Use(q.Cua(domain.CuaThuNgan))
		{
			// Hồ sơ của CHÍNH người đang đăng nhập — nằm ở nhóm `admin` chứ không
			// phải `manage`: nhân viên không vào được /admin/users, nếu đường này
			// cũng đóng thì họ không có cách nào tự đổi mật khẩu, phải nhờ quản trị
			// viên đặt hộ rồi đọc mật khẩu qua tin nhắn.
			admin.GET("/me", h.User.Me)
			admin.PUT("/me", h.User.UpdateMe)
			admin.PUT("/me/password", h.User.ChangePassword)

			// Danh mục & thương hiệu — khung phân loại của cả kho hàng. Người
			// đứng quầy không dựng khung đó; họ bán những gì đã có trong đó.
			q.Dat(manage, http.MethodPost, "/categories", "danh-muc.them", h.Category.Create)
			q.Dat(manage, http.MethodPut, "/categories/:id", "danh-muc.sua", h.Category.Update)
			q.Dat(manage, http.MethodDelete, "/categories/:id", "danh-muc.xoa", h.Category.Delete)

			// Thuộc tính — bảng tra hai tầng (thuộc tính + giá trị của nó) để khai
			// biến thể mặt hàng. Cùng tầng với danh mục: đây là khung phân loại,
			// không phải việc hằng ngày của quầy.
			q.Dat(manage, http.MethodGet, "/thuoc-tinh", "thuoc-tinh.xem", h.ThuocTinh.List)
			q.Dat(manage, http.MethodPost, "/thuoc-tinh", "thuoc-tinh.them", h.ThuocTinh.Create)
			q.Dat(manage, http.MethodGet, "/thuoc-tinh/:id", "thuoc-tinh.xem", h.ThuocTinh.Get)
			q.Dat(manage, http.MethodPut, "/thuoc-tinh/:id", "thuoc-tinh.sua", h.ThuocTinh.Update)
			q.Dat(manage, http.MethodPut, "/thuoc-tinh/:id/trang-thai", "thuoc-tinh.sua", h.ThuocTinh.DoiTrangThai)
			q.Dat(manage, http.MethodDelete, "/thuoc-tinh/:id", "thuoc-tinh.xoa", h.ThuocTinh.Delete)

			// Đơn vị tính — bảng tra gắn cho mặt hàng. Cùng tầng với danh mục:
			// đây là khung phân loại, không phải việc hằng ngày của quầy.
			q.Dat(manage, http.MethodGet, "/don-vi-tinh", "don-vi-tinh.xem", h.DonViTinh.List)
			q.Dat(manage, http.MethodPost, "/don-vi-tinh", "don-vi-tinh.them", h.DonViTinh.Create)
			q.Dat(manage, http.MethodGet, "/don-vi-tinh/:id", "don-vi-tinh.xem", h.DonViTinh.Get)
			q.Dat(manage, http.MethodPut, "/don-vi-tinh/:id", "don-vi-tinh.sua", h.DonViTinh.Update)
			q.Dat(manage, http.MethodPut, "/don-vi-tinh/:id/trang-thai", "don-vi-tinh.sua", h.DonViTinh.DoiTrangThai)
			q.Dat(manage, http.MethodDelete, "/don-vi-tinh/:id", "don-vi-tinh.xoa", h.DonViTinh.Delete)

			// Vị trí — chỗ để hàng ("Kệ A - Tầng 1", "Kho lạnh"). Cùng khuôn và
			// cùng tầng quyền với đơn vị tính: khung phân loại của mặt hàng.
			q.Dat(manage, http.MethodGet, "/vi-tri", "vi-tri.xem", h.ViTri.List)
			q.Dat(manage, http.MethodPost, "/vi-tri", "vi-tri.them", h.ViTri.Create)
			q.Dat(manage, http.MethodGet, "/vi-tri/:id", "vi-tri.xem", h.ViTri.Get)
			q.Dat(manage, http.MethodPut, "/vi-tri/:id", "vi-tri.sua", h.ViTri.Update)
			q.Dat(manage, http.MethodPut, "/vi-tri/:id/trang-thai", "vi-tri.sua", h.ViTri.DoiTrangThai)
			q.Dat(manage, http.MethodDelete, "/vi-tri/:id", "vi-tri.xoa", h.ViTri.Delete)

			// Thuế suất — bộ mức bày ra ở ô chọn thuế của đơn bán, đơn mua và
			// từng mặt hàng. Cùng tầng với danh mục: đây là khung, không phải
			// việc hằng ngày của quầy.
			q.Dat(manage, http.MethodGet, "/thue", "thue.xem", h.Thue.List)
			q.Dat(manage, http.MethodPut, "/thue/:id", "thue.sua", h.Thue.Update)
			q.Dat(manage, http.MethodPut, "/thue/:id/trang-thai", "thue.sua", h.Thue.DoiTrangThai)

			// Banner trang chủ — nội dung tiếp thị, cùng tầng quyền với sản phẩm.
			// "sort" phải đứng trước /:id, nếu không nó bị hiểu là id banner.
			q.Dat(manage, http.MethodGet, "/banners", "banner.xem", h.Banner.List)
			q.Dat(manage, http.MethodPost, "/banners", "banner.them", h.Banner.Create)
			q.Dat(manage, http.MethodPut, "/banners/sort", "banner.sua", h.Banner.Sort)
			q.Dat(manage, http.MethodGet, "/banners/:id", "banner.xem", h.Banner.Get)
			q.Dat(manage, http.MethodPut, "/banners/:id", "banner.sua", h.Banner.Update)
			q.Dat(manage, http.MethodPut, "/banners/:id/status", "banner.sua", h.Banner.UpdateStatus)
			q.Dat(manage, http.MethodDelete, "/banners/:id", "banner.xoa", h.Banner.Delete)

			// Chương trình khuyến mãi — cùng tầng quyền với sản phẩm vì nó chính là
			// thứ quyết định giá bán. "stats" phải đứng trước /:id, nếu không nó bị
			// hiểu là id chương trình.
			q.Dat(manage, http.MethodGet, "/promotions", "khuyen-mai.xem", h.Promo.List)
			q.Dat(manage, http.MethodGet, "/promotions/stats", "khuyen-mai.xem", h.Promo.Stats)
			q.Dat(manage, http.MethodPost, "/promotions", "khuyen-mai.them", h.Promo.Create)
			q.Dat(manage, http.MethodGet, "/promotions/:id", "khuyen-mai.xem", h.Promo.Get)
			q.Dat(manage, http.MethodPut, "/promotions/:id", "khuyen-mai.sua", h.Promo.Update)
			q.Dat(manage, http.MethodPut, "/promotions/:id/status", "khuyen-mai.sua", h.Promo.UpdateStatus)
			q.Dat(manage, http.MethodDelete, "/promotions/:id", "khuyen-mai.xoa", h.Promo.Delete)

			// Voucher — mã khách tự nhập lúc thanh toán, giảm trên tổng đơn. Cùng
			// tầng quyền với khuyến mãi vì cũng là tiền ra khỏi cửa hàng.
			q.Dat(manage, http.MethodGet, "/vouchers", "ma-giam-gia.xem", h.Voucher.List)
			q.Dat(manage, http.MethodGet, "/vouchers/stats", "ma-giam-gia.xem", h.Voucher.Stats)
			q.Dat(manage, http.MethodPost, "/vouchers", "ma-giam-gia.them", h.Voucher.Create)
			q.Dat(manage, http.MethodGet, "/vouchers/:id", "ma-giam-gia.xem", h.Voucher.Get)
			q.Dat(manage, http.MethodPut, "/vouchers/:id", "ma-giam-gia.sua", h.Voucher.Update)
			q.Dat(manage, http.MethodPut, "/vouchers/:id/status", "ma-giam-gia.sua", h.Voucher.UpdateStatus)
			q.Dat(manage, http.MethodDelete, "/vouchers/:id", "ma-giam-gia.xoa", h.Voucher.Delete)

			// Sản phẩm — nhóm `manage`. Đường ĐỌC của quầy không đi qua đây: màn
			// hình bán tại quầy tra hàng bằng /orders/pos/scan và danh mục công
			// khai GET /products, cả hai đều còn mở cho nhân viên.
			q.Dat(manage, http.MethodPost, "/products", "san-pham.them", h.Product.Create)
			// Xoá hàng loạt đặt TRƯỚC nhóm :id cho dễ đọc — một giao dịch thay vì
			// N lượt gọi nối đuôi nhau từ trang quản trị.
			q.Dat(manage, http.MethodPost, "/products/bulk-delete", "san-pham.xoa", h.Product.BulkDelete)
			q.Dat(manage, http.MethodPost, "/products/:id/duplicate", "san-pham.them", h.Product.Duplicate)
			// GET chi tiết: form sửa nạp lại dữ liệu mới nhất trước khi cho sửa,
			// thay vì làm việc trên bản đã nạp cùng danh sách (có thể đã cũ).
			//
			// Đường này phơi GIÁ VỐN, nên nó theo nhóm `manage` cùng lượt ghi chứ
			// không tách ra làm ngoại lệ chỉ-đọc.
			q.Dat(manage, http.MethodGet, "/products/:id", "san-pham.xem", h.Product.Get)
			q.Dat(manage, http.MethodPut, "/products/:id", "san-pham.sua", h.Product.Update)
			q.Dat(manage, http.MethodPut, "/products/:id/status", "san-pham.sua", h.Product.UpdateStatus)
			// Hai mũi tên lên/xuống trên bảng danh sách — chỉ đổi cột sort.
			q.Dat(manage, http.MethodPut, "/products/:id/sort", "san-pham.sua", h.Product.DoiChoThuTu)
			q.Dat(manage, http.MethodDelete, "/products/:id", "san-pham.xoa", h.Product.Delete)
			// Thẻ hàng hóa — đứng riêng chứ không nằm dưới /products/... vì Gin
			// không cho một nhánh vừa có đoạn tĩnh vừa có tham số :id.
			q.Dat(manage, http.MethodGet, "/the-hang-hoa", "san-pham.xem", h.Product.DanhSachThe)

			// Khách hàng — hồ sơ cá nhân của người mua, chỉ quản trị viên xem được.
			q.Dat(manage, http.MethodGet, "/customers", "khach-hang.xem", h.Customer.List)
			q.Dat(manage, http.MethodGet, "/customers/stats", "khach-hang.xem", h.Customer.Stats)
			q.Dat(manage, http.MethodGet, "/customers/:id", "khach-hang.xem", h.Customer.Get)
			q.Dat(manage, http.MethodPost, "/customers", "khach-hang.them", h.Customer.Create)
			q.Dat(manage, http.MethodPut, "/customers/:id", "khach-hang.sua", h.Customer.Update)
			q.Dat(manage, http.MethodPut, "/customers/:id/status", "khach-hang.sua", h.Customer.UpdateStatus)
			q.Dat(manage, http.MethodPut, "/customers/:id/password", "khach-hang.sua", h.Customer.SetPassword)
			q.Dat(manage, http.MethodDelete, "/customers/:id", "khach-hang.xoa", h.Customer.Delete)

			// Đơn hàng — cùng với Bán tại quầy và Ca làm việc, đây là phần CÒN LẠI
			// ở nhóm `admin`: người trực quầy phải tra lại đơn vừa bán, in lại hoá
			// đơn và sửa ghi chú giao hàng ngay tại chỗ.
			q.Dat(admin, http.MethodGet, "/orders", "don-hang.xem", h.Order.List)
			q.Dat(admin, http.MethodPost, "/orders", "don-hang.them", h.Order.Create)
			// Bán tại quầy — nằm ở nhóm `admin` chứ không phải `manage`: người đứng
			// quầy là nhân viên, và cả tính năng vô nghĩa nếu chỉ chủ cửa hàng bấm
			// được nút thu tiền.
			q.Dat(quay, http.MethodPost, "/orders/pos", "don-hang.them", h.Order.POSCheckout)
			// Quét mã và hạn mức giảm giá: hai đường màn hình quầy hỏi liên tục
			// trong lúc bán, nên đứng cạnh chính đường bán.
			q.Dat(quay, http.MethodGet, "/orders/pos/scan", "don-hang.xem", h.Order.POSScan)
			q.Dat(quay, http.MethodGet, "/orders/pos/discount-limit", "don-hang.xem", h.Order.POSDiscountLimit)
			// Đổi hàng: nhận hàng cũ + bán hàng mới + ghi chênh lệch, một giao dịch.
			q.Dat(quay, http.MethodPost, "/orders/pos/doi-hang", "don-hang.doi-hang", h.Order.POSDoiHang)
			q.Dat(admin, http.MethodGet, "/orders/stats", "don-hang.xem", h.Order.Stats)
			q.Dat(admin, http.MethodGet, "/orders/revenue", "don-hang.doanh-thu", h.Order.Revenue)
			q.Dat(admin, http.MethodGet, "/orders/:id", "don-hang.xem", h.Order.Get)
			q.Dat(admin, http.MethodPut, "/orders/:id", "don-hang.sua", h.Order.Update)
			q.Dat(admin, http.MethodPut, "/orders/:id/status", "don-hang.sua", h.Order.UpdateStatus)
			q.Dat(admin, http.MethodPut, "/orders/:id/payment", "don-hang.sua", h.Order.UpdatePayment)
			q.Dat(admin, http.MethodPut, "/orders/:id/shipping", "don-hang.sua", h.Order.UpdateShipping)
			q.Dat(admin, http.MethodPut, "/orders/:id/note", "don-hang.sua", h.Order.UpdateNote)

			// Hoá đơn điện tử của một đơn. XEM đi cùng quyền xem đơn (nhân viên
			// quầy cần biết đơn đã có hoá đơn chưa để khỏi xuất hai lần), còn PHÁT
			// HÀNH thì ở `manage`: nó ghi doanh thu với cơ quan thuế.
			q.Dat(admin, http.MethodGet, "/orders/:id/etax", "don-hang.xem", h.ETax.HoaDon)
			q.Dat(manage, http.MethodPost, "/orders/:id/etax", "don-hang.sua", h.ETax.PhatHanh)
			// Món còn trả được của một đơn — màn hình lập phiếu trả dựng form từ đây.
			// Đi theo nhóm `manage` cùng chính trang Trả hàng ngay dưới: nhân viên
			// không lập được phiếu thì cũng không cần cái form đó.
			q.Dat(manage, http.MethodGet, "/orders/:id/returnable", "tra-hang.xem", h.Return.Returnable)

			// Ca làm việc & sổ quỹ — nhóm `admin` chứ không phải `manage`: người
			// trực két là nhân viên, và cả cụm vô nghĩa nếu chỉ chủ mới mở/đóng ca
			// được. Chủ vẫn xem được toàn bộ lịch sử qua cùng những đường này.
			//
			// /hien-tai, /mo, /dong đứng TRƯỚC /:id để không bị hiểu là một id.
			q.Dat(admin, http.MethodGet, "/ca-lam-viec/hien-tai", "ca-lam-viec.xem", h.Ca.HienTai)
			q.Dat(quay, http.MethodPost, "/ca-lam-viec/mo", "ca-lam-viec.them", h.Ca.MoCa)
			q.Dat(quay, http.MethodPost, "/ca-lam-viec/dong", "ca-lam-viec.them", h.Ca.DongCa)
			q.Dat(admin, http.MethodGet, "/ca-lam-viec", "ca-lam-viec.xem", h.Ca.List)
			q.Dat(admin, http.MethodGet, "/ca-lam-viec/:id", "ca-lam-viec.xem", h.Ca.Get)
			q.Dat(quay, http.MethodPost, "/so-quy", "so-quy.them", h.Ca.GhiSoQuy)

			// Trả hàng — TIỀN RA khỏi két cho một đơn đã thu. Nhân viên bán được
			// hàng nhưng không tự duyệt được lượt hoàn tiền của chính mình.
			q.Dat(manage, http.MethodGet, "/returns", "tra-hang.xem", h.Return.List)
			q.Dat(manage, http.MethodPost, "/returns", "tra-hang.them", h.Return.Create)
			q.Dat(manage, http.MethodGet, "/returns/stats", "tra-hang.xem", h.Return.Stats)
			q.Dat(manage, http.MethodGet, "/returns/:id", "tra-hang.xem", h.Return.Get)
			q.Dat(manage, http.MethodPut, "/returns/:id/status", "tra-hang.sua", h.Return.UpdateStatus)
			q.Dat(manage, http.MethodPut, "/returns/:id/settle", "tra-hang.sua", h.Return.Settle)
			q.Dat(manage, http.MethodPut, "/returns/:id/note", "tra-hang.sua", h.Return.UpdateNote)

			// Tồn kho — đơn vị là biến thể sản phẩm, không phải sản phẩm.
			// Hai đường tĩnh phải đứng trước /:id, nếu không "stats" và "adjust"
			// sẽ bị hiểu thành id biến thể.
			//
			// Cả cụm ở `manage`: đây là nơi SỬA THẲNG số tồn và khai giá vốn, tức
			// là chỗ một lượt hàng thiếu biến mất khỏi sổ mà không để lại lượt bán
			// nào. Quầy vẫn trừ kho bình thường — nhưng chỉ qua đường bán hàng.
			q.Dat(manage, http.MethodGet, "/inventory", "ton-kho.xem", h.Stock.List)
			q.Dat(manage, http.MethodGet, "/inventory/stats", "ton-kho.xem", h.Stock.Stats)
			// Tồn tách theo từng chi nhánh — cùng kho, khác câu hỏi: không phải "còn
			// bao nhiêu" mà "số hàng ấy đang nằm ở đâu".
			q.Dat(manage, http.MethodGet, "/inventory/chi-nhanh", "ton-kho.xem", h.Stock.TheoChiNhanh)
			q.Dat(manage, http.MethodPost, "/inventory/adjust", "ton-kho.sua", h.Stock.BulkAdjust)
			q.Dat(manage, http.MethodPut, "/inventory/cost", "ton-kho.gia-von", h.Stock.SetCost)
			q.Dat(manage, http.MethodGet, "/inventory/:id", "ton-kho.xem", h.Stock.Get)
			q.Dat(manage, http.MethodGet, "/inventory/:id/history", "ton-kho.xem", h.Stock.History)
			q.Dat(manage, http.MethodPut, "/inventory/:id", "ton-kho.sua", h.Stock.Adjust)

			// Nhà cung cấp — bên bán hàng cho cửa hàng, dùng cho phiếu đặt hàng nhập.
			q.Dat(manage, http.MethodGet, "/suppliers", "nha-cung-cap.xem", h.Supplier.List)
			q.Dat(manage, http.MethodPost, "/suppliers", "nha-cung-cap.them", h.Supplier.Create)
			q.Dat(manage, http.MethodGet, "/suppliers/:id", "nha-cung-cap.xem", h.Supplier.Get)
			q.Dat(manage, http.MethodPut, "/suppliers/:id", "nha-cung-cap.sua", h.Supplier.Update)
			q.Dat(manage, http.MethodDelete, "/suppliers/:id", "nha-cung-cap.xoa", h.Supplier.Delete)

			// Đặt hàng nhập — chiều MUA VÀO của kho.
			// Hai đường tĩnh ("stats", "variants") phải đứng trước /:id, nếu không
			// chúng sẽ bị hiểu thành id phiếu.
			q.Dat(manage, http.MethodGet, "/purchases", "dat-hang-nhap.xem", h.Purchase.List)
			q.Dat(manage, http.MethodPost, "/purchases", "dat-hang-nhap.them", h.Purchase.Create)
			q.Dat(manage, http.MethodGet, "/purchases/stats", "dat-hang-nhap.xem", h.Purchase.Stats)
			q.Dat(manage, http.MethodGet, "/purchases/variants", "dat-hang-nhap.xem", h.Purchase.SearchVariants)
			q.Dat(manage, http.MethodGet, "/purchases/:id", "dat-hang-nhap.xem", h.Purchase.Get)
			q.Dat(manage, http.MethodPut, "/purchases/:id", "dat-hang-nhap.sua", h.Purchase.Update)
			q.Dat(manage, http.MethodDelete, "/purchases/:id", "dat-hang-nhap.xoa", h.Purchase.Delete)
			q.Dat(manage, http.MethodPut, "/purchases/:id/status", "dat-hang-nhap.sua", h.Purchase.UpdateStatus)
			q.Dat(manage, http.MethodPut, "/purchases/:id/payment", "dat-hang-nhap.sua", h.Purchase.UpdatePayment)
			// Nhận hàng là hành động tạo ra bút toán kho mới mỗi lần gọi (nhận nhiều
			// đợt), nên là POST chứ không phải PUT.
			q.Dat(manage, http.MethodPost, "/purchases/:id/receive", "nhap-kho.them", h.Purchase.Receive)

			// Nhập hàng — CHỈ ĐỌC lại các đợt hàng đã về (dựng từ sổ kho). Việc ghi
			// kho vẫn chỉ có một đường duy nhất là POST /purchases/:id/receive.
			// "stats" phải đứng trước /:code, nếu không nó bị hiểu là mã đợt nhập.
			// Trả hàng nhập — trả hàng lại nhà cung cấp (chiều ngược của nhập hàng).
			// "stats" và "returnable" phải đứng trước /:id để không bị hiểu là id phiếu.
			q.Dat(manage, http.MethodGet, "/purchase-returns", "tra-hang-nhap.xem", h.PReturn.List)
			q.Dat(manage, http.MethodPost, "/purchase-returns", "tra-hang-nhap.them", h.PReturn.Create)
			q.Dat(manage, http.MethodGet, "/purchase-returns/stats", "tra-hang-nhap.xem", h.PReturn.Stats)
			q.Dat(manage, http.MethodGet, "/purchase-returns/returnable/:id", "tra-hang-nhap.xem", h.PReturn.Returnable)
			q.Dat(manage, http.MethodGet, "/purchase-returns/:id", "tra-hang-nhap.xem", h.PReturn.Get)
			q.Dat(manage, http.MethodPut, "/purchase-returns/:id", "tra-hang-nhap.sua", h.PReturn.Update)
			q.Dat(manage, http.MethodPut, "/purchase-returns/:id/status", "tra-hang-nhap.sua", h.PReturn.UpdateStatus)
			q.Dat(manage, http.MethodPut, "/purchase-returns/:id/refund", "tra-hang-nhap.sua", h.PReturn.UpdateRefund)
			q.Dat(manage, http.MethodDelete, "/purchase-returns/:id", "tra-hang-nhap.xoa", h.PReturn.Delete)

			// Cấu hình hệ thống — key-value, ghi nhiều khoá một lần.
			//
			// --- Yêu cầu của khách + danh sách nhận tin ---
			// Hộp thư đến từ storefront: tên, số điện thoại, email của người lạ gửi
			// vào. Cùng loại dữ liệu với trang Khách hàng nên cùng nhóm quyền.
			// "stats" đứng TRƯỚC ":id" để gin không hiểu nhầm nó là một ID.
			q.Dat(manage, http.MethodGet, "/contact-requests", "lien-he.xem", h.Contact.List)
			q.Dat(manage, http.MethodGet, "/contact-requests/stats", "lien-he.xem", h.Contact.Stats)
			q.Dat(manage, http.MethodGet, "/contact-requests/:id", "lien-he.xem", h.Contact.Get)
			q.Dat(manage, http.MethodPut, "/contact-requests/:id/status", "lien-he.sua", h.Contact.UpdateStatus)
			q.Dat(manage, http.MethodDelete, "/contact-requests/:id", "lien-he.xoa", h.Contact.Delete)

			q.Dat(manage, http.MethodGet, "/newsletter", "nhan-tin.xem", h.Contact.Subscribers)
			q.Dat(manage, http.MethodPut, "/newsletter/:id/unsubscribe", "nhan-tin.sua", h.Contact.Unsubscribe)

			// Cấu hình hệ thống — key-value, ghi nhiều khoá một lần.
			//
			// ĐỌC là đường DUY NHẤT ngoài cụm quầy còn mở cho nhân viên, và có lý do
			// cụ thể: màn hình Bán tại quầy đọc tên cửa hàng, địa chỉ, lời cảm ơn ở
			// chân hoá đơn và hạn mức tự bớt giá (`pos_staff_discount_limit`) từ
			// đây. Chặn đọc thì hoá đơn in ra trống phần đầu và quầy không biết mình
			// được bớt tới đâu cho tới lúc bấm bán và bị từ chối.
			//
			// GHI vẫn chỉ quản trị viên.
			admin.GET("/settings", h.Setting.List)
			q.Dat(manage, http.MethodPut, "/settings", "cau-hinh.sua", h.Setting.Update)

			// Tài khoản NỘI BỘ (quản trị & nhân viên) + vai trò.
			// Khách hàng KHÔNG đi đường này — họ có /admin/customers riêng, và
			// service ở đây trả 404 nếu id trỏ vào một khách hàng.
			// "stats" phải đứng trước /:id, nếu không nó bị hiểu là id tài khoản.
			// Chi nhánh — mở/đóng điểm bán. Ở nhóm `manage` (nhân viên KHÔNG vào)
			// cùng mức với Người dùng và Cài đặt: mở thêm một điểm bán ăn thẳng vào
			// hạn mức `max_shops` của hợp đồng, tức là một quyết định có tiền đứng
			// sau chứ không phải việc hằng ngày.
			q.Dat(manage, http.MethodGet, "/chi-nhanh", "chi-nhanh.xem", h.ChiNhanh.List)
			q.Dat(manage, http.MethodPost, "/chi-nhanh", "chi-nhanh.them", h.ChiNhanh.Create)
			q.Dat(manage, http.MethodGet, "/chi-nhanh/:id", "chi-nhanh.xem", h.ChiNhanh.Get)
			q.Dat(manage, http.MethodPut, "/chi-nhanh/:id", "chi-nhanh.sua", h.ChiNhanh.Update)
			q.Dat(manage, http.MethodDelete, "/chi-nhanh/:id", "chi-nhanh.xoa", h.ChiNhanh.Delete)

			// Hoá đơn điện tử của một chi nhánh. Đi theo quyền của chính chi nhánh:
			// ai sửa được điểm bán thì nối được cổng HĐĐT cho nó, và mật khẩu ấy
			// phát hành được hoá đơn đứng tên cửa hàng nên không xuống thấp hơn.
			q.Dat(manage, http.MethodGet, "/chi-nhanh/:id/etax", "chi-nhanh.xem", h.ETax.Get)
			q.Dat(manage, http.MethodPost, "/chi-nhanh/:id/etax", "chi-nhanh.sua", h.ETax.Connect)
			q.Dat(manage, http.MethodPut, "/chi-nhanh/:id/etax", "chi-nhanh.sua", h.ETax.Update)
			q.Dat(manage, http.MethodPost, "/chi-nhanh/:id/etax/sync", "chi-nhanh.sua", h.ETax.Sync)
			q.Dat(manage, http.MethodDelete, "/chi-nhanh/:id/etax", "chi-nhanh.sua", h.ETax.Delete)

			// Quy tắc đánh số chứng từ. Đọc cũng ở `manage`: đây là bộ khung của
			// tiệm, không phải thứ quầy bán cần biết.
			q.Dat(manage, http.MethodGet, "/quy-tac-ma", "quy-tac-ma.xem", h.QuyTacMa.List)
			q.Dat(manage, http.MethodPut, "/quy-tac-ma", "quy-tac-ma.sua", h.QuyTacMa.Update)

			// Nhân sự — HỒ SƠ NGƯỜI ĐI LÀM, khác hẳn /users (tài khoản đăng
			// nhập): một người có thể có hồ sơ mà không có tài khoản. Cùng nhóm
			// `manage` vì hồ sơ mang lương và số căn cước của đồng nghiệp.
			q.Dat(manage, http.MethodGet, "/nhan-su", "nhan-su.xem", h.NhanSu.List)
			q.Dat(manage, http.MethodPost, "/nhan-su", "nhan-su.them", h.NhanSu.Create)
			q.Dat(manage, http.MethodGet, "/nhan-su/:id", "nhan-su.xem", h.NhanSu.Get)
			q.Dat(manage, http.MethodPut, "/nhan-su/:id", "nhan-su.sua", h.NhanSu.Update)
			// Công tắc trạng thái trên bảng danh sách — chỉ đổi một cột.
			q.Dat(manage, http.MethodPut, "/nhan-su/:id/trang-thai", "nhan-su.sua", h.NhanSu.DoiTrangThai)
			q.Dat(manage, http.MethodDelete, "/nhan-su/:id", "nhan-su.xoa", h.NhanSu.Delete)

			// Phân quyền theo chức năng. "danh-muc" phải đứng TRƯỚC /:id, nếu không
			// nó bị hiểu là id nhóm.
			//
			// quyen-cua-toi KHÔNG gắn quyền: ai đăng nhập được cũng phải đọc được
			// quyền của chính mình, nếu không trang quản trị chẳng biết nên vẽ mục
			// menu nào. Nó chỉ trả về đúng tập quyền của người gọi.
			admin.GET("/quyen-cua-toi", h.NhomQuyen.QuyenCuaToi)
			q.Dat(manage, http.MethodGet, "/nhom-quyen/danh-muc", "nhom-quyen.xem", h.NhomQuyen.DanhMuc)
			q.Dat(manage, http.MethodGet, "/nhom-quyen", "nhom-quyen.xem", h.NhomQuyen.List)
			q.Dat(manage, http.MethodPost, "/nhom-quyen", "nhom-quyen.them", h.NhomQuyen.Create)
			q.Dat(manage, http.MethodGet, "/nhom-quyen/:id", "nhom-quyen.xem", h.NhomQuyen.Get)
			q.Dat(manage, http.MethodPut, "/nhom-quyen/:id", "nhom-quyen.sua", h.NhomQuyen.Update)
			q.Dat(manage, http.MethodPut, "/nhom-quyen/:id/quyen", "nhom-quyen.sua", h.NhomQuyen.DatQuyen)
			q.Dat(manage, http.MethodDelete, "/nhom-quyen/:id", "nhom-quyen.xoa", h.NhomQuyen.Delete)

			// Gán nhóm cho một tài khoản: đứng ở cụm tài khoản vì đó là thứ nó sửa.
			q.Dat(manage, http.MethodGet, "/users/:id/quyen", "tai-khoan.xem", h.NhomQuyen.QuyenCuaNguoi)
			q.Dat(manage, http.MethodPut, "/users/:id/quyen", "nhom-quyen.sua", h.NhomQuyen.DatQuyenChoNguoi)

			q.Dat(manage, http.MethodGet, "/users", "tai-khoan.xem", h.User.List)
			q.Dat(manage, http.MethodPost, "/users", "tai-khoan.them", h.User.Create)
			q.Dat(manage, http.MethodGet, "/users/stats", "tai-khoan.xem", h.User.Stats)
			q.Dat(manage, http.MethodGet, "/users/:id", "tai-khoan.xem", h.User.Get)
			q.Dat(manage, http.MethodPut, "/users/:id", "tai-khoan.sua", h.User.Update)
			q.Dat(manage, http.MethodPut, "/users/:id/status", "tai-khoan.sua", h.User.UpdateStatus)
			q.Dat(manage, http.MethodPut, "/users/:id/password", "tai-khoan.sua", h.User.SetPassword)
			q.Dat(manage, http.MethodDelete, "/users/:id", "tai-khoan.xoa", h.User.Delete)

			q.Dat(manage, http.MethodGet, "/roles", "tai-khoan.xem", h.User.Roles)

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
			q.Dat(manage, http.MethodPut, "/roles/:id", "cau-hinh.sua", h.User.UpdateRole)

			q.Dat(manage, http.MethodGet, "/receipts", "nhap-kho.xem", h.Receipt.List)
			q.Dat(manage, http.MethodGet, "/receipts/stats", "nhap-kho.xem", h.Receipt.Stats)
			q.Dat(manage, http.MethodGet, "/receipts/:code", "nhap-kho.xem", h.Receipt.Get)

			// Báo cáo — CHỈ ĐỌC, gộp lại dữ liệu đã có theo khoảng thời gian.
			//
			// Nằm ở nhóm `manage` (nhân viên KHÔNG vào) vì báo cáo phơi ra hai thứ
			// mà các trang nghiệp vụ không phơi: giá vốn / lợi nhuận từng mặt hàng,
			// và bảng chi tiêu kèm thông tin liên hệ của từng khách. Nhân viên đã
			// không mở được trang Khách hàng thì cũng không nên đọc được cùng dữ
			// liệu đó qua đường vòng là báo cáo.
			q.Dat(manage, http.MethodGet, "/reports/revenue", "bao-cao.xem", h.Report.Revenue)
			q.Dat(manage, http.MethodGet, "/reports/orders", "bao-cao.xem", h.Report.Orders)
			q.Dat(manage, http.MethodGet, "/reports/products", "bao-cao.xem", h.Report.Products)
			q.Dat(manage, http.MethodGet, "/reports/customers", "bao-cao.xem", h.Report.Customers)

			// Bắn thông báo thử — công cụ chẩn đoán lúc cài đặt, KHÔNG mở ở
			// production: nó tạo dữ liệu thật trong bảng notifications.
			if !cfg.App.IsProduction() {
				q.Dat(manage, http.MethodPost, "/notifications/test", "cau-hinh.sua", h.Notif.TestPush)
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

	soQuyenDaGan = q.SoDangKy()

	return r
}

// soQuyenDaGan giữ bản đồ "METHOD /đường" -> chuỗi quyền của lượt dựng router
// gần nhất. Bài kiểm đọc nó để đối chiếu với bảng route thật của Gin.
var soQuyenDaGan = map[string]string{}

// QuyenTheoDuong trả bản sao sổ đăng ký quyền.
//
// Có mặt CHỈ để bài kiểm chống-bỏ-sót đọc được: nó so danh sách này với
// r.Routes() và bắt lỗi khi ai đó thêm một đường quản trị mà quên khai quyền.
func QuyenTheoDuong() map[string]string {
	ban := make(map[string]string, len(soQuyenDaGan))
	for d, quyen := range soQuyenDaGan {
		ban[d] = quyen
	}

	return ban
}
