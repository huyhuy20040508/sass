package service

import (
	"context"
	"fmt"
	"regexp"
	"strings"

	"sass-api/internal/domain"
	"sass-api/internal/dto"
)

// ChiNhanhService — nghiệp vụ ĐIỂM BÁN của một cửa hàng (bảng `shops`).
//
// ĐÂY LÀ THỨ GÓI CHUỖI BÁN. Trước nó, `max_shops` được chốt vào hợp đồng, in ra
// bảng đối chiếu lúc ký và in ra trang gói dịch vụ của chủ tiệm — nhưng không có
// màn hình nào tạo chi nhánh thứ hai, nên con số ấy canh một thứ không ai làm
// được. Ba gói giá vì thế cho ra cùng một phần mềm.
//
// Hai luật của module này, và cả hai đều là luật NGHIỆP VỤ chứ không phải kiểm
// tra dữ liệu:
//
//   - Số chi nhánh không vượt `max_shops` của hợp đồng (HanMucService).
//   - Cửa hàng luôn còn ÍT NHẤT MỘT chi nhánh đang hoạt động. Mọi bảng giao dịch
//     mang `shop_id`, nên không còn chi nhánh nào nghĩa là không còn chỗ để ghi
//     đơn hàng, phiếu nhập hay tồn kho.
type ChiNhanhService interface {
	// List trả về chi nhánh của cửa hàng. onlyActive = true cho các ô chọn (lập
	// phiếu, chọn nơi bán) — chỗ đó không được mời người dùng chọn một chi nhánh
	// đã đóng.
	List(ctx context.Context, onlyActive bool) ([]domain.ChiNhanh, error)
	GetByID(ctx context.Context, id uint) (*domain.ChiNhanh, error)
	Create(ctx context.Context, req *dto.ChiNhanhRequest) (*domain.ChiNhanh, error)
	Update(ctx context.Context, id uint, req *dto.ChiNhanhRequest) (*domain.ChiNhanh, error)
	Delete(ctx context.Context, id uint) error
}

type chiNhanhService struct {
	repo domain.ChiNhanhRepository
	// hanMuc xét trần `max_shops`. nil = máy chủ chưa nối được control plane,
	// khi đó không có hợp đồng nào đọc được nên không ép gì cả.
	hanMuc HanMucService
}

func NewChiNhanhService(repo domain.ChiNhanhRepository, hanMuc HanMucService) ChiNhanhService {
	return &chiNhanhService{repo: repo, hanMuc: hanMuc}
}

// maChiNhanhHopLe — chữ thường không dấu, số, chấm, gạch ngang, gạch dưới.
//
// Cùng luật với tên đăng nhập của nhân viên, và vì cùng một lý do: mã này đi vào
// chứng từ và vào các ô lọc, nên nó phải gõ được ở mọi bàn phím. Bắt đầu bằng
// chữ hoặc số để không có mã mở đầu bằng dấu gạch.
var maChiNhanhHopLe = regexp.MustCompile(`^[a-z0-9][a-z0-9._-]{1,29}$`)

func (s *chiNhanhService) List(ctx context.Context, onlyActive bool) ([]domain.ChiNhanh, error) {
	return s.repo.List(ctx, onlyActive)
}

func (s *chiNhanhService) GetByID(ctx context.Context, id uint) (*domain.ChiNhanh, error) {
	return s.repo.FindByID(ctx, id)
}

func (s *chiNhanhService) Create(ctx context.Context, req *dto.ChiNhanhRequest) (*domain.ChiNhanh, error) {
	code, err := s.chotMa(ctx, req.Code, 0)
	if err != nil {
		return nil, err
	}

	// Hạn mức xét SAU cùng, ngay trước lượt ghi — cùng thứ tự với sản phẩm và tài
	// khoản: người dùng phải biết mã của mình sai chỗ nào trước đã, rồi mới tới
	// chuyện gói hết chỗ.
	if err := conChoTao(ctx, s.hanMuc, domain.HanMucChiNhanh); err != nil {
		return nil, err
	}

	cn := &domain.ChiNhanh{
		Code:     code,
		Name:     strings.TrimSpace(req.Name),
		Phone:    domain.StringOrNull(strings.TrimSpace(req.Phone)),
		Address:  domain.StringOrNull(strings.TrimSpace(req.Address)),
		IsActive: req.IsActive == nil || *req.IsActive,
	}
	if err := s.repo.Create(ctx, cn); err != nil {
		return nil, err
	}

	return s.repo.FindByID(ctx, cn.ID)
}

