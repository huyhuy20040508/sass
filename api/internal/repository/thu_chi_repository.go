package repository

import (
	"context"
	"errors"
	"strings"

	"gorm.io/gorm"
	"gorm.io/gorm/clause"

	"sass-api/internal/domain"
)

type thuChiRepository struct{ db *gorm.DB }

func NewThuChiRepository(db *gorm.DB) domain.ThuChiRepository {
	return &thuChiRepository{db: db}
}

// ---------- Liệt kê ----------

// nguonTuLoc dịch một giá trị của ô lọc "Loại" về danh sách nguồn thật.
//
// "return" là mục "Trả hàng" trên màn hình và nó gồm CẢ HAI chiều trả. v2 chỉ
// lọc trả hàng bán rồi nhét trả hàng NCC vào chung mục "Bán hàng", nên bên đó
// chọn "Trả hàng" là không bao giờ thấy phiếu trả NCC.
func nguonTuLoc(v string) []string {
	switch strings.TrimSpace(v) {
	case "return":
		return []string{domain.ThuChiTuTraHang, domain.ThuChiTuTraNCC}
	case domain.ThuChiTuTay, domain.ThuChiTuDonHang, domain.ThuChiTuPhieuMua,
		domain.ThuChiTuTraNCC, domain.ThuChiTuTraHang:
		return []string{strings.TrimSpace(v)}
	}

	return nil
}

// veThuChi tách chuỗi "0,1" thành danh sách vế hợp lệ. Giá trị lạ bị bỏ, không
// báo lỗi: bộ lọc hỏng thì trang phải hiện danh sách chứ không phải câu lỗi.
//
// Trả []uint chứ KHÔNG phải []uint8 dù cột chỉ nhận 0/1: uint8 chính là byte,
// nên GORM nhìn []uint8 ra một chuỗi nhị phân và dựng `type IN '<binary>'` —
// MySQL từ chối ngay ở tầng cú pháp.
func veThuChi(v string) []uint {
	v = strings.TrimSpace(v)
	if v == "" {
		return nil
	}

	out := make([]uint, 0, 2)
	for _, phan := range strings.Split(v, ",") {
		switch strings.TrimSpace(phan) {
		case "0":
			out = append(out, uint(domain.ThuChiPhieuThu))
		case "1":
			out = append(out, uint(domain.ThuChiPhieuChi))
		}
	}

	return out
}

// loc dựng phần WHERE dùng chung cho cả ba lượt đọc: đếm, lấy trang, và cộng
// quỹ. `theoNgay` = false thì BỎ hai điều kiện ngày — đó là cách quỹ đầu kỳ lấy
// được mọi phiếu trước khoảng đang xem mà vẫn giữ nguyên các bộ lọc khác.
func (r *thuChiRepository) loc(ctx context.Context, f domain.ThuChiFilter, theoNgay bool) *gorm.DB {
	q := r.db.WithContext(ctx).Model(&domain.ThuChi{})

	if kw := strings.TrimSpace(f.Keyword); kw != "" {
		q = q.Where("code LIKE ?", "%"+kw+"%")
	}
	if f.ShopID > 0 {
		q = q.Where("shop_id = ?", f.ShopID)
	}

	// Vế thu / chi. KHÔNG dùng idTuChuoi ở đây: hàm ấy bỏ mọi số 0 (id 0 vô
	// nghĩa), mà 0 chính là "phiếu thu" — lọc riêng phiếu thu sẽ ra bảng rỗng.
	// Danh sách rỗng sau khi gạn = người gọi chỉ gửi giá trị lạ, coi như không lọc.
	if ve := veThuChi(f.Type); len(ve) > 0 {
		q = q.Where("type IN ?", ve)
	}

	if ids := idTuChuoi(f.CategoryID); len(ids) > 0 {
		q = q.Where("category_id IN ?", ids)
	}
	if ids := idTuChuoi(f.CreatedBy); len(ids) > 0 {
		q = q.Where("created_by IN ?", ids)
	}

	if s := strings.TrimSpace(f.Source); s != "" {
		nguon := make([]string, 0, 4)
		for _, phan := range strings.Split(s, ",") {
			nguon = append(nguon, nguonTuLoc(phan)...)
		}
		if len(nguon) > 0 {
			q = q.Where("source IN ?", nguon)
		}
	}

	if theoNgay {
		if d := strings.TrimSpace(f.FromDate); d != "" {
			q = q.Where("created_at >= ?", d+" 00:00:00")
		}
		if d := strings.TrimSpace(f.ToDate); d != "" {
			q = q.Where("created_at <= ?", d+" 23:59:59")
		}
	}

	return q
}

