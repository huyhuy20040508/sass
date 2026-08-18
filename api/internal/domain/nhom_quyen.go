package domain

import (
	"context"
	"errors"
	"sort"
	"time"
)

// NhomQuyen — một cách chia việc mà CỬA HÀNG tự đặt ra: "Thu ngân", "Quản lý ca
// tối", "Thủ kho". Bảng `permission_groups`.
//
// Đừng lẫn với Role: `roles` là bốn vai trò của PHẦN MỀM, dùng chung cả nền
// tảng, trả lời "anh là loại người nào" (người của tiệm hay khách mua hàng).
// Còn đây trả lời "anh bấm được nút nào" — câu mà hai tiệm cạnh nhau trả lời
// khác nhau, nên nó phải thuộc về từng cửa hàng.
type NhomQuyen struct {
	ID uint `json:"id" gorm:"primaryKey"`
	TenantOwned

	Code        string `json:"code"`
	Name        string `json:"name"`
	Description string `json:"description"`

	// IsSystem: nhóm hệ thống dựng sẵn. Sửa được, KHÔNG xoá được.
	IsSystem bool `json:"is_system"`
	// FullAccess: mọi quyền HIỆN CÓ VÀ SẼ CÓ — xem chú thích dài ở migration 0012.
	// Đây là thứ giữ cho một module ra mắt sau này tự tới tay quản trị viên.
	FullAccess bool `json:"full_access"`

	CreatedAt time.Time `json:"created_at"`
	UpdatedAt time.Time `json:"updated_at"`
}

func (NhomQuyen) TableName() string { return "permission_groups" }

// NhomQuyenItem — một quyền được tick trong một nhóm. Bảng `permission_group_items`.
type NhomQuyenItem struct {
	ID uint `json:"id" gorm:"primaryKey"`
	TenantOwned

	GroupID    uint   `json:"group_id"`
	Permission string `json:"permission"`

	CreatedAt time.Time `json:"created_at"`
	UpdatedAt time.Time `json:"updated_at"`
}

func (NhomQuyenItem) TableName() string { return "permission_group_items" }

// NhomQuyenCuaNguoi — một người mang một nhóm. Bảng `user_permission_groups`.
//
// Nhiều-nhiều, có chủ ý: người quản lý ca tối vẫn đứng quầy, kế toán vẫn phải
// vào được kho. Với quan hệ một-một, cách duy nhất để nói "vừa quản lý vừa thu
// ngân" là đẻ ra một nhóm thứ ba mang đúng cái tên ấy — rồi nhóm thứ tư, thứ
// năm cho mọi tổ hợp.
type NhomQuyenCuaNguoi struct {
	ID uint `json:"id" gorm:"primaryKey"`
	TenantOwned

	UserID  uint `json:"user_id"`
	GroupID uint `json:"group_id"`

	CreatedAt time.Time `json:"created_at"`
	UpdatedAt time.Time `json:"updated_at"`
}

func (NhomQuyenCuaNguoi) TableName() string { return "user_permission_groups" }

// BoQuyen là tập quyền của MỘT người, dựng cho MỘT request.
//
// Khi người đó mang nhiều nhóm, đây là HỢP quyền của tất cả. Không nhóm nào
// trừ quyền của nhóm khác — một hệ vừa cộng vừa trừ thì không ai đọc màn hình
// mà đoán được kết quả.
//
// Giá trị RỖNG nghĩa là KHÔNG quyền nào — không phải toàn quyền. Đó là lối đã
// dùng cho QuyenApp của khu điều hành, và vì cùng một lý do: quên gắn middleware
// phải làm mọi thứ ĐÓNG lại, chứ không mở ra.
type BoQuyen struct {
	// ToanQuyen: super admin, hoặc nhóm có cờ full_access.
	ToanQuyen bool
	tap       map[string]bool
}

// NewBoQuyen dựng tập quyền từ danh sách đọc dưới database.
func NewBoQuyen(toanQuyen bool, quyen []string) BoQuyen {
	tap := make(map[string]bool, len(quyen))
	for _, q := range quyen {
		tap[q] = true
	}

	return BoQuyen{ToanQuyen: toanQuyen, tap: tap}
}

// Co trả lời "người này có được làm việc đó không".
func (b BoQuyen) Co(quyen string) bool {
	if b.ToanQuyen {
		return true
	}

	return b.tap[quyen]
}

// DanhSach trả về các quyền đang có, đã sắp xếp — để trả cho trang quản trị vẽ
// menu. Nhóm toàn quyền trả về TOÀN BỘ danh mục, vì đó đúng là những gì họ có.
func (b BoQuyen) DanhSach() []string {
	if b.ToanQuyen {
		return TatCaQuyen()
	}

	ds := make([]string, 0, len(b.tap))
	for q := range b.tap {
		ds = append(ds, q)
	}
	sort.Strings(ds)

	return ds
}

