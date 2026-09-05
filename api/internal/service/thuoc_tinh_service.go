package service

import (
	"context"
	"fmt"
	"strings"

	"sass-api/internal/domain"
	"sass-api/internal/dto"
)

// ThuocTinhService — nghiệp vụ thuộc tính (Hàng hóa → Thuộc tính).
type ThuocTinhService interface {
	List(ctx context.Context, f domain.ThuocTinhFilter) ([]domain.ThuocTinh, error)
	GetByID(ctx context.Context, id uint) (*domain.ThuocTinh, error)
	Create(ctx context.Context, req *dto.ThuocTinhRequest) (*domain.ThuocTinh, error)
	Update(ctx context.Context, id uint, req *dto.ThuocTinhRequest) (*domain.ThuocTinh, error)
	DoiTrangThai(ctx context.Context, id uint, batLen bool) (*domain.ThuocTinh, error)
	Delete(ctx context.Context, id uint) error
}

type thuocTinhService struct {
	repo domain.ThuocTinhRepository
	// quyTac là quy tắc đánh số của cửa hàng. Chưa bật thì mã vẫn đặt theo cách
	// sẵn có (TT001…), nên cửa hàng không đụng màn cấu hình không thấy gì khác đi.
	quyTac domain.QuyTacMaRepository
}

func NewThuocTinhService(repo domain.ThuocTinhRepository, quyTac domain.QuyTacMaRepository) ThuocTinhService {
	return &thuocTinhService{repo: repo, quyTac: quyTac}
}

func (s *thuocTinhService) List(ctx context.Context, f domain.ThuocTinhFilter) ([]domain.ThuocTinh, error) {
	list, err := s.repo.List(ctx, f)
	if err != nil {
		return nil, err
	}

	return list, s.danhDauDangDung(ctx, list)
}

func (s *thuocTinhService) GetByID(ctx context.Context, id uint) (*domain.ThuocTinh, error) {
	tt, err := s.repo.FindByID(ctx, id)
	if err != nil {
		return nil, err
	}

	mot := []domain.ThuocTinh{*tt}
	if err := s.danhDauDangDung(ctx, mot); err != nil {
		return nil, err
	}
	tt.InUse = mot[0].InUse

	return tt, nil
}

func (s *thuocTinhService) Create(ctx context.Context, req *dto.ThuocTinhRequest) (*domain.ThuocTinh, error) {
	code := strings.ToUpper(strings.TrimSpace(req.Code))
	name := strings.TrimSpace(req.Name)

	// Bỏ trống mã = để phần mềm đặt, giống đơn vị tính. Bản cũ v2 bắt gõ tay cho
	// cả thuộc tính lẫn từng giá trị con — khai một thuộc tính sáu giá trị là
	// phải tự nghĩ ra bảy chuỗi viết tắt không trùng nhau.
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

	var items []dto.ThuocTinhGiaTriItem
	if req.Values != nil {
		items = *req.Values
	}
	giaTri, err := chuanHoaGiaTri(items, code, nil)
	if err != nil {
		return nil, err
	}

	tt := &domain.ThuocTinh{
		Code:     code,
		Name:     name,
		IsActive: req.IsActive == nil || *req.IsActive,
	}
	if err := s.repo.Create(ctx, tt, giaTri); err != nil {
		return nil, err
	}

	return tt, nil
}

func (s *thuocTinhService) Update(ctx context.Context, id uint, req *dto.ThuocTinhRequest) (*domain.ThuocTinh, error) {
	tt, err := s.repo.FindByID(ctx, id)
	if err != nil {
		return nil, err
	}

	// Mã bỏ trống khi sửa = GIỮ NGUYÊN mã cũ, không sinh mã mới: thuộc tính đã
	// gắn vào mặt hàng, tự đổi là hai bên lệch nhau.
	code := strings.ToUpper(strings.TrimSpace(req.Code))
	if code == "" {
		code = tt.Code
	}
	name := strings.TrimSpace(req.Name)

	if err := s.kiemTraTrung(ctx, code, name, id); err != nil {
		return nil, err
	}

	tt.Code = code
	tt.Name = name
	if req.IsActive != nil {
		tt.IsActive = *req.IsActive
	}

	// Không gửi `values` = không đụng tới bảng giá trị. Gửi mảng RỖNG mới là
	// "xoá hết" — hai câu ấy phải khác nhau, không thì một lượt sửa mỗi cái tên
	// cũng quét sạch giá trị của thuộc tính.
	if req.Values == nil {
		if err := s.repo.UpdateThan(ctx, tt); err != nil {
			return nil, err
		}

		return tt, nil
	}

	giaTri, err := chuanHoaGiaTri(*req.Values, code, tt.GiaTri)
	if err != nil {
		return nil, err
	}

	if err := s.repo.Update(ctx, tt, giaTri); err != nil {
		return nil, err
	}

	return tt, nil
}

// DoiTrangThai chỉ ghi ĐÚNG cột is_active — công tắc trên bảng không được phép
// mang theo tên, mã hay cờ định lượng. Bản cũ v2 gọi `fill($request->all())` ở
// đường trạng thái nên ai gửi kèm `name` là đổi luôn tên qua chính lượt gạt ấy.
func (s *thuocTinhService) DoiTrangThai(ctx context.Context, id uint, batLen bool) (*domain.ThuocTinh, error) {
	tt, err := s.repo.FindByID(ctx, id)
	if err != nil {
		return nil, err
	}

	tt.IsActive = batLen
	if err := s.repo.UpdateThan(ctx, tt); err != nil {
		return nil, err
	}

	return tt, nil
}

