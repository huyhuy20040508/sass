package service

import (
	"context"
	"errors"
	"strings"
	"time"

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
	// Delete — lyDo bắt buộc, ghi vào nhật ký cùng giao dịch.
	Delete(ctx context.Context, id uint, nguoi domain.ThuChiNguoiXem, lyDo string) error
	NhatKy(ctx context.Context, id uint) ([]domain.NhatKyThuChi, error)

	ListNguoiNop(ctx context.Context, keyword string) ([]domain.NguoiNopThuChi, error)
	CreateNguoiNop(ctx context.Context, req *dto.NguoiNopThuChiRequest) (*domain.NguoiNopThuChi, error)
	DeleteNguoiNop(ctx context.Context, id uint) error

	// CaQuay — ca đang mở ở chi nhánh của request; ErrChuaMoCa khi chưa mở.
	// Màn quầy chặn mọi lượt ghi bằng nó (luật 14/10), còn màn quản trị thì không.
	CaQuay(ctx context.Context) (uint, error)
	DanhMucQuay(ctx context.Context) (*domain.ThuChiDanhMucQuay, error)
	// TaiKhoan — choLap: tài khoản đang bật của chi nhánh lập phiếu; không thì mọi
	// tài khoản trong phạm vi shopLoc (0 = mọi chi nhánh) cho ô lọc.
	TaiKhoan(ctx context.Context, choLap bool, shopLoc uint) ([]domain.TaiKhoanThuChi, error)
	// DungCaiDat nối Cài đặt (phân loại ghim ở quầy) — gọi một lần lúc dựng ứng dụng.
	DungCaiDat(c caiDatDoc)
}

type thuChiService struct {
	repo   domain.ThuChiRepository
	maRepo domain.QuyTacMaRepository
	caiDat caiDatDoc
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
			t.CoTheDieuChinh = errors.Is(err, domain.ErrThuChiCaDaDong)
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
	goc, err := s.kiemPhieuGoc(ctx, req, shopID)
	if err != nil {
		return nil, err
	}
	if err := kiemNopVe(req); err != nil {
		return nil, err
	}
	if err := s.kiemThamChieu(ctx, req, nil); err != nil {
		return nil, err
	}
	taiKhoan, err := s.kiemTaiKhoan(ctx, req, shopID, nil)
	if err != nil {
		return nil, err
	}
	duyet, _, err := s.kiemDuyetChi(ctx, req, actorID, nil)
	if err != nil {
		return nil, err
	}

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
	if duyet != nil {
		luc := time.Now()
		t.ApprovedBy, t.ApprovedAt = duyet, &luc
	}
	if goc != nil {
		t.AdjustsID = &goc.ID
	}
	t.Kind = req.Kind
	t.BankAccountID = taiKhoan

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
		// Loại phiếu chốt lúc lập: phiếu nộp về vẫn là nộp về, phiếu thường không đổi thành nộp về.
		req.Kind = t.Kind
		if err := kiemNopVe(req); err != nil {
			return nil, err
		}
		if err := s.kiemThamChieu(ctx, req, t); err != nil {
			return nil, err
		}
		lyDo, err := canLyDo(req.Reason, "sửa")
		if err != nil {
			return nil, err
		}
		taiKhoan, err := s.kiemTaiKhoan(ctx, req, t.ShopID, t)
		if err != nil {
			return nil, err
		}
		doiTaiKhoan := taiKhoan != nil || t.BankAccountID != nil
		duyet, giu, err := s.kiemDuyetChi(ctx, req, nguoi.UserID, t)
		if err != nil {
			return nil, err
		}
		cu := *t

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
		cot := []string{
			"Type", "Amount", "CategoryID", "PayerType",
			"EmployeeID", "SupplierID", "PayerID",
			"PaymentMethod", "Attachment", "Note",
		}
		if duyet != nil && !giu {
			luc := time.Now()
			t.ApprovedBy, t.ApprovedAt = duyet, &luc
			cot = append(cot, "ApprovedBy", "ApprovedAt")
		}
		// Chỉ ghi cột khi có tài khoản cũ / mới: phiếu tiền mặt không đụng cột này.
		if doiTaiKhoan {
			t.BankAccountID = taiKhoan
			cot = append(cot, "BankAccountID")
		}
		t.NhatKy = s.dungNhatKy(ctx, &cu, t, domain.NhatKyThuChiSua, lyDo, nguoi.UserID)

		return cot, nil
	})
}

func (s *thuChiService) Delete(ctx context.Context, id uint, nguoi domain.ThuChiNguoiXem, lyDo string) error {
	t, err := s.repo.FindByID(ctx, id)
	if err != nil {
		return err
	}
	if err := s.kiemKhoa(ctx, t, nguoi); err != nil {
		return err
	}
	lyDo, err = canLyDo(lyDo, "xoá")
	if err != nil {
		return err
	}

	return s.repo.Delete(ctx, id, s.dungNhatKy(ctx, t, nil, domain.NhatKyThuChiXoa, lyDo, nguoi.UserID))
}

