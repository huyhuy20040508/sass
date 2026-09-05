// Package middleware chứa các Gin middleware dùng chung.
package middleware

import (
	"errors"
	"strconv"
	"strings"
	"time"

	"github.com/gin-gonic/gin"
	"go.uber.org/zap"

	"sass-api/internal/chinhanh"
	"sass-api/internal/domain"
	"sass-api/internal/tenant"
	"sass-api/pkg/jwt"
	"sass-api/pkg/logger"
	"sass-api/pkg/response"
)

// HeaderChiNhanh là tên header mang chi nhánh đang làm việc.
//
// Đặt tên có tiền tố X- theo lối cũ và giữ chữ tiếng Việt cho khớp với phần còn
// lại của hệ thống: người đọc nhật ký nginx thấy nó là hiểu ngay đang nói về
// cái gì, không phải tra tài liệu.
const HeaderChiNhanh = "X-Chi-Nhanh"

// Khóa lưu trong gin.Context.
const (
	CtxUserID   = "ctx_user_id"
	CtxRole     = "ctx_role"
	CtxTenantID = "ctx_tenant_id"
	// CtxChiNhanhID là chi nhánh đang làm việc của request. Có mặt khi trình
	// duyệt gửi header HeaderChiNhanh và nó tra ra hợp lệ, HOẶC khi người gọi bị
	// phân công về một chi nhánh (lúc đó gắn luôn dù họ không khai gì).
	CtxChiNhanhID = "ctx_chi_nhanh_id"
	// CtxChiNhanhGhim = chi nhánh trên là BẮT BUỘC với người này, không phải một
	// lựa chọn họ đổi được.
	//
	// Cần một cờ riêng vì nhìn vào CtxChiNhanhID không phân biệt được hai trường
	// hợp: chủ tiệm vừa chọn kho 2 ở thanh trên cùng, và nhân viên bị phân công
	// về kho 2. Người đầu được xem cả cửa hàng nếu muốn, người sau thì không —
	// mà chỉ có middleware mới biết ai là ai. Xem chiNhanhLoc.
	CtxChiNhanhGhim = "ctx_chi_nhanh_ghim"
	// CtxPlatformRole là vai trò trong KHU ĐIỀU HÀNH (owner | operator |
	// support), do XacThucNenTang đặt. Khác CtxRole — vai trò trong một cửa hàng.
	CtxPlatformRole = "ctx_platform_role"
	// CtxPlatformApps là TẬP PHẦN MỀM người điều hành được đụng vào
	// (domain.QuyenApp), cũng do XacThucNenTang đặt.
	CtxPlatformApps = "ctx_platform_apps"
	// CtxCuaHangKhoa = true: cửa hàng ĐÃ BỊ KHOÁ (hợp đồng hết hạn) nhưng request
	// này vẫn được đi tiếp, vì nó nằm trong danh sách đường cho phép khi khoá.
	// Handler nào cần nói khác đi trong tình huống đó thì đọc cờ này.
	CtxCuaHangKhoa = "ctx_cua_hang_khoa"
)

// CORS cấu hình Cross-Origin cho các origin được phép.
func CORS(allowOrigins []string) gin.HandlerFunc {
	allowed := make(map[string]bool, len(allowOrigins))
	for _, o := range allowOrigins {
		allowed[o] = true
	}
	return func(c *gin.Context) {
		origin := c.GetHeader("Origin")
		if allowed[origin] {
			c.Header("Access-Control-Allow-Origin", origin)
		}
		c.Header("Access-Control-Allow-Methods", "GET, POST, PUT, PATCH, DELETE, OPTIONS")
		c.Header("Access-Control-Allow-Headers", "Origin, Content-Type, Authorization, Accept")
		c.Header("Access-Control-Allow-Credentials", "true")
		if c.Request.Method == "OPTIONS" {
			c.AbortWithStatus(204)
			return
		}
		c.Next()
	}
}

// RequestLogger ghi log mỗi request bằng Zap.
func RequestLogger() gin.HandlerFunc {
	return func(c *gin.Context) {
		start := time.Now()
		path := c.Request.URL.Path
		c.Next()
		logger.Info("request",
			zap.String("method", c.Request.Method),
			zap.String("path", path),
			zap.Int("status", c.Writer.Status()),
			zap.Duration("latency", time.Since(start)),
			zap.String("ip", c.ClientIP()),
		)
	}
}

