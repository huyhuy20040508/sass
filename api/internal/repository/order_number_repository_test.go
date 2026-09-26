package repository

import (
	"context"
	"strings"
	"testing"

	"gorm.io/driver/mysql"
	"gorm.io/gorm"

	"sass-api/internal/domain"
	"sass-api/internal/tenant"
)

// Bài kiểm SỔ CHỨNG TỪ BÁN HÀNG — canh đúng MỘT thứ, và đó là thứ nguy hiểm
// nhất của tệp so_don_repository.go: câu UNION có được lọc theo cửa hàng ở CẢ
// HAI nhánh hay không.
//
// Vì sao đáng một bài kiểm riêng: plugin tenant chèn điều kiện ở callback của
// GORM, nhưng với bảng dẫn xuất nó CỐ Ý bỏ qua lớp ngoài (xem scopeTarget —
// nhánh `derived`) và tin rằng hai câu con tự lọc lấy. Niềm tin ấy đúng chừng
// nào hai câu con còn được dựng bằng *gorm.DB. Ngày nào có người đổi một nhánh
// thành chuỗi SQL cho "gọn", câu vẫn chạy, màn hình vẫn đúng với cửa hàng đang
// đăng nhập — và lặng lẽ trả về chứng từ của cửa hàng khác cho ai gọi thẳng API.
//
// Bài kiểm chạy KHÔ (DryRun): không cần database, không đọc ghi gì cả, chỉ dựng
// câu lệnh rồi soi chuỗi SQL.
func TestSoDon_LocTheoCuaHangOCaHaiNhanh(t *testing.T) {
	db, err := gorm.Open(mysql.New(mysql.Config{
		// Không mở kết nối nào: DryRun chỉ cần phương ngữ để dựng SQL. DSN vẫn phải
		// đúng dạng vì driver phân tích chuỗi ngay lúc Open, dù không quay số.
		DSN:                       "u:p@tcp(127.0.0.1:3306)/khong_ket_noi",
		SkipInitializeWithVersion: true,
		Conn:                      nil,
	}), &gorm.Config{DryRun: true, DisableAutomaticPing: true})
	if err != nil {
		t.Fatalf("không dựng được phiên GORM chạy khô: %v", err)
	}
	if err := db.Use(tenantScope{}); err != nil {
		t.Fatalf("không gắn được plugin tenant: %v", err)
	}

	repo := &orderRepository{db: db}
	ctx := tenant.WithID(context.Background(), 42)

	stmt := db.WithContext(ctx).
		Table("((?) UNION ALL (?)) AS so_don",
			repo.cauDonBan(ctx, domain.OrderFilter{}),
			repo.cauPhieuTra(ctx, domain.OrderFilter{})).
		Order(sapXep("")).
		Limit(20).
		Find(&[]domain.DongSoDon{}).Statement

	sql := stmt.SQL.String()
	if sql == "" {
		t.Fatal("không dựng ra câu SQL nào")
	}

	// Hai nhánh, hai điều kiện tenant — đếm chứ không chỉ tìm thấy một lần.
	if n := strings.Count(sql, "`tenant_id` = ?"); n < 2 {
		t.Fatalf("câu UNION phải lọc tenant ở CẢ HAI nhánh, đếm được %d:\n%s", n, sql)
	}

	// Và giá trị lọc phải là cửa hàng trong ctx, không phải một số nào khác.
	dem := 0
	for _, v := range stmt.Vars {
		if id, ok := v.(uint); ok && id == 42 {
			dem++
		}
	}
	if dem < 2 {
		t.Fatalf("phải truyền tenant 42 cho cả hai nhánh, đếm được %d: %v", dem, stmt.Vars)
	}
}

// Bộ lọc trạng thái quyết định nhánh phiếu trả có chạy hay bị gạt đi.
//
// Đây là luật của v2 chép sang: phiếu trả chỉ thuộc về đúng một lựa chọn trạng
// thái. Sai luật này thì lọc "Đã huỷ" vẫn thấy phiếu trả chen vào, và con số
// dưới chân bảng không khớp với thứ người dùng vừa tick.
func TestChonTraHang(t *testing.T) {
	cac := []struct {
		ten    string
		status string
		muon   bool
	}{
		{"không lọc thì xem tất", "", true},
		{"all cũng là không lọc", "all", true},
		{"có tick Trả hàng", "cancelled,returned", true},
		{"chỉ Trả hàng", "returned", true},
		{"không tick Trả hàng", "pending,confirmed", false},
		{"một trạng thái khác", "completed", false},
	}

	for _, c := range cac {
		if got := chonTraHang(c.status); got != c.muon {
			t.Errorf("%s: chonTraHang(%q) = %v, muốn %v", c.ten, c.status, got, c.muon)
		}
	}
}

// phienChayKho dựng phiên GORM chạy KHÔ có gắn plugin tenant — đủ để dựng câu
// lệnh rồi soi chuỗi SQL, không cần database.
func phienChayKho(t *testing.T) (*orderRepository, context.Context) {
	t.Helper()
	db, err := gorm.Open(mysql.New(mysql.Config{
		DSN:                       "u:p@tcp(127.0.0.1:3306)/khong_ket_noi",
		SkipInitializeWithVersion: true,
	}), &gorm.Config{DryRun: true, DisableAutomaticPing: true})
	if err != nil {
		t.Fatalf("không dựng được phiên GORM chạy khô: %v", err)
	}
	if err := db.Use(tenantScope{}); err != nil {
		t.Fatalf("không gắn được plugin tenant: %v", err)
	}

	return &orderRepository{db: db}, tenant.WithID(context.Background(), 42)
}

