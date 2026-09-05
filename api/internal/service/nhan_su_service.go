package service

import (
	"context"
	"fmt"
	"strconv"
	"strings"
	"time"

	"sass-api/internal/domain"
	"sass-api/internal/dto"
)

// NhanSuService — nghiệp vụ HỒ SƠ NHÂN VIÊN của một cửa hàng.
//
// Hai luật đáng nhớ, cả hai đều là nghiệp vụ chứ không phải kiểm tra dữ liệu:
//
//   - Mã nhân viên duy nhất trong MỘT cửa hàng, tự sinh NV0001, NV0002… khi bỏ
//     trống. Mã đi vào bảng lương nên đặt xong thì hạn chế đổi.
//   - Cấp tài khoản là việc TUỲ CHỌN nằm trong hồ sơ, và nó dùng lại UserService
//     của trang tài khoản cũ — không có bản sao thứ hai của những luật ấy ở đây.
//   - Trạng thái làm việc KÉO THEO tài khoản đăng nhập: đánh dấu "đã nghỉ" là
//     khoá luôn tài khoản gắn kèm. Xem dongBoTaiKhoan.
type NhanSuService interface {
	List(ctx context.Context, f domain.NhanSuFilter) ([]dto.NhanSuResponse, error)
	GetByID(ctx context.Context, id uint) (*dto.NhanSuResponse, error)
	Create(ctx context.Context, req *dto.NhanSuRequest, actor Actor) (*dto.NhanSuResponse, error)
	Update(ctx context.Context, id uint, req *dto.NhanSuRequest, actor Actor) (*dto.NhanSuResponse, error)
	// DoiTrangThai chỉ đổi ĐÚNG cột trạng thái của hồ sơ — cho công tắc trên bảng
	// danh sách. Tài khoản đăng nhập đi theo, đó là phần duy nhất nó chạm ra ngoài.
	DoiTrangThai(ctx context.Context, id uint, req *dto.NhanSuTrangThaiRequest, actor Actor) (*dto.NhanSuResponse, error)
	// Delete cần Actor vì nó KHOÁ tài khoản đăng nhập gắn kèm — mà mọi luật của
	// tài khoản (super admin cuối cùng, quyền của người thao tác) đều hỏi Actor.
	Delete(ctx context.Context, id uint, actor Actor) error
}

type nhanSuService struct {
	repo     domain.NhanVienRepository
	chiNhanh domain.ChiNhanhRepository
	// UserService chứ không phải UserRepository: cấp tài khoản phải đi qua đủ luật
	// của nó (vai trò, tên đăng nhập, email trùng, hạn mức, mật khẩu mặc định).
	users UserService
	// quyen chỉ dùng để ĐỌC nhóm của tài khoản gắn với hồ sơ. Việc gán nhóm là
	// đường riêng (NhomQuyenService) — nhóm quyền thuộc về tài khoản, còn đây là
	// sổ con người.
	quyen domain.QuyenRepository
	// quyTac là quy tắc đánh số của cửa hàng. Chưa bật thì mã vẫn là NV0001…
	quyTac domain.QuyTacMaRepository
}

func NewNhanSuService(
	repo domain.NhanVienRepository,
	chiNhanh domain.ChiNhanhRepository,
	users UserService,
	quyen domain.QuyenRepository,
	quyTac domain.QuyTacMaRepository,
) NhanSuService {
	return &nhanSuService{repo: repo, chiNhanh: chiNhanh, users: users, quyen: quyen, quyTac: quyTac}
}

// Tiền tố mã tự sinh; bốn chữ số đủ cho 9999 người.
const tienToMaNhanVien = "NV"

func (s *nhanSuService) List(ctx context.Context, f domain.NhanSuFilter) ([]dto.NhanSuResponse, error) {
	list, err := s.repo.List(ctx, f)
	if err != nil {
		return nil, err
	}

	return s.dungDanhSach(ctx, list)
}

func (s *nhanSuService) GetByID(ctx context.Context, id uint) (*dto.NhanSuResponse, error) {
	nv, err := s.repo.FindByID(ctx, id)
	if err != nil {
		return nil, err
	}
	items, err := s.dungDanhSach(ctx, []domain.NhanVien{*nv})
	if err != nil {
		return nil, err
	}

	return &items[0], nil
}