// JWTAuth bắt buộc có access token hợp lệ VÀ tài khoản/cửa hàng còn dùng được.
//
// VẾ THỨ HAI LÀ MỘT LƯỢT ĐỌC DATABASE Ở MỖI REQUEST, và đó là chủ ý. Trước đây
// hàm này chỉ kiểm chữ ký, nên xoá một cửa hàng hay khoá nó
// (`status = 'suspended'`) KHÔNG đá được ai đang mở phiên: token tự chứa danh
// tính, không chỗ nào tra lại, và người bị khoá vẫn dùng tiếp cho tới khi access
// token hết hạn. Với JWT_ACCESS_TTL từng bị đặt nhầm thành 268h thì "cho tới khi
// hết hạn" là mười một ngày.
//
// Cùng cách làm với XacThucNenTang của khu điều hành, và cùng lý do được ghi ở
// đó: khoá một người phải có hiệu lực NGAY, không phải chờ token cũ chết.
//
// KHÔNG CACHE. Câu truy vấn là một lượt tra khoá chính kèm một LEFT JOIN cũng
// theo khoá chính — rẻ hơn hầu hết handler mà nó đứng trước. Cache dù chỉ vài
// giây cũng biến "có hiệu lực ngay" thành "có hiệu lực gần như ngay", mà đó
// đúng là thứ vừa phải sửa. Ngày nào đo được nó thành điểm nghẽn thì chỗ thêm
// cache là ở đây, kèm một con số đo được.
//
// phien = nil thì bỏ qua vế thứ hai (chỉ kiểm chữ ký, y như trước). Có mặt để
// bài kiểm thử dựng middleware mà không cần database; ĐỪNG truyền nil ở
// cmd/api.
//
// choPhepKhiKhoa là các ĐƯỜNG (c.FullPath, vd "/api/v1/admin/goi-dich-vu") vẫn
// đi được khi CỬA HÀNG ĐÃ BỊ KHOÁ vì hết hạn hợp đồng. Danh sách này phải luôn
// ngắn và phải là đường CHỈ ĐỌC: nó là ngoại lệ duy nhất của chốt chặn quan
// trọng nhất hệ thống. Lý do nó tồn tại — xem choPhepKhiCuaHangKhoa.
func JWTAuth(mgr *jwt.Manager, phien domain.PhienRepository, choPhepKhiKhoa ...string) gin.HandlerFunc {
	choPhep := make(map[string]bool, len(choPhepKhiKhoa))
	for _, duong := range choPhepKhiKhoa {
		choPhep[duong] = true
	}

	return func(c *gin.Context) {
		token := extractBearer(c)
		if token == "" {
			response.Error(c, 401, "Thiếu access token")
			return
		}
		claims, err := mgr.Parse(token)
		if err != nil {
			response.Error(c, 401, "Token không hợp lệ hoặc đã hết hạn")
			return
		}
		if claims.Type != jwt.AccessToken {
			response.Error(c, 401, "Loại token không hợp lệ")
			return
		}
		if claims.TenantID == 0 {
			// Token cấp trước khi hệ thống có nhiều cửa hàng. Bắt đăng nhập lại thay
			// vì đoán cửa hàng số 1: đoán một lần là mọi token cũ trên đời thành chìa
			// khoá vào cửa hàng đó.
			response.Error(c, 401, "Phiên đăng nhập đã cũ, vui lòng đăng nhập lại")
			return
		}
		if !phienConSong(c, phien, claims, choPhep) {
			return
		}
		applyIdentity(c, claims)
		c.Next()
	}
}

// phienConSong tra lại tài khoản và cửa hàng của token. false = đã trả lời xong.
//
// Lỗi database KHÔNG đá người dùng ra: mất kết nối MySQL một giây mà đăng xuất
// cả hệ thống thì cách chữa còn tệ hơn bệnh. Trả 503 — người dùng thử lại, phiên
// còn nguyên.
//
// Ba câu trả lời khác nhau cho ba tình huống, cố ý không gộp: người bị khoá cửa
// hàng cần gọi cho nhà cung cấp phần mềm, người bị khoá tài khoản cần gọi cho
// quản lý của họ. Một câu "phiên hết hiệu lực" chung chung đẩy cả hai đi hỏi
// nhầm chỗ.
func phienConSong(
	c *gin.Context, phien domain.PhienRepository, claims *jwt.Claims, choPhep map[string]bool,
) bool {
	if phien == nil {
		return true
	}

	tt, err := phien.KiemPhien(c.Request.Context(), claims.UserID, claims.TenantID)
	if err != nil {
		logger.Error("không kiểm được phiên đăng nhập",
			zap.Uint("user_id", claims.UserID), zap.Uint("tenant_id", claims.TenantID), zap.Error(err))
		response.Error(c, 503, "Máy chủ đang bận, vui lòng thử lại")

		return false
	}

	// CẢ BA đều trả 401, không phải 403 — và đó là điểm mấu chốt của cả cơ chế này.
	//
	// 403 nghĩa là "bạn là ai thì đúng rồi, nhưng không được làm việc này", và ứng
	// dụng phía trước xử lý nó bằng cách hiện một câu lỗi rồi để nguyên phiên —
	// đúng như vậy, vì bấm nhầm một nút ngoài quyền hạn thì không đáng bị đăng
	// xuất. Ba trường hợp dưới đây khác hẳn: chính DANH TÍNH trong token đã hết
	// hiệu lực. Trả 403 thì Shop Admin giữ nguyên session và mọi trang chỉ hiện
	// lỗi mà không ai bị đá ra — đúng cái đang phải sửa.
	//
	// Với 401, ApiClient của Shop Admin đi đúng đường đã có sẵn: thử làm mới
	// token; /auth/refresh cũng từ chối (nó tra lại cửa hàng và tài khoản), nên
	// session bị xoá và lượt vào trang tiếp theo rơi về màn hình đăng nhập. Ở đó
	// /auth/shop-login mới là chỗ nói lý do cho đúng lúc — người đọc đang đứng
	// trước ô đăng nhập chứ không phải giữa một trang dở dang.
	switch {
	case !tt.CuaHangHoatDong:
		// Gồm cả cửa hàng ĐÃ BỊ XOÁ: không còn dòng nào thì cũng không còn hoạt
		// động. Không tách hai câu — với người đang ngồi trước màn hình thì "cửa
		// hàng bị khoá" và "cửa hàng không còn" dẫn tới cùng một việc: gọi cho
		// nhà cung cấp.
		return choPhepKhiCuaHangKhoa(c, claims, choPhep)
	case !tt.CoNguoiDung:
		response.Error(c, 401, "Tài khoản không còn tồn tại, vui lòng đăng nhập lại")

		return false
	case !tt.NguoiDungHoatDong:
		response.Error(c, 401, "Tài khoản đang không hoạt động, vui lòng liên hệ cửa hàng")

		return false
	}

	return true
}

