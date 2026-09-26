package repository

import (
	"context"
	"fmt"
	"strings"

	"gorm.io/gorm"

	"sass-api/internal/chinhanh"
	"sass-api/internal/domain"
)

// CỬA DUY NHẤT GHI TỒN KHO.
//
// Từ migration 0005, tồn kho nằm ở `variant_stocks` — mỗi chi nhánh một dòng, và
// đó là NƠI DUY NHẤT giữ số hàng. Bản cộng `product_variants.stock_quantity` đã
// bỏ hẳn (migration 0036): hai chỗ cùng giữ một sự thật thì sớm muộn lệch nhau,
// và cái lệch ấy chỉ lộ ra lúc có người đếm hàng thật. Chỗ nào cần "cả cửa hàng
// còn bao nhiêu" thì CỘNG RA trong chính câu truy vấn đó.
//
// Vì vậy: mọi chỗ đụng vào kho — nhập hàng, bán hàng, huỷ đơn, trả hàng, chỉnh
// kho — đều gọi ĐÚNG hàm dưới đây, trong CÙNG giao dịch với chứng từ của nó.
// `grep -rn "ghiTonChiNhanh"` phải liệt kê được toàn bộ chỗ hàng hoá đổi số.
//
// KHÔNG dựng lại một cột cache nào nữa, dù nó tiện tới đâu: cột như vậy luôn
// đúng cho tới ngày có một đường ghi quên cập nhật nó, và ngày đó không ai biết.

// chiNhanhCuaRequest trả về chi nhánh mà lượt ghi kho này thuộc về.
//
// Ưu tiên chi nhánh ĐANG LÀM VIỆC trong ctx (Shop Admin gửi lên ở mỗi request).
//
// KHÔNG CÓ TRONG CTX THÌ CHỈ ĐI TIẾP KHI CỬA HÀNG CÓ ĐÚNG MỘT CHI NHÁNH. Đó là
// tiệm một điểm bán — màn hình không có gì để chọn và cũng không gửi gì lên, nên
// "chi nhánh nào" chỉ có một câu trả lời và không cần hỏi.
//
// Từ hai chi nhánh trở lên thì DỪNG. Trước đây chỗ này lấy chi nhánh có id nhỏ
// nhất, tức là tự đoán: mở chi nhánh mới rồi nhập hàng cho nó, hàng lại chui vào
// chi nhánh cũ nhất — không lỗi nào nổi lên, và chỉ lộ ra lúc có người đếm hàng
// thật. Thà bắt người dùng chọn một lần còn hơn ghi chứng từ vào kho do máy đoán.
//
// Đọc thì khác: "không cắt theo chi nhánh nào" là một câu trả lời hợp lệ (xem
// khoDangXet bên inventory_repository). Chỉ đường GHI mới không được phép mơ hồ.
func chiNhanhCuaRequest(ctx context.Context, db *gorm.DB) (uint, error) {
	if id, ok := chinhanh.ID(ctx); ok {
		return id, nil
	}

	// Lấy tối đa hai dòng: chỉ cần biết "một hay nhiều hơn một", không cần đếm hết.
	var ds []domain.ChiNhanh
	if err := db.WithContext(ctx).Model(&domain.ChiNhanh{}).
		Where("is_active = ?", true).Order("id ASC").Limit(2).Find(&ds).Error; err != nil {
		return 0, err
	}

	switch len(ds) {
	case 0:
		// Cửa hàng không còn chi nhánh nào đang mở. Không thể xảy ra qua đường bình
		// thường (ChiNhanhService chặn đóng cái cuối cùng), nhưng nếu có thì phải
		// dừng ở đây: không có kho nào để ghi hàng vào.
		return 0, domain.ErrNotFound
	case 1:
		return ds[0].ID, nil
	default:
		return 0, domain.ErrChuaChonChiNhanh
	}
}

