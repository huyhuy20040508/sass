package apitest

import (
	"context"
	"encoding/json"
	"net/http"
	"strconv"
	"testing"

	"gorm.io/gorm"

	"sass-api/internal/domain"
)

// Bộ kiểm MÀN HÌNH TÍNH NĂNG GÓI — nhóm /platform/plans.
//
// Phần "ai được vào" nằm ở dang_nhap_nen_tang_test.go: ranh giới giữa tài khoản
// cửa hàng và tài khoản nền tảng là chuyện của đường đăng nhập, và nó được kiểm
// một lần ở đó thay vì kiểm lại ở mọi nhóm route.
//
// Ở đây kiểm hai thứ còn lại:
//
//  1. VAI TRÒ TRONG KHU ĐIỀU HÀNH. `support` xem được, không sửa được — toàn bộ
//     lý do vai trò đó tách khỏi `operator`.
//
//  2. GHI ĐÚNG CHƯA. Hạn mức là khoá · giá trị nên database không còn giữ hộ
//     kiểu dữ liệu (`value` là VARCHAR — xem migration 0005). Chốt chặn duy nhất
//     còn lại là registry bên service, nên nó phải được kiểm bằng request thật:
//     khoá lạ, giá trị sai kiểu, quá trần, và luật tất-cả-hoặc-không.

// Mã app riêng của bộ kiểm này.
//
// Riêng hẳn khỏi ba gói do migration nạp sẵn ('order': Khởi đầu / Cửa hàng /
// Chuỗi): bài kiểm SỬA hạn mức, mà sửa thẳng vào bảng giá thật của database
// test là để lại một bảng giá bị bóp méo cho mọi lần chạy sau.
const appDieuHanh = "isoapp"

// khuDieuHanh là bản API đã dựng, kèm bảng giá riêng và token của một người
// điều hành với vai trò cho trước.
type khuDieuHanh struct {
	*heThong

	// email + token của người điều hành đang đóng vai trong bài kiểm.
	email string
	token string
	appID uint
	// goiCoHanMuc có sẵn ba khoá; goiTrong chưa có khoá nào — "không có dòng" là
	// một trạng thái có nghĩa, phải kiểm riêng.
	goiCoHanMuc uint
	goiTrong    uint
}

// dungKhuDieuHanh gieo một app + hai dòng bảng giá của riêng bộ kiểm, rồi đăng
// nhập bằng một tài khoản điều hành mang vai trò yêu cầu.
func dungKhuDieuHanh(t *testing.T, vaiTro string) *khuDieuHanh {
	t.Helper()

	h := dungHeThongDieuHanh(t)
	k := &khuDieuHanh{heThong: h}

	k.email = "tinhnanggoi-" + vaiTro + "@nentang.test"
	gieoNguoiDieuHanh(t, h, k.email, vaiTro, matKhauTest)
	k.token = h.tokenNenTang(t, k.email, matKhauTest)

	// Dọn dấu vết của lần chạy trước TRƯỚC khi gieo: bộ kiểm dùng chung database
	// với mọi lần chạy khác, và một lần chạy bị Ctrl-C giữa chừng để lại dữ liệu.
	xoaBangGiaThu(t, h.nenTang)
	t.Cleanup(func() { xoaBangGiaThu(t, h.nenTang) })

	nen := context.Background()
	app := domain.App{Code: appDieuHanh, Name: "App của bài kiểm", Status: domain.AppActive}
	if err := h.nenTang.WithContext(nen).Create(&app).Error; err != nil {
		t.Fatalf("không tạo được app thử: %v", err)
	}
	k.appID = app.ID

	gia := 199000.0
	coHanMuc := domain.Plan{
		AppID: app.ID, Code: "khoi_dau", Name: "Gói có hạn mức",
		BillingCycle: domain.CycleThang, Price: &gia, TrialDays: 14,
		Status: domain.PlanStatusActive,
	}
	trong := domain.Plan{
		AppID: app.ID, Code: "chuoi", Name: "Gói chưa khai gì",
		BillingCycle: domain.CycleThang, TrialDays: 14, Status: domain.PlanStatusActive,
	}
	// Tạo từng dòng một: Create trên một slice dựng từ hai biến sẽ điền id vào
	// các phần tử của slice chứ không vào hai biến đó, và bước gieo tính năng
	// ngay dưới sẽ dùng plan_id = 0.
	for _, p := range []*domain.Plan{&coHanMuc, &trong} {
		if err := h.nenTang.WithContext(nen).Create(p).Error; err != nil {
			t.Fatalf("không tạo được bảng giá thử: %v", err)
		}
	}
	k.goiCoHanMuc, k.goiTrong = coHanMuc.ID, trong.ID

	dat := []domain.PlanFeature{
		{PlanID: coHanMuc.ID, Key: domain.FeatureMaxShops, Value: "1"},
		{PlanID: coHanMuc.ID, Key: domain.FeatureMaxUsers, Value: "2"},
		{PlanID: coHanMuc.ID, Key: domain.FeatureMaxProducts, Value: "500"},
	}
	if err := h.nenTang.WithContext(nen).Create(&dat).Error; err != nil {
		t.Fatalf("không gieo được tính năng gói: %v", err)
	}

	// operator/support phải được GIAO phần mềm này mới nhìn thấy nó (migration
	// 0010): người mới thêm vào sổ bắt đầu từ số không. owner thì không cần, và
	// cũng KHÔNG được có dòng gán nào — họ nhìn mọi phần mềm theo định nghĩa.
	if vaiTro != domain.PlatformRoleOwner {
		giaoApp(t, h, k.email, appDieuHanh)
	}

	return k
}

