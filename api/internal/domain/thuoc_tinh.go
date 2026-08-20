package domain

import (
	"context"
	"errors"
	"time"

	"gorm.io/gorm"
)

// THUỘC TÍNH — Hàng hóa → Thuộc tính.
//
// Bảng tra hai tầng: thuộc tính ("Kích cỡ", "Mức đá") và các GIÁ TRỊ của nó
// ("Nhỏ/Vừa/Lớn"). Dựng theo màn Quản lý thuộc tính của bản cũ v2
// (menu/menu-attribute), khác năm chỗ:
//
//  1. Có tenant_id ở CẢ HAI bảng. Bản cũ để `scopeBranch` trả thẳng query ra
//     (dòng lọc chi nhánh bị comment), còn bảng con thì không có cột chi nhánh
//     nào — nên mọi đường đọc thẳng bảng con là đọc của cả thiên hạ.
//
//  2. Mã duy nhất TRONG MỘT cửa hàng, không phải toàn hệ thống.
//
//  3. Giá trị có mã và mã duy nhất TRONG MỘT thuộc tính. Bản cũ thêm cột code
//     sau bằng ALTER, không ràng buộc gì.
//
//  4. Lượt sửa gửi lên NGUYÊN danh sách giá trị đang thấy: dòng vắng mặt là bị
//     xoá. Bản cũ bắn AJAX xoá ngay lúc bấm dấu × nên bấm nhầm rồi đóng hộp
//     thoại cũng không lấy lại được.
//
//  5. Đường đổi trạng thái chỉ ghi đúng cột is_active. Bản cũ gọi
//     `fill($request->all())` nên gửi kèm `name` là đổi luôn tên.
type ThuocTinh struct {
	ID uint `json:"id" gorm:"primaryKey"`
	TenantOwned
	// Code là mã ngắn người dùng tự đặt (SIZE, DA, TOPPING…), luôn viết hoa.
	Code string `json:"code"`
	Name string `json:"name"`
	// IsActive = có bày ra ở ô chọn thuộc tính lúc khai mặt hàng không. Tắt chứ
	// không xoá khi một thuộc tính thôi dùng: mặt hàng cũ vẫn phải tra ra tên.
	IsActive bool `json:"is_active"`
	// RawMaterial = thuộc tính này được dùng để khai định lượng nguyên vật liệu
	// cho món. Giữ đúng nghĩa cột raw_material_quantification của bản cũ.
	RawMaterial bool `json:"raw_material"`
	// GiaTri là các giá trị con, luôn trả kèm: một thuộc tính không có giá trị
	// nào thì chẳng dùng được vào việc gì, nên tách ra thành lượt gọi riêng chỉ
	// tổ khiến mọi màn hình phải gọi hai lần.
	GiaTri []ThuocTinhGiaTri `json:"values" gorm:"foreignKey:ThuocTinhID"`
	// InUse = đang bị thứ khác trỏ tới nên không xoá được. Hôm nay chưa bảng nào
	// trỏ tới thuộc tính (biến thể mặt hàng và định lượng nguyên liệu đều chưa
	// dựng) nên nó luôn false; màn quản trị đã đọc sẵn cờ này để khoá nút xoá,
	// dựng biến thể xong thì chỉ việc đếm ở repository.
	InUse     bool           `json:"in_use" gorm:"-"`
	CreatedAt time.Time      `json:"created_at"`
	UpdatedAt time.Time      `json:"updated_at"`
	DeletedAt gorm.DeletedAt `json:"-" gorm:"index"`
}

func (ThuocTinh) TableName() string { return "product_attributes" }

// ThuocTinhGiaTri — một giá trị của thuộc tính (thuộc tính "Kích cỡ" → "Nhỏ").
//
// KHÔNG xoá mềm. Bảng nhập cho phép bỏ một dòng rồi khai lại đúng mã ấy ngay
// trong cùng lượt sửa; xoá mềm nghĩa là dòng cũ còn nằm trong UNIQUE index và
// người dùng ăn lỗi "mã đã tồn tại" cho một bảng đang trống trơn.
type ThuocTinhGiaTri struct {
	ID uint `json:"id" gorm:"primaryKey"`
	TenantOwned
	ThuocTinhID uint      `json:"attribute_id" gorm:"column:attribute_id"`
	Code        string    `json:"code"`
	Name        string    `json:"name"`
	CreatedAt   time.Time `json:"created_at"`
	UpdatedAt   time.Time `json:"updated_at"`
}

