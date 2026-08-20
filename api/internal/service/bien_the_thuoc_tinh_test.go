package service

import (
	"testing"

	"sass-api/internal/dto"
)

// Biến thể dựng từ TỔ HỢP THUỘC TÍNH — bốn quy tắc của khuôn bán lẻ mới.
//
// Đây là chỗ màn Hàng hóa → Thuộc tính thật sự có tác dụng: người khai tick
// "128GB" + "Đen", server ghép tên và đặt mã. Sai ở đây thì mặt hàng vào kho với
// tên rỗng hoặc hai biến thể trùng mã, và cả hai chỉ lộ ra lúc quét mã ở quầy.

func intPtr(v int) *int { return &v }

// Tên biến thể ghép từ tên các giá trị, theo ĐÚNG thứ tự người khai gửi lên.
func TestTenBienTheGhepTheoThuTuGuiLen(t *testing.T) {
	ten := map[uint]string{11: "128GB", 22: "Đen"}

	v := dto.VariantRequest{Attributes: []dto.VariantAttributeRequest{
		{AttributeID: 1, ValueID: 11},
		{AttributeID: 2, ValueID: 22},
	}}
	if got := tenBienThe(v, ten); got != "128GB · Đen" {
		t.Fatalf("tên biến thể = %q, mong đợi %q", got, "128GB · Đen")
	}

	// Người khai gõ tên riêng thì tôn trọng, không ghép đè.
	v.Name = "Bản đặc biệt"
	if got := tenBienThe(v, ten); got != "Bản đặc biệt" {
		t.Fatalf("tên khai tay bị ghi đè: %q", got)
	}
}

// Không có chiều thuộc tính nào = hàng đơn: tên rỗng, và dòng ấy là dòng MẶC ĐỊNH.
//
// Bất biến "mọi mặt hàng luôn có ít nhất một dòng biến thể" nằm ở đây — mất dòng
// mặc định là mặt hàng không nhập kho được, không bán được.
func TestHangDonCoDongMacDinh(t *testing.T) {
	ds := buildVariants(bienTheGuiLen(nil), "IP15-0001", map[uint]string{})

	if len(ds) != 1 {
		t.Fatalf("hàng đơn phải có đúng 1 dòng, nhận %d", len(ds))
	}
	if ds[0].Name != "" {
		t.Fatalf("dòng mặc định phải có tên rỗng, nhận %q", ds[0].Name)
	}
	if !ds[0].IsDefault {
		t.Fatal("dòng duy nhất của hàng đơn phải là dòng mặc định")
	}
	if len(ds[0].Attributes) != 0 {
		t.Fatalf("dòng mặc định không được mang tổ hợp nào, nhận %d", len(ds[0].Attributes))
	}
	// Mã của dòng mặc định lấy đúng mã mặt hàng: quét mã hàng đơn ra chính nó.
	if ds[0].SKU != "IP15-0001" {
		t.Fatalf("mã dòng mặc định = %q, mong đợi mã mặt hàng", ds[0].SKU)
	}
}

// Mã biến thể bỏ trống thì ghép từ mã mặt hàng + tên biến thể, và phải ra mã
// đọc được: không có dấu chấm giữa, không có gạch đôi, không có khoảng trắng.
func TestMaBienTheGhepTuTenBienThe(t *testing.T) {
	cases := []struct{ skuCha, ten, want string }{
		{"IP15-0001", "128GB · Đen", "IP15-0001-128GB-DEN"},
		{"IP15-0001", "", "IP15-0001"},
		// Mã cha để trống = máy chủ sắp đặt mã theo quy tắc đánh số; ghép ở đây
		// thì ra một mã không dính gì tới mặt hàng.
		{"", "128GB", ""},
	}
	for _, c := range cases {
		if got := maBienThe(c.skuCha, c.ten); got != c.want {
			t.Fatalf("maBienThe(%q, %q) = %q, mong đợi %q", c.skuCha, c.ten, got, c.want)
		}
	}
}

// Hàng nhiều biến thể: mỗi tổ hợp một dòng, không dòng nào là dòng mặc định, và
// thứ tự bày ra lấy theo thứ tự gửi lên khi người khai không chỉ định pos.
func TestNhieuBienTheGiuThuTuVaKhongCoDongMacDinh(t *testing.T) {
	ten := map[uint]string{11: "128GB", 12: "256GB", 22: "Đen"}
	reqs := []dto.VariantRequest{
		{Attributes: []dto.VariantAttributeRequest{{AttributeID: 1, ValueID: 11}, {AttributeID: 2, ValueID: 22}}},
		{Attributes: []dto.VariantAttributeRequest{{AttributeID: 1, ValueID: 12}, {AttributeID: 2, ValueID: 22}}, Pos: intPtr(5)},
	}

	ds := buildVariants(bienTheGuiLen(reqs), "IP15-0001", ten)
	if len(ds) != 2 {
		t.Fatalf("mong đợi 2 biến thể, nhận %d", len(ds))
	}
	for i, v := range ds {
		if v.IsDefault {
			t.Fatalf("biến thể %d mang tổ hợp mà vẫn bị đánh dấu mặc định", i)
		}
		if len(v.Attributes) != 2 {
			t.Fatalf("biến thể %d phải có 2 chiều, nhận %d", i, len(v.Attributes))
		}
	}
	if ds[0].Name != "128GB · Đen" || ds[1].Name != "256GB · Đen" {
		t.Fatalf("tên biến thể sai: %q / %q", ds[0].Name, ds[1].Name)
	}
	if ds[0].Pos != 0 {
		t.Fatalf("pos mặc định phải theo thứ tự gửi lên, nhận %d", ds[0].Pos)
	}
	if ds[1].Pos != 5 {
		t.Fatalf("pos khai tay bị ghi đè: %d", ds[1].Pos)
	}
	// Hai tổ hợp khác nhau phải ra hai mã khác nhau — trùng mã là quét ở quầy ra
	// nhầm hàng.
	if ds[0].SKU == ds[1].SKU {
		t.Fatalf("hai biến thể trùng mã: %q", ds[0].SKU)
	}
}
