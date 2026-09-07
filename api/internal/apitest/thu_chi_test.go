package apitest

import (
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"strings"
	"testing"
	"time"

	"golang.org/x/crypto/bcrypt"

	"sass-api/internal/domain"
	"sass-api/internal/tenant"
)

// Bài kiểm QUẢN LÝ THU CHI qua API thật và MySQL thật.
//
// Sáu chỗ bản cũ v2 làm hỏng, không chỗ nào lộ ra ở tầng service với sổ giả:
//
//   - quỹ đầu kỳ nhân đôi khi bỏ trống ngày bắt đầu;
//   - lọc riêng "phiếu thu" ra bảng rỗng (mã của nó là số 0);
//   - ô "Trả hàng" không bao giờ thấy phiếu trả nhà cung cấp;
//   - ba lớp khoá sửa/xoá tính ở giao diện, máy chủ không chặn lại;
//   - hai cửa hàng nhìn thấy sổ của nhau;
//   - phiếu tiền mặt không vào két nên đóng ca đếm lệch.

// phieuTC là một dòng trên sổ thu chi.
type phieuTC struct {
	ID           uint    `json:"id"`
	Code         string  `json:"code"`
	Type         uint8   `json:"type"`
	Amount       float64 `json:"amount"`
	PayerType    string  `json:"payer_type"`
	PayerName    string  `json:"payer_name"`
	Source       string  `json:"source"`
	SourceCode   string  `json:"source_code"`
	Note         string  `json:"note"`
	Locked       bool    `json:"locked"`
	LockedReason string  `json:"locked_reason"`
	ShiftID      *uint   `json:"shift_id"`
}

// quyTC là bốn ô thống kê, nằm chung `meta` với phân trang.
type quyTC struct {
	Total      int64   `json:"total"`
	DauKy      float64 `json:"begin_balance"`
	TongThu    float64 `json:"total_income"`
	TongChi    float64 `json:"total_expense"`
	CuoiKy     float64 `json:"end_balance"`
	TotalPages int     `json:"total_pages"`
}

// lapPhieuTC gọi đường tạo phiếu và trả về mã HTTP kèm phiếu vừa lập.
func lapPhieuTC(t *testing.T, h *heThong, token string, than map[string]any) (int, phieuTC) {
	t.Helper()

	res := h.goi(t, token, http.MethodPost, "/api/v1/admin/thu-chi", than)

	var body struct {
		Data phieuTC `json:"data"`
	}
	_ = json.Unmarshal([]byte(res.than), &body)

	return res.ma, body.Data
}

// thuTienMat là payload gọn nhất: một phiếu thu tiền mặt.
func thuTienMat(soTien float64) map[string]any {
	return map[string]any{"type": 0, "amount": soTien, "payment_method": "cash"}
}

// chiTienMat là phiếu chi tiền mặt.
func chiTienMat(soTien float64) map[string]any {
	return map[string]any{"type": 1, "amount": soTien, "payment_method": "cash"}
}

// docThuChi đọc sổ. query là phần sau dấu ? (có thể rỗng).
func docThuChi(t *testing.T, h *heThong, token, query string) ([]phieuTC, quyTC) {
	t.Helper()

	duong := "/api/v1/admin/thu-chi"
	if query != "" {
		duong += "?" + query
	}

	res := h.goi(t, token, http.MethodGet, duong, nil)
	if res.ma != http.StatusOK {
		t.Fatalf("đọc sổ thu chi phải trả 200, nhận %d\n%s", res.ma, catBot(res.than))
	}

	var body struct {
		Data []phieuTC `json:"data"`
		Meta quyTC     `json:"meta"`
	}
	if err := json.Unmarshal([]byte(res.than), &body); err != nil {
		t.Fatalf("không đọc được phản hồi: %v\n%s", err, catBot(res.than))
	}

	return body.Data, body.Meta
}

// TestThuChi_LapRoiDocLai — phiếu lập tay mang nguồn `manual`, và người lập tự
// sửa/xoá được (không bị lớp khoá nào chạm tới).
func TestThuChi_LapRoiDocLai(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	ma, p := lapPhieuTC(t, h, a.token, map[string]any{
		"type": 0, "amount": 250000, "payment_method": "cash", "note": "Thu tiền lẻ",
	})
	if ma != http.StatusCreated {
		t.Fatalf("lập phiếu thu phải trả 201, nhận %d", ma)
	}
	if p.Source != "manual" {
		t.Fatalf("phiếu lập tay phải mang nguồn manual, nhận %q", p.Source)
	}
	if p.Locked {
		t.Fatalf("phiếu của chính mình, ca đang mở — không được khoá. Lý do đang trả: %q", p.LockedReason)
	}

	ds, _ := docThuChi(t, h, a.token, "")
	if len(ds) != 1 || ds[0].Amount != 250000 || ds[0].Note != "Thu tiền lẻ" {
		t.Fatalf("đọc lại không khớp phiếu vừa lập: %+v", ds)
	}
}

