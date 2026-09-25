package service

import (
	"context"
	"fmt"
	"math"
	"strings"
	"time"

	"sass-api/internal/domain"
	"sass-api/internal/dto"
)

// PhieuMuaHangDetail là phiếu kèm lịch sử và các thao tác hợp lệ kế tiếp.
type PhieuMuaHangDetail struct {
	*domain.PurchaseOrder
	Histories []domain.PurchaseOrderHistory `json:"histories"`
	// Payments là sổ TỪNG LƯỢT trả tiền — `paid_amount` chỉ nói tổng, sổ này nói
	// tổng ấy tới từ mấy lượt, mỗi lượt bao nhiêu và bằng hình thức gì.
	Payments []domain.PurchasePayment `json:"payments"`
	// CanEdit = phiếu còn sửa/xoá được (chỉ phiếu lưu tạm).
	CanEdit bool `json:"can_edit"`
	// CanApprove = phiếu còn duyệt được. Trang quản trị dựng nút từ hai cờ này
	// thay vì chép lại luật vào giao diện rồi lệch với server.
	CanApprove bool `json:"can_approve"`
	// CanPay = phiếu đã duyệt và CHƯA trả đồng nào. Trả một phần rồi thì khoản
	// nợ ấy thuộc về màn công nợ, không trả tiếp từ màn phiếu mua nữa.
	CanPay bool `json:"can_pay"`
}

type PhieuMuaHangService interface {
	List(ctx context.Context, f domain.PurchaseFilter) ([]domain.PurchaseOrder, int64, error)
	GetByID(ctx context.Context, id uint) (*PhieuMuaHangDetail, error)
	Stats(ctx context.Context) (domain.PurchaseStats, error)
	SearchVariants(ctx context.Context, keyword string, categoryID uint, limit int) ([]domain.PurchaseVariant, error)
	NhomHangCoHang(ctx context.Context) ([]domain.PurchaseNhomHang, error)

	Create(ctx context.Context, req *dto.PurchaseCreateRequest, actorID uint) (*PhieuMuaHangDetail, error)
	Update(ctx context.Context, id uint, req *dto.PurchaseUpdateRequest, actorID uint) (*PhieuMuaHangDetail, error)
	// Approve duyệt phiếu: hàng vào kho, phiếu khoá lại.
	Approve(ctx context.Context, id uint, req *dto.PurchaseApproveRequest, actorID uint) (*PhieuMuaHangDetail, error)
	Cancel(ctx context.Context, id uint, req *dto.PurchaseCancelRequest, actorID uint) (*PhieuMuaHangDetail, error)
	Pay(ctx context.Context, id uint, req *dto.PurchasePaymentRequest, actorID uint) (*PhieuMuaHangDetail, error)
	Delete(ctx context.Context, id uint) error
}

type phieuMuaHangService struct {
	repo domain.PurchaseOrderRepository
	// nccRepo chỉ để tra TÊN bên bán khi client gửi mỗi id — xem tenBenBan.
	nccRepo domain.NhaCungCapRepository
}

func NewPhieuMuaHangService(repo domain.PurchaseOrderRepository, nccRepo domain.NhaCungCapRepository) PhieuMuaHangService {
	return &phieuMuaHangService{repo: repo, nccRepo: nccRepo}
}

// tenBenBan chốt TÊN ghi xuống chứng từ.
//
// Client gõ tay thì lấy đúng chữ họ gõ (phiếu cho bên bán vãng lai). Bỏ trống
// mà có chọn nhà cung cấp thì tra tên trong danh mục — đây là bản CHỤP, chốt
// một lần lúc lập phiếu, đổi tên trong danh mục sau này không sửa phiếu cũ.
//
// Tra hỏng thì để trống chứ không chặn cả lượt lập phiếu: cái tên chỉ để in ra,
// còn con trỏ supplier_id mới là thứ gom số liệu.
func (s *phieuMuaHangService) tenBenBan(ctx context.Context, ten string, supplierID uint) string {
	if ten = strings.TrimSpace(ten); ten != "" || supplierID == 0 {
		return ten
	}

	ncc, err := s.nccRepo.FindByID(ctx, supplierID)
	if err != nil || ncc == nil {
		return ""
	}

	return ncc.Name
}