// TestTinhNangGoi_DocBangGia — màn hình Tính năng gói đọc được đúng thứ nó cần.
func TestTinhNangGoi_DocBangGia(t *testing.T) {
	k := dungKhuDieuHanh(t, domain.PlatformRoleOperator)

	res := k.goi(t, k.token, http.MethodGet, "/api/v1/platform/plans?app="+appDieuHanh, nil)
	if res.ma != http.StatusOK {
		t.Fatalf("đọc bảng giá hỏng: %d\n%s", res.ma, catBot(res.than))
	}

	var body struct {
		Data struct {
			Plans []struct {
				ID       uint              `json:"id"`
				AppCode  string            `json:"app_code"`
				Name     string            `json:"name"`
				Features map[string]string `json:"features"`
			} `json:"plans"`
			Fields []struct {
				Key         string `json:"key"`
				Type        string `json:"type"`
				KhongCoDong string `json:"khong_co_dong"`
			} `json:"fields"`
		} `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &body); err != nil {
		t.Fatalf("không đọc được phản hồi: %v — %s", err, catBot(res.than))
	}

	if len(body.Data.Plans) != 2 {
		t.Fatalf("lọc theo app %q phải ra đúng 2 dòng bảng giá, nhận %d\n%s",
			appDieuHanh, len(body.Data.Plans), catBot(res.than))
	}
	for _, p := range body.Data.Plans {
		if p.AppCode != appDieuHanh {
			t.Fatalf("lọc theo app mà lọt dòng của app %q", p.AppCode)
		}
		switch p.ID {
		case k.goiCoHanMuc:
			if p.Features[domain.FeatureMaxUsers] != "2" || p.Features[domain.FeatureMaxProducts] != "500" {
				t.Fatalf("gói có hạn mức trả về sai: %v", p.Features)
			}
		case k.goiTrong:
			// Gói chưa khai gì phải ra map RỖNG, không phải null và cũng không phải
			// một bộ giá trị mặc định bịa ra: "không có dòng" nghĩa là bảng giá
			// không quy định, và màn hình phải nói đúng như vậy.
			if len(p.Features) != 0 {
				t.Fatalf("gói chưa khai gì mà có sẵn tính năng: %v", p.Features)
			}
		}
	}

	// Siêu dữ liệu: màn hình dựng ô nhập từ đây nên thiếu nó là màn hình trống.
	if len(body.Data.Fields) == 0 {
		t.Fatalf("phản hồi không có `fields` — màn hình không dựng nổi ô nhập nào\n%s", catBot(res.than))
	}
	var thayOwnDomain bool
	for _, f := range body.Data.Fields {
		if f.Key == domain.FeatureOwnDomain {
			thayOwnDomain = true
			if f.KhongCoDong != "0" {
				t.Fatalf("khoá bật/tắt %q phải mặc định là TẮT khi không có dòng, nhận %q",
					f.Key, f.KhongCoDong)
			}
		}
	}
	if !thayOwnDomain {
		t.Fatalf("thiếu khoá %q trong `fields`", domain.FeatureOwnDomain)
	}
}

// TestTinhNangGoi_SuaVaXoa — lưu một lượt sửa đúng như màn hình gửi lên.
func TestTinhNangGoi_SuaVaXoa(t *testing.T) {
	k := dungKhuDieuHanh(t, domain.PlatformRoleOperator)

	res := k.goi(t, k.token, http.MethodPut, duongTinhNang(k.goiCoHanMuc), map[string]any{
		"items": map[string]string{
			domain.FeatureMaxUsers: "25",
			// Rỗng = XOÁ dòng: "bảng giá không quy định", khác hẳn "0".
			domain.FeatureMaxProducts: "",
			domain.FeatureOwnDomain:   "1",
		},
	})
	if res.ma != http.StatusOK {
		t.Fatalf("lưu tính năng gói hỏng: %d\n%s", res.ma, catBot(res.than))
	}

	sau := k.docTinhNang(t, k.goiCoHanMuc)
	if sau[domain.FeatureMaxUsers] != "25" {
		t.Fatalf("max_users chưa được ghi: %v", sau)
	}
	if sau[domain.FeatureOwnDomain] != "1" {
		t.Fatalf("own_domain chưa được bật: %v", sau)
	}
	if _, con := sau[domain.FeatureMaxProducts]; con {
		t.Fatalf("gửi giá trị rỗng mà dòng max_products vẫn còn: %v", sau)
	}
	// Khoá không gửi lên phải giữ nguyên.
	if sau[domain.FeatureMaxShops] != "1" {
		t.Fatalf("khoá không gửi lên bị đổi: %v", sau)
	}

	// vo_han là giá trị hợp lệ của hạn mức, và nó phải ĐI XUỐNG database nguyên
	// văn — không bị quy đổi thành 0 hay thành một con số trần nào đó.
	if res := k.goi(t, k.token, http.MethodPut, duongTinhNang(k.goiTrong), map[string]any{
		"items": map[string]string{domain.FeatureMaxProducts: domain.VoHan},
	}); res.ma != http.StatusOK {
		t.Fatalf("lưu %q hỏng: %d\n%s", domain.VoHan, res.ma, catBot(res.than))
	}
	if v := k.docTinhNang(t, k.goiTrong)[domain.FeatureMaxProducts]; v != domain.VoHan {
		t.Fatalf("gói không giới hạn sản phẩm lưu ra %q", v)
	}
}

// TestTinhNangGoi_TuChoiGiaTriSai — registry là chốt chặn DUY NHẤT còn lại từ
// khi `value` thành VARCHAR, nên nó phải được kiểm bằng request thật.
func TestTinhNangGoi_TuChoiGiaTriSai(t *testing.T) {
	k := dungKhuDieuHanh(t, domain.PlatformRoleOwner)

	xau := []struct {
		ten   string
		items map[string]string
	}{
		{"khoá không có trong registry", map[string]string{"max_don_hang": "100"}},
		{"chữ trong ô số", map[string]string{domain.FeatureMaxUsers: "mười"}},
		{"số âm", map[string]string{domain.FeatureMaxUsers: "-1"}},
		{"số thập phân", map[string]string{domain.FeatureMaxUsers: "2.5"}},
		{"vượt trần", map[string]string{domain.FeatureMaxShops: "999999"}},
		{"vo_han cho khoá bật/tắt", map[string]string{domain.FeatureOwnDomain: domain.VoHan}},
		{"giá trị lạ cho khoá bật/tắt", map[string]string{domain.FeatureOwnDomain: "co"}},
	}
	for _, tr := range xau {
		res := k.goi(t, k.token, http.MethodPut, duongTinhNang(k.goiCoHanMuc),
			map[string]any{"items": tr.items})
		if res.ma != http.StatusUnprocessableEntity {
			t.Fatalf("%s phải bị từ chối 422, nhận %d\n%s", tr.ten, res.ma, catBot(res.than))
		}
	}

	// TẤT-CẢ-HOẶC-KHÔNG: một khoá sai thì khoá đúng đi cùng cũng không được ghi.
	// Hạn mức nửa vời nguy hiểm hơn hạn mức sai hẳn — người sửa thấy báo lỗi và
	// tưởng chưa có gì được lưu.
	res := k.goi(t, k.token, http.MethodPut, duongTinhNang(k.goiCoHanMuc), map[string]any{
		"items": map[string]string{
			domain.FeatureMaxUsers: "7",
			"khoa_la":              "1",
		},
	})
	if res.ma != http.StatusUnprocessableEntity {
		t.Fatalf("payload có khoá lạ phải 422, nhận %d\n%s", res.ma, catBot(res.than))
	}
	if v := k.docTinhNang(t, k.goiCoHanMuc)[domain.FeatureMaxUsers]; v != "2" {
		t.Fatalf("yêu cầu bị từ chối mà max_users vẫn bị ghi: %q", v)
	}
}

// TestTinhNangGoi_HoTroChiDoc — `support` xem được, không sửa được.
//
// Đó là toàn bộ lý do vai trò này tách khỏi `operator`: người trực hỗ trợ cần
// nhìn thấy gói để trả lời điện thoại, không cần đổi bảng giá của cả nền tảng
// giữa lúc đang nghe máy.
func TestTinhNangGoi_HoTroChiDoc(t *testing.T) {
	k := dungKhuDieuHanh(t, domain.PlatformRoleSupport)

	if res := k.goi(t, k.token, http.MethodGet, "/api/v1/platform/plans", nil); res.ma != http.StatusOK {
		t.Fatalf("vai trò support phải XEM được bảng giá, nhận %d\n%s", res.ma, catBot(res.than))
	}

	res := k.goi(t, k.token, http.MethodPut, duongTinhNang(k.goiCoHanMuc), map[string]any{
		"items": map[string]string{domain.FeatureMaxUsers: "99"},
	})
	if res.ma != http.StatusForbidden {
		t.Fatalf("vai trò support phải bị chặn khi SỬA, nhận %d\n%s", res.ma, catBot(res.than))
	}
	if v := k.docTinhNang(t, k.goiCoHanMuc)[domain.FeatureMaxUsers]; v != "2" {
		t.Fatalf("support bị chặn mà dữ liệu vẫn đổi: %q", v)
	}
}

// TestTinhNangGoi_GoiKhongTonTai — id lạ là 404, không phải một màn hình trắng.
func TestTinhNangGoi_GoiKhongTonTai(t *testing.T) {
	k := dungKhuDieuHanh(t, domain.PlatformRoleOwner)

	const idLa = 987654321
	if res := k.goi(t, k.token, http.MethodGet, duongTinhNang(idLa), nil); res.ma != http.StatusNotFound {
		t.Fatalf("đọc gói không tồn tại phải 404, nhận %d\n%s", res.ma, catBot(res.than))
	}
	res := k.goi(t, k.token, http.MethodPut, duongTinhNang(idLa), map[string]any{
		"items": map[string]string{domain.FeatureMaxUsers: "3"},
	})
	if res.ma != http.StatusNotFound {
		t.Fatalf("sửa gói không tồn tại phải 404, nhận %d\n%s", res.ma, catBot(res.than))
	}
}

// ---------- phụ trợ ----------

func duongTinhNang(planID uint) string {
	return "/api/v1/platform/plans/" + strconv.FormatUint(uint64(planID), 10) + "/features"
}

// docTinhNang đọc lại tính năng của một gói QUA API, không qua database.
//
// Đọc qua API vì đó là thứ màn hình nhìn thấy: một lượt ghi thành công mà đường
// đọc trả về thứ khác thì bài kiểm phải đỏ.
func (k *khuDieuHanh) docTinhNang(t *testing.T, planID uint) map[string]string {
	t.Helper()

	res := k.goi(t, k.token, http.MethodGet, duongTinhNang(planID), nil)
	if res.ma != http.StatusOK {
		t.Fatalf("đọc tính năng gói %d hỏng: %d\n%s", planID, res.ma, catBot(res.than))
	}

	var body struct {
		Data struct {
			Plan struct {
				Features map[string]string `json:"features"`
			} `json:"plan"`
		} `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &body); err != nil {
		t.Fatalf("không đọc được phản hồi: %v — %s", err, catBot(res.than))
	}

	return body.Data.Plan.Features
}

