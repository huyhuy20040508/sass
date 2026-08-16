package service

import (
	"context"
	"errors"
	"fmt"

	"go.uber.org/zap"

	"sass-api/internal/domain"
	"sass-api/internal/tenant"
	"sass-api/pkg/logger"
)

// HanMucService là CHỖ ÉP ba hạn mức của hợp đồng — thứ mà từ ngày dựng control
// plane tới nay chưa ai viết (xem domain/han_muc.go).
//
// BẮC QUA HAI PLANE và đó là toàn bộ cái khó của nó: trần đọc ở sổ nền tảng
// (control plane), còn số đang dùng đếm ở dữ liệu của chính cửa hàng (data
// plane). Không câu SQL nào làm được cả hai, nên phép so sánh nằm ở đây.
//
// Cửa đọc sang sổ nền tảng là ThueBaoCuaKhachRepository — cùng cửa mà trang
// "Gói dịch vụ" của chủ tiệm đang dùng, và nó cố tình hẹp: đúng một khách, đúng
// phần mềm mà tiến trình này phục vụ.
//
// ĐỌC KỸ CHIỀU HỎNG TRƯỚC KHI SỬA: mọi thứ không chắc chắn ở đây đều rơi về
// CHO QUA, không phải chặn. Sổ nền tảng vừa mất kết nối, cửa hàng chưa có hợp
// đồng nào trong sổ, câu đếm hỏng — cả ba đều là chuyện của nhà cung cấp, và
// biến chúng thành "không thêm được sản phẩm" nghĩa là đem sự cố của mình đổ
// lên đầu người đang bán hàng. Hạn mức là điều khoản bán hàng chứ không phải
// ranh giới bảo mật: lọt một cái thì đòi tiền sau, chặn oan một cái thì mất
// khách.
type HanMucService interface {
	// ConChoTao trả về nil khi cửa hàng còn được thêm một cái nữa, và
	// domain.ErrVuotHanMuc (bọc kèm tên hạn mức, số đang dùng, trần) khi hết chỗ.
	//
	// Gọi NGAY TRƯỚC lượt ghi, sau mọi bước kiểm tra dữ liệu khác: người dùng cần
	// biết form của mình sai chỗ nào trước, rồi mới tới chuyện gói hết chỗ.
	ConChoTao(ctx context.Context, loai domain.LoaiHanMuc) error

	// DangDung đếm số thứ cửa hàng đang có, để màn hình in cạnh trần đã ký.
	//
	// CÙNG PHÉP ĐẾM với ConChoTao, và đó là lý do nó nằm ở đây chứ không ở service
	// của trang gói dịch vụ: hai chỗ đếm hai kiểu thì màn hình nói "còn 3 chỗ"
	// đúng lúc lượt tạo bị từ chối, và người dùng không có cách nào hiểu nổi.
	DangDung(ctx context.Context) (domain.SoDangDung, error)
}

// DemSanPham và DemTaiKhoanNoiBo là hai CỬA ĐẾM HẸP, cắt ra từ ProductRepository
// và UserRepository.
//
// Nhận nguyên hai repository kia cũng chạy, nhưng chúng có hơn ba mươi hàm và
// service này chỉ cần một — bài kiểm thử sẽ phải dựng hai bản giả to bằng cả
// tệp, và người đọc không biết được nó đụng tới những gì. Hai interface một hàm
// nói thẳng ra điều đó, và hiện thực thật thoả mãn chúng sẵn.
type (
	DemSanPham interface {
		Count(ctx context.Context) (int64, error)
	}
	DemTaiKhoanNoiBo interface {
		InternalStats(ctx context.Context) (domain.InternalUserStats, error)
	}
	DemChiNhanh interface {
		Count(ctx context.Context) (int64, error)
	}
)

type hanMucService struct {
	thueBao  domain.ThueBaoCuaKhachRepository
	sanPham  DemSanPham
	taiKhoan DemTaiKhoanNoiBo
	chiNhanh DemChiNhanh
	maApp    string
}

// NewHanMucService dựng cửa xét hạn mức cho MỘT phần mềm — maApp là mã app của
// chính tiến trình đang chạy (cfg.App.Code), không phải tham số của request. Để
// nó thành tham số nghĩa là một cửa hàng Order xét hạn mức theo hợp đồng của
// phần mềm khác mà khách đang mua.
func NewHanMucService(
	thueBao domain.ThueBaoCuaKhachRepository,
	sanPham DemSanPham,
	taiKhoan DemTaiKhoanNoiBo,
	chiNhanh DemChiNhanh,
	maApp string,
) HanMucService {
	return &hanMucService{
		thueBao: thueBao, sanPham: sanPham, taiKhoan: taiKhoan, chiNhanh: chiNhanh, maApp: maApp,
	}
}

