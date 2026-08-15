package domain

import (
	"context"
	"time"
)

// Thực thể của CONTROL PLANE — lược đồ selliotech_platform.
//
// ĐỌC KỸ TRƯỚC KHI DÙNG: mấy kiểu dưới đây chỉ đúng khi đi cùng KẾT NỐI THỨ HAI
// (cfg.Platform, xem cmd/api/main.go). Tên bảng của chúng — `tenants` — trùng
// đúng tên một bảng bên data plane, nên đưa nhầm *gorm.DB của data plane vào
// thì truy vấn vẫn CHẠY và vẫn trả về dữ liệu, chỉ là dữ liệu của bảng khác.
// Không có cách nào để trình biên dịch bắt lỗi đó, nên repository nào đọc mấy
// kiểu này phải nhận đúng kết nối control plane ngay ở hàm khởi tạo.
//
// Không nướng tên database vào TableName() ("selliotech_platform.tenants") vì
// tên đó là của môi trường — PLATFORM_DB_NAME ở máy cục bộ, máy thử và máy thật
// đều khác nhau.
//
// KHÔNG có khoá ngoại nào bắc qua hai lược đồ (xem đầu tệp migration), nên ở
// đây cũng không khai quan hệ GORM nào trỏ sang thực thể của data plane —
// Preload một quan hệ như vậy sẽ sinh JOIN sang database khác.

// PlatformTenant là một dòng trong sổ đăng ký khách hàng của nền tảng.
//
// Khác domain.Tenant (bảng `tenants` của data plane) ở mục đích: Tenant là bảng
// cha của mọi khoá ngoại tenant_id và là thứ API đọc lúc đăng nhập; còn kiểu này
// là sổ ghi khách đó là ai, đang mở hay đang khoá, liên lạc với ai.
//
// ID là SỐ CHUNG với domain.Tenant.ID và hôm nay do data plane cấp — bảng bên
// này không AUTO_INCREMENT, mỗi dòng phải ghi thẳng id đã có bên kia.
type PlatformTenant struct {
	ID   uint   `json:"id" gorm:"primaryKey"`
	Code string `json:"code"`
	Name string `json:"name"`
	// Status: active | suspended — cùng bộ giá trị với Tenant.Status. Quyết định
	// nằm ở đây, nhưng API chặn đăng nhập bằng cột bên data plane, nên đổi ở đây
	// thì PHẢI ghi sang bên kia.
	Status       string       `json:"status"`
	ContactName  StringOrNull `json:"contact_name"`
	ContactEmail StringOrNull `json:"contact_email"`
	ContactPhone StringOrNull `json:"contact_phone"`
	Note         StringOrNull `json:"note"`
	CreatedAt    time.Time    `json:"created_at"`
	UpdatedAt    time.Time    `json:"updated_at"`
}

func (PlatformTenant) TableName() string { return "tenants" }

// Subscription là gói khách đang dùng CHO MỘT PHẦN MỀM, và hạn của nó.
//
// Một tenant có tối đa một thuê bao còn hiệu lực TRÊN MỖI APP — ràng buộc này
// do khoá uq_subscriptions_current (tenant_id, app_id, current_mark) dưới
// database giữ, không phải do tầng Go tự nhớ. Khách mua hai phần mềm là HAI
// dòng, hai hạn, hai giá.
//
// Gia hạn = đẩy EndsAt của chính dòng đó; đổi gói = huỷ dòng cũ rồi thêm dòng
// mới.
type Subscription struct {
	ID       uint `json:"id" gorm:"primaryKey"`
	TenantID uint `json:"tenant_id"`
	// AppID là phần mềm mà thuê bao này mua. Mọi màn hình của khu điều hành đều
	// tách theo nó: doanh thu, khách dùng thử, khách đã trả tiền.
	AppID uint `json:"app_id"`
	// Plan là MÃ GÓI, tra ở `plans` theo (AppID, Plan, BillingCycle).
	//
	// Chuỗi tự do dưới database (VARCHAR, không còn ENUM) vì mỗi app có bộ gói
	// riêng — xem migration 0008. Nghĩa là database KHÔNG từ chối mã gõ sai nữa:
	// nơi lập hợp đồng phải tự tra bảng giá trước khi ghi.
	Plan string `json:"plan"`
	// PlanID là DÒNG bảng giá đã ký — CHỈ ĐỂ TRUY VẾT (migration 0011).
	//
	// TUYỆT ĐỐI KHÔNG đọc giá, hạn mức hay tính năng qua nó. Bảng giá được phép
	// đổi; hợp đồng đã ký thì không. Giá của hợp đồng là Price ngay dưới, hạn
	// mức là MaxShops/MaxUsers — tra sang Plan là biến hợp đồng thành thứ đi
	// theo bảng giá hiện hành, và lần sửa giá tới sẽ đổi luôn hợp đồng của
	// người đang trả tiền.
	//
	// nil = hợp đồng không sinh ra từ dòng bảng giá nào (thoả thuận riêng),
	// KHÁC "chưa điền".
	PlanID *uint `json:"plan_id"`
	// Status: trial | active | past_due | canceled
	Status string `json:"status"`
	// BillingCycle: thang | nam
	BillingCycle string  `json:"billing_cycle"`
	Price        float64 `json:"price"`
	// MaxShops / MaxUsers là hạn mức ĐÃ CHỐT với khách lúc ký, không phải hạn
	// mức của bảng giá hiện hành — đọc thẳng ở dòng này chứ đừng suy ra từ Plan
	// hay PlanID.
	MaxShops uint `json:"max_shops"`
	// MaxUsers / MaxProducts = 0 nghĩa là KHÔNG GIỚI HẠN (bản dịch của 'vo_han'
	// bên bảng giá). Mặc định dưới database là 1, nên quên điền thì khách nhận
	// đúng một — hỏng theo chiều lộ ra ngay, không phải chiều không ai thấy.
	//
	// CHƯA CÓ AI ÉP ba hạn mức này: chỗ ép đúng là data plane, lúc tạo chi
	// nhánh / tài khoản / sản phẩm, mà bên đó không đọc được sổ nền tảng. Chúng
	// có mặt để ngày viết chỗ ép thì con số đã nằm sẵn trong hợp đồng của từng
	// khách, không phải đi điền ngược giữa lúc họ đang dùng.
	MaxUsers    uint `json:"max_users"`
	MaxProducts uint `json:"max_products"`
	// OwnDomain: hợp đồng này có kèm tên miền riêng không — CHÉP từ bảng giá lúc
	// ký (migration 0013).
	//
	// Nơi cấp tên miền (`cmd/ten-mien`) đọc CỘT NÀY. Trước 0013 nó tra ngược về
	// `plan_features`, nghĩa là quyền lợi của khách đang trả tiền bị quyết bởi
	// bảng giá của hôm nay — bỏ tên miền riêng khỏi gói Chuỗi là khách Chuỗi đã
	// ký mất luôn quyền đó, không lỗi nào nổi lên.
	OwnDomain   bool         `json:"own_domain"`
	StartedAt   time.Time    `json:"started_at"`
	EndsAt      time.Time    `json:"ends_at"`
	TrialEndsAt *time.Time   `json:"trial_ends_at"`
	CanceledAt  *time.Time   `json:"canceled_at"`
	Note        StringOrNull `json:"note"`
	CreatedAt   time.Time    `json:"created_at"`
	UpdatedAt   time.Time    `json:"updated_at"`
}

func (Subscription) TableName() string { return "subscriptions" }

// Trạng thái thuê bao.
const (
	SubscriptionTrial    = "trial"
	SubscriptionActive   = "active"
	SubscriptionPastDue  = "past_due"
	SubscriptionCanceled = "canceled"
)

// Ba mã gói của app 'order'. Giá trị khớp cột `code` của bảng giá `plans`.
//
// Từ migration 0008, `subscriptions.plan` là VARCHAR chứ không còn ENUM: mỗi
// app có bộ gói riêng, nên thêm gói mới cho một app là THÊM DÒNG trong bảng
// giá, không phải sửa lược đồ. Ba hằng số dưới đây vẫn có ích cho code nào cần
// gọi thẳng tên gói của app 'order'.
const (
	PlanKhoiDau = "khoi_dau"
	PlanCuaHang = "cua_hang"
	PlanChuoi   = "chuoi"
)

