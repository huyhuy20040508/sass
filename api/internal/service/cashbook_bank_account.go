package service

import (
	"context"

	"sass-api/internal/domain"
	"sass-api/internal/dto"
)

// kiemTaiKhoan — phiếu CHUYỂN KHOẢN phải ghi tài khoản (khi chi nhánh đã khai ít
// nhất một tài khoản đang bật): không có thì đối sao kê không ghép được phiếu.
// Tài khoản phải thuộc chi nhánh của phiếu; đã tắt thì chỉ được giữ ở phiếu cũ.
// Phiếu tiền mặt trả nil (xoá tài khoản nếu đổi từ chuyển khoản sang).
func (s *thuChiService) kiemTaiKhoan(ctx context.Context, req *dto.ThuChiRequest, shopID uint, cu *domain.ThuChi) (*uint, error) {
	if req.PaymentMethod != "transfer" {
		return nil, nil
	}
	ds, err := s.repo.TaiKhoanNganHang(ctx, shopID, false)
	if err != nil {
		return nil, err
	}
	sai := func(cau string) error { return loiO(map[string]string{"bank_account_id": cau}) }
	if req.BankAccountID == nil || *req.BankAccountID == 0 {
		for _, tk := range ds {
			if tk.DangBat {
				return nil, sai("Chọn tài khoản nhận / chuyển của phiếu chuyển khoản.")
			}
		}
		return nil, nil
	}
	for _, tk := range ds {
		if tk.ID != *req.BankAccountID {
			continue
		}
		giuCu := cu != nil && cu.BankAccountID != nil && *cu.BankAccountID == tk.ID
		if !tk.DangBat && !giuCu {
			return nil, sai("Tài khoản " + tk.Nhan + " đang tắt — chọn tài khoản khác.")
		}
		id := tk.ID
		return &id, nil
	}
	return nil, sai("Tài khoản không thuộc chi nhánh của phiếu.")
}

func (s *thuChiService) TaiKhoan(ctx context.Context, choLap bool, shopLoc uint) ([]domain.TaiKhoanThuChi, error) {
	if !choLap {
		return s.repo.TaiKhoanNganHang(ctx, shopLoc, false)
	}
	shopID, err := s.repo.ChiNhanhMacDinh(ctx)
	if err != nil {
		return nil, err
	}
	return s.repo.TaiKhoanNganHang(ctx, shopID, true)
}