func (s *nhanSuService) Create(ctx context.Context, req *dto.NhanSuRequest, actor Actor) (*dto.NhanSuResponse, error) {
	if err := kiemEnumNhanSu(req); err != nil {
		return nil, err
	}

	ma, err := s.chotMa(ctx, req.Code, 0)
	if err != nil {
		return nil, err
	}
	if err := s.chanTrungTen(ctx, req.FullName, 0); err != nil {
		return nil, err
	}

	shopID, err := s.chotChiNhanh(ctx, req.ShopID)
	if err != nil {
		return nil, err
	}

	nv := &domain.NhanVien{Code: ma, ShopID: shopID}
	dienHoSo(nv, req)

	// Tài khoản dựng TRƯỚC hồ sơ: đó là lượt dễ hỏng nhất (trùng tên đăng nhập,
	// trùng email, hết hạn mức), và hỏng trước thì cửa hàng không đổi gì cả.
	if req.TaiKhoan != nil {
		u, err := s.taoTaiKhoan(ctx, req, actor)
		if err != nil {
			return nil, err
		}
		nv.UserID = &u.ID

		// Cửa vào ghi ngay sau khi có tài khoản: UserService.Create chỉ nhận role_id,
		// còn access_areas là cột riêng — xem migration 0015.
		if err := s.datCuaVao(ctx, u.ID, req, actor); err != nil {
			return nil, err
		}

		// Khai một người đã nghỉ mà vẫn cấp tài khoản là chuyện hiếm, nhưng để lọt
		// thì tài khoản đó mở toang. Đóng luôn cho khớp với hồ sơ.
		if err := s.dongBoTaiKhoan(ctx, nv, req.Status, false, actor); err != nil {
			return nil, err
		}
	}

	if err := s.repo.Create(ctx, nv); err != nil {
		return nil, err
	}

	return s.GetByID(ctx, nv.ID)
}

func (s *nhanSuService) Update(ctx context.Context, id uint, req *dto.NhanSuRequest, actor Actor) (*dto.NhanSuResponse, error) {
	if err := kiemEnumNhanSu(req); err != nil {
		return nil, err
	}

	nv, err := s.repo.FindByID(ctx, id)
	if err != nil {
		return nil, err
	}
	if err := s.chanTrungTen(ctx, req.FullName, id); err != nil {
		return nil, err
	}

	// Mã bỏ trống khi sửa = GIỮ NGUYÊN, không sinh mã mới. Khác hẳn lúc tạo, và
	// khác có chủ ý: mã cũ đã nằm trong bảng lương và bảng chấm công.
	if ma := strings.TrimSpace(req.Code); ma != "" && ma != nv.Code {
		chot, err := s.chotMa(ctx, ma, id)
		if err != nil {
			return nil, err
		}
		nv.Code = chot
	}

	shopID, err := s.chotChiNhanh(ctx, req.ShopID)
	if err != nil {
		return nil, err
	}
	nv.ShopID = shopID
	dienHoSo(nv, req)

	// Hồ sơ đang có tài khoản thì dừng: tạo thêm cái thứ hai cho cùng một người là
	// cách chắc chắn để cửa hàng không biết ai đang đăng nhập bằng gì.
	if req.TaiKhoan != nil {
		if nv.UserID != nil {
			return nil, domain.ErrNhanSuDaCoTaiKhoan
		}
		u, err := s.taoTaiKhoan(ctx, req, actor)
		if err != nil {
			return nil, err
		}
		nv.UserID = &u.ID
		if err := s.datCuaVao(ctx, u.ID, req, actor); err != nil {
			return nil, err
		}
	}

	// Hồ sơ đã có tài khoản: ô quyền trên màn hình là lệnh ĐỔI VAI TRÒ cho tài
	// khoản đó. Thiếu đoạn này thì chủ tiệm chọn quyền khác, bấm Lưu, màn hình
	// báo đã cập nhật — còn huy hiệu ngoài bảng vẫn nguyên như cũ, vì chẳng có
	// gì được ghi cả.
	//
	// SetRole tự bỏ qua khi vai trò không đổi, nên lượt sửa tên hay sửa lương
	// bình thường không phát sinh thêm lượt ghi nào.
	if req.TaiKhoan == nil && nv.UserID != nil && req.RoleID > 0 {
		if _, err := s.users.SetRole(ctx, *nv.UserID, req.RoleID, actor); err != nil {
			return nil, err
		}
		// Cửa vào đi cùng lượt đổi vai: bỏ tích "Thu ngân" của một quản lý là họ
		// mất quầy ngay lượt bấm sau, không phải chờ đăng nhập lại.
		if err := s.datCuaVao(ctx, *nv.UserID, req, actor); err != nil {
			return nil, err
		}
	}

	// Ô trạng thái trong form cũng đặt được "đã nghỉ", nên nó phải khoá tài khoản
	// y như công tắc ngoài bảng — hai đường vào cùng một việc thì không được cho
	// ra hai kết quả khác nhau.
	if err := s.dongBoTaiKhoan(ctx, nv, req.Status, req.MoTaiKhoan, actor); err != nil {
		return nil, err
	}

	if err := s.repo.Update(ctx, nv); err != nil {
		return nil, err
	}

	return s.GetByID(ctx, id)
}

