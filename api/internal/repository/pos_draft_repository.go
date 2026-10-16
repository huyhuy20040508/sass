package repository

import (
	"context"
	"errors"
	"strings"
	"time"

	"gorm.io/gorm"
	"gorm.io/gorm/clause"

	"sass-api/internal/domain"
)

// Đơn nháp ở quầy (pos_drafts) — xem domain.DonNhapQuay.
//
// Mọi lượt đọc / ghi đều cắt theo CHI NHÁNH đang làm: hoá đơn mở ở kho 1 không
// được sửa từ máy đang đứng ở kho 2, dù cùng cửa hàng.

// TaoNhapQuay — xem domain.OrderRepository.
//
// Cấp mã đơn trong CÙNG giao dịch với lượt tạo, từ đúng bộ đếm của lượt chốt đơn
// (như GiuMaDon): số đã cấp là của hoá đơn này, huỷ thì số đó bỏ trống như v2.
func (r *orderRepository) TaoNhapQuay(ctx context.Context, d *domain.DonNhapQuay) error {
	shopID, err := chiNhanhCuaRequest(ctx, r.db)
	if err != nil {
		return err
	}
	d.ShopID = shopID
	d.Status = domain.NhapQuayDangMo

	err = r.db.WithContext(ctx).Transaction(func(tx *gorm.DB) error {
		ma, err := SinhMaTrongTx(ctx, tx, domain.LoaiDonHang, shopID, daCoMa(ctx, tx, &domain.Order{}, "order_code"))
		if err != nil {
			return err
		}
		d.OrderCode = ma

		return tx.Create(d).Error
	})
	if err != nil {
		return err
	}
	ds := []domain.DonNhapQuay{*d}
	r.ganTenNguoiMo(ctx, ds)
	d.CreatedByName = ds[0].CreatedByName

	return nil
}

// NhapQuay — một đơn nháp của chi nhánh đang làm, kèm tên người mở.
func (r *orderRepository) NhapQuay(ctx context.Context, id uint) (*domain.DonNhapQuay, error) {
	var d domain.DonNhapQuay
	q := r.db.WithContext(ctx).Where("id = ?", id)
	if shopID := chiNhanhDoc(ctx, r.db); shopID > 0 {
		q = q.Where("shop_id = ?", shopID)
	}
	if err := q.First(&d).Error; err != nil {
		if errors.Is(err, gorm.ErrRecordNotFound) {
			return nil, domain.ErrNotFound
		}

		return nil, err
	}
	ds := []domain.DonNhapQuay{d}
	r.ganTenNguoiMo(ctx, ds)

	return &ds[0], nil
}

// SuaNhapQuay khoá dòng, kiểm còn mở, cho `apply` sửa rồi ghi lại.
//
// Hoá đơn đã chốt / huỷ / đang được chốt ở máy khác thì từ chối: một lượt lưu
// tới muộn từ máy thứ hai không được hồi sinh hoá đơn đã thành đơn.
func (r *orderRepository) SuaNhapQuay(ctx context.Context, id uint, apply func(d *domain.DonNhapQuay) error) (*domain.DonNhapQuay, error) {
	var out domain.DonNhapQuay
	err := r.db.WithContext(ctx).Transaction(func(tx *gorm.DB) error {
		q := tx.Clauses(clause.Locking{Strength: "UPDATE"}).Where("id = ?", id)
		if shopID := chiNhanhDoc(ctx, tx); shopID > 0 {
			q = q.Where("shop_id = ?", shopID)
		}
		if err := q.First(&out).Error; err != nil {
			if errors.Is(err, gorm.ErrRecordNotFound) {
				return domain.ErrNotFound
			}

			return err
		}
		switch out.Status {
		case domain.NhapQuayDangMo:
		case domain.NhapQuayDangChot:
			if out.ClaimedAt != nil && time.Since(*out.ClaimedAt) < domain.HanGianhChot {
				return domain.ErrNhapDangChot
			}
			// Máy giành chốt đã bỏ dở quá hạn — hoá đơn mở lại.
			out.Status = domain.NhapQuayDangMo
			out.ClaimedAt = nil
		default:
			return domain.ErrNhapKhongMo
		}
		if err := apply(&out); err != nil {
			return err
		}

		return tx.Save(&out).Error
	})
	if err != nil {
		return nil, err
	}
	ds := []domain.DonNhapQuay{out}
	r.ganTenNguoiMo(ctx, ds)

	return &ds[0], nil
}

