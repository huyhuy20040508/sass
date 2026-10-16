package dto

import (
	"encoding/json"
	"time"
)

// POSNhapItem — một dòng của hoá đơn đang mở, đủ để máy khác vẽ lại tab.
//
// Tên, ảnh, giá ở đây chỉ để HIỂN THỊ: lượt chốt tra lại giá từ database theo
// product_variant_id, không đọc giá từ nháp.
type POSNhapItem struct {
	ProductVariantID uint    `json:"product_variant_id" binding:"required"`
	Quantity         int     `json:"quantity" binding:"required,min=1,max=99"`
	DiscountPercent  float64 `json:"discount_percent" binding:"omitempty,min=0,max=100"`
	ProductName      string  `json:"product_name" binding:"omitempty,max=255"`
	VariantName      string  `json:"variant_name" binding:"omitempty,max=255"`
	SKU              string  `json:"sku" binding:"omitempty,max=100"`
	Thumbnail        string  `json:"thumbnail" binding:"omitempty,max=500"`
	UnitPrice        float64 `json:"unit_price" binding:"omitempty,min=0"`
	Vat              float64 `json:"vat" binding:"omitempty,min=0,max=100"`
	IsGift           bool    `json:"is_gift"`
}

// POSNhapRequest — tạo / ghi đè hoá đơn đang mở. Gửi NGUYÊN hoá đơn mỗi lần (ghi
// đè cả giỏ), không gửi phần chênh: máy quầy giữ bản đầy đủ, và một lượt lưu bị rớt
// mạng thì lượt sau tự vá lại.
type POSNhapRequest struct {
	Items         []POSNhapItem `json:"items" binding:"max=50,dive"`
	UserID        uint          `json:"user_id"`
	CustomerName  string        `json:"customer_name" binding:"omitempty,max=100"`
	CustomerPhone string        `json:"customer_phone" binding:"omitempty,max=20"`
	Note          string        `json:"note" binding:"omitempty,max=500"`
	// TamTinh: tổng màn quầy đang hiện, để danh sách / lịch sử có con số mà không
	// phải tính lại cả mạch khuyến mãi. Chỉ để xem.
	TamTinh float64 `json:"tam_tinh" binding:"omitempty,min=0"`
	// DuLieu: phần còn lại của hoá đơn trên màn quầy (giảm cả đơn, mã giảm, khuyến
	// mãi đã chọn, phụ thu, thông tin hoá đơn điện tử…) — để mở lại ở máy khác vẫn đủ.
	// API không đọc bên trong; giới hạn kích thước ở service.
	DuLieu json.RawMessage `json:"du_lieu" swaggertype:"object"`
}

// POSHuyNhapRequest — huỷ hoá đơn đang mở: bắt buộc lý do (như v2).
type POSHuyNhapRequest struct {
	LyDo string `json:"ly_do" binding:"required,min=2,max=255"`
}

// POSNhapResponse — một đơn nháp.
type POSNhapResponse struct {
	ID            uint            `json:"id"`
	OrderCode     string          `json:"order_code"`
	Status        string          `json:"status"`
	CreatedBy     uint            `json:"created_by"`
	CreatedByName string          `json:"created_by_name"`
	UserID        *uint           `json:"user_id"`
	CustomerName  string          `json:"customer_name"`
	CustomerPhone string          `json:"customer_phone"`
	Note          string          `json:"note"`
	ItemCount     int             `json:"item_count"`
	TamTinh       float64         `json:"tam_tinh"`
	Items         []POSNhapItem   `json:"items"`
	DuLieu        json.RawMessage `json:"du_lieu" swaggertype:"object"`
	OrderID       *uint           `json:"order_id"`
	CancelReason  string          `json:"cancel_reason"`
	CancelledAt   *time.Time      `json:"cancelled_at"`
	ConvertedAt   *time.Time      `json:"converted_at"`
	CreatedAt     time.Time       `json:"created_at"`
	UpdatedAt     time.Time       `json:"updated_at"`
}