// TestThuChi_KhongNhanNguonTuTrinhDuyet — một lượt gọi KHÔNG tự khai mình là
// phiếu tự sinh của đơn hàng được.
//
// Nếu nhận, người dùng gửi `source: "order"` một lần là phiếu ấy khoá cứng
// vĩnh viễn — chính họ hết sửa, hết xoá, mà chẳng có chứng từ gốc nào thật.
func TestThuChi_KhongNhanNguonTuTrinhDuyet(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	_, p := lapPhieuTC(t, h, a.token, map[string]any{
		"type": 0, "amount": 100000, "payment_method": "cash",
		"source": "order", "source_id": 999, "code": "TU-DAT", "shop_id": 999999,
	})
	if p.Source != "manual" {
		t.Fatalf("nguồn phải do máy chủ đặt là manual, nhận %q", p.Source)
	}
	if p.Code == "TU-DAT" {
		t.Fatal("mã phiếu phải do quy tắc đánh số sinh, không nhận từ payload")
	}
}

// TestThuChi_LocRiengPhieuThu — chỗ dễ chép sai nhất: mã của "phiếu thu" là số 0,
// mà mọi hàm gạn id đều bỏ số 0 đi vì id 0 vô nghĩa. Lọc riêng vế thu mà ra bảng
// rỗng là đúng cái bẫy ấy.
func TestThuChi_LocRiengPhieuThu(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	lapPhieuTC(t, h, a.token, thuTienMat(100000))
	lapPhieuTC(t, h, a.token, chiTienMat(30000))

	ds, _ := docThuChi(t, h, a.token, "type=0")
	if len(ds) != 1 || ds[0].Type != 0 {
		t.Fatalf("lọc type=0 phải trả về đúng phiếu thu, nhận %+v", ds)
	}

	ds, _ = docThuChi(t, h, a.token, "type=1")
	if len(ds) != 1 || ds[0].Type != 1 {
		t.Fatalf("lọc type=1 phải trả về đúng phiếu chi, nhận %+v", ds)
	}

	ds, _ = docThuChi(t, h, a.token, "type=0,1")
	if len(ds) != 2 {
		t.Fatalf("lọc cả hai vế phải trả về 2 phiếu, nhận %d", len(ds))
	}
}

// TestThuChi_QuyDauKyKhongNhanDoi — chỗ bản v2 sai.
//
// v2 dùng lại nguyên câu truy vấn CHƯA gắn điều kiện ngày để cộng đầu kỳ, nên
// khi bỏ trống ô ngày thì đầu kỳ ôm trọn lịch sử rồi cuối kỳ cộng thêm lần nữa.
// Không có mốc bắt đầu thì đầu kỳ phải bằng 0.
func TestThuChi_QuyDauKyKhongNhanDoi(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	lapPhieuTC(t, h, a.token, thuTienMat(1000000))
	lapPhieuTC(t, h, a.token, chiTienMat(250000))

	_, quy := docThuChi(t, h, a.token, "")
	if quy.DauKy != 0 {
		t.Fatalf("không khai ngày bắt đầu thì quỹ đầu kỳ phải là 0, nhận %v", quy.DauKy)
	}
	if quy.TongThu != 1000000 || quy.TongChi != 250000 {
		t.Fatalf("tổng thu/chi sai: thu %v, chi %v", quy.TongThu, quy.TongChi)
	}
	if quy.CuoiKy != 750000 {
		t.Fatalf("quỹ cuối kỳ phải là 750000 (0 + 1000000 − 250000), nhận %v", quy.CuoiKy)
	}
}

// TestThuChi_QuyDauKyCongTuKyTruoc — có mốc bắt đầu thì đầu kỳ gom mọi phiếu
// TRƯỚC mốc ấy, và bốn ô phải cân: cuối kỳ = đầu kỳ + thu − chi.
func TestThuChi_QuyDauKyCongTuKyTruoc(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	lapPhieuTC(t, h, a.token, thuTienMat(500000))
	lapPhieuTC(t, h, a.token, chiTienMat(200000))

	// Lọc từ NGÀY MAI: mọi phiếu vừa lập rơi vào "trước kỳ".
	mai := time.Now().AddDate(0, 0, 1).Format("2006-01-02")
	ds, quy := docThuChi(t, h, a.token, "from_date="+mai)

	if len(ds) != 0 {
		t.Fatalf("khoảng ngày mai trở đi chưa có phiếu nào, nhận %d", len(ds))
	}
	if quy.DauKy != 300000 {
		t.Fatalf("quỹ đầu kỳ phải gom phiếu trước mốc (500000 − 200000 = 300000), nhận %v", quy.DauKy)
	}
	if quy.CuoiKy != quy.DauKy+quy.TongThu-quy.TongChi {
		t.Fatalf("bốn ô quỹ không cân: %+v", quy)
	}
}

