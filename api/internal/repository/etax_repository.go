package repository

import (
	"context"
	"errors"

	"gorm.io/gorm"

	"sass-api/internal/domain"
)

type etaxRepository struct{ db *gorm.DB }

// NewEtaxRepository dựng sổ kết nối HĐĐT trên DATA PLANE (kết nối CÓ bộ lọc
// tenant) — mật khẩu cổng hoá đơn của cửa hàng này không được để lọt sang
// cửa hàng khác.
func NewEtaxRepository(db *gorm.DB) domain.EtaxRepository {
	return &etaxRepository{db: db}
}

func (r *etaxRepository) TheoChiNhanh(ctx context.Context, shopID uint) (*domain.EtaxConnection, error) {
	var cn domain.EtaxConnection
	err := r.db.WithContext(ctx).Where("shop_id = ?", shopID).Take(&cn).Error
	if errors.Is(err, gorm.ErrRecordNotFound) {
		return nil, domain.ErrNotFound
	}
	if err != nil {
		return nil, err
	}

	// Mẫu sắp theo ký hiệu để ô chọn không nhảy thứ tự giữa hai lần mở.
	if err := r.db.WithContext(ctx).Model(&domain.EtaxTemplate{}).
		Where("connection_id = ?", cn.ID).Order("symbol ASC").
		Find(&cn.Templates).Error; err != nil {
		return nil, err
	}

	return &cn, nil
}

func (r *etaxRepository) MaSoThueDaDung(ctx context.Context, taxCode string, trChiNhanh uint) (bool, error) {
	var so int64
	q := r.db.WithContext(ctx).Model(&domain.EtaxConnection{}).Where("tax_code = ?", taxCode)
	if trChiNhanh > 0 {
		q = q.Where("shop_id <> ?", trChiNhanh)
	}
	err := q.Count(&so).Error

	return so > 0, err
}

// Luu tạo mới hoặc ghi đè. Save() ghi cả bản ghi nên nơi gọi phải dựng đủ
// trường — xem EtaxService.
func (r *etaxRepository) Luu(ctx context.Context, cn *domain.EtaxConnection) error {
	return r.db.WithContext(ctx).Save(cn).Error
}

// Xoa xoá CỨNG: ngắt kết nối là bỏ hẳn tài khoản khỏi sổ, giữ lại một dòng đã
// tắt chỉ để đó một mật khẩu không ai dùng nữa. Mẫu hoá đơn đi theo bằng khoá
// ngoại ON DELETE CASCADE.
func (r *etaxRepository) Xoa(ctx context.Context, id uint) error {
	return r.db.WithContext(ctx).Delete(&domain.EtaxConnection{}, id).Error
}

// LuuMau thay TOÀN BỘ danh sách mẫu của một kết nối.
//
// Xoá rồi ghi lại thay vì upsert từng dòng: danh sách bên nhà cung cấp có thể
// BỚT đi (ký hiệu hết hạn, đăng ký nhầm rồi huỷ), mà upsert thì không bao giờ
// dọn được những dòng đã biến mất — và người dùng vẫn chọn được một ký hiệu
// không còn tồn tại.
func (r *etaxRepository) LuuMau(ctx context.Context, connectionID uint, ds []domain.EtaxTemplate) error {
	return r.db.WithContext(ctx).Transaction(func(tx *gorm.DB) error {
		if err := tx.Where("connection_id = ?", connectionID).
			Delete(&domain.EtaxTemplate{}).Error; err != nil {
			return err
		}
		if len(ds) == 0 {
			return nil
		}

		return tx.Create(&ds).Error
	})
}

func (r *etaxRepository) HoaDonTheoDon(ctx context.Context, orderID uint) (*domain.EtaxInvoice, error) {
	var hd domain.EtaxInvoice
	err := r.db.WithContext(ctx).Where("order_id = ?", orderID).Take(&hd).Error
	if errors.Is(err, gorm.ErrRecordNotFound) {
		return nil, domain.ErrNotFound
	}
	if err != nil {
		return nil, err
	}

	return &hd, nil
}

func (r *etaxRepository) LuuHoaDon(ctx context.Context, hd *domain.EtaxInvoice) error {
	return r.db.WithContext(ctx).Save(hd).Error
}

// ThueSuatTheoMatHang tra % thuế của một loạt mặt hàng.
//
// Đọc `products.vat` chứ không phải một cột trên dòng đơn hàng: order_items
// KHÔNG chụp lại thuế suất lúc bán. Nghĩa là hoá đơn phát hành cho một đơn CŨ
// sẽ mang thuế suất HÔM NAY của mặt hàng — chấp nhận được vì hoá đơn gần như
// luôn xuất ngay sau khi bán, nhưng đây là chỗ phải sửa nếu về sau cần phát
// hành bù cho đơn của tháng trước.
func (r *etaxRepository) ThueSuatTheoMatHang(ctx context.Context, ids []uint) (map[uint]int, error) {
	ra := make(map[uint]int, len(ids))
	if len(ids) == 0 {
		return ra, nil
	}

	var rows []struct {
		ID  uint
		VAT int
	}
	if err := r.db.WithContext(ctx).Model(&domain.Product{}).
		Select("id", "vat").Where("id IN ?", ids).Find(&rows).Error; err != nil {
		return nil, err
	}
	for _, row := range rows {
		ra[row.ID] = row.VAT
	}

	return ra, nil
}