func (s *chiNhanhService) Update(ctx context.Context, id uint, req *dto.ChiNhanhRequest) (*domain.ChiNhanh, error) {
	cn, err := s.repo.FindByID(ctx, id)
	if err != nil {
		return nil, err
	}

	// Mã bỏ trống khi sửa = GIỮ NGUYÊN, không sinh mã mới. Khác hẳn lúc tạo, và
	// khác có chủ ý: mã đã in trên chứng từ của chi nhánh này.
	if ma := chuanHoaMa(req.Code); ma != "" && ma != cn.Code {
		if _, err := s.chotMa(ctx, ma, id); err != nil {
			return nil, err
		}
		cn.Code = ma
	}

	cn.Name = strings.TrimSpace(req.Name)
	cn.Phone = domain.StringOrNull(strings.TrimSpace(req.Phone))
	cn.Address = domain.StringOrNull(strings.TrimSpace(req.Address))

	// Tắt chi nhánh hoạt động cuối cùng cũng là đóng cửa cả cửa hàng — chặn cùng
	// một luật với lượt xoá, vì hai thao tác này để lại đúng một hậu quả.
	if req.IsActive != nil && !*req.IsActive && cn.IsActive {
		if err := s.conChiNhanhKhac(ctx, id); err != nil {
			return nil, err
		}
	}
	if req.IsActive != nil {
		cn.IsActive = *req.IsActive
	}

	if err := s.repo.Update(ctx, cn); err != nil {
		return nil, err
	}

	return s.repo.FindByID(ctx, id)
}

func (s *chiNhanhService) Delete(ctx context.Context, id uint) error {
	cn, err := s.repo.FindByID(ctx, id)
	if err != nil {
		return err
	}

	// Chỉ xét khi chi nhánh đang HOẠT ĐỘNG: xoá một chi nhánh đã đóng thì không
	// làm biến mất chỗ bán nào cả, và chặn nó là chặn oan.
	if cn.IsActive {
		if err := s.conChiNhanhKhac(ctx, id); err != nil {
			return err
		}
	}

	return s.repo.Delete(ctx, id)
}

// conChiNhanhKhac bảo đảm còn chi nhánh HOẠT ĐỘNG khác ngoài id sắp bị đóng/xoá.
func (s *chiNhanhService) conChiNhanhKhac(ctx context.Context, id uint) error {
	conLai, err := s.repo.CountActiveExcept(ctx, id)
	if err != nil {
		return err
	}
	if conLai == 0 {
		return domain.ErrChiNhanhCuoiCung
	}

	return nil
}

// chotMa chuẩn hoá, kiểm khuôn và kiểm trùng một mã chi nhánh.
//
// excludeID = 0 lúc tạo. Mã rỗng chỉ được phép ở lượt TẠO (tự sinh); nơi gọi ở
// lượt sửa đã lọc trường hợp đó ra trước khi vào đây.
func (s *chiNhanhService) chotMa(ctx context.Context, ma string, excludeID uint) (string, error) {
	ma = chuanHoaMa(ma)
	if ma == "" {
		return s.maTiepTheo(ctx)
	}
	if !maChiNhanhHopLe.MatchString(ma) {
		return "", domain.ErrMaChiNhanhInvalid
	}

	trung, err := s.repo.ExistsByCode(ctx, ma, excludeID)
	if err != nil {
		return "", err
	}
	if trung {
		return "", domain.ErrMaChiNhanhDaCo
	}

	return ma, nil
}

// maTiepTheo sinh mã cho chi nhánh mới: chi-nhanh-2, chi-nhanh-3…
//
// Dò tới khi gặp mã còn trống thay vì lấy "số chi nhánh + 1": mã của chi nhánh
// đã xoá vẫn giữ chỗ trong khoá duy nhất, nên phép cộng đơn giản sẽ sinh ra một
// mã đã bị chiếm và lượt ghi hỏng ở tận MySQL.
//
// Bắt đầu từ 2 vì chi nhánh đầu tiên luôn là 'mac-dinh' (dựng cùng cửa hàng).
// Trần 200 lượt dò là lưới an toàn cho vòng lặp, không phải hạn mức — hạn mức
// thật nằm ở hợp đồng và đã được xét trước đó.
func (s *chiNhanhService) maTiepTheo(ctx context.Context) (string, error) {
	for i := 2; i < 200; i++ {
		ma := fmt.Sprintf("chi-nhanh-%d", i)
		trung, err := s.repo.ExistsByCode(ctx, ma, 0)
		if err != nil {
			return "", err
		}
		if !trung {
			return ma, nil
		}
	}

	// Không sinh nổi mã thì bắt người dùng tự đặt, chứ đừng ghi một mã trùng.
	return "", domain.ErrMaChiNhanhDaCo
}

// chuanHoaMa hạ chữ thường và cắt khoảng trắng thừa.
//
// Hạ chữ thường thay vì từ chối "Kho-1": người gõ không sai gì cả, và bắt họ gõ
// lại chỉ vì phím Shift là phiền vô ích. Khoá duy nhất của MySQL cũng không phân
// biệt hoa thường, nên nhận cả hai kiểu rồi lưu một kiểu là cách duy nhất để lượt
// kiểm trùng ở đây nói cùng một câu với database.
func chuanHoaMa(ma string) string {
	return strings.ToLower(strings.TrimSpace(ma))
}
