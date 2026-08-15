package service

import (
	"context"
	"strings"
	"time"

	"sass-api/internal/domain"
	"sass-api/internal/dto"
)

// KhachHangService phục vụ ba màn hình còn lại của khu điều hành: khách hàng,
// hợp đồng (Người dùng thử · Người dùng chính thức), và doanh thu theo quán.
//
// CHẠY TRÊN CONTROL PLANE, không có tenant nào trong ctx và cũng không cần: đây
// là ba đường đọc XUYÊN MỌI KHÁCH HÀNG. Đổi lại, không có lưới nào phía dưới đỡ
// hộ — mọi hàm nhận `quyen` và phạm vi được chốt ở đúng một chỗ (phamVi), để
// không hàm nào quên.
//
// TIỀN ĐỌC Ở SỔ THU, KHÔNG SUY TỪ HỢP ĐỒNG. `subscriptions.price` là điều
// khoản; doanh thu là `invoices` (migration 0014). Hai thứ đó chỉ bằng nhau vào
// tháng mà mọi khách trả đủ và đúng hạn.
type KhachHangService interface {
	// KhachHang liệt kê khách hàng của một phần mềm.
	KhachHang(ctx context.Context, quyen domain.QuyenApp, maApp, trangThai string) (dto.KhachHangResponse, error)
	// HopDong liệt kê thuê bao; trangThai rỗng = mọi trạng thái, nhom rỗng = cả
	// hai nhóm (xem domain.NhomDungThu).
	HopDong(ctx context.Context, quyen domain.QuyenApp, maApp, trangThai, nhom string) (dto.HopDongResponse, error)
	// DoanhThu cộng tiền đã thu theo từng cửa hàng trong khoảng [tu, den).
	DoanhThu(ctx context.Context, quyen domain.QuyenApp, maApp string, tu, den *time.Time) (dto.DoanhThuResponse, error)
}

type khachHangService struct {
	repo domain.KhachHangRepository
}

func NewKhachHangService(repo domain.KhachHangRepository) KhachHangService {
	return &khachHangService{repo: repo}
}

// phamVi dịch "người này được phụ trách gì" thành bộ lọc của repository.
//
// Một chỗ duy nhất cho cả ba màn hình. Chép luật này vào từng hàm là cách chắc
// chắn để một hàm nào đó quên vế `else`, và vế quên sẽ luôn là vế mở.
func phamVi(quyen domain.QuyenApp, maApp, trangThai string) (domain.LocKhuDieuHanh, error) {
	loc := domain.LocKhuDieuHanh{TrangThai: strings.TrimSpace(trangThai)}
	maApp = strings.TrimSpace(maApp)

	if maApp != "" {
		// Hỏi đích danh một phần mềm không được giao: nói thẳng là không phụ
		// trách. Trả danh sách rỗng thì người ta đọc thành "phần mềm này chưa có
		// khách nào" — một câu sai về tình hình kinh doanh.
		if !quyen.ChoPhep(maApp) {
			return loc, domain.ErrKhongPhuTrachApp
		}
		loc.MaApp = []string{maApp}

		return loc, nil
	}

	if quyen.ToanQuyen {
		// nil = không lọc. Chỉ owner đi nhánh này.
		return loc, nil
	}

	// Danh sách được giao — RỖNG cũng giữ nguyên là rỗng, và repository dịch nó
	// thành "không dòng nào". Đừng đổi rỗng thành nil ở đây: nil nghĩa là xem
	// được tất.
	loc.MaApp = quyen.Ma

	return loc, nil
}

func (s *khachHangService) KhachHang(ctx context.Context, quyen domain.QuyenApp, maApp, trangThai string) (dto.KhachHangResponse, error) {
	loc, err := phamVi(quyen, maApp, trangThai)
	if err != nil {
		return dto.KhachHangResponse{}, err
	}

	rows, err := s.repo.KhachHang(ctx, loc)
	if err != nil {
		return dto.KhachHangResponse{}, err
	}

	res := dto.KhachHangResponse{KhachHang: make([]dto.KhachHangItem, 0, len(rows))}
	for _, row := range rows {
		res.KhachHang = append(res.KhachHang, dto.KhachHangItem{
			ID:          row.ID,
			Ma:          row.Code,
			Ten:         row.Name,
			TrangThai:   row.Status,
			NguoiLienHe: string(row.ContactName),
			DienThoai:   string(row.ContactPhone),
			Email:       string(row.ContactEmail),
			SoHopDong:   row.SoHopDong,
			NgayVaoSo:   row.CreatedAt,
		})
	}

	return res, nil
}