// DoiTrangThai chỉ chạm vào một cột của hồ sơ — đó là toàn bộ lý do nó tồn tại:
// một cú bấm công tắc không được ghi đè lương hay chi nhánh bằng dữ liệu cũ trên
// màn hình. Thứ duy nhất nó chạm thêm là tài khoản đăng nhập (dongBoTaiKhoan).
func (s *nhanSuService) DoiTrangThai(ctx context.Context, id uint, req *dto.NhanSuTrangThaiRequest, actor Actor) (*dto.NhanSuResponse, error) {
	if !domain.TrangThaiNhanSuHopLe[req.Status] {
		return nil, loiO(map[string]string{"status": "Trạng thái làm việc không hợp lệ"})
	}

	nv, err := s.repo.FindByID(ctx, id)
	if err != nil {
		return nil, err
	}

	// Tài khoản đi TRƯỚC hồ sơ, cùng lý do như lúc tạo: đó là lượt dễ hỏng nhất
	// (super admin cuối cùng, tài khoản của chính người đang bấm). Hỏng ở đây thì
	// hồ sơ chưa đổi gì, và cửa hàng đọc màn hình vẫn thấy đúng những gì đang có.
	if err := s.dongBoTaiKhoan(ctx, nv, req.Status, req.MoTaiKhoan, actor); err != nil {
		return nil, err
	}

	nv.Status = req.Status
	if err := s.repo.Update(ctx, nv); err != nil {
		return nil, err
	}

	return s.GetByID(ctx, id)
}

// Delete xoá MỀM hồ sơ, và KHOÁ luôn tài khoản đăng nhập gắn kèm.
//
// Trước đây chỗ này để nguyên tài khoản, và đó là một lỗ hổng đúng bằng lỗ hổng
// migration 0011 đã bịt cho trạng thái "đã nghỉ" — chỉ khác là kín hơn nhiều:
//
//	Xoá hồ sơ thì repository nhả `user_id` ra trước khi xoá mềm. Từ giây đó
//	không còn gì nối tài khoản kia với hồ sơ nào nữa. Nó nằm lại trong `users`,
//	status vẫn 'active', không hiện ở màn hình nhân sự vì hồ sơ đã biến mất —
//	và người vừa bị xoá vẫn đăng nhập được bằng mật khẩu cũ. Không ai phát hiện
//	ra, vì không còn chỗ nào để nhìn.
//
// Xoá là hành động MẠNH HƠN "đã nghỉ", nên nó không thể lỏng hơn.
//
// Đi qua UserService.UpdateStatus chứ không ghi thẳng cột: mọi luật của tài
// khoản nằm ở đó và chỉ ở đó.
func (s *nhanSuService) Delete(ctx context.Context, id uint, actor Actor) error {
	nv, err := s.repo.FindByID(ctx, id)
	if err != nil {
		return err
	}

	if nv.UserID != nil {
		// RÀNG BUỘC SỔ SÁCH đi trước mọi lượt ghi. Hai câu hỏi, hai lý do khác nhau:
		//
		//  - Ca chưa đóng: xoá là khoá tài khoản, nên chính người đang giữ két mất
		//    đường đóng ca của mình. Ca treo lơ lửng, tiền trong đó không ai đối
		//    chiếu được nữa.
		//  - Đã ghi sổ quỹ: giữ hồ sơ để mấy dòng tiền ấy còn tra ra tên người ghi.
		//    Xoá mềm thì hàng vẫn nằm đó thật, nhưng danh sách không thấy — và lúc
		//    đối chiếu quỹ thì "không thấy" với "không có" là một.
		//
		// Cả hai đều bảo người dùng dùng trạng thái "đã nghỉ": hồ sơ ở lại, tài
		// khoản vẫn bị khoá, không mất gì cả.
		coCaChuaDong, coSoQuy, err := s.repo.RangBuocCuaTaiKhoan(ctx, *nv.UserID)
		if err != nil {
			return err
		}
		if coCaChuaDong {
			return domain.ErrNhanSuDangMoCa
		}
		if coSoQuy {
			return domain.ErrNhanSuDaGhiSoQuy
		}

		// Xoá hồ sơ của CHÍNH mình: lượt đó khoá luôn tài khoản đang thao tác, tức
		// là tự đá mình ra giữa chừng. Chặn trước bằng câu đọc hiểu được, cùng lối
		// với dongBoTaiKhoan.
		//
		// Hiện KHÔNG dựng được cảnh này qua API (đường tạo hồ sơ luôn cấp tài khoản
		// MỚI, không nhận user_id sẵn có) nên không có bài kiểm — nhưng dữ liệu thật
		// có những hồ sơ trỏ vào tài khoản của chính chủ tiệm, và ngày nào mở đường
		// gắn hồ sơ vào tài khoản sẵn có thì nhánh này thành đường đi được ngay.
		if *nv.UserID == actor.ID {
			return domain.ErrTuDanhDauNghiViec
		}
		// Tài khoản đi TRƯỚC hồ sơ: hỏng ở đây (super admin cuối cùng, thiếu quyền)
		// thì hồ sơ chưa mất, và cửa hàng đọc màn hình vẫn thấy đúng những gì đang có.
		if _, err := s.users.UpdateStatus(ctx, *nv.UserID, "inactive", actor); err != nil {
			return err
		}
	}

	return s.repo.Delete(ctx, id)
}

