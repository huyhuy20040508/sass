// Package middleware chứa các Gin middleware dùng chung.
package middleware

import (
	"strings"
	"time"

	"github.com/gin-gonic/gin"
	"go.uber.org/zap"

	"sass-api/pkg/jwt"
	"sass-api/pkg/logger"
	"sass-api/pkg/response"
)

// Khóa lưu trong gin.Context.
const (
	CtxUserID = "ctx_user_id"
	CtxRole   = "ctx_role"
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
		c.Set(CtxUserID, claims.UserID)
		c.Set(CtxRole, claims.Role)
		c.Next()
	}
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
		if err != nil || claims.Type != jwt.AccessToken {
			c.Next() // token hỏng thì coi như khách vãng lai
			return
		}
		c.Set(CtxUserID, claims.UserID)
		c.Set(CtxRole, claims.Role)
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
		c.Set(CtxUserID, claims.UserID)
		c.Set(CtxRole, claims.Role)
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
