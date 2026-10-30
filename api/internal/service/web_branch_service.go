package service

import (
	"context"
	"fmt"
	"strings"

	"sass-api/internal/domain"
	"sass-api/internal/dto"
)

// WebChiNhanhService — khu điều hành mở / gỡ web cho từng chi nhánh của một hợp
// đồng Website bán hàng. Mở = cấp một tên miền <tên chi nhánh>.<gốc> trong sổ tên
// miền (app web, shop_id); web (ecommerce_web) nhận chi nhánh theo đúng dòng đó.
type WebChiNhanhService interface {
	DanhSach(ctx context.Context, quyen domain.QuyenApp, hopDongID uint) (dto.WebChiNhanhResponse, error)
	Mo(ctx context.Context, quyen domain.QuyenApp, hopDongID, shopID uint) (dto.WebChiNhanhResponse, error)
	Go(ctx context.Context, quyen domain.QuyenApp, hopDongID, shopID uint) (dto.WebChiNhanhResponse, error)
}

type webChiNhanhService struct {
	hopDong domain.HopDongRepository
	repo    domain.WebChiNhanhRepository
	// tenMienGoc: WEB_BASE_DOMAIN — dev huyweb.selliotech.store, prod web.selliotech.store.
	// Không dùng thẳng <nhãn>.selliotech.store: POS cấp nhãn đó cho gói Chuỗi.
	tenMienGoc string
}

func NewWebChiNhanhService(hopDong domain.HopDongRepository, repo domain.WebChiNhanhRepository, tenMienGoc string) WebChiNhanhService {
	return &webChiNhanhService{hopDong: hopDong, repo: repo, tenMienGoc: strings.ToLower(strings.Trim(tenMienGoc, ". "))}
}

func (s *webChiNhanhService) hopDongWeb(ctx context.Context, quyen domain.QuyenApp, id uint) (*domain.HopDongDayDu, error) {
	hd, err := s.hopDong.Tim(ctx, id)
	if err != nil {
		return nil, err
	}
	if !quyen.ChoPhep(hd.MaApp) {
		return nil, domain.ErrKhongPhuTrachApp
	}
	if hd.MaApp != domain.AppWeb {
		return nil, loiO(map[string]string{"hop_dong": "Chỉ hợp đồng Website bán hàng mới mở web cho chi nhánh"})
	}

	return hd, nil
}

func (s *webChiNhanhService) DanhSach(ctx context.Context, quyen domain.QuyenApp, hopDongID uint) (dto.WebChiNhanhResponse, error) {
	hd, err := s.hopDongWeb(ctx, quyen, hopDongID)
	if err != nil {
		return dto.WebChiNhanhResponse{}, err
	}

	return s.bang(ctx, hd)
}

func (s *webChiNhanhService) bang(ctx context.Context, hd *domain.HopDongDayDu) (dto.WebChiNhanhResponse, error) {
	cns, err := s.repo.ChiNhanh(ctx, hd.TenantID)
	if err != nil {
		return dto.WebChiNhanhResponse{}, err
	}
	tms, err := s.repo.TenMien(ctx, hd.TenantID, hd.AppID)
	if err != nil {
		return dto.WebChiNhanhResponse{}, err
	}
	theoCN := make(map[uint]string, len(tms))
	for _, t := range tms {
		theoCN[t.ShopID] = t.Host
	}

	kq := dto.WebChiNhanhResponse{ChiNhanh: make([]dto.WebChiNhanhItem, 0, len(cns)), ToiDa: hd.MaxShops}
	for _, c := range cns {
		it := dto.WebChiNhanhItem{ID: c.ID, Ma: c.Ma, Ten: c.Ten, DangHoatDong: c.DangMo}
		if h, co := theoCN[c.ID]; co {
			it.TenMien = &h
			kq.DangMo++
		}
		kq.ChiNhanh = append(kq.ChiNhanh, it)
	}

	return kq, nil
}

func (s *webChiNhanhService) Mo(ctx context.Context, quyen domain.QuyenApp, hopDongID, shopID uint) (dto.WebChiNhanhResponse, error) {
	hd, err := s.hopDongWeb(ctx, quyen, hopDongID)
	if err != nil {
		return dto.WebChiNhanhResponse{}, err
	}
	if hd.Status == domain.SubscriptionCanceled {
		return dto.WebChiNhanhResponse{}, loiO(map[string]string{"hop_dong": "Hợp đồng đã huỷ — ký hợp đồng mới rồi mở web"})
	}

	bang, err := s.bang(ctx, hd)
	if err != nil {
		return bang, err
	}
	var cn *dto.WebChiNhanhItem
	for i := range bang.ChiNhanh {
		if bang.ChiNhanh[i].ID == shopID {
			cn = &bang.ChiNhanh[i]
		}
	}
	switch {
	case cn == nil:
		return bang, loiO(map[string]string{"shop_id": "Cửa hàng này không có chi nhánh đó"})
	case cn.TenMien != nil:
		return bang, nil
	case !cn.DangHoatDong:
		return bang, loiO(map[string]string{"shop_id": "Chi nhánh đang ngừng hoạt động bên POS"})
	case hd.MaxShops > 0 && uint(bang.DangMo) >= hd.MaxShops:
		return bang, loiO(map[string]string{"shop_id": fmt.Sprintf(
			"Hợp đồng chỉ cho mở web %d chi nhánh — gỡ web một chi nhánh khác, hoặc ký lại với số chi nhánh lớn hơn", hd.MaxShops)})
	}

	host, err := s.hostConTrong(ctx, cn.Ten, cn.Ma, cn.ID)
	if err != nil {
		return bang, err
	}
	if err := s.repo.Them(ctx, domain.TenMienWeb{TenantID: hd.TenantID, AppID: hd.AppID, ShopID: cn.ID, Host: host}); err != nil {
		return bang, err
	}

	return s.bang(ctx, hd)
}

func (s *webChiNhanhService) Go(ctx context.Context, quyen domain.QuyenApp, hopDongID, shopID uint) (dto.WebChiNhanhResponse, error) {
	hd, err := s.hopDongWeb(ctx, quyen, hopDongID)
	if err != nil {
		return dto.WebChiNhanhResponse{}, err
	}
	if _, err := s.repo.Xoa(ctx, hd.TenantID, hd.AppID, shopID); err != nil {
		return dto.WebChiNhanhResponse{}, err
	}

	return s.bang(ctx, hd)
}

// hostConTrong đặt tên miền theo TÊN CHI NHÁNH (người dùng chốt: không ghép tên
// cửa hàng). host là khoá unique toàn nền tảng, nên trùng thì thêm -2, -3 như
// `cmd/domains tu-dong`.
func (s *webChiNhanhService) hostConTrong(ctx context.Context, ten, ma string, shopID uint) (string, error) {
	goc := domain.NhanTenMienTuTen(ten)
	if goc == "" {
		goc = domain.NhanTenMienTuTen(ma)
	}
	if goc == "" {
		goc = fmt.Sprintf("chinhanh%d", shopID)
	}
	for i := 1; i <= 50; i++ {
		nhan := goc
		if i > 1 {
			nhan = fmt.Sprintf("%s-%d", goc, i)
		}
		if domain.LaNhanDatTruoc(nhan) {
			continue
		}
		host := nhan + "." + s.tenMienGoc
		chu, err := s.repo.ChuCuaHost(ctx, host)
		if err != nil {
			return "", err
		}
		if chu == 0 {
			return host, nil
		}
	}

	return "", fmt.Errorf("không tìm được tên miền trống cho nhãn %q sau 50 lần thử", goc)
}
