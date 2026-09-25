package repository

import (
	"context"
	"errors"
	"fmt"
	"slices"
	"strings"
	"time"

	"gorm.io/gorm"
	"gorm.io/gorm/clause"

	"sass-api/internal/domain"
)

// SỔ CHỨNG TỪ BÁN HÀNG — nguồn dữ liệu của màn Quản lý đơn hàng.
//
// Dựng theo đúng cách bản v2 làm màn này (ManagerOrderController::list): hai câu
// truy vấn riêng cho đơn bán và phiếu trả, UNION ALL lại, rồi PHÂN TRANG TRÊN
// CÂU UNION. Không gộp ở tầng Go: gộp trong bộ nhớ thì để lấy trang 5 phải đọc
// về cả bốn trang trước đó của cả hai bảng.
//
// VÌ SAO KHÔNG DÙNG LẠI List(): List trả thực thể Order cho storefront, POS và
// hộp chi tiết. Màn này cần một DÒNG BẢNG đã quy đổi sẵn — tiền theo phương
// thức, số còn nợ, tên người lập. Nhét cả hai vào một câu là bên nào cũng phải
// mang thừa nửa số cột của bên kia, và mỗi lần sửa màn lại chạm vào đường đặt
// hàng của khách.
//
// LỌC THEO CỬA HÀNG: hai câu con dựng bằng *gorm.DB nên plugin tenant chạy cho
// từng câu (xem scopeTarget trong tenant_scope.go — nhánh `derived`). Đừng đổi
// chúng thành chuỗi SQL viết tay: lúc đó plugin không chèn được điều kiện nào và
// nó sẽ chặn câu lệnh lại, đúng như thiết kế.
func (r *orderRepository) SoDon(ctx context.Context, f domain.OrderFilter) ([]domain.DongSoDon, domain.TongSoDon, error) {
	// Dựng LẠI hai câu con cho mỗi lượt dùng: một *gorm.DB mang sẵn statement, xài
	// hai lần (đếm rồi đọc) là điều kiện của lượt trước dính lại vào lượt sau.
	//
	// Bí danh cho bảng dẫn xuất là bắt buộc với MySQL; tên nào cũng được, miễn
	// mệnh đề ORDER BY bên dưới đọc theo cột của nó chứ không theo cột bảng gốc.
	union := func() *gorm.DB {
		return r.db.WithContext(ctx).
			Table("((?) UNION ALL (?)) AS so_don", r.cauDonBan(ctx, f), r.cauPhieuTra(ctx, f))
	}

	// Đếm và cộng hàng tổng trong CÙNG một lượt: hàng "tất cả" của v2 cộng trên
	// đúng tập dòng mà câu đếm đếm, tách hai câu thì hai con số có thể lệch nhau
	// khi có đơn mới chen vào giữa.
	var tong domain.TongSoDon
	err := union().Select(`COUNT(*) AS so_dong,
		COALESCE(SUM(tong_tien), 0) AS tong_tien,
		COALESCE(SUM(giam_gia), 0) AS giam_gia,
		COALESCE(SUM(phi_giao), 0) AS phi_giao,
		COALESCE(SUM(tien_mat), 0) AS tien_mat,
		COALESCE(SUM(chuyen_khoan), 0) AS chuyen_khoan,
		COALESCE(SUM(the_vi), 0) AS the_vi`).
		Scan(&tong).Error
	if err != nil {
		return nil, domain.TongSoDon{}, err
	}
	if tong.SoDong == 0 {
		return []domain.DongSoDon{}, tong, nil
	}

	trang := f.Page
	if trang < 1 {
		trang = 1
	}
	soDong := f.PageSize
	if soDong < 1 {
		soDong = 20
	}

	rows := []domain.DongSoDon{}
	err = union().
		Order(sapXep(f.Sort)).
		Limit(soDong).
		Offset((trang - 1) * soDong).
		Scan(&rows).Error
	if err != nil {
		return nil, domain.TongSoDon{}, err
	}

	return rows, tong, nil
}

