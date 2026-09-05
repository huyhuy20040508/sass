package service

import (
	"context"
	"fmt"
	"strings"
	"time"

	"sass-api/internal/chinhanh"
	"sass-api/internal/domain"
	"sass-api/internal/dto"
)

// ProductService định nghĩa nghiệp vụ sản phẩm.
type ProductService interface {
	List(ctx context.Context, f domain.ProductFilter) ([]domain.Product, int64, error)
	Get(ctx context.Context, id uint) (*domain.Product, error)
	GetBySlug(ctx context.Context, slug string) (*domain.Product, error)
	Create(ctx context.Context, req dto.ProductRequest) (*domain.Product, error)
	Update(ctx context.Context, id uint, req dto.ProductRequest) (*domain.Product, error)
	SetStatus(ctx context.Context, id uint, status string) (*domain.Product, error)
	// SetActive bật/tắt bán hàng bằng cờ, giữ nguyên mức "ngừng kinh doanh".
	SetActive(ctx context.Context, id uint, active bool) (*domain.Product, error)
	// DoiChoThuTu đưa mặt hàng lên trên hoặc xuống dưới một bậc trong danh sách.
	DoiChoThuTu(ctx context.Context, id uint, huong string) error
	// SapXepLai nhận nguyên một trình tự mới (kéo thả cả dòng tới chỗ khác).
	SapXepLai(ctx context.Context, ids []uint) error
	Duplicate(ctx context.Context, id uint) (*domain.Product, error)
	Delete(ctx context.Context, id uint) error
	// DeleteMany xoá nhiều sản phẩm trong một giao dịch, trả về số dòng đã xoá.
	DeleteMany(ctx context.Context, ids []uint) (int64, error)
	// DanhSachThe trả mọi thẻ hàng hóa của cửa hàng — nguồn gợi ý cho ô chọn thẻ.
	DanhSachThe(ctx context.Context) ([]domain.ProductTag, error)
}

type productService struct {
	repo         domain.ProductRepository
	categoryRepo domain.CategoryRepository
	// hanMuc xét hạn mức sản phẩm của hợp đồng. nil = máy chủ chưa nối được
	// control plane, khi đó không có hợp đồng nào đọc được nên không ép gì cả.
	hanMuc HanMucService
	// quyTac là quy tắc đánh số của cửa hàng: bật thì SKU tự sinh và ô mã ở màn
	// nhập khoá lại, tắt thì người dùng phải tự gõ như hiện nay.
	quyTac domain.QuyTacMaRepository
	// viTri để kiểm vị trí gán cho mặt hàng có thật và thuộc đúng cửa hàng này.
	viTri domain.ViTriRepository
	// donVi để kiểm đơn vị tính gán cho mặt hàng có thật và thuộc cửa hàng này.
	donVi domain.DonViTinhRepository
	// thuocTinh để tra TÊN giá trị thuộc tính (ghép tên biến thể) và để chặn
	// tổ hợp trỏ vào thuộc tính/giá trị không tồn tại.
	thuocTinh domain.ThuocTinhRepository
	// chiNhanh để kiểm chi nhánh gán cho mặt hàng có thật và thuộc cửa hàng này.
	chiNhanh domain.ChiNhanhRepository
}

func NewProductService(
	repo domain.ProductRepository,
	categoryRepo domain.CategoryRepository,
	hanMuc HanMucService,
	quyTac domain.QuyTacMaRepository,
	viTri domain.ViTriRepository,
	donVi domain.DonViTinhRepository,
	thuocTinh domain.ThuocTinhRepository,
	chiNhanh domain.ChiNhanhRepository,
) ProductService {
	return &productService{
		repo: repo, categoryRepo: categoryRepo,
		hanMuc: hanMuc, quyTac: quyTac, viTri: viTri,
		donVi: donVi, thuocTinh: thuocTinh, chiNhanh: chiNhanh,
	}
}

func (s *productService) List(ctx context.Context, f domain.ProductFilter) ([]domain.Product, int64, error) {
	if f.Page < 1 {
		f.Page = 1
	}
	if f.PageSize < 1 || f.PageSize > 100 {
		f.PageSize = 20
	}
	// Lọc theo danh mục thì gộp luôn sản phẩm của mọi danh mục con/cháu —
	// cây danh mục có thể 3 cấp (Câu lạc bộ → giải đấu → CLB), khách xem
	// trang "giải đấu" phải thấy áo của tất cả CLB thuộc giải.
	//
	// Lọc nhiều nhóm cùng lúc thì nở TỪNG nhóm rồi gộp lại — bản cũ cho tick
	// nhiều nhóm trong ô lọc.
	if len(f.CategoryIDs) > 0 || f.CategoryID != nil {
		goc := f.CategoryIDs
		if len(goc) == 0 {
			goc = []uint{*f.CategoryID}
		}
		if cats, err := s.categoryRepo.List(ctx, false); err == nil {
			daCo := make(map[uint]bool)
			gop := make([]uint, 0, len(goc))
			for _, id := range goc {
				for _, con := range descendantCategoryIDs(id, cats) {
					if !daCo[con] {
						daCo[con] = true
						gop = append(gop, con)
					}
				}
			}
			f.CategoryIDs = gop
		}
	}
	return s.repo.List(ctx, f)
}