// App là một phần mềm nền tảng bán ra.
//
// Hôm nay danh mục có đúng một dòng — AppOrder, phần mềm quản trị bán hàng
// đang chạy ở order.selliotech.store. Vì chỉ có một nên chưa chỗ nào phải hỏi
// "app nào": Subscription ghi gói mà không ghi gói CỦA app nào. Bảng có mặt để
// tới lúc có sản phẩm thứ hai thì không phải vừa dựng bảng vừa điền ngược cho
// các thuê bao đang chạy.
//
// Code — chứ không phải ID — là thứ đem đi dùng ở nơi khác (tiền tố tên miền,
// cấu hình, về sau là giá trị trong JWT/URL): ID là số tự sinh của một
// database, chép cấu hình sang máy khác là lệch.
type App struct {
	ID   uint   `json:"id" gorm:"primaryKey"`
	Code string `json:"code"`
	Name string `json:"name"`
	// Tagline: một dòng mô tả để hiện trong khu điều hành và bảng giá.
	Tagline StringOrNull `json:"tagline"`
	// Status: planned | active | retired
	Status    string    `json:"status"`
	CreatedAt time.Time `json:"created_at"`
	UpdatedAt time.Time `json:"updated_at"`
}

func (App) TableName() string { return "apps" }

// Mã của các app. Giá trị khớp cột `code` dưới database.
const (
	// AppOrder là phần mềm ĐANG CHẠY — dòng duy nhất của bảng apps hôm nay.
	AppOrder = "order"
)

// AppTrongDanhMuc là một phần mềm kèm con số nói nó đã bán được chưa.
//
// SoGoiDangBan trả lời câu đầu tiên người điều hành hỏi khi mở danh mục: phần
// mềm này đã có bảng giá chưa. App có mặt trong danh mục mà 0 gói đang bán thì
// không ai mua được, dù `status` ghi 'active' — hai thứ đó độc lập nhau, và
// nhìn `status` một mình sẽ hiểu nhầm.
type AppTrongDanhMuc struct {
	App
	SoGoiDangBan int `json:"so_goi_dang_ban"`
}

// AppRepository đọc DANH MỤC PHẦN MỀM của control plane.
//
// CHẠY TRÊN CONTROL PLANE (repository.NewPlatformDB), cùng ràng buộc với
// PlanRepository.
//
// Trước khi có port này, bảng `apps` chỉ được đọc ké qua JOIN trong hai repo
// khác (bảng giá và sổ tên miền) — đủ để hiển thị tên app kèm một dòng bảng
// giá, nhưng không trả lời được câu "nền tảng đang bán những phần mềm nào".
// Phần mềm vừa thêm vào danh mục mà chưa có gói nào sẽ không xuất hiện ở đâu
// cả, kể cả với chính người vừa thêm nó.
type AppRepository interface {
	// List trả về toàn bộ danh mục, sắp theo mã.
	//
	// KHÔNG lọc theo `status`: khu điều hành phải thấy cả app 'planned' (đang
	// dựng) lẫn 'retired' (ngừng bán) — đó chính là màn hình để quản lý vòng đời
	// của chúng. Nơi nào chỉ được bán app đang chạy thì tự xét lấy.
	List(ctx context.Context) ([]AppTrongDanhMuc, error)
}

// Trạng thái của một app trong danh mục.
//
// Mặc định dưới database là 'planned' chứ không phải 'active': dòng mới thêm mà
// tự nhiên bán được là cách nhanh nhất để ký hợp đồng cho phần mềm chưa tồn tại.
// AppRetired là NGỪNG BÁN, không phải ngừng chạy — khách cũ vẫn dùng tiếp.
const (
	AppPlanned = "planned"
	AppActive  = "active"
	AppRetired = "retired"
)

// Plan là MỘT MỨC GIÁ trong bảng giá hiện hành: gói này, app này, chu kỳ này.
//
// ĐÂY LÀ BẢNG GIÁ, KHÔNG PHẢI HỢP ĐỒNG. Subscription CHÉP Price/MaxShops ra
// lúc ký và từ đó sống độc lập — đừng bao giờ hiển thị giá của thuê bao bằng
// cách tra ngược về đây, vì bảng giá được phép đổi còn hợp đồng đã ký thì
// không. Chiều tra cứu hợp lệ duy nhất là lấy TÊN gói để hiển thị.
//
// Một gói bán theo tháng và theo năm là HAI dòng Plan, hai giá — khoá duy nhất
// là (AppID, Code, BillingCycle).
type Plan struct {
	ID    uint   `json:"id" gorm:"primaryKey"`
	AppID uint   `json:"app_id"`
	Code  string `json:"code"`
	Name  string `json:"name"`
	// Tagline: một dòng "gói này dành cho ai".
	Tagline StringOrNull `json:"tagline"`
	// BillingCycle: thang | nam
	BillingCycle string `json:"billing_cycle"`
	// Price nil = "Liên hệ" (gói Chuỗi): chưa có giá công khai, KHÁC 0 là miễn
	// phí. Subscription.Price thì không nil được — ký hợp đồng là phải có số.
	Price *float64 `json:"price"`
	// HẠN MỨC VÀ TÍNH NĂNG KHÔNG CÒN Ở ĐÂY. `max_shops` và `own_domain` từng là
	// hai cột của bảng này; migration 0005 chuyển chúng sang `plan_features` dạng
	// khoá · giá trị và bỏ hai cột đi. Đọc chúng qua PlanFeature (hoặc
	// PlanRepository.Features) chứ đừng thêm lại cột: thêm một hạn mức mới mà
	// phải chạy migration nghĩa là điều khoản bán hàng bị nhốt trong lược đồ.
	TrialDays uint `json:"trial_days"`
	// Status: active | retired. Retired là NGỪNG BÁN MỚI — không xoá dòng, vì
	// thuê bao cũ còn tra tên gói ở đây.
	Status    string    `json:"status"`
	CreatedAt time.Time `json:"created_at"`
	UpdatedAt time.Time `json:"updated_at"`
}

func (Plan) TableName() string { return "plans" }

// Trạng thái của một mức giá.
const (
	PlanStatusActive  = "active"
	PlanStatusRetired = "retired"
)

// Chu kỳ tính tiền. Cùng bộ giá trị với Subscription.BillingCycle.
const (
	CycleThang = "thang"
	CycleNam   = "nam"
)

// PlanFeature là MỘT điều khoản của một dòng bảng giá: gói này cho phép gì, bao
// nhiêu. Bảng khoá · giá trị, mỗi dòng một khoá.
//
// VÌ SAO KHOÁ · GIÁ TRỊ chứ không phải cột: hạn mức là điều khoản BÁN HÀNG, thứ
// đổi theo tháng và do người bán quyết. Mỗi hạn mức mới mà phải thêm một cột thì
// đổi chính sách giá cần một migration, một lần dựng lại và một lần triển khai.
// Cái giá phải trả là database không giữ hộ kiểu dữ liệu nữa — chỗ canh thay vào
// đó là registry trong service/plan_service.go.
//
// BA TRẠNG THÁI, đừng trộn làm một (xem migration 0005):
//
//   - có dòng, giá trị là số     — bảng giá chốt đúng con số đó;
//   - có dòng, giá trị VoHan     — bán không giới hạn;
//   - KHÔNG có dòng              — bảng giá không quy định, chốt lúc ký hợp
//     đồng. Đây là chỗ của gói Chuỗi ("từ hai cửa hàng trở lên"), và nó thay
//     cho `max_shops IS NULL` của bảng giá cũ.
//
// Gắn vào PlanID chứ không vào mã gói: một gói bán theo tháng và theo năm là hai
// dòng Plan, và hai dòng đó được phép khác hạn mức.
type PlanFeature struct {
	ID     uint `json:"-" gorm:"primaryKey"`
	PlanID uint `json:"-"`
	// Key là mã khoá — phải có trong registry của service, nếu không API từ chối
	// ghi. Cột dưới database tên `feature_key` vì `key` là từ khoá của MySQL.
	Key       string    `json:"key" gorm:"column:feature_key"`
	Value     string    `json:"value"`
	CreatedAt time.Time `json:"-"`
	UpdatedAt time.Time `json:"-"`
}