func (s *phieuMuaHangService) List(ctx context.Context, f domain.PurchaseFilter) ([]domain.PurchaseOrder, int64, error) {
	return s.repo.List(ctx, f)
}

func (s *phieuMuaHangService) Stats(ctx context.Context) (domain.PurchaseStats, error) {
	return s.repo.Stats(ctx)
}

func (s *phieuMuaHangService) SearchVariants(ctx context.Context, keyword string, categoryID uint, limit int) ([]domain.PurchaseVariant, error) {
	return s.repo.SearchVariants(ctx, keyword, categoryID, limit)
}

func (s *phieuMuaHangService) NhomHangCoHang(ctx context.Context) ([]domain.PurchaseNhomHang, error) {
	return s.repo.NhomHangCoHang(ctx)
}

func (s *phieuMuaHangService) GetByID(ctx context.Context, id uint) (*PhieuMuaHangDetail, error) {
	po, err := s.repo.FindByID(ctx, id)
	if err != nil {
		return nil, err
	}

	return s.detail(ctx, po)
}

func (s *phieuMuaHangService) detail(ctx context.Context, po *domain.PurchaseOrder) (*PhieuMuaHangDetail, error) {
	his, err := s.repo.Histories(ctx, po.ID)
	if err != nil {
		return nil, err
	}
	tra, err := s.repo.Payments(ctx, po.ID)
	if err != nil {
		return nil, err
	}

	nhap := po.Status == domain.PurchaseStatusDraft

	return &PhieuMuaHangDetail{
		PurchaseOrder: po,
		Histories:     his,
		Payments:      tra,
		CanEdit:       nhap,
		CanApprove:    nhap && len(po.Items) > 0,
		// Trả tiền ĐÚNG MỘT LƯỢT từ màn này, và chỉ khi phiếu chưa trả đồng nào.
		//
		// Phiếu đã trả một phần tức là hai bên đã chốt một khoản nợ có hạn và có
		// người đứng tên. Lượt trả tiếp theo là việc của màn CÔNG NỢ — ở đó mới
		// thấy được cả bức tranh nợ của nhà cung cấp, mới ghi được sổ thu chi và
		// mới đối chiếu được nhiều phiếu một lượt. Mở đường trả tiếp ở đây thì
		// hai chỗ cùng sửa một khoản nợ mà không chỗ nào biết chỗ kia.
		CanPay: po.Status == domain.PurchaseStatusApproved &&
			po.PaymentStatus == domain.PurchasePaymentUnpaid,
	}, nil
}

// ---------- Dựng dòng hàng ----------

// tienPhieu là bộ số tiền của một phiếu sau khi cộng hết các dòng.
type tienPhieu struct {
	Items float64
	VAT   float64
	Total float64
}

