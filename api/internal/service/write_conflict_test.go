package service

import (
	"errors"
	"testing"
	"time"

	"sass-api/internal/domain"
)

// CHỐNG GHI ĐÈ — xem docblock ở chong_ghi_de.go cho tình huống thật.
//
// Bốn nhánh dưới đây là toàn bộ hợp đồng của hàm, và ba trong bốn nhánh là
// "ĐỪNG chặn": chốt chặn này phải sai về phía cho qua, vì chặn nhầm nghĩa là
// người dùng mất phiếu vừa điền mà không hiểu vì sao.

func TestKiemBanDangSua_KhongKhaiThiKhongKiem(t *testing.T) {
	// Giao diện bản cũ chưa gửi mốc. Chặn chúng lại là khoá luôn đường sửa phiếu
	// của những màn chưa kịp cập nhật.
	if err := kiemBanDangSua("", time.Now()); err != nil {
		t.Fatalf("không khai mốc thì phải cho qua, nhận: %v", err)
	}
	if err := kiemBanDangSua("   ", time.Now()); err != nil {
		t.Fatalf("khai chuỗi trắng cũng phải cho qua, nhận: %v", err)
	}
}

func TestKiemBanDangSua_MocHongThiCungChoQua(t *testing.T) {
	// Một cái mốc hỏng không được phép biến thành "không sửa được phiếu nữa" —
	// thứ người dùng cần chỉ là lưu phiếu của họ.
	if err := kiemBanDangSua("hôm qua", time.Now()); err != nil {
		t.Fatalf("mốc không đọc được thì phải cho qua, nhận: %v", err)
	}
}

func TestKiemBanDangSua_DungMocThiDiTiep(t *testing.T) {
	thuc := time.Date(2026, 9, 4, 10, 30, 15, 123000000, time.FixedZone("ICT", 7*3600))

	// Đúng chuỗi client nhận được từ API.
	if err := kiemBanDangSua(thuc.Format(time.RFC3339Nano), thuc); err != nil {
		t.Fatalf("đúng mốc thì phải đi tiếp, nhận: %v", err)
	}

	// Cùng thời điểm nhưng khai ở múi giờ khác: vẫn là một mốc, phải đi tiếp.
	// So bằng chuỗi thì nhánh này gãy.
	if err := kiemBanDangSua(thuc.UTC().Format(time.RFC3339Nano), thuc); err != nil {
		t.Fatalf("cùng thời điểm khác múi giờ vẫn phải đi tiếp, nhận: %v", err)
	}
}

func TestKiemBanDangSua_LechMocThiChan(t *testing.T) {
	thuc := time.Date(2026, 9, 4, 10, 30, 15, 123000000, time.UTC)
	cu := thuc.Add(-2 * time.Minute)

	err := kiemBanDangSua(cu.Format(time.RFC3339Nano), thuc)
	if !errors.Is(err, domain.ErrPhieuVuaBiSua) {
		t.Fatalf("mốc cũ hơn phải bị chặn bằng ErrPhieuVuaBiSua, nhận: %v", err)
	}
}

// Cột trong database là DATETIME(3). Chênh lệch dưới một mili giây là rác của
// lượt đi vòng qua JSON, không phải người khác vừa lưu.
func TestKiemBanDangSua_LechDuoiMiliGiayThiBoQua(t *testing.T) {
	thuc := time.Date(2026, 9, 4, 10, 30, 15, 123456789, time.UTC)
	gan := thuc.Add(-321 * time.Nanosecond)

	if err := kiemBanDangSua(gan.Format(time.RFC3339Nano), thuc); err != nil {
		t.Fatalf("lệch dưới một mili giây thì phải cho qua, nhận: %v", err)
	}
}