// TestThuChi_HaiCuaHangKhongThayNhau — bộ lọc cửa hàng nằm dưới GORM nên không
// câu truy vấn nào đi vòng được. Bản cũ đóng dấu chi nhánh lúc GHI nhưng dòng lọc
// lúc ĐỌC bị comment lại.
func TestThuChi_HaiCuaHangKhongThayNhau(t *testing.T) {
	h := dungHeThong(t)
	a, b := haiCuaHang(t, h)

	lapPhieuTC(t, h, a.token, thuTienMat(111000))

	ds, quy := docThuChi(t, h, b.token, "")
	if len(ds) != 0 {
		t.Fatalf("cửa hàng B không được thấy phiếu của A, nhận %+v", ds)
	}
	if quy.TongThu != 0 {
		t.Fatalf("quỹ của B phải trống, nhận tổng thu %v", quy.TongThu)
	}
}

// TestThuChi_PhieuCuaNguoiKhac — lớp khoá thứ ba.
//
// Mặc định ai lập thì người ấy sửa, người ấy xoá. Chỉ tài khoản được giao quyền
// lẻ `thu-chi.sua-moi-nguoi` mới vượt được.
//
// Bài này KHÔNG dùng tài khoản nhân viên: nhóm route `manage` vốn chỉ cho vai
// admin đi qua, nên cảnh thật của lớp khoá này là HAI NGƯỜI QUẢN LÝ của cùng
// một cửa hàng — và đó cũng là lý do lớp khoá đọc theo quyền chứ không theo vai.
func TestThuChi_PhieuCuaNguoiKhac(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	// Quản lý thứ hai: cùng vai admin, nhưng CHỈ có bốn việc chuẩn của sổ thu
	// chi — không có quyền lẻ sửa phiếu người khác.
	hai := themQuanLyThuHai(t, h, a, "quantri2", []string{
		"thu-chi.xem", "thu-chi.them", "thu-chi.sua", "thu-chi.xoa",
	})

	// Người thứ nhất lập phiếu.
	_, p := lapPhieuTC(t, h, a.token, thuTienMat(120000))

	ds, _ := docThuChi(t, h, hai, "")
	if len(ds) != 1 {
		t.Fatalf("quản lý thứ hai vẫn ĐỌC được phiếu của người khác, nhận %d dòng", len(ds))
	}
	if !ds[0].Locked {
		t.Fatal("phiếu của người khác phải bật cờ locked — không thì giao diện bày nút rồi bấm vào bị từ chối")
	}

	duong := fmt.Sprintf("/api/v1/admin/thu-chi/%d", p.ID)
	if res := h.goi(t, hai, http.MethodDelete, duong, nil); res.ma != http.StatusConflict {
		t.Fatalf("xoá phiếu của người khác phải bị chặn 409, nhận %d — %s", res.ma, catBot(res.than))
	}

	// Giao thêm quyền lẻ thì mở ra NGAY với chính token cũ.
	ctx := tenant.WithID(context.Background(), a.id)
	if err := h.db.WithContext(ctx).Create(&domain.QuyenRieng{
		UserID: idTaiKhoan(t, h, a, "quantri2"), Permission: domain.QuyenSuaThuChiMoiNguoi,
	}).Error; err != nil {
		t.Fatalf("không giao được quyền lẻ: %v", err)
	}

	ds, _ = docThuChi(t, h, hai, "")
	if len(ds) != 1 || ds[0].Locked {
		t.Fatalf("có quyền lẻ rồi thì cờ locked phải tắt, nhận %+v", ds)
	}
	if res := h.goi(t, hai, http.MethodDelete, duong, nil); res.ma != http.StatusOK {
		t.Fatalf("có quyền lẻ thì phải xoá được, nhận %d — %s", res.ma, catBot(res.than))
	}
}

