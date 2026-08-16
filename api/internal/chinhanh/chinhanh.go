// Package chinhanh mang CHI NHÁNH ĐANG LÀM VIỆC đi xuyên các tầng bằng
// context.Context.
//
// Cặp song sinh của package tenant, và phải đọc kèm nó — nhưng hai thứ này KHÁC
// NHAU VỀ BẢN CHẤT, lẫn lộn là hỏng nặng:
//
//   - tenant là RANH GIỚI BẢO MẬT giữa các khách hàng. Thiếu nó thì câu truy vấn
//     phải HỎNG, và plugin GORM tự chèn điều kiện vào mọi câu để không ai quên.
//   - chi nhánh là phạm vi làm việc BÊN TRONG một khách hàng. Thiếu nó không
//     phải lỗi bảo mật: người dùng chỉ đang không đứng ở chi nhánh nào cụ thể,
//     và nơi gọi phải tự quyết định làm gì (thường là rơi về chi nhánh gốc, hoặc
//     nhìn gộp mọi chi nhánh).
//
// Vì khác nhau như vậy nên KHÔNG có plugin GORM nào tự chèn `shop_id`: một câu
// truy vấn quên chi nhánh vẫn trả về dữ liệu đúng của khách hàng đó, chỉ là gộp
// cả cửa hàng thay vì một điểm bán. Chỗ nào cần cắt theo chi nhánh thì phải tự
// khai, và tự khai được vì danh sách đó ngắn: kho, bút toán kho, và chứng từ.
//
// Giá trị ở đây LUÔN đã được xác minh thuộc về tenant trong cùng ctx —
// middleware.ChiNhanhDangLam là chỗ duy nhất đặt nó vào, và nó tra sổ chi nhánh
// trước khi đặt. Nhận id thẳng từ header rồi tin luôn nghĩa là chủ tiệm này gõ
// một con số và ghi hàng vào kho của tiệm khác.
package chinhanh

import "context"

type ctxKey int

const keyID ctxKey = iota

// WithID gắn chi nhánh đang làm việc vào ctx.
//
// id = 0 KHÔNG được cất: không chi nhánh nào mang số 0, nên nhận nó nghĩa là nơi
// gọi đang truyền một giá trị chưa khởi tạo. Cất vào thì lượt ghi kho sau đó rơi
// vào "chi nhánh 0" — một dòng variant_stocks trỏ vào hư không mà khoá ngoại sẽ
// từ chối, và người dùng nhận một lỗi 500 không nói lên điều gì.
func WithID(ctx context.Context, id uint) context.Context {
	if id == 0 {
		return ctx
	}

	return context.WithValue(ctx, keyID, id)
}

// ID trả về chi nhánh đang làm việc.
//
// ok = false nghĩa là request này KHÔNG đứng ở chi nhánh nào: khách vãng lai
// ngoài storefront, lượt quét nền, hay người dùng chọn "tất cả chi nhánh". Nơi
// gọi phải xử lý trường hợp đó chứ đừng coi là lỗi — xem chú thích đầu gói.
func ID(ctx context.Context) (uint, bool) {
	id, ok := ctx.Value(keyID).(uint)

	return id, ok && id != 0
}
