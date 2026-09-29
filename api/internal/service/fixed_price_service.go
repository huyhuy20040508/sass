package service

import (
	"context"
	"sort"
	"strings"
	"time"

	"sass-api/internal/domain"
	"sass-api/internal/dto"
)

// FixedPriceService — khuyến mại đồng giá (khuôn crm/fixed-price của v2).
//
// Hai phần: QUẢN TRỊ (màn CRM) và TÍNH GIÁ ở quầy. Tính giá chỉ có MỘT hàm
// (tinh) dùng chung cho lượt xem trước và lượt chốt đơn — tính hai nơi là sớm
// muộn số trên màn quầy lệch số máy chủ thu.
type FixedPriceService interface {
	List(ctx context.Context, f domain.FixedPriceFilter) ([]dto.FixedPriceResponse, int64, error)
	Codes(ctx context.Context) ([]string, error)
	Get(ctx context.Context, id uint) (*dto.FixedPriceResponse, error)
	Save(ctx context.Context, id uint, req dto.FixedPriceRequest) (*dto.FixedPriceResponse, error)
	SetStatus(ctx context.Context, id uint, on bool) error
	// HuyDuyet đưa chương trình đã duyệt về Lưu tạm — bắt buộc trước khi sửa.
	HuyDuyet(ctx context.Context, id uint) error
	Delete(ctx context.Context, id uint) error

	// XemTruoc: chương trình nào giỏ này đủ điều kiện, và nếu chọn ChonIDs thì
	// giá từng món, hàng tặng ra sao.
	XemTruoc(ctx context.Context, req dto.POSDongGiaRequest) (*dto.POSDongGiaResponse, error)
	// QuaCanKhoa: biến thể hàng tặng của các chương trình đã chọn — lượt chốt
	// đơn phải khoá cả chúng cùng hàng bán để trừ kho đúng.
	QuaCanKhoa(ctx context.Context, chon []uint) ([]uint, error)
	// ApDung chạy BÊN TRONG giao dịch chốt đơn: đổi giá các món bị phủ ngay
	// trong found và trả về hàng tặng phải thêm.
	ApDung(ctx context.Context, chon []uint, found map[uint]domain.CheckoutVariant, lines []domain.CheckoutLine) (*KetQuaDongGia, error)
}

// KetQuaDongGia — kết quả tính của một giỏ.
type KetQuaDongGia struct {
	// DuDieuKien: chương trình (cho quầy) có ít nhất một dòng đủ số lượng.
	DuDieuKien []domain.FixedPrice
	// Gia: biến thể → (giá đồng giá, dòng đồng giá nào).
	Gia map[uint]GiaDongGia
	// Qua: hàng tặng của các dòng đang được áp.
	Qua []QuaDongGia
}

type GiaDongGia struct {
	Price    float64
	DetailID uint
	TenCT    string
}

type QuaDongGia struct {
	VariantID uint
	Quantity  int
	DetailID  uint
}

type fixedPriceService struct {
	repo         domain.FixedPriceRepository
	categoryRepo domain.CategoryRepository
	orderRepo    domain.OrderRepository
}

func NewFixedPriceService(repo domain.FixedPriceRepository, categoryRepo domain.CategoryRepository, orderRepo domain.OrderRepository) FixedPriceService {
	return &fixedPriceService{repo: repo, categoryRepo: categoryRepo, orderRepo: orderRepo}
}

// ---------- Quản trị ----------

func (s *fixedPriceService) Codes(ctx context.Context) ([]string, error) {
	return s.repo.Codes(ctx)
}

func (s *fixedPriceService) List(ctx context.Context, f domain.FixedPriceFilter) ([]dto.FixedPriceResponse, int64, error) {
	if f.Page < 1 {
		f.Page = 1
	}
	items, total, err := s.repo.List(ctx, f)
	if err != nil {
		return nil, 0, err
	}

	return s.toResponses(ctx, items), total, nil
}

func (s *fixedPriceService) Get(ctx context.Context, id uint) (*dto.FixedPriceResponse, error) {
	f, err := s.repo.FindByID(ctx, id)
	if err != nil {
		return nil, err
	}
	res := s.toResponses(ctx, []domain.FixedPrice{*f})[0]

	return &res, nil
}