// descendantCategoryIDs trả về id danh mục gốc kèm toàn bộ id con/cháu (BFS,
// không giới hạn độ sâu — phòng khi sau này cây sâu hơn 3 cấp).
func descendantCategoryIDs(rootID uint, cats []domain.Category) []uint {
	childrenOf := make(map[uint][]uint, len(cats))
	for _, c := range cats {
		if c.ParentID != nil {
			childrenOf[*c.ParentID] = append(childrenOf[*c.ParentID], c.ID)
		}
	}
	ids := []uint{rootID}
	for queue := []uint{rootID}; len(queue) > 0; {
		id := queue[0]
		queue = queue[1:]
		for _, child := range childrenOf[id] {
			ids = append(ids, child)
			queue = append(queue, child)
		}
	}
	return ids
}

func (s *productService) Get(ctx context.Context, id uint) (*domain.Product, error) {
	return s.repo.FindByID(ctx, id)
}

func (s *productService) GetBySlug(ctx context.Context, slug string) (*domain.Product, error) {
	p, err := s.repo.FindBySlug(ctx, slug)
	if err != nil {
		return nil, err
	}
	// Tăng lượt xem (bỏ qua lỗi để không ảnh hưởng response).
	_ = s.repo.IncrementView(ctx, p.ID)
	return p, nil
}

func (s *productService) Create(ctx context.Context, req dto.ProductRequest) (*domain.Product, error) {
	if err := s.validateRefs(ctx, req); err != nil {
		return nil, err
	}
	// SKU bỏ trống: sinh theo quy tắc mã hàng hoá. Chưa bật quy tắc thì vẫn bắt
	// nhập tay — không tự bịa một mã mà cửa hàng không chọn hình dạng.
	if strings.TrimSpace(req.SKU) == "" {
		ma, err := s.quyTac.SinhMa(ctx, domain.LoaiHangHoa, 0, func(ma string) (bool, error) {
			return s.repo.ExistsBySKU(ctx, ma, 0)
		})
		if err != nil {
			return nil, err
		}
		if ma == "" {
			return nil, domain.ErrSKUBatBuoc
		}
		req.SKU = ma
	}
	if err := s.checkUnique(ctx, req, 0); err != nil {
		return nil, err
	}
	// Hạn mức xét SAU cùng, ngay trước lượt ghi: người dùng phải biết form của
	// mình sai chỗ nào trước đã. Báo "hết hạn mức" cho một request lẽ ra bị từ
	// chối vì trùng SKU là đẩy họ đi nâng gói để sửa một lỗi gõ nhầm.
	if err := conChoTao(ctx, s.hanMuc, domain.HanMucSanPham); err != nil {
		return nil, err
	}
	tenGiaTri, err := s.tenGiaTriThuocTinh(ctx, deref(req.Variants))
	if err != nil {
		return nil, err
	}
	// Không khai thuế thì lấy mức MẶC ĐỊNH CỦA NHÓM (quy tắc bản cũ v2: thuế đi
	// theo nhóm hàng, mặt hàng chỉ sửa đè khi cần). Trang quản trị tự điền sẵn ô
	// này lúc chọn nhóm; đường API và nhập Excel thì nhờ nhánh dưới đây.
	if req.VAT == nil {
		if cat, err := s.categoryRepo.FindByID(ctx, req.CategoryID); err == nil {
			vat := cat.VAT
			req.VAT = &vat
		}
	}
	p := &domain.Product{}
	applyProductRequest(p, req, true)
	// Mặt hàng mới nằm ngay ĐẦU danh sách. Để 0 thì nó rơi xuống đáy và người
	// vừa thêm hàng phải lật tới trang cuối mới thấy thứ mình vừa khai.
	if thuTu, err := s.repo.ThuTuKeTiep(ctx); err == nil {
		p.Sort = thuTu
	}
	if err := s.repo.Create(ctx, p); err != nil {
		return nil, err
	}
	// Mặt hàng mới LUÔN được dựng biến thể, kể cả khi client không gửi khoá
	// `variants` — xem bienTheGuiLen.
	ds := bienTheGuiLen(deref(req.Variants))
	if err := s.repo.ReplaceVariants(ctx, p.ID, buildVariants(ds, req.SKU, tenGiaTri)); err != nil {
		return nil, err
	}
	if req.Images != nil {
		if err := s.repo.ReplaceImages(ctx, p.ID, buildImages(*req.Images)); err != nil {
			return nil, err
		}
	}
	if err := s.ghiChiNhanhVaThe(ctx, p.ID, req); err != nil {
		return nil, err
	}
	return s.repo.FindByID(ctx, p.ID)
}

// ghiChiNhanhVaThe ghi hai cụm đi kèm mặt hàng: chi nhánh quản lý và thẻ.
//
// Vắng khoá = màn hình gọi tới không nắm hai thứ ấy (lượt nhập Excel chẳng hạn)
// nên không được phép xoá cụm người khác đã khai. Cùng quy ước với `variants`
// và `images`.
func (s *productService) ghiChiNhanhVaThe(ctx context.Context, productID uint, req dto.ProductRequest) error {
	if req.ShopIDs != nil {
		if err := s.repo.ReplaceShops(ctx, productID, *req.ShopIDs); err != nil {
			return err
		}
	}
	if req.Tags != nil {
		if err := s.repo.ReplaceTags(ctx, productID, *req.Tags); err != nil {
			return err
		}
	}
	// Vị trí đi cùng nhóm này vì cùng một lý do: nó là dòng ở bảng nối, không
	// phải cột của mặt hàng (migration 0052). Quy ước con trỏ giữ nguyên như cũ
	// — vắng mặt = không đụng tới, 0 = gỡ kệ, >0 = xếp vào kệ ấy — chỉ khác chỗ
	// ghi: bảng nối theo CHI NHÁNH ĐANG LÀM VIỆC.
	if req.LocationID != nil {
		if err := s.repo.DatViTri(ctx, productID, req.LocationID); err != nil {
			return err
		}
	}

	return nil
}