// choPhepKhiCuaHangKhoa quyết định một request của CỬA HÀNG ĐÃ BỊ KHOÁ đi được
// tới đâu. true = cho đi tiếp.
//
// VÌ SAO KHÔNG ĐÓNG SẠCH NHƯ TRƯỚC: khoá cửa hàng vì hết hạn hợp đồng mà chặn cả
// đường đọc gói dịch vụ thì người bị khoá không còn chỗ nào biết mình vừa hết
// hạn, hết hạn từ bao giờ, và gia hạn bao nhiêu tiền — họ chỉ thấy màn hình đăng
// nhập từ chối mật khẩu đúng. Cách chữa duy nhất khi đó là gọi điện, mà gọi cho
// ai thì cũng không trang nào nói.
//
// Ngoại lệ này hẹp theo BA chiều, và cả ba đều cần thiết:
//
//   - CHỈ vai trò quản lý (super_admin, admin). Nhân viên bán hàng không gia hạn
//     được gì, giữ họ lại trong một phần mềm đã khoá chỉ là để họ bấm vào từng
//     trang một và nhận lỗi. Họ nhận đúng câu 401 như trước và bị đưa ra màn hình
//     đăng nhập, nơi câu chữ nói rõ phải hỏi ai.
//   - CHỈ những đường trong danh sách cho phép — hôm nay là một đường đọc duy
//     nhất. Mọi đường còn lại bị chặn ngay tại đây, TRƯỚC khi chạm bất kỳ handler
//     nào, nên không có chỗ nào cho một lượt ghi lọt qua.
//   - 403 CHỨ KHÔNG PHẢI 401, kèm mã máy đọc được. 401 nghĩa là "danh tính hết
//     hiệu lực" và Shop Admin xử lý nó bằng cách xoá session rồi đá ra màn hình
//     đăng nhập — đúng cái phải tránh ở đây, vì phiên này vẫn còn dùng được cho
//     trang gói dịch vụ. Mã `CUA_HANG_KHOA` là thứ bên đó rẽ nhánh, thay vì so
//     khớp câu chữ tiếng Việt.
func choPhepKhiCuaHangKhoa(c *gin.Context, claims *jwt.Claims, choPhep map[string]bool) bool {
	if claims.Role != domain.RoleSuperAdmin && claims.Role != domain.RoleAdmin {
		response.Error(c, 401, "Cửa hàng đang tạm khoá, vui lòng liên hệ nhà cung cấp phần mềm")

		return false
	}

	c.Set(CtxCuaHangKhoa, true)
	if choPhep[c.FullPath()] {
		return true
	}

	response.ErrorMa(c, 403, response.MaCuaHangKhoa,
		"Cửa hàng đã hết hạn sử dụng — vui lòng gia hạn để tiếp tục làm việc")

	return false
}

// applyIdentity gắn danh tính vừa xác minh vào cả gin.Context (cho handler đọc)
// lẫn context của request (cho các tầng dưới).
//
// Vế thứ hai mới là vế quan trọng: repository chèn `WHERE tenant_id = ?` bằng
// cách đọc context của request (xem repository/tenant_scope.go), nên handler nào
// gọi service bằng c.Request.Context() thì tự động được lọc, còn handler nào
// dùng ctx khác sẽ HỎNG chứ không lặng lẽ đọc chéo dữ liệu.
func applyIdentity(c *gin.Context, claims *jwt.Claims) {
	c.Set(CtxUserID, claims.UserID)
	c.Set(CtxRole, claims.Role)
	c.Set(CtxTenantID, claims.TenantID)
	c.Request = c.Request.WithContext(tenant.WithID(c.Request.Context(), claims.TenantID))
}