// sapXep đổi mã sắp xếp của giao diện thành mệnh đề ORDER BY trên bảng union.
//
// Mặc định là `updated_at DESC` chứ không phải `created_at`: đúng như v2, và
// đúng cái người trực đơn cần — chứng từ vừa bị đụng tới phải nổi lên đầu, kể cả
// khi nó được lập từ hôm kia.
func sapXep(sort string) string {
	switch sort {
	case "oldest":
		return "created_at ASC, id ASC"
	case "total_desc":
		return "tong_tien DESC, id DESC"
	case "total_asc":
		return "tong_tien ASC, id DESC"
	case "newest":
		return "created_at DESC, id DESC"
	default: // "updated" và mọi giá trị lạ
		return "updated_at DESC, id DESC"
	}
}

// sqlDanhSach viết một nhóm HẰNG khai trong code thành danh sách SQL 'a','b'.
// Chỉ dùng cho hằng — giá trị người dùng gõ phải đi bằng tham số `?`.
func sqlDanhSach(ds []string) string {
	q := make([]string, len(ds))
	for i, v := range ds {
		q[i] = "'" + v + "'"
	}

	return strings.Join(q, ",")
}

// Các mảnh SQL dùng chung cho cột và bộ lọc của nhánh đơn bán. Khai MỘT lần để
// cột "Trạng thái" và ô lọc "Trạng thái" không bao giờ hiểu một đơn theo hai
// cách — lệch nhau là tick "Chưa thanh toán" mà bảng lại in "Một phần".
var (
	// daThuDu: đơn coi như đã thu đủ.
	//
	// `refunded` đứng cùng `paid`: tiền ĐÃ thu rồi mới hoàn, và khoản hoàn nằm ở
	// dòng phiếu trả của chính nó — y như v2 để đơn gốc "Đã thanh toán" còn phiếu
	// trả mang dòng "Trả hàng" riêng. Tính `refunded` là chưa thu thì đơn đã hoàn
	// tiền lại hiện "Công nợ" và nút Thu tiền.
	daThuDu = "orders.payment_status IN ('" + domain.OrderPaymentPaid + "','refunded')"

	// donConSong: đơn chưa khép. Đơn đã huỷ hay đã trả hết hàng không còn khoản
	// nào để đòi, dù lúc khép nó chưa thu đồng nào.
	donConSong = "orders.status NOT IN ('" + domain.OrderStatusCancelled + "','" + domain.OrderStatusReturned + "')"

	// phieuDaTra: phiếu trả mà hàng ĐÃ về kho — đúng tập trạng thái làm một đơn
	// thành "đã trả hết" (returnedStatuses). Phiếu mới gửi yêu cầu, bị từ chối hay
	// khách rút lại thì chưa có gì được trả, không có chỗ trong sổ bán hàng.
	phieuDaTra = sqlDanhSach(returnedStatuses)

	// cauLuotThu cộng sổ THU TIỀN của từng đơn (migration 0066), tách sẵn theo ba
	// nhóm phương thức. Nối bằng câu con đã gom nhóm chứ không JOIN thẳng rồi
	// GROUP BY cả câu ngoài: câu ngoài còn phải UNION với phiếu trả, mà một mệnh đề
	// GROUP BY quàng qua cả hai nhánh thì mỗi lần thêm cột lại phải nhớ thêm vào đó.
	cauLuotThu = fmt.Sprintf(`LEFT JOIN (
			SELECT order_id, tenant_id,
				SUM(amount) AS da_thu,
				SUM(CASE WHEN payment_method IN (%s) THEN amount ELSE 0 END) AS tm,
				SUM(CASE WHEN payment_method IN (%s) THEN amount ELSE 0 END) AS ck,
				SUM(CASE WHEN payment_method IN (%s) THEN amount ELSE 0 END) AS vi
			FROM order_payments
			WHERE deleted_at IS NULL
			GROUP BY order_id, tenant_id
		) tt ON tt.order_id = orders.id AND tt.tenant_id = orders.tenant_id`,
		sqlDanhSach(domain.NhomTienMat), sqlDanhSach(domain.NhomChuyenKhoan), sqlDanhSach(domain.NhomTheVi))

	// phanThuMotLan là phần tiền của đơn đã thu đủ mà sổ không có lượt nào ghi —
	// tiền thu một lần ngay lúc bán, hay đơn được gạt cờ "đã thanh toán". Phần ấy
	// đi theo phương thức khai trên đơn, đúng như v2 đọc `total_payment_1`.
	phanThuMotLan = "CASE WHEN " + daThuDu +
		" THEN GREATEST(orders.total_amount - COALESCE(tt.da_thu, 0), 0) ELSE 0 END"

	// trangThaiDon — năm trạng thái của v2, suy ra mỗi lượt đọc. Thứ tự các nhánh
	// chính là thứ tự ưu tiên:
	//
	//   - Huỷ đứng trước tiền: v2 xếp payment_status = 3 (huỷ) trên mọi nhánh
	//     đã/chưa thanh toán.
	//   - Đơn đã trả hết hàng CÓ phiếu trả thì vẫn đọc theo tiền, vì dòng "Trả
	//     hàng" đã có — chính là phiếu trả. Bên v2 cũng vậy: đơn gốc giữ "Đã thanh
	//     toán", phiếu trả là một dòng riêng. In cả hai là một lần trả hiện hai dòng.
	//   - Đơn bị chuyển thẳng sang "hoàn hàng" mà KHÔNG qua phiếu trả nào thì không
	//     có dòng nào khác nói chuyện ấy, nên chính nó mang chữ "Trả hàng".
	trangThaiDon = `CASE
			WHEN orders.status = '` + domain.OrderStatusCancelled + `' THEN '` + domain.TrangThaiSoDaHuy + `'
			WHEN orders.status = '` + domain.OrderStatusReturned + `' AND NOT EXISTS (
				SELECT 1 FROM order_returns r
				WHERE r.order_id = orders.id AND r.tenant_id = orders.tenant_id
					AND r.deleted_at IS NULL AND r.status IN (` + phieuDaTra + `)
			) THEN '` + domain.TrangThaiSoTraHang + `'
			WHEN ` + daThuDu + ` THEN '` + domain.TrangThaiSoDaThanhToan + `'
			WHEN COALESCE(tt.da_thu, 0) > 0 THEN '` + domain.TrangThaiSoMotPhan + `'
			ELSE '` + domain.TrangThaiSoChuaThu + `'
		END`

	tatCaTrangThaiSo = []string{
		domain.TrangThaiSoDaThanhToan, domain.TrangThaiSoChuaThu, domain.TrangThaiSoMotPhan,
		domain.TrangThaiSoTraHang, domain.TrangThaiSoDaHuy,
	}
)

