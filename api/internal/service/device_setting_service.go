package service

import (
	"context"
	"net"
	"regexp"
	"slices"
	"strconv"
	"strings"

	"sass-api/internal/domain"
)

// ThietBiService — Cài đặt → Thông số chung → Cài đặt thiết bị của chi nhánh
// đang làm: máy in, phiếu nào ra máy nào, nút in, mẫu in.
type ThietBiService interface {
	DanhSach(ctx context.Context) (*TBDanhSach, error)

	TaoMayIn(ctx context.Context, req TBMayInRequest) (*domain.MayIn, error)
	SuaMayIn(ctx context.Context, id uint, req TBMayInRequest) (*domain.MayIn, error)
	BatTatMayIn(ctx context.Context, id uint, bat bool) error
	XoaMayIn(ctx context.Context, id uint) error

	TaoGanPhieu(ctx context.Context, req TBGanPhieuRequest) (*domain.GanPhieuIn, error)
	SuaGanPhieu(ctx context.Context, id uint, req TBGanPhieuRequest) (*domain.GanPhieuIn, error)
	BatTatGanPhieu(ctx context.Context, id uint, bat bool) error
	XoaGanPhieu(ctx context.Context, id uint) error

	LuuNutIn(ctx context.Context, ma string, req TBNutInRequest) error

	LuuMauIn(ctx context.Context, ma string, req TBMauInRequest) (*TBMauIn, error)
	MacDinhMauIn(ctx context.Context, ma string) (*TBMauIn, error)
	// MauChoQuay — mẫu đang dùng của chi nhánh đang bán, cho phiếu in ở quầy.
	// Chi nhánh mơ hồ thì trả mặc định chứ không báo lỗi: in được còn hơn không.
	MauChoQuay(ctx context.Context, ma string) (*TBMauIn, error)
}

// TBMauIn — một mẫu đã gộp: danh mục dòng + giá trị đang dùng.
type TBMauIn struct {
	domain.MauIn
	Options  map[string]bool  `json:"options"`
	Settings domain.CaiDatMau `json:"settings"`
	// DaChinh: khác mặc định — Tổng quan liệt kê mẫu nào đã chỉnh.
	DaChinh bool `json:"da_chinh"`
}

type TBNutIn struct {
	domain.NutBamIn
	Slips    []string `json:"slips"`
	IsActive bool     `json:"is_active"`
}

type TBNhomHang struct {
	ID   uint   `json:"id"`
	Name string `json:"name"`
}

type TBDanhSach struct {
	ShopID    uint                 `json:"shop_id"`
	Printers  []domain.MayIn       `json:"printers"`
	Routes    []domain.GanPhieuIn  `json:"routes"`
	Buttons   []TBNutIn            `json:"buttons"`
	Templates []TBMauIn            `json:"templates"`
	Slips     []domain.LoaiPhieuIn `json:"slips"`
	Groups    []TBNhomHang         `json:"groups"`
	LabelSize []string             `json:"label_sizes"`
}

type TBMayInRequest struct {
	Name     string `json:"name"`
	ConnType string `json:"conn_type"`
	Address  string `json:"address"`
	Paper    string `json:"paper"`
	DPI      int    `json:"dpi"`
	IsActive *bool  `json:"is_active"`
}

type TBGanPhieuRequest struct {
	SlipType      string `json:"slip_type"`
	PrinterID     uint   `json:"printer_id"`
	AllCategories bool   `json:"all_categories"`
	CategoryIDs   []uint `json:"category_ids"`
	IsActive      *bool  `json:"is_active"`
}

type TBNutInRequest struct {
	Slips    []string `json:"slips"`
	IsActive *bool    `json:"is_active"`
}

type TBMauInRequest struct {
	Options  map[string]bool  `json:"options"`
	Settings domain.CaiDatMau `json:"settings"`
}

type thietBiService struct{ repo domain.ThietBiRepository }

func NewThietBiService(repo domain.ThietBiRepository) ThietBiService {
	return &thietBiService{repo: repo}
}

