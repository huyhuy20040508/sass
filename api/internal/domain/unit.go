package domain

import (
	"context"
	"errors"
	"time"

	"gorm.io/gorm"
)

// ĐƠN VỊ TÍNH — Hàng hóa → Đơn vị.
//
// Một bảng tra nhỏ: cửa hàng tự khai "Cái", "Hộp", "Kg", "Thùng"… rồi gắn cho
// mặt hàng. Dựng theo màn Đơn vị của bản cũ v2 (menu/menu-unit), khác ba chỗ:
//
//  1. Có tenant_id. Bản cũ để `scopeBranch` trả thẳng query ra, dòng lọc chi
//     nhánh bị comment lại — ghi thì đóng dấu chi nhánh, đọc thì thấy của tất
//     cả. Ở đây bộ lọc tenant là bắt buộc và nằm ở tầng dưới GORM.
//
//  2. Mã duy nhất TRONG MỘT cửa hàng, không phải toàn hệ thống. Bản cũ đặt
//     `Rule::unique('odr_menu_units')` không kèm chi nhánh: tiệm này đã dùng mã
//     "KG" thì tiệm khác không đặt được nữa.
//
//  3. Không có `is_default`. Hai dòng KG/G khoá cứng bên bản cũ là móc của tính
//     năng bán theo cân (quantity lưu gram) — ở đây chưa có tính năng ấy, bày ra
//     hai dòng không xoá được mà không dùng vào việc gì chỉ tổ khó hiểu.
type DonViTinh struct {
	ID uint `json:"id" gorm:"primaryKey"`
	TenantOwned
	// Code là mã ngắn người dùng tự đặt (KG, CAI, THUNG…), luôn viết hoa.
	Code string `json:"code"`
	Name string `json:"name"`
	// IsActive = có bày ra ở ô chọn đơn vị của mặt hàng không. Tắt chứ không xoá
	// khi một đơn vị thôi dùng: mặt hàng cũ vẫn phải tra ra được tên đơn vị.
	IsActive  bool           `json:"is_active"`
	CreatedAt time.Time      `json:"created_at"`
	UpdatedAt time.Time      `json:"updated_at"`
	DeletedAt gorm.DeletedAt `json:"-" gorm:"index"`
}

func (DonViTinh) TableName() string { return "product_units" }

// DonViTinhFilter — tham số lọc khi liệt kê đơn vị tính.
type DonViTinhFilter struct {
	Keyword string // tên hoặc mã
	// OnlyActive = true: chỉ đơn vị đang bật (ô chọn lúc khai mặt hàng dùng cái này).
	OnlyActive bool
}

// DonViTinhRepository — truy cập bảng product_units.
type DonViTinhRepository interface {
	List(ctx context.Context, f DonViTinhFilter) ([]DonViTinh, error)
	FindByID(ctx context.Context, id uint) (*DonViTinh, error)
	// ExistsByCode tính cả dòng đã xoá mềm — mã vẫn bị UNIQUE index giữ chỗ.
	ExistsByCode(ctx context.Context, code string, excludeID uint) (bool, error)
	// NextCode sinh mã kế tiếp theo dạng DV001 khi cửa hàng chưa bật quy tắc
	// đánh số riêng cho đơn vị tính.
	NextCode(ctx context.Context) (string, error)
	// ExistsByName so KHÔNG phân biệt hoa thường nhưng CÓ phân biệt dấu, nên
	// "Đường" và "Duong" là hai đơn vị khác nhau còn "kg" và "KG" thì không.
	// Chỉ tính dòng chưa xoá: tên không bị ràng buộc duy nhất dưới database.
	ExistsByName(ctx context.Context, name string, excludeID uint) (bool, error)
	Create(ctx context.Context, dv *DonViTinh) error
	Update(ctx context.Context, dv *DonViTinh) error
	Delete(ctx context.Context, id uint) error
}

// Hai lỗi trùng dữ liệu, tách nhau vì người đọc phải sửa hai ô khác nhau.
var (
	// ErrDonViTinhTrungMa — mã đã có trong cửa hàng (kể cả ở dòng đã xoá mềm).
	ErrDonViTinhTrungMa = errors.New("mã đơn vị tính đã tồn tại")

	// ErrDonViTinhTrungTen — tên đã có trong cửa hàng.
	ErrDonViTinhTrungTen = errors.New("tên đơn vị tính đã tồn tại")
)