// dungDongHang chụp lại thông tin mặt hàng cho các dòng client gửi lên, quy đổi
// đơn vị và tính tiền.
//
// Tên/SKU/ảnh/hệ số quy đổi lấy từ DB chứ không nhận từ client: phiếu in ra và
// bút toán kho phải nói về đúng một món hàng, với đúng một hệ số. Giá nhập thì
// ngược lại — do người mua thoả thuận với bên bán nên nhận từ client, màn hình
// gợi ý sẵn theo giá vốn đang khai.
func dungDongHang(
	items []dto.PurchaseItemRequest,
	found map[uint]domain.PurchaseVariant,
	vatMode string,
	vatPhieu int,
) ([]domain.PurchaseOrderItem, tienPhieu, error) {
	var tien tienPhieu

	// Gộp dòng trùng theo BỘ BA (mặt hàng, đơn vị mua, số lô): chọn hai lần
	// cùng một món cùng đơn vị cùng lô thì cộng số lượng, giá lấy theo lần khai
	// sau. Không gộp theo riêng mặt hàng — mua 1 thùng và 5 cái, hay hai lô khác
	// nhau, đều là những dòng có thật trên hoá đơn bên bán; gộp lại là phiếu in
	// ra không khớp chứng từ gốc.
	type khoa struct {
		VariantID uint
		UnitID    uint
		LotNumber string
	}
	gop := make(map[khoa]dto.PurchaseItemRequest, len(items))
	thuTu := make([]khoa, 0, len(items))

	for _, it := range items {
		if it.Quantity <= 0 {
			continue
		}
		k := khoa{VariantID: it.VariantID, UnitID: it.UnitID, LotNumber: strings.TrimSpace(it.LotNumber)}
		truoc, daCo := gop[k]
		if !daCo {
			thuTu = append(thuTu, k)
			gop[k] = it

			continue
		}
		truoc.Quantity += it.Quantity
		truoc.UnitCost = it.UnitCost
		truoc.VATPercent = it.VATPercent
		truoc.ExpireDate = it.ExpireDate
		gop[k] = truoc
	}
	if len(thuTu) == 0 {
		return nil, tien, domain.ErrPurchaseEmpty
	}

	lines := make([]domain.PurchaseOrderItem, 0, len(thuTu))
	for _, k := range thuTu {
		src, ok := found[k.VariantID]
		if !ok {
			return nil, tien, domain.ErrVariantNotFound
		}
		req := gop[k]

		dv, err := timDonVi(src, k.UnitID)
		if err != nil {
			return nil, tien, err
		}

		// Quy đổi phải ra số NGUYÊN: sổ kho đếm nguyên nên không có chỗ ghi phần
		// lẻ, và tự làm tròn thì mỗi phiếu lệch một ít, vài tháng sau không ai dò
		// ra chỗ hụt.
		base := float64(req.Quantity) * dv.Ratio
		if math.Abs(base-math.Round(base)) > 0.0001 {
			return nil, tien, domain.ErrPurchaseUnitRatio
		}

		vat := vatDong(vatMode, vatPhieu, req.VATPercent, src.VATPercent)

		lineAmount := lamTron(req.UnitCost * float64(req.Quantity))
		vatAmount := lamTron(lineAmount * float64(vatDuong(vat)) / 100)

		tien.Items += lineAmount
		tien.VAT += vatAmount

		productID := src.ProductID
		variantID := src.VariantID
		var unitID *uint
		if dv.UnitID > 0 {
			id := dv.UnitID
			unitID = &id
		}

		lines = append(lines, domain.PurchaseOrderItem{
			ProductID:        &productID,
			ProductVariantID: &variantID,
			ProductName:      src.ProductName,
			VariantSKU:       src.SKU,
			VariantName:      src.VariantName,
			Thumbnail:        src.Thumbnail,
			UnitID:           unitID,
			UnitName:         dv.Name,
			UnitRatio:        dv.Ratio,
			LotNumber:        k.LotNumber,
			ExpireDate:       ngayChungTu(req.ExpireDate),
			Quantity:         req.Quantity,
			BaseQuantity:     int(math.Round(base)),
			UnitCost:         req.UnitCost,
			VATPercent:       vat,
			LineAmount:       lineAmount,
			VATAmount:        vatAmount,
			TotalCost:        lineAmount + vatAmount,
		})
	}

	return lines, tien, nil
}

// timDonVi tra đơn vị MUA trong danh sách đơn vị mua được của mặt hàng.
//
// unitID = 0 hoặc trùng đơn vị chính = mua theo đơn vị tính chính, hệ số 1.
func timDonVi(src domain.PurchaseVariant, unitID uint) (domain.PurchaseUnit, error) {
	if unitID == 0 {
		return domain.PurchaseUnit{UnitID: src.BaseUnitID, Name: src.BaseUnitName, Ratio: 1}, nil
	}
	for _, u := range src.Units {
		if u.UnitID == unitID && u.Ratio > 0 {
			return u, nil
		}
	}

	// Đơn vị không nằm trong khối quy đổi của mặt hàng: từ chối thay vì đoán hệ
	// số 1. Đoán ở đây là mua một thùng mà kho cộng một cái.
	return domain.PurchaseUnit{}, domain.ErrPurchaseUnitLa
}

// vatDong chọn thuế suất cho một dòng hàng.
//
// Khai theo phiếu thì mọi dòng chung một mức. Khai theo dòng thì lấy số client
// gửi; client BỎ TRỐNG hẳn thì rơi về thuế suất của chính mặt hàng — đó là con
// số kế toán đã khai sẵn, và bắt người lập phiếu gõ lại từng dòng chỉ tạo thêm
// chỗ để sai.
//
// Gửi số 0 KHÁC bỏ trống: đó là khai một dòng không chịu thuế.
func vatDong(mode string, vatPhieu int, vatClient *int, vatHang int) int {
	if mode != domain.VATModeGoods {
		return vatPhieu
	}
	if vatClient != nil {
		return *vatClient
	}

	return vatHang
}

