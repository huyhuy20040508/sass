package domain

// HẠN MỨC HỢP ĐỒNG — ba con số đã chốt lúc ký, và chỗ ép chúng.
//
// max_shops / max_users / max_products có mặt trong Subscription từ ngày dựng
// control plane: bảng đối chiếu lúc ký in ra chúng, trang "Gói dịch vụ" của chủ
// tiệm in ra chúng. Nhưng suốt từ đó tới nay chúng chỉ là CHỮ — không chỗ nào từ
// chối lượt tạo thứ 21 của một gói bán 20 tài khoản. Hệ quả không nằm ở kỹ
// thuật mà nằm ở chỗ bán hàng: ba gói giá khác nhau cho ra đúng một phần mềm
// giống hệt nhau, nên gói cao chưa có lý do tồn tại.
//
// CHỖ ÉP LÀ DATA PLANE, ngay trước lượt ghi. Đó là nơi duy nhất biết cửa hàng
// đang có bao nhiêu cái; sổ nền tảng chỉ biết được phép có bao nhiêu. Thứ từng
// chặn việc này — "bên đó không đọc được sổ nền tảng" — hết hiệu lực từ lúc có
// ThueBaoCuaKhachRepository: một cửa hẹp một chiều, đúng một khách, đúng phần
// mềm mà tiến trình đang chạy.
//
// KHÔNG ép ở database bằng ràng buộc: trần nằm ở lược đồ KHÁC (control plane),
// và một CHECK không bắc qua hai database được. Nghĩa là hai lượt tạo chạy song
// song vẫn có thể cùng lọt qua và vượt trần đúng một cái. Chấp nhận: đây là
// điều khoản bán hàng, không phải ranh giới bảo mật — lố một sản phẩm không mất
// gì, còn khoá nhầm cửa hàng của người đang trả tiền thì mất thật.

// LoaiHanMuc là một trong ba hạn mức của hợp đồng.
//
// Kiểu riêng chứ không phải chuỗi trần: nơi gọi là các service tạo dữ liệu, và
// truyền nhầm "san_pham" vào chỗ đếm tài khoản là lỗi mà trình biên dịch phải
// bắt được.
type LoaiHanMuc string

const (
	// HanMucTaiKhoan đếm TÀI KHOẢN NỘI BỘ (super_admin, admin, staff) — người
	// đăng nhập vào phần mềm. Khách mua hàng ở storefront KHÔNG tính: họ là dữ
	// liệu kinh doanh của tiệm, không phải chỗ ngồi mà nhà cung cấp bán.
	HanMucTaiKhoan LoaiHanMuc = "tai_khoan"

	// HanMucSanPham đếm MỌI sản phẩm còn trong sổ, kể cả đang ẩn hay ngừng bán:
	// chúng vẫn chiếm chỗ trong kho dữ liệu và vẫn hiện ở trang quản trị. Chỉ
	// đếm hàng đang bán thì tắt hiển thị một sản phẩm trở thành cách lách hạn
	// mức, mà lách bằng đúng một cú bấm.
	HanMucSanPham LoaiHanMuc = "san_pham"

	// HanMucChiNhanh đếm chi nhánh (`shops`) — các ĐIỂM BÁN nằm trong một cửa
	// hàng, không phải cửa hàng.
	//
	// Đây là hạn mức mà gói Chuỗi bán: hai gói dưới chốt một chi nhánh, và cửa
	// hàng nào cũng được dựng sẵn đúng một chi nhánh 'mac-dinh' lúc mở tài khoản
	// — nên với hai gói đó, lượt mở chi nhánh ĐẦU TIÊN đã là lượt vượt trần.
	//
	// Tính cả chi nhánh đang ngừng hoạt động: nó còn giữ mã và mở lại là một cú
	// bấm (xem ChiNhanhRepository.Count).
	HanMucChiNhanh LoaiHanMuc = "chi_nhanh"
)

// Ten là tên tiếng Việt của hạn mức, để ghép vào câu người dùng đọc ("đã dùng
// hết 20 tài khoản của gói").
func (l LoaiHanMuc) Ten() string {
	switch l {
	case HanMucTaiKhoan:
		return "tài khoản"
	case HanMucSanPham:
		return "sản phẩm"
	case HanMucChiNhanh:
		return "chi nhánh"
	}

	return string(l)
}

// SoDangDung là số thứ cửa hàng ĐANG CÓ, để đặt cạnh trần đã ký.
//
// Đủ cả ba hạn mức: mỗi trường ở đây phải có một chỗ đếm thật đứng sau, và thêm
// trường trước khi có chỗ đếm là in một con số bịa ra màn hình hợp đồng.
type SoDangDung struct {
	TaiKhoan int64
	SanPham  int64
	ChiNhanh int64
}

// TranCua đọc trần của một hạn mức từ chính dòng hợp đồng.
//
// ĐỌC THẲNG Ở HỢP ĐỒNG, không tra sang bảng giá qua PlanID — xem chú thích ở
// Subscription.PlanID. Bảng giá được phép đổi, hợp đồng đã ký thì không, nên
// tra ngược nghĩa là lần sửa bảng giá tới sẽ đổi luôn hạn mức của người đang
// trả tiền.
//
// 0 = KHÔNG GIỚI HẠN (bản dịch của 'vo_han' bên bảng giá), khác hẳn "không được
// cái nào". Nơi gọi phải xét giá trị 0 trước khi so sánh.
func (s Subscription) TranCua(loai LoaiHanMuc) uint {
	switch loai {
	case HanMucTaiKhoan:
		return s.MaxUsers
	case HanMucSanPham:
		return s.MaxProducts
	case HanMucChiNhanh:
		return s.MaxShops
	}

	// Hạn mức lạ: coi như không giới hạn. Chặn một lượt tạo vì một khoá gõ sai
	// trong code là đem lỗi lập trình đổ lên đầu người đang bán hàng.
	return 0
}
