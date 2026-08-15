package repository

import (
	"context"
	"errors"
	"time"

	"gorm.io/gorm"

	"sass-api/internal/domain"
)

// donGiaHanRepository giữ sổ ĐƠN GIA HẠN do khách tự đặt.
//
// Kết nối truyền vào PHẢI là repository.NewPlatformDB — cùng ràng buộc với
// planRepository. Bảng `renewal_orders` không có bên data plane nên đưa nhầm
// kết nối vào là hỏng ngay, không có nhánh nào âm thầm ghi sai chỗ.
type donGiaHanRepository struct{ db *gorm.DB }

// NewDonGiaHanRepository nhận kết nối CONTROL PLANE (NewPlatformDB).
func NewDonGiaHanRepository(platformDB *gorm.DB) domain.DonGiaHanRepository {
	return &donGiaHanRepository{db: platformDB}
}

// Tao ghi đơn mới, rồi ghi lại `ma_don` bằng chính id vừa cấp.
//
// HAI BƯỚC vì mã đơn là id tự tăng: cổng thanh toán đòi một orderCode chưa từng
// dùng, và id của bảng này là thứ duy nhất bảo đảm được điều đó mà không phải
// sinh số ngẫu nhiên rồi dò trùng. Ghi tạm 0 rồi cập nhật — cột UNIQUE cho phép
// đúng một dòng mang 0 tại một thời điểm, nên hai lượt tạo đồng thời có thể đụng
// nhau; giao dịch bao cả hai bước để lượt sau chờ lượt trước xong.
func (r *donGiaHanRepository) Tao(ctx context.Context, don *domain.DonGiaHan) error {
	return r.db.WithContext(ctx).Transaction(func(tx *gorm.DB) error {
		if err := tx.Create(don).Error; err != nil {
			return err
		}

		don.MaDon = don.ID

		return tx.Model(&domain.DonGiaHan{}).
			Where("id = ?", don.ID).
			Update("ma_don", don.MaDon).Error
	})
}

func (r *donGiaHanRepository) GanLink(ctx context.Context, id uint, tt domain.ThongTinTraTien) error {
	return r.db.WithContext(ctx).
		Model(&domain.DonGiaHan{}).
		Where("id = ?", id).
		Updates(map[string]any{
			"link_id":       domain.StringOrNull(tt.LinkID),
			"checkout_url":  domain.StringOrNull(tt.CheckoutURL),
			"qr_code":       domain.StringOrNull(tt.QRCode),
			"ngan_hang_bin": domain.StringOrNull(tt.NganHangBIN),
			"so_tai_khoan":  domain.StringOrNull(tt.SoTaiKhoan),
			"chu_tai_khoan": domain.StringOrNull(tt.ChuTaiKhoan),
			"noi_dung":      domain.StringOrNull(tt.NoiDung),
			"het_han_luc":   tt.HetHan,
			"updated_at":    time.Now(),
		}).Error
}

func (r *donGiaHanRepository) Tim(ctx context.Context, tenantID, id uint) (*domain.DonGiaHan, error) {
	var don domain.DonGiaHan
	err := r.db.WithContext(ctx).
		Where("id = ? AND tenant_id = ?", id, tenantID).
		Take(&don).Error
	if errors.Is(err, gorm.ErrRecordNotFound) {
		return nil, domain.ErrNotFound
	}
	if err != nil {
		return nil, err
	}

	return &don, nil
}

func (r *donGiaHanRepository) TimTheoMaDon(ctx context.Context, maDon uint) (*domain.DonGiaHan, error) {
	var don domain.DonGiaHan
	err := r.db.WithContext(ctx).Where("ma_don = ?", maDon).Take(&don).Error
	if errors.Is(err, gorm.ErrRecordNotFound) {
		return nil, domain.ErrNotFound
	}
	if err != nil {
		return nil, err
	}

	return &don, nil
}

// DangCho trả về đơn chưa trả gần nhất của một hợp đồng.
//
// Lọc thêm `het_han_luc`: link đã quá hạn thì cổng không nhận tiền nữa, nên trả
// nó về cho khách là đưa họ tới một trang trả tiền đã chết.
func (r *donGiaHanRepository) DangCho(ctx context.Context, subscriptionID uint) (*domain.DonGiaHan, error) {
	var don domain.DonGiaHan
	err := r.db.WithContext(ctx).
		Where("subscription_id = ? AND trang_thai = ?", subscriptionID, domain.DonChoThanhToan).
		Where("het_han_luc IS NULL OR het_han_luc > ?", time.Now()).
		Order("id DESC").
		Take(&don).Error
	if errors.Is(err, gorm.ErrRecordNotFound) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}

	return &don, nil
}

// DanhDauDaTra chốt đơn, CHỈ khi nó còn đang chờ.
//
// Điều kiện trạng thái nằm ngay trong câu UPDATE chứ không chỉ ở lượt đọc trước
// đó: webhook của cổng tới nhiều lần cho cùng một giao dịch là chuyện bình
// thường, và hai lượt xử lý chạy song song sẽ cùng đọc thấy "đang chờ". Số dòng
// trả về là thứ duy nhất phân biệt lượt chốt thật với lượt lặp lại — nhờ nó mà
// một lần trả tiền không đẩy hạn hợp đồng hai lần.
func (r *donGiaHanRepository) DanhDauDaTra(ctx context.Context, id, invoiceID uint) (int64, error) {
	now := time.Now()
	dat := map[string]any{
		"trang_thai": domain.DonDaThanhToan,
		"paid_at":    now,
		"updated_at": now,
	}
	if invoiceID > 0 {
		dat["invoice_id"] = invoiceID
	}

	res := r.db.WithContext(ctx).
		Model(&domain.DonGiaHan{}).
		Where("id = ? AND trang_thai = ?", id, domain.DonChoThanhToan).
		Updates(dat)

	return res.RowsAffected, res.Error
}

func (r *donGiaHanRepository) GanHoaDon(ctx context.Context, id, invoiceID uint) error {
	return r.db.WithContext(ctx).
		Model(&domain.DonGiaHan{}).
		Where("id = ?", id).
		Updates(map[string]any{"invoice_id": invoiceID, "updated_at": time.Now()}).Error
}

func (r *donGiaHanRepository) DoiTrangThai(ctx context.Context, id uint, trangThai string) error {
	return r.db.WithContext(ctx).
		Model(&domain.DonGiaHan{}).
		Where("id = ? AND trang_thai = ?", id, domain.DonChoThanhToan).
		Updates(map[string]any{"trang_thai": trangThai, "updated_at": time.Now()}).Error
}
