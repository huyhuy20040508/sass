package service

import (
	"context"
	"math"
	"sort"
	"strings"
	"time"

	"sass-api/internal/domain"
	"sass-api/internal/dto"
)

// PromotionProgramService — chương trình khuyến mại (khuôn crm/promotion-program
// của v2): quản trị ở CRM, và tính giảm giá cho quầy. Tính chỉ ở MỘT hàm (tinh),
// dùng chung cho lượt xem trước và lượt chốt đơn.
type PromotionProgramService interface {
	List(ctx context.Context, f domain.PromotionProgramFilter) ([]dto.PromotionProgramResponse, int64, error)
	Codes(ctx context.Context) ([]string, error)
	Get(ctx context.Context, id uint) (*dto.PromotionProgramResponse, error)
	Save(ctx context.Context, id uint, req dto.PromotionProgramRequest) (*dto.PromotionProgramResponse, error)
	Duplicate(ctx context.Context, id uint) (*dto.PromotionProgramResponse, error)
	SetStatus(ctx context.Context, id uint, on bool) error
	HuyDuyet(ctx context.Context, id uint) error
	Delete(ctx context.Context, id uint) error

	XemTruoc(ctx context.Context, req dto.POSKhuyenMaiRequest) (*dto.POSKhuyenMaiResponse, error)
	QuaCanKhoa(ctx context.Context, chon []uint) ([]uint, error)
	// ApDung chạy BÊN TRONG giao dịch chốt đơn; found đã mang giá bán (sau giảm
	// giá tự áp) nên số giảm ở đây tính đúng trên số khách thật sự trả.
	ApDung(ctx context.Context, chon []uint, found map[uint]domain.CheckoutVariant, lines []domain.CheckoutLine) (*KetQuaKhuyenMai, error)
	TangLuotDung(ctx context.Context, ids []uint) error
}

// KetQuaKhuyenMai — kết quả tính của một giỏ.
type KetQuaKhuyenMai struct {
	DuDieuKien []domain.PromotionProgram
	Giam       float64
	// TheoChuongTrinh: chương trình → tiền giảm (để in phiếu, đếm lượt dùng).
	TheoChuongTrinh []KMDaApDung
	Qua             []QuaDongGia // DetailID ở đây là id BẬC chương trình
	BacDaApDung     []uint
}

type KMDaApDung struct {
	ID   uint
	Name string
	Giam float64
}

type promotionProgramService struct {
	repo         domain.PromotionProgramRepository
	fp           domain.FixedPriceRepository // tra tên sản phẩm / biến thể / danh mục của sản phẩm
	categoryRepo domain.CategoryRepository
	orderRepo    domain.OrderRepository
}

func NewPromotionProgramService(repo domain.PromotionProgramRepository, fp domain.FixedPriceRepository, categoryRepo domain.CategoryRepository, orderRepo domain.OrderRepository) PromotionProgramService {
	return &promotionProgramService{repo: repo, fp: fp, categoryRepo: categoryRepo, orderRepo: orderRepo}
}

// ---------- Quản trị ----------

func (s *promotionProgramService) List(ctx context.Context, f domain.PromotionProgramFilter) ([]dto.PromotionProgramResponse, int64, error) {
	if f.Page < 1 {
		f.Page = 1
	}
	items, total, err := s.repo.List(ctx, f)
	if err != nil {
		return nil, 0, err
	}

	return s.toResponses(ctx, items), total, nil
}

func (s *promotionProgramService) Codes(ctx context.Context) ([]string, error) {
	return s.repo.Codes(ctx)
}

func (s *promotionProgramService) Get(ctx context.Context, id uint) (*dto.PromotionProgramResponse, error) {
	p, err := s.repo.FindByID(ctx, id)
	if err != nil {
		return nil, err
	}
	res := s.toResponses(ctx, []domain.PromotionProgram{*p})[0]

	return &res, nil
}

