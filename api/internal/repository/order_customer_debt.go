package repository

import (
	"context"

	"sass-api/internal/domain"
)

// DonConNoCuaKhach — các đơn còn nợ của một khách, CŨ TRƯỚC: nút "Thu nợ" ở đầu
// khung chi tiết thu cho đơn cũ nhất trước, như thói quen gạch sổ nợ ở quầy.
//
// Cùng tập đơn (bỏ huỷ / trả hết) và cùng daThuCuaDon với AggregateCustomerOrders,
// để tổng các dòng khớp con số "Còn nợ" của danh sách khách.
func (r *orderRepository) DonConNoCuaKhach(ctx context.Context, userID uint) ([]domain.DonConNo, error) {
	rows := []domain.DonConNo{}
	err := r.db.WithContext(ctx).Table("orders").
		Select(`orders.id, orders.order_code, orders.shop_id, COALESCE(s.name, '') AS ten_chi_nhanh,
			orders.total_amount AS tong_tien,
			(`+daThuCuaDon("orders")+`) AS da_thu,
			orders.total_amount - (`+daThuCuaDon("orders")+`) AS con_no,
			orders.created_at`).
		Joins("LEFT JOIN shops s ON s.id = orders.shop_id AND s.tenant_id = orders.tenant_id").
		Where("orders.user_id = ?", userID).
		Where("orders.deleted_at IS NULL").
		Where("orders.status NOT IN ?", []string{domain.OrderStatusCancelled, domain.OrderStatusReturned}).
		Where("orders.total_amount - (" + daThuCuaDon("orders") + ") > 0").
		Order("orders.created_at ASC, orders.id ASC").
		Scan(&rows).Error
	if err != nil {
		return nil, err
	}

	cn := chiNhanhDoc(ctx, r.db)
	for i := range rows {
		rows[i].ThuDuoc = cn == 0 || rows[i].ShopID == cn
	}

	return rows, nil
}