// ---------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------

// dungDanhSach ghép tên chi nhánh và tài khoản vào hồ sơ: hai lượt đọc cho CẢ
// danh sách rồi tra trong map, không phải mỗi dòng một lượt.
func (s *nhanSuService) dungDanhSach(ctx context.Context, list []domain.NhanVien) ([]dto.NhanSuResponse, error) {
	tenChiNhanh := map[uint]string{}
	if cns, err := s.chiNhanh.List(ctx, false); err == nil {
		for _, cn := range cns {
			tenChiNhanh[cn.ID] = cn.Name
		}
	} else {
		return nil, err
	}

	// PageSize lớn: tài khoản nội bộ của một cửa hàng chỉ vài chục.
	taiKhoan := map[uint]dto.UserResponse{}
	us, _, err := s.users.List(ctx, domain.InternalUserFilter{Status: "all", Page: 1, PageSize: 500})
	if err != nil {
		return nil, err
	}
	for _, u := range us {
		taiKhoan[u.ID] = u
	}

	items := make([]dto.NhanSuResponse, 0, len(list))
	for i := range list {
		nv := list[i]
		item := dto.NhanSuResponse{NhanVien: nv}
		if nv.ShopID != nil {
			item.ShopName = tenChiNhanh[*nv.ShopID]
		}
		if nv.UserID != nil {
			if u, ok := taiKhoan[*nv.UserID]; ok {
				item.Username = u.Username
				item.RoleID = u.RoleID
				item.UserStatus = u.Status
				item.RoleDisplayName = u.RoleDisplayName
				item.Quyen = u.Quyen
			}
		}
		items = append(items, item)
	}

	return items, nil
}

