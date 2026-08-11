package migrate

import (
	"strings"
	"testing"
	"testing/fstest"
	"time"
)

// tepThu dựng một thư mục migrations giả trong bộ nhớ.
func tepThu(tep map[string]string) fstest.MapFS {
	fsys := fstest.MapFS{}
	for ten, noiDung := range tep {
		fsys["migrations/"+ten] = &fstest.MapFile{Data: []byte(noiDung)}
	}

	return fsys
}

func TestDocSapXepTheoSoThuTu(t *testing.T) {
	fsys := tepThu(map[string]string{
		"0010_muoi.sql": "CREATE TABLE b (id INT);",
		"0002_hai.sql":  "CREATE TABLE a (id INT);",
		"0001_mot.sql":  "CREATE TABLE c (id INT);",
	})

	ds, err := Doc(fsys, "migrations")
	if err != nil {
		t.Fatalf("Doc lỗi: %v", err)
	}

	muon := []int{1, 2, 10}
	if len(ds) != len(muon) {
		t.Fatalf("đọc được %d tệp, muốn %d", len(ds), len(muon))
	}
	for i, v := range muon {
		if ds[i].Version != v {
			t.Errorf("vị trí %d: version %d, muốn %d", i, ds[i].Version, v)
		}
	}

	// Sắp theo SỐ chứ không phải theo chuỗi: "0010" đứng sau "0002".
	if ds[2].Ten != "muoi" {
		t.Errorf("tệp cuối là %q, muốn \"muoi\"", ds[2].Ten)
	}
}

func TestDocBatTenTepSaiQuyTac(t *testing.T) {
	// Tên sai phải là LỖI chứ không phải bỏ qua im lặng — một tệp bị lờ đi thì
	// thay đổi lược đồ biến mất không dấu vết.
	xau := []string{"them-cot.sql", "1_mot.sql", "0001-mot.sql", "0001_Mot.sql", "0000_khong.sql"}

	for _, ten := range xau {
		t.Run(ten, func(t *testing.T) {
			_, err := Doc(tepThu(map[string]string{ten: "SELECT 1;"}), "migrations")
			if err == nil {
				t.Fatalf("tên %q sai quy tắc mà Doc không báo lỗi", ten)
			}
		})
	}
}

func TestDocBatTrungSoThuTu(t *testing.T) {
	_, err := Doc(tepThu(map[string]string{
		"0002_mot.sql": "SELECT 1;",
		"0002_hai.sql": "SELECT 2;",
	}), "migrations")

	if err == nil || !strings.Contains(err.Error(), "cùng số thứ tự") {
		t.Fatalf("muốn lỗi trùng số thứ tự, nhận: %v", err)
	}
}

func TestValidateChanLenhDoiDatabase(t *testing.T) {
	// Đây là cái bẫy đã có thật trong dự án: tệp cũ nướng cứng `USE selliotech`
	// nên nạp lên máy chủ là ghi nhầm sang database khác.
	cam := map[string]string{
		"USE":             "USE selliotech;\nALTER TABLE a ADD b INT;",
		"CREATE DATABASE": "CREATE DATABASE x;\nSELECT 1;",
		"DROP DATABASE":   "DROP DATABASE x;",
		"use chữ thường":  "use selliotech;\nSELECT 1;",
	}

	for ten, sqlText := range cam {
		t.Run(ten, func(t *testing.T) {
			if err := Validate("0001_thu.sql", sqlText); err == nil {
				t.Fatal("muốn báo lỗi, nhưng Validate cho qua")
			}
		})
	}
}

func TestValidateChoQuaKhiLenhCamNamTrongChuThich(t *testing.T) {
	// Chú thích giải thích "đừng viết USE ở đây" không được tính là vi phạm.
	hopLe := []string{
		"-- KHÔNG viết USE selliotech ở đây\nALTER TABLE a ADD b INT;",
		"/* CREATE DATABASE chỉ là ví dụ */\nALTER TABLE a ADD b INT;",
		"# USE gì đó\nSELECT 1;",
	}

	for _, sqlText := range hopLe {
		if err := Validate("0001_thu.sql", sqlText); err != nil {
			t.Errorf("Validate báo lỗi nhầm cho %q: %v", sqlText, err)
		}
	}
}

func TestValidateBatTepRong(t *testing.T) {
	if err := Validate("0001_rong.sql", "-- chỉ có chú thích\n\n"); err == nil {
		t.Fatal("tệp không có lệnh SQL nào mà Validate cho qua")
	}
}

func TestVanTayBoQuaKieuXuongDong(t *testing.T) {
	// Cùng một tệp checkout trên Windows (CRLF) và Linux (LF) phải ra cùng vân
	// tay, không thì mỗi lần deploy lại báo "tệp đã bị sửa".
	if vanTay("CREATE TABLE a;\nALTER TABLE a;\n") != vanTay("CREATE TABLE a;\r\nALTER TABLE a;\r\n") {
		t.Fatal("CRLF và LF cho ra vân tay khác nhau")
	}
}

func TestVanTayDoiKhiNoiDungDoi(t *testing.T) {
	if vanTay("ALTER TABLE a ADD b INT;") == vanTay("ALTER TABLE a ADD c INT;") {
		t.Fatal("hai nội dung khác nhau lại cho cùng vân tay")
	}
}

