package repository

import (
	"context"
	"errors"
	"time"

	"gorm.io/gorm"
	"gorm.io/gorm/clause"

	"sass-api/internal/domain"
)

// SINH MÃ THEO QUY TẮC — nơi quy tắc ở màn Thông số chung thật sự có hiệu lực.
//
// Mọi chỗ đặt mã trong hệ thống đều đi qua đây: chưa bật quy tắc thì hàm trả ""
// và nơi gọi giữ nguyên cách đặt mã sẵn có của nó. Nhờ vậy cửa hàng không đụng
// tới màn cấu hình không thấy gì khác đi.

// boDemMa là bộ đếm số thứ tự đã cấp — xem migration 0019.
type boDemMa struct {
	ID uint `gorm:"primaryKey"`
	domain.TenantOwned
	ShopID    uint
	DocType   string
	Bucket    string
	LastSeq   int64
	CreatedAt time.Time
	UpdatedAt time.Time
}

func (boDemMa) TableName() string { return "code_counters" }

// soLanDoMa là số lần thử khi mã cấp ra đã có người dùng.
//
// Xảy ra khi cửa hàng bật quy tắc GIỮA CHỪNG trên một tiền tố đã có mã cũ: bộ
// đếm bắt đầu từ 1 nên vài số đầu có thể đụng. Nhảy tiếp thay vì báo lỗi.
const soLanDoMa = 50

// SinhMa cấp mã kế tiếp cho một loại (xem domain.QuyTacMaRepository).
func (r *quyTacMaRepository) SinhMa(
	ctx context.Context, docType string, shopID uint, daCo func(string) (bool, error),
) (string, error) {
	var ma string
	err := r.db.WithContext(ctx).Transaction(func(tx *gorm.DB) error {
		var err error
		ma, err = SinhMaTrongTx(ctx, tx, docType, shopID, daCo)

		return err
	})

	return ma, err
}

// SinhMaTrongTx là bản dùng cho nơi ĐÃ mở transaction sẵn — mọi chứng từ đều
// thuộc nhóm này, vì số phải được cấp trong cùng lượt ghi với chính chứng từ đó.
//
// Trả "" khi loại chưa bật quy tắc.
func SinhMaTrongTx(
	ctx context.Context, tx *gorm.DB, docType string, shopID uint, daCo func(string) (bool, error),
) (string, error) {
	loai, ok := domain.TimLoaiMa(docType)
	if !ok {
		return "", domain.ErrLoaiMaLa
	}
	// Phạm vi do danh mục quyết định: danh mục dùng chung toàn cửa hàng, chứng từ
	// theo chi nhánh phát sinh.
	pham := shopID
	if loai.DungChung {
		pham = 0
	}

	var quyTac domain.QuyTacMa
	err := tx.WithContext(ctx).
		Where("doc_type = ? AND shop_id = ? AND is_active = ?", docType, pham, true).
		Take(&quyTac).Error
	if errors.Is(err, gorm.ErrRecordNotFound) {
		return "", nil
	}
	if err != nil {
		return "", err
	}

	bay := time.Now()
	moc := quyTac.Moc(bay)

	for i := 0; i < soLanDoMa; i++ {
		so, err := capSo(ctx, tx, pham, docType, moc)
		if err != nil {
			return "", err
		}

		ma := quyTac.MaMau(int(so), bay)
		if daCo == nil {
			return ma, nil
		}

		trung, err := daCo(ma)
		if err != nil {
			return "", err
		}
		if !trung {
			return ma, nil
		}
	}

	// Dò hết ngần ấy số mà vẫn đụng: quy tắc này đang chồng lên một dải mã cũ,
	// người dùng phải đổi tiền tố chứ không thể sinh bừa một mã trùng.
	return "", domain.ErrHetSoDe
}

// daCoMa dựng hàm hỏi bảng đích xem mã đã có ai dùng chưa.
//
// Unscoped: bản ghi đã xoá mềm vẫn giữ chỗ trong khoá duy nhất, nên mã của chúng
// vẫn là mã đã dùng.
func daCoMa(ctx context.Context, tx *gorm.DB, model any, cot string) func(string) (bool, error) {
	return func(ma string) (bool, error) {
		var n int64
		err := tx.WithContext(ctx).Unscoped().Model(model).Where(cot+" = ?", ma).Count(&n).Error

		return n > 0, err
	}
}

// maChungTu trả mã theo quy tắc, hoặc macDinh nếu loại này chưa bật quy tắc.
func maChungTu(
	ctx context.Context, tx *gorm.DB, docType string, shopID uint,
	model any, cot, macDinh string,
) (string, error) {
	ma, err := SinhMaTrongTx(ctx, tx, docType, shopID, daCoMa(ctx, tx, model, cot))
	if err != nil {
		return "", err
	}
	if ma == "" {
		return macDinh, nil
	}

	return ma, nil
}

// capSo lấy số thứ tự kế tiếp và KHOÁ hàng bộ đếm tới hết transaction.
//
// Khoá là toàn bộ lý do bảng này tồn tại: không có nó thì hai lượt lập phiếu
// cùng lúc cùng đọc ra một số, y như cách MAX(mã)+1 của bản ERP cũ vẫn hỏng.
func capSo(ctx context.Context, tx *gorm.DB, shopID uint, docType, bucket string) (int64, error) {
	var dem boDemMa
	err := tx.WithContext(ctx).
		Clauses(clause.Locking{Strength: "UPDATE"}).
		Where("shop_id = ? AND doc_type = ? AND bucket = ?", shopID, docType, bucket).
		Take(&dem).Error

	// Chưa có bộ đếm: dựng hàng đầu tiên. Hai lượt cùng dựng thì một bên vướng
	// khoá duy nhất — bên đó đọc lại (lúc này hàng đã có) rồi đi tiếp bình thường.
	if errors.Is(err, gorm.ErrRecordNotFound) {
		moi := boDemMa{ShopID: shopID, DocType: docType, Bucket: bucket, LastSeq: 1}
		if err := tx.WithContext(ctx).Create(&moi).Error; err == nil {
			return 1, nil
		} else if !errors.Is(err, gorm.ErrDuplicatedKey) {
			return 0, err
		}

		err = tx.WithContext(ctx).
			Clauses(clause.Locking{Strength: "UPDATE"}).
			Where("shop_id = ? AND doc_type = ? AND bucket = ?", shopID, docType, bucket).
			Take(&dem).Error
	}
	if err != nil {
		return 0, err
	}

	dem.LastSeq++
	if err := tx.WithContext(ctx).Model(&dem).Update("last_seq", dem.LastSeq).Error; err != nil {
		return 0, err
	}

	return dem.LastSeq, nil
}
