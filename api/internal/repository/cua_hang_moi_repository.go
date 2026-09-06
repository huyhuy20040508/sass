package repository

import (
	"context"
	"errors"
	"time"

	"gorm.io/gorm"

	"sass-api/internal/domain"
	"sass-api/internal/tenant"
)

// taiKhoanCuaHangRepository đọc và đặt lại mật khẩu tài khoản quản trị của một
// cửa hàng đang chạy. Kết nối DATA PLANE (NewDB).
type taiKhoanCuaHangRepository struct{ db *gorm.DB }

// NewTaiKhoanCuaHangRepository nhận kết nối DATA PLANE (NewDB).
func NewTaiKhoanCuaHangRepository(db *gorm.DB) domain.TaiKhoanCuaHangRepository {
	return &taiKhoanCuaHangRepository{db: db}
}

// QuanTri trả về tài khoản quản trị CŨ NHẤT của cửa hàng.
//
// Sắp theo id tăng dần: đó là tài khoản dựng cùng cửa hàng, tức người mà "đổi
// mật khẩu cho khách" gần như luôn có nghĩa là họ. Một cửa hàng có nhiều
// super_admin là chuyện có thật, nên nơi hiển thị PHẢI in tên đăng nhập ra —
// người bấm nút cần biết mình đang đổi mật khẩu của ai.
//
// Tính cả tài khoản đang bị khoá (`status = 'inactive'`): khoá rồi vẫn là tài
// khoản quản trị của tiệm đó, và giấu nó đi thì màn hình báo "cửa hàng không có
// tài khoản quản trị" — sai, và đẩy người xử lý đi tìm nhầm hướng. Trạng thái
// được trả ra để màn hình in kèm.
//
// KHÔNG tính tài khoản đã xoá mềm — `domain.User` có `gorm.DeletedAt` nên GORM
// tự loại chúng. Đúng như vậy: một tài khoản đã xoá thì không còn là quản trị
// của tiệm nào.
func (r *taiKhoanCuaHangRepository) QuanTri(ctx context.Context, tenantID uint) (*domain.QuanTriCuaHang, error) {
	var u domain.User
	err := r.db.WithContext(tenant.WithID(ctx, tenantID)).
		Where("role_id = ?", domain.SuperAdminRoleID).
		Order("id").
		First(&u).Error
	if errors.Is(err, gorm.ErrRecordNotFound) {
		return nil, domain.ErrNotFound
	}
	if err != nil {
		return nil, err
	}

	return &domain.QuanTriCuaHang{
		ID:          u.ID,
		TenDangNhap: string(u.Username),
		HoTen:       u.FullName,
		Email:       u.Email,
		TrangThai:   u.Status,
	}, nil
}

// DoiMatKhau ghi mật khẩu ĐÃ BĂM.
//
// tenant_id nằm trong điều kiện WHERE nhờ ctx mang tenant (bộ lọc GORM tự chèn),
// nên id người dùng có bị truyền sai cũng không đổi được mật khẩu của khách
// khác — cùng lắm là không khớp dòng nào.
//
// CHỈ đổi mật khẩu, không đụng `status`. Mở khoá hộ một tài khoản đang bị khoá
// là một thay đổi thứ hai mà người bấm nút không yêu cầu — và nếu ai đó cố ý
// khoá nó thì việc mở lại phải là một quyết định riêng. Màn hình in trạng thái
// tài khoản ra để người xử lý thấy nó đang khoá mà quyết.
func (r *taiKhoanCuaHangRepository) DoiMatKhau(ctx context.Context, tenantID, userID uint, bamMatKhau string) error {
	res := r.db.WithContext(tenant.WithID(ctx, tenantID)).
		Model(&domain.User{}).
		Where("id = ?", userID).
		Updates(map[string]any{
			"password_hash": bamMatKhau,
			"updated_at":    time.Now(),
		})
	if res.Error != nil {
		return res.Error
	}
	if res.RowsAffected == 0 {
		return domain.ErrNotFound
	}

	return nil
}

// cuaHangMoiRepository dựng một cửa hàng mới ở DATA PLANE.
//
// Kết nối truyền vào là repository.NewDB — kết nối CÓ bộ lọc tenant. Cố ý không
// dùng một kết nối trần: bộ lọc là thứ ghi hộ `tenant_id` vào `shops` và `users`
// (xem tenantScope.stamp), nên đi vòng qua nó nghĩa là tự điền cột đó bằng tay ở
// hai chỗ, và chỗ thứ hai sẽ là chỗ quên.
//
// Đây là repository DUY NHẤT của khu điều hành chạm vào data plane. Nó tồn tại
// vì bán phần mềm cho khách mới nghĩa là phải có chỗ cho họ đăng nhập, mà chỗ đó
// nằm bên kia ranh giới hai lược đồ.
type cuaHangMoiRepository struct{ db *gorm.DB }