func (s *fixedPriceService) Save(ctx context.Context, id uint, req dto.FixedPriceRequest) (*dto.FixedPriceResponse, error) {
	f := &domain.FixedPrice{}
	if id > 0 {
		cu, err := s.repo.FindByID(ctx, id)
		if err != nil {
			return nil, err
		}
		// Đã duyệt thì phải Huỷ duyệt trước — như v2, để không ai sửa lén một
		// chương trình quầy đang dùng.
		if cu.Approved {
			return nil, loiO(map[string]string{"approved": "Chương trình đang được duyệt. Bạn phải Huỷ duyệt trước khi sửa."})
		}
		f = cu
	} else {
		// Mã do HỆ THỐNG cấp, nối tiếp theo cửa hàng — người dùng không gõ mã.
		ma, err := s.repo.MaKeTiep(ctx)
		if err != nil {
			return nil, err
		}
		f.Code = ma
	}

	if err := dienFixedPrice(f, req); err != nil {
		return nil, err
	}
	if f.Status {
		if err := s.kiemTrung(ctx, f); err != nil {
			return nil, err
		}
	}
	if err := s.repo.Save(ctx, f); err != nil {
		return nil, err
	}

	return s.Get(ctx, f.ID)
}

// dienFixedPrice đổ yêu cầu vào entity và kiểm các ràng buộc phụ thuộc lẫn nhau.
func dienFixedPrice(f *domain.FixedPrice, req dto.FixedPriceRequest) error {
	loi := map[string]string{}

	f.Name = strings.TrimSpace(req.Name)
	f.Description = strings.TrimSpace(req.Description)
	f.Type = req.Type
	f.Status = boolOrDefault(req.Status, true)
	f.Approved = req.Approved
	f.NoTimeLimit = req.NoTimeLimit
	f.StartDate, f.EndDate = nil, nil
	if !req.NoTimeLimit {
		tu, e1 := time.ParseInLocation("2006-01-02", req.StartDate, time.Local)
		den, e2 := time.ParseInLocation("2006-01-02", req.EndDate, time.Local)
		switch {
		case e1 != nil || e2 != nil:
			loi["start_date"] = "Chọn ngày áp dụng, hoặc tick Không có giới hạn thời gian"
		case den.Before(tu):
			loi["end_date"] = "Đến ngày phải sau hoặc bằng Từ ngày"
		default:
			f.StartDate, f.EndDate = &tu, &den
		}
	}
	f.DaysOfWeek = ghepThu(req.DaysOfWeek)

	f.AllShops = req.AllShops
	f.ShopIDs = nil
	if !req.AllShops {
		seen := map[uint]bool{}
		for _, id := range req.ShopIDs {
			if id > 0 && !seen[id] {
				seen[id] = true
				f.ShopIDs = append(f.ShopIDs, id)
			}
		}
		if len(f.ShopIDs) == 0 {
			loi["shop_ids"] = "Chọn chi nhánh áp dụng"
		}
	}

	f.Details = nil
	daCo := map[uint]bool{}
	for _, d := range req.Details {
		if daCo[d.ObjectID] {
			loi["details"] = "Món hàng hoặc nhóm hàng bị trùng"
			continue
		}
		daCo[d.ObjectID] = true
		dong := domain.FixedPriceDetail{ObjectID: d.ObjectID, Quantity: d.Quantity, Price: lamTron(d.Price)}
		for _, g := range d.Gifts {
			dong.Gifts = append(dong.Gifts, domain.FixedPriceGift{ProductVariantID: g.ProductVariantID, Quantity: g.Quantity})
		}
		f.Details = append(f.Details, dong)
	}

	if len(loi) > 0 {
		return loiO(loi)
	}

	return nil
}

// kiemTrung — hai chương trình ĐANG BẬT cùng phủ một món ở cùng chi nhánh, cùng
// ngày, cùng thứ thì quầy không biết lấy giá nào: chặn ngay lúc lưu (luật
// FixedPriceConflictChecker của v2). Nhóm hàng và sản phẩm so chéo: sản phẩm nằm
// trong cây danh mục của chương trình kia cũng là trùng.
func (s *fixedPriceService) kiemTrung(ctx context.Context, f *domain.FixedPrice) error {
	khac, err := s.repo.DangBat(ctx)
	if err != nil {
		return err
	}

	var parentOf map[uint]uint
	catCua := map[uint]uint{} // sản phẩm → danh mục trực tiếp
	canCay := false
	for _, k := range append(khac, *f) {
		if k.Type != f.Type {
			canCay = true
		}
	}
	if canCay {
		parentOf = s.cayDanhMuc(ctx)
		// Danh mục của những sản phẩm có mặt trong phép so.
		ids := []uint{}
		for _, k := range append(khac, *f) {
			if k.Type == domain.FixedPriceTheoSanPham {
				for _, d := range k.Details {
					ids = append(ids, d.ObjectID)
				}
			}
		}
		if m, err := s.danhMucCuaSanPham(ctx, ids); err == nil {
			catCua = m
		}
	}

	phu := func(a domain.FixedPrice, da domain.FixedPriceDetail, b domain.FixedPrice, db domain.FixedPriceDetail) bool {
		switch {
		case a.Type == b.Type:
			return da.ObjectID == db.ObjectID
		case a.Type == domain.FixedPriceTheoNhom:
			return trongCay(catCua[db.ObjectID], da.ObjectID, parentOf)
		default:
			return trongCay(catCua[da.ObjectID], db.ObjectID, parentOf)
		}
	}

	for _, k := range khac {
		if k.ID == f.ID || !giaoChiNhanh(*f, k) || !giaoNgay(*f, k) || !giaoThu(f.DaysOfWeek, k.DaysOfWeek) {
			continue
		}
		for _, da := range f.Details {
			for _, db := range k.Details {
				if phu(*f, da, k, db) {
					return domain.ErrFixedPriceTrung
				}
			}
		}
	}

	return nil
}