func (PlanFeature) TableName() string { return "plan_features" }

// Mã các khoá tính năng. Nơi tiêu thụ tham chiếu hằng số này chứ không viết
// chuỗi thẳng, để đổi tên khoá là trình biên dịch chỉ ra hết chỗ hỏng.
//
// Danh sách ĐẦY ĐỦ (kiểu, nhãn, trần) nằm ở registry bên service — ở đây chỉ có
// những khoá mà code ngoài service phải gọi tên.
const (
	// FeatureMaxShops là số chi nhánh của gói. Đây chính là con số người lập hợp
	// đồng chép sang Subscription.MaxShops; không có dòng nào thì bảng giá không
	// quy định và người ký phải tự điền — quên thì mặc định bên kia là 1, tức là
	// bán gói chuỗi mà khách chỉ mở được một chi nhánh.
	FeatureMaxShops = "max_shops"
	// FeatureMaxUsers là số tài khoản nhân viên. Hôm nay CHƯA có chỗ nào ép hạn
	// mức này lúc tạo tài khoản — nó mới là điều khoản để hiển thị và để bán.
	FeatureMaxUsers = "max_users"
	// FeatureMaxProducts là số sản phẩm. Cùng tình trạng với FeatureMaxUsers.
	FeatureMaxProducts = "max_products"
	// FeatureOwnDomain: gói này có được cấp tên miền riêng không (cả subdomain
	// mình cấp lẫn tên miền của khách — `TenantDomain.Kind` mới là chỗ phân biệt
	// hai loại).
	//
	// ĐÂY LÀ ĐIỀU KHOẢN BÁN HÀNG NẰM TRONG DỮ LIỆU, cố ý không viết thành
	// `if plan == "chuoi"` trong code: đổi chính sách là sửa một ô trong bảng
	// giá, không phải sửa code rồi triển khai lại. Nơi ép luật là `cmd/ten-mien`,
	// đường duy nhất ghi vào sổ tên miền.
	FeatureOwnDomain = "own_domain"
)

// VoHan là giá trị nói "không giới hạn" — đúng câu "Không giới hạn sản phẩm" của
// gói Cửa hàng trên landing.
//
// KHÁC hẳn 0 (không được cái nào) và khác hẳn không có dòng (bảng giá không nói
// gì). Nhét cả ba vào một ô trống là ngày mai không ai phân biệt nổi "bán không
// giới hạn" với "chưa ai điền".
const VoHan = "vo_han"

// PlanRepository đọc/ghi BẢNG GIÁ và các điều khoản của nó.
//
// CHẠY TRÊN CONTROL PLANE: hiện thực phải nhận kết nối thứ hai
// (repository.NewPlatformDB). Đưa nhầm kết nối data plane vào thì bảng `plans`
// không tồn tại bên đó và mọi lượt đọc thành lỗi — ồn ào, nên đây là kiểu nhầm
// dễ thấy hơn của TenantDomainRepository.
type PlanRepository interface {
	// List trả về bảng giá, kèm mã app của từng dòng để nơi gọi khỏi phải tra
	// thêm. appCode rỗng = mọi app.
	List(ctx context.Context, appCode string) ([]PlanWithApp, error)
	// Find tra một dòng bảng giá theo id, ErrNotFound nếu không có.
	Find(ctx context.Context, id uint) (*PlanWithApp, error)
	// Features đọc điều khoản của MỘT gói.
	Features(ctx context.Context, planID uint) (map[string]string, error)
	// FeaturesOf đọc điều khoản của NHIỀU gói trong một lượt — màn hình danh sách
	// gọi nó thay vì Features trong vòng lặp.
	FeaturesOf(ctx context.Context, planIDs []uint) (map[uint]map[string]string, error)
	// SaveFeatures ghi các khoá trong `dat` và XOÁ các khoá trong `xoa`, trong một
	// giao dịch: hạn mức của một gói không được nửa mới nửa cũ.
	//
	// Xoá là một hành động RIÊNG chứ không phải ghi giá trị rỗng, vì "không có
	// dòng" mang nghĩa riêng — bảng giá không quy định (xem PlanFeature).
	SaveFeatures(ctx context.Context, planID uint, dat map[string]string, xoa []string) error
	// Sua ghi các cột THƯƠNG MẠI của một dòng bảng giá: tên, mô tả, giá, số ngày
	// dùng thử, còn bán hay không.
	//
	// KHÔNG đụng tới (app_id, code, billing_cycle). Bộ ba đó là DANH TÍNH của
	// dòng — khoá duy nhất của bảng — và hợp đồng đã ký tra tên gói về đây theo
	// `code`. Sửa mã của một dòng đang có khách nghĩa là hợp đồng của họ trỏ vào
	// hư không, mà không có gì báo. Muốn một mức giá khác thì thêm dòng mới.
	Sua(ctx context.Context, id uint, dat SuaPlan) error
}

// SuaPlan là phần SỬA ĐƯỢC của một dòng bảng giá.
//
// Là struct riêng chứ không dùng lại Plan: Plan có cả danh tính (app_id, code,
// billing_cycle) lẫn dấu thời gian, và nhận nguyên nó ở tầng ghi là mở đường cho
// một lượt sửa giá lỡ tay đổi luôn mã gói.
type SuaPlan struct {
	Name    string
	Tagline string
	// Price nil = "Liên hệ" (chưa công bố giá), KHÁC 0 là miễn phí. Đây là lý do
	// nó là con trỏ chứ không phải float64 — xem Plan.Price.
	Price     *float64
	TrialDays uint
	// Status: PlanStatusActive | PlanStatusRetired.
	Status string
}

// PlanWithApp là một dòng bảng giá kèm app của nó.
//
// Ghép ở tầng repository bằng một JOIN trong CÙNG lược đồ control plane — khác
// hẳn việc ghép hai plane, thứ luôn phải làm bằng tay ở tầng Go.
type PlanWithApp struct {
	Plan
	AppCode string `json:"app_code"`
	AppName string `json:"app_name"`
	// AppStatus là trạng thái của PHẦN MỀM, khác hẳn Plan.Status (trạng thái của
	// một mức giá). Hai thứ đó độc lập: một gói 'active' của một app 'planned'
	// vẫn không bán được, và nơi ký hợp đồng phải xét cả hai.
	AppStatus string `json:"app_status"`
}

// PlatformUserRepository là sổ NGƯỜI CỦA NỀN TẢNG — nơi khu điều hành xác thực
// và nơi nó xét quyền.
//
// CHẠY TRÊN CONTROL PLANE. Đây là bảng thay thế hoàn toàn cho cách cũ (mượn
// `super_admin` của một cửa hàng): vai trò đó là vai trò cao nhất TRONG MỘT
// TIỆM, mà tiệm nào cũng có một người như vậy — xem migration 0007.
//
// Cả hai hàm chỉ trả về dòng ĐANG SỐNG VÀ ĐANG HOẠT ĐỘNG. Bị khoá, đã xoá mềm,
// hay không tồn tại đều ra ErrNotFound: nơi gọi không cần phân biệt, và người
// gõ thử cũng không được biết mình vừa chạm đúng một tài khoản có thật.
type PlatformUserRepository interface {
	// FindByEmail tra theo email đã chuẩn hoá (chữ thường, không khoảng trắng
	// thừa) — đường đăng nhập dùng hàm này.
	FindByEmail(ctx context.Context, email string) (*PlatformUser, error)
	// FindByID tra theo id trong token. Mỗi request của khu điều hành gọi nó một
	// lần, và đó là chủ ý: khoá một người hay hạ vai trò của họ phải có hiệu lực
	// NGAY, không phải chờ token cũ hết hạn.
	FindByID(ctx context.Context, id uint) (*PlatformUser, error)
	// GhiLanDangNhap đánh dấu lần đăng nhập cuối.
	//
	// Hỏng thì nơi gọi ĐỪNG chặn đăng nhập: không ghi được một cái mốc thời gian
	// là chuyện nhỏ, đá người ta ra khỏi cửa vì chuyện đó mới là chuyện lớn.
	GhiLanDangNhap(ctx context.Context, id uint) error
	// QuyenApp đọc tập phần mềm được giao cho người này.
	//
	// owner KHÔNG có dòng nào trong bảng gán (xem migration 0010) — hiện thực
	// phải trả về ToanQuyen cho họ chứ đừng trả tập rỗng, không thì chủ nền tảng
	// là người duy nhất không vào được sản phẩm nào.
	QuyenApp(ctx context.Context, nguoi *PlatformUser) (QuyenApp, error)
}

