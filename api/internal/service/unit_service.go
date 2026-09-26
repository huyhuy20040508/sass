package service

import (
	"context"
	"strings"

	"sass-api/internal/domain"
	"sass-api/internal/dto"
)

// DonViTinhService — nghiệp vụ đơn vị tính (Hàng hóa → Đơn vị).
type DonViTinhService interface {
	List(ctx context.Context, f domain.DonViTinhFilter) ([]domain.DonViTinh, error)
	GetByID(ctx context.Context, id uint) (*domain.DonViTinh, error)
	Create(ctx context.Context, req *dto.DonViTinhRequest) (*domain.DonViTinh, error)
	Update(ctx context.Context, id uint, req *dto.DonViTinhRequest) (*domain.DonViTinh, error)
	DoiTrangThai(ctx context.Context, id uint, batLen bool) (*domain.DonViTinh, error)
	Delete(ctx context.Context, id uint) error
}

type donViTinhService struct {
	repo domain.DonViTinhRepository
	// quyTac là quy tắc đánh số của cửa hàng. Chưa bật thì mã vẫn đặt theo cách
	// sẵn có (DV001…), nên cửa hàng không đụng màn cấu hình không thấy gì khác đi.
	quyTac domain.QuyTacMaRepository
}

func NewDonViTinhService(repo domain.DonViTinhRepository, quyTac domain.QuyTacMaRepository) DonViTinhService {
	return &donViTinhService{repo: repo, quyTac: quyTac}
}

func (s *donViTinhService) List(ctx context.Context, f domain.DonViTinhFilter) ([]domain.DonViTinh, error) {
	return s.repo.List(ctx, f)
}

func (s *donViTinhService) GetByID(ctx context.Context, id uint) (*domain.DonViTinh, error) {
	return s.repo.FindByID(ctx, id)
}

func (s *donViTinhService) Create(ctx context.Context, req *dto.DonViTinhRequest) (*domain.DonViTinh, error) {
	code := strings.ToUpper(strings.TrimSpace(req.Code))
	name := strings.TrimSpace(req.Name)

	// Bỏ trống mã = để phần mềm đặt. Bản cũ v2 bắt gõ tay, mà đơn vị tính là thứ
	// khai một lần rồi thôi — người khai phải tự nghĩ ra một chuỗi viết tắt vừa
	// không trùng vừa đọc được, cho một ô gần như không ai tra.
	if code == "" {
		next, err := s.maTuSinh(ctx)
		if err != nil {
			return nil, err
		}
		code = next
	}

	if err := s.kiemTraTrung(ctx, code, name, 0); err != nil {
		return nil, err
	}

	dv := &domain.DonViTinh{
		Code:     code,
		Name:     name,
		IsActive: req.IsActive == nil || *req.IsActive,
	}
	if err := s.repo.Create(ctx, dv); err != nil {
		return nil, err
	}

	return dv, nil
}

func (s *donViTinhService) Update(ctx context.Context, id uint, req *dto.DonViTinhRequest) (*domain.DonViTinh, error) {
	dv, err := s.repo.FindByID(ctx, id)
	if err != nil {
		return nil, err
	}

	// Mã bỏ trống khi sửa = GIỮ NGUYÊN mã cũ, không sinh mã mới: đơn vị đã gắn
	// vào mặt hàng và in lên tem, tự đổi là hai bên lệch nhau.
	code := strings.ToUpper(strings.TrimSpace(req.Code))
	if code == "" {
		code = dv.Code
	}
	name := strings.TrimSpace(req.Name)

	if err := s.kiemTraTrung(ctx, code, name, id); err != nil {
		return nil, err
	}

	dv.Code = code
	dv.Name = name
	if req.IsActive != nil {
		dv.IsActive = *req.IsActive
	}

	if err := s.repo.Update(ctx, dv); err != nil {
		return nil, err
	}

	return dv, nil
}

// DoiTrangThai chỉ ghi ĐÚNG cột is_active — công tắc trên bảng không được phép
// mang theo tên và mã. Bản cũ v2 gọi `fill($request->all())` ở đường trạng thái
// nên ai gửi kèm `name` là đổi luôn tên qua chính lượt gạt công tắc đó.
func (s *donViTinhService) DoiTrangThai(ctx context.Context, id uint, batLen bool) (*domain.DonViTinh, error) {
	dv, err := s.repo.FindByID(ctx, id)
	if err != nil {
		return nil, err
	}

	dv.IsActive = batLen
	if err := s.repo.Update(ctx, dv); err != nil {
		return nil, err
	}

	return dv, nil
}

func (s *donViTinhService) Delete(ctx context.Context, id uint) error {
	if _, err := s.repo.FindByID(ctx, id); err != nil {
		return err
	}

	return s.repo.Delete(ctx, id)
}

// maTuSinh đặt mã cho đơn vị mới: theo quy tắc đánh số của cửa hàng nếu đã bật
// (Cài đặt → Thông số chung), không thì giữ dải DV001 sẵn có.
func (s *donViTinhService) maTuSinh(ctx context.Context) (string, error) {
	ma, err := s.quyTac.SinhMa(ctx, domain.LoaiDonViTinh, 0, func(ma string) (bool, error) {
		return s.repo.ExistsByCode(ctx, ma, 0)
	})
	if err != nil {
		return "", err
	}
	if ma != "" {
		return ma, nil
	}

	return s.repo.NextCode(ctx)
}

// kiemTraTrung chặn trùng mã và trùng tên trong cùng cửa hàng. excludeID > 0 là
// lượt sửa: bỏ qua chính dòng đang sửa.
func (s *donViTinhService) kiemTraTrung(ctx context.Context, code, name string, excludeID uint) error {
	trungMa, err := s.repo.ExistsByCode(ctx, code, excludeID)
	if err != nil {
		return err
	}
	if trungMa {
		return domain.ErrDonViTinhTrungMa
	}

	trungTen, err := s.repo.ExistsByName(ctx, name, excludeID)
	if err != nil {
		return err
	}
	if trungTen {
		return domain.ErrDonViTinhTrungTen
	}

	return nil
}