// tongTheoVe cộng SUM(amount) tách theo vế thu / chi trong MỘT lượt đọc.
//
// Một câu truy vấn chứ không hai: hai câu thì giữa chúng có thể có một phiếu vừa
// được ghi, và bảng in ra tổng thu của một thời điểm cộng tổng chi của thời điểm
// khác — số không cân mà không ai giải thích được.
func tongTheoVe(q *gorm.DB) (thu, chi float64, err error) {
	var row struct {
		Thu float64
		Chi float64
	}
	err = q.Select(
		"COALESCE(SUM(CASE WHEN type = ? THEN amount ELSE 0 END), 0) AS thu, "+
			"COALESCE(SUM(CASE WHEN type = ? THEN amount ELSE 0 END), 0) AS chi",
		domain.ThuChiPhieuThu, domain.ThuChiPhieuChi,
	).Scan(&row).Error

	return row.Thu, row.Chi, err
}

// List trả về trang đang xem + tổng số dòng + bốn ô quỹ.
//
// Quỹ đầu kỳ cộng từ MỌI phiếu trước ngày bắt đầu, giữ nguyên các bộ lọc khác.
// Không có ngày bắt đầu thì đầu kỳ bằng 0 chứ không phải "cả lịch sử": bản v2
// dùng lại nguyên câu truy vấn chưa gắn điều kiện ngày nên khi bỏ trống ô ngày
// thì đầu kỳ ôm trọn lịch sử, rồi cuối kỳ cộng thêm lần nữa — quỹ nhân đôi.
func (r *thuChiRepository) List(
	ctx context.Context, f domain.ThuChiFilter,
) ([]domain.ThuChi, int64, domain.ThuChiTongKet, error) {
	var tk domain.ThuChiTongKet

	var total int64
	if err := r.loc(ctx, f, true).Count(&total).Error; err != nil {
		return nil, 0, tk, err
	}

	// Trong khoảng đang xem.
	thu, chi, err := tongTheoVe(r.loc(ctx, f, true))
	if err != nil {
		return nil, 0, tk, err
	}
	tk.TongThu, tk.TongChi = thu, chi

	// Trước khoảng đang xem — chỉ khi có mốc bắt đầu.
	if d := strings.TrimSpace(f.FromDate); d != "" {
		qTruoc := r.loc(ctx, f, false).Where("created_at < ?", d+" 00:00:00")
		thuTruoc, chiTruoc, err := tongTheoVe(qTruoc)
		if err != nil {
			return nil, 0, tk, err
		}
		tk.DauKy = thuTruoc - chiTruoc
	}
	tk.CuoiKy = tk.DauKy + tk.TongThu - tk.TongChi

	page, size := f.Page, f.PageSize
	if page < 1 {
		page = 1
	}
	if size < 1 {
		size = 10
	}

	var list []domain.ThuChi
	err = r.loc(ctx, f, true).
		Order("created_at DESC").Order("id DESC").
		Offset((page - 1) * size).Limit(size).
		Find(&list).Error
	if err != nil {
		return nil, 0, tk, err
	}

	if err := r.trangTri(ctx, list); err != nil {
		return nil, 0, tk, err
	}

	return list, total, tk, nil
}

