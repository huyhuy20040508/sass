package middleware

import (
	"net/http"
	"net/http/httptest"
	"testing"
	"time"

	"github.com/gin-gonic/gin"
	gojwt "github.com/golang-jwt/jwt/v5"

	"sass-api/internal/tenant"
	"sass-api/pkg/jwt"
)

// biMatTest là bí mật ký token dùng chung cho cả tệp.
const biMatTest = "bi-mat-test"

// Middleware xác thực là chỗ DUY NHẤT rót cửa hàng vào ctx cho các đường đã đăng
// nhập. Sót ở đây thì mọi câu truy vấn phía sau hỏng (bộ lọc từ chối ctx không có
// tenant) — nên bài này canh cả hai vế: rót đúng, và từ chối token không nói được
// mình thuộc cửa hàng nào.

func mgrTest() *jwt.Manager {
	return jwt.NewManager(biMatTest, 15*time.Minute, 24*time.Hour)
}

// dungEngineTenant dựng một engine ghi lại tenant mà handler nhìn thấy trong ctx
// của request — đúng ctx mà handler thật truyền xuống service.
func dungEngineTenant(mw gin.HandlerFunc) (*gin.Engine, *uint) {
	thay := new(uint)
	r := gin.New()
	r.GET("/thu", mw, func(c *gin.Context) {
		if id, ok := tenant.ID(c.Request.Context()); ok {
			*thay = id
		}
		c.String(http.StatusOK, "ok")
	})

	return r, thay
}

func goiVoiToken(r *gin.Engine, token string) *httptest.ResponseRecorder {
	req := httptest.NewRequest(http.MethodGet, "/thu", nil)
	if token != "" {
		req.Header.Set("Authorization", "Bearer "+token)
	}
	w := httptest.NewRecorder()
	r.ServeHTTP(w, req)

	return w
}

func TestJWTAuthRotTenantVaoContext(t *testing.T) {
	mgr := mgrTest()
	token, _, err := mgr.Generate(7, 42, "admin", jwt.AccessToken)
	if err != nil {
		t.Fatalf("không cấp được token: %v", err)
	}

	r, thay := dungEngineTenant(JWTAuth(mgr))
	if w := goiVoiToken(r, token); w.Code != http.StatusOK {
		t.Fatalf("token hợp lệ phải đi qua, nhận %d", w.Code)
	}
	if *thay != 42 {
		t.Fatalf("ctx của request phải mang cửa hàng 42, nhận %d", *thay)
	}
}

// Token cũ (cấp trước khi hệ thống có nhiều cửa hàng) không mang tid. Phải bắt
// đăng nhập lại chứ KHÔNG được đoán là cửa hàng số 1 — đoán một lần là mọi token
// cũ còn hiệu lực trên đời trở thành chìa khoá vào cửa hàng đó.
func TestJWTAuthTuChoiTokenKhongCoCuaHang(t *testing.T) {
	mgr := mgrTest()
	token := tokenKhongCuaHang(t, mgr)

	r, thay := dungEngineTenant(JWTAuth(mgr))
	w := goiVoiToken(r, token)
	if w.Code != http.StatusUnauthorized {
		t.Fatalf("token thiếu cửa hàng phải bị từ chối 401, nhận %d", w.Code)
	}
	if *thay != 0 {
		t.Fatalf("không được rót cửa hàng nào vào ctx, nhận %d", *thay)
	}
}

// OptionalJWTAuth không chặn ai, nhưng cũng không được nhận nửa vời: token thiếu
// cửa hàng thì coi như khách vãng lai, chứ không phải "người dùng số 7 không
// thuộc cửa hàng nào".
func TestOptionalJWTAuthBoQuaTokenKhongCoCuaHang(t *testing.T) {
	mgr := mgrTest()

	r, thay := dungEngineTenant(OptionalJWTAuth(mgr))
	if w := goiVoiToken(r, tokenKhongCuaHang(t, mgr)); w.Code != http.StatusOK {
		t.Fatalf("OptionalJWTAuth không được chặn, nhận %d", w.Code)
	}
	if *thay != 0 {
		t.Fatalf("token thiếu cửa hàng không được rót gì vào ctx, nhận %d", *thay)
	}
}

// TenantRequired trả lời đúng sự thật cho khách vãng lai thay vì để câu truy vấn
// vỡ tận dưới tầng GORM rồi thành lỗi 500 chẳng nói lên điều gì.
func TestTenantRequiredChanKhiChuaBietCuaHang(t *testing.T) {
	mgr := mgrTest()
	token, _, err := mgr.Generate(7, 42, "admin", jwt.AccessToken)
	if err != nil {
		t.Fatalf("không cấp được token: %v", err)
	}

	r := gin.New()
	r.GET("/thu", OptionalJWTAuth(mgr), TenantRequired(), func(c *gin.Context) {
		c.String(http.StatusOK, "ok")
	})

	if w := goiVoiToken(r, ""); w.Code != http.StatusUnauthorized {
		t.Fatalf("khách vãng lai phải nhận 401, nhận %d", w.Code)
	}
	if w := goiVoiToken(r, token); w.Code != http.StatusOK {
		t.Fatalf("người đã đăng nhập phải đi qua, nhận %d", w.Code)
	}
}

// Generate từ chối cấp token không mang cửa hàng: thà không đăng nhập được còn
// hơn phát ra một chiếc token mà mọi lượt gọi sau đó đều hỏng.
func TestGenerateTuChoiThieuCuaHang(t *testing.T) {
	if _, _, err := mgrTest().Generate(7, 0, "admin", jwt.AccessToken); err == nil {
		t.Fatal("cấp token thiếu mã cửa hàng phải báo lỗi")
	}
}

// tokenKhongCuaHang dựng một token ĐÚNG CHỮ KÝ nhưng không có trường tid — đúng
// hình dạng những token đã phát trước đợt này và vẫn còn hạn trong trình duyệt
// người dùng.
//
// Phải ký tay bằng thư viện gốc chứ không gọi Generate được: Generate nay từ chối
// cấp loại token này, mà chúng thì đang tồn tại thật ngoài đời.
func tokenKhongCuaHang(t *testing.T, _ *jwt.Manager) string {
	t.Helper()

	tok := gojwt.NewWithClaims(gojwt.SigningMethodHS256, gojwt.MapClaims{
		"uid":  7,
		"role": "admin",
		"typ":  string(jwt.AccessToken),
		"exp":  time.Now().Add(time.Hour).Unix(),
		"iat":  time.Now().Unix(),
	})
	s, err := tok.SignedString([]byte(biMatTest))
	if err != nil {
		t.Fatalf("không ký được token cũ: %v", err)
	}

	return s
}
