package repository

import (
	"context"
	"errors"
	"fmt"
	"time"

	"sass-api/internal/domain"

	"gorm.io/gorm"
	"gorm.io/gorm/clause"
)

// DoiHang — đổi hàng tại quầy, trọn vẹn trong MỘT giao dịch.
//
// VÌ SAO PHẢI LÀ MỘT GIAO DỊCH, không phải "lập phiếu trả rồi bán đơn mới":
// hai việc này là hai vế của cùng một lượt trao đổi. Chạy rời nhau thì một lần
// sập giữa chừng để lại hoặc là hàng cũ đã về kho mà khách chưa nhận gì, hoặc là
// đơn mới đã trừ kho mà hàng cũ chưa được ghi nhận. Cả hai đều là lệch kho câm —
// không có thông báo lỗi nào, chỉ có con số sai từ hôm đó trở đi.
//
// Đi qua ĐÚNG các hàm mà hai luồng gốc đang dùng, không viết lại cái nào:
//
//	heldQuantities / returnableFrom      số còn trả được của đơn cũ
//	restockReturn                        hàng cũ về kho + trừ lượt bán
//	settleOrderIfFullyReturned           khép đơn cũ nếu đã trả hết
//	loadCheckoutVariants / syncOrderStock  giá và kho của đơn mới
//	ghiSoQuy                             chênh lệch tiền mặt vào sổ quỹ
//
// Nhờ vậy đổi hàng không thể lệch với trả hàng hay với bán hàng: chúng là cùng
// một đoạn code.
//
// build nhận đơn cũ ĐÃ KHOÁ, số còn trả được của từng dòng, và bảng giá của hàng
// mới đã khoá biến thể. Nó trả về phiếu trả, đơn mới, và dòng sổ quỹ (nil = lượt
// đổi này không đụng tới tiền mặt).
func (r *orderRepository) DoiHang(
	ctx context.Context,
	orderCu uint,
	lines []domain.CheckoutLine,
	build func(cu *domain.Order, conTraDuoc map[uint]domain.ReturnableItem, giaMoi map[uint]domain.CheckoutVariant) (*domain.OrderReturn, *domain.Order, *domain.SoQuy, error),
) (*domain.OrderReturn, *domain.Order, error) {
	var (
		phieu *domain.OrderReturn
		moi   *domain.Order
	)

	// Chi nhánh của ĐƠN MỚI, chốt trước khi mở giao dịch — cùng cách với Checkout.
	shopID, err := chiNhanhCuaRequest(ctx, r.db)
	if err != nil {
		return nil, nil, err
	}

	err = r.db.WithContext(ctx).Transaction(func(tx *gorm.DB) error {
		// 1. Khoá đơn cũ và tính lại số còn trả được NGAY DƯỚI KHOÁ.
		//
		// Tính trước rồi mới khoá là để hai người cùng đổi một chiếc áo đều thấy
		// "còn trả được 1" và cùng đi qua.
		var cu domain.Order
		err := tx.Clauses(clause.Locking{Strength: "UPDATE"}).
			Preload("Items", func(q *gorm.DB) *gorm.DB { return q.Order("id ASC") }).
			First(&cu, orderCu).Error
		if errors.Is(err, gorm.ErrRecordNotFound) {
			return domain.ErrNotFound
		}
		if err != nil {
			return err
		}

		held, err := heldQuantities(tx, orderCu, returnHoldsStatuses, 0)
		if err != nil {
			return err
		}
		conTraDuoc := make(map[uint]domain.ReturnableItem, len(cu.Items))
		for _, it := range returnableFrom(cu.Items, held) {
			conTraDuoc[it.OrderItemID] = it
		}

		// 2. Giá và tồn của hàng MỚI, có khoá vì các dòng này sắp bị trừ kho.
		giaMoi, err := loadCheckoutVariants(tx, lines, true)
		if err != nil {
			return err
		}

		// 3. Tầng service dựng phiếu trả + đơn mới + dòng quỹ.
		rt, don, quy, err := build(&cu, conTraDuoc, giaMoi)
		if err != nil {
			return err
		}

		// 4. Phiếu trả. Thuộc về chi nhánh của ĐƠN CŨ: hàng quay về đúng kho đã
		// xuất nó ra, không phải kho của quầy đang đứng.
		rt.OrderID = cu.ID
		rt.ShopID = cu.ShopID
		items := rt.Items
		rt.Items = nil
		rt.ReturnCode = fmt.Sprintf("TMP%d", time.Now().UnixNano())
		if err := tx.Create(rt).Error; err != nil {
			return err
		}
		maTra, err := maChungTu(ctx, tx, domain.LoaiTraHangKhach, rt.ShopID, &domain.OrderReturn{}, "return_code",
			fmt.Sprintf("RT%s%04d", time.Now().Format("20060102"), rt.ID))
		if err != nil {
			return err
		}
		rt.ReturnCode = maTra
		if err := tx.Model(rt).Update("return_code", rt.ReturnCode).Error; err != nil {
			return err
		}
		for i := range items {
			items[i].ReturnID = rt.ID
		}
		if len(items) > 0 {
			if err := tx.Create(&items).Error; err != nil {
				return err
			}
		}
		rt.Items = items

		// 5. Hàng cũ về kho NGAY. Khác trả hàng thường (lập phiếu → duyệt → nhận
		// hàng): ở quầy thì món hàng đang nằm trên tay người bán, không có gì để
		// chờ duyệt. Đó cũng là lý do phiếu sinh ra thẳng ở trạng thái đã nhận.
		if err := restockReturn(tx, rt); err != nil {
			return err
		}

		// 6. Đơn mới — cùng đường với một lượt bán tại quầy bình thường.
		don.ShopID = shopID
		don.OrderCode = fmt.Sprintf("TMP%d", time.Now().UnixNano())
		if err := tx.Create(don).Error; err != nil {
			return err
		}
		maDon, err := maChungTu(ctx, tx, domain.LoaiDonHang, don.ShopID, &domain.Order{}, "order_code",
			fmt.Sprintf("DH%s%04d", time.Now().Format("20060102"), don.ID))
		if err != nil {
			return err
		}
		don.OrderCode = maDon
		if err := tx.Model(don).Update("order_code", don.OrderCode).Error; err != nil {
			return err
		}
		if err := syncOrderStock(tx, don, orderDesiredStock(don.Items), "export", "Đổi hàng"); err != nil {
			return err
		}
		if soldCounted(don.Status) {
			if err := syncSoldCount(tx, don, "", don.Status); err != nil {
				return err
			}
		}
		if err := tx.Create(&domain.OrderStatusHistory{
			OrderID:    don.ID,
			FromStatus: "",
			ToStatus:   don.Status,
			Note:       "Đổi hàng từ đơn " + cu.OrderCode,
		}).Error; err != nil {
			return err
		}

		// 7. Nối hai vế lại. Thiếu mối nối này thì trong sổ chúng chỉ là một phiếu
		// trả và một đơn bán tình cờ trùng giờ, và câu hỏi "khách đổi cái áo đó
		// lấy cái gì" không tra được nữa.
		rt.ExchangeOrderID = &don.ID
		if err := tx.Model(rt).Update("exchange_order_id", don.ID).Error; err != nil {
			return err
		}

		// 8. Đơn cũ khép lại nếu mọi món đã được trả hết — dùng đúng hàm của luồng
		// trả hàng, để hai đường không bao giờ khép đơn theo hai luật khác nhau.
		var nguoiXuLy uint
		if rt.HandledBy != nil {
			nguoiXuLy = *rt.HandledBy
		}
		if err := settleOrderIfFullyReturned(tx, rt, nguoiXuLy); err != nil {
			return err
		}

		// 9. Chênh lệch tiền mặt vào sổ quỹ, vẫn trong cùng giao dịch này.
		if quy != nil {
			quy.ShopID = shopID
			refID := don.ID
			quy.ReferenceID = &refID
			if err := ghiSoQuy(tx, quy); err != nil {
				return err
			}
		}

		phieu, moi = rt, don
		return nil
	})

	return phieu, moi, err
}