func (s *thietBiService) DanhSach(ctx context.Context) (*TBDanhSach, error) {
	shopID, err := s.repo.ChiNhanhGhi(ctx)
	if err != nil {
		return nil, err
	}
	may, err := s.repo.MayIn(ctx, shopID)
	if err != nil {
		return nil, err
	}
	gan, err := s.repo.GanPhieu(ctx, shopID)
	if err != nil {
		return nil, err
	}
	nut, err := s.docNut(ctx, shopID)
	if err != nil {
		return nil, err
	}
	mau, err := s.docMau(ctx, shopID)
	if err != nil {
		return nil, err
	}
	nhom, err := s.repo.NhomHangGoc(ctx)
	if err != nil {
		return nil, err
	}
	groups := make([]TBNhomHang, 0, len(nhom))
	for _, n := range nhom {
		groups = append(groups, TBNhomHang{ID: n.ID, Name: n.Name})
	}
	for i := range gan {
		if gan[i].CategoryIDs == nil {
			gan[i].CategoryIDs = []uint{}
		}
	}

	return &TBDanhSach{
		ShopID: shopID, Printers: may, Routes: gan, Buttons: nut, Templates: mau,
		Slips: domain.DanhMucPhieuIn, Groups: groups, LabelSize: domain.KhoTemDuoc,
	}, nil
}

func (s *thietBiService) docNut(ctx context.Context, shopID uint) ([]TBNutIn, error) {
	luu, err := s.repo.NutIn(ctx, shopID)
	if err != nil {
		return nil, err
	}
	out := make([]TBNutIn, 0, len(domain.DanhMucNutIn))
	for _, n := range domain.DanhMucNutIn {
		v := TBNutIn{NutBamIn: n, Slips: n.MacDinh, IsActive: true}
		for _, l := range luu {
			if l.Button == n.Ma {
				v.Slips, v.IsActive = l.Slips, l.IsActive
			}
		}
		if v.Slips == nil {
			v.Slips = []string{}
		}
		out = append(out, v)
	}

	return out, nil
}

func (s *thietBiService) docMau(ctx context.Context, shopID uint) ([]TBMauIn, error) {
	luu, err := s.repo.MauIn(ctx, shopID)
	if err != nil {
		return nil, err
	}
	out := make([]TBMauIn, 0, len(domain.DanhMucMauIn))
	for _, m := range domain.DanhMucMauIn {
		var ban *domain.MauInLuu
		for i := range luu {
			if luu[i].Kind == m.Ma {
				ban = &luu[i]
			}
		}
		out = append(out, gopMau(m, ban))
	}

	return out, nil
}

// gopMau đắp bản đã lưu lên mặc định. Dòng mới thêm vào danh mục sau lần lưu
// cuối lấy mặc định của nó; khoá lạ trong bản lưu (dòng đã bỏ) thì rơi đi.
func gopMau(m domain.MauIn, ban *domain.MauInLuu) TBMauIn {
	v := TBMauIn{MauIn: m, Options: m.MacDinhDong(), Settings: m.CaiDat}
	if ban == nil {
		return v
	}
	for k, b := range ban.Options {
		if _, co := v.Options[k]; co {
			v.Options[k] = b
		}
	}
	v.Settings = ganCaiDat(m, ban.Settings)
	v.DaChinh = !mapBang(v.Options, m.MacDinhDong()) || v.Settings != m.CaiDat

	return v
}