// trangTri tra kèm tên chi nhánh, tên phân loại, tên người nộp và tên người lập.
//
// `SourceCode` (mã chứng từ gốc của phiếu tự sinh) tra theo NGUỒN: mỗi nguồn một
// bảng và một cột mã khác nhau, nên gom id theo nguồn rồi tra một lượt mỗi bảng.
//
// Gom theo từng bảng rồi tra MỘT lượt mỗi bảng, không tra từng dòng: một trang
// 50 phiếu mà tra lẻ là 250 lượt đi database cho một lần mở trang.
func (r *thuChiRepository) trangTri(ctx context.Context, list []domain.ThuChi) error {
	if len(list) == 0 {
		return nil
	}

	shopIDs := make([]uint, 0, len(list))
	catIDs := make([]uint, 0, len(list))
	userIDs := make([]uint, 0, len(list))
	nccIDs := make([]uint, 0, len(list))
	payerIDs := make([]uint, 0, len(list))

	for i := range list {
		t := &list[i]
		shopIDs = append(shopIDs, t.ShopID)
		if t.CategoryID != nil {
			catIDs = append(catIDs, *t.CategoryID)
		}
		if t.CreatedBy != nil {
			userIDs = append(userIDs, *t.CreatedBy)
		}
		if t.EmployeeID != nil {
			userIDs = append(userIDs, *t.EmployeeID)
		}
		if t.SupplierID != nil {
			nccIDs = append(nccIDs, *t.SupplierID)
		}
		if t.PayerID != nil {
			payerIDs = append(payerIDs, *t.PayerID)
		}
	}

	tenChiNhanh, err := r.tenTheoID(ctx, "shops", "name", shopIDs)
	if err != nil {
		return err
	}
	tenLoai, err := r.tenTheoID(ctx, "income_expense_types", "name", catIDs)
	if err != nil {
		return err
	}
	tenNguoi, err := r.tenTheoID(ctx, "users", "full_name", userIDs)
	if err != nil {
		return err
	}
	tenNCC, err := r.tenTheoID(ctx, "suppliers", "name", nccIDs)
	if err != nil {
		return err
	}
	tenNguoiNop, err := r.tenTheoID(ctx, "income_expense_payers", "name", payerIDs)
	if err != nil {
		return err
	}

	maNguon, err := r.maChungTuGoc(ctx, list)
	if err != nil {
		return err
	}

	for i := range list {
		t := &list[i]
		t.BranchName = tenChiNhanh[t.ShopID]
		if t.CategoryID != nil {
			t.CategoryName = tenLoai[*t.CategoryID]
		}
		if t.CreatedBy != nil {
			t.CreatedByName = tenNguoi[*t.CreatedBy]
		}

		// Gộp ba cột id về một: tên để in ra bảng, id để hộp Sửa chọn lại đúng
		// người. Thiếu vế id thì mở phiếu ra ô "Người nộp" trống trơn.
		switch {
		case t.EmployeeID != nil:
			t.PayerName, t.PayerRefID = tenNguoi[*t.EmployeeID], t.EmployeeID
		case t.SupplierID != nil:
			t.PayerName, t.PayerRefID = tenNCC[*t.SupplierID], t.SupplierID
		case t.PayerID != nil:
			t.PayerName, t.PayerRefID = tenNguoiNop[*t.PayerID], t.PayerID
		}

		if t.SourceID != nil {
			t.SourceCode = maNguon[t.Source][*t.SourceID]
		}
	}

	return nil
}

// bangMaNguon: mỗi nguồn phiếu tự sinh nằm ở một bảng, và cột mã cũng một tên
// khác nhau. Khai ở đây một chỗ để thêm nguồn mới là thêm một dòng.
var bangMaNguon = map[string][2]string{
	domain.ThuChiTuDonHang:  {"orders", "order_code"},
	domain.ThuChiTuPhieuMua: {"purchase_orders", "po_code"},
	domain.ThuChiTuTraHang:  {"order_returns", "return_code"},
	domain.ThuChiTuTraNCC:   {"supplier_returns", "return_code"},
}