func (s *productService) DanhSachThe(ctx context.Context) ([]domain.ProductTag, error) {
	return s.repo.DanhSachThe(ctx)
}

// deref mở con trỏ danh sách biến thể; nil -> danh sách rỗng.
func deref(v *[]dto.VariantRequest) []dto.VariantRequest {
	if v == nil {
		return nil
	}
	return *v
}

func (s *productService) Update(ctx context.Context, id uint, req dto.ProductRequest) (*domain.Product, error) {
	p, err := s.repo.FindByID(ctx, id)
	if err != nil {
		return nil, err
	}
	if err := s.validateRefs(ctx, req); err != nil {
		return nil, err
	}
	// SKU bỏ trống lúc SỬA = giữ mã cũ, không sinh mã mới: mã đã in trên tem và
	// trên phiếu nhập, tự đổi là hàng trong kho không tra ra được nữa.
	if strings.TrimSpace(req.SKU) == "" {
		req.SKU = p.SKU
	}
	if err := s.checkUnique(ctx, req, id); err != nil {
		return nil, err
	}
	tenGiaTri, err := s.tenGiaTriThuocTinh(ctx, deref(req.Variants))
	if err != nil {
		return nil, err
	}
	applyProductRequest(p, req, false)
	if err := s.repo.Update(ctx, p); err != nil {
		return nil, err
	}
	// Vắng khoá `variants` = màn hình không nắm được biến thể (chỉ bật/tắt trạng
	// thái chẳng hạn) -> không đụng tới. Có khoá mà rỗng thì vẫn phải còn dòng
	// mặc định, nếu không mặt hàng thành món không bán được.
	if req.Variants != nil {
		ds := bienTheGuiLen(*req.Variants)
		if err := s.repo.ReplaceVariants(ctx, p.ID, buildVariants(ds, req.SKU, tenGiaTri)); err != nil {
			return nil, err
		}
	}
	if req.Images != nil {
		if err := s.repo.ReplaceImages(ctx, p.ID, buildImages(*req.Images)); err != nil {
			return nil, err
		}
	}
	if err := s.ghiChiNhanhVaThe(ctx, p.ID, req); err != nil {
		return nil, err
	}
	return s.repo.FindByID(ctx, p.ID)
}

// DoiChoThuTu đưa mặt hàng lên/xuống một bậc trong thứ tự người bán tự xếp.
func (s *productService) DoiChoThuTu(ctx context.Context, id uint, huong string) error {
	if huong != "up" && huong != "down" {
		return domain.ErrHuongKhongHopLe
	}
	return s.repo.DoiChoThuTu(ctx, id, huong)
}

// SapXepLai ghi lại trình tự sau một lượt kéo thả.
//
// Lọc trùng ngay tại đây: id lặp làm tầng dưới phát một số thứ tự hai lần, và
// mặt hàng cuối danh sách mất chỗ.
func (s *productService) SapXepLai(ctx context.Context, ids []uint) error {
	daCo := make(map[uint]bool, len(ids))
	sach := make([]uint, 0, len(ids))
	for _, id := range ids {
		if id == 0 || daCo[id] {
			continue
		}
		daCo[id] = true
		sach = append(sach, id)
	}
	if len(sach) < 2 {
		// Một dòng thì không có gì để xếp lại — và cũng không đáng ghi vào sổ.
		return nil
	}
	return s.repo.SapXepLai(ctx, sach)
}

// SetStatus đổi trạng thái kinh doanh của sản phẩm — chỉ ghi status + is_active,
// không đụng tới biến thể, ảnh hay giá.
func (s *productService) SetStatus(ctx context.Context, id uint, status string) (*domain.Product, error) {
	if !domain.IsValidProductStatus(status) {
		return nil, domain.ErrProductStatusInvalid
	}
	if err := s.repo.SetStatus(ctx, id, status); err != nil {
		return nil, err
	}
	return s.repo.FindByID(ctx, id)
}

// SetActive bật/tắt bán hàng bằng CỜ — công tắc trạng thái ngoài bảng danh sách
// chỉ có hai nấc, mà trạng thái có ba mức.
//
// Bật thì cho bán. Tắt thì "tạm ẩn", TRỪ mặt hàng đang ngừng kinh doanh: người
// dùng gạt công tắc xuống không có ý nâng nó lên lại thành tạm ẩn. Cùng quy tắc
// với resolveProductStatus ở luồng lưu cả mặt hàng.
func (s *productService) SetActive(ctx context.Context, id uint, active bool) (*domain.Product, error) {
	p, err := s.repo.FindByID(ctx, id)
	if err != nil {
		return nil, err
	}

	status := domain.ProductStatusActive
	if !active {
		status = domain.ProductStatusHidden
		if p.Status == domain.ProductStatusDiscontinued {
			status = p.Status
		}
	}
	return s.SetStatus(ctx, id, status)
}

