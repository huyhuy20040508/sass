package repository

import (
	"context"

	"gorm.io/gorm"

	"sass-api/internal/domain"
)

// conDong phân biệt "KHÔNG CÓ dòng nào khớp" với "CÓ dòng nhưng không đổi giá
// trị nào", sau một lệnh UPDATE trả về RowsAffected = 0.
//
// MySQL đếm số dòng ĐỔI chứ không phải số dòng KHỚP (driver không bật
// CLIENT_FOUND_ROWS), nên `RowsAffected == 0` mang HAI nghĩa hoàn toàn khác
// nhau. Coi cả hai là "không tìm thấy" thì bấm "Đang bán" cho sản phẩm vốn đã
// đang bán sẽ nhận về "Không tìm thấy dữ liệu" — người dùng nhìn thấy một lỗi
// bịa, và nếu tin nó thì tưởng sản phẩm vừa biến mất.
//
// Đây KHÔNG phải chuyện làm sạch thông báo lỗi: mọi nút bật/tắt trong trang quản
// trị đều gửi trạng thái ĐÍCH chứ không gửi "đảo trạng thái", nên đặt lại đúng
// giá trị đang có là thao tác thường ngày (bấm hai lần, hai người cùng bấm, tải
// lại trang rồi bấm). Nó phải thành công và im lặng.
//
// Trả về ErrNotFound chỉ khi dòng thật sự không có — kể cả khi nó tồn tại nhưng
// thuộc cửa hàng khác, vì câu đếm dưới đây cũng đi qua bộ lọc tenant.
func conDong(ctx context.Context, db *gorm.DB, model any, id uint) error {
	var n int64
	if err := db.WithContext(ctx).Model(model).Where("id = ?", id).Count(&n).Error; err != nil {
		return err
	}
	if n == 0 {
		return domain.ErrNotFound
	}

	return nil
}