// xoaBangGiaThu dọn app + bảng giá riêng của bộ kiểm.
//
// plan_features đi theo plans bằng ON DELETE CASCADE nên không phải xoá tay —
// và bài kiểm này cũng là chỗ chứng minh ràng buộc đó có thật.
func xoaBangGiaThu(t *testing.T, nenTang *gorm.DB) {
	t.Helper()
	if nenTang == nil {
		return
	}

	nen := context.Background()
	for _, cau := range []string{
		"DELETE p FROM plans p JOIN apps a ON a.id = p.app_id WHERE a.code = ?",
		"DELETE FROM apps WHERE code = ?",
	} {
		if err := nenTang.WithContext(nen).Exec(cau, appDieuHanh).Error; err != nil {
			t.Fatalf("không dọn được bảng giá thử (%s): %v", cau, err)
		}
	}
}

// TestTinhNangGoi_ChiThayPhanMemDuocGiao — phân công phần mềm (migration 0010).
//
// operator ở đây được giao ĐÚNG một phần mềm khác với phần mềm của bảng giá
// thử, nên với họ bảng giá đó phải như không tồn tại: không hiện trong danh
// sách, hỏi đích danh thì 403, và sửa thì 403 — kiểm cả ba vì mỗi cái đi qua
// một nhánh code khác nhau.
func TestTinhNangGoi_ChiThayPhanMemDuocGiao(t *testing.T) {
	k := dungKhuDieuHanh(t, domain.PlatformRoleOperator)

	// dungKhuDieuHanh vừa giao app của bộ kiểm cho người này. Thu lại để dựng
	// đúng tình huống "có tên trong sổ, có vai trò sửa được, nhưng KHÔNG phụ
	// trách phần mềm này".
	thuApp(t, k.heThong, k.email, appDieuHanh)

	res := k.goi(t, k.token, http.MethodGet, "/api/v1/platform/plans", nil)
	if res.ma != http.StatusOK {
		t.Fatalf("đọc bảng giá hỏng: %d\n%s", res.ma, catBot(res.than))
	}
	if chuaDauVet(res.than, appDieuHanh) {
		t.Fatalf("danh sách vẫn lọt bảng giá của phần mềm KHÔNG được giao:\n%s", catBot(res.than))
	}

	// Hỏi đích danh: nói thẳng là không phụ trách, đừng trả danh sách rỗng —
	// rỗng đọc thành "phần mềm này chưa có gói nào" và người ta sẽ đi tạo gói
	// mới cho một sản phẩm không phải của mình.
	if res := k.goi(t, k.token, http.MethodGet, "/api/v1/platform/plans?app="+appDieuHanh, nil); res.ma != http.StatusForbidden {
		t.Fatalf("hỏi đích danh phần mềm không được giao phải 403, nhận %d\n%s", res.ma, catBot(res.than))
	}
	if res := k.goi(t, k.token, http.MethodGet, duongTinhNang(k.goiCoHanMuc), nil); res.ma != http.StatusForbidden {
		t.Fatalf("đọc gói của phần mềm không được giao phải 403, nhận %d\n%s", res.ma, catBot(res.than))
	}

	// Đường GHI phải bị chặn TRƯỚC khi ghi bất cứ thứ gì.
	res = k.goi(t, k.token, http.MethodPut, duongTinhNang(k.goiCoHanMuc), map[string]any{
		"items": map[string]string{domain.FeatureMaxUsers: "99"},
	})
	if res.ma != http.StatusForbidden {
		t.Fatalf("sửa gói của phần mềm không được giao phải 403, nhận %d\n%s", res.ma, catBot(res.than))
	}

	// Giao lại thì thấy ngay, không phải đăng nhập lại: quyền đọc từ sổ ở mỗi
	// request chứ không lấy từ token.
	giaoApp(t, k.heThong, k.email, appDieuHanh)
	if res := k.goi(t, k.token, http.MethodGet, "/api/v1/platform/plans?app="+appDieuHanh, nil); res.ma != http.StatusOK {
		t.Fatalf("giao lại rồi mà token cũ vẫn bị chặn (%d) — quyền đang lấy từ token thay vì từ sổ\n%s",
			res.ma, catBot(res.than))
	}
}

