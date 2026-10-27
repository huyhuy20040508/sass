package repository

import (
	"context"
	"errors"
	"time"

	"gorm.io/gorm"
	"gorm.io/gorm/clause"

	"sass-api/internal/domain"
	"sass-api/internal/tenant"
)

type paymentMethodRepository struct{ db *gorm.DB }

// NewPaymentMethodRepository chạy trên DATA PLANE (có bộ lọc tenant): khoá cổng
// thanh toán của cửa hàng này không được lọt sang cửa hàng khác.
func NewPaymentMethodRepository(db *gorm.DB) domain.PaymentMethodRepository {
	return &paymentMethodRepository{db: db}
}

// ChiNhanhGhi dùng chung luật của phiếu kho: không khai chi nhánh thì chỉ đi
// tiếp khi cửa hàng có đúng một chi nhánh. Đoán chi nhánh ở đây là khai tài
// khoản ngân hàng vào nhầm quầy.
func (r *paymentMethodRepository) ChiNhanhGhi(ctx context.Context) (uint, error) {
	return chiNhanhCuaRequest(ctx, r.db)
}

// ChiNhanhTrungTam — chi nhánh mà WEBSITE lấy tài khoản và cổng QR.
//
// Website không đứng ở chi nhánh nào, nên lấy chi nhánh loại "Công ty" (pháp
// nhân đứng tên cửa hàng); không có thì chi nhánh mở sớm nhất — chính là dòng
// 'mac-dinh' dựng cùng lúc mở cửa hàng.
func (r *paymentMethodRepository) ChiNhanhTrungTam(ctx context.Context) (uint, error) {
	var cn domain.ChiNhanh
	err := r.db.WithContext(ctx).Model(&domain.ChiNhanh{}).Select("id").
		Where("is_active = ?", true).
		Order("CASE WHEN branch_type = 2 THEN 0 ELSE 1 END, id ASC").
		Take(&cn).Error
	if errors.Is(err, gorm.ErrRecordNotFound) {
		return 0, domain.ErrNotFound
	}

	return cn.ID, err
}

func (r *paymentMethodRepository) PhuongThuc(ctx context.Context, shopID uint) ([]domain.PaymentMethod, error) {
	var ds []domain.PaymentMethod
	err := r.db.WithContext(ctx).Where("shop_id = ?", shopID).Order("type ASC").Find(&ds).Error

	return ds, err
}

func (r *paymentMethodRepository) ChiTiet(ctx context.Context, shopID uint) ([]domain.PaymentMethodDetail, error) {
	var ds []domain.PaymentMethodDetail
	err := r.db.WithContext(ctx).Where("shop_id = ?", shopID).Order("id ASC").Find(&ds).Error

	return ds, err
}

func (r *paymentMethodRepository) MayQuetThe(ctx context.Context, shopID uint) ([]domain.PaymentDevice, error) {
	var ds []domain.PaymentDevice
	err := r.db.WithContext(ctx).Where("shop_id = ?", shopID).Order("id ASC").Find(&ds).Error

	return ds, err
}

func (r *paymentMethodRepository) TaoSan(
	ctx context.Context, shopID uint, ds []domain.PaymentMethod,
	chiTiet func(map[uint8]uint) []domain.PaymentMethodDetail,
) (bool, error) {
	tao := false
	err := r.db.WithContext(ctx).Transaction(func(tx *gorm.DB) error {
		// DoNothing trên khoá (tenant, shop, type): hai request đầu tiên tới cùng
		// lúc thì chỉ một bên chèn được, bên kia nhận 0 dòng và không chép thêm
		// tài khoản lần thứ hai.
		res := tx.Clauses(clause.OnConflict{DoNothing: true}).Create(&ds)
		if res.Error != nil {
			return res.Error
		}
		if res.RowsAffected < int64(len(ds)) {
			return nil
		}
		tao = true
		idTheoLoai := make(map[uint8]uint, len(ds))
		for _, m := range ds {
			idTheoLoai[m.Type] = m.ID
		}
		if ct := chiTiet(idTheoLoai); len(ct) > 0 {
			return tx.Create(&ct).Error
		}

		return nil
	})

	return tao, err
}