// DeleteMany xoá hàng loạt. Lọc trùng và bỏ id rác ngay tại đây để tầng dưới chỉ
// nhận danh sách sạch.
func (s *productService) DeleteMany(ctx context.Context, ids []uint) (int64, error) {
	seen := make(map[uint]bool, len(ids))
	clean := make([]uint, 0, len(ids))
	for _, id := range ids {
		if id == 0 || seen[id] {
			continue
		}
		seen[id] = true
		clean = append(clean, id)
	}
	if len(clean) == 0 {
		return 0, nil
	}
	return s.repo.DeleteMany(ctx, clean)
}

// checkUnique kiểm tra slug và SKU trước khi ghi.
//
// Cả hai cột đều có UNIQUE index. Không kiểm tra ở đây thì lỗi rơi xuống MySQL,
// nhảy vào nhánh mặc định của handler và người dùng nhận về "Đã có lỗi xảy ra,
// vui lòng thử lại" — không nói được là trùng cái gì, cũng không sửa được.
func (s *productService) checkUnique(ctx context.Context, req dto.ProductRequest, excludeID uint) error {
	// TÊN xét TRƯỚC slug, dù cả hai đều hỏng cùng lúc: slug sinh ra TỪ tên, nên
	// đặt trùng tên thì bao giờ cũng vấp cả hai. Báo "trùng slug" trước là nói
	// về một thứ người dùng không nhìn thấy và không gõ, còn "trùng tên" thì
	// chỉ thẳng vào ô họ vừa điền.
	exists, err := s.repo.ExistsByName(ctx, strings.TrimSpace(req.Name), excludeID)
	if err != nil {
		return err
	}
	if exists {
		return domain.ErrProductTrungTen
	}

	exists, err = s.repo.ExistsBySlug(ctx, req.Slug, excludeID)
	if err != nil {
		return err
	}
	if exists {
		return domain.ErrSlugExists
	}

	exists, err = s.repo.ExistsBySKU(ctx, req.SKU, excludeID)
	if err != nil {
		return err
	}
	if exists {
		return domain.ErrSKUExists
	}

	return nil
}

// boDau đổi chữ tiếng Việt có dấu sang ASCII, giữ nguyên mọi ký tự khác.
//
// Bảng tra viết tay thay vì kéo thêm golang.org/x/text: chỉ dùng cho một việc
// duy nhất (đặt mã hàng), và bộ ký tự tiếng Việt là cố định.
func boDau(s string) string {
	var b strings.Builder
	b.Grow(len(s))
	for _, r := range s {
		if thay, ok := banChuCaiKhongDau[r]; ok {
			b.WriteRune(thay)
			continue
		}
		b.WriteRune(r)
	}
	return b.String()
}

// banChuCaiKhongDau dựng một lần lúc nạp gói.
var banChuCaiKhongDau = dungBangKhongDau()

func dungBangKhongDau() map[rune]rune {
	nhom := map[rune]string{
		'a': "áàạảãâấầậẩẫăắằặẳẵ",
		'e': "éèẹẻẽêếềệểễ",
		'i': "íìịỉĩ",
		'o': "óòọỏõôốồộổỗơớờợởỡ",
		'u': "úùụủũưứừựửữ",
		'y': "ýỳỵỷỹ",
		'd': "đ",
	}
	out := make(map[rune]rune, 200)
	for thay, ds := range nhom {
		for _, r := range ds {
			out[r] = thay
			// Bản viết hoa của chính chữ ấy.
			for _, hoa := range strings.ToUpper(string(r)) {
				out[hoa] = []rune(strings.ToUpper(string(thay)))[0]
			}
		}
	}
	return out
}

// maBienThe đặt mã cho biến thể chưa có mã riêng: <mã cha>-<tên biến thể>.
//
// Cùng công thức với trang quản trị, để mã của hàng thêm từ màn hình và hàng
// thêm qua API không thành hai kiểu.
func maBienThe(skuCha string, ten string) string {
	if strings.TrimSpace(skuCha) == "" {
		return ""
	}
	if ten = strings.TrimSpace(ten); ten == "" {
		return strings.ToUpper(skuCha)
	}
	// Dấu chấm giữa của tên biến thể ("128GB · Đen") không thuộc về một mã hàng —
	// đổi thành gạch nối rồi mới ghép.
	ten = strings.ReplaceAll(ten, "·", "-")
	// Bỏ dấu: mã hàng bị gõ lại bằng tay ở quầy, bị in lên tem, bị dán vào ô tìm
	// kiếm — "ĐEN" và "DEN" là hai chuỗi khác nhau với máy quét lẫn bàn phím.
	// Cùng công thức với trang quản trị (ProductController::variantSku).
	ten = boDau(ten)
	ma := strings.ToUpper(strings.Join([]string{skuCha, ten}, "-"))
	ma = strings.ReplaceAll(ma, " ", "-")
	// Ghép xong dễ ra "SP-01--DEN" khi tên có sẵn gạch — gom lại thành một.
	for strings.Contains(ma, "--") {
		ma = strings.ReplaceAll(ma, "--", "-")
	}
	return strings.Trim(ma, "-")
}