func (s *promotionProgramService) Save(ctx context.Context, id uint, req dto.PromotionProgramRequest) (*dto.PromotionProgramResponse, error) {
	p := &domain.PromotionProgram{}
	if id > 0 {
		cu, err := s.repo.FindByID(ctx, id)
		if err != nil {
			return nil, err
		}
		// v2: đang Duyệt thì phải "Huỷ duyệt" trước khi sửa.
		if cu.Approved {
			return nil, loiO(map[string]string{"approved": "Chương trình đang được Duyệt. Cần xác nhận Huỷ duyệt trước khi sửa."})
		}
		p = cu
	} else {
		ma, err := s.repo.MaKeTiep(ctx)
		if err != nil {
			return nil, err
		}
		p.Code = ma
	}

	if err := dienChuongTrinh(p, req); err != nil {
		return nil, err
	}
	// v2 kiểm trùng lúc lưu Duyệt (và lúc bật một chương trình đã duyệt).
	if p.Approved && p.Status {
		if err := s.kiemTrung(ctx, p); err != nil {
			return nil, err
		}
	}
	if err := s.repo.Save(ctx, p); err != nil {
		return nil, err
	}

	return s.Get(ctx, p.ID)
}

func dienChuongTrinh(p *domain.PromotionProgram, req dto.PromotionProgramRequest) error {
	loi := map[string]string{}

	p.Name = strings.TrimSpace(req.Name)
	p.Description = strings.TrimSpace(req.Description)
	p.Type = req.Type
	p.Status = boolOrDefault(req.Status, true)
	p.Approved = req.Approved
	p.NoTimeLimit = req.NoTimeLimit
	p.StartDate, p.EndDate = nil, nil
	if !req.NoTimeLimit {
		tu, e1 := time.ParseInLocation("2006-01-02", req.StartDate, time.Local)
		den, e2 := time.ParseInLocation("2006-01-02", req.EndDate, time.Local)
		switch {
		case e1 != nil || e2 != nil:
			loi["start_date"] = "Chọn ngày áp dụng, hoặc tick Không có giới hạn thời gian"
		case den.Before(tu):
			loi["end_date"] = "Đến ngày phải sau hoặc bằng Từ ngày"
		default:
			p.StartDate, p.EndDate = &tu, &den
		}
	}
	p.DaysOfWeek = ghepThu(req.DaysOfWeek)

	p.AllShops = req.AllShops
	p.ShopIDs = nil
	if !req.AllShops {
		seen := map[uint]bool{}
		for _, id := range req.ShopIDs {
			if id > 0 && !seen[id] {
				seen[id] = true
				p.ShopIDs = append(p.ShopIDs, id)
			}
		}
		if len(p.ShopIDs) == 0 {
			loi["shop_ids"] = "Chọn chi nhánh áp dụng"
		}
	}

	// Luật từng bậc của v2 (validatePromoDetails).
	p.Details = nil
	nguong := map[float64]bool{}
	hinhThuc := map[uint]uint8{}
	slTheo := map[[2]uint]bool{}
	for _, d := range req.Details {
		if d.Formality == domain.KMHinhThucPhanTram && d.Value > 100 {
			loi["details"] = "Giá trị phần trăm không được quá 100"
		}
		dong := domain.PromotionProgramDetail{Formality: d.Formality, Value: lamTron2(d.Value), MaxValue: lamTron2(d.MaxValue)}
		// "Tiền" thì giá trị tối đa chính là giá trị (form v2 chép sang).
		if d.Formality == domain.KMHinhThucTien {
			dong.MaxValue = dong.Value
		}
		switch req.Type {
		case domain.KMPhieuBanHang:
			if d.TotalApply <= 0 {
				loi["details"] = "Nhập tổng giá trị đơn hàng của từng dòng"
			}
			if nguong[d.TotalApply] {
				loi["details"] = "Tổng giá trị đơn hàng không được trùng nhau!"
			}
			nguong[d.TotalApply] = true
			if d.Formality == domain.KMHinhThucTien && d.Value > d.TotalApply {
				loi["details"] = "Giá trị giảm không được lớn hơn tổng giá trị đơn hàng"
			}
			dong.TotalApply = lamTron2(d.TotalApply)
		default:
			if d.ObjectID == 0 || d.Quantity < 1 {
				loi["details"] = "Vui lòng nhập đủ các giá trị!"
			}
			if f, co := hinhThuc[d.ObjectID]; co && f != d.Formality {
				loi["details"] = "Tất cả các dòng khuyến mãi phải cùng một hình thức (Phần trăm hoặc tiền)!"
			}
			hinhThuc[d.ObjectID] = d.Formality
			khoa := [2]uint{d.ObjectID, uint(d.Quantity)}
			if slTheo[khoa] {
				loi["details"] = "Số lượng khuyến mãi của từng dòng không được trùng nhau"
			}
			slTheo[khoa] = true
			dong.ObjectID, dong.Quantity = d.ObjectID, d.Quantity
		}
		for _, g := range d.Gifts {
			dong.Gifts = append(dong.Gifts, domain.PromotionProgramGift{ProductVariantID: g.ProductVariantID, Quantity: g.Quantity})
		}
		p.Details = append(p.Details, dong)
	}

	if len(loi) > 0 {
		return loiO(loi)
	}

	return nil
}

