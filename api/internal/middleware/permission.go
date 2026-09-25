package middleware

import (
	"net/http"

	"github.com/gin-gonic/gin"

	"sass-api/internal/domain"
	"sass-api/pkg/logger"
	"sass-api/pkg/response"

	"go.uber.org/zap"
)

// CtxQuyen giữ TẬP QUYỀN của người gọi trong suốt một request.
//
// Đọc một lần rồi nhớ: một request có thể đi qua nhiều lượt kiểm (middleware
// chặn đường, rồi handler tự hỏi "người này có được xem giá vốn không"), và
// không lượt nào trong số đó đáng phải đọc lại database.
const CtxQuyen = "ctx_quyen"

// MaThieuQuyen — mã máy đọc được cho lỗi 403 vì thiếu quyền.
//
// Tách khỏi 403 chung vì phía trước xử lý KHÁC HẲN: thiếu quyền thì ẩn mục menu
// đi và nói "bạn không được giao việc này", còn 403 vì cửa hàng bị khoá thì phải
// đưa người ta sang trang gói dịch vụ.
const MaThieuQuyen = "THIEU_QUYEN"

// KiemQuyen là chốt phân quyền theo chức năng.
//
// Nó vừa CHẶN (mỗi đường một quyền), vừa GIỮ SỔ những đường đã gắn quyền. Cái
// sổ mới là phần đáng giá: nó cho phép một bài kiểm khẳng định rằng KHÔNG đường
// quản trị nào bị bỏ quên. Không có sổ thì lỗ hổng do quên gắn quyền chỉ lộ ra
// khi có người thật đi vào đúng đường bị quên.
type KiemQuyen struct {
	repo domain.QuyenRepository
	// so ghi "METHOD /đường/đầy/đủ" -> chuỗi quyền.
	so map[string]string
}

func NewKiemQuyen(repo domain.QuyenRepository) *KiemQuyen {
	return &KiemQuyen{repo: repo, so: map[string]string{}}
}

// Dat đăng ký một đường CÓ gắn quyền.
//
// Dùng thay cho g.GET/g.POST… ở toàn bộ khu quản trị. Nó làm đúng hai việc: chèn
// lượt kiểm quyền vào đầu chuỗi xử lý, và ghi đường đó vào sổ.
func (k *KiemQuyen) Dat(g *gin.RouterGroup, method, path, quyen string, h gin.HandlerFunc) {
	full := joinPath(g.BasePath(), path)
	k.so[method+" "+full] = quyen
	g.Handle(method, path, k.Can(quyen), h)
}

// Can trả lượt kiểm cho MỘT quyền.
//
// Chuỗi quyền lạ làm chương trình CHẾT NGAY LÚC KHỞI ĐỘNG, cố ý. Một chuỗi gõ
// sai là quyền không ai được cấp, tức là khoá cứng cả trang mà không báo lỗi gì
// — hỏng theo kiểu im lặng nhất, và chỉ phát hiện được khi có người phàn nàn.
// Chết lúc khởi động thì nó hiện ra ở lượt chạy đầu tiên trên máy của người sửa.
func (k *KiemQuyen) Can(quyen string) gin.HandlerFunc {
	if !domain.QuyenHopLe(quyen) {
		panic("quyền không có trong danh mục: " + quyen +
			" (xem internal/domain/quyen.go)")
	}

	return func(c *gin.Context) {
		// Super admin đi thẳng, không tra bảng. Đây là tài khoản gốc của cửa
		// hàng: khoá nhầm nó là mất luôn đường vào để sửa. Mọi vai trò khác, KỂ
		// CẢ quản trị viên, đều bị tra.
		if c.GetString(CtxRole) == domain.RoleSuperAdmin {
			c.Next()

			return
		}

		bo, err := k.boQuyen(c)
		if err != nil {
			// Đọc quyền hỏng thì CHẶN. Ngược hẳn với hạn mức (hỏng thì cho qua,
			// vì đó là điều khoản bán hàng): đây là ranh giới bảo mật, và một
			// ranh giới mở ra khi database trục trặc thì không phải ranh giới.
			logger.Error("không đọc được quyền của tài khoản",
				zap.Uint("user_id", CurrentUserID(c)),
				zap.String("path", c.Request.URL.Path),
				zap.Error(err),
			)
			response.Error(c, http.StatusServiceUnavailable,
				"Không tra được quyền của bạn lúc này, vui lòng thử lại")

			return
		}

		if !bo.Co(quyen) {
			response.ErrorMa(c, http.StatusForbidden, MaThieuQuyen,
				"Bạn không được giao việc này")

			return
		}

		c.Next()
	}
}

// SoDangKy trả BẢN SAO sổ đăng ký cho bài kiểm đối chiếu với bảng route của Gin.
func (k *KiemQuyen) SoDangKy() map[string]string {
	ban := make(map[string]string, len(k.so))
	for d, q := range k.so {
		ban[d] = q
	}

	return ban
}