// tenBienThe ghép tên biến thể từ tổ hợp thuộc tính: "128GB · Đen".
//
// Tên do người khai gửi lên thì tôn trọng; bỏ trống mới ghép hộ. Thứ tự các
// chiều giữ đúng thứ tự gửi lên — người khai bày "Dung lượng" trước "Màu" thì
// tên đọc ra cũng vậy.
func tenBienThe(v dto.VariantRequest, tenGiaTri map[uint]string) string {
	if ten := strings.TrimSpace(v.Name); ten != "" {
		return ten
	}
	phan := make([]string, 0, len(v.Attributes))
	for _, a := range v.Attributes {
		if ten := strings.TrimSpace(tenGiaTri[a.ValueID]); ten != "" {
			phan = append(phan, ten)
		}
	}
	return strings.Join(phan, " · ")
}

// buildImages chuyển dữ liệu request sang entity ảnh.
func buildImages(reqs []dto.ImageRequest) []domain.ProductImage {
	out := make([]domain.ProductImage, 0, len(reqs))
	for i, img := range reqs {
		sort := img.SortOrder
		if sort == 0 {
			sort = i
		}
		out = append(out, domain.ProductImage{
			ID:        img.ID,
			URL:       img.URL,
			Alt:       img.Alt,
			SortOrder: sort,
			IsPrimary: img.IsPrimary,
		})
	}
	return out
}

// chuoiHoacNil đổi chuỗi rỗng (sau khi bỏ khoảng trắng) thành nil.
//
// Dùng cho các cột vừa UNIQUE vừa cho phép bỏ trống: MySQL coi mỗi NULL là một
// giá trị riêng nên nhiều dòng cùng bỏ trống vẫn chèn được, còn chuỗi rỗng thì
// chỉ có đúng một dòng được mang.
func chuoiHoacNil(s string) *string {
	s = strings.TrimSpace(s)
	if s == "" {
		return nil
	}
	return &s
}

// buildVariants chuyển dữ liệu request sang entity biến thể.
//
// StockQuantity cố ý để zero-value: ReplaceVariants bỏ qua cột này nên dòng
// thêm mới lấy DEFAULT 0 của DB, dòng cũ giữ nguyên tồn kho đang có.
// buildVariants dựng biến thể; skuCha điền cho dòng chưa có mã riêng, tenGiaTri
// là bảng tra id giá trị thuộc tính -> tên (để ghép tên biến thể).
func buildVariants(reqs []dto.VariantRequest, skuCha string, tenGiaTri map[uint]string) []domain.ProductVariant {
	out := make([]domain.ProductVariant, 0, len(reqs))
	for i, v := range reqs {
		ten := tenBienThe(v, tenGiaTri)

		ma := strings.TrimSpace(v.SKU)
		if ma == "" {
			ma = maBienThe(skuCha, ten)
		}

		pos := i
		if v.Pos != nil {
			pos = *v.Pos
		}

		tohop := make([]domain.ProductVariantAttribute, 0, len(v.Attributes))
		for _, a := range v.Attributes {
			tohop = append(tohop, domain.ProductVariantAttribute{
				AttributeID: a.AttributeID,
				ValueID:     a.ValueID,
			})
		}

		out = append(out, domain.ProductVariant{
			ID:  v.ID,
			SKU: ma,
			// Mã vạch để trống phải vào DB là NULL chứ không phải chuỗi rỗng: cột
			// UNIQUE, mà MySQL chỉ coi các NULL là khác nhau. Ghi "" thì biến thể
			// thứ hai chưa dán mã đụng ràng buộc với biến thể thứ nhất.
			Barcode: chuoiHoacNil(v.Barcode),
			Name:    ten,
			// Hàng đơn: dòng DUY NHẤT (không chiều thuộc tính nào) luôn là mặc
			// định — bất biến "mọi mặt hàng luôn có ít nhất một dòng biến thể".
			// Hàng nhiều biến thể thì nghe theo cờ người khai tick; không tick
			// dòng nào thì không dòng nào mặc định, và màn bán hàng bắt chọn.
			IsDefault:  len(tohop) == 0 || boolOrDefault(v.IsDefault, false),
			Pos:        pos,
			Attributes: tohop,
			Price:      v.Price,
			CostPrice:  v.CostPrice,
			WeightGram: v.WeightGram,
			Image:      v.Image,
			IsActive:   boolOrDefault(v.IsActive, true),
		})
	}
	return out
}