func lamTron2(v float64) float64 { return math.Round(v*100) / 100 }

// kiemTrung — luật checkConflict của v2: chỉ loại 2 / 3 mới xung đột, so với
// chương trình khác đang BẬT, cùng chi nhánh, ngày giao nhau. Cùng nhóm, cùng
// món, hay món nằm trong nhóm của chương trình kia đều là trùng.
func (s *promotionProgramService) kiemTrung(ctx context.Context, p *domain.PromotionProgram) error {
	if p.Type == domain.KMPhieuBanHang {
		return nil
	}
	khac, err := s.repo.DangBat(ctx)
	if err != nil {
		return err
	}

	parentOf := cayDanhMucTu(ctx, s.categoryRepo)
	spIDs := []uint{}
	for _, k := range append(khac, *p) {
		if k.Type == domain.KMDanhSachHang {
			for _, d := range k.Details {
				spIDs = append(spIDs, d.ObjectID)
			}
		}
	}
	catCua := map[uint]uint{}
	if len(spIDs) > 0 {
		if m, err := s.fp.DanhMucSanPham(ctx, spIDs); err == nil {
			catCua = m
		}
	}
	tenSP, _ := s.fp.TenSanPham(ctx, spIDs)
	tenDM := tenDanhMuc(ctx, s.categoryRepo)

	for _, k := range khac {
		if k.ID == p.ID || k.Type == domain.KMPhieuBanHang || !giaoChiNhanhKM(*p, k) || !giaoNgayKM(*p, k) {
			continue
		}
		for _, da := range p.Details {
			for _, db := range k.Details {
				switch {
				case p.Type == domain.KMNhomHang && k.Type == domain.KMNhomHang && da.ObjectID == db.ObjectID:
					return loiO(map[string]string{"details": "Đã tồn tại chương trình áp dụng cho nhóm hàng: " + tenDM[da.ObjectID] + " trong khoảng thời gian này"})
				case p.Type == domain.KMNhomHang && k.Type == domain.KMDanhSachHang && trongCay(catCua[db.ObjectID], da.ObjectID, parentOf):
					return loiO(map[string]string{"details": "Đã tồn tại chương trình áp dụng cho hàng nằm trong nhóm: " + tenDM[da.ObjectID] + " trong khoảng thời gian này"})
				case p.Type == domain.KMDanhSachHang && k.Type == domain.KMDanhSachHang && da.ObjectID == db.ObjectID:
					return loiO(map[string]string{"details": "Đã tồn tại chương trình áp dụng cho hàng: " + tenSP[da.ObjectID] + " trong khoảng thời gian này"})
				case p.Type == domain.KMDanhSachHang && k.Type == domain.KMNhomHang && trongCay(catCua[da.ObjectID], db.ObjectID, parentOf):
					return loiO(map[string]string{"details": "Hàng " + tenSP[da.ObjectID] + " đã nằm trong chương trình khuyến mãi theo nhóm trong khoảng thời gian này"})
				}
			}
		}
	}

	return nil
}