// maChungTuGoc tra mã chứng từ đã đẻ ra mỗi phiếu tự sinh, trả về map lồng
// [nguồn][id] = mã.
//
// KHÔNG lọc `deleted_at`: chứng từ gốc xoá đi rồi thì phiếu thu chi vẫn còn (tiền
// đã ra vào thật), và lúc ấy mã kia là manh mối DUY NHẤT để lần lại nó ra.
func (r *thuChiRepository) maChungTuGoc(
	ctx context.Context, list []domain.ThuChi,
) (map[string]map[uint]string, error) {
	theoNguon := make(map[string][]uint)
	for i := range list {
		t := &list[i]
		if t.SourceID == nil || *t.SourceID == 0 {
			continue
		}
		if _, co := bangMaNguon[t.Source]; co {
			theoNguon[t.Source] = append(theoNguon[t.Source], *t.SourceID)
		}
	}

	out := make(map[string]map[uint]string, len(theoNguon))
	for nguon, ids := range theoNguon {
		bang := bangMaNguon[nguon]
		ma, err := r.tenTheoID(ctx, bang[0], bang[1], ids)
		if err != nil {
			return nil, err
		}
		out[nguon] = ma
	}

	return out, nil
}

// tenTheoID tra tên của một bảng theo danh sách id.
//
// KHÔNG dùng Model(&struct{}) mà đi thẳng Table(): bảng `shops` và `users` có
// bộ lọc cửa hàng riêng ở tầng dưới GORM, còn ở đây id đã lấy từ chính phiếu của
// cửa hàng này nên không cần lọc lại — và lọc lại bằng một struct khác thì lại
// phải nhớ struct nào có TenantOwned.
func (r *thuChiRepository) tenTheoID(
	ctx context.Context, bang, cotTen string, ids []uint,
) (map[uint]string, error) {
	out := make(map[uint]string)
	if len(ids) == 0 {
		return out, nil
	}

	var rows []struct {
		ID  uint
		Ten string
	}
	err := r.db.WithContext(ctx).Table(bang).
		Select("id, "+cotTen+" AS ten").
		Where("id IN ?", ids).
		Scan(&rows).Error
	if err != nil {
		return nil, err
	}
	for _, row := range rows {
		out[row.ID] = row.Ten
	}

	return out, nil
}

// ---------- Một phiếu ----------

func (r *thuChiRepository) FindByID(ctx context.Context, id uint) (*domain.ThuChi, error) {
	var t domain.ThuChi
	err := r.db.WithContext(ctx).First(&t, id).Error
	if errors.Is(err, gorm.ErrRecordNotFound) {
		return nil, domain.ErrNotFound
	}
	if err != nil {
		return nil, err
	}

	// trangTri sửa TẠI CHỖ trên phần tử của slice, nên phải dựng slice trước rồi
	// trả về phần tử của chính nó — truyền `[]domain.ThuChi{t}` rồi trả `&t` là
	// trả về bản gốc chưa được điền tên.
	ds := []domain.ThuChi{t}
	if err := r.trangTri(ctx, ds); err != nil {
		return nil, err
	}

	return &ds[0], nil
}

