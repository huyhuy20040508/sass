package repository

import (
	"context"
	"slices"
	"strings"
	"time"

	"gorm.io/gorm"

	"sass-api/internal/domain"
)

type congNoRepository struct{ db *gorm.DB }

func NewCongNoRepository(db *gorm.DB) domain.CongNoRepository {
	return &congNoRepository{db: db}
}

// trangThaiNo gạn chuỗi "partial,unpaid" thành danh sách trạng thái hợp lệ.
// Giá trị lạ bị bỏ; gạn xong rỗng = coi như không lọc.
func trangThaiNo(v string) []string {
	v = strings.TrimSpace(v)
	if v == "" {
		return nil
	}

	out := make([]string, 0, 3)
	for _, phan := range strings.Split(v, ",") {
		switch p := strings.TrimSpace(phan); p {
		case domain.CongNoChuaTra, domain.CongNoMotPhan, domain.CongNoDaTra:
			if !slices.Contains(out, p) {
				out = append(out, p)
			}
		}
	}

	return out
}

// veTrangThai dịch một trạng thái thành mệnh đề SQL trên hai cột tiền.
//
// Cùng biên 1 phần trăm đồng với domain.TinhTrangNo — hai chỗ mà lệch nhau thì
// một phiếu lọt vào bộ lọc "chưa trả" rồi hiện chữ "đã thanh toán".
func veTrangThai(t string) string {
	switch t {
	case domain.CongNoDaTra:
		return "paid_amount >= total_amount - 0.005"
	case domain.CongNoChuaTra:
		return "paid_amount <= 0.005"
	case domain.CongNoMotPhan:
		return "paid_amount > 0.005 AND paid_amount < total_amount - 0.005"
	}

	return ""
}

// nen dựng phần WHERE chung cho MỌI lượt đọc của màn công nợ.
//
// Hai điều kiện cứng, không bộ lọc nào tắt được:
//
//   - `status = approved`: phiếu lưu tạm chưa vào kho và chưa ai nợ ai. v2 không
//     lọc trạng thái phiếu nên một phiếu nháp bỏ dở vẫn nằm trong sổ nợ.
//   - `is_debt = 1`: chỉ khoản HAI BÊN ĐÃ THOẢ THUẬN cho nợ mới phải đi đòi.
//     Trả thiếu vì mới trả một phần thì còn thiếu thật, nhưng chưa hẹn ngày nào
//     cả — v2 gộp cả hai nên sổ nợ đầy phiếu không có hạn, và cột "Hạn còn lại"
//     bên đó in ra ngày 01/01/1970.
func (r *congNoRepository) nen(ctx context.Context, f domain.CongNoFilter) *gorm.DB {
	q := r.db.WithContext(ctx).Model(&domain.PurchaseOrder{}).
		Where("status = ?", domain.PurchaseStatusApproved).
		Where("is_debt = ?", true)

	if f.ShopID > 0 {
		q = q.Where("shop_id = ?", f.ShopID)
	}
	// Hai ô tìm RIÊNG, nối bằng VÀ: gõ cả hai là thu hẹp dần, đúng cách hai ô
	// cạnh nhau của v2 hoạt động.
	if v := strings.TrimSpace(f.SupplierName); v != "" {
		q = q.Where("supplier_name LIKE ?", "%"+v+"%")
	}
	if v := strings.TrimSpace(f.Code); v != "" {
		q = q.Where("po_code LIKE ?", "%"+v+"%")
	}
	if ids := idTuChuoi(f.SupplierID); len(ids) > 0 {
		q = q.Where("supplier_id IN ?", ids)
	}
	if ids := idTuChuoi(f.CreatedBy); len(ids) > 0 {
		q = q.Where("created_by IN ?", ids)
	}

	// Trạng thái là ô chọn NHIỀU: chọn hai mục phải ra hợp của hai tập, nên nối
	// bằng OR trong một cặp ngoặc chứ không chồng Where.
	if ds := trangThaiNo(f.Status); len(ds) > 0 {
		ve := make([]string, 0, len(ds))
		for _, t := range ds {
			if s := veTrangThai(t); s != "" {
				ve = append(ve, "("+s+")")
			}
		}
		if len(ve) > 0 {
			q = q.Where(strings.Join(ve, " OR "))
		}
	}

	return q
}

