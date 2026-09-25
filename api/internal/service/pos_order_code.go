package service

import (
	"context"
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"strconv"
	"strings"
	"time"

	"sass-api/internal/dto"
	"sass-api/internal/tenant"
)

// MÃ ĐƠN GIỮ TRƯỚC Ở QUẦY — tab hoá đơn hiện mã đơn ngay khi có món, như v2 cũ.
//
// v2 cũ ghi đơn nháp xuống database từ món đầu tiên nên có mã sớm. Ở đây hoá đơn
// đang mở chỉ nằm trên máy quầy tới lúc chốt (không giữ hàng, không vào báo cáo),
// nên chỉ CẤP TRƯỚC con số: API lấy số kế tiếp từ bộ đếm quy tắc mã rồi ký lên nó.
// Lúc chốt, chữ ký đúng thì đơn vào sổ đúng mã ấy.
//
// Chữ ký là để máy quầy không tự đặt mã tuỳ ý: nó gắn mã với CỬA HÀNG và hạn dùng,
// ký bằng khoá phía server. Không cần bảng giữ chỗ — mã đã có đơn dùng thì lượt chốt
// thứ hai tự cấp mã mới (xem orderRepository.Checkout).

// hanGiuMaDon dài hơn hạn máy quầy giữ một hoá đơn đang mở (12 giờ), để hoá đơn còn
// hiện trên màn hình thì mã của nó vẫn dùng được.
const hanGiuMaDon = 24 * time.Hour

func (s *orderService) POSGiuMaDon(ctx context.Context) (*dto.POSMaDonResponse, error) {
	// Thiếu khoá ký thì không cấp: một mã không ký được là mã lượt chốt sẽ bỏ qua, và
	// tab hiện một con số không bao giờ vào sổ còn tệ hơn tab chưa có mã.
	if len(s.khoaMaDon) == 0 {
		return &dto.POSMaDonResponse{}, nil
	}

	ma, err := s.orderRepo.GiuMaDon(ctx)
	if err != nil {
		return nil, err
	}
	if ma == "" {
		return &dto.POSMaDonResponse{}, nil
	}

	han := time.Now().Add(hanGiuMaDon)

	return &dto.POSMaDonResponse{OrderCode: ma, Token: kyMaDon(ctx, s.khoaMaDon, ma, han), ExpiresAt: &han}, nil
}

// chuoiKyMaDon là nội dung được ký: loại chữ ký + cửa hàng + mã + hạn. Có tiền tố
// loại để chữ ký này không dùng lẫn được sang chỗ khác cùng khoá (token đăng nhập).
func chuoiKyMaDon(ctx context.Context, ma string, han int64) []byte {
	tid, _ := tenant.ID(ctx)

	return []byte("pos-ma-don|" + strconv.FormatUint(uint64(tid), 10) + "|" + ma + "|" + strconv.FormatInt(han, 10))
}

func kyMaDon(ctx context.Context, khoa []byte, ma string, han time.Time) string {
	h := hmac.New(sha256.New, khoa)
	h.Write(chuoiKyMaDon(ctx, ma, han.Unix()))

	return strconv.FormatInt(han.Unix(), 10) + "." + hex.EncodeToString(h.Sum(nil))
}

// maDonGiuHopLe trả mã nếu chữ ký đúng, đúng cửa hàng và còn hạn; "" nếu không.
func maDonGiuHopLe(ctx context.Context, khoa []byte, ma, token string, bay time.Time) string {
	ma = strings.TrimSpace(ma)
	if len(khoa) == 0 || ma == "" || token == "" {
		return ""
	}

	hanChuoi, chuKy, ok := strings.Cut(token, ".")
	if !ok {
		return ""
	}
	han, err := strconv.ParseInt(hanChuoi, 10, 64)
	if err != nil || bay.Unix() > han {
		return ""
	}
	nhan, err := hex.DecodeString(chuKy)
	if err != nil {
		return ""
	}

	h := hmac.New(sha256.New, khoa)
	h.Write(chuoiKyMaDon(ctx, ma, han))
	if !hmac.Equal(nhan, h.Sum(nil)) {
		return ""
	}

	return ma
}