func (s *productService) Duplicate(ctx context.Context, id uint) (*domain.Product, error) {
	orig, err := s.repo.FindByID(ctx, id)
	if err != nil {
		return nil, err
	}
	// Nhân bản cũng là một sản phẩm mới trong sổ, nên cũng ăn một chỗ của hạn
	// mức. Đây là đường dễ quên nhất: nó không đi qua Create ở trên, và cái nút
	// "Nhân bản" là cách nhanh nhất để thêm hàng loạt.
	if err := conChoTao(ctx, s.hanMuc, domain.HanMucSanPham); err != nil {
		return nil, err
	}

	now := time.Now().Unix()
	newSlug := fmt.Sprintf("%s-copy-%d", orig.Slug, now)
	newSKU := fmt.Sprintf("%s-COPY-%d", orig.SKU, now%10000)
	newName := fmt.Sprintf("%s (Bản sao)", orig.Name)

	newProduct := &domain.Product{
		CategoryID: orig.CategoryID,
		// Vị trí chép riêng SAU khi tạo (xem cuối hàm): nó là dòng ở bảng nối,
		// không phải một cột của mặt hàng.
		Name:            newName,
		Slug:            newSlug,
		SKU:             newSKU,
		Description:     orig.Description,
		UnitID:          orig.UnitID,
		UnitConversions: orig.UnitConversions,
		VAT:             orig.VAT,
		BasePrice:       orig.BasePrice,
		SalePrice:       orig.SalePrice,
		CostPrice:       orig.CostPrice,
		Thumbnail:       orig.Thumbnail,
		IsMultiVariant:  orig.IsMultiVariant,
		PrintLabel:      orig.PrintLabel,
		IsStockDeducted: orig.IsStockDeducted,
		IsSerial:        orig.IsSerial,
		// Bản sao luôn ở tạm ẩn: nó còn thiếu ảnh riêng, tên riêng, giá riêng —
		// bày ra ngay là khách thấy hai sản phẩm trùng tên nhau.
		Status:     domain.ProductStatusHidden,
		IsActive:   false,
		IsFeatured: orig.IsFeatured,
	}

	if err := s.repo.Create(ctx, newProduct); err != nil {
		return nil, err
	}

	// Chi nhánh và thẻ chép sang y nguyên: nhân bản là để khai nhanh một món
	// tương tự, mà món tương tự thì bán ở đúng những chi nhánh ấy và đeo đúng
	// những thẻ ấy.
	if len(orig.Shops) > 0 {
		ids := make([]uint, 0, len(orig.Shops))
		for _, cn := range orig.Shops {
			ids = append(ids, cn.ID)
		}
		if err := s.repo.ReplaceShops(ctx, newProduct.ID, ids); err != nil {
			return nil, err
		}
	}
	if len(orig.Tags) > 0 {
		ten := make([]string, 0, len(orig.Tags))
		for _, t := range orig.Tags {
			ten = append(ten, t.Name)
		}
		if err := s.repo.ReplaceTags(ctx, newProduct.ID, ten); err != nil {
			return nil, err
		}
	}

	// Kệ chép sang cùng lý do: món tương tự thì gần như luôn nằm cùng chỗ.
	// `orig.LocationID` là kệ TẠI CHI NHÁNH ĐANG LÀM VIỆC (repo điền lúc đọc),
	// và DatViTri cũng ghi vào đúng chi nhánh ấy — hai đầu khớp nhau.
	if orig.LocationID != nil {
		if err := s.repo.DatViTri(ctx, newProduct.ID, orig.LocationID); err != nil {
			return nil, err
		}
	}

	if len(orig.Variants) > 0 {
		// Bản sao KHÔNG kế thừa tồn kho: hàng không tự nhân đôi trong kho, phải
		// nhập hàng cho sản phẩm mới thì mới có tồn.
		newVariants := make([]domain.ProductVariant, 0, len(orig.Variants))
		for _, v := range orig.Variants {
			// Tổ hợp thuộc tính chép sang NGUYÊN VĂN (bỏ id dòng cũ) — bản sao
			// phải mang đúng bộ chiều của bản gốc, không thì tên biến thể còn mà
			// tổ hợp thì trống.
			tohop := make([]domain.ProductVariantAttribute, 0, len(v.Attributes))
			for _, a := range v.Attributes {
				tohop = append(tohop, domain.ProductVariantAttribute{
					AttributeID: a.AttributeID,
					ValueID:     a.ValueID,
				})
			}
			newVariants = append(newVariants, domain.ProductVariant{
				SKU:        fmt.Sprintf("%s-COPY-%d", v.SKU, now%10000),
				Name:       v.Name,
				IsDefault:  v.IsDefault,
				Pos:        v.Pos,
				Attributes: tohop,
				Price:      v.Price,
				CostPrice:  v.CostPrice,
				WeightGram: v.WeightGram,
				Image:      v.Image,
				IsActive:   v.IsActive,
			})
		}
		_ = s.repo.ReplaceVariants(ctx, newProduct.ID, newVariants)
	}

	if len(orig.Images) > 0 {
		newImages := make([]domain.ProductImage, 0, len(orig.Images))
		for _, img := range orig.Images {
			newImages = append(newImages, domain.ProductImage{
				URL:       img.URL,
				Alt:       img.Alt,
				SortOrder: img.SortOrder,
				IsPrimary: img.IsPrimary,
			})
		}
		_ = s.repo.ReplaceImages(ctx, newProduct.ID, newImages)
	}

	return s.repo.FindByID(ctx, newProduct.ID)
}

func (s *productService) Delete(ctx context.Context, id uint) error {
	if _, err := s.repo.FindByID(ctx, id); err != nil {
		return err
	}
	return s.repo.Delete(ctx, id)
}

