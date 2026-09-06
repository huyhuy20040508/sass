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

// PhieuDieuChinhService — Quản lý kho → Điều chỉnh tồn kho.
type PhieuDieuChinhService interface {
	List(ctx context.Context, f domain.DieuChinhFilter) ([]domain.PhieuDieuChinh, int64, error)
	GetByID(ctx context.Context, id uint) (*domain.PhieuDieuChinh, error)

	// Create lập phiếu rồi đi tiếp theo `status`: pending = gửi duyệt luôn,
	// approved = duyệt luôn (kho đổi ngay trong cùng lượt gọi).
	Create(ctx context.Context, req *dto.DieuChinhCreateRequest, actorID uint) (*domain.PhieuDieuChinh, error)
	Update(ctx context.Context, id uint, req *dto.DieuChinhUpdateRequest, actorID uint) (*domain.PhieuDieuChinh, error)
	Submit(ctx context.Context, id uint, actorID uint) (*domain.PhieuDieuChinh, error)
	Approve(ctx context.Context, id uint, req *dto.DieuChinhApproveRequest, actorID uint) (*domain.PhieuDieuChinh, error)
	Reject(ctx context.Context, id uint, req *dto.DieuChinhRejectRequest, actorID uint) (*domain.PhieuDieuChinh, error)
	Delete(ctx context.Context, id uint) error

	// HangAm liệt kê lô đang âm tại kho của request, cho hộp "Cân đối hàng âm".
	HangAm(ctx context.Context, shopID uint) ([]domain.HangAm, error)
	// MatHang tra hồ sơ + tồn + MỌI lô của một loạt mặt hàng tại kho — màn lập
	// phiếu gọi sau khi chọn hàng, như `getMenu` của v2.
	MatHang(ctx context.Context, shopID uint, variantIDs []uint) ([]domain.DieuChinhHang, error)
}

type phieuDieuChinhService struct {
	repo domain.PhieuDieuChinhRepository
}

func NewPhieuDieuChinhService(repo domain.PhieuDieuChinhRepository) PhieuDieuChinhService {
	return &phieuDieuChinhService{repo: repo}
}

func (s *phieuDieuChinhService) List(
	ctx context.Context, f domain.DieuChinhFilter,
) ([]domain.PhieuDieuChinh, int64, error) {
	return s.repo.List(ctx, f)
}

func (s *phieuDieuChinhService) GetByID(ctx context.Context, id uint) (*domain.PhieuDieuChinh, error) {
	return s.repo.FindByID(ctx, id)
}

// ---------------------------------------------------------------------
//  Lập và sửa
// ---------------------------------------------------------------------

func (s *phieuDieuChinhService) Create(
	ctx context.Context, req *dto.DieuChinhCreateRequest, actorID uint,
) (*domain.PhieuDieuChinh, error) {
	shopID, err := s.khoCuaPhieu(ctx, req.ShopID)
	if err != nil {
		return nil, err
	}

	loai := strings.TrimSpace(req.Type)
	if loai == "" {
		loai = domain.DieuChinhLoaiThuong
	}

	items, err := s.chotDongHang(ctx, shopID, loai, req.Items)
	if err != nil {
		return nil, err
	}

	trangThai := strings.TrimSpace(req.Status)
	if trangThai == "" {
		trangThai = domain.DieuChinhDraft
	}

	p := &domain.PhieuDieuChinh{
		ShopID:    shopID,
		Type:      loai,
		Status:    domain.DieuChinhDraft,
		Note:      strings.TrimSpace(req.Note),
		CreatedBy: idHoacNil(actorID),
		Items:     items,
	}
	// Gửi duyệt luôn thì ghi thẳng trạng thái chờ duyệt — một lượt ghi thay vì hai.
	if trangThai == domain.DieuChinhPending {
		p.Status = domain.DieuChinhPending
	}

	if err := s.repo.Create(ctx, p); err != nil {
		return nil, err
	}

	// Duyệt luôn đi qua đúng đường duyệt: cùng khoá dòng, cùng cách ghi kho.
	if trangThai == domain.DieuChinhApproved {
		return s.repo.Approve(ctx, p.ID, domain.DieuChinhApproval{ActorID: actorID})
	}

	return s.GetByID(ctx, p.ID)
}

