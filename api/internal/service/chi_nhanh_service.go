package service

import (
	"context"
	"fmt"
	"regexp"
	"strconv"
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
	// Create nhận actorID = người đang đăng nhập, để ghi cột "Người tạo" của
	// bảng danh sách. 0 = không xác định được (lượt gieo dữ liệu, kịch bản nội
	// bộ) và khi đó cột để NULL chứ không bịa ra một người.
	Create(ctx context.Context, req *dto.ChiNhanhRequest, actorID uint) (*domain.ChiNhanh, error)
	Update(ctx context.Context, id uint, req *dto.ChiNhanhRequest) (*domain.ChiNhanh, error)
	Delete(ctx context.Context, id uint) error
}

type chiNhanhService struct {
	repo domain.ChiNhanhRepository
	// hanMuc xét trần `max_shops`. nil = máy chủ chưa nối được control plane,
	// khi đó không có hợp đồng nào đọc được nên không ép gì cả.
	hanMuc HanMucService
	// quyTac là quy tắc đánh số của cửa hàng (Cài đặt → Thông số chung). Chưa
	// bật thì mã vẫn đặt theo dải chi-nhanh-2, chi-nhanh-3 sẵn có.
	quyTac domain.QuyTacMaRepository
}

func NewChiNhanhService(repo domain.ChiNhanhRepository, hanMuc HanMucService, quyTac domain.QuyTacMaRepository) ChiNhanhService {
	return &chiNhanhService{repo: repo, hanMuc: hanMuc, quyTac: quyTac}
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

func (s *chiNhanhService) Create(ctx context.Context, req *dto.ChiNhanhRequest, actorID uint) (*domain.ChiNhanh, error) {
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
		// Chi nhánh mới mặc định là ĐIỂM BÁN. Khai một pháp nhân là việc hiếm và
		// phải cố ý bấm, không phải thứ rơi vào do bỏ trống một ô.
		BranchType: 1,
	}
	if err := apDungHoSo(cn, req); err != nil {
		return nil, err
	}
	if actorID > 0 {
		id := actorID
		cn.CreatedBy = &id
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
	if err := apDungHoSo(cn, req); err != nil {
		return nil, err
	}

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
		return s.maTuSinh(ctx)
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

// maTuSinh đặt mã cho chi nhánh mới: theo quy tắc đánh số của cửa hàng nếu đã
// khai (Cài đặt → Thông số chung), không thì giữ dải chi-nhanh-2 sẵn có.
//
// Mã sinh ra được HẠ CHỮ THƯỜNG trước khi dùng: tiền tố người ta gõ thường là
// "CN" nhưng mã chi nhánh đi vào đường dẫn nên chỉ nhận chữ thường. Lượt kiểm
// trùng vẫn đúng vì khoá uq_shops_tenant_code không phân biệt hoa thường.
func (s *chiNhanhService) maTuSinh(ctx context.Context) (string, error) {
	ma, err := s.quyTac.SinhMa(ctx, domain.LoaiChiNhanh, 0, func(ma string) (bool, error) {
		return s.repo.ExistsByCode(ctx, chuanHoaMa(ma), 0)
	})
	if err != nil {
		return "", err
	}
	if ma == "" {
		return s.maTiepTheo(ctx)
	}

	// Quy tắc cho phép tiền tố mở đầu bằng gạch ngang, mã chi nhánh thì không.
	// Báo ra thay vì lặng lẽ rơi về dải cũ — người cấu hình cần biết để sửa.
	ma = chuanHoaMa(ma)
	if !maChiNhanhHopLe.MatchString(ma) {
		return "", domain.ErrMaChiNhanhInvalid
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

// apDungHoSo đổ phần HỒ SƠ ĐẦY ĐỦ của form (migration 0033) vào entity.
//
// Dùng chung cho cả tạo lẫn sửa, và cố ý coi request là TRẠNG THÁI CUỐI CÙNG:
// ô nào client gửi rỗng thì cột đó về NULL. Form của màn "Quản lý chi nhánh"
// luôn gửi đủ mọi ô, nên diễn giải "rỗng = giữ nguyên" sẽ biến thao tác xoá
// một dòng địa chỉ thành thao tác không có tác dụng gì — người dùng bấm Lưu,
// trang tải lại, chữ cũ vẫn nằm đó và không hiểu vì sao.
//
// Ngoại lệ DUY NHẤT là BranchType: nil = giữ nguyên loại cũ. Nó là con trỏ vì
// "không gửi" khác hẳn "gửi số 0", mà số 0 thì không phải loại nào cả.
func apDungHoSo(cn *domain.ChiNhanh, req *dto.ChiNhanhRequest) error {
	viTri, err := chuanHoaToaDo(req.Location)
	if err != nil {
		return err
	}

	// Toạ độ và phạm vi hoạt động chỉ có nghĩa khi đi cùng nhau — xem
	// ErrToaDoThieuCap. Kiểm ở đây chứ không ở tag binding: `required_with` chỉ
	// bắt được một chiều, mà thiếu chiều nào cũng vô nghĩa như nhau.
	coToaDo := viTri != ""
	coPhamVi := req.AreaScope != nil && *req.AreaScope > 0
	if coToaDo != coPhamVi {
		return domain.ErrToaDoThieuCap
	}

	cn.TransactionName = domain.StringOrNull(strings.TrimSpace(req.TransactionName))
	cn.TaxCode = domain.StringOrNull(strings.TrimSpace(req.TaxCode))
	cn.Email = domain.StringOrNull(strings.TrimSpace(req.Email))
	cn.Country = domain.StringOrNull(strings.TrimSpace(req.Country))
	cn.City = domain.StringOrNull(strings.TrimSpace(req.City))
	cn.Location = domain.StringOrNull(viTri)
	cn.AccessLink = domain.StringOrNull(strings.TrimSpace(req.AccessLink))
	cn.Image = domain.StringOrNull(strings.TrimSpace(req.Image))

	// KHÔNG TrimSpace ba khối hoá đơn: chúng in ra máy in nhiệt và người dùng
	// canh lề bằng chính khoảng trắng đầu dòng. Cắt đi là hoá đơn lệch một cột
	// so với thứ họ vừa xem thử trên màn hình.
	cn.HeaderInvoiceInfo = domain.StringOrNull(req.HeaderInvoiceInfo)
	cn.WifiInvoiceInfo = domain.StringOrNull(req.WifiInvoiceInfo)
	cn.FooterInvoiceInfo = domain.StringOrNull(req.FooterInvoiceInfo)

	if coPhamVi {
		m := *req.AreaScope
		cn.AreaScope = &m
	} else {
		cn.AreaScope = nil
	}

	if req.BranchType != nil {
		cn.BranchType = *req.BranchType
	}

	return nil
}

// chuanHoaToaDo đọc ô "Vị trí" theo khuôn "vĩ độ, kinh độ" của bản v2.
//
// Trả về chuỗi đã chuẩn hoá (đúng một dấu phẩy, đúng một khoảng trắng) để hai
// chi nhánh cùng một điểm không lưu thành hai chuỗi khác nhau. Rỗng vào thì
// rỗng ra — ô này tuỳ chọn.
func chuanHoaToaDo(s string) (string, error) {
	s = strings.TrimSpace(s)
	if s == "" {
		return "", nil
	}

	phan := strings.Split(s, ",")
	if len(phan) != 2 {
		return "", domain.ErrToaDoChiNhanhInvalid
	}

	// ParseFloat nhận cả "1e2" và "+10.8"; chặn trước bằng khuôn số thập phân
	// thường để thứ lưu xuống đúng là thứ Google Maps đưa ra.
	viDo, err := docToaDo(phan[0], 90)
	if err != nil {
		return "", err
	}
	kinhDo, err := docToaDo(phan[1], 180)
	if err != nil {
		return "", err
	}

	return viDo + ", " + kinhDo, nil
}

// soThapPhan — số thập phân thường, có thể mang dấu trừ. Không mũ, không dấu
// cộng, không khoảng trắng ở giữa.
var soThapPhan = regexp.MustCompile(`^-?\d{1,3}(\.\d+)?$`)

// docToaDo kiểm một nửa của cặp toạ độ và trả lại nó ở dạng đã cắt khoảng trắng.
//
// tran là biên tuyệt đối: 90 cho vĩ độ, 180 cho kinh độ.
func docToaDo(s string, tran float64) (string, error) {
	s = strings.TrimSpace(s)
	if !soThapPhan.MatchString(s) {
		return "", domain.ErrToaDoChiNhanhInvalid
	}

	v, err := strconv.ParseFloat(s, 64)
	if err != nil || v < -tran || v > tran {
		return "", domain.ErrToaDoChiNhanhInvalid
	}

	// "-0" và "-0.000" là cùng một điểm với "0" nhưng đọc như một toạ độ hỏng —
	// bản v2 cũng chặn đúng chỗ này.
	if v == 0 && strings.HasPrefix(s, "-") {
		return "", domain.ErrToaDoChiNhanhInvalid
	}

	return s, nil
}