// OptionalJWTAuth đọc token nếu có nhưng KHÔNG chặn khi thiếu/sai.
// Dùng cho endpoint công khai muốn biết thêm "ai đang gọi" — ví dụ đặt hàng:
// khách đã đăng nhập thì gắn đơn vào tài khoản, khách vãng lai vẫn đặt được.
func OptionalJWTAuth(mgr *jwt.Manager) gin.HandlerFunc {
	return func(c *gin.Context) {
		token := extractBearer(c)
		if token == "" {
			c.Next()
			return
		}
		claims, err := mgr.Parse(token)
		// Token hỏng, hoặc token cũ chưa mang mã cửa hàng, thì coi như khách vãng
		// lai. Không nhận nửa vời: lấy danh tính người dùng mà bỏ qua cửa hàng là
		// dựng một phiên không thuộc về đâu cả.
		if err != nil || claims.Type != jwt.AccessToken || claims.TenantID == 0 {
			c.Next()
			return
		}
		applyIdentity(c, claims)
		c.Next()
	}
}

// TenantRequired chặn request chưa xác định được cửa hàng.
//
// Dùng cho các đường CÔNG KHAI có chạm database: ở đó tenant chỉ đến từ token
// nếu người gọi có đăng nhập, mà không có thì mọi câu truy vấn sẽ hỏng tận dưới
// tầng GORM và trả về 500 chẳng nói lên điều gì. Chặn ở đây để câu trả lời đúng
// với sự thật: chưa biết đang hỏi cửa hàng nào.
//
// Đường công khai cho khách VÃNG LAI (storefront) rồi sẽ xác định cửa hàng theo
// TÊN MIỀN của request thay vì theo token — lúc đó chỗ resolve tên miền đặt
// trước middleware này và nó gần như không còn chặn ai nữa.
func TenantRequired() gin.HandlerFunc {
	return func(c *gin.Context) {
		if _, ok := tenant.ID(c.Request.Context()); !ok {
			response.Error(c, 401, "Chưa xác định được cửa hàng cho yêu cầu này")
			return
		}
		c.Next()
	}
}

// TenantFromHost xác định cửa hàng theo TÊN MIỀN của request.
//
// Đây là thứ cho phép KHÁCH VÃNG LAI mua hàng: trước nó, cửa hàng chỉ đến từ
// token, nên người chưa đăng nhập không nói được mình đang đứng ở tiệm nào và cả
// cụm storefront trả 401. Vào tên miền nào thì phục vụ cửa hàng ấy.
//
// Đặt SAU OptionalJWTAuth và TRƯỚC TenantRequired.
//
// Ba nguyên tắc:
//
//  1. TÊN MIỀN THẮNG TOKEN. Cùng một trình duyệt có thể còn token của tiệm A
//     trong khi đang mở trang của tiệm B (mua ở hai tiệm cùng nền tảng là
//     chuyện thường). Để token thắng thì người đó đứng trên trang B mà đọc dữ
//     liệu của A — sai với mọi thứ đang hiện trên màn hình. Nên lệch nhau là
//     TỪ CHỐI, không phải im lặng chọn một bên: client biết đường bỏ token đi
//     và gọi lại như khách vãng lai.
//
//  2. TÊN MIỀN LẠ THÌ KHÔNG ĐỘNG GÌ. Tên miền chưa vào sổ (hôm nay là chính
//     shop.selliotech.store của khu quản trị) đi tiếp y như trước: có token
//     thì dùng token, không thì TenantRequired chặn. Nhờ vậy bật middleware
//     này lên KHÔNG đổi hành vi của bất cứ đường nào đang chạy.
//
//  3. CỬA HÀNG BỊ KHOÁ THÌ ĐÓNG TRANG. Đó chính là ý nghĩa của trạng thái
//     suspended — khách hết hạn hoặc ngừng trả tiền. Trả 403 kèm câu chữ dành
//     cho NGƯỜI MUA chứ không phải cho chủ tiệm: người đọc nó là khách vãng
//     lai, họ không cần biết chuyện tiền nong giữa mình và chủ tiệm.
//
// repo = nil thì middleware thành một bước đi qua — dùng khi chưa dựng control
// plane. Cả cụm storefront lúc đó vẫn chỉ phục vụ người đã đăng nhập.
func TenantFromHost(repo domain.TenantDomainRepository) gin.HandlerFunc {
	if repo == nil {
		return func(c *gin.Context) { c.Next() }
	}

	return func(c *gin.Context) {
		host := c.Request.Host
		shop, err := repo.FindTenantByHost(c.Request.Context(), host)
		switch {
		case errors.Is(err, domain.ErrNotFound):
			c.Next()
			return
		case err != nil:
			// Không đọc được sổ tên miền: KHÔNG rơi về token. Rơi về token nghĩa là
			// lúc control plane trục trặc, một người đang đăng nhập ở tiệm A mở trang
			// tiệm B sẽ được phục vụ bằng dữ liệu của A — đúng cái mà nguyên tắc 1
			// vừa chặn, chỉ khác là xảy ra đúng lúc không ai nhìn.
			logger.Error("không tra được tên miền trong sổ nền tảng",
				zap.String("host", host), zap.Error(err))
			response.Error(c, 503, "Cửa hàng tạm thời không truy cập được, vui lòng thử lại sau ít phút")
			return
		}

		if shop.Status != domain.TenantActive {
			response.Error(c, 403, "Cửa hàng này hiện đang tạm ngừng hoạt động")
			return
		}

		if cu, ok := tenant.ID(c.Request.Context()); ok && cu != shop.ID {
			logger.Warn("token thuộc cửa hàng khác với tên miền đang mở",
				zap.String("host", host),
				zap.Uint("cua_hang_cua_ten_mien", shop.ID),
				zap.Uint("cua_hang_cua_token", cu),
			)
			response.Error(c, 401, "Phiên đăng nhập thuộc cửa hàng khác, vui lòng đăng nhập lại")
			return
		}

		c.Set(CtxTenantID, shop.ID)
		c.Request = c.Request.WithContext(tenant.WithID(c.Request.Context(), shop.ID))
		c.Next()
	}
}

