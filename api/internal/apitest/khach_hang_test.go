package apitest

import (
	"context"
	"encoding/json"
	"net/http"
	"testing"
	"time"

	"sass-api/internal/domain"
)

// Bộ kiểm BA MÀN HÌNH CÒN LẠI của khu điều hành: khách hàng, hợp đồng (Người
// dùng thử · Người dùng chính thức), doanh thu theo quán.
//
// Ba đường này đọc XUYÊN MỌI KHÁCH HÀNG — không có bộ lọc tenant nào phía dưới
// đỡ hộ, nên phạm vi duy nhất giới hạn chúng là phân công phần mềm. Vì vậy bài
// kiểm nặng nhất ở đây không phải "trả về đúng dữ liệu" mà là "KHÔNG trả về dữ
// liệu của phần mềm không được giao".
//
// Bài thứ hai đáng giá không kém: doanh thu phải đọc SỔ THU, không suy từ giá
// hợp đồng. Cách duy nhất kiểm được điều đó là dựng một hợp đồng giá cao mà chỉ
// thu về một phần — nếu ai đó "tối ưu" bằng cách cộng `subscriptions.price` thì
// con số sẽ nhảy lên và bài này đỏ.

// khachThu là một khách hàng giả trong SỔ NỀN TẢNG (control plane).
//
// id đặt ở dải cao và cố định theo bộ kiểm: bảng `tenants` bên control plane
// không AUTO_INCREMENT (id là số chung với data plane — xem 0001), nên bài kiểm
// phải tự cấp số, và số đó không được đụng cửa hàng thật của máy đang chạy.
type khachThu struct {
	id      uint
	ma      string
	hopDong uint
}

const (
	idKhachA = 990001
	idKhachB = 990002
)

// gieoKhachVaHopDong dựng một khách hàng + một hợp đồng cho app cho trước.
//
// Ghi thẳng bằng GORM chứ không gọi `cmd/thue-bao`: bài kiểm này kiểm API, còn
// lệnh kia là một tiến trình khác với .env khác.
func gieoKhachVaHopDong(t *testing.T, k *khuDieuHanh, id uint, ma, maApp, trangThai string, gia float64) *khachThu {
	t.Helper()
	nen := context.Background()

	if err := k.nenTang.WithContext(nen).Exec(
		`INSERT INTO tenants (id, code, name, status, created_at, updated_at)
		 VALUES (?, ?, ?, 'active', NOW(3), NOW(3))
		 ON DUPLICATE KEY UPDATE code = VALUES(code), name = VALUES(name)`,
		id, ma, "Khách "+ma).Error; err != nil {
		t.Fatalf("không gieo được khách %s: %v", ma, err)
	}

	// Chèn rồi đọc lại id, KHÔNG dùng RETURNING: MariaDB 10.4 (máy cục bộ) chưa
	// có mệnh đề đó, và một câu SQL chỉ chạy trên một trong hai engine là đúng
	// kiểu lệch mà cả cụm migration đang tránh.
	if err := k.nenTang.WithContext(nen).Exec(
		`INSERT INTO subscriptions
		   (tenant_id, app_id, plan, status, billing_cycle, price,
		    max_shops, max_users, max_products, own_domain,
		    started_at, ends_at, created_at, updated_at)
		 SELECT ?, a.id, 'khoi_dau', ?, 'thang', ?, 1, 2, 500, 0,
		        NOW(3), DATE_ADD(NOW(3), INTERVAL 30 DAY), NOW(3), NOW(3)
		   FROM apps a WHERE a.code = ?`, id, trangThai, gia, maApp).Error; err != nil {
		t.Fatalf("không gieo được hợp đồng cho %s: %v", ma, err)
	}

	var hopDongID uint
	if err := k.nenTang.WithContext(nen).Raw(
		`SELECT id FROM subscriptions WHERE tenant_id = ? AND status <> 'canceled'`, id).
		Scan(&hopDongID).Error; err != nil || hopDongID == 0 {
		t.Fatalf("không đọc lại được hợp đồng của %s: %v", ma, err)
	}

	t.Cleanup(func() { xoaKhachThu(t, k, id) })

	return &khachThu{id: id, ma: ma, hopDong: hopDongID}
}