// Invoice là MỘT LẦN TIỀN VÀO, không phải một hoá đơn đã phát hành.
//
// Doanh thu đọc ở đây chứ KHÔNG suy từ Subscription.Price: giá của hợp đồng nói
// "mỗi chu kỳ bao nhiêu", tức tiền ĐÁNG LẼ phải thu. Suy doanh thu từ nó chỉ
// đúng vào tháng mà mọi khách trả đủ, đúng hạn, không ai bỏ giữa chừng.
//
// PaidAt (tiền vào lúc nào) khác PeriodStart/PeriodEnd (trả cho chu kỳ nào), và
// hai thứ đó lệch nhau là bình thường — khách trả chậm nửa tháng. Báo cáo tiền
// mặt đọc PaidAt.
type Invoice struct {
	ID             uint      `json:"id" gorm:"primaryKey"`
	SubscriptionID uint      `json:"subscription_id"`
	Amount         float64   `json:"amount"`
	PeriodStart    time.Time `json:"period_start"`
	PeriodEnd      time.Time `json:"period_end"`
	PaidAt         time.Time `json:"paid_at"`
	// Method: chuyen_khoan | tien_mat | khac
	Method    string       `json:"method"`
	Reference StringOrNull `json:"reference"`
	Note      StringOrNull `json:"note"`
	CreatedAt time.Time    `json:"created_at"`
	UpdatedAt time.Time    `json:"updated_at"`
}

func (Invoice) TableName() string { return "invoices" }

// Hình thức tiền vào. Khớp ENUM của cột `invoices.method` — thêm giá trị mới ở
// đây mà quên migration thì MySQL từ chối cả câu INSERT.
const (
	PaymentChuyenKhoan = "chuyen_khoan"
	PaymentTienMat     = "tien_mat"
	PaymentKhac        = "khac"
)

// LocKhuDieuHanh là bộ lọc dùng chung của mọi màn hình khu điều hành.
//
// MaApp là danh sách mã phần mềm được phép đọc. nil = KHÔNG lọc (chỉ owner),
// còn slice RỖNG = không phần mềm nào, tức không dòng nào. Phân biệt hai thứ đó
// là bắt buộc: lẫn lộn thì người chưa được giao phần mềm nào lại nhìn thấy tất.
type LocKhuDieuHanh struct {
	MaApp []string
	// TrangThai lọc theo trạng thái thuê bao (trial | active | past_due |
	// canceled). Rỗng = mọi trạng thái.
	TrangThai string
	// Nhom lọc theo LOẠI hợp đồng chứ không theo trạng thái hiện tại của nó —
	// xem NhomDungThu. Rỗng = cả hai nhóm.
	Nhom string
	// Tu/Den giới hạn khoảng thời gian TIỀN VÀO, chỉ dùng cho báo cáo doanh thu.
	Tu  *time.Time
	Den *time.Time
}

// Hai NHÓM hợp đồng, tương ứng đúng hai màn hình "Người dùng thử" và "Người
// dùng chính thức" của khu điều hành.
//
// VÌ SAO KHÔNG LỌC BẰNG `status`, dù hai màn hình từng làm đúng như vậy
// (`?trang_thai=trial` và `?trang_thai=active`): trạng thái ĐỔI theo thời gian
// còn màn hình thì không. Hợp đồng thử hết hạn thành `past_due`, bấm huỷ thành
// `canceled` — và với bộ lọc theo trạng thái thì cả hai BIẾN MẤT khỏi màn hình
// đang mở, đúng lúc người ta cần nhìn thấy chúng nhất: khách vừa hết hạn là
// khách phải gọi điện, khách vừa huỷ là khách phải hỏi vì sao.
//
// Nhóm thì KHÔNG đổi: một hợp đồng sinh ra từ kỳ dùng thử vẫn mãi là hợp đồng
// dùng thử, dù hôm nay nó đang chạy, đã quá hạn hay đã đóng. Danh sách vì thế
// ổn định, và `trang_thai` của từng dòng trở thành thông tin hiển thị — đúng
// vai của nó.
//
// PHÂN BIỆT BẰNG `trial_ends_at`, không bằng một cột mới: cột đó có mặt đúng ở
// hợp đồng có giai đoạn thử, và GiaHan xoá nó đi đúng lúc chuyển sang chính
// thức — nên khách trả tiền lần đầu tự chuyển sang màn hình bên kia, không cần
// ai đánh dấu gì.
const (
	NhomDungThu   = "dung_thu"
	NhomChinhThuc = "chinh_thuc"
)

// KhachHangTrongSo là một khách hàng kèm tình trạng hợp đồng của họ.
//
// SoHopDong đếm hợp đồng CÒN HIỆU LỰC trong phạm vi lọc — khách có mặt trong sổ
// mà 0 hợp đồng là khách đã dừng hẳn, và đó là thứ màn hình phải phân biệt được
// với khách đang dùng.
type KhachHangTrongSo struct {
	PlatformTenant
	SoHopDong int `json:"so_hop_dong"`
}

// HopDongDayDu là một thuê bao kèm tên khách và mã phần mềm — thứ màn hình
// "Người dùng thử" / "Người dùng chính thức" hiển thị.
type HopDongDayDu struct {
	Subscription
	MaCuaHang  string `json:"ma_cua_hang"`
	TenCuaHang string `json:"ten_cua_hang"`
	MaApp      string `json:"ma_app"`
	TenApp     string `json:"ten_app"`
	// TenGoi là TÊN HIỂN THỊ của gói ("Cửa hàng"), tra từ bảng giá qua PlanID.
	//
	// Đây là chiều tra cứu hợp lệ DUY NHẤT từ hợp đồng sang bảng giá — xem chú
	// thích ở Plan. Giá và hạn mức thì tuyệt đối không: chúng đã chép ra lúc ký
	// và phải đọc thẳng ở dòng hợp đồng.
	//
	// Rỗng khi PlanID nil (hợp đồng thoả thuận riêng, không sinh từ dòng bảng giá
	// nào) hoặc dòng bảng giá đã bị xoá. Nơi hiển thị phải rơi về `Plan` — mã gói
	// xấu nhưng vẫn nói được cái gì đó, còn ô trống thì không.
	TenGoi StringOrNull `json:"ten_goi"`
	// Ba ô liên lạc chép từ SỔ KHÁCH HÀNG (`tenants` của control plane), không
	// phải từ hợp đồng: người gọi khách lúc sắp hết hạn cần số điện thoại ngay
	// trên dòng đó. Chúng đổi được sau khi ký, và đúng là phải như vậy — hợp đồng
	// thì không đổi, còn người liên lạc thì có.
	NguoiLienHe StringOrNull `json:"nguoi_lien_he"`
	DienThoai   StringOrNull `json:"dien_thoai"`
	Email       StringOrNull `json:"email"`
	// Ba ô dưới đây chỉ màn hình CHI TIẾT dùng tới. Danh sách không in chúng, nhưng
	// chúng đi cùng câu truy vấn sẵn có thay vì thêm một lượt đọc thứ hai — chi
	// phí bằng không, và giữ một hình dạng dữ liệu thay vì hai.
	GhiChuKhach      StringOrNull `json:"ghi_chu_khach"`
	NgayVaoSo        time.Time    `json:"ngay_vao_so"`
	TrangThaiCuaHang string       `json:"trang_thai_cua_hang"`
	// DaThuDen là `period_end` XA NHẤT trong sổ thu của CHÍNH hợp đồng này —
	// cùng con số KyCuoiDaThu đọc, chỉ là lấy kèm ngay trong câu truy vấn danh
	// sách thay vì một lượt đọc cho mỗi dòng.
	//
	// nil = chưa thu lần nào, và đó là câu trả lời hợp lệ chứ không phải lỗi.
	//
	// CÓ MẶT ĐỂ MÀN HÌNH BIẾT CÒN KỲ NÀO ĐỂ THU KHÔNG. Sổ thu chỉ nhận một lần
	// thu cho một kỳ (uq_invoices_ky), nên hợp đồng đã trả tới đúng hạn hiện tại
	// thì lượt thu tiếp theo chắc chắn bị từ chối — xem ErrKhongConKyDeThu. Cột
	// "Đã thu" gộp theo CỬA HÀNG không nói được điều đó: nó cộng tiền của mọi
	// hợp đồng và không mang theo kỳ nào cả.
	DaThuDen *time.Time `json:"da_thu_den"`
}