// chiNhanhDoc là bản MỀM của chiNhanhCuaRequest, dành cho đường ĐỌC.
//
// Khác đúng một chỗ, và chỗ ấy là tất cả: nó KHÔNG báo lỗi khi không xác định
// được chi nhánh, chỉ trả 0. Đọc mà mơ hồ thì câu trả lời đúng là "không cắt
// theo kho nào" — còn ghi mà mơ hồ thì phải dừng lại hỏi, vì ghi nhầm kho là
// dữ liệu sai nằm lại vĩnh viễn.
//
// Vẫn suy ra được với cửa hàng MỘT chi nhánh: màn hình bên đó không có gì để
// chọn nên cũng không gửi header, mà "kho nào" thì chỉ có một câu trả lời.
func chiNhanhDoc(ctx context.Context, db *gorm.DB) uint {
	if id, ok := chinhanh.ID(ctx); ok {
		return id
	}

	var ds []domain.ChiNhanh
	if err := db.WithContext(ctx).Model(&domain.ChiNhanh{}).
		Where("is_active = ?", true).Order("id ASC").Limit(2).Find(&ds).Error; err != nil {
		return 0
	}
	if len(ds) == 1 {
		return ds[0].ID
	}

	return 0
}

// chanChungTuKhacChiNhanh từ chối một chứng từ không thuộc chi nhánh đang làm
// việc.
//
// VÌ SAO CẦN: mọi đường ĐỌC/SỬA một chứng từ đều tra theo `id` (cộng điều kiện
// cửa hàng do plugin chèn), không hề hỏi chi nhánh. Id chạy tuần tự, nên nhân
// viên ghim ở kho 2 chỉ việc gõ `/admin/phieu-mua-hang/5` là đọc được giá nhập
// và công nợ của kho 1 — rồi PUT/DELETE cũng lọt nốt. Middleware chỉ xác minh
// cái header hợp lệ với người gọi, nó không biết gì về bản ghi đang bị với tới.
//
// KHÔNG cắt khi chưa xác định được chi nhánh (chủ tiệm nhiều kho chưa chọn kho
// nào): đó là quy ước chung của mọi lượt đọc trong tệp này — thà cho xem cả cửa
// hàng còn hơn giấu mất phiếu của chính mình. Nhân viên bị phân công thì luôn
// có chi nhánh trong ctx, kể cả khi họ gỡ header đi (xem middleware.ChiNhanhDangLam).
//
// Nhận NHIỀU chi nhánh vì phiếu điều chuyển có hai đầu: kho gửi và kho nhận đều
// là người trong cuộc, cả hai đều phải mở được phiếu.
func chanChungTuKhacChiNhanh(ctx context.Context, db *gorm.DB, cua ...uint) error {
	cn := chiNhanhDoc(ctx, db)
	if cn == 0 {
		return nil
	}

	for _, s := range cua {
		if s == cn {
			return nil
		}
	}

	return domain.ErrKhongThuocChiNhanh
}

// locGanChiNhanh giới hạn truy vấn theo một BẢNG GÁN chi nhánh, giữ đúng quy ước
// dùng khắp hệ thống: KHÔNG CÓ DÒNG NÀO = ÁP DỤNG CHO MỌI CHI NHÁNH.
//
// Quy ước ấy là thứ khiến dữ liệu cũ không cần vá: chương trình khuyến mãi lập từ
// trước khi có bảng gán thì không có dòng nào, nên tiếp tục chạy toàn cửa hàng y
// như cũ. Ngược lại, nếu "rỗng" nghĩa là "không chi nhánh nào" thì đúng ngày chạy
// migration là mọi khuyến mãi tắt hết.
//
// GIỚI HẠN ĐÃ BIẾT: hàm này cắt được khi request MANG THEO chi nhánh — tức là
// khu quản trị và bán tại quầy. Đường storefront (khách mua trên web gõ mã giảm
// giá) phân giải cửa hàng theo TÊN MIỀN và không có khái niệm "đang đứng ở kho
// nào", nên với cửa hàng nhiều chi nhánh chiNhanhDoc trả 0 và mã vẫn dùng được.
// Chữa cho tới nơi đòi trả lời trước một câu khác hẳn: đơn đặt trên web thuộc
// chi nhánh nào? Hôm nay hệ thống chưa có câu trả lời đó.
//
// bangGan  — bảng nối (promotion_shops, voucher_shops…)
// cotCha   — cột trỏ về bản ghi cha trong bảng nối (promotion_id…)
// bangCha  — bảng cha, để ghép `<bangCha>.id`
func locGanChiNhanh(q *gorm.DB, ctx context.Context, db *gorm.DB, bangGan, cotCha, bangCha string) *gorm.DB {
	shopID := chiNhanhDoc(ctx, db)
	if shopID == 0 {
		return q
	}

	// Nhúng thẳng shopID vào câu chữ: nó là uint đã qua parse ở middleware, không
	// có đường nào cho chuỗi lạ lọt vào. Cùng lối với locHangCuaChiNhanh.
	return q.Where(fmt.Sprintf(`(
		EXISTS (SELECT 1 FROM %[1]s g WHERE g.%[2]s = %[3]s.id AND g.shop_id = %[4]d)
		OR NOT EXISTS (SELECT 1 FROM %[1]s g2 WHERE g2.%[2]s = %[3]s.id)
	)`, bangGan, cotCha, bangCha, shopID))
}

