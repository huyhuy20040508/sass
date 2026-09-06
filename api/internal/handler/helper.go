// Package handler là tầng HTTP (Gin) — chỉ nhận request, gọi service, trả response.
package handler

import (
	"errors"
	"strconv"
	"strings"

	"sass-api/internal/domain"
	"sass-api/internal/middleware"
	"sass-api/internal/service"
	"sass-api/pkg/response"

	"github.com/gin-gonic/gin"
	"github.com/go-playground/validator/v10"
)

// bindJSON parse body JSON, tự trả lỗi 422 nếu không hợp lệ.
func bindJSON(c *gin.Context, req interface{}) bool {
	if err := c.ShouldBindJSON(req); err != nil {
		var ve validator.ValidationErrors
		if errors.As(err, &ve) {
			out := make(map[string]string, len(ve))
			for _, fe := range ve {
				out[fe.Field()] = validationMessage(fe)
			}
			response.ValidationError(c, out)
		} else {
			response.Error(c, 400, "Dữ liệu gửi lên không hợp lệ")
		}
		return false
	}
	return true
}

func validationMessage(fe validator.FieldError) string {
	switch fe.Tag() {
	case "required":
		return "Trường này là bắt buộc"
	case "email":
		return "Email không hợp lệ"
	case "min":
		return "Độ dài tối thiểu là " + fe.Param()
	case "max":
		return "Độ dài tối đa là " + fe.Param()
	default:
		return "Giá trị không hợp lệ"
	}
}

// parseUintParam đọc tham số path dạng uint.
func parseUintParam(c *gin.Context, name string) (uint, bool) {
	v, err := strconv.ParseUint(c.Param(name), 10, 64)
	if err != nil {
		response.Error(c, 400, "Tham số "+name+" không hợp lệ")
		return 0, false
	}
	return uint(v), true
}

// currentUserID lấy user id đã set bởi middleware JWTAuth.
func currentUserID(c *gin.Context) uint {
	if v, ok := c.Get(middleware.CtxUserID); ok {
		if id, ok := v.(uint); ok {
			return id
		}
	}
	return 0
}

// voucherUseError trả lời các lỗi khi KHÁCH dùng mã giảm giá — khác hẳn nhóm lỗi
// lúc quản trị tạo/sửa mã (trùng mã, sai định dạng…) nằm trong handleServiceError.
//
// Trả về true nghĩa là err thuộc nhóm này và response đã ghi xong. Gom vào một hàm
// vì cả ô nhập mã ở giỏ hàng lẫn lúc bấm đặt đều phải trả về CÙNG một câu chữ:
// khách thấy "hết lượt" lúc gõ mã rồi thấy câu khác lúc đặt là tưởng hai lỗi khác nhau.
//
// Mỗi lỗi nói rõ phải làm gì tiếp, vì cách xử lý khác hẳn nhau — bỏ mã đi, chờ tới
// ngày, hay mua thêm cho đủ đơn tối thiểu.
func voucherUseError(c *gin.Context, err error) bool {
	switch {
	case errors.Is(err, domain.ErrVoucherNotFound):
		response.Error(c, 422, "Mã giảm giá không tồn tại, vui lòng kiểm tra lại")
	case errors.Is(err, domain.ErrVoucherInactive):
		response.Error(c, 422, "Mã giảm giá này đang tạm dừng")
	case errors.Is(err, domain.ErrVoucherNotStarted):
		response.Error(c, 422, "Mã giảm giá chưa tới ngày sử dụng")
	case errors.Is(err, domain.ErrVoucherExpired):
		response.Error(c, 422, "Mã giảm giá đã hết hạn")
	case errors.Is(err, domain.ErrVoucherOutOfUses):
		response.Error(c, 422, "Mã giảm giá đã hết lượt sử dụng")
	case errors.Is(err, domain.ErrVoucherUserLimitReached):
		response.Error(c, 422, "Bạn đã dùng hết số lượt của mã này")
	case errors.Is(err, domain.ErrVoucherMinOrder):
		// err đã kèm số tiền còn thiếu
		response.Error(c, 422, "Đơn hàng chưa đủ điều kiện dùng mã — cần mua thêm "+
			strings.TrimPrefix(err.Error(), domain.ErrVoucherMinOrder.Error()+": "))
	default:
		return false
	}
	return true
}

// loiChiNhanh trả lời ba lỗi chi nhánh bằng đúng câu chữ của handleServiceError.
//
// Mỗi handler có mapper lỗi riêng (respondOrderError, respondInventoryError…),
// và cả năm cái đều quên ba lỗi này: chúng rơi xuống nhánh mặc định và thành
// 500 kèm câu chung chung. Mở một đơn của chi nhánh khác nhận về "Lỗi truy vấn
// đơn hàng" — đọc lên tưởng API hỏng, trong khi câu trả lời đúng là "bạn không
// làm việc tại chi nhánh này" và người dùng chỉ cần đổi chi nhánh ở thanh trên.
//
// Gọi ở ĐẦU mỗi mapper, cùng lối với voucherUseError.
func loiChiNhanh(c *gin.Context, err error) bool {
	switch {
	case errors.Is(err, domain.ErrChuaChonChiNhanh),
		errors.Is(err, domain.ErrChiNhanhDaDong),
		errors.Is(err, domain.ErrKhongThuocChiNhanh):
		handleServiceError(c, err)

		return true
	}

	return false
}