// NewCuaHangMoiRepository nhận kết nối DATA PLANE (NewDB).
func NewCuaHangMoiRepository(db *gorm.DB) domain.CuaHangMoiRepository {
	return &cuaHangMoiRepository{db: db}
}

// cuaHangRow là bảng `tenants` của DATA PLANE kèm hai cột thời gian.
//
// Không dùng domain.Tenant: kiểu đó cố ý chỉ có bốn trường (id · code · name ·
// status) vì nó phục vụ đường ĐĂNG NHẬP, nơi không ai cần biết cửa hàng vào sổ
// hôm nào. Thêm hai cột vào đó chỉ để phục vụ một câu INSERT là bắt mọi lượt
// đăng nhập đọc thừa hai cột. Để created_at NULL thì cũng chạy — cột cho phép
// NULL — nhưng "khách này vào sổ hôm nào" là câu hỏi có thật, và không có cách
// nào tìm lại câu trả lời sau khi đã ghi hụt.
type cuaHangRow struct {
	ID        uint   `gorm:"primaryKey"`
	Code      string `gorm:"column:code"`
	Name      string `gorm:"column:name"`
	Status    string `gorm:"column:status"`
	CreatedAt time.Time
	UpdatedAt time.Time
}

func (cuaHangRow) TableName() string { return "tenants" }

// chiNhanhRow là bảng `shops`.
//
// Chưa có entity nào cho bảng này trong domain: hôm nay không đường API nào đọc
// hay ghi chi nhánh, chỉ có công cụ dòng lệnh. Khai tại chỗ thay vì thêm một
// entity dùng đúng một lần — nhưng PHẢI nhúng domain.TenantOwned, nếu không
// tenantScope.stamp không tìm thấy cột tenant_id và sẽ chặn câu INSERT lại.
type chiNhanhRow struct {
	ID uint `gorm:"primaryKey"`
	domain.TenantOwned
	Code      string `gorm:"column:code"`
	Name      string `gorm:"column:name"`
	IsActive  bool   `gorm:"column:is_active"`
	CreatedAt time.Time
	UpdatedAt time.Time
}

func (chiNhanhRow) TableName() string { return "shops" }

// maChiNhanhMacDinh — mã chi nhánh đầu tiên của mọi cửa hàng, giống hệt
// `cmd/tao-admin`. Mã chỉ cần duy nhất trong một tenant nên mọi khách dùng chung
// một chuỗi được.
const maChiNhanhMacDinh = "mac-dinh"

// loaiThuChiKhoiDiem — danh sách phân loại thu chi gieo sẵn cho cửa hàng mới.
//
// Cùng bộ với migration 0057 đã gieo cho các cửa hàng đang có, để cửa hàng dựng
// trước và dựng sau nhìn thấy một danh sách. Sửa ở đây thì phải viết thêm một
// migration cho cửa hàng cũ, không thì hai đường vào lệch nhau.
//
// Gieo với IsDefault = false: đây là gợi ý mở màn chứ không phải loại hệ thống
// bám vào phiếu tự sinh. Tiệm không dùng "Khấu hao tài sản cố định" thì xoá đi.
var loaiThuChiKhoiDiem = []domain.LoaiThuChi{
	{Type: domain.LoaiThu, Name: "Các khoản thu khác"},
	{Type: domain.LoaiChi, Name: "Lương, thưởng, phụ cấp, bảo hiểm cho người lao động"},
	{Type: domain.LoaiChi, Name: "Thuê mặt bằng, thuê kho, thuê quầy, thuê thiết bị"},
	{Type: domain.LoaiChi, Name: "Điện, nước, Internet, điện thoại, viễn thông"},
	{Type: domain.LoaiChi, Name: "Phí thanh toán, phí ngân hàng, phí cổng thanh toán"},
	{Type: domain.LoaiChi, Name: "Marketing, quảng cáo, khuyến mại, chăm sóc khách hàng"},
	{Type: domain.LoaiChi, Name: "Sửa chữa, bảo dưỡng"},
	{Type: domain.LoaiChi, Name: "Phần mềm, dịch vụ thuê ngoài, tư vấn, quản trị"},
	{Type: domain.LoaiChi, Name: "Khấu hao tài sản cố định, công cụ dụng cụ"},
	{Type: domain.LoaiChi, Name: "Công tác phí, hội nghị, đào tạo"},
	{Type: domain.LoaiChi, Name: "Thuế, phí, lệ phí được phép tính vào chi phí"},
	{Type: domain.LoaiChi, Name: "Vận chuyển, giao hàng, phí đối tác giao hàng"},
}