// ganCaiDat chỉ nhận những ô mà loại mẫu này CÓ (ô mặc định khác rỗng, hoặc
// có dòng mang ô chữ đó).
func ganCaiDat(m domain.MauIn, moi domain.CaiDatMau) domain.CaiDatMau {
	goc := m.CaiDat
	out := goc
	if m.CoO("wifi_txt") {
		out.WifiTxt = moi.WifiTxt
	}
	if m.CoO("chinhsach_txt") {
		out.ChinhSachTxt = moi.ChinhSachTxt
	}
	if goc.Kho != "" && moi.Kho != "" {
		out.Kho = moi.Kho
	}
	if goc.Ban != 0 && moi.Ban != 0 {
		out.Ban = moi.Ban
	}
	if goc.KhoTem != "" && moi.KhoTem != "" {
		out.KhoTem = moi.KhoTem
	}
	if goc.Cot != 0 && moi.Cot != 0 {
		out.Cot = moi.Cot
	}
	if goc.Co != 0 && moi.Co != 0 {
		out.Co = moi.Co
	}
	out.FooterTxt = moi.FooterTxt
	if moi.FooterTxt == "" {
		out.FooterTxt = goc.FooterTxt
	}

	return out
}

func mapBang(a, b map[string]bool) bool {
	if len(a) != len(b) {
		return false
	}
	for k, v := range a {
		if b[k] != v {
			return false
		}
	}

	return true
}

// ---------- Máy in ----------

var macHopLe = regexp.MustCompile(`^([0-9A-Fa-f]{2}:){5}[0-9A-Fa-f]{2}$`)

func (s *thietBiService) kiemMayIn(ctx context.Context, shopID uint, m *domain.MayIn, req TBMayInRequest) error {
	o := map[string]string{}
	ten := strings.TrimSpace(req.Name)
	if ten == "" || len([]rune(ten)) > 100 {
		o["name"] = "Nhập tên máy in (tối đa 100 ký tự)."
	}
	dc := strings.TrimSpace(req.Address)
	switch req.ConnType {
	case domain.KetNoiLAN:
		ip := net.ParseIP(dc)
		if dc == "" {
			o["address"] = "Nhập địa chỉ IP."
		} else if ip == nil || ip.To4() == nil {
			o["address"] = "IP chưa đúng dạng — 4 cụm số 0–255, VD 192.168.1.50."
		}
	case domain.KetNoiBluetooth:
		if dc != "" && !macHopLe.MatchString(dc) {
			o["address"] = "MAC có dạng AB:CD:EF:11:22:33."
		}
		dc = strings.ToUpper(dc)
	case domain.KetNoiUSB, domain.KetNoiUrovo:
		// Cắm thẳng vào máy quầy: không có địa chỉ để khai.
		dc = ""
	default:
		o["conn_type"] = "Chọn kiểu kết nối: LAN, USB, Bluetooth hoặc Urovo."
	}
	if !slices.Contains(domain.KhoGiayMayIn, req.Paper) {
		o["paper"] = "Khổ giấy chỉ nhận 58, 80, A5 hoặc A4."
	}
	if !slices.Contains(domain.DPIMayIn, req.DPI) {
		o["dpi"] = "Độ phân giải chỉ nhận 203 hoặc 180 dpi."
	}
	if dc != "" && len(o) == 0 {
		ds, err := s.repo.MayIn(ctx, shopID)
		if err != nil {
			return err
		}
		for _, k := range ds {
			if k.ID != m.ID && k.ConnType == req.ConnType && strings.EqualFold(k.Address, dc) {
				o["address"] = "Địa chỉ này đã khai cho “" + k.Name + "”."
			}
		}
	}
	if len(o) > 0 {
		return loiO(o)
	}
	m.Name, m.ConnType, m.Address, m.Paper, m.DPI = ten, req.ConnType, dc, req.Paper, req.DPI
	if req.IsActive != nil {
		m.IsActive = *req.IsActive
	}

	return nil
}

func (s *thietBiService) TaoMayIn(ctx context.Context, req TBMayInRequest) (*domain.MayIn, error) {
	shopID, err := s.repo.ChiNhanhGhi(ctx)
	if err != nil {
		return nil, err
	}
	m := &domain.MayIn{ShopID: shopID, IsActive: true}
	if err := s.kiemMayIn(ctx, shopID, m, req); err != nil {
		return nil, err
	}

	return m, s.repo.LuuMayIn(ctx, m)
}

