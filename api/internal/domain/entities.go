// Package domain chứa entity nghiệp vụ (GORM models) và interface (ports).
// Tầng này KHÔNG phụ thuộc framework — chỉ là dữ liệu và hợp đồng.
package domain

import (
	"database/sql/driver"
	"fmt"
	"strings"
	"time"

	"gorm.io/gorm"
)

// EnumOrNull dùng cho các cột ENUM cho phép NULL (users.gender, products.kit_type).
//
// MySQL trên máy chủ bật STRICT_TRANS_TABLES nên ghi chuỗi rỗng ” vào cột ENUM
// sẽ lỗi "Error 1265: Data truncated for column". Máy dev không bật strict nên
// ” lọt qua âm thầm — lỗi chỉ nổ khi lên production. Kiểu này quy đổi chuỗi
// rỗng thành NULL lúc ghi và NULL thành chuỗi rỗng lúc đọc, nên phần còn lại của
// code vẫn dùng string bình thường.
type EnumOrNull string

func (e EnumOrNull) Value() (driver.Value, error) {
	if e == "" {
		return nil, nil
	}
	return string(e), nil
}

func (e *EnumOrNull) Scan(v any) error {
	switch s := v.(type) {
	case nil:
		*e = ""
	case string:
		*e = EnumOrNull(s)
	case []byte:
		*e = EnumOrNull(s)
	default:
		return fmt.Errorf("không đọc được giá trị enum: %v", v)
	}
	return nil
}

// StringOrNull là EnumOrNull dùng cho cột VARCHAR NULL nằm trong UNIQUE index
// (users.facebook_id, users.google_id). Chưa có giá trị thì BẮT BUỘC ghi NULL, không được ghi
// chuỗi rỗng: UNIQUE cho phép bao nhiêu dòng NULL cũng được, nhưng chuỗi rỗng chỉ
// lọt đúng MỘT dòng — tài khoản thường thứ hai đăng ký sẽ vỡ vì trùng khoá.
type StringOrNull = EnumOrNull

// TenantOwned nhúng vào MỌI entity nằm ở bảng có cột tenant_id.
//
// Nó phục vụ hai việc, và việc thứ hai mới là lý do nó tồn tại dưới dạng một
// kiểu riêng thay vì một dòng `TenantID uint` chép đi chép lại:
//
//  1. Cho GORM biết cột tenant_id có tồn tại, để câu INSERT ghi được nó. Không
//     có trường này thì bộ lọc không có chỗ nào để đặt giá trị vào, và dòng mới
//     rơi vào cửa hàng nào là do database tự đoán.
//
//  2. Làm DẤU ĐỌC ĐƯỢC BẰNG MÁY. Bài kiểm tra ở domain/tenant_owned_test.go
//     duyệt mọi entity trong gói này và bắt lỗi entity nào của bảng thuộc tenant
//     mà quên nhúng — nghĩa là quên một bảng không còn là chuyện phải nhớ.
//
// `<-:create` (ghi lúc tạo, không ghi lúc sửa) là chủ ý: tenant của một dòng dữ
// liệu KHÔNG BAO GIỜ đổi. Cho phép UPDATE cột này nghĩa là mở một đường chuyển
// dữ liệu từ cửa hàng này sang cửa hàng khác — chỉ cần một lệnh Save() trên một
// struct dựng thiếu trường là đủ.
//
// json:"-" vì đây là chuyện nội bộ của hệ thống: khách hàng không cần biết mình
// là số mấy, và con số đó lộ ra ngoài là gợi ý sẵn tham số để người ta thử sửa.
type TenantOwned struct {
	TenantID uint `json:"-" gorm:"column:tenant_id;<-:create"`
}

// ---------- 1. Xác thực & người dùng ----------

type Role struct {
	ID          uint      `json:"id" gorm:"primaryKey"`
	Name        string    `json:"name"`
	DisplayName string    `json:"display_name"`
	Description string    `json:"description"`
	CreatedAt   time.Time `json:"created_at"`
	UpdatedAt   time.Time `json:"updated_at"`
}

// RoleLabel là tên MỘT cửa hàng đặt cho MỘT vai trò, đè lên tên mặc định trong
// bảng roles.
//
// Vì sao phải có bảng riêng: roles là bảng dùng chung (bốn dòng, id cố định, code
// tham chiếu thẳng bằng SuperAdminRoleID = 1...), nên ghi tên hiển thị vào đó là
// ghi cho MỌI khách hàng — cửa hàng này đổi "Nhân viên" thành "Thu ngân" thì cửa
// hàng kia mở trang lên thấy nhân viên của mình đổi tên. Tách nhãn ra đây thì
// bảng roles chỉ còn được ĐỌC trong luồng phục vụ request.
//
// Không có nhãn = dùng tên mặc định của roles. Đó là trạng thái của mọi cửa hàng
// mới, và cũng là thứ khiến bảng này không bao giờ bắt buộc phải có dữ liệu.
type RoleLabel struct {
	ID uint `json:"id" gorm:"primaryKey"`
	TenantOwned
	RoleID      uint      `json:"role_id"`
	DisplayName string    `json:"display_name"`
	Description string    `json:"description"`
	CreatedAt   time.Time `json:"created_at"`
	UpdatedAt   time.Time `json:"updated_at"`
}

// ApplyLabel đè nhãn của cửa hàng lên vai trò dùng chung.
//
// Chỉ đụng tới tên hiển thị và mô tả. `Name` (mã vai trò) là thứ middleware và
// gate của trang quản trị so khớp để phân quyền — nó không phải chữ để đọc, và
// không cửa hàng nào được đổi.
func (r *Role) ApplyLabel(label *RoleLabel) {
	if r == nil || label == nil || label.DisplayName == "" {
		return
	}
	r.DisplayName = label.DisplayName
	r.Description = label.Description
}

// Tên vai trò chuẩn (khớp seed.sql).
const (
	RoleSuperAdmin = "super_admin"
	RoleAdmin      = "admin"
	RoleStaff      = "staff"
	RoleCustomer   = "customer"
)

// Id vai trò trong bảng roles (khớp seed.sql).
const (
	SuperAdminRoleID uint = 1
	AdminRoleID      uint = 2
	StaffRoleID      uint = 3
	CustomerRoleID   uint = 4
)

// Cửa vào — khớp SET `users.access_areas`.
const (
	CuaQuanLy  = "quan_ly"
	CuaThuNgan = "thu_ngan"
)

var CuaVaoHopLe = map[string]bool{CuaQuanLy: true, CuaThuNgan: true}

// CuaVao trả những cửa người này mở được.
//
// Cột rỗng = tài khoản có TRƯỚC migration 0015 (hoặc token cũ chưa mang cột
// này): suy từ role_id đúng như hệ thống hành xử trước đó — admin đi cả hai cửa,
// staff chỉ có quầy. Suy chứ không trả rỗng, vì trả rỗng là khoá cứng mọi tài
// khoản cũ ra ngoài ngay lượt triển khai.
func CuaVao(accessAreas string, roleID uint) []string {
	if accessAreas != "" {
		cua := make([]string, 0, 2)
		for _, c := range strings.Split(accessAreas, ",") {
			if c = strings.TrimSpace(c); CuaVaoHopLe[c] {
				cua = append(cua, c)
			}
		}

		return cua
	}

	switch roleID {
	case SuperAdminRoleID, AdminRoleID:
		return []string{CuaQuanLy, CuaThuNgan}
	case StaffRoleID:
		return []string{CuaThuNgan}
	}

	return nil
}

// CoCua cho biết người này mở được cửa đó không.
func CoCua(accessAreas string, roleID uint, cua string) bool {
	for _, c := range CuaVao(accessAreas, roleID) {
		if c == cua {
			return true
		}
	}

	return false
}

// InternalRoleIDs là các vai trò NỘI BỘ — mọi vai trò trừ customer.
//
// Đây là tập tài khoản của trang "Người dùng & vai trò": khách hàng có trang
// riêng và không bao giờ được tạo/sửa qua đường này.
var InternalRoleIDs = []uint{SuperAdminRoleID, AdminRoleID, StaffRoleID}

// Tenant là MỘT khách hàng đã mua phần mềm (xem bảng tenants).
//
// Chỉ khai những cột code thật sự đọc: GORM bỏ qua cột thừa trong SELECT *, nên
// không cần map `note`/`created_at` — hai cột đó NULL được, mà quét NULL vào
// string/time.Time thì lỗi ngay ở tầng driver.
type Tenant struct {
	ID uint `json:"id" gorm:"primaryKey"`
	// Code là thứ người dùng gõ vào ô ĐẦU TIÊN của màn hình đăng nhập 3 ô. Chuỗi
	// do mình cấp, không phải id — số auto-increment cho người ngoài đếm được hệ
	// thống có bao nhiêu khách.
	Code string `json:"code"`
	Name string `json:"name"`
	// Status: active | suspended. suspended = khách hết hạn hoặc ngừng trả tiền —
	// chặn đăng nhập nhưng KHÔNG xoá dữ liệu, đóng tiền lại là mở.
	Status string `json:"status"`
}

func (Tenant) TableName() string { return "tenants" }

// TenantActive là trạng thái duy nhất cho phép đăng nhập vào cửa hàng.
const TenantActive = "active"