// gieoLanThu ghi một lần TIỀN VÀO cho hợp đồng.
func gieoLanThu(t *testing.T, k *khuDieuHanh, hopDongID uint, soTien float64, tuNgay time.Time) {
	t.Helper()

	if err := k.nenTang.WithContext(context.Background()).Exec(
		`INSERT INTO invoices (subscription_id, amount, period_start, period_end, paid_at, method, created_at, updated_at)
		 VALUES (?, ?, ?, DATE_ADD(?, INTERVAL 30 DAY), ?, 'chuyen_khoan', NOW(3), NOW(3))`,
		hopDongID, soTien, tuNgay, tuNgay, tuNgay).Error; err != nil {
		t.Fatalf("không gieo được lần thu: %v", err)
	}
}

// xoaKhachThu dọn theo đúng chiều khoá ngoại: tiền → hợp đồng → khách.
func xoaKhachThu(t *testing.T, k *khuDieuHanh, id uint) {
	t.Helper()
	nen := context.Background()

	for _, cau := range []string{
		"DELETE i FROM invoices i JOIN subscriptions s ON s.id = i.subscription_id WHERE s.tenant_id = ?",
		"DELETE FROM subscriptions WHERE tenant_id = ?",
		"DELETE FROM tenants WHERE id = ?",
	} {
		if err := k.nenTang.WithContext(nen).Exec(cau, id).Error; err != nil {
			t.Fatalf("không dọn được khách thử (%s): %v", cau, err)
		}
	}
}

// TestKhachHang_ChiThayKhachCuaPhanMemDuocGiao — chốt quan trọng nhất của tệp.
//
// Hai khách: một mua phần mềm của bộ kiểm, một mua app 'order' (do migration
// nạp sẵn, KHÔNG được giao cho người này). Cả ba màn hình phải chỉ thấy khách
// thứ nhất.
func TestKhachHang_ChiThayKhachCuaPhanMemDuocGiao(t *testing.T) {
	k := dungKhuDieuHanh(t, domain.PlatformRoleOperator)

	cuaToi := gieoKhachVaHopDong(t, k, idKhachA, "khach-a", appDieuHanh, domain.SubscriptionActive, 500000)
	cuaNguoiKhac := gieoKhachVaHopDong(t, k, idKhachB, "khach-b", domain.AppOrder, domain.SubscriptionActive, 900000)
	gieoLanThu(t, k, cuaToi.hopDong, 500000, time.Now())
	gieoLanThu(t, k, cuaNguoiKhac.hopDong, 900000, time.Now())

	for _, duong := range []string{
		"/api/v1/platform/tenants",
		"/api/v1/platform/subscriptions",
		"/api/v1/platform/doanh-thu",
	} {
		res := k.goi(t, k.token, http.MethodGet, duong, nil)
		if res.ma != http.StatusOK {
			t.Fatalf("%s hỏng: %d\n%s", duong, res.ma, catBot(res.than))
		}
		if !chuaDauVet(res.than, "khach-a") {
			t.Fatalf("%s thiếu khách của phần mềm được giao:\n%s", duong, catBot(res.than))
		}
		if chuaDauVet(res.than, "khach-b") {
			t.Fatalf("RÒ RỈ: %s trả về khách của phần mềm KHÔNG được giao:\n%s", duong, catBot(res.than))
		}
	}

	// Hỏi đích danh phần mềm không được giao: 403, không phải danh sách rỗng.
	// Rỗng đọc thành "phần mềm này chưa có khách nào" — một câu sai về tình hình
	// kinh doanh.
	for _, duong := range []string{
		"/api/v1/platform/tenants?app=" + domain.AppOrder,
		"/api/v1/platform/subscriptions?app=" + domain.AppOrder,
		"/api/v1/platform/doanh-thu?app=" + domain.AppOrder,
	} {
		if res := k.goi(t, k.token, http.MethodGet, duong, nil); res.ma != http.StatusForbidden {
			t.Fatalf("%s phải trả 403, nhận %d\n%s", duong, res.ma, catBot(res.than))
		}
	}
}

