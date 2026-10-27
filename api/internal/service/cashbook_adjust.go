package service

import (
	"context"
	"errors"

	"sass-api/internal/domain"
	"sass-api/internal/dto"
)

// kiemPhieuGoc — lập phiếu điều chỉnh: phiếu gốc phải là phiếu TAY, cùng chi
// nhánh, thuộc ca ĐÃ ĐÓNG (phiếu còn sửa được thì sửa thẳng, không đẻ thêm phiếu).
// Trả nil khi không phải lượt điều chỉnh.
func (s *thuChiService) kiemPhieuGoc(ctx context.Context, req *dto.ThuChiRequest, shopID uint) (*domain.ThuChi, error) {
	if req.AdjustsID == nil || *req.AdjustsID == 0 {
		return nil, nil
	}
	sai := func(cau string) error { return loiO(map[string]string{"adjusts_id": cau}) }
	goc, err := s.repo.FindByID(ctx, *req.AdjustsID)
	if errors.Is(err, domain.ErrNotFound) || err == nil && goc.ShopID != shopID {
		return nil, sai("Phiếu gốc không tồn tại ở chi nhánh này.")
	}
	if err != nil {
		return nil, err
	}
	if goc.TuSinh() {
		return nil, sai("Phiếu tự sinh từ chứng từ khác — điều chỉnh ở chứng từ gốc.")
	}
	daDong := false
	if goc.ShiftID != nil {
		if daDong, err = s.repo.CaDaDong(ctx, *goc.ShiftID); err != nil {
			return nil, err
		}
	}
	if !daDong {
		return nil, sai("Phiếu gốc chưa khoá — sửa thẳng phiếu ấy thay vì lập phiếu điều chỉnh.")
	}
	req.LoaiGoc = goc.CategoryID
	return goc, nil
}
