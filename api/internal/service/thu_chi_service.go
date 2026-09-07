package service

import (
	"context"
	"strings"

	"sass-api/internal/domain"
	"sass-api/internal/dto"
)

// ThuChiService — nghiệp vụ sổ thu chi (Thu chi → Quản lý thu chi).
type ThuChiService interface {
	// List trả về trang đang xem, tổng số dòng và bốn ô quỹ. `nguoi` để dựng cờ
	// `locked` cho từng phiếu.
	List(ctx context.Context, f domain.ThuChiFilter, nguoi domain.ThuChiNguoiXem) (
		[]domain.ThuChi, int64, domain.ThuChiTongKet, error,
	)
	GetByID(ctx context.Context, id uint, nguoi domain.ThuChiNguoiXem) (*domain.ThuChi, error)
	Create(ctx context.Context, req *dto.ThuChiRequest, actorID uint) (*domain.ThuChi, error)
	Update(ctx context.Context, id uint, req *dto.ThuChiRequest, nguoi domain.ThuChiNguoiXem) (*domain.ThuChi, error)
	Delete(ctx context.Context, id uint, nguoi domain.ThuChiNguoiXem) error

	ListNguoiNop(ctx context.Context, keyword string) ([]domain.NguoiNopThuChi, error)
	CreateNguoiNop(ctx context.Context, req *dto.NguoiNopThuChiRequest) (*domain.NguoiNopThuChi, error)
}

type thuChiService struct {
	repo   domain.ThuChiRepository
	maRepo domain.QuyTacMaRepository
}

func NewThuChiService(repo domain.ThuChiRepository, maRepo domain.QuyTacMaRepository) ThuChiService {
	return &thuChiService{repo: repo, maRepo: maRepo}
}

// ---------- Đọc ----------

func (s *thuChiService) List(
	ctx context.Context, f domain.ThuChiFilter, nguoi domain.ThuChiNguoiXem,
) ([]domain.ThuChi, int64, domain.ThuChiTongKet, error) {
	list, total, tk, err := s.repo.List(ctx, f)
	if err != nil {
		return nil, 0, tk, err
	}

	if err := s.danhDauKhoa(ctx, list, nguoi); err != nil {
		return nil, 0, tk, err
	}

	return list, total, tk, nil
}

func (s *thuChiService) GetByID(
	ctx context.Context, id uint, nguoi domain.ThuChiNguoiXem,
) (*domain.ThuChi, error) {
	t, err := s.repo.FindByID(ctx, id)
	if err != nil {
		return nil, err
	}

	ds := []domain.ThuChi{*t}
	if err := s.danhDauKhoa(ctx, ds, nguoi); err != nil {
		return nil, err
	}

	return &ds[0], nil
}

// danhDauKhoa điền cờ `locked` + lý do cho từng phiếu.
//
// Trạng thái ca tra MỘT lượt cho cả trang rồi dùng chung: tra từng phiếu là 50
// lượt đi database cho một lần mở trang, và phần lớn trong đó hỏi lại cùng một ca.
//
// Luật khoá KHÔNG viết lại ở đây — gọi domain.ThuChiKhoa, cùng hàm mà lượt ghi
// gọi. Tách hai chỗ là một ngày nào đó nút hiện ra mà bấm vào thì bị từ chối.
func (s *thuChiService) danhDauKhoa(
	ctx context.Context, list []domain.ThuChi, nguoi domain.ThuChiNguoiXem,
) error {
	trangThaiCa := make(map[uint]bool)

	for i := range list {
		t := &list[i]

		daDong := false
		if t.ShiftID != nil {
			ca := *t.ShiftID
			if v, co := trangThaiCa[ca]; co {
				daDong = v
			} else {
				v, err := s.repo.CaDaDong(ctx, ca)
				if err != nil {
					return err
				}
				trangThaiCa[ca] = v
				daDong = v
			}
		}

		if err := domain.ThuChiKhoa(t, daDong, nguoi); err != nil {
			t.Locked = true
			t.LockedReason = err.Error()
		}
	}

	return nil
}

