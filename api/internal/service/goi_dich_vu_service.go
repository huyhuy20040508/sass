package service

import (
	"context"
	"errors"
	"math"
	"time"

	"sass-api/internal/domain"
	"sass-api/internal/dto"
)

// GoiDichVuService trả lời câu hỏi của CHỦ TIỆM: "tôi đang dùng gói nào, còn bao
// nhiêu ngày, và gia hạn thì bao nhiêu tiền".
//
// ĐÂY LÀ CỬA DUY NHẤT đi từ phía cửa hàng sang sổ nền tảng, nên đọc kỹ ranh giới
// của nó trước khi thêm hàm:
//
//   - CHỈ ĐỌC, và chỉ đọc của ĐÚNG một khách. Gia hạn là việc của khu điều hành
//     (`cmd/thue-bao`, màn hình hợp đồng) vì nó đi kèm tiền vào sổ thu — không có
//     đường nào ở đây cho khách tự đẩy hạn của chính mình.
//   - tenantID là THAM SỐ, không đọc lén từ ctx. Bộ lọc tenant tự động của GORM
//     không với tới control plane, nên cái id này là thứ duy nhất ngăn tiệm này
//     đọc hợp đồng của tiệm kia; bắt nơi gọi truyền vào nghĩa là không ai quên nó
//     mà code vẫn trông đúng.
//   - maApp cố định theo TIẾN TRÌNH (cfg.App.Code), không phải tham số của
//     request. Khách chỉ được xem gói của phần mềm họ đang mở, và để nó thành
//     query thì một cửa hàng Order sẽ dò được bảng giá của mọi sản phẩm khác.
type GoiDichVuService interface {
	// CuaToi trả về hợp đồng hiện tại (nil nếu chưa có) kèm bảng giá đang bán.
	CuaToi(ctx context.Context, tenantID uint) (dto.GoiDichVuCuaToiResponse, error)
}

type goiDichVuService struct {
	thueBao domain.ThueBaoCuaKhachRepository
	plans   domain.PlanRepository
	maApp   string
}

// NewGoiDichVuService dựng service cho MỘT phần mềm — maApp là mã app của chính
// tiến trình đang chạy (cfg.App.Code).
func NewGoiDichVuService(
	thueBao domain.ThueBaoCuaKhachRepository, plans domain.PlanRepository, maApp string,
) GoiDichVuService {
	return &goiDichVuService{thueBao: thueBao, plans: plans, maApp: maApp}
}

func (s *goiDichVuService) CuaToi(ctx context.Context, tenantID uint) (dto.GoiDichVuCuaToiResponse, error) {
	if tenantID == 0 {
		// Không xác định được cửa hàng thì KHÔNG đọc gì cả. Đọc "hợp đồng của
		// tenant 0" ra danh sách rỗng nghĩa là màn hình nói "chưa có hợp đồng" cho
		// một request lẽ ra phải bị từ chối.
		return dto.GoiDichVuCuaToiResponse{}, domain.ErrForbidden
	}

	res := dto.GoiDichVuCuaToiResponse{
		BangGia: []dto.PlanItem{},
		Fields:  planFeatureFields(),
	}

	// Hợp đồng trước, bảng giá sau: dòng bảng giá đang được dùng phải giữ lại kể
	// cả khi nó đã ngừng bán, nên bước lọc bên dưới cần biết plan_id của hợp đồng.
	hopDong, err := s.thueBao.HienTai(ctx, tenantID, s.maApp)
	switch {
	case errors.Is(err, domain.ErrNotFound):
		// Cửa hàng chưa có hợp đồng nào trong sổ nền tảng — thật với những tiệm
		// dựng tay trước khi có control plane. Vẫn trả bảng giá: đó chính là thứ
		// người đang không có hợp đồng cần nhìn.
		hopDong = nil
	case err != nil:
		return dto.GoiDichVuCuaToiResponse{}, err
	}

	if hopDong != nil {
		res.HopDong = hopDongCuaToi(*hopDong, time.Now())
	}

	rows, err := s.plans.List(ctx, s.maApp)
	if err != nil {
		return dto.GoiDichVuCuaToiResponse{}, err
	}

	giu := make([]domain.PlanWithApp, 0, len(rows))
	for _, row := range rows {
		if row.Status == domain.PlanStatusActive || dangDung(hopDong, row.ID) {
			giu = append(giu, row)
		}
	}

	ids := make([]uint, 0, len(giu))
	for _, row := range giu {
		ids = append(ids, row.ID)
	}
	features, err := s.plans.FeaturesOf(ctx, ids)
	if err != nil {
		return dto.GoiDichVuCuaToiResponse{}, err
	}

	for _, row := range giu {
		res.BangGia = append(res.BangGia, planItem(row, features[row.ID]))
	}

	return res, nil
}

