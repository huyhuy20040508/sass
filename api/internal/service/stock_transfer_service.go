package service

import (
	"context"
	"fmt"
	"strings"
	"time"

	"sass-api/internal/domain"
	"sass-api/internal/dto"
)

// DieuChuyenDetail là phiếu điều chuyển kèm các thao tác hợp lệ kế tiếp.
type DieuChuyenDetail struct {
	*domain.PhieuDieuChuyen
	// CanEdit = phiếu còn sửa/xoá được (chỉ phiếu lưu tạm).
	CanEdit bool `json:"can_edit"`
	// CanApprove = phiếu còn duyệt được. Trang quản trị dựng nút từ hai cờ này
	// thay vì chép lại luật vào giao diện rồi lệch với server.
	CanApprove bool `json:"can_approve"`
}

type PhieuDieuChuyenService interface {
	List(ctx context.Context, f domain.DieuChuyenFilter) ([]domain.PhieuDieuChuyen, int64, error)
	Stats(ctx context.Context, f domain.DieuChuyenFilter) (domain.DieuChuyenStats, error)
	GetByID(ctx context.Context, id uint) (*DieuChuyenDetail, error)

	Create(ctx context.Context, req *dto.DieuChuyenCreateRequest, actorID uint) (*DieuChuyenDetail, error)
	Update(ctx context.Context, id uint, req *dto.DieuChuyenUpdateRequest, actorID uint) (*DieuChuyenDetail, error)
	// Approve duyệt phiếu: hàng rời kho gửi và vào kho nhận.
	Approve(ctx context.Context, id uint, req *dto.DieuChuyenApproveRequest, actorID uint) (*DieuChuyenDetail, error)
	Delete(ctx context.Context, id uint) error
}

type phieuDieuChuyenService struct {
	repo domain.PhieuDieuChuyenRepository
	// chiNhanhRepo để chốt hai kho có thật, thuộc cửa hàng này, và đang mở.
	chiNhanhRepo domain.ChiNhanhRepository
}

func NewPhieuDieuChuyenService(
	repo domain.PhieuDieuChuyenRepository, chiNhanhRepo domain.ChiNhanhRepository,
) PhieuDieuChuyenService {
	return &phieuDieuChuyenService{repo: repo, chiNhanhRepo: chiNhanhRepo}
}

func (s *phieuDieuChuyenService) List(
	ctx context.Context, f domain.DieuChuyenFilter,
) ([]domain.PhieuDieuChuyen, int64, error) {
	return s.repo.List(ctx, f)
}

func (s *phieuDieuChuyenService) Stats(
	ctx context.Context, f domain.DieuChuyenFilter,
) (domain.DieuChuyenStats, error) {
	return s.repo.Stats(ctx, f)
}

func (s *phieuDieuChuyenService) GetByID(ctx context.Context, id uint) (*DieuChuyenDetail, error) {
	pdc, err := s.repo.FindByID(ctx, id)
	if err != nil {
		return nil, err
	}

	return s.boc(pdc), nil
}

func (s *phieuDieuChuyenService) boc(pdc *domain.PhieuDieuChuyen) *DieuChuyenDetail {
	conSua := pdc.Status == domain.DieuChuyenDraft

	return &DieuChuyenDetail{
		PhieuDieuChuyen: pdc,
		CanEdit:         conSua,
		CanApprove:      conSua,
	}
}

// ---------------------------------------------------------------------
//  Lập và sửa
// ---------------------------------------------------------------------

func (s *phieuDieuChuyenService) Create(
	ctx context.Context, req *dto.DieuChuyenCreateRequest, actorID uint,
) (*DieuChuyenDetail, error) {
	if err := s.chotHaiKho(ctx, req.FromShopID, req.ToShopID); err != nil {
		return nil, err
	}

	items, tong, err := s.chotDongHang(ctx, req.FromShopID, req.ToShopID, req.Items)
	if err != nil {
		return nil, err
	}

	pdc := &domain.PhieuDieuChuyen{
		FromShopID:  req.FromShopID,
		ToShopID:    req.ToShopID,
		Status:      domain.DieuChuyenDraft,
		ReceiverID:  idHoacNil(req.ReceiverID),
		Note:        strings.TrimSpace(req.Note),
		ItemsAmount: tong,
		CreatedBy:   idHoacNil(actorID),
		Items:       items,
	}

	if err := s.repo.Create(ctx, pdc); err != nil {
		return nil, err
	}

	return s.GetByID(ctx, pdc.ID)
}

