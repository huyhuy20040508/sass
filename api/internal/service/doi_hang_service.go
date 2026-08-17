package service

import (
	"context"
	"fmt"
	"math"
	"strings"
	"time"

	"sass-api/internal/domain"
	"sass-api/internal/dto"
)

// POSDoiHang — đổi hàng tại quầy.
//
// Một lượt đổi là hai vế của cùng một trao đổi: hàng cũ về kho, hàng mới ra khỏi
// kho, và phần chênh lệch được thanh toán ngay. Cả ba đi trong MỘT giao dịch của
// repository, nên không có trạng thái nửa vời.
//
// CHÍNH SÁCH TIỀN nằm ở đây chứ không ở repository:
//
//   - Chênh lệch DƯƠNG (hàng mới đắt hơn): khách trả thêm. Thu tiền mặt thì ghi
//     một dòng THU vào sổ quỹ, và kiểm tiền khách đưa có đủ không.
//   - Chênh lệch ÂM (hàng mới rẻ hơn, hoặc khách không lấy gì): cửa hàng trả lại
//     tiền. Ghi một dòng CHI.
//   - Bằng 0: không đụng tới két, không ghi sổ quỹ dòng nào.
//
// GIÁ HÀNG TRẢ LẤY THEO ĐƠN CŨ, không tra lại bảng giá hôm nay: khách trả lại
// đúng món đã mua thì phải được ghi nhận đúng số tiền đã trả cho nó. Tra lại giá
// hiện tại là món vừa lên giá thì khách được lợi, vừa xuống giá thì khách chịu
// thiệt — cả hai đều là thứ không ai giải thích được ở quầy.
func (s *orderService) POSDoiHang(
	ctx context.Context, req *dto.DoiHangRequest, role string, actorID uint,
) (*dto.DoiHangResponse, error) {
	if len(req.Tra) == 0 {
		return nil, domain.ErrEmptyCart
	}

	// Hạn quyền giảm giá áp cho hàng MỚI y như một lượt bán thường: đổi hàng
	// không phải cửa sau để bấm mức giảm mà bán thẳng thì không được phép.
	limit := s.POSDiscountLimit(ctx, role)
	for _, it := range req.Moi {
		if it.DiscountPercent > limit {
			return nil, fmt.Errorf("%w: tối đa %g%% — nhờ quản lý duyệt mức cao hơn",
				domain.ErrDiscountTooHigh, limit)
		}
	}

	lines := make([]domain.CheckoutLine, 0, len(req.Moi))
	for _, it := range req.Moi {
		lines = append(lines, domain.CheckoutLine{
			VariantID:          it.ProductVariantID,
			Quantity:           it.Quantity,
			DiscountPercent:    it.DiscountPercent,
			CustomPlayerName:   it.CustomPlayerName,
			CustomPlayerNumber: it.CustomPlayerNumber,
		})
	}

	restock := true
	if req.Restock != nil {
		restock = *req.Restock
	}

	var (
		tienTra, tienMoi float64
		dua, thoi        *float64
	)

	phieu, don, err := s.orderRepo.DoiHang(ctx, req.OrderID, lines,
		func(cu *domain.Order, conTraDuoc map[uint]domain.ReturnableItem, giaMoi map[uint]domain.CheckoutVariant) (*domain.OrderReturn, *domain.Order, *domain.SoQuy, error) {
			// ----- Vế 1: hàng khách mang trả -----
			rt := &domain.OrderReturn{
				UserID: cu.UserID,
				// Sinh thẳng ở "đã nhận hàng": món hàng đang nằm trên tay người bán,
				// không có gì để chờ duyệt. Khác hẳn trả hàng qua website.
				Status:      domain.ReturnStatusReceived,
				Reason:      chuoiHoacMacDinh(req.Reason, "changed_mind"),
				ReasonNote:  strings.TrimSpace(req.ReasonNote),
				RequestedBy: "admin",
				// Không hoàn tiền qua đường phiếu trả: phần tiền của lượt đổi được
				// quyết bằng CHÊNH LỆCH bên dưới. Ghi hoàn tiền ở đây nữa là trả cho
				// khách hai lần trên sổ.
				RefundMethod: "none",
				Restock:      restock,
			}
			if actorID > 0 {
				rt.HandledBy = &actorID
			}

			for _, t := range req.Tra {
				con, ok := conTraDuoc[t.OrderItemID]
				if !ok {
					return nil, nil, nil, domain.ErrNotFound
				}
				if t.Quantity > con.RemainQuantity {
					return nil, nil, nil, fmt.Errorf("%w: %s (còn trả được %d)",
						domain.ErrReturnQtyExceeded, con.ProductName, con.RemainQuantity)
				}

				line := con.UnitPrice * float64(t.Quantity)
				tienTra += line
				rt.Items = append(rt.Items, domain.OrderReturnItem{
					OrderItemID:      con.OrderItemID,
					ProductID:        con.ProductID,
					ProductVariantID: con.ProductVariantID,
					ProductName:      con.ProductName,
					VariantSKU:       con.VariantSKU,
					Size:             con.Size,
					Color:            con.Color,
					Thumbnail:        con.Thumbnail,
					UnitPrice:        con.UnitPrice,
					Quantity:         t.Quantity,
					TotalPrice:       line,
				})
			}
			rt.ItemsAmount = tienTra
			// RefundAmount = 0: tiền của lượt đổi đi qua chênh lệch, không qua phiếu.
			rt.RefundAmount = 0

			// ----- Vế 2: hàng khách lấy về -----
			s.applyPromotions(ctx, giaMoi)

			items, subtotal, err := buildOrderItems(giaMoi, lines)
			if err != nil {
				return nil, nil, nil, err
			}
			tienMoi = subtotal

			now := time.Now()
			don := &domain.Order{
				Channel:        domain.OrderChannelPOS,
				UserID:         cu.UserID,
				RecipientName:  cu.RecipientName,
				RecipientPhone: cu.RecipientPhone,
				PaymentMethod:  chuoiHoacMacDinh(req.PaymentMethod, domain.PaymentMethodCash),
				PaymentStatus:  domain.OrderPaymentPaid,
				Status:         domain.OrderStatusCompleted,
				Note:           strings.TrimSpace(req.Note),
				PlacedAt:       &now,
				ConfirmedAt:    &now,
				DeliveredAt:    &now,
				Items:          items,
				SubtotalAmount: subtotal,
				ShippingFee:    0,
				TotalAmount:    subtotal,
			}

			// ----- Chênh lệch -----
			chenh := math.Round(tienMoi - tienTra)

			// Tiền mặt khách đưa chỉ có nghĩa khi khách PHẢI TRẢ THÊM.
			if chenh > 0 && don.PaymentMethod == domain.PaymentMethodCash && req.AmountTendered != nil {
				t := *req.AmountTendered
				if t < chenh {
					return nil, nil, nil, fmt.Errorf("%w: còn thiếu %s",
						domain.ErrTenderTooLow, formatVND(chenh-t))
				}
				c := t - chenh
				dua, thoi = &t, &c
				don.AmountTendered, don.ChangeAmount = &t, &c
			}

			// Sổ quỹ chỉ ghi khi tiền mặt thật sự vào hoặc ra khỏi két.
			var quy *domain.SoQuy
			if don.PaymentMethod == domain.PaymentMethodCash && chenh != 0 {
				quy = &domain.SoQuy{
					Direction:     domain.SoQuyThu,
					Amount:        chenh,
					Reason:        "Đổi hàng — khách trả thêm",
					ReferenceType: domain.SoQuyTuDonHang,
					CreatedBy:     conTroNguoi(actorID),
				}
				if chenh < 0 {
					quy.Direction = domain.SoQuyChi
					quy.Amount = -chenh
					quy.Reason = "Đổi hàng — trả lại khách"
				}
			}

			return rt, don, quy, nil
		})
	if err != nil {
		return nil, err
	}

	// Đơn mới vừa lấy hàng khỏi kho — cảnh báo sắp hết như mọi lượt bán khác.
	s.signalOrder(ctx, don)
	s.notifyLowStock(ctx, don)

	chenh := math.Round(tienMoi - tienTra)
	msg := "Đã đổi hàng xong."
	switch {
	case chenh > 0 && thoi != nil:
		msg = "Khách trả thêm " + formatVND(chenh) + ", thối lại " + formatVND(*thoi) + "."
	case chenh > 0:
		msg = "Khách trả thêm " + formatVND(chenh) + "."
	case chenh < 0:
		msg = "Trả lại khách " + formatVND(-chenh) + "."
	}

	return &dto.DoiHangResponse{
		ReturnID:       phieu.ID,
		ReturnCode:     phieu.ReturnCode,
		OrderID:        don.ID,
		OrderCode:      don.OrderCode,
		TienTra:        tienTra,
		TienMoi:        tienMoi,
		ChenhLech:      chenh,
		AmountTendered: dua,
		ChangeAmount:   thoi,
		Message:        msg,
	}, nil
}

// chuoiHoacMacDinh trả về chuỗi đã bỏ khoảng trắng, rơi về mặc định nếu rỗng.
func chuoiHoacMacDinh(s, macDinh string) string {
	if v := strings.TrimSpace(s); v != "" {
		return v
	}
	return macDinh
}

// conTroNguoi trả con trỏ tới id người thao tác, nil nếu không xác định được.
func conTroNguoi(id uint) *uint {
	if id == 0 {
		return nil
	}
	return &id
}