// dangDung cho biết một dòng bảng giá có phải dòng mà hợp đồng hiện tại đã ký
// hay không.
//
// Đây là chiều tra cứu hợp lệ duy nhất từ hợp đồng sang bảng giá — chỉ để BIẾT
// ĐÓ LÀ DÒNG NÀO, không phải để đọc giá hay hạn mức của nó (xem domain.Plan).
func dangDung(hopDong *domain.HopDongDayDu, planID uint) bool {
	return hopDong != nil && hopDong.PlanID != nil && *hopDong.PlanID == planID
}

// hopDongCuaToi dịch một dòng hợp đồng sang bản dành cho CHỦ TIỆM.
//
// Hẹp hơn hopDongItem (bản của khu điều hành) đúng ở chỗ phải hẹp: không ghi chú
// hợp đồng, không ghi chú khách, không ba ô liên lạc trong sổ nền tảng — đó là
// chỗ người bán ghi việc nội bộ về chính khách này.
//
// homNay truyền vào chứ không gọi time.Now() bên trong, cùng lý do với
// hopDongItem: `con_lai_ngay` phải tính theo một mốc do nơi gọi chọn, để bài
// kiểm thử nói được về một ngày cụ thể.
func hopDongCuaToi(row domain.HopDongDayDu, homNay time.Time) *dto.HopDongCuaToi {
	// Rơi về MÃ gói khi bảng giá không tra ra tên (hợp đồng thoả thuận riêng, hoặc
	// dòng bảng giá đã bị xoá): mã gói xấu nhưng vẫn nói được cái gì đó, còn ô
	// trống thì không.
	tenGoi := string(row.TenGoi)
	if tenGoi == "" {
		tenGoi = row.Plan
	}

	return &dto.HopDongCuaToi{
		TenApp:       row.TenApp,
		Goi:          row.Plan,
		TenGoi:       tenGoi,
		TrangThai:    row.Status,
		ChuKy:        row.BillingCycle,
		Gia:          row.Price,
		ChiNhanh:     row.MaxShops,
		TaiKhoan:     row.MaxUsers,
		SanPham:      row.MaxProducts,
		TenMienRieng: row.OwnDomain,
		BatDau:       row.StartedAt,
		HetHan:       row.EndsAt,
		// So SÁNH THỜI ĐIỂM, không suy từ số ngày. Phép chia bên dưới cắt cụt về
		// 0 trong suốt 24 giờ quanh mốc hết hạn, nên "con_lai_ngay < 0" trả lời
		// SAI đúng vào lúc quan trọng nhất: hai phút sau khi hợp đồng chết, màn
		// hình vẫn xanh và không báo gì.
		DaHetHan: row.EndsAt.Before(homNay),
		// Ceil chứ không cắt cụt, và nó đúng cho cả hai chiều: còn 2 phút ra 1
		// ("còn 1 ngày", cảnh báo sớm còn hơn muộn), quá hạn 2 phút ra 0 ("hết hạn
		// hôm nay"), quá hạn 25 giờ ra -1.
		ConLaiNgay: int(math.Ceil(row.EndsAt.Sub(homNay).Hours() / 24)),
		// `trial_ends_at` là dấu duy nhất phân biệt kỳ dùng thử, và lượt gia hạn
		// đầu tiên xoá nó đi (xem HopDongRepository.GiaHan) — nên nó cũng chính là
		// câu trả lời cho "khách này đã trả tiền lần nào chưa".
		DungThu: row.TrialEndsAt != nil,
	}
}