// TenantSuspended là cửa hàng bị khoá: hết hạn hợp đồng hoặc ngừng trả tiền.
//
// KHÔNG xoá dữ liệu gì cả — đóng tiền lại là mở. Đây là thứ mà lượt quét hợp
// đồng quá hạn ghi xuống (xem service.QuetHanService), và cũng là thứ
// authService.LoginShop cùng middleware.JWTAuth đọc để đá người dùng ra.
const TenantSuspended = "suspended"

// ChiNhanh là MỘT ĐIỂM BÁN của một khách hàng — bảng `shops`.
//
// ĐỌC KỸ HAI CHỮ NÀY TRƯỚC KHI VIẾT TIẾP, chúng là cái bẫy lớn nhất của lược đồ
// này: "cửa hàng" trong cả dự án nghĩa là TENANT (một khách đã mua phần mềm,
// bảng `tenants`), còn `shops` là chi nhánh NẰM TRONG một tenant. Vì vậy thực
// thể này mang tên tiếng Việt: một struct tên `Shop` đứng cạnh `Tenant` sẽ được
// người đọc sau hiểu ngược, và cái hiểu ngược đó ghi thẳng xuống database.
//
// Mỗi tenant có ÍT NHẤT một chi nhánh — dòng 'mac-dinh' dựng cùng lúc mở tài
// khoản (xem CuaHangMoiRepository.Tao). Chi nhánh thứ hai trở đi là quyền lợi
// của gói Chuỗi, và số lượng bị hạn mức `max_shops` của hợp đồng chặn (xem
// LoaiHanMuc).
//
// Code chỉ cần duy nhất TRONG MỘT TENANT (uq_shops_tenant_code), khác `Tenant.Code`
// vốn duy nhất toàn hệ thống — hai tiệm khác nhau cùng có chi nhánh "kho-1" là
// chuyện bình thường.
type ChiNhanh struct {
	ID uint `json:"id" gorm:"primaryKey"`
	TenantOwned
	Code string `json:"code"`
	Name string `json:"name"`
	// Phone/Address NULL được dưới database. Dùng StringOrNull để đọc NULL không
	// vỡ ở tầng driver, và để ô bỏ trống ghi xuống NULL chứ không phải chuỗi rỗng.
	Phone   StringOrNull `json:"phone"`
	Address StringOrNull `json:"address"`
	// IsActive = false: chi nhánh ngừng hoạt động nhưng dữ liệu cũ vẫn tra được.
	// VẪN TÍNH vào hạn mức — nó còn giữ mã và còn đứng trong danh sách, y như tài
	// khoản bị khoá vẫn chiếm một chỗ.
	IsActive  bool           `json:"is_active"`
	CreatedAt time.Time      `json:"created_at"`
	UpdatedAt time.Time      `json:"updated_at"`
	DeletedAt gorm.DeletedAt `json:"-" gorm:"index"`
}

func (ChiNhanh) TableName() string { return "shops" }

// TonKhoChiNhanh là số hàng của MỘT biến thể ĐANG NẰM TẠI một chi nhánh — bảng
// `variant_stocks`, và từ migration 0005 là NGUỒN SỰ THẬT của tồn kho.
//
// ĐỌC KỸ QUAN HỆ VỚI ProductVariant.StockQuantity: cột kia không còn là nguồn
// sự thật nữa mà là BẢN CỘNG SẴN của mọi chi nhánh, do repository ghi lại ngay
// sau mỗi lần đụng vào bảng này (xem ghiTonChiNhanh). Nó vẫn còn vì hàng chục
// đường đọc dựa vào — trang bán hàng cho khách vãng lai, danh sách sản phẩm,
// báo cáo giá trị kho — và tất cả đều hỏi đúng một câu: "cả cửa hàng còn bao
// nhiêu".
//
// Vì vậy có đúng MỘT luật cho mọi người viết code sau: muốn ĐỔI tồn kho thì ghi
// vào đây và để repository dựng lại bản cộng; tuyệt đối không ghi thẳng
// `stock_quantity` nữa. Ghi thẳng thì hai bảng nói hai con số khác nhau, và cái
// sai đó không nổi lên ở đâu cho tới lúc có người đếm hàng thật trong kho.
//
// Không có dòng = chi nhánh đó chưa từng có hàng của biến thể này, KHÁC "có
// dòng, quantity = 0" (đã từng có, giờ hết). Nơi ghi phải upsert; nơi đọc phải
// coi thiếu dòng là 0.
type TonKhoChiNhanh struct {
	ID uint `json:"id" gorm:"primaryKey"`
	TenantOwned
	// ShopID là chi nhánh giữ số hàng này. Cặp (ShopID, ProductVariantID) là khoá
	// duy nhất uq_variant_stocks_shop_variant.
	ShopID           uint      `json:"shop_id"`
	ProductVariantID uint      `json:"product_variant_id"`
	Quantity         int       `json:"quantity"`
	CreatedAt        time.Time `json:"created_at"`
	UpdatedAt        time.Time `json:"updated_at"`
}

func (TonKhoChiNhanh) TableName() string { return "variant_stocks" }

type User struct {
	ID uint `json:"id" gorm:"primaryKey"`
	TenantOwned
	// Username là ô THỨ HAI của màn hình đăng nhập 3 ô, chỉ duy nhất trong một
	// tenant. NULL = tài khoản khách hàng (khách mua sắm đăng nhập bằng email):
	// UNIQUE chỉ cho lọt đúng một dòng chuỗi rỗng nên bắt buộc phải là NULL.
	Username StringOrNull `json:"username" gorm:"column:username"`
	RoleID   uint         `json:"role_id"`
	// AccessAreas là cột SET ghi ĐÚNG những cửa đã giao cho người này:
	// "quan_ly", "thu_ngan", hoặc cả hai. Tích gì vào được nấy.
	//
	// KHÁC role_id: role_id trả lời "anh là LOẠI người nào" (chủ tiệm / người của
	// tiệm / khách), còn đây trả lời "người của tiệm thì mở được CỬA nào". Một
	// con số không nói được câu "vừa quản lý vừa đứng quầy" — xem migration 0015.
	//
	// Rỗng = tài khoản có trước 0015 hoặc là khách hàng; CuaVao() suy từ role_id.
	AccessAreas  StringOrNull `json:"access_areas" gorm:"column:access_areas"`
	Role         *Role        `json:"role,omitempty" gorm:"foreignKey:RoleID"`
	FullName     string       `json:"full_name"`
	Email        string       `json:"email"`
	Phone        string       `json:"phone"`
	PasswordHash string       `json:"-"`
	// FacebookID là id người dùng do Facebook cấp (chỉ duy nhất trong phạm vi một
	// app). Rỗng = tài khoản chưa liên kết Facebook. Không trả ra JSON: giao diện
	// không dùng tới, mà lộ ra thì thành một mảnh dữ liệu định danh khách hàng.
	FacebookID StringOrNull `json:"-" gorm:"column:facebook_id"`
	// GoogleID là `sub` trong id_token của Google — định danh ổn định, không đổi kể
	// cả khi khách đổi email. Rỗng = chưa liên kết. Cũng không trả ra JSON.
	GoogleID        StringOrNull   `json:"-" gorm:"column:google_id"`
	Avatar          string         `json:"avatar"`
	Gender          EnumOrNull     `json:"gender"`
	DateOfBirth     *time.Time     `json:"date_of_birth"`
	Status          string         `json:"status"`
	EmailVerifiedAt *time.Time     `json:"email_verified_at"`
	PhoneVerifiedAt *time.Time     `json:"phone_verified_at"`
	LastLoginAt     *time.Time     `json:"last_login_at"`
	CreatedAt       time.Time      `json:"created_at"`
	UpdatedAt       time.Time      `json:"updated_at"`
	DeletedAt       gorm.DeletedAt `json:"-" gorm:"index"`
}

// EmailVerification lưu mã OTP gửi qua email khi khách đăng ký.
// Chỉ lưu bcrypt của mã, không lưu mã thô.
type EmailVerification struct {
	ID uint `json:"id" gorm:"primaryKey"`
	TenantOwned
	UserID     uint       `json:"user_id"`
	Email      string     `json:"email"`
	CodeHash   string     `json:"-"`
	Purpose    string     `json:"purpose"`
	Attempts   uint8      `json:"attempts"`
	ExpiresAt  time.Time  `json:"expires_at"`
	VerifiedAt *time.Time `json:"verified_at"`
	CreatedAt  time.Time  `json:"created_at"`
	UpdatedAt  time.Time  `json:"updated_at"`
}

func (EmailVerification) TableName() string { return "email_verifications" }

type UserAddress struct {
	ID uint `json:"id" gorm:"primaryKey"`
	TenantOwned
	UserID        uint           `json:"user_id"`
	RecipientName string         `json:"recipient_name"`
	Phone         string         `json:"phone"`
	Province      string         `json:"province"`
	District      string         `json:"district"`
	Ward          string         `json:"ward"`
	AddressLine   string         `json:"address_line"`
	Type          string         `json:"type"`
	IsDefault     bool           `json:"is_default"`
	CreatedAt     time.Time      `json:"created_at"`
	UpdatedAt     time.Time      `json:"updated_at"`
	DeletedAt     gorm.DeletedAt `json:"-" gorm:"index"`
}

// ---------- 2. Danh mục sản phẩm ----------