func (s *phieuDieuChinhService) Update(
	ctx context.Context, id uint, req *dto.DieuChinhUpdateRequest, actorID uint,
) (*domain.PhieuDieuChinh, error) {
	// Đọc trước để biết kho và loại phiếu: dòng hàng phải chốt theo đúng kho đó.
	cu, err := s.repo.FindByID(ctx, id)
	if err != nil {
		return nil, err
	}
	if cu.Status != domain.DieuChinhDraft {
		return nil, domain.ErrDieuChinhLocked
	}

	items, err := s.chotDongHang(ctx, cu.ShopID, cu.Type, req.Items)
	if err != nil {
		return nil, err
	}

	_, err = s.repo.Update(ctx, id, func(p *domain.PhieuDieuChinh) ([]string, []domain.PhieuDieuChinhItem, error) {
		// Kiểm TRONG khoá dòng: một lượt duyệt chen vào giữa thì phiếu đã khoá, và
		// ghi đè danh sách hàng lúc này là kho đã đổi theo danh sách cũ.
		if p.Status != domain.DieuChinhDraft {
			return nil, nil, domain.ErrDieuChinhLocked
		}
		if err := kiemBanDangSua(req.UpdatedAt, p.UpdatedAt); err != nil {
			return nil, nil, err
		}

		p.Note = strings.TrimSpace(req.Note)
		p.HandledBy = idHoacNil(actorID)

		return []string{"Note", "HandledBy"}, items, nil
	})
	if err != nil {
		return nil, err
	}

	switch strings.TrimSpace(req.Status) {
	case domain.DieuChinhPending:
		return s.repo.Submit(ctx, id, domain.DieuChinhApproval{ActorID: actorID})
	case domain.DieuChinhApproved:
		return s.repo.Approve(ctx, id, domain.DieuChinhApproval{ActorID: actorID})
	}

	return s.GetByID(ctx, id)
}

// khoCuaPhieu chốt kho của phiếu: client nói rõ thì lấy, không thì lấy kho của
// request (chi nhánh ghim ở header / chi nhánh duy nhất).
func (s *phieuDieuChinhService) khoCuaPhieu(ctx context.Context, shopID uint) (uint, error) {
	if shopID > 0 {
		return shopID, nil
	}

	return s.repo.ChiNhanhMacDinh(ctx)
}