// themQuanLyThuHai gieo thêm một tài khoản VAI ADMIN cho cửa hàng rồi đăng nhập,
// giao đúng danh sách quyền truyền vào (không bật cờ toàn quyền).
func themQuanLyThuHai(t *testing.T, h *heThong, c *cuaHang, username string, quyen []string) string {
	t.Helper()

	ctx := tenant.WithID(context.Background(), c.id)
	bam, err := bcrypt.GenerateFromPassword([]byte(matKhauTest), bcrypt.MinCost)
	if err != nil {
		t.Fatalf("không băm được mật khẩu: %v", err)
	}
	now := time.Now()

	u := &domain.User{
		Username: domain.StringOrNull(username), RoleID: domain.AdminRoleID,
		FullName: "Quản lý 2 " + c.vet, Email: username + "@" + c.vet + ".test",
		PasswordHash: string(bam), Status: "active", EmailVerifiedAt: &now,
	}
	if err := h.db.WithContext(ctx).Create(u).Error; err != nil {
		t.Fatalf("không gieo được tài khoản quản lý thứ hai: %v", err)
	}
	for _, q := range quyen {
		if err := h.db.WithContext(ctx).Create(&domain.QuyenRieng{
			UserID: u.ID, Permission: q,
		}).Error; err != nil {
			t.Fatalf("không giao được quyền %s: %v", q, err)
		}
	}

	res := h.goi(t, "", http.MethodPost, "/api/v1/auth/shop-login", map[string]any{
		"shop_code": c.ma, "username": username, "password": matKhauTest,
	})
	if res.ma != http.StatusOK {
		t.Fatalf("đăng nhập quản lý thứ hai hỏng: %d %s", res.ma, catBot(res.than))
	}
	var body struct {
		Data struct {
			AccessToken string `json:"access_token"`
		} `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &body); err != nil {
		t.Fatalf("không đọc được token: %v", err)
	}

	return body.Data.AccessToken
}

// idTaiKhoan tra id của một tài khoản theo username trong cửa hàng.
func idTaiKhoan(t *testing.T, h *heThong, c *cuaHang, username string) uint {
	t.Helper()

	ctx := tenant.WithID(context.Background(), c.id)
	var u domain.User
	if err := h.db.WithContext(ctx).Where("username = ?", username).First(&u).Error; err != nil {
		t.Fatalf("không tra được tài khoản %s: %v", username, err)
	}

	return u.ID
}

// TestThuChi_CaDaDongThiKhoaVoiMoiNguoi — lớp khoá thứ hai, và là lớp KHÔNG ai
// qua được, kể cả chủ tiệm: ca đã chốt số và hai bên đã ký nhận.
func TestThuChi_CaDaDongThiKhoaVoiMoiNguoi(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	moCa(t, h, a, 0)
	_, p := lapPhieuTC(t, h, a.token, chiTienMat(50000))
	if p.ShiftID == nil {
		t.Fatal("phiếu lập trong ca đang mở phải ghi thẳng shift_id — đó là thứ lớp khoá này dựa vào")
	}

	res := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/ca-lam-viec/dong",
		map[string]any{"counted_cash": 0})
	if res.ma != http.StatusOK && res.ma != http.StatusCreated {
		t.Fatalf("đóng ca trả %d\n%s", res.ma, catBot(res.than))
	}

	ds, _ := docThuChi(t, h, a.token, "")
	if len(ds) != 1 || !ds[0].Locked {
		t.Fatalf("phiếu thuộc ca đã đóng phải bật cờ locked, nhận %+v", ds)
	}

	duong := fmt.Sprintf("/api/v1/admin/thu-chi/%d", p.ID)
	if res := h.goi(t, a.token, http.MethodPut, duong, chiTienMat(999000)); res.ma != http.StatusConflict {
		t.Fatalf("CHỦ TIỆM sửa phiếu của ca đã đóng cũng phải bị chặn 409, nhận %d\n%s", res.ma, catBot(res.than))
	}
	if res := h.goi(t, a.token, http.MethodDelete, duong, nil); res.ma != http.StatusConflict {
		t.Fatalf("xoá phiếu của ca đã đóng phải bị chặn 409, nhận %d", res.ma)
	}
}

// TestThuChi_TienMatVaoSoQuyCuaCa — phiếu tiền mặt là tiền THẬT ra vào két, nên
// phải hiện trong tổng kết ca; phiếu chuyển khoản thì không.
//
// Không có mắt xích này thì đóng ca đếm lệch đúng bằng số đã thu/chi bằng tiền
// mặt, mà không có gì trên màn hình nói vì sao.
func TestThuChi_TienMatVaoSoQuyCuaCa(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	moCa(t, h, a, 0)

	lapPhieuTC(t, h, a.token, thuTienMat(300000))
	lapPhieuTC(t, h, a.token, chiTienMat(120000))
	lapPhieuTC(t, h, a.token, map[string]any{
		"type": 0, "amount": 900000, "payment_method": "transfer",
	})

	ca := doc(t, h.goi(t, a.token, http.MethodGet, "/api/v1/admin/ca-lam-viec/hien-tai", nil))
	if ca["tong_thu"].(float64) != 300000 {
		t.Fatalf("tổng thu của ca phải là 300000 (chuyển khoản KHÔNG vào két), đang là %v", ca["tong_thu"])
	}
	if ca["tong_chi"].(float64) != 120000 {
		t.Fatalf("tổng chi của ca phải là 120000, đang là %v", ca["tong_chi"])
	}
}

// TestThuChi_XoaPhieuThiGoLuonDongSoQuy — xoá phiếu mà để lại dòng trong két là
// đóng ca xong đếm thừa đúng bằng số vừa xoá.
func TestThuChi_XoaPhieuThiGoLuonDongSoQuy(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	moCa(t, h, a, 0)
	_, p := lapPhieuTC(t, h, a.token, thuTienMat(400000))

	duong := fmt.Sprintf("/api/v1/admin/thu-chi/%d", p.ID)
	if res := h.goi(t, a.token, http.MethodDelete, duong, nil); res.ma != http.StatusOK {
		t.Fatalf("xoá phiếu trả %d\n%s", res.ma, catBot(res.than))
	}

	ca := doc(t, h.goi(t, a.token, http.MethodGet, "/api/v1/admin/ca-lam-viec/hien-tai", nil))
	if ca["tong_thu"].(float64) != 0 {
		t.Fatalf("xoá phiếu rồi thì két phải về 0, đang là %v", ca["tong_thu"])
	}
}

// TestThuChi_SuaPhuongThucThiNanLaiKet — đổi từ tiền mặt sang chuyển khoản là
// tiền RỜI khỏi két, dòng sổ quỹ phải biến mất theo.
func TestThuChi_SuaPhuongThucThiNanLaiKet(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	moCa(t, h, a, 0)
	_, p := lapPhieuTC(t, h, a.token, thuTienMat(200000))

	duong := fmt.Sprintf("/api/v1/admin/thu-chi/%d", p.ID)
	res := h.goi(t, a.token, http.MethodPut, duong, map[string]any{
		"type": 0, "amount": 200000, "payment_method": "transfer",
	})
	if res.ma != http.StatusOK {
		t.Fatalf("sửa phiếu trả %d\n%s", res.ma, catBot(res.than))
	}

	ca := doc(t, h.goi(t, a.token, http.MethodGet, "/api/v1/admin/ca-lam-viec/hien-tai", nil))
	if ca["tong_thu"].(float64) != 0 {
		t.Fatalf("đổi sang chuyển khoản thì két phải về 0, đang là %v", ca["tong_thu"])
	}
}

// TestThuChi_DoiDoiTuongThiDonCotCu — sửa từ "Nhà cung cấp" sang "Khác" mà không
// dọn cột cũ thì phiếu mang hai đối tượng, và mỗi chỗ đọc lại chọn một cái.
func TestThuChi_DoiDoiTuongThiDonCotCu(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	res := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/nguoi-nop-thu-chi", map[string]any{
		"name": "Khách vãng lai " + a.vet, "phone": "0900000001",
	})
	if res.ma != http.StatusCreated {
		t.Fatalf("thêm người nộp trả %d\n%s", res.ma, catBot(res.than))
	}
	var nn struct {
		Data struct {
			ID uint `json:"id"`
		} `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &nn); err != nil {
		t.Fatalf("không đọc được người nộp vừa thêm: %v", err)
	}

	_, p := lapPhieuTC(t, h, a.token, map[string]any{
		"type": 0, "amount": 50000, "payment_method": "cash",
		"payer_type": "other", "payer_id": nn.Data.ID,
	})
	if p.PayerName == "" {
		t.Fatal("phiếu phải tra kèm tên người nộp để bảng không phải gọi thêm lượt nào")
	}

	// Bỏ đối tượng đi: cả loại lẫn id phải sạch.
	duong := fmt.Sprintf("/api/v1/admin/thu-chi/%d", p.ID)
	res = h.goi(t, a.token, http.MethodPut, duong, thuTienMat(50000))
	if res.ma != http.StatusOK {
		t.Fatalf("sửa phiếu trả %d\n%s", res.ma, catBot(res.than))
	}

	ds, _ := docThuChi(t, h, a.token, "")
	if len(ds) != 1 || ds[0].PayerType != "" || ds[0].PayerName != "" {
		t.Fatalf("bỏ đối tượng thì cả loại lẫn tên phải sạch, nhận %+v", ds)
	}
}