func (s *phieuDieuChuyenService) Update(
	ctx context.Context, id uint, req *dto.DieuChuyenUpdateRequest, actorID uint,
) (*DieuChuyenDetail, error) {
	if err := s.chotHaiKho(ctx, req.FromShopID, req.ToShopID); err != nil {
		return nil, err
	}

	items, tong, err := s.chotDongHang(ctx, req.FromShopID, req.ToShopID, req.Items)
	if err != nil {
		return nil, err
	}

	_, err = s.repo.Update(ctx, id, func(pdc *domain.PhieuDieuChuyen) ([]string, []domain.PhieuDieuChuyenItem, error) {
		// Kiểm TRONG khoá dòng: một lượt duyệt chen vào giữa thì phiếu đã khoá, và
		// ghi đè danh sách hàng lúc này là kho đã đổi theo danh sách cũ.
		if pdc.Status != domain.DieuChuyenDraft {
			return nil, nil, domain.ErrDieuChuyenLocked
		}
		if err := kiemBanDangSua(req.UpdatedAt, pdc.UpdatedAt); err != nil {
			return nil, nil, err
		}

		pdc.FromShopID = req.FromShopID
		pdc.ToShopID = req.ToShopID
		pdc.ReceiverID = idHoacNil(req.ReceiverID)
		pdc.Note = strings.TrimSpace(req.Note)
		pdc.ItemsAmount = tong
		pdc.HandledBy = idHoacNil(actorID)

		return []string{"FromShopID", "ToShopID", "ReceiverID", "Note", "ItemsAmount", "HandledBy"}, items, nil
	})
	if err != nil {
		return nil, err
	}

	return s.GetByID(ctx, id)
}

// chotHaiKho kiểm hai đầu của phiếu.
//
// Ba luật, và cả ba đều phải ở đây chứ không ở giao diện: ô chọn trên màn chỉ
// bày chi nhánh đang mở, nhưng gọi thẳng API thì không đi qua ô nào cả.
func (s *phieuDieuChuyenService) chotHaiKho(ctx context.Context, tu, den uint) error {
	if tu == den {
		return domain.ErrDieuChuyenCungKho
	}

	for _, id := range []uint{tu, den} {
		cn, err := s.chiNhanhRepo.FindByID(ctx, id)
		if err != nil {
			// ErrNotFound ở đây nghĩa là chi nhánh không thuộc cửa hàng đang đăng nhập
			// (lượt tra đã lọc theo tenant) — hoặc đã bị xoá.
			return fmt.Errorf("%w: chi nhánh #%d không thuộc cửa hàng này", domain.ErrDieuChuyenKhoLa, id)
		}
		if !cn.IsActive {
			return fmt.Errorf("%w: chi nhánh \"%s\" đã ngừng hoạt động", domain.ErrDieuChuyenKhoLa, cn.Name)
		}
	}

	return nil
}