// TestTinhNangGoi_OwnerThayMoiPhanMem — owner không có dòng giao việc nào và
// vẫn nhìn thấy mọi phần mềm, kể cả app vừa thêm vào danh mục.
//
// Đây là nhánh đi tắt trong repo (không hỏi bảng gán). Không kiểm thì một ngày
// nào đó ai đó "dọn dẹp" nhánh đó và chủ nền tảng thành người duy nhất không
// vào được sản phẩm nào.
func TestTinhNangGoi_OwnerThayMoiPhanMem(t *testing.T) {
	k := dungKhuDieuHanh(t, domain.PlatformRoleOwner)

	if soDong := demGiaoApp(t, k.heThong, k.email); soDong != 0 {
		t.Fatalf("owner không được có dòng giao việc nào, đếm được %d", soDong)
	}

	res := k.goi(t, k.token, http.MethodGet, "/api/v1/platform/plans?app="+appDieuHanh, nil)
	if res.ma != http.StatusOK {
		t.Fatalf("owner phải xem được mọi phần mềm, nhận %d\n%s", res.ma, catBot(res.than))
	}
}

// giaoApp / thuApp / demGiaoApp thao tác thẳng trên bảng gán.
//
// Ghi bằng SQL chứ không gọi `cmd/nguoi-dieu-hanh`: bài kiểm này kiểm API, còn
// lệnh kia là một tiến trình khác với .env khác.
func giaoApp(t *testing.T, h *heThong, email, maApp string) {
	t.Helper()

	err := h.nenTang.WithContext(context.Background()).Exec(
		`INSERT INTO platform_user_apps (platform_user_id, app_id, created_at)
		 SELECT u.id, a.id, NOW(3) FROM platform_users u, apps a
		  WHERE u.email = ? AND a.code = ?
		 ON DUPLICATE KEY UPDATE platform_user_apps.app_id = platform_user_apps.app_id`,
		email, maApp).Error
	if err != nil {
		t.Fatalf("không giao được app %s cho %s: %v", maApp, email, err)
	}
}