type Category struct {
	ID uint `json:"id" gorm:"primaryKey"`
	TenantOwned
	ParentID    *uint          `json:"parent_id"`
	Name        string         `json:"name"`
	Slug        string         `json:"slug"`
	Description string         `json:"description"`
	Image       string         `json:"image"`
	SortOrder   int            `json:"sort_order"`
	IsActive    bool           `json:"is_active"`
	Children    []Category     `json:"children,omitempty" gorm:"foreignKey:ParentID"`
	CreatedAt   time.Time      `json:"created_at"`
	UpdatedAt   time.Time      `json:"updated_at"`
	DeletedAt   gorm.DeletedAt `json:"-" gorm:"index"`
}

type Brand struct {
	ID uint `json:"id" gorm:"primaryKey"`
	TenantOwned
	Name        string         `json:"name"`
	Slug        string         `json:"slug"`
	Logo        string         `json:"logo"`
	Description string         `json:"description"`
	IsActive    bool           `json:"is_active"`
	CreatedAt   time.Time      `json:"created_at"`
	UpdatedAt   time.Time      `json:"updated_at"`
	DeletedAt   gorm.DeletedAt `json:"-" gorm:"index"`

	// ProductCount là số sản phẩm (chưa xoá) đang gắn thương hiệu này. Không có
	// cột trong DB (gorm:"-"), repository tự đếm và gán khi trả danh sách/chi tiết
	// — trang quản trị dùng để hiện mức sử dụng và cảnh báo trước khi xoá.
	ProductCount int64 `json:"product_count" gorm:"-"`
}

// Trạng thái kinh doanh của một sản phẩm.
//
// Đây là danh sách ĐÓNG — thêm giá trị mới phải sửa cả ENUM trong DB, nhãn bên
// trang quản trị và IsValidProductStatus ở dưới.
const (
	// ProductStatusActive — đang bán, hiện ngoài cửa hàng.
	ProductStatusActive = "active"
	// ProductStatusHidden — tạm ẩn: không hiện ngoài cửa hàng nhưng vẫn nhập
	// hàng, vẫn tính vào kho. Dùng khi chờ ảnh, chờ đủ size, chờ đợt bán.
	ProductStatusHidden = "hidden"
	// ProductStatusDiscontinued — ngừng kinh doanh: không hiện, không nhập thêm.
	// Cố ý KHÔNG xoá: đơn cũ, phiếu nhập cũ và báo cáo vẫn phải tra ra được.
	ProductStatusDiscontinued = "discontinued"
)

// ProductStatuses liệt kê các trạng thái hợp lệ, theo thứ tự vòng đời.
var ProductStatuses = []string{ProductStatusActive, ProductStatusHidden, ProductStatusDiscontinued}

// IsValidProductStatus cho biết mã trạng thái có nằm trong danh sách hỗ trợ không.
func IsValidProductStatus(s string) bool {
	for _, v := range ProductStatuses {
		if v == s {
			return true
		}
	}
	return false
}

type Product struct {
	ID uint `json:"id" gorm:"primaryKey"`
	TenantOwned
	CategoryID       uint       `json:"category_id"`
	Category         *Category  `json:"category,omitempty" gorm:"foreignKey:CategoryID"`
	BrandID          *uint      `json:"brand_id"`
	Brand            *Brand     `json:"brand,omitempty" gorm:"foreignKey:BrandID"`
	Name             string     `json:"name"`
	Slug             string     `json:"slug"`
	SKU              string     `json:"sku" gorm:"column:sku"`
	ShortDescription string     `json:"short_description"`
	Description      string     `json:"description"`
	Team             string     `json:"team"`
	Season           string     `json:"season"`
	KitType          EnumOrNull `json:"kit_type"`
	BasePrice        float64    `json:"base_price"`
	SalePrice        *float64   `json:"sale_price"`
	// FinalPrice / PromotionName KHÔNG nằm trong bảng products (`gorm:"-"`) — chúng
	// được tính lúc đọc từ các chương trình khuyến mãi đang chạy.
	//
	// Cố ý không ghi đè SalePrice: trang quản trị nạp sản phẩm từ chính API này rồi
	// lưu lại y nguyên, ghi đè vào đó là giá khuyến mãi tạm thời bị đóng đinh thành
	// giá cố định của sản phẩm ngay lần bấm Lưu đầu tiên.
	//
	// nil = không có chương trình nào áp cho sản phẩm này.
	FinalPrice    *float64 `json:"final_price,omitempty" gorm:"-"`
	PromotionName string   `json:"promotion_name,omitempty" gorm:"-"`
	// CostPrice là giá VỐN, dùng để tính giá trị tồn kho. nil = chưa khai, khác hẳn
	// với giá vốn bằng 0.
	//
	// Trường này CHỈ dành cho khu quản trị. Handler công khai xoá nó trước khi trả
	// lời (xem stripCost trong product_handler.go) — biên lợi nhuận của cửa hàng
	// không được phép lọt ra storefront.
	CostPrice *float64 `json:"cost_price"`
	Thumbnail string   `json:"thumbnail"`
	// IsActive = có hiện ngoài cửa hàng không. Cột này KHÔNG được đặt tay: nó
	// suy ra từ Status (xem SyncActive) và mọi truy vấn bán hàng/kho/báo cáo đều
	// lọc theo nó.
	IsActive bool `json:"is_active"`
	// Status tách "tạm ẩn ít hôm" khỏi "ngừng kinh doanh hẳn" — hai việc trước
	// đây trông giống hệt nhau trong danh sách vì cùng là is_active = 0.
	Status          string           `json:"status"`
	IsFeatured      bool             `json:"is_featured"`
	ViewCount       uint             `json:"view_count"`
	SoldCount       uint             `json:"sold_count"`
	RatingAvg       float64          `json:"rating_avg"`
	RatingCount     uint             `json:"rating_count"`
	MetaTitle       string           `json:"meta_title"`
	MetaDescription string           `json:"meta_description"`
	Variants        []ProductVariant `json:"variants,omitempty" gorm:"foreignKey:ProductID"`
	Images          []ProductImage   `json:"images,omitempty" gorm:"foreignKey:ProductID"`
	CreatedAt       time.Time        `json:"created_at"`
	UpdatedAt       time.Time        `json:"updated_at"`
	DeletedAt       gorm.DeletedAt   `json:"-" gorm:"index"`
}

type ProductVariant struct {
	ID uint `json:"id" gorm:"primaryKey"`
	TenantOwned
	ProductID uint   `json:"product_id"`
	SKU       string `json:"sku" gorm:"column:sku"`
	// Barcode là mã vạch in trên chính món hàng (EAN/UPC do nhà sản xuất đặt),
	// khác SKU — mã nội bộ do cửa hàng tự đặt cho dễ đọc. nil = chưa dán mã.
	//
	// Con trỏ chứ không phải string: cột UNIQUE, mà MySQL chỉ coi các NULL là
	// khác nhau. Dùng "" thì biến thể thứ hai chưa dán mã sẽ đụng ràng buộc với
	// biến thể thứ nhất, và người dùng nhận một lỗi trùng mã vạch cho hai món
	// đều không có mã vạch nào.
	Barcode *string  `json:"barcode"`
	Size    string   `json:"size"`
	Color   string   `json:"color"`
	Price   *float64 `json:"price"`
	// CostPrice ghi đè giá vốn của sản phẩm cha khi biến thể có giá vốn riêng.
	// nil = theo sản phẩm cha.
	// Cũng bị xoá khỏi phản hồi công khai như Product.CostPrice.
	CostPrice *float64 `json:"cost_price"`
	// FinalPrice là giá bán THẬT của riêng biến thể này sau khuyến mãi, tính lúc
	// đọc (`gorm:"-"`) theo đúng công thức tầng thanh toán dùng để thu tiền:
	// giá riêng của biến thể (nếu có) đè giá sản phẩm, rồi chương trình khuyến
	// mãi trừ tiếp lên trên.
	//
	// Có trường này thì trang chi tiết mới in được con số đúng khi khách đổi
	// size — trước đây trang chỉ có một giá ở cấp sản phẩm, biến thể khai giá
	// riêng là khách nhìn một đằng trả một nẻo.
	//
	// nil = biến thể không khai giá riêng và cũng không có chương trình nào áp.
	FinalPrice *float64 `json:"final_price,omitempty" gorm:"-"`
	// StockQuantity là cache tồn kho, chỉ nghiệp vụ kho được ghi (nhập hàng,
	// điều chỉnh, đơn hàng, trả hàng). Luồng tạo/sửa sản phẩm không đụng tới
	// cột này — xem productRepository.ReplaceVariants.
	StockQuantity int            `json:"stock_quantity"`
	WeightGram    int            `json:"weight_gram"`
	Image         string         `json:"image"`
	IsActive      bool           `json:"is_active"`
	CreatedAt     time.Time      `json:"created_at"`
	UpdatedAt     time.Time      `json:"updated_at"`
	DeletedAt     gorm.DeletedAt `json:"-" gorm:"index"`
}

type ProductImage struct {
	ID uint `json:"id" gorm:"primaryKey"`
	TenantOwned
	ProductID uint      `json:"product_id"`
	URL       string    `json:"url" gorm:"column:url"`
	Alt       string    `json:"alt"`
	SortOrder int       `json:"sort_order"`
	IsPrimary bool      `json:"is_primary"`
	CreatedAt time.Time `json:"created_at"`
	UpdatedAt time.Time `json:"updated_at"`
}

// ---------- 3. Giỏ hàng ----------