// dongBoTaiKhoan giữ TÀI KHOẢN ĐĂNG NHẬP đi theo trạng thái làm việc của hồ sơ.
//
// Lý do có hàm này, nói thẳng: đánh dấu "đã nghỉ" mà chỉ đổi cột employees.status
// thì dòng trong `users` vẫn active, và người vừa nghỉ hôm qua vẫn mở được quầy
// bán bằng mật khẩu cũ. Hồ sơ nói họ đã đi, phần mềm vẫn cho họ vào — đó là lỗ
// hổng, không phải chuyện hiển thị.
//
// Hai chiều KHÔNG đối xứng, có chủ ý:
//
//   - Sang `da_nghi`: khoá ngay, không hỏi. Khoá nhầm thì mở lại mất một lượt bấm;
//     quên khoá thì không ai biết cho tới khi có đơn hàng lạ.
//   - Về `dang_lam`: chỉ mở khi lượt gọi nói rõ moTaiKhoan — nhận lại người cũ là
//     quyết định của chủ tiệm, và màn hình hỏi lại trước khi gửi. Bỏ trống thì hồ
//     sơ đi làm lại nhưng tài khoản vẫn khoá, và danh sách nói rõ điều đó.
//   - `tam_nghi` không đụng tới tài khoản: người nghỉ dài ngày vẫn thuộc cửa hàng.
//     Trạng thái này không đặt được từ màn hình nhân sự, nó thuộc bảng chấm công.
//
// Đi qua UserService.UpdateStatus chứ không ghi thẳng cột: mọi luật của tài khoản
// (super admin cuối cùng, quyền của người thao tác) nằm ở đó và chỉ ở đó.
func (s *nhanSuService) dongBoTaiKhoan(
	ctx context.Context,
	nv *domain.NhanVien,
	status string,
	moTaiKhoan bool,
	actor Actor,
) error {
	// Không có tài khoản thì trạng thái làm việc chẳng kéo theo gì cả — phần đông
	// nhân viên tiệm nhỏ rơi vào nhánh này.
	if nv.UserID == nil {
		return nil
	}

	switch {
	case status == domain.NhanSuDaNghi:
		// Hồ sơ của CHÍNH người đang bấm: chặn trước bằng một câu đọc hiểu được,
		// thay vì để UserService trả 403 trống trơn giữa chừng.
		if *nv.UserID == actor.ID {
			return domain.ErrTuDanhDauNghiViec
		}
		_, err := s.users.UpdateStatus(ctx, *nv.UserID, "inactive", actor)

		return err
	case status == domain.NhanSuDangLam && moTaiKhoan:
		_, err := s.users.UpdateStatus(ctx, *nv.UserID, "active", actor)

		return err
	}

	return nil
}

// datCuaVao ghi CỬA VÀO đã tích lên tài khoản.
//
// Lượt gọi không nói gì về cửa (mảng rỗng) thì GIỮ NGUYÊN cột đang có — khác
// hẳn "tích rồi bỏ hết", và khác có chủ ý: đường gọi cũ (mobile, script) không
// biết tới ô này, gán rỗng cho chúng là âm thầm khoá người ta khỏi mọi khu.
func (s *nhanSuService) datCuaVao(ctx context.Context, userID uint, req *dto.NhanSuRequest, actor Actor) error {
	if len(req.Quyen) == 0 {
		return nil
	}

	return s.users.SetCuaVao(ctx, userID, req.Quyen, actor)
}

// taoTaiKhoan cấp tài khoản đăng nhập cho hồ sơ đang khai.
//
// Email lấy từ chính hồ sơ (bảng `users` bắt buộc và đặt UNIQUE lên nó). Không
// có thì từ chối, tuyệt đối không bịa một địa chỉ nội bộ: địa chỉ bịa nằm trong
// sổ y như địa chỉ thật, và ngày nào đó hệ thống gửi thư khôi phục mật khẩu tới.
func (s *nhanSuService) taoTaiKhoan(ctx context.Context, req *dto.NhanSuRequest, actor Actor) (*dto.UserResponse, error) {
	email := strings.TrimSpace(req.Email)
	if email == "" {
		return nil, loiO(map[string]string{
			"email": "Cấp tài khoản đăng nhập thì hồ sơ phải có email — đó là địa chỉ nhận thư khôi phục mật khẩu",
		})
	}

	tk := req.TaiKhoan

	return s.users.Create(ctx, &dto.UserRequest{
		FullName: strings.TrimSpace(req.FullName),
		Username: tk.Username,
		Email:    email,
		Phone:    strings.TrimSpace(req.Phone),
		// Quyền đọc từ req chứ không từ khối tài khoản: cùng một ô trên màn
		// hình dùng cho cả lượt cấp mới lẫn lượt đổi quyền sau này.
		RoleID: req.RoleID,
		// Người vừa tuyển thì tài khoản mở luôn.
		Status:   "active",
		Password: tk.Password,
	}, actor)
}