// TestThuChi_TraDuTruongChoManHinh — API phải trả ĐỦ thứ bảng và hộp Sửa cần.
//
// Hai chỗ từng hụt, và cả hai chỉ lộ ra khi so tên trường thật với tên màn hình
// đọc — chạy service với sổ giả thì không thấy:
//
//   - `attachment_url`: cột Đính kèm đọc tên này, API lại trả `attachment` nên
//     ô ấy luôn trống dù phiếu có tệp, và mở phiếu ra sửa là mất tệp.
//   - `payer_id`: id đối tượng nằm rải ở ba cột theo `payer_type`. Không gộp lại
//     thì phiếu ghi cho một nhân viên mở ra ô "Người nộp" trống trơn, lưu một
//     cái là mất người nộp.
func TestThuChi_TraDuTruongChoManHinh(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	res := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/nguoi-nop-thu-chi",
		map[string]any{"name": "Bác bảo vệ " + a.vet})
	if res.ma != http.StatusCreated {
		t.Fatalf("thêm người nộp trả %d", res.ma)
	}
	var nn struct {
		Data struct {
			ID uint `json:"id"`
		} `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &nn); err != nil {
		t.Fatalf("không đọc được người nộp: %v", err)
	}

	_, p := lapPhieuTC(t, h, a.token, map[string]any{
		"type": 1, "amount": 80000, "payment_method": "cash",
		"payer_type": "other", "payer_id": nn.Data.ID,
		"attachment": "http://localhost/storage/thu-chi/hoa-don.pdf",
	})

	// Đọc lại qua đường danh sách — đúng đường màn hình dùng.
	duong := fmt.Sprintf("/api/v1/admin/thu-chi/%d", p.ID)
	res = h.goi(t, a.token, http.MethodGet, duong, nil)
	if res.ma != http.StatusOK {
		t.Fatalf("đọc chi tiết trả %d", res.ma)
	}

	var body struct {
		Data map[string]any `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &body); err != nil {
		t.Fatalf("không đọc được phản hồi: %v", err)
	}

	if body.Data["attachment_url"] != "http://localhost/storage/thu-chi/hoa-don.pdf" {
		t.Fatalf("thiếu hoặc sai `attachment_url`, nhận %v", body.Data["attachment_url"])
	}
	if v, co := body.Data["payer_id"]; !co || v == nil {
		t.Fatal("thiếu `payer_id` gộp — hộp Sửa sẽ không chọn lại được người nộp")
	}
	// Ba cột thô KHÔNG được lọt ra: bày cả ba là bắt đầu bên kia nhớ cột nào
	// ứng với payer_type nào.
	for _, k := range []string{"employee_id", "supplier_id"} {
		if _, co := body.Data[k]; co {
			t.Fatalf("`%s` không được ra JSON — đã gộp vào payer_id rồi", k)
		}
	}

	// Mấy trường bảng in ra, thiếu cái nào là cột ấy trống.
	for _, k := range []string{
		"code", "type", "amount", "branch_name", "category_name",
		"payer_name", "created_by_name", "payment_method", "note",
		"source", "created_at", "locked",
	} {
		if _, co := body.Data[k]; !co {
			t.Fatalf("thiếu trường `%s` — cột tương ứng trên bảng sẽ trống", k)
		}
	}
}