func thuApp(t *testing.T, h *heThong, email, maApp string) {
	t.Helper()

	err := h.nenTang.WithContext(context.Background()).Exec(
		`DELETE ua FROM platform_user_apps ua
		   JOIN platform_users u ON u.id = ua.platform_user_id
		   JOIN apps a ON a.id = ua.app_id
		  WHERE u.email = ? AND a.code = ?`, email, maApp).Error
	if err != nil {
		t.Fatalf("không thu được app %s của %s: %v", maApp, email, err)
	}
}

func demGiaoApp(t *testing.T, h *heThong, email string) int64 {
	t.Helper()

	var so int64
	err := h.nenTang.WithContext(context.Background()).Raw(
		`SELECT COUNT(*) FROM platform_user_apps ua
		   JOIN platform_users u ON u.id = ua.platform_user_id
		  WHERE u.email = ?`, email).Scan(&so).Error
	if err != nil {
		t.Fatalf("không đếm được phân công của %s: %v", email, err)
	}

	return so
}

// TestDanhMucApp_ChiThayPhanMemDuocGiao — /platform/apps là thứ khu điều hành
// đọc đầu tiên để dựng bộ chọn phần mềm, nên nó phải lọc theo cùng một phân
// công với bảng giá. Lọc lệch nhau thì người ta chọn được một phần mềm rồi mọi
// màn hình phía sau trả 403.
func TestDanhMucApp_ChiThayPhanMemDuocGiao(t *testing.T) {
	k := dungKhuDieuHanh(t, domain.PlatformRoleOperator)

	// Được giao app của bộ kiểm (dungKhuDieuHanh vừa giao) → phải thấy nó, kèm
	// số gói đang bán đúng bằng hai dòng bảng giá vừa gieo.
	apps := k.docDanhMucApp(t)
	if apps[appDieuHanh] == nil {
		t.Fatalf("không thấy phần mềm được giao trong danh mục: %v", apps)
	}
	if apps[appDieuHanh].SoGoiDangBan != 2 {
		t.Fatalf("số gói đang bán của %s phải là 2, nhận %d", appDieuHanh, apps[appDieuHanh].SoGoiDangBan)
	}
	// App 'order' do migration nạp sẵn KHÔNG được giao cho người này.
	if apps[domain.AppOrder] != nil {
		t.Fatalf("lọt phần mềm %q chưa được giao vào danh mục", domain.AppOrder)
	}

	// Thu lại thì danh mục rỗng — và rỗng là câu trả lời ĐÚNG, không phải lỗi.
	thuApp(t, k.heThong, k.email, appDieuHanh)
	if con := k.docDanhMucApp(t); len(con) != 0 {
		t.Fatalf("thu hết phần mềm rồi mà danh mục vẫn còn: %v", con)
	}
}

