package service

import (
	"context"
	"errors"
	"strings"
	"testing"

	"sass-api/internal/domain"
	"sass-api/internal/tenant"
)

// Bài kiểm của CHỖ ÉP HẠN MỨC.
//
// Hai nhóm, và nhóm thứ hai mới là nhóm dễ hỏng về sau: một nhóm kiểm nó CHẶN
// đúng lúc hết chỗ, nhóm còn lại kiểm nó KHÔNG chặn ở mọi tình huống không chắc
// chắn — sổ nền tảng hỏng, cửa hàng chưa có hợp đồng, gói bán không giới hạn.
// Người sửa tệp này sau sẽ thấy nhóm thứ hai kỳ quặc ("sao lỗi lại trả nil?"),
// nên lý do nằm ngay trong tên từng bài: chặn oan một cửa hàng đang trả tiền tệ
// hơn hẳn lọt một sản phẩm.

// ---------- sổ giả ----------
//
// fakeThueBaoCuaKhach dùng lại của goi_dich_vu_test.go — cùng gói, và nó đã ghi
// nhớ sẵn tham số nó được gọi cùng, thứ bài kiểm dưới đây cần.

type demSanPhamGia struct {
	so  int64
	loi error
	lan int
}

func (d *demSanPhamGia) Count(context.Context) (int64, error) {
	d.lan++

	return d.so, d.loi
}

type demChiNhanhGia struct {
	so  int64
	loi error
	lan int
}

func (d *demChiNhanhGia) Count(context.Context) (int64, error) {
	d.lan++

	return d.so, d.loi
}

type demTaiKhoanGia struct {
	stats domain.InternalUserStats
	loi   error
	lan   int
}

func (d *demTaiKhoanGia) InternalStats(context.Context) (domain.InternalUserStats, error) {
	d.lan++

	return d.stats, d.loi
}

// hopDongVoi dựng một hợp đồng chỉ có ba hạn mức — phần còn lại không nằm trên
// đường đi của service này.
func hopDongVoi(chiNhanh, taiKhoan, sanPham uint) *domain.HopDongDayDu {
	return &domain.HopDongDayDu{
		Subscription: domain.Subscription{
			MaxShops: chiNhanh, MaxUsers: taiKhoan, MaxProducts: sanPham,
		},
	}
}

const tenantThu = uint(7)

func ctxCuaHang() context.Context {
	return tenant.WithID(context.Background(), tenantThu)
}

// ---------- chặn đúng lúc hết chỗ ----------

func TestHanMucChanKhiDayTran(t *testing.T) {
	thueBao := &fakeThueBaoCuaKhach{hopDong: hopDongVoi(1, 5, 20)}
	sanPham := &demSanPhamGia{so: 20}
	svc := NewHanMucService(thueBao, sanPham, &demTaiKhoanGia{}, &demChiNhanhGia{}, domain.AppOrder)

	err := svc.ConChoTao(ctxCuaHang(), domain.HanMucSanPham)
	if !errors.Is(err, domain.ErrVuotHanMuc) {
		t.Fatalf("đang dùng 20/20 mà vẫn cho tạo thêm: %v", err)
	}

	// Câu lỗi phải mang cả tên hạn mức lẫn cặp số: handler in nguyên nó ra cho
	// người dùng, và "không tạo được" thì họ không sửa được gì.
	if !strings.Contains(err.Error(), "sản phẩm 20/20") {
		t.Fatalf("lỗi không nói rõ đụng trần nào và trần bao nhiêu: %q", err)
	}
}

func TestHanMucChanCaKhiDaVuotTran(t *testing.T) {
	// Vượt trần là chuyện có thật: hạn mức của hợp đồng hạ xuống sau khi khách đã
	// nhập hàng, hoặc hai lượt tạo chạy song song cùng lọt qua. So sánh phải là
	// ">=" chứ không phải "==", nếu không thì đúng lúc đã lố, cửa lại mở toang.
	thueBao := &fakeThueBaoCuaKhach{hopDong: hopDongVoi(1, 5, 20)}
	svc := NewHanMucService(thueBao, &demSanPhamGia{so: 31}, &demTaiKhoanGia{}, &demChiNhanhGia{}, domain.AppOrder)

	if err := svc.ConChoTao(ctxCuaHang(), domain.HanMucSanPham); !errors.Is(err, domain.ErrVuotHanMuc) {
		t.Fatalf("đang dùng 31/20 mà vẫn cho tạo thêm: %v", err)
	}
}

func TestHanMucChoTaoKhiConCho(t *testing.T) {
	thueBao := &fakeThueBaoCuaKhach{hopDong: hopDongVoi(1, 5, 20)}
	svc := NewHanMucService(thueBao, &demSanPhamGia{so: 19}, &demTaiKhoanGia{}, &demChiNhanhGia{}, domain.AppOrder)

	if err := svc.ConChoTao(ctxCuaHang(), domain.HanMucSanPham); err != nil {
		t.Fatalf("đang dùng 19/20 mà bị chặn: %v", err)
	}
}