// chotDongHang dựng danh sách dòng hàng từ payload, CHỤP LẠI hồ sơ mặt hàng và
// tồn từng lô từ sổ của kho.
//
// Client chỉ nói ba thứ: mặt hàng nào, lô nào, lệch bao nhiêu. Tên, mã, đơn vị,
// giá vốn, tồn hiện tại tra ở đây — nhận chúng từ trình duyệt là ghi vào chứng
// từ những con số không có gốc.
func (s *phieuDieuChinhService) chotDongHang(
	ctx context.Context, shopID uint, loai string, req []dto.DieuChinhItemRequest,
) ([]domain.PhieuDieuChinhItem, error) {
	if len(req) == 0 {
		return nil, domain.ErrDieuChinhEmpty
	}

	ids := make([]uint, 0, len(req))
	for _, it := range req {
		if it.VariantID > 0 {
			ids = append(ids, it.VariantID)
		}
	}

	hang, err := s.repo.ThongTinHang(ctx, shopID, ids)
	if err != nil {
		return nil, err
	}

	type khoa struct {
		variant uint
		lo      string
	}
	daCo := make(map[khoa]bool, len(req))
	items := make([]domain.PhieuDieuChinhItem, 0, len(req))

	for _, it := range req {
		h, co := hang[it.VariantID]
		if !co {
			return nil, fmt.Errorf("%w: mặt hàng #%d không còn trong danh mục", domain.ErrNotFound, it.VariantID)
		}

		lech, err := soNguyen(it.AdjustQuantity, h.ProductName)
		if err != nil {
			return nil, err
		}

		soLo := chuanHoaLo(it.LotNumber)
		k := khoa{variant: it.VariantID, lo: soLo}
		if daCo[k] {
			return nil, fmt.Errorf("%w: %s", domain.ErrDieuChinhTrungLo, h.ProductName)
		}
		daCo[k] = true

		// Dòng lệch 0 không đổi gì trong kho — bỏ qua, đúng như v2 chỉ ghi kho cho
		// dòng có số. Nhưng kiểm trùng lô TRƯỚC khi bỏ để người dùng biết phiếu
		// đang có hai dòng cùng lô.
		if lech == 0 {
			continue
		}

		// Tồn, hạn dùng, giá vốn lấy của ĐÚNG LÔ khi lô đã có trong kho; lô mới thì
		// tồn 0, hạn dùng theo client khai, giá vốn theo mặt hàng.
		ton := 0
		var han *time.Time
		gia := h.CostPrice
		if l, thay := timLoTrongKho(h.Lots, soLo); thay {
			ton = l.Quantity
			han = l.ExpireDate
			if l.UnitCost > 0 {
				gia = l.UnitCost
			}
		} else if soLo != "" {
			han = docNgayLo(it.ExpireDate)
		}

		// Phiếu thường không được bớt quá số lô đang có. Kẹp NGAY LÚC LẬP để người
		// dùng biết sớm; lượt duyệt vẫn kiểm lại dưới khoá dòng. Phiếu cân đối thì
		// chỉ cộng nên không chạm trần.
		if loai != domain.DieuChinhLoaiCanDoi && lech < 0 && ton+lech < 0 {
			return nil, fmt.Errorf("%w: %s lô %s chỉ còn %d, phiếu đang bớt %d",
				domain.ErrDieuChinhThieuTon, h.ProductName, tenLo(soLo), ton, -lech)
		}

		productID := h.ProductID
		variantID := it.VariantID
		items = append(items, domain.PhieuDieuChinhItem{
			ProductID:        &productID,
			ProductVariantID: &variantID,
			ProductName:      h.ProductName,
			VariantSKU:       h.SKU,
			VariantName:      h.VariantName,
			UnitName:         h.UnitName,
			LotNumber:        soLo,
			ExpireDate:       han,
			Quantity:         ton,
			AdjustQuantity:   lech,
			UnitCost:         gia,
			Attachment:       strings.TrimSpace(it.Attachment),
		})
	}

	if len(items) == 0 {
		return nil, domain.ErrDieuChinhEmpty
	}

	return items, nil
}

// soNguyen ép số lệch về số nguyên. Trang quản trị (PHP) mã hoá 2 thành 2.0 nên
// nhận số thực, nhưng sổ kho đếm nguyên — có phần lẻ thì từ chối chứ không làm
// tròn: làm tròn là mỗi phiếu lệch một ít mà không ai thấy.
func soNguyen(v float64, ten string) (int, error) {
	if v != math.Trunc(v) {
		return 0, &LoiTheoO{Fields: map[string]string{
			"items": fmt.Sprintf("Số lượng điều chỉnh của %s phải là số nguyên", ten),
		}}
	}

	return int(v), nil
}

// chuanHoaLo đưa số lô về dạng lưu: chuỗi rỗng là lô không xác định. v2 để
// nguyên chữ 'Không xác định' trong dữ liệu và trang quản trị vẫn gửi chữ đó.
func chuanHoaLo(v string) string {
	v = strings.TrimSpace(v)
	if strings.EqualFold(v, "Không xác định") || strings.EqualFold(v, "khong xac dinh") {
		return domain.LoKhongXacDinh
	}

	return v
}

func tenLo(soLo string) string {
	if soLo == "" {
		return "Không xác định"
	}

	return soLo
}

func timLoTrongKho(lots []domain.TonKhoLo, soLo string) (domain.TonKhoLo, bool) {
	for _, l := range lots {
		if l.LotNumber == soLo {
			return l, true
		}
	}

	return domain.TonKhoLo{}, false
}