type Cart struct {
	ID uint `json:"id" gorm:"primaryKey"`
	TenantOwned
	UserID    *uint      `json:"user_id"`
	SessionID string     `json:"session_id"`
	Items     []CartItem `json:"items,omitempty" gorm:"foreignKey:CartID"`
	CreatedAt time.Time  `json:"created_at"`
	UpdatedAt time.Time  `json:"updated_at"`
}

type CartItem struct {
	ID uint `json:"id" gorm:"primaryKey"`
	TenantOwned
	CartID             uint            `json:"cart_id"`
	ProductVariantID   uint            `json:"product_variant_id"`
	Variant            *ProductVariant `json:"variant,omitempty" gorm:"foreignKey:ProductVariantID"`
	Quantity           int             `json:"quantity"`
	CustomPlayerName   string          `json:"custom_player_name"`
	CustomPlayerNumber string          `json:"custom_player_number"`
	CreatedAt          time.Time       `json:"created_at"`
	UpdatedAt          time.Time       `json:"updated_at"`
}

// ---------- 4. Voucher ----------

// Voucher là mã giảm giá khách TỰ NHẬP lúc thanh toán, giảm trên TỔNG ĐƠN.
//
// Khác Promotion ở ba điểm: khách phải gõ mã (không tự động), mức giảm tính trên
// cả đơn chứ không trên từng sản phẩm, và số lượt phát ra bị đếm — hết lượt là
// mã ngừng dùng được dù vẫn còn hạn.
//
// StartAt/EndAt để trống nghĩa là không giới hạn phía đó: mã dùng được ngay, hoặc
// dùng mãi tới khi bị tắt tay.
type Voucher struct {
	ID uint `json:"id" gorm:"primaryKey"`
	TenantOwned
	Code              string     `json:"code"`
	Description       string     `json:"description"`
	DiscountType      string     `json:"discount_type"`
	DiscountValue     float64    `json:"discount_value"`
	MaxDiscountAmount *float64   `json:"max_discount_amount"`
	MinOrderAmount    float64    `json:"min_order_amount"`
	UsageLimit        *uint      `json:"usage_limit"`
	UsageLimitPerUser *uint      `json:"usage_limit_per_user"`
	UsedCount         uint       `json:"used_count"`
	StartAt           *time.Time `json:"start_at"`
	EndAt             *time.Time `json:"end_at"`
	IsActive          bool       `json:"is_active"`
	// IsPublic quyết định mã có được KHOE RA ở ô nhập mã lúc thanh toán không.
	// Mặc định false: mã gửi tay cho một người (đền bù đơn lỗi, quà khách quen) mà
	// bị liệt kê công khai là ai cũng dùng được.
	IsPublic  bool           `json:"is_public"`
	CreatedAt time.Time      `json:"created_at"`
	UpdatedAt time.Time      `json:"updated_at"`
	DeletedAt gorm.DeletedAt `json:"-" gorm:"index"`
}

// Discount tính số tiền giảm cho một đơn có tiền hàng là subtotal.
//
// Luôn kẹp lại trong khoảng [0, subtotal]: giảm nhiều hơn tiền hàng thì đơn thành
// số âm. Cố ý tính trên TIỀN HÀNG chứ không gồm phí ship — mã giảm giá hàng, còn
// phí vận chuyển thì vẫn phải trả.
func (v Voucher) Discount(subtotal float64) float64 {
	if subtotal <= 0 {
		return 0
	}
	var d float64
	if v.DiscountType == DiscountPercentage {
		d = subtotal * v.DiscountValue / 100
		if v.MaxDiscountAmount != nil && *v.MaxDiscountAmount > 0 && d > *v.MaxDiscountAmount {
			d = *v.MaxDiscountAmount
		}
	} else {
		d = v.DiscountValue
	}
	if d < 0 {
		d = 0
	}
	if d > subtotal {
		d = subtotal
	}
	return d
}

// VoucherUsage là một lượt đã tiêu của mã, gắn với đúng một đơn.
//
// Dòng này KHÔNG bị xoá khi khách huỷ đơn: lượt đã dùng là mất luôn với khách đó.
// Nhờ vậy nó vừa là lịch sử, vừa là thứ chặn trò đặt rồi huỷ để xài mã mãi.
type VoucherUsage struct {
	ID uint `json:"id" gorm:"primaryKey"`
	TenantOwned
	VoucherID uint  `json:"voucher_id"`
	UserID    *uint `json:"user_id"`
	// RecipientPhone là danh tính của khách VÃNG LAI (chỉ chữ số). Không có nó thì
	// hạn mức "mỗi khách N lượt" chỉ chặn được người đã đăng nhập, còn ai không
	// đăng nhập là dùng lại vô hạn.
	RecipientPhone string     `json:"recipient_phone"`
	OrderID        uint       `json:"order_id"`
	DiscountAmount float64    `json:"discount_amount"`
	UsedAt         *time.Time `json:"used_at"`
}

// VoucherClaim là yêu cầu tiêu MỘT lượt voucher, chốt bên trong giao dịch đặt hàng.
//
// Tầng service đã kiểm hết điều kiện trước đó, nhưng vẫn mang theo hai hạn mức để
// repository kiểm LẦN CUỐI dưới khoá dòng: giữa lúc kiểm và lúc ghi, một khách
// khác có thể vừa tiêu mất lượt cuối cùng.
type VoucherClaim struct {
	VoucherID uint
	UserID    *uint
	// Phone là số điện thoại người nhận (chỉ chữ số) — danh tính dùng để chặn hạn
	// mức theo khách khi người đặt không đăng nhập.
	Phone    string
	Discount float64

	UsageLimit        *uint
	UsageLimitPerUser *uint
}

// ---------- 4b. Chương trình khuyến mãi ----------

// Kiểu giảm giá, dùng chung cho Promotion và Voucher.
const (
	DiscountPercentage = "percentage"
	DiscountFixed      = "fixed"
)

// Loại đích của một dòng phạm vi khuyến mãi.
const (
	PromotionTargetProduct  = "product"
	PromotionTargetCategory = "category"
	PromotionTargetBrand    = "brand"
)

// Promotion là một đợt giảm giá có thời hạn, áp thẳng lên giá từng sản phẩm.
//
// Khác Voucher ở hai điểm: khách KHÔNG phải nhập mã, và mức giảm tính trên từng
// sản phẩm chứ không phải trên tổng đơn. Giá gốc của sản phẩm không bị ghi đè —
// mức giảm được tính lúc đọc nên hết đợt là giá tự về như cũ.
type Promotion struct {
	ID uint `json:"id" gorm:"primaryKey"`
	TenantOwned
	Name         string `json:"name"`
	Description  string `json:"description"`
	DiscountType string `json:"discount_type"`
	// DiscountValue là % (khi type=percentage) hoặc số tiền giảm trên MỖI sản phẩm.
	DiscountValue float64 `json:"discount_value"`
	// MaxDiscountAmount chỉ có nghĩa khi giảm theo %: "giảm 30% nhưng tối đa 200k".
	MaxDiscountAmount *float64          `json:"max_discount_amount"`
	StartAt           time.Time         `json:"start_at"`
	EndAt             time.Time         `json:"end_at"`
	IsActive          bool              `json:"is_active"`
	Targets           []PromotionTarget `json:"targets,omitempty" gorm:"foreignKey:PromotionID"`
	CreatedAt         time.Time         `json:"created_at"`
	UpdatedAt         time.Time         `json:"updated_at"`
	DeletedAt         gorm.DeletedAt    `json:"-" gorm:"index"`
}

// Running cho biết chương trình có đang chạy tại thời điểm at hay không.
func (p Promotion) Running(at time.Time) bool {
	return p.IsActive && !at.Before(p.StartAt) && !at.After(p.EndAt)
}

// Discount tính số tiền giảm cho một sản phẩm đang bán ở giá price.
//
// Luôn kẹp lại trong khoảng [0, price]: giảm nhiều hơn giá bán thì thành giá âm,
// cửa hàng trả tiền cho khách để lấy hàng đi.
func (p Promotion) Discount(price float64) float64 {
	if price <= 0 {
		return 0
	}
	var d float64
	if p.DiscountType == DiscountPercentage {
		d = price * p.DiscountValue / 100
		if p.MaxDiscountAmount != nil && *p.MaxDiscountAmount > 0 && d > *p.MaxDiscountAmount {
			d = *p.MaxDiscountAmount
		}
	} else {
		d = p.DiscountValue
	}
	if d < 0 {
		d = 0
	}
	if d > price {
		d = price
	}
	return d
}

// PromotionTarget là một đích trong phạm vi áp dụng: một sản phẩm, một danh mục
// hoặc một thương hiệu. Một chương trình có thể trộn cả ba loại.
type PromotionTarget struct {
	ID uint `json:"id" gorm:"primaryKey"`
	TenantOwned
	PromotionID uint   `json:"promotion_id"`
	TargetType  string `json:"target_type"`
	TargetID    uint   `json:"target_id"`
}

// ---------- 5. Đơn hàng ----------

