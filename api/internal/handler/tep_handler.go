package handler

import (
	"crypto/rand"
	"encoding/hex"
	"fmt"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"time"

	"github.com/gin-gonic/gin"

	"sass-api/internal/tenant"
	"sass-api/pkg/response"
)

// TepHandler nhận file tải lên và trả về địa chỉ đọc lại được.
//
// VÌ SAO API PHẢI CÓ ĐƯỜNG NÀY. Trước đây ảnh mặt hàng đi một đường duy nhất:
// trang quản trị Laravel nhận file rồi tự cất vào public/storage của chính nó.
// Đường ấy dựa vào PHIÊN ĐĂNG NHẬP WEB, nên app điện thoại — vốn xác thực bằng
// Bearer token với API — không có cách nào dùng lại. Kết quả là khai hàng trên
// điện thoại thì không đính được ảnh, mà đó lại là chỗ tiện chụp ảnh nhất.
type TepHandler struct {
	// thuMuc là gốc chỗ cất file trên đĩa. Router phục vụ lại chính thư mục này
	// ở /uploads, nên đổi một chỗ là phải đổi cả hai.
	thuMuc string
	// goc là địa chỉ công khai của API (APP_BASE_URL). Trả về URL TUYỆT ĐỐI chứ
	// không phải đường tương đối: giá trị này đi thẳng vào cột `thumbnail` và
	// được đọc lại bởi app điện thoại, trang quản trị và cả trang bán hàng —
	// mỗi nơi một gốc khác nhau, nên đường tương đối là mỗi nơi hiểu một kiểu.
	goc string
}

func NewTepHandler(thuMuc, goc string) *TepHandler {
	return &TepHandler{thuMuc: thuMuc, goc: strings.TrimRight(goc, "/")}
}

// coToiDa là cỡ file lớn nhất nhận vào: 5MB.
//
// Ảnh chụp bằng điện thoại đời mới thường 2–4MB nên 5 là vừa; lớn hơn nữa thì
// phần lớn là ảnh chưa nén, mà cái ô bày nó ra chỉ rộng 52dp.
const coToiDa = 5 << 20

// duoiChoPhep là những đuôi ảnh nhận vào.
//
// Danh sách TRẮNG chứ không phải danh sách đen: chặn theo danh sách đen thì mỗi
// đuôi lạ chưa nghĩ ra đều lọt, mà thư mục này được phục vụ lại như file tĩnh.
var duoiChoPhep = map[string]bool{
	".jpg": true, ".jpeg": true, ".png": true, ".webp": true,
}

// TaiAnh godoc
//
//	@Summary		Tải ảnh lên
//	@Description	Nhận MỘT file ảnh (multipart, khoá `file`) rồi trả về địa chỉ đọc lại được để gán vào `thumbnail` của mặt hàng. Tối đa 5MB, chỉ nhận .jpg .jpeg .png .webp.
//	@Description	File cất theo từng cửa hàng (`uploads/<tenant>/<năm>/<tháng>/`) nên hai cửa hàng không bao giờ ghi đè lên nhau.
//	@Tags			Admin - Products
//	@Accept			multipart/form-data
//	@Produce		json
//	@Security		BearerAuth
//	@Param			file	formData	file	true	"Ảnh cần tải lên"
//	@Success		201		{object}	response.Body
//	@Failure		400		{object}	response.Body
//	@Failure		413		{object}	response.Body
//	@Router			/admin/products/anh [post]
func (h *TepHandler) TaiAnh(c *gin.Context) {
	tep, err := c.FormFile("file")
	if err != nil {
		response.Error(c, http.StatusBadRequest, "Chưa chọn ảnh để tải lên")

		return
	}

	if tep.Size > coToiDa {
		// 413 chứ không phải 400: nói đúng loại lỗi để chỗ gọi phân biệt được
		// "ảnh quá nặng" (bảo người dùng chụp lại nhỏ hơn) với "gửi sai kiểu".
		response.Error(c, http.StatusRequestEntityTooLarge, "Ảnh quá 5MB, chọn ảnh nhẹ hơn")

		return
	}

	duoi := strings.ToLower(filepath.Ext(tep.Filename))
	if !duoiChoPhep[duoi] {
		response.Error(c, http.StatusBadRequest, "Chỉ nhận ảnh .jpg, .jpeg, .png hoặc .webp")

		return
	}

	// Cắt theo CỬA HÀNG rồi tới năm/tháng. Cắt theo cửa hàng để hai tiệm không
	// bao giờ đụng file của nhau; cắt theo tháng để một thư mục không phình tới
	// mức chính hệ tệp chậm đi sau vài năm.
	shopID, _ := tenant.ID(c.Request.Context())
	nay := time.Now()
	nhanh := fmt.Sprintf("%d/%04d/%02d", shopID, nay.Year(), int(nay.Month()))

	thuMuc := filepath.Join(h.thuMuc, filepath.FromSlash(nhanh))
	if err := os.MkdirAll(thuMuc, 0o755); err != nil {
		response.Error(c, http.StatusInternalServerError, "Không tạo được chỗ cất ảnh")

		return
	}

	// Tên NGẪU NHIÊN, không giữ tên gốc. Giữ tên gốc thì hai người cùng tải
	// "anh.jpg" là đè lên nhau, mà tên do người dùng đặt còn mang được cả dấu
	// chấm và dấu gạch chéo — hai thứ đủ để trỏ file ra ngoài thư mục này.
	ten, err := tenNgauNhien()
	if err != nil {
		response.Error(c, http.StatusInternalServerError, "Không đặt được tên ảnh")

		return
	}
	ten += duoi

	if err := c.SaveUploadedFile(tep, filepath.Join(thuMuc, ten)); err != nil {
		response.Error(c, http.StatusInternalServerError, "Không ghi được ảnh xuống đĩa")

		return
	}

	response.CreatedMessage(c, "Đã tải ảnh lên", gin.H{"url": h.goc + "/uploads/" + nhanh + "/" + ten})
}

// tenNgauNhien sinh 16 byte ngẫu nhiên dạng hex.
//
// crypto/rand chứ không phải math/rand: tên file đoán được là người ngoài dò ra
// ảnh của cửa hàng khác chỉ bằng cách thử, trong khi thư mục này phục vụ tĩnh và
// không hỏi token.
func tenNgauNhien() (string, error) {
	b := make([]byte, 16)
	if _, err := rand.Read(b); err != nil {
		return "", err
	}

	return hex.EncodeToString(b), nil
}