// theoHan áp mốc hạn lên câu đã dựng sẵn.
//
// Cả ba mốc đều kèm "còn nợ thật" (`paid < total`): một phiếu đã trả xong hôm
// qua thì không còn là việc phải làm hôm nay, dù hạn của nó rơi đúng hôm nay.
// v2 chỉ gắn điều kiện ấy cho mốc "Quá hạn" mà quên hai mốc còn lại, nên nút
// "Đến hạn hôm nay" đếm cả phiếu đã tất toán.
func theoHan(q *gorm.DB, moc string, homNay, hanGan string) *gorm.DB {
	conNo := "paid_amount < total_amount - 0.005"

	switch moc {
	case domain.CongNoHanGan:
		return q.Where(conNo).
			Where("debt_due_date >= ? AND debt_due_date <= ?", homNay, hanGan)
	case domain.CongNoHanQua:
		return q.Where(conNo).Where("debt_due_date < ?", homNay)
	case domain.CongNoHanHomNay:
		return q.Where(conNo).Where("debt_due_date = ?", homNay)
	}

	return q
}

func (r *congNoRepository) List(ctx context.Context, f domain.CongNoFilter) ([]domain.CongNo, int64, domain.CongNoTongKet, error) {
	var tk domain.CongNoTongKet

	soNgay := f.SoNgayGan
	if soNgay <= 0 {
		soNgay = domain.CongNoSoNgayGan
	}
	now := time.Now()
	homNay := now.Format("2006-01-02")
	hanGan := now.AddDate(0, 0, soNgay).Format("2006-01-02")

	// BỐN CON SỐ TRƯỚC, và tính trên câu CHƯA áp mốc hạn.
	//
	// Bốn nút là để so với nhau — "tất cả 57, quá hạn 12". Nếu chúng đổi theo
	// mốc đang bấm thì bấm sang "Quá hạn" xong nút "Tất cả" cũng ghi 12, không
	// còn nói lên điều gì. Nhưng chúng PHẢI theo các bộ lọc còn lại: lọc riêng
	// một nhà cung cấp mà bốn con số vẫn của cả cửa hàng thì càng sai hơn — đó
	// đúng là chỗ v2 hỏng, bên đó clone câu truy vấn trước cả bộ lọc trạng thái.
	if err := r.demBonMoc(ctx, f, homNay, hanGan, &tk); err != nil {
		return nil, 0, tk, err
	}

	// Dựng lại câu cho mỗi lượt đọc thay vì tái dùng một *gorm.DB: mỗi lượt gọi
	// tận cùng (Count / Scan / Find) đều ghi thêm vào Statement của builder, nên
	// dùng lại một builder là mang theo Select của lượt trước.
	moc := strings.TrimSpace(f.Due)
	cau := func() *gorm.DB { return theoHan(r.nen(ctx, f), moc, homNay, hanGan) }

	var tong int64
	if err := cau().Count(&tong).Error; err != nil {
		return nil, 0, tk, err
	}

	// Tiền còn nợ của CẢ bộ lọc — cộng ở CSDL, không cộng trên trang đang xem.
	// Cộng 10 dòng của trang 1 rồi ghi là "tổng còn nợ" thì con số ấy đổi mỗi
	// lần lật trang.
	if err := cau().
		Select("COALESCE(SUM(total_amount - paid_amount), 0)").
		Scan(&tk.ConNo).Error; err != nil {
		return nil, 0, tk, err
	}

	var po []domain.PurchaseOrder
	err := cau().
		// Quá hạn lâu nhất lên trước, phiếu không có hạn xuống cuối: mở màn ra
		// là thấy ngay khoản phải gọi điện hôm nay. v2 xếp theo `id DESC` nên
		// khoản nợ cũ nhất — cái đáng lo nhất — nằm ở trang cuối.
		Order("debt_due_date IS NULL ASC, debt_due_date ASC, id DESC").
		Limit(f.PageSize).Offset((f.Page - 1) * f.PageSize).
		Find(&po).Error
	if err != nil {
		return nil, 0, tk, err
	}

	list := make([]domain.CongNo, len(po))
	for i, p := range po {
		list[i] = domain.CongNo{
			ID:           p.ID,
			Code:         p.POCode,
			ShopID:       p.ShopID,
			SupplierID:   p.SupplierID,
			SupplierName: p.SupplierName,
			TotalAmount:  p.TotalAmount,
			PaidAmount:   p.PaidAmount,
			Remaining:    p.TotalAmount - p.PaidAmount,
			DueDate:      p.DebtDueDate,
			ContactName:  p.DebtContactName,
			ContactPhone: p.DebtContactPhone,
			Status:       domain.TinhTrangNo(p.TotalAmount, p.PaidAmount),
			DaysLeft:     domain.SoNgayConLai(p.DebtDueDate, now),
			CreatedBy:    p.CreatedBy,
			CreatedAt:    p.CreatedAt,
		}
	}

	return list, tong, tk, r.napTen(ctx, list)
}

