package service

import (
	"context"
	"encoding/json"
	"strconv"
	"strings"
	"unicode/utf8"

	"sass-api/internal/domain"
)

// SettingPOSCashbookTemplates — mẫu phiếu nhanh ở hộp lập phiếu của quầy (JSON,
// tối đa 6). "[]" = chủ tắt hết; mặc định là năm việc quầy hay gặp.
const SettingPOSCashbookTemplates = "pos_cashbook_templates"

// SettingTypeMauPhieu — kiểu ô của khoá trên: giao diện dựng bảng soạn mẫu.
const SettingTypeMauPhieu = "cashbook_templates"

const soMauPhieuToiDa = 6

// mauPhieu — một mẫu như chủ khai. PhanLoai 0 thì dò theo GoiY ("vận chuyển|ship"):
// danh mục mỗi tiệm đặt tên khác nhau nên mẫu mặc định không thể mang sẵn id.
type mauPhieu struct {
	Ten      string  `json:"ten"`
	Loai     uint8   `json:"loai"`
	PhanLoai uint    `json:"phan_loai"`
	GoiY     string  `json:"goi_y,omitempty"`
	MoTa     string  `json:"mo_ta"`
	SoTien   float64 `json:"so_tien"`
}

var mauPhieuMacDinh = func() string {
	b, _ := json.Marshal([]mauPhieu{
		{Ten: "Trả ship", Loai: domain.LoaiChi, GoiY: "vận chuyển|giao hàng|ship", MoTa: "Trả ship đơn ", SoTien: 30000},
		{Ten: "Mua nước đá", Loai: domain.LoaiChi, GoiY: "nước đá|mua", MoTa: "Mua nước đá", SoTien: 20000},
		{Ten: "Mua đồ dùng quầy", Loai: domain.LoaiChi, GoiY: "đồ dùng|văn phòng phẩm|mua", MoTa: "Mua đồ dùng quầy", SoTien: 50000},
		{Ten: "Tạm ứng nhân viên", Loai: domain.LoaiChi, GoiY: "tạm ứng|lương", MoTa: "Tạm ứng cho ", SoTien: 200000},
		{Ten: "Nộp tiền về chủ", Loai: domain.LoaiChi, GoiY: "nộp|rút", MoTa: "Nộp tiền về chủ"},
	})
	return string(b)
}()

// kiemMauPhieu trả câu lỗi cho ô cài đặt, rỗng = hợp lệ.
func kiemMauPhieu(v string) string {
	var ds []mauPhieu
	if err := json.Unmarshal([]byte(v), &ds); err != nil {
		return "Danh sách mẫu không đúng dạng"
	}
	if len(ds) > soMauPhieuToiDa {
		return "Tối đa " + strconv.Itoa(soMauPhieuToiDa) + " mẫu"
	}
	for i, m := range ds {
		so := "Mẫu " + strconv.Itoa(i+1) + ": "
		ten := strings.TrimSpace(m.Ten)
		switch {
		case ten == "":
			return so + "nhập tên hiện trên nút"
		case utf8.RuneCountInString(ten) > 30:
			return so + "tên nút tối đa 30 ký tự"
		case m.Loai != domain.LoaiThu && m.Loai != domain.LoaiChi:
			return so + "loại phiếu chỉ là thu hoặc chi"
		case utf8.RuneCountInString(m.MoTa) > 200:
			return so + "mô tả tối đa 200 ký tự"
		case utf8.RuneCountInString(m.GoiY) > 100:
			return so + "gợi ý phân loại quá dài"
		case m.SoTien < 0 || m.SoTien > 1_000_000_000_000:
			return so + "số tiền gợi ý không hợp lệ"
		}
	}
	return ""
}

// mauPhieuQuay đọc mẫu từ Cài đặt và giải phân loại theo danh mục của tiệm:
// id chủ chọn còn đúng vế thì giữ, không thì dò theo gợi ý, không khớp thì để
// trống (thu ngân tự chọn).
func (s *thuChiService) mauPhieuQuay(ctx context.Context, loai []domain.LoaiThuChi) []domain.MauPhieuThuChi {
	ra := []domain.MauPhieuThuChi{}
	if s.caiDat == nil {
		return ra
	}
	var ds []mauPhieu
	if json.Unmarshal([]byte(s.caiDat.Get(ctx, SettingPOSCashbookTemplates)), &ds) != nil {
		return ra
	}
	for i, m := range ds {
		if i == soMauPhieuToiDa {
			break
		}
		var pl *uint
		for _, l := range loai {
			if l.ID == m.PhanLoai && l.Type == m.Loai {
				id := l.ID
				pl = &id
			}
		}
		if pl == nil && m.PhanLoai == 0 {
		goiY:
			for _, tu := range strings.Split(m.GoiY, "|") {
				if tu = strings.ToLower(strings.TrimSpace(tu)); tu == "" {
					continue
				}
				for _, l := range loai {
					if l.Type == m.Loai && strings.Contains(strings.ToLower(l.Name), tu) {
						id := l.ID
						pl = &id
						break goiY
					}
				}
			}
		}
		ra = append(ra, domain.MauPhieuThuChi{Ten: strings.TrimSpace(m.Ten), Loai: m.Loai, PhanLoai: pl, MoTa: m.MoTa, SoTien: m.SoTien})
	}
	return ra
}
