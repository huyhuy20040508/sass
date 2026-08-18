package service

import (
	"context"
	"fmt"
	"strings"

	"sass-api/internal/domain"
	"sass-api/internal/dto"
)

// NhomQuyenService — nghiệp vụ PHÂN QUYỀN của một cửa hàng.
//
// Ba luật đáng nhớ, cả ba đều là nghiệp vụ chứ không phải kiểm tra dữ liệu:
//
//   - Chuỗi quyền phải có trong danh mục Go. Một chuỗi lạ ghi xuống được thì nó
//     là hàng rác không ai kiểm; một chuỗi gõ sai là một trang không ai mở được
//     mà chẳng có gì báo.
//   - Nhóm hệ thống (Quản lý, Thu ngân) SỬA được nhưng KHÔNG xoá được. Xoá nhóm
//     cuối cùng còn quyền quản lý là tự khoá mình ra khỏi chính cửa hàng mình.
//   - Bỏ cờ "toàn quyền" thì phải TRẢI danh mục thành từng dòng ngay lúc đó —
//     xem BoToanQuyen.
type NhomQuyenService interface {
	// DanhMuc trả cây quyền để màn hình vẽ ô tick.
	DanhMuc(ctx context.Context) []domain.NhomMucQuyen

	List(ctx context.Context) ([]dto.NhomQuyenResponse, error)
	GetByID(ctx context.Context, id uint) (*dto.NhomQuyenResponse, error)
	Create(ctx context.Context, req *dto.NhomQuyenRequest) (*dto.NhomQuyenResponse, error)
	Update(ctx context.Context, id uint, req *dto.NhomQuyenRequest) (*dto.NhomQuyenResponse, error)
	Delete(ctx context.Context, id uint) error

	// DatQuyen thay TOÀN BỘ danh sách quyền của một nhóm.
	DatQuyen(ctx context.Context, id uint, quyen []string) (*dto.NhomQuyenResponse, error)

	// BoQuyenCuaToi trả tập quyền của một tài khoản — trang quản trị đọc để lọc
	// menu. Đi thẳng xuống repository vì nó không có luật nghiệp vụ nào.
	BoQuyenCuaToi(ctx context.Context, userID, tenantID uint) (domain.BoQuyen, error)

	// NhomCuaNguoi / DatNhomChoNguoi — một người mang được NHIỀU nhóm.
	NhomCuaNguoi(ctx context.Context, userID uint) ([]uint, error)
	DatNhomChoNguoi(ctx context.Context, userID uint, groupIDs []uint, actor Actor) error
}

type nhomQuyenService struct {
	repo  domain.QuyenRepository
	users domain.UserRepository
}

func NewNhomQuyenService(repo domain.QuyenRepository, users domain.UserRepository) NhomQuyenService {
	return &nhomQuyenService{repo: repo, users: users}
}

func (s *nhomQuyenService) DanhMuc(context.Context) []domain.NhomMucQuyen {
	return domain.DanhMucQuyen
}

func (s *nhomQuyenService) List(ctx context.Context) ([]dto.NhomQuyenResponse, error) {
	list, err := s.repo.List(ctx)
	if err != nil {
		return nil, err
	}

	items := make([]dto.NhomQuyenResponse, 0, len(list))
	for i := range list {
		item, err := s.dungResponse(ctx, &list[i])
		if err != nil {
			return nil, err
		}
		items = append(items, *item)
	}

	return items, nil
}

func (s *nhomQuyenService) GetByID(ctx context.Context, id uint) (*dto.NhomQuyenResponse, error) {
	nq, err := s.repo.FindByID(ctx, id)
	if err != nil {
		return nil, err
	}

	return s.dungResponse(ctx, nq)
}

