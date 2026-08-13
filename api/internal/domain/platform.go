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
}

// PlanWithApp là một dòng bảng giá kèm app của nó.
//
// Ghép ở tầng repository bằng một JOIN trong CÙNG lược đồ control plane — khác
// hẳn việc ghép hai plane, thứ luôn phải làm bằng tay ở tầng Go.
type PlanWithApp struct {
	Plan
	AppCode string `json:"app_code"`
	AppName string `json:"app_name"`
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
	// Tu/Den giới hạn khoảng thời gian TIỀN VÀO, chỉ dùng cho báo cáo doanh thu.
	Tu  *time.Time
	Den *time.Time
}

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
