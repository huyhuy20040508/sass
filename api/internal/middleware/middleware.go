// Package middleware chứa các Gin middleware dùng chung.
package middleware

import (
	"strings"
	"time"

	"github.com/gin-gonic/gin"
	"go.uber.org/zap"

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

// JWTAuth bắt buộc có access token hợp lệ.
func JWTAuth(mgr *jwt.Manager) gin.HandlerFunc {
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
		applyIdentity(c, claims)
		c.Next()
	}
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