// JWTAuthStream như JWTAuth nhưng chấp nhận thêm token ở query `?token=`.
//
// Chỉ dùng cho endpoint SSE: EventSource của trình duyệt KHÔNG cho đặt header
// Authorization, nên đây là cách duy nhất để kết nối stream mang theo danh tính.
// Đổi lại, token sẽ nằm trong URL (log truy cập, lịch sử duyệt web) — vì vậy
// tuyệt đối không mở kiểu xác thực này cho các endpoint đọc/ghi dữ liệu khác.
func JWTAuthStream(mgr *jwt.Manager) gin.HandlerFunc {
	return func(c *gin.Context) {
		token := extractBearer(c)
		if token == "" {
			token = strings.TrimSpace(c.Query("token"))
		}
		if token == "" {
			response.Error(c, 401, "Thiếu access token")
			return
		}
		claims, err := mgr.Parse(token)
		if err != nil || claims.Type != jwt.AccessToken {
			response.Error(c, 401, "Token không hợp lệ hoặc đã hết hạn")
			return
		}
		if claims.TenantID == 0 {
			response.Error(c, 401, "Phiên đăng nhập đã cũ, vui lòng đăng nhập lại")
			return
		}
		applyIdentity(c, claims)
		c.Next()
	}
}

// RequireRoles chỉ cho phép các vai trò chỉ định (dùng sau JWTAuth).
// Nếu JWTAuth chưa chạy (role rỗng), ghi log warning để dễ debug.
func RequireRoles(roles ...string) gin.HandlerFunc {
	allowed := make(map[string]bool, len(roles))
	for _, r := range roles {
		allowed[r] = true
	}
	return func(c *gin.Context) {
		role := c.GetString(CtxRole)
		if role == "" {
			logger.Warn("require_roles: CtxRole is empty — JWTAuth may not have run",
				zap.String("path", c.Request.URL.Path),
				zap.String("method", c.Request.Method),
			)
		}
		if !allowed[role] {
			response.Error(c, 403, "Bạn không có quyền truy cập tài nguyên này")
			return
		}
		c.Next()
	}
}