// dongBoSoQuy dựng lại dòng sổ quỹ của phiếu cho khớp với chính phiếu.
//
// VÌ SAO PHẢI CÓ: két tiền của một ca cộng từ `cash_entries` (xem tongKetCa), nên
// một phiếu chi tiền mặt không có dòng ở đó là lúc đóng ca đếm thiếu đúng bằng số
// ấy mà không có gì trên màn hình nói vì sao.
//
// VÌ SAO KHÔNG PHẢI LÀ CÁI BẢNG QUỸ CỦA v2 LÀM LẠI: dòng ở đây là bản DẪN XUẤT,
// mỗi lượt ghi phiếu dựng lại nguyên vẹn từ phiếu — gán thẳng chiều và số tiền,
// không cộng dồn, không trừ lùi. Bảng quỹ của v2 cộng/trừ DELTA nên sai một lần
// là sai vĩnh viễn; ở đây sai một lần thì lượt sửa kế tiếp nắn lại đúng.
//
// Chuyển khoản không đi qua két nên không có dòng nào; đổi từ tiền mặt sang
// chuyển khoản thì dòng cũ bị xoá.
func dongBoSoQuy(tx *gorm.DB, t *domain.ThuChi) error {
	var cu domain.SoQuy
	err := tx.Where("reference_type = ? AND reference_id = ?", domain.SoQuyTuThuChi, t.ID).
		First(&cu).Error
	coCu := err == nil
	if err != nil && !errors.Is(err, gorm.ErrRecordNotFound) {
		return err
	}

	if t.PaymentMethod != domain.ThuChiTienMat {
		if coCu {
			return tx.Delete(&domain.SoQuy{}, cu.ID).Error
		}

		return nil
	}

	huong := domain.SoQuyThu
	if t.Type == domain.ThuChiPhieuChi {
		huong = domain.SoQuyChi
	}
	// Lý do in ra sổ quỹ phải tự nói được nó từ đâu ra: người đối chiếu cuối ca
	// nhìn thấy một dòng tiền mà không biết phiếu nào thì phải đi tra ngược.
	lyDo := strings.TrimSpace(t.Code + " " + t.Note)

	if coCu {
		cu.Direction = huong
		cu.Amount = t.Amount
		cu.Reason = lyDo

		return tx.Model(&cu).Select("Direction", "Amount", "Reason").Updates(&cu).Error
	}

	return ghiSoQuy(tx, &domain.SoQuy{
		ShopID:        t.ShopID,
		Direction:     huong,
		Amount:        t.Amount,
		Reason:        lyDo,
		ReferenceType: domain.SoQuyTuThuChi,
		ReferenceID:   &t.ID,
		CreatedBy:     t.CreatedBy,
	})
}

// phuongThucThuChi đổi phương thức thanh toán của CHỨNG TỪ GỐC sang hai mã mà
// sổ thu chi biết.
//
// Đơn hàng có nhiều phương thức hơn (ví, cổng thanh toán…), nhưng sổ thu chi chỉ
// phân biệt "tiền có qua két hay không" — mọi thứ không phải tiền mặt đều là
// chuyển khoản với nó. Gộp ở MỘT chỗ chứ không rải `if` ở từng luồng: thêm một
// phương thức mới mà quên một chỗ là dòng tiền ấy vào sổ với mã rỗng.
func phuongThucThuChi(cua string) string {
	if cua == domain.PaymentMethodCash {
		return domain.ThuChiTienMat
	}

	return domain.ThuChiChuyenKhoan
}

// GhiThuChiTuSinh ghi MỘT phiếu thu/chi do CHỨNG TỪ KHÁC đẻ ra, trong đúng
// transaction của chứng từ ấy.
//
// Đây là bản v2 làm bằng hook `created` của model: mỗi đơn bán, mỗi lượt trả
// tiền nhà cung cấp, mỗi lượt trả hàng đều để lại một dòng trong sổ thu chi, nên
// mở sổ ra là thấy đủ tiền vào ra chứ không phải đi ghép từ bốn màn khác nhau.
//
// KHÁC hàm Create ở đúng một chỗ, và chỗ ấy quan trọng: KHÔNG đụng sổ quỹ.
// Luồng gốc (bán hàng, đổi hàng…) đã tự ghi `cash_entries` của nó rồi; ghi thêm
// ở đây là MỘT khoản tiền đếm hai lần trong két, và đóng ca lệch đúng bằng
// doanh thu tiền mặt của ca.
//
// Mã phiếu vẫn cấp theo quy tắc đánh số như phiếu tay — cùng dải PT/PC, để đọc
// sổ không phải phân biệt dòng nào máy ghi dòng nào người ghi.
//
// Nơi gọi chỉ cần đặt: ShopID, Type, Amount, PaymentMethod, Source, SourceID,
// Note, CreatedBy. Phần còn lại hàm này lo.
func GhiThuChiTuSinh(ctx context.Context, tx *gorm.DB, t *domain.ThuChi) error {
	// Không có tiền thì không có phiếu: một dòng 0 đồng chẳng nói lên điều gì mà
	// vẫn làm dài sổ, và người đối chiếu phải đọc qua nó.
	if t.Amount <= 0 {
		return nil
	}
	if t.ShopID == 0 {
		// Lỗi LẬP TRÌNH: nơi gọi chưa biết chi nhánh mà đã ghi sổ. Hỏng ồn ào ở
		// đây tốt hơn một dòng tiền rơi vào chi nhánh do database tự đoán.
		return errors.New("ghi phiếu thu chi mà chưa biết chi nhánh nào")
	}

	docType := domain.LoaiPhieuThu
	if t.Type == domain.ThuChiPhieuChi {
		docType = domain.LoaiPhieuChi
	}
	ma, err := SinhMaTrongTx(ctx, tx, docType, t.ShopID, nil)
	if err != nil {
		return err
	}
	t.Code = ma

	// Ca trực lúc phát sinh — chốt ngay, giống phiếu tay. Đó là thứ khoá sửa/xoá
	// theo ca đã đóng dựa vào; mà phiếu tự sinh thì vốn đã khoá sẵn.
	var ca domain.CaLamViec
	switch err := tx.WithContext(ctx).
		Where("shop_id = ? AND closed_at IS NULL", t.ShopID).
		Order("id DESC").First(&ca).Error; {
	case err == nil:
		id := ca.ID
		t.ShiftID = &id
	case errors.Is(err, gorm.ErrRecordNotFound):
		t.ShiftID = nil
	default:
		return err
	}

	if t.PaymentMethod == "" {
		t.PaymentMethod = domain.ThuChiTienMat
	}

	return translateThuChiErr(tx.WithContext(ctx).Create(t).Error)
}