// ghiTonChiNhanh cộng delta vào tồn của MỘT biến thể TẠI MỘT chi nhánh, rồi dựng
// lại bản cộng sẵn ở product_variants.
//
// Trả về (trước, sau) THEO CHI NHÁNH ĐÓ — đó mới là cặp số mà bút toán kho phải
// ghi. Ghi cặp số của cả cửa hàng vào sổ của một chi nhánh thì lịch sử kho đọc
// lên vô nghĩa: "trước 40, sau 41" trong khi kho đó chỉ có 3 cái.
//
// tx PHẢI là giao dịch đã KHOÁ biến thể (SELECT ... FOR UPDATE) như mọi nơi gọi
// đang làm. Không khoá thì hai lượt bán cùng lúc cùng đọc một con số rồi cùng
// ghi đè — bán quá số hàng đang có mà không lỗi nào nổi lên.
//
// choPhepAm = false: tồn xuống dưới 0 thì trả ErrOutOfStock và giao dịch phải
// cuộn lại. Cho phép âm chỉ dành cho lượt TRẢ HÀNG VỀ (delta > 0 nên không chạm
// mốc đó) và cho dữ liệu cũ vốn đã âm sẵn.
// ChuyenKho mô tả MỘT lượt hàng đổi số: bao nhiêu, của chứng từ nào, và — nếu
// nơi gọi biết — thuộc lô nào.
//
// Có mặt từ lúc số lô thành một chiều của tồn kho (migration 0047): trước đó
// ghiTonChiNhanh chỉ cần một con số delta, nay còn phải biết cộng vào lô nào
// hoặc rút khỏi lô nào, và ghi lại lượt ấy để lượt hoàn sau đảo đúng chỗ.
type ChuyenKho struct {
	// Delta âm = ra khỏi kho, dương = vào kho.
	Delta int
	// ChoPhepAm cho tổng tồn của chi nhánh xuống dưới 0. Chỉ dành cho lượt hàng
	// VỀ và cho dữ liệu cũ vốn đã âm sẵn.
	ChoPhepAm bool

	// Lo là các lô CHỈ ĐỊNH của lượt hàng vào — nhập mua, trả lại đúng lô. Tổng
	// số lượng của chúng phải bằng Delta. Rỗng nghĩa là nơi gọi không biết lô:
	// lượt ra rút theo FIFO/FEFO, lượt vào tra sổ để hoàn về đúng lô đã lấy.
	Lo []domain.LoNhapSo

	// Luat là thứ tự rút lô. Bỏ trống thì dùng LuatXuatKhoMacDinh (FIFO, không
	// soi hạn) — đúng hành vi trước khi có tính năng lô.
	Luat *domain.LuatXuatKho

	// RefType / RefID là chứng từ gây ra lượt này. Thiếu nó thì lượt hoàn kho
	// sau này không tra được đã rút những lô nào, và hàng dồn hết về lô "Không
	// xác định" — xem hoanTheoSo.
	RefType string
	RefID   uint
}