// SuaHopDong là phần SỬA ĐƯỢC của một hợp đồng và của khách đứng sau nó.
//
// DANH SÁCH NÀY CỐ TÌNH NGẮN. Gói, chu kỳ, giá và ba hạn mức KHÔNG có mặt: đó
// là điều khoản đã ký, và cả hệ thống dựng trên nguyên tắc chúng không đổi (xem
// Subscription). Bán thêm quyền lợi cho một khách là việc của `cmd/thue-bao`,
// nơi có bảng đối chiếu in ra trước mắt người ký — không phải một ô nhập trên
// màn hình danh sách.
//
// Thứ sửa được ở đây đều là THÔNG TIN, không phải điều khoản: khách đổi tên
// tiệm, đổi người phụ trách, đổi số điện thoại. Những thứ đó đổi thật, và không
// có chỗ nào khác để sửa chúng.
type SuaHopDong struct {
	TenCuaHang    string
	NguoiLienHe   string
	DienThoai     string
	Email         string
	GhiChuKhach   string
	GhiChuHopDong string
	// HetHan nil = giữ nguyên. Chỉ nhận khi hợp đồng còn ĐANG DÙNG THỬ: hạn của
	// một kỳ dùng thử là quyết định bán hàng, còn hạn của hợp đồng đã trả tiền là
	// điều khoản — muốn dài thêm thì gia hạn, để đường tiền và đường hạn đi cùng
	// nhau.
	HetHan *time.Time
}

// DoanhThuTheoQuan là tổng tiền ĐÃ THU của một cửa hàng trong khoảng lọc.
type DoanhThuTheoQuan struct {
	TenantID   uint    `json:"tenant_id"`
	MaCuaHang  string  `json:"ma_cua_hang"`
	TenCuaHang string  `json:"ten_cua_hang"`
	SoLanThu   int     `json:"so_lan_thu"`
	TongTien   float64 `json:"tong_tien"`
	LanCuoi    *string `json:"lan_cuoi"`
}

// KhachHangRepository đọc sổ KHÁCH HÀNG · HỢP ĐỒNG · TIỀN ĐÃ THU của control
// plane — ba màn hình còn lại của khu điều hành.
//
// CHẠY TRÊN CONTROL PLANE (repository.NewPlatformDB), cùng ràng buộc với
// PlanRepository và AppRepository.
//
// Mọi hàm nhận LocKhuDieuHanh và PHẢI tôn trọng MaApp: đây là ba đường đọc
// xuyên khách hàng, nên bỏ sót bộ lọc ở một hàm là để người phụ trách phần mềm
// này nhìn thấy khách của phần mềm kia.
type KhachHangRepository interface {
	// KhachHang liệt kê khách hàng CÓ hợp đồng trong phạm vi lọc.
	KhachHang(ctx context.Context, loc LocKhuDieuHanh) ([]KhachHangTrongSo, error)
	// HopDong liệt kê thuê bao, mới nhất trước.
	HopDong(ctx context.Context, loc LocKhuDieuHanh) ([]HopDongDayDu, error)
	// DoanhThu cộng tiền ĐÃ THU theo từng cửa hàng.
	DoanhThu(ctx context.Context, loc LocKhuDieuHanh) ([]DoanhThuTheoQuan, error)
}