// XacThucNenTang là chốt xác thực của KHU ĐIỀU HÀNH. Nó thay hẳn cho bộ
// JWTAuth + RequireRoles, KHÔNG đứng sau chúng.
//
// VÌ SAO KHÔNG DÙNG JWTAuth + RequireRoles(super_admin) — chỗ dễ hiểu nhầm nhất
// của cả tệp: `super_admin` là vai trò cao nhất TRONG MỘT CỬA HÀNG, và cửa hàng
// nào cũng có một người như vậy, chính là chủ shop. Chặn khu điều hành bằng vai
// trò đó nghĩa là chủ của bất kỳ tiệm nào cũng sửa được bảng giá của cả nền
// tảng, gồm cả việc tự bật tên miền riêng cho gói mình đang dùng.
//
// Ở đây token phải là TOKEN NỀN TẢNG (claims.Platform), thứ chỉ
// /auth/platform-login cấp ra sau khi đối chiếu mật khẩu trong sổ
// `platform_users`. Token của cửa hàng không có cờ đó và không có cách nào tự
// gắn vào — sửa một bit trong token là chữ ký hỏng.
//
// Hai chiều loại trừ nhau, và cả hai đều bằng cấu trúc chứ không bằng danh sách
// điều kiện phải nhớ:
//
//   - token cửa hàng ở khu điều hành → chặn tại đây (thiếu cờ);
//   - token nền tảng ở khu cửa hàng  → chặn tại JWTAuth (tenant = 0, điều kiện
//     đã có sẵn từ trước khi có tính năng này).
//
// VAI TRÒ ĐỌC LẠI TỪ SỔ Ở MỖI REQUEST, không lấy từ token, dù token có mang
// sẵn: khoá một người hay hạ vai trò của họ phải có hiệu lực NGAY. Lấy theo
// token thì người vừa bị thu quyền vẫn ghi được cho tới khi token cũ hết hạn —
// và access token sống 15 phút, đủ dài cho một người đang giận.
//
// Một lượt tra cho mỗi request, không đệm: nhóm này vài người dùng, vài lượt
// mỗi ngày, còn cái đệm đổi lấy đúng khoảng trống vừa nói ở trên.
//
// repo = nil nghĩa là chưa dựng control plane. Trả 503 chứ KHÔNG cho đi tiếp:
// một cổng không tra được sổ thì phải đóng.
func XacThucNenTang(mgr *jwt.Manager, repo domain.PlatformUserRepository) gin.HandlerFunc {
	return func(c *gin.Context) {
		if repo == nil {
			response.Error(c, 503, "Khu điều hành chưa sẵn sàng — máy chủ chưa nối được sổ nền tảng")
			return
		}

		token := extractBearer(c)
		if token == "" {
			response.Error(c, 401, "Thiếu access token")
			return
		}
		claims, err := mgr.Parse(token)
		if err != nil {
			response.Error(c, 401, "Token không hợp lệ hoặc đã hết hạn")
			return
		}
		if claims.Type != jwt.AccessToken {
			response.Error(c, 401, "Loại token không hợp lệ")
			return
		}
		if !claims.Platform {
			// Đây chính là lượt chặn quan trọng nhất: token thật, chữ ký thật, người
			// thật — nhưng là người của một CỬA HÀNG. Ghi log vì nó phải điều tra
			// được: hoặc người dùng đang mở nhầm cửa, hoặc ai đó đang thử.
			logger.Warn("token của cửa hàng gọi vào khu điều hành nền tảng",
				zap.Uint("tenant_id", claims.TenantID),
				zap.Uint("user_id", claims.UserID),
				zap.String("path", c.Request.URL.Path),
			)
			response.Error(c, 403, "Token này thuộc một cửa hàng, không vào được khu điều hành nền tảng")
			return
		}

		nguoi, err := repo.FindByID(c.Request.Context(), claims.UserID)
		switch {
		case errors.Is(err, domain.ErrNotFound):
			// Token còn hạn nhưng người đã bị khoá hoặc xoá khỏi sổ.
			logger.Warn("token nền tảng của một tài khoản không còn hiệu lực",
				zap.Uint("platform_user_id", claims.UserID),
				zap.String("path", c.Request.URL.Path),
			)
			response.Error(c, 403, "Tài khoản điều hành này không còn hiệu lực, vui lòng đăng nhập lại")
			return
		case err != nil:
			logger.Error("không tra được sổ người điều hành nền tảng",
				zap.Uint("platform_user_id", claims.UserID), zap.Error(err))
			response.Error(c, 503, "Khu điều hành tạm thời không truy cập được, vui lòng thử lại sau ít phút")
			return
		}

		// Tập phần mềm được giao, đọc cùng lượt với vai trò và vì cùng một lý do:
		// thu một phần mềm khỏi ai đó phải có hiệu lực ngay, không chờ token hết
		// hạn. owner không tốn câu truy vấn nào (xem repo).
		quyen, err := repo.QuyenApp(c.Request.Context(), nguoi)
		if err != nil {
			logger.Error("không đọc được phân công phần mềm của người điều hành",
				zap.Uint("platform_user_id", nguoi.ID), zap.Error(err))
			response.Error(c, 503, "Khu điều hành tạm thời không truy cập được, vui lòng thử lại sau ít phút")
			return
		}

		// KHÔNG gọi applyIdentity: hàm đó rót tenant vào context của request cho bộ
		// lọc GORM dùng, mà người điều hành không thuộc cửa hàng nào. Rót một số
		// vào đó là dựng một lời nói dối ngay tại chốt xác thực.
		c.Set(CtxUserID, nguoi.ID)
		c.Set(CtxPlatformRole, nguoi.Role)
		c.Set(CtxPlatformApps, quyen)
		c.Next()
	}
}

// PlatformRole đọc vai trò khu điều hành mà XacThucNenTang vừa đặt.
//
// Rỗng nghĩa là request KHÔNG đi qua middleware đó — handler phải coi đó là
// không có quyền, chứ đừng coi là chưa xác định.
func PlatformRole(c *gin.Context) string { return c.GetString(CtxPlatformRole) }