func (r *paymentMethodRepository) Ghi(ctx context.Context, shopID uint, fn func(tx domain.PaymentMethodTx) error) error {
	return r.db.WithContext(ctx).Transaction(func(tx *gorm.DB) error {
		// Khoá cả 4 dòng phương thức của chi nhánh trước khi đọc: luật mặc định
		// và luật "còn ít nhất một dòng bật" đọc rồi mới ghi, hai lượt chen nhau
		// là cùng thấy trạng thái cũ.
		var khoa []domain.PaymentMethod
		if err := tx.Clauses(clause.Locking{Strength: "UPDATE"}).
			Where("shop_id = ?", shopID).Find(&khoa).Error; err != nil {
			return err
		}

		return fn(&pmTx{tx: tx, shopID: shopID})
	})
}

func (r *paymentMethodRepository) TaoMay(ctx context.Context, d *domain.PaymentDevice) error {
	return r.db.WithContext(ctx).Create(d).Error
}

func (r *paymentMethodRepository) TheoMaWebhook(ctx context.Context, ma string) (*domain.PaymentMethodDetail, error) {
	ctx = tenant.WithoutScope(ctx, "webhook cổng thanh toán: mã trong URL là thứ cho biết cửa hàng nào")
	var d domain.PaymentMethodDetail
	err := r.db.WithContext(ctx).Where("webhook_code = ?", ma).Take(&d).Error
	if errors.Is(err, gorm.ErrRecordNotFound) {
		return nil, domain.ErrNotFound
	}

	return &d, err
}

func (r *paymentMethodRepository) GhiLanBaoTien(ctx context.Context, id uint, luc time.Time) error {
	return r.db.WithContext(ctx).Model(&domain.PaymentMethodDetail{}).Where("id = ?", id).
		UpdateColumn("data", gorm.Expr("JSON_SET(COALESCE(data, JSON_OBJECT()), '$.last_webhook_at', ?)", luc.Format(time.RFC3339))).Error
}

func (r *paymentMethodRepository) GanMaWebhook(ctx context.Context, id uint, ma string) error {
	return r.db.WithContext(ctx).Model(&domain.PaymentMethodDetail{}).
		Where("id = ? AND webhook_code IS NULL", id).UpdateColumn("webhook_code", ma).Error
}

// GhiDonDung ghi phí vào đơn VÀ phiếu chi phí thẻ trong cùng một transaction:
// báo cáo đọc orders.card_fee, sổ thu chi đọc phiếu — lệch nhau là hai màn hai số.
func (r *paymentMethodRepository) GhiDonDung(ctx context.Context, orderID uint, detailID *uint, phi float64) error {
	return r.db.WithContext(ctx).Transaction(func(tx *gorm.DB) error {
		err := tx.Model(&domain.Order{}).Where("id = ?", orderID).
			UpdateColumns(map[string]any{"payment_method_detail_id": detailID, "card_fee": phi}).Error
		if err != nil || phi <= 0 {
			return err
		}

		return ghiPhieuPhiThe(ctx, tx, orderID, detailID, phi)
	})
}