// HopDongRepository GHI vào sổ hợp đồng của control plane.
//
// Tách khỏi KhachHangRepository (chỉ đọc) vì hai bên có mức nguy hiểm khác hẳn
// nhau: quên bộ lọc app ở một hàm đọc là để người này nhìn thấy khách của người
// kia; quên ở một hàm ghi là để họ SỬA hợp đồng của khách người kia. Cửa vào của
// nhóm này vì thế nằm ở service — mọi hàm dưới đây nhận id đã được đối chiếu
// phạm vi, repository không tự xét lấy.
//
// CHẠY TRÊN CONTROL PLANE (repository.NewPlatformDB), cùng ràng buộc với
// KhachHangRepository.
type HopDongRepository interface {
	// UpsertKhachHang chép cửa hàng sang sổ nền tảng.
	//
	// ID KHÔNG tự tăng ở bảng này: nó là số chung với data plane, nên nơi gọi
	// phải truyền id đã có bên kia. Upsert chứ không insert vì khách có thể đã
	// nằm sẵn trong sổ từ một hợp đồng của phần mềm khác.
	//
	// Trả ErrMaConTrongSoNenTang khi `code` đang thuộc về một id KHÁC. Xem lỗi
	// đó về việc vì sao im lặng ghi tiếp lại hỏng nặng hơn là dừng.
	UpsertKhachHang(ctx context.Context, kh PlatformTenant) error
	// AiDangMangMa trả id của khách trong sổ đang mang mã này, 0 nếu mã còn
	// trống. `ngoaiTru` là id được phép mang mã đó (chính khách đang ghi); truyền
	// 0 khi chưa có id — lúc dựng khách mới, mọi dòng mang mã này đều là xung đột.
	//
	// Có mặt để chặn TRƯỚC khi dựng cửa hàng. Để lượt chép khách tự phát hiện thì
	// vẫn đúng, nhưng lúc đó cửa hàng đã dựng xong và mã đã bị chiếm ở data plane
	// — người bấm nút phải đi dọn một cửa hàng mồ côi trước khi thử lại.
	AiDangMangMa(ctx context.Context, ma string, ngoaiTru uint) (uint, error)
	// Tao ghi một hợp đồng mới. Đụng khoá uq_subscriptions_current thì trả về
	// ErrHopDongDangChay chứ không phải lỗi thô của MySQL.
	Tao(ctx context.Context, s *Subscription) error
	// Tim tra một hợp đồng theo id, kèm tên khách và mã app — mã app là thứ
	// service cần để đối chiếu phạm vi trước khi cho ghi.
	Tim(ctx context.Context, id uint) (*HopDongDayDu, error)
	// GiaHan đẩy hạn hợp đồng thêm soThang tháng và đưa nó về 'active'.
	//
	// Mốc tính là GREATEST(ends_at, NOW()) chứ không phải ends_at: gia hạn một
	// hợp đồng đã quá hạn ba tháng mà cộng dồn từ ngày cũ thì khách trả tiền
	// xong vẫn còn quá hạn.
	//
	// Đây CŨNG LÀ đường chuyển dùng thử sang chính thức — trial_ends_at bị xoá và
	// trạng thái thành 'active'. Hai việc đó là một; tách thành hai hàm thì bản
	// thứ hai sẽ quên một trong hai vế.
	GiaHan(ctx context.Context, id uint, soThang int) error
	// Huy đóng hợp đồng, ghi lý do vào note. Không xoá dòng: khách cũ vẫn phải
	// tra được mình đã dùng gì.
	Huy(ctx context.Context, id uint, lyDo string) error
	// Sua ghi phần sửa được của hợp đồng VÀ của khách đứng sau nó.
	//
	// Hai bảng trong MỘT giao dịch — làm được vì `tenants` và `subscriptions` của
	// control plane nằm cùng một lược đồ. Khác hẳn lúc mở tài khoản dùng thử, nơi
	// phải bắc qua hai database và không có giao dịch chung nào.
	Sua(ctx context.Context, id, tenantID uint, hs SuaHopDong) error
	// KyCuoiDaThu trả về `period_end` xa nhất đã ghi trong sổ thu của hợp đồng.
	//
	// nil = chưa thu lần nào. Nơi gọi dùng nó làm ĐIỂM BẮT ĐẦU của kỳ sắp thu, để
	// hai lần thu liên tiếp không chồng lấn và cũng không để hở khoảng giữa.
	KyCuoiDaThu(ctx context.Context, id uint) (*time.Time, error)
	// ThuTien ghi MỘT LẦN TIỀN VÀO.
	//
	// Đụng khoá uq_invoices_ky (subscription_id, period_start) thì trả về
	// ErrDaThuKyNay — ghi trùng một kỳ là cách dễ nhất để doanh thu tháng đó
	// phồng gấp đôi mà không ai thấy sai.
	// Nhận CON TRỎ để trả lại id vừa cấp: đơn gia hạn của khách phải chỉ ra được
	// dòng sổ thu tương ứng, nếu không thì hai bảng nói hai chuyện và không ai
	// đối chiếu được.
	ThuTien(ctx context.Context, hd *Invoice) error
	// TongDaThu cộng tiền đã thu của MỘT hợp đồng, kèm số lần thu.
	TongDaThu(ctx context.Context, id uint) (tong float64, soLan int, err error)
	// TenantDangCoHopDong trả về id những khách ĐANG có hợp đồng còn hiệu lực cho
	// một phần mềm.
	//
	// Dùng để trừ ra khỏi danh sách cửa hàng có thể ký mới: khoá
	// uq_subscriptions_current chỉ cho mỗi khách một hợp đồng còn sống trên mỗi
	// app, nên mời người ta chọn một cửa hàng đã có hợp đồng là mời họ ăn lỗi
	// trùng khoá sau khi đã điền xong form.
	TenantDangCoHopDong(ctx context.Context, maApp string) ([]uint, error)

	// ---------- Quét hợp đồng quá hạn ----------
	//
	// Bốn hàm dưới đây phục vụ MỘT việc: tới hạn mà chưa trả tiền thì khoá cửa
	// hàng lại. Xem service.QuetHanService về thứ tự và lý do.

	// QuaHan liệt kê hợp đồng đang chạy (`trial` hoặc `active`) mà `ends_at` đã
	// lùi về trước `moc`.
	//
	// KHÔNG tự ghi gì: người gọi còn phải hỏi thêm ConHopDongSong trước khi khoá,
	// và gộp cả ba việc vào một câu SQL thì không chỗ nào ghi được nhật ký nói rõ
	// khách nào vừa bị khoá vì hợp đồng nào.
	QuaHan(ctx context.Context, moc time.Time) ([]HopDongQuaHan, error)
	// ConHopDongSong lọc ra những khách VẪN CÒN một hợp đồng chưa tới hạn.
	//
	// Có mặt vì `tenants.status` là của CẢ KHÁCH HÀNG chứ không của riêng một
	// phần mềm: khách mua hai phần mềm, hết hạn một cái, mà khoá luôn cửa hàng
	// thì họ mất nốt cái đang còn trả tiền. Hôm nay mới bán một app nên danh sách
	// này gần như luôn rỗng — và đó chính là lúc dễ quên nhất.
	ConHopDongSong(ctx context.Context, tenantIDs []uint, moc time.Time) ([]uint, error)
	// DanhDauQuaHan đưa các hợp đồng sang `past_due`. Trả về số dòng đã đổi.
	//
	// KHÔNG đụng `ends_at`: cái mốc đó là ngày hợp đồng chết, và nó phải đứng
	// nguyên để lượt gia hạn sau còn biết khách nợ từ bao giờ.
	DanhDauQuaHan(ctx context.Context, ids []uint) (int64, error)
	// SapHetHan liệt kê hợp đồng đang chạy sẽ hết hạn TRONG KHOẢNG [bayGio, den).
	//
	// Khác QuaHan ở chiều thời gian: bên kia nhặt hợp đồng đã chết để khoá cửa
	// hàng, còn đây nhặt hợp đồng CÒN SỐNG để nhắc khách trước khi nó chết.
	SapHetHan(ctx context.Context, bayGio, den time.Time) ([]HopDongQuaHan, error)
	// DanhDauDaNhac ghi "đã nhắc hợp đồng này trong ngày đó".
	//
	// Trả về true nếu ĐÂY là lượt nhắc đầu tiên trong ngày, false nếu đã nhắc rồi.
	// Hiện thực phải dựa vào KHOÁ CHÍNH của bảng chứ đừng đọc trước rồi ghi: lượt
	// quét chạy 5 phút một lần và hai tiến trình cùng đọc thấy "chưa nhắc" sẽ cùng
	// gửi, biến cái chuông của khách thành nơi không ai bấm nữa.
	DanhDauDaNhac(ctx context.Context, subscriptionID uint, ngay time.Time, conLaiNgay int) (bool, error)
	// DoiTrangThaiKhach ghi `tenants.status` của SỔ NỀN TẢNG.
	//
	// Bản sao ở control plane, chỉ để khu điều hành nhìn đúng. Chốt chặn thật
	// nằm ở data plane — xem CuaHangMoiRepository.DoiTrangThai.
	DoiTrangThaiKhach(ctx context.Context, tenantIDs []uint, trangThai string) (int64, error)
}

// ThueBaoCuaKhachRepository đọc thuê bao của ĐÚNG MỘT khách — đường đọc duy nhất
// đi từ phía CỬA HÀNG sang sổ nền tảng.
//
// CHẠY TRÊN CONTROL PLANE (repository.NewPlatformDB), cùng ràng buộc với
// KhachHangRepository. Khác nó ở chỗ nguy hiểm: ba hàm bên kia đọc XUYÊN mọi
// khách và được canh bằng middleware của khu điều hành, còn hàm dưới đây chạy
// sau token của một cửa hàng bất kỳ. Vì thế nó nhận tenantID làm THAM SỐ BẮT
// BUỘC và không có biến thể nào bỏ điều kiện đó ra — quên lọc ở đây nghĩa là chủ
// tiệm này đọc được hợp đồng của tiệm khác.
//
// Lưu ý bộ lọc tenant tự động KHÔNG có tác dụng ở đây: nó chỉ gắn vào kết nối
// data plane, còn sổ nền tảng thì không. Điều kiện `tenant_id = ?` phải nằm ngay
// trong câu truy vấn.
type ThueBaoCuaKhachRepository interface {
	// HienTai trả về hợp đồng CÒN HIỆU LỰC của một khách cho một phần mềm.
	//
	// "Còn hiệu lực" = mọi trạng thái trừ `canceled`, khớp đúng định nghĩa của
	// khoá uq_subscriptions_current — nên kết quả nhiều nhất là một dòng. Hợp
	// đồng quá hạn (`past_due`) VẪN trả về: đó chính là lúc chủ tiệm mở trang gói
	// dịch vụ ra xem, và giấu nó đi thì màn hình nói "chưa có hợp đồng nào" ngay
	// giữa lúc khách cần biết mình vừa hết hạn.
	//
	// ErrNotFound = khách chưa có hợp đồng nào trong sổ, một trạng thái hợp lệ.
	HienTai(ctx context.Context, tenantID uint, maApp string) (*HopDongDayDu, error)
}

// HopDongQuaHan là MỘT hợp đồng đã chạy quá hạn mà chưa ai đóng lại.
//
// Đủ thông tin để GHI NHẬT KÝ ra một dòng người đọc hiểu được: khoá cửa hàng
// của khách là việc nặng nhất mà máy chủ này tự làm không cần ai bấm nút, nên
// "khách nào, phần mềm nào, hết hạn lúc nào" phải tra lại được.
type HopDongQuaHan struct {
	ID        uint
	TenantID  uint
	MaCuaHang string
	MaApp     string
	HetHan    time.Time
	// DungThu = true: hợp đồng chết ở kỳ DÙNG THỬ, khách chưa từng trả đồng nào.
	// Khác hẳn khách cũ ngừng đóng tiền, và người gọi điện cho họ cần biết.
	DungThu bool
}

