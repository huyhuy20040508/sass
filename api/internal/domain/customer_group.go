package domain

import (
	"context"
	"time"

	"gorm.io/gorm"
)

// CustomerGroup — nhóm khách hàng ("Khách vãng lai", "Khách sỉ"…).
//
// Bảng tra thuần: cửa hàng tự khai rồi gán cho từng khách ở màn Khách hàng.
// Port từ `3rd_group_customers` của bản v2, thêm tenant_id vì bên mình cắt dữ
// liệu theo cửa hàng.
type CustomerGroup struct {
	ID uint `json:"id" gorm:"primaryKey"`
	TenantOwned
	// Type tách hai danh sách không dùng chung: 0 nhóm khách CÁ NHÂN (nói về mức
	// thân thiết), 1 nhóm khách DOANH NGHIỆP (nói về lĩnh vực kinh doanh). Xem
	// migration 0063.
	Type uint         `json:"type"`
	Name string       `json:"name"`
	Note StringOrNull `json:"note"`
	// Status 0 = ngừng dùng: nhóm vẫn còn để khách cũ giữ được tên nhóm, nhưng
	// không hiện trong ô chọn lúc khai khách mới.
	Status    int            `json:"status"`
	CreatedAt time.Time      `json:"created_at"`
	UpdatedAt time.Time      `json:"updated_at"`
	DeletedAt gorm.DeletedAt `json:"-" gorm:"index"`
}

func (CustomerGroup) TableName() string { return "customer_groups" }

// CustomerGroupRepository — đọc/ghi nhóm khách hàng.
type CustomerGroupRepository interface {
	// List trả mọi nhóm chưa xoá của cửa hàng, xếp theo tên.
	// onlyActive = chỉ nhóm đang dùng (cho ô chọn lúc khai khách).
	// loai = nil lấy cả hai danh sách; trỏ vào 0/1 thì chỉ lấy đúng loại đó.
	List(ctx context.Context, onlyActive bool, loai *uint) ([]CustomerGroup, error)
	FindByID(ctx context.Context, id uint) (*CustomerGroup, error)
	// ExistsByName xét trùng tên trong PHẠM VI DÒNG CHƯA XOÁ và CÙNG LOẠI;
	// ignoreID > 0 để bỏ qua chính nó lúc sửa. Cùng tên ở hai loại khác nhau là
	// bình thường. Bảng cố ý không có UNIQUE KEY — xem migration 0061.
	ExistsByName(ctx context.Context, loai uint, name string, ignoreID uint) (bool, error)
	Create(ctx context.Context, g *CustomerGroup) error
	Update(ctx context.Context, g *CustomerGroup) error
	Delete(ctx context.Context, id uint) error
	// CountCustomers đếm khách đang trỏ vào nhóm — nhóm còn khách thì không xoá.
	CountCustomers(ctx context.Context, id uint) (int64, error)
}