type Order struct {
	ID uint `json:"id" gorm:"primaryKey"`
	TenantOwned
	// ShopID là CHI NHÁNH phát sinh đơn này: chi nhánh người bán đang làm việc
	// (đơn tại quầy), hoặc chi nhánh bán online (đơn từ storefront — xem
	// ChiNhanhRepository.BanOnline). Đây cũng là kho bị trừ hàng.
	ShopID    uint   `json:"shop_id"`
	OrderCode string `json:"order_code"`
	// Channel là NƠI đơn phát sinh: OrderChannelWeb (đơn có giao hàng — khách tự
	// đặt hoặc nhân viên đặt hộ) hoặc OrderChannelPOS (bán tại quầy). Đơn quầy
	// không có địa chỉ giao, sinh ra đã hoàn tất và đã thu tiền.
	//
	// default:web không thừa dù mọi đường tạo đơn đều tự khai: cột là ENUM NOT
	// NULL, nên đường nào quên khai sẽ ghi chuỗi rỗng và MySQL từ chối cả lượt
	// insert. Có tag này thì GORM bỏ hẳn cột khỏi câu lệnh và database điền 'web'
	// — đơn vào sổ với kênh đúng của nó thay vì cả thao tác gãy.
	Channel string `json:"channel" gorm:"default:web"`
	// UserID nil = khách lẻ: người mua tại quầy không có tài khoản, và ép họ tạo
	// một cái chỉ để bán được một lần là thứ không ai làm ở quầy thật.
	UserID           *uint   `json:"user_id"`
	VoucherID        *uint   `json:"voucher_id"`
	RecipientName    string  `json:"recipient_name"`
	RecipientPhone   string  `json:"recipient_phone"`
	RecipientEmail   string  `json:"recipient_email"`
	ShippingProvince string  `json:"shipping_province"`
	ShippingDistrict string  `json:"shipping_district"`
	ShippingWard     string  `json:"shipping_ward"`
	ShippingAddress  string  `json:"shipping_address"`
	SubtotalAmount   float64 `json:"subtotal_amount"`
	DiscountAmount   float64 `json:"discount_amount"`
	ShippingFee      float64 `json:"shipping_fee"`
	TotalAmount      float64 `json:"total_amount"`
	// AmountTendered / ChangeAmount là tiền khách đưa và tiền thối lại tại quầy.
	// nil = không thu bằng tiền mặt; 0 = có thu và khách đưa vừa đủ. Hai chuyện
	// khác nhau, nên không dùng float64 với giá trị 0 cho cả hai.
	AmountTendered *float64       `json:"amount_tendered"`
	ChangeAmount   *float64       `json:"change_amount"`
	VoucherCode    string         `json:"voucher_code"`
	PaymentMethod  string         `json:"payment_method"`
	PaymentStatus  string         `json:"payment_status"`
	Status         string         `json:"status"`
	ShippingMethod string         `json:"shipping_method"`
	TrackingNumber string         `json:"tracking_number"`
	Note           string         `json:"note"`
	AdminNote      string         `json:"admin_note"`
	CancelReason   string         `json:"cancel_reason"`
	PlacedAt       *time.Time     `json:"placed_at"`
	ConfirmedAt    *time.Time     `json:"confirmed_at"`
	ShippedAt      *time.Time     `json:"shipped_at"`
	DeliveredAt    *time.Time     `json:"delivered_at"`
	CancelledAt    *time.Time     `json:"cancelled_at"`
	Items          []OrderItem    `json:"items,omitempty" gorm:"foreignKey:OrderID"`
	CreatedAt      time.Time      `json:"created_at"`
	UpdatedAt      time.Time      `json:"updated_at"`
	DeletedAt      gorm.DeletedAt `json:"-" gorm:"index"`
	// StockMoves là những thay đổi tồn kho mà thao tác vừa rồi gây ra, do tầng
	// repository điền vào sau khi transaction chạy xong. Không phải cột của bảng
	// orders và không trả ra API — chỉ để service biết biến thể nào vừa tụt xuống
	// mức cần nhập thêm mà bắn cảnh báo, sau khi đơn đã ghi xong.
	StockMoves []StockMove `json:"-" gorm:"-"`
}

// StockMove là một lần tồn kho của biến thể đổi giá trị: số trước và số sau.
//
// Phải có cả hai số mới biết được biến thể VỪA CHẠM mức cảnh báo hay đã nằm dưới
// mức đó từ trước: chỉ nhìn số tồn hiện tại thì mọi đơn bán tiếp theo đều lại kêu
// một lần nữa về cùng một mặt hàng.
type StockMove struct {
	VariantID uint   `json:"variant_id"`
	SKU       string `json:"sku"`
	Before    int    `json:"before"`
	After     int    `json:"after"`
}

// Trạng thái đơn hàng.
const (
	OrderStatusPending    = "pending"
	OrderStatusConfirmed  = "confirmed"
	OrderStatusProcessing = "processing"
	OrderStatusShipping   = "shipping"
	OrderStatusDelivered  = "delivered"
	OrderStatusCompleted  = "completed"
	OrderStatusCancelled  = "cancelled"
	OrderStatusReturned   = "returned"
)

// Nơi đơn phát sinh (cột orders.channel).
const (
	// OrderChannelWeb — đơn có giao hàng. Gồm cả đơn nhân viên đặt hộ khi khách
	// gọi điện: khác ở người bấm nút, còn đơn thì vẫn có địa chỉ, có phí ship và
	// thu tiền sau.
	OrderChannelWeb = "web"
	// OrderChannelPOS — bán tại quầy: khách đứng trước mặt, trả tiền và cầm hàng
	// đi ngay trong một thao tác.
	OrderChannelPOS = "pos"
)

type OrderItem struct {
	ID uint `json:"id" gorm:"primaryKey"`
	TenantOwned
	OrderID          uint    `json:"order_id"`
	ProductID        *uint   `json:"product_id"`
	ProductVariantID *uint   `json:"product_variant_id"`
	ProductName      string  `json:"product_name"`
	VariantSKU       string  `json:"variant_sku" gorm:"column:variant_sku"`
	Size             string  `json:"size"`
	Color            string  `json:"color"`
	Thumbnail        string  `json:"thumbnail"`
	UnitPrice        float64 `json:"unit_price"`
	// CostPrice là giá vốn một đơn vị CHỤP LẠI tại thời điểm bán.
	//
	// Có nó thì lãi gộp của tháng trước không tự đổi khi nhập lô mới đắt hơn. nil =
	// dòng bán trước khi có cột này, hoặc đi qua đường tạo đơn thủ công (nơi người
	// nhập gõ giá bán chứ không tra giá vốn) — báo cáo lùi về giá vốn hiện tại cho
	// những dòng ấy, đúng như nó vẫn tính từ trước tới nay.
	CostPrice *float64 `json:"cost_price"`
	// DiscountPercent là mức người bán BẤM khi bớt giá dòng này (0 = không bớt),
	// DiscountAmount là số tiền thật đã trừ. Giữ cả hai vì mỗi con số trả lời một
	// câu khác nhau lúc đối soát: "ai được phép duyệt mức này" và "đã bớt bao
	// nhiêu tiền".
	DiscountPercent float64 `json:"discount_percent"`
	DiscountAmount  float64 `json:"discount_amount"`
	Quantity        int     `json:"quantity"`
	// TotalPrice là số tiền dòng này góp vào đơn: đơn giá × số lượng − phần đã bớt.
	TotalPrice         float64   `json:"total_price"`
	CustomPlayerName   string    `json:"custom_player_name"`
	CustomPlayerNumber string    `json:"custom_player_number"`
	CreatedAt          time.Time `json:"created_at"`
	UpdatedAt          time.Time `json:"updated_at"`
}

// TableName: bảng trong schema là số ít (order_status_history), khác quy ước
// số nhiều mặc định của GORM nên phải khai báo tường minh.
func (OrderStatusHistory) TableName() string { return "order_status_history" }

type OrderStatusHistory struct {
	ID uint `json:"id" gorm:"primaryKey"`
	TenantOwned
	OrderID    uint      `json:"order_id"`
	FromStatus string    `json:"from_status"`
	ToStatus   string    `json:"to_status"`
	Note       string    `json:"note"`
	ChangedBy  *uint     `json:"changed_by"`
	CreatedAt  time.Time `json:"created_at"`
}

// ---------- 5b. Trả hàng ----------