func (s *thietBiService) SuaMayIn(ctx context.Context, id uint, req TBMayInRequest) (*domain.MayIn, error) {
	shopID, err := s.repo.ChiNhanhGhi(ctx)
	if err != nil {
		return nil, err
	}
	m, err := s.repo.MotMayIn(ctx, shopID, id)
	if err != nil {
		return nil, err
	}
	if err := s.kiemMayIn(ctx, shopID, m, req); err != nil {
		return nil, err
	}

	return m, s.repo.LuuMayIn(ctx, m)
}

func (s *thietBiService) BatTatMayIn(ctx context.Context, id uint, bat bool) error {
	shopID, err := s.repo.ChiNhanhGhi(ctx)
	if err != nil {
		return err
	}
	m, err := s.repo.MotMayIn(ctx, shopID, id)
	if err != nil {
		return err
	}
	m.IsActive = bat

	return s.repo.LuuMayIn(ctx, m)
}

// XoaMayIn — máy đang được gán phiếu thì không xoá: phiếu ấy sẽ lặng lẽ rơi về
// hộp in của trình duyệt mà không ai hay. Nói rõ đang gán cho phiếu nào.
func (s *thietBiService) XoaMayIn(ctx context.Context, id uint) error {
	shopID, err := s.repo.ChiNhanhGhi(ctx)
	if err != nil {
		return err
	}
	m, err := s.repo.MotMayIn(ctx, shopID, id)
	if err != nil {
		return err
	}
	gan, err := s.repo.GanPhieu(ctx, shopID)
	if err != nil {
		return err
	}
	var dang []string
	for _, g := range gan {
		if g.PrinterID != id {
			continue
		}
		p, _ := domain.TimPhieuIn(g.SlipType)
		if !slices.Contains(dang, p.Ten) {
			dang = append(dang, p.Ten)
		}
	}
	if len(dang) > 0 {
		return loiPT(409, m.Name+" đang in "+strings.Join(dang, ", ")+". Gỡ ở tab “Phiếu in ra máy nào” trước, rồi xoá máy.")
	}

	return s.repo.XoaMayIn(ctx, shopID, id)
}

// ---------- Phiếu in ra máy nào ----------

func (s *thietBiService) kiemGan(ctx context.Context, shopID uint, g *domain.GanPhieuIn, req TBGanPhieuRequest) error {
	p, ok := domain.TimPhieuIn(req.SlipType)
	if !ok {
		return loiO(map[string]string{"slip_type": "Loại phiếu không có trong danh mục."})
	}
	if req.PrinterID == 0 {
		return loiO(map[string]string{"printer_id": "Chọn máy in."})
	}
	may, err := s.repo.MotMayIn(ctx, shopID, req.PrinterID)
	if err != nil {
		// Máy của chi nhánh khác cũng rơi vào đây: không cho gán chéo quầy.
		return loiO(map[string]string{"printer_id": "Máy in không thuộc chi nhánh này."})
	}

	nhom := []uint{}
	tatCa := false
	if p.TheoNhom {
		tatCa = req.AllCategories
		if !tatCa {
			for _, id := range req.CategoryIDs {
				if id > 0 && !slices.Contains(nhom, id) {
					nhom = append(nhom, id)
				}
			}
			if len(nhom) == 0 {
				return loiO(map[string]string{"category_ids": "Chọn ít nhất một nhóm hàng, hoặc Tất cả."})
			}
		}
	}

	ds, err := s.repo.GanPhieu(ctx, shopID)
	if err != nil {
		return err
	}
	for _, k := range ds {
		if k.ID == g.ID || k.SlipType != p.Ma || k.PrinterID != may.ID {
			continue
		}
		if !p.TheoNhom {
			return loiO(map[string]string{"printer_id": may.Name + " đã được gán " + p.Ten + " rồi."})
		}
		// Tem: hai dòng của cùng một máy không chung nhóm — một món in hai tem.
		if k.AllCategories || tatCa {
			return loiO(map[string]string{"category_ids": "Trùng với dòng tem đã có của máy này (Tất cả nhóm)."})
		}
		for _, id := range nhom {
			if slices.Contains(k.CategoryIDs, id) {
				return loiO(map[string]string{"category_ids": "Trùng nhóm hàng với dòng tem đã có của máy này."})
			}
		}
	}

	g.SlipType, g.PrinterID, g.AllCategories, g.CategoryIDs = p.Ma, may.ID, tatCa, nhom
	if req.IsActive != nil {
		g.IsActive = *req.IsActive
	}

	return nil
}

