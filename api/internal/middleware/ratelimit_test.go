package middleware

import (
	"net/http"
	"net/http/httptest"
	"testing"
	"time"

	"github.com/gin-gonic/gin"
)

func init() { gin.SetMode(gin.TestMode) }

// goi bắn một lượt vào engine từ một địa chỉ IP cho trước, trả về mã HTTP.
func goi(r *gin.Engine, ip string) int {
	req := httptest.NewRequest(http.MethodPost, "/thu", nil)
	req.RemoteAddr = ip + ":12345"
	w := httptest.NewRecorder()
	r.ServeHTTP(w, req)
	return w.Code
}

func dungEngine(limit int, window time.Duration) *gin.Engine {
	r := gin.New()
	// Không tin proxy nào: RemoteAddr của test chính là địa chỉ khách.
	_ = r.SetTrustedProxies(nil)
	r.POST("/thu", RateLimit("thu", limit, window), func(c *gin.Context) {
		c.String(http.StatusOK, "ok")
	})
	return r
}

func TestChanKhiVuotHanMuc(t *testing.T) {
	r := dungEngine(3, time.Minute)

	for i := 1; i <= 3; i++ {
		if code := goi(r, "1.2.3.4"); code != http.StatusOK {
			t.Fatalf("lượt %d phải được đi qua, nhận %d", i, code)
		}
	}
	if code := goi(r, "1.2.3.4"); code != http.StatusTooManyRequests {
		t.Errorf("lượt thứ 4 phải bị chặn, nhận %d", code)
	}
}

// Chặn IP này KHÔNG được làm phiền IP khác — hạn mức tính riêng từng địa chỉ.
func TestHanMucTinhRiengTungIP(t *testing.T) {
	r := dungEngine(2, time.Minute)

	goi(r, "1.1.1.1")
	goi(r, "1.1.1.1")
	if code := goi(r, "1.1.1.1"); code != http.StatusTooManyRequests {
		t.Fatalf("IP đầu phải bị chặn, nhận %d", code)
	}
	if code := goi(r, "2.2.2.2"); code != http.StatusOK {
		t.Errorf("IP khác bị vạ lây, nhận %d", code)
	}
}

// Hết cửa sổ thì token đầy lại, khách gõ sai mật khẩu vài lần rồi đi pha cà phê
// quay lại vẫn phải đăng nhập được.
func TestTokenDayLaiTheoThoiGian(t *testing.T) {
	// Cửa sổ 200ms: 2 lượt / 200ms → mỗi 100ms đầy thêm một token.
	r := dungEngine(2, 200*time.Millisecond)

	goi(r, "3.3.3.3")
	goi(r, "3.3.3.3")
	if code := goi(r, "3.3.3.3"); code != http.StatusTooManyRequests {
		t.Fatalf("phải bị chặn khi vừa hết token, nhận %d", code)
	}

	time.Sleep(150 * time.Millisecond)
	if code := goi(r, "3.3.3.3"); code != http.StatusOK {
		t.Errorf("chờ đủ lâu rồi vẫn bị chặn, nhận %d", code)
	}
}

// Bị chặn thì phải nói rõ chờ bao lâu, không để khách đoán mò.
func TestTraVeRetryAfter(t *testing.T) {
	r := dungEngine(1, 90*time.Second)

	goi(r, "4.4.4.4")

	req := httptest.NewRequest(http.MethodPost, "/thu", nil)
	req.RemoteAddr = "4.4.4.4:12345"
	w := httptest.NewRecorder()
	r.ServeHTTP(w, req)

	if w.Code != http.StatusTooManyRequests {
		t.Fatalf("phải bị chặn, nhận %d", w.Code)
	}
	if got := w.Header().Get("Retry-After"); got != "90" {
		t.Errorf("Retry-After = %q, mong đợi \"90\"", got)
	}
}

// Hai nhóm khác tên có hai gáo riêng: xin gửi lại mã nhiều lần không được làm
// khách hết luôn lượt đăng nhập.
func TestMoiNhomMotGaoRieng(t *testing.T) {
	r := gin.New()
	_ = r.SetTrustedProxies(nil)
	r.POST("/mot", RateLimit("mot", 1, time.Minute), func(c *gin.Context) { c.String(http.StatusOK, "ok") })
	r.POST("/hai", RateLimit("hai", 1, time.Minute), func(c *gin.Context) { c.String(http.StatusOK, "ok") })

	ban := func(path string) int {
		req := httptest.NewRequest(http.MethodPost, path, nil)
		req.RemoteAddr = "5.5.5.5:12345"
		w := httptest.NewRecorder()
		r.ServeHTTP(w, req)
		return w.Code
	}

	ban("/mot")
	if code := ban("/mot"); code != http.StatusTooManyRequests {
		t.Fatalf("nhóm mot phải hết lượt, nhận %d", code)
	}
	if code := ban("/hai"); code != http.StatusOK {
		t.Errorf("nhóm hai bị vạ lây từ nhóm mot, nhận %d", code)
	}
}

// Cấu hình vô nghĩa (0 lượt) thì cho đi thẳng, không được chặn sạch mọi người.
func TestHanMucKhongHopLeThiKhongChan(t *testing.T) {
	r := dungEngine(0, time.Minute)

	for i := 0; i < 5; i++ {
		if code := goi(r, "6.6.6.6"); code != http.StatusOK {
			t.Fatalf("lượt %d bị chặn dù hạn mức không hợp lệ, nhận %d", i+1, code)
		}
	}
}

// Khách KHÔNG được tự khai địa chỉ của mình qua X-Forwarded-For để né hạn mức.
// Đây chính là lý do router phải gọi SetTrustedProxies.
func TestKhongNeDuocBangXForwardedFor(t *testing.T) {
	r := dungEngine(2, time.Minute)

	ban := func(gia string) int {
		req := httptest.NewRequest(http.MethodPost, "/thu", nil)
		req.RemoteAddr = "7.7.7.7:12345"
		req.Header.Set("X-Forwarded-For", gia)
		w := httptest.NewRecorder()
		r.ServeHTTP(w, req)
		return w.Code
	}

	ban("9.9.9.1")
	ban("9.9.9.2")
	if code := ban("9.9.9.3"); code != http.StatusTooManyRequests {
		t.Errorf("đổi X-Forwarded-For mỗi lượt là né được hạn mức, nhận %d", code)
	}
}