// ghiTonChiNhanhLo là bản đầy đủ của cửa ghi kho: đổi TỔNG ở variant_stocks và
// đổi phần chia theo lô ở stock_lots, trong cùng một giao dịch.
//
// Thứ tự cố ý: đụng tổng TRƯỚC. Tổng là chỗ có luật "không cho âm", nên nó phải
// từ chối trước khi bảng lô kịp đổi — ngược lại thì một lượt bán quá tồn đã rút
// lô xong mới bị chặn, và giao dịch tuy cuộn lại nhưng luật đã nằm sai chỗ.
func ghiTonChiNhanhLo(tx *gorm.DB, shopID, variantID uint, c ChuyenKho) (truoc, sau int, err error) {
	truoc, sau, err = ghiTonChiNhanh(tx, shopID, variantID, c.Delta, c.ChoPhepAm)
	if err != nil || c.Delta == 0 {
		return truoc, sau, err
	}

	luat := domain.LuatXuatKhoMacDinh()
	if c.Luat != nil {
		luat = *c.Luat
	}

	if c.Delta < 0 {
		// Nơi gọi CHỈ RÕ lô (trả hàng nhà cung cấp: trả lô nào thì trừ lô ấy) thì
		// rút đúng những lô đó. Để FIFO tự chọn ở đây là trả lô A cho bên bán mà
		// trong sổ lô B vơi đi.
		if len(c.Lo) > 0 {
			phan := make(map[string]int, len(c.Lo))
			for _, l := range c.Lo {
				if l.Quantity <= 0 {
					continue
				}
				if err := nhapVaoLo(tx, shopID, variantID, domain.LoNhap{LotNumber: l.LotNumber}, -l.Quantity); err != nil {
					return truoc, sau, err
				}
				phan[l.LotNumber] += l.Quantity
			}

			return truoc, sau, ghiSoLo(tx, shopID, variantID, phan, -1, c.RefType, c.RefID)
		}

		phan, err := rutTheoLuat(tx, shopID, variantID, -c.Delta, luat)
		if err != nil {
			return truoc, sau, err
		}

		return truoc, sau, ghiSoLo(tx, shopID, variantID, phan, -1, c.RefType, c.RefID)
	}

	// Hàng VÀO kho, hai đường:
	//   - biết lô (nhập mua) → cộng thẳng vào lô đó;
	//   - không biết (huỷ đơn, khách trả) → tra sổ, hoàn về đúng lô đã lấy.
	if len(c.Lo) > 0 {
		phan := make(map[string]int, len(c.Lo))
		for _, l := range c.Lo {
			if l.Quantity <= 0 {
				continue
			}
			if err := nhapVaoLo(tx, shopID, variantID, l.LoNhap, l.Quantity); err != nil {
				return truoc, sau, err
			}
			phan[l.LotNumber] += l.Quantity
		}

		return truoc, sau, ghiSoLo(tx, shopID, variantID, phan, 1, c.RefType, c.RefID)
	}

	phan, err := hoanTheoSo(tx, shopID, variantID, c.Delta, c.RefType, c.RefID)
	if err != nil {
		return truoc, sau, err
	}

	return truoc, sau, ghiSoLo(tx, shopID, variantID, phan, 1, c.RefType, c.RefID)
}

