package service

import (
	"context"
	"strings"

	"sass-api/internal/domain"
	"sass-api/internal/dto"
)

// ViTriService — nghiệp vụ vị trí (Hàng hóa → Vị trí).
type ViTriService interface {
	List(ctx context.Context, f domain.ViTriFilter) ([]domain.ViTri, error)
	GetByID(ctx context.Context, id uint) (*domain.ViTri, error)
	Create(ctx context.Context, req *dto.ViTriRequest) (*domain.ViTri, error)
	Update(ctx context.Context, id uint, req *dto.ViTriRequest) (*domain.ViTri, error)
	DoiTrangThai(ctx context.Context, id uint, batLen bool) (*domain.ViTri, error)
	Delete(ctx context.Context, id uint) error
}

type viTriService struct {
	repo domain.ViTriRepository
	// quyTac là quy tắc đánh số của cửa hàng. Chưa bật thì mã vẫn đặt theo dải
	// VT001, nên cửa hàng không đụng màn cấu hình không thấy gì khác đi.
	quyTac domain.QuyTacMaRepository
}

func NewViTriService(repo domain.ViTriRepository, quyTac domain.QuyTacMaRepository) ViTriService {
	return &viTriService{repo: repo, quyTac: quyTac}
}

func (s *viTriService) List(ctx context.Context, f domain.ViTriFilter) ([]domain.ViTri, error) {
	list, err := s.repo.List(ctx, f)
	if err != nil {
		return nil, err
	}

	return list, s.danhDauDangDung(ctx, list)
}

func (s *viTriService) GetByID(ctx context.Context, id uint) (*domain.ViTri, error) {
	vt, err := s.repo.FindByID(ctx, id)
	if err != nil {
		return nil, err
	}

	mot := []domain.ViTri{*vt}
	if err := s.danhDauDangDung(ctx, mot); err != nil {
		return nil, err
	}
	vt.InUse = mot[0].InUse

	return vt, nil
}

func (s *viTriService) Create(ctx context.Context, req *dto.ViTriRequest) (*domain.ViTri, error) {
	// Kệ thuộc về MỘT chi nhánh, chốt ngay lúc khai và không đổi nữa: nó là chỗ
	// vật lý của một mặt bằng, không phải một cái nhãn dùng chung.
	shopID, err := s.repo.ChiNhanhGhi(ctx)
	if err != nil {
		return nil, err
	}

	code := strings.ToUpper(strings.TrimSpace(req.Code))
	name := strings.TrimSpace(req.Name)

	// Bỏ trống mã = để phần mềm đặt, cùng cách với đơn vị tính. Vị trí là thứ
	// khai một lần rồi thôi; bắt người khai tự nghĩ ra chuỗi viết tắt vừa không
	// trùng vừa đọc được chỉ tổ chặn họ ở ô đầu tiên.
	if code == "" {
		next, err := s.maTuSinh(ctx, shopID)
		if err != nil {
			return nil, err
		}
		code = next
	}

	if err := s.kiemTraTrung(ctx, code, name, 0, shopID); err != nil {
		return nil, err
	}

	vt := &domain.ViTri{
		ShopID:   shopID,
		Code:     code,
		Name:     name,
		IsActive: req.IsActive == nil || *req.IsActive,
	}
	if err := s.repo.Create(ctx, vt); err != nil {
		return nil, err
	}

	return vt, nil
}

