package service

import (
	"context"
	"math"
	"strings"
	"time"

	"sass-api/internal/domain"
	"sass-api/internal/dto"
)

// TraHangNCCDetail là phiếu trả kèm lịch sử và các thao tác hợp lệ kế tiếp.
type TraHangNCCDetail struct {
	*domain.SupplierReturn
	Histories []domain.SupplierReturnHistory `json:"histories"`
	// CanEdit = phiếu còn sửa/xoá được (chỉ phiếu lưu tạm).
	CanEdit bool `json:"can_edit"`
	// CanApprove = phiếu còn duyệt được. Trang quản trị dựng nút từ hai cờ này
	// thay vì chép lại luật vào giao diện rồi lệch với server.
	CanApprove bool `json:"can_approve"`
}

type TraHangNCCService interface {
	List(ctx context.Context, f domain.SupplierReturnFilter) ([]domain.SupplierReturn, int64, error)
	GetByID(ctx context.Context, id uint) (*TraHangNCCDetail, error)
	Stats(ctx context.Context) (domain.SupplierReturnStats, error)

	PhieuMua(ctx context.Context, supplierID uint, limit int) ([]domain.SupplierReturnPurchase, error)
	DongPhieuMua(ctx context.Context, purchaseID uint) (*domain.SupplierReturnPurchaseDetail, error)

	Create(ctx context.Context, req *dto.SupplierReturnCreateRequest, actorID uint) (*TraHangNCCDetail, error)
	Update(ctx context.Context, id uint, req *dto.SupplierReturnUpdateRequest, actorID uint) (*TraHangNCCDetail, error)
	// Approve duyệt phiếu: hàng rời kho, phiếu khoá lại.
	Approve(ctx context.Context, id uint, req *dto.SupplierReturnApproveRequest, actorID uint) (*TraHangNCCDetail, error)
	Delete(ctx context.Context, id uint) error
}

type traHangNCCService struct {
	repo domain.SupplierReturnRepository
	// nccRepo để chụp hồ sơ bên bán xuống chứng từ — xem hoSoBenBan.
	nccRepo domain.NhaCungCapRepository
}

func NewTraHangNCCService(repo domain.SupplierReturnRepository, nccRepo domain.NhaCungCapRepository) TraHangNCCService {
	return &traHangNCCService{repo: repo, nccRepo: nccRepo}
}

func (s *traHangNCCService) List(ctx context.Context, f domain.SupplierReturnFilter) ([]domain.SupplierReturn, int64, error) {
	return s.repo.List(ctx, f)
}

func (s *traHangNCCService) Stats(ctx context.Context) (domain.SupplierReturnStats, error) {
	return s.repo.Stats(ctx)
}

func (s *traHangNCCService) PhieuMua(ctx context.Context, supplierID uint, limit int) ([]domain.SupplierReturnPurchase, error) {
	return s.repo.PhieuMuaTraDuoc(ctx, supplierID, limit)
}

func (s *traHangNCCService) DongPhieuMua(ctx context.Context, purchaseID uint) (*domain.SupplierReturnPurchaseDetail, error) {
	return s.repo.DongPhieuMua(ctx, purchaseID)
}

func (s *traHangNCCService) GetByID(ctx context.Context, id uint) (*TraHangNCCDetail, error) {
	sr, err := s.repo.FindByID(ctx, id)
	if err != nil {
		return nil, err
	}

	return s.detail(ctx, sr)
}

func (s *traHangNCCService) detail(ctx context.Context, sr *domain.SupplierReturn) (*TraHangNCCDetail, error) {
	his, err := s.repo.Histories(ctx, sr.ID)
	if err != nil {
		return nil, err
	}

	nhap := sr.Status == domain.SupplierReturnDraft

	return &TraHangNCCDetail{
		SupplierReturn: sr,
		Histories:      his,
		CanEdit:        nhap,
		CanApprove:     nhap && len(sr.Items) > 0,
	}, nil
}

// ---------- Dựng dòng hàng ----------

// tienTra là bộ số tiền của một phiếu trả sau khi cộng hết các dòng.
type tienTra struct {
	Items float64
	VAT   float64
}