// cotTienDon là một trong ba cột tiền của đơn bán: các lượt thu thuộc nhóm ấy,
// cộng phần thu một lần nếu phương thức khai trên đơn cũng thuộc nhóm ấy.
func cotTienDon(cotLuotThu string, nhom []string) string {
	return fmt.Sprintf("COALESCE(tt.%s, 0) + CASE WHEN orders.payment_method IN (%s) THEN %s ELSE 0 END",
		cotLuotThu, sqlDanhSach(nhom), phanThuMotLan)
}

// cotTienPhieuTra là một trong ba cột tiền của phiếu trả: số tiền hoàn, nằm ở
// cột theo phương thức hoàn, và CHỈ khi phiếu đã hoàn tiền thật. Phiếu mới nhận
// hàng về (hay phiếu của lượt đổi hàng, hoàn "none") chưa có đồng nào đi ra.
func cotTienPhieuTra(nhom []string) string {
	return fmt.Sprintf("CASE WHEN order_returns.status = '%s' AND order_returns.refund_method IN (%s) THEN order_returns.refund_amount ELSE 0 END",
		domain.ReturnStatusRefunded, sqlDanhSach(nhom))
}

// cauDonBan dựng nhánh ĐƠN BÁN của câu union.
func (r *orderRepository) cauDonBan(ctx context.Context, f domain.OrderFilter) *gorm.DB {
	q := r.db.WithContext(ctx).Model(&domain.Order{}).
		// Nối sang `users` để lấy tên người lập. Điều kiện `tenant_id` viết thẳng
		// vào ON: plugin chỉ chèn bộ lọc cho bảng chính, bảng nối thì không —
		// thiếu dòng này là một ngày nào đó id trùng sẽ kéo tên của cửa hàng khác.
		Joins("LEFT JOIN users nt ON nt.id = orders.created_by AND nt.tenant_id = orders.tenant_id").
		Joins(cauLuotThu).
		Select(`'don' AS loai,
			orders.id AS id,
			orders.order_code AS ma,
			orders.channel AS kenh,
			orders.recipient_name AS khach_hang,
			orders.recipient_phone AS so_dien_thoai,
			orders.subtotal_amount AS tien_hang,
			orders.discount_amount AS giam_gia,
			orders.shipping_fee AS phi_giao,
			orders.total_amount AS tong_tien,
			orders.payment_method AS phuong_thuc,
			-- Đơn đã thu đủ thì coi như thu đủ DÙ SỔ TRỐNG: tiền vào két đi qua bốn
			-- đường và chưa đường nào ghi sổ, nên tin sổ ở đây là báo một đơn đã thu
			-- đủ thành "đã thu 0đ" — trông y hệt một khoản nợ.
			CASE WHEN ` + daThuDu + `
				THEN GREATEST(orders.total_amount, COALESCE(tt.da_thu, 0))
				ELSE COALESCE(tt.da_thu, 0) END AS da_thu,
			` + cotTienDon("tm", domain.NhomTienMat) + ` AS tien_mat,
			` + cotTienDon("ck", domain.NhomChuyenKhoan) + ` AS chuyen_khoan,
			` + cotTienDon("vi", domain.NhomTheVi) + ` AS the_vi,
			CASE WHEN ` + donConSong + ` AND NOT (` + daThuDu + `)
				THEN GREATEST(orders.total_amount - COALESCE(tt.da_thu, 0), 0)
				ELSE 0 END AS con_no,
			CASE WHEN ` + donConSong + ` AND NOT (` + daThuDu + `) AND COALESCE(tt.da_thu, 0) > 0
				THEN 1 ELSE 0 END AS co_cong_no,
			` + trangThaiDon + ` AS trang_thai,
			COALESCE(nt.full_name, '') AS nguoi_tao,
			orders.created_at AS created_at,
			orders.updated_at AS updated_at`)

	if f.Keyword != "" {
		q = q.Where("orders.order_code LIKE ?", "%"+f.Keyword+"%")
	}
	if f.Customer != "" {
		kw := "%" + f.Customer + "%"
		q = q.Where("(orders.recipient_name LIKE ? OR orders.recipient_phone LIKE ?)", kw, kw)
	}
	q = locTrangThaiSo(q, f.Status)
	q = locHoaDonDienTu(q, f.HoaDonDienTu)
	q = locNhieu(q, "orders.payment_status", f.PaymentStatus)
	q = locPhuongThucDon(q, f.PaymentMethod)
	q = locNhieu(q, "orders.channel", f.Channel)
	q = locNhieu(q, "orders.created_by", f.CreatedBy)
	if f.ShopID > 0 {
		q = q.Where("orders.shop_id = ?", f.ShopID)
	}
	if f.FromDate != "" {
		q = q.Where("orders.created_at >= ?", f.FromDate+" 00:00:00")
	}
	if f.ToDate != "" {
		q = q.Where("orders.created_at <= ?", f.ToDate+" 23:59:59")
	}

	return q
}

