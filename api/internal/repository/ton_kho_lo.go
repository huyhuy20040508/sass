package repository

import (
	"time"

	"gorm.io/gorm"
	"gorm.io/gorm/clause"

	"sass-api/internal/domain"
)

// SỐ LÔ LÀ MỘT CHIỀU CỦA TỒN KHO — phần rút và cộng theo lô.
//
// Tệp này KHÔNG được gọi từ đâu ngoài ghiTonChiNhanh trong ton_kho_chi_nhanh.go.
// Đó là cố ý: hai bảng `variant_stocks` (tổng) và `stock_lots` (chia theo lô)
// phải đổi cùng nhau trong cùng một giao dịch, và bất biến
//
//	variant_stocks.quantity = SUM(stock_lots.quantity)
//
// chỉ giữ được nếu có đúng một chỗ ghi cả hai. Gọi thẳng vào đây từ nơi khác là
// dựng ra một đường ghi thứ hai — và cái lệch nó tạo ra không nổi lên ở đâu cho
// tới lúc có người đếm hàng thật.

// Tên hai khoá cấu hình điều khiển lượt rút lô. Chép chuỗi ở đây thay vì tham
// chiếu hằng số bên service: repository KHÔNG được phụ thuộc ngược lên service,
// và hai chuỗi này cũng nằm trong database nên đổi tên là phải viết migration —
// không phải thứ lặng lẽ trôi đi.
const (
	khoaCachXuatKho   = "lot_issue_method"
	khoaChanHetHan    = "block_expired_stock"
	giaTriXuatKhoFEFO = "fefo"
)

// luatXuatKho đọc hai thông số điều khiển lượt rút lô của cửa hàng.
//
// Đọc trong CÙNG giao dịch với lượt ghi kho: đổi cấu hình giữa chừng thì lượt
// đang chạy vẫn theo luật nó đã đọc, không nửa đơn FIFO nửa đơn FEFO.
//
// Đọc hỏng thì trả luật mặc định chứ không trả lỗi: một lượt bán không được
// dừng lại chỉ vì bảng cấu hình đang có chuyện.
func luatXuatKho(tx *gorm.DB) domain.LuatXuatKho {
	luat := domain.LuatXuatKhoMacDinh()

	var rows []domain.Setting
	if err := tx.Model(&domain.Setting{}).
		Where("`key` IN ?", []string{khoaCachXuatKho, khoaChanHetHan}).
		Find(&rows).Error; err != nil {
		return luat
	}

	for _, r := range rows {
		switch r.Key {
		case khoaCachXuatKho:
			if r.Value == giaTriXuatKhoFEFO {
				luat.Cach = domain.XuatFEFO
			}
		case khoaChanHetHan:
			luat.ChanHetHan = r.Value == "1"
		}
	}

	return luat
}

// lotRow gói một lô đang xét trong lượt rút.
type lotRow struct {
	ID         uint
	LotNumber  string
	Quantity   int
	ExpireDate *time.Time
}

// nhapVaoLo cộng hàng VÀO một lô cụ thể, tạo lô nếu chi nhánh chưa có.
//
// Khoá dòng trước khi cộng: hai phiếu mua cùng lô duyệt song song thì lượt sau
// phải đọc được con số lượt trước vừa ghi, không thì mất một lượt cộng.
func nhapVaoLo(tx *gorm.DB, shopID, variantID uint, lo domain.LoNhap, soLuong int) error {
	var dong domain.TonKhoLo
	err := tx.Clauses(clause.Locking{Strength: "UPDATE"}).
		Where("shop_id = ? AND product_variant_id = ? AND lot_number = ?", shopID, variantID, lo.LotNumber).
		Take(&dong).Error

	switch {
	case err == gorm.ErrRecordNotFound:
		return tx.Create(&domain.TonKhoLo{
			ShopID:           shopID,
			ProductVariantID: variantID,
			LotNumber:        lo.LotNumber,
			ExpireDate:       lo.ExpireDate,
			Quantity:         soLuong,
			UnitCost:         lo.UnitCost,
		}).Error
	case err != nil:
		return err
	}

	moi := map[string]any{"quantity": dong.Quantity + soLuong}
	// Hạn dùng và giá nhập chỉ ghi đè khi lượt này CÓ khai. Lượt hoàn hàng về
	// không mang theo hai thứ đó, và ghi nil vào là xoá mất hạn dùng của một lô
	// đang có thật.
	if lo.ExpireDate != nil {
		moi["expire_date"] = lo.ExpireDate
	}
	if lo.UnitCost > 0 {
		moi["unit_cost"] = lo.UnitCost
	}

	return tx.Model(&domain.TonKhoLo{}).Where("id = ?", dong.ID).Updates(moi).Error
}

