package domain

import "errors"

// QUY ĐỔI ĐƠN VỊ — khối "Quy đổi đơn vị hàng hoá" ở chân hộp thoại mặt hàng.
//
// Khai "1 Thùng = 24 Cái" để nhập hàng theo thùng mà bán theo cái, kho vẫn đếm
// đúng một loại đơn vị. Giữ đúng cách bản cũ làm: lưu JSON ngay trên dòng mặt
// hàng chứ không dựng bảng riêng — đây là vài dòng đi kèm mặt hàng, luôn đọc và
// ghi trọn cụm, không có ai truy vấn xuyên qua chúng.

// QuyDoiDonVi — một dòng quy đổi: 1 <UnitID> = <Quantity> đơn vị tính chính.
type QuyDoiDonVi struct {
	UnitID uint `json:"unit_id"`
	// Quantity là số đơn vị tính CHÍNH đổi ra từ MỘT đơn vị quy đổi.
	//
	// Số thực chứ không phải số nguyên: "1 Thùng = 0.5 Tạ" là chuyện có thật ở
	// hàng cân, và ép số nguyên thì người khai phải đổi ngược đơn vị chính.
	Quantity float64 `json:"quantity"`
}

// DanhSachQuyDoi là cột JSON trên products.
//
// Ghi/đọc bằng serializer json có sẵn của GORM (xem thẻ gorm ở Product) chứ
// không tự viết Valuer/Scanner: bản tự viết trả nil cho danh sách rỗng, mà GORM
// lấy đúng giá trị ấy làm mẫu để đoán kiểu cột — nil thì nó không đoán được, quay
// ra coi đây là một QUAN HỆ và báo "define a valid foreign key".
type DanhSachQuyDoi []QuyDoiDonVi

var (
	// ErrQuyDoiTrungDonVi — hai dòng quy đổi cùng trỏ vào một đơn vị. Giữ cả hai
	// thì lúc nhập hàng không biết lấy dòng nào.
	ErrQuyDoiTrungDonVi = errors.New("mỗi đơn vị chỉ được khai quy đổi một lần")

	// ErrQuyDoiTrungDonViChinh — khai quy đổi cho chính đơn vị tính của mặt hàng
	// ("1 Cái = 5 Cái").
	ErrQuyDoiTrungDonViChinh = errors.New("không khai quy đổi cho chính đơn vị tính của mặt hàng")

	// ErrQuyDoiSoLuong — số lượng quy đổi phải lớn hơn 0. Để 0 là chia cho 0 ở
	// mọi chỗ tính tồn kho.
	ErrQuyDoiSoLuong = errors.New("số lượng quy đổi phải lớn hơn 0")
)

// KiemTraQuyDoi soát danh sách quy đổi trước khi ghi.
//
// donViChinh là đơn vị tính của mặt hàng (0 = chưa khai).
func KiemTraQuyDoi(ds DanhSachQuyDoi, donViChinh uint) error {
	daCo := make(map[uint]bool, len(ds))
	for _, d := range ds {
		if d.UnitID == 0 {
			continue
		}
		if d.Quantity <= 0 {
			return ErrQuyDoiSoLuong
		}
		if donViChinh > 0 && d.UnitID == donViChinh {
			return ErrQuyDoiTrungDonViChinh
		}
		if daCo[d.UnitID] {
			return ErrQuyDoiTrungDonVi
		}
		daCo[d.UnitID] = true
	}
	return nil
}