// dungDongTra chụp lại thông tin từ ĐÚNG dòng phiếu mua gốc rồi tính tiền.
//
// Tên hàng, đơn vị, hệ số quy đổi, giá nhập, số lô, hạn dùng và thuế suất đều
// lấy từ dòng phiếu mua chứ không nhận từ client: phiếu trả là chiều ngược của
// đúng lô hàng ấy, nhận giá khác từ trình duyệt là ghi sổ kho một con số không
// có gốc.
//
// Client chỉ nói HAI thứ: trả dòng nào và trả bao nhiêu.
func dungDongTra(
	items []dto.SupplierReturnItemRequest,
	nguon map[uint]domain.SupplierReturnLine,
) ([]domain.SupplierReturnItem, tienTra, error) {
	var tien tienTra

	// Gộp dòng trùng theo dòng phiếu mua: chọn hai lần cùng một dòng thì cộng số
	// lượng, chứ không ghi hai dòng rồi mỗi dòng qua trần một cách riêng.
	gop := make(map[uint]int, len(items))
	thuTu := make([]uint, 0, len(items))
	for _, it := range items {
		if it.Quantity <= 0 {
			continue
		}
		if _, daCo := gop[it.PurchaseItemID]; !daCo {
			thuTu = append(thuTu, it.PurchaseItemID)
		}
		gop[it.PurchaseItemID] += it.Quantity
	}
	if len(thuTu) == 0 {
		return nil, tien, domain.ErrSupplierReturnEmpty
	}

	lines := make([]domain.SupplierReturnItem, 0, len(thuTu))
	for _, poiID := range thuTu {
		src, ok := nguon[poiID]
		if !ok {
			return nil, tien, domain.ErrSupplierReturnLineLa
		}
		soLuong := gop[poiID]

		// Trần kiểm ở đây là phần CÒN ĐƯỢC TRẢ đọc cùng lúc với danh sách dòng.
		// Lượt duyệt kiểm lại lần nữa dưới khoá dòng — giữa hai mốc đó, một phiếu
		// trả khác có thể đã duyệt xong và ăn hết phần còn lại.
		if soLuong > src.Returnable {
			return nil, tien, domain.ErrSupplierReturnQuaSo
		}

		// Quy đổi phải ra số NGUYÊN: sổ kho đếm nguyên nên không có chỗ ghi phần lẻ.
		base := float64(soLuong) * src.UnitRatio
		if math.Abs(base-math.Round(base)) > 0.0001 {
			return nil, tien, domain.ErrSupplierReturnUnitRatio
		}

		lineAmount := lamTron(src.UnitCost * float64(soLuong))
		vatAmount := lamTron(lineAmount * float64(vatDuong(src.VATPercent)) / 100)

		tien.Items += lineAmount
		tien.VAT += vatAmount

		poi := poiID
		var variantID, productID, unitID *uint
		if src.VariantID > 0 {
			v := src.VariantID
			variantID = &v
		}
		if src.ProductID > 0 {
			p := src.ProductID
			productID = &p
		}
		if src.UnitID > 0 {
			u := src.UnitID
			unitID = &u
		}
		var han *time.Time
		if src.ExpireDate != nil {
			han = ngayChungTu(*src.ExpireDate)
		}

		lines = append(lines, domain.SupplierReturnItem{
			PurchaseOrderItemID: &poi,
			ProductID:           productID,
			ProductVariantID:    variantID,
			ProductName:         src.ProductName,
			VariantSKU:          src.VariantSKU,
			VariantName:         src.VariantName,
			Thumbnail:           src.Thumbnail,
			UnitID:              unitID,
			UnitName:            src.UnitName,
			UnitRatio:           src.UnitRatio,
			LotNumber:           src.LotNumber,
			ExpireDate:          han,
			Quantity:            soLuong,
			BaseQuantity:        int(math.Round(base)),
			UnitCost:            src.UnitCost,
			VATPercent:          src.VATPercent,
			LineAmount:          lineAmount,
			VATAmount:           vatAmount,
			TotalCost:           lineAmount + vatAmount,
		})
	}

	return lines, tien, nil
}