// vatDuong đổi mã thuế đặc biệt thành 0 để tính tiền.
//
// products.vat dùng số âm cho hai trường hợp không có thuế suất: -1 = KCT (không
// chịu thuế), -2 = KKKNT (không kê khai nộp thuế). Cả hai đều cộng 0 đồng thuế,
// nhưng phải GIỮ NGUYÊN số âm trên dòng hàng vì hoá đơn in ra ghi đúng chữ đó.
func vatDuong(v int) int {
	if v < 0 {
		return 0
	}

	return v
}

// tongPhieu — tiền hàng trừ chiết khấu cộng thuế, không bao giờ âm.
//
// Thuế tính trên tiền hàng TRƯỚC chiết khấu, giữ đúng cách bản v2 tính: chiết
// khấu ở đây là khoản bên bán bớt trên tổng phiếu sau khi đã ra hoá đơn, không
// phải khoản làm giảm giá tính thuế của từng dòng.
func tongPhieu(t tienPhieu, discount float64) float64 {
	v := t.Items - discount + t.VAT
	if v < 0 {
		return 0
	}

	return v
}

// trangThaiTra suy tình trạng thanh toán từ số đã trả.
//
// So sánh có biên 1₫ để sai số dấu phẩy động không giữ phiếu ở "trả một phần"
// dù thực tế đã trả đủ.
func trangThaiTra(paid, total float64) string {
	switch {
	case paid <= 0:
		return domain.PurchasePaymentUnpaid
	case paid+1 >= total:
		return domain.PurchasePaymentPaid
	default:
		return domain.PurchasePaymentPartial
	}
}

// ngayChungTu đọc ngày dạng YYYY-MM-DD; rỗng = chưa khai.
func ngayChungTu(v string) *time.Time {
	v = strings.TrimSpace(v)
	if v == "" {
		return nil
	}
	d, err := time.Parse("2006-01-02", v)
	if err != nil {
		return nil
	}

	return &d
}

func conTro(v uint) *uint {
	if v == 0 {
		return nil
	}

	return &v
}

// traDonVi tra mặt hàng của mọi dòng trong MỘT lượt truy vấn rồi dựng dòng phiếu.
func (s *phieuMuaHangService) traDongHang(
	ctx context.Context, items []dto.PurchaseItemRequest, vatMode string, vatPhieu int,
) ([]domain.PurchaseOrderItem, tienPhieu, error) {
	ids := make([]uint, 0, len(items))
	for _, it := range items {
		ids = append(ids, it.VariantID)
	}

	found, err := s.repo.LookupVariants(ctx, ids)
	if err != nil {
		return nil, tienPhieu{}, err
	}

	return dungDongHang(items, found, vatMode, vatPhieu)
}

// ---------- Tạo & sửa ----------

func (s *phieuMuaHangService) Create(ctx context.Context, req *dto.PurchaseCreateRequest, actorID uint) (*PhieuMuaHangDetail, error) {
	vatMode := req.VATMode
	if vatMode == "" {
		vatMode = domain.VATModeOrder
	}

	lines, tien, err := s.traDongHang(ctx, req.Items, vatMode, req.VATPercent)
	if err != nil {
		return nil, err
	}

	total := tongPhieu(tien, req.DiscountAmount)
	if req.PaidAmount > total+1 {
		return nil, domain.ErrPurchasePaidQuaTong
	}

	po := &domain.PurchaseOrder{
		SupplierID:           conTro(req.SupplierID),
		SupplierName:         s.tenBenBan(ctx, req.SupplierName, req.SupplierID),
		Status:               domain.PurchaseStatusDraft,
		DocumentDate:         ngayChungTu(req.DocumentDate),
		ExpectedDate:         ngayChungTu(req.ExpectedDate),
		PurchaserID:          conTro(req.PurchaserID),
		SupplierDeliveryCode: strings.TrimSpace(req.SupplierDeliveryCode),
		VATMode:              vatMode,
		VATPercent:           req.VATPercent,
		ItemsAmount:          tien.Items,
		DiscountAmount:       req.DiscountAmount,
		VATAmount:            tien.VAT,
		TotalAmount:          total,
		PaidAmount:           req.PaidAmount,
		PaymentStatus:        trangThaiTra(req.PaidAmount, total),
		Note:                 strings.TrimSpace(req.Note),
		Attachment:           strings.TrimSpace(req.Attachment),
		Items:                lines,
	}
	if actorID > 0 {
		id := actorID
		po.CreatedBy = &id
		po.HandledBy = &id
	}

	if err := s.repo.Create(ctx, po); err != nil {
		return nil, err
	}

	created, err := s.repo.FindByID(ctx, po.ID)
	if err != nil {
		return nil, err
	}

	return s.detail(ctx, created)
}