// CuaHangMoi là bộ ba thứ cần để dựng một khách hàng mới ở DATA PLANE: cửa hàng,
// chi nhánh mặc định, và tài khoản quản trị đầu tiên của họ.
//
// Đúng thứ `cmd/tao-admin` dựng. Gom thành một struct chứ không phải sáu tham số
// chuỗi liền nhau: sáu tham số cùng kiểu string thì hoán vị nhầm hai cái là lỗi
// mà trình biên dịch không bắt được, và cái hoán vị đó ghi thẳng xuống database.
type CuaHangMoi struct {
	Ma          string
	Ten         string
	TenDangNhap string
	HoTen       string
	Email       string
	// BamMatKhau là bcrypt của mật khẩu, KHÔNG phải mật khẩu thô. Băm ở tầng
	// service; repository không được nhận mật khẩu thô để không có đường nào ghi
	// nhầm nó xuống nguyên văn.
	BamMatKhau string
}

// CuaHangMoiRepository dựng một cửa hàng mới ở DATA PLANE.
//
// CHẠY TRÊN DATA PLANE (repository.NewDB — kết nối CÓ bộ lọc tenant). Đây là
// repository duy nhất của khu điều hành chạm vào data plane, và nó tồn tại vì
// một lý do: bán phần mềm cho khách mới nghĩa là phải có chỗ cho họ đăng nhập,
// mà chỗ đó nằm bên kia ranh giới.
//
// `tenants` là bảng toàn cục nên không cần tenant trong ctx; `shops` và `users`
// thì có, và hiện thực phải gắn tenant VỪA TẠO vào ctx trước khi ghi hai bảng đó
// — xem chú thích ở hiện thực.
// QuanTriCuaHang là TÀI KHOẢN QUẢN TRỊ của một cửa hàng — ô thứ hai của màn
// hình đăng nhập 3 ô, phía khách.
//
// KHÔNG có mật khẩu ở đây, kể cả bản băm. Khu điều hành không cần đọc nó và
// không được đọc nó: đường duy nhất chạm tới mật khẩu của khách là ĐẶT LẠI, và
// đường đó chỉ ghi.
type QuanTriCuaHang struct {
	ID          uint   `json:"id"`
	TenDangNhap string `json:"ten_dang_nhap"`
	HoTen       string `json:"ho_ten"`
	Email       string `json:"email"`
	// TrangThai: active | inactive.
	TrangThai string `json:"trang_thai"`
}

// TaiKhoanCuaHangRepository đọc và đặt lại mật khẩu TÀI KHOẢN QUẢN TRỊ của một
// cửa hàng.
//
// CHẠY TRÊN DATA PLANE (repository.NewDB). Tách khỏi CuaHangMoiRepository vì
// vòng đời khác hẳn: bên kia chỉ dựng cửa hàng MỚI, còn đây đụng vào tài khoản
// của một khách đang chạy.
//
// VÌ SAO KHU ĐIỀU HÀNH ĐƯỢC ĐẶT LẠI MẬT KHẨU KHÁCH: tài khoản quản trị của cửa
// hàng KHÔNG có đường tự khôi phục nào — quên-mật-khẩu-qua-email chỉ tồn tại
// trong cụm storefront dành cho khách mua sắm. Khách quên mật khẩu thì gọi cho
// nhà cung cấp phần mềm, và đây là chỗ nhà cung cấp làm được việc đó.
type TaiKhoanCuaHangRepository interface {
	// QuanTri trả về tài khoản quản trị của cửa hàng, ErrNotFound nếu không có.
	//
	// Một cửa hàng có thể có NHIỀU super_admin. Hàm này trả về người CŨ NHẤT —
	// tài khoản đầu tiên được dựng cùng cửa hàng, tức người mà "đổi mật khẩu cho
	// khách" gần như luôn có nghĩa là họ. Nơi hiển thị phải in tên đăng nhập ra
	// để người bấm nút biết mình đang đổi mật khẩu của ai.
	QuanTri(ctx context.Context, tenantID uint) (*QuanTriCuaHang, error)
	// DoiMatKhau ghi mật khẩu đã băm. Nhận CẢ tenantID để điều kiện WHERE mang
	// theo cửa hàng — id người dùng đến từ một lượt đọc trước đó, và bắt buộc nó
	// đi kèm cửa hàng nghĩa là không có đường nào đổi nhầm sang tài khoản của
	// khách khác dù id có bị truyền sai.
	DoiMatKhau(ctx context.Context, tenantID, userID uint, bamMatKhau string) error
}

// CuaHangCoSan là một cửa hàng ĐÃ TỒN TẠI ở data plane — ứng viên để ký hợp
// đồng, khác hẳn CuaHangMoi (thứ sắp được dựng).
type CuaHangCoSan struct {
	ID  uint   `json:"id"`
	Ma  string `json:"ma"`
	Ten string `json:"ten"`
	// TrangThai: active | suspended. Trả ra để màn hình in kèm — ký hợp đồng cho
	// một cửa hàng đang bị khoá là chuyện hợp lệ (ký xong mở khoá), nhưng người
	// ký phải biết mình đang ký cho cái gì.
	TrangThai string `json:"trang_thai"`
}

type CuaHangMoiRepository interface {
	// CoMa cho biết mã cửa hàng đã có người dùng chưa. Tính cả cửa hàng đang bị
	// khoá: mã là duy nhất toàn hệ thống, không phải duy nhất trong số đang mở.
	CoMa(ctx context.Context, ma string) (bool, error)
	// Tao dựng cửa hàng + chi nhánh mặc định + tài khoản quản trị, trả về
	// tenant_id vừa cấp. Trọn gói trong một giao dịch: một cửa hàng có mặt mà
	// không ai đăng nhập được vào là thứ tệ hơn cả không tạo gì.
	Tao(ctx context.Context, moi CuaHangMoi) (uint, error)
	// DanhSach liệt kê MỌI cửa hàng đang có ở data plane, sắp theo mã.
	//
	// KHÔNG lọc "cửa hàng nào chưa ký hợp đồng" ở đây, dù đó mới là thứ màn hình
	// cần: câu hỏi ấy cần đọc `subscriptions` bên CONTROL PLANE, mà hai lược đồ
	// không JOIN được. Việc trừ ra thuộc về service — nơi cầm cả hai kết nối.
	DanhSach(ctx context.Context) ([]CuaHangCoSan, error)
	// TimTheoMa tra một cửa hàng theo mã, ErrNotFound nếu không có.
	TimTheoMa(ctx context.Context, ma string) (*CuaHangCoSan, error)
	// DoiTrangThai ghi `tenants.status` ở DATA PLANE. Trả về số dòng đã đổi.
	//
	// ĐÂY LÀ CHỐT CHẶN THẬT, không phải bản ở control plane: cột này là thứ
	// authService.LoginShop đọc lúc đăng nhập và middleware.JWTAuth đọc ở mọi
	// request, nên ghi hụt ở đây nghĩa là khách hết hạn vẫn dùng tiếp bình
	// thường — dù sổ nền tảng đã ghi "đã khoá".
	//
	// Nhận cả danh sách vì lượt quét hạn khoá nhiều khách một lượt: một câu
	// UPDATE ... IN (...) thay cho N câu.
	DoiTrangThai(ctx context.Context, ids []uint, trangThai string) (int64, error)
}

// PlatformSetting là MỘT ô cấu hình của NHÀ CUNG CẤP phần mềm, dạng khoá · giá
// trị.
//
// KHÔNG CÓ tenant_id, và đó là khác biệt quan trọng nhất với `settings` bên data
// plane (bảng cùng vai trò nhưng của từng cửa hàng): đây là cấu hình của chính
// mình, một bộ duy nhất cho cả nền tảng. Lẫn hai bảng là đem số tài khoản của
// mình ghi đè cấu hình của một khách hàng.
//
// KHÔNG CẤT BÍ MẬT ở đây. Số tài khoản ngân hàng là thông tin công khai; khoá API
// của cổng thanh toán thì không, và chúng ở lại .env cho tới ngày có chỗ mã hoá
// đàng hoàng — xem migration 0015.
type PlatformSetting struct {
	Key       string    `json:"key" gorm:"column:setting_key;primaryKey"`
	Value     string    `json:"value"`
	UpdatedAt time.Time `json:"updated_at"`
}

func (PlatformSetting) TableName() string { return "platform_settings" }