// ---------- Ghi ----------

// Create lập một phiếu tay.
//
// Chi nhánh, ca trực, người lập và mã phiếu đều lấy từ phía máy chủ chứ không
// nhận từ trình duyệt — xem chú thích ở dto.ThuChiRequest.
func (s *thuChiService) Create(
	ctx context.Context, req *dto.ThuChiRequest, actorID uint,
) (*domain.ThuChi, error) {
	shopID, err := s.repo.ChiNhanhMacDinh(ctx)
	if err != nil {
		return nil, err
	}

	// Con trỏ đã qua `binding:"required,oneof=0 1"` nên chắc chắn khác nil.
	loai := *req.Type

	t := &domain.ThuChi{
		ShopID:        shopID,
		Type:          loai,
		Amount:        req.Amount,
		PaymentMethod: req.PaymentMethod,
		Attachment:    strings.TrimSpace(req.Attachment),
		Note:          strings.TrimSpace(req.Note),
		Source:        domain.ThuChiTuTay,
		CreatedBy:     &actorID,
	}
	if req.CategoryID > 0 {
		id := req.CategoryID
		t.CategoryID = &id
	}
	datDoiTuong(t, req.PayerType, req.PayerID)

	// Ca trực chốt NGAY LÚC LẬP, không dò lại theo giờ về sau. v2 so `created_at`
	// với khoảng [open_time, close_time] mỗi lần hiển thị, nên sửa lại giờ mở ca
	// là phiếu cũ nhảy sang ca khác.
	ca, err := s.repo.CaDangMo(ctx, shopID)
	if err != nil {
		return nil, err
	}
	t.ShiftID = ca

	ma, err := s.sinhMa(ctx, loai, shopID)
	if err != nil {
		return nil, err
	}
	t.Code = ma

	if err := s.repo.Create(ctx, t); err != nil {
		return nil, err
	}

	return s.repo.FindByID(ctx, t.ID)
}

// Update sửa một phiếu, sau khi qua đủ ba lớp khoá.
//
// Luật khoá xét LẠI bên trong lượt khoá dòng của repository, không phải trước
// đó: giữa lúc đọc để kiểm và lúc ghi, ca có thể vừa được đóng — kiểm bên ngoài
// rồi ghi bên trong là để hở đúng khoảng ấy.
//
// KHÔNG đổi: mã phiếu, chi nhánh, ca, người lập, nguồn. Chúng chốt lúc lập.
func (s *thuChiService) Update(
	ctx context.Context, id uint, req *dto.ThuChiRequest, nguoi domain.ThuChiNguoiXem,
) (*domain.ThuChi, error) {
	loai := *req.Type

	return s.repo.Update(ctx, id, func(t *domain.ThuChi) ([]string, error) {
		if err := s.kiemKhoa(ctx, t, nguoi); err != nil {
			return nil, err
		}

		t.Type = loai
		t.Amount = req.Amount
		t.PaymentMethod = req.PaymentMethod
		t.Attachment = strings.TrimSpace(req.Attachment)
		t.Note = strings.TrimSpace(req.Note)

		t.CategoryID = nil
		if req.CategoryID > 0 {
			cid := req.CategoryID
			t.CategoryID = &cid
		}
		datDoiTuong(t, req.PayerType, req.PayerID)

		// Liệt kê tường minh từng cột: Updates với struct bỏ qua giá trị rỗng, mà
		// "bỏ phân loại" hay "xoá ghi chú" đều là gán về rỗng — không khai ra thì
		// hai thao tác ấy im lặng không có tác dụng.
		return []string{
			"Type", "Amount", "CategoryID", "PayerType",
			"EmployeeID", "SupplierID", "PayerID",
			"PaymentMethod", "Attachment", "Note",
		}, nil
	})
}