// OrderReturn — một phiếu trả hàng của đơn. Một đơn có thể có nhiều phiếu (khách
// trả dần từng món), mỗi phiếu tự đi theo luồng duyệt riêng.
type OrderReturn struct {
	ID uint `json:"id" gorm:"primaryKey"`
	TenantOwned
	// ShopID là chi nhánh nhận hàng trả về — LUÔN lấy theo đơn gốc, không lấy
	// theo chi nhánh người duyệt đang đứng: hàng quay về đúng kho đã xuất nó ra,
	// nếu không thì hai kho cùng lệch, một bên thừa một bên thiếu.
	ShopID     uint   `json:"shop_id"`
	ReturnCode string `json:"return_code"`
	OrderID    uint   `json:"order_id"`
	// ExchangeOrderID là đơn MỚI khách lấy về trong một lượt đổi hàng tại quầy.
	// nil = trả hàng thuần, có hoàn tiền. Thiếu mối nối này thì trong sổ chỉ còn
	// một phiếu trả và một đơn bán tình cờ trùng giờ, và câu hỏi "khách đổi cái
	// áo đó lấy cái gì" không tra được nữa.
	ExchangeOrderID *uint  `json:"exchange_order_id"`
	UserID          *uint  `json:"user_id"`
	Status          string `json:"status"`
	Reason          string `json:"reason"`
	ReasonNote      string `json:"reason_note"`
	RequestedBy     string `json:"requested_by"`

	RefundMethod string `json:"refund_method"`
	BankAccount  string `json:"bank_account"`
	BankHolder   string `json:"bank_holder"`
	BankName     string `json:"bank_name"`

	ItemsAmount  float64 `json:"items_amount"`
	ShippingFee  float64 `json:"shipping_fee"`
	Deduction    float64 `json:"deduction"`
	RefundAmount float64 `json:"refund_amount"`
	// Restock = false khi hàng trả về bị lỗi/hỏng, không nhập lại kho được.
	Restock bool `json:"restock"`

	AdminNote    string `json:"admin_note"`
	RejectReason string `json:"reject_reason"`
	HandledBy    *uint  `json:"handled_by"`

	ApprovedAt *time.Time `json:"approved_at"`
	ReceivedAt *time.Time `json:"received_at"`
	RefundedAt *time.Time `json:"refunded_at"`
	ClosedAt   *time.Time `json:"closed_at"`

	Items []OrderReturnItem `json:"items,omitempty" gorm:"foreignKey:ReturnID"`
	// Order chỉ được nạp ở luồng danh sách/chi tiết để hiển thị mã đơn, người nhận.
	Order *Order `json:"order,omitempty" gorm:"foreignKey:OrderID"`

	CreatedAt time.Time      `json:"created_at"`
	UpdatedAt time.Time      `json:"updated_at"`
	DeletedAt gorm.DeletedAt `json:"-" gorm:"index"`
}

// Trạng thái phiếu trả hàng.
const (
	ReturnStatusPending   = "pending"   // khách vừa gửi yêu cầu, chờ duyệt
	ReturnStatusApproved  = "approved"  // đã duyệt, chờ nhận hàng về
	ReturnStatusReceived  = "received"  // đã nhận hàng, đã nhập lại kho
	ReturnStatusRefunded  = "refunded"  // đã hoàn tiền — điểm cuối
	ReturnStatusRejected  = "rejected"  // cửa hàng từ chối — điểm cuối
	ReturnStatusCancelled = "cancelled" // khách rút yêu cầu — điểm cuối
)

// Lý do trả hàng (mã cố định để thống kê được, kèm reason_note tự do).
const (
	ReturnReasonDefective      = "defective"
	ReturnReasonWrongItem      = "wrong_item"
	ReturnReasonWrongSize      = "wrong_size"
	ReturnReasonNotAsDescribed = "not_as_described"
	ReturnReasonChangedMind    = "changed_mind"
	ReturnReasonOther          = "other"
)

type OrderReturnItem struct {
	ID uint `json:"id" gorm:"primaryKey"`
	TenantOwned
	ReturnID         uint      `json:"return_id"`
	OrderItemID      uint      `json:"order_item_id"`
	ProductID        *uint     `json:"product_id"`
	ProductVariantID *uint     `json:"product_variant_id"`
	ProductName      string    `json:"product_name"`
	VariantSKU       string    `json:"variant_sku" gorm:"column:variant_sku"`
	Size             string    `json:"size"`
	Color            string    `json:"color"`
	Thumbnail        string    `json:"thumbnail"`
	UnitPrice        float64   `json:"unit_price"`
	Quantity         int       `json:"quantity"`
	TotalPrice       float64   `json:"total_price"`
	CreatedAt        time.Time `json:"created_at"`
	UpdatedAt        time.Time `json:"updated_at"`
}

// TableName: bảng trong schema là số ít (order_return_history), giống
// order_status_history — phải khai báo tường minh vì GORM mặc định số nhiều.
func (OrderReturnHistory) TableName() string { return "order_return_history" }

type OrderReturnHistory struct {
	ID uint `json:"id" gorm:"primaryKey"`
	TenantOwned
	ReturnID   uint      `json:"return_id"`
	FromStatus string    `json:"from_status"`
	ToStatus   string    `json:"to_status"`
	Note       string    `json:"note"`
	ChangedBy  *uint     `json:"changed_by"`
	CreatedAt  time.Time `json:"created_at"`
}

// ---------- 5c. Đặt hàng nhập ----------

// Supplier — nhà cung cấp, bên bán hàng cho cửa hàng.
type Supplier struct {
	ID uint `json:"id" gorm:"primaryKey"`
	TenantOwned
	Code        string         `json:"code"`
	Name        string         `json:"name"`
	ContactName string         `json:"contact_name"`
	Phone       string         `json:"phone"`
	Email       string         `json:"email"`
	Address     string         `json:"address"`
	TaxCode     string         `json:"tax_code"`
	Note        string         `json:"note"`
	IsActive    bool           `json:"is_active"`
	CreatedAt   time.Time      `json:"created_at"`
	UpdatedAt   time.Time      `json:"updated_at"`
	DeletedAt   gorm.DeletedAt `json:"-" gorm:"index"`

	// PurchaseCount là số phiếu đặt hàng (chưa xoá) của nhà cung cấp này. Không có
	// cột trong DB — repository tự đếm khi trả danh sách, để trang quản trị cảnh
	// báo được trước khi xoá.
	PurchaseCount int64 `json:"purchase_count" gorm:"-"`

	// Ba số dưới đây tổng hợp từ phiếu đặt hàng để trang "Nhà cung cấp" xếp hạng
	// và đối chiếu công nợ, cũng không có cột trong DB. Phiếu NHÁP và phiếu ĐÃ HUỶ
	// bị loại khỏi cả ba: nháp chưa đặt thật, huỷ thì không còn nợ ai — cùng luật
	// với thống kê ở trang Đặt hàng nhập.
	PurchaseAmount float64    `json:"purchase_amount" gorm:"-"`
	DebtAmount     float64    `json:"debt_amount" gorm:"-"`
	LastOrderAt    *time.Time `json:"last_order_at" gorm:"-"`
}

// PurchaseOrder — một phiếu đặt hàng nhập từ nhà cung cấp.
//
// Phiếu có thể nhận làm NHIỀU đợt (nhà cung cấp giao thiếu, giao dần): số thực
// nhận nằm ở từng dòng hàng, kho chỉ được cộng đúng vào lúc xác nhận nhận hàng.
type PurchaseOrder struct {
	ID uint `json:"id" gorm:"primaryKey"`
	TenantOwned
	// ShopID là chi nhánh ĐẶT hàng, và cũng là kho hàng sẽ về khi nhận. Chốt lúc
	// lập phiếu chứ không lúc nhận: người nhận hàng có thể đang đứng ở chi nhánh
	// khác, mà hàng thì về đúng nơi đã đặt.
	ShopID     uint   `json:"shop_id"`
	POCode     string `json:"po_code" gorm:"column:po_code"`
	SupplierID *uint  `json:"supplier_id"`
	// SupplierName là tên chụp lại lúc đặt — nhà cung cấp đổi tên hoặc bị xoá thì
	// phiếu cũ vẫn đọc được đúng như lúc ký.
	SupplierName string    `json:"supplier_name"`
	Supplier     *Supplier `json:"supplier,omitempty" gorm:"foreignKey:SupplierID"`
	Status       string    `json:"status"`

	ExpectedDate *time.Time `json:"expected_date" gorm:"type:date"`

	ItemsAmount    float64 `json:"items_amount"`
	DiscountAmount float64 `json:"discount_amount"`
	ShippingFee    float64 `json:"shipping_fee"`
	TotalAmount    float64 `json:"total_amount"`
	PaidAmount     float64 `json:"paid_amount"`
	PaymentStatus  string  `json:"payment_status"`

	Note         string `json:"note"`
	CancelReason string `json:"cancel_reason"`

	CreatedBy *uint `json:"created_by"`
	HandledBy *uint `json:"handled_by"`

	OrderedAt   *time.Time `json:"ordered_at"`
	ReceivedAt  *time.Time `json:"received_at"`
	CancelledAt *time.Time `json:"cancelled_at"`

	Items []PurchaseOrderItem `json:"items,omitempty" gorm:"foreignKey:PurchaseOrderID"`

	CreatedAt time.Time      `json:"created_at"`
	UpdatedAt time.Time      `json:"updated_at"`
	DeletedAt gorm.DeletedAt `json:"-" gorm:"index"`
}

// Trạng thái phiếu đặt hàng nhập.
const (
	PurchaseStatusDraft     = "draft"     // nháp — còn sửa thoải mái, chưa gửi NCC
	PurchaseStatusOrdered   = "ordered"   // đã đặt — đang chờ hàng về
	PurchaseStatusPartial   = "partial"   // đã nhận một phần
	PurchaseStatusReceived  = "received"  // đã nhận đủ — điểm cuối
	PurchaseStatusCancelled = "cancelled" // đã huỷ — điểm cuối
)

// Tình trạng thanh toán cho nhà cung cấp.
const (
	PurchasePaymentUnpaid  = "unpaid"
	PurchasePaymentPartial = "partial"
	PurchasePaymentPaid    = "paid"
)

type PurchaseOrderItem struct {
	ID uint `json:"id" gorm:"primaryKey"`
	TenantOwned
	PurchaseOrderID  uint    `json:"purchase_order_id"`
	ProductID        *uint   `json:"product_id"`
	ProductVariantID *uint   `json:"product_variant_id"`
	ProductName      string  `json:"product_name"`
	VariantSKU       string  `json:"variant_sku" gorm:"column:variant_sku"`
	Size             string  `json:"size"`
	Color            string  `json:"color"`
	Thumbnail        string  `json:"thumbnail"`
	UnitCost         float64 `json:"unit_cost"`
	Quantity         int     `json:"quantity"`
	// ReceivedQuantity cộng dồn qua các đợt nhận; bằng Quantity là dòng đã đủ hàng.
	ReceivedQuantity int       `json:"received_quantity"`
	TotalCost        float64   `json:"total_cost"`
	CreatedAt        time.Time `json:"created_at"`
	UpdatedAt        time.Time `json:"updated_at"`
}

