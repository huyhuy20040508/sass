package service

import (
	"context"
	"time"

	"go.uber.org/zap"

	"sass-api/internal/domain"
	"sass-api/pkg/logger"
)

// QuetHanService khoá cửa hàng của những hợp đồng đã hết hạn.
//
// VÌ SAO PHẢI CÓ. Cả cơ chế chặn người hết hạn đã có sẵn từ trước và chạy đúng:
// `tenants.status = 'suspended'` thì authService.LoginShop từ chối lượt đăng
// nhập mới, còn middleware.JWTAuth đá luôn phiên đang mở ra ở request kế tiếp.
// Thứ THIẾU là không chỗ nào GHI cột đó xuống — hợp đồng dùng thử hết hạn chỉ
// lặng lẽ thành một dòng đỏ trong khu điều hành, còn khách vẫn bán hàng bình
// thường cho tới khi có người mở database ra khoá tay. Đây là mảnh còn thiếu
// đó, không phải một cơ chế chặn thứ hai.
//
// VÌ SAO LÀ LƯỢT QUÉT NỀN, không phải một lượt kiểm ngay trong JWTAuth. Hợp
// đồng nằm ở CONTROL PLANE còn xác thực chạy trên DATA PLANE: hỏi hợp đồng ở
// mỗi request là thêm một lượt đọc bắc qua database thứ hai vào đường đi nóng
// nhất hệ thống, và biến một sự cố của control plane thành sự cố đăng nhập của
// mọi khách hàng. Quét nền thì cái giá chỉ là độ trễ: khách hết hạn còn dùng
// thêm tối đa một nhịp quét.
//
// CHẠM CẢ HAI PLANE, cùng ràng buộc với DungThuService: không giao dịch nào bao
// được cả hai, nên thứ tự ghi là một quyết định — xem QuetMotLuot.
type QuetHanService interface {
	// QuetMotLuot chạy đúng một lượt: tìm hợp đồng quá hạn, khoá khách của
	// chúng, đánh dấu hợp đồng `past_due`.
	//
	// An toàn khi gọi lại nhiều lần: lượt sau không thấy gì để làm nữa.
	QuetMotLuot(ctx context.Context) (KetQuaQuet, error)
	// Chay quét mỗi `nhip` một lần cho tới khi ctx bị huỷ. Quét NGAY một lượt
	// lúc gọi, không chờ hết nhịp đầu tiên: khởi động lại máy chủ là lúc thường
	// gặp nhất của một khoảng ngừng dài, và hợp đồng chết trong khoảng đó phải
	// được dọn ngay chứ không chờ thêm một nhịp nữa.
	Chay(ctx context.Context, nhip time.Duration)
}

// KetQuaQuet là kết quả MỘT lượt quét, để nơi gọi ghi nhật ký và để bài kiểm
// thử đối chiếu.
type KetQuaQuet struct {
	// QuaHan là số hợp đồng vừa phát hiện đã quá hạn.
	QuaHan int
	// DaKhoa là số cửa hàng THẬT SỰ đổi từ 'active' sang 'suspended' ở data
	// plane — tức số khách vừa mất quyền vào phần mềm trong lượt này.
	DaKhoa int64
	// GiuLai là số khách quá hạn một phần mềm nhưng còn hợp đồng sống ở phần
	// mềm khác, nên KHÔNG bị khoá.
	GiuLai int
}

type quetHanService struct {
	hopDong domain.HopDongRepository
	cuaHang domain.CuaHangMoiRepository
}

// NewQuetHanService nhận repository hợp đồng của CONTROL PLANE và repository
// cửa hàng của DATA PLANE — đúng cặp mà DungThuService cầm, và vì cùng lý do:
// đây là hai việc duy nhất trong hệ thống phải ghi sang cả hai bên.
func NewQuetHanService(hopDong domain.HopDongRepository, cuaHang domain.CuaHangMoiRepository) QuetHanService {
	return &quetHanService{hopDong: hopDong, cuaHang: cuaHang}
}

// NhipQuetMacDinh — khoảng cách giữa hai lượt quét.
//
// Năm phút là đổi giữa hai thứ: khách hết hạn dùng thêm bao lâu, và bao nhiêu
// câu truy vấn rỗng mỗi ngày. Lượt quét không tìm thấy gì chỉ tốn một câu SELECT
// có chỉ mục, nên năm phút đã là dư dả — hạ xuống một phút cũng không sai, chỉ
// là không mua thêm được gì: chẳng hợp đồng nào tính hạn theo phút.
const NhipQuetMacDinh = 5 * time.Minute