func (s *hanMucService) ConChoTao(ctx context.Context, loai domain.LoaiHanMuc) error {
	// Cửa hàng đọc THẲNG TỪ ctx, khác GoiDichVuService (nhận tenantID làm tham
	// số). Không phải vì tiện: ctx này chính là ctx sẽ đi xuống lượt INSERT ngay
	// sau đây, nên trần được xét trên đúng cửa hàng mà dòng mới sắp rơi vào. Bắt
	// nơi gọi truyền tay một con số thứ hai là mở đường cho hai con số lệch nhau
	// — đếm của tiệm này, ghi vào tiệm kia.
	//
	// Không có tenant trong ctx thì KHÔNG xét: hoặc đây là việc của nền tảng
	// (đã bọc tenant.WithoutScope), hoặc lượt ghi ngay sau sẽ tự hỏng ở bộ lọc
	// tenant kèm câu giải thích của nó. Trả lỗi hạn mức ở đây chỉ làm người đọc
	// nhật ký đi nhầm hướng.
	tenantID, ok := tenant.ID(ctx)
	if !ok {
		return nil
	}

	hopDong, err := s.thueBao.HienTai(ctx, tenantID, s.maApp)
	switch {
	case errors.Is(err, domain.ErrNotFound):
		// Chưa có hợp đồng nào trong sổ — thật với những tiệm dựng tay trước khi
		// có control plane. Không hợp đồng thì không có điều khoản nào để ép.
		return nil
	case err != nil:
		logger.Warn("không đọc được hợp đồng để xét hạn mức — cho qua lượt tạo này",
			zap.Uint("tenant_id", tenantID),
			zap.String("ma_app", s.maApp),
			zap.String("han_muc", string(loai)),
			zap.Error(err))

		return nil
	}

	tran := hopDong.TranCua(loai)
	// 0 = không giới hạn ('vo_han' bên bảng giá), khác hẳn "không được cái nào".
	// Xét trước lượt đếm để gói không giới hạn khỏi tốn một câu COUNT mỗi lần
	// thêm sản phẩm.
	if tran == 0 {
		return nil
	}

	dangDung, err := s.dem(ctx, loai)
	if err != nil {
		logger.Warn("không đếm được số đang dùng để xét hạn mức — cho qua lượt tạo này",
			zap.Uint("tenant_id", tenantID),
			zap.String("han_muc", string(loai)),
			zap.Error(err))

		return nil
	}

	if dangDung < int64(tran) {
		return nil
	}

	// Kèm cả ba con số vào lỗi: tên hạn mức để biết đụng trần nào, cặp số để
	// biết trần là bao nhiêu và mình đang ở đâu. Handler in nguyên chúng ra.
	return fmt.Errorf("%w: %s %d/%d", domain.ErrVuotHanMuc, loai.Ten(), dangDung, tran)
}

func (s *hanMucService) DangDung(ctx context.Context) (domain.SoDangDung, error) {
	var so domain.SoDangDung

	taiKhoan, err := s.dem(ctx, domain.HanMucTaiKhoan)
	if err != nil {
		return so, err
	}
	sanPham, err := s.dem(ctx, domain.HanMucSanPham)
	if err != nil {
		return so, err
	}
	chiNhanh, err := s.dem(ctx, domain.HanMucChiNhanh)
	if err != nil {
		return so, err
	}
	so.TaiKhoan, so.SanPham, so.ChiNhanh = taiKhoan, sanPham, chiNhanh

	return so, nil
}

// dem đếm số ĐANG CÓ của một hạn mức ở data plane.
//
// Hạn mức lạ (khoá gõ sai trong code) trả về 0 kèm nil: không có gì để so thì
// không chặn ai. Trả lỗi ở nhánh này là đem lỗi lập trình đổ lên đầu người đang
// bán hàng — cùng lý do với TranCua.
func (s *hanMucService) dem(ctx context.Context, loai domain.LoaiHanMuc) (int64, error) {
	switch loai {
	case domain.HanMucSanPham:
		return s.sanPham.Count(ctx)
	case domain.HanMucChiNhanh:
		// Đếm cả chi nhánh đang NGỪNG HOẠT ĐỘNG, cùng luật với tài khoản bị khoá
		// ngay dưới: nó còn giữ mã, còn đứng trong danh sách, và mở lại là một cú
		// bấm. Xem ChiNhanhRepository.Count.
		return s.chiNhanh.Count(ctx)
	case domain.HanMucTaiKhoan:
		stats, err := s.taiKhoan.InternalStats(ctx)

		// Tính CẢ tài khoản đang khoá (stats.Total, không phải stats.Active): một
		// tài khoản bị khoá vẫn giữ nguyên tên đăng nhập, email và lịch sử thao
		// tác của nó, tức vẫn chiếm một chỗ. Đếm mỗi tài khoản đang hoạt động thì
		// khoá tạm một người là mở ra một chỗ trống — hạn mức lách được bằng hai
		// cú bấm, và mở lại khoá thì vượt trần mà không đường nào phát hiện.
		return stats.Total, err
	}

	return 0, nil
}

// conChoTao gọi cửa xét hạn mức nếu máy chủ có nó.
//
// hanMuc nil nghĩa là tiến trình này CHƯA NỐI ĐƯỢC control plane (xem
// cmd/api/main.go): không đọc được hợp đồng nào thì không có điều khoản nào để
// ép, và phần bán hàng vẫn phải chạy bình thường — đúng như trước khi có tệp
// này. Gom lượt kiểm nil vào một hàm để mỗi nơi gọi còn lại đúng một dòng.
func conChoTao(ctx context.Context, hanMuc HanMucService, loai domain.LoaiHanMuc) error {
	if hanMuc == nil {
		return nil
	}

	return hanMuc.ConChoTao(ctx, loai)
}
