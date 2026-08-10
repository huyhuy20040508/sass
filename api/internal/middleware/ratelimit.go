package middleware

import (
	"strconv"
	"sync"
	"time"

	"github.com/gin-gonic/gin"

	"sass-api/pkg/logger"
	"sass-api/pkg/response"

	"go.uber.org/zap"
)

// RateLimit giới hạn số lượt gọi của MỘT địa chỉ IP tới một nhóm endpoint.
//
// VÌ SAO CẦN: trước khi có lớp này, mọi endpoint công khai đều gọi được không
// giới hạn. Nghĩa là chỉ cần một vòng lặp là dò được mật khẩu ở /auth/login,
// dội mã xác thực vào hộp thư người khác ở /auth/resend-verification, quét sạch
// mã giảm giá ở /vouchers/check, hay dò mã đơn hàng ở /public/orders/lookup.
//
// CÁCH HOẠT ĐỘNG — "gáo token": mỗi khoá (tên nhóm + IP) có một gáo chứa tối đa
// `limit` token, tự đầy lại đều đặn hết một gáo sau mỗi `window`. Mỗi lượt gọi
// tiêu một token; hết token thì trả 429 kèm Retry-After.
//
// Chọn gáo token chứ không phải "đếm theo khung giờ" vì khung giờ cho phép dồn
// gấp đôi hạn mức ngay chỗ giáp ranh (bắn hết hạn mức cuối khung này rồi bắn
// tiếp hết hạn mức đầu khung sau). Gáo token thì trải đều, đồng thời vẫn cho
// khách bấm dồn vài lượt liên tiếp — đúng cái người dùng thật hay làm.
//
// PHẠM VI: bộ đếm nằm trong bộ nhớ của tiến trình. Dự án chạy MỘT tiến trình API
// nên thế là đủ; chạy nhiều bản sao thì phải chuyển sang Redis, nếu không mỗi
// bản sao đếm một phách và hạn mức thật bị nhân lên bấy nhiêu lần.
func RateLimit(name string, limit int, window time.Duration) gin.HandlerFunc {
	if limit <= 0 || window <= 0 {
		// Cấu hình vô nghĩa thì cho đi thẳng còn hơn chặn sạch mọi người.
		return func(c *gin.Context) { c.Next() }
	}

	lim := newLimiter(float64(limit), float64(limit)/window.Seconds(), window)
	retryAfter := strconv.Itoa(int(window.Seconds()))

	return func(c *gin.Context) {
		// ClientIP CHỈ đáng tin khi đã khai trusted proxy (xem router.New):
		// không khai thì gin lấy nguyên giá trị X-Forwarded-For khách tự gửi lên,
		// và kẻ dò mật khẩu chỉ việc đổi header mỗi lượt là hạn mức thành vô nghĩa.
		if lim.allow(name + "|" + c.ClientIP()) {
			c.Next()
			return
		}

		logger.Warn("chặn vì gọi quá dày",
			zap.String("nhom", name),
			zap.String("ip", c.ClientIP()),
			zap.String("path", c.Request.URL.Path),
		)
		c.Header("Retry-After", retryAfter)
		response.Error(c, 429, "Bạn thao tác quá nhanh. Vui lòng thử lại sau ít phút.")
	}
}

// gao là phần token còn lại của một khoá, kèm mốc thời gian đã tính tới.
type gao struct {
	token float64
	moc   time.Time
}

type limiter struct {
	mu    sync.Mutex
	gaos  map[string]*gao
	burst float64       // sức chứa tối đa
	toc   float64       // token đầy thêm mỗi giây
	ttl   time.Duration // gáo im lặng quá lâu thì dọn đi
	quet  time.Time     // lần dọn gần nhất
}

func newLimiter(burst, toc float64, window time.Duration) *limiter {
	// Giữ gáo lâu hơn một window để người vừa bị chặn không được "xoá án" sớm
	// bằng cách ngồi im vài giây rồi bắn tiếp.
	ttl := window * 4
	if ttl < time.Minute {
		ttl = time.Minute
	}
	return &limiter{
		gaos:  make(map[string]*gao),
		burst: burst,
		toc:   toc,
		ttl:   ttl,
		quet:  time.Now(),
	}
}

func (l *limiter) allow(key string) bool {
	now := time.Now()

	l.mu.Lock()
	defer l.mu.Unlock()

	l.donRac(now)

	g, ok := l.gaos[key]
	if !ok {
		// Lượt đầu tiên: gáo đầy, tiêu một token.
		l.gaos[key] = &gao{token: l.burst - 1, moc: now}
		return true
	}

	// Đầy lại theo thời gian đã trôi qua, không vượt sức chứa.
	g.token += now.Sub(g.moc).Seconds() * l.toc
	if g.token > l.burst {
		g.token = l.burst
	}
	g.moc = now

	if g.token < 1 {
		return false
	}
	g.token--
	return true
}

// donRac xoá các gáo đã đầy lại hoàn toàn và im lặng quá lâu.
//
// Dọn ngay trong lúc kiểm tra (thay vì nuôi một goroutine hẹn giờ) để tắt máy
// chủ không phải lo dọn dẹp gì thêm. Chi phí gần như bằng 0: cả phút mới quét
// một lần, và bảng chỉ lớn bằng số IP đang gọi trong vòng ttl.
func (l *limiter) donRac(now time.Time) {
	if now.Sub(l.quet) < l.ttl {
		return
	}
	l.quet = now
	for k, g := range l.gaos {
		if now.Sub(g.moc) > l.ttl {
			delete(l.gaos, k)
		}
	}
}