// TestDanhMucApp_OwnerThayCaAppChuaCoGoi — owner thấy mọi phần mềm, kể cả cái
// chưa dựng bảng giá.
//
// Đây là lý do repo dùng LEFT JOIN: app vừa khai mà chưa có gói nào là dòng
// người điều hành cần thấy NHẤT — nó chưa bán được cho ai.
func TestDanhMucApp_OwnerThayCaAppChuaCoGoi(t *testing.T) {
	k := dungKhuDieuHanh(t, domain.PlatformRoleOwner)
	xoaBangGiaThu(t, k.nenTang) // xoá hai dòng bảng giá, giữ lại app

	if err := k.nenTang.WithContext(context.Background()).Exec(
		`INSERT INTO apps (code, name, status, created_at, updated_at)
		 VALUES (?, 'App chưa có bảng giá', 'planned', NOW(3), NOW(3))`, appDieuHanh).Error; err != nil {
		t.Fatalf("không gieo được app trống: %v", err)
	}

	apps := k.docDanhMucApp(t)
	trong := apps[appDieuHanh]
	if trong == nil {
		t.Fatalf("app chưa có bảng giá bị rơi khỏi danh mục: %v", apps)
	}
	if trong.SoGoiDangBan != 0 {
		t.Fatalf("app chưa có bảng giá phải đếm 0 gói, nhận %d", trong.SoGoiDangBan)
	}
	if trong.Status != domain.AppPlanned {
		t.Fatalf("app 'planned' phải hiện đúng trạng thái, nhận %q", trong.Status)
	}
}

// docDanhMucApp đọc /platform/apps thành map theo mã app.
func (k *khuDieuHanh) docDanhMucApp(t *testing.T) map[string]*struct {
	Code         string `json:"code"`
	Status       string `json:"status"`
	SoGoiDangBan int    `json:"so_goi_dang_ban"`
} {
	t.Helper()

	res := k.goi(t, k.token, http.MethodGet, "/api/v1/platform/apps", nil)
	if res.ma != http.StatusOK {
		t.Fatalf("đọc danh mục phần mềm hỏng: %d\n%s", res.ma, catBot(res.than))
	}

	var body struct {
		Data struct {
			Apps []struct {
				Code         string `json:"code"`
				Status       string `json:"status"`
				SoGoiDangBan int    `json:"so_goi_dang_ban"`
			} `json:"apps"`
		} `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &body); err != nil {
		t.Fatalf("không đọc được phản hồi: %v — %s", err, catBot(res.than))
	}

	out := map[string]*struct {
		Code         string `json:"code"`
		Status       string `json:"status"`
		SoGoiDangBan int    `json:"so_goi_dang_ban"`
	}{}
	for i := range body.Data.Apps {
		out[body.Data.Apps[i].Code] = &body.Data.Apps[i]
	}

	return out
}
