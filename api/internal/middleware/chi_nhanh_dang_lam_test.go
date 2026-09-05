package middleware

import (
	"context"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/gin-gonic/gin"

	"sass-api/internal/chinhanh"
	"sass-api/internal/domain"
)

// GỠ HEADER CHI NHÁNH ĐI THÌ KHÔNG ĐƯỢC THOÁT.
//
// Bản cũ: header rỗng thì `c.Next()` và ctx không mang chi nhánh nào. Nghe thì
// vô hại, nhưng nó là lỗ hổng đứng TRƯỚC mọi chốt chặn khác — quy ước của các
// lượt đọc trong hệ thống là "không rõ chi nhánh thì KHÔNG cắt", nên người bị
// ghim vào kho 2 chỉ cần bỏ header đi là mọi luật lọc phía dưới tự mở ra, và
// không tầng nào cứu được: chúng tin rằng ctx trống nghĩa là người này quản cả
// cửa hàng.

// soNhanSu là sổ nhân sự giả. cua = chi nhánh mà tài khoản bị phân về; nil nghĩa
// là người này đi đâu cũng được.
type soNhanSu struct {
	// Nhúng interface đầy đủ để chỉ phải cài đúng một phương thức. Gọi nhầm
	// phương thức khác thì panic nil — đúng ý: middleware này chỉ được phép hỏi
	// sổ nhân sự đúng một câu.
	domain.NhanVienRepository
	cua *uint
	err error
}

func (s soNhanSu) ChiNhanhCuaTaiKhoan(context.Context, uint) (*uint, error) {
	return s.cua, s.err
}

// soChiNhanh là sổ chi nhánh giả: chỉ cần trả về một chi nhánh đang mở.
type soChiNhanh struct {
	domain.ChiNhanhRepository
	dong bool
}

func (s soChiNhanh) FindByID(_ context.Context, id uint) (*domain.ChiNhanh, error) {
	return &domain.ChiNhanh{ID: id, Name: "Kho thử", IsActive: !s.dong}, nil
}

// dungEngineChiNhanh dựng một đường duy nhất, in ra chi nhánh mà ctx mang theo.
// 0 = không mang gì.
func dungEngineChiNhanh(vaiTro string, userID uint, nv domain.NhanVienRepository) *gin.Engine {
	r := gin.New()
	r.GET("/thu",
		func(c *gin.Context) {
			c.Set(CtxRole, vaiTro)
			c.Set(CtxUserID, userID)
			c.Next()
		},
		ChiNhanhDangLam(soChiNhanh{}, nv),
		func(c *gin.Context) {
			id, _ := chinhanh.ID(c.Request.Context())
			c.JSON(http.StatusOK, gin.H{
				"chi_nhanh": id,
				"ghim":      c.GetBool(CtxChiNhanhGhim),
			})
		},
	)

	return r
}

func goiThu(t *testing.T, r *gin.Engine, header string) (int, string) {
	t.Helper()

	req := httptest.NewRequest(http.MethodGet, "/thu", nil)
	if header != "" {
		req.Header.Set(HeaderChiNhanh, header)
	}
	w := httptest.NewRecorder()
	r.ServeHTTP(w, req)

	return w.Code, w.Body.String()
}

// ĐÂY LÀ BÀI KIỂM CHÍNH: nhân viên bị ghim kho 7, không khai header, thì ctx vẫn
// phải mang kho 7 — chứ không phải trống.
func TestChiNhanhDangLam_NhanVienGoHeaderVanBiGhim(t *testing.T) {
	gin.SetMode(gin.TestMode)

	kho := uint(7)
	r := dungEngineChiNhanh(domain.RoleStaff, 42, soNhanSu{cua: &kho})

	ma, than := goiThu(t, r, "")
	if ma != http.StatusOK {
		t.Fatalf("không khai header thì vẫn phải đi tiếp, nhận %d", ma)
	}
	if !strings.Contains(than, `"chi_nhanh":7`) {
		t.Fatalf("gỡ header đi mà ctx vẫn phải mang kho 7, nhận: %s", than)
	}
}

// Chủ tiệm / quản lý KHÔNG bị ghim: không khai thì ctx để trống, và các lượt đọc
// hiểu là "xem cả cửa hàng". Đây là chiều mở của cùng một luật — làm hỏng chiều
// này là chủ tiệm không xem được gì ngoài một kho.
func TestChiNhanhDangLam_ChuTiemKhongBiGhim(t *testing.T) {
	gin.SetMode(gin.TestMode)

	kho := uint(7)
	// Sổ nhân sự CÓ trả về chi nhánh, nhưng vai trò không phải nhân viên.
	r := dungEngineChiNhanh(domain.RoleAdmin, 42, soNhanSu{cua: &kho})

	ma, than := goiThu(t, r, "")
	if ma != http.StatusOK {
		t.Fatalf("chủ tiệm không khai header phải đi tiếp, nhận %d", ma)
	}
	if !strings.Contains(than, `"chi_nhanh":0`) {
		t.Fatalf("chủ tiệm không khai thì ctx phải để trống, nhận: %s", than)
	}
}