func (s *quetHanService) Chay(ctx context.Context, nhip time.Duration) {
	if nhip <= 0 {
		nhip = NhipQuetMacDinh
	}

	tick := time.NewTicker(nhip)
	defer tick.Stop()

	logger.Info("bật lượt quét hợp đồng quá hạn", zap.Duration("nhip", nhip))

	for {
		// Hỏng thì GHI LẠI rồi chờ nhịp sau, không dừng vòng lặp. Control plane
		// trục trặc mười phút mà lượt quét chết hẳn thì mọi hợp đồng hết hạn kể từ
		// đó không ai dọn nữa, cho tới lần khởi động lại tiếp theo — mà không có
		// dấu hiệu nào cho thấy điều đó đang xảy ra.
		if kq, err := s.QuetMotLuot(ctx); err != nil {
			// ctx bị huỷ = máy chủ đang tắt, không phải sự cố.
			if ctx.Err() != nil {
				logger.Info("dừng lượt quét hợp đồng quá hạn")
				return
			}
			logger.Error("lượt quét hợp đồng quá hạn hỏng — sẽ thử lại ở nhịp sau", zap.Error(err))
		} else if kq.QuaHan > 0 {
			logger.Info("đã quét hợp đồng quá hạn",
				zap.Int("so_hop_dong", kq.QuaHan),
				zap.Int64("so_cua_hang_da_khoa", kq.DaKhoa),
				zap.Int("so_cua_hang_giu_lai", kq.GiuLai))
		}

		select {
		case <-ctx.Done():
			logger.Info("dừng lượt quét hợp đồng quá hạn")
			return
		case <-tick.C:
		}
	}
}

func (s *quetHanService) QuetMotLuot(ctx context.Context) (KetQuaQuet, error) {
	var kq KetQuaQuet

	bayGio := time.Now()

	ds, err := s.hopDong.QuaHan(ctx, bayGio)
	if err != nil {
		return kq, err
	}
	if len(ds) == 0 {
		return kq, nil
	}
	kq.QuaHan = len(ds)

	// Khách nào còn hợp đồng sống ở phần mềm khác thì KHÔNG khoá: `tenants.status`
	// là của cả khách hàng, không của riêng một phần mềm.
	ungVien := make([]uint, 0, len(ds))
	daCo := make(map[uint]bool, len(ds))
	for _, hd := range ds {
		if !daCo[hd.TenantID] {
			daCo[hd.TenantID] = true
			ungVien = append(ungVien, hd.TenantID)
		}
	}

	conSong, err := s.hopDong.ConHopDongSong(ctx, ungVien, bayGio)
	if err != nil {
		return kq, err
	}
	thaRa := make(map[uint]bool, len(conSong))
	for _, id := range conSong {
		thaRa[id] = true
	}

	canKhoa := make([]uint, 0, len(ungVien))
	for _, id := range ungVien {
		if thaRa[id] {
			kq.GiuLai++
			continue
		}
		canKhoa = append(canKhoa, id)
	}

	// Nhật ký ghi TRƯỚC lượt ghi database, một dòng cho mỗi hợp đồng. Đây là việc
	// nặng nhất máy chủ tự làm mà không ai bấm nút — khách gọi lên hỏi "sao sáng
	// nay tôi không vào được" thì đây là chỗ trả lời, và câu trả lời phải có kể cả
	// khi lượt ghi bên dưới hỏng nửa chừng.
	for _, hd := range ds {
		if thaRa[hd.TenantID] {
			continue
		}
		logger.Info("hợp đồng đã quá hạn — khoá cửa hàng",
			zap.Uint("hop_dong_id", hd.ID),
			zap.String("ma_cua_hang", hd.MaCuaHang),
			zap.String("ma_app", hd.MaApp),
			zap.Time("het_han", hd.HetHan),
			zap.Bool("dung_thu", hd.DungThu))
	}

	// DATA PLANE TRƯỚC, control plane sau, và thứ tự này là một quyết định.
	//
	// Cột bên data plane là chốt chặn thật; cột bên control plane chỉ để khu điều
	// hành nhìn đúng. Ghi sổ nền tảng trước rồi hỏng ở bước sau thì màn hình báo
	// "đã khoá" trong khi khách vẫn bán hàng bình thường — hụt kiểu đó không ai
	// phát hiện ra. Chiều này hụt thì ngược lại: khách bị khoá thật, sổ chưa kịp
	// ghi, và lượt quét sau dọn nốt.
	khoa, err := s.cuaHang.DoiTrangThai(ctx, canKhoa, domain.TenantSuspended)
	if err != nil {
		return kq, err
	}
	kq.DaKhoa = khoa

	if _, err := s.hopDong.DoiTrangThaiKhach(ctx, canKhoa, domain.TenantSuspended); err != nil {
		return kq, err
	}

	// Đánh dấu hợp đồng CUỐI CÙNG, và cũng vì chuyện hụt: `past_due` chính là thứ
	// loại hợp đồng ra khỏi lượt quét sau (xem QuaHan). Ghi nó trước mà hai bước
	// khoá ở trên hỏng thì không lượt quét nào quay lại dọn nữa — khách hết hạn
	// dùng tiếp vĩnh viễn, và trong sổ thì họ đang ở trạng thái quá hạn đàng hoàng.
	ids := make([]uint, 0, len(ds))
	for _, hd := range ds {
		ids = append(ids, hd.ID)
	}
	if _, err := s.hopDong.DanhDauQuaHan(ctx, ids); err != nil {
		return kq, err
	}

	return kq, nil
}