// Tài khoản đang KHOÁ vẫn chiếm một chỗ: nó giữ nguyên tên đăng nhập, email và
// lịch sử của mình. Đếm mỗi tài khoản đang hoạt động thì khoá tạm một người là
// mở ra một chỗ trống, và mở khoá lại là vượt trần mà không ai thấy.
func TestHanMucTaiKhoanTinhCaNguoiDangKhoa(t *testing.T) {
	thueBao := &fakeThueBaoCuaKhach{hopDong: hopDongVoi(1, 5, 20)}
	taiKhoan := &demTaiKhoanGia{stats: domain.InternalUserStats{Total: 5, Active: 3, Inactive: 2}}
	svc := NewHanMucService(thueBao, &demSanPhamGia{}, taiKhoan, &demChiNhanhGia{}, domain.AppOrder)

	if err := svc.ConChoTao(ctxCuaHang(), domain.HanMucTaiKhoan); !errors.Is(err, domain.ErrVuotHanMuc) {
		t.Fatalf("5 tài khoản (3 đang chạy, 2 đang khoá) trên trần 5 mà vẫn cho thêm: %v", err)
	}
}

// Hợp đồng phải được tra bằng ĐÚNG cửa hàng trong ctx và đúng phần mềm của tiến
// trình. Sai vế đầu là xét trần của tiệm khác; sai vế sau là xét theo hợp đồng
// của một phần mềm mà khách mua riêng.
func TestHanMucTraHopDongDungCuaHangDungApp(t *testing.T) {
	thueBao := &fakeThueBaoCuaKhach{hopDong: hopDongVoi(1, 5, 20)}
	svc := NewHanMucService(thueBao, &demSanPhamGia{so: 1}, &demTaiKhoanGia{}, &demChiNhanhGia{}, domain.AppOrder)

	if err := svc.ConChoTao(ctxCuaHang(), domain.HanMucSanPham); err != nil {
		t.Fatalf("lượt tạo hợp lệ bị chặn: %v", err)
	}
	if thueBao.goiVoiID != tenantThu {
		t.Fatalf("tra hợp đồng của cửa hàng %d, đáng lẽ %d", thueBao.goiVoiID, tenantThu)
	}
	if thueBao.goiVoiMa != domain.AppOrder {
		t.Fatalf("tra hợp đồng của phần mềm %q, đáng lẽ %q", thueBao.goiVoiMa, domain.AppOrder)
	}
}

// ---------- không chặn khi không chắc chắn ----------

// 0 = 'vo_han' bên bảng giá. Không giới hạn thì cũng không tốn một câu COUNT cho
// mỗi lượt thêm sản phẩm.
func TestHanMucKhongGioiHanThiKhongDem(t *testing.T) {
	thueBao := &fakeThueBaoCuaKhach{hopDong: hopDongVoi(1, 0, 0)}
	sanPham := &demSanPhamGia{so: 999999}
	svc := NewHanMucService(thueBao, sanPham, &demTaiKhoanGia{}, &demChiNhanhGia{}, domain.AppOrder)

	if err := svc.ConChoTao(ctxCuaHang(), domain.HanMucSanPham); err != nil {
		t.Fatalf("gói không giới hạn mà vẫn chặn: %v", err)
	}
	if sanPham.lan != 0 {
		t.Fatalf("gói không giới hạn mà vẫn đếm %d lần", sanPham.lan)
	}
}

// Cửa hàng dựng tay trước khi có sổ hợp đồng: không hợp đồng thì không có điều
// khoản nào để ép, và đó là trạng thái HỢP LỆ chứ không phải lỗi.
func TestHanMucChuaCoHopDongThiKhongEp(t *testing.T) {
	svc := NewHanMucService(
		&fakeThueBaoCuaKhach{loi: domain.ErrNotFound}, &demSanPhamGia{so: 10_000},
		&demTaiKhoanGia{}, &demChiNhanhGia{}, domain.AppOrder)

	if err := svc.ConChoTao(ctxCuaHang(), domain.HanMucSanPham); err != nil {
		t.Fatalf("chưa có hợp đồng mà vẫn chặn: %v", err)
	}
}

// Sổ nền tảng hỏng là sự cố của NHÀ CUNG CẤP. Biến nó thành "không thêm được
// sản phẩm" là đem sự cố của mình đổ lên đầu người đang bán hàng.
func TestHanMucSoNenTangHongThiChoQua(t *testing.T) {
	svc := NewHanMucService(
		&fakeThueBaoCuaKhach{loi: errors.New("mất kết nối control plane")},
		&demSanPhamGia{so: 10_000}, &demTaiKhoanGia{}, &demChiNhanhGia{}, domain.AppOrder)

	if err := svc.ConChoTao(ctxCuaHang(), domain.HanMucSanPham); err != nil {
		t.Fatalf("sổ nền tảng hỏng mà lại chặn khách: %v", err)
	}
}