func giaoChiNhanhKM(a, b domain.PromotionProgram) bool {
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

func giaoNgayKM(a, b domain.PromotionProgram) bool {
	if a.NoTimeLimit || b.NoTimeLimit || a.StartDate == nil || b.StartDate == nil {
		return true
	}

	return !a.EndDate.Before(*b.StartDate) && !b.EndDate.Before(*a.StartDate)
}

func cayDanhMucTu(ctx context.Context, repo domain.CategoryRepository) map[uint]uint {
	out := map[uint]uint{}
	if cats, err := repo.List(ctx, false); err == nil {
		for _, c := range cats {
			if c.ParentID != nil {
				out[c.ID] = *c.ParentID
			}
		}
	}

	return out
}

func tenDanhMuc(ctx context.Context, repo domain.CategoryRepository) map[uint]string {
	out := map[uint]string{}
	if cats, err := repo.List(ctx, false); err == nil {
		for _, c := range cats {
			out[c.ID] = c.Name
		}
	}

	return out
}

// Duplicate — v2: bản sao ở trạng thái Lưu tạm, giữ nguyên bật/tắt, chép sâu
// bậc và hàng tặng; tên nối "COPY", mã hệ thống cấp mới.
func (s *promotionProgramService) Duplicate(ctx context.Context, id uint) (*dto.PromotionProgramResponse, error) {
	goc, err := s.repo.FindByID(ctx, id)
	if err != nil {
		return nil, err
	}
	ma, err := s.repo.MaKeTiep(ctx)
	if err != nil {
		return nil, err
	}
	ban := *goc
	ban.ID = 0
	ban.Code = ma
	ban.Name = catNgan(goc.Name+"COPY", 100)
	ban.Approved = false
	ban.Used = 0
	ban.Details = make([]domain.PromotionProgramDetail, len(goc.Details))
	for i, d := range goc.Details {
		d.ID = 0
		gifts := make([]domain.PromotionProgramGift, len(d.Gifts))
		for j, g := range d.Gifts {
			g.ID = 0
			gifts[j] = g
		}
		d.Gifts = gifts
		ban.Details[i] = d
	}
	if err := s.repo.Save(ctx, &ban); err != nil {
		return nil, err
	}

	return s.Get(ctx, ban.ID)
}

func (s *promotionProgramService) SetStatus(ctx context.Context, id uint, on bool) error {
	if on {
		p, err := s.repo.FindByID(ctx, id)
		if err != nil {
			return err
		}
		if p.Approved {
			p.Status = true
			if err := s.kiemTrung(ctx, p); err != nil {
				return err
			}
		}
	}

	return s.repo.SetStatus(ctx, id, on)
}

func (s *promotionProgramService) HuyDuyet(ctx context.Context, id uint) error {
	return s.repo.SetApproved(ctx, id, false)
}

func (s *promotionProgramService) Delete(ctx context.Context, id uint) error {
	p, err := s.repo.FindByID(ctx, id)
	if err != nil {
		return err
	}
	// v2: đơn đã dùng chương trình thì không xoá được.
	if p.Used > 0 {
		return domain.ErrKMDangDung
	}

	return s.repo.Delete(ctx, id)
}

func (s *promotionProgramService) TangLuotDung(ctx context.Context, ids []uint) error {
	return s.repo.TangLuotDung(ctx, ids)
}

func (s *promotionProgramService) toResponses(ctx context.Context, items []domain.PromotionProgram) []dto.PromotionProgramResponse {
	spIDs, bienThe := []uint{}, []uint{}
	coNhom := false
	for _, p := range items {
		for _, d := range p.Details {
			switch p.Type {
			case domain.KMDanhSachHang:
				spIDs = append(spIDs, d.ObjectID)
			case domain.KMNhomHang:
				coNhom = true
			}
			for _, g := range d.Gifts {
				bienThe = append(bienThe, g.ProductVariantID)
			}
		}
	}
	tenSP, _ := s.fp.TenSanPham(ctx, spIDs)
	tenBT, _ := s.fp.TenBienThe(ctx, bienThe)
	tenDM := map[uint]string{}
	if coNhom {
		tenDM = tenDanhMuc(ctx, s.categoryRepo)
	}

	out := make([]dto.PromotionProgramResponse, 0, len(items))
	for _, p := range items {
		r := dto.PromotionProgramResponse{
			ID: p.ID, Code: p.Code, Name: p.Name, Description: p.Description, Type: p.Type,
			Status: p.Status, Approved: p.Approved, NoTimeLimit: p.NoTimeLimit,
			DaysOfWeek: tachThu(p.DaysOfWeek), AllShops: p.AllShops, ShopIDs: p.ShopIDs, Used: p.Used,
			Details: []dto.PromotionProgramDetailResponse{}, CreatedAt: p.CreatedAt.Format(time.RFC3339),
		}
		if r.ShopIDs == nil {
			r.ShopIDs = []uint{}
		}
		if len(r.DaysOfWeek) == 0 {
			r.DaysOfWeek = []int{1, 2, 3, 4, 5, 6, 7}
		}
		if p.StartDate != nil {
			r.StartDate = p.StartDate.Format("2006-01-02")
		}
		if p.EndDate != nil {
			r.EndDate = p.EndDate.Format("2006-01-02")
		}
		for _, d := range p.Details {
			ten := ""
			switch p.Type {
			case domain.KMDanhSachHang:
				ten = tenSP[d.ObjectID]
			case domain.KMNhomHang:
				ten = tenDM[d.ObjectID]
			}
			dr := dto.PromotionProgramDetailResponse{ID: d.ID, TotalApply: d.TotalApply, ObjectID: d.ObjectID, ObjectName: ten,
				Quantity: d.Quantity, Formality: d.Formality, Value: d.Value, MaxValue: d.MaxValue, Gifts: []dto.FixedPriceGiftResponse{}}
			for _, g := range d.Gifts {
				dr.Gifts = append(dr.Gifts, dto.FixedPriceGiftResponse{ProductVariantID: g.ProductVariantID, Name: tenBT[g.ProductVariantID], Quantity: g.Quantity})
			}
			r.Details = append(r.Details, dr)
		}
		out = append(out, r)
	}

	return out
}

// ---------- Tính ở quầy ----------

func (s *promotionProgramService) choQuay(ctx context.Context) ([]domain.PromotionProgram, error) {
	ds, err := s.repo.DangBat(ctx)
	if err != nil {
		return nil, err
	}
	shop := s.repo.ChiNhanhQuay(ctx)
	now := time.Now()
	out := ds[:0]
	for _, p := range ds {
		if !p.Approved || !p.ChayNgay(now) {
			continue
		}
		if !p.AllShops {
			co := false
			for _, id := range p.ShopIDs {
				co = co || id == shop
			}
			if !co {
				continue
			}
		}
		out = append(out, p)
	}

	return out, nil
}

// tinh là HÀM TÍNH DUY NHẤT của chương trình khuyến mại — đúng thứ tự listPay()
// của quầy v2:
//
//  1. Mỗi chương trình lấy BẬC CAO NHẤT mà đơn đạt (ngưỡng ≤ giá trị của đơn).
//  2. Loại 2 / 3 giảm trên DÒNG: trên từng dòng, bậc "tiền" trừ trước, bậc "%"
//     tính trên phần còn lại. Loại 3: tiền = trừ mỗi dòng khớp; % có trần theo
//     dòng. Loại 2: tiền và trần % chia cho các dòng của nhóm theo tỉ trọng.
//  3. Loại 0 giảm trên ĐƠN: tiền trước, % tính trên tiền hàng đã trừ giảm dòng
//     và giảm tiền của đơn, có trần.
//  4. Tổng giảm không vượt tiền hàng.
//
// Tiền hàng là giá bán × số lượng (giá đã qua giảm giá tự áp), chưa trừ giảm tay
// — như v2 tính khuyến mại trên tiền hàng gốc.
func (s *promotionProgramService) tinh(ctx context.Context, ds []domain.PromotionProgram, found map[uint]domain.CheckoutVariant, lines []domain.CheckoutLine, chon []uint) *KetQuaKhuyenMai {
	sl := map[uint]int{}
	for _, l := range lines {
		sl[l.VariantID] += l.Quantity
	}
	tien := map[uint]float64{}
	tong := 0.0
	for vid, n := range sl {
		if cv, ok := found[vid]; ok {
			tien[vid] = cv.Price * float64(n)
			tong += tien[vid]
		}
	}

	var parentOf map[uint]uint
	for _, p := range ds {
		if p.Type == domain.KMNhomHang {
			parentOf = cayDanhMucTu(ctx, s.categoryRepo)
			break
		}
	}
	khop := func(p domain.PromotionProgram, d domain.PromotionProgramDetail, cv domain.CheckoutVariant) bool {
		if p.Type == domain.KMDanhSachHang {
			return cv.ProductID == d.ObjectID
		}
		return trongCay(cv.CategoryID, d.ObjectID, parentOf)
	}
	datBac := func(p domain.PromotionProgram, d domain.PromotionProgramDetail) bool {
		if p.Type == domain.KMPhieuBanHang {
			return d.TotalApply <= tong
		}
		n := 0
		for vid, q := range sl {
			if cv, ok := found[vid]; ok && khop(p, d, cv) {
				n += q
			}
		}
		return n >= d.Quantity
	}
	bacCaoNhat := func(p domain.PromotionProgram) *domain.PromotionProgramDetail {
		ds := append([]domain.PromotionProgramDetail{}, p.Details...)
		sort.SliceStable(ds, func(i, j int) bool {
			if p.Type == domain.KMPhieuBanHang {
				return ds[i].TotalApply > ds[j].TotalApply
			}
			return ds[i].Quantity > ds[j].Quantity
		})
		for i := range ds {
			if datBac(p, ds[i]) {
				return &ds[i]
			}
		}
		return nil
	}

	kq := &KetQuaKhuyenMai{}
	type apDung struct {
		p domain.PromotionProgram
		d domain.PromotionProgramDetail
	}
	muon := map[uint]bool{}
	for _, id := range chon {
		muon[id] = true
	}
	var dang []apDung
	for _, p := range ds {
		if len(p.Details) == 0 || tong <= 0 {
			continue
		}
		// Loại 2/3 chỉ hiện khi có một dòng đạt; loại 0 khi tổng đạt một bậc.
		d := bacCaoNhat(p)
		if d == nil {
			continue
		}
		kq.DuDieuKien = append(kq.DuDieuKien, p)
		if muon[p.ID] {
			dang = append(dang, apDung{p, *d})
		}
	}

	giamCT := map[uint]float64{}
	// 2. Giảm trên dòng.
	giamDong := map[uint]float64{}
	for vid := range tien {
		cv := found[vid]
		tienTru := 0.0
		for _, a := range dang {
			if a.p.Type == domain.KMPhieuBanHang || a.d.Formality != domain.KMHinhThucTien || !khop(a.p, a.d, cv) {
				continue
			}
			g := a.d.Value
			if a.p.Type == domain.KMNhomHang {
				g = a.d.MaxValue * tien[vid] / tienNhom(a.p, a.d, found, tien, khop)
			}
			g = math.Min(g, tien[vid]-tienTru)
			tienTru += g
			giamCT[a.p.ID] += g
		}
		conLai := tien[vid] - tienTru
		phanTram := 0.0
		for _, a := range dang {
			if a.p.Type == domain.KMPhieuBanHang || a.d.Formality != domain.KMHinhThucPhanTram || !khop(a.p, a.d, cv) {
				continue
			}
			g := conLai * a.d.Value / 100
			tran := a.d.MaxValue
			if a.p.Type == domain.KMNhomHang && tran > 0 {
				tran = a.d.MaxValue * tien[vid] / tienNhom(a.p, a.d, found, tien, khop)
			}
			if tran > 0 {
				g = math.Min(g, tran)
			}
			g = math.Min(g, conLai-phanTram)
			phanTram += g
			giamCT[a.p.ID] += g
		}
		giamDong[vid] = tienTru + phanTram
	}
	tongGiamDong := 0.0
	for _, g := range giamDong {
		tongGiamDong += g
	}

	// 3. Giảm trên đơn (loại 0).
	giamDonTien := 0.0
	for _, a := range dang {
		if a.p.Type == domain.KMPhieuBanHang && a.d.Formality == domain.KMHinhThucTien {
			giamDonTien += a.d.Value
			giamCT[a.p.ID] += a.d.Value
		}
	}
	nen := math.Max(0, tong-tongGiamDong-giamDonTien)
	giamDonPT := 0.0
	for _, a := range dang {
		if a.p.Type == domain.KMPhieuBanHang && a.d.Formality == domain.KMHinhThucPhanTram {
			g := nen * a.d.Value / 100
			if a.d.MaxValue > 0 {
				g = math.Min(g, a.d.MaxValue)
			}
			giamDonPT += g
			giamCT[a.p.ID] += g
		}
	}

	kq.Giam = math.Round(math.Min(tong, tongGiamDong+giamDonTien+giamDonPT))
	for _, a := range dang {
		kq.TheoChuongTrinh = append(kq.TheoChuongTrinh, KMDaApDung{ID: a.p.ID, Name: a.p.Name, Giam: math.Round(giamCT[a.p.ID])})
		kq.BacDaApDung = append(kq.BacDaApDung, a.d.ID)
		for _, g := range a.d.Gifts {
			kq.Qua = append(kq.Qua, QuaDongGia{VariantID: g.ProductVariantID, Quantity: g.Quantity, DetailID: a.d.ID})
		}
	}

	return kq
}

// tienNhom — tổng tiền các dòng thuộc nhóm của một bậc loại 2 (mẫu số khi chia
// giảm "tiền" và trần "%" về từng dòng).
func tienNhom(p domain.PromotionProgram, d domain.PromotionProgramDetail, found map[uint]domain.CheckoutVariant, tien map[uint]float64,
	khop func(domain.PromotionProgram, domain.PromotionProgramDetail, domain.CheckoutVariant) bool) float64 {
	t := 0.0
	for vid, v := range tien {
		if khop(p, d, found[vid]) {
			t += v
		}
	}
	if t <= 0 {
		return 1
	}

	return t
}

func (s *promotionProgramService) XemTruoc(ctx context.Context, req dto.POSKhuyenMaiRequest) (*dto.POSKhuyenMaiResponse, error) {
	res := &dto.POSKhuyenMaiResponse{ChuongTrinh: []dto.POSDongGiaChuongTrinh{}, TheoChuongTrinh: []dto.POSKhuyenMaiDong{}, Qua: []dto.POSDongGiaQua{}}
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
	for _, p := range kq.DuDieuKien {
		res.ChuongTrinh = append(res.ChuongTrinh, dto.POSDongGiaChuongTrinh{ID: p.ID, Code: p.Code, Name: p.Name, Type: p.Type})
	}
	res.Giam = kq.Giam
	for _, c := range kq.TheoChuongTrinh {
		res.TheoChuongTrinh = append(res.TheoChuongTrinh, dto.POSKhuyenMaiDong{ID: c.ID, Name: c.Name, Giam: c.Giam})
	}
	ids := []uint{}
	for _, q := range kq.Qua {
		ids = append(ids, q.VariantID)
	}
	ten, _ := s.fp.TenBienThe(ctx, ids)
	for _, q := range kq.Qua {
		res.Qua = append(res.Qua, dto.POSDongGiaQua{ProductVariantID: q.VariantID, Name: ten[q.VariantID], Quantity: q.Quantity})
	}

	return res, nil
}

func (s *promotionProgramService) QuaCanKhoa(ctx context.Context, chon []uint) ([]uint, error) {
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
	for _, p := range ds {
		if !muon[p.ID] {
			continue
		}
		for _, d := range p.Details {
			for _, g := range d.Gifts {
				out = append(out, g.ProductVariantID)
			}
		}
	}

	return out, nil
}

func (s *promotionProgramService) ApDung(ctx context.Context, chon []uint, found map[uint]domain.CheckoutVariant, lines []domain.CheckoutLine) (*KetQuaKhuyenMai, error) {
	ds, err := s.choQuay(ctx)
	if err != nil {
		return nil, err
	}

	return s.tinh(ctx, ds, found, lines, chon), nil
}
