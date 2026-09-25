package service

import (
	"strings"
	"time"

	"sass-api/internal/domain"
)

// CHỐNG GHI ĐÈ KHI HAI NGƯỜI CÙNG SỬA MỘT PHIẾU.
//
// Khoá dòng (SELECT ... FOR UPDATE) trong giao dịch sửa phiếu giữ cho DỮ LIỆU
// không rách: hai lượt lưu không xen vào giữa nhau, và lượt duyệt chen ngang bị
// chặn bằng kiểm trạng thái trong khoá. Nhưng nó KHÔNG cứu được chuyện này:
//
//	09:00  A mở phiếu PMH-12, thấy 3 dòng hàng
//	09:01  B mở đúng phiếu ấy, cũng thấy 3 dòng
//	09:05  A thêm dòng thứ tư, bấm Lưu — phiếu còn 4 dòng
//	09:07  B sửa số lượng dòng 1, bấm Lưu — phiếu về lại 3 dòng
//
// Dòng A vừa thêm biến mất, không lỗi nào nổi lên, và cả hai đều tin phiếu đang
// đúng. Lượt lưu của B mang theo TOÀN BỘ danh sách hàng nó đọc được lúc 09:01,
// nên nó ghi đè chứ không phải "sửa mỗi dòng 1".
//
// CÁCH CHỮA: client gửi lại mốc `updated_at` của bản nó đang xem. Lệch với mốc
// trong database nghĩa là có người đã lưu sau lúc nó mở phiếu — dừng lại và bảo
// người dùng mở lại phiếu.
//
// Vì sao không dùng cột `version` riêng: `updated_at` đã có sẵn ở mọi bảng phiếu
// và đã nằm trong phản hồi chi tiết, nên không phải thêm cột, thêm migration,
// hay nhớ tăng nó ở từng đường ghi — quên tăng một chỗ là chốt chặn tắt trong im
// lặng, đúng kiểu lỗi mà cả cụm này đang đi chữa.

// chiNhanhTuIDs dựng danh sách chi nhánh RÚT GỌN từ mấy cái id, chỉ để dựng phản
// hồi sau một lượt ghi.
//
// Có mặt vì lượt Create/Update trả về chính bản ghi vừa ghi, mà quan hệ Shops
// của nó thì chưa được nạp — phản hồi sẽ nói "không chi nhánh nào", và ở quy ước
// này câu ấy nghĩa là "áp dụng khắp nơi", tức là ngược hẳn thứ vừa lưu.
//
// Chỉ mang ID: nơi gọi duy nhất là hàm dựng phản hồi, và nó cũng chỉ đọc ID.
// Cần tên chi nhánh thì phải đọc lại từ database, đừng bịa thêm trường ở đây.
//
// Lọc id <= 0 để khớp đúng thứ ReplaceShops thật sự ghi xuống — lệch nhau là
// phản hồi lại nói sai lần nữa, chỉ theo một kiểu khác.
func chiNhanhTuIDs(ids []uint) []domain.ChiNhanh {
	if len(ids) == 0 {
		return nil
	}

	ds := make([]domain.ChiNhanh, 0, len(ids))
	for _, id := range ids {
		if id > 0 {
			ds = append(ds, domain.ChiNhanh{ID: id})
		}
	}

	return ds
}

// kiemBanDangSua so mốc sửa mà client gửi lên với mốc thật của bản ghi.
//
// khai == "" thì BỎ QUA. Đây là chủ ý: giao diện bản cũ chưa gửi trường này, và
// từ chối chúng là khoá luôn đường sửa phiếu của những màn chưa kịp cập nhật.
// Đổi lại, chốt chặn chỉ có tác dụng ở nơi client thật sự gửi — nên chỗ nào bật
// thì phải bật ở CẢ giao diện, không chỉ ở API.
func kiemBanDangSua(khai string, thuc time.Time) error {
	khai = strings.TrimSpace(khai)
	if khai == "" {
		return nil
	}

	moc, err := time.Parse(time.RFC3339Nano, khai)
	if err != nil {
		// Gửi lên một chuỗi không đọc được thì coi như không gửi. Báo lỗi ở đây là
		// biến một cái mốc hỏng thành "không sửa được phiếu nữa", trong khi thứ
		// người dùng cần chỉ là lưu phiếu của họ.
		return nil
	}

	// So theo THỜI ĐIỂM chứ không theo chuỗi: múi giờ và số chữ số lẻ của giây có
	// thể khác nhau giữa hai lượt tuần tự hoá mà vẫn là cùng một mốc.
	//
	// Cắt về mili giây vì cột trong database là DATETIME(3): giữ nguyên nano thì
	// một mốc vừa đi vòng qua JSON có thể lệch với mốc Go đang giữ trong bộ nhớ.
	if moc.Truncate(time.Millisecond).Equal(thuc.Truncate(time.Millisecond)) {
		return nil
	}

	return domain.ErrPhieuVuaBiSua
}