// rutTheoLuat rút `soLuong` (dương) khỏi các lô của một biến thể, theo thứ tự
// FIFO hoặc FEFO, và trả về đã rút của lô nào bao nhiêu.
//
// Rút HẾT lô này mới sang lô kế, đúng như v2: một lượt bán có thể ăn vào nhiều
// lô, và mỗi phần phải ghi sổ riêng để lượt hoàn sau này đảo lại đúng chỗ.
//
// Không đủ hàng thì phần thiếu dồn vào lô "Không xác định" — lô đó tụt xuống âm.
// Đây là hành vi của v2 (createUndefinedStock) và là hành vi ĐÚNG cho quầy:
// chặn ở đây nghĩa là người bán đứng hình giữa lúc có khách, trong khi luật
// "không cho bán quá tồn" đã được ghiTonChiNhanh canh ở mức tổng rồi.
func rutTheoLuat(tx *gorm.DB, shopID, variantID uint, soLuong int, luat domain.LuatXuatKho) (map[string]int, error) {
	daRut := make(map[string]int, 2)
	if soLuong <= 0 {
		return daRut, nil
	}

	var rows []lotRow
	q := tx.Model(&domain.TonKhoLo{}).
		Clauses(clause.Locking{Strength: "UPDATE"}).
		Where("shop_id = ? AND product_variant_id = ? AND quantity > 0", shopID, variantID)

	if luat.ChanHetHan {
		// Lô quá hạn nằm ngoài danh sách rút. Hàng vẫn còn trong kho và vẫn hiện
		// trên báo cáo — chỉ là không bán ra được nữa.
		q = q.Where("expire_date IS NULL OR expire_date >= ?", time.Now().Format("2006-01-02"))
	}

	if luat.Cach == domain.XuatFEFO {
		// Hàng KHÔNG có hạn xuống cuối: MySQL xếp NULL lên đầu, mà "không có hạn"
		// nghĩa là không vội bán, để nó chen trước lô sắp hết hạn là ngược ý FEFO.
		q = q.Order("expire_date IS NULL, expire_date ASC, id ASC")
	} else {
		q = q.Order("id ASC")
	}

	if err := q.Select("id, lot_number, quantity, expire_date").Scan(&rows).Error; err != nil {
		return nil, err
	}

	con := soLuong
	for _, r := range rows {
		if con <= 0 {
			break
		}
		lay := r.Quantity
		if lay > con {
			lay = con
		}
		if err := tx.Model(&domain.TonKhoLo{}).Where("id = ?", r.ID).
			Update("quantity", gorm.Expr("quantity - ?", lay)).Error; err != nil {
			return nil, err
		}
		daRut[r.LotNumber] += lay
		con -= lay
	}

	// Còn thiếu: lô "Không xác định" gánh phần âm. Đi qua nhapVaoLo với số âm để
	// dùng chung đúng một đường tạo/khoá dòng.
	if con > 0 {
		if err := nhapVaoLo(tx, shopID, variantID, domain.LoNhap{LotNumber: domain.LoKhongXacDinh}, -con); err != nil {
			return nil, err
		}
		daRut[domain.LoKhongXacDinh] += con
	}

	return daRut, nil
}