// TestThuChi_BanHangDeRaPhieuThu — bán một lượt là sổ thu chi có ngay một phiếu
// THU tự sinh, và phiếu ấy KHOÁ với mọi người.
//
// Đây là mắt xích của cả cụm "phiếu hệ thống": không có nó thì ba mục Bán hàng /
// Mua hàng / Trả hàng trong ô lọc "Loại" vĩnh viễn ra bảng rỗng, và sổ thu chi
// chỉ là nơi ghi mấy khoản lặt vặt thay vì sổ tiền của cửa hàng.
func TestThuChi_BanHangDeRaPhieuThu(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	res := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/orders/pos", map[string]any{
		"payment_method": "cash",
		"items":          []map[string]any{{"product_variant_id": a.bienThe, "quantity": 2}},
	})
	if res.ma != http.StatusCreated {
		t.Fatalf("bán tại quầy trả %d — %s", res.ma, catBot(res.than))
	}

	ds, quy := docThuChi(t, h, a.token, "")
	if len(ds) != 1 {
		t.Fatalf("bán xong phải có đúng 1 phiếu tự sinh, nhận %d", len(ds))
	}
	p := ds[0]
	if p.Source != "order" {
		t.Fatalf("phiếu phải mang nguồn `order`, nhận %q", p.Source)
	}
	if p.Type != 0 {
		t.Fatal("bán hàng là tiền VÀO nên phải là phiếu thu")
	}
	if p.Amount <= 0 || p.Amount != quy.TongThu {
		t.Fatalf("số tiền phiếu (%v) phải khớp tổng thu của sổ (%v)", p.Amount, quy.TongThu)
	}
	if !p.Locked {
		t.Fatal("phiếu tự sinh phải khoá: sửa nó là số liệu đơn hàng đổi mà đơn hàng không biết")
	}

	// Mã chứng từ gốc phải tra ra được: hộp Xem chi tiết in nó ngay dòng đầu, và
	// đó là manh mối duy nhất để từ một phiếu tự sinh lần ngược về đơn đã đẻ ra nó.
	if p.SourceCode == "" {
		t.Fatal("phiếu tự sinh phải mang mã đơn đã đẻ ra nó")
	}
	if !strings.Contains(res.than, p.SourceCode) {
		t.Fatalf("mã chứng từ gốc %q không có trong đơn vừa lập", p.SourceCode)
	}

	// Chốt chặn server, không chỉ cái cờ.
	duong := fmt.Sprintf("/api/v1/admin/thu-chi/%d", p.ID)
	if res := h.goi(t, a.token, http.MethodDelete, duong, nil); res.ma != http.StatusConflict {
		t.Fatalf("xoá phiếu tự sinh phải bị chặn 409, nhận %d", res.ma)
	}
	if res := h.goi(t, a.token, http.MethodPut, duong, thuTienMat(1)); res.ma != http.StatusConflict {
		t.Fatalf("sửa phiếu tự sinh phải bị chặn 409, nhận %d", res.ma)
	}
}

