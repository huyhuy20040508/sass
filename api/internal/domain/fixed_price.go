package domain

import (
	"context"
	"errors"
	"strconv"
	"strings"
	"time"
)

// KHUYẾN MẠI ĐỒNG GIÁ — khuôn pmt_fixed_prices của bản v2. Xem migration 0069.
//
// "Giỏ có từ Quantity cái của nhóm hàng / sản phẩm ObjectID thì MỌI cái của nó
// bán đúng Price, kèm hàng tặng." Khác Promotion ở hai điểm: điều kiện là SỐ
// LƯỢNG TRONG GIỎ (không tính được lúc khách xem một món lẻ), và thu ngân chủ
// động chọn chương trình ở quầy chứ không tự áp.

const (
	FixedPriceTheoNhom    uint8 = 1 // ObjectID là danh mục (kèm danh mục con)
	FixedPriceTheoSanPham uint8 = 2 // ObjectID là sản phẩm
)

type FixedPrice struct {
	ID uint `json:"id" gorm:"primaryKey"`
	TenantOwned
	Code        string `json:"code"`
	Name        string `json:"name"`
	Description string `json:"description"`
	Type        uint8  `json:"type"`
	// Status: bật / tắt. Approved: 0 lưu tạm, 1 đã duyệt. Quầy chỉ thấy chương
	// trình vừa bật vừa đã duyệt.
	Status      bool `json:"status"`
	Approved    bool `json:"approved"`
	NoTimeLimit bool `json:"no_time_limit"`
	// Ngày áp dụng, tính TRỌN ngày. Nil khi NoTimeLimit.
	StartDate *time.Time `json:"start_date" gorm:"type:date"`
	EndDate   *time.Time `json:"end_date" gorm:"type:date"`
	// DaysOfWeek "1,3,5" theo ISO; rỗng = mọi ngày.
	DaysOfWeek string `json:"days_of_week"`
	// AllShops = toàn hệ thống; không thì chỉ các chi nhánh ở ShopIDs.
	AllShops bool `json:"all_shops"`

	ShopIDs []uint             `json:"shop_ids" gorm:"-"`
	Details []FixedPriceDetail `json:"details" gorm:"foreignKey:FixedPriceID"`

	CreatedAt time.Time `json:"created_at"`
	UpdatedAt time.Time `json:"updated_at"`
}

func (FixedPrice) TableName() string { return "fixed_prices" }

type FixedPriceDetail struct {
	ID uint `json:"id" gorm:"primaryKey"`
	TenantOwned
	FixedPriceID uint             `json:"fixed_price_id"`
	ObjectID     uint             `json:"object_id"`
	Quantity     int              `json:"quantity"`
	Price        float64          `json:"price"`
	Gifts        []FixedPriceGift `json:"gifts" gorm:"foreignKey:FixedPriceDetailID"`
}

func (FixedPriceDetail) TableName() string { return "fixed_price_details" }

type FixedPriceGift struct {
	ID uint `json:"id" gorm:"primaryKey"`
	TenantOwned
	FixedPriceDetailID uint `json:"fixed_price_detail_id"`
	ProductVariantID   uint `json:"product_variant_id"`
	Quantity           int  `json:"quantity"`
}

func (FixedPriceGift) TableName() string { return "fixed_price_gifts" }

type FixedPriceShop struct {
	ID uint `gorm:"primaryKey"`
	TenantOwned
	FixedPriceID uint
	ShopID       uint
}

func (FixedPriceShop) TableName() string { return "fixed_price_shops" }

// ChayNgay cho biết chương trình có hiệu lực trong ngày của at không — chỉ xét
// ngày và thứ, không xét bật/duyệt/chi nhánh.
func (f FixedPrice) ChayNgay(at time.Time) bool {
	return hieuLucNgay(f.NoTimeLimit, f.StartDate, f.EndDate, f.DaysOfWeek, at)
}