// sqlCua dựng câu lệnh của một nhánh sổ chứng từ thành chuỗi SQL.
func sqlCua(ctx context.Context, q *gorm.DB) string {
	return q.WithContext(ctx).Find(&[]domain.DongSoDon{}).Statement.SQL.String()
}

// Phiếu trả với ô HĐĐT và ô phương thức — hai chỗ bản trước gạt nhầm cả nhánh.
//
// Luật của v2: chỉ lọc "HĐĐT: Có" mới gạt phiếu trả (nó không tự có hoá đơn);
// lọc "Không" thì phiếu trả vẫn ở lại. Lọc phương thức thì phiếu trả ở lại nếu
// nó hoàn bằng cùng nhóm, không bị gạt sạch.
func TestCauPhieuTra_HoaDonVaPhuongThuc(t *testing.T) {
	repo, ctx := phienChayKho(t)

	cac := []struct {
		ten      string
		f        domain.OrderFilter
		biGat    bool
		phaiChua string
	}{
		{"không lọc", domain.OrderFilter{}, false, ""},
		{"HĐĐT: Không vẫn giữ phiếu trả", domain.OrderFilter{HoaDonDienTu: "khong"}, false, ""},
		{"HĐĐT: Có thì gạt", domain.OrderFilter{HoaDonDienTu: "co"}, true, ""},
		{"tick cả hai = không lọc", domain.OrderFilter{HoaDonDienTu: "co,khong"}, false, ""},
		{"lọc phương thức giữ phiếu cùng nhóm", domain.OrderFilter{PaymentMethod: "momo"}, false, "order_returns.refund_method IN"},
		{"lọc trạng thái khác thì gạt", domain.OrderFilter{Status: "paid"}, true, ""},
	}
	for _, c := range cac {
		sql := sqlCua(ctx, repo.cauPhieuTra(ctx, c.f))
		if got := strings.Contains(sql, "1 = 0"); got != c.biGat {
			t.Errorf("%s: gạt nhánh phiếu trả = %v, muốn %v\n%s", c.ten, got, c.biGat, sql)
		}
		if c.phaiChua != "" && !strings.Contains(sql, c.phaiChua) {
			t.Errorf("%s: thiếu %q\n%s", c.ten, c.phaiChua, sql)
		}
		// Phiếu chưa nhận hàng (chờ duyệt, bị từ chối, khách rút) không bao giờ vào sổ.
		if !strings.Contains(sql, "order_returns.status IN ('received','refunded')") {
			t.Errorf("%s: nhánh phiếu trả phải chỉ lấy phiếu đã nhận hàng / đã hoàn tiền\n%s", c.ten, sql)
		}
	}
}

// Ô lọc "Trạng thái" phải so với ĐÚNG biểu thức in ra cột Trạng thái. Hai nơi
// hiểu một đơn theo hai cách là tick "Chưa thanh toán" mà bảng in "Một phần".
func TestCauDonBan_LocTrangThaiCungBieuThucVoiCot(t *testing.T) {
	repo, ctx := phienChayKho(t)

	sql := sqlCua(ctx, repo.cauDonBan(ctx, domain.OrderFilter{Status: "unpaid,partial"}))
	if n := strings.Count(sql, trangThaiDon); n != 2 {
		t.Fatalf("biểu thức trạng thái phải xuất hiện đúng 2 lần (cột + lọc), đếm được %d\n%s", n, sql)
	}

	// Giá trị lạ không được lặng lẽ thành "xem tất cả".
	if sql := sqlCua(ctx, repo.cauDonBan(ctx, domain.OrderFilter{Status: "pending"})); !strings.Contains(sql, "1 = 0") {
		t.Fatalf("lọc toàn giá trị lạ phải ra bảng rỗng\n%s", sql)
	}
}

// Nhóm phương thức — phiếu trả hoàn bằng bộ giá trị riêng (ewallet), nên lọc
// phiếu trả theo từng mã của đơn là không bao giờ khớp ví.
func TestNhomCua(t *testing.T) {
	cac := []struct {
		chon []string
		muon []string
	}{
		{[]string{"cash"}, []string{"cash", "cod"}},
		{[]string{"momo"}, []string{"vnpay", "momo", "payos", "ewallet"}},
		{[]string{"sepay", "cod"}, []string{"cash", "cod", "bank_transfer", "sepay"}},
		{[]string{"khong-co"}, []string{""}},
	}
	for _, c := range cac {
		if got := nhomCua(c.chon); strings.Join(got, ",") != strings.Join(c.muon, ",") {
			t.Errorf("nhomCua(%v) = %v, muốn %v", c.chon, got, c.muon)
		}
	}
}

// Mặc định là "vừa đụng tới lên đầu" như v2 (`orderByDesc('updated_at')`) —
// giao diện gửi `updated`, và giá trị lạ cũng rơi về đây.
func TestSapXep_MacDinhLaVuaDungToi(t *testing.T) {
	for _, s := range []string{"", "updated", "linh-tinh"} {
		if got := sapXep(s); got != "updated_at DESC, id DESC" {
			t.Errorf("sapXep(%q) = %q, muốn updated_at DESC", s, got)
		}
	}
	if got := sapXep("newest"); got != "created_at DESC, id DESC" {
		t.Errorf("sapXep(newest) = %q", got)
	}
}