func (s *thuChiService) Delete(ctx context.Context, id uint, nguoi domain.ThuChiNguoiXem) error {
	t, err := s.repo.FindByID(ctx, id)
	if err != nil {
		return err
	}
	if err := s.kiemKhoa(ctx, t, nguoi); err != nil {
		return err
	}

	return s.repo.Delete(ctx, id)
}

// kiemKhoa tra trạng thái ca rồi chạy đúng luật khoá của domain.
func (s *thuChiService) kiemKhoa(
	ctx context.Context, t *domain.ThuChi, nguoi domain.ThuChiNguoiXem,
) error {
	daDong := false
	if t.ShiftID != nil {
		v, err := s.repo.CaDaDong(ctx, *t.ShiftID)
		if err != nil {
			return err
		}
		daDong = v
	}

	return domain.ThuChiKhoa(t, daDong, nguoi)
}

// datDoiTuong ghi id người nộp vào ĐÚNG một trong ba cột, và dọn hai cột kia.
//
// Dọn là phần bắt buộc: sửa một phiếu từ "Nhà cung cấp" sang "Khác" mà không xoá
// `supplier_id` thì phiếu mang hai đối tượng, và mỗi chỗ đọc lại chọn một cái
// khác nhau tuỳ thứ tự if.
func datDoiTuong(t *domain.ThuChi, loai string, id uint) {
	t.PayerType = loai
	t.EmployeeID, t.SupplierID, t.PayerID = nil, nil, nil

	if loai == "" || id == 0 {
		// Không khai đối tượng thì cũng không giữ lại loại: một phiếu ghi
		// "Nhà cung cấp" mà không có nhà cung cấp nào chỉ làm người đọc đi tìm.
		t.PayerType = ""

		return
	}

	giaTri := id
	switch loai {
	case domain.ThuChiDoiTuongQuanLy, domain.ThuChiDoiTuongThuNgan:
		t.EmployeeID = &giaTri
	case domain.ThuChiDoiTuongNCC:
		t.SupplierID = &giaTri
	case domain.ThuChiDoiTuongKhac:
		t.PayerID = &giaTri
	}
}

// sinhMa cấp mã phiếu theo quy tắc đánh số của cửa hàng.
//
// Phiếu thu và phiếu chi đánh số RIÊNG (hai doc_type), giữ đúng cách tách đôi
// của v2: đọc sổ là thấy ngay dải mã nào tiền vào, dải nào tiền ra.
//
// Chưa bật quy tắc thì trả rỗng và phiếu không có mã — giống mọi chứng từ khác.
// Không tự bịa một dải mã ở đây: hai nơi cùng đặt mã là hai dải chồng nhau.
func (s *thuChiService) sinhMa(ctx context.Context, loai uint8, shopID uint) (string, error) {
	docType := domain.LoaiPhieuThu
	if loai == domain.ThuChiPhieuChi {
		docType = domain.LoaiPhieuChi
	}

	return s.maRepo.SinhMa(ctx, docType, shopID, nil)
}

// ---------- Người nộp ----------

func (s *thuChiService) ListNguoiNop(ctx context.Context, keyword string) ([]domain.NguoiNopThuChi, error) {
	return s.repo.ListNguoiNop(ctx, keyword)
}

func (s *thuChiService) CreateNguoiNop(
	ctx context.Context, req *dto.NguoiNopThuChiRequest,
) (*domain.NguoiNopThuChi, error) {
	name := strings.TrimSpace(req.Name)

	trung, err := s.repo.TonTaiNguoiNopTen(ctx, name)
	if err != nil {
		return nil, err
	}
	if trung {
		return nil, domain.ErrNguoiNopTrungTen
	}

	n := &domain.NguoiNopThuChi{
		Name:    name,
		Phone:   strings.TrimSpace(req.Phone),
		Address: strings.TrimSpace(req.Address),
	}
	if err := s.repo.CreateNguoiNop(ctx, n); err != nil {
		return nil, err
	}

	return n, nil
}