func (s *viTriService) Update(ctx context.Context, id uint, req *dto.ViTriRequest) (*domain.ViTri, error) {
	vt, err := s.repo.FindByID(ctx, id)
	if err != nil {
		return nil, err
	}

	// Mã bỏ trống khi sửa = GIỮ NGUYÊN mã cũ, không sinh mã mới: vị trí đã gắn
	// vào mặt hàng và có thể đã dán lên kệ, tự đổi là hai bên lệch nhau.
	code := strings.ToUpper(strings.TrimSpace(req.Code))
	if code == "" {
		code = vt.Code
	}
	name := strings.TrimSpace(req.Name)

	// Kiểm trùng trong CHÍNH chi nhánh của kệ đang sửa, không phải chi nhánh
	// người sửa đang đứng: kệ không đổi mặt bằng vì ai đó mở nó lên xem.
	if err := s.kiemTraTrung(ctx, code, name, id, vt.ShopID); err != nil {
		return nil, err
	}

	vt.Code = code
	vt.Name = name
	if req.IsActive != nil {
		vt.IsActive = *req.IsActive
	}

	if err := s.repo.Update(ctx, vt); err != nil {
		return nil, err
	}

	return vt, nil
}

// DoiTrangThai chỉ ghi ĐÚNG cột is_active — công tắc trên bảng không được phép
// mang theo tên và mã.
func (s *viTriService) DoiTrangThai(ctx context.Context, id uint, batLen bool) (*domain.ViTri, error) {
	vt, err := s.repo.FindByID(ctx, id)
	if err != nil {
		return nil, err
	}

	vt.IsActive = batLen
	if err := s.repo.Update(ctx, vt); err != nil {
		return nil, err
	}

	return vt, nil
}

// Delete chặn hẳn khi còn mặt hàng để ở vị trí này. Cho xoá thì những mặt hàng
// ấy mất chỗ trong im lặng, và người đi soạn hàng không còn cách nào biết chúng
// nằm đâu ngoài kho thật.
func (s *viTriService) Delete(ctx context.Context, id uint) error {
	if _, err := s.repo.FindByID(ctx, id); err != nil {
		return err
	}

	dung, err := s.repo.DangDuocDung(ctx, []uint{id})
	if err != nil {
		return err
	}
	if dung[id] {
		return domain.ErrViTriDangDung
	}

	return s.repo.Delete(ctx, id)
}

// danhDauDangDung đánh cờ InUse cho cả trang bằng MỘT lượt hỏi.
func (s *viTriService) danhDauDangDung(ctx context.Context, list []domain.ViTri) error {
	if len(list) == 0 {
		return nil
	}

	ids := make([]uint, 0, len(list))
	for _, vt := range list {
		ids = append(ids, vt.ID)
	}

	dung, err := s.repo.DangDuocDung(ctx, ids)
	if err != nil {
		return err
	}
	for i := range list {
		list[i].InUse = dung[list[i].ID]
	}

	return nil
}

// maTuSinh đặt mã cho vị trí mới: theo quy tắc đánh số của cửa hàng nếu đã bật
// (Cài đặt → Thông số chung), không thì giữ dải VT001.
func (s *viTriService) maTuSinh(ctx context.Context, shopID uint) (string, error) {
	ma, err := s.quyTac.SinhMa(ctx, domain.LoaiViTri, 0, func(ma string) (bool, error) {
		return s.repo.ExistsByCode(ctx, ma, 0, shopID)
	})
	if err != nil {
		return "", err
	}
	if ma != "" {
		return ma, nil
	}

	return s.repo.NextCode(ctx, shopID)
}

// kiemTraTrung chặn trùng mã và trùng tên trong cùng MỘT CHI NHÁNH — không phải
// cùng cửa hàng: hai mặt bằng khác nhau cùng có "Kệ A" là chuyện thường, và bắt
// họ nghĩ ra tên khác nhau chỉ vì database là bắt sai người. excludeID > 0 là
// lượt sửa: bỏ qua chính dòng đang sửa.
func (s *viTriService) kiemTraTrung(ctx context.Context, code, name string, excludeID, shopID uint) error {
	trungMa, err := s.repo.ExistsByCode(ctx, code, excludeID, shopID)
	if err != nil {
		return err
	}
	if trungMa {
		return domain.ErrViTriTrungMa
	}

	trungTen, err := s.repo.ExistsByName(ctx, name, excludeID, shopID)
	if err != nil {
		return err
	}
	if trungTen {
		return domain.ErrViTriTrungTen
	}

	return nil
}