// TestThuChi_PhieuTuSinhKhongDemHaiLanTrongKet — chỗ dễ hỏng nhất của cả thay đổi.
//
// Luồng bán hàng ĐÃ tự ghi `cash_entries` của nó. Nếu phiếu thu chi tự sinh cũng
// ghi thêm một dòng nữa thì MỘT khoản tiền đếm hai lần trong két, và đóng ca
// lệch đúng bằng doanh thu tiền mặt của ca — sai kiểu không ai truy ra nổi.
func TestThuChi_PhieuTuSinhKhongDemHaiLanTrongKet(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	moCa(t, h, a, 0)

	res := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/orders/pos", map[string]any{
		"payment_method": "cash",
		"items":          []map[string]any{{"product_variant_id": a.bienThe, "quantity": 2}},
	})
	if res.ma != http.StatusCreated {
		t.Fatalf("bán tại quầy trả %d — %s", res.ma, catBot(res.than))
	}

	ds, _ := docThuChi(t, h, a.token, "")
	if len(ds) != 1 {
		t.Fatalf("phải có 1 phiếu thu chi, nhận %d", len(ds))
	}

	ca := doc(t, h.goi(t, a.token, http.MethodGet, "/api/v1/admin/ca-lam-viec/hien-tai", nil))
	if ca["tong_thu"].(float64) != ds[0].Amount {
		t.Fatalf("két phải đúng bằng MỘT lượt bán (%v), đang là %v — phiếu tự sinh đang ghi thêm một dòng sổ quỹ nữa",
			ds[0].Amount, ca["tong_thu"])
	}
}

// TestThuChi_TraTienPhieuMuaDeRaPhieuChi — tiền trả nhà cung cấp là khoản RA lớn
// nhất của một cửa hàng bán lẻ; sổ thu chi thiếu nó thì tổng chi không có nghĩa.
func TestThuChi_TraTienPhieuMuaDeRaPhieuChi(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	res := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/nha-cung-cap", map[string]any{
		"name": "Cong ty Vat Tu " + a.vet, "address": "1 Nguyen Trai",
	})
	var ncc struct {
		Data struct {
			ID uint `json:"id"`
		} `json:"data"`
	}
	_ = json.Unmarshal([]byte(res.than), &ncc)
	if ncc.Data.ID == 0 {
		t.Fatalf("tạo nhà cung cấp hỏng %d", res.ma)
	}

	ma, phieu := lapPhieu(t, h, a.token, map[string]any{
		"supplier_id": ncc.Data.ID,
		"items":       []any{dongHang(a.bienThe, 3, 100000)},
	})
	if ma != http.StatusCreated {
		t.Fatalf("lập phiếu mua trả %d", ma)
	}

	duong := fmt.Sprintf("/api/v1/admin/phieu-mua-hang/%d/thanh-toan", phieu.ID)
	// `paid_amount` là số LUỸ KẾ đã trả, không phải tiền của riêng lượt này —
	// service tự lấy phần chênh so với lần trước để ghi sổ.
	res = h.goi(t, a.token, http.MethodPost, duong, map[string]any{
		"paid_amount": 120000, "payment_method": "cash",
	})
	if res.ma != http.StatusOK && res.ma != http.StatusCreated {
		t.Fatalf("thanh toán phiếu mua trả %d — %s", res.ma, catBot(res.than))
	}

	ds, _ := docThuChi(t, h, a.token, "source=purchase")
	if len(ds) != 1 || ds[0].Type != 1 || ds[0].Amount != 120000 {
		t.Fatalf("trả tiền phiếu mua phải đẻ đúng một phiếu CHI 120000, nhận %+v", ds)
	}
	if !ds[0].Locked {
		t.Fatal("phiếu tự sinh từ phiếu mua phải khoá")
	}
}