func (s *nhomQuyenService) Create(ctx context.Context, req *dto.NhomQuyenRequest) (*dto.NhomQuyenResponse, error) {
	quyen, err := chotQuyen(req.Quyen)
	if err != nil {
		return nil, err
	}

	ma, err := s.chotMa(ctx, req.Code, 0)
	if err != nil {
		return nil, err
	}

	nq := &domain.NhomQuyen{
		Code:        ma,
		Name:        strings.TrimSpace(req.Name),
		Description: strings.TrimSpace(req.Description),
		// Nhóm cửa hàng tự tạo KHÔNG bao giờ là nhóm hệ thống và KHÔNG được cấp
		// cờ toàn quyền từ đây: cờ ấy nghĩa là "mọi quyền hiện có và sẽ có", một
		// thứ chỉ nên có ở nhóm Quản lý mà hệ thống dựng sẵn.
		IsSystem:   false,
		FullAccess: false,
	}
	if err := s.repo.Create(ctx, nq); err != nil {
		return nil, err
	}
	if err := s.repo.DatQuyenChoNhom(ctx, nq.ID, quyen); err != nil {
		return nil, err
	}

	return s.GetByID(ctx, nq.ID)
}

// Update sửa tên và mô tả. Mã KHÔNG đổi được: nó là thứ mã nguồn gọi tên hai
// nhóm hệ thống, và đổi mã của chúng là làm hỏng lượt gieo cho cửa hàng mới.
func (s *nhomQuyenService) Update(ctx context.Context, id uint, req *dto.NhomQuyenRequest) (*dto.NhomQuyenResponse, error) {
	nq, err := s.repo.FindByID(ctx, id)
	if err != nil {
		return nil, err
	}

	nq.Name = strings.TrimSpace(req.Name)
	nq.Description = strings.TrimSpace(req.Description)
	if err := s.repo.Update(ctx, nq); err != nil {
		return nil, err
	}

	// Gửi kèm danh sách quyền thì cập nhật luôn; bỏ trống (nil) là giữ nguyên.
	// Phân biệt nil với mảng rỗng có chủ ý: mảng rỗng nghĩa là "bỏ hết tick".
	if req.Quyen != nil {
		if _, err := s.DatQuyen(ctx, id, req.Quyen); err != nil {
			return nil, err
		}
	}

	return s.GetByID(ctx, id)
}

// Delete xoá CỨNG. Nhóm hệ thống và nhóm còn người mang đều không xoá được.
func (s *nhomQuyenService) Delete(ctx context.Context, id uint) error {
	nq, err := s.repo.FindByID(ctx, id)
	if err != nil {
		return err
	}
	if nq.IsSystem {
		return domain.ErrNhomQuyenHeThong
	}

	// Đếm trước để câu từ chối nói được CON SỐ. Khoá ngoại cũng chặn, nhưng nó
	// chỉ nói "vi phạm ràng buộc" — người đọc không biết phải chuyển mấy người.
	n, err := s.repo.DemThanhVien(ctx, id)
	if err != nil {
		return err
	}
	if n > 0 {
		return fmt.Errorf("%w: %d tài khoản", domain.ErrNhomQuyenDangDung, n)
	}

	return s.repo.Delete(ctx, id)
}

// DatQuyen thay toàn bộ danh sách quyền của một nhóm.
//
// Nhóm đang mang cờ TOÀN QUYỀN thì lượt này gỡ cờ đi: người dùng vừa nói rõ họ
// muốn một danh sách cụ thể, và giữ cờ lại nghĩa là danh sách ấy không có tác
// dụng gì. Từ đó nhóm ấy là nhóm thường, không tự nhận quyền của module mới.
func (s *nhomQuyenService) DatQuyen(ctx context.Context, id uint, quyen []string) (*dto.NhomQuyenResponse, error) {
	nq, err := s.repo.FindByID(ctx, id)
	if err != nil {
		return nil, err
	}

	chot, err := chotQuyen(quyen)
	if err != nil {
		return nil, err
	}

	if nq.FullAccess {
		nq.FullAccess = false
		if err := s.repo.Update(ctx, nq); err != nil {
			return nil, err
		}
	}
	if err := s.repo.DatQuyenChoNhom(ctx, id, chot); err != nil {
		return nil, err
	}

	return s.GetByID(ctx, id)
}

func (s *nhomQuyenService) BoQuyenCuaToi(ctx context.Context, userID, tenantID uint) (domain.BoQuyen, error) {
	return s.repo.BoQuyenCuaNguoi(ctx, userID, tenantID)
}

func (s *nhomQuyenService) NhomCuaNguoi(ctx context.Context, userID uint) ([]uint, error) {
	return s.repo.NhomCuaNguoi(ctx, userID)
}

