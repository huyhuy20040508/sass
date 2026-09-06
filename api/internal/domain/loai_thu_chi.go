package domain

import (
	"context"
	"errors"
	"time"

	"gorm.io/gorm"
)

// LOẠI THU CHI — Thu chi → Loại thu chi.
//
// Bảng tra cho phiếu thu và phiếu chi: cửa hàng khai "Tiền điện nước", "Lương
// nhân viên"… rồi chọn lúc lập phiếu. Port từ `cab_income_expense_types` của
// bản cũ v2 (màn cashbook/type), khác ba chỗ:
//
//  1. Có tenant_id, và bộ lọc cửa hàng nằm ở tầng dưới GORM nên không câu truy
//     vấn nào đi vòng được. Bản cũ đóng dấu branch_id lúc GHI nhưng dòng lọc
//     lúc ĐỌC bị comment lại — mọi chi nhánh nhìn thấy danh sách của nhau.
//
//  2. Trùng tên xét cả lúc SỬA. Bản cũ chỉ kiểm lúc thêm, nên đổi tên một loại
//     thành tên của loại khác thì lọt qua và bảng có hai dòng y hệt.
//
//  3. Không có `type_tax`. Cột ấy là móc của màn sổ sách và kết xuất thuế mà
//     bên này chưa có — xem chú thích ở migration 0057.
type LoaiThuChi struct {
	ID uint `json:"id" gorm:"primaryKey"`
	TenantOwned
	// Type là THU hay CHI. Giữ đúng mã số của v2 (0/1) để màn phiếu thu chi sau
	// này không phải dịch qua lại giữa hai cách đánh số.
	Type uint8  `json:"type"`
	Name string `json:"name"`
	// IsDefault = loại do hệ thống dựng và có phiếu TỰ SINH trỏ vào (bán hàng,
	// trả hàng…). Xoá nó thì phiếu cũ mất tên loại, nên chặn cả sửa lẫn xoá.
	// Danh sách gieo sẵn lúc mở cửa hàng KHÔNG mang cờ này: chủ tiệm không dùng
	// "Khấu hao tài sản cố định" thì phải xoá được.
	IsDefault bool           `json:"is_default"`
	CreatedAt time.Time      `json:"created_at"`
	UpdatedAt time.Time      `json:"updated_at"`
	DeletedAt gorm.DeletedAt `json:"-" gorm:"index"`
}

func (LoaiThuChi) TableName() string { return "income_expense_types" }

// Hai giá trị của cột type. Trùng mã số của v2 — xem chú thích ở struct.
const (
	LoaiThu uint8 = 0
	LoaiChi uint8 = 1
)

// LoaiThuChiFilter — tham số lọc khi liệt kê.
type LoaiThuChiFilter struct {
	// Type nil = lấy cả thu lẫn chi (màn danh sách dựng hai bảng trong một lượt).
	Type    *uint8
	Keyword string
}

// LoaiThuChiRepository — truy cập bảng income_expense_types.
type LoaiThuChiRepository interface {
	List(ctx context.Context, f LoaiThuChiFilter) ([]LoaiThuChi, error)
	FindByID(ctx context.Context, id uint) (*LoaiThuChi, error)
	// ExistsByName xét trong CÙNG một loại thu/chi và CHỈ trên dòng chưa xoá:
	// "Tiền điện" bên thu và "Tiền điện" bên chi là hai loại khác nhau, còn tên
	// của dòng đã xoá thì phải khai lại được.
	ExistsByName(ctx context.Context, loai uint8, name string, excludeID uint) (bool, error)
	Create(ctx context.Context, l *LoaiThuChi) error
	Update(ctx context.Context, l *LoaiThuChi) error
	Delete(ctx context.Context, id uint) error
}

var (
	// ErrLoaiThuChiTrungTen — tên đã có trong cùng loại thu/chi của cửa hàng này.
	ErrLoaiThuChiTrungTen = errors.New("tên loại thu chi đã tồn tại")

	// ErrLoaiThuChiMacDinh — loại hệ thống dựng: không sửa, không xoá.
	ErrLoaiThuChiMacDinh = errors.New("loại thu chi mặc định không sửa hay xoá được")
)
