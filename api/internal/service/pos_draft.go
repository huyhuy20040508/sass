package service

import (
	"context"
	"encoding/json"
	"fmt"
	"strings"
	"time"

	"go.uber.org/zap"

	"sass-api/internal/domain"
	"sass-api/internal/dto"
	"sass-api/pkg/logger"
)

// ĐƠN NHÁP Ở QUẦY — hoá đơn đang mở, có mã đơn từ món đầu tiên (như v2 cũ).
//
// Không giữ kho và không vào báo cáo: kho trừ, tiền tính lại, ngày giờ đơn đều do
// lượt chốt (POSCheckout kèm draft_id) quyết định, đúng lúc khách trả tiền.

// duLieuToiDa — phần "dữ liệu khác" của một hoá đơn (giảm giá, khuyến mãi đã chọn,
// thông tin hoá đơn điện tử…) không bao giờ tới cỡ này; quá là gửi nhầm thứ khác.
const duLieuToiDa = 32 * 1024

func (s *orderService) POSTaoNhap(ctx context.Context, req *dto.POSNhapRequest, actorID uint) (*dto.POSNhapResponse, error) {
	d := &domain.DonNhapQuay{CreatedBy: actorID}
	if err := ganNhap(d, req, actorID); err != nil {
		return nil, err
	}
	if err := s.orderRepo.TaoNhapQuay(ctx, d); err != nil {
		return nil, err
	}

	return nhapRaDTO(d), nil
}

func (s *orderService) POSSuaNhap(ctx context.Context, id uint, req *dto.POSNhapRequest, actorID uint) (*dto.POSNhapResponse, error) {
	d, err := s.orderRepo.SuaNhapQuay(ctx, id, func(d *domain.DonNhapQuay) error {
		return ganNhap(d, req, actorID)
	})
	if err != nil {
		return nil, err
	}

	return nhapRaDTO(d), nil
}

// POSHuyNhap — huỷ hoá đơn đang mở. Dòng được GIỮ LẠI với lý do (như v2): lịch sử
// còn thấy hoá đơn nào bị bỏ, ai bỏ, vì sao; mã đơn của nó không cấp lại.
func (s *orderService) POSHuyNhap(ctx context.Context, id uint, lyDo string, actorID uint) (*dto.POSNhapResponse, error) {
	lyDo = strings.TrimSpace(lyDo)
	if lyDo == "" {
		return nil, &LoiTheoO{Fields: map[string]string{"ly_do": "Nhập lý do huỷ hoá đơn"}}
	}
	d, err := s.orderRepo.SuaNhapQuay(ctx, id, func(d *domain.DonNhapQuay) error {
		now := time.Now()
		uid := actorID
		d.Status = domain.NhapQuayDaHuy
		d.CancelReason = lyDo
		d.CancelledBy = &uid
		d.CancelledAt = &now
		d.UpdatedBy = &uid

		return nil
	})
	if err != nil {
		return nil, err
	}

	return nhapRaDTO(d), nil
}

func (s *orderService) POSXemNhap(ctx context.Context, id uint) (*dto.POSNhapResponse, error) {
	d, err := s.orderRepo.NhapQuay(ctx, id)
	if err != nil {
		return nil, err
	}

	return nhapRaDTO(d), nil
}

func (s *orderService) POSDanhSachNhap(ctx context.Context, f domain.LocNhapQuay) ([]dto.POSNhapResponse, error) {
	ds, err := s.orderRepo.DanhSachNhapQuay(ctx, f)
	if err != nil {
		return nil, err
	}
	out := make([]dto.POSNhapResponse, 0, len(ds))
	for i := range ds {
		out = append(out, *nhapRaDTO(&ds[i]))
	}

	return out, nil
}