// validateRefs kiểm tra category_id và location_id tồn tại.
func (s *productService) validateRefs(ctx context.Context, req dto.ProductRequest) error {
	if _, err := s.categoryRepo.FindByID(ctx, req.CategoryID); err != nil {
		return err // ErrNotFound -> handler trả 404
	}

	// Vị trí là tuỳ chọn: nil = không đụng tới, 0 = gỡ ra. Chỉ id > 0 mới phải
	// tra — và tra qua repo có bộ lọc tenant, nên id của cửa hàng khác rơi vào
	// ErrNotFound chứ không gán trộm được.
	if req.LocationID != nil && *req.LocationID > 0 {
		ke, err := s.viTri.FindByID(ctx, *req.LocationID)
		if err != nil {
			return err
		}
		// Kệ phải thuộc CHI NHÁNH ĐANG LÀM VIỆC. Kệ của quận khác là một chỗ vật
		// lý người đứng đây không với tới được — xếp hàng vào đó thì lượt soạn
		// hàng nào cũng dẫn tới một cái kho sai.
		//
		// Không đứng ở chi nhánh nào thì bỏ qua: lúc ấy DatViTri cũng không ghi
		// gì cả, nên không có gì để kiểm.
		if shopID, ok := chinhanh.ID(ctx); ok && ke.ShopID != shopID {
			return &LoiTheoO{Fields: map[string]string{
				"location_id": "Vị trí này thuộc chi nhánh khác — chọn một vị trí của chi nhánh đang làm việc",
			}}
		}
	}

	// Đơn vị tính cùng quy ước: nil = không đụng, 0 = gỡ ra, chỉ id > 0 mới tra.
	if req.UnitID != nil && *req.UnitID > 0 {
		if _, err := s.donVi.FindByID(ctx, *req.UnitID); err != nil {
			return err
		}
	}

	// Chi nhánh quản lý mặt hàng: từng id phải là chi nhánh CÓ THẬT của chính
	// cửa hàng này. Tra qua repo có bộ lọc tenant nên id của cửa hàng khác rơi
	// vào ErrNotFound chứ không gán trộm được.
	//
	// Danh sách rỗng KHÔNG phải lỗi: đó là "mọi chi nhánh".
	if req.ShopIDs != nil {
		for _, id := range *req.ShopIDs {
			if _, err := s.chiNhanh.FindByID(ctx, id); err != nil {
				return err
			}
		}
	}

	// Quy đổi đơn vị: mỗi đơn vị một dòng, không trùng đơn vị tính chính, số
	// lượng > 0. Và đơn vị được khai phải CÓ THẬT trong cửa hàng này.
	if req.UnitConversions != nil {
		ds := make(domain.DanhSachQuyDoi, 0, len(*req.UnitConversions))
		for _, q := range *req.UnitConversions {
			if _, err := s.donVi.FindByID(ctx, q.UnitID); err != nil {
				return err
			}
			ds = append(ds, domain.QuyDoiDonVi{UnitID: q.UnitID, Quantity: q.Quantity})
		}
		var donViChinh uint
		if req.UnitID != nil {
			donViChinh = *req.UnitID
		}
		if err := domain.KiemTraQuyDoi(ds, donViChinh); err != nil {
			return err
		}
	}

	return nil
}

// tenGiaTriThuocTinh dựng bảng tra id giá trị -> tên, đồng thời KIỂM tổ hợp
// người dùng gửi lên.
//
// Đọc qua repository nên bộ lọc tenant tự chèn: id giá trị của cửa hàng khác
// không có trong bảng tra, và lượt lưu bị chặn ở dưới thay vì âm thầm dựng ra
// một biến thể mang tên rỗng.
func (s *productService) tenGiaTriThuocTinh(ctx context.Context, variants []dto.VariantRequest) (map[uint]string, error) {
	canTra := false
	for _, v := range variants {
		if len(v.Attributes) > 0 {
			canTra = true
			break
		}
	}
	if !canTra {
		return map[uint]string{}, nil
	}

	// Lấy CẢ thuộc tính đang tắt: tắt một thuộc tính nghĩa là thôi bày nó ra lúc
	// khai hàng mới, không có nghĩa là hàng cũ mang nó thành hàng hỏng — sửa lại
	// giá bán của một mặt hàng như thế không được phép gãy.
	ds, err := s.thuocTinh.List(ctx, domain.ThuocTinhFilter{})
	if err != nil {
		return nil, err
	}

	ten := make(map[uint]string)
	thuoc := make(map[uint]uint) // id giá trị -> id thuộc tính của nó
	for _, tt := range ds {
		for _, gt := range tt.GiaTri {
			ten[gt.ID] = gt.Name
			thuoc[gt.ID] = tt.ID
		}
	}

	for _, v := range variants {
		daDung := make(map[uint]bool, len(v.Attributes))
		for _, a := range v.Attributes {
			// Giá trị phải có thật VÀ phải thuộc đúng thuộc tính được khai kèm —
			// gửi lệch một cặp là biến thể mang tên "128GB" dưới nhãn "Màu".
			if thuoc[a.ValueID] == 0 || thuoc[a.ValueID] != a.AttributeID {
				return nil, domain.ErrBienTheSaiThuocTinh
			}
			if daDung[a.AttributeID] {
				return nil, domain.ErrBienTheTrungThuocTinh
			}
			daDung[a.AttributeID] = true
		}
	}

	return ten, nil
}