// PlatformApps đọc tập phần mềm được giao mà XacThucNenTang vừa đặt.
//
// Request không đi qua middleware đó thì trả về zero value — tức KHÔNG phần mềm
// nào, không phải toàn quyền. Quên gắn middleware phải làm mọi thứ đóng lại,
// không phải mở ra.
func PlatformApps(c *gin.Context) domain.QuyenApp {
	if v, ok := c.Get(CtxPlatformApps); ok {
		if q, ok := v.(domain.QuyenApp); ok {
			return q
		}
	}

	return domain.QuyenApp{}
}

// extractBearer trích xuất token Bearer từ header Authorization.
// Trả về chuỗi rỗng nếu header không hợp lệ hoặc token rỗng.
func extractBearer(c *gin.Context) string {
	h := c.GetHeader("Authorization")
	if h == "" {
		return ""
	}
	parts := strings.SplitN(h, " ", 2)
	if len(parts) == 2 && strings.EqualFold(parts[0], "Bearer") {
		token := strings.TrimSpace(parts[1])
		if token == "" {
			return ""
		}
		return token
	}
	return ""
}

// ChiNhanhDangLam đọc CHI NHÁNH ĐANG LÀM VIỆC từ header và gắn vào ctx.
//
// Vì sao là HEADER chứ không phải một trường trong token: chi nhánh đổi trong
// lúc đang làm (Shop Admin có ô chọn ở thanh trên cùng), mà token thì chỉ đổi
// khi đăng nhập lại. Nhét vào token nghĩa là đổi kho phải đăng xuất — hoặc tệ
// hơn, phải cấp token mới sau mỗi lần bấm.
//
// Vì sao PHẢI TRA SỔ trước khi tin: con số này do trình duyệt gửi lên. Không đối
// chiếu thì chủ tiệm A gõ một id bất kỳ và ghi hàng vào kho của tiệm B — bộ lọc
// tenant không đỡ được, vì nó chỉ canh cột `tenant_id` chứ không biết `shop_id`
// vừa nhận có thuộc cửa hàng đó không. Lượt tra dưới đây chạy bằng ctx đã mang
// tenant, nên chi nhánh của tiệm khác đơn giản là không tra ra.
//
// Header thiếu / rỗng = KHÔNG gắn gì, và request chạy tiếp bình thường. Cửa
// hàng MỘT chi nhánh thì nơi cần chi nhánh tự suy ra được (chỉ có một câu trả
// lời); từ hai chi nhánh trở lên thì đường GHI dừng lại và đòi chọn — xem
// repository.chiNhanhCuaRequest. Không đoán hộ nữa.
//
// BA CHỐT khi header CÓ giá trị, và cả ba đều phải nằm ở đây chứ không phải ở
// giao diện:
//
//  1. Chi nhánh phải THUỘC CỬA HÀNG đang đăng nhập. Không đối chiếu thì chủ tiệm
//     A gõ một id bất kỳ và ghi hàng vào kho của tiệm B — bộ lọc tenant không đỡ
//     được, vì nó chỉ canh cột `tenant_id` chứ không biết `shop_id` vừa nhận có
//     thuộc cửa hàng đó không.
//  2. Chi nhánh phải ĐANG MỞ. Ô chọn trên giao diện chỉ bày chi nhánh đang mở,
//     nhưng đó là phép lịch sự chứ không phải hàng rào: gửi thẳng header là ghi
//     hàng vào một kho đã đóng cửa.
//  3. Người gọi phải ĐƯỢC LÀM ở chi nhánh đó. Nhân viên phân về chi nhánh nào
//     thì bán và nhập hàng ở đó; đổi header sang nơi khác là ghi chứng từ vào
//     chỗ mình không đứng. Chủ tiệm và quản lý không bị chốt này chặn.
//
// Cả ba trả 403 chứ không im lặng bỏ header: im lặng thì request vẫn chạy nhưng
// rơi vào chi nhánh khác, và cái sai đó không lộ ra ở đâu.
func ChiNhanhDangLam(repo domain.ChiNhanhRepository, nhanVien domain.NhanVienRepository) gin.HandlerFunc {
	return func(c *gin.Context) {
		if repo == nil {
			c.Next()

			return
		}

		raw := strings.TrimSpace(c.GetHeader(HeaderChiNhanh))
		if raw == "" {
			// KHÔNG khai chi nhánh, mà người gọi ĐÃ BỊ PHÂN CÔNG một chi nhánh: gắn
			// chi nhánh ấy vào ctx.
			//
			// Bỏ trống ở đây là để hở đúng cái cửa mà mọi chốt chặn phía sau trông
			// vào. Quy ước của các lượt ĐỌC là "không rõ chi nhánh thì không cắt",
			// nên chỉ cần gỡ header đi là nhân viên kho 2 đọc được sổ của kho 1 —
			// và không tầng nào dưới cứu được, vì chúng tin rằng ctx trống nghĩa là
			// người này quản cả cửa hàng.
			if cua := chiNhanhDuocPhan(c, nhanVien); cua != nil && *cua > 0 {
				c.Set(CtxChiNhanhID, *cua)
				c.Set(CtxChiNhanhGhim, true)
				c.Request = c.Request.WithContext(chinhanh.WithID(c.Request.Context(), *cua))
			}

			c.Next()

			return
		}

		id, err := strconv.ParseUint(raw, 10, 64)
		if err != nil || id == 0 {
			response.Error(c, 400, "Mã chi nhánh trên header không hợp lệ")
			c.Abort()

			return
		}

		cn, err := repo.FindByID(c.Request.Context(), uint(id))
		if err != nil {
			// ErrNotFound ở đây nghĩa là chi nhánh không thuộc cửa hàng đang đăng
			// nhập (lượt tra đã lọc theo tenant) — hoặc đã bị xoá.
			response.Error(c, 403, "Chi nhánh không thuộc cửa hàng này")
			c.Abort()

			return
		}

		if !cn.IsActive {
			response.Error(c, 403, "Chi nhánh \""+cn.Name+"\" đã ngừng hoạt động — chọn chi nhánh khác ở thanh trên cùng")
			c.Abort()

			return
		}

		if !duocLamTaiChiNhanh(c, nhanVien, cn.ID) {
			response.Error(c, 403, "Bạn không làm việc tại chi nhánh \""+cn.Name+"\"")
			c.Abort()

			return
		}

		c.Set(CtxChiNhanhID, cn.ID)
		// Người BỊ PHÂN CÔNG thì chi nhánh này là bắt buộc, không phải một lựa
		// chọn — kể cả khi họ tự khai đúng nó. chiNhanhLoc đọc cờ này để không
		// cho tham số `shop_id` trên URL kéo họ sang kho khác.
		if cua := chiNhanhDuocPhan(c, nhanVien); cua != nil && *cua > 0 {
			c.Set(CtxChiNhanhGhim, true)
		}
		c.Request = c.Request.WithContext(chinhanh.WithID(c.Request.Context(), cn.ID))
		c.Next()
	}
}