// hoanTheoSo trả `soLuong` (dương) VỀ đúng những lô đã rút ra theo một chứng từ.
//
// Đây là lý do sổ `stock_lot_moves` tồn tại: bán hàng không chọn lô, nên nếu
// không tra lại lượt rút thì huỷ đơn chỉ còn cách dồn hàng vào lô "Không xác
// định" — mỗi vòng bán–trả bào mòn một ít khỏi các lô có thật, vài tháng sau
// bảng lô chỉ còn một cục vô danh.
//
// Hoàn theo thứ tự NGƯỢC lại lúc rút (lô rút sau hoàn trước): trả một phần thì
// phần trả về đúng lô vừa lấy gần nhất, hợp với cách người ta thực sự trả hàng.
//
// Không tra ra lượt rút nào (đơn có từ trước khi có bảng này, hoặc chứng từ gốc
// đã bị xoá) thì hoàn vào lô "Không xác định" — mất dấu lô còn hơn mất hàng.
func hoanTheoSo(tx *gorm.DB, shopID, variantID uint, soLuong int, refType string, refID uint) (map[string]int, error) {
	daHoan := make(map[string]int, 2)
	if soLuong <= 0 {
		return daHoan, nil
	}

	var rows []struct {
		LotNumber string
		Quantity  int
	}
	// Cộng gộp theo lô rồi mới hoàn: một đơn có thể đã rút–hoàn vài lượt, và số
	// còn hoàn được của mỗi lô là TỔNG các lượt ấy, không phải dòng cuối cùng.
	err := tx.Model(&domain.ChuyenKhoLo{}).
		Where("shop_id = ? AND product_variant_id = ? AND reference_type = ? AND reference_id = ?",
			shopID, variantID, refType, refID).
		Select("lot_number, SUM(quantity) AS quantity").
		Group("lot_number").
		Having("SUM(quantity) < 0").
		Order("MIN(id) DESC").
		Scan(&rows).Error
	if err != nil {
		return nil, err
	}

	con := soLuong
	for _, r := range rows {
		if con <= 0 {
			break
		}
		conHoanDuoc := -r.Quantity
		tra := conHoanDuoc
		if tra > con {
			tra = con
		}
		if err := nhapVaoLo(tx, shopID, variantID, domain.LoNhap{LotNumber: r.LotNumber}, tra); err != nil {
			return nil, err
		}
		daHoan[r.LotNumber] += tra
		con -= tra
	}

	if con > 0 {
		if err := nhapVaoLo(tx, shopID, variantID, domain.LoNhap{LotNumber: domain.LoKhongXacDinh}, con); err != nil {
			return nil, err
		}
		daHoan[domain.LoKhongXacDinh] += con
	}

	return daHoan, nil
}

// ghiSoLo ghi các lượt lô vừa đi vào sổ tiêu thụ. `dau` là +1 khi hàng vào kho,
// -1 khi ra — `phan` luôn mang số dương.
func ghiSoLo(tx *gorm.DB, shopID, variantID uint, phan map[string]int, dau int, refType string, refID uint) error {
	if len(phan) == 0 {
		return nil
	}

	rows := make([]domain.ChuyenKhoLo, 0, len(phan))
	for lo, sl := range phan {
		if sl == 0 {
			continue
		}
		dong := domain.ChuyenKhoLo{
			ShopID:           shopID,
			ProductVariantID: variantID,
			LotNumber:        lo,
			Quantity:         dau * sl,
			ReferenceType:    refType,
		}
		if refID > 0 {
			id := refID
			dong.ReferenceID = &id
		}
		rows = append(rows, dong)
	}
	if len(rows) == 0 {
		return nil
	}

	return tx.Create(&rows).Error
}

// loCuaBienThe đọc các lô ĐANG CÒN HÀNG của một loạt biến thể tại một chi nhánh.
//
// Ô chọn số lô trên màn lập phiếu đọc đường này: bày ra lô đang có kèm hạn dùng
// và giá nhập lần trước, thay vì bắt người dùng gõ lại tay một chuỗi mà gõ sai
// một ký tự là đẻ ra một lô mới.
func loCuaBienThe(db *gorm.DB, shopID uint, variantIDs []uint) (map[uint][]domain.TonKhoLo, error) {
	out := make(map[uint][]domain.TonKhoLo, len(variantIDs))
	if shopID == 0 || len(variantIDs) == 0 {
		return out, nil
	}

	var rows []domain.TonKhoLo
	err := db.Model(&domain.TonKhoLo{}).
		Where("shop_id = ? AND product_variant_id IN ? AND quantity > 0 AND lot_number <> ''",
			shopID, variantIDs).
		Order("product_variant_id ASC, expire_date IS NULL, expire_date ASC, id ASC").
		Find(&rows).Error
	if err != nil {
		return nil, err
	}
	for _, r := range rows {
		out[r.ProductVariantID] = append(out[r.ProductVariantID], r)
	}

	return out, nil
}