// chotMa chuẩn hoá và kiểm trùng mã nhân viên. Mã rỗng chỉ được phép ở lượt tạo.
func (s *nhanSuService) chotMa(ctx context.Context, ma string, excludeID uint) (string, error) {
	ma = strings.ToUpper(strings.TrimSpace(ma))
	if ma == "" {
		return s.maTuSinh(ctx)
	}

	trung, err := s.repo.ExistsByCode(ctx, ma, excludeID)
	if err != nil {
		return "", err
	}
	if trung {
		return "", domain.ErrMaNhanVienDaCo
	}

	return ma, nil
}

// maTuSinh đặt mã cho hồ sơ mới: theo quy tắc của cửa hàng nếu đã bật, không thì
// giữ dải NV0001 sẵn có.
func (s *nhanSuService) maTuSinh(ctx context.Context) (string, error) {
	ma, err := s.quyTac.SinhMa(ctx, domain.LoaiNhanVien, 0, func(ma string) (bool, error) {
		return s.repo.ExistsByCode(ctx, ma, 0)
	})
	if err != nil {
		return "", err
	}
	if ma != "" {
		return ma, nil
	}

	return s.maTiepTheo(ctx)
}

// maTiepTheo sinh mã cho hồ sơ mới: NV0001, NV0002…
//
// Lấy mã LỚN NHẤT rồi cộng một, không phải "số hồ sơ + 1": mã của người đã xoá
// vẫn giữ chỗ trong khoá duy nhất nên phép đếm sẽ sinh ra một mã đã bị chiếm.
func (s *nhanSuService) maTiepTheo(ctx context.Context) (string, error) {
	lonNhat, err := s.repo.MaLonNhat(ctx)
	if err != nil {
		return "", err
	}

	so := 0
	if strings.HasPrefix(strings.ToUpper(lonNhat), tienToMaNhanVien) {
		if n, err := strconv.Atoi(strings.TrimPrefix(strings.ToUpper(lonNhat), tienToMaNhanVien)); err == nil {
			so = n
		}
	}

	// Dò tới khi gặp mã còn trống: mã kế tiếp vẫn có thể đã bị đặt tay từ trước.
	for i := so + 1; i < so+200; i++ {
		ma := fmt.Sprintf("%s%04d", tienToMaNhanVien, i)
		trung, err := s.repo.ExistsByCode(ctx, ma, 0)
		if err != nil {
			return "", err
		}
		if !trung {
			return ma, nil
		}
	}

	// Không sinh nổi mã thì bắt người dùng tự đặt, chứ đừng ghi một mã trùng.
	return "", domain.ErrMaNhanVienDaCo
}

// chotChiNhanh xác minh chi nhánh thuộc CHÍNH cửa hàng đang đăng nhập: FindByID
// đi qua bộ lọc tenant nên id của tiệm khác trả về ErrNotFound.
//
// Chi nhánh bắt buộc; cột `shop_id` vẫn cho NULL vì hồ sơ cũ có thể chưa có.
func (s *nhanSuService) chotChiNhanh(ctx context.Context, id uint) (*uint, error) {
	if id == 0 {
		return nil, loiO(map[string]string{"shop_id": "Chưa chọn chi nhánh làm việc"})
	}
	cn, err := s.chiNhanh.FindByID(ctx, id)
	if err != nil {
		return nil, err
	}

	return &cn.ID, nil
}

// kiemEnumNhanSu chặn giá trị ngoài danh sách ENUM. Kiểm ở đây chứ không để
// MySQL đỡ: cột ENUM nhận giá trị lạ sẽ ném lỗi 1265 với câu chữ của riêng nó.
func kiemEnumNhanSu(req *dto.NhanSuRequest) error {
	loi := map[string]string{}
	// Chức danh bỏ trống được (màn hình không còn hỏi), nhưng gửi lên thì phải là
	// giá trị thật — bỏ luôn lượt kiểm là mở đường cho chuỗi rác xuống cột ENUM.
	if req.Position != "" && !domain.ChucDanhHopLe[req.Position] {
		loi["position"] = "Chức danh không hợp lệ"
	}
	for _, ca := range req.WorkShift {
		if !domain.CaLamHopLe[ca] {
			loi["work_shift"] = "Ca làm việc không hợp lệ"

			break
		}
	}
	// "Cả ngày" ĐÃ GỒM sáng và chiều, nên nó không đứng chung với hai ca kia được.
	// Để lọt thì cột chứa "sang,ca_ngay" — một chuỗi không trả lời được câu hỏi
	// đơn giản nhất của bảng chấm công: người này trực mấy buổi?
	if len(req.WorkShift) > 1 {
		for _, ca := range req.WorkShift {
			if ca == domain.CaNgay {
				loi["work_shift"] = "Cả ngày đã gồm sáng và chiều, không chọn kèm ca khác"

				break
			}
		}
	}
	if !domain.TrangThaiNhanSuHopLe[req.Status] {
		loi["status"] = "Trạng thái làm việc không hợp lệ"
	}
	for _, cua := range req.Quyen {
		if !domain.CuaVaoHopLe[cua] {
			loi["quyen"] = "Quyền đăng nhập không hợp lệ"

			break
		}
	}
	if req.ContractType != "" && !domain.LoaiHopDongHopLe[req.ContractType] {
		loi["contract_type"] = "Loại hợp đồng không hợp lệ"
	}
	if req.SalaryType != "" && !domain.HinhThucLuongHopLe[req.SalaryType] {
		loi["salary_type"] = "Hình thức trả lương không hợp lệ"
	}
	if len(loi) > 0 {
		return loiO(loi)
	}

	return nil
}