// TableName: bảng trong schema là số ít (purchase_order_history), cùng quy ước
// với order_status_history — phải khai báo tường minh vì GORM mặc định số nhiều.
func (PurchaseOrderHistory) TableName() string { return "purchase_order_history" }

type PurchaseOrderHistory struct {
	ID uint `json:"id" gorm:"primaryKey"`
	TenantOwned
	PurchaseOrderID uint      `json:"purchase_order_id"`
	FromStatus      string    `json:"from_status"`
	ToStatus        string    `json:"to_status"`
	Note            string    `json:"note"`
	ChangedBy       *uint     `json:"changed_by"`
	CreatedAt       time.Time `json:"created_at"`
}

// ---------- 6. Thanh toán ----------

// Payment là MỘT LẦN thử thanh toán qua cổng, không phải một đơn hàng.
//
// Link của cổng có hạn và huỷ được, nên một đơn có thể có nhiều lần thử: khách để
// quá giờ rồi quay lại trả tiếp thì phải tạo link mới. Giữ đủ các lần thử mới đối
// soát lại được về sau.
type Payment struct {
	ID uint `json:"id" gorm:"primaryKey"`
	TenantOwned
	OrderID uint `json:"order_id"`
	// TransactionCode là mã giao dịch phía cổng. Với PayOS đây là `orderCode` do
	// mình sinh ra và gửi sang — thứ duy nhất webhook mang về để tìm lại đơn.
	TransactionCode string  `json:"transaction_code"`
	PaymentLinkID   string  `json:"payment_link_id"`
	CheckoutURL     string  `json:"checkout_url"`
	QRCode          string  `json:"qr_code"`
	Provider        string  `json:"provider"`
	Amount          float64 `json:"amount"`
	Currency        string  `json:"currency"`
	Status          string  `json:"status"`
	// GatewayResponse là con trỏ chứ không phải string vì cột là kiểu JSON: MySQL/
	// MariaDB từ chối cả dòng khi ghi chuỗi rỗng vào đó ("The document is empty" /
	// vi phạm ràng buộc json_valid). nil = chưa có phản hồi nào từ cổng, ghi xuống
	// thành NULL — đúng nghĩa hơn, và không ai phải nhớ Omit cột này khi tạo dòng.
	GatewayResponse *string    `json:"gateway_response" gorm:"type:json"`
	PaidAt          *time.Time `json:"paid_at"`
	ExpiredAt       *time.Time `json:"expired_at"`
	CreatedAt       time.Time  `json:"created_at"`
	UpdatedAt       time.Time  `json:"updated_at"`
}

// Hình thức thanh toán của đơn hàng.
const (
	PaymentMethodCOD  = "cod"
	PaymentMethodBank = "bank_transfer"
	// PaymentMethodCash — tiền mặt trao tay tại quầy. Khác 'cod' ở chỗ tiền đã
	// nằm trong két chứ không phải đang chờ shipper thu hộ.
	PaymentMethodCash  = "cash"
	PaymentMethodPayOS = "payos"
	PaymentMethodSePay = "sepay"
)

// OnlinePaymentMethod cho biết hình thức này có đi qua cổng thanh toán trực tuyến
// hay không — tức là có mã QR để khách quét và có đường xác nhận tự động.
func OnlinePaymentMethod(m string) bool {
	return m == PaymentMethodPayOS || m == PaymentMethodSePay
}

// Tình trạng thanh toán của ĐƠN HÀNG (cột orders.payment_status).
const (
	OrderPaymentPending = "pending"
	OrderPaymentPaid    = "paid"
)

// Trạng thái của MỘT LẦN thử thanh toán (cột payments.status).
const (
	PaymentStatusPending   = "pending"
	PaymentStatusSuccess   = "success"
	PaymentStatusFailed    = "failed"
	PaymentStatusCancelled = "cancelled"
)

// ---------- 7. Kho ----------

type InventoryTransaction struct {
	ID uint `json:"id" gorm:"primaryKey"`
	TenantOwned
	// ShopID là chi nhánh mà bút toán này phát sinh. Bắt buộc điền: một dòng sổ
	// kho không nói được hàng vào/ra ở đâu thì không đối chiếu lại được với kho
	// thật của bất kỳ chi nhánh nào.
	ShopID           uint      `json:"shop_id"`
	ProductVariantID uint      `json:"product_variant_id"`
	Type             string    `json:"type"`
	Quantity         int       `json:"quantity"`
	QuantityBefore   int       `json:"quantity_before"`
	QuantityAfter    int       `json:"quantity_after"`
	ReferenceType    string    `json:"reference_type"`
	ReferenceID      *uint     `json:"reference_id"`
	UnitCost         *float64  `json:"unit_cost"`
	Note             string    `json:"note"`
	CreatedBy        *uint     `json:"created_by"`
	CreatedAt        time.Time `json:"created_at"`
}

// ---------- Trả hàng nhập (trả hàng lại nhà cung cấp) ----------

// PurchaseReturn — phiếu trả hàng lại NHÀ CUNG CẤP, chiều ngược của nhập hàng.
//
// Phiếu nháp chưa đụng tới kho: tồn kho chỉ bị TRỪ đúng một lần, vào lúc chuyển
// sang "đã trả NCC" (hàng thật ra khỏi kho lúc đó). Vì vậy phiếu nháp xoá/huỷ
// được, còn phiếu đã trả thì không — muốn nhận lại hàng phải lập phiếu đặt hàng
// nhập mới để có chứng từ, không sửa lịch sử kho.
type PurchaseReturn struct {
	ID uint `json:"id" gorm:"primaryKey"`
	TenantOwned
	// ShopID là chi nhánh trả hàng lại nhà cung cấp — lấy theo phiếu đặt gốc,
	// cùng lý do với OrderReturn.
	ShopID     uint   `json:"shop_id"`
	ReturnCode string `json:"return_code"`
	// PurchaseOrderID có thể NULL nếu phiếu đặt gốc bị xoá; POCode/SupplierName là
	// bản chụp lúc lập phiếu nên phiếu cũ vẫn đọc được nguyên trạng.
	PurchaseOrderID *uint  `json:"purchase_order_id"`
	POCode          string `json:"po_code" gorm:"column:po_code"`
	SupplierID      *uint  `json:"supplier_id"`
	SupplierName    string `json:"supplier_name"`

	Status string `json:"status"`
	Reason string `json:"reason"`

	ItemsAmount float64 `json:"items_amount"`
	// RefundAmount là số NCC đã hoàn / đã đối trừ, LUỸ KẾ (không phải số vừa nhận).
	RefundAmount float64 `json:"refund_amount"`
	RefundStatus string  `json:"refund_status"`

	Note         string `json:"note"`
	CancelReason string `json:"cancel_reason"`

	CreatedBy *uint `json:"created_by"`
	HandledBy *uint `json:"handled_by"`

	ReturnedAt  *time.Time `json:"returned_at"`
	RefundedAt  *time.Time `json:"refunded_at"`
	CancelledAt *time.Time `json:"cancelled_at"`

	Items []PurchaseReturnItem `json:"items,omitempty" gorm:"foreignKey:PurchaseReturnID"`

	CreatedAt time.Time      `json:"created_at"`
	UpdatedAt time.Time      `json:"updated_at"`
	DeletedAt gorm.DeletedAt `json:"-" gorm:"index"`
}

// Trạng thái phiếu trả hàng nhập.
const (
	PurchaseReturnStatusDraft     = "draft"     // nháp — sửa/xoá được, CHƯA trừ kho
	PurchaseReturnStatusReturned  = "returned"  // đã trả NCC — đã trừ kho
	PurchaseReturnStatusRefunded  = "refunded"  // NCC đã hoàn tiền — điểm cuối
	PurchaseReturnStatusCancelled = "cancelled" // đã huỷ (chỉ từ nháp) — điểm cuối
)

// Lý do trả hàng cho nhà cung cấp.
const (
	PurchaseReturnReasonDefect    = "defect"     // hàng lỗi / hỏng
	PurchaseReturnReasonWrongItem = "wrong_item" // giao sai mẫu / sai size
	PurchaseReturnReasonOverStock = "over_stock" // giao vượt số đặt / nhập quá nhiều
	PurchaseReturnReasonExpired   = "expired"    // hàng cũ, quá mùa
	PurchaseReturnReasonOther     = "other"
)

// Tình trạng hoàn tiền của nhà cung cấp cho phiếu trả.
const (
	PurchaseRefundUnpaid  = "unpaid"
	PurchaseRefundPartial = "partial"
	PurchaseRefundPaid    = "paid"
)