func giaoChiNhanh(a, b domain.FixedPrice) bool {
	if a.AllShops || b.AllShops {
		return true
	}
	for _, x := range a.ShopIDs {
		for _, y := range b.ShopIDs {
			if x == y {
				return true
			}
		}
	}

	return false
}

func giaoNgay(a, b domain.FixedPrice) bool {
	if a.NoTimeLimit || b.NoTimeLimit || a.StartDate == nil || b.StartDate == nil {
		return true
	}

	return !a.EndDate.Before(*b.StartDate) && !b.EndDate.Before(*a.StartDate)
}

func giaoThu(a, b string) bool {
	if a == "" || b == "" {
		return true
	}
	for _, x := range strings.Split(a, ",") {
		for _, y := range strings.Split(b, ",") {
			if x == y {
				return true
			}
		}
	}

	return false
}

// trongCay: danh mục cat có nằm trong cây của goc (chính nó hoặc con cháu) không.
func trongCay(cat, goc uint, parentOf map[uint]uint) bool {
	for i := 0; cat != 0 && i < 20; i++ {
		if cat == goc {
			return true
		}
		cat = parentOf[cat]
	}

	return false
}

func (s *fixedPriceService) cayDanhMuc(ctx context.Context) map[uint]uint {
	out := map[uint]uint{}
	if cats, err := s.categoryRepo.List(ctx, false); err == nil {
		for _, c := range cats {
			if c.ParentID != nil {
				out[c.ID] = *c.ParentID
			}
		}
	}

	return out
}

func (s *fixedPriceService) danhMucCuaSanPham(ctx context.Context, ids []uint) (map[uint]uint, error) {
	if len(ids) == 0 {
		return map[uint]uint{}, nil
	}

	return s.repo.DanhMucSanPham(ctx, ids)
}

func (s *fixedPriceService) SetStatus(ctx context.Context, id uint, on bool) error {
	if on {
		f, err := s.repo.FindByID(ctx, id)
		if err != nil {
			return err
		}
		f.Status = true
		if err := s.kiemTrung(ctx, f); err != nil {
			return err
		}
	}

	return s.repo.SetStatus(ctx, id, on)
}

func (s *fixedPriceService) HuyDuyet(ctx context.Context, id uint) error {
	return s.repo.SetApproved(ctx, id, false)
}

func (s *fixedPriceService) Delete(ctx context.Context, id uint) error {
	f, err := s.repo.FindByID(ctx, id)
	if err != nil {
		return err
	}
	if f.Approved {
		return domain.ErrFixedPriceDaDuyet
	}

	return s.repo.Delete(ctx, id)
}