// cauPhieuTra dựng nhánh PHIẾU TRẢ HÀNG của câu union.
//
// Phiếu trả không mang kênh bán hay trạng thái thanh toán của riêng nó, nên ô
// lọc kênh được đối chiếu với ĐƠN GỐC (bảng `dg`), và ô nào không đối chiếu được
// thì tắt hẳn nhánh này — giống hệt cách v2 gạt `where id = -1`.
func (r *orderRepository) cauPhieuTra(ctx context.Context, f domain.OrderFilter) *gorm.DB {
	q := r.db.WithContext(ctx).Model(&domain.OrderReturn{}).
		// JOIN chứ không LEFT JOIN, và đơn gốc phải còn: phiếu trả của một đơn đã xoá
		// không còn gì để đối chiếu (v2: `whereHas('order')`).
		Joins("JOIN orders dg ON dg.id = order_returns.order_id AND dg.tenant_id = order_returns.tenant_id AND dg.deleted_at IS NULL").
		Joins("LEFT JOIN users nt ON nt.id = order_returns.handled_by AND nt.tenant_id = order_returns.tenant_id").
		// Tiền hàng, giảm giá và phí giao của phiếu trả là 0 như dòng trả hàng của v2:
		// cộng khoản khấu trừ của phiếu vào cột "Giảm giá" là hàng tổng dưới chân
		// bảng lẫn một thứ không phải khuyến mãi vào tổng khuyến mãi.
		Select(`'tra-hang' AS loai,
			order_returns.id AS id,
			order_returns.return_code AS ma,
			COALESCE(dg.channel, '') AS kenh,
			COALESCE(dg.recipient_name, '') AS khach_hang,
			COALESCE(dg.recipient_phone, '') AS so_dien_thoai,
			0 AS tien_hang,
			0 AS giam_gia,
			0 AS phi_giao,
			order_returns.refund_amount AS tong_tien,
			order_returns.refund_method AS phuong_thuc,
			0 AS da_thu,
			` + cotTienPhieuTra(domain.NhomTienMat) + ` AS tien_mat,
			` + cotTienPhieuTra(domain.NhomChuyenKhoan) + ` AS chuyen_khoan,
			` + cotTienPhieuTra(domain.NhomTheVi) + ` AS the_vi,
			0 AS con_no,
			0 AS co_cong_no,
			'` + domain.TrangThaiSoTraHang + `' AS trang_thai,
			COALESCE(nt.full_name, '') AS nguoi_tao,
			order_returns.created_at AS created_at,
			order_returns.updated_at AS updated_at`).
		Where("order_returns.status IN (" + phieuDaTra + ")")

	// Bộ lọc TRẠNG THÁI: phiếu trả chỉ thuộc về đúng một lựa chọn — "Trả hàng".
	// Người dùng lọc trạng thái khác mà vẫn thấy phiếu trả chen vào thì con số
	// dưới chân bảng không khớp với thứ họ vừa chọn.
	if !chonTraHang(f.Status) {
		return q.Where("1 = 0")
	}

	// Phiếu trả không tự có tờ hoá đơn nào. Lọc "HĐĐT: Có" thì nó rút lui; lọc
	// "Không" thì nó VẪN có mặt — đúng v2: `e_invoice === '0'` (có) mới gạt nhánh
	// phiếu trả, còn '1' (chưa có) thì giữ.
	//
	// Trạng thái thanh toán là của đơn bán, phiếu trả không có giá trị nào để so.
	if chiLocCoHoaDon(f.HoaDonDienTu) || coLoc(f.PaymentStatus) {
		return q.Where("1 = 0")
	}
	// Lọc phương thức: phiếu trả ở lại nếu nó HOÀN bằng cùng nhóm phương thức (v2
	// lọc phiếu trả theo `payment_method` của chính phiếu, không gạt cả nhánh).
	if coLoc(f.PaymentMethod) {
		q = q.Where("order_returns.refund_method IN ?", nhomCua(splitCSV(f.PaymentMethod)))
	}

	if f.Keyword != "" {
		q = q.Where("order_returns.return_code LIKE ?", "%"+f.Keyword+"%")
	}
	if f.Customer != "" {
		kw := "%" + f.Customer + "%"
		q = q.Where("(dg.recipient_name LIKE ? OR dg.recipient_phone LIKE ?)", kw, kw)
	}
	// Kênh lấy theo đơn gốc — phiếu trả của đơn quầy vẫn là chuyện của quầy.
	q = locNhieu(q, "dg.channel", f.Channel)
	q = locNhieu(q, "order_returns.handled_by", f.CreatedBy)
	if f.ShopID > 0 {
		q = q.Where("order_returns.shop_id = ?", f.ShopID)
	}
	if f.FromDate != "" {
		q = q.Where("order_returns.created_at >= ?", f.FromDate+" 00:00:00")
	}
	if f.ToDate != "" {
		q = q.Where("order_returns.created_at <= ?", f.ToDate+" 23:59:59")
	}

	return q
}

