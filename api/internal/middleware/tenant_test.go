package middleware

import (
	"context"
	"errors"
	"net/http"
	"net/http/httptest"
	"testing"
	"time"

	"github.com/gin-gonic/gin"
	gojwt "github.com/golang-jwt/jwt/v5"

	"sass-api/internal/domain"
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

	r, thay := dungEngineTenant(JWTAuth(mgr, nil))
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

	r, thay := dungEngineTenant(JWTAuth(mgr, nil))
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

// ---------- Phân giải cửa hàng theo TÊN MIỀN ----------

// soTenMien là sổ tên miền giả. Bài kiểm ở đây nhắm vào QUYẾT ĐỊNH của
// middleware (tên miền thắng token, cửa hàng khoá thì đóng trang, sổ hỏng thì
// từ chối) chứ không nhắm vào câu SQL — phần SQL đã chạy trên database thật ở
// internal/apitest.
type soTenMien struct {
	theo map[string]*domain.PlatformTenant
	loi  error
}

func (s soTenMien) FindTenantByHost(_ context.Context, host string) (*domain.PlatformTenant, error) {
	if s.loi != nil {
		return nil, s.loi
	}
	if t, ok := s.theo[host]; ok {
		return t, nil
	}

	return nil, domain.ErrNotFound
}

// dungEngineTenMien dựng engine có đủ ba middleware theo đúng thứ tự của router.
func dungEngineTenMien(mgr *jwt.Manager, so domain.TenantDomainRepository) (*gin.Engine, *uint) {
	thay := new(uint)
	r := gin.New()
	r.GET("/thu",
		OptionalJWTAuth(mgr),
		TenantFromHost(so),
		TenantRequired(),
		func(c *gin.Context) {
			if id, ok := tenant.ID(c.Request.Context()); ok {
				*thay = id
			}
			c.String(http.StatusOK, "ok")
		})

	return r, thay
}

func goiTuHost(r *gin.Engine, host, token string) *httptest.ResponseRecorder {
	req := httptest.NewRequest(http.MethodGet, "/thu", nil)
	req.Host = host
	if token != "" {
		req.Header.Set("Authorization", "Bearer "+token)
	}
	w := httptest.NewRecorder()
	r.ServeHTTP(w, req)

	return w
}

func soThu() soTenMien {
	return soTenMien{theo: map[string]*domain.PlatformTenant{
		"tiema.test": {ID: 11, Code: "tiema", Status: domain.TenantActive},
		"tiemb.test": {ID: 22, Code: "tiemb", Status: domain.TenantActive},
		"đóng.test":  {ID: 33, Code: "tiemc", Status: "suspended"},
	}}
}

// Khách VÃNG LAI vào tên miền nào thì được phục vụ cửa hàng ấy. Đây là điều kiện
// để cụm bán hàng cho khách tồn tại — trước nó, cửa hàng chỉ đến từ token nên
// người chưa đăng nhập không mua được ở đâu cả.
func TestTenantFromHostRotCuaHangChoKhachVangLai(t *testing.T) {
	r, thay := dungEngineTenMien(mgrTest(), soThu())

	if w := goiTuHost(r, "tiema.test", ""); w.Code != http.StatusOK {
		t.Fatalf("khách vãng lai vào tên miền đã vào sổ phải đi qua, nhận %d", w.Code)
	}
	if *thay != 11 {
		t.Fatalf("ctx phải mang cửa hàng 11, nhận %d", *thay)
	}
}

// Tên miền CHƯA vào sổ phải để mọi thứ y như trước khi có middleware này: token
// quyết định, không có token thì TenantRequired chặn. Nhờ vậy bật nó lên không
// đổi hành vi của khu quản trị đang chạy.
func TestTenantFromHostBoQuaTenMienLa(t *testing.T) {
	mgr := mgrTest()
	token, _, err := mgr.Generate(7, 42, "admin", jwt.AccessToken)
	if err != nil {
		t.Fatalf("không cấp được token: %v", err)
	}

	r, thay := dungEngineTenMien(mgr, soThu())

	if w := goiTuHost(r, "khong-co-trong-so.test", ""); w.Code != http.StatusUnauthorized {
		t.Fatalf("tên miền lạ + không token phải là 401, nhận %d", w.Code)
	}
	if w := goiTuHost(r, "khong-co-trong-so.test", token); w.Code != http.StatusOK {
		t.Fatalf("tên miền lạ + token hợp lệ phải đi qua, nhận %d", w.Code)
	}
	if *thay != 42 {
		t.Fatalf("ctx phải mang cửa hàng của token (42), nhận %d", *thay)
	}
}

// Token của tiệm này + tên miền của tiệm kia = TỪ CHỐI, không phải im lặng chọn
// một bên. Chọn token thì người đó đứng trên trang tiệm B mà đọc dữ liệu tiệm A.
func TestTenantFromHostTuChoiTokenLechTenMien(t *testing.T) {
	mgr := mgrTest()
	token, _, err := mgr.Generate(7, 11, "customer", jwt.AccessToken)
	if err != nil {
		t.Fatalf("không cấp được token: %v", err)
	}

	r, thay := dungEngineTenMien(mgr, soThu())

	if w := goiTuHost(r, "tiemb.test", token); w.Code != http.StatusUnauthorized {
		t.Fatalf("token cửa hàng 11 trên tên miền của cửa hàng 22 phải bị từ chối 401, nhận %d", w.Code)
	}
	if *thay != 0 {
		t.Fatalf("không được rót cửa hàng nào vào ctx, nhận %d", *thay)
	}

	if w := goiTuHost(r, "tiema.test", token); w.Code != http.StatusOK {
		t.Fatalf("đúng tên miền của mình phải đi qua, nhận %d", w.Code)
	}
}

// Cửa hàng ngừng trả tiền thì trang bán hàng đóng — đó là toàn bộ ý nghĩa của
// trạng thái suspended.
func TestTenantFromHostDongTrangKhiCuaHangBiKhoa(t *testing.T) {
	r, thay := dungEngineTenMien(mgrTest(), soThu())

	if w := goiTuHost(r, "đóng.test", ""); w.Code != http.StatusForbidden {
		t.Fatalf("cửa hàng bị khoá phải trả 403, nhận %d", w.Code)
	}
	if *thay != 0 {
		t.Fatalf("không được rót cửa hàng nào vào ctx, nhận %d", *thay)
	}
}

// Sổ tên miền hỏng thì TỪ CHỐI, KHÔNG rơi về token.
//
// Rơi về token nghĩa là đúng lúc control plane trục trặc — lúc không ai nhìn —
// một người đang đăng nhập ở tiệm A mở trang tiệm B sẽ được phục vụ bằng dữ liệu
// của A. Đây là nhánh không dựng lại được ở bài kiểm chạy trên database thật, nên
// nó phải có chỗ đứng riêng ở đây.
func TestTenantFromHostTuChoiKhiSoHong(t *testing.T) {
	mgr := mgrTest()
	token, _, err := mgr.Generate(7, 11, "customer", jwt.AccessToken)
	if err != nil {
		t.Fatalf("không cấp được token: %v", err)
	}

	r, thay := dungEngineTenMien(mgr, soTenMien{loi: errors.New("mất kết nối control plane")})

	w := goiTuHost(r, "tiemb.test", token)
	if w.Code != http.StatusServiceUnavailable {
		t.Fatalf("sổ tên miền hỏng phải trả 503, nhận %d", w.Code)
	}
	if *thay != 0 {
		t.Fatalf("không được rơi về cửa hàng của token, nhận %d", *thay)
	}
}

// repo = nil là bản dựng chưa có control plane: middleware thành một bước đi qua
// và mọi thứ chạy y như trước.
func TestTenantFromHostKhongCoSoThiDiQua(t *testing.T) {
	mgr := mgrTest()
	token, _, err := mgr.Generate(7, 42, "admin", jwt.AccessToken)
	if err != nil {
		t.Fatalf("không cấp được token: %v", err)
	}

	r, thay := dungEngineTenMien(mgr, nil)

	if w := goiTuHost(r, "tiema.test", token); w.Code != http.StatusOK {
		t.Fatalf("không có sổ thì token vẫn phải đi qua, nhận %d", w.Code)
	}
	if *thay != 42 {
		t.Fatalf("ctx phải mang cửa hàng của token (42), nhận %d", *thay)
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