func (s *thuChiService) NhatKy(ctx context.Context, id uint) ([]domain.NhatKyThuChi, error) {
	return s.repo.NhatKy(ctx, id)
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

// kiemThamChieu — phân loại và người nộp / nhận phải thuộc cửa hàng của phiên,
// phân loại phải đúng vế với phiếu. Khoá ngoại chỉ biết id có tồn tại ở BẤT KỲ
// cửa hàng nào; màn web tự bày đúng danh sách nhưng app gọi thẳng API thì không.
//
// cu != nil (đang sửa): giữ nguyên phân loại / người nộp cũ thì không tra lại —
// phân loại đã xoá mềm không được chặn việc sửa số tiền của phiếu cũ.
func (s *thuChiService) kiemThamChieu(ctx context.Context, req *dto.ThuChiRequest, cu *domain.ThuChi) error {
	loai := *req.Type
	o := map[string]string{}

	giuLoai := cu != nil && cu.CategoryID != nil && *cu.CategoryID == req.CategoryID && cu.Type == loai ||
		req.LoaiGoc != nil && *req.LoaiGoc == req.CategoryID
	switch {
	case req.CategoryID == 0 && req.Kind == domain.ThuChiNopVe:
		// Nộp về công ty không phải chi phí — không có phân loại chi phí nào để chọn.
	case req.CategoryID == 0:
		o["category_id"] = "Chọn phân loại cho phiếu."
	case !giuLoai:
		l, err := s.repo.TimPhanLoai(ctx, req.CategoryID)
		switch {
		case err != nil:
			return err
		case l == nil:
			o["category_id"] = "Phân loại không tồn tại hoặc không thuộc cửa hàng."
		case l.Type != loai:
			o["category_id"] = "Phân loại này là loại " + tenVe(l.Type) + ", phiếu đang là phiếu " + tenVe(loai) + "."
		}
	}

	if req.PayerType != "" && req.PayerID > 0 && !giuDoiTuong(cu, req.PayerType, req.PayerID) {
		co, err := s.repo.CoDoiTuong(ctx, req.PayerType, req.PayerID)
		if err != nil {
			return err
		}
		if !co {
			o["payer_id"] = "Người nộp / nhận không tồn tại hoặc không thuộc cửa hàng."
		}
	}

	if len(o) > 0 {
		return loiO(o)
	}

	return nil
}

func tenVe(loai uint8) string {
	if loai == domain.ThuChiPhieuChi {
		return "chi"
	}

	return "thu"
}

func giuDoiTuong(cu *domain.ThuChi, loai string, id uint) bool {
	if cu == nil || cu.PayerType != loai {
		return false
	}
	var cot *uint
	switch loai {
	case domain.ThuChiDoiTuongQuanLy, domain.ThuChiDoiTuongThuNgan:
		cot = cu.EmployeeID
	case domain.ThuChiDoiTuongNCC:
		cot = cu.SupplierID
	case domain.ThuChiDoiTuongKhac:
		cot = cu.PayerID
	}

	return cot != nil && *cot == id
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

// ---------- Quầy ----------

func (s *thuChiService) CaQuay(ctx context.Context) (uint, error) {
	shopID, err := s.repo.ChiNhanhMacDinh(ctx)
	if err != nil {
		return 0, err
	}
	ca, err := s.repo.CaDangMo(ctx, shopID)
	if err != nil {
		return 0, err
	}
	if ca == nil {
		return 0, domain.ErrChuaMoCa
	}

	return *ca, nil
}

func (s *thuChiService) DanhMucQuay(ctx context.Context) (*domain.ThuChiDanhMucQuay, error) {
	loai, err := s.repo.PhanLoai(ctx)
	if err != nil {
		return nil, err
	}
	nv, err := s.repo.NhanVien(ctx)
	if err != nil {
		return nil, err
	}
	nop, err := s.repo.ListNguoiNop(ctx, "")
	if err != nil {
		return nil, err
	}

	nhanh, ghim, err := s.phanLoaiNhanh(ctx, loai)
	if err != nil {
		return nil, err
	}

	shopID, err := s.repo.ChiNhanhMacDinh(ctx)
	if err != nil {
		return nil, err
	}
	tk, err := s.repo.TaiKhoanNganHang(ctx, shopID, true)
	if err != nil {
		return nil, err
	}

	return &domain.ThuChiDanhMucQuay{PhanLoai: loai, NhanVien: nv, NguoiNop: nop, PhanLoaiNhanh: nhanh, PhanLoaiGhim: ghim,
		MauPhieu: s.mauPhieuQuay(ctx, loai), TaiKhoan: tk}, nil
}

// ---------- Người nộp ----------

func (s *thuChiService) DeleteNguoiNop(ctx context.Context, id uint) error {
	return s.repo.DeleteNguoiNop(ctx, id)
}

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