// QuyenRepository — sổ phân quyền của một cửa hàng.
type QuyenRepository interface {
	// BoQuyenCuaNguoi đọc tập quyền của một tài khoản qua nhóm mà họ mang.
	//
	// Chạy trên đường NÓNG: mọi request vào một đường có gắn quyền đều hỏi câu
	// này. Nhận tenantID tường minh và tự khai điều kiện, cùng lý do như
	// KiemPhien — đây là câu hỏi về bảo mật, nó không được phụ thuộc vào việc
	// ctx có mang tenant hay chưa.
	BoQuyenCuaNguoi(ctx context.Context, userID, tenantID uint) (BoQuyen, error)

	// CuaVaoCuaNguoi đọc CỬA VÀO của một tài khoản: `users.access_areas` và
	// `users.role_id`. Trả cả hai vì cột cũ có thể rỗng và bên gọi suy từ vai —
	// xem domain.CuaVao.
	//
	// Đọc từ database chứ KHÔNG lấy từ token, và đó là chủ ý: chủ tiệm bỏ tích
	// "Thu ngân" của ai thì người đó phải mất cửa NGAY, không phải chờ tới lượt
	// đăng nhập sau. Cùng đường nóng với BoQuyenCuaNguoi và cùng kiểu nhớ lại
	// trong ctx, nên một request chỉ hỏi một lần.
	CuaVaoCuaNguoi(ctx context.Context, userID, tenantID uint) (accessAreas string, roleID uint, err error)

	List(ctx context.Context) ([]NhomQuyen, error)
	FindByID(ctx context.Context, id uint) (*NhomQuyen, error)
	FindByCode(ctx context.Context, code string) (*NhomQuyen, error)
	ExistsByCode(ctx context.Context, code string, excludeID uint) (bool, error)
	Create(ctx context.Context, nq *NhomQuyen) error
	Update(ctx context.Context, nq *NhomQuyen) error
	// Delete xoá CỨNG. Nhóm còn người dùng thì khoá ngoại chặn lại, và tầng
	// nghiệp vụ dịch thành câu nói rõ còn mấy người.
	Delete(ctx context.Context, id uint) error

	QuyenCuaNhom(ctx context.Context, groupID uint) ([]string, error)
	// DatQuyenChoNhom thay TOÀN BỘ danh sách quyền của nhóm.
	DatQuyenChoNhom(ctx context.Context, groupID uint, quyen []string) error
	DemThanhVien(ctx context.Context, groupID uint) (int64, error)

	// NhomCuaNguoi trả về id các nhóm một người đang mang.
	NhomCuaNguoi(ctx context.Context, userID uint) ([]uint, error)
	// NhomTheoNguoi đọc nhóm của NHIỀU người trong MỘT lượt.
	//
	// Có mặt vì màn hình nhân sự cần nó cho cả danh sách: mỗi dòng một lượt đọc
	// thì một cửa hàng ba chục người là ba chục lượt cho một lần mở trang.
	NhomTheoNguoi(ctx context.Context, userIDs []uint) (map[uint][]uint, error)
	// DatNhomChoNguoi thay TOÀN BỘ danh sách nhóm của một người.
	//
	// Danh sách rỗng = thu hết quyền. Khác hẳn khoá tài khoản: người đó vẫn đăng
	// nhập được, chỉ là không mở được trang nào.
	DatNhomChoNguoi(ctx context.Context, userID uint, groupIDs []uint) error
}

// Lỗi nghiệp vụ của phân quyền.
var (
	ErrMaNhomQuyenDaCo = errors.New("mã nhóm quyền này đã có nhóm khác dùng")
	// ErrQuyenLa — chuỗi quyền không có trong danh mục. Chặn ở đây thay vì ghi
	// xuống: một quyền gõ sai là một trang không ai mở được mà không báo lỗi gì.
	ErrQuyenLa = errors.New("quyền không có trong danh mục")
	// ErrNhomQuyenDangDung — xoá nhóm còn người mang.
	ErrNhomQuyenDangDung = errors.New("nhóm quyền này đang có người dùng")
	// ErrNhomQuyenHeThong — xoá nhóm hệ thống dựng sẵn.
	ErrNhomQuyenHeThong = errors.New("không xoá được nhóm quyền hệ thống")
)

// NhomMacDinh — hai nhóm hệ thống dựng cho MỌI cửa hàng.
//
// Khai trong Go chứ không chỉ trong SQL của migration, vì cùng bộ dữ liệu này
// phục vụ hai chỗ: gieo cho cửa hàng đang có (lệnh cmd/quyen) và gieo cho cửa
// hàng mở mới sau này. Hai bản chép tay ở hai nơi thì sẽ lệch.
type NhomMacDinh struct {
	Code       string
	Name       string
	MoTa       string
	FullAccess bool
	Quyen      []string
}

// NhomDungSan trả về hai nhóm mặc định.
//
// "Thu ngân" là BẢN GHI LẠI hành vi của vai trò `staff` trước lượt đổi này —
// đúng bằng, không hơn một chuỗi nào. Đó là hợp đồng của lượt di trú: sau khi
// triển khai, không thu ngân nào mất quyền và cũng không ai được thêm quyền.
func NhomDungSan() []NhomMacDinh {
	return []NhomMacDinh{
		{
			Code: NhomQuyenQuanLy, Name: "Quản lý",
			MoTa: "Toàn quyền trên cửa hàng", FullAccess: true,
		},
		{
			Code: NhomQuyenThuNgan, Name: "Thu ngân",
			MoTa:  "Bán tại quầy, đơn hàng, ca làm việc và sổ quỹ",
			Quyen: QuyenThuNgan(),
		},
	}
}
