package service

import (
	"testing"

	"sass-api/internal/domain"
)

// Registry của tính năng gói là chốt chặn DUY NHẤT còn lại từ khi hạn mức
// chuyển sang dạng khoá · giá trị: cột `value` là VARCHAR nên database không
// còn từ chối "hai tài khoản rưỡi" hay "mười" như hồi nó là SMALLINT (xem
// migration 0005). Vì vậy registry phải được kiểm như một phần của lược đồ,
// không phải như một bảng khai báo cho vui.

func TestRegistryTinhNangGoi_KhaiDayDu(t *testing.T) {
	thay := map[string]bool{}
	for _, d := range planFeatureRegistry {
		if thay[d.Key] {
			t.Fatalf("khoá %q khai hai lần — tra cứu sẽ lấy dòng nào là do thứ tự", d.Key)
		}
		thay[d.Key] = true

		if d.Label == "" {
			t.Errorf("khoá %q chưa có nhãn — màn hình sẽ hiện một ô nhập không tên", d.Key)
		}

		switch d.Type {
		case PlanFeatureSo:
			// Trần là thứ chặn cái gõ nhầm một chữ số. Không có trần thì một lần gõ
			// thừa số 0 bán ra một gói không ai định bán.
			if d.MaxNum <= 0 {
				t.Errorf("hạn mức %q không có trần", d.Key)
			}
		case PlanFeatureCoKhong:
			if d.ChoVoHan {
				t.Errorf("khoá bật/tắt %q lại nhận %q — hai thứ đó không đi cùng nhau", d.Key, domain.VoHan)
			}
			// Khoá bật/tắt PHẢI có mặc định đọc được: nơi ép luật (cmd/ten-mien) đọc
			// "không có dòng" thành TẮT, và màn hình phải nói đúng như vậy.
			if d.KhongCoDong != "0" && d.KhongCoDong != "1" {
				t.Errorf("khoá bật/tắt %q có mặc định %q, phải là 0 hoặc 1", d.Key, d.KhongCoDong)
			}
		default:
			t.Errorf("khoá %q khai kiểu lạ %q", d.Key, d.Type)
		}
	}

	// Bốn khoá này có tên trong domain vì code ngoài service gọi tới chúng; thiếu
	// một khoá trong registry nghĩa là API từ chối ghi đúng thứ mà nơi khác đọc.
	for _, key := range []string{
		domain.FeatureMaxShops, domain.FeatureMaxUsers,
		domain.FeatureMaxProducts, domain.FeatureOwnDomain,
	} {
		if !thay[key] {
			t.Errorf("registry thiếu khoá %q dù domain có hằng số cho nó", key)
		}
	}
}

func TestValidatePlanFeatures_NhanGiaTriDung(t *testing.T) {
	dat, xoa, err := validatePlanFeatures(map[string]string{
		domain.FeatureMaxUsers:    "10",
		domain.FeatureMaxProducts: domain.VoHan,
		domain.FeatureOwnDomain:   "1",
		// Khoảng trắng hai đầu là thứ ô nhập nào cũng gửi lên.
		domain.FeatureMaxShops: "  3  ",
	})
	if err != nil {
		t.Fatalf("payload hợp lệ mà bị từ chối: %v", err.Fields)
	}
	if len(xoa) != 0 {
		t.Fatalf("không gửi ô rỗng nào mà lại đòi xoá: %v", xoa)
	}
	if dat[domain.FeatureMaxShops] != "3" {
		t.Fatalf("giá trị chưa được cắt khoảng trắng: %q", dat[domain.FeatureMaxShops])
	}
	if dat[domain.FeatureMaxProducts] != domain.VoHan {
		t.Fatalf("%q bị đổi thành %q", domain.VoHan, dat[domain.FeatureMaxProducts])
	}
}

// Ô RỖNG LÀ XOÁ, không phải ghi chuỗi rỗng và cũng không phải ghi 0.
//
// Đây là chỗ dễ làm sai nhất của cả tính năng: "không có dòng" mang nghĩa
// riêng — bảng giá không quy định, số cụ thể chốt lúc ký hợp đồng. Ghi 0 vào đó
// là bán một gói cho phép KHÔNG chi nhánh nào.
func TestValidatePlanFeatures_ORongLaXoa(t *testing.T) {
	dat, xoa, err := validatePlanFeatures(map[string]string{
		domain.FeatureMaxShops: "   ",
		domain.FeatureMaxUsers: "5",
	})
	if err != nil {
		t.Fatalf("bị từ chối: %v", err.Fields)
	}
	if len(xoa) != 1 || xoa[0] != domain.FeatureMaxShops {
		t.Fatalf("ô rỗng phải thành lệnh xoá đúng khoá đó, nhận %v", xoa)
	}
	if _, co := dat[domain.FeatureMaxShops]; co {
		t.Fatalf("khoá vừa xoá lại nằm trong danh sách ghi: %v", dat)
	}
	if dat[domain.FeatureMaxUsers] != "5" {
		t.Fatalf("khoá đi cùng bị mất: %v", dat)
	}
}

func TestValidatePlanFeatures_TuChoiGiaTriSai(t *testing.T) {
	xau := map[string]map[string]string{
		"khoá không có trong registry": {"max_don_hang": "10"},
		"chữ trong ô số":               {domain.FeatureMaxUsers: "mười"},
		"số âm":                        {domain.FeatureMaxUsers: "-1"},
		"số thập phân":                 {domain.FeatureMaxUsers: "2.5"},
		"vượt trần":                    {domain.FeatureMaxShops: "999999"},
		"vo_han cho khoá bật/tắt":      {domain.FeatureOwnDomain: domain.VoHan},
		"giá trị lạ cho khoá bật/tắt":  {domain.FeatureOwnDomain: "co"},
	}
	for ten, items := range xau {
		if _, _, err := validatePlanFeatures(items); err == nil {
			t.Errorf("%s: phải bị từ chối mà lại lọt", ten)
		}
	}
}

// TẤT-CẢ-HOẶC-KHÔNG: một khoá sai thì khoá đúng đi cùng cũng không được ghi.
func TestValidatePlanFeatures_MotKhoaSaiThiKhongGhiGiCa(t *testing.T) {
	dat, xoa, err := validatePlanFeatures(map[string]string{
		domain.FeatureMaxUsers: "7",
		"khoa_la":              "1",
	})
	if err == nil {
		t.Fatalf("payload có khoá lạ mà vẫn qua")
	}
	if dat != nil || xoa != nil {
		t.Fatalf("bị từ chối rồi mà vẫn trả về việc phải ghi: dat=%v xoa=%v", dat, xoa)
	}
	if _, co := err.Fields["khoa_la"]; !co {
		t.Fatalf("lỗi không chỉ ra khoá sai: %v", err.Fields)
	}
}
