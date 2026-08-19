package service

import (
	"context"
	"regexp"
	"strings"

	"sass-api/internal/domain"
	"sass-api/internal/dto"
)

// QuyTacMaService — quy tắc đánh số chứng từ của một cửa hàng.
//
// Màn cấu hình gửi lên TRẠNG THÁI CUỐI CÙNG của đúng những phạm vi nó đang hiện
// (một chi nhánh + phạm vi dùng chung): loại nào có trong danh sách là bật, loại
// nào vắng mặt là tắt. Không có đường bật/tắt lẻ từng loại — hai cách nói cùng
// một chuyện là chỗ để sinh ra hai bản ghi cãi nhau.
type QuyTacMaService interface {
	DanhSach(ctx context.Context) (dto.QuyTacMaResponse, error)
	Luu(ctx context.Context, req *dto.LuuQuyTacMaRequest) error
}

type quyTacMaService struct {
	repo     domain.QuyTacMaRepository
	chiNhanh domain.ChiNhanhRepository
}

func NewQuyTacMaService(repo domain.QuyTacMaRepository, chiNhanh domain.ChiNhanhRepository) QuyTacMaService {
	return &quyTacMaService{repo: repo, chiNhanh: chiNhanh}
}

// maHopLe — tiền tố/hậu tố: chữ không dấu, số, gạch ngang, gạch dưới.
// Mã này in ra giấy và gõ vào ô tìm kiếm nên phải gõ được ở mọi bàn phím.
var maHopLe = regexp.MustCompile(`^[A-Za-z0-9_-]*$`)

func (s *quyTacMaService) DanhSach(ctx context.Context) (dto.QuyTacMaResponse, error) {
	ds, err := s.repo.List(ctx)
	if err != nil {
		return dto.QuyTacMaResponse{}, err
	}

	return dto.QuyTacMaResponse{Loai: domain.DanhMucLoaiMa, QuyTac: ds}, nil
}

func (s *quyTacMaService) Luu(ctx context.Context, req *dto.LuuQuyTacMaRequest) error {
	// Chi nhánh phải có thật: shop_id gõ bừa mà lọt xuống là một bộ quy tắc treo
	// lơ lửng, không màn hình nào đọc tới.
	if _, err := s.chiNhanh.FindByID(ctx, req.ShopID); err != nil {
		return err
	}

	ds := make([]domain.QuyTacMa, 0, len(req.QuyTac))
	for _, item := range req.QuyTac {
		q, err := s.chot(item, req.ShopID)
		if err != nil {
			return err
		}
		ds = append(ds, q)
	}

	// Hai phạm vi màn hình đang hiện: chi nhánh đang chọn và phạm vi dùng chung.
	return s.repo.Luu(ctx, []uint{req.ShopID, 0}, ds)
}

// chot kiểm một dòng và quy về phạm vi của nó.
func (s *quyTacMaService) chot(item dto.QuyTacMaItem, shopID uint) (domain.QuyTacMa, error) {
	loai, ok := domain.TimLoaiMa(item.DocType)
	if !ok {
		return domain.QuyTacMa{}, domain.ErrLoaiMaLa
	}
	if !domain.PhanGiaTriHopLe(item.ValuePart) {
		return domain.QuyTacMa{}, domain.ErrPhanGiaTriLa
	}
	if item.Length < domain.DoDaiMaToiThieu || item.Length > domain.DoDaiMaToiDa {
		return domain.QuyTacMa{}, domain.ErrDoDaiMaLa
	}

	prefix := strings.TrimSpace(item.Prefix)
	suffix := strings.TrimSpace(item.Suffix)
	if !maHopLe.MatchString(prefix) || !maHopLe.MatchString(suffix) {
		return domain.QuyTacMa{}, domain.ErrTienToLa
	}

	// Danh mục dùng chung toàn cửa hàng, chứng từ theo chi nhánh — phạm vi do
	// danh mục quyết định, không nghe theo dữ liệu gửi lên.
	pham := shopID
	if loai.DungChung {
		pham = 0
	}

	return domain.QuyTacMa{
		ShopID:    pham,
		DocType:   loai.Ma,
		Prefix:    prefix,
		ValuePart: item.ValuePart,
		Length:    item.Length,
		Suffix:    suffix,
		IsActive:  true,
	}, nil
}