// PurchaseReturnItem — một dòng hàng trả lại nhà cung cấp. Tên/SKU/size/màu và
// giá nhập đều là bản chụp của dòng phiếu đặt gốc.
type PurchaseReturnItem struct {
	ID uint `json:"id" gorm:"primaryKey"`
	TenantOwned
	PurchaseReturnID uint `json:"purchase_return_id"`
	// PurchaseOrderItemID để tính số CÒN TRẢ ĐƯỢC của dòng phiếu đặt gốc.
	PurchaseOrderItemID *uint  `json:"purchase_order_item_id"`
	ProductID           *uint  `json:"product_id"`
	ProductVariantID    *uint  `json:"product_variant_id"`
	ProductName         string `json:"product_name"`
	VariantSKU          string `json:"variant_sku" gorm:"column:variant_sku"`
	Size                string `json:"size"`
	Color               string `json:"color"`
	Thumbnail           string `json:"thumbnail"`

	Quantity  int     `json:"quantity"`
	UnitCost  float64 `json:"unit_cost"`
	TotalCost float64 `json:"total_cost"`

	CreatedAt time.Time `json:"created_at"`
	UpdatedAt time.Time `json:"updated_at"`
}

// TableName: bảng trong schema là số ít, cùng quy ước với purchase_order_history.
func (PurchaseReturnHistory) TableName() string { return "purchase_return_history" }

type PurchaseReturnHistory struct {
	ID uint `json:"id" gorm:"primaryKey"`
	TenantOwned
	PurchaseReturnID uint      `json:"purchase_return_id"`
	FromStatus       string    `json:"from_status"`
	ToStatus         string    `json:"to_status"`
	Note             string    `json:"note"`
	ChangedBy        *uint     `json:"changed_by"`
	CreatedAt        time.Time `json:"created_at"`
}

// ---------- 8. Tương tác ----------

type ProductReview struct {
	ID uint `json:"id" gorm:"primaryKey"`
	TenantOwned
	ProductID   uint           `json:"product_id"`
	UserID      uint           `json:"user_id"`
	OrderItemID *uint          `json:"order_item_id"`
	Rating      uint8          `json:"rating"`
	Title       string         `json:"title"`
	Content     string         `json:"content"`
	Images      string         `json:"images" gorm:"type:json"`
	IsApproved  bool           `json:"is_approved"`
	AdminReply  string         `json:"admin_reply"`
	CreatedAt   time.Time      `json:"created_at"`
	UpdatedAt   time.Time      `json:"updated_at"`
	DeletedAt   gorm.DeletedAt `json:"-" gorm:"index"`
}

// Trạng thái xử lý một yêu cầu khách gửi từ storefront.
const (
	ContactStatusNew     = "moi"
	ContactStatusWorking = "dang-xu-ly"
	ContactStatusDone    = "da-xong"
)

// Loại yêu cầu — quyết định form nào gửi lên, và trang quản trị lọc theo cột này.
const (
	ContactTypeContact = "lien-he"
	ContactTypeTradeIn = "thu-mua"
)

// ContactRequest là một yêu cầu khách để lại ở form Liên hệ hoặc form Thu mua.
//
// Hai form dùng chung một bảng, phân biệt bằng Type — xem chú thích ở
// database/migrations/0001_nen-2026-08-10.sql.
type ContactRequest struct {
	ID uint `json:"id" gorm:"primaryKey"`
	TenantOwned
	Type     string `json:"type"`
	FullName string `json:"full_name"`
	Phone    string `json:"phone"`
	Email    string `json:"email"`
	Address  string `json:"address"`
	Subject  string `json:"subject"`
	Content  string `json:"content"`
	// Images lưu nguyên chuỗi JSON của mảng URL (kiểu cột là JSON). Tầng service
	// tự tách ra mảng khi trả về — xem dto.ContactRequestResponse.
	//
	// PHẢI là con trỏ để "không có ảnh" ghi được thành NULL. Dùng string thường
	// thì giá trị rỗng đi xuống DB là chuỗi rỗng '', mà '' không phải JSON hợp
	// lệ nên MySQL chặn thẳng: "CONSTRAINT `contact_requests.images` failed" —
	// hỏng mọi yêu cầu KHÔNG đính kèm ảnh, tức gần như toàn bộ form liên hệ.
	Images    *string        `json:"-" gorm:"type:json"`
	Status    string         `json:"status"`
	AdminNote string         `json:"admin_note"`
	IP        string         `json:"-" gorm:"column:ip"`
	HandledBy *uint          `json:"handled_by"`
	HandledAt *time.Time     `json:"handled_at"`
	CreatedAt time.Time      `json:"created_at"`
	UpdatedAt time.Time      `json:"updated_at"`
	DeletedAt gorm.DeletedAt `json:"-" gorm:"index"`

	// Handler là nhân viên đã xử lý, nạp kèm để danh sách khỏi phải tra thêm.
	Handler *User `json:"handler,omitempty" gorm:"foreignKey:HandledBy"`
}

func (ContactRequest) TableName() string { return "contact_requests" }

// NewsletterSubscriber là một email đăng ký nhận tin ở chân trang.
type NewsletterSubscriber struct {
	ID uint `json:"id" gorm:"primaryKey"`
	TenantOwned
	Email          string     `json:"email"`
	IsActive       bool       `json:"is_active"`
	Source         string     `json:"source"`
	IP             string     `json:"-" gorm:"column:ip"`
	UnsubscribedAt *time.Time `json:"unsubscribed_at"`
	CreatedAt      time.Time  `json:"created_at"`
	UpdatedAt      time.Time  `json:"updated_at"`
}

func (NewsletterSubscriber) TableName() string { return "newsletter_subscribers" }

type Wishlist struct {
	ID uint `json:"id" gorm:"primaryKey"`
	TenantOwned
	UserID    uint      `json:"user_id"`
	ProductID uint      `json:"product_id"`
	CreatedAt time.Time `json:"created_at"`
}

type Notification struct {
	ID uint `json:"id" gorm:"primaryKey"`
	TenantOwned
	UserID    *uint      `json:"user_id"`
	Type      string     `json:"type"`
	Title     string     `json:"title"`
	Content   string     `json:"content"`
	Data      string     `json:"data" gorm:"type:json"`
	IsRead    bool       `json:"is_read"`
	ReadAt    *time.Time `json:"read_at"`
	CreatedAt time.Time  `json:"created_at"`
}

// ---------- 9. Marketing & cấu hình ----------

// Vị trí banner — mỗi mã tương ứng MỘT khối cố định trên storefront. Đây là danh
// sách đóng: thêm mã mới ở đây phải kèm chỗ hiển thị bên storefront, nếu không
// người bán tải ảnh lên rồi không thấy nó xuất hiện ở đâu cả.
const (
	BannerPositionHomeSlider = "home_slider" // Slideshow lớn đầu trang chủ
	BannerPositionHomePoster = "home_poster" // Dải poster trượt ngang giữa trang chủ
	BannerPositionHomeKids   = "home_kids"   // Ảnh ngang full-width trên khối "Dành cho trẻ em"
)

// BannerPositions liệt kê các vị trí hợp lệ, theo đúng thứ tự xuất hiện trên trang chủ.
var BannerPositions = []string{
	BannerPositionHomeSlider,
	BannerPositionHomePoster,
	BannerPositionHomeKids,
}

// IsValidBannerPosition cho biết mã vị trí có nằm trong danh sách hỗ trợ không.
func IsValidBannerPosition(p string) bool {
	for _, v := range BannerPositions {
		if v == p {
			return true
		}
	}
	return false
}

type Banner struct {
	ID uint `json:"id" gorm:"primaryKey"`
	TenantOwned
	Title     string `json:"title"`
	Image     string `json:"image"`
	Link      string `json:"link"`
	Position  string `json:"position"`
	SortOrder int    `json:"sort_order"`
	IsActive  bool   `json:"is_active"`
	// StartAt/EndAt là lịch chạy. nil = không giới hạn đầu đó. Banner chỉ lên
	// storefront khi is_active VÀ thời điểm hiện tại nằm trong khoảng này.
	StartAt   *time.Time `json:"start_at"`
	EndAt     *time.Time `json:"end_at"`
	CreatedAt time.Time  `json:"created_at"`
	UpdatedAt time.Time  `json:"updated_at"`
}

// IsLive cho biết banner có đang thực sự hiện trên storefront tại thời điểm now
// hay không — gộp cả công tắc hiển thị lẫn lịch chạy.
func (b Banner) IsLive(now time.Time) bool {
	if !b.IsActive {
		return false
	}
	if b.StartAt != nil && now.Before(*b.StartAt) {
		return false
	}
	if b.EndAt != nil && now.After(*b.EndAt) {
		return false
	}
	return true
}

type Setting struct {
	ID uint `json:"id" gorm:"primaryKey"`
	TenantOwned
	Key       string    `json:"key" gorm:"column:key"`
	Value     string    `json:"value" gorm:"column:value"`
	Group     string    `json:"group" gorm:"column:group"`
	CreatedAt time.Time `json:"created_at"`
	UpdatedAt time.Time `json:"updated_at"`
}

// ---------- 10. Nhật ký ----------

type ActivityLog struct {
	ID uint `json:"id" gorm:"primaryKey"`
	TenantOwned
	UserID      *uint     `json:"user_id"`
	Action      string    `json:"action"`
	SubjectType string    `json:"subject_type"`
	SubjectID   *uint     `json:"subject_id"`
	Description string    `json:"description"`
	Properties  string    `json:"properties" gorm:"type:json"`
	IPAddress   string    `json:"ip_address" gorm:"column:ip_address"`
	UserAgent   string    `json:"user_agent"`
	CreatedAt   time.Time `json:"created_at"`
}
