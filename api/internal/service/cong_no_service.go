package service

import (
	"context"

	"sass-api/internal/domain"
)

// CongNoService — nghiệp vụ màn Công nợ (Thu chi → Công nợ).
//
// CHỈ ĐỌC, và đó là toàn bộ thiết kế của cụm này. Ghi một lượt trả nợ đi bằng
// PhieuMuaHangService.Pay — đường ấy khoá dòng phiếu, cập nhật `paid_amount`
// cùng `payment_status` và ghi `purchase_payments` trong MỘT giao dịch. Mở thêm
// đường ghi thứ hai vào đúng mấy cột ấy là dựng sẵn chỗ cho hai con số lệch
// nhau, đúng cái v2 vướng: DebtController cộng `cab_debts.paid` còn
// PurchaseOrderController cộng `pch_orders.payment_total_price`, không ai đối
// chiếu hai bên bao giờ.
type CongNoService interface {
	List(ctx context.Context, f domain.CongNoFilter) ([]domain.CongNo, int64, domain.CongNoTongKet, error)
	// LichSuTra là sổ từng lượt trả của một khoản nợ. `id` là id PHIẾU MUA.
	LichSuTra(ctx context.Context, id uint) ([]domain.PurchasePayment, error)
}

type congNoService struct {
	repo domain.CongNoRepository
	// muaRepo để xác nhận phiếu có thật và thuộc cửa hàng đang gọi trước khi trả
	// sổ trả tiền của nó. Đọc thẳng purchase_payments theo id truyền vào là để
	// người ta dò id lần lượt mà đọc sổ của cửa hàng khác.
	muaRepo domain.PurchaseOrderRepository
}

func NewCongNoService(repo domain.CongNoRepository, muaRepo domain.PurchaseOrderRepository) CongNoService {
	return &congNoService{repo: repo, muaRepo: muaRepo}
}

func (s *congNoService) List(ctx context.Context, f domain.CongNoFilter) ([]domain.CongNo, int64, domain.CongNoTongKet, error) {
	return s.repo.List(ctx, f)
}

func (s *congNoService) LichSuTra(ctx context.Context, id uint) ([]domain.PurchasePayment, error) {
	// Trả về ĐÚNG lỗi của lượt tra phiếu: id không có thật phải là 404 chứ không
	// phải một sổ rỗng — sổ rỗng đọc ra là "phiếu này chưa trả đồng nào", một
	// câu khác hẳn.
	if _, err := s.muaRepo.FindByID(ctx, id); err != nil {
		return nil, err
	}

	return s.repo.LichSuTra(ctx, id)
}