// ghiPhieuPhiThe: chuyển khoản vì ngân hàng trừ thẳng lúc báo có — tiền không
// qua két, ghi tiền mặt là đóng ca lệch đúng bằng phí.
func ghiPhieuPhiThe(ctx context.Context, tx *gorm.DB, orderID uint, detailID *uint, phi float64) error {
	var daCo int64
	err := tx.Model(&domain.ThuChi{}).
		Where("source = ? AND source_id = ?", domain.ThuChiTuPhiThe, orderID).
		Count(&daCo).Error
	if err != nil || daCo > 0 {
		return err
	}

	var don domain.Order
	if err := tx.Select("id", "shop_id", "order_code").First(&don, orderID).Error; err != nil {
		return err
	}

	moTa := "Phí quẹt thẻ " + don.OrderCode
	if detailID != nil {
		var tenMay string
		tx.Table("payment_method_details d").
			Joins("JOIN payment_devices pd ON pd.id = d.device_id").
			Where("d.id = ?", *detailID).
			Select("pd.name").Scan(&tenMay)
		if tenMay != "" {
			moTa += " · " + tenMay
		}
	}

	var loai []uint
	tx.Model(&domain.LoaiThuChi{}).
		Where("type = ? AND name = ?", domain.ThuChiPhieuChi, domain.LoaiChiPhiThanhToan).
		Order("id").Limit(1).Pluck("id", &loai)

	t := &domain.ThuChi{
		ShopID:        don.ShopID,
		Type:          domain.ThuChiPhieuChi,
		Amount:        phi,
		PaymentMethod: domain.ThuChiChuyenKhoan,
		Note:          moTa,
		Source:        domain.ThuChiTuPhiThe,
		SourceID:      &orderID,
	}
	if len(loai) > 0 {
		t.CategoryID = &loai[0]
	}

	return GhiThuChiTuSinh(ctx, tx, t)
}

func (r *paymentMethodRepository) SoDonDung(ctx context.Context, detailID uint) (int64, error) {
	var n int64
	err := r.db.WithContext(ctx).Model(&domain.Order{}).Where("payment_method_detail_id = ?", detailID).Count(&n).Error

	return n, err
}

func (r *paymentMethodRepository) SoPhieuThuChiDung(ctx context.Context, detailID uint) (int64, error) {
	var n int64
	err := r.db.WithContext(ctx).Model(&domain.ThuChi{}).Where("bank_account_id = ?", detailID).Count(&n).Error

	return n, err
}

type pmTx struct {
	tx     *gorm.DB
	shopID uint
}

func (t *pmTx) PhuongThuc() ([]domain.PaymentMethod, error) {
	var ds []domain.PaymentMethod
	err := t.tx.Where("shop_id = ?", t.shopID).Order("type ASC").Find(&ds).Error

	return ds, err
}

func (t *pmTx) ChiTiet() ([]domain.PaymentMethodDetail, error) {
	var ds []domain.PaymentMethodDetail
	err := t.tx.Where("shop_id = ?", t.shopID).Order("id ASC").Find(&ds).Error

	return ds, err
}

func (t *pmTx) May(id uint) (*domain.PaymentDevice, error) {
	var d domain.PaymentDevice
	err := t.tx.Where("shop_id = ? AND id = ?", t.shopID, id).Take(&d).Error
	if errors.Is(err, gorm.ErrRecordNotFound) {
		return nil, domain.ErrNotFound
	}

	return &d, err
}

func (t *pmTx) LuuPhuongThuc(m *domain.PaymentMethod) error {
	return t.tx.Select("status", "is_default", "updated_at").Save(m).Error
}

func (t *pmTx) LuuChiTiet(d *domain.PaymentMethodDetail) error {
	return t.tx.Save(d).Error
}

func (t *pmTx) XoaChiTiet(id uint) error {
	return t.tx.Where("shop_id = ?", t.shopID).Delete(&domain.PaymentMethodDetail{}, id).Error
}

func (t *pmTx) DanhSachMay() ([]domain.PaymentDevice, error) {
	var ds []domain.PaymentDevice
	err := t.tx.Where("shop_id = ?", t.shopID).Order("id ASC").Find(&ds).Error

	return ds, err
}

func (t *pmTx) LuuMay(d *domain.PaymentDevice) error {
	return t.tx.Select("code", "name", "description", "updated_at").Save(d).Error
}

func (t *pmTx) XoaMay(id uint) error {
	return t.tx.Where("shop_id = ?", t.shopID).Delete(&domain.PaymentDevice{}, id).Error
}