// TestKhachHang_NguoiDungThuVaChinhThuc — hai màn hình, một đường, khác mỗi bộ lọc.
func TestKhachHang_NguoiDungThuVaChinhThuc(t *testing.T) {
	k := dungKhuDieuHanh(t, domain.PlatformRoleOperator)

	gieoKhachVaHopDong(t, k, idKhachA, "khach-thu", appDieuHanh, domain.SubscriptionTrial, 199000)
	gieoKhachVaHopDong(t, k, idKhachB, "khach-tra-tien", appDieuHanh, domain.SubscriptionActive, 499000)

	dungThu := k.docHopDong(t, "?trang_thai="+domain.SubscriptionTrial)
	if len(dungThu) != 1 || dungThu[0].MaCuaHang != "khach-thu" {
		t.Fatalf("màn hình Người dùng thử phải ra đúng khách đang dùng thử, nhận %+v", dungThu)
	}
	chinhThuc := k.docHopDong(t, "?trang_thai="+domain.SubscriptionActive)
	if len(chinhThuc) != 1 || chinhThuc[0].MaCuaHang != "khach-tra-tien" {
		t.Fatalf("màn hình Người dùng chính thức phải ra đúng khách đã trả tiền, nhận %+v", chinhThuc)
	}

	// Hạn mức trong câu trả lời phải là hạn mức CỦA HỢP ĐỒNG, đọc thẳng chứ
	// không tra sang bảng giá — gói 'khoi_dau' của bộ kiểm khai 2 tài khoản, hợp
	// đồng cũng gieo 2, nên bài này chỉ đỏ khi ai đó nối lại đường tra ngược rồi
	// đọc nhầm sang một dòng bảng giá khác.
	if chinhThuc[0].TaiKhoan != 2 || chinhThuc[0].SanPham != 500 {
		t.Fatalf("hạn mức phải lấy từ hợp đồng: %+v", chinhThuc[0])
	}
	if chinhThuc[0].ConLaiNgay < 0 {
		t.Fatalf("hợp đồng còn 30 ngày mà báo quá hạn: %d", chinhThuc[0].ConLaiNgay)
	}
}

// TestDoanhThu_DocSoThuChuKhongSuyTuGiaHopDong — bài kiểm đắt nhất của tệp.
//
// Hợp đồng 9.000.000đ nhưng khách mới trả 2.000.000đ. Doanh thu phải là con số
// ĐÃ THU. Ai đó cộng `subscriptions.price` cho nhanh thì bài này đỏ ngay.
func TestDoanhThu_DocSoThuChuKhongSuyTuGiaHopDong(t *testing.T) {
	k := dungKhuDieuHanh(t, domain.PlatformRoleOperator)
	khach := gieoKhachVaHopDong(t, k, idKhachA, "khach-tra-thieu", appDieuHanh, domain.SubscriptionActive, 9_000_000)

	// Chưa ghi lần thu nào: doanh thu là 0, KHÔNG phải 9 triệu.
	if dt := k.docDoanhThu(t, ""); dt.TongTien != 0 || dt.SoLanThu != 0 {
		t.Fatalf("chưa thu đồng nào mà báo cáo ra tiền: %+v — đang suy từ giá hợp đồng?", dt)
	}

	gieoLanThu(t, k, khach.hopDong, 2_000_000, time.Now())

	dt := k.docDoanhThu(t, "")
	if dt.TongTien != 2_000_000 {
		t.Fatalf("doanh thu phải là TIỀN ĐÃ THU (2.000.000), nhận %v", dt.TongTien)
	}
	if dt.SoLanThu != 1 || len(dt.TheoQuan) != 1 || dt.TheoQuan[0].MaCuaHang != "khach-tra-thieu" {
		t.Fatalf("báo cáo theo quán sai: %+v", dt)
	}
}