// locTrangThaiSo lọc theo TRẠNG THÁI CỦA SỔ — năm giá trị suy ra của v2.
//
// So thẳng với chính biểu thức in ra cột Trạng thái (trangThaiDon), nên ô tick
// và cột trong bảng không thể hiểu một đơn theo hai cách. Tick nhiều ô nghĩa là
// "cho tôi xem những đơn thuộc BẤT KỲ nhóm nào trong số này".
func locTrangThaiSo(q *gorm.DB, giaTri string) *gorm.DB {
	if !coLoc(giaTri) {
		return q
	}

	var chon []string
	for _, v := range splitCSV(giaTri) {
		if slices.Contains(tatCaTrangThaiSo, v) {
			chon = append(chon, v)
		}
	}
	if len(chon) == 0 {
		// Tick toàn giá trị lạ: KHÔNG trả về bảng đầy đủ, vì người dùng vừa nói rõ
		// họ chỉ muốn một nhóm nào đó.
		return q.Where("1 = 0")
	}

	return q.Where("("+trangThaiDon+") IN ?", chon)
}

// locPhuongThucDon lọc đơn bán theo phương thức: đơn KHAI phương thức ấy, hoặc có
// ít nhất một lượt thu bằng phương thức ấy. Vế sau là để đơn chuyển khoản mà
// khách trả nợ bằng tiền mặt vẫn hiện khi lọc "Tiền mặt" — đúng cột tiền mặt
// của nó đang mang số.
func locPhuongThucDon(q *gorm.DB, giaTri string) *gorm.DB {
	if !coLoc(giaTri) {
		return q
	}
	ds := splitCSV(giaTri)

	return q.Where(`(orders.payment_method IN ? OR EXISTS (
		SELECT 1 FROM order_payments p
		WHERE p.order_id = orders.id AND p.tenant_id = orders.tenant_id
			AND p.deleted_at IS NULL AND p.payment_method IN ?
	))`, ds, ds)
}