// CoMa cho biết mã cửa hàng đã có người dùng chưa.
//
// `tenants` là bảng toàn cục (xem globalTables) nên câu này không cần tenant
// trong ctx — và cũng không được cần: lúc gọi nó thì cửa hàng còn chưa tồn tại.
//
// Đếm cả cửa hàng đang bị khoá: mã là duy nhất TOÀN HỆ THỐNG (uq_tenants_code),
// không phải duy nhất trong số đang mở. Lọc theo status ở đây thì màn hình báo
// "mã dùng được" rồi câu INSERT vỡ khoá ngay sau đó.
func (r *cuaHangMoiRepository) CoMa(ctx context.Context, ma string) (bool, error) {
	var so int64
	if err := r.db.WithContext(ctx).
		Model(&cuaHangRow{}).
		Where("code = ?", ma).
		Count(&so).Error; err != nil {
		return false, err
	}

	return so > 0, nil
}

// DanhSach liệt kê mọi cửa hàng ở data plane.
//
// `tenants` là bảng toàn cục nên không cần tenant trong ctx — và không được
// cần: đây là câu hỏi bắc qua mọi khách hàng, đúng bản chất khu điều hành.
//
// Không lọc theo `status`: cửa hàng đang bị khoá vẫn ký hợp đồng được (ký xong
// mở khoá), và giấu nó đi thì người ký đi tìm mãi một cái tên có thật.
func (r *cuaHangMoiRepository) DanhSach(ctx context.Context) ([]domain.CuaHangCoSan, error) {
	var rows []domain.CuaHangCoSan
	err := r.db.WithContext(ctx).
		Model(&cuaHangRow{}).
		Select("id, code AS ma, name AS ten, status AS trang_thai").
		Order("code").
		Scan(&rows).Error

	return rows, err
}

func (r *cuaHangMoiRepository) TimTheoMa(ctx context.Context, ma string) (*domain.CuaHangCoSan, error) {
	if ma == "" {
		return nil, domain.ErrNotFound
	}

	var ra domain.CuaHangCoSan
	err := r.db.WithContext(ctx).
		Model(&cuaHangRow{}).
		Select("id, code AS ma, name AS ten, status AS trang_thai").
		Where("code = ?", ma).
		Take(&ra).Error
	if errors.Is(err, gorm.ErrRecordNotFound) {
		return nil, domain.ErrNotFound
	}
	if err != nil {
		return nil, err
	}

	return &ra, nil
}

// DoiTrangThai khoá / mở khoá cửa hàng ở DATA PLANE.
//
// `tenants` là bảng toàn cục (xem globalTables) nên câu này không cần tenant
// trong ctx — và không được cần: nó chạy từ lượt quét hạn của máy chủ, một
// đường không có request nào đứng sau, nên cũng không có cửa hàng nào trong
// ngữ cảnh.
//
// `status <> ?` trong WHERE để RowsAffected đếm đúng số cửa hàng THẬT SỰ đổi
// trạng thái. Con số đó đi thẳng vào nhật ký, và một lượt quét báo "vừa khoá 12
// cửa hàng" trong khi cả 12 đều đã khoá từ hôm qua là một dòng nhật ký nói dối.
func (r *cuaHangMoiRepository) DoiTrangThai(ctx context.Context, ids []uint, trangThai string) (int64, error) {
	if len(ids) == 0 {
		return 0, nil
	}

	res := r.db.WithContext(ctx).
		Model(&cuaHangRow{}).
		Where("id IN ?", ids).
		Where("status <> ?", trangThai).
		Updates(map[string]any{
			"status":     trangThai,
			"updated_at": time.Now(),
		})

	return res.RowsAffected, res.Error
}

