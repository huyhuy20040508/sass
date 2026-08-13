package repository

import (
	"context"
	"errors"
	"strings"

	"gorm.io/gorm"

	"sass-api/internal/domain"
)

// tenantDomainRepository đọc sổ tên miền của CONTROL PLANE.
//
// Kết nối truyền vào PHẢI là repository.NewPlatformDB. Không có cách nào để
// trình biên dịch ép điều đó — cả hai đều là *gorm.DB — nên nó được nhắc ở tên
// hàm khởi tạo và ở đây. Đưa nhầm kết nối data plane vào thì bảng
// tenant_domains không tồn tại và MỌI tên miền thành "không tìm thấy": trang bán
// hàng đứng im mà nhật ký chỉ nói "không có tên miền nào khớp".
//
// KHÔNG có bộ lọc tenant trên kết nối này, và đó là bản chất của việc: bảng bên
// đây nói VỀ các cửa hàng chứ không THUỘC cửa hàng nào, và câu truy vấn dưới đây
// chạy TRƯỚC khi biết đang phục vụ cửa hàng nào — nó chính là thứ đi tìm câu trả
// lời đó.
type tenantDomainRepository struct {
	db *gorm.DB
	// maApp là phần mềm mà TIẾN TRÌNH NÀY phục vụ (cfg.App.Code).
	//
	// Sổ tên miền là sổ của cả nền tảng: từ khi một khách mua được nhiều phần
	// mềm (migration 0009), trong đó có cả địa chỉ của những phần mềm khác.
	// Không lọc thì `bida.quochuy.store` phân giải ra đúng cửa hàng Quốc Huy và
	// API bán hàng phục vụ luôn — khách gõ đúng địa chỉ của mình rồi nhìn thấy
	// một sản phẩm khác, mà không có lỗi nào nổi lên ở đâu cả.
	maApp string
}

// NewTenantDomainRepository nhận kết nối CONTROL PLANE (NewPlatformDB) và mã
// phần mềm mà tiến trình này phục vụ.
//
// maApp rỗng bị coi là lỗi lập trình chứ không phải "không lọc": nơi gọi quên
// truyền thì mọi tên miền của mọi phần mềm đều phân giải được ở đây, đúng cái
// mà cột app_id sinh ra để chặn. Vì vậy repo trả về "không tìm thấy" cho mọi
// host — hỏng thì hỏng hẳn và thấy ngay, thay vì phục vụ nhầm.
func NewTenantDomainRepository(platformDB *gorm.DB, maApp string) domain.TenantDomainRepository {
	return &tenantDomainRepository{db: platformDB, maApp: strings.ToLower(strings.TrimSpace(maApp))}
}

// FindTenantByHost tra cửa hàng sở hữu tên miền.
//
// Một câu truy vấn cho mỗi request công khai, KHÔNG có bộ nhớ đệm. Cân nhắc có
// chủ ý: bảng nhỏ, host là khoá unique nên tra một dòng bằng chỉ mục, và cái giá
// của việc đệm là một khoảng thời gian mà tên miền vừa cấp cho khách chưa dùng
// được còn tên miền vừa gỡ thì vẫn còn chạy — hai câu hỏi hỗ trợ khó chịu hơn
// nửa mili giây rất nhiều. Ngày nào con số này lộ ra trong hồ sơ hiệu năng thì
// hãy đệm ở ĐÂY, kèm hạn ngắn và cả kết quả "không có" (nếu không, mỗi header
// Host bịa là một lượt đi xuống database).
//
// Không dùng verified_at làm điều kiện: cột đó nói về việc ĐÃ xác minh DNS để
// xin chứng chỉ HTTPS hay chưa, không phải về quyền sở hữu. Bảng này chỉ khu
// điều hành ghi được (khách không có đường nào tự khai tên miền), nên dòng nào
// có mặt ở đây đều là dòng đã được duyệt.
func (r *tenantDomainRepository) FindTenantByHost(ctx context.Context, host string) (*domain.PlatformTenant, error) {
	host = ChuanHoaHost(host)
	if host == "" || r.maApp == "" {
		return nil, domain.ErrNotFound
	}

	tim := func(h string) (*domain.PlatformTenant, error) {
		var t domain.PlatformTenant
		err := r.db.WithContext(ctx).
			Table("tenants AS t").
			Joins("JOIN tenant_domains d ON d.tenant_id = t.id").
			// Tên miền phải thuộc ĐÚNG phần mềm mà tiến trình này phục vụ. Lọc
			// bằng JOIN sang `apps` chứ không nướng id vào cấu hình: id là số tự
			// sinh của từng database, chép .env sang máy khác là lệch.
			Joins("JOIN apps a ON a.id = d.app_id AND a.code = ?", r.maApp).
			Where("d.host = ?", h).
			Select("t.*").
			First(&t).Error
		if errors.Is(err, gorm.ErrRecordNotFound) {
			return nil, domain.ErrNotFound
		}
		if err != nil {
			return nil, err
		}
		return &t, nil
	}

	t, err := tim(host)
	if !errors.Is(err, domain.ErrNotFound) {
		return t, err
	}

	// Thử lại sau khi bỏ "www.".
	//
	// An toàn vì host là khoá UNIQUE TOÀN BẢNG: "www.x.com" không có trong sổ
	// nghĩa là nó không thuộc về ai, nên trả về chủ của "x.com" không thể lấn
	// sang khách khác. Không có bước này thì khách gõ thêm ba chữ www vào thanh
	// địa chỉ sẽ thấy một trang báo không tìm ra cửa hàng.
	if rest, ok := strings.CutPrefix(host, "www."); ok {
		return tim(rest)
	}

	return nil, domain.ErrNotFound
}

// ChuanHoaHost đưa header Host về đúng dạng đang lưu trong tenant_domains: chữ
// thường, không cổng, không dấu chấm cuối.
//
// Xuất khẩu để công cụ dòng lệnh của khu điều hành chuẩn hoá y hệt lúc GHI —
// hai bên lệch nhau một dấu chấm là tên miền vào sổ rồi mà không phân giải được.
func ChuanHoaHost(host string) string {
	host = strings.ToLower(strings.TrimSpace(host))

	// Cắt cổng. Cắt từ dấu hai chấm CUỐI CÙNG để không cắt nhầm giữa một địa chỉ
	// IPv6 dạng [::1]:8080.
	if i := strings.LastIndex(host, ":"); i > strings.LastIndex(host, "]") {
		host = host[:i]
	}
	host = strings.Trim(host, "[]")

	// Tên miền tuyệt đối ("x.com.") là hợp lệ trong DNS và trình duyệt gửi lên
	// nguyên như vậy nếu người dùng gõ thế.
	return strings.TrimSuffix(host, ".")
}