// dienHoSo đổ dữ liệu request vào entity. Không đụng tới Code, ShopID và UserID
// — ba trường đó có luật riêng ở nơi gọi.
func dienHoSo(nv *domain.NhanVien, req *dto.NhanSuRequest) {
	nv.FullName = strings.TrimSpace(req.FullName)
	nv.Avatar = domain.StringOrNull(strings.TrimSpace(req.Avatar))
	nv.Gender = domain.EnumOrNull(req.Gender)
	nv.BirthDate = parseNgay(req.BirthDate)
	nv.Phone = domain.StringOrNull(strings.TrimSpace(req.Phone))
	nv.Email = domain.StringOrNull(strings.TrimSpace(req.Email))
	nv.IDNumber = domain.StringOrNull(strings.TrimSpace(req.IDNumber))
	nv.Address = domain.StringOrNull(strings.TrimSpace(req.Address))

	// Chức danh bỏ TRỐNG = giữ nguyên giá trị đang có. Màn hình không còn ô này
	// nên mọi lượt sửa đều gửi rỗng; gán thẳng vào thì một lượt sửa tên cũng xoá
	// sạch chức danh của những hồ sơ khai từ trước.
	if req.Position != "" {
		nv.Position = req.Position
	}
	// Hồ sơ mới không có gì để giữ, mà cột là ENUM NOT NULL — ghi chuỗi rỗng
	// xuống là lỗi 1265. Ghi thẳng mặc định của cột cho tường minh.
	if nv.Position == "" {
		nv.Position = domain.ChucDanhBanHang
	}
	nv.HiredOn = parseNgay(req.HiredOn)
	// Cột SET nhận chuỗi ngăn bằng dấu phẩy; không tick ca nào -> NULL (chưa xếp).
	nv.WorkShift = domain.StringOrNull(strings.Join(req.WorkShift, ","))
	nv.ContractType = domain.EnumOrNull(req.ContractType)
	nv.Status = req.Status

	nv.SalaryType = domain.EnumOrNull(req.SalaryType)
	nv.Salary = req.Salary
	nv.Allowance = req.Allowance
	nv.CommissionRate = req.CommissionRate

	nv.Note = domain.StringOrNull(strings.TrimSpace(req.Note))
}

// parseNgay đọc "YYYY-MM-DD"; rỗng hoặc sai -> nil.
func parseNgay(s string) *time.Time {
	s = strings.TrimSpace(s)
	if s == "" {
		return nil
	}
	for _, layout := range []string{dateLayout, time.RFC3339} {
		if t, err := time.Parse(layout, s); err == nil {
			return &t
		}
	}

	return nil
}

// chanTrungTen — hai hồ sơ cùng tên trong một cửa hàng là không cho, dù mã khác.
// Chấm công và bảng lương đều gọi người theo tên; hai dòng trùng tên thì ghi
// nhầm ca của ai cũng không ai thấy.
func (s *nhanSuService) chanTrungTen(ctx context.Context, name string, excludeID uint) error {
	trung, err := s.repo.ExistsByName(ctx, strings.TrimSpace(name), excludeID)
	if err != nil {
		return err
	}
	if trung {
		return domain.ErrNhanVienTrungTen
	}

	return nil
}