// handleServiceError ánh xạ lỗi nghiệp vụ sang mã HTTP phù hợp.
func handleServiceError(c *gin.Context, err error) {
	// Lỗi theo TỪNG Ô đứng trước mọi nhánh khác: nó mang sẵn tên ô và câu chữ,
	// nên chỉ việc trả 422 y như lỗi validate của binding. Trước đây chỉ handler
	// dùng thử bắt kiểu này, và mọi service khác trả nó về đây đều rơi xuống
	// nhánh mặc định — người dùng nhận 500 cho một lỗi nhập liệu.
	var theoO *service.LoiTheoO
	if errors.As(err, &theoO) {
		response.ValidationError(c, theoO.Fields)

		return
	}

	switch {
	case errors.Is(err, domain.ErrNotFound):
		response.Error(c, 404, "Không tìm thấy dữ liệu")
	case errors.Is(err, domain.ErrEmailExists):
		response.Error(c, 409, "Email đã được sử dụng")
	case errors.Is(err, domain.ErrSlugExists):
		response.Error(c, 409, "Đường dẫn (slug) này đã có sản phẩm khác dùng, vui lòng đổi tên hoặc sửa slug")
	case errors.Is(err, domain.ErrSKUExists):
		response.Error(c, 409, "SKU này đã có sản phẩm khác dùng, vui lòng đặt SKU khác")
	case errors.Is(err, domain.ErrProductStatusInvalid):
		response.ValidationError(c, map[string]string{"status": "Trạng thái sản phẩm không hợp lệ"})
	// Bấm mũi tên khi đã ở đầu/cuối danh sách: nói thẳng, đừng trả "có lỗi xảy ra".
	case errors.Is(err, domain.ErrDaODau):
		response.Error(c, 409, "Mặt hàng đã ở đầu danh sách")
	case errors.Is(err, domain.ErrDaOCuoi):
		response.Error(c, 409, "Mặt hàng đã ở cuối danh sách")
	case errors.Is(err, domain.ErrQuyDoiTrungDonVi):
		response.Error(c, 422, "Mỗi đơn vị chỉ được khai quy đổi một lần")
	case errors.Is(err, domain.ErrQuyDoiTrungDonViChinh):
		response.Error(c, 422, "Không khai quy đổi cho chính đơn vị tính của mặt hàng")
	case errors.Is(err, domain.ErrQuyDoiSoLuong):
		response.Error(c, 422, "Số lượng quy đổi phải lớn hơn 0")
	// Phiếu mua hàng
	case errors.Is(err, domain.ErrPurchaseEmpty):
		response.Error(c, 422, "Phiếu mua hàng phải có ít nhất một dòng hàng")
	case errors.Is(err, domain.ErrPurchaseLocked):
		response.Error(c, 409, "Phiếu đã duyệt hoặc đã huỷ nên không sửa được. "+
			"Muốn chữa số đã vào kho thì cân đối ở màn Tồn kho, nơi có bút toán riêng ghi lại ai sửa và sửa vì sao")
	case errors.Is(err, domain.ErrPurchaseUnitRatio):
		response.Error(c, 422, "Số lượng quy đổi ra đơn vị tính chính phải là số nguyên — đổi số lượng hoặc chọn đơn vị khác")
	case errors.Is(err, domain.ErrPurchaseUnitLa):
		response.Error(c, 422, "Đơn vị mua này chưa khai quy đổi cho mặt hàng")
	case errors.Is(err, domain.ErrPurchasePaidQuaTong):
		response.ValidationError(c, map[string]string{
			"paid_amount": "Số tiền đã trả không được lớn hơn tổng tiền phiếu",
		})
	case errors.Is(err, domain.ErrPurchaseNoThieuHan):
		response.ValidationError(c, map[string]string{
			"debt_due_date": "Ghi nợ thì phải hẹn ngày trả nốt",
		})
	case errors.Is(err, domain.ErrPurchaseNoThieuNguoi):
		response.ValidationError(c, map[string]string{
			"debt_contact_name": "Ghi nợ thì phải có người đại diện bên bán và số điện thoại",
		})
	case errors.Is(err, domain.ErrPurchaseNoDaTraDu):
		response.ValidationError(c, map[string]string{
			"paid_amount": "Đã trả đủ thì không còn gì để ghi nợ",
		})
	// Phiếu điều chuyển — mỗi lỗi một cách chữa khác nhau.
	case errors.Is(err, domain.ErrDieuChuyenEmpty):
		response.Error(c, 422, "Phiếu điều chuyển phải có ít nhất một dòng hàng")
	case errors.Is(err, domain.ErrDieuChuyenLocked):
		response.Error(c, 409, "Phiếu đã duyệt nên không sửa được. "+
			"Kho hai đầu đã đổi theo nó — muốn chữa thì lập phiếu điều chuyển ngược lại")
	case errors.Is(err, domain.ErrDieuChuyenCungKho):
		response.ValidationError(c, map[string]string{
			"to_shop_id": "Kho nhập phải khác kho xuất",
		})
	// err đã kèm tên hoặc id chi nhánh: in nguyên, vì "chi nhánh không hợp lệ"
	// thì người lập phiếu không biết mình chọn sai ô nào trong hai ô.
	case errors.Is(err, domain.ErrDieuChuyenKhoLa):
		response.Error(c, 422, strings.TrimPrefix(err.Error(), domain.ErrDieuChuyenKhoLa.Error()+": "))
	// err đã kèm tên mặt hàng và cặp số còn/đang ghi — in nguyên, vì "không đủ
	// hàng" chung chung thì không biết sửa dòng nào xuống bao nhiêu.
	case errors.Is(err, domain.ErrDieuChuyenThieuTon):
		response.Error(c, 422, "Kho xuất không đủ hàng: "+
			strings.TrimPrefix(err.Error(), domain.ErrDieuChuyenThieuTon.Error()+": "))
	// Phiếu điều chỉnh tồn kho
	case errors.Is(err, domain.ErrDieuChinhEmpty):
		response.Error(c, 422, "Phiếu điều chỉnh phải có ít nhất một dòng hàng có số lệch khác 0")
	case errors.Is(err, domain.ErrDieuChinhLocked):
		response.Error(c, 409, "Phiếu đã duyệt hoặc đã từ chối nên không sửa được. "+
			"Kho đã đổi theo nó — muốn chữa thì lập phiếu điều chỉnh mới")
	case errors.Is(err, domain.ErrDieuChinhSaiTrangThai):
		response.Error(c, 409, "Phiếu không ở trạng thái cho phép thao tác này")
	case errors.Is(err, domain.ErrDieuChinhTrungLo):
		response.Error(c, 422, "Cùng một mặt hàng không được chọn trùng lô trong một phiếu")
	case errors.Is(err, domain.ErrDieuChinhKhongCoHangAm):
		response.Error(c, 404, "Kho đang không có hàng hoá nào âm cả")
	// err đã kèm tên mặt hàng và cặp số — in nguyên, vì "không đủ hàng" chung
	// chung thì không biết sửa dòng nào.
	case errors.Is(err, domain.ErrDieuChinhThieuTon):
		response.Error(c, 422, "Kho không đủ hàng để bớt: "+
			strings.TrimPrefix(err.Error(), domain.ErrDieuChinhThieuTon.Error()+": "))
	// Trả hàng nhà cung cấp
	case errors.Is(err, domain.ErrSupplierReturnEmpty):
		response.Error(c, 422, "Phiếu trả hàng phải có ít nhất một dòng hàng")
	case errors.Is(err, domain.ErrSupplierReturnLocked):
		response.Error(c, 409, "Phiếu trả đã duyệt nên không sửa được. "+
			"Kho đã trừ theo nó, muốn chữa thì cân đối ở màn Tồn kho")
	case errors.Is(err, domain.ErrSupplierReturnNoPurchase):
		response.Error(c, 422, "Phiếu trả phải gắn với một phiếu mua ĐÃ DUYỆT của đúng nhà cung cấp đó")
	case errors.Is(err, domain.ErrSupplierReturnLineLa):
		response.Error(c, 422, "Dòng hàng không thuộc phiếu mua đã chọn")
	case errors.Is(err, domain.ErrSupplierReturnQuaSo):
		response.Error(c, 422, "Số lượng trả vượt quá phần còn được trả của dòng hàng "+
			"(đã trừ phần đã trả ở các phiếu trước và phần kho không còn hàng)")
	case errors.Is(err, domain.ErrSupplierReturnUnitRatio):
		response.Error(c, 422, "Số lượng quy đổi ra đơn vị tính chính phải là số nguyên")
	// Kho không đủ hàng cho lượt ghi sổ — lượt duyệt phiếu trả rơi vào đây.
	case errors.Is(err, domain.ErrOutOfStock):
		response.Error(c, 409, "Kho không còn đủ hàng cho lượt xuất này — kiểm lại tồn kho rồi thử lại")
	case errors.Is(err, domain.ErrHuongKhongHopLe):
		response.ValidationError(c, map[string]string{"huong": "Hướng di chuyển không hợp lệ"})
	case errors.Is(err, domain.ErrConflict):
		response.Error(c, 409, "Dữ liệu đang được tham chiếu hoặc bị trùng, không thể thực hiện")
	case errors.Is(err, domain.ErrInvalidCredentials):
		response.Error(c, 401, "Email hoặc mật khẩu không đúng")
	case errors.Is(err, domain.ErrShopLoginFailed):
		response.Error(c, 401, "Mã cửa hàng, tên đăng nhập hoặc mật khẩu không đúng")
	case errors.Is(err, domain.ErrTenantSuspended):
		response.Error(c, 403, "Cửa hàng đang tạm khoá, vui lòng liên hệ nhà cung cấp phần mềm")
	case errors.Is(err, domain.ErrUsernameExists):
		response.Error(c, 409, "Tên đăng nhập này đã có người dùng, vui lòng đặt tên khác")
	case errors.Is(err, domain.ErrUsernameInvalid):
		response.ValidationError(c, map[string]string{
			"username": "Tên đăng nhập chỉ gồm chữ thường không dấu, số, dấu chấm, gạch ngang hoặc gạch dưới (3–50 ký tự)",
		})
	case errors.Is(err, domain.ErrUserInactive):
		response.Error(c, 403, "Tài khoản đang không hoạt động, vui lòng liên hệ cửa hàng")
	case errors.Is(err, domain.ErrPaymentMethodDisabled):
		response.Error(c, 422, "Cửa hàng hiện không nhận hình thức thanh toán này, vui lòng chọn cách khác")
	case errors.Is(err, domain.ErrForbidden):
		response.Error(c, 403, "Không có quyền thực hiện")
	case errors.Is(err, domain.ErrBannerPositionInvalid):
		response.ValidationError(c, map[string]string{"position": "Vị trí hiển thị không hợp lệ"})
	case errors.Is(err, domain.ErrBannerScheduleInvalid):
		response.ValidationError(c, map[string]string{"end_at": "Ngày kết thúc phải sau ngày bắt đầu"})
	// 422 chứ không phải 401: phiên đăng nhập vẫn tốt, chỉ ô "mật khẩu hiện tại"
	// gõ sai. Trả 401 thì trang quản trị hiểu là token chết và đá người dùng ra
	// màn hình đăng nhập chỉ vì một lần gõ nhầm.
	case errors.Is(err, domain.ErrPasswordIncorrect):
		response.ValidationError(c, map[string]string{"current_password": "Mật khẩu hiện tại không đúng"})
	case errors.Is(err, domain.ErrPasswordSame):
		response.ValidationError(c, map[string]string{"new_password": "Mật khẩu mới trùng với mật khẩu đang dùng"})
	// 409 chứ không phải 422: dữ liệu gửi lên không có gì sai, form không có ô
	// nào để tô đỏ — cửa hàng chỉ đơn giản là hết chỗ trong gói. err đã kèm tên
	// hạn mức và cặp "đang dùng/trần", in nguyên ra vì người đọc cần biết trần
	// là bao nhiêu mới quyết được nên dọn bớt hay nâng gói.
	case errors.Is(err, domain.ErrVuotHanMuc):
		response.Error(c, 409, "Gói dịch vụ của cửa hàng đã hết chỗ ("+
			strings.TrimPrefix(err.Error(), domain.ErrVuotHanMuc.Error()+": ")+
			"). Xoá bớt hoặc liên hệ nhà cung cấp để nâng gói.")
	// 409 chứ không phải 422: form không có ô nào sai, người dùng chỉ chưa nói
	// mình đang đứng ở chi nhánh nào. Câu trả lời phải chỉ vào ô chọn ở thanh
	// trên cùng, không phải vào một ô nhập trong form.
	case errors.Is(err, domain.ErrChuaChonChiNhanh):
		response.Error(c, 409, "Chưa chọn chi nhánh làm việc. Chọn chi nhánh ở thanh trên cùng rồi thao tác lại — cửa hàng có nhiều chi nhánh nên hệ thống không tự đoán được chứng từ này thuộc kho nào.")
	case errors.Is(err, domain.ErrChiNhanhDaDong):
		response.Error(c, 403, "Chi nhánh này đã ngừng hoạt động — chọn chi nhánh khác ở thanh trên cùng")
	case errors.Is(err, domain.ErrKhongThuocChiNhanh):
		response.Error(c, 403, "Bạn không làm việc tại chi nhánh này")
	// 409 chứ không 422: đây không phải lỗi người dùng gõ sai ô nào, mà là hai
	// người cùng sửa một phiếu. Câu trả lời duy nhất đúng là mở lại phiếu.
	case errors.Is(err, domain.ErrPhieuVuaBiSua):
		response.Error(c, 409, "Phiếu này vừa được người khác lưu trong lúc bạn đang mở. Đóng và mở lại phiếu để xem bản mới nhất rồi sửa tiếp — lưu đè bây giờ sẽ xoá mất phần họ vừa nhập.")
	// 422: đây là lỗi của DÒNG HÀNG trong phiếu, sửa được bằng cách bỏ dòng đó ra
	// hoặc mở mặt hàng lên gán thêm chi nhánh. err đã kèm tên mặt hàng — in
	// nguyên, vì phiếu có thể dài vài chục dòng.
	case errors.Is(err, domain.ErrHangKhongThuocChiNhanh):
		response.Error(c, 422, strings.TrimPrefix(err.Error(), domain.ErrHangKhongThuocChiNhanh.Error()+": "))
	// Chi nhánh. Năm lỗi, năm cách chữa khác hẳn nhau — nên không gộp làm một.
	case errors.Is(err, domain.ErrMaChiNhanhDaCo):
		response.ValidationError(c, map[string]string{
			"code": "Mã này đã có chi nhánh khác dùng, vui lòng đặt mã khác",
		})
	case errors.Is(err, domain.ErrMaChiNhanhInvalid):
		response.ValidationError(c, map[string]string{
			"code": "Mã chi nhánh chỉ gồm chữ thường không dấu, số, dấu chấm, gạch ngang hoặc gạch dưới (2–30 ký tự)",
		})
	case errors.Is(err, domain.ErrToaDoChiNhanhInvalid):
		response.ValidationError(c, map[string]string{
			"location": "Vị trí phải là cặp toạ độ \"vĩ độ, kinh độ\" — mở Google Maps, bấm chuột phải vào điểm cần lấy rồi dán con số hiện ra",
		})
	case errors.Is(err, domain.ErrToaDoThieuCap):
		response.ValidationError(c, map[string]string{
			"area_scope": "Khai vị trí thì phải khai cả phạm vi hoạt động, và ngược lại",
		})
	// Hoá đơn điện tử — mỗi lỗi một cách chữa khác nhau.
	case errors.Is(err, domain.ErrETaxChuaCoKhoa):
		response.Error(c, 503, "Máy chủ chưa khai khoá mã hoá (ETAX_SECRET_KEY) nên chưa lưu được mật khẩu cổng hoá đơn điện tử. Liên hệ nhà cung cấp phần mềm.")
	case errors.Is(err, domain.ErrETaxNhaCungCapLa):
		response.ValidationError(c, map[string]string{
			"provider": "Nhà cung cấp hoá đơn điện tử này chưa được hỗ trợ",
		})
	case errors.Is(err, domain.ErrETaxMSTDaDung):
		response.Error(c, 409, "Mã số thuế này đã kết nối ở một chi nhánh khác — mỗi tài khoản cổng hoá đơn chỉ dùng cho một điểm bán")
	case errors.Is(err, domain.ErrETaxKyHieuLa):
		response.ValidationError(c, map[string]string{
			"template_symbol": "Ký hiệu này không có trong danh sách đã đăng ký — bấm Đồng bộ mẫu rồi chọn lại",
		})
	// Phát hành và sửa hoá đơn. Mỗi lỗi một cách chữa, và không lỗi nào trong số
	// này là "lỗi hệ thống" — để chúng rơi xuống nhánh 500 mặc định là biến một
	// việc người dùng tự làm được thành một cuộc gọi hỗ trợ.
	case errors.Is(err, domain.ErrETaxChuaNoi):
		response.Error(c, 409, "Chi nhánh của đơn này chưa kết nối hoá đơn điện tử — vào Quản lý chi nhánh để nối")
	case errors.Is(err, domain.ErrETaxChuaChonKyHieu):
		response.Error(c, 409, "Chi nhánh này chưa chọn ký hiệu phát hành — vào Quản lý chi nhánh để chọn")
	case errors.Is(err, domain.ErrDonChuaThuTien):
		response.Error(c, 409, "Đơn chưa thanh toán nên chưa phát hành hoá đơn được")
	case errors.Is(err, domain.ErrHoaDonDaPhatHanh):
		response.Error(c, 409, "Đơn này đã phát hành hoá đơn rồi")
	case errors.Is(err, domain.ErrHoaDonChuaLap):
		response.Error(c, 404, "Đơn này chưa phát hành hoá đơn")
	case errors.Is(err, domain.ErrHoaDonThieuMa):
		response.Error(c, 409, "Hoá đơn này chưa có mã bên cổng — hãy phát hành lại")
	case errors.Is(err, domain.ErrHoaDonDaKy):
		response.Error(c, 409, "Hoá đơn này đã ký rồi")
	case errors.Is(err, domain.ErrHoaDonChuaKy):
		response.Error(c, 409, "Hoá đơn chưa ký nên chưa có bản XML")
	case errors.Is(err, domain.ErrHoaDonKhongSuaDuoc):
		response.Error(c, 409, "Chỉ thay thế hoặc điều chỉnh được hoá đơn đã được cơ quan thuế cấp mã")
	case errors.Is(err, domain.ErrHoaDonKhongThayTheDuoc):
		response.Error(c, 409, "Hoá đơn này không thay thế được nữa — chỉ tờ gốc hoặc tờ thay thế mới thay được")
	case errors.Is(err, domain.ErrChiNhanhCuoiCung):
		response.Error(c, 409, "Đây là chi nhánh đang hoạt động cuối cùng — đóng nó xong thì cửa hàng không còn điểm bán nào để ghi đơn hàng hay tồn kho")
	// Nhân sự. Ba lỗi, ba cách chữa khác nhau: đổi mã, chọn người khác, hoặc bỏ
	// tick cấp tài khoản — nên không gộp chúng vào một câu chung.
	case errors.Is(err, domain.ErrMaNhanVienDaCo):
		response.ValidationError(c, map[string]string{
			"code": "Mã này đã có nhân viên khác dùng, vui lòng đặt mã khác",
		})
	case errors.Is(err, domain.ErrTaiKhoanDaGanNhanSu):
		response.Error(c, 409, "Tài khoản này đã gắn với một hồ sơ nhân sự khác")
	case errors.Is(err, domain.ErrNhanSuDaCoTaiKhoan):
		response.Error(c, 409, "Hồ sơ này đã có tài khoản đăng nhập — đổi mật khẩu hay đổi quyền là thao tác riêng, không cấp thêm tài khoản thứ hai")
	// Phân quyền theo chức năng. Ba lỗi, ba cách chữa khác nhau: sửa lại chuỗi
	// gõ sai, chuyển người sang nhóm khác, hoặc thôi đừng xoá nhóm hệ thống.
	case errors.Is(err, domain.ErrQuyenLa):
		response.ValidationError(c, map[string]string{
			"quyen": "Có quyền không nằm trong danh mục của phần mềm (" +
				strings.TrimPrefix(err.Error(), domain.ErrQuyenLa.Error()+": ") + ")",
		})
	case errors.Is(err, domain.ErrMaNhomQuyenDaCo):
		response.ValidationError(c, map[string]string{
			"code": "Mã này đã có nhóm quyền khác dùng, vui lòng đặt mã khác",
		})
	case errors.Is(err, domain.ErrNhomQuyenHeThong):
		response.Error(c, 422, "Đây là nhóm quyền hệ thống dựng sẵn — sửa được tên và quyền, nhưng không xoá được")
	case errors.Is(err, domain.ErrSKUBatBuoc):
		response.ValidationError(c, map[string]string{
			"sku": "Nhập mã hàng hoá, hoặc bật quy tắc mã hàng hoá ở Cài đặt → Thông số chung để hệ thống tự đặt",
		})
	case errors.Is(err, domain.ErrHetSoDe):
		response.Error(c, 409, "Mã sinh theo quy tắc đang trùng với mã đã có. Đổi tiền tố hoặc hậu tố ở Cài đặt → Thông số chung rồi thử lại")
	// Quy tắc đánh số chứng từ — cả ba đều là ô nhập sai, tô đỏ đúng ô đó.
	case errors.Is(err, domain.ErrLoaiMaLa):
		response.ValidationError(c, map[string]string{
			"doc_type": "Loại chứng từ này không có trong danh mục đánh số của phần mềm",
		})
	case errors.Is(err, domain.ErrPhanGiaTriLa):
		response.ValidationError(c, map[string]string{
			"value_part": "Phần giá trị chỉ nhận số thứ tự, ngày tháng năm hoặc tháng năm",
		})
	case errors.Is(err, domain.ErrDoDaiMaLa):
		response.ValidationError(c, map[string]string{
			"length": "Số ký tự phần giá trị phải từ " +
				strconv.Itoa(domain.DoDaiMaToiThieu) + " đến " + strconv.Itoa(domain.DoDaiMaToiDa),
		})
	case errors.Is(err, domain.ErrTienToLa):
		response.ValidationError(c, map[string]string{
			"prefix": "Tiền tố và hậu tố chỉ gồm chữ không dấu, số, gạch ngang hoặc gạch dưới",
		})
	// Thuế suất — ô chọn mức, nên tô đỏ đúng ô đó.
	case errors.Is(err, domain.ErrLoaiThueLa):
		response.Error(c, 422, "Loại thuế này không có trong danh mục của phần mềm")
	case errors.Is(err, domain.ErrMucThueLa):
		response.ValidationError(c, map[string]string{
			"muc": "Có mức thuế không nằm trong bộ mức của loại này",
		})
	case errors.Is(err, domain.ErrThueTrongRong):
		response.ValidationError(c, map[string]string{
			"muc": "Giữ lại ít nhất một mức thuế. Muốn thôi áp thuế thì tắt cả dòng",
		})
	// Nhân sự / nhóm quyền — trùng tên.
	case errors.Is(err, domain.ErrNhanVienTrungTen):
		response.ValidationError(c, map[string]string{
			"full_name": "Tên nhân viên này đã có trong cửa hàng",
		})
	case errors.Is(err, domain.ErrNhomQuyenTrungTen):
		response.ValidationError(c, map[string]string{
			"name": "Tên nhóm quyền này đã có trong cửa hàng",
		})
	// Nhà cung cấp / chi nhánh — trùng tên.
	case errors.Is(err, domain.ErrNhaCungCapTrungTen):
		response.ValidationError(c, map[string]string{
			"name": "Tên nhà cung cấp này đã có trong cửa hàng",
		})
	case errors.Is(err, domain.ErrTenChiNhanhDaCo):
		response.ValidationError(c, map[string]string{
			"name": "Tên chi nhánh này đã có trong cửa hàng",
		})
	// Mặt hàng — trùng tên (mã khác cũng không cho).
	case errors.Is(err, domain.ErrProductTrungTen):
		response.ValidationError(c, map[string]string{
			"name": "Tên mặt hàng này đã có trong cửa hàng",
		})
	// Nhóm hàng hoá — trùng tên.
	case errors.Is(err, domain.ErrCategoryTrungTen):
		response.ValidationError(c, map[string]string{
			"name": "Tên nhóm hàng hoá này đã có trong cửa hàng",
		})
	// Đơn vị tính — trùng mã hay trùng tên, tô đỏ đúng ô người vừa gõ.
	case errors.Is(err, domain.ErrDonViTinhTrungMa):
		response.ValidationError(c, map[string]string{
			"code": "Mã đơn vị này đã có trong cửa hàng (tính cả đơn vị đã xoá)",
		})
	case errors.Is(err, domain.ErrDonViTinhTrungTen):
		response.ValidationError(c, map[string]string{
			"name": "Tên đơn vị này đã có trong cửa hàng",
		})
	// Vị trí — cùng hai lỗi trùng với đơn vị tính.
	case errors.Is(err, domain.ErrViTriTrungMa):
		response.ValidationError(c, map[string]string{
			"code": "Mã vị trí này đã có trong cửa hàng (tính cả vị trí đã xoá)",
		})
	case errors.Is(err, domain.ErrViTriTrungTen):
		response.ValidationError(c, map[string]string{
			"name": "Tên vị trí này đã có trong cửa hàng",
		})
	case errors.Is(err, domain.ErrViTriDangDung):
		response.Error(c, 409, "Còn mặt hàng để ở vị trí này nên không xoá được. "+
			"Chuyển chúng sang vị trí khác trước, hoặc TẮT vị trí này đi nếu chỉ muốn thôi bày nó ra")
	// Nhà cung cấp — trùng mã tô đỏ ô mã, còn phiếu thì chặn xoá.
	case errors.Is(err, domain.ErrNhaCungCapTrungMa):
		response.ValidationError(c, map[string]string{
			"code": "Mã nhà cung cấp này đã có trong cửa hàng (tính cả bên đã xoá)",
		})
	// Thuộc tính — bốn lỗi trùng, tô đỏ đúng ô người vừa gõ. Hai lỗi của giá trị
	// con trỏ vào cả bảng giá trị chứ không vào một dòng: màn hình không đánh số
	// dòng nên chỉ vào "dòng thứ ba" cũng chẳng giúp ai.
	case errors.Is(err, domain.ErrThuocTinhTrungMa):
		response.ValidationError(c, map[string]string{
			"code": "Mã thuộc tính này đã có trong cửa hàng (tính cả thuộc tính đã xoá)",
		})
	case errors.Is(err, domain.ErrThuocTinhTrungTen):
		response.ValidationError(c, map[string]string{
			"name": "Tên thuộc tính này đã có trong cửa hàng",
		})
	case errors.Is(err, domain.ErrGiaTriTrungMa):
		response.ValidationError(c, map[string]string{
			"values": "Có hai giá trị trùng mã nhau. Mỗi giá trị một mã, hoặc bỏ trống để hệ thống đặt hộ",
		})
	case errors.Is(err, domain.ErrGiaTriTrungTen):
		response.ValidationError(c, map[string]string{
			"values": "Có hai giá trị trùng tên nhau",
		})
	case errors.Is(err, domain.ErrGiaTriLaCuaThuocTinhKhac):
		response.ValidationError(c, map[string]string{
			"values": "Danh sách gửi lên có giá trị không thuộc thuộc tính đang sửa",
		})
	case errors.Is(err, domain.ErrNhanSuDangMoCa):
		response.Error(c, 409, "Nhân viên này còn một ca chưa đóng. Đóng ca đó trước đã — khoá tài khoản bây giờ là chính người đang giữ két mất đường đóng ca")
	case errors.Is(err, domain.ErrNhanSuChuaCoTaiKhoan):
		response.Error(c, 409, "Hồ sơ này chưa có tài khoản đăng nhập nên không có mật khẩu để đặt lại")
	case errors.Is(err, domain.ErrNhanSuDaGhiSoQuy):
		response.Error(c, 409, "Nhân viên này đã ghi sổ quỹ nên hồ sơ phải giữ lại để đối chiếu tiền. Nghỉ việc thì đặt trạng thái \"Đã nghỉ\" — tài khoản vẫn bị khoá")
	case errors.Is(err, domain.ErrTuDanhDauNghiViec):
		response.Error(c, 409, "Đây là hồ sơ gắn với tài khoản bạn đang đăng nhập — đánh dấu nghỉ việc sẽ khoá luôn tài khoản này và đá bạn ra ngoài. Nhờ một quản trị viên khác làm giúp")
	case errors.Is(err, domain.ErrLastSuperAdmin):
		response.Error(c, 409, "Đây là super admin đang hoạt động cuối cùng — khoá hoặc xoá xong sẽ không còn ai quản trị được hệ thống")
	case errors.Is(err, domain.ErrEmailNotVerified):
		response.Error(c, 403, "Email chưa được xác thực, vui lòng nhập mã đã gửi tới hộp thư của bạn")
	case errors.Is(err, domain.ErrCodeInvalid):
		response.Error(c, 400, "Mã xác thực không đúng")
	case errors.Is(err, domain.ErrCodeExpired):
		response.Error(c, 400, "Mã xác thực đã hết hạn, vui lòng bấm gửi lại mã")
	case errors.Is(err, domain.ErrTooManyAttempts):
		response.Error(c, 400, "Nhập sai mã quá 5 lần, vui lòng bấm gửi lại mã mới")
	case errors.Is(err, domain.ErrResendTooSoon):
		// err đã kèm số giây còn phải chờ
		response.Error(c, 429, "Vui lòng chờ "+strings.TrimPrefix(err.Error(), domain.ErrResendTooSoon.Error()+": ")+" rồi thử lại")
	case errors.Is(err, domain.ErrKhongPhuTrachApp):
		response.Error(c, 403, "Bạn không phụ trách phần mềm này")
	// Hai lỗi của luồng KHÁCH TỰ GIA HẠN. Tách hẳn nhau vì người đọc phải làm hai
	// việc khác nhau: một bên là gọi cho nhà cung cấp (chưa bật cổng), một bên là
	// thử lại sau ít phút (cổng vừa trục trặc).
	case errors.Is(err, domain.ErrChuaBatCongThanhToan):
		response.Error(c, 503, "Nhà cung cấp chưa bật thanh toán trực tuyến — vui lòng liên hệ để gia hạn")
	case errors.Is(err, domain.ErrCongThanhToanLoi):
		response.Error(c, 502, "Cổng thanh toán đang không phản hồi, vui lòng thử lại sau ít phút")
	case errors.Is(err, domain.ErrPlatformUnavailable):
		response.Error(c, 503, "Khu điều hành nền tảng chưa sẵn sàng — máy chủ chưa nối được sổ nền tảng")

	// --- Ký hợp đồng từ khu điều hành ---
	// 409 cho hai lỗi TRÙNG (thứ đã có người chiếm) và 422 cho các lỗi bảng giá
	// (thứ phải đi sửa nơi khác rồi quay lại). Người bấm nút xử lý hai nhóm này
	// bằng hai cách hoàn toàn khác nhau, nên chúng không được cùng một mã.
	case errors.Is(err, domain.ErrCuaHangDaCo):
		response.ValidationError(c, map[string]string{
			"ma_cua_hang": "Mã cửa hàng này đã có người dùng, vui lòng đặt mã khác",
		})
	case errors.Is(err, domain.ErrMaConTrongSoNenTang):
		// Gắn vào ô `ma_cua_hang` như lỗi trùng mã thường, nhưng nói RÕ là dòng cũ
		// nằm ở sổ nền tảng: người bấm nút bên khu order sẽ tìm mã đó trong danh
		// sách khách và không thấy gì, rồi tưởng máy chủ hỏng.
		response.ValidationError(c, map[string]string{
			"ma_cua_hang": "Mã này còn trong sổ nền tảng dưới một khách cũ — xoá hẳn khách đó rồi tạo lại, hoặc đặt mã khác",
		})
	case errors.Is(err, domain.ErrHopDongDangChay):
		response.Error(c, 409, "Cửa hàng này đã có hợp đồng còn hiệu lực cho phần mềm đó — gia hạn hợp đồng cũ, hoặc huỷ nó trước rồi ký lại")
	case errors.Is(err, domain.ErrGoiNgungBan):
		response.ValidationError(c, map[string]string{
			"plan_id": "Gói này đã ngừng bán nên không ký mới được, vui lòng chọn gói khác",
		})
	case errors.Is(err, domain.ErrAppChuaBan):
		response.Error(c, 422, "Phần mềm này chưa ở trạng thái đang bán")
	case errors.Is(err, domain.ErrBangGiaChuaCoGia):
		response.ValidationError(c, map[string]string{
			"plan_id": "Bảng giá ghi \"Liên hệ\" cho gói này nên chưa có giá để chép sang hợp đồng",
		})
	case errors.Is(err, domain.ErrBangGiaThieuHanMuc):
		// err đã kèm tên hạn mức còn thiếu — in nguyên ra, vì người sửa cần biết
		// phải điền ô nào ở màn hình Tính năng gói.
		response.ValidationError(c, map[string]string{
			"plan_id": "Bảng giá của gói này chưa khai đủ hạn mức (" +
				strings.TrimPrefix(err.Error(), domain.ErrBangGiaThieuHanMuc.Error()+": ") + ")",
		})
	case errors.Is(err, domain.ErrDaThuKyNay):
		response.Error(c, 409, "Kỳ này đã có một lần thu ghi trong sổ. Nếu đây là lần thu của kỳ khác thì khai rõ ngày đầu và ngày cuối kỳ.")
	case errors.Is(err, domain.ErrKhongConKyDeThu):
		response.Error(c, 409, "Hợp đồng đã trả tiền tới hết hạn hiện tại — gia hạn trước, rồi mới có kỳ mới để thu")
	case errors.Is(err, domain.ErrHopDongDaHuy):
		response.Error(c, 409, "Hợp đồng này đã huỷ — khách quay lại thì ký hợp đồng mới, không mở lại hợp đồng cũ")
	case errors.Is(err, domain.ErrFacebookDisabled):
		response.Error(c, 503, "Cửa hàng chưa bật đăng nhập bằng Facebook")
	case errors.Is(err, domain.ErrFacebookAuthFailed):
		response.Error(c, 401, "Không xác minh được tài khoản Facebook, vui lòng thử lại")
	case errors.Is(err, domain.ErrFacebookNoEmail):
		response.Error(c, 422, "Tài khoản Facebook của bạn không chia sẻ email nên chưa tạo được tài khoản. Vui lòng đăng ký bằng email.")
	case errors.Is(err, domain.ErrPromotionTimeRange):
		response.Error(c, 422, "Thời gian chạy không hợp lệ: ngày kết thúc phải sau ngày bắt đầu")
	case errors.Is(err, domain.ErrPromotionPercentRange):
		response.Error(c, 422, "Phần trăm giảm phải trong khoảng 1–100")
	case errors.Is(err, domain.ErrPromotionNoScope):
		response.Error(c, 422, "Vui lòng chọn ít nhất một sản phẩm, danh mục hoặc thương hiệu để áp dụng")
	case errors.Is(err, domain.ErrVoucherCodeExists):
		response.Error(c, 409, "Mã voucher này đã tồn tại, vui lòng đặt mã khác")
	case errors.Is(err, domain.ErrVoucherCodeInvalid):
		response.ValidationError(c, map[string]string{
			"code": "Mã chỉ gồm chữ không dấu, số, gạch ngang hoặc gạch dưới (3–50 ký tự)",
		})
	case errors.Is(err, domain.ErrVoucherTimeRange):
		response.Error(c, 422, "Thời gian hiệu lực không hợp lệ: ngày kết thúc phải sau ngày bắt đầu")
	case errors.Is(err, domain.ErrVoucherPercentRange):
		response.Error(c, 422, "Phần trăm giảm phải trong khoảng 1–100")
	case errors.Is(err, domain.ErrVoucherLimitBelowUsed):
		response.Error(c, 422, "Tổng lượt dùng đang nhỏ hơn số lượt đã phát ra — mã sẽ hết lượt ngay lúc lưu")
	case errors.Is(err, domain.ErrGoogleDisabled):
		response.Error(c, 503, "Cửa hàng chưa bật đăng nhập bằng Google")
	case errors.Is(err, domain.ErrGoogleAuthFailed):
		response.Error(c, 401, "Không xác minh được tài khoản Google, vui lòng thử lại")
	case errors.Is(err, domain.ErrGoogleNoEmail):
		response.Error(c, 422, "Tài khoản Google của bạn không có email đã xác minh nên chưa tạo được tài khoản. Vui lòng đăng ký bằng email.")
	case errors.Is(err, domain.ErrMailSendFailed):
		response.Error(c, 502, "Không gửi được email xác thực, vui lòng thử lại hoặc liên hệ hotline")
	default:
		response.Error(c, 500, "Đã có lỗi xảy ra, vui lòng thử lại")
	}
}

