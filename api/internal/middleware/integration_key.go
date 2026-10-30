package middleware

import (
	"errors"
	"strings"

	"github.com/gin-gonic/gin"
	"go.uber.org/zap"

	"sass-api/internal/domain"
	"sass-api/internal/tenant"
	"sass-api/pkg/logger"
	"sass-api/pkg/response"
)

// HeaderKhoaTichHop mang khoá tích hợp của một hệ thống gọi thay cửa hàng.
const HeaderKhoaTichHop = "X-Integration-Key"

// CtxKhoaTichHop = id khoá tích hợp đã xác minh — có nghĩa là lượt gọi này đến
// từ một HỆ THỐNG (web bán hàng), không phải từ một người.
const CtxKhoaTichHop = "ctx_khoa_tich_hop"

// TenantFromIntegrationKey xác định cửa hàng theo KHOÁ TÍCH HỢP.
//
// Web bán hàng gọi API từ máy chủ của nó, thay một cửa hàng, lúc không ai đăng
// nhập (khách xem hàng, khách đặt đơn). Nó không có token người dùng, và tên
// miền của lượt gọi là 127.0.0.1 chứ không phải tên miền của tiệm — nên hai
// cách xác định cửa hàng có sẵn đều không dùng được.
//
// Đặt SAU OptionalJWTAuth và TRƯỚC TenantFromHost:
//
//   - Không có header thì không động gì: mọi đường đang chạy giữ nguyên.
//   - Có cả khoá lẫn token người dùng thì TỪ CHỐI. Khoá là danh tính của một
//     hệ thống, token là danh tính của một người; nhận cả hai là không biết
//     lượt gọi này nhân danh ai, và không cần thiết — web không bao giờ gửi cả hai.
//   - Khoá sai / đã thu hồi → 401. Cửa hàng bị khoá (hết hạn) → 403: web bán
//     hàng của khách hết hạn phải đóng theo, như mọi cửa vào khác.
//
// TenantFromHost phía sau vẫn đối chiếu: nếu sau này lượt gọi đi qua tên miền
// của một tiệm khác với tiệm của khoá, nó từ chối như với token.
func TenantFromIntegrationKey(repo domain.IntegrationKeyRepository) gin.HandlerFunc {
	if repo == nil {
		return func(c *gin.Context) { c.Next() }
	}

	return func(c *gin.Context) {
		khoa := strings.TrimSpace(c.GetHeader(HeaderKhoaTichHop))
		if khoa == "" {
			c.Next()
			return
		}
		if _, ok := tenant.ID(c.Request.Context()); ok {
			response.Error(c, 401, "Không gửi cùng lúc khoá tích hợp và token đăng nhập")
			return
		}

		chu, err := repo.TheoBam(c.Request.Context(), domain.BamKhoaTichHop(khoa))
		switch {
		case errors.Is(err, domain.ErrNotFound):
			response.Error(c, 401, "Khoá tích hợp không hợp lệ hoặc đã bị thu hồi")
			return
		case err != nil:
			logger.Error("không tra được khoá tích hợp", zap.Error(err))
			response.Error(c, 503, "Tạm thời không xác minh được khoá tích hợp, vui lòng thử lại")
			return
		}
		if chu.TenantStatus != domain.TenantActive {
			response.Error(c, 403, "Cửa hàng đang tạm khoá, vui lòng liên hệ nhà cung cấp phần mềm")
			return
		}

		c.Set(CtxTenantID, chu.TenantID)
		c.Set(CtxKhoaTichHop, chu.KeyID)
		c.Request = c.Request.WithContext(tenant.WithID(c.Request.Context(), chu.TenantID))
		// Chỉ để chủ tiệm thấy "lần dùng gần nhất"; ghi hỏng không chặn lượt gọi.
		if err := repo.GhiLanDung(c.Request.Context(), chu.KeyID); err != nil {
			logger.Warn("không ghi được lần dùng khoá tích hợp", zap.Uint("khoa", chu.KeyID), zap.Error(err))
		}
		c.Next()
	}
}

// YeuCauKhoaTichHop đóng đường với mọi lượt gọi KHÔNG mang khoá tích hợp.
//
// Dùng cho cụm /web-ban: những đường này tạo ra dữ liệu thật trong cửa hàng
// (hồ sơ khách) mà không có ai đăng nhập, nên chỉ hệ thống đã được chủ tiệm cấp
// khoá mới gọi được. Khách vãng lai mở thẳng URL thì nhận 401, không phải một
// lượt tạo khách vô danh.
//
// Đặt SAU TenantFromIntegrationKey: nó đọc dấu mà middleware kia để lại.
func YeuCauKhoaTichHop() gin.HandlerFunc {
	return func(c *gin.Context) {
		if _, co := c.Get(CtxKhoaTichHop); !co {
			response.Error(c, 401, "Đường này chỉ dành cho hệ thống đã được cấp khoá tích hợp")
			return
		}
		c.Next()
	}
}