// TestDoanhThu_LocTheoKhoangNgay — khoảng lọc là NGÀY TIỀN VÀO, và `den` là mốc
// chặn trên KHÔNG bao gồm.
func TestDoanhThu_LocTheoKhoangNgay(t *testing.T) {
	k := dungKhuDieuHanh(t, domain.PlatformRoleOperator)
	khach := gieoKhachVaHopDong(t, k, idKhachA, "khach-hai-lan", appDieuHanh, domain.SubscriptionActive, 500000)

	homNay := time.Now()
	thangTruoc := homNay.AddDate(0, -1, 0)
	gieoLanThu(t, k, khach.hopDong, 500000, thangTruoc)
	// Lần thu thứ hai phải khác `period_start` — khoá uq_invoices_ky chặn hai lần
	// thu cho cùng một kỳ, và đó là ràng buộc bài kiểm này phải tôn trọng chứ
	// không né.
	gieoLanThu(t, k, khach.hopDong, 300000, homNay)

	if dt := k.docDoanhThu(t, ""); dt.TongTien != 800000 {
		t.Fatalf("không lọc thì phải cộng cả hai lần thu, nhận %v", dt.TongTien)
	}

	// Chỉ tháng này: mốc `tu` = đầu hôm nay, `den` = ngày mai.
	tu := homNay.Format("2006-01-02")
	den := homNay.AddDate(0, 0, 1).Format("2006-01-02")
	if dt := k.docDoanhThu(t, "?tu="+tu+"&den="+den); dt.TongTien != 300000 {
		t.Fatalf("lọc theo ngày tiền vào sai: mong 300.000, nhận %v", dt.TongTien)
	}

	// Khoảng trong tương lai: rỗng, và tổng phải là 0 chứ không phải toàn bộ.
	sau := homNay.AddDate(0, 1, 0).Format("2006-01-02")
	if dt := k.docDoanhThu(t, "?tu="+sau); dt.TongTien != 0 || len(dt.TheoQuan) != 0 {
		t.Fatalf("khoảng chưa có lần thu nào phải ra 0: %+v", dt)
	}

	// Ngày gõ sai định dạng: 422, KHÔNG được âm thầm thành "từ trước tới nay" —
	// một ngày gõ nhầm mà lặng lẽ bỏ qua bộ lọc là báo cáo ra con số của cả đời.
	if res := k.goi(t, k.token, http.MethodGet, "/api/v1/platform/doanh-thu?tu=01-08-2026", nil); res.ma != http.StatusUnprocessableEntity {
		t.Fatalf("ngày sai định dạng phải 422, nhận %d\n%s", res.ma, catBot(res.than))
	}
}

// TestHopDong_ConKyDeThu — ô quyết định màn hình có chìa nút "Thu tiền" ra không.
//
// Sổ thu chỉ nhận MỘT lần thu cho một kỳ (uq_invoices_ky), nên hợp đồng đã trả
// tiền tới đúng hạn hiện tại thì lượt thu tiếp theo chắc chắn bị từ chối
// (ErrKhongConKyDeThu). Bài này canh việc danh sách nói ra điều đó TRƯỚC khi có
// người bấm — chứ không phải chìa nút ra rồi báo lỗi.
//
// Ba tình huống, và tình huống thứ ba là chỗ dễ làm ẩu nhất: trả THIẾU vẫn còn
// kỳ để thu. Ai đó rút gọn thành "có dòng nào trong sổ thu chưa" thì bài đỏ ở
// đúng chỗ đó — và cái sai ấy sẽ khoá luôn đường thu nốt phần còn lại của khách.
func TestHopDong_ConKyDeThu(t *testing.T) {
	k := dungKhuDieuHanh(t, domain.PlatformRoleOperator)

	// Hợp đồng gieo ra: bắt đầu hôm nay, hết hạn sau 30 ngày.
	duKy := gieoKhachVaHopDong(t, k, idKhachA, "khach-tra-du", appDieuHanh, domain.SubscriptionActive, 500000)
	thieuKy := gieoKhachVaHopDong(t, k, idKhachB, "khach-tra-thieu-ky", appDieuHanh, domain.SubscriptionActive, 500000)

	// 1. Chưa thu lần nào — còn nguyên kỳ đầu tiên.
	hd := timHopDong(t, k.docHopDong(t, ""), "khach-tra-du")
	if !hd.ConKyDeThu || hd.DaThuDen != nil {
		t.Fatalf("hợp đồng chưa thu đồng nào phải còn kỳ để thu: %+v", hd)
	}

	// 2. Đã trả trọn kỳ (period_end = hạn hợp đồng) — hết kỳ để thu.
	gieoLanThu(t, k, duKy.hopDong, 500000, time.Now())
	hd = timHopDong(t, k.docHopDong(t, ""), "khach-tra-du")
	if hd.ConKyDeThu {
		t.Fatalf("khách đã trả tới đúng hạn mà vẫn báo còn kỳ để thu — màn hình sẽ chìa nút Thu tiền ra rồi ăn lỗi: %+v", hd)
	}
	if hd.DaThuDen == nil {
		t.Fatalf("thu rồi mà không trả về kỳ đã thu tới đâu: %+v", hd)
	}

	// 3. Mới trả tới GIỮA kỳ — vẫn thu tiếp được phần còn lại.
	gieoLanThu(t, k, thieuKy.hopDong, 200000, time.Now().AddDate(0, 0, -20))
	hd = timHopDong(t, k.docHopDong(t, ""), "khach-tra-thieu-ky")
	if !hd.ConKyDeThu {
		t.Fatalf("khách mới trả tới giữa kỳ mà đã tắt đường thu nốt phần còn lại: %+v", hd)
	}
}