func ghiTonChiNhanh(tx *gorm.DB, shopID, variantID uint, delta int, choPhepAm bool) (truoc, sau int, err error) {
	if shopID == 0 {
		// Lỗi LẬP TRÌNH, không phải lỗi người dùng: nơi gọi chưa xác định được chi
		// nhánh mà vẫn ghi kho. Hỏng ồn ào ở đây tốt hơn hẳn một dòng tồn kho rơi
		// vào chi nhánh do database tự đoán.
		return 0, 0, fmt.Errorf("ghi tồn kho mà chưa biết chi nhánh nào (biến thể %d)", variantID)
	}

	var dong domain.TonKhoChiNhanh
	// Unscoped: biến thể có thể đã xoá mềm mà hàng vẫn nằm trong kho — luồng huỷ
	// đơn phải trả được hàng về. Bản thân variant_stocks không có xoá mềm; điều
	// kiện tenant vẫn do plugin chèn.
	err = tx.Where("shop_id = ? AND product_variant_id = ?", shopID, variantID).
		Take(&dong).Error
	switch {
	case err == gorm.ErrRecordNotFound:
		// Chưa có dòng = chi nhánh này chưa từng có hàng của biến thể đó. Đây là
		// trạng thái BÌNH THƯỜNG (chi nhánh mới mở, hàng mới nhập lần đầu), nên
		// dựng dòng mới từ 0 chứ đừng coi là lỗi.
		dong = domain.TonKhoChiNhanh{ShopID: shopID, ProductVariantID: variantID}
	case err != nil:
		return 0, 0, err
	}

	truoc = dong.Quantity
	sau = truoc + delta
	if sau < 0 && !choPhepAm {
		return truoc, truoc, domain.ErrOutOfStock
	}

	dong.Quantity = sau
	if dong.ID == 0 {
		if err := tx.Create(&dong).Error; err != nil {
			return truoc, truoc, err
		}
	} else if err := tx.Model(&domain.TonKhoChiNhanh{}).
		Where("id = ?", dong.ID).Update("quantity", sau).Error; err != nil {
		return truoc, truoc, err
	}

	return truoc, sau, nil
}

// tonCuaChiNhanh đọc tồn của MỘT LOẠT biến thể TẠI một chi nhánh.
//
// Cặp đôi ĐỌC của ghiTonChiNhanh, và có mặt vì cùng một lý do: chỗ nào TRỪ kho
// của chi nhánh nào thì chỗ HIỆN SỐ cho người dùng cũng phải hỏi đúng chi nhánh
// ấy. Đọc bản cộng `product_variants.stock_quantity` rồi trừ vào một chi nhánh
// là kiểu lệch tệ nhất: màn hình nói còn 10, người dùng chốt phiếu 8, và lượt
// ghi kho từ chối vì kho đó chỉ có 5 — sau khi khách đã trả tiền.
//
// Biến thể chưa có dòng nào ở chi nhánh đó thì KHÔNG có khoá trong map, và nơi
// gọi đọc ra 0 — đúng nghĩa "kho này chưa từng có món hàng ấy", không phải lỗi.
func tonCuaChiNhanh(db *gorm.DB, shopID uint, variantIDs []uint) (map[uint]int, error) {
	ton := make(map[uint]int, len(variantIDs))
	if shopID == 0 || len(variantIDs) == 0 {
		return ton, nil
	}

	var rows []struct {
		ProductVariantID uint
		Quantity         int
	}
	if err := db.Model(&domain.TonKhoChiNhanh{}).
		Where("shop_id = ? AND product_variant_id IN ?", shopID, variantIDs).
		Select("product_variant_id, quantity").
		Scan(&rows).Error; err != nil {
		return nil, err
	}
	for _, r := range rows {
		ton[r.ProductVariantID] = r.Quantity
	}

	return ton, nil
}

// datTonChiNhanh ĐẶT tồn của một biến thể tại một chi nhánh về đúng một con số
// (kiểm kê), thay vì cộng thêm.
//
// Tách khỏi ghiTonChiNhanh vì lượt kiểm kê biết SỐ ĐÍCH chứ không biết chênh
// lệch, mà tự tính chênh lệch ở nơi gọi nghĩa là nơi gọi phải đọc tồn trước —
// đúng một lượt đọc nữa nằm ngoài khoá, tức là đúng chỗ hai người kiểm kê cùng
// lúc ghi đè lên nhau.
func datTonChiNhanh(tx *gorm.DB, shopID, variantID uint, soDich int, choPhepAm bool) (truoc, sau int, err error) {
	if soDich < 0 && !choPhepAm {
		return 0, 0, domain.ErrOutOfStock
	}

	truoc, _, err = ghiTonChiNhanh(tx, shopID, variantID, 0, true)
	if err != nil {
		return 0, 0, err
	}

	// Đi qua cửa CÓ LÔ, không phải ghiTonChiNhanh trần: kiểm kê không khai lô,
	// nên đếm thiếu thì rút FIFO/FEFO còn đếm thừa thì vào lô "Không xác định".
	// Gọi cửa trần ở đây là đổi tổng mà bảng lô đứng yên — bất biến
	// SUM(stock_lots) = variant_stocks gãy ngay tại đó.
	luat := luatXuatKho(tx)

	return ghiTonChiNhanhLo(tx, shopID, variantID, ChuyenKho{
		Delta:     soDich - truoc,
		ChoPhepAm: choPhepAm,
		Luat:      &luat,
		RefType:   domain.KhoRefKiemKe,
	})
}