func (ThuocTinhGiaTri) TableName() string { return "product_attribute_values" }

// ThuocTinhFilter — tham số lọc khi liệt kê thuộc tính.
type ThuocTinhFilter struct {
	Keyword string // tên hoặc mã
	// OnlyActive = true: chỉ thuộc tính đang bật (ô chọn lúc khai mặt hàng).
	OnlyActive bool
	// OnlyRawMaterial = true: chỉ thuộc tính bật cờ định lượng nguyên vật liệu.
	OnlyRawMaterial bool
}

// ThuocTinhRepository — truy cập bảng product_attributes và bảng giá trị con.
type ThuocTinhRepository interface {
	List(ctx context.Context, f ThuocTinhFilter) ([]ThuocTinh, error)
	FindByID(ctx context.Context, id uint) (*ThuocTinh, error)
	// ExistsByCode tính cả dòng đã xoá mềm — mã vẫn bị UNIQUE index giữ chỗ.
	ExistsByCode(ctx context.Context, code string, excludeID uint) (bool, error)
	// ExistsByName so KHÔNG phân biệt hoa thường nhưng CÓ phân biệt dấu, nên
	// "Đường" và "Duong" là hai thuộc tính khác nhau còn "size" và "SIZE" thì
	// không. Chỉ tính dòng chưa xoá: tên không bị ràng buộc duy nhất dưới DB.
	ExistsByName(ctx context.Context, name string, excludeID uint) (bool, error)
	// NextCode sinh mã kế tiếp theo dạng TT001 khi cửa hàng chưa bật quy tắc
	// đánh số riêng cho thuộc tính.
	NextCode(ctx context.Context) (string, error)
	// Create ghi thuộc tính cùng toàn bộ giá trị của nó trong MỘT transaction:
	// thuộc tính có mặt mà giá trị mất một nửa là thứ người dùng không nhìn ra
	// cho tới lúc khai mặt hàng.
	Create(ctx context.Context, tt *ThuocTinh, giaTri []ThuocTinhGiaTri) error
	// Update ghi phần thân thuộc tính và ĐỒNG BỘ danh sách giá trị: dòng có id
	// thì sửa, không id thì thêm, và giá trị cũ vắng mặt trong danh sách thì
	// xoá. Cũng gói trong một transaction.
	Update(ctx context.Context, tt *ThuocTinh, giaTri []ThuocTinhGiaTri) error
	// UpdateThan chỉ ghi phần thân, không đụng tới giá trị — đường đổi trạng
	// thái dùng cái này.
	UpdateThan(ctx context.Context, tt *ThuocTinh) error
	// Delete xoá mềm thuộc tính và xoá hẳn các giá trị của nó.
	Delete(ctx context.Context, id uint) error
	// DangDuocDung trả về tập id thuộc tính đang bị thứ khác trỏ tới. Hôm nay
	// luôn rỗng — xem chú thích ở trường ThuocTinh.InUse.
	DangDuocDung(ctx context.Context, ids []uint) (map[uint]bool, error)
}

// Bốn lỗi trùng dữ liệu, tách nhau vì người đọc phải sửa bốn ô khác nhau.
var (
	// ErrThuocTinhTrungMa — mã đã có trong cửa hàng (kể cả ở dòng đã xoá mềm).
	ErrThuocTinhTrungMa = errors.New("mã thuộc tính đã tồn tại")

	// ErrThuocTinhTrungTen — tên đã có trong cửa hàng.
	ErrThuocTinhTrungTen = errors.New("tên thuộc tính đã tồn tại")

	// ErrGiaTriTrungMa — hai giá trị cùng mã trong CÙNG một thuộc tính.
	ErrGiaTriTrungMa = errors.New("mã giá trị bị trùng trong cùng thuộc tính")

	// ErrGiaTriTrungTen — hai giá trị cùng tên trong CÙNG một thuộc tính.
	ErrGiaTriTrungTen = errors.New("tên giá trị bị trùng trong cùng thuộc tính")

	// ErrGiaTriLaCuaThuocTinhKhac — dòng gửi lên mang id của một giá trị không
	// thuộc thuộc tính đang sửa. Bản cũ v2 nhét thẳng id ấy vào `updateOrCreate`
	// nên nó thành lượt INSERT với khoá chính do client chọn.
	ErrGiaTriLaCuaThuocTinhKhac = errors.New("giá trị không thuộc thuộc tính đang sửa")
)
