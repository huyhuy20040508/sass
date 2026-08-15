// Package middleware chứa các Gin middleware dùng chung.
package middleware

import (
	"errors"
	"strings"
	"time"

	"github.com/gin-gonic/gin"
	"go.uber.org/zap"

	"sass-api/internal/domain"
	"sass-api/internal/tenant"
	"sass-api/pkg/jwt"
	"sass-api/pkg/logger"
	"sass-api/pkg/response"
)

// Khóa lưu trong gin.Context.
const (
	CtxUserID   = "ctx_user_id"
	CtxRole     = "ctx_role"
	CtxTenantID = "ctx_tenant_id"
	// CtxPlatformRole là vai trò trong KHU ĐIỀU HÀNH (owner | operator |
	// support), do XacThucNenTang đặt. Khác CtxRole — vai trò trong một cửa hàng.
	CtxPlatformRole = "ctx_platform_role"
	// CtxPlatformApps là TẬP PHẦN MỀM người điều hành được đụng vào
	// (domain.QuyenApp), cũng do XacThucNenTang đặt.
	CtxPlatformApps = "ctx_platform_apps"
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
func JWTAuth(mgr *jwt.Manager, phien domain.PhienRepository) gin.HandlerFunc {
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
		if !phienConSong(c, phien, claims) {
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
func phienConSong(c *gin.Context, phien domain.PhienRepository, claims *jwt.Claims) bool {
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
		response.Error(c, 401, "Cửa hàng đang tạm khoá, vui lòng liên hệ nhà cung cấp phần mềm")

		return false
	case !tt.CoNguoiDung:
		response.Error(c, 401, "Tài khoản không còn tồn tại, vui lòng đăng nhập lại")

		return false
	case !tt.NguoiDungHoatDong:
		response.Error(c, 401, "Tài khoản đang không hoạt động, vui lòng liên hệ cửa hàng")

		return false
	}

	return true
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
//     order.selliotech.store của khu quản trị) đi tiếp y như trước: có token
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
