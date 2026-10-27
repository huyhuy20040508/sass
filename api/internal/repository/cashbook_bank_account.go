package repository

import (
	"context"
	"encoding/json"
	"strings"

	"sass-api/internal/domain"
)

// dongTaiKhoan — một dòng payment_method_details bank_transfer kèm tên chi nhánh.
type dongTaiKhoan struct {
	ID        uint
	ShopID    uint
	Status    bool
	IsDefault bool
	Data      string
	ChiNhanh  string
}

func (d dongTaiKhoan) tk() domain.TaiKhoanThuChi {
	var m map[string]string
	_ = json.Unmarshal([]byte(d.Data), &m)
	ngan := domain.TenNganHang(m["bank_id"])
	if ngan == "" {
		ngan = m["bank_name_old"]
	}
	so := m["bank_account"]
	nhan := strings.Join(khongRong(ngan, so, m["bank_account_name"]), " · ")
	return domain.TaiKhoanThuChi{ID: d.ID, ShopID: d.ShopID, ChiNhanh: d.ChiNhanh, Nhan: nhan, NganHang: ngan,
		SoTaiKhoan: so, ChuTaiKhoan: m["bank_account_name"], DangBat: d.Status, MacDinh: d.IsDefault}
}

func khongRong(v ...string) []string {
	ra := []string{}
	for _, s := range v {
		if s = strings.TrimSpace(s); s != "" {
			ra = append(ra, s)
		}
	}
	return ra
}

func (r *thuChiRepository) docTaiKhoan(ctx context.Context, where string, args ...any) ([]domain.TaiKhoanThuChi, error) {
	var rows []dongTaiKhoan
	err := r.db.WithContext(ctx).Model(&domain.PaymentMethodDetail{}).
		Select("payment_method_details.id, payment_method_details.shop_id, payment_method_details.status, payment_method_details.is_default, "+
			"COALESCE(CAST(payment_method_details.data AS CHAR), '') AS data, COALESCE(s.name, '') AS chi_nhanh").
		Joins("LEFT JOIN shops s ON s.id = payment_method_details.shop_id").
		Where("payment_method_details.detail_type = ?", "bank_transfer").
		Where(where, args...).
		Order("payment_method_details.shop_id, payment_method_details.is_default DESC, payment_method_details.id").
		Scan(&rows).Error
	if err != nil {
		return nil, err
	}
	ra := make([]domain.TaiKhoanThuChi, 0, len(rows))
	for _, d := range rows {
		ra = append(ra, d.tk())
	}
	return ra, nil
}

func (r *thuChiRepository) TaiKhoanNganHang(ctx context.Context, shopID uint, chiDangBat bool) ([]domain.TaiKhoanThuChi, error) {
	where, args := "1 = 1", []any{}
	if shopID > 0 {
		where, args = "payment_method_details.shop_id = ?", []any{shopID}
	}
	if chiDangBat {
		where += " AND payment_method_details.status = 1"
	}
	return r.docTaiKhoan(ctx, where, args...)
}

// nhanTaiKhoan — nhãn tài khoản cho các phiếu chuyển khoản trong danh sách.
func (r *thuChiRepository) nhanTaiKhoan(ctx context.Context, list []domain.ThuChi) (map[uint]string, error) {
	ra := map[uint]string{}
	var ids []uint
	for _, t := range list {
		if t.BankAccountID != nil {
			ids = append(ids, *t.BankAccountID)
		}
	}
	if len(ids) == 0 {
		return ra, nil
	}
	ds, err := r.docTaiKhoan(ctx, "payment_method_details.id IN ?", ids)
	if err != nil {
		return nil, err
	}
	for _, tk := range ds {
		ra[tk.ID] = tk.Nhan
	}
	return ra, nil
}