// chotTuNhap bọc posCheckout cho lượt chốt mang draft_id: giành hoá đơn trước (một
// máy một lượt), chốt bằng đúng mã của nháp, thành công thì đánh dấu đã thành đơn,
// hỏng thì mở lại hoá đơn để thu ngân sửa tiếp.
func (s *orderService) chotTuNhap(ctx context.Context, req *dto.POSCheckoutRequest, role string, actorID uint) (*dto.POSCheckoutResponse, error) {
	nhap, err := s.orderRepo.GianhNhapQuay(ctx, req.DraftID)
	if err != nil {
		return nil, err
	}

	res, err := s.posCheckout(ctx, req, role, actorID, nhap.OrderCode)
	if err != nil {
		if traErr := s.orderRepo.TraNhapQuay(ctx, nhap.ID); traErr != nil {
			logger.Error("không mở lại được hoá đơn nháp sau lượt chốt hỏng",
				zap.Uint("draft_id", nhap.ID), zap.Error(traErr))
		}

		return nil, err
	}

	// Đơn đã vào sổ: ghi nhãn "đã thành đơn" hỏng thì chỉ ghi log — không được báo lỗi
	// cho một lượt bán đã thu tiền. Dòng nháp kẹt ở dang_chot quá hạn sẽ mở lại, nên
	// lượt chốt lần hai sẽ gặp mã đã có đơn và tự cấp mã khác (orderRepository.Checkout).
	if chotErr := s.orderRepo.ChotNhapQuay(ctx, nhap.ID, res.OrderID); chotErr != nil {
		logger.Error("không đánh dấu được hoá đơn nháp đã thành đơn",
			zap.Uint("draft_id", nhap.ID), zap.Uint("order_id", res.OrderID), zap.Error(chotErr))
	}

	return res, nil
}

// ganNhap chép phần màn quầy gửi lên vào dòng nháp, sau khi soát.
func ganNhap(d *domain.DonNhapQuay, req *dto.POSNhapRequest, actorID uint) error {
	items := req.Items
	if items == nil {
		items = []dto.POSNhapItem{}
	}
	for i := range items {
		items[i].ProductName = strings.TrimSpace(items[i].ProductName)
	}
	b, err := json.Marshal(items)
	if err != nil {
		return err
	}

	duLieu := strings.TrimSpace(string(req.DuLieu))
	if duLieu == "" || duLieu == "null" {
		duLieu = "{}"
	}
	if len(duLieu) > duLieuToiDa {
		return &LoiTheoO{Fields: map[string]string{"du_lieu": fmt.Sprintf("Dữ liệu hoá đơn quá lớn (tối đa %d KB)", duLieuToiDa/1024)}}
	}
	var thu map[string]any
	if json.Unmarshal([]byte(duLieu), &thu) != nil {
		return &LoiTheoO{Fields: map[string]string{"du_lieu": "Dữ liệu hoá đơn phải là một đối tượng JSON"}}
	}

	soMon := 0
	for _, it := range items {
		soMon += it.Quantity
	}

	d.Items = string(b)
	d.DuLieu = duLieu
	d.ItemCount = soMon
	d.TamTinh = req.TamTinh
	d.CustomerName = strings.TrimSpace(req.CustomerName)
	d.CustomerPhone = strings.TrimSpace(req.CustomerPhone)
	d.Note = strings.TrimSpace(req.Note)
	d.UserID = nil
	if req.UserID > 0 {
		uid := req.UserID
		d.UserID = &uid
	}
	if actorID > 0 {
		uid := actorID
		d.UpdatedBy = &uid
	}

	return nil
}

func nhapRaDTO(d *domain.DonNhapQuay) *dto.POSNhapResponse {
	items := []dto.POSNhapItem{}
	if d.Items != "" {
		_ = json.Unmarshal([]byte(d.Items), &items)
	}
	duLieu := json.RawMessage("{}")
	if d.DuLieu != "" && json.Valid([]byte(d.DuLieu)) {
		duLieu = json.RawMessage(d.DuLieu)
	}

	return &dto.POSNhapResponse{
		ID: d.ID, OrderCode: d.OrderCode, Status: d.Status,
		CreatedBy: d.CreatedBy, CreatedByName: d.CreatedByName,
		UserID: d.UserID, CustomerName: d.CustomerName, CustomerPhone: d.CustomerPhone,
		Note: d.Note, ItemCount: d.ItemCount, TamTinh: d.TamTinh,
		Items: items, DuLieu: duLieu,
		OrderID: d.OrderID, CancelReason: d.CancelReason, CancelledAt: d.CancelledAt, ConvertedAt: d.ConvertedAt,
		CreatedAt: d.CreatedAt, UpdatedAt: d.UpdatedAt,
	}
}