func (s *thietBiService) TaoGanPhieu(ctx context.Context, req TBGanPhieuRequest) (*domain.GanPhieuIn, error) {
	shopID, err := s.repo.ChiNhanhGhi(ctx)
	if err != nil {
		return nil, err
	}
	g := &domain.GanPhieuIn{ShopID: shopID, IsActive: true}
	if err := s.kiemGan(ctx, shopID, g, req); err != nil {
		return nil, err
	}

	return g, s.repo.LuuGanPhieu(ctx, g)
}

func (s *thietBiService) SuaGanPhieu(ctx context.Context, id uint, req TBGanPhieuRequest) (*domain.GanPhieuIn, error) {
	shopID, err := s.repo.ChiNhanhGhi(ctx)
	if err != nil {
		return nil, err
	}
	g, err := s.repo.MotGanPhieu(ctx, shopID, id)
	if err != nil {
		return nil, err
	}
	if err := s.kiemGan(ctx, shopID, g, req); err != nil {
		return nil, err
	}

	return g, s.repo.LuuGanPhieu(ctx, g)
}

func (s *thietBiService) BatTatGanPhieu(ctx context.Context, id uint, bat bool) error {
	shopID, err := s.repo.ChiNhanhGhi(ctx)
	if err != nil {
		return err
	}
	g, err := s.repo.MotGanPhieu(ctx, shopID, id)
	if err != nil {
		return err
	}
	g.IsActive = bat

	return s.repo.LuuGanPhieu(ctx, g)
}

func (s *thietBiService) XoaGanPhieu(ctx context.Context, id uint) error {
	shopID, err := s.repo.ChiNhanhGhi(ctx)
	if err != nil {
		return err
	}
	if _, err := s.repo.MotGanPhieu(ctx, shopID, id); err != nil {
		return err
	}

	return s.repo.XoaGanPhieu(ctx, shopID, id)
}

// ---------- Nút in ----------

func (s *thietBiService) LuuNutIn(ctx context.Context, ma string, req TBNutInRequest) error {
	nut, ok := domain.TimNutIn(ma)
	if !ok {
		return domain.ErrNotFound
	}
	shopID, err := s.repo.ChiNhanhGhi(ctx)
	if err != nil {
		return err
	}
	slips := []string{}
	for _, p := range req.Slips {
		if slices.Contains(slips, p) {
			continue
		}
		if !slices.Contains(nut.ChoPhep, p) {
			pp, _ := domain.TimPhieuIn(p)
			ten := pp.Ten
			if ten == "" {
				ten = p
			}

			return loiO(map[string]string{"slips": "Nút " + nut.Ten + " không in được " + ten + "."})
		}
		slips = append(slips, p)
	}
	bat := true
	if req.IsActive != nil {
		bat = *req.IsActive
	}

	return s.repo.LuuNutIn(ctx, &domain.NutIn{ShopID: shopID, Button: nut.Ma, Slips: slips, IsActive: bat})
}

// ---------- Mẫu in ----------

