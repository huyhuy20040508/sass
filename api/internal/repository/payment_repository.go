package repository

import (
	"context"
	"errors"
	"strings"
	"time"

	"gorm.io/gorm"

	"sass-api/internal/domain"
)

type paymentRepository struct{ db *gorm.DB }

func NewPaymentRepository(db *gorm.DB) domain.PaymentRepository {
	return &paymentRepository{db: db}
}

func (r *paymentRepository) Create(ctx context.Context, p *domain.Payment) error {
	return r.db.WithContext(ctx).Create(p).Error
}

func (r *paymentRepository) FindByTransactionCode(ctx context.Context, code string) (*domain.Payment, error) {
	var p domain.Payment
	err := r.db.WithContext(ctx).Where("transaction_code = ?", code).First(&p).Error
	if errors.Is(err, gorm.ErrRecordNotFound) {
		return nil, domain.ErrNotFound
	}
	if err != nil {
		return nil, err
	}
	return &p, nil
}

// FindOpenByOrder trả lần thử còn hiệu lực gần nhất của đơn. Không có thì trả
// domain.ErrNotFound — nơi gọi hiểu là "cần tạo link mới".
func (r *paymentRepository) FindOpenByOrder(ctx context.Context, orderID uint, now time.Time) (*domain.Payment, error) {
	var p domain.Payment
	err := r.db.WithContext(ctx).
		Where("order_id = ? AND status = ?", orderID, domain.PaymentStatusPending).
		// expired_at NULL = link không đặt hạn, vẫn dùng được.
		Where("expired_at IS NULL OR expired_at > ?", now).
		Order("id DESC").
		First(&p).Error
	if errors.Is(err, gorm.ErrRecordNotFound) {
		return nil, domain.ErrNotFound
	}
	if err != nil {
		return nil, err
	}
	return &p, nil
}

// FindPendingByContent dò xem nội dung chuyển khoản có chứa mã giao dịch nào đang
// chờ không.
//
// Điều kiện LIKE bị lật ngược so với thường lệ: chuỗi cần dò nằm ở vế TRÁI, còn
// khuôn mẫu ghép từ chính cột trong bảng. Nghĩa là "nội dung này có chứa mã của
// dòng nào không", chứ không phải "cột này có chứa nội dung không".
//
// Lấy dòng mới nhất khi có nhiều dòng khớp: mã đơn dài và có tiền tố riêng nên
// chuyện trùng gần như không xảy ra, nhưng nếu có thì lần thử gần đây mới là lần
// khách đang trả tiền.
func (r *paymentRepository) FindPendingByContent(ctx context.Context, provider, content string) (*domain.Payment, error) {
	if strings.TrimSpace(content) == "" {
		return nil, domain.ErrNotFound
	}

	var p domain.Payment
	err := r.db.WithContext(ctx).
		Where("provider = ? AND status = ?", provider, domain.PaymentStatusPending).
		Where("transaction_code <> '' AND ? LIKE CONCAT('%', transaction_code, '%')", content).
		Order("id DESC").
		First(&p).Error
	if errors.Is(err, gorm.ErrRecordNotFound) {
		return nil, domain.ErrNotFound
	}
	if err != nil {
		return nil, err
	}
	return &p, nil
}

// MarkSettled chốt kết quả một lần thử.
//
// Điều kiện `status = 'pending'` nằm ngay trong câu UPDATE chứ không đọc-rồi-ghi:
// PayOS gửi webhook lặp là chuyện bình thường, và hai webhook về cùng lúc mà mỗi
// bên tự đọc trạng thái cũ thì cả hai đều thấy "đang chờ" rồi cùng báo đơn đã
// thanh toán. Để database quyết định ai là người đầu tiên.
func (r *paymentRepository) MarkSettled(ctx context.Context, id uint, status, gatewayResponse string, paidAt *time.Time) (bool, error) {
	updates := map[string]any{
		"status":     status,
		"updated_at": time.Now(),
	}
	if gatewayResponse != "" {
		updates["gateway_response"] = gatewayResponse
	}
	if paidAt != nil {
		updates["paid_at"] = *paidAt
	}

	res := r.db.WithContext(ctx).
		Model(&domain.Payment{}).
		Where("id = ? AND status = ?", id, domain.PaymentStatusPending).
		Updates(updates)
	if res.Error != nil {
		return false, res.Error
	}
	return res.RowsAffected > 0, nil
}
