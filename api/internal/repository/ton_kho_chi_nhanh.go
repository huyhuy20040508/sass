package repository

import (
	"context"
	"errors"
	"fmt"

	"gorm.io/gorm"

	"sass-api/internal/chinhanh"
	"sass-api/internal/domain"
)

// CỬA DUY NHẤT GHI TỒN KHO.
//
// Từ migration 0005, tồn kho nằm ở `variant_stocks` (mỗi chi nhánh một dòng) và
// `product_variants.stock_quantity` chỉ còn là BẢN CỘNG SẴN của mọi chi nhánh.
// Hai bảng đó phải luôn khớp nhau, mà cách duy nhất giữ được điều đó là không có
// đường nào ghi riêng lẻ vào một trong hai.
//
// Vì vậy: mọi chỗ đụng vào kho — nhập hàng, bán hàng, huỷ đơn, trả hàng, chỉnh
// kho — đều gọi ĐÚNG hàm dưới đây, trong CÙNG giao dịch với chứng từ của nó.
// `grep -rn "ghiTonChiNhanh"` phải liệt kê được toàn bộ chỗ hàng hoá đổi số.
//
// KHÔNG viết `Update("stock_quantity", …)` ở bất cứ đâu nữa. Câu lệnh đó vẫn
// chạy, vẫn đổi đúng con số người ta nhìn thấy trên màn hình, và để lại một
// chi nhánh có sổ kho sai — sai theo kiểu chỉ lộ ra lúc có người đếm hàng thật.

// chiNhanhCuaRequest trả về chi nhánh mà lượt ghi kho này thuộc về.
//
// Ưu tiên chi nhánh ĐANG LÀM VIỆC trong ctx (Shop Admin gửi lên ở mỗi request).
// Không có thì rơi về chi nhánh bán online — và đó là nhánh chạy thường xuyên
// nhất chứ không phải trường hợp hiếm: cửa hàng chỉ có MỘT chi nhánh thì màn
// hình không có gì để chọn và cũng không gửi gì lên.
//
// Rơi về như vậy an toàn vì nó vẫn nằm trong đúng cửa hàng của ctx (câu truy vấn
// đi qua bộ lọc tenant). Cái không được phép là rơi về một hằng số — `shop_id`
// mặc định 1 dưới database đúng với đúng một khách hàng đầu tiên và sai với mọi
// khách sau đó.
func chiNhanhCuaRequest(ctx context.Context, db *gorm.DB) (uint, error) {
	if id, ok := chinhanh.ID(ctx); ok {
		return id, nil
	}

	var cn domain.ChiNhanh
	err := db.WithContext(ctx).Model(&domain.ChiNhanh{}).
		Where("is_active = ?", true).Order("id ASC").Take(&cn).Error
	if errors.Is(err, gorm.ErrRecordNotFound) {
		// Cửa hàng không còn chi nhánh nào đang mở. Không thể xảy ra qua đường bình
		// thường (ChiNhanhService chặn đóng cái cuối cùng), nhưng nếu có thì phải
		// dừng ở đây: không có kho nào để ghi hàng vào.
		return 0, domain.ErrNotFound
	}
	if err != nil {
		return 0, err
	}

	return cn.ID, nil
}

// ghiTonChiNhanh cộng delta vào tồn của MỘT biến thể TẠI MỘT chi nhánh, rồi dựng
// lại bản cộng sẵn ở product_variants.
//
// Trả về (trước, sau) THEO CHI NHÁNH ĐÓ — đó mới là cặp số mà bút toán kho phải
// ghi. Ghi cặp số của cả cửa hàng vào sổ của một chi nhánh thì lịch sử kho đọc
// lên vô nghĩa: "trước 40, sau 41" trong khi kho đó chỉ có 3 cái.
//
// tx PHẢI là giao dịch đã KHOÁ biến thể (SELECT ... FOR UPDATE) như mọi nơi gọi
// đang làm. Không khoá thì hai lượt bán cùng lúc cùng đọc một con số rồi cùng
// ghi đè — bán quá số hàng đang có mà không lỗi nào nổi lên.
//
// choPhepAm = false: tồn xuống dưới 0 thì trả ErrOutOfStock và giao dịch phải
// cuộn lại. Cho phép âm chỉ dành cho lượt TRẢ HÀNG VỀ (delta > 0 nên không chạm
// mốc đó) và cho dữ liệu cũ vốn đã âm sẵn.
func ghiTonChiNhanh(tx *gorm.DB, shopID, variantID uint, delta int, choPhepAm bool) (truoc, sau int, err error) {
	if shopID == 0 {
		// Lỗi LẬP TRÌNH, không phải lỗi người dùng: nơi gọi chưa xác định được chi
		// nhánh mà vẫn ghi kho. Hỏng ồn ào ở đây tốt hơn hẳn một dòng tồn kho rơi
		// vào chi nhánh do database tự đoán.
		return 0, 0, fmt.Errorf("ghi tồn kho mà chưa biết chi nhánh nào (biến thể %d)", variantID)
	}

	var dong domain.TonKhoChiNhanh
	// Unscoped: biến thể có thể đã xoá mềm mà hàng vẫn nằm trong kho — luồng huỷ
	// đơn phải trả được hàng về. Bản thân variant_stocks không có xoá mềm; điều
	// kiện tenant vẫn do plugin chèn.
	err = tx.Where("shop_id = ? AND product_variant_id = ?", shopID, variantID).
		Take(&dong).Error
	switch {
	case err == gorm.ErrRecordNotFound:
		// Chưa có dòng = chi nhánh này chưa từng có hàng của biến thể đó. Đây là
		// trạng thái BÌNH THƯỜNG (chi nhánh mới mở, hàng mới nhập lần đầu), nên
		// dựng dòng mới từ 0 chứ đừng coi là lỗi.
		dong = domain.TonKhoChiNhanh{ShopID: shopID, ProductVariantID: variantID}
	case err != nil:
		return 0, 0, err
	}

	truoc = dong.Quantity
	sau = truoc + delta
	if sau < 0 && !choPhepAm {
		return truoc, truoc, domain.ErrOutOfStock
	}

	dong.Quantity = sau
	if dong.ID == 0 {
		if err := tx.Create(&dong).Error; err != nil {
			return truoc, truoc, err
		}
	} else if err := tx.Model(&domain.TonKhoChiNhanh{}).
		Where("id = ?", dong.ID).Update("quantity", sau).Error; err != nil {
		return truoc, truoc, err
	}

	if err := dungLaiBanCong(tx, variantID); err != nil {
		return truoc, truoc, err
	}

	return truoc, sau, nil
}