func (s *khachHangService) HopDong(ctx context.Context, quyen domain.QuyenApp, maApp, trangThai, nhom string) (dto.HopDongResponse, error) {
	loc, err := phamVi(quyen, maApp, trangThai)
	if err != nil {
		return dto.HopDongResponse{}, err
	}
	loc.Nhom = strings.TrimSpace(nhom)

	rows, err := s.repo.HopDong(ctx, loc)
	if err != nil {
		return dto.HopDongResponse{}, err
	}

	homNay := time.Now()
	res := dto.HopDongResponse{HopDong: make([]dto.HopDongItem, 0, len(rows))}
	for _, row := range rows {
		res.HopDong = append(res.HopDong, hopDongItem(row, homNay))
	}

	return res, nil
}

// hopDongItem dịch một dòng hợp đồng sang DTO.
//
// Tách ra khỏi vòng lặp vì hai đường GHI (gia hạn · huỷ, xem DungThuService)
// cũng trả về đúng hình dạng này để màn hình cập nhật lại dòng vừa đổi. Chép
// phép dịch ra hai bản thì `con_lai_ngay` sẽ được tính bằng hai cách, và cách
// thứ hai sẽ là cách sai.
//
// homNay truyền vào chứ không gọi time.Now() bên trong: cả một danh sách phải
// được tính theo CÙNG một mốc, nếu không hai dòng cạnh nhau có thể lệch một ngày
// chỉ vì vòng lặp chạy qua nửa đêm.
func hopDongItem(row domain.HopDongDayDu, homNay time.Time) dto.HopDongItem {
	// Rơi về MÃ gói khi bảng giá không tra ra tên: hợp đồng thoả thuận riêng
	// (plan_id nil) hoặc dòng bảng giá đã bị xoá. Chốt chặn đặt Ở ĐÂY, một chỗ,
	// để mọi màn hình khỏi tự nghĩ ra cách xử lý ô trống — và nghĩ khác nhau.
	tenGoi := string(row.TenGoi)
	if tenGoi == "" {
		tenGoi = row.Plan
	}

	return dto.HopDongItem{
		ID:         row.ID,
		MaCuaHang:  row.MaCuaHang,
		TenCuaHang: row.TenCuaHang,
		MaApp:      row.MaApp,
		TenApp:     row.TenApp,
		Goi:        row.Plan,
		TenGoi:     tenGoi,
		TrangThai:  row.Status,
		ChuKy:      row.BillingCycle,
		Gia:        row.Price,
		// Hạn mức đọc THẲNG ở hợp đồng, không tra sang bảng giá — xem
		// domain.Subscription. 0 = không giới hạn, và màn hình phải dịch ra chữ
		// chứ đừng in số 0.
		ChiNhanh:     row.MaxShops,
		TaiKhoan:     row.MaxUsers,
		SanPham:      row.MaxProducts,
		TenMienRieng: row.OwnDomain,
		BatDau:       row.StartedAt,
		HetHan:       row.EndsAt,
		ConLaiNgay:   int(row.EndsAt.Sub(homNay).Hours() / 24),
		NguoiLienHe:  string(row.NguoiLienHe),
		DienThoai:    string(row.DienThoai),
		Email:        string(row.Email),
	}
}

func (s *khachHangService) DoanhThu(ctx context.Context, quyen domain.QuyenApp, maApp string, tu, den *time.Time) (dto.DoanhThuResponse, error) {
	loc, err := phamVi(quyen, maApp, "")
	if err != nil {
		return dto.DoanhThuResponse{}, err
	}
	loc.Tu, loc.Den = tu, den

	rows, err := s.repo.DoanhThu(ctx, loc)
	if err != nil {
		return dto.DoanhThuResponse{}, err
	}

	res := dto.DoanhThuResponse{TheoQuan: make([]dto.DoanhThuItem, 0, len(rows))}
	for _, row := range rows {
		lanCuoi := ""
		if row.LanCuoi != nil {
			lanCuoi = *row.LanCuoi
		}
		res.TheoQuan = append(res.TheoQuan, dto.DoanhThuItem{
			MaCuaHang:  row.MaCuaHang,
			TenCuaHang: row.TenCuaHang,
			SoLanThu:   row.SoLanThu,
			TongTien:   row.TongTien,
			LanCuoi:    lanCuoi,
		})
		res.TongTien += row.TongTien
		res.SoLanThu += row.SoLanThu
	}

	return res, nil
}