// TestThuChi_TraTienNgayLucLapPhieuMuaCungVaoSo — lỗ hổng có sẵn, nay bịt lại.
//
// `paid_amount` của phiếu mua được đặt ở BA đường: lập phiếu, sửa phiếu nháp, và
// bấm Thanh toán. Trước đây chỉ đường thứ ba ghi `purchase_payments`, nên mua
// hàng mà trả tiền LUÔN lúc lập phiếu là khoản tiền ấy biến mất khỏi sổ trả
// tiền, khỏi màn Công nợ, và khỏi sổ thu chi — chỉ mỗi con số trên phiếu biết.
//
// Bài này gác cả hai vế của bất biến:
//
//	purchase_orders.paid_amount = SUM(purchase_payments.amount)
func TestThuChi_TraTienNgayLucLapPhieuMuaCungVaoSo(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	res := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/nha-cung-cap", map[string]any{
		"name": "Cong ty Bao Bi " + a.vet, "address": "9 Tran Phu",
	})
	var ncc struct {
		Data struct {
			ID uint `json:"id"`
		} `json:"data"`
	}
	_ = json.Unmarshal([]byte(res.than), &ncc)
	if ncc.Data.ID == 0 {
		t.Fatalf("tạo nhà cung cấp hỏng %d", res.ma)
	}

	// Lập phiếu và TRẢ LUÔN 300000 ngay trong cùng lượt.
	ma, phieu := lapPhieu(t, h, a.token, map[string]any{
		"supplier_id": ncc.Data.ID,
		"items":       []any{dongHang(a.bienThe, 5, 100000)},
		"paid_amount": 300000,
	})
	if ma != http.StatusCreated {
		t.Fatalf("lập phiếu mua trả %d", ma)
	}

	ds, _ := docThuChi(t, h, a.token, "source=purchase")
	if len(ds) != 1 {
		t.Fatalf("trả tiền ngay lúc lập phiếu phải đẻ đúng 1 phiếu chi, nhận %d", len(ds))
	}
	if ds[0].Type != 1 || ds[0].Amount != 300000 {
		t.Fatalf("phải là phiếu CHI 300000, nhận %+v", ds[0])
	}

	// Sổ trả tiền cũng phải có dòng ấy — không thì màn Công nợ vẫn thấy phiếu
	// chưa trả đồng nào.
	duong := fmt.Sprintf("/api/v1/admin/cong-no/%d/lich-su-tra", phieu.ID)
	res = h.goi(t, a.token, http.MethodGet, duong, nil)
	if res.ma == http.StatusOK {
		var sổ struct {
			Data []struct {
				Amount float64 `json:"amount"`
			} `json:"data"`
		}
		_ = json.Unmarshal([]byte(res.than), &sổ)
		tong := 0.0
		for _, d := range sổ.Data {
			tong += d.Amount
		}
		if tong != 300000 {
			t.Fatalf("sổ trả tiền phải cộng đúng 300000 (bất biến paid_amount), nhận %v", tong)
		}
	}
}

// TestThuChi_NguoiNopTrungTen — hai người nộp cùng tên trong một cửa hàng là hai
// dòng không phân biệt được ở ô chọn.
func TestThuChi_NguoiNopTrungTen(t *testing.T) {
	h := dungHeThong(t)
	a, b := haiCuaHang(t, h)

	than := map[string]any{"name": "Chú Ba", "phone": "0900000002"}

	if res := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/nguoi-nop-thu-chi", than); res.ma != http.StatusCreated {
		t.Fatalf("lượt thêm đầu phải trả 201, nhận %d", res.ma)
	}
	if res := h.goi(t, a.token, http.MethodPost, "/api/v1/admin/nguoi-nop-thu-chi", than); res.ma != http.StatusUnprocessableEntity {
		t.Fatalf("trùng tên trong cùng cửa hàng phải bị chặn 422, nhận %d", res.ma)
	}
	// Cửa hàng khác thì không liên quan.
	if res := h.goi(t, b.token, http.MethodPost, "/api/v1/admin/nguoi-nop-thu-chi", than); res.ma != http.StatusCreated {
		t.Fatalf("cửa hàng khác khai cùng tên phải được, nhận %d", res.ma)
	}
}

// TestThuChi_SoTienPhaiDuong — 0 đồng và số âm đều không phải một khoản tiền.
func TestThuChi_SoTienPhaiDuong(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	for _, soTien := range []float64{0, -1000} {
		than := map[string]any{"type": 0, "amount": soTien, "payment_method": "cash"}
		if ma, _ := lapPhieuTC(t, h, a.token, than); ma != http.StatusUnprocessableEntity {
			t.Fatalf("số tiền %v phải bị chặn 422, nhận %d", soTien, ma)
		}
	}
}
