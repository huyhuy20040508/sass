package domain

import "context"

// AppWeb là mã của Website bán hàng (database/platform 0019): mỗi CHI NHÁNH một
// web, tên miền trỏ tới chi nhánh qua tenant_domains.shop_id.
const AppWeb = "web"

// AppBanThem là các app BÁN THÊM trên một cửa hàng POS. Chúng tự đóng khi hết
// hạn (web tự kiểm hợp đồng của mình) và KHÔNG quyết định cửa hàng sống hay bị
// khoá — tenants.status là cửa của POS. Đếm chúng vào thì cửa hàng hết hạn POS mà
// còn hợp đồng web vẫn dùng POS tiếp, không mất đồng nào.
var AppBanThem = []string{AppWeb}

func LaAppBanThem(maApp string) bool {
	for _, a := range AppBanThem {
		if a == maApp {
			return true
		}
	}

	return false
}

// ChiNhanhCuaKhach là một chi nhánh còn sống của một cửa hàng — đọc từ DATA PLANE.
type ChiNhanhCuaKhach struct {
	ID     uint
	Ma     string
	Ten    string
	DangMo bool // shops.is_active
}

// TenMienWeb là một dòng tenant_domains của app web.
type TenMienWeb struct {
	TenantID uint
	AppID    uint
	ShopID   uint
	Host     string
}

// WebChiNhanhRepository bắc qua HAI plane: chi nhánh ở data plane, sổ tên miền ở
// control plane. Không giao dịch nào bao được cả hai — chỉ GHI ở control plane.
type WebChiNhanhRepository interface {
	ChiNhanh(ctx context.Context, tenantID uint) ([]ChiNhanhCuaKhach, error)
	TenMien(ctx context.Context, tenantID, appID uint) ([]TenMienWeb, error)
	// ChuCuaHost trả tenant_id đang giữ host, 0 nếu host còn trống.
	ChuCuaHost(ctx context.Context, host string) (uint, error)
	Them(ctx context.Context, tm TenMienWeb) error
	Xoa(ctx context.Context, tenantID, appID, shopID uint) (int64, error)
}
