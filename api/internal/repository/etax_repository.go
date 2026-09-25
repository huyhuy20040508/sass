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

// DanhSachHoaDon — sổ hoá đơn của màn "Hoá đơn điện tử".
//
// Nối sang `orders` để in mã đơn và người mua: tờ hoá đơn không tự chép lại hai
// thứ ấy (nó giữ nguyên văn payload, nhưng đọc JSON cho từng dòng của một danh
// sách là quá đắt). LEFT JOIN chứ không JOIN: đơn bị xoá mềm thì tờ hoá đơn
// VẪN là chứng từ đã nộp cơ quan thuế, không được rơi khỏi sổ.
//
// Điều kiện `tenant_id` của hai bảng nối viết thẳng vào ON: plugin tenant chỉ
// chèn bộ lọc cho bảng chính.
func (r *etaxRepository) DanhSachHoaDon(ctx context.Context, f domain.HoaDonFilter) ([]domain.DongHoaDon, int64, domain.DemHoaDon, error) {
	// Hàng nút lọc đếm TRƯỚC khi áp ô trạng thái — đúng như v2: bấm "Lỗi" rồi
	// thì các nút khác vẫn phải nói mỗi nhóm có bao nhiêu tờ.
	var dem domain.DemHoaDon
	err := r.cauHoaDon(ctx, f).Select(`COUNT(*) AS tat_ca,
		COALESCE(SUM(etax_invoices.status = ?), 0) AS nhap,
		COALESCE(SUM(etax_invoices.status = ?), 0) AS da_gui,
		COALESCE(SUM(etax_invoices.status = ?), 0) AS da_phat_hanh,
		COALESCE(SUM(etax_invoices.status = ?), 0) AS hong`,
		domain.HoaDonNhap, domain.HoaDonDaGui, domain.HoaDonDaPhatHanh, domain.HoaDonHong).
		Scan(&dem).Error
	if err != nil {
		return nil, 0, domain.DemHoaDon{}, err
	}

	loc := func() *gorm.DB {
		return locNhieu(r.cauHoaDon(ctx, f), "etax_invoices.status", f.TrangThai)
	}

	var tong int64
	if err := loc().Count(&tong).Error; err != nil {
		return nil, 0, domain.DemHoaDon{}, err
	}
	rows := []domain.DongHoaDon{}
	if tong == 0 {
		return rows, 0, dem, nil
	}

	trang := max(f.Page, 1)
	soDong := f.PageSize
	if soDong < 1 {
		soDong = 20
	}

	// COALESCE cho mọi cột NULL được: tờ nháp chưa có số, chưa có mã cơ quan
	// thuế, và đơn đã xoá cứng thì không còn người mua.
	err = loc().Select(`etax_invoices.id, etax_invoices.order_id, etax_invoices.shop_id,
			COALESCE(o.order_code, '') AS order_code,
			etax_invoices.provider, etax_invoices.symbol,
			COALESCE(etax_invoices.invoice_no, '') AS invoice_no,
			COALESCE(etax_invoices.invoice_id, '') AS invoice_id,
			COALESCE(etax_invoices.tax_auth_code, '') AS tax_auth_code,
			COALESCE(etax_invoices.lookup_code, '') AS lookup_code,
			etax_invoices.status, etax_invoices.doc_status,
			etax_invoices.total_amount, etax_invoices.vat_amount,
			COALESCE(etax_invoices.error, '') AS error,
			COALESCE(o.recipient_name, '') AS customer_name,
			COALESCE(o.recipient_email, '') AS customer_email,
			COALESCE(o.recipient_phone, '') AS customer_phone,
			COALESCE(nt.full_name, '') AS nguoi_tao,
			etax_invoices.issued_at, etax_invoices.created_at`).
		// Mới nhất lên đầu, như v2 (`orderBy('id', 'desc')`).
		Order("etax_invoices.id DESC").
		Limit(soDong).
		Offset((trang - 1) * soDong).
		Scan(&rows).Error
	if err != nil {
		return nil, 0, domain.DemHoaDon{}, err
	}

	return rows, tong, dem, nil
}

// cauHoaDon dựng câu GỐC của sổ hoá đơn: hai bảng nối và mọi ô lọc TRỪ trạng
// thái (ô ấy áp riêng, sau lượt đếm cho hàng nút).
//
// Dựng LẠI cho mỗi lượt dùng — một *gorm.DB mang sẵn statement, xài hai lần thì
// điều kiện của lượt trước dính sang lượt sau.
func (r *etaxRepository) cauHoaDon(ctx context.Context, f domain.HoaDonFilter) *gorm.DB {
	q := r.db.WithContext(ctx).Model(&domain.EtaxInvoice{}).
		Joins("LEFT JOIN orders o ON o.id = etax_invoices.order_id AND o.tenant_id = etax_invoices.tenant_id").
		Joins("LEFT JOIN users nt ON nt.id = o.created_by AND nt.tenant_id = o.tenant_id")

	if f.KyHieu != "" {
		q = q.Where("etax_invoices.symbol LIKE ?", "%"+f.KyHieu+"%")
	}
	if f.SoHoaDon != "" {
		q = q.Where("etax_invoices.invoice_no LIKE ?", "%"+f.SoHoaDon+"%")
	}
	if f.MaDon != "" {
		kw := "%" + f.MaDon + "%"
		q = q.Where("(o.order_code LIKE ? OR etax_invoices.tax_auth_code LIKE ?)", kw, kw)
	}
	if f.KhachHang != "" {
		kw := "%" + f.KhachHang + "%"
		q = q.Where("(o.recipient_name LIKE ? OR o.recipient_phone LIKE ? OR o.recipient_email LIKE ?)", kw, kw, kw)
	}
	q = locNhieu(q, "o.created_by", f.CreatedBy)
	if f.ShopID > 0 {
		q = q.Where("etax_invoices.shop_id = ?", f.ShopID)
	}
	// Ngày phát hành: tờ chưa được cấp số (nháp, hỏng) thì chưa có ngày ấy — lấy
	// lúc lập lượt phát hành, không thì bộ lọc ngày làm chúng biến mất.
	if f.FromDate != "" {
		q = q.Where("COALESCE(etax_invoices.issued_at, etax_invoices.created_at) >= ?", f.FromDate+" 00:00:00")
	}
	if f.ToDate != "" {
		q = q.Where("COALESCE(etax_invoices.issued_at, etax_invoices.created_at) <= ?", f.ToDate+" 23:59:59")
	}

	return q
}