// bienTheGuiLen chuẩn hoá danh sách biến thể trước khi ghi.
//
// Giữ BẤT BIẾN "mọi mặt hàng luôn có ít nhất một dòng biến thể": màn hình gửi
// lên mảng rỗng (hoặc hàng đơn không khai dòng nào) thì dựng hộ một dòng mặc
// định mang mã của chính mặt hàng. Không có dòng nào thì mặt hàng ấy không nhập
// kho được, không bán được, và mọi chứng từ trỏ vào nó đều 422.
func bienTheGuiLen(reqs []dto.VariantRequest) []dto.VariantRequest {
	if len(reqs) > 0 {
		return reqs
	}
	return []dto.VariantRequest{{}}
}

// applyProductRequest gán dữ liệu từ request vào entity.
// isCreate=true chỉ khi tạo mới (đặt mặc định cho các cờ boolean khi nil).
func applyProductRequest(p *domain.Product, req dto.ProductRequest, isCreate bool) {
	p.CategoryID = req.CategoryID
	// VỊ TRÍ KHÔNG GÁN Ở ĐÂY NỮA. Từ migration 0052 nó nằm ở bảng nối
	// `product_shop_locations` (mỗi chi nhánh một kệ), nên `p.LocationID` chỉ là
	// một trường CHỈ ĐỌC do truy vấn con điền vào. Ghi vào nó ở đây thì lượt Lưu
	// im lặng không đổi gì — đường ghi thật là repo.DatViTri, gọi ngay sau lượt
	// ghi mặt hàng.
	// Đơn vị tính cùng quy ước con trỏ với vị trí: vắng mặt = giữ nguyên,
	// 0 = gỡ ra, >0 = gán.
	if req.UnitID != nil {
		if *req.UnitID == 0 {
			p.UnitID = nil
		} else {
			id := *req.UnitID
			p.UnitID = &id
		}
	}
	p.Name = req.Name
	p.Slug = req.Slug
	p.SKU = req.SKU
	p.Description = req.Description
	if req.VAT != nil {
		p.VAT = *req.VAT
	}
	p.BasePrice = req.BasePrice
	p.SalePrice = req.SalePrice
	p.CostPrice = req.CostPrice
	p.Thumbnail = req.Thumbnail
	// Vắng khoá = không đụng tới (màn hình nào không dựng khối quy đổi thì lượt
	// Lưu của nó không được phép xoá cụm người khác đã khai).
	if req.UnitConversions != nil {
		ds := make(domain.DanhSachQuyDoi, 0, len(*req.UnitConversions))
		for _, q := range *req.UnitConversions {
			ds = append(ds, domain.QuyDoiDonVi{UnitID: q.UnitID, Quantity: q.Quantity})
		}
		p.UnitConversions = ds
	}
	// Hàng nhiều biến thể: người khai nói rõ thì nghe theo; không nói thì suy từ
	// danh sách biến thể gửi kèm — có dòng nào mang tổ hợp thuộc tính nghĩa là
	// hàng nhiều biến thể.
	if req.IsMultiVariant != nil {
		p.IsMultiVariant = *req.IsMultiVariant
	} else if req.Variants != nil {
		p.IsMultiVariant = false
		for _, v := range *req.Variants {
			if len(v.Attributes) > 0 {
				p.IsMultiVariant = true
				break
			}
		}
	}

	if isCreate {
		p.IsFeatured = boolOrDefault(req.IsFeatured, false)
		// Mặc định của bản cũ: in tem và trừ kho bật sẵn, seri tắt.
		p.PrintLabel = boolOrDefault(req.PrintLabel, true)
		p.IsStockDeducted = boolOrDefault(req.IsStockDeducted, true)
		p.IsSerial = boolOrDefault(req.IsSerial, false)
	} else {
		p.IsFeatured = boolOrDefault(req.IsFeatured, p.IsFeatured)
		p.PrintLabel = boolOrDefault(req.PrintLabel, p.PrintLabel)
		p.IsStockDeducted = boolOrDefault(req.IsStockDeducted, p.IsStockDeducted)
		p.IsSerial = boolOrDefault(req.IsSerial, p.IsSerial)
	}

	// Trạng thái là nguồn sự thật; is_active chỉ là bản rút gọn của nó để các
	// truy vấn bán hàng lọc cho nhanh. Luôn suy ra, không bao giờ nhận thẳng từ
	// request — nhận cả hai là có ngày chúng lệch nhau.
	p.Status = resolveProductStatus(req, p, isCreate)
	p.IsActive = p.Status == domain.ProductStatusActive
}

// resolveProductStatus chọn trạng thái cuối cùng cho sản phẩm.
//
// Ưu tiên trường `status` mới. Vẫn đọc `is_active` để tương thích ngược: bản
// quản trị cũ (và các script gọi API sẵn có) chỉ biết gửi cờ bật/tắt.
func resolveProductStatus(req dto.ProductRequest, p *domain.Product, isCreate bool) string {
	if domain.IsValidProductStatus(req.Status) {
		return req.Status
	}

	current := p.Status
	if isCreate || !domain.IsValidProductStatus(current) {
		current = domain.ProductStatusActive
	}

	if req.IsActive == nil {
		return current
	}
	if *req.IsActive {
		return domain.ProductStatusActive
	}
	// Tắt hiển thị mà đang ngừng kinh doanh thì giữ nguyên — người dùng chỉ gửi
	// cờ cũ, không có ý hạ cấp "ngừng kinh doanh" xuống thành "tạm ẩn".
	if current == domain.ProductStatusDiscontinued {
		return current
	}
	return domain.ProductStatusHidden
}