// nguonTraHang đọc phiếu mua gốc và trả về map dòng-mua → thông tin trả được.
//
// `boQua` là phiếu trả đang sửa: phần nó đang giữ không được tính vào "đã trả",
// nếu không thì mở phiếu ra bấm Lưu mà không đổi gì cũng bị từ chối.
func (s *traHangNCCService) nguonTraHang(
	ctx context.Context, purchaseID, supplierID, boQua uint,
) (*domain.SupplierReturnPurchaseDetail, map[uint]domain.SupplierReturnLine, error) {
	if purchaseID == 0 {
		return nil, nil, domain.ErrSupplierReturnNoPurchase
	}

	ct, err := s.repo.DongPhieuMua(ctx, purchaseID)
	if err != nil {
		return nil, nil, err
	}
	// Phiếu mua của bên bán KHÁC: trả hàng cho nhầm nhà cung cấp là sai cả sổ
	// công nợ lẫn chứng từ in ra.
	if supplierID > 0 && ct.SupplierID > 0 && ct.SupplierID != supplierID {
		return nil, nil, domain.ErrSupplierReturnNoPurchase
	}

	nguon := make(map[uint]domain.SupplierReturnLine, len(ct.Lines))
	for _, ln := range ct.Lines {
		nguon[ln.PurchaseItemID] = ln
	}

	if boQua == 0 {
		return ct, nguon, nil
	}

	// Đang sửa: cộng lại phần chính phiếu này đang giữ vào trần.
	poiIDs := make([]uint, 0, len(ct.Lines))
	for _, ln := range ct.Lines {
		poiIDs = append(poiIDs, ln.PurchaseItemID)
	}
	daTraKhac, err := s.repo.DaTraTheoDongMua(ctx, poiIDs, boQua)
	if err != nil {
		return nil, nil, err
	}
	for id, ln := range nguon {
		con := ln.Quantity - daTraKhac[id]
		if ln.Stock < con {
			con = ln.Stock
		}
		if con < 0 {
			con = 0
		}
		ln.Returned = daTraKhac[id]
		ln.Returnable = con
		nguon[id] = ln
	}

	return ct, nguon, nil
}

// hoSoBenBan chụp mã/tên/địa chỉ/số máy của nhà cung cấp xuống chứng từ.
//
// Lấy từ DB chứ không nhận từ client: đây là bản chụp có giá trị pháp lý trên tờ
// phiếu, để trình duyệt gửi lên thì sửa vài ô là in ra một địa chỉ chưa từng tồn
// tại. Tra hỏng thì để trống chứ không chặn cả lượt lập phiếu.
func (s *traHangNCCService) hoSoBenBan(ctx context.Context, sr *domain.SupplierReturn, supplierID uint) {
	if supplierID == 0 {
		return
	}
	ncc, err := s.nccRepo.FindByID(ctx, supplierID)
	if err != nil || ncc == nil {
		return
	}

	sr.SupplierCode = ncc.Code
	sr.SupplierName = ncc.Name
	sr.Address = ncc.Address
	sr.Address2 = ncc.AddressLine2
	sr.SupplierPhone = ncc.Phone
	sr.ContactPhone = ncc.RepresentativePhone
}

// ---------- Tạo & sửa ----------

func (s *traHangNCCService) Create(ctx context.Context, req *dto.SupplierReturnCreateRequest, actorID uint) (*TraHangNCCDetail, error) {
	ct, nguon, err := s.nguonTraHang(ctx, req.PurchaseOrderID, req.SupplierID, 0)
	if err != nil {
		return nil, err
	}

	lines, tien, err := dungDongTra(req.Items, nguon)
	if err != nil {
		return nil, err
	}

	poID := ct.ID
	sr := &domain.SupplierReturn{
		PurchaseOrderID:      &poID,
		PurchaseOrderCode:    ct.POCode,
		SupplierID:           conTro(req.SupplierID),
		Status:               domain.SupplierReturnDraft,
		DocumentDate:         ngayChungTu(req.DocumentDate),
		ExpiredDate:          ngayChungTu(req.ExpiredDate),
		PurchaserID:          conTro(req.PurchaserID),
		ReceiverDeliveryNote: strings.TrimSpace(req.ReceiverDeliveryNote),
		VATPercent:           ct.VATPercent,
		ItemsAmount:          tien.Items,
		VATAmount:            tien.VAT,
		TotalAmount:          tien.Items + tien.VAT,
		Note:                 strings.TrimSpace(req.Note),
		Items:                lines,
	}
	s.hoSoBenBan(ctx, sr, req.SupplierID)
	if actorID > 0 {
		id := actorID
		sr.CreatedBy = &id
		sr.HandledBy = &id
	}

	if err := s.repo.Create(ctx, sr); err != nil {
		return nil, err
	}

	created, err := s.repo.FindByID(ctx, sr.ID)
	if err != nil {
		return nil, err
	}

	return s.detail(ctx, created)
}