func (s *phieuMuaHangService) Update(ctx context.Context, id uint, req *dto.PurchaseUpdateRequest, actorID uint) (*PhieuMuaHangDetail, error) {
	// Tra phiếu TRƯỚC khi soi danh sách hàng: phiếu không phải của cửa hàng này
	// thì câu trả lời đúng là 404, chứ không phải "sản phẩm không còn tồn tại" —
	// dòng hàng gửi lên cũng của cửa hàng kia nên nó hỏng trước, và lời báo lỗi
	// đó vừa sai vừa hé ra rằng có một phiếu mang id ấy ở đâu đó.
	if _, err := s.repo.FindByID(ctx, id); err != nil {
		return nil, err
	}

	vatMode := req.VATMode
	if vatMode == "" {
		vatMode = domain.VATModeOrder
	}

	lines, tien, err := s.traDongHang(ctx, req.Items, vatMode, req.VATPercent)
	if err != nil {
		return nil, err
	}

	total := tongPhieu(tien, req.DiscountAmount)
	if req.PaidAmount > total+1 {
		return nil, domain.ErrPurchasePaidQuaTong
	}

	// Tra tên TRƯỚC khi vào khoá dòng: gọi thêm một câu truy vấn khi đang giữ
	// khoá là giữ nó lâu hơn cần thiết.
	ten := s.tenBenBan(ctx, req.SupplierName, req.SupplierID)

	po, err := s.repo.Update(ctx, id, func(po *domain.PurchaseOrder) ([]string, []domain.PurchaseOrderItem, error) {
		// Chỉ phiếu LƯU TẠM sửa được. Phiếu đã duyệt là kho đã đổi theo nó; sửa
		// tiếp thì sổ kho nói một đằng, phiếu nói một nẻo — và đó đúng là chỗ bản
		// v2 cộng kho lặp mỗi lượt lưu.
		if po.Status != domain.PurchaseStatusDraft {
			return nil, nil, domain.ErrPurchaseLocked
		}
		// Kiểm TRONG khoá dòng, cạnh lượt kiểm trạng thái: hai câu hỏi khác nhau
		// ("phiếu còn sửa được không" và "bản mình đang xem còn mới không") nhưng
		// cùng đòi đọc bản ghi mới nhất.
		if err := kiemBanDangSua(req.UpdatedAt, po.UpdatedAt); err != nil {
			return nil, nil, err
		}

		po.SupplierID = conTro(req.SupplierID)
		po.SupplierName = ten
		po.DocumentDate = ngayChungTu(req.DocumentDate)
		po.ExpectedDate = ngayChungTu(req.ExpectedDate)
		po.PurchaserID = conTro(req.PurchaserID)
		po.SupplierDeliveryCode = strings.TrimSpace(req.SupplierDeliveryCode)
		po.VATMode = vatMode
		po.VATPercent = req.VATPercent
		po.ItemsAmount = tien.Items
		po.DiscountAmount = req.DiscountAmount
		po.VATAmount = tien.VAT
		po.TotalAmount = total
		po.PaidAmount = req.PaidAmount
		po.PaymentStatus = trangThaiTra(req.PaidAmount, total)
		po.Note = strings.TrimSpace(req.Note)
		po.Attachment = strings.TrimSpace(req.Attachment)

		cols := []string{"SupplierID", "SupplierName", "DocumentDate", "ExpectedDate",
			"PurchaserID", "SupplierDeliveryCode", "VATMode", "VATPercent",
			"ItemsAmount", "DiscountAmount", "VATAmount", "TotalAmount",
			"PaidAmount", "PaymentStatus", "Note", "Attachment"}
		if actorID > 0 {
			actor := actorID
			po.HandledBy = &actor
			cols = append(cols, "HandledBy")
		}

		return cols, lines, nil
	})
	if err != nil {
		return nil, err
	}

	updated, err := s.repo.FindByID(ctx, po.ID)
	if err != nil {
		return nil, err
	}

	return s.detail(ctx, updated)
}