func (s *fixedPriceService) toResponses(ctx context.Context, items []domain.FixedPrice) []dto.FixedPriceResponse {
	// Tra tên MỘT lượt cho cả trang: danh mục, sản phẩm, biến thể hàng tặng.
	spIDs, bienThe := []uint{}, []uint{}
	coNhom := false
	for _, f := range items {
		for _, d := range f.Details {
			if f.Type == domain.FixedPriceTheoSanPham {
				spIDs = append(spIDs, d.ObjectID)
			} else {
				coNhom = true
			}
			for _, g := range d.Gifts {
				bienThe = append(bienThe, g.ProductVariantID)
			}
		}
	}
	tenSP, _ := s.repo.TenSanPham(ctx, spIDs)
	tenBT, _ := s.repo.TenBienThe(ctx, bienThe)
	tenDM := map[uint]string{}
	if coNhom {
		if cats, err := s.categoryRepo.List(ctx, false); err == nil {
			for _, c := range cats {
				tenDM[c.ID] = c.Name
			}
		}
	}

	out := make([]dto.FixedPriceResponse, 0, len(items))
	for _, f := range items {
		r := dto.FixedPriceResponse{
			ID: f.ID, Code: f.Code, Name: f.Name, Description: f.Description, Type: f.Type,
			Status: f.Status, Approved: f.Approved, NoTimeLimit: f.NoTimeLimit,
			DaysOfWeek: tachThu(f.DaysOfWeek), AllShops: f.AllShops, ShopIDs: f.ShopIDs,
			Details: []dto.FixedPriceDetailResponse{}, CreatedAt: f.CreatedAt.Format(time.RFC3339),
		}
		if r.ShopIDs == nil {
			r.ShopIDs = []uint{}
		}
		if len(r.DaysOfWeek) == 0 {
			r.DaysOfWeek = []int{1, 2, 3, 4, 5, 6, 7}
		}
		if f.StartDate != nil {
			r.StartDate = f.StartDate.Format("2006-01-02")
		}
		if f.EndDate != nil {
			r.EndDate = f.EndDate.Format("2006-01-02")
		}
		for _, d := range f.Details {
			ten := tenSP[d.ObjectID]
			if f.Type == domain.FixedPriceTheoNhom {
				ten = tenDM[d.ObjectID]
			}
			dr := dto.FixedPriceDetailResponse{ID: d.ID, ObjectID: d.ObjectID, ObjectName: ten,
				Quantity: d.Quantity, Price: d.Price, Gifts: []dto.FixedPriceGiftResponse{}}
			for _, g := range d.Gifts {
				dr.Gifts = append(dr.Gifts, dto.FixedPriceGiftResponse{
					ProductVariantID: g.ProductVariantID, Name: tenBT[g.ProductVariantID], Quantity: g.Quantity})
			}
			r.Details = append(r.Details, dr)
		}
		out = append(out, r)
	}

	return out
}

// ---------- Tính giá ở quầy ----------

// choQuay: chương trình quầy được dùng HÔM NAY ở chi nhánh đang bán — đang bật,
// đã duyệt, đúng ngày, đúng thứ, đúng chi nhánh.
func (s *fixedPriceService) choQuay(ctx context.Context) ([]domain.FixedPrice, error) {
	ds, err := s.repo.DangBat(ctx)
	if err != nil {
		return nil, err
	}
	shop := s.repo.ChiNhanhQuay(ctx)
	now := time.Now()
	out := ds[:0]
	for _, f := range ds {
		if !f.Approved || !f.ChayNgay(now) {
			continue
		}
		if !f.AllShops {
			co := false
			for _, id := range f.ShopIDs {
				co = co || id == shop
			}
			if !co {
				continue
			}
		}
		out = append(out, f)
	}

	return out, nil
}

// tinh là HÀM TÍNH DUY NHẤT của đồng giá.
//
// Số lượng đếm trên hàng BÁN của giỏ (không đếm hàng tặng). Áp theo thứ tự như
// quầy v2: dòng theo nhóm hàng trước, dòng theo sản phẩm sau — nên món lẻ thắng
// nhóm; cùng mức thì chương trình chọn sau thắng. MỌI cái của món bị phủ bán
// đúng giá đồng giá, không chỉ Q cái đầu.
func (s *fixedPriceService) tinh(ctx context.Context, ds []domain.FixedPrice, found map[uint]domain.CheckoutVariant, lines []domain.CheckoutLine, chon []uint) *KetQuaDongGia {
	sl := map[uint]int{}
	for _, l := range lines {
		sl[l.VariantID] += l.Quantity
	}

	var parentOf map[uint]uint
	for _, f := range ds {
		if f.Type == domain.FixedPriceTheoNhom {
			parentOf = s.cayDanhMuc(ctx)
			break
		}
	}

	phu := func(f domain.FixedPrice, d domain.FixedPriceDetail, cv domain.CheckoutVariant) bool {
		if f.Type == domain.FixedPriceTheoSanPham {
			return cv.ProductID == d.ObjectID
		}
		return trongCay(cv.CategoryID, d.ObjectID, parentOf)
	}
	du := func(f domain.FixedPrice, d domain.FixedPriceDetail) bool {
		tong := 0
		for vid, n := range sl {
			if cv, ok := found[vid]; ok && phu(f, d, cv) {
				tong += n
			}
		}
		return tong >= d.Quantity
	}

	kq := &KetQuaDongGia{Gia: map[uint]GiaDongGia{}}
	for _, f := range ds {
		for _, d := range f.Details {
			if du(f, d) {
				kq.DuDieuKien = append(kq.DuDieuKien, f)
				break
			}
		}
	}

	thuTu := map[uint]int{}
	for i, id := range chon {
		thuTu[id] = i + 1
	}
	dangChon := []domain.FixedPrice{}
	for _, f := range kq.DuDieuKien {
		if thuTu[f.ID] > 0 {
			dangChon = append(dangChon, f)
		}
	}
	sort.SliceStable(dangChon, func(i, j int) bool {
		if dangChon[i].Type != dangChon[j].Type {
			return dangChon[i].Type < dangChon[j].Type // nhóm (1) trước sản phẩm (2)
		}
		return thuTu[dangChon[i].ID] < thuTu[dangChon[j].ID]
	})

	for _, f := range dangChon {
		for _, d := range f.Details {
			if !du(f, d) {
				continue
			}
			for vid := range sl {
				if cv, ok := found[vid]; ok && phu(f, d, cv) {
					kq.Gia[vid] = GiaDongGia{Price: d.Price, DetailID: d.ID, TenCT: f.Name}
				}
			}
			for _, g := range d.Gifts {
				kq.Qua = append(kq.Qua, QuaDongGia{VariantID: g.ProductVariantID, Quantity: g.Quantity, DetailID: d.ID})
			}
		}
	}

	return kq
}