// Câu đếm hỏng: cùng lý do trên. Không biết đang dùng bao nhiêu thì không có cơ
// sở nào để từ chối.
func TestHanMucDemHongThiChoQua(t *testing.T) {
	svc := NewHanMucService(
		&fakeThueBaoCuaKhach{hopDong: hopDongVoi(1, 5, 20)},
		&demSanPhamGia{loi: errors.New("database bận")}, &demTaiKhoanGia{}, &demChiNhanhGia{}, domain.AppOrder)

	if err := svc.ConChoTao(ctxCuaHang(), domain.HanMucSanPham); err != nil {
		t.Fatalf("đếm hỏng mà lại chặn khách: %v", err)
	}
}

// ctx không mang cửa hàng: hoặc đây là việc của nền tảng, hoặc lượt ghi ngay sau
// sẽ tự hỏng ở bộ lọc tenant kèm câu giải thích của nó. Đọc hợp đồng của "cửa
// hàng số 0" mới là thứ không được phép xảy ra.
func TestHanMucKhongCoCuaHangTrongCtxThiKhongTraSo(t *testing.T) {
	thueBao := &fakeThueBaoCuaKhach{hopDong: hopDongVoi(1, 1, 1)}
	svc := NewHanMucService(thueBao, &demSanPhamGia{so: 99}, &demTaiKhoanGia{}, &demChiNhanhGia{}, domain.AppOrder)

	if err := svc.ConChoTao(context.Background(), domain.HanMucSanPham); err != nil {
		t.Fatalf("ctx chưa xác định cửa hàng mà lại trả lỗi hạn mức: %v", err)
	}
	if thueBao.goiVoiID != 0 {
		t.Fatalf("đã tra sổ nền tảng bằng cửa hàng %d dù ctx chưa xác định được ai", thueBao.goiVoiID)
	}
}

// ---------- số đang dùng cho màn hình ----------

// Trang "Gói dịch vụ" đọc số đang dùng bằng CHÍNH cửa đếm của lượt chặn. Hai chỗ
// đếm hai kiểu thì màn hình nói "còn 3 chỗ" đúng lúc lượt tạo bị từ chối.
func TestHanMucDangDungDemCaHaiLoai(t *testing.T) {
	svc := NewHanMucService(
		&fakeThueBaoCuaKhach{}, &demSanPhamGia{so: 128},
		&demTaiKhoanGia{stats: domain.InternalUserStats{Total: 4, Active: 3, Inactive: 1}},
		&demChiNhanhGia{so: 2}, domain.AppOrder)

	so, err := svc.DangDung(ctxCuaHang())
	if err != nil {
		t.Fatalf("không đếm được số đang dùng: %v", err)
	}
	if so.SanPham != 128 || so.TaiKhoan != 4 || so.ChiNhanh != 2 {
		t.Fatalf("đếm ra %d sản phẩm / %d tài khoản / %d chi nhánh, đáng lẽ 128 / 4 / 2",
			so.SanPham, so.TaiKhoan, so.ChiNhanh)
	}
}

// ---------- chi nhánh: hạn mức mà gói Chuỗi bán ----------

// Gói Khởi đầu và gói Cửa hàng chốt MỘT chi nhánh, mà cửa hàng nào cũng được
// dựng sẵn một chi nhánh 'mac-dinh'. Nên với hai gói đó, lượt mở chi nhánh đầu
// tiên đã là lượt vượt trần — và đó chính là chỗ gói Chuỗi có lý do tồn tại.
func TestHanMucChiNhanhGoiMotChiNhanhThiKhongMoThemDuoc(t *testing.T) {
	thueBao := &fakeThueBaoCuaKhach{hopDong: hopDongVoi(1, 5, 20)}
	svc := NewHanMucService(
		thueBao, &demSanPhamGia{}, &demTaiKhoanGia{}, &demChiNhanhGia{so: 1}, domain.AppOrder)

	err := svc.ConChoTao(ctxCuaHang(), domain.HanMucChiNhanh)
	if !errors.Is(err, domain.ErrVuotHanMuc) {
		t.Fatalf("gói 1 chi nhánh, đang có 1, mà vẫn cho mở thêm: %v", err)
	}
	if !strings.Contains(err.Error(), "chi nhánh 1/1") {
		t.Fatalf("lỗi không nói rõ đụng trần chi nhánh: %q", err)
	}
}

func TestHanMucChiNhanhGoiChuoiThiMoDuoc(t *testing.T) {
	thueBao := &fakeThueBaoCuaKhach{hopDong: hopDongVoi(3, 5, 20)}
	svc := NewHanMucService(
		thueBao, &demSanPhamGia{}, &demTaiKhoanGia{}, &demChiNhanhGia{so: 1}, domain.AppOrder)

	if err := svc.ConChoTao(ctxCuaHang(), domain.HanMucChiNhanh); err != nil {
		t.Fatalf("gói 3 chi nhánh, đang có 1, mà bị chặn: %v", err)
	}
}