// TestKhachHang_ChuaGiaoPhanMemNaoThiRong — người mới thêm vào sổ điều hành
// đăng nhập được nhưng chưa thấy gì, và đó là câu trả lời ĐÚNG.
func TestKhachHang_ChuaGiaoPhanMemNaoThiRong(t *testing.T) {
	k := dungKhuDieuHanh(t, domain.PlatformRoleOperator)
	gieoKhachVaHopDong(t, k, idKhachA, "khach-a", appDieuHanh, domain.SubscriptionActive, 500000)
	thuApp(t, k.heThong, k.email, appDieuHanh)

	for _, duong := range []string{
		"/api/v1/platform/tenants",
		"/api/v1/platform/subscriptions",
		"/api/v1/platform/doanh-thu",
	} {
		res := k.goi(t, k.token, http.MethodGet, duong, nil)
		if res.ma != http.StatusOK {
			t.Fatalf("%s phải 200 (rỗng), nhận %d\n%s", duong, res.ma, catBot(res.than))
		}
		if chuaDauVet(res.than, "khach-a") {
			t.Fatalf("RÒ RỈ: chưa được giao phần mềm nào mà %s vẫn trả dữ liệu:\n%s", duong, catBot(res.than))
		}
	}
}

// ---------- phụ trợ ----------

type hopDongThu struct {
	MaCuaHang  string  `json:"ma_cua_hang"`
	TrangThai  string  `json:"trang_thai"`
	TaiKhoan   uint    `json:"tai_khoan"`
	SanPham    uint    `json:"san_pham"`
	ConLaiNgay int     `json:"con_lai_ngay"`
	ConKyDeThu bool    `json:"con_ky_de_thu"`
	DaThuDen   *string `json:"da_thu_den"`
}

// timHopDong nhặt đúng dòng của một cửa hàng trong danh sách.
func timHopDong(t *testing.T, ds []hopDongThu, ma string) hopDongThu {
	t.Helper()

	for _, hd := range ds {
		if hd.MaCuaHang == ma {
			return hd
		}
	}
	t.Fatalf("không thấy hợp đồng của %s trong danh sách: %+v", ma, ds)

	return hopDongThu{}
}

func (k *khuDieuHanh) docHopDong(t *testing.T, truyVan string) []hopDongThu {
	t.Helper()

	res := k.goi(t, k.token, http.MethodGet, "/api/v1/platform/subscriptions"+truyVan, nil)
	if res.ma != http.StatusOK {
		t.Fatalf("đọc hợp đồng hỏng: %d\n%s", res.ma, catBot(res.than))
	}

	var body struct {
		Data struct {
			HopDong []hopDongThu `json:"hop_dong"`
		} `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &body); err != nil {
		t.Fatalf("không đọc được phản hồi: %v — %s", err, catBot(res.than))
	}

	return body.Data.HopDong
}

type doanhThuThu struct {
	TheoQuan []struct {
		MaCuaHang string  `json:"ma_cua_hang"`
		SoLanThu  int     `json:"so_lan_thu"`
		TongTien  float64 `json:"tong_tien"`
	} `json:"theo_quan"`
	TongTien float64 `json:"tong_tien"`
	SoLanThu int     `json:"so_lan_thu"`
}

func (k *khuDieuHanh) docDoanhThu(t *testing.T, truyVan string) doanhThuThu {
	t.Helper()

	res := k.goi(t, k.token, http.MethodGet, "/api/v1/platform/doanh-thu"+truyVan, nil)
	if res.ma != http.StatusOK {
		t.Fatalf("đọc doanh thu hỏng: %d\n%s", res.ma, catBot(res.than))
	}

	var body struct {
		Data doanhThuThu `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &body); err != nil {
		t.Fatalf("không đọc được phản hồi: %v — %s", err, catBot(res.than))
	}

	return body.Data
}
