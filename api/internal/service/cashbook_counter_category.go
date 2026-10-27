package service

import (
	"context"
	"strconv"
	"strings"
	"time"

	"sass-api/internal/domain"
)

// Dãy phân loại ở hộp lập phiếu của quầy: tối đa 6 viên mỗi vế, ít nhất 4 nếu đủ
// loại hợp. Thứ tự: chủ ghim → hay dùng ở chi nhánh 30 ngày → loại "việc của quầy".
const (
	soVienToiDa      = 6
	soVienToiThieu   = 4
	phanLoaiHayDungN = 30 * 24 * time.Hour
)

// Tên loại khớp các từ này là việc thật ở quầy (trả ship, mua nước đá, tạm ứng…)
// — dùng khi chi nhánh chưa có phiếu nào. Danh mục do từng tiệm tự đặt tên nên
// chỉ dò được theo chữ.
var tuViecQuay = []string{"vận chuyển", "giao hàng", "ship", "mua", "nước đá", "đồ dùng", "văn phòng phẩm",
	"tạm ứng", "nộp", "sửa chữa", "chăm sóc khách", "thu khác", "khác", "công nợ", "cọc"}

// Khoản chủ tiệm trả định kỳ: không bao giờ tự lên dãy (vẫn ở "Loại khác…"),
// trừ khi chủ ghim hoặc quầy thật sự dùng.
var tuKhoanCuaChu = []string{"lương", "thuê mặt bằng", "điện, nước", "phí thanh toán", "khấu hao", "thuế",
	"phần mềm", "công tác phí"}

// caiDatDoc — phần Cài đặt mà Thu chi cần; nil (test cũ) = không có phân loại ghim.
type caiDatDoc interface {
	Get(ctx context.Context, key string) string
}

func (s *thuChiService) DungCaiDat(c caiDatDoc) { s.caiDat = c }

// docIDGhim đọc "24,14,2" thành danh sách id (bỏ phần hỏng, bỏ trùng).
func docIDGhim(v string) []uint {
	var ds []uint
	gap := map[uint]bool{}
	for _, p := range strings.Split(v, ",") {
		n, err := strconv.ParseUint(strings.TrimSpace(p), 10, 64)
		if err != nil || n == 0 || gap[uint(n)] {
			continue
		}
		gap[uint(n)] = true
		ds = append(ds, uint(n))
	}
	return ds
}

func coTu(ten string, tu []string) bool {
	ten = strings.ToLower(ten)
	for _, t := range tu {
		if strings.Contains(ten, t) {
			return true
		}
	}
	return false
}

// xepPhanLoaiNhanh ghép dãy cho cả hai vế; trả về dãy và phần ghim còn hiệu lực.
func xepPhanLoaiNhanh(loai []domain.LoaiThuChi, ghim, hayDung []uint) (nhanh, conGhim []uint) {
	theoID := map[uint]domain.LoaiThuChi{}
	for _, l := range loai {
		theoID[l.ID] = l
	}
	for _, ve := range []uint8{domain.LoaiThu, domain.LoaiChi} {
		var day []uint
		co := map[uint]bool{}
		them := func(id uint) {
			if l, ok := theoID[id]; ok && l.Type == ve && !co[id] && len(day) < soVienToiDa {
				co[id] = true
				day = append(day, id)
			}
		}
		for _, id := range ghim {
			n := len(day)
			them(id)
			if len(day) > n {
				conGhim = append(conGhim, id)
			}
		}
		for _, id := range hayDung {
			them(id)
		}
		for _, tu := range tuViecQuay {
			for _, l := range loai {
				if len(day) < soVienToiThieu && strings.Contains(strings.ToLower(l.Name), tu) && !coTu(l.Name, tuKhoanCuaChu) {
					them(l.ID)
				}
			}
		}
		for _, l := range loai {
			if len(day) < soVienToiThieu && !coTu(l.Name, tuKhoanCuaChu) {
				them(l.ID)
			}
		}
		nhanh = append(nhanh, day...)
	}
	return nhanh, conGhim
}

func (s *thuChiService) phanLoaiNhanh(ctx context.Context, loai []domain.LoaiThuChi) (nhanh, ghim []uint, err error) {
	shopID, err := s.repo.ChiNhanhMacDinh(ctx)
	if err != nil {
		return nil, nil, err
	}
	hayDung, err := s.repo.PhanLoaiHayDung(ctx, shopID, time.Now().Add(-phanLoaiHayDungN))
	if err != nil {
		return nil, nil, err
	}
	if s.caiDat != nil {
		ghim = docIDGhim(s.caiDat.Get(ctx, SettingPOSCashbookPinnedTypes))
	}
	nhanh, ghim = xepPhanLoaiNhanh(loai, ghim, hayDung)
	// Mảng rỗng chứ không null: màn quầy duyệt thẳng hai danh sách này.
	if nhanh == nil {
		nhanh = []uint{}
	}
	if ghim == nil {
		ghim = []uint{}
	}
	return nhanh, ghim, nil
}
