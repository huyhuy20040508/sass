package domain

import (
	"context"
	"errors"
	"time"

	"gorm.io/gorm"
)

// VỊ TRÍ — Hàng hóa → Vị trí.
//
// Một bảng tra nhỏ: cửa hàng tự khai chỗ để hàng ("Kệ A - Tầng 1", "Kho lạnh",
// "Quầy trước"…) rồi gắn cho mặt hàng. Người soạn hàng đọc mã vị trí là biết đi
// thẳng tới đâu, thay vì đi dò cả kho.
//
// Bản cũ v2 KHÔNG có màn này — Menu QR chỉ tới Hoa hồng là hết, còn `hrm/position`
// bên đó là CHỨC VỤ nhân sự, không dính gì tới hàng hoá (mà cũng là code chết:
// không route nào trỏ tới). Nên đây dựng theo khuôn DonViTinh, vì cùng là bảng
// tra mã + tên của một cửa hàng, và giữ nguyên ba điều đã sửa ở đó:
//
//  1. Có tenant_id, và bộ lọc tenant nằm ở tầng dưới GORM chứ không phải mỗi
//     truy vấn tự nhớ.
//
//  2. Mã duy nhất TRONG MỘT cửa hàng, không phải toàn hệ thống.
//
//  3. Không có dòng khoá cứng nào. Chỗ để hàng là chuyện riêng của từng mặt
//     bằng, seed hộ vài dòng thì cửa hàng nào cũng phải xoá đi khai lại.
type ViTri struct {
	ID uint `json:"id" gorm:"primaryKey"`
	TenantOwned
	// Code là mã ngắn người dùng tự đặt (KEA1, KHOLANH…), luôn viết hoa.
	Code string `json:"code"`
	Name string `json:"name"`
	// IsActive = có bày ra ở ô chọn vị trí của mặt hàng không. Tắt chứ không xoá
	// khi một vị trí thôi dùng: mặt hàng cũ vẫn phải tra ra được tên vị trí.
	IsActive bool `json:"is_active"`
	// InUse = đang có mặt hàng trỏ tới nên không xoá được. Màn quản trị đọc cờ
	// này để xám hẳn nút xoá kèm lý do, thay vì cho bấm rồi mới báo lỗi.
	InUse     bool           `json:"in_use" gorm:"-"`
	CreatedAt time.Time      `json:"created_at"`
	UpdatedAt time.Time      `json:"updated_at"`
	DeletedAt gorm.DeletedAt `json:"-" gorm:"index"`
}

func (ViTri) TableName() string { return "product_locations" }

// ViTriFilter — tham số lọc khi liệt kê vị trí.
type ViTriFilter struct {
	Keyword string // tên hoặc mã
	// OnlyActive = true: chỉ vị trí đang bật (ô chọn lúc khai mặt hàng dùng cái này).
	OnlyActive bool
}

// ViTriRepository — truy cập bảng product_locations.
type ViTriRepository interface {
	List(ctx context.Context, f ViTriFilter) ([]ViTri, error)
	FindByID(ctx context.Context, id uint) (*ViTri, error)
	// ExistsByCode tính cả dòng đã xoá mềm — mã vẫn bị UNIQUE index giữ chỗ.
	ExistsByCode(ctx context.Context, code string, excludeID uint) (bool, error)
	// NextCode sinh mã kế tiếp theo dạng VT001 khi cửa hàng chưa bật quy tắc
	// đánh số riêng cho vị trí.
	NextCode(ctx context.Context) (string, error)
	// ExistsByName so KHÔNG phân biệt hoa thường nhưng CÓ phân biệt dấu, nên
	// "Kệ Đá" và "Ke Da" là hai vị trí khác nhau còn "kệ a" và "Kệ A" thì không.
	// Chỉ tính dòng chưa xoá: tên không bị ràng buộc duy nhất dưới database.
	ExistsByName(ctx context.Context, name string, excludeID uint) (bool, error)
	Create(ctx context.Context, vt *ViTri) error
	Update(ctx context.Context, vt *ViTri) error
	Delete(ctx context.Context, id uint) error
	// DangDuocDung trả về tập id vị trí đang có mặt hàng trỏ tới. Nhận cả trang
	// một lượt chứ không hỏi từng dòng: bảng 50 dòng mà mỗi dòng một câu đếm là
	// 50 lượt đi database cho một cái nút xám.
	DangDuocDung(ctx context.Context, ids []uint) (map[uint]bool, error)
}

// Hai lỗi trùng dữ liệu, tách nhau vì người đọc phải sửa hai ô khác nhau.
var (
	// ErrViTriTrungMa — mã đã có trong cửa hàng (kể cả ở dòng đã xoá mềm).
	ErrViTriTrungMa = errors.New("mã vị trí đã tồn tại")

	// ErrViTriTrungTen — tên đã có trong cửa hàng.
	ErrViTriTrungTen = errors.New("tên vị trí đã tồn tại")

	// ErrViTriDangDung — còn mặt hàng để ở vị trí này nên không xoá được. Xoá
	// được thì những mặt hàng ấy mất luôn chỗ, và không ai biết chúng đang nằm ở
	// đâu ngoài kho thật.
	ErrViTriDangDung = errors.New("vị trí đang được mặt hàng sử dụng")
)