// duocLamTaiChiNhanh cho biết người đang gọi có được làm việc ở chi nhánh này
// không.
//
// Luật: ai KHÔNG bị phân công thì đi đâu cũng được; ai ĐÃ bị phân công thì chỉ ở
// đúng chỗ mình. Cụ thể là "được" khi:
//   - không tra được sổ nhân sự (repo nil) — chốt này chưa dựng được thì không
//     khoá cửa ai cả, sai chiều đó rẻ hơn hẳn chiều kia;
//   - vai trò KHÔNG phải nhân viên (chủ tiệm, quản lý) — họ quản cả cửa hàng;
//   - có vai trò nhân viên nhưng hồ sơ chưa khai chi nhánh (tiệm một điểm bán);
//   - chi nhánh khai trên header đúng bằng chi nhánh được phân.
//
// Lỗi khi đọc sổ cũng trả true: một trục trặc database không được phép biến
// thành "không ai bán hàng được nữa".
func duocLamTaiChiNhanh(c *gin.Context, nhanVien domain.NhanVienRepository, shopID uint) bool {
	cua := chiNhanhDuocPhan(c, nhanVien)

	return cua == nil || *cua == shopID
}

// chiNhanhDuocPhan trả chi nhánh mà người đang gọi BỊ BUỘC vào, hoặc nil khi họ
// đi đâu cũng được (chủ tiệm, quản lý, nhân viên chưa phân công).
//
// Trả nil ở MỌI nhánh "không biết": sổ nhân sự chưa dựng, không đọc được tài
// khoản, database trục trặc. Một trục trặc không được phép biến thành "không ai
// bán hàng được nữa" — nhưng cũng vì thế nil KHÔNG phải bằng chứng rằng người
// này được tự do đi lại, nơi gọi đừng dùng nó để phân quyền.
func chiNhanhDuocPhan(c *gin.Context, nhanVien domain.NhanVienRepository) *uint {
	if nhanVien == nil {
		return nil
	}
	if vaiTro, _ := c.Get(CtxRole); vaiTro != domain.RoleStaff {
		return nil
	}

	userID, ok := c.Get(CtxUserID)
	if !ok {
		return nil
	}
	id, ok := userID.(uint)
	if !ok || id == 0 {
		return nil
	}

	cua, err := nhanVien.ChiNhanhCuaTaiKhoan(c.Request.Context(), id)
	if err != nil {
		logger.Warn("không đọc được chi nhánh của tài khoản — cho qua lượt này",
			zap.Uint("user_id", id), zap.Error(err))

		return nil
	}

	return cua
}