func (s *fixedPriceService) XemTruoc(ctx context.Context, req dto.POSDongGiaRequest) (*dto.POSDongGiaResponse, error) {
	res := &dto.POSDongGiaResponse{ChuongTrinh: []dto.POSDongGiaChuongTrinh{}, Gia: []dto.POSDongGiaGia{}, Qua: []dto.POSDongGiaQua{}}
	if len(req.Items) == 0 {
		return res, nil
	}
	ds, err := s.choQuay(ctx)
	if err != nil || len(ds) == 0 {
		return res, err
	}

	lines := make([]domain.CheckoutLine, 0, len(req.Items))
	for _, it := range req.Items {
		lines = append(lines, domain.CheckoutLine{VariantID: it.ProductVariantID, Quantity: it.Quantity})
	}
	found, err := s.orderRepo.QuoteVariants(ctx, lines)
	if err != nil {
		return nil, err
	}

	kq := s.tinh(ctx, ds, found, lines, req.ChonIDs)
	for _, f := range kq.DuDieuKien {
		res.ChuongTrinh = append(res.ChuongTrinh, dto.POSDongGiaChuongTrinh{ID: f.ID, Code: f.Code, Name: f.Name, Type: f.Type})
	}
	for vid, g := range kq.Gia {
		res.Gia = append(res.Gia, dto.POSDongGiaGia{ProductVariantID: vid, Price: g.Price})
	}
	sort.Slice(res.Gia, func(i, j int) bool { return res.Gia[i].ProductVariantID < res.Gia[j].ProductVariantID })

	ids := []uint{}
	for _, q := range kq.Qua {
		ids = append(ids, q.VariantID)
	}
	ten, _ := s.repo.TenBienThe(ctx, ids)
	for _, q := range kq.Qua {
		res.Qua = append(res.Qua, dto.POSDongGiaQua{ProductVariantID: q.VariantID, Name: ten[q.VariantID], Quantity: q.Quantity})
	}

	return res, nil
}

func (s *fixedPriceService) QuaCanKhoa(ctx context.Context, chon []uint) ([]uint, error) {
	if len(chon) == 0 {
		return nil, nil
	}
	ds, err := s.choQuay(ctx)
	if err != nil {
		return nil, err
	}
	muon := map[uint]bool{}
	for _, id := range chon {
		muon[id] = true
	}
	out := []uint{}
	for _, f := range ds {
		if !muon[f.ID] {
			continue
		}
		for _, d := range f.Details {
			for _, g := range d.Gifts {
				out = append(out, g.ProductVariantID)
			}
		}
	}

	return out, nil
}

func (s *fixedPriceService) ApDung(ctx context.Context, chon []uint, found map[uint]domain.CheckoutVariant, lines []domain.CheckoutLine) (*KetQuaDongGia, error) {
	ds, err := s.choQuay(ctx)
	if err != nil {
		return nil, err
	}
	kq := s.tinh(ctx, ds, found, lines, chon)
	for vid, g := range kq.Gia {
		cv := found[vid]
		cv.Price = g.Price
		cv.PromotionName = "Đồng giá: " + g.TenCT
		found[vid] = cv
	}

	return kq, nil
}