// boQuyen đọc quyền một lần cho cả request rồi nhớ trong ctx.
func (k *KiemQuyen) boQuyen(c *gin.Context) (domain.BoQuyen, error) {
	if san, ok := c.Get(CtxQuyen); ok {
		if bo, ok := san.(domain.BoQuyen); ok {
			return bo, nil
		}
	}

	bo, err := k.repo.BoQuyenCuaNguoi(c.Request.Context(), CurrentUserID(c), CurrentTenantID(c))
	if err != nil {
		return domain.BoQuyen{}, err
	}
	c.Set(CtxQuyen, bo)

	return bo, nil
}

// CoQuyen cho HANDLER hỏi "người gọi có quyền này không" — dùng để cắt bớt dữ
// liệu trong phản hồi (giá vốn, lương) chứ không phải để chặn đường; chặn là
// việc của Dat.
//
// Chưa nạp quyền vào ctx thì trả FALSE, theo đúng lối của PlatformRole: quên
// gắn middleware phải là ĐÓNG, không phải mở.
func CoQuyen(c *gin.Context, quyen string) bool {
	if c.GetString(CtxRole) == domain.RoleSuperAdmin {
		return true
	}
	san, ok := c.Get(CtxQuyen)
	if !ok {
		return false
	}
	bo, ok := san.(domain.BoQuyen)

	return ok && bo.Co(quyen)
}

// CurrentUserID / CurrentTenantID — hai getter công khai cho gói khác dùng lại,
// cùng lối "không có thì trả 0" với các getter sẵn có trong gói này.
func CurrentUserID(c *gin.Context) uint {
	id, _ := c.Get(CtxUserID)
	v, _ := id.(uint)

	return v
}

func CurrentTenantID(c *gin.Context) uint {
	id, _ := c.Get(CtxTenantID)
	v, _ := id.(uint)

	return v
}

// joinPath ghép đường của nhóm với đường của route, tránh sinh ra dấu gạch đôi.
func joinPath(base, path string) string {
	switch {
	case path == "" || path == "/":
		return base
	case base == "/":
		return path
	case len(path) > 0 && path[0] == '/':
		return base + path
	default:
		return base + "/" + path
	}
}

// CtxCuaVao giữ CỬA VÀO của người gọi trong suốt một request (giống CtxQuyen).
const CtxCuaVao = "ctx_cua_vao"

// MaSaiCua — mã máy đọc được cho lỗi 403 vì không được giao cửa này.
//
// Tách khỏi MaThieuQuyen vì phía trước xử lý khác: thiếu quyền thì ẩn một nút
// đi, còn sai cửa nghĩa là cả module không dành cho người này — trang quản trị
// đưa họ về đúng module của mình thay vì hiện một màn hình rỗng.
const MaSaiCua = "SAI_CUA"

// Cua chặn một nhóm đường theo CỬA VÀO đã giao (users.access_areas).
//
// Khác Can(): Can hỏi "người này có được bấm nút đó không", còn đây hỏi "người
// này có được đứng ở khu này không". Hai câu khác nhau — chủ tiệm có thể giao
// đủ quyền bán hàng cho một người mà vẫn không cho họ vào quầy, và ngược lại.
//
// Đọc từ database chứ không từ token: bỏ tích một cửa thì người đó phải mất cửa
// ngay lượt bấm sau, chứ không phải chờ tới lần đăng nhập kế tiếp.
func (k *KiemQuyen) Cua(cua string) gin.HandlerFunc {
	if !domain.CuaVaoHopLe[cua] {
		panic("cửa không có trong danh mục: " + cua + " (xem internal/domain/entities.go)")
	}

	return func(c *gin.Context) {
		// Super admin đi thẳng, cùng lý do như Can(): đây là tài khoản gốc của cửa
		// hàng, khoá nhầm nó là mất luôn đường vào để sửa.
		if c.GetString(CtxRole) == domain.RoleSuperAdmin {
			c.Next()

			return
		}

		areas, roleID, err := k.cuaVao(c)
		if err != nil {
			// Hỏng thì CHẶN, cùng lối với Can(): một ranh giới mở ra khi database
			// trục trặc thì không phải ranh giới.
			logger.Error("không đọc được cửa vào của tài khoản",
				zap.Uint("user_id", CurrentUserID(c)),
				zap.String("path", c.Request.URL.Path),
				zap.Error(err),
			)
			response.Error(c, http.StatusServiceUnavailable,
				"Không tra được quyền của bạn lúc này, vui lòng thử lại")

			return
		}

		if !domain.CoCua(areas, roleID, cua) {
			response.ErrorMa(c, http.StatusForbidden, MaSaiCua,
				"Tài khoản của bạn không được giao khu này")

			return
		}

		c.Next()
	}
}

// cuaVao đọc một lần cho cả request rồi nhớ trong ctx.
func (k *KiemQuyen) cuaVao(c *gin.Context) (string, uint, error) {
	type ghi struct {
		areas  string
		roleID uint
	}
	if san, ok := c.Get(CtxCuaVao); ok {
		if g, ok := san.(ghi); ok {
			return g.areas, g.roleID, nil
		}
	}

	areas, roleID, err := k.repo.CuaVaoCuaNguoi(c.Request.Context(), CurrentUserID(c), CurrentTenantID(c))
	if err != nil {
		return "", 0, err
	}
	c.Set(CtxCuaVao, ghi{areas: areas, roleID: roleID})

	return areas, roleID, nil
}