// demBonMoc lấy cả bốn con số trong MỘT lượt đọc.
//
// Bốn câu COUNT riêng thì giữa chúng có thể có một lượt trả tiền vừa được ghi,
// và hàng nút in ra "tất cả 57" cạnh "quá hạn 12" của hai thời điểm khác nhau —
// cộng lại không khớp mà không ai giải thích được. v2 chạy đúng bốn câu.
func (r *congNoRepository) demBonMoc(ctx context.Context, f domain.CongNoFilter, homNay, hanGan string, tk *domain.CongNoTongKet) error {
	conNo := "paid_amount < total_amount - 0.005"

	// COALESCE quanh cả ba SUM: không dòng nào khớp thì SUM trả về NULL, và
	// quét NULL vào int64 là một lỗi driver chứ không phải con số 0 — sổ chưa có
	// khoản nợ nào sẽ làm cả trang gãy thay vì hiện bảng rỗng.
	return r.nen(ctx, f).Select(
		"COUNT(*) AS tat_ca,"+
			" COALESCE(SUM(CASE WHEN "+conNo+" AND debt_due_date >= ? AND debt_due_date <= ? THEN 1 ELSE 0 END), 0) AS gan,"+
			" COALESCE(SUM(CASE WHEN "+conNo+" AND debt_due_date < ? THEN 1 ELSE 0 END), 0) AS qua,"+
			" COALESCE(SUM(CASE WHEN "+conNo+" AND debt_due_date = ? THEN 1 ELSE 0 END), 0) AS hom_nay",
		homNay, hanGan, homNay, homNay,
	).Scan(tk).Error
}

// napTen tra tên chi nhánh và tên người lập cho cả trang, mỗi thứ một câu.
//
// Unscoped cả hai: chi nhánh có thể đã đóng và người lập có thể đã nghỉ việc,
// khoản nợ họ để lại thì vẫn phải đòi và vẫn phải in ra được tên.
func (r *congNoRepository) napTen(ctx context.Context, list []domain.CongNo) error {
	nguoiIDs := make([]uint, 0, len(list))
	cnIDs := make([]uint, 0, len(list))
	for _, c := range list {
		if c.CreatedBy != nil && *c.CreatedBy > 0 && !slices.Contains(nguoiIDs, *c.CreatedBy) {
			nguoiIDs = append(nguoiIDs, *c.CreatedBy)
		}
		if c.ShopID > 0 && !slices.Contains(cnIDs, c.ShopID) {
			cnIDs = append(cnIDs, c.ShopID)
		}
	}

	type dong struct {
		ID   uint
		Name string
	}

	tenNguoi := make(map[uint]string, len(nguoiIDs))
	if len(nguoiIDs) > 0 {
		var rows []dong
		if err := r.db.WithContext(ctx).Unscoped().Table("users").
			Select("id, COALESCE(full_name, '') AS name").
			Where("id IN ?", nguoiIDs).Scan(&rows).Error; err != nil {
			return err
		}
		for _, n := range rows {
			tenNguoi[n.ID] = n.Name
		}
	}

	tenCN := make(map[uint]string, len(cnIDs))
	if len(cnIDs) > 0 {
		var rows []dong
		if err := r.db.WithContext(ctx).Unscoped().Table("shops").
			Select("id, COALESCE(name, '') AS name").
			Where("id IN ?", cnIDs).Scan(&rows).Error; err != nil {
			return err
		}
		for _, n := range rows {
			tenCN[n.ID] = n.Name
		}
	}

	for i := range list {
		if list[i].CreatedBy != nil {
			list[i].CreatedByName = tenNguoi[*list[i].CreatedBy]
		}
		list[i].BranchName = tenCN[list[i].ShopID]
	}

	return nil
}

func (r *congNoRepository) LichSuTra(ctx context.Context, purchaseOrderID uint) ([]domain.PurchasePayment, error) {
	var ds []domain.PurchasePayment
	if err := r.db.WithContext(ctx).
		Where("purchase_order_id = ?", purchaseOrderID).
		Order("id ASC").Find(&ds).Error; err != nil {
		return nil, err
	}

	ids := make([]uint, 0, len(ds))
	for _, p := range ds {
		if p.CreatedBy != nil && *p.CreatedBy > 0 && !slices.Contains(ids, *p.CreatedBy) {
			ids = append(ids, *p.CreatedBy)
		}
	}
	if len(ids) == 0 {
		return ds, nil
	}

	var rows []struct {
		ID       uint
		FullName string
	}
	if err := r.db.WithContext(ctx).Unscoped().Table("users").
		Select("id, COALESCE(full_name, '') AS full_name").
		Where("id IN ?", ids).Scan(&rows).Error; err != nil {
		return nil, err
	}

	ten := make(map[uint]string, len(rows))
	for _, n := range rows {
		ten[n.ID] = n.FullName
	}
	for i := range ds {
		if ds[i].CreatedBy != nil {
			ds[i].CreatedByName = ten[*ds[i].CreatedBy]
		}
	}

	return ds, nil
}