// docNgayLo đọc hạn dùng của lô MỚI từ client: YYYY-MM-DD hoặc DD-MM-YYYY.
// Đọc không ra thì coi như không có hạn — không chặn cả phiếu vì một ô ngày.
func docNgayLo(v string) *time.Time {
	v = strings.TrimSpace(v)
	if v == "" {
		return nil
	}
	for _, khuon := range []string{"2006-01-02", "02-01-2006", "02/01/2006"} {
		if t, err := time.Parse(khuon, v); err == nil {
			return &t
		}
	}

	return nil
}

// ---------------------------------------------------------------------
//  Đổi trạng thái
// ---------------------------------------------------------------------

func (s *phieuDieuChinhService) Submit(ctx context.Context, id uint, actorID uint) (*domain.PhieuDieuChinh, error) {
	return s.repo.Submit(ctx, id, domain.DieuChinhApproval{ActorID: actorID})
}

func (s *phieuDieuChinhService) Approve(
	ctx context.Context, id uint, req *dto.DieuChinhApproveRequest, actorID uint,
) (*domain.PhieuDieuChinh, error) {
	note := ""
	if req != nil {
		note = req.Note
	}

	return s.repo.Approve(ctx, id, domain.DieuChinhApproval{ActorID: actorID, Note: note})
}

func (s *phieuDieuChinhService) Reject(
	ctx context.Context, id uint, req *dto.DieuChinhRejectRequest, actorID uint,
) (*domain.PhieuDieuChinh, error) {
	lyDo := ""
	if req != nil {
		lyDo = strings.TrimSpace(req.RejectReason)
	}
	if lyDo == "" {
		return nil, &LoiTheoO{Fields: map[string]string{
			"reject_reason": "Vui lòng nói rõ vì sao từ chối phiếu",
		}}
	}

	return s.repo.Reject(ctx, id, domain.DieuChinhApproval{ActorID: actorID, Note: lyDo})
}

// Delete chỉ xoá được phiếu LƯU TẠM — đúng như v2. Phiếu đã gửi duyệt trở đi
// là chứng từ đã có người khác nhìn thấy; phiếu đã duyệt thì kho đã đổi theo nó.
func (s *phieuDieuChinhService) Delete(ctx context.Context, id uint) error {
	p, err := s.repo.FindByID(ctx, id)
	if err != nil {
		return err
	}
	if p.Status != domain.DieuChinhDraft {
		return domain.ErrDieuChinhLocked
	}

	return s.repo.Delete(ctx, id)
}

// ---------------------------------------------------------------------
//  Mặt hàng cho màn lập phiếu
// ---------------------------------------------------------------------

func (s *phieuDieuChinhService) MatHang(
	ctx context.Context, shopID uint, variantIDs []uint,
) ([]domain.DieuChinhHang, error) {
	kho, err := s.khoCuaPhieu(ctx, shopID)
	if err != nil {
		return nil, err
	}

	hang, err := s.repo.ThongTinHang(ctx, kho, variantIDs)
	if err != nil {
		return nil, err
	}

	// Giữ đúng thứ tự client hỏi: lưới hàng thêm dòng theo thứ tự người dùng chọn.
	out := make([]domain.DieuChinhHang, 0, len(variantIDs))
	for _, id := range variantIDs {
		if h, co := hang[id]; co {
			if h.Lots == nil {
				h.Lots = []domain.TonKhoLo{}
			}
			out = append(out, h)
		}
	}

	return out, nil
}

// ---------------------------------------------------------------------
//  Hàng âm
// ---------------------------------------------------------------------

func (s *phieuDieuChinhService) HangAm(ctx context.Context, shopID uint) ([]domain.HangAm, error) {
	kho, err := s.khoCuaPhieu(ctx, shopID)
	if err != nil {
		return nil, err
	}

	ds, err := s.repo.HangAm(ctx, kho)
	if err != nil {
		return nil, err
	}
	if len(ds) == 0 {
		return nil, domain.ErrDieuChinhKhongCoHangAm
	}

	return ds, nil
}