// DanhSachNhapQuay — đơn nháp của chi nhánh đang làm, mới đụng tới lên đầu.
func (r *orderRepository) DanhSachNhapQuay(ctx context.Context, f domain.LocNhapQuay) ([]domain.DonNhapQuay, error) {
	q := r.db.WithContext(ctx).Model(&domain.DonNhapQuay{})
	if shopID := chiNhanhDoc(ctx, r.db); shopID > 0 {
		q = q.Where("shop_id = ?", shopID)
	}
	if st := splitCSV(f.Status); len(st) > 0 {
		q = q.Where("status IN ?", st)
	}
	if f.CreatedBy > 0 {
		q = q.Where("created_by = ?", f.CreatedBy)
	}
	if f.FromDate != "" {
		q = q.Where("created_at >= ?", f.FromDate+" 00:00:00")
	}
	if f.ToDate != "" {
		q = q.Where("created_at <= ?", f.ToDate+" 23:59:59")
	}
	limit := f.Limit
	if limit <= 0 || limit > 500 {
		limit = 200
	}

	var ds []domain.DonNhapQuay
	if err := q.Order("updated_at DESC").Order("id DESC").Limit(limit).Find(&ds).Error; err != nil {
		return nil, err
	}
	r.ganTenNguoiMo(ctx, ds)

	return ds, nil
}

// GianhNhapQuay — một máy bắt đầu thanh toán: chuyển open → dang_chot bằng MỘT câu
// UPDATE có điều kiện. Hai máy bấm cùng lúc thì chỉ một câu đổi được dòng; máy kia
// nhận ErrNhapDangChot thay vì lập đơn thứ hai cho cùng giỏ hàng.
func (r *orderRepository) GianhNhapQuay(ctx context.Context, id uint) (*domain.DonNhapQuay, error) {
	shopID, err := chiNhanhCuaRequest(ctx, r.db)
	if err != nil {
		return nil, err
	}
	now := time.Now()
	res := r.db.WithContext(ctx).Model(&domain.DonNhapQuay{}).
		Where("id = ? AND shop_id = ?", id, shopID).
		Where("(status = ? OR (status = ? AND claimed_at < ?))",
			domain.NhapQuayDangMo, domain.NhapQuayDangChot, now.Add(-domain.HanGianhChot)).
		Updates(map[string]any{"status": domain.NhapQuayDangChot, "claimed_at": now})
	if res.Error != nil {
		return nil, res.Error
	}

	d, err := r.NhapQuay(ctx, id)
	if err != nil {
		return nil, err
	}
	if res.RowsAffected == 0 {
		if d.Status == domain.NhapQuayDangChot {
			return nil, domain.ErrNhapDangChot
		}

		return nil, domain.ErrNhapKhongMo
	}

	return d, nil
}

// TraNhapQuay — lượt chốt hỏng (hết hàng, sai tiền…): hoá đơn mở lại để sửa tiếp.
func (r *orderRepository) TraNhapQuay(ctx context.Context, id uint) error {
	return r.db.WithContext(ctx).Model(&domain.DonNhapQuay{}).
		Where("id = ? AND status = ?", id, domain.NhapQuayDangChot).
		Updates(map[string]any{"status": domain.NhapQuayDangMo, "claimed_at": nil}).Error
}

// ChotNhapQuay — hoá đơn đã thành đơn `orderID`.
func (r *orderRepository) ChotNhapQuay(ctx context.Context, id, orderID uint) error {
	now := time.Now()

	return r.db.WithContext(ctx).Model(&domain.DonNhapQuay{}).
		Where("id = ? AND status = ?", id, domain.NhapQuayDangChot).
		Updates(map[string]any{"status": domain.NhapQuayDaChot, "order_id": orderID, "converted_at": now}).Error
}

// ganTenNguoiMo điền tên người mở hoá đơn — một câu cho cả danh sách.
func (r *orderRepository) ganTenNguoiMo(ctx context.Context, ds []domain.DonNhapQuay) {
	if len(ds) == 0 {
		return
	}
	ids := make([]uint, 0, len(ds))
	for _, d := range ds {
		ids = append(ids, d.CreatedBy)
	}
	var nguoi []struct {
		ID       uint
		FullName string
		Username string
	}
	if err := r.db.WithContext(ctx).Table("users").Select("id, full_name, username").
		Where("id IN ?", ids).Scan(&nguoi).Error; err != nil {
		return
	}
	ten := make(map[uint]string, len(nguoi))
	for _, n := range nguoi {
		ten[n.ID] = strings.TrimSpace(n.FullName)
		if ten[n.ID] == "" {
			ten[n.ID] = n.Username
		}
	}
	for i := range ds {
		ds[i].CreatedByName = ten[ds[i].CreatedBy]
	}
}