// chotDongHang dựng danh sách dòng hàng từ payload, CHỤP LẠI hồ sơ mặt hàng và
// lô từ sổ của kho xuất.
//
// Client chỉ nói ba thứ: mặt hàng nào, bao nhiêu, lô nào. Mọi thứ còn lại — tên,
// mã, đơn vị, giá vốn, hạn lô — tra ở đây. Nhận chúng từ trình duyệt là ghi vào
// chứng từ và sổ kho những con số không có gốc.
//
// Gộp dòng trùng (cùng biến thể, cùng lô) thay vì để hai dòng: lượt duyệt cộng
// chúng lại rồi cũng ghi một bút toán, nên để tách ra chỉ làm phiếu in ra khó
// đọc và tổng tiền dễ nhìn nhầm.
func (s *phieuDieuChuyenService) chotDongHang(
	ctx context.Context, khoXuat, khoNhan uint, req []dto.DieuChuyenItemRequest,
) ([]domain.PhieuDieuChuyenItem, float64, error) {
	if len(req) == 0 {
		return nil, 0, domain.ErrDieuChuyenEmpty
	}

	ids := make([]uint, 0, len(req))
	for _, it := range req {
		if it.VariantID > 0 {
			ids = append(ids, it.VariantID)
		}
	}

	// KHO NHẬN phải được phép giữ những mặt hàng này.
	//
	// Thiếu chốt này thì phiếu điều chuyển là một lỗ hổng đi vòng qua mọi chốt
	// khác: mặt hàng gán riêng chi nhánh A vẫn chuyển sang B được, và số hàng ấy
	// nằm lại B ở trạng thái CHẾT — kho có hàng thật nhưng màn Hàng hoá của B
	// không bày mặt hàng ra nên không bán, không lập phiếu, không làm gì được.
	// Đã xảy ra thật với phiếu PDC202609040001.
	//
	// CHỈ kiểm đầu NHẬN, KHÔNG kiểm đầu GỬI. Đầu gửi là ĐƯỜNG DỌN: chi nhánh lỡ
	// đang ôm hàng của một mặt hàng vừa bị gán đi nơi khác phải chuyển trả được
	// số hàng có thật ra khỏi kho mình. Chặn cả hai đầu là khoá chết nó vĩnh
	// viễn — cùng lý do chanHangKhacChiNhanh không đụng tới chỉnh kho và phiếu
	// trả nhà cung cấp.
	if err := s.repo.ChanHangKhongThuoc(ctx, khoNhan, ids); err != nil {
		return nil, 0, err
	}

	hang, err := s.repo.ThongTinHang(ctx, khoXuat, ids)
	if err != nil {
		return nil, 0, err
	}

	type khoa struct {
		variant uint
		lo      string
	}
	viTri := make(map[khoa]int, len(req))
	items := make([]domain.PhieuDieuChuyenItem, 0, len(req))
	// Cộng dồn theo BIẾN THỂ để so với tồn: một mặt hàng chia làm ba lô trong
	// cùng phiếu thì trần là tổng ba dòng, không phải từng dòng một.
	tongTheoBienThe := make(map[uint]int, len(req))

	for _, it := range req {
		h, co := hang[it.VariantID]
		if !co {
			return nil, 0, fmt.Errorf("%w: mặt hàng #%d không còn trong danh mục", domain.ErrNotFound, it.VariantID)
		}
		if it.Quantity <= 0 {
			continue
		}

		soLo := strings.TrimSpace(it.LotNumber)
		k := khoa{variant: it.VariantID, lo: soLo}
		if i, daCo := viTri[k]; daCo {
			items[i].Quantity += it.Quantity
			items[i].LineAmount = float64(items[i].Quantity) * items[i].UnitCost
			tongTheoBienThe[it.VariantID] += it.Quantity

			continue
		}

		// Giá vốn và hạn dùng lấy của ĐÚNG LÔ khi có chỉ định lô; không chỉ định thì
		// lấy giá vốn bình quân của mặt hàng.
		gia := h.CostPrice
		var han *time.Time
		if soLo != "" {
			lo, thay := timLo(h.Lots, soLo)
			if !thay {
				return nil, 0, fmt.Errorf("%w: lô %q của mặt hàng %s không còn hàng ở kho xuất",
					domain.ErrDieuChuyenThieuTon, soLo, h.ProductName)
			}
			han = lo.ExpireDate
			if lo.UnitCost > 0 {
				gia = lo.UnitCost
			}
		}

		productID := h.ProductID
		variantID := it.VariantID
		viTri[k] = len(items)
		items = append(items, domain.PhieuDieuChuyenItem{
			ProductID:        &productID,
			ProductVariantID: &variantID,
			ProductName:      h.ProductName,
			VariantSKU:       h.SKU,
			VariantName:      h.VariantName,
			UnitName:         h.UnitName,
			LotNumber:        soLo,
			ExpireDate:       han,
			Quantity:         it.Quantity,
			UnitCost:         gia,
			LineAmount:       float64(it.Quantity) * gia,
		})
		tongTheoBienThe[it.VariantID] += it.Quantity
	}

	if len(items) == 0 {
		return nil, 0, domain.ErrDieuChuyenEmpty
	}

	// Kẹp theo tồn NGAY LÚC LẬP để người dùng biết sớm. Lượt duyệt vẫn kiểm lại
	// dưới khoá dòng — giữa hai thời điểm ấy hàng có thể đã bán đi, và chốt thật
	// phải nằm ở nơi trừ kho.
	for vid, so := range tongTheoBienThe {
		h := hang[vid]
		if so > h.Stock {
			return nil, 0, fmt.Errorf("%w: %s chỉ còn %d ở kho xuất, phiếu đang ghi %d",
				domain.ErrDieuChuyenThieuTon, h.ProductName, h.Stock, so)
		}
	}

	var tong float64
	for _, it := range items {
		tong += it.LineAmount
	}

	return items, tong, nil
}

// timLo tìm một lô theo số trong danh sách lô còn hàng của kho xuất.
func timLo(lots []domain.TonKhoLo, soLo string) (domain.TonKhoLo, bool) {
	for _, l := range lots {
		if l.LotNumber == soLo {
			return l, true
		}
	}

	return domain.TonKhoLo{}, false
}

// idHoacNil đổi 0 thành nil: cột khoá ngoại để NULL nghĩa là "chưa khai", còn
// số 0 thì MySQL hiểu là một id thật và ràng buộc khoá ngoại sẽ từ chối.
func idHoacNil(id uint) *uint {
	if id == 0 {
		return nil
	}

	return &id
}

// ---------------------------------------------------------------------
//  Duyệt và xoá
// ---------------------------------------------------------------------

func (s *phieuDieuChuyenService) Approve(
	ctx context.Context, id uint, req *dto.DieuChuyenApproveRequest, actorID uint,
) (*DieuChuyenDetail, error) {
	note := ""
	if req != nil {
		note = req.Note
	}

	pdc, err := s.repo.Approve(ctx, id, domain.DieuChuyenApproval{ActorID: actorID, Note: note})
	if err != nil {
		return nil, err
	}

	return s.boc(pdc), nil
}

// Delete chỉ xoá được phiếu LƯU TẠM. Phiếu đã duyệt phải nằm lại trong sổ: kho
// hai đầu đã đổi theo nó, và xoá chứng từ đi thì hai con số ấy không còn gốc.
func (s *phieuDieuChuyenService) Delete(ctx context.Context, id uint) error {
	pdc, err := s.repo.FindByID(ctx, id)
	if err != nil {
		return err
	}
	if pdc.Status != domain.DieuChuyenDraft {
		return domain.ErrDieuChuyenLocked
	}

	return s.repo.Delete(ctx, id)
}