// Create ghi phiếu VÀ dòng sổ quỹ của nó trong cùng một transaction: hai thứ ấy
// là một sự việc, ghi được cái này mà hụt cái kia là két lệch ngay từ dòng đầu.
func (r *thuChiRepository) Create(ctx context.Context, t *domain.ThuChi) error {
	return r.db.WithContext(ctx).Transaction(func(tx *gorm.DB) error {
		if err := translateThuChiErr(tx.Create(t).Error); err != nil {
			return err
		}

		return dongBoSoQuy(tx, t)
	})
}

// Update đọc phiếu dưới khoá dòng rồi ghi đúng những cột `mutate` trả về.
//
// Khoá dòng chứ không đọc-rồi-ghi trần: hai người cùng sửa một phiếu thì người
// sau phải nhìn thấy trạng thái người trước vừa ghi, nhất là khi luật khoá
// (ca đã đóng, người lập) được xét lại ngay trong `mutate`.
func (r *thuChiRepository) Update(
	ctx context.Context, id uint, mutate func(t *domain.ThuChi) ([]string, error),
) (*domain.ThuChi, error) {
	var ra *domain.ThuChi

	err := r.db.WithContext(ctx).Transaction(func(tx *gorm.DB) error {
		var t domain.ThuChi
		err := tx.Clauses(clause.Locking{Strength: "UPDATE"}).First(&t, id).Error
		if errors.Is(err, gorm.ErrRecordNotFound) {
			return domain.ErrNotFound
		}
		if err != nil {
			return err
		}

		cot, err := mutate(&t)
		if err != nil {
			return err
		}
		if len(cot) > 0 {
			if err := tx.Model(&t).Select(cot).Updates(&t).Error; err != nil {
				return translateThuChiErr(err)
			}
			if err := dongBoSoQuy(tx, &t); err != nil {
				return err
			}
		}

		ra = &t

		return nil
	})
	if err != nil {
		return nil, err
	}

	ds := []domain.ThuChi{*ra}
	if err := r.trangTri(ctx, ds); err != nil {
		return nil, err
	}

	return &ds[0], nil
}

