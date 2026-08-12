package domain

import "testing"

func TestNhanTenMienTuTen(t *testing.T) {
	truongHop := []struct{ ten, muon string }{
		// Cái tên có thật trong hệ thống, và là lý do của cả tệp này.
		{"Quốc Huy", "quochuy"},

		// Đủ mặt dấu tiếng Việt.
		{"Cửa Hàng Đồ Gỗ Mỹ Nghệ", "cuahangdogomynghe"},
		{"Tạp hoá Dì Tư", "taphoaditu"},
		{"ĐẠI PHÁT", "daiphat"},
		{"Nệm Ưu Việt", "nemuuviet"},

		// Khoảng trắng, dấu câu, ký hiệu: bỏ hẳn, không đổi thành gạch nối.
		{"  Shop  A&B  ", "shopab"},
		{"Bánh mì 24/7", "banhmi247"},
		{"Nhà thuốc (Số 1)", "nhathuocso1"},

		// Không rút được chữ nào — nơi gọi phải dùng mã cửa hàng thay thế.
		{"", ""},
		{"   ", ""},
		{"!!! ???", ""},

		// Cắt đúng DoDaiNhanToiDa ký tự.
		{"Cong ty trach nhiem huu han thuong mai dich vu ABC", "congtytrachnhiemhuuhanthuongma"},
	}

	for _, tc := range truongHop {
		if ra := NhanTenMienTuTen(tc.ten); ra != tc.muon {
			t.Errorf("NhanTenMienTuTen(%q) = %q, muốn %q", tc.ten, ra, tc.muon)
		}
	}
}

func TestNhanTenMienTuTen_KhongVuotDoDai(t *testing.T) {
	nhan := NhanTenMienTuTen("Cửa hàng vật liệu xây dựng Hoà Bình Thịnh Vượng Phát Đạt")
	if len(nhan) != DoDaiNhanToiDa {
		t.Errorf("tên dài phải bị cắt còn %d ký tự, nhận %d (%q)", DoDaiNhanToiDa, len(nhan), nhan)
	}
}

// Nhãn sinh ra phải hợp lệ với DNS: chỉ a-z0-9. Kiểm bằng chính đầu ra chứ không
// tin vào mắt đọc bảng trên — một dấu tiếng Việt sót lại trong bảng bỏ dấu sẽ
// lọt xuống đây thành ký tự lạ trong tên miền.
func TestNhanTenMienTuTen_ChiChuVaSo(t *testing.T) {
	ten := []string{
		"Quốc Huy", "Đồ gỗ", "Tạp hoá", "Nệm Ưu Việt", "Bánh mì 24/7",
		"ÁÀẢÃẠÂẤẦẨẪẬĂẮẰẲẴẶ", "ÉÈẺẼẸÊẾỀỂỄỆ", "ÍÌỈĨỊ", "ÓÒỎÕỌÔỐỒỔỖỘƠỚỜỞỠỢ",
		"ÚÙỦŨỤƯỨỪỬỮỰ", "ÝỲỶỸỴ", "Đ",
	}
	for _, s := range ten {
		for _, r := range NhanTenMienTuTen(s) {
			if !((r >= 'a' && r <= 'z') || (r >= '0' && r <= '9')) {
				t.Errorf("NhanTenMienTuTen(%q) còn ký tự %q — không dùng làm tên miền được", s, r)
			}
		}
	}
}

func TestLaNhanDatTruoc(t *testing.T) {
	// Hai nhãn nền tảng đang dùng THẬT: cấp mất là mất địa chỉ của chính mình.
	for _, nhan := range []string{"order", "admin", "api", "www", "ORDER", "Admin"} {
		if !LaNhanDatTruoc(nhan) {
			t.Errorf("%q phải bị giữ lại cho nền tảng", nhan)
		}
	}
	for _, nhan := range []string{"quochuy", "cuahang", "taphoaditu"} {
		if LaNhanDatTruoc(nhan) {
			t.Errorf("%q là nhãn của khách, không được chặn", nhan)
		}
	}
}