// Tao dựng cửa hàng + chi nhánh mặc định + tài khoản quản trị.
//
// BA BẢNG TRONG MỘT GIAO DỊCH. Không phải để cho gọn: một cửa hàng có mặt trong
// `tenants` mà không ai đăng nhập được vào là thứ tệ hơn hẳn việc không tạo gì —
// mã đã bị chiếm, người bán thử lại sẽ ăn lỗi "mã đã có người dùng", và phải mở
// database ra dọn tay mới đi tiếp được.
//
// Giao dịch này KHÔNG bao gồm hợp đồng bên control plane: hai database khác nhau
// thì không có giao dịch chung. Thứ tự và cách xử lý phần hụt đó nằm ở service.
func (r *cuaHangMoiRepository) Tao(ctx context.Context, moi domain.CuaHangMoi) (uint, error) {
	var tenantID uint

	err := r.db.Transaction(func(tx *gorm.DB) error {
		ch := cuaHangRow{
			Code:   moi.Ma,
			Name:   moi.Ten,
			Status: domain.TenantActive,
		}
		if err := tx.WithContext(ctx).Create(&ch).Error; err != nil {
			if errors.Is(err, gorm.ErrDuplicatedKey) {
				// Có người vừa chiếm mã này giữa lúc CoMa và câu INSERT. Hiếm, nhưng
				// khoá duy nhất dưới database mới là chốt chặn thật — lượt kiểm ở
				// trên chỉ để báo sớm cho người đang gõ form.
				return domain.ErrCuaHangDaCo
			}

			return err
		}
		tenantID = ch.ID

		// Từ đây trở xuống là bảng CỦA KHÁCH HÀNG. ctx phải mang tenant vừa cấp,
		// nếu không tenantScope chặn câu INSERT lại kèm ErrNoTenant — đúng như nó
		// phải làm, vì "chưa xác định được cửa hàng" và "cửa hàng này" là hai
		// chuyện khác nhau.
		//
		// Dùng WithID chứ KHÔNG dùng WithoutScope: cửa hàng ở đây đã xác định rồi,
		// và tắt bộ lọc đi thì cột tenant_id không được ghi hộ nữa.
		ctxMoi := tenant.WithID(ctx, tenantID)

		// Mỗi cửa hàng phải có ít nhất một chi nhánh: bảng giao dịch mang shop_id,
		// và gói một-cửa-hàng chính là tenant có ĐÚNG MỘT shop (giao diện ẩn hẳn
		// khái niệm chi nhánh khi chỉ có một).
		cn := chiNhanhRow{
			Code:     maChiNhanhMacDinh,
			Name:     moi.Ten,
			IsActive: true,
		}
		if err := tx.WithContext(ctxMoi).Create(&cn).Error; err != nil {
			return err
		}

		// EmailVerifiedAt đặt sẵn: tài khoản này do NGƯỜI BÁN dựng hộ, không đi qua
		// luồng đăng ký có mã OTP. Để trống thì khách đăng nhập lần đầu bị chặn ở
		// bước xác thực email và không có mã nào để nhập.
		bayGio := time.Now()
		ad := domain.User{
			Username:     domain.StringOrNull(moi.TenDangNhap),
			RoleID:       domain.SuperAdminRoleID,
			FullName:     moi.HoTen,
			Email:        moi.Email,
			PasswordHash: moi.BamMatKhau,
			// "active" viết thẳng: bảng users chưa có bộ hằng số trạng thái, và cả
			// auth_service.go cũng đang viết thẳng chuỗi này.
			Status:          "active",
			EmailVerifiedAt: &bayGio,
		}
		// Danh sách phân loại thu chi mở màn. Gieo ngay ở đây chứ không để màn
		// Loại thu chi tự gieo lúc mở lần đầu: gieo theo lượt ĐỌC thì hai người
		// mở cùng lúc là gieo hai lần, mà bảng cố ý không có khoá duy nhất để bắt.
		khoiDiem := make([]domain.LoaiThuChi, len(loaiThuChiKhoiDiem))
		copy(khoiDiem, loaiThuChiKhoiDiem)
		if err := tx.WithContext(ctxMoi).Create(&khoiDiem).Error; err != nil {
			return err
		}

		if err := tx.WithContext(ctxMoi).Create(&ad).Error; err != nil {
			if errors.Is(err, gorm.ErrDuplicatedKey) {
				// Khoá duy nhất của users tính theo (tenant_id, …) mà tenant thì vừa
				// sinh ra xong, nên không trùng với khách nào khác được. Còn lại đúng
				// một khả năng: form gửi lên email hoặc tên đăng nhập trùng NHAU
				// trong cùng lượt — không có, vì chỉ tạo một tài khoản. Giữ nhánh này
				// để lỗi lạ không đội lốt lỗi 500 vô danh.
				return domain.ErrConflict
			}

			return err
		}

		return nil
	})
	if err != nil {
		return 0, err
	}

	return tenantID, nil
}