// hieuLucNgay — luật ngày dùng chung của đồng giá và chương trình khuyến mại:
// trong khoảng ngày (trọn ngày, trừ khi không giới hạn) VÀ đúng thứ ("1,3,5"
// theo ISO; rỗng = mọi ngày).
func hieuLucNgay(khongHan bool, tu, den *time.Time, thuCSV string, at time.Time) bool {
	if !khongHan {
		ngay := time.Date(at.Year(), at.Month(), at.Day(), 0, 0, 0, 0, at.Location())
		if tu != nil && ngay.Before(dauNgay(*tu, at.Location())) {
			return false
		}
		if den != nil && ngay.After(dauNgay(*den, at.Location())) {
			return false
		}
	}
	if strings.TrimSpace(thuCSV) == "" {
		return true
	}
	thu := int(at.Weekday())
	if thu == 0 {
		thu = 7
	}
	for _, d := range strings.Split(thuCSV, ",") {
		if strings.TrimSpace(d) == strconv.Itoa(thu) {
			return true
		}
	}

	return false
}

func dauNgay(t time.Time, loc *time.Location) time.Time {
	return time.Date(t.Year(), t.Month(), t.Day(), 0, 0, 0, 0, loc)
}

// FixedPriceFilter — lọc màn CRM → Khuyến mại đồng giá.
type FixedPriceFilter struct {
	Keyword  string // mã hoặc tên
	FromDate string // YYYY-MM-DD — chương trình có hiệu lực ngày nào trong khoảng
	ToDate   string
	ShopIDs  []uint
	Codes    []string
	Page     int
	PageSize int
}

type FixedPriceRepository interface {
	List(ctx context.Context, f FixedPriceFilter) ([]FixedPrice, int64, error)
	FindByID(ctx context.Context, id uint) (*FixedPrice, error)
	// Save tạo mới (ID = 0) hoặc thay TOÀN BỘ dòng, hàng tặng và chi nhánh của
	// một chương trình, trong một giao dịch.
	Save(ctx context.Context, f *FixedPrice) error
	SetStatus(ctx context.Context, id uint, on bool) error
	SetApproved(ctx context.Context, id uint, on bool) error
	Delete(ctx context.Context, id uint) error
	// MaKeTiep: mã chương trình tiếp theo của cửa hàng (DG00001, DG00002…).
	MaKeTiep(ctx context.Context) (string, error)
	// Codes: mọi mã chương trình của cửa hàng — ô lọc "Mã khuyến mãi".
	Codes(ctx context.Context) ([]string, error)
	// DangBat trả mọi chương trình đang bật (kèm dòng, chi nhánh) — nguồn cho
	// lượt kiểm trùng và cho quầy (quầy lọc thêm đã duyệt / ngày / chi nhánh).
	DangBat(ctx context.Context) ([]FixedPrice, error)
	// TenSanPham / TenBienThe tra tên cho màn CRM ("Áo thun (Đỏ / M)").
	TenSanPham(ctx context.Context, ids []uint) (map[uint]string, error)
	TenBienThe(ctx context.Context, ids []uint) (map[uint]string, error)
	// DanhMucSanPham: sản phẩm → danh mục trực tiếp (kiểm trùng nhóm ↔ sản phẩm).
	DanhMucSanPham(ctx context.Context, ids []uint) (map[uint]uint, error)
	// ChiNhanhQuay là chi nhánh đang bán của request (đường tính của quầy).
	ChiNhanhQuay(ctx context.Context) uint
}

var (
	ErrFixedPriceDaDuyet = errors.New("Chương trình khuyến mãi đã duyệt, không được xoá.")
	ErrFixedPriceTrung   = errors.New("Món hàng hoặc nhóm hàng đã có trong chương trình đồng giá khác đang bật.")
	// ErrDongGiaKhongGopKM: đơn dùng đồng giá thì không cộng thêm voucher, giảm
	// tay hay khuyến mãi thường — đúng luật quầy của v2.
	ErrDongGiaKhongGopKM = errors.New("Đơn dùng đồng giá thì không dùng thêm mã giảm giá hay giảm tay.")
)