// ---------- Duyệt / huỷ / trả tiền ----------

func (s *phieuMuaHangService) Approve(ctx context.Context, id uint, req *dto.PurchaseApproveRequest, actorID uint) (*PhieuMuaHangDetail, error) {
	// Bỏ trống = bật: giá vốn mới nhất là giá vừa mua.
	capNhatGiaVon := true
	if req.UpdateCost != nil {
		capNhatGiaVon = *req.UpdateCost
	}

	po, err := s.repo.Approve(ctx, id, domain.PurchaseApproval{
		UpdateCost: capNhatGiaVon,
		Note:       req.Note,
		ActorID:    actorID,
	})
	if err != nil {
		return nil, err
	}

	// Đọc lại: lượt duyệt vừa ghi mốc lịch sử và đổi trạng thái trong transaction.
	full, err := s.repo.FindByID(ctx, po.ID)
	if err != nil {
		return nil, err
	}

	return s.detail(ctx, full)
}

func (s *phieuMuaHangService) Cancel(ctx context.Context, id uint, req *dto.PurchaseCancelRequest, actorID uint) (*PhieuMuaHangDetail, error) {
	po, err := s.repo.LockAndUpdate(ctx, id, func(po *domain.PurchaseOrder) (*domain.PurchaseOrderHistory, *domain.PurchasePayment, []string, error) {
		// Chỉ huỷ được phiếu lưu tạm. Phiếu đã duyệt là hàng đã vào kho: muốn trả
		// lại bên bán thì đó là một chứng từ khác, không phải xoá dấu vết phiếu này.
		if po.Status != domain.PurchaseStatusDraft {
			return nil, nil, nil, domain.ErrPurchaseLocked
		}

		now := time.Now()
		po.Status = domain.PurchaseStatusCancelled
		po.CancelledAt = &now
		po.CancelReason = strings.TrimSpace(req.Note)
		cols := []string{"Status", "CancelledAt", "CancelReason"}
		if actorID > 0 {
			actor := actorID
			po.HandledBy = &actor
			cols = append(cols, "HandledBy")
		}

		his := &domain.PurchaseOrderHistory{
			PurchaseOrderID: po.ID,
			FromStatus:      domain.PurchaseStatusDraft,
			ToStatus:        domain.PurchaseStatusCancelled,
			Note:            po.CancelReason,
			ChangedBy:       conTro(actorID),
		}

		// Huỷ phiếu không đụng tới tiền, nên sổ trả tiền không có dòng nào.
		return his, nil, cols, nil
	})
	if err != nil {
		return nil, err
	}

	return s.detail(ctx, po)
}

