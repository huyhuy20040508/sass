package repository

import (
	"context"

	"gorm.io/gorm"
	"gorm.io/gorm/clause"

	"sass-api/internal/domain"
)

// GIÁ BÁN THEO CHI NHÁNH — cửa đọc/ghi của bảng `variant_shop_prices`.
//
// THIẾU DÒNG = DÙNG GIÁ GỐC. Đó là toàn bộ hợp đồng của bảng này, và nó cùng
// một luật với `product_shops` (rỗng = mọi chi nhánh) và với quy tắc đánh số
// (không có dòng thì rơi về mã mặc định) — xem migration 0051.
//
// Nghĩa là mọi nơi ĐỌC giá phải hỏi bảng này TRƯỚC rồi mới rơi về giá gốc, và
// không nơi nào được phép giả định "chưa khai" là 0.

// giaTheoChiNhanh đọc giá riêng của một chi nhánh cho một loạt biến thể.
//
// Khoá thiếu trong map = chi nhánh này KHÔNG khai giá riêng cho biến thể đó, và
// nơi gọi phải dùng giá gốc. Trả về map rỗng khi shopID = 0 (không đứng ở chi
// nhánh nào — gian hàng công khai, báo cáo toàn cửa hàng): lúc ấy "giá của chi
// nhánh nào" không có câu trả lời, nên giá gốc là câu đúng.
func giaTheoChiNhanh(db *gorm.DB, shopID uint, variantIDs []uint) (map[uint]float64, error) {
	gia := make(map[uint]float64, len(variantIDs))
	if shopID == 0 || len(variantIDs) == 0 {
		return gia, nil
	}

	var rows []struct {
		ProductVariantID uint
		Price            float64
	}
	err := db.Table("variant_shop_prices").
		Select("product_variant_id, price").
		Where("shop_id = ? AND product_variant_id IN ?", shopID, variantIDs).
		Scan(&rows).Error
	if err != nil {
		return nil, err
	}
	for _, r := range rows {
		gia[r.ProductVariantID] = r.Price
	}

	return gia, nil
}

type giaChiNhanhRepository struct{ db *gorm.DB }

func NewGiaChiNhanhRepository(db *gorm.DB) domain.GiaChiNhanhRepository {
	return &giaChiNhanhRepository{db: db}
}

func (r *giaChiNhanhRepository) TheoBienThe(
	ctx context.Context, variantID uint,
) ([]domain.GiaChiNhanh, error) {
	var list []domain.GiaChiNhanh
	err := r.db.WithContext(ctx).
		Where("product_variant_id = ?", variantID).
		Order("shop_id ASC").
		Find(&list).Error
	if err != nil {
		return nil, err
	}

	return list, nil
}

// Dat khai giá riêng cho một cặp (chi nhánh, biến thể).
//
// Upsert theo khoá duy nhất chứ không đọc-rồi-ghi: hai người cùng khai một cặp
// thì lượt sau đè lên lượt trước, không ai nhận lỗi khoá trùng.
//
// Đi qua Create của GORM chứ KHÔNG phải Exec SQL viết tay: chỉ đường này mới
// được plugin đóng dấu `tenant_id`. SQL tay thì plugin từ chối thẳng, và đúng
// như vậy — một câu INSERT quên tenant_id là dữ liệu của cửa hàng này rơi sang
// cửa hàng khác.
func (r *giaChiNhanhRepository) Dat(ctx context.Context, shopID, variantID uint, gia float64) error {
	dong := domain.GiaChiNhanh{ShopID: shopID, ProductVariantID: variantID, Price: gia}

	return r.db.WithContext(ctx).Clauses(clause.OnConflict{
		Columns:   []clause.Column{{Name: "shop_id"}, {Name: "product_variant_id"}},
		DoUpdates: clause.AssignmentColumns([]string{"price", "updated_at"}),
	}).Create(&dong).Error
}

// Xoa gỡ giá riêng — chi nhánh trở lại dùng giá gốc.
//
// KHÔNG có lỗi "không tìm thấy": gỡ một thứ vốn không có là kết quả đúng như
// người dùng muốn, và bắt họ phân biệt hai trường hợp ấy chẳng để làm gì.
func (r *giaChiNhanhRepository) Xoa(ctx context.Context, shopID, variantID uint) error {
	return r.db.WithContext(ctx).
		Where("shop_id = ? AND product_variant_id = ?", shopID, variantID).
		Delete(&domain.GiaChiNhanh{}).Error
}