// PlatformSettingRepository đọc/ghi cấu hình của nhà cung cấp.
//
// CHẠY TRÊN CONTROL PLANE (repository.NewPlatformDB), cùng ràng buộc với
// PlanRepository. Đưa nhầm kết nối data plane vào thì bảng `platform_settings`
// không tồn tại bên đó và mọi lượt gọi đều lỗi — nhầm kiểu ồn ào, dễ thấy.
type PlatformSettingRepository interface {
	// All trả về TOÀN BỘ dòng đang có, dạng map khoá → giá trị.
	//
	// Khoá thiếu dòng KHÔNG được điền hộ ở đây: giá trị mặc định là chuyện của
	// registry bên service, và trộn hai nguồn lại thì không ai phân biệt được
	// "người bán đã khai đúng bằng mặc định" với "chưa ai khai bao giờ".
	All(ctx context.Context) (map[string]string, error)
	// Save ghi nhiều khoá trong MỘT giao dịch.
	//
	// Tất-cả-hoặc-không: một bộ thông tin chuyển khoản ghi được nửa chừng là số
	// tài khoản mới đứng cạnh tên chủ tài khoản cũ — khách chuyển tiền theo đó thì
	// tiền đi đúng một nơi không ai ngờ.
	Save(ctx context.Context, items map[string]string) error
}

// PlatformUser là tài khoản của KHU ĐIỀU HÀNH nền tảng — mình và người làm cùng,
// KHÔNG phải nhân viên bán hàng của khách (những người đó ở bảng users của data
// plane và đăng nhập bằng 3 ô).
//
// Không có TenantID: người của nền tảng không thuộc cửa hàng nào. Đó chính là
// lý do bảng này tồn tại thay vì dùng vai trò super_admin trong users — xem
// migration control plane.
type PlatformUser struct {
	ID       uint   `json:"id" gorm:"primaryKey"`
	Email    string `json:"email"`
	FullName string `json:"full_name"`
	// PasswordHash nil = tài khoản CÓ trong sổ nhưng CHƯA đặt mật khẩu, nên chưa
	// đăng nhập được. Đây là trạng thái hợp lệ và cố ý giữ lại (xem migration
	// 0007): thêm người vào sổ và giao mật khẩu cho họ là hai việc, và việc thứ
	// hai thường xảy ra ở một thời điểm khác.
	//
	// Nơi xác thực phải coi nil là TỪ CHỐI, không phải là "bỏ qua bước mật khẩu".
	PasswordHash *string `json:"-"`
	// Role: owner | operator | support
	Role string `json:"role"`
	// Status: active | locked
	Status      string     `json:"status"`
	LastLoginAt *time.Time `json:"last_login_at"`
	CreatedAt   time.Time  `json:"created_at"`
	UpdatedAt   time.Time  `json:"updated_at"`
	DeletedAt   *time.Time `json:"-"`
}

func (PlatformUser) TableName() string { return "platform_users" }

// Vai trò trong khu điều hành.
const (
	PlatformRoleOwner    = "owner"
	PlatformRoleOperator = "operator"
	PlatformRoleSupport  = "support"
)

// QuyenApp là TẬP PHẦN MỀM mà một người điều hành được đụng vào.
//
// Tách khỏi vai trò vì hai câu hỏi khác nhau: vai trò trả lời "được xem hay
// được sửa", còn cái này trả lời "được xem/sửa CỦA PHẦN MỀM NÀO". Người phụ
// trách bida là operator (sửa được), nhưng chỉ trong phạm vi bida.
//
// Zero value (ToanQuyen=false, Ma rỗng) nghĩa là KHÔNG được phần mềm nào — mặc
// định an toàn: quên nạp quyền thì mọi thứ đóng, không phải mọi thứ mở.
type QuyenApp struct {
	// ToanQuyen = true: nhìn mọi phần mềm, kể cả phần mềm vừa thêm vào danh mục
	// sáng nay. Chỉ vai trò owner được như vậy (xem migration 0010).
	ToanQuyen bool
	// Ma là mã các app được giao. Chỉ có nghĩa khi ToanQuyen = false.
	Ma []string
}

// ChoPhep trả lời "người này có được đụng vào app này không".
func (q QuyenApp) ChoPhep(ma string) bool {
	if q.ToanQuyen {
		return true
	}
	for _, m := range q.Ma {
		if m == ma {
			return true
		}
	}

	return false
}

// KhongCoAppNao = true khi người này chưa được giao phần mềm nào.
//
// Dùng để nói đúng lý do trên màn hình ("chưa được giao phần mềm nào") thay vì
// để họ nhìn một danh sách rỗng và tưởng nền tảng chưa bán gì.
func (q QuyenApp) KhongCoAppNao() bool { return !q.ToanQuyen && len(q.Ma) == 0 }

// PlatformRoleGhiDuoc trả lời "vai trò này có được SỬA sổ nền tảng không".
//
// `support` là vai trò chỉ đọc, và đó là toàn bộ lý do nó tồn tại tách khỏi
// `operator`: người trực hỗ trợ cần nhìn thấy khách đang ở gói nào để trả lời
// điện thoại, nhưng không cần — và không nên — đổi được bảng giá của cả nền
// tảng giữa lúc đang nghe máy.
//
// Hàm nằm ở domain chứ không nằm trong handler vì nó là luật, và ngày có màn
// hình thứ hai của khu điều hành thì màn hình đó phải hỏi cùng một câu.
func PlatformRoleGhiDuoc(role string) bool {
	return role == PlatformRoleOwner || role == PlatformRoleOperator
}

// TenantDomain là tên miền trỏ vào một cửa hàng.
//
// Host lưu CHỮ THƯỜNG, không scheme, không cổng — nó phải so khớp thẳng được
// với header Host của request.
type TenantDomain struct {
	ID       uint `json:"id" gorm:"primaryKey"`
	TenantID uint `json:"tenant_id"`
	// AppID là phần mềm mà địa chỉ này phục vụ.
	//
	// Một khách mua nhiều phần mềm thì có nhiều tên miền, mỗi cái trỏ vào một
	// sản phẩm khác nhau — nên phân giải tên miền phải lọc theo app của chính
	// tiến trình đang chạy, không thì khách gõ đúng địa chỉ của mình mà nhìn
	// thấy nhầm sản phẩm. Xem migration 0009.
	AppID uint   `json:"app_id"`
	Host  string `json:"host"`
	// Kind: subdomain | custom
	Kind      string `json:"kind"`
	IsPrimary bool   `json:"is_primary"`
	// VerifiedAt NULL = chưa xác minh DNS. Chưa xác minh mà đã xin chứng chỉ
	// HTTPS thì Let's Encrypt từ chối, hỏng nhiều lần liên tiếp là bị khoá hạn
	// mức cả tuần.
	VerifiedAt *time.Time `json:"verified_at"`
	CreatedAt  time.Time  `json:"created_at"`
	UpdatedAt  time.Time  `json:"updated_at"`
}

func (TenantDomain) TableName() string { return "tenant_domains" }

// TenantDomainRepository tra cửa hàng theo tên miền của request.
//
// CHẠY TRÊN CONTROL PLANE. Hiện thực của nó phải nhận kết nối thứ hai
// (repository.NewPlatformDB) — đưa nhầm kết nối data plane vào thì câu truy vấn
// vẫn chạy vì bên đó cũng có bảng `tenants`, chỉ là nó không có tenant_domains
// và mọi tên miền sẽ thành "không tìm thấy".
//
// Đây là port DUY NHẤT của luồng phục vụ request đọc sang control plane, nên nó
// cũng là chỗ quyết định control plane có phải thành phần sống còn hay không:
// bật cụm bán hàng cho khách mà sổ này không đọc được thì không tên miền nào
// phân giải được, tức là cả trang bán hàng đứng im.
type TenantDomainRepository interface {
	// FindTenantByHost trả về cửa hàng sở hữu tên miền, kèm trạng thái của nó để
	// nơi gọi tự quyết định có phục vụ hay không.
	//
	// host phải đã chuẩn hoá: chữ thường, không scheme, không cổng.
	// Không có tên miền nào khớp thì trả ErrNotFound.
	FindTenantByHost(ctx context.Context, host string) (*PlatformTenant, error)
}