// nhomCua trả mọi phương thức thuộc CÙNG NHÓM với các phương thức đã chọn.
//
// Phiếu trả hoàn tiền bằng bộ giá trị khác đơn (cash | bank_transfer | ewallet),
// nên lọc phiếu trả theo đúng từng giá trị của đơn là không bao giờ khớp "ví".
// Gom lên nhóm — cũng là cách v2 lọc: bốn nhóm, không phải từng mã.
func nhomCua(chon []string) []string {
	var out []string
	for _, nhom := range [][]string{domain.NhomTienMat, domain.NhomChuyenKhoan, domain.NhomTheVi} {
		for _, v := range chon {
			if slices.Contains(nhom, v) {
				out = append(out, nhom...)

				break
			}
		}
	}
	if len(out) == 0 {
		// Chọn toàn giá trị ngoài ba nhóm: không phiếu trả nào khớp.
		return []string{""}
	}

	return out
}

// coHoaDon là điều kiện "đơn này đã có tờ hoá đơn điện tử nào".
//
// EXISTS chứ không LEFT JOIN: một đơn có thể mang NHIỀU dòng hoá đơn (tờ thay
// thế, tờ điều chỉnh), mà join vào thì đơn ấy hiện lên hai ba lần trong sổ và
// mọi con số cộng dồn nhân đôi theo.
//
// `tenant_id` phải khai ngay trong câu con: plugin lọc theo cửa hàng chỉ chèn
// điều kiện cho bảng chính, câu con viết tay thì không ai chèn hộ.
const coHoaDon = `EXISTS (
	SELECT 1 FROM etax_invoices e
	WHERE e.order_id = orders.id AND e.tenant_id = orders.tenant_id
)`

// locHoaDonDienTu lọc theo ô "HĐĐT: Có / Không".
//
// Tick cả hai (hoặc không tick ô nào) = KHÔNG lọc: mọi đơn đều rơi vào đúng một
// trong hai nhóm, nên hỏi cả hai là hỏi tất cả.
func locHoaDonDienTu(q *gorm.DB, giaTri string) *gorm.DB {
	switch {
	case !motBenHoaDon(giaTri):
		return q
	case splitCSV(giaTri)[0] == "co":
		return q.Where(coHoaDon)
	default:
		return q.Where("NOT " + coHoaDon)
	}
}

// motBenHoaDon: ô hoá đơn điện tử đang chọn ĐÚNG MỘT bên, tức là đang lọc thật.
func motBenHoaDon(giaTri string) bool {
	if !coLoc(giaTri) {
		return false
	}
	ds := splitCSV(giaTri)

	return len(ds) == 1 && (ds[0] == "co" || ds[0] == "khong")
}

// chiLocCoHoaDon: ô hoá đơn điện tử đang chọn đúng MỘT bên và bên ấy là "Có".
func chiLocCoHoaDon(giaTri string) bool {
	return motBenHoaDon(giaTri) && splitCSV(giaTri)[0] == "co"
}

// coLoc cho biết một ô lọc chọn-nhiều có đang bật hay không.
func coLoc(v string) bool { return v != "" && v != "all" }

// chonTraHang cho biết bộ lọc trạng thái có bao gồm "Trả hàng" hay không.
// Không lọc trạng thái = xem tất cả, nên phiếu trả cũng có mặt.
func chonTraHang(status string) bool {
	if !coLoc(status) {
		return true
	}
	for _, s := range splitCSV(status) {
		if s == domain.TrangThaiSoTraHang {
			return true
		}
	}

	return false
}