func (s *traHangNCCService) Update(ctx context.Context, id uint, req *dto.SupplierReturnUpdateRequest, actorID uint) (*TraHangNCCDetail, error) {
	// Tra phiếu TRƯỚC khi soi danh sách hàng: phiếu không phải của cửa hàng này
	// thì câu trả lời đúng là 404, chứ không phải "dòng hàng không thuộc phiếu
	// mua đã chọn" — lời báo lỗi đó vừa sai vừa hé ra rằng có một phiếu mang id
	// ấy ở đâu đó.
	if _, err := s.repo.FindByID(ctx, id); err != nil {
		return nil, err
	}

	ct, nguon, err := s.nguonTraHang(ctx, req.PurchaseOrderID, req.SupplierID, id)
	if err != nil {
		return nil, err
	}

	lines, tien, err := dungDongTra(req.Items, nguon)
	if err != nil {
		return nil, err
	}

	sr, err := s.repo.Update(ctx, id, func(sr *domain.SupplierReturn) ([]string, []domain.SupplierReturnItem, error) {
		// Chỉ phiếu LƯU TẠM sửa được. Phiếu đã duyệt là kho đã trừ theo nó; sửa
		// tiếp thì sổ kho nói một đằng, phiếu nói một nẻo — đúng chỗ bản v2 hoàn
		// kho rồi trừ lại mỗi lượt lưu.
		if sr.Status != domain.SupplierReturnDraft {
			return nil, nil, domain.ErrSupplierReturnLocked
		}
		if err := kiemBanDangSua(req.UpdatedAt, sr.UpdatedAt); err != nil {
			return nil, nil, err
		}

		poID := ct.ID
		sr.PurchaseOrderID = &poID
		sr.PurchaseOrderCode = ct.POCode
		sr.SupplierID = conTro(req.SupplierID)
		sr.DocumentDate = ngayChungTu(req.DocumentDate)
		sr.ExpiredDate = ngayChungTu(req.ExpiredDate)
		sr.PurchaserID = conTro(req.PurchaserID)
		sr.ReceiverDeliveryNote = strings.TrimSpace(req.ReceiverDeliveryNote)
		sr.VATPercent = ct.VATPercent
		sr.ItemsAmount = tien.Items
		sr.VATAmount = tien.VAT
		sr.TotalAmount = tien.Items + tien.VAT
		sr.Note = strings.TrimSpace(req.Note)
		s.hoSoBenBan(ctx, sr, req.SupplierID)

		cols := []string{"PurchaseOrderID", "PurchaseOrderCode", "SupplierID", "SupplierCode",
			"SupplierName", "Address", "Address2", "SupplierPhone", "ContactPhone",
			"DocumentDate", "ExpiredDate", "PurchaserID", "ReceiverDeliveryNote",
			"VATPercent", "ItemsAmount", "VATAmount", "TotalAmount", "Note"}
		if actorID > 0 {
			actor := actorID
			sr.HandledBy = &actor
			cols = append(cols, "HandledBy")
		}

		return cols, lines, nil
	})
	if err != nil {
		return nil, err
	}

	updated, err := s.repo.FindByID(ctx, sr.ID)
	if err != nil {
		return nil, err
	}

	return s.detail(ctx, updated)
}

// ---------- Duyệt / xoá ----------

func (s *traHangNCCService) Approve(ctx context.Context, id uint, req *dto.SupplierReturnApproveRequest, actorID uint) (*TraHangNCCDetail, error) {
	sr, err := s.repo.Approve(ctx, id, domain.SupplierReturnApproval{
		Note:    req.Note,
		ActorID: actorID,
	})
	if err != nil {
		return nil, err
	}

	// Đọc lại: lượt duyệt vừa ghi mốc lịch sử và đổi trạng thái trong transaction.
	full, err := s.repo.FindByID(ctx, sr.ID)
	if err != nil {
		return nil, err
	}

	return s.detail(ctx, full)
}

func (s *traHangNCCService) Delete(ctx context.Context, id uint) error {
	sr, err := s.repo.FindByID(ctx, id)
	if err != nil {
		return err
	}
	// Chỉ xoá được phiếu lưu tạm. Phiếu đã duyệt phải nằm lại trong sổ vì kho đã
	// đổi theo nó.
	if sr.Status != domain.SupplierReturnDraft {
		return domain.ErrSupplierReturnLocked
	}

	return s.repo.Delete(ctx, id)
}
