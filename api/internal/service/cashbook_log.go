package service

import (
	"context"
	"encoding/json"
	"strconv"
	"strings"
	"unicode/utf8"

	"sass-api/internal/domain"
)

// canLyDo — sửa / xoá phiếu thu chi phải có lý do: số tiền đã vào két mà đổi
// không lời giải thích thì nhật ký chỉ còn ghi được "ai", không ghi được "vì sao".
func canLyDo(v, viec string) (string, error) {
	v = strings.TrimSpace(v)
	switch {
	case v == "":
		return "", loiO(map[string]string{"reason": "Ghi lý do " + viec + " phiếu."})
	case utf8.RuneCountInString(v) > 200:
		return "", loiO(map[string]string{"reason": "Lý do tối đa 200 ký tự."})
	}
	return v, nil
}

// dungNhatKy dựng dòng activity_logs cho lượt sửa (moi != nil) hoặc xoá.
// Tên phân loại chụp lại lúc ghi: phân loại có thể bị đổi tên / xoá về sau.
func (s *thuChiService) dungNhatKy(ctx context.Context, cu, moi *domain.ThuChi, hanh, lyDo string, actor uint) *domain.ActivityLog {
	ten := func(id *uint) string {
		if id == nil {
			return ""
		}
		if l, _ := s.repo.TimPhanLoai(ctx, *id); l != nil {
			return l.Name
		}
		return "#" + strconv.FormatUint(uint64(*id), 10)
	}
	td := domain.ThayDoiThuChi{Ma: cu.Code, Loai: cu.Type, Ca: cu.ShiftID, SoTienCu: cu.Amount,
		PhanLoaiCu: ten(cu.CategoryID), HinhThucCu: cu.PaymentMethod, MoTaCu: cu.Note}
	if moi != nil {
		tien, pl, ht, mt := moi.Amount, ten(moi.CategoryID), moi.PaymentMethod, moi.Note
		td.SoTienMoi, td.PhanLoaiMoi, td.HinhThucMoi, td.MoTaMoi = &tien, &pl, &ht, &mt
	}
	b, _ := json.Marshal(td)
	id, ai := cu.ID, actor
	return &domain.ActivityLog{ShopID: cu.ShopID, UserID: &ai, Action: hanh, SubjectType: domain.NhatKyThuChiLoai,
		SubjectID: &id, Description: lyDo, Properties: string(b)}
}