// GhiLuotThu ghi MỘT LƯỢT THU TIỀN cho đơn và cập nhật lại trạng thái tiền.
//
// Cả ba việc — đọc số đã thu, chèn dòng mới, đổi trạng thái — nằm trong MỘT giao
// dịch và đơn bị khoá (SELECT ... FOR UPDATE) trước khi cộng: hai người cùng thu
// một đơn ở hai máy mà không khoá thì cả hai cùng đọc "còn nợ 700", cùng ghi 700,
// và đơn nhận vào 1.400 nghìn.
//
// Thu quá số còn nợ bị TỪ CHỐI chứ không cắt bớt cho vừa: người gõ nhầm một số 0
// cần thấy lỗi, không phải thấy phiếu ghi một số khác số họ vừa gõ.
//
// Thu đủ thì đơn tự sang `paid` — đó là ý nghĩa duy nhất của việc thu nốt phần
// còn lại, và bắt người dùng bấm thêm một nút nữa để nói điều hiển nhiên ấy chỉ
// tạo ra những đơn thu đủ mà vẫn treo cờ chưa thanh toán.
func (r *orderRepository) GhiLuotThu(ctx context.Context, p *domain.OrderPayment) error {
	if p.Amount <= 0 {
		return fmt.Errorf("%w: số tiền thu phải lớn hơn 0", domain.ErrInvalidStatus)
	}

	return r.db.WithContext(ctx).Transaction(func(tx *gorm.DB) error {
		var o domain.Order
		if err := tx.Clauses(clause.Locking{Strength: "UPDATE"}).
			First(&o, p.OrderID).Error; err != nil {
			if errors.Is(err, gorm.ErrRecordNotFound) {
				return domain.ErrNotFound
			}

			return err
		}
		if err := chanChungTuKhacChiNhanh(ctx, tx, o.ShopID); err != nil {
			return err
		}
		// Đơn đã khép thì không còn khoản nào để thu: huỷ là giao dịch không thành,
		// trả hết hàng là tiền phải đi RA chứ không vào. Sổ chứng từ cũng in 0 ở
		// cột còn nợ của hai loại đơn này.
		switch o.Status {
		case domain.OrderStatusCancelled:
			return fmt.Errorf("%w: đơn đã huỷ, không ghi thu tiền được", domain.ErrInvalidStatus)
		case domain.OrderStatusReturned:
			return fmt.Errorf("%w: đơn đã trả hàng, không ghi thu tiền được", domain.ErrInvalidStatus)
		}
		switch o.PaymentStatus {
		case domain.OrderPaymentPaid:
			return fmt.Errorf("%w: đơn này đã thu đủ tiền", domain.ErrInvalidStatus)
		case "refunded":
			return fmt.Errorf("%w: đơn này đã hoàn tiền cho khách", domain.ErrInvalidStatus)
		}

		var daThu float64
		if err := tx.Model(&domain.OrderPayment{}).
			Where("order_id = ?", o.ID).
			Select("COALESCE(SUM(amount), 0)").Scan(&daThu).Error; err != nil {
			return err
		}

		conNo := o.TotalAmount - daThu
		if p.Amount > conNo {
			return fmt.Errorf("%w: đơn chỉ còn nợ %.0f", domain.ErrInvalidStatus, conNo)
		}

		if p.PaidAt.IsZero() {
			p.PaidAt = time.Now()
		}
		if p.PaymentMethod == "" {
			p.PaymentMethod = o.PaymentMethod
		}
		if err := tx.Create(p).Error; err != nil {
			return err
		}

		// Thu nốt phần còn lại thì đóng luôn trạng thái tiền.
		if daThu+p.Amount >= o.TotalAmount {
			if err := tx.Model(&o).Update("payment_status", domain.OrderPaymentPaid).Error; err != nil {
				return err
			}
		}

		return nil
	})
}

// LuotThuCuaDon liệt kê các lượt thu đã ghi của một đơn, cũ trước mới sau.
func (r *orderRepository) LuotThuCuaDon(ctx context.Context, orderID uint) ([]domain.OrderPayment, error) {
	rows := []domain.OrderPayment{}
	err := r.db.WithContext(ctx).
		Where("order_id = ?", orderID).
		Order("paid_at ASC, id ASC").
		Find(&rows).Error

	return rows, err
}