func (s *thuocTinhService) Delete(ctx context.Context, id uint) error {
	if _, err := s.repo.FindByID(ctx, id); err != nil {
		return err
	}

	return s.repo.Delete(ctx, id)
}

// danhDauDangDung đánh cờ InUse cho cả trang bằng MỘT lượt hỏi, thay vì mỗi
// dòng một câu như bản cũ (`checkExistProductMaterialDetail()` gọi trong vòng
// lặp Blade).
func (s *thuocTinhService) danhDauDangDung(ctx context.Context, list []domain.ThuocTinh) error {
	if len(list) == 0 {
		return nil
	}

	ids := make([]uint, 0, len(list))
	for _, tt := range list {
		ids = append(ids, tt.ID)
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

// maTuSinh đặt mã cho thuộc tính mới: theo quy tắc đánh số của cửa hàng nếu đã
// bật (Cài đặt → Thông số chung), không thì giữ dải TT001.
func (s *thuocTinhService) maTuSinh(ctx context.Context) (string, error) {
	ma, err := s.quyTac.SinhMa(ctx, domain.LoaiThuocTinh, 0, func(ma string) (bool, error) {
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
func (s *thuocTinhService) kiemTraTrung(ctx context.Context, code, name string, excludeID uint) error {
	trungMa, err := s.repo.ExistsByCode(ctx, code, excludeID)
	if err != nil {
		return err
	}
	if trungMa {
		return domain.ErrThuocTinhTrungMa
	}

	trungTen, err := s.repo.ExistsByName(ctx, name, excludeID)
	if err != nil {
		return err
	}
	if trungTen {
		return domain.ErrThuocTinhTrungTen
	}

	return nil
}

// chuanHoaGiaTri dựng danh sách giá trị sẽ ghi xuống.
//
// `cu` là các giá trị hiện có của thuộc tính (rỗng khi tạo mới): dòng gửi lên
// kèm id phải nằm trong đó, không thì đây là id của thuộc tính KHÁC. Bản cũ v2
// nhét thẳng id vào `updateOrCreate` nên id lạ thành một lượt INSERT với khoá
// chính do client chọn — chính là lỗi CAT-R04 mà bản API v2 của họ phải vá lại.
//
// Mã bỏ trống được đặt hộ theo dạng <mã thuộc tính><số thứ tự>: SIZE01, SIZE02…
// Mã và tên phải khác nhau trong CÙNG một thuộc tính; so không phân biệt hoa
// thường nhưng có phân biệt dấu, cùng luật với tên thuộc tính.
func chuanHoaGiaTri(items []dto.ThuocTinhGiaTriItem, maThuocTinh string, cu []domain.ThuocTinhGiaTri) ([]domain.ThuocTinhGiaTri, error) {
	if len(items) == 0 {
		return nil, nil
	}

	idCu := make(map[uint]bool, len(cu))
	for _, gt := range cu {
		idCu[gt.ID] = true
	}

	ra := make([]domain.ThuocTinhGiaTri, 0, len(items))
	daCoMa := make(map[string]bool, len(items))
	daCoTen := make(map[string]bool, len(items))

	// Lượt một: chốt các mã người dùng tự gõ, để lượt sinh mã bên dưới không
	// đụng phải chúng.
	for _, it := range items {
		if it.ID > 0 && !idCu[it.ID] {
			return nil, domain.ErrGiaTriLaCuaThuocTinhKhac
		}

		ma := strings.ToUpper(strings.TrimSpace(it.Code))
		if ma == "" {
			continue
		}
		if daCoMa[ma] {
			return nil, domain.ErrGiaTriTrungMa
		}
		daCoMa[ma] = true
	}

	dem := 0
	for _, it := range items {
		ten := strings.TrimSpace(it.Name)
		khoaTen := strings.ToLower(ten)
		if daCoTen[khoaTen] {
			return nil, domain.ErrGiaTriTrungTen
		}
		daCoTen[khoaTen] = true

		ma := strings.ToUpper(strings.TrimSpace(it.Code))
		if ma == "" {
			var err error
			if ma, err = maGiaTriKeTiep(maThuocTinh, daCoMa, &dem); err != nil {
				return nil, err
			}
			daCoMa[ma] = true
		}

		ra = append(ra, domain.ThuocTinhGiaTri{ID: it.ID, Code: ma, Name: ten})
	}

	return ra, nil
}

// soDeMaGiaTri là số lần thử tối đa khi đặt mã hộ cho một giá trị. Bảng nhập
// chặn ở 100 dòng nên chừng này luôn đủ; có trần để một dữ liệu lạ không biến
// vòng lặp thành vòng vô tận.
const soDeMaGiaTri = 999

// maGiaTriKeTiep đặt mã hộ cho một giá trị: <mã thuộc tính> + số thứ tự hai
// chữ số, nhảy qua những mã người dùng đã tự gõ trong cùng lượt.
func maGiaTriKeTiep(maThuocTinh string, daCo map[string]bool, dem *int) (string, error) {
	for *dem < soDeMaGiaTri {
		*dem++
		ma := fmt.Sprintf("%s%02d", maThuocTinh, *dem)
		if !daCo[ma] {
			return ma, nil
		}
	}

	return "", domain.ErrHetSoDe
}