// Nhân viên CHƯA bị phân công (tiệm một điểm bán) cũng để trống — không có gì
// để ghim thì đừng bịa ra một chi nhánh.
func TestChiNhanhDangLam_NhanVienChuaPhanCongThiDeTrong(t *testing.T) {
	gin.SetMode(gin.TestMode)

	r := dungEngineChiNhanh(domain.RoleStaff, 42, soNhanSu{cua: nil})

	ma, than := goiThu(t, r, "")
	if ma != http.StatusOK || !strings.Contains(than, `"chi_nhanh":0`) {
		t.Fatalf("nhân viên chưa phân công phải để ctx trống, nhận %d: %s", ma, than)
	}
}

// Sổ nhân sự hỏng thì KHÔNG khoá cửa ai cả: một trục trặc database không được
// phép biến thành "không ai bán hàng được nữa".
func TestChiNhanhDangLam_SoNhanSuHongThiVanDiTiep(t *testing.T) {
	gin.SetMode(gin.TestMode)

	r := dungEngineChiNhanh(domain.RoleStaff, 42, soNhanSu{err: context.DeadlineExceeded})

	ma, than := goiThu(t, r, "")
	if ma != http.StatusOK || !strings.Contains(than, `"chi_nhanh":0`) {
		t.Fatalf("sổ nhân sự hỏng vẫn phải đi tiếp, nhận %d: %s", ma, than)
	}
}

// CỜ GHIM phải bật cho người bị phân công, kể cả khi họ tự khai đúng kho mình.
//
// Nhìn vào chi nhánh trong ctx thì hai trường hợp giống hệt nhau: chủ tiệm vừa
// chọn kho 7, và nhân viên bị phân về kho 7. Người đầu được xem cả cửa hàng nếu
// muốn (?shop_id=0), người sau thì không — chiNhanhLoc bên handler dựa vào đúng
// cờ này để biết ai là ai. Thiếu nó, tham số trên URL đưa nhân viên đi khắp nơi.
func TestChiNhanhDangLam_NguoiBiPhanCongThiCoCoGhim(t *testing.T) {
	gin.SetMode(gin.TestMode)

	kho := uint(7)
	nv := dungEngineChiNhanh(domain.RoleStaff, 42, soNhanSu{cua: &kho})

	// Không khai header.
	if _, than := goiThu(t, nv, ""); !strings.Contains(than, `"ghim":true`) {
		t.Fatalf("nhân viên không khai header phải có cờ ghim, nhận: %s", than)
	}
	// Khai đúng kho của mình — vẫn là bắt buộc, không phải lựa chọn.
	if _, than := goiThu(t, nv, "7"); !strings.Contains(than, `"ghim":true`) {
		t.Fatalf("nhân viên khai đúng kho mình vẫn phải có cờ ghim, nhận: %s", than)
	}

	// Chủ tiệm chọn kho 7: KHÔNG ghim, họ đổi được.
	chu := dungEngineChiNhanh(domain.RoleAdmin, 42, soNhanSu{cua: &kho})
	if _, than := goiThu(t, chu, "7"); strings.Contains(than, `"ghim":true`) {
		t.Fatalf("chủ tiệm không được bị ghim, nhận: %s", than)
	}
}

// Khai header của chi nhánh KHÔNG phải của mình thì bị từ chối — luật cũ, giữ
// nguyên. Bài này ở đây để lượt sửa sau không vô tình đánh đổi nó lấy chiều mở.
func TestChiNhanhDangLam_NhanVienKhaiKhoKhacThiBiTuChoi(t *testing.T) {
	gin.SetMode(gin.TestMode)

	kho := uint(7)
	r := dungEngineChiNhanh(domain.RoleStaff, 42, soNhanSu{cua: &kho})

	if ma, than := goiThu(t, r, "9"); ma != http.StatusForbidden {
		t.Fatalf("khai kho không phải của mình phải bị chặn 403, nhận %d: %s", ma, than)
	}
	// Còn kho của chính mình thì đi được.
	if ma, than := goiThu(t, r, "7"); ma != http.StatusOK || !strings.Contains(than, `"chi_nhanh":7`) {
		t.Fatalf("khai đúng kho của mình phải đi được, nhận %d: %s", ma, than)
	}
}