// chiNhanhLoc chốt CHI NHÁNH mà một danh sách phải cắt theo.
//
// Ba nước, theo đúng thứ tự:
//
//   - query `shop_id` có giá trị > 0 → cắt theo đúng chi nhánh đó. Dùng cho ô lọc
//     "Chi nhánh" mà người dùng chủ động bấm, và cho báo cáo xem chéo.
//   - query `shop_id=0` → KHÔNG cắt: người dùng cố ý chọn "Tất cả chi nhánh".
//     Phải khai tường minh, vì đây là câu trả lời hiếm.
//   - không gửi `shop_id` → cắt theo CHI NHÁNH ĐANG LÀM VIỆC.
//
// Nước thứ ba là chỗ vừa phải sửa. Trước đây thiếu nó, nên mọi danh sách chứng
// từ bày đơn và phiếu của MỌI chi nhánh cho người đang đứng ở đúng một quầy —
// con số trên đầu trang cũng cộng theo cả cửa hàng. Cửa hàng một chi nhánh thì
// ctx không mang gì và kết quả vẫn y như cũ.
func chiNhanhLoc(c *gin.Context) uint {
	// NGƯỜI BỊ PHÂN CÔNG thì tham số trên URL không có tiếng nói.
	//
	// Thiếu vế này thì cả ba nước trên chỉ là gợi ý: nhân viên ghim ở kho 2 gõ
	// thêm `?shop_id=1` là xem được danh sách đơn của kho 1, và `?shop_id=0` là
	// xem của cả cửa hàng. Middleware đã chặn HEADER khai sai kho, nhưng tham số
	// query đi vòng qua nó — hai đường vào cùng một câu hỏi mà chỉ một đường có
	// người gác.
	if c.GetBool(middleware.CtxChiNhanhGhim) {
		return c.GetUint(middleware.CtxChiNhanhID)
	}

	if s := c.Query("shop_id"); s != "" {
		v, err := strconv.ParseUint(s, 10, 64)
		if err != nil {
			return 0
		}

		return uint(v)
	}

	return c.GetUint(middleware.CtxChiNhanhID)
}

// chiNhanhLocPtr là chiNhanhLoc cho những bộ lọc dùng *uint (nil = không cắt).
func chiNhanhLocPtr(c *gin.Context) *uint {
	if id := chiNhanhLoc(c); id > 0 {
		return &id
	}

	return nil
}