func (s *thietBiService) LuuMauIn(ctx context.Context, ma string, req TBMauInRequest) (*TBMauIn, error) {
	m, ok := domain.TimMauIn(ma)
	if !ok {
		return nil, domain.ErrNotFound
	}
	shopID, err := s.repo.ChiNhanhGhi(ctx)
	if err != nil {
		return nil, err
	}

	o := map[string]string{}
	goc := m.MacDinhDong()
	dong := map[string]bool{}
	for k, b := range req.Options {
		if _, co := goc[k]; !co {
			o["options."+k] = "Mẫu " + m.Ten + " không có dòng này."

			continue
		}
		dong[k] = b
	}
	// Dòng không gửi lên giữ mặc định — màn hình gửi đủ, bên khác gửi thiếu
	// cũng không làm tắt nhầm dòng nào.
	for k, b := range goc {
		if _, co := dong[k]; !co {
			dong[k] = b
		}
	}

	st := req.Settings
	if m.CaiDat.Kho != "" && st.Kho != "" && st.Kho != "58" && st.Kho != "80" {
		o["settings.kho"] = "Khổ giấy chỉ nhận 58 hoặc 80mm."
	}
	if m.CaiDat.Ban != 0 && st.Ban != 0 && (st.Ban < 1 || st.Ban > 99) {
		o["settings.ban"] = "Số bản in từ 1 đến 99."
	}
	if m.CaiDat.KhoTem != "" && st.KhoTem != "" && !slices.Contains(domain.KhoTemDuoc, st.KhoTem) {
		o["settings.kho_tem"] = "Khổ tem không có trong danh sách."
	}
	if m.CaiDat.Cot != 0 && st.Cot != 0 && (st.Cot < 1 || st.Cot > 3) {
		o["settings.cot"] = "Số tem một hàng từ 1 đến 3."
	}
	if m.CaiDat.Co != 0 && st.Co != 0 && (st.Co < 5 || st.Co > 12) {
		o["settings.co"] = "Cỡ chữ tem từ 5 đến 12."
	}
	st.FooterTxt = strings.TrimSpace(st.FooterTxt)
	if m.FooterToiDa > 0 && len([]rune(st.FooterTxt)) > m.FooterToiDa {
		o["settings.footer_txt"] = "Chân trang tối đa " + strconv.Itoa(m.FooterToiDa) + " ký tự."
	}
	st.WifiTxt = strings.TrimSpace(st.WifiTxt)
	if len([]rune(st.WifiTxt)) > 80 {
		o["settings.wifi_txt"] = "Dòng wifi tối đa 80 ký tự."
	}
	st.ChinhSachTxt = strings.TrimSpace(st.ChinhSachTxt)
	if len([]rune(st.ChinhSachTxt)) > 200 {
		o["settings.chinhsach_txt"] = "Chính sách đổi trả tối đa 200 ký tự."
	}
	if len(o) > 0 {
		return nil, loiO(o)
	}

	luu := &domain.MauInLuu{ShopID: shopID, Kind: m.Ma, Options: dong, Settings: ganCaiDat(m, st)}
	if err := s.repo.LuuMauIn(ctx, luu); err != nil {
		return nil, err
	}
	v := gopMau(m, luu)

	return &v, nil
}

func (s *thietBiService) MacDinhMauIn(ctx context.Context, ma string) (*TBMauIn, error) {
	m, ok := domain.TimMauIn(ma)
	if !ok {
		return nil, domain.ErrNotFound
	}
	shopID, err := s.repo.ChiNhanhGhi(ctx)
	if err != nil {
		return nil, err
	}
	if err := s.repo.XoaMauIn(ctx, shopID, m.Ma); err != nil {
		return nil, err
	}
	v := gopMau(m, nil)

	return &v, nil
}

func (s *thietBiService) MauChoQuay(ctx context.Context, ma string) (*TBMauIn, error) {
	m, ok := domain.TimMauIn(ma)
	if !ok {
		return nil, domain.ErrNotFound
	}
	shopID := s.repo.ChiNhanhDoc(ctx)
	if shopID == 0 {
		v := gopMau(m, nil)

		return &v, nil
	}
	ds, err := s.repo.MauIn(ctx, shopID)
	if err != nil {
		return nil, err
	}
	for i := range ds {
		if ds[i].Kind == m.Ma {
			v := gopMau(m, &ds[i])

			return &v, nil
		}
	}
	v := gopMau(m, nil)

	return &v, nil
}