// tonCuaChiNhanh đọc tồn của MỘT LOẠT biến thể TẠI một chi nhánh.
//
// Cặp đôi ĐỌC của ghiTonChiNhanh, và có mặt vì cùng một lý do: chỗ nào TRỪ kho
// của chi nhánh nào thì chỗ HIỆN SỐ cho người dùng cũng phải hỏi đúng chi nhánh
// ấy. Đọc bản cộng `product_variants.stock_quantity` rồi trừ vào một chi nhánh
// là kiểu lệch tệ nhất: màn hình nói còn 10, người dùng chốt phiếu 8, và lượt
// ghi kho từ chối vì kho đó chỉ có 5 — sau khi khách đã trả tiền.
//
// Biến thể chưa có dòng nào ở chi nhánh đó thì KHÔNG có khoá trong map, và nơi
// gọi đọc ra 0 — đúng nghĩa "kho này chưa từng có món hàng ấy", không phải lỗi.
func tonCuaChiNhanh(db *gorm.DB, shopID uint, variantIDs []uint) (map[uint]int, error) {
	ton := make(map[uint]int, len(variantIDs))
	if shopID == 0 || len(variantIDs) == 0 {
		return ton, nil
	}

	var rows []struct {
		ProductVariantID uint
		Quantity         int
	}
	if err := db.Model(&domain.TonKhoChiNhanh{}).
		Where("shop_id = ? AND product_variant_id IN ?", shopID, variantIDs).
		Select("product_variant_id, quantity").
		Scan(&rows).Error; err != nil {
		return nil, err
	}
	for _, r := range rows {
		ton[r.ProductVariantID] = r.Quantity
	}

	return ton, nil
}

// datTonChiNhanh ĐẶT tồn của một biến thể tại một chi nhánh về đúng một con số
// (kiểm kê), thay vì cộng thêm.
//
// Tách khỏi ghiTonChiNhanh vì lượt kiểm kê biết SỐ ĐÍCH chứ không biết chênh
// lệch, mà tự tính chênh lệch ở nơi gọi nghĩa là nơi gọi phải đọc tồn trước —
// đúng một lượt đọc nữa nằm ngoài khoá, tức là đúng chỗ hai người kiểm kê cùng
// lúc ghi đè lên nhau.
func datTonChiNhanh(tx *gorm.DB, shopID, variantID uint, soDich int, choPhepAm bool) (truoc, sau int, err error) {
	if soDich < 0 && !choPhepAm {
		return 0, 0, domain.ErrOutOfStock
	}

	truoc, _, err = ghiTonChiNhanh(tx, shopID, variantID, 0, true)
	if err != nil {
		return 0, 0, err
	}

	return ghiTonChiNhanh(tx, shopID, variantID, soDich-truoc, choPhepAm)
}

// dungLaiBanCong ghi lại product_variants.stock_quantity = tổng mọi chi nhánh.
//
// Cộng lại từ đầu chứ không cộng dồn delta: hai cách cho cùng kết quả khi mọi
// thứ chạy đúng, nhưng chỉ cách này TỰ CHỮA khi có gì đó lệch (một dòng ghi
// tay dưới database, một lượt migration chạy dở). Chi phí là một câu SUM trên
// vài dòng có index — không đáng để đổi lấy một con số trôi dần theo thời gian
// mà không ai đối chiếu lại được.
//
// Unscoped ở lượt UPDATE: biến thể đã xoá mềm vẫn phải cập nhật bản cộng, nếu
// không thì hàng trả về của một đơn cũ nằm trong variant_stocks mà con số hiển
// thị đứng im.
func dungLaiBanCong(tx *gorm.DB, variantID uint) error {
	var tong int
	if err := tx.Model(&domain.TonKhoChiNhanh{}).
		Where("product_variant_id = ?", variantID).
		Select("COALESCE(SUM(quantity), 0)").Scan(&tong).Error; err != nil {
		return err
	}

	return tx.Unscoped().Model(&domain.ProductVariant{}).
		Where("id = ?", variantID).
		Update("stock_quantity", tong).Error
}