// DatNhomChoNguoi thay toàn bộ danh sách nhóm của một người.
//
// Chặn TỰ ĐỔI NHÓM CỦA CHÍNH MÌNH, cùng lý do với việc không cho tự đổi vai trò:
// phiên đang chạy bằng token cũ nên màn hình vẫn trông bình thường, tới lần đăng
// nhập sau mới phát hiện mất quyền — và lúc đó thì không còn đường vào để sửa.
func (s *nhomQuyenService) DatNhomChoNguoi(ctx context.Context, userID uint, groupIDs []uint, actor Actor) error {
	if actor.ID == userID {
		return domain.ErrForbidden
	}

	// Tài khoản phải thuộc CHÍNH cửa hàng này: FindByID đi qua bộ lọc tenant nên
	// id của tiệm khác trả về ErrNotFound.
	if _, err := s.users.FindByID(ctx, userID); err != nil {
		return err
	}

	// Mọi nhóm cũng phải của chính cửa hàng này. Không kiểm thì một id nhóm của
	// tiệm khác đi thẳng vào bảng nối, và người này nhận quyền theo bảng của họ.
	for _, gid := range groupIDs {
		if _, err := s.repo.FindByID(ctx, gid); err != nil {
			return err
		}
	}

	return s.repo.DatNhomChoNguoi(ctx, userID, groupIDs)
}

// ---------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------

func (s *nhomQuyenService) dungResponse(ctx context.Context, nq *domain.NhomQuyen) (*dto.NhomQuyenResponse, error) {
	item := dto.NhomQuyenResponse{NhomQuyen: *nq}

	// Nhóm toàn quyền trả về CẢ danh mục, vì đó đúng là những gì nó có — màn
	// hình tick sẽ hiện đủ dấu tick thay vì một danh sách trống khó hiểu.
	if nq.FullAccess {
		item.Quyen = domain.TatCaQuyen()
	} else {
		quyen, err := s.repo.QuyenCuaNhom(ctx, nq.ID)
		if err != nil {
			return nil, err
		}
		item.Quyen = quyen
	}

	n, err := s.repo.DemThanhVien(ctx, nq.ID)
	if err != nil {
		return nil, err
	}
	item.SoThanhVien = n

	return &item, nil
}

// chotMa chuẩn hoá và kiểm trùng mã nhóm. Mã rỗng thì tự đặt nhom-1, nhom-2…
func (s *nhomQuyenService) chotMa(ctx context.Context, ma string, excludeID uint) (string, error) {
	ma = strings.ToLower(strings.TrimSpace(ma))
	if ma != "" {
		trung, err := s.repo.ExistsByCode(ctx, ma, excludeID)
		if err != nil {
			return "", err
		}
		if trung {
			return "", domain.ErrMaNhomQuyenDaCo
		}

		return ma, nil
	}

	// Dò tới khi gặp mã còn trống. Không đếm số nhóm rồi cộng một: nhóm đã xoá
	// vẫn có thể để lại một mã đang chiếm chỗ.
	for i := 1; i < 200; i++ {
		thu := fmt.Sprintf("nhom-%d", i)
		trung, err := s.repo.ExistsByCode(ctx, thu, 0)
		if err != nil {
			return "", err
		}
		if !trung {
			return thu, nil
		}
	}

	return "", domain.ErrMaNhomQuyenDaCo
}

// chotQuyen bỏ trùng và TỪ CHỐI chuỗi không có trong danh mục.
//
// Từ chối thay vì lặng lẽ bỏ qua: màn hình gửi lên một chuỗi lạ nghĩa là hai bên
// đã lệch nhau, và bỏ qua thì cửa hàng bấm Lưu, thấy báo thành công, rồi phát
// hiện quyền mình vừa tick không có tác dụng gì.
func chotQuyen(ds []string) ([]string, error) {
	da := make(map[string]bool, len(ds))
	ra := make([]string, 0, len(ds))

	for _, q := range ds {
		q = strings.TrimSpace(q)
		if q == "" || da[q] {
			continue
		}
		if !domain.QuyenHopLe(q) {
			return nil, fmt.Errorf("%w: %s", domain.ErrQuyenLa, q)
		}
		da[q] = true
		ra = append(ra, q)
	}

	return ra, nil
}