// Delete xoá mềm phiếu, và xoá HẲN dòng sổ quỹ dẫn xuất của nó.
//
// Hai cách xoá khác nhau là có chủ ý: phiếu giữ lại để tra lịch sử, còn dòng sổ
// quỹ thì không được giữ — nó là tiền đang nằm trong két của ca, để lại là đóng
// ca xong đếm thừa đúng bằng số vừa xoá. Phiếu của ca ĐÃ ĐÓNG không tới được
// đây: lớp khoá chặn từ tầng service.
func (r *thuChiRepository) Delete(ctx context.Context, id uint) error {
	return r.db.WithContext(ctx).Transaction(func(tx *gorm.DB) error {
		err := tx.Where("reference_type = ? AND reference_id = ?", domain.SoQuyTuThuChi, id).
			Delete(&domain.SoQuy{}).Error
		if err != nil {
			return err
		}

		return translateThuChiErr(tx.Delete(&domain.ThuChi{}, id).Error)
	})
}

// ---------- Chi nhánh & ca trực ----------

func (r *thuChiRepository) ChiNhanhMacDinh(ctx context.Context) (uint, error) {
	return chiNhanhCuaRequest(ctx, r.db)
}

// CaDangMo tra ca còn đang trực tại chi nhánh. Không có ca nào thì trả nil chứ
// không báo lỗi — xem chú thích ở ThuChi.ShiftID.
func (r *thuChiRepository) CaDangMo(ctx context.Context, shopID uint) (*uint, error) {
	if shopID == 0 {
		return nil, nil
	}

	var ca domain.CaLamViec
	err := r.db.WithContext(ctx).
		Where("shop_id = ? AND closed_at IS NULL", shopID).
		Order("id DESC").First(&ca).Error
	switch {
	case err == nil:
		id := ca.ID

		return &id, nil
	case errors.Is(err, gorm.ErrRecordNotFound):
		return nil, nil
	default:
		return nil, err
	}
}

// CaDaDong: ca không tra ra được cũng trả false. Khoá một phiếu vì một ca đã
// biến mất là khoá nhầm — người dùng mất đường sửa mà không có lý do nào đúng.
func (r *thuChiRepository) CaDaDong(ctx context.Context, shiftID uint) (bool, error) {
	if shiftID == 0 {
		return false, nil
	}

	var ca domain.CaLamViec
	err := r.db.WithContext(ctx).First(&ca, shiftID).Error
	switch {
	case err == nil:
		return !ca.DangMo(), nil
	case errors.Is(err, gorm.ErrRecordNotFound):
		return false, nil
	default:
		return false, err
	}
}

// ---------- Người nộp ----------

func (r *thuChiRepository) ListNguoiNop(ctx context.Context, keyword string) ([]domain.NguoiNopThuChi, error) {
	q := r.db.WithContext(ctx).Order("name ASC")
	if kw := strings.TrimSpace(keyword); kw != "" {
		q = q.Where("name LIKE ? OR phone LIKE ?", "%"+kw+"%", "%"+kw+"%")
	}

	var list []domain.NguoiNopThuChi
	if err := q.Find(&list).Error; err != nil {
		return nil, err
	}

	return list, nil
}

// TonTaiNguoiNopTen so bằng LOWER(...) COLLATE utf8mb4_bin, cùng luật với loại
// thu chi: đối chiếu mặc định của cột bỏ qua CẢ hoa thường LẪN dấu nên "Hà" và
// "Ha" bị coi là một người.
//
// KHÔNG Unscoped: tên của người đã xoá không giữ chỗ.
func (r *thuChiRepository) TonTaiNguoiNopTen(ctx context.Context, name string) (bool, error) {
	var count int64
	err := r.db.WithContext(ctx).Model(&domain.NguoiNopThuChi{}).
		Where("LOWER(name) COLLATE utf8mb4_bin = LOWER(?)", name).
		Count(&count).Error

	return count > 0, err
}

func (r *thuChiRepository) CreateNguoiNop(ctx context.Context, n *domain.NguoiNopThuChi) error {
	return translateThuChiErr(r.db.WithContext(ctx).Create(n).Error)
}

func translateThuChiErr(err error) error {
	switch {
	case err == nil:
		return nil
	case errors.Is(err, gorm.ErrDuplicatedKey):
		return domain.ErrConflict
	case errors.Is(err, gorm.ErrForeignKeyViolated):
		return domain.ErrConflict
	default:
		return err
	}
}
