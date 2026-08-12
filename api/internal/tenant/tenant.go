// Package tenant mang danh tính KHÁCH HÀNG đang được phục vụ đi xuyên qua các
// tầng bằng context.Context.
//
// VÌ SAO LÀ CONTEXT chứ không phải một tham số `tenantID uint` truyền tay qua
// từng hàm: tham số truyền tay chỉ đúng khi KHÔNG ai quên. Ở đây có hơn hai trăm
// câu truy vấn nằm rải trong hai mươi repository, và người viết chỗ thứ hai trăm
// lẻ một sẽ là người không đọc tệp này. Context đi kèm mọi lượt gọi sẵn rồi
// (repository nào cũng đã `WithContext(ctx)`), nên plugin GORM đọc được tenant ở
// TẦNG DƯỚI CÙNG — chỗ mà không câu truy vấn nào đi vòng qua được.
//
// Không có hàm nào ở đây đọc/ghi database. Gói này chỉ là chỗ CẤT và LẤY một con
// số, để cả middleware (bên trên) lẫn plugin GORM (bên dưới) dùng chung mà không
// tạo phụ thuộc vòng.
package tenant

import "context"

type ctxKey int

const (
	keyID ctxKey = iota
	keySkip
)

// WithID gắn cửa hàng đang được phục vụ vào ctx.
//
// Từ đây trở xuống, mọi câu truy vấn chạy bằng ctx này đều bị plugin GORM chèn
// thêm `WHERE tenant_id = <id>`. Gọi hàm này là hành động CẤP QUYỀN đọc dữ liệu
// của một khách hàng, nên chỉ có hai chỗ được phép gọi:
//
//   - middleware xác thực, sau khi đã xác minh chữ ký token;
//   - luồng đăng nhập, sau khi đã tra ra cửa hàng theo mã khách gõ.
//
// id = 0 KHÔNG được cất: không có cửa hàng nào mang số 0, nên nhận nó nghĩa là
// nơi gọi đang truyền một giá trị chưa khởi tạo. Cất vào thì truy vấn lặng lẽ
// lọc `tenant_id = 0` và trả về danh sách rỗng — sai mà trông như "chưa có dữ
// liệu". Bỏ qua thì ctx coi như không có tenant, và plugin sẽ báo lỗi thẳng.
func WithID(ctx context.Context, id uint) context.Context {
	if id == 0 {
		return ctx
	}

	return context.WithValue(ctx, keyID, id)
}

// ID trả về cửa hàng gắn trong ctx. ok = false nghĩa là CHƯA XÁC ĐỊNH được khách
// hàng — người gọi phải coi đó là lỗi, không phải là "lấy tất cả".
func ID(ctx context.Context) (uint, bool) {
	id, ok := ctx.Value(keyID).(uint)

	return id, ok && id != 0
}

// WithoutScope TẮT bộ lọc tenant cho nhánh ctx này.
//
// Đây là cửa duy nhất đi vòng qua ranh giới bảo mật của cả hệ thống, nên nó cố ý
// KHÓ DÙNG và DỄ TÌM: bắt buộc khai lý do bằng chữ, và tên hàm đủ lạ để
// `grep -rn "WithoutScope"` liệt kê được toàn bộ chỗ dùng trong vài giây. Danh
// sách đó phải luôn ngắn và mỗi dòng phải giải thích được.
//
// Chỉ dùng cho việc CỦA NỀN TẢNG, tức việc mà bản chất là bắc qua nhiều khách
// hàng (khu điều hành, công cụ dòng lệnh, tác vụ bảo trì). TUYỆT ĐỐI không dùng
// để "chữa" một câu truy vấn đang báo lỗi thiếu tenant trong luồng phục vụ
// request: lỗi đó nói rằng tenant chưa được xác định đúng ở tầng trên, và tắt
// bộ lọc đi chính là biến nó thành rò dữ liệu.
//
// Lý do rỗng bị coi như không tắt: một chuỗi rỗng không giải thích được điều gì
// cho người đọc log lúc sự cố.
func WithoutScope(ctx context.Context, reason string) context.Context {
	if reason == "" {
		return ctx
	}

	return context.WithValue(ctx, keySkip, reason)
}

// SkipReason cho biết ctx này đã được tắt bộ lọc hay chưa, kèm lý do đã khai.
func SkipReason(ctx context.Context) (string, bool) {
	reason, ok := ctx.Value(keySkip).(string)

	return reason, ok && reason != ""
}