func (s *phieuMuaHangService) Pay(ctx context.Context, id uint, req *dto.PurchasePaymentRequest, actorID uint) (*PhieuMuaHangDetail, error) {
	po, err := s.repo.LockAndUpdate(ctx, id, func(po *domain.PurchaseOrder) (*domain.PurchaseOrderHistory, *domain.PurchasePayment, []string, error) {
		if po.Status == domain.PurchaseStatusCancelled {
			return nil, nil, nil, domain.ErrPurchaseLocked
		}
		// Kiểm TRONG khoá dòng và so với tổng ĐANG lưu, không phải tổng client
		// gửi kèm: bản v2 tin con số client gửi nên sửa vài ô trên trình duyệt là
		// ghi được một phiếu đã trả đủ mà chưa trả đồng nào.
		if req.PaidAmount > po.TotalAmount+1 {
			return nil, nil, nil, domain.ErrPurchasePaidQuaTong
		}

		// ---- Thoả thuận nợ, soát y như hộp thanh toán của bản v2 ----
		//
		// Soát Ở ĐÂY chứ không chỉ ở trình duyệt: bên v2 mọi luật này nằm trong
		// JS của hộp thoại, nên gọi thẳng đường API là ghi được một khoản nợ
		// không hạn, không người đòi.
		if req.IsDebt {
			if req.PaidAmount+1 >= po.TotalAmount {
				return nil, nil, nil, domain.ErrPurchaseNoDaTraDu
			}
			if req.DebtDueDate == "" {
				return nil, nil, nil, domain.ErrPurchaseNoThieuHan
			}
			if strings.TrimSpace(req.DebtContactName) == "" || strings.TrimSpace(req.DebtContactPhone) == "" {
				return nil, nil, nil, domain.ErrPurchaseNoThieuNguoi
			}
		}

		truoc := po.PaidAmount
		po.PaidAmount = req.PaidAmount
		po.PaymentStatus = trangThaiTra(po.PaidAmount, po.TotalAmount)
		po.PaymentMethod = req.PaymentMethod
		po.PaymentAttachment = strings.TrimSpace(req.PaymentAttachment)

		// Tắt ghi nợ thì DỌN luôn ba trường đi kèm. Để lại thì phiếu mang một
		// hạn nợ và một người đòi cho khoản nợ không còn tồn tại — mọi báo cáo
		// đọc theo hạn sẽ vớ phải nó.
		po.IsDebt = req.IsDebt
		if req.IsDebt {
			po.DebtDueDate = ngayChungTu(req.DebtDueDate)
			po.DebtContactName = strings.TrimSpace(req.DebtContactName)
			po.DebtContactPhone = strings.TrimSpace(req.DebtContactPhone)
		} else {
			po.DebtDueDate = nil
			po.DebtContactName = ""
			po.DebtContactPhone = ""
		}

		cols := []string{
			"PaidAmount", "PaymentStatus", "PaymentMethod", "PaymentAttachment",
			"IsDebt", "DebtDueDate", "DebtContactName", "DebtContactPhone",
		}
		if actorID > 0 {
			actor := actorID
			po.HandledBy = &actor
			cols = append(cols, "HandledBy")
		}

		// Ghi vào lịch sử cả số trước lẫn số sau: "đã trả 500.000" một mình không
		// nói được đây là lần trả đầu hay lần sửa lại con số cũ.
		note := strings.TrimSpace(req.Note)
		if note == "" {
			note = "Cập nhật số đã trả nhà cung cấp"
		}
		his := &domain.PurchaseOrderHistory{
			PurchaseOrderID: po.ID,
			FromStatus:      po.Status,
			ToStatus:        po.Status,
			Note:            note + " (" + soTien(truoc) + " → " + soTien(po.PaidAmount) + ")",
			ChangedBy:       conTro(actorID),
		}

		// SỔ TRẢ TIỀN — chỉ ghi khi tiền THỰC SỰ đổi.
		//
		// Sửa mỗi hạn nợ hay số điện thoại thì không đẻ ra một dòng "trả 0 đồng";
		// chuyện ấy đã có purchase_order_history ghi lại. `Amount` là phần CHÊNH
		// nên cộng cả sổ đúng bằng paid_amount, và một lượt chữa lại con số ghi
		// sai sẽ ra số ÂM — nhìn vào sổ là biết ngay đó không phải lượt trả.
		var tra *domain.PurchasePayment
		if chenh := po.PaidAmount - truoc; chenh != 0 {
			tra = &domain.PurchasePayment{
				Amount:        chenh,
				PaidAfter:     po.PaidAmount,
				PaymentMethod: po.PaymentMethod,
				Note:          strings.TrimSpace(req.Note),
				Attachment:    po.PaymentAttachment,
				CreatedBy:     conTro(actorID),
			}
		}

		return his, tra, cols, nil
	})
	if err != nil {
		return nil, err
	}

	return s.detail(ctx, po)
}

// soTien in số tiền cho dòng lịch sử. Không phần lẻ, không ký hiệu tiền tệ:
// dòng lịch sử đọc bằng mắt người, hào không giúp gì cả.
func soTien(v float64) string {
	return fmt.Sprintf("%.0f", v)
}

func (s *phieuMuaHangService) Delete(ctx context.Context, id uint) error {
	po, err := s.repo.FindByID(ctx, id)
	if err != nil {
		return err
	}
	// Chỉ xoá được phiếu lưu tạm. Phiếu đã duyệt phải nằm lại trong sổ vì kho đã
	// đổi theo nó; phiếu đã huỷ nằm lại để còn đọc được lý do huỷ.
	if po.Status != domain.PurchaseStatusDraft {
		return domain.ErrPurchaseLocked
	}

	return s.repo.Delete(ctx, id)
}
