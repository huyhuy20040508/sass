package service

import (
	"context"

	"sass-api/internal/domain"
	"sass-api/internal/dto"
)

// ThueService — thuế suất của một cửa hàng (Hàng hóa → Thuế).
//
// Bốn loại cố định: không có thêm, không có xoá. Cửa hàng chỉ tick mức nào cho
// hiện ra ở màn nghiệp vụ, và bật/tắt cả dòng.
type ThueService interface {
	DanhSach(ctx context.Context) ([]dto.ThueItem, error)
	CapNhat(ctx context.Context, id uint, muc []int) (dto.ThueItem, error)
	DoiTrangThai(ctx context.Context, id uint, bat bool) (dto.ThueItem, error)
}

type thueService struct {
	repo domain.ThueRepository
}

func NewThueService(repo domain.ThueRepository) ThueService {
	return &thueService{repo: repo}
}

// DanhSach dựng đủ bốn dòng rồi trả về theo thứ tự danh mục.
func (s *thueService) DanhSach(ctx context.Context) ([]dto.ThueItem, error) {
	if err := s.taoThieu(ctx); err != nil {
		return nil, err
	}

	ds, err := s.repo.List(ctx)
	if err != nil {
		return nil, err
	}

	theoLoai := make(map[string]domain.Thue, len(ds))
	for _, t := range ds {
		theoLoai[t.Loai] = t
	}

	// Đi theo danh mục chứ không theo thứ tự trong bảng: bảng sắp theo id, mà id
	// phụ thuộc lượt chèn nên thứ tự cột trên màn hình sẽ nhảy giữa các cửa hàng.
	out := make([]dto.ThueItem, 0, len(domain.DanhMucLoaiThue))
	for _, loai := range domain.DanhMucLoaiThue {
		t, co := theoLoai[loai.Ma]
		if !co {
			continue
		}
		out = append(out, dungItem(t, loai))
	}

	return out, nil
}

func (s *thueService) CapNhat(ctx context.Context, id uint, muc []int) (dto.ThueItem, error) {
	t, err := s.repo.FindByID(ctx, id)
	if err != nil {
		return dto.ThueItem{}, err
	}

	loai, co := domain.TimLoaiThue(t.Loai)
	if !co {
		return dto.ThueItem{}, domain.ErrLoaiThueLa
	}

	sach, err := chotMuc(loai, muc)
	if err != nil {
		return dto.ThueItem{}, err
	}

	t.DatMuc(sach)
	if err := s.repo.Luu(ctx, t); err != nil {
		return dto.ThueItem{}, err
	}

	return dungItem(*t, loai), nil
}

func (s *thueService) DoiTrangThai(ctx context.Context, id uint, bat bool) (dto.ThueItem, error) {
	t, err := s.repo.FindByID(ctx, id)
	if err != nil {
		return dto.ThueItem{}, err
	}

	loai, co := domain.TimLoaiThue(t.Loai)
	if !co {
		return dto.ThueItem{}, domain.ErrLoaiThueLa
	}

	t.IsActive = bat
	if err := s.repo.Luu(ctx, t); err != nil {
		return dto.ThueItem{}, err
	}

	return dungItem(*t, loai), nil
}

// taoThieu bù những loại cửa hàng chưa có, với bộ mức mặc định của từng loại.
func (s *thueService) taoThieu(ctx context.Context) error {
	ds, err := s.repo.List(ctx)
	if err != nil {
		return err
	}

	daCo := make(map[string]bool, len(ds))
	for _, t := range ds {
		daCo[t.Loai] = true
	}

	var them []domain.Thue
	for _, loai := range domain.DanhMucLoaiThue {
		if daCo[loai.Ma] {
			continue
		}
		t := domain.Thue{Loai: loai.Ma, IsActive: true}
		t.DatMuc(loai.MacDinh)
		them = append(them, t)
	}

	return s.repo.TaoThieu(ctx, them)
}

// chotMuc lọc trùng, giữ đúng thứ tự của danh mục và chặn mức lạ.
//
// Sắp theo danh mục chứ không theo thứ tự người dùng bấm: ô chọn nhiều trả về
// theo lượt tick, để nguyên thì mỗi lần lưu là một thứ tự khác nhau trên bảng.
func chotMuc(loai domain.LoaiThue, muc []int) ([]int, error) {
	chon := make(map[int]bool, len(muc))
	for _, m := range muc {
		if !loai.ChoChon(m) {
			return nil, domain.ErrMucThueLa
		}
		chon[m] = true
	}

	sach := make([]int, 0, len(chon))
	for _, m := range loai.ChonDuoc {
		if chon[m] {
			sach = append(sach, m)
		}
	}

	if len(sach) == 0 {
		return nil, domain.ErrThueTrongRong
	}

	return sach, nil
}

// dungItem ghép một dòng trong bảng với loại của nó thành dữ liệu màn hình.
func dungItem(t domain.Thue, loai domain.LoaiThue) dto.ThueItem {
	muc := t.DanhSachMuc()

	nhan := make([]string, 0, len(muc))
	for _, m := range muc {
		nhan = append(nhan, domain.TenMuc(m))
	}

	chon := make([]dto.MucThueChon, 0, len(loai.ChonDuoc))
	for _, m := range loai.ChonDuoc {
		chon = append(chon, dto.MucThueChon{GiaTri: m, Nhan: domain.TenMuc(m)})
	}

	return dto.ThueItem{
		ID:       t.ID,
		Loai:     t.Loai,
		Ten:      loai.Ten,
		MoTa:     loai.MoTa,
		Muc:      muc,
		MucNhan:  nhan,
		ChonDuoc: chon,
		IsActive: t.IsActive,
	}
}