// chanHangKhacChiNhanh từ chối chứng từ có mặt hàng ĐÃ GÁN RIÊNG cho chi nhánh
// khác.
//
// Ô "Chi nhánh" trên form hàng hoá ghi vào bảng nối `product_shops`, và quy ước
// là KHÔNG có dòng nào = mặt hàng dùng chung mọi chi nhánh (xem
// Product.Shops). Nhưng suốt từ ngày có ô ấy tới nay không nơi ghi nào tra lại
// nó: gán một món riêng cho chi nhánh A xong, người đứng ở B vẫn nhập và bán
// món đó bình thường, và tồn kho ghi vào B thật. Gán xong mà vẫn làm được mọi
// thứ thì việc gán không có nghĩa gì.
//
// CHỈ CHẶN LƯỢT GHI MỚI (nhập hàng, bán hàng). KHÔNG dùng ở chỉnh kho và phiếu
// trả nhà cung cấp: hai đường đó là để DỌN, và chi nhánh lỡ đang giữ hàng của
// một mặt hàng vừa bị gán đi nơi khác vẫn phải xuất được số hàng có thật ra khỏi
// kho mình. Chặn cả đường dọn thì hàng nằm chết trong kho, không cách nào gỡ.
//
// Trả tên tối đa ba mặt hàng: phiếu có thể dài vài chục dòng, và "sai ở đâu" là
// thứ duy nhất giúp sửa được. Nhiều hơn ba thì nói còn bao nhiêu nữa.
func chanHangKhacChiNhanh(ctx context.Context, db *gorm.DB, shopID uint, variantIDs []uint) error {
	if shopID == 0 || len(variantIDs) == 0 {
		return nil
	}

	var ten []string
	err := db.WithContext(ctx).
		Table("product_variants AS v").
		Joins("JOIN products p ON p.id = v.product_id").
		Where("v.id IN ?", variantIDs).
		Where(`EXISTS (SELECT 1 FROM product_shops ps WHERE ps.product_id = p.id)
			AND NOT EXISTS (SELECT 1 FROM product_shops ps2
				WHERE ps2.product_id = p.id AND ps2.shop_id = ?)`, shopID).
		Distinct().
		Pluck("p.name", &ten).Error
	if err != nil {
		return err
	}
	if len(ten) == 0 {
		return nil
	}

	cau := strings.Join(ten, ", ")
	if len(ten) > 3 {
		cau = strings.Join(ten[:3], ", ") + fmt.Sprintf(" và %d mặt hàng khác", len(ten)-3)
	}

	return fmt.Errorf("%w: %s không thuộc chi nhánh đang lập phiếu. Bỏ dòng đó ra, hoặc mở mặt hàng lên gán thêm chi nhánh này",
		domain.ErrHangKhongThuocChiNhanh, cau)
}

// bienTheCuaPhieu / bienTheCuaDon gom id biến thể của các dòng hàng để đưa vào
// chanHangKhacChiNhanh.
//
// Dòng KHÔNG gắn biến thể (hàng gõ tay, không có trong danh mục) thì bỏ qua: nó
// không thuộc mặt hàng nào nên cũng không thuộc chi nhánh nào.
func bienTheCuaPhieu(items []domain.PurchaseOrderItem) []uint {
	ids := make([]uint, 0, len(items))
	for _, it := range items {
		if it.ProductVariantID != nil && *it.ProductVariantID > 0 {
			ids = append(ids, *it.ProductVariantID)
		}
	}

	return ids
}

func bienTheCuaDon(items []domain.OrderItem) []uint {
	ids := make([]uint, 0, len(items))
	for _, it := range items {
		if it.ProductVariantID != nil && *it.ProductVariantID > 0 {
			ids = append(ids, *it.ProductVariantID)
		}
	}

	return ids
}