func TestSoTinhDungPhanConLai(t *testing.T) {
	ds, err := Doc(tepThu(map[string]string{
		"0001_mot.sql": "SELECT 1;",
		"0002_hai.sql": "SELECT 2;",
		"0003_ba.sql":  "SELECT 3;",
	}), "migrations")
	if err != nil {
		t.Fatal(err)
	}

	daChay := []DaChay{
		{Version: 1, Ten: "mot", VanTay: ds[0].VanTay, ChayLuc: time.Now()},
	}

	tt := So(ds, daChay)

	if len(tt.ConLai) != 2 {
		t.Fatalf("còn lại %d tệp, muốn 2", len(tt.ConLai))
	}
	if tt.ConLai[0].Version != 2 || tt.ConLai[1].Version != 3 {
		t.Errorf("phần còn lại sai thứ tự: %v", tt.ConLai)
	}
	if tt.Sach() {
		t.Error("còn tệp chưa chạy mà Sach() báo khớp")
	}
}

func TestSoBatTepDaChayBiSuaNoiDung(t *testing.T) {
	ds, err := Doc(tepThu(map[string]string{"0001_mot.sql": "SELECT 1;"}), "migrations")
	if err != nil {
		t.Fatal(err)
	}

	// Database ghi một vân tay khác — tức tệp đã bị sửa sau khi chạy.
	tt := So(ds, []DaChay{{Version: 1, Ten: "mot", VanTay: "van-tay-cu"}})

	if len(tt.LechVanTay) != 1 {
		t.Fatalf("phát hiện %d tệp lệch, muốn 1", len(tt.LechVanTay))
	}
	if len(tt.ConLai) != 0 {
		t.Errorf("tệp đã chạy không được tính vào phần còn lại: %v", tt.ConLai)
	}
	if tt.Sach() {
		t.Error("có tệp bị sửa mà Sach() báo khớp")
	}
}

func TestSoBatTepDaChayNhungKhongCon(t *testing.T) {
	ds, err := Doc(tepThu(map[string]string{"0001_mot.sql": "SELECT 1;"}), "migrations")
	if err != nil {
		t.Fatal(err)
	}

	tt := So(ds, []DaChay{
		{Version: 1, Ten: "mot", VanTay: ds[0].VanTay},
		{Version: 2, Ten: "da-bi-xoa", VanTay: "abc"},
	})

	if len(tt.ThieuTep) != 1 || tt.ThieuTep[0].Version != 2 {
		t.Fatalf("muốn báo thiếu tệp 0002, nhận: %v", tt.ThieuTep)
	}
}

func TestSoBaoKhopKhiDayDu(t *testing.T) {
	ds, err := Doc(tepThu(map[string]string{
		"0001_mot.sql": "SELECT 1;",
		"0002_hai.sql": "SELECT 2;",
	}), "migrations")
	if err != nil {
		t.Fatal(err)
	}

	tt := So(ds, []DaChay{
		{Version: 1, Ten: "mot", VanTay: ds[0].VanTay},
		{Version: 2, Ten: "hai", VanTay: ds[1].VanTay},
	})

	if !tt.Sach() {
		t.Fatalf("mọi thứ khớp mà Sach() báo lệch: %+v", tt)
	}
}

func TestTenTepMoi(t *testing.T) {
	ds, err := Doc(tepThu(map[string]string{
		"0001_mot.sql":  "SELECT 1;",
		"0009_chin.sql": "SELECT 9;",
	}), "migrations")
	if err != nil {
		t.Fatal(err)
	}

	truongHop := []struct{ vao, ra string }{
		{"them-cot-ma-van-don", "0010_them-cot-ma-van-don.sql"},
		{"Thêm Cột", "0010_th-m-c-t.sql"},       // bỏ dấu tiếng Việt, không để tên có dấu
		{"  bang   moi  ", "0010_bang-moi.sql"}, // gộp khoảng trắng thành gạch nối
	}

	for _, tc := range truongHop {
		ten, err := TenTepMoi(ds, tc.vao)
		if err != nil {
			t.Errorf("TenTepMoi(%q) lỗi: %v", tc.vao, err)

			continue
		}
		if ten != tc.ra {
			t.Errorf("TenTepMoi(%q) = %q, muốn %q", tc.vao, ten, tc.ra)
		}
	}

	if _, err := TenTepMoi(nil, "dau-tien"); err != nil {
		t.Errorf("thư mục rỗng phải tạo được tệp đầu tiên: %v", err)
	}
	if _, err := TenTepMoi(ds, "!!!"); err == nil {
		t.Error("tên rỗng sau khi chuẩn hoá mà không báo lỗi")
	}
}

func TestSoVanTayLietKeKhacBiet(t *testing.T) {
	a := VanTayLuocDo{Bang: []BangLuocDo{
		{Ten: "orders", Cot: []string{"id bigint NO", "ma_van_don varchar(50) YES"}},
		{Ten: "users", Cot: []string{"id bigint NO"}},
	}}
	b := VanTayLuocDo{Bang: []BangLuocDo{
		{Ten: "orders", Cot: []string{"id bigint NO"}},
		{Ten: "cu", Cot: []string{"id bigint NO"}},
	}}

	khac := SoVanTay(a, b, "prod", "test")

	noiDung := strings.Join(khac, "\n")
	for _, can := range []string{"users", "cu", "ma_van_don"} {
		if !strings.Contains(noiDung, can) {
			t.Errorf("kết quả so sánh không nhắc tới %q:\n%s", can, noiDung)
		}
	}
}

func TestSoVanTayKhongBaoGiKhiGiongNhau(t *testing.T) {
	a := VanTayLuocDo{Bang: []BangLuocDo{
		{Ten: "orders", Cot: []string{"id bigint NO"}, Khoa: []string{"PRIMARY UNIQUE id"}},
	}}

	if khac := SoVanTay(a, a, "x", "y"); len(khac) != 0 {
		t.Fatalf("hai lược đồ giống hệt mà báo khác: %v", khac)
	}
}
